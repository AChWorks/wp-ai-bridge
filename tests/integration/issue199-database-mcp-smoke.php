<?php
/**
 * Synthetic authenticated OAuth -> Bridge -> official Adapter -> database proof.
 *
 * No external transport or production state is exercised by this local REST test.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue134-review-helper.php';

function wpai199mcp_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function wpai199mcp_call( $token, $session, $name, array $input ) {
	wp_set_current_user( 0 );
	return wpai134_review_call( $token, $session, 'wp-ai-bridge/' . $name, $input );
}
function wpai199mcp_data( array $response ) {
	wpai199mcp_assert( true === ( $response['result']['structuredContent']['success'] ?? false ), 'Authenticated database MCP call failed.' );
	return $response['result']['structuredContent']['data'];
}
function wpai199mcp_delete( $token, $session ) {
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'DELETE', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Mcp-Session-Id', $session );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	return rest_do_request( $request );
}

global $wpdb;
$actor = get_current_user_id();
$original = get_option( Settings::OPTION_NAME, false );
$original_log = get_option( Mutation_Log::OPTION_NAME, false );
$table = $wpdb->prefix . 'wpai199_mcp_' . bin2hex( random_bytes( 4 ) );
$created = false;
$token = '';
$session = '';
$store = null;

try {
	wpai199mcp_assert( current_user_can( 'manage_options' ), 'Synthetic MCP fixture requires its administrator.' );
	wpai199mcp_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id bigint unsigned NOT NULL PRIMARY KEY, payload longblob NOT NULL) ENGINE=InnoDB', $table ) ), 'Cannot create private MCP fixture table.' );
	$created = true;
	$bytes = str_repeat( chr( 255 ), 70000 ) . str_repeat( "Database-MCP-سلام", 1200 );
	wpai199mcp_assert( 1 === $wpdb->insert( $table, array( 'id' => '1', 'payload' => $bytes ), array( '%s', '%s' ) ), 'Cannot populate private MCP fixture.' );
	$settings = new Settings();
	$grants = $settings->defaults();
	$grants[ Settings::GROUP_DATABASE_INSPECTION ] = 1;
	$grants[ Settings::GROUP_NATIVE_ABILITIES ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	list( $token, $session, $store ) = wpai134_review_connect( $actor );

	$schema = wpai199mcp_data( wpai199mcp_call( $token, $session, 'database-inspect', array( 'action' => 'schema', 'table' => $table, 'limit' => 10 ) ) );
	wpai199mcp_assert( 2 === count( $schema['items'] ), 'Native Bridge-owned database inspection did not cross the authenticated Adapter with Native Abilities disabled.' );
	$type_definition = wpai199mcp_data( wpai199mcp_call( $token, $session, 'database-inspect', array( 'action' => 'column_type', 'table' => $table, 'column' => 'id' ) ) );
	wpai199mcp_assert( 'bigint' === $type_definition['data_type'] && 'column_type_definition' === $type_definition['cell']['sql_type'] && null === $type_definition['cell']['next_offset'], 'Metadata-only type continuation was not exposed through authenticated MCP.' );

	$rows_request = array( 'action' => 'rows', 'table' => $table, 'columns' => array( 'id', 'payload' ), 'limit' => 1 );
	$no_sensitive = wpai199mcp_call( $token, $session, 'database-read', $rows_request );
	wpai199mcp_assert( true === ( $no_sensitive['result']['isError'] ?? false ), 'Metadata-only OAuth connection read sensitive database values.' );

	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_DATABASE_READ ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$rows = wpai199mcp_data( wpai199mcp_call( $token, $session, 'database-read', $rows_request ) );
	wpai199mcp_assert( '1' === base64_decode( $rows['items'][0]['cells']['id']['data'], true ), 'Authenticated MCP database row identity changed.' );
	wpai199mcp_assert( true === $rows['items'][0]['key_complete'], 'Ordinary primary key should be a complete reusable identity.' );
	$hash = hash( 'sha256', $bytes );
	$assembled = '';
	$offset = 0;
	$requested_window = intdiv( Bounded_Payload::response_limit(), 4 );
	do {
		$response = wpai199mcp_call( $token, $session, 'database-read', array(
			'action' => 'cell', 'table' => $table, 'column' => 'payload', 'key' => array( 'id' => base64_encode( '1' ) ),
			'offset' => $offset, 'length' => $requested_window, 'expected_sha256' => $hash,
		) );
		$window = wpai199mcp_data( $response );
		wpai199mcp_assert( strlen( wp_json_encode( $response ) ) <= Bounded_Payload::response_limit() && Bounded_Payload::fits( $window ), 'Actual duplicated MCP result exceeded the response policy after adaptive byte sizing.' );
		wpai199mcp_assert( $window['cell']['bytes'] > 0 && $window['cell']['bytes'] <= $requested_window, 'Adaptive MCP bytes did not respect the requested SQL window.' );
		if ( 0 === $offset ) {
			wpai199mcp_assert( $window['cell']['bytes'] < $requested_window, 'Worst-case slash-base64 MCP reply did not adapt to the encoded envelope.' );
		}
		wpai199mcp_assert( null === $window['cell']['next_offset'] || $offset + $window['cell']['bytes'] === $window['cell']['next_offset'], 'Adaptive MCP continuation skipped or repeated bytes.' );
		wpai199mcp_assert( $hash === $window['cell']['sha256'] && $offset === $window['cell']['offset'], 'Authenticated MCP cell window identity changed.' );
		$assembled .= base64_decode( $window['cell']['data'], true );
		$offset = $window['cell']['next_offset'];
	} while ( null !== $offset );
	wpai199mcp_assert( $bytes === $assembled && $hash === hash( 'sha256', $assembled ), 'Authenticated MCP byte reconstruction failed.' );
	$position = wpai199mcp_data( wpai199mcp_call( $token, $session, 'database-read', array( 'action' => 'cell', 'table' => $table, 'column' => 'payload', 'row_offset' => 0, 'filters' => array( 'id' => base64_encode( '1' ) ), 'expected_sha256' => $hash, 'length' => 32 ) ) );
	wpai199mcp_assert( 0 === $position['row_offset'] && 'live_row_position_not_identity' === $position['consistency'] && substr( $bytes, 0, 32 ) === base64_decode( $position['cell']['data'], true ), 'Authenticated MCP positional cell input/output did not preserve its declared contract.' );


	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_DATABASE_READ ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	$revoked = wpai199mcp_call( $token, $session, 'database-read', $rows_request );
	wpai199mcp_assert( true === ( $revoked['result']['isError'] ?? false ), 'An existing OAuth/MCP session bypassed a freshly revoked database grant.' );
	$closed = wpai199mcp_delete( $token, $session );
	wpai199mcp_assert( in_array( $closed->get_status(), array( 200, 204 ), true ), 'Synthetic MCP session did not terminate.' );
	$session = '';
	wpai199mcp_assert( $store->revoke( $token ), 'Synthetic access token could not be revoked.' );
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( '{"jsonrpc":"2.0","id":99,"method":"tools/list","params":{}}' );
	wpai199mcp_assert( 401 === rest_do_request( $request )->get_status(), 'Revoked access token still authenticated a new database-capable request.' );
	$token = '';
	echo "PASS: Issue #199 authenticated OAuth/MCP database exposure, bytes, grant and token revocation.\n";
} finally {
	if ( '' !== $token && '' !== $session ) {
		wpai199mcp_delete( $token, $session );
	}
	if ( '' !== $token && null !== $store ) {
		$store->revoke( $token );
	}
	wp_set_current_user( $actor );
	if ( $created ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	}
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
	if ( false === $original_log ) {
		delete_option( Mutation_Log::OPTION_NAME );
	} else {
		update_option( Mutation_Log::OPTION_NAME, $original_log, false );
	}
}
