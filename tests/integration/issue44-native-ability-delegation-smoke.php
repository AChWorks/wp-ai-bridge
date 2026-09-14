<?php
/** Live WordPress Issue #44 native Ability delegation smoke. */

use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Auth\OAuth_Store;
use WP_Native_Builder_Bridge\Support\Native_Ability_Delegation;
use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue44_live_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function wpnb_issue44_live_data( $response ) {
	return json_decode( wp_json_encode( $response->get_data() ), true );
}
function wpnb_issue44_live_structured( array $response_data ) {
	if ( isset( $response_data['result']['structuredContent'] ) && is_array( $response_data['result']['structuredContent'] ) ) {
		return $response_data['result']['structuredContent'];
	}
	$text    = $response_data['result']['content'][0]['text'] ?? '';
	$decoded = is_string( $text ) ? json_decode( $text, true ) : null;
	return is_array( $decoded ) ? $decoded : array();
}
function wpnb_issue44_live_request( $route, $method, $access_token, array $payload = array(), $session_id = '' ) {
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( $method, $route );
	$request->set_header( 'Authorization', 'Bearer ' . $access_token );
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
function wpnb_issue44_live_session( $route, $token, $user_id ) {
	$before = class_exists( '\\WP\\MCP\\Transport\\Infrastructure\\SessionManager' )
		? \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $user_id )
		: array();
	$response = wpnb_issue44_live_request(
		$route,
		'POST',
		$token,
		array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => (object) array(),
				'clientInfo'      => array( 'name' => 'wp-ai-bridge-issue44', 'version' => '1.0.0' ),
			),
		)
	);
	wpnb_issue44_live_assert( 200 === $response->get_status(), 'Issue #44 MCP initialize failed for ' . $route );
	$headers = $response->get_headers();
	$session = isset( $headers['Mcp-Session-Id'] ) ? (string) $headers['Mcp-Session-Id'] : '';
	if ( '' === $session && class_exists( '\\WP\\MCP\\Transport\\Infrastructure\\SessionManager' ) ) {
		$after   = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $user_id );
		$created = array_diff_key( $after, $before );
		$session = (string) array_key_first( $created );
	}
	wpnb_issue44_live_assert( '' !== $session, 'Issue #44 MCP session was not created for ' . $route );
	$notification = wpnb_issue44_live_request(
		$route,
		'POST',
		$token,
		array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ),
		$session
	);
	wpnb_issue44_live_assert( 202 === $notification->get_status(), 'Issue #44 initialized notification failed.' );
	return $session;
}
function wpnb_issue44_live_call( $route, $token, $session, $ability_name, $id ) {
	$response = wpnb_issue44_live_request(
		$route,
		'POST',
		$token,
		array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'mcp-adapter-execute-ability',
				'arguments' => array( 'ability_name' => $ability_name, 'parameters' => (object) array() ),
			),
		),
		$session
	);
	wpnb_issue44_live_assert( 200 === $response->get_status(), 'Issue #44 tools/call transport failed for ' . $ability_name );
	return wpnb_issue44_live_data( $response );
}
function wpnb_issue44_live_error_text( array $data ) {
	return (string) ( $data['result']['content'][0]['text'] ?? '' );
}

$user_id = get_current_user_id();
wpnb_issue44_live_assert( $user_id > 0, 'Run Issue #44 smoke as an authenticated administrator.' );
$settings        = new Settings();
$before_settings = get_option( Settings::OPTION_NAME, array() );
$current         = is_array( $before_settings ) ? $before_settings : array();
$current[ Settings::GROUP_NATIVE_ABILITIES ] = 0;
update_option( Settings::OPTION_NAME, $current, false );

$provider = wp_get_ability( 'issue44/provider-allowed' );
wpnb_issue44_live_assert( $provider instanceof WP_AI_Bridge_Issue44_Custom_Ability, 'Late custom provider Ability was not registered.' );
wpnb_issue44_live_assert( ! Native_Ability_Delegation::is_bridge_owned_ability( $provider ), 'Provider Ability was incorrectly marked Bridge-owned.' );
$foreign = wp_get_ability( 'wp-native-builder/foreign-fixture' );
wpnb_issue44_live_assert( $foreign instanceof WP_Ability && ! Native_Ability_Delegation::is_bridge_owned_ability( $foreign ), 'Foreign historical-prefix Ability was incorrectly marked Bridge-owned.' );
$bridge_info = wp_get_ability( 'wp-native-builder/bridge-info' );
wpnb_issue44_live_assert( $bridge_info instanceof WP_Ability && Native_Ability_Delegation::is_bridge_owned_ability( $bridge_info ), 'Actual Bridge Ability is missing ownership marker.' );

$direct = $provider->execute( array() );
wpnb_issue44_live_assert( ! is_wp_error( $direct ) && true === ( $direct['executed'] ?? false ), 'Native direct Ability execution changed while Bridge delegation was disabled.' );

