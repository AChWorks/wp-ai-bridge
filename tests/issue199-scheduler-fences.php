<?php
/**
 * Scheduler consumer fencing/revocation tests; physical journal tests are separate.
 *
 * @package WP_AI_Bridge
 */
namespace {
	require __DIR__ . '/bootstrap.php';
	function is_multisite() { return false; }
	function get_current_blog_id() { return \WP_AI_Bridge\Support\Administration_Access::$origin['blog']; }
	function wp_salt( $scheme = 'auth' ) { return 'scheduler-fence-test'; }
	function has_action( $hook ) { return 'owned_fixture' === $hook ? 10 : false; }
	function _get_cron_array() { return $GLOBALS['scheduler_fixture_events'] ?? array(); }
	function wp_get_schedules() { return array(); }
	function wp_schedule_single_event( $timestamp, $hook, $args, $wp_error ) {
		++$GLOBALS['scheduler_fixture_calls'];
		$GLOBALS['scheduler_fixture_events'][ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = array( 'schedule' => false, 'args' => $args );
		if ( isset( $GLOBALS['scheduler_fixture_nested'] ) ) { ( $GLOBALS['scheduler_fixture_nested'] )(); }
		if ( ! empty( $GLOBALS['scheduler_fixture_throw'] ) ) { throw new \RuntimeException( 'Synthetic committed then thrown.' ); }
		return true;
	}
	function wp_get_scheduled_event( $hook, $args, $timestamp ) {
		$GLOBALS['scheduler_fixture_observations'][] = array( \WP_AI_Bridge\Support\Administration_Access::$origin, \WP_AI_Bridge\Support\Administration_Access::$enabled );
		if ( isset( $GLOBALS['scheduler_fixture_on_observe'] ) ) { ( $GLOBALS['scheduler_fixture_on_observe'] )(); }
		$row = $GLOBALS['scheduler_fixture_events'][ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] ?? false;
		return false === $row ? false : (object) $row;
	}
	$GLOBALS['wp_current_filter'] = array();
	$GLOBALS['wp_filter'] = array();
}
namespace WP_AI_Bridge\Support {
	class Administration_Access {
		public static $origin = array( 'blog' => 1, 'network' => 1, 'user' => 7, 'client' => 'owned-client' );
		public static $enabled = true;
		public function __construct( $permissions ) {}
		public function allowed( $group, $network = false, $extra = array() ) { return self::$enabled; }
		public function context() { return self::$origin; }
		public function same_context( $origin ) { return self::$origin === $origin; }
		public function configuration() { return array( 'step_bytes' => 65536, 'step_seconds' => 2 ); }
	}
	class Mutation_Log {
		public function record( $ability, $type, $id, $success, $code ) {
			$GLOBALS['scheduler_fixture_audits'][] = array( Administration_Access::$origin, Administration_Access::$enabled );
			return true;
		}
	}
	class Administration_Operation_Journal {
		public static $records = array();
		public static $on_reserve;
		public static $fail_arm = false;
		public static $fail_complete = false;
		public static $arms = 0;
		public static $completions = 0;
		public static $context_valid = true;
		private $identity;
		public function __construct( $purpose, $identity, $budget ) {
			if ( 'scheduler' !== $purpose ) { throw new \RuntimeException( 'Wrong purpose.' ); }
			$this->identity = $identity;
		}
		private function key( $id ) { return hash( 'sha256', json_encode( array( $this->identity, $id ) ) ); }
		private function wrap( $key, $historical ) {
			return array( 'state' => self::$records[ $key ]['state'], 'operation_ref' => $key, 'historical' => $historical, 'receipt' => self::$records[ $key ] );
		}
		public function check_context() {
			$identity = $this->identity;
			unset( $identity['scope'] );
			return self::$context_valid && $identity === Administration_Access::$origin ? true : new \WP_Error( 'context_changed', 'Changed.' );
		}
		public function status( $id, $fingerprint = null ) {
			$key = $this->key( $id );
			return isset( self::$records[ $key ] ) ? $this->wrap( $key, true ) : null;
		}
		public function reserve( $id, $fingerprint, $descriptor ) {
			$key = $this->key( $id );
			$existing = isset( self::$records[ $key ] );
			if ( $existing && self::$records[ $key ]['fingerprint'] !== $fingerprint ) {
				return new \WP_Error( 'administration_operation_conflict', 'Changed request.' );
			}
			if ( ! $existing ) {
				self::$records[ $key ] = array( 'state' => 'prepared', 'fingerprint' => $fingerprint, 'descriptor' => $descriptor, 'result' => null, 'created_at' => 'fixture' );
			}
			$result = $this->wrap( $key, $existing );
			$result['claim'] = $existing ? null : array( 'token' => $key );
			$result['dispatch_allowed'] = ! $existing;
			if ( is_callable( self::$on_reserve ) ) {
				$callback = self::$on_reserve;
				self::$on_reserve = null;
				$callback();
			}
			return $result;
		}
		public function arm( $claim ) {
			++self::$arms;
			if ( self::$fail_arm ) { return new \WP_Error( 'administration_arm_unknown', 'No acknowledgement.' ); }
			self::$records[ $claim['token'] ]['state'] = 'dispatch_armed';
			return true;
		}
		public function complete( $claim, $result ) {
			++self::$completions;
			if ( self::$fail_complete ) { return new \WP_Error( 'administration_complete_unknown', 'Lost acknowledgement.' ); }
			self::$records[ $claim['token'] ]['state'] = 'completed';
			self::$records[ $claim['token'] ]['result'] = $result;
			return $this->wrap( $claim['token'], false );
		}
	}
}
namespace {
	use WP_AI_Bridge\Abilities\Scheduler_Abilities;
	use WP_AI_Bridge\Support\Administration_Access;
	use WP_AI_Bridge\Support\Administration_Operation_Journal;
	use WP_AI_Bridge\Support\Permissions;
	use WP_AI_Bridge\Support\Settings;

