<?php
/**
 * Indexed restore counts retain atomic receipts and exact normalization checks.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support {
	/** Native-function doubles only; production has no I/O injection hooks. */
	function wpai_insert_io_double( $operation, $native ) {
		if ( ! isset( $GLOBALS['wpai_insert_io'] ) ) { return $native(); }
		$fault = &$GLOBALS['wpai_insert_io'];
		$fault['calls'][ $operation ] = ( $fault['calls'][ $operation ] ?? 0 ) + 1;
		if ( $operation === $fault['operation'] && $fault['calls'][ $operation ] === $fault['call'] ) {
			++$fault['emitted'];
			trigger_error( 'Synthetic staged native diagnostic.', E_USER_WARNING );
			if ( 'throw' === $fault['result'] ) { throw new \RuntimeException( 'synthetic_staged_io_exception' ); }
			if ( 'fail' === $fault['result'] ) { return 'seek' === $operation ? -1 : false; }
		}
		return $native();
	}
	function ftell( $stream ) { return wpai_insert_io_double( 'tell', static function () use ( $stream ) { return \ftell( $stream ); } ); }
	function fseek( $stream, $offset, $whence = SEEK_SET ) { return wpai_insert_io_double( 'seek', static function () use ( $stream, $offset, $whence ) { return \fseek( $stream, $offset, $whence ); } ); }
	function fread( $stream, $length ) { return wpai_insert_io_double( 'read', static function () use ( $stream, $length ) { return \fread( $stream, $length ); } ); }
	class Database_Control_Storage {
		public function __construct( $connection, $budget ) {}
		public function receipt_schema() {}
	}
}
namespace {
	require __DIR__ . '/bootstrap.php';

	use WP_AI_Bridge\Support\Backup_Database;
	use WP_AI_Bridge\Support\Backup_Schema;

