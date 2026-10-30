<?php
/**
 * Genuine independent-session receipt arbitration and stale-row races.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-database-write-helper.php';

function wpai199w_race( array $requests, $table, $actor ) {
	global $wpdb;
	wpai199w_assert( function_exists( 'proc_open' ), 'Native concurrency evidence requires proc_open.' );
	$directory = WP_CONTENT_DIR . '/wpai199w-race-' . bin2hex( random_bytes( 6 ) );
	wpai199w_assert( mkdir( $directory, 0700 ), 'Cannot create race barrier directory.' );
	$php = getenv( 'WPAI199W_PHP' ) ?: PHP_BINARY;
	$children = array();
	$locked = false;
	$observed_statements = 0;
	$overlap_proof = 'none';
	try {
		// Hold the actual target row so both children demonstrably overlap.
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=1 FOR UPDATE', $table ) );
		$locked = true;
		foreach ( array( 'one', 'two' ) as $index => $slot ) {
			$environment = getenv();
			$environment['WPAI199W_WPLOAD'] = ABSPATH . 'wp-load.php';
			$environment['WPAI199W_WORKER'] = __DIR__ . '/issue199-database-write-concurrency-worker.php';
			$environment['WPAI199W_ACTOR'] = (string) $actor;
			$environment['WPAI199W_RACE_DIRECTORY'] = $directory;
			$environment['WPAI199W_RACE_SLOT'] = $slot;
			$environment['WPAI199W_RACE_REQUEST'] = base64_encode( wp_json_encode( $requests[ $index ] ) );
			$code = 'require getenv("WPAI199W_WPLOAD"); wp_set_current_user((int)getenv("WPAI199W_ACTOR")); require getenv("WPAI199W_WORKER");';
			$pipes = array();
			$process = proc_open( array( $php, '-r', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, ABSPATH, $environment );
			wpai199w_assert( is_resource( $process ), 'Cannot start independent WordPress race process.' );
			fclose( $pipes[0] );
			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );
			$children[] = array( 'process' => $process, 'out' => $pipes[1], 'err' => $pipes[2], 'stdout' => '', 'stderr' => '', 'exit' => null );
		}
		// Exact native statements remain blocked behind our held target row.
		// Inspect the two connections directly; an INNODB_TRX snapshot taken
		// inside the observing transaction can omit newly arriving waiters.
		$deadline = microtime( true ) + 20;
		do {
			$one = is_file( $directory . '/one.ready' ) ? (int) file_get_contents( $directory . '/one.ready' ) : 0;
			$two = is_file( $directory . '/two.ready' ) ? (int) file_get_contents( $directory . '/two.ready' ) : 0;
			$observed_statements = 0;
			// Read exact native statements for the two known connections.
			$native_processes = $wpdb->get_results( $wpdb->prepare( 'SELECT ID,COMMAND,INFO FROM information_schema.PROCESSLIST WHERE ID IN (%d,%d)', $one, $two ), ARRAY_A );
			$observer_flags = array();
			foreach ( $native_processes as $native_process ) {
				$info = (string) ( $native_process['INFO'] ?? '' );
				if ( ! in_array( (int) $native_process['ID'], array( $one, $two ), true ) ) {
					continue;
				}
				$observer_flags[] = array( 'id' => $native_process['ID'], 'command' => $native_process['COMMAND'], 'bytes' => strlen( $info ), 'target' => false !== strpos( $info, $table ), 'options' => false !== strpos( $info, $wpdb->options ), 'for_update' => false !== stripos( $info, 'FOR UPDATE' ), 'insert' => 0 === stripos( $info, 'INSERT INTO' ) );
				if ( 'EXECUTE' === strtoupper( $native_process['COMMAND'] ) && (
					( false !== strpos( $info, $wpdb->options ) && (
						( 0 === stripos( $info, 'INSERT INTO' ) && false !== strpos( $info, 'option_name' ) ) ||
						( false !== strpos( $info, 'option_value' ) && false !== stripos( $info, 'FOR UPDATE' ) )
					) ) ||
					( false !== strpos( $info, $table ) && false !== stripos( $info, 'FOR UPDATE' ) )
				) ) {
					++$observed_statements;
				}
			}
			$native_waits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT' AND trx_mysql_thread_id IN (%d,%d)", $one, $two ) );
			$overlap_proof = $native_waits >= 2 ? 'native_innodb_lock_waits' : ( $observed_statements >= 2 ? 'native_named_statements' : 'none' );
			$observed_statements = max( $observed_statements, $native_waits );
			if ( $observed_statements >= 2 ) {
				break;
			}
			foreach ( $children as &$child ) {
				$child['stdout'] .= stream_get_contents( $child['out'] );
				$child['stderr'] .= stream_get_contents( $child['err'] );
			}
			unset( $child );
			usleep( 20000 );
		} while ( microtime( true ) < $deadline );
		$last_tx = $wpdb->get_results( 'SELECT trx_state,trx_mysql_thread_id FROM information_schema.INNODB_TRX', ARRAY_A );
		$last_states = $wpdb->get_results( "SELECT ID,COMMAND,STATE, CASE WHEN INFO LIKE '%information_schema.COLUMNS%' THEN 'columns' WHEN INFO LIKE '%information_schema.TABLES%' THEN 'tables' WHEN INFO LIKE '%SHOW CREATE TABLE%' THEN 'ddl' WHEN INFO LIKE '%information_schema.STATISTICS%' THEN 'indexes' WHEN INFO LIKE '%information_schema.TRIGGERS%' THEN 'triggers' WHEN INFO LIKE '%option_value%' THEN 'receipt' ELSE 'other' END AS phase FROM information_schema.PROCESSLIST WHERE DB=DATABASE()", ARRAY_A );
		$last_error = $wpdb->last_error;
		$wpdb->query( 'COMMIT AND NO CHAIN NO RELEASE' );
		$locked = false;
		foreach ( $children as &$child ) {
			$child['stdout'] .= stream_get_contents( $child['out'] );
			$child['stderr'] .= stream_get_contents( $child['err'] );
		}
		unset( $child );
		wpai199w_assert( $observed_statements >= 2, 'Two exact native receipt/target statements did not overlap behind the held row lock. ids=' . $one . ',' . $two . '; states=' . wp_json_encode( $last_states ) . '; tx=' . wp_json_encode( $last_tx ) . '; observer_error=' . $last_error . '; observer_flags=' . wp_json_encode( $observer_flags ) . '; children=' . wp_json_encode( array_map( static function ( $child ) { return array( 'stdout' => substr( $child['stdout'], 0, 1000 ), 'stderr' => substr( $child['stderr'], 0, 1000 ) ); }, $children ) ) );
		$deadline = microtime( true ) + 25;
		do {
			$running = false;
			foreach ( $children as &$child ) {
				$child['stdout'] .= stream_get_contents( $child['out'] );
				$child['stderr'] .= stream_get_contents( $child['err'] );
				$status = proc_get_status( $child['process'] );
				if ( $status['running'] ) {
					$running = true;
				} elseif ( null === $child['exit'] ) {
					$child['exit'] = $status['exitcode'];
				}
			}
			unset( $child );
			if ( $running ) {
				usleep( 20000 );
			}
		} while ( $running && microtime( true ) < $deadline );
		wpai199w_assert( ! $running, 'Native receipt race exceeded its bounded deadline.' );
		$results = array();
		foreach ( $children as &$child ) {
			$child['stdout'] .= stream_get_contents( $child['out'] );
			$child['stderr'] .= stream_get_contents( $child['err'] );
			wpai199w_assert( 0 === $child['exit'], 'Independent native race failed: ' . substr( $child['stderr'], 0, 1000 ) );
			wpai199w_assert( 1 === preg_match( '/WPAI199W_RACE_RESULT=(\{[^\r\n]+\})/', $child['stdout'], $match ), 'Race worker omitted its bounded result.' );
			$results[] = json_decode( $match[1], true );
		}
		unset( $child );
		echo 'WPAI199W_CONCURRENCY_PROOF=' . $overlap_proof . "\n";
		return $results;
	} finally {
		if ( $locked ) {
			$wpdb->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
		}
		foreach ( $children as $child ) {
			$status = proc_get_status( $child['process'] );
			if ( $status['running'] ) {
				proc_terminate( $child['process'] );
			}
			fclose( $child['out'] );
			fclose( $child['err'] );
			proc_close( $child['process'] );
		}
		foreach ( array( 'one.ready', 'two.ready' ) as $name ) {
			if ( is_file( $directory . '/' . $name ) ) {
				unlink( $directory . '/' . $name );
			}
		}
		rmdir( $directory );
	}
}

global $wpdb;
$actor = get_current_user_id();
$database = get_option( Settings::DATABASE_OPTION_NAME, false );
$log = get_option( Mutation_Log::OPTION_NAME, false );
$receipts = wpai199w_receipts_before();
$table = $wpdb->prefix . 'wpai199wr_' . bin2hex( random_bytes( 5 ) );
$created = false;

try {
	wpai199w_assert( ! is_multisite() && current_user_can( 'manage_options' ), 'Concurrency fixture requires its disposable administrator.' );
	$settings = new Settings();
	$settings->register();
	update_option( Settings::DATABASE_OPTION_NAME, wpai199w_grants(), false );
	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, payload LONGBLOB NULL) ENGINE=InnoDB', $table ) ), 'Cannot create native race target.' );
	$created = true;
	$wpdb->insert( $table, array( 'id' => 1, 'payload' => 'initial' ) );
	$schema = wpai199w_describe( $table )['schema_hash'];

	$step = wpai199w_step( 'update', $table, $schema, array( 'payload' => base64_encode( 'same request committed once' ) ), '1' );
	$request = array( 'action' => 'execute', 'operation_id' => 'write-race-same-' . bin2hex( random_bytes( 5 ) ), 'operations' => array( $step ) );
	$results = wpai199w_race( array( $request, $request ), $table, $actor );
	wpai199w_assert( 'committed' === $results[0]['status'] && 'committed' === $results[1]['status'] && $results[0]['operation_ref'] === $results[1]['operation_ref'], 'Same-ID waiters did not converge on one committed receipt.' );
	wpai199w_assert( $results[0]['historical'] !== $results[1]['historical'], 'Same-ID contenders both claimed the native effect.' );
	wpai199w_assert( 'same request committed once' === wpai199w_bytes( $table, 'payload', '1' ), 'Same-ID arbitration changed exact intended bytes.' );

	$step = wpai199w_step( 'update', $table, $schema, array( 'payload' => base64_encode( 'conflict one' ) ), '1' );
	$first = array( 'action' => 'execute', 'operation_id' => 'write-race-conflict-' . bin2hex( random_bytes( 5 ) ), 'operations' => array( $step ) );
	$second = $first;
	$second['operations'][0]['values']['payload'] = base64_encode( 'conflict two' );
	$results = wpai199w_race( array( $first, $second ), $table, $actor );
	$errors = array_values( array_filter( array_column( $results, 'error' ) ) );
	$committed = array_values( array_filter( array_column( $results, 'status' ) ) );
	wpai199w_assert( array( 'database_write_operation_conflict' ) === $errors && array( 'committed' ) === $committed, 'Different same-ID requests were not uniquely arbitrated.' );
	wpai199w_assert( in_array( wpai199w_bytes( $table, 'payload', '1' ), array( 'conflict one', 'conflict two' ), true ), 'Conflicting operation produced unknown bytes.' );

	$step = wpai199w_step( 'update', $table, $schema, array( 'payload' => base64_encode( 'stale one' ) ), '1' );
	$first = array( 'action' => 'execute', 'operation_id' => 'write-race-stale-one-' . bin2hex( random_bytes( 5 ) ), 'operations' => array( $step ) );
	$second = $first;
	$second['operation_id'] = 'write-race-stale-two-' . bin2hex( random_bytes( 5 ) );
	$second['operations'][0]['values']['payload'] = base64_encode( 'stale two' );
	$results = wpai199w_race( array( $first, $second ), $table, $actor );
	$errors = array_values( array_filter( array_column( $results, 'error' ) ) );
	$committed = array_values( array_filter( array_column( $results, 'status' ) ) );
	wpai199w_assert( array( 'database_write_row_stale' ) === $errors && array( 'committed' ) === $committed, 'Independent stale preconditions both changed the same current row.' );
	echo "PASS: Issue #199 genuine overlapping native same-ID replay/conflict and full-row stale-write concurrency.\n";
} finally {
	if ( $created ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	}
	wpai199w_restore_receipts( $receipts );
	wpai199w_restore_option( Settings::DATABASE_OPTION_NAME, $database );
	wpai199w_restore_option( Mutation_Log::OPTION_NAME, $log );
}
