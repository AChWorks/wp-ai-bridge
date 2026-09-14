<?php
/**
 * Administrator-controlled delegation for provider-native Abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Support;

use WP_Error;

/**
 * Applies one Bridge policy gate to registered non-Bridge Abilities invoked through
 * the exact WP AI Bridge MCP routes while preserving native provider permissions.
 */
final class Native_Ability_Delegation {
	const ADAPTER_EXECUTE_ABILITY = 'mcp-adapter/execute-ability';
	const BRIDGE_OWNED_META       = 'wp_ai_bridge_owned';

	/** @var array<int,string> */
	private $bridge_routes = array(
		'/wp-ai-bridge/v1/mcp',
		'/wp-native-builder/v1/mcp',
	);

	/** @var Settings */
	private $settings;

	/** @var int */
	private $bridge_request_depth = 0;

	/**
	 * @param Settings $settings Bridge access-group settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/** @return void */
	public function boot() {
		add_filter( 'wp_register_ability_args', array( $this, 'filter_ability_args' ), PHP_INT_MAX, 2 );
		add_filter( 'rest_endpoints', array( $this, 'filter_rest_endpoints' ), PHP_INT_MAX );
	}

	/**
	 * Marks successfully registering Bridge-owned Abilities and wraps only the
	 * Adapter's generic execution permission callback.
	 *
	 * WordPress applies this filter after duplicate-name rejection, so a third-party
	 * Ability that already owns a historical Bridge name cannot inherit the marker.
	 *
	 * @param array<string,mixed> $args Ability registration arguments.
	 * @param string              $name Ability name.
	 * @return array<string,mixed>
	 */
	public function filter_ability_args( $args, $name ) {
		if ( ! is_array( $args ) || ! is_string( $name ) ) {
			return $args;
		}

		if ( $this->is_bridge_registration( $name, $args ) ) {
			$meta                            = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
			$meta[ self::BRIDGE_OWNED_META ] = true;
			$args['meta']                    = $meta;
			return $args;
		}

		if ( self::ADAPTER_EXECUTE_ABILITY !== $name || empty( $args['permission_callback'] ) || ! is_callable( $args['permission_callback'] ) ) {
			return $args;
		}

		$original_permission = $args['permission_callback'];
		$args['permission_callback'] = function ( $input = array() ) use ( $original_permission ) {
			if ( ! $this->in_bridge_request() || ! is_array( $input ) || empty( $input['ability_name'] ) || ! is_string( $input['ability_name'] ) ) {
				return call_user_func( $original_permission, $input );
			}

			$target = function_exists( 'wp_get_ability' ) ? wp_get_ability( $input['ability_name'] ) : null;
			if ( ! $target || self::is_bridge_owned_ability( $target ) ) {
				return call_user_func( $original_permission, $input );
			}

			if ( ! $this->settings->is_enabled( Settings::GROUP_NATIVE_ABILITIES ) ) {
				return new WP_Error(
					'wp_ai_bridge_native_abilities_disabled',
					__( 'Native Abilities access is disabled in WP AI Bridge settings.', 'wp-native-builder-bridge' )
				);
			}

			return call_user_func( $original_permission, $input );
		};

		return $args;
	}

	/**
	 * Wraps only the exact canonical and legacy Bridge MCP callbacks. The marker
	 * therefore encloses Adapter tool permission and execution without changing
	 * transport authentication or unrelated REST requests.
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

				$callback = $handler['callback'];
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
	 * Reports whether an actual registered Ability carries the Bridge ownership marker.
	 *
	 * @param mixed $ability Ability object.
	 * @return bool
	 */
	public static function is_bridge_owned_ability( $ability ) {
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
			return false;
		}
		$meta = $ability->get_meta();
		return is_array( $meta ) && true === ( $meta[ self::BRIDGE_OWNED_META ] ?? false );
	}

	/** @return bool */
	private function in_bridge_request() {
		return $this->bridge_request_depth > 0;
	}

	/**
	 * @param string              $name Ability name.
	 * @param array<string,mixed> $args Registration arguments.
	 * @return bool
	 */
	private function is_bridge_registration( $name, array $args ) {
		return 0 === strpos( $name, 'wp-native-builder/' )
			&& $this->is_bridge_callback( $args['permission_callback'] ?? null )
			&& $this->is_bridge_callback( $args['execute_callback'] ?? null );
	}

	/**
	 * @param mixed $callback Registered callback.
	 * @return bool
	 */
	private function is_bridge_callback( $callback ) {
		if ( ! is_array( $callback ) || 2 !== count( $callback ) ) {
			return false;
		}
		$owner = $callback[0];
		$class = is_object( $owner ) ? get_class( $owner ) : ( is_string( $owner ) ? $owner : '' );
		return 0 === strpos( $class, 'WP_Native_Builder_Bridge\\' );
	}
}
