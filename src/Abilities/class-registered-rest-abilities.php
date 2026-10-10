<?php
/**
 * Bounded, read-only inspection of registered WordPress REST route contracts.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Registry discovery only. This class deliberately cannot dispatch REST requests.
 */
final class Registered_REST_Abilities {
	const MAX_PAGE_SIZE   = 25;
	const MAX_INDEX_ROUTES = 1024;
	const MAX_ROUTE_BYTES = 512;
	const MAX_ENDPOINTS   = 16;
	const MAX_ARGUMENTS   = 80;

	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Bridge permission service. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/** @return array<int,object> Registered native Abilities. */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/rest-routes-read',
			array(
				'label'               => __( 'Read Registered REST Routes', 'wp-ai-bridge' ),
				'description'         => __( 'Discovers registered WordPress REST route names and bounded public index contracts. Does not run routes or determine execution permission.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'read' ),
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

	/** @return bool WordPress principal plus an independent default-off Bridge grant. */
	public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_REST_DISCOVERY, 'manage_options' );
	}

	/**
	 * Inspect the live local registry without invoking a provider endpoint or permission callback.
	 *
	 * @param mixed $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read( $input ) {
		if ( ! $this->can_read() ) {
			return new WP_Error( 'rest_routes_permission_denied', __( 'REST discovery requires an enabled Bridge grant and WordPress administration permission.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'action', 'route', 'namespace', 'page', 'per_page' ) ) ) {
			return $this->invalid_input();
		}

		$action = isset( $input['action'] ) ? $input['action'] : 'list';
		if ( ! in_array( $action, array( 'list', 'get' ), true ) || ! function_exists( 'rest_get_server' ) ) {
			return $this->invalid_input();
		}
		$server = rest_get_server();
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) || ! method_exists( $server, 'get_data_for_routes' ) ) {
			return new WP_Error( 'rest_routes_unavailable', __( 'The native WordPress REST route registry is unavailable.', 'wp-ai-bridge' ) );
		}

		if ( 'get' === $action ) {
			if ( array_diff( array_keys( $input ), array( 'action', 'route' ) ) || ! isset( $input['route'] ) || ! is_string( $input['route'] ) || strlen( $input['route'] ) > self::MAX_ROUTE_BYTES ) {
				return new WP_Error( 'rest_route_not_found', __( 'The exact registered REST route was not found.', 'wp-ai-bridge' ) );
			}

			// Core must first establish exact local route identity. Only that route is
			// converted to public index data, so get does not inspect every schema.
			$routes = $server->get_routes();
			if ( ! is_array( $routes ) ) {
				return new WP_Error( 'rest_routes_unavailable', __( 'The native WordPress REST route registry is unavailable.', 'wp-ai-bridge' ) );
			}
			$route = $input['route'];
			if ( ! isset( $routes[ $route ] ) || ! $this->has_public_handler( $routes[ $route ] ) ) {
				return new WP_Error( 'rest_route_not_found', __( 'The exact registered REST route was not found.', 'wp-ai-bridge' ) );
			}

			$index = $this->filtered_index( $server, array( $route => $routes[ $route ] ) );
			if ( is_wp_error( $index ) ) {
				return $index;
			}
			$item = isset( $index[ $route ] ) ? $this->contract( $route, $index[ $route ], true ) : null;
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			if ( null === $item ) {
				return new WP_Error( 'rest_route_not_found', __( 'The exact registered REST route was not found.', 'wp-ai-bridge' ) );
			}
			return $this->bounded(
				array(
					'items'                => array( $item ),
					'page'                 => 1,
					'per_page'             => 1,
					'total'                => 1,
					'total_pages'          => 1,
					'has_more'             => false,
					'execution_permission' => 'not_evaluated',
				)
			);
		}

		if ( array_diff( array_keys( $input ), array( 'action', 'namespace', 'page', 'per_page' ) ) ) {
			return $this->invalid_input();
		}
		$page      = isset( $input['page'] ) ? $input['page'] : 1;
		$per_page  = isset( $input['per_page'] ) ? $input['per_page'] : 20;
		$namespace = isset( $input['namespace'] ) ? $input['namespace'] : '';
		if ( ! is_int( $page ) || $page < 1 || ! is_int( $per_page ) || $per_page < 1 || $per_page > self::MAX_PAGE_SIZE || ! is_string( $namespace ) || strlen( $namespace ) > 100 || ( '' !== $namespace && ! preg_match( '#^[a-zA-Z0-9._/-]+$#D', $namespace ) ) ) {
			return $this->invalid_input();
		}
		if ( $page > intdiv( PHP_INT_MAX, $per_page ) ) {
			return $this->invalid_input();
		}

		// Native namespace filtering happens before the public-index conversion.
		// Count before converting; otherwise a small per_page still builds every
		// third-party provider schema and runs every public-index filter.
		$routes = $server->get_routes( '' !== $namespace ? trim( $namespace, '/' ) : '' );
		if ( ! is_array( $routes ) ) {
			return new WP_Error( 'rest_routes_unavailable', __( 'The native WordPress REST route registry is unavailable.', 'wp-ai-bridge' ) );
		}
		if ( count( $routes ) > self::MAX_INDEX_ROUTES ) {
			return new WP_Error( 'rest_routes_narrowing_required', __( 'Too many registered REST routes to inspect safely. Specify a smaller namespace.', 'wp-ai-bridge' ) );
		}
		$index = $this->filtered_index( $server, $routes );
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$matches = array();
		foreach ( $routes as $route => $callbacks ) {
			if ( ! is_string( $route ) || ! $this->has_public_handler( $callbacks ) || ! array_key_exists( $route, $index ) ) {
				continue;
			}
			// Only Core's post-filter public index is ever projected. A provider's
			// hidden route or removed/invalid filtered index item stays hidden.
			$item = $this->contract( $route, $index[ $route ], false );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			if ( null !== $item ) {
				$matches[ $route ] = $item;
			}
		}
		ksort( $matches, SORT_STRING );
		$total = count( $matches );
		$items = array();
		if ( ( $page - 1 ) * $per_page < $total ) {
			$items = array_values( array_slice( $matches, ( $page - 1 ) * $per_page, $per_page ) );
		}
		return $this->bounded(
			array(
				'items'                => $items,
				'page'                 => $page,
				'per_page'             => $per_page,
				'total'                => $total,
				'total_pages'          => (int) ceil( $total / $per_page ),
				'has_more'             => $page < (int) ceil( $total / $per_page ),
				'execution_permission' => 'not_evaluated',
			)
		);
	}

	/**
	 * Confirm Core registered at least one index-visible handler, independent
	 * of what subsequent metadata filters may append to their output.
	 *
	 * @param mixed $callbacks Core-normalized route callbacks.
	 * @return bool
	 */
	private function has_public_handler( $callbacks ) {
		if ( ! is_array( $callbacks ) ) {
			return false;
		}
		foreach ( $callbacks as $callback ) {
			if ( is_array( $callback ) && ! empty( $callback['show_in_index'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Use the same aggregate native public-index filter pipeline as Core.
	 * Per-route get_data_for_route() skips rest_endpoints_description and
	 * rest_route_data, so its unfiltered output must never be projected.
	 *
	 * @param object              $server Core REST server.
	 * @param array<string,mixed> $routes Exact normalized registered route map.
	 * @return array<string,mixed>|WP_Error
	 */
	private function filtered_index( $server, array $routes ) {
		$public = $server->get_data_for_routes( $routes, 'view' );
		if ( ! is_array( $public ) ) {
			return $this->unrepresentable();
		}
		// A filter may add arbitrary keys; registration is an independent
		// requirement even for metadata deliberately exposed in an index.
		return array_intersect_key( $public, $routes );
	}

	/**
	 * Project only selected, already-filtered public index fields. Never read
	 * a handler, provider callback, default, private schema or raw registry data.
	 *
	 * @param string $route  Exact registered route regex.
	 * @param mixed  $public Core's post-filter public index data for this route.
	 * @param bool   $detail Include bounded index argument metadata.
	 * @return array<string,mixed>|WP_Error|null Null means not public.
	 */
	private function contract( $route, $public, $detail ) {
		if ( strlen( $route ) > self::MAX_ROUTE_BYTES ) {
			return $this->unrepresentable();
		}
		if ( ! is_array( $public ) || ! isset( $public['methods'] ) || ! is_array( $public['methods'] ) ) {
			return null;
		}
		$methods = array_values( array_filter( $public['methods'], 'is_string' ) );
		if ( ! $methods ) {
			return null;
		}

		$item = array(
			'route'           => $route,
			'indexed'         => true,
			'methods'         => $methods,
			'contract_detail' => $detail ? 'name_type_required_only' : 'not_returned',
			'endpoints'       => array(),
		);
		if ( ! $detail ) {
			return $item;
		}
		$endpoints = isset( $public['endpoints'] ) ? $public['endpoints'] : array();
		if ( ! is_array( $endpoints ) || count( $endpoints ) > self::MAX_ENDPOINTS ) {
			return $this->unrepresentable();
		}
		foreach ( $endpoints as $endpoint ) {
			if ( ! is_array( $endpoint ) || ! isset( $endpoint['methods'] ) || ! is_array( $endpoint['methods'] ) ) {
				return $this->unrepresentable();
			}
			// If a public-index filter narrowed the route's methods, endpoint
			// details may not revive methods removed at the aggregate level.
			$endpoint_methods = array_values( array_intersect( array_filter( $endpoint['methods'], 'is_string' ), $methods ) );
			if ( ! $endpoint_methods ) {
				continue;
			}
			$args = isset( $endpoint['args'] ) ? $endpoint['args'] : array();
			if ( ! is_array( $args ) || count( $args ) > self::MAX_ARGUMENTS ) {
				return $this->unrepresentable();
			}
			$fields = array();
			foreach ( $args as $name => $schema ) {
				if ( ! is_string( $name ) || strlen( $name ) > 128 || ! is_array( $schema ) ) {
					return $this->unrepresentable();
				}
				$raw_type = isset( $schema['type'] ) ? $schema['type'] : 'unspecified';
				$type     = is_string( $raw_type ) && strlen( $raw_type ) <= 64 ? $raw_type : 'unspecified';
				$fields[] = array(
					'name'     => $name,
					'type'     => $type,
					'required' => ! empty( $schema['required'] ),
				);
			}
			$item['endpoints'][] = array(
				'methods'   => $endpoint_methods,
				'arguments' => $fields,
			);
		}
		return $item;
	}

	/** @param array<string,mixed> $data Result candidate. @return array<string,mixed>|WP_Error */
	private function bounded( array $data ) {
		if ( ! Bounded_Payload::fits( $data ) ) {
			return new WP_Error( 'rest_routes_response_too_large', __( 'REST route metadata exceeds the bounded response. Reduce the page size or request a smaller contract.', 'wp-ai-bridge' ) );
		}
		return $data;
	}

	/** @return WP_Error */
	private function unrepresentable() {
		return new WP_Error( 'rest_route_contract_unrepresentable', __( 'The registered REST route cannot be represented safely without omitting required metadata.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function invalid_input() {
		return new WP_Error( 'rest_routes_invalid_input', __( 'Use list with bounded pagination and an optional namespace, or get with an exact route.', 'wp-ai-bridge' ) );
	}

	/** @return array<string,mixed> */
	private function input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'    => array(
					'type'    => 'string',
					'enum'    => array( 'list', 'get' ),
					'default' => 'list',
				),
				'route'     => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => self::MAX_ROUTE_BYTES,
				),
				'namespace' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_PAGE_SIZE,
					'default' => 20,
				),
			),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items'                => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'page'                 => array( 'type' => 'integer' ),
				'per_page'             => array( 'type' => 'integer' ),
				'total'                => array( 'type' => 'integer' ),
				'total_pages'          => array( 'type' => 'integer' ),
				'has_more'             => array( 'type' => 'boolean' ),
				'execution_permission' => array(
					'type' => 'string',
					'enum' => array( 'not_evaluated' ),
				),
			),
			'required'             => array( 'items', 'page', 'per_page', 'total', 'total_pages', 'has_more', 'execution_permission' ),
			'additionalProperties' => false,
		);
	}
}
