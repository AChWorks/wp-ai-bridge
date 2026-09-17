<?php
/**
 * One-time migration into canonical WP AI Bridge Workspace identities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge;

use RuntimeException;

/**
 * Migrates only persistent legacy Workspace records into WP AI Bridge.
 */
final class Migrator {
	const SCHEMA_OPTION  = 'wp_ai_bridge_schema_version';
	const SCHEMA_VERSION = 1;

	const LEGACY_PLUGIN = 'wp-native-builder-bridge/wp-native-builder-bridge.php';

	/**
	 * WordPress activation callback.
	 *
	 * @param bool $network_wide Whether this is a network activation.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( self::LEGACY_PLUGIN ) || ( is_multisite() && is_plugin_active_for_network( self::LEGACY_PLUGIN ) ) ) {
			wp_die( esc_html__( 'Deactivate and remove WP Native Builder Bridge before activating WP AI Bridge.', 'wp-ai-bridge' ) );
		}

		try {
			if ( is_multisite() && $network_wide ) {
				$site_ids = get_sites(
					array(
						'fields' => 'ids',
						'number' => 0,
					)
				);
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( (int) $site_id );
					self::migrate_site();
					restore_current_blog();
				}
				self::retire_legacy_network_state();
			} else {
				self::migrate_site();
			}
		} catch ( RuntimeException $exception ) {
			wp_die(
				esc_html( $exception->getMessage() ),
				esc_html__( 'WP AI Bridge migration stopped safely', 'wp-ai-bridge' ),
				array( 'response' => 500 )
			);
		}
	}

	/**
	 * Migrates one site's persistent Workspace rows and retires orphaned legacy runtime state.
	 *
	 * @return void
	 */
	private static function migrate_site() {
		global $wpdb;

		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) < self::SCHEMA_VERSION ) {
			self::assert_workspace_conflicts_absent();
			$requires_transactional_storage = self::has_legacy_workspace_state();
			$pre_migration_state            = null;

			$started = false;
			try {
				if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One-time migration transaction on WordPress-owned tables.
					throw new RuntimeException( 'WP AI Bridge could not establish the required Workspace migration transaction.' );
				}
				$started = true;

				// Read all migration-owned tables inside the transaction before checking
				// their engines. This also holds their metadata identity stable while the
				// transaction proceeds, so the verified engines cannot change mid-migration.
				$pre_migration_state = self::workspace_migration_fingerprint();
				if ( $requires_transactional_storage ) {
					self::assert_transactional_workspace_tables();
				}

				self::migrate_workspace_rows();

				if ( ! update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false ) ) {
					$current = (int) get_option( self::SCHEMA_OPTION, 0 );
					if ( self::SCHEMA_VERSION !== $current ) {
						throw new RuntimeException( 'WP AI Bridge could not persist the canonical Workspace schema version.' );
					}
				}

				self::verify_workspace_migration();

				if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Completes the bounded migration transaction.
					throw new RuntimeException( 'WP AI Bridge could not commit the Workspace migration transaction.' );
				}
				$started = false;
				wp_cache_flush();
			} catch ( \Throwable $exception ) {
				if ( $started ) {
					if ( false === $wpdb->query( 'ROLLBACK' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Restores pre-migration database state on failure.
						wp_cache_flush();
						throw new RuntimeException( 'WP AI Bridge Workspace migration failed and rollback could not be confirmed: ' . $exception->getMessage() );
					}
				}
				wp_cache_flush();
				if ( null !== $pre_migration_state ) {
					self::assert_workspace_state_matches( $pre_migration_state );
				}
				throw new RuntimeException( 'WP AI Bridge Workspace migration failed closed: ' . $exception->getMessage() );
			}
		}

		// Retire disposable legacy state only after the durable Workspace migration is
		// committed. This cleanup is repeat-safe and runs again on later activations.
		self::retire_legacy_site_state();
		self::verify_legacy_runtime_state_retired();
	}

	/**
	 * Returns whether this site still has legacy Workspace identities to migrate.
	 *
	 * @return bool
	 */
	private static function has_legacy_workspace_state() {
		global $wpdb;

		$legacy_posts = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('wpnb_doc','wpnb_task')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact bounded migration inventory.
		$legacy_meta  = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wpnb_workspace_state'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact bounded migration inventory.
		if ( null === $legacy_posts || null === $legacy_meta ) {
			throw new RuntimeException( 'WP AI Bridge could not inspect legacy Workspace state before migration.' );
		}

		return (int) $legacy_posts > 0 || (int) $legacy_meta > 0;
	}

	/**
	 * Requires rollback-capable storage for every table mutated by Workspace migration.
	 *
	 * @return void
	 */
	private static function assert_transactional_workspace_tables() {
		global $wpdb;

		foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->options ) as $table ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One-time storage-engine preflight for migration-owned WordPress tables.
			if ( ! is_string( $engine ) || '' === trim( $engine ) ) {
				throw new RuntimeException( 'WP AI Bridge could not verify InnoDB transactional storage for migration table ' . $table . '.' );
			}
			if ( 'INNODB' !== strtoupper( trim( $engine ) ) ) {
				throw new RuntimeException( 'WP AI Bridge Workspace migration requires InnoDB transactional storage; table ' . $table . ' uses ' . $engine . '.' );
			}
		}
	}

	/**
	 * Returns a stable fingerprint of every migration-sensitive Workspace identity.
	 *
	 * @return string
	 */
	private static function workspace_migration_fingerprint() {
		global $wpdb;

		$posts       = $wpdb->get_results( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_type IN ('wpnb_doc','wpnb_task','wpai_doc','wpai_task') ORDER BY ID", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded pre/post rollback verification for migration-owned identities.
		$meta        = $wpdb->get_results( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_wpnb_workspace_state','_wpai_workspace_state') ORDER BY meta_id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded pre/post rollback verification for migration-owned identities.
		$schema_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::SCHEMA_OPTION
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct database fingerprint avoids stale option-cache state after rollback.
		if ( ! is_array( $posts ) || ! is_array( $meta ) || ! is_array( $schema_rows ) ) {
			throw new RuntimeException( 'WP AI Bridge could not inspect Workspace state for migration rollback protection.' );
		}

		return hash(
			'sha256',
			wp_json_encode(
				array(
					'posts'          => $posts,
					'meta'           => $meta,
					'schema_version' => $schema_rows ? (string) $schema_rows[0]['option_value'] : false,
				)
			)
		);
	}

	/**
	 * Verifies that a failed transaction restored the exact pre-migration identities.
	 *
	 * @param string $expected Expected pre-migration fingerprint.
	 * @return void
	 */
	private static function assert_workspace_state_matches( $expected ) {
		if ( ! hash_equals( $expected, self::workspace_migration_fingerprint() ) ) {
			throw new RuntimeException( 'WP AI Bridge could not verify restoration of the pre-migration Workspace state.' );
		}
	}

	/** @return void */
	private static function assert_workspace_conflicts_absent() {
		global $wpdb;
		$legacy = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('wpnb_doc','wpnb_task')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact bounded migration inventory.
		$new    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('wpai_doc','wpai_task')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact bounded migration inventory.
		if ( $legacy > 0 && $new > 0 ) {
			throw new RuntimeException( 'Both legacy and canonical WP AI Bridge Workspace records exist; automatic migration would be ambiguous.' );
		}

		$meta_conflicts = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} legacy INNER JOIN {$wpdb->postmeta} canonical ON canonical.post_id = legacy.post_id WHERE legacy.meta_key = '_wpnb_workspace_state' AND canonical.meta_key = '_wpai_workspace_state'"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact bounded migration conflict check.
		if ( $meta_conflicts > 0 ) {
			throw new RuntimeException( 'Conflicting legacy and canonical Workspace metadata exists.' );
		}
	}

	/** @return void */
	private static function migrate_workspace_rows() {
		global $wpdb;
		$updates = array(
			'wpnb_doc'  => 'wpai_doc',
			'wpnb_task' => 'wpai_task',
		);
		foreach ( $updates as $legacy => $canonical ) {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s", $canonical, $legacy ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time exact Workspace post-type migration.
			if ( false === $result ) {
				throw new RuntimeException( 'WP AI Bridge could not migrate legacy Workspace record type ' . $legacy . '.' );
			}
		}

		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s", '_wpai_workspace_state', '_wpnb_workspace_state' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time exact Workspace metadata-key migration.
		if ( false === $result ) {
			throw new RuntimeException( 'WP AI Bridge could not migrate legacy Workspace metadata.' );
		}
	}

	/**
	 * Removes legacy state that the owner explicitly chose not to migrate.
	 *
	 * The old v0.3.0 uninstall already removes most of these values. These exact
	 * deletes cover any orphaned remnants while deliberately leaving only the
	 * Workspace rows that were migrated above.
	 *
	 * @return void
	 */
	private static function retire_legacy_site_state() {
		delete_option( 'wp_native_builder_bridge_settings' );
		delete_option( 'wp_native_builder_bridge_recent_actions' );
		delete_option( 'wp_native_builder_bridge_oauth_instance' );
		delete_option( 'wp_native_builder_bridge_oauth_clients' );
		delete_option( 'wp_native_builder_bridge_oauth_clients_revision' );
		delete_option( 'wp_native_builder_bridge_source_recovery' );
		delete_option( 'wp_native_builder_bridge_source_lock' );

		delete_transient( 'wpnb_oauth_chatgpt_cimd_ok' );
		delete_transient( 'wpnb_oauth_chatgpt_jwks' );
		delete_transient( 'wpnb_oauth_chatgpt_jwks_refresh' );
		wp_clear_scheduled_hook( 'wpnb_oauth_cleanup_client_assertion' );

		global $wpdb;
		$prefixes = array(
			'wpnb_oauth_assertion_',
			'_transient_wpnb_oauth_',
			'_transient_timeout_wpnb_oauth_',
		);
		foreach ( $prefixes as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Retires bounded legacy OAuth/runtime remnants that are explicitly outside migration scope.
		}
	}

	/** @return void */
	private static function retire_legacy_network_state() {
		if ( ! is_multisite() ) {
			return;
		}
		delete_site_option( 'wp_native_builder_bridge_source_recovery' );
		delete_site_option( 'wp_native_builder_bridge_source_lock' );
	}

	/** @return void */
	private static function verify_workspace_migration() {
		global $wpdb;
		$legacy_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('wpnb_doc','wpnb_task')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact post-migration verification.
		$legacy_meta  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wpnb_workspace_state'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact post-migration verification.
		if ( 0 !== $legacy_posts || 0 !== $legacy_meta ) {
			throw new RuntimeException( 'Legacy Workspace storage identifiers remained after migration.' );
		}
	}

	/** @return void */
	private static function verify_legacy_runtime_state_retired() {
		foreach (
			array(
				'wp_native_builder_bridge_settings',
				'wp_native_builder_bridge_recent_actions',
				'wp_native_builder_bridge_oauth_instance',
				'wp_native_builder_bridge_oauth_clients',
				'wp_native_builder_bridge_oauth_clients_revision',
				'wp_native_builder_bridge_source_recovery',
				'wp_native_builder_bridge_source_lock',
			) as $legacy_runtime_option
		) {
			if ( false !== get_option( $legacy_runtime_option, false ) ) {
				throw new RuntimeException( 'Disposable legacy runtime state remained after canonical migration: ' . $legacy_runtime_option . '.' );
			}
		}
	}
}
