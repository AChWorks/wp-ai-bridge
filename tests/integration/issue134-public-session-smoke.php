<?php
/**
 * Draft private transfer lifecycle over Bridge's real OAuth MCP
 * REST route on a disposable WordPress installation with the official Adapter.
 *
 * This checks staging, not consuming, importing or materializing client attachments.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_AI_Bridge\Support\Payload_Settings;
use WP_AI_Bridge\Support\Bounded_Payload;

function wpai134_session_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai134_session_request( $token, $session, $id, $ability, $parameters ) {
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
	wpai134_session_assert( 200 === $response->get_status() && is_array( $decoded ), 'Native MCP REST dispatch failed for ' . $ability );
	return array( $decoded, $response->get_headers() );
}


/**
 * Simulate an actual MCP byte sender, not an internal PHP stage shortcut.
 *
 * @param string $token Authenticated fixture bearer.
 * @param string $session Native Adapter session.
 * @param int $seq JSON-RPC ID.
 * @param string $purpose Native transfer purpose.
 * @param string $body Exact untrusted client payload.
 * @param array<int,string> $identities Cleanup identities.
 * @return string Transfer ID.
 */
function wpai134_client_stage( $token, $session, &$seq, $purpose, $body, &$identities ) {
	$begin   = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'begin',
			'purpose'     => $purpose,
			'total_bytes' => strlen( $body ),
			'sha256'      => hash( 'sha256', $body ),
		)
	);
	$payload = $begin['result']['structuredContent'] ?? array();
	wpai134_session_assert( true === ( $payload['success'] ?? false ), 'Native MCP client could not begin exact ' . $purpose . ' source.' );
	$id           = $payload['data']['transfer_id'];
	$identities[] = $id;
	$offset       = 0;
	$window       = min( 32768, $payload['data']['max_chunk_bytes'] );
	while ( $offset < strlen( $body ) ) {
		$part = substr( $body, $offset, $window );
		$sent = wpai134_session_action(
			$token,
			$session,
			$seq,
			array(
				'action'         => 'append',
				'transfer_id'    => $id,
				'offset'         => $offset,
				'content_base64' => base64_encode( $part ),
			)
		);
		wpai134_session_assert(
			true === ( $sent['result']['structuredContent']['success'] ?? false ) &&
			$offset + strlen( $part ) === ( $sent['result']['structuredContent']['data']['received_bytes'] ?? 0 ),
			'Native MCP byte sender failed at source offset ' . $offset
		);
		$offset += strlen( $part );
	}
	$verified = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'verify',
			'transfer_id' => $id,
		)
	);
	wpai134_session_assert( true === ( $verified['result']['structuredContent']['success'] ?? false ), 'Native MCP sender did not verify final staged hash.' );
	return $id;
}

$actor           = get_current_user_id();
$settings        = new Settings();
$original        = get_option( Settings::OPTION_NAME, false );
$original_size   = get_option( Transfer_Settings::OPTION_NAME, false );
$original_wire   = get_option( Payload_Settings::OPTION_NAME, false );
$grants          = $settings->defaults();
$policy          = new Transfer_Settings();
$store           = new OAuth_Store();
$oauth           = new OAuth_Server( $store );
$session         = '';
$token           = '';
$identities      = array();
$media_id        = 0;
$opaque_media_id = 0;
$content_ids     = array();
$extra_media_ids = array();
$seq             = 20;

function wpai134_session_action( $token, $session, &$seq, $action ) {
	list( $out ) = wpai134_session_request( $token, $session, $seq++, 'wp-ai-bridge/private-transfer', $action );
	return $out;
}

