<?php
/**
 * Actual network-principal and per-blog delegation boundaries for database reads.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Database_Abilities;
use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

function wpai199ms_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai199ms_render_controls( Settings $settings ) {
	$page = new Settings_Page( new Environment(), $settings );
	ob_start();
	try {
		$page->render_settings();
		$html = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	$controls = array();
	$processor = new WP_HTML_Tag_Processor( $html );
	while ( $processor->next_tag( 'INPUT' ) ) {
		$name = $processor->get_attribute( 'name' );
		foreach ( array( Settings::GROUP_DATABASE_INSPECTION, Settings::GROUP_DATABASE_READ, Settings::GROUP_SITE_READ ) as $key ) {
			if ( Settings::OPTION_NAME . '[' . $key . ']' === $name ) {
				$controls[ $key ] = null !== $processor->get_attribute( 'disabled' );
			}
		}
	}
	wpai199ms_assert( 3 === count( $controls ), 'Native Settings page omitted the expected database/ordinary inputs.' );
	return array( $controls, $html );
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
	$settings->register();
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
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	wp_set_current_user( $site_admin );
	wpai199ms_assert( current_user_can( 'manage_options' ) && ! is_super_admin(), 'Local administrator fixture has wrong native authority.' );
	wpai199ms_assert( ! $settings->can_configure_group( Settings::GROUP_DATABASE_INSPECTION ) && ! $settings->can_configure_group( Settings::GROUP_DATABASE_READ ) && $settings->can_configure_group( Settings::GROUP_SITE_READ ), 'Configuration authority did not distinguish network database grants from ordinary site settings.' );
	$local_enable = array( Settings::GROUP_DATABASE_INSPECTION => 1, Settings::GROUP_DATABASE_READ => 1, Settings::GROUP_SITE_READ => 0, Settings::GROUP_BUILDER_WRITE => 1 );
	$sanitized_enable = sanitize_option( Settings::OPTION_NAME, $local_enable );
	wpai199ms_assert( 0 === $sanitized_enable[ Settings::GROUP_DATABASE_INSPECTION ] && 0 === $sanitized_enable[ Settings::GROUP_DATABASE_READ ], 'Native registered sanitize_option allowed a local database enable.' );
	update_option( Settings::OPTION_NAME, $local_enable, false );
	$stored_enable = get_option( Settings::OPTION_NAME );
	wpai199ms_assert( 0 === $stored_enable[ Settings::GROUP_DATABASE_INSPECTION ] && 0 === $stored_enable[ Settings::GROUP_DATABASE_READ ] && 0 === $stored_enable[ Settings::GROUP_SITE_READ ] && 1 === $stored_enable[ Settings::GROUP_BUILDER_WRITE ], 'Native option update expanded database delegation or failed to save ordinary settings.' );
	list( $local_controls, $local_html ) = wpai199ms_render_controls( $settings );
	$explanation = __( 'Only a Super Admin with current network administration authority can change database delegation on multisite. Existing values are preserved when you save other settings.', 'wp-ai-bridge' );
	wpai199ms_assert( $local_controls[ Settings::GROUP_DATABASE_INSPECTION ] && $local_controls[ Settings::GROUP_DATABASE_READ ] && ! $local_controls[ Settings::GROUP_SITE_READ ] && str_contains( $local_html, esc_html( $explanation ) ), 'Local Settings UI did not disable/explain restricted database controls while retaining ordinary fields.' );

	wpai199ms_assert( ! $provider->can_read() && ! $provider->can_inspect(), 'Site-admin authority became network database authority.' );
	wpai199ms_assert( is_wp_error( $inspect->execute( array( 'action' => 'schema', 'table' => $GLOBALS['wpdb']->base_prefix . 'users' ) ) ), 'Site admin inspected shared network users through a main-site prefix.' );
	wp_set_current_user( $original_user );
	update_option( Settings::OPTION_NAME, $grants, false );
	$super_enabled = get_option( Settings::OPTION_NAME );
	wpai199ms_assert( 1 === $super_enabled[ Settings::GROUP_DATABASE_INSPECTION ] && 1 === $super_enabled[ Settings::GROUP_DATABASE_READ ] && $provider->can_read(), 'Actual Super Admin could not enable database grants through registered native updates.' );
	list( $super_controls, $super_html ) = wpai199ms_render_controls( $settings );
	wpai199ms_assert( ! $super_controls[ Settings::GROUP_DATABASE_INSPECTION ] && ! $super_controls[ Settings::GROUP_DATABASE_READ ] && ! str_contains( $super_html, esc_html( $explanation ) ), 'Authorized Super Admin retained disabled database controls or misleading restriction text.' );
	wp_set_current_user( $site_admin );
	update_option( Settings::OPTION_NAME, array( Settings::GROUP_DATABASE_INSPECTION => 0, Settings::GROUP_DATABASE_READ => 0, Settings::GROUP_SITE_READ => 1 ), false );
	$local_revoke = get_option( Settings::OPTION_NAME );
	wpai199ms_assert( 1 === $local_revoke[ Settings::GROUP_DATABASE_INSPECTION ] && 1 === $local_revoke[ Settings::GROUP_DATABASE_READ ] && 1 === $local_revoke[ Settings::GROUP_SITE_READ ], 'Local explicit zero changed Super Admin database grants or blocked an ordinary setting.' );
	update_option( Settings::OPTION_NAME, array( Settings::GROUP_SITE_READ => 0, Settings::GROUP_BUILDER_WRITE => 1 ), false );
	$local_omission = get_option( Settings::OPTION_NAME );
	wpai199ms_assert( 1 === $local_omission[ Settings::GROUP_DATABASE_INSPECTION ] && 1 === $local_omission[ Settings::GROUP_DATABASE_READ ] && 0 === $local_omission[ Settings::GROUP_SITE_READ ] && 1 === $local_omission[ Settings::GROUP_BUILDER_WRITE ], 'Disabled/omitted UI fields lost existing database grants during ordinary site-setting saves.' );
	wp_set_current_user( $original_user );
	$deny_network = static function ( $caps, $cap ) {
		return 'manage_network_options' === $cap ? array( 'do_not_allow' ) : $caps;
	};
	add_filter( 'map_meta_cap', $deny_network, 999, 2 );
	wpai199ms_assert( ! $provider->can_read(), 'Super Admin bypassed explicit native network-capability denial.' );
	wpai199ms_assert( ! $settings->can_configure_group( Settings::GROUP_DATABASE_READ ), 'Mapped native denial was ignored by database configuration authority.' );
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	$denied_change = get_option( Settings::OPTION_NAME );
	wpai199ms_assert( 1 === $denied_change[ Settings::GROUP_DATABASE_INSPECTION ] && 1 === $denied_change[ Settings::GROUP_DATABASE_READ ], 'Native option update revoked database grants despite explicit network-authority denial.' );

	remove_filter( 'map_meta_cap', $deny_network, 999 );
	$deny_network = null;
	wpai199ms_assert( $provider->can_read(), 'Network grant did not recover after removing synthetic denial.' );
	$grants[ Settings::GROUP_DATABASE_INSPECTION ] = 0;
	$grants[ Settings::GROUP_DATABASE_READ ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wpai199ms_assert( ! $provider->can_read(), 'Network database inspection revocation was cached.' );
	$super_revoked = get_option( Settings::OPTION_NAME );
	wpai199ms_assert( 0 === $super_revoked[ Settings::GROUP_DATABASE_INSPECTION ] && 0 === $super_revoked[ Settings::GROUP_DATABASE_READ ], 'Authorized Super Admin could not revoke both database grants through the native sanitizer/update path.' );

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
