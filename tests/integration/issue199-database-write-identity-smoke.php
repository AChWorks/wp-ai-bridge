<?php
/**
 * Native identifier character limits and filename-encoded FK identities.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-database-write-helper.php';

global $wpdb;
$actor = get_current_user_id();
$database = get_option( Settings::DATABASE_OPTION_NAME, false );
$log = get_option( Mutation_Log::OPTION_NAME, false );
$receipts = wpai199w_receipts_before();
$suffix = bin2hex( random_bytes( 4 ) );
$parent = $wpdb->prefix . 'wpai199w-پدر-' . $suffix;
$child = $wpdb->prefix . 'wpai199wid_' . $suffix;
$unicode = $wpdb->prefix . str_repeat( 'د', 35 );
$auto = $wpdb->prefix . 'wpai199wa_' . $suffix;
$created = array();
$binary_default = $wpdb->prefix . 'wpai199wb_' . $suffix;

try {
	wpai199w_assert( ! is_multisite() && current_user_can( 'manage_options' ), 'Identity fixture requires disposable administrator.' );
	$settings = new Settings();
	$settings->register();
	update_option( Settings::DATABASE_OPTION_NAME, wpai199w_grants(), false );
	foreach ( array( $parent, $unicode, $auto ) as $name ) {
		$definition = $auto === $name ? '(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, payload LONGBLOB NULL)' : '(id INT NOT NULL PRIMARY KEY, payload LONGBLOB NULL)';
		wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ' . $definition . ' ENGINE=InnoDB', $name ) ), 'Cannot create native special-name identity fixture.' );
		$created[] = $name;
	}
	wpai199w_assert( strlen( $unicode ) > 64 && preg_match_all( '/./us', $unicode ) <= 64, 'Unicode identifier does not distinguish characters from bytes.' );
	$unicode_schema = wpai199w_describe( $unicode )['schema_hash'];
	wpai199w_ok( wpai199w_execute( 'write-unicode-' . $suffix, array( wpai199w_step( 'insert', $unicode, $unicode_schema, array( 'id' => base64_encode( '1' ), 'payload' => base64_encode( 'exact Unicode identifier' ) ) ) ) ), 'A valid native identifier longer than64 UTF8 bytes was rejected.' );
	wpai199w_assert( 'exact Unicode identifier' === wpai199w_bytes( $unicode, 'payload', '1' ), 'Unicode identifier addressed a different table.' );

	$auto_schema = wpai199w_describe( $auto )['schema_hash'];
	$auto_result = wpai199w_ok( wpai199w_execute( 'write-default-auto-' . $suffix, array( wpai199w_step( 'insert', $auto, $auto_schema, array() ) ) ), 'All-default eligible auto-increment insert failed.' );
	wpai199w_assert( '1' === $auto_result['steps'][0]['auto_id'] && is_string( $auto_result['steps'][0]['auto_id'] ) && null === wpai199w_bytes( $auto, 'payload', '1' ), 'Auto-increment ID was not captured as an exact decimal string.' );
	$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i AUTO_INCREMENT=18446744073709551614', $auto ) );
	$large_auto_schema = wpai199w_describe( $auto )['schema_hash'];
	$large_auto = wpai199w_ok( wpai199w_execute( 'write-large-auto-' . $suffix, array( wpai199w_step( 'insert', $auto, $large_auto_schema, array() ) ) ), 'Large automatic identity insert failed.' );
	wpai199w_assert( '18446744073709551614' === $large_auto['steps'][0]['auto_id'] && 1 === wpai199w_count( $auto, '18446744073709551614' ), 'Native automatic BIGINT identity lost decimal precision.' );


	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( "CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, payload VARBINARY(4) DEFAULT X'FF00') ENGINE=InnoDB", $binary_default ) ), 'Cannot create binary-literal default fixture.' );
	$created[] = $binary_default;
	$wpdb->insert( $binary_default, array( 'id' => 1 ) );
	$default = $wpdb->get_var( $wpdb->prepare( 'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME=BINARY %s AND COLUMN_NAME=%s', $binary_default, 'payload' ) );
	echo 'WPAI199W_BINARY_DEFAULT_METADATA_UTF8=' . (int) ( 1 === preg_match( '//u', $default ) ) . '; bytes=' . strlen( $default ) . '; sha256=' . hash( 'sha256', $default ) . "\n";
	$binary_description = wpai199w_call( array( 'action' => 'describe', 'table' => $binary_default ) );
	if ( is_wp_error( $binary_description ) ) {
		wpai199w_error( $binary_description, 'Binary metadata did not fail closed.', array( 'database_write_unsupported_schema' ) );
	} else {
		$binary_state = wpai199w_state( $binary_default, wpai199w_key( '1' ) );
		$old_step = array( 'action' => 'update', 'table' => $binary_default, 'expected_schema_hash' => $binary_description['schema_hash'], 'key' => wpai199w_key( '1' ), 'expected_state_hash' => $binary_state['state_hash'], 'values' => array( 'payload' => base64_encode( 'new' ) ) );
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ALTER COLUMN payload SET DEFAULT X'FE00'", $binary_default ) );
		wpai199w_error( wpai199w_execute( 'write-binary-default-' . $suffix, array( $old_step ) ), 'Binary metadata schema hash ignored a changed literal default.', array( 'database_write_schema_stale' ) );
		wpai199w_assert( "\xff\x00" === wpai199w_bytes( $binary_default, 'payload', '1' ), 'Binary default schema-stale rejection changed existing exact bytes.' );
	}

	$constraint = 'fk_identity_' . $suffix;
	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, parent_id INT NOT NULL, CONSTRAINT %i FOREIGN KEY(parent_id) REFERENCES %i(id) ON DELETE CASCADE) ENGINE=InnoDB', $child, $constraint, $parent ) ), 'Cannot create native FK against special-character table.' );
	$created[] = $child;
	$wpdb->insert( $parent, array( 'id' => 1, 'payload' => 'parent remains' ) );
	$wpdb->insert( $child, array( 'id' => 1, 'parent_id' => 1 ) );
	$native_view = false !== stripos( (string) $wpdb->get_var( 'SELECT VERSION()' ), 'MariaDB' ) ? 'INNODB_SYS_FOREIGN' : 'INNODB_FOREIGN';
	$native_ref = $wpdb->get_var( $wpdb->prepare( 'SELECT REF_NAME FROM information_schema.' . $native_view . ' WHERE ID LIKE %s', '%/' . $wpdb->esc_like( $constraint ) ) );
	wpai199w_assert( is_string( $native_ref ) && '' !== $native_ref, 'Complete native FK metadata identity unavailable.' );
	echo 'WPAI199W_NATIVE_FK_REFERENCE=' . $native_ref . "\n";
	$schema = wpai199w_describe( $parent )['schema_hash'];
	$guard_result = wpai199w_execute( 'write-special-fk-' . $suffix, array( wpai199w_step( 'delete', $parent, $schema, array(), '1' ) ) );
	$guard_state = array( 'native_reference' => $native_ref, 'error' => is_wp_error( $guard_result ) ? $guard_result->get_error_code() : null, 'status' => is_array( $guard_result ) ? ( $guard_result['status'] ?? null ) : null, 'parent_rows' => wpai199w_count( $parent, '1' ), 'child_rows' => wpai199w_count( $child, '1' ) );
	echo 'WPAI199W_FK_GUARD_RESULT=' . wp_json_encode( $guard_state ) . "\n";
	wpai199w_error( $guard_result, 'Special-character native FK identity bypassed the mutating-cascade guard.', array( 'database_write_fk_effects' ) );
	wpai199w_assert( 1 === wpai199w_count( $parent, '1' ) && 1 === wpai199w_count( $child, '1' ), 'Denied filename-encoded FK cascade changed parent/child state.' );
	echo "PASS: Issue #199 native UTF8 identifier characters, default auto-ID and special-character FK effect identity.\n";
} finally {
	foreach ( array_reverse( $created ) as $fixture ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $fixture ) );
	}
	wpai199w_restore_receipts( $receipts );
	wpai199w_restore_option( Settings::DATABASE_OPTION_NAME, $database );
	wpai199w_restore_option( Mutation_Log::OPTION_NAME, $log );
	wp_set_current_user( $actor );
}
