<?php
/**
 * Deterministic Core-inventory enumeration complexity regression for #214.
 *
 * This isolated fixture counts calls to WordPress inventory APIs. Actual
 * WordPress, Adapter and pagination behavior is covered by the integration smoke.
 *
 * @package WP_AI_Bridge
 */

error_reporting( E_ALL );

$root = sys_get_temp_dir() . '/wpai214-inventory-' . bin2hex( random_bytes( 8 ) );
define( 'ABSPATH', $root . '/wp/' );
define( 'WP_PLUGIN_DIR', $root . '/plugins' );

$GLOBALS['wpai214_plugin_files']  = array();
$GLOBALS['wpai214_plugin_calls']  = 0;
$GLOBALS['wpai214_theme_calls']   = array( 'php' => 0, 'css' => 0 );
$GLOBALS['wpai214_theme_files']   = array( 'php' => array(), 'css' => array() );
$GLOBALS['wpai214_theme_root']    = $root . '/themes';
$GLOBALS['wpai214_created_files'] = array();
$GLOBALS['wpai214_created_dirs']  = array();

function wpai214_inventory_check( $test, $message ) {
	if ( ! $test ) {
		throw new RuntimeException( $message );
	}
}

class WP_Error {
	public function __construct( $code = '', $message = '' ) {}
}
function __( $text, $domain = null ) { return $text; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function untrailingslashit( $path ) { return rtrim( $path, '/\\' ); }
function get_plugins() { return array( 'bulk/main.php' => array() ); }
function get_plugin_files( $plugin ) {
	++$GLOBALS['wpai214_plugin_calls'];
	return $GLOBALS['wpai214_plugin_files'];
}
function wp_get_plugin_file_editable_extensions( $plugin ) { return array( 'php' ); }
function is_plugin_active( $plugin ) { return false; }
function is_multisite() { return false; }
function is_plugin_active_for_network( $plugin ) { return false; }
function wp_get_themes() { return array( 'bulk-theme' => new WPAI214_Inventory_Theme() ); }
function wp_get_theme_file_editable_extensions( $theme ) { return array( 'php', 'css' ); }
function get_theme_root( $stylesheet = '' ) { return $GLOBALS['wpai214_theme_root']; }
function get_stylesheet() { return 'other-theme'; }
function get_template() { return 'other-theme'; }
class WP_Filesystem_Direct {
	public function __construct( $args = null ) {}
	public function size( $path ) { return filesize( $path ); }
}
class WPAI214_Inventory_Theme {
	public function get_stylesheet_directory() { return $GLOBALS['wpai214_theme_root'] . '/bulk-theme'; }
	public function get_files( $extension, $depth = 0 ) {
		++$GLOBALS['wpai214_theme_calls'][ $extension ];
		return $GLOBALS['wpai214_theme_files'][ $extension ];
	}
}

function wpai214_inventory_dir( $path ) {
	wpai214_inventory_check( mkdir( $path, 0700 ), 'Cannot create isolated inventory fixture directory.' );
	$GLOBALS['wpai214_created_dirs'][] = $path;
}
function wpai214_inventory_file( $path ) {
	$GLOBALS['wpai214_created_files'][] = $path;
	wpai214_inventory_check( false !== file_put_contents( $path, "<?php\n// Synthetic file.\n" ), 'Cannot create isolated inventory fixture file.' );
}

try {
	wpai214_inventory_dir( $root );
	wpai214_inventory_dir( ABSPATH );
	wpai214_inventory_dir( ABSPATH . 'wp-admin' );
	wpai214_inventory_dir( ABSPATH . 'wp-admin/includes' );
	foreach ( array( 'plugin.php', 'theme.php', 'file.php', 'class-wp-filesystem-base.php', 'class-wp-filesystem-direct.php' ) as $name ) {
		wpai214_inventory_file( ABSPATH . 'wp-admin/includes/' . $name );
	}
	wpai214_inventory_dir( WP_PLUGIN_DIR );
	wpai214_inventory_dir( WP_PLUGIN_DIR . '/bulk' );
	wpai214_inventory_dir( $GLOBALS['wpai214_theme_root'] );
	wpai214_inventory_dir( $GLOBALS['wpai214_theme_root'] . '/bulk-theme' );
	wpai214_inventory_file( WP_PLUGIN_DIR . '/bulk/main.php' );
	$GLOBALS['wpai214_plugin_files'][] = 'bulk/main.php';

	for ( $i = 0; $i < 200; ++$i ) {
		$name = sprintf( 'bulk/file-%04d.php', $i );
		wpai214_inventory_file( WP_PLUGIN_DIR . '/' . $name );
		$GLOBALS['wpai214_plugin_files'][] = $name;
	}
	$outside = $root . '/outside.php';
	wpai214_inventory_file( $outside );
	$link = WP_PLUGIN_DIR . '/bulk/escape.php';
	wpai214_inventory_check( symlink( $outside, $link ), 'Cannot create confined symlink test.' );
	$GLOBALS['wpai214_created_files'][] = $link;
	$GLOBALS['wpai214_plugin_files'][] = 'bulk/escape.php';

	for ( $i = 0; $i < 80; ++$i ) {
		$name = sprintf( 'file-%04d.php', $i );
		$path = $GLOBALS['wpai214_theme_root'] . '/bulk-theme/' . $name;
		wpai214_inventory_file( $path );
		$GLOBALS['wpai214_theme_files']['php'][ $name ] = $path;
	}
	$style = $GLOBALS['wpai214_theme_root'] . '/bulk-theme/style.css';
	wpai214_inventory_file( $style );
	$GLOBALS['wpai214_theme_files']['css']['style.css'] = $style;

	require dirname( __DIR__ ) . '/src/Abilities/class-source-editing-abilities.php';
	$ref      = new ReflectionClass( WP_AI_Bridge\Abilities\Source_Editing_Abilities::class );
	$provider = $ref->newInstanceWithoutConstructor();
	$method   = $ref->getMethod( 'discover_targets' );

	$plugin_items = $method->invoke( $provider, 'plugin', 'bulk/main.php' );
	wpai214_inventory_check( 201 === count( $plugin_items ), 'Core-owned plugin file count or symlink confinement changed.' );
	wpai214_inventory_check( 1 === $GLOBALS['wpai214_plugin_calls'], 'Plugin file inventory rescanned once per file instead of once per extension.' );
	wpai214_inventory_check( 'file-0000.php' === $plugin_items[0]['file'], 'Plugin source inventory sorting changed.' );
	wpai214_inventory_check( 'main.php' === $plugin_items[200]['file'], 'Plugin source inventory final entry changed.' );

	$theme_items = $method->invoke( $provider, 'theme', 'bulk-theme' );
	wpai214_inventory_check( 81 === count( $theme_items ), 'Core-owned theme file inventory changed.' );
	wpai214_inventory_check(
		1 === $GLOBALS['wpai214_theme_calls']['php'] && 1 === $GLOBALS['wpai214_theme_calls']['css'],
		'Theme file inventory rescanned for each individual file.'
	);
	printf( "PASS: #214 Core inventory scanned once per extension; 201 plugin and 81 theme source files, symlink rejected.\n" );
} finally {
	foreach ( array_reverse( $GLOBALS['wpai214_created_files'] ) as $path ) {
		@unlink( $path );
	}
	foreach ( array_reverse( $GLOBALS['wpai214_created_dirs'] ) as $path ) {
		@rmdir( $path );
	}
}
