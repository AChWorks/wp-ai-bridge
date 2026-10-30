<?php
/**
 * Declared dependency and abnormal-state notice regressions.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Plugin;
use WP_AI_Bridge\Support\Environment;

if ( ! defined( 'WP_AI_BRIDGE_FILE' ) ) {
	define( 'WP_AI_BRIDGE_FILE', ABSPATH . 'wp-content/plugins/wp-ai-bridge/wp-ai-bridge.php' );
}

class WP_Ability {}

class WP_Plugin_Dependencies {
	public static $declared = true;
	public static $unmet    = true;

	public static function has_dependencies( $file ) {
		return 'wp-ai-bridge/wp-ai-bridge.php' === $file && self::$declared;
	}

	public static function has_unmet_dependencies( $file ) {
		return 'wp-ai-bridge/wp-ai-bridge.php' === $file && self::$unmet;
	}
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function get_current_screen() {
	return $GLOBALS['wpai198_screen'];
}

$tests = 0;
function wpai198_assert( $condition, $message ) {
	global $tests;
	++$tests;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$header = file_get_contents( dirname( __DIR__ ) . '/wp-ai-bridge.php', false, null, 0, 8192 );
wpai198_assert( 1 === preg_match_all( '/^[ \t]*\*[ \t]*Requires Plugins:[ \t]*([^\r\n]*)$/m', $header, $matches ), 'There must be one native dependency header in Core\'s header window.' );
wpai198_assert( array( 'mcp-adapter' ) === $matches[1], 'Native dependency must use the official single WordPress.org slug.' );

$plugin      = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
$environment = new ReflectionProperty( Plugin::class, 'environment' );
$environment->setValue( $plugin, new Environment() );
$render = static function ( $screen ) use ( $plugin ) {
	$GLOBALS['wpai198_screen'] = $screen;
	ob_start();
	try {
		$plugin->render_dependency_notices();
		return ob_get_contents();
	} finally {
		ob_end_clean();
	}
};

wpai198_assert( '' === $render( (object) array( 'base' => 'plugins' ) ), 'Core Plugins requirements must not receive a duplicate Bridge warning.' );
wpai198_assert( false !== strpos( $render( (object) array( 'base' => 'dashboard' ) ), 'official WordPress MCP Adapter is not available' ), 'An already-active abnormal state must retain the Bridge warning elsewhere.' );
wpai198_assert( false !== strpos( $render( null ), 'official WordPress MCP Adapter is not available' ), 'An unavailable screen must retain the graceful fallback.' );
WP_Plugin_Dependencies::$unmet = false;
wpai198_assert( false !== strpos( $render( (object) array( 'base' => 'plugins' ) ), 'official WordPress MCP Adapter is not available' ), 'An active but broken Adapter must retain the runtime warning.' );
WP_Plugin_Dependencies::$unmet    = true;
WP_Plugin_Dependencies::$declared = false;
wpai198_assert( false !== strpos( $render( (object) array( 'base' => 'plugins' ) ), 'official WordPress MCP Adapter is not available' ), 'Missing Core declaration knowledge must not suppress the fallback.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
wpai198_assert( '' === $render( (object) array( 'base' => 'dashboard' ) ), 'Non-administrators must not receive the protected dependency notice.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
$GLOBALS['wp_version']                                = '6.8';
wpai198_assert( false !== strpos( $render( (object) array( 'base' => 'plugins' ) ), 'requires WordPress 6.9' ), 'The distinct unsupported-Core error must not be suppressed.' );

echo "PASS: Issue #198 native dependency metadata and fallback notices ({$tests} checks).\n";
