<?php
/**
 * Concurrent create ownership coverage for Issue #58 metadata.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpai_issue58_create_race_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue58_create_race_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue58_create_race_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpai_issue58_create_race_empty_hash( $user_id, $key ) {
	$empty = wpai_issue58_create_race_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id' => (int) $user_id,
			'key'     => (string) $key,
		)
	);
	wpai_issue58_create_race_assert( ! is_wp_error( $empty ) && 1 === count( $empty['items'] ) && 0 === $empty['items'][0]['count'], 'Could not establish empty create-race metadata state.' );
	return $empty['items'][0]['state_hash'];
}

$settings        = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$admin_id        = get_current_user_id();
$user_id         = 0;
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
	wpai_issue58_create_race_assert( ! is_wp_error( $user_id ) && $user_id > 0, 'Could not create Issue #58 create-race user.' );

	$key        = 'issue58_create_race';
	$empty_hash = wpai_issue58_create_race_empty_hash( $user_id, $key );
	$raced      = false;
	$hook       = static function ( $object_id, $meta_key, $meta_value ) use ( $user_id, $key, &$raced ) {
		if ( $raced || (int) $object_id !== (int) $user_id || (string) $meta_key !== $key || 'bridge' !== $meta_value ) {
			return;
		}
		$raced = true;
		add_user_meta( $user_id, $key, 'concurrent', true );
	};
	add_action( 'add_user_meta', $hook, 10, 3 );

	$result = wpai_issue58_create_race_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => $key,
			'expected_state_hash' => $empty_hash,
			'value_json'          => '"bridge"',
		)
	);
	remove_action( 'add_user_meta', $hook, 10 );
	$hook = null;

	wpai_issue58_create_race_assert( $raced, 'Concurrent create fixture did not run.' );
	wpai_issue58_create_race_assert( is_wp_error( $result ) && 'stale_object_meta_conflict' === $result->get_error_code(), 'Concurrent metadata create did not fail stale.' );
	$values = get_user_meta( $user_id, $key, false );
	sort( $values );
	wpai_issue58_create_race_assert( array( 'concurrent' ) === $values, 'Create-race cleanup removed concurrent state or retained the Bridge-owned raced row.' );

	$observer_key   = 'issue58_create_observer';
	$observer_hash  = wpai_issue58_create_race_empty_hash( $user_id, $observer_key );
	$observer_raced = false;
	$hook           = static function ( $meta_id, $object_id, $meta_key, $meta_value ) use ( $user_id, $observer_key, &$observer_raced ) {
		if ( $observer_raced || (int) $object_id !== (int) $user_id || (string) $meta_key !== $observer_key || 'bridge' !== $meta_value ) {
			return;
		}
		$observer_raced = true;
		update_metadata_by_mid( 'user', (int) $meta_id, 'observer' );
	};
	add_action( 'added_user_meta', $hook, 10, 4 );

	$observer_result = wpai_issue58_create_race_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => $observer_key,
			'expected_state_hash' => $observer_hash,
			'value_json'          => '"bridge"',
		)
	);
	remove_action( 'added_user_meta', $hook, 10 );
	$hook = null;

	wpai_issue58_create_race_assert( $observer_raced, 'Post-create observer fixture did not run.' );
	wpai_issue58_create_race_assert( is_wp_error( $observer_result ) && 'stale_object_meta_conflict' === $observer_result->get_error_code(), 'Observer-mutated create did not fail stale.' );
	wpai_issue58_create_race_assert( 'observer' === get_user_meta( $user_id, $observer_key, true ), 'Create cleanup overwrote or removed observer-owned newer state.' );

	echo "PASS: Issue #58 concurrent create ownership and cleanup.\n";
} finally {
	if ( is_callable( $hook ) ) {
		remove_action( 'add_user_meta', $hook, 10 );
		remove_action( 'added_user_meta', $hook, 10 );
	}
	wp_set_current_user( $admin_id );
	update_option( Settings::OPTION_NAME, $original_access, false );
	if ( $user_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
}
