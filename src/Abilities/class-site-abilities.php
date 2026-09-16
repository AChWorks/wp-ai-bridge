<?php
/**
 * Site inspection abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

/**
 * Registers the Bridge-owned site context supplement.
 */
final class Site_Abilities {
	/**
	 * Existing Ability resolver.
	 *
	 * @var Ability_Resolver
	 */
	private $resolver;

	/**
	 * Bridge permission service.
	 *
	 * @var Permissions
	 */
	private $permissions;

	/**
	 * Creates the site Ability provider.
	 *
	 * @param Ability_Resolver $resolver    Existing Ability resolver.
	 * @param Permissions      $permissions Bridge permission service.
	 */
	public function __construct( Ability_Resolver $resolver, Permissions $permissions ) {
		$this->resolver    = $resolver;
		$this->permissions = $permissions;
	}

	/**
	 * Registers site-context.
	 *
	 * @return void
	 */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/site-context',
			array(
				'label'               => __( 'Site Context', 'wp-ai-bridge' ),
				'description'         => __( 'Returns site-building context not covered by the standard WordPress Core information abilities, plus reusable Ability discovery hints.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'can_execute' ),
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/**
	 * Checks Site Read access and the normal WordPress read capability.
	 *
	 * @return bool
	 */
	public function can_execute() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
	}

	/**
	 * Builds site-building context without exposing arbitrary options or secrets.
	 *
	 * @return array<string,mixed> Site context.
	 */
	public function execute() {
		return array(
			'site'               => $this->site_summary(),
			'current_user'       => $this->current_user_summary(),
			'theme'              => $this->theme_summary(),
			'plugins'            => $this->plugin_summaries(),
			'post_types'         => $this->post_type_summaries(),
			'taxonomies'         => $this->taxonomy_summaries(),
			'reuse'              => $this->reuse_map(),
			'external_abilities' => $this->resolver->public_catalog( 50 ),
		);
	}

	/**
	 * Returns site configuration relevant to site building.
	 *
	 * @return array<string,mixed> Site summary.
	 */
	private function site_summary() {
		$timezone = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : (string) get_option( 'timezone_string', '' );

		return array(
			'locale'              => function_exists( 'get_locale' ) ? (string) get_locale() : '',
			'timezone'            => (string) $timezone,
			'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
			'show_on_front'       => (string) get_option( 'show_on_front', 'posts' ),
			'page_on_front'       => (int) get_option( 'page_on_front', 0 ),
			'page_for_posts'      => (int) get_option( 'page_for_posts', 0 ),
			'is_rtl'              => function_exists( 'is_rtl' ) ? (bool) is_rtl() : false,
		);
	}

	/**
	 * Returns bounded current-user capability information.
	 *
	 * @return array<string,mixed> Current-user summary.
	 */
	private function current_user_summary() {
		$capabilities = array(
			'read',
			'edit_posts',
			'publish_posts',
			'upload_files',
			'manage_categories',
			'manage_options',
			'install_plugins',
			'activate_plugins',
			'install_themes',
			'switch_themes',
			'list_users',
			'create_users',
			'edit_users',
			'delete_users',
		);
		$effective    = array();

		foreach ( $capabilities as $capability ) {
			$effective[ $capability ] = current_user_can( $capability );
		}

		return array(
			'id'           => (int) get_current_user_id(),
			'capabilities' => $effective,
		);
	}

	/**
	 * Returns active theme information.
	 *
	 * @return array<string,mixed> Theme summary.
	 */
	private function theme_summary() {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return array(
				'name'           => '',
				'version'        => '',
				'stylesheet'     => '',
				'template'       => '',
				'is_block_theme' => false,
			);
		}

		$theme = wp_get_theme();

		return array(
			'name'           => (string) $theme->get( 'Name' ),
			'version'        => (string) $theme->get( 'Version' ),
			'stylesheet'     => (string) $theme->get_stylesheet(),
			'template'       => (string) $theme->get_template(),
			'is_block_theme' => function_exists( 'wp_is_block_theme' ) ? (bool) wp_is_block_theme() : false,
		);
	}

