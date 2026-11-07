<?php
/**
 * Real WordPress large-extension source inventory and confinement regression.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai214_inventory_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$plugin_slug       = 'wpai214-inventory-fixture';
$plugin            = $plugin_slug . '/fixture.php';
$plugin_dir        = WP_PLUGIN_DIR . '/' . $plugin_slug;
$theme_slug        = 'wpai214-inventory-theme';
$theme_dir         = get_theme_root() . '/' . $theme_slug;
$outside_file      = WP_CONTENT_DIR . '/wpai214-inventory-outside.php';
$symlink_file      = $plugin_dir . '/outside.php';
$original_settings = get_option( Settings::OPTION_NAME, false );
$plugin_files      = array();
$plugin_subdirs    = array();
$theme_files       = array();
$plugin_created    = false;
$theme_created     = false;
$outside_created   = false;

wpai214_inventory_assert(
	! file_exists( $plugin_dir ) && ! is_link( $plugin_dir ) &&
	! file_exists( $theme_dir ) && ! is_link( $theme_dir ) &&
	! file_exists( $outside_file ) && ! is_link( $outside_file ),
	'Existing source inventory fixture path must never be overwritten.'
);

try {
	wpai214_inventory_assert( wp_mkdir_p( $plugin_dir ), 'Cannot create task-owned plugin fixture.' );
	$plugin_created = true;
	$plugin_main    = $plugin_dir . '/fixture.php';
	wpai214_inventory_assert(
		false !== file_put_contents( $plugin_main, "<?php\n/* Plugin Name: WP AI Bridge Inventory Fixture */\n" ),
		'Cannot create plugin inventory header.'
	);
	$plugin_files[] = $plugin_main;

	// The historical implementation rescanned this entire Core inventory
	// once for each file, even when per_page=1.
	for ( $i = 0; $i < 420; ++$i ) {
		$relative_dir = sprintf( 'part-%02d', intdiv( $i, 35 ) );
		$directory    = $plugin_dir . '/' . $relative_dir;
		if ( ! in_array( $directory, $plugin_subdirs, true ) ) {
			wpai214_inventory_assert( wp_mkdir_p( $directory ), 'Cannot create plugin inventory subdirectory.' );
			$plugin_subdirs[] = $directory;
		}
		$path = $directory . '/' . sprintf( 'file-%04d.php', $i );
		wpai214_inventory_assert( false !== file_put_contents( $path, "<?php\n// Synthetic editable source.\n" ), 'Cannot write plugin inventory fixture file.' );
		$plugin_files[] = $path;
	}
	wpai214_inventory_assert( false !== file_put_contents( $outside_file, "<?php\n// Out-of-root fixture.\n" ), 'Cannot create outside marker.' );
	$outside_created = true;
	wpai214_inventory_assert( symlink( $outside_file, $symlink_file ), 'Cannot create symlink escape fixture.' );

	wpai214_inventory_assert( wp_mkdir_p( $theme_dir ), 'Cannot create task-owned theme fixture.' );
	$theme_created = true;
	$theme_style   = $theme_dir . '/style.css';
	$theme_main    = $theme_dir . '/functions.php';
	$theme_index   = $theme_dir . '/index.php';
	wpai214_inventory_assert(
		false !== file_put_contents( $theme_style, "/*\nTheme Name: WP AI Bridge Inventory Test Theme\nVersion: 1.0.0\n*/\n" ) &&
		false !== file_put_contents( $theme_main, "<?php\n// Theme fixture.\n" ) &&
		false !== file_put_contents( $theme_index, "<?php\n// Valid classic theme entrypoint.\n" ),
		'Cannot create theme inventory headers.'
	);
	$theme_files[] = $theme_style;
	$theme_files[] = $theme_main;
	$theme_files[] = $theme_index;
	$theme_extra   = $theme_dir . '/includes';
	wpai214_inventory_assert( wp_mkdir_p( $theme_extra ), 'Cannot create theme inventory subdirectory.' );
	for ( $i = 0; $i < 80; ++$i ) {
		$path = $theme_extra . '/' . sprintf( 'partial-%04d.php', $i );
		wpai214_inventory_assert( false !== file_put_contents( $path, "<?php\n// Synthetic theme source.\n" ), 'Cannot write theme inventory fixture.' );
		$theme_files[] = $path;
	}

	wp_clean_plugins_cache( true );
	wp_clean_themes_cache( true );
	$settings                                    = new Settings();
	$grants                                      = $settings->defaults();
	$grants[ Settings::GROUP_CODE_EXTENSIONS ]   = 1;
	$grants[ Settings::GROUP_SOURCE_EDITING ]    = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$read = wp_get_ability( 'wp-ai-bridge/source-files-read' );
	wpai214_inventory_assert( $read instanceof WP_Ability, 'Native source inventory Ability unavailable.' );

	$start  = microtime( true );
	$first  = $read->execute( array( 'action' => 'list', 'kind' => 'plugin', 'extension' => $plugin, 'per_page' => 1 ) );
	$first_ms = ( microtime( true ) - $start ) * 1000;
	wpai214_inventory_assert(
		! is_wp_error( $first ) && 421 === $first['total'] && 421 === $first['total_pages'] &&
		1 === count( $first['items'] ) && 'fixture.php' === $first['items'][0]['file'],
		'Large installed plugin page 1 had incorrect count, order or pagination.'
	);
	$start = microtime( true );
	$last  = $read->execute( array( 'action' => 'list', 'kind' => 'plugin', 'extension' => $plugin, 'page' => 421, 'per_page' => 1 ) );
	$last_ms = ( microtime( true ) - $start ) * 1000;
	wpai214_inventory_assert(
		! is_wp_error( $last ) && 421 === $last['total'] && 1 === count( $last['items']) &&
		'part-11/file-0419.php' === $last['items'][0]['file'],
		'Large plugin final page was incomplete or unstable.'
	);
	$missing = $read->execute( array( 'action' => 'list', 'kind' => 'plugin', 'extension' => 'not-installed/not-installed.php', 'per_page' => 1 ) );
	wpai214_inventory_assert( ! is_wp_error( $missing ) && 0 === $missing['total'] && array() === $missing['items'], 'Unknown plugin identity incorrectly returned inventory.' );
	$escaped = $read->execute( array( 'action' => 'read', 'kind' => 'plugin', 'extension' => $plugin, 'file' => 'outside.php' ) );
	wpai214_inventory_assert( is_wp_error( $escaped ), 'Symlink escape became readable after inventory optimization.' );

	$start = microtime( true );
	$theme = $read->execute( array( 'action' => 'list', 'kind' => 'theme', 'extension' => $theme_slug, 'per_page' => 1 ) );
	$theme_ms = ( microtime( true ) - $start ) * 1000;
	wpai214_inventory_assert(
		! is_wp_error( $theme ) && $theme['total'] >= 80 &&
		1 === count( $theme['items'] ) && $theme_slug === $theme['items'][0]['extension'],
		'Large installed theme source inventory failed: ' .
		( is_wp_error( $theme ) ? $theme->get_error_code() : wp_json_encode( array(
			'total'           => $theme['total'],
			'first_extension' => $theme['items'][0]['extension'] ?? null,
			'registered'      => array_key_exists( $theme_slug, wp_get_themes() ),
		) ) )
	);
	$denied_settings = $grants;
	$denied_settings[ Settings::GROUP_SOURCE_EDITING ] = 0;
	update_option( Settings::OPTION_NAME, $denied_settings, false );
	$denied = $read->execute( array( 'action' => 'list', 'kind' => 'plugin', 'extension' => $plugin, 'per_page' => 1 ) );
	wpai214_inventory_assert( is_wp_error( $denied ), 'Disabled Source Editing consent exposed inventory.' );

	printf(
		"PASS: #214 actual WordPress source inventory; plugin_files=421 first_ms=%.1f last_ms=%.1f theme_files=%d theme_ms=%.1f peak_memory=%d\n",
		$first_ms,
		$last_ms,
		$theme['total'],
		$theme_ms,
		memory_get_peak_usage( true )
	);
} finally {
	if ( false === $original_settings ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original_settings, false );
	}
	if ( $plugin_created ) {
		@unlink( $symlink_file );
		foreach ( $plugin_files as $file ) {
			@unlink( $file );
		}
		foreach ( array_reverse( $plugin_subdirs ) as $directory ) {
			@rmdir( $directory );
		}
		@rmdir( $plugin_dir );
	}
	if ( $theme_created ) {
		foreach ( $theme_files as $file ) {
			@unlink( $file );
		}
		@rmdir( $theme_dir . '/includes' );
		@rmdir( $theme_dir );
	}
	if ( $outside_created ) {
		@unlink( $outside_file );
	}
	wp_clean_plugins_cache( true );
	wp_clean_themes_cache( true );
}
