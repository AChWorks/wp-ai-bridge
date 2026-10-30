<?php
/**
 * Actual independent-process public filesystem arbitration and recovery refusal.
 *
 * Run via wp eval-file only after the coordinator installs its frozen product.
 * Required environment: WPAI199C_EXPECT_DATABASE, WPAI199C_EXPECT_ENGINE,
 * WPAI199C_EXPECT_PRODUCT_SHA, WPAI199C_EXPECT_FILESYSTEM_SHA256.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Administration_Configuration;
use WP_AI_Bridge\Support\Administration_Operation_Journal;
use WP_AI_Bridge\Support\Safe_Temporary_Staging;

require_once __DIR__ . '/issue199-completion-helper.php';

global $wpdb;
wpai199c_assert( defined( 'WPAI_ADMIN13_DISPOSABLE' ) && true === WPAI_ADMIN13_DISPOSABLE &&
	is_object( $wpdb ) && defined( 'WP_AI_BRIDGE_DIR' ) && function_exists( 'wp_get_ability' ), 'Only the already installed disposable WordPress candidate may load this fixture.' );
$database = getenv( 'WPAI199C_EXPECT_DATABASE' );
$expected_engine = getenv( 'WPAI199C_EXPECT_ENGINE' );
$product_sha = getenv( 'WPAI199C_EXPECT_PRODUCT_SHA' );
$filesystem_sha = getenv( 'WPAI199C_EXPECT_FILESYSTEM_SHA256' );
$version = (string) $wpdb->get_var( 'SELECT VERSION()' );
$engine = false !== stripos( $version, 'MariaDB' ) ? 'mariadb' : 'mysql';
wpai199c_assert( defined( 'WPAI_ADMIN13_DISPOSABLE' ) && true === WPAI_ADMIN13_DISPOSABLE &&
	1 === get_current_user_id() && current_user_can( 'manage_options' ) && ! is_multisite() &&
	is_string( $database ) && '' !== $database && $wpdb->dbname === $database && $wpdb->get_var( 'SELECT DATABASE()' ) === $database &&
	$engine === $expected_engine && is_string( $product_sha ) && 1 === preg_match( '/^[a-f0-9]{40}$/D', $product_sha ) &&
	is_string( $filesystem_sha ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $filesystem_sha ) &&
	hash_file( 'sha256', WP_AI_BRIDGE_DIR . '/src/Support/class-scoped-filesystem.php' ) === $filesystem_sha,
	'Explicit disposable actor/database/engine/installed-product identity is required.' );
wpai199c_assert( function_exists( 'proc_open' ) && is_file( ABSPATH . 'wp-load.php' ), 'Independent PHP child execution is unavailable.' );
$worker = __DIR__ . '/issue199-completion-concurrency-worker.php';
wpai199c_assert( is_file( $worker ) && ! is_link( $worker ), 'Exact independent worker helper missing.' );
$worker_sha = hash_file( 'sha256', $worker );
$private = Safe_Temporary_Staging::directory();
wpai199c_assert( is_string( $private ) && realpath( $private ) === $private, 'A private task barrier base is required.' );
$nonce = bin2hex( random_bytes( 6 ) );
$directory = $private . '/wpai199c-concurrency-' . $nonce;
$fixture = WP_CONTENT_DIR . '/uploads/wpai199c-concurrency-' . $nonce;
$root_id = 'concurrency_' . $nonce;
$before = wpai199c_options_before();
$children = array();
$leases = array();
$private_cleanup = array();
$success = false;
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) { ++$checks; wpai199c_assert( $condition, $message ); };
$json = static function ( $name ) use ( $directory ) {
	$path = $directory . '/' . $name;
	if ( ! is_file( $path ) ) { return null; }
	wpai199c_assert( ! is_link( $path ) && filesize( $path ) <= 16384, 'Task barrier evidence exceeds its exact scope.' );
	return json_decode( file_get_contents( $path ), true, 32, JSON_THROW_ON_ERROR );
};
$publish = static function ( $name, $value = null ) use ( $directory ) {
	$path = $directory . '/' . $name;
	$bytes = null === $value ? '' : json_encode( $value, JSON_THROW_ON_ERROR );
	$file = fopen( $path, 'x+b' );
	wpai199c_assert( is_resource( $file ), 'Task barrier publication must be exclusive.' );
	try { wpai199c_assert( chmod( $path, 0600 ) && fwrite( $file, $bytes ) === strlen( $bytes ) && fflush( $file ), 'Task barrier write failed.' ); }
	finally { fclose( $file ); }
};
$poll = static function () use ( &$children ) {
	foreach ( $children as &$child ) {
		$child['stdout'] .= stream_get_contents( $child['out'], 16385 - strlen( $child['stdout'] ) );
		$child['stderr'] .= stream_get_contents( $child['err'], 16385 - strlen( $child['stderr'] ) );
		wpai199c_assert( strlen( $child['stdout'] ) <= 16384 && strlen( $child['stderr'] ) <= 16384, 'Bounded task child output overflowed.' );
		$status = proc_get_status( $child['process'] );
		if ( ! $status['running'] && null === $child['exit'] ) { $child['exit'] = $status['exitcode']; }
	}
	unset( $child );
};
$await = static function ( callable $condition, $message, $seconds = 25 ) use ( $poll ) {
	$deadline = microtime( true ) + $seconds;
	do {
		$poll();
		if ( $condition() ) { return; }
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );
	throw new RuntimeException( $message );
};
$launch = static function ( $slot, $case ) use ( &$children, $directory, $worker ) {
	$environment = getenv();
	$environment['WPAI199C_RACE_DIRECTORY'] = $directory;
	$environment['WPAI199C_RACE_SLOT'] = $slot;
	$environment['WPAI199C_RACE_CASE'] = $case;
	$environment['WPAI199C_RACE_WPLOAD'] = ABSPATH . 'wp-load.php';
	$environment['WPAI199C_RACE_WORKER'] = $worker;
	$code = 'require getenv("WPAI199C_RACE_WPLOAD"); wp_set_current_user(1); require getenv("WPAI199C_RACE_WORKER");';
	$pipes = array();
	$process = proc_open( array( PHP_BINARY, '-r', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, ABSPATH, $environment );
	wpai199c_assert( is_resource( $process ), 'Cannot start the exact independent PHP worker.' );
	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
	$native = proc_get_status( $process );
	$children[ $slot ] = array( 'process' => $process, 'out' => $pipes[1], 'err' => $pipes[2], 'pid' => $native['pid'], 'exit' => null, 'stdout' => '', 'stderr' => '' );
};
$generation = static function ( $path ) {
	clearstatcache( true, $path );
	$stat = lstat( $path );
	wpai199c_assert( is_array( $stat ) && ! is_link( $path ), 'Exact task generation is unavailable.' );
	return array_intersect_key( $stat, array_fill_keys( array( 'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime' ), true ) );
};
$events = static function ( $case ) use ( $directory ) {
	$path = $directory . '/native-effects.jsonl';
	if ( ! is_file( $path ) ) { return array(); }
	wpai199c_assert( ! is_link( $path ) && filesize( $path ) <= 16384, 'Task native counter escaped its finite scope.' );
	$records = array();
	foreach ( explode( "\n", trim( file_get_contents( $path ) ) ) as $line ) {
		if ( '' !== $line ) {
			$record = json_decode( $line, true, 16, JSON_THROW_ON_ERROR );
			if ( $case === $record['case'] ) { $records[] = $record; }
		}
	}
	return $records;
};
$stage = static function ( $bytes ) use ( $root_id, &$leases ) {
	$open = wpai199c_ok( wpai199c_ability( 'filesystem-transfer', array( 'action' => 'begin', 'root_id' => $root_id, 'total_bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ) ) ), 'Cannot stage the exact task source.' );
	$leases[] = $open['id'];
	$offset = 0;
	while ( $offset < strlen( $bytes ) ) {
		$part = substr( $bytes, $offset, min( $open['chunk'], 1024 ) );
		wpai199c_ok( wpai199c_ability( 'filesystem-transfer', array( 'action' => 'append', 'root_id' => $root_id, 'transfer_id' => $open['id'], 'offset' => $offset, 'base64' => base64_encode( $part ) ) ), 'Task stage append failed.' );
		$offset += strlen( $part );
	}
	wpai199c_ok( wpai199c_ability( 'filesystem-transfer', array( 'action' => 'verify', 'root_id' => $root_id, 'transfer_id' => $open['id'] ) ), 'Task stage verification failed.' );
	return $open['id'];
};
try {
	$assert( mkdir( $directory, 0700 ) && mkdir( $fixture, 0700 ), 'Cannot create exclusive task-only barrier/file roots.' );
	$fixture = realpath( $fixture );
	$assert( is_string( $fixture ), 'Task fixture root must be canonical.' );
	wpai199c_grants( true );
	$configuration = new Administration_Configuration();
	$stored = $configuration->sanitize( array( 'roots' => array( array( 'id' => $root_id, 'label' => 'Disposable concurrency files', 'path' => $fixture,
		'purposes' => array( 'filesystem' ), 'read' => true, 'write' => true, 'destructive' => true, 'executable' => false, 'scope' => 'site', 'nonserved' => false ) ),
		'restore_schemas' => array(), 'backup_tables' => array(), 'step_bytes' => 1048576, 'step_seconds' => 5 ) );
	update_option( Administration_Configuration::OPTION_NAME, $stored, false );
	$current = $configuration->all();
	$assert( true === $current['valid'] && $current['roots'][ $root_id ]['path'] === realpath( $fixture ), 'Actual protected root configuration was not admitted.' );

	$bytes = "one native publication\0سلام-" . $nonce;
	$request = array( 'action' => 'write', 'root_id' => $root_id, 'path' => 'race.bin', 'operation_id' => 'completion-race-' . $nonce, 'transfer_id' => $stage( $bytes ) );
	$assignment = array( 'database' => $database, 'actor' => 1, 'root_id' => $root_id, 'fixture_root' => realpath( $fixture ), 'request' => $request,
		'target' => $fixture . '/race.bin', 'sha256' => hash( 'sha256', $bytes ), 'product_sha' => $product_sha, 'filesystem_sha256' => $filesystem_sha, 'worker_sha256' => $worker_sha );
	$publish( 'race.assignment.json', $assignment );
	$launch( 'one', 'race' );
	$launch( 'two', 'race' );
	$await( static function () use ( $json ) { return null !== $json( 'one.ready.json' ) && null !== $json( 'two.ready.json' ); }, 'Independent WordPress workers did not both become ready.' );
	$one = $json( 'one.ready.json' ); $two = $json( 'two.ready.json' );
	$assert( $one['pid'] !== $two['pid'] && $one['pid'] === $children['one']['pid'] && $two['pid'] === $children['two']['pid'] &&
		$one['connection_id'] !== $two['connection_id'] && (int) $one['connection_id'] > 0 && (int) $two['connection_id'] > 0, 'Race workers did not have independent native PHP/database sessions.' );
	$publish( 'race.go' );
	$winner = null;
	$await( static function () use ( $json, &$winner ) {
		foreach ( array( 'one', 'two' ) as $slot ) { if ( null !== $json( $slot . '.effect-ready.json' ) ) { $winner = $slot; } }
		if ( null === $winner ) { return false; }
		$peer = 'one' === $winner ? 'two' : 'one';
		return null !== $json( 'one.entered.json' ) && null !== $json( 'two.entered.json' ) && null !== $json( $peer . '.first.json' );
	}, 'Two public calls did not overlap behind the armed native publication gate.' );
	$peer = 'one' === $winner ? 'two' : 'one';
	$loser = $json( $peer . '.first.json' );
	$assert( in_array( $loser['error'] ?? '', array( 'transfer_session_busy', 'filesystem_busy', 'filesystem_stale' ), true ) ||
		( true === ( $loser['historical'] ?? false ) && 'dispatch_armed' === ( $loser['state'] ?? '' ) ), 'Overlapping loser did not report honest current busy/stale/armed history.' );
	$entered_one = $json( 'one.entered.json' ); $entered_two = $json( 'two.entered.json' );
	$assert( $entered_one['request_sha256'] === $entered_two['request_sha256'], 'Independent workers did not consume the identical public request.' );
	$armed = wpai199c_ok( wpai199c_ability( 'filesystem-write', array( 'action' => 'status', 'root_id' => $root_id, 'operation_id' => $request['operation_id'] ) ), 'Cannot observe actual durable pre-effect arm.' );
	$assert( 'dispatch_armed' === $armed['state'] && 'unknown' === $armed['outcome'] &&
		! file_exists( $assignment['target'] ) && array() === $events( 'race' ), 'Native publication ran before an acknowledged arm or before the test gate.' );
	$publish( 'race.effect-release' );
	$await( static function () use ( $json ) { return null !== $json( 'one.first.json' ) && null !== $json( 'two.first.json' ); }, 'First public calls did not return after releasing the native syscall.' );
	$winning = $json( $winner . '.first.json' );
	$assert( 'completed' === $winning['state'] && 'committed' === $winning['outcome'] && false === $winning['historical'], 'Exactly one native publication winner was not acknowledged.' );
	$published = $generation( $assignment['target'] );
	$assert( strlen( $bytes ) === $published['size'] && hash( 'sha256', $bytes ) === hash_file( 'sha256', $assignment['target'] ), 'Real publication bytes differ from the verified request.' );
	$publish( 'race.retry-go' );
	$await( static function () use ( $json ) { return null !== $json( 'one.final.json' ) && null !== $json( 'two.final.json' ); }, 'Independent status/input recovery and replay did not finish.' );
	foreach ( array( 'one', 'two' ) as $slot ) {
		$finished = $json( $slot . '.final.json' );
		$assert( 'completed' === $finished['status']['state'] && 'committed' === $finished['status']['outcome'] &&
			true === ( $finished['input_status']['payload_released'] ?? false ) && true === $finished['replay']['historical'] &&
			$winning['operation_ref'] === $finished['replay']['operation_ref'], 'Status/input recovery allowed redispatch or lost historical identity.' );
	}
	$native = $events( 'race' );
	$assert( 1 === count( $native ) && true === $native[0]['success'] && $winner === $native[0]['slot'] &&
		$published === $generation( $assignment['target'] ), 'The identical operation dispatched another target-link effect or changed the final generation.' );

	// A real partial cleanup leaves the product's own native candidate/preimage evidence.
	$preimage = 'old-preimage-owned-by-disposable-fixture-' . $nonce;
	$candidate = 'same-candidate-bytes-' . $nonce;
	$recovery_path = $fixture . '/recovery.bin';
	$assert( file_put_contents( $recovery_path, $preimage, LOCK_EX ) === strlen( $preimage ) && chmod( $recovery_path, 0600 ), 'Cannot create the exact task recovery preimage.' );
	$before_file = wpai199c_ok( wpai199c_ability( 'filesystem-read', array( 'action' => 'hash', 'root_id' => $root_id, 'path' => 'recovery.bin' ) ), 'Cannot select the actual recovery preimage.' );
	$recovery_request = array( 'action' => 'write', 'root_id' => $root_id, 'path' => 'recovery.bin', 'operation_id' => 'completion-partial-' . $nonce,
		'expected_identity' => $before_file['identity'], 'expected_sha256' => $before_file['sha256'], 'transfer_id' => $stage( $candidate ) );
	$recovery_assignment = array_replace( $assignment, array( 'request' => $recovery_request, 'target' => $recovery_path, 'sha256' => hash( 'sha256', $candidate ) ) );
	$publish( 'recovery.assignment.json', $recovery_assignment );
	$publish( 'recovery.effect-release' );
	$launch( 'interrupted', 'recovery' );
	$await( static function () use ( $json ) { return null !== $json( 'interrupted.ready.json' ); }, 'Independent recovery writer did not become ready.' );
	$publish( 'recovery.go' );
	$await( static function () use ( $json ) { return null !== $json( 'interrupted.final.json' ); }, 'Independent partial-cleanup public write exceeded its deadline.' );
	$assert( null !== $json( 'recovery.cleanup-fault.json' ), 'The discriminating cleanup fault was not reached after native publication.' );
	$recovery_status = wpai199c_ok( wpai199c_ability( 'filesystem-write', array( 'action' => 'status', 'root_id' => $root_id, 'operation_id' => $recovery_request['operation_id'] ) ), 'Actual partial publication receipt unavailable.' );
	$assert( 'completed' === $recovery_status['state'] && 'recovery_required' === $recovery_status['outcome'] &&
		isset( $recovery_status['recovery_target']['identity'] ), 'Interrupted native publication lost its honest recovery state/pinned generation.' );
	$owned = $generation( $recovery_path );
	$assert( hash( 'sha256', $candidate ) === hash_file( 'sha256', $recovery_path ) && 2 === $owned['nlink'], 'Expected actual pinned candidate was not published.' );
	$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, Administration_Operation_Journal::PREFIX . $recovery_status['operation_ref'] ) );
	$record = json_decode( (string) $raw, true, 32, JSON_THROW_ON_ERROR );
	$evidence = $record['descriptor']['recovery'] ?? null;
	$assert( 'filesystem' === ( $record['purpose'] ?? '' ) && $root_id === ( $record['descriptor']['root_id'] ?? '' ) &&
		array( base64_encode( 'recovery.bin' ) ) === ( $record['descriptor']['components'] ?? null ) &&
		is_array( $evidence ) && $evidence['candidate_sha256'] === hash( 'sha256', $candidate ), 'Refusing unrelated private recovery evidence.' );
	foreach ( array( 'candidate' => 'candidate_stat', 'hold' => 'preimage_stat' ) as $path_key => $stat_key ) {
		$path = $evidence[ $path_key ];
		$expected = $evidence[ $stat_key ];
		$assert( is_string( $path ) && 0 === strpos( $path, $private . DIRECTORY_SEPARATOR ) &&
			1 === preg_match( '/^(?:candidate|hold)-[a-f0-9]{48}$/D', basename( $path ) ) && ! is_link( $path ) &&
			lstat( $path )['dev'] === $expected['dev'] && lstat( $path )['ino'] === $expected['ino'], 'Private task evidence generation is not exact.' );
		$private_cleanup[] = array( 'path' => $path, 'stat' => $expected );
	}
	$assert( rename( $recovery_path, $fixture . '/retained-candidate.bin' ), 'Cannot retain the original candidate while creating an independent generation.' );
	$fresh = fopen( $recovery_path, 'x+b' );
	$assert( is_resource( $fresh ), 'Competing generation was not created exclusively.' );
	try { $assert( fwrite( $fresh, $candidate ) === strlen( $candidate ) && fflush( $fresh ) && chmod( $recovery_path, 0640 ), 'Competing generation bytes/mode could not be set.' ); }
	finally { fclose( $fresh ); }
	$competitor = $generation( $recovery_path );
	$assert( $owned['ino'] !== $competitor['ino'] && $owned['dev'] === $competitor['dev'], 'Discriminating same-byte competitor reused the pinned generation.' );
	$observed_competitor = wpai199c_ok( wpai199c_ability( 'filesystem-read', array( 'action' => 'stat', 'root_id' => $root_id, 'path' => 'recovery.bin' ) ), 'Cannot observe the independent current generation.' );
	$denied = wpai199c_ability( 'filesystem-write', array( 'action' => 'recover', 'root_id' => $root_id, 'path' => 'recovery.bin',
		'operation_id' => $recovery_request['operation_id'], 'recovery_id' => 'completion-refuse-' . $nonce, 'expected_identity' => $observed_competitor['identity'] ) );
	$assert( is_wp_error( $denied ) && 'filesystem_manual_recovery_required' === $denied->get_error_code() &&
		$competitor === $generation( $recovery_path ) && 0640 === ( $competitor['mode'] & 0777 ) &&
		hash( 'sha256', $candidate ) === hash_file( 'sha256', $recovery_path ), 'Recovery adopted or modified a fresh same-byte competing generation.' );
	$recovery_events = $events( 'recovery' );
	$assert( 1 === count( $recovery_events ) && true === $recovery_events[0]['success'], 'Partial-cleanup fixture redispatched its native publication.' );

	$await( static function () use ( &$children ) {
		foreach ( $children as $child ) { if ( null === $child['exit'] ) { return false; } }
		return true;
	}, 'Owned worker processes did not terminate.', 10 );
	foreach ( $children as $child ) { $assert( 0 === $child['exit'], 'Independent task worker exited unsuccessfully: ' . substr( $child['stderr'], 0, 1000 ) ); }
	$success = true;
	echo 'WPAI199C_CONCURRENCY_PROOF=' . json_encode( array(
		'product_sha' => $product_sha, 'filesystem_sha256' => $filesystem_sha, 'parent_test_sha256' => hash_file( 'sha256', __FILE__ ), 'worker_test_sha256' => $worker_sha,
		'database' => $database, 'engine' => $version, 'workers' => array( $one, $two ), 'armed_before_effect' => true,
		'public_request_sha256' => $entered_one['request_sha256'], 'winner' => $winner, 'publication_attempts' => count( $native ),
		'historical_replays' => 2, 'same_byte_competitor_preserved' => true, 'candidate_inode' => $owned['ino'], 'competitor_inode' => $competitor['ino'], 'checks' => $checks,
	), JSON_THROW_ON_ERROR ) . "\n";
	echo "PASS: independent public filesystem race, one armed native publication, historical recovery and competing-generation refusal.\n";
} catch ( Throwable $failure ) {
	// Compose deletes its disposable volumes after failure. Emit only bounded
	// task records before unwinding, while retaining every original assertion.
	try {
		$poll();
		$records = array();
		foreach ( array( 'one', 'two', 'interrupted' ) as $slot ) {
			foreach ( array( 'ready', 'effect-ready', 'first', 'final' ) as $phase ) {
				$name = $slot . '.' . $phase . '.json';
				$value = $json( $name );
				if ( null !== $value ) { $records[ $name ] = $value; }
			}
		}
		$pipes = array();
		foreach ( $children as $slot => $child ) {
			$pipes[ $slot ] = array( 'pid' => $child['pid'], 'exit' => $child['exit'],
				'stdout' => substr( $child['stdout'], 0, 1024 ), 'stderr' => substr( $child['stderr'], 0, 1024 ) );
		}
		$stats = array();
		foreach ( array( 'private_base' => $private, 'fixture_root' => $fixture, 'race_target' => $fixture . '/race.bin' ) as $label => $path ) {
			clearstatcache( true, $path );
			$stat = @lstat( $path );
			$stats[ $label ] = is_array( $stat ) ? array_intersect_key( $stat, array_fill_keys( array( 'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime' ), true ) ) : null;
		}
		$diagnostic = array( 'run' => $nonce, 'winner' => $winner ?? null, 'product_sha' => $product_sha, 'engine' => $version,
			'parent_test_sha256' => hash_file( 'sha256', __FILE__ ), 'worker_test_sha256' => $worker_sha,
			'effective_uid' => function_exists( 'posix_geteuid' ) ? posix_geteuid() : null,
			'records' => $records, 'native_race' => array_slice( $events( 'race' ), 0, 4 ),
			'native_recovery' => array_slice( $events( 'recovery' ), 0, 4 ), 'stats' => $stats, 'children' => $pipes );
		$encoded = json_encode( $diagnostic, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( strlen( $encoded ) > 16384 ) {
			$diagnostic['records'] = array_intersect_key( $records, array_fill_keys( array( 'one.first.json', 'two.first.json', 'interrupted.first.json' ), true ) );
			$diagnostic['children'] = array();
			$diagnostic['truncated'] = true;
			$encoded = json_encode( $diagnostic, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE );
		}
		echo strlen( $encoded ) <= 16384 ? 'WPAI199C_CONCURRENCY_FAILURE=' . $encoded . "\n" : "WPAI199C_CONCURRENCY_FAILURE=diagnostic_budget_exceeded\n";
	} catch ( Throwable $diagnostic_failure ) {
		echo "WPAI199C_CONCURRENCY_FAILURE=diagnostic_unavailable\n";
	}
	throw $failure;
} finally {
	try {
		foreach ( $children as &$child ) {
			$status = proc_get_status( $child['process'] );
			if ( $status['running'] ) {
				proc_terminate( $child['process'] );
				$deadline = microtime( true ) + 2;
				do { usleep( 10000 ); $status = proc_get_status( $child['process'] ); } while ( $status['running'] && microtime( true ) < $deadline );
				if ( $status['running'] ) { proc_terminate( $child['process'], 9 ); }
			}
			fclose( $child['out'] ); fclose( $child['err'] ); proc_close( $child['process'] );
		}
		unset( $child );
		if ( $success ) {
			foreach ( $leases as $lease ) { wpai199c_ability( 'filesystem-transfer', array( 'action' => 'release', 'root_id' => $root_id, 'transfer_id' => $lease ) ); }
			foreach ( $private_cleanup as $entry ) {
				$now = lstat( $entry['path'] );
				wpai199c_assert( ! is_link( $entry['path'] ) && $now['dev'] === $entry['stat']['dev'] && $now['ino'] === $entry['stat']['ino'] && unlink( $entry['path'] ), 'Refusing changed private evidence cleanup.' );
			}
			foreach ( array( 'race.bin', 'recovery.bin', 'retained-candidate.bin' ) as $name ) {
				$path = $fixture . '/' . $name;
				if ( is_file( $path ) && ! is_link( $path ) ) { unlink( $path ); }
			}
			rmdir( $fixture );
			foreach ( new DirectoryIterator( $directory ) as $entry ) {
				if ( ! $entry->isDot() ) { wpai199c_assert( $entry->isFile() && ! $entry->isLink(), 'Unexpected task barrier entry; preserve for reconciliation.' ); unlink( $entry->getPathname() ); }
			}
			rmdir( $directory );
		} else {
			echo 'WPAI199C_CONCURRENCY_PRESERVED_RUN=' . $nonce . "\n";
		}
	} finally {
		wp_set_current_user( 1 );
		wpai199c_restore_options( $before );
	}
}
