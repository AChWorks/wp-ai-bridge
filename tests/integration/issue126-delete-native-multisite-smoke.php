<?php
/**
 * Disposable real multisite exact-delete lifecycle gates (#126/R166-01).
 * Never run on a site that is not a freshly provisioned isolated test DB.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
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
$dynamic_doc = null;
$colliding = 0;
$dynamic_colliding = 0;
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
		$secondary_activity_before = get_option( Mutation_Log::OPTION_NAME, null );
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
		$pre_return_blog = (int) get_current_blog_id();
		$foreign_log_after_pre = get_option( Mutation_Log::OPTION_NAME, null );
	} finally {
		remove_filter( 'pre_delete_post', $before_switch, 10 );
		if ( (int) get_current_blog_id() === $secondary ) {
			restore_current_blog();
		}
	}
	$assert( $secondary === $pre_return_blog &&
		$secondary_activity_before === $foreign_log_after_pre,
		'Pre-delete site drift wrote an Activity entry into the wrong blog.' );
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

	// A callback registered while pre_delete_post is running can append a
	// PHP_INT_MAX tail BEHIND the installed Bridge guard. A non-null return
	// is not sufficient: a later callback could reset it to null. The
	// terminal-order check must interrupt Core BEFORE the tail executes.
	$late_pre_registered = false;
	$late_pre_executed = false;
	$late_pre = static function ( $check, $candidate, $force ) use ( $id, $secondary, &$late_pre_executed ) {
		if ( (int) $candidate->ID === $id && true === $force ) {
			$late_pre_executed = true;
			switch_to_blog( $secondary );
		}
		return $check;
	};
	$register_late_pre = static function ( $check, $candidate, $force ) use ( $id, &$late_pre_registered, $late_pre ) {
		if ( (int) $candidate->ID === $id && true === $force ) {
			$late_pre_registered = true;
			add_filter( 'pre_delete_post', $late_pre, PHP_INT_MAX, 3 );
		}
		return $check;
	};
	add_filter( 'pre_delete_post', $register_late_pre, 10, 3 );
	try {
		$dynamic_pre_result = $call( $input );
	} finally {
		remove_filter( 'pre_delete_post', $register_late_pre, 10 );
		remove_filter( 'pre_delete_post', $late_pre, PHP_INT_MAX );
		if ( (int) get_current_blog_id() === $secondary ) {
			restore_current_blog();
		}
	}
	$after_dynamic_pre = $store->get_document( $id );
	$assert( $late_pre_registered && ! $late_pre_executed && 0 === $native_started &&
		is_wp_error( $dynamic_pre_result ) &&
		'workspace_delete_aborted' === $dynamic_pre_result->get_error_code(),
		'A dynamically appended PHP_INT_MAX pre-delete callback was allowed to run.' );
	$assert( ! is_wp_error( $after_dynamic_pre ) &&
		$after_dynamic_pre['version'] === $doc['version'] &&
		$after_dynamic_pre['state_hash'] === $doc['state_hash'],
		'Dynamic same-priority pre-delete ordering left an unreadable fence.' );
	switch_to_blog( $secondary );
	try {
		$other = get_post( $id );
		$assert( $other instanceof WP_Post &&
			$other_fingerprint === wp_json_encode( $other->to_array() ) &&
			$secondary_activity_before === get_option( Mutation_Log::OPTION_NAME, null ),
			'Dynamic pre-delete registration altered foreign post or Activity.' );
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
		$native_return_blog = (int) get_current_blog_id();
		$foreign_log_after_native = get_option( Mutation_Log::OPTION_NAME, null );
	} finally {
		remove_action( 'before_delete_post', $before_native_switch, 10 );
		remove_action( 'before_delete_post', $observe_native, 1 );
		if ( (int) get_current_blog_id() === $secondary ) {
			restore_current_blog();
		}
	}
	$assert( $secondary === $native_return_blog &&
		$secondary_activity_before === $foreign_log_after_native,
		'Native-hook site drift wrote an Activity entry into the wrong blog.' );
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
	// A second exact fixture proves the analogous PHP_INT_MAX tail risk in
	// before_delete_post, without trying to re-delete the first uncertain
	// record. Native entry is observable but Core cleanup must NOT proceed.
	$dynamic_doc = $store->create_document( array(
		'project_ref' => $project,
		'key'         => 'dynamic-before-guard',
		'title'       => 'Disposable before-delete hook-order fixture',
		'content'     => 'Must never be cleaned by Core while a hook guard is nonterminal.',
	) );
	$assert( ! is_wp_error( $dynamic_doc ), 'Cannot create second exact multisite fixture.' );
	$dynamic_id = (int) $dynamic_doc['id'];
	$dynamic_input = array(
		'kind'                => 'document',
		'id'                  => $dynamic_id,
		'blog_id'             => $base,
		'project_ref'         => $project,
		'expected_version'    => (int) $dynamic_doc['version'],
		'expected_state_hash' => (string) $dynamic_doc['state_hash'],
		'confirmation'        => 'PERMANENTLY DELETE DOCUMENT ' . $dynamic_id,
	);
	$native_marker = 'preserve-before-core-cleanup';
	update_post_meta( $dynamic_id, '_wp_trash_meta_time', $native_marker );
	switch_to_blog( $secondary );
	try {
		$other_dynamic_before = get_post( $dynamic_id );
		if ( ! $other_dynamic_before ) {
			$dynamic_colliding = wp_insert_post( array(
				'import_id'    => $dynamic_id,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'Untouched dynamic-before cross-blog collision',
				'post_content' => 'Disposable foreign record.',
			), true );
			$assert( ! is_wp_error( $dynamic_colliding ) && (int) $dynamic_colliding === $dynamic_id,
				'Could not create dynamic-before numeric-ID collision.' );
		}
		$other_dynamic_before = get_post( $dynamic_id );
		$assert( $other_dynamic_before instanceof WP_Post, 'Missing second numeric-ID collision.' );
		$dynamic_foreign_fingerprint = wp_json_encode( $other_dynamic_before->to_array() );
	} finally {
		restore_current_blog();
	}

	// This late action, if reached, would switch into a foreign blog before
	// Core's very first delete_post_meta() call. The guard must throw first.
	$late_before_registered = false;
	$late_before_executed   = false;
	$dynamic_native_started = 0;
	$observe_dynamic = static function ( $candidate_id ) use ( $dynamic_id, &$dynamic_native_started ) {
		if ( (int) $candidate_id === $dynamic_id ) {
			++$dynamic_native_started;
		}
	};
	$late_before = static function ( $candidate_id ) use ( $dynamic_id, $secondary, &$late_before_executed ) {
		if ( (int) $candidate_id === $dynamic_id ) {
			$late_before_executed = true;
			switch_to_blog( $secondary );
		}
	};
	$register_late_before = static function ( $candidate_id ) use ( $dynamic_id, $late_before, &$late_before_registered ) {
		if ( (int) $candidate_id === $dynamic_id ) {
			$late_before_registered = true;
			add_action( 'before_delete_post', $late_before, PHP_INT_MAX, 1 );
		}
	};
	add_action( 'before_delete_post', $observe_dynamic, 1, 1 );
	add_action( 'before_delete_post', $register_late_before, 10, 1 );
	try {
		$dynamic_before_result = $call( $dynamic_input );
	} finally {
		remove_action( 'before_delete_post', $observe_dynamic, 1 );
		remove_action( 'before_delete_post', $register_late_before, 10 );
		remove_action( 'before_delete_post', $late_before, PHP_INT_MAX );
		if ( (int) get_current_blog_id() === $secondary ) {
			restore_current_blog();
		}
	}
	$assert( $late_before_registered && ! $late_before_executed &&
		1 === $dynamic_native_started && is_wp_error( $dynamic_before_result ) &&
		'workspace_delete_outcome_unknown' === $dynamic_before_result->get_error_code(),
		'A dynamically appended PHP_INT_MAX before-delete callback bypassed the native guard.' );
	$assert( get_post( $dynamic_id ) instanceof WP_Post &&
		$native_marker === get_post_meta( $dynamic_id, '_wp_trash_meta_time', true ) &&
		is_wp_error( $store->get_document( $dynamic_id ) ),
		'Core cleanup ran or the uncertain before-delete fence was prematurely restored.' );
	switch_to_blog( $secondary );
	try {
		$other = get_post( $id );
		$other_dynamic = get_post( $dynamic_id );
		$assert( $other instanceof WP_Post &&
			$other_fingerprint === wp_json_encode( $other->to_array() ) &&
			$other_dynamic instanceof WP_Post &&
			$dynamic_foreign_fingerprint === wp_json_encode( $other_dynamic->to_array() ) &&
			$secondary_activity_before === get_option( Mutation_Log::OPTION_NAME, null ),
			'Dynamic before-delete registration altered a foreign blog or its Activity.' );
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
	if ( $dynamic_doc && ! is_wp_error( $dynamic_doc ) ) {
		wp_delete_post( (int) $dynamic_doc['id'], true );
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
	if ( $dynamic_colliding && ! is_wp_error( $dynamic_colliding ) ) {
		switch_to_blog( $secondary );
		try {
			wp_delete_post( (int) $dynamic_colliding, true );
		} finally {
			restore_current_blog();
		}
	}
}
