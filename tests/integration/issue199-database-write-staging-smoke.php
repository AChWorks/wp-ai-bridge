<?php
/**
 * Native large-value staging, internal source coordination and streaming proof.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Database_Staging;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Transfer_Session;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-database-write-helper.php';

global $wpdb;
$actor = get_current_user_id();
$original = get_option( Settings::OPTION_NAME, false );
$database = get_option( Settings::DATABASE_OPTION_NAME, false );
$log = get_option( Mutation_Log::OPTION_NAME, false );
$receipts = wpai199w_receipts_before();
$table = $wpdb->prefix . 'wpai199ws_' . bin2hex( random_bytes( 5 ) );
$created = false;
$key_table = $table . '_key';
$key_created = false;
$ids = array();
$other = 0;
$stream = null;

try {
	wpai199w_assert( ! is_multisite() && current_user_can( 'manage_options' ), 'Staging smoke requires its disposable administrator.' );
	$settings = new Settings();
	$settings->register();
	$ordinary = $settings->defaults();
	$ordinary[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $ordinary, false );
	update_option( Settings::DATABASE_OPTION_NAME, wpai199w_grants(), false );
	$permissions = new Permissions( $settings );
	$generic = new Private_Transfer_Session( $permissions );
	$staging = new Database_Staging( $permissions );
	wpai199w_error( $generic->begin( 'database_value', 0, hash( 'sha256', '' ), 1024, 128, 2, 600 ), 'Generic transfer authority created a database-purpose source.' );
	$media = $generic->begin( 'media', 0, hash( 'sha256', '' ), 1024, 128, 2, 600 );
	wpai199w_assert( ! is_wp_error( $media ), 'Cannot create isolated generic-purpose fixture.' );
	$media_id = $media['id'];
	wpai199w_error( wpai199w_call( array( 'action' => 'stage_status', 'transfer_id' => $media_id ) ), 'Database staging authority accessed a generic-purpose source.' );
	wpai199w_assert( true === $generic->cancel( $media_id ), 'Generic source cleanup failed.' );
	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, payload LONGBLOB NULL) ENGINE=InnoDB', $table ) ), 'Cannot create large-value target.' );
	$created = true;
	$wpdb->insert( $table, array( 'id' => 1, 'payload' => 'original' ) );
	$schema = wpai199w_describe( $table )['schema_hash'];
	$total = 4194304;
	// Hash and stage repeated bounded pieces; never create a 4MiB PHP string.
	$pattern = str_repeat( "\x00\xff\x80'\\سلام", 2048 );
	$hash_context = hash_init( 'sha256' );
	for ( $offset = 0; $offset < $total; $offset += strlen( $piece ) ) {
		$piece = substr( $pattern, 0, min( strlen( $pattern ), $total - $offset ) );
		hash_update( $hash_context, $piece );
	}
	$hash = hash_final( $hash_context );
	$open = wpai199w_ok( wpai199w_call( array( 'action' => 'stage_open', 'total_bytes' => $total, 'sha256' => $hash ) ), 'Cannot open 4MiB database value.' );
	$id = $open['transfer_id'];
	$ids[] = $id;
	wpai199w_assert( 'database_value' === $open['purpose'] && false === strpos( wp_json_encode( $open ), '/srv/' ), 'Staging exposed a path or changed fixed purpose.' );
	wpai199w_error( $generic->status( $id ), 'Generic transfer consumer acquired database bytes.' );
	wpai199w_error( wpai199w_call( array( 'action' => 'stage_verify', 'transfer_id' => $id ) ), 'Incomplete source was verified.' );
	$piece_limit = min( strlen( $pattern ), $open['max_chunk_bytes'] );
	$offset = 0;
	while ( $offset < $total ) {
		$piece = substr( $pattern . $pattern, $offset % strlen( $pattern ), min( $piece_limit, $total - $offset ) );
		$append = array( 'action' => 'stage_append', 'transfer_id' => $id, 'offset' => $offset, 'content_base64' => base64_encode( $piece ) );
		$next = wpai199w_ok( wpai199w_call( $append ), 'Bounded database staging append failed.' );
		if ( 0 === $offset ) {
			wpai199w_ok( wpai199w_call( $append ), 'Identical appended byte retry failed.' );
			$changed = $append;
			$changed['content_base64'] = base64_encode( str_repeat( '?', strlen( $piece ) ) );
			wpai199w_error( wpai199w_call( $changed ), 'Conflicting appended-byte retry was overwritten.' );
		}
		$offset += strlen( $piece );
		wpai199w_assert( $offset === $next['received_bytes'], 'Staging continuation repeated or skipped bytes.' );
	}
	wpai199w_assert( 'original' === wpai199w_bytes( $table, 'payload', '1' ), 'Staging changed its future database target.' );
	$verified = wpai199w_ok( wpai199w_call( array( 'action' => 'stage_verify', 'transfer_id' => $id ) ), 'Exact 4MiB source could not be verified.' );
	wpai199w_assert( 'verified' === $verified['state'] && $hash === $verified['sha256'] && $total === $verified['total_bytes'], 'Verified source digest/size changed.' );
	$descriptor = array( 'transfer_id' => $id, 'total_bytes' => $total, 'sha256' => $hash );
	$mismatch = $descriptor;
	$mismatch['sha256'] = str_repeat( 'a', 64 );
	wpai199w_error( $staging->with_sources( array( $mismatch ), static function () { throw new RuntimeException( 'Mismatched descriptor reached source consumer.' ); } ), 'Source descriptor ignored expected SHA256.' );

	$thrown = false;
	try {
		$staging->with_sources( array( $descriptor, $descriptor ), static function ( $sources ) use ( $id, &$stream, $permissions ) {
			wpai199w_assert( 1 === count( $sources ), 'Duplicate source descriptor acquired multiple coordination locks.' );
			$stream = $sources[ $id ]['stream'];
			wpai199w_assert( is_resource( $stream ) && "\x00\xff" === fread( $stream, 2 ), 'Consumer did not receive verified exact stream bytes.' );
			foreach ( array( 'stage_status', 'stage_verify', 'stage_cancel', 'stage_release', 'stage_append' ) as $action ) {
				$input = array( 'action' => $action, 'transfer_id' => $id );
				if ( 'stage_append' === $action ) {
					$input['offset'] = 0;
					$input['content_base64'] = base64_encode( 'x' );
				}
				$busy = ( new Database_Staging( $permissions ) )->execute( $input );
				wpai199w_error( $busy, 'Reentrant source lifecycle escaped coordinator ownership.', array( 'transfer_session_busy' ) );
			}
			throw new RuntimeException( 'wpai199w-source-callback-checkpoint' );
		} );
	} catch ( RuntimeException $error ) {
		if ( 'wpai199w-source-callback-checkpoint' !== $error->getMessage() ) {
			throw $error;
		}
		$thrown = true;
	}
	wpai199w_assert( $thrown && ! is_resource( $stream ), 'Throwing source callback leaked an open descriptor.' );
	wpai199w_ok( wpai199w_call( array( 'action' => 'stage_status', 'transfer_id' => $id ) ), 'Callback failure leaked the source coordinator.' );
	$other = wp_insert_user( array( 'user_login' => 'wpai199w-stage-' . bin2hex( random_bytes( 4 ) ), 'user_pass' => wp_generate_password( 32 ), 'user_email' => bin2hex( random_bytes( 5 ) ) . '@example.invalid', 'role' => 'administrator' ) );
	wpai199w_assert( is_int( $other ) && $other > 0, 'Cannot create independent administrator.' );
	wp_set_current_user( $other );
	wpai199w_error( wpai199w_call( array( 'action' => 'stage_status', 'transfer_id' => $id ) ), 'An unrelated administrator acquired private database bytes.' );
	wp_set_current_user( $actor );
	$grants = wpai199w_grants();
	$grants[ Settings::GROUP_DATABASE_WRITE ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199w_error( wpai199w_call( array( 'action' => 'stage_status', 'transfer_id' => $id ) ), 'A staged lease bypassed current Write revocation.' );
	update_option( Settings::DATABASE_OPTION_NAME, wpai199w_grants(), false );

	$step = wpai199w_step( 'update', $table, $schema, array( 'payload' => $descriptor ), '1' );
	$request = array( 'action' => 'execute', 'operation_id' => 'write-stream-' . bin2hex( random_bytes( 5 ) ), 'operations' => array( $step ) );
	unset( $pattern, $piece, $append, $changed, $next );
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		memory_reset_peak_usage();
	}
	$memory_before = memory_get_usage();
	$allocated_before = memory_get_usage( true );
	$result = wpai199w_ok( wpai199w_call( $request ), 'Verified source did not stream into one native prepared update.' );
	$peak_delta = memory_get_peak_usage() - $memory_before;
	$allocated_delta = memory_get_peak_usage( true ) - $allocated_before;
	$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT OCTET_LENGTH(payload) AS bytes, LOWER(SHA2(payload,256)) AS sha FROM %i WHERE id = 1', $table ), ARRAY_A );
	wpai199w_assert( (string) $total === (string) $stored['bytes'] && $hash === $stored['sha'], 'Streamed 4MiB value failed full SQL length/SHA verification.' );
	wpai199w_assert( $peak_delta < 2097152, 'Streaming materialized too much of the 4MiB source in PHP.' );

	// A complete oversized mutation key uses the same verified stream contract.
	// Prefix indexing does not authorize a hash-only or positional identity.
	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (large_key LONGBLOB NOT NULL, payload LONGBLOB NULL, PRIMARY KEY (large_key(16))) ENGINE=InnoDB', $key_table ) ), 'Cannot create oversized complete-key fixture.' );
	$key_created = true;
	$key_schema = wpai199w_describe( $key_table )['schema_hash'];
	$key_insert = array( 'action' => 'insert', 'table' => $key_table, 'expected_schema_hash' => $key_schema, 'values' => array( 'large_key' => $descriptor, 'payload' => base64_encode( 'key target' ) ) );
	wpai199w_ok( wpai199w_execute( 'write-stream-key-insert-' . bin2hex( random_bytes( 5 ) ), array( $key_insert ) ), 'Oversized explicit primary-key insert failed.' );
	$key = array( 'large_key' => $descriptor );
	$key_state = wpai199w_state( $key_table, $key );
	$key_update = array( 'action' => 'update', 'table' => $key_table, 'expected_schema_hash' => $key_schema, 'key' => (object) $key, 'expected_state_hash' => $key_state['state_hash'], 'values' => array( 'payload' => base64_encode( 'streamed complete key matched' ) ) );
	wpai199w_ok( wpai199w_execute( 'write-stream-key-update-' . bin2hex( random_bytes( 5 ) ), array( $key_update ) ), 'Oversized complete key could not target an exact update.' );
	$key_summary = $wpdb->get_row( $wpdb->prepare( 'SELECT OCTET_LENGTH(large_key) AS bytes, LOWER(SHA2(large_key,256)) AS sha, payload FROM %i', $key_table ), ARRAY_A );
	wpai199w_assert( (string) $total === (string) $key_summary['bytes'] && $hash === $key_summary['sha'] && 'streamed complete key matched' === $key_summary['payload'], 'Oversized primary key was truncated/hashed instead of matched by full bytes.' );
	wpai199w_error( wpai199w_call( array( 'action' => 'row_state', 'table' => $key_table, 'key_hashes' => array( 'large_key' => $hash ) ) ), 'Hash-only mutation identity was accepted.' );
	$key_delete = array( 'action' => 'delete', 'table' => $key_table, 'expected_schema_hash' => $key_schema, 'key' => $key, 'expected_state_hash' => wpai199w_state( $key_table, $key )['state_hash'] );
	wpai199w_ok( wpai199w_execute( 'write-stream-key-delete-' . bin2hex( random_bytes( 5 ) ), array( $key_delete ) ), 'Oversized complete key could not target an exact delete.' );
	wpai199w_assert( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $key_table ) ), 'Oversized complete-key delete left its row.' );
	wpai199w_ok( wpai199w_call( array( 'action' => 'stage_release', 'transfer_id' => $id ) ), 'Consumed source could not be released.' );
	$ids = array();
	wpai199w_ok( wpai199w_call( $request ), 'Committed receipt lookup depended on a released/expired staged file.' );
	$restaged = wpai199w_ok( wpai199w_call( array( 'action' => 'stage_open', 'total_bytes' => 0, 'sha256' => hash( 'sha256', '' ) ) ), 'Cannot open independent empty source.' );
	$ids[] = $restaged['transfer_id'];
	wpai199w_ok( wpai199w_call( array( 'action' => 'stage_verify', 'transfer_id' => $restaged['transfer_id'] ) ), 'Empty staged value could not be verified.' );
	$zero_request = array( 'action' => 'execute', 'operation_id' => 'write-zero-' . bin2hex( random_bytes( 5 ) ), 'operations' => array( wpai199w_step( 'update', $table, $schema, array( 'payload' => array( 'transfer_id' => $restaged['transfer_id'], 'total_bytes' => 0, 'sha256' => hash( 'sha256', '' ) ) ), '1' ) ) );
	wpai199w_ok( wpai199w_call( $zero_request ), 'Zero-byte staged value was treated as NULL/omission.' );
	$equivalent = wpai199w_ok( wpai199w_call( array( 'action' => 'stage_open', 'total_bytes' => 0, 'sha256' => hash( 'sha256', '' ) ) ), 'Cannot restage equivalent bytes independently.' );
	$ids[] = $equivalent['transfer_id'];
	wpai199w_ok( wpai199w_call( array( 'action' => 'stage_verify', 'transfer_id' => $equivalent['transfer_id'] ) ), 'Equivalent restaged bytes did not verify.' );
	$zero_request['operations'][0]['values']['payload']['transfer_id'] = $equivalent['transfer_id'];
	$historical = wpai199w_ok( wpai199w_call( $zero_request ), 'Equivalent restaged bytes changed the request fingerprint.' );
	wpai199w_assert( true === $historical['historical'], 'Equivalent restaged bytes repeated an already committed effect.' );
	wpai199w_assert( '' === wpai199w_bytes( $table, 'payload', '1' ), 'Empty staged value changed exact semantics.' );
	echo 'PASS: Issue #199 4194304-byte native streamed write; full SHA256=' . $hash . '; PHP live peak delta=' . $peak_delta . '; allocated peak delta=' . $allocated_delta . " bytes.\n";
} finally {
	wp_set_current_user( $actor );
	update_option( Settings::DATABASE_OPTION_NAME, wpai199w_grants(), false );
	foreach ( $ids as $id ) {
		wpai199w_call( array( 'action' => 'stage_cancel', 'transfer_id' => $id ) );
	}
	if ( $other > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $other );
	}
	if ( $key_created ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $key_table ) );
	}
	if ( $created ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	}
	wpai199w_restore_receipts( $receipts );
	wpai199w_restore_option( Settings::OPTION_NAME, $original );
	wpai199w_restore_option( Settings::DATABASE_OPTION_NAME, $database );
	wpai199w_restore_option( Mutation_Log::OPTION_NAME, $log );
}
