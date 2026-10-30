<?php
/**
 * Backup session restoration and isolated row-hash namespace regressions.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Backup_Database;
use WP_AI_Bridge\Support\Backup_Schema;

final class WPAI_Backup_Session_Native {
	public $database = 'wp_backup_session';
	public $prefix = 'wp_';
	public $mode = 'NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
	public $zone = 'SYSTEM';
	public $original;
	public $calls = array();
	public $quarantined = false;
	public $quarantines = 0;
	public $reject_admission = false;
	public $fail_mode_entry = false;
	public $fail_mode_restore = false;
	public $fail_zone_entry = false;
	public $fail_zone_restore = false;
	public $group_sql = null;
	public $group_parameters = null;

	public function __construct() { $this->original = $this->mode; }
	public function assert_clean_session() {
		$this->calls[] = array( 'admit' );
		if ( $this->reject_admission ) { throw new RuntimeException( 'ambient_session' ); }
	}
	public function set_backup_sql_mode( $mode ) {
		if ( $this->quarantined ) { throw new RuntimeException( 'quarantined_handle' ); }
		$this->calls[] = array( 'mode', $mode );
		$this->mode = $mode;
		if ( $this->fail_mode_entry && $mode !== $this->original || $this->fail_mode_restore && $mode === $this->original ) {
			$this->quarantined = true;
			throw new RuntimeException( 'mode_readback_unknown' );
		}
		// The adapter owner separately proves complete3135/readback semantics.
		return array( 'warning_count' => 1, 'accepted_deprecation_count' => 1 );
	}
	public function quarantine_backup_session() { ++$this->quarantines; $this->quarantined = true; }
	public function run( $sql, array $parameters = array(), array $sources = array(), $max_rows = 4096 ) {
		$this->calls[] = array( 'run', $sql, $parameters );
		if ( 0 === strpos( $sql, 'SET SESSION sql_mode' ) ) { throw new LogicException( 'Backup bypassed the narrowly scoped SQL-mode setter.' ); }
		if ( 0 === strpos( $sql, 'SELECT @@SESSION.sql_mode AS sql_mode' ) ) {
			return array( 'rows' => array( array( 'sql_mode' => $this->mode, 'time_zone' => $this->zone, 'version' => 'session-fixture' ) ) );
		}
		if ( 'SET SESSION time_zone=?' === $sql ) {
			if ( $this->quarantined ) { throw new RuntimeException( 'quarantined_handle' ); }
			$this->zone = $parameters[0];
			if ( $this->fail_zone_entry && '+00:00' === $this->zone || $this->fail_zone_restore && 'SYSTEM' === $this->zone ) {
				throw new RuntimeException( 'database_write_warning' );
			}
			return array( 'rows' => array() );
		}
		$this->group_sql = $sql;
		$this->group_parameters = $parameters;
		return array( 'rows' => array( array( 'digest' => str_repeat( 'b', 64 ), 'multiplicity' => '2' ) ) );
	}
}

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$database = static function ( $native ) {
	$r = new ReflectionClass( Backup_Database::class );
	$db = $r->newInstanceWithoutConstructor();
	$r->getProperty( 'connection' )->setValue( $db, $native );
	$r->getProperty( 'budget' )->setValue( $db, 1048576 );
	return $db;
};
$session = array( 'sql_mode' => 'NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_AUTO_VALUE_ON_ZERO', 'time_zone' => '+00:00' );
$native = new WPAI_Backup_Session_Native();
$result = $database( $native )->with_session( $session, static function () use ( $native, $session, $assert ) {
	$assert( $native->mode === $session['sql_mode'] && '+00:00' === $native->zone, 'The callback did not receive the exact requested session.' );
	return 'observed';
} );
$assert( 'observed' === $result && $native->mode === $native->original && 'SYSTEM' === $native->zone, 'Normal backup session did not restore the actual original settings.' );
$mode_calls = array_values( array_filter( $native->calls, static function ( $call ) { return 'mode' === $call[0]; } ) );
$assert( 2 === count( $mode_calls ) && $mode_calls[0][1] === $session['sql_mode'] && $mode_calls[1][1] === $native->original, 'Entry and restoration must both use the narrow setter without changing zero-date modes.' );

$native = new WPAI_Backup_Session_Native();
$called = false;
$native->reject_admission = true;
$error = null;
try { $database( $native )->with_session( $session, static function () use ( &$called ) { $called = true; } ); }
catch ( RuntimeException $caught ) { $error = $caught->getMessage(); }
$mode_calls = array_filter( $native->calls, static function ( $call ) { return 'mode' === $call[0] || 'run' === $call[0] && 0 === strpos( $call[1], 'SET ' ); } );
$assert( 'ambient_session' === $error && ! $called && ! $mode_calls && ! $native->quarantined, 'Rejected caller-owned sessions must not receive backup setters or cleanup.' );

$native = new WPAI_Backup_Session_Native();
$error = null;
try { $database( $native )->with_session( $session, static function () { throw new RuntimeException( 'callback_failed' ); } ); }
catch ( RuntimeException $caught ) { $error = $caught->getMessage(); }
$assert( 'callback_failed' === $error && $native->mode === $native->original && 'SYSTEM' === $native->zone && ! $native->quarantined, 'Callback failure must restore settings and preserve the functional error.' );

$native = new WPAI_Backup_Session_Native();
$native->fail_zone_entry = true;
$called = false;
$error = null;
try { $database( $native )->with_session( $session, static function () use ( &$called ) { $called = true; } ); }
catch ( RuntimeException $caught ) { $error = $caught->getMessage(); }
$assert( 'database_write_warning' === $error && ! $called && $native->mode === $native->original && 'SYSTEM' === $native->zone && ! $native->quarantined, 'A time-zone warning must stay strict while successful cleanup restores the admitted session.' );

foreach ( array( 'fail_mode_restore', 'fail_zone_restore' ) as $failure ) {
	$native = new WPAI_Backup_Session_Native();
	$native->$failure = true;
	$effects = 0;
	$error = null;
	try { $database( $native )->with_session( $session, static function () use ( &$effects ) { ++$effects; return 'committed'; } ); }
	catch ( RuntimeException $caught ) { $error = $caught->getMessage(); }
	$assert( null !== $error && 1 === $effects && $native->quarantined && $native->quarantines > 0, 'Unprovable restoration must quarantine without pretending to roll back an acknowledged callback effect.' );
}
$native = new WPAI_Backup_Session_Native();
$native->fail_mode_entry = true;
$called = false;
try { $database( $native )->with_session( $session, static function () use ( &$called ) { $called = true; } ); }
catch ( RuntimeException $caught ) {}
$assert( ! $called && $native->quarantined && $native->quarantines > 0, 'Unproven mode admission must never reach the callback or reuse the handle.' );

$native = new WPAI_Backup_Session_Native();
$columns = array_map( static function ( $name ) { return array( 'name' => $name ); },
	array( 'digest', 'multiplicity', 'wpai_row_sha256', 'wpai_backup_rows', 'value' ) );
$after = str_repeat( 'a', 64 );
$group = $database( $native )->next_group( array( 'table' => 'wp_collisions', 'columns' => $columns ), $after, 'isolated_restore' );
$hash = Backup_Schema::qualified( 'wpai_backup_rows', 'wpai_row_sha256' );
$assert( str_repeat( 'b', 64 ) === $group['digest'] && '2' === $group['multiplicity'] && array( $after ) === $native->group_parameters, 'Grouped response or continuation parameters changed.' );
$assert( false !== strpos( $native->group_sql, ' FROM (SELECT ' ) &&
	false !== strpos( $native->group_sql, 'FROM ' . Backup_Schema::qualified( 'isolated_restore', 'wp_collisions' ) . ') AS ' ),
	'Row hashes must be projected through an isolated derived namespace.' );
$assert( false !== strpos( $native->group_sql, 'WHERE ' . $hash . ' > ? GROUP BY ' . $hash . ' ORDER BY ' . $hash . ' LIMIT ' ),
	'Filtering, grouping and ordering must resolve only the explicitly qualified generated hash.' );
$assert( false === strpos( $native->group_sql, 'GROUP BY digest' ) && false === strpos( $native->group_sql, 'HAVING digest' ), 'A legitimate source digest column must not select the grouping key.' );
foreach ( array_column( $columns, 'name' ) as $name ) {
	$assert( false !== strpos( $native->group_sql, 'CAST(' . chr( 96 ) . $name . chr( 96 ) . ' AS BINARY)' ), 'A legitimate generated-looking source name was omitted or banned.' );
}
echo "issue199-backup-session: {$checks} checks passed\n";
