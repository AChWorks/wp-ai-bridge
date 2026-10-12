<?php
/**
 * Exact Bridge public WXR producer/verified private import preflight, via the
 * installed official MCP Adapter's authenticated WordPress REST dispatcher.
 *
 * No WXR import/real-site effect is executed. Fixture posts and private leases
 * live only in the isolated disposable integration WordPress installation.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;

/** @param bool $condition Assertion. @param string $reason Reason. */
function wpai139_mcp_assert( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
}

/**
 * @param string $token Ephemeral OAuth bearer.
 * @param string $session Authenticated MCP session identifier.
 * @param int $sequence JSON-RPC identity.
 * @param string $method MCP method.
 * @param array<string,mixed> $parameters Exact arguments.
 * @return array<int,mixed>
 */
function wpai139_mcp_request( $token, $session, $sequence, $method, $parameters = array() ) {
	$request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	$request->set_header( 'Content-Type', 'application/json' );
	if ( '' !== $session ) {
		$request->set_header( 'Mcp-Session-Id', $session );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	}
	$body = array(
		'jsonrpc' => '2.0',
		'id'      => $sequence,
		'method'  => $method,
	);
	if ( 'initialize' === $method ) {
		$body['params'] = array(
			'protocolVersion' => '2025-11-25',
			'capabilities'    => (object) array(),
			'clientInfo'      => array(
				'name'    => 'wpai139-private-mcp-wxr-fixture',
				'version' => '1.0',
			),
		);
	} else {
		$body['params'] = array(
			'name'      => 'mcp-adapter-execute-ability',
			'arguments' => array(
				'ability_name' => $method,
				'parameters'   => $parameters,
			),
		);
		$body['method'] = 'tools/call';
	}
	$request->set_body( wp_json_encode( $body ) );
	$response = rest_do_request( $request );
	$decoded  = json_decode( wp_json_encode( $response->get_data() ), true );
	wpai139_mcp_assert( 200 === $response->get_status() && is_array( $decoded ), 'Authenticated MCP REST request did not complete.' );
	return array( $decoded, $response->get_headers() );
}

/**
 * @param string $token Ephemeral OAuth bearer.
 * @param string $session Current MCP session.
 * @param int $sequence JSON-RPC counter.
 * @param string $ability Bridge Ability name.
 * @param array<string,mixed> $input Exact tool arguments.
 * @return array<string,mixed> Structured content.
 */
function wpai139_mcp_execute( $token, $session, &$sequence, $ability, $input ) {
	list( $raw ) = wpai139_mcp_request( $token, $session, $sequence++, $ability, $input );
	$structured  = $raw['result']['structuredContent'] ?? array();
	wpai139_mcp_assert( is_array( $structured ), 'MCP result lacked structured content.' );
	if ( ! empty( $raw['result']['isError'] ) ) {
		return array( 'success' => false );
	}
	return $structured;
}

$actor           = get_current_user_id();
$original_grants = get_option( Settings::OPTION_NAME, false );
$original_limits = get_option( Transfer_Settings::OPTION_NAME, false );
$settings        = new Settings();
$store           = new OAuth_Store();
$oauth           = new OAuth_Server( $store );
$created_post    = 0;
$transfer_ids    = array();
$token           = '';
$session         = '';
$seq             = 4;
$marker          = 'wpai139-binary-' . bin2hex( random_bytes( 6 ) );

