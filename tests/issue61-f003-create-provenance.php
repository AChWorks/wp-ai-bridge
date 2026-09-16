<?php
/**
 * Dependency-free persistence provenance tests for Issue #61 / F-003/F-004/F-005.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Secure_Application_Password_Abilities;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

$GLOBALS['wpnb61_f003_filters']       = array();
$GLOBALS['wpnb61_f003_requests']      = array();
$GLOBALS['wpnb61_f003_sequence']      = 0;
$GLOBALS['wpnb61_f003_nested_uuids']  = array();
$GLOBALS['wpnb61_f003_delete_routes']      = array();
$GLOBALS['wpnb61_f003_snapshot_reads']      = 0;
$GLOBALS['wpnb61_f003_permission_allowed']  = true;

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

function wpnb61_f003_fire_filter( $hook, $value, ...$args ) {
	foreach ( array_values( $GLOBALS['wpnb61_f003_filters'][ $hook ] ?? array() ) as $registered ) {
		$call_args = array_slice( array_merge( array( $value ), $args ), 0, max( 1, $registered['accepted_args'] ) );
		$value     = call_user_func_array( $registered['callback'], $call_args );
	}
	return $value;
}

function wpnb61_f003_fire_action( $hook, ...$args ) {
	foreach ( array_values( $GLOBALS['wpai_test']['actions']['all'] ?? array() ) as $callback ) {
		call_user_func_array( $callback, array_merge( array( $hook ), $args ) );
	}
	foreach ( array_values( $GLOBALS['wpai_test']['actions'][ $hook ] ?? array() ) as $callback ) {
		call_user_func_array( $callback, $args );
	}
}

if ( ! function_exists( 'update_metadata' ) ) {
	function update_metadata( $meta_type, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		$check = wpnb61_f003_fire_filter( 'update_' . $meta_type . '_metadata', null, $object_id, $meta_key, $meta_value, $prev_value );
		if ( null !== $check ) {
			return (bool) $check;
		}
		if ( 'user' !== $meta_type || WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS !== $meta_key || ! is_array( $meta_value ) ) {
			return false;
		}
		WP_Application_Passwords::$storage[ (int) $object_id ] = array();
		foreach ( $meta_value as $stored ) {
			WP_Application_Passwords::$storage[ (int) $object_id ][ $stored['uuid'] ] = $stored;
		}
		return true;
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $meta_key, $meta_value, $prev_value = '' ) {
		return update_metadata( 'user', $user_id, $meta_key, $meta_value, $prev_value );
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
			++$GLOBALS['wpnb61_f003_snapshot_reads'];
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
			$saved      = self::set_user_application_passwords( $user_id, $proposed );
			if ( ! $saved ) {
				return new WP_Error( 'db_error', 'Mock metadata write rejected.' );
			}
			wpnb61_f003_fire_action( 'wp_create_application_password', $user_id, $item, $secret, $args );
			return array( $secret, $item );
		}

		public static function delete_application_password( $user_id, $uuid ) {
			$passwords = self::get_user_application_passwords( $user_id );
			foreach ( $passwords as $key => $item ) {
				if ( $item['uuid'] !== $uuid ) {
					continue;
				}
				unset( $passwords[ $key ] );
				if ( ! self::set_user_application_passwords( $user_id, $passwords ) ) {
					return new WP_Error( 'db_error', 'Mock metadata delete rejected.' );
				}
				return true;
			}
			return new WP_Error( 'application_password_not_found', 'Not found.' );
		}

		protected static function set_user_application_passwords( $user_id, $passwords ) {
			return update_user_meta( $user_id, self::USERMETA_KEY_APPLICATION_PASSWORDS, $passwords );
		}
	}
}

if ( ! class_exists( 'WP_REST_Application_Passwords_Controller' ) ) {
	class WP_REST_Application_Passwords_Controller {
		public function create_item_permissions_check( $request ) {
			if ( 7 !== (int) $request->get_param( 'user_id' ) ) {
				return new WP_Error( 'rest_user_invalid_id', 'Invalid user ID.' );
			}
			if ( empty( $GLOBALS['wpnb61_f003_permission_allowed'] ) ) {
				return new WP_Error( 'rest_cannot_create_application_passwords', 'Not allowed.' );
			}
			return true;
		}

		public function create_item( $request ) {
			$prepared = (object) array( 'name' => $request->params['name'] );
			if ( ! empty( $request->params['app_id'] ) ) {
				$prepared->app_id = $request->params['app_id'];
			}
			$prepared = wpnb61_f003_fire_filter( 'rest_pre_insert_application_password', $prepared, $request );
			if ( is_wp_error( $prepared ) ) {
				return new WP_AI_Bridge_Issue61_F003_Response( array(), $prepared );
			}
			$args = (array) $prepared;
			$created = WP_Application_Passwords::create_new_application_password( 7, $args );
			if ( is_wp_error( $created ) ) {
				return new WP_AI_Bridge_Issue61_F003_Response( array(), $created );
			}
			if ( 'F002 post-persistence error' === $request->params['name'] || 0 === strpos( $request->params['name'], 'F005 ' ) ) {
				$unsafe = implode( '|', array( $created[0], $created[1]['password'], $created[1]['uuid'], $created[1]['app_id'] ) );
				return new WP_AI_Bridge_Issue61_F003_Response(
					array(),
					new WP_Error(
						'issue61_f002_injected',
						'Injected post-persistence failure: ' . $unsafe,
						array( 'status' => 409, 'unsafe_payload' => $created[1], 'new_password' => $created[0] )
					)
				);
			}
			$item                 = WP_Application_Passwords::get_user_application_password( 7, $created[1]['uuid'] );
			$item['new_password'] = $created[0];
			wpnb61_f003_fire_action( 'rest_after_insert_application_password', $item, $request, true );
			$response_item             = $item;
			$response_item['password'] = $created[0];
			unset( $response_item['new_password'] );
			return new WP_AI_Bridge_Issue61_F003_Response( $response_item );
		}

		public function delete_item( $request ) {
			$uuid = (string) $request->get_param( 'uuid' );
			$deleted = WP_Application_Passwords::delete_application_password( 7, $uuid );
			if ( is_wp_error( $deleted ) ) {
				return new WP_AI_Bridge_Issue61_F003_Response( array(), $deleted );
			}
			return new WP_AI_Bridge_Issue61_F003_Response( array( 'deleted' => true ) );
		}
	}
}

function rest_do_request( $request ) {
	$GLOBALS['wpnb61_f003_requests'][] = array( 'method' => $request->method, 'route' => $request->route );
	$base = '/wp/v2/users/7/application-passwords';
	$before = wpnb61_f003_fire_filter( 'rest_request_before_callbacks', null, array(), $request );
	if ( $before instanceof WP_AI_Bridge_Issue61_F003_Response ) {
		return $before;
	}
	if ( is_wp_error( $before ) ) {
		return new WP_AI_Bridge_Issue61_F003_Response( array(), $before );
	}
	if ( 'POST' === $request->method && $base === $request->route ) {
		$controller = new WP_REST_Application_Passwords_Controller();
		return $controller->create_item( $request );
	}
	if ( 'DELETE' === $request->method && 0 === strpos( $request->route, $base . '/' ) ) {
		$uuid = substr( $request->route, strlen( $base ) + 1 );
		$request->set_param( 'uuid', $uuid );
		$GLOBALS['wpnb61_f003_delete_routes'][] = $request->route;
		$controller = new WP_REST_Application_Passwords_Controller();
		return $controller->delete_item( $request );
	}
	return new WP_AI_Bridge_Issue61_F003_Response( array(), new WP_Error( 'unexpected_route', 'Unexpected route.' ) );
}

wpai_test_reset_state();
$settings = new Settings();
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] = $settings->defaults();
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_AUTHENTICATION ] = 1;
$provider = new Secure_Application_Password_Abilities( new Permissions( $settings ), new Mutation_Log() );

$GLOBALS['wpnb61_f003_permission_allowed'] = false;
$reads_before_denied = $GLOBALS['wpnb61_f003_snapshot_reads'];
$requests_before_denied = count( $GLOBALS['wpnb61_f003_requests'] );
$denied = $provider->create( array( 'user_id' => 7, 'name' => 'denied before snapshot' ) );
wpnb61_f003_assert( is_wp_error( $denied ) && 'rest_cannot_create_application_passwords' === $denied->get_error_code(), 'Create permission preflight did not preserve Core denial.' );
wpnb61_f003_assert( $reads_before_denied === $GLOBALS['wpnb61_f003_snapshot_reads'], 'Denied create inspected Application Password storage before Core authorization.' );
wpnb61_f003_assert( $requests_before_denied === count( $GLOBALS['wpnb61_f003_requests'] ), 'Denied create dispatched REST after preflight denial.' );
$GLOBALS['wpnb61_f003_permission_allowed'] = true;

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
wpnb61_f003_assert( is_wp_error( $f002 ) && 'application_passwords_rest_request_failed' === $f002->get_error_code(), 'Secure create did not normalize the F-002 downstream error.' );
$f002_blob = wp_json_encode( array( $f002->get_error_code(), $f002->get_error_message(), method_exists( $f002, 'get_error_data' ) ? $f002->get_error_data() : null ) );
foreach ( array( 'issue61_f002_injected', 'Injected post-persistence failure', 'secret-', 'hash-' ) as $unsafe ) {
	wpnb61_f003_assert( false === strpos( $f002_blob, $unsafe ), 'Secure F-002 normalized error leaked downstream credential/error material.' );
}
wpnb61_f003_assert( $f002_before + 1 === count( $GLOBALS['wpnb61_f003_delete_routes'] ), 'F-002 dependency-free path did not perform one exact cleanup DELETE.' );
wpnb61_f003_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( 7 ) ), 'F-002 cleanup did not restore baseline state.' );

/* F-004: a pre-existing same-key metadata callback re-enters before the genuine outer write reaches Bridge. */
$f004_guard         = false;
$f004_nested_uuid   = '44444444-5555-4666-8777-000000000004';
$f004_nested_secret = 'f004-provider-secret';
$f004_nested_app_id = '77777777-8888-4999-8aaa-cccccccccccc';
$f004_nested_hash   = 'hash-' . hash( 'sha256', $f004_nested_secret );
$f004_callback = static function ( $check, $user_id, $meta_key, $meta_value ) use ( &$f004_guard, $f004_nested_uuid, $f004_nested_app_id, $f004_nested_hash ) {
	if ( $f004_guard || 7 !== (int) $user_id || WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS !== $meta_key || ! is_array( $meta_value ) ) {
		return $check;
	}
	$outer = false;
	foreach ( $meta_value as $item ) {
		if ( is_array( $item ) && 'F004 outer create' === ( $item['name'] ?? '' ) ) {
			$outer = true;
			break;
		}
	}
	if ( ! $outer ) {
		return $check;
	}
	$f004_guard = true;
	try {
		$nested = WP_Application_Passwords::get_user_application_passwords( 7 );
		$nested[] = array(
			'uuid'      => $f004_nested_uuid,
			'app_id'    => $f004_nested_app_id,
			'name'      => 'F004 provider nested',
			'password'  => $f004_nested_hash,
			'created'   => 1789470404,
			'last_used' => null,
			'last_ip'   => null,
		);
		update_user_meta( 7, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $nested );
	} finally {
		$f004_guard = false;
	}
	throw new RuntimeException( 'F004 provider preemption unsafe details' );
};
add_filter( 'update_user_metadata', $f004_callback, 10, 4 );
$f004_delete_before = count( $GLOBALS['wpnb61_f003_delete_routes'] );
try {
	$f004_result = $provider->create(
		array(
			'user_id' => 7,
			'name'    => 'F004 outer create',
			'app_id'  => '88888888-9999-4aaa-8bbb-dddddddddddd',
		)
	);
} finally {
	remove_filter( 'update_user_metadata', $f004_callback, 10 );
}
wpnb61_f003_assert( is_wp_error( $f004_result ) && 'application_password_create_recovery_required' === $f004_result->get_error_code(), 'F-004 re-entrant metadata preemption did not require recovery.' );
wpnb61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( 7, $f004_nested_uuid ) ), 'F-004 provider-owned nested credential was deleted.' );
wpnb61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( 7, $baseline_uuid ) ), 'F-004 changed the baseline credential.' );
wpnb61_f003_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( 7 ) ), 'F-004 changed state beyond the provider-owned nested write.' );
wpnb61_f003_assert( $f004_delete_before === count( $GLOBALS['wpnb61_f003_delete_routes'] ), 'F-004 incorrectly attempted destructive cleanup without exact outer provenance.' );
$f004_blob = $f004_result->get_error_message() . wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
foreach ( array( $f004_nested_uuid, $f004_nested_secret, $f004_nested_hash, $f004_nested_app_id, '88888888-9999-4aaa-8bbb-dddddddddddd', 'unsafe details' ) as $sensitive ) {
	wpnb61_f003_assert( false === strpos( $f004_blob, $sensitive ), 'F-004 leaked provider/credential provenance.' );
}
WP_Application_Passwords::delete_application_password( 7, $f004_nested_uuid );
wpnb61_f003_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( 7 ) ), 'F-004 fixture cleanup did not restore baseline.' );

