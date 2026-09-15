<?php
/**
 * Real WordPress Application Password lifecycle coverage for Issue #61.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue61_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue61_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpnb_issue61_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpnb_issue61_contains_secret_field( $value ) {
	if ( ! is_array( $value ) ) {
		return false;
	}
	foreach ( $value as $key => $child ) {
		if ( is_string( $key ) && in_array( strtolower( $key ), array( 'password', 'user_pass' ), true ) ) {
			return true;
		}
		if ( is_array( $child ) && wpnb_issue61_contains_secret_field( $child ) ) {
			return true;
		}
	}
	return false;
}

$settings        = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$admin_id        = get_current_user_id();
$target_user     = 0;
$secret_one      = '';
$secret_two      = '';
$secret_three    = '';
$admin_fixture   = null;
$availability_filter        = null;
$global_availability_enabled = false;

try {
	wpnb_issue61_assert( 0 === $settings->defaults()[ Settings::GROUP_AUTHENTICATION ], 'Authentication & Credentials must default off.' );
	$legacy = array(
		Settings::GROUP_SITE_READ         => 1,
		Settings::GROUP_USERS_DESTRUCTIVE => 1,
		Settings::GROUP_ADVANCED_METADATA => 1,
	);
	update_option( Settings::OPTION_NAME, $legacy, false );
	wpnb_issue61_assert( 0 === $settings->all()[ Settings::GROUP_AUTHENTICATION ], 'Historical elevated consent silently enabled Authentication & Credentials.' );

	$target_user = wp_insert_user(
		array(
			'user_login' => 'issue61-app-pass-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'issue61-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpnb_issue61_assert( ! is_wp_error( $target_user ) && $target_user > 0, 'Could not create Issue #61 user fixture.' );

	$ability_names = array(
		'wp-native-builder/application-passwords-read',
		'wp-native-builder/application-password-create',
		'wp-native-builder/application-password-update',
		'wp-native-builder/application-password-delete',
		'wp-native-builder/application-passwords-delete-all',
	);
	foreach ( $ability_names as $ability_name ) {
		wpnb_issue61_assert( wp_get_ability( $ability_name ) instanceof WP_Ability, 'Issue #61 Ability is not registered: ' . $ability_name );
	}

	$disabled_operations = array(
		wpnb_issue61_execute( 'wp-native-builder/application-passwords-read', array( 'action' => 'list', 'user_id' => (int) $target_user ) ),
		wpnb_issue61_execute( 'wp-native-builder/application-passwords-read', array( 'action' => 'get', 'user_id' => (int) $target_user, 'uuid' => '11111111-2222-3333-4444-555555555555' ) ),
		wpnb_issue61_execute( 'wp-native-builder/application-password-create', array( 'user_id' => (int) $target_user, 'name' => 'Denied while disabled' ) ),
		wpnb_issue61_execute( 'wp-native-builder/application-password-update', array( 'user_id' => (int) $target_user, 'uuid' => '11111111-2222-3333-4444-555555555555', 'name' => 'Denied rename' ) ),
		wpnb_issue61_execute( 'wp-native-builder/application-password-delete', array( 'user_id' => (int) $target_user, 'uuid' => '11111111-2222-3333-4444-555555555555' ) ),
		wpnb_issue61_execute( 'wp-native-builder/application-passwords-delete-all', array( 'user_id' => (int) $target_user, 'confirm' => 'revoke_all' ) ),
	);
	foreach ( $disabled_operations as $disabled_operation ) {
		wpnb_issue61_assert( is_wp_error( $disabled_operation ), 'Default-off Authentication & Credentials allowed an Application Password operation.' );
	}
	wpnb_issue61_assert( empty( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'Denied default-off operations left Application Password state behind.' );

	$enabled                                      = $settings->defaults();
	$enabled[ Settings::GROUP_AUTHENTICATION ]    = 1;
	$enabled[ Settings::GROUP_ADVANCED_METADATA ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	add_filter( 'wp_is_application_passwords_available', '__return_true' );
	$global_availability_enabled = true;

	$created = wpnb_issue61_execute(
		'wp-native-builder/application-password-create',
		array(
			'user_id' => (int) $target_user,
			'name'    => 'Issue 61 primary',
			'app_id'  => '11111111-2222-3333-4444-555555555555',
		)
	);
	wpnb_issue61_assert( ! is_wp_error( $created ), 'Authorized Core Application Password create failed.' );
	wpnb_issue61_assert( ! empty( $created['password'] ) && is_string( $created['password'] ), 'Create did not return the one-time Core credential.' );
	wpnb_issue61_assert( isset( $created['item']['uuid'] ) && ! isset( $created['item']['password'] ), 'Create item metadata leaked or omitted the bounded identity.' );
	$secret_one = $created['password'];
	$uuid_one   = $created['item']['uuid'];

	$stored = WP_Application_Passwords::get_user_application_password( $target_user, $uuid_one );
	wpnb_issue61_assert( is_array( $stored ) && isset( $stored['password'] ), 'Core stored Application Password fixture is missing its internal hash.' );
	wpnb_issue61_assert( $stored['password'] !== $secret_one, 'Core persisted the plaintext Application Password instead of a hash.' );
	wpnb_issue61_assert( false === strpos( wp_json_encode( $created['item'] ), $stored['password'] ), 'Bridge create metadata exposed Core stored hash state.' );

	$list = wpnb_issue61_execute(
		'wp-native-builder/application-passwords-read',
		array( 'action' => 'list', 'user_id' => (int) $target_user )
	);
	wpnb_issue61_assert( ! is_wp_error( $list ) && 1 === $list['count'], 'Authorized Application Password list failed.' );
	wpnb_issue61_assert( ! wpnb_issue61_contains_secret_field( $list ) && false === strpos( wp_json_encode( $list ), $secret_one ), 'List returned reusable credential material.' );
	wpnb_issue61_assert( false === strpos( wp_json_encode( $list ), $stored['password'] ), 'List returned the stored Application Password hash.' );

	$get = wpnb_issue61_execute(
		'wp-native-builder/application-passwords-read',
		array( 'action' => 'get', 'user_id' => (int) $target_user, 'uuid' => $uuid_one )
	);
	wpnb_issue61_assert( ! is_wp_error( $get ) && 1 === $get['count'], 'Authorized exact Application Password read failed.' );
	wpnb_issue61_assert( ! wpnb_issue61_contains_secret_field( $get ) && false === strpos( wp_json_encode( $get ), $secret_one ), 'Exact read re-exposed the generated credential.' );

	$renamed = wpnb_issue61_execute(
		'wp-native-builder/application-password-update',
		array( 'user_id' => (int) $target_user, 'uuid' => $uuid_one, 'name' => 'Issue 61 renamed' )
	);
	wpnb_issue61_assert( ! is_wp_error( $renamed ) && 'Issue 61 renamed' === $renamed['name'], 'Core Application Password rename failed.' );
	wpnb_issue61_assert( ! wpnb_issue61_contains_secret_field( $renamed ) && false === strpos( wp_json_encode( $renamed ), $secret_one ), 'Update re-exposed credential material.' );

	$bad_uuid = wpnb_issue61_execute(
		'wp-native-builder/application-passwords-read',
		array( 'action' => 'get', 'user_id' => (int) $target_user, 'uuid' => '../not-a-uuid' )
	);
	wpnb_issue61_assert( is_wp_error( $bad_uuid ), 'Invalid Application Password UUID escaped the bounded route contract.' );

	$missing_user = wpnb_issue61_execute(
		'wp-native-builder/application-passwords-read',
		array( 'action' => 'list', 'user_id' => 999999999 )
	);
	wpnb_issue61_assert( is_wp_error( $missing_user ), 'Nonexistent Application Password user target was accepted.' );

	remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	$global_availability_enabled = false;
	add_filter( 'wp_is_application_passwords_available', '__return_false' );
	$globally_unavailable = wpnb_issue61_execute(
		'wp-native-builder/application-passwords-read',
		array( 'action' => 'list', 'user_id' => (int) $target_user )
	);
	wpnb_issue61_assert( is_wp_error( $globally_unavailable ), 'Bridge bypassed Core global Application Password availability.' );
	remove_filter( 'wp_is_application_passwords_available', '__return_false' );
	add_filter( 'wp_is_application_passwords_available', '__return_true' );
	$global_availability_enabled = true;

	$availability_filter = static function ( $available, $user ) use ( $target_user ) {
		return (int) $user->ID === (int) $target_user ? false : $available;
	};
	add_filter( 'wp_is_application_passwords_available_for_user', $availability_filter, 10, 2 );
	$unavailable = wpnb_issue61_execute(
		'wp-native-builder/application-passwords-read',
		array( 'action' => 'list', 'user_id' => (int) $target_user )
	);
	wpnb_issue61_assert( is_wp_error( $unavailable ) && 'application_passwords_disabled_for_user' === $unavailable->get_error_code(), 'Bridge bypassed Core per-user Application Password availability.' );
	remove_filter( 'wp_is_application_passwords_available_for_user', $availability_filter, 10 );
	$availability_filter        = null;
$global_availability_enabled = false;

	$generic_meta = wpnb_issue61_execute(
		'wp-native-builder/user-meta-read',
		array( 'user_id' => (int) $target_user, 'key' => '_application_passwords', 'include_values' => true )
	);
	wpnb_issue61_assert( is_wp_error( $generic_meta ), 'Generic user metadata exposed Application Password storage.' );

	$admin_created = wpnb_issue61_execute(
		'wp-native-builder/application-password-create',
		array( 'user_id' => (int) $admin_id, 'name' => 'Issue 61 admin authority fixture' )
	);
	wpnb_issue61_assert( ! is_wp_error( $admin_created ), 'Could not create administrator Application Password authority fixture.' );
	$admin_fixture = array(
		'uuid'   => $admin_created['item']['uuid'],
		'secret' => $admin_created['password'],
	);
	wp_set_current_user( $target_user );
	$denied_operations = array(
		wpnb_issue61_execute( 'wp-native-builder/application-passwords-read', array( 'action' => 'list', 'user_id' => (int) $admin_id ) ),
		wpnb_issue61_execute( 'wp-native-builder/application-passwords-read', array( 'action' => 'get', 'user_id' => (int) $admin_id, 'uuid' => $admin_fixture['uuid'] ) ),
		wpnb_issue61_execute( 'wp-native-builder/application-password-create', array( 'user_id' => (int) $admin_id, 'name' => 'Denied create' ) ),
		wpnb_issue61_execute( 'wp-native-builder/application-password-update', array( 'user_id' => (int) $admin_id, 'uuid' => $admin_fixture['uuid'], 'name' => 'Denied rename' ) ),
		wpnb_issue61_execute( 'wp-native-builder/application-password-delete', array( 'user_id' => (int) $admin_id, 'uuid' => $admin_fixture['uuid'] ) ),
		wpnb_issue61_execute( 'wp-native-builder/application-passwords-delete-all', array( 'user_id' => (int) $admin_id, 'confirm' => 'revoke_all' ) ),
	);
	foreach ( $denied_operations as $denied_operation ) {
		wpnb_issue61_assert( is_wp_error( $denied_operation ), 'Low-privilege principal bypassed a Core Application Password authority check.' );
	}
	wp_set_current_user( $admin_id );
	wpnb_issue61_assert( is_array( WP_Application_Passwords::get_user_application_password( $admin_id, $admin_fixture['uuid'] ) ), 'Denied principal changed administrator Application Password state.' );
	WP_Application_Passwords::delete_application_password( $admin_id, $admin_fixture['uuid'] );
	$admin_fixture = null;

	$revoked = wpnb_issue61_execute(
		'wp-native-builder/application-password-delete',
		array( 'user_id' => (int) $target_user, 'uuid' => $uuid_one )
	);
	wpnb_issue61_assert( ! is_wp_error( $revoked ) && true === $revoked['deleted'], 'Exact Application Password revoke failed.' );
	wpnb_issue61_assert( null === WP_Application_Passwords::get_user_application_password( $target_user, $uuid_one ), 'Exact revoke left the Core Application Password behind.' );

	$created_two = wpnb_issue61_execute(
		'wp-native-builder/application-password-create',
		array( 'user_id' => (int) $target_user, 'name' => 'Issue 61 bulk one' )
	);
	$created_three = wpnb_issue61_execute(
		'wp-native-builder/application-password-create',
		array( 'user_id' => (int) $target_user, 'name' => 'Issue 61 bulk two' )
	);
	wpnb_issue61_assert( ! is_wp_error( $created_two ) && ! is_wp_error( $created_three ), 'Could not create revoke-all fixtures.' );
	$secret_two   = $created_two['password'];
	$secret_three = $created_three['password'];

	$missing_confirm = wpnb_issue61_execute(
		'wp-native-builder/application-passwords-delete-all',
		array( 'user_id' => (int) $target_user )
	);
	wpnb_issue61_assert( is_wp_error( $missing_confirm ), 'Revoke-all succeeded without an explicit confirmation token.' );
	wpnb_issue61_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'Rejected revoke-all changed Application Password state.' );

	$deleted_all = wpnb_issue61_execute(
		'wp-native-builder/application-passwords-delete-all',
		array( 'user_id' => (int) $target_user, 'confirm' => 'revoke_all' )
	);
	wpnb_issue61_assert( ! is_wp_error( $deleted_all ) && true === $deleted_all['deleted'] && $deleted_all['count'] >= 2, 'Explicit revoke-all failed.' );
	wpnb_issue61_assert( empty( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'Explicit revoke-all left Core Application Passwords behind.' );

	wpnb_issue61_assert( false === strpos( wp_json_encode( get_option( Settings::OPTION_NAME, array() ) ), $secret_one ), 'Bridge settings persisted a generated Application Password.' );
	$log_json = wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	foreach ( array_filter( array( $secret_one, $secret_two, $secret_three ) ) as $secret ) {
		wpnb_issue61_assert( false === strpos( $log_json, $secret ), 'Mutation log captured a generated Application Password.' );
	}
	foreach ( array( $uuid_one, $created_two['item']['uuid'], $created_three['item']['uuid'] ) as $uuid ) {
		wpnb_issue61_assert( false === strpos( $log_json, $uuid ), 'Mutation log captured an Application Password UUID.' );
	}

	echo "PASS: Issue #61 Core Application Password lifecycle, authority and secret redaction.\n";
} finally {
	remove_filter( 'wp_is_application_passwords_available', '__return_false' );
	if ( $global_availability_enabled ) {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}
	if ( is_callable( $availability_filter ) ) {
		remove_filter( 'wp_is_application_passwords_available_for_user', $availability_filter, 10 );
	}
	wp_set_current_user( $admin_id );
	if ( is_array( $admin_fixture ) && ! empty( $admin_fixture['uuid'] ) ) {
		WP_Application_Passwords::delete_application_password( $admin_id, $admin_fixture['uuid'] );
	}
	if ( $target_user > 0 ) {
		WP_Application_Passwords::delete_all_application_passwords( $target_user );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $target_user );
	}
	update_option( Settings::OPTION_NAME, $original_access, false );
}
