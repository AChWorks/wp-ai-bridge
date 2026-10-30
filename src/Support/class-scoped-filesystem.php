<?php
/**
 * Bounded scoped native filesystem access and verified publication.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support;

use WP_Error;

/** One backend, with explicit trusted-ancestor-writer and nontransactional limits. */
final class Scoped_Filesystem {
	private $permissions;
	private $roots;
	private $access;

	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
		$this->roots       = new Filesystem_Roots( $permissions );
		$this->access      = new Administration_Access( $permissions );
	}

	/** UTF8 relative string or canonical Base64 component array; raw names stay lossless. */
	public static function components( $relative ) {
		if ( is_string( $relative ) ) {
			if ( '' === $relative ) {
				return array();
			}
			if ( '/' === $relative[0] || '\\' === $relative[0] || false !== strpos( $relative, "\0" ) ||
				preg_match( '/^[a-zA-Z][a-zA-Z0-9+.-]*:/D', $relative ) || 1 !== preg_match( '//u', $relative ) ) {
				return new WP_Error( 'filesystem_path_invalid', 'Use a relative path or canonical Base64 filename components.' );
			}
			$parts = explode( '/', $relative );
		} elseif ( is_array( $relative ) && array_keys( $relative ) === range( 0, count( $relative ) - 1 ) ) {
			$parts = array();
			foreach ( $relative as $encoded ) {
				$part = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
				if ( ! is_string( $part ) || base64_encode( $part ) !== $encoded ) {
					return new WP_Error( 'filesystem_path_invalid', 'Filename components must use canonical Base64.' );
				}
				$parts[] = $part;
			}
		} elseif ( array() === $relative ) {
			return array();
		} else {
			return new WP_Error( 'filesystem_path_invalid', 'The relative filename representation is invalid.' );
		}
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || false !== strpos( $part, "\0" ) ||
				false !== strpos( $part, '/' ) || ( '\\' === DIRECTORY_SEPARATOR && false !== strpos( $part, '\\' ) ) ) {
				return new WP_Error( 'filesystem_path_invalid', 'Dot, empty and separator filename components are not allowed.' );
			}
		}
		return $parts;
	}

	public function stat( $root_id, $relative, $purpose = 'filesystem' ) {
		$root = $this->roots->resolve( $root_id, $purpose, 'read' );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		return $this->describe( $root, $relative, false );
	}

	private function describe( array $root, $relative, $missing ) {
		$parts = self::components( $relative );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}
		$path      = $root['path'];
		$ancestors = array(
			array(
				'path' => $path,
				'stat' => $root['stat'],
			),
		);
		$stat      = $root['stat'];
		foreach ( $parts as $index => $part ) {
			$path .= DIRECTORY_SEPARATOR . $part;
			if ( Filesystem_Roots::protected_path( $path ) ) {
				return new WP_Error( 'filesystem_protected', 'Bridge private/control artifacts are excluded from this surface.' );
			}
			clearstatcache( true, $path );
			$stat = @lstat( $path );
			if ( ! is_array( $stat ) ) {
				if ( $missing && count( $parts ) - 1 === $index ) {
					$stat = null;
					break;
				}
				return new WP_Error( 'filesystem_not_found', 'The selected file or ancestor is unavailable.' );
			}
			$type = $stat['mode'] & 0170000;
			if ( is_link( $path ) || ! in_array( $type, array( 0040000, 0100000 ), true ) ||
				( 0100000 === $type && 1 !== $stat['nlink'] ) ||
				( $index < count( $parts ) - 1 && 0040000 !== $type ) ) {
				return new WP_Error( 'filesystem_unsafe_type', 'Symlinks, hard-linked files and special files are not followed.' );
			}
			if ( $index < count( $parts ) - 1 ) {
				$ancestors[] = array(
					'path' => $path,
					'stat' => $stat,
				);
			}
		}
		$fields     = null === $stat ? null : $this->stat_fields( $stat );
		$key        = array( $root['id'], $root['revision'], $root['origin'], $root['purpose'], $parts );
		$descriptor = array(
			'root_id'       => $root['id'],
			'root_revision' => $root['revision'],
			'root'          => $root,
			'purpose'       => $root['purpose'],
			'path'          => $path,
			'ancestors'     => $ancestors,
			'relative_path' => implode( DIRECTORY_SEPARATOR, $parts ),
			'components'    => array_map( 'base64_encode', $parts ),
			'name'          => $parts ? end( $parts ) : '',
			'type'          => null === $stat ? 'absent' : ( 0040000 === ( $stat['mode'] & 0170000 ) ? 'directory' : 'file' ),
			'size'          => $stat['size'] ?? 0,
			'bytes'         => $stat['size'] ?? 0,
			'mode'          => isset( $stat['mode'] ) ? $stat['mode'] & 0777 : 0,
			'mtime'         => $stat['mtime'] ?? 0,
			'ctime'         => $stat['ctime'] ?? 0,
			'stat'          => $stat,
			'identity'      => hash_hmac( 'sha256', serialize( array( $key, $fields ) ), wp_salt( 'auth' ) ),
			'file_key'      => hash_hmac( 'sha256', serialize( array( $key, $stat['dev'] ?? null, $stat['ino'] ?? null ) ), wp_salt( 'auth' ) ),
		);
		return $descriptor;
	}

	private function stat_fields( array $stat ) {
		return array_intersect_key( $stat, array_fill_keys( array( 'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime' ), true ) );
	}

	/** Fixed safe fields for public callers; never leak the internal root/path/stream. */
	public static function public_descriptor( array $descriptor ) {
		return array_intersect_key(
			$descriptor,
			array_fill_keys(
				array( 'root_id', 'root_revision', 'components', 'type', 'size', 'bytes', 'mode', 'mtime', 'ctime', 'identity', 'file_key' ),
				true
			)
		);
	}

	/** Physical checks only; safe to use after a dispatch fence without native hooks. */
	private function physical_same( array $descriptor, $stream = null ) {
		foreach ( $descriptor['ancestors'] as $ancestor ) {
			clearstatcache( true, $ancestor['path'] );
			$now = @lstat( $ancestor['path'] );
			if ( ! is_array( $now ) || is_link( $ancestor['path'] ) ||
				$now['dev'] !== $ancestor['stat']['dev'] || $now['ino'] !== $ancestor['stat']['ino'] ||
				0040000 !== ( $now['mode'] & 0170000 ) ) {
				return false;
			}
		}
		clearstatcache( true, $descriptor['path'] );
		$now = @lstat( $descriptor['path'] );
		if ( 'absent' === $descriptor['type'] ) {
			return false === $now && ! is_link( $descriptor['path'] );
		}
		if ( ! is_array( $now ) || is_link( $descriptor['path'] ) ||
			$this->stat_fields( $now ) !== $this->stat_fields( $descriptor['stat'] ) ) {
			return false;
		}
		return null === $stream || ( is_resource( $stream ) && $this->stat_fields( fstat( $stream ) ) === $this->stat_fields( $now ) );
	}

	public function assert_same( array $descriptor ) {
		$root = $this->roots->resolve( $descriptor['root_id'], $descriptor['purpose'], 'read' );
		return ! is_wp_error( $root ) && $root['revision'] === $descriptor['root_revision'] &&
			$root['origin'] === $descriptor['root']['origin'] && $this->physical_same( $descriptor )
			? true : new WP_Error( 'filesystem_stale', 'The selected file, root or authority changed.' );
	}

	public function with_read_stream( $root_id, $relative, $purpose, $expected_identity, callable $callback ) {
		$descriptor = $this->stat( $root_id, $relative, $purpose );
		if ( is_wp_error( $descriptor ) ) {
			return $descriptor;
		}
		if ( 'file' !== $descriptor['type'] || ( null !== $expected_identity && $expected_identity !== $descriptor['identity'] ) ) {
			return new WP_Error( 'filesystem_stale', 'Select the exact current regular-file identity.' );
		}
		$stream = @fopen( $descriptor['path'], 'rb' );
		if ( ! is_resource( $stream ) ) {
			return new WP_Error( 'filesystem_read_failed', 'The PHP process cannot open this file for reading.' );
		}
		try {
			if ( ! $this->physical_same( $descriptor, $stream ) || ! flock( $stream, LOCK_SH | LOCK_NB ) ) {
				return new WP_Error( 'filesystem_stale', 'The opened file differs from the selected file or is busy.' );
			}
			$result = $callback( $stream, $descriptor );
			$same   = $this->assert_same( $descriptor );
			return is_wp_error( $same ) || ! $this->physical_same( $descriptor, $stream ) ?
				new WP_Error( 'filesystem_stale', 'The file changed during reading; discard this window.' ) : $result;
		} finally {
			flock( $stream, LOCK_UN );
			fclose( $stream );
		}
	}

	public function read_window( $root_id, $relative, $purpose, $expected_identity, $offset, $max_bytes ) {
		$limit = min( ( new Transfer_Settings() )->bytes()['chunk'], max( 1, intdiv( Bounded_Payload::response_limit(), 3 ) ) );
		if ( ! is_int( $offset ) || $offset < 0 || ! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes > $limit ) {
			return new WP_Error( 'filesystem_window_invalid', 'The byte window exceeds current finite response resources.' );
		}
		return $this->with_read_stream(
			$root_id,
			$relative,
			$purpose,
			$expected_identity,
			function ( $stream, $descriptor ) use ( $offset, $max_bytes ) {
				if ( $offset > $descriptor['size'] || 0 !== fseek( $stream, $offset ) ) {
					return new WP_Error( 'filesystem_window_invalid', 'The byte offset is beyond the current file.' );
				}
				$length = min( $max_bytes, $descriptor['size'] - $offset );
				$bytes  = 0 === $length ? '' : fread( $stream, $length );
				if ( ! is_string( $bytes ) || strlen( $bytes ) !== $length ) {
					return new WP_Error( 'filesystem_read_failed', 'The requested file window was incomplete.' );
				}
				return array(
					'bytes'       => $bytes,
					'offset'      => $offset,
					'next_offset' => $offset + $length,
					'complete'    => $offset + $length === $descriptor['size'],
					'identity'    => $descriptor['identity'],
					'descriptor'  => $descriptor,
				);
			}
		);
	}

	public function hash( $root_id, $relative, $purpose = 'filesystem', $expected_identity = null ) {
		return $this->with_read_stream(
			$root_id,
			$relative,
			$purpose,
			$expected_identity,
			function ( $stream, $descriptor ) {
				$hash = hash_init( 'sha256' );
				if ( hash_update_stream( $hash, $stream ) !== $descriptor['size'] ) {
					return new WP_Error( 'filesystem_hash_failed', 'The complete file digest could not be read.' );
				}
				return array_merge( self::public_descriptor( $descriptor ), array( 'sha256' => hash_final( $hash ) ) );
			}
		);
	}

	public function iterate_entries( $root_id, $relative, $purpose, $expected_identity, callable $callback, $work_budget ) {
		$directory = $this->stat( $root_id, $relative, $purpose );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		if ( 'directory' !== $directory['type'] || ( null !== $expected_identity && $expected_identity !== $directory['identity'] ) ) {
			return new WP_Error( 'filesystem_stale', 'Select the current directory identity.' );
		}
		$config      = $this->access->configuration();
		$budget      = is_array( $work_budget ) ? $work_budget : array();
		$cursor      = $budget['cursor'] ?? 0;
		$seconds     = min( $budget['seconds'] ?? $config['step_seconds'], $config['step_seconds'] );
		$byte_budget = min( $budget['bytes'] ?? $config['step_bytes'], $config['step_bytes'] );
		if ( ! is_int( $cursor ) || $cursor < 0 || $seconds <= 0 || $byte_budget < 1 ) {
			return new WP_Error( 'filesystem_window_invalid', 'Directory work budgets must be finite positive values.' );
		}
		$handle = @opendir( $directory['path'] );
		if ( ! is_resource( $handle ) ) {
			return new WP_Error( 'filesystem_list_failed', 'The PHP process cannot enumerate this directory.' );
		}
		$start    = microtime( true );
		$seen     = 0;
		$used     = 0;
		$complete = true;
		try {
			while ( true ) {
				$name = readdir( $handle );
				if ( false === $name ) {
					break;
				}
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				if ( $seen++ < $cursor ) {
					if ( microtime( true ) - $start >= $seconds ) {
						return new WP_Error( 'filesystem_scan_budget', 'Reopening this directory cursor exceeds the configured work time; increase the resource policy or use a narrower root.' );
					}
					continue;
				}
				if ( $used >= $byte_budget || microtime( true ) - $start >= $seconds ||
					( isset( $budget['entries'] ) && $seen - $cursor > $budget['entries'] ) ) {
					--$seen;
					$complete = false;
					break;
				}
				$components   = $directory['components'];
				$components[] = base64_encode( $name );
				if ( Filesystem_Roots::protected_path( $directory['path'] . DIRECTORY_SEPARATOR . $name ) ) {
					if ( in_array( $purpose, array( 'backup_export', 'backup_restore' ), true ) || ! empty( $budget['reject_protected'] ) ) {
						return new WP_Error( 'backup_source_protected_gap', 'A required backup directory contains excluded Bridge-private or control artifacts; full capture cannot silently omit them.' );
					}
					$used += strlen( $name ) + 128;
					continue;
				}
				$entry = $this->stat( $root_id, $components, $purpose );
				if ( is_wp_error( $entry ) ) {
					return $entry;
				}
				$used    += strlen( $name ) + 256;
				$decision = $callback( $entry );
				if ( is_wp_error( $decision ) ) {
					return $decision;
				}
				if ( false === $decision || null === $decision ) {
					if ( null === $decision ) {
						--$seen;
					}
					$complete = false;
					break;
				}
			}
		} finally {
			closedir( $handle );
		}
		$same = $this->assert_same( $directory );
		return is_wp_error( $same ) ? $same : array(
			'cursor'             => $seen,
			'complete'           => $complete,
			'scanned'            => $seen - $cursor,
			'directory_identity' => $directory['identity'],
		);
	}

	private function mutation_target( $root_id, $relative, $purpose, $destructive = false ) {
		$root = $this->roots->resolve( $root_id, $purpose, $destructive ? 'destructive' : 'write' );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$parts = self::components( $relative );
		if ( is_wp_error( $parts ) || ! $parts ) {
			return new WP_Error( 'filesystem_root_mutation', 'The configured root itself cannot be renamed or deleted.' );
		}
		$name       = end( $parts );
		$full_path  = $root['path'] . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $parts );
		$known_code = ( defined( 'WP_PLUGIN_DIR' ) && Filesystem_Roots::within( $full_path, WP_PLUGIN_DIR ) ) ||
			( defined( 'WPMU_PLUGIN_DIR' ) && Filesystem_Roots::within( $full_path, WPMU_PLUGIN_DIR ) ) ||
			( defined( 'WP_CONTENT_DIR' ) && Filesystem_Roots::within( $full_path, WP_CONTENT_DIR . '/themes' ) ) ||
			Filesystem_Roots::within( $full_path, rtrim( ABSPATH, '/\\' ) . '/wp-admin' ) ||
			Filesystem_Roots::within( $full_path, rtrim( ABSPATH, '/\\' ) . '/wp-includes' );
		$vcs        = Filesystem_Roots::vcs_path( $full_path );
		$executable = ! empty( $root['executable'] ) || $known_code || $vcs ||
			preg_match( '/(?:\.(?:php[0-9]*|phtml|phar|inc|cgi|pl|py|sh|ini|shtml)|^\.htaccess|^\.user\.ini|^web\.config|^\.env)$/iD', $name );
		if ( $executable ) {
			$allowed = $this->roots->resolve( $root_id, $purpose, 'executable' );
			if ( is_wp_error( $allowed ) ) {
				return new WP_Error( 'filesystem_executable_denied', 'Executable/configuration changes require separate current code trust.' );
			}
			if ( 'filesystem' === $purpose || $vcs ) {
				$full_path   = $root['path'] . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $parts );
				$plugin_path = defined( 'WP_PLUGIN_DIR' ) && Filesystem_Roots::within( $full_path, WP_PLUGIN_DIR );
				$mu_path     = defined( 'WPMU_PLUGIN_DIR' ) && Filesystem_Roots::within( $full_path, WPMU_PLUGIN_DIR );
				$theme_root  = function_exists( 'get_theme_root' ) ? get_theme_root() : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/themes' : '' );
				$theme_path  = is_string( $theme_root ) && '' !== $theme_root && Filesystem_Roots::within( $full_path, $theme_root );
				$native      = $plugin_path || $mu_path ? current_user_can( 'edit_plugins' ) :
					( $theme_path ? current_user_can( 'edit_themes' ) : ( current_user_can( 'edit_plugins' ) && current_user_can( 'edit_themes' ) ) );
				if ( ! $native ) {
					return new WP_Error( 'filesystem_executable_denied', 'The matching current native code capabilities are required.' );
				}
			}
		}
		return $this->describe( $root, $relative, true );
	}

	/** Preflight a move source/destination before any continuation can change it. */
	public function check_mutation( $root_id, $relative, $purpose = 'filesystem', $destructive = false ) {
		$target = $this->mutation_target( $root_id, $relative, $purpose, $destructive );
		return is_wp_error( $target ) ? $target : true;
	}

	/** Private same-device work area; never uses public same-directory stage names. */
	private function private_directory( array $target ) {
		$base = Safe_Temporary_Staging::directory();
		if ( ! is_string( $base ) ) {
			return new WP_Error( 'filesystem_private_stage_unavailable', 'A private same-device staging directory is required.' );
		}
		$directory = rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.wpai-fs-' .
			substr( hash_hmac( 'sha256', ABSPATH, wp_salt( 'auth' ) ), 0, 40 );
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700 ) && ! is_dir( $directory ) ) {
			return new WP_Error( 'filesystem_private_stage_unavailable', 'Private filesystem staging cannot be created.' );
		}
		clearstatcache( true, $directory );
		$stat   = @lstat( $directory );
		$parent = @lstat( dirname( $target['path'] ) );
		if ( ! is_array( $stat ) || ! is_array( $parent ) || is_link( $directory ) || realpath( $directory ) !== $directory ||
			0700 !== ( $stat['mode'] & 0777 ) || $stat['dev'] !== $parent['dev'] || Filesystem_Roots::served( $directory ) ) {
			return new WP_Error( 'filesystem_private_stage_unavailable', 'No verified private staging area exists on the destination filesystem.' );
		}
		return $directory;
	}

	private function lock( array $target ) {
		$directory = $this->private_directory( $target );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$path   = $directory . '/lock-' . hash( 'sha256', $target['path'] );
		$handle = @fopen( $path, 'x+b' );
		if ( is_resource( $handle ) ) {
			@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Confined syscall failure is explicitly checked; private paths must not escape through PHP warnings.
		} else {
			$handle = @fopen( $path, 'r+b' );
		}
		$stat = @lstat( $path );
		if ( ! is_resource( $handle ) || ! is_array( $stat ) || is_link( $path ) || 1 !== $stat['nlink'] ||
			0600 !== ( $stat['mode'] & 0777 ) || fstat( $handle )['ino'] !== $stat['ino'] ||
			fstat( $handle )['dev'] !== $stat['dev'] || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			return new WP_Error( 'filesystem_busy', 'The selected filesystem path is busy or its private lock is unavailable.' );
		}
		return $handle;
	}

	private function unlock( $handle ) {
		flock( $handle, LOCK_UN );
		fclose( $handle );
	}

	public function mkdir( $root_id, $relative, $purpose, $expected_parent_identity, $operation_id, $mode = 0755 ) {
		$target = $this->mutation_target( $root_id, $relative, $purpose );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( ! is_int( $mode ) || $mode < 0 || $mode > 0777 ) {
			return new WP_Error( 'filesystem_mode_invalid', 'Use ordinary permission bits only.' );
		}
		$request = array(
			'action'            => 'mkdir',
			'expected_parent'   => $expected_parent_identity,
			'expected_identity' => null,
			'mode'              => $mode,
		);
		$history = $this->historical( $operation_id, $target, $request );
		if ( null !== $history ) {
			return $history;
		}
		$parts = $target['components'];
		array_pop( $parts );
		$parent = $this->stat( $root_id, $parts, $purpose );
		if ( is_wp_error( $parent ) || $parent['identity'] !== $expected_parent_identity || 'absent' !== $target['type'] ) {
			return new WP_Error( 'filesystem_stale', 'The parent or expected absent destination changed.' );
		}
		return $this->dispatch(
			$operation_id,
			$target,
			$request,
			function () use ( $target, $mode ) {
				if ( ! $this->physical_same( $target ) || ! @mkdir( $target['path'], $mode ) ) {
					return array(
						'outcome' => 'rejected_no_effect',
						'reason'  => 'exclusive_mkdir_failed',
					);
				}
				return array(
					'outcome' => 'committed',
					'action'  => 'mkdir',
				);
			}
		);
	}

	/** Narrow ordinary-mode finalization for completed isolated restore/copy entries. */
	public function set_mode( $root_id, $relative, $purpose, $expected_identity, $mode, $operation_id ) {
		$target = $this->mutation_target( $root_id, $relative, $purpose );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( ! is_int( $mode ) || $mode < 0 || $mode > 0777 ) {
			return new WP_Error( 'filesystem_mode_invalid', 'Use ordinary permission bits only.' );
		}
		$request = array(
			'action'            => 'mode',
			'expected_identity' => $expected_identity,
			'mode'              => $mode,
		);
		$history = $this->historical( $operation_id, $target, $request );
		if ( null !== $history ) {
			return $history;
		}
		if ( $target['identity'] !== $expected_identity || 'absent' === $target['type'] ) {
			return new WP_Error( 'filesystem_stale', 'Mode finalization requires the exact current entry identity.' );
		}
		return $this->dispatch(
			$operation_id,
			$target,
			$request,
			function () use ( $target, $mode ) {
				if ( ! $this->physical_same( $target ) || ! @chmod( $target['path'], $mode ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Confined syscall failure is explicitly checked; private paths must not escape through PHP warnings.
					return array(
						'outcome' => 'unknown',
						'reason'  => 'mode_change_unverified',
					);
				}
				clearstatcache( true, $target['path'] );
				$stat = @lstat( $target['path'] );
				return array(
					'outcome' => is_array( $stat ) && $stat['ino'] === $target['stat']['ino'] &&
						$stat['dev'] === $target['stat']['dev'] && ( $stat['mode'] & 0777 ) === $mode ? 'committed' : 'unknown',
					'action'  => 'mode',
				);
			}
		);
	}

	/** Exact historical write match without reopening a released input payload. */
	public function write_status( $root_id, $relative, $purpose, $expected, array $source, $operation_id ) {
		$target = $this->mutation_target( $root_id, $relative, $purpose );
		if ( is_wp_error( $target ) ) {
			return $target; }
		$request = array(
			'action'            => 'write',
			'preimage'          => is_array( $expected ) ? ( $expected['sha256'] ?? null ) : null,
			'source_sha256'     => $source['sha256'] ?? '',
			'integrity'         => $source['integrity'] ?? 'sha256',
			'bytes'             => $source['bytes'] ?? null,
			'expected_identity' => is_array( $expected ) ? ( $expected['identity'] ?? null ) : $expected,
			'mode'              => $source['mode'] ?? null,
		);
		return $this->historical( $operation_id, $target, $request );
	}

	/** Prepare privately in bounded steps, then one no-replace publication protocol. */
	public function apply_verified_source( $root_id, $relative, $purpose, $expected, array $source, $operation_id ) {
		$target = $this->mutation_target( $root_id, $relative, $purpose );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$expected_id = is_array( $expected ) ? ( $expected['identity'] ?? null ) : $expected;
		$preimage    = is_array( $expected ) ? ( $expected['sha256'] ?? null ) : null;
		$request     = array(
			'action'            => 'write',
			'preimage'          => $preimage,
			'source_sha256'     => $source['sha256'] ?? '',
			'integrity'         => $source['integrity'] ?? 'sha256',
			'bytes'             => $source['bytes'] ?? null,
			'expected_identity' => $expected_id,
			'mode'              => $source['mode'] ?? null,
		);
		$history     = $this->historical( $operation_id, $target, $request );
		if ( null !== $history ) {
			return $history;
		}
		if ( ( null === $expected && 'absent' !== $target['type'] ) ||
			( null !== $expected && ( 'file' !== $target['type'] || $expected_id !== $target['identity'] ||
				! is_string( $preimage ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $preimage ) ) ) ||
			! is_resource( $source['stream'] ?? null ) || ! is_int( $source['bytes'] ?? null ) || $source['bytes'] < 0 ) {
			return new WP_Error( 'filesystem_stale', 'Select absence or the complete exact file identity and SHA256 preimage.' );
		}
		$lock = $this->lock( $target );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$stage = $this->prepare_source( $target, $source, $operation_id );
			if ( is_wp_error( $stage ) || ! empty( $stage['preparing'] ) ) {
				return $stage;
			}
			if ( ! $this->physical_same( $target ) || ( null !== $preimage &&
				! hash_equals( $preimage, (string) hash_file( 'sha256', $target['path'] ) ) ) ) {
				return new WP_Error( 'filesystem_stale', 'The destination changed during private preparation.' );
			}
			$mode = $source['mode'] ?? ( 'file' === $target['type'] ? $target['mode'] : 0644 );
			if ( ! is_int( $mode ) || $mode < 0 || $mode > 0777 ) {
				return new WP_Error( 'filesystem_mode_invalid', 'Use ordinary permission bits only.' );
			}
			if ( ! @chmod( $stage['path'], $mode ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Confined syscall failure is explicitly checked; private paths must not escape through PHP warnings.
				return new WP_Error( 'filesystem_metadata_denied', 'The PHP process cannot preserve destination mode.' );
			}
			if ( 'file' === $target['type'] ) {
				$stat = @stat( $stage['path'] );
				if ( $stat['uid'] !== $target['stat']['uid'] && ! @chown( $stage['path'], $target['stat']['uid'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Confined syscall failure is explicitly checked; private paths must not escape through PHP warnings.
					return new WP_Error( 'filesystem_metadata_denied', 'The PHP process cannot preserve destination ownership.' );
				}
				if ( $stat['gid'] !== $target['stat']['gid'] && ! @chgrp( $stage['path'], $target['stat']['gid'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Confined syscall failure is explicitly checked; private paths must not escape through PHP warnings.
					return new WP_Error( 'filesystem_metadata_denied', 'The PHP process cannot preserve destination group.' );
				}
			}
			$probe = dirname( $stage['path'] ) . '/probe-' . bin2hex( random_bytes( 24 ) );
			if ( ! @link( $stage['path'], $probe ) || ! @unlink( $probe ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Confined syscall failure is explicitly checked; private paths must not escape through PHP warnings.
				return new WP_Error( 'filesystem_atomic_publish_unavailable', 'The destination filesystem cannot provide verified no-replace hard-link publication.' );
			}
			$hold                = dirname( $stage['path'] ) . '/hold-' . bin2hex( random_bytes( 24 ) );
			$request['recovery'] = array(
				'stage'            => $stage['path'],
				'hold'             => $hold,
				'candidate_sha256' => $stage['sha256'],
			);
			$result              = $this->dispatch(
				$operation_id,
				$target,
				$request,
				function () use ( $target, $stage, $hold, $preimage ) {
					if ( ! $this->physical_same( $target ) ) {
						return array(
							'outcome' => 'rejected_no_effect',
							'reason'  => 'stale',
						);
					}
					$moved = false;
					if ( 'file' === $target['type'] ) {
						if ( ! @rename( $target['path'], $hold ) ) {
							return array(
								'outcome' => 'rejected_no_effect',
								'reason'  => 'quarantine_failed',
							);
						}
						$moved = true;
						if ( ! hash_equals( $preimage, (string) hash_file( 'sha256', $hold ) ) ) {
							$restored = @link( $hold, $target['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Confined syscall failure is explicitly checked; private paths must not escape through PHP warnings.
							if ( $restored ) {
								@unlink( $hold );
							}
							return array(
								'outcome' => $restored ? 'rejected_no_effect' : 'recovery_required',
								'reason'  => 'quarantined_preimage_changed',
							);
						}
					}
					if ( ! @link( $stage['path'], $target['path'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Confined syscall failure is explicitly checked; private paths must not escape through PHP warnings.
						$restored = ! $moved || @link( $hold, $target['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Confined syscall failure is explicitly checked; private paths must not escape through PHP warnings.
						if ( $moved && $restored ) {
							@unlink( $hold );
						}
						return array(
							'outcome' => $restored ? 'rejected_no_effect' : 'recovery_required',
							'reason'  => 'publication_competitor_or_host_denial',
						);
					}
					$published = @lstat( $target['path'] );
					$staged    = @lstat( $stage['path'] );
					if ( ! is_array( $published ) || ! is_array( $staged ) || $published['ino'] !== $staged['ino'] ||
						$published['dev'] !== $staged['dev'] ||
						! hash_equals( $stage['sha256'], (string) hash_file( 'sha256', $target['path'] ) ) ) {
						return array(
							'outcome' => 'recovery_required',
							'reason'  => 'published_generation_unverified',
						);
					}
					if ( $moved && ! hash_equals( $preimage, (string) hash_file( 'sha256', $hold ) ) ) {
						return array(
							'outcome' => 'recovery_required',
							'reason'  => 'previous_inode_changed',
						);
					}
					if ( ! @unlink( $stage['path'] ) || ( $moved && ! @unlink( $hold ) ) ) {
						return array(
							'outcome' => 'recovery_required',
							'reason'  => 'private_cleanup_failed',
						);
					}
					return array(
						'outcome' => 'committed',
						'action'  => 'write',
						'bytes'   => $published['size'],
					);
				}
			);
			if ( ! is_wp_error( $result ) && 'committed' === ( $result['outcome'] ?? '' ) && ! empty( $stage['direct'] ) ) {
				$released = $source['release_payload']();
				if ( is_wp_error( $released ) ) {
					return $released;
				}
			} elseif ( ! empty( $stage['direct'] ) && is_file( $stage['path'] ) ) {
				// Restore the still-inert lease's private mode after a rejected publication.
				@chmod( $stage['path'], 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Only the exact verified private input inode.
			}
			return $result;

		} finally {
			$this->unlock( $lock );
		}
	}

	private function prepare_source( array $target, array $source, $operation_id ) {
		if ( isset( $source['private_path'], $source['release_payload'] ) && is_callable( $source['release_payload'] ) ) {
			$path   = $source['private_path'];
			$stat   = @lstat( $path );
			$parent = @lstat( dirname( $target['path'] ) );
			$fd     = fstat( $source['stream'] );
			if ( ! is_array( $stat ) || ! is_array( $parent ) || is_link( $path ) ||
				$stat['ino'] !== $fd['ino'] || $stat['dev'] !== $fd['dev'] || 1 !== $stat['nlink'] ||
				$stat['dev'] !== $parent['dev'] || 0700 !== ( fileperms( dirname( $path ) ) & 0777 ) ||
				! preg_match( '/^[a-f0-9]{64}\.bytes$/D', basename( $path ) ) || Filesystem_Roots::served( dirname( $path ) ) ) {
				return new WP_Error( 'filesystem_private_stage_unavailable', 'The verified private input must be on the destination filesystem.' );
			}
			if ( $stat['size'] !== $source['bytes'] || 0 !== fseek( $source['stream'], 0 ) ) {
				return new WP_Error( 'filesystem_source_changed', 'The verified private source length changed.' );
			}
			$hash = hash_init( 'sha256' );
			if ( 'sha256-frame-chain-v1' === ( $source['integrity'] ?? 'sha256' ) ) {
				$chunk = $source['chunk_bytes'] ?? null;
				if ( ! is_int( $chunk ) || $chunk < 1 || $chunk > ( new Transfer_Settings() )->bytes()['chunk'] ) {
					return new WP_Error( 'filesystem_source_changed', 'Frame-chain inputs require the original bounded fixed chunk width.' );
				}
				$chain  = hash( 'sha256', 'wpai-administration-stream-v1' );
				$offset = 0;
				while ( $offset < $source['bytes'] ) {
					$part = fread( $source['stream'], min( $chunk, $source['bytes'] - $offset ) );
					if ( ! is_string( $part ) || '' === $part ) {
						return new WP_Error( 'filesystem_source_changed', 'A verified raw-file frame is unavailable.' );
					}
					hash_update( $hash, $part );
					$chain   = hash( 'sha256', $chain . ':' . strlen( $part ) . ':' . hash( 'sha256', $part ) );
					$offset += strlen( $part );
				}
				if ( ! hash_equals( $source['sha256'], $chain ) ) {
					return new WP_Error( 'filesystem_source_changed', 'The current raw-file frame chain differs from its sealed identity.' );
				}
			} elseif ( hash_update_stream( $hash, $source['stream'] ) !== $source['bytes'] ) {
				return new WP_Error( 'filesystem_source_changed', 'The complete private source digest is unavailable.' );
			}
			$sha256 = hash_final( $hash );
			if ( 'sha256' === ( $source['integrity'] ?? 'sha256' ) && ! hash_equals( $source['sha256'], $sha256 ) ) {
				return new WP_Error( 'filesystem_source_changed', 'The verified private input changed before publication.' );
			}

			return array(
				'path'   => $path,
				'sha256' => $sha256,
				'bytes'  => $source['bytes'],
				'direct' => true,
			);
		}
		$config      = $this->access->configuration();
		$state       = $source['checkpoint']['filesystem_write'] ?? null;
		$fingerprint = hash(
			'sha256',
			serialize(
				array(
					$operation_id,
					$target['root_id'],
					$target['components'],
					$target['root_revision'],
					$source['bytes'],
					$source['sha256'] ?? '',
				)
			)
		);
		$directory   = $this->private_directory( $target );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		if ( null === $state ) {
			$path = $directory . '/stage-' . bin2hex( random_bytes( 24 ) );
			$file = @fopen( $path, 'x+b' );
			if ( ! is_resource( $file ) || ! @chmod( $path, 0600 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Confined syscall failure is explicitly checked; private paths must not escape through PHP warnings.
				if ( is_resource( $file ) ) {
					fclose( $file );
				}
				return new WP_Error( 'filesystem_stage_failed', 'Private destination stage cannot be allocated.' );
			}
			$stat = fstat( $file );
			fclose( $file );
			$state = array(
				'fingerprint' => $fingerprint,
				'path'        => $path,
				'dev'         => $stat['dev'],
				'ino'         => $stat['ino'],
				'offset'      => 0,
				'chain'       => hash( 'sha256', 'wpai-administration-stream-v1' ),
			);
		}
		if ( ( $state['fingerprint'] ?? null ) !== $fingerprint || dirname( $state['path'] ) !== $directory ) {
			return new WP_Error( 'filesystem_stage_conflict', 'Private preparation belongs to another exact operation.' );
		}
		$file = @fopen( $state['path'], 'r+b' );
		$stat = @lstat( $state['path'] );
		if ( ! is_resource( $file ) || ! is_array( $stat ) || is_link( $state['path'] ) || 1 !== $stat['nlink'] ||
			$stat['dev'] !== $state['dev'] || $stat['ino'] !== $state['ino'] ||
			fstat( $file )['dev'] !== $stat['dev'] || fstat( $file )['ino'] !== $stat['ino'] ||
			$stat['size'] < $state['offset'] ) {
			if ( is_resource( $file ) ) {
				fclose( $file );
			}
			return new WP_Error( 'filesystem_stage_conflict', 'Private preparation identity changed.' );
		}
		$start  = microtime( true );
		$copied = 0;
		try {
			if ( ! ftruncate( $file, $state['offset'] ) || 0 !== fseek( $file, $state['offset'] ) ||
				0 !== fseek( $source['stream'], $state['offset'] ) ) {
				return new WP_Error( 'filesystem_stage_failed', 'Private preparation offset cannot be recovered.' );
			}
			$chunk = $source['chunk_bytes'] ?? min( ( new Transfer_Settings() )->bytes()['chunk'], $config['step_bytes'] );
			while ( $state['offset'] < $source['bytes'] ) {
				$length = min( $chunk, $source['bytes'] - $state['offset'] );
				if ( $copied > 0 && ( $copied + $length > $config['step_bytes'] || microtime( true ) - $start >= $config['step_seconds'] ) ) {
					break;
				}
				$bytes = fread( $source['stream'], $length );
				if ( ! is_string( $bytes ) || strlen( $bytes ) !== $length ) {
					return new WP_Error( 'filesystem_source_changed', 'Verified source ended before its declared length.' );
				}
				$done = 0;
				while ( $done < $length ) {
					$written = fwrite( $file, substr( $bytes, $done ) );
					if ( ! is_int( $written ) || $written < 1 ) {
						return new WP_Error( 'filesystem_stage_failed', 'Private preparation write was interrupted.' );
					}
					$done += $written;
				}
				$state['offset'] += $length;
				$copied          += $length;
				$state['chain']   = hash( 'sha256', $state['chain'] . ':' . $length . ':' . hash( 'sha256', $bytes ) );
			}
			if ( ! fflush( $file ) || fstat( $file )['size'] !== $state['offset'] ) {
				return new WP_Error( 'filesystem_stage_failed', 'Private preparation length was not confirmed.' );
			}
		} finally {
			fclose( $file );
		}
		$checkpoint                     = $source['checkpoint'] ?? array();
		$checkpoint['filesystem_write'] = $state;
		if ( isset( $source['save_checkpoint'] ) && is_callable( $source['save_checkpoint'] ) ) {
			$saved = $source['save_checkpoint']( $checkpoint );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		} elseif ( $state['offset'] < $source['bytes'] ) {
			@unlink( $state['path'] );
			return new WP_Error( 'filesystem_checkpoint_required', 'Large writes require a purpose-bound resumable source checkpoint.' );
		}
		if ( $state['offset'] < $source['bytes'] ) {
			return array(
				'preparing'   => true,
				'outcome'     => 'not_dispatched',
				'next_offset' => $state['offset'],
			);
		}
		$sha256 = hash_file( 'sha256', $state['path'] );
		if ( 'sha256-frame-chain-v1' === ( $source['integrity'] ?? 'sha256' ) ) {
			if ( empty( $source['chunk_bytes'] ) || ! hash_equals( $source['sha256'], $state['chain'] ) ) {
				return new WP_Error( 'filesystem_source_changed', 'The prepared frame chain differs from the verified source.' );
			}
		} elseif ( ! is_string( $sha256 ) || ! hash_equals( $source['sha256'], $sha256 ) ) {
			return new WP_Error( 'filesystem_source_changed', 'The prepared file differs from the verified source digest.' );
		}
		return array(
			'path'   => $state['path'],
			'sha256' => $sha256,
			'bytes'  => $state['offset'],
		);
	}

	public function delete( $root_id, $relative, $purpose, $expected, $operation_id ) {
		$target = $this->mutation_target( $root_id, $relative, $purpose, true );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$expected_id = is_array( $expected ) ? ( $expected['identity'] ?? null ) : $expected;
		$preimage    = is_array( $expected ) ? ( $expected['sha256'] ?? null ) : null;
		$request     = array(
			'action'            => 'delete',
			'preimage'          => $preimage,
			'expected_identity' => $expected_id,
		);
		$history     = $this->historical( $operation_id, $target, $request );
		if ( null !== $history ) {
			return $history;
		}
		if ( $target['identity'] !== $expected_id || 'absent' === $target['type'] ||
			( 'file' === $target['type'] && ( ! is_string( $preimage ) ||
				! hash_equals( $preimage, (string) hash_file( 'sha256', $target['path'] ) ) ) ) ) {
			return new WP_Error( 'filesystem_stale', 'Deletion requires the exact current identity and file digest.' );
		}
		$lock = $this->lock( $target );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$directory           = $this->private_directory( $target );
			$hold                = $directory . '/hold-' . bin2hex( random_bytes( 24 ) );
			$request['recovery'] = array( 'hold' => $hold );
			return $this->dispatch(
				$operation_id,
				$target,
				$request,
				function () use ( $target, $hold, $preimage ) {
					if ( ! $this->physical_same( $target ) ) {
						return array(
							'outcome' => 'rejected_no_effect',
							'reason'  => 'stale',
						);
					}
					if ( 'directory' === $target['type'] ) {
						return array(
							'outcome' => @rmdir( $target['path'] ) ? 'committed' : 'rejected_no_effect',
							'action'  => 'delete_empty_directory',
						);
					}
					if ( ! @rename( $target['path'], $hold ) ) {
						return array(
							'outcome' => 'rejected_no_effect',
							'reason'  => 'quarantine_failed',
						);
					}
					if ( ! hash_equals( $preimage, (string) hash_file( 'sha256', $hold ) ) ) {
						$restored = @link( $hold, $target['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Confined syscall failure is explicitly checked; private paths must not escape through PHP warnings.
						if ( $restored ) {
							@unlink( $hold );
						}
						return array(
							'outcome' => $restored ? 'rejected_no_effect' : 'recovery_required',
							'reason'  => 'preimage_changed',
						);
					}
					return array(
						'outcome' => @unlink( $hold ) ? 'committed' : 'recovery_required',
						'action'  => 'delete',
					);
				}
			);
		} finally {
			$this->unlock( $lock );
		}
	}


	/** Replays are matched to immutable caller request facts, before current target inspection. */
	private function request_descriptor( array $target, array $descriptor ) {
		$descriptor['root_id']       = $target['root_id'];
		$descriptor['root_revision'] = $target['root_revision'];
		$descriptor['components']    = $target['components'];
		return $descriptor;
	}

	private function journal( array $target ) {
		$identity = array_merge( array( 'scope' => $target['root']['scope'] ), $target['root']['origin'] );
		return new Administration_Operation_Journal( 'filesystem', $identity, ( new Transfer_Settings() )->bytes()['request'] );
	}

	private function historical( $operation_id, array $target, array $request ) {
		$request = $this->request_descriptor( $target, $request );
		return $this->public_result( $this->journal( $target )->status( $operation_id, hash( 'sha256', serialize( $request ) ) ) );
	}

	private function public_result( $result ) {
		if ( is_array( $result ) && isset( $result['receipt'] ) ) {
			unset( $result['receipt']['descriptor']['recovery'] );
			$result['outcome'] = $result['receipt']['result']['outcome'] ?? 'unknown';
		}
		return $result;
	}

	public function status( $root_id, $purpose, $operation_id ) {
		$root = $this->roots->resolve( $root_id, $purpose, 'write' );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$target  = $this->describe( $root, array(), false );
		$journal = $this->journal( $target );
		$result  = $journal->status( $operation_id );
		if ( is_array( $result ) && 'directory_copy' === ( $result['receipt']['descriptor']['action'] ?? '' ) ) {
			$done = $journal->status( 'fs-done-' . hash( 'sha256', $operation_id ) );
			if ( is_wp_error( $done ) ) {
				return $done; }
			$result['outcome']     = is_array( $done ) && 'committed' === ( $done['receipt']['result']['copy_outcome'] ?? '' ) ? 'committed' : 'incomplete';
			$result['transfer_id'] = $result['receipt']['result']['continuation_id'] ?? '';
			unset( $result['receipt']['descriptor']['recovery'] );
			return $result;
		}
		return $this->public_result( $result );
	}

	/** Recovery never recreates an absent pathname or overwrites a competing generation. */
	public function recover( $root_id, $relative, $purpose, $operation_id, $recovery_id, $expected_identity ) {
		$target = $this->mutation_target( $root_id, $relative, $purpose, true );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$record = $this->journal( $target )->status( $operation_id );
		if ( is_wp_error( $record ) || ! is_array( $record ) ) {
			return $record;
		}
		$descriptor = $record['receipt']['descriptor'];
		$recovery   = $descriptor['recovery'] ?? array();
		$directory  = $this->private_directory( $target );
		$hold       = $recovery['hold'] ?? '';
		if ( $target['components'] !== $descriptor['components'] || $target['identity'] !== $expected_identity ||
			'file' !== $target['type'] || ! is_string( $directory ) || ! $this->private_recovery_path( $hold, $target ) ||
			empty( $recovery['candidate_sha256'] ) || empty( $descriptor['preimage'] ) ||
			! hash_equals( $recovery['candidate_sha256'], (string) hash_file( 'sha256', $target['path'] ) ) ||
			is_link( $hold ) || ! is_file( $hold ) || 1 !== @lstat( $hold )['nlink'] ||
			! hash_equals( $descriptor['preimage'], (string) hash_file( 'sha256', $hold ) ) ) {
			return new WP_Error( 'filesystem_manual_recovery_required', 'The exact owned candidate and private preimage are not both available; preserve the evidence and reconcile manually.' );
		}
		$stream = @fopen( $hold, 'rb' );
		if ( ! is_resource( $stream ) ) {
			return new WP_Error( 'filesystem_manual_recovery_required', 'Private recovery preimage is unavailable.' );
		}
		try {
			return $this->apply_verified_source(
				$root_id,
				$relative,
				$purpose,
				array(
					'identity' => $target['identity'],
					'sha256'   => $recovery['candidate_sha256'],
				),
				array(
					'stream'    => $stream,
					'bytes'     => filesize( $hold ),
					'sha256'    => $descriptor['preimage'],
					'integrity' => 'sha256',
				),
				$recovery_id
			);
		} finally {
			fclose( $stream );
		}
	}

	private function private_recovery_path( $path, array $target ) {
		$base      = Safe_Temporary_Staging::directory();
		$directory = dirname( $path );
		$stat      = @lstat( $directory );
		$parent    = @lstat( dirname( $target['path'] ) );
		return is_string( $base ) && Filesystem_Roots::within( $directory, $base ) &&
			is_array( $stat ) && is_array( $parent ) && ! is_link( $directory ) && realpath( $directory ) === $directory &&
			0700 === ( $stat['mode'] & 0777 ) && $stat['dev'] === $parent['dev'] &&
			! Filesystem_Roots::served( $directory ) && 1 === preg_match( '/^hold-[a-f0-9]{48}$/D', basename( $path ) );
	}

	private function dispatch( $operation_id, array $target, array $descriptor, callable $effect ) {
		$journal    = $this->journal( $target );
		$descriptor = $this->request_descriptor( $target, $descriptor );
		$request    = $descriptor;
		unset( $request['recovery'] );
		$fingerprint = hash( 'sha256', serialize( $request ) );
		$claim       = $journal->reserve( $operation_id, $fingerprint, $descriptor );
		if ( is_wp_error( $claim ) || empty( $claim['dispatch_allowed'] ) ) {
			return $this->public_result( $claim );
		}
		$fresh = $this->mutation_target(
			$target['root_id'],
			$target['components'],
			$target['purpose'],
			'delete' === $descriptor['action']
		);
		if ( is_wp_error( $fresh ) || $fresh['identity'] !== $target['identity'] ||
			! $this->access->same_context( $target['root']['origin'] ) ) {
			return $this->public_result(
				$journal->complete(
					$claim['claim'],
					array(
						'outcome' => 'not_dispatched',
						'reason'  => 'authority_or_target_changed',
					)
				)
			);
		}
		$armed = $journal->arm( $claim['claim'] );
		if ( is_wp_error( $armed ) ) {
			return $armed;
		}
		// No WordPress callbacks occur between the durable arm and these native syscalls.
		set_error_handler(
			static function () {
				return true;
			}
		);
		try {
			$result = $effect();
		} catch ( \Throwable $error ) {
			$result = array(
				'outcome' => 'unknown',
				'reason'  => 'filesystem_protocol_interrupted',
			);
		} finally {
			restore_error_handler();
		}
		return $this->public_result( $journal->complete( $claim['claim'], $result ) );
	}
}
