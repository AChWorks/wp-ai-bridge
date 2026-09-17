<?php
/**
 * Real WordPress smoke coverage for Issue #3 content/block abilities.
 *
 * Run with:
 * wp eval-file tests/integration/issue3-content-block-smoke.php --user=<administrator>
 */

use WP_AI_Bridge\Support\Settings;

function wpai_issue3_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue3_error_code( $value ) {
	return is_wp_error( $value ) ? $value->get_error_code() : '';
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	throw new RuntimeException( 'Abilities API is unavailable.' );
}

$required = array(
	'wp-ai-bridge/bridge-info',
	'wp-ai-bridge/site-context',
	'wp-ai-bridge/content-upsert',
	'wp-ai-bridge/content-delete',
	'wp-ai-bridge/revisions-read',
	'wp-ai-bridge/revision-restore',
	'wp-ai-bridge/blocks-read',
	'wp-ai-bridge/blocks-mutate',
);
foreach ( $required as $name ) {
	wpai_issue3_assert( (bool) wp_get_ability( $name ), 'Missing registered ability: ' . $name );
}
wpai_issue3_assert( (bool) wp_get_ability( 'wp-ai-bridge/content-read' ), 'WordPress 6.9 baseline should register the Bridge content-read fallback.' );

echo "ISSUE3_REGISTRATION_OK\n";

register_post_type(
	'wpai_book',
	array(
		'label'        => 'WP Native Builder Books',
		'public'       => false,
		'show_ui'      => true,
		'show_in_rest' => true,
		'supports'     => array( 'title', 'editor', 'excerpt', 'revisions', 'thumbnail' ),
		'map_meta_cap' => true,
	)
);
register_post_status(
	'wpai_live',
	array(
		'label'  => 'WP Native Builder Live',
		'public' => true,
	)
);

$settings = new Settings();
$access   = $settings->defaults();
$access[ Settings::GROUP_SITE_READ ]     = 1;
$access[ Settings::GROUP_BUILDER_WRITE ] = 1;
$access[ Settings::GROUP_LIVE_CONTENT ]  = 0;
$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
update_option( Settings::OPTION_NAME, $access, false );

$upsert       = wp_get_ability( 'wp-ai-bridge/content-upsert' );
$content_read = wp_get_ability( 'wp-ai-bridge/content-read' );
$blocks_read  = wp_get_ability( 'wp-ai-bridge/blocks-read' );
$blocks_mutate = wp_get_ability( 'wp-ai-bridge/blocks-mutate' );
$revisions_read = wp_get_ability( 'wp-ai-bridge/revisions-read' );
$revision_restore = wp_get_ability( 'wp-ai-bridge/revision-restore' );
$content_delete = wp_get_ability( 'wp-ai-bridge/content-delete' );

$initial_markup = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>One</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:paragraph --><p>Outside</p><!-- /wp:paragraph -->';
$created = $upsert->execute(
	array(
		'action'    => 'create',
		'post_type' => 'wpai_book',
		'title'     => 'Issue 3 Draft',
		'content'   => $initial_markup,
		'status'    => 'draft',
	)
);
wpai_issue3_assert( ! is_wp_error( $created ), 'Draft create failed: ' . wpai_issue3_error_code( $created ) );
wpai_issue3_assert( 'wpai_book' === $created['post_type'], 'Generic CPT create returned the wrong post type.' );
wpai_issue3_assert( 'draft' === $created['status'], 'Draft create returned the wrong status.' );
wpai_issue3_assert( isset( $created['state_hash'] ) && 64 === strlen( $created['state_hash'] ), 'Content create did not expose the full mutation state fingerprint.' );
$post_id = (int) $created['id'];

$read = $content_read->execute(
	array(
		'action'    => 'get',
		'post_type' => 'wpai_book',
		'id'        => $post_id,
	)
);
wpai_issue3_assert( ! is_wp_error( $read ) && 1 === count( $read['items'] ), 'Content fallback could not read the generic CPT.' );
wpai_issue3_assert( false !== strpos( $read['items'][0]['content'], 'Outside' ), 'Content fallback did not return the saved block content.' );

$tree = $blocks_read->execute( array( 'post_id' => $post_id ) );
wpai_issue3_assert( ! is_wp_error( $tree ), 'Block read failed: ' . wpai_issue3_error_code( $tree ) );
wpai_issue3_assert( 'core/group' === $tree['blocks'][0]['name'], 'Expected a group at block path 0.' );
wpai_issue3_assert( '0.0' === $tree['blocks'][0]['inner_blocks'][0]['path'], 'Nested block path 0.0 was not generated.' );
wpai_issue3_assert( '0.1' === $tree['blocks'][0]['inner_blocks'][1]['path'], 'Nested block path 0.1 was not generated.' );
wpai_issue3_assert( '1' === $tree['blocks'][1]['path'], 'Root sibling path 1 was not generated.' );

