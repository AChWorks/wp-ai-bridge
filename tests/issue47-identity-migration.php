<?php
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Registrar;
use WP_Native_Builder_Bridge\Admin\Settings_Page;
use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Support\Settings;

$failures = 0;
$tests    = 0;

function wpnb_issue47_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

wpnb_issue47_assert( 'wp-ai-bridge-direct' === OAuth_Server::MCP_SERVER_ID, 'Canonical MCP server ID uses WP AI Bridge identity.' );
wpnb_issue47_assert( 'wp-native-builder-direct' === OAuth_Server::LEGACY_MCP_SERVER_ID, 'Legacy MCP server ID remains available.' );
wpnb_issue47_assert( 'wp-ai-bridge/v1' === OAuth_Server::MCP_ROUTE_NAMESPACE, 'Canonical MCP namespace uses WP AI Bridge identity.' );
wpnb_issue47_assert( 'wp-native-builder/v1' === OAuth_Server::LEGACY_MCP_ROUTE_NAMESPACE, 'Legacy MCP namespace remains available.' );
wpnb_issue47_assert( '/wp-ai-bridge/oauth/authorize' === OAuth_Server::AUTHORIZATION_PATH, 'Canonical authorization path uses WP AI Bridge identity.' );
wpnb_issue47_assert( '/wp-native-builder/oauth/authorize' === OAuth_Server::LEGACY_AUTHORIZATION_PATH, 'Legacy authorization path remains available.' );

wpnb_issue47_assert( 'wp-ai-bridge' === Settings_Page::PAGE_SLUG, 'Canonical admin slug uses WP AI Bridge identity.' );
wpnb_issue47_assert( 'wp-native-builder' === Settings_Page::LEGACY_PAGE_SLUG, 'Legacy admin dashboard slug remains available.' );
wpnb_issue47_assert( 'wp-ai-bridge-settings' === Settings_Page::SETTINGS_SLUG, 'Canonical settings slug uses WP AI Bridge identity.' );
wpnb_issue47_assert( 'wp-native-builder-settings' === Settings_Page::LEGACY_SETTINGS_SLUG, 'Legacy settings slug remains available.' );

// These are durable compatibility identifiers, not current product branding.
wpnb_issue47_assert( 'wp-native-builder' === Registrar::CATEGORY, 'Ability category slug remains stable across public rename.' );
wpnb_issue47_assert( 'wp_native_builder_bridge_settings' === Settings::OPTION_NAME, 'Settings storage key remains stable across public rename.' );

$plugin = file_get_contents( dirname( __DIR__ ) . '/wp-native-builder-bridge.php' );
wpnb_issue47_assert( false !== strpos( $plugin, 'Plugin Name: WP AI Bridge' ), 'Plugin header exposes WP AI Bridge.' );
wpnb_issue47_assert( false !== strpos( $plugin, 'Plugin URI: https://github.com/ach1992/wp-ai-bridge' ), 'Plugin header points at canonical repository identity.' );
wpnb_issue47_assert( false !== strpos( $plugin, 'Text Domain: wp-native-builder-bridge' ), 'Text domain remains stable for translation compatibility.' );
wpnb_issue47_assert( false !== strpos( $plugin, "define( 'WP_NATIVE_BUILDER_BRIDGE_VERSION'" ), 'Legacy PHP constants remain stable for runtime compatibility.' );

if ( $failures > 0 ) {
	fwrite( STDERR, sprintf( "%d of %d Issue #47 identity migration assertions failed.\n", $failures, $tests ) );
	exit( 1 );
}

echo sprintf( "PASS: %d Issue #47 identity migration contract assertions.\n", $tests );
