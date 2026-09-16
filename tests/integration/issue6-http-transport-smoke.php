<?php
/**
 * Live WordPress REST-dispatch smoke for MCP Adapter v0.6.1 HTTP session behavior.
 *
 * Run with:
 * wp eval-file tests/integration/issue6-http-transport-smoke.php --user=<administrator>
 *
 * @package WP_Native_Builder_Bridge
 */

function wpai_issue6_http_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue6_http_data( $response ) {
	return json_decode( wp_json_encode( $response->get_data() ), true );
}

function wpai_issue6_http_request( $method, array $payload = array(), $session_id = '' ) {
	$request = new WP_REST_Request( $method, '/mcp/mcp-adapter-default-server' );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	if ( 'POST' === $method ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );
	}
	if ( '' !== $session_id ) {
		$request->set_header( 'Mcp-Session-Id', $session_id );
	}
	return rest_do_request( $request );
}

$authenticated_user_id = get_current_user_id();
wpai_issue6_http_assert( $authenticated_user_id > 0, 'Run this smoke as an authenticated WordPress user.' );

$initialize = array(
	'jsonrpc' => '2.0',
	'id'      => 1,
	'method'  => 'initialize',
	'params'  => array(
		'protocolVersion' => '2025-11-25',
		'capabilities'    => (object) array(),
		'clientInfo'      => array(
			'name'    => 'wp-ai-bridge-http-smoke',
			'version' => '1.0.0',
		),
	),
);

// Transport authentication is independent from individual Ability permissions.
wp_set_current_user( 0 );
$unauthorized = wpai_issue6_http_request( 'POST', $initialize );
wpai_issue6_http_assert( 401 === $unauthorized->get_status(), 'Unauthenticated initialize did not return HTTP 401.' );
$unauthorized_data = wpai_issue6_http_data( $unauthorized );
wpai_issue6_http_assert( 'rest_forbidden' === ( $unauthorized_data['code'] ?? '' ), 'Live REST route did not return the current WordPress permission envelope for unauthenticated access.' );

wp_set_current_user( $authenticated_user_id );
$before_sessions = class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' )
	? \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $authenticated_user_id )
	: array();
$initialized = wpai_issue6_http_request( 'POST', $initialize );
wpai_issue6_http_assert( 200 === $initialized->get_status(), 'Authenticated initialize did not return HTTP 200.' );
$initialized_data = wpai_issue6_http_data( $initialized );
wpai_issue6_http_assert( '2025-11-25' === ( $initialized_data['result']['protocolVersion'] ?? '' ), 'MCP Adapter did not negotiate the 2025-11-25 protocol.' );
$headers    = $initialized->get_headers();
$session_id = isset( $headers['Mcp-Session-Id'] ) ? (string) $headers['Mcp-Session-Id'] : '';
if ( '' === $session_id && class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ) {
	$after_sessions = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $authenticated_user_id );
	$new_sessions   = array_diff_key( $after_sessions, $before_sessions );
	$session_id     = (string) array_key_first( $new_sessions );
}
wpai_issue6_http_assert( '' !== $session_id, 'Initialize did not create an MCP session.' );

$missing_session = wpai_issue6_http_request(
	'POST',
	array(
		'jsonrpc' => '2.0',
		'id'      => 2,
		'method'  => 'tools/list',
		'params'  => (object) array(),
	)
);
wpai_issue6_http_assert( 400 === $missing_session->get_status(), 'Missing session header did not return HTTP 400.' );
$missing_data = wpai_issue6_http_data( $missing_session );
wpai_issue6_http_assert( -32600 === ( $missing_data['error']['code'] ?? null ), 'Missing session header did not return MCP invalid-request code -32600.' );

$notification = wpai_issue6_http_request(
	'POST',
	array(
		'jsonrpc' => '2.0',
		'method'  => 'notifications/initialized',
	),
	$session_id
);
wpai_issue6_http_assert( 202 === $notification->get_status(), 'Initialized notification did not return HTTP 202.' );

$tools_response = wpai_issue6_http_request(
	'POST',
	array(
		'jsonrpc' => '2.0',
		'id'      => 3,
		'method'  => 'tools/list',
		'params'  => (object) array(),
	),
	$session_id
);
wpai_issue6_http_assert( 200 === $tools_response->get_status(), 'Authenticated tools/list did not return HTTP 200.' );
$tools_data  = wpai_issue6_http_data( $tools_response );
$tool_names = array_column( $tools_data['result']['tools'] ?? array(), 'name' );
sort( $tool_names );
wpai_issue6_http_assert(
	array(
		'mcp-adapter-discover-abilities',
		'mcp-adapter-execute-ability',
		'mcp-adapter-get-ability-info',
	) === $tool_names,
	'Default MCP server did not expose exactly the three expected layered tools.'
);

$invalid_session = wpai_issue6_http_request(
	'POST',
	array(
		'jsonrpc' => '2.0',
		'id'      => 4,
		'method'  => 'tools/list',
		'params'  => (object) array(),
	),
	'00000000-0000-4000-8000-000000000000'
);
wpai_issue6_http_assert( 404 === $invalid_session->get_status(), 'Invalid session did not return the live Adapter HTTP 404 session-not-found envelope.' );
$invalid_data = wpai_issue6_http_data( $invalid_session );
wpai_issue6_http_assert( -32005 === ( $invalid_data['error']['code'] ?? null ), 'Invalid session did not return the live Adapter session-not-found code -32005.' );

$get_response = wpai_issue6_http_request( 'GET', array(), $session_id );
wpai_issue6_http_assert( 405 === $get_response->get_status(), 'GET did not return HTTP 405 while SSE remains unsupported.' );

$delete_response = wpai_issue6_http_request( 'DELETE', array(), $session_id );
wpai_issue6_http_assert( in_array( $delete_response->get_status(), array( 200, 204 ), true ), 'DELETE did not terminate the HTTP MCP session.' );

$expired_session = wpai_issue6_http_request(
	'POST',
	array(
		'jsonrpc' => '2.0',
		'id'      => 5,
		'method'  => 'tools/list',
		'params'  => (object) array(),
	),
	$session_id
);
$expired_data = wpai_issue6_http_data( $expired_session );
wpai_issue6_http_assert( 404 === $expired_session->get_status(), 'Terminated session did not return HTTP 404 on reuse.' );
wpai_issue6_http_assert( -32005 === ( $expired_data['error']['code'] ?? null ), 'Terminated session remained usable.' );

wp_set_current_user( $authenticated_user_id );
echo "PASS: Issue #6 MCP HTTP transport/session smoke.\n";
