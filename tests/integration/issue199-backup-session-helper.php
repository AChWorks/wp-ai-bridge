<?php
/** Actual native representation guard checks, before any quiescent full capture. */
use WP_AI_Bridge\Support\Backup_Database;
use WP_AI_Bridge\Support\Database_Connection;

function wpai199b_native_session_cases() {
	global $wpdb;
	$source = getenv( 'WPAI199C_EXPECT_DATABASE' );
	$target = getenv( 'WPAI_ADMIN13_RESTORE_SCHEMA' );
	wpai199c_assert( defined( 'WPAI_ADMIN13_DISPOSABLE' ) && true === WPAI_ADMIN13_DISPOSABLE &&
		current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() ) && '512M' === ini_get( 'memory_limit' ) &&
		$wpdb->dbh instanceof mysqli && is_string( $source ) && '' !== $source &&
		is_string( $target ) && $source !== $target && preg_match( '/^wpai[a-z0-9_]*restore[a-z0-9_]*$/D', $target ), 'Exact disposable native session guard scope required.' );
	$handle = $wpdb->dbh;
	$thread = mysqli_thread_id( $handle );
	$sample = static function () use ( $handle ) {
		$result = $handle->query( 'SELECT CURRENT_USER() AS account,DATABASE() AS db,@@SESSION.sql_mode AS sql_mode,@@SESSION.time_zone AS time_zone,@@SESSION.autocommit AS ac,@@SESSION.foreign_key_checks AS fk,@@SESSION.unique_checks AS uq' );
		try { return $result->fetch_assoc(); } finally { $result->free(); }
	};
	$baseline = $sample();
	wpai199c_assert( $baseline['db'] === $source && '1' === (string) $baseline['ac'] && '1' === (string) $baseline['fk'] &&
		'1' === (string) $baseline['uq'], 'Native session helper never adopts foreign transaction/flag state.' );
	$database = new Backup_Database( 1048576 );
	$connection = new Database_Connection(); // Retains the same existing Core handle; no credentials/connection creation.
	wpai199c_assert( true === $connection->assert_clean_session(), 'Positive native clean-session admission failed; no foreign work may be adopted.' );
	$planned = $database->identity()['session'];
	$planned['time_zone'] = '+00:00';
	$modes = array_filter( explode( ',', $planned['sql_mode'] ) );
	if ( ! in_array( 'NO_AUTO_VALUE_ON_ZERO', $modes, true ) ) { $modes[] = 'NO_AUTO_VALUE_ON_ZERO'; }
	sort( $modes, SORT_STRING ); $planned['sql_mode'] = implode( ',', $modes );
	$checks = 0;
	$check = static function ( $condition, $message ) use ( &$checks ) { ++$checks; wpai199c_assert( $condition, $message ); };
	$changed = static function ( callable $dependent ) use ( $check ) {
		$observed = false;
		try { $dependent(); } catch ( RuntimeException $error ) {
			$check( 'backup_native_session_changed' === $error->getMessage(), 'Unexpected native session guard error domain.' );
			$observed = true;
		}
		$check( $observed, 'Changed native session reached dependent work.' );
	};
	$assert_baseline = static function () use ( $sample, $baseline, $check, $handle, $thread, $connection ) {
		$check( $baseline === $sample() && $thread === mysqli_thread_id( $handle ) && true === $connection->assert_clean_session(), 'Native helper changed the original account/handle/database/session or transaction state.' );
	};
	$database->with_session( $planned, static function () use ( $database, $planned, $check ) {
		$check( true === $database->assert_session( $planned ), 'Actual native planned representation was rejected.' );
		$equivalent = $planned; $equivalent['sql_mode'] = implode( ',', array_reverse( explode( ',', $planned['sql_mode'] ) ) );
		$check( true === $database->assert_session( $equivalent ), 'Equivalent native mode order was treated as a changed representation.' );
	} );
	$assert_baseline();
	foreach ( array( 'time_zone', 'sql_mode' ) as $kind ) {
		$dependent_work = false;
		$changed( static function () use ( $database, $connection, $planned, $handle, $kind, &$dependent_work ) {
			$database->with_session( $planned, static function () use ( $database, $connection, $planned, $handle, $kind, &$dependent_work ) {
				if ( 'time_zone' === $kind ) { $handle->query( "SET SESSION time_zone='+01:00'" ); }
				else {
					$without_auto = array_values( array_diff( explode( ',', $planned['sql_mode'] ), array( 'NO_AUTO_VALUE_ON_ZERO' ) ) );
					$connection->set_backup_sql_mode( implode( ',', $without_auto ) );
				}
				$database->assert_session( $planned );
				$dependent_work = true; // A sentinel only: no row, DDL or filesystem operation is dispatched.
			} );
		} );
		$check( false === $dependent_work, 'Changed native representation reached a dependent operation.' );
		$assert_baseline(); // Outer native finally must restore original mode and time zone after rejection.
	}
	$original_wpdb = $wpdb;
	foreach ( array( 'db', 'ac', 'fk', 'uq' ) as $kind ) {
		$fresh = null;
		try {
			// TEST ONLY: each unsafe mutant owns a separate real Core connection using this task's existing account.
			// The production adapter still retains exactly the current Core handle and reads no credentials.
			$fresh = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$fresh->set_prefix( $original_wpdb->base_prefix );
			$fresh->set_blog_id( get_current_blog_id(), get_current_network_id() );
			$check( $fresh->ready && $fresh->dbh instanceof mysqli && $fresh->dbh !== $handle, 'Cannot isolate an owned actual Core connection for unsafe session disposal.' );
			$isolated_handle = $fresh->dbh;
			$result = $isolated_handle->query( 'SELECT CURRENT_USER() AS account,DATABASE() AS db,@@SESSION.autocommit AS ac,@@SESSION.foreign_key_checks AS fk,@@SESSION.unique_checks AS uq' );
			try { $clean = $result->fetch_assoc(); } finally { $result->free(); }
			$check( $clean['account'] === $baseline['account'] && $clean['db'] === $source && '1' === (string) $clean['ac'] &&
				'1' === (string) $clean['fk'] && '1' === (string) $clean['uq'], 'Unsafe mutant may not adopt another account, database, flag state or foreign transaction.' );
			$wpdb = $fresh;
			$fresh_connection = new Database_Connection();
			$check( true === $fresh_connection->assert_clean_session(), 'Positive native admission of the owned fresh Core session failed.' );
			$isolated = new Backup_Database( 1048576 );
			$dependent_work = false;
			$changed( static function () use ( $isolated, $planned, $isolated_handle, $target, $kind, &$dependent_work ) {
				$isolated->with_session( $planned, static function () use ( $isolated, $planned, $isolated_handle, $target, $kind, &$dependent_work ) {
					if ( 'db' === $kind ) { $isolated_handle->select_db( $target ); }
					elseif ( 'ac' === $kind ) { $isolated_handle->query( 'SET SESSION autocommit=0' ); }
					elseif ( 'fk' === $kind ) { $isolated_handle->query( 'SET SESSION foreign_key_checks=0' ); }
					else { $isolated_handle->query( 'SET SESSION unique_checks=0' ); }
					$isolated->assert_session( $planned );
					$dependent_work = true; // Sentinel only; no DML, DDL or files are dispatched.
				} );
			} );
			$check( false === $dependent_work && false === $fresh->ready && null === $fresh->dbh,
				'Unsafe native database/flag drift was reused or repaired instead of discarding its owned Core handle.' );
			// Do not USE/reset flags/COMMIT/ROLLBACK/reconnect after unsafe drift.
		} finally {
			$wpdb = $original_wpdb;
			if ( $fresh instanceof wpdb && $fresh->dbh instanceof mysqli ) {
				$fresh->close(); // Failed-test cleanup of this helper's own disposable handle only.
			}
		}
		$assert_baseline();
	}
	$assert_baseline();
	echo 'PASS: actual native backup session guard (' . $checks . " checks; original session/handle restored; unsafe isolated handles discarded; no dependent effects).\n";
}
