<?php
/**
 * Published v0.5.0 -> v0.5.1 release-candidate upgrade acceptance.
 *
 * Runs ONLY in an isolated disposable WordPress/official Adapter test lane.
 * Test credentials live only in one non-autoloading test-option row shared by
 * the disposable WordPress workers, are never printed, and are deleted on
 * success or discarded with the isolated test database on failure.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\Approved_OAuth_Clients;
use WP_AI_Bridge\Auth\Client_Assertion_Validator;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

function wpai124_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai124_b64( $value ) {
	return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
}

/** @return callable Registered mock removal. */
function wpai124_mock_client( $client_id, $jwks_url, $key ) {
	$details = openssl_pkey_get_details( $key );
	wpai124_assert( is_array( $details ) && isset( $details['rsa']['n'], $details['rsa']['e'] ), 'Test signing-key metadata is unavailable.' );
	$jwk = array(
		'kty' => 'RSA',
		'kid' => 'wpai124-upgrade-fixture',
		'use' => 'sig',
		'alg' => 'RS256',
		'n'   => wpai124_b64( $details['rsa']['n'] ),
		'e'   => wpai124_b64( $details['rsa']['e'] ),
	);
	$mock = static function ( $preempt, $args, $url ) use ( $client_id, $jwks_url, $jwk ) {
		if ( $client_id === $url ) {
			$body = array(
				'client_id'                  => $client_id,
				'client_name'                => 'Disposable published-upgrade fixture',
				'redirect_uris'              => array( 'https://www.example.com/wpai124/callback' ),
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'private_key_jwt',
				'jwks_uri'                   => $jwks_url,
			);
		} elseif ( $jwks_url === $url ) {
			$body = array( 'keys' => array( $jwk ) );
		} else {
			return $preempt;
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $mock, 10, 3 );
	return static function () use ( $mock ) {
		remove_filter( 'pre_http_request', $mock, 10 );
	};
}

function wpai124_assertion( $client_id, $token_url, $key ) {
	$header = array(
		'alg' => 'RS256',
		'kid' => 'wpai124-upgrade-fixture',
		'typ' => 'JWT',
	);
	$claims = array(
		'iss' => $client_id,
		'sub' => $client_id,
		'aud' => $token_url,
		'iat' => time(),
		'exp' => time() + 120,
		'jti' => bin2hex( random_bytes( 16 ) ),
	);
	$input = wpai124_b64( wp_json_encode( $header ) ) . '.' . wpai124_b64( wp_json_encode( $claims ) );
	$signature = '';
	wpai124_assert( openssl_sign( $input, $signature, $key, OPENSSL_ALGO_SHA256 ), 'Could not sign disposable test assertion.' );
	return $input . '.' . wpai124_b64( $signature );
}

function wpai124_token_request( $oauth, $client_id, $key, $grant_type, array $params ) {
	$request = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
	foreach ( array_merge( $params, array( 'grant_type' => $grant_type, 'client_id' => $client_id ) ) as $name => $value ) {
		$request->set_param( $name, $value );
	}
	$request->set_param( 'client_assertion_type', Client_Assertion_Validator::ASSERTION_TYPE );
	$request->set_param( 'client_assertion', wpai124_assertion( $client_id, $oauth->token_endpoint_url(), $key ) );
	return $oauth->handle_token_request( $request );
}

function wpai124_mcp_request( $access_token, $payload, $session_id = '' ) {
	$request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $access_token );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $payload ) );
	if ( '' !== $session_id ) {
		$request->set_header( 'Mcp-Session-Id', $session_id );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	}
	return rest_do_request( $request );
}

function wpai124_response_data( $response ) {
	return json_decode( wp_json_encode( $response->get_data() ), true );
}

wpai124_assert( get_current_user_id() > 0, 'Run published upgrade fixture with a test WordPress administrator.' );
wpai124_assert( function_exists( 'openssl_pkey_new' ), 'OpenSSL is needed for disposable OAuth client signatures.' );
wpai124_assert( 'wp-ai-bridge/wp-ai-bridge.php' === plugin_basename( WP_AI_BRIDGE_FILE ), 'Canonical Bridge plugin path changed.' );

$phase = (string) getenv( 'WPAI_UPGRADE_PHASE' );
$state_option = 'wpai124_published_upgrade_test_state';
$client_id = 'https://www.example.com/wpai124/upgrade-client.json';
$jwks_url = 'https://www.example.com/wpai124/upgrade-jwks.json';
$registry = new Approved_OAuth_Clients();
$settings = new Settings();
$oauth_store = new OAuth_Store();
$oauth = new OAuth_Server( $oauth_store, $registry );
$workspace = new Store();

