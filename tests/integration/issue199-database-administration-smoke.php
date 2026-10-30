<?php
/**
 * Actual public database schema and native-account SQL fixture.
 *
 * Run only via wp eval-file in a coordinator-owned disposable installation.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Database_Connection;
use WP_AI_Bridge\Support\Database_Native_SQL_Operation;
use WP_AI_Bridge\Auth\OAuth_Server;

global $wpdb;
$expected_database = getenv( 'WPAI199C_EXPECT_DATABASE' );
$expected_engine = getenv( 'WPAI199C_EXPECT_ENGINE' );
if ( ! defined( 'WPAI_ADMIN13_DISPOSABLE' ) || true !== WPAI_ADMIN13_DISPOSABLE ||
	! is_string( $expected_database ) || '' === $expected_database || ! in_array( $expected_engine, array( 'mysql', 'mariadb' ), true ) ||
	$wpdb->dbname !== $expected_database || $wpdb->get_var( 'SELECT DATABASE()' ) !== $expected_database ||
	! defined( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL' ) || true !== WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL ) {
	throw new RuntimeException( 'Explicit disposable fixture/database/engine/native-SQL configuration is required.' );
}
$version = (string) $wpdb->get_var( 'SELECT VERSION()' );
$mariadb = false !== stripos( $version, 'MariaDB' );
if ( $mariadb !== ( 'mariadb' === $expected_engine ) ) {
	throw new RuntimeException( 'Native engine does not match the coordinator expectation.' );
}
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ( ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) ) ) {
	throw new RuntimeException( 'The disposable native administrator must exist.' );
}
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$call = static function ( $name, $input ) use ( $assert ) {
	$ability = wp_get_ability( $name );
	$assert( is_object( $ability ), 'Missing actual public administration Ability.' );
	return $ability->execute( $input );
};
$ok = static function ( $result, $message ) use ( $assert ) {
	$assert( ! is_wp_error( $result ), $message . ( is_wp_error( $result ) ? ': ' . $result->get_error_code() : '' ) );
	return $result;
};
$schema = static function ( $input ) use ( $call ) { return $call( 'wp-ai-bridge/database-schema', $input ); };
$sql = static function ( $input ) use ( $call ) { return $call( 'wp-ai-bridge/database-native-sql', $input ); };
$nonce = bin2hex( random_bytes( 6 ) );
$table = $wpdb->base_prefix . 'admin13_schema_' . $nonce;
$child = $table . '_child';
$destination = $table . '_renamed';
$old_grants = get_option( Settings::DATABASE_OPTION_NAME, null );
$grants = array_fill_keys( array_keys( ( new Settings() )->database_defaults() ), 1 );
$tables = array( $child, $destination, $table );
$results = array();
try {
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	$create = array( 'action' => 'execute', 'operation_id' => 'schema-create-' . $nonce, 'operation' => array(
		'action' => 'create_table', 'table' => $table, 'expected_schema_hash' => null,
		'columns' => array( array( 'name' => 'id', 'type' => 'bigint', 'nullable' => false, 'auto_increment' => true ), array( 'name' => 'body', 'type' => 'longblob', 'nullable' => true ) ),
		'indexes' => array( array( 'name' => 'PRIMARY', 'kind' => 'primary', 'columns' => array( 'id' ) ) ),
		'charset' => 'utf8mb4',
	) );
	$created = $ok( $schema( $create ), 'Typed creation failed' );
	$assert( 'acknowledged' === $created['receipt']['result']['native']['outcome'], 'Creation did not retain native acknowledgement.' );
	$historical = $ok( $schema( $create ), 'Historical creation failed' );
	$assert( true === $historical['historical'] && $historical['operation_ref'] === $created['operation_ref'], 'Repeated creation did not recover its own receipt.' );
	$describe = static function ( $name ) use ( $schema, $ok ) { return $ok( $schema( array( 'action' => 'describe', 'table' => $name ) ), 'Cannot observe native schema' ); };
	$execute = static function ( $action, $extra = array(), $target = null ) use ( $schema, $ok, $describe, &$table, $nonce ) {
		$name = null === $target ? $table : $target;
		$before = $describe( $name );
		return $ok( $schema( array( 'action' => 'execute', 'operation_id' => 'schema-' . $action . '-' . $nonce . '-' . bin2hex( random_bytes( 3 ) ),
			'operation' => array_merge( array( 'action' => $action, 'table' => $name, 'expected_schema_hash' => $before['schema_hash'] ), $extra ) ) ), 'Typed ' . $action . ' failed' );
	};
	$wpdb->query( 'INSERT INTO ' . Database_Connection::quote( $table ) . " (body) VALUES (X'00ff27')" );
	$old = $describe( $table );
	$added = $execute( 'add_column', array( 'column' => array( 'name' => 'label', 'type' => 'varchar', 'length' => 64, 'nullable' => false, 'default' => base64_encode( "x'\\y" ) ) ) );
	$assert( 'acknowledged' === $added['receipt']['result']['native']['outcome'] && "x'\\y" === $wpdb->get_var( 'SELECT label FROM ' . Database_Connection::quote( $table ) . ' LIMIT 1' ), 'Native literal/default bytes changed.' );
	$stale = $schema( array( 'action' => 'execute', 'operation_id' => 'schema-stale-' . $nonce, 'operation' => array( 'action' => 'drop_column', 'table' => $table, 'expected_schema_hash' => $old['schema_hash'], 'name' => 'label' ) ) );
	$assert( is_wp_error( $stale ) && 'database_schema_stale' === $stale->get_error_code(), 'Stale observed schema was adopted.' );
	$execute( 'add_index', array( 'index' => array( 'name' => 'body_prefix', 'kind' => 'index', 'columns' => array( array( 'prefix' => 64, 'name' => 'body' ) ) ) ) );
	$execute( 'modify_column', array( 'column' => array( 'name' => 'label', 'type' => 'varchar', 'length' => 128, 'nullable' => false, 'default' => base64_encode( "x'\\y" ) ) ) );
	$execute( 'drop_index', array( 'name' => 'body_prefix' ) );
	$execute( 'drop_column', array( 'name' => 'label' ) );
	$wpdb->query( 'CREATE TABLE ' . Database_Connection::quote( $child ) . ' (id BIGINT NOT NULL PRIMARY KEY,pid BIGINT,FOREIGN KEY(pid) REFERENCES ' . Database_Connection::quote( $table ) . '(id)) ENGINE=InnoDB' );
	$blocked = $execute( 'truncate_table' );
	$assert( 'native_error' === $blocked['receipt']['result']['native']['outcome'] && '1' === (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database_Connection::quote( $table ) ), 'Inbound FK veto was called applied or disabled.' );
	$wpdb->query( 'DROP TABLE ' . Database_Connection::quote( $child ) );
	$execute( 'rename_column', array( 'name' => 'id', 'new_name' => 'row_id' ) );
	if ( ! $mariadb ) {
		$renamed = $execute( 'rename_table', array( 'new_name' => $destination, 'expected_destination_absent' => true ) );
		$assert( 'acknowledged' === $renamed['receipt']['result']['native']['outcome'], 'Native table rename fence failed.' );
		$table = $destination;
	} else {
		$before = $describe( $table );
		$rename = $schema( array( 'action' => 'execute', 'operation_id' => 'schema-table-rename-' . $nonce, 'operation' => array( 'action' => 'rename_table', 'table' => $table, 'expected_schema_hash' => $before['schema_hash'], 'new_name' => $destination, 'expected_destination_absent' => true ) ) );
		$assert( is_wp_error( $rename ) && 'database_schema_exclusive_ddl_required' === $rename->get_error_code(), 'Unproved MariaDB table rename claimed a retained fence.' );
	}
	$execute( 'truncate_table' );
	$assert( '0' === (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database_Connection::quote( $table ) ), 'Acknowledged truncate retained rows.' );
	$protected = $schema( array( 'action' => 'execute', 'operation_id' => 'schema-control-' . $nonce, 'operation' => array( 'action' => 'drop_table', 'table' => $wpdb->options, 'expected_schema_hash' => str_repeat( 'a', 64 ) ) ) );
	$assert( is_wp_error( $protected ) && 'database_schema_control_dependency' === $protected->get_error_code(), 'Whole control dependency was exposed to typed DDL.' );

	$native = array( 'action' => 'prepare', 'operation_id' => 'sql-native-' . $nonce, 'acknowledge_native_account_scope' => true,
		'statement_base64' => base64_encode( "SELECT CAST(9007199254740993 AS UNSIGNED) AS exact_int,CAST(1.234567890123456789 AS DECIMAL(30,18)) AS exact_dec,CAST(NULL AS CHAR(1)) AS null_value,X'00ff27' AS raw_bytes,1.5e0 AS floating" ),
		'parameters' => array(), 'max_result_bytes' => 65536 );
	$prepared = $ok( $sql( $native ), 'Native SQL preparation failed' );
	$assert( $prepared['resource_admission']['row_bound_bytes'] < $prepared['resource_admission']['max_allowed_packet'], 'Small metadata-bound result required the full native packet allocation.' );
	$run = array_replace( $native, array( 'action' => 'execute', 'preparation_token' => $prepared['preparation_token'] ) );
	$executed = $ok( $sql( $run ), 'Native SQL execute failed' );
	$assert( 'acknowledged' === $executed['receipt']['result']['native']['outcome'] && true === $executed['receipt']['result']['result_complete'], 'Native SQL acknowledgement/result completeness was lost.' );
	$lease = $executed['private_result'];
	$results[] = array( $native['operation_id'], $lease['id'] );
	$content = '';
	$offset = 0;
	do {
		$window = $ok( $sql( array( 'action' => 'result_read', 'operation_id' => $native['operation_id'], 'acknowledge_native_account_scope' => true, 'result_id' => $lease['id'], 'offset' => $offset, 'length' => 512, 'expected_sha256' => $lease['sha256'] ) ), 'Native result window failed' );
		$bytes = base64_decode( $window['base64'], true );
		$assert( hash( 'sha256', $bytes ) === $window['chunk_sha256'], 'Native result window checksum failed.' );
		$content .= $bytes;
		$offset = $window['next_offset'];
	} while ( ! $window['complete'] );
	$assert( hash( 'sha256', $content ) === $lease['sha256'], 'Whole private result checksum failed.' );
	$frames = array_map( static function ( $line ) { return json_decode( $line, true, 16, JSON_THROW_ON_ERROR ); }, explode( "\n", rtrim( $content, "\n" ) ) );
	$cells = array();
	foreach ( $frames as $frame ) { if ( isset( $frame['column'] ) ) { $cells[ $frame['column'] ] = $frame; } }
	$assert( '9007199254740993' === base64_decode( $cells[0]['data'], true ) && '1.234567890123456789' === base64_decode( $cells[1]['data'], true ), 'Native integer/decimal exact bytes lost precision.' );
	$assert( true === $cells[2]['is_null'] && "\0\xff'" === base64_decode( $cells[3]['data'], true ) && pack( 'E', 1.5 ) === base64_decode( $cells[4]['data'], true ), 'NULL/binary/native floating representation changed.' );
	$again = $ok( $sql( $run ), 'Native historical execute failed' );
	$assert( true === $again['historical'], 'Consumed native SQL token executed again.' );
	$revoked = $grants; $revoked[ Settings::GROUP_DATABASE_SQL ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $revoked, false );
	$assert( is_wp_error( $sql( array( 'action' => 'result_status', 'operation_id' => $native['operation_id'], 'acknowledge_native_account_scope' => true, 'result_id' => $lease['id'] ) ) ), 'Revoked SQL result authority remained usable.' );
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	$execute( 'drop_table' );
	echo 'PASS: actual typed schema/native-account SQL (' . $checks . ' checks; ' . $version . ").\n";
} finally {
	foreach ( $results as $result ) {
		$sql( array( 'action' => 'result_release', 'operation_id' => $result[0], 'acknowledge_native_account_scope' => true, 'result_id' => $result[1] ) );
	}
	foreach ( $tables as $cleanup ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database_Connection::quote( $cleanup ) );
	}
	if ( null === $old_grants ) { delete_option( Settings::DATABASE_OPTION_NAME ); } else { update_option( Settings::DATABASE_OPTION_NAME, $old_grants, false ); }
}
