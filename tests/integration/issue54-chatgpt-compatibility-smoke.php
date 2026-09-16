<?php
/**
 * Issue #54 direct-ChatGPT compatibility regression.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\Approved_OAuth_Clients;
use WP_AI_Bridge\Auth\Client_Assertion_Validator;
use WP_AI_Bridge\Auth\OAuth_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

/**
 * Fails the integration test when a compatibility invariant is false.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 * @return void
 */
function wpai_issue54_chatgpt_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . "\n" );
		exit( 1 );
	}
}

$registry       = new Approved_OAuth_Clients();
$original_cache = get_transient( Approved_OAuth_Clients::CHATGPT_METADATA_CACHE );
$http_calls     = 0;

// Force the authorization-time compatibility check to inspect metadata rather than a cache hit.
delete_transient( Approved_OAuth_Clients::CHATGPT_METADATA_CACHE );

$http_mock = static function ( $preempt, $args, $url ) use ( &$http_calls ) {
	if ( OAuth_Server::CHATGPT_CLIENT_ID !== $url ) {
		return $preempt;
	}
	++$http_calls;
	wpai_issue54_chatgpt_assert( 0 === (int) ( $args['redirection'] ?? -1 ), 'ChatGPT metadata fetch unexpectedly allows redirects.' );
	wpai_issue54_chatgpt_assert( (int) ( $args['limit_response_size'] ?? 0 ) <= Approved_OAuth_Clients::MAX_METADATA + 1, 'ChatGPT metadata fetch lost its response-size bound.' );

	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode(
			array(
				'client_id'                  => OAuth_Server::CHATGPT_CLIENT_ID,
				'client_name'                => 'ChatGPT',
				'redirect_uris'              => array(
					OAuth_Server::CHATGPT_REDIRECT_URI,
					'https://chatgpt.com/another-supported-callback',
				),
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'private_key_jwt',
				'jwks_uri'                   => Client_Assertion_Validator::CHATGPT_JWKS_URI,
			)
		),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $http_mock, 10, 3 );

$authorization_profile = $registry->resolve( OAuth_Server::CHATGPT_CLIENT_ID );
wpai_issue54_chatgpt_assert( is_array( $authorization_profile ), 'Built-in ChatGPT metadata with an additional callback was rejected.' );
wpai_issue54_chatgpt_assert( 1 === $http_calls, 'Authorization-time ChatGPT metadata was not verified exactly once.' );
wpai_issue54_chatgpt_assert(
	array( OAuth_Server::CHATGPT_REDIRECT_URI ) === ( $authorization_profile['redirect_uris'] ?? array() ),
	'Additional ChatGPT metadata callbacks became trusted Bridge redirect URIs.'
);
wpai_issue54_chatgpt_assert(
	$registry->redirect_allowed( $authorization_profile, OAuth_Server::CHATGPT_REDIRECT_URI ),
	'The historical ChatGPT callback was not retained.'
);
wpai_issue54_chatgpt_assert(
	! $registry->redirect_allowed( $authorization_profile, 'https://chatgpt.com/another-supported-callback' ),
	'An unrelated ChatGPT metadata callback was widened into the Bridge callback contract.'
);

// A post-upgrade refresh/revocation path must keep the historical fixed profile and
// must not acquire a new live client-metadata dependency merely because Issue #54 is installed.
delete_transient( Approved_OAuth_Clients::CHATGPT_METADATA_CACHE );
$before_client_auth = $http_calls;
$client_auth_profile = $registry->resolve_for_client_auth( OAuth_Server::CHATGPT_CLIENT_ID );
wpai_issue54_chatgpt_assert( is_array( $client_auth_profile ), 'Built-in ChatGPT client-auth profile became unavailable without metadata cache.' );
wpai_issue54_chatgpt_assert( $before_client_auth === $http_calls, 'ChatGPT token/revocation client authentication unexpectedly fetched client metadata.' );
wpai_issue54_chatgpt_assert(
	OAuth_Server::CHATGPT_REDIRECT_URI === ( $client_auth_profile['redirect_uris'][0] ?? '' ),
	'Built-in ChatGPT client-auth profile changed its historical callback identity.'
);
wpai_issue54_chatgpt_assert(
	Client_Assertion_Validator::CHATGPT_JWKS_URI === ( $client_auth_profile['jwks_uri'] ?? '' ),
	'Built-in ChatGPT client-auth profile changed its historical JWKS identity.'
);

remove_filter( 'pre_http_request', $http_mock, 10 );
if ( false === $original_cache ) {
	delete_transient( Approved_OAuth_Clients::CHATGPT_METADATA_CACHE );
} else {
	set_transient( Approved_OAuth_Clients::CHATGPT_METADATA_CACHE, $original_cache, 15 * MINUTE_IN_SECONDS );
}

echo "PASS: Issue #54 direct ChatGPT compatibility.\n";
