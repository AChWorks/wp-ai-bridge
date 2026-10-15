<?php
/**
 * Disposable real multisite exact-delete lifecycle gates (#126/R166-01).
 * Never run on a site that is not a freshly provisioned isolated test DB.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

$checks = 0;
$assert = static function ( $ok, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$assert( is_multisite(), 'This fixture requires disposable WordPress multisite.' );
$base = (int) get_current_blog_id();
$secondary = 0;
foreach ( get_sites( array( 'number' => 5, 'fields' => 'ids' ) ) as $site ) {
	if ( (int) $site !== $base ) {
		$secondary = (int) $site;
		break;
	}
}
$assert( $secondary > 0, 'Missing disposable secondary blog.' );
$settings = new Settings();
$old_access = get_option( Settings::OPTION_NAME, null );
$access = $settings->defaults();
$access[ Settings::GROUP_SITE_READ ] = 1;
$access[ Settings::GROUP_BUILDER_WRITE ] = 1;
$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $access, false );
$auth = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
$auth->setValue( null, 'disposable-network-delete' );
$store = new Store();
$doc = null;
$colliding = 0;
$native_started = 0;
$project = 'native-delete-network-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 12 );
$call = static function ( $input ) {
	$ability = wp_get_ability( 'wp-ai-bridge/workspace-delete' );
	if ( ! $ability instanceof WP_Ability ) {
		throw new RuntimeException( 'Missing exact-delete Ability on disposable multisite.' );
	}
	return $ability->execute( $input );
};
try {
	$doc = $store->create_document( array(
		'project_ref' => $project,
		'key' => 'native-boundary',
		'title' => 'Disposable multisite exact-delete fixture',
		'content' => 'Original blog private record, not a generic post.',
	) );
	$assert( ! is_wp_error( $doc ), 'Could not create original blog private record.' );
	$id = (int) $doc['id'];
	$input = array(
		'kind' => 'document',
		'id' => $id,
		'blog_id' => $base,
		'project_ref' => $project,
		'expected_version' => (int) $doc['version'],
		'expected_state_hash' => (string) $doc['state_hash'],
		'confirmation' => 'PERMANENTLY DELETE DOCUMENT ' . $id,
	);

	// Ensure the other disposable blog has a post at the SAME numeric ID.
	// Preserve an existing fixture instead of accidentally replacing it.
	switch_to_blog( $secondary );
	try {
		$other_before = get_post( $id );
		if ( ! $other_before ) {
			$colliding = wp_insert_post( array(
				'import_id' => $id,
				'post_type' => 'post',
				'post_status' => 'draft',
				'post_title' => 'Untouched disposable cross-blog collision',
				'post_content' => 'This separate site post must never be affected.',
			), true );
			$assert( ! is_wp_error( $colliding ) && (int) $colliding === $id,
				'Could not set up the numeric-ID collision.' );
		}
		$other_before = get_post( $id );
		$assert( $other_before instanceof WP_Post, 'Missing other-blog post ID.' );
		$other_fingerprint = wp_json_encode( $other_before->to_array() );
	} finally {
		restore_current_blog();
	}
	$observe_native = static function ( $candidate_id ) use ( &$native_started, $id ) {
		if ( (int) $candidate_id === $id ) {
			++$native_started;
		}
	};
	add_action( 'before_delete_post', $observe_native, 1, 1 );

	// Hook switches blog but returns null, so Core would normally proceed.
	// The late pre_delete_post gate must stop it and safely restore its CAS.
	$switched = false;
	$before_switch = static function ( $check, $candidate, $force ) use ( $id, $secondary, &$switched ) {
		if ( (int) $candidate->ID === $id && true === $force ) {
			$switched = (bool) switch_to_blog( $secondary );
		}
		return $check;
	};
	add_filter( 'pre_delete_post', $before_switch, 10, 3 );
	try {
		$result = $call( $input );
	} finally {
		remove_filter( 'pre_delete_post', $before_switch, 10 );
		if ( (int) get_current_blog_id() === $secondary ) {
			restore_current_blog();
		}
	}
	$readback = $store->get_document( $id );
	$assert( $switched && is_wp_error( $result ) &&
		'workspace_delete_aborted' === $result->get_error_code() && 0 === $native_started,
		'Cross-blog pre_delete_post transition reached native deletion.' );
	$assert( ! is_wp_error( $readback ) &&
		$readback['version'] === $doc['version'] &&
		$readback['state_hash'] === $doc['state_hash'],
		'Cross-blog pre-native abort stranded the original private record.' );
	switch_to_blog( $secondary );
	try {
		clean_post_cache( $id );
		$other = get_post( $id );
		$assert( $other instanceof WP_Post &&
			$other_fingerprint === wp_json_encode( $other->to_array() ),
			'Other blog numeric-ID collision was modified or removed.' );
	} finally {
		restore_current_blog();
	}

	// Even changes made by a before_delete_post callback must not reach
	// WordPress cleanup; because this phase has started the fence is NOT
	// automatically restored (earlier callbacks may have side effects).
	$before_native_switch = static function ( $candidate_id ) use ( $id, $secondary ) {
		if ( (int) $candidate_id === $id ) {
			switch_to_blog( $secondary );
		}
	};
	add_action( 'before_delete_post', $before_native_switch, 10, 1 );
	try {
		$result = $call( $input );
	} finally {
		remove_action( 'before_delete_post', $before_native_switch, 10 );
		remove_action( 'before_delete_post', $observe_native, 1 );
		if ( (int) get_current_blog_id() === $secondary ) {
			restore_current_blog();
		}
	}
	$assert( is_wp_error( $result ) &&
		'workspace_delete_outcome_unknown' === $result->get_error_code() &&
		1 === $native_started && get_post( $id ) instanceof WP_Post,
		'Changed before_delete_post context was not safely stopped.' );
	$assert( is_wp_error( $store->get_document( $id ) ),
		'An uncertain native lifecycle incorrectly un-fenced a record.' );
	switch_to_blog( $secondary );
	try {
		clean_post_cache( $id );
		$other = get_post( $id );
		$assert( $other instanceof WP_Post &&
			$other_fingerprint === wp_json_encode( $other->to_array() ),
			'Other blog was affected after before_delete_post context changed.' );
	} finally {
		restore_current_blog();
	}
	echo "PASS: #126 {$checks} disposable exact-delete multisite lifecycle assertions.\n";
} finally {
	$auth->setValue( null, '' );
	if ( $doc && ! is_wp_error( $doc ) ) {
		// Fixture cleanup uses a direct native delete after hook removal.
		wp_delete_post( (int) $doc['id'], true );
	}
	if ( null === $old_access ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $old_access, false );
	}
	if ( $colliding && ! is_wp_error( $colliding ) ) {
		switch_to_blog( $secondary );
		try {
			wp_delete_post( (int) $colliding, true );
		} finally {
			restore_current_blog();
		}
	}
}
