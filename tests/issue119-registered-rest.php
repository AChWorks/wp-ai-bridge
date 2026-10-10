<?php
/**
 * Issue #119 read-only REST route discovery contract.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Registered_REST_Abilities;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

$assertions = 0;
function wpai119_check( $condition, $message ) {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class WP_AI_Bridge_Test_REST_Server {
	public $routes = array();
	public $callbacks_invoked = 0;
	public $description_filter = null;
	public $route_filter = null;
	public $index_calls = 0;
	public $last_inspected_routes = 0;

	public function get_routes( $namespace = '' ) {
		if ( '' === $namespace ) {
			return $this->routes;
		}
		$filtered = array();
		foreach ( $this->routes as $route => $handlers ) {
			if ( 0 === strpos( $route, '/' . $namespace . '/' ) ) {
				$filtered[ $route ] = $handlers;
			}
		}
		return $filtered;
	}

	public function get_data_for_route( $route, $handlers, $context = 'view' ) {
		$public = array();
		$methods = array();
		foreach ( $handlers as $handler ) {
			if ( empty( $handler['show_in_index'] ) ) {
				continue;
			}
			$names = array_keys( $handler['methods'] );
			$methods = array_merge( $methods, $names );
			$public[] = array( 'methods' => $names, 'args' => $handler['args'] );
		}
		return $public ? array( 'methods' => $methods, 'endpoints' => $public ) : null;
	}

	// Model WP_REST_Server::get_data_for_routes(), including the two public
	// REST-index filters omitted by get_data_for_route().
	public function get_data_for_routes( $routes, $context = 'view' ) {
		++$this->index_calls;
		$this->last_inspected_routes = count( $routes );
		$available = array();
		foreach ( $routes as $route => $handlers ) {
			$entry = $this->get_data_for_route( $route, $handlers, $context );
			if ( empty( $entry ) ) {
				continue;
			}
			$available[ $route ] = $this->description_filter ? call_user_func( $this->description_filter, $entry ) : $entry;
		}
		return $this->route_filter ? call_user_func( $this->route_filter, $available, $routes ) : $available;
	}
}

$GLOBALS['wpai119_rest'] = new WP_AI_Bridge_Test_REST_Server();
function rest_get_server() {
	return $GLOBALS['wpai119_rest'];
}

wpai_test_reset_state();
$settings = new Settings();
$provider = new Registered_REST_Abilities( new Permissions( $settings ) );
$defs     = $settings->defaults();
wpai119_check( 0 === $defs[ Settings::GROUP_REST_DISCOVERY ], 'New REST discovery consent must be default off.' );
wpai119_check( 0 === $settings->all()[ Settings::GROUP_REST_DISCOVERY ], 'Existing settings must not inherit broad discovery consent.' );
$registered = $provider->register();
$ability    = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/rest-routes-read'];
wpai119_check( 1 === count( $registered ), 'Expected a single registered inspector.' );
wpai119_check( true === $ability['meta']['annotations']['readonly'] && false === $ability['meta']['annotations']['destructive'], 'Must be static read-only class.' );
wpai119_check( false === $ability['input_schema']['additionalProperties'], 'Input schema must be closed.' );
wpai119_check( false === call_user_func( $ability['permission_callback'] ), 'Default Site Read must not grant REST registry.' );
wpai119_check( is_wp_error( $provider->read( array( 'action' => 'list' ) ) ), 'Direct callback must deny without consent.' );

$defs[ Settings::GROUP_REST_DISCOVERY ] = 1;
update_option( Settings::OPTION_NAME, $defs, false );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
wpai119_check( false === $provider->can_read(), 'WordPress admin capability remains mandatory.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;

$handler = array(
	'show_in_index' => true,
	'methods' => array( 'GET' => true, 'POST' => true ),
	'args' => array(
		'id' => array( 'type' => 'integer', 'required' => true ),
		'secret' => array( 'type' => 'string', 'default' => 'test-private-credential', 'description' => 'Confidential memo' ),
	),
	'callback' => static function () { throw new RuntimeException( 'Never dispatch a discovered route.' ); },
	'permission_callback' => static function () { throw new RuntimeException( 'Never evaluate route authority on discovery.' ); },
);
$GLOBALS['wpai119_rest']->routes = array(
	'/acme/v1/private' => array( array( 'show_in_index' => false, 'methods' => array( 'GET' => true ), 'callback' => $handler['callback'] ) ),
	'/wp/v2/posts/(?P<id>[\d]+)' => array( $handler ),
);
for ( $i = 0; $i < 50; $i++ ) {
	$GLOBALS['wpai119_rest']->routes[ '/acme/v1/item-' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ) ] = array( $handler );
}

$page = $provider->read( array( 'action' => 'list', 'namespace' => 'acme/v1', 'per_page' => 10, 'page' => 2 ) );
wpai119_check( ! is_wp_error( $page ) && 50 === $page['total'], 'Provider routes were not enumerated dynamically.' );
wpai119_check( 10 === count( $page['items'] ) && $page['has_more'], 'Page must be bounded and continue honestly.' );
wpai119_check( 'not_evaluated' === $page['execution_permission'], 'Catalog cannot claim executable permission.' );
wpai119_check( ! str_contains( wp_json_encode( $page ), 'test-private-credential' ), 'Public metadata must never dump defaults.' );
wpai119_check( ! str_contains( wp_json_encode( $page ), 'Confidential memo' ), 'Public metadata must never dump descriptions.' );
wpai119_check( ! isset( $page['items'][0]['endpoints'][0] ), 'A list must not return potentially large argument schemas.' );

$detail = $provider->read( array( 'action' => 'get', 'route' => '/wp/v2/posts/(?P<id>[\d]+)' ) );
wpai119_check( ! is_wp_error( $detail ) && $detail['items'][0]['indexed'], 'Public exact registered contract should be available.' );
wpai119_check( 'name_type_required_only' === $detail['items'][0]['contract_detail'], 'Argument projection must be explicit.' );
wpai119_check( 2 === count( $detail['items'][0]['endpoints'][0]['arguments'] ), 'Native public argument names must be retained.' );
wpai119_check( ! str_contains( wp_json_encode( $detail ), 'test-private-credential' ), 'Provider defaults must be omitted from detail.' );
wpai119_check( ! str_contains( wp_json_encode( $detail ), 'Confidential memo' ), 'Provider descriptions must be omitted.' );

$hidden = $provider->read( array( 'action' => 'get', 'route' => '/acme/v1/private' ) );
wpai119_check( is_wp_error( $hidden ) && 'rest_route_not_found' === $hidden->get_error_code(), 'Hidden registered routes must be indistinguishable from unknown routes.' );
$hidden_list = $provider->read( array( 'action' => 'list', 'namespace' => 'acme/v1' ) );
wpai119_check( ! is_wp_error( $hidden_list ) && 50 === $hidden_list['total'], 'Hidden native REST routes must not appear in catalog totals.' );
wpai119_check( is_wp_error( $provider->read( array( 'action' => 'get', 'route' => '/acme/v1/missing' ) ) ), 'Only exact registered routes can be inspected.' );
wpai119_check( is_wp_error( $provider->read( array( 'action' => 'list', 'page' => PHP_INT_MAX, 'per_page' => 10 ) ) ), 'Overflowing pagination must be rejected.' );
wpai119_check( is_wp_error( $provider->read( array( 'action' => 'list', 'unknown' => true ) ) ), 'Unknown input must not pass direct callback.' );

$GLOBALS['wpai119_rest']->routes['/acme/v1/' . str_repeat( 'X', 600 )] = array( $handler );
$large = $provider->read( array( 'action' => 'get', 'route' => '/acme/v1/' . str_repeat( 'X', 600 ) ) );
wpai119_check( is_wp_error( $large ), 'Oversize routes must be explicit errors, not silent clipping.' );
// The oversized-route probe must not pollute subsequent filtered-index totals.
unset( $GLOBALS['wpai119_rest']->routes[ '/acme/v1/' . str_repeat( 'X', 600 ) ] );

// Simulate native index redaction rather than changing the raw route registry.
$GLOBALS['wpai119_rest']->description_filter = static function ( $entry ) {
	if ( isset( $entry['endpoints'] ) ) {
		foreach ( $entry['endpoints'] as &$endpoint ) {
			unset( $endpoint['args']['secret'] );
		}
		unset( $endpoint );
	}
	if ( in_array( 'POST', $entry['methods'], true ) ) {
		$entry['methods'] = array( 'GET' );
	}
	return $entry;
};
$GLOBALS['wpai119_rest']->route_filter = static function ( $available, $routes ) {
	unset( $available['/acme/v1/item-001'] );
	// Index filters may append keys, but they are not native registered routes.
	$available['/acme/v1/synthetic'] = array( 'methods' => array( 'GET' ), 'endpoints' => array() );
	// Even adding metadata for a private registered route cannot override
	// its Core show_in_index=false setting.
	$available['/acme/v1/private'] = array( 'methods' => array( 'GET' ), 'endpoints' => array() );
	return $available;
};
$redacted = $provider->read( array( 'action' => 'get', 'route' => '/wp/v2/posts/(?P<id>[\d]+)' ) );
wpai119_check( ! is_wp_error( $redacted ) && array( 'GET' ) === $redacted['items'][0]['methods'], 'Public index method redaction was bypassed.' );
wpai119_check( 1 === count( $redacted['items'][0]['endpoints'][0]['arguments'] ) && 'id' === $redacted['items'][0]['endpoints'][0]['arguments'][0]['name'], 'Native public index argument redaction was bypassed.' );
wpai119_check( array( 'GET' ) === $redacted['items'][0]['endpoints'][0]['methods'], 'Removed route-level methods leaked through endpoint details.' );
$filtered_page = $provider->read( array( 'action' => 'list', 'namespace' => 'acme/v1', 'per_page' => 25 ) );
wpai119_check( ! is_wp_error( $filtered_page ) && 49 === $filtered_page['total'], 'Aggregate public route removal, hidden-route exclusion or synthetic-route rejection failed.' );
wpai119_check( ! in_array( '/acme/v1/item-001', array_column( $filtered_page['items'], 'route' ), true ), 'Removed native index route was resurrected.' );
wpai119_check( is_wp_error( $provider->read( array( 'action' => 'get', 'route' => '/acme/v1/item-001' ) ) ), 'Exact get resurrected a route removed by rest_route_data.' );
wpai119_check( is_wp_error( $provider->read( array( 'action' => 'get', 'route' => '/acme/v1/private' ) ) ), 'Filtered injection exposed a show_in_index=false route.' );
wpai119_check( is_wp_error( $provider->read( array( 'action' => 'get', 'route' => '/acme/v1/synthetic' ) ) ), 'Synthetic public-index route without a registration was accepted.' );

// A small output page must not launch unbounded schema normalization.
// Exact get and a small native namespace remain usable despite a heavy site.
for ( $i = 0; $i <= Registered_REST_Abilities::MAX_INDEX_ROUTES; $i++ ) {
	$GLOBALS['wpai119_rest']->routes[ '/heavy/v1/item-' . $i ] = array( $handler );
}
$before_index_calls = $GLOBALS['wpai119_rest']->index_calls;
$too_heavy = $provider->read( array( 'action' => 'list', 'per_page' => 1 ) );
wpai119_check( is_wp_error( $too_heavy ) && 'rest_routes_narrowing_required' === $too_heavy->get_error_code(), 'Oversized registry should request narrowing, not scan provider schemas or truncate totals.' );
wpai119_check( $before_index_calls === $GLOBALS['wpai119_rest']->index_calls, 'Large registry triggered public-index schema materialization despite work ceiling.' );
$small_namespace = $provider->read( array( 'action' => 'list', 'namespace' => 'wp/v2', 'per_page' => 1 ) );
wpai119_check( ! is_wp_error( $small_namespace ) && 1 === $small_namespace['total'] && $GLOBALS['wpai119_rest']->last_inspected_routes <= 1, 'Namespace filtering did not constrain the real index conversion.' );
$single_on_heavy = $provider->read( array( 'action' => 'get', 'route' => '/wp/v2/posts/(?P<id>[\d]+)' ) );
wpai119_check( ! is_wp_error( $single_on_heavy ) && 1 === $GLOBALS['wpai119_rest']->last_inspected_routes, 'Exact get normalized more than its one registered route schema.' );
$heavy_namespace = $provider->read( array( 'action' => 'list', 'namespace' => 'heavy/v1', 'per_page' => 1 ) );
wpai119_check( is_wp_error( $heavy_namespace ) && 'rest_routes_narrowing_required' === $heavy_namespace->get_error_code(), 'Over-budget namespace must fail closed with narrowing guidance.' );

$defs[ Settings::GROUP_REST_DISCOVERY ] = 0;
update_option( Settings::OPTION_NAME, $defs, false );
wpai119_check( is_wp_error( $provider->read( array( 'action' => 'get', 'route' => '/acme/v1/private' ) ) ), 'Revocation must take effect on the next read.' );

print "PASS: Issue #119 bounded REST discovery ({$assertions} assertions).\n";
