<?php
/**
 * Real WordPress Persistent Workspace integration smoke for Issue #8.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Content_Eligibility;
use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

function wpai_issue8_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue8_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue8_assert( $ability instanceof WP_Ability, 'Missing Workspace ability: ' . $name );
	return $ability->execute( $input );
}

$settings                                    = new Settings();
$store                                       = new Store();
$original_settings                           = get_option( Settings::OPTION_NAME, null );
$access                                      = $settings->defaults();
$access[ Settings::GROUP_SITE_READ ]         = 1;
$access[ Settings::GROUP_BUILDER_WRITE ]     = 1;
$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $access, false );

try {
	$document_type = get_post_type_object( Store::DOCUMENT_POST_TYPE );
	$task_type     = get_post_type_object( Store::TASK_POST_TYPE );
	wpai_issue8_assert( $document_type && $task_type, 'Workspace post types are not registered.' );
	foreach ( array( $document_type, $task_type ) as $type ) {
		wpai_issue8_assert( false === (bool) $type->public, 'Workspace post type became public.' );
		wpai_issue8_assert( false === (bool) $type->publicly_queryable, 'Workspace post type became publicly queryable.' );
		wpai_issue8_assert( true === (bool) $type->exclude_from_search, 'Workspace post type is not excluded from search.' );
		wpai_issue8_assert( false === (bool) $type->show_ui, 'Workspace post type leaked into ordinary WordPress editor UI.' );
		wpai_issue8_assert( false === (bool) $type->show_in_rest, 'Workspace storage was exposed through generic REST.' );
		wpai_issue8_assert( false === (bool) $type->show_in_nav_menus, 'Workspace post type leaked into navigation menus.' );
		wpai_issue8_assert( false === (bool) $type->query_var, 'Workspace post type unexpectedly exposes a public query variable.' );
		wpai_issue8_assert( false === (bool) $type->rewrite, 'Workspace post type unexpectedly exposes rewrite rules.' );
		wpai_issue8_assert( false === (bool) $type->has_archive, 'Workspace post type unexpectedly exposes an archive.' );
		wpai_issue8_assert( false === is_post_type_viewable( $type ), 'Workspace post type is unexpectedly front-end viewable.' );
		wpai_issue8_assert( false === post_type_supports( $type->name, 'editor' ), 'Workspace post type unexpectedly supports the normal editor.' );
		wpai_issue8_assert( false === post_type_supports( $type->name, 'revisions' ), 'Workspace correctness unexpectedly depends on WordPress revisions.' );
	}
	wpai_issue8_assert( null === Content_Eligibility::post_type_object( Store::DOCUMENT_POST_TYPE ), 'Workspace document leaked into generic content eligibility.' );
	wpai_issue8_assert( null === Content_Eligibility::post_type_object( Store::TASK_POST_TYPE ), 'Workspace task leaked into generic content eligibility.' );

	$document_create = wpai_issue8_execute(
		'wp-ai-bridge/workspace-document',
		array(
			'action'  => 'create',
			'key'     => 'project-brief',
			'title'   => 'Project Brief',
			'content' => "# Project Brief\n\nDurable orientation, not chat history.",
		)
	);
	wpai_issue8_assert( ! is_wp_error( $document_create ), 'Workspace document create failed.' );
	$document = $document_create['items'][0];
	wpai_issue8_assert( 1 === $document['version'] && 64 === strlen( $document['state_hash'] ), 'Workspace document did not expose version/state hash.' );
	wpai_issue8_assert( 0 === count( wp_get_post_revisions( $document['id'] ) ), 'Workspace document unexpectedly created WordPress revisions.' );

	$generic_read = wpai_issue8_execute(
		'wp-ai-bridge/content-read',
		array(
			'action' => 'get',
			'id'     => $document['id'],
		)
	);
	wpai_issue8_assert( is_wp_error( $generic_read ), 'Generic content-read exposed Workspace internal storage.' );
	$generic_blocks = wpai_issue8_execute( 'wp-ai-bridge/blocks-read', array( 'post_id' => $document['id'] ) );
	wpai_issue8_assert( is_wp_error( $generic_blocks ), 'Generic block-read exposed Workspace internal storage.' );
	$generic_create = wpai_issue8_execute(
		'wp-ai-bridge/content-upsert',
		array(
			'action'    => 'create',
			'post_type' => Store::DOCUMENT_POST_TYPE,
			'title'     => 'Must not exist',
			'status'    => 'draft',
		)
	);
	wpai_issue8_assert( is_wp_error( $generic_create ), 'Generic content-upsert created a Workspace internal record.' );

	$document_update = wpai_issue8_execute(
		'wp-ai-bridge/workspace-document',
		array(
			'action'              => 'update',
			'id'                  => $document['id'],
			'content'             => "# Project Brief\n\nCurrent durable project orientation.",
			'expected_version'    => $document['version'],
			'expected_state_hash' => $document['state_hash'],
		)
	);
	wpai_issue8_assert( ! is_wp_error( $document_update ), 'Workspace document update failed.' );
	$updated_document = $document_update['items'][0];
	wpai_issue8_assert( 2 === $updated_document['version'], 'Workspace document version did not advance monotonically.' );

	$stale_document = wpai_issue8_execute(
		'wp-ai-bridge/workspace-document',
		array(
			'action'              => 'update',
			'id'                  => $document['id'],
			'title'               => 'Stale overwrite must fail',
			'expected_version'    => $document['version'],
			'expected_state_hash' => $document['state_hash'],
		)
	);
	wpai_issue8_assert( is_wp_error( $stale_document ) && 'workspace_stale' === $stale_document->get_error_code(), 'Stale Workspace document write was not rejected deterministically.' );

	$task_create = wpai_issue8_execute(
		'wp-ai-bridge/workspace-task',
		array(
			'action'       => 'create',
			'title'        => 'Build navigation',
			'goal'         => 'Finish the primary site navigation.',
			'acceptance'   => array( 'Desktop and mobile navigation is present.', 'Existing unrelated menu items are preserved.' ),
			'dependencies' => array( 'Project Brief' ),
			'target_refs'  => array( 'primary-menu' ),
			'progress'     => 'todo',
			'review'       => 'pending',
			'delivery'     => 'draft_preview',
			'notes'        => 'Keep this concise.',
		)
	);
	wpai_issue8_assert( ! is_wp_error( $task_create ), 'Workspace task create failed.' );
	$task = $task_create['items'][0];
	wpai_issue8_assert( 'todo' === $task['progress'] && 'pending' === $task['review'] && 'draft_preview' === $task['delivery'], 'Independent Workspace task states were not preserved.' );
	wpai_issue8_assert( 0 === count( wp_get_post_revisions( $task['id'] ) ), 'Workspace task unexpectedly created WordPress revisions.' );

	$task_transition = wpai_issue8_execute(
		'wp-ai-bridge/workspace-task',
		array(
			'action'              => 'transition',
			'id'                  => $task['id'],
			'progress'            => 'in_progress',
			'review'              => 'not_required',
			'expected_version'    => $task['version'],
			'expected_state_hash' => $task['state_hash'],
		)
	);
	wpai_issue8_assert( ! is_wp_error( $task_transition ), 'Workspace task transition failed.' );
	$task_progress = $task_transition['items'][0];
	wpai_issue8_assert( 'in_progress' === $task_progress['progress'] && 'not_required' === $task_progress['review'] && 'draft_preview' === $task_progress['delivery'], 'Task transition did not keep progress/review/delivery independent.' );

	$stale_task = wpai_issue8_execute(
		'wp-ai-bridge/workspace-task',
		array(
			'action'              => 'update',
			'id'                  => $task['id'],
			'notes'               => 'Stale write',
			'expected_version'    => $task['version'],
			'expected_state_hash' => $task['state_hash'],
		)
	);
	wpai_issue8_assert( is_wp_error( $stale_task ) && 'workspace_stale' === $stale_task->get_error_code(), 'Stale Workspace task write was not rejected.' );

	$resume = wpai_issue8_execute( 'wp-ai-bridge/workspace-resume' );
	wpai_issue8_assert( ! is_wp_error( $resume ), 'Workspace resume failed.' );
	wpai_issue8_assert( 'Build navigation' === $resume['current_focus'], 'Workspace resume did not surface the current in-progress task.' );
	wpai_issue8_assert( 1 === $resume['counts']['documents'] && 1 === $resume['counts']['tasks'], 'Workspace resume counts are incorrect.' );
	wpai_issue8_assert( ! array_key_exists( 'notes', $resume['active_tasks'][0] ), 'Workspace resume leaked full task notes instead of compact orientation.' );
	wpai_issue8_assert( ! array_key_exists( 'content', $resume['documents'][0] ), 'Workspace resume dumped document content instead of an index.' );

	// Pruning revisions cannot affect current state or stale identity because Workspace
	// post types do not opt into the revisions feature and state lives in one CAS meta payload.
	foreach ( array_merge( wp_get_post_revisions( $document['id'] ), wp_get_post_revisions( $task['id'] ) ) as $revision ) {
		wp_delete_post_revision( $revision->ID );
	}
	$after_prune = $store->get_document( $document['id'] );
	wpai_issue8_assert( ! is_wp_error( $after_prune ) && $updated_document['state_hash'] === $after_prune['state_hash'], 'Workspace state changed after revision pruning.' );

	$export = $store->export_snapshot();
	wpai_issue8_assert( 'wp-ai-bridge-workspace-v1' === $export['format'], 'Workspace export format identifier is missing.' );
	wpai_issue8_assert( 1 === count( $export['documents'] ) && 1 === count( $export['tasks'] ), 'Workspace export omitted current records.' );

	// Permission gates remain separate from storage correctness.
	$denied_read                              = $access;
	$denied_read[ Settings::GROUP_SITE_READ ] = 0;
	update_option( Settings::OPTION_NAME, $denied_read, false );
	$read_result = wpai_issue8_execute( 'wp-ai-bridge/workspace-resume' );
	wpai_issue8_assert( is_wp_error( $read_result ), 'Workspace resume bypassed disabled Site Read.' );

	$denied_write                                  = $access;
	$denied_write[ Settings::GROUP_BUILDER_WRITE ] = 0;
	update_option( Settings::OPTION_NAME, $denied_write, false );
	$write_result = wpai_issue8_execute(
		'wp-ai-bridge/workspace-document',
		array(
			'action' => 'create',
			'title'  => 'Denied',
		)
	);
	wpai_issue8_assert( is_wp_error( $write_result ), 'Workspace document write bypassed disabled Builder Write.' );
	update_option( Settings::OPTION_NAME, $access, false );

	// Exercise real WordPress admin menu registration without exposing internal CPTs.
	if ( ! function_exists( 'add_menu_page' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	global $menu, $submenu;
	$menu    = array();
	$submenu = array();
	$page    = new Settings_Page( new Environment(), $settings, new OAuth_Server(), $store, new Mutation_Log() );
	$page->register_menu();
	wpai_issue8_assert( isset( $submenu[ Settings_Page::PAGE_SLUG ] ), 'WP AI Bridge top-level menu did not register.' );
	$submenu_slugs = array_column( $submenu[ Settings_Page::PAGE_SLUG ], 2 );
	foreach ( array( Settings_Page::PAGE_SLUG, Settings_Page::DOCUMENTS_SLUG, Settings_Page::TASKS_SLUG, Settings_Page::ACTIVITY_SLUG, Settings_Page::SETTINGS_SLUG ) as $slug ) {
		wpai_issue8_assert( in_array( $slug, $submenu_slugs, true ), 'Missing WP AI Bridge admin submenu: ' . $slug );
	}
	wpai_issue8_assert( 'Dashboard' === $submenu[ Settings_Page::PAGE_SLUG ][0][0], 'WP AI Bridge parent submenu is not labelled Dashboard.' );

	$screen_checks = array(
		'render_dashboard' => 'Workspace overview',
		'render_documents' => 'Project Brief',
		'render_tasks'     => 'Build navigation',
		'render_activity'  => 'workspace-task',
	);
	foreach ( $screen_checks as $method => $needle ) {
		ob_start();
		$page->{$method}();
		$screen_html = ob_get_clean();
		wpai_issue8_assert( false !== strpos( $screen_html, $needle ), 'Admin screen did not render expected content: ' . $method );
		if ( 'render_tasks' === $method ) {
			wpai_issue8_assert( false !== strpos( $screen_html, 'style="margin: 16px 0 18px;"' ), 'Task filters should retain vertical spacing from the description and results table.' );
		}
	}

	ob_start();
	$page->render_settings();
	$settings_html = ob_get_clean();
	wpai_issue8_assert( false !== strpos( $settings_html, 'wpai_workspace_export' ), 'Admin Settings did not render Workspace export.' );
	wpai_issue8_assert( false !== strpos( $settings_html, 'wpai_workspace_clear' ), 'Admin Settings did not render explicit Workspace clear.' );

	$cleared = $store->clear();
	wpai_issue8_assert( ! is_wp_error( $cleared ) && 1 === $cleared['documents'] && 1 === $cleared['tasks'], 'Explicit Workspace clear did not remove both fixture records.' );
	$empty_resume = $store->resume();
	wpai_issue8_assert( 0 === $empty_resume['counts']['documents'] && 0 === $empty_resume['counts']['tasks'], 'Workspace was not empty after explicit clear.' );

	echo "PASS: Issue #8 Persistent Workspace storage, isolation, concurrency, permissions, admin UX, export, and clear.\n";
} finally {
	$store->clear();
	if ( null === $original_settings ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original_settings, false );
	}
}
