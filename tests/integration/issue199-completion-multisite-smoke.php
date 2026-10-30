<?php
/**
 * Current native multisite, protected site/network and private-origin boundaries.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Administration_Configuration;
use WP_AI_Bridge\Support\Native_Option_Access;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-completion-helper.php';
wpai199c_assert( defined( 'WPAI_ADMIN13_DISPOSABLE' ) && true === WPAI_ADMIN13_DISPOSABLE, 'Explicit disposable multisite bootstrap required.' );
if ( ! function_exists( 'wpai199c_seed_provider' ) ) {
	require_once dirname( __DIR__ ) . '/fixtures/issue199-completion-provider.php';
}
global $wpdb;
wpai199c_assert( is_multisite() && is_super_admin() && current_user_can( 'manage_network_options' ), 'Actual native Super Admin multisite context required.' );
wpai199c_assert( '' !== (string) getenv( 'WPAI199C_EXPECT_DATABASE' ) && getenv( 'WPAI199C_EXPECT_DATABASE' ) === $wpdb->get_var( 'SELECT DATABASE()' ), 'Explicit disposable database identity mismatch.' );
$actor = get_current_user_id();
$blog = get_current_blog_id();
$network = get_current_network_id();
$before = wpai199c_options_before();
$network_read = get_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, null );
$network_write = get_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, null );
$suffix = bin2hex( random_bytes( 4 ) );
$seed = null;
$second_blog = 0;
$second_before = null;
$ordinary_admin = 0;
$subscriber = 0;
$stage_id = '';
$map = array();
$table = $wpdb->prefix . 'admin13_multisite_' . $suffix;
$created = false;
try {
	$seed = wpai199c_seed_provider( $suffix );
	wpai199c_configure( $seed['directory'], getenv( 'WPAI199C_RESTORE_ROOT' ), getenv( 'WPAI199C_RESTORE_SCHEMA' ), $seed['tables'], 'network' );
	wpai199c_grants();
	wpai199c_provider_lifecycle_grants();
	update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 1 );
	update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 1 );
	$created = false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY,payload LONGBLOB NULL) ENGINE=InnoDB', $table ) );
	wpai199c_assert( $created, 'Cannot create exact owned multisite schema fixture.' );
	$map = array(
		'filesystem-read' => array( 'action' => 'stat', 'root_id' => 'fixtures', 'path' => 'binary.bin' ),
		'logs-read' => array( 'action' => 'tail', 'root_id' => 'fixtures', 'path' => 'fixture.log', 'max_bytes' => 512, 'lines' => 1 ),
		'scheduler-read' => array( 'action' => 'list', 'provider' => 'wp_cron', 'limit' => 1 ),
		'native-configuration' => array( 'action' => 'read', 'target' => 'site_transient', 'name' => 'admin13_network_' . $suffix ),
		'customizer-settings' => array( 'action' => 'list', 'expected_stylesheet' => get_stylesheet(), 'limit' => 3 ),
		'full-backup' => array( 'action' => 'inspect', 'source_roots' => array( 'installation' ) ),
		'database-schema' => array( 'action' => 'describe', 'table' => $table ),
	);
	foreach ( $map as $ability => $input ) {
		wpai199c_ok( wpai199c_ability( $ability, $input ), 'Current native Super Admin could not use explicitly granted family: ' . $ability );
	}
	$ordinary_admin = wp_insert_user( array( 'user_login' => 'admin13-site-' . $suffix, 'user_pass' => wp_generate_password( 40 ), 'user_email' => 'admin13-site-' . $suffix . '@example.invalid', 'role' => 'administrator' ) );
	$subscriber = wp_insert_user( array( 'user_login' => 'admin13-reader-' . $suffix, 'user_pass' => wp_generate_password( 40 ), 'user_email' => 'admin13-reader-' . $suffix . '@example.invalid', 'role' => 'subscriber' ) );
	wpai199c_assert( is_int( $ordinary_admin ) && is_int( $subscriber ), 'Cannot create disposable real native authority roles.' );
	wp_set_current_user( $ordinary_admin );
	wpai199c_assert( current_user_can( 'manage_options' ) && ! is_super_admin(), 'Site administrator fixture unexpectedly owns network authority.' );
	foreach ( $map as $ability => $input ) {
		wpai199c_assert( is_wp_error( wpai199c_ability( $ability, $input ) ), 'Site administrator adopted a shared/network/provider operation: ' . $ability );
	}
	wp_set_current_user( $subscriber );
	foreach ( $map as $ability => $input ) {
		wpai199c_assert( is_wp_error( wpai199c_ability( $ability, $input ) ), 'Native capability loss was ignored: ' . $ability );
	}
	wp_set_current_user( $actor );
	$stage = wpai199c_ok( wpai199c_ability( 'filesystem-transfer', array( 'action' => 'begin', 'root_id' => 'fixtures', 'total_bytes' => 3, 'sha256' => hash( 'sha256', 'abc' ) ) ), 'Cannot establish a genuine source-blog private continuation.' );
	$stage_id = $stage['id'];
	$source_options = array();
	foreach ( array( Settings::OPTION_NAME, Settings::DATABASE_OPTION_NAME, Settings::ADMINISTRATION_OPTION_NAME, Administration_Configuration::OPTION_NAME ) as $name ) {
		$source_options[ $name ] = get_option( $name );
	}
	$second_blog = wpmu_create_blog( 'admin13-' . $suffix . '.example.invalid', '/', 'Disposable administration context', $actor, array( 'public' => 0 ), $network );
	wpai199c_assert( is_int( $second_blog ) && $second_blog > 0, 'Cannot create a real second-blog context.' );
	switch_to_blog( $second_blog );
	$second_before = wpai199c_options_before();
	// Simulate persisted data adoption without invoking the genuine setter,
	// which would deliberately create fresh authorized second-blog bindings.
	foreach ( $source_options as $name => $value ) {
		wpai199c_assert( false !== $wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => maybe_serialize( $value ), 'autoload' => 'no' ), array( '%s', '%s', '%s' ) ), 'Cannot create exact stored-context fixture.' );
		wp_cache_delete( $name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	foreach ( $map as $ability => $input ) {
		if ( 'database-schema' === $ability ) {
			continue; // Legacy DB grants have their own installation/network policy.
		}
		wpai199c_assert( is_wp_error( wpai199c_ability( $ability, $input ) ), 'Copied protected grant/config adopted another blog binding: ' . $ability );
	}
	wpai199c_assert( is_wp_error( wpai199c_ability( 'filesystem-transfer', array( 'action' => 'status', 'root_id' => 'fixtures', 'transfer_id' => $stage_id ) ) ), 'Second blog adopted a source-blog private continuation.' );
	wpai199c_restore_options( $second_before );
	$second_before = null;
	restore_current_blog();
	wpai199c_ok( wpai199c_ability( 'filesystem-transfer', array( 'action' => 'status', 'root_id' => 'fixtures', 'transfer_id' => $stage_id ) ), 'Original blog lost its genuine private continuation.' );
	$flags = ( new Settings() )->sanitize_administration_grants( array_fill_keys( Settings::ADMINISTRATION_GROUPS, 0 ) );
	update_option( Settings::ADMINISTRATION_OPTION_NAME, $flags, false );
	foreach ( $map as $ability => $input ) {
		if ( 'database-schema' !== $ability ) {
			wpai199c_assert( is_wp_error( wpai199c_ability( $ability, $input ) ), 'Fresh protected grant revocation was ignored: ' . $ability );
		}
	}
	wpai199c_assert( is_wp_error( wpai199c_ability( 'filesystem-transfer', array( 'action' => 'status', 'root_id' => 'fixtures', 'transfer_id' => $stage_id ) ) ), 'Revoked private continuation remained accessible.' );
	echo "PASS: actual multisite administrator/network authority, capability loss, stored blog binding and fresh private-continuation revocation across new families.\n";
} finally {
	if ( get_current_blog_id() !== $blog ) {
		wp_set_current_user( $actor );
		if ( is_array( $second_before ) ) { wpai199c_restore_options( $second_before ); }
		while ( get_current_blog_id() !== $blog && ms_is_switched() ) { restore_current_blog(); }
	}
	wp_set_current_user( $actor );
	wpai199c_grants();
	if ( '' !== $stage_id ) { wpai199c_ability( 'filesystem-transfer', array( 'action' => 'release', 'root_id' => 'fixtures', 'transfer_id' => $stage_id ) ); }
	if ( $created ) { $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) ); }
	if ( $seed ) { wpai199c_cleanup_seed( $seed, $suffix ); }
	foreach ( array( $ordinary_admin, $subscriber ) as $user_id ) { if ( is_int( $user_id ) && $user_id > 0 ) { wpmu_delete_user( $user_id ); } }
	if ( is_int( $second_blog ) && $second_blog > 0 ) { wpmu_delete_blog( $second_blog, true ); }
	foreach ( array( Native_Option_Access::NETWORK_OPTION_NAME => $network_read, Native_Option_Access::NETWORK_WRITE_OPTION_NAME => $network_write ) as $name => $value ) {
		if ( null === $value ) { delete_network_option( $network, $name ); } else { update_network_option( $network, $name, $value ); }
	}
	wpai199c_restore_options( $before );
}
