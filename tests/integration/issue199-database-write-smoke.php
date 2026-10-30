<?php
/**
 * Real-engine atomic writes, exact bytes, stale state and recovery invariants.
 *
 * Run only on the private disposable single-site WordPress test installation.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Database_Write_Abilities;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-database-write-helper.php';

global $wpdb;
$actor = get_current_user_id();
$original = get_option( Settings::OPTION_NAME, false );
$database = get_option( Settings::DATABASE_OPTION_NAME, false );
$log = get_option( Mutation_Log::OPTION_NAME, false );
$receipts = wpai199w_receipts_before();
$suffix = bin2hex( random_bytes( 5 ) );
$table = $wpdb->prefix . 'wpai199w_' . $suffix;
$numeric = $wpdb->prefix . 'wpai199wn_' . $suffix;
$created = array();
$ordinary_option = 'plugin199w_ordinary_' . $suffix;
$alias_option = 'wp_ai_' . "\u{200B}" . 'bridge_199w_' . $suffix;
$workspace_post = 0;
$ordinary_post = 0;
$deny_log = null;

try {
	wpai199w_assert( ! is_multisite() && current_user_can( 'manage_options' ), 'Native typed-write smoke requires its disposable single-site administrator.' );
	$settings = new Settings();
	$settings->register();
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	update_option( Settings::DATABASE_OPTION_NAME, $settings->database_defaults(), false );
	wpai199w_error( wpai199w_call( array( 'action' => 'describe', 'table' => $table ) ), 'Default-off write Ability authorized execution.' );
	$grant_reads = 0;
	$alternating = static function () use ( &$grant_reads ) {
		++$grant_reads;
		return array( Settings::GROUP_DATABASE_INSPECTION => 1 === $grant_reads % 2 ? 1 : 0, Settings::GROUP_DATABASE_READ => 1 === $grant_reads % 2 ? 0 : 1, Settings::GROUP_DATABASE_WRITE => 1, Settings::GROUP_DATABASE_DELETE => 1 );
	};
	add_filter( 'pre_option_' . Settings::DATABASE_OPTION_NAME, $alternating, 10, 0 );
	try {
		$provider = new Database_Write_Abilities( new Permissions( $settings ) );
		wpai199w_assert( ! $provider->can_write() && 1 === $grant_reads, 'Write prerequisites combined policy bits from different protected snapshots.' );
	} finally {
		remove_filter( 'pre_option_' . Settings::DATABASE_OPTION_NAME, $alternating, 10 );
	}
	$grants = wpai199w_grants();
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, payload LONGBLOB NULL, amount DECIMAL(30,10) NOT NULL DEFAULT 0, note LONGTEXT NULL, other_value VARCHAR(100) NOT NULL DEFAULT \'untouched\') ENGINE=InnoDB', $table ) ), 'Cannot create transactional exact-value fixture.' );
	$created[] = $table;
	wpai199w_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (`0` VARBINARY(30) NOT NULL, `01` LONGBLOB NULL, PRIMARY KEY (`0`)) ENGINE=InnoDB', $numeric ) ), 'Cannot create exact numeric-name fixture.' );
	$created[] = $numeric;
	$description = wpai199w_describe( $table );
	wpai199w_assert( array( 'id' ) === $description['primary_key'], 'Ordered primary-key names changed.' );
	$schema = $description['schema_hash'];
	$marker = 'wpai199w-secret-' . $suffix;
	$binary = "\x00\xff\x80'\\\n" . $marker;
	$amount = '12345678901234567890.0123456789';
	$serialized = 'O:24:"WPAI199_NeverInstantiate":1:{s:5:"value";s:6:"opaque";}';
	$values = array( 'id' => base64_encode( '18446744073709551614' ), 'payload' => base64_encode( $binary ), 'amount' => base64_encode( $amount ), 'note' => base64_encode( $serialized ) );
	$insert = wpai199w_step( 'insert', $table, $schema, $values );
	$request = array( 'action' => 'execute', 'operation_id' => 'write-exact-' . $suffix, 'operations' => array( $insert ) );
	$result = wpai199w_ok( wpai199w_call( $request ), 'Exact insert failed.' );
	wpai199w_assert( 'committed' === $result['status'] && false === $result['historical'] && 'inserted' === $result['steps'][0]['outcome'] && 1 === $result['steps'][0]['affected'], 'Initial receipt omitted compact committed outcome.' );
	wpai199w_assert( 1 === wpai199w_count( $table, '18446744073709551614' ), 'BIGINT primary key lost precision.' );
	foreach ( array( 'payload' => $binary, 'amount' => $amount, 'note' => $serialized, 'other_value' => 'untouched' ) as $column => $expected ) {
		wpai199w_assert( $expected === wpai199w_bytes( $table, $column, '18446744073709551614' ), 'Insert normalized exact bytes for ' . $column );
	}
	$before = wpai199w_state( $table, wpai199w_key( '18446744073709551614' ) );
	wpai199w_assert( false === strpos( wp_json_encode( $before ), $marker ) && false === strpos( wp_json_encode( $result ), $marker ) && false === strpos( wp_json_encode( $result ), base64_encode( $binary ) ), 'State/receipt disclosed input values.' );
	$replayed = wpai199w_ok( wpai199w_call( $request ), 'Identical committed replay did not return its historical receipt.' );
	wpai199w_assert( true === $replayed['historical'] && $result['operation_ref'] === $replayed['operation_ref'], 'Historical replay changed operation identity.' );
	wpai199w_assert( 1 === wpai199w_count( $table, '18446744073709551614' ), 'Committed replay repeated an insert.' );
	$conflict = $request;
	$conflict['operations'][0]['values']['payload'] = base64_encode( 'different' );
	wpai199w_error( wpai199w_call( $conflict ), 'Same operation ID accepted a different request.', array( 'database_write_operation_conflict' ) );

	// A normal insert and a no-op each require only the receipt-final UPDATE.
	$count_before = wpai199w_updates();
	wpai199w_ok( wpai199w_execute( 'write-counter-' . $suffix, array( wpai199w_step( 'insert', $table, $schema, array( 'id' => base64_encode( '1' ), 'payload' => '', 'note' => null ) ) ) ), 'Control insert failed.' );
	$receipt_updates = wpai199w_updates() - $count_before;
	$noop = wpai199w_step( 'update', $table, $schema, array( 'payload' => '' ), '1' );
	$count_before = wpai199w_updates();
	$noop_result = wpai199w_ok( wpai199w_execute( 'write-noop-' . $suffix, array( $noop ) ), 'No-op update failed.' );
	wpai199w_assert( $receipt_updates === wpai199w_updates() - $count_before, 'No-op issued a target UPDATE in addition to the atomic receipt write.' );
	wpai199w_assert( 'unchanged' === $noop_result['steps'][0]['outcome'] && 0 === $noop_result['steps'][0]['affected'], 'No-op receipt claimed a target mutation.' );
	wpai199w_assert( '' === wpai199w_bytes( $table, 'payload', '1' ) && null === wpai199w_bytes( $table, 'note', '1' ), 'Empty and NULL became equivalent.' );

	$stale = wpai199w_step( 'update', $table, $schema, array( 'payload' => base64_encode( 'planned' ) ), '1' );
	$wpdb->update( $table, array( 'other_value' => 'concurrent unrelated change' ), array( 'id' => '1' ) );
	wpai199w_error( wpai199w_execute( 'write-stale-' . $suffix, array( $stale ) ), 'Full-row precondition ignored an unrelated-column change.', array( 'database_write_row_stale' ) );
	wpai199w_assert( '' === wpai199w_bytes( $table, 'payload', '1' ), 'Stale update changed target bytes.' );
	$fresh = wpai199w_step( 'update', $table, $schema, array( 'payload' => base64_encode( "سلام\0دنیا" ), 'note' => base64_encode( '' ) ), '1' );
	wpai199w_ok( wpai199w_execute( 'write-fresh-' . $suffix, array( $fresh ) ), 'Fresh exact update failed.' );
	wpai199w_assert( "سلام\0دنیا" === wpai199w_bytes( $table, 'payload', '1' ) && '' === wpai199w_bytes( $table, 'note', '1' ), 'RTL/NUL/empty update was normalized.' );
	$pk_change = wpai199w_step( 'update', $table, $schema, array( 'id' => base64_encode( '9' ) ), '1' );
	wpai199w_error( wpai199w_execute( 'write-pk-' . $suffix, array( $pk_change ) ), 'Update changed immutable mutation identity.' );
	wpai199w_assert( 1 === wpai199w_count( $table, '1' ) && 0 === wpai199w_count( $table, '9' ), 'Denied primary-key change had effects.' );

	$batch = array(
		wpai199w_step( 'insert', $table, $schema, array( 'id' => base64_encode( '2' ), 'payload' => base64_encode( 'first step' ) ) ),
		wpai199w_step( 'insert', $table, $schema, array( 'id' => base64_encode( '1' ), 'payload' => base64_encode( 'later step' ) ) ),
	);
	wpai199w_error( wpai199w_execute( 'write-rollback-' . $suffix, $batch ), 'A failed later step returned success.' );
	wpai199w_assert( 0 === wpai199w_count( $table, '2' ) && "سلام\0دنیا" === wpai199w_bytes( $table, 'payload', '1' ), 'Failed later step left partial effects.' );
	$wpdb->delete( $table, array( 'id' => '1' ) );
	wpai199w_ok( wpai199w_execute( 'write-rollback-' . $suffix, $batch ), 'Rolled-back identical attempt retained an immutable failed fingerprint.' );
	wpai199w_assert( 1 === wpai199w_count( $table, '2' ) && 'later step' === wpai199w_bytes( $table, 'payload', '1' ), 'Retry after proven rollback changed ordered batch semantics.' );

	$delete = wpai199w_step( 'delete', $table, $schema, array(), '2' );
	$delete_request = array( 'action' => 'execute', 'operation_id' => 'write-delete-' . $suffix, 'operations' => array( $delete ) );
	$grants[ Settings::GROUP_DATABASE_DELETE ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199w_error( wpai199w_call( $delete_request ), 'Delete executed without current destructive consent.' );
	wpai199w_assert( 1 === wpai199w_count( $table, '2' ), 'Denied delete had effects.' );
	$grants[ Settings::GROUP_DATABASE_DELETE ] = 1;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199w_ok( wpai199w_call( $delete_request ), 'Authorized delete failed.' );
	wpai199w_assert( 0 === wpai199w_count( $table, '2' ), 'Committed delete left its row.' );
	wpai199w_error( wpai199w_call( array( 'action' => 'recover', 'operation_id' => $delete_request['operation_id'] ) ), 'Delete recovery omitted explicit destructive consent.' );
	wpai199w_ok( wpai199w_call( array( 'action' => 'recover', 'operation_id' => $delete_request['operation_id'], 'destructive' => true ) ), 'Deleted-row historical recovery required current row existence.' );
	$grants[ Settings::GROUP_DATABASE_DELETE ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199w_error( wpai199w_call( $delete_request ), 'Committed delete replay bypassed current Delete revocation.' );
	wpai199w_error( wpai199w_call( array( 'action' => 'recover', 'operation_id' => $delete_request['operation_id'], 'destructive' => true ) ), 'Delete recovery bypassed current Delete revocation.' );
	$grants[ Settings::GROUP_DATABASE_DELETE ] = 1;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	$grants[ Settings::GROUP_DATABASE_WRITE ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199w_error( wpai199w_call( $request ), 'Committed nondelete replay bypassed current Write revocation.' );
	$grants[ Settings::GROUP_DATABASE_WRITE ] = 1;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );

	$numeric_schema = wpai199w_describe( $numeric )['schema_hash'];
	$numeric_insert = array( 'action' => 'insert', 'table' => $numeric, 'expected_schema_hash' => $numeric_schema, 'values' => (object) array( '0' => base64_encode( 'identity' ), '01' => base64_encode( "\0numeric" ) ) );
	wpai199w_ok( wpai199w_execute( 'write-numeric-' . $suffix, array( $numeric_insert ) ), 'Native numeric object columns were rejected.' );
	$numeric_state = wpai199w_state( $numeric, array( '0' => base64_encode( 'identity' ) ) );
	$numeric_update = array( 'action' => 'update', 'table' => $numeric, 'expected_schema_hash' => $numeric_schema, 'key' => (object) array( '0' => base64_encode( 'identity' ) ), 'expected_state_hash' => $numeric_state['state_hash'], 'values' => (object) array( '01' => base64_encode( 'changed' ) ) );
	wpai199w_ok( wpai199w_execute( 'write-numeric-update-' . $suffix, array( $numeric_update ) ), 'Numeric primary-key object changed identity.' );
	wpai199w_assert( 'changed' === $wpdb->get_var( $wpdb->prepare( 'SELECT `01` FROM %i WHERE `0` = %s', $numeric, 'identity' ) ), 'Exact numeric column names were conflated.' );

	$schema_stale = wpai199w_step( 'update', $table, $schema, array( 'payload' => base64_encode( 'schema stale' ) ), '1' );
	$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD extra_column INT NOT NULL DEFAULT 0', $table ) );
	wpai199w_error( wpai199w_execute( 'write-schema-stale-' . $suffix, array( $schema_stale ) ), 'A changed complete schema accepted the old schema hash.', array( 'database_write_schema_stale' ) );
	wpai199w_assert( 'later step' === wpai199w_bytes( $table, 'payload', '1' ), 'Stale schema had row effects.' );

	// Old AND proposed native-collation control identities must stay guarded.
	$options_schema = wpai199w_describe( $wpdb->options )['schema_hash'];
	add_option( $ordinary_option, $marker, '', false );
	$ordinary_id = $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name = %s', $wpdb->options, $ordinary_option ) );
	$protected_id = $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name = %s', $wpdb->options, Settings::DATABASE_OPTION_NAME ) );
	foreach ( array( array( $ordinary_id, strtoupper( Settings::DATABASE_OPTION_NAME ) ), array( $protected_id, 'wpai199w_unprotected_' . $suffix ) ) as $rename ) {
		$key = array( 'option_id' => base64_encode( (string) $rename[0] ) );
		$state = wpai199w_state( $wpdb->options, $key );
		$step = array( 'action' => 'update', 'table' => $wpdb->options, 'expected_schema_hash' => $options_schema, 'key' => $key, 'expected_state_hash' => $state['state_hash'], 'values' => array( 'option_name' => base64_encode( $rename[1] ) ) );
		wpai199w_error( wpai199w_execute( 'write-control-' . bin2hex( random_bytes( 4 ) ), array( $step ) ), 'Protected identity was renamed into/out of control state.' );
	}
	wpai199w_assert( $grants === get_option( Settings::DATABASE_OPTION_NAME ) && $marker === get_option( $ordinary_option ), 'Denied control rename changed authorization/ordinary state.' );
	// U+200B is native-collation ignorable here but is not a byte-prefix match.
	add_option( $alias_option, $marker, '', false );
	$alias_id = $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE BINARY option_name=BINARY %s', $wpdb->options, $alias_option ) );
	$equivalent = $wpdb->get_var( $wpdb->prepare( 'SELECT option_name=%s FROM %i WHERE option_id=%s', 'wp_ai_bridge_199w_' . $suffix, $wpdb->options, $alias_id ) );
	wpai199w_assert( '1' === (string) $equivalent, 'Ignorable-alias fixture does not share the actual options native collation identity.' );
	foreach ( array( array( $alias_id, 'plugin199w_alias_removed_' . $suffix ), array( $ordinary_id, $alias_option . '_proposed' ) ) as $rename ) {
		$key = array( 'option_id' => base64_encode( (string) $rename[0] ) );
		$alias_step = array( 'action' => 'update', 'table' => $wpdb->options, 'expected_schema_hash' => $options_schema, 'key' => $key, 'expected_state_hash' => wpai199w_state( $wpdb->options, $key )['state_hash'], 'values' => array( 'option_name' => base64_encode( $rename[1] ) ) );
		wpai199w_error( wpai199w_execute( 'write-ignorable-' . bin2hex( random_bytes( 4 ) ), array( $alias_step ) ), 'Native-collation ignorable old/proposed protected identity was writable.', array( 'database_write_control_state' ) );
	}
	$ordinary_key = array( 'option_id' => base64_encode( (string) $ordinary_id ) );
	$ordinary_update = array( 'action' => 'update', 'table' => $wpdb->options, 'expected_schema_hash' => $options_schema, 'key' => $ordinary_key, 'expected_state_hash' => wpai199w_state( $wpdb->options, $ordinary_key )['state_hash'], 'values' => array( 'option_value' => base64_encode( 'ordinary plugin secret changed' ) ) );
	wpai199w_ok( wpai199w_execute( 'write-ordinary-' . $suffix, array( $ordinary_update ) ), 'Explicit sensitive/write consent could not update an ordinary plugin secret.' );
	wp_cache_delete( $ordinary_option, 'options' );
	wpai199w_assert( 'ordinary plugin secret changed' === get_option( $ordinary_option ), 'Ordinary secret update was not exact.' );

	$workspace_post = wp_insert_post( array( 'post_type' => 'wpai_doc', 'post_status' => 'draft', 'post_title' => 'Canonical Workspace control fixture' ), true );
	$ordinary_post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Ordinary typed control fixture' ), true );
	wpai199w_assert( is_int( $workspace_post ) && $workspace_post > 0 && is_int( $ordinary_post ) && $ordinary_post > 0, 'Cannot create owned Workspace/post control identities.' );
	$posts_schema = wpai199w_describe( $wpdb->posts )['schema_hash'];
	foreach ( array( array( $workspace_post, array( 'post_title' => base64_encode( 'forbidden canonical mutation' ) ) ), array( $ordinary_post, array( 'post_type' => base64_encode( 'wpai_doc' ) ) ) ) as $control ) {
		$key = array( 'ID' => base64_encode( (string) $control[0] ) );
		$control_step = array( 'action' => 'update', 'table' => $wpdb->posts, 'expected_schema_hash' => $posts_schema, 'key' => $key, 'expected_state_hash' => wpai199w_state( $wpdb->posts, $key )['state_hash'], 'values' => $control[1] );
		wpai199w_error( wpai199w_execute( 'write-workspace-post-' . bin2hex( random_bytes( 4 ) ), array( $control_step ) ), 'Old/proposed canonical Workspace post identity was writable.', array( 'database_write_control_state' ) );
	}
	$ordinary_meta = add_post_meta( $ordinary_post, 'plugin199w_meta', 'ordinary meta', true );
	$canonical_meta = add_post_meta( $workspace_post, 'plugin199w_meta', 'canonical parent meta', true );
	$workspace_meta = add_post_meta( $ordinary_post, '_wpai_workspace_state', 'canonical state', true );
	$meta_schema = wpai199w_describe( $wpdb->postmeta )['schema_hash'];
	foreach ( array( array( $canonical_meta, array( 'meta_value' => base64_encode( 'forbidden parent' ) ) ), array( $workspace_meta, array( 'meta_key' => base64_encode( 'plugin199w_renamed' ) ) ), array( $ordinary_meta, array( 'post_id' => base64_encode( (string) $workspace_post ) ) ) ) as $control ) {
		$key = array( 'meta_id' => base64_encode( (string) $control[0] ) );
		$control_step = array( 'action' => 'update', 'table' => $wpdb->postmeta, 'expected_schema_hash' => $meta_schema, 'key' => $key, 'expected_state_hash' => wpai199w_state( $wpdb->postmeta, $key )['state_hash'], 'values' => $control[1] );
		wpai199w_error( wpai199w_execute( 'write-workspace-meta-' . bin2hex( random_bytes( 4 ) ), array( $control_step ) ), 'Canonical Workspace metadata old/proposed parent or key was writable.', array( 'database_write_control_state' ) );
	}

	// Post-COMMIT reporting failure cannot turn a committed row into retry advice.
	$schema = wpai199w_describe( $table )['schema_hash'];
	$deny_log = static function ( $value, $old ) { return $old; };
	add_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $deny_log, PHP_INT_MAX, 2 );
	$reporting_request = array( 'action' => 'execute', 'operation_id' => 'write-reporting-' . $suffix, 'operations' => array( wpai199w_step( 'insert', $table, $schema, array( 'id' => base64_encode( '3' ), 'payload' => base64_encode( 'committed despite reporting' ) ) ) ) );
	$reporting_result = wpai199w_ok( wpai199w_call( $reporting_request ), 'Post-COMMIT reporting failure was returned as an ordinary mutation failure.' );
	wpai199w_assert( 'committed' === $reporting_result['status'] && true === $reporting_result['reporting_pending'], 'Committed projection failure omitted its truthful reporting-pending classification.' );
	wpai199w_assert( 1 === wpai199w_count( $table, '3' ), 'Reporting failure lost committed target state.' );
	remove_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $deny_log, PHP_INT_MAX );
	$deny_log = null;
	wpai199w_ok( wpai199w_call( $reporting_request ), 'Reporting-pending committed replay did not recover.' );

	$receipt_json = wp_json_encode( $wpdb->get_col( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'wpai_database_write_' ) . '%' ) ) );
	$audit_json = wp_json_encode( get_option( Mutation_Log::OPTION_NAME, array() ) );
	foreach ( array( $marker, base64_encode( $binary ), $serialized, 'ordinary plugin secret changed' ) as $secret ) {
		wpai199w_assert( false === strpos( $receipt_json, $secret ) && false === strpos( $audit_json, $secret ), 'Audit/receipt persisted raw caller bytes.' );
	}
	echo 'PASS: Issue #199 native typed-write exact bytes, atomicity, staleness, controls, current authority and historical recovery (' . $GLOBALS['wpai199w_checks'] . " checks).\n";
} finally {
	if ( null !== $deny_log ) {
		remove_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $deny_log, PHP_INT_MAX );
	}
	wp_set_current_user( $actor );
	if ( ! is_wp_error( $workspace_post ) && $workspace_post > 0 ) {
		wp_delete_post( $workspace_post, true );
	}
	if ( ! is_wp_error( $ordinary_post ) && $ordinary_post > 0 ) {
		wp_delete_post( $ordinary_post, true );
	}
	delete_option( $ordinary_option );
	delete_option( $alias_option );
	foreach ( array_reverse( $created ) as $fixture ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $fixture ) );
	}
	wpai199w_restore_receipts( $receipts );
	wpai199w_restore_option( Settings::OPTION_NAME, $original );
	wpai199w_restore_option( Settings::DATABASE_OPTION_NAME, $database );
	wpai199w_restore_option( Mutation_Log::OPTION_NAME, $log );
}
