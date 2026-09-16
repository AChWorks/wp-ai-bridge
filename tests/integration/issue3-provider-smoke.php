<?php
/**
 * Real WordPress smoke coverage for Issue #3 media, taxonomy, and navigation providers.
 *
 * Run with:
 * wp eval-file tests/integration/issue3-provider-smoke.php --user=<administrator>
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai_issue3_provider_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue3_provider_execute( $name, array $input ) {
	$ability = wp_get_ability( $name );
	wpai_issue3_provider_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

$settings = new Settings();
$access   = $settings->defaults();
$access[ Settings::GROUP_SITE_READ ]         = 1;
$access[ Settings::GROUP_BUILDER_WRITE ]     = 1;
$access[ Settings::GROUP_LIVE_CONTENT ]      = 0;
$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
update_option( Settings::OPTION_NAME, $access, false );

$attachment_id = 0;
$term_id       = 0;
$draft_id      = 0;
$published_id  = 0;
$menu_id       = 0;
$item_one      = 0;
$item_two      = 0;
$suffix        = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );

try {
	// Media uses supplied bytes and WordPress upload/MIME handling, never a client server path.
	$media_upload_ability = wp_get_ability( 'wp-ai-bridge/media-upload' );
	wpai_issue3_provider_assert( $media_upload_ability instanceof WP_Ability, 'media-upload is not registered.' );
	$media_schema = $media_upload_ability->get_input_schema();
	wpai_issue3_provider_assert( isset( $media_schema['properties']['content_base64'] ), 'media-upload does not expose its bounded byte transport.' );
	wpai_issue3_provider_assert( ! isset( $media_schema['properties']['path'] ) && ! isset( $media_schema['properties']['server_path'] ), 'media-upload accepts a client-supplied server path.' );

	$media = $media_upload_ability->execute(
		array(
			'filename'       => 'wpai-issue3-' . $suffix . '.png',
			'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
			'title'          => 'WPNB Issue 3 Provider Smoke',
			'alt_text'       => 'Initial smoke alt',
		)
	);
	wpai_issue3_provider_assert( ! is_wp_error( $media ) && ! empty( $media['id'] ), 'Media upload failed.' );
	$attachment_id = (int) $media['id'];

	$media_update = wpai_issue3_provider_execute(
		'wp-ai-bridge/media-update',
		array(
			'id'       => $attachment_id,
			'caption'  => 'Updated provider smoke caption',
			'alt_text' => 'Updated provider smoke alt',
		)
	);
	wpai_issue3_provider_assert( ! is_wp_error( $media_update ), 'Media metadata update failed.' );
	$media_read = wpai_issue3_provider_execute( 'wp-ai-bridge/media-read', array( 'action' => 'get', 'id' => $attachment_id ) );
	wpai_issue3_provider_assert( ! is_wp_error( $media_read ) && 'Updated provider smoke alt' === $media_read['items'][0]['alt_text'], 'Media read did not return the updated metadata.' );
	$media_delete_denied = wpai_issue3_provider_execute( 'wp-ai-bridge/media-delete', array( 'id' => $attachment_id ) );
	wpai_issue3_provider_assert( is_wp_error( $media_delete_denied ) && get_post( $attachment_id ), 'Media deletion bypassed Users & Destructive.' );

	// Generic taxonomy create/read/assignment works; live assignment and deletion retain their gates.
	$term = wpai_issue3_provider_execute(
		'wp-ai-bridge/term-upsert',
		array(
			'action'      => 'create',
			'taxonomy'    => 'category',
			'name'        => 'WPNB Issue 3 ' . $suffix,
			'description' => 'Temporary Issue #3 integration term.',
		)
	);
	wpai_issue3_provider_assert( ! is_wp_error( $term ) && ! empty( $term['term_id'] ), 'Taxonomy term create failed.' );
	$term_id = (int) $term['term_id'];
	$term_read = wpai_issue3_provider_execute( 'wp-ai-bridge/terms-read', array( 'action' => 'get', 'taxonomy' => 'category', 'term_id' => $term_id ) );
	wpai_issue3_provider_assert( ! is_wp_error( $term_read ) && $term_id === (int) $term_read['items'][0]['term_id'], 'Taxonomy term read failed.' );

	$draft_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => 'WPNB taxonomy draft ' . $suffix,
			'post_content' => 'Temporary taxonomy assignment fixture.',
		),
		true
	);
	wpai_issue3_provider_assert( ! is_wp_error( $draft_id ), 'Could not create draft taxonomy fixture.' );
	$draft_id = (int) $draft_id;
	$assigned = wpai_issue3_provider_execute(
		'wp-ai-bridge/terms-assign',
		array(
			'post_id'  => $draft_id,
			'taxonomy' => 'category',
			'term_ids' => array( $term_id ),
		)
	);
	wpai_issue3_provider_assert( ! is_wp_error( $assigned ) && in_array( $term_id, $assigned['term_ids'], true ), 'Draft taxonomy assignment failed.' );

	$published_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'WPNB taxonomy live ' . $suffix,
			'post_content' => 'Temporary live taxonomy assignment fixture.',
		),
		true
	);
	wpai_issue3_provider_assert( ! is_wp_error( $published_id ), 'Could not create published taxonomy fixture.' );
	$published_id = (int) $published_id;
	$live_assign_denied = wpai_issue3_provider_execute(
		'wp-ai-bridge/terms-assign',
		array(
			'post_id'  => $published_id,
			'taxonomy' => 'category',
			'term_ids' => array( $term_id ),
		)
	);
	wpai_issue3_provider_assert( is_wp_error( $live_assign_denied ) && ! has_category( $term_id, $published_id ), 'Published taxonomy assignment bypassed Live Content.' );
	$term_delete_denied = wpai_issue3_provider_execute( 'wp-ai-bridge/term-delete', array( 'taxonomy' => 'category', 'term_id' => $term_id ) );
	wpai_issue3_provider_assert( is_wp_error( $term_delete_denied ) && term_exists( $term_id, 'category' ), 'Taxonomy deletion bypassed Users & Destructive.' );

	// Dedicated classic navigation create/update/reorder stays in Builder Write; removal additionally needs destructive access.
	$nav_ability = wp_get_ability( 'wp-ai-bridge/classic-navigation-mutate' );
	wpai_issue3_provider_assert( $nav_ability instanceof WP_Ability, 'classic-navigation-mutate is not registered.' );
	$nav_meta = $nav_ability->get_meta();
	wpai_issue3_provider_assert( ! empty( $nav_meta['annotations']['destructive'] ), 'Classic navigation ability does not accurately annotate its permanent removal path.' );
	$menu = $nav_ability->execute( array( 'action' => 'create_menu', 'name' => 'WPNB Issue 3 ' . $suffix ) );
	wpai_issue3_provider_assert( ! is_wp_error( $menu ) && ! empty( $menu['menu_id'] ), 'Classic navigation menu create failed under Builder Write.' );
	$menu_id = (int) $menu['menu_id'];

	$first = $nav_ability->execute(
		array(
			'action'    => 'upsert_item',
			'menu_id'   => $menu_id,
			'item_type' => 'custom',
			'title'     => 'First',
			'url'       => 'https://example.test/first/',
			'position'  => 1,
		)
	);
	wpai_issue3_provider_assert( ! is_wp_error( $first ) && ! empty( $first['item_id'] ), 'First classic navigation item create failed.' );
	$item_one = (int) $first['item_id'];
	$second = $nav_ability->execute(
		array(
			'action'    => 'upsert_item',
			'menu_id'   => $menu_id,
			'item_type' => 'custom',
			'title'     => 'Second',
			'url'       => 'https://example.test/second/',
			'position'  => 2,
		)
	);
	wpai_issue3_provider_assert( ! is_wp_error( $second ) && ! empty( $second['item_id'] ), 'Second classic navigation item create failed.' );
	$item_two = (int) $second['item_id'];

	$move_second = $nav_ability->execute( array( 'action' => 'upsert_item', 'menu_id' => $menu_id, 'item_id' => $item_two, 'position' => 1 ) );
	$move_first  = $nav_ability->execute( array( 'action' => 'upsert_item', 'menu_id' => $menu_id, 'item_id' => $item_one, 'position' => 2 ) );
	wpai_issue3_provider_assert( ! is_wp_error( $move_second ) && ! is_wp_error( $move_first ), 'Classic navigation reorder failed under Builder Write.' );
	$nav_read = wpai_issue3_provider_execute( 'wp-ai-bridge/navigation-read', array() );
	$found_menu = null;
	foreach ( $nav_read['classic_menus'] as $candidate_menu ) {
		if ( $menu_id === (int) $candidate_menu['menu_id'] ) {
			$found_menu = $candidate_menu;
			break;
		}
	}
	wpai_issue3_provider_assert( is_array( $found_menu ) && 2 === count( $found_menu['items'] ), 'Navigation read did not return the created menu items.' );
	wpai_issue3_provider_assert( $item_two === (int) $found_menu['items'][0]['item_id'], 'Navigation reorder did not move the second item to the first position.' );

	$remove_denied = $nav_ability->execute( array( 'action' => 'remove_item', 'item_id' => $item_one ) );
	wpai_issue3_provider_assert( is_wp_error( $remove_denied ) && is_nav_menu_item( $item_one ), 'Permanent menu-item removal bypassed Users & Destructive.' );

	// Enable the destructive group and prove each isolated destructive path now succeeds.
	$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );
	$removed_one = $nav_ability->execute( array( 'action' => 'remove_item', 'item_id' => $item_one ) );
	wpai_issue3_provider_assert( ! is_wp_error( $removed_one ) && ! empty( $removed_one['removed'] ) && ! is_nav_menu_item( $item_one ), 'Menu-item removal failed after destructive access was enabled.' );
	$item_one = 0;
	$removed_two = $nav_ability->execute( array( 'action' => 'remove_item', 'item_id' => $item_two ) );
	wpai_issue3_provider_assert( ! is_wp_error( $removed_two ) && ! empty( $removed_two['removed'] ), 'Second menu-item removal failed after destructive access was enabled.' );
	$item_two = 0;

	$deleted_term = wpai_issue3_provider_execute( 'wp-ai-bridge/term-delete', array( 'taxonomy' => 'category', 'term_id' => $term_id ) );
	wpai_issue3_provider_assert( ! is_wp_error( $deleted_term ) && ! empty( $deleted_term['deleted'] ), 'Taxonomy delete failed after destructive access was enabled.' );
	$term_id = 0;
	$deleted_media = wpai_issue3_provider_execute( 'wp-ai-bridge/media-delete', array( 'id' => $attachment_id ) );
	wpai_issue3_provider_assert( ! is_wp_error( $deleted_media ) && ! empty( $deleted_media['deleted'] ), 'Media delete failed after destructive access was enabled.' );
	$attachment_id = 0;

	echo "PASS: Issue #3 media/taxonomy/navigation provider smoke.\n";
} finally {
	if ( $item_one > 0 ) {
		wp_delete_post( $item_one, true );
	}
	if ( $item_two > 0 ) {
		wp_delete_post( $item_two, true );
	}
	if ( $menu_id > 0 ) {
		wp_delete_nav_menu( $menu_id );
	}
	if ( $term_id > 0 ) {
		wp_delete_term( $term_id, 'category' );
	}
	if ( $attachment_id > 0 ) {
		wp_delete_attachment( $attachment_id, true );
	}
	if ( $draft_id > 0 ) {
		wp_delete_post( $draft_id, true );
	}
	if ( $published_id > 0 ) {
		wp_delete_post( $published_id, true );
	}
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
}
