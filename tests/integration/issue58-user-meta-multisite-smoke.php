<?php
/**
 * Multisite authority coverage for Issue #58 user metadata.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue58_ms_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue58_ms_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpnb_issue58_ms_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

$settings             = new Settings();
$original_user        = get_current_user_id();
$original_blog        = get_current_blog_id();
$main_settings_exists = false !== get_option( Settings::OPTION_NAME, false );
$main_settings        = get_option( Settings::OPTION_NAME, array() );
$secondary_blog       = 0;
$secondary_settings   = array();
$secondary_exists     = false;
$switched             = false;
$ordinary_user        = 0;
$site_admin           = 0;

try {
	wpnb_issue58_ms_assert( is_multisite(), 'Issue #58 multisite smoke must run on multisite.' );
	wpnb_issue58_ms_assert( is_super_admin( $original_user ), 'Issue #58 multisite smoke must begin as Super Admin.' );

	$access                                      = $settings->defaults();
	$access[ Settings::GROUP_ADVANCED_METADATA ] = 1;
	$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );

	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 20,
		)
	);
	foreach ( $site_ids as $site_id ) {
		if ( (int) $site_id !== (int) $original_blog ) {
			$secondary_blog = (int) $site_id;
			break;
		}
	}
	wpnb_issue58_ms_assert( $secondary_blog > 0, 'Issue #58 multisite smoke requires a secondary site.' );

	$ordinary_user = wp_insert_user(
		array(
			'user_login' => 'issue58-ms-user-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'issue58-ms-user-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpnb_issue58_ms_assert( ! is_wp_error( $ordinary_user ) && $ordinary_user > 0, 'Could not create ordinary multisite user fixture.' );

	$site_admin = wp_insert_user(
		array(
			'user_login' => 'issue58-ms-admin-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'issue58-ms-admin-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'administrator',
		)
	);
	wpnb_issue58_ms_assert( ! is_wp_error( $site_admin ) && $site_admin > 0, 'Could not create multisite site-administrator fixture.' );
	wpnb_issue58_ms_assert( ! is_super_admin( $site_admin ), 'Site-administrator fixture unexpectedly became Super Admin.' );

	add_user_meta( $ordinary_user, 'issue58_ms_normal', 'network-safe', true );
	$super_read = wpnb_issue58_ms_execute(
		'wp-native-builder/user-meta-read',
		array(
			'user_id'        => (int) $ordinary_user,
			'key'            => 'issue58_ms_normal',
			'include_values' => true,
		)
	);
	wpnb_issue58_ms_assert( ! is_wp_error( $super_read ), 'Super Admin could not read authorized ordinary user metadata.' );
	wpnb_issue58_ms_assert( '"network-safe"' === $super_read['items'][0]['values'][0]['value_json'], 'Authorized multisite user metadata returned the wrong value.' );

	wp_set_current_user( $site_admin );
	wpnb_issue58_ms_assert( ! current_user_can( 'edit_user', $ordinary_user ), 'Multisite site-admin fixture unexpectedly has native edit_user authority for another account.' );
	$denied_read = wpnb_issue58_ms_execute(
		'wp-native-builder/user-meta-read',
		array(
			'user_id' => (int) $ordinary_user,
			'key'     => 'issue58_ms_normal',
		)
	);
	wpnb_issue58_ms_assert( is_wp_error( $denied_read ), 'Generic user metadata bypassed native multisite edit_user authority.' );

	$before_normal = get_user_meta( $ordinary_user, 'issue58_ms_normal', true );
	$denied_update = wpnb_issue58_ms_execute(
		'wp-native-builder/user-meta-update',
		array(
			'user_id'             => (int) $ordinary_user,
			'key'                 => 'issue58_ms_normal',
			'expected_state_hash' => str_repeat( '0', 64 ),
			'value_json'          => '"bypass"',
		)
	);
	wpnb_issue58_ms_assert( is_wp_error( $denied_update ), 'Generic user metadata update bypassed native multisite edit_user authority.' );
	wpnb_issue58_ms_assert( $before_normal === get_user_meta( $ordinary_user, 'issue58_ms_normal', true ), 'Denied multisite metadata update changed user state.' );

	wpnb_issue58_ms_assert( ! current_user_can( 'edit_user', $original_user ), 'Non-Super-Admin fixture unexpectedly has native authority over a Super Admin.' );
	$denied_super = wpnb_issue58_ms_execute(
		'wp-native-builder/user-meta-read',
		array(
			'user_id' => (int) $original_user,
			'key'     => 'issue58_ms_normal',
		)
	);
	wpnb_issue58_ms_assert( is_wp_error( $denied_super ), 'Generic user metadata bypassed the multisite Super Admin boundary.' );
	wp_set_current_user( $original_user );

	add_user_to_blog( $secondary_blog, $ordinary_user, 'subscriber' );
	switch_to_blog( $secondary_blog );
	$switched           = true;
	$secondary_exists   = false !== get_option( Settings::OPTION_NAME, false );
	$secondary_settings = get_option( Settings::OPTION_NAME, array() );
	update_option( Settings::OPTION_NAME, $access, false );

	global $wpdb;
	$role_key = $wpdb->get_blog_prefix( $secondary_blog ) . 'capabilities';
	wpnb_issue58_ms_assert( metadata_exists( 'user', $ordinary_user, $role_key ), 'Secondary-site role metadata fixture is missing.' );
	$role_before = get_user_meta( $ordinary_user, $role_key, true );

	$role_read = wpnb_issue58_ms_execute(
		'wp-native-builder/user-meta-read',
		array(
			'user_id'        => (int) $ordinary_user,
			'key'            => $role_key,
			'include_values' => true,
		)
	);
	wpnb_issue58_ms_assert( is_wp_error( $role_read ), 'Site-specific capability metadata was exposed to generic user metadata.' );

	$broad = wpnb_issue58_ms_execute(
		'wp-native-builder/user-meta-read',
		array( 'user_id' => (int) $ordinary_user )
	);
	wpnb_issue58_ms_assert( ! is_wp_error( $broad ), 'Bounded multisite user metadata discovery failed.' );
	$normal_seen = false;
	foreach ( $broad['items'] as $item ) {
		wpnb_issue58_ms_assert( $role_key !== $item['key'], 'Site-specific capability metadata leaked through broad discovery.' );
		wpnb_issue58_ms_assert( empty( $item['values'] ), 'Broad user metadata discovery returned a metadata value.' );
		if ( 'issue58_ms_normal' === $item['key'] ) {
			$normal_seen = true;
		}
	}
	wpnb_issue58_ms_assert( $normal_seen, 'Multisite broad metadata discovery did not include the ordinary authorized fixture key.' );

	$role_update = wpnb_issue58_ms_execute(
		'wp-native-builder/user-meta-update',
		array(
			'user_id'             => (int) $ordinary_user,
			'key'                 => $role_key,
			'expected_state_hash' => str_repeat( '0', 64 ),
			'value_json'          => '{"administrator":true}',
		)
	);
	wpnb_issue58_ms_assert( is_wp_error( $role_update ), 'Generic user metadata accepted site-specific role/capability mutation.' );
	wpnb_issue58_ms_assert( $role_before === get_user_meta( $ordinary_user, $role_key, true ), 'Rejected role/capability mutation changed multisite authority state.' );

	echo "PASS: Issue #58 multisite user authority and role-metadata boundaries.\n";
} finally {
	wp_set_current_user( $original_user );
	if ( $switched ) {
		if ( $secondary_exists ) {
			update_option( Settings::OPTION_NAME, $secondary_settings, false );
		} else {
			delete_option( Settings::OPTION_NAME );
		}
		restore_current_blog();
	}
	if ( get_current_blog_id() !== $original_blog ) {
		switch_to_blog( $original_blog );
	}
	if ( $main_settings_exists ) {
		update_option( Settings::OPTION_NAME, $main_settings, false );
	} else {
		delete_option( Settings::OPTION_NAME );
	}
}
