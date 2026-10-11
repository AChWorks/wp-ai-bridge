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
					if ( $meta['expires'] <= time() ) {
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
		$result                       = $this->check_file( $root, $id, $meta );
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

	/** @param string $id Opaque session. @return bool|WP_Error */
	public function cancel( $id ) {
		$loaded = $this->load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $root, $handle ) = $loaded;
		$deleted               = $this->erase( $root, $id );
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
			! in_array( $meta['purpose'], array( 'media', 'text', 'wxr' ), true ) ||
			! is_string( $meta['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $meta['sha256'] ) ||
			! in_array( $meta['state'], array( 'open', 'verified' ), true ) ) {
			$this->close_meta( $h );
			return new WP_Error( 'transfer_storage_uncertain', 'Private transfer metadata is malformed or incomplete.' );
		}
		return array( $h, $meta );
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
		if ( $meta['expires'] <= time() ) {
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
			'id'       => $meta['id'],
			'purpose'  => $meta['purpose'],
			'total'    => $meta['total'],
			'received' => $meta['received'],
			'chunk'    => $meta['chunk'],
			'complete' => $meta['received'] === $meta['total'],
			'state'    => $meta['state'],
			'sha256'   => $meta['sha256'],
			'expires'  => $meta['expires'],
		);
	}
}