/* F-006: same-request nested REST create must not inherit outer cleanup provenance. */
$f006_guard         = false;
$f006_nested_uuid   = '';
$f006_nested_secret = '';
$f006_nested_app_id = '12121212-3434-4567-8abc-909090909090';
$f006_outer_app_id  = '23232323-4545-4678-8bcd-808080808080';
$f006_callback = static function ( $prepared, $request ) use ( &$f006_guard, &$f006_nested_uuid, &$f006_nested_secret, $f006_nested_app_id ) {
	if ( $f006_guard
		|| ! $request instanceof WP_REST_Request
		|| 'POST' !== $request->method
		|| 'F006 outer create' !== $request->get_param( 'name' ) ) {
		return $prepared;
	}

	$f006_guard = true;
	$outer_name = $request->get_param( 'name' );
	$outer_app  = $request->get_param( 'app_id' );
	try {
		$request->set_param( 'name', 'F006 provider nested' );
		$request->set_param( 'app_id', $f006_nested_app_id );
		$nested_response = rest_do_request( $request );
		$nested_data     = $nested_response->get_data();
		if ( is_array( $nested_data ) ) {
			$f006_nested_uuid   = isset( $nested_data['uuid'] ) && is_string( $nested_data['uuid'] ) ? $nested_data['uuid'] : '';
			$f006_nested_secret = isset( $nested_data['password'] ) && is_string( $nested_data['password'] ) ? $nested_data['password'] : '';
		}
	} finally {
		$request->set_param( 'name', $outer_name );
		$request->set_param( 'app_id', $outer_app );
		$f006_guard = false;
	}

	return new WP_Error( 'issue61_f006_abort', 'Injected same-request outer abort unsafe details.' );
};
add_filter( 'rest_pre_insert_application_password', $f006_callback, 10, 2 );
$f006_delete_before = count( $GLOBALS['wpnb61_f003_delete_routes'] );
try {
	$f006_result = $provider->create(
		array(
			'user_id' => 7,
			'name'    => 'F006 outer create',
			'app_id'  => $f006_outer_app_id,
		)
	);
} finally {
	remove_filter( 'rest_pre_insert_application_password', $f006_callback, 10 );
}
wpnb61_f003_assert( is_wp_error( $f006_result ) && 'application_password_create_recovery_required' === $f006_result->get_error_code(), 'F-006 same-request nested dispatch did not require recovery.' );
wpnb61_f003_assert( '' !== $f006_nested_uuid && '' !== $f006_nested_secret, 'F-006 fixture did not persist a distinct nested credential.' );
wpnb61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( 7, $f006_nested_uuid ) ), 'F-006 nested provider credential was deleted.' );
wpnb61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( 7, $baseline_uuid ) ), 'F-006 changed the baseline credential.' );
wpnb61_f003_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( 7 ) ), 'F-006 changed state beyond the nested provider credential.' );
wpnb61_f003_assert( $f006_delete_before === count( $GLOBALS['wpnb61_f003_delete_routes'] ), 'F-006 incorrectly attempted cleanup DELETE against nested same-request provenance.' );
$f006_blob = $f006_result->get_error_message() . wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
foreach ( array( $f006_nested_uuid, $f006_nested_secret, $f006_nested_app_id, $f006_outer_app_id, 'unsafe details' ) as $sensitive ) {
	wpnb61_f003_assert( false === strpos( $f006_blob, $sensitive ), 'F-006 leaked nested same-request credential provenance.' );
}
WP_Application_Passwords::delete_application_password( 7, $f006_nested_uuid );
wpnb61_f003_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( 7 ) ), 'F-006 fixture cleanup did not restore baseline.' );

