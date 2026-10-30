<?php
/**
 * Consumer cursor identity tests; scoped path/open security is tested by the shared backend.
 *
 * @package WP_AI_Bridge
 */
namespace {
	require __DIR__ . '/bootstrap.php';
	function wp_salt( $scheme = 'auth' ) { return 'log-cursor-test'; }
}
namespace WP_AI_Bridge\Support {
	class Administration_Access {
		public static $config = array( 'revision' => 'one', 'step_bytes' => 8192, 'step_seconds' => 2 );
		public function __construct( $permissions ) {}
		public function configuration() { return self::$config; }
	}
	class Filesystem_Roots {
		public function __construct( $permissions ) {}
	}
	class Scoped_Filesystem {
		public static $stream;
		public static $key = 'same-inode';
		public static $revision = 'root-one';
		public function __construct( $permissions ) {}
		public function with_read_stream( $root, $path, $purpose, $expected, $callback ) {
			if ( 'logs' !== $purpose || null !== $expected ) {
				throw new \RuntimeException( 'Log consumer bypassed fixed purpose/latest pinned open.' );
			}
			$stat = fstat( self::$stream );
			return $callback( self::$stream, array( 'size' => $stat['size'], 'identity' => 'observed-full-stat',
				'file_key' => self::$key, 'root_revision' => self::$revision ) );
		}
	}
}
namespace {
	use WP_AI_Bridge\Support\Administration_Access;
	use WP_AI_Bridge\Support\Log_Reader;
	use WP_AI_Bridge\Support\Scoped_Filesystem;
	use WP_AI_Bridge\Support\Permissions;
	use WP_AI_Bridge\Support\Settings;

	$assertions = 0;
	function cursor_assert( $condition, $why ) {
		global $assertions;
		++$assertions;
		if ( ! $condition ) { throw new RuntimeException( $why ); }
	}
	$stream = tmpfile();
	Scoped_Filesystem::$stream = $stream;
	fwrite( $stream, "first\nsecond\n" );
	$reader = new Log_Reader( new Permissions( new Settings() ) );
	$origin = array( 'blog' => 1, 'network' => 1, 'user' => 3, 'client' => 'client-one' );
	$request = array( 'action' => 'tail', 'root_id' => 'approved', 'path' => 'provider.log', 'max_bytes' => 4096, 'lines' => 1 );
	$first = $reader->read( $request, $origin );
	cursor_assert( ! is_wp_error( $first ) && "second\n" === base64_decode( $first['data_base64'] ) && $first['eof'], 'Initial tail/cursor failed.' );
	fseek( $stream, 0, SEEK_END );
	$bytes = "third\x00\xff\n";
	fwrite( $stream, $bytes );
	$next_request = array( 'action' => 'read', 'root_id' => 'approved', 'path' => 'provider.log', 'max_bytes' => 4096, 'cursor' => $first['cursor'] );
	$next = $reader->read( $next_request, $origin );
	cursor_assert( ! is_wp_error( $next ) && $bytes === base64_decode( $next['data_base64'] ) && 13 === $next['offset'], 'Append continuation changed or skipped bytes.' );
	$wrong = $next_request;
	$wrong['path'] = 'other.log';
	cursor_assert( is_wp_error( $reader->read( $wrong, $origin ) ), 'Cursor adopted another relative source.' );
	$wrong = $next_request;
	$wrong['cursor'] = substr( $first['cursor'], 0, -1 ) . ( 'a' === substr( $first['cursor'], -1 ) ? 'b' : 'a' );
	cursor_assert( is_wp_error( $reader->read( $wrong, $origin ) ), 'Forged cursor accepted.' );
	$other = $origin;
	$other['client'] = 'client-two';
	cursor_assert( is_wp_error( $reader->read( $next_request, $other ) ), 'Client-switched cursor adopted.' );
	$other = $origin;
	$other['network'] = 2;
	cursor_assert( is_wp_error( $reader->read( $next_request, $other ) ), 'Original network binding missing.' );
	Administration_Access::$config['revision'] = 'two';
	cursor_assert( is_wp_error( $reader->read( $next_request, $origin ) ), 'Changed root configuration adopted old cursor.' );
	Administration_Access::$config['revision'] = 'one';
	Scoped_Filesystem::$key = 'replacement-inode';
	cursor_assert( is_wp_error( $reader->read( $next_request, $origin ) ), 'Rename rotation adopted new inode.' );
	Scoped_Filesystem::$key = 'same-inode';
	Scoped_Filesystem::$revision = 'root-two';
	cursor_assert( is_wp_error( $reader->read( $next_request, $origin ) ), 'Changed root adopted old cursor.' );
	Scoped_Filesystem::$revision = 'root-one';
	ftruncate( $stream, 4 );
	cursor_assert( is_wp_error( $reader->read( $next_request, $origin ) ), 'Copytruncate shrink adopted old byte offset.' );
	rewind( $stream );
	fwrite( $stream, "xxxxxxxxxxxx\n" );
	cursor_assert( is_wp_error( $reader->read( $next_request, $origin ) ), 'Same-size rewritten boundary adopted old offset.' );
	fclose( $stream );
	echo 'Log cursor regressions passed: ' . $assertions . "\n";
}
