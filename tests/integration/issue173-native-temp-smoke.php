<?php
/**
 * Disposable real-WordPress temporary input and native Media acceptance.
 *
 * Test-only host sets WP_TEMP_DIR to the web-served wp-content root. Execute:
 * wp eval-file tests/integration/issue173-native-temp-smoke.php --user=1 --allow-root
 *
 * @package WP_AI_Bridge
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_TEMP_DIR' ) ) {
	throw new RuntimeException( 'This fixture needs WordPress and a controlled WP_TEMP_DIR.' );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$webroot = realpath( ABSPATH );
$unsafe  = realpath( WP_TEMP_DIR );
if ( ! is_string( $webroot ) || ! is_string( $unsafe ) ||
	0 !== strpos( $unsafe . '/', rtrim( $webroot, '/' ) . '/' ) ) {
	throw new RuntimeException( 'The fixture must intentionally serve its WordPress temporary root.' );
}
if ( ! current_user_can( 'upload_files' ) ) {
	throw new RuntimeException( 'Fixture user lacks native Media permission.' );
}

$public     = '';
$private    = '';
$attachment = 0;
try {
	// Control: native WP_TEMP_DIR itself IS served by this isolated Apache host.
	$public = wp_tempnam( 'wpai173-synthetic-public-marker.txt' );
	if ( ! is_string( $public ) || ! is_file( $public ) ||
		str_replace( '\\', '/', dirname( realpath( $public ) ) ) !== str_replace( '\\', '/', $unsafe ) ) {
		throw new RuntimeException( 'Could not reproduce a public WordPress temporary file.' );
	}
	$marker = 'WPAI173_NON_SECRET_TEST_ONLY';
	file_put_contents( $public, $marker );
	$response = wp_remote_get( 'http://wordpress/wp-content/' . basename( $public ), array( 'timeout' => 10 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ||
		$marker !== wp_remote_retrieve_body( $response ) ) {
		throw new RuntimeException( 'Fixture static HTTP 200 control did not reproduce.' );
	}
	wp_delete_file( $public );
	$public = '';

	// Candidate: private scratch despite web-served WP_TEMP_DIR, with no user
	// path; this remains private until explicitly moved by native WordPress.
	$private = \WP_AI_Bridge\Support\Safe_Temporary_Staging::create( 'wpai173-native-media.png' );
	if ( is_wp_error( $private ) ) {
		throw new RuntimeException( 'Safe scratch could not be created: ' . $private->get_error_code() );
	}
	$canonical = realpath( $private );
	if ( ! is_string( $canonical ) ||
		0 === strpos( $canonical . '/', rtrim( $webroot, '/' ) . '/' ) ||
		0600 !== ( fileperms( $canonical ) & 0777 ) || 0 !== filesize( $canonical ) ) {
		throw new RuntimeException( 'Scratch path, permission or pre-write state was unsafe.' );
	}

	// Known small synthetic one-pixel PNG. Only the accepted native Media
	// destination is allowed to become public.
	$binary = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jphkAAAAASUVORK5CYII=', true );
	if ( ! is_string( $binary ) || strlen( $binary ) < 50 ||
		strlen( $binary ) !== file_put_contents( $canonical, $binary ) ) {
		throw new RuntimeException( 'Could not write one synthetic image into private scratch.' );
	}
	$attachment = media_handle_sideload(
		array(
			'name'     => 'wpai173-native-media.png',
			'tmp_name' => $canonical,
			'error'    => 0,
			'size'     => strlen( $binary ),
		),
		0
	);
	if ( is_wp_error( $attachment ) || ! is_int( $attachment ) || $attachment < 1 ) {
		$error = is_wp_error( $attachment ) ? $attachment->get_error_code() : 'invalid_id';
		throw new RuntimeException( 'Native Media sideload failed: ' . $error );
	}
	$destination = get_attached_file( $attachment );
	if ( ! is_string( $destination ) || ! is_file( $destination ) ||
		! hash_equals( hash( 'sha256', $binary ), hash_file( 'sha256', $destination ) ) ) {
		throw new RuntimeException( 'Core Media destination lost source bytes.' );
	}
	echo 'PASS: #173 WordPress ' . get_bloginfo( 'version' ) . ' live Apache public-temp control, private staging and native Media SHA-256 parity.' . PHP_EOL;
} finally {
	if ( is_int( $attachment ) && $attachment > 0 ) {
		wp_delete_attachment( $attachment, true );
	}
	if ( is_string( $private ) && is_file( $private ) ) {
		wp_delete_file( $private );
	}
	if ( is_string( $public ) && is_file( $public ) ) {
		wp_delete_file( $public );
	}
}
