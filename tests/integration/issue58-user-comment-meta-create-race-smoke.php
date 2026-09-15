<?php
/**
 * Concurrent create ownership coverage for Issue #58 metadata.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue58_create_race_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue58_create_race_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpnb_issue58_create_race_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

$settings        = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$admin_id        = get_current_user_id();
$user_id         = 0;
$key             = 'issue58_create_race';
$raced           = false;
$hook            = null;

try {
	$access                                      = $settings->defaults();
	$access[ Settings::GROUP_ADVANCED_METADATA ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );

	$user_id = wp_insert_user(
		array(
			'user_login' => 'issue58-create-race-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'issue58-create-race-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpnb_issue58_create_race_assert( ! is_wp_error( $user_id ) && $user_id > 0, 'Could not create Issue #58 create-race user.' );

	$empty = wpnb_issue58_create_race_execute(
		'wp-native-builder/user-meta-read',
		array(
			'user_id' => (int) $user_id,
			'key'     => $key,
		)
	);
	wpnb_issue58_create_race_assert( ! is_wp_error( $empty ) && 1 === count( $empty['items'] ) && 0 === $empty['items'][0]['count'], 'Could not establish empty create-race metadata state.' );
	$empty_hash = $empty['items'][0]['state_hash'];

	$hook = static function ( $object_id, $meta_key, $meta_value ) use ( $user_id, $key, &$raced ) {
		if ( $raced || (int) $object_id !== (int) $user_id || (string) $meta_key !== $key || 'bridge' !== $meta_value ) {
			return;
		}
		$raced = true;
		add_user_meta( $user_id, $key, 'concurrent', true );
	};
	add_action( 'add_user_meta', $hook, 10, 3 );

	$result = wpnb_issue58_create_race_execute(
		'wp-native-builder/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => $key,
			'expected_state_hash' => $empty_hash,
			'value_json'          => '"bridge"',
		)
	);
	remove_action( 'add_user_meta', $hook, 10 );
	$hook = null;

	wpnb_issue58_create_race_assert( $raced, 'Concurrent create fixture did not run.' );
	wpnb_issue58_create_race_assert( is_wp_error( $result ) && 'stale_object_meta_conflict' === $result->get_error_code(), 'Concurrent metadata create did not fail stale.' );
	$values = get_user_meta( $user_id, $key, false );
	sort( $values );
	wpnb_issue58_create_race_assert( array( 'concurrent' ) === $values, 'Create-race cleanup removed concurrent state or retained the Bridge-owned raced row.' );

	echo "PASS: Issue #58 concurrent create ownership and cleanup.\n";
} finally {
	if ( is_callable( $hook ) ) {
		remove_action( 'add_user_meta', $hook, 10 );
	}
	wp_set_current_user( $admin_id );
	update_option( Settings::OPTION_NAME, $original_access, false );
	if ( $user_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
}
