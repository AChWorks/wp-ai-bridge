<?php
/**
 * Real WordPress same-request nested REST create coverage for Issue #61 / F-006.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue61_f006_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue61_f006_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpnb_issue61_f006_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpnb_issue61_f006_error_blob( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return '';
	}
	return wp_json_encode( array( $error->get_error_code(), $error->get_error_message(), $error->get_error_data() ) );
}

function wpnb_issue61_f006_assert_private( $blob, array $needles, $context ) {
	foreach ( $needles as $needle ) {
		if ( is_string( $needle ) && '' !== $needle ) {
			wpnb_issue61_f006_assert( false === strpos( $blob, $needle ), $context . ' leaked credential/provenance state.' );
		}
	}
}

$settings            = new Settings();
$original_access     = get_option( Settings::OPTION_NAME, $settings->defaults() );
$admin_id            = get_current_user_id();
$target_user         = 0;
$global_availability = false;
$f006_callback       = null;
$f006_delete_observer = null;
$baseline_uuid       = '';
$f006_nested_uuid    = '';

try {
	$enabled                                   = $settings->defaults();
	$enabled[ Settings::GROUP_AUTHENTICATION ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	add_filter( 'wp_is_application_passwords_available', '__return_true' );
	$global_availability = true;

	$target_user = wp_insert_user(
		array(
			'user_login' => 'issue61-f006-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'issue61-f006-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpnb_issue61_f006_assert( ! is_wp_error( $target_user ) && $target_user > 0, 'Could not create F-006 user fixture.' );

	$baseline = wpnb_issue61_f006_execute(
		'wp-native-builder/application-password-create',
		array(
			'user_id' => (int) $target_user,
			'name'    => 'Issue 61 F006 baseline',
		)
	);
	wpnb_issue61_f006_assert( ! is_wp_error( $baseline ), 'Could not create F-006 baseline credential.' );
	$baseline_uuid   = $baseline['item']['uuid'];
	$baseline_secret = $baseline['password'];
	$baseline_item   = WP_Application_Passwords::get_user_application_password( $target_user, $baseline_uuid );
	wpnb_issue61_f006_assert( is_array( $baseline_item ) && isset( $baseline_item['password'] ), 'F-006 baseline credential is unavailable.' );
	$baseline_hash = $baseline_item['password'];

	$f006_guard         = false;
	$f006_nested_secret = '';
	$f006_nested_hash   = '';
	$f006_nested_app_id = '56565656-7878-4901-8abc-121212121212';
	$f006_outer_app_id  = '67676767-8989-4012-8bcd-232323232323';
	$f006_outer_name    = 'Issue 61 F006 outer create';
	$f006_nested_name   = 'Issue 61 F006 provider nested';
	$f006_delete_routes = array();

	$f006_delete_observer = static function ( $response, $handler, $request ) use ( $target_user, &$f006_delete_routes ) {
		if ( $request instanceof WP_REST_Request && 'DELETE' === $request->get_method() ) {
			$prefix = '/wp/v2/users/' . (int) $target_user . '/application-passwords/';
			if ( 0 === strpos( $request->get_route(), $prefix ) ) {
				$f006_delete_routes[] = $request->get_route();
			}
		}
		return $response;
	};

	$f006_callback = static function ( $prepared, $request ) use ( $target_user, $f006_outer_name, $f006_nested_name, $f006_nested_app_id, &$f006_guard, &$f006_nested_uuid, &$f006_nested_secret, &$f006_nested_hash ) {
		if ( $f006_guard
			|| ! $request instanceof WP_REST_Request
			|| 'POST' !== $request->get_method()
			|| '/wp/v2/users/' . (int) $target_user . '/application-passwords' !== $request->get_route()
			|| $f006_outer_name !== $request->get_param( 'name' ) ) {
			return $prepared;
		}

		$f006_guard = true;
		$outer_name    = $request->get_param( 'name' );
		$outer_app_id  = $request->get_param( 'app_id' );
		$outer_context = $request->get_param( 'context' );
		try {
			$request->set_param( 'name', $f006_nested_name );
			$request->set_param( 'app_id', $f006_nested_app_id );
			$nested_response = rest_do_request( $request );
			$nested_data     = $nested_response instanceof WP_REST_Response ? $nested_response->get_data() : array();
			if ( is_array( $nested_data ) ) {
				$f006_nested_uuid   = isset( $nested_data['uuid'] ) && is_string( $nested_data['uuid'] ) ? $nested_data['uuid'] : '';
				$f006_nested_secret = isset( $nested_data['password'] ) && is_string( $nested_data['password'] ) ? $nested_data['password'] : '';
			}
			if ( '' !== $f006_nested_uuid ) {
				$nested_item = WP_Application_Passwords::get_user_application_password( $target_user, $f006_nested_uuid );
				$f006_nested_hash = is_array( $nested_item ) && isset( $nested_item['password'] ) && is_string( $nested_item['password'] ) ? $nested_item['password'] : '';
			}
		} finally {
			$request->set_param( 'name', $outer_name );
			$request->set_param( 'app_id', $outer_app_id );
			$request->set_param( 'context', $outer_context );
			$f006_guard = false;
		}

		return new WP_Error( 'issue61_f006_abort', 'Injected same-request outer abort unsafe details.' );
	};

	add_filter( 'rest_request_before_callbacks', $f006_delete_observer, 10, 3 );
	add_filter( 'rest_pre_insert_application_password', $f006_callback, 10, 2 );
	try {
		$f006_result = wpnb_issue61_f006_execute(
			'wp-native-builder/application-password-create',
			array(
				'user_id' => (int) $target_user,
				'name'    => $f006_outer_name,
				'app_id'  => $f006_outer_app_id,
			)
		);
	} finally {
		remove_filter( 'rest_pre_insert_application_password', $f006_callback, 10 );
		remove_filter( 'rest_request_before_callbacks', $f006_delete_observer, 10 );
		$f006_callback        = null;
		$f006_delete_observer = null;
	}

	$f006_nested_item = '' !== $f006_nested_uuid ? WP_Application_Passwords::get_user_application_password( $target_user, $f006_nested_uuid ) : null;
	wpnb_issue61_f006_assert( is_wp_error( $f006_result ) && 'application_password_create_recovery_required' === $f006_result->get_error_code(), 'F-006 same-request nested dispatch did not require recovery.' );
	wpnb_issue61_f006_assert( '' !== $f006_nested_uuid && '' !== $f006_nested_secret && '' !== $f006_nested_hash, 'F-006 fixture did not persist a distinct nested credential.' );
	wpnb_issue61_f006_assert( is_array( $f006_nested_item ) && $f006_nested_name === $f006_nested_item['name'] && $f006_nested_app_id === $f006_nested_item['app_id'], 'F-006 nested provider credential did not survive unchanged.' );
	wpnb_issue61_f006_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $baseline_uuid ) ), 'F-006 changed the baseline credential.' );
	wpnb_issue61_f006_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-006 changed Application Password state beyond the nested provider credential.' );
	wpnb_issue61_f006_assert( empty( $f006_delete_routes ), 'F-006 attempted a cleanup REST DELETE against nested same-request provenance.' );

	$f006_blob = wpnb_issue61_f006_error_blob( $f006_result ) . wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	wpnb_issue61_f006_assert_private(
		$f006_blob,
		array(
			$f006_nested_uuid,
			$f006_nested_secret,
			$f006_nested_hash,
			$f006_nested_app_id,
			$f006_outer_app_id,
			$baseline_uuid,
			$baseline_secret,
			$baseline_hash,
			'unsafe details',
		),
		'F-006'
	);

	WP_Application_Passwords::delete_application_password( $target_user, $f006_nested_uuid );
	$f006_nested_uuid = '';
	WP_Application_Passwords::delete_application_password( $target_user, $baseline_uuid );
	$baseline_uuid = '';
	wpnb_issue61_f006_assert( empty( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-006 fixture cleanup did not restore empty state.' );

	echo "PASS: Issue #61 F-006 same-request nested REST dispatch hardening.\n";
} finally {
	if ( is_callable( $f006_callback ) ) {
		remove_filter( 'rest_pre_insert_application_password', $f006_callback, 10 );
	}
	if ( is_callable( $f006_delete_observer ) ) {
		remove_filter( 'rest_request_before_callbacks', $f006_delete_observer, 10 );
	}
	if ( $global_availability ) {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}
	wp_set_current_user( $admin_id );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( $target_user > 0 ) {
		WP_Application_Passwords::delete_all_application_passwords( $target_user );
		wp_delete_user( $target_user );
	}
	update_option( Settings::OPTION_NAME, $original_access, false );
}
