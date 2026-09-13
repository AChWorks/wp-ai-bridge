<?php
/**
 * Direct ChatGPT OAuth and MCP transport integration.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Auth;

/**
 * Provides a WordPress-native OAuth 2.1 compatibility layer for direct ChatGPT MCP Apps.
 */
final class OAuth_Server {
	const MCP_SERVER_ID              = 'wp-ai-bridge-direct';
	const LEGACY_MCP_SERVER_ID       = 'wp-native-builder-direct';
	const MCP_ROUTE_NAMESPACE        = 'wp-ai-bridge/v1';
	const LEGACY_MCP_ROUTE_NAMESPACE = 'wp-native-builder/v1';
	const MCP_ROUTE                  = 'mcp';
	const MCP_REQUEST_ROUTE          = '/wp-ai-bridge/v1/mcp';
	const LEGACY_MCP_REQUEST_ROUTE   = '/wp-native-builder/v1/mcp';
	const AUTHORIZATION_PATH         = '/wp-ai-bridge/oauth/authorize';
	const LEGACY_AUTHORIZATION_PATH  = '/wp-native-builder/oauth/authorize';
	const PROTECTED_META_PATH        = '/.well-known/oauth-protected-resource';
	const LEGACY_PROTECTED_META_PATH = '/.well-known/oauth-protected-resource/wp-native-builder/v1/mcp';
	const AUTH_SERVER_META_PATH      = '/.well-known/oauth-authorization-server';

	const CHATGPT_CLIENT_ID    = 'https://chatgpt.com/oauth/client.json';
	const CHATGPT_REDIRECT_URI = 'https://chatgpt.com/connector_platform_oauth_redirect';

	const SCOPE_MCP     = 'mcp:use';
	const SCOPE_OFFLINE = 'offline_access';

	const CONSENT_TTL = 600;
	const CODE_TTL    = 300;
	const ACCESS_TTL  = 3600;
	const REFRESH_TTL = 2592000;

	const CLIENT_METADATA_CACHE = 'wpnb_oauth_chatgpt_cimd_ok';

	/**
	 * Opaque artifact store.
	 *
	 * @var OAuth_Store
	 */
	private $store;

	/**
	 * ChatGPT signed-client assertion validator.
	 *
	 * @var Client_Assertion_Validator
	 */
	private $client_assertions;

	/**
	 * Request-local authentication state used to shape MCP HTTP challenges.
	 *
	 * @var string
	 */
	private $auth_state = 'none';

	/**
	 * Creates the OAuth service.
	 *
	 * @param OAuth_Store|null $store Optional store override for tests.
	 */
	public function __construct( ?OAuth_Store $store = null ) {
		$this->store             = $store ? $store : new OAuth_Store();
		$this->client_assertions = new Client_Assertion_Validator( $this->store );
	}

	/**
	 * Registers WordPress and MCP Adapter hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'mcp_adapter_init', array( $this, 'register_mcp_server' ), 20, 1 );
		add_action( 'rest_api_init', array( $this, 'register_oauth_routes' ), 20 );
		add_action( 'parse_request', array( $this, 'maybe_handle_public_endpoint' ), 1 );
		add_action( 'wpnb_oauth_cleanup_client_assertion', array( $this->store, 'cleanup_client_assertion' ), 10, 2 );
		add_filter( 'rest_post_dispatch', array( $this, 'add_mcp_authentication_challenge' ), 10, 3 );
	}

	/**
	 * Registers canonical and migration-compatibility HTTP servers through MCP Adapter.
	 *
	 * @param object $adapter MCP Adapter instance.
	 * @return void
	 */
	public function register_mcp_server( $adapter ) {
		if (
			! is_object( $adapter ) ||
			! method_exists( $adapter, 'create_server' ) ||
			! class_exists( '\\WP\\MCP\\Transport\\HttpTransport' ) ||
			! class_exists( '\\WP\\MCP\\Infrastructure\\ErrorHandling\\NullMcpErrorHandler' ) ||
			! class_exists( '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler' )
		) {
			return;
		}

		$servers = array(
			array( self::MCP_SERVER_ID, self::MCP_ROUTE_NAMESPACE, 'WP AI Bridge' ),
			array( self::LEGACY_MCP_SERVER_ID, self::LEGACY_MCP_ROUTE_NAMESPACE, 'WP AI Bridge (legacy endpoint)' ),
		);

		foreach ( $servers as $server ) {
			$adapter->create_server(
				$server[0],
				$server[1],
				self::MCP_ROUTE,
				$server[2],
				'Direct ChatGPT MCP endpoint for WordPress-native administration.',
				WP_NATIVE_BUILDER_BRIDGE_VERSION,
				array( '\\WP\\MCP\\Transport\\HttpTransport' ),
				'\\WP\\MCP\\Infrastructure\\ErrorHandling\\NullMcpErrorHandler',
				'\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler',
				array(
					'mcp-adapter/discover-abilities',
					'mcp-adapter/get-ability-info',
					'mcp-adapter/execute-ability',
				),
				array(),
				array(),
				array( $this, 'authenticate_mcp_request' )
			);
		}
	}

