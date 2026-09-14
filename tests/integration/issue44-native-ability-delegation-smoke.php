<?php
/** Live WordPress Issue #44 native Ability delegation smoke. */

use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Auth\OAuth_Store;
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
function wpnb_issue44_live_delegation( $catalog, $ability_name ) {
	$result = $catalog->execute( array( 'action' => 'get', 'name' => $ability_name ) );
	wpnb_issue44_live_assert( ! is_wp_error( $result ), 'Ability catalog could not inspect ' . $ability_name );
	wpnb_issue44_live_assert( 'not_evaluated' === ( $result['execution_permission'] ?? '' ), 'Ability catalog evaluated target permission for ' . $ability_name );
	return (string) ( $result['items'][0]['bridge_delegation'] ?? '' );
}

$user_id = get_current_user_id();
wpnb_issue44_live_assert( $user_id > 0, 'Run Issue #44 smoke as an authenticated administrator.' );
$settings        = new Settings();
$before_settings = get_option( Settings::OPTION_NAME, array() );
$current         = is_array( $before_settings ) ? $before_settings : array();
$current[ Settings::GROUP_NATIVE_ABILITIES ] = 0;
update_option( Settings::OPTION_NAME, $current, false );

$provider     = wp_get_ability( 'issue44/provider-allowed' );
$foreign      = wp_get_ability( 'wp-native-builder/foreign-fixture' );
$forged_class = wp_get_ability( 'wp-native-builder/forged-class-fixture' );
$bridge_info  = wp_get_ability( 'wp-native-builder/bridge-info' );
wpnb_issue44_live_assert( $provider instanceof WP_AI_Bridge_Issue44_Custom_Ability, 'Late custom provider Ability was not registered.' );
wpnb_issue44_live_assert( $foreign instanceof WP_Ability, 'Historical-prefix provider fixture was not registered.' );
wpnb_issue44_live_assert( $forged_class instanceof WP_AI_Bridge_Issue44_Forged_Meta_Ability, 'Forged custom ability_class fixture was not registered.' );
wpnb_issue44_live_assert( $bridge_info instanceof WP_Ability, 'Bridge-owned Ability fixture was not registered.' );

$direct = $provider->execute( array() );
wpnb_issue44_live_assert( ! is_wp_error( $direct ) && true === ( $direct['executed'] ?? false ), 'Native direct Ability execution changed while Bridge delegation was disabled.' );
$direct_forged = $forged_class->execute( array() );
wpnb_issue44_live_assert( ! is_wp_error( $direct_forged ) && true === ( $direct_forged['executed'] ?? false ), 'Forged custom class changed ordinary direct Ability execution.' );

$catalog = wp_get_ability( 'wp-native-builder/abilities-read' );
wpnb_issue44_live_assert( $catalog instanceof WP_Ability, 'Bridge Ability catalog was not registered.' );
wpnb_issue44_live_assert( 'native_abilities' === wpnb_issue44_live_delegation( $catalog, 'issue44/provider-allowed' ), 'Catalog did not expose native_abilities for custom provider.' );
wpnb_issue44_live_assert( 'native_abilities' === wpnb_issue44_live_delegation( $catalog, 'wp-native-builder/foreign-fixture' ), 'Registration-meta forgery changed discovery ownership.' );
wpnb_issue44_live_assert( 'native_abilities' === wpnb_issue44_live_delegation( $catalog, 'wp-native-builder/forged-class-fixture' ), 'Custom get_meta() forgery changed discovery ownership.' );
wpnb_issue44_live_assert( 'ability_specific' === wpnb_issue44_live_delegation( $catalog, 'wp-native-builder/bridge-info' ), 'Genuine Bridge Ability did not retain ability-specific discovery policy.' );

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
	wpnb_issue44_live_assert( true === ( $forged['result']['isError'] ?? false ), 'Historical prefix/metadata forgery bypassed Native Abilities on ' . $route );

	$forged_virtual = wpnb_issue44_live_call( $route, $token, $session, 'wp-native-builder/forged-class-fixture', ++$id );
	wpnb_issue44_live_assert( true === ( $forged_virtual['result']['isError'] ?? false ), 'Custom get_meta() ownership forgery bypassed Native Abilities on ' . $route );

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

	$forged_allowed = wpnb_issue44_live_call( $route, $token, $session, 'wp-native-builder/forged-class-fixture', ++$id );
	wpnb_issue44_live_assert( false === ( $forged_allowed['result']['isError'] ?? false ), 'Enabled Native Abilities did not allow provider custom class after native permission.' );
	$forged_structured = wpnb_issue44_live_structured( $forged_allowed );
	wpnb_issue44_live_assert( true === ( $forged_structured['success'] ?? false ) && true === ( $forged_structured['data']['executed'] ?? false ), 'Provider custom class execution result was not preserved.' );

	$provider_denied = wpnb_issue44_live_call( $route, $token, $session, 'issue44/provider-denied', ++$id );
	wpnb_issue44_live_assert( true === ( $provider_denied['result']['isError'] ?? false ), 'Bridge widened provider-native permission denial.' );

	$current[ Settings::GROUP_NATIVE_ABILITIES ] = 0;
	update_option( Settings::OPTION_NAME, $current, false );
	$revoked = wpnb_issue44_live_call( $route, $token, $session, 'issue44/provider-allowed', ++$id );
	wpnb_issue44_live_assert( true === ( $revoked['result']['isError'] ?? false ), 'Native Abilities revocation did not take effect immediately.' );
	$revoked_forged = wpnb_issue44_live_call( $route, $token, $session, 'wp-native-builder/forged-class-fixture', ++$id );
	wpnb_issue44_live_assert( true === ( $revoked_forged['result']['isError'] ?? false ), 'Revocation did not deny provider custom class immediately.' );

	$delete = wpnb_issue44_live_request( $route, 'DELETE', $token, array(), $session );
	wpnb_issue44_live_assert( in_array( $delete->get_status(), array( 200, 204 ), true ), 'Issue #44 session termination failed.' );
	wpnb_issue44_live_assert( $store->revoke( $token ), 'Issue #44 access token could not be revoked.' );
}

wp_set_current_user( $user_id );
update_option( Settings::OPTION_NAME, $before_settings, false );
delete_option( 'wp_ai_bridge_issue44_provider_executed' );
echo "PASS: Issue #44 native Ability delegation across canonical and legacy Bridge MCP routes.\n";
