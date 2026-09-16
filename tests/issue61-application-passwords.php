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

$GLOBALS['wpnb61_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wpnb61_filters'][ $hook ][] = array(
			'callback'      => $callback,
			'priority'      => (int) $priority,
			'accepted_args' => (int) $accepted_args,
		);
		usort(
			$GLOBALS['wpnb61_filters'][ $hook ],
			static function ( $left, $right ) {
				return $left['priority'] <=> $right['priority'];
			}
		);
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['wpnb61_filters'][ $hook ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['wpnb61_filters'][ $hook ] as $index => $registered ) {
			if ( $registered['callback'] === $callback && (int) $registered['priority'] === (int) $priority ) {
				unset( $GLOBALS['wpnb61_filters'][ $hook ][ $index ] );
				$GLOBALS['wpnb61_filters'][ $hook ] = array_values( $GLOBALS['wpnb61_filters'][ $hook ] );
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		static $sequence = 0;
		++$sequence;
		return sprintf( '00000000-0000-4000-8000-%012d', $sequence );
	}
}

function wpnb61_fire_filter( $hook, $value, ...$args ) {
	foreach ( array_values( $GLOBALS['wpnb61_filters'][ $hook ] ?? array() ) as $registered ) {
		$call_args = array_slice( array_merge( array( $value ), $args ), 0, max( 1, $registered['accepted_args'] ) );
		$value     = call_user_func_array( $registered['callback'], $call_args );
	}
	return $value;
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['wpai_test']['actions'][ $hook ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['wpai_test']['actions'][ $hook ] as $index => $registered ) {
			if ( $registered === $callback ) {
				unset( $GLOBALS['wpai_test']['actions'][ $hook ][ $index ] );
				$GLOBALS['wpai_test']['actions'][ $hook ] = array_values( $GLOBALS['wpai_test']['actions'][ $hook ] );
				return true;
			}
		}
		return false;
	}
}

function wpnb61_fire_action( $hook, ...$args ) {
	foreach ( array_values( $GLOBALS['wpai_test']['actions'][ $hook ] ?? array() ) as $callback ) {
		call_user_func_array( $callback, $args );
	}
}

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
$GLOBALS['wpnb61_secret']           = 'abcd EFGH ijkl MNOP 1234 5678';
$GLOBALS['wpnb61_substituted_uuid'] = 'aaaaaaaa-1111-2222-3333-bbbbbbbbbbbb';
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
		$prepared = (object) array( 'name' => $request->params['name'] );
		if ( ! empty( $request->params['app_id'] ) ) {
			$prepared->app_id = $request->params['app_id'];
		}
		$prepared = wpnb61_fire_filter( 'rest_pre_insert_application_password', $prepared, $request );
		if ( is_wp_error( $prepared ) ) {
			return new WP_AI_Bridge_Issue61_Test_Response( array(), $prepared );
		}
		$item         = $GLOBALS['wpnb61_item'];
		$item['name'] = $request->params['name'];
		$args         = (array) $prepared;
		if ( 'Unobservable create' !== $request->params['name'] ) {
			wpnb61_fire_action( 'wp_create_application_password', 7, $item, $GLOBALS['wpnb61_secret'], $args );
		}
		if ( 'Ambiguous persisted creates' === $request->params['name'] ) {
			$second_item         = $item;
			$second_item['uuid'] = '22222222-3333-4444-5555-666666666666';
			wpnb61_fire_action( 'wp_create_application_password', 7, $second_item, 'second generated secret', $args );
			return new WP_AI_Bridge_Issue61_Test_Response( array(), new WP_Error( 'issue61_ambiguous_create', 'Ambiguous nested create.' ) );
		}
		if ( 'Persisted then WP_Error' === $request->params['name'] ) {
			return new WP_AI_Bridge_Issue61_Test_Response( array(), new WP_Error( 'issue61_f002_injected', 'Injected post-persistence failure: ' . $GLOBALS['wpnb61_secret'] . '|' . $GLOBALS['wpnb61_item']['password'] . '|' . $GLOBALS['wpnb61_item']['uuid'] . '|' . $GLOBALS['wpnb61_item']['app_id'], array( 'status' => 409, 'unsafe_payload' => $GLOBALS['wpnb61_item'] ) ) );
		}
		if ( 'Persisted then exception' === $request->params['name'] ) {
			throw new RuntimeException( 'Injected post-persistence exception with unsafe details.' );
		}
		$item['new_password'] = $GLOBALS['wpnb61_secret'];
		if ( 'Unobservable create' !== $request->params['name'] ) {
			wpnb61_fire_action( 'rest_after_insert_application_password', $item, $request, true );
		}
		$item['password'] = $GLOBALS['wpnb61_secret'];
		if ( 'Invalid shape' === $request->params['name'] ) {
			unset( $item['name'] );
		} elseif ( 'Missing password' === $request->params['name'] ) {
			unset( $item['password'] );
		} elseif ( 'Substituted password' === $request->params['name'] ) {
			$item['password'] = 'filtered replacement secret';
		} elseif ( 'Substituted UUID invalid shape' === $request->params['name'] ) {
			$item['uuid'] = $GLOBALS['wpnb61_substituted_uuid'];
			unset( $item['name'] );
		} elseif ( 'Substituted UUID valid shape' === $request->params['name'] ) {
			$item['uuid'] = $GLOBALS['wpnb61_substituted_uuid'];
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

wpai_test_reset_state();
$settings = new Settings();
wpnb61_assert( 0 === $settings->defaults()[ Settings::GROUP_AUTHENTICATION ], 'Authentication & Credentials must default off.' );
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] = array(
	Settings::GROUP_USERS_DESTRUCTIVE => 1,
	Settings::GROUP_ADVANCED_METADATA => 1,
);
wpnb61_assert( 0 === $settings->all()[ Settings::GROUP_AUTHENTICATION ], 'Historical elevated grants must not silently enable Authentication & Credentials.' );

