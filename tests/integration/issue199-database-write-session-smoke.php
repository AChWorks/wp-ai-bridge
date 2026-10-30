<?php
/**
 * Native session ownership, lock preservation and unsafe-effect boundaries.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Database_Connection;
use WP_AI_Bridge\Support\Database_Write_Failure;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-database-write-helper.php';

function wpai199w_lock_count( $table ) {
	global $wpdb;
	$rows = $wpdb->get_results( 'SHOW OPEN TABLES WHERE In_use > 0', ARRAY_A );
	foreach ( $rows as $row ) {
		if ( $table === $row['Table'] ) {
			return (int) $row['In_use'];
		}
	}
	return 0;
}

global $wpdb;
$actor = get_current_user_id();
$database = get_option( Settings::DATABASE_OPTION_NAME, false );
$log = get_option( Mutation_Log::OPTION_NAME, false );
$receipts = wpai199w_receipts_before();
$suffix = bin2hex( random_bytes( 5 ) );
$table = $wpdb->prefix . 'wpai199wc_' . $suffix;
$heap = $wpdb->prefix . 'wpai199wh_' . $suffix;
$diagnostic = $wpdb->prefix . 'wpai199wd_' . $suffix;
$myisam = $wpdb->prefix . 'wpai199wm_' . $suffix;
$expression = $wpdb->prefix . 'wpai199we_' . $suffix;
$generated = $wpdb->prefix . 'wpai199wg_' . $suffix;
$parent = $wpdb->prefix . 'wpai199wp_' . $suffix;
$child = $wpdb->prefix . 'wpai199wf_' . $suffix;
$outside = 'outside_wpai199w_' . $suffix;
$created = array();
$trigger = $wpdb->prefix . 'wpai199wt_' . $suffix;
$trigger_created = false;
$temporary = false;
$locked = false;
$sql_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
$completion = (string) $wpdb->get_var( 'SELECT @@SESSION.completion_type' );

try {
	wpai199w_assert( ! is_multisite() && current_user_can( 'manage_options' ), 'Session fixture requires disposable single-site administrator.' );
	$settings = new Settings();
	$settings->register();
	update_option( Settings::DATABASE_OPTION_NAME, wpai199w_grants(), false );
	foreach ( array(
		$table => '(id INT NOT NULL PRIMARY KEY, payload LONGBLOB NULL, amount DECIMAL(10,2) NOT NULL DEFAULT 0, short_value VARCHAR(4) NULL) ENGINE=InnoDB',
		$heap => '(payload LONGBLOB NULL) ENGINE=InnoDB',
		$diagnostic => '(id VARCHAR(64) NOT NULL PRIMARY KEY, payload LONGBLOB NULL) ENGINE=InnoDB',
		$myisam => '(id INT NOT NULL PRIMARY KEY, payload LONGBLOB NULL) ENGINE=MyISAM',
		$outside => '(id INT NOT NULL PRIMARY KEY, payload LONGBLOB NULL) ENGINE=InnoDB',
		$expression => '(id INT NOT NULL PRIMARY KEY, token VARCHAR(36) NOT NULL DEFAULT (uuid())) ENGINE=InnoDB',
		$generated => '(id INT NOT NULL PRIMARY KEY, derived INT GENERATED ALWAYS AS (id + 1) STORED) ENGINE=InnoDB',
		$parent => '(id INT NOT NULL PRIMARY KEY, tag INT NOT NULL UNIQUE, payload LONGBLOB NULL) ENGINE=InnoDB',
	) as $name => $definition ) {
		wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ' . $definition, $name ) ), 'Cannot create compatibility fixture.' );
		$created[] = $name;
	}
	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, parent_tag INT NOT NULL, CONSTRAINT %i FOREIGN KEY(parent_tag) REFERENCES %i(tag) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB', $child, 'fk_' . $suffix, $parent ) ), 'Cannot create native inbound cascade graph.' );
	$created[] = $child;
	$wpdb->insert( $table, array( 'id' => 1, 'payload' => 'original' ) );
	$wpdb->insert( $parent, array( 'id' => 1, 'tag' => 10, 'payload' => 'parent' ) );
	$wpdb->insert( $child, array( 'id' => 1, 'parent_tag' => 10 ) );
	$schema = wpai199w_describe( $table )['schema_hash'];
	$step = wpai199w_step( 'update', $table, $schema, array( 'payload' => base64_encode( 'owned' ) ), '1' );

	// Real SQL failure with ERROR but no STRICT emits a PHP warning even under
	// @. The caller handler must never see data or run inside owned work.
	$diagnostic_schema = wpai199w_describe( $diagnostic )['schema_hash'];
	$secret_key = 'private-diagnostic-' . $suffix;
	$wpdb->insert( $diagnostic, array( 'id' => $secret_key, 'payload' => 'retained' ) );
	$report_mode = ( new mysqli_driver() )->report_mode;
	$observed = array();
	$observer = static function ( $level, $message ) use ( &$observed ) {
		$observed[] = array( $level, $message );
		return true;
	};
	set_error_handler( $observer );
	try {
		mysqli_report( MYSQLI_REPORT_ERROR );
		$diagnostic_first = wpai199w_step( 'insert', $diagnostic, $diagnostic_schema, array( 'id' => base64_encode( 'rolled-back-' . $suffix ), 'payload' => base64_encode( 'must roll back' ) ) );
		$diagnostic_duplicate = wpai199w_step( 'insert', $diagnostic, $diagnostic_schema, array( 'id' => base64_encode( $secret_key ) ) );
		$diagnostic_id = 'write-diagnostic-failure-' . $suffix;
		$diagnostic_error = wpai199w_error( wpai199w_execute( $diagnostic_id, array( $diagnostic_first, $diagnostic_duplicate ) ), 'Diagnostic-mode duplicate batch succeeded.', array( 'database_write_query_failed' ) );
		wpai199w_assert( array() === $observed, 'Native SQL warning dispatched the caller application handler during owned work.' );
		wpai199w_assert( 1062 === $diagnostic_error->get_error_data()['errno'] && 'not_committed' === $diagnostic_error->get_error_data()['outcome'] && ! str_contains( wp_json_encode( $diagnostic_error ), $secret_key ), 'Native duplicate diagnostics lost their truthful redacted classification.' );
		wpai199w_assert( 0 === wpai199w_count( $diagnostic, 'rolled-back-' . $suffix ) && 'retained' === wpai199w_bytes( $diagnostic, 'payload', $secret_key ), 'Diagnostic-mode failure left partial target effects.' );
		wpai199w_assert( 'not_recorded' === wpai199w_ok( wpai199w_call( array( 'action' => 'recover', 'operation_id' => $diagnostic_id ) ), 'Diagnostic failure recovery failed.' )['status'], 'Diagnostic-mode failure committed a receipt.' );
		$diagnostic_success = wpai199w_step( 'insert', $diagnostic, $diagnostic_schema, array( 'id' => base64_encode( 'success-' . $suffix ) ) );
		wpai199w_ok( wpai199w_execute( 'write-diagnostic-success-' . $suffix, array( $diagnostic_success ) ), 'Legitimate non-STRICT mysqli mode was unnecessarily rejected.' );
		wpai199w_assert( array() === $observed && MYSQLI_REPORT_ERROR === ( new mysqli_driver() )->report_mode, 'Native work changed caller mysqli reporting or dispatched its handler.' );
		// Exercise prepare, unparameterized query and index-report diagnostics on
		// the retained native connection without any owning WordPress callback.
		$native = new Database_Connection();
		$native->begin();
		try {
			foreach ( array( MYSQLI_REPORT_ERROR, MYSQLI_REPORT_ERROR | MYSQLI_REPORT_INDEX, MYSQLI_REPORT_ALL ) as $native_mode ) {
				mysqli_report( $native_mode );
				foreach ( array( array( 'SELECT ? FROM', array( $secret_key ) ), array( 'SELECT FROM', array() ) ) as $invalid_sql ) {
					$failed = false;
					try {
						$native->run( $invalid_sql[0], $invalid_sql[1] );
					} catch ( Database_Write_Failure $failure ) {
						$failed = 'database_write_query_failed' === $failure->reason && 1064 === ( $failure->details['errno'] ?? 0 );
					}
					wpai199w_assert( $failed && array() === $observed && $native_mode === ( new mysqli_driver() )->report_mode, 'Native query/prepare failure escaped diagnostic isolation or changed report mode.' );
				}
				try {
					$native->run( 'SELECT COUNT(*) AS rows_count FROM ' . $native->table( $diagnostic ) . ' WHERE payload IS NOT NULL' );
				} catch ( Database_Write_Failure $failure ) {
					// STRICT intentionally converts the driver's no-index diagnostic
					// to an exception with errno0; preserving that mode is deliberate.
					wpai199w_assert( 0 !== ( $native_mode & MYSQLI_REPORT_STRICT ) && 'database_write_query_failed' === $failure->reason && 0 === ( $failure->details['errno'] ?? -1 ), 'Native index diagnostic had an unexpected outcome.' );
				}
				$native->run( 'SELECT 1 AS connection_usable' );
				wpai199w_assert( array() === $observed && $native_mode === ( new mysqli_driver() )->report_mode, 'Native index reporting dispatched the application handler or changed report mode.' );
			}
		} finally {
			wpai199w_assert( $native->rollback(), 'Native diagnostic fixture could not restore its owned session.' );
		}
		trigger_error( 'wpai199w-caller-handler-restored', E_USER_WARNING );
		wpai199w_assert( array( array( E_USER_WARNING, 'wpai199w-caller-handler-restored' ) ) === $observed, 'Original caller handler/mask was not restored after native success/failure cleanup.' );
	} finally {
		mysqli_report( $report_mode );
		restore_error_handler();
	}
	wpai199w_assert( $report_mode === ( new mysqli_driver() )->report_mode, 'Original mysqli report mode was not restored by diagnostic fixture.' );


	wpai199w_assert( false !== $wpdb->query( 'START TRANSACTION' ), 'Cannot start ambient native transaction.' );
	$wpdb->insert( $table, array( 'id' => 99, 'payload' => 'ambient uncommitted' ) );
	wpai199w_error( wpai199w_execute( 'write-ambient-' . $suffix, array( $step ) ), 'Writer entered/committed an ambient transaction.', array( 'database_write_unsupported_session' ) );
	wpai199w_assert( 1 === wpai199w_count( $table, 99 ), 'Writer rolled back caller-owned ambient work.' );
	$wpdb->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	wpai199w_assert( 0 === wpai199w_count( $table, 99 ) && 'original' === wpai199w_bytes( $table, 'payload', 1 ), 'Ambient work was implicitly committed by rejection.' );
	$wpdb->query( 'SET autocommit=0' );
	wpai199w_error( wpai199w_execute( 'write-autocommit0-' . $suffix, array( $step ) ), 'Writer accepted a caller-owned autocommit0 session.', array( 'database_write_unsupported_session' ) );
	wpai199w_assert( '0' === (string) $wpdb->get_var( 'SELECT @@autocommit' ), 'Writer changed caller autocommit mode while rejecting.' );
	$wpdb->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	$wpdb->query( 'SET autocommit=1' );

	foreach ( array( 1, 2 ) as $mode ) {
		$wpdb->query( 'SET SESSION completion_type=' . $mode );
		$connection_id = (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$insert = wpai199w_step( 'insert', $table, $schema, array( 'id' => base64_encode( (string) ( 10 + $mode ) ), 'payload' => base64_encode( 'completion control' ) ) );
		wpai199w_ok( wpai199w_execute( 'write-completion-' . $mode . '-' . $suffix, array( $insert ) ), 'Native completion mode escaped explicit NO CHAIN NO RELEASE.' );
		wpai199w_assert( $connection_id === (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) && '1' === (string) $wpdb->get_var( 'SELECT @@autocommit' ), 'Committed transaction chained/released its retained physical session.' );
		wpai199w_error( wpai199w_execute( 'write-completion-fail-' . $mode . '-' . $suffix, array( $insert ) ), 'Duplicate later operation succeeded.' );
		wpai199w_assert( $connection_id === (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) && '1' === (string) $wpdb->get_var( 'SELECT @@autocommit' ), 'Rollback chained/released its retained physical session.' );
	}
	$wpdb->query( $wpdb->prepare( 'SET SESSION completion_type=%s', $completion ) );

	// Lock both the target and durable receipt table exactly as a native caller.
	// START TRANSACTION would silently lose these SQL-layer locks.
	$wpdb->query( $wpdb->prepare( 'LOCK TABLES %i WRITE, %i WRITE', $table, $wpdb->options ) );
	$locked = true;
	wpai199w_assert( wpai199w_lock_count( $table ) > 0, 'Explicit native table lock fixture was not established.' );
	$lock_insert = wpai199w_step( 'insert', $table, $schema, array( 'id' => base64_encode( '20' ), 'payload' => base64_encode( 'locked success' ) ) );
	wpai199w_ok( wpai199w_execute( 'write-lock-success-' . $suffix, array( $lock_insert ) ), 'Supported explicit LOCK TABLES session could not execute.' );
	wpai199w_assert( wpai199w_lock_count( $table ) > 0 && 'locked success' === wpai199w_bytes( $table, 'payload', '20' ), 'Successful write released caller table locks.' );
	$stale = $step;
	$stale['expected_state_hash'] = str_repeat( '0', 64 );
	wpai199w_error( wpai199w_execute( 'write-lock-denied-' . $suffix, array( $stale ) ), 'Stale explicit-lock write succeeded.' );
	wpai199w_assert( wpai199w_lock_count( $table ) > 0 && 'original' === wpai199w_bytes( $table, 'payload', '1' ), 'Denied write released caller table locks or changed bytes.' );
	$lock_first = wpai199w_step( 'insert', $table, $schema, array( 'id' => base64_encode( '21' ), 'payload' => base64_encode( 'must roll back' ) ) );
	wpai199w_error( wpai199w_execute( 'write-lock-failure-' . $suffix, array( $lock_first, $lock_insert ) ), 'Later SQL failure under explicit table locks succeeded.' );
	wpai199w_assert( wpai199w_lock_count( $table ) > 0 && 0 === wpai199w_count( $table, '21' ) && '1' === (string) $wpdb->get_var( 'SELECT @@autocommit' ), 'SQL failure lost table locks, partial effects or session cleanup.' );
	$wpdb->query( 'UNLOCK TABLES' );
	$locked = false;

	foreach ( array( $heap, $myisam, $outside, $expression, $generated ) as $unsafe ) {
		wpai199w_error( wpai199w_call( array( 'action' => 'describe', 'table' => $unsafe ) ), 'Unsafe effect/schema/table was described as compatible.' );
	}
	$wpdb->query( $wpdb->prepare( 'CREATE TRIGGER %i BEFORE INSERT ON %i FOR EACH ROW SET NEW.payload = \'trigger effect\'', $trigger, $table ) );
	wpai199w_assert( '' === $wpdb->last_error, 'Cannot create actual trigger visibility fixture.' );
	$trigger_created = true;
	wpai199w_error( wpai199w_call( array( 'action' => 'describe', 'table' => $table ) ), 'A trigger-bearing table was described as safe.' );
	wpai199w_assert( 0 === wpai199w_count( $table, '30' ), 'Trigger rejection had target effects.' );
	$wpdb->query( $wpdb->prepare( 'DROP TRIGGER %i', $trigger ) );
	$trigger_created = false;

	$parent_schema = wpai199w_describe( $parent )['schema_hash'];
	wpai199w_error( wpai199w_execute( 'write-cascade-delete-' . $suffix, array( wpai199w_step( 'delete', $parent, $parent_schema, array(), '1' ) ) ), 'Mutating native inbound DELETE cascade was accepted.' );
	wpai199w_error( wpai199w_execute( 'write-cascade-update-' . $suffix, array( wpai199w_step( 'update', $parent, $parent_schema, array( 'tag' => base64_encode( '11' ) ), '1' ) ) ), 'Mutating native indexed-column UPDATE cascade was accepted.' );
	wpai199w_assert( 1 === wpai199w_count( $child, '1' ) && '10' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT parent_tag FROM %i WHERE id=1', $child ) ), 'Denied cascade altered the referencing row.' );
	wpai199w_ok( wpai199w_execute( 'write-nonindex-' . $suffix, array( wpai199w_step( 'update', $parent, $parent_schema, array( 'payload' => base64_encode( 'nonindexed allowed' ) ), '1' ) ) ), 'Nonindexed column update was unnecessarily blocked by an unrelated cascade graph.' );

	$wpdb->query( "SET SESSION sql_mode=''" );
	$warning = wpai199w_step( 'update', $table, $schema, array( 'short_value' => base64_encode( 'too-long' ) ), '1' );
	wpai199w_error( wpai199w_execute( 'write-warning-' . $suffix, array( $warning ) ), 'Native truncation warning was committed.' );
	wpai199w_assert( null === wpai199w_bytes( $table, 'short_value', '1' ), 'Warning rollback did not restore NULL.' );
	$normalized = wpai199w_step( 'update', $table, $schema, array( 'amount' => base64_encode( '1.234' ) ), '1' );
	wpai199w_error( wpai199w_execute( 'write-normalization-' . $suffix, array( $normalized ) ), 'Silent DECIMAL normalization was committed.' );
	wpai199w_assert( '0.00' === wpai199w_bytes( $table, 'amount', '1' ), 'Normalization mismatch left changed bytes.' );
	$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode=%s', $sql_mode ) );

	$wpdb->query( $wpdb->prepare( 'CREATE TEMPORARY TABLE %i (id INT NOT NULL PRIMARY KEY, payload LONGBLOB NULL) ENGINE=InnoDB', $table ) );
	wpai199w_assert( '' === $wpdb->last_error, 'Cannot create persistent-name temporary shadow.' );
	$temporary = true;
	wpai199w_error( wpai199w_call( array( 'action' => 'describe', 'table' => $table ) ), 'A temporary shadow was accepted as the pinned persistent target.' );
	$wpdb->query( $wpdb->prepare( 'DROP TEMPORARY TABLE %i', $table ) );
	$temporary = false;
	wpai199w_assert( 'original' === wpai199w_bytes( $table, 'payload', '1' ), 'Temporary shadow rejection altered persistent row.' );
	echo 'PASS: Issue #199 native session/LOCK TABLES preservation, unsafe schema/effects and normalization (' . $GLOBALS['wpai199w_checks'] . " checks).\n";
} finally {
	if ( $locked ) {
		$wpdb->query( 'UNLOCK TABLES' );
	}
	$wpdb->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	$wpdb->query( 'SET autocommit=1' );
	$wpdb->query( $wpdb->prepare( 'SET SESSION completion_type=%s', $completion ) );
	$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode=%s', $sql_mode ) );
	if ( $temporary ) {
		$wpdb->query( $wpdb->prepare( 'DROP TEMPORARY TABLE %i', $table ) );
	}
	if ( $trigger_created ) {
		$wpdb->query( $wpdb->prepare( 'DROP TRIGGER %i', $trigger ) );
	}
	foreach ( array_reverse( $created ) as $fixture ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $fixture ) );
	}
	wpai199w_restore_receipts( $receipts );
	wpai199w_restore_option( Settings::DATABASE_OPTION_NAME, $database );
	wpai199w_restore_option( Mutation_Log::OPTION_NAME, $log );
	wp_set_current_user( $actor );
}
