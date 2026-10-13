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
use WP_AI_Bridge\Support\Payload_Settings;

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
						'clientInfo'      => array(
							'name'    => 'wpai134-direct-byte-fixture',
							'version' => '1.0',
						),
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

$actor           = get_current_user_id();
$settings        = new Settings();
$original        = get_option( Settings::OPTION_NAME, $settings->defaults() );
$original_inline = get_option( Payload_Settings::OPTION_NAME, false );
$grant           = $settings->defaults();
$store           = new OAuth_Store();
$oauth           = new OAuth_Server( $store );
$token           = '';
$session         = '';
$attachment      = 0;
$zip_attachment  = 0;
$zip_path        = '';
$zip_size        = 0;
$zip_wire_bytes  = 0;
$post_id         = 0;

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
	$png            = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=', true );
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
	$uploaded       = $upload['result']['structuredContent'] ?? array();
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
		array(
			'action' => 'get',
			'id'     => $attachment,
		)
	);
	$media              = $read_media['result']['structuredContent'] ?? array();
	wpai134_mcp_assert(
		true === ( $media['success'] ?? false ) &&
		(int) ( $media['data']['items'][0]['id'] ?? 0 ) === $attachment,
		'Existing direct MCP Media metadata read failed.'
	);

	// Representative finite-size opaque ZIP ingress through the EXISTING
	// native Media Library Ability; there is no installation/extraction,
	// general private handoff, public binary endpoint or provider-specific
	// ZIP pipeline. This uses a disposable random synthetic archive only.
	wpai134_mcp_assert( class_exists( 'ZipArchive' ), 'Native ZIP extension unavailable for representative upload proof.' );
	$zip_path = wp_tempnam( 'wpai134-media-upload.zip' );
	wpai134_mcp_assert( is_string( $zip_path ) && '' !== $zip_path, 'Could not allocate disposable ZIP input.' );
	$zip = new ZipArchive();
	wpai134_mcp_assert(
		true === $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ),
		'Could not initialize disposable ZIP fixture.'
	);
	$zip_payload = random_bytes( 1024 * 1024 );
	wpai134_mcp_assert( $zip->addFromString( 'payload.bin', $zip_payload ), 'ZIP fixture payload creation failed.' );
	unset( $zip_payload );
	wpai134_mcp_assert( true === $zip->close(), 'ZIP fixture close failed.' );
	$zip_input = file_get_contents( $zip_path );
	wpai134_mcp_assert(
		is_string( $zip_input ) && strlen( $zip_input ) > 1024 * 1024 &&
		strlen( $zip_input ) < wp_max_upload_size(),
		'Generated ZIP is outside native WordPress permitted Media upload size.'
	);
	$zip_size       = strlen( $zip_input );
	$zip_args       = array(
		'filename'       => 'wpai134-direct-opaque.zip',
		'content_base64' => base64_encode( $zip_input ),
	);
	$zip_wire       = wp_json_encode(
		array(
			'ability_name' => 'wp-ai-bridge/media-upload',
			'parameters'   => $zip_args,
		)
	);
	$zip_wire_bytes = is_string( $zip_wire ) ? strlen( $zip_wire ) : 0;
	// Distinct operator-tunable inline upload ceiling: this valid
	// 1,048,856-byte ZIP must fail when the bridge budget is 1 MiB.
	$payload_limits                                       = ( new Payload_Settings() )->defaults();
	$payload_limits[ Payload_Settings::INLINE_MEDIA_KEY ] = 1;
	wp_set_current_user( $actor );
	update_option( Payload_Settings::OPTION_NAME, $payload_limits, false );
	wp_set_current_user( 0 );
	list( $blocked_zip ) = wpai134_mcp_request( $token, $session, 81, 'wp-ai-bridge/media-upload', $zip_args );
	wpai134_mcp_assert( ! empty( $blocked_zip['result']['isError'] ), 'Configured 1 MiB inline Media budget was ignored for a larger ZIP.' );
	wp_set_current_user( $actor );
	update_option( Payload_Settings::OPTION_NAME, ( new Payload_Settings() )->defaults(), false );
	wp_set_current_user( 0 );
	list( $zip_result ) = wpai134_mcp_request( $token, $session, 4, 'wp-ai-bridge/media-upload', $zip_args );
	unset( $zip_args, $zip_wire );
	$zip_returned = $zip_result['result']['structuredContent'] ?? array();
	wpai134_mcp_assert(
		true === ( $zip_returned['success'] ?? false ) && (int) ( $zip_returned['data']['id'] ?? 0 ) > 0,
		'Valid finite ZIP file was not accepted by native Media Library MCP upload.'
	);
	$zip_attachment = (int) $zip_returned['data']['id'];
	wp_set_current_user( $actor );
	$zip_saved = get_attached_file( $zip_attachment );
	wpai134_mcp_assert(
		is_string( $zip_saved ) && is_file( $zip_saved ) &&
		filesize( $zip_saved ) === $zip_size &&
		hash( 'sha256', $zip_input ) === hash_file( 'sha256', $zip_saved ),
		'WordPress Media Library changed the opaque ZIP payload bytes.'
	);
	// New draft common binary window reader, Media source adapter only. Direct
	// authenticated MCP results must fit the actual dual-encoded Adapter reply.
	$max_binary_wire = 0;
	$binary_windows  = 0;
	foreach ( array(
		$attachment     => $png,
		$zip_attachment => $zip_input,
	) as $source_id => $source_bytes ) {
		wp_set_current_user( 0 );
		list( $inspection ) = wpai134_mcp_request(
			$token,
			$session,
			105,
			'wp-ai-bridge/file-bytes-read',
			array(
				'action'      => 'inspect',
				'source_type' => 'media',
				'source_id'   => (int) $source_id,
			)
		);
		$info               = $inspection['result']['structuredContent'] ?? array();
		wpai134_mcp_assert( true === ( $info['success'] ?? false ) && strlen( $source_bytes ) === $info['data']['total_bytes'], 'Native MCP binary source inspection failed.' );
		$source_hash = hash( 'sha256', $source_bytes );
		wpai134_mcp_assert( $source_hash === $info['data']['content_sha256'], 'Binary source digest was incorrect.' );
		$source_snapshot = $info['data']['snapshot'];
		$cursor          = 0;
		$assembled_bytes = '';
		$finished_bytes  = false;
		for ( $piece = 0; $piece < 130; ++$piece ) {
			list( $answer ) = wpai134_mcp_request(
				$token,
				$session,
				120 + $piece,
				'wp-ai-bridge/file-bytes-read',
				array(
					'action'            => 'chunk',
					'source_type'       => 'media',
					'source_id'         => (int) $source_id,
					'offset'            => $cursor,
					'max_bytes'         => 128 * 1024,
					'expected_snapshot' => $source_snapshot,
					'expected_sha256'   => $source_hash,
				)
			);
			$wire           = wp_json_encode( $answer );
			wpai134_mcp_assert( is_string( $wire ) && strlen( $wire ) <= \WP_AI_Bridge\Support\Bounded_Payload::response_limit(), 'Full real MCP Base64 result exceeded the effective wire budget.' );
			$max_binary_wire = max( $max_binary_wire, strlen( $wire ) );
			$block           = $answer['result']['structuredContent'] ?? array();
			wpai134_mcp_assert( true === ( $block['success'] ?? false ) && ( $block['data']['snapshot'] ?? '' ) === $source_snapshot, 'Authenticated binary read failed or leaked source identity.' );
			$part = base64_decode( $block['data']['content_base64'], true );
			wpai134_mcp_assert( is_string( $part ) && strlen( $part ) > 0 && ( $block['data']['offset'] ?? -1 ) === $cursor, 'Binary part decode and forward progress failed.' );
			$assembled_bytes .= $part;
			$cursor           = $block['data']['next_offset'];
			++$binary_windows;
			if ( $block['data']['complete'] ) {
				$finished_bytes = true;
				break;
			}
		}
		wpai134_mcp_assert(
			$finished_bytes && $source_bytes === $assembled_bytes &&
			hash( 'sha256', $assembled_bytes ) === $source_hash &&
			strlen( $source_bytes ) === $cursor,
			'Native MCP Media+opaque ZIP binary roundtrip was not byte-for-byte exact.'
		);
		list( $wrong ) = wpai134_mcp_request(
			$token,
			$session,
			219,
			'wp-ai-bridge/file-bytes-read',
			array(
				'action'            => 'chunk',
				'source_type'       => 'media',
				'source_id'         => (int) $source_id,
				'offset'            => 0,
				'max_bytes'         => 200,
				'expected_snapshot' => $source_snapshot,
				'expected_sha256'   => str_repeat( '0', 64 ),
			)
		);
		wpai134_mcp_assert( ! empty( $wrong['result']['isError'] ), 'Wrong file hash did not invalidate the signed snapshot.' );
	}
	// WordPress media metadata must not turn a file Ability into a reader
	// for arbitrary files outside the native uploads root.
	wp_set_current_user( $actor );
	$original_file_ref = get_post_meta( $attachment, '_wp_attached_file', true );
	update_post_meta( $attachment, '_wp_attached_file', '../../wp-config.php' );
	wp_set_current_user( 0 );
	list( $escaped ) = wpai134_mcp_request(
		$token,
		$session,
		225,
		'wp-ai-bridge/file-bytes-read',
		array(
			'action'      => 'inspect',
			'source_type' => 'media',
			'source_id'   => $attachment,
		)
	);
	wp_set_current_user( $actor );
	update_post_meta( $attachment, '_wp_attached_file', $original_file_ref );
	wp_set_current_user( 0 );
	wpai134_mcp_assert( ! empty( $escaped['result']['isError'] ), 'The reader accepted a file outside WordPress uploads.' );
	unset( $zip_input );
	wp_set_current_user( 0 );

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
	$offset             = 0;
	$assembled          = '';
	$hash               = hash( 'sha256', $source );
	$max_encoded_result = 0;
	$read_window_count  = 0;
	$finished           = false;
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
		wpai134_mcp_assert(
			strlen( $encoded_message ) <= \WP_AI_Bridge\Support\Bounded_Payload::response_limit(),
			'Real Adapter JSON result exceeded the administrator MCP response budget.'
		);
		++$read_window_count;
		$result = $message['result']['structuredContent'] ?? array();
		wpai134_mcp_assert( true === ( $result['success'] ?? false ), 'Authorized direct MCP content window failed.' );
		$item = $result['data']['items'][0] ?? array();
		wpai134_mcp_assert(
			( $item['content_hash'] ?? '' ) === $hash &&
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
		$finished && $assembled === $source && hash( 'sha256', $assembled ) === $hash,
		'Direct MCP Unicode window reconstruction was not byte-exact.'
	);

	// Existing site-read grant must remain authoritative after the client has
	// already received an inspection snapshot and byte windows.
	$grant[ Settings::GROUP_SITE_READ ] = 0;
	wp_set_current_user( $actor );
	update_option( Settings::OPTION_NAME, $grant, false );
	wp_set_current_user( 0 );
	list( $revoked_read ) = wpai134_mcp_request(
		$token,
		$session,
		87,
		'wp-ai-bridge/file-bytes-read',
		array(
			'action'      => 'inspect',
			'source_type' => 'media',
			'source_id'   => (int) $attachment,
		)
	);
	wpai134_mcp_assert( ! empty( $revoked_read['result']['isError'] ), 'Revoked Site Read group still delivered protected Media bytes.' );
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
	echo sprintf( "MEASURE: #134 binary_outbound_windows=%d largest_binary_mcp_json_bytes=%d.\\n", $binary_windows, $max_binary_wire );
	// Results describe the native local MCP REST response only, not unknown
	// Gateway or external ChatGPT host attachment/transport limits.
	echo sprintf(
		"MEASURE: #134 media_bytes=%d media_base64_bytes=%d opaque_zip_bytes=%d zip_mcp_arguments_bytes=%d utf8_source_bytes=%d text_windows=%d largest_encoded_mcp_result_bytes=%d php_peak_bytes=%d.\n",
		strlen( $png ),
		strlen( base64_encode( $png ) ),
		$zip_size,
		$zip_wire_bytes,
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
	if ( $zip_attachment > 0 ) {
		wp_delete_attachment( $zip_attachment, true );
	}
	if ( '' !== $zip_path ) {
		wp_delete_file( $zip_path );
	}
	if ( is_int( $post_id ) && $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	if ( '' !== $token ) {
		$store->revoke( $token );
	}
	if ( false === $original_inline ) {
		delete_option( Payload_Settings::OPTION_NAME );
	} else {
		update_option( Payload_Settings::OPTION_NAME, $original_inline, false );
	}
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
}
