<?php
/**
 * Bounded WordPress comment administration abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Provides a typed facade over the fixed Core comments REST contract.
 */
final class Comment_Abilities {
	const LIST_CONTENT_MAX_BYTES = 4096;
	const ITEM_CONTENT_MAX_BYTES = 32768;

	/** @var Permissions */
	private $permissions;

	/** @var Mutation_Log */
	private $log;

	/**
	 * Creates the comment Ability provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Bounded mutation logger.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/**
	 * Registers bounded comment administration abilities.
	 *
	 * @return array<int,object> Successfully registered Ability objects.
	 */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/comments-read',
			array(
				'label'               => __( 'Read Comments', 'wp-ai-bridge' ),
				'description'         => __( 'Lists or retrieves bounded WordPress comment data through the fixed Core comments REST contract.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-ai-bridge/comment-reply',
			array(
				'label'               => __( 'Reply to Comment', 'wp-ai-bridge' ),
				'description'         => __( 'Creates one reply through the fixed Core comments REST contract without exposing author, IP, status, or metadata overrides.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'parent'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'content' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 20000,
						),
					),
					'required'             => array( 'post', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->item_schema(),
				'execute_callback'    => array( $this, 'reply' ),
				'permission_callback' => array( $this, 'can_reply' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-ai-bridge/comment-status',
			array(
				'label'               => __( 'Moderate Comment', 'wp-ai-bridge' ),
				'description'         => __( 'Changes one comment moderation status through WordPress Core comment lifecycle APIs.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'status' => array(
							'type' => 'string',
							'enum' => array( 'approved', 'hold', 'spam', 'unspam', 'trash', 'untrash' ),
						),
					),
					'required'             => array( 'id', 'status' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->item_schema(),
				'execute_callback'    => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_status' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-ai-bridge/comment-delete',
			array(
				'label'               => __( 'Trash or Delete Comment', 'wp-ai-bridge' ),
				'description'         => __( 'Moves one comment to Trash or permanently deletes it when both Comments and Users & Destructive access are enabled.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'force' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
						'trashed' => array( 'type' => 'boolean' ),
						'status'  => array( 'type' => 'string' ),
					),
					'required'             => array( 'id', 'deleted', 'trashed', 'status' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/**
	 * Checks comment-read delegation.
	 *
	 * Moderation scope deliberately requires WordPress moderation authority in
	 * addition to the Bridge Comments grant so private queues are not exposed by
	 * a low-privilege principal.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public function can_read( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_COMMENTS, 'read' ) || ! is_array( $input ) ) {
			return false;
		}

		return 'moderation' !== ( $input['scope'] ?? 'public' ) || current_user_can( 'moderate_comments' );
	}

	/** @return bool */
	public function can_reply() {
		return $this->permissions->allowed( Settings::GROUP_COMMENTS, 'read' );
	}

	/** @return bool */
	public function can_status() {
		return $this->permissions->allowed( Settings::GROUP_COMMENTS, 'moderate_comments' );
	}

	/**
	 * Checks Trash/permanent-delete delegation.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public function can_delete( $input ) {
		if ( ! is_array( $input ) || empty( $input['id'] ) || ! $this->permissions->allowed( Settings::GROUP_COMMENTS, 'read' ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_comment', (int) $input['id'] ) ) {
			return false;
		}

		return empty( $input['force'] ) || $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' );
	}

	/**
	 * Lists or gets comments through the fixed Core REST route.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read( $input ) {
		if ( ! $this->can_read( $input ) ) {
			return new WP_Error( 'comment_read_denied', __( 'Comments access and the required WordPress permission are required to read comments.', 'wp-ai-bridge' ) );
		}

		$action = isset( $input['action'] ) ? (string) $input['action'] : 'list';
		$scope  = isset( $input['scope'] ) ? (string) $input['scope'] : 'public';
		if ( ! in_array( $action, array( 'list', 'get' ), true ) || ! in_array( $scope, array( 'public', 'moderation' ), true ) ) {
			return $this->invalid_input();
		}
		if ( 'moderation' === $scope && ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error( 'comment_moderation_denied', __( 'WordPress moderation permission is required to inspect non-public comment queues.', 'wp-ai-bridge' ) );
		}

		$context = 'moderation' === $scope ? 'edit' : 'view';
		if ( 'get' === $action ) {
			if ( empty( $input['id'] ) ) {
				return $this->invalid_input();
			}
			$result = $this->dispatch( 'GET', '/wp/v2/comments/' . (int) $input['id'], array( 'context' => $context ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$item = $this->normalize_item( $result['data'] );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			if ( 'public' === $scope && 'approved' !== $item['status'] ) {
				return new WP_Error( 'comment_scope_requires_moderation', __( 'Non-public comment statuses require moderation scope.', 'wp-ai-bridge' ) );
			}

			return array(
				'items'       => array( $item ),
				'page'        => 1,
				'per_page'    => 1,
				'total'       => 1,
				'total_pages' => 1,
			);
		}

		$status = isset( $input['status'] ) ? (string) $input['status'] : 'approved';
		if ( 'public' === $scope && 'approved' !== $status ) {
			return new WP_Error( 'comment_scope_requires_moderation', __( 'Non-public comment statuses require moderation scope.', 'wp-ai-bridge' ) );
		}
		if ( 'public' === $scope && empty( $input['post'] ) ) {
			return $this->invalid_input();
		}

		$post_id = ! empty( $input['post'] ) ? (int) $input['post'] : 0;
		if ( 'public' === $scope && ! empty( $input['parent'] ) ) {
			$parent_id = (int) $input['parent'];
			$parent    = $this->dispatch( 'GET', '/wp/v2/comments/' . $parent_id, array( 'context' => 'view' ) );
			if ( is_wp_error( $parent ) ) {
				return $this->invalid_input();
			}
			$parent_item = $this->normalize_item( $parent['data'] );
			if ( is_wp_error( $parent_item ) || 'approved' !== $parent_item['status'] || $post_id !== (int) $parent_item['post'] ) {
				return $this->invalid_input();
			}
		}

		$params = array(
			'context'  => $context,
			'page'     => isset( $input['page'] ) ? (int) $input['page'] : 1,
			'per_page' => isset( $input['per_page'] ) ? (int) $input['per_page'] : 25,
			'status'   => 'approved' === $status ? 'approve' : $status,
			'type'     => 'comment',
		);
		if ( $post_id > 0 ) {
			$params['post'] = array( $post_id );
		}
		if ( isset( $input['parent'] ) ) {
			$params['parent'] = array( (int) $input['parent'] );
		}
		if ( ! empty( $input['order'] ) ) {
			$params['order'] = (string) $input['order'];
		}

		$result = $this->dispatch( 'GET', '/wp/v2/comments', $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = array();
		foreach ( is_array( $result['data'] ) ? $result['data'] : array() as $raw ) {
			$item = $this->normalize_item( $raw, self::LIST_CONTENT_MAX_BYTES );
			if ( is_wp_error( $item ) ) {
				continue;
			}
			$items[] = $item;
		}
		$headers = $result['headers'];
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $items );
		$pages   = isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : ( $total > 0 ? 1 : 0 );

		return array(
			'items'       => $items,
			'page'        => $params['page'],
			'per_page'    => $params['per_page'],
			'total'       => $total,
			'total_pages' => $pages,
		);
	}

	/**
	 * Creates one reply through the Core comments REST route.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function reply( $input ) {
		if ( ! $this->can_reply() ) {
			return new WP_Error( 'comment_reply_denied', __( 'Comments access is required to reply to comments.', 'wp-ai-bridge' ) );
		}

		$params = array(
			'post'    => (int) $input['post'],
			'content' => (string) $input['content'],
		);
		if ( ! empty( $input['parent'] ) ) {
			$params['parent'] = (int) $input['parent'];
		}

		$result = $this->dispatch( 'POST', '/wp/v2/comments', $params );
		if ( is_wp_error( $result ) ) {
			$this->log->record( 'wp-ai-bridge/comment-reply', 'comment', 0, false, $result->get_error_code() );
			return $result;
		}
		$item = $this->normalize_item( $result['data'] );
		if ( is_wp_error( $item ) ) {
			$this->log->record( 'wp-ai-bridge/comment-reply', 'comment', 0, false, $item->get_error_code() );
			return $item;
		}
		$this->log->record( 'wp-ai-bridge/comment-reply', 'comment', $item['id'], true, '' );

		return $item;
	}

	/**
	 * Changes one comment status through the Core REST route.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function status( $input ) {
		if ( ! $this->can_status() ) {
			return new WP_Error( 'comment_status_denied', __( 'Comments access and WordPress moderation permission are required to change comment status.', 'wp-ai-bridge' ) );
		}

		$id = (int) $input['id'];
		if ( ! $this->is_comment_type( $id ) ) {
			return new WP_Error( 'comment_type_not_supported', __( 'The requested object is not a standard WordPress comment.', 'wp-ai-bridge' ) );
		}
		$result = $this->dispatch(
			'POST',
			'/wp/v2/comments/' . $id,
			array(
				'context' => 'edit',
				'status'  => (string) $input['status'],
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->log->record( 'wp-ai-bridge/comment-status', 'comment', $id, false, $result->get_error_code() );
			return $result;
		}
		$item = $this->normalize_item( $result['data'] );
		if ( is_wp_error( $item ) ) {
			$this->log->record( 'wp-ai-bridge/comment-status', 'comment', $id, false, $item->get_error_code() );
			return $item;
		}
		$this->log->record( 'wp-ai-bridge/comment-status', 'comment', $id, true, '' );

		return $item;
	}

	/**
	 * Trashes or permanently deletes one comment through the Core REST route.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete( $input ) {
		if ( ! $this->can_delete( $input ) ) {
			return new WP_Error( 'comment_delete_denied', __( 'Comments access and the required WordPress permission are required to trash or delete this comment.', 'wp-ai-bridge' ) );
		}

		$id    = (int) $input['id'];
		$force = ! empty( $input['force'] );
		if ( ! $this->is_comment_type( $id ) ) {
			return new WP_Error( 'comment_type_not_supported', __( 'The requested object is not a standard WordPress comment.', 'wp-ai-bridge' ) );
		}
		if ( $force && ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' ) ) {
			return new WP_Error( 'comment_force_delete_denied', __( 'Permanent comment deletion also requires Users & Destructive access.', 'wp-ai-bridge' ) );
		}
		if ( ! $force ) {
			if ( function_exists( 'wp_get_comment_status' ) && 'trash' === wp_get_comment_status( $id ) ) {
				return array(
					'id'      => $id,
					'deleted' => false,
					'trashed' => true,
					'status'  => 'trash',
				);
			}

			$result = $this->dispatch(
				'POST',
				'/wp/v2/comments/' . $id,
				array(
					'context' => 'edit',
					'status'  => 'trash',
				)
			);
			if ( is_wp_error( $result ) ) {
				$this->log->record( 'wp-ai-bridge/comment-delete', 'comment', $id, false, $result->get_error_code() );
				return $result;
			}
			$item = $this->normalize_item( is_array( $result['data'] ) ? $result['data'] : array() );
			if ( is_wp_error( $item ) || 'trash' !== $item['status'] ) {
				$error = is_wp_error( $item ) ? $item : new WP_Error( 'comment_trash_failed', __( 'WordPress did not confirm that the comment moved to Trash.', 'wp-ai-bridge' ) );
				$this->log->record( 'wp-ai-bridge/comment-delete', 'comment', $id, false, $error->get_error_code() );
				return $error;
			}
			$this->log->record( 'wp-ai-bridge/comment-delete', 'comment', $id, true, '' );

			return array(
				'id'      => $id,
				'deleted' => false,
				'trashed' => true,
				'status'  => 'trash',
			);
		}

		$result = $this->dispatch( 'DELETE', '/wp/v2/comments/' . $id, array( 'force' => true ) );
		if ( is_wp_error( $result ) ) {
			$this->log->record( 'wp-ai-bridge/comment-delete', 'comment', $id, false, $result->get_error_code() );
			return $result;
		}
		$data    = is_array( $result['data'] ) ? $result['data'] : array();
		$deleted = ! empty( $data['deleted'] );
		if ( ! $deleted ) {
			$error = new WP_Error( 'comment_delete_failed', __( 'WordPress did not confirm permanent comment deletion.', 'wp-ai-bridge' ) );
			$this->log->record( 'wp-ai-bridge/comment-delete', 'comment', $id, false, $error->get_error_code() );
			return $error;
		}
		$this->log->record( 'wp-ai-bridge/comment-delete', 'comment', $id, true, '' );

		return array(
			'id'      => $id,
			'deleted' => true,
			'trashed' => false,
			'status'  => 'deleted',
		);
	}

	/**
	 * Sends one internal request to a fixed Core comments route.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $route  Fixed Core route selected by Bridge code.
	 * @param array<string,mixed> $params Request parameters.
	 * @return array{data:mixed,headers:array<string,mixed>}|WP_Error
	 */
	private function dispatch( $method, $route, array $params ) {
		if ( ! class_exists( 'WP_REST_Request' ) || ! function_exists( 'rest_do_request' ) ) {
			return new WP_Error( 'comments_rest_unavailable', __( 'The WordPress comments REST contract is unavailable.', 'wp-ai-bridge' ) );
		}
		if ( ! in_array( $route, array( '/wp/v2/comments' ), true ) && ! preg_match( '#^/wp/v2/comments/[1-9][0-9]*$#', $route ) ) {
			return new WP_Error( 'comments_rest_route_denied', __( 'The requested internal REST route is not part of the bounded Comments contract.', 'wp-ai-bridge' ) );
		}

		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
			return new WP_Error( 'comments_rest_invalid_response', __( 'WordPress returned an invalid comments REST response.', 'wp-ai-bridge' ) );
		}
		if ( method_exists( $response, 'is_error' ) && $response->is_error() ) {
			return method_exists( $response, 'as_error' ) ? $response->as_error() : new WP_Error( 'comments_rest_request_failed', __( 'WordPress rejected the comments REST request.', 'wp-ai-bridge' ) );
		}

		return array(
			'data'    => $response->get_data(),
			'headers' => method_exists( $response, 'get_headers' ) && is_array( $response->get_headers() ) ? $response->get_headers() : array(),
		);
	}

	/**
	 * Returns a bounded comment representation without private transport fields.
	 *
	 * @param mixed $raw               Core REST response item.
	 * @param int   $content_max_bytes Maximum returned comment-content bytes.
	 * @return array<string,mixed>|WP_Error
	 */
	private function normalize_item( $raw, $content_max_bytes = self::ITEM_CONTENT_MAX_BYTES ) {
		if ( ! is_array( $raw ) || empty( $raw['id'] ) || ( isset( $raw['type'] ) && 'comment' !== $raw['type'] ) ) {
			return new WP_Error( 'comment_contract_invalid', __( 'WordPress returned an unsupported comment representation.', 'wp-ai-bridge' ) );
		}
		$content = '';
		if ( isset( $raw['content'] ) && is_array( $raw['content'] ) ) {
			if ( isset( $raw['content']['raw'] ) && is_string( $raw['content']['raw'] ) ) {
				$content = $raw['content']['raw'];
			} elseif ( isset( $raw['content']['rendered'] ) && is_string( $raw['content']['rendered'] ) ) {
				$content = $raw['content']['rendered'];
			}
		} elseif ( isset( $raw['content'] ) && is_string( $raw['content'] ) ) {
			$content = $raw['content'];
		}
		$bounded_content = $this->bounded_utf8( $content, (int) $content_max_bytes );

		return array(
			'id'                => (int) $raw['id'],
			'post'              => isset( $raw['post'] ) ? (int) $raw['post'] : 0,
			'parent'            => isset( $raw['parent'] ) ? (int) $raw['parent'] : 0,
			'author_id'         => isset( $raw['author'] ) ? (int) $raw['author'] : 0,
			'author_name'       => isset( $raw['author_name'] ) ? (string) $raw['author_name'] : '',
			'author_url'        => isset( $raw['author_url'] ) ? (string) $raw['author_url'] : '',
			'content'           => $bounded_content,
			'content_truncated' => strlen( $bounded_content ) < strlen( $content ),
			'status'            => isset( $raw['status'] ) ? (string) $raw['status'] : '',
			'date_gmt'          => isset( $raw['date_gmt'] ) ? (string) $raw['date_gmt'] : '',
			'type'              => 'comment',
		);
	}

	/**
	 * Returns a UTF-8-safe byte-bounded prefix.
	 *
	 * @param string $value     Input text.
	 * @param int    $max_bytes Maximum bytes.
	 * @return string
	 */
	private function bounded_utf8( $value, $max_bytes ) {
		$value     = (string) $value;
		$max_bytes = max( 0, (int) $max_bytes );
		if ( strlen( $value ) <= $max_bytes ) {
			return $value;
		}

		$bounded = substr( $value, 0, $max_bytes );
		while ( '' !== $bounded && 1 !== preg_match( '//u', $bounded ) ) {
			$bounded = substr( $bounded, 0, -1 );
		}

		return $bounded;
	}

	/**
	 * Confines mutations to standard comments, excluding Core/editor notes.
	 *
	 * @param int $id Comment ID.
	 * @return bool
	 */
	private function is_comment_type( $id ) {
		return function_exists( 'get_comment_type' ) && 'comment' === get_comment_type( (int) $id );
	}

	/** @return WP_Error */
	private function invalid_input() {
		return new WP_Error( 'invalid_comment_input', __( 'Use a supported bounded Comments action and input contract.', 'wp-ai-bridge' ) );
	}

	/** @return array<string,mixed> */
	private function read_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'   => array(
					'type'    => 'string',
					'enum'    => array( 'list', 'get' ),
					'default' => 'list',
				),
				'id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'scope'    => array(
					'type'    => 'string',
					'enum'    => array( 'public', 'moderation' ),
					'default' => 'public',
				),
				'post'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'parent'   => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'status'   => array(
					'type'    => 'string',
					'enum'    => array( 'approved', 'hold', 'spam', 'trash' ),
					'default' => 'approved',
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 25,
				),
				'order'    => array(
					'type'    => 'string',
					'enum'    => array( 'asc', 'desc' ),
					'default' => 'desc',
				),
			),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function read_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items'       => array(
					'type'  => 'array',
					'items' => $this->item_schema(),
				),
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
				'total'       => array( 'type' => 'integer' ),
				'total_pages' => array( 'type' => 'integer' ),
			),
			'required'             => array( 'items', 'page', 'per_page', 'total', 'total_pages' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function item_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                => array( 'type' => 'integer' ),
				'post'              => array( 'type' => 'integer' ),
				'parent'            => array( 'type' => 'integer' ),
				'author_id'         => array( 'type' => 'integer' ),
				'author_name'       => array( 'type' => 'string' ),
				'author_url'        => array( 'type' => 'string' ),
				'content'           => array( 'type' => 'string' ),
				'content_truncated' => array( 'type' => 'boolean' ),
				'status'            => array( 'type' => 'string' ),
				'date_gmt'          => array( 'type' => 'string' ),
				'type'              => array(
					'type' => 'string',
					'enum' => array( 'comment' ),
				),
			),
			'required'             => array( 'id', 'post', 'parent', 'author_id', 'author_name', 'author_url', 'content', 'content_truncated', 'status', 'date_gmt', 'type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns MCP exposure metadata.
	 *
	 * @param bool $is_readonly Read-only annotation.
	 * @param bool $destructive Destructive annotation.
	 * @param bool $idempotent  Idempotent annotation.
	 * @return array<string,mixed>
	 */
	private function meta( $is_readonly, $destructive, $idempotent ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => (bool) $is_readonly,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent,
			),
		);
	}
}
