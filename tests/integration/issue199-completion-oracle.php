<?php
/**
 * Independent physical restore oracle. Never calls the backup implementation.
 *
 * @package WP_AI_Bridge
 */

function wpai199c_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai199c_identifier( $name ) {
	return '`' . str_replace( '`', '``', $name ) . '`';
}

/** Metadata is byte encoded so binary defaults cannot disappear in JSON. */
function wpai199c_metadata_hash( array $rows ) {
	foreach ( $rows as &$row ) {
		ksort( $row, SORT_STRING );
		foreach ( $row as &$value ) {
			$value = null === $value ? null : base64_encode( (string) $value );
		}
		unset( $value );
	}
	unset( $row );
	return hash( 'sha256', json_encode( $rows, JSON_THROW_ON_ERROR ) );
}

/** Oracle failures never forward data-bearing native diagnostics. */
function wpai199c_query( mysqli $db, $sql, $stream = false ) {
	try {
		return $db->query( $sql, $stream ? MYSQLI_USE_RESULT : MYSQLI_STORE_RESULT );
	} catch ( mysqli_sql_exception $error ) {
		throw new RuntimeException( 'Independent oracle native read failed: ' . (int) $error->getCode() );
	}
}

function wpai199c_metadata( mysqli $db, $sql ) {
	$result = wpai199c_query( $db, $sql );
	wpai199c_assert( $result instanceof mysqli_result, 'Oracle metadata result missing.' );
	try {
		return $result->fetch_all( MYSQLI_ASSOC );
	} finally {
		$result->free();
	}
}

/**
 * Hash every physical cell and every duplicate occurrence, without deserializing.
 * Complete rows are streamed; explicit budgets fail instead of truncating.
 */
function wpai199c_row_multiset( mysqli $db, $schema, $table, array $columns, $max_rows, $max_bytes ) {
	$sql = 'SELECT ' . implode( ',', array_map( static function ( $column ) {
		return 'CAST(' . wpai199c_identifier( $column ) . ' AS BINARY)';
	}, $columns ) ) . ' FROM ' . wpai199c_identifier( $schema ) . '.' . wpai199c_identifier( $table );
	$result = wpai199c_query( $db, $sql, true );
	wpai199c_assert( $result instanceof mysqli_result, 'Oracle physical row stream missing.' );
	$multiset = array();
	$count = 0;
	$bytes = 0;
	try {
		while ( is_array( $row = $result->fetch_row() ) ) {
			++$count;
			wpai199c_assert( $count <= $max_rows, 'Oracle row budget exceeded; no complete comparison claimed.' );
			$hash = hash_init( 'sha256' );
			foreach ( $row as $cell ) {
				if ( null === $cell ) {
					hash_update( $hash, "N;" );
					continue;
				}
				$bytes += strlen( $cell );
				wpai199c_assert( $bytes <= $max_bytes, 'Oracle byte budget exceeded; no complete comparison claimed.' );
				hash_update( $hash, 'B' . strlen( $cell ) . ':' );
				hash_update( $hash, $cell );
			}
			$key = hash_final( $hash );
			$multiset[ $key ] = ( $multiset[ $key ] ?? 0 ) + 1;
		}
		wpai199c_assert( null === $row, 'Oracle physical row stream did not finish cleanly.' );
	} finally {
		$result->free();
	}
	ksort( $multiset, SORT_STRING );
	$hash = hash_init( 'sha256' );
	foreach ( $multiset as $row_hash => $multiplicity ) {
		hash_update( $hash, $row_hash . ':' . $multiplicity . "\n" );
	}
	return array( 'rows' => $count, 'cell_bytes' => $bytes, 'multiset_sha256' => hash_final( $hash ) );
}