	/**
	 * Registers OAuth token and revocation endpoints under canonical and legacy namespaces.
	 *
	 * @return void
	 */
	public function register_oauth_routes() {
		foreach ( array( self::MCP_ROUTE_NAMESPACE, self::LEGACY_MCP_ROUTE_NAMESPACE ) as $namespace ) {
			register_rest_route(
				$namespace,
				'/oauth/token',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_token_request' ),
					'permission_callback' => '__return_true',
				)
			);

			register_rest_route(
				$namespace,
				'/oauth/revoke',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_revoke_request' ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	/**
	 * Returns the canonical direct MCP resource URL users paste into ChatGPT.
	 *
	 * @return string MCP endpoint URL.
	 */
	public function mcp_endpoint_url() {
		return rest_url( self::MCP_ROUTE_NAMESPACE . '/' . self::MCP_ROUTE );
	}

	/**
	 * Returns the legacy MCP resource URL retained for existing connections.
	 *
	 * @return string Legacy MCP endpoint URL.
	 */
	public function legacy_mcp_endpoint_url() {
		return rest_url( self::LEGACY_MCP_ROUTE_NAMESPACE . '/' . self::MCP_ROUTE );
	}

	/**
	 * Returns the authorization server issuer identifier.
	 *
	 * @return string Issuer URL.
	 */
	public function issuer_url() {
		return untrailingslashit( home_url( '/' ) );
	}

	/**
	 * Returns the canonical protected-resource metadata URL.
	 *
	 * @return string Metadata URL.
	 */
	public function protected_resource_metadata_url() {
		return home_url( self::PROTECTED_META_PATH );
	}

	/**
	 * Returns the protected-resource metadata URL for the retained legacy resource.
	 *
	 * @return string Legacy metadata URL.
	 */
	public function legacy_protected_resource_metadata_url() {
		return home_url( self::LEGACY_PROTECTED_META_PATH );
	}

	/**
	 * Returns the authorization-server metadata URL.
	 *
	 * @return string Metadata URL.
	 */
	public function authorization_server_metadata_url() {
		return home_url( self::AUTH_SERVER_META_PATH );
	}

	/**
	 * Returns the authorization endpoint URL.
	 *
	 * @return string Authorization URL.
	 */
	public function authorization_endpoint_url() {
		return home_url( self::AUTHORIZATION_PATH );
	}

	/**
	 * Returns the legacy browser authorization endpoint retained as an alias.
	 *
	 * @return string Legacy authorization URL.
	 */
	public function legacy_authorization_endpoint_url() {
		return home_url( self::LEGACY_AUTHORIZATION_PATH );
	}

	/**
	 * Returns the token endpoint URL.
	 *
	 * @return string Token URL.
	 */
	public function token_endpoint_url() {
		return rest_url( self::MCP_ROUTE_NAMESPACE . '/oauth/token' );
	}

	/**
	 * Returns the legacy OAuth token endpoint retained for existing clients.
	 *
	 * @return string Legacy token URL.
	 */
	public function legacy_token_endpoint_url() {
		return rest_url( self::LEGACY_MCP_ROUTE_NAMESPACE . '/oauth/token' );
	}

	/**
	 * Returns the revocation endpoint URL.
	 *
	 * @return string Revocation URL.
	 */
	public function revocation_endpoint_url() {
		return rest_url( self::MCP_ROUTE_NAMESPACE . '/oauth/revoke' );
	}

	/**
	 * Returns the legacy revocation endpoint retained for existing clients.
	 *
	 * @return string Legacy revocation URL.
	 */
	public function legacy_revocation_endpoint_url() {
		return rest_url( self::LEGACY_MCP_ROUTE_NAMESPACE . '/oauth/revoke' );
	}

	/**
	 * Returns whether the canonical MCP endpoint is HTTPS.
	 *
	 * @return bool True for HTTPS deployment.
	 */
	public function is_https_ready() {
		return 'https' === strtolower( (string) wp_parse_url( $this->mcp_endpoint_url(), PHP_URL_SCHEME ) );
	}

	/**
	 * Returns OAuth Protected Resource Metadata (RFC 9728) for the canonical resource.
	 *
	 * @return array<string,mixed> Metadata document.
	 */
	public function protected_resource_metadata() {
		return $this->protected_resource_metadata_for( $this->mcp_endpoint_url(), 'WP AI Bridge' );
	}

	/**
	 * Returns OAuth Protected Resource Metadata for the retained legacy resource.
	 *
	 * @return array<string,mixed> Metadata document.
	 */
	public function legacy_protected_resource_metadata() {
		return $this->protected_resource_metadata_for( $this->legacy_mcp_endpoint_url(), 'WP AI Bridge (legacy endpoint)' );
	}

	/**
	 * Returns OAuth Authorization Server Metadata (RFC 8414 + MCP requirements).
	 *
	 * @return array<string,mixed> Metadata document.
	 */
	public function authorization_server_metadata() {
		return array(
			'issuer'                                     => $this->issuer_url(),
			'authorization_endpoint'                     => $this->authorization_endpoint_url(),
			'token_endpoint'                             => $this->token_endpoint_url(),
			'revocation_endpoint'                        => $this->revocation_endpoint_url(),
			'authorization_response_iss_parameter_supported' => true,
			'client_id_metadata_document_supported'      => true,
			'token_endpoint_auth_methods_supported'      => array( 'private_key_jwt' ),
			'token_endpoint_auth_signing_alg_values_supported' => array( 'RS256' ),
			'revocation_endpoint_auth_methods_supported' => array( 'private_key_jwt' ),
			'revocation_endpoint_auth_signing_alg_values_supported' => array( 'RS256' ),
			'grant_types_supported'                      => array( 'authorization_code', 'refresh_token' ),
			'response_types_supported'                   => array( 'code' ),
			'code_challenge_methods_supported'           => array( 'S256' ),
			'scopes_supported'                           => $this->supported_scopes(),
		);
	}

	/**
	 * Serves canonical/legacy protected-resource metadata, authorization metadata, and authorization aliases.
	 *
	 * @return void
	 */
	public function maybe_handle_public_endpoint() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		if ( ! is_string( $request_path ) ) {
			return;
		}

		$protected_path        = wp_parse_url( $this->protected_resource_metadata_url(), PHP_URL_PATH );
		$legacy_protected_path = wp_parse_url( $this->legacy_protected_resource_metadata_url(), PHP_URL_PATH );
		$server_path           = wp_parse_url( $this->authorization_server_metadata_url(), PHP_URL_PATH );
		$authorize_path        = wp_parse_url( $this->authorization_endpoint_url(), PHP_URL_PATH );
		$legacy_authorize_path = wp_parse_url( $this->legacy_authorization_endpoint_url(), PHP_URL_PATH );

		if ( $request_path === $protected_path ) {
			$this->serve_metadata_document( $this->protected_resource_metadata() );
		}

		if ( $request_path === $legacy_protected_path ) {
			$this->serve_metadata_document( $this->legacy_protected_resource_metadata() );
		}

		if ( $request_path === $server_path ) {
			$this->serve_metadata_document( $this->authorization_server_metadata() );
		}

		if ( $request_path === $authorize_path || $request_path === $legacy_authorize_path ) {
			$this->handle_authorization_endpoint();
		}
	}

	/**
	 * Authenticates an incoming Bridge MCP request with an opaque Bearer access token.
	 *
	 * @param \WP_REST_Request $request MCP request.
	 * @return bool Whether transport access is authorized.
	 */
	public function authenticate_mcp_request( $request ) {
		$this->auth_state = 'missing';
		if ( ! $this->is_https_ready() ) {
			$this->auth_state = 'invalid';
			return false;
		}

		if ( ! $request instanceof \WP_REST_Request ) {
			$this->auth_state = 'invalid';
			return false;
		}

		$authorization = trim( (string) $request->get_header( 'Authorization' ) );
		if ( '' === $authorization ) {
			return false;
		}

		if ( 1 !== preg_match( '/^Bearer[ ]+([^[:space:]]+)$/i', $authorization, $matches ) ) {
			$this->auth_state = 'invalid';
			return false;
		}

		$claims = $this->store->read( OAuth_Store::TYPE_ACCESS, $matches[1] );
		if ( false === $claims ) {
			$this->auth_state = 'invalid';
			return false;
		}

		$expected_resource = $this->request_resource_url( $request );
		if (
			'' === $expected_resource ||
			empty( $claims['client_id'] ) ||
			! hash_equals( self::CHATGPT_CLIENT_ID, (string) $claims['client_id'] ) ||
			empty( $claims['resource'] ) ||
			! hash_equals( $expected_resource, (string) $claims['resource'] ) ||
			empty( $claims['scope'] ) ||
			! in_array( self::SCOPE_MCP, $this->parse_scope( (string) $claims['scope'] ), true ) ||
			empty( $claims['user_id'] )
		) {
			$this->auth_state = 'invalid';
			return false;
		}

		$user = get_user_by( 'id', (int) $claims['user_id'] );
		if ( ! $user ) {
			$this->auth_state = 'invalid';
			return false;
		}

		if ( ! user_can( $user, 'read' ) ) {
			$this->auth_state = 'forbidden';
			return false;
		}

		wp_set_current_user( (int) $claims['user_id'] );
		$this->auth_state = 'authenticated';
		return true;
	}

	/**
	 * Ensures missing/invalid credentials produce an OAuth-discoverable MCP challenge.
	 *
	 * @param mixed            $response REST response.
	 * @param \WP_REST_Server  $server   REST server.
	 * @param \WP_REST_Request $request  REST request.
	 * @return mixed REST response.
	 */
	public function add_mcp_authentication_challenge( $response, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter signature.
		if ( ! $request instanceof \WP_REST_Request || ! in_array( $request->get_route(), array( self::MCP_REQUEST_ROUTE, self::LEGACY_MCP_REQUEST_ROUTE ), true ) ) {
			return $response;
		}

		if ( ! in_array( $this->auth_state, array( 'missing', 'invalid' ), true ) ) {
			return $response;
		}

		$response = rest_ensure_response( $response );
		$response->set_status( 401 );
		$response->header( 'WWW-Authenticate', $this->www_authenticate_header( $request ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Handles the OAuth token endpoint.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Token or OAuth error response.
	 */
	public function handle_token_request( $request ) {
		if ( ! $this->is_https_ready() ) {
			return $this->oauth_error( 'invalid_request', 'OAuth token issuance requires an HTTPS MCP resource.' );
		}

		$client_auth = $this->client_assertions->validate(
			$request,
			self::CHATGPT_CLIENT_ID,
			array( $this->token_endpoint_url(), $this->legacy_token_endpoint_url(), $this->issuer_url() )
		);
		if ( is_wp_error( $client_auth ) ) {
			return $this->oauth_error( $client_auth->get_error_code(), $client_auth->get_error_message() );
		}

		$grant_type = $this->bounded_param( $request, 'grant_type', 64 );
		if ( 'authorization_code' === $grant_type ) {
			return $this->exchange_authorization_code( $request );
		}

		if ( 'refresh_token' === $grant_type ) {
			return $this->exchange_refresh_token( $request );
		}

		return $this->oauth_error( 'unsupported_grant_type', 'Supported grant types are authorization_code and refresh_token.' );
	}

	/**
	 * Handles RFC 7009-style token revocation for the authenticated ChatGPT client.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Empty success response.
	 */
	public function handle_revoke_request( $request ) {
		if ( ! $this->is_https_ready() ) {
			return $this->oauth_error( 'invalid_request', 'OAuth token revocation requires an HTTPS MCP resource.' );
		}

		$client_auth = $this->client_assertions->validate(
			$request,
			self::CHATGPT_CLIENT_ID,
			array(
				$this->revocation_endpoint_url(),
				$this->legacy_revocation_endpoint_url(),
				$this->token_endpoint_url(),
				$this->legacy_token_endpoint_url(),
				$this->issuer_url(),
			)
		);
		if ( is_wp_error( $client_auth ) ) {
			return $this->oauth_error( $client_auth->get_error_code(), $client_auth->get_error_message() );
		}

		$token = $this->bounded_param( $request, 'token', 256 );
		if ( '' !== $token ) {
			$this->store->revoke( $token );
		}

		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Handles the browser-facing authorization endpoint.
	 *
	 * @return void
	 */
	private function handle_authorization_endpoint() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'POST' === $method ) {
			$this->handle_consent_submission();
			return;
		}

		if ( 'GET' !== $method ) {
			status_header( 405 );
			header( 'Allow: GET, POST' );
			wp_die( esc_html__( 'Method not allowed.', 'wp-native-builder-bridge' ), '', array( 'response' => 405 ) );
		}

		$params    = array_map( 'wp_unslash', $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth authorization requests are validated below and do not mutate state.
		$validated = $this->validate_authorization_request( $params );
		if ( is_wp_error( $validated ) ) {
			$this->authorization_failure( $validated, $params );
		}

		if ( ! is_user_logged_in() ) {
			$return_url = add_query_arg( $validated, $this->authorization_endpoint_url() );
			wp_safe_redirect( wp_login_url( $return_url ) );
			exit;
		}

		if ( ! current_user_can( 'read' ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Your WordPress account is not allowed to connect this site.', 'wp-native-builder-bridge' ), '', array( 'response' => 403 ) );
		}

		$validated['user_id'] = get_current_user_id();
		$consent_id           = $this->store->issue( OAuth_Store::TYPE_CONSENT, $validated, self::CONSENT_TTL );
		$this->render_consent_page( $consent_id, $validated );
	}

	/**
	 * Validates an authorization request before login/consent.
	 *
	 * @param array<string,mixed> $params Raw query parameters.
	 * @return array<string,string>|\WP_Error Valid request or error.
	 */
	private function validate_authorization_request( array $params ) {
		if ( ! $this->is_https_ready() ) {
			return new \WP_Error( 'invalid_request', 'OAuth authorization requires an HTTPS MCP resource.' );
		}

		$client_id     = $this->bounded_array_value( $params, 'client_id', 256 );
		$redirect_uri  = $this->bounded_array_value( $params, 'redirect_uri', 512 );
		$response_type = $this->bounded_array_value( $params, 'response_type', 32 );
		$challenge     = $this->bounded_array_value( $params, 'code_challenge', 160 );
		$method        = $this->bounded_array_value( $params, 'code_challenge_method', 16 );
		$resource      = $this->bounded_array_value( $params, 'resource', 1024 );
		$scope         = $this->bounded_array_value( $params, 'scope', 256 );
		$state         = $this->bounded_array_value( $params, 'state', 1024 );

		if ( self::CHATGPT_CLIENT_ID !== $client_id ) {
			return new \WP_Error( 'invalid_client', 'Only the current ChatGPT CIMD client is accepted.' );
		}

		if ( self::CHATGPT_REDIRECT_URI !== $redirect_uri ) {
			return new \WP_Error( 'invalid_request', 'The ChatGPT redirect URI is invalid.' );
		}

		if ( 'code' !== $response_type ) {
			return new \WP_Error( 'unsupported_response_type', 'Only the authorization code response type is supported.' );
		}

		if ( 'S256' !== $method || 1 !== preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $challenge ) ) {
			return new \WP_Error( 'invalid_request', 'PKCE with an S256 code challenge is required.' );
		}

		if ( ! $this->is_supported_resource_url( $resource ) ) {
			return new \WP_Error( 'invalid_target', 'The OAuth resource does not match a supported WP AI Bridge MCP endpoint.' );
		}

		$normalized_scope = $this->normalize_scope( $scope );
		if ( is_wp_error( $normalized_scope ) ) {
			return $normalized_scope;
		}

		if ( '' === $state ) {
			return new \WP_Error( 'invalid_request', 'A state value is required.' );
		}

		$client_metadata = $this->validate_chatgpt_client_metadata();
		if ( is_wp_error( $client_metadata ) ) {
			return $client_metadata;
		}

		return array(
			'client_id'             => $client_id,
			'redirect_uri'          => $redirect_uri,
			'response_type'         => 'code',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'resource'              => $resource,
			'scope'                 => $normalized_scope,
			'state'                 => $state,
		);
	}

	/**
	 * Validates the current ChatGPT Client ID Metadata Document from its fixed HTTPS URL.
	 *
	 * @return true|\WP_Error True when valid, otherwise an OAuth error.
	 */
	private function validate_chatgpt_client_metadata() {
		if ( 1 === (int) get_transient( self::CLIENT_METADATA_CACHE ) ) {
			return true;
		}

		$response = wp_safe_remote_get(
			self::CHATGPT_CLIENT_ID,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'temporarily_unavailable', 'ChatGPT client metadata could not be verified.' );
		}

		$metadata = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $metadata ) ) {
			return new \WP_Error( 'invalid_client', 'ChatGPT client metadata is not valid JSON.' );
		}

		$redirects   = isset( $metadata['redirect_uris'] ) && is_array( $metadata['redirect_uris'] ) ? $metadata['redirect_uris'] : array();
		$grants      = isset( $metadata['grant_types'] ) && is_array( $metadata['grant_types'] ) ? $metadata['grant_types'] : array();
		$responses   = isset( $metadata['response_types'] ) && is_array( $metadata['response_types'] ) ? $metadata['response_types'] : array();
		$auth_method = isset( $metadata['token_endpoint_auth_method'] ) && is_string( $metadata['token_endpoint_auth_method'] ) ? $metadata['token_endpoint_auth_method'] : '';
		$jwks_uri    = isset( $metadata['jwks_uri'] ) && is_string( $metadata['jwks_uri'] ) ? $metadata['jwks_uri'] : '';

		if (
			self::CHATGPT_CLIENT_ID !== ( $metadata['client_id'] ?? '' ) ||
			! in_array( self::CHATGPT_REDIRECT_URI, $redirects, true ) ||
			! in_array( 'authorization_code', $grants, true ) ||
			! in_array( 'refresh_token', $grants, true ) ||
			! in_array( 'code', $responses, true ) ||
			'private_key_jwt' !== $auth_method ||
			Client_Assertion_Validator::CHATGPT_JWKS_URI !== $jwks_uri
		) {
			return new \WP_Error( 'invalid_client', 'ChatGPT client metadata does not satisfy the supported OAuth profile.' );
		}

		set_transient( self::CLIENT_METADATA_CACHE, 1, 15 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Processes an authenticated WordPress user's consent decision.
	 *
	 * @return void
	 */
	private function handle_consent_submission() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->authorization_endpoint_url() ) );
			exit;
		}