$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] = $settings->defaults();
$GLOBALS['wpai_test']['capabilities']['read']             = true;
$provider   = new Application_Password_Abilities( new Permissions( $settings ), new Mutation_Log() );
$registered = $provider->register();
wpnb61_assert( 5 === count( $registered ), 'Exactly five bounded Application Password abilities are registered.' );
foreach ( array( 'application-passwords-read', 'application-password-create', 'application-password-update', 'application-password-delete', 'application-passwords-delete-all' ) as $name ) {
	wpnb61_assert( isset( $GLOBALS['wpai_test']['registered_abilities'][ 'wp-ai-bridge/' . $name ] ), $name . ' is registered.' );
}

$read_args = array( 'action' => 'list', 'user_id' => 7 );
wpnb61_assert( false === $provider->can_access( $read_args ), 'Default-off Authentication & Credentials denied coarse access.' );
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_AUTHENTICATION ] = 1;
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

$requests_before_missing_password = count( $GLOBALS['wpnb61_rest_requests'] );
$missing_password = $provider->create( array( 'user_id' => 7, 'name' => 'Missing password' ) );
wpnb61_assert( is_wp_error( $missing_password ) && 'application_passwords_rest_invalid_response' === $missing_password->get_error_code(), 'Missing create-response password did not fail after exact cleanup.' );
$missing_password_requests = array_slice( $GLOBALS['wpnb61_rest_requests'], $requests_before_missing_password );
wpnb61_assert( 2 === count( $missing_password_requests ) && 'DELETE' === $missing_password_requests[1]['method'], 'Missing create-response password did not trigger exact cleanup.' );
wpnb61_assert( '/wp/v2/users/7/application-passwords/' . $GLOBALS['wpnb61_item']['uuid'] === $missing_password_requests[1]['route'], 'Missing-password cleanup did not use the pre-response Core identity.' );

$requests_before_substituted_password = count( $GLOBALS['wpnb61_rest_requests'] );
$substituted_password = $provider->create( array( 'user_id' => 7, 'name' => 'Substituted password' ) );
wpnb61_assert( is_wp_error( $substituted_password ) && 'application_passwords_rest_invalid_response' === $substituted_password->get_error_code(), 'Substituted create-response password was accepted.' );
$substituted_password_requests = array_slice( $GLOBALS['wpnb61_rest_requests'], $requests_before_substituted_password );
wpnb61_assert( 2 === count( $substituted_password_requests ) && 'DELETE' === $substituted_password_requests[1]['method'] && '/wp/v2/users/7/application-passwords/' . $GLOBALS['wpnb61_item']['uuid'] === $substituted_password_requests[1]['route'], 'Substituted create-response password did not clean up the exact created credential.' );

$requests_before_substituted = count( $GLOBALS['wpnb61_rest_requests'] );
$substituted = $provider->create( array( 'user_id' => 7, 'name' => 'Substituted UUID invalid shape' ) );
wpnb61_assert( is_wp_error( $substituted ) && 'application_passwords_rest_invalid_response' === $substituted->get_error_code(), 'Substituted response UUID did not fail closed.' );
$substituted_requests = array_slice( $GLOBALS['wpnb61_rest_requests'], $requests_before_substituted );
wpnb61_assert( 2 === count( $substituted_requests ) && '/wp/v2/users/7/application-passwords/' . $GLOBALS['wpnb61_item']['uuid'] === $substituted_requests[1]['route'], 'Substituted response UUID redirected cleanup away from the actual created credential.' );
wpnb61_assert( false === strpos( $substituted_requests[1]['route'], $GLOBALS['wpnb61_substituted_uuid'] ), 'Cleanup trusted the mutable response UUID.' );

