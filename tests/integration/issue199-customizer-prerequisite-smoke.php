<?php
/**
 * Pinned Astra late-bootstrap prerequisite and public native configuration proof.
 *
 * Run in a fresh disposable WordPress request after activating Astra 4.13.11.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Customizer_Abilities;
use WP_AI_Bridge\Abilities\Native_Configuration_Abilities;
use WP_AI_Bridge\Support\Administration_Operation_Journal;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Option_Value;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-native-options-helper.php';

function wpai199cl_options() {
	global $wpdb;
	// The fixed audit log records failed attempts; every other option must match.
	return $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name <> %s ORDER BY BINARY option_name", Mutation_Log::OPTION_NAME ), ARRAY_A );
}
function wpai199cl_source( $value ) {
	return array( 'value_json' => wpai199n_ok( Native_Option_Value::encode( $value, 8388608, true ), 'Astra native fixture value encoding failed.' )['json'] );
}

wpai199n_assert( ! is_multisite() && current_user_can( 'manage_options' ) && current_user_can( 'edit_theme_options' ), 'Use only a disposable single-site native administrator.' );
wpai199n_assert( 'astra' === get_stylesheet() && '4.13.11' === wp_get_theme()->get( 'Version' ), 'The late-bootstrap prerequisite requires exact active Astra 4.13.11.' );
$settings = new Settings();
$permissions = new Permissions( $settings );
$native = new Native_Configuration_Abilities( $permissions );
$customizer = new Customizer_Abilities( $permissions );
$original = get_option( Settings::OPTION_NAME, null );
$administration = get_option( Settings::ADMINISTRATION_OPTION_NAME, null );
$activity = get_option( Mutation_Log::OPTION_NAME, null );
$mods = get_option( 'theme_mods_astra', null );
$prefix = 'astra199_' . bin2hex( random_bytes( 6 ) );
$refs = array();
$old_post = $_POST;
$old_request = $_REQUEST;
$had_customizer = array_key_exists( 'wp_customize', $GLOBALS );
$old_customizer = $GLOBALS['wp_customize'] ?? null;
$published = 0;
$on_publish = static function () use ( &$published ) { ++$published; };
try {
	update_option( Settings::OPTION_NAME, $settings->sanitize( array_fill_keys( array_keys( $settings->defaults() ), 1 ) ), false );
	update_option( Settings::ADMINISTRATION_OPTION_NAME, $settings->sanitize_administration_grants( array( Settings::GROUP_NATIVE_CONFIGURATION => 1, Settings::GROUP_CUSTOMIZER_WRITE => 1 ) ), false );
	// Astra still uses the public Theme Modification and Transients contracts.
	foreach ( array( 'theme_mod', 'transient' ) as $target ) {
		$base = array( 'target' => $target, 'name' => $prefix . '_' . $target );
		if ( 'theme_mod' === $target ) { $base['expected_stylesheet'] = 'astra'; }
		$request = array_merge( $base, array( 'action' => 'set', 'operation_id' => $prefix . '-set-' . $target, 'value_source' => wpai199cl_source( 'public-native-value' ) ) );
		if ( 'transient' === $target ) { $request['expiration_seconds'] = 300; }
		$result = wpai199n_ok( $native->execute( $request ), 'Astra public native configuration setter failed.' );
		$refs[ $result['operation_ref'] ] = true;
		$read = wpai199n_ok( $native->execute( array_merge( $base, array( 'action' => 'read' ) ) ), 'Astra public native configuration read failed.' );
		wpai199n_assert( 'public-native-value' === Native_Option_Value::decode( $read['value_json'], 8388608, 0, true )['value'], 'Astra public native configuration changed its value.' );
		$result = wpai199n_ok( $native->execute( array_merge( $base, array( 'action' => 'delete', 'operation_id' => $prefix . '-delete-' . $target, 'confirm' => true ) ) ), 'Astra public native configuration removal failed.' );
		$refs[ $result['operation_ref'] ] = true;
		wpai199n_assert( false === ( 'theme_mod' === $target ? get_theme_mod( $base['name'] ) : get_transient( $base['name'] ) ), 'Astra public native configuration removal did not reach native storage.' );
	}

	$_POST['customized'] = '{"must_not_publish":"ambient-secret"}';
	$_REQUEST['customize_theme'] = 'must-not-preview';
	$_REQUEST['customize_changeset_uuid'] = 'must-not-adopt';
	$ambient_post = $_POST; $ambient_request = $_REQUEST;
	$ambient_stack = $GLOBALS['wp_current_filter'] ?? array();
	$before = wpai199cl_options();
	global $wpdb;
	$changesets = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'customize_changeset' ORDER BY ID" );
	$journal_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( Administration_Operation_Journal::PREFIX ) . '%' ) );
	add_action( 'customize_save', $on_publish );
	$operation = $prefix . '-late-customizer';
	$result = wpai199n_error( $customizer->execute( array( 'action' => 'update', 'expected_stylesheet' => 'astra', 'name' => $prefix . '_setting', 'operation_id' => $operation, 'value_source' => wpai199cl_source( 'must-not-publish' ) ) ), 'Astra early-only registration dependency was not a typed prerequisite.', array( 'customizer_late_bootstrap_unavailable' ) );
	wpai199n_assert( false === $result->get_error_data()['dispatch_attempted'] && '' === $result->get_error_data()['operation_ref'], 'Unavailable Astra bootstrap reported a native dispatch.' );
	wpai199n_assert( 0 === $published && $changesets === $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'customize_changeset' ORDER BY ID" ), 'Unavailable Astra bootstrap created or published a changeset.' );
	wpai199n_assert( $journal_count === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( Administration_Operation_Journal::PREFIX ) . '%' ) ), 'Unavailable Astra bootstrap reserved or armed a journal.' );
	wpai199n_assert( $before === wpai199cl_options(), 'Unavailable Astra bootstrap changed options, theme mods, grants or journals.' );
	wpai199n_assert( $_POST === $ambient_post && $_REQUEST === $ambient_request && array_key_exists( 'wp_customize', $GLOBALS ) === $had_customizer && ( $GLOBALS['wp_customize'] ?? null ) === $old_customizer, 'Unavailable Astra bootstrap failed to restore exact ambient state.' );
	wpai199n_assert( $ambient_stack === ( $GLOBALS['wp_current_filter'] ?? array() ), 'Unavailable Astra bootstrap left an escaped WordPress hook frame.' );
	wpai199n_assert( ! preg_match( '~Astra_Customizer|class-astra|/var/www|\\.php|ambient-secret~', json_encode( array( $result->get_error_message(), $result->get_error_data() ) ) ), 'Astra prerequisite diagnostic leaked provider paths or ambient values.' );
	$status = wpai199n_ok( $customizer->execute( array( 'action' => 'status', 'expected_stylesheet' => 'astra', 'operation_id' => $operation ) ), 'Astra failed-bootstrap status recovery failed.' );
	wpai199n_assert( 'not_recorded' === $status['state'], 'Astra prerequisite failure left an operation receipt.' );
	echo 'PASS: Astra public native configuration and late-bootstrap prerequisite (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
} finally {
	remove_action( 'customize_save', $on_publish );
	$_POST = $old_post; $_REQUEST = $old_request;
	if ( $had_customizer ) { $GLOBALS['wp_customize'] = $old_customizer; } else { unset( $GLOBALS['wp_customize'] ); }
	delete_transient( $prefix . '_transient' );
	wpai199n_restore_option( 'theme_mods_astra', $mods, null !== $mods );
	global $wpdb;
	foreach ( array_keys( $refs ) as $ref ) {
		if ( preg_match( '/^[a-f0-9]{64}$/D', $ref ) ) { $wpdb->delete( $wpdb->options, array( 'option_name' => Administration_Operation_Journal::PREFIX . $ref ), array( '%s' ) ); }
	}
	wpai199n_restore_option( Settings::OPTION_NAME, $original, null !== $original );
	wpai199n_restore_option( Settings::ADMINISTRATION_OPTION_NAME, $administration, null !== $administration );
	wpai199n_restore_option( Mutation_Log::OPTION_NAME, $activity, null !== $activity );
}
