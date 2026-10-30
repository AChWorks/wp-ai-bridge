<?php
/**
 * Internal-only, resumable, opaque byte ingress outside the WordPress webroot.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/**
 * Per-site/principal/client sessions. Not a public Ability or file URL.
 *
 * The caller owns finite resource budgets and future consumer authorization.
 * This class stores bytes but NEVER imports media/WXR, edits posts, executes an
 * archive or exposes a client-selectable filesystem path. No daemon required.
 */
final class Private_Transfer_Session {
	/** @var Permissions */
	private $permissions;

	/** @var bool Fixed DB-only authority; generic transfer callers never acquire it. */
	private $database_only = false;

	/** @var string|null Fixed native scope; null preserves generic transport. */
	private $native_options_scope = null;

	/** @var array<string,array> Exact original proofs of settings leases created by this instance. */
	private $native_created_roots = array();

	/** @param Permissions $permissions Bridge current principal policy. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/**
	 * Internal fixed-purpose factory. Database use does not inherit or grant any
	 * generic media, content, source-editing or MCP transfer authority.
	 *
	 * @param Permissions $permissions Current database delegation.
	 * @return self
	 */
	public static function for_database( Permissions $permissions ) {
		$store                = new self( $permissions );
		$store->database_only = true;
		return $store;
	}

	/**
	 * Internal native settings factory. An invalid scope never gains generic
	 * transfer authority. Network and site purposes remain distinct.
	 *
	 * @param Permissions $permissions Current native read delegation.
	 * @param string      $scope Site or network.
	 * @return self
	 */
	public static function for_native_options( Permissions $permissions, string $scope ) {
		$store                       = new self( $permissions );
		$store->native_options_scope = $scope;
		return $store;
	}

	/** @return array<string,mixed> Current native snapshot principal and source. */
	private function native_binding() {
		return array(
			'blog'      => (int) get_current_blog_id(),
			'user'      => (int) get_current_user_id(),
			'client'    => OAuth_Server::authenticated_mcp_client_id(),
			'source_id' => 'network' === $this->native_options_scope ? (int) get_current_network_id() : (int) get_current_blog_id(),
		);
	}