/* F-005a: mutate the captured item after the early check but before Core DELETE persistence. */
$f005_changed_uuid   = '';
$f005_changed_secret = '';
$f005_changed_app_id = '99999999-aaaa-4bbb-8ccc-eeeeeeeeeeee';
$f005_changed_hash   = '';
$f005_changed_done   = false;
$f005_capture = static function ( $user_id, $item, $new_password, $args ) use ( &$f005_changed_secret ) {
	if ( 7 === (int) $user_id && is_array( $args ) && 'F005 changed fingerprint' === ( $args['name'] ?? '' ) ) {
		$f005_changed_secret = is_string( $new_password ) ? $new_password : '';
	}
};
$f005_interpose = static function ( $response, $handler, $request ) use ( &$f005_changed_uuid, &$f005_changed_hash, &$f005_changed_done, $f005_changed_app_id ) {
	if ( $f005_changed_done || ! $request instanceof WP_REST_Request || 'DELETE' !== $request->method ) {
		return $response;
	}
	$base = '/wp/v2/users/7/application-passwords/';
	if ( 0 !== strpos( $request->route, $base ) ) {
		return $response;
	}
	$f005_changed_uuid = substr( $request->route, strlen( $base ) );
	$items = WP_Application_Passwords::get_user_application_passwords( 7 );
	foreach ( $items as &$item ) {
		if ( $item['uuid'] === $f005_changed_uuid ) {
			$item['name']   = 'F005 changed after verification';
			$item['app_id'] = $f005_changed_app_id;
			$f005_changed_hash = $item['password'];
			break;
		}
	}
	unset( $item );
	update_user_meta( 7, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $items );
	$f005_changed_done = true;
	return $response;
};
add_action( 'wp_create_application_password', $f005_capture, 20, 4 );
add_filter( 'rest_request_before_callbacks', $f005_interpose, 10, 3 );
$f005_delete_before = count( $GLOBALS['wpnb61_f003_delete_routes'] );
try {
	$f005_changed_result = $provider->create( array( 'user_id' => 7, 'name' => 'F005 changed fingerprint' ) );
} finally {
	remove_filter( 'rest_request_before_callbacks', $f005_interpose, 10 );
	remove_action( 'wp_create_application_password', $f005_capture, 20 );
}
$f005_changed_item = WP_Application_Passwords::get_user_application_password( 7, $f005_changed_uuid );
wpnb61_f003_assert( is_wp_error( $f005_changed_result ) && 'application_password_create_recovery_required' === $f005_changed_result->get_error_code(), 'F-005 changed-fingerprint interposition did not require recovery.' );
wpnb61_f003_assert( $f005_changed_done && is_array( $f005_changed_item ) && 'F005 changed after verification' === $f005_changed_item['name'], 'F-005 changed-fingerprint replacement did not survive guarded cleanup.' );
wpnb61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( 7, $baseline_uuid ) ), 'F-005 changed-fingerprint flow deleted the baseline credential.' );
wpnb61_f003_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( 7 ) ), 'F-005 changed-fingerprint flow changed unrelated state.' );
wpnb61_f003_assert( $f005_delete_before + 1 === count( $GLOBALS['wpnb61_f003_delete_routes'] ), 'F-005 changed-fingerprint flow did not stay within one fixed cleanup DELETE attempt.' );
$f005_changed_blob = $f005_changed_result->get_error_message() . wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
foreach ( array( $f005_changed_uuid, $f005_changed_secret, $f005_changed_hash, $f005_changed_app_id ) as $sensitive ) {
	wpnb61_f003_assert( '' === $sensitive || false === strpos( $f005_changed_blob, $sensitive ), 'F-005 changed-fingerprint flow leaked credential provenance.' );
}
WP_Application_Passwords::delete_application_password( 7, $f005_changed_uuid );
wpnb61_f003_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( 7 ) ), 'F-005 changed-fingerprint fixture cleanup did not restore baseline.' );