	/**
	 * Returns installed plugin summaries without secrets.
	 *
	 * @return array<int,array<string,mixed>> Plugin summaries.
	 */
	private function plugin_summaries() {
		$active  = get_option( 'active_plugins', array() );
		$active  = is_array( $active ) ? array_values( $active ) : array();
		$plugins = array();

		if ( function_exists( 'get_plugins' ) ) {
			$plugins = get_plugins();
		} elseif ( defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			if ( function_exists( 'get_plugins' ) ) {
				$plugins = get_plugins();
			}
		}

		$results = array();
		foreach ( $plugins as $file => $data ) {
			$results[] = array(
				'file'    => (string) $file,
				'name'    => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'active'  => in_array( $file, $active, true ),
			);
		}

		return $results;
	}

	/**
	 * Returns editable post-type discovery information.
	 *
	 * @return array<int,array<string,mixed>> Post-type summaries.
	 */
	private function post_type_summaries() {
		if ( ! function_exists( 'get_post_types' ) ) {
			return array();
		}

		$objects = get_post_types( array( 'show_ui' => true ), 'objects' );
		$results = array();

		foreach ( $objects as $object ) {
			if ( ! is_object( $object ) || empty( $object->name ) ) {
				continue;
			}

			$results[] = array(
				'name'               => (string) $object->name,
				'label'              => isset( $object->label ) ? (string) $object->label : (string) $object->name,
				'hierarchical'       => ! empty( $object->hierarchical ),
				'show_in_rest'       => ! empty( $object->show_in_rest ),
				'rest_base'          => isset( $object->rest_base ) && $object->rest_base ? (string) $object->rest_base : (string) $object->name,
				'supports_editor'    => function_exists( 'post_type_supports' ) ? (bool) post_type_supports( $object->name, 'editor' ) : false,
				'supports_thumbnail' => function_exists( 'post_type_supports' ) ? (bool) post_type_supports( $object->name, 'thumbnail' ) : false,
			);
		}

		return $results;
	}

	/**
	 * Returns editable taxonomy discovery information.
	 *
	 * @return array<int,array<string,mixed>> Taxonomy summaries.
	 */
	private function taxonomy_summaries() {
		if ( ! function_exists( 'get_taxonomies' ) ) {
			return array();
		}

		$objects = get_taxonomies( array( 'show_ui' => true ), 'objects' );
		$results = array();

		foreach ( $objects as $object ) {
			if ( ! is_object( $object ) || empty( $object->name ) ) {
				continue;
			}

			$results[] = array(
				'name'         => (string) $object->name,
				'label'        => isset( $object->label ) ? (string) $object->label : (string) $object->name,
				'hierarchical' => ! empty( $object->hierarchical ),
				'show_in_rest' => ! empty( $object->show_in_rest ),
				'rest_base'    => isset( $object->rest_base ) && $object->rest_base ? (string) $object->rest_base : (string) $object->name,
				'object_types' => isset( $object->object_type ) && is_array( $object->object_type ) ? array_values( $object->object_type ) : array(),
			);
		}

		return $results;
	}

	/**
	 * Returns known compatible upstream Ability reuse candidates.
	 *
	 * @return array<string,string> Logical operation to Ability-name map.
	 */
	private function reuse_map() {
		$map    = array(
			'site_info'        => array( array( 'core/get-site-info' ), array() ),
			'user_info'        => array( array( 'core/get-user-info' ), array() ),
			'environment_info' => array( array( 'core/get-environment-info' ), array() ),
		);
		$result = array();

		foreach ( $map as $logical => $definition ) {
			$ability            = $this->resolver->find( $definition[0], $definition[1] );
			$result[ $logical ] = $ability ? (string) $ability->get_name() : '';
		}

		return $result;
	}

