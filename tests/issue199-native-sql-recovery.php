<?php
/**
 * Native-account preparation consumption and conditional recovery regressions.
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
		public function __construct( $db, $budget ) {}
		public function receipt_schema() {}
	}
	final class Operation_Content_Limits {
		public static function effective_memory_bytes() { return 268435456; }
	}
	final class Private_Transfer_Session {
		public static $cancelled = array();
		public function cancel( $id ) { self::$cancelled[] = $id; return true; }
		public function status( $id ) { return in_array( $id, self::$cancelled, true ) ? new \WP_Error( 'transfer_invalid', 'Disposed result.' ) : array( 'state' => 'verified' ); }
		public static function for_administration( $permissions, $purpose, $binding ) { return new self(); }
		public function begin_administration_stream( $max ) { return array( 'id' => str_repeat( 'b', 64 ) ); }
		public function with_administration_writer( $id, $callback ) { return array( 'receipt' => array( 'id' => $id, 'sha256' => hash( 'sha256', 'result' ), 'bytes' => 6 ), 'result' => $callback( new \stdClass() ) ); }
	}
	final class Database_Connection {
		public static $rows = array();
		public static $prepares = 0;
		public static $executes = 0;
		public static $lost_commit = false;
		public static $erase_on_execute = false;
		public $options = 'wp_options';
		private $snapshot = null;
		public function assert_clean_session() { return true; }
		public function assert_callback_context() {}
		public function abandon_native_sql_session() {}
		public function begin_callback_journal() { $this->snapshot = self::$rows; }
		public function rollback() { if ( null !== $this->snapshot ) { self::$rows = $this->snapshot; $this->snapshot = null; } return true; }
		public function commit() { $this->snapshot = null; if ( self::$lost_commit ) { self::$lost_commit = false; throw new Database_Write_Failure( 'database_write_outcome_unknown' ); } return true; }
		public function table( $table ) { return $table; }
		public function prepare_native_sql( $sql, $parameters, $budget, $memory ) { ++self::$prepares; return array( 'token' => 'native-only', 'field_count' => 1, 'max_allowed_packet' => 4194304, 'required_headroom' => 10000, 'minimum_result_bytes' => 100 ); }
		public function cancel_native_sql( $token ) {}
		public function stream_native_sql_once( $token, $writer ) {
			++self::$executes;
			if ( self::$erase_on_execute ) { self::$rows = array(); }
			return array( 'native' => array( 'outcome' => 'acknowledged', 'errno' => 0, 'sqlstate' => '00000', 'warning_count' => 0, 'affected' => '1' ), 'result_complete' => true, 'rows' => '1', 'result_error' => '' );
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
				$match = ( self::$rows[ $parameters[1] ] ?? null ) === $parameters[3] && $parameters[1] === $parameters[2];
				if ( $match ) { self::$rows[ $parameters[1] ] = $parameters[0]; }
				return array( 'affected' => $match ? 1 : 0 );
			}
			throw new \RuntimeException( 'Unexpected native receipt operation.' );
		}
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';
	function get_current_blog_id() { return 1; }
	function get_current_network_id() { return 1; }
	function is_multisite() { return false; }

	use WP_AI_Bridge\Support\Database_Native_SQL_Operation as Operation;
	use WP_AI_Bridge\Support\Database_Connection as Native;
	use WP_AI_Bridge\Support\Permissions;
	use WP_AI_Bridge\Support\Settings;

	$checks = 0;
	$assert = static function ( $condition, $message ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
	$permissions = new Permissions( new Settings() );
	$run = static function ( $request ) use ( $permissions ) { return ( new Operation( $permissions ) )->execute( $request, static function () { return true; } ); };
	$request = array( 'action' => 'prepare', 'operation_id' => 'native-recovery-first', 'acknowledge_native_account_scope' => true, 'statement_base64' => base64_encode( 'SELECT 1' ), 'parameters' => array(), 'max_result_bytes' => 4096 );
	$prepared = $run( $request );
	$assert( ! is_wp_error( $prepared ) && 'prepared' === $prepared['state'] && preg_match( '/^[a-f0-9]{64}$/D', $prepared['preparation_token'] ), 'A new SQL preparation did not issue its one-use secret.' );
	$assert( false === $prepared['installation_confined'] && true === $prepared['recovery_is_conditional'], 'Native account scope or conditional recovery was hidden.' );
	$assert( 1 === Native::$prepares && 0 === Native::$executes, 'Preparation executed SQL.' );
	$execute = array_replace( $request, array( 'action' => 'execute', 'preparation_token' => $prepared['preparation_token'] ) );
	$completed = $run( $execute );
	$assert( ! is_wp_error( $completed ) && 'completed' === $completed['state'] && 1 === Native::$executes, 'Winning prepared identity did not execute once.' );
	$prepares = Native::$prepares;
	$historical = $run( $execute );
	$assert( true === $historical['historical'] && 1 === Native::$executes && $prepares === Native::$prepares, 'Historical execution re-prepared or resent target SQL.' );
	$receipt_rows = Native::$rows;
	$released = $run( array( 'action' => 'result_release', 'operation_id' => $request['operation_id'], 'acknowledge_native_account_scope' => true, 'result_id' => $completed['private_result']['id'] ) );
	$assert( 'cancelled' === ( $released['state'] ?? null ) && true === ( $released['payload_released'] ?? null ) && array( $completed['private_result']['id'] ) === \WP_AI_Bridge\Support\Private_Transfer_Session::$cancelled, 'Verified native results did not use immutable-result cancellation.' );
	$assert( $receipt_rows === Native::$rows, 'Private result disposal changed native SQL effect history.' );
	$disposed = $run( array( 'action' => 'result_status', 'operation_id' => $request['operation_id'], 'acknowledge_native_account_scope' => true, 'result_id' => $completed['private_result']['id'] ) );
	$assert( is_wp_error( $disposed ), 'Disposed private result remained readable.' );
	$recovered = $run( array( 'action' => 'recover', 'operation_id' => $request['operation_id'], 'acknowledge_native_account_scope' => true ) );
	$after_disposal = $run( $execute );
	$assert( true === $recovered['historical'] && 'completed' === $recovered['state'] && $completed['operation_ref'] === $recovered['operation_ref'] && $completed['receipt'] === $recovered['receipt'], 'Result disposal lost acknowledged historical recovery.' );
	$assert( true === $after_disposal['historical'] && 1 === Native::$executes && $prepares === Native::$prepares, 'Disposed results caused native SQL re-preparation or redispatch.' );
	$changed = array_replace( $execute, array( 'statement_base64' => base64_encode( 'SELECT 2' ) ) );
	$assert( is_wp_error( $run( $changed ) ) && 1 === Native::$executes, 'Changed statement adopted the consumed identity.' );
	Native::$rows = array();
	$missing = $run( $execute );
	$assert( is_wp_error( $missing ) && 'database_native_sql_preparation_missing' === $missing->get_error_code() && $prepares === Native::$prepares, 'Execute recreated a deleted preparation or re-prepared missing history.' );
	$request['operation_id'] = 'native-recovery-arm-loss';
	$second = $run( $request );
	$arm = array_replace( $request, array( 'action' => 'execute', 'preparation_token' => $second['preparation_token'] ) );
	Native::$lost_commit = true;
	$lost = $run( $arm );
	$assert( is_wp_error( $lost ) && 1 === Native::$executes, 'An ambiguous arm commit allowed a SQL send.' );
	$retry = $run( $arm );
	$assert( 'dispatch_armed' === $retry['state'] && true === $retry['historical'] && 1 === Native::$executes, 'A durable unknown arm was automatically rearmed.' );
	$request['operation_id'] = 'native-recovery-self-erase';
	$third = $run( $request );
	Native::$erase_on_execute = true;
	$erased = $run( array_replace( $request, array( 'action' => 'execute', 'preparation_token' => $third['preparation_token'] ) ) );
	$assert( ! is_wp_error( $erased ) && true === $erased['reporting_pending'] && 'acknowledged' === $erased['receipt']['result']['native']['outcome'] && 2 === Native::$executes, 'Control-store loss rewrote acknowledged SQL as rollback or retryable failure.' );
	$assert( is_wp_error( $run( array_replace( $request, array( 'action' => 'execute', 'preparation_token' => $third['preparation_token'] ) ) ) ) && 2 === Native::$executes, 'Deleted self-receipt permitted another execution.' );
	echo "PASS: native SQL preparation consumption, historical-first recovery, lost-arm and control-store loss ({$checks} checks).\n";
}
