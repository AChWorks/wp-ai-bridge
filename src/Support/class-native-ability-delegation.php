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

	/** @var array<int,string> */
	private $bridge_routes = array(
		'/wp-ai-bridge/v1/mcp',
		'/wp-native-builder/v1/mcp',
	);

	/** @var Settings */
	private $settings;

	/** @var int */
	private $bridge_request_depth = 0;

	/** @var int */
	private $bridge_registration_depth = 0;

	/** @var array<string,bool> */
	private $pending_bridge_ability_names = array();

	/** @var \SplObjectStorage<object,mixed>|null */
	private static $bridge_owned_abilities = null;

	/**
	 * @param Settings $settings Bridge access-group settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		self::ownership_store();
	}

	/** @return void */
	public function boot() {
		add_filter( 'wp_register_ability_args', array( $this, 'filter_ability_args' ), PHP_INT_MAX, 2 );
		add_filter( 'rest_endpoints', array( $this, 'filter_rest_endpoints' ), PHP_INT_MAX );
	}

	/**
	 * Runs the Bridge registrar inside a private provenance phase, then binds every
	 * successfully registered Bridge Ability name observed during that exact call
	 * stack to the live object currently held by WordPress.
	 *
	 * Provider registrations on their own hooks never enter this phase. Finalization
	 * happens before control returns to later `wp_abilities_api_init` callbacks, so a
	 * later unregister/re-register cannot inherit the original object's ownership.
	 *
	 * @param callable $registration_callback Bridge registrar callback.
	 * @return void
	 */
	public function capture_bridge_registrations( $registration_callback ) {
		if ( ! is_callable( $registration_callback ) ) {
			return;
		}

		++$this->bridge_registration_depth;
		try {
			call_user_func( $registration_callback );
		} finally {
			$this->bridge_registration_depth = max( 0, $this->bridge_registration_depth - 1 );
			$this->finalize_bridge_ownership();
		}
	}

	/**
	 * Records names only while the Bridge registrar owns the synchronous registration
	 * phase and wraps only the Adapter's generic execution permission callback.
	 *
	 * Provider metadata, annotations, namespaces and custom Ability virtual methods
	 * are deliberately irrelevant to Bridge ownership provenance.
	 *
	 * @param array<string,mixed> $args Ability registration arguments.
	 * @param string              $name Ability name.
	 * @return array<string,mixed>
	 */
	public function filter_ability_args( $args, $name ) {
		if ( ! is_array( $args ) || ! is_string( $name ) ) {
			return $args;
		}

		if ( $this->bridge_registration_depth > 0 ) {
			$this->pending_bridge_ability_names[ $name ] = true;
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
	 * Reports whether an exact live Ability object was registered during the private
	 * Bridge registrar phase.
	 *
	 * Provider-controlled metadata and virtual getters are intentionally irrelevant
	 * to this authorization decision.
	 *
	 * @param mixed $ability Ability object.
	 * @return bool
	 */
	public static function is_bridge_owned_ability( $ability ) {
		return is_object( $ability ) && self::ownership_store()->contains( $ability );
	}

	/**
	 * Converts pending names observed inside the Bridge registrar call stack into
	 * exact live object identities from the authoritative WordPress registry.
	 *
	 * @return void
	 */
	private function finalize_bridge_ownership() {
		$names                              = array_keys( $this->pending_bridge_ability_names );
		$this->pending_bridge_ability_names = array();
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return;
		}

		$store = self::ownership_store();
		foreach ( $names as $name ) {
			$ability = wp_get_ability( $name );
			if ( is_object( $ability ) ) {
				$store->attach( $ability );
			}
		}
	}

	/**
	 * Returns the private Bridge ownership identity set.
	 *
	 * @return \SplObjectStorage<object,mixed>
	 */
	private static function ownership_store() {
		if ( null === self::$bridge_owned_abilities ) {
			self::$bridge_owned_abilities = new \SplObjectStorage();
		}
		return self::$bridge_owned_abilities;
	}

	/** @return bool */
	private function in_bridge_request() {
		return $this->bridge_request_depth > 0;
	}
}