if ( 'before' === $phase ) {
	wpai124_assert( '0.5.0' === WP_AI_BRIDGE_VERSION, 'Published baseline plugin version was not 0.5.0.' );
	wpai124_assert( false === get_option( $state_option, false ), 'Published-upgrade test state unexpectedly exists.' );
	$old_settings = $settings->defaults();
	$old_settings[ Settings::GROUP_BUILDER_WRITE ] = 1;
	$old_settings[ Settings::GROUP_NATIVE_ABILITIES ] = 1;
	update_option( Settings::OPTION_NAME, $old_settings, false );
	wpai124_assert( $settings->all() === $old_settings, 'Published baseline Bridge settings were not persisted.' );

	$approved = $registry->sanitize( array( $client_id ) );
	wpai124_assert( array( $client_id ) === $approved, 'Published baseline did not accept the fixture OAuth client ID.' );
	update_option( Approved_OAuth_Clients::OPTION_NAME, $approved, false );
	$revision = $registry->revision();
	wpai124_assert( $registry->is_approved( $client_id ), 'Published baseline did not retain the approved OAuth client.' );

	$doc = $workspace->create_document( array( 'key' => 'published-upgrade', 'title' => 'Published upgrade document', 'content' => 'Persisted across v0.5.0 to candidate.' ) );
	$task = $workspace->create_task( array( 'title' => 'Published upgrade task', 'progress' => 'in_progress', 'review' => 'not_required', 'delivery' => 'not_applicable' ) );
	wpai124_assert( ! is_wp_error( $doc ) && ! is_wp_error( $task ), 'Published baseline Workspace seed failed.' );

	$key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
	wpai124_assert( false !== $key, 'Could not create disposable OAuth private key.' );
	$private_pem = '';
	wpai124_assert( openssl_pkey_export( $key, $private_pem ), 'Could not export disposable OAuth private key into the isolated test volume.' );
	$remove_mock = wpai124_mock_client( $client_id, $jwks_url, $key );

	$verifier = str_repeat( 'A', 64 );
	$code = $oauth_store->issue(
		OAuth_Store::TYPE_CODE,
		array(
			'user_id'         => get_current_user_id(),
			'client_id'       => $client_id,
			'client_revision' => $revision,
			'redirect_uri'    => 'https://www.example.com/wpai124/callback',
			'code_challenge'  => wpai124_b64( hash( 'sha256', $verifier, true ) ),
			'resource'        => $oauth->mcp_endpoint_url(),
			'scope'           => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
		),
		OAuth_Server::CODE_TTL
	);
	$response = wpai124_token_request(
		$oauth,
		$client_id,
		$key,
		'authorization_code',
		array(
			'code'          => $code,
			'redirect_uri'  => 'https://www.example.com/wpai124/callback',
			'code_verifier' => $verifier,
			'resource'      => $oauth->mcp_endpoint_url(),
		)
	);
	$remove_mock();
	wpai124_assert( 200 === $response->get_status(), 'Published 0.5.0 signed-client PKCE token exchange did not succeed.' );
	$data = wpai124_response_data( $response );
	wpai124_assert( ! empty( $data['access_token'] ) && ! empty( $data['refresh_token'] ), 'Published 0.5.0 did not mint access and refresh credentials.' );
	wpai124_assert( false !== $oauth_store->read( OAuth_Store::TYPE_ACCESS, $data['access_token'], false, $client_id ), 'Published 0.5.0 access token is unusable.' );
	wpai124_assert( false !== $oauth_store->read( OAuth_Store::TYPE_REFRESH, $data['refresh_token'], false, $client_id ), 'Published 0.5.0 refresh token is unusable.' );

	$fixture = array(
		'baseline'            => '0.5.0',
		'wordpress_user'      => get_current_user_id(),
		'settings'            => $old_settings,
		'client_id'           => $client_id,
		'client_revision'     => $revision,
		'instance_id'         => $oauth_store->instance_id(),
		'resource'            => $oauth->mcp_endpoint_url(),
		'document_id'         => (int) $doc['id'],
		'document_state_hash' => (string) $doc['state_hash'],
		'task_id'             => (int) $task['id'],
		'task_state_hash'     => (string) $task['state_hash'],
		'access_token'       => $data['access_token'],
		'refresh_token'      => $data['refresh_token'],
		'private_key_pem'    => $private_pem,
	);
	wpai124_assert( add_option( $state_option, $fixture, '', false ), 'Could not persist isolated test-only continuity state.' );
	wpai124_assert( $fixture === get_option( $state_option, false ), 'Test-only upgrade state was not stored byte-exactly.' );
	echo "PASS: published v0.5.0 seeded canonical settings, Workspace and signed-client OAuth/PKCE credentials.\n";
	return;
}

