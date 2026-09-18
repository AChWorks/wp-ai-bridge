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
	const ADAPTER_EXECUTE_ABILITY          = 'mcp-adapter/execute-ability';
	const NATIVE_RESULT_INSPECTION_BUDGET = 4194304;

	/** @var array<int,string> */
	private $bridge_routes = array(
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
	 * Wraps the Adapter generic execution permission and result callbacks.
	 *
	 * Provider metadata, annotations, namespaces, class names and custom Ability
	 * virtual methods are deliberately irrelevant to Bridge ownership provenance.
	 * Provider-native errors and results are additionally bounded at the exact
	 * Bridge MCP request boundary so credentials cannot escape through the Adapter.
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

			try {
				$result = call_user_func( $original_permission, $input );
			} catch ( \Throwable $throwable ) {
				return new WP_Error(
					'wp_ai_bridge_native_ability_permission_failed',
					__( 'Native Ability permission check failed.', 'wp-ai-bridge' )
				);
			}

			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'wp_ai_bridge_native_ability_permission_denied',
					__( 'Native Ability permission was denied.', 'wp-ai-bridge' )
				);
			}

			return $result;
		};

		if ( ! empty( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$original_execute         = $args['execute_callback'];
			$args['execute_callback'] = function ( $input = array() ) use ( $original_execute ) {
				if ( ! $this->in_bridge_request() || ! is_array( $input ) || empty( $input['ability_name'] ) || ! is_string( $input['ability_name'] ) ) {
					return call_user_func( $original_execute, $input );
				}

				$target = function_exists( 'wp_get_ability' ) ? wp_get_ability( $input['ability_name'] ) : null;
				if ( ! $target || $this->is_bridge_owned_ability( $target ) ) {
					return call_user_func( $original_execute, $input );
				}

				try {
					$result = call_user_func( $original_execute, $input );
				} catch ( \Throwable $throwable ) {
					return $this->native_execution_failure();
				}

				return $this->sanitize_native_execution_result( $result );
			};
		}

		return $args;
	}

	/**
	 * Replaces provider-native execution failures and unsafe result shapes with
	 * bounded Bridge-owned responses that cannot carry provider secret material.
	 *
	 * @param mixed $result Adapter execute-ability callback result.
	 * @return array<string,mixed>
	 */
	private function sanitize_native_execution_result( $result ) {
		if ( ! is_array( $result ) || ! array_key_exists( 'success', $result ) || ! is_bool( $result['success'] ) ) {
			return $this->native_result_blocked();
		}

		if ( false === $result['success'] ) {
			return $this->native_execution_failure();
		}

		if ( ! array_key_exists( 'data', $result ) ) {
			return $this->native_result_blocked();
		}

		$remaining = self::NATIVE_RESULT_INSPECTION_BUDGET;
		if ( ! $this->is_safe_native_result( $result['data'], $remaining ) ) {
			return $this->native_result_blocked();
		}

		return $result;
	}

	/**
	 * Traverses JSON-compatible provider data without invoking custom serializers.
	 *
	 * @param mixed $value     Provider result value.
	 * @param int   $remaining Remaining inspection budget.
	 * @param int   $depth     Current nesting depth.
	 * @return bool
	 */
	private function is_safe_native_result( $value, &$remaining, $depth = 0 ) {
		if ( $depth > 64 || is_resource( $value ) || ( is_object( $value ) && ! $value instanceof \stdClass ) ) {
			return false;
		}

		$remaining -= is_string( $value ) ? strlen( $value ) : 1;
		if ( $remaining < 0 ) {
			return false;
		}

		if ( is_array( $value ) || $value instanceof \stdClass ) {
			foreach ( (array) $value as $key => $child ) {
				if ( is_string( $key ) ) {
					$remaining -= strlen( $key );
					if ( $remaining < 0 || Metadata_Key_Policy::is_sensitive( $key ) ) {
						return false;
					}
				} else {
					--$remaining;
					if ( $remaining < 0 ) {
						return false;
					}
				}

				if ( ! $this->is_safe_native_result( $child, $remaining, $depth + 1 ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/** @return array<string,mixed> */
	private function native_execution_failure() {
		return array(
			'success' => false,
			'error'   => __( 'Native Ability execution failed.', 'wp-ai-bridge' ),
		);
	}

	/** @return array<string,mixed> */
	private function native_result_blocked() {
		return array(
			'success' => false,
			'error'   => __( 'Native Ability result was blocked because it may contain credential or security data.', 'wp-ai-bridge' ),
		);
	}

	/**
	 * Wraps only the exact canonical Bridge MCP callback. The request
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
