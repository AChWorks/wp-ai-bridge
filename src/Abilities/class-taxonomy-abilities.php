<?php
/**
 * Generic WordPress taxonomy abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Provides term inspection, mutation, assignment, and gated deletion.
 */
final class Taxonomy_Abilities {
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
	 * Creates the taxonomy Ability provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Mutation logger.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/**
	 * Registers taxonomy abilities.
	 *
	 * @return void
	 */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/terms-read',
			array(
				'label'               => __( 'Read Taxonomy Terms', 'wp-ai-bridge' ),
				'description'         => __( 'Lists or retrieves terms from registered editable WordPress taxonomies.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/term-upsert',
			array(
				'label'               => __( 'Create or Update Taxonomy Term', 'wp-ai-bridge' ),
				'description'         => __( 'Creates or updates one term in a registered editable taxonomy using WordPress APIs.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->upsert_input_schema(),
				'output_schema'       => $this->term_schema(),
				'execute_callback'    => array( $this, 'upsert' ),
				'permission_callback' => array( $this, 'can_upsert' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/terms-assign',
			array(
				'label'               => __( 'Assign Taxonomy Terms', 'wp-ai-bridge' ),
				'description'         => __( 'Assigns an explicit list of existing term IDs to one content object through WordPress taxonomy APIs.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'taxonomy' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'term_ids' => array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						'append'   => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'post_id', 'taxonomy', 'term_ids' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'  => array( 'type' => 'integer' ),
						'taxonomy' => array( 'type' => 'string' ),
						'term_ids' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
					'required'             => array( 'post_id', 'taxonomy', 'term_ids' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'assign' ),
				'permission_callback' => array( $this, 'can_assign' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/term-delete',
			array(
				'label'               => __( 'Delete Taxonomy Term', 'wp-ai-bridge' ),
				'description'         => __( 'Deletes one taxonomy term when destructive access and WordPress taxonomy capabilities allow it.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'term_id'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'taxonomy', 'term_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy' => array( 'type' => 'string' ),
						'term_id'  => array( 'type' => 'integer' ),
						'deleted'  => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'taxonomy', 'term_id', 'deleted' ),
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
	 * Checks taxonomy read permission without exposing hidden provider internals.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether terms may be inspected.
	 */
	public function can_read( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' ) || ! is_array( $input ) || empty( $input['taxonomy'] ) ) {
			return false;
		}
		$taxonomy = $this->editable_taxonomy( (string) $input['taxonomy'] );
		if ( ! $taxonomy ) {
			return false;
		}

		return ! empty( $taxonomy->public ) || ! empty( $taxonomy->publicly_queryable ) || current_user_can( $taxonomy->cap->manage_terms );
	}

	/**
	 * Checks term create/update permission.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether the requested term mutation is allowed.
	 */
	public function can_upsert( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'read' ) || ! is_array( $input ) || empty( $input['taxonomy'] ) ) {
			return false;
		}
		$taxonomy = $this->editable_taxonomy( (string) $input['taxonomy'] );
		if ( ! $taxonomy ) {
			return false;
		}

		$action = isset( $input['action'] ) ? (string) $input['action'] : '';
		if ( 'create' === $action ) {
			return current_user_can( $taxonomy->cap->manage_terms );
		}
		if ( 'update' === $action && ! empty( $input['term_id'] ) ) {
			return current_user_can( $taxonomy->cap->edit_terms );
		}

		return false;
	}

	/**
	 * Checks term-assignment permission.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether assignment is allowed.
	 */
	public function can_assign( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'read' ) || ! is_array( $input ) || empty( $input['post_id'] ) || empty( $input['taxonomy'] ) ) {
			return false;
		}

		$post     = get_post( (int) $input['post_id'] );
		$taxonomy = $this->editable_taxonomy( (string) $input['taxonomy'] );
		if ( ! $post || ! $taxonomy || ! is_object_in_taxonomy( $post->post_type, $taxonomy->name ) ) {
			return false;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
			return false;
		}

		if ( $this->requires_live_access( $post->post_status ) ) {
			$post_type = get_post_type_object( $post->post_type );
			return $post_type
				&& isset( $post_type->cap->publish_posts )
				&& $this->permissions->allowed( Settings::GROUP_LIVE_CONTENT, $post_type->cap->publish_posts );
		}

		return true;
	}

	/**
	 * Checks destructive term-delete permission.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether deletion is allowed.
	 */
	public function can_delete( $input ) {
		if ( ! is_array( $input ) || empty( $input['taxonomy'] ) || empty( $input['term_id'] ) || ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' ) ) {
			return false;
		}
		$taxonomy = $this->editable_taxonomy( (string) $input['taxonomy'] );
		return $taxonomy && current_user_can( $taxonomy->cap->delete_terms );
	}

	/**
	 * Lists or retrieves taxonomy terms.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error Term result.
	 */
	public function read( $input ) {
		$taxonomy = $this->editable_taxonomy( (string) $input['taxonomy'] );
		if ( ! $taxonomy ) {
			return new WP_Error( 'unsupported_taxonomy', __( 'The requested taxonomy is not available for generic Bridge operations.', 'wp-ai-bridge' ) );
		}

		$action = isset( $input['action'] ) ? (string) $input['action'] : 'list';
		if ( 'get' === $action ) {
			$term = ! empty( $input['term_id'] ) ? get_term( (int) $input['term_id'], $taxonomy->name ) : null;
			if ( ! $term || is_wp_error( $term ) ) {
				return new WP_Error( 'term_not_found', __( 'The requested taxonomy term does not exist.', 'wp-ai-bridge' ) );
			}
			return array(
				'items'       => array( $this->format_term( $term ) ),
				'page'        => 1,
				'per_page'    => 1,
				'total'       => 1,
				'total_pages' => 1,
			);
		}

		$page       = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per_page   = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 50;
		$hide_empty = ! empty( $input['hide_empty'] );
		$args       = array(
			'taxonomy'   => $taxonomy->name,
			'hide_empty' => $hide_empty,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = (string) $input['search'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$count_args = array( 'hide_empty' => $hide_empty );
		if ( ! empty( $input['search'] ) ) {
			$count_args['search'] = (string) $input['search'];
		}
		if ( isset( $input['parent'] ) ) {
			$count_args['parent'] = (int) $input['parent'];
		}
		$count_args['taxonomy'] = $taxonomy->name;
		$total                  = wp_count_terms( $count_args );
		if ( is_wp_error( $total ) ) {
			return $total;
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = $this->format_term( $term );
		}
		$total = (int) $total;
		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Creates or updates one taxonomy term.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error Term or error.
	 */
	public function upsert( $input ) {
		$ability  = 'wp-ai-bridge/term-upsert';
		$taxonomy = $this->editable_taxonomy( (string) $input['taxonomy'] );
		if ( ! $taxonomy ) {
			return $this->logged_error( $ability, 'unsupported_taxonomy', __( 'The requested taxonomy is not available for generic Bridge operations.', 'wp-ai-bridge' ) );
		}

		$args = array();
		foreach ( array( 'slug', 'description', 'parent' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$args[ $key ] = $input[ $key ];
			}
		}

		$action = (string) $input['action'];
		if ( 'create' === $action ) {
			if ( empty( $input['name'] ) ) {
				return $this->logged_error( $ability, 'term_name_required', __( 'name is required when creating a taxonomy term.', 'wp-ai-bridge' ) );
			}
			$result = wp_insert_term( (string) $input['name'], $taxonomy->name, $args );
		} else {
			$term_id = ! empty( $input['term_id'] ) ? (int) $input['term_id'] : 0;
			$term    = $term_id ? get_term( $term_id, $taxonomy->name ) : null;
			if ( ! $term || is_wp_error( $term ) ) {
				return $this->logged_error( $ability, 'term_not_found', __( 'The taxonomy term to update does not exist.', 'wp-ai-bridge' ), $term_id );
			}
			if ( array_key_exists( 'name', $input ) ) {
				$args['name'] = (string) $input['name'];
			}
			$result = wp_update_term( $term_id, $taxonomy->name, $args );
		}

		if ( is_wp_error( $result ) ) {
			$this->log->record( $ability, 'term', 0, false, $result->get_error_code() );
			return $result;
		}

		$term_id = (int) $result['term_id'];
		$this->log->record( $ability, 'term', $term_id, true, '' );
		return $this->format_term( get_term( $term_id, $taxonomy->name ) );
	}

	/**
	 * Assigns existing terms to one content object.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error Assignment result.
	 */
	public function assign( $input ) {
		$ability  = 'wp-ai-bridge/terms-assign';
		$post_id  = (int) $input['post_id'];
		$taxonomy = $this->editable_taxonomy( (string) $input['taxonomy'] );
		if ( ! $taxonomy ) {
			return $this->logged_error( $ability, 'unsupported_taxonomy', __( 'The requested taxonomy is not available for generic Bridge operations.', 'wp-ai-bridge' ), $post_id );
		}

		$term_ids = array_values( array_unique( array_map( 'intval', $input['term_ids'] ) ) );
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, $taxonomy->name );
			if ( ! $term || is_wp_error( $term ) ) {
				return $this->logged_error( $ability, 'term_not_found', __( 'Every assigned term_id must already exist in the requested taxonomy.', 'wp-ai-bridge' ), $post_id );
			}
		}

		$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy->name, ! empty( $input['append'] ) );
		if ( is_wp_error( $result ) ) {
			$this->log->record( $ability, 'post', $post_id, false, $result->get_error_code() );
			return $result;
		}

		$assigned = wp_get_object_terms( $post_id, $taxonomy->name, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}
		$assigned = array_values( array_map( 'intval', $assigned ) );
		sort( $assigned );
		$this->log->record( $ability, 'post', $post_id, true, '' );
		return array(
			'post_id'  => $post_id,
			'taxonomy' => $taxonomy->name,
			'term_ids' => $assigned,
		);
	}

	/**
	 * Deletes one taxonomy term.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error Delete result.
	 */
	public function delete( $input ) {
		$ability  = 'wp-ai-bridge/term-delete';
		$taxonomy = $this->editable_taxonomy( (string) $input['taxonomy'] );
		$term_id  = (int) $input['term_id'];
		$term     = $taxonomy ? get_term( $term_id, $taxonomy->name ) : null;
		if ( ! $taxonomy || ! $term || is_wp_error( $term ) ) {
			return $this->logged_error( $ability, 'term_not_found', __( 'The requested taxonomy term does not exist.', 'wp-ai-bridge' ), $term_id );
		}

		$result = wp_delete_term( $term_id, $taxonomy->name );
		if ( is_wp_error( $result ) ) {
			$this->log->record( $ability, 'term', $term_id, false, $result->get_error_code() );
			return $result;
		}
		if ( false === $result ) {
			return $this->logged_error( $ability, 'term_delete_failed', __( 'WordPress could not delete the requested taxonomy term.', 'wp-ai-bridge' ), $term_id );
		}

		$this->log->record( $ability, 'term', $term_id, true, '' );
		return array(
			'taxonomy' => $taxonomy->name,
			'term_id'  => $term_id,
			'deleted'  => true,
		);
	}

	/**
	 * Resolves a taxonomy safe for generic Bridge operations.
	 *
	 * @param string $name Taxonomy name.
	 * @return object|null Taxonomy object or null.
	 */
	private function editable_taxonomy( $name ) {
		$taxonomy = is_string( $name ) && '' !== $name ? get_taxonomy( $name ) : false;
		if ( ! $taxonomy || ( empty( $taxonomy->show_ui ) && empty( $taxonomy->show_in_rest ) ) || empty( $taxonomy->cap ) ) {
			return null;
		}
		return $taxonomy;
	}

	/**
	 * Determines whether content state requires Live Content access.
	 *
	 * @param string $status Post status.
	 * @return bool Whether Live Content is required.
	 */
	private function requires_live_access( $status ) {
		$status = (string) $status;
		return '' !== $status && ! in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true );
	}

	/**
	 * Formats one taxonomy term.
	 *
	 * @param object $term WordPress term object.
	 * @return array<string,mixed> Term summary.
	 */
	private function format_term( $term ) {
		return array(
			'term_id'     => (int) $term->term_id,
			'taxonomy'    => (string) $term->taxonomy,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		);
	}

	/**
	 * Returns the term-read input schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function read_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'     => array(
					'type'    => 'string',
					'enum'    => array( 'list', 'get' ),
					'default' => 'list',
				),
				'taxonomy'   => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'term_id'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'search'     => array( 'type' => 'string' ),
				'parent'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'hide_empty' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'page'       => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 50,
				),
			),
			'required'             => array( 'taxonomy' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the term-upsert input schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function upsert_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'      => array(
					'type' => 'string',
					'enum' => array( 'create', 'update' ),
				),
				'taxonomy'    => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'term_id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'parent'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			'required'             => array( 'action', 'taxonomy' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the term-read output schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function read_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items'       => array(
					'type'  => 'array',
					'items' => $this->term_schema(),
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
	 * Returns the taxonomy-term output schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function term_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'term_id'     => array( 'type' => 'integer' ),
				'taxonomy'    => array( 'type' => 'string' ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'parent'      => array( 'type' => 'integer' ),
				'count'       => array( 'type' => 'integer' ),
			),
			'required'             => array( 'term_id', 'taxonomy', 'name', 'slug', 'description', 'parent', 'count' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds standard MCP exposure metadata.
	 *
	 * @param bool $read_only   Whether read-only.
	 * @param bool $destructive Whether destructive.
	 * @param bool $idempotent  Whether idempotent.
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
	 * Records a bounded taxonomy mutation failure.
	 *
	 * @param string $ability   Ability name.
	 * @param string $code      Error code.
	 * @param string $message   Error message.
	 * @param int    $target_id Optional target ID.
	 * @return WP_Error Error object.
	 */
	private function logged_error( $ability, $code, $message, $target_id = 0 ) {
		$this->log->record( $ability, 'term', (int) $target_id, false, $code );
		return new WP_Error( $code, $message );
	}
}
