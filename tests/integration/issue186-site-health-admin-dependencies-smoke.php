<?php
/**
 * Issue #186: native Core Site Health needs admin helpers under MCP/REST.
 *
 * Runs in a fresh, disposable WordPress + official MCP Adapter process so the
 * bridge cannot accidentally rely on wp-admin's normal page bootstrap.
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
$adapter  = wp_get_ability( 'mcp-adapter/execute-ability' );

wpai186_assert( $provider instanceof WP_Ability, 'Native Site Health provider not registered.' );
wpai186_assert( $adapter instanceof WP_Ability, 'Official MCP Adapter not registered.' );

try {
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	wpai186_assert( true !== $provider->check_permissions( array( 'action' => 'run', 'test' => 'plugin_version' ) ), 'Default-off permission was bypassed.' );

	$groups = $settings->defaults();
	$groups[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $groups, false );

	$tests = array(
		'plugin_version'            => 'get_plugin_updates',
		'php_version'               => 'wp_check_php_version',
		'theme_version'             => 'get_theme_updates',
		'wordpress_version'         => 'get_core_updates',
		'plugin_theme_auto_updates' => 'wp_is_auto_update_enabled_for_type',
	);
	foreach ( $tests as $test => $required_function ) {
		$before = function_exists( $required_function );
		$result = $provider->execute( array( 'action' => 'run', 'test' => $test ) );
		wpai186_assert( ! is_wp_error( $result ), 'Native Core health test failed under MCP runtime: ' . $test . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
		wpai186_assert( 1 === ( $result['total'] ?? null ), 'Expected one Core health result for ' . $test );
		wpai186_assert( 'core_direct' === ( $result['items'][0]['source'] ?? null ), 'Core-only execution contract changed for ' . $test );
		wpai186_assert( in_array( $result['items'][0]['status'] ?? '', array( 'good', 'recommended', 'critical', 'outcome_unknown' ), true ), 'Invalid Core health status for ' . $test );
		wpai186_assert( function_exists( $required_function ), 'Required WordPress Core helper was not available after executing ' . $test );
		$through_adapter = $adapter->execute(
			array(
				'ability_name' => 'wp-ai-bridge/site-health',
				'parameters'   => array( 'action' => 'run', 'test' => $test ),
			)
		);
		wpai186_assert( ! is_wp_error( $through_adapter ) && true === ( $through_adapter['success'] ?? false ), 'Official Adapter rejected native Site Health test: ' . $test );
		printf( "PASS: #186 %s via native Core and MCP Adapter; Core helper preloaded=%s.\n", $test, $before ? 'yes' : 'no' );
	}

	$groups[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $groups, false );
	wpai186_assert( true !== $provider->check_permissions( array( 'action' => 'run', 'test' => 'php_version' ) ), 'Revoked Site Configuration permission allowed Core test.' );
	wp_set_current_user( 0 );
	wpai186_assert( true !== $provider->check_permissions( array( 'action' => 'run', 'test' => 'plugin_version' ) ), 'Anonymous user could execute Core health tests.' );
	echo "PASS: #186 Site Health MCP/REST admin helper bootstrap, Core ownership and permission regression.\n";
} finally {
	wp_set_current_user( $actor );
	update_option( Settings::OPTION_NAME, $original, false );
}
