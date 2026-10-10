<?php
/**
 * Issue #119: native REST execution through actual WordPress and MCP Adapter.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Settings;

function wpai119execute_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$user_id  = get_current_user_id();
$core_post_id = 0;
$calls    = 0;
$permissions_called = 0;

try {
	$defaults = $settings->defaults();
	update_option( Settings::OPTION_NAME, $defaults, false );
	$ability = wp_get_ability( 'wp-ai-bridge/rest-route-invoke' );
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	wpai119execute_check( $ability instanceof WP_Ability && $adapter instanceof WP_Ability, 'High-trust invocation or official Adapter registration unavailable.' );

	$server = rest_get_server();
	wpai119execute_check( $server instanceof WP_REST_Server, 'Native server unavailable.' );
	$route = '/wpai119exec/v1/item/(?P<id>[\d]+)';
	$path  = '/wpai119exec/v1/item/7';

	$handler = static function ( $request ) use ( &$calls ) {
		++$calls;
		return new WP_REST_Response(
			array(
				'id'    => (int) $request->get_param( 'id' ),
				'title' => (string) $request->get_param( 'title' ),
			),
			'POST' === $request->get_method() ? 201 : 200
		);
	};
	$permission = static function ( $request ) use ( &$permissions_called ) {
		++$permissions_called;
		return current_user_can( 'manage_options' );
	};
	$server->register_route(
		'wpai119exec/v1',
		'/wpai119exec/v1/item/(?P<id>[\d]+)',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => $handler,
				'permission_callback' => $permission,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => $handler,
				'permission_callback' => $permission,
				'args'                => array(
					'title' => array( 'type' => 'string', 'required' => true ),
				),
			),
		)
	);
	$server->register_route(
		'wpai119exec/v1',
		'/wpai119exec/v1/private',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'show_in_index'       => false,
				'callback'            => $handler,
				'permission_callback' => $permission,
			),
		)
	);
	$server->register_route(
		'wpai119exec/v1',
		'/wpai119exec/v1/denied',
		array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => $handler,
				'permission_callback' => static function () { return false; },
			),
		)
	);
	// Innocuous provider route/argument names can conceal credentials,
	// executable source writes or package ingress. These isolated fixtures
	// never touch actual credentials/files/packages; they only count calls.
	$sensitive_calls = 0;
	foreach (
		array(
			array( '/wpai119exec/v1/config', 'value' ),
			array( '/wpai119exec/v1/manage', 'payload' ),
			array( '/wpai119exec/v1/process', 'data' ),
		) as $opaque_fixture
	) {
		$server->register_route(
			'wpai119exec/v1',
			$opaque_fixture[0],
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => static function () use ( &$sensitive_calls ) {
						++$sensitive_calls;
						return array( 'ok' => true );
					},
					'permission_callback' => $permission,
					'args'                => array( $opaque_fixture[1] => array( 'type' => 'string' ) ),
				),
			)
		);
	}

	$hidden_dispatches = 0;
	$public_dispatches = 0;
	$server->register_route(
		'wpai119exec/v1',
		'/wpai119exec/v1/same-method-hidden-first',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'show_in_index'       => false,
				'callback'            => static function () use ( &$hidden_dispatches ) {
					++$hidden_dispatches;
					return array( 'private' => true );
				},
				'permission_callback' => $permission,
			),
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () use ( &$public_dispatches ) {
					++$public_dispatches;
					return array( 'public' => true );
				},
				'permission_callback' => $permission,
			),
		)
	);
	$server->register_route(
		'wpai119exec/v1',
		'/wpai119exec/v1/same-method-schema-variants',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () use ( &$public_dispatches ) {
					++$public_dispatches;
					return array( 'public' => 'first' );
				},
				'permission_callback' => $permission,
				'args'                => array( 'value' => array( 'type' => 'string' ) ),
			),
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () use ( &$public_dispatches ) {
					++$public_dispatches;
					return array( 'public' => 'later' );
				},
				'permission_callback' => $permission,
				'args'                => array( 'payload' => array( 'type' => 'string' ) ),
			),
		)
	);
	$server->register_route(
		'wpai119exec/v1',
		'/wpai119exec/v1/filtered-selected',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () use ( &$public_dispatches ) {
					++$public_dispatches;
					return array( 'public' => true );
				},
				'permission_callback' => $permission,
			),
		)
	);
	$server->register_route(
		'wpai119exec/v1',
		'/wpai119exec/v1/oversized',
		array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => static function () use ( &$calls ) {
					++$calls;
					return array( 'content' => str_repeat( 'x', Bounded_Payload::RESPONSE_BYTES + 512 ) );
				},
				'permission_callback' => $permission,
			),
		)
	);

	$read = array( 'route' => $route, 'path' => $path, 'method' => 'GET' );
	wpai119execute_check( is_wp_error( $ability->execute( $read ) ), 'High-trust route invocation inherited Site Read or other grants.' );
	wpai119execute_check( 0 === $calls && 0 === $permissions_called, 'Default-off invocation reached a native callback or permission check.' );

	$enabled = $defaults;
	$enabled[ Settings::GROUP_REST_INVOCATION ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	$before_sensitive = $sensitive_calls;
	foreach (
		array(
			array( '/wpai119exec/v1/config', 'value' ),
			array( '/wpai119exec/v1/manage', 'payload' ),
			array( '/wpai119exec/v1/process', 'data' ),
		) as $opaque_fixture
	) {
		$attempt = $ability->execute(
			array(
				'route'  => $opaque_fixture[0],
				'path'   => $opaque_fixture[0],
				'method' => 'POST',
				'body'   => array( $opaque_fixture[1] => 'opaque-value' ),
			)
		);
		wpai119execute_check( is_wp_error( $attempt ), 'REST-only grant bypassed a semantic credential/source/package consent with innocuous field names.' );
	}
	wpai119execute_check( $before_sensitive === $sensitive_calls, 'Protected provider operation executed with generic REST grant only.' );

	// Full administrator-equivalent mode is a separate explicit opt-in to
	// every purpose-specific Bridge group. Core/provider capabilities still
	// run through current_user_can before WordPress native dispatch.
	foreach ( $settings->groups() as $group => $definition ) {
		$enabled[ $group ] = 1;
	}
	update_option( Settings::OPTION_NAME, $enabled, false );

	// Exercise a real WordPress Core route and native edit_posts/read_post
	// mapping, not only a controlled third-party-like provider callback.
	$core_post_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => 'WP AI Bridge registered REST Core contract fixture',
			'post_content' => 'Core route read under the current administrator.',
	),
		true
	);
	wpai119execute_check( ! is_wp_error( $core_post_id ) && is_int( $core_post_id ) && $core_post_id > 0, 'Could not create isolated Core REST fixture.' );
	$core_result = $ability->execute(
		array(
			'route'  => '/wp/v2/posts/(?P<id>[\\d]+)',
			'path'   => '/wp/v2/posts/' . $core_post_id,
			'method' => 'GET',
		)
	);
	wpai119execute_check(
		! is_wp_error( $core_result ) &&
		200 === $core_result['status'] &&
		'reported_success' === $core_result['outcome'] &&
		$core_post_id === ( $core_result['data']['id'] ?? 0 ),
		'Authorized native WordPress Core post route could not be read using the generic Ability.'
	);
	wpai119execute_check( Bounded_Payload::fits( $core_result ), 'Core route native response exceeded the bounded MCP envelope.' );

	$first = $ability->execute( $read );
	wpai119execute_check( ! is_wp_error( $first ) && 200 === $first['status'] && 'reported_success' === $first['outcome'] && 7 === $first['data']['id'], 'Native GET failed under explicit high-trust grant.' );
	wpai119execute_check( 1 === $calls && 1 === $permissions_called, 'WordPress provider permission and callback did not run exactly once.' );

	$mutate = array( 'route' => $route, 'path' => $path, 'method' => 'POST', 'body' => array( 'title' => 'native-json' ) );
	$native = $ability->execute( $mutate );
	wpai119execute_check( ! is_wp_error( $native ) && 201 === $native['status'] && 'reported_success' === $native['outcome'] && 'native-json' === $native['data']['title'], 'Real native POST JSON validation/dispatch failed.' );
	wpai119execute_check( 2 === $calls && 2 === $permissions_called, 'Native POST skipped or repeated provider auth/callback.' );
	wpai119execute_check( Bounded_Payload::fits( $native ), 'Native mutation response is not MCP bounded.' );

	$via_adapter = $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/rest-route-invoke',
			'parameters'   => $read,
		)
	);
	wpai119execute_check( ! is_wp_error( $via_adapter ) && true === ( $via_adapter['success'] ?? false ) && $first === $via_adapter['data'], 'Official MCP Adapter altered read-only generic REST execution result.' );
	wpai119execute_check( 3 === $calls && 3 === $permissions_called, 'Official MCP Adapter failed to execute through the native permission callback.' );

	$invalid = $mutate;
	$invalid['body'] = array( 'title' => array( 'wrong-type' ) );
	$bad = $ability->execute( $invalid );
	wpai119execute_check( ! is_wp_error( $bad ) && 400 === $bad['status'] && 'outcome_unknown' === $bad['outcome'], 'Native Core schema rejection must be enforced without optimistic retry advice.' );
	wpai119execute_check( 3 === $calls, 'Native invalid parameter unexpectedly ran provider callback.' );

	$denied = $ability->execute( array( 'route' => '/wpai119exec/v1/denied', 'path' => '/wpai119exec/v1/denied', 'method' => 'POST' ) );
	wpai119execute_check( ! is_wp_error( $denied ) && 403 === $denied['status'] && 'outcome_unknown' === $denied['outcome'], 'Provider-native permission denial must remain authoritative.' );
	wpai119execute_check( 3 === $calls, 'Provider permission denial nonetheless invoked callback.' );

	wpai119execute_check( isset( $server->get_routes()['/batch/v1'] ), 'WordPress Core /batch/v1 route is absent from this supported integration fixture.' );
	$batch = $ability->execute(
		array(
			'route'  => '/batch/v1',
			'path'   => '/batch/v1',
			'method' => 'POST',
			'body'   => array(
				'requests' => array(
					array(
						'method' => 'POST',
						'path'   => '/wp/v2/settings',
						'body'   => array( 'title' => 'blocked' ),
					),
				),
			),
		)
	);
	wpai119execute_check( is_wp_error( $batch ), 'WordPress Core batch fan-out must not bypass specialized Bridge controls.' );
	foreach ( array(
		array( 'route' => $route, 'path' => '/wpai119exec/v1/item/7?foo=1', 'method' => 'GET' ),
		array( 'route' => $route, 'path' => '/wpai119exec/v1/item/../7', 'method' => 'GET' ),
		array( 'route' => $route, 'path' => '/wpai119exec/v1/item/9/nope', 'method' => 'GET' ),
		array( 'route' => '/wpai119exec/v1/private', 'path' => '/wpai119exec/v1/private', 'method' => 'GET' ),
		array( 'route' => '/wp/v2/settings', 'path' => '/wp/v2/settings', 'method' => 'GET' ),
		array( 'route' => $route, 'path' => $path, 'method' => 'POST', 'body' => array( 'application_password' => 'secret' ) ),
	) as $rejected ) {
		wpai119execute_check( is_wp_error( $ability->execute( $rejected ) ), 'Unsafe route or input escaped local boundary.' );
	}
	wpai119execute_check( 3 === $calls, 'Unsafe requests reached provider callbacks.' );

	$filter = static function ( $routes, $raw ) use ( $route ) {
		unset( $routes[ $route ] );
		return $routes;
	};
	add_filter( 'rest_route_data', $filter, 10, 2 );
	try {
		wpai119execute_check( is_wp_error( $ability->execute( $read ) ), 'Provider removed route index yet generic execution resurrected it.' );
	} finally {
		remove_filter( 'rest_route_data', $filter, 10 );
	}
	wpai119execute_check( 3 === $calls, 'Filtered-out route was dispatched.' );

	$redact_arg = static function ( $contract ) {
		if ( 'wpai119exec/v1' === ( $contract['namespace'] ?? '' ) && isset( $contract['endpoints'] ) ) {
			foreach ( $contract['endpoints'] as &$endpoint ) {
				unset( $endpoint['args']['title'] );
			}
			unset( $endpoint );
		}
		return $contract;
	};
	add_filter( 'rest_endpoints_description', $redact_arg, 10, 1 );
	try {
		$public = $server->get_data_for_routes( array( $route => $server->get_routes()[ $route ] ), 'view' );
		wpai119execute_check( ! isset( $public[ $route ]['endpoints'][1]['args']['title'] ), 'Native endpoint filter fixture did not redact title.' );
		wpai119execute_check( is_wp_error( $ability->execute( $mutate ) ), 'Invocation bypassed redacted public-index argument names.' );
	} finally {
		remove_filter( 'rest_endpoints_description', $redact_arg, 10 );
	}
	wpai119execute_check( 3 === $calls, 'Redacted argument was dispatched anyway.' );
	wpai119execute_check(
		is_wp_error( $ability->execute( array( 'route' => $route, 'path' => $path, 'method' => 'GET', 'query' => array( 'id' => 999 ) ) ) ),
		'Query parameters must not override the Core native path capture identity.'
	);


	// Core chooses the first same-route method handler irrespective of
	// show_in_index. A later public handler must not expose a hidden first.
	$initial_methods = $server->get_data_for_routes(
		array(
			'/wpai119exec/v1/same-method-hidden-first' => $server->get_routes()['/wpai119exec/v1/same-method-hidden-first'],
		),
		'view'
	);
	wpai119execute_check(
		isset( $initial_methods['/wpai119exec/v1/same-method-hidden-first'] ) &&
		in_array( 'GET', $initial_methods['/wpai119exec/v1/same-method-hidden-first']['methods'], true ),
		'Hidden-first fixture does not advertise the later public GET handler.'
	);
	$hidden_first = $ability->execute(
		array(
			'route'  => '/wpai119exec/v1/same-method-hidden-first',
			'path'   => '/wpai119exec/v1/same-method-hidden-first',
			'method' => 'GET',
		)
	);
	wpai119execute_check( is_wp_error( $hidden_first ) && 0 === $hidden_dispatches && 0 === $public_dispatches, 'Hidden first Core handler was exposed by a later public method.' );
	$adapter_denied = $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/rest-route-invoke',
			'parameters'   => array(
				'route'  => '/wpai119exec/v1/same-method-hidden-first',
				'path'   => '/wpai119exec/v1/same-method-hidden-first',
				'method' => 'GET',
			),
		)
	);
	wpai119execute_check( ( is_wp_error( $adapter_denied ) || empty( $adapter_denied['success'] ) ) && 0 === $hidden_dispatches, 'Official Adapter re-exposed a hidden-first handler.' );

	$schema_variants = $ability->execute(
		array(
			'route'  => '/wpai119exec/v1/same-method-schema-variants',
			'path'   => '/wpai119exec/v1/same-method-schema-variants',
			'method' => 'GET',
			'query'  => array( 'payload' => 'later-only' ),
		)
	);
	wpai119execute_check( is_wp_error( $schema_variants ) && 0 === $public_dispatches, 'Disjoint same-method public argument schemas were incorrectly merged for Core first-handler validation.' );
	$filtered = static function ( $contract ) {
		if ( 'wpai119exec/v1' === ( $contract['namespace'] ?? '' ) && isset( $contract['endpoints'] ) ) {
			foreach ( $contract['endpoints'] as &$endpoint ) {
				$endpoint['methods'] = array();
			}
			unset( $endpoint );
		}
		return $contract;
	};
	add_filter( 'rest_endpoints_description', $filtered, 10, 1 );
	try {
		wpai119execute_check(
			is_wp_error(
				$ability->execute(
					array(
						'route' => '/wpai119exec/v1/filtered-selected',
						'path' => '/wpai119exec/v1/filtered-selected',
						'method' => 'GET',
					)
				)
			) && 0 === $public_dispatches,
			'Filtered Core-selected endpoint was executed despite public-index removal.'
		);
	} finally {
		remove_filter( 'rest_endpoints_description', $filtered, 10 );
	}
	$revoked = $enabled;
	$revoked[ Settings::GROUP_AUTHENTICATION ] = 0;
	update_option( Settings::OPTION_NAME, $revoked, false );
	wpai119execute_check( is_wp_error( $ability->execute( $read ) ), 'Specialized credential grant revocation was bypassed by generic REST.' );
	$revoked = $enabled;
	$revoked[ Settings::GROUP_SOURCE_EDITING ] = 0;
	update_option( Settings::OPTION_NAME, $revoked, false );
	wpai119execute_check( is_wp_error( $ability->execute( $read ) ), 'Specialized source editing grant revocation was bypassed by generic REST.' );
	$revoked = $enabled;
	$revoked[ Settings::GROUP_EXTERNAL_PACKAGES ] = 0;
	update_option( Settings::OPTION_NAME, $revoked, false );
	wpai119execute_check( is_wp_error( $ability->execute( $read ) ), 'Specialized package installation grant revocation was bypassed by generic REST.' );
	update_option( Settings::OPTION_NAME, $enabled, false );

	$large = $ability->execute( array( 'route' => '/wpai119exec/v1/oversized', 'path' => '/wpai119exec/v1/oversized', 'method' => 'POST' ) );
	wpai119execute_check( ! is_wp_error( $large ) && 'outcome_unknown' === $large['outcome'], 'Post-callback oversized result must expose only uncertainty, not imply rollback.' );
	wpai119execute_check( 4 === $calls, 'Oversize fixture did not execute before response limitation.' );
	wpai119execute_check( ! str_contains( wp_json_encode( $large ), str_repeat( 'x', 32 ) ), 'Large provider data escaped REST response guard.' );

	wp_set_current_user( 0 );
	wpai119execute_check( is_wp_error( $ability->execute( $read ) ), 'Anonymous WordPress principal inherited the administrator grant.' );
	wp_set_current_user( $user_id );

	update_option( Settings::OPTION_NAME, $defaults, false );
	wpai119execute_check( is_wp_error( $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/rest-route-invoke', 'parameters' => $read ) ) ), 'Revoked high-trust grant was ignored by official MCP Adapter.' );
	wpai119execute_check( 4 === $calls, 'Revocation still allowed provider execution.' );

	echo "PASS: Issue #119 registered REST invocation default-off, Core/provider permission, JSON mutation, index privacy, revocation, bounded output and official MCP Adapter.\n";
} finally {
	wp_set_current_user( $user_id );
	update_option( Settings::OPTION_NAME, $original, false );
	if ( is_int( $core_post_id ) && $core_post_id > 0 ) {
		wp_delete_post( $core_post_id, true );
	}
}
