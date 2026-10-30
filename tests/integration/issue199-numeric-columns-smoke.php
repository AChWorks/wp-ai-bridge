<?php
/**
 * Quoted numeric SQL identifiers through native and authenticated MCP contracts.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue134-review-helper.php';

function wpai199numeric_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai199numeric_native( $name, $input ) {
	$ability = wp_get_ability( 'wp-ai-bridge/' . $name );
	wpai199numeric_assert( $ability instanceof WP_Ability, 'Numeric fixture Ability is unavailable.' );
	$result = $ability->execute( $input );
	wpai199numeric_assert( ! is_wp_error( $result ), 'Native numeric-column request failed: ' . ( is_wp_error( $result ) ? $result->get_error_code() : '' ) );
	return $result;
}

/** Keep the actual first JSON encoding: associative decoding would lose object-shape evidence. */
function wpai199numeric_mcp( $token, $session, $name, array $input ) {
	static $request_id = 19900;
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Mcp-Session-Id', $session );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array(
		'jsonrpc' => '2.0',
		'id' => ++$request_id,
		'method' => 'tools/call',
		'params' => array(
			'name' => 'mcp-adapter-execute-ability',
			'arguments' => array( 'ability_name' => 'wp-ai-bridge/' . $name, 'parameters' => $input ),
		),
	) ) );
	$response = rest_do_request( $request );
	$wire = wp_json_encode( $response->get_data() );
	$decoded = is_string( $wire ) ? json_decode( $wire ) : null;
	wpai199numeric_assert( 200 === $response->get_status() && is_object( $decoded ), 'Numeric MCP request did not return JSON success transport.' );
	wpai199numeric_assert( true === ( $decoded->result->structuredContent->success ?? false ), 'Numeric MCP Ability execution failed.' );
	wpai199numeric_assert( strlen( $wire ) <= Bounded_Payload::response_limit(), 'Numeric MCP response exceeded the configured encoded response budget.' );
	$text = json_decode( $decoded->result->content[0]->text ?? '' );
	wpai199numeric_assert( is_object( $text ) && true === ( $text->success ?? false ), 'Adapter text representation is missing the numeric result.' );
	return array( 'data' => $decoded->result->structuredContent->data, 'text' => $text->data );
}

function wpai199numeric_native_maps( array $item ) {
	foreach ( array( 'key', 'key_hashes', 'cells' ) as $name ) {
		wpai199numeric_assert( $item[ $name ] instanceof stdClass, 'Native ' . $name . ' must always be an object, including empty dictionaries.' );
	}
	$wire = json_decode( wp_json_encode( $item ) );
	foreach ( array( 'key', 'key_hashes', 'cells' ) as $name ) {
		wpai199numeric_assert( $wire->{$name} instanceof stdClass, 'Native JSON encoded ' . $name . ' as an array.' );
	}
}

function wpai199numeric_wire_maps( $item ) {
	wpai199numeric_assert( $item instanceof stdClass, 'MCP row lost its object shape.' );
	foreach ( array( 'key', 'key_hashes', 'cells' ) as $name ) {
		wpai199numeric_assert( $item->{$name} instanceof stdClass, 'Actual MCP JSON encoded ' . $name . ' as an array.' );
	}
}

function wpai199numeric_close( $token, $session ) {
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'DELETE', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Mcp-Session-Id', $session );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	return rest_do_request( $request );
}

global $wpdb;
$actor = get_current_user_id();
$settings = new Settings();
$settings->register();
$missing = new stdClass();
$options = array();
foreach ( array( Settings::OPTION_NAME, Settings::DATABASE_OPTION_NAME, Mutation_Log::OPTION_NAME ) as $name ) {
	$value = get_option( $name, $missing );
	$options[ $name ] = array( 'exists' => $value !== $missing, 'value' => $value === $missing ? null : $value );
}
$suffix = bin2hex( random_bytes( 4 ) );
$base = $wpdb->prefix . 'wpai199_numeric_' . $suffix;
$table = $base . '_ordinary';
$giant = $base . '_giant';
$heap = $base . '_heap';
$created = array();
$token = '';
$session = '';
$store = null;
$failure = null;
$cleanup_errors = array();

