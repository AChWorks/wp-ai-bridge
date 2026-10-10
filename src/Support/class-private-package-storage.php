<?php
/**
 * Durable per-blog identity for private ZIP storage shared by web/cron/CLI.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * A database option AND an opaque marker inside the actual private directory
 * must agree. Neither a matching /tmp pathname nor a missing local directory
 * proves that another PHP worker's ZIPs have been retired.
 */
final class Private_Package_Storage {
	const DOMAIN_OPTION = 'wpai_private_zip_storage_v1';
	const MARKER        = '.wpai-storage-domain';

	/** @return WP_Error */
	private static function unavailable() {
		return new WP_Error( 'private_package_storage_unavailable', __( 'Private package storage is unavailable.', 'wp-ai-bridge' ) );
	}

	/** @return \WP_Filesystem_Direct Only fixed, non-public Bridge-owned marker paths. */
	private static function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		return new \WP_Filesystem_Direct( false );
	}

	/** @return string|WP_Error A canonical, server-derived, non-public site directory. */
	private static function path() {
		$temp = realpath( sys_get_temp_dir() );
		$web  = realpath( ABSPATH );
		if ( false === $temp || false === $web ) {
			return self::unavailable();
		}
		$temp = rtrim( $temp, '/\\' );
		foreach ( array( $web, defined( 'WP_CONTENT_DIR' ) ? realpath( WP_CONTENT_DIR ) : false, ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false ) as $public ) {
			if ( false !== $public && ( rtrim( $public, '/\\' ) === $temp || 0 === strpos( $temp . '/', rtrim( $public, '/\\' ) . '/' ) ) ) {
				return self::unavailable();
			}
		}
		$site_key = hash( 'sha256', $web . '|' . (string) get_current_blog_id() );
		return $temp . '/wpai-private-zip-' . substr( $site_key, 0, 24 );
	}

	/** Validate both actual marker inode and database identity before touching ZIPs. */
	private static function matches( $path, $identity ) {
		$marker = $path . '/' . self::MARKER;
		if ( ! is_string( $identity ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $identity ) ||
			! is_dir( $path ) || is_link( $path ) || ! is_file( $marker ) || is_link( $marker ) ) {
			return false;
		}
		clearstatcache( true, $path );
		clearstatcache( true, $marker );
		if ( 0 !== ( (int) fileperms( $path ) & 0077 ) || 0 !== ( (int) fileperms( $marker ) & 0077 ) ) {
			return false;
		}
		$contents = self::filesystem()->get_contents( $marker );
		return is_string( $contents ) && hash_equals( $identity, $contents );
	}

	/**
	 * Resolve storage without creating or binding a different root.
	 *
	 * @return string|null|WP_Error Null is allowed ONLY if no domain was ever
	 *                              bound and the current site path is absent.
	 */
	public static function existing_directory() {
		$path = self::path();
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$identity = get_option( self::DOMAIN_OPTION, false );
		if ( false === $identity && ! file_exists( $path ) && ! is_link( $path ) ) {
			return null;
		}
		return self::matches( $path, $identity ) ? $path : self::unavailable();
	}

	/** @return string|WP_Error Bind before the FIRST private executable ZIP is written. */
	public static function stage_directory() {
		$path = self::path();
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$identity = get_option( self::DOMAIN_OPTION, false );
		if ( false !== $identity ) {
			return self::matches( $path, $identity ) && is_writable( $path ) ? $path : self::unavailable();
		}
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_dir( $path ) ) ) {
			return self::unavailable();
		}
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return self::unavailable();
		}
		if ( ! is_dir( $path ) || is_link( $path ) || ! chmod( $path, 0700 ) || ! is_writable( $path ) ) {
			return self::unavailable();
		}
		// Unbound nonempty directories may contain old ZIPs from a different
		// storage domain. Never silently claim them as this PHP worker's root.
		$contents = scandir( $path );
		if ( ! is_array( $contents ) || array( '.', '..' ) !== $contents ) {
			return self::unavailable();
		}
		$marker = $path . '/' . self::MARKER;
		$token  = bin2hex( random_bytes( 32 ) );
		if ( ! self::filesystem()->put_contents( $marker, $token, 0600 ) ||
			is_link( $marker ) || 0 !== ( (int) fileperms( $marker ) & 0077 ) ||
			! add_option( self::DOMAIN_OPTION, $token, '', false ) ) {
			return self::unavailable();
		}
		return self::matches( $path, get_option( self::DOMAIN_OPTION, false ) ) ? $path : self::unavailable();
	}

	/** Remove the verified empty directory and retire the durable identity. */
	public static function retire_directory( $path ) {
		$expected = self::existing_directory();
		if ( is_wp_error( $expected ) || ! is_string( $expected ) || $expected !== $path ) {
			return false;
		}
		$remaining = scandir( $path );
		if ( ! is_array( $remaining ) || array( '.', '..', self::MARKER ) !== $remaining ) {
			return false;
		}
		$marker = $path . '/' . self::MARKER;
		wp_delete_file( $marker );
		clearstatcache( true, $marker );
		if ( is_file( $marker ) || is_link( $marker ) ) {
			return false;
		}
		self::filesystem()->rmdir( $path );
		clearstatcache( true, $path );
		if ( is_dir( $path ) || is_link( $path ) ) {
			return false;
		}
		delete_option( self::DOMAIN_OPTION );
		return false === get_option( self::DOMAIN_OPTION, false );
	}
}
