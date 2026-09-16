<?php
/**
 * Plugin Name: WP AI Bridge
 * Plugin URI: https://github.com/ach1992/wp-ai-bridge
 * Description: Connects ChatGPT to WordPress through OAuth, MCP, and permission-checked WordPress Abilities.
 * Version: 0.3.0
 * Requires at least: 6.9
 * Author: ACh
 * Author URI: https://ach.li
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-native-builder-bridge
 * Domain Path: /languages
 *
 * @package WP_Native_Builder_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_NATIVE_BUILDER_BRIDGE_VERSION', '0.3.0' );
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

WP_Native_Builder_Bridge\Plugin::instance()->boot();