$requests_before_valid_substitution = count( $GLOBALS['wpnb61_rest_requests'] );
$valid_substitution = $provider->create( array( 'user_id' => 7, 'name' => 'Substituted UUID valid shape' ) );
wpnb61_assert( is_wp_error( $valid_substitution ) && 'application_passwords_rest_invalid_response' === $valid_substitution->get_error_code(), 'Valid-shaped substituted response UUID was accepted as the created identity.' );
$valid_substitution_requests = array_slice( $GLOBALS['wpnb61_rest_requests'], $requests_before_valid_substitution );
wpnb61_assert( 2 === count( $valid_substitution_requests ) && '/wp/v2/users/7/application-passwords/' . $GLOBALS['wpnb61_item']['uuid'] === $valid_substitution_requests[1]['route'], 'Valid-shaped UUID substitution redirected cleanup away from the actual created credential.' );

$requests_before_persisted_error = count( $GLOBALS['wpnb61_rest_requests'] );
$persisted_error = $provider->create( array( 'user_id' => 7, 'name' => 'Persisted then WP_Error' ) );
wpnb61_assert( is_wp_error( $persisted_error ) && 'application_passwords_rest_request_failed' === $persisted_error->get_error_code(), 'Post-persistence REST error was not normalized after exact cleanup.' );
$persisted_error_blob = wp_json_encode( array( $persisted_error->get_error_code(), $persisted_error->get_error_message(), method_exists( $persisted_error, 'get_error_data' ) ? $persisted_error->get_error_data() : null ) );
foreach ( array( 'issue61_f002_injected', $GLOBALS['wpnb61_secret'], $GLOBALS['wpnb61_item']['password'], $GLOBALS['wpnb61_item']['uuid'], $GLOBALS['wpnb61_item']['app_id'], 'Injected post-persistence failure' ) as $unsafe ) {
	wpnb61_assert( false === strpos( $persisted_error_blob, $unsafe ), 'Post-persistence normalized error leaked downstream credential/error material.' );
}
$persisted_error_requests = array_slice( $GLOBALS['wpnb61_rest_requests'], $requests_before_persisted_error );
wpnb61_assert( 2 === count( $persisted_error_requests ) && 'POST' === $persisted_error_requests[0]['method'] && 'DELETE' === $persisted_error_requests[1]['method'], 'Post-persistence REST error did not trigger exact cleanup.' );
wpnb61_assert( '/wp/v2/users/7/application-passwords/' . $GLOBALS['wpnb61_item']['uuid'] === $persisted_error_requests[1]['route'], 'Post-persistence REST error cleanup targeted the wrong credential.' );

$requests_before_persisted_exception = count( $GLOBALS['wpnb61_rest_requests'] );
$persisted_exception = $provider->create( array( 'user_id' => 7, 'name' => 'Persisted then exception' ) );
wpnb61_assert( is_wp_error( $persisted_exception ) && 'application_passwords_rest_request_failed' === $persisted_exception->get_error_code(), 'Post-persistence exception did not become a bounded REST failure after exact cleanup.' );
wpnb61_assert( false === strpos( $persisted_exception->get_error_message(), 'unsafe details' ), 'Post-persistence exception details leaked through the bounded error.' );
$persisted_exception_requests = array_slice( $GLOBALS['wpnb61_rest_requests'], $requests_before_persisted_exception );
wpnb61_assert( 2 === count( $persisted_exception_requests ) && 'DELETE' === $persisted_exception_requests[1]['method'] && '/wp/v2/users/7/application-passwords/' . $GLOBALS['wpnb61_item']['uuid'] === $persisted_exception_requests[1]['route'], 'Post-persistence exception did not clean up the exact created credential.' );

$requests_before_ambiguous = count( $GLOBALS['wpnb61_rest_requests'] );
$ambiguous = $provider->create( array( 'user_id' => 7, 'name' => 'Ambiguous persisted creates' ) );
wpnb61_assert( is_wp_error( $ambiguous ) && 'application_password_create_recovery_required' === $ambiguous->get_error_code(), 'Ambiguous persisted-create correlation did not require explicit recovery.' );
$ambiguous_requests = array_slice( $GLOBALS['wpnb61_rest_requests'], $requests_before_ambiguous );
wpnb61_assert( 1 === count( $ambiguous_requests ) && 'POST' === $ambiguous_requests[0]['method'], 'Ambiguous persisted-create correlation guessed a destructive cleanup target.' );

$requests_before_unobservable = count( $GLOBALS['wpnb61_rest_requests'] );
$unobservable = $provider->create( array( 'user_id' => 7, 'name' => 'Unobservable create' ) );
wpnb61_assert( is_wp_error( $unobservable ) && 'application_password_create_recovery_required' === $unobservable->get_error_code(), 'Unobservable create did not return an explicit recovery-required error.' );
$unobservable_requests = array_slice( $GLOBALS['wpnb61_rest_requests'], $requests_before_unobservable );
wpnb61_assert( 1 === count( $unobservable_requests ) && 'POST' === $unobservable_requests[0]['method'], 'Unobservable create guessed a destructive cleanup target.' );

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
