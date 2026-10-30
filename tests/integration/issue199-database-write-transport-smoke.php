<?php
/**
 * Genuine MySQL protocol loss immediately before/after server-acknowledged COMMIT.
 *
 * No product failure hooks or connection substitutes. The child WordPress Core
 * opens its normal mysqli handle through a task-private Unix packet relay.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-database-write-helper.php';

function wpai199w_transport_process( array $command, array $environment ) {
	$pipes = array();
	$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, ABSPATH, $environment );
	wpai199w_assert( is_resource( $process ), 'Cannot start private transport subprocess.' );
	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
	return array( 'process' => $process, 'out' => $pipes[1], 'err' => $pipes[2], 'stdout' => '', 'stderr' => '', 'exit' => null );
}
function wpai199w_transport_wait( array &$process ) {
	$deadline = microtime( true ) + 45;
	do {
		$process['stdout'] .= stream_get_contents( $process['out'] );
		$process['stderr'] .= stream_get_contents( $process['err'] );
		$status = proc_get_status( $process['process'] );
		if ( ! $status['running'] ) {
			$process['exit'] = $status['exitcode'];
			break;
		}
		usleep( 20000 );
	} while ( microtime( true ) < $deadline );
	wpai199w_assert( null !== $process['exit'], 'Private transport subprocess exceeded its deadline.' );
	$process['stdout'] .= stream_get_contents( $process['out'] );
	$process['stderr'] .= stream_get_contents( $process['err'] );
	wpai199w_assert( 0 === $process['exit'], 'Private transport subprocess failed: ' . substr( $process['stderr'], 0, 800 ) );
}
function wpai199w_transport_close( array $process ) {
	$status = proc_get_status( $process['process'] );
	if ( $status['running'] ) {
		proc_terminate( $process['process'] );
	}
	fclose( $process['out'] );
	fclose( $process['err'] );
	proc_close( $process['process'] );
}
function wpai199w_transport( array $request, $actor, $mode ) {
	global $wpdb;
	$php = getenv( 'WPAI199W_PHP' ) ?: PHP_BINARY;
	// Obtain only a non-secret host/socket locator. Auth remains in wp-config.
	$host = DB_HOST;
	if ( str_starts_with( $host, 'localhost:' ) && str_starts_with( substr( $host, 10 ), '/' ) ) {
		$upstream = 'unix://' . substr( $host, 10 );
	} elseif ( 'localhost' === $host ) {
		$upstream = 'unix://' . (string) $wpdb->get_var( 'SELECT @@socket' );
	} else {
		$upstream = 'tcp://' . $host . ( str_contains( $host, ':' ) ? '' : ':3306' );
	}
	$socket = rtrim( sys_get_temp_dir(), '/' ) . '/wpai199w-proxy-' . bin2hex( random_bytes( 6 ) ) . '.sock';
	$environment = getenv();
	$environment['WPAI199W_PROXY_SOCKET'] = $socket;
	$environment['WPAI199W_PROXY_UPSTREAM'] = $upstream;
	$environment['WPAI199W_PROXY_MODE'] = $mode;
	$environment['WPAI199W_TRANSPORT_REQUEST'] = base64_encode( wp_json_encode( $request ) );
	$environment['WPAI199W_WPLOAD'] = ABSPATH . 'wp-load.php';
	$environment['WPAI199W_WORKER'] = __DIR__ . '/issue199-database-write-transport-worker.php';
	$environment['WPAI199W_ACTOR'] = (string) $actor;
	$proxy = wpai199w_transport_process( array( $php, __DIR__ . '/issue199-database-write-transport-proxy.php' ), $environment );
	$client = null;
	try {
		$deadline = microtime( true ) + 10;
		while ( ! file_exists( $socket ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		wpai199w_assert( file_exists( $socket ), 'Private database relay socket did not initialize.' );
		// Core still creates and owns the handle. Suppress only wp-config's
		// duplicate constant diagnostic, without changing credentials/config.
		$code = 'define("DB_HOST","localhost:".getenv("WPAI199W_PROXY_SOCKET")); set_error_handler(static function($level,$message){return E_WARNING===$level && str_contains($message,"Constant DB_HOST already defined");}); require getenv("WPAI199W_WPLOAD"); restore_error_handler(); wp_set_current_user((int)getenv("WPAI199W_ACTOR")); require getenv("WPAI199W_WORKER");';
		$client = wpai199w_transport_process( array( $php, '-r', $code ), $environment );
		wpai199w_transport_wait( $client );
		wpai199w_transport_wait( $proxy );
		wpai199w_assert( 1 === preg_match( '/WPAI199W_TRANSPORT_RESULT=(\{[^\r\n]+\})/', $client['stdout'], $client_match ), 'Transport client omitted its bounded outcome.' );
		wpai199w_assert( 1 === preg_match( '/WPAI199W_PROXY_RESULT=(\{[^\r\n]+\})/', $proxy['stdout'], $proxy_match ), 'Transport relay omitted its exact checkpoint evidence.' );
		return array( json_decode( $client_match[1], true ), json_decode( $proxy_match[1], true ) );
	} finally {
		if ( null !== $client ) {
			wpai199w_transport_close( $client );
		}
		wpai199w_transport_close( $proxy );
		if ( file_exists( $socket ) ) {
			unlink( $socket );
		}
	}
}

global $wpdb;
$actor = get_current_user_id();
$database = get_option( Settings::DATABASE_OPTION_NAME, false );
$log = get_option( Mutation_Log::OPTION_NAME, false );
$receipts = wpai199w_receipts_before();
$table = $wpdb->prefix . 'wpai199wx_' . bin2hex( random_bytes( 5 ) );
$created = false;

try {
	wpai199w_assert( ! is_multisite() && current_user_can( 'manage_options' ) && function_exists( 'proc_open' ), 'Transport fixture requires disposable administrator and proc_open.' );
	$settings = new Settings();
	$settings->register();
	update_option( Settings::DATABASE_OPTION_NAME, wpai199w_grants(), false );
	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, payload LONGBLOB NULL) ENGINE=InnoDB', $table ) ), 'Cannot create native transport target.' );
	$created = true;
	$wpdb->insert( $table, array( 'id' => 1, 'payload' => 'initial' ) );
	$schema = wpai199w_describe( $table )['schema_hash'];
	foreach ( array( 'before', 'after' ) as $mode ) {
		$expected = 'transport committed ' . $mode;
		$request = array( 'action' => 'execute', 'operation_id' => 'write-transport-' . $mode . '-' . bin2hex( random_bytes( 5 ) ), 'operations' => array( wpai199w_step( 'update', $table, $schema, array( 'payload' => base64_encode( $expected ) ), '1' ) ) );
		$prior = wpai199w_bytes( $table, 'payload', '1' );
		list( $result, $checkpoint ) = wpai199w_transport( $request, $actor, $mode );
		wpai199w_assert( 'database_write_outcome_unknown' === ( $result['error'] ?? '' ), 'A lost COMMIT acknowledgement was reported as a retryable failure or known commit.' );
		wpai199w_assert( 1 === $checkpoint['commit_seen'] && $checkpoint['reply_dropped'], 'Transport invocation retried COMMIT or missed its checkpoint.' );
		$recovery = wpai199w_ok( wpai199w_call( array( 'action' => 'recover', 'operation_id' => $request['operation_id'] ) ), 'Independent recovery failed after transport loss.' );
		if ( 'before' === $mode ) {
			wpai199w_assert( 0 === $checkpoint['commit_forwarded'] && ! $checkpoint['server_commit_ok'] && 'not_recorded' === $recovery['status'] && $prior === wpai199w_bytes( $table, 'payload', '1' ), 'Dropped-before-COMMIT connection left target/receipt effects.' );
			// Relay has closed both physical sockets and recovery proves no
			// committed effect. Retry exactly the same ID AND request.
			wpai199w_ok( wpai199w_call( $request ), 'Identical retry after proven disconnected rollback failed.' );
		} else {
			wpai199w_assert( 1 === $checkpoint['commit_forwarded'] && $checkpoint['server_commit_ok'] && 'committed' === $recovery['status'] && true === $recovery['historical'], 'Acknowledged server COMMIT did not recover its atomic receipt.' );
			$historical = wpai199w_ok( wpai199w_call( $request ), 'Committed ambiguous invocation did not replay historically.' );
			wpai199w_assert( true === $historical['historical'], 'Ambiguous committed replay repeated native effects.' );
		}
		wpai199w_assert( $expected === wpai199w_bytes( $table, 'payload', '1' ), 'Recovered transport operation has incorrect exact bytes.' );
	}
	echo "PASS: Issue #199 actual protocol drops before/after COMMIT, no invocation retry, atomic receipt recovery and identical-request rollback retry.\n";
} finally {
	if ( $created ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	}
	wpai199w_restore_receipts( $receipts );
	wpai199w_restore_option( Settings::DATABASE_OPTION_NAME, $database );
	wpai199w_restore_option( Mutation_Log::OPTION_NAME, $log );
}
