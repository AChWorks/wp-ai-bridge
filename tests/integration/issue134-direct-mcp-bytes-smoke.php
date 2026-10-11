<?php
/**
 * Source-bound UTF-8 egress and small Media ingress over Bridge's real OAuth MCP
 * REST route on a disposable WordPress installation with the official Adapter.
 *
 * This checks already supported operations, not a general private binary file
 * handoff, external ChatGPT attachment ingress, or private file egress.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;
use WP_AI_Bridge\Support\Settings;

function wpai134_mcp_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai134_mcp_request( $token, $session, $id, $ability, $parameters ) {
	$request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	$request->set_header( 'Content-Type', 'application/json' );
	if ( '' !== $session ) {
		$request->set_header( 'Mcp-Session-Id', $session );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	}
	if ( '' === $ability ) {
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => '2025-11-25',
						'capabilities'    => (object) array(),
						'clientInfo'      => array( 'name' => 'wpai134-direct-byte-fixture', 'version' => '1.0' ),
					),
				)
			)
		);
	} else {
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'mcp-adapter-execute-ability',
						'arguments' => array(
							'ability_name' => $ability,
							'parameters'   => $parameters,
						),
					),
				)
			)
		);
	}
	$response = rest_do_request( $request );
	$decoded  = json_decode( wp_json_encode( $response->get_data() ), true );
	wpai134_mcp_assert( 200 === $response->get_status() && is_array( $decoded ), 'Native MCP REST dispatch failed for ' . $ability );
	return array( $decoded, $response->get_headers() );
}

$actor      = get_current_user_id();
$settings   = new Settings();
$original   = get_option( Settings::OPTION_NAME, $settings->defaults() );
$grant      = $settings->defaults();
$store      = new OAuth_Store();
$oauth      = new OAuth_Server( $store );
$token      = '';
$session    = '';
$attachment = 0;
$post_id    = 0;

try {
	wpai134_mcp_assert( $actor > 0, 'Native fixture requires authenticated WordPress administrator.' );
	$grant[ Settings::GROUP_SITE_READ ]     = 1;
	$grant[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grant, false );

	$token = $store->issue(
		OAuth_Store::TYPE_ACCESS,
		array(
			'user_id'   => $actor,
			'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
			'resource'  => $oauth->mcp_endpoint_url(),
			'scope'     => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
		),
		120
	);
	wpai134_mcp_assert( is_string( $token ) && '' !== $token, 'Could not issue disposable OAuth bearer.' );
	$before_sessions = class_exists( '\\WP\\MCP\\Transport\\Infrastructure\\SessionManager' )
		? \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor ) : array();
	wp_set_current_user( 0 );
	list( $init, $headers ) = wpai134_mcp_request( $token, '', 1, '', array() );
	wpai134_mcp_assert( '2025-11-25' === ( $init['result']['protocolVersion'] ?? null ), 'Real OAuth MCP protocol was not initialized.' );
	$session = (string) ( $headers['Mcp-Session-Id'] ?? '' );
	if ( '' === $session && class_exists( '\\WP\\MCP\\Transport\\Infrastructure\\SessionManager' ) ) {
		$after_sessions = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor );
		$session        = (string) array_key_first( array_diff_key( $after_sessions, $before_sessions ) );
	}
	wpai134_mcp_assert( '' !== $session, 'Native OAuth MCP transport did not return a session.' );
	$notice = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$notice->set_header( 'Authorization', 'Bearer ' . $token );
	$notice->set_header( 'Mcp-Session-Id', $session );
	$notice->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	$notice->set_header( 'Content-Type', 'application/json' );
	$notice->set_body( '{"jsonrpc":"2.0","method":"notifications/initialized"}' );
	wpai134_mcp_assert( 202 === rest_do_request( $notice )->get_status(), 'MCP initialization notification failed.' );

	// Existing inline Media path: byte-exact ingress into the native Media
	// Library, not a general streaming/private file transfer implementation.
	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=', true );
	list( $upload ) = wpai134_mcp_request(
		$token,
		$session,
		2,
		'wp-ai-bridge/media-upload',
		array(
			'filename'       => 'wpai134-direct-bytes.png',
			'content_base64' => base64_encode( $png ),
			'title'          => 'Disposable MCP ingress fixture',
		)
	);
	$uploaded = $upload['result']['structuredContent'] ?? array();
	wpai134_mcp_assert(
		true === ( $uploaded['success'] ?? false ) && isset( $uploaded['data']['id'] ),
		'Native OAuth MCP Media ingress failed.'
	);
	$attachment = (int) $uploaded['data']['id'];
	wp_set_current_user( $actor );
	$file = get_attached_file( $attachment );
	wpai134_mcp_assert(
		is_string( $file ) && is_file( $file ) &&
		strlen( $png ) === filesize( $file ) &&
		hash( 'sha256', $png ) === hash_file( 'sha256', $file ),
		'Native Media Library wrote different bytes than authorized MCP client supplied.'
	);
	wp_set_current_user( 0 );

	list( $read_media ) = wpai134_mcp_request(
		$token,
		$session,
		3,
		'wp-ai-bridge/media-read',
		array( 'action' => 'get', 'id' => $attachment )
	);
	$media = $read_media['result']['structuredContent'] ?? array();
	wpai134_mcp_assert(
		true === ( $media['success'] ?? false ) &&
		$attachment === (int) ( $media['data']['items'][0]['id'] ?? 0 ),
		'Existing direct MCP Media metadata read failed.'
	);

	// Existing data egress for large multilingual post content. The client
	// reconstructs immutable byte-offset windows and validates SHA-256.
	$source = str_repeat( "سلام WordPress / exact UTF-8 🌍\n", 6500 );
	wp_set_current_user( $actor );
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => 'Disposable direct MCP UTF-8 fixture',
			'post_content' => wp_slash( $source ),
		),
		true
	);
	wpai134_mcp_assert( ! is_wp_error( $post_id ) && (int) $post_id > 0, 'Could not create disposable private draft.' );
	wp_set_current_user( 0 );
	$offset                 = 0;
	$assembled              = '';
	$hash                   = hash( 'sha256', $source );
	$max_encoded_result     = 0;
	$read_window_count      = 0;
	$finished  = false;
	for ( $part = 0; $part < 60; ++$part ) {
		$params = array(
			'action'            => 'get',
			'id'                => (int) $post_id,
			'content_offset'    => $offset,
			'content_max_bytes' => 110 * 1024,
		);
		if ( $offset > 0 ) {
			$params['expected_content_hash'] = $hash;
		}
		list( $message ) = wpai134_mcp_request( $token, $session, 10 + $part, 'wp-ai-bridge/content-read', $params );
		$encoded_message = wp_json_encode( $message );
		wpai134_mcp_assert( is_string( $encoded_message ), 'MCP result could not be encoded as JSON for effective-byte accounting.' );
		$max_encoded_result = max( $max_encoded_result, strlen( $encoded_message ) );
		++$read_window_count;
		$result = $message['result']['structuredContent'] ?? array();
		wpai134_mcp_assert( true === ( $result['success'] ?? false ), 'Authorized direct MCP content window failed.' );
		$item = $result['data']['items'][0] ?? array();
		wpai134_mcp_assert(
			$hash === ( $item['content_hash'] ?? '' ) &&
			$offset < ( $item['content_next_offset'] ?? 0 ) &&
			( $item['content_total_bytes'] ?? null ) === strlen( $source ),
			'Hash-bound direct content window lost immutable identity/forward progress.'
		);
		$assembled .= $item['content'];
		$offset     = $item['content_next_offset'];
		if ( ! empty( $item['content_complete'] ) ) {
			$finished = true;
			break;
		}
	}
	wpai134_mcp_assert(
		$finished && $source === $assembled && $hash === hash( 'sha256', $assembled ),
		'Direct MCP Unicode window reconstruction was not byte-exact.'
	);

	$grant[ Settings::GROUP_BUILDER_WRITE ] = 0;
	wp_set_current_user( $actor );
	update_option( Settings::OPTION_NAME, $grant, false );
	wp_set_current_user( 0 );
	list( $denied ) = wpai134_mcp_request(
		$token,
		$session,
		90,
		'wp-ai-bridge/media-upload',
		array(
			'filename'       => 'wpai134-revoked.png',
			'content_base64' => base64_encode( $png ),
		)
	);
	wpai134_mcp_assert( ! empty( $denied['result']['isError'] ), 'Revoked Builder Write grant still allowed MCP file ingress.' );
	// Results describe the native local MCP REST response only, not unknown
	// Gateway or external ChatGPT host attachment/transport limits.
	echo sprintf(
		"MEASURE: #134 media_bytes=%d media_base64_bytes=%d utf8_source_bytes=%d text_windows=%d largest_encoded_mcp_result_bytes=%d php_peak_bytes=%d.\n",
		strlen( $png ),
		strlen( base64_encode( $png ) ),
		strlen( $source ),
		$read_window_count,
		$max_encoded_result,
		memory_get_peak_usage( true )
	);
	echo "PASS: #134 Bridge OAuth MCP small binary ingress, large UTF-8 egress, hash equality and revocation.\n";
} finally {
	wp_set_current_user( $actor );
	if ( $attachment > 0 ) {
		wp_delete_attachment( $attachment, true );
	}
	if ( is_int( $post_id ) && $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	if ( '' !== $token ) {
		$store->revoke( $token );
	}
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
}
