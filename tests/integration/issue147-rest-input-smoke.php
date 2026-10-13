<?php
/**
 * Type-aware configured REST input above former 16KiB on real WordPress.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Support\REST_Invocation_Input_Limits;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;

function wpai147rest_assert( $ok, $why ) {
	if ( ! $ok ) {
		throw new RuntimeException( $why );
	}
}

$grants = new Settings();
$limits = new REST_Invocation_Input_Limits();
$old_grants = get_option( Settings::OPTION_NAME, false );
$old_policy = get_option( REST_Invocation_Input_Limits::OPTION_NAME, false );
$old_transport = get_option( Transfer_Settings::OPTION_NAME, false );
$calls = 0;
$value = str_repeat( 'A', 22 * 1024 );
$payload = array(
	'route' => '/wpai147rest/v1/large',
	'path' => '/wpai147rest/v1/large',
	'method' => 'POST',
	'body' => array( 'value' => $value ),
);
$ability = wp_get_ability( 'wp-ai-bridge/rest-route-invoke' );
$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
wpai147rest_assert( $ability instanceof WP_Ability && $adapter instanceof WP_Ability, 'Native generic REST or official MCP Adapter not registered.' );

$server = rest_get_server();
$server->register_route(
	'wpai147rest/v1',
	'/wpai147rest/v1/large',
	array(
		array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => static function ( $request ) use ( &$calls ) {
				++$calls;
				$actual = (string) $request->get_param( 'value' );
				return 'O' === substr( $actual, 0, 1 ) ? array( 'oversized' => str_repeat( 'z', 20 * 1024 ) ) : array( 'accepted_bytes' => strlen( $actual ), 'sha256' => hash( 'sha256', $actual ) );
			},
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'args' => array( 'value' => array( 'type' => 'string', 'required' => true ) ),
		),
	)
);

try {
	$all = $grants->defaults();
	foreach ( array_keys( $all ) as $key ) {
		$all[ $key ] = 1;
	}
	update_option( Settings::OPTION_NAME, $all, false );
	wpai147rest_assert( 16 * 1024 === $limits->effective_bytes(), 'Unconfigured REST one-shot input must preserve 16KiB default.' );
	$denied = $ability->execute( $payload );
	wpai147rest_assert( is_wp_error( $denied ) && 0 === $calls, 'Default 16KiB denied payload must never invoke a provider.' );

	$limits->register();
	$saved = $limits->sanitize( '48' );
	update_option( REST_Invocation_Input_Limits::OPTION_NAME, $saved, false );
	wpai147rest_assert( 48 * 1024 === $limits->effective_bytes(), 'Raised isolated native REST policy did not become effective.' );
	$direct = $ability->execute( $payload );
	wpai147rest_assert( ! is_wp_error( $direct ) && 'reported_success' === $direct['outcome'] && $direct['data']['accepted_bytes'] === strlen( $value ) && $direct['data']['sha256'] === hash( 'sha256', $value ), 'Native REST dispatch silently truncated or rejected the authorized 22KiB body.' );

	$via_mcp = $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/rest-route-invoke', 'parameters' => $payload ) );
	wpai147rest_assert( ! is_wp_error( $via_mcp ) && true === ( $via_mcp['success'] ?? false ) && 'reported_success' === ( $via_mcp['data']['outcome'] ?? '' ), 'Official Adapter did not allow the authorized bounded 22KiB native REST body.' );
	wpai147rest_assert( 2 === $calls, 'Authorized direct+Adapter call did not reach native registered provider exactly twice.' );
	$oversized_output = $payload;
	$oversized_output['body']['value'] = 'O' . substr( $value, 1 );
	$unsafe_output = $ability->execute( $oversized_output );
	wpai147rest_assert( ! is_wp_error( $unsafe_output ) && 'outcome_unknown' === ( $unsafe_output['outcome'] ?? '' ), 'Larger operator input policy must not widen the existing 16KiB native provider-output safety budget.' );
	wpai147rest_assert( 3 === $calls, 'The inert oversized-output guard fixture never dispatched.' );

	update_option( Transfer_Settings::OPTION_NAME, array( Transfer_Settings::REQUEST_KIB => 16 ), false );
	wpai147rest_assert( 16 * 1024 === $limits->effective_bytes(), 'Lowered existing MCP one-message policy failed to clamp a larger REST input policy.' );
	$transport_denied = $ability->execute( $payload );
	wpai147rest_assert( is_wp_error( $transport_denied ) && 3 === $calls, 'Lowered shared MCP budget reached native provider dispatch.' );

	delete_option( Transfer_Settings::OPTION_NAME );
	$all[ Settings::GROUP_REST_INVOCATION ] = 0;
	update_option( Settings::OPTION_NAME, $all, false );
	$revoked = $ability->execute( $payload );
	wpai147rest_assert( is_wp_error( $revoked ) && 3 === $calls, 'Independent REST grant revocation was bypassed by capacity setting.' );
	echo "PASS: #147 real installed WordPress + official MCP Adapter 22KiB registered REST provider body, old 16KiB default denial, raised 48KiB success, transport clamp and grant revocation.\n";
} finally {
	if ( false === $old_grants ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $old_grants, false );
	}
	if ( false === $old_policy ) {
		delete_option( REST_Invocation_Input_Limits::OPTION_NAME );
	} else {
		update_option( REST_Invocation_Input_Limits::OPTION_NAME, $old_policy, false );
	}
	if ( false === $old_transport ) {
		delete_option( Transfer_Settings::OPTION_NAME );
	} else {
		update_option( Transfer_Settings::OPTION_NAME, $old_transport, false );
	}
}
