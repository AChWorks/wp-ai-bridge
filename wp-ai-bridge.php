<?php
/**
 * Plugin Name: WP AI Bridge
 * Plugin URI: https://github.com/ach1992/wp-ai-bridge
 * Description: Connects ChatGPT to WordPress through OAuth, MCP, and permission-checked WordPress Abilities.
 * Version: 0.4.0
 * Requires at least: 6.9
 * Author: ACh
 * Author URI: https://ach.li
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-bridge
 * Domain Path: /languages
 *
 * @package WP_Native_Builder_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wp_ai_bridge_legacy_plugin   = 'wp-native-builder-bridge/wp-native-builder-bridge.php';
$wp_ai_bridge_active_plugins  = (array) get_option( 'active_plugins', array() );
$wp_ai_bridge_network_plugins = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
if ( in_array( $wp_ai_bridge_legacy_plugin, $wp_ai_bridge_active_plugins, true ) || isset( $wp_ai_bridge_network_plugins[ $wp_ai_bridge_legacy_plugin ] ) ) {
	$wp_ai_bridge_legacy_active_message = 'Deactivate WP Native Builder Bridge before activating WP AI Bridge. Do not uninstall the legacy plugin until WP AI Bridge finishes and verifies the one-time data migration.';
	if ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING ) {
		throw new RuntimeException( $wp_ai_bridge_legacy_active_message );
	}
	add_action(
		'admin_notices',
		static function () use ( $wp_ai_bridge_legacy_active_message ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $wp_ai_bridge_legacy_active_message ) );
			}
		}
	);
	return;
}

define( 'WP_NATIVE_BUILDER_BRIDGE_VERSION', '0.4.0' );
define( 'WP_NATIVE_BUILDER_BRIDGE_FILE', __FILE__ );
define( 'WP_NATIVE_BUILDER_BRIDGE_DIR', __DIR__ );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WP_Native_Builder_Bridge\\';

		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative   = substr( $class_name, strlen( $prefix ) );
		$parts      = explode( '\\', $relative );
		$class_file = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
		$directory  = $parts ? implode( '/', $parts ) . '/' : '';
		$path       = WP_NATIVE_BUILDER_BRIDGE_DIR . '/src/' . $directory . $class_file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

$wp_ai_bridge_migration = WP_Native_Builder_Bridge\Support\Identity_Migration::run();
if ( is_wp_error( $wp_ai_bridge_migration ) ) {
	$GLOBALS['wp_ai_bridge_identity_migration_error'] = $wp_ai_bridge_migration;
	add_action(
		'admin_notices',
		static function () {
			$error = $GLOBALS['wp_ai_bridge_identity_migration_error'] ?? null;
			if ( $error instanceof WP_Error && current_user_can( 'manage_options' ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html( 'WP AI Bridge identity migration is blocked: ' . $error->get_error_message() )
				);
			}
		}
	);
	if ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING ) {
		throw new RuntimeException( 'WP AI Bridge identity migration failed: ' . $wp_ai_bridge_migration->get_error_code() );
	}
	return;
}

WP_Native_Builder_Bridge\Plugin::instance()->boot();
