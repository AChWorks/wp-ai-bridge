<?php
/**
 * Core content abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Provides generic post/page/editable-CPT operations and revision handling.
 */
final class Content_Abilities {
	/**
	 * Bridge permission service.
	 *
	 * @var Permissions
	 */
	private $permissions;

	/**
	 * Bounded mutation logger.
	 *
	 * @var Mutation_Log
	 */
	private $log;

	/**
	 * Creates the content Ability provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Mutation logger.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/**
	 * Registers the Bridge-owned generic content abilities.
	 *
	 * @return void
	 */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/content-read',
			array(
				'label'               => __( 'Read Content', 'wp-ai-bridge' ),
				'description'         => __( 'Lists or retrieves posts, pages, and editable custom post types through WordPress APIs.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/content-upsert',
			array(
				'label'               => __( 'Create or Update Content', 'wp-ai-bridge' ),
				'description'         => __( 'Creates or updates one WordPress content object with access-group, capability, and stale-write checks.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->upsert_input_schema(),
				'output_schema'       => $this->item_schema( true ),
				'execute_callback'    => array( $this, 'upsert' ),
				'permission_callback' => array( $this, 'can_upsert' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/content-delete',
			array(
				'label'               => __( 'Trash or Delete Content', 'wp-ai-bridge' ),
				'description'         => __( 'Moves content to Trash or permanently deletes it when destructive access is enabled.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->delete_input_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
						'trashed' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'id', 'deleted', 'trashed' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/revisions-read',
			array(
				'label'               => __( 'Read Content Revisions', 'wp-ai-bridge' ),
				'description'         => __( 'Lists bounded WordPress revision metadata or reads one revision through hash-guarded UTF-8 content and title/excerpt windows.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'               => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'limit'                 => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 10,
						),
						'include_content'       => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'offset'                => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100000,
						),
						'revision_id'           => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'content_offset'        => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'content_max_bytes'     => array(
							'type'    => 'integer',
							'minimum' => 4,
							'maximum' => Bounded_Payload::TEXT_WINDOW_BYTES,
						),
						'expected_content_hash' => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
						'text_field'            => array(
							'type' => 'string',
							'enum' => array( 'title', 'excerpt' ),
						),
						'text_offset'           => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'text_max_bytes'        => array(
							'type'    => 'integer',
							'minimum' => 4,
							'maximum' => Bounded_Payload::TEXT_WINDOW_BYTES,
						),
						'expected_text_hash'    => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => $this->revision_schema(),
				),
				'execute_callback'    => array( $this, 'read_revisions' ),
				'permission_callback' => array( $this, 'can_read_revisions' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/revision-restore',
			array(
				'label'               => __( 'Restore Content Revision', 'wp-ai-bridge' ),
				'description'         => __( 'Restores a WordPress revision after checking the expected current content identity.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'               => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'revision_id'           => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'expected_modified_gmt' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'expected_state_hash'   => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
					),
					'required'             => array( 'post_id', 'revision_id', 'expected_modified_gmt', 'expected_state_hash' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->item_schema( true ),
				'execute_callback'    => array( $this, 'restore_revision' ),
				'permission_callback' => array( $this, 'can_restore_revision' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/**
	 * Checks permission for content reads.
	 *
	 * @return bool Whether the current user may read content.
	 */
	public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
	}

	/**
	 * Checks permission for content creation and updates.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether the current user may perform the requested mutation.
	 */
	public function can_upsert( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'read' ) || ! is_array( $input ) ) {
			return false;
		}

		$action = isset( $input['action'] ) ? (string) $input['action'] : '';
		$status = isset( $input['status'] ) ? (string) $input['status'] : '';
		if ( '' !== $status && ! $this->valid_authoring_status( $status ) ) {
			return false;
		}

		if ( 'create' === $action ) {
			$type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';
			$obj  = $this->editable_post_type( $type );
			if ( ! $obj ) {
				return false;
			}
			$create_cap = isset( $obj->cap->create_posts ) ? $obj->cap->create_posts : $obj->cap->edit_posts;
			if ( ! current_user_can( $create_cap ) ) {
				return false;
			}
			return ! $this->requires_live_access( $status ) || $this->can_publish_type( $obj );
		}

		if ( 'update' !== $action || empty( $input['id'] ) ) {
			return false;
		}

		$post = get_post( (int) $input['id'] );
		if ( ! $post || ! $this->editable_post_type( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		if ( $this->requires_live_access( $post->post_status ) || $this->requires_live_access( $status ) ) {
			return $this->can_publish_type( get_post_type_object( $post->post_type ) );
		}

		return true;
	}

	/**
	 * Checks permission for content trash/delete operations.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether the current user may delete the target.
	 */
	public function can_delete( $input ) {
		if ( ! is_array( $input ) || empty( $input['id'] ) ) {
			return false;
		}
		return $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' )
			&& current_user_can( 'delete_post', (int) $input['id'] );
	}

	/**
	 * Checks permission for revision inspection. WordPress Core protects a post's
	 * historical (potentially unpublished) revisions with edit_post, not read_post.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether the current user may read revisions.
	 */
	public function can_read_revisions( $input ) {
		return is_array( $input )
			&& ! empty( $input['post_id'] )
			&& $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' )
			&& current_user_can( 'edit_post', (int) $input['post_id'] );
	}

	/**
	 * Checks permission for revision restoration.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether the current user may restore the revision.
	 */
	public function can_restore_revision( $input ) {
		if ( ! is_array( $input ) || empty( $input['post_id'] ) ) {
			return false;
		}
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post || ! $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'read' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}
		return ! $this->requires_live_access( $post->post_status ) || $this->can_publish_type( get_post_type_object( $post->post_type ) );
	}

	/**
	 * Reads content.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read( $input ) {
		$action          = isset( $input['action'] ) ? (string) $input['action'] : 'list';
		$include_content = ! empty( $input['include_content'] );

		if ( 'get' === $action ) {
			$post = null;
			if ( ! empty( $input['id'] ) ) {
				$post = get_post( (int) $input['id'] );
			} elseif ( ! empty( $input['slug'] ) && ! empty( $input['post_type'] ) ) {
				$post = get_page_by_path( (string) $input['slug'], OBJECT, (string) $input['post_type'] );
			}

			if ( ! $post || ! $this->editable_post_type( $post->post_type ) || ! current_user_can( 'read_post', $post->ID ) ) {
				return new WP_Error( 'content_not_found', __( 'The requested content is not available.', 'wp-ai-bridge' ) );
			}

			$content  = (string) $post->post_content;
			$identity = hash( 'sha256', $content );
			$offset   = isset( $input['content_offset'] ) ? (int) $input['content_offset'] : 0;
			$windowed = isset( $input['content_offset'] ) || isset( $input['content_max_bytes'] );
			if ( $offset > 0 && empty( $input['expected_content_hash'] ) ) {
				return new WP_Error( 'content_identity_required', __( 'A resumed content read requires expected_content_hash from the previous response.', 'wp-ai-bridge' ) );
			}
			if ( isset( $input['expected_content_hash'] ) && ! hash_equals( $identity, (string) $input['expected_content_hash'] ) ) {
				return new WP_Error( 'stale_content_conflict', __( 'The content changed while reading its windows; restart from offset zero.', 'wp-ai-bridge' ) );
			}

			if ( isset( $input['text_field'] ) ) {
				return $this->read_text_field( $post, $input );
			}

			return $this->read_single_content( $post, $input, $content, $offset, $windowed );
		}

		$type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
		$obj  = $this->editable_post_type( $type );
		if ( ! $obj ) {
			return new WP_Error( 'unsupported_post_type', __( 'The requested post type is not available for Bridge content operations.', 'wp-ai-bridge' ) );
		}

		$can_edit = current_user_can( $obj->cap->edit_posts );
		$status   = isset( $input['status'] ) && '' !== $input['status'] ? (string) $input['status'] : ( $can_edit ? 'any' : 'publish' );
		if ( ! $can_edit && 'publish' !== $status ) {
			return new WP_Error( 'content_status_forbidden', __( 'The current user may only list published content for this post type.', 'wp-ai-bridge' ) );
		}

		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 50, (int) $input['per_page'] ) ) : 20;
		$args     = array(
			'post_type'      => $type,
			'post_status'    => $status,
			'paged'          => $page,
			'posts_per_page' => $per_page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}

		$query         = new \WP_Query( $args );
		$items         = array();
		$compact_items = array();
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'read_post', $post->ID ) ) {
				$item    = $this->format_post( $post, false );
				$content = (string) $post->post_content;
				if ( $include_content ) {
					$item['content_total_bytes'] = strlen( $content );
					$item['content_next_offset'] = 0;
					$item['content_complete']    = false;
					if ( strlen( $content ) <= Bounded_Payload::TEXT_WINDOW_BYTES ) {
						$item['content']             = $content;
						$item['content_next_offset'] = strlen( $content );
						$item['content_complete']    = true;
					}
				}
				$items[] = $item;
				$compact = $this->compact_post_identity( $post );
				if ( $include_content ) {
					$compact['content_total_bytes'] = strlen( $content );
					$compact['content_next_offset'] = 0;
					$compact['content_complete']    = '' === $content;
				}
				$compact_items[] = $compact;
			}
		}

		$result = array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
		if ( Bounded_Payload::fits( $result ) ) {
			return $result;
		}
		$result['items'] = $compact_items;
		return Bounded_Payload::fits( $result ) ? $result : $this->oversized_content_error();
	}

	/**
	 * Creates or updates content.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function upsert( $input ) {
		if ( is_array( $input ) && 'create' === ( $input['action'] ?? '' ) && array_key_exists( 'operation_id', $input ) ) {
			return \WP_AI_Bridge\Support\Create_Claim::run(
				'content-create',
				$input,
				array( $this, 'can_upsert' ),
				function ( $clean ) {
					return $this->upsert( $clean ); },
				function ( $id, $clean ) {
					$post = get_post( $id );
					return $post && $post->post_type === $clean['post_type'] && current_user_can( 'edit_post', $id )
						? $this->format_post_after_mutation( $post ) : null;
				}
			);
		}
		if ( is_array( $input ) && array_key_exists( 'operation_id', $input ) ) {
			return new WP_Error( 'create_claim_invalid', __( 'operation_id is only supported for create.', 'wp-ai-bridge' ) );
		}
		$ability = 'wp-ai-bridge/content-upsert';
		$action  = (string) $input['action'];
		$id      = ! empty( $input['id'] ) ? (int) $input['id'] : 0;
		$post    = $id ? get_post( $id ) : null;

		if ( 'update' === $action && ! $post ) {
			return $this->logged_error( $ability, 'content_not_found', __( 'The content to update does not exist.', 'wp-ai-bridge' ), $id );
		}

		if ( isset( $input['status'] ) && ! $this->valid_authoring_status( (string) $input['status'] ) ) {
			return $this->logged_error( $ability, 'invalid_content_status', __( 'status must be a registered authoring status and cannot be a destructive or internal status.', 'wp-ai-bridge' ), $id );
		}

		$type = 'create' === $action ? (string) $input['post_type'] : $post->post_type;
		if ( ! $this->editable_post_type( $type ) ) {
			return $this->logged_error( $ability, 'unsupported_post_type', __( 'The requested post type is not available for Bridge content operations.', 'wp-ai-bridge' ), $id );
		}

		$featured_media = null;
		if ( array_key_exists( 'featured_media', $input ) ) {
			$featured_media = (int) $input['featured_media'];
			if ( 0 !== $featured_media ) {
				$attachment = get_post( $featured_media );
				if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $featured_media ) ) {
					return $this->logged_error( $ability, 'invalid_featured_media', __( 'featured_media must reference an existing image attachment.', 'wp-ai-bridge' ), $id );
				}
			}
		}

		$data = array();
		if ( 'create' === $action ) {
			$data['post_type']   = $type;
			$data['post_status'] = isset( $input['status'] ) ? (string) $input['status'] : 'draft';
		} else {
			$data['ID'] = $id;
		}

		$map = array(
			'title'      => 'post_title',
			'content'    => 'post_content',
			'excerpt'    => 'post_excerpt',
			'status'     => 'post_status',
			'parent_id'  => 'post_parent',
			'menu_order' => 'menu_order',
		);
		foreach ( $map as $input_key => $post_key ) {
			if ( array_key_exists( $input_key, $input ) ) {
				$data[ $post_key ] = $input[ $input_key ];
			}
		}
		if ( array_key_exists( 'slug', $input ) ) {
			$data['post_name'] = sanitize_title( (string) $input['slug'] );
		}
		if ( array_key_exists( 'template', $input ) ) {
			$data['page_template'] = (string) $input['template'];
		}

		if ( 'update' === $action ) {
			$post     = get_post( $id );
			$conflict = $this->check_expected_identity(
				$post,
				isset( $input['expected_modified_gmt'] ) ? $input['expected_modified_gmt'] : '',
				isset( $input['expected_state_hash'] ) ? $input['expected_state_hash'] : ''
			);
			if ( is_wp_error( $conflict ) ) {
				$this->log->record( $ability, 'post', $id, false, $conflict->get_error_code() );
				return $conflict;
			}
		}

		$result = 'create' === $action ? wp_insert_post( $data, true ) : wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			$this->log->record( $ability, 'post', $id, false, $result->get_error_code() );
			return $result;
		}
		$id = (int) $result;

		if ( null !== $featured_media ) {
			if ( 0 === $featured_media ) {
				delete_post_thumbnail( $id );
			} elseif ( ! set_post_thumbnail( $id, $featured_media ) ) {
				$this->log->record( $ability, 'post', $id, false, 'featured_media_failed' );
				return new WP_Error(
					'featured_media_failed',
					__( 'The content was saved, but WordPress could not assign the featured media.', 'wp-ai-bridge' ),
					array(
						'content_saved' => true,
						'id'            => $id,
					)
				);
			}
		}

		$this->log->record( $ability, 'post', $id, true, '' );
		return $this->format_post_after_mutation( get_post( $id ) );
	}

	/**
	 * Trashes or permanently deletes content.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete( $input ) {
		$ability = 'wp-ai-bridge/content-delete';
		$id      = (int) $input['id'];
		$post    = get_post( $id );
		if ( ! $post || ! $this->editable_post_type( $post->post_type ) ) {
			return $this->logged_error( $ability, 'content_not_found', __( 'The requested content does not exist.', 'wp-ai-bridge' ), $id );
		}

		$force  = ! empty( $input['force'] );
		$result = $force ? wp_delete_post( $id, true ) : wp_trash_post( $id );
		if ( ! $result ) {
			return $this->logged_error( $ability, 'content_delete_failed', __( 'WordPress could not trash or delete the content.', 'wp-ai-bridge' ), $id );
		}

		$this->log->record( $ability, 'post', $id, true, '' );
		return array(
			'id'      => $id,
			'deleted' => $force,
			'trashed' => ! $force,
		);
	}

	/**
	 * Reads revisions.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function read_revisions( $input ) {
		// Each request, including a continuation, must pass the CURRENT parent-post authority.
		if ( ! $this->can_read_revisions( $input ) ) {
			return new WP_Error( 'revision_read_forbidden', __( 'Revision access is no longer permitted.', 'wp-ai-bridge' ) );
		}
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'content_not_found', __( 'The requested content does not exist.', 'wp-ai-bridge' ) );
		}

		if ( isset( $input['revision_id'] ) ) {
			$revision_id = (int) $input['revision_id'];
			$revision    = wp_get_post_revision( $revision_id );
			if ( ! $revision || (int) $revision->post_parent !== (int) $post->ID ) {
				return new WP_Error( 'revision_not_found', __( 'The requested revision does not belong to this content object.', 'wp-ai-bridge' ) );
			}
			return $this->read_selected_revision( $revision, $input );
		}

		foreach ( array( 'content_offset', 'content_max_bytes', 'expected_content_hash', 'text_field', 'text_offset', 'text_max_bytes', 'expected_text_hash' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				return new WP_Error( 'revision_selector_required', __( 'Choose an exact revision_id to read content or text windows.', 'wp-ai-bridge' ) );
			}
		}

		$limit     = isset( $input['limit'] ) ? max( 1, min( 50, (int) $input['limit'] ) ) : 10;
		$offset    = isset( $input['offset'] ) ? max( 0, min( 100000, (int) $input['offset'] ) ) : 0;
		$include   = ! empty( $input['include_content'] );
		$revisions = wp_get_post_revisions(
			$post->ID,
			array(
				'posts_per_page' => $limit,
				'offset'         => $offset,
			)
		);
		$items     = array();
		$compact   = array();

		foreach ( $revisions as $revision ) {
			if ( (int) $revision->post_parent !== (int) $post->ID || ! $this->can_read_revisions( $input ) ) {
				return new WP_Error( 'revision_read_forbidden', __( 'Revision access is no longer permitted.', 'wp-ai-bridge' ) );
			}
			$body       = (string) $revision->post_content;
			$large_meta = strlen( (string) $revision->post_title ) > Bounded_Payload::TEXT_WINDOW_BYTES || strlen( (string) $revision->post_excerpt ) > Bounded_Payload::TEXT_WINDOW_BYTES;
			$item       = $large_meta ? $this->compact_revision( $revision, $include ) : $this->format_revision( $revision, false );
			if ( $include && ! $large_meta ) {
				if ( strlen( $body ) <= Bounded_Payload::TEXT_WINDOW_BYTES ) {
					$item['content'] = $body;
				} else {
					$item['content_total_bytes']  = strlen( $body );
					$item['content_next_offset']  = 0;
					$item['content_complete']     = false;
					$item['projection_truncated'] = true;
					$item['omitted_fields']       = array( 'content' );
				}
			}
			$items[]   = $item;
			$compact[] = $this->compact_revision( $revision, $include );
		}
		if ( Bounded_Payload::fits( $items ) ) {
			return $items;
		}
		return Bounded_Payload::fits( $compact ) ? $compact : $this->revision_oversized_error();
	}

	/**
	 * Reads one revision, with explicit byte-safe body/metadata continuation.
	 *
	 * @param object              $revision Authorized, exact-parent revision.
	 * @param array<string,mixed> $input    Validated selector and optional window.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function read_selected_revision( $revision, $input ) {
		if ( isset( $input['text_field'] ) ) {
			if ( ! empty( $input['include_content'] ) || isset( $input['content_offset'] ) || isset( $input['content_max_bytes'] ) || isset( $input['expected_content_hash'] ) ) {
				return new WP_Error( 'invalid_revision_query', __( 'Select either a revision text field or its content, not both.', 'wp-ai-bridge' ) );
			}
			return $this->read_revision_text_window( $revision, $input );
		}
		foreach ( array( 'text_offset', 'text_max_bytes', 'expected_text_hash' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				return new WP_Error( 'invalid_revision_query', __( 'Choose text_field before requesting a revision text window.', 'wp-ai-bridge' ) );
			}
		}

		$include = ! empty( $input['include_content'] );
		if ( ! $include ) {
			if ( isset( $input['content_offset'] ) || isset( $input['content_max_bytes'] ) || isset( $input['expected_content_hash'] ) ) {
				return new WP_Error( 'invalid_revision_query', __( 'Set include_content to read revision content windows.', 'wp-ai-bridge' ) );
			}
			$large_meta = strlen( (string) $revision->post_title ) > Bounded_Payload::TEXT_WINDOW_BYTES || strlen( (string) $revision->post_excerpt ) > Bounded_Payload::TEXT_WINDOW_BYTES;
			$item       = $large_meta ? $this->compact_revision( $revision, false ) : $this->format_revision( $revision, false );
			if ( Bounded_Payload::fits( array( $item ) ) ) {
				return array( $item );
			}
			$item = $this->compact_revision( $revision, false );
			return Bounded_Payload::fits( array( $item ) ) ? array( $item ) : $this->revision_oversized_error();
		}

		$content = (string) $revision->post_content;
		$hash    = hash( 'sha256', $content );
		$offset  = isset( $input['content_offset'] ) ? (int) $input['content_offset'] : 0;
		$limit   = isset( $input['content_max_bytes'] ) ? (int) $input['content_max_bytes'] : Bounded_Payload::TEXT_WINDOW_BYTES;
		if ( $offset > 0 && empty( $input['expected_content_hash'] ) ) {
			return new WP_Error( 'revision_identity_required', __( 'A resumed revision read requires its expected_content_hash.', 'wp-ai-bridge' ) );
		}
		if ( isset( $input['expected_content_hash'] ) && ! hash_equals( $hash, (string) $input['expected_content_hash'] ) ) {
			return new WP_Error( 'stale_revision_conflict', __( 'The selected revision content changed; restart from offset zero.', 'wp-ai-bridge' ) );
		}

		// Existing callers retain complete small-body behavior; selectors always report windows.
		if ( ! isset( $input['content_offset'] ) && ! isset( $input['content_max_bytes'] ) &&
			strlen( $content ) <= Bounded_Payload::TEXT_WINDOW_BYTES &&
			strlen( (string) $revision->post_title ) <= Bounded_Payload::TEXT_WINDOW_BYTES &&
			strlen( (string) $revision->post_excerpt ) <= Bounded_Payload::TEXT_WINDOW_BYTES ) {
			$item                        = $this->format_revision( $revision, true );
			$item['content_total_bytes'] = strlen( $content );
			$item['content_next_offset'] = strlen( $content );
			$item['content_complete']    = true;
			if ( Bounded_Payload::fits( array( $item ) ) ) {
				return array( $item );
			}
		}

		$item = $this->compact_revision( $revision, true );
		for ( $size = $limit; $size >= 4; $size = max( 3, (int) floor( $size / 2 ) ) ) {
			$window = Bounded_Payload::text_window( $content, $offset, $size );
			if ( is_wp_error( $window ) ) {
				return $window;
			}
			$item['content']             = $window['content'];
			$item['content_total_bytes'] = $window['total_bytes'];
			$item['content_next_offset'] = $window['next_offset'];
			$item['content_complete']    = $window['complete'];
			if ( $window['complete'] ) {
				$item['omitted_fields'] = array_values( array_diff( $item['omitted_fields'], array( 'content' ) ) );
			}
			if ( Bounded_Payload::fits( array( $item ) ) ) {
				return array( $item );
			}
		}
		return $this->revision_oversized_error();
	}

	/**
	 * Reads one giant title/excerpt field without inline or silent truncation.
	 *
	 * @param object              $revision Selected revision.
	 * @param array<string,mixed> $input    Text field selection.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function read_revision_text_window( $revision, $input ) {
		$field = (string) $input['text_field'];
		if ( ! in_array( $field, array( 'title', 'excerpt' ), true ) ) {
			return new WP_Error( 'invalid_revision_query', __( 'Only revision title or excerpt windows are supported.', 'wp-ai-bridge' ) );
		}
		$value  = (string) ( 'title' === $field ? $revision->post_title : $revision->post_excerpt );
		$hash   = hash( 'sha256', $value );
		$offset = isset( $input['text_offset'] ) ? (int) $input['text_offset'] : 0;
		$limit  = isset( $input['text_max_bytes'] ) ? (int) $input['text_max_bytes'] : Bounded_Payload::TEXT_WINDOW_BYTES;
		if ( $offset > 0 && empty( $input['expected_text_hash'] ) ) {
			return new WP_Error( 'revision_identity_required', __( 'A resumed revision text read requires its expected_text_hash.', 'wp-ai-bridge' ) );
		}
		if ( isset( $input['expected_text_hash'] ) && ! hash_equals( $hash, (string) $input['expected_text_hash'] ) ) {
			return new WP_Error( 'stale_revision_conflict', __( 'The selected revision text changed; restart from offset zero.', 'wp-ai-bridge' ) );
		}
		$item = $this->compact_revision( $revision, false );
		for ( $size = $limit; $size >= 4; $size = max( 3, (int) floor( $size / 2 ) ) ) {
			$window = Bounded_Payload::text_window( $value, $offset, $size );
			if ( is_wp_error( $window ) ) {
				return $window;
			}
			$item['text_field']       = $field;
			$item['text_hash']        = $hash;
			$item['text_chunk']       = $window['content'];
			$item['text_total_bytes'] = $window['total_bytes'];
			$item['text_next_offset'] = $window['next_offset'];
			$item['text_complete']    = $window['complete'];
			if ( Bounded_Payload::fits( array( $item ) ) ) {
				return array( $item );
			}
		}
		return $this->revision_oversized_error();
	}

	/**
	 * Small fixed revision identity for a long historical record or list.
	 *
	 * @param object $revision Revision object.
	 * @param bool   $include_content Whether caller also requested its content.
	 * @return array<string,mixed> Bounded projection with explicit omissions.
	 */
	private function compact_revision( $revision, $include_content ) {
		$title   = (string) $revision->post_title;
		$excerpt = (string) $revision->post_excerpt;
		$content = (string) $revision->post_content;
		$fields  = array( 'title', 'excerpt' );
		if ( $include_content ) {
			$fields[] = 'content';
		}
		return array(
			'id'                   => (int) $revision->ID,
			'parent_id'            => (int) $revision->post_parent,
			'date_gmt'             => (string) $revision->post_date_gmt,
			'modified_gmt'         => (string) $revision->post_modified_gmt,
			'author_id'            => (int) $revision->post_author,
			'content_hash'         => hash( 'sha256', $content ),
			'content_total_bytes'  => strlen( $content ),
			'title_hash'           => hash( 'sha256', $title ),
			'title_total_bytes'    => strlen( $title ),
			'excerpt_hash'         => hash( 'sha256', $excerpt ),
			'excerpt_total_bytes'  => strlen( $excerpt ),
			'projection_truncated' => true,
			'omitted_fields'       => $fields,
		);
	}

	/** @return WP_Error */
	private function revision_oversized_error() {
		return new WP_Error( 'revision_response_too_large', __( 'Revision results exceed the MCP response budget. Reduce limit, select revision_id, or use content/text windows.', 'wp-ai-bridge' ) );
	}

	/**
	 * Restores a revision.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function restore_revision( $input ) {
		$ability     = 'wp-ai-bridge/revision-restore';
		$post_id     = (int) $input['post_id'];
		$revision_id = (int) $input['revision_id'];
		$post        = get_post( $post_id );
		$revision    = wp_get_post_revision( $revision_id );
		if ( ! $post || ! $revision || (int) $revision->post_parent !== $post_id ) {
			return $this->logged_error( $ability, 'revision_not_found', __( 'The requested revision does not belong to this content object.', 'wp-ai-bridge' ), $post_id );
		}
		$post     = get_post( $post_id );
		$conflict = $this->check_expected_identity( $post, (string) $input['expected_modified_gmt'], (string) $input['expected_state_hash'] );
		if ( is_wp_error( $conflict ) ) {
			$this->log->record( $ability, 'post', $post_id, false, $conflict->get_error_code() );
			return $conflict;
		}

		$result = wp_restore_post_revision( $revision_id );
		if ( ! $result ) {
			return $this->logged_error( $ability, 'revision_restore_failed', __( 'WordPress could not restore the requested revision.', 'wp-ai-bridge' ), $post_id );
		}
		$this->log->record( $ability, 'post', $post_id, true, '' );
		return $this->format_post_after_mutation( get_post( $post_id ) );
	}

	/**
	 * Checks the Live Content group and the post type publish capability.
	 *
	 * @param object $obj Post type object.
	 * @return bool Whether live mutation is permitted.
	 */
	private function can_publish_type( $obj ) {
		return $obj
			&& isset( $obj->cap->publish_posts )
			&& $this->permissions->allowed( Settings::GROUP_LIVE_CONTENT, $obj->cap->publish_posts );
	}

	/**
	 * Determines whether a status transition requires Live Content access.
	 *
	 * Only WordPress' known non-live authoring statuses are treated as builder-only.
	 * Unknown/custom statuses are conservatively gated as live because providers may
	 * expose them publicly or attach consequential workflow semantics to them.
	 *
	 * @param string $status Post status.
	 * @return bool
	 */
	private function requires_live_access( $status ) {
		$status = (string) $status;
		if ( '' === $status ) {
			return false;
		}

		return ! in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true );
	}

	/**
	 * Resolves a post type that is safe for generic Bridge editing.
	 *
	 * @param string $type Post type name.
	 * @return object|null Editable post-type object, or null.
	 */
	private function editable_post_type( $type ) {
		return Content_Eligibility::post_type_object( $type );
	}

	/**
	 * Checks the observed timestamp and mutation-relevant state fingerprint immediately before a full write.
	 *
	 * @param object $post              Post object.
	 * @param mixed  $expected_modified Expected modified GMT.
	 * @param mixed  $expected_hash     Expected SHA-256 state hash.
	 * @return true|WP_Error
	 */
	private function check_expected_identity( $post, $expected_modified, $expected_hash ) {
		if ( ! $post || ! is_string( $expected_modified ) || '' === $expected_modified || ! is_string( $expected_hash ) || 64 !== strlen( $expected_hash ) ) {
			return new WP_Error( 'expected_identity_required', __( 'expected_modified_gmt and expected_state_hash are required for overwrite-sensitive full-content updates.', 'wp-ai-bridge' ) );
		}

		$current_hash = $this->state_hash( $post );
		if ( (string) $post->post_modified_gmt !== $expected_modified || $current_hash !== $expected_hash ) {
			return new WP_Error(
				'stale_content_conflict',
				__( 'The content state changed after it was inspected. Refresh it before applying this update.', 'wp-ai-bridge' ),
				array(
					'current_modified_gmt' => (string) $post->post_modified_gmt,
					'current_state_hash'   => $current_hash,
				)
			);
		}
		return true;
	}

	/**
	 * Builds the deterministic identity for every field content-upsert may overwrite.
	 *
	 * @param object $post Post object.
	 * @return string SHA-256 state fingerprint.
	 */
	private function state_hash( $post ) {
		$state = array(
			'title'          => (string) $post->post_title,
			'content'        => (string) $post->post_content,
			'excerpt'        => (string) $post->post_excerpt,
			'status'         => (string) $post->post_status,
			'slug'           => (string) $post->post_name,
			'parent_id'      => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'template'       => function_exists( 'get_page_template_slug' ) ? (string) get_page_template_slug( $post->ID ) : '',
			'featured_media' => function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post->ID ) : 0,
		);

		return hash( 'sha256', wp_json_encode( $state ) );
	}

	/**
	 * Accepts only registered authoring statuses and excludes destructive/internal Core states.
	 *
	 * @param string $status Requested post status.
	 * @return bool Whether ordinary content upsert may target the status.
	 */
	private function valid_authoring_status( $status ) {
		$status = (string) $status;
		if ( '' === $status || in_array( $status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return false;
		}

		return function_exists( 'get_post_status_object' ) && (bool) get_post_status_object( $status );
	}

	/**
	 * Formats one content object for compact AI consumption.
	 *
	 * @param object $post            Post object.
	 * @param bool   $include_content Whether to include full content.
	 * @return array<string,mixed> Formatted content item.
	 */
	private function format_post( $post, $include_content ) {
		$item = array(
			'id'             => (int) $post->ID,
			'post_type'      => (string) $post->post_type,
			'status'         => (string) $post->post_status,
			'slug'           => (string) $post->post_name,
			'title'          => (string) $post->post_title,
			'excerpt'        => (string) $post->post_excerpt,
			'modified_gmt'   => (string) $post->post_modified_gmt,
			'parent_id'      => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'template'       => function_exists( 'get_page_template_slug' ) ? (string) get_page_template_slug( $post->ID ) : '',
			'featured_media' => function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post->ID ) : 0,
			'content_hash'   => hash( 'sha256', (string) $post->post_content ),
			'state_hash'     => $this->state_hash( $post ),
		);
		if ( $include_content ) {
			$item['content'] = (string) $post->post_content;
		}
		return $item;
	}


	/**
	 * One complete or compact single-post response. Large metadata never
	 * prevents a caller from resuming by content hash and exact field name.
	 *
	 * @param object              $post     Authorized post.
	 * @param array<string,mixed> $input    Read input.
	 * @param string              $content  Stored body.
	 * @param int                 $offset   UTF-8 byte offset.
	 * @param bool                $windowed Whether a byte window was requested.
	 * @return array<string,mixed>|WP_Error
	 */
	private function read_single_content( $post, $input, $content, $offset, $windowed ) {
		$item          = $this->format_post( $post, false );
		$limit         = isset( $input['content_max_bytes'] ) ? (int) $input['content_max_bytes'] : Bounded_Payload::TEXT_WINDOW_BYTES;
		$needs_content = $windowed || strlen( $content ) <= Bounded_Payload::TEXT_WINDOW_BYTES;
		if ( $needs_content ) {
			$window = Bounded_Payload::text_window( $content, $offset, $limit );
			if ( is_wp_error( $window ) ) {
				return $window;
			}
			$item['content']             = $window['content'];
			$item['content_total_bytes'] = $window['total_bytes'];
			$item['content_next_offset'] = $window['next_offset'];
			$item['content_complete']    = $window['complete'];
		} else {
			$item['content_total_bytes'] = strlen( $content );
			$item['content_next_offset'] = 0;
			$item['content_complete']    = false;
		}
		$result = $this->single_item_result( $item );
		if ( Bounded_Payload::fits( $result ) ) {
			return $result;
		}

		// Shrink an included body before resorting to a compact metadata view.
		if ( $needs_content ) {
			for ( $size = (int) strlen( $item['content'] ); $size >= 4; $size = (int) floor( $size / 2 ) ) {
				$window = Bounded_Payload::text_window( $content, $offset, $size );
				if ( is_wp_error( $window ) ) {
					break;
				}
				$item['content']             = $window['content'];
				$item['content_next_offset'] = $window['next_offset'];
				$item['content_complete']    = $window['complete'];
				$result                      = $this->single_item_result( $item );
				if ( Bounded_Payload::fits( $result ) ) {
					return $result;
				}
				if ( 4 === $size ) {
					break;
				}
			}
		}

		// The title/excerpt/slug/template may dwarf the body itself.
		$item                        = $this->compact_post_identity( $post );
		$item['content_total_bytes'] = strlen( $content );
		$item['content_next_offset'] = $offset;
		$item['content_complete']    = strlen( $content ) === $offset;
		if ( $needs_content ) {
			for ( $size = $limit; $size >= 4; $size = (int) floor( $size / 2 ) ) {
				$window = Bounded_Payload::text_window( $content, $offset, $size );
				if ( is_wp_error( $window ) ) {
					return $window;
				}
				$item['content']             = $window['content'];
				$item['content_next_offset'] = $window['next_offset'];
				$item['content_complete']    = $window['complete'];
				$result                      = $this->single_item_result( $item );
				if ( Bounded_Payload::fits( $result ) ) {
					return $result;
				}
				if ( 4 === $size ) {
					break;
				}
			}
		}
		$result = $this->single_item_result( $item );
		return Bounded_Payload::fits( $result ) ? $result : $this->oversized_content_error();
	}

	/**
	 * Explicit byte windows for unbounded non-body metadata fields.
	 * The full state hash guards continuations even if body content is unchanged.
	 *
	 * @param object              $post  Authorized post.
	 * @param array<string,mixed> $input Requested field and continuation.
	 * @return array<string,mixed>|WP_Error
	 */
	private function read_text_field( $post, $input ) {
		$field = (string) $input['text_field'];
		if ( ! in_array( $field, array( 'title', 'excerpt', 'slug', 'status', 'template' ), true ) ) {
			return $this->oversized_content_error();
		}
		$offset     = isset( $input['text_offset'] ) ? (int) $input['text_offset'] : 0;
		$state_hash = $this->state_hash( $post );
		if ( $offset > 0 && empty( $input['expected_state_hash'] ) ) {
			return new WP_Error( 'content_identity_required', __( 'A resumed text-field read requires expected_state_hash from the previous response.', 'wp-ai-bridge' ) );
		}
		if ( isset( $input['expected_state_hash'] ) && ! hash_equals( $state_hash, (string) $input['expected_state_hash'] ) ) {
			return new WP_Error( 'stale_content_conflict', __( 'The content state changed while reading the text field; restart at offset zero.', 'wp-ai-bridge' ) );
		}
		$values             = array(
			'title'    => (string) $post->post_title,
			'excerpt'  => (string) $post->post_excerpt,
			'slug'     => (string) $post->post_name,
			'status'   => (string) $post->post_status,
			'template' => function_exists( 'get_page_template_slug' ) ? (string) get_page_template_slug( $post->ID ) : '',
		);
		$value              = $values[ $field ];
		$item               = $this->compact_post_identity( $post );
		$item['text_field'] = $field;
		$item['text_hash']  = hash( 'sha256', $value );
		$limit              = isset( $input['text_max_bytes'] ) ? (int) $input['text_max_bytes'] : Bounded_Payload::TEXT_WINDOW_BYTES;
		for ( $size = $limit; $size >= 4; $size = (int) floor( $size / 2 ) ) {
			$window = Bounded_Payload::text_window( $value, $offset, $size );
			if ( is_wp_error( $window ) ) {
				return $window;
			}
			$item['text_chunk']       = $window['content'];
			$item['text_total_bytes'] = $window['total_bytes'];
			$item['text_next_offset'] = $window['next_offset'];
			$item['text_complete']    = $window['complete'];
			$result                   = $this->single_item_result( $item );
			if ( Bounded_Payload::fits( $result ) ) {
				return $result;
			}
			if ( 4 === $size ) {
				break;
			}
		}
		return $this->oversized_content_error();
	}

	/**
	 * Compact projection contains only fixed/bounded identity and simple IDs.
	 * Names of intentionally omitted fields tell clients what to retrieve
	 * through text_field windows; nothing is silently truncated.
	 *
	 * @param object $post WordPress post.
	 * @return array<string,mixed>
	 */
	private function compact_post_identity( $post ) {
		return array(
			'id'                   => (int) $post->ID,
			'post_type'            => (string) $post->post_type,
			'modified_gmt'         => (string) $post->post_modified_gmt,
			'parent_id'            => (int) $post->post_parent,
			'menu_order'           => (int) $post->menu_order,
			'featured_media'       => function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post->ID ) : 0,
			'content_hash'         => hash( 'sha256', (string) $post->post_content ),
			'state_hash'           => $this->state_hash( $post ),
			'projection_truncated' => true,
			'omitted_fields'       => array( 'title', 'excerpt', 'slug', 'status', 'template' ),
		);
	}

	/**
	 * @param array<string,mixed> $item Single post item.
	 * @return array<string,mixed>
	 */
	private function single_item_result( $item ) {
		return array(
			'items'       => array( $item ),
			'page'        => 1,
			'per_page'    => 1,
			'total'       => 1,
			'total_pages' => 1,
		);
	}

	/**
	 * Successful writes never return an unbounded post metadata projection.
	 *
	 * @param object $post Committed content.
	 * @return array<string,mixed>
	 */
	private function format_post_after_mutation( $post ) {
		$item                        = $this->format_post( $post, false );
		$body                        = (string) $post->post_content;
		$item['content_total_bytes'] = strlen( $body );
		for ( $size = Bounded_Payload::TEXT_WINDOW_BYTES; $size >= 4; $size = (int) floor( $size / 2 ) ) {
			$window = Bounded_Payload::text_window( $body, 0, $size );
			if ( is_wp_error( $window ) ) {
				break;
			}
			$item['content']             = $window['content'];
			$item['content_next_offset'] = $window['next_offset'];
			$item['content_complete']    = $window['complete'];
			if ( Bounded_Payload::fits( $item ) ) {
				return $item;
			}
			if ( 4 === $size ) {
				break;
			}
		}

		// WordPress has ALREADY persisted the change. Return only bounded
		// committed identities; never reattempt a destructive write.
		$item                        = $this->compact_post_identity( $post );
		$item['content']             = '';
		$item['content_total_bytes'] = strlen( $body );
		$item['content_next_offset'] = 0;
		$item['content_complete']    = '' === $body;
		if ( Bounded_Payload::fits( $item ) ) {
			return $item;
		}
		// Only fixed-size identity fields survive this final defensive guard.
		return array(
			'id'                   => (int) $post->ID,
			'post_type'            => (string) $post->post_type,
			'modified_gmt'         => (string) $post->post_modified_gmt,
			'content_hash'         => hash( 'sha256', $body ),
			'state_hash'           => $this->state_hash( $post ),
			'content'              => '',
			'content_total_bytes'  => strlen( $body ),
			'content_next_offset'  => 0,
			'content_complete'     => '' === $body,
			'projection_truncated' => true,
			'omitted_fields'       => array( 'title', 'excerpt', 'slug', 'status', 'template', 'parent_id', 'menu_order', 'featured_media' ),
		);
	}

	/** @return WP_Error */
	private function oversized_content_error() {
		return new WP_Error( 'content_response_too_large', __( 'The requested content result exceeds the MCP response budget. Reduce per_page, disable include_content, or use content-read with content_offset and content_max_bytes.', 'wp-ai-bridge' ) );
	}

	/**
	 * Formats one revision.
	 *
	 * @param object $revision        Revision object.
	 * @param bool   $include_content Whether to include full revision content.
	 * @return array<string,mixed> Formatted revision.
	 */
	private function format_revision( $revision, $include_content ) {
		$item = array(
			'id'           => (int) $revision->ID,
			'parent_id'    => (int) $revision->post_parent,
			'date_gmt'     => (string) $revision->post_date_gmt,
			'modified_gmt' => (string) $revision->post_modified_gmt,
			'author_id'    => (int) $revision->post_author,
			'title'        => (string) $revision->post_title,
			'excerpt'      => (string) $revision->post_excerpt,
			'content_hash' => hash( 'sha256', (string) $revision->post_content ),
		);
		if ( $include_content ) {
			$item['content'] = (string) $revision->post_content;
		}
		return $item;
	}

	/**
	 * Returns the content-read input schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function read_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'                => array(
					'type'    => 'string',
					'enum'    => array( 'list', 'get' ),
					'default' => 'list',
				),
				'post_type'             => array(
					'type'    => 'string',
					'default' => 'post',
				),
				'id'                    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'slug'                  => array( 'type' => 'string' ),
				'search'                => array( 'type' => 'string' ),
				'status'                => array( 'type' => 'string' ),
				'page'                  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'              => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
				),
				'include_content'       => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'content_offset'        => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'content_max_bytes'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => Bounded_Payload::TEXT_WINDOW_BYTES,
				),
				'expected_content_hash' => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
				'text_field'            => array(
					'type' => 'string',
					'enum' => array( 'title', 'excerpt', 'slug', 'status', 'template' ),
				),
				'text_offset'           => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'text_max_bytes'        => array(
					'type'    => 'integer',
					'minimum' => 4,
					'maximum' => Bounded_Payload::TEXT_WINDOW_BYTES,
				),
				'expected_state_hash'   => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the content-read output schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function read_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items'       => array(
					'type'  => 'array',
					'items' => $this->item_schema( false ),
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

	/**
	 * Returns the content-upsert input schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function upsert_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'                => array(
					'type' => 'string',
					'enum' => array( 'create', 'update' ),
				),
				'id'                    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'post_type'             => array( 'type' => 'string' ),
				'title'                 => array( 'type' => 'string' ),
				'content'               => array( 'type' => 'string' ),
				'excerpt'               => array( 'type' => 'string' ),
				'status'                => array( 'type' => 'string' ),
				'slug'                  => array( 'type' => 'string' ),
				'parent_id'             => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'menu_order'            => array( 'type' => 'integer' ),
				'template'              => array( 'type' => 'string' ),
				'featured_media'        => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'operation_id'          => array(
					'type'      => 'string',
					'minLength' => 8,
					'maxLength' => 128,
				),
				'expected_modified_gmt' => array( 'type' => 'string' ),
				'expected_state_hash'   => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the content-delete input schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function delete_input_schema() {
		return array(
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
		);
	}

	/**
	 * Returns the content-item output schema.
	 *
	 * @param bool $content_required Whether content is required in output.
	 * @return array<string,mixed> JSON schema.
	 */
	private function item_schema( $content_required ) {
		$properties = array(
			'id'                   => array( 'type' => 'integer' ),
			'post_type'            => array( 'type' => 'string' ),
			'status'               => array( 'type' => 'string' ),
			'slug'                 => array( 'type' => 'string' ),
			'title'                => array( 'type' => 'string' ),
			'excerpt'              => array( 'type' => 'string' ),
			'modified_gmt'         => array( 'type' => 'string' ),
			'parent_id'            => array( 'type' => 'integer' ),
			'menu_order'           => array( 'type' => 'integer' ),
			'template'             => array( 'type' => 'string' ),
			'featured_media'       => array( 'type' => 'integer' ),
			'content_hash'         => array( 'type' => 'string' ),
			'state_hash'           => array( 'type' => 'string' ),
			'content'              => array( 'type' => 'string' ),
			'content_total_bytes'  => array( 'type' => 'integer' ),
			'content_next_offset'  => array( 'type' => 'integer' ),
			'content_complete'     => array( 'type' => 'boolean' ),
			'projection_truncated' => array( 'type' => 'boolean' ),
			'omitted_fields'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'text_field'           => array( 'type' => 'string' ),
			'text_chunk'           => array( 'type' => 'string' ),
			'text_hash'            => array( 'type' => 'string' ),
			'text_total_bytes'     => array( 'type' => 'integer' ),
			'text_next_offset'     => array( 'type' => 'integer' ),
			'text_complete'        => array( 'type' => 'boolean' ),
		);
		$required   = array( 'id', 'post_type', 'modified_gmt', 'content_hash', 'state_hash' );
		if ( $content_required ) {
			$required[] = 'content';
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the revision output schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function revision_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                   => array( 'type' => 'integer' ),
				'parent_id'            => array( 'type' => 'integer' ),
				'date_gmt'             => array( 'type' => 'string' ),
				'modified_gmt'         => array( 'type' => 'string' ),
				'author_id'            => array( 'type' => 'integer' ),
				'title'                => array( 'type' => 'string' ),
				'excerpt'              => array( 'type' => 'string' ),
				'content_hash'         => array( 'type' => 'string' ),
				'content'              => array( 'type' => 'string' ),
				'content_total_bytes'  => array( 'type' => 'integer' ),
				'content_next_offset'  => array( 'type' => 'integer' ),
				'content_complete'     => array( 'type' => 'boolean' ),
				'title_hash'           => array( 'type' => 'string' ),
				'title_total_bytes'    => array( 'type' => 'integer' ),
				'excerpt_hash'         => array( 'type' => 'string' ),
				'excerpt_total_bytes'  => array( 'type' => 'integer' ),
				'text_field'           => array(
					'type' => 'string',
					'enum' => array( 'title', 'excerpt' ),
				),
				'text_hash'            => array( 'type' => 'string' ),
				'text_chunk'           => array( 'type' => 'string' ),
				'text_total_bytes'     => array( 'type' => 'integer' ),
				'text_next_offset'     => array( 'type' => 'integer' ),
				'text_complete'        => array( 'type' => 'boolean' ),
				'projection_truncated' => array( 'type' => 'boolean' ),
				'omitted_fields'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'id', 'parent_id', 'date_gmt', 'modified_gmt', 'author_id', 'content_hash' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds MCP exposure metadata and tool annotations.
	 *
	 * @param bool $read_only   Whether the Ability is read-only.
	 * @param bool $destructive Whether the Ability is destructive.
	 * @param bool $idempotent  Whether repeated execution is idempotent.
	 * @return array<string,mixed> Ability metadata.
	 */
	private function meta( $read_only, $destructive, $idempotent ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => (bool) $read_only,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent,
			),
		);
	}

	/**
	 * Records a bounded failure and returns its WordPress error.
	 *
	 * @param string $ability   Ability name.
	 * @param string $code      Error code.
	 * @param string $message   Error message.
	 * @param int    $target_id Optional target post ID.
	 * @return WP_Error Error object.
	 */
	private function logged_error( $ability, $code, $message, $target_id = 0 ) {
		$this->log->record( $ability, 'post', (int) $target_id, false, $code );
		return new WP_Error( $code, $message );
	}
}
