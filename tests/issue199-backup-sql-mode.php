<?php
/**
 * Fixed backup sql_mode acknowledgement and strict generic warning regressions.
 *
 * Native function doubles exercise the real adapter without opening a database.
 *
 * @package WP_AI_Bridge
 */

namespace {
	// The doubles also run in the ordinary dependency-free fast suite.
	if ( ! class_exists( 'mysqli_stmt', false ) ) { class mysqli_stmt {} }
	if ( ! class_exists( 'mysqli_result', false ) ) { class mysqli_result {} }
}

namespace WP_AI_Bridge\Support {
	final class Backup_Mode_Native_Probe {
		public static $count;
		public static $warning;
		public static $actual;
		public static $phase;
		public static $prepares;
		public static $executes;
		public static $diagnostic_reads;
		public static $closes;
		public static $statements;
		public static $bound;
		public static $fetched;
		public static $readback_warning;
		public static $execute_fails;
		public static $close_fails;
		public static $warning_emitted;
		public static function reset( $mode, $count = 1 ) {
			self::$actual = $mode;
			self::$count = $count;
			self::$warning = new Backup_Mode_Warning();
			self::$phase = '';
			self::$prepares = self::$executes = self::$diagnostic_reads = self::$closes = 0;
			self::$statements = array();
			self::$bound = null;
			self::$fetched = self::$readback_warning = self::$execute_fails = self::$close_fails = self::$warning_emitted = false;
		}
	}
	final class Backup_Mode_Warning {
		public $errno = 3135;
		public $sqlstate = 'HY000';
		public $message = "'NO_ZERO_DATE', 'NO_ZERO_IN_DATE' and 'ERROR_FOR_DIVISION_BY_ZERO' sql modes should be used with strict mode. They will be merged with strict mode in a future release.";
		public $more = false;
		public function next() { return $this->more; }
	}
	function mysqli_thread_id( $handle ) { return 73; }
	function mysqli_prepare( $handle, $sql ) {
		++Backup_Mode_Native_Probe::$prepares;
		Backup_Mode_Native_Probe::$statements[] = $sql;
		Backup_Mode_Native_Probe::$phase = 'prepare';
		return ( new \ReflectionClass( \mysqli_stmt::class ) )->newInstanceWithoutConstructor();
	}
	function mysqli_stmt_bind_param( $statement, $types, &...$values ) {
		if ( 's' !== $types || 1 !== count( $values ) ) { throw new \RuntimeException( 'Unexpected fixed setter parameter shape.' ); }
		Backup_Mode_Native_Probe::$bound = $values[0];
		return true;
	}
	function mysqli_stmt_execute( $statement ) {
		++Backup_Mode_Native_Probe::$executes;
		Backup_Mode_Native_Probe::$phase = 'execute';
		if ( Backup_Mode_Native_Probe::$warning_emitted ) { trigger_error( 'private native diagnostic must not reach an application handler', E_USER_WARNING ); }
		return ! Backup_Mode_Native_Probe::$execute_fails;
	}
	function mysqli_warning_count( $handle ) {
		return 'prepare' === Backup_Mode_Native_Probe::$phase ? 0 :
			( 'readback' === Backup_Mode_Native_Probe::$phase ? ( Backup_Mode_Native_Probe::$readback_warning ? 1 : 0 ) : Backup_Mode_Native_Probe::$count );
	}
	function mysqli_stmt_get_warnings( $statement ) { ++Backup_Mode_Native_Probe::$diagnostic_reads; return Backup_Mode_Native_Probe::$warning; }
	function mysqli_stmt_close( $statement ) { if ( Backup_Mode_Native_Probe::$close_fails ) { throw new \RuntimeException( 'Unproved statement cleanup.' ); } return true; }
	function mysqli_stmt_errno( $statement ) { return 1234; }
	function mysqli_stmt_sqlstate( $statement ) { return 'HY000'; }
	function mysqli_close( $handle ) { ++Backup_Mode_Native_Probe::$closes; return true; }
	function mysqli_query( $handle, $sql ) {
		Backup_Mode_Native_Probe::$statements[] = $sql;
		Backup_Mode_Native_Probe::$phase = 'SELECT @@SESSION.sql_mode AS mode' === $sql ? 'readback' : 'generic';
		return 'readback' === Backup_Mode_Native_Probe::$phase ?
			( new \ReflectionClass( \mysqli_result::class ) )->newInstanceWithoutConstructor() : true;
	}
	function mysqli_affected_rows( $handle ) { return 0; }
	function mysqli_insert_id( $handle ) { return 0; }
	function mysqli_fetch_assoc( $result ) {
		if ( Backup_Mode_Native_Probe::$fetched ) { return null; }
		Backup_Mode_Native_Probe::$fetched = true;
		return null === Backup_Mode_Native_Probe::$actual ? null : array( 'mode' => Backup_Mode_Native_Probe::$actual );
	}
	function mysqli_free_result( $result ) {}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	use WP_AI_Bridge\Support\Backup_Mode_Native_Probe as Probe;
	use WP_AI_Bridge\Support\Database_Connection;
	use WP_AI_Bridge\Support\Database_Write_Failure;

