<?php
/**
 * Disposable multisite proof: an exception from WordPress's special "all"
 * dispatcher must never be converted into a normal Workspace delete result.
 *
 * Invoke once per stage in a SEPARATE WP-CLI process, and only on a fresh
 * isolated WordPress database. The deliberately thrown provider exception
 * leaves WP_Hook's "all" execution state stale in that process.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

$stage = getenv( 'WPAI_ALL_HOOK_STAGE' );
if ( ! in_array( $stage, array( 'pre_delete_post', 'before_delete_post' ), true ) ) {
	throw new RuntimeException( 'A supported disposable all-hook stage is required.' );
}
$checks = 0;
$assert = static function ( $value, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $value ) {
		throw new RuntimeException( $message );
	}
};
$assert( is_multisite(), 'This test must run on disposable WordPress multisite.' );
$base      = (int) get_current_blog_id();
$secondary = 0;
foreach ( get_sites( array( 'number' => 5, 'fields' => 'ids' ) ) as $site ) {
	if ( (int) $site !== $base ) {
		$secondary = (int) $site;
		break;
	}
}
$assert( $secondary > 0, 'Disposable secondary site is required.' );
$settings   = new Settings();
$old_access = get_option( Settings::OPTION_NAME, null );
$access     = $settings->defaults();
$access[ Settings::GROUP_SITE_READ ]         = 1;
$access[ Settings::GROUP_BUILDER_WRITE ]     = 1;
$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $access, false );
$auth = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
$auth->setValue( null, 'disposable-all-hook-' . $stage );
$store         = new Store();
$doc           = null;
$colliding     = 0;
$throwing_all  = null;
try {
	$project = 'all-hook-exception-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 16 );
	$doc     = $store->create_document( array(
		'project_ref' => $project,
		'key'         => 'all-exception-' . $stage,
		'title'       => 'Disposable global WordPress hook exception',
		'content'     => 'Never delete the other blog or silently swallow a provider exception.',
	) );
	$assert( ! is_wp_error( $doc ), 'Cannot create private exact-delete fixture.' );
	$id    = (int) $doc['id'];
	$input = array(
		'kind'                => 'document',
		'id'                  => $id,
		'blog_id'             => $base,
		'project_ref'         => $project,
		'expected_version'    => (int) $doc['version'],
		'expected_state_hash' => (string) $doc['state_hash'],
		'confirmation'        => 'PERMANENTLY DELETE DOCUMENT ' . $id,
	);
	$marker = 'keep-all-exception-' . $stage;
	update_post_meta( $id, '_wp_trash_meta_time', $marker );

	switch_to_blog( $secondary );
	try {
		$other = get_post( $id );
		if ( ! $other ) {
			$colliding = wp_insert_post( array(
				'import_id'    => $id,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'Unrelated disposable foreign numeric-ID collision',
				'post_content' => 'Must remain unchanged.',
			), true );
			$assert( ! is_wp_error( $colliding ) && (int) $colliding === $id,
				'Cannot build exact same-ID cross-blog fixture.' );
		}
		$other = get_post( $id );
		$assert( $other instanceof WP_Post, 'Foreign colliding post missing.' );
		$foreign_fingerprint = wp_json_encode( $other->to_array() );
		$foreign_activity    = get_option( Mutation_Log::OPTION_NAME, null );
	} finally {
		restore_current_blog();
	}
	$local_activity = get_option( Mutation_Log::OPTION_NAME, null );

	// Registered only after fixture preparation. A provider may throw during
	// the hidden 'all' dispatch BEFORE any named native hook callback starts.
	$provider_exception = new RuntimeException( 'Disposable provider all-hook exception (' . $stage . ')' );
	$throw_count        = 0;
	$throwing_all       = static function ( $hook_name ) use ( $stage, $provider_exception, &$throw_count ) {
		if ( $stage === $hook_name ) {
			++$throw_count;
			throw $provider_exception;
		}
	};
	add_action( 'all', $throwing_all, PHP_INT_MAX, 1 );
	global $wp_filter, $wp_current_filter;
	$callbacks_before = $wp_filter['all']->callbacks;
	$stack_before     = $wp_current_filter;
	$caught           = null;
	$actual           = null;
	$callbacks_after  = null;
	$all_hook_depth   = 0;
	$stack_after      = null;
	$still_registered = false;
	try {
		$ability = wp_get_ability( 'wp-ai-bridge/workspace-delete' );
		$assert( $ability instanceof WP_Ability, 'Exact-delete Ability is absent.' );
		try {
			$actual = $ability->execute( $input );
		} catch ( Throwable $error ) {
			$caught = $error;
		}
		$still_registered = PHP_INT_MAX === has_filter( 'all', $throwing_all );
		$callbacks_after  = isset( $wp_filter['all'] ) ? $wp_filter['all']->callbacks : null;
		$stack_after      = $wp_current_filter;
		$all_hook_depth   = isset( $wp_filter['all'] ) ?
			( new ReflectionProperty( WP_Hook::class, 'nesting_level' ) )->getValue( $wp_filter['all'] ) : 0;
	} finally {
		// The prior error deliberately poisoned 'all' inside this disposable
		// PHP process. Do not leave the throwing provider registered.
		remove_action( 'all', $throwing_all, PHP_INT_MAX );
	}
	$actual_kind = is_wp_error( $actual ) ? $actual->get_error_code() :
		( is_array( $actual ) ? 'array' : get_debug_type( $actual ) );
	$caught_kind = $caught instanceof Throwable ? get_class( $caught ) : 'none';
	$assert( $caught === $provider_exception && null === $actual && 1 === $throw_count,
		sprintf(
			'All-hook propagation mismatch: stage=%s, WordPress=%s, throws=%d, caught_type=%s, caught_exact=%d, result=%s, user=%d, blog=%d.',
			$stage,
			get_bloginfo( 'version' ),
			$throw_count,
			$caught_kind,
			(int) ( $caught === $provider_exception ),
			$actual_kind,
			get_current_user_id(),
			get_current_blog_id()
		) );
	$assert( $still_registered && $callbacks_after === $callbacks_before,
		'Bridge removed, rewrote or reordered an unrelated all-hook callback.' );
	$assert( $all_hook_depth > 0 &&
		$stack_after === array_merge( $stack_before, array( $stage ) ),
		'The test did not reach the hidden all dispatcher with an escaped native hook frame.' );

	// Inspect the bounded disposable state AFTER removing the injected hook.
	// Unknown third-party effects must not be reclassified as a clean abort
	// with an optimistically restored CAS fence or as successful deletion.
	$rows  = get_post_meta( $id, Store::META_STATE, false );
	$state = 1 === count( $rows ) && is_string( $rows[0] ) ?
		json_decode( $rows[0], true ) : null;
	$assert( get_post( $id ) instanceof WP_Post &&
		$marker === get_post_meta( $id, '_wp_trash_meta_time', true ) &&
		is_array( $state ) && ! empty( $state['delete_pending'] ),
		'Provider exception reached native cleanup or restored an uncertain CAS fence.' );
	$assert( $local_activity === get_option( Mutation_Log::OPTION_NAME, null ),
		'Uncaught provider exception unexpectedly recorded a normal Workspace Activity outcome.' );
	switch_to_blog( $secondary );
	try {
		clean_post_cache( $id );
		$after_foreign = get_post( $id );
		$assert( $after_foreign instanceof WP_Post &&
			$foreign_fingerprint === wp_json_encode( $after_foreign->to_array() ) &&
			$foreign_activity === get_option( Mutation_Log::OPTION_NAME, null ),
			'Provider all-hook exception changed the unrelated blog record or Activity.' );
	} finally {
		restore_current_blog();
	}
	echo "PASS: #126 {$checks} disposable {$stage} all-hook exception propagation and isolation assertions.\n";
} finally {
	if ( $throwing_all ) {
		remove_action( 'all', $throwing_all, PHP_INT_MAX );
	}
	$auth->setValue( null, '' );
	if ( $doc && ! is_wp_error( $doc ) ) {
		wp_delete_post( (int) $doc['id'], true );
	}
	if ( $colliding && ! is_wp_error( $colliding ) ) {
		switch_to_blog( $secondary );
		try {
			wp_delete_post( (int) $colliding, true );
		} finally {
			restore_current_blog();
		}
	}
	if ( null === $old_access ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $old_access, false );
	}
}
