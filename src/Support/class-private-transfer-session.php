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

	/** @param Permissions $permissions Bridge current principal policy. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/**
	 * @param string $purpose media, text, or wxr.
	 * @param int    $total Exact source byte count including zero.
	 * @param string $sha256 Lowercase expected SHA-256.
	 * @param int    $max_total Explicit operator/caller file budget.
	 * @param int    $max_chunk Explicit maximum raw bytes per continuation.
	 * @param int    $active_limit Maximum reserved concurrent sessions for a site.
	 * @param int    $ttl_seconds Finite retention in seconds.
	 * @return array<string,mixed>|WP_Error No private path in return.
	 */
	public function begin( $purpose, $total, $sha256, $max_total, $max_chunk, $active_limit, $ttl_seconds, $max_active = null ) {
		if ( ! $this->allowed( $purpose ) ) {
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
					if ( ! preg_match( '/^[a-f0-9]{64}\\.meta$/D', $name ) ) {
						continue;
					}
					$id   = substr( $name, 0, 64 );
					$info = $this->open_meta( $root, $id );
					if ( is_wp_error( $info ) ) {
						return new WP_Error( 'transfer_storage_uncertain', 'Private transfer state is inconsistent; inspect storage before beginning another transfer.' );
					}
					list( $handle, $meta ) = $info;
					if ( $meta['expires'] <= time() && 'committing' !== $meta['state'] ) {
						if ( ! $this->erase( $root, $id ) ) {
							$this->close_meta( $handle );
							return new WP_Error( 'transfer_cleanup_uncertain', 'Expired private transfer bytes could not be fully deleted. Manual cleanup is required.' );
						}
					} else {
						++$count;
						// Subtract before adding to prevent integer overflow.
						if ( $meta['total'] > $max_active - $used ) {
							$this->close_meta( $handle );
							return new WP_Error( 'transfer_capacity', 'Active private transfer reservation budget reached; adjust the administrator setting or complete/cancel older leases.' );
						}
						$used    += $meta['total'];
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

			$id       = bin2hex( random_bytes( 32 ) );
			$meta     = array(
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
			$data     = $root . '/' . $id . '.bytes';
			$manifest = $root . '/' . $id . '.meta';
			$file     = @fopen( $data, 'x+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Randomly generated private root, exclusive creation.
			if ( false === $file ) {
				return new WP_Error( 'transfer_storage_unavailable', 'Could not allocate private transfer bytes.' );
			}
			if ( ! $this->secure_file( $file, $data ) ) {
				fclose( $file );
				$this->remove_file( $data );
				return new WP_Error( 'transfer_storage_unsafe', 'Cannot enforce private transfer file permissions.' );
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
		$result = $this->check_file( $root, $id, $meta );
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
		$lease = $this->begin( 'media_read', $size, $sha256, $policy['file'], $chunk, $policy['sessions'], $policy['ttl'], $policy['active'] );
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
				if ( 'verified' !== $meta['state'] || 'media_read' !== $meta['purpose'] ||
					! current_user_can( 'read_post', $attachment_id ) ||
					'attachment' !== get_post_type( $attachment_id ) ) {
					return $this->denied();
				}
				$meta['source_id'] = $attachment_id;
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
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		try {
			if ( 'media_read' !== $meta['purpose'] || 'verified' !== $meta['state'] ||
				! $this->allowed( 'media_read' ) ||
				! is_int( $meta['source_id'] ?? null ) ||
				'attachment' !== get_post_type( $meta['source_id'] ) ||
				! current_user_can( 'read_post', $meta['source_id'] ) ||
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
	public function claim_content( $id ) {
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
			$meta['state']          = 'committing';
			$meta['consumer']       = 'content';
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
			$post = get_post( $post_id );
			if ( 'text' !== $meta['purpose'] || 'committing' !== $meta['state'] ||
				'content' !== ( $meta['consumer'] ?? '' ) ||
				! is_int( $post_id ) || $post_id < 1 ||
				! $post || ! current_user_can( 'edit_post', $post_id ) ||
				! $this->allowed( 'text' ) ||
				strlen( (string) $post->post_content ) !== $meta['total'] ||
				! hash_equals( $meta['sha256'], hash( 'sha256', (string) $post->post_content ) ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'WordPress text persisted with an unverified result; inspect the post, never retry blindly.' );
			}
			$meta['state']          = 'committed';
			$meta['destination_id'] = $post_id;
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'WordPress text was saved, but the private receipt could not be committed.' );
			}
			return $this->receipt( $meta );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/** @param string $id Opaque session. @return bool|WP_Error */
	public function cancel( $id ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle, $meta ) = $loaded;
		if ( 'committing' === $meta['state'] ) {
			$this->close_meta( $handle );
			return new WP_Error( 'transfer_outcome_unknown', 'A WordPress Media effect may be in progress or unacknowledged. Inspect the destination before removing its receipt.' );
		}
		$deleted = $this->erase( $root, $id );
		$this->close_meta( $handle );
		return $deleted ? true : new WP_Error( 'transfer_cleanup_uncertain', 'Private transfer deletion could not be verified; inspect and retry cleanup.' );
	}

	/** @param string $purpose Explicit caller workflow. @return bool */
	private function allowed( $purpose ) {
		if ( 0 === get_current_user_id() ) {
			return false;
		}
		switch ( $purpose ) {
			case 'media':
				return $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'upload_files' );
			case 'media_read':
				return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
			case 'text':
				return $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'edit_posts' );
			case 'wxr':
				return $this->permissions->allowed_all( array( array( Settings::GROUP_SITE_CONFIG, 'import' ), array( Settings::GROUP_BUILDER_WRITE, 'edit_posts' ) ) );
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
		$base = realpath( get_temp_dir() );
		if ( ! is_string( $base ) || ! is_dir( $base ) || ! is_writable( $base ) ) {
			return new WP_Error( 'transfer_storage_unavailable', 'No writable temporary private storage is available.' );
		}
		$label = hash( 'sha256', serialize( array( DB_NAME, ABSPATH, get_current_blog_id(), wp_salt( 'auth' ) ) ) );
		$root  = $base . '/wpai-transfer-' . substr( $label, 0, 40 );
		foreach ( array( ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? '' ) as $webroot ) {
			$real = is_string( $webroot ) ? realpath( $webroot ) : false;
			if ( is_string( $real ) && 0 === strpos( wp_normalize_path( $root ) . '/', trailingslashit( wp_normalize_path( $real ) ) ) ) {
				return new WP_Error( 'transfer_storage_unsafe', 'The private transfer root must be outside the WordPress web directory.' );
			}
		}
		if ( ! is_dir( $root ) && ! @mkdir( $root, 0700 ) && ! is_dir( $root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Fixed site-secret directory, outside webroot.
			return new WP_Error( 'transfer_storage_unavailable', 'Cannot create an owner-only private transfer directory.' );
		}
		if ( is_link( $root ) || realpath( $root ) !== $root || ! chmod( $root, 0700 ) ||
			0700 !== ( fileperms( $root ) & 0777 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Enforce private root permission.
			return new WP_Error( 'transfer_storage_unsafe', 'Cannot validate the private transfer directory.' );
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
		return chmod( $path, 0600 ) && 0600 === ( fileperms( $path ) & 0777 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Owner-only private stream.
	}

	/** @param string $root Root. @param string $id Opaque session. @return array<int,mixed>|WP_Error */
	private function open_meta( $root, $id ) {
		$path = $root . '/' . $id . '.meta';
		$h    = @fopen( $path, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Exact opaque file in owner-only private root.
		if ( false === $h || ! $this->secure_file( $h, $path ) || ! flock( $h, LOCK_EX ) ) {
			if ( is_resource( $h ) ) {
				fclose( $h );
			}
			return new WP_Error( 'transfer_storage_uncertain', 'Private transfer manifest could not be safely opened.' );
		}
		$raw  = stream_get_contents( $h, 8192 );
		$meta = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $meta ) || ( $meta['version'] ?? null ) !== 1 || ( $meta['id'] ?? null ) !== $id ||
			! isset(
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
			) ||
			! is_int( $meta['total'] ) || ! is_int( $meta['received'] ) || ! is_int( $meta['chunk'] ) ||
			$meta['received'] < 0 || $meta['total'] < $meta['received'] || $meta['chunk'] < 1 ||
			! is_int( $meta['expires'] ) || $meta['expires'] < 1 ||
			! is_int( $meta['blog'] ) || $meta['blog'] < 1 ||
			! is_int( $meta['user'] ) || $meta['user'] < 1 ||
			! is_string( $meta['client'] ) || ! is_string( $meta['purpose'] ) ||
			! in_array( $meta['purpose'], array( 'media', 'media_read', 'text', 'wxr' ), true ) ||
			! is_string( $meta['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $meta['sha256'] ) ||
			! in_array( $meta['state'], array( 'open', 'verified', 'committing', 'committed' ), true ) ||
			( in_array( $meta['state'], array( 'committing', 'committed' ), true ) &&
				( ! in_array( $meta['consumer'] ?? '', array( 'media', 'content' ), true ) ||
					! is_int( $meta['destination_id'] ?? null ) || $meta['destination_id'] < 0 ) ) ) {
			$this->close_meta( $h );
			return new WP_Error( 'transfer_storage_uncertain', 'Private transfer metadata is malformed or incomplete.' );
		}
		return array( $h, $meta );
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
			$meta['state']          = 'committing';
			$meta['consumer']       = 'media';
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
	 * Audited private copy for WordPress's native sideload consumer.
	 * Claiming first makes uncertain WordPress outcomes replay-safe.
	 *
	 * @param string $id Exact verified lease.
	 * @return array<string,mixed>|WP_Error Internal-only temp descriptor.
	 */
	public function prepare_media_sideload( $id ) {
		// Make and verify the private copy BEFORE claiming any native effect.
		// Exhausted disk or transient IO failure must be safely retryable.
		$source_info = $this->verified_private_source( $id, 'media' );
		if ( is_wp_error( $source_info ) ) {
			return $source_info;
		}
		$private_dir = dirname( $source_info['path'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam,WordPress.PHP.NoSilencedErrors.Discouraged -- Verified owner-only private root, not web-accessible.
		$temp = @tempnam( $private_dir, 'wpai-media-' );
		if ( ! is_string( $temp ) ) {
			return new WP_Error( 'transfer_storage_unavailable', 'Private Media copy could not be allocated. The verified transfer remains safe to resume.' );
		}
		if ( dirname( $temp ) !== $private_dir || is_link( $temp ) || ! chmod( $temp, 0600 ) ) {
			$this->remove_file( $temp );
			return new WP_Error( 'transfer_storage_unsafe', 'Private Media copy could not be secured. The verified transfer remains unconsumed.' );
		}
		$source = @fopen( $source_info['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Audited verified private source, no warnings with path.
		$target = @fopen( $temp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Audited private duplicate stream.
		if ( ! is_resource( $source ) || ! is_resource( $target ) ) {
			if ( is_resource( $source ) ) {
				fclose( $source );
			}
			if ( is_resource( $target ) ) {
				fclose( $target );
			}
			$this->cleanup_media_temporary( $temp );
			return new WP_Error( 'transfer_storage_unavailable', 'Private Media copy could not be opened. The verified transfer remains unconsumed.' );
		}
		$copied  = stream_copy_to_stream( $source, $target );
		$flushed = fflush( $target );
		fclose( $source );
		fclose( $target );
		if ( $copied !== $source_info['bytes'] || ! $flushed ||
			! hash_equals( $source_info['sha256'], (string) hash_file( 'sha256', $temp ) ) ) {
			$this->cleanup_media_temporary( $temp );
			return new WP_Error( 'transfer_source_changed', 'Private Media copy could not be verified. Recheck the exact source before retrying.' );
		}
		// Final revalidation and one-way state change immediately precede
		// calling Core; a concurrent import can claim this lease only once.
		$claim = $this->claim_media( $id );
		if ( is_wp_error( $claim ) ) {
			$this->cleanup_media_temporary( $temp );
			return $claim;
		}
		return array(
			'temp'   => $temp,
			'bytes'  => $claim['bytes'],
			'sha256' => $claim['sha256'],
		);
	}

	/**
	 * Remove only temporary copies created in the current private root.
	 * The WordPress native sideload may already have moved the source.
	 *
	 * @param string $temp Private generated copy path.
	 * @return bool
	 */
	public function cleanup_media_temporary( $temp ) {
		$root = $this->root();
		if ( is_wp_error( $root ) || ! is_string( $temp ) ||
			dirname( $temp ) !== $root || ! str_starts_with( basename( $temp ), 'wpai-media-' ) ) {
			return false;
		}
		return $this->remove_file( $temp );
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
				! is_int( $attachment_id ) || $attachment_id < 1 ||
				! $this->allowed( 'media' ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'The native Media result could not be authorized or reconciled; inspect Media before retrying.' );
			}
			$attachment = get_post( $attachment_id );
			$path       = get_attached_file( $attachment_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ||
				! is_string( $path ) || ! is_file( $path ) ||
				(int) filesize( $path ) !== $meta['total'] ||
				! hash_equals( $meta['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'WordPress created an uncertain Media result; inspect its exact bytes and identity.' );
			}
			$meta['state']          = 'committed';
			$meta['destination_id'] = $attachment_id;
			if ( ! $this->save_meta( $handle, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'WordPress Media was saved but the private receipt is uncertain; inspect the destination.' );
			}
			return $this->receipt( $meta );
		} finally {
			$this->close_meta( $handle );
		}
	}

	/** @param string $id Exact opaque id. @return array<int,mixed>|WP_Error */
	private function load( $id ) {
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $id ) ) {
			return $this->invalid();
		}
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$info = $this->open_meta( $root, $id );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		list( $h, $meta ) = $info;
		if ( (int) get_current_blog_id() !== $meta['blog'] ||
			(int) get_current_user_id() !== $meta['user'] ||
			OAuth_Server::authenticated_mcp_client_id() !== $meta['client'] ||
			! $this->allowed( $meta['purpose'] ) ) {
			$this->close_meta( $h );
			return $this->denied();
		}
		if ( $meta['expires'] <= time() && 'committing' !== $meta['state'] ) {
			$this->close_meta( $h );
			return new WP_Error( 'transfer_expired', 'The private transfer lease expired; a new transfer is required.' );
		}
		return array( $root, $h, $meta );
	}

	/** @param resource $h Locked metadata handle. @param array<string,mixed> $meta State. @return bool */
	private function save_meta( $h, $meta ) {
		$json = wp_json_encode( $meta );
		if ( ! is_string( $json ) || strlen( $json ) > 4096 || ! rewind( $h ) || ! ftruncate( $h, 0 ) ) {
			return false;
		}
		return strlen( $json ) === fwrite( $h, $json ) && fflush( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Private locked descriptor, bounded metadata.
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
		$h    = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Private inert exact session file.
		if ( false === $h ) {
			return new WP_Error( 'transfer_storage_uncertain', 'Private transfer bytes are unavailable.' );
		}
		$valid = $this->secure_file( $h, $path ) && $this->file_length( $h, $meta['received'] );
		fclose( $h );
		return $valid ? $path : new WP_Error( 'transfer_storage_uncertain', 'Private transfer source length or identity changed.' );
	}

	/** @param string $root Root. @param string $id Session. @return bool */
	private function erase( $root, $id ) {
		if ( ! $this->remove_file( $root . '/' . $id . '.bytes' ) ) {
			return false;
		}
		return $this->remove_file( $root . '/' . $id . '.meta' );
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
			'id'             => $meta['id'],
			'purpose'        => $meta['purpose'],
			'total'          => $meta['total'],
			'received'       => $meta['received'],
			'chunk'          => $meta['chunk'],
			'complete'       => $meta['received'] === $meta['total'],
			'state'          => $meta['state'],
			'sha256'         => $meta['sha256'],
			'expires'        => $meta['expires'],
			'destination_id' => (int) ( $meta['destination_id'] ?? 0 ),
			'source_id'      => (int) ( $meta['source_id'] ?? 0 ),
		);
	}
}
