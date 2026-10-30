<?php
/**
 * Canonical HTTPS OAuth/MCP native mutations and inert input lifecycle.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue134-review-helper.php';
require_once __DIR__ . '/issue199-native-option-write-helper.php';

function wpai199nwmcp_call( $token, $session, array $input ) {
	wp_set_current_user( 0 );
	$result = wpai134_review_call( $token, $session, 'wp-ai-bridge/native-options-write', $input );
	wpai199n_assert( strlen( wp_json_encode( $result ) ) <= Bounded_Payload::response_limit(), 'Actual duplicated MCP mutation receipt exceeded current response policy.' );
	return $result;
}
function wpai199nwmcp_data( $result ) {
	wpai199n_assert( true === ( $result['result']['structuredContent']['success'] ?? false ), 'Authenticated native mutation MCP call failed.' );
	return $result['result']['structuredContent']['data'];
}
function wpai199nwmcp_denied( $result, $message ) { wpai199n_assert( true === ( $result['result']['isError'] ?? false ), $message ); }
function wpai199nwmcp_close( $token, $session ) {
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'DELETE', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Mcp-Session-Id', $session );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	return rest_do_request( $request );
}

$actor = get_current_user_id();
$ordinary = get_option( Settings::OPTION_NAME, null );
$log = get_option( Mutation_Log::OPTION_NAME, null );
$home = get_option( 'home' );
$siteurl = get_option( 'siteurl' );
$journals = wpai199nw_journals_before();
$name = 'native199nwmcp_' . bin2hex( random_bytes( 5 ) );
$token = '';
$session = '';
$store = null;
$input_id = '';
$direct_input_id = '';

try {
	wpai199n_assert( current_user_can( 'manage_options' ) && '' === OAuth_Server::authenticated_mcp_client_id(), 'MCP fixture needs its native administrator without an existing client context.' );
	update_option( 'home', set_url_scheme( $home, 'https' ) );
	update_option( 'siteurl', set_url_scheme( $siteurl, 'https' ) );
	$settings = new Settings();
	$settings->register();
	$flags = wpai199nw_grants();
	$flags[ Settings::GROUP_NATIVE_ABILITIES ] = 0;
	update_option( Settings::OPTION_NAME, $settings->sanitize( $flags ), false );
	$direct = wpai199nw_request( 'add', $name . '_direct', 'native client identity' );
	wpai199nw_returned( wpai199nw_call( $direct ), true );
	$direct_input = wpai199nw_stage( wpai199nw_source( 'native client source' )['value_json'] );
	$direct_input_id = $direct_input['transfer_id'];
	wpai199nw_grants( false );
	list( $token, $session, $store ) = wpai134_review_connect( $actor );
	$request = wpai199nw_request( 'add', $name, "MCP native\0سلام" );
	wpai199nwmcp_denied( wpai199nwmcp_call( $token, $session, $request ), 'Existing OAuth connection obtained native write without current consent.' );
	wp_set_current_user( $actor );
	wpai199nw_grants();
	wpai199n_assert( ! $settings->is_enabled( Settings::GROUP_NATIVE_ABILITIES ), 'MCP fixture accidentally enabled delegated Native Abilities.' );
	$first = wpai199nw_returned( wpai199nwmcp_data( wpai199nwmcp_call( $token, $session, $request ) ), true );
	wpai199n_assert( "MCP native\0سلام" === get_option( $name ) && false === $first['historical'], 'Bridge-owned native write did not cross official MCP with Native Abilities off.' );
	$replay = wpai199nw_receipt( wpai199nwmcp_data( wpai199nwmcp_call( $token, $session, $request ) ) );
	wpai199n_assert( true === $replay['historical'] && $first['operation_ref'] === $replay['operation_ref'], 'Authenticated same-ID replay changed native operation identity.' );
	$wrong_client = wpai199nwmcp_data( wpai199nwmcp_call( $token, $session, array( 'action' => 'status', 'scope' => 'site', 'operation_id' => $direct['operation_id'] ) ) );
	wpai199n_assert( 'not_recorded' === $wrong_client['state'] && null === $wrong_client['receipt'], 'Same user OAuth client exposed another native-client receipt/name.' );
	wpai199nwmcp_denied( wpai199nwmcp_call( $token, $session, array( 'action' => 'input_status', 'scope' => 'site', 'transfer_id' => $direct_input_id ) ), 'Another authenticated client accessed a native value source.' );

	$value = str_repeat( "native MCP سلام\0", 4500 );
	$document = wpai199nw_source( $value )['value_json'];
	$caller = static function ( array $input ) use ( $token, $session ) { return wpai199nwmcp_data( wpai199nwmcp_call( $token, $session, $input ) ); };
	$source = wpai199nw_stage( $document, 'site', $caller );
	$input_id = $source['transfer_id'];
	$staged = wpai199nw_request( 'update', $name, '' );
	$staged['value_source'] = $source;
	$written = wpai199nw_returned( wpai199nwmcp_data( wpai199nwmcp_call( $token, $session, $staged ) ), true );
	wpai199n_assert( $value === get_option( $name ) && hash( 'sha256', $document ) === $written['receipt']['observed_after_sha256'], 'Authenticated staged native value/hash changed.' );
	$generic = wpai134_review_transfer( $token, $session, array( 'action' => 'status', 'transfer_id' => $input_id ) );
	wpai199nwmcp_denied( $generic, 'Generic MCP private transfer consumed native-write purpose.' );
	wp_set_current_user( $actor );
	wpai199nw_grants( false );
	wpai199nwmcp_denied( wpai199nwmcp_call( $token, $session, $staged ), 'Existing OAuth session bypassed current native-write revocation.' );
	wpai199nwmcp_denied( wpai199nwmcp_call( $token, $session, array( 'action' => 'input_status', 'scope' => 'site', 'transfer_id' => $input_id ) ), 'Revoked write consent exposed inert input.' );
	$historical = wpai199nw_receipt( wpai199nwmcp_data( wpai199nwmcp_call( $token, $session, array( 'action' => 'status', 'scope' => 'site', 'operation_id' => $staged['operation_id'] ) ) ) );
	wpai199n_assert( true === $historical['historical'] && $value === get_option( $name ), 'Read-authorized historical status resent a native write.' );
	wp_set_current_user( $actor );
	wpai199nw_grants();
	$caller( array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => $input_id ) );
	$input_id = '';
	wpai199n_assert( in_array( wpai199nwmcp_close( $token, $session )->get_status(), array( 200, 204 ), true ), 'Synthetic native-write MCP session did not close.' );
	$session = '';
	wpai199n_assert( $store->revoke( $token ), 'Synthetic native-write OAuth token did not revoke.' );
	wp_set_current_user( 0 );
	$revoked = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$revoked->set_header( 'Authorization', 'Bearer ' . $token );
	$revoked->set_header( 'Content-Type', 'application/json' );
	$revoked->set_body( '{"jsonrpc":"2.0","id":199,"method":"tools/list","params":{}}' );
	wpai199n_assert( 401 === rest_do_request( $revoked )->get_status(), 'Revoked native-write token authenticated canonical MCP.' );
	$token = '';
	echo 'PASS: actual HTTPS OAuth/MCP native writes, staged input, private identity and current revocation (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
} finally {
	wp_set_current_user( $actor );
	wpai199nw_grants();
	if ( '' !== $input_id && '' !== $token && '' !== $session ) { wpai199nwmcp_call( $token, $session, array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => $input_id ) ); }
	if ( '' !== $token && '' !== $session ) { wpai199nwmcp_close( $token, $session ); }
	if ( '' !== $token && null !== $store ) { $store->revoke( $token ); }
	wp_set_current_user( $actor );
	$client = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
	$client->setValue( null, '' );
	if ( '' !== $direct_input_id ) { wpai199nw_call( array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => $direct_input_id ) ); }
	delete_option( $name );
	delete_option( $name . '_direct' );
	wpai199nw_cleanup_journals( $journals );
	wpai199n_restore_option( 'home', $home );
	wpai199n_restore_option( 'siteurl', $siteurl );
	wpai199n_restore_option( Settings::OPTION_NAME, $ordinary, null !== $ordinary );
	wpai199n_restore_option( Mutation_Log::OPTION_NAME, $log, null !== $log );
}