$catalog        = wp_get_ability( 'wp-native-builder/abilities-read' );
$catalog_result = $catalog->execute( array( 'action' => 'list', 'search' => 'issue44', 'page' => 1, 'per_page' => 100 ) );
wpnb_issue44_live_assert( ! is_wp_error( $catalog_result ) && 'not_evaluated' === $catalog_result['execution_permission'], 'Ability catalog evaluated target permission.' );
$delegation = array();
foreach ( $catalog_result['items'] as $item ) {
	$delegation[ $item['name'] ] = $item['bridge_delegation'];
}
wpnb_issue44_live_assert( 'native_abilities' === ( $delegation['issue44/provider-allowed'] ?? '' ), 'Catalog did not expose native_abilities requirement.' );

$store     = new OAuth_Store();
$oauth     = new OAuth_Server( $store );
$resources = array(
	OAuth_Server::MCP_REQUEST_ROUTE         => $oauth->mcp_endpoint_url(),
	OAuth_Server::LEGACY_MCP_REQUEST_ROUTE => $oauth->legacy_mcp_endpoint_url(),
);
$id = 10;
foreach ( $resources as $route => $resource ) {
	wp_set_current_user( $user_id );
	$token = $store->issue(
		OAuth_Store::TYPE_ACCESS,
		array(
			'user_id'   => $user_id,
			'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
			'resource'  => $resource,
			'scope'     => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
		),
		120
	);
	$session = wpnb_issue44_live_session( $route, $token, $user_id );

	$denied = wpnb_issue44_live_call( $route, $token, $session, 'issue44/provider-allowed', ++$id );
	wpnb_issue44_live_assert( true === ( $denied['result']['isError'] ?? false ), 'Native provider executed while Native Abilities was disabled on ' . $route );
	wpnb_issue44_live_assert( false !== strpos( wpnb_issue44_live_error_text( $denied ), 'Native Abilities access is disabled' ), 'Native delegation denial was not actionable on ' . $route );

	$forged = wpnb_issue44_live_call( $route, $token, $session, 'wp-native-builder/foreign-fixture', ++$id );
	wpnb_issue44_live_assert( true === ( $forged['result']['isError'] ?? false ), 'Historical Bridge prefix bypassed Native Abilities on ' . $route );

	$bridge = wpnb_issue44_live_call( $route, $token, $session, 'wp-native-builder/bridge-info', ++$id );
	wpnb_issue44_live_assert( false === ( $bridge['result']['isError'] ?? false ), 'Bridge-owned Ability was incorrectly blocked by Native Abilities on ' . $route );
	$bridge_structured = wpnb_issue44_live_structured( $bridge );
	wpnb_issue44_live_assert( true === ( $bridge_structured['success'] ?? false ), 'Bridge-owned execute result failed on ' . $route );

	$current = $settings->all();
	$current[ Settings::GROUP_NATIVE_ABILITIES ] = 1;
	update_option( Settings::OPTION_NAME, $current, false );
	$allowed = wpnb_issue44_live_call( $route, $token, $session, 'issue44/provider-allowed', ++$id );
	wpnb_issue44_live_assert( false === ( $allowed['result']['isError'] ?? false ), 'Enabled Native Abilities did not allow authorized provider on ' . $route );
	$allowed_structured = wpnb_issue44_live_structured( $allowed );
	wpnb_issue44_live_assert( true === ( $allowed_structured['success'] ?? false ) && true === ( $allowed_structured['data']['executed'] ?? false ), 'Authorized provider execution result was not preserved.' );

	$provider_denied = wpnb_issue44_live_call( $route, $token, $session, 'issue44/provider-denied', ++$id );
	wpnb_issue44_live_assert( true === ( $provider_denied['result']['isError'] ?? false ), 'Bridge widened provider-native permission denial.' );

	$current[ Settings::GROUP_NATIVE_ABILITIES ] = 0;
	update_option( Settings::OPTION_NAME, $current, false );
	$revoked = wpnb_issue44_live_call( $route, $token, $session, 'issue44/provider-allowed', ++$id );
	wpnb_issue44_live_assert( true === ( $revoked['result']['isError'] ?? false ), 'Native Abilities revocation did not take effect immediately.' );

	$delete = wpnb_issue44_live_request( $route, 'DELETE', $token, array(), $session );
	wpnb_issue44_live_assert( in_array( $delete->get_status(), array( 200, 204 ), true ), 'Issue #44 session termination failed.' );
	wpnb_issue44_live_assert( $store->revoke( $token ), 'Issue #44 access token could not be revoked.' );
}

wp_set_current_user( $user_id );
update_option( Settings::OPTION_NAME, $before_settings, false );
delete_option( 'wp_ai_bridge_issue44_provider_executed' );
echo "PASS: Issue #44 native Ability delegation across canonical and legacy Bridge MCP routes.\n";
