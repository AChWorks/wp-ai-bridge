<?php
/**
 * WordPress-installation-wide native extension mutation coordinator.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Serializes Bridge Core plugin/theme installs, including all multisite blogs
 * that share the same wp-content plugin/theme filesystem. MySQL named locks
 * are connection-scoped and automatically abandoned when the worker dies.
 */
final class Extension_Install_Lock {
	const WAIT_SECONDS = 0;

	/** @var string */
	private $name = '';

	/** @var bool */
	private $acquired = false;

	/** @var resource|null Held through the complete PHP/native Core operation. */
	private $file_handle = null;

	/** @var string Shared, inert lock filename within the extension filesystem. */
	private $file_path = '';

	/**
	 * Acquire the shared extension-filesystem lock BEFORE touching the DB
	 * coordinator. The descriptor survives wpdb::check_connection() reconnects
	 * and only releases when this PHP worker finishes/terminates.
	 *
	 * The lock file is empty and non-executable. 0644 permits a CI/WP-CLI
	 * process and the real web PHP user to lock the same inode read-only.
	 * Kernel advisory locks do not require write access to file contents.
	 *
	 * @return bool
	 */
	private function acquire_filesystem_lock() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return false;
		}
		$shared_root = realpath( WP_CONTENT_DIR );
		if ( false === $shared_root || ! is_dir( $shared_root ) ) {
			return false;
		}
		$path = rtrim( $shared_root, '/\\' ) . '/.wpai-extension-native-mutation.lock';
		clearstatcache( true, $path );
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			return false;
		}
		$handle = false;
		if ( ! file_exists( $path ) ) {
			// Exclusive creation prevents following a pre-existing symlink.
			$handle = @fopen( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Fixed inert installation lock, never a caller-controlled path.
			if ( false !== $handle && ! chmod( $path, 0644 ) ) {
				fclose( $handle );
				return false;
			}
		}
		if ( false === $handle ) {
			$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Only the canonical pre-existing inert lock inode, opened read-only.
		}
		if ( false === $handle ) {
			return false;
		}
		if ( ! $this->file_identity_matches( $path, $handle ) ||
			! flock( $handle, LOCK_EX | LOCK_NB ) ||
			! $this->file_identity_matches( $path, $handle ) ) {
			fclose( $handle );
			return false;
		}
		$this->file_handle = $handle;
		$this->file_path   = $path;
		return true;
	}

	/** Prevent symlink/filename replacement from creating competing lock inodes. */
	private function file_identity_matches( $path, $handle ) {
		if ( ! is_resource( $handle ) ) {
			return false;
		}
		clearstatcache( true, $path );
		$disk = lstat( $path );
		$open = fstat( $handle );
		return is_array( $disk ) && is_array( $open ) && ! is_link( $path ) &&
			0100000 === ( $disk['mode'] & 0170000 ) &&
			$disk['ino'] === $open['ino'] && $disk['dev'] === $open['dev'];
	}

	private function lock_name() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->base_prefix ) || ! defined( 'ABSPATH' ) ) {
			return '';
		}
		return 'wpai_ext_' . substr( hash( 'sha256', (string) $wpdb->base_prefix . '|' . (string) realpath( ABSPATH ) ), 0, 40 );
	}

	/** Acquire without waiting: contention is safe to retry before any Core effects. */
	public function acquire() {
		global $wpdb;
		if ( $this->acquired ) {
			return false;
		}
		$name = $this->lock_name();
		if ( '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! $this->acquire_filesystem_lock() ) {
			return false;
		}
		$result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed named-lock acquisition on one WordPress installation.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::WAIT_SECONDS ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fully prepared constant SQL.
		);
		if ( 1 !== (int) $result ) {
			$this->release();
			return false;
		}
		$this->name     = $name;
		$this->acquired = true;
		if ( ! $this->is_owned() ) {
			$this->release();
			return false;
		}
		return true;
	}

	/** Fail closed after any DB reconnect/lock loss before reporting Core success. */
	public function is_owned() {
		global $wpdb;
		if ( ! $this->acquired || '' === $this->name || ! isset( $wpdb ) || ! is_object( $wpdb ) ||
			! $this->file_identity_matches( $this->file_path, $this->file_handle ) ) {
			return false;
		}
		$owner   = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact named-lock owner lookup.
			$wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $this->name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fully prepared constant SQL.
		);
		$session = $wpdb->get_var( 'SELECT CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed connection identity.
		return (int) $owner > 0 && (int) $owner === (int) $session;
	}

	/** Never release an unrelated session's lock, even after reconnect. */
	public function release() {
		global $wpdb;
		if ( $this->is_owned() ) {
			$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release only this session's verified lock.
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fully prepared constant SQL.
			);
		}
		// Always retire our PHP-owned file descriptor, even if $wpdb reconnected
		// and its old named lock vanished. Never unlock a different DB session.
		if ( is_resource( $this->file_handle ) ) {
			flock( $this->file_handle, LOCK_UN );
			fclose( $this->file_handle );
		}
		$this->file_handle = null;
		$this->file_path   = '';
		$this->acquired    = false;
		$this->name        = '';
	}

	/** A PHP early exit must not retain the filesystem lock. */
	public function __destruct() {
		$this->release();
	}
}