	final class WPAI_Backup_Insert_Native {
		public $database = 'restore_source';
		public $options = 'wp_options';
		public $queries = array();
		public $rows = array();
		public $receipts = array();
		public $begins = 0;
		public $commits = 0;
		public $rollbacks = 0;
		public $inserts = 0;
		public $normalize = false;
		public $unique = true;
		public $stream;
		public $admitted_position;
		public $count_positions = array();
		public $owned = false;
		private $snapshot;
		public static function digest( $values ) {
			$identity = 'wpai-row-v1;';
			foreach ( $values as $value ) { $identity .= null === $value ? 'N;' : 'V' . strlen( $value ) . ':' . hash( 'sha256', $value ) . ';'; }
			return hash( 'sha256', $identity );
		}
		public function table( $table ) { return Backup_Schema::qualified( $this->database, $table ); }
		public function begin_callback_journal() {
			++$this->begins; $this->owned = true; $this->admitted_position = ftell( $this->stream );
			$this->snapshot = array( $this->rows, $this->receipts );
		}
		public function commit() { $this->owned = false; ++$this->commits; $this->snapshot = null; return true; }
		public function rollback() { $this->owned = false; ++$this->rollbacks; if ( null !== $this->snapshot ) { list( $this->rows, $this->receipts ) = $this->snapshot; $this->snapshot = null; } return true; }
		public function run( $sql, $parameters = array(), $sources = array(), $maximum = 4096 ) {
			$this->queries[] = array( $sql, $parameters );
			if ( 0 === strpos( $sql, 'SELECT option_value FROM ' ) ) {
				return array( 'rows' => isset( $this->receipts[ $parameters[0] ] ) ? array( array( 'option_value' => $this->receipts[ $parameters[0] ] ) ) : array() );
			}
			if ( 0 === strpos( $sql, 'INSERT INTO ' . $this->table( $this->options ) ) ) {
				$this->receipts[ $parameters[0] ] = $parameters[1]; return array( 'rows' => array() );
			}
			if ( 0 === strpos( $sql, 'INSERT INTO ' ) ) {
				$values = array();
				foreach ( $parameters as $parameter ) {
					if ( null === $parameter ) { $values[] = null; continue; }
					$source = $sources[ $parameter['source'] ];
					fseek( $source['stream'], $source['offset'] );
					$values[] = 0 === $source['bytes'] ? '' : fread( $source['stream'], $source['bytes'] );
				}
				// Model ordinary integer native canonicalization without floats.
				if ( null !== $values[0] && preg_match( '/^[+]?[0-9]+$/D', $values[0] ) ) { $values[0] = ltrim( $values[0], '+0' ); $values[0] = '' === $values[0] ? '0' : $values[0]; }
				if ( $this->unique ) { foreach ( $this->rows as $existing ) { if ( $existing[0] === $values[0] ) { throw new \RuntimeException( 'native_primary_conflict' ); } } }
				if ( $this->normalize ) { $values[1] = 'native changed bytes'; }
				++$this->inserts; $this->rows[] = $values; return array( 'rows' => array() );
			}
			$this->count_positions[] = ftell( $this->stream );
			$after = 0 === strpos( $sql, 'SELECT (COUNT(*)=' );
			$indexed = false !== strpos( $sql, 'WHERE `id`=CAST(' );
			$offset = $after ? 1 : 0;
			$pk = $indexed ? $parameters[ $offset++ ] : null;
			if ( $indexed && false !== strpos( $sql, '=CAST(? AS UNSIGNED)' ) &&
				( strlen( $pk ) > 19 || strlen( $pk ) === 19 && strcmp( $pk, '9223372036854775807' ) > 0 ) ) {
				throw new \RuntimeException( 'database_write_warning' );
			}
			$group = $parameters[ $offset ];
			$count = 0;
			foreach ( $this->rows as $row ) { if ( ( null === $pk || $pk === $row[0] ) && self::digest( $row ) === $group ) { ++$count; } }
			return array( 'rows' => array( $after ? array( 'ok' => (string) ( (int) $parameters[0] + 1 === $count ? 1 : 0 ) ) : array( 'n' => (string) $count ) ) );
		}
	}
	$checks = 0;
	$assert = static function ( $value, $message ) use ( &$checks ) { ++$checks; if ( ! $value ) { throw new \RuntimeException( $message ); } };
	$error = static function ( $callback ) { try { $callback(); } catch ( \Throwable $caught ) { return $caught->getMessage(); } return null; };
	$make = static function ( $pk, $key = 'PRIMARY KEY (`id`)', $kind = 'bigint unsigned' ) {
		$ddl = 'CREATE TABLE `wp_insert_fixture` (`id` ' . $kind . ' NOT NULL,`payload` longblob DEFAULT NULL' .
			( '' === $key ? '' : ',' . $key ) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
		$schema = array( 'table' => 'wp_insert_fixture', 'ddl' => $ddl, 'hash' => hash( 'sha256', $ddl ),
			'blueprint' => Backup_Schema::blueprint( $ddl, 'wp_insert_fixture' ), 'columns' => array(
				array( 'name' => 'id', 'type' => strtok( $kind, ' (' ), 'charset' => null ), array( 'name' => 'payload', 'type' => 'longblob', 'charset' => null ) ) );
		$values = array( $pk, "raw\0\xffpayload" ); $bytes = 'prefix:'; $cells = array();
		foreach ( $values as $value ) {
			$cells[] = array( 'null' => null === $value, 'offset' => strlen( $bytes ), 'bytes' => null === $value ? 0 : strlen( $value ), 'sha256' => null === $value ? null : hash( 'sha256', $value ) );
			if ( null !== $value ) { $bytes .= $value; }
		}
		$stream = fopen( 'php://memory', 'w+b' ); fwrite( $stream, $bytes ); fseek( $stream, 3 );
		$source = array( 'stream' => $stream, 'bytes' => strlen( $bytes ) );
		$native = new WPAI_Backup_Insert_Native(); $native->stream = $stream; $native->unique = '' !== $key;
		$r = new \ReflectionClass( Backup_Database::class ); $db = $r->newInstanceWithoutConstructor();
		$r->getProperty( 'connection' )->setValue( $db, $native ); $r->getProperty( 'budget' )->setValue( $db, 1048576 );
		return array( $db, $native, $schema, $cells, $source, WPAI_Backup_Insert_Native::digest( $values ), $values );
	};
	foreach ( array( '0', '18446744073709551615', '-9223372036854775808' ) as $pk ) {
		$signed = '-' === $pk[0];
		list( $db, $native, $schema, $cells, $source, $group, $values ) = $make( $pk, 'PRIMARY KEY (`id`)', $signed ? 'bigint' : 'bigint unsigned' );
		try {
			$db->insert( 'isolated_target', $schema, $cells, $source, $group, 'same-row-' . $pk );
			$counts = array_values( array_filter( $native->queries, static function ( $query ) { return false !== strpos( $query[0], 'COUNT(*)' ); } ) );
			$cast = $signed ? 'SIGNED' : 'UNSIGNED';
			$assert( 2 === count( $counts ) && false !== strpos( $counts[0][0], '`id`=CAST(CONVERT(? USING ascii) AS ' . $cast . ') AND SHA2(CONCAT(' ) &&
				false !== strpos( $counts[1][0], '`id`=CAST(CONVERT(? USING ascii) AS ' . $cast . ') AND SHA2(CONCAT(' ) &&
				array( $pk, $group ) === $counts[0][1] && array( '0', $pk, $group ) === $counts[1][1],
				'Both counts must use the exact bounded typed PK plus the unchanged row identity, including zero/u64/signed boundaries.' );
			$assert( array( $values ) === $native->rows && 1 === $native->commits && 0 === $native->rollbacks && 3 === $native->admitted_position && 3 === $native->count_positions[0],
				'Bounded PK reading must restore the stream before original transaction/receipt/data work.' );
			fseek( $source['stream'], $cells[0]['offset'] ); fwrite( $source['stream'], 'x' );
			$db->insert( 'isolated_target', $schema, $cells, $source, $group, 'same-row-' . $pk );
			$assert( 1 === $native->inserts && 1 === $native->commits && 1 === $native->rollbacks,
				'An acknowledged row receipt must retain its no-resend path without depending on staged bytes again.' );
		} finally { fclose( $source['stream'] ); }
	}
	foreach ( array( array( '', 'bigint unsigned' ), array( 'PRIMARY KEY (`id`)', 'varchar(64)' ),
		array( 'PRIMARY KEY (`id`,`payload`(16))', 'bigint unsigned' ) ) as $fallback ) {
		list( $db, $native, $schema, $cells, $source, $group ) = $make( '7', $fallback[0], $fallback[1] );
		try {
			$db->insert( 'isolated_target', $schema, $cells, $source, $group, 'fallback' );
			$counts = array_values( array_filter( $native->queries, static function ( $query ) { return false !== strpos( $query[0], 'COUNT(*)' ); } ) );
			$assert( 2 === count( $counts ) && false === strpos( $counts[0][0], '=CAST(' ) &&
				array( $group ) === $counts[0][1] && array( '0', $group ) === $counts[1][1], 'Unsupported/keyless locators must retain both generic exact-row count queries.' );
			if ( '' === $fallback[0] ) {
				$db->insert( 'isolated_target', $schema, $cells, $source, $group, 'second-duplicate' );
				$assert( 2 === count( $native->rows ) && 2 === $native->commits, 'Keyless duplicate occurrences must retain their independent atomic receipts/multiplicity.' );
			}
		} finally { fclose( $source['stream'] ); }
	}
	foreach ( array( false, true ) as $canonical ) {
		list( $db, $native, $schema, $cells, $source, $group ) = $make( $canonical ? '1' : '01' );
		try {
			$native->normalize = $canonical;
			$assert( 'backup_restore_value_normalized' === $error( static function () use ( $db, $schema, $cells, $source, $group ) { $db->insert( 'isolated_target', $schema, $cells, $source, $group, 'normalized' ); } ) &&
				array() === $native->rows && array() === $native->receipts && 1 === $native->rollbacks && 0 === $native->commits,
				'Indexed and noncanonical fallback rows must retain the independent normalization check and atomic rollback.' );
		} finally { fclose( $source['stream'] ); }
	}
	list( $db, $native, $schema, $cells, $source, $group ) = $make( '1' );
	try {
		$native->rows = array( array( '1', 'already changed target' ) );
		$assert( 'native_primary_conflict' === $error( static function () use ( $db, $schema, $cells, $source, $group ) { $db->insert( 'isolated_target', $schema, $cells, $source, $group, 'stale' ); } ) &&
			array( array( '1', 'already changed target' ) ) === $native->rows && array() === $native->receipts && 1 === $native->rollbacks,
			'A stale same-PK target must not bypass its differing full-row hash or be overwritten.' );
	} finally { fclose( $source['stream'] ); }
	list( $db, $native, $schema, $cells, $source, $group ) = $make( '1' );
	try {
		$cells[0]['sha256'] = hash( 'sha256', 'other verified bytes' );
		$assert( 'backup_archive_cell_integrity' === $error( static function () use ( $db, $schema, $cells, $source, $group ) { $db->insert( 'isolated_target', $schema, $cells, $source, $group, 'bad-stage' ); } ) &&
			1 === $native->begins && 1 === $native->rollbacks && 0 === $native->inserts && array() === $native->receipts && 3 === ftell( $source['stream'] ),
			'Changed staged PK bytes must reject before receipt/data insertion, roll back the owned journal and restore the stream position.' );
	} finally { fclose( $source['stream'] ); }

