<?php
/**
 * Native journal clean sessions, independent concurrency and protocol ambiguity.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Support\Database_Connection;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;
require_once __DIR__ . '/issue199-native-option-write-helper.php';

$role = getenv( 'WPAI199NW_ROLE' );
if ( in_array( $role, array( 'race', 'transport' ), true ) ) {
	$request = json_decode( base64_decode( getenv( 'WPAI199NW_REQUEST' ), true ), true );
	$directory = getenv( 'WPAI199NW_DIRECTORY' );
	$slot = getenv( 'WPAI199NW_SLOT' ) ?: 'transport';
	wpai199n_assert( is_array( $request ) && is_dir( $directory ) && current_user_can( 'manage_options' ), 'Invalid native worker assignment.' );
	add_filter( 'pre_update_option_' . $request['name'], static function ( $value ) use ( $directory, $role ) {
		wpai199n_assert( false !== file_put_contents( $directory . '/calls', "called\n", FILE_APPEND | LOCK_EX ), 'Cannot record actual native mutator callback.' );
		if ( 'race' === $role ) {
			file_put_contents( $directory . '/entered', '1' );
			$deadline = microtime( true ) + 25;
			while ( ! is_file( $directory . '/release' ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
			wpai199n_assert( is_file( $directory . '/release' ), 'Native callback barrier timed out.' );
		}
		return $value;
	} );
	if ( 'race' === $role ) {
		file_put_contents( $directory . '/' . $slot . '.ready', '1' );
		$deadline = microtime( true ) + 20;
		while ( ! is_file( $directory . '/go' ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
		wpai199n_assert( is_file( $directory . '/go' ), 'Native start barrier timed out.' );
	}
	$result = wpai199nw_call( $request );
	$summary = is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : array( 'state' => $result['state'], 'operation_ref' => $result['operation_ref'], 'historical' => $result['historical'], 'outcome' => $result['receipt']['outcome'] ?? null );
	file_put_contents( $directory . '/' . $slot . '.done', wp_json_encode( $summary ) );
	echo 'WPAI199NW_WORKER_RESULT=' . wp_json_encode( $summary ) . "\n";
	return;
}
function wpai199nws_process( array $command, array $env ) {
	$pipes = array();
	$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, ABSPATH, $env );
	wpai199n_assert( is_resource( $process ), 'Cannot create independent native worker.' );
	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
	return array( 'process' => $process, 'out' => $pipes[1], 'err' => $pipes[2], 'stdout' => '', 'stderr' => '', 'exit' => null );
}
function wpai199nws_wait( array &$child ) {
	$deadline = microtime( true ) + 45;
	do {
		$child['stdout'] .= stream_get_contents( $child['out'] );
		$child['stderr'] .= stream_get_contents( $child['err'] );
		$status = proc_get_status( $child['process'] );
		if ( ! $status['running'] ) { $child['exit'] = $status['exitcode']; break; }
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );
	wpai199n_assert( 0 === $child['exit'], 'Native worker failed/timed out: ' . substr( $child['stderr'], 0, 600 ) );
	$child['stdout'] .= stream_get_contents( $child['out'] );
}
function wpai199nws_close( array $child ) {
	$status = proc_get_status( $child['process'] );
	if ( $status['running'] ) { proc_terminate( $child['process'] ); }
	fclose( $child['out'] ); fclose( $child['err'] ); proc_close( $child['process'] );
}
function wpai199nws_env( array $request, $actor, $directory ) {
	$env = getenv();
	$env['WPAI199NW_REQUEST'] = base64_encode( wp_json_encode( $request ) );
	$env['WPAI199NW_DIRECTORY'] = $directory;
	$env['WPAI199NW_ACTOR'] = (string) $actor;
	$env['WPAI199NW_WPLOAD'] = ABSPATH . 'wp-load.php';
	$env['WPAI199NW_WORKER'] = __FILE__;
	return $env;
}
function wpai199nws_transport( array $request, $actor, $directory, $mode ) {
	global $wpdb;
	$host = DB_HOST;
	if ( str_starts_with( $host, 'localhost:/' ) ) { $upstream = 'unix://' . substr( $host, 10 ); }
	elseif ( 'localhost' === $host ) { $upstream = 'unix://' . $wpdb->get_var( 'SELECT @@socket' ); }
	else { $upstream = 'tcp://' . $host . ( str_contains( $host, ':' ) ? '' : ':3306' ); }
	$socket = rtrim( sys_get_temp_dir(), '/' ) . '/wpai199w-proxy-' . bin2hex( random_bytes( 6 ) ) . '.sock';
	$env = wpai199nws_env( $request, $actor, $directory );
	$env['WPAI199NW_ROLE'] = 'transport';
	$env['WPAI199W_PROXY_SOCKET'] = $socket;
	$env['WPAI199W_PROXY_UPSTREAM'] = $upstream;
	$env['WPAI199W_PROXY_MODE'] = $mode;
	$php = getenv( 'WPAI199W_PHP' ) ?: PHP_BINARY;
	$proxy = wpai199nws_process( array( $php, __DIR__ . '/issue199-native-option-write-transport-proxy.php' ), $env );
	$client = null;
	try {
		$deadline = microtime( true ) + 10;
		while ( ! file_exists( $socket ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
		wpai199n_assert( file_exists( $socket ), 'Private native SQL relay did not initialize.' );
		// Existing typed-write relay pattern: only this disposable child selects
		// a private socket; Core still owns the genuine mysqli connection.
		$code = 'define("DB_HOST","localhost:".getenv("WPAI199W_PROXY_SOCKET")); set_error_handler(static function($level,$message){return E_WARNING===$level && str_contains($message,"Constant DB_HOST already defined");}); require getenv("WPAI199NW_WPLOAD"); restore_error_handler(); wp_set_current_user((int)getenv("WPAI199NW_ACTOR")); require getenv("WPAI199NW_WORKER");';
		$client = wpai199nws_process( array( $php, '-r', $code ), $env );
		wpai199nws_wait( $client ); wpai199nws_wait( $proxy );
		wpai199n_assert( 1 === preg_match( '/WPAI199NW_WORKER_RESULT=(\{[^\r\n]+\})/', $client['stdout'], $client_match ) && 1 === preg_match( '/WPAI199W_PROXY_RESULT=(\{[^\r\n]+\})/', $proxy['stdout'], $proxy_match ), 'Native protocol omitted checkpoint/outcome evidence.' );
		return array( json_decode( $client_match[1], true ), json_decode( $proxy_match[1], true ) );
	} finally {
		if ( null !== $client ) { wpai199nws_close( $client ); }
		wpai199nws_close( $proxy );
		if ( file_exists( $socket ) ) { unlink( $socket ); }
	}
}
/** Core publishes Options caches before transaction ownership is resolved. */
function wpai199nws_forget_rolled_back_options( $target ) {
	// These are fixture-owned ROLLBACKs, not product transaction repair.
	// add/update_option cache Activity/target immediately after SQL success;
	// a later caller rollback cannot undo those PHP/object-cache entries.
	wp_cache_delete( $target, 'options' );
	wp_cache_delete( Mutation_Log::OPTION_NAME, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
}
function wpai199nws_database_drift( $name ) {
	global $wpdb;
	$original_db = (string) $wpdb->get_var( 'SELECT DATABASE()' );
	$alternate = getenv( 'WPAI199NW_ALTERNATE_DB' ) ?: ( 'n199_single' === $original_db ? 'n199_multi' : $original_db . '_native_context_' . bin2hex( random_bytes( 3 ) ) );
	wpai199n_assert( $alternate !== $original_db && 1 === preg_match( '/^[A-Za-z0-9_]{1,64}$/D', $alternate ), 'Invalid synthetic alternate database locator.' );
	$handle = $wpdb->dbh;
	$report = ( new mysqli_driver() )->report_mode;
	$owns_schema = false;
	$owns_table = false;
	$inserted = false;
	$native_before = get_option( $name );
	$audit_before = get_option( Mutation_Log::OPTION_NAME, array() );
	$foreign_value = 'separate physical database unchanged';
	$quote = array( Database_Connection::class, 'quote' );
	$foreign_table = $quote( $alternate ) . '.' . $quote( $wpdb->options );
	try {
		mysqli_report( MYSQLI_REPORT_OFF );
		$selected = @mysqli_select_db( $handle, $alternate );
		if ( ! $selected && 'n199_multi' !== $alternate ) {
			$owns_schema = true === @mysqli_query( $handle, 'CREATE DATABASE ' . $quote( $alternate ) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
			$selected = $owns_schema && @mysqli_select_db( $handle, $alternate );
		}
		$unavailable_errno = mysqli_errno( $handle );
		mysqli_select_db( $handle, $original_db );
		mysqli_report( $report );
		if ( ! $selected ) {
			echo 'SKIP: selected-physical-database drift needs a separately granted disposable schema (native errno ' . $unavailable_errno . ").\n";
			return;
		}
		$exists = mysqli_query( $handle, $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND BINARY TABLE_NAME=BINARY %s', $alternate, $wpdb->options ) )->fetch_row()[0];
		if ( '0' === (string) $exists ) {
			wpai199n_assert( true === mysqli_query( $handle, 'CREATE TABLE ' . $foreign_table . ' LIKE ' . $quote( $original_db ) . '.' . $quote( $wpdb->options ) ), 'Cannot create synthetic alternate native options table.' );
			$owns_table = true;
		}
		wpai199n_assert( true === mysqli_query( $handle, $wpdb->prepare( 'INSERT INTO ' . $foreign_table . ' (option_name,option_value,autoload) VALUES (%s,%s,%s)', $name, $foreign_value, 'off' ) ), 'Cannot insert exact synthetic foreign option.' );
		$inserted = true;
		foreach ( array( 'precondition', 'audit' ) as $phase ) {
			$calls = 0;
			$switched = false;
			$count = static function ( $value ) use ( &$calls ) { ++$calls; return $value; };
			add_filter( 'pre_update_option_' . $name, $count );
			$drift = static function ( $value, $old = null ) use ( $phase, $handle, $alternate, $native_before, &$switched ) {
				wpai199n_assert( true === mysqli_select_db( $handle, $alternate ), 'Cannot switch the original native physical connection.' );
				$switched = true;
				return 'precondition' === $phase ? $native_before : $old;
			};
			$hook = 'precondition' === $phase ? 'pre_option_' . $name : 'pre_update_option_' . Mutation_Log::OPTION_NAME;
			$request = wpai199nw_request( 'update', $name, 'effect before audit context failure' );
			if ( 'precondition' === $phase ) { $request['expected_observed_sha256'] = wpai199nw_hash( $name ); }
			add_filter( $hook, $drift, PHP_INT_MAX, 2 );
			try {
				$error = wpai199n_error( wpai199nw_call( $request ), 'Selected physical database drift returned sensitive native output.' );
				wpai199n_assert( $switched && $alternate === mysqli_query( $handle, 'SELECT DATABASE()' )->fetch_row()[0] && ( 'precondition' === $phase ? 0 : 1 ) === $calls, 'Physical drift was repaired silently or reached the wrong number of native setters.' );
				if ( 'precondition' === $phase ) { wpai199n_assert( false !== strpos( $error->get_error_code(), 'context' ) || false !== strpos( $error->get_error_code(), 'connection' ), 'Physical DB drift was disguised as a value precondition mismatch.' ); }
				wpai199nw_public_private( $error, array( $name, $foreign_value, 'effect before audit context failure' ) );
				wpai199n_assert( $foreign_value === mysqli_query( $handle, $wpdb->prepare( 'SELECT option_value FROM ' . $foreign_table . ' WHERE BINARY option_name=BINARY %s', $name ) )->fetch_row()[0], 'Native mutation retargeted the foreign physical database.' );
			} finally {
				remove_filter( $hook, $drift, PHP_INT_MAX );
				remove_filter( 'pre_update_option_' . $name, $count );
				mysqli_select_db( $handle, $original_db );
				wp_cache_delete( $name, 'options' );
				wpai199n_restore_option( $name, $native_before );
				wpai199n_restore_option( Mutation_Log::OPTION_NAME, $audit_before );
			}
		}
	} finally {
		mysqli_report( $report );
		mysqli_select_db( $handle, $original_db );
		if ( $inserted ) { mysqli_query( $handle, $wpdb->prepare( 'DELETE FROM ' . $foreign_table . ' WHERE BINARY option_name=BINARY %s', $name ) ); }
		if ( $owns_schema ) { mysqli_query( $handle, 'DROP DATABASE ' . $quote( $alternate ) ); }
		elseif ( $owns_table ) { mysqli_query( $handle, 'DROP TABLE ' . $foreign_table ); }
	}
}
global $wpdb;
$actor = get_current_user_id();
$ordinary = get_option( Settings::OPTION_NAME, null );
$log = get_option( Mutation_Log::OPTION_NAME, null );
$journals = wpai199nw_journals_before();
$suffix = bin2hex( random_bytes( 5 ) );
$name = 'native199nws_' . $suffix;
$table = $wpdb->prefix . 'wpai199nws_' . $suffix;
$directory = WP_CONTENT_DIR . '/wpai199nw-session-' . $suffix;
$observer = null;
$created = false;
$children = array();
$callback = null;
$provider_tx = null;
try {
	wpai199n_assert( ! is_multisite() && current_user_can( 'manage_options' ) && function_exists( 'proc_open' ), 'Use disposable single-site administrator and independent PHP workers.' );
	$settings = new Settings(); $settings->register(); wpai199nw_grants();
	wpai199n_assert( mkdir( $directory, 0700 ) && true === add_option( $name, 'initial', '', false ), 'Cannot create native session fixture.' );
	wpai199n_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, n INT NOT NULL) ENGINE=InnoDB', $table ) ), 'Cannot create native observer table.' );
	$created = true;
	wpai199n_assert( 1 === $wpdb->insert( $table, array( 'id' => 1, 'n' => 0 ), array( '%d', '%d' ) ), 'Cannot initialize native observer row.' );
	$observer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$observer->suppress_errors( true );
	wpai199n_assert( false !== $observer->query( 'SET SESSION lock_wait_timeout=1' ) && false !== $observer->query( 'SET SESSION innodb_lock_wait_timeout=1' ), 'Cannot bound independent observer.' );
	$callback_count = 0;
	$callback = static function ( $value ) use ( &$callback_count ) { ++$callback_count; return $value; };
	add_filter( 'pre_update_option_' . $name, $callback );
	$wpdb->query( 'START TRANSACTION' );
	wpai199n_assert( 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET n=7 WHERE id=1', $table ) ), 'Cannot establish caller-owned work.' );
	wpai199n_error( wpai199nw_call( wpai199nw_request( 'update', $name, 'must not dispatch' ) ), 'Native journal entered ambient transaction.', array( 'native_options_write_unsupported_session' ) );
	wpai199n_assert( 0 === $callback_count && '7' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT n FROM %i WHERE id=1', $table ) ) && '0' === (string) $observer->get_var( $observer->prepare( 'SELECT n FROM %i WHERE id=1', $table ) ), 'Ambient denial committed/rolled back caller work or dispatched.' );
	$wpdb->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	wpai199nws_forget_rolled_back_options( $name );
	foreach ( array( array( $wpdb->options . ' WRITE', $wpdb->options, false ), array( $table . ' READ', $table, true ), array( $table . ' WRITE', $table, false ), array( $wpdb->options . ' WRITE, ' . $table . ' READ', $table, true ) ) as $index => $lock_case ) {
		$wpdb->query( 'LOCK TABLES ' . $lock_case[0] );
		$previous_error_suppression = $wpdb->suppress_errors( true );
		try {
			wpai199n_error( wpai199nw_call( wpai199nw_request( 'update', $name, 'must not enter lock', 'site', 'native-lock-' . $suffix . '-' . $index ) ), 'Callback journal admitted SQL locks.', array( 'native_options_write_unsupported_session' ) );
			$sql = $lock_case[2] ? $observer->prepare( 'UPDATE %i SET n=1 WHERE id=1', $lock_case[1] ) : $observer->prepare( 'SELECT 1 FROM %i LIMIT 1', $lock_case[1] );
			wpai199n_assert( false === $observer->query( $sql ) && 1205 === mysqli_errno( $observer->dbh ) && 0 === $callback_count, 'Observer did not prove retained caller SQL locks and zero native dispatch.' );
		} finally {
			$wpdb->query( 'UNLOCK TABLES' );
			$wpdb->suppress_errors( $previous_error_suppression );
		}
	}
	$wpdb->query( $wpdb->prepare( 'LOCK TABLES %i WRITE, %i WRITE', $wpdb->options, $table ) );
	try {
		$old = new Database_Connection(); $old->begin();
		$old->run( 'UPDATE ' . $old->table( $table ) . ' SET n=2 WHERE id=1' );
		wpai199n_assert( true === $old->commit(), 'Old typed connection lost supported LOCK TABLES mode.' );
		wpai199n_assert( false === $observer->query( $observer->prepare( 'SELECT n FROM %i WHERE id=1', $table ) ) && 1205 === mysqli_errno( $observer->dbh ), 'Old typed commit released caller SQL locks.' );
	} finally { $wpdb->query( 'UNLOCK TABLES' ); }
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET n=0 WHERE id=1', $table ) );
	$provider_tx = static function ( $value ) use ( $table ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		wpai199n_assert( 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET n=9 WHERE id=1', $table ) ), 'Cannot establish provider transaction.' );
		return $value;
	};
	add_filter( 'pre_update_option_' . $name, $provider_tx, 20 );
	$left_tx = wpai199nw_request( 'update', $name, 'provider-owned target bytes' );
	wpai199nw_call( $left_tx );
	remove_filter( 'pre_update_option_' . $name, $provider_tx, 20 ); $provider_tx = null;
	wpai199n_assert( 1 === $callback_count && '9' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT n FROM %i WHERE id=1', $table ) ) && '0' === (string) $observer->get_var( $observer->prepare( 'SELECT n FROM %i WHERE id=1', $table ) ), 'Completion committed/rolled back provider transaction.' );
	wpai199n_assert( 'initial' === $observer->get_var( $observer->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $name ) ), 'Completion implicitly committed native provider target bytes.' );
	$wpdb->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	wpai199nws_forget_rolled_back_options( $name );
	$left_status = wpai199nw_receipt( wpai199nw_status( $left_tx['operation_id'] ) );
	wpai199n_assert( 'unknown' === $left_status['receipt']['outcome'], 'Provider transaction was classified as applied/not applied.' );
	wpai199nw_call( $left_tx );
	wpai199n_assert( 1 === $callback_count && 'initial' === get_option( $name ), 'Provider-left same-ID retry resent native callback.' );
	remove_filter( 'pre_update_option_' . $name, $callback ); $callback = null;
	wpai199nws_database_drift( $name );
	foreach ( array( 'before', 'after', 'restore' ) as $mode ) {
		$request = wpai199nw_request( 'update', $name, 'protocol must not dispatch', 'site', 'native-protocol-' . $mode . '-' . $suffix );
		list( $failure, $checkpoint ) = wpai199nws_transport( $request, $actor, $directory, $mode );
		wpai199n_assert( isset( $failure['error'] ) && 1 === $checkpoint['commit_seen'] && true === $checkpoint['reply_dropped'] && ! is_file( $directory . '/calls' ), 'Journal protocol loss dispatched a setter or retried COMMIT.' );
		wpai199n_assert( 'initial' === get_option( $name ), 'Failed journal admission affected target.' );
		if ( 'before' !== $mode ) {
			wpai199n_assert( true === $checkpoint['server_commit_ok'] && 1 === $checkpoint['commit_forwarded'], 'Committed native fence was not observed.' );
			if ( 'restore' === $mode ) { wpai199n_assert( 1 === $checkpoint['restore_seen'] && true === $checkpoint['server_restore_ok'], 'Restoration reply loss was not exercised.' ); }
			$pending = wpai199nw_receipt( wpai199nw_status( $request['operation_id'] ) );
			wpai199n_assert( 'unknown' === $pending['receipt']['outcome'], 'Committed fence ambiguity was labeled not attempted.' );
			wpai199nw_call( $request );
			wpai199n_assert( ! is_file( $directory . '/calls' ) && 'initial' === get_option( $name ), 'Committed fence retry reached native setter.' );
		}
	}
	$race = wpai199nw_request( 'update', $name, 'one independent native dispatch', 'site', 'native-race-' . $suffix );
	foreach ( array( 'one', 'two' ) as $slot ) {
		$env = wpai199nws_env( $race, $actor, $directory ); $env['WPAI199NW_ROLE'] = 'race'; $env['WPAI199NW_SLOT'] = $slot;
		$code = 'require getenv("WPAI199NW_WPLOAD"); wp_set_current_user((int)getenv("WPAI199NW_ACTOR")); require getenv("WPAI199NW_WORKER");';
		$children[] = wpai199nws_process( array( getenv( 'WPAI199W_PHP' ) ?: PHP_BINARY, '-r', $code ), $env );
	}
	$deadline = microtime( true ) + 15;
	while ( ( ! is_file( $directory . '/one.ready' ) || ! is_file( $directory . '/two.ready' ) ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	wpai199n_assert( is_file( $directory . '/one.ready' ) && is_file( $directory . '/two.ready' ), 'Two requests did not reach common start.' );
	file_put_contents( $directory . '/go', '1' );
	$deadline = microtime( true ) + 20;
	do {
		$done = (int) is_file( $directory . '/one.done' ) + (int) is_file( $directory . '/two.done' );
		if ( is_file( $directory . '/entered' ) && 1 === $done ) { break; }
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );
	wpai199n_assert( is_file( $directory . '/entered' ) && 1 === $done && array( 'called' ) === file( $directory . '/calls', FILE_IGNORE_NEW_LINES ), 'Same-ID loser did not finish while exactly one winning setter was held.' );
	file_put_contents( $directory . '/release', '1' );
	foreach ( $children as &$child ) { wpai199nws_wait( $child ); } unset( $child );
	wp_cache_delete( $name, 'options' );
	wpai199n_assert( 'one independent native dispatch' === get_option( $name ) && array( 'called' ) === file( $directory . '/calls', FILE_IGNORE_NEW_LINES ), 'Concurrent same-ID requests dispatched more than once.' );
	$final = wpai199nw_receipt( wpai199nw_status( $race['operation_id'] ) );
	wpai199n_assert( 'native_call_returned' === $final['receipt']['outcome'] && true === $final['receipt']['native_return'], 'Final journal lost native callback evidence.' );
	echo 'PASS: native journal sessions, real transport loss and independent same-ID dispatch (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
} finally {
	if ( null !== $provider_tx ) { remove_filter( 'pre_update_option_' . $name, $provider_tx, 20 ); }
	if ( null !== $callback ) { remove_filter( 'pre_update_option_' . $name, $callback ); }
	$wpdb->query( 'UNLOCK TABLES' );
	$wpdb->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	wpai199nws_forget_rolled_back_options( $name );
	$wpdb->query( 'SET autocommit=1' );
	if ( is_dir( $directory ) ) { file_put_contents( $directory . '/release', '1' ); }
	foreach ( $children as $child ) { wpai199nws_close( $child ); }
	if ( $observer instanceof wpdb ) { $observer->close(); }
	if ( $created ) { $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) ); }
	delete_option( $name ); wpai199nw_cleanup_journals( $journals );
	wpai199n_restore_option( Settings::OPTION_NAME, $ordinary, null !== $ordinary ); wpai199n_restore_option( Mutation_Log::OPTION_NAME, $log, null !== $log );
	if ( is_dir( $directory ) ) {
		foreach ( new FilesystemIterator( $directory, FilesystemIterator::SKIP_DOTS ) as $entry ) { if ( $entry->isFile() && ! $entry->isLink() ) { unlink( $entry->getPathname() ); } }
		rmdir( $directory );
	}
}
