<?php
/**
 * Real WordPress concurrency/compensation coverage for Issue #58.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai_issue58_race_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue58_race_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue58_race_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpai_issue58_race_state( $type, $id, $key ) {
	$field  = 'user' === $type ? 'user_id' : 'comment_id';
	$result = wpai_issue58_race_execute(
		'wp-ai-bridge/' . $type . '-meta-read',
		array(
			$field => (int) $id,
			'key'  => (string) $key,
		)
	);
	wpai_issue58_race_assert( ! is_wp_error( $result ) && 1 === count( $result['items'] ), 'Could not read metadata race fixture.' );
	return $result['items'][0];
}

$settings        = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$admin_id        = get_current_user_id();
$user_id         = 0;
$post_id         = 0;
$comment_id      = 0;

try {
	$access                                      = $settings->defaults();
	$access[ Settings::GROUP_ADVANCED_METADATA ] = 1;
	$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );

	$user_id = wp_insert_user(
		array(
			'user_login' => 'issue58-race-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'issue58-race-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpai_issue58_race_assert( ! is_wp_error( $user_id ) && $user_id > 0, 'Could not create race user fixture.' );

	$post_id = wp_insert_post(
		array(
			'post_title'  => 'Issue 58 race host',
			'post_status' => 'publish',
			'post_type'   => 'post',
		)
	);
	wpai_issue58_race_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Could not create race post fixture.' );
	$comment_id = wp_insert_comment(
		array(
			'comment_post_ID'      => (int) $post_id,
			'comment_content'      => 'Issue 58 race comment',
			'comment_author'       => 'Issue 58',
			'comment_author_email' => 'issue58-race-comment@example.invalid',
			'comment_approved'     => 1,
		)
	);
	wpai_issue58_race_assert( $comment_id > 0, 'Could not create race comment fixture.' );

	$user_key = 'issue58_update_race';
	add_user_meta( $user_id, $user_key, 'before', true );
	$user_state = wpai_issue58_race_state( 'user', $user_id, $user_key );
	$user_raced = false;
	$user_hook  = static function ( $meta_id, $object_id, $meta_key, $meta_value ) use ( $user_id, $user_key, &$user_raced ) {
		unset( $meta_id );
		if ( ! $user_raced && (int) $object_id === (int) $user_id && $meta_key === $user_key && 'after' === $meta_value ) {
			$user_raced = true;
			add_user_meta( $user_id, $user_key, 'concurrent', false );
		}
	};
	add_action( 'updated_user_meta', $user_hook, 10, 4 );
	$user_result = wpai_issue58_race_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => $user_key,
			'expected_state_hash' => $user_state['state_hash'],
			'value_json'          => '"after"',
		)
	);
	remove_action( 'updated_user_meta', $user_hook, 10 );
	wpai_issue58_race_assert( is_wp_error( $user_result ) && 'stale_object_meta_conflict' === $user_result->get_error_code(), 'Concurrent user metadata update did not fail stale.' );
	$user_values = get_user_meta( $user_id, $user_key, false );
	sort( $user_values );
	wpai_issue58_race_assert( array( 'before', 'concurrent' ) === $user_values, 'User metadata compensation overwrote or lost concurrent state.' );

	$comment_key = 'issue58_delete_race';
	add_comment_meta( $comment_id, $comment_key, 'before', true );
	$comment_state = wpai_issue58_race_state( 'comment', $comment_id, $comment_key );
	$comment_raced = false;
	$comment_hook  = static function ( $meta_ids, $object_id, $meta_key, $meta_value ) use ( $comment_id, $comment_key, &$comment_raced ) {
		unset( $meta_ids, $meta_value );
		if ( ! $comment_raced && (int) $object_id === (int) $comment_id && $meta_key === $comment_key ) {
			$comment_raced = true;
			add_comment_meta( $comment_id, $comment_key, 'concurrent', false );
		}
	};
	add_action( 'deleted_comment_meta', $comment_hook, 10, 4 );
	$comment_result = wpai_issue58_race_execute(
		'wp-ai-bridge/comment-meta-delete',
		array(
			'comment_id'          => (int) $comment_id,
			'key'                 => $comment_key,
			'expected_state_hash' => $comment_state['state_hash'],
		)
	);
	remove_action( 'deleted_comment_meta', $comment_hook, 10 );
	wpai_issue58_race_assert( is_wp_error( $comment_result ) && 'stale_object_meta_conflict' === $comment_result->get_error_code(), 'Concurrent comment metadata delete did not fail stale.' );
	$comment_values = get_comment_meta( $comment_id, $comment_key, false );
	sort( $comment_values );
	wpai_issue58_race_assert( array( 'before', 'concurrent' ) === $comment_values, 'Comment metadata compensation overwrote or lost concurrent state.' );

	echo "PASS: Issue #58 metadata concurrency compensation.\n";
} finally {
	wp_set_current_user( $admin_id );
	update_option( Settings::OPTION_NAME, $original_access, false );
	if ( $comment_id > 0 ) {
		wp_delete_comment( $comment_id, true );
	}
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	if ( $user_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
}
