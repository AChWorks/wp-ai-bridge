<?php
/**
 * Disposable WordPress/official Adapter smoke for read-only Core update status.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Support\Settings;

function wpai130141_live_assert( $truth, $reason ) {
	if ( ! $truth ) {
		throw new RuntimeException( $reason );
	}
}

$settings       = new Settings();
$original       = get_option( Settings::OPTION_NAME, $settings->defaults() );
$original_cache = get_site_transient( 'update_core' );
$original_user  = get_current_user_id();
try {
	$ability = wp_get_ability( 'wp-ai-bridge/core-update-status' );
	wpai130141_live_assert( $ability instanceof WP_Ability, 'Core update diagnostics not registered by native Abilities API.' );
	$default = $settings->defaults();
	update_option( Settings::OPTION_NAME, $default, false );
	wpai130141_live_assert( true !== $ability->check_permissions( array() ), 'Default-off Site Configuration permission bypass.' );

	$default[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $default, false );
	wpai130141_live_assert( true === $ability->check_permissions( array() ), 'Native administrator permission or Site Configuration missing.' );

	$fixture = (object) array(
		'last_checked' => time() - 60,
		'version_checked' => get_bloginfo( 'version' ),
		'updates'      => array(
			(object) array( 'response' => 'upgrade', 'current' => '99.9', 'locale' => 'en_US' ),
		),
	);
	set_site_transient( 'update_core', $fixture );
	$result = $ability->execute( array() );
	wpai130141_live_assert( ! is_wp_error( $result ) && 'update_available' === $result['status'], 'Real WordPress native cache upgrade was not detected.' );
	wpai130141_live_assert( '99.9' === $result['offers'][0]['version'] && false === $result['upgrade_execution_via_bridge'], 'Real native offer projection/readonly boundary failed.' );

	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	wpai130141_live_assert( $adapter instanceof WP_Ability, 'Official MCP Adapter execution surface unavailable.' );
	$through_adapter = $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/core-update-status', 'parameters' => array() ) );
	wpai130141_live_assert( ! is_wp_error( $through_adapter ) && true === ( $through_adapter['success'] ?? false ) && 'update_available' === ( $through_adapter['data']['status'] ?? '' ), 'Official MCP Adapter did not carry bounded readonly Core status.' );

	set_site_transient( 'update_core', (object) array( 'last_checked' => time() - 2 * DAY_IN_SECONDS, 'version_checked' => get_bloginfo( 'version' ), 'updates' => array() ) );
	$stale = $ability->execute( array() );
	wpai130141_live_assert( ! is_wp_error( $stale ) && 'unknown_or_stale' === $stale['status'], 'Stale real Core cache fabricated an up-to-date assertion.' );

	$default[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $default, false );
	wpai130141_live_assert( true !== $ability->check_permissions( array() ), 'Grant revocation failed on a new native invocation.' );
	echo "PASS: #130/#141 real WordPress/official Adapter cached Core diagnostic smoke.\n";
} finally {
	wp_set_current_user( $original_user );
	update_option( Settings::OPTION_NAME, $original, false );
	if ( false === $original_cache ) {
		delete_site_transient( 'update_core' );
	} else {
		set_site_transient( 'update_core', $original_cache );
	}
}
