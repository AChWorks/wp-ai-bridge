<?php
/**
 * Real WordPress persistence-boundary adversarial coverage for Issue #61 / F-004/F-005.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Settings;

function wpai_issue61_persist_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue61_persist_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue61_persist_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpai_issue61_persist_error_blob( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return '';
	}
	return wp_json_encode( array( $error->get_error_code(), $error->get_error_message(), $error->get_error_data() ) );
}

function wpai_issue61_persist_assert_private( $blob, array $needles, $context ) {
	foreach ( $needles as $needle ) {
		if ( is_string( $needle ) && '' !== $needle ) {
			wpai_issue61_persist_assert( false === strpos( $blob, $needle ), $context . ' leaked credential/provenance state.' );
		}
	}
}

$settings            = new Settings();
$original_access     = get_option( Settings::OPTION_NAME, $settings->defaults() );
$admin_id            = get_current_user_id();
$target_user         = 0;
$global_availability = false;
$f004_callback       = null;
$f005_prepare        = null;
$f005_before         = null;
$f005_capture        = null;
$f005_late_metadata  = null;
$baseline_uuid       = '';
$f004_nested_uuid    = '';
$f005_changed_uuid   = '';
$f005_replace_uuid   = '';

try {
	$enabled                                    = $settings->defaults();
	$enabled[ Settings::GROUP_AUTHENTICATION ]  = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	add_filter( 'wp_is_application_passwords_available', '__return_true' );
	$global_availability = true;

	$target_user = wp_insert_user(
		array(
			'user_login' => 'issue61-persist-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'issue61-persist-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpai_issue61_persist_assert( ! is_wp_error( $target_user ) && $target_user > 0, 'Could not create persistence-boundary user fixture.' );

	$baseline = wpai_issue61_persist_execute(
		'wp-ai-bridge/application-password-create',
		array( 'user_id' => (int) $target_user, 'name' => 'Issue 61 persistence baseline' )
	);
	wpai_issue61_persist_assert( ! is_wp_error( $baseline ), 'Could not create persistence-boundary baseline credential.' );
	$baseline_uuid   = $baseline['item']['uuid'];
	$baseline_secret = $baseline['password'];
	$baseline_item   = WP_Application_Passwords::get_user_application_password( $target_user, $baseline_uuid );
	wpai_issue61_persist_assert( is_array( $baseline_item ) && isset( $baseline_item['password'] ), 'Persistence baseline credential is unavailable.' );
	$baseline_hash = $baseline_item['password'];

	/* F-004: re-enter the same metadata key before Bridge sees the genuine outer persistence attempt. */
	$f004_guard         = false;
	$f004_nested_uuid   = wp_generate_uuid4();
	$f004_nested_secret = 'issue61-f004-provider-secret';
	$f004_nested_hash   = WP_Application_Passwords::hash_password( $f004_nested_secret );
	$f004_nested_app_id = '11111111-aaaa-4bbb-8ccc-444444444444';
	$f004_outer_app_id  = '22222222-bbbb-4ccc-8ddd-555555555555';
	$f004_callback = static function ( $check, $observed_user_id, $meta_key, $meta_value ) use ( $target_user, &$f004_guard, $f004_nested_uuid, $f004_nested_hash, $f004_nested_app_id ) {
		if ( $f004_guard
			|| (int) $observed_user_id !== (int) $target_user
			|| WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS !== $meta_key
			|| ! is_array( $meta_value ) ) {
			return $check;
		}
		$outer = false;
		foreach ( $meta_value as $item ) {
			if ( is_array( $item ) && 'Issue 61 F004 outer create' === ( $item['name'] ?? '' ) ) {
				$outer = true;
				break;
			}
		}
		if ( ! $outer ) {
			return $check;
		}

		$f004_guard = true;
		try {
			$nested = get_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, true );
			$nested = is_array( $nested ) ? $nested : array();
			$nested[] = array(
				'uuid'      => $f004_nested_uuid,
				'app_id'    => $f004_nested_app_id,
				'name'      => 'Issue 61 F004 provider nested',
				'password'  => $f004_nested_hash,
				'created'   => time(),
				'last_used' => null,
				'last_ip'   => null,
			);
			update_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $nested );
		} finally {
			$f004_guard = false;
		}
		throw new RuntimeException( 'Issue 61 F004 provider preemption unsafe details' );
	};
	add_filter( 'update_user_metadata', $f004_callback, 10, 4 );
	try {
		$f004_result = wpai_issue61_persist_execute(
			'wp-ai-bridge/application-password-create',
			array(
				'user_id' => (int) $target_user,
				'name'    => 'Issue 61 F004 outer create',
				'app_id'  => $f004_outer_app_id,
			)
		);
	} finally {
		remove_filter( 'update_user_metadata', $f004_callback, 10 );
		$f004_callback = null;
	}
	wpai_issue61_persist_assert( is_wp_error( $f004_result ) && 'application_password_create_recovery_required' === $f004_result->get_error_code(), 'F-004 re-entrant metadata preemption did not require recovery.' );
	wpai_issue61_persist_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $f004_nested_uuid ) ), 'F-004 provider-owned nested credential was deleted.' );
	wpai_issue61_persist_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $baseline_uuid ) ), 'F-004 changed the pre-existing baseline credential.' );
	wpai_issue61_persist_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-004 changed Application Password state beyond the provider-owned nested write.' );
	$f004_blob = wpai_issue61_persist_error_blob( $f004_result ) . wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	wpai_issue61_persist_assert_private( $f004_blob, array( $f004_nested_uuid, $f004_nested_secret, $f004_nested_hash, $f004_nested_app_id, $f004_outer_app_id, $baseline_secret, $baseline_hash, 'unsafe details' ), 'F-004' );
	WP_Application_Passwords::delete_application_password( $target_user, $f004_nested_uuid );
	$f004_nested_uuid = '';
	wpai_issue61_persist_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-004 fixture cleanup did not restore baseline.' );

	/* F-005a: mutate a non-secret field after early fingerprint verification, before DELETE persistence. */
	$f005_name           = 'Issue 61 F005 changed fingerprint';
	$f005_changed_app_id = '33333333-cccc-4ddd-8eee-666666666666';
	$f005_changed_secret = '';
	$f005_changed_hash   = '';
	$f005_changed_done   = false;
	$f005_capture = static function ( $observed_user_id, $item, $new_password, $args ) use ( $target_user, $f005_name, &$f005_changed_secret ) {
		if ( (int) $observed_user_id === (int) $target_user && is_array( $args ) && $f005_name === ( $args['name'] ?? '' ) ) {
			$f005_changed_secret = is_string( $new_password ) ? $new_password : '';
		}
	};
	$f005_prepare = static function ( $response, $item, $request ) use ( $target_user, $f005_name ) {
		if ( $request instanceof WP_REST_Request
			&& 'POST' === $request->get_method()
			&& '/wp/v2/users/' . (int) $target_user . '/application-passwords' === $request->get_route()
			&& $f005_name === $request->get_param( 'name' )
			&& $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				unset( $data['password'] );
				$response->set_data( $data );
			}
		}
		return $response;
	};
	$f005_before = static function ( $response, $handler, $request ) use ( $target_user, &$f005_changed_uuid, &$f005_changed_hash, &$f005_changed_done, $f005_changed_app_id ) {
		if ( $f005_changed_done || ! $request instanceof WP_REST_Request || 'DELETE' !== $request->get_method() ) {
			return $response;
		}
		$prefix = '/wp/v2/users/' . (int) $target_user . '/application-passwords/';
		if ( 0 !== strpos( $request->get_route(), $prefix ) ) {
			return $response;
		}
		$f005_changed_uuid = substr( $request->get_route(), strlen( $prefix ) );
		$items = get_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, true );
		$items = is_array( $items ) ? $items : array();
		foreach ( $items as &$stored ) {
			if ( is_array( $stored ) && ( $stored['uuid'] ?? '' ) === $f005_changed_uuid ) {
				$stored['name']   = 'Issue 61 F005 changed after verification';
				$stored['app_id'] = $f005_changed_app_id;
				$f005_changed_hash = isset( $stored['password'] ) && is_string( $stored['password'] ) ? $stored['password'] : '';
				break;
			}
		}
		unset( $stored );
		update_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $items );
		$f005_changed_done = true;
		return $response;
	};
	add_action( 'wp_create_application_password', $f005_capture, 20, 4 );
	add_filter( 'rest_prepare_application_password', $f005_prepare, 20, 3 );
	add_filter( 'rest_request_before_callbacks', $f005_before, 10, 3 );
	try {
		$f005_changed_result = wpai_issue61_persist_execute(
			'wp-ai-bridge/application-password-create',
			array( 'user_id' => (int) $target_user, 'name' => $f005_name )
		);
	} finally {
		remove_filter( 'rest_request_before_callbacks', $f005_before, 10 );
		remove_filter( 'rest_prepare_application_password', $f005_prepare, 20 );
		remove_action( 'wp_create_application_password', $f005_capture, 20 );
		$f005_before  = null;
		$f005_prepare = null;
		$f005_capture = null;
	}
	$f005_changed_item = WP_Application_Passwords::get_user_application_password( $target_user, $f005_changed_uuid );
	wpai_issue61_persist_assert( is_wp_error( $f005_changed_result ) && 'application_password_create_recovery_required' === $f005_changed_result->get_error_code(), 'F-005 changed-fingerprint interposition did not require recovery.' );
	wpai_issue61_persist_assert( $f005_changed_done && is_array( $f005_changed_item ) && 'Issue 61 F005 changed after verification' === $f005_changed_item['name'], 'F-005 changed-fingerprint credential did not survive guarded cleanup.' );
	wpai_issue61_persist_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $baseline_uuid ) ), 'F-005 changed-fingerprint flow deleted the baseline credential.' );
	wpai_issue61_persist_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-005 changed-fingerprint flow changed unrelated credential state.' );
	$f005_changed_blob = wpai_issue61_persist_error_blob( $f005_changed_result ) . wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	wpai_issue61_persist_assert_private( $f005_changed_blob, array( $f005_changed_uuid, $f005_changed_secret, $f005_changed_hash, $f005_changed_app_id, $baseline_uuid, $baseline_secret, $baseline_hash ), 'F-005 changed fingerprint' );
	WP_Application_Passwords::delete_application_password( $target_user, $f005_changed_uuid );
	$f005_changed_uuid = '';
	wpai_issue61_persist_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-005 changed-fingerprint fixture cleanup did not restore baseline.' );

	/* F-005b: replace the whole current credential but retain the captured UUID. */
	$f005_replace_name   = 'Issue 61 F005 same UUID replacement';
	$f005_replace_app_id = '44444444-dddd-4eee-8fff-777777777777';
	$f005_outer_secret   = '';
	$f005_replace_secret = 'issue61-f005-replacement-secret';
	$f005_replace_hash   = WP_Application_Passwords::hash_password( $f005_replace_secret );
	$f005_replace_done   = false;
	$f005_capture = static function ( $observed_user_id, $item, $new_password, $args ) use ( $target_user, $f005_replace_name, &$f005_outer_secret ) {
		if ( (int) $observed_user_id === (int) $target_user && is_array( $args ) && $f005_replace_name === ( $args['name'] ?? '' ) ) {
			$f005_outer_secret = is_string( $new_password ) ? $new_password : '';
		}
	};
	$f005_prepare = static function ( $response, $item, $request ) use ( $target_user, $f005_replace_name ) {
		if ( $request instanceof WP_REST_Request
			&& 'POST' === $request->get_method()
			&& '/wp/v2/users/' . (int) $target_user . '/application-passwords' === $request->get_route()
			&& $f005_replace_name === $request->get_param( 'name' )
			&& $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				unset( $data['password'] );
				$response->set_data( $data );
			}
		}
		return $response;
	};
	$f005_before = static function ( $response, $handler, $request ) use ( $target_user, &$f005_replace_uuid, &$f005_replace_done, $f005_replace_app_id, $f005_replace_hash ) {
		if ( $f005_replace_done || ! $request instanceof WP_REST_Request || 'DELETE' !== $request->get_method() ) {
			return $response;
		}
		$prefix = '/wp/v2/users/' . (int) $target_user . '/application-passwords/';
		if ( 0 !== strpos( $request->get_route(), $prefix ) ) {
			return $response;
		}
		$f005_replace_uuid = substr( $request->get_route(), strlen( $prefix ) );
		$items = get_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, true );
		$items = is_array( $items ) ? $items : array();
		foreach ( $items as &$stored ) {
			if ( is_array( $stored ) && ( $stored['uuid'] ?? '' ) === $f005_replace_uuid ) {
				$stored = array(
					'uuid'      => $f005_replace_uuid,
					'app_id'    => $f005_replace_app_id,
					'name'      => 'Issue 61 F005 replacement current credential',
					'password'  => $f005_replace_hash,
					'created'   => time(),
					'last_used' => null,
					'last_ip'   => null,
				);
				break;
			}
		}
		unset( $stored );
		update_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $items );
		$f005_replace_done = true;
		return $response;
	};
	add_action( 'wp_create_application_password', $f005_capture, 20, 4 );
	add_filter( 'rest_prepare_application_password', $f005_prepare, 20, 3 );
	add_filter( 'rest_request_before_callbacks', $f005_before, 10, 3 );
	try {
		$f005_replace_result = wpai_issue61_persist_execute(
			'wp-ai-bridge/application-password-create',
			array( 'user_id' => (int) $target_user, 'name' => $f005_replace_name )
		);
	} finally {
		remove_filter( 'rest_request_before_callbacks', $f005_before, 10 );
		remove_filter( 'rest_prepare_application_password', $f005_prepare, 20 );
		remove_action( 'wp_create_application_password', $f005_capture, 20 );
		$f005_before  = null;
		$f005_prepare = null;
		$f005_capture = null;
	}
	$f005_replace_item = WP_Application_Passwords::get_user_application_password( $target_user, $f005_replace_uuid );
	wpai_issue61_persist_assert( is_wp_error( $f005_replace_result ) && 'application_password_create_recovery_required' === $f005_replace_result->get_error_code(), 'F-005 same-UUID replacement did not require recovery.' );
	wpai_issue61_persist_assert( $f005_replace_done && is_array( $f005_replace_item ) && $f005_replace_hash === $f005_replace_item['password'], 'F-005 same-UUID replacement was deleted by guarded cleanup.' );
	wpai_issue61_persist_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $baseline_uuid ) ), 'F-005 same-UUID replacement flow deleted the baseline credential.' );
	wpai_issue61_persist_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-005 same-UUID replacement flow changed unrelated credential state.' );
	$f005_replace_blob = wpai_issue61_persist_error_blob( $f005_replace_result ) . wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	wpai_issue61_persist_assert_private( $f005_replace_blob, array( $f005_replace_uuid, $f005_outer_secret, $f005_replace_secret, $f005_replace_hash, $f005_replace_app_id, $baseline_uuid, $baseline_secret, $baseline_hash ), 'F-005 same UUID replacement' );
	WP_Application_Passwords::delete_application_password( $target_user, $f005_replace_uuid );
	$f005_replace_uuid = '';
	wpai_issue61_persist_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-005 replacement fixture cleanup did not restore baseline.' );

	/* F-005c: dynamically register a same-PHP_INT_MAX callback after the Bridge delete guard. */
	$f005_late_name         = 'Issue 61 F005 late same-priority replacement';
	$f005_late_app_id       = '55555555-eeee-4fff-8aaa-888888888888';
	$f005_late_secret       = 'issue61-f005-late-replacement-secret';
	$f005_late_hash         = WP_Application_Passwords::hash_password( $f005_late_secret );
	$f005_late_outer_secret = '';
	$f005_late_uuid         = '';
	$f005_late_done         = false;
	$f005_late_guard        = false;
	$f005_capture = static function ( $observed_user_id, $item, $new_password, $args ) use ( $target_user, $f005_late_name, &$f005_late_outer_secret ) {
		if ( (int) $observed_user_id === (int) $target_user && is_array( $args ) && $f005_late_name === ( $args['name'] ?? '' ) ) {
			$f005_late_outer_secret = is_string( $new_password ) ? $new_password : '';
		}
	};
	$f005_prepare = static function ( $response, $item, $request ) use ( $target_user, $f005_late_name ) {
		if ( $request instanceof WP_REST_Request
			&& 'POST' === $request->get_method()
			&& '/wp/v2/users/' . (int) $target_user . '/application-passwords' === $request->get_route()
			&& $f005_late_name === $request->get_param( 'name' )
			&& $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				unset( $data['password'] );
				$response->set_data( $data );
			}
		}
		return $response;
	};
	$f005_late_metadata = static function ( $check, $observed_user_id, $meta_key, $meta_value ) use ( $target_user, &$f005_late_guard, &$f005_late_done, &$f005_late_uuid, $f005_late_app_id, $f005_late_hash ) {
		if ( $f005_late_guard
			|| $f005_late_done
			|| (int) $observed_user_id !== (int) $target_user
			|| WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS !== $meta_key
			|| ! is_array( $meta_value ) ) {
			return $check;
		}
		$current = get_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, true );
		$current = is_array( $current ) ? $current : array();
		$proposed_uuids = array();
		foreach ( $meta_value as $proposed_item ) {
			if ( is_array( $proposed_item ) && isset( $proposed_item['uuid'] ) ) {
				$proposed_uuids[] = $proposed_item['uuid'];
			}
		}
		foreach ( $current as &$stored ) {
			if ( is_array( $stored ) && isset( $stored['uuid'] ) && ! in_array( $stored['uuid'], $proposed_uuids, true ) ) {
				$f005_late_uuid = $stored['uuid'];
				$stored = array(
					'uuid'      => $f005_late_uuid,
					'app_id'    => $f005_late_app_id,
					'name'      => 'Issue 61 F005 late replacement current credential',
					'password'  => $f005_late_hash,
					'created'   => time(),
					'last_used' => null,
					'last_ip'   => null,
				);
				$f005_late_guard = true;
				try {
					update_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $current );
				} finally {
					$f005_late_guard = false;
				}
				$f005_late_done = true;
				break;
			}
		}
		unset( $stored );
		return $check;
	};
	$f005_before = static function ( $response, $handler, $request ) use ( $target_user, &$f005_late_metadata ) {
		if ( ! $request instanceof WP_REST_Request || 'DELETE' !== $request->get_method() ) {
			return $response;
		}
		$prefix = '/wp/v2/users/' . (int) $target_user . '/application-passwords/';
		if ( 0 === strpos( $request->get_route(), $prefix ) ) {
			add_filter( 'update_user_metadata', $f005_late_metadata, PHP_INT_MAX, 4 );
		}
		return $response;
	};
	add_action( 'wp_create_application_password', $f005_capture, 20, 4 );
	add_filter( 'rest_prepare_application_password', $f005_prepare, 20, 3 );
	add_filter( 'rest_request_before_callbacks', $f005_before, 10, 3 );
	try {
		$f005_late_result = wpai_issue61_persist_execute(
			'wp-ai-bridge/application-password-create',
			array( 'user_id' => (int) $target_user, 'name' => $f005_late_name )
		);
	} finally {
		remove_filter( 'rest_request_before_callbacks', $f005_before, 10 );
		remove_filter( 'rest_prepare_application_password', $f005_prepare, 20 );
		remove_action( 'wp_create_application_password', $f005_capture, 20 );
		remove_filter( 'update_user_metadata', $f005_late_metadata, PHP_INT_MAX );
		$f005_before        = null;
		$f005_prepare       = null;
		$f005_capture       = null;
		$f005_late_metadata = null;
	}
	$f005_late_item = WP_Application_Passwords::get_user_application_password( $target_user, $f005_late_uuid );
	wpai_issue61_persist_assert( is_wp_error( $f005_late_result ) && 'application_password_create_recovery_required' === $f005_late_result->get_error_code(), 'F-005 late same-priority interposition did not require recovery.' );
	wpai_issue61_persist_assert( $f005_late_done && is_array( $f005_late_item ) && $f005_late_hash === $f005_late_item['password'], 'F-005 late same-priority replacement was deleted after the guard check.' );
	wpai_issue61_persist_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $baseline_uuid ) ), 'F-005 late same-priority flow deleted the baseline credential.' );
	wpai_issue61_persist_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-005 late same-priority flow changed unrelated credential state.' );
	$f005_late_blob = wpai_issue61_persist_error_blob( $f005_late_result ) . wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	wpai_issue61_persist_assert_private( $f005_late_blob, array( $f005_late_uuid, $f005_late_outer_secret, $f005_late_secret, $f005_late_hash, $f005_late_app_id, $baseline_uuid, $baseline_secret, $baseline_hash ), 'F-005 late same priority' );
	WP_Application_Passwords::delete_application_password( $target_user, $f005_late_uuid );
	$f005_late_uuid = '';

	WP_Application_Passwords::delete_application_password( $target_user, $baseline_uuid );
	$baseline_uuid = '';
	wpai_issue61_persist_assert( empty( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'Persistence-boundary fixture cleanup did not restore empty Application Password state.' );

	echo "PASS: Issue #61 F-004/F-005 persistence-boundary hardening.\n";
} finally {
	if ( is_callable( $f004_callback ) ) {
		remove_filter( 'update_user_metadata', $f004_callback, 10 );
	}
	if ( is_callable( $f005_before ) ) {
		remove_filter( 'rest_request_before_callbacks', $f005_before, 10 );
	}
	if ( is_callable( $f005_prepare ) ) {
		remove_filter( 'rest_prepare_application_password', $f005_prepare, 20 );
	}
	if ( is_callable( $f005_capture ) ) {
		remove_action( 'wp_create_application_password', $f005_capture, 20 );
	}	if ( is_callable( $f005_late_metadata ) ) {
		remove_filter( 'update_user_metadata', $f005_late_metadata, PHP_INT_MAX );
	}
	if ( $global_availability ) {
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
