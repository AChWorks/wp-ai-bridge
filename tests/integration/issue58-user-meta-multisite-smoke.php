<?php
/**
 * Multisite authority coverage for Issue #58 user metadata.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai_issue58_ms_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue58_ms_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue58_ms_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

$settings              = new Settings();
$original_user         = get_current_user_id();
$original_blog         = get_current_blog_id();
$main_settings_exists  = false !== get_option( Settings::OPTION_NAME, false );
$main_settings         = get_option( Settings::OPTION_NAME, array() );
$secondary_blog        = 0;
$secondary_settings    = array();
$secondary_exists      = false;
$switched              = false;
$ordinary_user         = 0;
$site_admin            = 0;
$original_base_prefix  = null;
$original_table_prefix = null;

try {
	wpai_issue58_ms_assert( is_multisite(), 'Issue #58 multisite smoke must run on multisite.' );
	wpai_issue58_ms_assert( is_super_admin( $original_user ), 'Issue #58 multisite smoke must begin as Super Admin.' );

	global $wpdb, $table_prefix;
	$original_base_prefix  = (string) $wpdb->base_prefix;
	$original_table_prefix = (string) $table_prefix;

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
	wpai_issue58_ms_assert( $secondary_blog > 0, 'Issue #58 multisite smoke requires a secondary site.' );

	$ordinary_user = wp_insert_user(
		array(
			'user_login' => 'issue58-ms-user-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'issue58-ms-user-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpai_issue58_ms_assert( ! is_wp_error( $ordinary_user ) && $ordinary_user > 0, 'Could not create ordinary multisite user fixture.' );

	$site_admin = wp_insert_user(
		array(
			'user_login' => 'issue58-ms-admin-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'issue58-ms-admin-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'administrator',
		)
	);
	wpai_issue58_ms_assert( ! is_wp_error( $site_admin ) && $site_admin > 0, 'Could not create multisite site-administrator fixture.' );
	wpai_issue58_ms_assert( ! is_super_admin( $site_admin ), 'Site-administrator fixture unexpectedly became Super Admin.' );

	add_user_meta( $ordinary_user, 'issue58_ms_normal', 'network-safe', true );
	$super_read = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id'        => (int) $ordinary_user,
			'key'            => 'issue58_ms_normal',
			'include_values' => true,
		)
	);
	wpai_issue58_ms_assert( ! is_wp_error( $super_read ), 'Super Admin could not read authorized ordinary user metadata.' );
	wpai_issue58_ms_assert( '"network-safe"' === $super_read['items'][0]['values'][0]['value_json'], 'Authorized multisite user metadata returned the wrong value.' );

	wp_set_current_user( $site_admin );
	wpai_issue58_ms_assert( ! current_user_can( 'edit_user', $ordinary_user ), 'Multisite site-admin fixture unexpectedly has native edit_user authority for another account.' );
	$denied_read = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id' => (int) $ordinary_user,
			'key'     => 'issue58_ms_normal',
		)
	);
	wpai_issue58_ms_assert( is_wp_error( $denied_read ), 'Generic user metadata bypassed native multisite edit_user authority.' );

	$before_normal = get_user_meta( $ordinary_user, 'issue58_ms_normal', true );
	$denied_update = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $ordinary_user,
			'key'                 => 'issue58_ms_normal',
			'expected_state_hash' => str_repeat( '0', 64 ),
			'value_json'          => '"bypass"',
		)
	);
	wpai_issue58_ms_assert( is_wp_error( $denied_update ), 'Generic user metadata update bypassed native multisite edit_user authority.' );
	wpai_issue58_ms_assert( get_user_meta( $ordinary_user, 'issue58_ms_normal', true ) === $before_normal, 'Denied multisite metadata update changed user state.' );

	wpai_issue58_ms_assert( ! current_user_can( 'edit_user', $original_user ), 'Non-Super-Admin fixture unexpectedly has native authority over a Super Admin.' );
	$denied_super = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id' => (int) $original_user,
			'key'     => 'issue58_ms_normal',
		)
	);
	wpai_issue58_ms_assert( is_wp_error( $denied_super ), 'Generic user metadata bypassed the multisite Super Admin boundary.' );
	wp_set_current_user( $original_user );

	add_user_to_blog( $secondary_blog, $ordinary_user, 'subscriber' );
	switch_to_blog( $secondary_blog );
	$switched           = true;
	$secondary_exists   = false !== get_option( Settings::OPTION_NAME, false );
	$secondary_settings = get_option( Settings::OPTION_NAME, array() );
	update_option( Settings::OPTION_NAME, $access, false );

	$role_key = $wpdb->get_blog_prefix( $secondary_blog ) . 'capabilities';
	wpai_issue58_ms_assert( metadata_exists( 'user', $ordinary_user, $role_key ), 'Secondary-site role metadata fixture is missing.' );
	$role_before = get_user_meta( $ordinary_user, $role_key, true );

	$role_read = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id'        => (int) $ordinary_user,
			'key'            => $role_key,
			'include_values' => true,
		)
	);
	wpai_issue58_ms_assert( is_wp_error( $role_read ), 'Site-specific capability metadata was exposed to generic user metadata.' );

	$broad = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-read',
		array( 'user_id' => (int) $ordinary_user )
	);
	wpai_issue58_ms_assert( ! is_wp_error( $broad ), 'Bounded multisite user metadata discovery failed.' );
	$normal_seen = false;
	foreach ( $broad['items'] as $item ) {
		wpai_issue58_ms_assert( $role_key !== $item['key'], 'Site-specific capability metadata leaked through broad discovery.' );
		wpai_issue58_ms_assert( empty( $item['values'] ), 'Broad user metadata discovery returned a metadata value.' );
		if ( 'issue58_ms_normal' === $item['key'] ) {
			$normal_seen = true;
		}
	}
	wpai_issue58_ms_assert( $normal_seen, 'Multisite broad metadata discovery did not include the ordinary authorized fixture key.' );

	$role_update = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $ordinary_user,
			'key'                 => $role_key,
			'expected_state_hash' => str_repeat( '0', 64 ),
			'value_json'          => '{"administrator":true}',
		)
	);
	wpai_issue58_ms_assert( is_wp_error( $role_update ), 'Generic user metadata accepted site-specific role/capability mutation.' );
	wpai_issue58_ms_assert( get_user_meta( $ordinary_user, $role_key, true ) === $role_before, 'Rejected role/capability mutation changed multisite authority state.' );

	restore_current_blog();
	$switched = false;
	wpai_issue58_ms_assert( get_current_blog_id() === $original_blog, 'Could not return to the multisite main-site fixture.' );
	wpai_issue58_ms_assert( 1 === (int) $original_blog, 'Delimiterless-prefix coverage requires the standard multisite main site ID 1 fixture.' );

	wp_set_current_user( $ordinary_user );
	wpai_issue58_ms_assert( current_user_can( 'edit_user', $ordinary_user ), 'WordPress did not preserve native self edit_user authority for the multisite fixture.' );

	$delimiterless_cap_key   = 'wpcapabilities';
	$delimiterless_level_key = 'wpuser_level';
	delete_user_meta( $ordinary_user, $delimiterless_cap_key );
	delete_user_meta( $ordinary_user, $delimiterless_level_key );
	$empty_cap   = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id' => (int) $ordinary_user,
			'key'     => $delimiterless_cap_key,
		)
	);
	$empty_level = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id' => (int) $ordinary_user,
			'key'     => $delimiterless_level_key,
		)
	);
	wpai_issue58_ms_assert( ! is_wp_error( $empty_cap ) && 0 === $empty_cap['items'][0]['count'], 'Could not capture the empty delimiterless capability state.' );
	wpai_issue58_ms_assert( ! is_wp_error( $empty_level ) && 0 === $empty_level['items'][0]['count'], 'Could not capture the empty delimiterless user-level state.' );
	add_user_meta( $ordinary_user, $delimiterless_cap_key, array( 'subscriber' => true ), true );
	add_user_meta( $ordinary_user, $delimiterless_level_key, 0, true );
	$cap_state   = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id' => (int) $ordinary_user,
			'key'     => $delimiterless_cap_key,
		)
	);
	$level_state = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-read',
		array(
			'user_id' => (int) $ordinary_user,
			'key'     => $delimiterless_level_key,
		)
	);
	wpai_issue58_ms_assert( ! is_wp_error( $cap_state ) && ! is_wp_error( $level_state ), 'Could not capture delimiterless-prefix precondition state.' );

	$prefix_probe = clone $wpdb;
	$prefix_probe->set_prefix( 'wp' );
	wpai_issue58_ms_assert( 'wp' === $prefix_probe->base_prefix, 'WordPress rejected the delimiterless wp table prefix fixture.' );

	// Preserve the real integration tables while making get_blog_prefix() expose the
	// authority identities produced by a valid delimiterless base prefix.
	$wpdb->base_prefix = 'wp';
	$table_prefix      = 'wp';
	wpai_issue58_ms_assert( $delimiterless_cap_key === $wpdb->get_blog_prefix( $original_blog ) . 'capabilities', 'Delimiterless capability identity did not match WordPress get_blog_prefix().' );
	wpai_issue58_ms_assert( $delimiterless_level_key === $wpdb->get_blog_prefix( $original_blog ) . 'user_level', 'Delimiterless user-level identity did not match WordPress get_blog_prefix().' );

	foreach ( array( $delimiterless_cap_key, $delimiterless_level_key ) as $authority_key ) {
		$exact = wpai_issue58_ms_execute(
			'wp-ai-bridge/user-meta-read',
			array(
				'user_id'        => (int) $ordinary_user,
				'key'            => $authority_key,
				'include_values' => true,
			)
		);
		wpai_issue58_ms_assert( is_wp_error( $exact ), 'Delimiterless WordPress authority metadata escaped exact-read exclusion.' );
	}

	$delimiterless_broad = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-read',
		array( 'user_id' => (int) $ordinary_user )
	);
	wpai_issue58_ms_assert( ! is_wp_error( $delimiterless_broad ), 'Delimiterless-prefix broad self-user discovery failed.' );
	foreach ( $delimiterless_broad['items'] as $item ) {
		wpai_issue58_ms_assert( $delimiterless_cap_key !== $item['key'] && $delimiterless_level_key !== $item['key'], 'Delimiterless WordPress authority metadata leaked through broad discovery.' );
	}

	$delimiterless_update = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $ordinary_user,
			'key'                 => $delimiterless_cap_key,
			'expected_state_hash' => $cap_state['items'][0]['state_hash'],
			'value_json'          => '{"administrator":true}',
		)
	);
	wpai_issue58_ms_assert( is_wp_error( $delimiterless_update ), 'Delimiterless WordPress capability metadata accepted an exact update.' );
	wpai_issue58_ms_assert( array( 'subscriber' => true ) === get_user_meta( $ordinary_user, $delimiterless_cap_key, true ), 'Denied delimiterless capability update changed state.' );

	$delimiterless_level_update = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $ordinary_user,
			'key'                 => $delimiterless_level_key,
			'expected_state_hash' => $level_state['items'][0]['state_hash'],
			'value_json'          => '7',
		)
	);
	wpai_issue58_ms_assert( is_wp_error( $delimiterless_level_update ), 'Delimiterless WordPress user-level metadata accepted an exact update.' );
	wpai_issue58_ms_assert( 0 === (int) get_user_meta( $ordinary_user, $delimiterless_level_key, true ), 'Denied delimiterless user-level update changed state.' );

	$delimiterless_cap_delete = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-delete',
		array(
			'user_id'             => (int) $ordinary_user,
			'key'                 => $delimiterless_cap_key,
			'expected_state_hash' => $cap_state['items'][0]['state_hash'],
		)
	);
	wpai_issue58_ms_assert( is_wp_error( $delimiterless_cap_delete ) && metadata_exists( 'user', $ordinary_user, $delimiterless_cap_key ), 'Delimiterless WordPress capability metadata accepted delete.' );

	$delimiterless_level_delete = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-delete',
		array(
			'user_id'             => (int) $ordinary_user,
			'key'                 => $delimiterless_level_key,
			'expected_state_hash' => $level_state['items'][0]['state_hash'],
		)
	);
	wpai_issue58_ms_assert( is_wp_error( $delimiterless_level_delete ) && metadata_exists( 'user', $ordinary_user, $delimiterless_level_key ), 'Delimiterless WordPress user-level metadata accepted delete.' );

	delete_user_meta( $ordinary_user, $delimiterless_cap_key );
	delete_user_meta( $ordinary_user, $delimiterless_level_key );
	$delimiterless_cap_create = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $ordinary_user,
			'key'                 => $delimiterless_cap_key,
			'expected_state_hash' => $empty_cap['items'][0]['state_hash'],
			'value_json'          => '{"administrator":true}',
		)
	);
	wpai_issue58_ms_assert( is_wp_error( $delimiterless_cap_create ) && ! metadata_exists( 'user', $ordinary_user, $delimiterless_cap_key ), 'Delimiterless WordPress capability metadata accepted create.' );
	$delimiterless_level_create = wpai_issue58_ms_execute(
		'wp-ai-bridge/user-meta-update',
		array(
			'user_id'             => (int) $ordinary_user,
			'key'                 => $delimiterless_level_key,
			'expected_state_hash' => $empty_level['items'][0]['state_hash'],
			'value_json'          => '7',
		)
	);
	wpai_issue58_ms_assert( is_wp_error( $delimiterless_level_create ) && ! metadata_exists( 'user', $ordinary_user, $delimiterless_level_key ), 'Delimiterless WordPress user-level metadata accepted create.' );

	$wpdb->base_prefix = $original_base_prefix;
	$table_prefix      = $original_table_prefix;
	wp_set_current_user( $original_user );

	echo "PASS: Issue #58 multisite user authority, role metadata, and delimiterless-prefix boundaries.\n";
} finally {
	if ( null !== $original_base_prefix ) {
		$wpdb->base_prefix = $original_base_prefix;
	}
	if ( null !== $original_table_prefix ) {
		$table_prefix = $original_table_prefix;
	}
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