	$reflection = new ReflectionClass( Database_Connection::class );
	$checks = 0;
	$assert = static function ( $condition, $message ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
	$original = 'NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
	$backup = $original . ',NO_AUTO_VALUE_ON_ZERO';
	$subject = static function ( $actual, $count = 1 ) use ( $reflection ) {
		Probe::reset( $actual, $count );
		$connection = $reflection->newInstanceWithoutConstructor();
		$handle = new stdClass();
		$GLOBALS['wpdb'] = (object) array( 'dbh' => $handle, 'ready' => true, 'base_prefix' => 'wp_', 'options' => 'wp_options' );
		foreach ( array( 'handle' => $handle, 'wpdb' => $GLOBALS['wpdb'], 'session' => 73 ) as $name => $value ) {
			$reflection->getProperty( $name )->setValue( $connection, $value );
		}
		$connection->prefix = 'wp_';
		$connection->options = 'wp_options';
		$connection->packet_bytes = 67108864;
		return $connection;
	};
	$rejected = static function ( $connection, $mode, $reason ) use ( $assert ) {
		try {
			$connection->set_backup_sql_mode( $mode );
			throw new RuntimeException( 'An unproved backup mode change was accepted.' );
		} catch ( Database_Write_Failure $error ) {
			$assert( $reason === $error->reason, 'Backup-mode failure lost its fixed diagnostic: ' . $error->reason );
		}
		$assert( 1 === Probe::$executes && 1 === Probe::$closes && null === $GLOBALS['wpdb']->dbh && false === $GLOBALS['wpdb']->ready, 'An unproved dispatched mode was retried or left usable.' );
	};

	$connection = $subject( 'NO_AUTO_VALUE_ON_ZERO,' . $original );
	$ack = $connection->set_backup_sql_mode( $backup );
	$assert( array( 'warning_count' => 1, 'accepted_deprecation_count' => 1 ) === $ack &&
		1 === Probe::$executes && 1 === Probe::$diagnostic_reads && 0 === Probe::$closes, 'Complete known deprecation was not acknowledged with exact native mode readback.' );
	$assert( array( 'SET SESSION sql_mode=?', 'SELECT @@SESSION.sql_mode AS mode' ) === Probe::$statements && $backup === Probe::$bound, 'The compatibility path changed SQL or supplied mode bytes.' );
	$connection = $subject( $original );
	$assert( 1 === $connection->set_backup_sql_mode( $original )['accepted_deprecation_count'] && $original === Probe::$bound, 'Restoring the actual original mode rejected its same known deprecation.' );
	$connection = $subject( '', 0 );
	$assert( array( 'warning_count' => 0, 'accepted_deprecation_count' => 0 ) === $connection->set_backup_sql_mode( '' ) && 0 === Probe::$diagnostic_reads, 'A warning-free exact empty mode requested stale diagnostics.' );

	foreach ( array( 'missing', 'wrong_errno', 'wrong_state', 'truncated', 'extra_message', 'extra_entry' ) as $case ) {
		$connection = $subject( $backup );
		if ( 'missing' === $case ) { Probe::$warning = false; }
		if ( 'wrong_errno' === $case ) { Probe::$warning->errno = 1287; }
		if ( 'wrong_state' === $case ) { Probe::$warning->sqlstate = '01000'; }
		if ( 'truncated' === $case ) { Probe::$warning->message = substr( Probe::$warning->message, 0, -1 ); }
		if ( 'extra_message' === $case ) { Probe::$warning->message .= ' Another warning.'; }
		if ( 'extra_entry' === $case ) { Probe::$warning->more = true; }
		$rejected( $connection, $backup, 'database_backup_sql_mode_warning' );
	}
	$connection = $subject( $backup, 2 );
	$rejected( $connection, $backup, 'database_backup_sql_mode_warning' );
	$assert( 0 === Probe::$diagnostic_reads, 'A mixed/truncated warning count was retrieved as if a complete single diagnostic.' );
	$connection = $subject( $original );
	$rejected( $connection, $backup, 'database_backup_sql_mode_readback' );
	$connection = $subject( null );
	$rejected( $connection, $backup, 'database_backup_sql_mode_readback' );
	$connection = $subject( $backup );
	Probe::$readback_warning = true;
	$rejected( $connection, $backup, 'database_write_warning' );
	$connection = $subject( $backup );
	Probe::$execute_fails = true;
	$rejected( $connection, $backup, 'database_write_query_failed' );
	$connection = $subject( $backup );
	Probe::$close_fails = true;
	$rejected( $connection, $backup, 'database_backup_sql_mode_unproved' );

	$connection = $subject( $backup );
	try {
		$connection->run( 'SET SESSION sql_mode=' . "'NO_AUTO_VALUE_ON_ZERO'" );
		throw new RuntimeException( 'Generic SQL warning rejection was relaxed.' );
	} catch ( Database_Write_Failure $error ) {
		$assert( 'database_write_warning' === $error->reason && 0 === Probe::$diagnostic_reads && 0 === Probe::$closes, 'Generic run gained the backup-only warning exemption.' );
	}
	foreach ( array( 'ANSI,ANSI', 'NO_AUTO_VALUE_ON_ZERO,', 'no_auto_value_on_zero', "ANSI\0", str_repeat( 'A', 4097 ) ) as $invalid ) {
		$connection = $subject( $backup );
		try {
			$connection->set_backup_sql_mode( $invalid );
			throw new RuntimeException( 'Invalid native mode list was admitted.' );
		} catch ( Database_Write_Failure $error ) {
			$assert( 'database_backup_sql_mode_invalid' === $error->reason && 0 === Probe::$prepares && 0 === Probe::$executes && 0 === Probe::$closes, 'Invalid mode dispatched or quarantined an untouched caller session.' );
		}
	}
	$connection = $subject( $backup );
	$reflection->getProperty( 'owned' )->setValue( $connection, true );
	try {
		$connection->set_backup_sql_mode( $backup );
		throw new RuntimeException( 'An owned native transaction admitted backup session changes.' );
	} catch ( Database_Write_Failure $error ) {
		$assert( 'database_write_unsupported_connection' === $error->reason && 0 === Probe::$executes && 0 === Probe::$closes, 'Backup changed or closed an inherited owned transaction.' );
	}
	$connection = $subject( $backup );
	Probe::$warning_emitted = true;
	$application_warnings = 0;
	set_error_handler( static function () use ( &$application_warnings ) { ++$application_warnings; return true; } );
	try {
		$connection->set_backup_sql_mode( $backup );
		$assert( 0 === $application_warnings, 'Native setter diagnostics escaped to an application callback.' );
		trigger_error( 'ordinary application warning after setter', E_USER_WARNING );
		$assert( 1 === $application_warnings, 'The fixed setter did not restore the application error-handler stack.' );
	} finally { restore_error_handler(); }
	$connection->quarantine_backup_session();
	$connection->quarantine_backup_session();
	$assert( 1 === Probe::$closes && null === $GLOBALS['wpdb']->dbh, 'Backup cleanup quarantine repeated native close or retained the unusable handle.' );
	echo "PASS: fixed backup sql_mode complete diagnostics, readback, quarantine and generic warning fence ({$checks} checks).\n";
}
