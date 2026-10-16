<?php
/**
 * Private temporary input staging for native WordPress sideload consumers.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Allocate only in a location outside known web-served WordPress roots.
 *
 * Temporary HTTP downloads and inbound bytes are not public Media until the
 * native consumer has accepted them. This intentionally fails closed when no
 * trustworthy scratch directory can be identified.
 */
final class Safe_Temporary_Staging {
	/** One-shot files older than a day are considered abandoned. */
	private const ORPHAN_SECONDS = 86400;

	/** Bounded entries examined on each normal allocation. */
	private const SWEEP_ENTRIES = 128;

	/**
	 * Create one empty owner-only file through WordPress's exclusive allocator.
	 *
	 * @param string $filename Validated original filename (not reused as a temp path).
	 * @return string|WP_Error Absolute file path or a safe error.
	 */
	public static function create( $filename ) {
		$directories = array();
		if ( function_exists( 'get_temp_dir' ) ) {
			$directories[] = get_temp_dir();
		}
		if ( function_exists( 'sys_get_temp_dir' ) ) {
			$directories[] = sys_get_temp_dir();
		}
		$php_upload = ini_get( 'upload_tmp_dir' );
		if ( is_string( $php_upload ) && '' !== $php_upload ) {
			$directories[] = $php_upload;
		}

		$attempted = array();
		foreach ( $directories as $directory ) {
			if ( ! is_string( $directory ) || '' === $directory || ! is_dir( $directory ) || ! is_writable( $directory ) ) {
				continue;
			}
			$root = realpath( $directory );
			if ( ! is_string( $root ) || '' === $root || isset( $attempted[ $root ] ) || self::is_served_path( $root ) ) {
				continue;
			}
			$attempted[ $root ] = true;
			$staging            = self::one_shot_directory( $root );
			if ( false === $staging ) {
				continue;
			}
			// These one-shot inputs never share a directory with permanent
			// transfer journals, claim fences or WordPress Media.
			self::reap_orphans( $staging );

			// WordPress creates the inode exclusively. No unverified payload is
			// written to this path before all confinement checks succeed.
			$file = wp_tempnam( 'wpai-native-stage', $staging . DIRECTORY_SEPARATOR );
			if ( ! is_string( $file ) || '' === $file || is_link( $file ) || ! is_file( $file ) ) {
				continue;
			}
			$real = realpath( $file );
			clearstatcache( true, $file );
			if ( ! is_string( $real ) || dirname( $real ) !== $staging || self::is_served_path( $real ) ||
				0 !== filesize( $file ) ) {
				// Never delete an unexpected path or nonempty file.
				if ( is_string( $real ) && dirname( $real ) === $staging && 0 === filesize( $file ) ) {
					wp_delete_file( $file );
				}
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- WordPress-owned empty temp inode, before sensitive bytes arrive.
			if ( 0600 !== ( fileperms( $file ) & 0777 ) && ! chmod( $file, 0600 ) ) {
				wp_delete_file( $file );
				continue;
			}
			clearstatcache( true, $file );
			if ( 0600 === ( fileperms( $file ) & 0777 ) ) {
				return $real;
			}
			wp_delete_file( $file );
		}

		return new WP_Error(
			'temporary_storage_unsafe',
			__( 'A private temporary staging directory is unavailable; nothing was imported.', 'wp-ai-bridge' )
		);
	}

	/**
	 * Own an isolated 0700 directory, stable across worker restarts, for transient
	 * Media/ZIP inputs only. Do not move private transfer journal roots here.
	 *
	 * @param string $base_directory Verified canonical PHP/OS temp path.
	 * @return string|false Canonical private directory or failure.
	 */
	private static function one_shot_directory( $base_directory ) {
		$identity = str_replace( '\\', '/', ABSPATH ) . "\0" .
			( defined( 'DB_NAME' ) ? DB_NAME : '' ) . "\0" .
			( function_exists( 'get_current_blog_id' ) ? (string) get_current_blog_id() : '1' );
		$path     = rtrim( $base_directory, '/\\' ) . DIRECTORY_SEPARATOR .
			'wpai-native-input-' . substr( hash( 'sha256', $identity ), 0, 32 );
		if ( is_link( $path ) ) {
			return false;
		}
		if ( ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Verify directory after concurrent mkdir below.
			if ( ! @mkdir( $path, 0700 ) && ! is_dir( $path ) ) {
				return false;
			}
		}
		clearstatcache( true, $path );
		$real = realpath( $path );
		if ( ! is_string( $real ) || dirname( $real ) !== $base_directory || is_link( $path ) ||
			! is_dir( $path ) || ! is_writable( $path ) || self::is_served_path( $real ) ||
			0700 !== ( fileperms( $path ) & 0777 ) ) {
			return false;
		}
		return $real;
	}

	/**
	 * Opportunistically retire crashed one-shot input files on later safe use.
	 * Each scan is bounded; idle sites do not promise wall-clock-time deletion.
	 *
	 * @param string $directory Canonical owner-only staging directory.
	 * @return void
	 */
	private static function reap_orphans( $directory ) {
		$cutoff = time() - self::ORPHAN_SECONDS;
		$seen   = 0;
		try {
			foreach ( new \DirectoryIterator( $directory ) as $entry ) {
				if ( $entry->isDot() ) {
					continue;
				}
				if ( ++$seen > self::SWEEP_ENTRIES ) {
					break;
				}
				$name = $entry->getFilename();
				if ( ! str_starts_with( $name, 'wpai-native-stage' ) ||
					$entry->isLink() || ! $entry->isFile() ) {
					continue;
				}
				$path = $directory . DIRECTORY_SEPARATOR . $name;
				// Recheck the inode and age; do not follow symlinks, delete hard
				// links, or touch files other than this class's one-shot inputs.
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent consumers may already have retired the inode.
				$stat = @lstat( $path );
				if ( ! is_array( $stat ) || 0100000 !== ( $stat['mode'] & 0170000 ) ||
					1 !== $stat['nlink'] || $stat['mtime'] > $cutoff || is_link( $path ) ) {
					continue;
				}
				wp_delete_file( $path );
			}
		} catch ( \UnexpectedValueException $error ) {
			// Filesystem racing with allocation is not grounds to widen access.
		}
	}

	/**
	 * Recognize WordPress directories or document roots even through symlinks.
	 *
	 * This is conservative: webserver aliases outside known roots still require
	 * host-level assessment. chmod alone never establishes HTTP confidentiality.
	 *
	 * @param string $path Canonical candidate directory or file.
	 * @return bool
	 */
	private static function is_served_path( $path ) {
		$roots = array( ABSPATH );
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = WP_CONTENT_DIR;
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$roots[] = WP_PLUGIN_DIR;
		}
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$roots[] = $_SERVER['DOCUMENT_ROOT'];
		}
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$uploads = wp_get_upload_dir();
			if ( is_array( $uploads ) && isset( $uploads['basedir'] ) ) {
				$roots[] = $uploads['basedir'];
			}
		}
		$normalized = str_replace( '\\', '/', $path );
		foreach ( $roots as $root ) {
			if ( ! is_string( $root ) || '' === $root ) {
				continue;
			}
			$canonical = realpath( $root );
			if ( ! is_string( $canonical ) || '' === $canonical ) {
				continue;
			}
			$prefix = rtrim( str_replace( '\\', '/', $canonical ), '/' );
			if ( $normalized === $prefix || 0 === strpos( $normalized, $prefix . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