wpai124_assert( 'after' === $phase, 'Unsupported published-upgrade fixture phase.' );
wpai124_assert( '0.5.1' === WP_AI_BRIDGE_VERSION, 'Upgraded source did not report v0.5.1.' );
$fixture = get_option( $state_option, false );
wpai124_assert( false !== $fixture, 'Published v0.5.0 continuity evidence is absent.' );
wpai124_assert( is_array( $fixture ) && '0.5.0' === ( $fixture['baseline'] ?? '' ), 'Published baseline continuity data is invalid.' );
wpai124_assert( (int) $fixture['wordpress_user'] === get_current_user_id(), 'Upgraded WordPress principal changed.' );
wpai124_assert( $fixture['settings'] === get_option( Settings::OPTION_NAME ), 'Stored canonical Bridge delegation settings were changed on upgrade.' );
wpai124_assert( false === $settings->is_enabled( Settings::GROUP_REST_DISCOVERY ), 'REST Discovery was silently enabled after upgrade.' );
wpai124_assert( false === $settings->is_enabled( Settings::GROUP_REST_INVOCATION ), 'REST Invocation was silently enabled after upgrade.' );
wpai124_assert( 1 === (int) $settings->all()[ Settings::GROUP_BUILDER_WRITE ], 'Previously enabled Builder Write was lost.' );
wpai124_assert( 1 === (int) $settings->all()[ Settings::GROUP_NATIVE_ABILITIES ], 'Previously enabled Native Abilities was lost.' );
wpai124_assert( (string) $fixture['instance_id'] === $oauth_store->instance_id(), 'OAuth installation identity unexpectedly reset during upgrade.' );
wpai124_assert( (int) $fixture['client_revision'] === $registry->revision(), 'Approved OAuth client revision unexpectedly changed.' );
wpai124_assert( $registry->is_approved( $client_id ), 'Previously approved OAuth client was lost on upgrade.' );
wpai124_assert( $fixture['resource'] === $oauth->mcp_endpoint_url(), 'Canonical MCP resource/namespace changed on upgrade.' );
wpai124_assert( false === get_option( 'wp_ai_bridge_schema_version', false ), 'Retired migration schema marker appeared on upgrade.' );
wpai124_assert( ! is_file( WP_AI_BRIDGE_DIR . '/src/class-migrator.php' ), 'Retired migration entrypoint returned during upgrade.' );
wpai124_assert( ! is_file( WP_AI_BRIDGE_DIR . '/languages/fa_IR-parts/migration.php' ), 'Retired migration localization returned during upgrade.' );

$doc = $workspace->get_document( (int) $fixture['document_id'] );
$task = $workspace->get_task( (int) $fixture['task_id'] );
wpai124_assert( ! is_wp_error( $doc ) && ! is_wp_error( $task ), 'Persisted Workspace document/task could not be read after upgrade.' );
wpai124_assert( hash_equals( (string) $fixture['document_state_hash'], (string) $doc['state_hash'] ), 'Workspace document changed across published upgrade.' );
wpai124_assert( hash_equals( (string) $fixture['task_state_hash'], (string) $task['state_hash'] ), 'Workspace task changed across published upgrade.' );

$old_access = (string) $fixture['access_token'];
$old_refresh = (string) $fixture['refresh_token'];
wpai124_assert( false !== $oauth_store->read( OAuth_Store::TYPE_ACCESS, $old_access, false, $client_id ), 'Published pre-upgrade OAuth access credential was not preserved.' );
wpai124_assert( false !== $oauth_store->read( OAuth_Store::TYPE_REFRESH, $old_refresh, false, $client_id ), 'Published pre-upgrade OAuth refresh credential was not preserved.' );

$routes = rest_get_server()->get_routes();
wpai124_assert( isset( $routes[ OAuth_Server::MCP_REQUEST_ROUTE ], $routes['/wp-ai-bridge/v1/oauth/token'] ), 'Official Adapter/Bridge canonical MCP or token route is missing after upgrade.' );
wpai124_assert( wp_get_ability( 'wp-ai-bridge/rest-routes-read' ) instanceof WP_Ability, 'New bounded REST discovery is missing after upgrade.' );
wpai124_assert( wp_get_ability( 'wp-ai-bridge/rest-route-invoke' ) instanceof WP_Ability, 'Guarded REST invocation was lost after upgrade.' );
wpai124_assert( ! wp_get_ability( 'wp-ai-bridge/private-packages-read' ), 'Removed ZIP staging Ability survived upgrade.' );
wpai124_assert( ! wp_get_ability( 'wp-ai-bridge/private-package-install' ), 'Removed ZIP installer Ability survived upgrade.' );
wpai124_assert( false === has_action( 'admin_post_wpai_private_zip_upload' ), 'Removed admin ZIP staging route survived upgrade.' );

// Exercise the real official Adapter transport with the v0.5.0 access token,
// not a newly minted candidate-only token or a mocked MCP tool response.
$before_sessions = class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' )
	? \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( get_current_user_id() )
	: array();
