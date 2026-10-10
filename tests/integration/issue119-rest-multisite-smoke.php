<?php
/**
 * Issue #119: generic REST execution across actual multisite principals/blogs.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai119ms_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings            = new Settings();
$main_blog           = get_current_blog_id();
$original_user       = get_current_user_id();
$main_original       = get_option( Settings::OPTION_NAME, false );
$secondary_blog      = 0;
$secondary_original  = false;
$site_admin_id       = 0;
$switched            = false;
$calls               = 0;

try {
	wpai119ms_check( is_multisite() && is_super_admin( $original_user ), 'Native generic REST multisite test needs a Super Admin.' );
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 20 ) ) as $id ) {
		if ( (int) $id !== (int) $main_blog ) {
			$secondary_blog = (int) $id;
			break;
		}
	}
	wpai119ms_check( $secondary_blog > 0, 'Native generic REST multisite fixture has no second site.' );
	$ability = wp_get_ability( 'wp-ai-bridge/rest-route-invoke' );
	wpai119ms_check( $ability instanceof WP_Ability, 'Generic REST Ability is unavailable on this multisite.' );

	$server = rest_get_server();
	$server->register_route(
		'wpai119ms/v1',
		'/wpai119ms/v1/site',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () use ( &$calls ) {
					++$calls;
					return array(
						'blog_id' => (int) get_current_blog_id(),
						'user_id' => (int) get_current_user_id(),
					);
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			),
		)
	);
	$input = array(
		'route'  => '/wpai119ms/v1/site',
		'path'   => '/wpai119ms/v1/site',
		'method' => 'GET',
	);
	$full_grants = $settings->defaults();
	foreach ( $settings->groups() as $group => $definition ) {
		$full_grants[ $group ] = 1;
	}
	update_option( Settings::OPTION_NAME, $full_grants, false );
	$first = $ability->execute( $input );
	wpai119ms_check(
		! is_wp_error( $first ) && 'reported_success' === $first['outcome'] &&
		(int) $main_blog === $first['data']['blog_id'] && (int) $original_user === $first['data']['user_id'],
		'Super Admin did not execute provider REST under main-site and principal identity.'
	);
	wpai119ms_check( 1 === $calls, 'Main-site provider callback did not run exactly once.' );

	switch_to_blog( $secondary_blog );
	$switched = true;
	$secondary_original = get_option( Settings::OPTION_NAME, false );
	$no_grant = $ability->execute( $input );
	wpai119ms_check( is_wp_error( $no_grant ) && 1 === $calls, 'Secondary site inherited main-site REST grant.' );
	update_option( Settings::OPTION_NAME, $full_grants, false );

	$site_admin_id = wp_insert_user(
		array(
			'user_login' => 'wpai119-ms-' . wp_generate_password( 9, false, false ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'wpai119-ms-' . wp_generate_password( 9, false, false ) . '@example.invalid',
		)
	);
	wpai119ms_check( ! is_wp_error( $site_admin_id ) && (int) $site_admin_id > 0, 'Cannot establish isolated non-network-admin fixture.' );
	add_user_to_blog( $secondary_blog, $site_admin_id, 'administrator' );
	wp_set_current_user( $site_admin_id );
	wpai119ms_check( ! is_super_admin( $site_admin_id ) && current_user_can( 'manage_options' ), 'Site admin fixture has incorrect site/network authority.' );
	$site_admin_result = $ability->execute( $input );
	wpai119ms_check( is_wp_error( $site_admin_result ) && 1 === $calls, 'Non-Super-Admin executed unknown protected provider semantics without all native network capabilities.' );

	wp_set_current_user( $original_user );
	$second = $ability->execute( $input );
	wpai119ms_check(
		! is_wp_error( $second ) && 'reported_success' === $second['outcome'] &&
		(int) $secondary_blog === $second['data']['blog_id'] && (int) $original_user === $second['data']['user_id'],
		'Super Admin native REST request escaped selected secondary site or current principal.'
	);
	wpai119ms_check( 2 === $calls, 'Secondary-site provider callback ran more than once.' );

	$site_change = static function ( $metadata ) use ( $main_blog ) {
		switch_to_blog( $main_blog );
		return $metadata;
	};
	add_filter( 'rest_endpoints_description', $site_change, 10, 1 );
	try {
		$drift = $ability->execute( $input );
		wpai119ms_check( is_wp_error( $drift ) && 2 === $calls, 'Provider preflight blog switch bypassed exact-site REST identity binding.' );
	} finally {
		remove_filter( 'rest_endpoints_description', $site_change, 10 );
		if ( (int) get_current_blog_id() === (int) $main_blog ) {
			restore_current_blog();
		}
	}
	wpai119ms_check( (int) $secondary_blog === (int) get_current_blog_id(), 'Preflight drift restoration did not restore secondary site.' );

	$no_grant_secondary = $full_grants;
	$no_grant_secondary[ Settings::GROUP_REST_INVOCATION ] = 0;
	update_option( Settings::OPTION_NAME, $no_grant_secondary, false );
	$revoked = $ability->execute( $input );
	wpai119ms_check( is_wp_error( $revoked ) && 2 === $calls, 'Secondary-site grant revocation did not deny next provider callback.' );

	restore_current_blog();
	$switched = false;
	$main_unaffected = $ability->execute( $input );
	wpai119ms_check(
		! is_wp_error( $main_unaffected ) && (int) $main_blog === $main_unaffected['data']['blog_id'] && 3 === $calls,
		'Secondary-site grant revocation unexpectedly mutated main-site grant or principal.'
	);
	echo "PASS: Issue #119 real generic REST multisite site/network caps, selected Target identity, drift and revocation.\n";
} finally {
	wp_set_current_user( $original_user );
	if ( $switched ) {
		restore_current_blog();
	}
	if ( false === $main_original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $main_original, false );
	}
	if ( $secondary_blog > 0 ) {
		switch_to_blog( $secondary_blog );
		if ( false === $secondary_original ) {
			delete_option( Settings::OPTION_NAME );
		} else {
			update_option( Settings::OPTION_NAME, $secondary_original, false );
		}
		restore_current_blog();
	}
	if ( $site_admin_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $site_admin_id );
	}
}
