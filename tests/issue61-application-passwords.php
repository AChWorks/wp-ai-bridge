<?php
/**
 * Dependency-free Application Password boundary tests for Issue #61.
 *
 * @package WP_Native_Builder_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Application_Password_Abilities;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

$failures = 0;
$tests    = 0;

function wpnb61_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public $method;
		public $route;
		public $params = array();

		public function __construct( $method, $route ) {
			$this->method = (string) $method;
			$this->route  = (string) $route;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}
	}
}

final class WP_AI_Bridge_Issue61_Test_Response {
	private $data;
	private $error;

	public function __construct( $data, $error = null ) {
		$this->data  = $data;
		$this->error = $error;
	}

	public function get_data() { return $this->data; }
	public function get_headers() { return array(); }
	public function is_error() { return $this->error instanceof WP_Error; }
	public function as_error() { return $this->error; }
}

$GLOBALS['wpnb61_rest_requests'] = array();
$GLOBALS['wpnb61_secret']        = 'abcd EFGH ijkl MNOP 1234 5678';
$GLOBALS['wpnb61_item']          = array(
	'uuid'      => '11111111-2222-3333-4444-555555555555',
	'app_id'    => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
	'name'      => 'Bridge test',
	'password'  => '$P$stored-hash-must-not-leak',
	'created'   => '2026-09-15T10:00:00',
	'last_used' => null,
	'last_ip'   => null,
);

function rest_do_request( $request ) {
	$GLOBALS['wpnb61_rest_requests'][] = array(
		'method' => $request->method,
		'route'  => $request->route,
		'params' => $request->params,
	);
	$base = '/wp/v2/users/7/application-passwords';
	$uuid = $GLOBALS['wpnb61_item']['uuid'];
	if ( 'GET' === $request->method && $base === $request->route ) {
		return new WP_AI_Bridge_Issue61_Test_Response( array( $GLOBALS['wpnb61_item'] ) );
	}
	if ( 'GET' === $request->method && $base . '/' . $uuid === $request->route ) {
		return new WP_AI_Bridge_Issue61_Test_Response( $GLOBALS['wpnb61_item'] );
	}
	if ( 'POST' === $request->method && $base === $request->route ) {
		$item             = $GLOBALS['wpnb61_item'];
		$item['name']     = $request->params['name'];
		$item['password'] = $GLOBALS['wpnb61_secret'];
		if ( 'Invalid shape' === $request->params['name'] ) {
			unset( $item['name'] );
		}
		return new WP_AI_Bridge_Issue61_Test_Response( $item );
	}
	if ( 'POST' === $request->method && $base . '/' . $uuid === $request->route ) {
		$item         = $GLOBALS['wpnb61_item'];
		$item['name'] = $request->params['name'];
		return new WP_AI_Bridge_Issue61_Test_Response( $item );
	}
	if ( 'DELETE' === $request->method && $base . '/' . $uuid === $request->route ) {
		return new WP_AI_Bridge_Issue61_Test_Response( array( 'deleted' => true, 'previous' => $GLOBALS['wpnb61_item'] ) );
	}
	if ( 'DELETE' === $request->method && $base === $request->route ) {
		return new WP_AI_Bridge_Issue61_Test_Response( array( 'deleted' => true, 'count' => 2 ) );
	}
	return new WP_AI_Bridge_Issue61_Test_Response( array(), new WP_Error( 'unexpected_route', 'Unexpected test REST request.' ) );
}

wpnb_test_reset_state();
$settings = new Settings();
wpnb61_assert( 0 === $settings->defaults()[ Settings::GROUP_AUTHENTICATION ], 'Authentication & Credentials must default off.' );
$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = array(
	Settings::GROUP_USERS_DESTRUCTIVE => 1,
	Settings::GROUP_ADVANCED_METADATA => 1,
);
wpnb61_assert( 0 === $settings->all()[ Settings::GROUP_AUTHENTICATION ], 'Historical elevated grants must not silently enable Authentication & Credentials.' );

$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = $settings->defaults();
$GLOBALS['wpnb_test']['capabilities']['read']             = true;
$provider   = new Application_Password_Abilities( new Permissions( $settings ), new Mutation_Log() );
$registered = $provider->register();
wpnb61_assert( 5 === count( $registered ), 'Exactly five bounded Application Password abilities are registered.' );
foreach ( array( 'application-passwords-read', 'application-password-create', 'application-password-update', 'application-password-delete', 'application-passwords-delete-all' ) as $name ) {
	wpnb61_assert( isset( $GLOBALS['wpnb_test']['registered_abilities'][ 'wp-native-builder/' . $name ] ), $name . ' is registered.' );
}

$read_args = array( 'action' => 'list', 'user_id' => 7 );
wpnb61_assert( false === $provider->can_access( $read_args ), 'Default-off Authentication & Credentials denied coarse access.' );
$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_AUTHENTICATION ] = 1;
wpnb61_assert( true === $provider->can_access( $read_args ), 'Enabled Authentication & Credentials permits dispatch to Core authority checks.' );

$request_count_before_invalid_action = count( $GLOBALS['wpnb61_rest_requests'] );
$invalid_action = $provider->read( array( 'action' => 'unexpected', 'user_id' => 7 ) );
wpnb61_assert( is_wp_error( $invalid_action ) && 'application_password_read_action_invalid' === $invalid_action->get_error_code(), 'Direct invalid read action did not fail closed.' );
wpnb61_assert( $request_count_before_invalid_action === count( $GLOBALS['wpnb61_rest_requests'] ), 'Invalid read action reached the Core REST dispatcher.' );

$list = $provider->read( $read_args );
wpnb61_assert( ! is_wp_error( $list ) && 1 === $list['count'], 'List normalizes one Application Password item.' );
wpnb61_assert( ! array_key_exists( 'password', $list['items'][0] ), 'List output excludes password/hash state.' );
wpnb61_assert( false === strpos( wp_json_encode( $list ), '$P$stored-hash-must-not-leak' ), 'Stored hash leaked from list output.' );

$uuid = $GLOBALS['wpnb61_item']['uuid'];
$get  = $provider->read( array( 'action' => 'get', 'user_id' => 7, 'uuid' => $uuid ) );
wpnb61_assert( ! is_wp_error( $get ) && 1 === $get['count'], 'Exact get uses the fixed item route.' );
wpnb61_assert( ! array_key_exists( 'password', $get['items'][0] ), 'Exact get excludes password/hash state.' );

$created = $provider->create( array( 'user_id' => 7, 'name' => 'Created test' ) );
wpnb61_assert( ! is_wp_error( $created ) && $GLOBALS['wpnb61_secret'] === $created['password'], 'Create returns the Core-generated credential exactly once.' );
wpnb61_assert( ! array_key_exists( 'password', $created['item'] ), 'Created item metadata does not duplicate the credential.' );

$requests_before_invalid_create = count( $GLOBALS['wpnb61_rest_requests'] );
$invalid_create = $provider->create( array( 'user_id' => 7, 'name' => 'Invalid shape' ) );
wpnb61_assert( is_wp_error( $invalid_create ) && 'application_passwords_rest_invalid_response' === $invalid_create->get_error_code(), 'Invalid Core create response did not fail after bounded cleanup.' );
$cleanup_requests = array_slice( $GLOBALS['wpnb61_rest_requests'], $requests_before_invalid_create );
wpnb61_assert( 2 === count( $cleanup_requests ) && 'POST' === $cleanup_requests[0]['method'] && 'DELETE' === $cleanup_requests[1]['method'], 'Invalid create response did not trigger exact Core revocation cleanup.' );
wpnb61_assert( '/wp/v2/users/7/application-passwords/' . $GLOBALS['wpnb61_item']['uuid'] === $cleanup_requests[1]['route'], 'Invalid create cleanup targeted the wrong Application Password identity.' );

$updated = $provider->update( array( 'user_id' => 7, 'uuid' => $uuid, 'name' => 'Renamed test' ) );
wpnb61_assert( ! is_wp_error( $updated ) && 'Renamed test' === $updated['name'], 'Update normalizes the renamed Core item.' );
wpnb61_assert( ! array_key_exists( 'password', $updated ), 'Update output excludes credential state.' );

$deleted = $provider->delete( array( 'user_id' => 7, 'uuid' => $uuid ) );
wpnb61_assert( ! is_wp_error( $deleted ) && true === $deleted['deleted'], 'Exact revoke returns a bounded confirmation.' );
wpnb61_assert( ! array_key_exists( 'previous', $deleted ), 'Exact revoke does not relay Core previous secret-bearing state.' );

$all_denied = $provider->delete_all( array( 'user_id' => 7, 'confirm' => 'wrong' ) );
wpnb61_assert( is_wp_error( $all_denied ), 'Revoke-all rejects an invalid confirmation token.' );
$all = $provider->delete_all( array( 'user_id' => 7, 'confirm' => 'revoke_all' ) );
wpnb61_assert( ! is_wp_error( $all ) && 2 === $all['count'], 'Explicit revoke-all returns only a bounded count.' );

$requests = $GLOBALS['wpnb61_rest_requests'];
foreach ( $requests as $request ) {
	wpnb61_assert( 1 === preg_match( '#^/wp/v2/users/7/application-passwords(?:/[A-Za-z0-9_-]{1,64})?$#', $request['route'] ), 'Provider dispatched outside the fixed Core route family.' );
	wpnb61_assert( in_array( $request['method'], array( 'GET', 'POST', 'DELETE' ), true ), 'Provider dispatched an unapproved REST method.' );
}

$log_json = wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
wpnb61_assert( false === strpos( $log_json, $GLOBALS['wpnb61_secret'] ), 'Mutation log captured the generated credential.' );
wpnb61_assert( false === strpos( $log_json, $uuid ), 'Mutation log captured an Application Password UUID.' );
wpnb61_assert( false === strpos( $log_json, '$P$stored-hash-must-not-leak' ), 'Mutation log captured a stored Application Password hash.' );

$source = file_get_contents( dirname( __DIR__ ) . '/src/Abilities/class-application-password-abilities.php' );
wpnb61_assert( false === strpos( $source, '_application_passwords' ), 'Purpose-specific provider directly references generic Application Password usermeta.' );
wpnb61_assert( false === strpos( $source, 'WP_Application_Passwords::' ), 'Provider bypasses the fixed Core REST permission contract.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "Issue #61 Application Password tests: {$failures}/{$tests} failed.\n" );
	exit( 1 );
}

echo "PASS: Issue #61 Application Password boundary ({$tests} assertions).\n";
