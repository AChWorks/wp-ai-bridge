<?php
/**
 * Real WordPress adversarial create-provenance coverage for Issue #61 / F-003.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Settings;

function wpai_issue61_f003_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue61_f003_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue61_f003_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpai_issue61_f003_error_blob( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return '';
	}
	return wp_json_encode( array( $error->get_error_code(), $error->get_error_message(), $error->get_error_data() ) );
}

function wpai_issue61_f003_assert_private( $blob, array $needles, $context ) {
	foreach ( array_filter( $needles, 'is_string' ) as $needle ) {
		if ( '' !== $needle ) {
			wpai_issue61_f003_assert( false === strpos( $blob, $needle ), $context . ' leaked sensitive create provenance.' );
		}
	}
}

$settings                 = new Settings();
$original_access          = get_option( Settings::OPTION_NAME, $settings->defaults() );
$admin_id                 = get_current_user_id();
$target_user              = 0;
$unauthorized_user        = 0;
$preexisting_uuid         = '';
$preexisting_secret       = '';
$preexisting_hash         = '';
$all_callback             = null;
$same_priority_callback   = null;
$global_availability      = false;
$all_nested_uuid          = '';
$same_nested_uuid         = '';

try {
	$enabled                                   = $settings->defaults();
	$enabled[ Settings::GROUP_AUTHENTICATION ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	add_filter( 'wp_is_application_passwords_available', '__return_true' );
	$global_availability = true;

	$target_user = wp_insert_user(
		array(
			'user_login' => 'issue61-f003-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'issue61-f003-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpai_issue61_f003_assert( ! is_wp_error( $target_user ) && $target_user > 0, 'Could not create F-003 user fixture.' );

	$unauthorized_user = wp_insert_user(
		array(
			'user_login' => 'issue61-f003-denied-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'issue61-f003-denied-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpai_issue61_f003_assert( ! is_wp_error( $unauthorized_user ) && $unauthorized_user > 0, 'Could not create F-003 unauthorized actor fixture.' );

	$legacy_raw = array(
		array(
			'app_id'    => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
			'name'      => 'Issue 61 legacy no UUID',
			'password'  => WP_Application_Passwords::hash_password( 'issue61-legacy-secret' ),
			'created'   => time(),
			'last_used' => null,
			'last_ip'   => null,
		),
	);
	update_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $legacy_raw );
	$legacy_before = get_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, true );
	wpai_issue61_f003_assert( $legacy_raw === $legacy_before && ! isset( $legacy_before[0]['uuid'] ), 'Could not establish legacy no-UUID Application Password fixture.' );

	wp_set_current_user( $unauthorized_user );
	$denied = wpai_issue61_f003_execute(
		'wp-ai-bridge/application-password-create',
		array(
			'user_id' => (int) $target_user,
			'name'    => 'Denied before storage inspection',
		)
	);
	$legacy_after = get_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, true );
	wpai_issue61_f003_assert( is_wp_error( $denied ) && 'rest_cannot_create_application_passwords' === $denied->get_error_code(), 'Unauthorized create did not preserve Core create_app_password denial.' );
	wpai_issue61_f003_assert( $legacy_before === $legacy_after && ! isset( $legacy_after[0]['uuid'] ), 'Unauthorized create inspected/mutated legacy Application Password storage before Core authorization.' );

	wp_set_current_user( $admin_id );
	delete_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS );
	wpai_issue61_f003_assert( '' === get_user_meta( $target_user, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, true ), 'Legacy authorization fixture cleanup failed.' );

	$preexisting = wpai_issue61_f003_execute(
		'wp-ai-bridge/application-password-create',
		array(
			'user_id' => (int) $target_user,
			'name'    => 'Issue 61 F003 pre-existing',
		)
	);
	wpai_issue61_f003_assert( ! is_wp_error( $preexisting ), 'Could not create F-003 pre-existing credential fixture.' );
	$preexisting_uuid   = $preexisting['item']['uuid'];
	$preexisting_secret = $preexisting['password'];
	$preexisting_item   = WP_Application_Passwords::get_user_application_password( $target_user, $preexisting_uuid );
	wpai_issue61_f003_assert( is_array( $preexisting_item ) && isset( $preexisting_item['password'] ), 'F-003 pre-existing credential is unavailable.' );
	$preexisting_hash = $preexisting_item['password'];

	/*
	 * Global `all` executes before hook-specific callbacks. The callback records the
	 * genuine outer item, creates a nested credential, then throws before the outer
	 * `wp_create_application_password` action can reach any later observer.
	 */
	$all_outer_uuid   = '';
	$all_outer_secret = '';
	$all_nested_secret = '';
	$all_guard        = false;
	$all_name         = 'Issue 61 F003 all outer';
	$all_callback     = static function ( $hook_name, ...$args ) use ( $target_user, $all_name, &$all_guard, &$all_outer_uuid, &$all_outer_secret, &$all_nested_uuid, &$all_nested_secret ) {
		if ( 'wp_create_application_password' !== $hook_name || $all_guard || count( $args ) < 4 ) {
			return;
		}
		$observed_user_id = $args[0];
		$item             = $args[1];
		$new_password     = $args[2];
		$create_args      = $args[3];
		if ( (int) $observed_user_id !== (int) $target_user
			|| ! is_array( $item )
			|| ! is_array( $create_args )
			|| $all_name !== ( $create_args['name'] ?? '' ) ) {
			return;
		}
		$all_outer_uuid   = isset( $item['uuid'] ) && is_string( $item['uuid'] ) ? $item['uuid'] : '';
		$all_outer_secret = is_string( $new_password ) ? $new_password : '';
		wpai_issue61_f003_assert( ! array_key_exists( '__wp_ai_bridge_create_correlation', $create_args ), 'F-003 public Core action exposed the retired Bridge correlation token.' );

		$all_guard = true;
		try {
			$nested = WP_Application_Passwords::create_new_application_password(
				$target_user,
				array( 'name' => 'Issue 61 F003 all nested' )
			);
			wpai_issue61_f003_assert( ! is_wp_error( $nested ), 'F-003 all-hook nested credential creation failed.' );
			$all_nested_secret = $nested[0];
			$all_nested_uuid   = $nested[1]['uuid'];
		} finally {
			$all_guard = false;
		}
		throw new RuntimeException( 'F003 all-hook preemption unsafe details' );
	};
	add_action( 'all', $all_callback, PHP_INT_MIN, 99 );
	$all_result = wpai_issue61_f003_execute(
		'wp-ai-bridge/application-password-create',
		array(
			'user_id' => (int) $target_user,
			'name'    => $all_name,
			'app_id'  => '33333333-4444-4555-8666-777777777777',
		)
	);
	remove_action( 'all', $all_callback, PHP_INT_MIN );
	$all_callback = null;

	wpai_issue61_f003_assert( is_wp_error( $all_result ) && 'application_password_create_recovery_required' === $all_result->get_error_code(), 'F-003 all-hook preemption did not require explicit recovery.' );
	wpai_issue61_f003_assert( '' !== $all_outer_uuid && '' !== $all_nested_uuid && $all_outer_uuid !== $all_nested_uuid, 'F-003 all-hook fixture did not create distinct outer and nested credentials.' );
	wpai_issue61_f003_assert( null === WP_Application_Passwords::get_user_application_password( $target_user, $all_outer_uuid ), 'F-003 all-hook preemption left the genuine requested credential behind.' );
	wpai_issue61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $all_nested_uuid ) ), 'F-003 all-hook nested credential incorrectly became outer cleanup authority.' );
	wpai_issue61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $preexisting_uuid ) ), 'F-003 all-hook cleanup revoked the pre-existing credential.' );
	wpai_issue61_f003_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-003 all-hook recovery changed credential state beyond exact outer cleanup.' );
	$all_error_blob = wpai_issue61_f003_error_blob( $all_result );
	wpai_issue61_f003_assert( false === strpos( $all_error_blob, 'unsafe details' ), 'F-003 all-hook throwable details escaped the bounded error.' );
	$all_log_blob = wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	wpai_issue61_f003_assert_private( $all_error_blob . $all_log_blob, array( $all_outer_secret, $all_nested_secret, $all_outer_uuid, $all_nested_uuid, $preexisting_secret, $preexisting_hash, '33333333-4444-4555-8666-777777777777', '__wp_ai_bridge_create_correlation' ), 'F-003 all-hook path' );
	WP_Application_Passwords::delete_application_password( $target_user, $all_nested_uuid );
	$all_nested_uuid = '';
	wpai_issue61_f003_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-003 all-hook fixture cleanup did not restore the pre-existing-only baseline.' );

	/*
	 * A pre-existing callback at the same extreme priority is registered before the
	 * Bridge invocation. It nests a create and throws before later callbacks run.
	 */
	$same_outer_uuid    = '';
	$same_outer_secret  = '';
	$same_nested_secret = '';
	$same_guard         = false;
	$same_name          = 'Issue 61 F003 same priority outer';
	$same_priority_callback = static function ( $observed_user_id, $item, $new_password, $create_args ) use ( $target_user, $same_name, &$same_guard, &$same_outer_uuid, &$same_outer_secret, &$same_nested_uuid, &$same_nested_secret ) {
		if ( $same_guard
			|| (int) $observed_user_id !== (int) $target_user
			|| ! is_array( $item )
			|| ! is_array( $create_args )
			|| $same_name !== ( $create_args['name'] ?? '' ) ) {
			return;
		}
		$same_outer_uuid   = isset( $item['uuid'] ) && is_string( $item['uuid'] ) ? $item['uuid'] : '';
		$same_outer_secret = is_string( $new_password ) ? $new_password : '';
		wpai_issue61_f003_assert( ! array_key_exists( '__wp_ai_bridge_create_correlation', $create_args ), 'F-003 same-priority Core action exposed the retired Bridge correlation token.' );

		$same_guard = true;
		try {
			$nested = WP_Application_Passwords::create_new_application_password(
				$target_user,
				array( 'name' => 'Issue 61 F003 same priority nested' )
			);
			wpai_issue61_f003_assert( ! is_wp_error( $nested ), 'F-003 same-priority nested credential creation failed.' );
			$same_nested_secret = $nested[0];
			$same_nested_uuid   = $nested[1]['uuid'];
		} finally {
			$same_guard = false;
		}
		throw new RuntimeException( 'F003 same-priority preemption unsafe details' );
	};
	add_action( 'wp_create_application_password', $same_priority_callback, PHP_INT_MIN, 4 );
	$same_result = wpai_issue61_f003_execute(
		'wp-ai-bridge/application-password-create',
		array(
			'user_id' => (int) $target_user,
			'name'    => $same_name,
			'app_id'  => '44444444-5555-4666-8777-888888888888',
		)
	);
	remove_action( 'wp_create_application_password', $same_priority_callback, PHP_INT_MIN );
	$same_priority_callback = null;

	wpai_issue61_f003_assert( is_wp_error( $same_result ) && 'application_password_create_recovery_required' === $same_result->get_error_code(), 'F-003 same-priority preemption did not require explicit recovery.' );
	wpai_issue61_f003_assert( '' !== $same_outer_uuid && '' !== $same_nested_uuid && $same_outer_uuid !== $same_nested_uuid, 'F-003 same-priority fixture did not create distinct outer and nested credentials.' );
	wpai_issue61_f003_assert( null === WP_Application_Passwords::get_user_application_password( $target_user, $same_outer_uuid ), 'F-003 same-priority preemption left the genuine requested credential behind.' );
	wpai_issue61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $same_nested_uuid ) ), 'F-003 same-priority nested credential incorrectly became outer cleanup authority.' );
	wpai_issue61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( $target_user, $preexisting_uuid ) ), 'F-003 same-priority cleanup revoked the pre-existing credential.' );
	wpai_issue61_f003_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-003 same-priority recovery changed state beyond exact outer cleanup.' );
	$same_error_blob = wpai_issue61_f003_error_blob( $same_result );
	wpai_issue61_f003_assert( false === strpos( $same_error_blob, 'unsafe details' ), 'F-003 same-priority throwable details escaped the bounded error.' );
	$same_log_blob = wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
	wpai_issue61_f003_assert_private( $same_error_blob . $same_log_blob, array( $same_outer_secret, $same_nested_secret, $same_outer_uuid, $same_nested_uuid, $preexisting_secret, $preexisting_hash, '44444444-5555-4666-8777-888888888888', '__wp_ai_bridge_create_correlation' ), 'F-003 same-priority path' );
	WP_Application_Passwords::delete_application_password( $target_user, $same_nested_uuid );
	$same_nested_uuid = '';

	WP_Application_Passwords::delete_application_password( $target_user, $preexisting_uuid );
	$preexisting_uuid = '';
	wpai_issue61_f003_assert( empty( WP_Application_Passwords::get_user_application_passwords( $target_user ) ), 'F-003 fixture cleanup did not restore empty Application Password state.' );

	echo "PASS: Issue #61 F-003 replay/preemption provenance hardening.\n";
} finally {
	if ( is_callable( $all_callback ) ) {
		remove_action( 'all', $all_callback, PHP_INT_MIN );
	}
	if ( is_callable( $same_priority_callback ) ) {
		remove_action( 'wp_create_application_password', $same_priority_callback, PHP_INT_MIN );
	}
	if ( $global_availability ) {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}
	wp_set_current_user( $admin_id );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( $target_user > 0 ) {
		if ( '' !== $all_nested_uuid ) {
			WP_Application_Passwords::delete_application_password( $target_user, $all_nested_uuid );
		}
		if ( '' !== $same_nested_uuid ) {
			WP_Application_Passwords::delete_application_password( $target_user, $same_nested_uuid );
		}
		WP_Application_Passwords::delete_all_application_passwords( $target_user );
		wp_delete_user( $target_user );
	}
	if ( $unauthorized_user > 0 ) {
		wp_delete_user( $unauthorized_user );
	}
	update_option( Settings::OPTION_NAME, $original_access, false );
}