	// Every staged I/O primitive, including the finally seek, executes while the
	// owned receipt transaction is active. An application handler must never run.
	foreach ( array(
		array( 'tell', 1, 'success' ), array( 'seek', 1, 'success' ), array( 'read', 1, 'success' ), array( 'seek', 2, 'success' ),
		array( 'tell', 1, 'fail' ), array( 'seek', 1, 'fail' ), array( 'read', 1, 'fail' ), array( 'seek', 2, 'fail' ),
		array( 'tell', 1, 'throw' ), array( 'seek', 2, 'throw' ),
	) as $case ) {
		list( $db, $native, $schema, $cells, $source, $group, $values ) = $make( '18446744073709551615' );
		$GLOBALS['wpai_insert_io'] = array( 'operation' => $case[0], 'call' => $case[1], 'result' => $case[2], 'calls' => array(), 'emitted' => 0 );
		$application_warnings = 0; $application_owned_callbacks = 0;
		set_error_handler( static function () use ( &$application_warnings, &$application_owned_callbacks, $native ) {
			++$application_warnings;
			if ( $native->owned ) { ++$application_owned_callbacks; }
			return true;
		} );
		try {
			$observed = $error( static function () use ( $db, $schema, $cells, $source, $group ) { $db->insert( 'isolated_target', $schema, $cells, $source, $group, 'io-guarded' ); } );
			$assert( 1 === $GLOBALS['wpai_insert_io']['emitted'] && 0 === $application_warnings && 0 === $application_owned_callbacks,
				'Staged tell/read/seek diagnostics must never re-enter an application handler inside an owned row transaction.' );
			trigger_error( 'Application handler restoration marker.', E_USER_NOTICE );
			$assert( 1 === $application_warnings && 0 === $application_owned_callbacks, 'The caller error handler must be restored after success, native failure and thrown cleanup.' );
			if ( 'success' === $case[2] ) {
				$assert( null === $observed && array( $values ) === $native->rows && 1 === $native->commits && 0 === $native->rollbacks,
					'Verified exact bytes and native integer behavior must remain unchanged by the diagnostic boundary.' );
			} else {
				$expected = 'throw' === $case[2] ? 'synthetic_staged_io_exception' : ( 'read' === $case[0] ? 'backup_archive_cell_integrity' : 'backup_restore_row_incomplete' );
				$assert( $expected === $observed && array() === $native->rows && array() === $native->receipts &&
					0 === $native->inserts && 0 === $native->commits && 1 === $native->rollbacks,
					'Native staged read/seek failure must roll back owned work before any new receipt or target insertion.' );
			}
		} finally {
			restore_error_handler(); unset( $GLOBALS['wpai_insert_io'] ); fclose( $source['stream'] );
		}
	}
	echo "issue199-backup-insert: {$checks} checks passed\n";
}
