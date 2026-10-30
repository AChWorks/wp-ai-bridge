<?php
/**
 * Actual exporter-step admission counts, finite continuation and cleanup fences.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support {
	class Permissions {}
	class Administration_Access {
		public function __construct( $permissions ) {}
		public function context() { return array( 'user' => 1, 'blog' => 1, 'network' => 1, 'client' => 'step-test' ); }
		public function same_context( $origin ) { return $origin === $this->context(); }
		public function configuration() { return array( 'revision' => str_repeat( 'a', 64 ), 'backup_tables' => array(), 'restore_schemas' => array( 'step_target' ) ); }
	}
	class Filesystem_Roots {
		public function __construct( $permissions ) {}
		public function resolve( $id, $purpose, $mode ) { return array( 'path' => rtrim( ABSPATH, '/' ) ); }
		public static function within( $path, $root ) { return $path === $root || 0 === strpos( $path, $root . DIRECTORY_SEPARATOR ); }
	}
	class Private_Transfer_Session {
		public static $store;
		public static $stores = array();
		public static function for_administration( $permissions, $purpose, $binding ) { return self::$stores[ $purpose ] ?? self::$store; }
	}
	function microtime( $as_float = false ) { return $GLOBALS['step_clock']; }
}
namespace {
	function wp_upload_dir( $time, $create, $refresh ) { return array( 'basedir' => ABSPATH ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	class WP_Error {
		private $code;
		public function __construct( $code ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
	require __DIR__ . '/../src/Support/class-backup-archive.php';
	require __DIR__ . '/../src/Support/class-backup-operation.php';
	require __DIR__ . '/../src/Support/class-backup-database.php';
	require __DIR__ . '/../src/Support/class-backup-exporter.php';
	require __DIR__ . '/../src/Support/class-backup-restore.php';
	require __DIR__ . '/../src/Support/class-backup-schema.php';
	require __DIR__ . '/../src/Support/class-database-connection.php';
	require __DIR__ . '/../src/Support/class-scoped-filesystem.php';
	require __DIR__ . '/fixtures/issue199-backup-package.php';

	use WP_AI_Bridge\Support\Backup_Archive;
	use WP_AI_Bridge\Support\Backup_Database;
	use WP_AI_Bridge\Support\Backup_Exporter;
	use WP_AI_Bridge\Support\Backup_Restore;
	use WP_AI_Bridge\Support\Administration_Access;
	use WP_AI_Bridge\Support\Permissions;
	use WP_AI_Bridge\Support\Private_Transfer_Session;

	final class WPAI_Backup_Step_Native {
		public $database = 'step_source';
		public $prefix = 'wp_';
		public $mode = 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
		public $original = 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
		public $selected = 'step_source';
		public $ac = '1';
		public $fk = '1';
		public $uq = '1';
		public $guard_reads = 0;
		public $zone = 'SYSTEM';
		public $admissions = 0;
		public $identity_reads = 0;
		public $mode_sets = 0;
		public $zone_sets = 0;
		public $quarantined = false;
		public $ready = true;
		public $dbh = true;
		public $quarantine_calls = 0;
		public $native_after_discard = 0;
		public $fail_quarantine = false;
		public $reject = false;
		public $fail_cleanup = false;
		public $active = false;
		public $events = array();
		public function assert_clean_session() {
			++$this->admissions;
			$this->events[] = 'admit';
			if ( $this->reject ) { throw new \RuntimeException( 'ambient_native_session' ); }
		}
		public function set_backup_sql_mode( $mode ) {
			++$this->mode_sets;
			$this->events[] = $mode === $this->original ? 'restore_mode' : 'enter_mode';
			if ( $this->quarantined ) { ++$this->native_after_discard; throw new \RuntimeException( 'native_quarantined' ); }
			if ( $this->fail_cleanup && $mode === $this->original ) { throw new \RuntimeException( 'cleanup_unproved' ); }
			$this->mode = $mode;
		}
		public function quarantine_backup_session() { ++$this->quarantine_calls; $this->quarantined = true; $this->ready = false; $this->dbh = null; $this->active = false; $this->events[] = 'quarantine'; if ( $this->fail_quarantine ) { throw new \RuntimeException( 'discard_reporting_failed' ); } }
		public function run( $sql, $parameters = array(), $sources = array(), $rows = 4096 ) {
			if ( $this->quarantined ) { ++$this->native_after_discard; throw new \RuntimeException( 'native_quarantined' ); }
			if ( 0 === strpos( $sql, 'SELECT DATABASE() AS db,@@SESSION.sql_mode' ) ) {
				++$this->guard_reads;
				return array( 'rows' => array( array( 'db' => $this->selected, 'sql_mode' => $this->mode, 'time_zone' => $this->zone, 'ac' => $this->ac, 'fk' => $this->fk, 'uq' => $this->uq ) ) );
			}
			if ( 0 === strpos( $sql, 'SELECT @@SESSION.sql_mode AS sql_mode' ) ) {
				++$this->identity_reads;
				return array( 'rows' => array( array( 'sql_mode' => $this->mode, 'time_zone' => $this->zone, 'version' => 'step-native-fixture' ) ) );
			}
			if ( 'SET SESSION time_zone=?' !== $sql ) { throw new \LogicException( 'Unexpected native SQL.' ); }
			++$this->zone_sets;
			$this->zone = $parameters[0];
			$this->active = '+00:00' === $this->zone;
			$this->events[] = $this->active ? 'enter_zone' : 'restore_zone';
			return array( 'rows' => array() );
		}
	}
	/** Stub row inventory; session admission uses the actual Backup_Database. */
	final class WPAI_Backup_Step_Database {
		public $native;
		public $session_database;
		public $ddl_calls = 0;
		public $cell_calls = 0;
		public $insert_calls = 0;
		public function cell( $schema, $group, $column, $offset, $length ) { ++$this->cell_calls; $GLOBALS['step_clock'] += 1; $value = '+00:00' === $this->native->zone ? '2026-10-30 12:00:00' : '2026-10-30 13:00:00'; return array( 'bytes' => strlen( $value ), 'value' => $value, 'digest' => hash( 'sha256', $value ) ); }
		public function insert( $database, $schema, $cells, $source, $group, $operation ) { ++$this->insert_calls; }
		public $schema_calls = 0;
		public $group_calls = 0;
		public $inventory_calls = 0;
		public $schema;
		public $activity;
		public $activity_reads = 0;
		public function ddl( $sql, $operation, $origin ) { if ( ! $this->native->active ) { throw new \RuntimeException( 'ddl_not_admitted' ); } ++$this->ddl_calls; $GLOBALS['step_clock'] += 2; return array( 'outcome' => 'acknowledged' ); }
		public function schema( $table, $database ) { ++$this->schema_calls; return $this->schema; }
		public function next_group( $schema, $after, $database ) { if ( ! $this->native->active ) { throw new \RuntimeException( 'query_not_admitted' ); } ++$this->group_calls; $GLOBALS['step_clock'] += 3; return null; }
		public function assert_inventory( $schema, $tables ) { if ( ! $this->native->active ) { throw new \RuntimeException( 'inventory_not_admitted' ); } ++$this->inventory_calls; }
		public function __construct( $native ) {
			$this->native = $native;
			$r = new \ReflectionClass( Backup_Database::class );
			$this->session_database = $r->newInstanceWithoutConstructor();
			$r->getProperty( 'connection' )->setValue( $this->session_database, $native );
			$r->getProperty( 'budget' )->setValue( $this->session_database, 1048576 );
		}
		public function reset_groups() { $this->session_database->reset_groups(); }
		public function with_session( $session, $callback ) { return $this->session_database->with_session( $session, $callback ); }
		public function assert_session( $session ) { return $this->session_database->assert_session( $session ); }
		public function identity() { return $this->session_database->identity(); }
		public function inventory( $extras ) { return array(); }
		public function activity_digest() { ++$this->activity_reads; return $this->activity; }
	}
	final class WPAI_Backup_Step_Filesystem {
		public $native;
		public $bytes;
		public $reads = 0;
		public $fail_read = false;
		public $advance = 0;
		public $scan = false;
		public $entry = null;
		public $wrapper_counts = false;
		public $on_iterate;
		public $publications = 0;
		public $published_bytes;
		public function status( $root, $purpose, $operation ) { return null; }
		public function apply_verified_source( $root, $parts, $purpose, $expected, $source, $operation ) {
			if ( ! $this->native->active ) { throw new \RuntimeException( 'publication_not_admitted' ); }
			++$this->publications; $this->published_bytes = stream_get_contents( $source['stream'] );
			return array( 'outcome' => 'committed' );
		}
		public function __construct( $native ) { $this->native = $native; $this->bytes = str_repeat( "b\0\xff", 30000 ); }
		public function stat( $root, $parts, $purpose ) {
			return array( 'type' => 'directory', 'identity' => str_repeat( 'b', 64 ), 'components' => array(), 'mode' => 0755 );
		}
		public function with_read_stream( $root, $parts, $purpose, $identity, $callback ) {
			if ( ! $this->native->active ) { throw new \RuntimeException( 'read_outside_admission' ); }
			++$this->reads;
			$GLOBALS['step_clock'] += $this->advance;
			if ( $this->fail_read ) { throw new \RuntimeException( 'source_read_failed' ); }
			$stream = fopen( 'php://memory', 'w+b' );
			try { fwrite( $stream, $this->bytes ); rewind( $stream ); return $callback( $stream ); }
			finally { fclose( $stream ); }
		}
		public function iterate_entries( $root, $parts, $purpose, $identity, $callback, $budget ) {
			if ( ! $this->native->active ) { throw new \RuntimeException( 'scan_outside_admission' ); }
			if ( $this->wrapper_counts ) { $callback( array() ); if ( $this->on_iterate ) { ( $this->on_iterate )(); } return array( 'cursor' => 1, 'complete' => true ); }
			$GLOBALS['step_clock'] += 1;
			$cursor = $budget['cursor'];
			if ( $cursor < 2 ) { return array( 'cursor' => $cursor + 1, 'complete' => false ); }
			if ( null !== $this->entry ) { $callback( $this->entry ); }
			return array( 'cursor' => $cursor, 'complete' => true );
		}
	}
	/** Test double only: production continues to use its existing private backend. */
	final class WPAI_Backup_Step_Store {
		public $native;
		public $state;
		public $revision = 0;
		public $raw = '';
		public $chain;
		public $stream_state = 'open';
		public $appends = 0;
		public $checkpoints = 0;
		public $seals = 0;
		public $fail_append = 0;
		public $fail_checkpoint = 0;
		public $revoke_after_append = false;
		public $denied = false;
		public $corrupt_source = false;
		public $recoveries = 0;
		public $changes = array();
		public $read_advance = 0;
		public $on_checkpoint;
		public $on_source;
		public $is_archive = false;
		public function __construct( $native ) { $this->native = $native; $this->chain = Backup_Archive::initial_chain(); }
		private function denied() { return $this->denied ? new WP_Error( 'transfer_denied' ) : null; }
		public function read_checkpoint( $id ) { return $this->denied() ?? array( 'revision' => $this->revision, 'state' => $this->state ); }
		public function status( $id ) {
			return $this->denied() ?? array( 'state' => $this->stream_state, 'received' => strlen( $this->raw ), 'total' => strlen( $this->raw ), 'sha256' => $this->chain );
		}
		public function append_verified_frame( $id, $offset, $frame, $sha ) {
			if ( null !== ( $error = $this->denied() ) ) { return $error; }
			++$this->appends;
			if ( $this->fail_append === $this->appends ) { return new WP_Error( 'transfer_append_failed' ); }
			if ( $offset !== strlen( $this->raw ) || hash( 'sha256', $frame ) !== $sha ) { throw new \RuntimeException( 'Append identity changed.' ); }
			if ( ! $this->native->active ) { throw new \RuntimeException( 'append_outside_step_session' ); }
			$this->raw .= $frame;
			$this->chain = Backup_Archive::fold( $this->chain, $frame );
			$this->native->events[] = 'append';
			if ( $this->revoke_after_append ) { $this->denied = true; }
			return true;
		}
		public function checkpoint( $id, $state, $revision ) {
			if ( null !== ( $error = $this->denied() ) ) { return $error; }
			++$this->checkpoints;
			if ( $this->fail_checkpoint === $this->checkpoints ) { return new WP_Error( 'transfer_checkpoint_failed' ); }
			if ( $revision !== $this->revision ) { throw new \RuntimeException( 'CAS revision changed.' ); }
			if ( in_array( $state['status'], array( 'sealed', 'verified' ), true ) && $this->native->active ) { throw new \RuntimeException( 'complete_checkpoint_before_cleanup' ); }
			if ( 'table' === $state['phase'] && 'verify' === $state['mode'] ) { ++$this->recoveries; }
			$this->state = json_decode( json_encode( $state ), true, 512, JSON_THROW_ON_ERROR );
			$this->changes[] = $this->state;
			++$this->revision;
			$this->native->events[] = 'checkpoint:' . $state['status'];
			if ( $this->on_checkpoint ) { ( $this->on_checkpoint )(); }
			return array( 'state' => $this->state, 'revision' => $this->revision );
		}
		public function seal_administration_stream( $id, $offset, $chain ) {
			if ( null !== ( $error = $this->denied() ) ) { return $error; }
			++$this->seals;
			if ( $this->native->active || $this->native->quarantined || $this->native->mode !== $this->native->original ||
				'SYSTEM' !== $this->native->zone || strlen( $this->raw ) !== $offset || $this->chain !== $chain ) {
				throw new \RuntimeException( 'Seal preceded proved restoration or changed the stream.' );
			}
			$this->native->events[] = 'seal';
			$this->stream_state = 'verified';
			return true;
		}
		public function with_administration_source( $id, $callback ) {
			$GLOBALS['step_clock'] += $this->read_advance;
			if ( $this->on_source ) { ( $this->on_source )(); }
			$stream = fopen( 'php://memory', 'w+b' );
			try { fwrite( $stream, $this->corrupt_source ? str_repeat( 'x', strlen( $this->raw ) ) : $this->raw ); rewind( $stream ); return $callback( array( 'stream' => $stream, 'bytes' => strlen( $this->raw ), 'sha256' => $this->chain, 'integrity' => Backup_Archive::CHAIN_FORMAT ) ); }
			finally { fclose( $stream ); }
		}
	}

	final class WPAI_Backup_Step_Stage {
		public $job;
		public $raw = array();
		public $chain = array();
		public $appends = 0;
		public $cancels = 0;
		public $fail_cancel = false;
		public $on_source;
		public function begin_administration_stream( $maximum, $policy ) { $id = 'stage-' . count( $this->raw ); $this->raw[ $id ] = ''; $this->chain[ $id ] = Backup_Archive::initial_chain(); return array( 'id' => $id ); }
		public function append_verified_frame( $id, $offset, $bytes, $sha ) { if ( strlen( $this->raw[ $id ] ) !== $offset || hash( 'sha256', $bytes ) !== $sha ) { throw new \RuntimeException( 'stage_offset_changed' ); } ++$this->appends; $this->raw[ $id ] .= $bytes; $this->chain[ $id ] = Backup_Archive::fold( $this->chain[ $id ], $bytes ); return true; }
		public function seal_administration_stream( $id, $offset, $chain ) { if ( strlen( $this->raw[ $id ] ) !== $offset || $chain !== $this->chain[ $id ] ) { throw new \RuntimeException( 'stage_changed' ); } return true; }
		public function with_administration_source( $id, $callback ) { if ( $this->on_source ) { ( $this->on_source )(); } $stream = fopen( 'php://memory', 'w+b' ); try { fwrite( $stream, $this->raw[ $id ] ); rewind( $stream ); return $callback( array( 'stream' => $stream, 'bytes' => strlen( $this->raw[ $id ] ), 'sha256' => $this->chain[ $id ], 'integrity' => Backup_Archive::CHAIN_FORMAT ) ); } finally { fclose( $stream ); } }
		public function cancel( $id ) { if ( $this->fail_cancel ) { return new WP_Error( 'transfer_cleanup_uncertain' ); } if ( ( $this->job->state['release_stage'] ?? null ) !== $id || isset( $this->job->state['file_stage'] ) ) { throw new \RuntimeException( 'stage_released_before_cursor' ); } ++$this->cancels; unset( $this->raw[ $id ] ); return true; }
	}

	$checks = 0;
	$assert = static function ( $condition, $message ) use ( &$checks ) {
		++$checks;
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	};
	$error = static function ( $callback ) {
		try { $callback(); } catch ( \Throwable $caught ) { return $caught->getMessage(); }
		return null;
	};
	$fixture = sys_get_temp_dir() . '/wpai-backup-step-' . bin2hex( random_bytes( 8 ) );
	mkdir( $fixture, 0700 );
	define( 'ABSPATH', $fixture . '/' );
	$make = static function ( $phase = 'file', $bytes = 25000, $seconds = 5 ) {
		$GLOBALS['step_clock'] = 0;
		$native = new WPAI_Backup_Step_Native();
		$fs = new WPAI_Backup_Step_Filesystem( $native );
		$db = new WPAI_Backup_Step_Database( $native );
		$store = new WPAI_Backup_Step_Store( $native );
		Private_Transfer_Session::$store = $store;
		$r = new \ReflectionClass( Backup_Exporter::class );
		$exporter = $r->newInstanceWithoutConstructor();
		$permissions = new Permissions();
		foreach ( array( 'permissions' => $permissions, 'access' => new Administration_Access( $permissions ), 'filesystem' => $fs,
			'database' => $db, 'policy' => array( 'step_bytes' => $bytes, 'step_seconds' => $seconds, 'request' => 131072 ),
			'budget' => 16384 ) as $name => $value ) { $r->getProperty( $name )->setValue( $exporter, $value ); }
		$plan = $exporter->plan( array( 'installation' ) );
		$state = array( 'kind' => 'export', 'status' => 'capturing', 'phase' => $phase, 'mode' => 'capture', 'plan' => $plan,
			'seq' => 0, 'offset' => 0, 'chain' => Backup_Archive::initial_chain(), 'root_index' => 0, 'table_index' => 0,
			'chunk_bytes' => 4096, 'files_digest' => hash( 'sha256', 'wpai-backup-files-v1' ),
			'db_digest' => hash( 'sha256', 'wpai-backup-database-v1' ), 'file_offset' => 0, 'file_chain' => Backup_Archive::initial_chain(),
			'file' => array( 'bytes' => strlen( $fs->bytes ), 'components' => array( base64_encode( 'payload' ) ), 'identity' => str_repeat( 'c', 64 ) ),
			'stack' => array( array( 'components' => array(), 'identity' => str_repeat( 'b', 64 ), 'after' => null, 'scan_cursor' => 0, 'candidate' => null ) ) );
		if ( 'root' === $phase ) {
			$state['mode'] = 'verify';
			$state['status'] = 'verifying_source';
			$state['root_index'] = 1;
			$state['expected_db'] = $state['db_digest'];
			$state['expected_files'] = $state['files_digest'];
		}
		$store->state = $state;
		return array( $exporter, $native, $fs, $store, $r );
	};
	try {
		list( $exporter, $native, $fs, $store, $r ) = $make();
		$db = $r->getProperty( 'database' )->getValue( $exporter );
		$first = $exporter->step( 'fixture' );
		$assert( 1 === $native->admissions && 2 === $native->mode_sets && 2 === $native->zone_sets && $fs->reads > 1 && 0 === $native->guard_reads && 2 === $db->activity_reads,
			'Many file frames must use one actual Backup_Database admission and one setter/restoration pair per step.' );
		$assert( ! $first['complete'] && $first['bytes'] >= 25000 && $first['bytes'] < 25000 + 16384 &&
			$store->checkpoints === $store->appends && $store->revision === $store->checkpoints,
			'Finite byte overshoot, per-record checkpoints and CAS accounting must remain bounded and unchanged.' );
		$previous_bytes = $first['bytes'];
		$second = $exporter->step( 'fixture' );
		$assert( 2 === $native->admissions && 4 === $native->mode_sets && 4 === $native->zone_sets &&
			$second['bytes'] > $previous_bytes && $store->state['file_offset'] === $fs->reads * 4096 && 3 === $db->activity_reads,
			'A continuation must admit and restore its own session and continue exact byte offsets.' );
		$stream = fopen( 'php://memory', 'w+b' );
		fwrite( $stream, $store->raw ); rewind( $stream );
		$payload = ''; $seq = 0;
		while ( null !== ( $frame = Backup_Archive::read( $stream, 16384 ) ) ) {
			$assert( $seq++ === $frame['record']['seq'] && 'file_chunk' === $frame['record']['type'], 'Frame sequence/type changed across step continuation.' );
			$payload .= $frame['payload'];
		}
		fclose( $stream );
		$assert( $payload === substr( $fs->bytes, 0, strlen( $payload ) ), 'Opaque chunk bytes changed across bounded steps.' );

		list( $exporter, $native, $fs, $store ) = $make( 'file', 1000000, 2 );
		$fs->advance = 1;
		$exporter->step( 'fixture' );
		$assert( 2 === $fs->reads && 2 === $store->checkpoints && 1 === $native->admissions && 2 === $native->mode_sets,
			'Existing cooperative deadline must stop the loop without per-record session resets.' );
		list( $exporter, $native, $fs, $store ) = $make( 'entries', 1000000, 2 );
		$exporter->step( 'fixture' );
		$assert( 2 === $store->checkpoints && 0 === $store->appends && 2 === $store->state['stack'][0]['scan_cursor'] &&
			1 === $native->admissions && 2 === $native->mode_sets,
			'Cursor-only iterations must retain acknowledgement/checkpoint progress within one bounded admission.' );

		foreach ( array( 'read', 'append', 'checkpoint', 'authority' ) as $failure ) {
			list( $exporter, $native, $fs, $store ) = $make();
			if ( 'read' === $failure ) { $fs->fail_read = true; $expected = 'source_read_failed'; }
			if ( 'append' === $failure ) { $store->fail_append = 2; $expected = 'transfer_append_failed'; }
			if ( 'checkpoint' === $failure ) { $store->fail_checkpoint = 2; $expected = 'transfer_checkpoint_failed'; }
			if ( 'authority' === $failure ) { $store->revoke_after_append = true; $expected = 'transfer_denied'; }
			$observed = $error( static function () use ( $exporter ) { $exporter->step( 'fixture' ); } );
			$assert( $expected === $observed && 1 === $native->admissions && 2 === $native->mode_sets && 2 === $native->zone_sets &&
				$native->mode === $native->original && 'SYSTEM' === $native->zone && ! $native->quarantined && 0 === $store->seals,
				'Functional/authorization errors must retain their evidence and restore exactly once without a complete seal.' );
		}
		list( $exporter, $native, $fs, $store ) = $make();
		$native->reject = true;
		$assert( 'ambient_native_session' === $error( static function () use ( $exporter ) { $exporter->step( 'fixture' ); } ) &&
			0 === $native->mode_sets && 0 === $native->zone_sets && 0 === $fs->reads && 0 === $store->appends && 0 === $store->checkpoints &&
			! $native->quarantined && $native->ready && true === $native->dbh && 0 === $native->quarantine_calls,
			'Rejected caller-owned native sessions must receive no backup setter, callback, append or cleanup.' );

		list( $exporter, $native, $fs, $store ) = $make( 'root' );
		$sealed = $exporter->step( 'fixture' );
		$assert( $sealed['complete'] && 'sealed' === $sealed['state'] && 1 === $store->seals && 1 === $store->checkpoints &&
			array_search( 'restore_zone', $native->events, true ) < array_search( 'seal', $native->events, true ) &&
			'checkpoint:sealed' === end( $native->events ), 'Footer publication must restore before seal and only then persist complete.' );
		$exporter->step( 'fixture' );
		$assert( 1 === $native->admissions && 1 === $store->seals, 'An acknowledged complete receipt must not re-enter native admission.' );

		foreach ( array( false, true ) as $already_verified ) {
			list( $exporter, $native, $fs, $store, $r ) = $make( 'root' );
			if ( $already_verified ) {
				$copy = $store->state; $args = array( &$copy );
				$record = $r->getMethod( 'next' )->invokeArgs( $exporter, $args ); $record['record']['seq'] = 0;
				$store->raw = Backup_Archive::encode( $record['record'], $record['payload'], 16384 );
				$store->chain = Backup_Archive::fold( $store->chain, $store->raw ); $store->stream_state = 'verified';
			}
			$native->fail_cleanup = true;
			$assert( 'cleanup_unproved' === $error( static function () use ( $exporter ) { $exporter->step( 'fixture' ); } ) &&
				$native->quarantined && 0 === $store->seals && 0 === $store->checkpoints && 'verifying_source' === $store->state['status'] &&
				0 === $store->state['offset'], 'Unproved cleanup must quarantine and prevent a complete receipt even when a footer already exists.' );
		}
		list( $exporter, $native, $fs, $store, $r ) = $make( 'root' );
		$copy = $store->state; $args = array( &$copy );
		$record = $r->getMethod( 'next' )->invokeArgs( $exporter, $args ); $record['record']['seq'] = 0;
		$store->raw = Backup_Archive::encode( $record['record'], $record['payload'], 16384 );
		$store->chain = Backup_Archive::fold( $store->chain, $store->raw ); $store->stream_state = 'verified';
		$replayed = $exporter->step( 'fixture' );
		$assert( $replayed['complete'] && 0 === $store->appends && 1 === $store->seals &&
			array_search( 'restore_zone', $native->events, true ) < array_search( 'seal', $native->events, true ),
			'An already-verified exact footer must recover without duplicate append and restore before its completion receipt.' );

		list( $exporter, $native, $fs, $store, $r ) = $make( 'root' );
		$copy = $store->state; $args = array( &$copy );
		$record = $r->getMethod( 'next' )->invokeArgs( $exporter, $args ); $record['record']['seq'] = 0;
		$store->raw = Backup_Archive::encode( $record['record'], $record['payload'], 16384 );
		$store->chain = Backup_Archive::fold( $store->chain, $store->raw ); $store->stream_state = 'verified'; $store->corrupt_source = true;
		$assert( 'backup_archive_changed' === $error( static function () use ( $exporter ) { $exporter->step( 'fixture' ); } ) &&
			0 === $store->seals && ! $native->active, 'Matching footer lengths/chains must still compare actual immutable bytes and restore on mismatch.' );

		list( $exporter, $native, $fs, $store ) = $make( 'root', 1000000, 2 );
		// An unsealed extra footer with no cursor acknowledgement must retain
		// the existing full-source rewind before it can become complete.
		$store->raw = 'unacknowledged-footer'; $store->chain = Backup_Archive::fold( $store->chain, $store->raw );
		$recovery = $exporter->step( 'fixture' );
		$assert( ! $recovery['complete'] && $store->recoveries >= 1 && 1 === $native->admissions && 0 === $store->seals &&
			'verify' === $store->state['mode'], 'Open footer/crash recovery must retain source re-verification and withhold completion.' );

		$restore_make = static function ( array $records = array(), $phase = 'apply', $seconds = 5 ) use ( $make ) {
			Private_Transfer_Session::$stores = array();
			list( $exporter, $native, $fs, $job, $er ) = $make( 'file', 1000000, $seconds );
			$db = $er->getProperty( 'database' )->getValue( $exporter );
			$archive = new WPAI_Backup_Step_Store( $native );
			foreach ( $records as $seq => $item ) {
				$item[0]['seq'] = $seq;
				$frame = Backup_Archive::encode( $item[0], $item[1] ?? '', 16384 );
				$archive->raw .= $frame; $archive->chain = Backup_Archive::fold( $archive->chain, $frame );
			}
			$stage = new WPAI_Backup_Step_Stage(); $stage->job = $job;
			Private_Transfer_Session::$stores = array( 'backup_state' => $job, 'backup_archive' => $archive, 'backup_input' => $stage );
			$r = new \ReflectionClass( Backup_Restore::class ); $restore = $r->newInstanceWithoutConstructor();
			$permissions = new Permissions();
			foreach ( array( 'permissions' => $permissions, 'access' => new Administration_Access( $permissions ), 'filesystem' => $fs,
				'database' => $db, 'policy' => array( 'step_bytes' => 1000000, 'step_seconds' => $seconds, 'file' => 1000000 ),
				'budget' => 16384 ) as $name => $value ) { $r->getProperty( $name )->setValue( $restore, $value ); }
			$plan = $job->state['plan'];
			$job->state = array( 'kind' => 'restore', 'status' => 'restoring', 'phase' => $phase,
				'origin' => $plan['context'], 'configuration_revision' => $plan['configuration_revision'], 'target_schema' => 'step_target',
				'target_root' => 'restore', 'target_root_identity' => str_repeat( 'b', 64 ), 'schemas' => array(), 'plan_hash' => str_repeat( 'd', 64 ),
				'archive_id' => 'archive-fixture', 'archive_purpose' => 'backup_archive',
				'archive' => array( 'bytes' => strlen( $archive->raw ), 'sha256' => $archive->chain, 'integrity' => Backup_Archive::CHAIN_FORMAT ),
				'header' => array( 'plan' => $plan ), 'offset' => 0, 'seq' => 0, 'chain' => Backup_Archive::initial_chain(),
				'current_root' => 'installation' );
			return array( $restore, $native, $fs, $job, $archive, $stage, $db, $r );
		};
		$file_bytes = str_repeat( "r\0\xff", 2000 );
		$file_records = array(
			array( array( 'type' => 'file', 'components' => array( base64_encode( 'payload' ) ), 'mode' => 0640,
				'bytes' => strlen( $file_bytes ), 'chunk_bytes' => 3000 ) ),
			array( array( 'type' => 'file_chunk', 'offset' => 0 ), substr( $file_bytes, 0, 3000 ) ),
			array( array( 'type' => 'file_chunk', 'offset' => 3000 ), substr( $file_bytes, 3000 ) ),
			array( array( 'type' => 'file_end' ) ),
		);
		list( $restore, $native, $fs, $job, $archive, $stage ) = $restore_make( $file_records, 'apply', 4 );
		$archive->read_advance = 1;
		$applied = $restore->step( 'fixture' );
		$assert( ! $applied['complete'] && 1 === $native->admissions && 2 === $native->mode_sets && 2 === $native->zone_sets &&
			2 === $stage->appends && 1 === $fs->publications && $file_bytes === $fs->published_bytes && 4 === $job->state['seq'],
			'Actual restore file frames must use one step admission, preserve exact staged bytes and obey the existing deadline.' );
		$assert( 1 === $stage->cancels && ! isset( $job->state['release_stage'] ) && ! isset( $job->state['file_stage'] ) &&
			5 === $job->checkpoints, 'Stage cleanup must remain after its durable cursor and persist removal separately.' );

		list( $restore, $native, $fs, $job, $archive, $stage ) = $restore_make( $file_records, 'apply', 2 );
		$archive->read_advance = 1; $restore->step( 'fixture' );
		$assert( 2 === $job->state['seq'] && 1 === $stage->appends && 0 === $stage->cancels, 'An incomplete stage must survive bounded restore continuation.' );
		$restore->step( 'fixture' );
		$assert( 2 === $native->admissions && 4 === $native->mode_sets && 4 === $native->zone_sets && 1 === $fs->publications &&
			$file_bytes === $fs->published_bytes && 1 === $stage->cancels, 'The next restore step must admit independently without replaying staged bytes or effects.' );

		list( $restore, $native, $fs, $job, $archive, $stage ) = $restore_make( $file_records, 'apply', 4 );
		$archive->read_advance = 1; $job->fail_checkpoint = 4;
		$assert( 'transfer_checkpoint_failed' === $error( static function () use ( $restore ) { $restore->step( 'fixture' ); } ) &&
			1 === $fs->publications && 0 === $stage->cancels && 3 === $job->state['seq'] && 'SYSTEM' === $native->zone,
			'A post-effect checkpoint failure must restore the session and retain staged bytes/receipt position without pretending the publication rolled back.' );
		list( $restore, $native, $fs, $job, $archive, $stage ) = $restore_make( $file_records, 'apply', 4 );
		$archive->read_advance = 1; $stage->fail_cancel = true;
		$assert( 'backup_stage_cleanup_pending' === $error( static function () use ( $restore ) { $restore->step( 'fixture' ); } ) &&
			4 === $job->state['seq'] && isset( $job->state['release_stage'] ) && 1 === $fs->publications && 'SYSTEM' === $native->zone,
			'A staged cleanup failure must retain its durable completed effect/cursor and truthful pending cleanup.' );

		list( $restore, $native, $fs, $job, $archive, $stage, $db ) = $restore_make( array(), 'constraints', 5 );
		$ddl = 'CREATE TABLE `wp_child` (`id` bigint NOT NULL,`parent` bigint DEFAULT NULL,PRIMARY KEY (`id`),KEY `parent` (`parent`),CONSTRAINT `fk_self` FOREIGN KEY (`parent`) REFERENCES `wp_child` (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
		$table = base64_encode( 'wp_child' ); $hash = hash( 'sha256', $ddl );
		$packed = array( 'table' => $table, 'ddl' => base64_encode( $ddl ), 'hash' => $hash,
			'columns' => array( array( 'name' => base64_encode( 'id' ), 'type' => 'bigint', 'charset' => null ),
				array( 'name' => base64_encode( 'parent' ), 'type' => 'bigint', 'charset' => null ) ) );
		$job->state['header']['plan']['tables'] = array( $table ); $job->state['schemas'] = array( $table => $packed );
		$job->state['constraint_table'] = 0; $job->state['constraint_index'] = 0;
		$db->schema = Backup_Exporter::unpack_schema( $packed );
		$job->state['footer']['database_digest'] = hash( 'sha256', hash( 'sha256', 'wpai-backup-database-v1' ) . ':table:' . $table . ':' . $hash );
		$restore->step( 'fixture' );
		$assert( 1 === $native->admissions && 2 === $native->mode_sets && 2 === $native->zone_sets &&
			1 === $db->ddl_calls && 1 === $db->schema_calls && 1 === $db->group_calls && 1 === $job->state['verify_table'],
			'Constraints and schema/row verification must share the admitted step without nested guards or altered progression.' );

		foreach ( array( false, true ) as $fail_cleanup ) {
			list( $restore, $native, $fs, $job, $archive, $stage, $db ) = $restore_make( array( array( array( 'type' => 'footer' ) ) ), 'verify_files' );
			$job->state['status'] = 'verifying_restore'; $fs->wrapper_counts = true; $native->fail_cleanup = $fail_cleanup;
			if ( $fail_cleanup ) {
				$assert( 'cleanup_unproved' === $error( static function () use ( $restore ) { $restore->step( 'fixture' ); } ) &&
					$native->quarantined && 0 === $job->checkpoints && 'verifying_restore' === $job->state['status'] &&
					0 === $job->state['offset'], 'Restore verification must not persist complete after failed native cleanup.' );
			} else {
				$verified = $restore->step( 'fixture' );
				$assert( $verified['complete'] && $verified['restore_verified'] && 1 === $native->admissions && 1 === $db->inventory_calls &&
					1 === $job->checkpoints && ! $native->active && 'verified' === $job->state['status'] &&
					array_search( 'restore_zone', $native->events, true ) < array_search( 'checkpoint:verified', $native->events, true ),
					'Complete destination verification must restore the native session before its durable verified receipt.' );
				$restore->step( 'fixture' );
				$assert( 1 === $native->admissions && 1 === $job->checkpoints, 'A complete restore receipt must not admit/replay its native session.' );
			}
		}
		list( $restore, $native, $fs, $job ) = $restore_make( $file_records );
		$native->reject = true;
		$assert( 'ambient_native_session' === $error( static function () use ( $restore ) { $restore->step( 'fixture' ); } ) &&
			0 === $native->mode_sets && 0 === $native->zone_sets && 0 === $job->checkpoints && 0 === $fs->publications &&
			! $native->quarantined && $native->ready && true === $native->dbh && 0 === $native->quarantine_calls,
			'Restore must retain caller-owned native preconditions before any admitted effect or staged processing.' );
		list( $restore, $native, $fs, $job ) = $restore_make( $file_records );
		unset( $job->state['header'] );
		$assert( 'backup_restore_checkpoint_invalid' === $error( static function () use ( $restore ) { $restore->step( 'fixture' ); } ) &&
			0 === $native->admissions && 0 === $job->checkpoints, 'A restore checkpoint without its validated header must never select native session settings.' );

		foreach ( array( '', 'wrong_cell_sha' ) as $fault ) {
			list( $restore, $native, $fs, $job, $archive ) = $restore_make();
			$package = WPAI_Backup_Package_Fixture::make( $fault, 8193 );
			$archive->raw = implode( '', $package['frames'] ); $archive->chain = $package['chain'];
			$job->state['archive'] = array( 'bytes' => strlen( $archive->raw ), 'sha256' => $archive->chain, 'integrity' => Backup_Archive::CHAIN_FORMAT );
			$job->state['status'] = 'preflighting'; $job->state['phase'] = 'preflight'; unset( $job->state['header'] );
			$job->state += array( 'grammar' => 'header', 'table_index' => 0, 'root_index' => 0, 'stack' => array(),
				'db_digest' => hash( 'sha256', 'wpai-backup-database-v1' ), 'files_digest' => hash( 'sha256', 'wpai-backup-files-v1' ) );
			if ( '' === $fault ) {
				$ready = $restore->step( 'fixture' );
				$assert( 'ready' === $ready['state'] && ! $ready['complete'] && isset( $job->state['header'] ) && 0 === $native->admissions &&
					0 === $native->mode_sets && 0 === $native->guard_reads && 0 === $fs->publications, 'Complete valid package preflight must reach ready without native session admission or effects.' );
				$restore->step( 'fixture' );
				$assert( 0 === $native->admissions, 'Ready requires separate begin approval and cannot silently admit restoration.' );
			} else {
				$assert( 'backup_archive_cell_integrity' === $error( static function () use ( $restore ) { $restore->step( 'fixture' ); } ) &&
					0 === $native->admissions && 0 === $native->mode_sets && 0 === $fs->publications,
					'Malformed untrusted cell settings/data must reject in preflight before any native admission/effects.' );
			}
		}

		$timestamp_ddl = 'CREATE TABLE `wp_session_cells` (`first` timestamp NULL DEFAULT NULL,`second` timestamp NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
		$timestamp_packed = array( 'table' => base64_encode( 'wp_session_cells' ), 'ddl' => base64_encode( $timestamp_ddl ), 'hash' => hash( 'sha256', $timestamp_ddl ),
			'columns' => array( array( 'name' => base64_encode( 'first' ), 'type' => 'timestamp', 'charset' => null ),
				array( 'name' => base64_encode( 'second' ), 'type' => 'timestamp', 'charset' => null ) ) );
		foreach ( array( 'zone', 'mode', 'selected', 'ac', 'fk', 'uq' ) as $changed ) {
			Private_Transfer_Session::$stores = array();
			list( $exporter, $native, $fs, $store, $r ) = $make( 'cell', 1000000, 2 );
			$db = $r->getProperty( 'database' )->getValue( $exporter );
			$store->state['schema'] = $timestamp_packed; $store->state['column'] = 0; $store->state['cell_offset'] = 0;
			$store->state['group'] = array( 'digest' => str_repeat( 'e', 64 ), 'multiplicity' => '1' );
			$store->on_checkpoint = static function () use ( $native, $changed ) {
				if ( 'zone' === $changed ) { $native->zone = '+01:00'; }
				elseif ( 'mode' === $changed ) { $native->mode .= ',STRICT_TRANS_TABLES'; }
				else { $native->$changed = 'selected' === $changed ? 'other_schema' : '0'; }
			};
			$observed = $error( static function () use ( $exporter ) { $exporter->step( 'fixture' ); } );
			$discard = ! in_array( $changed, array( 'mode', 'zone' ), true );
			$assert( 'backup_native_session_changed' === $observed && 1 === $db->cell_calls && 1 === $store->checkpoints &&
				0 === $store->seals && 2 === $native->guard_reads && 0 === $native->native_after_discard &&
				( $discard ? $native->quarantined && ! $native->ready && null === $native->dbh && 1 === $native->quarantine_calls &&
					1 === $native->mode_sets && 1 === $native->zone_sets : ! $native->quarantined && $native->ready && true === $native->dbh &&
					$native->mode === $native->original && 'SYSTEM' === $native->zone && 2 === $native->mode_sets && 2 === $native->zone_sets ),
				'A checkpoint mutation must stop before dependent reads: DB/flags discard without cleanup SQL; mode/TZ restore the proven original session.' );
		}
		list( $restore, $native, $fs, $job, $archive, $stage, $db ) = $restore_make( array( array( array( 'type' => 'table', 'table' => $timestamp_packed['table'] ) ) ), 'apply', 1 );
		$archive->read_advance = 1; $job->state['schemas'] = array( $timestamp_packed['table'] => $timestamp_packed );
		$archive->on_source = static function () use ( $native ) { $native->zone = '+01:00'; };
		$assert( 'backup_native_session_changed' === $error( static function () use ( $restore ) { $restore->step( 'fixture' ); } ) &&
			0 === $db->ddl_calls && 0 === $job->checkpoints && 2 === $native->mode_sets && 'SYSTEM' === $native->zone,
			'A fresh archive authority/source callback must not change settings before dependent apply DDL.' );

		list( $restore, $native, $fs, $job, $archive, $stage, $db ) = $restore_make( array( array( array( 'type' => 'row_end' ) ) ), 'apply', 1 );
		$archive->read_advance = 1;
		$id = $stage->begin_administration_stream( 1, array() )['id'];
		$job->state += array( 'current_table' => $timestamp_packed['table'], 'row_stage' => $id, 'row_bytes' => 0,
			'row_chain' => Backup_Archive::initial_chain(), 'row_occurrence' => 0, 'row_count' => 1, 'row_cells' => array(), 'row_group' => str_repeat( 'e', 64 ) );
		$job->state['schemas'] = array( $timestamp_packed['table'] => $timestamp_packed );
		$stage->on_source = static function () use ( $native ) { $native->zone = '+01:00'; };
		$assert( 'backup_native_session_changed' === $error( static function () use ( $restore ) { $restore->step( 'fixture' ); } ) &&
			0 === $db->insert_calls && 0 === $job->checkpoints && 0 === $stage->cancels && 'SYSTEM' === $native->zone,
			'A nested row-stage source callback must be checked again immediately before timestamp insertion.' );

		list( $restore, $native, $fs, $job, $archive, $stage, $db ) = $restore_make( array( array( array( 'type' => 'footer' ) ) ), 'verify_files' );
		$job->state['status'] = 'verifying_restore'; $fs->wrapper_counts = true;
		$fs->on_iterate = static function () use ( $native ) { $native->mode .= ',STRICT_TRANS_TABLES'; };
		$assert( 'backup_native_session_changed' === $error( static function () use ( $restore ) { $restore->step( 'fixture' ); } ) &&
			0 === $db->inventory_calls && 0 === $job->checkpoints && 'verifying_restore' === $job->state['status'] && 'SYSTEM' === $native->zone,
			'Terminal filesystem authority callbacks must be followed by a session check before native inventory/completion.' );

		list( $restore, $native, $fs, $job, $archive, $stage ) = $restore_make( $file_records, 'apply', 4 );
		$archive->read_advance = 1;
		$archive->on_source = static function () use ( $native ) { $native->mode = implode( ',', array_reverse( explode( ',', $native->mode ) ) ); };
		$restored_order = $restore->step( 'fixture' );
		$assert( ! $restored_order['complete'] && 1 === $fs->publications && $file_bytes === $fs->published_bytes &&
			2 === $native->mode_sets && 2 === $native->zone_sets && 5 === $native->guard_reads,
			'Equivalent native mode ordering must pass the combined readonly samples without extra SETs.' );
		list( $restore, $native, $fs, $job, $archive, $stage ) = $restore_make( $file_records, 'apply', 4 );
		$archive->read_advance = 1;
		$stage->on_source = static function () use ( $native ) { $native->zone = '+01:00'; };
		$assert( 'backup_native_session_changed' === $error( static function () use ( $restore ) { $restore->step( 'fixture' ); } ) &&
			0 === $fs->publications && 0 === $stage->cancels && 3 === $job->checkpoints && 'SYSTEM' === $native->zone,
			'A nested file-stage source callback must be checked again before journalled publication.' );

		foreach ( array( 'constraints', 'verify_database' ) as $phase ) {
			list( $restore, $native, $fs, $job, $archive, $stage, $db ) = $restore_make( array(), $phase, 5 );
			$job->state['header']['plan']['tables'] = array( $table ); $job->state['schemas'] = array( $table => $packed );
			$job->state += array( 'constraint_table' => 0, 'constraint_index' => 0, 'verify_table' => 0, 'verify_after' => '',
				'verify_db' => hash( 'sha256', 'wpai-backup-database-v1' ), 'verify_schema_started' => false );
			$db->schema = Backup_Exporter::unpack_schema( $packed );
			$job->state['footer']['database_digest'] = hash( 'sha256', hash( 'sha256', 'wpai-backup-database-v1' ) . ':table:' . $table . ':' . $hash );
			$job->on_checkpoint = static function () use ( $native ) { $native->zone = '+01:00'; };
			$assert( 'backup_native_session_changed' === $error( static function () use ( $restore ) { $restore->step( 'fixture' ); } ) &&
				1 === $job->checkpoints && 2 === $native->guard_reads && 2 === $native->mode_sets && 0 === $db->inventory_calls &&
				( 'constraints' === $phase ? 1 === $db->ddl_calls && 0 === $db->schema_calls : 1 === $db->schema_calls && 1 === $db->group_calls ),
				'Constraint/verification continuation must stop after a checkpoint SESSION mutation before further dependent native work.' );
		}
		Private_Transfer_Session::$stores = array();
		list( $exporter, $native, $fs, $store, $r ) = $make( 'cell', 1000000, 2 );
		$db = $r->getProperty( 'database' )->getValue( $exporter );
		$store->state['schema'] = $timestamp_packed; $store->state['column'] = 0; $store->state['cell_offset'] = 0;
		$store->state['group'] = array( 'digest' => str_repeat( 'e', 64 ), 'multiplicity' => '1' );
		$store->on_checkpoint = static function () use ( $native ) { $native->zone = '+01:00'; };
		$native->fail_cleanup = true;
		$assert( 'cleanup_unproved' === $error( static function () use ( $exporter ) { $exporter->step( 'fixture' ); } ) &&
			1 === $db->cell_calls && 1 === $store->checkpoints && $native->quarantined && 0 === $store->seals,
			'An invariant mismatch followed by unproved cleanup must retain quarantine and never replay or report completion.' );

		Private_Transfer_Session::$stores = array();
		list( $exporter, $native, $fs, $store, $r ) = $make( 'cell', 1000000, 2 );
		$db = $r->getProperty( 'database' )->getValue( $exporter );
		$store->state['schema'] = $timestamp_packed; $store->state['column'] = 0; $store->state['cell_offset'] = 0;
		$store->state['group'] = array( 'digest' => str_repeat( 'e', 64 ), 'multiplicity' => '1' );
		$store->on_checkpoint = static function () use ( $native ) { $native->fk = '0'; };
		$native->fail_quarantine = true;
		$assert( 'backup_native_session_changed' === $error( static function () use ( $exporter ) { $exporter->step( 'fixture' ); } ) &&
			1 === $db->cell_calls && 1 === $native->quarantine_calls && ! $native->ready && null === $native->dbh &&
			0 === $native->native_after_discard && 1 === $native->mode_sets && 1 === $native->zone_sets && 0 === $store->seals,
			'Discard/reporting failure must retain the primary drift error without a second cleanup attempt or complete receipt.' );

		foreach ( array( 'file', 'root' ) as $phase ) {
			Private_Transfer_Session::$stores = array();
			list( $exporter, $native, $fs, $store, $r ) = $make( $phase );
			$db = $r->getProperty( 'database' )->getValue( $exporter ); $db->activity = str_repeat( 'a', 64 );
			if ( 'root' === $phase ) { $store->raw = 'unacknowledged-footer'; }
			$assert( 'backup_source_stale' === $error( static function () use ( $exporter ) { $exporter->step( 'fixture' ); } ) &&
				2 === $db->activity_reads && 0 === $store->appends && 0 === $store->checkpoints && 0 === $store->seals &&
				0 === $fs->reads && 0 === $store->recoveries && 2 === $native->mode_sets && 2 === $native->zone_sets &&
				! $native->quarantined && 'SYSTEM' === $native->zone,
				'Known Activity drift must reject before new frames, cursor checkpoints or footer-recovery work and still restore the admitted session.' );
		}
	} finally { rmdir( $fixture ); }
	echo "issue199-backup-step: {$checks} checks passed\n";
}
