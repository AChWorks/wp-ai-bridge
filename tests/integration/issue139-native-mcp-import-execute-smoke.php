<?php
/**
 * Exact authenticated native WXR Importer effects, via the
 * installed official MCP Adapter's authenticated WordPress REST dispatcher.
 *
 * Actual WXR import effects run ONLY in the disposable isolated WordPress
 * installation with official WordPress Importer enabled before bootstrap.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;

/** @param bool $condition Assertion. @param string $reason Reason. */
function wpai139_effect_assert( $condition, $reason ) {
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
function wpai139_effect_request( $token, $session, $sequence, $method, $parameters = array() ) {
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
	wpai139_effect_assert( 200 === $response->get_status() && is_array( $decoded ), 'Authenticated MCP REST request did not complete.' );
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
function wpai139_effect_execute( $token, $session, &$sequence, $ability, $input ) {
	list( $raw ) = wpai139_effect_request( $token, $session, $sequence++, $ability, $input );
	$structured  = $raw['result']['structuredContent'] ?? array();
	wpai139_effect_assert( is_array( $structured ), 'MCP result lacked structured content.' );
	if ( ! empty( $raw['result']['isError'] ) ) {
		return array( 'success' => false );
	}
	return $structured;
}

$actor          = get_current_user_id();
$grants_before  = get_option( Settings::OPTION_NAME, false );
$settings       = new Settings();
$grants         = $settings->defaults();
$store          = new OAuth_Store();
$oauth          = new OAuth_Server( $store );
$marker         = 'wpai139-import-' . bin2hex( random_bytes( 5 ) );
$source_id      = 0;
$restored_id    = 0;
$partial_id     = 0;
$late_id        = 0;
$source_date    = '';
$lease_ids      = array();
$token          = '';
$session        = '';
$seq            = 8;
$post_type      = 'wpai139_probe';
$taxonomy       = 'wpai139_label';
$artifact_path  = '';
$owner_count    = count_users()['total_users'];
$source_content = 'سلام دنیا / Welcome 🌍 original WXR content ' . $marker;
$source_term    = 'WXR Proof ' . $marker;

try {
	wpai139_effect_assert(
		$actor > 0 && current_user_can( 'manage_options' ) &&
		defined( 'WP_LOAD_IMPORTERS' ) && true === WP_LOAD_IMPORTERS &&
		class_exists( 'WP_Import', false ),
		'Native importer must be installed and bootstrapped in the disposable test site.'
	);
	$grants[ Settings::GROUP_SITE_CONFIG ]   = 1;
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	$grants[ Settings::GROUP_SITE_READ ]     = 1;
	$grants[ Settings::GROUP_LIVE_CONTENT ]  = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	register_post_type(
		$post_type,
		array(
			'public'     => true,
			'can_export' => true,
			'supports'   => array( 'title', 'editor', 'author', 'comments' ),
		)
	);
	register_taxonomy( $taxonomy, array( $post_type ), array( 'public' => true ) );
	$source_id = wp_insert_post(
		array(
			'post_title'   => $marker,
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_author'  => $actor,
			'post_content' => wp_slash( $source_content ),
		),
		true
	);
	wpai139_effect_assert( ! is_wp_error( $source_id ) && $source_id > 0, 'Could not seed source WXR CPT fixture.' );
	$source_date = (string) get_post( $source_id )->post_date;
	add_post_meta( $source_id, 'wpai139_verified_import', $marker, true );
	$term = wp_insert_term( $source_term, $taxonomy );
	wpai139_effect_assert( ! is_wp_error( $term ), 'Cannot create source WXR taxonomy fixture.' );
	wp_set_object_terms( $source_id, (int) $term['term_id'], $taxonomy );
	$artifact = ( new \WP_AI_Bridge\Support\WXR_Export_Stager(
		new \WP_AI_Bridge\Support\Permissions( $settings )
	) )->stage( $post_type, 2 * 1048576 );
	wpai139_effect_assert( ! is_wp_error( $artifact ), 'Official WordPress exporter could not create WXR fixture.' );
	$artifact_path = $artifact['path'];
	$bytes         = file_get_contents( $artifact_path );
	wpai139_effect_assert(
		is_string( $bytes ) && strlen( $bytes ) === $artifact['bytes'] &&
		hash( 'sha256', $bytes ) === $artifact['sha256'] &&
		false !== strpos( $bytes, $source_content ) &&
		false !== strpos( $bytes, $marker ),
		'Core WXR source did not contain exact UTF-8, meta and CPT fixture.'
	);

	$token = $store->issue(
		OAuth_Store::TYPE_ACCESS,
		array(
			'user_id'   => $actor,
			'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
			'resource'  => $oauth->mcp_endpoint_url(),
			'scope'     => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
		),
		1200
	);
	wpai139_effect_assert( is_string( $token ) && '' !== $token, 'OAuth token not available for native Importer test.' );
	$prior = class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ?
		\WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor ) : array();
	wp_set_current_user( 0 );
	list( $initialized, $headers ) = wpai139_effect_request( $token, '', 1, 'initialize' );
	wpai139_effect_assert( '2025-11-25' === ( $initialized['result']['protocolVersion'] ?? '' ), 'Native OAuth MCP init failed.' );
	$session = (string) ( $headers['Mcp-Session-Id'] ?? '' );
	if ( '' === $session && class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ) {
		$after   = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor );
		$session = (string) array_key_first( array_diff_key( $after, $prior ) );
	}
	wpai139_effect_assert( '' !== $session, 'Authenticated native MCP import test session missing.' );
	$notice = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$notice->set_header( 'Authorization', 'Bearer ' . $token );
	$notice->set_header( 'Mcp-Session-Id', $session );
	$notice->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	$notice->set_header( 'Content-Type', 'application/json' );
	$notice->set_body( '{"jsonrpc":"2.0","method":"notifications/initialized"}' );
	wpai139_effect_assert( 202 === rest_do_request( $notice )->get_status(), 'Native MCP import initialization missing.' );

	/** @param string $payload Exact validated WXR bytes. @return string Private inbound lease. */
	function wpai139_effect_stage( $payload, $token, $session, &$seq, &$lease_ids ) {
		$begin = wpai139_effect_execute(
			$token,
			$session,
			$seq,
			'wp-ai-bridge/private-transfer',
			array(
				'action'      => 'begin',
				'purpose'     => 'wxr',
				'total_bytes' => strlen( $payload ),
				'sha256'      => hash( 'sha256', $payload ),
			)
		);
		wpai139_effect_assert( true === ( $begin['success'] ?? false ), 'WXR import ingress cannot begin.' );
		$id          = $begin['data']['transfer_id'];
		$lease_ids[] = $id;
		$window      = min( 20000, (int) $begin['data']['max_chunk_bytes'] );
		for ( $offset = 0; $offset < strlen( $payload ); $offset += $window ) {
			$appended = wpai139_effect_execute(
				$token,
				$session,
				$seq,
				'wp-ai-bridge/private-transfer',
				array(
					'action'         => 'append',
					'transfer_id'    => $id,
					'offset'         => $offset,
					'content_base64' => base64_encode( substr( $payload, $offset, $window ) ),
				)
			);
			wpai139_effect_assert( true === ( $appended['success'] ?? false ), 'WXR native effect source chunk did not append.' );
		}
		$verified = wpai139_effect_execute(
			$token,
			$session,
			$seq,
			'wp-ai-bridge/private-transfer',
			array(
				'action'      => 'verify',
				'transfer_id' => $id,
			)
		);
		wpai139_effect_assert( true === ( $verified['success'] ?? false ), 'WXR native import source verification failed.' );
		return $id;
	}

	// A byte-verifiable, structurally valid WXR must still reject PHP-object
	// metadata before the upstream native importer deserializes anything.
	$object_meta = '<wp:postmeta><wp:meta_key><![CDATA[wpai139_object_probe]]></wp:meta_key><wp:meta_value><![CDATA[O:8:"stdClass":0:{}]]></wp:meta_value></wp:postmeta>';
	wpai139_effect_assert( false !== strpos( $bytes, '</item>' ), 'Core WXR lacks item boundary for unsafe-meta probe.' );
	$poisoned    = substr_replace( $bytes, $object_meta, strpos( $bytes, '</item>' ), 0 );
	$poison_id   = wpai139_effect_stage( $poisoned, $token, $session, $seq, $lease_ids );
	$poison_plan = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-plan',
		array( 'transfer_id' => $poison_id )
	);
	wpai139_effect_assert( false === ( $poison_plan['success'] ?? true ), 'Serialized PHP object meta was approved for native WXR import.' );
	echo "PASS: #139 exact-byte verified WXR serialized PHP object rejected before any native mutation.\n";

	// Importing published content is an effect stronger than draft creation.
	// Test status-based Live Content gate independently from filename/format.
	$published = preg_replace(
		'~(<wp:status>\s*(?:<!\[CDATA\[)?)draft((?:\]\]>)?\s*</wp:status>)~',
		'$1publish$2',
		$bytes,
		1,
		$publish_subs
	);
	wpai139_effect_assert( 1 === $publish_subs && is_string( $published ), 'Core WXR status fixture could not be safely changed to publish.' );
	$published_id   = wpai139_effect_stage( $published, $token, $session, $seq, $lease_ids );
	$publish_denied = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-plan',
		array( 'transfer_id' => $published_id )
	);
	wpai139_effect_assert( false === ( $publish_denied['success'] ?? true ), 'WXR published post bypassed default-off Live Content grant.' );
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_LIVE_CONTENT ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );
	$publish_plan = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-plan',
		array( 'transfer_id' => $published_id )
	);
	wpai139_effect_assert(
		true === ( $publish_plan['success'] ?? false ) &&
		1 === ( $publish_plan['data']['publish_candidates'] ?? 0 ),
		'Native WXR published content was not explicitly counted with Live Content consent.'
	);
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_LIVE_CONTENT ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );
	$publish_revoked = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-execute',
		array(
			'transfer_id' => $published_id,
			'plan_token'  => $publish_plan['data']['plan_token'],
			'confirm'     => true,
		)
	);
	wpai139_effect_assert( false === ( $publish_revoked['success'] ?? true ), 'Revoked Live Content grant did not block published WXR import.' );
	echo "PASS: #139 published import Live Content grant, source-bound plan and revocation deny.\n";

	$id   = wpai139_effect_stage( $bytes, $token, $session, $seq, $lease_ids );
	$info = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-plan',
		array( 'transfer_id' => $id )
	);
	wpai139_effect_assert(
		true === ( $info['success'] ?? false ) &&
		1 === ( $info['data']['item_count'] ?? 0 ) &&
		1 === ( $info['data']['conflict_count'] ?? 0 ) &&
		false === ( $info['data']['fetch_attachments'] ?? true ) &&
		false === ( $info['data']['create_users'] ?? true ),
		'Native provider-bound read-only plan did not report existing source conflict.'
	);
	$plan_before = $info['data'];
	$rejected    = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-execute',
		array(
			'transfer_id' => $id,
			'plan_token'  => $plan_before['plan_token'],
			'confirm'     => true,
		)
	);
	wpai139_effect_assert( false === ( $rejected['success'] ?? true ), 'Default reject-on-conflict was bypassed.' );
	$no_confirm = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-execute',
		array(
			'transfer_id' => $id,
			'plan_token'  => $plan_before['plan_token'],
			'confirm'     => false,
		)
	);
	wpai139_effect_assert( false === ( $no_confirm['success'] ?? true ), 'WXR native importer executed without explicit consent.' );
	$wrong_author = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-plan',
		array(
			'transfer_id'    => $id,
			'author_mapping' => array( 'unknown_author' => 9999999 ),
		)
	);
	wpai139_effect_assert( false === ( $wrong_author['success'] ?? true ), 'Unknown author mapping was accepted.' );

	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );
	$revoked = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-plan',
		array( 'transfer_id' => $id )
	);
	wpai139_effect_assert( false === ( $revoked['success'] ?? true ), 'Revoked Builder Write did not deny native importer plan.' );
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_delete_post( $source_id, true );
	$source_id = 0;
	wp_set_current_user( 0 );

	$plan = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-plan',
		array(
			'transfer_id'    => $id,
			'author_mapping' => array( 'admin' => $actor ),
		)
	);
	wpai139_effect_assert(
		true === ( $plan['success'] ?? false ) &&
		0 === ( $plan['data']['conflict_count'] ?? -1 ) &&
		( $plan['data']['plan_token'] ?? '' ) !== $plan_before['plan_token'],
		'Import plan did not bind changed WordPress target state and exact author mapping.'
	);
	$stale = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-execute',
		array(
			'transfer_id' => $id,
			'plan_token'  => $plan_before['plan_token'],
			'confirm'     => true,
		)
	);
	wpai139_effect_assert( false === ( $stale['success'] ?? true ), 'Stale pre-deletion plan token was accepted.' );

	$executed = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-execute',
		array(
			'transfer_id'    => $id,
			'author_mapping' => array( 'admin' => $actor ),
			'plan_token'     => $plan['data']['plan_token'],
			'confirm'        => true,
		)
	);
	wpai139_effect_assert(
		true === ( $executed['success'] ?? false ) &&
		true === ( $executed['data']['native_provider_returned'] ?? false ) &&
		1 === ( $executed['data']['processed_posts'] ?? 0 ),
		'Official native importer did not execute the exact approved WXR once.'
	);
	$created = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'title'          => $marker,
			'posts_per_page' => 3,
		)
	);
	wpai139_effect_assert( 1 === count( $created ), 'Official native importer did not create one target CPT.' );
	$restored_id = (int) $created[0]->ID;
	wpai139_effect_assert(
		$source_content === $created[0]->post_content &&
		(int) $created[0]->post_author === $actor &&
		get_post_meta( $restored_id, 'wpai139_verified_import', true ) === $marker,
		'Native Importer changed imported multilingual body, author or metadata.'
	);
	$term_names = wp_get_post_terms( $restored_id, $taxonomy, array( 'fields' => 'names' ) );
	wpai139_effect_assert( ! is_wp_error( $term_names ) && in_array( $source_term, $term_names, true ), 'Native Importer did not preserve CPT taxonomy association.' );
	$status = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/private-transfer',
		array(
			'action'      => 'status',
			'transfer_id' => $id,
		)
	);
	wpai139_effect_assert(
		true === ( $status['success'] ?? false ) &&
		'committed' === ( $status['data']['state'] ?? '' ) &&
		'provider_returned' === ( $status['data']['outcome'] ?? '' ) &&
		1 === ( $status['data']['import_processed'] ?? 0 ),
		'Durable native Importer claim and receipt were not recorded.'
	);
	$replayed = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-execute',
		array(
			'transfer_id'    => $id,
			'author_mapping' => array( 'admin' => $actor ),
			'plan_token'     => $plan['data']['plan_token'],
			'confirm'        => true,
		)
	);
	wpai139_effect_assert( false === ( $replayed['success'] ?? true ), 'Native WXR effect was replayed after committing.' );
	wpai139_effect_assert( (int) count_users()['total_users'] === $owner_count, 'Native WXR Importer created or deleted users.' );
	echo "PASS: #139 exact authorized native OAuth MCP WXR Importer created CPT, Unicode, taxonomy, meta with existing author; no user/remote file creation.\n";
	echo "PASS: #139 default conflict denial, grant revocation, exact plan stale rejection, durable no-replay and observable native receipt.\n";

	wp_set_current_user( $actor );
	wp_delete_post( $restored_id, true );
	$restored_id = 0;
	wp_set_current_user( 0 );
	$second = wpai139_effect_stage( $bytes, $token, $session, $seq, $lease_ids );
	$next   = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-plan',
		array( 'transfer_id' => $second )
	);
	wpai139_effect_assert( true === ( $next['success'] ?? false ) && 0 === ( $next['data']['conflict_count'] ?? -1 ), 'Pre-interruption native plan invalid.' );
	// Stop AFTER Core writes the first post. A native Importer error is not
	// a transaction rollback and must never trigger an automatic replay.
	$fail_after_insert = static function ( $post_id ) {
		if ( is_int( $post_id ) && $post_id > 0 ) {
			throw new RuntimeException( 'disposable after-insert importer interruption probe' );
		}
	};
	add_action( 'wp_import_insert_post', $fail_after_insert, 1, 1 );
	$interrupted = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-execute',
		array(
			'transfer_id' => $second,
			'plan_token'  => $next['data']['plan_token'],
			'confirm'     => true,
		)
	);
	remove_action( 'wp_import_insert_post', $fail_after_insert, 1 );
	wpai139_effect_assert( false === ( $interrupted['success'] ?? true ), 'Importer exception did not return an uncertain state.' );
	$partial_objects = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'title'          => $marker,
			'posts_per_page' => 3,
		)
	);
	wpai139_effect_assert( 1 === count( $partial_objects ), 'Native partial-operation fixture did not insert a post before interruption.' );
	$partial_id = (int) $partial_objects[0]->ID;
	$unknown    = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/private-transfer',
		array(
			'action'      => 'status',
			'transfer_id' => $second,
		)
	);
	wpai139_effect_assert(
		true === ( $unknown['success'] ?? false ) &&
		'committing' === ( $unknown['data']['state'] ?? '' ) &&
		'unknown' === ( $unknown['data']['outcome'] ?? '' ),
		'Interrupted native WXR effect lost its immutable claim / unknown state.'
	);
	$retry = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-execute',
		array(
			'transfer_id' => $second,
			'plan_token'  => $next['data']['plan_token'],
			'confirm'     => true,
		)
	);
	wpai139_effect_assert( false === ( $retry['success'] ?? true ), 'Claimed interrupted WXR import was silently retried.' );
	wpai139_effect_assert(
		1 === count(
			get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'title'          => $marker,
					'posts_per_page' => 3,
				)
			)
		),
		'Unknown import was repeated and duplicated a partially created post.'
	);
	echo "PASS: #139 native Importer interrupted after an actual WordPress write; partial outcome, durable unknown claim and no blind retry.\n";

	// In a second independent effect, a competing writer creates an existing
	// target AFTER consent planning, inside native importer start. A hook at
	// its actual wp_import_existing_post decision must refuse changing that
	// unconsented new target even though the one-way claim is already held.
	wp_set_current_user( $actor );
	wp_delete_post( $partial_id, true );
	$partial_id = 0;
	wp_set_current_user( 0 );
	$race_lease = wpai139_effect_stage( $bytes, $token, $session, $seq, $lease_ids );
	$race_plan  = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-plan',
		array( 'transfer_id' => $race_lease )
	);
	wpai139_effect_assert( true === ( $race_plan['success'] ?? false ) && 0 === ( $race_plan['data']['conflict_count'] ?? -1 ), 'Target race fixture started with an existing object.' );
	$inject_late = static function () use ( &$late_id, $source_date, $post_type, $marker, $actor ) {
		$late_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'draft',
				'post_title'   => $marker,
				'post_date'    => $source_date,
				'post_author'  => $actor,
				'post_content' => 'competing author content: do not overwrite',
			),
			true
		);
		if ( is_wp_error( $late_id ) ) {
			throw new RuntimeException( 'race fixture could not create competing post' );
		}
	};
	add_action( 'import_start', $inject_late, 1 );
	$race_result = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/wxr-import-execute',
		array(
			'transfer_id' => $race_lease,
			'plan_token'  => $race_plan['data']['plan_token'],
			'confirm'     => true,
		)
	);
	remove_action( 'import_start', $inject_late, 1 );
	wpai139_effect_assert(
		false === ( $race_result['success'] ?? true ) &&
		is_int( $late_id ) && $late_id > 0 &&
		'competing author content: do not overwrite' === (string) get_post( $late_id )->post_content &&
		'' === (string) get_post_meta( $late_id, 'wpai139_verified_import', true ),
		'Target conflict race modified an unapproved competing post.'
	);
	$race_state = wpai139_effect_execute(
		$token,
		$session,
		$seq,
		'wp-ai-bridge/private-transfer',
		array(
			'action'      => 'status',
			'transfer_id' => $race_lease,
		)
	);
	wpai139_effect_assert(
		true === ( $race_state['success'] ?? false ) &&
		'committing' === ( $race_state['data']['state'] ?? '' ) &&
		'unknown' === ( $race_state['data']['outcome'] ?? '' ),
		'Last-moment target conflict was not durably recorded as an unknown one-way effect.'
	);
	echo "PASS: #139 newly raced native importer target rejected without modifying competing content; immutable uncertain receipt retained.\n";

} finally {
	wp_set_current_user( $actor );
	if ( $source_id > 0 ) {
		wp_delete_post( $source_id, true );
	}
	if ( $restored_id > 0 ) {
		wp_delete_post( $restored_id, true );
	}
	if ( $partial_id > 0 ) {
		wp_delete_post( $partial_id, true );
	}
	if ( $late_id > 0 ) {
		wp_delete_post( $late_id, true );
	}
	if ( '' !== $artifact_path ) {
		wp_delete_file( $artifact_path );
	}
	if ( false === $grants_before ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $grants_before, false );
	}
}
