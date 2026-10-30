<?php
/** Boot a separate harness copy only after the full restore oracle has passed. */
require_once __DIR__ . '/issue199-completion-helper.php';
global $wpdb;
$database = getenv( 'WPAI199C_EXPECT_DATABASE' );
$schema = getenv( 'WPAI_ADMIN13_RESTORE_SCHEMA' );
$target = getenv( 'WPAI_ADMIN13_RESTORE_ROOT' );
wpai199c_assert( defined( 'WPAI_ADMIN13_DISPOSABLE' ) && true === WPAI_ADMIN13_DISPOSABLE &&
	is_string( $database ) && '' !== $database && $wpdb->get_var( 'SELECT DATABASE()' ) === $database &&
	is_string( $schema ) && $schema !== $database && preg_match( '/^wpai[a-z0-9_]+restore[a-z0-9_]*$/D', $schema ) &&
	is_string( $target ) && realpath( $target ) === $target && ! is_link( $target ) &&
	defined( 'WP_TEMP_DIR' ) && function_exists( 'proc_open' ), 'Explicit disposable source and restore identity required.' );
$verified = $target . '/roots/installation';
wpai199c_assert( realpath( $verified ) === $verified && is_file( $verified . '/wp-load.php' ) && is_file( $verified . '/wp-config.php' ), 'Verified full installation restore is unavailable.' );
$before = wpai199c_file_image( $verified );
$source_before = wpai199c_database_image( $wpdb->dbh, $database );
$base = realpath( dirname( rtrim( WP_TEMP_DIR, '/' ) ) );
wpai199c_assert( is_string( $base ) && ! str_starts_with( $base . '/', rtrim( ABSPATH, '/' ) . '/' ), 'Derived bootstrap requires existing nonserved private storage.' );
$derived = $base . '/derived-' . bin2hex( random_bytes( 6 ) );
wpai199c_assert( mkdir( $derived, fileperms( $verified ) & 0777 ), 'Cannot create a separate derived bootstrap root.' );
$copy = static function ( $source, $destination ) use ( &$copy ) {
	wpai199c_assert( ! is_link( $source ), 'Derived copy refuses symlinks.' );
	if ( is_dir( $source ) ) {
		wpai199c_assert( mkdir( $destination, fileperms( $source ) & 0777 ), 'Derived directory copy failed.' );
		foreach ( new DirectoryIterator( $source ) as $entry ) {
			if ( ! $entry->isDot() ) { $copy( $entry->getPathname(), $destination . '/' . $entry->getFilename() ); }
		}
	} else {
		wpai199c_assert( is_file( $source ) && copy( $source, $destination ) && chmod( $destination, fileperms( $source ) & 0777 ), 'Derived file copy failed.' );
	}
};
foreach ( new DirectoryIterator( $verified ) as $entry ) {
	if ( ! $entry->isDot() ) { $copy( $entry->getPathname(), $derived . '/' . $entry->getFilename() ); }
}
$isolated_temp = $base . '/derived-temp-' . bin2hex( random_bytes( 6 ) );
wpai199c_assert( mkdir( $isolated_temp, 0700 ), 'Cannot create derived private temporary storage.' );
$config = "<?php\n";
foreach ( array( 'DB_NAME' => $schema, 'DB_USER' => DB_USER, 'DB_PASSWORD' => DB_PASSWORD, 'DB_HOST' => DB_HOST,
	'DB_CHARSET' => DB_CHARSET, 'DB_COLLATE' => defined( 'DB_COLLATE' ) ? DB_COLLATE : '',
	'WP_HOME' => 'https://derived-admin13.invalid', 'WP_SITEURL' => 'https://derived-admin13.invalid',
	'WP_TEMP_DIR' => $isolated_temp, 'DISABLE_WP_CRON' => true, 'WP_HTTP_BLOCK_EXTERNAL' => true,
	'DISALLOW_FILE_MODS' => true, 'WP_CACHE' => false, 'WPAI_ADMIN13_DISPOSABLE' => true,
	'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL' => false ) as $key => $value ) {
	$config .= 'define(' . var_export( $key, true ) . ',' . var_export( $value, true ) . ");\n";
}
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $key ) {
	$config .= 'define(' . var_export( $key, true ) . ',' . var_export( bin2hex( random_bytes( 32 ) ), true ) . ");\n";
}
$config .= '$table_prefix=' . var_export( $wpdb->prefix, true ) . ";\ndefine('ABSPATH',__DIR__.'/');\nrequire ABSPATH.'wp-settings.php';\n";
wpai199c_assert( file_put_contents( $derived . '/wp-config.php', $config ) === strlen( $config ) && chmod( $derived . '/wp-config.php', 0600 ), 'Derived isolated configuration failed.' );
$mu = $derived . '/wp-content/mu-plugins';
if ( ! is_dir( $mu ) ) { wpai199c_assert( mkdir( $mu, 0755 ), 'Derived MU isolation directory failed.' ); }
$barrier = <<<'BARRIER'
<?php
add_filter('pre_http_request',static function(){return new WP_Error('admin13_derived_network_blocked');},PHP_INT_MIN);
add_filter('pre_wp_mail','__return_false',PHP_INT_MIN);
add_filter('action_scheduler_allow_async_request_runner','__return_false',PHP_INT_MIN);
BARRIER;
wpai199c_assert( file_put_contents( $mu . '/000-admin13-derived-isolation.php', $barrier ) === strlen( $barrier ), 'Derived bootstrap isolation failed.' );
$environment = getenv();
$environment['WPAI199C_DERIVED_ROOT'] = $derived;
$environment['WPAI199C_DERIVED_SCHEMA'] = $schema;
$environment['WPAI199C_DERIVED_VERSION'] = $GLOBALS['wp_version'];
$code = <<<'BOOT'
require getenv('WPAI199C_DERIVED_ROOT').'/wp-load.php';
global $wpdb,$wp_version;
if(DB_NAME!==getenv('WPAI199C_DERIVED_SCHEMA')||$wpdb->get_var('SELECT DATABASE()')!==DB_NAME||$wp_version!==getenv('WPAI199C_DERIVED_VERSION')||!is_string(get_option('blogname'))||!function_exists('wp_get_ability')||!defined('WP_AI_BRIDGE_DIR')){throw new RuntimeException('Derived restored WordPress bootstrap identity failed');}
if(!is_wp_error(wp_remote_get('https://example.invalid/'))){throw new RuntimeException('Derived network isolation failed');}
echo json_encode(['status'=>'pass','wordpress'=>$wp_version,'database'=>DB_NAME,'bridge_loaded'=>true,'network_blocked'=>true,'mail_blocked'=>wp_mail('nobody@example.invalid','test','test')===false])."\n";
BOOT;
$process = proc_open( array( PHP_BINARY, '-d', 'memory_limit=512M', '-r', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $derived, $environment );
wpai199c_assert( is_resource( $process ), 'Cannot launch a separate restored WordPress PHP process.' );
fclose( $pipes[0] ); stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
$output = ''; $errors = ''; $deadline = microtime( true ) + 60; $exit = null;
try {
	do {
		$output .= stream_get_contents( $pipes[1] ); $errors .= stream_get_contents( $pipes[2] );
		wpai199c_assert( strlen( $output ) + strlen( $errors ) <= 32768, 'Derived child exceeded bounded output.' );
		$status = proc_get_status( $process );
		if ( ! $status['running'] ) { $exit = $status['exitcode']; break; }
		wpai199c_assert( microtime( true ) < $deadline, 'Derived child exceeded finite bootstrap deadline.' );
		usleep( 10000 );
	} while ( true );
} finally {
	if ( null === $exit ) { proc_terminate( $process ); }
	fclose( $pipes[1] ); fclose( $pipes[2] ); $closed = proc_close( $process );
	if ( null === $exit ) { $exit = $closed; }
}
wpai199c_assert( 0 === $exit, 'Derived restored WordPress bootstrap failed: ' . substr( $errors, 0, 2000 ) );
$boot = json_decode( trim( $output ), true );
wpai199c_assert( is_array( $boot ) && true === $boot['mail_blocked'], 'Derived child boot evidence was incomplete.' );
wpai199c_assert( $source_before === wpai199c_database_image( $wpdb->dbh, $database ), 'Derived bootstrap modified the source database.' );
wpai199c_assert( $before === wpai199c_file_image( $verified ), 'Derived bootstrap modified the exact verified restored files.' );
echo json_encode( array( 'test' => 'issue199-derived-restored-bootstrap', 'status' => 'pass', 'derived_root' => $derived, 'verified_root_preserved' => true, 'boot' => $boot ), JSON_UNESCAPED_SLASHES ) . "\n";