try {
	wpai134_session_assert( $actor > 0 && current_user_can( 'manage_options' ), 'Disposable integration requires a current WordPress administrator.' );
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	$grants[ Settings::GROUP_SITE_READ ]     = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$limits = $policy->defaults();
	// Set a small per-file policy to prove administrator-controlled errors,
	// then raise it WITHOUT changing runtime code or host settings.
	$limits[ Transfer_Settings::FILE_MIB ]   = 1;
	$limits[ Transfer_Settings::ACTIVE_MIB ] = 4;
	update_option( Transfer_Settings::OPTION_NAME, $limits, false );

	$token = $store->issue(
		OAuth_Store::TYPE_ACCESS,
		array(
			'user_id'   => $actor,
			'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
			'resource'  => $oauth->mcp_endpoint_url(),
			'scope'     => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
		),
		300
	);
	wpai134_session_assert( is_string( $token ) && '' !== $token, 'Could not issue disposable OAuth bearer.' );
	$before_sessions = class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' )
		? \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor ) : array();
	wp_set_current_user( 0 );
	list( $init, $headers ) = wpai134_session_request( $token, '', 1, '', array() );
	wpai134_session_assert( '2025-11-25' === ( $init['result']['protocolVersion'] ?? '' ), 'Native authenticated MCP session failed initialization.' );
	$session = (string) ( $headers['Mcp-Session-Id'] ?? '' );
	if ( '' === $session && class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ) {
		$after_sessions = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor );
		$session        = (string) array_key_first( array_diff_key( $after_sessions, $before_sessions ) );
	}
	wpai134_session_assert( '' !== $session, 'Native OAuth MCP session identity is missing.' );
	$notice = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$notice->set_header( 'Authorization', 'Bearer ' . $token );
	$notice->set_header( 'Mcp-Session-Id', $session );
	$notice->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	$notice->set_header( 'Content-Type', 'application/json' );
	$notice->set_body( '{"jsonrpc":"2.0","method":"notifications/initialized"}' );
	wpai134_session_assert( 202 === rest_do_request( $notice )->get_status(), 'MCP initialization notification failed.' );

	$policy_result = wpai134_session_action( $token, $session, $seq, array( 'action' => 'limits' ) );
	$limit_data    = $policy_result['result']['structuredContent'] ?? array();
	wpai134_session_assert(
		true === ( $limit_data['success'] ?? false ) &&
		1048576 === ( $limit_data['data']['max_file_bytes'] ?? 0 ) &&
		isset( $limit_data['data']['max_chunk_bytes'] ),
		'Private transfer limits did not use the WordPress administrator policy.'
	);

	$source      = str_repeat( "فارسی✨Καλημέρα\n", 100000 );
	$source     .= random_bytes( 257 );
	$source_size = strlen( $source );
	wpai134_session_assert( $source_size > 1048576, 'Representative source fixture is too small.' );
	$begin_input = array(
		'action'      => 'begin',
		'purpose'     => 'media',
		'total_bytes' => $source_size,
		'sha256'      => hash( 'sha256', $source ),
	);
	$too_large   = wpai134_session_action( $token, $session, $seq, $begin_input );
	wpai134_session_assert( ! empty( $too_large['result']['isError'] ), 'Configurable single-transfer budget was ignored.' );

	wp_set_current_user( $actor );
	$limits[ Transfer_Settings::FILE_MIB ]   = 16;
	$limits[ Transfer_Settings::ACTIVE_MIB ] = 32;
	update_option( Transfer_Settings::OPTION_NAME, $limits, false );
	wp_set_current_user( 0 );

	$begun = wpai134_session_action( $token, $session, $seq, $begin_input );
	$start = $begun['result']['structuredContent'] ?? array();
	wpai134_session_assert( true === ( $start['success'] ?? false ) && 'open' === ( $start['data']['state'] ?? '' ), 'Authenticated private transfer begin failed.' );
	$id           = (string) $start['data']['transfer_id'];
	$identities[] = $id;
	wpai134_session_assert( 64 === strlen( $id ) && 16 * 1048576 === $start['data']['max_file_bytes'], 'Negotiated per-file capacity was not retained.' );
	$cursor    = 0;
	$steps     = 0;
	$part_size = min( 32768, $start['data']['max_chunk_bytes'] );
	while ( $cursor < $source_size ) {
		$part     = substr( $source, $cursor, $part_size );
		$params   = array(
			'action'         => 'append',
			'transfer_id'    => $id,
			'offset'         => $cursor,
			'content_base64' => base64_encode( $part ),
		);
		$appended = wpai134_session_action( $token, $session, $seq, $params );
		$value    = $appended['result']['structuredContent'] ?? array();
		wpai134_session_assert( true === ( $value['success'] ?? false ) && $cursor + strlen( $part ) === ( $value['data']['received_bytes'] ?? -1 ), 'Partial private MCP bytes did not advance exact offset.' );
		if ( 0 === $cursor ) {
			$replayed = wpai134_session_action( $token, $session, $seq, $params );
			wpai134_session_assert( true === ( $replayed['result']['structuredContent']['success'] ?? false ), 'Identical duplicate chunk did not replay safely.' );
			$conflict_params                   = $params;
			$conflict_params['content_base64'] = base64_encode( str_repeat( 'Z', strlen( $part ) ) );
			$conflicted                        = wpai134_session_action( $token, $session, $seq, $conflict_params );
			wpai134_session_assert( ! empty( $conflicted['result']['isError'] ), 'Conflicting replay silently altered staged bytes.' );
		}
		$cursor += strlen( $part );
		++$steps;
	}
	$verified = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'verify',
			'transfer_id' => $id,
		)
	);
	$data     = $verified['result']['structuredContent'] ?? array();
	wpai134_session_assert(
		true === ( $data['success'] ?? false ) && 'verified' === $data['data']['state'] &&
		$source_size === $data['data']['received_bytes'] &&
		hash( 'sha256', $source ) === $data['data']['sha256'],
		'Whole source verify did not prove exact hash and length.'
	);
	// This proof is internal-only: private filesystem paths are never sent
	// back by the authenticated MCP Ability.
	wp_set_current_user( $actor );
	$internal = ( new \WP_AI_Bridge\Support\Private_Transfer_Session( new \WP_AI_Bridge\Support\Permissions( $settings ) ) )->verified_private_source( $id, 'media' );
	wpai134_session_assert(
		! is_wp_error( $internal ) && is_file( $internal['path'] ) &&
		hash_file( 'sha256', $internal['path'] ) === hash( 'sha256', $source ),
		'Native WordPress private storage differs from externally staged MCP bytes.'
	);
	// R3: kill a checkpoint update after an incomplete JSON publication.
	// A prior checksum-protected generation must remain authoritative,
	// and unrelated admission must not be globally poisoned.
	$private_root = dirname( $internal['path'] );
	wp_set_current_user( 0 );
	$journal_id = wpai134_client_stage( $token, $session, $seq, 'text', 'journal recovery exact source bytes', $identities );
	$slot0      = $private_root . '/' . $journal_id . '.meta.state.0';
	$slot1      = $private_root . '/' . $journal_id . '.meta.state.1';
	$wire0      = json_decode( (string) file_get_contents( $slot0 ), true );
	$wire1      = json_decode( (string) file_get_contents( $slot1 ), true );
	wpai134_session_assert(
		is_array( $wire0 ) && is_array( $wire1 ) &&
		$wire0['generation'] !== $wire1['generation'],
		'R3: two distinct committed private manifest generations were not created.'
	);
	$newest = $wire0['generation'] > $wire1['generation'] ? $slot0 : $slot1;
	wpai134_session_assert(
		false !== file_put_contents( $newest, '{"generation":' ),
		'R3: could not emulate a torn manifest checkpoint on disposable WordPress.'
	);
	$recovered = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $journal_id,
		)
	);
	wpai134_session_assert(
		true === ( $recovered['result']['structuredContent']['success'] ?? false ) &&
		'open' === ( $recovered['result']['structuredContent']['data']['state'] ?? '' ),
		'R3: torn newest checkpoint did not recover its last complete generation.'
	);
	$verified_again = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'verify',
			'transfer_id' => $journal_id,
		)
	);
	wpai134_session_assert(
		true === ( $verified_again['result']['structuredContent']['success'] ?? false ) &&
		'verified' === ( $verified_again['result']['structuredContent']['data']['state'] ?? '' ),
		'R3: last committed checkpoint could not reverify the exact persisted bytes.'
	);
	$new_admission = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'begin',
			'purpose'     => 'text',
			'total_bytes' => 0,
			'sha256'      => hash( 'sha256', '' ),
		)
	);
	wpai134_session_assert(
		true === ( $new_admission['result']['structuredContent']['success'] ?? false ),
		'R3: recoverable torn checkpoint still blocked unrelated private admission.'
	);
	$identities[] = $new_admission['result']['structuredContent']['data']['transfer_id'];
	// Flush a chunk into the data stream without publishing the matching
	// received-offset checkpoint, as if PHP died between these two effects.
	$tail_full  = 'prefix-restart-source';
	$tail_begin = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'begin',
			'purpose'     => 'text',
			'total_bytes' => strlen( $tail_full ),
			'sha256'      => hash( 'sha256', $tail_full ),
		)
	);
	$tail_id    = $tail_begin['result']['structuredContent']['data']['transfer_id'] ?? '';
	wpai134_session_assert( strlen( $tail_id ) === 64, 'R3: resumable tail fixture failed to begin.' );
	$identities[] = $tail_id;
	$prefix       = 'prefix-';
	$first        = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'         => 'append',
			'transfer_id'    => $tail_id,
			'offset'         => 0,
			'content_base64' => base64_encode( $prefix ),
		)
	);
	wpai134_session_assert(
		true === ( $first['result']['structuredContent']['success'] ?? false ),
		'R3: prefix fixture append failed.'
	);
	$tail_path = $private_root . '/' . $tail_id . '.bytes';
	wpai134_session_assert(
		false !== file_put_contents( $tail_path, 'unacknowledged', FILE_APPEND ),
		'R3: failed to emulate a flushed uncheckpointed tail.'
	);
	$recovered_tail = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $tail_id,
		)
	);
	wpai134_session_assert(
		true === ( $recovered_tail['result']['structuredContent']['success'] ?? false ) &&
		strlen( $prefix ) === ( $recovered_tail['result']['structuredContent']['data']['received_bytes'] ?? 0 ) &&
		filesize( $tail_path ) === strlen( $prefix ),
		'R3: unacknowledged tail bytes did not roll back to last durable receipt.'
	);
	$continued     = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'         => 'append',
			'transfer_id'    => $tail_id,
			'offset'         => strlen( $prefix ),
			'content_base64' => base64_encode( substr( $tail_full, strlen( $prefix ) ) ),
		)
	);
	$tail_verified = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'verify',
			'transfer_id' => $tail_id,
		)
	);
	wpai134_session_assert(
		true === ( $continued['result']['structuredContent']['success'] ?? false ) &&
		true === ( $tail_verified['result']['structuredContent']['success'] ?? false ),
		'R3: resumed exact byte source could not complete after worker-death tail recovery.'
	);
	echo "PASS: #134 review R3 stable-manifest generation recovery, unacknowledged byte-tail repair and unrelated admission.\n";
	wp_set_current_user( 0 );

	$cancel = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'cancel',
			'transfer_id' => $id,
		)
	);
	wpai134_session_assert( true === ( $cancel['result']['structuredContent']['success'] ?? false ), 'Authenticated lease cancellation did not acknowledge exact deletion.' );
	$missing = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $id,
		)
	);
	wpai134_session_assert( ! empty( $missing['result']['isError'] ), 'Cancelled private lease remained readable.' );


	// Explicit second-stage native Media consumer: passive library import,
	// never plugin activation, archive extraction or arbitrary server path.
	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=', true );
	wpai134_session_assert( is_string( $png ) && 68 === strlen( $png ), 'Media consumer fixture must be a real PNG.' );
	$media_begin = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'begin',
			'purpose'     => 'media',
			'total_bytes' => strlen( $png ),
			'sha256'      => hash( 'sha256', $png ),
		)
	);
	wpai134_session_assert( true === ( $media_begin['result']['structuredContent']['success'] ?? false ), 'Native Media staging begin failed.' );
	$media_lease  = $media_begin['result']['structuredContent']['data']['transfer_id'];
	$identities[] = $media_lease;
	$media_bytes  = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'         => 'append',
			'transfer_id'    => $media_lease,
			'offset'         => 0,
			'content_base64' => base64_encode( $png ),
		)
	);
	wpai134_session_assert( true === ( $media_bytes['result']['structuredContent']['success'] ?? false ), 'Native Media staging bytes failed.' );
	$media_verified = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'verify',
			'transfer_id' => $media_lease,
		)
	);
	wpai134_session_assert( true === ( $media_verified['result']['structuredContent']['success'] ?? false ), 'Native Media source verification failed.' );

	list( $bad_media ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-media-import',
		array(
			'transfer_id' => $media_lease,
			'filename'    => 'wpai134-staged.php',
		)
	);
	wpai134_session_assert( ! empty( $bad_media['result']['isError'] ), 'A disallowed executable Media MIME was admitted.' );
	// A host that disables native WordPress Media uploads must not be
	// bypassed merely because source bytes arrived as smaller MCP chunks.
	$disable_uploads = static function () {
		return 0;
	};
	add_filter( 'upload_size_limit', $disable_uploads );
	list( $host_denied ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-media-import',
		array(
			'transfer_id' => $media_lease,
			'filename'    => 'wpai134-staged.png',
		)
	);
	remove_filter( 'upload_size_limit', $disable_uploads );
	wpai134_session_assert( ! empty( $host_denied['result']['isError'] ), 'Disabled native WordPress uploads were bypassed by a staged transfer.' );
	list( $good_media ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-media-import',
		array(
			'transfer_id' => $media_lease,
			'filename'    => 'wpai134-staged.png',
		)
	);
	$media_data         = $good_media['result']['structuredContent'] ?? array();
	wpai134_session_assert(
		true === ( $media_data['success'] ?? false ) &&
		'committed' === ( $media_data['data']['state'] ?? '' ) &&
		(int) ( $media_data['data']['media_id'] ?? 0 ) > 0,
		'Verified Media staging was not consumed through native WordPress Media Library.'
	);
	$media_id = (int) $media_data['data']['media_id'];
	wp_set_current_user( $actor );
	$media_file = get_attached_file( $media_id );
	wpai134_session_assert(
		'attachment' === get_post_type( $media_id ) &&
		is_string( $media_file ) && is_file( $media_file ) &&
		hash_file( 'sha256', $media_file ) === hash( 'sha256', $png ),
		'Native Media consumer did not preserve exact verified file bytes.'
	);
	wp_set_current_user( 0 );
	$media_status = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $media_lease,
		)
	);
	wpai134_session_assert(
		true === ( $media_status['result']['structuredContent']['success'] ?? false ) &&
		(int) $media_status['result']['structuredContent']['data']['destination_id'] === $media_id,
		'Committed Media effect had no durable status/attachment receipt.'
	);
	// R1: verify after a native effect must never reopen the same lease.
	$reverify_media = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'verify',
			'transfer_id' => $media_lease,
		)
	);
	wpai134_session_assert( ! empty( $reverify_media['result']['isError'] ), 'R1: verified committed Media reopened.' );
	$media_again = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $media_lease,
		)
	);
	wpai134_session_assert(
		'committed' === ( $media_again['result']['structuredContent']['data']['state'] ?? '' ) &&
		( $media_again['result']['structuredContent']['data']['destination_id'] ?? 0 ) === $media_id,
		'R1: committed Media receipt changed on reverify.'
	);
	// Outbound producer: a source-bound private snapshot, losslessly read by
	// the same external JSON-RPC-style MCP client without a public URL/path.
	list( $exported ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-media-export',
		array( 'media_id' => $media_id )
	);
	$exp              = $exported['result']['structuredContent'] ?? array();
	wpai134_session_assert(
		true === ( $exp['success'] ?? false ) &&
		strlen( $exp['data']['transfer_id'] ?? '' ) === 64 &&
		68 === ( $exp['data']['total_bytes'] ?? 0 ),
		'Native WordPress Media export snapshot could not be opened.'
	);
	$export_id    = $exp['data']['transfer_id'];
	$identities[] = $export_id;
	// A private snapshot is not an irrevocable reading grant. Deny all
	// windows and status immediately after Bridge Site Read is revoked.
	wp_set_current_user( $actor );
	$grants                              = get_option( Settings::OPTION_NAME, array() );
	$grants[ Settings::GROUP_SITE_READ ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );
	$revoked_read = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'read',
			'transfer_id' => $export_id,
			'offset'      => 0,
			'max_bytes'   => 23,
		)
	);
	wpai134_session_assert( ! empty( $revoked_read['result']['isError'] ), 'Revoked Bridge Site Read still accessed a frozen Media export.' );
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_SITE_READ ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );
	$cursor     = 0;
	$downloaded = '';
	while ( $cursor < strlen( $png ) ) {
		$read   = wpai134_session_action(
			$token,
			$session,
			$seq,
			array(
				'action'      => 'read',
				'transfer_id' => $export_id,
				'offset'      => $cursor,
				'max_bytes'   => 23,
			)
		);
		$window = $read['result']['structuredContent'] ?? array();
		wpai134_session_assert( true === ( $window['success'] ?? false ), 'Authenticated Media export read failed.' );
		$part = base64_decode( $window['data']['content_base64'] ?? '', true );
		wpai134_session_assert(
			is_string( $part ) && hash( 'sha256', $part ) === ( $window['data']['chunk_sha256'] ?? '' ) &&
			$cursor + strlen( $part ) === ( $window['data']['read_next_offset'] ?? 0 ),
			'Frozen Media transfer window had an incorrect chunk or offset.'
		);
		$downloaded .= $part;
		$cursor     += strlen( $part );
	}
	wpai134_session_assert(
		$downloaded === $png && hash( 'sha256', $downloaded ) === $exp['data']['sha256'],
		'Native outbound WordPress Media MCP transfer did not reconstruct exact private bytes.'
	);
	$end = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'read',
			'transfer_id' => $export_id,
			'offset'      => strlen( $png ),
			'max_bytes'   => 23,
		)
	);
	wpai134_session_assert(
		true === ( $end['result']['structuredContent']['success'] ?? false ) &&
		true === ( $end['result']['structuredContent']['data']['read_complete'] ?? false ) &&
		'' === ( $end['result']['structuredContent']['data']['content_base64'] ?? null ),
		'Exact end-of-file export continuation did not terminate without fabricated bytes.'
	);
	$read_denied = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'read',
			'transfer_id' => $media_lease,
			'offset'      => 0,
			'max_bytes'   => 1,
		)
	);
	wpai134_session_assert( ! empty( $read_denied['result']['isError'] ), 'Inbound Media staging was mistakenly readable as an outbound export.' );
	$export_cancel = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'cancel',
			'transfer_id' => $export_id,
		)
	);
	wpai134_session_assert( true === ( $export_cancel['result']['structuredContent']['success'] ?? false ), 'WordPress Media export snapshot release failed.' );

	// Real WordPress source, arbitrary binary bytes and >1 MiB end-to-end
	// outward transfer without ever making a single oversized MCP result.
	wp_set_current_user( $actor );
	wpai134_session_assert( class_exists( 'ZipArchive' ), 'Native WordPress large ZIP export fixture requires PHP ZIP support.' );
	$zip_temp = wp_tempnam( 'wpai134-binary-outbound.zip' );
	wpai134_session_assert( is_string( $zip_temp ), 'Disposable outgoing ZIP temp file unavailable.' );
	$zip = new ZipArchive();
	wpai134_session_assert( true === $zip->open( $zip_temp, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Outgoing ZIP fixture cannot open.' );
	wpai134_session_assert( $zip->addFromString( 'opaque-payload.bin', random_bytes( 1310851 ) ), 'Outgoing ZIP payload cannot be recorded.' );
	wpai134_session_assert( true === $zip->close(), 'Outgoing ZIP fixture cannot close.' );
	$opaque_bytes = file_get_contents( $zip_temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only disposable ZIP fixture.
	unlink( $zip_temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temporary ZIP fixture cleanup.
	wpai134_session_assert( is_string( $opaque_bytes ) && strlen( $opaque_bytes ) > 1048576, 'Native ZIP fixture must exceed a single transfer chunk.' );
	$opaque_upload = wp_upload_bits( 'wpai134-opaque.zip', null, $opaque_bytes );
	wpai134_session_assert(
		empty( $opaque_upload['error'] ) && ! empty( $opaque_upload['file'] ),
		'Disposable large ZIP source was not created: ' . ( $opaque_upload['error'] ?? '' )
	);
	$opaque_media_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'application/zip',
			'post_title'     => 'Private outgoing binary acceptance',
			'post_status'    => 'inherit',
		),
		$opaque_upload['file']
	);
	wpai134_session_assert( is_int( $opaque_media_id ) && $opaque_media_id > 0, 'Native Media attachment for outbound acceptance is missing.' );
	wp_set_current_user( 0 );
	list( $big_export ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-media-export',
		array( 'media_id' => $opaque_media_id )
	);
	$big_receipt        = $big_export['result']['structuredContent'] ?? array();
	wpai134_session_assert(
		true === ( $big_receipt['success'] ?? false ) &&
		strlen( $opaque_bytes ) === ( $big_receipt['data']['total_bytes'] ?? 0 ) &&
		hash( 'sha256', $opaque_bytes ) === ( $big_receipt['data']['sha256'] ?? '' ),
		'Private snapshot could not freeze the original large Media file.'
	);
	$big_id       = $big_receipt['data']['transfer_id'];
	$identities[] = $big_id;
	// R4: a request for the maximum raw window shrinks against the
	// Adapter's doubled, escaped serialized MCP output, not just Base64.
	$wire_small                                   = ( new Payload_Settings() )->defaults();
	$wire_small[ Payload_Settings::RESPONSE_KEY ] = 16;
	wp_set_current_user( $actor );
	update_option( Payload_Settings::OPTION_NAME, $wire_small, false );
	wp_set_current_user( 0 );
	$small_read   = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'read',
			'transfer_id' => $big_id,
			'offset'      => 0,
			'max_bytes'   => 49152,
		)
	);
	$small_result = $small_read['result']['structuredContent']['data'] ?? array();
	wpai134_session_assert(
		true === ( $small_read['result']['structuredContent']['success'] ?? false ) &&
		$small_result['read_next_offset'] > 0 && $small_result['read_next_offset'] < 49152 &&
		Bounded_Payload::fits( $small_result ) &&
		strlen( wp_json_encode( $small_read ) ) <= 16 * 1024,
		'R4: minimum 16 KiB MCP response overflowed instead of adaptively shrinking.'
	);
	$wire_default = ( new Payload_Settings() )->defaults();
	wp_set_current_user( $actor );
	update_option( Payload_Settings::OPTION_NAME, $wire_default, false );
	wp_set_current_user( 0 );
	$default_read   = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'read',
			'transfer_id' => $big_id,
			'offset'      => 0,
			'max_bytes'   => 49152,
		)
	);
	$default_result = $default_read['result']['structuredContent']['data'] ?? array();
	wpai134_session_assert(
		true === ( $default_read['result']['structuredContent']['success'] ?? false ) &&
		$default_result['read_next_offset'] > 0 && $default_result['read_next_offset'] < 49152 &&
		Bounded_Payload::fits( $default_result ) &&
		strlen( wp_json_encode( $default_read ) ) <= 128 * 1024,
		'R4: default 128 KiB MCP response overflowed with a double-encoded maximum raw chunk.'
	);
	$received_bytes = '';
	$big_offset     = 0;
	$big_windows    = 0;
	while ( $big_offset < strlen( $opaque_bytes ) ) {
		$window     = wpai134_session_action(
			$token,
			$session,
			$seq,
			array(
				'action'      => 'read',
				'transfer_id' => $big_id,
				'offset'      => $big_offset,
				'max_bytes'   => 32768,
			)
		);
		$part       = $window['result']['structuredContent'] ?? array();
		$part_bytes = base64_decode( $part['data']['content_base64'] ?? '', true );
		wpai134_session_assert(
			true === ( $part['success'] ?? false ) &&
			is_string( $part_bytes ) && '' !== $part_bytes &&
			$big_offset + strlen( $part_bytes ) === ( $part['data']['read_next_offset'] ?? 0 ) &&
			hash( 'sha256', $part_bytes ) === ( $part['data']['chunk_sha256'] ?? '' ),
			'Large Media export chunk failed exact offset or digest validation.'
		);
		$received_bytes .= $part_bytes;
		$big_offset     += strlen( $part_bytes );
		++$big_windows;
	}
	wpai134_session_assert(
		$big_windows >= 40 && $received_bytes === $opaque_bytes &&
		hash( 'sha256', $received_bytes ) === $big_receipt['data']['sha256'],
		'Native Media outgoing 1 MiB-plus binary reconstruction lost bytes.'
	);
	$big_cancel = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'cancel',
			'transfer_id' => $big_id,
		)
	);
	wpai134_session_assert( true === ( $big_cancel['result']['structuredContent']['success'] ?? false ), 'Large Media export lease was not released.' );
	echo 'MEASURE: #134 outbound Media bytes=' . strlen( $opaque_bytes ) . ' windows=' . $big_windows . " SHA256 exact.\n";
	// R6: the native Media scratch copy consumes the same configurable
	// aggregate reservation and no claimed effect occurs on a denial.
	$scratch_id = wpai134_client_stage(
		$token,
		$session,
		$seq,
		'media',
		$opaque_bytes,
		$identities
	);
	wp_set_current_user( $actor );
	$limits[ Transfer_Settings::ACTIVE_MIB ] = 2;
	update_option( Transfer_Settings::OPTION_NAME, $limits, false );
	wp_set_current_user( 0 );
	list( $scratch_denied ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-media-import',
		array(
			'transfer_id' => $scratch_id,
			'filename'    => 'wpai134-budgeted.zip',
		)
	);
	wpai134_session_assert(
		! empty( $scratch_denied['result']['isError'] ),
		'R6: Media scratch copy ignored the aggregate 2 MiB private capacity.'
	);
	$scratch_denied_status = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $scratch_id,
		)
	);
	wpai134_session_assert(
		'verified' === ( $scratch_denied_status['result']['structuredContent']['data']['state'] ?? '' ) &&
		! file_exists( $private_root . '/' . $scratch_id . '.scratch' ),
		'R6: denied scratch reservation nevertheless created bytes or a native effect.'
	);
	wp_set_current_user( $actor );
	$limits[ Transfer_Settings::ACTIVE_MIB ] = 32;
	update_option( Transfer_Settings::OPTION_NAME, $limits, false );
	wp_set_current_user( 0 );
	list( $scratch_imported ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-media-import',
		array(
			'transfer_id' => $scratch_id,
			'filename'    => 'wpai134-budgeted.zip',
		)
	);
	$imported_data            = $scratch_imported['result']['structuredContent']['data'] ?? array();
	wpai134_session_assert(
		true === ( $scratch_imported['result']['structuredContent']['success'] ?? false ) &&
		'committed' === ( $imported_data['state'] ?? '' ) &&
		( $imported_data['media_id'] ?? 0 ) > 0 &&
		! file_exists( $private_root . '/' . $scratch_id . '.scratch' ),
		'R6: correctly budgeted Media import failed or retained a private scratch duplicate.'
	);
	$extra_media_ids[] = (int) $imported_data['media_id'];

	// R6: simulate a worker that finished copy creation, then died
	// before claiming an effect. The next admission must reconcile
	// exactly that orphan and release its active reservation.
	$orphan_id                = wpai134_client_stage( $token, $session, $seq, 'media', $png, $identities );
	$state0                   = $private_root . '/' . $orphan_id . '.meta.state.0';
	$state1                   = $private_root . '/' . $orphan_id . '.meta.state.1';
	$check0                   = json_decode( (string) file_get_contents( $state0 ), true );
	$check1                   = json_decode( (string) file_get_contents( $state1 ), true );
	$latest                   = $check0['generation'] > $check1['generation'] ? $check0 : $check1;
	$meta                     = json_decode( $latest['body'], true );
	$meta['generation']       = $latest['generation'] + 1;
	$meta['state']            = 'preparing';
	$meta['scratch_reserved'] = strlen( $png );
	$body                     = wp_json_encode( $meta );
	$wire                     = wp_json_encode(
		array(
			'generation' => $meta['generation'],
			'sha256'     => hash( 'sha256', $body ),
			'body'       => $body,
		)
	);
	$next_slot                = $private_root . '/' . $orphan_id . '.meta.state.' . ( $meta['generation'] % 2 );
	$orphan_scratch           = $private_root . '/' . $orphan_id . '.scratch';
	wpai134_session_assert(
		false !== file_put_contents( $next_slot, $wire ) &&
		false !== file_put_contents( $orphan_scratch, $png ),
		'R6: could not simulate a checkpointed unclaimed scratch after disposable worker death.'
	);
	$reject_cleanup = static function ( $path ) use ( $orphan_scratch ) {
		return $path === $orphan_scratch ? false : $path;
	};
	add_filter( 'wp_delete_file', $reject_cleanup );
	$blocked_admission = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'begin',
			'purpose'     => 'media',
			'total_bytes' => 0,
			'sha256'      => hash( 'sha256', '' ),
		)
	);
	remove_filter( 'wp_delete_file', $reject_cleanup );
	wpai134_session_assert(
		! empty( $blocked_admission['result']['isError'] ) &&
		file_exists( $orphan_scratch ),
		'R6: failed scratch cleanup was incorrectly counted as free capacity.'
	);
	$swept    = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'begin',
			'purpose'     => 'media',
			'total_bytes' => 0,
			'sha256'      => hash( 'sha256', '' ),
		)
	);
	$swept_id = $swept['result']['structuredContent']['data']['transfer_id'] ?? '';
	wpai134_session_assert(
		true === ( $swept['result']['structuredContent']['success'] ?? false ) &&
		! file_exists( $orphan_scratch ),
		'R6: new admission did not safely recover a dead worker unclaimed scratch.'
	);
	$identities[] = $swept_id;
	$orphan_state = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $orphan_id,
		)
	);
	wpai134_session_assert(
		'verified' === ( $orphan_state['result']['structuredContent']['data']['state'] ?? '' ) &&
		! file_exists( $orphan_scratch ),
		'R6: abandoned pre-effect Media lease remained preparing or retained scratch.'
	);
	echo "PASS: #134 review R6 Media scratch budget denial, verified native import, orphan recovery and deletion-veto retry.\n";
	// R5: full-size 3200px native JPEG exceeds the default 2560px WordPress
	// big-image threshold. WordPress keeps original bytes separately while
	// the current attachment path is its scaled derivative.
	wpai134_session_assert(
		function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' ) &&
		function_exists( 'wp_get_original_image_path' ) &&
		wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ),
		'R5: disposable native image editor cannot exercise real WordPress large-image scaling.'
	);
	$canvas = imagecreatetruecolor( 3200, 90 );
	wpai134_session_assert( false !== $canvas, 'R5: could not create native 3200px JPEG fixture.' );
	$color = imagecolorallocate( $canvas, 21, 107, 153 );
	imagefill( $canvas, 0, 0, $color );
	ob_start();
	$image_written = imagejpeg( $canvas, null, 88 );
	$jpeg          = ob_get_clean();
	imagedestroy( $canvas );
	wpai134_session_assert(
		$image_written && is_string( $jpeg ) && strlen( $jpeg ) > 0,
		'R5: native 3200px JPEG source could not be encoded.'
	);
	$jpeg_id               = wpai134_client_stage( $token, $session, $seq, 'media', $jpeg, $identities );
	list( $scaled_result ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-media-import',
		array(
			'transfer_id' => $jpeg_id,
			'filename'    => 'wpai134-large-jpeg.jpg',
		)
	);
	$scaled_data           = $scaled_result['result']['structuredContent']['data'] ?? array();
	wpai134_session_assert(
		true === ( $scaled_result['result']['structuredContent']['success'] ?? false ) &&
		'committed' === ( $scaled_data['state'] ?? '' ) &&
		( $scaled_data['media_id'] ?? 0 ) > 0,
		'R5: native WordPress large-image processing was incorrectly declared uncertain.'
	);
	$scaled_id         = (int) $scaled_data['media_id'];
	$extra_media_ids[] = $scaled_id;
	wp_set_current_user( $actor );
	$current_image      = get_attached_file( $scaled_id );
	$original_image     = wp_get_original_image_path( $scaled_id );
	$current_dimensions = is_string( $current_image ) ? getimagesize( $current_image ) : false;
	wpai134_session_assert(
		is_string( $current_image ) && is_string( $original_image ) &&
		$current_image !== $original_image &&
		file_exists( $original_image ) &&
		hash_file( 'sha256', $original_image ) === hash( 'sha256', $jpeg ) &&
		is_array( $current_dimensions ) && $current_dimensions[0] <= 2560,
		'R5: WordPress large-image derivative was not separated from its original exact uploaded source.'
	);
	wp_set_current_user( 0 );
	$scaled_status = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $jpeg_id,
		)
	);
	wpai134_session_assert(
		( $scaled_status['result']['structuredContent']['data']['destination_id'] ?? 0 ) === $scaled_id &&
		'original_upload' === ( $scaled_status['result']['structuredContent']['data']['source_representation'] ?? '' ),
		'R5: original byte identity and native derivative representation were confused.'
	);
	echo "PASS: #134 review R5 native WordPress 3200px JPEG scaling, original SHA256 and current derived path.\n";




	list( $duplicate ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-media-import',
		array(
			'transfer_id' => $media_lease,
			'filename'    => 'wpai134-staged.png',
		)
	);
	wpai134_session_assert( ! empty( $duplicate['result']['isError'] ), 'Replay created a second Media object after a committed effect.' );
	$media_released = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'cancel',
			'transfer_id' => $media_lease,
		)
	);
	wpai134_session_assert( true === ( $media_released['result']['structuredContent']['success'] ?? false ), 'Completed transfer could not release its private staged copy.' );
	wp_set_current_user( $actor );
	wpai134_session_assert( get_post( $media_id ) && is_file( get_attached_file( $media_id ) ), 'Private staged-data release deleted the native Media attachment.' );
	wp_set_current_user( 0 );

	// Multi-megabyte UTF-8 text travels through the same authenticated
	// byte plane, but mutates WordPress only through explicit content-upsert
	// authorization with an exact native content state precondition.
	$long_text = str_repeat( "درود 🌍 Guten\\berg <?php echo 'literal'; ?> \\n", 40000 );
	wpai134_session_assert( strlen( $long_text ) > 1048576, 'Native text fixture must exceed a single JSON/MCP message.' );
	$text_id         = wpai134_client_stage( $token, $session, $seq, 'text', $long_text, $identities );
	list( $created ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-content-commit',
		array(
			'action'      => 'create',
			'transfer_id' => $text_id,
			'post_type'   => 'post',
			'title'       => 'انتقال متن چندبخشی',
		)
	);
	$created_data    = $created['result']['structuredContent'] ?? array();
	wpai134_session_assert(
		true === ( $created_data['success'] ?? false ) && 'committed' === ( $created_data['data']['state'] ?? '' ) &&
		(int) ( $created_data['data']['post_id'] ?? 0 ) > 0,
		'Authenticated native WordPress text consumer did not commit a new draft.'
	);
	$post_id       = (int) $created_data['data']['post_id'];
	$content_ids[] = $post_id;
	wp_set_current_user( $actor );
	$post = get_post( $post_id );
	wpai134_session_assert(
		$post && 'draft' === $post->post_status &&
		$long_text === $post->post_content && hash( 'sha256', $post->post_content ) === hash( 'sha256', $long_text ),
		'Native WordPress content creation did not preserve exact multi-MiB UTF-8 and slash bytes.'
	);
	wp_set_current_user( 0 );
	list( $duplicate_text ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-content-commit',
		array(
			'action'      => 'create',
			'transfer_id' => $text_id,
			'post_type'   => 'post',
			'title'       => 'تکرار غیرمجاز',
		)
	);
	wpai134_session_assert( ! empty( $duplicate_text['result']['isError'] ), 'Claimed WordPress create was replayed as a duplicate post.' );
	$reverify_text = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'verify',
			'transfer_id' => $text_id,
		)
	);
	wpai134_session_assert( ! empty( $reverify_text['result']['isError'] ), 'R1: consumed text source reverified.' );
	list( $replayed_text ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-content-commit',
		array(
			'action'      => 'create',
			'transfer_id' => $text_id,
			'post_type'   => 'post',
			'title'       => 'no second native post',
		)
	);
	wpai134_session_assert( ! empty( $replayed_text['result']['isError'] ), 'R1: reverified text produced a duplicate WordPress post.' );

	$revised_text  = str_repeat( "متن جدید 🌿 HTML <em>ارزش</em> و \\\\ اسلش\n", 40000 );
	$revision_id   = wpai134_client_stage( $token, $session, $seq, 'text', $revised_text, $identities );
	list( $stale ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-content-commit',
		array(
			'action'                => 'update',
			'transfer_id'           => $revision_id,
			'post_id'               => $post_id,
			'expected_modified_gmt' => '2000-01-01 00:00:00',
			'expected_state_hash'   => str_repeat( '0', 64 ),
		)
	);
	wpai134_session_assert( ! empty( $stale['result']['isError'] ), 'Stale full-text CAS did not refuse an overwrite.' );
	$unclaimed = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $revision_id,
		)
	);
	wpai134_session_assert( 'verified' === ( $unclaimed['result']['structuredContent']['data']['state'] ?? '' ), 'Stale preflight consumed a still-safe text source.' );
	list( $current ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/content-read',
		array(
			'action'          => 'get',
			'id'              => $post_id,
			'include_content' => false,
		)
	);
	$current_data    = $current['result']['structuredContent']['data']['items'][0] ?? array();
	wpai134_session_assert(
		! empty( $current_data['state_hash'] ) && ! empty( $current_data['modified_gmt'] ),
		'Native current post state lacked the CAS identity required for update.'
	);
	list( $updated ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-content-commit',
		array(
			'action'                => 'update',
			'transfer_id'           => $revision_id,
			'post_id'               => $post_id,
			'expected_modified_gmt' => $current_data['modified_gmt'],
			'expected_state_hash'   => $current_data['state_hash'],
		)
	);
	$updated_data    = $updated['result']['structuredContent'] ?? array();
	wpai134_session_assert(
		true === ( $updated_data['success'] ?? false ) &&
		(int) ( $updated_data['data']['post_id'] ?? 0 ) === $post_id &&
		'committed' === ( $updated_data['data']['state'] ?? '' ),
		'Native WordPress content update did not commit the exact prior content state.'
	);
	wp_set_current_user( $actor );
	wpai134_session_assert(
		get_post( $post_id )->post_content === $revised_text,
		'Post update truncated or rewrote Markdown, UTF-8, literal backslashes or HTML.'
	);
	wp_set_current_user( 0 );
	$updated_status = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $revision_id,
		)
	);
	wpai134_session_assert(
		( $updated_status['result']['structuredContent']['data']['destination_id'] ?? 0 ) === $post_id,
		'Successful native content commit lacked a durable destination receipt.'
	);
	$reverify_updated = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'verify',
			'transfer_id' => $revision_id,
		)
	);
	wpai134_session_assert( ! empty( $reverify_updated['result']['isError'] ), 'R1: committed content update reopened.' );
	// R7: WordPress filters may legitimately change the resulting body,
	// but the observed native destination ID must never disappear.
	$modify_post = static function ( $data ) {
		if ( isset( $data['post_title'] ) && 'wpai134-transformed-source' === $data['post_title'] ) {
			$data['post_content'] .= ' [WordPress native filter changed body]';
		}
		return $data;
	};
	add_filter( 'wp_insert_post_data', $modify_post );
	$changed_id             = wpai134_client_stage( $token, $session, $seq, 'text', 'Original exact verified input.', $identities );
	list( $changed_result ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-content-commit',
		array(
			'action'      => 'create',
			'transfer_id' => $changed_id,
			'post_type'   => 'post',
			'title'       => 'wpai134-transformed-source',
		)
	);
	remove_filter( 'wp_insert_post_data', $modify_post );
	wpai134_session_assert( ! empty( $changed_result['result']['isError'] ), 'R7: native transformed result was wrongly marked byte-exact.' );
	$observed      = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $changed_id,
		)
	);
	$observed_data = $observed['result']['structuredContent']['data'] ?? array();
	$observed_id   = (int) ( $observed_data['destination_id'] ?? 0 );
	wpai134_session_assert(
		$observed_id > 0 && 'observed' === ( $observed_data['state'] ?? '' ) &&
		'observed_changed' === ( $observed_data['outcome'] ?? '' ),
		'R7: changed WordPress create lost observed identity/outcome receipt.'
	);
	$content_ids[]     = $observed_id;
	$released_observed = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'release',
			'transfer_id' => $changed_id,
		)
	);
	wpai134_session_assert(
		true === ( $released_observed['result']['structuredContent']['success'] ?? false ) &&
		true === ( $released_observed['result']['structuredContent']['data']['payload_released'] ?? false ),
		'R7: releasing known transformed payload failed.'
	);
	$still_known = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $changed_id,
		)
	);
	wpai134_session_assert(
		( $still_known['result']['structuredContent']['data']['destination_id'] ?? 0 ) === $observed_id &&
		'observed' === ( $still_known['result']['structuredContent']['data']['state'] ?? '' ),
		'R7: releasing payload destroyed durable observed destination ID.'
	);
	echo "PASS: #134 review R1/R4/R7 verify replay denial, MCP dual-wire adaptive windows, observed ID and safe reservation release.\n";
	echo "PASS: #134 real WordPress native MCP bidirectional Media snapshot and >1 MiB lossless UTF-8 content create/update with CAS, no replay.\n";

	// Reject malformed UTF-8 before the irreversible native write claim.
	$invalid_text_id      = wpai134_client_stage( $token, $session, $seq, 'text', "valid UTF-8 سپس \xFF invalid", $identities );
	list( $bad_encoding ) = wpai134_session_request(
		$token,
		$session,
		$seq++,
		'wp-ai-bridge/transfer-content-commit',
		array(
			'action'      => 'create',
			'transfer_id' => $invalid_text_id,
			'post_type'   => 'post',
			'title'       => 'reject-utf8',
		)
	);
	wpai134_session_assert( ! empty( $bad_encoding['result']['isError'] ), 'Invalid UTF-8 was written to a native WordPress post.' );
	$still_verified = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $invalid_text_id,
		)
	);
	wpai134_session_assert( 'verified' === ( $still_verified['result']['structuredContent']['data']['state'] ?? '' ), 'Rejected encoding claimed a native content effect.' );
	// Zero-byte source must verify without an invented synthetic chunk.
	$zero = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'begin',
			'purpose'     => 'media',
			'total_bytes' => 0,
			'sha256'      => hash( 'sha256', '' ),
		)
	);
	wpai134_session_assert( true === ( $zero['result']['structuredContent']['success'] ?? false ), 'Valid empty binary transfer was refused.' );
	$zero_id       = $zero['result']['structuredContent']['data']['transfer_id'];
	$identities[]  = $zero_id;
	$zero_verified = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'verify',
			'transfer_id' => $zero_id,
		)
	);
	wpai134_session_assert( true === ( $zero_verified['result']['structuredContent']['success'] ?? false ), 'Empty source was not verified.' );

	// Real-time grant revocation must invalidate all subsequent operations.
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );
	$revoked = wpai134_session_action(
		$token,
		$session,
		$seq,
		array(
			'action'      => 'status',
			'transfer_id' => $zero_id,
		)
	);
	wpai134_session_assert( ! empty( $revoked['result']['isError'] ), 'Revoked grant left a private lease operational.' );

	echo sprintf(
		"MEASURE: #134 native authenticated MCP private transfer bytes=%d chunks=%d php_peak_bytes=%d.\n",
		$source_size,
		$steps,
		memory_get_peak_usage( true )
	);
	echo "PASS: #134 native OAuth MCP chunked transfer, verified Media Library import, exact hash, no-replay, zero/cancel and grant revocation.\n";
} finally {
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$transfer = new \WP_AI_Bridge\Support\Private_Transfer_Session( new \WP_AI_Bridge\Support\Permissions( $settings ) );
	foreach ( $identities as $identity ) {
		$transfer->cancel( $identity );
	}
	if ( $media_id > 0 ) {
		wp_delete_attachment( $media_id, true );
	}
	if ( $opaque_media_id > 0 ) {
		wp_delete_attachment( $opaque_media_id, true );
	}
	foreach ( $extra_media_ids as $extra_media_id ) {
		wp_delete_attachment( $extra_media_id, true );
	}
	foreach ( $content_ids as $content_id ) {
		wp_delete_post( $content_id, true );
	}
	if ( '' !== $token ) {
		$store->revoke( $token );
	}
	if ( false === $original_size ) {
		delete_option( Transfer_Settings::OPTION_NAME );
	} else {
		update_option( Transfer_Settings::OPTION_NAME, $original_size, false );
	}
	if ( false === $original_wire ) {
		delete_option( Payload_Settings::OPTION_NAME );
	} else {
		update_option( Payload_Settings::OPTION_NAME, $original_wire, false );
	}
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
}