	/**
	 * Returns the site-context output schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function output_schema() {
		$closed_object = static function ( array $properties, array $required ) {
			return array(
				'type'                 => 'object',
				'properties'           => $properties,
				'required'             => $required,
				'additionalProperties' => false,
			);
		};

		$site          = $closed_object(
			array(
				'locale'              => array( 'type' => 'string' ),
				'timezone'            => array( 'type' => 'string' ),
				'permalink_structure' => array( 'type' => 'string' ),
				'show_on_front'       => array(
					'type' => 'string',
					'enum' => array( 'posts', 'page' ),
				),
				'page_on_front'       => array( 'type' => 'integer' ),
				'page_for_posts'      => array( 'type' => 'integer' ),
				'is_rtl'              => array( 'type' => 'boolean' ),
			),
			array( 'locale', 'timezone', 'permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts', 'is_rtl' )
		);
		$current_user  = $closed_object(
			array(
				'id'           => array( 'type' => 'integer' ),
				'capabilities' => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'boolean' ),
				),
			),
			array( 'id', 'capabilities' )
		);
		$theme         = $closed_object(
			array(
				'name'           => array( 'type' => 'string' ),
				'version'        => array( 'type' => 'string' ),
				'stylesheet'     => array( 'type' => 'string' ),
				'template'       => array( 'type' => 'string' ),
				'is_block_theme' => array( 'type' => 'boolean' ),
			),
			array( 'name', 'version', 'stylesheet', 'template', 'is_block_theme' )
		);
		$plugin        = $closed_object(
			array(
				'file'    => array( 'type' => 'string' ),
				'name'    => array( 'type' => 'string' ),
				'version' => array( 'type' => 'string' ),
				'active'  => array( 'type' => 'boolean' ),
			),
			array( 'file', 'name', 'version', 'active' )
		);
		$post_type     = $closed_object(
			array(
				'name'               => array( 'type' => 'string' ),
				'label'              => array( 'type' => 'string' ),
				'hierarchical'       => array( 'type' => 'boolean' ),
				'show_in_rest'       => array( 'type' => 'boolean' ),
				'rest_base'          => array( 'type' => 'string' ),
				'supports_editor'    => array( 'type' => 'boolean' ),
				'supports_thumbnail' => array( 'type' => 'boolean' ),
			),
			array( 'name', 'label', 'hierarchical', 'show_in_rest', 'rest_base', 'supports_editor', 'supports_thumbnail' )
		);
		$taxonomy      = $closed_object(
			array(
				'name'         => array( 'type' => 'string' ),
				'label'        => array( 'type' => 'string' ),
				'hierarchical' => array( 'type' => 'boolean' ),
				'show_in_rest' => array( 'type' => 'boolean' ),
				'rest_base'    => array( 'type' => 'string' ),
				'object_types' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'name', 'label', 'hierarchical', 'show_in_rest', 'rest_base', 'object_types' )
		);
		$reuse         = $closed_object(
			array(
				'site_info'        => array( 'type' => 'string' ),
				'user_info'        => array( 'type' => 'string' ),
				'environment_info' => array( 'type' => 'string' ),
			),
			array( 'site_info', 'user_info', 'environment_info' )
		);
		$external_item = $closed_object(
			array(
				'name'        => array( 'type' => 'string' ),
				'label'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'category'    => array( 'type' => 'string' ),
			),
			array( 'name', 'label', 'description', 'category' )
		);

		return $closed_object(
			array(
				'site'               => $site,
				'current_user'       => $current_user,
				'theme'              => $theme,
				'plugins'            => array(
					'type'  => 'array',
					'items' => $plugin,
				),
				'post_types'         => array(
					'type'  => 'array',
					'items' => $post_type,
				),
				'taxonomies'         => array(
					'type'  => 'array',
					'items' => $taxonomy,
				),
				'reuse'              => $reuse,
				'external_abilities' => array(
					'type'  => 'array',
					'items' => $external_item,
				),
			),
			array( 'site', 'current_user', 'theme', 'plugins', 'post_types', 'taxonomies', 'reuse', 'external_abilities' )
		);
	}
}
