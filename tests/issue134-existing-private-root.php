<?php
/**
 * Issue #134: a verified owner-only existing lease directory must be reusable
 * even when a restrictive host refuses redundant chmod calls.
 *
 * This is a wholly disposable PHP-only fixture; no real site is touched.
 */

namespace WP_AI_Bridge\Support {
	class Permissions {}

	// Simulate a managed PHP host that refuses chmod, including no-op calls.
	function chmod( $path, $mode ) {
		++$GLOBALS['wpai134_chmod_attempts'];
		return false;
	}
}

namespace {
	$fixture = sys_get_temp_dir() . '/wpai-134-root-' . bin2hex( random_bytes( 8 ) );
	$site    = $fixture . '/site';
	$safe    = $fixture . '/private';
	if ( ! mkdir( $fixture, 0700 ) || ! mkdir( $site, 0700 ) ||
		! mkdir( $site . '/wp-content', 0700 ) || ! mkdir( $safe, 0700 ) ) {
		throw new \RuntimeException( 'Could not create isolated storage regression fixture.' );
	}
	define( 'ABSPATH', $site . '/' );
	define( 'WP_CONTENT_DIR', $site . '/wp-content' );
	define( 'DB_NAME', 'wpai-134-fixture' );
	$_SERVER['DOCUMENT_ROOT'] = $site;
	$GLOBALS['wpai134_chmod_attempts'] = 0;
	$GLOBALS['wpai134_safe'] = $safe;

	class WP_Error {
		private $code;
		private $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code;
			$this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_data() { return $this->data; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function __( $message, $domain = null ) { return $message; }
	function get_temp_dir() { return $GLOBALS['wpai134_safe']; }
	function wp_get_upload_dir() { return array( 'basedir' => WP_CONTENT_DIR ); }
	function get_current_blog_id() { return 1; }
	function wp_salt( $scheme = '' ) { return 'synthetic-auth-salt'; }
	function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
	function trailingslashit( $path ) { return rtrim( $path, '/' ) . '/'; }

	require dirname( __DIR__ ) . '/src/Support/class-safe-temporary-staging.php';
	require dirname( __DIR__ ) . '/src/Support/class-private-transfer-session.php';

	$assertions = 0;
	function wpai134_assert( $condition, $message ) {
		global $assertions;
		++$assertions;
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$root = $safe . '/wpai-transfer-' . substr(
		hash( 'sha256', serialize( array( DB_NAME, ABSPATH, 1, wp_salt( 'auth' ) ) ) ),
		0, 40
	);
	$file = $root . '/private-file';
	$handle = null;
	try {
		$store = new \WP_AI_Bridge\Support\Private_Transfer_Session( new \WP_AI_Bridge\Support\Permissions() );
		$root_method = new \ReflectionMethod( $store, 'root' );
		$secure_file = new \ReflectionMethod( $store, 'secure_file' );
		$first = $root_method->invoke( $store );
		wpai134_assert( $first === $root && 0700 === ( fileperms( $root ) & 0777 ), 'New owner-only private root failed.' );
		wpai134_assert( 0 === $GLOBALS['wpai134_chmod_attempts'], 'No-op chmod was invoked on an already-secure root.' );

		$handle = fopen( $file, 'x+b' );
		wpai134_assert( is_resource( $handle ), 'Could not create fixture private file.' );
		wpai134_assert( \chmod( $file, 0600 ), 'Fixture setup chmod failed.' );
		wpai134_assert( true === $secure_file->invoke( $store, $handle, $file ), 'Secure 0600 file was rejected for denied no-op chmod.' );
		wpai134_assert( 0 === $GLOBALS['wpai134_chmod_attempts'], 'No-op chmod was invoked on already-secure file.' );

		wpai134_assert( \chmod( $file, 0644 ), 'Could not prepare insecure test file.' );
		wpai134_assert( false === $secure_file->invoke( $store, $handle, $file ), 'Insecure 0644 file was accepted when chmod was denied.' );
		wpai134_assert( 1 === $GLOBALS['wpai134_chmod_attempts'], 'Insecure file did not require corrective chmod.' );
		fclose( $handle );
		$handle = null;
		unlink( $file );

		wpai134_assert( \chmod( $root, 0755 ), 'Could not prepare insecure test directory.' );
		$result = $root_method->invoke( $store );
		wpai134_assert( is_wp_error( $result ) &&
			'transfer_storage_unsafe' === $result->get_error_code() &&
			'root_mode' === ( $result->get_error_data()['storage_reason'] ?? '' ),
			'Insecure 0755 private root did not reject with a safe reason.' );
		wpai134_assert( 2 === $GLOBALS['wpai134_chmod_attempts'], 'Insecure root did not require corrective chmod.' );

		rmdir( $root );
		mkdir( $fixture . '/safe-target', 0700 );
		symlink( $fixture . '/safe-target', $root );
		$result = $root_method->invoke( $store );
		wpai134_assert( is_wp_error( $result ) &&
			'root_symlink' === ( $result->get_error_data()['storage_reason'] ?? '' ),
			'Private root symlink was not rejected before any corrective write.' );
		wpai134_assert( 2 === $GLOBALS['wpai134_chmod_attempts'], 'Symlink classification invoked chmod.' );

		echo "PASS: #134 secure pre-existing root/file, denied no-op chmod, unsafe mode and symlink ({$assertions} assertions).\n";
	} finally {
		if ( is_resource( $handle ) ) { fclose( $handle ); }
		if ( is_file( $file ) ) { unlink( $file ); }
		if ( is_link( $root ) ) { unlink( $root ); }
		if ( is_dir( $root ) ) { rmdir( $root ); }
		if ( is_dir( $fixture . '/safe-target' ) ) { rmdir( $fixture . '/safe-target' ); }
		rmdir( $safe );
		rmdir( $site . '/wp-content' );
		rmdir( $site );
		rmdir( $fixture );
	}
}