	$assertions = 0;
	function fence_assert( $condition, $why ) {
		global $assertions;
		++$assertions;
		if ( ! $condition ) { throw new RuntimeException( $why ); }
	}
	$GLOBALS['scheduler_fixture_calls'] = 0;
	$provider = new Scheduler_Abilities( new Permissions( new Settings() ) );
	$request = array( 'action' => 'schedule', 'provider' => 'wp_cron', 'hook' => 'owned_fixture', 'args' => array( 'private-owned-marker' ),
		'timestamp' => 12345, 'confirm' => true, 'operation_id' => 'fixture-operation-one' );
	Administration_Access::$enabled = false;
	fence_assert( is_wp_error( $provider->manage( $request ) ) && 0 === $GLOBALS['scheduler_fixture_calls'] && array() === Administration_Operation_Journal::$records, 'Denied effect reached native or journal.' );
	Administration_Access::$enabled = true;
	$first = $provider->manage( $request );
	fence_assert( ! is_wp_error( $first ) && $first['dispatch_attempted'] && 1 === $GLOBALS['scheduler_fixture_calls'], 'Winning reservation did not dispatch exactly one API.' );
	fence_assert( ! isset( $first['claim'], $first['dispatch_allowed'], $first['receipt']['fingerprint'] ) && false === strpos( json_encode( $first ), 'private-owned-marker' ), 'Private claim/request data leaked.' );
	$again = $provider->manage( $request );
	fence_assert( ! is_wp_error( $again ) && $again['historical'] && ! $again['dispatch_attempted'] && 1 === $GLOBALS['scheduler_fixture_calls'], 'Historical receipt rearmed.' );
	$changed = $request;
	++$changed['timestamp'];
	fence_assert( is_wp_error( $provider->manage( $changed ) ) && 1 === $GLOBALS['scheduler_fixture_calls'], 'Conflicting fingerprint dispatched.' );
	$bad_read = $provider->read( array( 'action' => 'list', 'provider' => 'wp_cron', 'offset' => null ) );
	fence_assert( is_wp_error( $bad_read ) && false === $bad_read->get_error_data()['dispatch_attempted'], 'Subsequent read inherited previous dispatch evidence or accepted null offset.' );

