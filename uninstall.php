<?php
/**
 * Uninstall cleanup for WP Native Builder Bridge-owned settings and OAuth metadata.
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
 * consent/code/access/refresh artifact. The fixed ChatGPT metadata/JWKS caches,
 * short-lived client-assertion replay claims, and their cleanup events are also
 * removed. Persistent Workspace documents/tasks are intentionally preserved on
 * uninstall. Their explicit destructive lifecycle is WP Native Builder -> Settings
 * -> Clear Workspace, which requires administrator/destructive authorization. A pending
 * source-recovery record is also preserved: it owns bounded exact-preimage/replacement
 * artifacts that may still require reconciliation after reinstall.
 *
 * @return void
 */
function wp_native_builder_bridge_uninstall_site_options() {
	delete_option( 'wp_native_builder_bridge_settings' );
	delete_option( 'wp_native_builder_bridge_recent_actions' );
	delete_option( 'wp_native_builder_bridge_oauth_instance' );
	delete_transient( 'wpnb_oauth_chatgpt_cimd_ok' );
	delete_transient( 'wpnb_oauth_chatgpt_jwks' );
	delete_transient( 'wpnb_oauth_chatgpt_jwks_refresh' );
	wp_unschedule_hook( 'wpnb_oauth_cleanup_client_assertion' );

	// Client-assertion replay claims contain only a hashed JWT ID and expiry,
	// but uninstall should still remove every Bridge-owned claim immediately.
	global $wpdb;
	$assertion_prefix = $wpdb->esc_like( 'wpnb_oauth_assertion_' ) . '%';
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-only deletion of this plugin's bounded option prefix.
		$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $assertion_prefix ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table identifier is the current WordPress options table; the value is prepared below.
	);
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
