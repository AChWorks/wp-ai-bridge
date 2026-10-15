<?php
/**
 * Real disposable WordPress delete lifecycle hook-error boundaries (#126).
 *
 * Each scenario runs in a separate WP-CLI process with an isolated test DB.
 * The unrecoverable case MUST end the process with exit status 70, not return.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

$stage = getenv( 'WPAI_HOOK_BOUNDARY_STAGE' );
if ( ! in_array( $stage, array( 'permission', 'post_cas', 'compensation', 'activity', 'unrecoverable' ), true ) ) {
	throw new RuntimeException( 'Invalid disposable hook-boundary scenario.' );
}
$checks = 0;
$assert = static function ( $truth, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $truth ) {
		throw new RuntimeException( $message );
	}
};
$assert( is_multisite(), 'Test requires disposable WordPress multisite.' );
$blog_id = (int) get_current_blog_id();
$foreign_blog_id = 0;
foreach ( get_sites( array( 'number' => 5, 'fields' => 'ids' ) ) as $site ) {
	if ( (int) $site !== $blog_id ) {
		$foreign_blog_id = (int) $site;
		break;
	}
}
$assert( 0 < $foreign_blog_id, 'Missing disposable foreign site.' );
$settings    = new Settings();
$old_access  = get_option( Settings::OPTION_NAME, null );
$access      = $settings->defaults();
$access[ Settings::GROUP_SITE_READ ]         = 1;
$access[ Settings::GROUP_BUILDER_WRITE ]     = 1;
$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $access, false );
$auth = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
$auth->setValue( null, 'disposable-hook-boundary-' . $stage );
$store     = new Store();
$doc       = null;
$collision = 0;
$hook_name = null;
$hook_fn   = null;
$veto_fn   = null;
$all_keep  = null;

try {
	$project = 'hook-boundary-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 16 );
	$doc     = $store->create_document( array(
		'project_ref' => $project,
		'key'         => 'hook-boundary-' . $stage,
		'title'       => 'Disposable Workspace hook recovery boundary',
		'content'     => 'Dedicated isolated hook exception fixture.',
	) );
	$assert( ! is_wp_error( $doc ), 'Cannot create private Workspace record.' );
	$id = (int) $doc['id'];
	$input = array(
		'kind'                => 'document',
		'id'                  => $id,
		'blog_id'             => $blog_id,
		'project_ref'         => $project,
		'expected_version'    => (int) $doc['version'],
		'expected_state_hash' => (string) $doc['state_hash'],
		'confirmation'        => 'PERMANENTLY DELETE DOCUMENT ' . $id,
	);
	$original_rows = get_post_meta( $id, Store::META_STATE, false );
	$marker = 'retain-hook-boundary-' . $stage;
	update_post_meta( $id, '_wp_trash_meta_time', $marker );
	$local_activity = get_option( Mutation_Log::OPTION_NAME, null );

	switch_to_blog( $foreign_blog_id );
	try {
		$foreign_post = get_post( $id );
		if ( ! $foreign_post ) {
			$collision = wp_insert_post( array(
				'import_id'    => $id,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'Foreign ID collision for hook error recovery',
				'post_content' => 'Never modify this post.',
			), true );
			$assert( ! is_wp_error( $collision ) && (int) $collision === $id,
				'Could not prepare foreign same-ID collision.' );
		}
		$foreign_post = get_post( $id );
		$assert( $foreign_post instanceof WP_Post, 'Foreign record is absent.' );
		$foreign_fingerprint = wp_json_encode( $foreign_post->to_array() );
		$foreign_activity    = get_option( Mutation_Log::OPTION_NAME, null );
	} finally {
		restore_current_blog();
	}

	// Keep a legitimate provider 'all' registration alive throughout each
	// exception. It must never be lost, silently reordered or left active.
	$all_hits = 0;
	$all_keep = static function () use ( &$all_hits ) {
		++$all_hits;
	};
	add_action( 'all', $all_keep, 40, 0 );

	$provider_exception = new RuntimeException( 'Disposable exact-delete hook provider failure.' );
	$trigger_count      = 0;
	$native_started     = 0;
	$observe_native = static function ( $candidate_id ) use ( $id, &$native_started ) {
		if ( (int) $candidate_id === $id ) {
			++$native_started;
		}
	};
	add_action( 'before_delete_post', $observe_native, 1, 1 );

	if ( 'permission' === $stage || 'unrecoverable' === $stage ) {
		$hook_name = 'map_meta_cap';
		$hook_fn = static function ( $caps, $cap, $user_id, $args ) use ( &$trigger_count, $id, $provider_exception, $stage ) {
			if ( 'delete_post' === $cap && in_array( $id, $args, true ) ) {
				++$trigger_count;
				if ( 'unrecoverable' === $stage ) {
					// The global dispatcher cannot be reconstructed from a
					// foreign object. No WP_Ability catch may resume this run.
					$GLOBALS['wp_filter']['map_meta_cap'] = (object) array( 'untrusted' => true );
				}
				throw $provider_exception;
			}
			return $caps;
		};
		add_filter( $hook_name, $hook_fn, 15, 4 );
	} elseif ( 'post_cas' === $stage ) {
		$hook_name = 'updated_post_meta';
		$hook_fn = static function ( $meta_id, $object_id, $key, $value ) use ( &$trigger_count, $id, $provider_exception ) {
			$decoded = is_string( $value ) ? json_decode( $value, true ) : null;
			if ( $id === (int) $object_id && Store::META_STATE === $key &&
				is_array( $decoded ) && ! empty( $decoded['delete_pending'] ) ) {
				++$trigger_count;
				throw $provider_exception;
			}
		};
		add_action( $hook_name, $hook_fn, 15, 4 );
	} elseif ( 'compensation' === $stage ) {
		$veto_seen = false;
		$veto_fn = static function ( $pre, $candidate, $force ) use ( &$veto_seen, $id ) {
			if ( $candidate instanceof WP_Post && $id === (int) $candidate->ID && true === $force ) {
				$veto_seen = true;
				return false;
			}
			return $pre;
		};
		add_filter( 'pre_delete_post', $veto_fn, 10, 3 );
		$hook_name = 'get_post_metadata';
		$hook_fn = static function ( $value, $object_id, $key ) use ( $id, &$veto_seen, &$trigger_count, $provider_exception ) {
			if ( $veto_seen && (int) $object_id === $id && Store::META_STATE === $key ) {
				++$trigger_count;
				throw $provider_exception;
			}
			return $value;
		};
		add_filter( $hook_name, $hook_fn, 15, 3 );
	} else {
		$hook_name = 'pre_update_option_' . Mutation_Log::OPTION_NAME;
		$hook_fn = static function ( $new_value ) use ( &$trigger_count, $provider_exception ) {
			++$trigger_count;
			throw $provider_exception;
		};
		add_filter( $hook_name, $hook_fn, 15, 1 );
	}

	global $wp_filter, $wp_current_filter;
	$stack_before     = $wp_current_filter;
	$registry_before  = $wp_filter[ $hook_name ]->callbacks;
	$all_before       = $wp_filter['all']->callbacks;
	$throw_seen       = null;
	$result           = null;
	$ability          = wp_get_ability( 'wp-ai-bridge/workspace-delete' );
	$assert( $ability instanceof WP_Ability, 'Missing disposable delete Ability.' );
	try {
		$result = $ability->execute( $input );
	} catch ( Throwable $exception ) {
		$throw_seen = $exception;
	}
	if ( 'unrecoverable' === $stage ) {
		// Reaching this marker on WordPress 7.1.3 proves unsafe continuation.
		echo "UNSAFE_BOUNDARY_CONTINUED\n";
		throw new RuntimeException( 'An unprovable hook reconstruction did not terminate this PHP process.' );
	}

	$assert( 1 === $trigger_count, 'Wrong number of provider exceptions during guarded operation.' );
	$expected_native_starts = 'activity' === $stage ? 1 : 0;
	$assert( $blog_id === (int) get_current_blog_id() && $expected_native_starts === $native_started,
		'Native cleanup or site switch differed from the expected stage.' );
	$assert( $wp_current_filter === $stack_before &&
		! doing_filter( 'map_meta_cap' ) &&
		! doing_filter( 'get_post_metadata' ) &&
		! doing_action( 'updated_post_meta' ) &&
		! doing_filter( $hook_name ),
		'WordPress global filter stack remained poisoned after boundary recovery.' );
	$assert( $wp_filter[ $hook_name ]->callbacks === $registry_before &&
		$wp_filter['all']->callbacks === $all_before,
		'Provider hook callback IDs/priority/registration order were changed during recovery.' );
	$reflection = new ReflectionClass( WP_Hook::class );
	foreach ( array( $hook_name, 'all' ) as $named_hook ) {
		$instance = $wp_filter[ $named_hook ];
		$assert( get_class( $instance ) === WP_Hook::class,
			'Provider dispatcher was replaced with an incompatible type.' );
		foreach ( array(
			'nesting_level'    => 0,
			'iterations'       => array(),
			'current_priority' => array(),
			'doing_action'     => false,
		) as $field => $expect ) {
			$assert( $reflection->getProperty( $field )->getValue( $instance ) === $expect,
				'Stale WordPress dispatcher state: ' . $named_hook . '/' . $field );
		}
	}
	$probe_hits = 0;
	$probe = static function () use ( &$probe_hits ) {
		++$probe_hits;
	};
	add_action( 'wpai_issue126_hook_boundary_probe', $probe, 10, 0 );
	try {
		do_action( 'wpai_issue126_hook_boundary_probe' );
	} finally {
		remove_action( 'wpai_issue126_hook_boundary_probe', $probe, 10 );
	}
	$assert( 1 === $probe_hits && 0 < $all_hits && $wp_current_filter === $stack_before,
		'Later same-request WordPress hook dispatch failed after provider exception.' );

	// Remove the deliberate throwing callback only AFTER proving its original
	// registration and the restored dispatcher identity/order.
	remove_filter( $hook_name, $hook_fn, 15 );
	$hook_fn = null;
	if ( $veto_fn ) {
		remove_filter( 'pre_delete_post', $veto_fn, 10 );
		$veto_fn = null;
	}
	$pending_rows = get_post_meta( $id, Store::META_STATE, false );
	if ( 'permission' === $stage || 'post_cas' === $stage ) {
		$expected_wrapped_code = 'permission' === $stage ? 'ability_invalid_permissions' : 'ability_callback_exception';
		$wrapped = null === $throw_seen && is_wp_error( $result ) &&
			$expected_wrapped_code === $result->get_error_code();
		$propagated = $throw_seen === $provider_exception && null === $result;
		$assert( $wrapped || $propagated, 'Provider callback failure was masked or returned false success.' );
		$assert( get_post( $id ) instanceof WP_Post &&
			$marker === get_post_meta( $id, '_wp_trash_meta_time', true ),
			'WordPress Core cleanup ran after a pre-native provider exception.' );
		if ( 'permission' === $stage ) {
			$assert( $original_rows === $pending_rows,
				'Pre-CAS permission failure changed an exact private record.' );
		} else {
			$decoded = 1 === count( $pending_rows ) && is_string( $pending_rows[0] ) ?
				json_decode( $pending_rows[0], true ) : null;
			$assert( is_array( $decoded ) && ! empty( $decoded['delete_pending'] ),
				'Post-CAS provider exception did not retain a conservative delete_pending fence.' );
		}
		$assert( $local_activity === get_option( Mutation_Log::OPTION_NAME, null ),
			'Propagated provider exception was incorrectly recorded as a normal Activity outcome.' );
	} elseif ( 'compensation' === $stage ) {
		$decoded = 1 === count( $pending_rows ) && is_string( $pending_rows[0] ) ?
			json_decode( $pending_rows[0], true ) : null;
		$assert( null === $throw_seen && is_wp_error( $result ) &&
			'workspace_delete_outcome_unknown' === $result->get_error_code(),
			'Compensation hook failure did not report the deliberately uncertain outcome.' );
		$assert( is_array( $decoded ) && ! empty( $decoded['delete_pending'] ) &&
			get_post( $id ) instanceof WP_Post &&
			$marker === get_post_meta( $id, '_wp_trash_meta_time', true ),
			'Compensation failure unexpectedly restored the fence or reached native cleanup.' );
	} else {
		$assert( null === $throw_seen && is_array( $result ) &&
			true === $result['deleted'] && $id === $result['id'],
			'Best-effort Activity exception changed an already verified delete receipt.' );
		$assert( ! get_post( $id ) && ! get_post_meta( $id, Store::META_STATE, false ),
			'Verified deletion did not remove the exact WordPress record.' );
		$assert( $local_activity === get_option( Mutation_Log::OPTION_NAME, null ),
			'Failed Activity write overwrote the last committed audit entries.' );
	}

	switch_to_blog( $foreign_blog_id );
	try {
		clean_post_cache( $id );
		$other = get_post( $id );
		$assert( $other instanceof WP_Post &&
			$foreign_fingerprint === wp_json_encode( $other->to_array() ) &&
			$foreign_activity === get_option( Mutation_Log::OPTION_NAME, null ),
			'Provider exception altered another site with the same numeric ID or its Activity.' );
	} finally {
		restore_current_blog();
	}
	echo "PASS: #126 {$checks} real multisite {$stage} hook dispatcher / exact-delete recovery assertions.\n";
} finally {
	if ( $hook_name && $hook_fn ) {
		remove_filter( $hook_name, $hook_fn, 15 );
	}
	if ( $veto_fn ) {
		remove_filter( 'pre_delete_post', $veto_fn, 10 );
	}
	if ( $all_keep ) {
		remove_action( 'all', $all_keep, 40 );
	}
	if ( isset( $observe_native ) ) {
		remove_action( 'before_delete_post', $observe_native, 1 );
	}
	$auth->setValue( null, '' );
	if ( $doc && ! is_wp_error( $doc ) ) {
		wp_delete_post( (int) $doc['id'], true );
	}
	if ( $collision && ! is_wp_error( $collision ) ) {
		switch_to_blog( $foreign_blog_id );
		try {
			wp_delete_post( (int) $collision, true );
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
