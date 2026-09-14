<?php
/**
 * OAuth transient-backed token storage.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Auth;

/**
 * Stores opaque OAuth artifacts without persisting bearer secrets in plaintext.
 */
final class OAuth_Store {
	const INSTANCE_OPTION = 'wp_native_builder_bridge_oauth_instance';

	const TYPE_CONSENT = 'consent';
	const TYPE_CODE    = 'code';
	const TYPE_ACCESS  = 'access';
	const TYPE_REFRESH = 'refresh';

	/** @var string Exact client authenticated for one token-endpoint flow. */
	private $authenticated_client_id = '';

	/**
	 * Returns the current installation identity, creating it lazily when needed.
	 *
	 * @return string Installation identity.
	 */
	public function instance_id() {
		$stored = get_option( self::INSTANCE_OPTION, '' );
		if ( is_string( $stored ) && preg_match( '/^[a-f0-9]{32}$/', $stored ) ) {
			return $stored;
		}
		$generated = bin2hex( random_bytes( 16 ) );
		if ( add_option( self::INSTANCE_OPTION, $generated, '', false ) ) {
			return $generated;
		}
		$stored = get_option( self::INSTANCE_OPTION, '' );
		return is_string( $stored ) && preg_match( '/^[a-f0-9]{32}$/', $stored ) ? $stored : $generated;
	}

	/**
	 * Binds or clears the exact client authenticated for the current OAuth flow.
	 *
	 * This is request-local process state only. It is never persisted and is used
	 * solely to ensure that one-time code/refresh consumption cannot cross client
	 * boundaries after private_key_jwt authentication has succeeded.
	 *
	 * @param string $client_id Exact authenticated client ID, or empty to clear.
	 * @return void
	 */
	public function set_authenticated_client( $client_id ) {
		if ( ! is_string( $client_id ) || strlen( $client_id ) > 256 ) {
			$this->authenticated_client_id = '';
			return;
		}
		$this->authenticated_client_id = $client_id;
	}

	/**
	 * Issues an opaque artifact and stores only a keyed hash of its secret.
	 *
	 * @param string              $type   Artifact type.
	 * @param array<string,mixed> $claims Claims bound to the artifact.
	 * @param int                 $ttl    Lifetime in seconds.
	 * @return string Opaque artifact.
	 */
	public function issue( $type, array $claims, $ttl ) {
		$prefix = $this->token_prefix( $type );
		$ttl    = max( 1, (int) $ttl );
		if ( '' === $prefix ) {
			throw new \InvalidArgumentException( 'Unsupported OAuth artifact type.' );
		}
		$selector = $this->random_urlsafe( 18 );
		$secret   = $this->random_urlsafe( 32 );
		$token    = $prefix . '.' . $selector . '.' . $secret;

		$claims['secret_hash'] = $this->hash_secret( $secret );
		$claims['expires_at']  = time() + $ttl;
		$claims['instance_id'] = $this->instance_id();
		set_transient( $this->transient_key( $type, $selector ), $claims, $ttl );
		return $token;
	}

	/**
	 * Reads and validates an opaque artifact.
	 *
	 * When an expected client is supplied, its binding is checked before an
	 * otherwise valid one-time artifact is consumed. Token-endpoint code/refresh
	 * consumption automatically uses the request-local client established by the
	 * signed assertion validator, preventing one approved OAuth client from
	 * invalidating another client's artifact merely by presenting its opaque value.
	 *
	 * @param string $type               Artifact type.
	 * @param string $token              Opaque artifact.
	 * @param bool   $consume            Whether to remove a successfully validated artifact.
	 * @param string $expected_client_id Optional authenticated client binding.
	 * @return array<string,mixed>|false Valid claims or false.
	 */
	public function read( $type, $token, $consume = false, $expected_client_id = '' ) {
		if ( ! is_string( $expected_client_id ) || strlen( $expected_client_id ) > 256 ) {
			return false;
		}
		if (
			'' === $expected_client_id &&
			$consume &&
			in_array( $type, array( self::TYPE_CODE, self::TYPE_REFRESH ), true )
		) {
			$expected_client_id = $this->authenticated_client_id;
		}

		$parsed = $this->parse( $type, $token );
		if ( false === $parsed ) {
			return false;
		}
		list( $selector, $secret ) = $parsed;
		$key                       = $this->transient_key( $type, $selector );
		$claims                    = get_transient( $key );
		if ( ! is_array( $claims ) || empty( $claims['secret_hash'] ) || empty( $claims['expires_at'] ) || empty( $claims['instance_id'] ) ) {
			return false;
		}
		if ( (int) $claims['expires_at'] <= time() ) {
			delete_transient( $key );
			return false;
		}
		if ( ! hash_equals( (string) $claims['instance_id'], $this->instance_id() ) ) {
			return false;
		}
		if ( ! hash_equals( (string) $claims['secret_hash'], $this->hash_secret( $secret ) ) ) {
			return false;
		}
		if ( '' !== $expected_client_id ) {
			if ( empty( $claims['client_id'] ) || ! hash_equals( $expected_client_id, (string) $claims['client_id'] ) ) {
				return false;
			}
		}
		if ( $consume && ! delete_transient( $key ) ) {
			return false;
		}
		unset( $claims['secret_hash'], $claims['instance_id'] );
		return $claims;
	}

