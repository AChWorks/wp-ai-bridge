<?php
/** Real WordPress Issue #52 bounded comment administration smoke. */

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue52_live_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings          = new Settings();
$original_settings = get_option( Settings::OPTION_NAME, array() );
$original_log      = get_option( Mutation_Log::OPTION_NAME, array() );
$original_user     = get_current_user_id();
$created_comments  = array();
$created_posts     = array();
$created_users     = array();

try {
	wpnb_issue52_live_assert( $original_user > 0, 'Run Issue #52 smoke as an authenticated administrator.' );
	$effective = $settings->all();
	wpnb_issue52_live_assert( 0 === $effective[ Settings::GROUP_COMMENTS ], 'Comments must default off on a fresh install.' );

	update_option(
		Settings::OPTION_NAME,
		array(
			Settings::GROUP_SITE_READ     => 1,
			Settings::GROUP_BUILDER_WRITE => 1,
		),
		false
	);
	$upgrade = $settings->all();
	wpnb_issue52_live_assert( 1 === $upgrade[ Settings::GROUP_SITE_READ ], 'Historical Site Read consent changed during upgrade simulation.' );
	wpnb_issue52_live_assert( 1 === $upgrade[ Settings::GROUP_BUILDER_WRITE ], 'Historical Builder Write consent changed during upgrade simulation.' );
	wpnb_issue52_live_assert( 0 === $upgrade[ Settings::GROUP_COMMENTS ], 'Historical grants silently enabled Comments.' );

	$read   = wp_get_ability( 'wp-native-builder/comments-read' );
	$reply  = wp_get_ability( 'wp-native-builder/comment-reply' );
	$status = wp_get_ability( 'wp-native-builder/comment-status' );
	$delete = wp_get_ability( 'wp-native-builder/comment-delete' );
	foreach ( array( $read, $reply, $status, $delete ) as $ability ) {
		wpnb_issue52_live_assert( $ability instanceof WP_Ability, 'Issue #52 Bridge Ability was not registered.' );
	}
	$read_schema = $read->get_input_schema();
	wpnb_issue52_live_assert( ! isset( $read_schema['properties']['search'], $read_schema['properties']['route'], $read_schema['properties']['method'] ), 'Comment read schema exposed broad search or generic REST dispatch controls.' );

	$post_id = wp_insert_post(
		array(
			'post_title'     => 'Issue 52 comments fixture',
			'post_content'   => 'Fixture',
			'post_status'    => 'publish',
			'post_type'      => 'post',
			'comment_status' => 'open',
		),
		true
	);
	wpnb_issue52_live_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Issue #52 fixture post could not be created.' );
	$created_posts[] = (int) $post_id;

	$approved_id = wp_insert_comment(
		array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => 'Approved Author',
			'comment_author_email' => 'approved@example.invalid',
			'comment_author_IP'    => '192.0.2.11',
			'comment_agent'        => 'private-approved-agent',
			'comment_content'      => str_repeat( 'A', 5000 ),
			'comment_approved'     => 1,
			'comment_type'         => '',
			'user_id'              => 0,
		)
	);
	$pending_id = wp_insert_comment(
		array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => 'Pending Author',
			'comment_author_email' => 'pending@example.invalid',
			'comment_author_IP'    => '192.0.2.12',
			'comment_agent'        => 'private-pending-agent',
			'comment_content'      => 'Pending fixture',
			'comment_approved'     => 0,
			'comment_type'         => '',
			'user_id'              => 0,
		)
	);
	$note_id = wp_insert_comment(
		array(
			'comment_post_ID'  => $post_id,
			'comment_author'   => 'Internal Note',
			'comment_content'  => 'Must never be mutated by comment abilities',
			'comment_approved' => 1,
			'comment_type'     => 'note',
			'user_id'          => $original_user,
		)
	);
	foreach ( array( $approved_id, $pending_id, $note_id ) as $comment_id ) {
		wpnb_issue52_live_assert( $comment_id > 0, 'Issue #52 fixture comment could not be created.' );
		$created_comments[] = (int) $comment_id;
	}

	$disabled = $settings->defaults();
	update_option( Settings::OPTION_NAME, $disabled, false );
	wpnb_issue52_live_assert( false === $read->check_permissions( array( 'action' => 'list', 'post' => $post_id ) ), 'Disabled Comments permitted read.' );
	$before_count = (int) get_comments( array( 'post_id' => $post_id, 'count' => true, 'status' => 'all' ) );
	$direct_denied = $reply->execute( array( 'post' => $post_id, 'content' => 'Must not exist' ) );
	wpnb_issue52_live_assert( is_wp_error( $direct_denied ) && 'ability_invalid_permissions' === $direct_denied->get_error_code(), 'WP_Ability execute did not fail closed while Comments was disabled.' );
	$after_count = (int) get_comments( array( 'post_id' => $post_id, 'count' => true, 'status' => 'all' ) );
	wpnb_issue52_live_assert( $before_count === $after_count, 'Disabled direct execute created a comment.' );

	$enabled = $settings->defaults();
	$enabled[ Settings::GROUP_COMMENTS ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	wpnb_issue52_live_assert( true === $read->check_permissions( array( 'action' => 'list', 'post' => $post_id, 'scope' => 'public' ) ), 'Enabled Comments did not permit public comment inspection.' );

	$public = $read->execute(
		array(
			'action'   => 'list',
			'scope'    => 'public',
			'post'     => $post_id,
			'status'   => 'approved',
			'page'     => 1,
			'per_page' => 100,
		)
	);
	wpnb_issue52_live_assert( ! is_wp_error( $public ), 'Public comment list failed.' );
	$public_ids = array_column( $public['items'], 'id' );
	wpnb_issue52_live_assert( in_array( (int) $approved_id, $public_ids, true ), 'Approved standard comment missing from public list.' );
	wpnb_issue52_live_assert( ! in_array( (int) $pending_id, $public_ids, true ), 'Pending comment leaked through public list.' );
	wpnb_issue52_live_assert( ! in_array( (int) $note_id, $public_ids, true ), 'Non-comment note leaked through standard comment list.' );
	$approved_output = null;
	foreach ( $public['items'] as $candidate_item ) {
		if ( (int) $candidate_item['id'] === (int) $approved_id ) {
			$approved_output = $candidate_item;
			break;
		}
	}
	wpnb_issue52_live_assert( is_array( $approved_output ) && strlen( $approved_output['content'] ) <= 4096 && true === $approved_output['content_truncated'], 'Large comment output was not explicitly byte-bounded.' );
	foreach ( $public['items'] as $item ) {
		foreach ( array( 'author_email', 'author_ip', 'user_agent', 'meta', 'avatar_urls' ) as $private_key ) {
			wpnb_issue52_live_assert( ! array_key_exists( $private_key, $item ), 'Private field leaked from comment output: ' . $private_key );
		}
	}

	$moderation = $read->execute(
		array(
			'action'   => 'list',
			'scope'    => 'moderation',
			'post'     => $post_id,
			'status'   => 'hold',
			'page'     => 1,
			'per_page' => 100,
		)
	);
	wpnb_issue52_live_assert( ! is_wp_error( $moderation ), 'Moderation queue read failed for administrator.' );
	wpnb_issue52_live_assert( in_array( (int) $pending_id, array_column( $moderation['items'], 'id' ), true ), 'Pending comment missing from moderation queue.' );
	$public_pending = $read->execute( array( 'action' => 'get', 'scope' => 'public', 'id' => $pending_id ) );
	wpnb_issue52_live_assert( is_wp_error( $public_pending ) && 'comment_scope_requires_moderation' === $public_pending->get_error_code(), 'Public exact-get exposed a non-public comment to a privileged principal.' );
	$moderation_pending = $read->execute( array( 'action' => 'get', 'scope' => 'moderation', 'id' => $pending_id ) );
	wpnb_issue52_live_assert( ! is_wp_error( $moderation_pending ) && (int) $moderation_pending['items'][0]['id'] === (int) $pending_id, 'Moderation exact-get could not inspect the pending comment.' );

	$reply_input = array( 'post' => $post_id, 'parent' => $approved_id, 'content' => 'Issue 52 bounded reply' );
	wpnb_issue52_live_assert( true === $reply->check_permissions( $reply_input ), 'Comments grant did not permit reply permission check.' );
	$reply_result = $reply->execute( $reply_input );
	wpnb_issue52_live_assert( ! is_wp_error( $reply_result ), 'Core REST comment reply failed.' );
	$reply_id = (int) $reply_result['id'];
	$created_comments[] = $reply_id;
	$reply_object = get_comment( $reply_id );
	wpnb_issue52_live_assert( $reply_object instanceof WP_Comment, 'Reply was not persisted as a WordPress comment.' );
	wpnb_issue52_live_assert( (int) $reply_object->comment_post_ID === (int) $post_id, 'Reply changed the concrete target post.' );
	wpnb_issue52_live_assert( (int) $reply_object->comment_parent === (int) $approved_id, 'Reply changed the concrete parent comment.' );
	wpnb_issue52_live_assert( 'Issue 52 bounded reply' === $reply_object->comment_content, 'Reply content changed unexpectedly.' );

	foreach ( array( 'approved', 'spam', 'unspam', 'trash', 'untrash' ) as $transition ) {
		$input = array( 'id' => $pending_id, 'status' => $transition );
		wpnb_issue52_live_assert( true === $status->check_permissions( $input ), 'Administrator status permission denied for ' . $transition );
		$result = $status->execute( $input );
		wpnb_issue52_live_assert( ! is_wp_error( $result ), 'Core status transition failed for ' . $transition );
		$expected = $transition;
		if ( 'unspam' === $transition || 'untrash' === $transition ) {
			$expected = 'approved';
		}
		wpnb_issue52_live_assert( $expected === wp_get_comment_status( $pending_id ), 'Unexpected Core comment status after ' . $transition );
	}

	$note_mutation = $status->execute( array( 'id' => $note_id, 'status' => 'hold' ) );
	wpnb_issue52_live_assert( is_wp_error( $note_mutation ) && 'comment_type_not_supported' === $note_mutation->get_error_code(), 'Status Ability mutated a non-comment note.' );

	$trash_id = wp_insert_comment(
		array(
			'comment_post_ID'  => $post_id,
			'comment_author'   => 'Trash Fixture',
			'comment_content'  => 'Trash me safely',
			'comment_approved' => 1,
			'comment_type'     => '',
		)
	);
	wpnb_issue52_live_assert( $trash_id > 0, 'Trash fixture could not be created.' );
	$created_comments[] = (int) $trash_id;
	$trash_input = array( 'id' => $trash_id, 'force' => false );
	wpnb_issue52_live_assert( true === $delete->check_permissions( $trash_input ), 'Comments grant did not permit safe Trash operation.' );
	$trash = $delete->execute( $trash_input );
	wpnb_issue52_live_assert( ! is_wp_error( $trash ) && true === $trash['trashed'] && false === $trash['deleted'], 'Non-force delete did not move comment to Trash.' );
	wpnb_issue52_live_assert( 'trash' === wp_get_comment_status( $trash_id ), 'Comment did not enter Trash.' );

	$repeat_trash = $delete->execute( $trash_input );
	wpnb_issue52_live_assert( ! is_wp_error( $repeat_trash ) && true === $repeat_trash['trashed'] && false === $repeat_trash['deleted'], 'Repeated non-force delete was not idempotent.' );
	wpnb_issue52_live_assert( get_comment( $trash_id ) instanceof WP_Comment, 'Repeated non-force delete permanently removed an already-trashed comment.' );
	wpnb_issue52_live_assert( false === $delete->check_permissions( array( 'id' => $trash_id, 'force' => true ) ), 'Permanent deletion was permitted without Users & Destructive.' );
	$force_denied = $delete->execute( array( 'id' => $trash_id, 'force' => true ) );
	wpnb_issue52_live_assert( is_wp_error( $force_denied ), 'Direct execute permanently deleted without destructive grant.' );
	wpnb_issue52_live_assert( get_comment( $trash_id ) instanceof WP_Comment, 'Denied force-delete removed the comment.' );

	$enabled[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	wpnb_issue52_live_assert( true === $delete->check_permissions( array( 'id' => $trash_id, 'force' => true ) ), 'Dual Comments + destructive grant did not permit exact force-delete.' );
	$forced = $delete->execute( array( 'id' => $trash_id, 'force' => true ) );
	wpnb_issue52_live_assert( ! is_wp_error( $forced ) && true === $forced['deleted'], 'Authorized force-delete failed.' );
	wpnb_issue52_live_assert( null === get_comment( $trash_id ), 'Authorized force-delete did not permanently remove the comment.' );
	$created_comments = array_values( array_diff( $created_comments, array( (int) $trash_id ) ) );

	$subscriber_id = wp_create_user( 'issue52_subscriber_' . wp_generate_password( 8, false ), wp_generate_password( 24, true ), 'issue52@example.invalid' );
	wpnb_issue52_live_assert( ! is_wp_error( $subscriber_id ), 'Subscriber fixture could not be created.' );
	$created_users[] = (int) $subscriber_id;
	$subscriber = new WP_User( $subscriber_id );
	$subscriber->set_role( 'subscriber' );
	wp_set_current_user( $subscriber_id );
	$moderation_input = array( 'action' => 'list', 'scope' => 'moderation', 'post' => $post_id, 'status' => 'hold' );
	wpnb_issue52_live_assert( false === $read->check_permissions( $moderation_input ), 'Low-privilege principal received moderation read authority.' );
	wpnb_issue52_live_assert( false === $status->check_permissions( array( 'id' => $approved_id, 'status' => 'hold' ) ), 'Low-privilege principal received moderation mutation authority.' );
	wpnb_issue52_live_assert( false === $delete->check_permissions( array( 'id' => $approved_id, 'force' => false ) ), 'Low-privilege principal received exact comment deletion authority.' );

	wp_set_current_user( $original_user );
	$enabled[ Settings::GROUP_COMMENTS ] = 0;
	update_option( Settings::OPTION_NAME, $enabled, false );
	$before_revoke_count = (int) get_comments( array( 'post_id' => $post_id, 'count' => true, 'status' => 'all' ) );
	$revoked = $reply->execute( array( 'post' => $post_id, 'content' => 'Revoked reply' ) );
	wpnb_issue52_live_assert( is_wp_error( $revoked ) && 'ability_invalid_permissions' === $revoked->get_error_code(), 'Comments revocation did not deny WP_Ability execution immediately.' );
	$after_revoke_count = (int) get_comments( array( 'post_id' => $post_id, 'count' => true, 'status' => 'all' ) );
	wpnb_issue52_live_assert( $before_revoke_count === $after_revoke_count, 'Revoked execute still mutated WordPress comments.' );

	$log_entries = get_option( Mutation_Log::OPTION_NAME, array() );
	foreach ( is_array( $log_entries ) ? $log_entries : array() as $entry ) {
		wpnb_issue52_live_assert( ! isset( $entry['content'], $entry['author_email'], $entry['author_ip'], $entry['user_agent'] ), 'Mutation log retained comment/private author payload.' );
	}
} finally {
	wp_set_current_user( $original_user );
	foreach ( array_unique( array_map( 'intval', $created_comments ) ) as $comment_id ) {
		if ( get_comment( $comment_id ) ) {
			wp_delete_comment( $comment_id, true );
		}
	}
	foreach ( array_unique( array_map( 'intval', $created_posts ) ) as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	if ( $created_users ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( array_unique( array_map( 'intval', $created_users ) ) as $user_id ) {
			wp_delete_user( $user_id );
		}
	}
	update_option( Settings::OPTION_NAME, $original_settings, false );
	update_option( Mutation_Log::OPTION_NAME, $original_log, false );
}

echo "PASS: Issue #52 bounded WordPress comment administration.\n";
