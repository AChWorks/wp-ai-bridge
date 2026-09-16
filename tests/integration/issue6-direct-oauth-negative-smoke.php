<?php
/**
 * Negative live WordPress regressions for the direct ChatGPT OAuth/MCP boundary.
 *
 * Run with:
 * wp eval-file tests/integration/issue6-direct-oauth-negative-smoke.php --user=<administrator>
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;

function wpai_issue6_oauth_negative_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue6_oauth_bearer_request( $token ) {
	$request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	return $request;
}

$user_id = get_current_user_id();
wpai_issue6_oauth_negative_assert( $user_id > 0, 'Run this smoke as an authenticated WordPress user.' );

$store = new OAuth_Store();
$oauth = new OAuth_Server( $store );
$scope = OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE;

$base_claims = array(
	'user_id'   => $user_id,
	'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
	'resource'  => $oauth->mcp_endpoint_url(),
	'scope'     => $scope,
);

// A structurally valid access token for any other OAuth resource must fail closed.
$wrong_resource = $store->issue(
	OAuth_Store::TYPE_ACCESS,
	array_merge(
		$base_claims,
		array(
			'resource' => 'https://invalid.example/wp-json/wp-ai-bridge/v1/mcp',
		)
	),
	60
);
wp_set_current_user( 0 );
wpai_issue6_oauth_negative_assert(
	false === $oauth->authenticate_mcp_request( wpai_issue6_oauth_bearer_request( $wrong_resource ) ),
	'A valid access token bound to another resource was accepted.'
);
$store->revoke( $wrong_resource );

// Access tokens minted for another OAuth client must not cross the ChatGPT client boundary.
$wrong_client = $store->issue(
	OAuth_Store::TYPE_ACCESS,
	array_merge(
		$base_claims,
		array(
			'client_id' => 'https://invalid.example/oauth/client.json',
		)
	),
	60
);
wp_set_current_user( 0 );
wpai_issue6_oauth_negative_assert(
	false === $oauth->authenticate_mcp_request( wpai_issue6_oauth_bearer_request( $wrong_client ) ),
	'Access token bound to another OAuth client was accepted.'
);
wpai_issue6_oauth_negative_assert( 0 === get_current_user_id(), 'Rejected wrong-client Bearer authentication changed the current WordPress user.' );
$store->revoke( $wrong_client );

// Expiry is checked from server-side state and an expired artifact is removed on read.
$expired = $store->issue( OAuth_Store::TYPE_ACCESS, $base_claims, 60 );
$parts   = explode( '.', $expired );
wpai_issue6_oauth_negative_assert( 3 === count( $parts ), 'Expired-token fixture shape is invalid.' );
$transient_key = 'wpai_oauth_access_' . $parts[1];
$stored        = get_transient( $transient_key );
wpai_issue6_oauth_negative_assert( is_array( $stored ), 'Expired-token fixture backing state is unavailable.' );
$stored['expires_at'] = time() - 1;
set_transient( $transient_key, $stored, 60 );
wp_set_current_user( 0 );
wpai_issue6_oauth_negative_assert(
	false === $oauth->authenticate_mcp_request( wpai_issue6_oauth_bearer_request( $expired ) ),
	'Expired access token was accepted.'
);
wpai_issue6_oauth_negative_assert( false === get_transient( $transient_key ), 'Expired access-token state was not deleted after validation.' );

// Token validity never substitutes for a live WordPress user identity.
$missing_user = $store->issue(
	OAuth_Store::TYPE_ACCESS,
	array_merge(
		$base_claims,
		array(
			'user_id' => 999999999,
		)
	),
	60
);
wp_set_current_user( 0 );
wpai_issue6_oauth_negative_assert(
	false === $oauth->authenticate_mcp_request( wpai_issue6_oauth_bearer_request( $missing_user ) ),
	'Access token for a missing WordPress user was accepted.'
);
$store->revoke( $missing_user );

// OAuth credentials must never be accepted or mutated when the canonical MCP resource is not HTTPS.
$secure_home    = get_option( 'home' );
$secure_siteurl = get_option( 'siteurl' );
$insecure_token = $store->issue( OAuth_Store::TYPE_ACCESS, $base_claims, 60 );
try {
	update_option( 'home', 'http://localhost', false );
	update_option( 'siteurl', 'http://localhost', false );
	$insecure_oauth = new OAuth_Server( $store );
	wpai_issue6_oauth_negative_assert( false === $insecure_oauth->is_https_ready(), 'HTTP fixture unexpectedly reports HTTPS readiness.' );

	wp_set_current_user( 0 );
	wpai_issue6_oauth_negative_assert(
		false === $insecure_oauth->authenticate_mcp_request( wpai_issue6_oauth_bearer_request( $insecure_token ) ),
		'Bearer authentication was accepted for an HTTP MCP resource.'
	);
	wpai_issue6_oauth_negative_assert( 0 === get_current_user_id(), 'Rejected HTTP Bearer authentication changed the current WordPress user.' );

	$insecure_request = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
	$insecure_request->set_param( 'grant_type', 'refresh_token' );
	$insecure_response = $insecure_oauth->handle_token_request( $insecure_request );
	wpai_issue6_oauth_negative_assert( 400 === $insecure_response->get_status(), 'HTTP token endpoint did not fail closed.' );
	wpai_issue6_oauth_negative_assert( 'invalid_request' === ( $insecure_response->get_data()['error'] ?? '' ), 'HTTP token endpoint returned the wrong OAuth error.' );

	$revoke_request = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/revoke' );
	$revoke_request->set_param( 'token', $insecure_token );
	$revoke_response = $insecure_oauth->handle_revoke_request( $revoke_request );
	wpai_issue6_oauth_negative_assert( 400 === $revoke_response->get_status(), 'HTTP revocation endpoint did not fail closed.' );
} finally {
	update_option( 'home', $secure_home, false );
	update_option( 'siteurl', $secure_siteurl, false );
}

$restored_oauth = new OAuth_Server( $store );
wp_set_current_user( 0 );
wpai_issue6_oauth_negative_assert(
	true === $restored_oauth->authenticate_mcp_request( wpai_issue6_oauth_bearer_request( $insecure_token ) ),
	'HTTP revocation attempt mutated a token despite the fail-closed transport guard.'
);
$store->revoke( $insecure_token );

wp_set_current_user( $user_id );
echo "PASS: Issue #6 direct OAuth negative bearer regressions.\n";
