<?php
/**
 * private_key_jwt OAuth client authentication.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Auth;

/**
 * Validates signed assertions for one exact already-approved OAuth client.
 */
final class Client_Assertion_Validator {
	const CHATGPT_JWKS_URI      = 'https://chatgpt.com/oauth/jwks.json';
	const ASSERTION_TYPE        = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
	const JWKS_CACHE            = 'wpnb_oauth_chatgpt_jwks';
	const JWKS_REFRESH_COOLDOWN = 'wpnb_oauth_chatgpt_jwks_refresh';
	const JWKS_REFRESH_INTERVAL = 60;
	const MAX_ASSERTION_TTL     = 600;
	const CLOCK_SKEW            = 60;
	const MAX_JWKS_SIZE         = 65536;

	/** @var OAuth_Store */
	private $store;

	/**
	 * @param OAuth_Store $store OAuth storage service.
	 */
	public function __construct( OAuth_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Reads only the untrusted iss/sub identity needed to select an approved client.
	 *
	 * The returned value has not been authenticated. Callers may use it only as an
	 * exact key into an administrator-approved registry before full validation.
	 *
	 * @param \WP_REST_Request $request OAuth request.
	 * @return string|\WP_Error Candidate client ID or error.
	 */
	public function identify_client( $request ) {
		$parsed = $this->parse_assertion( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$claims = $parsed['claims'];
		$iss    = isset( $claims['iss'] ) && is_string( $claims['iss'] ) ? $claims['iss'] : '';
		$sub    = isset( $claims['sub'] ) && is_string( $claims['sub'] ) ? $claims['sub'] : '';
		if ( '' === $iss || strlen( $iss ) > Approved_OAuth_Clients::MAX_CLIENT_ID || ! hash_equals( $iss, $sub ) ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion identity is invalid.' );
		}
		return $iss;
	}

	/**
	 * Validates one private_key_jwt assertion against an exact client profile.
	 *
	 * @param \WP_REST_Request    $request   OAuth request.
	 * @param array<string,mixed> $profile   Validated approved client profile.
	 * @param array<int,string>   $audiences Accepted authorization-server audiences.
	 * @return true|\WP_Error True when valid, otherwise an OAuth error.
	 */
	public function validate( $request, array $profile, array $audiences ) {
		if ( ! $request instanceof \WP_REST_Request || ! function_exists( 'openssl_verify' ) ) {
			return new \WP_Error( 'invalid_client', 'Signed OAuth client authentication is unavailable.' );
		}

		$parsed = $this->parse_assertion( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$parts  = $parsed['parts'];
		$header = $parsed['header'];
		$claims = $parsed['claims'];

		$client_id = isset( $profile['client_id'] ) && is_string( $profile['client_id'] ) ? $profile['client_id'] : '';
		$jwks_uri  = isset( $profile['jwks_uri'] ) && is_string( $profile['jwks_uri'] ) ? $profile['jwks_uri'] : '';
		if ( '' === $client_id || '' === $jwks_uri ) {
			return new \WP_Error( 'invalid_client', 'The approved OAuth client profile is incomplete.' );
		}

		$kid = isset( $header['kid'] ) && is_string( $header['kid'] ) ? $header['kid'] : '';
		if ( 'RS256' !== ( $header['alg'] ?? '' ) || '' === $kid || strlen( $kid ) > 160 ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion signing header is invalid.' );
		}
		if (
			( $claims['iss'] ?? '' ) !== $client_id ||
			( $claims['sub'] ?? '' ) !== $client_id ||
			! $this->audience_matches( $claims['aud'] ?? null, $audiences )
		) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion claims are invalid.' );
		}

		$now = time();
		$exp = isset( $claims['exp'] ) && is_numeric( $claims['exp'] ) ? (int) $claims['exp'] : 0;
		$iat = isset( $claims['iat'] ) && is_numeric( $claims['iat'] ) ? (int) $claims['iat'] : 0;
		$jti = isset( $claims['jti'] ) && is_string( $claims['jti'] ) ? $claims['jti'] : '';
		if (
			$exp <= ( $now - self::CLOCK_SKEW ) ||
			$exp > ( $now + self::MAX_ASSERTION_TTL ) ||
			( $iat && ( $iat > ( $now + self::CLOCK_SKEW ) || $iat < ( $now - self::MAX_ASSERTION_TTL ) ) ) ||
			'' === $jti ||
			strlen( $jti ) > 256
		) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion lifetime or identifier is invalid.' );
		}
		if ( isset( $claims['nbf'] ) && is_numeric( $claims['nbf'] ) && (int) $claims['nbf'] > ( $now + self::CLOCK_SKEW ) ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion is not active yet.' );
		}

		$jwk = $this->find_signing_key( $kid, $client_id, $jwks_uri );
		if ( is_wp_error( $jwk ) ) {
			return $jwk;
		}
		$pem = $this->rsa_jwk_to_pem( $jwk );
		if ( is_wp_error( $pem ) ) {
			return $pem;
		}
		$verified = openssl_verify( $parts[0] . '.' . $parts[1], $parsed['signature'], $pem, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion signature is invalid.' );
		}

		$replay_ttl       = max( 1, min( self::MAX_ASSERTION_TTL, $exp - $now + self::CLOCK_SKEW ) );
		$replay_namespace = OAuth_Server::CHATGPT_CLIENT_ID === $client_id ? '' : $client_id;
		if ( ! $this->store->claim_client_assertion( $jti, $replay_ttl, $replay_namespace ) ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion has already been used.' );
		}

		return true;
	}

	/**
	 * Clears bounded per-client JWKS caches after an administrator trust change.
	 *
	 * @param string $client_id Client ID.
	 * @return void
	 */
	public static function clear_client_cache( $client_id ) {
		delete_transient( self::cache_key_for( $client_id ) );
		delete_transient( self::cooldown_key_for( $client_id ) );
	}

	/**
	 * Parses the bounded JWT envelope without authenticating its contents.
	 *
	 * @param \WP_REST_Request $request OAuth request.
	 * @return array<string,mixed>|\WP_Error Parsed assertion.
	 */
	private function parse_assertion( $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return new \WP_Error( 'invalid_client', 'A private_key_jwt client assertion is required.' );
		}
		$assertion_type = $this->bounded_param( $request, 'client_assertion_type', 128 );
		$assertion      = $this->bounded_param( $request, 'client_assertion', 8192 );
		if ( self::ASSERTION_TYPE !== $assertion_type || '' === $assertion ) {
			return new \WP_Error( 'invalid_client', 'A private_key_jwt client assertion is required.' );
		}
		$parts = explode( '.', $assertion );
		if ( 3 !== count( $parts ) ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion is malformed.' );
		}
		foreach ( $parts as $part ) {
			if ( '' === $part || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $part ) ) {
				return new \WP_Error( 'invalid_client', 'The OAuth client assertion is malformed.' );
			}
		}
		$header_json = $this->base64url_decode( $parts[0] );
		$claims_json = $this->base64url_decode( $parts[1] );
		$signature   = $this->base64url_decode( $parts[2] );
		if ( false === $header_json || false === $claims_json || false === $signature ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion encoding is invalid.' );
		}
		$header = json_decode( $header_json, true );
		$claims = json_decode( $claims_json, true );
		if ( ! is_array( $header ) || ! is_array( $claims ) ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion JSON is invalid.' );
		}
		return array(
			'parts'     => $parts,
			'header'    => $header,
			'claims'    => $claims,
			'signature' => $signature,
		);
	}

	/**
	 * Returns whether an assertion audience names this authorization server.
	 *
	 * @param mixed             $claim     aud claim.
	 * @param array<int,string> $audiences Accepted audiences.
	 * @return bool Match result.
	 */
	private function audience_matches( $claim, array $audiences ) {
		$claimed = is_string( $claim ) ? array( $claim ) : ( is_array( $claim ) ? $claim : array() );
		$claimed = array_values( array_filter( $claimed, 'is_string' ) );
		foreach ( array_slice( $claimed, 0, 8 ) as $value ) {
			if ( in_array( $value, $audiences, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Retrieves one current RS256 signing key, refreshing once for rotation.
	 *
	 * @param string $kid       Key identifier.
	 * @param string $client_id Exact client ID.
	 * @param string $jwks_uri  Exact approved JWKS URI.
	 * @return array<string,mixed>|\WP_Error JWK or error.
	 */
	private function find_signing_key( $kid, $client_id, $jwks_uri ) {
		$jwks = $this->jwks( $client_id, $jwks_uri );
		if ( is_wp_error( $jwks ) ) {
			return $jwks;
		}
		$key = $this->select_signing_key( $jwks, $kid );
		if ( false !== $key ) {
			return $key;
		}

		$cooldown = self::cooldown_key_for( $client_id );
		if ( false !== get_transient( $cooldown ) ) {
			return new \WP_Error( 'invalid_client', 'No trusted signing key matches the OAuth client assertion.' );
		}
		set_transient( $cooldown, 1, self::JWKS_REFRESH_INTERVAL );
		$jwks = $this->jwks( $client_id, $jwks_uri, true );
		if ( is_wp_error( $jwks ) ) {
			return $jwks;
		}
		$key = $this->select_signing_key( $jwks, $kid );
		return false !== $key ? $key : new \WP_Error( 'invalid_client', 'No trusted signing key matches the OAuth client assertion.' );
	}

	/**
	 * Selects one exact RS256 signing key from a bounded JWKS set.
	 *
	 * @param array<int,array<string,mixed>> $jwks Signing keys.
	 * @param string                         $kid  Requested key ID.
	 * @return array<string,mixed>|false Matching key or false.
	 */
	private function select_signing_key( array $jwks, $kid ) {
		foreach ( $jwks as $key ) {
			if (
				is_array( $key ) &&
				( $key['kid'] ?? '' ) === $kid &&
				'RSA' === ( $key['kty'] ?? '' ) &&
				'RS256' === ( $key['alg'] ?? '' ) &&
				( ! isset( $key['use'] ) || 'sig' === $key['use'] )
			) {
				return $key;
			}
		}
		return false;
	}

	/**
	 * Fetches and validates one bounded public-HTTPS JWKS document.
	 *
	 * @param string $client_id     Exact approved client ID.
	 * @param string $jwks_uri      Exact approved JWKS URI.
	 * @param bool   $force_refresh Whether to bypass local cache.
	 * @return array<int,array<string,mixed>>|\WP_Error Signing keys or error.
	 */
	private function jwks( $client_id, $jwks_uri, $force_refresh = false ) {
		$cache_key = self::cache_key_for( $client_id );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}
		if ( ! $this->is_safe_public_https_url( $jwks_uri ) ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client JWKS URI is not a safe public HTTPS target.' );
		}

		$response = wp_safe_remote_get(
			$jwks_uri,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_JWKS_SIZE + 1,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'temporarily_unavailable', 'OAuth client signing keys could not be verified.' );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'invalid_client', 'OAuth client signing-key metadata returned an unexpected HTTP status.' );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_JWKS_SIZE ) {
			return new \WP_Error( 'invalid_client', 'OAuth client signing-key metadata exceeds the bounded response size.' );
		}

		$document = json_decode( $body, true );
		$keys     = isset( $document['keys'] ) && is_array( $document['keys'] ) ? array_slice( $document['keys'], 0, 10 ) : array();
		$valid    = array();
		foreach ( $keys as $key ) {
			if (
				is_array( $key ) &&
				'RSA' === ( $key['kty'] ?? '' ) &&
				'RS256' === ( $key['alg'] ?? '' ) &&
				isset( $key['kid'], $key['n'], $key['e'] ) &&
				is_string( $key['kid'] ) && strlen( $key['kid'] ) <= 160 &&
				is_string( $key['n'] ) && strlen( $key['n'] ) <= 1024 &&
				is_string( $key['e'] ) && strlen( $key['e'] ) <= 32
			) {
				$valid[] = $key;
			}
		}
		if ( empty( $valid ) ) {
			return new \WP_Error( 'invalid_client', 'OAuth client signing-key metadata is invalid.' );
		}

		set_transient( $cache_key, $valid, 15 * MINUTE_IN_SECONDS );
		set_transient( self::cooldown_key_for( $client_id ), 1, self::JWKS_REFRESH_INTERVAL );
		return $valid;
	}

	/**
	 * Converts an RSA JWK to SubjectPublicKeyInfo PEM.
	 *
	 * @param array<string,mixed> $jwk RSA JWK.
	 * @return string|\WP_Error PEM or error.
	 */
	private function rsa_jwk_to_pem( array $jwk ) {
		$modulus  = $this->base64url_decode( (string) ( $jwk['n'] ?? '' ) );
		$exponent = $this->base64url_decode( (string) ( $jwk['e'] ?? '' ) );
		if ( false === $modulus || false === $exponent || strlen( $modulus ) < 256 || strlen( $modulus ) > 512 || '' === $exponent || strlen( $exponent ) > 8 ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client RSA signing key is invalid.' );
		}
		$rsa_public = $this->der_sequence( $this->der_integer( $modulus ) . $this->der_integer( $exponent ) );
		$algorithm  = hex2bin( '300d06092a864886f70d0101010500' );
		if ( false === $algorithm ) {
			return new \WP_Error( 'invalid_client', 'The RSA signing-key algorithm is unavailable.' );
		}
		$subject_public_key = $this->der_sequence( $algorithm . $this->der_bit_string( $rsa_public ) );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $subject_public_key ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/** @param string $bytes Raw unsigned integer. @return string DER integer. */
	private function der_integer( $bytes ) {
		$bytes = ltrim( $bytes, "\x00" );
		if ( '' === $bytes ) {
			$bytes = "\x00";
		}
		if ( ord( $bytes[0] ) & 0x80 ) {
			$bytes = "\x00" . $bytes;
		}
		return "\x02" . $this->der_length( strlen( $bytes ) ) . $bytes;
	}

	/** @param string $bytes DER payload. @return string DER sequence. */
	private function der_sequence( $bytes ) {
		return "\x30" . $this->der_length( strlen( $bytes ) ) . $bytes;
	}

	/** @param string $bytes DER payload. @return string DER bit string. */
	private function der_bit_string( $bytes ) {
		$payload = "\x00" . $bytes;
		return "\x03" . $this->der_length( strlen( $payload ) ) . $payload;
	}

	/** @param int $length Payload length. @return string DER length. */
	private function der_length( $length ) {
		$length = (int) $length;
		if ( $length < 128 ) {
			return chr( $length );
		}
		$encoded = '';
		while ( $length > 0 ) {
			$encoded = chr( $length & 0xff ) . $encoded;
			$length >>= 8;
		}
		return chr( 0x80 | strlen( $encoded ) ) . $encoded;
	}

	/**
	 * Strict base64url decoder.
	 *
	 * @param string $value Encoded value.
	 * @return string|false Decoded bytes or false.
	 */
	private function base64url_decode( $value ) {
		if ( ! is_string( $value ) || '' === $value || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			return false;
		}
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	/**
	 * Reads one bounded scalar REST parameter.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $name    Parameter name.
	 * @param int              $max     Maximum length.
	 * @return string Bounded scalar or empty string.
	 */
	private function bounded_param( $request, $name, $max ) {
		$value = $request->get_param( $name );
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		return strlen( $value ) <= (int) $max ? $value : '';
	}

	/**
	 * Validates a public HTTPS URL before an outbound request.
	 *
	 * @param string $url URL.
	 * @return bool Safe target.
	 */
	private function is_safe_public_https_url( $url ) {
		$url = is_string( $url ) ? $url : '';
		return '' !== $url && strlen( $url ) <= 512 && 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) && false === strpos( $url, '#' ) && (bool) wp_http_validate_url( $url );
	}

	/** @param string $client_id Client ID. @return string Cache key. */
	private static function cache_key_for( $client_id ) {
		return OAuth_Server::CHATGPT_CLIENT_ID === (string) $client_id ? self::JWKS_CACHE : 'wpnb_oauth_jwks_' . substr( hash( 'sha256', (string) $client_id ), 0, 24 );
	}

	/** @param string $client_id Client ID. @return string Cooldown key. */
	private static function cooldown_key_for( $client_id ) {
		return OAuth_Server::CHATGPT_CLIENT_ID === (string) $client_id ? self::JWKS_REFRESH_COOLDOWN : 'wpnb_oauth_jwks_refresh_' . substr( hash( 'sha256', (string) $client_id ), 0, 24 );
	}
}