try {
	wpai139_mcp_assert( $actor > 0 && current_user_can( 'manage_options' ) && current_user_can( 'export' ) && current_user_can( 'import' ), 'Native WXR test requires a disposable WordPress administrator.' );
	$grants                                  = $settings->defaults();
	$grants[ Settings::GROUP_SITE_CONFIG ]   = 1;
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	$grants[ Settings::GROUP_SITE_READ ]     = 1;
	update_option( Settings::OPTION_NAME, $grants, false );

	$created_post = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => 'Bridge Core WXR authenticated fixture ' . $marker,
			'post_content' => wp_slash( "Private WXR UTF-8 فارسی🌍 {$marker}\\ literal HTML <strong>safe</strong>." ),
		),
		true
	);
	wpai139_mcp_assert( ! is_wp_error( $created_post ) && (int) $created_post > 0, 'Could not create a private test-only WordPress post.' );

	$export  = wp_get_ability( 'wp-ai-bridge/wxr-export-create' );
	$preview = wp_get_ability( 'wp-ai-bridge/wxr-import-preview' );
	wpai139_mcp_assert( $export instanceof WP_Ability && $preview instanceof WP_Ability, 'WXR producer and import preview Abilities are not registered.' );
	$exp_meta = $export->get_meta()['annotations'] ?? array();
	$pre_meta = $preview->get_meta()['annotations'] ?? array();
	wpai139_mcp_assert(
		false === ( $exp_meta['readonly'] ?? null ) && false === ( $exp_meta['idempotent'] ?? null ) &&
		true === ( $pre_meta['readonly'] ?? null ) && true === ( $pre_meta['idempotent'] ?? null ),
		'WXR Ability metadata advertises incorrect side-effect semantics.'
	);

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
	wpai139_mcp_assert( is_string( $token ) && '' !== $token, 'Disposable OAuth bearer was unavailable.' );
	$before = class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ?
		\WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor ) : array();
	wp_set_current_user( 0 );
	list( $initialized, $headers ) = wpai139_mcp_request( $token, '', 1, 'initialize' );
	wpai139_mcp_assert( '2025-11-25' === ( $initialized['result']['protocolVersion'] ?? '' ), 'Official MCP Adapter initialization failed.' );
	$session = (string) ( $headers['Mcp-Session-Id'] ?? '' );
	if ( '' === $session && class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ) {
		$after   = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor );
		$session = (string) array_key_first( array_diff_key( $after, $before ) );
	}
	wpai139_mcp_assert( '' !== $session, 'Native MCP session identity not issued.' );
	$notice = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$notice->set_header( 'Authorization', 'Bearer ' . $token );
	$notice->set_header( 'Mcp-Session-Id', $session );
	$notice->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	$notice->set_header( 'Content-Type', 'application/json' );
	$notice->set_body( '{"jsonrpc":"2.0","method":"notifications/initialized"}' );
	wpai139_mcp_assert( 202 === rest_do_request( $notice )->get_status(), 'Native MCP notification was rejected.' );

	$created = wpai139_mcp_execute( $token, $session, $seq, 'wp-ai-bridge/wxr-export-create', array( 'content_type' => 'post' ) );
	wpai139_mcp_assert( true === ( $created['success'] ?? false ), 'Native WXR private source creation failed.' );
	$receipt = $created['data'];
	$out_id  = $receipt['transfer_id'] ?? '';
	wpai139_mcp_assert(
		is_string( $out_id ) && 64 === strlen( $out_id ) &&
		( $receipt['total_bytes'] ?? 0 ) > 200 &&
		1 === ( $receipt['blog_id'] ?? 0 ) &&
		'verified' === ( $receipt['state'] ?? '' ),
		'Core WXR private transfer metadata is incomplete.'
	);
	$transfer_ids[] = $out_id;

	// Site Read is NOT sufficient for exporting private drafts.
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );
	$revoked = wpai139_mcp_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/private-transfer',
		array(
			'action'      => 'read',
			'transfer_id' => $out_id,
			'offset'      => 0,
			'max_bytes'   => 4096,
		)
	);
	wpai139_mcp_assert( false === ( $revoked['success'] ?? true ), 'WXR private export bytes leaked after export grant revocation.' );
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );

	$downloaded = '';
	$read_count = 0;
	while ( strlen( $downloaded ) < $receipt['total_bytes'] ) {
		$read = wpai139_mcp_execute(
			$token,
			$session,
			$seq,
			'wp-ai-bridge/private-transfer',
			array(
				'action'      => 'read',
				'transfer_id' => $out_id,
				'offset'      => strlen( $downloaded ),
				'max_bytes'   => 24576,
			)
		);
		wpai139_mcp_assert( true === ( $read['success'] ?? false ), 'WXR source window read failed.' );
		$piece = base64_decode( $read['data']['content_base64'] ?? '', true );
		wpai139_mcp_assert(
			is_string( $piece ) && '' !== $piece &&
			hash( 'sha256', $piece ) === ( $read['data']['chunk_sha256'] ?? '' ) &&
			strlen( $downloaded ) + strlen( $piece ) === ( $read['data']['read_next_offset'] ?? -1 ),
			'WXR source window offset or SHA-256 did not match.'
		);
		$downloaded .= $piece;
		++$read_count;
		wpai139_mcp_assert( $read_count < 200, 'WXR byte read used unbounded window count.' );
	}
	wpai139_mcp_assert(
		hash( 'sha256', $downloaded ) === $receipt['sha256'] &&
		strlen( $downloaded ) === $receipt['total_bytes'] &&
		false !== strpos( $downloaded, $marker ) &&
		false !== strpos( $downloaded, '<rss ' ),
		'WordPress WXR export is incomplete or byte hash differs.'
	);
	echo "PASS: #139 native OAuth MCP private WXR producer/windowed egress; SHA-256 and draft payload match.\n";

	// Read-only private preview is purpose-specific and refuses the outbound lease.
	$wrong_purpose = wpai139_mcp_execute( $token, $session, $seq, 'wp-ai-bridge/wxr-import-preview', array( 'transfer_id' => $out_id ) );
	wpai139_mcp_assert( false === ( $wrong_purpose['success'] ?? true ), 'Outbound WXR lease was accepted as incoming import bytes.' );

	$begun = wpai139_mcp_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/private-transfer',
		array(
			'action'      => 'begin',
			'purpose'     => 'wxr',
			'total_bytes' => strlen( $downloaded ),
			'sha256'      => hash( 'sha256', $downloaded ),
		)
	);
	wpai139_mcp_assert( true === ( $begun['success'] ?? false ), 'WXR inbound lease begin failed.' );
	$in_id          = $begun['data']['transfer_id'];
	$transfer_ids[] = $in_id;
	$chunk_bytes    = min( 24000, (int) $begun['data']['max_chunk_bytes'] );
	for ( $pos = 0; $pos < strlen( $downloaded ); $pos += $chunk_bytes ) {
		$piece    = substr( $downloaded, $pos, $chunk_bytes );
		$appended = wpai139_mcp_execute(
			$token,
			$session,
			$seq,
			'wp-ai-bridge/private-transfer',
			array(
				'action'         => 'append',
				'transfer_id'    => $in_id,
				'offset'         => $pos,
				'content_base64' => base64_encode( $piece ),
			)
		);
		wpai139_mcp_assert( true === ( $appended['success'] ?? false ), 'Authenticated WXR inbound chunk was rejected.' );
	}
	$verified = wpai139_mcp_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/private-transfer',
		array(
			'action'      => 'verify',
			'transfer_id' => $in_id,
		)
	);
	wpai139_mcp_assert( true === ( $verified['success'] ?? false ), 'WXR inbound complete SHA-256 verification failed.' );

	$preflight = wpai139_mcp_execute( $token, $session, $seq, 'wp-ai-bridge/wxr-import-preview', array( 'transfer_id' => $in_id ) );
	wpai139_mcp_assert(
		true === ( $preflight['success'] ?? false ) &&
		( $preflight['data']['content_sha256'] ?? '' ) === hash( 'sha256', $downloaded ) &&
		( $preflight['data']['content_bytes'] ?? -1 ) === strlen( $downloaded ) &&
		( $preflight['data']['item_count'] ?? 0 ) > 0 &&
		false === ( $preflight['data']['mutation_performed'] ?? true ) &&
		false === ( $preflight['data']['authoritative_plan'] ?? true ),
		'Verified incoming WXR source was not structurally previewed without side effects.'
	);
	wpai139_mcp_assert( false !== get_post( $created_post ), 'Read-only WXR preflight unexpectedly changed original WordPress content.' );
	echo "PASS: #139 native OAuth MCP verified inbound WXR -> read-only XML preview, no import effect.\n";

	// Revoke the required Builder Write grant. It must deny import preview.
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );
	$denied = wpai139_mcp_execute( $token, $session, $seq, 'wp-ai-bridge/wxr-import-preview', array( 'transfer_id' => $in_id ) );
	wpai139_mcp_assert( false === ( $denied['success'] ?? true ), 'WXR preview bypassed revoked Builder Write access.' );
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );

	echo "PASS: #139 WXR transfer purpose isolation, revoke/resume checks and MCP annotations.\n";
} finally {
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_SITE_CONFIG ]   = 1;
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );
	if ( '' !== $session ) {
		foreach ( $transfer_ids as $id ) {
			wpai139_mcp_execute(
				$token,
				$session,
				$seq,
				'wp-ai-bridge/private-transfer',
				array(
					'action'      => 'cancel',
					'transfer_id' => $id,
				)
			);
		}
	}
	wp_set_current_user( $actor );
	if ( $created_post > 0 ) {
		wp_delete_post( $created_post, true );
	}
	if ( false === $original_grants ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original_grants, false );
	}
	if ( false === $original_limits ) {
		delete_option( Transfer_Settings::OPTION_NAME );
	} else {
		update_option( Transfer_Settings::OPTION_NAME, $original_limits, false );
	}
}
