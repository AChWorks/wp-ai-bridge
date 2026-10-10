<?php
/**
 * Issue #119 independent high-trust native REST dispatch authorization contract.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Registered_REST_Invocation_Abilities;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Permissions;

$assertions = 0;
function wpai119invoke_assert( $condition, $label ) {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
}

final class WP_REST_Request {
	public $method;
	public $route;
	public $query = array();
	public $headers = array();
	public $body = '';

	public function __construct( $method, $route ) {
		$this->method = $method;
		$this->route  = $route;
	}
	public function set_query_params( $query ) { $this->query = $query; }
	public function set_header( $name, $value ) { $this->headers[ strtolower( $name ) ] = $value; }
	public function set_body( $value ) { $this->body = $value; }
}

final class WP_REST_Response {
	private $status;
	private $data;
	public function __construct( $data, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function get_status() { return $this->status; }
	public function get_data() { return $this->data; }
}

final class WP_AI_Bridge_REST_Invocation_Test_Server {
	public $routes = array();
	public $removed = array();
	public $visible_methods = array();
	public $removed_args = array();
	public $before_index = null;
	public $hidden_endpoint_methods = array();
	public function get_routes() { return $this->routes; }
	public function get_data_for_routes( $routes, $context = 'view' ) {
		if ( is_callable( $this->before_index ) ) {
			call_user_func( $this->before_index );
		}
		$output = array();
		foreach ( $routes as $route => $handlers ) {
			if ( in_array( $route, $this->removed, true ) ) {
				continue;
			}
			$methods   = array();
			$endpoints = array();
			foreach ( $handlers as $handler ) {
				if ( ! empty( $handler['show_in_index'] ) ) {
					$handler_methods = array_keys( $handler['methods'] );
					$handler_methods = array_values( array_diff( $handler_methods, $this->hidden_endpoint_methods[ $route ] ?? array() ) );
					$methods         = array_merge( $methods, $handler_methods );
					$args            = isset( $handler['args'] ) ? $handler['args'] : array();
					foreach ( $this->removed_args[ $route ] ?? array() as $name ) {
						unset( $args[ $name ] );
					}
					$endpoints[] = array( 'methods' => $handler_methods, 'args' => $args );
				}
			}
			if ( isset( $this->visible_methods[ $route ] ) ) {
				$methods = $this->visible_methods[ $route ];
			}
			if ( $methods ) {
				$output[ $route ] = array( 'methods' => $methods, 'endpoints' => $endpoints );
			}
		}
		return $output;
	}
}

$GLOBALS['wpai119invoke_server'] = new WP_AI_Bridge_REST_Invocation_Test_Server();
$GLOBALS['wpai119invoke_calls'] = array();
$GLOBALS['wpai119invoke_response'] = new WP_REST_Response( array( 'ok' => true ), 200 );
$GLOBALS['wpai119invoke_on_dispatch'] = null;

function rest_get_server() { return $GLOBALS['wpai119invoke_server']; }
function rest_do_request( $request ) {
	$GLOBALS['wpai119invoke_calls'][] = $request;
	if ( is_callable( $GLOBALS['wpai119invoke_on_dispatch'] ) ) {
		call_user_func( $GLOBALS['wpai119invoke_on_dispatch'], $request );
	}
	if ( $GLOBALS['wpai119invoke_response'] instanceof \Throwable ) {
		throw $GLOBALS['wpai119invoke_response'];
	}
	return $GLOBALS['wpai119invoke_response'];
}

wpai_test_reset_state();
$GLOBALS['wpai_test']['blog_id'] = 1;
function get_current_blog_id() { return $GLOBALS['wpai_test']['blog_id']; }
$settings = new Settings();
$provider = new Registered_REST_Invocation_Abilities( new Permissions( $settings ) );
$defaults = $settings->defaults();
wpai119invoke_assert( 0 === $defaults[ Settings::GROUP_REST_INVOCATION ], 'New high-trust grant must default OFF on fresh installations.' );
wpai119invoke_assert( 0 === $settings->all()[ Settings::GROUP_REST_INVOCATION ], 'Older stored settings must not inherit high-trust invocation.' );
$registered = $provider->register();
wpai119invoke_assert( 1 === count( $registered ), 'Expected one native Ability registration.' );
$ability = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/rest-route-invoke'];
wpai119invoke_assert( false === $ability['meta']['annotations']['readonly'] && true === $ability['meta']['annotations']['destructive'] && false === $ability['meta']['annotations']['idempotent'], 'Static mixed-effect metadata must require destructive/high-trust Gateway policy.' );
wpai119invoke_assert( false === $ability['input_schema']['additionalProperties'], 'REST input contract must be closed.' );
wpai119invoke_assert( ! $provider->can_invoke(), 'Native Abilities/Site Read/Discovery are not implicit broad REST grants.' );

$handler = array(
	'show_in_index' => true,
	'methods'       => array( 'GET' => true, 'POST' => true ),
	'args'          => array(
		'page'  => array( 'type' => 'integer' ),
		'title' => array( 'type' => 'string' ),
		'id'    => array( 'type' => 'integer' ),
	),
);
$server = $GLOBALS['wpai119invoke_server'];
$server->routes = array(
	'/acme/v1/data' => array( $handler ),
	'/acme/v1/item/(?P<id>[\d]+)' => array( $handler ),
	'/acme/v1/hidden' => array( array( 'show_in_index' => false, 'methods' => array( 'GET' => true ) ) ),
	'/wp-ai-bridge/v1/mcp' => array( $handler ),
	'/wp/v2/settings' => array( $handler ),
	'/wp/v2/users/(?P<id>[\d]+)' => array( $handler ),
	'/acme/v1/credentials' => array( $handler ),
);
$req = array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data', 'method' => 'GET', 'query' => array( 'page' => 2 ) );
wpai119invoke_assert( is_wp_error( $provider->invoke( $req ) ), 'Direct callback must deny without high-trust grant.' );
$defaults[ Settings::GROUP_REST_INVOCATION ] = 1;
update_option( Settings::OPTION_NAME, $defaults, false );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
wpai119invoke_assert( ! $provider->can_invoke(), 'Native administrative capability is also mandatory.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;

$result = $provider->invoke( $req );
wpai119invoke_assert( ! is_wp_error( $result ) && 'reported_success' === $result['outcome'] && 200 === $result['status'], 'Authorized GET should use the native REST dispatcher.' );
wpai119invoke_assert( 1 === count( $GLOBALS['wpai119invoke_calls'] ) && array( 'page' => 2 ) === $GLOBALS['wpai119invoke_calls'][0]->query, 'Native request must preserve query parameters without external HTTP.' );
wpai119invoke_assert( ! isset( $GLOBALS['wpai119invoke_calls'][0]->headers['authorization'] ), 'No custom Authorization header can be injected.' );
wpai119invoke_assert( ! is_wp_error( $provider->invoke( array( 'route' => '/acme/v1/item/(?P<id>[\d]+)', 'path' => '/acme/v1/item/24', 'method' => 'GET' ) ) ), 'Exact registered regex with concrete matching path should be supported.' );

foreach ( array(
	array( 'route' => '/acme/v1/data', 'path' => 'https://evil.test/wp-json/acme/v1/data', 'method' => 'GET' ),
	array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/../data', 'method' => 'GET' ),
	array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data?password=1', 'method' => 'GET' ),
	array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data', 'method' => 'HEAD' ),
	array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data', 'method' => 'GET', 'body' => array( 'x' => 1 ) ),
	array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data', 'method' => 'GET', 'query' => array( 'client_secret' => 'not-allowed' ) ),
	array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data', 'method' => 'POST', 'body' => array( 'nested' => array( 'api_key' => 'not-allowed' ) ) ),
	array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/other', 'method' => 'GET' ),
	array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data', 'method' => 'GET', 'host' => 'evil.test' ),
	array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data', 'method' => 'GET', 'query' => array( 'huge' => str_repeat( 'x', 20000 ) ) ),
	array( 'route' => '/wp-ai-bridge/v1/mcp', 'path' => '/wp-ai-bridge/v1/mcp', 'method' => 'POST' ),
	array( 'route' => '/wp/v2/settings', 'path' => '/wp/v2/settings', 'method' => 'GET' ),
	array( 'route' => '/wp/v2/users/(?P<id>[\d]+)', 'path' => '/wp/v2/users/1', 'method' => 'POST' ),
	array( 'route' => '/acme/v1/credentials', 'path' => '/acme/v1/credentials', 'method' => 'GET' ),
) as $invalid ) {
	wpai119invoke_assert( is_wp_error( $provider->invoke( $invalid ) ), 'Malicious/unrepresentable REST request passed boundary: ' . wp_json_encode( $invalid ) );
}
wpai119invoke_assert( 2 === count( $GLOBALS['wpai119invoke_calls'] ), 'Rejected requests must not reach Core dispatcher.' );

$hidden = $provider->invoke( array( 'route' => '/acme/v1/hidden', 'path' => '/acme/v1/hidden', 'method' => 'GET' ) );
wpai119invoke_assert( is_wp_error( $hidden ), 'Private show_in_index=false route must not become executable.' );
$server->removed[] = '/acme/v1/data';
wpai119invoke_assert( is_wp_error( $provider->invoke( $req ) ), 'Public index filter removal should block execution.' );
$server->removed = array();
$server->visible_methods['/acme/v1/data'] = array( 'GET' );
wpai119invoke_assert( is_wp_error( $provider->invoke( array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data', 'method' => 'POST' ) ) ), 'Public index filter removed POST; REST invocation cannot revive it.' );
$server->removed_args['/acme/v1/data'] = array( 'title' );
wpai119invoke_assert( is_wp_error( $provider->invoke( array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data', 'method' => 'POST', 'body' => array( 'title' => 'hidden' ) ) ) ), 'Provider public-index redaction must prevent hidden argument injection.' );
$server->removed_args = array();
$server->hidden_endpoint_methods['/acme/v1/data'] = array( 'POST' );
wpai119invoke_assert( is_wp_error( $provider->invoke( array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data', 'method' => 'POST' ) ) ), 'A removed public endpoint method must not reappear from the aggregate route method list.' );
$server->hidden_endpoint_methods = array();
wpai119invoke_assert( is_wp_error( $provider->invoke( array( 'route' => '/acme/v1/item/(?P<id>[\\d]+)', 'path' => '/acme/v1/item/24', 'method' => 'GET', 'query' => array( 'id' => 99 ) ) ) ), 'Query parameter cannot override a route capture used for native object authorization.' );

$server->visible_methods = array();

$server->routes['/acme/v1/(?P<slug>[a-z]+)'] = array( $handler );
wpai119invoke_assert( is_wp_error( $provider->invoke( $req ) ), 'Overlapping routes must fail closed, not dispatch the wrong native handler.' );
unset( $server->routes['/acme/v1/(?P<slug>[a-z]+)'] );

$GLOBALS['wpai119invoke_response'] = new WP_REST_Response( array( 'ok' => true, 'count' => 1 ), 201 );
$mutate = array( 'route' => '/acme/v1/data', 'path' => '/acme/v1/data', 'method' => 'POST', 'body' => array( 'title' => 'fixture' ) );
$result = $provider->invoke( $mutate );
wpai119invoke_assert( ! is_wp_error( $result ) && 'reported_success' === $result['outcome'] && 201 === $result['status'], 'Native successful mutation must report status without claiming idempotence.' );
$last = end( $GLOBALS['wpai119invoke_calls'] );
wpai119invoke_assert( 'application/json' === $last->headers['content-type'] && array( 'title' => 'fixture' ) === json_decode( $last->body, true ), 'Mutating input must preserve native typed JSON body.' );

$GLOBALS['wpai119invoke_response'] = new WP_REST_Response( array( 'error' => 'do-not-disclose-private-provider-error' ), 409 );
$result = $provider->invoke( $mutate );
wpai119invoke_assert( ! is_wp_error( $result ) && 'outcome_unknown' === $result['outcome'] && ! str_contains( wp_json_encode( $result ), 'do-not-disclose' ), 'Uncertain native mutation status must not expose provider details or imply retry safety.' );
$GLOBALS['wpai119invoke_response'] = new \RuntimeException( 'do-not-disclose-native-throwable' );
$result = $provider->invoke( $mutate );
wpai119invoke_assert( ! is_wp_error( $result ) && 'outcome_unknown' === $result['outcome'] && ! str_contains( wp_json_encode( $result ), 'do-not-disclose' ), 'Interrupted native mutation must preserve opaque outcome_unknown.' );

$GLOBALS['wpai119invoke_response'] = new WP_REST_Response( array( 'consumer_secret' => 'do-not-disclose' ), 200 );
$result = $provider->invoke( $mutate );
wpai119invoke_assert( ! is_wp_error( $result ) && 'outcome_unknown' === $result['outcome'], 'Potentially sensitive native output after mutation cannot be relayed.' );
$GLOBALS['wpai119invoke_response'] = new WP_REST_Response( array( 'large' => str_repeat( 'x', 20000 ) ), 200 );
$result = $provider->invoke( $mutate );
wpai119invoke_assert( ! is_wp_error( $result ) && 'outcome_unknown' === $result['outcome'], 'Large native output after mutation must signal uncertainty, not retry safety.' );

$GLOBALS['wpai119invoke_response'] = new WP_REST_Response( array( 'ok' => true ), 200 );
// Even a GET handler may have side effects. A thrown or truncated result
// must never be represented as a safe/idempotent read that can be replayed.
$GLOBALS['wpai119invoke_response'] = new \RuntimeException( 'confidential-read-side-effect' );
$get_unknown = $provider->invoke( $req );
wpai119invoke_assert( ! is_wp_error( $get_unknown ) && 'outcome_unknown' === $get_unknown['outcome'] && ! str_contains( wp_json_encode( $get_unknown ), 'confidential' ), 'Generic GET exceptions must preserve the same unknown mutation outcome.' );
$GLOBALS['wpai119invoke_response'] = new WP_REST_Response( array( 'ok' => true ), 200 );
$server->before_index = static function () use ( $provider, $req ) {
	$nested = $provider->invoke( $req );
	wpai119invoke_assert( is_wp_error( $nested ) && 'rest_invocation_recursive' === $nested->get_error_code(), 'Public-index preflight must reject nested execution.' );
};
$index_ok = $provider->invoke( $req );
wpai119invoke_assert( ! is_wp_error( $index_ok ) && 'reported_success' === $index_ok['outcome'], 'Outer route survives a refused nested preflight call.' );
$server->before_index = static function () {
	throw new RuntimeException( 'private-provider-index-message' );
};
$index_error = $provider->invoke( $req );
wpai119invoke_assert( ! is_wp_error( $index_error ) && 'outcome_unknown' === $index_error['outcome'] && ! str_contains( wp_json_encode( $index_error ), 'private-provider-index-message' ), 'Provider public-index exception must be safely redacted.' );
$server->before_index = null;
$before_identity_calls = count( $GLOBALS['wpai119invoke_calls'] );
$server->before_index = static function () {
	$GLOBALS['wpai_test']['user_id'] = 2;
};
$identity_switch = $provider->invoke( $req );
wpai119invoke_assert( is_wp_error( $identity_switch ) && 'rest_invocation_denied' === $identity_switch->get_error_code(), 'Preflight principal switching cannot authorize execution as another admin.' );
$GLOBALS['wpai_test']['user_id'] = 1;
$server->before_index = static function () {
	$GLOBALS['wpai_test']['blog_id'] = 2;
};
$site_switch = $provider->invoke( $req );
wpai119invoke_assert( is_wp_error( $site_switch ) && 'rest_invocation_denied' === $site_switch->get_error_code(), 'Preflight site switching cannot change execution Target.' );
$GLOBALS['wpai_test']['blog_id'] = 1;
$server->before_index = null;
wpai119invoke_assert( $before_identity_calls === count( $GLOBALS['wpai119invoke_calls'] ), 'A changed WordPress principal/site dispatched provider code.' );
$GLOBALS['wpai119invoke_on_dispatch'] = static function () use ( $provider, $req ) {
	$nested = $provider->invoke( $req );
	wpai119invoke_assert( is_wp_error( $nested ) && 'rest_invocation_recursive' === $nested->get_error_code(), 'Provider reentrancy must fail closed.' );
};
$result = $provider->invoke( $req );
wpai119invoke_assert( ! is_wp_error( $result ), 'Outer request should remain valid when nested invocation is rejected.' );
$GLOBALS['wpai119invoke_on_dispatch'] = null;

$defaults[ Settings::GROUP_REST_INVOCATION ] = 0;
update_option( Settings::OPTION_NAME, $defaults, false );
wpai119invoke_assert( is_wp_error( $provider->invoke( $req ) ), 'Revocation must deny the next execution.' );

print "PASS: Issue #119 guarded native REST invocation ({$assertions} assertions).\n";
