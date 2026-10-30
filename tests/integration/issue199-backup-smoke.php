<?php
/**
 * Actual native full installation capture, portable ingress and isolated restore.
 * Run only inside the Master-provisioned disposable WordPress runtime.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Backup_Archive;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Transfer_Session;
use WP_AI_Bridge\Support\Administration_Access;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-completion-helper.php';
require_once __DIR__ . '/issue199-backup-preflight-helper.php';

global $wpdb;
wpai199c_assert( defined( 'ABSPATH' ) && $wpdb->dbh instanceof mysqli, 'Native disposable WordPress bootstrap required.' );
wpai199c_assert( defined( 'WPAI_ADMIN13_DISPOSABLE' ) && true === WPAI_ADMIN13_DISPOSABLE, 'Explicit disposable Core bootstrap required.' );
$expected_database = getenv( 'WPAI199C_EXPECT_DATABASE' );
wpai199c_assert( is_string( $expected_database ) && '' !== $expected_database, 'Explicit expected disposable database is required.' );
$database_probe = $wpdb->dbh->query( 'SELECT DATABASE()' );
wpai199c_assert( $database_probe instanceof mysqli_result, 'Selected disposable database could not be verified.' );
try {
	$selected_database = $database_probe->fetch_row();
	wpai199c_assert( is_array( $selected_database ) && is_string( $selected_database[0] ?? null ) &&
		hash_equals( $expected_database, $selected_database[0] ), 'Selected database does not match the explicitly expected disposable database.' );
} finally {
	$database_probe->free();
}
$target_schema = getenv( 'WPAI_ADMIN13_RESTORE_SCHEMA' );
$target_root = getenv( 'WPAI_ADMIN13_RESTORE_ROOT' );
wpai199c_assert( is_string( $target_schema ) && preg_match( '/^wpai[a-z0-9_]+restore[a-z0-9_]*$/D', $target_schema ), 'Explicit task-owned empty restore schema required.' );
wpai199c_assert( is_string( $target_root ) && realpath( $target_root ) === $target_root && is_dir( $target_root ) && ! is_link( $target_root ), 'Explicit task-owned nonserved restore directory required.' );
if ( ! function_exists( 'wpai199c_seed_provider' ) ) {
	require dirname( __DIR__ ) . '/fixtures/issue199-completion-provider.php';
}
$original_user = get_current_user_id();
wp_set_current_user( 1 );
wpai199c_assert( current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() ), 'Actual disposable administrator/network authority required.' );
require_once __DIR__ . '/issue199-backup-session-helper.php';
wpai199b_native_session_cases();
$before = wpai199c_options_before();
$suffix = bin2hex( random_bytes( 4 ) );
$seed = null;
$archive_id = null;
$input_id = null;
$restore_id = null;
$json_table = null;
$collision_table = null;
$collision_table_name = $wpdb->prefix . 'wpai199b_collision_' . $suffix;
$started = microtime( true );
$test_seconds = getenv( 'WPAI_ADMIN13_BACKUP_TEST_SECONDS' );
$test_seconds = is_string( $test_seconds ) && ctype_digit( $test_seconds ) ? (int) $test_seconds : 900;
wpai199c_assert( $test_seconds >= 60 && $test_seconds <= 3600, 'Finite test runtime policy required.' );
$deadline = $started + $test_seconds;
$call = static function ( array $input ) { return wpai199c_ability( 'full-backup', $input ); };
$step = static function ( $action, $id, array $terminal ) use ( $call, $deadline ) {
	do {
		wpai199c_assert( microtime( true ) < $deadline, 'Native backup test runtime exhausted; no complete claim.' );
		$result = wpai199c_ok( $call( array( 'action' => $action, 'session_id' => $id ) ), 'Native backup continuation failed.' );
	} while ( ! in_array( $result['state'], $terminal, true ) );
	return $result;
};
try {
	wpai199c_grants();
	$seed = wpai199c_seed_provider( $suffix );
	wpai199c_assert( false !== $wpdb->query( $wpdb->prepare(
		'CREATE TABLE %i (`digest` VARBINARY(64) NULL,`multiplicity` VARBINARY(64) NULL,`wpai_row_sha256` VARBINARY(64) NULL,`wpai_backup_rows` VARBINARY(64) NULL,`value` VARBINARY(64) NULL) ENGINE=InnoDB',
		$collision_table_name
	) ), 'Native row-hash namespace collision fixture creation failed.' );
	$collision_table = $collision_table_name;
	// These are legitimate source names, including both generated namespace
	// names. The same source digest must not merge one/two; one is duplicated.
	foreach ( array( 'one', 'two', 'one' ) as $collision_value ) {
		wpai199c_assert( false !== $wpdb->insert( $collision_table, array(
			'digest' => 'same', 'multiplicity' => 'same', 'wpai_row_sha256' => 'same',
			'wpai_backup_rows' => 'same', 'value' => $collision_value,
		), array( '%s', '%s', '%s', '%s', '%s' ) ), 'Native row-hash namespace collision seed failed.' );
	}
	wpai199c_assert( '3' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $collision_table ) ) &&
		'2' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `value`=%s', $collision_table, 'one' ) ) &&
		'1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `value`=%s', $collision_table, 'two' ) ),
		'Native keyless collision fixture must retain two distinct rows and an exact duplicate.' );
	$json_table = 'provider_admin13_json_' . $suffix;
	wpai199c_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id BIGINT NOT NULL PRIMARY KEY,doc JSON NULL) ENGINE=InnoDB', $json_table ) ), 'Native JSON compatibility fixture creation failed.' );
	$backup_tables = array_merge( $seed['tables'], array( $json_table ) );
	wpai199c_configure( $seed['directory'], $target_root, $target_schema, $backup_tables, is_multisite() ? 'network' : 'site' );
	if ( false !== stripos( (string) $wpdb->get_var( 'SELECT VERSION()' ), 'MariaDB' ) ) {
		$unsupported_json = $call( array( 'action' => 'inspect', 'source_roots' => array( 'installation' ) ) );
		wpai199c_assert( is_wp_error( $unsupported_json ) && 'backup_schema_constraint_or_partition_unsupported' === $unsupported_json->get_error_code(),
			'MariaDB JSON/CHECK must remain an explicit unsupported preflight shape.' );
		wpai199c_assert( false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $json_table ) ), 'Exact owned JSON compatibility fixture cleanup failed.' );
		$json_table = null;
		wpai199c_configure( $seed['directory'], $target_root, $target_schema, $seed['tables'], is_multisite() ? 'network' : 'site' );
	} else {
		foreach ( array(
			array( 'id' => '1', 'doc' => '{"unicode":"سلام","large":18446744073709551614,"decimal":123456789012345678.123456789012}' ),
			array( 'id' => '2', 'doc' => null ),
			array( 'id' => '3', 'doc' => 'null' ),
			array( 'id' => '4', 'doc' => '"سلام JSON string"' ),
		) as $json_row ) {
			wpai199c_assert( false !== $wpdb->insert( $json_table, $json_row, array( '%s', '%s' ) ), 'Exact native JSON seed failed.' );
		}
	}
	wpai199b_preflight_integrity_cases( $call, $target_schema, $target_root, 'restore', $deadline );
	$source_schema = $wpdb->get_var( 'SELECT DATABASE()' );
	wpai199c_assert( $source_schema !== $target_schema, 'Source and target must be distinct.' );
	$native_session = $wpdb->get_row( 'SELECT DATABASE() AS db,@@SESSION.autocommit AS ac,@@SESSION.foreign_key_checks AS fk,@@SESSION.unique_checks AS uq,@@SESSION.sql_mode AS mode,@@SESSION.time_zone AS tz', ARRAY_A );
	$expected_db = wpai199c_database_image( $wpdb->dbh, $source_schema );
	$expected_files = wpai199c_file_image( rtrim( ABSPATH, '/\\' ) );
	$plan = wpai199c_ok( $call( array( 'action' => 'inspect', 'source_roots' => array( 'installation' ) ) ), 'Full approved source preflight failed.' );
	$begun = wpai199c_ok( $call( array( 'action' => 'export_begin', 'source_roots' => array( 'installation' ),
		'expected_plan_hash' => $plan['plan_hash'], 'quiescence_confirmed' => true, 'bootstrap_writers_confirmed' => true ) ), 'Capture reservation failed.' );
	$archive_id = $begun['archive_id'];
	$export = $step( 'export_step', $archive_id, array( 'sealed' ) );
	wpai199c_assert( true === $export['complete'] && true === $export['source_verified'] && true === $export['quiescence_assumption'] &&
		true === $export['activation_pending'] && 'declared_quiescent_verified_capture' === $export['consistency'], 'Capture semantics or completion claim were dishonest.' );
	wpai199c_assert( $expected_db === wpai199c_database_image( $wpdb->dbh, $source_schema ), 'Successful capture wrote its source database.' );
	wpai199c_assert( $expected_files === wpai199c_file_image( rtrim( ABSPATH, '/\\' ) ), 'Successful capture changed installation files.' );
	$read = wpai199c_ok( $call( array( 'action' => 'read', 'purpose' => 'backup_archive', 'session_id' => $archive_id, 'offset' => 0, 'max_bytes' => 1024 ) ), 'Authenticated archive egress failed.' );
	wpai199c_assert( 0 === strpos( base64_decode( $read['content_base64'] ?? $read['data'] ?? $read['base64'] ?? '', true ), 'WPAI1:' ), 'Egress did not contain the actual framed database/files archive.' );

	// Move the entire portable package through public bounded input actions.
	$opened = wpai199c_ok( $call( array( 'action' => 'input_open', 'max_total_bytes' => $export['bytes'] ) ), 'Portable archive input reservation failed.' );
	$input_id = $opened['session_id'];
	$permissions = new Permissions( new Settings() );
	$access = new Administration_Access( $permissions );
	$private = Private_Transfer_Session::for_administration( $permissions, 'backup_archive', $access->context() );
	$copied = $private->with_administration_source( $archive_id, static function ( $source ) use ( $call, $input_id, $export ) {
		$offset = 0;
		while ( null !== ( $frame = Backup_Archive::read( $source['stream'], $export['max_frame_bytes'] ) ) ) {
			wpai199c_ok( $call( array( 'action' => 'input_append', 'session_id' => $input_id, 'offset' => $offset,
				'content_base64' => base64_encode( $frame['frame'] ), 'sha256' => hash( 'sha256', $frame['frame'] ) ) ), 'Portable frame ingress failed.' );
			$offset += strlen( $frame['frame'] );
		}
		return $offset;
	} );
	wpai199c_assert( ! is_wp_error( $copied ) && $copied === $export['bytes'], 'Portable package copy was incomplete.' );
	wpai199c_ok( $call( array( 'action' => 'input_seal', 'session_id' => $input_id, 'offset' => $export['bytes'], 'sha256' => $export['sha256'] ) ), 'Portable input seal failed.' );

	$prepared = wpai199c_ok( $call( array( 'action' => 'restore_prepare', 'archive_id' => $input_id, 'archive_sha256' => $export['sha256'],
		'archive_purpose' => 'backup_input', 'target_schema' => $target_schema, 'target_root' => 'restore' ) ), 'Isolated restore preflight reservation failed.' );
	$restore_id = $prepared['session_id'];
	$ready = $step( 'restore_step', $restore_id, array( 'ready' ) );
	wpai199c_assert( false === $ready['complete'], 'Preflight alone was reported restored.' );
	wpai199c_assert( array() === wpai199c_database_image( $wpdb->dbh, $target_schema )['tables'], 'Preflight executed target schema changes.' );
	wpai199c_grants( false );
	wpai199c_assert( is_wp_error( $call( array( 'action' => 'restore_begin', 'session_id' => $restore_id, 'expected_plan_hash' => $ready['plan_hash'], 'quiescence_confirmed' => true ) ) ), 'Revoked restore authority still executed.' );
	wpai199c_grants( true );
	wpai199c_ok( $call( array( 'action' => 'restore_begin', 'session_id' => $restore_id, 'expected_plan_hash' => $ready['plan_hash'], 'quiescence_confirmed' => true ) ), 'Approved exact restore plan failed.' );
	$restored = $step( 'restore_step', $restore_id, array( 'verified' ) );
	wpai199c_assert( true === $restored['complete'] && true === $restored['restore_verified'] && true === $restored['activation_pending'], 'Restore was not independently verified or implied activation.' );
	$restored_root = $target_root . '/roots/installation';
	wpai199c_assert_restored( $expected_db, $expected_files, $wpdb->dbh, $target_schema, $restored_root );
	$after_session = $wpdb->get_row( 'SELECT DATABASE() AS db,@@SESSION.autocommit AS ac,@@SESSION.foreign_key_checks AS fk,@@SESSION.unique_checks AS uq,@@SESSION.sql_mode AS mode,@@SESSION.time_zone AS tz', ARRAY_A );
	wpai199c_assert( $native_session === $after_session, 'Backup/restore changed the caller native connection/session.' );

	// A required failure diagnostic during a resumed capture is a source write.
	// It must remain visible and prevent a full-completion receipt.
	$drift_plan = wpai199c_ok( $call( array( 'action' => 'inspect', 'source_roots' => array( 'installation' ) ) ), 'Drift source preflight failed.' );
	$drift = wpai199c_ok( $call( array( 'action' => 'export_begin', 'source_roots' => array( 'installation' ), 'expected_plan_hash' => $drift_plan['plan_hash'],
		'quiescence_confirmed' => true, 'bootstrap_writers_confirmed' => true ) ), 'Drift capture reservation failed.' );
	$changed = false;
	$drift_error = null;
	do {
		wpai199c_assert( microtime( true ) < $deadline, 'Drift fixture runtime exhausted.' );
		$observed = $call( array( 'action' => 'export_step', 'session_id' => $drift['archive_id'] ) );
		if ( is_wp_error( $observed ) ) {
			$drift_error = $observed;
			break;
		}
		if ( ! $changed && false === $observed['complete'] ) {
			wpai199c_assert( ( new Mutation_Log() )->record( 'wp-ai-bridge/full-backup', 'backup', 0, false, 'backup_synthetic_capture_failure' ), 'Required diagnostic fixture did not persist.' );
			$changed = true;
		}
		wpai199c_assert( false === $observed['complete'], 'Source diagnostic drift was reported complete.' );
	} while ( true );
	wpai199c_assert( $changed && 'backup_source_stale' === $drift_error->get_error_code(), 'Source Activity drift was not detected honestly.' );
	$status = wpai199c_ok( $call( array( 'action' => 'export_status', 'session_id' => $drift['archive_id'] ) ), 'Failed capture status unavailable.' );
	wpai199c_assert( false === $status['complete'] && 'incomplete' === $status['state'], 'Returned capture failure was resumable as complete.' );
	wpai199c_ok( $call( array( 'action' => 'cancel', 'purpose' => 'backup_archive', 'session_id' => $drift['archive_id'] ) ), 'Failed archive byte cleanup failed.' );
	echo json_encode( array( 'test' => 'issue199-backup-native', 'status' => 'pass', 'database_tables' => count( $expected_db['tables'] ),
		'archive_bytes' => $export['bytes'], 'restored_root' => $restored_root, 'activation_pending' => true, 'seconds' => round( microtime( true ) - $started, 2 ) ), JSON_UNESCAPED_SLASHES ) . "\n";
} finally {
	// Keep the independently verified isolated destination for the coordinator's
	// derived bootstrap. Source receipts remain in this disposable DB for audit.
	foreach ( array( array( 'backup_archive', $archive_id ), array( 'backup_input', $input_id ) ) as $owned ) {
		if ( is_string( $owned[1] ) ) {
			$call( array( 'action' => 'cancel', 'purpose' => $owned[0], 'session_id' => $owned[1] ) );
		}
	}
	if ( is_string( $json_table ) ) {
		wpai199c_assert( $json_table === 'provider_admin13_json_' . $suffix, 'Refusing unrelated JSON fixture cleanup.' );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $json_table ) );
	}
	if ( is_string( $collision_table ) ) {
		wpai199c_assert( $collision_table === $collision_table_name, 'Refusing unrelated row-hash collision fixture cleanup.' );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $collision_table ) );
	}
	if ( is_array( $seed ) ) {
		wpai199c_cleanup_seed( $seed, $suffix );
	}
	wpai199c_restore_options( $before );
	wp_set_current_user( $original_user );
}
