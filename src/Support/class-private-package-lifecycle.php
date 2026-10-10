<?php
/**
 * Private ZIP deactivation and uninstall lifecycle (works without plugin boot).
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

require_once __DIR__ . '/class-extension-install-lock.php';

/**
 * Retire only Bridge-owned inert ZIPs and disposable options. A claimed or
 * ambiguous install leaves minimal metadata, never executable archive bytes.
 * The helper can be required directly by WordPress uninstall.php.
 */
final class Private_Package_Lifecycle {
	const META_PREFIX  = 'wpai_private_zip_v1_';
	const CLAIM_PREFIX = 'wpai_private_zip_claim_v1_';
	const CRON_HOOK    = 'wpai_private_zip_cleanup';

	/** @return bool Return false rather than asserting success after I/O uncertainty. */
	public static function cleanup_current_blog() {
		if ( ! self::remove_private_files() ) {
			return false;
		}
		// A claimed in-flight/unknown install must stay blocked after reinstall.
		$meta_ok = self::walk_options(
			self::META_PREFIX,
			static function ( $option_name, $id ) {
				$meta    = get_option( $option_name, false );
				$claimed = false !== get_option( self::CLAIM_PREFIX . $id, false );
				if ( is_array( $meta ) && ( $claimed && ! in_array( $meta['status'] ?? '', array( 'installed', 'expired' ), true ) ||
					in_array( $meta['status'] ?? '', array( 'installing', 'outcome_unknown' ), true ) ) ) {
					// Preserve only the recovery identity, hash, and ownership.
					$minimal = array(
						'id'              => $id,
						'kind'            => in_array( $meta['kind'] ?? '', array( 'plugin', 'theme' ), true ) ? $meta['kind'] : '',
						'user_id'         => (int) ( $meta['user_id'] ?? 0 ),
						'blog_id'         => (int) ( $meta['blog_id'] ?? 0 ),
						'client_id'       => substr( (string) ( $meta['client_id'] ?? '' ), 0, 256 ),
						'client_revision' => (int) ( $meta['client_revision'] ?? 0 ),
						'filename'        => '',
						'sha256'          => preg_match( '/^[0-9a-f]{64}$/D', (string) ( $meta['sha256'] ?? '' ) ) ? $meta['sha256'] : '',
						'bytes'           => 0,
						'root'            => substr( (string) ( $meta['root'] ?? '' ), 0, 128 ),
						'created'         => (int) ( $meta['created'] ?? time() ),
						'expires'         => (int) ( $meta['expires'] ?? time() ),
						'status'          => 'outcome_unknown',
						'target'          => substr( (string) ( $meta['target'] ?? '' ), 0, 256 ),
					);
					if ( $minimal === $meta ) {
						return true;
					}
					update_option( $option_name, $minimal, false );
					return get_option( $option_name, false ) === $minimal;
				}
				delete_option( $option_name );
				return false === get_option( $option_name, false );
			}
		);
		if ( ! $meta_ok ) {
			return false;
		}
		$claims_ok = self::walk_options(
			self::CLAIM_PREFIX,
			static function ( $option_name, $id ) {
				$meta = get_option( self::META_PREFIX . $id, false );
				if ( is_array( $meta ) && 'outcome_unknown' === ( $meta['status'] ?? '' ) ) {
					return true;
				}
				delete_option( $option_name );
				return false === get_option( $option_name, false );
			}
		);
		if ( ! $claims_ok ) {
			return false;
		}
		wp_unschedule_hook( self::CRON_HOOK );
		return ! wp_next_scheduled( self::CRON_HOOK );
	}

	/** @return bool Deactivation/uninstall must not race a native Core install. */
	public static function cleanup_sites( $network_wide ) {
		$lock = new Extension_Install_Lock();
		if ( ! $lock->acquire() ) {
			return false;
		}
		try {
			if ( ! $network_wide || ! is_multisite() ) {
				return $lock->is_owned() && self::cleanup_current_blog();
			}
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			if ( ! is_array( $site_ids ) || ! $site_ids ) {
				return false;
			}
			foreach ( $site_ids as $site_id ) {
				if ( ! $lock->is_owned() ) {
					return false;
				}
				switch_to_blog( (int) $site_id );
				try {
					if ( ! self::cleanup_current_blog() ) {
						return false;
					}
				} finally {
					restore_current_blog();
				}
			}
			return $lock->is_owned();
		} finally {
			$lock->release();
		}
	}

	/** Iterate a fixed prefix through bounded indexed pages, including non-autoload options. */
	private static function walk_options( $prefix, $visitor ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! is_callable( $visitor ) ) {
			return false;
		}
		$cursor  = 0;
		$pattern = $wpdb->esc_like( $prefix ) . '%';
		do {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-only bounded option-key pagination for Bridge-owned prefixes.
				$wpdb->prepare( "SELECT option_id, option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT %d", $pattern, $cursor, 128 ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted current-blog table and prepared values.
				ARRAY_A
			);
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				return false;
			}
			foreach ( $rows as $row ) {
				$cursor = (int) ( $row['option_id'] ?? 0 );
				$name   = (string) ( $row['option_name'] ?? '' );
				if ( 1 !== preg_match( '/^' . preg_quote( $prefix, '/' ) . '([0-9a-f]{48})$/D', $name, $matches ) ) {
					continue;
				}
				if ( ! $visitor( $name, $matches[1] ) ) {
					return false;
				}
			}
		} while ( count( $rows ) === 128 );
		return true;
	}

	/** Remove only valid opaque ZIP filenames under our non-symlink private directory. */
	private static function remove_private_files() {
		$temp = realpath( sys_get_temp_dir() );
		$web  = realpath( ABSPATH );
		if ( false === $temp || false === $web ) {
			return false;
		}
		$site_key = hash( 'sha256', rtrim( $web, '/\\' ) . '|' . (string) get_current_blog_id() );
		$path     = rtrim( $temp, '/\\' ) . '/wpai-private-zip-' . substr( $site_key, 0, 24 );
		if ( is_link( $path ) ) {
			return false;
		}
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( ! is_dir( $path ) ) {
			return false;
		}
		$files = glob( $path . '/*.zip' );
		if ( ! is_array( $files ) ) {
			return false;
		}
		foreach ( $files as $file ) {
			if ( 1 !== preg_match( '/^[0-9a-f]{48}\.zip$/D', basename( $file ) ) || is_link( $file ) || ! is_file( $file ) ) {
				return false;
			}
			wp_delete_file( $file );
			clearstatcache( true, $file );
			if ( is_file( $file ) || is_link( $file ) ) {
				return false;
			}
		}
		// WordPress's non-recursive direct filesystem API removes only our empty
		// directory. Unknown files are never deleted to make this step succeed.
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$filesystem = new \WP_Filesystem_Direct( false );
		$filesystem->rmdir( $path );
		clearstatcache( true, $path );
		return ! is_dir( $path );
	}
}
