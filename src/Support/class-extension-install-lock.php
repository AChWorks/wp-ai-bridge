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
		if ( '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed named-lock acquisition on one WordPress installation.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::WAIT_SECONDS ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fully prepared constant SQL.
		);
		if ( 1 !== (int) $result ) {
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
		if ( ! $this->acquired || '' === $this->name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
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
		$this->acquired = false;
		$this->name     = '';
	}
}
