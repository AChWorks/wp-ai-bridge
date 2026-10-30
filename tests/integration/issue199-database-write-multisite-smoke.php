<?php
/**
 * Actual multisite authority, independent per-blog grants and control guards.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Database_Write_Abilities;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-database-write-helper.php';

function wpai199wms_denied_save( $callback ) {
	$denied = false;
	$handler = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			throw new RuntimeException( 'wpai199wms-denied-' . ( is_array( $args ) ? ( $args['response'] ?? 500 ) : 500 ) );
		};
	};
	add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		if ( 'wpai199wms-denied-403' !== $error->getMessage() ) {
			throw $error;
		}
		$denied = true;
	} finally {
		remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	}
	wpai199w_assert( $denied, 'Unauthorized database delegation save did not stop before persistence.' );
}

global $wpdb;
$actor = get_current_user_id();
$main = get_current_blog_id();
$main_database = get_option( Settings::DATABASE_OPTION_NAME, false );
$main_ordinary = get_option( Settings::OPTION_NAME, false );
$secondary = 0;
$local = 0;
$switched = false;
$table = '';
$created = false;
$deny_network = null;
$receipts = array();
$network_control = 'wp_ai_bridge_199w_network_' . bin2hex( random_bytes( 5 ) );
$network_ordinary = 'plugin199w_network_' . bin2hex( random_bytes( 5 ) );

try {
	wpai199w_assert( is_multisite() && is_super_admin() && current_user_can( 'manage_network_options' ), 'Multisite typed-write fixture requires actual synthetic Super Admin.' );
	$settings = new Settings();
	$settings->register();
	$provider = new Database_Write_Abilities( new Permissions( $settings ) );
	$grants = wpai199w_grants();
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199w_assert( $provider->can_write(), 'Actual Super Admin cannot use explicit main-site write delegation.' );
	$network = get_network();
	$secondary = wpmu_create_blog( $network->domain, trailingslashit( $network->path ) . 'wpai199w-' . bin2hex( random_bytes( 5 ) ) . '/', 'Typed database administration fixture', $actor, array(), $network->id );
	wpai199w_assert( is_int( $secondary ) && $secondary > 0, 'Cannot create isolated secondary site.' );
	switch_to_blog( $secondary );
	$switched = true;
	$receipts = wpai199w_receipts_before();
	update_option( Settings::DATABASE_OPTION_NAME, $settings->database_defaults(), false );
	wpai199w_assert( ! $provider->can_write(), 'Main-site write grants leaked to a secondary blog.' );
	$table = $wpdb->prefix . 'wpai199w_ms_' . bin2hex( random_bytes( 4 ) );
	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, payload LONGBLOB NULL) ENGINE=InnoDB', $table ) ), 'Cannot create secondary synthetic target.' );
	$created = true;
	wpai199w_error( wpai199w_call( array( 'action' => 'describe', 'table' => $table ) ), 'Secondary blog inherited main delegation through execution.' );
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	$schema = wpai199w_describe( $table )['schema_hash'];
	$request = array( 'action' => 'execute', 'operation_id' => 'write-ms-' . bin2hex( random_bytes( 5 ) ), 'operations' => array( wpai199w_step( 'insert', $table, $schema, array( 'id' => base64_encode( '1' ), 'payload' => base64_encode( "سلام\0network" ) ) ) ) );
	wpai199w_ok( wpai199w_call( $request ), 'Actual Super Admin secondary typed insert failed.' );
	wpai199w_assert( "سلام\0network" === wpai199w_bytes( $table, 'payload', '1' ), 'Secondary native write changed exact bytes.' );

	$local = wp_insert_user( array( 'user_login' => 'wpai199w-local-' . bin2hex( random_bytes( 5 ) ), 'user_pass' => wp_generate_password( 32 ), 'user_email' => bin2hex( random_bytes( 5 ) ) . '@example.invalid' ) );
	wpai199w_assert( is_int( $local ) && $local > 0 && ! is_wp_error( add_user_to_blog( $secondary, $local, 'administrator' ) ), 'Cannot create actual site administrator.' );
	wp_set_current_user( $local );
	wpai199w_assert( current_user_can( 'manage_options' ) && ! is_super_admin(), 'Local role fixture has wrong authority.' );
	foreach ( array( Settings::GROUP_DATABASE_WRITE, Settings::GROUP_DATABASE_DELETE ) as $group ) {
		wpai199w_assert( ! $settings->can_configure_group( $group ), 'Site administrator can configure a protected write group.' );
	}
	wpai199w_assert( ! $provider->can_write( $request ), 'Actual site administrator inherited powerful installation database execution.' );
	wpai199w_error( wpai199w_call( $request ), 'Site administrator replayed a Super Admin receipt.' );
	wpai199wms_denied_save( static function () use ( $settings ) { update_option( Settings::DATABASE_OPTION_NAME, $settings->database_defaults(), false ); } );
	wpai199w_assert( $grants === get_option( Settings::DATABASE_OPTION_NAME ), 'Unauthorized configuration changed protected grants.' );
	$ordinary = array( Settings::GROUP_DATABASE_WRITE => 1, Settings::GROUP_DATABASE_DELETE => 1, Settings::GROUP_SITE_READ => 1 );
	update_option( Settings::OPTION_NAME, $ordinary, false );
	wpai199w_assert( ! isset( get_option( Settings::OPTION_NAME )[ Settings::GROUP_DATABASE_WRITE ], get_option( Settings::OPTION_NAME )[ Settings::GROUP_DATABASE_DELETE ] ), 'Ordinary native settings persisted protected write bits.' );
	wp_set_current_user( $actor );
	$deny_network = static function ( $caps, $cap ) { return 'manage_network_options' === $cap ? array( 'do_not_allow' ) : $caps; };
	add_filter( 'map_meta_cap', $deny_network, 999, 2 );
	wpai199w_error( wpai199w_call( $request ), 'Super Admin bypassed an explicit native network denial.' );
	wpai199w_assert( ! $settings->can_configure_group( Settings::GROUP_DATABASE_WRITE ), 'Explicit network denial did not govern write delegation configuration.' );
	remove_filter( 'map_meta_cap', $deny_network, 999 );
	$deny_network = null;
	$grants[ Settings::GROUP_DATABASE_WRITE ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199w_error( wpai199w_call( $request ), 'Same Super Admin request bypassed current per-blog revocation.' );
	wpai199w_assert( 1 === wpai199w_count( $table, 1 ), 'Revoked replay repeated or deleted the committed row.' );
	update_option( Settings::DATABASE_OPTION_NAME, wpai199w_grants(), false );

	// A main-blog invocation must still guard secondary-blog Bridge controls.
	$secondary_options = $wpdb->options;
	$secondary_key = array( 'option_id' => base64_encode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name=%s', $secondary_options, Settings::DATABASE_OPTION_NAME ) ) ) );
	$secondary_schema = wpai199w_describe( $secondary_options )['schema_hash'];
	$secondary_state = wpai199w_state( $secondary_options, $secondary_key )['state_hash'];
	restore_current_blog();
	$switched = false;
	$protected = array( 'action' => 'update', 'table' => $secondary_options, 'expected_schema_hash' => $secondary_schema, 'key' => $secondary_key, 'expected_state_hash' => $secondary_state, 'values' => array( 'option_name' => base64_encode( 'renamed_secondary_control' ) ) );
	wpai199w_error( wpai199w_execute( 'write-cross-site-control-' . bin2hex( random_bytes( 5 ) ), array( $protected ) ), 'Installation-wide protected control guard missed a secondary-blog option row.' );
	wpai199w_assert( wpai199w_grants() === get_blog_option( $secondary, Settings::DATABASE_OPTION_NAME ), 'Cross-site control denial changed secondary grants.' );
	add_site_option( $network_control, 'network protected state' );
	add_site_option( $network_ordinary, 'ordinary network state' );
	$network_schema = wpai199w_describe( $wpdb->sitemeta )['schema_hash'];
	foreach ( array( array( $network_control, $network_ordinary . '_renamed' ), array( $network_ordinary, strtoupper( $network_control ) . '_proposed' ) ) as $rename ) {
		$meta_id = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE meta_key=%s AND site_id=%d', $wpdb->sitemeta, $rename[0], get_current_network_id() ) );
		$key = array( 'meta_id' => base64_encode( (string) $meta_id ) );
		$step = array( 'action' => 'update', 'table' => $wpdb->sitemeta, 'expected_schema_hash' => $network_schema, 'key' => $key, 'expected_state_hash' => wpai199w_state( $wpdb->sitemeta, $key )['state_hash'], 'values' => array( 'meta_key' => base64_encode( $rename[1] ) ) );
		wpai199w_error( wpai199w_execute( 'write-network-control-' . bin2hex( random_bytes( 4 ) ), array( $step ) ), 'Native network metadata old/proposed protected identity was writable.', array( 'database_write_control_state' ) );
	}
	echo "PASS: Issue #199 actual multisite Super Admin/site-admin/network denial, per-blog revocation and cross-blog control protection.\n";
} finally {
	wp_set_current_user( $actor );
	if ( null !== $deny_network ) {
		remove_filter( 'map_meta_cap', $deny_network, 999 );
	}
	if ( ! $switched && $secondary > 0 ) {
		switch_to_blog( $secondary );
		$switched = true;
	}
	if ( $switched ) {
		if ( $created ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
		}
		wpai199w_restore_receipts( $receipts );
		restore_current_blog();
	}
	if ( $local > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $local );
	}
	if ( $secondary > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_blog( $secondary, true );
	}
	delete_site_option( $network_control );
	delete_site_option( $network_ordinary );
	wpai199w_restore_option( Settings::OPTION_NAME, $main_ordinary );
	wpai199w_restore_option( Settings::DATABASE_OPTION_NAME, $main_database );
	wp_set_current_user( $actor );
}
