<?php
/**
 * Uninstall cleanup for WP AI Bridge-owned settings and OAuth metadata.
 *
 * @package WP_Native_Builder_Bridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes only disposable Bridge settings, activity, lock, and OAuth metadata.
 *
 * Removing the OAuth installation identity invalidates every outstanding
 * consent/code/access/refresh artifact. Built-in ChatGPT and additional-client
 * metadata/JWKS caches, approval state, short-lived assertion replay claims,
 * and their cleanup events are also removed. Persistent Workspace documents/tasks
 * are intentionally preserved on uninstall. Their explicit destructive lifecycle
 * is WP AI Bridge -> Settings -> Clear Workspace, which requires administrator/
 * destructive authorization. A pending source-recovery record is also preserved:
 * it owns bounded exact-preimage/replacement artifacts that may still require
 * reconciliation after reinstall.
 *
 * @return void
 */
function wp_native_builder_bridge_uninstall_site_options() {
	delete_option( 'wp_native_builder_bridge_settings' );
	delete_option( 'wp_native_builder_bridge_recent_actions' );
	delete_option( 'wp_native_builder_bridge_oauth_instance' );
	delete_option( 'wp_native_builder_bridge_oauth_clients' );
	delete_option( 'wp_native_builder_bridge_oauth_clients_revision' );
	delete_transient( 'wpnb_oauth_chatgpt_cimd_ok' );
	delete_transient( 'wpnb_oauth_chatgpt_jwks' );
	delete_transient( 'wpnb_oauth_chatgpt_jwks_refresh' );
	wp_unschedule_hook( 'wpnb_oauth_cleanup_client_assertion' );

	global $wpdb;
	// Assertion claims contain only a hashed JWT ID and expiry. Dynamic metadata/JWKS
	// transients contain only public client metadata/public signing keys. Remove every
	// Bridge-owned bounded prefix so uninstall leaves no disposable OAuth state behind.
	$prefixes = array(
		'wpnb_oauth_assertion_',
		'_transient_wpnb_oauth_client_meta_',
		'_transient_timeout_wpnb_oauth_client_meta_',
		'_transient_wpnb_oauth_jwks_',
		'_transient_timeout_wpnb_oauth_jwks_',
		'_transient_wpnb_oauth_jwks_refresh_',
		'_transient_timeout_wpnb_oauth_jwks_refresh_',
	);
	foreach ( $prefixes as $prefix ) {
		$like = $wpdb->esc_like( $prefix ) . '%';
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-only deletion of this plugin's bounded option/transient prefixes.
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table identifier is the current WordPress options table; the value is prepared.
		);
	}
}

if ( is_multisite() ) {
	delete_site_option( 'wp_native_builder_bridge_source_lock' );
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		wp_native_builder_bridge_uninstall_site_options();
		restore_current_blog();
	}
} else {
	delete_option( 'wp_native_builder_bridge_source_lock' );
	wp_native_builder_bridge_uninstall_site_options();
}
