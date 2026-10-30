<?php
/**
 * Disposable native WordPress database administration and private queue fixture.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Database_Abilities;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

function wpai199_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function wpai199_execute( $name, array $input ) {
	$ability = wp_get_ability( 'wp-ai-bridge/' . $name );
	wpai199_assert( $ability instanceof WP_Ability, 'Missing database Ability: ' . $name );
	$result = $ability->execute( $input );
	if ( ! is_wp_error( $result ) && 'rows' === $input['action'] ) {
		foreach ( $result['items'] as &$row ) {
			foreach ( array( 'key', 'key_hashes', 'cells' ) as $dictionary ) {
				wpai199_assert( $row[ $dictionary ] instanceof stdClass, 'Native row dictionaries must remain JSON objects, including empty maps.' );
				$row[ $dictionary ] = (array) $row[ $dictionary ];
			}
		}
		unset( $row );
	}
	return $result;
}
function wpai199_value( array $row, $name ) {
	$cell = $row['cells'][ $name ];
	wpai199_assert( 'base64' === $cell['encoding'] && null === $cell['next_offset'], 'Expected one complete exact cell.' );
	return $cell['is_null'] ? null : base64_decode( $cell['data'], true );
}

function wpai199_restore_option( $name, $original ) {
	if ( false === $original ) {
		delete_option( $name );
		return;
	}
	$restore = static function () use ( $original ) { return $original; };
	add_filter( 'pre_update_option_' . $name, $restore, PHP_INT_MAX );
	try {
		update_option( $name, $original, false );
	} finally {
		remove_filter( 'pre_update_option_' . $name, $restore, PHP_INT_MAX );
	}
}
function wpai199_expect_protected_denial( $callback ) {
	$denied = false;
	$handler = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			$response = is_array( $args ) ? ( $args['response'] ?? 500 ) : 500;
			throw new RuntimeException( 'wpai199-protected-denied-' . $response );
		};
	};
	add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		if ( 'wpai199-protected-denied-403' !== $error->getMessage() ) {
			throw $error;
		}
		$denied = true;
	} finally {
		remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	}
	wpai199_assert( $denied, 'Unauthorized protected-option update did not stop with HTTP 403 before persistence.' );
}

global $wpdb;
$original = get_option( Settings::OPTION_NAME, false );
$original_database = get_option( Settings::DATABASE_OPTION_NAME, false );
$original_log = get_option( Mutation_Log::OPTION_NAME, false );
$original_user = get_current_user_id();
$original_show = $wpdb->show_errors;
$original_suppress = $wpdb->suppress_errors;
$suffix = bin2hex( random_bytes( 4 ) );
$table = $wpdb->prefix . 'wpai199_deliveries_' . $suffix;
$heap = $wpdb->prefix . 'wpai199_heap_' . $suffix;
$giant = $wpdb->prefix . 'wpai199_giant_' . $suffix;
$enum = $wpdb->prefix . 'wpai199_enum_' . $suffix;
$view = $wpdb->prefix . 'wpai199_view_' . $suffix;
$outside = 'outside_wpai199_' . $suffix;
$subscriber = 0;
$tables = array();
$view_created = false;
$freeze_audit = null;
$fail_query = null;

try {
	wpai199_assert( ! is_multisite(), 'Run the main database smoke on disposable single-site WordPress.' );
	wpai199_assert( current_user_can( 'manage_options' ), 'Database smoke requires its synthetic administrator.' );
	$settings = new Settings();
	$settings->register();
	$provider = new Database_Abilities( new Permissions( $settings ), new Mutation_Log() );
	$grant_reads = 0;
	$alternating_grants = static function () use ( &$grant_reads ) {
		++$grant_reads;
		return array(
			Settings::GROUP_DATABASE_INSPECTION => 1 === $grant_reads % 2 ? 1 : 0,
			Settings::GROUP_DATABASE_READ => 1 === $grant_reads % 2 ? 0 : 1,
		);
	};
	add_filter( 'pre_option_' . Settings::DATABASE_OPTION_NAME, $alternating_grants, 10, 0 );
	try {
		wpai199_assert( ! $provider->can_read(), 'Database read combined grants that were never enabled in one protected snapshot.' );
	} finally {
		remove_filter( 'pre_option_' . Settings::DATABASE_OPTION_NAME, $alternating_grants, 10 );
	}

	$grants = $settings->database_defaults();
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199_assert( is_wp_error( wpai199_execute( 'database-inspect', array( 'action' => 'tables' ) ) ), 'Default-off inspection was bypassed.' );
	$old = array();
	foreach ( $settings->groups() as $group => $definition ) {
		if ( ! in_array( $group, array( Settings::GROUP_DATABASE_INSPECTION, Settings::GROUP_DATABASE_READ ), true ) ) {
			$old[ $group ] = 1;
		}
	}
	update_option( Settings::OPTION_NAME, $old, false );
	wpai199_assert( ! $provider->can_inspect() && ! $provider->can_read(), 'Upgrade expanded historical unrelated grants.' );

	wpai199_assert( false !== $wpdb->query( $wpdb->prepare(
		"CREATE TABLE %i (id bigint unsigned NOT NULL PRIMARY KEY, telegram_message_id bigint unsigned NOT NULL DEFAULT 0, sync_attempts int NOT NULL DEFAULT 0, sync_next_attempt_at datetime NULL, last_error longtext NULL, payload longblob NULL, exact_amount decimal(30,10) NOT NULL, serialized_value longtext NOT NULL, private_value varchar(255) NOT NULL DEFAULT 'wpai199-private-schema-default', KEY initial_sends (telegram_message_id, sync_next_attempt_at)) ENGINE=InnoDB",
		$table
	) ), 'Cannot create synthetic private delivery table.' );
	$tables[] = $table;
	foreach ( array( $heap, $outside ) as $fixture ) {
		wpai199_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (tag varchar(30) NOT NULL, payload longblob NULL) ENGINE=InnoDB', $fixture ) ), 'Cannot create secondary private fixture.' );
		$tables[] = $fixture;
	}
	wpai199_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (large_key longtext NOT NULL, id int NOT NULL, PRIMARY KEY (large_key(16))) ENGINE=InnoDB', $giant ) ), 'Cannot create prefix-primary-key fixture.' );
	$tables[] = $giant;
	$large_key = 'giant-prefix-key-' . str_repeat( 'K', 40000 );
	wpai199_assert( 1 === $wpdb->insert( $giant, array( 'large_key' => $large_key, 'id' => 1 ), array( '%s', '%d' ) ), 'Cannot populate large primary-key fixture.' );
	$enum_values = array();
	for ( $i = 0; $i < 1500; ++$i ) {
		$enum_values[] = 'value' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) . str_repeat( 'x', 25 );
	}
	$enum_sql = 'CREATE TABLE %i (choice ENUM(' . implode( ',', array_fill( 0, count( $enum_values ), '%s' ) ) . ') NOT NULL) ENGINE=InnoDB';
	wpai199_assert( false !== $wpdb->query( $wpdb->prepare( $enum_sql, array_merge( array( $enum ), $enum_values ) ) ), 'Cannot create oversized schema-type fixture.' );
	$tables[] = $enum;
	$full_type = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY %s AND COLUMN_NAME = %s', $enum, 'choice' ) );
	wpai199_assert( strlen( $full_type ) > intdiv( Bounded_Payload::response_limit(), 4 ), 'Schema-type fixture does not exceed one requested window.' );
	$marker = 'wpai199-synthetic-sensitive-data-' . $suffix;
	$amount = '12345678901234567890.0123456789';
	$serialized = 'O:24:"WPAI199_NeverInstantiate":1:{s:5:"value";s:6:"opaque";}';
	$binary = "\x00\xff\x80quote'\\\n" . $marker;
	$large = str_repeat( "\x00\xff" . 'abcdefghijk', 3000 );
	foreach ( array( '1', '2', '3', '18446744073709551614' ) as $id ) {
		wpai199_assert( 1 === $wpdb->insert( $table, array(
			'id' => $id, 'telegram_message_id' => '2' === $id ? '999' : '0',
			'sync_attempts' => '3' === $id ? '2' : '0',
			'sync_next_attempt_at' => '3' === $id ? '2030-01-02 03:04:05' : null,
			'last_error' => '3' === $id ? 'Synthetic temporary network failure' : null,
			'payload' => '2' === $id ? $large : ( '3' === $id ? null : $binary ),
			'exact_amount' => $amount, 'serialized_value' => $serialized, 'private_value' => $marker,
		), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) ), 'Cannot insert synthetic delivery record.' );
	}
	foreach ( array( array( 'same', 'first' ), array( 'same', 'second' ), array( 'unique', 'third' ) ) as $row ) {
		wpai199_assert( 1 === $wpdb->insert( $heap, array( 'tag' => $row[0], 'payload' => $row[1] ) ), 'Cannot insert keyless table fixture.' );
	}
	wpai199_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE VIEW %i AS SELECT id FROM %i', $view, $table ) ), 'Cannot create synthetic view.' );
	$view_created = true;

	$grants[ Settings::GROUP_DATABASE_INSPECTION ] = 1;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	$inventory = wpai199_execute( 'database-inspect', array( 'action' => 'tables', 'limit' => 1 ) );
	wpai199_assert( ! is_wp_error( $inventory ) && 1 === count( $inventory['items'] ) && $inventory['has_more'], 'Bounded table inventory failed.' );
	$seen = array();
	$after_table = '';
	do {
		$page = wpai199_execute( 'database-inspect', array( 'action' => 'tables', 'limit' => 20, 'after_table' => $after_table ) );
		wpai199_assert( ! is_wp_error( $page ), 'Table inventory continuation failed.' );
		foreach ( $page['items'] as $item ) {
			wpai199_assert( ! isset( $seen[ $item['name'] ] ), 'Table keyset duplicated an item.' );
			$seen[ $item['name'] ] = true;
		}
		$after_table = $page['after_table'];
	} while ( $page['has_more'] );
	wpai199_assert( isset( $seen[ $table ], $seen[ $heap ] ) && ! isset( $seen[ $outside ], $seen[ $view ] ), 'Discovery missed private tables or exposed views/unrelated tables.' );
	$schema = wpai199_execute( 'database-inspect', array( 'action' => 'schema', 'table' => $table, 'limit' => 20 ) );
	wpai199_assert( ! is_wp_error( $schema ) && 9 === count( $schema['items'] ), 'Private table schema inspection failed.' );
	$schema_json = wp_json_encode( $schema );
	wpai199_assert( ! str_contains( $schema_json, $marker ) && ! str_contains( $schema_json, 'wpai199-private-schema-default' ), 'Schema discovery dumped stored/default secrets.' );
	wpai199_assert( str_contains( $schema_json, 'bigint' ) && str_contains( $schema_json, 'decimal' ), 'SQL type metadata was lost.' );
	$indexes = wpai199_execute( 'database-inspect', array( 'action' => 'schema', 'section' => 'indexes', 'table' => $table, 'limit' => 1 ) );
	wpai199_assert( ! is_wp_error( $indexes ) && 1 === count( $indexes['items'] ) && $indexes['has_more'] && 1 === $indexes['next_offset'], 'Index pagination failed.' );
	$enum_schema = wpai199_execute( 'database-inspect', array( 'action' => 'schema', 'table' => $enum, 'limit' => 1 ) );
	wpai199_assert( ! is_wp_error( $enum_schema ) && true === $enum_schema['items'][0]['sql_type_truncated'], 'Oversized type definition was not marked for continuation.' );
	$type_offset = 0;
	$type_bytes = '';
	$type_hash = hash( 'sha256', $full_type );
	do {
		$type_args = array( 'action' => 'column_type', 'table' => $enum, 'column' => 'choice', 'offset' => $type_offset, 'length' => intdiv( Bounded_Payload::response_limit(), 4 ), 'expected_sha256' => $type_hash );
		$type_window = wpai199_execute( 'database-inspect', $type_args );
		wpai199_assert( ! is_wp_error( $type_window ) && Bounded_Payload::fits( $type_window ), 'Metadata-only oversized type window failed.' );
		wpai199_assert( 'column_type_definition' === $type_window['cell']['sql_type'] && 'live_schema_not_snapshot' === $type_window['consistency'] && 'enum' === $type_window['data_type'], 'Schema continuation did not preserve its exact contract.' );
		wpai199_assert( $type_offset === $type_window['cell']['offset'] && $type_hash === $type_window['cell']['sha256'], 'Schema type window identity changed.' );
		$type_bytes .= base64_decode( $type_window['cell']['data'], true );
		$type_offset = $type_window['cell']['next_offset'];
	} while ( null !== $type_offset );
	wpai199_assert( $full_type === $type_bytes && $type_hash === hash( 'sha256', $type_bytes ), 'Complete oversized ENUM definition could not be reconstructed without sensitive-read authority.' );
	$wrong_type = wpai199_execute( 'database-inspect', array( 'action' => 'column_type', 'table' => $enum, 'column' => 'choice', 'offset' => 1, 'expected_sha256' => hash( 'sha256', 'older type' ) ) );
	wpai199_assert( is_wp_error( $wrong_type ), 'A mismatched schema-type hash was accepted.' );
	$request = array( 'action' => 'rows', 'table' => $table, 'columns' => array( 'id', 'private_value' ), 'limit' => 1 );
	wpai199_assert( is_wp_error( wpai199_execute( 'database-read', $request ) ), 'Metadata consent disclosed private raw values.' );
	$grants[ Settings::GROUP_DATABASE_READ ] = 1;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	$first = wpai199_execute( 'database-read', $request );
	wpai199_assert( ! is_wp_error( $first ) && 'integer_primary_keyset' === $first['paging'], 'Integer keyset read failed.' );
	wpai199_assert( '1' === wpai199_value( $first['items'][0], 'id' ) && $marker === wpai199_value( $first['items'][0], 'private_value' ), 'Authorized private bytes were altered or redacted.' );
	$ids = array();
	$after_id = null;
	do {
		$args = $request;
		if ( null !== $after_id ) {
			$args['after_id'] = $after_id;
		}
		$page = wpai199_execute( 'database-read', $args );
		wpai199_assert( ! is_wp_error( $page ) && Bounded_Payload::fits( $page ), 'Keyset page failed its transport budget.' );
		foreach ( $page['items'] as $item ) {
			$ids[] = wpai199_value( $item, 'id' );
		}
		$after_id = $page['after_id'];
	} while ( $page['has_more'] );
	wpai199_assert( array( '1', '2', '3', '18446744073709551614' ) === $ids, 'Unsigned BIGINT keyset lost, rounded or duplicated an ID.' );

	$pending = wpai199_execute( 'database-read', array( 'action' => 'rows', 'table' => $table, 'columns' => array( 'id', 'sync_attempts', 'last_error' ), 'filters' => array( 'telegram_message_id' => base64_encode( '0' ) ), 'limit' => 10 ) );
	wpai199_assert( ! is_wp_error( $pending ) && 3 === count( $pending['items'] ), 'Generic queue pending-initial-send filter failed.' );
	$nulls = wpai199_execute( 'database-read', array( 'action' => 'rows', 'table' => $table, 'columns' => array( 'id' ), 'filters' => array( 'sync_next_attempt_at' => null ), 'limit' => 10 ) );
	wpai199_assert( ! is_wp_error( $nulls ) && 3 === count( $nulls['items'] ), 'SQL NULL equality filter failed.' );
	$fidelity = wpai199_execute( 'database-read', array( 'action' => 'rows', 'table' => $table, 'columns' => array( 'exact_amount', 'serialized_value', 'payload' ), 'filters' => array( 'id' => base64_encode( '1' ) ), 'limit' => 1 ) );
	wpai199_assert( ! is_wp_error( $fidelity ), 'Typed byte-fidelity read failed.' );
	wpai199_assert( $amount === wpai199_value( $fidelity['items'][0], 'exact_amount' ), 'DECIMAL was converted through floating point.' );
	wpai199_assert( $serialized === wpai199_value( $fidelity['items'][0], 'serialized_value' ), 'Serialized provider bytes were decoded or rewritten.' );
	wpai199_assert( $binary === wpai199_value( $fidelity['items'][0], 'payload' ), 'Binary/NUL bytes were lost.' );
	$null = wpai199_execute( 'database-read', array( 'action' => 'cell', 'table' => $table, 'column' => 'payload', 'key' => array( 'id' => base64_encode( '3' ) ) ) );
	wpai199_assert( ! is_wp_error( $null ) && $null['cell']['is_null'] && null === $null['cell']['sha256'] && '' === $null['cell']['data'], 'SQL NULL aliased an empty string.' );

	$window_args = array( 'action' => 'cell', 'table' => $table, 'column' => 'payload', 'key' => array( 'id' => base64_encode( '2' ) ), 'length' => 2048 );
	$assembled = '';
	$offset = 0;
	$hash = hash( 'sha256', $large );
	do {
		$args = $window_args;
		$args['offset'] = $offset;
		$args['expected_sha256'] = $hash;
		$window = wpai199_execute( 'database-read', $args );
		wpai199_assert( ! is_wp_error( $window ) && Bounded_Payload::fits( $window ), 'Large-cell bounded window failed.' );
		wpai199_assert( $hash === $window['cell']['sha256'] && $offset === $window['cell']['offset'], 'Window identity/offset changed.' );
		$assembled .= base64_decode( $window['cell']['data'], true );
		$offset = $window['cell']['next_offset'];
	} while ( null !== $offset );
	wpai199_assert( $large === $assembled && $hash === hash( 'sha256', $assembled ), 'Large-cell reconstruction failed byte/SHA256 integrity.' );
	wpai199_assert( 1 === $wpdb->update( $table, array( 'payload' => 'newer synthetic value' ), array( 'id' => '2' ) ), 'Cannot update stale-read fixture.' );
	wpai199_assert( is_wp_error( wpai199_execute( 'database-read', $window_args + array( 'offset' => 2048, 'expected_sha256' => $hash ) ) ), 'Changed value was spliced into an earlier cell read.' );
	$heap_page = wpai199_execute( 'database-read', array( 'action' => 'rows', 'table' => $heap, 'columns' => array( 'tag' ), 'limit' => 1 ) );
	wpai199_assert( ! is_wp_error( $heap_page ) && 'offset_live' === $heap_page['paging'] && 1 === $heap_page['next_offset'], 'Keyless table did not report honest offset-live continuation.' );
	wpai199_assert( is_wp_error( wpai199_execute( 'database-read', array( 'action' => 'cell', 'table' => $heap, 'column' => 'payload', 'key' => array( 'tag' => base64_encode( 'same' ) ) ) ) ), 'Ambiguous key supplied an arbitrary cell.' );
	wpai199_assert( is_wp_error( wpai199_execute( 'database-read', array( 'action' => 'cell', 'table' => $table, 'column' => 'payload', 'key' => array( 'id' => base64_encode( '999999' ) ) ) ) ), 'Absent record was reported as a complete cell.' );
	$unique = wpai199_execute( 'database-read', array( 'action' => 'cell', 'table' => $heap, 'column' => 'payload', 'key' => array( 'tag' => base64_encode( 'unique' ) ) ) );
	wpai199_assert( ! is_wp_error( $unique ) && 'third' === base64_decode( $unique['cell']['data'], true ), 'Unique keyless-table record could not be read.' );

	$duplicate_large = str_repeat( chr( 255 ), 70000 );
	wpai199_assert( 2 === $wpdb->update( $heap, array( 'payload' => $duplicate_large ), array( 'tag' => 'same' ) ), 'Cannot establish duplicated oversized keyless values.' );
	$filtered_heap = wpai199_execute( 'database-read', array( 'action' => 'rows', 'table' => $heap, 'columns' => array( 'tag', 'payload' ), 'filters' => array( 'tag' => base64_encode( 'same' ) ), 'limit' => 1 ) );
	wpai199_assert( ! is_wp_error( $filtered_heap ) && 0 === $filtered_heap['items'][0]['row_offset'] && null !== $filtered_heap['items'][0]['cells']['payload']['next_offset'], 'Offset-live row did not expose its bounded positional continuation.' );
	$filtered_second = wpai199_execute( 'database-read', array( 'action' => 'rows', 'table' => $heap, 'columns' => array( 'payload' ), 'filters' => array( 'tag' => base64_encode( 'same' ) ), 'offset' => 1, 'limit' => 1 ) );
	wpai199_assert( ! is_wp_error( $filtered_second ) && 1 === $filtered_second['items'][0]['row_offset'], 'Filtered row position was not the absolute input offset.' );
	$duplicate_hash = hash( 'sha256', $duplicate_large );
	$duplicate_bytes = '';
	$duplicate_offset = 0;
	$requested_window = intdiv( Bounded_Payload::response_limit(), 4 );
	do {
		$position_window = wpai199_execute( 'database-read', array( 'action' => 'cell', 'table' => $heap, 'column' => 'payload', 'row_offset' => 0, 'filters' => array( 'tag' => base64_encode( 'same' ) ), 'offset' => $duplicate_offset, 'length' => $requested_window, 'expected_sha256' => $duplicate_hash ) );
		wpai199_assert( ! is_wp_error( $position_window ) && Bounded_Payload::fits( $position_window ), 'Duplicated keyless value could not supply a bounded positional window.' );
		$piece = base64_decode( $position_window['cell']['data'], true );
		wpai199_assert( '' !== $piece && strlen( $piece ) <= $requested_window && $duplicate_offset === $position_window['cell']['offset'], 'Adaptive positional bytes exceeded or lost their requested window.' );
		wpai199_assert( 0 === $position_window['row_offset'] && 'live_row_position_not_identity' === $position_window['consistency'], 'Positional cell was advertised as a stable row identity.' );
		if ( 0 === $duplicate_offset ) {
			wpai199_assert( strlen( $piece ) < $requested_window, 'Worst-case slash-base64 response did not adapt to its encoded MCP envelope.' );
		}
		$duplicate_bytes .= $piece;
		$next = $position_window['cell']['next_offset'];
		wpai199_assert( null === $next || $duplicate_offset + strlen( $piece ) === $next, 'Adaptive positional continuation skipped/repeated bytes.' );
		$duplicate_offset = $next;
	} while ( null !== $duplicate_offset );
	wpai199_assert( $duplicate_large === $duplicate_bytes, 'Duplicated keyless value reconstruction lost original bytes.' );
	wpai199_assert( 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET payload = %s WHERE tag = %s LIMIT 1', $heap, 'newer positional value', 'same' ) ), 'Cannot change the selected keyless row.' );
	$wrong_ordinal = wpai199_execute( 'database-read', array( 'action' => 'cell', 'table' => $heap, 'column' => 'payload', 'row_offset' => 0, 'filters' => array( 'tag' => base64_encode( 'same' ) ), 'expected_sha256' => $duplicate_hash ) );
	wpai199_assert( is_wp_error( $wrong_ordinal ) && 'database_cell_stale' === $wrong_ordinal->get_error_code(), 'Expected hash silently selected a different duplicate instead of rejecting the changed ordinal.' );
	$unchanged_ordinal = wpai199_execute( 'database-read', array( 'action' => 'cell', 'table' => $heap, 'column' => 'payload', 'row_offset' => 1, 'filters' => array( 'tag' => base64_encode( 'same' ) ), 'length' => 32, 'expected_sha256' => $duplicate_hash ) );
	wpai199_assert( ! is_wp_error( $unchanged_ordinal ) && 1 === $unchanged_ordinal['row_offset'], 'A valid unchanged second ordinal was shifted by hash filtering.' );
	$utf8 = str_repeat( 'سلام/WordPress ', 5000 );
	wpai199_assert( 1 === $wpdb->insert( $heap, array( 'tag' => 'utf8', 'payload' => $utf8 ) ), 'Cannot insert normal UTF-8 window fixture.' );
	$utf8_bytes = '';
	$utf8_offset = 0;
	do {
		$utf8_window = wpai199_execute( 'database-read', array( 'action' => 'cell', 'table' => $heap, 'column' => 'payload', 'key' => array( 'tag' => base64_encode( 'utf8' ) ), 'offset' => $utf8_offset, 'expected_sha256' => hash( 'sha256', $utf8 ) ) );
		wpai199_assert( ! is_wp_error( $utf8_window ) && Bounded_Payload::fits( $utf8_window ) && $utf8_window['cell']['bytes'] <= $requested_window, 'Default normal UTF-8 window exceeded its bounded envelope.' );
		if ( 0 === $utf8_offset ) {
			wpai199_assert( $utf8_window['cell']['bytes'] > intdiv( Bounded_Payload::response_limit(), 16 ), 'Default window retained the obsolete small response allowance.' );
		}
		$utf8_bytes .= base64_decode( $utf8_window['cell']['data'], true );
		$utf8_offset = $utf8_window['cell']['next_offset'];
	} while ( null !== $utf8_offset );
	wpai199_assert( $utf8 === $utf8_bytes, 'Normal UTF-8 bytes were not reconstructed after adaptive windows.' );

	$giant_rows = wpai199_execute( 'database-read', array( 'action' => 'rows', 'table' => $giant, 'columns' => array( 'id', 'large_key' ), 'limit' => 1 ) );
	wpai199_assert( ! is_wp_error( $giant_rows ) && Bounded_Payload::fits( $giant_rows ), 'Large prefix-primary-key row exceeded the response budget.' );
	$giant_row = $giant_rows['items'][0];
	$large_key_hash = hash( 'sha256', $large_key );
	wpai199_assert( false === $giant_row['key_complete'] && ! isset( $giant_row['key']['large_key'] ) && $large_key_hash === $giant_row['key_hashes']['large_key'], 'Oversized primary key was loaded/advertised as a complete row identity.' );
	wpai199_assert( strlen( $large_key ) === $giant_row['cells']['large_key']['total_bytes'] && $giant_row['cells']['large_key']['bytes'] < strlen( $large_key ), 'Oversized primary-key bytes were not windowed.' );
	$assembled_key = '';
	$key_offset = 0;
	do {
		$key_window = wpai199_execute( 'database-read', array( 'action' => 'cell', 'table' => $giant, 'column' => 'large_key', 'key_hashes' => $giant_row['key_hashes'], 'offset' => $key_offset, 'length' => 2048, 'expected_sha256' => $large_key_hash ) );
		wpai199_assert( ! is_wp_error( $key_window ) && Bounded_Payload::fits( $key_window ), 'Hash-selected oversized-key window failed.' );
		$assembled_key .= base64_decode( $key_window['cell']['data'], true );
		$key_offset = $key_window['cell']['next_offset'];
	} while ( null !== $key_offset );
	wpai199_assert( $large_key === $assembled_key, 'Hash-only selector could not reconstruct the exact oversized primary key.' );
	$mixed_selector = wpai199_execute( 'database-read', array( 'action' => 'cell', 'table' => $giant, 'column' => 'large_key', 'key' => array( 'id' => base64_encode( '2' ) ), 'key_hashes' => $giant_row['key_hashes'] ) );
	wpai199_assert( is_wp_error( $mixed_selector ), 'Hash selectors ignored additional exact-key requirements.' );

	foreach ( array( $outside, $view, $table . chr( 96 ) . ' WHERE 1=1 --' ) as $denied ) {
		wpai199_assert( is_wp_error( wpai199_execute( 'database-inspect', array( 'action' => 'schema', 'table' => $denied ) ) ), 'Scope/view/identifier payload bypassed exact table resolution.' );
	}
	$injected = wpai199_execute( 'database-read', array( 'action' => 'rows', 'table' => $table, 'columns' => array( 'id' . chr( 96 ) . ' FROM information_schema.TABLES --' ) ) );
	wpai199_assert( is_wp_error( $injected ), 'An unknown column became SQL.' );
	wpai199_assert( is_wp_error( wpai199_execute( 'database-read', $request + array( 'sql' => 'SELECT 1' ) ) ), 'An arbitrary SQL field was accepted.' );
	wpai199_assert( '4' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'Read-only surface mutated the fixture.' );
	wpai199_assert( $original_show === $wpdb->show_errors, 'Reader failed to restore database error-display mode.' );
	$fail_query = static function ( $query ) use ( $table, $suffix ) {
		return str_contains( $query, $table ) && str_contains( $query, 'SUBSTRING' ) ? 'SELECT wpai199_private_database_error_' . $suffix : $query;
	};
	add_filter( 'query', $fail_query );
	$query_error = $provider->read( $request );
	remove_filter( 'query', $fail_query );
	$fail_query = null;
	wpai199_assert( is_wp_error( $query_error ) && 'database_query_failed' === $query_error->get_error_code(), 'Database failure was reported as an empty successful result.' );
	wpai199_assert( ! str_contains( $query_error->get_error_message(), 'wpai199_private_database_error_' ) && ! str_contains( $query_error->get_error_message(), $marker ), 'Raw database error/query data escaped into the response.' );
	wpai199_assert( $original_suppress === $wpdb->suppress_errors && $original_show === $wpdb->show_errors, 'Database error suppression was not restored after failure.' );

	$subscriber = wp_insert_user( array( 'user_login' => 'wpai199-' . $suffix, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber', 'user_email' => 'wpai199-' . $suffix . '@example.invalid' ) );
	wpai199_assert( ! is_wp_error( $subscriber ), 'Cannot create nonadministrator fixture.' );
	wp_set_current_user( $subscriber );
	$before_protected = get_option( Settings::DATABASE_OPTION_NAME );
	wpai199_expect_protected_denial( static function () use ( $settings ) {
		update_option( Settings::DATABASE_OPTION_NAME, $settings->database_defaults(), false );
	} );
	wpai199_assert( $before_protected === get_option( Settings::DATABASE_OPTION_NAME ), 'Low-role native option update changed database grants.' );

	wpai199_assert( is_wp_error( wpai199_execute( 'database-read', $request ) ), 'A nonadministrator obtained sensitive values.' );
	wp_set_current_user( $original_user );
	wpai199_assert( $provider->can_read(), 'Authorized administrator unexpectedly denied.' );
	$grants[ Settings::GROUP_DATABASE_READ ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199_assert( is_wp_error( $provider->read( $request ) ), 'The execute callback reused authorization after grant revocation.' );
	$grants[ Settings::GROUP_DATABASE_READ ] = 1;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	$log = new Mutation_Log();
	for ( $i = 0; $i < Mutation_Log::LIMIT + 5; ++$i ) {
		wpai199_assert( $log->record( 'wp-ai-bridge/database-read', 'db_rows_synthetic', 0, true ), 'Repeated identical read audit reported a false persistence failure.' );
	}
	$entries = get_option( Mutation_Log::OPTION_NAME, array() );
	wpai199_assert( Mutation_Log::LIMIT === count( $entries ) && Mutation_Log::LIMIT === count( array_unique( array_column( $entries, 'event_ref' ) ) ), 'Repeated reads lacked independent audit event identities.' );
	$log_json = wp_json_encode( get_option( Mutation_Log::OPTION_NAME, array() ) );
	wpai199_assert( str_contains( $log_json, 'database-read' ) && ! str_contains( $log_json, $marker ) && ! str_contains( $log_json, base64_encode( $marker ) ) && ! str_contains( $log_json, $serialized ), 'Read audit missing or contained private values.' );
	$freeze_audit = static function ( $value, $old_value ) { return $old_value; };
	add_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $freeze_audit, 10, 2 );
	$audit_denied = $provider->read( $request );
	remove_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $freeze_audit, 10 );
	$freeze_audit = null;
	wpai199_assert( is_wp_error( $audit_denied ) && 'database_audit_unavailable' === $audit_denied->get_error_code(), 'Raw values escaped without confirmed audit persistence.' );
	echo "PASS: Issue #199 native database/queue reads, scopes, byte windows and permissions.\n";
} finally {
	if ( null !== $fail_query ) {
		remove_filter( 'query', $fail_query );
	}
	if ( null !== $freeze_audit ) {
		remove_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $freeze_audit, 10 );
	}
	wp_set_current_user( $original_user );
	if ( $view_created ) {
		$wpdb->query( $wpdb->prepare( 'DROP VIEW %i', $view ) );
	}
	foreach ( array_reverse( $tables ) as $fixture ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $fixture ) );
	}
	if ( is_int( $subscriber ) && $subscriber > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $subscriber );
	}
	wpai199_restore_option( Settings::OPTION_NAME, $original );
	wpai199_restore_option( Settings::DATABASE_OPTION_NAME, $original_database );
	if ( false === $original_log ) {
		delete_option( Mutation_Log::OPTION_NAME );
	} else {
		update_option( Mutation_Log::OPTION_NAME, $original_log, false );
	}
}
