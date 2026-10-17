<?php
/**
 * Issue #186: native Core Site Health needs admin helpers under MCP/REST.
 *
 * Runs in a fresh, disposable bare WordPress bootstrap with MCP Adapter
 * installed, without WP-CLI's normal preloaded wp-admin helper functions.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai186_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$actor    = get_current_user_id();
$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$provider = wp_get_ability( 'wp-ai-bridge/site-health' );

wpai186_assert( $provider instanceof WP_Ability, 'Native Site Health provider not registered.' );
// The official Adapter registers its proxy only during MCP-specific bootstrap.
// This bare WordPress process verifies missing Core helpers; the existing
// #140 integration test verifies Adapter dispatch separately.

// The previous WP-CLI-based fixture preloaded every affected helper and
// could not prove the real, separately observed MCP/REST bootstrap defect.
wpai186_assert( ! ( defined( 'WP_CLI' ) && WP_CLI ), 'Site Health missing-helper acceptance cannot run through WP-CLI.' );
$test  = getenv( 'WPAI186_TEST' );
$tests = array(
	'plugin_version'            => array( 'get_plugin_updates' ),
	'php_version'               => array( 'wp_check_php_version' ),
	'theme_version'             => array( 'get_theme_updates' ),
	'wordpress_version'         => array( 'get_core_updates' ),
	'plugin_theme_auto_updates' => array( 'wp_is_auto_update_enabled_for_type', 'wp_is_auto_update_forced_for_item' ),
);
wpai186_assert( is_string( $test ) && isset( $tests[ $test ] ), 'An explicit fixed Core Site Health test is required.' );
foreach ( $tests[ $test ] as $required_function ) {
	wpai186_assert( ! function_exists( $required_function ), 'The Core helper was already loaded; missing-bootstrap regression is not discriminating: ' . $required_function );
}

try {
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	wpai186_assert( true !== $provider->check_permissions( array( 'action' => 'run', 'test' => $test ) ), 'Default-off permission was bypassed.' );

	$groups = $settings->defaults();
	$groups[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $groups, false );

	$result = $provider->execute( array( 'action' => 'run', 'test' => $test ) );
	wpai186_assert( ! is_wp_error( $result ), 'Native Core health test failed under MCP runtime: ' . $test . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
	wpai186_assert( 1 === ( $result['total'] ?? null ), 'Expected one Core health result for ' . $test );
	wpai186_assert( 'core_direct' === ( $result['items'][0]['source'] ?? null ), 'Core-only execution contract changed for ' . $test );
	wpai186_assert( in_array( $result['items'][0]['status'] ?? '', array( 'good', 'recommended', 'critical', 'outcome_unknown' ), true ), 'Invalid Core health status for ' . $test );
	foreach ( $tests[ $test ] as $required_function ) {
		wpai186_assert( function_exists( $required_function ), 'Required WordPress Core helper was not available after executing ' . $test . ': ' . $required_function );
	}
	printf( "PASS: #186 %s via fresh bare WordPress Core; all required helpers absent at start.\n", $test );

	$groups[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $groups, false );
	wpai186_assert( true !== $provider->check_permissions( array( 'action' => 'run', 'test' => $test ) ), 'Revoked Site Configuration permission allowed Core test.' );
	wp_set_current_user( 0 );
	wpai186_assert( true !== $provider->check_permissions( array( 'action' => 'run', 'test' => $test ) ), 'Anonymous user could execute Core health tests.' );
	echo "PASS: #186 Site Health bare WordPress bootstrap, Core ownership and permission regression; official Adapter exercised independently by #140.\n";
} finally {
	wp_set_current_user( $actor );
	update_option( Settings::OPTION_NAME, $original, false );
}