	$request['operation_id'] = 'fixture-operation-throw';
	++$request['timestamp'];
	$GLOBALS['scheduler_fixture_throw'] = true;
	$partial = $provider->manage( $request );
	fence_assert( ! is_wp_error( $partial ) && 'unknown' === $partial['receipt']['result']['outcome'] && $partial['receipt']['result']['native_api_threw'] && 2 === $GLOBALS['scheduler_fixture_calls'], 'Effect-then-throw was misclassified.' );
	$GLOBALS['scheduler_fixture_throw'] = false;
	$again = $provider->manage( $request );
	fence_assert( ! is_wp_error( $again ) && ! $again['dispatch_attempted'] && 2 === $GLOBALS['scheduler_fixture_calls'], 'Unknown native effect replayed.' );

	$request['operation_id'] = 'fixture-operation-revoked';
	++$request['timestamp'];
	Administration_Operation_Journal::$on_reserve = static function () { Administration_Access::$enabled = false; };
	$revoked = $provider->manage( $request );
	fence_assert( is_wp_error( $revoked ) && 2 === $GLOBALS['scheduler_fixture_calls'], 'Revocation after reservation did not prevent dispatch.' );
	Administration_Access::$enabled = true;
	$status = $provider->manage( array( 'action' => 'status', 'provider' => 'wp_cron', 'operation_id' => $request['operation_id'] ) );
	fence_assert( ! is_wp_error( $status ) && 'not_dispatched' === $status['receipt']['result']['outcome'], 'Known pre-dispatch rejection was hidden as a committed effect.' );

	$request['operation_id'] = 'fixture-operation-network';
	++$request['timestamp'];
	Administration_Operation_Journal::$on_reserve = static function () { Administration_Access::$origin['network'] = 2; };
	$switched = $provider->manage( $request );
	fence_assert( is_wp_error( $switched ) && 2 === $GLOBALS['scheduler_fixture_calls'], 'Original network switch reached native dispatch.' );
	Administration_Access::$origin['network'] = 1;

	$request['operation_id'] = 'fixture-operation-arm-failure';
	++$request['timestamp'];
	Administration_Operation_Journal::$fail_arm = true;
	$unarmed = $provider->manage( $request );
	fence_assert( is_wp_error( $unarmed ) && 2 === $GLOBALS['scheduler_fixture_calls'], 'Unacknowledged arm dispatched.' );
	$arms = Administration_Operation_Journal::$arms;
	Administration_Operation_Journal::$fail_arm = false;
	$again = $provider->manage( $request );
	fence_assert( ! is_wp_error( $again ) && 'prepared' === $again['state'] && ! $again['dispatch_attempted'] && $arms === Administration_Operation_Journal::$arms, 'Prepared uncertain reservation was silently rearmed.' );

	$request['operation_id'] = 'fixture-operation-completion-loss';
	++$request['timestamp'];
	Administration_Operation_Journal::$fail_complete = true;
	$lost = $provider->manage( $request );
	fence_assert( is_wp_error( $lost ) && 3 === $GLOBALS['scheduler_fixture_calls'], 'Completion acknowledgement loss lost dispatch accounting.' );
	Administration_Operation_Journal::$fail_complete = false;
	$again = $provider->manage( $request );
	fence_assert( ! is_wp_error( $again ) && 'dispatch_armed' === $again['state'] && ! $again['dispatch_attempted'] && 3 === $GLOBALS['scheduler_fixture_calls'], 'Armed uncertain reservation replayed.' );

