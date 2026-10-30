<?php
/**
 * Deliberate least-privilege compatibility regression.
 *
 * Master/CI must select WPAI199W_VISIBILITY=fk with native PROCESS absent, or
 * =trigger with native TRIGGER absent. This fixture never changes DB privileges.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-database-write-helper.php';

global $wpdb;
$mode = getenv( 'WPAI199W_VISIBILITY' );
wpai199w_assert( in_array( $mode, array( 'fk', 'trigger' ), true ), 'Explicit least-privilege visibility test mode required.' );
$database = get_option( Settings::DATABASE_OPTION_NAME, false );
$log = get_option( Mutation_Log::OPTION_NAME, false );
$receipts = wpai199w_receipts_before();
$table = $wpdb->prefix . 'wpai199wv_' . bin2hex( random_bytes( 5 ) );
$created = false;

try {
	wpai199w_assert( current_user_can( 'manage_options' ), 'Visibility fixture requires its synthetic administrator.' );
	$settings = new Settings();
	$settings->register();
	update_option( Settings::DATABASE_OPTION_NAME, wpai199w_grants(), false );
	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, indexed_value INT NOT NULL, payload LONGBLOB NULL, KEY lookup_value(indexed_value)) ENGINE=InnoDB', $table ) ), 'Cannot create least-privilege target.' );
	$created = true;
	$wpdb->insert( $table, array( 'id' => 1, 'indexed_value' => 10, 'payload' => 'unchanged' ) );
	if ( 'trigger' === $mode ) {
		$listing = $wpdb->get_results( $wpdb->prepare( 'SHOW TRIGGERS WHERE %i=%s', 'Table', $table ), ARRAY_A );
		wpai199w_assert( is_array( $listing ) && ! $listing, 'Hidden trigger-listing precondition did not produce an empty native result.' );
		wpai199w_error( wpai199w_call( array( 'action' => 'describe', 'table' => $table ) ), 'Empty privilege-filtered trigger listing authorized write eligibility.', array( 'database_write_trigger_visibility' ) );
	} else {
		$native_view = false !== stripos( (string) $wpdb->get_var( 'SELECT VERSION()' ), 'MariaDB' ) ? 'INNODB_SYS_FOREIGN' : 'INNODB_FOREIGN';
		$wpdb->get_results( 'SELECT TYPE FROM information_schema.' . $native_view . ' LIMIT 1', ARRAY_A );
		wpai199w_assert( '' !== $wpdb->last_error, 'Native FK visibility must actually be unavailable for this discriminating fixture.' );
		$schema = wpai199w_describe( $table )['schema_hash'];
		$noop = wpai199w_step( 'update', $table, $schema, array( 'indexed_value' => base64_encode( '10' ) ), '1' );
		$result = wpai199w_ok( wpai199w_execute( 'write-no-fk-noop-' . bin2hex( random_bytes( 5 ) ), array( $noop ) ), 'Indexed no-op incorrectly required native FK graph privileges.' );
		wpai199w_assert( 'unchanged' === $result['steps'][0]['outcome'] && 0 === $result['steps'][0]['affected'], 'No-op without graph visibility claimed target effects.' );
		$nonindexed = wpai199w_step( 'update', $table, $schema, array( 'payload' => base64_encode( 'nonindexed allowed' ) ), '1' );
		wpai199w_ok( wpai199w_execute( 'write-no-fk-nonindex-' . bin2hex( random_bytes( 5 ) ), array( $nonindexed ) ), 'Nonindexed update unnecessarily required native FK graph privileges.' );
		$indexed = wpai199w_step( 'update', $table, $schema, array( 'indexed_value' => base64_encode( '11' ) ), '1' );
		wpai199w_error( wpai199w_execute( 'write-no-fk-index-' . bin2hex( random_bytes( 5 ) ), array( $indexed ) ), 'Indexed mutation guessed an incomplete FK graph.', array( 'database_write_fk_visibility' ) );
		wpai199w_error( wpai199w_execute( 'write-no-fk-delete-' . bin2hex( random_bytes( 5 ) ), array( wpai199w_step( 'delete', $table, $schema, array(), '1' ) ) ), 'Delete guessed an incomplete FK graph.', array( 'database_write_fk_visibility' ) );
		wpai199w_assert( '10' === wpai199w_bytes( $table, 'indexed_value', '1' ) && 'nonindexed allowed' === wpai199w_bytes( $table, 'payload', '1' ), 'Denied graph-dependent mutation changed exact state.' );
	}
	wpai199w_assert( 1 === wpai199w_count( $table, '1' ), 'Visibility rejection deleted target state.' );
	echo 'PASS: Issue #199 deliberately unavailable native ' . $mode . " visibility, safe no-op/nonindexed boundaries and unchanged denied effects.\n";
} finally {
	if ( $created ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	}
	wpai199w_restore_receipts( $receipts );
	wpai199w_restore_option( Settings::DATABASE_OPTION_NAME, $database );
	wpai199w_restore_option( Mutation_Log::OPTION_NAME, $log );
}
