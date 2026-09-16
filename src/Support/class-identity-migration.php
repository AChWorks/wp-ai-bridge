<?php
/**
 * One-time WP Native Builder Bridge -> WP AI Bridge identity migration.
 *
 * This compatibility code exists only for the bounded v0.4.0 cutover and is
 * expected to be removed after the Owner confirms the real installation has
 * migrated successfully.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Support;

use WP_Error;

/**
 * Migrates durable Bridge-owned state to the canonical WP AI Bridge identity.
 */
final class Identity_Migration {
	const VERSION       = 1;
	const STATUS_OPTION = 'wp_ai_bridge_identity_migration';

	const LEGACY_SOURCE_RECOVERY = 'wp_native_builder_bridge_source_recovery';
	const LEGACY_SOURCE_LOCK     = 'wp_native_builder_bridge_source_lock';

	/** @var array<string,string> */
	private static $option_map = array(
		'wp_native_builder_bridge_settings'               => 'wp_ai_bridge_settings',
		'wp_native_builder_bridge_recent_actions'         => 'wp_ai_bridge_recent_actions',
		'wp_native_builder_bridge_oauth_instance'         => 'wp_ai_bridge_oauth_instance',
		'wp_native_builder_bridge_oauth_clients'          => 'wp_ai_bridge_oauth_clients',
		'wp_native_builder_bridge_oauth_clients_revision' => 'wp_ai_bridge_oauth_clients_revision',
	);

	/**
	 * Migrates every site in the current installation once.
	 *
	 * @return true|WP_Error
	 */
	public static function run() {
		$recovery = is_multisite()
			? get_site_option( self::LEGACY_SOURCE_RECOVERY, false )
			: get_option( self::LEGACY_SOURCE_RECOVERY, false );
		if ( false !== $recovery ) {
			return new WP_Error(
				'identity_migration_pending_source_recovery',
				'A pending legacy Source Editing recovery record exists. Reactivate v0.3.0, resolve or reconcile that recovery first, then retry the WP AI Bridge migration.'
			);
		}

		if ( is_multisite() ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				$result = self::migrate_current_site();
				restore_current_blog();
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			delete_site_option( self::LEGACY_SOURCE_LOCK );
			return true;
		}

		$result = self::migrate_current_site();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		delete_option( self::LEGACY_SOURCE_LOCK );
		return true;
	}