		$consent_id = isset( $_POST['consent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['consent_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below before state mutation.
		$nonce      = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the nonce being verified.
		$decision   = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below before state mutation.

		if ( '' === $consent_id || ! wp_verify_nonce( $nonce, 'wpnb_oauth_consent_' . $consent_id ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'The OAuth consent request is invalid or expired.', 'wp-native-builder-bridge' ), '', array( 'response' => 403 ) );
		}

		$claims = $this->store->read( OAuth_Store::TYPE_CONSENT, $consent_id, true );
		if ( false === $claims || empty( $claims['user_id'] ) || get_current_user_id() !== (int) $claims['user_id'] ) {
			status_header( 400 );
			wp_die( esc_html__( 'The OAuth consent request is invalid or expired.', 'wp-native-builder-bridge' ), '', array( 'response' => 400 ) );
		}

		if ( 'approve' !== $decision ) {
			$this->redirect_authorization_response(
				(string) $claims['redirect_uri'],
				array(
					'error' => 'access_denied',
					'state' => (string) $claims['state'],
					'iss'   => $this->issuer_url(),
				)
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			$this->redirect_authorization_response(
				(string) $claims['redirect_uri'],
				array(
					'error' => 'access_denied',
					'state' => (string) $claims['state'],
					'iss'   => $this->issuer_url(),
				)
			);
		}

		$code = $this->store->issue(
			OAuth_Store::TYPE_CODE,
			array(
				'user_id'        => get_current_user_id(),
				'client_id'      => (string) $claims['client_id'],
				'redirect_uri'   => (string) $claims['redirect_uri'],
				'code_challenge' => (string) $claims['code_challenge'],
				'resource'       => (string) $claims['resource'],
				'scope'          => (string) $claims['scope'],
			),
			self::CODE_TTL
		);

		$this->redirect_authorization_response(
			(string) $claims['redirect_uri'],
			array(
				'code'  => $code,
				'state' => (string) $claims['state'],
				'iss'   => $this->issuer_url(),
			)
		);
	}

	/**
	 * Exchanges a one-time authorization code with mandatory PKCE verification.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Token or error response.
	 */
	private function exchange_authorization_code( $request ) {
		$code         = $this->bounded_param( $request, 'code', 256 );
		$client_id    = $this->bounded_param( $request, 'client_id', 256 );
		$client_id    = '' === $client_id ? self::CHATGPT_CLIENT_ID : $client_id;
		$redirect_uri = $this->bounded_param( $request, 'redirect_uri', 512 );
		$resource     = $this->bounded_param( $request, 'resource', 1024 );
		$verifier     = $this->bounded_param( $request, 'code_verifier', 160 );

		if ( self::CHATGPT_CLIENT_ID !== $client_id || self::CHATGPT_REDIRECT_URI !== $redirect_uri ) {
			return $this->oauth_error( 'invalid_client', 'The OAuth client is invalid.' );
		}

		if ( ! $this->is_supported_resource_url( $resource ) ) {
			return $this->oauth_error( 'invalid_target', 'The OAuth resource is invalid.' );
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
			return $this->oauth_error( 'invalid_grant', 'The PKCE verifier is invalid.' );
		}

		$claims = $this->store->read( OAuth_Store::TYPE_CODE, $code, true );
		if ( false === $claims ) {
			return $this->oauth_error( 'invalid_grant', 'The authorization code is invalid, expired, or already used.' );
		}

		if (
			! hash_equals( (string) $claims['client_id'], $client_id ) ||
			! hash_equals( (string) $claims['redirect_uri'], $redirect_uri ) ||
			! hash_equals( (string) $claims['resource'], $resource ) ||
			! hash_equals( (string) $claims['code_challenge'], $this->pkce_challenge( $verifier ) )
		) {
			return $this->oauth_error( 'invalid_grant', 'The authorization code binding is invalid.' );
		}

		return $this->issue_token_response( $claims );
	}

	/**
	 * Rotates a refresh token and issues a new access/refresh pair.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Token or error response.
	 */
	private function exchange_refresh_token( $request ) {
		$refresh_token = $this->bounded_param( $request, 'refresh_token', 256 );
		$client_id     = $this->bounded_param( $request, 'client_id', 256 );
		$client_id     = '' === $client_id ? self::CHATGPT_CLIENT_ID : $client_id;
		$resource      = $this->bounded_param( $request, 'resource', 1024 );

		if ( self::CHATGPT_CLIENT_ID !== $client_id ) {
			return $this->oauth_error( 'invalid_client', 'The OAuth client is invalid.' );
		}

		if ( ! $this->is_supported_resource_url( $resource ) ) {
			return $this->oauth_error( 'invalid_target', 'The OAuth resource is invalid.' );
		}

		$claims = $this->store->read( OAuth_Store::TYPE_REFRESH, $refresh_token, true );
		if ( false === $claims ) {
			return $this->oauth_error( 'invalid_grant', 'The refresh token is invalid, expired, revoked, or already rotated.' );
		}

		if (
			! hash_equals( (string) $claims['client_id'], $client_id ) ||
			! hash_equals( (string) $claims['resource'], $resource ) ||
			empty( $claims['scope'] ) ||
			! in_array( self::SCOPE_OFFLINE, $this->parse_scope( (string) $claims['scope'] ), true )
		) {
			return $this->oauth_error( 'invalid_grant', 'The refresh token binding is invalid.' );
		}

		return $this->issue_token_response( $claims );
	}

	/**
	 * Issues a short-lived access token and rotating refresh token.
	 *
	 * @param array<string,mixed> $claims Authorization claims.
	 * @return \WP_REST_Response Token response.
	 */
	private function issue_token_response( array $claims ) {
		$user_id   = isset( $claims['user_id'] ) ? (int) $claims['user_id'] : 0;
		$client_id = isset( $claims['client_id'] ) ? (string) $claims['client_id'] : '';
		$resource  = isset( $claims['resource'] ) ? (string) $claims['resource'] : '';
		$scope     = isset( $claims['scope'] ) ? (string) $claims['scope'] : '';
		$user      = $user_id ? get_user_by( 'id', $user_id ) : false;

		if (
			! $user ||
			! user_can( $user, 'read' ) ||
			self::CHATGPT_CLIENT_ID !== $client_id ||
			! $this->is_supported_resource_url( $resource )
		) {
			return $this->oauth_error( 'invalid_grant', 'The WordPress authorization is no longer valid.' );
		}

		$token_claims = array(
			'user_id'   => $user_id,
			'client_id' => $client_id,
			'resource'  => $resource,
			'scope'     => $scope,
		);

		$access_token = $this->store->issue( OAuth_Store::TYPE_ACCESS, $token_claims, self::ACCESS_TTL );
		$data         = array(
			'access_token' => $access_token,
			'token_type'   => 'Bearer',
			'expires_in'   => self::ACCESS_TTL,
			'scope'        => $scope,
		);

		if ( in_array( self::SCOPE_OFFLINE, $this->parse_scope( $scope ), true ) ) {
			$data['refresh_token'] = $this->store->issue( OAuth_Store::TYPE_REFRESH, $token_claims, self::REFRESH_TTL );
		}

		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Normalizes requested OAuth scopes and requires the MCP scope.
	 *
	 * @param string $scope Space-delimited scope string.
	 * @return string|\WP_Error Normalized scopes or error.
	 */
	private function normalize_scope( $scope ) {
		$requested = $this->parse_scope( $scope );
		if ( empty( $requested ) ) {
			// Default to the minimum usable scope. Long-lived refresh access must be explicit.
			$requested = array( self::SCOPE_MCP );
		}

		foreach ( $requested as $item ) {
			if ( ! in_array( $item, $this->supported_scopes(), true ) ) {
				return new \WP_Error( 'invalid_scope', 'The requested OAuth scope is not supported.' );
			}
		}

		if ( ! in_array( self::SCOPE_MCP, $requested, true ) ) {
			return new \WP_Error( 'invalid_scope', 'The mcp:use scope is required.' );
		}

		$normalized = array();
		foreach ( $this->supported_scopes() as $supported ) {
			if ( in_array( $supported, $requested, true ) ) {
				$normalized[] = $supported;
			}
		}

		return implode( ' ', $normalized );
	}

	/**
	 * Returns whether an OAuth resource is one of the two exact migration endpoints.
	 *
	 * @param string $resource_url Candidate resource URL.
	 * @return bool Whether the resource is canonical or the retained legacy alias.
	 */
	private function is_supported_resource_url( $resource_url ) {
		$resource_url = (string) $resource_url;
		foreach ( array( $this->mcp_endpoint_url(), $this->legacy_mcp_endpoint_url() ) as $supported ) {
			if ( hash_equals( $supported, $resource_url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Maps one exact MCP REST route to its own audience URL.
	 *
	 * @param \WP_REST_Request $request MCP request.
	 * @return string Exact audience URL, or an empty string for another route.
	 */
	private function request_resource_url( $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return '';
		}

		$route = $request->get_route();
		if ( self::MCP_REQUEST_ROUTE === $route ) {
			return $this->mcp_endpoint_url();
		}
		if ( self::LEGACY_MCP_REQUEST_ROUTE === $route ) {
			return $this->legacy_mcp_endpoint_url();
		}

		return '';
	}

	/**
	 * Builds a protected-resource metadata document for one exact resource.
	 *
	 * @param string $resource_url  Exact resource URL.
	 * @param string $resource_name Public resource label.
	 * @return array<string,mixed> Metadata document.
	 */
	private function protected_resource_metadata_for( $resource_url, $resource_name ) {
		return array(
			'resource'                 => (string) $resource_url,
			'authorization_servers'    => array( $this->issuer_url() ),
			'scopes_supported'         => $this->supported_scopes(),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => (string) $resource_name,
		);
	}

	/**
	 * Returns supported OAuth scopes in stable order.
	 *
	 * @return array<int,string> Scope list.
	 */
	private function supported_scopes() {
		return array( self::SCOPE_MCP, self::SCOPE_OFFLINE );
	}

	/**
	 * Parses a bounded space-delimited scope string.
	 *
	 * @param string $scope Scope string.
	 * @return array<int,string> Unique scopes.
	 */
	private function parse_scope( $scope ) {
		$scope = trim( (string) $scope );
		if ( '' === $scope ) {
			return array();
		}

		$items = preg_split( '/[ ]+/', $scope );
		$items = is_array( $items ) ? array_values( array_unique( array_filter( $items, 'strlen' ) ) ) : array();
		return array_slice( $items, 0, 8 );
	}

	/**
	 * Produces an S256 PKCE challenge.
	 *
	 * @param string $verifier PKCE verifier.
	 * @return string Challenge.
	 */
	private function pkce_challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Returns the OAuth Bearer challenge for the exact MCP resource route.
	 *
	 * @param \WP_REST_Request|null $request Optional MCP request.
	 * @return string WWW-Authenticate value.
	 */
	private function www_authenticate_header( $request = null ) {
		$metadata_url = $this->protected_resource_metadata_url();
		if ( $request instanceof \WP_REST_Request && self::LEGACY_MCP_REQUEST_ROUTE === $request->get_route() ) {
			$metadata_url = $this->legacy_protected_resource_metadata_url();
		}

		return sprintf(
			'Bearer resource_metadata="%s", scope="%s"',
			$metadata_url,
			implode( ' ', $this->supported_scopes() )
		);
	}

	/**
	 * Reads one bounded scalar REST parameter.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $name    Parameter name.
	 * @param int              $max     Maximum length.
	 * @return string Bounded value or empty string.
	 */
	private function bounded_param( $request, $name, $max ) {
		$value = $request instanceof \WP_REST_Request ? $request->get_param( $name ) : '';
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;
		return strlen( $value ) <= (int) $max ? $value : '';
	}

	/**
	 * Reads one bounded scalar array value.
	 *
	 * @param array<string,mixed> $values Source values.
	 * @param string              $name   Key.
	 * @param int                 $max    Maximum length.
	 * @return string Bounded value or empty string.
	 */
	private function bounded_array_value( array $values, $name, $max ) {
		if ( ! isset( $values[ $name ] ) || ! is_scalar( $values[ $name ] ) ) {
			return '';
		}

		$value = (string) $values[ $name ];
		return strlen( $value ) <= (int) $max ? $value : '';
	}

	/**
	 * Returns a no-store OAuth JSON error.
	 *
	 * @param string $code        OAuth error code.
	 * @param string $description Human-readable description.
	 * @return \WP_REST_Response Error response.
	 */
	private function oauth_error( $code, $description ) {
		$response = new \WP_REST_Response(
			array(
				'error'             => sanitize_key( $code ),
				'error_description' => (string) $description,
			),
			400
		);
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Handles an invalid authorization request without creating an open redirect.
	 *
	 * @param \WP_Error           $error  OAuth validation error.
	 * @param array<string,mixed> $params Original parameters.
	 * @return void
	 */
	private function authorization_failure( $error, array $params ) {
		$redirect_uri = $this->bounded_array_value( $params, 'redirect_uri', 512 );
		$client_id    = $this->bounded_array_value( $params, 'client_id', 256 );
		$state        = $this->bounded_array_value( $params, 'state', 1024 );

		if ( self::CHATGPT_CLIENT_ID === $client_id && self::CHATGPT_REDIRECT_URI === $redirect_uri ) {
			$values = array(
				'error' => sanitize_key( $error->get_error_code() ),
				'iss'   => $this->issuer_url(),
			);
			if ( '' !== $state ) {
				$values['state'] = $state;
			}
			$this->redirect_authorization_response( $redirect_uri, $values );
		}

		status_header( 400 );
		wp_die( esc_html( $error->get_error_message() ), esc_html__( 'OAuth authorization error', 'wp-native-builder-bridge' ), array( 'response' => 400 ) );
	}

	/**
	 * Redirects only to the fixed ChatGPT callback URI validated by this server.
	 *
	 * @param string               $redirect_uri Fixed redirect URI.
	 * @param array<string,string> $values       OAuth response values.
	 * @return void
	 */
	private function redirect_authorization_response( $redirect_uri, array $values ) {
		if ( self::CHATGPT_REDIRECT_URI !== $redirect_uri ) {
			status_header( 400 );
			wp_die( esc_html__( 'Invalid OAuth redirect URI.', 'wp-native-builder-bridge' ), '', array( 'response' => 400 ) );
		}

		$url = add_query_arg( $values, $redirect_uri );
		wp_redirect( $url, 302, 'WP AI Bridge' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Destination is the exact hard-coded ChatGPT callback above.
		exit;
	}

	/**
	 * Renders a minimal same-origin WordPress consent screen.
	 *
	 * @param string               $consent_id Opaque consent request ID.
	 * @param array<string,string> $request    Validated authorization request.
	 * @return void
	 */
	private function render_consent_page( $consent_id, array $request ) {
		$user = wp_get_current_user();
		nocache_headers();
		send_frame_options_header();
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self' https://chatgpt.com; base-uri 'none'; frame-ancestors 'none'" );
		status_header( 200 );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html__( 'Authorize ChatGPT', 'wp-native-builder-bridge' ); ?></title>
	<style>body{font-family:system-ui,sans-serif;background:#f0f0f1;margin:0;padding:32px}.wpnb-oauth{max-width:620px;margin:40px auto;background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:28px;box-shadow:0 1px 2px rgba(0,0,0,.04)}h1{margin-top:0;font-size:24px}code{word-break:break-all}.actions{display:flex;gap:12px;margin-top:24px}.button{border:1px solid #2271b1;border-radius:3px;padding:8px 14px;font:inherit;cursor:pointer}.primary{background:#2271b1;color:#fff}.secondary{background:#fff;color:#2271b1}</style>
</head>
<body>
	<main class="wpnb-oauth">
		<h1><?php echo esc_html__( 'Authorize ChatGPT for this WordPress site', 'wp-native-builder-bridge' ); ?></h1>
		<p><?php echo esc_html__( 'ChatGPT is requesting an OAuth connection to WP AI Bridge. The connection acts as your current WordPress account, and every Bridge ability still checks its access group and WordPress capabilities.', 'wp-native-builder-bridge' ); ?></p>
		<p><strong><?php echo esc_html__( 'WordPress account:', 'wp-native-builder-bridge' ); ?></strong> <?php echo esc_html( $user->display_name ); ?></p>
		<p><strong><?php echo esc_html__( 'Site:', 'wp-native-builder-bridge' ); ?></strong> <?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
		<p><strong><?php echo esc_html__( 'MCP resource:', 'wp-native-builder-bridge' ); ?></strong><br><code><?php echo esc_html( $request['resource'] ); ?></code></p>
		<p><strong><?php echo esc_html__( 'OAuth scopes:', 'wp-native-builder-bridge' ); ?></strong> <code><?php echo esc_html( $request['scope'] ); ?></code></p>
		<?php if ( in_array( self::SCOPE_OFFLINE, $this->parse_scope( (string) $request['scope'] ), true ) ) : ?>
			<p><?php echo esc_html__( 'The offline_access scope lets ChatGPT refresh this OAuth connection without asking you to sign in again each time. It does not enable any Bridge access group or add WordPress capabilities.', 'wp-native-builder-bridge' ); ?></p>
		<?php endif; ?>
		<p><?php echo esc_html__( 'Access remains limited by the enabled groups under WP AI Bridge → Settings. You can deny this request without changing those settings.', 'wp-native-builder-bridge' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->authorization_endpoint_url() ); ?>">
			<input type="hidden" name="consent_id" value="<?php echo esc_attr( $consent_id ); ?>">
			<?php wp_nonce_field( 'wpnb_oauth_consent_' . $consent_id ); ?>
			<div class="actions">
				<button class="button primary" type="submit" name="decision" value="approve"><?php echo esc_html__( 'Authorize ChatGPT', 'wp-native-builder-bridge' ); ?></button>
				<button class="button secondary" type="submit" name="decision" value="deny"><?php echo esc_html__( 'Deny', 'wp-native-builder-bridge' ); ?></button>
			</div>
		</form>
	</main>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Serves public OAuth discovery metadata.
	 *
	 * @param array<string,mixed> $document Metadata document.
	 * @return void
	 */
	private function serve_metadata_document( array $document ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			header( 'Allow: GET' );
			wp_send_json( array( 'error' => 'method_not_allowed' ), 405 );
		}

		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=300' );
		wp_send_json( $document, 200, JSON_UNESCAPED_SLASHES );
	}
}
