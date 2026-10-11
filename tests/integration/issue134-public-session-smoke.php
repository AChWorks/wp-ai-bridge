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


$actor         = get_current_user_id();
$settings      = new Settings();
$original      = get_option( Settings::OPTION_NAME, false );
$original_size = get_option( Transfer_Settings::OPTION_NAME, false );
$grants        = $settings->defaults();
$policy        = new Transfer_Settings();
$store         = new OAuth_Store();
$oauth         = new OAuth_Server( $store );
$session       = '';
$token         = '';
$identities    = array();
$seq           = 20;

function wpai134_session_action( $token, $session, &$seq, $action ) {
	list( $out ) = wpai134_session_request( $token, $session, $seq++, 'wp-ai-bridge/private-transfer', $action );
	return $out;
}

try {
	wpai134_session_assert( $actor > 0 && current_user_can( 'manage_options' ), 'Disposable integration requires a current WordPress administrator.' );
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$limits = $policy->defaults();
	// Set a small per-file policy to prove administrator-controlled errors,
	// then raise it WITHOUT changing runtime code or host settings.
	$limits[ Transfer_Settings::FILE_MIB ] = 1;
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

	$source = str_repeat( "فارسی✨Καλημέρα\n", 100000 );
	$source .= random_bytes( 257 );
	$source_size = strlen( $source );
	wpai134_session_assert( $source_size > 1048576, 'Representative source fixture is too small.' );
	$begin_input = array(
		'action'      => 'begin',
		'purpose'     => 'media',
		'total_bytes' => $source_size,
		'sha256'      => hash( 'sha256', $source ),
	);
	$too_large = wpai134_session_action( $token, $session, $seq, $begin_input );
	wpai134_session_assert( ! empty( $too_large['result']['isError'] ), 'Configurable single-transfer budget was ignored.' );

	wp_set_current_user( $actor );
	$limits[ Transfer_Settings::FILE_MIB ]   = 16;
	$limits[ Transfer_Settings::ACTIVE_MIB ] = 32;
	update_option( Transfer_Settings::OPTION_NAME, $limits, false );
	wp_set_current_user( 0 );

	$begun = wpai134_session_action( $token, $session, $seq, $begin_input );
	$start = $begun['result']['structuredContent'] ?? array();
	wpai134_session_assert( true === ( $start['success'] ?? false ) && 'open' === ( $start['data']['state'] ?? '' ), 'Authenticated private transfer begin failed.' );
	$id   = (string) $start['data']['transfer_id'];
	$identities[] = $id;
	wpai134_session_assert( 64 === strlen( $id ) && 16 * 1048576 === $start['data']['max_file_bytes'], 'Negotiated per-file capacity was not retained.' );
	$cursor = 0;
	$steps  = 0;
	$part_size = min( 32768, $start['data']['max_chunk_bytes'] );
	while ( $cursor < $source_size ) {
		$part = substr( $source, $cursor, $part_size );
		$params = array(
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
			$conflict_params = $params;
			$conflict_params['content_base64'] = base64_encode( str_repeat( 'Z', strlen( $part ) ) );
			$conflicted = wpai134_session_action( $token, $session, $seq, $conflict_params );
			wpai134_session_assert( ! empty( $conflicted['result']['isError'] ), 'Conflicting replay silently altered staged bytes.' );
		}
		$cursor += strlen( $part );
		++$steps;
	}
	$verified = wpai134_session_action( $token, $session, $seq, array( 'action' => 'verify', 'transfer_id' => $id ) );
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
	wp_set_current_user( 0 );

	$cancel = wpai134_session_action( $token, $session, $seq, array( 'action' => 'cancel', 'transfer_id' => $id ) );
	wpai134_session_assert( true === ( $cancel['result']['structuredContent']['success'] ?? false ), 'Authenticated lease cancellation did not acknowledge exact deletion.' );
	$missing = wpai134_session_action( $token, $session, $seq, array( 'action' => 'status', 'transfer_id' => $id ) );
	wpai134_session_assert( ! empty( $missing['result']['isError'] ), 'Cancelled private lease remained readable.' );

	// Zero-byte source must verify without an invented synthetic chunk.
	$zero = wpai134_session_action(
		$token, $session, $seq,
		array( 'action' => 'begin', 'purpose' => 'media', 'total_bytes' => 0, 'sha256' => hash( 'sha256', '' ) )
	);
	wpai134_session_assert( true === ( $zero['result']['structuredContent']['success'] ?? false ), 'Valid empty binary transfer was refused.' );
	$zero_id = $zero['result']['structuredContent']['data']['transfer_id'];
	$identities[] = $zero_id;
	$zero_verified = wpai134_session_action( $token, $session, $seq, array( 'action' => 'verify', 'transfer_id' => $zero_id ) );
	wpai134_session_assert( true === ( $zero_verified['result']['structuredContent']['success'] ?? false ), 'Empty source was not verified.' );

	// Real-time grant revocation must invalidate all subsequent operations.
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );
	$revoked = wpai134_session_action( $token, $session, $seq, array( 'action' => 'status', 'transfer_id' => $zero_id ) );
	wpai134_session_assert( ! empty( $revoked['result']['isError'] ), 'Revoked grant left a private lease operational.' );

	echo sprintf(
		"MEASURE: #134 native authenticated MCP private transfer bytes=%d chunks=%d php_peak_bytes=%d.\n",
		$source_size, $steps, memory_get_peak_usage( true )
	);
	echo "PASS: #134 native OAuth MCP configurable multi-chunk private transfer, hash, replay, zero, cancellation and revocation.\n";
} finally {
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$transfer = new \WP_AI_Bridge\Support\Private_Transfer_Session( new \WP_AI_Bridge\Support\Permissions( $settings ) );
	foreach ( $identities as $identity ) {
		$transfer->cancel( $identity );
	}
	if ( '' !== $token ) {
		$store->revoke( $token );
	}
	if ( false === $original_size ) {
		delete_option( Transfer_Settings::OPTION_NAME );
	} else {
		update_option( Transfer_Settings::OPTION_NAME, $original_size, false );
	}
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
}
