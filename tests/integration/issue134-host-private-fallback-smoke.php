<?php
/**
 * Disposable native WordPress #134 private-directory fallback probe.
 * Requires WP_TEMP_DIR to be a static-served content directory.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Safe_Temporary_Staging;
use WP_AI_Bridge\Support\Private_Artifact_Writer;

function wpai134_private_fallback_assert( $valid, $reason ) {
	if ( ! $valid ) {
		throw new RuntimeException( $reason );
	}
}

wpai134_private_fallback_assert(
	defined( 'WP_TEMP_DIR' ) && realpath( WP_TEMP_DIR ) === realpath( WP_CONTENT_DIR ),
	'Fixture requires WordPress default temp root inside served content.'
);
$directory = Safe_Temporary_Staging::directory();
wpai134_private_fallback_assert(
	is_string( $directory ) && $directory === realpath( sys_get_temp_dir() ) &&
	0 !== strpos( $directory . '/', rtrim( realpath( ABSPATH ), '/' ) . '/' ),
	'Internal private-directory resolver did not choose safe OS temporary storage.'
);
$private = '';
try {
	$writer = Private_Artifact_Writer::begin( 512 );
	wpai134_private_fallback_assert( ! is_wp_error( $writer ), 'Native private WXR artifact writer still unavailable.' );
	$bytes = "WPAI134 synthetic private test\x00\xff\n" . "سلام";
	wpai134_private_fallback_assert( true === $writer->append( $bytes ), 'Native private writer failed.' );
	$artifact = $writer->finish();
	wpai134_private_fallback_assert( is_array( $artifact ), 'Native private artifact finish failed.' );
	$private = $artifact['path'];
	wpai134_private_fallback_assert(
		is_file( $private ) && 0600 === ( fileperms( $private ) & 0777 ) &&
		dirname( realpath( $private ) ) === $directory &&
		$artifact['bytes'] === strlen( $bytes ) &&
		hash_equals( $artifact['sha256'], hash_file( 'sha256', $private ) ) &&
		hash_equals( hash( 'sha256', $bytes ), $artifact['sha256'] ),
		'Native private bytes, permission or digest changed.'
	);
	global $wpdb;
	wpai134_private_fallback_assert(
		$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wpai_private_lease' ) ) ) === null,
		'Unmerged DB fallback schema was unexpectedly created.'
	);
	echo 'PASS: #134 WordPress private OS temp fallback, 0600 WXR artifact, SHA-256, no DB schema or mandatory host setup.' . PHP_EOL;
} finally {
	if ( is_string( $private ) && is_file( $private ) ) {
		wp_delete_file( $private );
	}
}