try {
	wpai199numeric_assert( ! is_multisite() && current_user_can( 'manage_options' ), 'Run the numeric fixture as the disposable single-site administrator.' );
	wpai199numeric_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (%i bigint unsigned NOT NULL PRIMARY KEY, %i longblob NOT NULL) ENGINE=InnoDB', $table, '0', '01' ) ), 'Cannot create ordinary numeric-column fixture.' );
	$created[] = $table;
	wpai199numeric_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (%i longtext NOT NULL, %i longblob NOT NULL, PRIMARY KEY (%i(16))) ENGINE=InnoDB', $giant, '0', '01', '0' ) ), 'Cannot create oversized numeric primary-key fixture.' );
	$created[] = $giant;
	wpai199numeric_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (%i varchar(20) NOT NULL, %i varchar(20) NOT NULL) ENGINE=InnoDB', $heap, '0', '01' ) ), 'Cannot create keyless numeric-column fixture.' );
	$created[] = $heap;
	$binary = "\x00\xff\x80\"\\\n" . 'distinct-leading-zero-column';
	$other = "\x00other\xff";
	foreach ( array( array( '1', $binary ), array( '2', $other ) ) as $row ) {
		wpai199numeric_assert( 1 === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (%i,%i) VALUES (%s,FROM_BASE64(%s))', $table, '0', '01', $row[0], base64_encode( $row[1] ) ) ), 'Cannot populate ordinary numeric fixture.' );
	}
	$large_key = 'prefix-numeric-0-' . str_repeat( 'K', 40000 );
	$large_binary = str_repeat( "\x00\xff\x80\\\"numeric-0/01", 4000 );
	wpai199numeric_assert( 1 === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (%i,%i) VALUES (%s,FROM_BASE64(%s))', $giant, '0', '01', $large_key, base64_encode( $large_binary ) ) ), 'Cannot populate oversized numeric key fixture.' );
	wpai199numeric_assert( 1 === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (%i,%i) VALUES (%s,%s)', $heap, '0', '01', 'zero', 'leading-zero' ) ), 'Cannot populate keyless numeric fixture.' );
	update_option( Settings::DATABASE_OPTION_NAME, array( Settings::GROUP_DATABASE_INSPECTION => 1, Settings::GROUP_DATABASE_READ => 1 ), false );
	wpai199numeric_assert( $settings->is_enabled( Settings::GROUP_DATABASE_INSPECTION ) && $settings->is_enabled( Settings::GROUP_DATABASE_READ ), 'The actual administrator could not grant dedicated database access.' );

	$schema = wpai199numeric_native( 'database-inspect', array( 'action' => 'schema', 'table' => $table, 'limit' => 10 ) );
	wpai199numeric_assert( array( '0', '01' ) === array_column( $schema['items'], 'name' ), 'Native discovery aliased numeric zero and leading-zero identifiers.' );
	$type = wpai199numeric_native( 'database-inspect', array( 'action' => 'column_type', 'table' => $table, 'column' => '0' ) );
	wpai199numeric_assert( 'bigint' === $type['data_type'], 'Exact numeric-column type discovery failed.' );
	$filters = json_decode( '{"0":"' . base64_encode( '1' ) . '","01":"' . base64_encode( $binary ) . '"}', true );
	wpai199numeric_assert( is_int( array_key_first( $filters ) ) && array_key_exists( '01', $filters ), 'Fixture failed to exercise PHP integer coercion of a numeric JSON key.' );
	$input = array( 'action' => 'rows', 'table' => $table, 'columns' => array( '0', '01' ), 'filters' => $filters, 'limit' => 2 );
	$rows = wpai199numeric_native( 'database-read', $input );
	wpai199numeric_assert( 1 === count( $rows['items'] ), 'Native byte-exact numeric filter did not identify its row.' );
	$item = $rows['items'][0];
	wpai199numeric_native_maps( $item );
	wpai199numeric_assert( property_exists( $item['cells'], '0' ) && property_exists( $item['cells'], '01' ), 'Native cells dictionary lost one distinct numeric name.' );
	wpai199numeric_assert( '1' === base64_decode( $item['cells']->{'0'}['data'], true ) && $binary === base64_decode( $item['cells']->{'01'}['data'], true ), 'Numeric identifiers aliased their distinct stored bytes.' );
	wpai199numeric_assert( 0 === count( get_object_vars( $item['key_hashes'] ) ), 'Ordinary numeric primary key unexpectedly became a hash key.' );
	$native_key = json_decode( wp_json_encode( $item['key'] ), true );
	$cell = wpai199numeric_native( 'database-read', array( 'action' => 'cell', 'table' => $table, 'column' => '01', 'key' => $native_key ) );
	wpai199numeric_assert( $binary === base64_decode( $cell['cell']['data'], true ), 'Native full numeric primary-key JSON reuse failed.' );
	$direct_cell = wpai199numeric_native( 'database-read', (object) array( 'action' => 'cell', 'table' => $table, 'column' => '01', 'key' => $item['key'] ) );
	wpai199numeric_assert( $binary === base64_decode( $direct_cell['cell']['data'], true ), 'Returned full-key stdClass could not be reused directly in a native object request.' );
	$miss = $input;
	$miss['filters'][0] = base64_encode( '2' );
	wpai199numeric_assert( array() === wpai199numeric_native( 'database-read', $miss )['items'], 'Zero and leading-zero filter keys were conflated.' );

	$large_rows = wpai199numeric_native( 'database-read', array( 'action' => 'rows', 'table' => $giant, 'columns' => array( '0', '01' ), 'limit' => 1 ) );
	$large_item = $large_rows['items'][0];
	wpai199numeric_native_maps( $large_item );
	wpai199numeric_assert( false === $large_item['key_complete'] && 0 === count( get_object_vars( $large_item['key'] ) ) && hash( 'sha256', $large_key ) === $large_item['key_hashes']->{'0'}, 'Oversized numeric primary key did not retain its exact hash dictionary.' );
	$direct_hash_cell = wpai199numeric_native( 'database-read', (object) array(
		'action' => 'cell', 'table' => $giant, 'column' => '0',
		'key' => $large_item['key'], 'key_hashes' => $large_item['key_hashes'],
		'length' => 8192, 'expected_sha256' => hash( 'sha256', $large_key ),
	) );
	wpai199numeric_assert( substr( $large_key, 0, 8192 ) === base64_decode( $direct_hash_cell['cell']['data'], true ) && hash( 'sha256', $large_key ) === $direct_hash_cell['cell']['sha256'], 'Returned empty-key/hash-key stdClass selectors could not be reused directly in a native object request.' );
	$hash_keys = json_decode( wp_json_encode( $large_item['key_hashes'] ), true );
	$assembled_key = '';
	$offset = 0;
	do {
		$part = wpai199numeric_native( 'database-read', array( 'action' => 'cell', 'table' => $giant, 'column' => '0', 'key_hashes' => $hash_keys, 'offset' => $offset, 'length' => 8192, 'expected_sha256' => hash( 'sha256', $large_key ) ) );
		wpai199numeric_assert( $offset === $part['cell']['offset'] && $part['cell']['bytes'] > 0, 'Native numeric hash-key continuation did not progress exactly.' );
		$assembled_key .= base64_decode( $part['cell']['data'], true );
		$offset = $part['cell']['next_offset'];
	} while ( null !== $offset );
	wpai199numeric_assert( $large_key === $assembled_key, 'Native oversized numeric primary-key bytes could not be reconstructed.' );
	$empty = wpai199numeric_native( 'database-read', array( 'action' => 'rows', 'table' => $heap, 'columns' => array( '0', '01' ), 'limit' => 1 ) );
	wpai199numeric_native_maps( $empty['items'][0] );
	wpai199numeric_assert( 0 === count( get_object_vars( $empty['items'][0]['key'] ) ) && 0 === count( get_object_vars( $empty['items'][0]['key_hashes'] ) ), 'Keyless dictionaries were not empty JSON objects.' );

	list( $token, $session, $store ) = wpai134_review_connect( $actor );
	$wire_schema = wpai199numeric_mcp( $token, $session, 'database-inspect', array( 'action' => 'schema', 'table' => $table, 'limit' => 10 ) );
	foreach ( $wire_schema as $representation ) {
		wpai199numeric_assert( array( '0', '01' ) === array_map( static function ( $column ) { return $column->name; }, $representation->items ), 'Authenticated MCP schema aliased numeric identifiers.' );
	}
	$wire_input = $input;
	$wire_input['filters'] = (object) $filters;
	$wire_rows = wpai199numeric_mcp( $token, $session, 'database-read', $wire_input );
	foreach ( $wire_rows as $representation ) {
		wpai199numeric_assert( 1 === count( $representation->items ), 'Authenticated numeric filter did not select exactly one row.' );
		$wire_item = $representation->items[0];
		wpai199numeric_wire_maps( $wire_item );
		wpai199numeric_assert( property_exists( $wire_item->cells, '0' ) && property_exists( $wire_item->cells, '01' ) && '1' === base64_decode( $wire_item->cells->{'0'}->data, true ) && $binary === base64_decode( $wire_item->cells->{'01'}->data, true ), 'Actual MCP JSON lost distinct numeric column bytes.' );
		wpai199numeric_assert( 0 === count( get_object_vars( $wire_item->key_hashes ) ), 'Empty MCP hash dictionary was altered.' );
	}
	$wire_cell = wpai199numeric_mcp( $token, $session, 'database-read', array( 'action' => 'cell', 'table' => $table, 'column' => '01', 'key' => $wire_rows['data']->items[0]->key ) );
	wpai199numeric_assert( $binary === base64_decode( $wire_cell['data']->cell->data, true ), 'Full numeric key could not be reused through actual JSON/MCP.' );
	$wire_giant = wpai199numeric_mcp( $token, $session, 'database-read', array( 'action' => 'rows', 'table' => $giant, 'columns' => array( '0', '01' ), 'limit' => 1 ) );
	foreach ( $wire_giant as $representation ) {
		$wire_item = $representation->items[0];
		wpai199numeric_wire_maps( $wire_item );
		wpai199numeric_assert( false === $wire_item->key_complete && 0 === count( get_object_vars( $wire_item->key ) ) && hash( 'sha256', $large_key ) === $wire_item->key_hashes->{'0'}, 'Actual MCP oversized zero-name primary key lost its hash reference.' );
	}
	$assembled_binary = '';
	$offset = 0;
	do {
		$window = wpai199numeric_mcp( $token, $session, 'database-read', array(
			'action' => 'cell', 'table' => $giant, 'column' => '01',
			'key' => $wire_giant['data']->items[0]->key,
			'key_hashes' => $wire_giant['data']->items[0]->key_hashes,
			'offset' => $offset, 'length' => intdiv( Bounded_Payload::response_limit(), 4 ),
			'expected_sha256' => hash( 'sha256', $large_binary ),
		) );
		$part = $window['data']->cell;
		wpai199numeric_assert( $offset === $part->offset && $part->bytes > 0 && hash( 'sha256', $large_binary ) === $part->sha256, 'Authenticated numeric hash-key binary continuation lost its identity.' );
		$assembled_binary .= base64_decode( $part->data, true );
		$offset = $part->next_offset;
	} while ( null !== $offset );
	wpai199numeric_assert( $large_binary === $assembled_binary && hash( 'sha256', $large_binary ) === hash( 'sha256', $assembled_binary ), 'Actual numeric JSON/hash-key MCP binary reconstruction failed.' );
	$wire_heap = wpai199numeric_mcp( $token, $session, 'database-read', array( 'action' => 'rows', 'table' => $heap, 'columns' => array( '0', '01' ), 'limit' => 1 ) );
	foreach ( $wire_heap as $representation ) {
		$wire_item = $representation->items[0];
		wpai199numeric_wire_maps( $wire_item );
		wpai199numeric_assert( 0 === count( get_object_vars( $wire_item->key ) ) && 0 === count( get_object_vars( $wire_item->key_hashes ) ), 'Actual MCP empty dictionaries became JSON arrays.' );
	}
	$closed = wpai199numeric_close( $token, $session );
	wpai199numeric_assert( in_array( $closed->get_status(), array( 200, 204 ), true ), 'Numeric fixture MCP session did not close.' );
	$session = '';
	wpai199numeric_assert( $store->revoke( $token ), 'Numeric fixture synthetic access token did not revoke.' );
	$token = '';
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	if ( '' !== $token && '' !== $session ) {
		try {
			$closed = wpai199numeric_close( $token, $session );
			if ( ! in_array( $closed->get_status(), array( 200, 204 ), true ) ) {
				$cleanup_errors[] = 'MCP session cleanup failed';
			}
		} catch ( Throwable $error ) {
			$cleanup_errors[] = 'MCP session cleanup raised an error';
		}
	}
	if ( '' !== $token && null !== $store && ! $store->revoke( $token ) ) {
		$cleanup_errors[] = 'synthetic OAuth token cleanup failed';
	}
	wp_set_current_user( $actor );
	foreach ( array_reverse( $created ) as $created_table ) {
		if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $created_table ) ) ||
			null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $created_table ) ) ) ) {
			$cleanup_errors[] = 'exact numeric fixture table cleanup failed';
		}
	}
	foreach ( $options as $name => $before ) {
		// This fixture never mutates ordinary Bridge grants; do not normalize them.
		if ( Settings::OPTION_NAME !== $name ) {
			if ( $before['exists'] ) {
				update_option( $name, $before['value'], false );
			} else {
				delete_option( $name );
			}
		}
		$absent = new stdClass();
		$current = get_option( $name, $absent );
		if ( $before['exists'] ? $current !== $before['value'] : $current !== $absent ) {
			$cleanup_errors[] = 'original numeric fixture settings/Activity state was not preserved';
		}
	}
}
if ( $cleanup_errors ) {
	throw new RuntimeException( implode( '; ', $cleanup_errors ), 0, $failure );
}
if ( null !== $failure ) {
	throw $failure;
}
echo "PASS: Issue #199 quoted numeric 0/01 identifiers, native/MCP object dictionaries, full/hash key reuse and exact binary windows; cleanup verified.\n";
