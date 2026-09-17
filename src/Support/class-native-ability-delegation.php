<?php
/**
 * Administrator-controlled delegation for provider-native Abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Applies one Bridge policy gate to registered non-Bridge Abilities invoked through
 * the exact WP AI Bridge MCP routes while preserving native provider permissions.
 */
final class Native_Ability_Delegation {
	const ADAPTER_EXECUTE_ABILITY = 'mcp-adapter/execute-ability';

	/** @var array<int,string> */
	private $bridge_routes = array(
		'/wp-ai-bridge/v1/mcp',
		'/wp-ai-bridge/v1/mcp',
	);

	/** @var Settings */
	private $settings;

	/** @var int */
	private $bridge_request_depth = 0;

	/** @var \SplObjectStorage<object,mixed> */
	private $bridge_owned_abilities;

	/**
	 * @param Settings $settings Bridge access-group settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings               = $settings;
		$this->bridge_owned_abilities = new \SplObjectStorage();
	}

	/** @return void */
	public function boot() {
		add_filter( 'wp_register_ability_args', array( $this, 'filter_ability_args' ), PHP_INT_MAX, 2 );
		add_filter( 'rest_endpoints', array( $this, 'filter_rest_endpoints' ), PHP_INT_MAX );
	}


	/**
	 * Remembers exact successful Core registration return objects as Bridge-owned.
	 *
	 * WordPress remains the sole Ability registry. This stores only object identity
	 * returned by the Bridge's own wp_register_ability() calls.
	 *
	 * @param array<int,object> $abilities Exact successful Core registration results.
	 * @return void
	 */
	public function remember_bridge_abilities( array $abilities ) {
		foreach ( $abilities as $ability ) {
			if ( is_object( $ability ) ) {
				$this->bridge_owned_abilities->attach( $ability );
			}
		}
	}

	/**
	 * Wraps only the Adapter generic execution permission callback.
	 * execution permission callback.
	 *
	 * Provider metadata, annotations, namespaces, class names and custom Ability
	 * virtual methods are deliberately irrelevant to Bridge ownership provenance.
	 *
	 * @param array<string,mixed> $args Ability registration arguments.
	 * @param string              $name Ability name.
	 * @return array<string,mixed>
	 */
	public function filter_ability_args( $args, $name ) {
		if ( ! is_array( $args ) || ! is_string( $name ) ) {
			return $args;
		}

		if ( self::ADAPTER_EXECUTE_ABILITY !== $name || empty( $args['permission_callback'] ) || ! is_callable( $args['permission_callback'] ) ) {
			return $args;
		}

		$original_permission         = $args['permission_callback'];
		$args['permission_callback'] = function ( $input = array() ) use ( $original_permission ) {
			if ( ! $this->in_bridge_request() || ! is_array( $input ) || empty( $input['ability_name'] ) || ! is_string( $input['ability_name'] ) ) {
				return call_user_func( $original_permission, $input );
			}

			$target = function_exists( 'wp_get_ability' ) ? wp_get_ability( $input['ability_name'] ) : null;
			if ( ! $target || $this->is_bridge_owned_ability( $target ) ) {
				return call_user_func( $original_permission, $input );
			}

			if ( ! $this->settings->is_enabled( Settings::GROUP_NATIVE_ABILITIES ) ) {
				return new WP_Error(
					'wp_ai_bridge_native_abilities_disabled',
					__( 'Native Abilities access is disabled in WP AI Bridge settings.', 'wp-ai-bridge' )
				);
			}

			return call_user_func( $original_permission, $input );
		};

		return $args;
	}

	/**
	 * Wraps only the exact canonical and legacy Bridge MCP callbacks. The request
	 * context therefore encloses Adapter tool permission and execution without
	 * changing transport authentication or unrelated REST requests.
	 *
	 * @param array<string,mixed> $endpoints Registered REST endpoints.
	 * @return array<string,mixed>
	 */
	public function filter_rest_endpoints( $endpoints ) {
		if ( ! is_array( $endpoints ) ) {
			return $endpoints;
		}

		foreach ( $this->bridge_routes as $route ) {
			if ( empty( $endpoints[ $route ] ) || ! is_array( $endpoints[ $route ] ) ) {
				continue;
			}

			foreach ( $endpoints[ $route ] as $index => $handler ) {
				if ( ! is_array( $handler ) || empty( $handler['callback'] ) || ! is_callable( $handler['callback'] ) ) {
					continue;
				}

				$callback                                  = $handler['callback'];
				$endpoints[ $route ][ $index ]['callback'] = function ( $request ) use ( $callback ) {
					++$this->bridge_request_depth;
					try {
						return call_user_func( $callback, $request );
					} finally {
						$this->bridge_request_depth = max( 0, $this->bridge_request_depth - 1 );
					}
				};
			}
		}

		return $endpoints;
	}

	/**
	 * Reports whether an exact live Ability object belongs to the authoritative
	 * Bridge provenance set.
	 *
	 * @param mixed $ability Ability object.
	 * @return bool
	 */
	public function is_bridge_owned_ability( $ability ) {
		return is_object( $ability ) && $this->bridge_owned_abilities->contains( $ability );
	}

	/** @return bool */
	private function in_bridge_request() {
		return $this->bridge_request_depth > 0;
	}
}
