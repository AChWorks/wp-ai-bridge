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
$secret_three                 = '';
$admin_fixture                = null;
$availability_filter          = null;
$global_availability_enabled  = false;
$malformed_capture_action     = null;
$malformed_prepare_filter     = null;
$substitution_capture_action   = null;
$substitution_prepare_filter   = null;
$preexisting_substitution_uuid = '';
$f002_capture_action           = null;
$f002_additional_field_active  = false;
$f002_had_previous_name_field  = false;
$f002_previous_name_field      = null;
$f002_callback_secret          = '';
$f002_callback_hash            = '';
$f002_callback_uuid            = '';
$f002_callback_app_id          = '';
$f007_shared_error_filter      = null;

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

	$f002_preexisting = wpnb_issue61_execute(
		'wp-native-builder/application-password-create',
		array( 'user_id' => (int) $target_user, 'name' => 'Issue 61 F002 pre-existing' )
	);
	wpnb_issue61_assert( ! is_wp_error( $f002_preexisting ), 'Could not create the F-002 pre-existing credential fixture.' );
	$f002_preexisting_uuid   = $f002_preexisting['item']['uuid'];
	$f002_preexisting_secret = $f002_preexisting['password'];
	$f002_preexisting_stored = WP_Application_Passwords::get_user_application_password( $target_user, $f002_preexisting_uuid );
	wpnb_issue61_assert( is_array( $f002_preexisting_stored ) && isset( $f002_preexisting_stored['password'] ), 'F-002 pre-existing credential fixture is unavailable.' );

	$f002_created_uuid = '';
	$f002_secret       = '';
	$f002_name         = 'Issue 61 post-persistence additional-field failure';
	$f002_app_id       = '22222222-3333-4444-8555-666666666666';
	$f002_capture_action = static function ( $observed_user_id, $item, $new_password, $args ) use ( $target_user, $f002_name, &$f002_created_uuid, &$f002_secret ) {
		if ( (int) $observed_user_id !== (int) $target_user
			|| ! is_array( $item )
			|| ! is_array( $args )
			|| $f002_name !== ( $args['name'] ?? '' ) ) {
			return;
		}
		$f002_created_uuid = isset( $item['uuid'] ) && is_string( $item['uuid'] ) ? $item['uuid'] : '';
		$f002_secret       = is_string( $new_password ) ? $new_password : '';
	};
	add_action( 'wp_create_application_password', $f002_capture_action, 20, 4 );

	global $wp_rest_additional_fields;
	$f002_had_previous_name_field = isset( $wp_rest_additional_fields['application-password']['name'] );
	if ( $f002_had_previous_name_field ) {
		$f002_previous_name_field = $wp_rest_additional_fields['application-password']['name'];
	}
	register_rest_field(
		'application-password',
		'name',
		array(
			'update_callback' => static function ( $value, $object, $field_name, $request ) use ( $target_user, $f002_name, &$f002_callback_secret, &$f002_callback_hash, &$f002_callback_uuid, &$f002_callback_app_id ) {
				if ( $request instanceof WP_REST_Request
					&& 'POST' === $request->get_method()
					&& '/wp/v2/users/' . (int) $target_user . '/application-passwords' === $request->get_route()
					&& $f002_name === $value ) {
					$f002_callback_secret = is_array( $object ) && isset( $object['new_password'] ) && is_string( $object['new_password'] ) ? $object['new_password'] : '';
					$f002_callback_hash   = is_array( $object ) && isset( $object['password'] ) && is_string( $object['password'] ) ? $object['password'] : '';
					$f002_callback_uuid   = is_array( $object ) && isset( $object['uuid'] ) && is_string( $object['uuid'] ) ? $object['uuid'] : '';
					$f002_callback_app_id = is_array( $object ) && isset( $object['app_id'] ) && is_string( $object['app_id'] ) ? $object['app_id'] : '';
					$unsafe = implode( '|', array( $f002_callback_secret, $f002_callback_hash, $f002_callback_uuid, $f002_callback_app_id ) );
					return new WP_Error(
						'issue61_f002_injected',
						'Injected secret-bearing post-persistence failure: ' . $unsafe,
						array(
							'status'         => 409,
							'unsafe_payload' => array(
								'new_password' => $f002_callback_secret,
								'password'     => $f002_callback_hash,
								'uuid'         => $f002_callback_uuid,
								'app_id'       => $f002_callback_app_id,
							),
						)
					);
				}
				return true;
			},
		)
	);
	$f002_additional_field_active = true;
	try {
		$f002_result = wpnb_issue61_execute(
			'wp-native-builder/application-password-create',
			array(
				'user_id' => (int) $target_user,
				'name'    => $f002_name,
				'app_id'  => $f002_app_id,
			)
		);
	} finally {
		remove_action( 'wp_create_application_password', $f002_capture_action, 20 );
		$f002_capture_action = null;
		if ( $f002_had_previous_name_field ) {
			$wp_rest_additional_fields['application-password']['name'] = $f002_previous_name_field;
		} else {
			unset( $wp_rest_additional_fields['application-password']['name'] );
			if ( empty( $wp_rest_additional_fields['application-password'] ) ) {
				unset( $wp_rest_additional_fields['application-password'] );
			}
		}
		$f002_additional_field_active = false;
	}
	wpnb_issue61_assert( is_wp_error( $f002_result ) && 'application_passwords_rest_request_failed' === $f002_result->get_error_code(), 'F-002 secret-bearing downstream REST failure was not normalized after bounded cleanup.' );
	wpnb_issue61_assert( 409 === (int) ( $f002_result->get_error_data()['status'] ?? 0 ), 'F-002 normalization did not preserve the bounded HTTP status.' );
	wpnb_issue61_assert( '' !== $f002_created_uuid && '' !== $f002_secret, 'F-002 fixture did not observe the credential persisted before the injected REST error.' );
	wpnb_issue61_assert( WP_Application_Passwords::chunk_password( $f002_secret ) === $f002_callback_secret, 'F-002 additional-field callback did not receive the generated credential sentinel.' );
	wpnb_issue61_assert( '' !== $f002_callback_hash, 'F-002 additional-field callback did not receive the stored password hash sentinel.' );
	wpnb_issue61_assert( $f002_created_uuid === $f002_callback_uuid, 'F-002 additional-field callback did not receive the persisted UUID sentinel.' );
	wpnb_issue61_assert( $f002_app_id === $f002_callback_app_id, 'F-002 additional-field callback did not receive the persisted app ID sentinel.' );
	wpnb_issue61_assert( $f002_created_uuid !== $f002_preexisting_uuid, 'F-002 fixture did not create a distinct credential.' );
	wpnb_issue61_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $f002_preexisting_uuid ) ), 'F-002 cleanup revoked the pre-existing credential.' );
	wpnb_issue61_assert( null === WP_Application_Passwords::get_user_application_password( $target_user, $f002_created_uuid ), 'F-002 cleanup left the newly persisted credential behind.' );
	wpnb_issue61_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-002 cleanup changed state beyond the exact newly persisted credential.' );
	$f002_error_blob = wp_json_encode( array( $f002_result->get_error_code(), $f002_result->get_error_message(), $f002_result->get_error_data() ) );
	foreach ( array( $f002_secret, $f002_callback_secret, $f002_callback_hash, $f002_created_uuid, $f002_app_id, 'issue61_f002_injected', 'Injected secret-bearing post-persistence failure' ) as $unsafe ) {
		wpnb_issue61_assert( false === strpos( $f002_error_blob, $unsafe ), 'F-002 normalized error leaked downstream credential/error material.' );
	}
	$f002_log_blob = wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	foreach ( array( $f002_secret, $f002_callback_secret, $f002_callback_hash, $f002_created_uuid, $f002_app_id, 'issue61_f002_injected' ) as $unsafe ) {
		wpnb_issue61_assert( false === strpos( $f002_log_blob, $unsafe ), 'F-002 mutation log leaked downstream credential/error material.' );
	}
	wpnb_issue61_assert( false === strpos( $f002_log_blob, $f002_preexisting_stored['password'] ), 'F-002 mutation log leaked a stored Application Password hash.' );
	wpnb_issue61_assert( false === strpos( $f002_log_blob, $f002_preexisting_secret ), 'F-002 mutation log leaked the pre-existing plaintext credential.' );
	wpnb_issue61_assert( ! array_key_exists( '__wp_ai_bridge_create_correlation', $f002_preexisting_stored ), 'F-002 correlation state was persisted in Application Password storage.' );

	$f007_shared_error_filter = static function ( $result, $server, $request ) use ( $target_user, $f002_preexisting_secret, $f002_preexisting_stored, $f002_preexisting_uuid ) {
		if ( ! $request instanceof WP_REST_Request || 0 !== strpos( $request->get_route(), '/wp/v2/users/' . (int) $target_user . '/application-passwords' ) ) {
			return $result;
		}
		$unsafe = implode( '|', array( $f002_preexisting_secret, $f002_preexisting_stored['password'], $f002_preexisting_uuid ) );
		return new WP_Error(
			'issue61_f007_shared_injected',
			'Injected shared-path secret-bearing failure: ' . $unsafe,
			array(
				'status'         => 403,
				'unsafe_payload' => array(
					'new_password' => $f002_preexisting_secret,
					'password'     => $f002_preexisting_stored['password'],
					'uuid'         => $f002_preexisting_uuid,
				),
			)
		);
	};
	add_filter( 'rest_pre_dispatch', $f007_shared_error_filter, 10, 3 );
	try {
		$f007_shared_results = array(
			wpnb_issue61_execute( 'wp-native-builder/application-passwords-read', array( 'action' => 'list', 'user_id' => (int) $target_user ) ),
			wpnb_issue61_execute( 'wp-native-builder/application-passwords-read', array( 'action' => 'get', 'user_id' => (int) $target_user, 'uuid' => $f002_preexisting_uuid ) ),
			wpnb_issue61_execute( 'wp-native-builder/application-password-update', array( 'user_id' => (int) $target_user, 'uuid' => $f002_preexisting_uuid, 'name' => 'F007 denied rename' ) ),
			wpnb_issue61_execute( 'wp-native-builder/application-password-delete', array( 'user_id' => (int) $target_user, 'uuid' => $f002_preexisting_uuid ) ),
			wpnb_issue61_execute( 'wp-native-builder/application-passwords-delete-all', array( 'user_id' => (int) $target_user, 'confirm' => 'revoke_all' ) ),
		);
	} finally {
		remove_filter( 'rest_pre_dispatch', $f007_shared_error_filter, 10 );
		$f007_shared_error_filter = null;
	}
	foreach ( $f007_shared_results as $f007_shared_result ) {
		wpnb_issue61_assert( is_wp_error( $f007_shared_result ) && 'application_passwords_rest_request_failed' === $f007_shared_result->get_error_code(), 'F-007 shared Application Password REST failure was not normalized.' );
		wpnb_issue61_assert( 403 === (int) ( $f007_shared_result->get_error_data()['status'] ?? 0 ), 'F-007 shared error normalization did not preserve bounded HTTP status.' );
		$f007_shared_blob = wp_json_encode( array( $f007_shared_result->get_error_code(), $f007_shared_result->get_error_message(), $f007_shared_result->get_error_data() ) );
		foreach ( array( $f002_preexisting_secret, $f002_preexisting_stored['password'], $f002_preexisting_uuid, 'issue61_f007_shared_injected', 'Injected shared-path secret-bearing failure' ) as $unsafe ) {
			wpnb_issue61_assert( false === strpos( $f007_shared_blob, $unsafe ), 'F-007 shared normalized error leaked downstream credential/error material.' );
		}
	}
	wpnb_issue61_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $f002_preexisting_uuid ) ), 'F-007 shared error-path audit changed Application Password state.' );

	WP_Application_Passwords::delete_application_password( $target_user, $f002_preexisting_uuid );
	wpnb_issue61_assert( empty( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-002/F-007 fixture cleanup did not restore empty Application Password state.' );

	$malformed_created_uuid = '';
	$malformed_secret       = '';
	$malformed_name         = 'Issue 61 missing response secret';
	$malformed_capture_action = static function ( $item, $request, $creating ) use ( $target_user, $malformed_name, &$malformed_created_uuid, &$malformed_secret ) {
		if ( true !== $creating
			|| ! $request instanceof WP_REST_Request
			|| 'POST' !== $request->get_method()
			|| '/wp/v2/users/' . (int) $target_user . '/application-passwords' !== $request->get_route()
			|| $malformed_name !== $request->get_param( 'name' )
			|| ! is_array( $item ) ) {
			return;
		}
		$malformed_created_uuid = isset( $item['uuid'] ) && is_string( $item['uuid'] ) ? $item['uuid'] : '';
		$malformed_secret       = isset( $item['new_password'] ) && is_string( $item['new_password'] ) ? $item['new_password'] : '';
	};
	$malformed_prepare_filter = static function ( $response, $item, $request ) use ( $target_user, $malformed_name ) {
		if ( $request instanceof WP_REST_Request
			&& 'POST' === $request->get_method()
			&& '/wp/v2/users/' . (int) $target_user . '/application-passwords' === $request->get_route()
			&& $malformed_name === $request->get_param( 'name' )
			&& $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				unset( $data['password'] );
				$response->set_data( $data );
			}
		}
		return $response;
	};
	add_action( 'rest_after_insert_application_password', $malformed_capture_action, 10, 3 );
	add_filter( 'rest_prepare_application_password', $malformed_prepare_filter, 10, 3 );
	$missing_password = wpnb_issue61_execute(
		'wp-native-builder/application-password-create',
		array( 'user_id' => (int) $target_user, 'name' => $malformed_name )
	);
	remove_filter( 'rest_prepare_application_password', $malformed_prepare_filter, 10 );
	remove_action( 'rest_after_insert_application_password', $malformed_capture_action, 10 );
	$malformed_prepare_filter = null;
	$malformed_capture_action = null;
	wpnb_issue61_assert( is_wp_error( $missing_password ) && 'application_passwords_rest_invalid_response' === $missing_password->get_error_code(), 'Filtered create response without plaintext credential did not fail closed.' );
	wpnb_issue61_assert( '' !== $malformed_created_uuid, 'Core create identity was not observed before response filtering.' );
	wpnb_issue61_assert( null === WP_Application_Passwords::get_user_application_password( $target_user, $malformed_created_uuid ), 'Missing-password response left the exact newly created credential behind.' );
	wpnb_issue61_assert( empty( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'Missing-password cleanup changed the expected Application Password state.' );
	$missing_error_blob = wp_json_encode( array( $missing_password->get_error_message(), $missing_password->get_error_data() ) );
	wpnb_issue61_assert( '' === $malformed_secret || false === strpos( $missing_error_blob, $malformed_secret ), 'Missing-password error leaked the generated credential.' );
	$missing_log_blob = wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	wpnb_issue61_assert( '' === $malformed_secret || false === strpos( $missing_log_blob, $malformed_secret ), 'Missing-password cleanup logged the generated credential.' );
	wpnb_issue61_assert( false === strpos( $missing_log_blob, $malformed_created_uuid ), 'Missing-password cleanup logged the created credential UUID.' );

	$preexisting = wpnb_issue61_execute(
		'wp-native-builder/application-password-create',
		array( 'user_id' => (int) $target_user, 'name' => 'Issue 61 pre-existing cleanup target' )
	);
	wpnb_issue61_assert( ! is_wp_error( $preexisting ), 'Could not create the pre-existing Application Password cleanup-safety fixture.' );
	$preexisting_substitution_uuid = $preexisting['item']['uuid'];
	$preexisting_secret            = $preexisting['password'];
	$preexisting_stored            = WP_Application_Passwords::get_user_application_password( $target_user, $preexisting_substitution_uuid );
	wpnb_issue61_assert( is_array( $preexisting_stored ) && isset( $preexisting_stored['password'] ), 'Pre-existing Application Password cleanup-safety fixture is unavailable.' );

	$substitution_created_uuid = '';
	$substitution_secret       = '';
	$substitution_name         = 'Issue 61 substituted response identity';
	$substitution_capture_action = static function ( $item, $request, $creating ) use ( $target_user, $substitution_name, &$substitution_created_uuid, &$substitution_secret ) {
		if ( true !== $creating
			|| ! $request instanceof WP_REST_Request
			|| 'POST' !== $request->get_method()
			|| '/wp/v2/users/' . (int) $target_user . '/application-passwords' !== $request->get_route()
			|| $substitution_name !== $request->get_param( 'name' )
			|| ! is_array( $item ) ) {
			return;
		}
		$substitution_created_uuid = isset( $item['uuid'] ) && is_string( $item['uuid'] ) ? $item['uuid'] : '';
		$substitution_secret       = isset( $item['new_password'] ) && is_string( $item['new_password'] ) ? $item['new_password'] : '';
	};
	$substitution_prepare_filter = static function ( $response, $item, $request ) use ( $target_user, $substitution_name, $preexisting_substitution_uuid ) {
		if ( $request instanceof WP_REST_Request
			&& 'POST' === $request->get_method()
			&& '/wp/v2/users/' . (int) $target_user . '/application-passwords' === $request->get_route()
			&& $substitution_name === $request->get_param( 'name' )
			&& $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				$data['uuid'] = $preexisting_substitution_uuid;
				unset( $data['name'] );
				$response->set_data( $data );
			}
		}
		return $response;
	};
	add_action( 'rest_after_insert_application_password', $substitution_capture_action, 10, 3 );
	add_filter( 'rest_prepare_application_password', $substitution_prepare_filter, 10, 3 );
	$substituted_response = wpnb_issue61_execute(
		'wp-native-builder/application-password-create',
		array( 'user_id' => (int) $target_user, 'name' => $substitution_name )
	);
	remove_filter( 'rest_prepare_application_password', $substitution_prepare_filter, 10 );
	remove_action( 'rest_after_insert_application_password', $substitution_capture_action, 10 );
	$substitution_prepare_filter = null;
	$substitution_capture_action = null;
	wpnb_issue61_assert( is_wp_error( $substituted_response ) && 'application_passwords_rest_invalid_response' === $substituted_response->get_error_code(), 'Substituted create-response UUID did not fail closed.' );
	wpnb_issue61_assert( '' !== $substitution_created_uuid && $preexisting_substitution_uuid !== $substitution_created_uuid, 'Substitution test did not observe a distinct newly created credential.' );
	wpnb_issue61_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $preexisting_substitution_uuid ) ), 'Malformed response cleanup revoked the substituted pre-existing credential.' );
	wpnb_issue61_assert( null === WP_Application_Passwords::get_user_application_password( $target_user, $substitution_created_uuid ), 'Malformed response cleanup left the actual newly created credential behind.' );
	wpnb_issue61_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'Substitution cleanup changed Application Password state beyond the exact created credential.' );
	$substitution_error_blob = wp_json_encode( array( $substituted_response->get_error_message(), $substituted_response->get_error_data() ) );
	wpnb_issue61_assert( '' === $substitution_secret || false === strpos( $substitution_error_blob, $substitution_secret ), 'Substituted-response error leaked the generated credential.' );
	$substitution_log_blob = wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	wpnb_issue61_assert( '' === $substitution_secret || false === strpos( $substitution_log_blob, $substitution_secret ), 'Substituted-response cleanup logged the generated credential.' );
	wpnb_issue61_assert( false === strpos( $substitution_log_blob, $substitution_created_uuid ), 'Substituted-response cleanup logged the created credential UUID.' );
	wpnb_issue61_assert( false === strpos( $substitution_log_blob, $preexisting_stored['password'] ), 'Substituted-response cleanup logged a stored Application Password hash.' );
	wpnb_issue61_assert( false === strpos( $substitution_log_blob, $preexisting_secret ), 'Substituted-response cleanup logged the pre-existing plaintext credential.' );
	WP_Application_Passwords::delete_application_password( $target_user, $preexisting_substitution_uuid );
	$preexisting_substitution_uuid = '';
	wpnb_issue61_assert( empty( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'Cleanup-safety fixture removal did not restore an empty Application Password state.' );

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
	wpnb_issue61_assert( is_wp_error( $unavailable ) && 'application_passwords_rest_request_failed' === $unavailable->get_error_code(), 'Bridge did not normalize Core per-user Application Password unavailability.' );
	remove_filter( 'wp_is_application_passwords_available_for_user', $availability_filter, 10 );
	$availability_filter = null;

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
	if ( is_callable( $f002_capture_action ) ) {
		remove_action( 'wp_create_application_password', $f002_capture_action, 20 );
	}
	if ( $f002_additional_field_active ) {
		global $wp_rest_additional_fields;
		if ( $f002_had_previous_name_field ) {
			$wp_rest_additional_fields['application-password']['name'] = $f002_previous_name_field;
		} else {
			unset( $wp_rest_additional_fields['application-password']['name'] );
			if ( empty( $wp_rest_additional_fields['application-password'] ) ) {
				unset( $wp_rest_additional_fields['application-password'] );
			}
		}
	}
	if ( is_callable( $malformed_prepare_filter ) ) {
		remove_filter( 'rest_prepare_application_password', $malformed_prepare_filter, 10 );
	}
	if ( is_callable( $malformed_capture_action ) ) {
		remove_action( 'rest_after_insert_application_password', $malformed_capture_action, 10 );
	}
	if ( is_callable( $substitution_prepare_filter ) ) {
		remove_filter( 'rest_prepare_application_password', $substitution_prepare_filter, 10 );
	}
	if ( is_callable( $substitution_capture_action ) ) {
		remove_action( 'rest_after_insert_application_password', $substitution_capture_action, 10 );
	}
	remove_filter( 'wp_is_application_passwords_available', '__return_false' );
	if ( $global_availability_enabled ) {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}
	if ( is_callable( $availability_filter ) ) {
		remove_filter( 'wp_is_application_passwords_available_for_user', $availability_filter, 10 );
	}
	if ( is_callable( $f007_shared_error_filter ) ) {
		remove_filter( 'rest_pre_dispatch', $f007_shared_error_filter, 10 );
	}
	wp_set_current_user( $admin_id );
	if ( is_array( $admin_fixture ) && ! empty( $admin_fixture['uuid'] ) ) {
		WP_Application_Passwords::delete_application_password( $admin_id, $admin_fixture['uuid'] );
	}
	if ( $target_user > 0 ) {
		if ( '' !== $preexisting_substitution_uuid ) {
			WP_Application_Passwords::delete_application_password( $target_user, $preexisting_substitution_uuid );
		}
		WP_Application_Passwords::delete_all_application_passwords( $target_user );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $target_user );
	}
	update_option( Settings::OPTION_NAME, $original_access, false );
}
