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
	$key_column = 'id';
	$unproved_rename = static function ( array $operation, $suffix ) use ( $schema, $ok, $assert, $describe, $wpdb, &$table, &$key_column, $version, $nonce ) {
		$before = $describe( $table );
		$ddl_before = $wpdb->get_var( 'SHOW CREATE TABLE ' . Database_Connection::quote( $table ), 1 );
		$rows_sql = 'SELECT ' . Database_Connection::quote( $key_column ) . ',HEX(body) AS body FROM ' . Database_Connection::quote( $table ) . ' ORDER BY ' . Database_Connection::quote( $key_column ) . ' LIMIT 2';
		$rows_before = $wpdb->get_results( $rows_sql, ARRAY_A );
		$operation_id = 'schema-unproved-' . $suffix . '-' . $nonce;
		$rename = $schema( array( 'action' => 'execute', 'operation_id' => $operation_id,
			'operation' => array_merge( array( 'table' => $table, 'expected_schema_hash' => $before['schema_hash'] ), $operation ) ) );
		$details = is_wp_error( $rename ) ? $rename->get_error_data() : null;
		$assert( is_wp_error( $rename ) && 'database_schema_exclusive_ddl_required' === $rename->get_error_code() &&
			is_array( $details ) && 'unproven_engine_specific_rename_fence' === ( $details['precondition'] ?? null ) &&
			$version === ( $details['native_version'] ?? null ) && 'observed_not_atomic' === ( $details['precondition_strength'] ?? null ) &&
			! isset( $details['native'] ) && ! isset( $details['outcome'] ), 'Unqualified native rename did not report its exact pre-dispatch fence prerequisite.' );
		$after = $describe( $table );
		$assert( is_string( $ddl_before ) && '' !== $ddl_before && $before['schema_hash'] === $after['schema_hash'] &&
			$ddl_before === $wpdb->get_var( 'SHOW CREATE TABLE ' . Database_Connection::quote( $table ), 1 ) &&
			is_array( $rows_before ) && count( $rows_before ) === 1 && $rows_before === $wpdb->get_results( $rows_sql, ARRAY_A ),
			'Unqualified native rename changed the exact target definition or fixture row bytes.' );
		$recovered = $ok( $schema( array( 'action' => 'recover', 'operation_id' => $operation_id ) ), 'Cannot inspect unqualified rename journal history' );
		$assert( 'not_recorded' === ( $recovered['status'] ?? null ) && true === ( $recovered['absence_is_not_reexecution_authority'] ?? null ),
			'Unqualified native rename reserved or armed a journal, or claimed absence authorized resend.' );
		echo 'WPAI199C_RENAME_PREREQUISITE=' . json_encode( array( 'action' => $operation['action'], 'native_version' => $version,
			'precondition' => $details['precondition'], 'target_unchanged' => true, 'journal' => 'not_recorded' ), JSON_THROW_ON_ERROR ) . "\n";
	};
	if ( $mariadb && 1 === preg_match( '/^12\.3\./', $version ) ) {
		// CI MariaDB 12.3 has no two-session fence qualification. Keep the product gate
		// and positively verify its exact pre-effect compatibility contract.
		$unproved_rename( array( 'action' => 'rename_column', 'name' => 'id', 'new_name' => 'row_id' ), 'column' );
	} else {
		$column_renamed = $execute( 'rename_column', array( 'name' => 'id', 'new_name' => 'row_id' ) );
		$assert( 'acknowledged' === $column_renamed['receipt']['result']['native']['outcome'] &&
			'owned_write_lock_definition_and_complete_fk_checks' === ( $column_renamed['receipt']['result']['native']['rename_fence'] ?? null ),
			'Qualified native column rename lost its acknowledged owned fence.' );
		$key_column = 'row_id';
	}
	if ( ! $mariadb ) {
		$renamed = $execute( 'rename_table', array( 'new_name' => $destination, 'expected_destination_absent' => true ) );
		$assert( 'acknowledged' === $renamed['receipt']['result']['native']['outcome'], 'Native table rename fence failed.' );
		$table = $destination;
	} else {
		$unproved_rename( array( 'action' => 'rename_table', 'new_name' => $destination, 'expected_destination_absent' => true ), 'table' );
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
	$released = $ok( $sql( array( 'action' => 'result_release', 'operation_id' => $native['operation_id'], 'acknowledge_native_account_scope' => true, 'result_id' => $lease['id'] ) ), 'Verified SQL result disposal failed' );
	$assert( 'cancelled' === $released['state'] && true === $released['payload_released'], 'Immutable SQL result disposal was not acknowledged.' );
	array_pop( $results );
	$assert( is_wp_error( $sql( array( 'action' => 'result_status', 'operation_id' => $native['operation_id'], 'acknowledge_native_account_scope' => true, 'result_id' => $lease['id'] ) ) ), 'Disposed SQL result remained available.' );
	$recovered = $ok( $sql( array( 'action' => 'recover', 'operation_id' => $native['operation_id'], 'acknowledge_native_account_scope' => true ) ), 'SQL effect history was lost after result disposal' );
	$after_disposal = $ok( $sql( $run ), 'Disposed SQL result prevented historical execute recovery' );
	$assert( true === $recovered['historical'] && true === $after_disposal['historical'] &&
		$executed['operation_ref'] === $recovered['operation_ref'] && $executed['receipt'] === $recovered['receipt'] &&
		$executed['receipt'] === $after_disposal['receipt'], 'Result disposal altered the retained SQL effect receipt.' );

	// A native DML effect makes accidental redispatch independently observable.
	$assert( 1 === $wpdb->query( 'INSERT INTO ' . Database_Connection::quote( $table ) . ' (' . Database_Connection::quote( $key_column ) . ",body) VALUES (7,X'00')" ), 'Cannot seed the private no-redispatch row.' );
	$mutation = array( 'action' => 'prepare', 'operation_id' => 'sql-disposal-' . $nonce, 'acknowledge_native_account_scope' => true,
		'statement_base64' => base64_encode( 'UPDATE ' . Database_Connection::quote( $table ) . " SET body=CONCAT(body,X'61') WHERE " . Database_Connection::quote( $key_column ) . '=7' ),
		'parameters' => array(), 'max_result_bytes' => 4096 );
	$mutation_prepared = $ok( $sql( $mutation ), 'Native disposal mutation preparation failed' );
	$mutation_run = array_replace( $mutation, array( 'action' => 'execute', 'preparation_token' => $mutation_prepared['preparation_token'] ) );
	$mutated = $ok( $sql( $mutation_run ), 'Native disposal mutation execute failed' );
	$results[] = array( $mutation['operation_id'], $mutated['private_result']['id'] );
	$assert( 'acknowledged' === $mutated['receipt']['result']['native']['outcome'] &&
		'0061' === $wpdb->get_var( 'SELECT HEX(body) FROM ' . Database_Connection::quote( $table ) . ' WHERE ' . Database_Connection::quote( $key_column ) . '=7' ), 'The single native effect was not acknowledged with exact row bytes.' );
	$mutation_disposed = $ok( $sql( array( 'action' => 'result_release', 'operation_id' => $mutation['operation_id'], 'acknowledge_native_account_scope' => true, 'result_id' => $mutated['private_result']['id'] ) ), 'Native mutation result disposal failed' );
	$assert( 'cancelled' === $mutation_disposed['state'] && true === $mutation_disposed['payload_released'], 'Native mutation result was not disposed.' );
	array_pop( $results );
	$mutation_recovered = $ok( $sql( array( 'action' => 'recover', 'operation_id' => $mutation['operation_id'], 'acknowledge_native_account_scope' => true ) ), 'Native mutation receipt recovery failed' );
	$mutation_replayed = $ok( $sql( $mutation_run ), 'Native mutation historical replay failed' );
	$assert( true === $mutation_recovered['historical'] && true === $mutation_replayed['historical'] &&
		$mutated['operation_ref'] === $mutation_recovered['operation_ref'] && $mutated['receipt'] === $mutation_replayed['receipt'] &&
		'0061' === $wpdb->get_var( 'SELECT HEX(body) FROM ' . Database_Connection::quote( $table ) . ' WHERE ' . Database_Connection::quote( $key_column ) . '=7' ), 'Recovery after result disposal resent native DML or changed its effect history.' );
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