$initial_identity = $tree;
$replace = $blocks_mutate->execute(
	array(
		'post_id'               => $post_id,
		'action'                => 'replace',
		'path'                  => '0.0',
		'block_markup'          => '<!-- wp:heading --><h2 class="wp-block-heading">Changed</h2><!-- /wp:heading -->',
		'expected_modified_gmt' => $tree['modified_gmt'],
		'expected_content_hash' => $tree['content_hash'],
		'expected_block_hash'   => $tree['blocks'][0]['inner_blocks'][0]['block_hash'],
	)
);
wpai_issue3_assert( ! is_wp_error( $replace ), 'Nested block replace failed: ' . wpai_issue3_error_code( $replace ) );
$current_content = (string) get_post( $post_id )->post_content;
wpai_issue3_assert( false !== strpos( $current_content, 'Changed' ), 'Nested replace did not write the replacement block.' );
wpai_issue3_assert( false !== strpos( $current_content, '>Two<' ), 'Nested replace changed the unrelated sibling block.' );
wpai_issue3_assert( false !== strpos( $current_content, '>Outside<' ), 'Nested replace changed the unrelated root block.' );

$stale = $blocks_mutate->execute(
	array(
		'post_id'               => $post_id,
		'action'                => 'remove',
		'path'                  => '1',
		'expected_modified_gmt' => $initial_identity['modified_gmt'],
		'expected_content_hash' => $initial_identity['content_hash'],
		'expected_block_hash'   => $initial_identity['blocks'][1]['block_hash'],
	)
);
wpai_issue3_assert( is_wp_error( $stale ) && 'stale_content_conflict' === $stale->get_error_code(), 'Stale block mutation was not rejected by object identity.' );

$tree = $blocks_read->execute( array( 'post_id' => $post_id ) );
$insert = $blocks_mutate->execute(
	array(
		'post_id'               => $post_id,
		'action'                => 'insert_after',
		'path'                  => '0.0',
		'block_markup'          => '<!-- wp:paragraph --><p>Inserted</p><!-- /wp:paragraph -->',
		'expected_modified_gmt' => $tree['modified_gmt'],
		'expected_content_hash' => $tree['content_hash'],
		'expected_block_hash'   => $tree['blocks'][0]['inner_blocks'][0]['block_hash'],
	)
);
wpai_issue3_assert( ! is_wp_error( $insert ), 'Nested block insert failed: ' . wpai_issue3_error_code( $insert ) );
$current_content = (string) get_post( $post_id )->post_content;
wpai_issue3_assert( false !== strpos( $current_content, '>Changed<' ), 'Nested insert damaged the reference block.' );
wpai_issue3_assert( false !== strpos( $current_content, '>Inserted<' ), 'Nested insert did not serialize the inserted block.' );
wpai_issue3_assert( false !== strpos( $current_content, '>Two<' ), 'Nested insert damaged the following sibling.' );
wpai_issue3_assert( false !== strpos( $current_content, '>Outside<' ), 'Nested insert damaged unrelated root content.' );

$stale_upsert = $upsert->execute(
	array(
		'action'                => 'update',
		'id'                    => $post_id,
		'title'                 => 'Should Not Apply',
		'expected_modified_gmt' => $created['modified_gmt'],
		'expected_state_hash'   => $created['state_hash'],
	)
);
wpai_issue3_assert( is_wp_error( $stale_upsert ) && 'stale_content_conflict' === $stale_upsert->get_error_code(), 'Content update did not reject a stale content hash.' );
wpai_issue3_assert( 'Issue 3 Draft' === get_post( $post_id )->post_title, 'Stale content update changed the post title.' );

$fresh = $content_read->execute( array( 'action' => 'get', 'post_type' => 'wpai_book', 'id' => $post_id ) );
$fresh_item = $fresh['items'][0];
$updated = $upsert->execute(
	array(
		'action'                => 'update',
		'id'                    => $post_id,
		'title'                 => 'Issue 3 Updated',
		'expected_modified_gmt' => $fresh_item['modified_gmt'],
		'expected_state_hash'   => $fresh_item['state_hash'],
	)
);
wpai_issue3_assert( ! is_wp_error( $updated ) && 'Issue 3 Updated' === $updated['title'], 'Fresh content update failed.' );