	$request['operation_id'] = 'fixture-operation-nested';
	++$request['timestamp'];
	$GLOBALS['scheduler_fixture_nested'] = static function () use ( $provider, $request ) {
		$error = $provider->manage( $request );
		fence_assert( is_wp_error( $error ) && false === $error->get_error_data()['dispatch_attempted'], 'Nested call adopted outer dispatch evidence.' );
	};
	$nested = $provider->manage( $request );
	unset( $GLOBALS['scheduler_fixture_nested'] );
	fence_assert( ! is_wp_error( $nested ) && $nested['dispatch_attempted'] && 4 === $GLOBALS['scheduler_fixture_calls'], 'Nested rejection destroyed outer accounting.' );

	// Native callbacks and provider readback may leave a different ambient
	// principal/tenant or revoke authority after a real effect. No follow-up
	// provider observation, completion or audit may borrow that changed context.
	$origin = Administration_Access::$origin;
	foreach ( array( 'dispatch', 'observation' ) as $stage ) {
		foreach ( array( 'user', 'blog', 'network', 'client', 'grant', 'journal' ) as $change ) {
			$request['operation_id'] = 'fixture-post-' . $stage . '-' . $change;
			++$request['timestamp'];
			$calls = $GLOBALS['scheduler_fixture_calls'];
			$observations = count( $GLOBALS['scheduler_fixture_observations'] ?? array() );
			$audits = count( $GLOBALS['scheduler_fixture_audits'] ?? array() );
			$completions = Administration_Operation_Journal::$completions;
			$arms = Administration_Operation_Journal::$arms;
			$callback = static function () use ( $change ) {
				if ( 'grant' === $change ) {
					Administration_Access::$enabled = false;
				} elseif ( 'journal' === $change ) {
					Administration_Operation_Journal::$context_valid = false;
				} else {
					Administration_Access::$origin[ $change ] = 'client' === $change ? 'foreign-client' : 99;
				}
			};
			$key = 'dispatch' === $stage ? 'scheduler_fixture_nested' : 'scheduler_fixture_on_observe';
			$GLOBALS[ $key ] = $callback;
			$changed = $provider->manage( $request );
			unset( $GLOBALS[ $key ] );
			fence_assert( $observations + ( 'observation' === $stage ? 1 : 0 ) === count( $GLOBALS['scheduler_fixture_observations'] ?? array() ) &&
				$audits === count( $GLOBALS['scheduler_fixture_audits'] ?? array() ) && $completions === Administration_Operation_Journal::$completions,
				'Lost ' . $change . ' context after ' . $stage . ' reached follow-up observation, completion or audit.' );
			fence_assert( is_wp_error( $changed ) && 'scheduler_context_changed' === $changed->get_error_code() &&
				true === $changed->get_error_data()['dispatch_attempted'] && 'unknown' === ( $changed->get_error_data()['outcome'] ?? null ) &&
				$calls + 1 === $GLOBALS['scheduler_fixture_calls'], 'Changed post-effect context lost explicit unknown/dispatch evidence.' );
			Administration_Access::$origin = $origin;
			Administration_Access::$enabled = true;
			Administration_Operation_Journal::$context_valid = true;
			$status = $provider->manage( array( 'action' => 'status', 'provider' => 'wp_cron', 'operation_id' => $request['operation_id'] ) );
			fence_assert( ! is_wp_error( $status ) && 'dispatch_armed' === $status['state'] && null === $status['receipt']['result'] &&
				! $status['dispatch_attempted'], 'Lost post-effect authority overwrote the armed fence.' );
			$again = $provider->manage( $request );
			fence_assert( ! is_wp_error( $again ) && 'dispatch_armed' === $again['state'] && ! $again['dispatch_attempted'] &&
				$calls + 1 === $GLOBALS['scheduler_fixture_calls'] && $arms + 1 === Administration_Operation_Journal::$arms,
				'Changed-context native effect replayed after original authority was restored.' );
		}
	}
	echo 'Scheduler consumer fence regressions passed: ' . $assertions . "\n";
}
