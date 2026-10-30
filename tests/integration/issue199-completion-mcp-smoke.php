<?php
/**
 * Actual canonical OAuth/MCP coverage for every new administration family.
 * Run only after the coordinator installs a stable candidate in disposable Core.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-completion-helper.php';
require_once __DIR__ . '/issue134-review-helper.php';

wpai199c_assert( defined( 'WPAI_ADMIN13_DISPOSABLE' ) && true === WPAI_ADMIN13_DISPOSABLE, 'Explicit disposable Core bootstrap required.' );
if ( ! function_exists( 'wpai199c_seed_provider' ) ) {
	require_once dirname( __DIR__ ) . '/fixtures/issue199-completion-provider.php';
}

function wpai199c_mcp_data( $token, $session, $ability, array $input ) {
	wp_set_current_user( 0 );
	$response = wpai134_review_call( $token, $session, 'wp-ai-bridge/' . $ability, $input );
	if ( true !== ( $response['result']['structuredContent']['success'] ?? false ) ) {
		$diagnostic = get_temp_dir() . 'admin13-mcp-first-error-' . getmypid() . '.json';
		if ( ! file_exists( $diagnostic ) ) { file_put_contents( $diagnostic, json_encode( array( 'ability' => $ability, 'request_window' => array_intersect_key( $input, array_flip( array( 'action', 'operation_id', 'result_id', 'offset', 'length', 'expected_sha256' ) ) ), 'result' => $response['result'] ?? null ), JSON_THROW_ON_ERROR ) ); }
		chmod( $diagnostic, 0600 );
		throw new RuntimeException( 'Canonical completion MCP call failed: ' . $ability . '; private task diagnostic recorded.' );
	}
	return wpai199c_ok( $response['result']['structuredContent']['data'] ?? null, 'Canonical MCP data missing.' );
}
function wpai199c_mcp_denied( $token, $session, $ability, array $input ) {
	wp_set_current_user( 0 );
	$response = wpai134_review_call( $token, $session, 'wp-ai-bridge/' . $ability, $input );
	wpai199c_assert( true === ( $response['result']['isError'] ?? false ), 'Existing MCP session bypassed current delegation: ' . $ability );
}
function wpai199c_wire_value( $bytes ) {
	return array( 'value_json' => json_encode( array( 'format' => 'wpai-native-option-value-v2', 'value' => array( 'type' => 'string', 'base64' => base64_encode( $bytes ) ) ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
}
function wpai199c_close_mcp( $token, $session ) {
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'DELETE', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Mcp-Session-Id', $session );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	return rest_do_request( $request );
}

global $wpdb;
$actor = get_current_user_id();
$expected_database = getenv( 'WPAI199C_EXPECT_DATABASE' );
$restore_schema = getenv( 'WPAI199C_RESTORE_SCHEMA' );
$restore_root = getenv( 'WPAI199C_RESTORE_ROOT' );
wpai199c_assert( $actor > 0 && current_user_can( 'manage_options' ) && ! is_multisite(), 'MCP completion fixture requires its isolated single-site native administrator.' );
wpai199c_assert( is_string( $expected_database ) && '' !== $expected_database && $expected_database === $wpdb->get_var( 'SELECT DATABASE()' ), 'Explicit disposable database identity mismatch.' );
wpai199c_assert( is_string( $restore_schema ) && '' !== $restore_schema && is_string( $restore_root ) && is_dir( $restore_root ), 'Coordinator must supply an approved target schema and existing nonserved root.' );
$before = wpai199c_options_before();
$siteurl = get_option( 'siteurl' );
$home = get_option( 'home' );
$suffix = bin2hex( random_bytes( 4 ) );
$table = $wpdb->prefix . 'admin13_wire_' . $suffix;
$name = 'admin13_wire_' . $suffix;
$seed = null;
$token = '';
$session = '';
$store = null;
$input_id = '';
$export_id = '';
$sql_result_id = '';
$sql_operation = 'admin13-sql-' . $suffix;
$cron_args = array( 'admin13-wire-' . $suffix, 'normal' );
$table_created = false;
$prior_mod = get_theme_mod( 'wpai199c_customizer_text', null );
$cleanup_errors = array();
$recovery_path = get_temp_dir() . 'admin13-mcp-recovery-' . $suffix . '.json';

try {
	$seed = wpai199c_seed_provider( $suffix );
	file_put_contents( $recovery_path, json_encode( array( 'before' => $before, 'seed' => $seed, 'suffix' => $suffix, 'table' => $table, 'prior_mod' => $prior_mod, 'siteurl' => $siteurl, 'home' => $home ), JSON_THROW_ON_ERROR ) );
	chmod( $recovery_path, 0600 );
	wpai199c_configure( $seed['directory'], $restore_root, $restore_schema, $seed['tables'] );
	update_option( 'siteurl', set_url_scheme( $siteurl, 'https' ) );
	update_option( 'home', set_url_scheme( $home, 'https' ) );
	wpai199c_grants( false );
	list( $token, $session, $store ) = wpai134_review_connect( $actor );
	$denials = array(
		'filesystem-read' => array( 'action' => 'stat', 'root_id' => 'fixtures', 'path' => 'binary.bin' ),
		'logs-read' => array( 'action' => 'tail', 'root_id' => 'fixtures', 'path' => 'fixture.log', 'max_bytes' => 1024, 'lines' => 1 ),
		'scheduler-read' => array( 'action' => 'providers' ),
		'native-configuration' => array( 'action' => 'read', 'target' => 'transient', 'name' => $name ),
		'customizer-settings' => array( 'action' => 'list', 'expected_stylesheet' => get_stylesheet(), 'limit' => 5 ),
		'full-backup' => array( 'action' => 'inspect', 'source_roots' => array( 'installation' ) ),
		'database-schema' => array( 'action' => 'describe', 'table' => $wpdb->options ),
		'database-native-sql' => array( 'action' => 'prepare', 'operation_id' => $sql_operation, 'acknowledge_native_account_scope' => true, 'statement_base64' => base64_encode( 'SELECT ? AS exact_bytes' ), 'parameters' => array( base64_encode( 'initial denied' ) ), 'max_result_bytes' => 1048576 ),
	);
	foreach ( $denials as $ability => $input ) {
		wpai199c_mcp_denied( $token, $session, $ability, $input );
	}
	wp_set_current_user( $actor );
	wpai199c_grants();
	$stat = wpai199c_mcp_data( $token, $session, 'filesystem-read', array( 'action' => 'stat', 'root_id' => 'fixtures', 'path' => 'binary.bin' ) );
	$window = wpai199c_mcp_data( $token, $session, 'filesystem-read', array( 'action' => 'read', 'root_id' => 'fixtures', 'path' => 'binary.bin', 'expected_identity' => $stat['identity'], 'offset' => 1, 'max_bytes' => 31 ) );
	wpai199c_assert( base64_decode( $window['base64'], true ) === file_get_contents( $seed['directory'] . '/binary.bin', false, null, 1, 31 ), 'MCP scoped file bytes changed.' );
	$tail = wpai199c_mcp_data( $token, $session, 'logs-read', $denials['logs-read'] );
	wpai199c_assert( str_contains( base64_decode( $tail['data_base64'], true ), 'synthetic last' ) && $tail['bytes'] <= 1024, 'MCP bounded log tail omitted actual file bytes.' );
	wpai199c_mcp_data( $token, $session, 'scheduler-read', array( 'action' => 'providers' ) );

	// Real public Core scheduler dispatch, never a broad due-queue runner.
	$when = time() + 7200;
	wpai199c_mcp_data( $token, $session, 'scheduler-manage', array( 'action' => 'schedule', 'provider' => 'wp_cron', 'hook' => 'wpai199c_owned_queue_effect', 'args' => $cron_args, 'schedule_type' => 'single', 'timestamp' => $when, 'operation_id' => 'admin13-cron-' . $suffix, 'confirm' => true ) );
	wp_set_current_user( $actor );
	wpai199c_assert( $when === wp_next_scheduled( 'wpai199c_owned_queue_effect', $cron_args ), 'MCP scheduler did not create the exact owned Core event.' );

	$wire_bytes = "MCP\0سلام";
	wpai199c_mcp_data( $token, $session, 'native-configuration', array( 'action' => 'set', 'target' => 'transient', 'name' => $name, 'operation_id' => 'admin13-config-' . $suffix, 'expiration_seconds' => 300, 'value_source' => wpai199c_wire_value( $wire_bytes ) ) );
	wp_set_current_user( $actor );
	wpai199c_assert( $wire_bytes === get_transient( $name ), 'MCP native transient write did not preserve actual bytes.' );
	wpai199c_provider_lifecycle_grants();
	wpai199c_mcp_data( $token, $session, 'customizer-settings', array( 'action' => 'update', 'expected_stylesheet' => get_stylesheet(), 'name' => 'wpai199c_customizer_text', 'operation_id' => 'admin13-customizer-' . $suffix, 'value_source' => wpai199c_wire_value( 'MCP customizer ' . $suffix ) ) );
	wp_set_current_user( $actor );
	wpai199c_assert( 'MCP customizer ' . $suffix === get_theme_mod( 'wpai199c_customizer_text' ), 'MCP Customizer publication did not reach native theme storage.' );

	$schema_request = array( 'action' => 'execute', 'operation_id' => 'admin13-schema-' . $suffix, 'operation' => array( 'action' => 'create_table', 'table' => $table, 'expected_schema_hash' => null, 'columns' => array( array( 'name' => 'id', 'type' => 'int', 'nullable' => false ) ), 'indexes' => array( array( 'name' => 'PRIMARY', 'kind' => 'primary', 'columns' => array( 'id' ) ) ) ) );
	wpai199c_mcp_data( $token, $session, 'database-schema', $schema_request );
	wp_set_current_user( $actor );
	$table_created = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	wpai199c_assert( $table_created, 'MCP schema operation did not create its exact native table.' );
	$replay = wpai199c_mcp_data( $token, $session, 'database-schema', $schema_request );
	wpai199c_assert( true === $replay['historical'], 'MCP schema replay lost historical recovery.' );

	// One inert input is resumed, reauthorized, privately consumed, then exported.
	$bytes = file_get_contents( $seed['directory'] . '/binary.bin' );
	$limits = wpai199c_mcp_data( $token, $session, 'filesystem-transfer', array( 'action' => 'limits', 'root_id' => 'fixtures' ) );
	$open = wpai199c_mcp_data( $token, $session, 'filesystem-transfer', array( 'action' => 'begin', 'root_id' => 'fixtures', 'total_bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ) ) );
	$input_id = $open['id'];
	$offset = 0;
	$chunk = min( 32768, $limits['chunk'], max( 1, intdiv( $limits['request'], 2 ) ) );
	while ( $offset < strlen( $bytes ) ) {
		$piece = substr( $bytes, $offset, $chunk );
		$append = array( 'action' => 'append', 'root_id' => 'fixtures', 'transfer_id' => $input_id, 'offset' => $offset, 'base64' => base64_encode( $piece ) );
		wpai199c_mcp_data( $token, $session, 'filesystem-transfer', $append );
		if ( 0 === $offset ) {
			wpai199c_mcp_data( $token, $session, 'filesystem-transfer', $append );
			wp_set_current_user( $actor );
			$flags = ( new Settings() )->sanitize_administration_grants( array_fill_keys( Settings::ADMINISTRATION_GROUPS, 1 ) );
			$flags[ Settings::GROUP_FILESYSTEM_WRITE ] = 0;
			update_option( Settings::ADMINISTRATION_OPTION_NAME, $flags, false );
			wpai199c_mcp_denied( $token, $session, 'filesystem-transfer', $append );
			wp_set_current_user( $actor );
			wpai199c_grants();
		}
		$offset += strlen( $piece );
	}
	wpai199c_mcp_data( $token, $session, 'filesystem-transfer', array( 'action' => 'verify', 'root_id' => 'fixtures', 'transfer_id' => $input_id ) );
	( new OAuth_Server( $store ) )->authenticate_mcp_request( new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE ) );
	wp_set_current_user( $actor );
	wpai199c_assert( is_wp_error( wpai199c_ability( 'filesystem-transfer', array( 'action' => 'status', 'root_id' => 'fixtures', 'transfer_id' => $input_id ) ) ), 'Native client adopted an OAuth-owned private filesystem input.' );
	$write = array( 'action' => 'write', 'root_id' => 'fixtures', 'path' => 'wire-published.bin', 'transfer_id' => $input_id, 'operation_id' => 'admin13-file-' . $suffix );
	wpai199c_mcp_data( $token, $session, 'filesystem-write', $write );
	wpai199c_assert( hash( 'sha256', $bytes ) === hash_file( 'sha256', $seed['directory'] . '/wire-published.bin' ), 'MCP publication changed complete native file bytes.' );
	wpai199c_mcp_data( $token, $session, 'filesystem-write', $write );
	$export = wpai199c_mcp_data( $token, $session, 'filesystem-transfer', array( 'action' => 'export_begin', 'root_id' => 'fixtures', 'path' => 'wire-published.bin' ) );
	$export_id = $export['id'];
	for ( $steps = 0; 'verified' !== $export['state']; ++$steps ) {
		wpai199c_assert( $steps < 10000, 'MCP file export exceeded its finite continuation bound.' );
		$export = wpai199c_mcp_data( $token, $session, 'filesystem-transfer', array( 'action' => 'export_step', 'root_id' => 'fixtures', 'transfer_id' => $export_id ) );
	}
	$hash = hash_init( 'sha256' );
	$offset = 0;
	do {
		$read = wpai199c_mcp_data( $token, $session, 'filesystem-transfer', array( 'action' => 'read', 'root_id' => 'fixtures', 'transfer_id' => $export_id, 'offset' => $offset, 'max_bytes' => $chunk ) );
		$piece = base64_decode( $read['base64'], true );
		wpai199c_assert( is_string( $piece ) && hash( 'sha256', $piece ) === $read['chunk_sha256'] && $read['next_offset'] === $offset + strlen( $piece ), 'MCP export byte window lost exact continuity.' );
		hash_update( $hash, $piece ); $offset = $read['next_offset'];
	} while ( ! $read['complete'] );
	wpai199c_assert( strlen( $bytes ) === $offset && hash( 'sha256', $bytes ) === hash_final( $hash ), 'MCP resumed file egress differs from all original bytes.' );

	if ( defined( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL' ) && true === WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL ) {
		$sql = array( 'action' => 'prepare', 'operation_id' => $sql_operation, 'acknowledge_native_account_scope' => true, 'statement_base64' => base64_encode( 'SELECT ? AS exact_bytes' ), 'parameters' => array( base64_encode( $wire_bytes ) ), 'max_result_bytes' => 1048576 );
		$prepared = wpai199c_mcp_data( $token, $session, 'database-native-sql', $sql );
		$sql['action'] = 'execute'; $sql['preparation_token'] = $prepared['preparation_token'];
		$executed = wpai199c_mcp_data( $token, $session, 'database-native-sql', $sql );
		wpai199c_assert( false === $executed['installation_confined'] && false === $executed['reexecution_allowed'], 'MCP native SQL lost explicit account-scope/one-dispatch truth.' );
		$sql_result_id = $executed['receipt']['result']['result_id'];
		wpai199c_assert( true === $executed['receipt']['result']['result_complete'] && true === $executed['receipt']['result']['result_available'] && $sql_result_id === $executed['private_result']['id'], 'SQL result receipt was not complete, available and identity-consistent.' );
		$sql_result_sha256 = $executed['private_result']['sha256'];
		$result_bytes = ''; $offset = 0;
		do {
			$part = wpai199c_mcp_data( $token, $session, 'database-native-sql', array( 'action' => 'result_read', 'operation_id' => $sql_operation, 'acknowledge_native_account_scope' => true, 'result_id' => $sql_result_id, 'offset' => $offset, 'length' => 257, 'expected_sha256' => $sql_result_sha256 ) );
			$piece = base64_decode( $part['base64'], true );
			wpai199c_assert( is_string( $piece ) && hash( 'sha256', $piece ) === $part['chunk_sha256'], 'Native SQL wire result window is not exact.' );
			$result_bytes .= $piece; $offset = $part['next_offset'];
			wpai199c_assert( strlen( $result_bytes ) <= 1048576, 'Tiny native SQL result exceeded its explicit reservation.' );
		} while ( ! $part['complete'] );
		$cells = '';
		foreach ( explode( "\n", trim( $result_bytes ) ) as $line ) {
			$frame = json_decode( $line, true, 64, JSON_THROW_ON_ERROR );
			if ( '0' === (string) ( $frame['row'] ?? '' ) && 0 === ( $frame['column'] ?? null ) ) {
				$cells .= base64_decode( $frame['data'], true );
			}
		}
		wpai199c_assert( $wire_bytes === $cells, 'MCP native SQL exact binary cell differs from submitted parameter.' );
	}

	$plan = wpai199c_mcp_data( $token, $session, 'full-backup', $denials['full-backup'] );
	wpai199c_assert( false === $plan['complete'] && true === $plan['quiescence_assumption'] && in_array( base64_encode( $seed['tables'][1] ), $plan['tables'], true ), 'MCP full-backup preflight omitted approved keyless provider storage or claimed completion.' );
	wp_set_current_user( $actor );
	wpai199c_grants( false );
	foreach ( $denials as $ability => $input ) {
		wpai199c_mcp_denied( $token, $session, $ability, $input );
	}
	wp_set_current_user( $actor );
	wpai199c_assert( hash( 'sha256', $bytes ) === hash_file( 'sha256', $seed['directory'] . '/wire-published.bin' ), 'Revoked MCP calls changed already published bytes.' );

} finally {
	wp_set_current_user( $actor );
	wpai199c_grants();
	foreach ( array( array( $input_id, false ), array( $export_id, true ) ) as $lease ) {
		if ( '' !== $token && '' !== $session && '' !== $lease[0] ) {
			try { wpai199c_mcp_data( $token, $session, 'filesystem-transfer', array( 'action' => 'release', 'root_id' => 'fixtures', 'transfer_id' => $lease[0], 'export' => $lease[1] ) ); } catch ( Throwable $error ) { $cleanup_errors[] = 'filesystem_transfer'; }
		}
	}
	if ( '' !== $sql_result_id && '' !== $token && '' !== $session ) {
		try { wpai199c_mcp_data( $token, $session, 'database-native-sql', array( 'action' => 'result_release', 'operation_id' => $sql_operation, 'acknowledge_native_account_scope' => true, 'result_id' => $sql_result_id ) ); } catch ( Throwable $error ) { $cleanup_errors[] = 'native_sql_result'; }
	}
	if ( '' !== $token && '' !== $session ) {
		wpai199c_close_mcp( $token, $session );
	}
	if ( '' !== $token && null !== $store ) {
		$store->revoke( $token );
		( new OAuth_Server( $store ) )->authenticate_mcp_request( new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE ) );
	}
	wp_set_current_user( $actor );
	wp_clear_scheduled_hook( 'wpai199c_owned_queue_effect', $cron_args );
	delete_transient( $name );
	null === $prior_mod ? remove_theme_mod( 'wpai199c_customizer_text' ) : set_theme_mod( 'wpai199c_customizer_text', $prior_mod );
	if ( $table_created ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	}
	if ( is_array( $seed ) ) {
		wpai199c_cleanup_seed( $seed, $suffix );
	}
	update_option( 'siteurl', $siteurl );
	update_option( 'home', $home );
	wpai199c_restore_options( $before );
	if ( file_exists( $recovery_path ) ) { $recovery = json_decode( file_get_contents( $recovery_path ), true ); $recovery['cleaned'] = true; $recovery['cleanup_errors'] = $cleanup_errors; file_put_contents( $recovery_path, json_encode( $recovery, JSON_THROW_ON_ERROR ) ); }
}
wpai199c_assert( ! $cleanup_errors, 'Completion fixture restored source state but a private lease cleanup needs inspection.' );
echo "PASS: completion families through canonical OAuth/MCP; default-off/current revocation, real native effects, resumed exact file bytes and private origin.\n";
