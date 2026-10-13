<?php
/**
 * Disposable OAuth MCP test client for the #134 crash/recovery/locking lanes.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;

function wpai134_review_require( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<int,mixed> [token, session, store] */
function wpai134_review_connect( $user_id ) {
	$store = new OAuth_Store();
	$oauth = new OAuth_Server( $store );
	$token = $store->issue(
		OAuth_Store::TYPE_ACCESS,
		array(
			'user_id'   => $user_id,
			'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
			'resource'  => $oauth->mcp_endpoint_url(),
			'scope'     => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
		),
		300
	);
	wpai134_review_require( is_string( $token ) && '' !== $token, 'Unable to issue disposable review OAuth token.' );
	$before = class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' )
		? \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $user_id ) : array();
	wp_set_current_user( 0 );
	$init    = wpai134_review_call( $token, '', 'initialize', array() );
	$session = (string) ( $init['headers']['Mcp-Session-Id'] ?? '' );
	if ( '' === $session && class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ) {
		$after   = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $user_id );
		$session = (string) array_key_first( array_diff_key( $after, $before ) );
	}
	wpai134_review_require( '' !== $session, 'Native MCP session ID missing.' );
	$notice = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$notice->set_header( 'Authorization', 'Bearer ' . $token );
	$notice->set_header( 'Mcp-Session-Id', $session );
	$notice->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	$notice->set_header( 'Content-Type', 'application/json' );
	$notice->set_body( '{"jsonrpc":"2.0","method":"notifications/initialized"}' );
	wpai134_review_require( 202 === rest_do_request( $notice )->get_status(), 'Native MCP handshake failed.' );
	return array( $token, $session, $store );
}

/** @return array<string,mixed> */
function wpai134_review_call( $token, $session, $ability, $parameters ) {
	$request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	if ( '' !== $session ) {
		$request->set_header( 'Mcp-Session-Id', $session );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	}
	$call = 'initialize' === $ability
		? array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => (object) array(),
				'clientInfo'      => array(
					'name'    => 'wpai134-review',
					'version' => '1',
				),
			),
		)
		: array(
			'jsonrpc' => '2.0',
			'id'      => 31,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'mcp-adapter-execute-ability',
				'arguments' => array(
					'ability_name' => $ability,
					'parameters'   => $parameters,
				),
			),
		);
	$request->set_body( wp_json_encode( $call ) );
	$result = rest_do_request( $request );
	wpai134_review_require( 200 === $result->get_status(), 'Unexpected native MCP HTTP status.' );
	$decoded            = json_decode( wp_json_encode( $result->get_data() ), true );
	$decoded['headers'] = $result->get_headers();
	return $decoded;
}

function wpai134_review_transfer( $token, $session, $params ) {
	return wpai134_review_call( $token, $session, 'wp-ai-bridge/private-transfer', $params );
}

/** @return string Opaque validated lease ID. */
function wpai134_review_stage( $token, $session, $purpose, $bytes ) {
	$begin = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'begin',
			'purpose'     => $purpose,
			'total_bytes' => strlen( $bytes ),
			'sha256'      => hash( 'sha256', $bytes ),
		)
	);
	$data  = $begin['result']['structuredContent']['data'] ?? array();
	wpai134_review_require( true === ( $begin['result']['structuredContent']['success'] ?? false ), 'Unable to begin fixture transfer: ' . wp_json_encode( $begin['result'] ?? array() ) );
	$id     = $data['transfer_id'];
	$offset = 0;
	while ( $offset < strlen( $bytes ) ) {
		$piece  = substr( $bytes, $offset, min( 8192, $data['max_chunk_bytes'] ) );
		$result = wpai134_review_transfer(
			$token,
			$session,
			array(
				'action'         => 'append',
				'transfer_id'    => $id,
				'offset'         => $offset,
				'content_base64' => base64_encode( $piece ),
			)
		);
		wpai134_review_require( true === ( $result['result']['structuredContent']['success'] ?? false ), 'Fixture transfer append failed.' );
		$offset += strlen( $piece );
	}
	$verified = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'verify',
			'transfer_id' => $id,
		)
	);
	wpai134_review_require( true === ( $verified['result']['structuredContent']['success'] ?? false ), 'Fixture transfer verify failed.' );
	return $id;
}
