<?php
/**
 * Plugin Name: WP AI Bridge
 * Plugin URI: https://github.com/ach1992/wp-ai-bridge
 * Description: Connects ChatGPT to WordPress through OAuth, MCP, and permission-checked WordPress Abilities.
 * Version: 0.4.1
 * Requires at least: 6.9
 * Author: ACh
 * Author URI: https://ach.li
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-bridge
 * Domain Path: /languages
 *
 * @package WP_AI_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_AI_BRIDGE_VERSION', '0.4.1' );
define( 'WP_AI_BRIDGE_FILE', __FILE__ );
define( 'WP_AI_BRIDGE_DIR', __DIR__ );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WP_AI_Bridge\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative   = substr( $class_name, strlen( $prefix ) );
		$parts      = explode( '\\', $relative );
		$class_file = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
		$directory  = $parts ? implode( '/', $parts ) . '/' : '';
		$path       = WP_AI_BRIDGE_DIR . '/src/' . $directory . $class_file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		WP_AI_Bridge\Plugin::instance()->boot();
	},
	1
);