$custom_live_denied = $upsert->execute(
	array(
		'action'    => 'create',
		'post_type' => 'wpai_book',
		'title'     => 'Custom Live Must Be Gated',
		'status'    => 'wpai_live',
	)
);
wpai_issue3_assert( is_wp_error( $custom_live_denied ), 'A custom live status bypassed the Live Content group.' );

$fresh = $content_read->execute( array( 'action' => 'get', 'post_type' => 'wpai_book', 'id' => $post_id ) );
$fresh_item = $fresh['items'][0];
$publish_denied = $upsert->execute(
	array(
		'action'                => 'update',
		'id'                    => $post_id,
		'status'                => 'publish',
		'expected_modified_gmt' => $fresh_item['modified_gmt'],
		'expected_state_hash'   => $fresh_item['state_hash'],
	)
);
wpai_issue3_assert( is_wp_error( $publish_denied ), 'Publishing bypassed the disabled Live Content group.' );

$access[ Settings::GROUP_LIVE_CONTENT ] = 1;
update_option( Settings::OPTION_NAME, $access, false );
$published = $upsert->execute(
	array(
		'action'                => 'update',
		'id'                    => $post_id,
		'status'                => 'publish',
		'expected_modified_gmt' => $fresh_item['modified_gmt'],
		'expected_state_hash'   => $fresh_item['state_hash'],
	)
);
wpai_issue3_assert( ! is_wp_error( $published ) && 'publish' === $published['status'], 'Publishing failed after Live Content was enabled: ' . wpai_issue3_error_code( $published ) . ( is_wp_error( $published ) ? ' / ' . $published->get_error_message() : '' ) );

$access[ Settings::GROUP_LIVE_CONTENT ] = 0;
update_option( Settings::OPTION_NAME, $access, false );
$published_tree = $blocks_read->execute( array( 'post_id' => $post_id ) );
$live_block_denied = $blocks_mutate->execute(
	array(
		'post_id'               => $post_id,
		'action'                => 'append',
		'block_markup'          => '<!-- wp:paragraph --><p>Must Not Apply</p><!-- /wp:paragraph -->',
		'expected_modified_gmt' => $published_tree['modified_gmt'],
		'expected_content_hash' => $published_tree['content_hash'],
	)
);
wpai_issue3_assert( is_wp_error( $live_block_denied ), 'Published block mutation bypassed the disabled Live Content group.' );
wpai_issue3_assert( false === strpos( get_post( $post_id )->post_content, 'Must Not Apply' ), 'Denied published block mutation changed content.' );

$access[ Settings::GROUP_LIVE_CONTENT ] = 1;
update_option( Settings::OPTION_NAME, $access, false );
$revisions = $revisions_read->execute( array( 'post_id' => $post_id, 'limit' => 20, 'include_content' => true ) );
wpai_issue3_assert( ! is_wp_error( $revisions ) && count( $revisions ) > 0, 'Expected WordPress revisions after content mutations.' );

$current = $content_read->execute( array( 'action' => 'get', 'post_type' => 'wpai_book', 'id' => $post_id ) );
$current_item = $current['items'][0];
$restored = $revision_restore->execute(
	array(
		'post_id'               => $post_id,
		'revision_id'           => (int) $revisions[0]['id'],
		'expected_modified_gmt' => $current_item['modified_gmt'],
		'expected_state_hash'   => $current_item['state_hash'],
	)
);
wpai_issue3_assert( ! is_wp_error( $restored ), 'Revision restore failed: ' . wpai_issue3_error_code( $restored ) );

$delete_denied = $content_delete->execute( array( 'id' => $post_id, 'force' => false ) );
wpai_issue3_assert( is_wp_error( $delete_denied ), 'Content deletion bypassed the disabled Users & Destructive group.' );

$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $access, false );
$trashed = $content_delete->execute( array( 'id' => $post_id, 'force' => false ) );
wpai_issue3_assert( ! is_wp_error( $trashed ) && ! empty( $trashed['trashed'] ), 'Content trash failed after destructive access was enabled.' );
$deleted = $content_delete->execute( array( 'id' => $post_id, 'force' => true ) );
wpai_issue3_assert( ! is_wp_error( $deleted ) && ! empty( $deleted['deleted'] ), 'Permanent content deletion failed after destructive access was enabled.' );

update_option( Settings::OPTION_NAME, $settings->defaults(), false );
unregister_post_type( 'wpai_book' );

$actions = get_option( 'wp_ai_bridge_recent_actions', array() );
wpai_issue3_assert( is_array( $actions ) && count( $actions ) > 0, 'Expected bounded mutation log entries from Issue #3 operations.' );

printf( "ISSUE3_CONTENT_BLOCK_OK post=%d revisions=%d log=%d\n", $post_id, count( $revisions ), count( $actions ) );
