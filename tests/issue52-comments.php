<?php
/**
 * Dependency-free Issue #52 comment administration tests.
 *
 * @package WP_Native_Builder_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Comment_Abilities;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

$failures = 0;
$tests    = 0;

function wpnb52_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public $method;
		public $route;
		public $params = array();

		public function __construct( $method, $route ) {
			$this->method = (string) $method;
			$this->route  = (string) $route;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}
	}
}

final class WP_AI_Bridge_Issue52_Test_Response {
	private $data;
	private $headers;
	private $error;

	public function __construct( $data, array $headers = array(), $error = null ) {
		$this->data    = $data;
		$this->headers = $headers;
		$this->error   = $error;
	}

	public function get_data() { return $this->data; }
	public function get_headers() { return $this->headers; }
	public function is_error() { return $this->error instanceof WP_Error; }
	public function as_error() { return $this->error; }
}

$GLOBALS['wpnb52_rest_requests'] = array();
$GLOBALS['wpnb52_comment_types'] = array();
$GLOBALS['wpnb52_comment_statuses'] = array();

function rest_do_request( $request ) {
	$GLOBALS['wpnb52_rest_requests'][] = array(
		'method' => $request->method,
		'route'  => $request->route,
		'params' => $request->params,
	);

	if ( 'GET' === $request->method && '/wp/v2/comments' === $request->route ) {
		return new WP_AI_Bridge_Issue52_Test_Response(
			array(
				array(
					'id'           => 11,
					'post'         => 8,
					'parent'       => 0,
					'author'       => 2,
					'author_name'  => 'Public Author',
					'author_url'   => 'https://example.test/author',
					'author_email' => 'hidden@example.test',
					'author_ip'    => '192.0.2.10',
					'user_agent'   => 'secret-agent',
					'content'      => array( 'rendered' => str_repeat( 'x', 5000 ) ),
					'status'       => 'approved',
					'date_gmt'     => '2026-09-14T08:00:00',
					'type'         => 'comment',
					'meta'         => array( 'private' => 'secret' ),
				)
			),
			array(
				'X-WP-Total'      => '1',
				'X-WP-TotalPages' => '1',
			)
		);
	}

	if ( 'GET' === $request->method && preg_match( '#^/wp/v2/comments/([0-9]+)$#', $request->route, $matches ) ) {
		return new WP_AI_Bridge_Issue52_Test_Response(
			array(
				'id'           => (int) $matches[1],
				'post'         => 77 === (int) $matches[1] ? 9 : 8,
				'parent'       => 0,
				'author'       => 2,
				'author_name'  => 'Public Author',
				'author_email' => 'hidden@example.test',
				'author_ip'    => '192.0.2.10',
				'user_agent'   => 'secret-agent',
				'content'      => array( 'raw' => 'Exact content' ),
				'status'       => 99 === (int) $matches[1] ? 'hold' : 'approved',
				'date_gmt'     => '2026-09-14T08:00:00',
				'type'         => 'comment',
				'meta'         => array( 'private' => 'secret' ),
			)
		);
	}

	if ( 'POST' === $request->method && '/wp/v2/comments' === $request->route ) {
		return new WP_AI_Bridge_Issue52_Test_Response(
			array(
				'id'          => 22,
				'post'        => (int) $request->params['post'],
				'parent'      => isset( $request->params['parent'] ) ? (int) $request->params['parent'] : 0,
				'author'      => 1,
				'author_name' => 'Admin',
				'author_url'  => '',
				'content'     => array( 'raw' => (string) $request->params['content'] ),
				'status'      => 'approved',
				'date_gmt'    => '2026-09-14T08:01:00',
				'type'        => 'comment',
			)
		);
	}

	if ( 'POST' === $request->method && preg_match( '#^/wp/v2/comments/([0-9]+)$#', $request->route, $matches ) ) {
		return new WP_AI_Bridge_Issue52_Test_Response(
			array(
				'id'          => (int) $matches[1],
				'post'        => 8,
				'parent'      => 0,
				'author'      => 2,
				'author_name' => 'Author',
				'author_url'  => '',
				'content'     => array( 'raw' => 'Moderated content' ),
				'status'      => (string) $request->params['status'],
				'date_gmt'    => '2026-09-14T08:02:00',
				'type'        => 'comment',
			)
		);
	}

	if ( 'DELETE' === $request->method && preg_match( '#^/wp/v2/comments/([0-9]+)$#', $request->route, $matches ) ) {
		if ( ! empty( $request->params['force'] ) ) {
			return new WP_AI_Bridge_Issue52_Test_Response( array( 'deleted' => true, 'previous' => array( 'id' => (int) $matches[1] ) ) );
		}
		return new WP_AI_Bridge_Issue52_Test_Response(
			array(
				'id'          => (int) $matches[1],
				'post'        => 8,
				'parent'      => 0,
				'author'      => 2,
				'author_name' => 'Author',
				'author_url'  => '',
				'content'     => array( 'raw' => 'Trashed content' ),
				'status'      => 'trash',
				'date_gmt'    => '2026-09-14T08:03:00',
				'type'        => 'comment',
			)
		);
	}

	return new WP_AI_Bridge_Issue52_Test_Response( array(), array(), new WP_Error( 'unexpected_route', 'Unexpected test REST request.' ) );
}

function get_comment_type( $comment_id ) {
	return $GLOBALS['wpnb52_comment_types'][ (int) $comment_id ] ?? 'comment';
}
function wp_get_comment_status( $comment_id ) {
	return $GLOBALS['wpnb52_comment_statuses'][ (int) $comment_id ] ?? 'approved';
}

wpai_test_reset_state();
$settings = new Settings();
wpnb52_assert( 0 === $settings->defaults()[ Settings::GROUP_COMMENTS ], 'Comments must default off.' );
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] = array( Settings::GROUP_BUILDER_WRITE => 1 );
wpnb52_assert( 0 === $settings->all()[ Settings::GROUP_COMMENTS ], 'Historical write consent must not silently enable Comments.' );

$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] = $settings->defaults();
$GLOBALS['wpai_test']['capabilities'] = array(
	'read'              => true,
	'moderate_comments' => true,
	'edit_comment'      => true,
);
$permissions = new Permissions( $settings );
$log         = new Mutation_Log();
$comments    = new Comment_Abilities( $permissions, $log );
$registered  = $comments->register();
wpnb52_assert( 4 === count( $registered ), 'Exactly four bounded comment Abilities are registered.' );
foreach ( array( 'comments-read', 'comment-reply', 'comment-status', 'comment-delete' ) as $name ) {
	wpnb52_assert( isset( $GLOBALS['wpai_test']['registered_abilities'][ 'wp-ai-bridge/' . $name ] ), $name . ' is registered.' );
}

$read_ability = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/comments-read'];
wpnb52_assert( false === call_user_func( $read_ability['permission_callback'], array( 'action' => 'list' ) ), 'Disabled Comments group denies comment reads.' );
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_COMMENTS ] = 1;
wpnb52_assert( true === call_user_func( $read_ability['permission_callback'], array( 'action' => 'list' ) ), 'Enabled Comments group permits public read before Core route checks.' );

$public = $comments->read( array( 'action' => 'list', 'scope' => 'public', 'status' => 'approved', 'post' => 8, 'page' => 1, 'per_page' => 25 ) );
wpnb52_assert( ! is_wp_error( $public ) && 1 === count( $public['items'] ), 'Public comment list normalizes one Core REST item.' );
$item = $public['items'][0];
wpnb52_assert( strlen( $item['content'] ) <= Comment_Abilities::LIST_CONTENT_MAX_BYTES && true === $item['content_truncated'], 'List output byte-bounds large comment content and reports truncation.' );
foreach ( array( 'author_email', 'author_ip', 'user_agent', 'meta' ) as $private_key ) {
	wpnb52_assert( ! array_key_exists( $private_key, $item ), 'Comment output excludes private field ' . $private_key . '.' );
}
$last_request = end( $GLOBALS['wpnb52_rest_requests'] );
wpnb52_assert( 'GET' === $last_request['method'] && '/wp/v2/comments' === $last_request['route'], 'List uses only the fixed Core comments collection route.' );
wpnb52_assert( 'comment' === $last_request['params']['type'], 'List confines results to standard comments.' );
wpnb52_assert( 'approve' === $last_request['params']['status'], 'Approved list maps to Core query status.' );

$requests_before_unscoped_public = count( $GLOBALS['wpnb52_rest_requests'] );
$unscoped_public = $comments->read( array( 'action' => 'list', 'scope' => 'public', 'status' => 'approved' ) );
wpnb52_assert( is_wp_error( $unscoped_public ) && 'invalid_comment_input' === $unscoped_public->get_error_code(), 'Public list requires an exact post so Core pagination totals cannot span unreadable posts.' );
wpnb52_assert( $requests_before_unscoped_public === count( $GLOBALS['wpnb52_rest_requests'] ), 'Rejected unscoped public list performs no Core collection request.' );
$cross_post_parent = $comments->read( array( 'action' => 'list', 'scope' => 'public', 'status' => 'approved', 'post' => 8, 'parent' => 77 ) );
wpnb52_assert( is_wp_error( $cross_post_parent ) && 'invalid_comment_input' === $cross_post_parent->get_error_code(), 'Public parent filter rejects a readable parent from a different post.' );
$hidden_parent = $comments->read( array( 'action' => 'list', 'scope' => 'public', 'status' => 'approved', 'post' => 8, 'parent' => 99 ) );
wpnb52_assert( is_wp_error( $hidden_parent ) && 'invalid_comment_input' === $hidden_parent->get_error_code(), 'Public parent filter rejects a non-public parent instead of exposing aggregate information.' );

$bad_scope = $comments->read( array( 'action' => 'list', 'scope' => 'public', 'status' => 'spam' ) );
wpnb52_assert( is_wp_error( $bad_scope ) && 'comment_scope_requires_moderation' === $bad_scope->get_error_code(), 'Public scope cannot inspect non-public queues.' );
$private_exact = $comments->read( array( 'action' => 'get', 'scope' => 'public', 'id' => 99 ) );
wpnb52_assert( is_wp_error( $private_exact ) && 'comment_scope_requires_moderation' === $private_exact->get_error_code(), 'Public scope cannot retrieve one non-public comment even for a privileged principal.' );
$GLOBALS['wpai_test']['capabilities']['moderate_comments'] = false;
wpnb52_assert( false === call_user_func( $read_ability['permission_callback'], array( 'action' => 'list', 'scope' => 'moderation' ) ), 'Moderation read requires WordPress moderation capability.' );
$GLOBALS['wpai_test']['capabilities']['moderate_comments'] = true;

$reply_ability = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/comment-reply'];
$reply_schema  = $reply_ability['input_schema']['properties'];
foreach ( array( 'author', 'author_email', 'author_ip', 'status', 'meta', 'route', 'method', 'search' ) as $forbidden_input ) {
	wpnb52_assert( ! isset( $reply_schema[ $forbidden_input ] ), 'Reply schema excludes escalation input ' . $forbidden_input . '.' );
}
$reply = $comments->reply( array( 'post' => 8, 'parent' => 11, 'content' => 'A bounded reply.' ) );
wpnb52_assert( ! is_wp_error( $reply ) && 22 === $reply['id'], 'Reply returns normalized Core-created comment.' );
$last_request = end( $GLOBALS['wpnb52_rest_requests'] );
wpnb52_assert( 'POST' === $last_request['method'] && '/wp/v2/comments' === $last_request['route'], 'Reply uses only fixed Core create route.' );
wpnb52_assert( array( 'post', 'content', 'parent' ) === array_keys( $last_request['params'] ), 'Reply forwards only bounded post/content/parent parameters.' );

$status_ability = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/comment-status'];
$GLOBALS['wpnb52_comment_types'][11] = 'comment';
wpnb52_assert( true === call_user_func( $status_ability['permission_callback'], array( 'id' => 11, 'status' => 'spam' ) ), 'Status requires Comments plus WordPress moderation capability.' );
$status = $comments->status( array( 'id' => 11, 'status' => 'unspam' ) );
wpnb52_assert( ! is_wp_error( $status ) && 'unspam' === $status['status'], 'Status passes supported Core unspam lifecycle value unchanged.' );
$GLOBALS['wpnb52_comment_types'][12] = 'note';
$note_status = $comments->status( array( 'id' => 12, 'status' => 'approved' ) );
wpnb52_assert( is_wp_error( $note_status ) && 'comment_type_not_supported' === $note_status->get_error_code(), 'Comment mutations exclude Core/editor notes.' );

$delete_ability = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/comment-delete'];
$GLOBALS['wpnb52_comment_types'][13] = 'comment';
$GLOBALS['wpnb52_comment_statuses'][13] = 'approved';
wpnb52_assert( true === call_user_func( $delete_ability['permission_callback'], array( 'id' => 13, 'force' => false ) ), 'Trash requires Comments and exact edit_comment authority.' );
$trash_result = $comments->delete( array( 'id' => 13, 'force' => false ) );
wpnb52_assert( ! is_wp_error( $trash_result ) && true === $trash_result['trashed'] && false === $trash_result['deleted'], 'Non-force delete moves the comment to Trash.' );
$trash_request = end( $GLOBALS['wpnb52_rest_requests'] );
wpnb52_assert( 'POST' === $trash_request['method'] && '/wp/v2/comments/13' === $trash_request['route'] && 'trash' === $trash_request['params']['status'], 'Non-force delete uses only the Core status-update route, never DELETE.' );
wpnb52_assert( false === call_user_func( $delete_ability['permission_callback'], array( 'id' => 13, 'force' => true ) ), 'Permanent delete is denied without Users & Destructive.' );
$GLOBALS['wpnb52_comment_statuses'][13] = 'trash';
$requests_before_idempotent_trash = count( $GLOBALS['wpnb52_rest_requests'] );
$already_trashed = $comments->delete( array( 'id' => 13, 'force' => false ) );
wpnb52_assert( ! is_wp_error( $already_trashed ) && true === $already_trashed['trashed'] && false === $already_trashed['deleted'], 'Non-force delete is idempotent for an already trashed comment.' );
wpnb52_assert( $requests_before_idempotent_trash === count( $GLOBALS['wpnb52_rest_requests'] ), 'Already-trashed non-force delete never reaches Core delete and cannot become a permanent deletion.' );
$GLOBALS['wpnb52_comment_statuses'][13] = 'approved';
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
wpnb52_assert( true === call_user_func( $delete_ability['permission_callback'], array( 'id' => 13, 'force' => true ) ), 'Permanent delete requires both Comments and Users & Destructive.' );
$forced = $comments->delete( array( 'id' => 13, 'force' => true ) );
wpnb52_assert( ! is_wp_error( $forced ) && true === $forced['deleted'] && false === $forced['trashed'], 'Force delete requires Core confirmation and reports permanent deletion.' );

$entries = $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ] ?? array();
wpnb52_assert( ! empty( $entries ), 'Comment mutations produce bounded mutation-log entries.' );
$allowed_log_fields = array( 'timestamp', 'user_id', 'ability', 'target_type', 'target_id', 'success', 'error_code' );
foreach ( $entries as $entry ) {
	wpnb52_assert( array() === array_diff( array_keys( $entry ), $allowed_log_fields ), 'Mutation log stores metadata only.' );
	wpnb52_assert( ! isset( $entry['content'], $entry['author_email'], $entry['author_ip'] ), 'Mutation log excludes comment content and private author data.' );
}

$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_COMMENTS ] = 0;
$requests_before_revoked_execute = count( $GLOBALS['wpnb52_rest_requests'] );
$revoked_reply = $comments->reply( array( 'post' => 8, 'content' => 'must not dispatch' ) );
wpnb52_assert( is_wp_error( $revoked_reply ) && 'comment_reply_denied' === $revoked_reply->get_error_code(), 'Execute path re-checks Comments after revocation.' );
wpnb52_assert( $requests_before_revoked_execute === count( $GLOBALS['wpnb52_rest_requests'] ), 'Revoked execute path performs no Core REST mutation.' );
wpnb52_assert( false === call_user_func( $reply_ability['permission_callback'], array( 'post' => 8, 'content' => 'x' ) ), 'Disabling Comments immediately revokes reply permission.' );
wpnb52_assert( false === call_user_func( $status_ability['permission_callback'], array( 'id' => 11, 'status' => 'approved' ) ), 'Disabling Comments immediately revokes moderation permission.' );

if ( $failures ) {
	fwrite( STDERR, "Issue #52 comments: {$failures} failure(s) across {$tests} assertions.\n" );
	exit( 1 );
}

echo "PASS: Issue #52 bounded comment administration ({$tests} assertions).\n";
