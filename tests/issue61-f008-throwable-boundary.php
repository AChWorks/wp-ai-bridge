<?php
/**
 * Dependency-free Throwable normalization coverage for Issue #61 / F-008.
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

function wpnb61_f008_assert( $condition, $message ) {
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

$GLOBALS['wpnb61_f008_requests'] = array();
$GLOBALS['wpnb61_f008_secret']   = 'f008 RAW secret 1234 5678';
$GLOBALS['wpnb61_f008_hash']     = '$P$f008-stored-hash-must-not-leak';
$GLOBALS['wpnb61_f008_uuid']     = '11111111-2222-4333-8444-555555555555';
$GLOBALS['wpnb61_f008_app_id']   = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$GLOBALS['wpnb61_f008_marker']   = 'issue61_f008_secret_bearing_throwable';

function rest_do_request( $request ) {
	$GLOBALS['wpnb61_f008_requests'][] = array(
		'method' => $request->method,
		'route'  => $request->route,
		'params' => $request->params,
	);

	throw new RuntimeException(
		implode(
			'|',
			array(
				$GLOBALS['wpnb61_f008_marker'],
				$GLOBALS['wpnb61_f008_secret'],
				$GLOBALS['wpnb61_f008_hash'],
				$GLOBALS['wpnb61_f008_uuid'],
				$GLOBALS['wpnb61_f008_app_id'],
			)
		)
	);
}

wpai_test_reset_state();
$settings = new Settings();
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] = $settings->defaults();
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_AUTHENTICATION ] = 1;
$GLOBALS['wpai_test']['capabilities']['read'] = true;

$provider = new Application_Password_Abilities( new Permissions( $settings ), new Mutation_Log() );
$uuid     = $GLOBALS['wpnb61_f008_uuid'];

$results = array(
	'list'       => $provider->read( array( 'action' => 'list', 'user_id' => 7 ) ),
	'get'        => $provider->read( array( 'action' => 'get', 'user_id' => 7, 'uuid' => $uuid ) ),
	'update'     => $provider->update( array( 'user_id' => 7, 'uuid' => $uuid, 'name' => 'F008 denied rename' ) ),
	'delete'     => $provider->delete( array( 'user_id' => 7, 'uuid' => $uuid ) ),
	'delete_all' => $provider->delete_all( array( 'user_id' => 7, 'confirm' => 'revoke_all' ) ),
);

$unsafe_values = array(
	$GLOBALS['wpnb61_f008_marker'],
	$GLOBALS['wpnb61_f008_secret'],
	$GLOBALS['wpnb61_f008_hash'],
	$GLOBALS['wpnb61_f008_uuid'],
	$GLOBALS['wpnb61_f008_app_id'],
	'RuntimeException',
);

foreach ( $results as $operation => $result ) {
	wpnb61_f008_assert( is_wp_error( $result ), 'F-008 ' . $operation . ' Throwable did not become a WP_Error.' );
	wpnb61_f008_assert( 'application_passwords_rest_request_failed' === $result->get_error_code(), 'F-008 ' . $operation . ' Throwable did not use the bounded Bridge error code.' );
	wpnb61_f008_assert( 'WordPress rejected the Application Password REST request.' === $result->get_error_message(), 'F-008 ' . $operation . ' Throwable did not use the fixed safe Bridge message.' );
	$data = method_exists( $result, 'get_error_data' ) ? $result->get_error_data() : null;
	wpnb61_f008_assert( empty( $data ), 'F-008 ' . $operation . ' Throwable exposed arbitrary exception/status data.' );
	$blob = wp_json_encode( array( $result->get_error_code(), $result->get_error_message(), $data ) );
	foreach ( $unsafe_values as $unsafe ) {
		wpnb61_f008_assert( false === strpos( $blob, $unsafe ), 'F-008 ' . $operation . ' error leaked Throwable credential material.' );
	}
}

$expected_requests = array(
	array( 'GET', '/wp/v2/users/7/application-passwords' ),
	array( 'GET', '/wp/v2/users/7/application-passwords/' . $uuid ),
	array( 'POST', '/wp/v2/users/7/application-passwords/' . $uuid ),
	array( 'DELETE', '/wp/v2/users/7/application-passwords/' . $uuid ),
	array( 'DELETE', '/wp/v2/users/7/application-passwords' ),
);
wpnb61_f008_assert( count( $expected_requests ) === count( $GLOBALS['wpnb61_f008_requests'] ), 'F-008 did not exercise all five shared operations exactly once.' );
foreach ( $expected_requests as $index => $expected ) {
	$actual = $GLOBALS['wpnb61_f008_requests'][ $index ] ?? array();
	wpnb61_f008_assert( $expected[0] === ( $actual['method'] ?? null ), 'F-008 dispatched an unexpected REST method.' );
	wpnb61_f008_assert( $expected[1] === ( $actual['route'] ?? null ), 'F-008 dispatched outside the exact expected REST route.' );
}

$log_blob = wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
foreach ( $unsafe_values as $unsafe ) {
	wpnb61_f008_assert( false === strpos( $log_blob, $unsafe ), 'F-008 mutation log leaked Throwable credential material.' );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "Issue #61 F-008 Throwable boundary tests: {$failures}/{$tests} failed.\n" );
	exit( 1 );
}

echo "PASS: Issue #61 F-008 Throwable boundary ({$tests} assertions).\n";
