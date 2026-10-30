<?php
/**
 * Disposable Core configuration/cache/Customizer lifecycle and private exchange.
 *
 * Run once per isolated WordPress PHP request with a native administrator.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Native_Configuration_Abilities;
use WP_AI_Bridge\Abilities\Customizer_Abilities;
use WP_AI_Bridge\Support\Administration_Operation_Journal;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Option_Value;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-native-options-helper.php';

function wpai199c_source( $value ) {
	return array( 'value_json' => wpai199n_ok( Native_Option_Value::encode( $value, 8388608, true ), 'Native configuration fixture could not encode value.' )['json'] );
}
function wpai199c_grants() {
	$settings = new Settings();
	update_option( Settings::OPTION_NAME, $settings->sanitize( array_fill_keys( array_keys( $settings->defaults() ), 1 ) ), false );
	update_option( Settings::ADMINISTRATION_OPTION_NAME, $settings->sanitize_administration_grants( array( Settings::GROUP_NATIVE_CONFIGURATION => 1, Settings::GROUP_CUSTOMIZER_WRITE => 1 ) ), false );
}
function wpai199c_request( $target, $action, $name, $value = null ) {
	$input = array( 'action' => $action, 'target' => $target, 'name' => $name );
	if ( in_array( $action, array( 'set', 'delete' ), true ) ) {
		$input['operation_id'] = 'configuration-native-' . bin2hex( random_bytes( 8 ) );
		if ( 'theme_mod' === $target ) { $input['expected_stylesheet'] = get_stylesheet(); }
		if ( 'set' === $action ) { $input['value_source'] = wpai199c_source( $value ); if ( 'theme_mod' !== $target ) { $input['expiration_seconds'] = 300; } }
		if ( 'delete' === $action ) { $input['confirm'] = true; }
	}
	return $input;
}

wpai199n_assert( ! is_multisite() && current_user_can( 'manage_options' ) && current_user_can( 'edit_theme_options' ), 'Use only a disposable single-site native administrator.' );
$settings = new Settings();
$permissions = new Permissions( $settings );
$native = new Native_Configuration_Abilities( $permissions );
$customizer = new Customizer_Abilities( $permissions );
$original = get_option( Settings::OPTION_NAME, null );
$administration = get_option( Settings::ADMINISTRATION_OPTION_NAME, null );
$activity = get_option( Mutation_Log::OPTION_NAME, null );
$stylesheet = get_stylesheet();
$mods = get_option( 'theme_mods_' . $stylesheet, null );
$prefix = 'configuration199_' . bin2hex( random_bytes( 5 ) );
$transients = array( $prefix . '_native', $prefix . '_effect', $prefix . '_large', $prefix . '_stage' );
$options = array( $prefix . '_appearance', $prefix . '_ambient' );
$refs = array();
$leases = array();
$changesets = array();
$hooks = array();
$old_post = $_POST;
$old_request = $_REQUEST;
$had_customizer = array_key_exists( 'wp_customize', $GLOBALS );
$old_customizer = $GLOBALS['wp_customize'] ?? null;
$call = static function ( array $input ) use ( $native, &$refs, &$leases ) {
	$result = $native->execute( $input );
	if ( is_array( $result ) ) {
		if ( ! empty( $result['operation_ref'] ) ) { $refs[ $result['operation_ref'] ] = true; }
		if ( ! empty( $result['transfer_id'] ) && in_array( $input['action'], array( 'input_begin', 'snapshot' ), true ) ) { $leases[] = array( $input['target'], $result['transfer_id'], 'snapshot' === $input['action'] ); }
	}
	return $result;
};
try {
	wpai199c_grants();
	$value = (object) array( 'enabled' => true, 'rows' => array( 8 => 'eight', '08' => false ), 'private' => 'inert-provider-secret' );
	$request = wpai199c_request( 'transient', 'set', $transients[0], $value );
	$calls = 0;
	$counter = static function ( $value ) use ( &$calls ) { ++$calls; return $value; };
	add_filter( 'pre_set_transient_' . $transients[0], $counter );
	$hooks[] = array( 'pre_set_transient_' . $transients[0], $counter, 10 );
	$result = wpai199n_ok( $call( $request ), 'Native transient set failed.' );
	wpai199n_assert( 'completed' === $result['state'] && 'native_call_returned' === $result['receipt']['result']['outcome'] && true === $result['receipt']['result']['native_return'], 'Native transient outcome was not acknowledged.' );
	wpai199n_assert( get_transient( $transients[0] ) == $value && 1 === $calls, 'Native transient setter changed type/data or count.' );
	$repeat = wpai199n_ok( $call( $request ), 'Native historical replay failed.' );
	wpai199n_assert( true === $repeat['historical'] && 1 === $calls, 'Same identity repeated a native setter.' );
	$stale = wpai199c_request( 'transient', 'set', $transients[0], false );
	$stale['expected_observed_sha256'] = str_repeat( '0', 64 );
	wpai199n_error( $call( $stale ), 'Stale effective-value intent dispatched.' );
	wpai199n_assert( 1 === $calls, 'Stale native intent reached a provider setter.' );
	$read = wpai199n_ok( $call( wpai199c_request( 'transient', 'read', $transients[0] ) ), 'Native stdClass transient read failed.' );
	wpai199n_assert( Native_Option_Value::decode( $read['value_json'], 8388608, 0, true )['value'] == $value, 'Native stdClass did not survive exact transport.' );
	wpai199n_assert( 'unknown' === $read['storage_presence'], 'Native filtered value was advertised as physical existence.' );
	$missing = wpai199n_ok( $call( wpai199c_request( 'transient', 'read', $prefix . '_missing' ) ), 'Missing native transient failed.' );
	wpai199n_assert( false === Native_Option_Value::decode( $missing['value_json'], 4096, 0, true )['value'] && 'unknown' === $missing['storage_presence'], 'Native false ambiguity was erased.' );

	$throwing = static function () { throw new RuntimeException( 'inert-private-provider-error' ); };
	add_action( 'set_transient_' . $transients[1], $throwing );
	try {
		$unknown_request = wpai199c_request( 'transient', 'set', $transients[1], 'written-before-throw' );
		$unknown = wpai199n_ok( $call( $unknown_request ), 'Native after-effect throw lost its receipt.' );
		wpai199n_assert( 'unknown' === $unknown['receipt']['result']['outcome'] && $unknown['receipt']['result']['callback_threw'] && 'written-before-throw' === get_transient( $transients[1] ), 'After-effect throw was mislabeled rolled back.' );
		wpai199n_assert( ! str_contains( json_encode( $unknown ), 'inert-private-provider-error' ), 'Native provider exception leaked.' );
		$call( $unknown_request );
	} finally { remove_action( 'set_transient_' . $transients[1], $throwing ); }

	$theme_request = wpai199c_request( 'theme_mod', 'set', $prefix . '_tint', 'blue' );
	wpai199n_ok( $call( $theme_request ), 'Current-theme mod update failed.' );
	wpai199n_assert( 'blue' === get_theme_mod( $prefix . '_tint' ), 'Native theme modification was not retained.' );
	$wrong_theme = $theme_request; $wrong_theme['operation_id'] .= '-stale'; $wrong_theme['expected_stylesheet'] .= '-other';
	wpai199n_error( $call( $wrong_theme ), 'Different theme identity was accepted.' );
	wpai199n_ok( $call( wpai199c_request( 'theme_mod', 'delete', $prefix . '_tint' ) ), 'Current-theme removal failed.' );
	wpai199n_assert( false === get_theme_mod( $prefix . '_tint' ), 'Native removal was replaced by a null setting.' );
	wpai199n_error( $call( wpai199c_request( 'transient', 'read', 'wpai_oauth_access_fake' ) ), 'Bridge credential transient was read.' );

	$large = str_repeat( 'native-window-', 18000 );
	set_transient( $transients[2], $large, 300 );
	$large_read = wpai199n_ok( $call( wpai199c_request( 'transient', 'read', $transients[2] ) ), 'Large native read failed instead of offering snapshot.' );
	wpai199n_assert( ! $large_read['value_available'] && $large_read['snapshot_required'] && '' === $large_read['value_json'], 'Large native value was truncated or over-returned.' );
	$snapshot = wpai199n_ok( $call( wpai199c_request( 'transient', 'snapshot', $transients[2] ) ), 'Large native private snapshot failed.' );
	$bytes = ''; $offset = 0;
	do {
		$window = wpai199n_ok( $call( array( 'action' => 'snapshot_read', 'target' => 'transient', 'transfer_id' => $snapshot['transfer_id'], 'offset' => $offset ) ), 'Private native snapshot window failed.' );
		$part = base64_decode( $window['base64'], true );
		wpai199n_assert( is_string( $part ) && hash( 'sha256', $part ) === $window['chunk_sha256'], 'Native snapshot chunk digest changed.' );
		$bytes .= $part; $offset = $window['next_offset'];
	} while ( ! $window['complete'] );
	wpai199n_assert( strlen( $bytes ) === $snapshot['total_bytes'] && hash( 'sha256', $bytes ) === $snapshot['sha256'], 'Complete native snapshot integrity changed.' );
	wpai199n_assert( $large === Native_Option_Value::decode( $bytes, 8388608, 0, true )['value'], 'Private native snapshot was not lossless.' );
	$input = wpai199n_ok( $call( array( 'action' => 'input_begin', 'target' => 'transient', 'total_bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ) ) ), 'Private configuration input begin failed.' );
	for ( $offset = 0; $offset < strlen( $bytes ); ) {
		$part = substr( $bytes, $offset, $input['chunk_bytes'] );
		wpai199n_ok( $call( array( 'action' => 'input_append', 'target' => 'transient', 'transfer_id' => $input['transfer_id'], 'offset' => $offset, 'base64' => base64_encode( $part ) ) ), 'Private native input append failed.' );
		$offset += strlen( $part );
	}
	wpai199n_ok( $call( array( 'action' => 'input_verify', 'target' => 'transient', 'transfer_id' => $input['transfer_id'] ) ), 'Private native input verification failed.' );
	$staged = wpai199c_request( 'transient', 'set', $transients[3], null );
	$staged['value_source'] = array( 'transfer_id' => $input['transfer_id'], 'sha256' => $input['sha256'], 'total_bytes' => $input['total_bytes'] );
	wpai199n_ok( $call( $staged ), 'Verified staged value did not reach native destination.' );
	wpai199n_assert( $large === get_transient( $transients[3] ), 'Private value consumer changed native bytes.' );
	$wrong_purpose = $staged; $wrong_purpose['operation_id'] .= '-purpose'; $wrong_purpose['value_source']['transfer_id'] = $snapshot['transfer_id'];
	wpai199n_error( $call( $wrong_purpose ), 'A snapshot was adopted as a writable input.' );

	// One fresh owned native changeset, including provider sanitization and
	// synchronous Core before/after publication hooks. No ambient POST is adopted.
	$seen_core = false; $validated = 0; $sanitized = 0; $before = 0; $after = 0;
	$setting_name = $options[0] . '[tint]';
	$registration = static function ( $manager ) use ( &$seen_core, &$validated, &$sanitized, $setting_name, $options ) {
		$seen_core = null !== $manager->get_setting( 'blogname' );
		$manager->add_setting( $setting_name, array( 'type' => 'option', 'capability' => 'edit_theme_options', 'validate_callback' => static function ( $validity, $value ) use ( &$validated ) { ++$validated; if ( ! is_string( $value ) ) { $validity->add( 'invalid', 'private-value-rejected' ); } return $validity; }, 'sanitize_callback' => static function ( $value ) use ( &$sanitized ) { ++$sanitized; return strtoupper( $value ); } ) );
		$manager->add_setting( $options[1], array( 'type' => 'option', 'capability' => 'edit_theme_options' ) );
	};
	$before_callback = static function () use ( &$before ) { ++$before; };
	$after_callback = static function () use ( &$after ) { ++$after; };
	add_action( 'customize_register', $registration, 10 );
	add_action( 'customize_save', $before_callback ); add_action( 'customize_save_after', $after_callback );
	$hooks[] = array( 'customize_register', $registration, 10 ); $hooks[] = array( 'customize_save', $before_callback, 10 ); $hooks[] = array( 'customize_save_after', $after_callback, 10 );
	$_POST['customized'] = json_encode( array( $options[1] => 'must-not-be-published' ) );
	$_REQUEST['customize_theme'] = 'must-not-preview';
	$ambient_post = $_POST; $ambient_request = $_REQUEST;
	$custom_request = array( 'action' => 'update', 'name' => $setting_name, 'expected_stylesheet' => $stylesheet, 'operation_id' => 'customizer-native-' . bin2hex( random_bytes( 8 ) ), 'value_source' => wpai199c_source( 'purple' ) );
	$published = wpai199n_ok( $customizer->execute( $custom_request ), 'Registered native Customizer publication failed.' );
	$refs[ $published['operation_ref'] ] = true;
	if ( ! empty( $published['receipt']['result']['changeset_id'] ) ) { $changesets[] = $published['receipt']['result']['changeset_id']; }
	wpai199n_assert( $seen_core && $validated > 0 && $sanitized > 0 && 1 === $before && 1 === $after, 'Late Core-first registration, native validation or full publication hooks were bypassed.' );
	wpai199n_assert( array( 'tint' => 'PURPLE' ) === get_option( $options[0] ) && false === get_option( $options[1] ), 'Customizer overwrote unrelated fields or adopted ambient values.' );
	wpai199n_assert( $_POST === $ambient_post && $_REQUEST === $ambient_request && ( $GLOBALS['wp_customize'] ?? null ) === $old_customizer, 'Customizer did not restore ambient request/global state.' );
	$replayed = wpai199n_ok( $customizer->execute( $custom_request ), 'Owned Customizer historical replay failed.' );
	wpai199n_assert( $replayed['historical'] && 1 === $before && 1 === $after, 'Customizer recovery recreated/published another changeset.' );

	echo 'PASS: native configuration/Customizer lifecycle (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
} finally {
	foreach ( $hooks as $hook ) { remove_filter( $hook[0], $hook[1], $hook[2] ); }
	$_POST = $old_post; $_REQUEST = $old_request;
	if ( $had_customizer ) { $GLOBALS['wp_customize'] = $old_customizer; } else { unset( $GLOBALS['wp_customize'] ); }
	wpai199c_grants();
	foreach ( $leases as $lease ) { $native->execute( array( 'action' => $lease[2] ? 'snapshot_release' : 'input_release', 'target' => $lease[0], 'transfer_id' => $lease[1] ) ); }
	foreach ( $transients as $name ) { delete_transient( $name ); }
	foreach ( $options as $name ) { delete_option( $name ); }
	foreach ( $changesets as $id ) { wp_delete_post( $id, true ); }
	wpai199n_restore_option( 'theme_mods_' . $stylesheet, $mods, null !== $mods );
	global $wpdb;
	foreach ( array_keys( $refs ) as $ref ) {
		if ( preg_match( '/^[a-f0-9]{64}$/D', $ref ) ) { $wpdb->delete( $wpdb->options, array( 'option_name' => Administration_Operation_Journal::PREFIX . $ref ), array( '%s' ) ); }
	}
	wpai199n_restore_option( Settings::OPTION_NAME, $original, null !== $original );
	wpai199n_restore_option( Settings::ADMINISTRATION_OPTION_NAME, $administration, null !== $administration );
	wpai199n_restore_option( Mutation_Log::OPTION_NAME, $activity, null !== $activity );
}
