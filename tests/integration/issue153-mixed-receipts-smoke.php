<?php
/**
 * Real MariaDB shared legacy receipt / typed capacity regression (#153 R1).
 * Integration runner uses a disposable WordPress database only.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Create_Claim;
use WP_AI_Bridge\Support\Create_Claim_Store;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Workspace_Limits;
use WP_AI_Bridge\Workspace\Store;

function wpai153_receipt_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function wpai153_native_create( $name, $params ) {
	$ability = wp_get_ability( 'wp-ai-bridge/' . $name );
	wpai153_receipt_assert( $ability instanceof WP_Ability, 'Native WordPress create ability unavailable: ' . $name );
	return $ability->execute( $params );
}

$token             = wp_generate_uuid4();
$project           = 'wpai153-r1-' . $token;
$old_options       = array();
$created_options   = array();
$document_ids      = array();
$task_ids          = array();
$created_post      = 0;
$created_media     = 0;
$created_comment   = 0;
$comment_parent    = 0;
$record_policy     = new Workspace_Limits();
$previous_capacity = get_option( Workspace_Limits::OPTION_NAME, $record_policy->defaults() );
$bridge_settings   = new Settings();
$previous_grants   = get_option( Settings::OPTION_NAME, $bridge_settings->defaults() );
$baseline_generic  = Create_Claim_Store::count_domain( Create_Claim::DOMAIN_OPERATION );
$baseline_document = Create_Claim_Store::count_domain( Create_Claim::DOMAIN_CANONICAL );
wpai153_receipt_assert( $baseline_generic < 2048 && $baseline_document < 2048, 'Fixture must start with independent receipt headroom.' );

try {
	$legacy_json = wp_json_encode(
		array(
			'fingerprint' => str_repeat( '0', 64 ),
			'state'       => 'expired',
			'created_at'  => 1,
			'id'          => 0,
		)
	);
	for ( $i = 0; $i < 2300; ++$i ) {
		$name = Create_Claim::PREFIX . hash( 'sha256', 'wpai153-legacy-' . $token . '-' . $i );
		wpai153_receipt_assert( add_option( $name, $legacy_json, '', 'off' ), 'Could not seed immutable legacy receipt.' );
		$old_options[] = $name;
	}
	wpai153_receipt_assert(
		Create_Claim_Store::count() >= 2300 &&
		Create_Claim_Store::count_domain( Create_Claim::DOMAIN_OPERATION ) === $baseline_generic &&
		Create_Claim_Store::count_domain( Create_Claim::DOMAIN_CANONICAL ) === $baseline_document,
		'Historical generic receipts contaminated typed operation/canonical budgets.'
	);
	$raised = $record_policy->sanitize(
		array(
			'documents' => '2200',
			'tasks'     => '5100',
		)
	);
	update_option( Workspace_Limits::OPTION_NAME, $raised, false );
	wpai153_receipt_assert(
		9500 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_CANONICAL ) &&
		2048 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_OPERATION ),
		'Generic receipt capacity is coupled to Workspace count settings.'
	);

	$store = new Store();
	for ( $i = 0; $i < 10; ++$i ) {
		$created = $store->create_document(
			array(
				'project_ref' => $project,
				'key'         => 'plan-' . $i,
				'title'       => 'WPAI153 legacy-independent document ' . $i,
				'content'     => 'Authoritative test plan',
			)
		);
		wpai153_receipt_assert( ! is_wp_error( $created ) && ! empty( $created['id'] ), 'Canonical receipt admission was stranded by legacy generic receipts.' );
		$document_ids[]    = (int) $created['id'];
		$identity          = array( 'canonical-document', get_current_blog_id(), $project, 'plan-' . $i );
		$created_options[] = Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( $identity ) );
	}
	for ( $i = 0; $i < 10; ++$i ) {
		$created = $store->create_task(
			array(
				'title'       => 'WPAI153 task ' . $i,
				'project_ref' => $project,
			)
		);
		wpai153_receipt_assert( ! is_wp_error( $created ), 'Workspace task could not reach the fixture policy.' );
		$task_ids[] = (int) $created['id'];
	}
	// Lowering record quotas affects Workspace admission only, never the
	// existing records, old immutable receipts or unrelated native creates.
	update_option(
		Workspace_Limits::OPTION_NAME,
		array(
			'documents' => 10,
			'tasks'     => 10,
		),
		false
	);
	$blocked_doc  = $store->create_document( array( 'title' => 'Above lowered capacity' ) );
	$blocked_task = $store->create_task( array( 'title' => 'Above lowered task capacity' ) );
	$preserved    = $store->page_documents(
		array(
			'project_ref' => $project,
			'limit'       => 25,
		)
	);
	wpai153_receipt_assert(
		is_wp_error( $blocked_doc ) && 'workspace_document_limit' === $blocked_doc->get_error_code() &&
		is_wp_error( $blocked_task ) && 'workspace_task_limit' === $blocked_task->get_error_code() &&
		! is_wp_error( $preserved ) && 10 === $preserved['total'],
		'Lowered Workspace quota hid old records or failed to block excess admission.'
	);
	wpai153_receipt_assert(
		2048 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_OPERATION ),
		'Lowering Workspace capacity shrank unrelated generic recovery admission.'
	);

	$grants                                  = $bridge_settings->defaults();
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	$grants[ Settings::GROUP_REMOTE_MEDIA ]  = 1;
	$grants[ Settings::GROUP_LIVE_CONTENT ]  = 1;
	$grants[ Settings::GROUP_COMMENTS ]      = 1;
	update_option( Settings::OPTION_NAME, $grants, false );

	$post_key   = 'post-' . $token;
	$post       = array(
		'action'       => 'create',
		'post_type'    => 'post',
		'title'        => 'WPAI153 distinct native keyed post',
		'status'       => 'draft',
		'operation_id' => $post_key,
	);
	$first_post = wpai153_native_create( 'content-upsert', $post );
	wpai153_receipt_assert( ! is_wp_error( $first_post ) && ! empty( $first_post['id'] ), 'Post create failed because legacy receipts exceeded 2048.' );
	$created_post      = (int) $first_post['id'];
	$post_identity     = array( get_current_blog_id(), get_current_user_id(), (string) OAuth_Server::authenticated_mcp_client_id(), 'content-create', $post_key );
	$post_receipt      = Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( $post_identity ) );
	$created_options[] = $post_receipt;
	$raw               = get_option( $post_receipt, false );
	$historical_state  = is_string( $raw ) ? json_decode( $raw, true ) : null;
	wpai153_receipt_assert( is_array( $historical_state ) && 'operation' === $historical_state['admission_domain'], 'New typed receipt was not recorded.' );
	unset( $historical_state['admission_domain'] );
	update_option( $post_receipt, wp_json_encode( $historical_state ), false );
	$retried_post = wpai153_native_create( 'content-upsert', $post );
	wpai153_receipt_assert(
		! is_wp_error( $retried_post ) && (int) $retried_post['id'] === $created_post,
		'Legacy untyped committed receipt failed to recover exact post without replay.'
	);

	$media_key = 'media-' . $token;
	$media     = array(
		'filename'       => 'wpai153-fixture.png',
		'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=',
		'operation_id'   => $media_key,
	);
	$uploaded  = wpai153_native_create( 'media-upload', $media );
	wpai153_receipt_assert( ! is_wp_error( $uploaded ) && ! empty( $uploaded['id'] ), 'Unrelated keyed media upload was disabled by Workspace quota lowering.' );
	$created_media     = (int) $uploaded['id'];
	$created_options[] = Create_Claim::PREFIX . hash(
		'sha256',
		wp_json_encode(
			array( get_current_blog_id(), get_current_user_id(), (string) OAuth_Server::authenticated_mcp_client_id(), 'media-upload', $media_key )
		)
	);

	$parent = wp_insert_post(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'post_title'     => 'WPAI153 comment parent',
			'comment_status' => 'open',
		),
		true
	);
	wpai153_receipt_assert( ! is_wp_error( $parent ) && $parent, 'Comment parent could not be created.' );
	$comment_parent = (int) $parent;
	$comment_key    = 'comment-' . $token;
	$comment_params = array(
		'post'         => $comment_parent,
		'content'      => 'WPAI153 keyed comment.',
		'operation_id' => $comment_key,
	);
	$commented      = wpai153_native_create( 'comment-reply', $comment_params );
	wpai153_receipt_assert( ! is_wp_error( $commented ) && ! empty( $commented['id'] ), 'Unrelated keyed comment reply was disabled by Workspace quota lowering.' );
	$created_comment   = (int) $commented['id'];
	$created_options[] = Create_Claim::PREFIX . hash(
		'sha256',
		wp_json_encode(
			array( get_current_blog_id(), get_current_user_id(), (string) OAuth_Server::authenticated_mcp_client_id(), 'comment-reply', $comment_key )
		)
	);
	wpai153_receipt_assert(
		Create_Claim_Store::count_domain( Create_Claim::DOMAIN_OPERATION ) >= $baseline_generic + 2 &&
		Create_Claim_Store::count_domain( Create_Claim::DOMAIN_CANONICAL ) === $baseline_document + 10,
		'Independent durable receipt counts drifted after lowering and mixed native creates.'
	);
	echo "PASS: #153 R1 2300 historical legacy receipts, configured Workspace admission, lowering, old-key recovery and native post/media/comment creates.\n";
} finally {
	if ( $created_comment ) {
		wp_delete_comment( $created_comment, true ); }
	if ( $comment_parent ) {
		wp_delete_post( $comment_parent, true ); }
	if ( $created_media ) {
		wp_delete_attachment( $created_media, true ); }
	if ( $created_post ) {
		wp_delete_post( $created_post, true ); }
	foreach ( $task_ids as $id ) {
		wp_delete_post( $id, true ); }
	foreach ( $document_ids as $id ) {
		wp_delete_post( $id, true ); }
	foreach ( $created_options as $name ) {
		delete_option( $name ); }
	foreach ( $old_options as $name ) {
		delete_option( $name ); }
	update_option( Workspace_Limits::OPTION_NAME, $previous_capacity, false );
	update_option( Settings::OPTION_NAME, $previous_grants, false );
}
