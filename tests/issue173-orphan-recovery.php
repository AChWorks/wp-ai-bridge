<?php
/**
 * One-shot private staging recovery after a hard-killed PHP worker.
 */
error_reporting( E_ALL );

$child   = isset( $argv[1] ) && 'child' === $argv[1];
$fixture = $child && isset( $argv[2] )
	? $argv[2]
	: sys_get_temp_dir() . '/wpai173-orphan-' . bin2hex( random_bytes( 8 ) );

if ( ! $child ) {
	foreach ( array( $fixture, $fixture . '/site', $fixture . '/site/wp-content', $fixture . '/private' ) as $dir ) {
		if ( ! mkdir( $dir, 0700 ) ) {
			throw new RuntimeException( 'Could not initialize private staging interruption fixture.' );
		}
	}
}
define( 'ABSPATH', $fixture . '/site/' );
define( 'WP_CONTENT_DIR', $fixture . '/site/wp-content' );
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;

class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function __( $message, $domain ) { return $message; }
function get_temp_dir() { return $GLOBALS['fixture'] . '/private/'; }
function wp_get_upload_dir() { return array( 'basedir' => WP_CONTENT_DIR ); }
function wp_tempnam( $filename, $directory = '' ) {
	return tempnam( '' !== $directory ? $directory : get_temp_dir(), 'wpai-native-stage-' );
}
function wp_delete_file( $path ) { if ( is_file( $path ) ) { unlink( $path ); } }
function wpai173_assert( $condition, $why ) {
	if ( ! $condition ) {
		throw new RuntimeException( $why );
	}
}
$GLOBALS['fixture'] = $fixture;

require dirname( __DIR__ ) . '/src/Support/class-safe-temporary-staging.php';
use WP_AI_Bridge\Support\Safe_Temporary_Staging;

if ( $child ) {
	$file = Safe_Temporary_Staging::create( 'private-media.png' );
	wpai173_assert( is_string( $file ), 'Child failed to stage a private file.' );
	file_put_contents( $file, 'confidential bytes from killed worker' );
	echo $file . "\n";
	flush();
	sleep( 30 ); // Parent terminates this process with SIGKILL, bypassing finally/shutdown.
	exit( 0 );
}

$proc  = null;
$pipes = array();
$stage = '';
$young = '';
$other = '';
$orphan = '';
try {
	$command = 'exec ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) .
		' child ' . escapeshellarg( $fixture );
	$proc = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	wpai173_assert( is_resource( $proc ), 'Failed to launch isolated staging worker.' );
	fclose( $pipes[0] );
	$orphan = trim( (string) fgets( $pipes[1] ) );
	$stage  = dirname( $orphan );
	wpai173_assert( is_file( $orphan ), 'Child did not create the expected sensitive staging bytes.' );
	wpai173_assert( dirname( $stage ) === realpath( $fixture . '/private' ), 'Sensitive file left the isolated private OS root.' );
	wpai173_assert( 0600 === ( fileperms( $orphan ) & 0777 ) && 0700 === ( fileperms( $stage ) & 0777 ), 'Staging confinement is not private.' );
	proc_terminate( $proc, 9 );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );
	$proc = null;
	wpai173_assert( is_file( $orphan ) && 'confidential bytes from killed worker' === file_get_contents( $orphan ), 'Hard process termination did not strand an orphan.' );

	$young = Safe_Temporary_Staging::create( 'currently-working.zip' );
	wpai173_assert( is_string( $young ) && is_file( $young ), 'Failed to create a still-active stage.' );
	file_put_contents( $young, 'active data' );
	$other = $stage . '/unrelated-transfer.claim';
	file_put_contents( $other, 'do not remove' );
	wpai173_assert( touch( $orphan, time() - 86400 - 120 ), 'Could not age orphan fixture.' );

	$fresh = Safe_Temporary_Staging::create( 'next-operation.png' );
	wpai173_assert( is_string( $fresh ) && is_file( $fresh ), 'Safe subsequent allocation failed.' );
	wpai173_assert( ! file_exists( $orphan ), 'Old hard-killed orphan not reaped on safe next operation.' );
	wpai173_assert( 'active data' === file_get_contents( $young ), 'Young active stage was modified.' );
	wpai173_assert( 'do not remove' === file_get_contents( $other ), 'Unrelated claim was touched.' );
	wpai173_assert( dirname( $fresh ) === $stage, 'Reusable private stage root changed across workers.' );
	wp_delete_file( $fresh );
	echo "PASS: Issue #173 killed-worker orphan reaped; active and unrelated files preserved.\n";
} finally {
	if ( is_resource( $proc ) ) {
		proc_terminate( $proc, 9 );
		foreach ( $pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe );
			}
		}
		proc_close( $proc );
	}
	foreach ( array( $young, $orphan, $other ) as $path ) {
		if ( $path && is_file( $path ) ) {
			unlink( $path );
		}
	}
	if ( $stage && is_dir( $stage ) ) {
		rmdir( $stage );
	}
	foreach ( array( $fixture . '/private', $fixture . '/site/wp-content', $fixture . '/site', $fixture ) as $dir ) {
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}
}
