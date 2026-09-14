<?php
/**
 * Core content abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
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
			'wp-native-builder/content-read',
			array(
				'label'               => __( 'Read Content', 'wp-native-builder-bridge' ),
				'description'         => __( 'Lists or retrieves posts, pages, and editable custom post types through WordPress APIs.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-native-builder/content-upsert',
			array(
				'label'               => __( 'Create or Update Content', 'wp-native-builder-bridge' ),
				'description'         => __( 'Creates or updates one WordPress content object with access-group, capability, and stale-write checks.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->upsert_input_schema(),
				'output_schema'       => $this->item_schema( true ),
				'execute_callback'    => array( $this, 'upsert' ),
				'permission_callback' => array( $this, 'can_upsert' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-native-builder/content-delete',
			array(
				'label'               => __( 'Trash or Delete Content', 'wp-native-builder-bridge' ),
				'description'         => __( 'Moves content to Trash or permanently deletes it when destructive access is enabled.', 'wp-native-builder-bridge' ),
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
			'wp-native-builder/revisions-read',
			array(
				'label'               => __( 'Read Content Revisions', 'wp-native-builder-bridge' ),
				'description'         => __( 'Lists WordPress revisions for one content object.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'         => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'limit'           => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 10,
						),
						'include_content' => array(
							'type'    => 'boolean',
							'default' => false,
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
			'wp-native-builder/revision-restore',
			array(
				'label'               => __( 'Restore Content Revision', 'wp-native-builder-bridge' ),
				'description'         => __( 'Restores a WordPress revision after checking the expected current content identity.', 'wp-native-builder-bridge' ),
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
	 * Checks permission for revision inspection.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether the current user may read revisions.
	 */
	public function can_read_revisions( $input ) {
		return is_array( $input )
			&& ! empty( $input['post_id'] )
			&& $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' )
			&& current_user_can( 'read_post', (int) $input['post_id'] );
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
				return new WP_Error( 'content_not_found', __( 'The requested content is not available.', 'wp-native-builder-bridge' ) );
			}

			return array(
				'items'       => array( $this->format_post( $post, true ) ),
				'page'        => 1,
				'per_page'    => 1,
				'total'       => 1,
				'total_pages' => 1,
			);
		}

		$type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
		$obj  = $this->editable_post_type( $type );
		if ( ! $obj ) {
			return new WP_Error( 'unsupported_post_type', __( 'The requested post type is not available for Bridge content operations.', 'wp-native-builder-bridge' ) );
		}

		$can_edit = current_user_can( $obj->cap->edit_posts );
		$status   = isset( $input['status'] ) && '' !== $input['status'] ? (string) $input['status'] : ( $can_edit ? 'any' : 'publish' );
		if ( ! $can_edit && 'publish' !== $status ) {
			return new WP_Error( 'content_status_forbidden', __( 'The current user may only list published content for this post type.', 'wp-native-builder-bridge' ) );
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

		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'read_post', $post->ID ) ) {
				$items[] = $this->format_post( $post, $include_content );
			}
		}

		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Creates or updates content.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function upsert( $input ) {
		$ability = 'wp-native-builder/content-upsert';
		$action  = (string) $input['action'];
		$id      = ! empty( $input['id'] ) ? (int) $input['id'] : 0;
		$post    = $id ? get_post( $id ) : null;

		if ( 'update' === $action && ! $post ) {
			return $this->logged_error( $ability, 'content_not_found', __( 'The content to update does not exist.', 'wp-native-builder-bridge' ), $id );
		}

		if ( isset( $input['status'] ) && ! $this->valid_authoring_status( (string) $input['status'] ) ) {
			return $this->logged_error( $ability, 'invalid_content_status', __( 'status must be a registered authoring status and cannot be a destructive or internal status.', 'wp-native-builder-bridge' ), $id );
		}

		$type = 'create' === $action ? (string) $input['post_type'] : $post->post_type;
		if ( ! $this->editable_post_type( $type ) ) {
			return $this->logged_error( $ability, 'unsupported_post_type', __( 'The requested post type is not available for Bridge content operations.', 'wp-native-builder-bridge' ), $id );
		}

		$featured_media = null;
		if ( array_key_exists( 'featured_media', $input ) ) {
			$featured_media = (int) $input['featured_media'];
			if ( 0 !== $featured_media ) {
				$attachment = get_post( $featured_media );
				if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $featured_media ) ) {
					return $this->logged_error( $ability, 'invalid_featured_media', __( 'featured_media must reference an existing image attachment.', 'wp-native-builder-bridge' ), $id );
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
					__( 'The content was saved, but WordPress could not assign the featured media.', 'wp-native-builder-bridge' ),
					array(
						'content_saved' => true,
						'id'            => $id,
					)
				);
			}
		}

		$this->log->record( $ability, 'post', $id, true, '' );
		return $this->format_post( get_post( $id ), true );
	}

	/**
	 * Trashes or permanently deletes content.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete( $input ) {
		$ability = 'wp-native-builder/content-delete';
		$id      = (int) $input['id'];
		$post    = get_post( $id );
		if ( ! $post || ! $this->editable_post_type( $post->post_type ) ) {
			return $this->logged_error( $ability, 'content_not_found', __( 'The requested content does not exist.', 'wp-native-builder-bridge' ), $id );
		}

		$force  = ! empty( $input['force'] );
		$result = $force ? wp_delete_post( $id, true ) : wp_trash_post( $id );
		if ( ! $result ) {
			return $this->logged_error( $ability, 'content_delete_failed', __( 'WordPress could not trash or delete the content.', 'wp-native-builder-bridge' ), $id );
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
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'content_not_found', __( 'The requested content does not exist.', 'wp-native-builder-bridge' ) );
		}
		$limit     = isset( $input['limit'] ) ? max( 1, min( 50, (int) $input['limit'] ) ) : 10;
		$revisions = wp_get_post_revisions( $post->ID, array( 'posts_per_page' => $limit ) );
		$result    = array();
		foreach ( $revisions as $revision ) {
			$result[] = $this->format_revision( $revision, ! empty( $input['include_content'] ) );
		}
		return $result;
	}

	/**
	 * Restores a revision.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function restore_revision( $input ) {
		$ability     = 'wp-native-builder/revision-restore';
		$post_id     = (int) $input['post_id'];
		$revision_id = (int) $input['revision_id'];
		$post        = get_post( $post_id );
		$revision    = wp_get_post_revision( $revision_id );
		if ( ! $post || ! $revision || (int) $revision->post_parent !== $post_id ) {
			return $this->logged_error( $ability, 'revision_not_found', __( 'The requested revision does not belong to this content object.', 'wp-native-builder-bridge' ), $post_id );
		}
		$post     = get_post( $post_id );
		$conflict = $this->check_expected_identity( $post, (string) $input['expected_modified_gmt'], (string) $input['expected_state_hash'] );
		if ( is_wp_error( $conflict ) ) {
			$this->log->record( $ability, 'post', $post_id, false, $conflict->get_error_code() );
			return $conflict;
		}

		$result = wp_restore_post_revision( $revision_id );
		if ( ! $result ) {
			return $this->logged_error( $ability, 'revision_restore_failed', __( 'WordPress could not restore the requested revision.', 'wp-native-builder-bridge' ), $post_id );
		}
		$this->log->record( $ability, 'post', $post_id, true, '' );
		return $this->format_post( get_post( $post_id ), true );
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
			return new WP_Error( 'expected_identity_required', __( 'expected_modified_gmt and expected_state_hash are required for overwrite-sensitive full-content updates.', 'wp-native-builder-bridge' ) );
		}

		$current_hash = $this->state_hash( $post );
		if ( (string) $post->post_modified_gmt !== $expected_modified || $current_hash !== $expected_hash ) {
			return new WP_Error(
				'stale_content_conflict',
				__( 'The content state changed after it was inspected. Refresh it before applying this update.', 'wp-native-builder-bridge' ),
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
				'action'          => array(
					'type'    => 'string',
					'enum'    => array( 'list', 'get' ),
					'default' => 'list',
				),
				'post_type'       => array(
					'type'    => 'string',
					'default' => 'post',
				),
				'id'              => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'slug'            => array( 'type' => 'string' ),
				'search'          => array( 'type' => 'string' ),
				'status'          => array( 'type' => 'string' ),
				'page'            => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
				),
				'include_content' => array(
					'type'    => 'boolean',
					'default' => false,
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
			'id'             => array( 'type' => 'integer' ),
			'post_type'      => array( 'type' => 'string' ),
			'status'         => array( 'type' => 'string' ),
			'slug'           => array( 'type' => 'string' ),
			'title'          => array( 'type' => 'string' ),
			'excerpt'        => array( 'type' => 'string' ),
			'modified_gmt'   => array( 'type' => 'string' ),
			'parent_id'      => array( 'type' => 'integer' ),
			'menu_order'     => array( 'type' => 'integer' ),
			'template'       => array( 'type' => 'string' ),
			'featured_media' => array( 'type' => 'integer' ),
			'content_hash'   => array( 'type' => 'string' ),
			'state_hash'     => array( 'type' => 'string' ),
			'content'        => array( 'type' => 'string' ),
		);
		$required   = array( 'id', 'post_type', 'status', 'slug', 'title', 'excerpt', 'modified_gmt', 'parent_id', 'menu_order', 'template', 'featured_media', 'content_hash', 'state_hash' );
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
				'id'           => array( 'type' => 'integer' ),
				'parent_id'    => array( 'type' => 'integer' ),
				'date_gmt'     => array( 'type' => 'string' ),
				'modified_gmt' => array( 'type' => 'string' ),
				'author_id'    => array( 'type' => 'integer' ),
				'title'        => array( 'type' => 'string' ),
				'excerpt'      => array( 'type' => 'string' ),
				'content_hash' => array( 'type' => 'string' ),
				'content'      => array( 'type' => 'string' ),
			),
			'required'             => array( 'id', 'parent_id', 'date_gmt', 'modified_gmt', 'author_id', 'title', 'excerpt', 'content_hash' ),
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
