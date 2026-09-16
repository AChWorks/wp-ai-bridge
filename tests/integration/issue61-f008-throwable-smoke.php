<?php
/**
 * Real WordPress Throwable normalization coverage for Issue #61 / F-008.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Settings;

function wpai_issue61_f008_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue61_f008_execute( $name, array $input ) {
	$ability = wp_get_ability( $name );
	wpai_issue61_f008_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpai_issue61_f008_assert_bounded_error( $result, array $unsafe_values, $label ) {
	wpai_issue61_f008_assert( is_wp_error( $result ), $label . ' did not return a bounded WP_Error.' );
	wpai_issue61_f008_assert( 'application_passwords_rest_request_failed' === $result->get_error_code(), $label . ' did not use the fixed Bridge error code.' );
	wpai_issue61_f008_assert( 'WordPress rejected the Application Password REST request.' === $result->get_error_message(), $label . ' did not use the fixed safe Bridge message.' );
	$data = $result->get_error_data();
	wpai_issue61_f008_assert( empty( $data ), $label . ' exposed arbitrary Throwable/status data.' );
	$blob = wp_json_encode( array( $result->get_error_code(), $result->get_error_message(), $data ) );
	foreach ( $unsafe_values as $unsafe ) {
		wpai_issue61_f008_assert( '' === $unsafe || false === strpos( $blob, $unsafe ), $label . ' leaked Throwable credential material.' );
	}
}

$settings             = new Settings();
$original_access      = get_option( Settings::OPTION_NAME, $settings->defaults() );
$admin_id             = get_current_user_id();
$target_user          = 0;
$fixture_uuid         = '';
$prepare_throw_filter = null;
$pre_dispatch_throw   = null;
$availability_enabled = false;

try {
	$target_user = wp_insert_user(
		array(
			'user_login' => 'issue61-f008-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'issue61-f008-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpai_issue61_f008_assert( ! is_wp_error( $target_user ) && $target_user > 0, 'Could not create F-008 user fixture.' );

	$enabled                                   = $settings->defaults();
	$enabled[ Settings::GROUP_AUTHENTICATION ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	add_filter( 'wp_is_application_passwords_available', '__return_true' );
	$availability_enabled = true;

	$app_id  = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
	$created = wpai_issue61_f008_execute(
		'wp-ai-bridge/application-password-create',
		array(
			'user_id' => (int) $target_user,
			'name'    => 'Issue 61 F008 fixture',
			'app_id'  => $app_id,
		)
	);
	wpai_issue61_f008_assert( ! is_wp_error( $created ), 'Could not create the F-008 Application Password fixture.' );
	$fixture_uuid = $created['item']['uuid'];
	$secret       = $created['password'];
	$chunked      = WP_Application_Passwords::chunk_password( $secret );
	$stored       = WP_Application_Passwords::get_user_application_password( $target_user, $fixture_uuid );
	wpai_issue61_f008_assert( is_array( $stored ) && isset( $stored['password'] ), 'F-008 fixture stored credential is unavailable.' );
	$stored_hash = $stored['password'];
	wpai_issue61_f008_assert( $stored_hash !== $secret, 'F-008 fixture did not retain a one-way stored password hash.' );

	$marker        = 'issue61_f008_secret_bearing_throwable';
	$prepare_seen  = 0;
	$prepare_hash  = '';
	$prepare_uuid  = '';
	$prepare_appid = '';
	$unsafe_values = array( $marker, $secret, $chunked, $stored_hash, $fixture_uuid, $app_id, 'RuntimeException' );

	$prepare_throw_filter = static function ( $response, $item, $request ) use ( $target_user, $fixture_uuid, $secret, $chunked, $marker, &$prepare_seen, &$prepare_hash, &$prepare_uuid, &$prepare_appid ) {
		if ( ! $request instanceof WP_REST_Request
			|| 'GET' !== $request->get_method()
			|| 0 !== strpos( $request->get_route(), '/wp/v2/users/' . (int) $target_user . '/application-passwords' )
			|| ! is_array( $item )
			|| $fixture_uuid !== ( $item['uuid'] ?? '' ) ) {
			return $response;
		}

		++$prepare_seen;
		$prepare_hash  = isset( $item['password'] ) && is_string( $item['password'] ) ? $item['password'] : '';
		$prepare_uuid  = isset( $item['uuid'] ) && is_string( $item['uuid'] ) ? $item['uuid'] : '';
		$prepare_appid = isset( $item['app_id'] ) && is_string( $item['app_id'] ) ? $item['app_id'] : '';

		throw new RuntimeException(
			implode( '|', array( $marker, $secret, $chunked, $prepare_hash, $prepare_uuid, $prepare_appid ) )
		);
	};
	add_filter( 'rest_prepare_application_password', $prepare_throw_filter, 10, 3 );
	try {
		$list_result = wpai_issue61_f008_execute(
			'wp-ai-bridge/application-passwords-read',
			array( 'action' => 'list', 'user_id' => (int) $target_user )
		);
		$get_result = wpai_issue61_f008_execute(
			'wp-ai-bridge/application-passwords-read',
			array( 'action' => 'get', 'user_id' => (int) $target_user, 'uuid' => $fixture_uuid )
		);
	} finally {
		remove_filter( 'rest_prepare_application_password', $prepare_throw_filter, 10 );
		$prepare_throw_filter = null;
	}

	wpai_issue61_f008_assert_bounded_error( $list_result, $unsafe_values, 'F-008 list prepare Throwable' );
	wpai_issue61_f008_assert_bounded_error( $get_result, $unsafe_values, 'F-008 get prepare Throwable' );
	wpai_issue61_f008_assert( $prepare_seen >= 2, 'F-008 real-Core prepare filter did not observe both read paths.' );
	wpai_issue61_f008_assert( $stored_hash === $prepare_hash, 'F-008 real-Core prepare callback did not receive the stored password hash.' );
	wpai_issue61_f008_assert( $fixture_uuid === $prepare_uuid, 'F-008 real-Core prepare callback did not receive the credential UUID.' );
	wpai_issue61_f008_assert( $app_id === $prepare_appid, 'F-008 real-Core prepare callback did not receive the credential app ID.' );

	$pre_dispatch_throw = static function ( $result, $server, $request ) use ( $target_user, $fixture_uuid, $secret, $chunked, $stored_hash, $app_id, $marker ) {
		if ( ! $request instanceof WP_REST_Request
			|| 0 !== strpos( $request->get_route(), '/wp/v2/users/' . (int) $target_user . '/application-passwords' )
			|| ! in_array( $request->get_method(), array( 'POST', 'DELETE' ), true ) ) {
			return $result;
		}

		throw new RuntimeException(
			implode( '|', array( $marker, $secret, $chunked, $stored_hash, $fixture_uuid, $app_id ) )
		);
	};
	add_filter( 'rest_pre_dispatch', $pre_dispatch_throw, 10, 3 );
	try {
		$update_result = wpai_issue61_f008_execute(
			'wp-ai-bridge/application-password-update',
			array( 'user_id' => (int) $target_user, 'uuid' => $fixture_uuid, 'name' => 'F008 must not persist' )
		);
		$delete_result = wpai_issue61_f008_execute(
			'wp-ai-bridge/application-password-delete',
			array( 'user_id' => (int) $target_user, 'uuid' => $fixture_uuid )
		);
		$delete_all_result = wpai_issue61_f008_execute(
			'wp-ai-bridge/application-passwords-delete-all',
			array( 'user_id' => (int) $target_user, 'confirm' => 'revoke_all' )
		);
	} finally {
		remove_filter( 'rest_pre_dispatch', $pre_dispatch_throw, 10 );
		$pre_dispatch_throw = null;
	}

	wpai_issue61_f008_assert_bounded_error( $update_result, $unsafe_values, 'F-008 update pre-dispatch Throwable' );
	wpai_issue61_f008_assert_bounded_error( $delete_result, $unsafe_values, 'F-008 delete pre-dispatch Throwable' );
	wpai_issue61_f008_assert_bounded_error( $delete_all_result, $unsafe_values, 'F-008 delete-all pre-dispatch Throwable' );

	$after = WP_Application_Passwords::get_user_application_password( $target_user, $fixture_uuid );
	wpai_issue61_f008_assert( is_array( $after ), 'F-008 Throwable handling changed credential state.' );
	wpai_issue61_f008_assert( 'Issue 61 F008 fixture' === ( $after['name'] ?? '' ), 'F-008 update Throwable persisted a rename.' );
	wpai_issue61_f008_assert( $stored_hash === ( $after['password'] ?? '' ), 'F-008 Throwable handling replaced the credential hash.' );
	wpai_issue61_f008_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-008 delete/delete-all Throwable changed credential count.' );

	$log_blob = wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	foreach ( $unsafe_values as $unsafe ) {
		wpai_issue61_f008_assert( '' === $unsafe || false === strpos( $log_blob, $unsafe ), 'F-008 mutation log leaked Throwable credential material.' );
	}

	echo "PASS: Issue #61 F-008 shared REST Throwable normalization and secret redaction.\n";
} finally {
	if ( is_callable( $prepare_throw_filter ) ) {
		remove_filter( 'rest_prepare_application_password', $prepare_throw_filter, 10 );
	}
	if ( is_callable( $pre_dispatch_throw ) ) {
		remove_filter( 'rest_pre_dispatch', $pre_dispatch_throw, 10 );
	}
	if ( $availability_enabled ) {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}
	wp_set_current_user( $admin_id );
	if ( $target_user > 0 ) {
		WP_Application_Passwords::delete_all_application_passwords( $target_user );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $target_user );
	}
	update_option( Settings::OPTION_NAME, $original_access, false );
}
