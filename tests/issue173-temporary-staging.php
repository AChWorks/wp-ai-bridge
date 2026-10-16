<?php
/**
 * Regressions for private temporary bytes before native WordPress effects.
 */
error_reporting( E_ALL );

$fixture = sys_get_temp_dir() . '/wpai173-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $fixture, 0700 ) || ! mkdir( $fixture . '/site', 0700 ) ||
	! mkdir( $fixture . '/site/wp-content', 0700 ) || ! mkdir( $fixture . '/aliased-uploads', 0700 ) ) {
	throw new RuntimeException( 'Could not prepare isolated temporary-staging fixture.' );
}
define( 'ABSPATH', $fixture . '/site/' );
define( 'WP_CONTENT_DIR', $fixture . '/site/wp-content' );
$GLOBALS['wpai173'] = array(
	'temp_dir' => WP_CONTENT_DIR,
	'uploads'  => $fixture . '/aliased-uploads',
	'created'  => array(),
);

class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function __( $message, $domain ) { return $message; }
function get_temp_dir() { return $GLOBALS['wpai173']['temp_dir']; }
function wp_get_upload_dir() { return array( 'basedir' => $GLOBALS['wpai173']['uploads'] ); }
function wp_tempnam( $filename, $directory = '' ) {
	$target = '' === $directory ? get_temp_dir() : $directory;
	$file = tempnam( $target, 'wpai-native-stage-' );
	$GLOBALS['wpai173']['created'][] = $file;
	return $file;
}
function wp_delete_file( $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
function wpai173_check( $valid, $reason ) { if ( ! $valid ) { throw new RuntimeException( $reason ); } }

require dirname( __DIR__ ) . '/src/Support/class-safe-temporary-staging.php';
use WP_AI_Bridge\Support\Safe_Temporary_Staging;

try {
	// WP_TEMP_DIR may be a publicly served content directory; stage elsewhere
	// automatically when the host has a trustworthy ordinary OS temp directory.
	$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
	wpai173_check( Safe_Temporary_Staging::directory() === realpath( sys_get_temp_dir() ), 'Private transfer root did not recover from publicly served WP temp.' );
	$file = Safe_Temporary_Staging::create( 'untrusted-image.png' );
	wpai173_check( is_string( $file ) && is_file( $file ), 'No safe scratch file was created.' );
	wpai173_check( dirname( dirname( $file ) ) === realpath( sys_get_temp_dir() ), 'Staging did not leave the served content root.' );
	wpai173_check( 0700 === ( fileperms( dirname( $file ) ) & 0777 ), 'Reusable staging directory not owner-only.' );
	wpai173_check( str_starts_with( basename( dirname( $file ) ), 'wpai-native-input-' ), 'No dedicated stage directory.' );
	wpai173_check( 0600 === ( fileperms( $file ) & 0777 ), 'Staging file not owner-only.' );
	wpai173_check( 0 === filesize( $file ), 'Allocation wrote bytes before authorization.' );
	wpai173_check( 1 === count( $GLOBALS['wpai173']['created'] ), 'Unsafe WordPress temp was allocated before selecting the safe alternative.' );
	wp_delete_file( $file );

	// Explicit upload aliases and symlinks remain treated as potentially public.
	$GLOBALS['wpai173']['temp_dir'] = $fixture . '/aliased-uploads';
	$file = Safe_Temporary_Staging::create( 'untrusted-zip.zip' );
	wpai173_check( is_string( $file ) && is_file( $file ), 'Alias fallback failed.' );
	wpai173_check( dirname( dirname( $file ) ) === realpath( sys_get_temp_dir() ), 'Alias path was treated as private.' );
	wp_delete_file( $file );

	wpai173_check( symlink( WP_CONTENT_DIR, $fixture . '/linked-content' ), 'Fixture symlink unavailable.' );
	$GLOBALS['wpai173']['temp_dir'] = $fixture . '/linked-content';
	$file = Safe_Temporary_Staging::create( 'untrusted-source.zip' );
	wpai173_check( is_string( $file ) && is_file( $file ), 'Symlink fallback failed.' );
	wpai173_check( dirname( dirname( $file ) ) === realpath( sys_get_temp_dir() ), 'Symlink to served path accepted.' );
	wp_delete_file( $file );

	// If every available directory is served, refuse before writing anything.
	$_SERVER['DOCUMENT_ROOT'] = sys_get_temp_dir();
	$before = count( $GLOBALS['wpai173']['created'] );
	$denied = Safe_Temporary_Staging::create( 'untrusted-media.png' );
	wpai173_check( Safe_Temporary_Staging::directory() instanceof WP_Error, 'Unsafe-only host exposed a private transfer root.' );
	wpai173_check( $denied instanceof WP_Error && 'temporary_storage_unsafe' === $denied->get_error_code(), 'Unsafe-only host did not fail closed.' );
	wpai173_check( count( $GLOBALS['wpai173']['created'] ) === $before, 'Unsafe-only host allocated a public temporary file.' );
	echo "PASS: Issue #173 private-temp staging, served roots, aliases, symlinks and fail-closed.\n";
} finally {
	foreach ( $GLOBALS['wpai173']['created'] as $candidate ) {
		if ( is_string( $candidate ) && is_file( $candidate ) ) {
			unlink( $candidate );
		}
	}
	// Remove only fixture-created empty stage directories after deleting their
	// own allocated files. Production leaves the reusable directory in place.
	foreach ( array_unique( array_map( 'dirname', $GLOBALS['wpai173']['created'] ) ) as $stage ) {
		if ( str_starts_with( basename( $stage ), 'wpai-native-input-' ) && is_dir( $stage ) ) {
			rmdir( $stage );
		}
	}
	if ( is_link( $fixture . '/linked-content' ) ) {
		unlink( $fixture . '/linked-content' );
	}
	rmdir( $fixture . '/aliased-uploads' );
	rmdir( $fixture . '/site/wp-content' );
	rmdir( $fixture . '/site' );
	rmdir( $fixture );
}
