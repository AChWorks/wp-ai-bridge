<?php
/**
 * Durable administration fences with an independent transactional store double.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support {
	final class Database_Write_Failure extends \RuntimeException {
		public $reason;
		public $details;
		public function __construct( $reason, array $details = array() ) { parent::__construct( $reason ); $this->reason = $reason; $this->details = $details; }
	}
	final class Database_Control_Storage {
		public function __construct( $connection, $budget ) {}
		public function receipt_schema() { return array(); }
	}
	final class Database_Connection {
		public static $rows = array();
		public static $commit_unknown = false;
		public static $rollback_unknown = false;
		public static $commits = 0;
		public $options = 'wp_options';
		private $snapshot;
		public function assert_callback_context() {}
		public function begin_callback_journal() { $this->snapshot = self::$rows; }
		public function table( $table ) { return $table; }
		public function rollback() { if ( null !== $this->snapshot ) { self::$rows = $this->snapshot; $this->snapshot = null; } return ! self::$rollback_unknown; }
		public function commit() {
			++self::$commits;
			$this->snapshot = null;
			if ( self::$commit_unknown ) { self::$commit_unknown = false; throw new Database_Write_Failure( 'database_write_outcome_unknown' ); }
			return true;
		}
		public function run( $sql, $parameters = array(), $sources = array(), $max_rows = 4096 ) {
			if ( 0 === strpos( $sql, 'SELECT ' ) ) {
				$name = $parameters[1];
				$raw = self::$rows[ $name ] ?? null;
				return array( 'rows' => null === $raw ? array() : array( array( 'name' => $name, 'bytes' => (string) strlen( $raw ), 'value' => $raw ) ) );
			}
			if ( 0 === strpos( $sql, 'INSERT ' ) ) {
				if ( isset( self::$rows[ $parameters[0] ] ) ) { throw new Database_Write_Failure( 'database_write_query_failed', array( 'errno' => 1062 ) ); }
				self::$rows[ $parameters[0] ] = $parameters[1];
				return array( 'affected' => 1 );
			}
			if ( 0 === strpos( $sql, 'UPDATE ' ) ) {
				$matches = isset( self::$rows[ $parameters[1] ] ) && self::$rows[ $parameters[1] ] === $parameters[3] && $parameters[1] === $parameters[2];
				if ( $matches ) { self::$rows[ $parameters[1] ] = $parameters[0]; }
				return array( 'affected' => $matches ? 1 : 0 );
			}
			throw new \RuntimeException( 'Unexpected transactional-store operation.' );
		}
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';
	function get_current_blog_id() { return $GLOBALS['journal_blog'] ?? 1; }
	function get_current_network_id() { return 1; }

	use WP_AI_Bridge\Support\Administration_Operation_Journal as Journal;
	use WP_AI_Bridge\Support\Database_Connection as Store;

	$checks = 0;
	$assert = static function ( $condition, $message ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
	$identity = array( 'scope' => 'site', 'blog' => 1, 'network' => 1, 'user' => 1, 'client' => '' );
	$fingerprint = hash( 'sha256', 'request one' );
	$journal = new Journal( 'scheduler', $identity, 65536 );
	$reservation = $journal->reserve( 'journal-first', $fingerprint, array( 'action' => 'schedule', 'target_hash' => hash( 'sha256', 'target' ) ) );
	$assert( ! is_wp_error( $reservation ) && 'prepared' === $reservation['state'] && $reservation['dispatch_allowed'], 'A fresh reservation did not acknowledge prepared state.' );
	$assert( 1 === Store::$commits, 'The initial receipt was not committed before dispatch admission.' );
	$duplicate = ( new Journal( 'scheduler', $identity, 65536 ) )->reserve( 'journal-first', $fingerprint, array() );
	$assert( false === $duplicate['dispatch_allowed'] && null === $duplicate['claim'] && true === $duplicate['historical'], 'A duplicate prepared identity acquired a new dispatch claim.' );
	$conflict = $journal->status( 'journal-first', hash( 'sha256', 'different' ) );
	$assert( is_wp_error( $conflict ) && 'administration_operation_conflict' === $conflict->get_error_code(), 'Changed requests adopted an existing identity.' );
	$assert( true === $journal->arm( $reservation['claim'] ) && 2 === Store::$commits, 'Arming did not acknowledge its own durable commit.' );
	$armed = ( new Journal( 'scheduler', $identity, 65536 ) )->status( 'journal-first' );
	$assert( 'dispatch_armed' === $armed['state'] && null === $armed['receipt']['result'], 'The durable pre-effect fence was not independently visible.' );
	$assert( is_wp_error( $journal->arm( $reservation['claim'] ) ), 'An owned armed claim rearmed.' );
	$completed = $journal->complete( $reservation['claim'], array( 'outcome' => 'native_call_returned', 'native_return' => false ) );
	$assert( 'completed' === $completed['state'] && false === $completed['receipt']['result']['native_return'], 'Native false was lost or interpreted as rollback.' );
	$assert( is_wp_error( $journal->complete( $reservation['claim'], array( 'outcome' => 'unknown' ) ) ), 'Completed claim was rewritten.' );
	$assert( null === $journal->status( 'journal-absent' ), 'Missing receipts were invented.' );
	$other = new Journal( 'filesystem', $identity, 65536 );
	$assert( null === $other->status( 'journal-first' ), 'Receipt purpose isolation was lost.' );
	$prepared = $other->reserve( 'journal-no-dispatch', $fingerprint, array() );
	$assert( is_wp_error( $other->complete( $prepared['claim'], array( 'outcome' => 'committed' ) ) ), 'A prepared receipt falsely completed an effect.' );
	$assert( 'completed' === $other->complete( $prepared['claim'], array( 'outcome' => 'not_dispatched' ) )['state'], 'A known undispatched reservation could not be finalized.' );
	$uncertain = $other->reserve( 'journal-lost-arm', $fingerprint, array() );
	Store::$commit_unknown = true;
	$assert( is_wp_error( $other->arm( $uncertain['claim'] ) ), 'An uncertain arm commit permitted dispatch.' );
	$historical = ( new Journal( 'filesystem', $identity, 65536 ) )->reserve( 'journal-lost-arm', $fingerprint, array() );
	$assert( 'dispatch_armed' === $historical['state'] && ! $historical['dispatch_allowed'], 'A lost acknowledged commit could be retried as a fresh effect.' );
	$GLOBALS['journal_blog'] = 2;
	$assert( is_wp_error( $journal->check_context() ), 'Changed blog adopted an owned journal.' );
	$GLOBALS['journal_blog'] = 1;
	$invalid = $journal->reserve( 'journal-object', $fingerprint, array( 'object' => new class implements JsonSerializable { public function jsonSerialize(): mixed { throw new RuntimeException( 'Never call arbitrary serialization.' ); } } ) );
	$assert( is_wp_error( $invalid ) && 'administration_operation_invalid' === $invalid->get_error_code(), 'Executable metadata object was serialized.' );
	$tiny = new Journal( 'schema', $identity, 32 );
	$assert( is_wp_error( $tiny->reserve( 'journal-budget', $fingerprint, array() ) ), 'An unrepresentable journal exceeded its finite metadata budget.' );
	echo "PASS: administration journal ownership, durable arming, replay and unknown-effect fencing ({$checks} checks).\n";
}
