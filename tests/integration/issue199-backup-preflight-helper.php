<?php
/**
 * Real API preflight acceptance using deliberately self-consistent bad packages.
 * Caller supplies the already guarded disposable native fixture and empty target.
 *
 * @package WP_AI_Bridge
 */
require_once dirname( __DIR__ ) . '/fixtures/issue199-backup-package.php';

function wpai199b_preflight_integrity_cases( callable $call, $schema, $root, $deadline ) {
	global $wpdb;
	wpai199c_assert( defined( 'WPAI_ADMIN13_DISPOSABLE' ) && true === WPAI_ADMIN13_DISPOSABLE &&
		is_string( getenv( 'WPAI199C_EXPECT_DATABASE' ) ) &&
		getenv( 'WPAI199C_EXPECT_DATABASE' ) === $wpdb->get_var( 'SELECT DATABASE()' ), 'Explicit guarded disposable database required for preflight fixtures.' );
	$before_database = wpai199c_database_image( $wpdb->dbh, $schema );
	$before_files = wpai199c_file_image( $root );
	foreach ( array(
		'wrong_cell_sha' => 'backup_archive_cell_integrity',
		'wrong_payload' => 'backup_archive_cell_integrity',
		'wrong_empty_sha' => 'backup_archive_cell_integrity',
		'wrong_type' => 'backup_schema_metadata_mismatch',
		'wrong_binary_charset' => 'backup_schema_metadata_mismatch',
		'wrong_text_charset' => 'backup_schema_metadata_mismatch',
		'' => null,
	) as $fault => $expected_error ) {
		$package = WPAI_Backup_Package_Fixture::make( $fault );
		$input = null;
		$job = null;
		try {
			$opened = wpai199c_ok( $call( array( 'action' => 'input_open', 'max_total_bytes' => $package['bytes'] ) ), 'Integrity fixture reservation failed.' );
			$input = $opened['session_id'];
			$offset = 0;
			foreach ( $package['frames'] as $frame ) {
				wpai199c_assert( microtime( true ) < $deadline, 'Integrity fixture test budget exhausted.' );
				wpai199c_ok( $call( array( 'action' => 'input_append', 'session_id' => $input, 'offset' => $offset,
					'content_base64' => base64_encode( $frame ), 'sha256' => hash( 'sha256', $frame ) ) ), 'A complete correctly framed integrity fixture was rejected by transport.' );
				$offset += strlen( $frame );
			}
			wpai199c_ok( $call( array( 'action' => 'input_seal', 'session_id' => $input, 'offset' => $offset, 'sha256' => $package['chain'] ) ), 'Integrity fixture frame-chain seal failed.' );
			$prepared = wpai199c_ok( $call( array( 'action' => 'restore_prepare', 'archive_id' => $input, 'archive_sha256' => $package['chain'],
				'archive_purpose' => 'backup_input', 'target_schema' => $schema, 'target_root' => $root ) ), 'Integrity fixture prepare failed before package validation.' );
			$job = $prepared['session_id'];
			do {
				wpai199c_assert( microtime( true ) < $deadline, 'Integrity fixture preflight budget exhausted.' );
				$result = $call( array( 'action' => 'restore_step', 'session_id' => $job ) );
			} while ( ! is_wp_error( $result ) && 'ready' !== $result['state'] );
			if ( null === $expected_error ) {
				wpai199c_assert( ! is_wp_error( $result ) && 'ready' === $result['state'] && false === $result['complete'],
					'Valid large/chunked/NULL/empty/binary package did not reach preflight ready.' );
			} else {
				wpai199c_assert( is_wp_error( $result ) && $expected_error === $result->get_error_code(),
					'Malformed fully re-framed package passed complete preflight or returned the wrong guard.' );
			}
			wpai199c_assert( $before_database === wpai199c_database_image( $wpdb->dbh, $schema ), 'Preflight integrity rejection changed target schema/data.' );
			wpai199c_assert( $before_files === wpai199c_file_image( $root ), 'Preflight integrity rejection changed target files.' );
		} finally {
			if ( is_string( $job ) ) {
				wpai199c_ok( $call( array( 'action' => 'cancel', 'session_id' => $job, 'purpose' => 'backup_state' ) ), 'Preflight job cleanup failed.' );
			}
			if ( is_string( $input ) ) {
				wpai199c_ok( $call( array( 'action' => 'cancel', 'session_id' => $input, 'purpose' => 'backup_input' ) ), 'Preflight input cleanup failed.' );
			}
		}
	}
}
