<?php
/**
 * Independent disposable WordPress worker for completion filesystem races.
 *
 * Test namespace interception retains the actual native link syscall. Production
 * has no test seam. Only this worker's exact task target is counted or paused.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support {
	function wpai199cr_private_json( $path, array $value ) {
		$encoded = json_encode( $value, JSON_THROW_ON_ERROR ) . "\n";
		$temporary = $path . '.tmp-' . \getmypid();
		$file = \fopen( $temporary, 'x+b' );
		if ( ! \is_resource( $file ) ) { throw new \RuntimeException( 'Cannot publish task barrier evidence.' ); }
		try {
			if ( ! \chmod( $temporary, 0600 ) || \fwrite( $file, $encoded ) !== \strlen( $encoded ) || ! \fflush( $file ) ) {
				throw new \RuntimeException( 'Task barrier evidence was not persisted.' );
			}
		} finally { \fclose( $file ); }
		if ( \file_exists( $path ) || ! \rename( $temporary, $path ) ) { throw new \RuntimeException( 'Task barrier publication failed.' ); }
	}
	function wpai199cr_native_event( array $event ) {
		$context = $GLOBALS['wpai199cr_worker_context'];
		$event['case'] = $context['case'];
		$event['slot'] = $context['slot'];
		$event['pid'] = \getmypid();
		$file = \fopen( $context['directory'] . '/native-effects.jsonl', 'a+b' );
		if ( ! \is_resource( $file ) || ! \flock( $file, LOCK_EX ) ) { throw new \RuntimeException( 'Task native counter unavailable.' ); }
		try {
			$encoded = json_encode( $event, JSON_THROW_ON_ERROR ) . "\n";
			if ( ! \chmod( $context['directory'] . '/native-effects.jsonl', 0600 ) ||
				\fwrite( $file, $encoded ) !== \strlen( $encoded ) || ! \fflush( $file ) ) {
				throw new \RuntimeException( 'Task native counter could not be acknowledged.' );
			}
		} finally { \flock( $file, LOCK_UN ); \fclose( $file ); }
	}
	function link( $from, $to ) {
		$context = $GLOBALS['wpai199cr_worker_context'] ?? null;
		if ( ! \is_array( $context ) || $to !== $context['target'] ) { return \link( $from, $to ); }
		$ready = $context['directory'] . '/' . $context['slot'] . '.effect-ready.json';
		if ( ! \is_file( $ready ) ) {
			wpai199cr_private_json( $ready, array( 'pid' => \getmypid(), 'case' => $context['case'], 'at' => 'actual_target_link_after_arm' ) );
		}
		$deadline = \microtime( true ) + 20;
		while ( ! \is_file( $context['directory'] . '/' . $context['case'] . '.effect-release' ) ) {
			if ( \microtime( true ) >= $deadline ) { throw new \RuntimeException( 'Task publication gate exceeded its deadline.' ); }
			\usleep( 10000 );
		}
		$result = \link( $from, $to );
		wpai199cr_native_event( array( 'event' => 'publication_link', 'success' => $result ) );
		return $result;
	}
	function unlink( $path ) {
		$context = $GLOBALS['wpai199cr_worker_context'] ?? null;
		if ( \is_array( $context ) && 'recovery' === $context['case'] &&
			empty( $GLOBALS['wpai199cr_cleanup_fault_used'] ) &&
			1 === \preg_match( '/^hold-[a-f0-9]{48}$/D', \basename( $path ) ) &&
			0 === \strpos( $path, $context['private_base'] . DIRECTORY_SEPARATOR ) &&
			\is_file( $context['target'] ) && $context['sha256'] === \hash_file( 'sha256', $context['target'] ) ) {
			$GLOBALS['wpai199cr_cleanup_fault_used'] = true;
			wpai199cr_private_json( $context['directory'] . '/recovery.cleanup-fault.json', array( 'pid' => \getmypid(), 'at' => 'owned_hold_unlink_after_real_publication' ) );
			return false;
		}
		return \unlink( $path );
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) || ! defined( 'WPAI_ADMIN13_DISPOSABLE' ) || true !== WPAI_ADMIN13_DISPOSABLE ) {
		throw new RuntimeException( 'Independent worker requires the explicit disposable WordPress bootstrap.' );
	}
	require_once __DIR__ . '/issue199-completion-helper.php';

	$directory = getenv( 'WPAI199C_RACE_DIRECTORY' );
	$slot = getenv( 'WPAI199C_RACE_SLOT' );
	$case = getenv( 'WPAI199C_RACE_CASE' );
	$private = WP_AI_Bridge\Support\Safe_Temporary_Staging::directory();
	wpai199c_assert( is_string( $directory ) && is_string( $private ) &&
		1 === preg_match( '/^wpai199c-concurrency-[a-f0-9]{12}$/D', basename( $directory ) ) &&
		dirname( $directory ) === $private && realpath( $directory ) === $directory && ! is_link( $directory ) &&
		0700 === ( fileperms( $directory ) & 0777 ) &&
		in_array( $slot, array( 'one', 'two', 'interrupted' ), true ) &&
		in_array( $case, array( 'race', 'recovery' ), true ) &&
		( 'recovery' === $case ? 'interrupted' === $slot : 'interrupted' !== $slot ), 'Independent worker assignment/root is invalid.' );
	$assignment_path = $directory . '/' . $case . '.assignment.json';
	wpai199c_assert( is_file( $assignment_path ) && ! is_link( $assignment_path ) && filesize( $assignment_path ) <= 16384, 'Bounded task assignment missing.' );
	$assignment = json_decode( file_get_contents( $assignment_path ), true, 32, JSON_THROW_ON_ERROR );
	global $wpdb;
	$expected_database = getenv( 'WPAI199C_EXPECT_DATABASE' );
	$engine = false !== stripos( (string) $wpdb->get_var( 'SELECT VERSION()' ), 'MariaDB' ) ? 'mariadb' : 'mysql';
	wpai199c_assert( is_array( $assignment ) && $assignment['database'] === $expected_database && $wpdb->dbname === $expected_database &&
		$wpdb->get_var( 'SELECT DATABASE()' ) === $expected_database && $engine === getenv( 'WPAI199C_EXPECT_ENGINE' ) &&
		1 === $assignment['actor'] && get_current_user_id() === $assignment['actor'] && current_user_can( 'manage_options' ) && ! is_multisite() &&
		hash_file( 'sha256', __FILE__ ) === $assignment['worker_sha256'] &&
		hash_file( 'sha256', WP_AI_BRIDGE_DIR . '/src/Support/class-scoped-filesystem.php' ) === $assignment['filesystem_sha256'] &&
		$assignment['product_sha'] === getenv( 'WPAI199C_EXPECT_PRODUCT_SHA' ), 'Independent worker bootstrap, actor, database or selected product differs.' );
	$configuration = ( new WP_AI_Bridge\Support\Administration_Configuration() )->all();
	$root = $configuration['roots'][ $assignment['root_id'] ] ?? null;
	wpai199c_assert( is_array( $root ) && $root['path'] === $assignment['fixture_root'] &&
		realpath( $root['path'] ) === $root['path'] && ! is_link( $root['path'] ) &&
		$assignment['target'] === $root['path'] . '/' . $assignment['request']['path'] &&
		'write' === $assignment['request']['action'] && $assignment['root_id'] === $assignment['request']['root_id'], 'Independent worker public target does not match the exact configured task root.' );
	$GLOBALS['wpai199cr_worker_context'] = array(
		'directory' => $directory, 'target' => $assignment['target'], 'slot' => $slot,
		'case' => $case, 'sha256' => $assignment['sha256'], 'private_base' => $private,
	);
	$summary = static function ( $value ) {
		if ( is_wp_error( $value ) ) { return array( 'error' => $value->get_error_code() ); }
		return array( 'state' => $value['state'] ?? null, 'outcome' => $value['outcome'] ?? null,
			'historical' => $value['historical'] ?? null, 'operation_ref' => $value['operation_ref'] ?? null,
			'payload_released' => $value['payload_released'] ?? null );
	};
	$write_json = static function ( $name, $value ) use ( $directory ) {
		WP_AI_Bridge\Support\wpai199cr_private_json( $directory . '/' . $name, $value );
	};
	$wait = static function ( $name, $seconds = 25 ) use ( $directory ) {
		$deadline = microtime( true ) + $seconds;
		while ( ! is_file( $directory . '/' . $name ) ) {
			if ( microtime( true ) >= $deadline ) { throw new RuntimeException( 'Independent worker barrier timed out: ' . $name ); }
			usleep( 10000 );
		}
	};
	// Only nonblocking read observations may be retried. Effect and historical
	// execute calls below each remain exactly one public Ability invocation.
	$observe = static function ( $ability, array $request ) {
		wpai199c_assert( 'status' === ( $request['action'] ?? null ) &&
			in_array( $ability, array( 'filesystem-write', 'filesystem-transfer' ), true ), 'Observation retry received an effect request.' );
		$deadline = microtime( true ) + 2;
		for ( $attempt = 0; $attempt < 100; ++$attempt ) {
			$value = wpai199c_ability( $ability, $request );
			if ( ! is_wp_error( $value ) || 'transfer_session_busy' !== $value->get_error_code() || microtime( true ) >= $deadline ) {
				return $value;
			}
			usleep( 20000 );
		}
		return $value;
	};
	$connection_id = (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
	$write_json( $slot . '.ready.json', array( 'pid' => getmypid(), 'connection_id' => $connection_id,
		'filesystem_sha256' => $assignment['filesystem_sha256'], 'worker_sha256' => $assignment['worker_sha256'] ) );
	$wait( $case . '.go' );
	$write_json( $slot . '.entered.json', array( 'pid' => getmypid(), 'at' => 'public_ability_execute', 'request_sha256' => hash( 'sha256', json_encode( $assignment['request'] ) ) ) );
	$first = wpai199c_ability( 'filesystem-write', $assignment['request'] );
	$first_summary = $summary( $first );
	$write_json( $slot . '.first.json', $first_summary );
	$final = array( 'first' => $first_summary, 'pid' => getmypid(), 'connection_id' => $connection_id );
	if ( 'race' === $case ) {
		$wait( 'race.retry-go' );
		$final['status'] = $summary( $observe( 'filesystem-write', array( 'action' => 'status', 'root_id' => $assignment['root_id'], 'operation_id' => $assignment['request']['operation_id'] ) ) );
		$final['input_status'] = $summary( $observe( 'filesystem-transfer', array( 'action' => 'status', 'root_id' => $assignment['root_id'], 'transfer_id' => $assignment['request']['transfer_id'] ) ) );
		$final['replay'] = $summary( wpai199c_ability( 'filesystem-write', $assignment['request'] ) );
	}
	$write_json( $slot . '.final.json', $final );
	echo 'WPAI199C_CONCURRENCY_WORKER=' . json_encode( array( 'slot' => $slot, 'case' => $case, 'pid' => getmypid(), 'connection_id' => $connection_id, 'first' => $first_summary, 'replay' => $final['replay'] ?? null ), JSON_THROW_ON_ERROR ) . "\n";
}
