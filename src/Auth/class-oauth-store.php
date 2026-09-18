<?php
/**
 * OAuth transient-backed token storage.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Auth;

/**
 * Stores opaque OAuth artifacts without persisting bearer secrets in plaintext.
 */
final class OAuth_Store {
	const INSTANCE_OPTION = 'wp_ai_bridge_oauth_instance';

	const TYPE_CONSENT = 'consent';
	const TYPE_CODE    = 'code';
	const TYPE_ACCESS  = 'access';
	const TYPE_REFRESH = 'refresh';

	const CLIENT_ASSERTION_REPLAY_TTL_CAP = 600;
	const CLIENT_ASSERTION_REPLAY_SKEW    = 60;

	const REFRESH_RECOVERY_VERSION = 1;
	const REFRESH_RECOVERY_TTL_MAX = 120;
	const REFRESH_LOCK_TIMEOUT     = 5;

	const REFRESH_RECOVERY_PREFIX = 'wpai_oauth_refresh_recovery_';
	const REFRESH_RECOVERY_CLAIM  = '_wpai_refresh_recovery_id';

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
		unset( $claims['secret_hash'], $claims['instance_id'], $claims[ self::REFRESH_RECOVERY_CLAIM ] );
		return $claims;
	}

	/**
	 * Acquires the per-source refresh recovery lock.
	 *
	 * The lock name is derived from a keyed digest of the complete opaque refresh
	 * token. Bearer material is never sent to MySQL/MariaDB as a lock name.
	 *
	 * @param string $refresh_token Exact source refresh token.
	 * @return string|false Recovery identity when locked, otherwise false.
	 */
	public function acquire_refresh_lock( $refresh_token ) {
		$recovery_id = $this->refresh_recovery_id( $refresh_token );
		if ( false === $recovery_id || ! $this->acquire_refresh_lock_id( $recovery_id ) ) {
			return false;
		}
		return $recovery_id;
	}

	/**
	 * Releases a previously acquired refresh recovery lock.
	 *
	 * @param string $recovery_id Recovery identity returned by acquire_refresh_lock().
	 * @return void
	 */
	public function release_refresh_lock( $recovery_id ) {
		$this->release_refresh_lock_id( $recovery_id );
	}

	/**
	 * Reads one authenticated short-lived refresh recovery envelope.
	 *
	 * @param string $refresh_token Exact previously presented refresh token.
	 * @return array<string,mixed>|false Recovery payload or false.
	 */
	public function read_refresh_recovery( $refresh_token ) {
		$recovery_id = $this->refresh_recovery_id( $refresh_token );
		if ( false === $recovery_id ) {
			return false;
		}
		$key      = $this->refresh_recovery_key( $recovery_id );
		$envelope = get_transient( $key );
		if ( false === $envelope ) {
			return false;
		}
		$payload = $this->decrypt_refresh_recovery( $recovery_id, $envelope );
		if ( false === $payload || ! $this->valid_refresh_recovery_payload( $payload, $recovery_id ) ) {
			delete_transient( $key );
			return false;
		}
		if ( (int) $payload['expires_at'] <= time() ) {
			delete_transient( $key );
			return false;
		}
		return $payload;
	}

	/**
	 * Stages exactly one successor generation and its encrypted recovery envelope.
	 *
	 * Recovery state is persisted before successor artifacts are materialized. If
	 * this process stops between those operations, a later exact retry can resume
	 * the same prepared token identities instead of minting a second generation.
	 *
	 * @param string              $refresh_token Source refresh token.
	 * @param array<string,mixed> $claims        Validated authorization claims.
	 * @param int                 $access_ttl    Access-token lifetime.
	 * @param int                 $refresh_ttl   Successor refresh-token lifetime.
	 * @param int                 $recovery_ttl  Retry recovery lifetime.
	 * @return array<string,mixed>|\WP_Error Recovery payload or storage error.
	 */
	public function stage_refresh_recovery( $refresh_token, array $claims, $access_ttl, $refresh_ttl, $recovery_ttl ) {
		$recovery_id  = $this->refresh_recovery_id( $refresh_token );
		$access_ttl   = max( 1, (int) $access_ttl );
		$refresh_ttl  = max( 1, (int) $refresh_ttl );
		$recovery_ttl = (int) $recovery_ttl;
		if (
			false === $recovery_id ||
			$recovery_ttl < 1 ||
			$recovery_ttl > self::REFRESH_RECOVERY_TTL_MAX ||
			! $this->refresh_lock_is_owned( $recovery_id )
		) {
			return new \WP_Error( 'oauth_refresh_recovery_unavailable', 'Refresh recovery state could not be staged safely.' );
		}
		if ( false !== get_transient( $this->refresh_recovery_key( $recovery_id ) ) ) {
			return new \WP_Error( 'oauth_refresh_recovery_exists', 'Refresh recovery state already exists.' );
		}

		$canonical_claims = $this->canonical_recovery_claims( $claims );
		if ( false === $canonical_claims ) {
			return new \WP_Error( 'oauth_refresh_recovery_claims', 'Refresh recovery claims are invalid.' );
		}

		$now                     = time();
		$access_token            = $this->generate_artifact_token( self::TYPE_ACCESS );
		$refresh_token_successor = $this->generate_artifact_token( self::TYPE_REFRESH );
		if ( false === $access_token || false === $refresh_token_successor ) {
			return new \WP_Error( 'oauth_refresh_recovery_generation', 'Refresh successor artifacts could not be prepared.' );
		}

		$payload = array(
			'version'            => self::REFRESH_RECOVERY_VERSION,
			'phase'              => 'prepared',
			'recovery_id'        => $recovery_id,
			'created_at'         => $now,
			'expires_at'         => $now + $recovery_ttl,
			'claims'             => $canonical_claims,
			'access_token'       => $access_token,
			'access_expires_at'  => $now + $access_ttl,
			'refresh_token'      => $refresh_token_successor,
			'refresh_expires_at' => $now + $refresh_ttl,
			'response'           => array(
				'access_token'  => $access_token,
				'token_type'    => 'Bearer',
				'expires_in'    => $access_ttl,
				'scope'         => (string) $canonical_claims['scope'],
				'refresh_token' => $refresh_token_successor,
			),
		);

		if ( ! $this->save_refresh_recovery( $payload ) ) {
			return new \WP_Error( 'oauth_refresh_recovery_storage', 'Refresh recovery state could not be stored safely.' );
		}

		if ( ! $this->materialize_refresh_recovery_artifacts( $payload ) ) {
			$this->delete_exact_artifact( self::TYPE_ACCESS, $access_token );
			$this->delete_exact_artifact( self::TYPE_REFRESH, $refresh_token_successor );
			delete_transient( $this->refresh_recovery_key( $recovery_id ) );
			return new \WP_Error( 'oauth_refresh_recovery_storage', 'Refresh successor artifacts could not be stored safely.' );
		}

		$payload['phase'] = 'committed';
		if ( ! $this->save_refresh_recovery( $payload ) ) {
			$this->delete_exact_artifact( self::TYPE_ACCESS, $access_token );
			$this->delete_exact_artifact( self::TYPE_REFRESH, $refresh_token_successor );
			delete_transient( $this->refresh_recovery_key( $recovery_id ) );
			return new \WP_Error( 'oauth_refresh_recovery_storage', 'Committed refresh recovery state could not be stored safely.' );
		}

		return $payload;
	}

	/**
	 * Resumes a staged recovery without minting new bearer identities.
	 *
	 * @param string              $refresh_token Exact source refresh token.
	 * @param array<string,mixed> $payload       Decrypted recovery payload.
	 * @return array<string,mixed>|\WP_Error Committed payload or error.
	 */
	public function resume_refresh_recovery( $refresh_token, array $payload ) {
		$recovery_id = $this->refresh_recovery_id( $refresh_token );
		if (
			false === $recovery_id ||
			! $this->refresh_lock_is_owned( $recovery_id ) ||
			! $this->valid_refresh_recovery_payload( $payload, $recovery_id ) ||
			(int) $payload['expires_at'] <= time()
		) {
			return new \WP_Error( 'oauth_refresh_recovery_invalid', 'Refresh recovery state is invalid or expired.' );
		}
		if ( 'prepared' === $payload['phase'] ) {
			if ( ! $this->materialize_refresh_recovery_artifacts( $payload ) ) {
				return new \WP_Error( 'oauth_refresh_recovery_storage', 'Prepared refresh successor artifacts could not be resumed safely.' );
			}
			$payload['phase'] = 'committed';
			if ( ! $this->save_refresh_recovery( $payload ) ) {
				return new \WP_Error( 'oauth_refresh_recovery_storage', 'Resumed refresh recovery state could not be committed safely.' );
			}
		}
		return 'committed' === $payload['phase']
			? $payload
			: new \WP_Error( 'oauth_refresh_recovery_invalid', 'Refresh recovery state has an invalid phase.' );
	}

	/**
	 * Consumes the old refresh token under the already-held recovery lock.
	 *
	 * @param string              $refresh_token       Source refresh token.
	 * @param string              $expected_client_id  Authenticated exact client.
	 * @param array<string,mixed> $expected_claims     Expected source claims.
	 * @param bool                $allow_already_used   Whether absence is valid for recovery.
	 * @return bool Successful exact consume or accepted prior consume.
	 */
	public function consume_refresh_source( $refresh_token, $expected_client_id, array $expected_claims, $allow_already_used = false ) {
		$recovery_id = $this->refresh_recovery_id( $refresh_token );
		if ( false === $recovery_id || ! $this->refresh_lock_is_owned( $recovery_id ) ) {
			return false;
		}
		$current = $this->read( self::TYPE_REFRESH, $refresh_token, false, $expected_client_id );
		if ( false === $current ) {
			return (bool) $allow_already_used;
		}
		if ( ! $this->recovery_claims_match( $current, $expected_claims ) ) {
			return false;
		}
		$consumed = $this->read( self::TYPE_REFRESH, $refresh_token, true, $expected_client_id );
		return is_array( $consumed ) && $this->recovery_claims_match( $consumed, $expected_claims );
	}

	/**
	 * Validates that the exact committed successor artifacts remain live and bound.
	 *
	 * @param array<string,mixed> $payload            Recovery payload.
	 * @param string              $expected_client_id Authenticated exact client.
	 * @return bool Successor lifecycle and bindings are current.
	 */
	public function refresh_recovery_successors_valid( array $payload, $expected_client_id ) {
		if ( ! $this->valid_refresh_recovery_payload( $payload, (string) ( $payload['recovery_id'] ?? '' ) ) || 'committed' !== $payload['phase'] ) {
			return false;
		}
		$access = $this->read( self::TYPE_ACCESS, (string) $payload['access_token'], false, $expected_client_id );
		if ( ! is_array( $access ) || ! $this->recovery_claims_match( $access, $payload['claims'] ) ) {
			return false;
		}
		$refresh = $this->read( self::TYPE_REFRESH, (string) $payload['refresh_token'], false, $expected_client_id );
		return is_array( $refresh ) && $this->recovery_claims_match( $refresh, $payload['claims'] );
	}

	/**
	 * Removes the one-shot recovery allowance while leaving successor tokens intact.
	 *
	 * @param string $refresh_token Exact source refresh token.
	 * @return bool Whether recovery state was removed.
	 */
	public function consume_refresh_recovery( $refresh_token ) {
		$recovery_id = $this->refresh_recovery_id( $refresh_token );
		if ( false === $recovery_id || ! $this->refresh_lock_is_owned( $recovery_id ) ) {
			return false;
		}
		$key = $this->refresh_recovery_key( $recovery_id );
		delete_transient( $key );
		return false === get_transient( $key );
	}

	/**
	 * Cancels recovery and optionally removes the prepared/committed successors.
	 *
	 * @param string                   $refresh_token Exact source refresh token.
	 * @param array<string,mixed>|null $payload       Optional recovery payload.
	 * @param bool                     $remove_tokens Whether exact successors must be removed.
	 * @return void
	 */
	public function cancel_refresh_recovery( $refresh_token, $payload = null, $remove_tokens = false ) {
		$recovery_id = $this->refresh_recovery_id( $refresh_token );
		if ( false === $recovery_id || ! $this->refresh_lock_is_owned( $recovery_id ) ) {
			return;
		}
		if ( $remove_tokens && is_array( $payload ) ) {
			$this->delete_exact_artifact( self::TYPE_ACCESS, (string) ( $payload['access_token'] ?? '' ) );
			$this->delete_exact_artifact( self::TYPE_REFRESH, (string) ( $payload['refresh_token'] ?? '' ) );
		}
		delete_transient( $this->refresh_recovery_key( $recovery_id ) );
	}

	/**
	 * Atomically claims one signed client-assertion JWT ID for its short lifetime.
	 *
	 * Historical ChatGPT assertions keep the legacy empty namespace so replay
	 * claims created immediately before an upgrade remain effective. Additional
	 * clients use their exact client ID as a namespace so equal jti values cannot
	 * collide across independently operated clients.
	 *
	 * The validator historically saturates its requested replay lifetime at 600
	 * seconds even though clock skew can keep a maximum-lifetime assertion valid
	 * for another 60 seconds. A saturated request therefore retains the marker for
	 * the complete 660-second acceptance window instead of becoming reclaimable
	 * while the same signed assertion can still pass validation.
	 *
	 * Existing replay options are never reclaimed synchronously, even when their
	 * stored expiry has elapsed. Their scheduled cleanup event owns removal. This
	 * preserves the final skew window for markers created by an older Bridge build
	 * that stored a 600-second expiry before this retention fix was installed.
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
		$ttl = max( 1, (int) $ttl );
		if ( $ttl >= self::CLIENT_ASSERTION_REPLAY_TTL_CAP ) {
			$ttl = self::CLIENT_ASSERTION_REPLAY_TTL_CAP + self::CLIENT_ASSERTION_REPLAY_SKEW;
		}
		$expires_at = time() + $ttl;
		$material   = '' === $client_id ? $jti : $client_id . "\0" . $jti;
		$key        = 'wpai_oauth_assertion_' . substr( hash( 'sha256', $material ), 0, 40 );
		if ( ! add_option( $key, $expires_at, '', false ) ) {
			return false;
		}
		wp_schedule_single_event( $expires_at + MINUTE_IN_SECONDS, 'wpai_oauth_cleanup_client_assertion', array( $key, $expires_at ) );
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
		if ( ! is_string( $key ) || 1 !== preg_match( '/^wpai_oauth_assertion_[a-f0-9]{40}$/', $key ) ) {
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
	 * Refresh artifacts share the same per-token lock as rotation. Successor access/
	 * refresh artifacts also cancel any still-live recovery allowance that could
	 * otherwise replay the just-revoked token in a previously committed response.
	 *
	 * @param string $token              Opaque artifact.
	 * @param string $expected_client_id Optional authenticated client binding.
	 * @return bool Whether a valid artifact or pending recovery was revoked.
	 */
	public function revoke( $token, $expected_client_id = '' ) {
		if ( ! is_string( $expected_client_id ) || strlen( $expected_client_id ) > 256 ) {
			return false;
		}

		foreach ( array( self::TYPE_CONSENT, self::TYPE_CODE, self::TYPE_ACCESS, self::TYPE_REFRESH ) as $type ) {
			$parsed = $this->parse( $type, $token );
			if ( false === $parsed ) {
				continue;
			}
			list( $selector, $secret ) = $parsed;
			$key                       = $this->transient_key( $type, $selector );
			$claims                    = get_transient( $key );

			if ( ! is_array( $claims ) || empty( $claims['secret_hash'] ) ) {
				if ( self::TYPE_REFRESH !== $type ) {
					return false;
				}
				$source_recovery_id = $this->refresh_recovery_id( $token );
				if ( false === $source_recovery_id || ! $this->acquire_refresh_lock_id( $source_recovery_id ) ) {
					return false;
				}
				try {
					$payload = $this->read_refresh_recovery( $token );
					if ( ! is_array( $payload ) || ! $this->recovery_client_matches( $payload, $expected_client_id ) ) {
						return false;
					}
					$this->cancel_refresh_recovery( $token, $payload, true );
					return false === get_transient( $this->refresh_recovery_key( $source_recovery_id ) );
				} finally {
					$this->release_refresh_lock_id( $source_recovery_id );
				}
			}

			if ( ! hash_equals( (string) $claims['secret_hash'], $this->hash_secret( $secret ) ) ) {
				return false;
			}
			if ( '' !== $expected_client_id && ( empty( $claims['client_id'] ) || ! hash_equals( $expected_client_id, (string) $claims['client_id'] ) ) ) {
				return false;
			}

			$lock_ids = array();
			if ( self::TYPE_REFRESH === $type ) {
				$source_recovery_id = $this->refresh_recovery_id( $token );
				if ( false === $source_recovery_id ) {
					return false;
				}
				$lock_ids[] = $source_recovery_id;
			}
			if ( ! empty( $claims[ self::REFRESH_RECOVERY_CLAIM ] ) ) {
				$parent_recovery_id = (string) $claims[ self::REFRESH_RECOVERY_CLAIM ];
				if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $parent_recovery_id ) ) {
					return false;
				}
				$lock_ids[] = $parent_recovery_id;
			}

			$acquired = $this->acquire_refresh_lock_ids( $lock_ids );
			if ( false === $acquired ) {
				return false;
			}
			try {
				$current = get_transient( $key );
				if ( ! is_array( $current ) || empty( $current['secret_hash'] ) || ! hash_equals( (string) $current['secret_hash'], $this->hash_secret( $secret ) ) ) {
					return false;
				}
				if ( '' !== $expected_client_id && ( empty( $current['client_id'] ) || ! hash_equals( $expected_client_id, (string) $current['client_id'] ) ) ) {
					return false;
				}
				if ( ! empty( $current[ self::REFRESH_RECOVERY_CLAIM ] ) ) {
					$parent_recovery_id = (string) $current[ self::REFRESH_RECOVERY_CLAIM ];
					$payload            = $this->read_refresh_recovery_by_id( $parent_recovery_id );
					if ( is_array( $payload ) && ! $this->recovery_client_matches( $payload, $expected_client_id ) ) {
						return false;
					}
					delete_transient( $this->refresh_recovery_key( $parent_recovery_id ) );
				}
				return delete_transient( $key ) || false === get_transient( $key );
			} finally {
				$this->release_refresh_lock_ids( $acquired );
			}
		}
		return false;
	}

	/**
	 * Returns the keyed identity for one exact source refresh token.
	 *
	 * @param string $refresh_token Refresh token.
	 * @return string|false Recovery identity or false.
	 */
	private function refresh_recovery_id( $refresh_token ) {
		if ( false === $this->parse( self::TYPE_REFRESH, $refresh_token ) ) {
			return false;
		}
		return substr( hash_hmac( 'sha256', "wpai-refresh-recovery\0" . $refresh_token, wp_salt( 'auth' ) ), 0, 40 );
	}

	/** @param string $recovery_id Recovery identity. @return string Transient key. */
	private function refresh_recovery_key( $recovery_id ) {
		return 1 === preg_match( '/^[a-f0-9]{40}$/', (string) $recovery_id ) ? self::REFRESH_RECOVERY_PREFIX . $recovery_id : '';
	}

	/** @param string $recovery_id Recovery identity. @return string Advisory-lock name. */
	private function refresh_lock_name( $recovery_id ) {
		return 1 === preg_match( '/^[a-f0-9]{40}$/', (string) $recovery_id ) ? 'wpai_rr_' . $recovery_id : '';
	}

	/** @param string $recovery_id Recovery identity. @return bool Whether acquired. */
	private function acquire_refresh_lock_id( $recovery_id ) {
		global $wpdb;
		$name = $this->refresh_lock_name( $recovery_id );
		if ( '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL/MariaDB advisory lock is the cross-request refresh serialization primitive.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::REFRESH_LOCK_TIMEOUT ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared and no identifier is interpolated.
		);
		return 1 === (int) $result;
	}

	/** @param string $recovery_id Recovery identity. @return void */
	private function release_refresh_lock_id( $recovery_id ) {
		global $wpdb;
		$name = $this->refresh_lock_name( $recovery_id );
		if ( '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the request-owned advisory lock.
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Value is prepared.
		);
	}

	/** @param string $recovery_id Recovery identity. @return bool Whether current DB session owns it. */
	private function refresh_lock_is_owned( $recovery_id ) {
		global $wpdb;
		$name = $this->refresh_lock_name( $recovery_id );
		if ( '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$owner   = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory-lock ownership must be tied to this exact DB session.
			$wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Value is prepared.
		);
		$current = $wpdb->get_var( 'SELECT CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed diagnostic query for the current DB session.
		return (int) $owner > 0 && (int) $owner === (int) $current;
	}

	/**
	 * Acquires zero or more recovery locks in deterministic order.
	 *
	 * @param array<int,string> $recovery_ids Recovery identities.
	 * @return array<int,string>|false Locks acquired by this call, or false.
	 */
	private function acquire_refresh_lock_ids( array $recovery_ids ) {
		$recovery_ids = array_values( array_unique( array_filter( $recovery_ids, 'is_string' ) ) );
		sort( $recovery_ids, SORT_STRING );
		$acquired = array();
		foreach ( $recovery_ids as $recovery_id ) {
			if ( $this->refresh_lock_is_owned( $recovery_id ) ) {
				continue;
			}
			if ( ! $this->acquire_refresh_lock_id( $recovery_id ) ) {
				$this->release_refresh_lock_ids( $acquired );
				return false;
			}
			$acquired[] = $recovery_id;
		}
		return $acquired;
	}

	/** @param array<int,string> $recovery_ids Recovery identities. @return void */
	private function release_refresh_lock_ids( array $recovery_ids ) {
		foreach ( array_reverse( $recovery_ids ) as $recovery_id ) {
			$this->release_refresh_lock_id( $recovery_id );
		}
	}

	/**
	 * Returns only the claims that define the OAuth authorization binding.
	 *
	 * @param array<string,mixed> $claims Candidate claims.
	 * @return array<string,mixed>|false Canonical claims or false.
	 */
	private function canonical_recovery_claims( array $claims ) {
		$user_id   = isset( $claims['user_id'] ) ? (int) $claims['user_id'] : 0;
		$client_id = isset( $claims['client_id'] ) && is_string( $claims['client_id'] ) ? $claims['client_id'] : '';
		$resource  = isset( $claims['resource'] ) && is_string( $claims['resource'] ) ? $claims['resource'] : '';
		$scope     = isset( $claims['scope'] ) && is_string( $claims['scope'] ) ? $claims['scope'] : '';
		if ( $user_id < 1 || '' === $client_id || strlen( $client_id ) > 256 || '' === $resource || strlen( $resource ) > 1024 || '' === $scope || strlen( $scope ) > 256 ) {
			return false;
		}
		$canonical = array(
			'user_id'   => $user_id,
			'client_id' => $client_id,
			'resource'  => $resource,
			'scope'     => $scope,
		);
		if ( array_key_exists( 'client_revision', $claims ) ) {
			$canonical['client_revision'] = (int) $claims['client_revision'];
		}
		return $canonical;
	}

	/** @param array<string,mixed> $left First claims. @param array<string,mixed> $right Second claims. @return bool Exact canonical match. */
	private function recovery_claims_match( array $left, array $right ) {
		$left  = $this->canonical_recovery_claims( $left );
		$right = $this->canonical_recovery_claims( $right );
		return is_array( $left ) && is_array( $right ) && $left === $right;
	}

	/** @param string $type Artifact type. @return string|false Fresh opaque token or false. */
	private function generate_artifact_token( $type ) {
		$prefix = $this->token_prefix( $type );
		if ( '' === $prefix ) {
			return false;
		}
		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$selector = $this->random_urlsafe( 18 );
			$secret   = $this->random_urlsafe( 32 );
			if ( false === get_transient( $this->transient_key( $type, $selector ) ) ) {
				return $prefix . '.' . $selector . '.' . $secret;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $payload Recovery payload. @return bool Whether safely stored. */
	private function save_refresh_recovery( array $payload ) {
		$recovery_id = isset( $payload['recovery_id'] ) ? (string) $payload['recovery_id'] : '';
		if ( ! $this->valid_refresh_recovery_payload( $payload, $recovery_id ) ) {
			return false;
		}
		$remaining = (int) $payload['expires_at'] - time();
		if ( $remaining < 1 || $remaining > self::REFRESH_RECOVERY_TTL_MAX ) {
			return false;
		}
		$envelope = $this->encrypt_refresh_recovery( $recovery_id, $payload );
		if ( false === $envelope ) {
			return false;
		}
		$key = $this->refresh_recovery_key( $recovery_id );
		set_transient( $key, $envelope, $remaining );
		$stored = get_transient( $key );
		$check  = false !== $stored ? $this->decrypt_refresh_recovery( $recovery_id, $stored ) : false;
		return is_array( $check ) && $this->refresh_recovery_payloads_match( $payload, $check );
	}

	/** @param array<string,mixed> $payload Recovery payload. @return bool Whether exact successors exist. */
	private function materialize_refresh_recovery_artifacts( array $payload ) {
		if ( ! $this->valid_refresh_recovery_payload( $payload, (string) ( $payload['recovery_id'] ?? '' ) ) ) {
			return false;
		}
		$claims                                 = $payload['claims'];
		$claims[ self::REFRESH_RECOVERY_CLAIM ] = (string) $payload['recovery_id'];
		return $this->persist_exact_artifact( self::TYPE_ACCESS, (string) $payload['access_token'], $claims, (int) $payload['access_expires_at'] )
			&& $this->persist_exact_artifact( self::TYPE_REFRESH, (string) $payload['refresh_token'], $claims, (int) $payload['refresh_expires_at'] );
	}

	/**
	 * Persists one pre-generated artifact idempotently and verifies exact state.
	 *
	 * @param string              $type       Artifact type.
	 * @param string              $token      Pre-generated opaque token.
	 * @param array<string,mixed> $claims     Claims including recovery identity.
	 * @param int                 $expires_at Absolute expiry.
	 * @return bool Whether exact state exists after the write.
	 */
	private function persist_exact_artifact( $type, $token, array $claims, $expires_at ) {
		$parsed = $this->parse( $type, $token );
		$ttl    = (int) $expires_at - time();
		if ( false === $parsed || $ttl < 1 ) {
			return false;
		}
		list( $selector, $secret ) = $parsed;
		$record                    = $claims;
		$record['secret_hash']     = $this->hash_secret( $secret );
		$record['expires_at']      = (int) $expires_at;
		$record['instance_id']     = $this->instance_id();
		$key                       = $this->transient_key( $type, $selector );
		$existing                  = get_transient( $key );
		if ( is_array( $existing ) ) {
			return $this->artifact_records_match( $existing, $record );
		}
		set_transient( $key, $record, $ttl );
		$stored = get_transient( $key );
		return is_array( $stored ) && $this->artifact_records_match( $stored, $record );
	}

	/** @param array<string,mixed> $left First record. @param array<string,mixed> $right Second record. @return bool Exact security-relevant match. */
	private function artifact_records_match( array $left, array $right ) {
		foreach ( array( 'secret_hash', 'instance_id', self::REFRESH_RECOVERY_CLAIM ) as $key ) {
			if ( empty( $left[ $key ] ) || empty( $right[ $key ] ) || ! hash_equals( (string) $left[ $key ], (string) $right[ $key ] ) ) {
				return false;
			}
		}
		if ( (int) ( $left['expires_at'] ?? 0 ) !== (int) ( $right['expires_at'] ?? 0 ) ) {
			return false;
		}
		return $this->recovery_claims_match( $left, $right );
	}

	/** @param string $type Artifact type. @param string $token Opaque token. @return bool Whether exact artifact is absent after deletion. */
	private function delete_exact_artifact( $type, $token ) {
		$parsed = $this->parse( $type, $token );
		if ( false === $parsed ) {
			return false;
		}
		list( $selector, $secret ) = $parsed;
		$key                       = $this->transient_key( $type, $selector );
		$record                    = get_transient( $key );
		if ( false === $record ) {
			return true;
		}
		if ( ! is_array( $record ) || empty( $record['secret_hash'] ) || ! hash_equals( (string) $record['secret_hash'], $this->hash_secret( $secret ) ) ) {
			return false;
		}
		delete_transient( $key );
		return false === get_transient( $key );
	}

	/** @param array<string,mixed> $payload Recovery payload. @param string $recovery_id Expected recovery identity. @return bool Structural validity. */
	private function valid_refresh_recovery_payload( array $payload, $recovery_id ) {
		if (
			self::REFRESH_RECOVERY_VERSION !== (int) ( $payload['version'] ?? 0 ) ||
			! in_array( $payload['phase'] ?? '', array( 'prepared', 'committed' ), true ) ||
			1 !== preg_match( '/^[a-f0-9]{40}$/', (string) $recovery_id ) ||
			! isset( $payload['recovery_id'] ) ||
			! hash_equals( (string) $recovery_id, (string) $payload['recovery_id'] ) ||
			! isset( $payload['created_at'], $payload['expires_at'], $payload['access_expires_at'], $payload['refresh_expires_at'] ) ||
			(int) $payload['created_at'] < 1 ||
			(int) $payload['expires_at'] <= (int) $payload['created_at'] ||
			(int) $payload['expires_at'] - (int) $payload['created_at'] > self::REFRESH_RECOVERY_TTL_MAX ||
			(int) $payload['access_expires_at'] <= (int) $payload['created_at'] ||
			(int) $payload['refresh_expires_at'] <= (int) $payload['created_at'] ||
			! isset( $payload['claims'] ) || ! is_array( $payload['claims'] ) ||
			false === $this->canonical_recovery_claims( $payload['claims'] ) ||
			! isset( $payload['access_token'], $payload['refresh_token'] ) ||
			false === $this->parse( self::TYPE_ACCESS, $payload['access_token'] ) ||
			false === $this->parse( self::TYPE_REFRESH, $payload['refresh_token'] ) ||
			! isset( $payload['response'] ) || ! is_array( $payload['response'] )
		) {
			return false;
		}
		$response = $payload['response'];
		return isset( $response['access_token'], $response['refresh_token'], $response['token_type'], $response['expires_in'], $response['scope'] )
			&& is_string( $response['access_token'] )
			&& is_string( $response['refresh_token'] )
			&& 'Bearer' === $response['token_type']
			&& (int) $response['expires_in'] > 0
			&& hash_equals( (string) $payload['access_token'], $response['access_token'] )
			&& hash_equals( (string) $payload['refresh_token'], $response['refresh_token'] )
			&& hash_equals( (string) $payload['claims']['scope'], (string) $response['scope'] );
	}

	/** @param array<string,mixed> $left First payload. @param array<string,mixed> $right Second payload. @return bool Exact immutable payload match. */
	private function refresh_recovery_payloads_match( array $left, array $right ) {
		if ( ! $this->valid_refresh_recovery_payload( $left, (string) ( $left['recovery_id'] ?? '' ) ) || ! $this->valid_refresh_recovery_payload( $right, (string) ( $right['recovery_id'] ?? '' ) ) ) {
			return false;
		}
		return $left === $right;
	}

	/** @param string $recovery_id Recovery identity. @param array<string,mixed> $payload Plain recovery payload. @return array<string,mixed>|false Encrypted envelope. */
	private function encrypt_refresh_recovery( $recovery_id, array $payload ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > 16384 ) {
			return false;
		}
		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $json, 'aes-256-gcm', $this->refresh_recovery_cipher_key(), OPENSSL_RAW_DATA, $iv, $tag, $this->refresh_recovery_aad( $recovery_id ), 16 );
		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return false;
		}
		return array(
			'version'    => self::REFRESH_RECOVERY_VERSION,
			'cipher'     => 'aes-256-gcm',
			'iv'         => base64_encode( $iv ),
			'tag'        => base64_encode( $tag ),
			'ciphertext' => base64_encode( $ciphertext ),
		);
	}

	/** @param string $recovery_id Recovery identity. @param mixed $envelope Stored envelope. @return array<string,mixed>|false Decrypted payload. */
	private function decrypt_refresh_recovery( $recovery_id, $envelope ) {
		if ( ! function_exists( 'openssl_decrypt' ) || ! is_array( $envelope ) ) {
			return false;
		}
		if (
			self::REFRESH_RECOVERY_VERSION !== (int) ( $envelope['version'] ?? 0 ) ||
			'aes-256-gcm' !== ( $envelope['cipher'] ?? '' ) ||
			! isset( $envelope['iv'], $envelope['tag'], $envelope['ciphertext'] ) ||
			! is_string( $envelope['iv'] ) || ! is_string( $envelope['tag'] ) || ! is_string( $envelope['ciphertext'] ) ||
			strlen( $envelope['ciphertext'] ) > 24576
		) {
			return false;
		}
		$iv         = base64_decode( $envelope['iv'], true );
		$tag        = base64_decode( $envelope['tag'], true );
		$ciphertext = base64_decode( $envelope['ciphertext'], true );
		if ( false === $iv || false === $tag || false === $ciphertext || 12 !== strlen( $iv ) || 16 !== strlen( $tag ) || strlen( $ciphertext ) > 16384 ) {
			return false;
		}
		$json = openssl_decrypt( $ciphertext, 'aes-256-gcm', $this->refresh_recovery_cipher_key(), OPENSSL_RAW_DATA, $iv, $tag, $this->refresh_recovery_aad( $recovery_id ) );
		if ( false === $json || strlen( $json ) > 16384 ) {
			return false;
		}
		$payload = json_decode( $json, true );
		return is_array( $payload ) && JSON_ERROR_NONE === json_last_error() ? $payload : false;
	}

	/** @return string Binary AES-256 recovery key. */
	private function refresh_recovery_cipher_key() {
		return hash_hmac( 'sha256', "wpai-refresh-recovery-key-v1\0" . $this->instance_id(), wp_salt( 'auth' ), true );
	}

	/** @param string $recovery_id Recovery identity. @return string Authenticated additional data. */
	private function refresh_recovery_aad( $recovery_id ) {
		return "wpai-refresh-recovery-aad-v1\0" . $this->instance_id() . "\0" . (string) $recovery_id;
	}

	/** @param string $recovery_id Recovery identity. @return array<string,mixed>|false Valid payload. */
	private function read_refresh_recovery_by_id( $recovery_id ) {
		$key = $this->refresh_recovery_key( $recovery_id );
		if ( '' === $key ) {
			return false;
		}
		$envelope = get_transient( $key );
		if ( false === $envelope ) {
			return false;
		}
		$payload = $this->decrypt_refresh_recovery( $recovery_id, $envelope );
		if ( ! is_array( $payload ) || ! $this->valid_refresh_recovery_payload( $payload, $recovery_id ) || (int) $payload['expires_at'] <= time() ) {
			delete_transient( $key );
			return false;
		}
		return $payload;
	}

	/** @param array<string,mixed> $payload Recovery payload. @param string $expected_client_id Expected client or empty. @return bool Client binding match. */
	private function recovery_client_matches( array $payload, $expected_client_id ) {
		$client_id = isset( $payload['claims']['client_id'] ) ? (string) $payload['claims']['client_id'] : '';
		return '' !== $client_id && ( '' === $expected_client_id || hash_equals( $expected_client_id, $client_id ) );
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
			self::TYPE_CONSENT => 'wpai_q',
			self::TYPE_CODE    => 'wpai_c',
			self::TYPE_ACCESS  => 'wpai_a',
			self::TYPE_REFRESH => 'wpai_r',
		);
		return isset( $prefixes[ $type ] ) ? $prefixes[ $type ] : '';
	}

	/** @param string $type Artifact type. @param string $selector Selector. @return string Transient key. */
	private function transient_key( $type, $selector ) {
		return 'wpai_oauth_' . sanitize_key( $type ) . '_' . $selector;
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
