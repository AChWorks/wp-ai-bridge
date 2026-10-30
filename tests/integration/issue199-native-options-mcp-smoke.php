<?php
/**
 * Synthetic authenticated OAuth -> canonical Bridge REST -> official MCP Adapter.
 *
 * No external transport or production configuration is exercised here.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-native-options-helper.php';
require_once __DIR__ . '/issue134-review-helper.php';

function wpai199nmcp_call( $token, $session, array $input ) {
	wp_set_current_user( 0 );
	$response = wpai134_review_call( $token, $session, 'wp-ai-bridge/native-options-read', $input );
	wpai199n_assert( strlen( wp_json_encode( $response ) ) <= Bounded_Payload::response_limit(), 'Actual duplicated MCP native option result exceeded the current response policy.' );
	return $response;
}
function wpai199nmcp_data( array $response ) {
	wpai199n_assert( true === ( $response['result']['structuredContent']['success'] ?? false ), 'Authenticated native option MCP call failed.' );
	return $response['result']['structuredContent']['data'];
}
function wpai199nmcp_denied( array $response, $message ) {
	wpai199n_assert( true === ( $response['result']['isError'] ?? false ), $message );
}
function wpai199nmcp_close( $token, $session ) {
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'DELETE', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Mcp-Session-Id', $session );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	return rest_do_request( $request );
}

$actor = get_current_user_id();
$original = get_option( Settings::OPTION_NAME, null );
$original_log = get_option( Mutation_Log::OPTION_NAME, null );
$suffix = bin2hex( random_bytes( 5 ) );
$name = 'wpai199nmcp_' . $suffix;
$virtual = $name . '_virtual';
$marker = 'synthetic-mcp-native-secret-' . $suffix;
$bytes = str_repeat( "\xff\x00MCPNativeسلام", 180000 );
$provider_calls = 0;
$filter = static function () use ( &$provider_calls, &$bytes ) { ++$provider_calls; return $bytes; };
$token = '';
$session = '';
$store = null;
$direct_id = '';
$mcp_ids = array();

try {
	wpai199n_assert( current_user_can( 'manage_options' ) && '' === OAuth_Server::authenticated_mcp_client_id(), 'Synthetic MCP fixture must start as a native administrator without an OAuth client context.' );
	$settings = new Settings();
	$settings->register();
	wpai199n_assert( true === add_option( $name, $marker, '', false ), 'Cannot store the synthetic MCP neutral-key option.' );
	add_filter( 'pre_option_' . $virtual, $filter, 10, 0 );
	$flags = $settings->defaults();
	$flags[ Settings::GROUP_NATIVE_ABILITIES ] = 0;
	update_option( Settings::OPTION_NAME, $flags, false );

	// A snapshot created outside OAuth cannot be reopened by the same user
	// through another authenticated client identity.
	wpai199n_grant_site();
	$direct = wpai199n_ok( wpai199n_call( array( 'action' => 'snapshot', 'scope' => 'site', 'name' => $name ) ), 'Cannot prepare same-user native client-binding fixture.' );
	$direct_id = $direct['transfer_id'];
	wpai199n_grant_site( false );
	list( $token, $session, $store ) = wpai134_review_connect( $actor );
	wpai199nmcp_denied( wpai199nmcp_call( $token, $session, array( 'action' => 'read', 'scope' => 'site', 'name' => $name ) ), 'Existing OAuth/MCP connection disclosed a neutral-key secret without native consent.' );
	wp_set_current_user( $actor );
	wpai199n_grant_site();
	wpai199n_assert( ! $settings->is_enabled( Settings::GROUP_NATIVE_ABILITIES ), 'MCP fixture accidentally enabled the delegated Native Abilities group.' );
	$read = wpai199nmcp_data( wpai199nmcp_call( $token, $session, array( 'action' => 'read', 'scope' => 'site', 'name' => $name ) ) );
	wpai199n_same( $marker, wpai199n_decode( $read['value_json'] ), 'Bridge-owned sensitive native read did not cross the authenticated official Adapter with Native Abilities off.' );
	$inventory = wpai199nmcp_data( wpai199nmcp_call( $token, $session, array( 'action' => 'list', 'scope' => 'site', 'name_prefix' => $name, 'limit' => 1 ) ) );
	wpai199n_assert( 1 === count( $inventory['items'] ) && ! str_contains( wp_json_encode( $inventory ), $marker ), 'Authenticated MCP physical inventory disclosed values.' );
	wpai199nmcp_denied( wpai199nmcp_call( $token, $session, array( 'action' => 'snapshot_status', 'scope' => 'site', 'transfer_id' => $direct_id ) ), 'Same user with a different OAuth client context reopened a native snapshot.' );

	$provider_calls = 0;
	$snapshot = wpai199nmcp_data( wpai199nmcp_call( $token, $session, array( 'action' => 'snapshot', 'scope' => 'site', 'name' => $virtual ) ) );
	$mcp_ids[] = $snapshot['transfer_id'];
	wpai199n_assert( 1 === $provider_calls && $snapshot['total_bytes'] > Bounded_Payload::response_limit(), 'MCP snapshot did not capture one complete value beyond inline policy.' );
	$expected = $bytes;
	$bytes = 'mutated after immutable MCP snapshot';
	$caller = static function ( array $input ) use ( $token, $session ) { return wpai199nmcp_data( wpai199nmcp_call( $token, $session, $input ) ); };
	$assembled = wpai199n_collect( $snapshot, 'site', $caller );
	wpai199n_same( $expected, wpai199n_decode( $assembled ), 'Authenticated MCP snapshot reconstruction lost binary native bytes.' );
	wpai199n_assert( 1 === $provider_calls, 'Authenticated continuation reran the native provider.' );
	$explicit = wpai199nmcp_data( wpai199nmcp_call( $token, $session, array( 'action' => 'snapshot_read', 'scope' => 'site', 'transfer_id' => $snapshot['transfer_id'], 'offset' => 7, 'length' => 17 ) ) );
	wpai199n_assert( substr( $assembled, 7, 17 ) === base64_decode( $explicit['base64'], true ) && 24 === $explicit['next_offset'], 'Authenticated optional byte-window length/offset changed bytes.' );
	$generic = wpai134_review_transfer( $token, $session, array( 'action' => 'status', 'transfer_id' => $snapshot['transfer_id'] ) );
	wpai199nmcp_denied( $generic, 'Generic MCP transfer endpoint consumed a native settings purpose.' );

	wp_set_current_user( $actor );
	wpai199n_grant_site( false );
	foreach ( array( 'snapshot_status', 'snapshot_read', 'snapshot_release' ) as $action ) {
		$input = array( 'action' => $action, 'scope' => 'site', 'transfer_id' => $snapshot['transfer_id'] );
		if ( 'snapshot_read' === $action ) { $input['offset'] = 0; }
		wpai199nmcp_denied( wpai199nmcp_call( $token, $session, $input ), 'Current native revocation was bypassed by an existing OAuth/MCP session.' );
	}
	wp_set_current_user( $actor );
	wpai199n_grant_site();
	$released = wpai199nmcp_data( wpai199nmcp_call( $token, $session, array( 'action' => 'snapshot_release', 'scope' => 'site', 'transfer_id' => $snapshot['transfer_id'] ) ) );
	wpai199n_assert( true === $released['released'], 'Authenticated snapshot release did not confirm cleanup.' );
	$mcp_ids = array();
	wpai199nmcp_denied( wpai199nmcp_call( $token, $session, array( 'action' => 'snapshot_status', 'scope' => 'site', 'transfer_id' => $snapshot['transfer_id'] ) ), 'Released native snapshot remained exposed through MCP.' );

	wpai199n_assert( in_array( wpai199nmcp_close( $token, $session )->get_status(), array( 200, 204 ), true ), 'Synthetic native MCP session did not close.' );
	$session = '';
	wpai199n_assert( $store->revoke( $token ), 'Synthetic native MCP token could not be revoked.' );
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( '{"jsonrpc":"2.0","id":99,"method":"tools/list","params":{}}' );
	wpai199n_assert( 401 === rest_do_request( $request )->get_status(), 'Revoked native-options token authenticated a new canonical MCP request.' );
	$token = '';
	echo 'PASS: Issue #199 authenticated OAuth/MCP native options, full snapshot bytes, client binding and revocation (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
} finally {
	wp_set_current_user( $actor );
	wpai199n_grant_site();
	foreach ( $mcp_ids as $id ) {
		if ( '' !== $token && '' !== $session ) { wpai199nmcp_call( $token, $session, array( 'action' => 'snapshot_release', 'scope' => 'site', 'transfer_id' => $id ) ); }
	}
	if ( '' !== $token && '' !== $session ) { wpai199nmcp_close( $token, $session ); }
	if ( '' !== $token && null !== $store ) { $store->revoke( $token ); }
	wp_set_current_user( $actor );
	// Reset only the request-local authenticated identity after all real REST
	// requests. This is fixture cleanup, never an authorization assertion.
	$client = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
	$client->setValue( null, '' );
	if ( '' !== $direct_id ) { wpai199n_call( array( 'action' => 'snapshot_release', 'scope' => 'site', 'transfer_id' => $direct_id ) ); }
	remove_filter( 'pre_option_' . $virtual, $filter, 10 );
	delete_option( $name );
	wpai199n_restore_option( Settings::OPTION_NAME, $original, null !== $original );
	wpai199n_restore_option( Mutation_Log::OPTION_NAME, $original_log, null !== $original_log );
}
