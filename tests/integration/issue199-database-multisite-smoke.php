<?php
/**
 * Actual network-principal and per-blog delegation boundaries for database reads.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Database_Abilities;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

function wpai199ms_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$original_user = get_current_user_id();
$original_blog = get_current_blog_id();
$main_original = get_option( Settings::OPTION_NAME, false );
$secondary = 0;
$created_secondary = false;
$secondary_original = false;
$switched = false;
$site_admin = 0;
$deny_network = null;

try {
	wpai199ms_assert( is_multisite() && is_super_admin(), 'Database multisite fixture requires its synthetic Super Admin.' );
	$settings = new Settings();
	$provider = new Database_Abilities( new Permissions( $settings ), new Mutation_Log() );
	$inspect = wp_get_ability( 'wp-ai-bridge/database-inspect' );
	wpai199ms_assert( $inspect instanceof WP_Ability, 'Database inspection not registered on multisite.' );
	$grants = $settings->defaults();
	$grants[ Settings::GROUP_DATABASE_INSPECTION ] = 1;
	$grants[ Settings::GROUP_DATABASE_READ ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	wpai199ms_assert( $provider->can_read(), 'Real Super Admin/network grants did not enable database reads.' );
	wpai199ms_assert( ! is_wp_error( $inspect->execute( array( 'action' => 'tables', 'limit' => 1 ) ) ), 'Super Admin cannot inspect the installation database.' );
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 20 ) ) as $blog_id ) {
		if ( (int) $blog_id !== (int) $original_blog ) {
			$secondary = (int) $blog_id;
			break;
		}
	}
	if ( ! $secondary ) {
		$network = get_network();
		$secondary = wpmu_create_blog( $network->domain, trailingslashit( $network->path ) . 'wpai199-' . strtolower( wp_generate_password( 8, false, false ) ) . '/', 'Database administration test', $original_user, array(), $network->id );
		wpai199ms_assert( ! is_wp_error( $secondary ) && $secondary > 0, 'Cannot create a second synthetic site.' );
		$created_secondary = true;
	}

	switch_to_blog( $secondary );
	$switched = true;
	$secondary_original = get_option( Settings::OPTION_NAME, false );
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	wpai199ms_assert( ! $provider->can_read() && is_wp_error( $inspect->execute( array( 'action' => 'tables' ) ) ), 'Main-blog database grants leaked to another blog.' );
	update_option( Settings::OPTION_NAME, $grants, false );
	wpai199ms_assert( $provider->can_read(), 'Super Admin cannot use an explicitly granted secondary site.' );
	$suffix = wp_generate_password( 10, false, false );
	$site_admin = wp_insert_user( array( 'user_login' => 'wpai199ms-' . $suffix, 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'wpai199ms-' . $suffix . '@example.invalid' ) );
	wpai199ms_assert( ! is_wp_error( $site_admin ), 'Cannot create local administrator fixture.' );
	add_user_to_blog( $secondary, $site_admin, 'administrator' );
	wp_set_current_user( $site_admin );
	wpai199ms_assert( current_user_can( 'manage_options' ) && ! is_super_admin(), 'Local administrator fixture has wrong native authority.' );
	wpai199ms_assert( ! $provider->can_read() && ! $provider->can_inspect(), 'Site-admin authority became network database authority.' );
	wpai199ms_assert( is_wp_error( $inspect->execute( array( 'action' => 'schema', 'table' => $GLOBALS['wpdb']->base_prefix . 'users' ) ) ), 'Site admin inspected shared network users through a main-site prefix.' );
	wp_set_current_user( $original_user );
	$deny_network = static function ( $caps, $cap ) {
		return 'manage_network_options' === $cap ? array( 'do_not_allow' ) : $caps;
	};
	add_filter( 'map_meta_cap', $deny_network, 999, 2 );
	wpai199ms_assert( ! $provider->can_read(), 'Super Admin bypassed explicit native network-capability denial.' );
	remove_filter( 'map_meta_cap', $deny_network, 999 );
	$deny_network = null;
	wpai199ms_assert( $provider->can_read(), 'Network grant did not recover after removing synthetic denial.' );
	$grants[ Settings::GROUP_DATABASE_INSPECTION ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wpai199ms_assert( ! $provider->can_read(), 'Network database inspection revocation was cached.' );
	echo "PASS: Issue #199 multisite network/site authority and per-blog revocation.\n";
} finally {
	wp_set_current_user( $original_user );
	if ( null !== $deny_network ) {
		remove_filter( 'map_meta_cap', $deny_network, 999 );
	}
	if ( $switched ) {
		if ( false === $secondary_original ) {
			delete_option( Settings::OPTION_NAME );
		} else {
			update_option( Settings::OPTION_NAME, $secondary_original, false );
		}
		restore_current_blog();
	}
	if ( $created_secondary && is_int( $secondary ) && $secondary > 0 ) {
		wp_delete_site( $secondary );
	}
	if ( is_int( $site_admin ) && $site_admin > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $site_admin );
	}
	if ( false === $main_original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $main_original, false );
	}
}
