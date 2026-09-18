<?php
/**
 * One process in the Issue #80 refresh concurrency regression.
 *
 * The worker deliberately emits only hashes of bearer artifacts.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;

$refresh_token = getenv( 'WPAI_ISSUE80_REFRESH_TOKEN' );
if ( ! is_string( $refresh_token ) || '' === $refresh_token ) {
	throw new RuntimeException( 'Issue #80 concurrency refresh token is unavailable.' );
}

$store = new OAuth_Store();
$store->set_authenticated_client( OAuth_Server::CHATGPT_CLIENT_ID );
$oauth = new OAuth_Server( $store );

$request = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
$request->set_param( 'grant_type', 'refresh_token' );
$request->set_param( 'refresh_token', $refresh_token );
$request->set_param( 'client_id', OAuth_Server::CHATGPT_CLIENT_ID );
$request->set_param( 'resource', $oauth->mcp_endpoint_url() );

$exchange = new ReflectionMethod( OAuth_Server::class, 'exchange_refresh_token' );
$exchange->setAccessible( true );
$response = $exchange->invoke(
	$oauth,
	$request,
	array( 'client_id' => OAuth_Server::CHATGPT_CLIENT_ID )
);
$data     = json_decode( wp_json_encode( $response->get_data() ), true );
$data     = is_array( $data ) ? $data : array();

$result = array(
	'status'       => (int) $response->get_status(),
	'error'        => isset( $data['error'] ) ? (string) $data['error'] : '',
	'access_hash'  => ! empty( $data['access_token'] ) ? hash( 'sha256', (string) $data['access_token'] ) : '',
	'refresh_hash' => ! empty( $data['refresh_token'] ) ? hash( 'sha256', (string) $data['refresh_token'] ) : '',
);

echo wp_json_encode( $result ) . "\n";
