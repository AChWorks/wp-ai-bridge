<?php
/**
 * Disposable native Media Ability staging/exception cleanup regression.
 *
 * Run only on an isolated WordPress install with WP_TEMP_DIR inside its
 * publicly served content directory. Never uses user files.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Media_Abilities;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

function wpai173_provider_check( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
}

wpai173_provider_check( current_user_can( 'upload_files' ), 'Fixture requires authenticated upload authority.' );
wpai173_provider_check( defined( 'WP_TEMP_DIR' ) && realpath( WP_TEMP_DIR ) === realpath( WP_CONTENT_DIR ), 'Fixture requires publicly served WordPress temp root.' );

$payload = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jphkAAAAASUVORK5CYII=', true );
wpai173_provider_check( is_string( $payload ) && strlen( $payload ) > 50, 'Fixture PNG unavailable.' );
$input  = array( 'filename' => 'wpai173-provider.png', 'content_base64' => base64_encode( $payload ) );
$media  = new Media_Abilities( new Permissions( new Settings() ), new Mutation_Log() );
$paths  = array();
$ids    = array();
$mode   = '';
$hook   = static function ( $file ) use ( &$paths, &$mode ) {
	$path = $file['tmp_name'] ?? '';
	if ( is_string( $path ) && '' !== $path ) {
		$paths[] = $path;
		wpai173_provider_check( is_file( $path ) && 0600 === ( fileperms( $path ) & 0777 ), 'Provider staged an unprotected file.' );
		wpai173_provider_check(
			0 !== strpos( (string) realpath( $path ) . '/', rtrim( (string) realpath( ABSPATH ), '/' ) . '/' ),
			'Provider staged data in served WordPress filesystem.'
		);
	}
	if ( 'reject' === $mode ) {
		$file['error'] = 'Synthetic validation rejection';
	}
	if ( 'throw' === $mode ) {
		throw new RuntimeException( 'Synthetic pre-move exception' );
	}
	return $file;
};
add_filter( 'wp_handle_sideload_prefilter', $hook );
try {
	$mode    = 'accept';
	$success = $media->upload( $input );
	wpai173_provider_check( ! is_wp_error( $success ) && ! empty( $success['id'] ), 'Media Ability did not publish a valid attachment.' );
	$id = (int) $success['id'];
	$ids[] = $id;
	$attached = get_attached_file( $id );
	wpai173_provider_check(
		is_string( $attached ) && is_file( $attached ) &&
		hash_equals( hash( 'sha256', $payload ), (string) hash_file( 'sha256', $attached ) ),
		'Published Media file differs from synthetic source.'
	);
	wpai173_provider_check( count( $paths ) === 1 && ! is_file( $paths[0] ), 'Successful Media staging was not moved/cleaned.' );

	$mode    = 'reject';
	$failure = $media->upload( $input );
	wpai173_provider_check( is_wp_error( $failure ), 'Synthetic prefilter rejection was accepted.' );
	wpai173_provider_check( count( $paths ) === 2 && ! is_file( $paths[1] ), 'Rejected Media pre-effect bytes remained staged.' );

	$mode   = 'throw';
	$threw  = false;
	try {
		$media->upload( $input );
	} catch ( RuntimeException $error ) {
		$threw = 'Synthetic pre-move exception' === $error->getMessage();
	}
	wpai173_provider_check( $threw && count( $paths ) === 3 && ! is_file( $paths[2] ), 'Thrown native exception leaked pre-effect staged data.' );
	echo 'PASS: #173 actual Media Ability HTTP-served temp exclusion, native success, validation failure and Throwable cleanup.' . PHP_EOL;
} finally {
	remove_filter( 'wp_handle_sideload_prefilter', $hook );
	foreach ( $ids as $id ) {
		wp_delete_attachment( $id, true );
	}
	foreach ( $paths as $path ) {
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
