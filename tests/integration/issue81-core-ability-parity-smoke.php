<?php
/**
 * Isolated issue #81 native Core Ability catalog/execute parity test.
 *
 * Reproduces the exact documented read-only Core Ability family through the
 * real WordPress registry, pinned Adapter and authenticated Bridge MCP.
 * Synthetic user/client/WordPress only; never run against a hosted site.
 */
use WP_AI_Bridge\Abilities\Ability_Resolver;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;
use WP_AI_Bridge\Support\Settings;

function wpai81_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function wpai81_mcp_request( $token, array $payload, $session = '' ) {
	// Exercise the actual OAuth/MCP REST boundary, not an already-authenticated
	// direct WP_Ability::execute() shortcut.
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	$request->set_body( wp_json_encode( $payload ) );
	if ( '' !== $session ) {
		$request->set_header( 'Mcp-Session-Id', $session );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	}
	return rest_do_request( $request );
}
function wpai81_mcp_call( $token, $session, $ability_name, array $parameters, $id ) {
	$result = wpai81_mcp_request(
		$token,
		array(
			'jsonrpc' => '2.0',
			'id' => $id,
			'method' => 'tools/call',
			'params' => array(
				'name' => 'mcp-adapter-execute-ability',
				'arguments' => array(
					'ability_name' => $ability_name,
					'parameters' => (object) $parameters,
				),
			),
		),
		$session
	);
	wpai81_assert( 200 === $result->get_status(), 'MCP HTTP did not return 200 for ' . $ability_name );
	$data = json_decode( wp_json_encode( $result->get_data() ), true );
	wpai81_assert( false === ( $data['result']['isError'] ?? true ), 'MCP failed an advertised Core Ability ' . $ability_name );
	$structured = $data['result']['structuredContent'] ?? array();
	if ( ! is_array( $structured ) || ! isset( $structured['success'] ) ) {
		$text = $data['result']['content'][0]['text'] ?? '';
		$structured = is_string( $text ) ? json_decode( $text, true ) : array();
	}
	wpai81_assert( true === ( $structured['success'] ?? false ), 'MCP result returned success=false for ' . $ability_name );
	return $structured['data'] ?? null;
}

$admin_id = get_current_user_id();
wpai81_assert( $admin_id > 0 && current_user_can( 'manage_options' ), 'Requires synthetic QA administrator.' );
$settings = new Settings();
$old = get_option( Settings::OPTION_NAME, array() );
$read_only_cases = array(
	'core/get-environment-info' => array( 'fields' => array( 'wp_version' ) ),
	'core/get-site-info'        => array( 'fields' => array( 'version' ) ),
	'core/get-user-info'        => array( 'fields' => array( 'roles' ) ),
);
$resolver = new Ability_Resolver();
try {
	$new = $settings->all();
	$new[ Settings::GROUP_NATIVE_ABILITIES ] = 1;
	update_option( Settings::OPTION_NAME, $new, false );
	$catalog = wp_get_ability( 'wp-ai-bridge/abilities-read' );
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	wpai81_assert( $catalog instanceof WP_Ability && $adapter instanceof WP_Ability, 'Catalog/Adapter not registered.' );
	foreach ( $read_only_cases as $name => $input ) {
		$ability = wp_get_ability( $name );
		wpai81_assert( $ability instanceof WP_Ability, 'Core Ability not registered: ' . $name );
		$meta = $ability->get_meta();
		wpai81_assert( true === ( $meta['public'] ?? null ), 'Core Ability expected public meta: ' . $name );
		wpai81_assert(
			true === \WP\MCP\Abilities\McpAbilityExposure::is_public( $ability )
			&& true === $resolver->is_mcp_exposed( $ability ),
			'Adapter/Bridge exposure mismatch: ' . $name
		);
		$found = $catalog->execute( array( 'action' => 'get', 'name' => $name ) );
		wpai81_assert( ! is_wp_error( $found ) && 'native_abilities' === $found['items'][0]['bridge_delegation'], 'Catalog missing native Core contract: ' . $name );
		$permission = $adapter->check_permissions( array( 'ability_name' => $name, 'parameters' => $input ) );
		wpai81_assert( true === $permission, 'Native Adapter permission denied in same WordPress context: ' . $name );
		$native = $adapter->execute( array( 'ability_name' => $name, 'parameters' => $input ) );
		wpai81_assert( ! is_wp_error( $native ) && true === ( $native['success'] ?? false ), 'Adapter direct execution failed: ' . $name );
	}
	$oauth = new OAuth_Server( new OAuth_Store() );
	$store = new OAuth_Store();
	$token = $store->issue( OAuth_Store::TYPE_ACCESS, array(
		'user_id' => $admin_id,
		'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
		'resource' => $oauth->mcp_endpoint_url(),
		'scope' => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
	), 120 );
	wpai81_assert( is_string( $token ) && '' !== $token, 'Synthetic OAuth fixture could not issue token.' );
	$before = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $admin_id );
	$init = wpai81_mcp_request( $token, array(
		'jsonrpc' => '2.0',
		'id' => 1,
		'method' => 'initialize',
		'params' => array(
			'protocolVersion' => '2025-11-25',
			'capabilities' => (object) array(),
			'clientInfo' => array( 'name' => 'wpai81-synthetic', 'version' => '1.0' ),
		),
	) );
	wpai81_assert( 200 === $init->get_status(), 'MCP initialize failed.' );
	$headers = $init->get_headers();
	$session = (string) ( $headers['Mcp-Session-Id'] ?? '' );
	if ( '' === $session ) {
		$after = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $admin_id );
		$created = array_diff_key( $after, $before );
		$session = (string) array_key_first( $created );
	}
	wpai81_assert( '' !== $session, 'MCP session creation failed.' );
	$initialized = wpai81_mcp_request(
		$token, array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ), $session
	);
	wpai81_assert( 202 === $initialized->get_status(), 'MCP initialized notification failed.' );
	$id = 10;
	foreach ( $read_only_cases as $name => $input ) {
		$from_catalog = wpai81_mcp_call( $token, $session, 'wp-ai-bridge/abilities-read', array( 'action' => 'get', 'name' => $name ), ++$id );
		wpai81_assert( $name === ( $from_catalog['items'][0]['name'] ?? '' ), 'MCP catalog mismatch: ' . $name );
		$from_adapter = wpai81_mcp_call( $token, $session, $name, $input, ++$id );
		wpai81_assert( is_array( $from_adapter ), 'MCP Core execution payload invalid: ' . $name );
		$key = array_values( $input['fields'] )[0];
		wpai81_assert( array_key_exists( $key, $from_adapter ), 'MCP Core output field missing: ' . $name );
		echo 'PASS: ' . $name . ' catalog + Adapter + real MCP, identical synthetic OAuth principal.' . PHP_EOL;
	}
	echo 'PASS: issue81 clean official runtime has no native Core catalog/execute mismatch.' . PHP_EOL;
} finally {
	wp_set_current_user( $admin_id );
	update_option( Settings::OPTION_NAME, $old, false );
}
