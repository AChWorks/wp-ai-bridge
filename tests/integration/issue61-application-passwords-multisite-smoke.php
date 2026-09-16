<?php
/**
 * Multisite Application Password authority coverage for Issue #61.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpai_issue61_ms_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue61_ms_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue61_ms_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

$settings           = new Settings();
$original_user      = get_current_user_id();
$original_blog      = get_current_blog_id();
$main_settings      = get_option( Settings::OPTION_NAME, false );
$secondary_blog     = 0;
$secondary_settings = false;
$ordinary_user      = 0;
$site_admin         = 0;
$main_uuid          = '';
$secondary_uuid     = '';
$self_uuid          = '';
$switched           = false;
$availability_enabled = false;

try {
	wpai_issue61_ms_assert( is_multisite(), 'Issue #61 multisite smoke requires multisite.' );
	wpai_issue61_ms_assert( is_super_admin( $original_user ), 'Issue #61 multisite smoke must begin as Super Admin.' );
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 20 ) ) as $site_id ) {
		if ( (int) $site_id !== (int) $original_blog ) {
			$secondary_blog = (int) $site_id;
			break;
		}
	}
	wpai_issue61_ms_assert( $secondary_blog > 0, 'Issue #61 multisite smoke requires a secondary site.' );

	$enabled                                   = $settings->defaults();
	$enabled[ Settings::GROUP_AUTHENTICATION ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	add_filter( 'wp_is_application_passwords_available', '__return_true' );
	$availability_enabled = true;

	$ordinary_user = wp_insert_user(
		array(
			'user_login' => 'issue61-ms-user-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'issue61-ms-user-' . wp_generate_password( 8, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpai_issue61_ms_assert( ! is_wp_error( $ordinary_user ) && $ordinary_user > 0, 'Could not create Issue #61 multisite ordinary user.' );
	wpai_issue61_ms_assert( is_user_member_of_blog( $ordinary_user, $original_blog ), 'Ordinary fixture is not a main-site member.' );

	$main_create = wpai_issue61_ms_execute(
		'wp-ai-bridge/application-password-create',
		array( 'user_id' => (int) $ordinary_user, 'name' => 'Issue 61 main-site fixture' )
	);
	wpai_issue61_ms_assert( ! is_wp_error( $main_create ), 'Super Admin could not manage Application Passwords for a main-site member.' );
	$main_uuid = $main_create['item']['uuid'];

	switch_to_blog( $secondary_blog );
	$switched           = true;
	$secondary_settings = get_option( Settings::OPTION_NAME, false );
	update_option( Settings::OPTION_NAME, $enabled, false );
	wpai_issue61_ms_assert( ! is_user_member_of_blog( $ordinary_user, $secondary_blog ), 'Ordinary fixture unexpectedly belongs to the secondary site.' );

	$nonmember = wpai_issue61_ms_execute(
		'wp-ai-bridge/application-passwords-read',
		array( 'action' => 'list', 'user_id' => (int) $ordinary_user )
	);
	wpai_issue61_ms_assert( is_wp_error( $nonmember ), 'Bridge bypassed Core multisite target-membership protection.' );

	add_user_to_blog( $secondary_blog, $ordinary_user, 'subscriber' );
	wpai_issue61_ms_assert( is_user_member_of_blog( $ordinary_user, $secondary_blog ), 'Could not add ordinary fixture to secondary site.' );
	$secondary_create = wpai_issue61_ms_execute(
		'wp-ai-bridge/application-password-create',
		array( 'user_id' => (int) $ordinary_user, 'name' => 'Issue 61 secondary fixture' )
	);
	wpai_issue61_ms_assert( ! is_wp_error( $secondary_create ), 'Super Admin could not use Core Application Password lifecycle for a secondary-site member.' );
	$secondary_uuid = $secondary_create['item']['uuid'];

	$site_admin = wp_insert_user(
		array(
			'user_login' => 'issue61-ms-admin-' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'issue61-ms-admin-' . wp_generate_password( 8, false, false ) . '@example.invalid',
		)
	);
	wpai_issue61_ms_assert( ! is_wp_error( $site_admin ) && $site_admin > 0, 'Could not create Issue #61 multisite site administrator.' );
	add_user_to_blog( $secondary_blog, $site_admin, 'administrator' );
	wpai_issue61_ms_assert( ! is_super_admin( $site_admin ), 'Site-administrator fixture unexpectedly became Super Admin.' );
	wp_set_current_user( $site_admin );

	$super_denied = wpai_issue61_ms_execute(
		'wp-ai-bridge/application-passwords-read',
		array( 'action' => 'list', 'user_id' => (int) $original_user )
	);
	wpai_issue61_ms_assert( is_wp_error( $super_denied ), 'Non-Super-Admin site administrator bypassed the Super Admin user-management boundary.' );

	$self_create = wpai_issue61_ms_execute(
		'wp-ai-bridge/application-password-create',
		array( 'user_id' => (int) $site_admin, 'name' => 'Issue 61 self fixture' )
	);
	wpai_issue61_ms_assert( ! is_wp_error( $self_create ), 'Core self-user Application Password authority was not preserved for a site administrator.' );
	$self_uuid = $self_create['item']['uuid'];
	$self_delete = wpai_issue61_ms_execute(
		'wp-ai-bridge/application-password-delete',
		array( 'user_id' => (int) $site_admin, 'uuid' => $self_uuid )
	);
	wpai_issue61_ms_assert( ! is_wp_error( $self_delete ) && true === $self_delete['deleted'], 'Site administrator could not revoke an authorized self Application Password.' );
	$self_uuid = '';

	wp_set_current_user( $original_user );
	$secondary_delete = wpai_issue61_ms_execute(
		'wp-ai-bridge/application-password-delete',
		array( 'user_id' => (int) $ordinary_user, 'uuid' => $secondary_uuid )
	);
	wpai_issue61_ms_assert( ! is_wp_error( $secondary_delete ), 'Super Admin could not revoke the secondary-site Application Password fixture.' );
	$secondary_uuid = '';

	restore_current_blog();
	$switched = false;
	$main_delete = wpai_issue61_ms_execute(
		'wp-ai-bridge/application-password-delete',
		array( 'user_id' => (int) $ordinary_user, 'uuid' => $main_uuid )
	);
	wpai_issue61_ms_assert( ! is_wp_error( $main_delete ), 'Super Admin could not revoke the main-site Application Password fixture.' );
	$main_uuid = '';

	echo "PASS: Issue #61 multisite Application Password authority and membership boundaries.\n";
} finally {
	if ( $availability_enabled ) {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}
	wp_set_current_user( $original_user );
	if ( $switched ) {
		restore_current_blog();
	}
	if ( $ordinary_user > 0 ) {
		WP_Application_Passwords::delete_all_application_passwords( $ordinary_user );
	}
	if ( $site_admin > 0 ) {
		WP_Application_Passwords::delete_all_application_passwords( $site_admin );
	}
	if ( false === $main_settings ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $main_settings, false );
	}
	if ( $secondary_blog > 0 ) {
		switch_to_blog( $secondary_blog );
		if ( false === $secondary_settings ) {
			delete_option( Settings::OPTION_NAME );
		} else {
			update_option( Settings::OPTION_NAME, $secondary_settings, false );
		}
		restore_current_blog();
	}
	if ( $site_admin > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $site_admin );
	}
	if ( $ordinary_user > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $ordinary_user );
	}
}
