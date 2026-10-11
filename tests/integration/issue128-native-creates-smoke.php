<?php
/**
 * Native six-path keyed create readback: content, media, import, comments;
 * Workspace document/task are separately exercised by #126 integration.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Support\Settings;

function wpai128_native_assert( $ok, $why ) {
	if ( ! $ok ) { throw new RuntimeException( $why ); }
}
function wpai128_native_execute( $ability, $input ) {
	$contract = wp_get_ability( 'wp-ai-bridge/' . $ability );
	wpai128_native_assert( $contract instanceof WP_Ability, 'Missing native create Ability ' . $ability );
	return $contract->execute( $input );
}

$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$groups = $settings->defaults();
$groups[ Settings::GROUP_BUILDER_WRITE ] = 1;
$groups[ Settings::GROUP_REMOTE_MEDIA ] = 1;
$groups[ Settings::GROUP_LIVE_CONTENT ] = 1;
$groups[ Settings::GROUP_COMMENTS ] = 1;
$post = 0;
$comment = 0;
$attachments = array();
$http_calls = 0;
$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=';
$png = base64_decode( $png_base64, true );
$source = 'https://s.w.org/wpai-128-fixture';
$mock = static function ( $pre, $args, $url ) use ( $source, $png, &$http_calls ) {
	if ( $url !== $source ) {
		return $pre;
	}
	++$http_calls;
	if ( empty( $args['stream'] ) || empty( $args['reject_unsafe_urls'] ) ||
		! isset( $args['filename'] ) || empty( $args['sslverify'] ) ) {
		return new WP_Error( 'fixture_policy_mismatch', 'Media import must be safe streaming.' );
	}
	file_put_contents( $args['filename'], $png );
	return array(
		'response' => array( 'code' => 200, 'message' => 'Fixture' ),
		'headers' => array( 'content-length' => (string) strlen( $png ) ),
		'body' => '',
		'cookies' => array(),
		'filename' => $args['filename'],
	);
};
try {
	update_option( Settings::OPTION_NAME, $groups, false );
	$content = array( 'action' => 'create', 'post_type' => 'post', 'title' => 'WPAI 128 keyed post', 'status' => 'draft', 'operation_id' => 'content-key-128-01' );
	$created = wpai128_native_execute( 'content-upsert', $content );
	wpai128_native_assert( ! is_wp_error( $created ) && ! empty( $created['id'] ), 'Keyed WordPress post create failed.' );
	$post = (int) $created['id'];
	$retry = wpai128_native_execute( 'content-upsert', $content );
	wpai128_native_assert( ! is_wp_error( $retry ) && (int) $retry['id'] === $post, 'Lost post response duplicated an object.' );
	$conflict = wpai128_native_execute( 'content-upsert', array_replace( $content, array( 'title' => 'Another intent' ) ) );
	wpai128_native_assert( is_wp_error( $conflict ) && 'create_claim_conflict' === $conflict->get_error_code(), 'Reused key with different payload not blocked.' );

	$media = array( 'filename' => 'wpai128.png', 'content_base64' => $png_base64, 'operation_id' => 'media-key-128-01' );
	$media_first = wpai128_native_execute( 'media-upload', $media );
	wpai128_native_assert( ! is_wp_error( $media_first ) && ! empty( $media_first['id'] ), 'Keyed Media Library upload failed.' );
	$attachments[] = (int) $media_first['id'];
	$media_retry = wpai128_native_execute( 'media-upload', $media );
	wpai128_native_assert( ! is_wp_error( $media_retry ) && $media_retry['id'] === $media_first['id'], 'Media upload repeated after success.' );

	add_filter( 'pre_http_request', $mock, 10, 3 );
	$remote = array( 'url' => $source, 'filename' => 'wpai128-import.png', 'operation_id' => 'import-key-128-01' );
	$imported = wpai128_native_execute( 'media-import-url', $remote );
	wpai128_native_assert( ! is_wp_error( $imported ) && ! empty( $imported['id'] ) && 1 === $http_calls, 'Keyed native safe HTTPS sideload failed.' );
	$attachments[] = (int) $imported['id'];
	$imported_retry = wpai128_native_execute( 'media-import-url', $remote );
	wpai128_native_assert( ! is_wp_error( $imported_retry ) && $imported_retry['id'] === $imported['id'] && 1 === $http_calls, 'URL import repeated HTTP on retry.' );
	remove_filter( 'pre_http_request', $mock, 10 );

	$post_for_comment = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'WPAI 128 comment fixture', 'comment_status' => 'open' ), true );
	wpai128_native_assert( ! is_wp_error( $post_for_comment ), 'Comment parent fixture create failed.' );
	$post_for_comment = (int) $post_for_comment;
	$comment_input = array( 'post' => $post_for_comment, 'content' => 'Create once.', 'operation_id' => 'comment-key-128-01' );
	$first_comment = wpai128_native_execute( 'comment-reply', $comment_input );
	wpai128_native_assert( ! is_wp_error( $first_comment ) && ! empty( $first_comment['id'] ), 'Native keyed comment reply failed.' );
	$comment = (int) $first_comment['id'];
	$comment_retry = wpai128_native_execute( 'comment-reply', $comment_input );
	wpai128_native_assert( ! is_wp_error( $comment_retry ) && $comment_retry['id'] === $comment, 'Comment reply created a duplicate.' );
	$groups[ Settings::GROUP_COMMENTS ] = 0;
	update_option( Settings::OPTION_NAME, $groups, false );
	wpai128_native_assert( is_wp_error( wpai128_native_execute( 'comment-reply', $comment_input ) ), 'Revoked Comments grant disclosed an old create receipt.' );

	echo "PASS: #128 keyed post/media/URL import/comment creates and no-replay readback.\n";
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
	if ( $comment ) { wp_delete_comment( $comment, true ); }
	if ( isset( $post_for_comment ) && is_int( $post_for_comment ) ) { wp_delete_post( $post_for_comment, true ); }
	foreach ( $attachments as $id ) { wp_delete_attachment( $id, true ); }
	if ( $post ) { wp_delete_post( $post, true ); }
	update_option( Settings::OPTION_NAME, $original, false );
}
