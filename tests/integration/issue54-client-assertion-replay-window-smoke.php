<?php
/**
 * Real-WordPress regression for the full additional-client assertion replay window.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Auth\Client_Assertion_Validator;
use WP_Native_Builder_Bridge\Auth\OAuth_Store;

function wpai_issue54_replay_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue54_replay_b64( $value ) {
	return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
}

wpai_issue54_replay_assert( function_exists( 'openssl_pkey_new' ), 'OpenSSL is required for client-assertion replay coverage.' );

$client_id = 'https://www.example.com/wp-ai-bridge/replay-window-client.json';
$jwks_uri  = 'https://www.example.com/wp-ai-bridge/replay-window-jwks.json';
$audience  = rest_url( 'wp-ai-bridge/v1/oauth/token' );
$jti       = 'issue54-max-window-replay';
$kid       = 'issue54-max-window-key';

$private_key = openssl_pkey_new(
	array(
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	)
);
wpai_issue54_replay_assert( false !== $private_key, 'Could not generate the replay-window RSA fixture.' );
$key_details = openssl_pkey_get_details( $private_key );
wpai_issue54_replay_assert( is_array( $key_details ) && ! empty( $key_details['rsa']['n'] ) && ! empty( $key_details['rsa']['e'] ), 'Replay-window RSA fixture details are unavailable.' );

$jwk = array(
	'kty' => 'RSA',
	'kid' => $kid,
	'use' => 'sig',
	'alg' => 'RS256',
	'n'   => wpai_issue54_replay_b64( $key_details['rsa']['n'] ),
	'e'   => wpai_issue54_replay_b64( $key_details['rsa']['e'] ),
);

$http_mock = static function ( $preempt, $args, $url ) use ( $jwks_uri, $jwk ) {
	if ( $jwks_uri !== $url ) {
		return $preempt;
	}
	wpai_issue54_replay_assert( 0 === (int) ( $args['redirection'] ?? -1 ), 'Replay-window JWKS retrieval allowed redirects.' );
	wpai_issue54_replay_assert( (int) ( $args['timeout'] ?? 0 ) <= 8, 'Replay-window JWKS retrieval exceeded the timeout bound.' );
	wpai_issue54_replay_assert( 65537 === (int) ( $args['limit_response_size'] ?? 0 ), 'Replay-window JWKS retrieval lost the response-size bound.' );
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( array( 'keys' => array( $jwk ) ) ),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $http_mock, 10, 3 );
Client_Assertion_Validator::clear_client_cache( $client_id );

$store     = new OAuth_Store();
$validator = new Client_Assertion_Validator( $store );
$issued_at = time();
$exp       = $issued_at + Client_Assertion_Validator::MAX_ASSERTION_TTL;

$header = array(
	'alg' => 'RS256',
	'kid' => $kid,
	'typ' => 'JWT',
);
$claims = array(
	'iss' => $client_id,
	'sub' => $client_id,
	'aud' => $audience,
	'exp' => $exp,
	'jti' => $jti,
);
$input = wpai_issue54_replay_b64( wp_json_encode( $header ) ) . '.' . wpai_issue54_replay_b64( wp_json_encode( $claims ) );
$signature = '';
wpai_issue54_replay_assert( openssl_sign( $input, $signature, $private_key, OPENSSL_ALGO_SHA256 ), 'Could not sign the replay-window client assertion.' );
$assertion = $input . '.' . wpai_issue54_replay_b64( $signature );

$request = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$request->set_param( 'client_assertion_type', Client_Assertion_Validator::ASSERTION_TYPE );
$request->set_param( 'client_assertion', $assertion );
$profile = array(
	'client_id' => $client_id,
	'jwks_uri'  => $jwks_uri,
);

$result = $validator->validate( $request, $profile, array( $audience ) );
wpai_issue54_replay_assert( true === $result, 'Maximum-lifetime additional-client assertion was rejected.' );

$replay_key    = 'wpai_oauth_assertion_' . substr( hash( 'sha256', $client_id . "\0" . $jti ), 0, 40 );
$replay_expiry = (int) get_option( $replay_key, 0 );
wpai_issue54_replay_assert( $replay_expiry > 0, 'Replay-window marker was not persisted.' );
wpai_issue54_replay_assert(
	$replay_expiry >= $exp + Client_Assertion_Validator::CLOCK_SKEW,
	'Replay-window marker expires before the signed assertion stops being acceptable.'
);
wpai_issue54_replay_assert(
	$replay_expiry <= time() + OAuth_Store::CLIENT_ASSERTION_REPLAY_TTL_CAP + OAuth_Store::CLIENT_ASSERTION_REPLAY_SKEW,
	'Replay-window marker exceeded its bounded maximum retention.'
);

$replay = $validator->validate( $request, $profile, array( $audience ) );
wpai_issue54_replay_assert( is_wp_error( $replay ) && 'invalid_client' === $replay->get_error_code(), 'The same signed client assertion was accepted twice while its acceptance window remained open.' );

// Deterministically model a pre-fix marker whose stored 600-second expiry has
// elapsed while this maximum-lifetime assertion is still inside its skew window.
$legacy_expiry = time() - 1;
update_option( $replay_key, $legacy_expiry, false );
$late_replay = $validator->validate( $request, $profile, array( $audience ) );
wpai_issue54_replay_assert( is_wp_error( $late_replay ) && 'invalid_client' === $late_replay->get_error_code(), 'An expired pre-fix replay marker was reclaimed while the same signed assertion was still acceptable.' );
wpai_issue54_replay_assert( $legacy_expiry === (int) get_option( $replay_key, 0 ), 'Authentication deleted the pre-fix replay marker before scheduled cleanup.' );
$store->cleanup_client_assertion( $replay_key, $legacy_expiry );
wpai_issue54_replay_assert( false === get_option( $replay_key, false ), 'Authoritative replay cleanup did not remove the simulated pre-fix marker.' );

Client_Assertion_Validator::clear_client_cache( $client_id );
remove_filter( 'pre_http_request', $http_mock, 10 );

echo "Issue #54 client-assertion replay window: PASS\n";
