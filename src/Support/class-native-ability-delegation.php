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

	/** @var string|null Exact WordPress Ability named by the current Bridge tools/call. */
	private $current_mcp_ability = null;

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
		// WordPress 7.1+ exposes each lifecycle boundary; these filters only
		// observe results and return the unmodified input/result.
		add_filter( 'wp_pre_execute_ability', array( $this, 'observe_ability_pre_execute' ), PHP_INT_MAX, 4 );
		add_filter( 'wp_ability_normalize_input', array( $this, 'observe_ability_normalize_input' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_ability_validate_input', array( $this, 'observe_ability_validate_input' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_ability_permission_result', array( $this, 'observe_ability_permission_result' ), PHP_INT_MAX, 4 );
		add_filter( 'wp_ability_execute_result', array( $this, 'observe_ability_execute_result' ), PHP_INT_MAX, 4 );
		add_filter( 'wp_ability_validate_output', array( $this, 'observe_ability_validate_output' ), PHP_INT_MAX, 3 );
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
				$this->capture_mcp_error( $input['ability_name'], $denied, 'bridge_policy', 'authorization', 'bridge_generated' );
				return $denied;
			}

			try {
				$result = call_user_func( $original_permission, $input );
			} catch ( \Throwable $throwable ) {
				// Throwable is not a WP_Error and has no original WP error code.
				$details = Error_Details::from_adapter_message( $throwable->getMessage() );
				$safe    = new WP_Error(
					'wp_ai_bridge_adapter_permission_exception',
					$details['message'],
					array(
						'exception_class' => get_class( $throwable ),
						'exception_code'  => $throwable->getCode(),
					)
				);
				// Only the Adapter wrapper boundary is proven; a Throwable is not
				// an original provider WP_Error. Mark the Bridge-generated code.
				$this->capture_mcp_error( $input['ability_name'], $safe, 'adapter_boundary', 'authorization', 'bridge_generated' );
				return $safe;
			}

			if ( is_wp_error( $result ) ) {
				// Preserve the provider's error identifier and non-sensitive
				// information instead of replacing it with a generic denial.
				$details = Error_Details::from_wp_error( $result );
				$safe    = new WP_Error( $details['code'] ?? '', $details['message'], $details['data'] );
				$this->capture_mcp_error( $input['ability_name'], $safe, 'adapter_boundary', 'authorization', 'observed_at_adapter' );
				return $safe;
			}

			if ( false === $result ) {
				$this->capture_mcp_error(
					$input['ability_name'],
					Error_Details::from_adapter_message( 'Native Ability permission callback returned false.' ),
					'adapter_boundary',
					'authorization',
					'unavailable'
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

		$target       = function_exists( 'wp_get_ability' ) ? wp_get_ability( $args['ability_name'] ) : null;
		$bridge_owned = $target && $this->is_bridge_owned_ability( $target );
		// Success responses retain their purpose-specific Bridge contracts;
		// Bridge-owned failures are subject to the same secret masking as
		// native provider failures, including errors originating in Core.
		if ( $bridge_owned && ( ! is_array( $result ) || false !== ( $result['success'] ?? null ) ) ) {
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
						'adapter_execute',
						'unavailable'
					);
				} else {
					$this->capture_mcp_error(
						$args['ability_name'],
						new WP_Error( 'bridge_native_result_blocked', __( 'Bridge rejected an unsafe native Ability result shape.', 'wp-ai-bridge' ) ),
						'bridge_policy',
						'native_result',
						'bridge_generated'
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
	 * Observe Core 7.1+ lifecycle failures without changing what WordPress
	 * ultimately returns. A hook that only names an Ability is verified
	 * against the registry and the current authorized MCP tool target.
	 * Provider callbacks, Core validation and Adapter checks remain distinct.
	 *
	 * @param mixed $value Actual hook value, returned unmodified.
	 * @param string $ability_name Core Ability name.
	 * @param object|null $ability Core Ability instance if supplied.
	 * @param string $stage Proven Core phase.
	 * @return mixed
	 */
	private function observe_core_boundary( $value, $ability_name, $ability, $stage ) {
		if ( ! $this->in_bridge_request() || ! is_string( $ability_name ) ||
			$ability_name !== $this->current_mcp_ability ||
			self::ADAPTER_EXECUTE_ABILITY === $ability_name || ! function_exists( 'wp_get_ability' ) ) {
			return $value;
		}
		$registered = wp_get_ability( $ability_name );
		if ( ! is_object( $registered ) ||
			( null !== $ability && $registered !== $ability ) ) {
			return $value;
		}
		// Core's normalization, validation and permission filters establish a
		// Core lifecycle observation, not proof of a provider callback cause.
		$origin = 'ability_execution' === $stage ?
			( $this->is_bridge_owned_ability( $registered ) ? 'bridge_ability' : 'native_ability' ) :
			'wordpress_core';
		if ( is_wp_error( $value ) ) {
			$this->capture_mcp_error( $ability_name, $value, $origin, $stage, 'observed_wordpress' );
		} elseif ( 'authorization' === $stage && false === $value ) {
			$this->capture_mcp_error(
				$ability_name,
				Error_Details::from_adapter_message( 'WordPress Ability permission check returned false.' ),
				$origin,
				$stage,
				'unavailable'
			);
		}
		return $value;
	}

	/** @return mixed Unmodified short-circuit sentinel/value. */
	public function observe_ability_pre_execute( $value, $name, $input, $ability ) {
		return $this->observe_core_boundary( $value, $name, $ability, 'pre_execution' );
	}

	/** @return mixed Unmodified normalized input. */
	public function observe_ability_normalize_input( $value, $name, $ability ) {
		return $this->observe_core_boundary( $value, $name, $ability, 'normalization' );
	}

	/** @return mixed Unmodified input validation result. */
	public function observe_ability_validate_input( $value, $input, $name ) {
		return $this->observe_core_boundary( $value, $name, null, 'input_validation' );
	}

	/** @return mixed Unmodified provider permission result. */
	public function observe_ability_permission_result( $value, $name, $input, $ability ) {
		return $this->observe_core_boundary( $value, $name, $ability, 'authorization' );
	}

	/** @return mixed Unmodified callback result. */
	public function observe_ability_execute_result( $value, $name, $input, $ability ) {
		return $this->observe_core_boundary( $value, $name, $ability, 'ability_execution' );
	}

	/** @return mixed Unmodified output validation result. */
	public function observe_ability_validate_output( $value, $output, $name ) {
		return $this->observe_core_boundary( $value, $name, null, 'output_validation' );
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
	 * @param string $code_source Proven provenance of the error code.
	 * @return void
	 */
	private function capture_mcp_error( $ability, $error, $origin, $stage, $code_source = 'observed_wordpress' ) {
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
		$code        = $details['code'] ?? null;
		$code_source = null === $code ? 'unavailable' : $code_source;
		$hint        = 'inspect_provider_diagnostics';
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
		$ref                     = $this->diagnostic_log->record_mcp_failure( $ability, $details, $origin, $stage, $hint, $client_id, $code_source );
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
			'code_source'           => $code_source,
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
		if ( ! $request instanceof \WP_REST_Request ||
			! $response instanceof \WP_REST_Response || 'POST' !== $request->get_method() ||
			'/wp-ai-bridge/v1/mcp' !== $request->get_route() ) {
			return $response;
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || '2.0' !== ( $params['jsonrpc'] ?? null ) ||
			'tools/call' !== ( $params['method'] ?? null ) ||
			'mcp-adapter-execute-ability' !== ( $params['params']['name'] ?? null ) ||
			! is_string( $this->current_mcp_ability ) ||
			( $params['params']['arguments']['ability_name'] ??
				$params['params']['arguments']['input']['ability_name'] ?? null ) !== $this->current_mcp_ability ||
			! isset( $params['id'] ) || ( ! is_int( $params['id'] ) && ! is_string( $params['id'] ) ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || '2.0' !== ( $data['jsonrpc'] ?? null ) ||
			( $data['id'] ?? null ) !== $params['id'] ) {
			return $response;
		}

		// The official Adapter hydrates JSON-RPC records. During an actual
		// REST call the nested CallToolResult can be a stdClass or a protocol
		// JsonSerializable Record, whereas isolated callback fixtures commonly
		// return plain PHP arrays. Inspect only this already-rejected result.
		$result = $data['result'] ?? null;
		if ( $result instanceof \JsonSerializable ) {
			$result = $result->jsonSerialize();
		}
		if ( $result instanceof \stdClass ) {
			$result = (array) $result;
		}
		if ( ! is_array( $result ) || true !== ( $result['isError'] ?? null ) ) {
			return $response;
		}
		$content = $result['content'] ?? null;
		if ( $content instanceof \JsonSerializable ) {
			$content = $content->jsonSerialize();
		}
		if ( $content instanceof \stdClass ) {
			$content = (array) $content;
		}
		if ( ! is_array( $content ) ) {
			return $response;
		}
		$first = $content[0] ?? null;
		if ( $first instanceof \JsonSerializable ) {
			$first = $first->jsonSerialize();
		}
		if ( $first instanceof \stdClass ) {
			$first = (array) $first;
		}
		$original_text = is_array( $first ) && is_string( $first['text'] ?? null ) ? $first['text'] : '';
		$captured      = $this->pending_mcp_error;
		if ( ! is_array( $captured ) || $captured['ability'] !== $this->current_mcp_ability ) {
			// WordPress 6.9 and early Adapter permission failures may expose no
			// original WP_Error. Do not invent provider codes or stages.
			$details = Error_Details::from_adapter_message(
				'' !== $original_text ? $original_text : 'MCP Adapter returned a tool error without text.'
			);
			$this->capture_mcp_error( $this->current_mcp_ability, $details, 'adapter_boundary', 'adapter_execute', 'unavailable' );
			$captured = $this->pending_mcp_error;
			if ( ! is_array( $captured ) ) {
				$captured = array(
					'ability'               => $this->current_mcp_ability,
					'code'                  => null,
					'message'               => $details['message'],
					'data'                  => null,
					'redacted'              => $details['redacted'],
					'truncated'             => $details['truncated'],
					'origin'                => 'adapter_boundary',
					'stage'                 => 'adapter_execute',
					'next_step'             => 'inspect_provider_diagnostics',
					'code_available'        => false,
					'code_source'           => 'unavailable',
					'diagnostics_persisted' => false,
					'outcome'               => 'unknown',
					'retry_safe'            => false,
				);
			}
		}
		// Show observed, selectively redacted error context while preserving
		// the Adapter's original isError outcome and the JSON-RPC request id.
		$result['content']           = array(
			array(
				'type' => 'text',
				'text' => $captured['message'],
			),
		);
		$result['structuredContent'] = array(
			'schema_version' => 1,
			'error'          => $captured,
		);
		$data['result']              = $result;
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
						$this->pending_mcp_error   = null;
						$this->current_mcp_ability = null;
						if ( $request instanceof \WP_REST_Request &&
							'POST' === $request->get_method() ) {
							$params = $request->get_json_params();
							$name   = $params['params']['arguments']['ability_name'] ??
								$params['params']['arguments']['input']['ability_name'] ?? null;
							if ( is_array( $params ) && 'tools/call' === ( $params['method'] ?? null ) &&
								'mcp-adapter-execute-ability' === ( $params['params']['name'] ?? null ) &&
								is_string( $name ) && 1 === preg_match( '/^[a-z0-9_-]+\/[a-z0-9_-]+$/D', $name ) ) {
								$this->current_mcp_ability = $name;
							}
						}
					}
					++$this->bridge_request_depth;
					try {
						$response = call_user_func( $callback, $request );
						return 1 === $this->bridge_request_depth ?
							$this->project_mcp_error( $response, $request ) : $response;
					} finally {
						$this->bridge_request_depth = max( 0, $this->bridge_request_depth - 1 );
						if ( 0 === $this->bridge_request_depth ) {
							$this->pending_mcp_error   = null;
							$this->current_mcp_ability = null;
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
