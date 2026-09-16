<?php
/**
 * Real-WordPress integration coverage for Issue #54 approved OAuth clients.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Auth\Approved_OAuth_Clients;
use WP_Native_Builder_Bridge\Auth\Client_Assertion_Validator;
use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Auth\OAuth_Store;

function wpai_issue54_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue54_data( $response ) {
	return json_decode( wp_json_encode( $response->get_data() ), true );
}

function wpai_issue54_b64( $value ) {
	return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
}

function wpai_issue54_key_fixture( $kid ) {
	$key = openssl_pkey_new(
		array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		)
	);
	wpai_issue54_assert( false !== $key, 'Could not generate an RSA test key.' );
	$details = openssl_pkey_get_details( $key );
	wpai_issue54_assert( is_array( $details ) && ! empty( $details['rsa']['n'] ) && ! empty( $details['rsa']['e'] ), 'RSA key details are unavailable.' );
	return array(
		'private' => $key,
		'jwk'     => array(
			'kty' => 'RSA',
			'kid' => $kid,
			'use' => 'sig',
			'alg' => 'RS256',
			'n'   => wpai_issue54_b64( $details['rsa']['n'] ),
			'e'   => wpai_issue54_b64( $details['rsa']['e'] ),
		),
	);
}

function wpai_issue54_assertion( $client_id, $audience, $fixture, $jti = '', array $claim_overrides = array(), array $header_overrides = array() ) {
	$header = array_merge(
		array(
			'alg' => 'RS256',
			'kid' => $fixture['jwk']['kid'],
			'typ' => 'JWT',
		),
		$header_overrides
	);
	$claims = array_merge(
		array(
			'iss' => $client_id,
			'sub' => $client_id,
			'aud' => $audience,
			'iat' => time(),
			'exp' => time() + 120,
			'jti' => '' !== $jti ? $jti : bin2hex( random_bytes( 16 ) ),
		),
		$claim_overrides
	);
	$input     = wpai_issue54_b64( wp_json_encode( $header ) ) . '.' . wpai_issue54_b64( wp_json_encode( $claims ) );
	$signature = '';
	wpai_issue54_assert( openssl_sign( $input, $signature, $fixture['private'], OPENSSL_ALGO_SHA256 ), 'Could not sign OAuth client assertion.' );
	return $input . '.' . wpai_issue54_b64( $signature );
}

function wpai_issue54_auth( WP_REST_Request $request, $assertion ) {
	$request->set_param( 'client_assertion_type', Client_Assertion_Validator::ASSERTION_TYPE );
	$request->set_param( 'client_assertion', $assertion );
}

function wpai_issue54_token_request( OAuth_Server $oauth, array $params, $assertion ) {
	$request = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
	foreach ( $params as $name => $value ) {
		$request->set_param( $name, $value );
	}
	wpai_issue54_auth( $request, $assertion );
	return $oauth->handle_token_request( $request );
}

function wpai_issue54_metadata_cache( $client_id ) {
	return 'wpai_oauth_client_meta_' . substr( hash( 'sha256', $client_id ), 0, 24 );
}

function wpai_issue54_jwks_cache( $client_id ) {
	return 'wpai_oauth_jwks_' . substr( hash( 'sha256', $client_id ), 0, 24 );
}

function wpai_issue54_jwks_cooldown( $client_id ) {
	return 'wpai_oauth_jwks_refresh_' . substr( hash( 'sha256', $client_id ), 0, 24 );
}

wpai_issue54_assert( get_current_user_id() > 0, 'Run Issue #54 integration as an authenticated WordPress user.' );
wpai_issue54_assert( function_exists( 'openssl_pkey_new' ), 'OpenSSL is required for approved-client integration.' );
$user_id = get_current_user_id();

$client_a   = 'https://www.example.com/wp-ai-bridge/client-a.json';
$client_b   = 'https://www.example.org/wp-ai-bridge/client-b.json';
$redirect_a = 'https://www.example.com/wp-ai-bridge/callback-a';
$redirect_b = 'https://www.example.org/wp-ai-bridge/callback-b';
$jwks_a     = 'https://www.example.com/wp-ai-bridge/jwks-a.json';
$jwks_b     = 'https://www.example.org/wp-ai-bridge/jwks-b.json';
$key_a1     = wpai_issue54_key_fixture( 'gateway-a-1' );
$key_a2     = wpai_issue54_key_fixture( 'gateway-a-2' );
$key_b      = wpai_issue54_key_fixture( 'gateway-b-1' );

$original_clients  = get_option( Approved_OAuth_Clients::OPTION_NAME, null );
$original_revision = get_option( Approved_OAuth_Clients::REVISION_OPTION, null );
$registry          = new Approved_OAuth_Clients();
$store             = new OAuth_Store();
$oauth             = new OAuth_Server( $store, $registry );

// Fresh-install/upgrade semantics: zero additional clients; ChatGPT remains built in.
delete_option( Approved_OAuth_Clients::OPTION_NAME );
delete_option( Approved_OAuth_Clients::REVISION_OPTION );
wpai_issue54_assert( array() === $registry->all(), 'Additional OAuth clients did not default to none.' );
wpai_issue54_assert( $registry->is_approved( OAuth_Server::CHATGPT_CLIENT_ID ), 'Built-in ChatGPT client unexpectedly requires approval.' );
wpai_issue54_assert( ! $registry->is_approved( $client_a ), 'Unapproved Gateway client was accepted.' );

// Approval sanitizer is exact public HTTPS only and excludes the built-in identity.
$sanitized = $registry->sanitize( implode( "\n", array( $client_a, $client_b, 'http://www.example.net/client.json', 'https://127.0.0.1/client.json', OAuth_Server::CHATGPT_CLIENT_ID, $client_a ) ) );
wpai_issue54_assert( array( $client_a, $client_b ) === $sanitized, 'Approved client sanitizer did not enforce exact public HTTPS identities/deduplication.' );
update_option( Approved_OAuth_Clients::OPTION_NAME, $sanitized, false );
$approved_revision = $registry->revision();
wpai_issue54_assert( $approved_revision > 1, 'Initial additional-client approval did not rotate approval revision.' );

$GLOBALS['wpai_issue54_mode']       = 'normal';
$GLOBALS['wpai_issue54_a_jwk']      = $key_a1['jwk'];
$GLOBALS['wpai_issue54_http_calls'] = array();
$metadata_a = static function () use ( $client_a, $redirect_a, $jwks_a ) {
	return array(
		'client_id'                  => $client_a,
		'client_name'                => 'Controlled MCP Gateway A',
		'redirect_uris'              => array( $redirect_a ),
		'grant_types'                => array( 'authorization_code', 'refresh_token' ),
		'response_types'             => array( 'code' ),
		'token_endpoint_auth_method' => 'private_key_jwt',
		'jwks_uri'                   => $jwks_a,
	);
};
$metadata_b = static function () use ( $client_b, $redirect_b, $jwks_b ) {
	return array(
		'client_id'                  => $client_b,
		'client_name'                => 'Controlled MCP Gateway B',
		'redirect_uris'              => array( $redirect_b ),
		'grant_types'                => array( 'authorization_code', 'refresh_token' ),
		'response_types'             => array( 'code' ),
		'token_endpoint_auth_method' => 'private_key_jwt',
		'jwks_uri'                   => $jwks_b,
	);
};
$http_mock = static function ( $preempt, $args, $url ) use ( $client_a, $client_b, $jwks_a, $jwks_b, $key_b, $metadata_a, $metadata_b ) {
	$GLOBALS['wpai_issue54_http_calls'][] = array( 'url' => $url, 'args' => $args );
	wpai_issue54_assert( 0 === (int) ( $args['redirection'] ?? -1 ), 'OAuth metadata/JWKS retrieval allowed redirects.' );
	wpai_issue54_assert( (int) ( $args['timeout'] ?? 0 ) <= 8, 'OAuth metadata/JWKS retrieval exceeded timeout bound.' );
	wpai_issue54_assert( 65537 === (int) ( $args['limit_response_size'] ?? 0 ), 'OAuth metadata/JWKS retrieval lost response-size bound.' );
	$mode = $GLOBALS['wpai_issue54_mode'];
	if ( 'timeout' === $mode && $client_b === $url ) {
		return new WP_Error( 'http_request_failed', 'fixture timeout' );
	}
	if ( 'redirect' === $mode && $client_b === $url ) {
		return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 302, 'message' => 'Found' ), 'cookies' => array(), 'filename' => null );
	}
	if ( 'oversize' === $mode && $client_b === $url ) {
		return array( 'headers' => array(), 'body' => str_repeat( 'A', 65537 ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	if ( 'invalid-json' === $mode && $client_b === $url ) {
		return array( 'headers' => array(), 'body' => '{invalid', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	if ( $client_a === $url ) {
		$body = $metadata_a();
	} elseif ( $client_b === $url ) {
		$body = $metadata_b();
		if ( 'private-jwks' === $mode ) {
			$body['jwks_uri'] = 'https://127.0.0.1/jwks.json';
		}
		if ( 'cross-identity' === $mode ) {
			$body['client_id'] = $client_a;
		}
	} elseif ( $jwks_a === $url ) {
		$body = array( 'keys' => array( $GLOBALS['wpai_issue54_a_jwk'] ) );
	} elseif ( $jwks_b === $url ) {
		$body = array( 'keys' => array( $key_b['jwk'] ) );
	} else {
		return $preempt;
	}
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( $body ),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $http_mock, 10, 3 );

$validate_authorization = new ReflectionMethod( OAuth_Server::class, 'validate_authorization_request' );
$redirect_origin = new ReflectionMethod( OAuth_Server::class, 'redirect_origin' );
wpai_issue54_assert( 'https://www.example.com' === $redirect_origin->invoke( $oauth, $redirect_a ), 'Approved callback origin was not confined to its exact HTTPS origin.' );
$verifier               = str_repeat( 'A', 64 );
$challenge              = wpai_issue54_b64( hash( 'sha256', $verifier, true ) );
$base_auth               = array(
	'client_id'             => $client_a,
	'redirect_uri'          => $redirect_a,
	'response_type'         => 'code',
	'code_challenge'        => $challenge,
	'code_challenge_method' => 'S256',
	'resource'              => $oauth->mcp_endpoint_url(),
	'scope'                 => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
	'state'                 => 'gateway-state',
);
$validated = $validate_authorization->invoke( $oauth, $base_auth );
wpai_issue54_assert( is_array( $validated ), 'Approved Gateway authorization request was rejected.' );
wpai_issue54_assert( $client_a === ( $validated['client_id'] ?? '' ), 'Validated authorization changed Gateway identity.' );
wpai_issue54_assert( $approved_revision === ( $validated['client_revision'] ?? 0 ), 'Authorization did not bind current approval revision.' );

$unapproved = $base_auth;
$unapproved['client_id'] = 'https://www.example.net/unapproved-client.json';
$unapproved_result = $validate_authorization->invoke( $oauth, $unapproved );
wpai_issue54_assert( is_wp_error( $unapproved_result ) && 'invalid_client' === $unapproved_result->get_error_code(), 'Unapproved client reached authorization.' );
$wrong_redirect = $base_auth;
$wrong_redirect['redirect_uri'] = 'https://www.example.com/attacker-callback';
$wrong_redirect_result = $validate_authorization->invoke( $oauth, $wrong_redirect );
wpai_issue54_assert( is_wp_error( $wrong_redirect_result ), 'Unregistered redirect URI was accepted.' );

// Metadata network/profile negatives stay bounded and fail closed.
foreach ( array( 'timeout' => 'temporarily_unavailable', 'redirect' => 'invalid_client', 'oversize' => 'invalid_client', 'invalid-json' => 'invalid_client', 'private-jwks' => 'invalid_client', 'cross-identity' => 'invalid_client' ) as $mode => $expected_error ) {
	$GLOBALS['wpai_issue54_mode'] = $mode;
	delete_transient( wpai_issue54_metadata_cache( $client_b ) );
	$result = $registry->resolve( $client_b );
	wpai_issue54_assert( is_wp_error( $result ) && $expected_error === $result->get_error_code(), 'Metadata negative did not fail closed for mode: ' . $mode );
}
$GLOBALS['wpai_issue54_mode'] = 'normal';
delete_transient( wpai_issue54_metadata_cache( $client_b ) );
wpai_issue54_assert( is_array( $registry->resolve( $client_b ) ), 'Approved Gateway B metadata did not recover after negative fixtures.' );

// Same jti remains independent across approved clients, while per-client replay is denied.
$shared_jti = 'shared-jti-across-clients';
$probe_a = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$probe_a->set_param( 'grant_type', 'unsupported_fixture' );
$assertion_a_shared = wpai_issue54_assertion( $client_a, $oauth->token_endpoint_url(), $key_a1, $shared_jti );
wpai_issue54_auth( $probe_a, $assertion_a_shared );
$probe_a_response = $oauth->handle_token_request( $probe_a );
wpai_issue54_assert( 'unsupported_grant_type' === ( wpai_issue54_data( $probe_a_response )['error'] ?? '' ), 'Gateway A valid assertion did not reach grant processing.' );
$probe_b = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$probe_b->set_param( 'grant_type', 'unsupported_fixture' );
$assertion_b_shared = wpai_issue54_assertion( $client_b, $oauth->token_endpoint_url(), $key_b, $shared_jti );
wpai_issue54_auth( $probe_b, $assertion_b_shared );
$probe_b_response = $oauth->handle_token_request( $probe_b );
wpai_issue54_assert( 'unsupported_grant_type' === ( wpai_issue54_data( $probe_b_response )['error'] ?? '' ), 'Equal jti collided across approved clients.' );
$replay_a = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$replay_a->set_param( 'grant_type', 'unsupported_fixture' );
wpai_issue54_auth( $replay_a, $assertion_a_shared );
wpai_issue54_assert( 'invalid_client' === ( wpai_issue54_data( $oauth->handle_token_request( $replay_a ) )['error'] ?? '' ), 'Gateway A assertion replay was accepted.' );

// Cross-client signing, audience, lifetime, and algorithm confusion are denied.
$cross_signed = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$cross_signed->set_param( 'grant_type', 'unsupported_fixture' );
wpai_issue54_auth( $cross_signed, wpai_issue54_assertion( $client_b, $oauth->token_endpoint_url(), $key_a1 ) );
wpai_issue54_assert( 'invalid_client' === ( wpai_issue54_data( $oauth->handle_token_request( $cross_signed ) )['error'] ?? '' ), 'Client A key authenticated as Client B.' );
$wrong_aud = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$wrong_aud->set_param( 'grant_type', 'unsupported_fixture' );
wpai_issue54_auth( $wrong_aud, wpai_issue54_assertion( $client_a, 'https://attacker.example/token', $key_a1 ) );
wpai_issue54_assert( 'invalid_client' === ( wpai_issue54_data( $oauth->handle_token_request( $wrong_aud ) )['error'] ?? '' ), 'Foreign client-assertion audience was accepted.' );
$expired = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$expired->set_param( 'grant_type', 'unsupported_fixture' );
wpai_issue54_auth( $expired, wpai_issue54_assertion( $client_a, $oauth->token_endpoint_url(), $key_a1, '', array( 'iat' => time() - 900, 'exp' => time() - 500 ) ) );
wpai_issue54_assert( 'invalid_client' === ( wpai_issue54_data( $oauth->handle_token_request( $expired ) )['error'] ?? '' ), 'Expired client assertion was accepted.' );
$wrong_alg = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$wrong_alg->set_param( 'grant_type', 'unsupported_fixture' );
wpai_issue54_auth( $wrong_alg, wpai_issue54_assertion( $client_a, $oauth->token_endpoint_url(), $key_a1, '', array(), array( 'alg' => 'HS256' ) ) );
wpai_issue54_assert( 'invalid_client' === ( wpai_issue54_data( $oauth->handle_token_request( $wrong_alg ) )['error'] ?? '' ), 'Non-RS256 client assertion was accepted.' );

// Key rotation: an unknown kid can refresh the exact approved JWKS after cooldown expires.
set_transient( wpai_issue54_jwks_cache( $client_a ), array( $key_a1['jwk'] ), 15 * MINUTE_IN_SECONDS );
delete_transient( wpai_issue54_jwks_cooldown( $client_a ) );
$GLOBALS['wpai_issue54_a_jwk'] = $key_a2['jwk'];
$rotated = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$rotated->set_param( 'grant_type', 'unsupported_fixture' );
wpai_issue54_auth( $rotated, wpai_issue54_assertion( $client_a, $oauth->token_endpoint_url(), $key_a2 ) );
wpai_issue54_assert( 'unsupported_grant_type' === ( wpai_issue54_data( $oauth->handle_token_request( $rotated ) )['error'] ?? '' ), 'Approved-client JWKS rotation did not refresh safely.' );

// Complete one controlled Gateway authorization-code + PKCE exchange.
$code_claims = array(
	'user_id'         => $user_id,
	'client_id'       => $client_a,
	'client_revision' => $registry->revision(),
	'redirect_uri'    => $redirect_a,
	'code_challenge'  => $challenge,
	'resource'        => $oauth->mcp_endpoint_url(),
	'scope'           => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
);
$code = $store->issue( OAuth_Store::TYPE_CODE, $code_claims, OAuth_Server::CODE_TTL );
$token_response = wpai_issue54_token_request(
	$oauth,
	array(
		'grant_type'    => 'authorization_code',
		'code'          => $code,
		'client_id'     => $client_a,
		'redirect_uri'  => $redirect_a,
		'resource'      => $oauth->mcp_endpoint_url(),
		'code_verifier' => $verifier,
	),
	wpai_issue54_assertion( $client_a, $oauth->token_endpoint_url(), $key_a2 )
);
wpai_issue54_assert( 200 === $token_response->get_status(), 'Approved Gateway authorization-code exchange failed.' );
$token_data = wpai_issue54_data( $token_response );
$access     = (string) ( $token_data['access_token'] ?? '' );
$refresh    = (string) ( $token_data['refresh_token'] ?? '' );
wpai_issue54_assert( 0 === strpos( $access, 'wpai_a.' ) && 0 === strpos( $refresh, 'wpai_r.' ), 'Gateway did not receive bounded opaque access/refresh artifacts.' );

wp_set_current_user( 0 );
$bearer = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$bearer->set_header( 'Authorization', 'Bearer ' . $access );
wpai_issue54_assert( $oauth->authenticate_mcp_request( $bearer ), 'Approved Gateway resource-bound access token did not authenticate.' );
wpai_issue54_assert( $user_id === get_current_user_id(), 'Gateway token did not restore the exact WordPress principal.' );

// Client B cannot revoke Client A's token even when B itself authenticates correctly.
$revoke_b = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/revoke' );
$revoke_b->set_param( 'token', $access );
wpai_issue54_auth( $revoke_b, wpai_issue54_assertion( $client_b, $oauth->revocation_endpoint_url(), $key_b ) );
wpai_issue54_assert( 200 === $oauth->handle_revoke_request( $revoke_b )->get_status(), 'Cross-client RFC7009 request did not return normal bounded response.' );
wp_set_current_user( 0 );
$still_valid = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$still_valid->set_header( 'Authorization', 'Bearer ' . $access );
wpai_issue54_assert( $oauth->authenticate_mcp_request( $still_valid ), 'Client B revoked Client A token.' );

// Removal immediately blocks authorization, bearer use and refresh; re-approval does not resurrect old artifacts.
$removed = $registry->sanitize( $client_b );
update_option( Approved_OAuth_Clients::OPTION_NAME, $removed, false );
wpai_issue54_assert( ! $registry->is_approved( $client_a ), 'Removed Gateway remained approved.' );
wp_set_current_user( 0 );
$removed_bearer = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$removed_bearer->set_header( 'Authorization', 'Bearer ' . $access );
wpai_issue54_assert( ! $oauth->authenticate_mcp_request( $removed_bearer ), 'Removed Gateway access token remained usable.' );
$removed_auth = $validate_authorization->invoke( $oauth, $base_auth );
wpai_issue54_assert( is_wp_error( $removed_auth ) && 'invalid_client' === $removed_auth->get_error_code(), 'Removed Gateway could still authorize.' );
$removed_refresh = wpai_issue54_token_request(
	$oauth,
	array(
		'grant_type'    => 'refresh_token',
		'refresh_token' => $refresh,
		'client_id'     => $client_a,
		'resource'      => $oauth->mcp_endpoint_url(),
	),
	wpai_issue54_assertion( $client_a, $oauth->token_endpoint_url(), $key_a2 )
);
wpai_issue54_assert( 'invalid_client' === ( wpai_issue54_data( $removed_refresh )['error'] ?? '' ), 'Removed Gateway refreshed an outstanding token.' );

$reapproved = $registry->sanitize( implode( "\n", array( $client_a, $client_b ) ) );
update_option( Approved_OAuth_Clients::OPTION_NAME, $reapproved, false );
wpai_issue54_assert( $registry->revision() > $approved_revision, 'Re-approval did not retain a newer approval revision.' );
wp_set_current_user( 0 );
$old_bearer = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$old_bearer->set_header( 'Authorization', 'Bearer ' . $access );
wpai_issue54_assert( ! $oauth->authenticate_mcp_request( $old_bearer ), 'Re-approval resurrected an old Gateway access token.' );
$old_refresh = wpai_issue54_token_request(
	$oauth,
	array(
		'grant_type'    => 'refresh_token',
		'refresh_token' => $refresh,
		'client_id'     => $client_a,
		'resource'      => $oauth->mcp_endpoint_url(),
	),
	wpai_issue54_assertion( $client_a, $oauth->token_endpoint_url(), $key_a2 )
);
wpai_issue54_assert( 'invalid_grant' === ( wpai_issue54_data( $old_refresh )['error'] ?? '' ), 'Re-approval resurrected an old Gateway refresh token.' );

// Direct ChatGPT artifacts intentionally ignore the additional-client revision and remain valid.
$chatgpt_access = $store->issue(
	OAuth_Store::TYPE_ACCESS,
	array(
		'user_id'   => $user_id,
		'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
		'resource'  => $oauth->mcp_endpoint_url(),
		'scope'     => OAuth_Server::SCOPE_MCP,
	),
	OAuth_Server::ACCESS_TTL
);
wp_set_current_user( 0 );
$chatgpt_bearer = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$chatgpt_bearer->set_header( 'Authorization', 'Bearer ' . $chatgpt_access );
wpai_issue54_assert( $oauth->authenticate_mcp_request( $chatgpt_bearer ), 'Additional-client changes invalidated a direct ChatGPT artifact.' );

// Cleanup fixture state.
$store->revoke( $chatgpt_access );
remove_filter( 'pre_http_request', $http_mock, 10 );
foreach ( array( $client_a, $client_b ) as $client_id ) {
	delete_transient( wpai_issue54_metadata_cache( $client_id ) );
	delete_transient( wpai_issue54_jwks_cache( $client_id ) );
	delete_transient( wpai_issue54_jwks_cooldown( $client_id ) );
}
if ( null === $original_clients ) {
	delete_option( Approved_OAuth_Clients::OPTION_NAME );
} else {
	update_option( Approved_OAuth_Clients::OPTION_NAME, $original_clients, false );
}
if ( null === $original_revision ) {
	delete_option( Approved_OAuth_Clients::REVISION_OPTION );
} else {
	update_option( Approved_OAuth_Clients::REVISION_OPTION, $original_revision, false );
}
wp_set_current_user( $user_id );

echo "PASS: Issue #54 approved OAuth clients integration.\n";
