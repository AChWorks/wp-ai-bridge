<?php
/**
 * Issue #119: real WordPress and official MCP Adapter registered REST discovery.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Settings;

function wpai119_live_assert( $passed, $message ) {
	if ( ! $passed ) {
		throw new RuntimeException( $message );
	}
}

$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$user_id  = get_current_user_id();
$calls    = 0;

try {
	$defaults = $settings->defaults();
	update_option( Settings::OPTION_NAME, $defaults, false );
	$ability = wp_get_ability( 'wp-ai-bridge/rest-routes-read' );
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	wpai119_live_assert( $ability instanceof WP_Ability && $adapter instanceof WP_Ability, 'Registered REST inspector or official MCP Adapter is unavailable.' );
	wpai119_live_assert( is_wp_error( $ability->execute( array() ) ), 'New REST grant silently inherited Site Read or another existing consent.' );

	$server = rest_get_server();
	wpai119_live_assert( $server instanceof WP_REST_Server, 'Real REST registry is unavailable.' );
	$callback = static function () use ( &$calls ) {
		++$calls;
		return array( 'secret' => 'never-execute' );
	};
	$permission = static function () use ( &$calls ) {
		++$calls;
		return false;
	};
	$server->register_route(
		'wpai119/v1',
		'/wpai119/v1/probe',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => $callback,
				'permission_callback' => $permission,
				'args'                => array(
					'probe' => array(
						'type'        => 'string',
						'required'    => true,
						'default'     => 'fixture-secret-do-not-export',
						'description' => 'fixture-private-description',
					),
				),
			),
		)
	);
	$server->register_route(
		'wpai119/v1',
		'/wpai119/v1/hidden',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => $callback,
				'permission_callback' => $permission,
				'show_in_index'       => false,
			),
		)
	);

	$server->register_route(
		'wpai119/v1',
		'/wpai119/v1/removed',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => $callback,
				'permission_callback' => $permission,
			),
		)
	);

	$enabled = $defaults;
	$enabled[ Settings::GROUP_REST_DISCOVERY ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );

	$result = $ability->execute( array( 'action' => 'get', 'route' => '/wpai119/v1/probe' ) );
	wpai119_live_assert( ! is_wp_error( $result ) && Bounded_Payload::fits( $result ), 'Real WordPress route detail failed bounded native execution.' );
	wpai119_live_assert( 'not_evaluated' === $result['execution_permission'] && true === $result['items'][0]['indexed'], 'REST catalog claimed real target authorization or hid a public provider.' );
	wpai119_live_assert( 'probe' === $result['items'][0]['endpoints'][0]['arguments'][0]['name'], 'Native provider argument identity was not projected.' );
	wpai119_live_assert( false === strpos( wp_json_encode( $result ), 'fixture-secret-do-not-export' ), 'Private WordPress schema defaults escaped REST discovery.' );
	wpai119_live_assert( false === strpos( wp_json_encode( $result ), 'fixture-private-description' ), 'Sensitive provider schema description escaped REST discovery.' );
	wpai119_live_assert( 0 === $calls, 'REST discovery invoked native provider execution or permission callback.' );

	$info = wp_get_ability( 'mcp-adapter/get-ability-info' )->execute( array( 'ability_name' => 'wp-ai-bridge/rest-routes-read' ) );
	wpai119_live_assert( ! is_wp_error( $info ) && 'wp-ai-bridge/rest-routes-read' === $info['name'], 'Official MCP Adapter cannot inspect the discovery Ability.' );
	$wrapped = $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/rest-routes-read',
			'parameters'   => array( 'action' => 'get', 'route' => '/wpai119/v1/probe' ),
		)
	);
	wpai119_live_assert( ! is_wp_error( $wrapped ) && true === ( $wrapped['success'] ?? false ) && Bounded_Payload::fits( $wrapped ), 'Adapter could not carry a bounded REST discovery response.' );
	wpai119_live_assert( $result === $wrapped['data'], 'Adapter altered native REST route contract metadata.' );

	$list = $ability->execute( array( 'namespace' => 'wpai119/v1', 'per_page' => 5 ) );
	wpai119_live_assert( ! is_wp_error( $list ) && 3 === $list['total'] && 3 === count( $list['items'] ), 'Namespace-scoped native registry omitted a public route or exposed a hidden one.' );
	wpai119_live_assert( ! in_array( '/wpai119/v1/hidden', array_column( $list['items'], 'route' ), true ), 'Native show_in_index=false contract leaked through list.' );
	$hidden = $ability->execute( array( 'action' => 'get', 'route' => '/wpai119/v1/hidden' ) );
	wpai119_live_assert( is_wp_error( $hidden ) && 'rest_route_not_found' === $hidden->get_error_code(), 'A private route must be indistinguishable from an absent route.' );
	wpai119_live_assert( 0 === $calls, 'Namespace listing or hidden get invoked callbacks.' );

	// Install real Core public-index filters, not mocks of the Bridge projection.
	// The pre-filter normalized registered handlers deliberately remain intact.
	$description_filter = static function ( $data ) {
		if ( 'wpai119/v1' === ( $data['namespace'] ?? '' ) && ! empty( $data['endpoints'] ) ) {
			foreach ( $data['endpoints'] as &$endpoint ) {
				if ( isset( $endpoint['args']['probe'] ) ) {
					unset( $endpoint['args']['probe'] );
				}
			}
			unset( $endpoint );
		}
		return $data;
	};
	$route_filter = static function ( $available, $routes ) {
		unset( $available['/wpai119/v1/removed'] );
		// The public-index filter is also allowed to add synthetic descriptions;
		// a Bridge catalog must not claim them as locally registered operations.
		$available['/wpai119/v1/synthetic'] = array( 'methods' => array( 'GET' ), 'endpoints' => array() );
		return $available;
	};
	add_filter( 'rest_endpoints_description', $description_filter, 10, 1 );
	add_filter( 'rest_route_data', $route_filter, 10, 2 );
	try {
		$public_after_filters = $server->get_data_for_routes( $server->get_routes( 'wpai119/v1' ), 'view' );
		wpai119_live_assert( ! isset( $public_after_filters['/wpai119/v1/removed'] ), 'WordPress public index filter fixture failed to remove its selected route.' );
		wpai119_live_assert( ! isset( $public_after_filters['/wpai119/v1/probe']['endpoints'][0]['args']['probe'] ), 'WordPress endpoint-description filter fixture failed to redact its selected argument.' );

		$redacted = $ability->execute( array( 'action' => 'get', 'route' => '/wpai119/v1/probe' ) );
		wpai119_live_assert( ! is_wp_error( $redacted ) && empty( $redacted['items'][0]['endpoints'][0]['arguments'] ), 'Bridge exact get restored argument names removed by the native rest_endpoints_description filter.' );
		$filtered_list = $ability->execute( array( 'namespace' => 'wpai119/v1', 'per_page' => 10 ) );
		wpai119_live_assert( ! is_wp_error( $filtered_list ) && 2 === $filtered_list['total'], 'Bridge list resurrected a route removed by native rest_route_data, or counted synthetic filter data.' );
		$names = array_column( $filtered_list['items'], 'route' );
		wpai119_live_assert( ! in_array( '/wpai119/v1/removed', $names, true ) && ! in_array( '/wpai119/v1/synthetic', $names, true ) && ! in_array( '/wpai119/v1/hidden', $names, true ), 'A removed, synthetic, or show_in_index=false route escaped into Bridge discovery.' );
		$removed = $ability->execute( array( 'action' => 'get', 'route' => '/wpai119/v1/removed' ) );
		wpai119_live_assert( is_wp_error( $removed ) && 'rest_route_not_found' === $removed->get_error_code(), 'Bridge exact get bypassed native public route removal.' );
		$hidden_again = $ability->execute( array( 'action' => 'get', 'route' => '/wpai119/v1/hidden' ) );
		wpai119_live_assert( is_wp_error( $hidden_again ) && 'rest_route_not_found' === $hidden_again->get_error_code(), 'show_in_index=false route became visible after public index filter injection.' );
		$wrapped_redacted = $adapter->execute(
			array(
				'ability_name' => 'wp-ai-bridge/rest-routes-read',
				'parameters'   => array( 'action' => 'get', 'route' => '/wpai119/v1/probe' ),
			)
		);
		wpai119_live_assert( ! is_wp_error( $wrapped_redacted ) && true === ( $wrapped_redacted['success'] ?? false ) && $redacted === $wrapped_redacted['data'], 'Official MCP Adapter bypassed the filtered native REST index output.' );
		wpai119_live_assert( 0 === $calls, 'Native REST route callback/permission callback executed while validating public index redaction.' );
	} finally {
		remove_filter( 'rest_endpoints_description', $description_filter, 10 );
		remove_filter( 'rest_route_data', $route_filter, 10 );
	}


	wp_set_current_user( 0 );
	wpai119_live_assert( is_wp_error( $ability->execute( array( 'namespace' => 'wpai119/v1' ) ) ), 'Anonymous principal inherited administrator REST discovery.' );
	wp_set_current_user( $user_id );

	update_option( Settings::OPTION_NAME, $defaults, false );
	wpai119_live_assert( is_wp_error( $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/rest-routes-read', 'parameters' => array() ) ) ), 'Gateway-compatible Adapter path ignored Bridge grant revocation.' );
	echo "PASS: WordPress REST public index privacy, default-off delegation, revocation and official MCP Adapter execution.\n";
} finally {
	wp_set_current_user( $user_id );
	update_option( Settings::OPTION_NAME, $original, false );
}