/* F-005b: replace the whole credential while retaining the captured UUID. */
$f005_replacement_uuid   = '';
$f005_outer_secret       = '';
$f005_replacement_secret = 'f005-replacement-secret';
$f005_replacement_hash   = 'hash-' . hash( 'sha256', $f005_replacement_secret );
$f005_replacement_app_id = 'aaaaaaaa-bbbb-4ccc-8ddd-ffffffffffff';
$f005_replacement_done   = false;
$f005_capture_replacement = static function ( $user_id, $item, $new_password, $args ) use ( &$f005_outer_secret ) {
	if ( 7 === (int) $user_id && is_array( $args ) && 'F005 same uuid replacement' === ( $args['name'] ?? '' ) ) {
		$f005_outer_secret = is_string( $new_password ) ? $new_password : '';
	}
};
$f005_replace = static function ( $response, $handler, $request ) use ( &$f005_replacement_uuid, &$f005_replacement_done, $f005_replacement_hash, $f005_replacement_app_id ) {
	if ( $f005_replacement_done || ! $request instanceof WP_REST_Request || 'DELETE' !== $request->method ) {
		return $response;
	}
	$base = '/wp/v2/users/7/application-passwords/';
	if ( 0 !== strpos( $request->route, $base ) ) {
		return $response;
	}
	$f005_replacement_uuid = substr( $request->route, strlen( $base ) );
	$items = WP_Application_Passwords::get_user_application_passwords( 7 );
	foreach ( $items as &$item ) {
		if ( $item['uuid'] === $f005_replacement_uuid ) {
			$item = array(
				'uuid'      => $f005_replacement_uuid,
				'app_id'    => $f005_replacement_app_id,
				'name'      => 'F005 replacement current credential',
				'password'  => $f005_replacement_hash,
				'created'   => 1789470505,
				'last_used' => null,
				'last_ip'   => null,
			);
			break;
		}
	}
	unset( $item );
	update_user_meta( 7, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $items );
	$f005_replacement_done = true;
	return $response;
};
add_action( 'wp_create_application_password', $f005_capture_replacement, 20, 4 );
add_filter( 'rest_request_before_callbacks', $f005_replace, 10, 3 );
$f005_replace_delete_before = count( $GLOBALS['wpnb61_f003_delete_routes'] );
try {
	$f005_replacement_result = $provider->create( array( 'user_id' => 7, 'name' => 'F005 same uuid replacement' ) );
} finally {
	remove_filter( 'rest_request_before_callbacks', $f005_replace, 10 );
	remove_action( 'wp_create_application_password', $f005_capture_replacement, 20 );
}
$f005_replacement_item = WP_Application_Passwords::get_user_application_password( 7, $f005_replacement_uuid );
wpnb61_f003_assert( is_wp_error( $f005_replacement_result ) && 'application_password_create_recovery_required' === $f005_replacement_result->get_error_code(), 'F-005 same-UUID replacement did not require recovery.' );
wpnb61_f003_assert( $f005_replacement_done && is_array( $f005_replacement_item ) && $f005_replacement_hash === $f005_replacement_item['password'], 'F-005 same-UUID replacement was deleted by cleanup.' );
wpnb61_f003_assert( is_array( WP_Application_Passwords::get_user_application_password( 7, $baseline_uuid ) ), 'F-005 same-UUID replacement flow deleted the baseline credential.' );
wpnb61_f003_assert( 2 === count( WP_Application_Passwords::get_user_application_passwords( 7 ) ), 'F-005 same-UUID replacement flow changed unrelated state.' );
wpnb61_f003_assert( $f005_replace_delete_before + 1 === count( $GLOBALS['wpnb61_f003_delete_routes'] ), 'F-005 same-UUID replacement did not stay within one fixed cleanup DELETE attempt.' );
$f005_replacement_blob = $f005_replacement_result->get_error_message() . wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
foreach ( array( $f005_replacement_uuid, $f005_outer_secret, $f005_replacement_secret, $f005_replacement_hash, $f005_replacement_app_id ) as $sensitive ) {
	wpnb61_f003_assert( '' === $sensitive || false === strpos( $f005_replacement_blob, $sensitive ), 'F-005 same-UUID replacement leaked credential provenance.' );
}
WP_Application_Passwords::delete_application_password( 7, $f005_replacement_uuid );
wpnb61_f003_assert( 1 === count( WP_Application_Passwords::get_user_application_passwords( 7 ) ), 'F-005 replacement fixture cleanup did not restore baseline.' );

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

echo "PASS: Issue #61 F-003/F-004/F-005/F-006 persistence provenance ({$tests} assertions).\n";
