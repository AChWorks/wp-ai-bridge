<?php
/**
 * Regression for faithful, redacted Bridge and Adapter error provenance.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities {
	/** Exact first-party callback class provenance, not caller-controlled metadata. */
	final class Error_Provenance_Test_Provider {
		public function execute( $input = null ) {
			return new \WP_Error(
				'unlisted_provider_code.v2',
				'Native task rejected: invalid file state; Bearer VERY_PRIVATE_TOKEN',
				array(
					'reason'       => 'disk quota exhausted',
					'worker'       => 'php_version',
					'api_key'      => 'NEVER_EXPOSE_API_KEY',
					'inner'        => array( 'path' => '/srv/private/auth/private.txt', 'attempt' => 2 ),
				)
			);
		}
		public function permission( $input = null ) {
			return true;
		}
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	use WP_AI_Bridge\Abilities\Diagnostics_Abilities;
	use WP_AI_Bridge\Abilities\Error_Provenance_Test_Provider;
	use WP_AI_Bridge\Abilities\Site_Health_Abilities;
	use WP_AI_Bridge\Auth\OAuth_Server;
	use WP_AI_Bridge\Support\Error_Details;
	use WP_AI_Bridge\Support\Mutation_Log;
	use WP_AI_Bridge\Support\Native_Ability_Delegation;
	use WP_AI_Bridge\Support\Permissions;
	use WP_AI_Bridge\Support\Settings;

	class WP_REST_Request {
		private $params;
		public function __construct( $params ) { $this->params = $params; }
		public function get_method() { return 'POST'; }
		public function get_route() { return '/wp-ai-bridge/v1/mcp'; }
		public function get_json_params() { return $this->params; }
	}
	class WP_REST_Response {
		private $data;
		public function __construct( $data ) { $this->data = $data; }
		public function get_data() { return $this->data; }
		public function set_data( $value ) { $this->data = $value; }
	}
	class WP_AI_Test_MCP_Server {
		public function get_server_id() { return Native_Ability_Delegation::BRIDGE_MCP_SERVER_ID; }
	}

	function wp_salt( $scheme = 'auth' ) { return 'isolated-diagnostic-test-only-site-salt'; }

	$checks = 0;
	$assert = static function ( $value, $message ) use ( &$checks ) {
		++$checks;
		if ( ! $value ) { throw new \RuntimeException( $message ); }
	};
	wpai_test_reset_state();
	$authenticated_client = new \ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
	$authenticated_client->setValue( null, 'authorized-client-A' );
	$settings   = new Settings();
	$log        = new Mutation_Log();
	$delegation = new Native_Ability_Delegation( $settings );

	$provider = new Site_Health_Abilities( new Permissions( $settings ) );
	$args = $delegation->filter_ability_args(
		array(
			'execute_callback' => array( $provider, 'execute' ),
			'permission_callback' => array( $provider, 'can_inspect' ),
		),
		'wp-ai-bridge/site-health'
	);
	$registered = new \stdClass();
	$delegation->remember_bridge_abilities( array( $registered ) );
	$GLOBALS['wpai_test']['abilities']['wp-ai-bridge/site-health'] = $registered;

	$original = ( new Error_Provenance_Test_Provider() )->execute();
	$details  = Error_Details::from_wp_error( $original );
	$assert( 'unlisted_provider_code.v2' === $details['code'], 'Unlisted original codes were normalized.' );
	$assert( str_contains( $details['message'], 'invalid file state' ), 'Non-sensitive error message was lost.' );
	$assert( ! str_contains( $details['message'], 'VERY_PRIVATE_TOKEN' ), 'Bearer token leaked.' );
	$assert( 'disk quota exhausted' === $details['data']['reason'], 'Useful error data lost.' );
	$assert( '[REDACTED]' === $details['data']['api_key'] &&
		'[REDACTED]' === $details['data']['inner']['path'], 'Sensitive fields leaked.' );
	$header_error = Error_Details::from_wp_error(
		new WP_Error( 'upstream_response', 'HTTP response header failure', array(
			'headers' => array( 'content-type' => 'application/json', 'Authorization' => 'Bearer HEADER_SECRET' ),
			'http_status' => 503,
		) )
	);
	$assert( 'application/json' === $header_error['data']['headers']['content-type'] &&
		503 === $header_error['data']['http_status'] &&
		'[REDACTED]' === $header_error['data']['headers']['Authorization'],
		'Non-sensitive header/status information was removed or sensitive Authorization leaked.' );


	$params = array(
		'jsonrpc' => '2.0',
		'id' => 17,
		'method' => 'tools/call',
		'params' => array(
			'name' => 'mcp-adapter-execute-ability',
			'arguments' => array( 'ability_name' => 'wp-ai-bridge/site-health', 'parameters' => array() ),
		),
	);
	$route = $delegation->filter_rest_endpoints(
		array(
			'/wp-ai-bridge/v1/mcp' => array(
				array( 'callback' => static function () use ( $args, $params ) {
					$raw = $args['execute_callback']( array() );
					return new WP_REST_Response(
						array(
							'jsonrpc' => '2.0', 'id' => $params['id'],
							'result' => array(
								'isError' => true,
								'content' => array( array( 'type' => 'text', 'text' => $raw->get_error_message() ) ),
							),
						)
					);
				} ),
			),
		)
	);
	$invoke = $route['/wp-ai-bridge/v1/mcp'][0]['callback'];
	$result = $invoke( new WP_REST_Request( $params ) )->get_data()['result'];
	$error  = $result['structuredContent']['error'] ?? array();
	$assert( true === $result['isError'] && 'site_health_denied' === ( $error['code'] ?? null ),
		'First-party WP_Error code lost at Adapter projection.' );
	$assert( str_contains( $result['content'][0]['text'] ?? '', 'Site Configuration' ) &&
		! str_contains( json_encode( $result ), 'VERY_PRIVATE_TOKEN' ) &&
		! str_contains( json_encode( $result ), 'NEVER_EXPOSE_API_KEY' ),
		'Response did not preserve useful text or leaked secret.' );
	$assert( false === $error['redacted'] && false === $error['truncated'] &&
		true === $error['code_available'] && true === $error['diagnostics_persisted'],
		'Redaction, source-code and persistence truth missing.' );

	$ref = $error['diagnostic_reference'] ?? '';
	$diagnostics = new Diagnostics_Abilities( new Permissions( $settings ), $log );
	$diagnostics->register();
	$view  = $diagnostics->execute( array( 'reference' => $ref, 'limit' => 5 ) );
	$entry = $view['events'][0] ?? array();
	$assert( 1 === $view['returned'] && $entry['event_ref'] === $ref &&
		'site_health_denied' === $entry['error_code'], 'Diagnostic reference lookup lost exact code.' );
	$assert( str_contains( $entry['message'] ?? '', 'Site Configuration' ) &&
		! str_contains( json_encode( $view ), 'VERY_PRIVATE_TOKEN' ) &&
		null === ( $entry['data'] ?? null ),
		'Privileged diagnostics lost useful safe detail.' );
	// Treat stored diagnostic records as untrusted: a poisoned credential
	// fragment in an option entry must not leak through reference lookup.
	$stored = $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ];
	$GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ][0]['error_code'] = 'provider_token=TOP_SECRET_DATA';
	$poisoned = $diagnostics->execute( array( 'reference' => $ref ) );
	$assert( 1 === $poisoned['returned'] && ! str_contains( json_encode( $poisoned ), 'TOP_SECRET_DATA' ) &&
		str_contains( json_encode( $poisoned ), '[REDACTED]' ),
		'Poisoned persisted error code exposed secret through privileged lookup.' );
	$GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ] = $stored;

	$authenticated_client->setValue( null, 'other-client-B' );
	$assert( 0 === $diagnostics->execute( array( 'reference' => $ref ) )['returned'],
		'Other OAuth client enumerated a diagnostic reference.' );
	$authenticated_client->setValue( null, 'authorized-client-A' );
	$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
	$assert( is_wp_error( $diagnostics->execute( array( 'reference' => $ref ) ) ),
		'Non-admin can read diagnostics.' );
	$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
	$assert( is_wp_error( $diagnostics->execute( array( 'reference' => '../secret' ) ) ),
		'Unbounded/path diagnostic reference accepted.' );

	// WordPress 7.1 native hook observes the exact WP_Error before Adapter loss.
	$native_name = 'core/get-environment-info';
	$native     = new WP_Native_Builder_Test_Ability( $native_name );
	$GLOBALS['wpai_test']['abilities'][ $native_name ] = $native;
	$native_error = new WP_Error( 'provider_custom_failure.v3', 'Native environment check: wrong type', array( 'status' => 422 ) );
	$native_args = $params;
	$native_args['id'] = 18;
	$native_args['params']['arguments']['ability_name'] = $native_name;
	$native_route = $delegation->filter_rest_endpoints(
		array(
			'/wp-ai-bridge/v1/mcp' => array(
				array( 'callback' => static function () use ( $delegation, $native, $native_error, $native_name, $native_args ) {
					$delegation->observe_ability_execute_result( $native_error, $native_name, array(), $native );
					$filtered = $delegation->filter_tool_call_result(
						array( 'success' => false, 'error' => $native_error->get_error_message() ),
						array( 'ability_name' => $native_name ),
						Native_Ability_Delegation::ADAPTER_EXECUTE_TOOL,
						new \stdClass(), new WP_AI_Test_MCP_Server()
					);
					return new WP_REST_Response(
						array( 'jsonrpc' => '2.0', 'id' => 18, 'result' => array(
							'isError' => true,
							'content' => array( array( 'type' => 'text', 'text' => $filtered['error'] ) ),
						) )
					);
				} ),
			),
		)
	);
	$observed = $native_route['/wp-ai-bridge/v1/mcp'][0]['callback']( new WP_REST_Request( $native_args ) )
		->get_data()['result']['structuredContent']['error'] ?? array();
	$assert( 'provider_custom_failure.v3' === ( $observed['code'] ?? null ) &&
		'native_ability' === ( $observed['origin'] ?? null ) &&
		422 === ( $observed['data']['status'] ?? null ), 'WordPress 7.1 hook lost native code/data.' );

	// Supported older runtime: Adapter has *already* stripped the WP_Error code.
	$legacy_params = $native_args;
	$legacy_params['id'] = 19;
	$legacy_route = $delegation->filter_rest_endpoints(
		array(
			'/wp-ai-bridge/v1/mcp' => array(
				array( 'callback' => static function () use ( $delegation, $native_name ) {
					$filtered = $delegation->filter_tool_call_result(
						array( 'success' => false, 'error' => 'Unsupported provider state: token=DO_NOT_LEAK' ),
						array( 'ability_name' => $native_name ),
						Native_Ability_Delegation::ADAPTER_EXECUTE_TOOL,
						new \stdClass(), new WP_AI_Test_MCP_Server()
					);
					return new WP_REST_Response(
						array( 'jsonrpc' => '2.0', 'id' => 19, 'result' => array(
							'isError' => true,
							'content' => array( array( 'type' => 'text', 'text' => $filtered['error'] ) ),
						) )
					);
				} ),
			),
		)
	);
	$legacy_response = $legacy_route['/wp-ai-bridge/v1/mcp'][0]['callback']( new WP_REST_Request( $legacy_params ) )
		->get_data()['result'];
	$legacy_error = $legacy_response['structuredContent']['error'] ?? array();
	$assert( null === ( $legacy_error['code'] ?? null ) && false === $legacy_error['code_available'] &&
		'adapter_boundary' === $legacy_error['origin'], 'Adapter-stripped code was falsely reconstructed.' );
	$assert( str_contains( $legacy_error['message'], 'Unsupported provider state' ) &&
		! str_contains( json_encode( $legacy_response ), 'DO_NOT_LEAK' ),
		'Adapter fallback discarded useful message or leaked secret.' );

	// Context is request-scoped, not leaked into successful or unrelated calls.
	$success_route = $delegation->filter_rest_endpoints(
		array( '/wp-ai-bridge/v1/mcp' => array( array( 'callback' => static function () {
			return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => 20,
				'result' => array( 'isError' => false, 'content' => array() ) ) );
		} ) ) )
	);
	$success_params = $params;
	$success_params['id'] = 20;
	$success = $success_route['/wp-ai-bridge/v1/mcp'][0]['callback']( new WP_REST_Request( $success_params ) )->get_data();
	$assert( ! isset( $success['result']['structuredContent'] ), 'Previous error leaked into later successful request.' );
	// A Bridge capability returning boolean false is an observed denial,
	// never a fabricated WordPress WP_Error code or guessed root cause.
	$permission_params = $params;
	$permission_params['id'] = 21;
	$permission_route = $delegation->filter_rest_endpoints(
		array( '/wp-ai-bridge/v1/mcp' => array(
			array( 'callback' => static function () use ( $args ) {
				$allow = $args['permission_callback']( array() );
				return new WP_REST_Response( array(
					'jsonrpc' => '2.0', 'id' => 21,
					'result' => array( 'isError' => true, 'content' => array(
						array( 'type' => 'text', 'text' => $allow ? 'allowed' : 'Permission denied' ),
					) ),
				) );
			} ),
		) )
	);
	$permission_result = $permission_route['/wp-ai-bridge/v1/mcp'][0]['callback'](
		new WP_REST_Request( $permission_params )
	)->get_data()['result']['structuredContent']['error'] ?? array();
	$assert( null === ( $permission_result['code'] ?? null ) &&
		'bridge_ability' === ( $permission_result['origin'] ?? '' ) &&
		'authorization' === ( $permission_result['stage'] ?? '' ) &&
		'inspect_permissions' === ( $permission_result['next_step'] ?? '' ),
		'Boolean permission rejection fabricated a code or lost observed stage.' );

	$authenticated_client->setValue( null, '' );

	echo "PASS: faithful Bridge/Adapter errors, scoped diagnostics and selective redaction ({$checks} assertions).\n";
}
