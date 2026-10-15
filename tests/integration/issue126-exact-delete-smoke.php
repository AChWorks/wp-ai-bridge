<?php
/**
 * Real isolated WordPress exact Workspace delete acceptance (#126).
 *
 * The test runner supplies a fresh disposable database; it MUST NOT be run
 * against an unrelated installed/production site.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$abilities = static function ( $input ) {
	$api = wp_get_ability( 'wp-ai-bridge/workspace-delete' );
	if ( ! $api instanceof WP_Ability ) {
		throw new RuntimeException( 'Expected exact Workspace delete Ability is not registered.' );
	}
	return $api->execute( $input );
};
$record_ids = static function ( $type ) {
	$ids = get_posts( array(
		'post_type'      => $type,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );
	$ids = array_map( 'intval', $ids );
	sort( $ids, SORT_NUMERIC );
	return $ids;
};
$store        = new Store();
$settings     = new Settings();
$before       = get_option( Settings::OPTION_NAME, null );
$old_docs     = $record_ids( Store::DOCUMENT_POST_TYPE );
$old_tasks    = $record_ids( Store::TASK_POST_TYPE );
$access       = $settings->defaults();
$access[ Settings::GROUP_SITE_READ ]         = 1;
$access[ Settings::GROUP_BUILDER_WRITE ]     = 1;
$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $access, false );
$auth         = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
$auth->setValue( null, 'disposable-ci-exact-delete' );
$project_ref  = 'issue126-e2e-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 16 );
$blog_id      = (int) get_current_blog_id();
$doc          = null;
$task         = null;
try {
	$doc = $store->create_document( array(
		'project_ref' => $project_ref,
		'key'         => 'fixture-brief',
		'title'       => 'CI disposable Project Brief',
		'content'     => "# Brief\n\nDisposable exact-delete fixture.",
	) );
	$assert( ! is_wp_error( $doc ) && $doc['project_ref'] === $project_ref, 'Bound project document create failed.' );
	$task = $store->create_task( array(
		'project_ref' => $project_ref,
		'title'       => 'CI disposable active task',
		'progress'    => 'in_progress',
		'next_action' => 'Check exact fixture delete and stale-state rejection',
	) );
	$assert( ! is_wp_error( $task ) && $task['project_ref'] === $project_ref, 'Bound project task create failed.' );
	$doc_input = array(
		'kind'                => 'document',
		'id'                  => (int) $doc['id'],
		'blog_id'             => $blog_id,
		'project_ref'         => $project_ref,
		'expected_version'    => (int) $doc['version'],
		'expected_state_hash' => (string) $doc['state_hash'],
		'confirmation'        => 'PERMANENTLY DELETE DOCUMENT ' . $doc['id'],
	);
	$task_input = array(
		'kind'                => 'task',
		'id'                  => (int) $task['id'],
		'blog_id'             => $blog_id,
		'project_ref'         => $project_ref,
		'expected_version'    => (int) $task['version'],
		'expected_state_hash' => (string) $task['state_hash'],
		'confirmation'        => 'PERMANENTLY DELETE TASK ' . $task['id'],
	);

	$deny = $abilities( array_merge( $doc_input, array( 'blog_id' => $blog_id + 1 ) ) );
	$assert( is_wp_error( $deny ) && (bool) get_post( $doc['id'] ), 'Cross-blog request deleted a fixture.' );
	$deny = $abilities( array_merge( $doc_input, array( 'project_ref' => 'wrong-project' ) ) );
	$assert( is_wp_error( $deny ) && (bool) get_post( $doc['id'] ), 'Wrong-project request deleted a fixture.' );
	$deny = $abilities( array_merge( $doc_input, array( 'kind' => 'task', 'confirmation' => 'PERMANENTLY DELETE TASK ' . $doc['id'] ) ) );
	$assert( is_wp_error( $deny ) && (bool) get_post( $doc['id'] ), 'Wrong post type request deleted a fixture.' );
	$deny = $abilities( array_merge( $doc_input, array( 'confirmation' => 'yes' ) ) );
	$assert( is_wp_error( $deny ) && (bool) get_post( $doc['id'] ), 'Weak confirmation deleted a fixture.' );
	$auth->setValue( null, '' );
	$deny = $abilities( $doc_input );
	$assert( is_wp_error( $deny ) && (bool) get_post( $doc['id'] ), 'Missing authenticated MCP client permitted deletion.' );
	$auth->setValue( null, 'disposable-ci-exact-delete' );
	$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
	update_option( Settings::OPTION_NAME, $access, false );
	$deny = $abilities( $doc_input );
	$assert( is_wp_error( $deny ) && (bool) get_post( $doc['id'] ), 'Revoked destructive grant permitted deletion.' );
	$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );

	$updated = $store->update_document(
		$doc['id'],
		array(
			'content'             => "# Brief\n\nRevision before guarded delete.",
			'expected_version'    => $doc['version'],
			'expected_state_hash' => $doc['state_hash'],
		)
	);
	$assert( ! is_wp_error( $updated ) && $updated['version'] === $doc['version'] + 1, 'Document CAS revision failed.' );
	$deny = $abilities( $doc_input );
	$assert( is_wp_error( $deny ) && 'workspace_stale' === $deny->get_error_code() &&
		(bool) get_post( $doc['id'] ), 'Stale deletion removed a concurrently updated document.' );
	$doc_input['expected_version']    = $updated['version'];
	$doc_input['expected_state_hash'] = $updated['state_hash'];

	$ok = $abilities( $doc_input );
	$assert( ! is_wp_error( $ok ) && true === $ok['deleted'] && $doc['id'] === $ok['id'], 'Exact doc deletion did not return a verified receipt.' );
	$assert( ! get_post( $doc['id'] ) && ! get_post_meta( $doc['id'], Store::META_STATE, false ), 'Deleted document or private metadata remains visible.' );
	$deny = $abilities( $doc_input );
	$assert( is_wp_error( $deny ), 'Replayed permanent delete returned a misleading success.' );
	$still_reserved = $store->create_document( array(
		'project_ref' => $project_ref,
		'key'         => 'fixture-brief',
		'title'       => 'Dangerous accidental recreate',
		'content'     => 'Should not be created',
	) );
	$assert( is_wp_error( $still_reserved ), 'Durable canonical key claim was incorrectly recycled after deletion.' );

	// A plugin filter may fail while writing Activity AFTER irreversible
	// deletion. It must not mask a verified success and invite a blind retry.
	$break_activity = static function () {
		throw new RuntimeException( 'Synthetic disposable Activity storage failure.' );
	};
	add_filter( 'pre_update_option_wp_ai_bridge_recent_actions', $break_activity, 10, 2 );
	try {
		$ok = $abilities( $task_input );
	} finally {
		remove_filter( 'pre_update_option_wp_ai_bridge_recent_actions', $break_activity, 10 );
	}
	$assert( ! is_wp_error( $ok ) && true === $ok['deleted'] && $task['id'] === $ok['id'], 'Exact task deletion did not return a verified receipt when Activity storage failed.' );
	$assert( ! get_post( $task['id'] ) && ! get_post_meta( $task['id'], Store::META_STATE, false ), 'Deleted task or private metadata remains visible.' );
	$assert( $old_docs === $record_ids( Store::DOCUMENT_POST_TYPE ) &&
		$old_tasks === $record_ids( Store::TASK_POST_TYPE ), 'Original documents/tasks changed or a test fixture remains.' );

	echo "PASS: #126 {$checks} real WordPress guarded exact-delete, CAS, authority and exact cleanup assertions.\n";
} finally {
	$auth->setValue( null, '' );
	if ( null === $before ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $before, false );
	}
}
