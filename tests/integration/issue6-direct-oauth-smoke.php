<?php
/**
 * Live WordPress smoke for the direct ChatGPT OAuth/MCP endpoint.
 *
 * Run with:
 * wp eval-file tests/integration/issue6-direct-oauth-smoke.php --user=<administrator>
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Auth\Client_Assertion_Validator;
use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Auth\OAuth_Store;

function wpai_issue6_oauth_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue6_oauth_data( $response ) {
	return json_decode( wp_json_encode( $response->get_data() ), true );
}

function wpai_issue6_oauth_base64url( $value ) {
	return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
}

function wpai_issue6_oauth_client_assertion( $audience, $jti = '', array $claim_overrides = array(), array $header_overrides = array() ) {
	$key = $GLOBALS['wpai_issue6_oauth_private_key'] ?? null;
	wpai_issue6_oauth_assert( $key, 'Private-key fixture is unavailable.' );
	$header    = array_merge(
		array(
			'alg' => 'RS256',
			'kid' => $GLOBALS['wpai_issue6_oauth_kid'],
			'typ' => 'JWT',
		),
		$header_overrides
	);
	$claims    = array_merge(
		array(
			'iss' => OAuth_Server::CHATGPT_CLIENT_ID,
			'sub' => OAuth_Server::CHATGPT_CLIENT_ID,
			'aud' => $audience,
			'iat' => time(),
			'exp' => time() + 120,
			'jti' => '' !== $jti ? $jti : bin2hex( random_bytes( 16 ) ),
		),
		$claim_overrides
	);
	$input     = wpai_issue6_oauth_base64url( wp_json_encode( $header ) ) . '.' . wpai_issue6_oauth_base64url( wp_json_encode( $claims ) );
	$signature = '';
	wpai_issue6_oauth_assert( openssl_sign( $input, $signature, $key, OPENSSL_ALGO_SHA256 ), 'Could not sign private_key_jwt fixture.' );
	return $input . '.' . wpai_issue6_oauth_base64url( $signature );
}

function wpai_issue6_oauth_apply_client_auth( WP_REST_Request $request, $audience, $assertion = '' ) {
	$request->set_param( 'client_assertion_type', Client_Assertion_Validator::ASSERTION_TYPE );
	$request->set_param( 'client_assertion', '' !== $assertion ? $assertion : wpai_issue6_oauth_client_assertion( $audience ) );
}

function wpai_issue6_oauth_token_request( OAuth_Server $oauth, array $params, $assertion = '' ) {
	$request = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
	foreach ( $params as $name => $value ) {
		$request->set_param( $name, $value );
	}
	wpai_issue6_oauth_apply_client_auth( $request, $oauth->token_endpoint_url(), $assertion );
	return $oauth->handle_token_request( $request );
}

$authenticated_user_id = get_current_user_id();
wpai_issue6_oauth_assert( $authenticated_user_id > 0, 'Run this smoke as an authenticated WordPress user.' );
wpai_issue6_oauth_assert( function_exists( 'openssl_pkey_new' ), 'OpenSSL is required for ChatGPT private_key_jwt authentication.' );

$private_key = openssl_pkey_new(
	array(
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	)
);
wpai_issue6_oauth_assert( false !== $private_key, 'Could not create private_key_jwt test key.' );
$key_details = openssl_pkey_get_details( $private_key );
wpai_issue6_oauth_assert( is_array( $key_details ) && ! empty( $key_details['rsa']['n'] ) && ! empty( $key_details['rsa']['e'] ), 'RSA test key details are unavailable.' );
$GLOBALS['wpai_issue6_oauth_private_key'] = $private_key;
$GLOBALS['wpai_issue6_oauth_kid']         = 'wpai-private-key-jwt-fixture';
$fixture_jwks                             = array(
	'keys' => array(
		array(
			'kty' => 'RSA',
			'kid' => $GLOBALS['wpai_issue6_oauth_kid'],
			'use' => 'sig',
			'alg' => 'RS256',
			'n'   => wpai_issue6_oauth_base64url( $key_details['rsa']['n'] ),
			'e'   => wpai_issue6_oauth_base64url( $key_details['rsa']['e'] ),
		),
	),
);
$fixture_client_metadata                  = array(
	'client_id'                  => OAuth_Server::CHATGPT_CLIENT_ID,
	'client_name'                => 'ChatGPT',
	'redirect_uris'              => array( OAuth_Server::CHATGPT_REDIRECT_URI ),
	'grant_types'                => array( 'authorization_code', 'refresh_token' ),
	'response_types'             => array( 'code' ),
	'token_endpoint_auth_method' => 'private_key_jwt',
	'jwks_uri'                   => Client_Assertion_Validator::CHATGPT_JWKS_URI,
);
$GLOBALS['wpai_issue6_jwks_fetches'] = 0;
$wpai_issue6_http_mock                    = static function ( $preempt, $args, $url ) use ( $fixture_jwks, $fixture_client_metadata ) {
	if ( OAuth_Server::CHATGPT_CLIENT_ID === $url ) {
		$body = $fixture_client_metadata;
	} elseif ( Client_Assertion_Validator::CHATGPT_JWKS_URI === $url ) {
		++$GLOBALS['wpai_issue6_jwks_fetches'];
		$body = $fixture_jwks;
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
add_filter( 'pre_http_request', $wpai_issue6_http_mock, 10, 3 );
delete_transient( OAuth_Server::CLIENT_METADATA_CACHE );
delete_transient( Client_Assertion_Validator::JWKS_CACHE );
delete_transient( Client_Assertion_Validator::JWKS_REFRESH_COOLDOWN );

$store = new OAuth_Store();
$oauth = new OAuth_Server( $store );

$protected = $oauth->protected_resource_metadata();
wpai_issue6_oauth_assert( $oauth->mcp_endpoint_url() === ( $protected['resource'] ?? '' ), 'Protected-resource metadata does not use the canonical direct MCP URL.' );
wpai_issue6_oauth_assert( in_array( $oauth->issuer_url(), $protected['authorization_servers'] ?? array(), true ), 'Protected-resource metadata does not advertise the WordPress OAuth issuer.' );
wpai_issue6_oauth_assert( in_array( OAuth_Server::SCOPE_MCP, $protected['scopes_supported'] ?? array(), true ), 'Protected-resource metadata does not advertise the MCP scope.' );
wpai_issue6_oauth_assert( in_array( OAuth_Server::SCOPE_OFFLINE, $protected['scopes_supported'] ?? array(), true ), 'Protected-resource metadata does not advertise offline_access.' );

$authorization = $oauth->authorization_server_metadata();
wpai_issue6_oauth_assert( true === ( $authorization['client_id_metadata_document_supported'] ?? false ), 'Authorization metadata does not advertise Client ID Metadata Document support.' );
wpai_issue6_oauth_assert( array( 'S256' ) === ( $authorization['code_challenge_methods_supported'] ?? array() ), 'Authorization metadata does not require PKCE S256.' );
wpai_issue6_oauth_assert( array( 'private_key_jwt' ) === ( $authorization['token_endpoint_auth_methods_supported'] ?? array() ), 'Authorization metadata does not require ChatGPT private_key_jwt client authentication.' );
wpai_issue6_oauth_assert( array( 'RS256' ) === ( $authorization['token_endpoint_auth_signing_alg_values_supported'] ?? array() ), 'Authorization metadata does not advertise RS256 client assertions.' );
wpai_issue6_oauth_assert( in_array( 'refresh_token', $authorization['grant_types_supported'] ?? array(), true ), 'Authorization metadata does not advertise refresh_token.' );
wpai_issue6_oauth_assert( true === ( $authorization['authorization_response_iss_parameter_supported'] ?? false ), 'Authorization metadata does not advertise authorization-response issuer identification.' );

// Omitting scope must never silently grant offline_access. The least-privilege
// default is the minimum MCP scope, while refresh access remains opt-in.
$validate_authorization = new ReflectionMethod( OAuth_Server::class, 'validate_authorization_request' );
$default_scope_request  = $validate_authorization->invoke(
	$oauth,
	array(
		'client_id'             => OAuth_Server::CHATGPT_CLIENT_ID,
		'redirect_uri'          => OAuth_Server::CHATGPT_REDIRECT_URI,
		'response_type'         => 'code',
		'code_challenge'        => str_repeat( 'A', 43 ),
		'code_challenge_method' => 'S256',
		'resource'              => $oauth->mcp_endpoint_url(),
		'scope'                 => '',
		'state'                 => 'wpai-default-scope-test',
	)
);
wpai_issue6_oauth_assert( is_array( $default_scope_request ), 'Authorization request without scope was not accepted.' );
wpai_issue6_oauth_assert( OAuth_Server::SCOPE_MCP === ( $default_scope_request['scope'] ?? '' ), 'Missing OAuth scope silently granted more than mcp:use.' );
$routes = rest_get_server()->get_routes();
wpai_issue6_oauth_assert( isset( $routes[ OAuth_Server::MCP_REQUEST_ROUTE ] ), 'Bridge-owned direct MCP REST route was not registered by MCP Adapter.' );
wpai_issue6_oauth_assert( isset( $routes['/wp-ai-bridge/v1/oauth/token'] ), 'OAuth token REST route was not registered.' );
wpai_issue6_oauth_assert( isset( $routes['/wp-ai-bridge/v1/oauth/revoke'] ), 'OAuth revocation REST route was not registered.' );

$verifier  = str_repeat( 'A', 64 );
$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

$missing_client_auth = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$missing_client_auth->set_param( 'grant_type', 'unsupported_fixture' );
$missing_client_auth_response = $oauth->handle_token_request( $missing_client_auth );
wpai_issue6_oauth_assert( 'invalid_client' === ( wpai_issue6_oauth_data( $missing_client_auth_response )['error'] ?? '' ), 'Token endpoint accepted missing private_key_jwt client authentication.' );

$replay_assertion = wpai_issue6_oauth_client_assertion( $oauth->token_endpoint_url(), 'wpai-client-assertion-replay' );
$assertion_probe  = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$assertion_probe->set_param( 'grant_type', 'unsupported_fixture' );
wpai_issue6_oauth_apply_client_auth( $assertion_probe, $oauth->token_endpoint_url(), $replay_assertion );
$assertion_probe_response = $oauth->handle_token_request( $assertion_probe );
wpai_issue6_oauth_assert( 'unsupported_grant_type' === ( wpai_issue6_oauth_data( $assertion_probe_response )['error'] ?? '' ), 'Valid private_key_jwt client authentication did not reach grant processing.' );
$assertion_replay = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$assertion_replay->set_param( 'grant_type', 'unsupported_fixture' );
wpai_issue6_oauth_apply_client_auth( $assertion_replay, $oauth->token_endpoint_url(), $replay_assertion );
$assertion_replay_response = $oauth->handle_token_request( $assertion_replay );
wpai_issue6_oauth_assert( 'invalid_client' === ( wpai_issue6_oauth_data( $assertion_replay_response )['error'] ?? '' ), 'private_key_jwt client assertion replay was accepted.' );

$bad_signature          = wpai_issue6_oauth_client_assertion( $oauth->token_endpoint_url() );
$bad_signature_parts    = explode( '.', $bad_signature );
$bad_signature_parts[2] = ( 'A' === $bad_signature_parts[2][0] ? 'B' : 'A' ) . substr( $bad_signature_parts[2], 1 );
$bad_signature          = implode( '.', $bad_signature_parts );
$bad_client_auth        = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$bad_client_auth->set_param( 'grant_type', 'unsupported_fixture' );
wpai_issue6_oauth_apply_client_auth( $bad_client_auth, $oauth->token_endpoint_url(), $bad_signature );
$bad_client_auth_response = $oauth->handle_token_request( $bad_client_auth );
wpai_issue6_oauth_assert( 'invalid_client' === ( wpai_issue6_oauth_data( $bad_client_auth_response )['error'] ?? '' ), 'Invalid private_key_jwt signature was accepted.' );

$wrong_audience_assertion = wpai_issue6_oauth_client_assertion( 'https://attacker.invalid/oauth/token' );
$wrong_audience_request   = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$wrong_audience_request->set_param( 'grant_type', 'unsupported_fixture' );
wpai_issue6_oauth_apply_client_auth( $wrong_audience_request, $oauth->token_endpoint_url(), $wrong_audience_assertion );
$wrong_audience_response = $oauth->handle_token_request( $wrong_audience_request );
wpai_issue6_oauth_assert( 'invalid_client' === ( wpai_issue6_oauth_data( $wrong_audience_response )['error'] ?? '' ), 'private_key_jwt with a foreign audience was accepted.' );

$unknown_kid_assertion = wpai_issue6_oauth_client_assertion( $oauth->token_endpoint_url(), '', array(), array( 'kid' => 'unknown-chatgpt-key' ) );
$unknown_kid_request   = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$unknown_kid_request->set_param( 'grant_type', 'unsupported_fixture' );
wpai_issue6_oauth_apply_client_auth( $unknown_kid_request, $oauth->token_endpoint_url(), $unknown_kid_assertion );
$unknown_kid_response = $oauth->handle_token_request( $unknown_kid_request );
wpai_issue6_oauth_assert( 'invalid_client' === ( wpai_issue6_oauth_data( $unknown_kid_response )['error'] ?? '' ), 'private_key_jwt signed under an unknown kid was accepted.' );
wpai_issue6_oauth_assert( 1 === ( $GLOBALS['wpai_issue6_jwks_fetches'] ?? 0 ), 'Unknown private_key_jwt kid bypassed the JWKS refresh cooldown.' );

$mcp_only_claims   = array(
	'user_id'        => $authenticated_user_id,
	'client_id'      => OAuth_Server::CHATGPT_CLIENT_ID,
	'redirect_uri'   => OAuth_Server::CHATGPT_REDIRECT_URI,
	'code_challenge' => $challenge,
	'resource'       => $oauth->mcp_endpoint_url(),
	'scope'          => OAuth_Server::SCOPE_MCP,
);
$mcp_only_code     = $store->issue( OAuth_Store::TYPE_CODE, $mcp_only_claims, OAuth_Server::CODE_TTL );
$mcp_only_response = wpai_issue6_oauth_token_request(
	$oauth,
	array(
		'grant_type'    => 'authorization_code',
		'code'          => $mcp_only_code,
		'client_id'     => OAuth_Server::CHATGPT_CLIENT_ID,
		'redirect_uri'  => OAuth_Server::CHATGPT_REDIRECT_URI,
		'resource'      => $oauth->mcp_endpoint_url(),
		'code_verifier' => $verifier,
	)
);
wpai_issue6_oauth_assert( 200 === $mcp_only_response->get_status(), 'MCP-only authorization code exchange failed.' );
$mcp_only_data = wpai_issue6_oauth_data( $mcp_only_response );
wpai_issue6_oauth_assert( ! isset( $mcp_only_data['refresh_token'] ), 'Refresh token was issued without offline_access.' );
wpai_issue6_oauth_assert( OAuth_Server::SCOPE_MCP === ( $mcp_only_data['scope'] ?? '' ), 'MCP-only token response changed the authorized scope.' );
if ( ! empty( $mcp_only_data['access_token'] ) ) {
	$store->revoke( (string) $mcp_only_data['access_token'] );
}

$scope  = OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE;
$claims = array(
	'user_id'        => $authenticated_user_id,
	'client_id'      => OAuth_Server::CHATGPT_CLIENT_ID,
	'redirect_uri'   => OAuth_Server::CHATGPT_REDIRECT_URI,
	'code_challenge' => $challenge,
	'resource'       => $oauth->mcp_endpoint_url(),
	'scope'          => $scope,
);

$code = $store->issue( OAuth_Store::TYPE_CODE, $claims, OAuth_Server::CODE_TTL );
wpai_issue6_oauth_assert( 0 === strpos( $code, 'wpai_c.' ), 'Authorization code is not an opaque Bridge code.' );

$token_response = wpai_issue6_oauth_token_request(
	$oauth,
	array(
		'grant_type'    => 'authorization_code',
		'code'          => $code,
		'client_id'     => OAuth_Server::CHATGPT_CLIENT_ID,
		'redirect_uri'  => OAuth_Server::CHATGPT_REDIRECT_URI,
		'resource'      => $oauth->mcp_endpoint_url(),
		'code_verifier' => $verifier,
	)
);
wpai_issue6_oauth_assert( 200 === $token_response->get_status(), 'Valid authorization code + PKCE exchange failed.' );
$token_data = wpai_issue6_oauth_data( $token_response );
$access     = isset( $token_data['access_token'] ) ? (string) $token_data['access_token'] : '';
$refresh    = isset( $token_data['refresh_token'] ) ? (string) $token_data['refresh_token'] : '';
wpai_issue6_oauth_assert( 0 === strpos( $access, 'wpai_a.' ), 'Access token is not an opaque Bridge access token.' );
wpai_issue6_oauth_assert( 0 === strpos( $refresh, 'wpai_r.' ), 'Refresh token is not an opaque Bridge refresh token.' );
wpai_issue6_oauth_assert( 'Bearer' === ( $token_data['token_type'] ?? '' ), 'Token response does not use Bearer token_type.' );
wpai_issue6_oauth_assert( ( $token_data['scope'] ?? '' ) === $scope, 'Token response changed the authorized scope.' );
wpai_issue6_oauth_assert( OAuth_Server::ACCESS_TTL === ( $token_data['expires_in'] ?? 0 ), 'Token response does not advertise the bounded access-token lifetime.' );
wpai_issue6_oauth_assert( 'no-store' === ( $token_response->get_headers()['Cache-Control'] ?? '' ), 'Token response is not cache-disabled.' );

$access_parts = explode( '.', $access );
wpai_issue6_oauth_assert( 3 === count( $access_parts ), 'Access token shape is invalid.' );
$stored_access = get_transient( 'wpai_oauth_access_' . $access_parts[1] );
wpai_issue6_oauth_assert( is_array( $stored_access ) && ! empty( $stored_access['secret_hash'] ), 'Access-token backing record does not contain a secret hash.' );
wpai_issue6_oauth_assert( false === strpos( serialize( $stored_access ), $access ), 'Plaintext access token was persisted in its backing transient.' );
wpai_issue6_oauth_assert( false === strpos( serialize( $stored_access ), $access_parts[2] ), 'Plaintext access-token secret was persisted in its backing transient.' );

$replay_response = wpai_issue6_oauth_token_request(
	$oauth,
	array(
		'grant_type'    => 'authorization_code',
		'code'          => $code,
		'client_id'     => OAuth_Server::CHATGPT_CLIENT_ID,
		'redirect_uri'  => OAuth_Server::CHATGPT_REDIRECT_URI,
		'resource'      => $oauth->mcp_endpoint_url(),
		'code_verifier' => $verifier,
	)
);
wpai_issue6_oauth_assert( 400 === $replay_response->get_status(), 'Authorization code replay was accepted.' );
wpai_issue6_oauth_assert( 'invalid_grant' === ( wpai_issue6_oauth_data( $replay_response )['error'] ?? '' ), 'Authorization code replay did not fail as invalid_grant.' );

$bad_code = $store->issue( OAuth_Store::TYPE_CODE, $claims, OAuth_Server::CODE_TTL );
$bad_pkce = wpai_issue6_oauth_token_request(
	$oauth,
	array(
		'grant_type'    => 'authorization_code',
		'code'          => $bad_code,
		'client_id'     => OAuth_Server::CHATGPT_CLIENT_ID,
		'redirect_uri'  => OAuth_Server::CHATGPT_REDIRECT_URI,
		'resource'      => $oauth->mcp_endpoint_url(),
		'code_verifier' => str_repeat( 'B', 64 ),
	)
);
wpai_issue6_oauth_assert( 400 === $bad_pkce->get_status(), 'Wrong PKCE verifier was accepted.' );
wpai_issue6_oauth_assert( 'invalid_grant' === ( wpai_issue6_oauth_data( $bad_pkce )['error'] ?? '' ), 'Wrong PKCE verifier did not fail as invalid_grant.' );

wp_set_current_user( 0 );
$missing_request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
wpai_issue6_oauth_assert( false === $oauth->authenticate_mcp_request( $missing_request ), 'Direct MCP transport accepted a request without a Bearer token.' );
$challenge_response = $oauth->add_mcp_authentication_challenge( new WP_REST_Response( array( 'code' => 'rest_forbidden' ), 403 ), rest_get_server(), $missing_request );
$challenge_headers  = $challenge_response->get_headers();
wpai_issue6_oauth_assert( 401 === $challenge_response->get_status(), 'Missing Bearer token did not produce HTTP 401.' );
wpai_issue6_oauth_assert( false !== strpos( $challenge_headers['WWW-Authenticate'] ?? '', 'Bearer resource_metadata="' . $oauth->protected_resource_metadata_url() . '"' ), 'HTTP 401 does not advertise protected-resource metadata.' );
wpai_issue6_oauth_assert( 'no-store' === ( $challenge_headers['Cache-Control'] ?? '' ), 'Authentication challenge is cacheable.' );

$invalid_request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$invalid_request->set_header( 'Authorization', 'Bearer wpai_a.invalid.invalid' );
wpai_issue6_oauth_assert( false === $oauth->authenticate_mcp_request( $invalid_request ), 'Malformed/unknown Bearer token was accepted.' );

$authorized_request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$authorized_request->set_header( 'Authorization', 'Bearer ' . $access );
wpai_issue6_oauth_assert( true === $oauth->authenticate_mcp_request( $authorized_request ), 'Valid resource-bound access token did not authenticate the WordPress user.' );
wpai_issue6_oauth_assert( get_current_user_id() === $authenticated_user_id, 'Bearer token did not restore the authorized WordPress user identity.' );

$initialize = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$initialize->set_header( 'Authorization', 'Bearer ' . $access );
$initialize->set_header( 'Accept', 'application/json, text/event-stream' );
$initialize->set_header( 'Content-Type', 'application/json' );
$initialize->set_body(
	wp_json_encode(
		array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => (object) array(),
				'clientInfo'      => array(
					'name'    => 'wp-native-builder-direct-oauth-smoke',
					'version' => '1.0.0',
				),
			),
		)
	)
);
$initialized = rest_do_request( $initialize );
wpai_issue6_oauth_assert( 200 === $initialized->get_status(), 'OAuth-authenticated direct MCP initialize did not return HTTP 200.' );
$initialized_data = wpai_issue6_oauth_data( $initialized );
wpai_issue6_oauth_assert( '2025-11-25' === ( $initialized_data['result']['protocolVersion'] ?? '' ), 'Direct OAuth MCP route did not negotiate the expected MCP protocol.' );

$refresh_response = wpai_issue6_oauth_token_request(
	$oauth,
	array(
		'grant_type'    => 'refresh_token',
		'refresh_token' => $refresh,
		'client_id'     => OAuth_Server::CHATGPT_CLIENT_ID,
		'resource'      => $oauth->mcp_endpoint_url(),
	)
);
wpai_issue6_oauth_assert( 200 === $refresh_response->get_status(), 'Valid refresh token rotation failed.' );
$refreshed   = wpai_issue6_oauth_data( $refresh_response );
$new_access  = isset( $refreshed['access_token'] ) ? (string) $refreshed['access_token'] : '';
$new_refresh = isset( $refreshed['refresh_token'] ) ? (string) $refreshed['refresh_token'] : '';
wpai_issue6_oauth_assert( '' !== $new_access && $new_access !== $access, 'Refresh did not rotate the access token.' );
wpai_issue6_oauth_assert( '' !== $new_refresh && $new_refresh !== $refresh, 'Refresh did not rotate the refresh token.' );

$refresh_replay = wpai_issue6_oauth_token_request(
	$oauth,
	array(
		'grant_type'    => 'refresh_token',
		'refresh_token' => $refresh,
		'client_id'     => OAuth_Server::CHATGPT_CLIENT_ID,
		'resource'      => $oauth->mcp_endpoint_url(),
	)
);
wpai_issue6_oauth_assert( 400 === $refresh_replay->get_status(), 'Rotated refresh token was reusable.' );
wpai_issue6_oauth_assert( 'invalid_grant' === ( wpai_issue6_oauth_data( $refresh_replay )['error'] ?? '' ), 'Refresh-token replay did not fail as invalid_grant.' );

$revoke = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/revoke' );
$revoke->set_param( 'token', $new_access );
wpai_issue6_oauth_apply_client_auth( $revoke, $oauth->revocation_endpoint_url() );
$revoke_response = $oauth->handle_revoke_request( $revoke );
wpai_issue6_oauth_assert( 200 === $revoke_response->get_status(), 'Access-token revocation endpoint failed.' );
wpai_issue6_oauth_assert( 'no-store' === ( $revoke_response->get_headers()['Cache-Control'] ?? '' ), 'Revocation response is cacheable.' );

$revoked_request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$revoked_request->set_header( 'Authorization', 'Bearer ' . $new_access );
wpai_issue6_oauth_assert( false === $oauth->authenticate_mcp_request( $revoked_request ), 'Revoked access token remained valid.' );

$store->revoke( $access );
$store->revoke( $new_refresh );
remove_filter( 'pre_http_request', $wpai_issue6_http_mock, 10 );
delete_transient( OAuth_Server::CLIENT_METADATA_CACHE );
delete_transient( Client_Assertion_Validator::JWKS_CACHE );
delete_transient( Client_Assertion_Validator::JWKS_REFRESH_COOLDOWN );
wp_set_current_user( $authenticated_user_id );

echo "PASS: Issue #6 direct ChatGPT OAuth/MCP smoke.\n";