	/**
	 * Migrates one site's persisted Bridge-owned state.
	 *
	 * @return true|WP_Error
	 */
	private static function migrate_current_site() {
		$status = get_option( self::STATUS_OPTION, array() );
		if ( is_array( $status ) && self::VERSION === (int) ( $status['version'] ?? 0 ) && ! empty( $status['completed'] ) ) {
			return true;
		}
		if ( false !== get_option( self::STATUS_OPTION, false ) && ! empty( $status ) && ! is_array( $status ) ) {
			return new WP_Error( 'identity_migration_status_conflict', 'The WP AI Bridge migration status option contains an unexpected value.' );
		}

		$has_legacy_state = self::legacy_state_exists();
		if ( ! $has_legacy_state ) {
			return self::write_completion_status( false );
		}

		$result = self::preflight_options();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = self::preflight_workspace();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( self::$option_map as $legacy => $canonical ) {
			$result = self::migrate_option( $legacy, $canonical );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$result = self::migrate_workspace();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::retire_legacy_oauth_artifacts();
		delete_option( self::LEGACY_SOURCE_LOCK );

		$result = self::verify_retired_state();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::write_completion_status( true );
	}

	/**
	 * Records whether this installation actually consumed legacy state.
	 *
	 * @param bool $migrated_legacy Whether legacy data was migrated.
	 * @return true|WP_Error
	 */
	private static function write_completion_status( $migrated_legacy ) {
		$completed = array(
			'version'         => self::VERSION,
			'completed'       => true,
			'completed_gmt'   => gmdate( 'c' ),
			'migrated_legacy' => (bool) $migrated_legacy,
			'reconnect_oauth' => (bool) $migrated_legacy,
		);
		if ( ! update_option( self::STATUS_OPTION, $completed, false ) ) {
			$current = get_option( self::STATUS_OPTION, array() );
			if ( ! is_array( $current ) || self::VERSION !== (int) ( $current['version'] ?? 0 ) || empty( $current['completed'] ) ) {
				return new WP_Error( 'identity_migration_status_write_failed', 'WP AI Bridge could not persist the identity-migration completion marker.' );
			}
		}
		return true;
	}

	/** @return bool Whether this site still contains any legacy Bridge-owned state. */
	private static function legacy_state_exists() {
		global $wpdb;
		foreach ( array_keys( self::$option_map ) as $legacy ) {
			if ( null !== self::physical_option( $legacy ) ) {
				return true;
			}
		}
		$workspace = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('wpnb_doc','wpnb_task')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed migration identifiers only.
		);
		if ( $workspace > 0 ) {
			return true;
		}
		$legacy_oauth = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpnb_oauth_' ) . '%' )
		);
		return $legacy_oauth > 0 || false !== get_option( self::LEGACY_SOURCE_LOCK, false );
	}

	/** @return true|WP_Error */
	private static function preflight_options() {
		foreach ( self::$option_map as $legacy => $canonical ) {
			$old = self::physical_option( $legacy );
			$new = self::physical_option( $canonical );
			if ( null === $old || null === $new ) {
				continue;
			}
			$expected = self::transformed_option_value( $legacy, $old['option_value'] );
			if ( ! hash_equals( $expected, $new['option_value'] ) ) {
				return new WP_Error(
					'identity_migration_option_conflict',
					'Legacy and canonical WP AI Bridge options both exist with different values: ' . $legacy
				);
			}
		}
		return true;
	}

	/** @return true|WP_Error */
	private static function preflight_workspace() {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT legacy.post_id, legacy.meta_value AS legacy_value, canonical.meta_value AS canonical_value
				FROM {$wpdb->postmeta} legacy
				INNER JOIN {$wpdb->posts} posts ON posts.ID = legacy.post_id
				INNER JOIN {$wpdb->postmeta} canonical ON canonical.post_id = legacy.post_id AND canonical.meta_key = %s
				WHERE legacy.meta_key = %s AND posts.post_type IN (%s, %s, %s, %s)",
				'_wpai_workspace_state',
				'_wpnb_workspace_state',
				'wpnb_doc',
				'wpnb_task',
				'wpai_doc',
				'wpai_task'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'identity_migration_workspace_preflight_failed', 'WP AI Bridge could not inspect legacy Workspace metadata.' );
		}
		foreach ( $rows as $row ) {
			if ( ! hash_equals( (string) $row['legacy_value'], (string) $row['canonical_value'] ) ) {
				return new WP_Error( 'identity_migration_workspace_conflict', 'A Workspace record already contains conflicting legacy and canonical state.' );
			}
		}
		return true;
	}

	/** @return true|WP_Error */
	private static function migrate_option( $legacy, $canonical ) {
		global $wpdb;

		$old = self::physical_option( $legacy );
		if ( null === $old ) {
			return true;
		}
		$expected = self::transformed_option_value( $legacy, $old['option_value'] );
		$new      = self::physical_option( $canonical );
		if ( null === $new ) {
			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->options,
				array(
					'option_name'  => $canonical,
					'option_value' => $expected,
					'autoload'     => (string) $old['autoload'],
				),
				array( '%s', '%s', '%s' )
			);
			self::flush_option_caches( $canonical );
			$new = self::physical_option( $canonical );
			if ( false === $inserted && ( null === $new || ! hash_equals( $expected, $new['option_value'] ) ) ) {
				return new WP_Error( 'identity_migration_option_write_failed', 'WP AI Bridge could not create canonical option: ' . $canonical );
			}
		}
		if ( null === $new || ! hash_equals( $expected, $new['option_value'] ) ) {
			return new WP_Error( 'identity_migration_option_verify_failed', 'WP AI Bridge could not verify canonical option: ' . $canonical );
		}
		delete_option( $legacy );
		if ( null !== self::physical_option( $legacy ) ) {
			return new WP_Error( 'identity_migration_legacy_option_cleanup_failed', 'WP AI Bridge could not retire legacy option: ' . $legacy );
		}
		return true;
	}

	/** @return true|WP_Error */
	private static function migrate_workspace() {
		global $wpdb;

		$workspace_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('wpnb_doc','wpnb_task','wpai_doc','wpai_task')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed migration identifiers only.
		);
		$legacy_meta   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT pm.meta_id, pm.post_id, pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} posts ON posts.ID = pm.post_id
				WHERE pm.meta_key = %s AND posts.post_type IN (%s, %s, %s, %s)",
				'_wpnb_workspace_state',
				'wpnb_doc',
				'wpnb_task',
				'wpai_doc',
				'wpai_task'
			),
			ARRAY_A
		);
		if ( ! is_array( $legacy_meta ) ) {
			return new WP_Error( 'identity_migration_workspace_read_failed', 'WP AI Bridge could not read legacy Workspace state.' );
		}
		foreach ( $legacy_meta as $row ) {
			$canonical = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
					(int) $row['post_id'],
					'_wpai_workspace_state'
				),
				ARRAY_A
			);
			if ( ! empty( $canonical ) ) {
				if ( 1 !== count( $canonical ) || ! hash_equals( (string) $row['meta_value'], (string) $canonical[0]['meta_value'] ) ) {
					return new WP_Error( 'identity_migration_workspace_conflict', 'A Workspace record has ambiguous or conflicting canonical metadata.' );
				}
				$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->postmeta,
					array(
						'meta_id'  => (int) $row['meta_id'],
						'meta_key' => '_wpnb_workspace_state',
					),
					array( '%d', '%s' )
				);
				if ( false === $deleted ) {
					return new WP_Error( 'identity_migration_workspace_cleanup_failed', 'WP AI Bridge could not retire duplicate legacy Workspace metadata.' );
				}
				continue;
			}
			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->postmeta,
				array( 'meta_key' => '_wpai_workspace_state' ),
				array(
					'meta_id'  => (int) $row['meta_id'],
					'meta_key' => '_wpnb_workspace_state',
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
			if ( 1 !== $updated ) {
				return new WP_Error( 'identity_migration_workspace_meta_write_failed', 'WP AI Bridge could not migrate one Workspace metadata row.' );
			}
			wp_cache_delete( (int) $row['post_id'], 'post_meta' );
		}

		foreach ( array(
			'wpnb_doc'  => 'wpai_doc',
			'wpnb_task' => 'wpai_task',
		) as $legacy => $canonical ) {
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s", $canonical, $legacy )
			);
			if ( false === $updated ) {
				return new WP_Error( 'identity_migration_workspace_type_write_failed', 'WP AI Bridge could not migrate Workspace post type: ' . $legacy );
			}
		}
		foreach ( (array) $workspace_ids as $post_id ) {
			clean_post_cache( (int) $post_id );
		}
		return true;
	}

	/** @return void */
	private static function retire_legacy_oauth_artifacts() {
		global $wpdb;

		$prefixes = array(
			'wpnb_oauth_assertion_',
			'_transient_wpnb_oauth_',
			'_transient_timeout_wpnb_oauth_',
		);
		foreach ( $prefixes as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
			);
		}
		wp_clear_scheduled_hook( 'wpnb_oauth_cleanup_client_assertion' );
	}

	/** @return true|WP_Error */
	private static function verify_retired_state() {
		global $wpdb;
		foreach ( array_keys( self::$option_map ) as $legacy ) {
			if ( null !== self::physical_option( $legacy ) ) {
				return new WP_Error( 'identity_migration_legacy_state_remaining', 'A legacy WP Native Builder Bridge option remains after migration: ' . $legacy );
			}
		}
		$legacy_posts = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('wpnb_doc','wpnb_task')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed migration identifiers only.
		);
		if ( 0 !== $legacy_posts ) {
			return new WP_Error( 'identity_migration_legacy_workspace_remaining', 'Legacy Workspace post types remain after migration.' );
		}
		$legacy_meta = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} posts ON posts.ID = pm.post_id WHERE pm.meta_key = %s AND posts.post_type IN (%s, %s)",
				'_wpnb_workspace_state',
				'wpai_doc',
				'wpai_task'
			)
		);
		if ( 0 !== $legacy_meta ) {
			return new WP_Error( 'identity_migration_legacy_workspace_meta_remaining', 'Legacy Workspace metadata remains after migration.' );
		}
		return true;
	}


	/**
	 * Invalidates the Core option caches after an exact raw-row migration write.
	 *
	 * Direct SQL is used only so the historical serialized bytes/autoload mode can
	 * be preserved exactly. Core's alloptions/notoptions caches must therefore be
	 * invalidated explicitly before get_option() is used again in the request.
	 *
	 * @param string $name Canonical option name.
	 * @return void
	 */
	private static function flush_option_caches( $name ) {
		wp_cache_delete( (string) $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Returns one physical option row without applying option filters.
	 *
	 * @param string $name Option name.
	 * @return array{option_value:string,autoload:string}|null
	 */
	private static function physical_option( $name ) {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ),
			ARRAY_A
		);
		return is_array( $row ) ? array(
			'option_value' => (string) $row['option_value'],
			'autoload'     => (string) $row['autoload'],
		) : null;
	}

	/**
	 * Transforms only bounded historical metadata whose identifier is part of the public activity record.
	 *
	 * @param string $legacy_option Legacy option name.
	 * @param string $raw           Stored raw option bytes.
	 * @return string Canonical stored bytes.
	 */
	private static function transformed_option_value( $legacy_option, $raw ) {
		if ( 'wp_native_builder_bridge_recent_actions' !== $legacy_option ) {
			return (string) $raw;
		}
		$entries = maybe_unserialize( $raw );
		if ( ! is_array( $entries ) ) {
			return (string) $raw;
		}
		foreach ( $entries as &$entry ) {
			if ( is_array( $entry ) && isset( $entry['ability'] ) && is_string( $entry['ability'] ) && 0 === strpos( $entry['ability'], 'wp-native-builder/' ) ) {
				$entry['ability'] = 'wp-ai-bridge/' . substr( $entry['ability'], strlen( 'wp-native-builder/' ) );
			}
		}
		unset( $entry );
		return maybe_serialize( $entries );
	}
}
