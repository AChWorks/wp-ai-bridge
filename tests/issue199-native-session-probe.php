<?php
/**
 * Disposable native engine admission proof; never a product capability.
 *
 * Usage: php tests/issue199-native-session-probe.php /private/connection.json
 * The locator must identify a skip-networking n199_phase0 fixture database.
 *
 * @package WP_AI_Bridge
 */

if ( PHP_SAPI !== 'cli' || 2 !== $argc ) {
	throw new RuntimeException( 'A private phase0 connection locator is required.' );
}
$locator = json_decode( file_get_contents( $argv[1] ), true, 16, JSON_THROW_ON_ERROR );
if ( ! is_array( $locator ) || 'n199_phase0' !== ( $locator['database'] ?? '' ) ||
	! is_string( $locator['client_config'] ?? null ) || ! is_string( $locator['socket'] ?? null ) ) {
	throw new RuntimeException( 'Only the disposable phase0 fixture is admitted.' );
}
$ini = parse_ini_file( $locator['client_config'], true, INI_SCANNER_RAW );
$client = $ini['client'] ?? array();
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
$connect = static function () use ( $locator, $client ) {
	return new mysqli( 'localhost', $client['user'], $client['password'] ?? '', $locator['database'], 0, $locator['socket'] );
};
$owner = $connect();
$observer = $connect();
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$scalar = static function ( $connection, $sql ) {
	$result = $connection->query( $sql );
	$row = $result->fetch_row();
	$result->free();
	return $row[0] ?? null;
};
$flags = static function ( $connection ) {
	$result = $connection->query( 'SELECT @@SESSION.autocommit AS ac, @@SESSION.foreign_key_checks AS fk, @@SESSION.unique_checks AS uq' );
	$row = $result->fetch_assoc();
	$result->free();
	return $row;
};
$expect_errno = static function ( $connection, $sql, $expected, $message ) use ( $assert ) {
	try {
		$connection->query( $sql );
	} catch ( mysqli_sql_exception $error ) {
		$assert( in_array( $error->getCode(), (array) $expected, true ), $message . ' Unexpected native errno: ' . $error->getCode() );
		return;
	}
	throw new RuntimeException( $message . ' Native call unexpectedly succeeded.' );
};
$assert( '1' === (string) $scalar( $owner, 'SELECT @@skip_networking' ), 'Fixture has a TCP listener enabled.' );
$assert( array( 'ac' => '1', 'fk' => '1', 'uq' => '1' ) === $flags( $owner ), 'Initial session flags are incompatible.' );
$version = $scalar( $owner, 'SELECT VERSION()' );
$prefix = 'wpai_session_probe_' . bin2hex( random_bytes( 6 ) );
$table = $prefix . '_options';
$other = $prefix . '_other';
$alias = $prefix . '_handler';
$tables_created = false;
try {
	$owner->query( "CREATE TABLE {$table} (id BIGINT NOT NULL PRIMARY KEY, v BIGINT NOT NULL) ENGINE=InnoDB" );
	$owner->query( "CREATE TABLE {$other} (id BIGINT NOT NULL PRIMARY KEY, v BIGINT NOT NULL) ENGINE=InnoDB" );
	$tables_created = true;
	$owner->query( "INSERT INTO {$table} VALUES (1,0)" );
	$owner->query( "INSERT INTO {$other} VALUES (1,0)" );
	$observer->query( 'SET SESSION lock_wait_timeout=1' );
	$observer->query( 'SET SESSION innodb_lock_wait_timeout=1' );

	$owner->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' );
	$owner->query( "HANDLER {$table} OPEN AS {$alias}" );
	$owner->query( "HANDLER {$alias} CLOSE" );
	$assert( '0' === (string) $scalar( $owner, "SELECT v FROM {$table} WHERE id=1" ), 'Normal handler open/close changed fixture data.' );

	// SET TRANSACTION alone admits these locked sessions. HANDLER must reject
	// them without releasing their caller-owned SQL locks.
	$lock_cases = array(
		array( "{$table} WRITE", $table, false ),
		array( "{$other} READ", $other, true ),
		array( "{$other} WRITE", $other, false ),
		array( "{$table} WRITE, {$other} READ", $table, false ),
	);
	foreach ( $lock_cases as $case ) {
		$owner->query( 'LOCK TABLES ' . $case[0] );
		try {
			$owner->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' );
			$assert( '1' === $flags( $owner )['ac'], 'Active SQL locks unexpectedly changed autocommit.' );
			$expect_errno( $owner, "HANDLER {$table} OPEN AS {$alias}", 1192, 'Locked-table admission did not fail positively.' );
			$query = $case[2] ? "UPDATE {$case[1]} SET v=1 WHERE id=1" : "SELECT v FROM {$case[1]} WHERE id=1";
			$expect_errno( $observer, $query, 1205, 'External observer did not prove caller SQL lock retention.' );
		} finally {
			$owner->query( 'UNLOCK TABLES' );
		}
		$assert( '0' === (string) $scalar( $observer, "SELECT v FROM {$case[1]} WHERE id=1" ), 'Lock proof unexpectedly changed fixture data.' );
	}

	$owner->query( 'START TRANSACTION' );
	try {
		$expect_errno( $owner, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 1568, 'An explicit empty caller transaction was admitted.' );
	} finally {
		$owner->query( 'ROLLBACK' );
	}
	$owner->query( 'START TRANSACTION' );
	try {
		$owner->query( "UPDATE {$table} SET v=7 WHERE id=1" );
		$expect_errno( $owner, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 1568, 'A dirty caller transaction was admitted.' );
		$assert( '0' === (string) $scalar( $observer, "SELECT v FROM {$table} WHERE id=1" ), 'The caller transaction was committed by admission.' );
		$assert( '7' === (string) $scalar( $owner, "SELECT v FROM {$table} WHERE id=1" ), 'The caller transaction was rolled back by admission.' );
	} finally {
		$owner->query( 'ROLLBACK' );
	}
	$owner->query( 'SET autocommit=0' );
	try {
		$assert( '0' === $flags( $owner )['ac'], 'Autocommit-zero admission cannot be identified without repair.' );
	} finally {
		$owner->query( 'ROLLBACK' );
		$owner->query( 'SET autocommit=1' );
	}

	$owner->query( "HANDLER {$table} OPEN AS {$alias}" );
	try {
		$expect_errno( $owner, "HANDLER {$table} OPEN AS {$alias}", array( 1066, 1050 ), 'An existing handler alias was overwritten.' );
		$assert( '1' === (string) $scalar( $owner, "HANDLER {$alias} READ FIRST" ), 'A collision closed the original caller handler.' );
	} finally {
		$owner->query( "HANDLER {$alias} CLOSE" );
	}

	// Full short journal sequence: acknowledge commit and restored mode before
	// an observer can establish durable state. This is native SQL proof only.
	$owner->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' );
	$owner->query( "HANDLER {$table} OPEN AS {$alias}" );
	$owner->query( "HANDLER {$alias} CLOSE" );
	$owner->query( 'SET autocommit=0' );
	$owner->query( "INSERT INTO {$table} VALUES (2,42)" );
	$owner->query( 'COMMIT AND NO CHAIN NO RELEASE' );
	$owner->query( 'SET autocommit=1' );
	$assert( '42' === (string) $scalar( $observer, "SELECT v FROM {$table} WHERE id=2" ), 'Acknowledged journal data was not observed from a second session.' );
	$assert( array( 'ac' => '1', 'fk' => '1', 'uq' => '1' ) === $flags( $owner ), 'Journal sequence did not restore admitted flags.' );
	echo "PASS: native callback-journal engine prerequisite ({$checks} checks; {$version}).\n";
} finally {
	try { $owner->query( 'UNLOCK TABLES' ); } catch ( Throwable $error ) {}
	try { $owner->query( 'ROLLBACK' ); } catch ( Throwable $error ) {}
	try { $owner->query( 'SET autocommit=1' ); } catch ( Throwable $error ) {}
	if ( $tables_created ) {
		$owner->query( "DROP TABLE IF EXISTS {$other}, {$table}" );
	}
	$owner->close();
	$observer->close();
}