	/**
	 * @param string $purpose Fixed authorized consumer purpose.
	 * @param int    $total Exact source byte count including zero.
	 * @param string $sha256 Lowercase expected SHA-256.
	 * @param int    $max_total Explicit operator/caller file budget.
	 * @param int    $max_chunk Explicit maximum raw bytes per continuation.
	 * @param int    $active_limit Maximum reserved concurrent sessions for a site.
	 * @param int    $ttl_seconds Finite retention in seconds.
	 * @return array<string,mixed>|WP_Error No private path in return.
	 */
	public function begin( $purpose, $total, $sha256, $max_total, $max_chunk, $active_limit, $ttl_seconds, $max_active = null ) {
		$native_binding = null !== $this->native_options_scope ? $this->native_binding() : null;
		if ( ! $this->allowed( $purpose ) ||
			( null !== $native_binding && $native_binding !== $this->native_binding() ) ) {
			return $this->denied();
		}
		if ( null === $max_active && is_int( $max_total ) && is_int( $active_limit ) && $max_total > 0 && $active_limit > 0 ) {
			// Preserve the earlier internal callers' reservation policy without overflowing.
			$max_active = $active_limit > intdiv( PHP_INT_MAX, $max_total ) ? PHP_INT_MAX : $active_limit * $max_total;
		}
		if (
			! is_int( $max_active ) || $max_active < 1 ||
			! is_int( $total ) || $total < 0 || $total > $max_active || ! is_int( $max_total ) || $max_total < 1 ||
			$total > $max_total || ! is_int( $max_chunk ) || $max_chunk < 1 || $max_chunk > $max_total ||
			! is_int( $active_limit ) || $active_limit < 1 ||
			! is_int( $ttl_seconds ) || $ttl_seconds < 60 || $ttl_seconds > PHP_INT_MAX - time() ||
			! is_string( $sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 )
		) {
			return $this->invalid();
		}
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$guard = $this->guard( $root );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		try {
			$used    = 0;
			$pending = 0;
			$count   = 0;
			// Iterate bounded-memory over private metadata; no fixed 256-entry
			// product ceiling or full-directory glob allocation. Concurrency
			// and reserved-byte limits are administrator-owned.
			try {
				$entries = new \FilesystemIterator( $root, \FilesystemIterator::SKIP_DOTS );
				foreach ( $entries as $entry ) {
					$name = $entry->getFilename();
					if ( preg_match( '/^([a-f0-9]{64})\\.claim\\.pending\\.[a-f0-9]{16}$/D', $name, $found ) ) {
						$this->reconcile_unpublished_claim( $root, $found[1], $entry );
						continue;
					}
					if ( ! preg_match( '/^[a-f0-9]{64}\\.meta$/D', $name ) ) {
						continue;
					}
					$id = substr( $name, 0, 64 );
					if ( file_exists( $root . '/' . $id . '.tombstone' ) ) {
						if ( ! $this->erase( $root, $id ) ) {
							return new WP_Error( 'transfer_cleanup_uncertain', 'Previously cancelled private lease cleanup requires attention.' );
						}
						continue;
					}
					$info = $this->admission_meta( $root, $id );
					if ( is_wp_error( $info ) ) {
						return $info;
					}
					list( $handle, $meta ) = $info;
					if ( is_resource( $handle ) && ! $this->recover_scratch( $root, $id, $handle, $meta ) ) {
						$this->close_meta( $handle );
						return new WP_Error( 'transfer_cleanup_uncertain', 'Private scratch could not be reconciled before capacity admission.' );
					}
					if ( is_resource( $handle ) && $meta['expires'] <= time() && ! in_array( $meta['state'], array( 'committing', 'observed' ), true ) ) {
						if ( ! $this->erase( $root, $id ) ) {
							$this->close_meta( $handle );
							return new WP_Error( 'transfer_cleanup_uncertain', 'Expired private transfer bytes could not be fully deleted. Manual cleanup is required.' );
						}
					} elseif ( empty( $meta['payload_released'] ) ) {
						++$count;
						// Persisted, bounded scratch counts against the same
						// aggregate private storage and admission policy.
						$reserved = $meta['total'] + (int) ( $meta['scratch_reserved'] ?? 0 );
						if ( $reserved < $meta['total'] || $reserved > $max_active - $used ) {
							$this->close_meta( $handle );
							return new WP_Error( 'transfer_capacity', 'Active private transfer and scratch reservation budget reached.' );
						}
						$used    += $reserved;
						$pending += $meta['total'] - $meta['received'];
					}
					$this->close_meta( $handle );
				}
			} catch ( \UnexpectedValueException $error ) {
				return new WP_Error( 'transfer_storage_unavailable', 'Private transfer metadata directory cannot be enumerated.' );
			}
			if ( $count >= $active_limit || $total > $max_active - $used ) {
				return new WP_Error( 'transfer_capacity', 'Configured concurrent private transfer reservation capacity reached.' );
			}
			// Aggregate reservation should fit the *actual* current private
			// storage, not merely an invented software-wide file ceiling.
			$free = disk_free_space( $root );
			if ( ! is_float( $free ) && ! is_int( $free ) ) {
				return new WP_Error( 'transfer_disk_unknown', 'Available private disk capacity could not be measured.' );
			}
			if ( $total > $free || $pending > $free - $total ) {
				return new WP_Error( 'transfer_disk_insufficient', 'Private temporary storage cannot accommodate the requested active transfer reservation.' );
			}

			$id   = bin2hex( random_bytes( 32 ) );
			$meta = array(
				'version'  => 1,
				'id'       => $id,
				'purpose'  => $purpose,
				'blog'     => (int) get_current_blog_id(),
				'user'     => (int) get_current_user_id(),
				'client'   => OAuth_Server::authenticated_mcp_client_id(),
				'total'    => $total,
				'chunk'    => $max_chunk,
				'received' => 0,
				'sha256'   => $sha256,
				'expires'  => time() + $ttl_seconds,
				'state'    => 'open',
			);
			if ( null !== $native_binding ) {
				// Bind at creation, before any append/status/verify can load it.
				$meta = array_merge( $meta, $native_binding );
			}
			$data     = $root . '/' . $id . '.bytes';
			$manifest = $root . '/' . $id . '.meta';
			$file     = @fopen( $data, 'x+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Randomly generated private root, exclusive creation.
			if ( false === $file ) {
				return new WP_Error( 'transfer_storage_unavailable', 'Could not allocate private transfer bytes.' );
			}
			if ( ! $this->secure_file( $file, $data ) ) {
				fclose( $file );
				$this->remove_file( $data );
				return new WP_Error( 'transfer_storage_unsafe', 'Cannot enforce private transfer file permissions.', array( 'storage_reason' => 'lease_file_mode' ) );
			}
			fclose( $file );
			$handle = @fopen( $manifest, 'x+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive fixed-format manifest in private root.
			if ( false === $handle || ! $this->secure_file( $handle, $manifest ) || ! $this->save_meta( $handle, $meta ) ) {
				if ( is_resource( $handle ) ) {
					fclose( $handle );
				}
				$this->remove_file( $manifest );
				$this->remove_file( $data );
				return new WP_Error( 'transfer_storage_unavailable', 'Could not persist the private transfer identity.' );
			}
			fclose( $handle );
			if ( null !== $native_binding ) {
				if ( ! $this->allowed( $purpose ) || $native_binding !== $this->native_binding() ) {
					return $this->erase( $root, $id ) ? $this->denied() :
						new WP_Error( 'transfer_cleanup_uncertain', 'Failed private settings creation requires cleanup.' );
				}
				$root_identity = @lstat( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A disappeared private root must not disclose its path in PHP diagnostics.
				if ( ! is_array( $root_identity ) ) {
					return new WP_Error( 'transfer_cleanup_uncertain', 'The private settings creation root became unavailable.' );
				}
				$this->native_created_roots[ $id ] = array(
					'root'    => $root,
					'device'  => $root_identity['dev'],
					'inode'   => $root_identity['ino'],
					'binding' => $native_binding,
					'sha256'  => $sha256,
					'total'   => $total,
				);
			}
			return $this->receipt( $meta );
		} finally {
			flock( $guard, LOCK_UN );
			fclose( $guard );
		}
	}

	/** @param string $id Exact opaque transfer ID. @return array<string,mixed>|WP_Error */
	public function status( $id ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		if ( 'media_read' === $meta['purpose'] && ! empty( $meta['source_id'] ) &&
			( 'attachment' !== get_post_type( $meta['source_id'] ) ||
				! current_user_can( 'read_post', $meta['source_id'] ) ) ) {
			$this->close_meta( $handle );
			return $this->denied();
		}
		$result = ! empty( $meta['payload_released'] ) ? true : $this->check_file( $root, $id, $meta );
		$this->close_meta( $handle );
		return is_wp_error( $result ) ? $result : $this->receipt( $meta );
	}

	/**
	 * Append an opaque raw binary chunk with exact offsets and safe replay.
	 *
	 * @param string $id Exact opaque transfer identity.
	 * @param int $offset Absolute byte offset, must match receipt.
	 * @param string $bytes Opaque binary chunk, within caller-supplied bound.
	 * @return array<string,mixed>|WP_Error
	 */
	public function append( $id, $offset, $bytes ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( 'open' !== $meta['state'] || ! is_int( $offset ) || $offset < 0 || ! is_string( $bytes ) ||
				'' === $bytes || strlen( $bytes ) > $meta['chunk'] ||
				$offset > $meta['received'] || strlen( $bytes ) > $meta['total'] - $offset ) {
				return $this->invalid();
			}
			$path = $root . '/' . $id . '.bytes';
			$file = @fopen( $path, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Fixed private session file validated against its inode/size.
			if ( false === $file ) {
				return new WP_Error( 'transfer_storage_uncertain', 'Private transfer bytes are unavailable.' );
			}
			try {
				if ( ! $this->secure_file( $file, $path ) || ! $this->file_length( $file, $meta['received'] ) ) {
					return new WP_Error( 'transfer_storage_uncertain', 'Private transfer bytes do not match the committed receipt.' );
				}
				if ( $offset < $meta['received'] ) {
					if ( strlen( $bytes ) > $meta['received'] - $offset || 0 !== fseek( $file, $offset ) ||
						fread( $file, strlen( $bytes ) ) !== $bytes ) {
						return new WP_Error( 'transfer_conflict', 'Overlapping or changed retry bytes do not match the committed source.' );
					}
					return $this->receipt( $meta );
				}
				if ( 0 !== fseek( $file, $offset ) ) {
					return new WP_Error( 'transfer_storage_uncertain', 'Private transfer seek failed before writing.' );
				}
				$cursor = 0;
				$length = strlen( $bytes );
				while ( $cursor < $length ) {
					$written = fwrite( $file, substr( $bytes, $cursor ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded owner-only source file, append at a checked offset.
					if ( false === $written || 0 === $written ) {
						return new WP_Error( 'transfer_outcome_unknown', 'Private transfer write was interrupted; inspect status before any retry.' );
					}
					$cursor += $written;
				}
				if ( ! fflush( $file ) || ! $this->file_length( $file, $offset + $length ) ) {
					return new WP_Error( 'transfer_outcome_unknown', 'Private transfer flush or length could not be verified.' );
				}
				$meta['received'] += $length;
				if ( ! $this->save_meta( $handle, $meta ) ) {
					return new WP_Error( 'transfer_outcome_unknown', 'Private transfer receipt may not have been committed.' );
				}
				return $this->receipt( $meta );
			} finally {
				fclose( $file );
			}
		} finally {
			$this->close_meta( $handle );
		}
	}

	/** @param string $id Opaque session. @return array<string,mixed>|WP_Error */
	public function verify( $id ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			// The verification state machine is monotonic. A digest check is
			// not permission to reopen a claimed/committed native effect.
			if ( 'open' !== $meta['state'] && 'verified' !== $meta['state'] ) {
				return new WP_Error( 'transfer_consumed', 'This private lease is preparing or already claimed. Verification cannot reopen a WordPress effect.' );
			}
			$path = $this->check_file( $root, $id, $meta );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
			if ( $meta['total'] !== $meta['received'] ) {
				return new WP_Error( 'transfer_incomplete', 'The staged private transfer is incomplete.' );
			}
			$digest = hash_file( 'sha256', $path );
			if ( ! is_string( $digest ) || ! hash_equals( $meta['sha256'], $digest ) ) {
				return new WP_Error( 'transfer_checksum_mismatch', 'Private transfer data did not match the declared SHA-256.' );
			}
			if ( 'verified' === $meta['state'] ) {
				return $this->receipt( $meta );
			}
			$meta['state'] = 'verified';
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Verification completed but the receipt could not be committed.' );
			}
			return $this->receipt( $meta );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/**
	 * Internal consumer only. NEVER return this result from public Abilities.
	 *
	 * @param string $id Exact opaque session.
	 * @param string $purpose The caller's authorized consumer.
	 * @return array<string,mixed>|WP_Error
	 */
	public function verified_private_source( $id, $purpose ) {
		if ( null !== $this->native_options_scope ) {
			return $this->denied();
		}
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( 'verified' !== $meta['state'] || $purpose !== $meta['purpose'] || ! $this->allowed( $purpose ) ) {
				return $this->denied();
			}
			$path = $this->check_file( $root, $id, $meta );
			if ( is_wp_error( $path ) || ! hash_equals( $meta['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				return new WP_Error( 'transfer_source_changed', 'The verified private transfer source changed or was removed.' );
			}
			return array(
				'path'    => $path,
				'bytes'   => $meta['total'],
				'sha256'  => $meta['sha256'],
				'blog_id' => $meta['blog'],
			);
		} finally {
			$this->close_meta( $handle );
		}
	}

	/**
	 * Hold verified database inputs for one synchronous consumer callback.
	 *
	 * The stable per-session coordinators remain locked until every read is
	 * finished. Append, release, cancellation and admission cleanup all respect
	 * those same locks. No claim fence is created: only the database transaction
	 * receipt owns database effects, and staging can be reused after rollback.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Exact byte identities.
	 * @param callable $callback Receives ID-indexed stream/bytes/sha256 entries.
	 * @return mixed|WP_Error The callback result, never a public source pathname.
	 */
	public function with_database_sources( array $descriptors, callable $callback ) {
		if ( ! $this->database_only || ! $this->allowed( 'database_value' ) ) {
			return $this->denied();
		}
		$unique = array();
		foreach ( $descriptors as $descriptor ) {
			if ( ! is_array( $descriptor ) || 3 !== count( $descriptor ) ||
				array_diff( array_keys( $descriptor ), array( 'transfer_id', 'total_bytes', 'sha256' ) ) ||
				! isset( $descriptor['transfer_id'], $descriptor['total_bytes'], $descriptor['sha256'] ) ||
				! is_string( $descriptor['transfer_id'] ) ||
				1 !== preg_match( '/^[a-f0-9]{64}$/D', $descriptor['transfer_id'] ) ||
				! is_int( $descriptor['total_bytes'] ) || $descriptor['total_bytes'] < 0 ||
				! is_string( $descriptor['sha256'] ) ||
				1 !== preg_match( '/^[a-f0-9]{64}$/D', $descriptor['sha256'] ) ) {
				return $this->invalid();
			}
			$id = $descriptor['transfer_id'];
			if ( isset( $unique[ $id ] ) &&
				( $unique[ $id ]['total_bytes'] !== $descriptor['total_bytes'] ||
					! hash_equals( $unique[ $id ]['sha256'], $descriptor['sha256'] ) ) ) {
				return $this->invalid();
			}
			$unique[ $id ] = $descriptor;
		}
		// Acquire each coordinator exactly once, in a stable order. Never hold
		// the shared admission guard or recursively acquire a lease coordinator.
		ksort( $unique, SORT_STRING );
		$handles = array();
		$sources = array();
		try {
			foreach ( $unique as $id => $descriptor ) {
				$loaded = $this->load( $id, false, true );
				if ( is_wp_error( $loaded ) ) {
					return $loaded;
				}
				list( $root, $handle, $meta ) = $loaded;
				$handles[ $id ]               = $handle;
				if ( 'database_value' !== $meta['purpose'] || 'verified' !== $meta['state'] ||
					! empty( $meta['payload_released'] ) ||
					$meta['total'] !== $meta['received'] ||
					$meta['total'] !== $descriptor['total_bytes'] ||
					! hash_equals( $meta['sha256'], $descriptor['sha256'] ) ) {
					return new WP_Error( 'transfer_source_changed', __( 'The database input must match its complete verified transfer identity.', 'wp-ai-bridge' ) );
				}
				$path   = $root . '/' . $id . '.bytes';
				$stream = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Fixed private source, pinned by its stable session coordinator.
				if ( ! is_resource( $stream ) ) {
					return new WP_Error( 'transfer_source_changed', __( 'The verified database input is unavailable.', 'wp-ai-bridge' ) );
				}
				$sources[ $id ] = array(
					'stream' => $stream,
					'bytes'  => $meta['total'],
					'sha256' => $meta['sha256'],
				);
				$hash           = hash_init( 'sha256' );
				if ( ! $this->secure_file( $stream, $path ) ||
					! $this->file_length( $stream, $meta['total'] ) ||
					hash_update_stream( $hash, $stream ) !== $meta['total'] ||
					! hash_equals( $meta['sha256'], hash_final( $hash ) ) ||
					! $this->secure_file( $stream, $path ) ||
					! $this->file_length( $stream, $meta['total'] ) || ! rewind( $stream ) ) {
					return new WP_Error( 'transfer_source_changed', __( 'The verified database input changed before streaming.', 'wp-ai-bridge' ) );
				}
			}
			// All WordPress policy, metadata and file verification is complete.
			// The callback may now own one native database transaction; this
			// class performs only resource close/unlock after callback entry.
			return $callback( $sources );
		} finally {
			foreach ( $sources as $source ) {
				if ( is_resource( $source['stream'] ) ) {
					fclose( $source['stream'] );
				}
			}
			foreach ( array_reverse( $handles, true ) as $handle ) {
				$this->close_meta( $handle );
			}
		}
	}

	/**
	 * A verified source candidate may be previewed repeatedly only against the
	 * same exact installed target and preimage; no effect has been claimed.
	 *
	 * @return true|WP_Error
	 */
	public function bind_source_preview( $id, $purpose, $target_hash, $preimage, $candidate_hash, $candidate_id ) {
		if ( ! in_array( $purpose, array( 'source_plugin', 'source_theme' ), true ) ||
			! $this->valid_source_bindings( $target_hash, $preimage, $candidate_hash, $candidate_id ) ) {
			return $this->invalid();
		}
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( 'verified' !== $meta['state'] || $purpose !== $meta['purpose'] ||
				! $this->allowed( $purpose ) || ! hash_equals( $meta['sha256'], $candidate_hash ) ) {
				return $this->denied();
			}
			$binding = array(
				'source_target'    => $target_hash,
				'source_preimage'  => $preimage,
				'source_candidate' => $candidate_hash,
				'source_preview'   => $candidate_id,
			);
			foreach ( $binding as $key => $value ) {
				if ( isset( $meta[ $key ] ) && ! hash_equals( (string) $meta[ $key ], $value ) ) {
					return new WP_Error( 'source_transfer_target_changed', 'A source lease cannot be rebound to another file or revision.' );
				}
			}
			$path = $this->check_file( $root, $id, $meta );
			if ( is_wp_error( $path ) || ! hash_equals( $candidate_hash, (string) hash_file( 'sha256', $path ) ) ) {
				return new WP_Error( 'transfer_source_changed', 'Source lease bytes changed before preview.' );
			}
			$meta = array_merge( $meta, $binding );
			return $this->save_meta( $handle, $meta ) ? true :
				new WP_Error( 'transfer_outcome_unknown', 'Source binding persistence is uncertain.' );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/** @return bool */
	private function valid_source_bindings( $target_hash, $preimage, $candidate_hash, $candidate_id ) {
		foreach ( array( $target_hash, $preimage, $candidate_hash, $candidate_id ) as $hash ) {
			if ( ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * One-way durable source effect fence; never replay a lost acknowledgement.
	 * The caller preserves the existing WordPress source CAS/recovery writer.
	 * @return true|WP_Error
	 */
	public function claim_source( $id, $purpose, $target_hash, $preimage, $candidate_hash, $candidate_id ) {
		if ( ! in_array( $purpose, array( 'source_plugin', 'source_theme' ), true ) ||
			! $this->valid_source_bindings( $target_hash, $preimage, $candidate_hash, $candidate_id ) ) {
			return $this->invalid();
		}
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( 'verified' !== $meta['state'] || $purpose !== $meta['purpose'] ||
				! $this->allowed( $purpose ) || ! hash_equals( $meta['sha256'], $candidate_hash ) ||
				! hash_equals( (string) ( $meta['source_target'] ?? '' ), $target_hash ) ||
				! hash_equals( (string) ( $meta['source_preimage'] ?? '' ), $preimage ) ||
				! hash_equals( (string) ( $meta['source_candidate'] ?? '' ), $candidate_hash ) ||
				! hash_equals( (string) ( $meta['source_preview'] ?? '' ), $candidate_id ) ) {
				return new WP_Error( 'source_transfer_target_changed', 'Source lease must match the exact previewed file and revision.' );
			}
			$path = $this->check_file( $root, $id, $meta );
			if ( is_wp_error( $path ) || ! hash_equals( $candidate_hash, (string) hash_file( 'sha256', $path ) ) ) {
				return new WP_Error( 'transfer_source_changed', 'Source bytes changed before the source effect.' );
			}
			if ( ! $this->fence_native_effect( $root, $id, 'source', 'apply', 0 ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Source effect fence is uncertain; never retry.' );
			}
			$meta['state']          = 'committing';
			$meta['consumer']       = 'source';
			$meta['intent']         = 'apply';
			$meta['target_id']      = 0;
			$meta['destination_id'] = 0;
			$meta['outcome']        = 'unknown';
			return $this->save_meta( $handle, $meta ) ? true :
				new WP_Error( 'transfer_outcome_unknown', 'Source apply was fenced but its receipt is uncertain.' );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/** @return true|WP_Error */
	public function finish_source( $id, $purpose, $target_hash, $candidate_hash ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( 'committing' !== $meta['state'] || 'source' !== ( $meta['consumer'] ?? '' ) ||
				$purpose !== $meta['purpose'] || ! $this->allowed( $purpose ) ||
				! hash_equals( (string) ( $meta['source_target'] ?? '' ), $target_hash ) ||
				! hash_equals( (string) ( $meta['source_candidate'] ?? '' ), $candidate_hash ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Source effect identity is uncertain; do not retry.' );
			}
			$meta['state']   = 'committed';
			$meta['outcome'] = 'verified';
			return $this->save_meta( $handle, $meta ) ? true :
				new WP_Error( 'transfer_outcome_unknown', 'Source edit finished but private claim persistence is uncertain.' );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/**
	 * Snapshot one WordPress-validated source into the same private, digest-bound
	 * transport. Caller must first authorize and confine the source to its native
	 * provider root. No path may be returned through an MCP response.
	 *
	 * The copy and verification are bounded-memory; admission is governed by
	 * administrator transfer, concurrency and available disk budgets.
	 *
	 * @param string $path Provider-validated local regular file.
	 * @param array<string,int> $policy Current administrator transfer policy.
	 * @return array<string,mixed>|WP_Error
	 */
	public function stage_media_export( $path, $policy, $attachment_id ) {
		if ( ! $this->allowed( 'media_read' ) || ! is_string( $path ) ||
			! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ||
			! is_int( $attachment_id ) || $attachment_id < 1 ||
			! current_user_can( 'read_post', $attachment_id ) ||
			! is_array( $policy ) ) {
			return $this->denied();
		}
		return $this->stage_native_export( $path, $policy, 'media_read', $attachment_id );
	}

	/**
	 * Freeze a provider-resolved installed source in the existing transfer plane.
	 * Never accept a client-supplied path; the source provider checks confinement.
	 * @return array<string,mixed>|WP_Error
	 */
	public function stage_source_export( $path, $policy, $kind ) {
		$purpose = 'plugin' === $kind ? 'source_plugin_read' : ( 'theme' === $kind ? 'source_theme_read' : '' );
		if ( '' === $purpose || ! $this->allowed( $purpose ) || ! is_array( $policy ) ||
			! is_string( $path ) || is_link( $path ) || ! is_file( $path ) ||
			! is_readable( $path ) || wp_normalize_path( (string) realpath( $path ) ) !== wp_normalize_path( $path ) ) {
			return $this->denied();
		}
		return $this->stage_native_export( $path, $policy, $purpose, (int) get_current_blog_id() );
	}

	/**
	 * Freeze an already-generated WordPress Core WXR private artifact.
	 * Caller is the exact native producer, never a remote filesystem path.
	 *
	 * @param array<string,mixed> $artifact Trusted, just-generated native WXR descriptor.
	 * @param array<string,int>   $policy Current administrator-owned transfer budgets.
	 * @return array<string,mixed>|WP_Error
	 */
	public function stage_wxr_export( $artifact, $policy ) {
		if ( ! $this->allowed( 'wxr_read' ) || ! is_array( $artifact ) || ! is_array( $policy ) ||
			! isset( $artifact['path'], $artifact['bytes'], $artifact['sha256'], $artifact['blog_id'] ) ||
			! is_string( $artifact['path'] ) || ! is_int( $artifact['bytes'] ) ||
			! is_int( $artifact['blog_id'] ) || (int) get_current_blog_id() !== $artifact['blog_id'] ||
			$artifact['bytes'] < 1 || $artifact['bytes'] > $policy['file'] ||
			! is_string( $artifact['sha256'] ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $artifact['sha256'] ) ) {
			return $this->denied();
		}
		$path      = $artifact['path'];
		$canonical = realpath( $path );
		$temp_dir  = Safe_Temporary_Staging::directory();
		if ( ! is_string( $canonical ) || ! is_string( $temp_dir ) ||
			is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ||
			dirname( $canonical ) !== $temp_dir ||
			! str_starts_with( basename( $canonical ), 'wpai-private-artifact' ) ||
			0600 !== ( fileperms( $canonical ) & 0777 ) ) {
			return new WP_Error( 'wxr_export_storage', 'WordPress WXR source must be a restricted private artifact.' );
		}
		clearstatcache( true, $canonical );
		if ( filesize( $canonical ) !== $artifact['bytes'] ||
			! hash_equals( $artifact['sha256'], (string) hash_file( 'sha256', $canonical ) ) ) {
			return new WP_Error( 'wxr_export_stale', 'Private Core WXR bytes changed before snapshotting.' );
		}
		$receipt = $this->stage_native_export( $canonical, $policy, 'wxr_read', (int) get_current_blog_id() );
		if ( ! is_wp_error( $receipt ) && ( $receipt['total'] !== $artifact['bytes'] ||
			! hash_equals( $receipt['sha256'], $artifact['sha256'] ) ) ) {
			$this->cancel( $receipt['id'] );
			return new WP_Error( 'wxr_export_stale', 'Private Core WXR snapshot bytes differ from the generated source.' );
		}
		return $receipt;
	}

	/**
	 * Stage a complete trusted encoder document directly into existing private
	 * bytes. No public temporary source, native getter or effect journal.
	 *
	 * @param string            $encoded_json Complete native value document.
	 * @param array<string,int> $policy Current operator limits.
	 * @return array<string,mixed>|WP_Error
	 */
	public function stage_encoded_settings( string $encoded_json, array $policy ) {
		$purpose = 'native_' . $this->native_options_scope . '_settings_read';
		if ( null === $this->native_options_scope || ! $this->allowed( $purpose ) ) {
			return $this->denied();
		}
		$size  = strlen( $encoded_json );
		$chunk = min( $policy['chunk'], $policy['file'], max( 1, intdiv( max( 1, $policy['request'] - 4096 ), 4 ) * 3 ) );
		$lease = Hook_Dispatch_Recovery::run(
			function () use ( $purpose, $size, $encoded_json, $policy, $chunk ) {
				return $this->begin( $purpose, $size, hash( 'sha256', $encoded_json ), $policy['file'], $chunk, $policy['sessions'], $policy['ttl'], $policy['active'] );
			}
		);
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		$id      = $lease['id'];
		$success = false;
		try {
			$offset = 0;
			while ( $offset < $size ) {
				$next = Hook_Dispatch_Recovery::run(
					function () use ( $id, $offset, $encoded_json, $chunk, $size ) {
						return $this->append( $id, $offset, substr( $encoded_json, $offset, min( $chunk, $size - $offset ) ) );
					}
				);
				if ( is_wp_error( $next ) ) {
					return $next;
				}
				$offset = $next['received'];
			}
			$verified = Hook_Dispatch_Recovery::run(
				function () use ( $id ) {
					return $this->verify( $id );
				}
			);
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			$state = Hook_Dispatch_Recovery::run(
				function () use ( $id ) {
					return $this->status( $id );
				}
			);
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$success = 'verified' === $state['state'];
			return $success ? $state : $this->invalid();
		} finally {
			if ( ! $success ) {
				try {
					$cleanup = Hook_Dispatch_Recovery::run(
						function () use ( $id ) {
							return $this->discard_native_option_creation( $id );
						}
					);
				} catch ( \Throwable $error ) {
					$cleanup = new WP_Error( 'transfer_cleanup_uncertain', 'Failed private settings creation requires cleanup.' );
				}
				if ( is_wp_error( $cleanup ) ) {
					return $cleanup;
				}
			}
		}
	}

	/**
	 * Cleanup authority is limited to a lease allocated by this exact instance.
	 * It survives revocation/drift only to erase its own failed creation; it
	 * grants no read, general cancellation or access to other stored leases.
	 *
	 * @param string $id Exact newly allocated lease.
	 * @return bool|WP_Error
	 */
	public function discard_native_option_creation( $id ) {
		if ( null === $this->native_options_scope || ! is_string( $id ) ||
			! isset( $this->native_created_roots[ $id ] ) ) {
			return $this->denied();
		}
		$proof = $this->native_created_roots[ $id ];
		$root  = $proof['root'];
		clearstatcache( true, $root );
		$stat = @lstat( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing original storage is a redacted cleanup failure.
		if ( is_link( $root ) || realpath( $root ) !== $root || ! is_array( $stat ) ||
			$stat['dev'] !== $proof['device'] || $stat['ino'] !== $proof['inode'] ||
			0040000 !== ( $stat['mode'] & 0170000 ) || 0700 !== ( $stat['mode'] & 0777 ) ) {
			return new WP_Error( 'transfer_storage_unsafe', 'The original private settings creation root changed.' );
		}
		$guard = $this->guard( $root );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		try {
			$info = $this->open_meta( $root, $id, true, true );
			if ( is_wp_error( $info ) ) {
				return $info;
			}
			list( $handle, $meta ) = $info;
			try {
				if ( 'native_' . $this->native_options_scope . '_settings_read' !== $meta['purpose'] ||
					array_intersect_key( $meta, $proof['binding'] ) !== $proof['binding'] ||
					$proof['total'] !== $meta['total'] ||
					! hash_equals( $proof['sha256'], $meta['sha256'] ) ||
					! in_array( $meta['state'], array( 'open', 'verified' ), true ) ) {
					return $this->denied();
				}
				if ( ! $this->erase( $root, $id ) ) {
					return new WP_Error( 'transfer_cleanup_uncertain', 'Failed private settings creation requires cleanup.' );
				}
				unset( $this->native_created_roots[ $id ] );
				return true;
			} finally {
				$this->close_meta( $handle );
			}
		} finally {
			flock( $guard, LOCK_UN );
			fclose( $guard );
		}
	}

	/**
	 * One private snapshot writer, reused by native Media and WXR producers.
	 * Permissions and exact provider identity are rechecked before receipt.
	 *
	 * @param string $path Trusted provider-constrained file.
	 * @param array<string,int> $policy Administrator capacity limits.
	 * @param string $purpose Internal private read lease type.
	 * @param int $source_id Exact attachment ID or originating blog ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function stage_native_export( $path, $policy, $purpose, $source_id ) {
		clearstatcache( true, $path );
		$size = filesize( $path );
		if ( ! is_int( $size ) || $size < 0 || $size > $policy['file'] ) {
			return new WP_Error( 'transfer_size_setting', 'This file exceeds the current administrator-configured file transfer budget.' );
		}
		$sha256 = hash_file( 'sha256', $path );
		if ( ! is_string( $sha256 ) ) {
			return new WP_Error( 'transfer_source_changed', 'The WordPress source could not be hashed before export.' );
		}
		$chunk = min( $policy['chunk'], max( 1, intdiv( max( 1, $policy['request'] - 4096 ) * 3, 4 ) ) );
		$lease = $this->begin( $purpose, $size, $sha256, $policy['file'], $chunk, $policy['sessions'], $policy['ttl'], $policy['active'] );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		$id     = $lease['id'];
		$source = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Constrained native attachment source; never a caller path.
		try {
			if ( ! is_resource( $source ) ) {
				return new WP_Error( 'transfer_source_changed', 'WordPress source became unavailable during export.' );
			}
			$offset = 0;
			while ( $offset < $size ) {
				$part = fread( $source, min( $chunk, $size - $offset ) );
				if ( ! is_string( $part ) || '' === $part ) {
					return new WP_Error( 'transfer_source_changed', 'WordPress source was interrupted during export.' );
				}
				$next = $this->append( $id, $offset, $part );
				if ( is_wp_error( $next ) ) {
					return $next;
				}
				$offset = $next['received'];
			}
			$verified = $this->verify( $id );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			$loaded = $this->load( $id );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			list( $root, $handle, $meta ) = $loaded;
			try {
				if ( 'verified' !== $meta['state'] || $purpose !== $meta['purpose'] || ! $this->allowed( $purpose ) ||
					( 'media_read' === $purpose && ( ! current_user_can( 'read_post', $source_id ) ||
						'attachment' !== get_post_type( $source_id ) ) ) ||
					( 'wxr_read' === $purpose && (int) get_current_blog_id() !== $source_id ) ||
					( in_array( $purpose, array( 'source_plugin_read', 'source_theme_read' ), true ) &&
						(int) get_current_blog_id() !== $source_id ) ) {
					return $this->denied();
				}
				$meta['source_id'] = $source_id;
				if ( ! $this->save_meta( $handle, $meta ) ) {
					return new WP_Error( 'transfer_outcome_unknown', 'Media snapshot receipt could not be stored.' );
				}
				return $this->receipt( $meta );
			} finally {
				$this->close_meta( $handle );
			}
		} finally {
			if ( is_resource( $source ) ) {
				fclose( $source );
			}
			// Release incomplete/failed snapshots; verified copies remain for
			// normal client read/cancel/expiry. The lease never exposes its path.
			$state = $this->status( $id );
			if ( is_wp_error( $state ) || 'verified' !== $state['state'] || empty( $state['source_id'] ) ) {
				$this->cancel( $id );
			}
		}
	}

	/**
	 * One bounded, arbitrary-offset byte window of a frozen Media export.
	 * The final client MUST independently verify total length and SHA-256.
	 *
	 * @param string $id Authenticated snapshot.
	 * @param int $offset Absolute raw byte offset.
	 * @param int $max_bytes Current permitted per-response raw bytes.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read_media_export( $id, $offset, $max_bytes ) {
		return $this->read_export_window( $id, $offset, $max_bytes, array( 'media_read', 'wxr_read', 'source_plugin_read', 'source_theme_read' ) );
	}

	/** @return array<string,mixed>|WP_Error Fixed native-settings byte plane only. */
	public function read_native_options( $id, $offset, $max_bytes ) {
		if ( null === $this->native_options_scope ) {
			return $this->denied();
		}
		return $this->read_export_window( $id, $offset, $max_bytes, array( 'native_' . $this->native_options_scope . '_settings_read' ) );
	}

	/** @return array<string,mixed>|WP_Error Shared immutable window implementation. */
	private function read_export_window( $id, $offset, $max_bytes, $purposes ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( ! in_array( $meta['purpose'], $purposes, true ) ||
				'verified' !== $meta['state'] || ! $this->allowed( $meta['purpose'] ) ||
				( null !== $this->native_options_scope &&
					$this->native_binding() !== array_intersect_key( $meta, $this->native_binding() ) ) ||
				! is_int( $meta['source_id'] ?? null ) ||
				( 'media_read' === $meta['purpose'] && (
					'attachment' !== get_post_type( $meta['source_id'] ) ||
					! current_user_can( 'read_post', $meta['source_id'] ) ) ) ||
				( 'wxr_read' === $meta['purpose'] && (int) get_current_blog_id() !== $meta['source_id'] ) ||
				( in_array( $meta['purpose'], array( 'source_plugin_read', 'source_theme_read' ), true ) &&
					(int) get_current_blog_id() !== $meta['source_id'] ) ||
				! is_int( $offset ) || $offset < 0 || $offset > $meta['total'] ||
				! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes > $meta['chunk'] ) {
				return $this->invalid();
			}
			$path   = $root . '/' . $id . '.bytes';
			$stream = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Authenticated, confined immutable snapshot.
			if ( ! is_resource( $stream ) ) {
				return new WP_Error( 'transfer_storage_uncertain', 'Private exported bytes could not be opened.' );
			}
			try {
				if ( ! $this->secure_file( $stream, $path ) ||
					! $this->file_length( $stream, $meta['total'] ) || 0 !== fseek( $stream, $offset ) ) {
					return new WP_Error( 'transfer_source_changed', 'Private export snapshot identity or byte offset changed.' );
				}
				$wanted = min( $max_bytes, $meta['total'] - $offset );
				$bytes  = 0 === $wanted ? '' : fread( $stream, $wanted );
				if ( ! is_string( $bytes ) || strlen( $bytes ) !== $wanted ) {
					return new WP_Error( 'transfer_source_changed', 'Private export snapshot ended before the declared window.' );
				}
				return array(
					'id'           => $id,
					'offset'       => $offset,
					'next_offset'  => $offset + $wanted,
					'complete'     => $offset + $wanted === $meta['total'],
					'base64'       => base64_encode( $bytes ),
					'chunk_sha256' => hash( 'sha256', $bytes ),
					'total'        => $meta['total'],
					'sha256'       => $meta['sha256'],
				);
			} finally {
				fclose( $stream );
			}
		} finally {
			$this->close_meta( $handle );
		}
	}

	/**
	 * Claim one text stage before its potentially non-idempotent WordPress write.
	 * A crash after this claim must NOT silently retry the effect.
	 *
	 * @param string $id Verified text lease.
	 * @return array<string,mixed>|WP_Error
	 */
	public function claim_content( $id, $intent = 'create', $target_id = 0 ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( 'text' !== $meta['purpose'] || 'verified' !== $meta['state'] ||
				! $this->allowed( 'text' ) ) {
				return new WP_Error( 'transfer_consume_uncertain', 'Text transfer has already been claimed or is not verified.' );
			}
			$path = $this->check_file( $root, $id, $meta );
			if ( is_wp_error( $path ) ||
				! hash_equals( $meta['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				return new WP_Error( 'transfer_source_changed', 'Staged text changed before native consumption.' );
			}
			if ( ! in_array( $intent, array( 'create', 'update' ), true ) ||
				! is_int( $target_id ) || $target_id < 0 ||
				( 'update' === $intent && $target_id < 1 ) ) {
				return $this->invalid();
			}
			if ( ! $this->fence_native_effect( $root, $id, 'content', $intent, $target_id ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Native text claim could not be durably fenced; do not retry blindly.' );
			}
			$meta['state']          = 'committing';
			$meta['consumer']       = 'content';
			$meta['intent']         = $intent;
			$meta['target_id']      = $target_id;
			$meta['outcome']        = 'unknown';
			$meta['destination_id'] = 0;
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Text claim persistence was uncertain; do not blindly retry.' );
			}
			return $this->receipt( $meta );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/**
	 * Verify the exact native WordPress content effect before recording success.
	 * @param string $id Claimed transfer.
	 * @param int $post_id Native WordPress object.
	 * @return array<string,mixed>|WP_Error
	 */
	public function finish_content( $id, $post_id ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( 'text' !== $meta['purpose'] || 'committing' !== $meta['state'] ||
				'content' !== ( $meta['consumer'] ?? '' ) ||
				! is_int( $post_id ) || $post_id < 1 ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Native text identity is uncertain; do not retry blindly.' );
			}
			$meta['destination_id'] = $post_id;
			$meta['outcome']        = 'observed';
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Known native post identity could not be checkpointed.' );
			}
			$post = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ||
				! $this->allowed( 'text' ) ||
				strlen( (string) $post->post_content ) !== $meta['total'] ||
				! hash_equals( $meta['sha256'], hash( 'sha256', (string) $post->post_content ) ) ) {
				$meta['outcome'] = 'observed_changed';
				$meta['state']   = 'observed';
				if ( ! $this->save_meta( $handle, $meta ) ) {
					return new WP_Error( 'transfer_outcome_unknown', 'Native text changed and the final receipt is uncertain; inspect the recorded post ID.' );
				}
				return new WP_Error( 'transfer_native_transformed', 'WordPress returned a known post ID but stored content differs from the verified input; inspect that exact post.' );
			}
			$meta['state']   = 'committed';
			$meta['outcome'] = 'verified';
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'WordPress text was saved, but the private receipt could not be committed.' );
			}
			return $this->receipt( $meta );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/**
	 * A deterministic failure before native write is not an unknown effect.
	 * The one-way fence remains, but is marked as a known rejection.
	 *
	 * @param string $id Claimed lease.
	 * @return array<string,mixed>|WP_Error
	 */
	public function record_known_no_effect( $id ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( 'committing' !== $meta['state'] || 'content' !== ( $meta['consumer'] ?? '' ) ||
				( $meta['destination_id'] ?? 0 ) !== 0 ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Native write rejection could not be classified safely.' );
			}
			$meta['state']   = 'rejected';
			$meta['outcome'] = 'known_no_effect';
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Known rejected effect was not durably checkpointed.' );
			}
			return $this->receipt( $meta );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/**
	 * Free private byte reservations while retaining the one-way outcome
	 * fence and minimal recovery metadata. This is NOT a retry/undo.
	 * Truly unknown native effects require an explicit administrator
	 * acknowledgement; no other client can silently discard evidence.
	 *
	 * @param string $id Existing exact lease.
	 * @param bool $ack_unknown Explicit, authorized outcome-risk acceptance.
	 * @return array<string,mixed>|WP_Error
	 */
	public function release_payload( $id, $ack_unknown = false ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( ! in_array( $meta['state'], array( 'committing', 'committed', 'observed', 'rejected' ), true ) ) {
				return $this->invalid();
			}
			if ( 'committing' === $meta['state'] &&
				( ! $ack_unknown || ! current_user_can( 'manage_options' ) ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Native effect may have happened; site administrator must explicitly acknowledge uncertainty before releasing the private bytes.' );
			}
			$released = $this->release_private_payload( $root, $id, $handle, $meta );
			return is_wp_error( $released ) ? $released : $this->receipt( $meta );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/**
	 * Administrator-only, exact-site release of unknown-effect private bytes.
	 * Does NOT grant read/append/verify/cancel/retry or lease ownership.
	 * The final native-effect claim and identity journal are never removed.
	 *
	 * @param string $id Exact opaque lease ID.
	 * @return array<string,mixed>|WP_Error Minimal acknowledgement, no source metadata.
	 */
	public function admin_release_unknown( $id ) {
		if ( ! current_user_can( 'manage_options' ) ||
			'' === OAuth_Server::authenticated_mcp_client_id() ||
			! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $id ) ) {
			return $this->denied();
		}
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$info = $this->open_meta( $root, $id );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		list( $handle, $meta ) = $info;
		try {
			if ( (int) get_current_blog_id() !== $meta['blog'] ||
				'committing' !== $meta['state'] || 'unknown' !== $meta['outcome'] ) {
				return new WP_Error( 'transfer_admin_recovery_denied', 'Only a same-site administrator may release an explicitly unknown native-effect payload.' );
			}
			$released = $this->release_private_payload( $root, $id, $handle, $meta );
			if ( is_wp_error( $released ) ) {
				return $released;
			}
			// Expose only the opaque ID and release result. Never disclose
			// another principal's digest, byte count, origin or private path.
			return array(
				'id'               => $id,
				'state'            => 'committing',
				'payload_released' => true,
			);
		} finally {
			$this->close_meta( $handle );
		}
	}

	/** @param string $id Opaque session. @return bool|WP_Error */
	public function cancel( $id ) {
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$guard = $this->guard( $root );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		try {
			// A previous deletion may have been refused after writing the
			// tombstone. An authorized owner must still be able to resume
			// cleanup, without exposing the lease for data-plane operations.
			$loaded = $this->load( $id, true, true );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			list( $root, $handle, $meta ) = $loaded;
			try {
				if ( in_array( $meta['state'], array( 'committing', 'observed', 'preparing' ), true ) ) {
					return new WP_Error( 'transfer_outcome_unknown', 'Native effect may have occurred or preparation is active. Use explicit payload release and retain recovery identity.' );
				}
				return $this->erase( $root, $id ) ? true :
					new WP_Error( 'transfer_cleanup_uncertain', 'Private cancellation was not completed; its durable tombstone prevents lease replay.' );
			} finally {
				$this->close_meta( $handle );
			}
		} finally {
			flock( $guard, LOCK_UN );
			fclose( $guard );
		}
	}

	/** @param string $purpose Explicit caller workflow. @return bool */
	private function allowed( $purpose ) {
		if ( 0 === get_current_user_id() ) {
			return false;
		}
		if ( null !== $this->native_options_scope ) {
			if ( ! in_array( $this->native_options_scope, array( 'site', 'network' ), true ) ||
				'native_' . $this->native_options_scope . '_settings_read' !== $purpose ) {
				return false;
			}
			try {
				return Hook_Dispatch_Recovery::run(
					function () {
						return ( new Native_Option_Access( $this->permissions ) )->allowed( $this->native_options_scope );
					}
				);
			} catch ( \Throwable $error ) {
				return false;
			}
		}
		if ( $this->database_only ) {
			return 'database_value' === $purpose &&
				( ! is_multisite() || ( is_super_admin() && current_user_can( 'manage_network_options' ) ) ) &&
				$this->permissions->allowed_all(
					array(
						array( Settings::GROUP_DATABASE_INSPECTION, 'manage_options' ),
						array( Settings::GROUP_DATABASE_READ, 'manage_options' ),
						array( Settings::GROUP_DATABASE_WRITE, 'manage_options' ),
					)
				);
		}
		switch ( $purpose ) {
			case 'media':
				return $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'upload_files' );
			case 'media_read':
				return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
			case 'wxr_read':
				return $this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'export' ) &&
					( ! is_multisite() || current_user_can( 'manage_network_options' ) );
			case 'text':
				return $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'edit_posts' );
			case 'wxr':
				return $this->permissions->allowed_all( array( array( Settings::GROUP_SITE_CONFIG, 'import' ), array( Settings::GROUP_BUILDER_WRITE, 'edit_posts' ) ) );
			case 'source_plugin':
			case 'source_plugin_read':
			case 'source_theme':
			case 'source_theme_read':
				if ( '' === OAuth_Server::authenticated_mcp_client_id() ) {
					return false;
				}
				$capability = str_starts_with( $purpose, 'source_plugin' ) ? 'edit_plugins' : 'edit_themes';
				return wp_is_file_mod_allowed( 'wp_ai_bridge_source_editing' ) &&
					$this->permissions->allowed( Settings::GROUP_CODE_EXTENSIONS, 'read' ) &&
					$this->permissions->allowed( Settings::GROUP_SOURCE_EDITING, 'read' ) &&
					current_user_can( $capability );
			default:
				return false;
		}
	}

	/** @return WP_Error */
	private function denied() {
		return new WP_Error( 'transfer_denied', 'Native WordPress and Bridge permission is required for this private transfer purpose.' );
	}

	/** @return WP_Error */
	private function invalid() {
		return new WP_Error( 'transfer_invalid', 'The private transfer request, byte offset or quota is invalid.' );
	}

	/** @return string|WP_Error */
	private function root() {
		$base = Safe_Temporary_Staging::directory();
		if ( ! is_string( $base ) || ! is_dir( $base ) || ! is_writable( $base ) ) {
			return new WP_Error( 'transfer_storage_unavailable', 'No writable temporary private storage is available.' );
		}
		$label = hash( 'sha256', serialize( array( DB_NAME, ABSPATH, get_current_blog_id(), wp_salt( 'auth' ) ) ) );
		$root  = $base . '/wpai-transfer-' . substr( $label, 0, 40 );
		foreach ( array( ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? '' ) as $webroot ) {
			$real = is_string( $webroot ) ? realpath( $webroot ) : false;
			if ( is_string( $real ) && 0 === strpos( wp_normalize_path( $root ) . '/', trailingslashit( wp_normalize_path( $real ) ) ) ) {
				return new WP_Error( 'transfer_storage_unsafe', 'The private transfer root must be outside the WordPress web directory.', array( 'storage_reason' => 'root_web_served' ) );
			}
		}
		if ( ! is_dir( $root ) && ! @mkdir( $root, 0700 ) && ! is_dir( $root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Fixed site-secret directory, outside webroot.
			return new WP_Error( 'transfer_storage_unavailable', 'Cannot create an owner-only private transfer directory.' );
		}
		if ( is_link( $root ) ) {
			return new WP_Error( 'transfer_storage_unsafe', 'Private transfer directory cannot be a symbolic link.', array( 'storage_reason' => 'root_symlink' ) );
		}
		if ( realpath( $root ) !== $root ) {
			return new WP_Error( 'transfer_storage_unsafe', 'Private transfer directory is not canonical.', array( 'storage_reason' => 'root_noncanonical' ) );
		}
		clearstatcache( true, $root );
		$mode = fileperms( $root );
		// A correct 0700 directory is already safe. Some managed hosts deny an
		// unnecessary chmod, even when the existing owner-only mode is valid.
		if ( false === $mode || ( 0700 !== ( $mode & 0777 ) && ! chmod( $root, 0700 ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Only tighten an insecure existing directory.
			return new WP_Error( 'transfer_storage_unsafe', 'Cannot enforce private transfer directory permissions.', array( 'storage_reason' => 'root_mode' ) );
		}
		clearstatcache( true, $root );
		if ( 0700 !== ( fileperms( $root ) & 0777 ) ) {
			return new WP_Error( 'transfer_storage_unsafe', 'Private transfer directory permissions remain unsafe.', array( 'storage_reason' => 'root_mode' ) );
		}
		return $root;
	}

	/** @param string $root Validated private root. @return resource|WP_Error */
	private function guard( $root ) {
		$path = $root . '/.admission';
		$h    = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Private fixed-name admission lock.
		if ( false === $h || ! $this->secure_file( $h, $path ) || ! flock( $h, LOCK_EX ) ) {
			if ( is_resource( $h ) ) {
				fclose( $h );
			}
			return new WP_Error( 'transfer_storage_unavailable', 'Could not acquire private transfer admission lock.' );
		}
		return $h;
	}

	/** @param resource $h Opened handle. @param string $path Expected file. @return bool */
	private function secure_file( $h, $path ) {
		if ( ! is_resource( $h ) || is_link( $path ) ) {
			return false;
		}
		clearstatcache( true, $path );
		$disk = lstat( $path );
		$fd   = fstat( $h );
		if ( ! is_array( $disk ) || ! is_array( $fd ) ||
			$disk['ino'] !== $fd['ino'] || $disk['dev'] !== $fd['dev'] ||
			0100000 !== ( $disk['mode'] & 0170000 ) ) {
			return false;
		}
		$mode = fileperms( $path );
		if ( false === $mode || ( 0600 !== ( $mode & 0777 ) && ! chmod( $path, 0600 ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Tighten only when the inode is not already private.
			return false;
		}
		clearstatcache( true, $path );
		return 0600 === ( fileperms( $path ) & 0777 );
	}

	/**
	 * Admission can inspect stable, atomically published generations without
	 * waiting for a Media native effect's long-held per-lease flock. Global
	 * guard serializes new/scratch reservations; two generations are kept
	 * conservative for concurrent payload release and append progress.
	 *
	 * @return array<int,mixed>|WP_Error [lock|null, capacity metadata].
	 */
	private function admission_meta( $root, $id ) {
		$info = $this->open_meta( $root, $id, false, true );
		if ( ! is_wp_error( $info ) || 'transfer_session_busy' !== $info->get_error_code() ) {
			return $info;
		}
		$snapshots = array();
		foreach ( array( 0, 1 ) as $slot ) {
			$read = $this->read_checkpoint( $root . '/' . $id . '.meta.state.' . $slot );
			if ( is_array( $read ) && $this->valid_meta( $read['body'], $id ) ) {
				$snapshots[] = $read;
			}
		}
		if ( ! $snapshots ) {
			return new WP_Error( 'transfer_session_busy', 'A native WordPress effect is active and its reservation cannot be safely read.' );
		}
		usort(
			$snapshots,
			static function ( $a, $b ) {
				return $b['generation'] <=> $a['generation'];
			}
		);
		$meta = $snapshots[0]['body'];
		foreach ( $snapshots as $snapshot ) {
			$candidate = $snapshot['body'];
			if ( $candidate['total'] !== $meta['total'] || $candidate['blog'] !== $meta['blog'] ) {
				return new WP_Error( 'transfer_session_busy', 'Busy private reservation generations disagree.' );
			}
			$meta['scratch_reserved'] = max( (int) ( $meta['scratch_reserved'] ?? 0 ), (int) ( $candidate['scratch_reserved'] ?? 0 ) );
			$meta['received']         = min( $meta['received'], $candidate['received'] );
			$meta['payload_released'] = ! empty( $meta['payload_released'] ) && ! empty( $candidate['payload_released'] );
		}
		// Do not expire, delete or mutate an actively held lease. The
		// conservative snapshot counts its full maximum reservation instead.
		return array( null, $meta );
	}

	/**
	 * A killed writer may leave only an unpublished .claim.pending.* file.
	 * Never promote it to a native-effect fence, and never remove one while
	 * its owner still holds the stable per-session coordinator.
	 */
	private function reconcile_unpublished_claim( $root, $id, $entry ) {
		if ( $entry->getMTime() > time() - 120 ) {
			return;
		}
		$path = $root . '/' . $entry->getFilename();
		// A cancelled lease can lose its .meta coordinator while a killed
		// pre-publication writer left only an inert, old pending file.
		$manifest = $root . '/' . $id . '.meta';
		if ( ! file_exists( $manifest ) && ! is_link( $manifest ) ) {
			$this->remove_file( $path );
			return;
		}
		$info = $this->open_meta( $root, $id, false, true );
		if ( ! is_wp_error( $info ) ) {
			$this->remove_file( $path );
			$this->close_meta( $info[0] );
		}
	}

	/**
	 * Lock identity is stable across checkpoint replacement. The .meta inode is
	 * never truncated or renamed. Two alternating, checksum-protected generations
	 * make partial writes and worker death recoverable without accepting a torn
	 * JSON manifest as the source of truth.
	 *
	 * @param string $root Owner-only site directory.
	 * @param string $id Fixed hexadecimal transfer identity.
	 * @return array<int,mixed>|WP_Error
	 */
	private function open_meta( $root, $id, $allow_tombstone = false, $nonblocking = false ) {
		$path = $root . '/' . $id . '.meta';
		$h    = @fopen( $path, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Stable private coordination inode, never replaced.
		if ( false === $h || ! $this->secure_file( $h, $path ) ) {
			if ( is_resource( $h ) ) {
				fclose( $h );
			}
			return new WP_Error( 'transfer_storage_uncertain', 'Private transfer coordination lock could not be safely opened.' );
		}
		if ( ! flock( $h, LOCK_EX | ( $nonblocking ? LOCK_NB : 0 ) ) ) {
			fclose( $h );
			return new WP_Error( $nonblocking ? 'transfer_session_busy' : 'transfer_storage_uncertain', 'Private transfer is busy with another native operation; retry after it completes.' );
		}
		// A canceller can unlink the stable coordinator while a peer waits
		// on its inode. Check the live directory identity *after* locking.
		if ( ! $this->secure_file( $h, $path ) ||
			( ! $allow_tombstone &&
				( file_exists( $root . '/' . $id . '.tombstone' ) ||
					is_link( $root . '/' . $id . '.tombstone' ) ) ) ) {
			$this->close_meta( $h );
			return new WP_Error( 'transfer_cancelled', 'This private lease is removed or has a durable deletion marker.' );
		}
		$meta      = null;
		$highest   = 0;
		$has_slots = false;
		foreach ( array( 0, 1 ) as $slot ) {
			$checkpoint = $path . '.state.' . $slot;
			if ( file_exists( $checkpoint ) || is_link( $checkpoint ) ) {
				$has_slots = true;
			}
			$candidate = $this->read_checkpoint( $checkpoint );
			if ( is_array( $candidate ) && $candidate['generation'] > $highest ) {
				$highest = $candidate['generation'];
				$meta    = $candidate['body'];
			}
		}
		if ( ! $has_slots ) {
			// Compatibility for draft leases created before journaled state.
			rewind( $h );
			$legacy = stream_get_contents( $h, 8192 );
			$meta   = is_string( $legacy ) ? json_decode( $legacy, true ) : null;
		}
		if ( ! $this->valid_meta( $meta, $id ) ) {
			$this->close_meta( $h );
			return new WP_Error( 'transfer_storage_uncertain', 'Private transfer has no complete, valid checkpoint.' );
		}
		$meta['generation'] = $highest;
		// An immutable claim fence is published BEFORE a native effect.
		// A torn newer checkpoint can never reauthorize a consumed old one.
		$claim = $this->read_claim_fence( $root, $id );
		if ( is_wp_error( $claim ) ) {
			// An incomplete legacy final fence is NEVER discarded or replayed.
			// Fail this lease closed as an unknown native effect, while still
			// allowing unrelated capacity accounting and admin-only cleanup.
			$meta['state']          = 'committing';
			$meta['consumer']       = 'unknown';
			$meta['destination_id'] = 0;
			$meta['intent']         = 'unknown';
			$meta['target_id']      = 0;
			$meta['outcome']        = 'unknown';
		}
		if ( is_array( $claim ) && in_array( $meta['state'], array( 'open', 'verified', 'preparing' ), true ) ) {
			$meta['state']          = 'committing';
			$meta['consumer']       = $claim['consumer'];
			$meta['destination_id'] = 0;
			$meta['intent']         = $claim['intent'];
			$meta['target_id']      = $claim['target_id'];
			$meta['outcome']        = 'unknown';
		}
		return array( $h, $meta );
	}

	/** @param mixed $meta Checkpoint body. @param string $id Exact lease. @return bool */
	private function valid_meta( $meta, $id ) {
		return is_array( $meta ) &&
			1 === ( $meta['version'] ?? null ) && hash_equals( $id, (string) ( $meta['id'] ?? '' ) ) &&
			isset(
				$meta['purpose'],
				$meta['blog'],
				$meta['user'],
				$meta['client'],
				$meta['total'],
				$meta['chunk'],
				$meta['received'],
				$meta['sha256'],
				$meta['expires'],
				$meta['state']
			) &&
			is_int( $meta['total'] ) && is_int( $meta['received'] ) && is_int( $meta['chunk'] ) &&
			$meta['total'] >= $meta['received'] && $meta['received'] >= 0 && $meta['chunk'] >= 1 &&
			is_int( $meta['blog'] ) && $meta['blog'] >= 1 &&
			is_int( $meta['user'] ) && $meta['user'] >= 1 &&
			is_int( $meta['expires'] ) && $meta['expires'] >= 1 &&
			is_string( $meta['client'] ) && is_string( $meta['purpose'] ) &&
			in_array( $meta['purpose'], array( 'media', 'media_read', 'wxr_read', 'text', 'wxr', 'source_plugin', 'source_theme', 'source_plugin_read', 'source_theme_read', 'database_value', 'native_site_settings_read', 'native_network_settings_read' ), true ) &&
			( ! in_array( $meta['purpose'], array( 'native_site_settings_read', 'native_network_settings_read' ), true ) ||
				( is_int( $meta['source_id'] ?? null ) && $meta['source_id'] > 0 &&
					in_array( $meta['state'], array( 'open', 'verified' ), true ) ) ) &&
			is_string( $meta['sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $meta['sha256'] ) &&
			in_array( $meta['state'], array( 'open', 'preparing', 'verified', 'committing', 'committed', 'rejected', 'observed' ), true ) &&
			( ! in_array( $meta['state'], array( 'committing', 'committed', 'rejected', 'observed' ), true ) ||
				( ( in_array( $meta['consumer'] ?? '', array( 'media', 'content', 'wxr', 'source' ), true ) ||
				( 'committing' === $meta['state'] && 'unknown' === ( $meta['consumer'] ?? '' ) &&
					'unknown' === ( $meta['outcome'] ?? '' ) ) ) &&
				is_int( $meta['destination_id'] ?? null ) && $meta['destination_id'] >= 0 ) );
	}

	/** @param string $path Private checkpoint. @return array<string,mixed>|null */
	private function read_checkpoint( $path ) {
		$h = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Fixed private journal slot; missing slots are normal.
		if ( ! is_resource( $h ) ) {
			return null;
		}
		try {
			if ( ! $this->secure_file( $h, $path ) ) {
				return null;
			}
			$raw  = stream_get_contents( $h, 12288 );
			$wire = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( ! is_array( $wire ) || ! is_int( $wire['generation'] ?? null ) ||
				$wire['generation'] < 1 || ! is_string( $wire['body'] ?? null ) ||
				! is_string( $wire['sha256'] ?? null ) ||
				! hash_equals( hash( 'sha256', $wire['body'] ), $wire['sha256'] ) ) {
				return null;
			}
			$body = json_decode( $wire['body'], true );
			return is_array( $body ) && ( $body['generation'] ?? null ) === $wire['generation']
				? array(
					'generation' => $wire['generation'],
					'body'       => $body,
				) : null;
		} finally {
			fclose( $h );
		}
	}

	/** @param string $root Private root. @param string $id Lease. @return array<string,mixed>|null|WP_Error */
	private function read_claim_fence( $root, $id ) {
		$path = $root . '/' . $id . '.claim';
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return null;
		}
		$h = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- One-way private native-effect fence.
		if ( ! is_resource( $h ) ) {
			return new WP_Error( 'transfer_outcome_unknown', 'Claim fence is unavailable; no native retry is safe.' );
		}
		try {
			if ( ! $this->secure_file( $h, $path ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Claim fence identity changed; no native retry is safe.' );
			}
			$wire = json_decode( (string) stream_get_contents( $h, 2048 ), true );
			if ( ! is_array( $wire ) ||
				! in_array( $wire['consumer'] ?? '', array( 'media', 'content', 'wxr', 'source' ), true ) ||
				! is_string( $wire['intent'] ?? null ) ||
				! is_int( $wire['target_id'] ?? null ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Claim fence is incomplete; no native retry is safe.' );
			}
			return $wire;
		} finally {
			fclose( $h );
		}
	}

	/**
	 * Persist a one-way native-effect fence on stable storage before Core write.
	 * Failure is unknown and never permits a second consumer.
	 *
	 * @param string $root Private root.
	 * @param string $id Lease.
	 * @param string $consumer Effect type.
	 * @param string $intent create/update/import.
	 * @param int $target_id Existing target or zero.
	 * @return bool
	 */
	private function fence_native_effect( $root, $id, $consumer, $intent, $target_id ) {
		// The final claim name MUST NOT become visible until its full
		// immutable contents are flushed. The stable .meta coordinator is
		// held by the caller across publication and the subsequent Core effect.
		$path = $root . '/' . $id . '.claim';
		if ( file_exists( $path ) || is_link( $path ) ) {
			return false;
		}
		$temporary = $path . '.pending.' . bin2hex( random_bytes( 8 ) );
		$h         = @fopen( $temporary, 'x+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Owner-only unpublished claim, exclusive creation.
		if ( ! is_resource( $h ) ) {
			return false;
		}
		$complete = false;
		try {
			$wire = wp_json_encode(
				array(
					'consumer'  => $consumer,
					'intent'    => $intent,
					'target_id' => $target_id,
				)
			);
			if ( ! is_string( $wire ) || ! $this->secure_file( $h, $temporary ) ) {
				return false;
			}
			$offset = 0;
			while ( $offset < strlen( $wire ) ) {
				$written = fwrite( $h, substr( $wire, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded unpublished private claim.
				if ( ! is_int( $written ) || $written < 1 ) {
					return false;
				}
				$offset += $written;
			}
			$complete = fflush( $h ) && function_exists( 'fsync' ) && fsync( $h );
		} finally {
			fclose( $h );
			if ( ! $complete ) {
				$this->remove_file( $temporary );
			}
		}
		if ( ! $complete ) {
			return false;
		}
		// The per-lease coordinator prevents a concurrent claimant from
		// racing this final-name check. Never replace an existing fence.
		if ( file_exists( $path ) || is_link( $path ) ) {
			$this->remove_file( $temporary );
			return false;
		}
		if ( ! rename( $temporary, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-directory claim publication under stable per-lease lock.
			$this->remove_file( $temporary );
			return false;
		}
		// A successful rename and readback prove visibility, NOT durability
		// across machine/power loss. Synchronize the containing directory's
		// entry before allowing any irreversible WordPress native effect.
		// If unsupported/failed, keep the final claim and fail CLOSED.
		if ( ! $this->durably_sync_claim_directory( $root ) ) {
			return false;
		}
		$published = $this->read_claim_fence( $root, $id );
		return is_array( $published ) &&
			$consumer === $published['consumer'] &&
			$intent === $published['intent'] &&
			$target_id === $published['target_id'];
	}

	/**
	 * Synchronize the exact private claim directory, including its just-renamed
	 * final filename. fopen() of a directory as an r-stream is supported by
	 * Linux/PHP's native file wrapper; other backends must fail closed.
	 *
	 * @param string $root Previously validated 0700, non-symlink private root.
	 * @return bool Directory entry was synchronously flushed to backing storage.
	 */
	private function durably_sync_claim_directory( $root ) {
		if ( ! function_exists( 'fsync' ) || ! is_dir( $root ) || is_link( $root ) ) {
			return false;
		}
		$directory = @fopen( $root, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- The private owner-only directory itself must be fsync'ed after atomic claim publication.
		if ( ! is_resource( $directory ) ) {
			return false;
		}
		try {
			$stat = fstat( $directory );
			return is_array( $stat ) && 0040000 === ( $stat['mode'] & 0170000 ) &&
				@fsync( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unsupported directory fsync must fail closed before the WordPress effect.
		} finally {
			fclose( $directory );
		}
	}

	/**
	 * Lock and irreversibly claim one exact verified WXR lease. Keep the stable
	 * session lock held across the *entire* native WordPress Importer call;
	 * cancellation, competing effects and byte release cannot race the parser.
	 * A fatal exit leaves an immutable claim and an unknown receipt, NEVER a
	 * retryable verified lease. No source path is returned to MCP clients.
	 *
	 * @param string $id Opaque inbound WXR lease.
	 * @param string $plan_hash Exact authenticated, validated import plan.
	 * @return array<string,mixed>|WP_Error Locked private descriptor.
	 */
	public function claim_wxr_import( $id, $plan_hash ) {
		if ( ! is_string( $plan_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $plan_hash ) ) {
			return $this->invalid();
		}
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		$claimed                      = false;
		try {
			if ( 'wxr' !== $meta['purpose'] || ! $this->allowed( 'wxr' ) ) {
				return $this->denied();
			}
			if ( 'verified' !== $meta['state'] ) {
				return new WP_Error( 'transfer_consume_uncertain', 'WXR lease already claimed; inspect status and WordPress objects, never repeat import.' );
			}
			$path = $this->check_file( $root, $id, $meta );
			if ( is_wp_error( $path ) || ! hash_equals( $meta['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				return new WP_Error( 'transfer_source_changed', 'WXR source changed before native import.' );
			}
			if ( ! $this->fence_native_effect( $root, $id, 'wxr', 'import', 0 ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Native WXR claim could not be durably fenced; never retry.' );
			}
			$meta['state']          = 'committing';
			$meta['consumer']       = 'wxr';
			$meta['intent']         = 'import';
			$meta['target_id']      = 0;
			$meta['outcome']        = 'unknown';
			$meta['destination_id'] = 0;
			$meta['plan_hash']      = $plan_hash;
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'WXR claim was published but checkpoint could not be saved; never retry.' );
			}
			$claimed = true;
			return array(
				'id'     => $id,
				'path'   => $path,
				'bytes'  => $meta['total'],
				'sha256' => $meta['sha256'],
				'lock'   => $handle,
				'meta'   => $meta,
			);
		} finally {
			if ( ! $claimed ) {
				$this->close_meta( $handle );
			}
		}
	}

	/**
	 * Record the native importer's observed outcome while holding its
	 * original coordinator. No claim is ever removed or made retryable.
	 *
	 * @param array<string,mixed> $claim Internal locked claim.
	 * @param int $items Planned WXR item count.
	 * @param int $processed Actually mapped native post count (may include preexisting).
	 * @param bool $finished Whether official Importer returned normally.
	 * @return array<string,mixed>|WP_Error Outcome and durable receipt.
	 */
	public function finish_wxr_import( $claim, $items, $processed, $finished ) {
		if ( ! is_array( $claim ) || ! is_resource( $claim['lock'] ?? null ) ) {
			return new WP_Error( 'transfer_outcome_unknown', 'WXR native claim lock is absent.' );
		}
		$handle = $claim['lock'];
		try {
			$meta = $claim['meta'];
			if ( 'wxr' !== $meta['purpose'] || 'committing' !== $meta['state'] ||
				'wxr' !== ( $meta['consumer'] ?? '' ) ||
				! is_int( $items ) || $items < 0 ||
				! is_int( $processed ) || $processed < 0 || $processed > $items ||
				! is_bool( $finished ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Native WXR outcome cannot be certified.' );
			}
			$meta['import_items']     = $items;
			$meta['import_processed'] = $processed;
			$meta['state']            = $finished && $processed === $items ? 'committed' : 'observed';
			$meta['outcome']          = ! $finished ? 'partial_or_unknown' :
				( $processed === $items ? 'provider_returned' : 'partial_observed' );
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'WXR importer returned but durable receipt is uncertain.' );
			}
			return $this->receipt( $meta );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/** @param array<string,mixed> $claim Locked WXR source after interruption. */
	public function abandon_wxr_import( $claim ) {
		if ( is_array( $claim ) && is_resource( $claim['lock'] ?? null ) ) {
			$this->close_meta( $claim['lock'] );
		}
	}

	/**
	 * Claim one verified private source for its one permitted native Media
	 * effect. Persist the uncertain state BEFORE any WordPress operation.
	 * No automatic retry is allowed after claiming, even across process death.
	 *
	 * @param string $id Lease.
	 * @return array<string,mixed>|WP_Error Internal-only source descriptor.
	 */
	public function claim_media( $id ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( 'media' !== $meta['purpose'] || ! $this->allowed( 'media' ) ) {
				return $this->denied();
			}
			if ( 'verified' !== $meta['state'] ) {
				return new WP_Error( 'transfer_consume_uncertain', 'This transfer cannot be consumed again. Inspect its recorded outcome and the WordPress Media Library.' );
			}
			$path = $this->check_file( $root, $id, $meta );
			if ( is_wp_error( $path ) || ! hash_equals( $meta['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				return new WP_Error( 'transfer_source_changed', 'The staged source changed before Media import.' );
			}
			if ( ! $this->fence_native_effect( $root, $id, 'media', 'import', 0 ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Native Media claim could not be durably fenced; do not retry blindly.' );
			}
			$meta['state']          = 'committing';
			$meta['consumer']       = 'media';
			$meta['intent']         = 'import';
			$meta['target_id']      = 0;
			$meta['outcome']        = 'unknown';
			$meta['destination_id'] = 0;
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'The private Media import claim could not be durably recorded. Do not retry blindly.' );
			}
			return array(
				'path'   => $path,
				'bytes'  => $meta['total'],
				'sha256' => $meta['sha256'],
				'id'     => $meta['id'],
			);
		} finally {
			$this->close_meta( $handle );
		}
	}

	/**
	 * Reserve one and only one scratch copy under the same admission budget
	 * as persistent staging. The stable per-lease .meta flock stays held until
	 * WordPress finishes its native sideload; a second consumer cannot copy or
	 * claim the same bytes while the first worker is using its private copy.
	 *
	 * @param string $id Verified authorized Media lease.
	 * @return array<string,mixed>|WP_Error Internal-only copy + held lease.
	 */
	public function prepare_media_sideload( $id ) {
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$guard = $this->guard( $root );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$claim = null;
		try {
			$policy  = ( new Transfer_Settings() )->bytes();
			$used    = 0;
			$entries = new \FilesystemIterator( $root, \FilesystemIterator::SKIP_DOTS );
			foreach ( $entries as $entry ) {
				$name = $entry->getFilename();
				if ( ! preg_match( '/^[a-f0-9]{64}\\.meta$/D', $name ) ) {
					continue;
				}
				$owner = substr( $name, 0, 64 );
				if ( file_exists( $root . '/' . $owner . '.tombstone' ) ) {
					if ( ! $this->erase( $root, $owner ) ) {
						return new WP_Error( 'transfer_cleanup_uncertain', 'Interrupted private cancellation could not be reconciled.' );
					}
					continue;
				}
				$info = $this->admission_meta( $root, $owner );
				if ( is_wp_error( $info ) ) {
					return $info;
				}
				list( $handle, $meta ) = $info;
				if ( is_resource( $handle ) && ! $this->recover_scratch( $root, $owner, $handle, $meta ) ) {
					$this->close_meta( $handle );
					return new WP_Error( 'transfer_cleanup_uncertain', 'An abandoned private Media scratch reservation could not be reconciled.' );
				}
				if ( empty( $meta['payload_released'] ) ) {
					$size = $meta['total'] + (int) ( $meta['scratch_reserved'] ?? 0 );
					if ( $size < $meta['total'] || $size > $policy['active'] - $used ) {
						$this->close_meta( $handle );
						return new WP_Error( 'transfer_capacity', 'Configured aggregate byte reservations cannot admit another native Media copy.' );
					}
					$used += $size;
				}
				$this->close_meta( $handle );
			}
			$loaded = $this->load( $id, false, true );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			list( $session_root, $handle, $meta ) = $loaded;
			if ( 'media' !== $meta['purpose'] || 'verified' !== $meta['state'] ||
				! $this->allowed( 'media' ) || ! empty( $meta['scratch_reserved'] ) ||
				$meta['total'] > $policy['active'] - $used ) {
				$this->close_meta( $handle );
				return new WP_Error( 'transfer_capacity', 'Verified Media lease is already in preparation or current scratch capacity is insufficient.' );
			}
			$free = disk_free_space( $root );
			if ( ! is_int( $free ) && ! is_float( $free ) || $free < $meta['total'] ) {
				$this->close_meta( $handle );
				return new WP_Error( 'transfer_disk_insufficient', 'Actual private scratch disk capacity is insufficient for native Media import.' );
			}
			$path = $this->check_file( $root, $id, $meta );
			if ( is_wp_error( $path ) || ! hash_equals( $meta['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				$this->close_meta( $handle );
				return new WP_Error( 'transfer_source_changed', 'Verified Media source changed before scratch reservation.' );
			}
			$meta['state']            = 'preparing';
			$meta['scratch_reserved'] = $meta['total'];
			if ( ! $this->save_meta( $handle, $meta ) ) {
				$this->close_meta( $handle );
				return new WP_Error( 'transfer_storage_uncertain', 'Private Media scratch reservation was not durably recorded.' );
			}
			$claim = array(
				'lease_id' => $id,
				'temp'     => $root . '/' . $id . '.scratch',
				'path'     => $path,
				'lock'     => $handle,
				'meta'     => $meta,
				'bytes'    => $meta['total'],
				'sha256'   => $meta['sha256'],
			);
		} catch ( \UnexpectedValueException $error ) {
			return new WP_Error( 'transfer_storage_unavailable', 'Private transfer scratch reservations could not be enumerated.' );
		} finally {
			flock( $guard, LOCK_UN );
			fclose( $guard );
		}
		$source   = @fopen( $claim['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Verified locked private bytes.
		$target   = @fopen( $claim['temp'], 'x+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive exact private scratch under reserved disk budget.
		$prepared = false;
		try {
			if ( ! is_resource( $source ) || ! is_resource( $target ) ||
				! $this->secure_file( $source, $claim['path'] ) ||
				! $this->secure_file( $target, $claim['temp'] ) ) {
				return new WP_Error( 'transfer_storage_unavailable', 'Private Media copy could not be opened safely; the source is not yet consumed.' );
			}
			$copied  = stream_copy_to_stream( $source, $target );
			$flushed = fflush( $target ) && ( ! function_exists( 'fsync' ) || fsync( $target ) );
			fclose( $source );
			$source = null;
			fclose( $target );
			$target = null;
			if ( $copied !== $claim['bytes'] || ! $flushed ||
				! hash_equals( $claim['sha256'], (string) hash_file( 'sha256', $claim['temp'] ) ) ) {
				return new WP_Error( 'transfer_source_changed', 'Private Media scratch verification failed before any native effect.' );
			}
			// Claim fencing is one-way and durably precedes the native call.
			if ( ! $this->fence_native_effect( $root, $id, 'media', 'import', 0 ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Native Media claim fence is uncertain; never retry this lease blindly.' );
			}
			$meta                   = $claim['meta'];
			$meta['state']          = 'committing';
			$meta['consumer']       = 'media';
			$meta['intent']         = 'import';
			$meta['target_id']      = 0;
			$meta['outcome']        = 'unknown';
			$meta['destination_id'] = 0;
			if ( ! $this->save_meta( $claim['lock'], $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Private Media claim checkpoint failed after one-way fence.' );
			}
			$claim['meta'] = $meta;
			$prepared      = true;
			return $claim;
		} finally {
			if ( is_resource( $source ) ) {
				fclose( $source );
			}
			if ( is_resource( $target ) ) {
				fclose( $target );
			}
			if ( ! $prepared ) {
				$this->cleanup_media_temporary( $claim['temp'], $claim );
			}
		}
	}

	/**
	 * Reconcile abandoned scratch under the stable per-lease coordinator.
	 * Admission additionally holds the global guard; an explicit same-site
	 * administrator release holds only this lease lock. Neither may delete
	 * an actively owned native Media temporary file.
	 *
	 * @param string $root Private storage.
	 * @param string $id Lease ID.
	 * @param resource $handle Stable per-lease lock.
	 * @param array<string,mixed> $meta In/out checkpoint.
	 * @return bool
	 */
	private function recover_scratch( $root, $id, $handle, &$meta ) {
		$path = $root . '/' . $id . '.scratch';
		clearstatcache( true, $path );
		if ( empty( $meta['scratch_reserved'] ) && ! file_exists( $path ) && ! is_link( $path ) ) {
			return true;
		}
		// If the old process still owns the lock, open_meta cannot return.
		// Therefore this reservation is not being used by WordPress now.
		if ( ! $this->remove_file( $path ) ) {
			return false;
		}
		$meta['scratch_reserved'] = 0;
		if ( 'preparing' === $meta['state'] ) {
			$meta['state'] = 'verified';
		}
		return $this->save_meta( $handle, $meta );
	}

	/**
	 * Release ALL exact private payload representations (including abandoned
	 * Media scratch) while holding the original per-lease coordinator. A
	 * successful acknowledgement is never returned while private bytes remain.
	 * Do not remove native-effect claims or operation identity receipts.
	 *
	 * @param string $root Owner-only private storage root.
	 * @param string $id Exact opaque lease identifier.
	 * @param resource $handle Held per-lease coordinator.
	 * @param array<string,mixed> $meta Updated only on durable checkpoint.
	 * @return true|WP_Error
	 */
	private function release_private_payload( $root, $id, $handle, &$meta ) {
		if ( ! $this->recover_scratch( $root, $id, $handle, $meta ) ) {
			return new WP_Error( 'transfer_cleanup_uncertain', 'Private Media scratch cleanup could not be verified; reservation remains active.' );
		}
		if ( ! $this->remove_file( $root . '/' . $id . '.bytes' ) ) {
			return new WP_Error( 'transfer_cleanup_uncertain', 'Private staged bytes could not be verified as removed; reservation remains active.' );
		}
		if ( empty( $meta['payload_released'] ) ) {
			$meta['payload_released'] = true;
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_cleanup_uncertain', 'Private payload removal succeeded but the release receipt could not be checkpointed.' );
			}
		}
		return true;
	}

	/**
	 * Release a tracked scratch reservation only after native sideload has
	 * returned. The stable lock is held for the WHOLE native operation.
	 *
	 * @param string $temp Exact internally generated scratch path.
	 * @param array<string,mixed> $claim Locked internal descriptor.
	 * @return bool
	 */
	public function cleanup_media_temporary( $temp, $claim = array() ) {
		if ( ! is_array( $claim ) || ! is_resource( $claim['lock'] ?? null ) ) {
			return false;
		}
		$handle = $claim['lock'];
		$meta   = $claim['meta'];
		$root   = $this->root();
		try {
			if ( is_wp_error( $root ) || ! is_string( $temp ) ||
				$temp !== $root . '/' . $claim['lease_id'] . '.scratch' ||
				! $this->remove_file( $temp ) ) {
				return false;
			}
			$meta['scratch_reserved'] = 0;
			if ( 'preparing' === $meta['state'] ) {
				$meta['state'] = 'verified';
			}
			return $this->save_meta( $handle, $meta );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/**
	 * Record a native Media effect after the saved attachment bytes are
	 * independently verified. Failure preserves the claimed/unknown state.
	 *
	 * @param string $id Lease identity.
	 * @param int    $attachment_id Verified native WordPress ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function finish_media( $id, $attachment_id ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( 'media' !== $meta['purpose'] || 'committing' !== $meta['state'] ||
				! is_int( $attachment_id ) || $attachment_id < 1 ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Native Media result identity was not established.' );
			}
			// Native Media has returned an ID, which is evidence even if
			// subsequent image conversion or auth changes prevent verification.
			$meta['destination_id'] = $attachment_id;
			$meta['outcome']        = 'observed';
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Observed attachment ID could not be checkpointed.' );
			}
			$attachment = get_post( $attachment_id );
			$current    = get_attached_file( $attachment_id );
			$original   = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $attachment_id ) : false;
			$path       = is_string( $original ) && '' !== $original ? $original : $current;
			$uploads    = wp_get_upload_dir();
			$basedir    = realpath( (string) ( $uploads['basedir'] ?? '' ) );
			$real       = is_string( $path ) ? realpath( $path ) : false;
			if ( ! $this->allowed( 'media' ) || ! current_user_can( 'edit_post', $attachment_id ) ||
				! $attachment || 'attachment' !== $attachment->post_type ||
				! is_string( $basedir ) || ! is_string( $real ) ||
				! str_starts_with( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $basedir ) ) ) ||
				! is_file( $real ) || is_link( $real ) ||
				(int) filesize( $real ) !== $meta['total'] ||
				! hash_equals( $meta['sha256'], (string) hash_file( 'sha256', $real ) ) ) {
				$meta['state']   = 'observed';
				$meta['outcome'] = 'observed_changed';
				if ( ! $this->save_meta( $handle, $meta ) ) {
					return new WP_Error( 'transfer_outcome_unknown', 'Observed Media identity was saved but verification and receipt were uncertain.' );
				}
				return new WP_Error( 'transfer_native_transformed', 'WordPress returned a known Media object but original uploaded bytes could not be fully verified.' );
			}
			$meta['source_representation'] = is_string( $original ) && '' !== $original ? 'original_upload' : 'current_media';
			$meta['state']                 = 'committed';
			$meta['outcome']               = 'verified';
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'WordPress Media was saved but the private receipt is uncertain; inspect the destination.' );
			}
			return $this->receipt( $meta );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/** @param string $id Exact opaque id. @return array<int,mixed>|WP_Error */
	private function load( $id, $allow_tombstone = false, $nonblocking = false ) {
		// Database consumers can retain several sources for one callback. A
		// reentrant stage action must report busy rather than wait on its own
		// coordinator. Generic transfer waiting behavior is unchanged.
		$nonblocking = $nonblocking || $this->database_only;
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $id ) ) {
			return $this->invalid();
		}
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		if ( ! $allow_tombstone &&
			( file_exists( $root . '/' . $id . '.tombstone' ) ||
				is_link( $root . '/' . $id . '.tombstone' ) ) ) {
			return new WP_Error( 'transfer_cancelled', 'This transfer has a durable cancellation tombstone.' );
		}
		$info = $this->open_meta( $root, $id, $allow_tombstone, $nonblocking );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		list( $h, $meta ) = $info;
		if ( (int) get_current_blog_id() !== $meta['blog'] ||
			(int) get_current_user_id() !== $meta['user'] ||
			OAuth_Server::authenticated_mcp_client_id() !== $meta['client'] ||
			! $this->allowed( $meta['purpose'] ) ||
			( null !== $this->native_options_scope &&
				( ! is_int( $meta['source_id'] ?? null ) ||
					$meta['source_id'] !== $this->native_binding()['source_id'] ||
					(int) get_current_blog_id() !== $meta['blog'] ||
					(int) get_current_user_id() !== $meta['user'] ||
					OAuth_Server::authenticated_mcp_client_id() !== $meta['client'] ) ) ) {
			$this->close_meta( $h );
			return $this->denied();
		}
		if ( $meta['expires'] <= time() && ! in_array( $meta['state'], array( 'committing', 'observed' ), true ) ) {
			$this->close_meta( $h );
			return new WP_Error( 'transfer_expired', 'The private transfer lease expired; a new transfer is required.' );
		}
		return array( $root, $h, $meta );
	}

	/**
	 * Publish a complete, checked generation atomically. The stable .meta
	 * coordinator remains locked; never truncate or replace its inode.
	 *
	 * @param resource $h Stable locked manifest.
	 * @param array<string,mixed> $meta Snapshot to commit.
	 * @return bool
	 */
	private function save_meta( $h, &$meta ) {
		$identity = stream_get_meta_data( $h );
		$path     = $identity['uri'] ?? '';
		if ( ! is_string( $path ) || ! str_ends_with( $path, '.meta' ) ) {
			return false;
		}
		$next = (int) ( $meta['generation'] ?? 0 ) + 1;
		if ( $next < 1 || $next >= PHP_INT_MAX ) {
			return false;
		}
		$next_meta               = $meta;
		$next_meta['generation'] = $next;
		$body                    = wp_json_encode( $next_meta );
		if ( ! is_string( $body ) || strlen( $body ) > 4096 ) {
			return false;
		}
		$wire = wp_json_encode(
			array(
				'generation' => $next,
				'sha256'     => hash( 'sha256', $body ),
				'body'       => $body,
			)
		);
		if ( ! is_string( $wire ) || strlen( $wire ) > 12288 ) {
			return false;
		}
		$dest = $path . '.state.' . ( $next % 2 );
		$temp = $dest . '.tmp.' . bin2hex( random_bytes( 8 ) );
		$out  = @fopen( $temp, 'x+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive unpublished journal generation in private root.
		if ( ! is_resource( $out ) ) {
			return false;
		}
		$written = false;
		try {
			if ( ! $this->secure_file( $out, $temp ) ) {
				return false;
			}
			$offset = 0;
			while ( $offset < strlen( $wire ) ) {
				$size = fwrite( $out, substr( $wire, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded unpublished checkpoint bytes.
				if ( ! is_int( $size ) || $size < 1 ) {
					return false;
				}
				$offset += $size;
			}
			$written = fflush( $out ) && ( ! function_exists( 'fsync' ) || fsync( $out ) );
		} finally {
			fclose( $out );
			if ( ! $written ) {
				$this->remove_file( $temp );
			}
		}
		if ( ! $written ) {
			return false;
		}
		// Atomic publication; a killed process leaves the previous slot valid.
		if ( ! rename( $temp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Verified private, same-directory atomic journal publication.
			$this->remove_file( $temp );
			return false;
		}
		$reloaded = $this->read_checkpoint( $dest );
		if ( ! is_array( $reloaded ) || $next !== $reloaded['generation'] ) {
			return false;
		}
		$meta = $next_meta;
		return true;
	}

	/** @param resource $h Locked metadata handle. @return void */
	private function close_meta( $h ) {
		if ( is_resource( $h ) ) {
			flock( $h, LOCK_UN );
			fclose( $h );
		}
	}

	/** @param resource $handle Data stream. @param int $expected Exact committed bytes. @return bool */
	private function file_length( $handle, $expected ) {
		$stat = fstat( $handle );
		return is_array( $stat ) && (int) $stat['size'] === $expected;
	}

	/** @param string $root Root. @param string $id Session. @param array<string,mixed> $meta State. @return string|WP_Error */
	private function check_file( $root, $id, $meta ) {
		$path = $root . '/' . $id . '.bytes';
		if ( ! empty( $meta['payload_released'] ) ) {
			return new WP_Error( 'transfer_payload_released', 'Native effect receipt is retained but its private payload has already been released.' );
		}
		$h = @fopen( $path, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Exact leased private bytes; opened under stable session coordinator.
		if ( false === $h ) {
			return new WP_Error( 'transfer_storage_uncertain', 'Private transfer bytes are unavailable.' );
		}
		$valid = $this->secure_file( $h, $path );
		if ( $valid ) {
			$stat = fstat( $h );
			$size = is_array( $stat ) ? $stat['size'] : -1;
			// A killed writer may have flushed data after the last
			// published received offset. Only OPEN unconsumed leases may
			// discard that *unacknowledged* tail; never truncate an effect.
			if ( 'open' === $meta['state'] && is_int( $size ) && $size > $meta['received'] ) {
				$valid = ftruncate( $h, $meta['received'] ) && fflush( $h ) &&
					( ! function_exists( 'fsync' ) || fsync( $h ) );
			}
			$valid = $valid && $this->file_length( $h, $meta['received'] );
		}
		fclose( $h );
		return $valid ? $path : new WP_Error( 'transfer_storage_uncertain', 'Private transfer source length or identity changed.' );
	}

	/** @param string $root Root. @param string $id Session. @return bool */
	private function erase( $root, $id ) {
		// Crash-safe deletion marker: after any worker termination between
		// deleting checkpoints and the stable lock inode, the lease cannot
		// silently fall back to an unclaimed legacy manifest.
		$marker = $root . '/' . $id . '.tombstone';
		if ( ! file_exists( $marker ) ) {
			$h = @fopen( $marker, 'x+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Durable one-way lease deletion marker.
			if ( ! is_resource( $h ) ) {
				return false;
			}
			$secure = $this->secure_file( $h, $marker ) && 1 === fwrite( $h, '1' ) &&
				fflush( $h ) && ( ! function_exists( 'fsync' ) || fsync( $h ) );
			fclose( $h );
			if ( ! $secure ) {
				return false;
			}
		}
		foreach ( array( '.scratch', '.bytes', '.meta.state.0', '.meta.state.1', '.claim', '.meta' ) as $suffix ) {
			if ( ! $this->remove_file( $root . '/' . $id . $suffix ) ) {
				return false;
			}
		}
		return $this->remove_file( $marker );
	}

	/** @param string $path Fixed private file. @return bool */
	private function remove_file( $path ) {
		clearstatcache( true, $path );
		if ( is_link( $path ) ) {
			return false;
		}
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( ! is_file( $path ) ) {
			return false;
		}
		wp_delete_file( $path );
		clearstatcache( true, $path );
		return ! file_exists( $path ) && ! is_link( $path );
	}

	/** @param array<string,mixed> $meta Private metadata. @return array<string,mixed> */
	private function receipt( $meta ) {
		return array(
			'id'                    => $meta['id'],
			'purpose'               => $meta['purpose'],
			'total'                 => $meta['total'],
			'received'              => $meta['received'],
			'chunk'                 => $meta['chunk'],
			'complete'              => $meta['received'] === $meta['total'],
			'state'                 => $meta['state'],
			'sha256'                => $meta['sha256'],
			'expires'               => $meta['expires'],
			'destination_id'        => (int) ( $meta['destination_id'] ?? 0 ),
			'source_id'             => (int) ( $meta['source_id'] ?? 0 ),
			'outcome'               => (string) ( $meta['outcome'] ?? 'none' ),
			'intent'                => (string) ( $meta['intent'] ?? '' ),
			'target_id'             => (int) ( $meta['target_id'] ?? 0 ),
			'source_representation' => (string) ( $meta['source_representation'] ?? '' ),
			'payload_released'      => ! empty( $meta['payload_released'] ),
			'plan_hash'             => (string) ( $meta['plan_hash'] ?? '' ),
			'import_items'          => (int) ( $meta['import_items'] ?? 0 ),
			'import_processed'      => (int) ( $meta['import_processed'] ?? 0 ),
		);
	}
}
