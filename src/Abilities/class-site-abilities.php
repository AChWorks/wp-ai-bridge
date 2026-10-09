<?php
/**
 * Site inspection abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Registers the Bridge-owned site context supplement.
 */
final class Site_Abilities {
	/** Maximum bytes of each projected scalar metadata field. */
	const FIELD_BYTES = 512;

	/** Reserve room for the MCP Adapter wrapping the Ability result. */
	const RESPONSE_HEADROOM_BYTES = 2048;
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
					'properties'           => array(
						'section' => array(
							'type' => 'string',
							'enum' => array( 'all', 'site', 'current_user', 'theme', 'plugins', 'post_types', 'taxonomies', 'reuse', 'external_abilities' ),
						),
						'offset'  => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'limit'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
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
	 * Returns legacy small-site context, or bounded selected context on large sites.
	 *
	 * A default request never silently drops collections. On oversized sites it
	 * returns the current principal's capability flags with explicit omissions;
	 * callers can request individual sections, then page the larger collections.
	 *
	 * @param array<string,mixed> $input Validated selection/pagination, or empty for legacy mode.
	 * @return array<string,mixed>|WP_Error Site context or a typed denial.
	 */
	public function execute( $input = array() ) {
		if ( ! $this->can_execute() ) {
			return new WP_Error( 'site_context_permission_denied', __( 'Site Read access and the WordPress read capability are required to inspect site context.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'section', 'offset', 'limit' ) ) ) {
			return $this->invalid_input();
		}

		$section     = isset( $input['section'] ) ? $input['section'] : 'all';
		$collections = array( 'plugins', 'post_types', 'taxonomies', 'external_abilities' );
		$sections    = array( 'site', 'current_user', 'theme', 'plugins', 'post_types', 'taxonomies', 'reuse', 'external_abilities' );
		if ( ! is_string( $section ) || ( 'all' !== $section && ! in_array( $section, $sections, true ) ) ) {
			return $this->invalid_input();
		}
		if ( ! in_array( $section, $collections, true ) && ( array_key_exists( 'offset', $input ) || array_key_exists( 'limit', $input ) ) ) {
			return $this->invalid_input();
		}

		if ( 'all' === $section ) {
			// A 51st public hint means the legacy first-50 view would be incomplete.
			$external = $this->resolver->public_catalog( 51 );
			$result   = array(
				'site'               => $this->site_summary(),
				'current_user'       => $this->current_user_summary(),
				'theme'              => $this->theme_summary(),
				'plugins'            => $this->plugin_summaries(),
				'post_types'         => $this->post_type_summaries(),
				'taxonomies'         => $this->taxonomy_summaries(),
				'reuse'              => $this->reuse_map(),
				'external_abilities' => array_slice( $external, 0, 50 ),
			);
			if ( count( $external ) <= 50 && $this->fits_response( $result ) ) {
				return $result; // Exact historical shape for genuinely small sites.
			}

			return array(
				'current_user' => $result['current_user'],
				'projection'   => array(
					'section'          => 'all',
					'reason'           => count( $external ) > 50 ? 'catalog_limit' : 'response_budget',
					'omitted_sections' => array_values( array_diff( $sections, array( 'current_user' ) ) ),
				),
			);
		}

		if ( ! in_array( $section, $collections, true ) ) {
			$method = array(
				'site'         => 'site_summary',
				'current_user' => 'current_user_summary',
				'theme'        => 'theme_summary',
				'reuse'        => 'reuse_map',
			);
			$record = $this->compact_record( $this->{$method[ $section ]}() );
			$cut    = $record['truncated_fields'] ?? array();
			unset( $record['truncated_fields'] );
			$result = array( $section => $record );
			if ( $cut ) {
				$result['projection'] = array(
					'section'          => $section,
					'reason'           => 'field_budget',
					'truncated_fields' => $cut,
				);
			}
			return $this->fits_response( $result ) ? $result : $this->too_large();
		}

		$offset = isset( $input['offset'] ) ? $input['offset'] : 0;
		$limit  = isset( $input['limit'] ) ? $input['limit'] : 25;
		if ( ! is_int( $offset ) || $offset < 0 || ! is_int( $limit ) || $limit < 1 || $limit > 50 ) {
			return $this->invalid_input();
		}
		if ( 'external_abilities' === $section ) {
			$window = $this->resolver->public_catalog( $limit + 1, $offset );
		} else {
			$method = array(
				'plugins'    => 'plugin_summaries',
				'post_types' => 'post_type_summaries',
				'taxonomies' => 'taxonomy_summaries',
			);
			$window = array_slice( $this->{$method[ $section ]}(), $offset, $limit + 1 );
		}
		$items     = array();
		$truncated = false;
		foreach ( array_slice( $window, 0, $limit ) as $item ) {
			$compact   = $this->compact_record( $item );
			$has_more  = count( $window ) > count( $items ) + 1;
			$candidate = $this->page_result( $section, $offset, $limit, array_merge( $items, array( $compact ) ), $has_more, $truncated || isset( $compact['truncated_fields'] ) );
			if ( ! $this->fits_response( $candidate ) ) {
				if ( ! $items ) {
					return $this->too_large();
				}
				break;
			}
			$items[]   = $compact;
			$truncated = $truncated || isset( $compact['truncated_fields'] );
		}
		$has_more = count( $window ) > count( $items );
		$result   = $this->page_result( $section, $offset, $limit, $items, $has_more, $truncated );
		return $this->fits_response( $result ) ? $result : $this->too_large();
	}

	/** @return WP_Error Invalid selector or pagination. */
	private function invalid_input() {
		return new WP_Error( 'site_context_invalid_input', __( 'Invalid site-context selection or pagination.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error Non-representable response, without returning unbounded data. */
	private function too_large() {
		return new WP_Error( 'site_context_response_too_large', __( 'The site-context selection exceeds the bounded response. Request a smaller limit or another section.', 'wp-ai-bridge' ) );
	}

	/**
	 * Keep every emitted Ability result comfortably below the MCP envelope.
	 *
	 * @param mixed $result JSON-safe result.
	 * @return bool
	 */
	private function fits_response( $result ) {
		// Avoid allocating a huge encoded string for very large provider fields.
		$remaining = Bounded_Payload::RESPONSE_BYTES - self::RESPONSE_HEADROOM_BYTES;
		if ( ! $this->within_raw_budget( $result, $remaining ) ) {
			return false;
		}
		$json = wp_json_encode( $result );
		return is_string( $json ) && strlen( $json ) <= Bounded_Payload::RESPONSE_BYTES - self::RESPONSE_HEADROOM_BYTES;
	}

	/**
	 * A cheap lower bound on JSON bytes before serialization; never trust provider sizes.
	 *
	 * @param mixed $value     Result tree.
	 * @param int   $remaining Remaining lower-bound budget (updated by reference).
	 * @param int   $depth     Bounded known output nesting.
	 * @return bool
	 */
	private function within_raw_budget( $value, &$remaining, $depth = 0 ) {
		if ( $depth > 8 ) {
			return false;
		}
		if ( is_string( $value ) ) {
			$remaining -= strlen( $value );
			return $remaining >= 0;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $nested ) {
				// Account for minimal JSON delimiters and associative key names.
				$remaining -= is_string( $key ) ? strlen( $key ) + 3 : 1;
				if ( $remaining < 0 || ! $this->within_raw_budget( $nested, $remaining, $depth + 1 ) ) {
					return false;
				}
			}
		}
		return $remaining >= 0;
	}

	/**
	 * Bound untrusted public labels/descriptions without silently fabricating full values.
	 *
	 * @param array<string,mixed> $record Known allowlisted metadata record.
	 * @return array<string,mixed> Compact record; optional truncated_fields identifies every lossy field.
	 */
	private function compact_record( array $record ) {
		$cut = array();
		foreach ( $record as $field => $value ) {
			if ( is_string( $value ) ) {
				$window = Bounded_Payload::text_window( $value, 0, self::FIELD_BYTES );
				if ( is_wp_error( $window ) ) {
					$record[ $field ] = '';
					$cut[]            = $field;
				} else {
					$record[ $field ] = $window['content'];
					if ( ! $window['complete'] ) {
						$cut[] = $field;
					}
				}
			} elseif ( is_array( $value ) && 'object_types' === $field ) {
				// Taxonomy object types are an allowlisted string list, never arbitrary options.
				$reduced = array();
				foreach ( array_slice( $value, 0, 16 ) as $entry ) {
					$part      = Bounded_Payload::text_window( (string) $entry, 0, self::FIELD_BYTES );
					$reduced[] = is_wp_error( $part ) ? '' : $part['content'];
					if ( is_wp_error( $part ) || ! $part['complete'] ) {
						$cut[] = $field;
					}
				}
				$record[ $field ] = $reduced;
				if ( count( $value ) > 16 ) {
					$cut[] = $field;
				}
			}
		}
		if ( $cut ) {
			$record['truncated_fields'] = array_values( array_unique( $cut ) );
		}
		return $record;
	}

	/**
	 * Single small page envelope with explicit progress and a field-loss indicator.
	 *
	 * @param string $section   Selected metadata collection.
	 * @param int    $offset    Initial index for this request.
	 * @param int    $limit     Requested maximum items.
	 * @param array  $items     Exactly represented projected items.
	 * @param bool   $has_more  More source items available.
	 * @param bool   $truncated Whether any string/list was projected.
	 * @return array<string,mixed>
	 */
	private function page_result( $section, $offset, $limit, array $items, $has_more, $truncated ) {
		$page = array(
			'section'              => $section,
			'offset'               => $offset,
			'limit'                => $limit,
			'returned'             => count( $items ),
			'has_more'             => (bool) $has_more,
			'projection_truncated' => (bool) $truncated,
		);
		if ( $has_more ) {
			$page['next_offset'] = $offset + count( $items );
		}
		return array(
			$section => $items,
			'page'   => $page,
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
			$schema = array(
				'type'                 => 'object',
				'properties'           => $properties,
				'additionalProperties' => false,
			);
			if ( $required ) {
				$schema['required'] = $required;
			}
			return $schema;
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

		$truncated_fields                                = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		$plugin['properties']['truncated_fields']        = $truncated_fields;
		$post_type['properties']['truncated_fields']     = $truncated_fields;
		$taxonomy['properties']['truncated_fields']      = $truncated_fields;
		$external_item['properties']['truncated_fields'] = $truncated_fields;
		$page       = $closed_object(
			array(
				'section'              => array( 'type' => 'string' ),
				'offset'               => array( 'type' => 'integer' ),
				'limit'                => array( 'type' => 'integer' ),
				'returned'             => array( 'type' => 'integer' ),
				'has_more'             => array( 'type' => 'boolean' ),
				'next_offset'          => array( 'type' => 'integer' ),
				'projection_truncated' => array( 'type' => 'boolean' ),
			),
			array( 'section', 'offset', 'limit', 'returned', 'has_more', 'projection_truncated' )
		);
		$projection = $closed_object(
			array(
				'section'          => array( 'type' => 'string' ),
				'reason'           => array(
					'type' => 'string',
					'enum' => array( 'response_budget', 'catalog_limit', 'field_budget' ),
				),
				'omitted_sections' => $truncated_fields,
				'truncated_fields' => $truncated_fields,
			),
			array( 'section', 'reason' )
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
				'page'               => $page,
				'projection'         => $projection,
			),
			array() // Individual section requests and the oversized legacy summary are intentionally sparse.
		);
	}
}
