<?php
/**
 * Real WordPress integration coverage for Issue #58 user/comment metadata.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai_issue58_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue58_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue58_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpai_issue58_item( $type, $id, $key, $include_values = false ) {
	$field = 'user' === $type ? 'user_id' : 'comment_id';
	$result = wpai_issue58_execute(
		'wp-ai-bridge/' . $type . '-meta-read',
		array(
			$field           => (int) $id,
			'key'            => (string) $key,
			'include_values' => (bool) $include_values,
		)
	);
	wpai_issue58_assert( ! is_wp_error( $result ), 'Exact ' . $type . ' metadata read failed for ' . $key . '.' );
	wpai_issue58_assert( 1 === count( $result['items'] ), 'Exact metadata read did not return one item.' );
	return $result['items'][0];
}

$settings        = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$admin_id        = get_current_user_id();
$user_id         = 0;
$post_id         = 0;
$comment_id      = 0;
$registered      = array();

try {
	$disabled                                       = $settings->defaults();
	$disabled[ Settings::GROUP_ADVANCED_METADATA ] = 0;
	update_option( Settings::OPTION_NAME, $disabled, false );

	$user_id = wp_insert_user(
		array(
			'user_login' => 'issue58-user-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'issue58-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpai_issue58_assert( ! is_wp_error( $user_id ) && $user_id > 0, 'Could not create Issue #58 user fixture.' );

	$post_id = wp_insert_post(
		array(
			'post_title'  => 'Issue 58 comment host',
			'post_status' => 'publish',
			'post_type'   => 'post',
		)
	);
	wpai_issue58_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Could not create Issue #58 post fixture.' );
	$comment_id = wp_insert_comment(
		array(
			'comment_post_ID'      => (int) $post_id,
			'comment_content'      => 'Issue 58 comment fixture',
			'comment_author'       => 'Issue 58',
			'comment_author_email' => 'issue58-comment@example.invalid',
			'comment_approved'     => 1,
		)
	);
	wpai_issue58_assert( $comment_id > 0, 'Could not create Issue #58 comment fixture.' );

	$disabled_read = wpai_issue58_execute( 'wp-ai-bridge/user-meta-read', array( 'user_id' => (int) $user_id, 'key' => 'issue58_normal' ) );
	wpai_issue58_assert( is_wp_error( $disabled_read ), 'Advanced Metadata default-off boundary was bypassed.' );

	$access                                      = $settings->defaults();
	$access[ Settings::GROUP_ADVANCED_METADATA ] = 1;
	$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );

	foreach ( array( 'user', 'comment' ) as $type ) {
		foreach ( array( 'read', 'update', 'delete' ) as $operation ) {
			wpai_issue58_assert( wp_get_ability( 'wp-ai-bridge/' . $type . '-meta-' . $operation ) instanceof WP_Ability, 'Missing Issue #58 ability.' );
		}
	}

	$empty = wpai_issue58_item( 'user', $user_id, 'issue58_normal' );
	wpai_issue58_assert( 0 === $empty['count'], 'Expected empty user metadata state.' );
	$created = wpai_issue58_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => 'issue58_normal',
			'expected_state_hash' => $empty['state_hash'],
			'value_json'          => wp_json_encode( array( 'alpha' => 1 ) ),
		)
	);
	wpai_issue58_assert( ! is_wp_error( $created ) && 1 === $created['count'], 'User metadata create failed.' );
	$read_user = wpai_issue58_item( 'user', $user_id, 'issue58_normal', true );
	wpai_issue58_assert( wp_json_encode( array( 'alpha' => 1 ) ) === $read_user['values'][0]['value_json'], 'User metadata exact value changed unexpectedly.' );

	$stale = wpai_issue58_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => 'issue58_normal',
			'expected_state_hash' => $empty['state_hash'],
			'value_json'          => '"stale"',
		)
	);
	wpai_issue58_assert( is_wp_error( $stale ), 'Stale user metadata update was accepted.' );

	register_meta(
		'user',
		'issue58_sanitized',
		array(
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => static function ( $value ) {
				return strtoupper( (string) $value );
			},
		)
	);
	$registered[]    = array( 'user', 'issue58_sanitized' );
	$sanitized_empty = wpai_issue58_item( 'user', $user_id, 'issue58_sanitized' );
	$sanitized       = wpai_issue58_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => 'issue58_sanitized',
			'expected_state_hash' => $sanitized_empty['state_hash'],
			'value_json'          => '"mixedCase"',
		)
	);
	wpai_issue58_assert( ! is_wp_error( $sanitized ), 'Registered user metadata sanitizer path failed.' );
	wpai_issue58_assert( 'MIXEDCASE' === get_user_meta( $user_id, 'issue58_sanitized', true ), 'Registered user metadata sanitizer was bypassed.' );

	$protected_key = '_issue58_private';
	add_user_meta( $user_id, $protected_key, 'private-value', true );
	$protected = wpai_issue58_item( 'user', $user_id, $protected_key, true );
	wpai_issue58_assert( '"private-value"' === $protected['values'][0]['value_json'], 'Advanced Metadata did not unlock ordinary protected user metadata.' );

	$deny_key = '_issue58_provider_denied';
	add_user_meta( $user_id, $deny_key, 'denied-value', true );
	$deny_filter = static function () {
		return false;
	};
	add_filter( 'auth_user_meta_' . $deny_key . '_for_user', $deny_filter, 10, 6 );
	$denied = wpai_issue58_execute( 'wp-ai-bridge/user-meta-read', array( 'user_id' => (int) $user_id, 'key' => $deny_key, 'include_values' => true ) );
	wpai_issue58_assert( is_wp_error( $denied ), 'Explicit provider auth denial was bypassed for user metadata.' );
	remove_filter( 'auth_user_meta_' . $deny_key . '_for_user', $deny_filter, 10 );

	global $wpdb;
	foreach ( array( $wpdb->prefix . 'capabilities', $wpdb->prefix . 'user_level', 'session_tokens', '_application_passwords', 'api_token' ) as $sensitive_key ) {
		$sensitive = wpai_issue58_execute( 'wp-ai-bridge/user-meta-read', array( 'user_id' => (int) $user_id, 'key' => $sensitive_key, 'include_values' => true ) );
		wpai_issue58_assert( is_wp_error( $sensitive ), 'Sensitive user authority/auth key was exposed: ' . $sensitive_key );
	}

	$comment_empty = wpai_issue58_item( 'comment', $comment_id, 'issue58_comment' );
	$comment_created = wpai_issue58_execute(
		'wp-ai-bridge/comment-meta-update',
		array(
			'comment_id'          => (int) $comment_id,
			'key'                 => 'issue58_comment',
			'expected_state_hash' => $comment_empty['state_hash'],
			'value_json'          => '"first"',
		)
	);
	wpai_issue58_assert( ! is_wp_error( $comment_created ), 'Comment metadata create failed.' );
	$comment_read = wpai_issue58_item( 'comment', $comment_id, 'issue58_comment', true );
	wpai_issue58_assert( '"first"' === $comment_read['values'][0]['value_json'], 'Comment metadata exact read returned the wrong value.' );

	add_comment_meta( $comment_id, 'issue58_multi', 'one', false );
	add_comment_meta( $comment_id, 'issue58_multi', 'two', false );
	$multi = wpai_issue58_item( 'comment', $comment_id, 'issue58_multi' );
	wpai_issue58_assert( 2 === $multi['count'], 'Comment metadata multi-row fixture was not visible as ambiguous state.' );
	$multi_update = wpai_issue58_execute(
		'wp-ai-bridge/comment-meta-update',
		array(
			'comment_id'          => (int) $comment_id,
			'key'                 => 'issue58_multi',
			'expected_state_hash' => $multi['state_hash'],
			'value_json'          => '"blocked"',
		)
	);
	wpai_issue58_assert( is_wp_error( $multi_update ), 'Ambiguous multi-row comment metadata update was accepted.' );

	add_user_meta( $user_id, 'issue58_object', (object) array( 'opaque' => 'value' ), true );
	$object_read = wpai_issue58_execute( 'wp-ai-bridge/user-meta-read', array( 'user_id' => (int) $user_id, 'key' => 'issue58_object', 'include_values' => true ) );
	wpai_issue58_assert( is_wp_error( $object_read ), 'Lossy PHP object metadata was exposed through JSON.' );

	$comment_state = wpai_issue58_item( 'comment', $comment_id, 'issue58_comment' );
	$no_delete = $access;
	$no_delete[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
	update_option( Settings::OPTION_NAME, $no_delete, false );
	$blocked_delete = wpai_issue58_execute( 'wp-ai-bridge/comment-meta-delete', array( 'comment_id' => (int) $comment_id, 'key' => 'issue58_comment', 'expected_state_hash' => $comment_state['state_hash'] ) );
	wpai_issue58_assert( is_wp_error( $blocked_delete ) && metadata_exists( 'comment', $comment_id, 'issue58_comment' ), 'Destructive metadata gate was bypassed.' );
	update_option( Settings::OPTION_NAME, $access, false );
	$deleted = wpai_issue58_execute( 'wp-ai-bridge/comment-meta-delete', array( 'comment_id' => (int) $comment_id, 'key' => 'issue58_comment', 'expected_state_hash' => $comment_state['state_hash'] ) );
	wpai_issue58_assert( ! is_wp_error( $deleted ) && true === $deleted['deleted'] && ! metadata_exists( 'comment', $comment_id, 'issue58_comment' ), 'Authorized comment metadata delete failed.' );

	wp_set_current_user( $user_id );
	$unauthorized = wpai_issue58_execute( 'wp-ai-bridge/user-meta-read', array( 'user_id' => (int) $admin_id, 'key' => 'issue58_normal' ) );
	wpai_issue58_assert( is_wp_error( $unauthorized ), 'Subscriber could access another user metadata target.' );
	wp_set_current_user( $admin_id );

	$missing = wpai_issue58_execute( 'wp-ai-bridge/comment-meta-read', array( 'comment_id' => 999999999, 'key' => 'issue58_missing' ) );
	wpai_issue58_assert( is_wp_error( $missing ), 'Nonexistent comment metadata target was accepted.' );

	echo "PASS: Issue #58 bounded user/comment metadata integration.\n";
} finally {
	wp_set_current_user( $admin_id );
	foreach ( $registered as $registration ) {
		unregister_meta_key( $registration[0], $registration[1] );
	}
	update_option( Settings::OPTION_NAME, $original_access, false );
	if ( $comment_id > 0 ) {
		wp_delete_comment( $comment_id, true );
	}
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	if ( $user_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
}
