<?php
/**
 * Real, disposable WordPress Core Plugin_Upgrader package input smoke.
 *
 * Requires an isolated site; never run against a production installation.
 * A newly generated synthetic plugin is installed without activation, then
 * explicitly removed. Only the native Core effect publishes plugin files.
 *
 * @package WP_AI_Bridge
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'ZipArchive' ) ) {
	throw new RuntimeException( 'WordPress and ZipArchive are required for this disposable test.' );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

$slug = 'wpai173-synthetic-package';
$entry = $slug . '/' . $slug . '.php';
$zip_path = '';
$installed = false;
try {
	if ( is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
		throw new RuntimeException( 'Synthetic target already exists. Do not overwrite it.' );
	}
	$zip_path = \WP_AI_Bridge\Support\Safe_Temporary_Staging::create( 'wpai173-synthetic-package.zip' );
	if ( is_wp_error( $zip_path ) ) {
		throw new RuntimeException( 'Private package scratch unavailable: ' . $zip_path->get_error_code() );
	}
	if ( 0 === strpos( realpath( $zip_path ) . '/', rtrim( realpath( ABSPATH ), '/' ) . '/' ) ||
		0600 !== ( fileperms( $zip_path ) & 0777 ) ) {
		throw new RuntimeException( 'Package scratch became publicly served.' );
	}
	$archive = new ZipArchive();
	if ( true !== $archive->open( $zip_path, ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Could not open synthetic ZIP under safe temporary scratch.' );
	}
	$source = "<?php\n/*\nPlugin Name: WPAI173 Synthetic Fixture\nVersion: 1.0.0\n*/\n";
	if ( ! $archive->addFromString( $entry, $source ) || ! $archive->close() ) {
		throw new RuntimeException( 'Could not create synthetic Core package.' );
	}
	$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$result = $upgrader->install( $zip_path );
	if ( is_wp_error( $result ) || ! $result ) {
		throw new RuntimeException( 'Core Plugin_Upgrader rejected the privately staged package.' );
	}
	$installed = is_file( WP_PLUGIN_DIR . '/' . $entry );
	if ( ! $installed || is_plugin_active( $entry ) ||
		$source !== file_get_contents( WP_PLUGIN_DIR . '/' . $entry ) ) {
		throw new RuntimeException( 'Native Core package effect did not preserve synthetic content or state.' );
	}
	echo 'PASS: #173 WordPress Core Plugin_Upgrader accepted private ZIP scratch and published exact synthetic plugin only at install.' . PHP_EOL;
} finally {
	if ( $installed || is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
		// This disposable test owns exactly this previously absent synthetic
		// plugin. Do not touch other plugin installations or running services.
		delete_plugins( array( $entry ) );
	}
	if ( is_string( $zip_path ) && is_file( $zip_path ) ) {
		wp_delete_file( $zip_path );
	}
}
