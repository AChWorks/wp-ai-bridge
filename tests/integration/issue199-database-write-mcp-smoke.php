<?php
/**
 * Authenticated canonical OAuth/MCP typed writes with Native Abilities off.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue134-review-helper.php';
require_once __DIR__ . '/issue199-database-write-helper.php';

function wpai199wmcp_call( $token, $session, array $input ) {
	wp_set_current_user( 0 );
	return wpai134_review_call( $token, $session, 'wp-ai-bridge/database-write', $input );
}
function wpai199wmcp_data( $response ) {
	wpai199w_assert( true === ( $response['result']['structuredContent']['success'] ?? false ), 'Authenticated typed-write MCP invocation failed: ' . wp_json_encode( $response['result'] ?? array() ) );
	return $response['result']['structuredContent']['data'];
}
function wpai199wmcp_denied( $response, $message ) {
	wpai199w_assert( true === ( $response['result']['isError'] ?? false ), $message );
}
function wpai199wmcp_close( $token, $session ) {
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'DELETE', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Mcp-Session-Id', $session );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	return rest_do_request( $request );
}

global $wpdb;
$actor = get_current_user_id();
$ordinary = get_option( Settings::OPTION_NAME, false );
$siteurl = get_option( 'siteurl' );
$home = get_option( 'home' );
$database = get_option( Settings::DATABASE_OPTION_NAME, false );
$log = get_option( Mutation_Log::OPTION_NAME, false );
$receipts = wpai199w_receipts_before();
$table = $wpdb->prefix . 'wpai199w_mcp_' . bin2hex( random_bytes( 5 ) );
$created = false;
$token = '';
$session = '';
$store = null;
$stage_id = '';

try {
	wpai199w_assert( current_user_can( 'manage_options' ), 'MCP typed-write fixture requires its disposable administrator.' );
	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, `0` LONGBLOB NULL, `01` LONGTEXT NULL) ENGINE=InnoDB', $table ) ), 'Cannot create authenticated typed-write fixture.' );
	$created = true;
	update_option( 'siteurl', set_url_scheme( $siteurl, 'https' ) );
	update_option( 'home', set_url_scheme( $home, 'https' ) );
	$settings = new Settings();
	$settings->register();
	$off = $settings->defaults();
	$off[ Settings::GROUP_NATIVE_ABILITIES ] = 0;
	update_option( Settings::OPTION_NAME, $off, false );
	$grants = wpai199w_grants( false );
	$grants[ Settings::GROUP_DATABASE_WRITE ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	list( $token, $session, $store ) = wpai134_review_connect( $actor );
	wpai199wmcp_denied( wpai199wmcp_call( $token, $session, array( 'action' => 'describe', 'table' => $table ) ), 'Read-only OAuth connection obtained write introspection.' );
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_DATABASE_WRITE ] = 1;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	$description = wpai199wmcp_data( wpai199wmcp_call( $token, $session, array( 'action' => 'describe', 'table' => $table ) ) );
	wpai199w_assert( array( 'id' ) === $description['primary_key'], 'Bridge-owned write Ability did not cross official MCP with Native Abilities off.' );
	$bytes = "\0\xffMCPسلام'\\";
	$request = array( 'action' => 'execute', 'operation_id' => 'write-mcp-' . bin2hex( random_bytes( 6 ) ), 'operations' => array( array( 'action' => 'insert', 'table' => $table, 'expected_schema_hash' => $description['schema_hash'], 'values' => (object) array( 'id' => base64_encode( '18446744073709551614' ), '0' => base64_encode( $bytes ), '01' => null ) ) ) );
	$result = wpai199wmcp_data( wpai199wmcp_call( $token, $session, $request ) );
	wpai199w_assert( 'committed' === $result['status'] && false === $result['historical'], 'First MCP write did not return current committed evidence.' );
	wpai199w_assert( $bytes === wpai199w_bytes( $table, '0', '18446744073709551614' ) && null === wpai199w_bytes( $table, '01', '18446744073709551614' ), 'Authenticated numeric columns/binary/NULL lost wire fidelity.' );
	$replay = wpai199wmcp_data( wpai199wmcp_call( $token, $session, $request ) );
	wpai199w_assert( true === $replay['historical'] && $result['operation_ref'] === $replay['operation_ref'] && 1 === wpai199w_count( $table, '18446744073709551614' ), 'Authenticated same-ID replay repeated effects or changed operation identity.' );

	$stage_bytes = str_repeat( "\0\xffسلام", 2000 );
	$open = wpai199wmcp_data( wpai199wmcp_call( $token, $session, array( 'action' => 'stage_open', 'total_bytes' => strlen( $stage_bytes ), 'sha256' => hash( 'sha256', $stage_bytes ) ) ) );
	$stage_id = $open['transfer_id'];
	$offset = 0;
	while ( $offset < strlen( $stage_bytes ) ) {
		$piece = substr( $stage_bytes, $offset, min( 8192, $open['max_chunk_bytes'] ) );
		wpai199wmcp_data( wpai199wmcp_call( $token, $session, array( 'action' => 'stage_append', 'transfer_id' => $stage_id, 'offset' => $offset, 'content_base64' => base64_encode( $piece ) ) ) );
		$offset += strlen( $piece );
	}
	wpai199wmcp_data( wpai199wmcp_call( $token, $session, array( 'action' => 'stage_verify', 'transfer_id' => $stage_id ) ) );
	( new OAuth_Server( $store ) )->authenticate_mcp_request( new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE ) );
	wp_set_current_user( $actor );
	wpai199w_error( wpai199w_call( array( 'action' => 'stage_status', 'transfer_id' => $stage_id ) ), 'A native client inherited a different OAuth client private source.' );
	$state = wpai199wmcp_data( wpai199wmcp_call( $token, $session, array( 'action' => 'row_state', 'table' => $table, 'key' => array( 'id' => base64_encode( '18446744073709551614' ) ) ) ) );
	$update = array( 'action' => 'execute', 'operation_id' => 'write-mcp-stage-' . bin2hex( random_bytes( 6 ) ), 'operations' => array( array( 'action' => 'update', 'table' => $table, 'expected_schema_hash' => $description['schema_hash'], 'key' => array( 'id' => base64_encode( '18446744073709551614' ) ), 'expected_state_hash' => $state['state_hash'], 'values' => (object) array( '0' => (object) array( 'transfer_id' => $stage_id, 'total_bytes' => strlen( $stage_bytes ), 'sha256' => hash( 'sha256', $stage_bytes ) ), '01' => base64_encode( 'متن دقیق' ) ) ) ) );
	wpai199wmcp_data( wpai199wmcp_call( $token, $session, $update ) );
	wpai199w_assert( $stage_bytes === wpai199w_bytes( $table, '0', '18446744073709551614' ) && 'متن دقیق' === wpai199w_bytes( $table, '01', '18446744073709551614' ), 'Authenticated staged exact bytes changed.' );
	wpai199wmcp_data( wpai199wmcp_call( $token, $session, array( 'action' => 'stage_release', 'transfer_id' => $stage_id ) ) );
	$stage_id = '';
	wpai199wmcp_data( wpai199wmcp_call( $token, $session, $update ) );
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_DATABASE_WRITE ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199wmcp_denied( wpai199wmcp_call( $token, $session, $update ), 'An existing authenticated MCP session bypassed freshly revoked write consent.' );
	wpai199w_assert( $stage_bytes === wpai199w_bytes( $table, '0', '18446744073709551614' ), 'Revoked replay changed committed bytes.' );
	$closed = wpai199wmcp_close( $token, $session );
	wpai199w_assert( in_array( $closed->get_status(), array( 200, 204 ), true ), 'Authenticated fixture session did not terminate.' );
	$session = '';
	wpai199w_assert( $store->revoke( $token ), 'Fixture access token did not revoke.' );
	wp_set_current_user( 0 );
	$revoked_request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$revoked_request->set_header( 'Authorization', 'Bearer ' . $token );
	$revoked_request->set_header( 'Content-Type', 'application/json' );
	$revoked_request->set_body( '{"jsonrpc":"2.0","id":199,"method":"tools/list","params":{}}' );
	wpai199w_assert( 401 === rest_do_request( $revoked_request )->get_status(), 'A revoked token still authenticated a database-capable request.' );
	$token = '';
	echo "PASS: Issue #199 canonical authenticated OAuth/MCP typed write/staging/replay/revocation with Native Abilities off.\n";
} finally {
	if ( '' !== $stage_id && '' !== $token && '' !== $session ) {
		wp_set_current_user( $actor );
		update_option( Settings::DATABASE_OPTION_NAME, wpai199w_grants(), false );
		wpai199wmcp_call( $token, $session, array( 'action' => 'stage_cancel', 'transfer_id' => $stage_id ) );
	}
	if ( '' !== $token && '' !== $session ) {
		wpai199wmcp_close( $token, $session );
	}
	if ( '' !== $token && null !== $store ) {
		$store->revoke( $token );
	}
	wp_set_current_user( $actor );
	if ( $created ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	}
	wpai199w_restore_receipts( $receipts );
	wpai199w_restore_option( 'siteurl', $siteurl );
	wpai199w_restore_option( 'home', $home );
	wpai199w_restore_option( Settings::OPTION_NAME, $ordinary );
	wpai199w_restore_option( Settings::DATABASE_OPTION_NAME, $database );
	wpai199w_restore_option( Mutation_Log::OPTION_NAME, $log );
}
