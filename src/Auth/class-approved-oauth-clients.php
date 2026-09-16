<?php
/**
 * Administrator-approved OAuth client registry.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Auth;

/**
 * Keeps additional OAuth client trust explicit, bounded, and separate from Bridge access groups.
 */
final class Approved_OAuth_Clients {
	const OPTION_NAME      = 'wp_ai_bridge_oauth_clients';
	const OPTION_GROUP     = 'wp_ai_bridge_oauth_clients';
	const REVISION_OPTION  = 'wp_ai_bridge_oauth_clients_revision';
	const ADMIN_PAGE_SLUG  = 'wp-ai-bridge-oauth-clients';
	const MAX_CLIENTS      = 10;
	const MAX_CLIENT_ID    = 256;
	const MAX_REDIRECT_URI = 512;
	const MAX_METADATA     = 65536;

	const CHATGPT_METADATA_CACHE = 'wpai_oauth_chatgpt_cimd_ok';

	/**
	 * Registers settings and the bounded administration screen.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_init', array( $this, 'register_setting' ), 20 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
	}

	/**
	 * Registers the additional-client allowlist as an independent option.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Registers the OAuth client administration page under WP AI Bridge.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'wp-ai-bridge',
			__( 'OAuth Clients', 'wp-ai-bridge' ),
			__( 'OAuth Clients', 'wp-ai-bridge' ),
			'manage_options',
			self::ADMIN_PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Renders the small administrator allowlist editor.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP AI Bridge settings.', 'wp-ai-bridge' ) );
		}

		$clients = $this->all();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'OAuth Clients', 'wp-ai-bridge' ); ?></h1>
			<p><?php echo esc_html__( 'ChatGPT remains a built-in OAuth client and needs no configuration. Add only HTTPS Client ID Metadata Document URLs for independently operated clients you explicitly trust to request a WordPress connection.', 'wp-ai-bridge' ); ?></p>
			<p><strong><?php echo esc_html__( 'Built-in client:', 'wp-ai-bridge' ); ?></strong> <code><?php echo esc_html( OAuth_Server::CHATGPT_CLIENT_ID ); ?></code></p>
			<p><?php echo esc_html__( 'Approving a client grants connection eligibility only. Every MCP operation still requires the enabled WP AI Bridge access group and the connected WordPress user capabilities. Changing this list immediately invalidates outstanding additional-client OAuth artifacts.', 'wp-ai-bridge' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation"><tbody><tr>
					<th scope="row"><label for="wpai-oauth-client-ids"><?php echo esc_html__( 'Approved client metadata URLs', 'wp-ai-bridge' ); ?></label></th>
					<td>
						<textarea id="wpai-oauth-client-ids" name="<?php echo esc_attr( self::OPTION_NAME ); ?>" rows="8" cols="90" class="large-text code" spellcheck="false"><?php echo esc_textarea( implode( "\n", $clients ) ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'One exact public HTTPS client_id URL per line. Wildcards, HTTP URLs, local/private network targets, redirects, and shared secrets are not accepted.', 'wp-ai-bridge' ); ?></p>
					</td>
				</tr></tbody></table>
				<?php submit_button( __( 'Save OAuth Clients', 'wp-ai-bridge' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitizes an administrator-submitted exact client allowlist.
	 *
	 * A configuration change rotates the shared additional-client approval revision.
	 * That deliberately invalidates every outstanding non-ChatGPT code/access/refresh
	 * artifact, which makes removal fail closed even if the same client is later re-added.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<int,string> Normalized approved client IDs.
	 */
	public function sanitize( $input ) {
		$values = is_array( $input ) ? $input : preg_split( '/[\r\n]+/', (string) $input );
		$values = is_array( $values ) ? $values : array();
		$result = array();

		foreach ( array_slice( $values, 0, self::MAX_CLIENTS * 2 ) as $value ) {
			$client_id = $this->normalize_public_https_url( $value, self::MAX_CLIENT_ID );
			if ( '' === $client_id || OAuth_Server::CHATGPT_CLIENT_ID === $client_id ) {
				continue;
			}
			if ( ! in_array( $client_id, $result, true ) ) {
				$result[] = $client_id;
			}
			if ( count( $result ) >= self::MAX_CLIENTS ) {
				break;
			}
		}

		$before = $this->all();
		if ( $before !== $result ) {
			update_option( self::REVISION_OPTION, $this->revision() + 1, false );
			foreach ( array_unique( array_merge( $before, $result ) ) as $client_id ) {
				delete_transient( $this->metadata_cache_key( $client_id ) );
				Client_Assertion_Validator::clear_client_cache( $client_id );
			}
		}

		return $result;
	}

