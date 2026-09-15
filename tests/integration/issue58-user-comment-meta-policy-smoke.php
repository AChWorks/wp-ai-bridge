<?php
/**
 * Policy and precondition coverage for Issue #58 user/comment metadata.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue58_policy_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue58_policy_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpnb_issue58_policy_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpnb_issue58_policy_item( $type, $id, $key, $include_values = false ) {
	$field  = 'user' === $type ? 'user_id' : 'comment_id';
	$result = wpnb_issue58_policy_execute(
		'wp-native-builder/' . $type . '-meta-read',
		array(
			$field           => (int) $id,
			'key'            => (string) $key,
			'include_values' => (bool) $include_values,
		)
	);
	wpnb_issue58_policy_assert( ! is_wp_error( $result ) && 1 === count( $result['items'] ), 'Could not inspect Issue #58 policy fixture.' );
	return $result['items'][0];
}

$settings        = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$admin_id        = get_current_user_id();
$user_id         = 0;
$post_id         = 0;
$comment_id      = 0;
$same_cap_filter = null;

try {
	$user_id = wp_insert_user(
		array(
			'user_login' => 'issue58-policy-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'issue58-policy-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpnb_issue58_policy_assert( ! is_wp_error( $user_id ) && $user_id > 0, 'Could not create Issue #58 policy user.' );

	$post_id = wp_insert_post(
		array(
			'post_title'  => 'Issue 58 policy host',
			'post_status' => 'publish',
			'post_type'   => 'post',
		)
	);
	wpnb_issue58_policy_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Could not create Issue #58 policy post.' );

	$comment_id = wp_insert_comment(
		array(
			'comment_post_ID'      => (int) $post_id,
			'comment_content'      => 'Issue 58 policy comment',
			'comment_author'       => 'Issue 58',
			'comment_author_email' => 'issue58-policy-comment@example.invalid',
			'comment_approved'     => 1,
		)
	);
	wpnb_issue58_policy_assert( $comment_id > 0, 'Could not create Issue #58 policy comment.' );

	add_user_meta( $user_id, 'issue58_disabled_update', 'before', true );
	add_comment_meta( $comment_id, 'issue58_disabled_delete', 'before', true );

	$disabled                                      = $settings->defaults();
	$disabled[ Settings::GROUP_ADVANCED_METADATA ] = 0;
	$disabled[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $disabled, false );

	$disabled_update = wpnb_issue58_policy_execute(
		'wp-native-builder/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => 'issue58_disabled_update',
			'expected_state_hash' => str_repeat( '0', 64 ),
			'value_json'          => '"after"',
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $disabled_update ), 'Advanced Metadata disabled state allowed a user metadata update.' );
	wpnb_issue58_policy_assert( 'before' === get_user_meta( $user_id, 'issue58_disabled_update', true ), 'Denied disabled user metadata update changed state.' );

	$disabled_delete = wpnb_issue58_policy_execute(
		'wp-native-builder/comment-meta-delete',
		array(
			'comment_id'          => (int) $comment_id,
			'key'                 => 'issue58_disabled_delete',
			'expected_state_hash' => str_repeat( '0', 64 ),
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $disabled_delete ), 'Advanced Metadata disabled state allowed a comment metadata delete.' );
	wpnb_issue58_policy_assert( metadata_exists( 'comment', $comment_id, 'issue58_disabled_delete' ), 'Denied disabled comment metadata delete changed state.' );

	$access                                      = $settings->defaults();
	$access[ Settings::GROUP_ADVANCED_METADATA ] = 1;
	$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );

	$private_key = '_issue58_comment_private';
	add_comment_meta( $comment_id, $private_key, 'private-value', true );
	$private = wpnb_issue58_policy_item( 'comment', $comment_id, $private_key, true );
	wpnb_issue58_policy_assert( '"private-value"' === $private['values'][0]['value_json'], 'Advanced Metadata did not unlock ordinary protected comment metadata.' );

	$denied_key = '_issue58_comment_provider_denied';
	add_comment_meta( $comment_id, $denied_key, 'provider-owned', true );
	$deny_filter = static function () {
		return false;
	};
	add_filter( 'auth_comment_meta_' . $denied_key, $deny_filter, 10, 6 );
	$provider_denied = wpnb_issue58_policy_execute(
		'wp-native-builder/comment-meta-read',
		array(
			'comment_id'     => (int) $comment_id,
			'key'            => $denied_key,
			'include_values' => true,
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $provider_denied ), 'Explicit comment metadata provider denial was bypassed.' );
	$provider_update = wpnb_issue58_policy_execute(
		'wp-native-builder/comment-meta-update',
		array(
			'comment_id'          => (int) $comment_id,
			'key'                 => $denied_key,
			'expected_state_hash' => str_repeat( '0', 64 ),
			'value_json'          => '"bypass"',
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $provider_update ), 'Explicit comment metadata provider denial was bypassed by update.' );
	wpnb_issue58_policy_assert( 'provider-owned' === get_comment_meta( $comment_id, $denied_key, true ), 'Provider-denied comment metadata changed state.' );
	remove_filter( 'auth_comment_meta_' . $denied_key, $deny_filter, 10 );

	$user_policy_key           = '_issue58_user_same_cap_policy';
	$comment_policy_key        = '_issue58_comment_same_cap_policy';
	$user_create_policy_key    = '_issue58_user_same_cap_create';
	$comment_create_policy_key = '_issue58_comment_same_cap_create';
	add_user_meta( $user_id, $user_policy_key, 'user-policy-before', true );
	add_comment_meta( $comment_id, $comment_policy_key, 'comment-policy-before', true );
	$user_policy_state    = wpnb_issue58_policy_item( 'user', $user_id, $user_policy_key );
	$comment_policy_state = wpnb_issue58_policy_item( 'comment', $comment_id, $comment_policy_key );
	$user_empty_state     = wpnb_issue58_policy_item( 'user', $user_id, $user_create_policy_key );
	$comment_empty_state  = wpnb_issue58_policy_item( 'comment', $comment_id, $comment_create_policy_key );

	$same_cap_targets = array(
		'user'    => array(
			array( (int) $user_id, $user_policy_key ),
			array( (int) $user_id, $user_create_policy_key ),
		),
		'comment' => array(
			array( (int) $comment_id, $comment_policy_key ),
			array( (int) $comment_id, $comment_create_policy_key ),
		),
	);
	$same_cap_filter  = static function ( $caps, $cap, $filter_user_id, $args ) use ( $same_cap_targets ) {
		if ( ! preg_match( '/^(add|edit|delete)_(user|comment)_meta$/', (string) $cap, $matches ) || count( (array) $args ) < 2 ) {
			return $caps;
		}
		$type      = $matches[2];
		$object_id = (int) $args[0];
		$meta_key  = (string) $args[1];
		foreach ( $same_cap_targets[ $type ] as $target ) {
			if ( $object_id === $target[0] && $meta_key === $target[1] ) {
				$caps[] = $cap;
				return array_values( array_unique( $caps ) );
			}
		}
		return $caps;
	};
	add_filter( 'map_meta_cap', $same_cap_filter, 99, 4 );

	foreach ( array( 'add', 'edit', 'delete' ) as $operation ) {
		wpnb_issue58_policy_assert( ! current_user_can( $operation . '_user_meta', $user_id, $user_policy_key ), 'Native user metadata policy unexpectedly allowed the same-capability mapped requirement.' );
		wpnb_issue58_policy_assert( ! current_user_can( $operation . '_comment_meta', $comment_id, $comment_policy_key ), 'Native comment metadata policy unexpectedly allowed the same-capability mapped requirement.' );
	}

	$mapped_user_read = wpnb_issue58_policy_execute(
		'wp-native-builder/user-meta-read',
		array(
			'user_id'        => (int) $user_id,
			'key'            => $user_policy_key,
			'include_values' => true,
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $mapped_user_read ), 'Bridge bypassed a same-capability map_meta_cap user policy on exact read.' );
	$mapped_comment_read = wpnb_issue58_policy_execute(
		'wp-native-builder/comment-meta-read',
		array(
			'comment_id'     => (int) $comment_id,
			'key'            => $comment_policy_key,
			'include_values' => true,
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $mapped_comment_read ), 'Bridge bypassed a same-capability map_meta_cap comment policy on exact read.' );

	$mapped_user_update = wpnb_issue58_policy_execute(
		'wp-native-builder/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => $user_policy_key,
			'expected_state_hash' => $user_policy_state['state_hash'],
			'value_json'          => '"user-policy-after"',
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $mapped_user_update ), 'Bridge bypassed a same-capability map_meta_cap user policy on update.' );
	wpnb_issue58_policy_assert( 'user-policy-before' === get_user_meta( $user_id, $user_policy_key, true ), 'Denied same-capability user metadata update changed state.' );
	$mapped_comment_update = wpnb_issue58_policy_execute(
		'wp-native-builder/comment-meta-update',
		array(
			'comment_id'          => (int) $comment_id,
			'key'                 => $comment_policy_key,
			'expected_state_hash' => $comment_policy_state['state_hash'],
			'value_json'          => '"comment-policy-after"',
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $mapped_comment_update ), 'Bridge bypassed a same-capability map_meta_cap comment policy on update.' );
	wpnb_issue58_policy_assert( 'comment-policy-before' === get_comment_meta( $comment_id, $comment_policy_key, true ), 'Denied same-capability comment metadata update changed state.' );

	$mapped_user_delete = wpnb_issue58_policy_execute(
		'wp-native-builder/user-meta-delete',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => $user_policy_key,
			'expected_state_hash' => $user_policy_state['state_hash'],
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $mapped_user_delete ) && metadata_exists( 'user', $user_id, $user_policy_key ), 'Bridge bypassed a same-capability map_meta_cap user policy on delete.' );
	$mapped_comment_delete = wpnb_issue58_policy_execute(
		'wp-native-builder/comment-meta-delete',
		array(
			'comment_id'          => (int) $comment_id,
			'key'                 => $comment_policy_key,
			'expected_state_hash' => $comment_policy_state['state_hash'],
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $mapped_comment_delete ) && metadata_exists( 'comment', $comment_id, $comment_policy_key ), 'Bridge bypassed a same-capability map_meta_cap comment policy on delete.' );

	$mapped_user_create = wpnb_issue58_policy_execute(
		'wp-native-builder/user-meta-update',
		array(
			'user_id'             => (int) $user_id,
			'key'                 => $user_create_policy_key,
			'expected_state_hash' => $user_empty_state['state_hash'],
			'value_json'          => '"created"',
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $mapped_user_create ) && ! metadata_exists( 'user', $user_id, $user_create_policy_key ), 'Bridge bypassed a same-capability map_meta_cap user policy on create.' );
	$mapped_comment_create = wpnb_issue58_policy_execute(
		'wp-native-builder/comment-meta-update',
		array(
			'comment_id'          => (int) $comment_id,
			'key'                 => $comment_create_policy_key,
			'expected_state_hash' => $comment_empty_state['state_hash'],
			'value_json'          => '"created"',
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $mapped_comment_create ) && ! metadata_exists( 'comment', $comment_id, $comment_create_policy_key ), 'Bridge bypassed a same-capability map_meta_cap comment policy on create.' );

	remove_filter( 'map_meta_cap', $same_cap_filter, 99 );
	$same_cap_filter = null;

	$stale_key = 'issue58_stale_delete';
	add_comment_meta( $comment_id, $stale_key, 'before', true );
	$stale_state = wpnb_issue58_policy_item( 'comment', $comment_id, $stale_key );
	update_comment_meta( $comment_id, $stale_key, 'newer' );
	$stale_delete = wpnb_issue58_policy_execute(
		'wp-native-builder/comment-meta-delete',
		array(
			'comment_id'          => (int) $comment_id,
			'key'                 => $stale_key,
			'expected_state_hash' => $stale_state['state_hash'],
		)
	);
	wpnb_issue58_policy_assert( is_wp_error( $stale_delete ), 'Stale comment metadata delete was accepted.' );
	wpnb_issue58_policy_assert( 'newer' === get_comment_meta( $comment_id, $stale_key, true ), 'Stale comment metadata delete changed newer state.' );

	echo "PASS: Issue #58 disabled, provider-auth, protected-comment, and stale-delete policy coverage.\n";
} finally {
	if ( null !== $same_cap_filter ) {
		remove_filter( 'map_meta_cap', $same_cap_filter, 99 );
	}
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
