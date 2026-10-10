<?php
/**
 * High-trust, bounded dispatch to locally registered WordPress REST endpoints.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Metadata_Key_Policy;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * This is not an HTTP proxy. Core owns dispatch, validation and provider permissions.
 *
 * Every operation shares the conservative static destructive classification because
 * MCP/Gateway authorization is evaluated per Ability, not per supplied REST method.
 */
final class Registered_REST_Invocation_Abilities {
	const MAX_ROUTE_BYTES = 512;
	const MAX_INPUT_BYTES = 16384;
	const MAX_REGISTRY    = 4096;
	const MAX_NODES       = 1024;
	const MAX_DEPTH       = 16;

	/** @var Permissions */
	private $permissions;

	/** @var bool Reentrant invocation must never dispatch another generic call. */
	private $in_flight = false;

	/** @param Permissions $permissions WordPress principal + Bridge group policy. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/** @return array<int,object> Actual registered native Ability objects. */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/rest-route-invoke',
			array(
				'label'               => __( 'Invoke Registered REST Route', 'wp-ai-bridge' ),
				'description'         => __( 'Invokes one bounded, public-index-visible local WordPress REST route using native permissions. Requires separate high-trust grant. Can mutate data and is not idempotent.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'permission_callback' => array( $this, 'can_invoke' ),
				'execute_callback'    => array( $this, 'invoke' ),
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool The independent group never inherits Site Read, discovery or Native Abilities. */
	public function can_invoke() {
		return $this->permissions->allowed( Settings::GROUP_REST_INVOCATION, 'manage_options' );
	}

	/**
	 * Invoke exactly one eligible local path. Caller supplies no host, headers,
	 * cookies, credentials, file streams, request body bytes, or override method.
	 *
	 * @param mixed $input Closed, typed Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function invoke( $input ) {
		if ( ! $this->can_invoke() ) {
			return $this->error( 'rest_invocation_denied', 'Registered REST invocation requires a separate enabled Bridge grant and WordPress administration permission.' );
		}
		if ( $this->in_flight ) {
			return $this->error( 'rest_invocation_recursive', 'Nested generic REST invocation is not allowed.' );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'route', 'path', 'method', 'query', 'body' ) ) ) {
			return $this->invalid();
		}
		$route  = isset( $input['route'] ) ? $input['route'] : null;
		$path   = isset( $input['path'] ) ? $input['path'] : null;
		$method = isset( $input['method'] ) ? $input['method'] : null;
		$query  = isset( $input['query'] ) ? $input['query'] : array();
		$body   = isset( $input['body'] ) ? $input['body'] : array();

		if (
			! is_string( $route ) || strlen( $route ) < 2 || strlen( $route ) > self::MAX_ROUTE_BYTES ||
			! is_string( $path ) || strlen( $path ) < 2 || strlen( $path ) > self::MAX_ROUTE_BYTES ||
			! is_string( $method ) || ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ||
			! is_array( $query ) || ! is_array( $body ) ||
			( 'GET' === $method && array_key_exists( 'body', $input ) && $body ) ||
			! preg_match( '#^/[A-Za-z0-9._~/\x2D]+$#D', $path ) ||
			$this->contains_path_traversal( $path ) ||
			$this->blocked_path( $path ) ||
			$this->blocked_path( $route )
		) {
			return $this->invalid();
		}

		// Real WordPress always accepts a concrete request path. The selected
		// regex is used only as an independent registry identity assertion.
		$selected_match = @preg_match( '@^' . $route . '$@i', $path );
		if ( 1 !== $selected_match ) {
			return $this->invalid();
		}
		if ( ! $this->safe_tree( $query ) || ! $this->safe_tree( $body ) ) {
			return $this->invalid();
		}
		$input_json = wp_json_encode( array( 'query' => $query, 'body' => $body ) );
		if ( ! is_string( $input_json ) || strlen( $input_json ) > self::MAX_INPUT_BYTES ) {
			return $this->invalid();
		}
		if ( ! function_exists( 'rest_get_server' ) || ! function_exists( 'rest_do_request' ) || ! class_exists( 'WP_REST_Request' ) ) {
			return $this->error( 'rest_invocation_unavailable', 'The native WordPress REST dispatcher is unavailable.' );
		}

		$server = rest_get_server();
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) || ! method_exists( $server, 'get_data_for_routes' ) ) {
			return $this->error( 'rest_invocation_unavailable', 'The native WordPress REST dispatcher is unavailable.' );
		}
		$routes = $server->get_routes();
		if ( ! is_array( $routes ) || count( $routes ) > self::MAX_REGISTRY ) {
			return $this->error( 'rest_invocation_registry_unavailable', 'The local REST route registry cannot be safely inspected.' );
		}
		if ( ! isset( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return $this->not_found();
		}

		// A deliberately non-public REST handler is not a generic entry point.
		// Honor the final filtered Core index including provider redactions.
		$public_handler = false;
		$native_method  = false;
		foreach ( $routes[ $route ] as $handler ) {
			if ( is_array( $handler ) && ! empty( $handler['show_in_index'] ) ) {
				$public_handler = true;
				if ( isset( $handler['methods'] ) && is_array( $handler['methods'] ) && ! empty( $handler['methods'][ $method ] ) ) {
					$native_method = true;
				}
			}
		}
		if ( ! $public_handler || ! $native_method ) {
			return $this->not_found();
		}
		$indexed = $server->get_data_for_routes( array( $route => $routes[ $route ] ), 'view' );
		if (
			! is_array( $indexed ) || ! isset( $indexed[ $route ] ) ||
			! is_array( $indexed[ $route ] ) || ! isset( $indexed[ $route ]['methods'] ) ||
			! is_array( $indexed[ $route ]['methods'] ) ||
			! in_array( $method, $indexed[ $route ]['methods'], true )
		) {
			return $this->not_found();
		}

		// WordPress may register overlapping regexes. Never claim to invoke the
		// selected contract while Core would resolve a different route instead.
		// Fail closed rather than bypassing Core's native matching order.
		foreach ( $routes as $candidate => $handlers ) {
			if ( $candidate === $route || ! is_string( $candidate ) ) {
				continue;
			}
			if ( 1 === @preg_match( '@^' . $candidate . '$@i', $path ) ) {
				return $this->error( 'rest_invocation_ambiguous_route', 'Multiple registered REST routes match the requested path.' );
			}
		}

		// Recheck grant immediately before dispatch. No WordPress principal, site
		// context or OAuth client identity is changed inside this Ability.
		if ( ! $this->can_invoke() ) {
			return $this->error( 'rest_invocation_denied', 'Registered REST invocation requires a separate enabled Bridge grant and WordPress administration permission.' );
		}

		$request = new \WP_REST_Request( $method, $path );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		if ( $body ) {
			$body_json = wp_json_encode( $body );
			if ( ! is_string( $body_json ) || strlen( $body_json ) > self::MAX_INPUT_BYTES ) {
				return $this->invalid();
			}
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $body_json );
		}
		$mutating        = 'GET' !== $method;
		$this->in_flight = true;
		try {
			$response = rest_do_request( $request );
			if ( is_wp_error( $response ) || ! is_object( $response ) || ! method_exists( $response, 'get_status' ) || ! method_exists( $response, 'get_data' ) ) {
				return $this->unavailable_result( $mutating );
			}
			$status = $response->get_status();
			if ( ! is_int( $status ) || $status < 100 || $status > 599 ) {
				return $this->unavailable_result( $mutating );
			}
			if ( $status < 200 || $status >= 300 ) {
				return array(
					'status'  => $status,
					'outcome' => $mutating ? 'outcome_unknown' : 'failed',
					'error'   => __( 'WordPress did not report a successful REST operation. Review current state before retrying a mutation.', 'wp-ai-bridge' ),
				);
			}

			$data = $response->get_data();
			if ( ! $this->safe_tree( $data ) ) {
				return $this->unavailable_result( $mutating );
			}
			$result = array(
				'status'  => $status,
				'outcome' => $mutating ? 'reported_success' : 'succeeded',
				'data'    => $data,
			);
			if ( ! Bounded_Payload::fits( $result ) ) {
				return $this->unavailable_result( $mutating );
			}
			return $result;
		} catch ( \Throwable $throwable ) {
			// Callback errors can follow a committed mutation. Suppress native
			// exception messages and never imply that retrying is safe.
			return $this->unavailable_result( $mutating );
		} finally {
			$this->in_flight = false;
		}
	}

	/** @param string $path Local REST route/path. @return bool */
	private function blocked_path( $path ) {
		if ( 1 === preg_match( '#^/(?:wp-ai-bridge|mcp-adapter|mcp|wp-abilities)(?:/|$)#i', $path ) ) {
			return true;
		}
		if ( 1 === preg_match( '#(?:^|/)(?:oauth|auth|authorization|login|logout|credentials?|secrets?|sessions?|tokens?|api-keys?|application-passwords|webhooks?)(?:/|$)#i', $path ) ) {
			return true;
		}
		// Purpose-specific Bridge controls may not be bypassed by their
		// ordinary Core REST equivalents.
		return 1 === preg_match( '#^/wp/v2/(?:settings|plugins|themes|users)(?:/|$)#i', $path );
	}

	/** @param string $path Concrete local path. @return bool */
	private function contains_path_traversal( $path ) {
		foreach ( explode( '/', ltrim( $path, '/' ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate all keys/values before accepting request params or returning
	 * provider results. No custom serializers, resources or secret-bearing keys.
	 *
	 * @param mixed $value JSON-shaped value.
	 * @param int   $depth Current depth.
	 * @param int   $budget Remaining inspected node/byte budget.
	 * @return bool
	 */
	private function safe_tree( $value, $depth = 0, &$budget = null ) {
		if ( null === $budget ) {
			$budget = self::MAX_INPUT_BYTES;
		}
		if ( $depth > self::MAX_DEPTH || is_resource( $value ) || ( is_object( $value ) && ! ( $value instanceof \stdClass ) ) ) {
			return false;
		}
		if ( is_array( $value ) || $value instanceof \stdClass ) {
			--$budget;
			if ( $budget < 0 ) {
				return false;
			}
			if ( count( (array) $value ) > self::MAX_NODES ) {
				return false;
			}
			foreach ( (array) $value as $key => $child ) {
				if ( is_string( $key ) ) {
					$budget -= strlen( $key );
					if ( Metadata_Key_Policy::is_sensitive( $key ) || $this->sensitive_key( $key ) ) {
						return false;
					}
				} else {
					--$budget;
				}
				if ( $budget < 0 || ! $this->safe_tree( $child, $depth + 1, $budget ) ) {
					return false;
				}
			}
			return true;
		}
		if ( ! is_null( $value ) && ! is_scalar( $value ) ) {
			return false;
		}
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return false;
		}
		$budget -= is_string( $value ) ? strlen( $value ) : 1;
		return $budget >= 0;
	}

	/** @param string $key Provider field name. @return bool */
	private function sensitive_key( $key ) {
		$normalized = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $key ) );
		return 1 === preg_match( '/(?:^|_)(?:nonce|authorization|cookies?|oauth|tokens?|secrets?|credentials?|passwords?|private_keys?|sessions?)(?:_|$)/', $normalized );
	}

	/** @param bool $mutating Was this a potentially mutating native method. @return array<string,mixed>|WP_Error */
	private function unavailable_result( $mutating ) {
		if ( $mutating ) {
			return array(
				'status'  => 0,
				'outcome' => 'outcome_unknown',
				'error'   => __( 'The REST operation outcome cannot be confirmed. Inspect provider state before retrying.', 'wp-ai-bridge' ),
			);
		}
		return $this->error( 'rest_invocation_result_unavailable', 'The REST response cannot be returned safely within the data limit.' );
	}

	/** @return WP_Error */
	private function invalid() {
		return $this->error( 'rest_invocation_invalid', 'Specify one exact registered route, a matching local path, an allowed method and bounded JSON parameters.' );
	}

	/** @return WP_Error */
	private function not_found() {
		return $this->error( 'rest_invocation_route_not_found', 'The requested public registered REST route and method are not available.' );
	}

	/** @param string $code Error code. @param string $message Safe message. @return WP_Error */
	private function error( $code, $message ) {
		return new WP_Error( $code, __( $message, 'wp-ai-bridge' ) );
	}

	/** @return array<string,mixed> */
	private function input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'route'  => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => self::MAX_ROUTE_BYTES ),
				'path'   => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => self::MAX_ROUTE_BYTES ),
				'method' => array( 'type' => 'string', 'enum' => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) ),
				'query'  => array( 'type' => 'object', 'additionalProperties' => true ),
				'body'   => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'required'             => array( 'route', 'path', 'method' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status'  => array( 'type' => 'integer' ),
				'outcome' => array(
					'type' => 'string',
					'enum' => array( 'succeeded', 'reported_success', 'failed', 'outcome_unknown' ),
				),
				'data'    => array( 'type' => array( 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' ) ),
				'error'   => array( 'type' => 'string' ),
			),
			'required'             => array( 'status', 'outcome' ),
			'additionalProperties' => false,
		);
	}
}
