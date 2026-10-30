<?php
/**
 * Shared observable assertions for disposable typed-write native fixtures.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai199w_assert( $condition, $message ) {
	$GLOBALS['wpai199w_checks'] = 1 + ( $GLOBALS['wpai199w_checks'] ?? 0 );
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function wpai199w_call( array $input ) {
	$ability = wp_get_ability( 'wp-ai-bridge/database-write' );
	wpai199w_assert( $ability instanceof WP_Ability, 'Missing typed database-write Ability.' );
	return $ability->execute( $input );
}
function wpai199w_ok( $result, $message ) {
	wpai199w_assert( ! is_wp_error( $result ), $message . ( is_wp_error( $result ) ? ' [' . $result->get_error_code() . ']' : '' ) );
	wpai199w_assert( is_array( $result ), 'Database result must be an object-shaped array.' );
	return $result;
}
function wpai199w_error( $result, $message, $codes = array() ) {
	wpai199w_assert( is_wp_error( $result ), $message );
	if ( $codes ) {
		wpai199w_assert( in_array( $result->get_error_code(), $codes, true ), 'Unexpected error at ' . $message . ': ' . $result->get_error_code() );
	}
	return $result;
}
function wpai199w_grants( $delete = true ) {
	$settings = new Settings();
	$grants = $settings->database_defaults();
	foreach ( array( Settings::GROUP_DATABASE_INSPECTION, Settings::GROUP_DATABASE_READ, Settings::GROUP_DATABASE_WRITE ) as $group ) {
		$grants[ $group ] = 1;
	}
	$grants[ Settings::GROUP_DATABASE_DELETE ] = $delete ? 1 : 0;
	return $grants;
}
function wpai199w_describe( $table ) {
	$result = wpai199w_ok( wpai199w_call( array( 'action' => 'describe', 'table' => $table ) ), 'Cannot describe eligible write table.' );
	wpai199w_assert( is_string( $result['schema_hash'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $result['schema_hash'] ), 'Describe omitted its exact schema hash.' );
	wpai199w_assert( isset( $result['primary_key'] ) && is_array( $result['primary_key'] ), 'Describe omitted ordered primary-key metadata.' );
	return $result;
}
function wpai199w_key( $id, $column = 'id' ) {
	return array( $column => base64_encode( (string) $id ) );
}
function wpai199w_state( $table, array $key ) {
	$result = wpai199w_ok( wpai199w_call( array( 'action' => 'row_state', 'table' => $table, 'key' => $key ) ), 'Cannot inspect complete current row state.' );
	wpai199w_assert( is_string( $result['state_hash'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $result['state_hash'] ), 'row_state omitted its full-row hash.' );
	wpai199w_assert( ! isset( $result['values'], $result['cells'], $result['row'] ), 'row_state disclosed complete sensitive values.' );
	return $result;
}
function wpai199w_step( $action, $table, $schema, array $values = array(), $id = null ) {
	$step = array( 'action' => $action, 'table' => $table, 'expected_schema_hash' => $schema );
	if ( 'delete' !== $action ) {
		$step['values'] = $values;
	}
	if ( 'insert' !== $action ) {
		$step['key'] = wpai199w_key( $id );
		$step['expected_state_hash'] = wpai199w_state( $table, $step['key'] )['state_hash'];
	}
	return $step;
}
function wpai199w_execute( $operation, array $steps ) {
	return wpai199w_call( array( 'action' => 'execute', 'operation_id' => $operation, 'operations' => $steps ) );
}
function wpai199w_bytes( $table, $column, $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT %i IS NULL AS n, HEX(CAST(%i AS BINARY)) AS h FROM %i WHERE id = %s', $column, $column, $table, (string) $id ), ARRAY_A );
	wpai199w_assert( is_array( $row ), 'Expected exact fixture row was absent.' );
	return '1' === (string) $row['n'] ? null : hex2bin( $row['h'] );
}
function wpai199w_count( $table, $id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %s', $table, (string) $id ) );
}
function wpai199w_updates() {
	global $wpdb;
	$row = $wpdb->get_row( "SHOW SESSION STATUS LIKE 'Com_update'", ARRAY_A );
	return (int) $row['Value'];
}
function wpai199w_restore_option( $name, $original ) {
	if ( false === $original ) {
		delete_option( $name );
		return;
	}
	$restore = static function () use ( $original ) { return $original; };
	add_filter( 'pre_update_option_' . $name, $restore, PHP_INT_MAX );
	try {
		update_option( $name, $original, false );
	} finally {
		remove_filter( 'pre_update_option_' . $name, $restore, PHP_INT_MAX );
	}
}
function wpai199w_restore_receipts( array $before ) {
	global $wpdb;
	// This fixture is exclusive. Delete only new receipts under the engine's
	// fixed namespace; preserve all prior durable identities byte/type-exact.
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'wpai_database_write_' ) . '%' ) ) as $name ) {
		if ( ! in_array( $name, $before, true ) ) {
			delete_option( $name );
		}
	}
}
function wpai199w_receipts_before() {
	global $wpdb;
	return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'wpai_database_write_' ) . '%' ) );
}