	/**
	 * Atomically claims one signed client-assertion JWT ID for its short lifetime.
	 *
	 * Historical ChatGPT assertions keep the legacy empty namespace so replay
	 * claims created immediately before an upgrade remain effective. Additional
	 * clients use their exact client ID as a namespace so equal jti values cannot
	 * collide across independently operated clients.
	 *
	 * @param string $jti       JWT ID.
	 * @param int    $ttl       Claim lifetime in seconds.
	 * @param string $client_id Optional replay namespace.
	 * @return bool True only for the first successful claim.
	 */
	public function claim_client_assertion( $jti, $ttl, $client_id = '' ) {
		if ( ! is_string( $jti ) || '' === $jti || strlen( $jti ) > 256 || ! is_string( $client_id ) || strlen( $client_id ) > 256 ) {
			return false;
		}
		$ttl        = max( 1, min( 600, (int) $ttl ) );
		$expires_at = time() + $ttl;
		$material   = '' === $client_id ? $jti : $client_id . "\0" . $jti;
		$key        = 'wpnb_oauth_assertion_' . substr( hash( 'sha256', $material ), 0, 40 );
		$existing   = get_option( $key, false );
		if ( false !== $existing && (int) $existing <= time() ) {
			delete_option( $key );
		}
		if ( ! add_option( $key, $expires_at, '', false ) ) {
			return false;
		}
		wp_schedule_single_event( $expires_at + MINUTE_IN_SECONDS, 'wpnb_oauth_cleanup_client_assertion', array( $key, $expires_at ) );
		return true;
	}

	/**
	 * Removes one expired client-assertion replay claim created by this store.
	 *
	 * @param string $key             Stored option name.
	 * @param int    $expected_expiry Expiry captured when the claim was created.
	 * @return void
	 */
	public function cleanup_client_assertion( $key, $expected_expiry ) {
		if ( ! is_string( $key ) || 1 !== preg_match( '/^wpnb_oauth_assertion_[a-f0-9]{40}$/', $key ) ) {
			return;
		}
		$stored = get_option( $key, false );
		if ( false !== $stored && (int) $stored === (int) $expected_expiry && (int) $stored <= time() ) {
			delete_option( $key );
		}
	}

	/**
	 * Revokes one opaque artifact when its secret and optional client binding are valid.
	 *
	 * @param string $token              Opaque artifact.
	 * @param string $expected_client_id Optional authenticated client binding.
	 * @return bool Whether a valid artifact was revoked.
	 */
	public function revoke( $token, $expected_client_id = '' ) {
		foreach ( array( self::TYPE_CONSENT, self::TYPE_CODE, self::TYPE_ACCESS, self::TYPE_REFRESH ) as $type ) {
			$parsed = $this->parse( $type, $token );
			if ( false === $parsed ) {
				continue;
			}
			list( $selector, $secret ) = $parsed;
			$key                       = $this->transient_key( $type, $selector );
			$claims                    = get_transient( $key );
			if ( ! is_array( $claims ) || empty( $claims['secret_hash'] ) ) {
				return false;
			}
			if ( ! hash_equals( (string) $claims['secret_hash'], $this->hash_secret( $secret ) ) ) {
				return false;
			}
			if ( '' !== $expected_client_id ) {
				if ( empty( $claims['client_id'] ) || ! hash_equals( $expected_client_id, (string) $claims['client_id'] ) ) {
					return false;
				}
			}
			return delete_transient( $key );
		}
		return false;
	}

	/**
	 * Parses a token for one expected type.
	 *
	 * @param string $type  Artifact type.
	 * @param mixed  $token Token value.
	 * @return array{0:string,1:string}|false Selector and secret or false.
	 */
	private function parse( $type, $token ) {
		$prefix = $this->token_prefix( $type );
		if ( '' === $prefix || ! is_string( $token ) || strlen( $token ) > 256 ) {
			return false;
		}
		$pattern = '/^' . preg_quote( $prefix, '/' ) . '\.([A-Za-z0-9_-]{20,40})\.([A-Za-z0-9_-]{40,80})$/';
		if ( 1 !== preg_match( $pattern, $token, $matches ) ) {
			return false;
		}
		return array( $matches[1], $matches[2] );
	}

	/** @param string $type Artifact type. @return string Prefix. */
	private function token_prefix( $type ) {
		$prefixes = array(
			self::TYPE_CONSENT => 'wpnb_q',
			self::TYPE_CODE    => 'wpnb_c',
			self::TYPE_ACCESS  => 'wpnb_a',
			self::TYPE_REFRESH => 'wpnb_r',
		);
		return isset( $prefixes[ $type ] ) ? $prefixes[ $type ] : '';
	}

	/** @param string $type Artifact type. @param string $selector Selector. @return string Transient key. */
	private function transient_key( $type, $selector ) {
		return 'wpnb_oauth_' . sanitize_key( $type ) . '_' . $selector;
	}

	/** @param string $secret Bearer secret. @return string Secret hash. */
	private function hash_secret( $secret ) {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
	}

	/** @param int $bytes Random byte count. @return string URL-safe value. */
	private function random_urlsafe( $bytes ) {
		return rtrim( strtr( base64_encode( random_bytes( (int) $bytes ) ), '+/', '-_' ), '=' );
	}
}