wp_set_current_user( 0 );
$initialize = wpai124_mcp_request(
	$old_access,
	array(
		'jsonrpc' => '2.0',
		'id' => 1,
		'method' => 'initialize',
		'params' => array(
			'protocolVersion' => '2025-11-25',
			'capabilities' => (object) array(),
			'clientInfo' => array( 'name' => 'wpai124-published-upgrade', 'version' => '1.0' ),
		),
	)
);
wpai124_assert( 200 === $initialize->get_status(), 'Pre-upgrade access token could not initialize official MCP Adapter after upgrade.' );
$protocol = wpai124_response_data( $initialize );
wpai124_assert( '2025-11-25' === ( $protocol['result']['protocolVersion'] ?? '' ), 'Official Adapter protocol negotiation changed across upgrade.' );
$headers = $initialize->get_headers();
$session_id = isset( $headers['Mcp-Session-Id'] ) ? (string) $headers['Mcp-Session-Id'] : '';
if ( '' === $session_id && class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ) {
	$after_sessions = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( (int) $fixture['wordpress_user'] );
	$session_id = (string) array_key_first( array_diff_key( $after_sessions, $before_sessions ) );
}
wpai124_assert( '' !== $session_id, 'Official Adapter did not allocate an authenticated MCP session.' );
$notification = wpai124_mcp_request( $old_access, array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ), $session_id );
wpai124_assert( 202 === $notification->get_status(), 'Official Adapter initialized notification failed.' );
$execute = wpai124_mcp_request(
	$old_access,
	array(
		'jsonrpc' => '2.0',
		'id' => 2,
		'method' => 'tools/call',
		'params' => array(
			'name' => 'mcp-adapter-execute-ability',
			'arguments' => array( 'ability_name' => 'wp-ai-bridge/bridge-info', 'parameters' => (object) array() ),
		),
	),
	$session_id
);
wpai124_assert( 200 === $execute->get_status(), 'Official Adapter rejected the published access token for bridge-info execution.' );
$mcp_data = wpai124_response_data( $execute );
$structured = $mcp_data['result']['structuredContent'] ?? null;
if ( ! is_array( $structured ) ) {
	$structured = json_decode( (string) ( $mcp_data['result']['content'][0]['text'] ?? '' ), true );
}
wpai124_assert( is_array( $structured ) && true === ( $structured['success'] ?? false ), 'Official Adapter returned unsuccessful bridge-info result after upgrade.' );
wpai124_assert( '0.5.1' === ( $structured['data']['plugin_version'] ?? '' ), 'Official Adapter reported the wrong upgraded plugin version.' );

// Finish the existing v0.5.0 authorization lifecycle through v0.5.1's real
// signed-client OAuth token endpoint and bounded refresh-token rotation.
$key = openssl_pkey_get_private( (string) $fixture['private_key_pem'] );
wpai124_assert( false !== $key, 'Could not restore the isolated test client key.' );
$remove_mock = wpai124_mock_client( $client_id, $jwks_url, $key );
$response = wpai124_token_request(
	$oauth,
	$client_id,
	$key,
	'refresh_token',
	array( 'refresh_token' => $old_refresh, 'resource' => $fixture['resource'] )
);
$remove_mock();
wpai124_assert( 200 === $response->get_status(), 'Published v0.5.0 refresh token failed signed-client renewal after upgrade.' );
$data = wpai124_response_data( $response );
wpai124_assert( ! empty( $data['access_token'] ) && ! empty( $data['refresh_token'] ), 'Upgraded OAuth endpoint did not produce a successor token pair.' );
wpai124_assert( ! hash_equals( $old_refresh, (string) $data['refresh_token'] ), 'Upgraded OAuth endpoint reused the old refresh token.' );
wpai124_assert( false === $oauth_store->read( OAuth_Store::TYPE_REFRESH, $old_refresh ), 'Published refresh token was not consumed after successful rotation.' );
wpai124_assert( false !== $oauth_store->read( OAuth_Store::TYPE_REFRESH, $data['refresh_token'], false, $client_id ), 'New refresh token lost its client binding.' );
wpai124_assert( false !== $oauth_store->read( OAuth_Store::TYPE_ACCESS, $data['access_token'], false, $client_id ), 'New access token lost its client binding.' );

$oauth_store->revoke( $old_access, $client_id );
$oauth_store->revoke( $data['access_token'], $client_id );
$oauth_store->revoke( $data['refresh_token'], $client_id );
wpai124_assert( delete_option( $state_option ), 'Test-only bearer and private key continuity state could not be retired.' );
echo "PASS: published v0.5.0 -> v0.5.1 canonical state, Workspace, OAuth rotation, default-off REST, and official authenticated MCP Adapter.\n";

