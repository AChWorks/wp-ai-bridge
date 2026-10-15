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
	/**
	 * Create one empty owner-only file through WordPress's exclusive allocator.
	 *
	 * @param string $filename Already validated filename used only as a hint.
	 * @return string|WP_Error Absolute file path or a safe error.
	 */
	public static function create( $filename ) {
		foreach ( self::private_directories() as $root ) {
			// WordPress creates the inode exclusively. No unverified payload is
			// written to this path before all confinement checks succeed.
			$file = wp_tempnam( $filename, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR );
			if ( ! is_string( $file ) || '' === $file || is_link( $file ) || ! is_file( $file ) ) {
				continue;
			}
			$real = realpath( $file );
			clearstatcache( true, $file );
			if ( ! is_string( $real ) || dirname( $real ) !== $root || self::is_served_path( $real ) ||
				0 !== filesize( $file ) ) {
				// Never delete an unexpected path or nonempty file.
				if ( is_string( $real ) && dirname( $real ) === $root && 0 === filesize( $file ) ) {
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
	 * Locate the stable default private scratch root without creating files.
	 * Only internal consumers may use this location; do not expose its path.
	 *
	 * @return string|WP_Error
	 */
	public static function directory() {
		$directories = self::private_directories();
		if ( $directories ) {
			return $directories[0];
		}
		return new WP_Error(
			'temporary_storage_unsafe',
			__( 'A private temporary staging directory is unavailable; nothing was imported.', 'wp-ai-bridge' )
		);
	}

	/**
	 * Prefer the WordPress default when genuinely private so existing transfer
	 * journals remain at their original stable location across upgrades.
	 *
	 * @return array<int,string> Canonical, distinct writable private directories.
	 */
	private static function private_directories() {
		$candidates = array();
		if ( function_exists( 'get_temp_dir' ) ) {
			$candidates[] = get_temp_dir();
		}
		if ( function_exists( 'sys_get_temp_dir' ) ) {
			$candidates[] = sys_get_temp_dir();
		}
		$candidates[] = ini_get( 'upload_tmp_dir' );
		$directories  = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) || '' === $candidate ||
				! is_dir( $candidate ) || ! is_writable( $candidate ) ) {
				continue;
			}
			$path = realpath( $candidate );
			if ( ! is_string( $path ) || '' === $path ||
				isset( $directories[ $path ] ) || self::is_served_path( $path ) ) {
				continue;
			}
			$directories[ $path ] = $path;
		}
		return array_values( $directories );
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
