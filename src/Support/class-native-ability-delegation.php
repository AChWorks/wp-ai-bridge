<?php
/**
 * Administrator-controlled delegation for provider-native Abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/**
 * Applies one Bridge policy gate to registered non-Bridge Abilities invoked through
 * the exact WP AI Bridge MCP routes while preserving native provider permissions.
 */
final class Native_Ability_Delegation {
	const ADAPTER_EXECUTE_ABILITY         = 'mcp-adapter/execute-ability';
	const ADAPTER_EXECUTE_TOOL            = 'mcp-adapter-execute-ability';
	const BRIDGE_MCP_SERVER_ID            = 'wp-ai-bridge-direct';
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

	/** @var array<string,mixed>|null One sanitized error captured inside the current request. */
	private $pending_mcp_error = null;

	/** @var Mutation_Log */
	private $diagnostic_log;

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
		add_filter( 'mcp_adapter_tool_call_result', array( $this, 'filter_tool_call_result' ), PHP_INT_MAX, 5 );
		add_filter( 'wp_ability_execute_result', array( $this, 'observe_ability_execute_result' ), PHP_INT_MAX, 4 );
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
	 * Observe execution failures from callbacks actually implemented in the
	 * plugin's Abilities source directory. This is instrumentation provenance
	 * only: Native Abilities ownership still depends exclusively on exact
	 * successful WordPress registration return objects (SplObjectStorage).
	 *
	 * @param mixed $callback Registered WordPress execute callback.
	 * @return bool
	 */
	private function has_bridge_source_callback( $callback ) {
		if ( ! is_array( $callback ) || 2 !== count( $callback ) ||
			! is_object( $callback[0] ) || ! is_string( $callback[1] ) ||
			! is_callable( $callback ) ) {
			return false;
		}
		try {
			$source = ( new \ReflectionMethod( $callback[0], $callback[1] ) )->getFileName();
		} catch ( \ReflectionException $exception ) {
			// A magic __call() can make a callback callable without a real method.
			return false;
		}
		$actual  = is_string( $source ) ? realpath( $source ) : false;
		$trusted = realpath( dirname( __DIR__ ) . '/Abilities' );
		return false !== $trusted && false !== $actual &&
			0 === strpos( $actual, $trusted . DIRECTORY_SEPARATOR );
	}

