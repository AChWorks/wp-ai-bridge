<?php
/** Disposable multisite network authority smoke. */
use WP_AI_Bridge\Support\Settings;
function wpai141_network_assert( $ok, $message ) {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
}
wpai141_network_assert( is_multisite(), 'Expected multisite.' );
$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$network_admin = get_current_user_id();
$site_admin = 0;
try {
	$ability = wp_get_ability( 'wp-ai-bridge/core-update-status' );
	wpai141_network_assert( $ability instanceof WP_Ability, 'Core diagnostic not registered.' );
	$enabled = $settings->defaults();
	$enabled[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	wpai141_network_assert( is_super_admin( $network_admin ) && true === $ability->check_permissions( array() ), 'Super Admin cannot read network cache.' );
	$username = 'wpai_diag_' . strtolower( wp_generate_password( 10, false, false ) );
	$site_admin = wp_create_user( $username, wp_generate_password( 24 ), $username . '@example.invalid' );
	wpai141_network_assert( ! is_wp_error( $site_admin ), 'Could not create disposable site user.' );
	$site_admin = (int) $site_admin;
	$user = new WP_User( $site_admin );
	$user->set_role( 'administrator' );
	wpai141_network_assert( ! is_super_admin( $site_admin ), 'Site user has network authority.' );
	wp_set_current_user( $site_admin );
	wpai141_network_assert( current_user_can( 'manage_options' ), 'Fixture is not site admin.' );
	wpai141_network_assert( ! current_user_can( 'manage_network_options' ), 'Site admin has network authority.' );
	wpai141_network_assert( true !== $ability->check_permissions( array() ), 'Site admin exposed Core network cache.' );
	wpai141_network_assert( is_wp_error( $ability->execute( array() ) ), 'Direct execution bypassed network authority.' );
	echo "PASS: Core-update-status rejects multisite site admin.\n";
} finally {
	wp_set_current_user( $network_admin );
	update_option( Settings::OPTION_NAME, $original, false );
	if ( $site_admin > 0 && function_exists( 'wpmu_delete_user' ) ) { wpmu_delete_user( $site_admin ); }
}