	/**
	 * Returns the exact approved additional client IDs.
	 *
	 * @return array<int,string> Approved IDs.
	 */
	public function all() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$result = array();
		foreach ( array_slice( $stored, 0, self::MAX_CLIENTS ) as $value ) {
			$client_id = $this->normalize_stored_https_url( $value, self::MAX_CLIENT_ID );
			if ( '' !== $client_id && OAuth_Server::CHATGPT_CLIENT_ID !== $client_id && ! in_array( $client_id, $result, true ) ) {
				$result[] = $client_id;
			}
		}
		return $result;
	}

	/**
	 * Returns the monotonically increasing additional-client approval revision.
	 *
	 * @return int Revision, minimum 1.
	 */
	public function revision() {
		return max( 1, (int) get_option( self::REVISION_OPTION, 1 ) );
	}

	/**
	 * Returns whether a client remains eligible right now.
	 *
	 * @param string $client_id Exact client ID.
	 * @return bool Eligibility.
	 */
	public function is_approved( $client_id ) {
		$client_id = (string) $client_id;
		if ( OAuth_Server::CHATGPT_CLIENT_ID === $client_id ) {
			return true;
		}
		return in_array( $client_id, $this->all(), true );
	}

	/**
	 * Resolves and validates one currently approved client metadata profile.
	 *
	 * Authorization uses this method so the built-in ChatGPT Client ID Metadata
	 * Document retains the same verification boundary as before additional clients.
	 *
	 * @param string $client_id Exact client metadata URL.
	 * @return array<string,mixed>|\WP_Error Validated client profile.
	 */
	public function resolve( $client_id ) {
		$client_id = (string) $client_id;
		if ( ! $this->is_approved( $client_id ) ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client is not approved.' );
		}

		$is_chatgpt = OAuth_Server::CHATGPT_CLIENT_ID === $client_id;
		$cache_key  = $this->metadata_cache_key( $client_id );
		$cached     = get_transient( $cache_key );
		if ( $is_chatgpt && 1 === (int) $cached ) {
			return $this->chatgpt_profile();
		}
		if ( ! $is_chatgpt && is_array( $cached ) && ( $cached['client_id'] ?? '' ) === $client_id ) {
			$cached['approval_revision'] = $this->revision();
			return $cached;
		}

		$metadata = $this->fetch_json( $client_id, 'OAuth client metadata' );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$profile = $this->validate_metadata( $client_id, $metadata, $is_chatgpt );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		if ( $is_chatgpt ) {
			set_transient( self::CHATGPT_METADATA_CACHE, 1, 15 * MINUTE_IN_SECONDS );
		} else {
			set_transient( $cache_key, $profile, 15 * MINUTE_IN_SECONDS );
		}
		return $profile;
	}

	/**
	 * Resolves the profile needed for signed token/revocation client authentication.
	 *
	 * The built-in ChatGPT client historically did not depend on a fresh Client ID
	 * Metadata fetch during token refresh or revocation. Keep that resilience and use
	 * its fixed compatibility profile; additional clients still require current
	 * administrator approval plus their validated metadata profile.
	 *
	 * @param string $client_id Exact client ID from the untrusted assertion identity.
	 * @return array<string,mixed>|\WP_Error Client-authentication profile.
	 */
	public function resolve_for_client_auth( $client_id ) {
		$client_id = (string) $client_id;
		if ( OAuth_Server::CHATGPT_CLIENT_ID === $client_id ) {
			return $this->chatgpt_profile();
		}
		return $this->resolve( $client_id );
	}

	/**
	 * Checks an exact redirect URI against a validated client profile.
	 *
	 * @param array<string,mixed> $profile      Validated profile.
	 * @param string              $redirect_uri Candidate redirect.
	 * @return bool Match result.
	 */
	public function redirect_allowed( array $profile, $redirect_uri ) {
		$redirect_uri = (string) $redirect_uri;
		return isset( $profile['redirect_uris'] ) && is_array( $profile['redirect_uris'] ) && in_array( $redirect_uri, $profile['redirect_uris'], true );
	}

	/**
	 * Returns the current artifact approval revision expected for a client.
	 *
	 * Built-in ChatGPT keeps revision zero so historical artifacts remain valid.
	 *
	 * @param string $client_id Client ID.
	 * @return int Revision.
	 */
	public function artifact_revision( $client_id ) {
		return OAuth_Server::CHATGPT_CLIENT_ID === (string) $client_id ? 0 : $this->revision();
	}

	/**
	 * Returns whether an artifact's stored client approval is still current.
	 *
	 * @param string $client_id Client ID.
	 * @param mixed  $revision  Stored revision.
	 * @return bool Current binding.
	 */
	public function artifact_is_current( $client_id, $revision ) {
		$client_id = (string) $client_id;
		if ( OAuth_Server::CHATGPT_CLIENT_ID === $client_id ) {
			return true;
		}
		return $this->is_approved( $client_id ) && (int) $revision === $this->revision();
	}

	/**
	 * Returns the fixed ChatGPT compatibility profile after metadata validation or
	 * for the historical token/revocation client-authentication path.
	 *
	 * @return array<string,mixed> Profile.
	 */
	private function chatgpt_profile() {
		return array(
			'client_id'         => OAuth_Server::CHATGPT_CLIENT_ID,
			'client_name'       => 'ChatGPT',
			'redirect_uris'     => array( OAuth_Server::CHATGPT_REDIRECT_URI ),
			'jwks_uri'          => Client_Assertion_Validator::CHATGPT_JWKS_URI,
			'approval_revision' => 0,
			'built_in'          => true,
		);
	}

	/**
	 * Validates an OAuth Client ID Metadata Document into a bounded profile.
	 *
	 * The built-in ChatGPT branch intentionally preserves the pre-Issue-54
	 * compatibility contract: its fixed redirect URI must be present, but unrelated
	 * additional redirect entries in the official metadata do not become trusted
	 * Bridge callbacks and do not invalidate the client. Additional clients use the
	 * stricter bounded exact-public-HTTPS redirect set below.
	 *
	 * @param string              $client_id  Exact requested identity.
	 * @param array<string,mixed> $metadata   Parsed JSON document.
	 * @param bool                $is_chatgpt Whether this is the built-in compatibility client.
	 * @return array<string,mixed>|\WP_Error Profile or error.
	 */
	private function validate_metadata( $client_id, array $metadata, $is_chatgpt ) {
		if ( ( $metadata['client_id'] ?? '' ) !== $client_id ) {
			return new \WP_Error( 'invalid_client', 'OAuth client metadata does not self-identify as the approved client.' );
		}

		$redirects   = isset( $metadata['redirect_uris'] ) && is_array( $metadata['redirect_uris'] ) ? $metadata['redirect_uris'] : array();
		$grants      = isset( $metadata['grant_types'] ) && is_array( $metadata['grant_types'] ) ? array_slice( $metadata['grant_types'], 0, 8 ) : array();
		$responses   = isset( $metadata['response_types'] ) && is_array( $metadata['response_types'] ) ? array_slice( $metadata['response_types'], 0, 8 ) : array();
		$auth_method = isset( $metadata['token_endpoint_auth_method'] ) && is_string( $metadata['token_endpoint_auth_method'] ) ? $metadata['token_endpoint_auth_method'] : '';
		$raw_jwks    = isset( $metadata['jwks_uri'] ) && is_string( $metadata['jwks_uri'] ) ? $metadata['jwks_uri'] : '';

		if ( $is_chatgpt ) {
			if (
				! in_array( OAuth_Server::CHATGPT_REDIRECT_URI, $redirects, true ) ||
				! in_array( 'authorization_code', $grants, true ) ||
				! in_array( 'refresh_token', $grants, true ) ||
				! in_array( 'code', $responses, true ) ||
				'private_key_jwt' !== $auth_method ||
				Client_Assertion_Validator::CHATGPT_JWKS_URI !== $raw_jwks
			) {
				return new \WP_Error( 'invalid_client', 'ChatGPT client metadata does not satisfy the built-in compatibility profile.' );
			}
			return $this->chatgpt_profile();
		}

		$redirects = array_slice( $redirects, 0, 9 );
		if ( empty( $redirects ) || count( $redirects ) > 8 ) {
			return new \WP_Error( 'invalid_client', 'OAuth client metadata contains an invalid redirect URI set.' );
		}
		$validated_redirects = array();
		foreach ( $redirects as $redirect_uri ) {
			$redirect_uri = $this->normalize_public_https_url( $redirect_uri, self::MAX_REDIRECT_URI );
			if ( '' === $redirect_uri || in_array( $redirect_uri, $validated_redirects, true ) ) {
				return new \WP_Error( 'invalid_client', 'OAuth client metadata contains an invalid redirect URI.' );
			}
			$validated_redirects[] = $redirect_uri;
		}

		$jwks_uri = $this->normalize_public_https_url( $raw_jwks, self::MAX_REDIRECT_URI );
		if (
			! in_array( 'authorization_code', $grants, true ) ||
			! in_array( 'refresh_token', $grants, true ) ||
			! in_array( 'code', $responses, true ) ||
			'private_key_jwt' !== $auth_method ||
			'' === $jwks_uri
		) {
			return new \WP_Error( 'invalid_client', 'OAuth client metadata does not satisfy the supported private_key_jwt profile.' );
		}

		$client_name = isset( $metadata['client_name'] ) && is_string( $metadata['client_name'] ) ? sanitize_text_field( $metadata['client_name'] ) : '';
		if ( '' === $client_name ) {
			$client_name = (string) wp_parse_url( $client_id, PHP_URL_HOST );
		}
		if ( strlen( $client_name ) > 120 ) {
			$client_name = (string) wp_parse_url( $client_id, PHP_URL_HOST );
		}

		return array(
			'client_id'         => $client_id,
			'client_name'       => $client_name,
			'redirect_uris'     => $validated_redirects,
			'jwks_uri'          => $jwks_uri,
			'approval_revision' => $this->revision(),
			'built_in'          => false,
		);
	}

	/**
	 * Fetches one bounded JSON document from an already safe public HTTPS URL.
	 *
	 * @param string $url   Exact URL.
	 * @param string $label Error label.
	 * @return array<string,mixed>|\WP_Error Parsed document.
	 */
	private function fetch_json( $url, $label ) {
		if ( '' === $this->normalize_public_https_url( $url, self::MAX_REDIRECT_URI ) ) {
			return new \WP_Error( 'invalid_client', $label . ' URL is not a safe public HTTPS target.' );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_METADATA + 1,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'temporarily_unavailable', $label . ' could not be verified.' );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			if ( OAuth_Server::CHATGPT_CLIENT_ID === $url ) {
				return new \WP_Error( 'temporarily_unavailable', 'ChatGPT client metadata could not be verified.' );
			}
			return new \WP_Error( 'invalid_client', $label . ' returned an unexpected HTTP status.' );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_METADATA ) {
			return new \WP_Error( 'invalid_client', $label . ' exceeds the bounded response size.' );
		}
		$document = json_decode( $body, true );
		return is_array( $document ) ? $document : new \WP_Error( 'invalid_client', $label . ' is not valid JSON.' );
	}

	/**
	 * Normalizes and validates one exact public HTTPS URL.
	 *
	 * @param mixed $value Candidate value.
	 * @param int   $max   Maximum byte length.
	 * @return string Safe URL or empty string.
	 */
	private function normalize_public_https_url( $value, $max ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$url = trim( (string) $value );
		if ( '' === $url || strlen( $url ) > (int) $max || false !== strpos( $url, '#' ) ) {
			return '';
		}
		$url = esc_url_raw( $url, array( 'https' ) );
		if ( '' === $url || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return '';
		}
		if ( '' !== (string) wp_parse_url( $url, PHP_URL_USER ) || '' !== (string) wp_parse_url( $url, PHP_URL_PASS ) ) {
			return '';
		}
		return wp_http_validate_url( $url ) ? $url : '';
	}

	/**
	 * Performs lightweight fail-closed validation for a value already sanitized at save time.
	 *
	 * Bearer authentication uses this path and therefore never performs DNS/network
	 * validation merely to check whether an administrator approval still exists.
	 *
	 * @param mixed $value Candidate stored value.
	 * @param int   $max   Maximum byte length.
	 * @return string Stored exact HTTPS URL or empty string.
	 */
	private function normalize_stored_https_url( $value, $max ) {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > (int) $max || false !== strpos( $value, '#' ) ) {
			return '';
		}
		if ( 'https' !== strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ) || '' === (string) wp_parse_url( $value, PHP_URL_HOST ) ) {
			return '';
		}
		if ( '' !== (string) wp_parse_url( $value, PHP_URL_USER ) || '' !== (string) wp_parse_url( $value, PHP_URL_PASS ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Returns the per-client metadata cache key while preserving the historical ChatGPT key.
	 *
	 * @param string $client_id Client ID.
	 * @return string Cache key.
	 */
	private function metadata_cache_key( $client_id ) {
		if ( OAuth_Server::CHATGPT_CLIENT_ID === (string) $client_id ) {
			return self::CHATGPT_METADATA_CACHE;
		}
		return 'wpai_oauth_client_meta_' . substr( hash( 'sha256', (string) $client_id ), 0, 24 );
	}
}
