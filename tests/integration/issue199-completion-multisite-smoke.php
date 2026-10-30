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
$sql_results = array();
$sql_bytes = "multisite SQL\0\xffسلام";
$sql_pending_run = null;
wpai199c_assert( defined( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL' ) && true === WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL, 'Explicit disposable native-account SQL server policy required.' );
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
		'database-native-sql' => array( 'action' => 'prepare', 'operation_id' => 'admin13-ms-sql-' . $suffix,
			'acknowledge_native_account_scope' => true, 'statement_base64' => base64_encode( 'SELECT ? AS exact_bytes' ),
			'parameters' => array( base64_encode( $sql_bytes ) ), 'max_result_bytes' => 65536 ),
	);
	$sql_prepared = null;
	foreach ( $map as $ability => $input ) {
		$positive = wpai199c_ok( wpai199c_ability( $ability, $input ), 'Current native Super Admin could not use explicitly granted family: ' . $ability );
		if ( 'database-native-sql' === $ability ) { $sql_prepared = $positive; }
	}
	$sql_run = array_replace( $map['database-native-sql'], array( 'action' => 'execute', 'preparation_token' => $sql_prepared['preparation_token'] ) );
	$sql_executed = wpai199c_ok( wpai199c_ability( 'database-native-sql', $sql_run ), 'Actual Super Admin native-account SQL dispatch failed.' );
	wpai199c_assert( false === $sql_executed['installation_confined'] && false === $sql_executed['reexecution_allowed'], 'Native-account SQL invented a blog/table confinement claim or allowed blind resend.' );
	$sql_lease = $sql_executed['private_result'];
	$sql_results[] = array( $sql_run['operation_id'], $sql_lease['id'], $sql_lease['sha256'] );
	$sql_content = ''; $sql_offset = 0;
	do {
		$sql_window = wpai199c_ok( wpai199c_ability( 'database-native-sql', array( 'action' => 'result_read', 'operation_id' => $sql_run['operation_id'],
			'acknowledge_native_account_scope' => true, 'result_id' => $sql_lease['id'], 'offset' => $sql_offset, 'length' => 97, 'expected_sha256' => $sql_lease['sha256'] ) ), 'Multisite exact private SQL result window failed.' );
		$sql_piece = base64_decode( $sql_window['base64'], true );
		wpai199c_assert( is_string( $sql_piece ) && hash( 'sha256', $sql_piece ) === $sql_window['chunk_sha256'], 'Multisite private SQL result window checksum changed.' );
		$sql_content .= $sql_piece; $sql_offset = $sql_window['next_offset'];
	} while ( ! $sql_window['complete'] );
	wpai199c_assert( hash( 'sha256', $sql_content ) === $sql_lease['sha256'], 'Multisite complete native SQL result checksum changed.' );
	$sql_cells = '';
	foreach ( explode( "\n", trim( $sql_content ) ) as $line ) {
		$frame = json_decode( $line, true, 32, JSON_THROW_ON_ERROR );
		if ( '0' === (string) ( $frame['row'] ?? '' ) && 0 === ( $frame['column'] ?? null ) ) { $sql_cells .= base64_decode( $frame['data'], true ); }
	}
	wpai199c_assert( $sql_bytes === $sql_cells, 'Actual multisite native-account SQL binary cell was not exact.' );
	$sql_grants = get_option( Settings::DATABASE_OPTION_NAME );
	foreach ( Settings::DATABASE_GROUPS as $index => $group ) {
		$without = $sql_grants; $without[ $group ] = 0;
		update_option( Settings::DATABASE_OPTION_NAME, ( new Settings() )->sanitize_database_grants( $without ), false );
		$denied_prepare = $map['database-native-sql']; $denied_prepare['operation_id'] .= '-deny-' . $index;
		wpai199c_assert( is_wp_error( wpai199c_ability( 'database-native-sql', $denied_prepare ) ), 'One missing native database consent still admitted account SQL: ' . $group );
	}
	update_option( Settings::DATABASE_OPTION_NAME, $sql_grants, false );
	$sql_pending = $map['database-native-sql']; $sql_pending['operation_id'] .= '-pending';
	$sql_pending_prepared = wpai199c_ok( wpai199c_ability( 'database-native-sql', $sql_pending ), 'Originating blog could not reserve its private native SQL preparation.' );
	$sql_pending_run = array_replace( $sql_pending, array( 'action' => 'execute', 'preparation_token' => $sql_pending_prepared['preparation_token'] ) );
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
		if ( in_array( $ability, array( 'database-schema', 'database-native-sql' ), true ) ) {
			continue; // Native-account SQL intentionally has the acknowledged account scope.
		}
		wpai199c_assert( is_wp_error( wpai199c_ability( $ability, $input ) ), 'Copied protected grant/config adopted another blog binding: ' . $ability );
	}
	wpai199c_assert( is_wp_error( wpai199c_ability( 'filesystem-transfer', array( 'action' => 'status', 'root_id' => 'fixtures', 'transfer_id' => $stage_id ) ) ), 'Second blog adopted a source-blog private continuation.' );
	// Genuine current second-blog consent may prepare account SQL; an old blog's token/result is never adopted.
	update_option( Settings::DATABASE_OPTION_NAME, ( new Settings() )->sanitize_database_grants( array_fill_keys( Settings::DATABASE_GROUPS, 1 ) ), false );
	$second_sql = $map['database-native-sql']; $second_sql['operation_id'] .= '-second';
	wpai199c_ok( wpai199c_ability( 'database-native-sql', $second_sql ), 'Freshly consented actual Super Admin could not prepare native account SQL in its current blog.' );
	wpai199c_assert( is_wp_error( wpai199c_ability( 'database-native-sql', $sql_pending_run ) ), 'Second blog adopted the originating blog private preparation token.' );
	foreach ( array( 'result_status', 'result_read' ) as $action ) {
		$old_result = array( 'action' => $action, 'operation_id' => $sql_run['operation_id'], 'acknowledge_native_account_scope' => true, 'result_id' => $sql_lease['id'] );
		if ( 'result_read' === $action ) { $old_result += array( 'offset' => 0, 'length' => 97, 'expected_sha256' => $sql_lease['sha256'] ); }
		wpai199c_assert( is_wp_error( wpai199c_ability( 'database-native-sql', $old_result ) ), 'Second blog adopted an originating-blog native SQL result.' );
	}
	$foreign_recovery = wpai199c_ok( wpai199c_ability( 'database-native-sql', array( 'action' => 'recover', 'operation_id' => $sql_run['operation_id'], 'acknowledge_native_account_scope' => true ) ), 'Current native account recovery observation failed.' );
	wpai199c_assert( 'not_recorded' === $foreign_recovery['state'] && false === $foreign_recovery['reexecution_allowed'] && $foreign_recovery['operation_ref'] !== $sql_executed['operation_ref'], 'Second blog adopted an originating-blog SQL receipt or offered replay.' );

	wpai199c_restore_options( $second_before );
	$second_before = null;
	restore_current_blog();
	$origin_result = wpai199c_ok( wpai199c_ability( 'database-native-sql', array( 'action' => 'result_status', 'operation_id' => $sql_run['operation_id'], 'acknowledge_native_account_scope' => true, 'result_id' => $sql_lease['id'] ) ), 'Originating blog lost its private SQL result after another-blog refusal.' );
	wpai199c_assert( $origin_result['sha256'] === $sql_lease['sha256'], 'Another blog changed the original private result identity.' );
	$origin_pending = wpai199c_ok( wpai199c_ability( 'database-native-sql', $sql_pending_run ), 'Another blog consumed the original one-use SQL preparation.' );
	wpai199c_assert( false === $origin_pending['historical'], 'Originating private preparation was dispatched outside its own blog.' );
	$sql_results[] = array( $sql_pending_run['operation_id'], $origin_pending['private_result']['id'], $origin_pending['private_result']['sha256'] );
	$without_sql = $sql_grants; $without_sql[ Settings::GROUP_DATABASE_SQL ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, ( new Settings() )->sanitize_database_grants( $without_sql ), false );
	wpai199c_assert( is_wp_error( wpai199c_ability( 'database-native-sql', array( 'action' => 'result_status', 'operation_id' => $sql_run['operation_id'], 'acknowledge_native_account_scope' => true, 'result_id' => $sql_lease['id'] ) ) ), 'Current native SQL consent revocation left an old result readable.' );
	update_option( Settings::DATABASE_OPTION_NAME, $sql_grants, false );
	foreach ( $sql_results as $owned_result ) {
		wpai199c_ok( wpai199c_ability( 'database-native-sql', array( 'action' => 'result_release', 'operation_id' => $owned_result[0], 'acknowledge_native_account_scope' => true, 'result_id' => $owned_result[1] ) ), 'Originating blog native SQL result release failed.' );
		wpai199c_assert( is_wp_error( wpai199c_ability( 'database-native-sql', array( 'action' => 'result_read', 'operation_id' => $owned_result[0], 'acknowledge_native_account_scope' => true, 'result_id' => $owned_result[1], 'offset' => 0, 'length' => 1, 'expected_sha256' => $owned_result[2] ) ) ), 'Released originating SQL result remained readable.' );
	}
	$sql_results = array();
	$origin_recovery = wpai199c_ok( wpai199c_ability( 'database-native-sql', array( 'action' => 'recover', 'operation_id' => $sql_run['operation_id'], 'acknowledge_native_account_scope' => true ) ), 'Originating blog historical SQL recovery failed after result release.' );
	wpai199c_assert( true === $origin_recovery['historical'] && false === $origin_recovery['reexecution_allowed'], 'Result release erased the native SQL one-dispatch recovery fence.' );
	wpai199c_ok( wpai199c_ability( 'filesystem-transfer', array( 'action' => 'status', 'root_id' => 'fixtures', 'transfer_id' => $stage_id ) ), 'Original blog lost its genuine private continuation.' );
	$flags = ( new Settings() )->sanitize_administration_grants( array_fill_keys( Settings::ADMINISTRATION_GROUPS, 0 ) );
	update_option( Settings::ADMINISTRATION_OPTION_NAME, $flags, false );
	foreach ( $map as $ability => $input ) {
		if ( ! in_array( $ability, array( 'database-schema', 'database-native-sql' ), true ) ) {
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
	foreach ( $sql_results as $owned_result ) { wpai199c_ability( 'database-native-sql', array( 'action' => 'result_release', 'operation_id' => $owned_result[0], 'acknowledge_native_account_scope' => true, 'result_id' => $owned_result[1] ) ); }
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
