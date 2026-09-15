<?php
/**
 * Dependency-free replay/preemption provenance tests for Issue #61 / F-003.
 *
 * @package WP_Native_Builder_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Secure_Application_Password_Abilities;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

$GLOBALS['wpnb61_f003_filters']       = array();
$GLOBALS['wpnb61_f003_requests']      = array();
$GLOBALS['wpnb61_f003_sequence']      = 0;
$GLOBALS['wpnb61_f003_nested_uuids']  = array();
$GLOBALS['wpnb61_f003_delete_routes'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wpnb61_f003_filters'][ $hook ][] = array(
			'callback'      => $callback,
			'priority'      => (int) $priority,
			'accepted_args' => (int) $accepted_args,
		);
		usort(
			$GLOBALS['wpnb61_f003_filters'][ $hook ],
			static function ( $left, $right ) {
				return $left['priority'] <=> $right['priority'];
			}
		);
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['wpnb61_f003_filters'][ $hook ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['wpnb61_f003_filters'][ $hook ] as $index => $registered ) {
			if ( $registered['callback'] === $callback && (int) $registered['priority'] === (int) $priority ) {
				unset( $GLOBALS['wpnb61_f003_filters'][ $hook ][ $index ] );
				$GLOBALS['wpnb61_f003_filters'][ $hook ] = array_values( $GLOBALS['wpnb61_f003_filters'][ $hook ] );
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['wpnb_test']['actions'][ $hook ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['wpnb_test']['actions'][ $hook ] as $index => $registered ) {
			if ( $registered === $callback ) {
				unset( $GLOBALS['wpnb_test']['actions'][ $hook ][ $index ] );
				$GLOBALS['wpnb_test']['actions'][ $hook ] = array_values( $GLOBALS['wpnb_test']['actions'][ $hook ] );
				return true;
			}
		}
		return false;
	}
}

function wpnb61_f003_fire_filter( $hook, $value, ...$args ) {
	foreach ( array_values( $GLOBALS['wpnb61_f003_filters'][ $hook ] ?? array() ) as $registered ) {
		$call_args = array_slice( array_merge( array( $value ), $args ), 0, max( 1, $registered['accepted_args'] ) );
		$value     = call_user_func_array( $registered['callback'], $call_args );
	}
	return $value;
}

function wpnb61_f003_fire_action( $hook, ...$args ) {
	foreach ( array_values( $GLOBALS['wpnb_test']['actions']['all'] ?? array() ) as $callback ) {
		call_user_func_array( $callback, array_merge( array( $hook ), $args ) );
	}
	foreach ( array_values( $GLOBALS['wpnb_test']['actions'][ $hook ] ?? array() ) as $callback ) {
		call_user_func_array( $callback, $args );
	}
}

$failures = 0;
$tests    = 0;
function wpnb61_f003_assert( $condition, $message ) {
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
		public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	}
}

final class WP_AI_Bridge_Issue61_F003_Response {
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

if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	class WP_Application_Passwords {
		const USERMETA_KEY_APPLICATION_PASSWORDS = '_application_passwords';
		public static $storage = array();

		public static function get_user_application_passwords( $user_id ) {
			return array_values( self::$storage[ (int) $user_id ] ?? array() );
		}

		public static function get_user_application_password( $user_id, $uuid ) {
			foreach ( self::get_user_application_passwords( $user_id ) as $item ) {
				if ( $item['uuid'] === $uuid ) {
					return $item;
				}
			}
			return null;
		}

		public static function create_new_application_password( $user_id, $args = array() ) {
			++$GLOBALS['wpnb61_f003_sequence'];
			$sequence = $GLOBALS['wpnb61_f003_sequence'];
			$uuid     = sprintf( '11111111-2222-4333-8444-%012d', $sequence );
			$secret   = sprintf( 'secret-%018d', $sequence );
			$item     = array(
				'uuid'      => $uuid,
				'app_id'    => isset( $args['app_id'] ) ? (string) $args['app_id'] : '',
				'name'      => isset( $args['name'] ) ? (string) $args['name'] : '',
				'password'  => 'hash-' . hash( 'sha256', $secret ),
				'created'   => 1789470000 + $sequence,
				'last_used' => null,
				'last_ip'   => null,
			);
			$proposed   = self::get_user_application_passwords( $user_id );
			$proposed[] = $item;
			$check      = wpnb61_f003_fire_filter( 'update_user_metadata', null, $user_id, self::USERMETA_KEY_APPLICATION_PASSWORDS, $proposed, '' );
			if ( null !== $check && ! $check ) {
				return new WP_Error( 'db_error', 'Mock metadata write rejected.' );
			}
			self::$storage[ (int) $user_id ] = array();
			foreach ( $proposed as $stored ) {
				self::$storage[ (int) $user_id ][ $stored['uuid'] ] = $stored;
			}
			wpnb61_f003_fire_action( 'wp_create_application_password', $user_id, $item, $secret, $args );
			return array( $secret, $item );
		}

		public static function delete_application_password( $user_id, $uuid ) {
			if ( isset( self::$storage[ (int) $user_id ][ $uuid ] ) ) {
				unset( self::$storage[ (int) $user_id ][ $uuid ] );
				return true;
			}
			return new WP_Error( 'application_password_not_found', 'Not found.' );
		}
	}
}

if ( ! class_exists( 'WP_REST_Application_Passwords_Controller' ) ) {
	class WP_REST_Application_Passwords_Controller {
		public function create_item( $request ) {
			$args = array( 'name' => $request->params['name'] );
			if ( ! empty( $request->params['app_id'] ) ) {
				$args['app_id'] = $request->params['app_id'];
			}
			$created = WP_Application_Passwords::create_new_application_password( 7, $args );
			if ( is_wp_error( $created ) ) {
				return new WP_AI_Bridge_Issue61_F003_Response( array(), $created );
			}
			if ( 'F002 post-persistence error' === $request->params['name'] ) {
				return new WP_AI_Bridge_Issue61_F003_Response( array(), new WP_Error( 'issue61_f002_injected', 'Injected post-persistence failure.' ) );
			}
			$item                 = WP_Application_Passwords::get_user_application_password( 7, $created[1]['uuid'] );
			$item['new_password'] = $created[0];
			wpnb61_f003_fire_action( 'rest_after_insert_application_password', $item, $request, true );
			$response_item             = $item;
			$response_item['password'] = $created[0];
			unset( $response_item['new_password'] );
			return new WP_AI_Bridge_Issue61_F003_Response( $response_item );
		}
	}
}

function rest_do_request( $request ) {
	$GLOBALS['wpnb61_f003_requests'][] = array( 'method' => $request->method, 'route' => $request->route );
	$base = '/wp/v2/users/7/application-passwords';
	if ( 'POST' === $request->method && $base === $request->route ) {
		$controller = new WP_REST_Application_Passwords_Controller();
		return $controller->create_item( $request );
	}
	if ( 'DELETE' === $request->method && 0 === strpos( $request->route, $base . '/' ) ) {
		$uuid = substr( $request->route, strlen( $base ) + 1 );
		$GLOBALS['wpnb61_f003_delete_routes'][] = $request->route;
		$deleted = WP_Application_Passwords::delete_application_password( 7, $uuid );
		if ( is_wp_error( $deleted ) ) {
			return new WP_AI_Bridge_Issue61_F003_Response( array(), $deleted );
		}
		return new WP_AI_Bridge_Issue61_F003_Response( array( 'deleted' => true ) );
	}
	return new WP_AI_Bridge_Issue61_F003_Response( array(), new WP_Error( 'unexpected_route', 'Unexpected route.' ) );
}

wpnb_test_reset_state();
$settings = new Settings();
$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = $settings->defaults();
$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_AUTHENTICATION ] = 1;
$provider = new Secure_Application_Password_Abilities( new Permissions( $settings ), new Mutation_Log() );

$baseline = WP_Application_Passwords::create_new_application_password( 7, array( 'name' => 'baseline' ) );
wpnb61_f003_assert( ! is_wp_error( $baseline ), 'Could not create dependency-free baseline credential.' );
$baseline_uuid = $baseline[1]['uuid'];

$success = $provider->create( array( 'user_id' => 7, 'name' => 'normal secure create' ) );
wpnb61_f003_assert( ! is_wp_error( $success ) && ! empty( $success['password'] ), 'Secure create failed in the normal dependency-free path.' );
$success_uuid = $success['item']['uuid'];
wpnb61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( 7, $success_uuid ) ), 'Normal secure create did not persist its credential.' );
WP_Application_Passwords::delete_application_password( 7, $success_uuid );

$f002_before = count( $GLOBALS['wpnb61_f003_delete_routes'] );
$f002 = $provider->create( array( 'user_id' => 7, 'name' => 'F002 post-persistence error' ) );
wpnb61_f003_assert( is_wp_error( $f002 ) && 'issue61_f002_injected' === $f002->get_error_code(), 'Secure create did not preserve bounded F-002 cleanup behavior.' );
wpnb61_f003_assert( $f002_before + 1 === count( $GLOBALS['wpnb61_f003_delete_routes'] ), 'F-002 dependency-free path did not perform one exact cleanup DELETE.' );
wpnb61_f003_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( 7 ) ), 'F-002 cleanup did not restore baseline state.' );

$all_guard         = false;
$all_outer_uuid    = '';
$all_nested_uuid   = '';
$all_outer_secret  = '';
$all_nested_secret = '';
$all_callback = static function ( $hook_name, ...$args ) use ( &$all_guard, &$all_outer_uuid, &$all_nested_uuid, &$all_outer_secret, &$all_nested_secret ) {
	if ( 'wp_create_application_password' !== $hook_name || $all_guard || count( $args ) < 4 || 'F003 all outer' !== ( $args[3]['name'] ?? '' ) ) {
		return;
	}
	wpnb61_f003_assert( ! array_key_exists( '__wp_ai_bridge_create_correlation', $args[3] ), 'Secure provider exposed the retired public correlation token.' );
	$all_outer_uuid   = $args[1]['uuid'];
	$all_outer_secret = $args[2];
	$all_guard        = true;
	try {
		$nested = WP_Application_Passwords::create_new_application_password( 7, array( 'name' => 'F003 all nested' ) );
		$all_nested_secret = $nested[0];
		$all_nested_uuid   = $nested[1]['uuid'];
	} finally {
		$all_guard = false;
	}
	throw new RuntimeException( 'all unsafe details' );
};
add_action( 'all', $all_callback, PHP_INT_MIN, 99 );
$all_delete_before = count( $GLOBALS['wpnb61_f003_delete_routes'] );
$all_result = $provider->create( array( 'user_id' => 7, 'name' => 'F003 all outer' ) );
remove_action( 'all', $all_callback, PHP_INT_MIN );
wpnb61_f003_assert( is_wp_error( $all_result ) && 'application_password_create_recovery_required' === $all_result->get_error_code(), 'Global all preemption did not return recovery-required.' );
wpnb61_f003_assert( null === WP_Application_Passwords::get_user_application_password( 7, $all_outer_uuid ), 'Global all preemption left the genuine outer credential.' );
wpnb61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( 7, $all_nested_uuid ) ), 'Global all nested credential became destructive cleanup authority.' );
wpnb61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( 7, $baseline_uuid ) ), 'Global all preemption revoked the baseline credential.' );
wpnb61_f003_assert( $all_delete_before + 1 === count( $GLOBALS['wpnb61_f003_delete_routes'] ), 'Global all preemption performed anything other than one exact outer cleanup.' );
wpnb61_f003_assert( false !== strpos( $GLOBALS['wpnb61_f003_delete_routes'][ $all_delete_before ], $all_outer_uuid ), 'Global all cleanup did not target the genuine outer UUID.' );
wpnb61_f003_assert( false === strpos( $all_result->get_error_message(), 'unsafe details' ), 'Global all throwable details leaked.' );
WP_Application_Passwords::delete_application_password( 7, $all_nested_uuid );

$same_guard         = false;
$same_outer_uuid    = '';
$same_nested_uuid   = '';
$same_outer_secret  = '';
$same_nested_secret = '';
$same_callback = static function ( $user_id, $item, $new_password, $args ) use ( &$same_guard, &$same_outer_uuid, &$same_nested_uuid, &$same_outer_secret, &$same_nested_secret ) {
	if ( $same_guard || 7 !== (int) $user_id || 'F003 same outer' !== ( $args['name'] ?? '' ) ) {
		return;
	}
	wpnb61_f003_assert( ! array_key_exists( '__wp_ai_bridge_create_correlation', $args ), 'Same-priority path exposed the retired public correlation token.' );
	$same_outer_uuid   = $item['uuid'];
	$same_outer_secret = $new_password;
	$same_guard        = true;
	try {
		$nested = WP_Application_Passwords::create_new_application_password( 7, array( 'name' => 'F003 same nested' ) );
		$same_nested_secret = $nested[0];
		$same_nested_uuid   = $nested[1]['uuid'];
	} finally {
		$same_guard = false;
	}
	throw new RuntimeException( 'same unsafe details' );
};
add_action( 'wp_create_application_password', $same_callback, PHP_INT_MIN, 4 );
$same_delete_before = count( $GLOBALS['wpnb61_f003_delete_routes'] );
$same_result = $provider->create( array( 'user_id' => 7, 'name' => 'F003 same outer' ) );
remove_action( 'wp_create_application_password', $same_callback, PHP_INT_MIN );
wpnb61_f003_assert( is_wp_error( $same_result ) && 'application_password_create_recovery_required' === $same_result->get_error_code(), 'Same-priority preemption did not return recovery-required.' );
wpnb61_f003_assert( null === WP_Application_Passwords::get_user_application_password( 7, $same_outer_uuid ), 'Same-priority preemption left the genuine outer credential.' );
wpnb61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( 7, $same_nested_uuid ) ), 'Same-priority nested credential became destructive cleanup authority.' );
wpnb61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( 7, $baseline_uuid ) ), 'Same-priority preemption revoked the baseline credential.' );
wpnb61_f003_assert( $same_delete_before + 1 === count( $GLOBALS['wpnb61_f003_delete_routes'] ), 'Same-priority preemption performed anything other than one exact outer cleanup.' );
wpnb61_f003_assert( false !== strpos( $GLOBALS['wpnb61_f003_delete_routes'][ $same_delete_before ], $same_outer_uuid ), 'Same-priority cleanup did not target the genuine outer UUID.' );
wpnb61_f003_assert( false === strpos( $same_result->get_error_message(), 'unsafe details' ), 'Same-priority throwable details leaked.' );

$log_blob = wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
foreach ( array( $all_outer_secret, $all_nested_secret, $same_outer_secret, $same_nested_secret, $all_outer_uuid, $all_nested_uuid, $same_outer_uuid, $same_nested_uuid, '__wp_ai_bridge_create_correlation' ) as $sensitive ) {
	wpnb61_f003_assert( '' === $sensitive || false === strpos( $log_blob, $sensitive ), 'F-003 dependency-free log leaked credential provenance.' );
}

$secure_source = file_get_contents( dirname( __DIR__ ) . '/src/Abilities/class-secure-application-password-abilities.php' );
wpnb61_f003_assert( false === strpos( $secure_source, 'rest_pre_insert_application_password' ), 'Secure provider still depends on the replayable pre-insert correlation path.' );
wpnb61_f003_assert( false === strpos( $secure_source, 'CREATE_CORRELATION_ARG' ), 'Secure provider still defines a public-action correlation token.' );
wpnb61_f003_assert( false === strpos( $secure_source, "add_action( 'wp_create_application_password'" ), 'Secure provider still observes public create action for cleanup provenance.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "Issue #61 F-003 tests: {$failures}/{$tests} failed.\n" );
	exit( 1 );
}

echo "PASS: Issue #61 F-003 replay/preemption provenance ({$tests} assertions).\n";
