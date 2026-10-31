<?php
/**
 * Issue 209: disposable PHP-bootstrap and config publication regression cases.
 * Each case gets its own PHP process because PHP constants cannot be undefined.
 */
if ( ! isset( $argv[1] ) ) {
	$cases = array(
		'ready', 'parent', 'minimal', 'crlf', 'already', 'false', 'duplicate', 'dynamic', 'eval', 'conditional',
		'external', 'missing', 'nonstandard', 'html_tail', 'unwritable', 'policy', 'file_mod', 'disallow_mods', 'non_direct', 'context_drift', 'runtime_external', 'runtime_conflict',
		'multisite', 'nonce', 'stale', 'concurrent', 'linked', 'unknown',
	);
	$count = 0;
	foreach ( $cases as $case ) {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $case );
		$lines = array();
		exec( $command . ' 2>&1', $lines, $code );
		if ( 0 !== $code ) {
			fwrite( STDERR, "FAIL: {$case}\n" . implode( "\n", $lines ) . "\n" );
			exit( 1 );
		}
		++$count;
	}
	echo "PASS: Issue 209 {$count} isolated PHP/config cases.\n";
	exit( 0 );
}

$case = $argv[1];
$root = sys_get_temp_dir() . '/wpai209-case-' . bin2hex( random_bytes( 8 ) );
$site = $root . '/site';
mkdir( $root, 0700 );
mkdir( $site, 0700 );
define( 'ABSPATH', $site . '/' );
$fixture = $site . '/wp-config.php';
$extra   = $root . '/alternate/wp-config.php';
$source  = "<?php\n/** secret-preserving fixture */\n\$db_password = 'synthetic-test-value';\nrequire_once ABSPATH . 'wp-settings.php';\n";
$before  = '';
$GLOBALS['wpai209'] = array(
	'case' => $case, 'path' => $fixture, 'can_edit' => true, 'file_mod' => true,
	'multisite' => false, 'super_admin' => true, 'context' => true, 'race' => false,
);

if ( 'minimal' === $case ) {
	$source = "<?php\nrequire_once ABSPATH . 'wp-settings.php';\n";
}
if ( 'crlf' === $case ) {
	$source = str_replace( "\n", "\r\n", $source );
}
if ( 'already' === $case ) {
	$source = str_replace( "require_once", "define( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL', true );\nrequire_once", $source );
}
if ( 'runtime_conflict' === $case ) {
	$source = str_replace( "require_once", "define( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL', false );\nrequire_once", $source );
}
if ( 'false' === $case ) {
	$source = str_replace( "require_once", "define( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL', false );\nrequire_once", $source );
}
if ( 'duplicate' === $case ) {
	$source = str_replace( "require_once", "define( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL', false );\ndefine( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL', true );\nrequire_once", $source );
}
if ( 'eval' === $case ) {
	$source = str_replace( "require_once", "eval( '/* disposable inert fixture */' );\nrequire_once", $source );
}
if ( 'dynamic' === $case ) {
	$source = str_replace( "require_once", "define( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_' . 'SQL', false );\nrequire_once", $source );
}
if ( 'conditional' === $case ) {
	$source = str_replace( "require_once", "if ( false ) { define( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL', true ); }\nrequire_once", $source );
}
if ( 'html_tail' === $case ) {
	$source .= "?>\nunsafe HTML tail";
}
if ( 'nonstandard' === $case ) {
	$source = str_replace( "require_once", "include", $source );
}
if ( 'external' === $case || 'linked' === $case ) {
	mkdir( dirname( $extra ), 0700 );
	$fixture = $extra;
}
if ( 'missing' !== $case && 'unknown' !== $case ) {
	file_put_contents( $fixture, $source );
}
if ( 'linked' === $case ) {
	symlink( $extra, $site . '/wp-config.php' );
	$fixture = $site . '/wp-config.php';
}
file_put_contents( $site . '/wp-settings.php', "<?php\n" );
if ( 'parent' === $case ) {
	$fixture = $root . '/wp-config.php';
	file_put_contents( $fixture, $source );
	@unlink( $site . '/wp-config.php' );
}
if ( 'unwritable' === $case ) {
	chmod( $fixture, 0444 );
}
if ( 'policy' === $case ) {
	$GLOBALS['wpai209']['can_edit'] = false;
}
if ( 'disallow_mods' === $case ) {
	define( 'DISALLOW_FILE_MODS', true );
}
if ( 'non_direct' === $case ) {
	define( 'FS_METHOD', 'ssh2' );
}
if ( 'file_mod' === $case ) {
	$GLOBALS['wpai209']['file_mod'] = false;
}
if ( 'context_drift' === $case ) {
	$GLOBALS['wpai209']['drift'] = true;
}
if ( 'multisite' === $case ) {
	$GLOBALS['wpai209']['multisite'] = true;
	$GLOBALS['wpai209']['super_admin'] = false;
}