	/**
	 * Wraps the Adapter generic execution permission callback.
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

		// Capture the true first-party callback result before the Adapter's
		// ExecuteAbilityAbility converts WP_Error into message-only success=false.
		// Do not trust a provider's namespace or metadata to establish ownership.
		if ( 0 === strpos( $name, 'wp-ai-bridge/' ) &&
			! empty( $args['execute_callback'] ) &&
			$this->has_bridge_source_callback( $args['execute_callback'] )
		) {
			$original_execute         = $args['execute_callback'];
			$args['execute_callback'] = function ( $input = null ) use ( $original_execute, $name ) {
				$result = call_user_func( $original_execute, $input );
				if ( is_wp_error( $result ) && $this->in_bridge_request() ) {
					$this->capture_mcp_error( $name, $result, 'bridge_ability', 'ability_execution' );
				}
				return $result;
			};
			if ( ! empty( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ) {
				$original_permission         = $args['permission_callback'];
				$args['permission_callback'] = function ( $input = null ) use ( $original_permission, $name ) {
					$result = call_user_func( $original_permission, $input );
					if ( $this->in_bridge_request() ) {
						if ( is_wp_error( $result ) ) {
							$this->capture_mcp_error( $name, $result, 'bridge_ability', 'authorization' );
						} elseif ( false === $result ) {
							$this->capture_mcp_error(
								$name,
								Error_Details::from_adapter_message( 'WordPress Ability permission callback returned false.' ),
								'bridge_ability',
								'authorization'
							);
						}
					}
					return $result;
				};
			}
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
				$denied = new WP_Error(
					'wp_ai_bridge_native_abilities_disabled',
					__( 'Native Abilities access is disabled in WP AI Bridge settings.', 'wp-ai-bridge' )
				);
				$this->capture_mcp_error( $input['ability_name'], $denied, 'bridge_policy', 'authorization' );
				return $denied;
			}

			try {
				$result = call_user_func( $original_permission, $input );
			} catch ( \Throwable $throwable ) {
				// Throwable is not a WP_Error and has no original WP error code.
				$details = Error_Details::from_adapter_message( $throwable->getMessage() );
				$safe    = new WP_Error(
					'native_permission_exception',
					$details['message'],
					array(
						'exception_class' => get_class( $throwable ),
						'exception_code'  => $throwable->getCode(),
					)
				);
				$this->capture_mcp_error( $input['ability_name'], $safe, 'native_ability', 'authorization' );
				return $safe;
			}

			if ( is_wp_error( $result ) ) {
				// Preserve the provider's error identifier and non-sensitive
				// information instead of replacing it with a generic denial.
				$details = Error_Details::from_wp_error( $result );
				$safe    = new WP_Error( $details['code'] ?? '', $details['message'], $details['data'] );
				$this->capture_mcp_error( $input['ability_name'], $safe, 'native_ability', 'authorization' );
				return $safe;
			}

			if ( false === $result ) {
				$this->capture_mcp_error(
					$input['ability_name'],
					Error_Details::from_adapter_message( 'Native Ability permission callback returned false.' ),
					'native_ability',
					'authorization'
				);
			}
			return $result;
		};

		return $args;
	}

	/**
	 * Bounds provider-native results at the Adapter's final pre-protocol result hook.
	 *
	 * The hook is scoped to the Bridge-owned MCP server and execute-ability tool.
	 * Direct Ability execution and other Adapter servers remain unchanged.
	 *
	 * @param mixed  $result    Raw Adapter tool result.
	 * @param mixed  $args      Tool arguments.
	 * @param string $tool_name Protocol tool name.
	 * @param mixed  $mcp_tool  Adapter MCP tool object.
	 * @param mixed  $server    Adapter MCP server object.
	 * @return mixed
	 */
	public function filter_tool_call_result( $result, $args, $tool_name, $mcp_tool, $server ) {
		if (
			self::ADAPTER_EXECUTE_TOOL !== $tool_name ||
			! is_array( $args ) ||
			empty( $args['ability_name'] ) ||
			! is_string( $args['ability_name'] ) ||
			! is_object( $mcp_tool ) ||
			! is_object( $server ) ||
			! method_exists( $server, 'get_server_id' ) ||
			self::BRIDGE_MCP_SERVER_ID !== $server->get_server_id()
		) {
			return $result;
		}

		$target = function_exists( 'wp_get_ability' ) ? wp_get_ability( $args['ability_name'] ) : null;
		if ( $target && $this->is_bridge_owned_ability( $target ) ) {
			return $result;
		}

		$sanitized = $this->sanitize_native_execution_result( $result );
		if ( is_array( $sanitized ) && false === ( $sanitized['success'] ?? true ) &&
			$this->in_bridge_request() ) {
			if ( ! is_array( $this->pending_mcp_error ) ||
				$this->pending_mcp_error['ability'] !== $args['ability_name'] ) {
				if ( is_array( $result ) && false === ( $result['success'] ?? true ) &&
					is_string( $result['error'] ?? null ) ) {
					// Adapter v0.7.1 already discarded the original WP_Error
					// code/data. Keep its actual redacted message, mark code unknown.
					$this->capture_mcp_error(
						$args['ability_name'],
						Error_Details::from_adapter_message( $result['error'] ),
						'adapter_boundary',
						'adapter_execute'
					);
				} else {
					$this->capture_mcp_error(
						$args['ability_name'],
						new WP_Error( 'bridge_native_result_blocked', __( 'Bridge rejected an unsafe native Ability result shape.', 'wp-ai-bridge' ) ),
						'bridge_policy',
						'native_result'
					);
				}
			}
		}
		return $sanitized;
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
			if ( ! is_string( $result['error'] ?? null ) ) {
				return $this->native_result_blocked();
			}
			return array(
				'success' => false,
				'error'   => Error_Details::from_adapter_message( $result['error'] )['message'],
			);
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
		if ( $depth > 64 || is_resource( $value ) || ( is_object( $value ) && ! ( $value instanceof \stdClass ) ) ) {
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
	private function native_result_blocked() {
		return array(
			'success' => false,
			'error'   => __( 'Native Ability result was blocked because it may contain credential or security data.', 'wp-ai-bridge' ),
		);
	}

	/**
	 * Observe an actual WordPress 7.1+ callback WP_Error before Adapter v0.7.1
	 * loses its code and data. Earlier supported WordPress retains the honest
	 * message-only Adapter fallback. This hook never changes execution outcomes.
	 *
	 * @param mixed $result Callback result.
	 * @param string $ability_name Executed Ability.
	 * @param mixed $input Normalized input (never persisted).
	 * @param mixed $ability Exact WordPress Ability object.
	 * @return mixed
	 */
	public function observe_ability_execute_result( $result, $ability_name, $input, $ability ) {
		if ( ! $this->in_bridge_request() || ! is_wp_error( $result ) ||
			! is_string( $ability_name ) || ! is_object( $ability ) ||
			$this->is_bridge_owned_ability( $ability ) ) {
			return $result;
		}
		$this->capture_mcp_error( $ability_name, $result, 'native_ability', 'ability_execution' );
		return $result;
	}

	/**
	 * Preserve exact observed code/message/data, subject only to redaction of
	 * sensitive fragments. No code-to-category mapping or fabricated provider
	 * cause is permitted. A persisted reference belongs to this WordPress
	 * request only; Gateway correlation is independent until actually forwarded.
	 *
	 * @param string $ability Exact selected Ability.
	 * @param mixed $error WP_Error or observed Adapter message details.
	 * @param string $origin Proven observation boundary.
	 * @param string $stage Proven execution stage.
	 * @return void
	 */
	private function capture_mcp_error( $ability, $error, $origin, $stage ) {
		if ( ! $this->in_bridge_request() || ! function_exists( 'get_current_user_id' ) ||
			get_current_user_id() < 1 || ! is_string( $ability ) ) {
			return;
		}
		$client_id = OAuth_Server::authenticated_mcp_client_id();
		if ( '' === $client_id ) {
			return;
		}
		// Do not let a later Adapter summary overwrite a captured WordPress code.
		if ( is_array( $this->pending_mcp_error ) &&
			$this->pending_mcp_error['ability'] === $ability ) {
			return;
		}
		$details = is_wp_error( $error ) ? Error_Details::from_wp_error( $error ) : $error;
		if ( ! is_array( $details ) || ! is_string( $details['message'] ?? null ) ) {
			return;
		}
		$code = $details['code'] ?? null;
		$hint = 'inspect_provider_diagnostics';
		if ( 'authorization' === $stage ) {
			$hint = 'inspect_permissions';
		} elseif ( 'bridge_policy' === $origin ) {
			$hint = 'inspect_native_result';
		} elseif ( 'bridge_ability' === $origin && in_array( $code, array( 'invalid_media_payload', 'invalid_media_filename', 'media_too_large', 'transfer_invalid', 'site_health_invalid_input' ), true ) ) {
			$hint = 'inspect_request';
		} elseif ( 'bridge_ability' === $origin && in_array( $code, array( 'media_temp_failed', 'media_temp_write_failed', 'transfer_storage_unsafe', 'transfer_storage_unavailable', 'transfer_disk_unknown', 'transfer_disk_insufficient' ), true ) ) {
			$hint = 'inspect_private_storage';
		}
		if ( null === $this->diagnostic_log ) {
			$this->diagnostic_log = new Mutation_Log();
		}
		$ref                     = $this->diagnostic_log->record_mcp_failure( $ability, $details, $origin, $stage, $hint, $client_id );
		$this->pending_mcp_error = array(
			'ability'               => $ability,
			'code'                  => $code,
			'message'               => $details['message'],
			'data'                  => $details['data'] ?? null,
			'redacted'              => $details['redacted'] ?? false,
			'truncated'             => $details['truncated'] ?? false,
			'origin'                => $origin,
			'stage'                 => $stage,
			'next_step'             => $hint,
			'code_available'        => null !== $code,
			'diagnostics_persisted' => '' !== $ref,
			'outcome'               => 'unknown',
			'retry_safe'            => false,
		);
		if ( '' !== $ref ) {
			$this->pending_mcp_error['diagnostic_reference'] = $ref;
		}
	}


	/**
	 * Enrich ONLY an already rejected, exact Bridge MCP tools/call response.
	 * This Bridge-local compatibility layer is needed while Adapter #298 drops
	 * WP_Error code/data; it leaves permission, transport and other MCP replies
	 * unchanged. A provider code that was not observed remains unknown.
	 *
	 * @param mixed $response Adapter REST result.
	 * @param mixed $request  Current REST request.
	 * @return mixed
	 */
	private function project_mcp_error( $response, $request ) {
		$captured = $this->pending_mcp_error;
		if ( ! is_array( $captured ) || ! $request instanceof \WP_REST_Request ||
			! $response instanceof \WP_REST_Response || 'POST' !== $request->get_method() ||
			'/wp-ai-bridge/v1/mcp' !== $request->get_route() ) {
			return $response;
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || '2.0' !== ( $params['jsonrpc'] ?? null ) ||
			'tools/call' !== ( $params['method'] ?? null ) ||
			'mcp-adapter-execute-ability' !== ( $params['params']['name'] ?? null ) ||
			( $params['params']['arguments']['ability_name'] ??
				$params['params']['arguments']['input']['ability_name'] ?? null ) !== $captured['ability'] ||
			! isset( $params['id'] ) || ( ! is_int( $params['id'] ) && ! is_string( $params['id'] ) ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || '2.0' !== ( $data['jsonrpc'] ?? null ) ||
			( $data['id'] ?? null ) !== $params['id'] || ! is_array( $data['result'] ?? null ) ||
			true !== ( $data['result']['isError'] ?? null ) ||
			! is_array( $data['result']['content'] ?? null ) ) {
			return $response;
		}
		// The captured message is preserved with only sensitive fragments masked;
		// never reflect arbitrary raw Adapter text or an untrusted request payload.
		$data['result']['content']           = array(
			array(
				'type' => 'text',
				'text' => $captured['message'],
			),
		);
		$data['result']['structuredContent'] = array(
			'schema_version' => 1,
			'error'          => $captured,
		);
		$response->set_data( $data );
		return $response;
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
					if ( 0 === $this->bridge_request_depth ) {
						$this->pending_mcp_error = null;
					}
					++$this->bridge_request_depth;
					try {
						$response = call_user_func( $callback, $request );
						return 1 === $this->bridge_request_depth ?
							$this->project_mcp_error( $response, $request ) : $response;
					} finally {
						$this->bridge_request_depth = max( 0, $this->bridge_request_depth - 1 );
						if ( 0 === $this->bridge_request_depth ) {
							$this->pending_mcp_error = null;
						}
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
