<?php
/**
 * Shared test-only native mutation assertions and exact disposable cleanup.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Native_Option_Value;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-native-options-helper.php';

function wpai199nw_call( array $input ) {
	$ability = wp_get_ability( 'wp-ai-bridge/native-options-write' );
	wpai199n_assert( $ability instanceof WP_Ability, 'Missing native-options-write Ability.' );
	return $ability->execute( $input );
}
function wpai199nw_source( $value ) {
	$encoded = wpai199n_ok( Native_Option_Value::encode( $value, 16 * 1024 * 1024 ), 'Cannot encode synthetic native input.' );
	return array( 'value_json' => $encoded['json'] );
}
function wpai199nw_request( $action, $name, $value = null, $scope = 'site', $operation_id = null ) {
	$input = array( 'action' => $action, 'scope' => $scope, 'name' => $name, 'operation_id' => $operation_id ?? 'native-write-' . bin2hex( random_bytes( 8 ) ) );
	if ( 'delete' === $action ) { $input['confirm'] = true; } else { $input['value_source'] = wpai199nw_source( $value ); }
	return $input;
}
function wpai199nw_grants( $write = true, $delete = true ) {
	$settings = new Settings();
	$flags = $settings->all();
	$flags[ Settings::GROUP_NATIVE_OPTIONS_READ ] = 1;
	$flags[ Settings::GROUP_NATIVE_OPTIONS_WRITE ] = $write ? 1 : 0;
	$flags[ Settings::GROUP_SITE_CONFIG ] = 1;
	$flags[ Settings::GROUP_USERS_DESTRUCTIVE ] = $delete ? 1 : 0;
	update_option( Settings::OPTION_NAME, $settings->sanitize( $flags ), false );
	return $flags;
}
function wpai199nw_receipt( $result, $message = 'Native mutation journal result failed.' ) {
	$result = wpai199n_ok( $result, $message );
	wpai199n_assert( isset( $result['state'], $result['operation_ref'], $result['historical'] ) && is_array( $result['receipt'] ?? null ), 'Mutation result omitted its bounded historical journal wrapper.' );
	$receipt = $result['receipt'];
	wpai199n_assert( 'wpai-native-option-operation-v1' === ( $receipt['format'] ?? null ) && 'native_options_write' === ( $receipt['purpose'] ?? null ), 'Mutation result changed its private journal format/purpose.' );
	wpai199n_assert( in_array( $receipt['outcome'] ?? '', array( 'not_dispatched', 'native_call_returned', 'unknown' ), true ) && array_key_exists( 'native_return', $receipt ) && ( is_bool( $receipt['native_return'] ) || null === $receipt['native_return'] ) && is_bool( $receipt['callback_threw'] ?? null ), 'Mutation receipt conflated dispatch, return or callback outcomes.' );
	return $result;
}
function wpai199nw_returned( $result, $native_return ) {
	$result = wpai199nw_receipt( $result );
	wpai199n_assert( 'native_call_returned' === $result['receipt']['outcome'] && $native_return === $result['receipt']['native_return'] && false === $result['receipt']['callback_threw'], 'Native returned true/false was replaced by a transactional effect claim.' );
	return $result;
}
function wpai199nw_status( $operation_id, $scope = 'site' ) {
	return wpai199nw_call( array( 'action' => 'status', 'scope' => $scope, 'operation_id' => $operation_id ) );
}
function wpai199nw_hash( $name, $scope = 'site' ) {
	$value = 'network' === $scope ? get_network_option( get_current_network_id(), $name ) : get_option( $name );
	$encoded = wpai199n_ok( Native_Option_Value::encode( $value, 16 * 1024 * 1024 ), 'Cannot encode current native observed value.' );
	return $encoded['sha256'];
}
function wpai199nw_stage( $document, $scope = 'site', $caller = null ) {
	$call = null === $caller ? 'wpai199nw_call' : $caller;
	$open = wpai199n_ok( $call( array( 'action' => 'input_begin', 'scope' => $scope, 'total_bytes' => strlen( $document ), 'sha256' => hash( 'sha256', $document ) ) ), 'Cannot begin inert native value input.' );
	$id = $open['transfer_id'];
	wpai199n_assert( is_int( $open['chunk_bytes'] ) && $open['chunk_bytes'] > 0 && 0 === $open['received_bytes'], 'Inert input omitted its finite chunk/progress receipt.' );
	$offset = 0;
	while ( $offset < strlen( $document ) ) {
		$piece = substr( $document, $offset, min( 32768, $open['chunk_bytes'] ) );
		$next = wpai199n_ok( $call( array( 'action' => 'input_append', 'scope' => $scope, 'transfer_id' => $id, 'offset' => $offset, 'base64' => base64_encode( $piece ) ) ), 'Cannot append exact native input window.' );
		$offset += strlen( $piece );
		wpai199n_assert( $offset === $next['received_bytes'], 'Inert input append lost byte offsets.' );
	}
	$verified = wpai199n_ok( $call( array( 'action' => 'input_verify', 'scope' => $scope, 'transfer_id' => $id ) ), 'Cannot verify complete native input bytes.' );
	wpai199n_assert( 'verified' === $verified['state'] && strlen( $document ) === $verified['total_bytes'] && hash( 'sha256', $document ) === $verified['sha256'], 'Verified native input changed full size/SHA.' );
	return array( 'transfer_id' => $id, 'sha256' => $verified['sha256'], 'total_bytes' => $verified['total_bytes'] );
}
function wpai199nw_journals_before() {
	global $wpdb;
	return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'wpai_native_option_operation_' ) . '%' ) );
}
function wpai199nw_cleanup_journals( array $before ) {
	foreach ( array_diff( wpai199nw_journals_before(), $before ) as $name ) {
		wpai199n_assert( 1 === preg_match( '/^wpai_native_option_operation_[a-f0-9]{64}$/D', $name ), 'Unexpected journal cleanup identity.' );
		$raw = get_option( $name );
		$receipt = is_string( $raw ) ? json_decode( $raw, true ) : null;
		wpai199n_assert( is_array( $receipt ) && 'native_options_write' === ( $receipt['purpose'] ?? null ), 'Refusing cleanup of another receipt purpose.' );
		delete_option( $name );
	}
}
function wpai199nw_public_private( $result, array $secrets ) {
	$encoded = is_wp_error( $result ) ? wp_json_encode( array( $result->get_error_code(), $result->get_error_message(), $result->get_error_data() ) ) : wp_json_encode( $result );
	foreach ( $secrets as $secret ) { wpai199n_assert( ! str_contains( $encoded, $secret ), 'Public native error/activity leaked raw option/provider bytes.' ); }
}