/** Compare on the same native engine; schema-name references normalize to self. */
function wpai199c_database_image( mysqli $db, $schema, $max_rows = 100000, $max_bytes = 268435456 ) {
	$q_schema = "'" . $db->real_escape_string( $schema ) . "'";
	$inventory = wpai199c_metadata( $db, 'SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $q_schema . ' ORDER BY BINARY TABLE_NAME' );
	$image = array( 'tables' => array(), 'objects' => array() );
	$statistic_fields = array_column( wpai199c_metadata( $db, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='information_schema' AND TABLE_NAME='STATISTICS'" ), 'COLUMN_NAME' );
	$extra_statistics = '';
	foreach ( array( 'IS_VISIBLE', 'IGNORED', 'EXPRESSION' ) as $field ) {
		if ( in_array( $field, $statistic_fields, true ) ) {
			$extra_statistics .= ',' . wpai199c_identifier( $field );
		}
	}
	foreach ( $inventory as $item ) {
		wpai199c_assert( 'BASE TABLE' === $item['TABLE_TYPE'], 'Oracle expected supported ordinary tables; unsupported object must block backup preflight.' );
		$table = $item['TABLE_NAME'];
		$where = ' WHERE TABLE_SCHEMA=' . $q_schema . " AND BINARY TABLE_NAME=BINARY '" . $db->real_escape_string( $table ) . "'";
		$properties = wpai199c_metadata( $db, 'SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION,CREATE_OPTIONS,TABLE_COMMENT,AUTO_INCREMENT FROM information_schema.TABLES' . $where );
		$columns = wpai199c_metadata( $db, 'SELECT COLUMN_NAME,ORDINAL_POSITION,COLUMN_DEFAULT,IS_NULLABLE,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_OCTET_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,DATETIME_PRECISION,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_TYPE,COLUMN_KEY,EXTRA,COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS' . $where . ' ORDER BY ORDINAL_POSITION' );
		$indexes = wpai199c_metadata( $db, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,PACKED,NULLABLE,INDEX_TYPE,COMMENT,INDEX_COMMENT' . $extra_statistics . ' FROM information_schema.STATISTICS' . $where . ' ORDER BY BINARY INDEX_NAME,SEQ_IN_INDEX' );
		$keys = wpai199c_metadata( $db, 'SELECT CONSTRAINT_NAME,ORDINAL_POSITION,COLUMN_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE' . $where . ' ORDER BY BINARY CONSTRAINT_NAME,ORDINAL_POSITION' );
		foreach ( $keys as &$key ) {
			if ( $schema === $key['REFERENCED_TABLE_SCHEMA'] ) {
				$key['REFERENCED_TABLE_SCHEMA'] = 'SELF';
			}
		}
		unset( $key );
		$foreign = wpai199c_metadata( $db, 'SELECT CONSTRAINT_NAME,UNIQUE_CONSTRAINT_NAME,MATCH_OPTION,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=' . $q_schema . " AND BINARY TABLE_NAME=BINARY '" . $db->real_escape_string( $table ) . "' ORDER BY BINARY CONSTRAINT_NAME" );
		$image['tables'][ $table ] = array(
			'properties' => wpai199c_metadata_hash( $properties ),
			'columns' => wpai199c_metadata_hash( $columns ),
			'indexes' => wpai199c_metadata_hash( $indexes ),
			'keys' => wpai199c_metadata_hash( $keys ),
			'foreign' => wpai199c_metadata_hash( $foreign ),
			'data' => wpai199c_row_multiset( $db, $schema, $table, array_column( $columns, 'COLUMN_NAME' ), $max_rows, $max_bytes ),
		);
	}
	foreach ( array(
		'triggers' => 'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=' . $q_schema . ' ORDER BY BINARY TRIGGER_NAME',
		'routines' => 'SELECT ROUTINE_NAME,ROUTINE_TYPE,DATA_TYPE,ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=' . $q_schema . ' ORDER BY BINARY ROUTINE_NAME',
		'events' => 'SELECT EVENT_NAME,EVENT_DEFINITION,EVENT_TYPE,INTERVAL_VALUE,INTERVAL_FIELD,STATUS FROM information_schema.EVENTS WHERE EVENT_SCHEMA=' . $q_schema . ' ORDER BY BINARY EVENT_NAME',
	) as $kind => $sql ) {
		$rows = wpai199c_metadata( $db, $sql );
		$image['objects'][ $kind ] = array( 'count' => count( $rows ), 'sha256' => wpai199c_metadata_hash( $rows ) );
	}
	return $image;
}

/** Hash every included file and directory, including empty-directory modes. */
function wpai199c_file_image( $root, $max_entries = 100000, $max_bytes = 1073741824 ) {
	$root = realpath( $root );
	wpai199c_assert( is_string( $root ) && is_dir( $root ), 'Oracle installation root absent.' );
	$entries = array( '.' => $root );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $iterator as $entry ) {
		$relative = substr( $entry->getPathname(), strlen( $root ) + 1 );
		$entries[ $relative ] = $entry->getPathname();
		wpai199c_assert( count( $entries ) <= $max_entries, 'Oracle file inventory budget exceeded.' );
	}
	ksort( $entries, SORT_STRING );
	$image = array();
	$bytes = 0;
	foreach ( $entries as $relative => $path ) {
		$before = lstat( $path );
		wpai199c_assert( is_array( $before ), 'Oracle included path disappeared.' );
		$type = $before['mode'] & 0170000;
		wpai199c_assert( in_array( $type, array( 0040000, 0100000 ), true ), 'Oracle unsupported filesystem object; backup must not silently omit it.' );
		$row = array( 'type' => 0040000 === $type ? 'directory' : 'file', 'mode' => $before['mode'] & 07777 );
		if ( 0100000 === $type ) {
			$bytes += $before['size'];
			wpai199c_assert( $bytes <= $max_bytes, 'Oracle complete file-byte budget exceeded.' );
			$row['bytes'] = $before['size'];
			$row['sha256'] = hash_file( 'sha256', $path );
			wpai199c_assert( is_string( $row['sha256'] ) && 64 === strlen( $row['sha256'] ), 'Oracle could not hash complete included file bytes.' );
			clearstatcache( true, $path );
			$after = lstat( $path );
			foreach ( array( 'dev', 'ino', 'mode', 'size', 'mtime', 'ctime' ) as $field ) {
				wpai199c_assert( $after && $before[ $field ] === $after[ $field ], 'Oracle source changed while hashing under declared quiescence.' );
			}
		}
		$image[ $relative ] = $row;
	}
	return $image;
}

function wpai199c_assert_restored( array $expected_database, array $expected_files, mysqli $db, $target_schema, $restored_installation_root ) {
	$actual_database = wpai199c_database_image( $db, $target_schema );
	wpai199c_assert( $expected_database === $actual_database, 'Actual restored schema/data/object inventory differs from independent source oracle.' );
	$actual_files = wpai199c_file_image( $restored_installation_root );
	wpai199c_assert( $expected_files === $actual_files, 'Actual restored path/type/mode/bytes/empty-directory inventory differs from independent source oracle.' );
	return array( 'tables' => count( $actual_database['tables'] ), 'file_entries' => count( $actual_files ), 'physical_comparison' => 'complete' );
}