if ( in_array( $case, array( 'runtime_external', 'runtime_conflict' ), true ) ) {
	define( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL', true ); // Synthetic external runtime source.
}
if ( ! in_array( $case, array( 'unknown', 'missing' ), true ) ) {
	// The PHP runtime records the exact included config. Core's wp-settings
	// bootstrap is a deliberate no-op in this disposable fixture.
	ini_set( 'display_errors', '0' );
	error_reporting( E_ERROR );
	include $fixture;
}

function __( $text, $domain = '' ) { return $text; }
function current_user_can( $cap ) { return 'edit_files' === $cap ? $GLOBALS['wpai209']['can_edit'] : $GLOBALS['wpai209']['context']; }
function is_multisite() { return $GLOBALS['wpai209']['multisite']; }
function is_super_admin() { return $GLOBALS['wpai209']['super_admin']; }
function wp_is_file_mod_allowed( $context ) {
	if ( ! empty( $GLOBALS['wpai209']['drift'] ) ) {
		$GLOBALS['wpai209']['drift'] = false;
		$GLOBALS['wpai209']['blog'] = 18;
	}
	return $GLOBALS['wpai209']['file_mod'];
}
function get_current_user_id() { return 11; }
function get_current_blog_id() { return $GLOBALS['wpai209']['blog'] ?? 17; }
function get_current_network_id() { return 23; }
function wp_verify_nonce( $nonce, $action ) { return 'valid-' . $action === $nonce; }
function nocache_headers() {}
function get_temp_dir() {
	if ( ! empty( $GLOBALS['wpai209']['race'] ) ) {
		file_put_contents( $GLOBALS['wpai209']['path'], "\n// concurrent actor changed file", FILE_APPEND );
		$GLOBALS['wpai209']['race'] = false;
	}
	return sys_get_temp_dir();
}

clearstatcache( true, $fixture );
$original_mode = is_file( $fixture ) ? ( fileperms( $fixture ) & 07777 ) : 0;
require dirname( __DIR__ ) . '/src/Admin/class-native-sql-config-setup.php';
$service = new WP_AI_Bridge\Admin\Native_SQL_Config_Setup();
$state   = $service->inspect();
$assert  = static function ( $condition, $message ) use ( $case ) {
	if ( ! $condition ) {
		throw new RuntimeException( "{$case}: {$message}" );
	}
};

$expected = array(
	'ready' => 'ready', 'parent' => 'ready', 'minimal' => 'ready', 'crlf' => 'ready', 'already' => 'enabled', 'false' => 'false',
	'duplicate' => 'complex', 'dynamic' => 'complex', 'eval' => 'complex', 'conditional' => 'pending',
	'external' => 'unsupported', 'missing' => 'unknown', 'nonstandard' => 'unsupported', 'html_tail' => 'unsupported',
	'runtime_external' => 'external', 'runtime_conflict' => 'false', 'unwritable' => 'ready', 'policy' => 'ready', 'file_mod' => 'ready', 'disallow_mods' => 'ready', 'non_direct' => 'ready', 'context_drift' => 'ready',
	'multisite' => 'ready', 'nonce' => 'ready', 'stale' => 'ready',
	'concurrent' => 'ready', 'linked' => 'unsupported', 'unknown' => 'unknown',
);
$assert( $expected[ $case ] === $state['state'], 'Incorrect detected state: ' . $state['state'] );
if ( ! in_array( $case, array( 'unknown', 'missing' ), true ) ) {
	$assert( realpath( $fixture ) === $state['path'], 'Must identify the exact included file.' );
} else {
	$assert( '' === $state['path'], 'Cannot invent an unproven config path.' );
}
$assert( in_array( $case, array( 'ready', 'parent', 'minimal', 'crlf', 'nonce', 'stale', 'concurrent' ), true ) === $state['can_auto'], 'Wrong automatic edit admission.' );
if ( 'runtime_external' === $case || 'runtime_conflict' === $case ) {
	$assert( $state['enabled'], 'Actual runtime enabled status must not erase conflicting or external file diagnostics.' );
}

if ( 'disallow_mods' === $case ) {
	$assert( str_contains( $state['reason'], 'DISALLOW_FILE_MODS' ), 'Report precise native configuration policy denial.' );
}
if ( 'non_direct' === $case ) {
	$assert( str_contains( $state['reason'], 'FS_METHOD' ), 'Do not bypass an explicit non-direct WordPress filesystem policy.' );
}
if ( 'context_drift' === $case ) {
	$assert( str_contains( $state['reason'], 'changed during native permission' ), 'Reject context drift caused by a WordPress policy hook.' );
}

$post = array(
	'action' => $service::ACTION,
	'_wpnonce' => 'valid-' . $service->nonce_action(),
	'user' => '11', 'blog' => '17', 'network' => '23',
	'expected_digest' => $state['digest'], 'expected_path_hash' => $state['path_hash'],
);
if ( 'nonce' === $case ) {
	$post['_wpnonce'] = 'invalid';
}
if ( 'stale' === $case ) {
	file_put_contents( $fixture, "\n// changed before submission", FILE_APPEND );
}
if ( 'concurrent' === $case ) {
	$GLOBALS['wpai209']['race'] = true;
	$GLOBALS['wpai209']['path'] = $fixture;
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$result = $service->activate( $post );
$published = in_array( $case, array( 'ready', 'parent', 'minimal', 'crlf' ), true );
$assert( ( 'written' === ( $result['result'] ?? '' ) ) === $published, 'Wrong publish result.' );
if ( $published ) {
	$after = file_get_contents( $fixture );
	$assert( 1 === substr_count( $after, $service::DEFINITION ), 'One exact definition must be published.' );
	$newline = 'crlf' === $case ? "\r\n" : "\n";
	$assert( str_replace( $service::DEFINITION . $newline . $newline, '', $after ) === $source, 'All original bytes must be preserved.' );
	$assert( false === defined( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL' ), 'Current PHP request must stay unchanged.' );
	clearstatcache( true, $fixture );
	$assert( $original_mode === ( fileperms( $fixture ) & 07777 ), 'Original file mode must survive replacement.' );
	$assert( 'pending' === $service->inspect()['state'], 'Current request must not incorrectly claim activation.' );
	$cmd = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( 'define("ABSPATH", ' . var_export( ABSPATH, true ) . '); require ' . var_export( $fixture, true ) . '; exit(defined("WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL") && true === WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL ? 0 : 1);' );
	exec( $cmd, $output, $code );
	$assert( 0 === $code, 'Fresh PHP bootstrap must observe boolean true.' );
} elseif ( ! in_array( $case, array( 'unknown', 'missing' ), true ) ) {
	$after = file_get_contents( $fixture );
	$assert( 0 === substr_count( $after, $service::DEFINITION ) || 'already' === $case || 'conditional' === $case || 'duplicate' === $case, 'Fallback must never add a new definition.' );
	if ( 'stale' === $case || 'concurrent' === $case ) {
		$assert( false !== strpos( $after, 'changed' ), 'External changes must not be overwritten.' );
	}
}

function wpai209_remove( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		@chmod( $path, 0600 );
		@unlink( $path );
	} elseif ( is_dir( $path ) ) {
		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $child ) {
			wpai209_remove( $path . '/' . $child );
		}
		rmdir( $path );
	}
}
if ( $state['path'] ) {
	$lock_file = sys_get_temp_dir() . '/wpai-config-' . hash( 'sha256', $state['path'] ) . '.lock';
	@unlink( $lock_file ); // Test-owned finished fixture lock only, never a hosted lock.
}
wpai209_remove( $root );
echo "PASS: {$case}\n";
