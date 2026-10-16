<?php
/**
 * Disposable WordPress private-temp denial under restricted PHP open_basedir.
 *
 * Requires a fixture site with deliberately web-served WP_TEMP_DIR. This
 * restricts only the current ephemeral CLI request; it cannot change the
 * installed WordPress/host configuration or affect another request.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Safe_Temporary_Staging;
use WP_AI_Bridge\Support\Private_Artifact_Writer;

if ( ! defined( 'WP_TEMP_DIR' ) ||
	realpath( WP_TEMP_DIR ) !== realpath( WP_CONTENT_DIR ) ||
	! is_string( realpath( ABSPATH ) ) ) {
	throw new RuntimeException( 'A served WP_TEMP_DIR fixture is required.' );
}
$outside = realpath( sys_get_temp_dir() );
if ( ! is_string( $outside ) ||
	0 === strpos( $outside . '/', rtrim( realpath( ABSPATH ), '/' ) . '/' ) ) {
	throw new RuntimeException( 'Isolated off-webroot system temp required for denial simulation.' );
}
$previous = ini_get( 'open_basedir' );
if ( false === ini_set( 'open_basedir', realpath( ABSPATH ) ) ) {
	throw new RuntimeException( 'PHP fixture cannot tighten open_basedir.' );
}
$warning_count = 0;
set_error_handler(
	static function ( $severity ) use ( &$warning_count ) {
		if ( E_WARNING === $severity ) {
			++$warning_count;
		}
		return true;
	}
);
try {
	// In this process the only allowable PHP file locations are under the
	// served site root, which must never become an accepted private fallback.
	$directory = Safe_Temporary_Staging::directory();
	$input = Safe_Temporary_Staging::create( 'wpai134-test-private.bin' );
	$outbound = Private_Artifact_Writer::begin( 128 );
	if ( ! is_wp_error( $directory ) || 'temporary_storage_unsafe' !== $directory->get_error_code() ||
		! is_wp_error( $input ) || 'temporary_storage_unsafe' !== $input->get_error_code() ||
		! is_wp_error( $outbound ) || 'artifact_storage_unsafe' !== $outbound->get_error_code() ) {
		throw new RuntimeException( 'A restricted WordPress host accepted confidential staging without a private path.' );
	}
	echo 'PASS: #134 restricted open_basedir and served WP_TEMP_DIR reject input and outbound confidential bytes before write.' . PHP_EOL;
} finally {
	restore_error_handler();
	// Tightened open_basedir cannot be loosened within a PHP request. The
	// disposable CLI process ends immediately after this fixture.
}
