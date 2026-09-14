<?php
/**
 * WordPress navigation abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Provides theme-neutral discovery plus classic-menu mutation.
 */
final class Navigation_Abilities {
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
	 * Creates the navigation Ability provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Mutation logger.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/**
	 * Registers navigation abilities.
	 *
	 * @return void
	 */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-native-builder/navigation-read',
			array(
				'label'               => __( 'Read Navigation', 'wp-native-builder-bridge' ),
				'description'         => __( 'Inspects classic menus, theme menu locations, and block-navigation entities available on the current site.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-native-builder/classic-navigation-mutate',
			array(
				'label'               => __( 'Mutate Classic Navigation', 'wp-native-builder-bridge' ),
				'description'         => __( 'Creates or updates classic menus, menu items, ordering, and theme menu-location assignments; permanent item removal additionally requires destructive access.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->mutate_input_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'action'   => array( 'type' => 'string' ),
						'menu_id'  => array( 'type' => 'integer' ),
						'item_id'  => array( 'type' => 'integer' ),
						'location' => array( 'type' => 'string' ),
						'removed'  => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'action', 'menu_id', 'item_id', 'location', 'removed' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'mutate_classic' ),
				'permission_callback' => array( $this, 'can_mutate_classic' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/**
	 * Checks navigation inspection permission.
	 *
	 * @return bool Whether navigation may be inspected.
	 */
	public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
	}

	/**
	 * Checks classic-navigation mutation permission.
	 *
	 * @return bool Whether classic navigation may be changed.
	 */
	public function can_mutate_classic( $input ) {
		if ( ! is_array( $input ) || empty( $input['action'] ) || ! $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'edit_theme_options' ) ) {
			return false;
		}

		if ( 'remove_item' === (string) $input['action'] ) {
			return $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'edit_theme_options' );
		}

		return true;
	}

	/**
	 * Returns classic and block-navigation discovery information.
	 *
	 * @return array<string,mixed> Navigation summary.
	 */
	public function read() {
		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items           = wp_get_nav_menu_items( $menu->term_id );
			$items           = is_array( $items ) ? $items : array();
			$formatted_items = array();
			foreach ( $items as $item ) {
				$formatted_items[] = $this->format_menu_item( $item );
			}
			$menus[] = array(
				'menu_id'     => (int) $menu->term_id,
				'name'        => (string) $menu->name,
				'slug'        => (string) $menu->slug,
				'description' => (string) $menu->description,
				'items'       => $formatted_items,
			);
		}

		$registered = get_registered_nav_menus();
		$assigned   = get_nav_menu_locations();
		$locations  = array();
		foreach ( $registered as $slug => $description ) {
			$locations[] = array(
				'location'    => (string) $slug,
				'description' => (string) $description,
				'menu_id'     => isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0,
			);
		}

		$block_navigations = array();
		if ( post_type_exists( 'wp_navigation' ) ) {
			$posts = get_posts(
				array(
					'post_type'      => 'wp_navigation',
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);
			foreach ( $posts as $post ) {
				if ( ! current_user_can( 'read_post', $post->ID ) ) {
					continue;
				}
				$block_navigations[] = array(
					'id'             => (int) $post->ID,
					'title'          => (string) $post->post_title,
					'status'         => (string) $post->post_status,
					'modified_gmt'   => (string) $post->post_modified_gmt,
					'content_hash'   => hash( 'sha256', (string) $post->post_content ),
					'blocks_ability' => 'wp-native-builder/blocks-read',
					'mutate_ability' => 'wp-native-builder/blocks-mutate',
				);
			}
		}

		return array(
			'classic_menus'     => $menus,
			'locations'         => $locations,
			'block_navigations' => $block_navigations,
		);
	}

	/**
	 * Applies one bounded classic-menu mutation.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error Mutation result.
	 */
	public function mutate_classic( $input ) {
		$ability = 'wp-native-builder/classic-navigation-mutate';
		$action  = (string) $input['action'];
		$result  = array(
			'action'   => $action,
			'menu_id'  => 0,
			'item_id'  => 0,
			'location' => '',
			'removed'  => false,
		);

		if ( 'create_menu' === $action || 'update_menu' === $action ) {
			$menu_id = 'update_menu' === $action && ! empty( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
			if ( $menu_id && ! wp_get_nav_menu_object( $menu_id ) ) {
				return $this->logged_error( 'navigation_menu_not_found', __( 'The requested classic navigation menu does not exist.', 'wp-native-builder-bridge' ), $menu_id );
			}
			if ( empty( $input['name'] ) ) {
				return $this->logged_error( 'navigation_menu_name_required', __( 'name is required when creating or updating a classic navigation menu.', 'wp-native-builder-bridge' ), $menu_id );
			}
			$args = array( 'menu-name' => wp_slash( sanitize_text_field( (string) $input['name'] ) ) );
			if ( array_key_exists( 'description', $input ) ) {
				$args['description'] = wp_slash( sanitize_textarea_field( (string) $input['description'] ) );
			}
			$menu_id = wp_update_nav_menu_object( $menu_id, $args );
			if ( is_wp_error( $menu_id ) ) {
				$this->log->record( $ability, 'nav_menu', 0, false, $menu_id->get_error_code() );
				return $menu_id;
			}
			$result['menu_id'] = (int) $menu_id;
		} elseif ( 'upsert_item' === $action ) {
			$menu_id = ! empty( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
			if ( ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) {
				return $this->logged_error( 'navigation_menu_not_found', __( 'A valid menu_id is required for menu-item mutation.', 'wp-native-builder-bridge' ), $menu_id );
			}
			$item_id  = ! empty( $input['item_id'] ) ? (int) $input['item_id'] : 0;
			$existing = null;
			if ( $item_id ) {
				if ( ! is_nav_menu_item( $item_id ) ) {
					return $this->logged_error( 'navigation_item_not_found', __( 'The requested menu item does not exist.', 'wp-native-builder-bridge' ), $item_id );
				}
				$existing = wp_setup_nav_menu_item( get_post( $item_id ) );
			}
			$item_args = $this->menu_item_args( $input, $existing );
			if ( is_wp_error( $item_args ) ) {
				$this->log->record( $ability, 'nav_menu_item', $item_id, false, $item_args->get_error_code() );
				return $item_args;
			}
			$item_id = wp_update_nav_menu_item( $menu_id, $item_id, $item_args );
			if ( is_wp_error( $item_id ) ) {
				$this->log->record( $ability, 'nav_menu_item', 0, false, $item_id->get_error_code() );
				return $item_id;
			}
			$result['menu_id'] = $menu_id;
			$result['item_id'] = (int) $item_id;
		} elseif ( 'remove_item' === $action ) {
			if ( ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'edit_theme_options' ) ) {
				return $this->logged_error( 'navigation_destructive_access_required', __( 'Permanent menu-item removal requires Users & Destructive access.', 'wp-native-builder-bridge' ) );
			}
			$item_id = ! empty( $input['item_id'] ) ? (int) $input['item_id'] : 0;
			if ( ! $item_id || ! is_nav_menu_item( $item_id ) ) {
				return $this->logged_error( 'navigation_item_not_found', __( 'A valid item_id is required to remove a classic navigation item.', 'wp-native-builder-bridge' ), $item_id );
			}
			$item = wp_delete_post( $item_id, true );
			if ( ! $item ) {
				return $this->logged_error( 'navigation_item_remove_failed', __( 'WordPress could not remove the classic navigation item.', 'wp-native-builder-bridge' ), $item_id );
			}
			$result['item_id'] = $item_id;
			$result['removed'] = true;
		} elseif ( 'set_location' === $action ) {
			$location   = ! empty( $input['location'] ) ? sanitize_key( (string) $input['location'] ) : '';
			$registered = get_registered_nav_menus();
			if ( '' === $location || ! array_key_exists( $location, $registered ) ) {
				return $this->logged_error( 'navigation_location_not_found', __( 'location must identify a menu location registered by the active theme.', 'wp-native-builder-bridge' ) );
			}
			$menu_id = isset( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
			if ( $menu_id && ! wp_get_nav_menu_object( $menu_id ) ) {
				return $this->logged_error( 'navigation_menu_not_found', __( 'The requested classic navigation menu does not exist.', 'wp-native-builder-bridge' ), $menu_id );
			}
			$locations              = get_nav_menu_locations();
			$locations[ $location ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
			$result['menu_id']  = $menu_id;
			$result['location'] = $location;
		} else {
			return $this->logged_error( 'invalid_navigation_action', __( 'The requested classic navigation action is not supported.', 'wp-native-builder-bridge' ) );
		}

		$target_type = $result['item_id'] ? 'nav_menu_item' : 'nav_menu';
		$target_id   = $result['item_id'] ? $result['item_id'] : $result['menu_id'];
		$this->log->record( $ability, $target_type, $target_id, true, '' );
		return $result;
	}

	/**
	 * Builds and validates classic menu-item arguments.
	 *
	 * @param array<string,mixed> $input    Validated Ability input.
	 * @param object|null         $existing Existing menu item when updating.
	 * @return array<string,mixed>|WP_Error Menu-item arguments or error.
	 */
	private function menu_item_args( $input, $existing = null ) {
		$type = isset( $input['item_type'] ) ? (string) $input['item_type'] : ( $existing ? (string) $existing->type : 'custom' );
		$args = array(
			'menu-item-type'   => $type,
			'menu-item-status' => $existing ? (string) $existing->post_status : 'publish',
		);

		if ( 'custom' === $type ) {
			$url   = array_key_exists( 'url', $input ) ? (string) $input['url'] : ( $existing ? (string) $existing->url : '' );
			$title = array_key_exists( 'title', $input ) ? (string) $input['title'] : ( $existing ? (string) $existing->title : '' );
			if ( '' === $url || '' === $title ) {
				return new WP_Error( 'navigation_custom_item_fields_required', __( 'Custom menu items require both title and url.', 'wp-native-builder-bridge' ) );
			}
			$sanitized_url = esc_url_raw( $url );
			if ( '' === $sanitized_url ) {
				return new WP_Error( 'navigation_invalid_url', __( 'The custom menu item URL is not valid.', 'wp-native-builder-bridge' ) );
			}
			$args['menu-item-url']    = $sanitized_url;
			$args['menu-item-title']  = wp_slash( sanitize_text_field( $title ) );
			$args['menu-item-object'] = 'custom';
		} else {
			$object = array_key_exists( 'object', $input ) ? (string) $input['object'] : ( $existing ? (string) $existing->object : '' );
			if ( '' === $object ) {
				return new WP_Error( 'navigation_item_object_required', __( 'Non-custom menu items require an object identifier.', 'wp-native-builder-bridge' ) );
			}
			$args['menu-item-object'] = sanitize_key( $object );
			if ( 'post_type_archive' !== $type ) {
				$object_id = array_key_exists( 'object_id', $input ) ? (int) $input['object_id'] : ( $existing ? (int) $existing->object_id : 0 );
				if ( ! $object_id ) {
					return new WP_Error( 'navigation_item_object_id_required', __( 'This menu item type requires object_id.', 'wp-native-builder-bridge' ) );
				}
				$args['menu-item-object-id'] = $object_id;
			}
			if ( array_key_exists( 'title', $input ) ) {
				$args['menu-item-title'] = wp_slash( sanitize_text_field( (string) $input['title'] ) );
			}
		}

		if ( $existing ) {
			$args['menu-item-position']    = (int) $existing->menu_order;
			$args['menu-item-parent-id']   = (int) $existing->menu_item_parent;
			$args['menu-item-description'] = wp_slash( (string) $existing->description );
			$args['menu-item-attr-title']  = wp_slash( (string) $existing->attr_title );
			$args['menu-item-target']      = (string) $existing->target;
			$args['menu-item-classes']     = is_array( $existing->classes ) ? implode( ' ', array_map( 'sanitize_html_class', $existing->classes ) ) : sanitize_text_field( (string) $existing->classes );
			$args['menu-item-xfn']         = (string) $existing->xfn;
		}

		$scalar_map = array(
			'position'  => 'menu-item-position',
			'parent_id' => 'menu-item-parent-id',
		);
		foreach ( $scalar_map as $input_key => $arg_key ) {
			if ( array_key_exists( $input_key, $input ) ) {
				$args[ $arg_key ] = (int) $input[ $input_key ];
			}
		}
		if ( array_key_exists( 'description', $input ) ) {
			$args['menu-item-description'] = wp_slash( sanitize_textarea_field( (string) $input['description'] ) );
		}
		if ( array_key_exists( 'attr_title', $input ) ) {
			$args['menu-item-attr-title'] = wp_slash( sanitize_text_field( (string) $input['attr_title'] ) );
		}
		if ( array_key_exists( 'target', $input ) ) {
			$args['menu-item-target'] = '_blank' === $input['target'] ? '_blank' : '';
		}
		if ( array_key_exists( 'classes', $input ) ) {
			$args['menu-item-classes'] = implode( ' ', array_values( array_map( 'sanitize_html_class', $input['classes'] ) ) );
		}
		if ( array_key_exists( 'xfn', $input ) ) {
			$args['menu-item-xfn'] = sanitize_text_field( (string) $input['xfn'] );
		}

		return $args;
	}

	/**
	 * Formats one classic menu item.
	 *
	 * @param object $item Menu item object.
	 * @return array<string,mixed> Menu item summary.
	 */
	private function format_menu_item( $item ) {
		return array(
			'item_id'     => (int) $item->ID,
			'parent_id'   => (int) $item->menu_item_parent,
			'position'    => (int) $item->menu_order,
			'type'        => (string) $item->type,
			'object'      => (string) $item->object,
			'object_id'   => (int) $item->object_id,
			'title'       => (string) $item->title,
			'url'         => (string) $item->url,
			'target'      => (string) $item->target,
			'classes'     => is_array( $item->classes ) ? array_values( array_map( 'strval', $item->classes ) ) : array(),
			'description' => (string) $item->description,
		);
	}

	/**
	 * Returns the classic-navigation mutation input schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function mutate_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'      => array(
					'type' => 'string',
					'enum' => array( 'create_menu', 'update_menu', 'upsert_item', 'remove_item', 'set_location' ),
				),
				'menu_id'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'item_id'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'name'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'location'    => array( 'type' => 'string' ),
				'item_type'   => array(
					'type' => 'string',
					'enum' => array( 'custom', 'post_type', 'taxonomy', 'post_type_archive' ),
				),
				'object'      => array( 'type' => 'string' ),
				'object_id'   => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'title'       => array( 'type' => 'string' ),
				'url'         => array( 'type' => 'string' ),
				'position'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'parent_id'   => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'attr_title'  => array( 'type' => 'string' ),
				'target'      => array(
					'type' => 'string',
					'enum' => array( '', '_blank' ),
				),
				'classes'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'xfn'         => array( 'type' => 'string' ),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the navigation discovery output schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function read_output_schema() {
		$menu_item = array(
			'type'                 => 'object',
			'properties'           => array(
				'item_id'     => array( 'type' => 'integer' ),
				'parent_id'   => array( 'type' => 'integer' ),
				'position'    => array( 'type' => 'integer' ),
				'type'        => array( 'type' => 'string' ),
				'object'      => array( 'type' => 'string' ),
				'object_id'   => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'url'         => array( 'type' => 'string' ),
				'target'      => array( 'type' => 'string' ),
				'classes'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'description' => array( 'type' => 'string' ),
			),
			'required'             => array( 'item_id', 'parent_id', 'position', 'type', 'object', 'object_id', 'title', 'url', 'target', 'classes', 'description' ),
			'additionalProperties' => false,
		);

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'classic_menus'     => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'menu_id'     => array( 'type' => 'integer' ),
							'name'        => array( 'type' => 'string' ),
							'slug'        => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'items'       => array(
								'type'  => 'array',
								'items' => $menu_item,
							),
						),
						'required'             => array( 'menu_id', 'name', 'slug', 'description', 'items' ),
						'additionalProperties' => false,
					),
				),
				'locations'         => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'location'    => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'menu_id'     => array( 'type' => 'integer' ),
						),
						'required'             => array( 'location', 'description', 'menu_id' ),
						'additionalProperties' => false,
					),
				),
				'block_navigations' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'             => array( 'type' => 'integer' ),
							'title'          => array( 'type' => 'string' ),
							'status'         => array( 'type' => 'string' ),
							'modified_gmt'   => array( 'type' => 'string' ),
							'content_hash'   => array( 'type' => 'string' ),
							'blocks_ability' => array( 'type' => 'string' ),
							'mutate_ability' => array( 'type' => 'string' ),
						),
						'required'             => array( 'id', 'title', 'status', 'modified_gmt', 'content_hash', 'blocks_ability', 'mutate_ability' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'classic_menus', 'locations', 'block_navigations' ),
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
	 * Records a bounded classic-navigation mutation failure.
	 *
	 * @param string $code      Error code.
	 * @param string $message   Error message.
	 * @param int    $target_id Optional menu or item ID.
	 * @return WP_Error Error object.
	 */
	private function logged_error( $code, $message, $target_id = 0 ) {
		$this->log->record( 'wp-native-builder/classic-navigation-mutate', 'navigation', (int) $target_id, false, $code );
		return new WP_Error( $code, $message );
	}
}
