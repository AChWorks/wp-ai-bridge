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
			return $this->error( 'rest_invocation_denied', __( 'Registered REST invocation requires a separate enabled Bridge grant and WordPress administration permission.', 'wp-ai-bridge' ) );
		}
		if ( $this->in_flight ) {
			return $this->error( 'rest_invocation_recursive', __( 'Nested generic REST invocation is not allowed.', 'wp-ai-bridge' ) );
		}
		// Provider route-index filters are executable hooks too: guard the
		// entire preflight and dispatch, not only the final Core callback.
		$this->in_flight = true;
		try {
			return $this->invoke_registered( $input );
		} catch ( \Throwable $throwable ) {
			// Provider filters and Core registration hooks execute during preflight.
			// Neither their throwable messages nor any assumed rollback are safe.
			return $this->unavailable_result();
		} finally {
			$this->in_flight = false;
		}
	}

	/** @param mixed $input Bound native route execution input. @return array<string,mixed>|WP_Error */
	private function invoke_registered( $input ) {
		$principal_id = get_current_user_id();
		$site_id      = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
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

		if ( array_intersect_key( $query, $body ) ) {
			return $this->invalid();
		}
		if ( ! $this->safe_tree( $query ) || ! $this->safe_tree( $body ) ) {
			return $this->invalid();
		}
		$input_json = wp_json_encode(
			array(
				'query' => $query,
				'body'  => $body,
			)
		);
		if ( ! is_string( $input_json ) || strlen( $input_json ) > self::MAX_INPUT_BYTES ) {
			return $this->invalid();
		}
		if ( ! function_exists( 'rest_get_server' ) || ! function_exists( 'rest_do_request' ) || ! class_exists( 'WP_REST_Request' ) ) {
			return $this->error( 'rest_invocation_unavailable', __( 'The native WordPress REST dispatcher is unavailable.', 'wp-ai-bridge' ) );
		}

		$server = rest_get_server();
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) || ! method_exists( $server, 'get_data_for_routes' ) ) {
			return $this->error( 'rest_invocation_unavailable', __( 'The native WordPress REST dispatcher is unavailable.', 'wp-ai-bridge' ) );
		}
		$routes = $server->get_routes();
		if ( ! is_array( $routes ) || count( $routes ) > self::MAX_REGISTRY ) {
			return $this->error( 'rest_invocation_registry_unavailable', __( 'The local REST route registry cannot be safely inspected.', 'wp-ai-bridge' ) );
		}
		if ( ! isset( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return $this->not_found();
		}

		// Real WordPress always accepts a concrete request path. The selected
		// regex is used only as an independent registry identity assertion.
		$path_matches   = array();
		$selected_match = preg_match( '@^' . $route . '$@i', $path, $path_matches );
		if ( 1 !== $selected_match ) {
			return $this->invalid();
		}
		// Route captures are authoritative. A query/body parameter with the
		// same name could override the Core-decoded URL parameter on some
		// WordPress request paths, changing the resource being authorized.
		foreach ( $path_matches as $name => $value ) {
			if ( is_string( $name ) && ( array_key_exists( $name, $query ) || array_key_exists( $name, $body ) ) ) {
				return $this->invalid();
			}
		}

		// Core dispatch selects the *first* handler matching the method,
		// independent of show_in_index. For a same-route/same-method pair,
		// the filtered public index has no stable identity link to Core's
		// chosen handler. Fail closed for ANY duplicate method registration,
		// including a hidden handler registered before a public handler.
		$selected_handler = null;
		foreach ( $routes[ $route ] as $handler ) {
			if (
				! is_array( $handler ) || ! isset( $handler['methods'] ) ||
				! is_array( $handler['methods'] ) || empty( $handler['methods'][ $method ] )
			) {
				continue;
			}
			if ( null !== $selected_handler ) {
				return $this->not_found();
			}
			$selected_handler = $handler;
		}
		if ( null === $selected_handler || empty( $selected_handler['show_in_index'] ) ) {
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

		// Bind public arguments to the ONE method handler Core can
		// select. Aggregate/public endpoint data is never sufficient when
		// two registered handlers can accept the same request method.
		$visible_args = array();
		$endpoints    = isset( $indexed[ $route ]['endpoints'] ) ? $indexed[ $route ]['endpoints'] : null;
		$native_args  = isset( $selected_handler['args'] ) ? $selected_handler['args'] : array();
		if ( ! is_array( $endpoints ) || ! is_array( $native_args ) ) {
			return $this->not_found();
		}
		$endpoint_count = 0;
		foreach ( $endpoints as $endpoint ) {
			if (
				! is_array( $endpoint ) || ! isset( $endpoint['methods'] ) ||
				! is_array( $endpoint['methods'] ) || ! in_array( $method, $endpoint['methods'], true )
			) {
				continue;
			}
			++$endpoint_count;
			if ( 1 !== $endpoint_count ) {
				return $this->not_found();
			}
			$arguments = isset( $endpoint['args'] ) ? $endpoint['args'] : array();
			if ( ! is_array( $arguments ) ) {
				return $this->not_found();
			}
			foreach ( array_keys( $arguments ) as $name ) {
				// A public-index filter may remove arguments, not create a new
				// executable parameter outside Core's selected native schema.
				if ( ! is_string( $name ) || ! array_key_exists( $name, $native_args ) ) {
					return $this->not_found();
				}
				$visible_args[ $name ] = true;
			}
		}
		if ( 1 !== $endpoint_count ) {
			return $this->not_found();
		}
		foreach ( array_keys( $query + $body ) as $name ) {
			if ( ! is_string( $name ) || ! isset( $visible_args[ $name ] ) ) {
				return $this->invalid();
			}
		}

		// WordPress may register overlapping regexes. Never claim to invoke the
		// selected contract while Core would resolve a different route instead.
		// Fail closed rather than bypassing Core's native matching order.
		foreach ( $routes as $candidate => $handlers ) {
			if ( $candidate === $route || ! is_string( $candidate ) ) {
				continue;
			}
			if ( 1 === preg_match( '@^' . $candidate . '$@i', $path ) ) {
				return $this->error( 'rest_invocation_ambiguous_route', __( 'Multiple registered REST routes match the requested path.', 'wp-ai-bridge' ) );
			}
		}

		// Core/provider callbacks do not declare trustworthy effect classes.
		// Even a GET may perform credential, source or package operations with
		// innocuous route/parameter names. For an unclassified generic route,
		// require EACH independent purpose-specific Bridge consent and its
		// native mapped capability. An administrator who did not explicitly
		// opt into all protected lifecycles cannot use REST to bypass any one
		// of them. This is a conservative, revocable super-trust policy, not
		// a claim that caller-supplied method/name proves harmlessness.
		if ( ! $this->protected_lifecycle_consent() ) {
			return $this->error( 'rest_invocation_protected_consent_required', __( 'Generic REST execution needs independently enabled protected lifecycle permissions.', 'wp-ai-bridge' ) );
		}
		// Recheck all grants after provider index filters and before dispatch.
		// No WordPress principal, blog context or OAuth identity is changed.
		if (
			! $this->can_invoke() ||
			! $this->protected_lifecycle_consent() ||
			get_current_user_id() !== $principal_id ||
			( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ) !== $site_id
		) {
			return $this->error( 'rest_invocation_denied', __( 'Registered REST invocation requires a separate enabled Bridge grant and WordPress administration permission.', 'wp-ai-bridge' ) );
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
		// A provider may attach side effects even to GET. All post-dispatch
		// failures and truncation are potentially ambiguous, and no method is
		// treated as safe for automatic replay.
		try {
			$response = rest_do_request( $request );
			if ( is_wp_error( $response ) || ! is_object( $response ) || ! method_exists( $response, 'get_status' ) || ! method_exists( $response, 'get_data' ) ) {
				return $this->unavailable_result();
			}
			$status = $response->get_status();
			if ( ! is_int( $status ) || $status < 100 || $status > 599 ) {
				return $this->unavailable_result();
			}
			if ( $status < 200 || $status >= 300 ) {
				return array(
					'status'  => $status,
					'outcome' => 'outcome_unknown',
					'error'   => __( 'WordPress did not report a successful REST operation. Review current state before retrying a mutation.', 'wp-ai-bridge' ),
				);
			}

			$data = $response->get_data();
			if ( ! $this->safe_tree( $data ) ) {
				return $this->unavailable_result();
			}
			$result = array(
				'status'  => $status,
				'outcome' => 'reported_success',
				'data'    => $data,
			);
			if ( ! Bounded_Payload::fits( $result ) ) {
				return $this->unavailable_result();
			}
			return $result;
		} catch ( \Throwable $throwable ) {
			// Callback errors can follow a committed mutation. Suppress native
			// exception messages and never imply that retrying is safe.
			return $this->unavailable_result();
		}
	}

	/**
	 * Unclassified provider/Core REST effects can perform ANY protected
	 * lifecycle. Requiring every corresponding explicit Bridge grant and
	 * native capability preserves the same policy even for innocuous
	 * provider names and opaque data keys. No caller-provided effect claim
	 * can weaken this conservative authorization. A future server-owned
	 * auditable effect registry may narrow these requirements.
	 *
	 * @return bool
	 */
	private function protected_lifecycle_consent() {
		$requirements = array(
			array( Settings::GROUP_BUILDER_WRITE, 'edit_posts' ),
			array( Settings::GROUP_REMOTE_MEDIA, 'upload_files' ),
			array( Settings::GROUP_LIVE_CONTENT, 'publish_posts' ),
			array( Settings::GROUP_SITE_CONFIG, 'manage_options' ),
			array( Settings::GROUP_ADVANCED_METADATA, 'manage_options' ),
			array( Settings::GROUP_AUTHENTICATION, 'manage_options' ),
			array( Settings::GROUP_CODE_EXTENSIONS, 'activate_plugins' ),
			array( Settings::GROUP_EXTERNAL_PACKAGES, 'install_plugins' ),
			array( Settings::GROUP_SOURCE_EDITING, 'edit_plugins' ),
			array( Settings::GROUP_SOURCE_EDITING, 'edit_themes' ),
			array( Settings::GROUP_COMMENTS, 'moderate_comments' ),
			array( Settings::GROUP_USERS_DESTRUCTIVE, 'delete_users' ),
		);
		foreach ( $requirements as $requirement ) {
			if ( ! $this->permissions->allowed( $requirement[0], $requirement[1] ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param string $path Local REST route/path. @return bool */
	private function blocked_path( $path ) {
		// WordPress Core /batch/v1 and provider batch handlers may internally
		// fan out into routes whose separate Bridge trust grants are denied.
		if ( 1 === preg_match( '#(?:^|/)batch(?:/|$)#i', $path ) ) {
			return true;
		}
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

	/** @return array<string,mixed> Ambiguous outcome; no method is safe to retry blindly. */
	private function unavailable_result() {
		return array(
			'status'  => 0,
			'outcome' => 'outcome_unknown',
			'error'   => __( 'The REST operation outcome cannot be confirmed. Inspect provider state before retrying.', 'wp-ai-bridge' ),
		);
	}

	/** @return WP_Error */
	private function invalid() {
		return $this->error( 'rest_invocation_invalid', __( 'Specify one exact registered route, a matching local path, an allowed method and bounded JSON parameters.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function not_found() {
		return $this->error( 'rest_invocation_route_not_found', __( 'The requested public registered REST route and method are not available.', 'wp-ai-bridge' ) );
	}

	/** @param string $code Error code. @param string $message Safe message. @return WP_Error */
	private function error( $code, $message ) {
		return new WP_Error( $code, $message );
	}

	/** @return array<string,mixed> */
	private function input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'route'  => array(
					'type'      => 'string',
					'minLength' => 2,
					'maxLength' => self::MAX_ROUTE_BYTES,
				),
				'path'   => array(
					'type'      => 'string',
					'minLength' => 2,
					'maxLength' => self::MAX_ROUTE_BYTES,
				),
				'method' => array(
					'type' => 'string',
					'enum' => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
				),
				'query'  => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'body'   => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
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
					'enum' => array( 'reported_success', 'outcome_unknown' ),
				),
				'data'    => array( 'type' => array( 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' ) ),
				'error'   => array( 'type' => 'string' ),
			),
			'required'             => array( 'status', 'outcome' ),
			'additionalProperties' => false,
		);
	}
}
