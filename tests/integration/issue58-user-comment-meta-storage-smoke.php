<?php
/**
 * Storage-boundary coverage for Issue #58 user/comment metadata.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;
use WP_Native_Builder_Bridge\Support\User_Comment_Meta_Store;

function wpai_issue58_storage_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue58_storage_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue58_storage_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpai_issue58_storage_empty_hash( $user_id, $key ) {
	$empty = wpai_issue58_storage_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id' => (int) $user_id,
			'key'     => (string) $key,
		)
	);
	wpai_issue58_storage_assert( ! is_wp_error( $empty ) && 1 === count( $empty['items'] ) && 0 === $empty['items'][0]['count'], 'Could not establish empty metadata state.' );
	return $empty['items'][0]['state_hash'];
}

$settings        = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$admin_id        = get_current_user_id();
$user_id         = 0;
$expanded_key    = 'issue58_sanitizer_expansion';
$registered      = false;

try {
	$access                                      = $settings->defaults();
	$access[ Settings::GROUP_ADVANCED_METADATA ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );

	$user_id = wp_insert_user(
		array(
			'user_login' => 'issue58-storage-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'issue58-storage-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpai_issue58_storage_assert( ! is_wp_error( $user_id ) && $user_id > 0, 'Could not create Issue #58 storage user.' );

	$key_255 = 'issue58_' . str_repeat( 'k', 247 );
	wpai_issue58_storage_assert( 255 === strlen( $key_255 ), 'Issue #58 255-character metadata key fixture is invalid.' );
	$empty_hash  = wpai_issue58_storage_empty_hash( $user_id, $key_255 );
	$slash_value = 'C:\\bridge\\path\\tail';
	$created = wpai_issue58_storage_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => $key_255,
			'expected_state_hash' => $empty_hash,
			'value_json'          => wp_json_encode( $slash_value ),
		)
	);
	wpai_issue58_storage_assert( ! is_wp_error( $created ), 'Issue #58 could not create a 255-character user metadata key.' );
	wpai_issue58_storage_assert( $slash_value === get_user_meta( $user_id, $key_255, true ), 'Create-path metadata slashing changed a canonical backslash value.' );
	wpai_issue58_storage_assert( wp_json_encode( $slash_value ) === $created['values'][0]['value_json'], 'Created backslash metadata value was not returned byte-equivalently through JSON.' );

	$exact = wpai_issue58_storage_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id'        => (int) $user_id,
			'key'            => $key_255,
			'include_values' => true,
		)
	);
	wpai_issue58_storage_assert( ! is_wp_error( $exact ), 'Exact read failed for the 255-character metadata key.' );
	wpai_issue58_storage_assert( wp_json_encode( $slash_value ) === $exact['items'][0]['values'][0]['value_json'], 'Exact read changed the canonical backslash value.' );

	$large_key   = 'issue58_oversized_physical_value';
	$large_value = str_repeat( 'x', User_Comment_Meta_Store::MAX_VALUE_BYTES + 1 );
	add_user_meta( $user_id, $large_key, $large_value, true );
	$oversized = wpai_issue58_storage_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id'        => (int) $user_id,
			'key'            => $large_key,
			'include_values' => true,
		)
	);
	wpai_issue58_storage_assert( is_wp_error( $oversized ) && 'object_meta_value_too_large' === $oversized->get_error_code(), 'Oversized physical metadata value did not fail the bounded exact-read contract.' );

	$registered = register_meta(
		'user',
		$expanded_key,
		array(
			'single'            => true,
			'type'              => 'string',
			'sanitize_callback' => static function () {
				return str_repeat( 'z', User_Comment_Meta_Store::MAX_VALUE_BYTES + 1 );
			},
			'auth_callback'     => '__return_true',
		)
	);
	wpai_issue58_storage_assert( true === $registered, 'Could not register sanitizer-expansion metadata fixture.' );
	$expanded_hash = wpai_issue58_storage_empty_hash( $user_id, $expanded_key );
	$expanded = wpai_issue58_storage_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => $expanded_key,
			'expected_state_hash' => $expanded_hash,
			'value_json'          => '"small"',
		)
	);
	wpai_issue58_storage_assert( is_wp_error( $expanded ) && 'object_meta_value_too_large' === $expanded->get_error_code(), 'Sanitizer-expanded oversized create did not fail before persistence.' );
	wpai_issue58_storage_assert( ! metadata_exists( 'user', $user_id, $expanded_key ), 'Sanitizer-expanded oversized create left committed metadata behind.' );

	echo "PASS: Issue #58 native key length, create slashing, and bounded physical value storage.\n";
} finally {
	if ( $registered && function_exists( 'unregister_meta_key' ) ) {
		unregister_meta_key( 'user', $expanded_key );
	}
	wp_set_current_user( $admin_id );
	update_option( Settings::OPTION_NAME, $original_access, false );
	if ( $user_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
}
