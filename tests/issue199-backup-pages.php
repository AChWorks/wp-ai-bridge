<?php
/**
 * Bounded digest pages, exact integer locators and portable cursor fidelity.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Backup_Database;
use WP_AI_Bridge\Support\Backup_Exporter;
use WP_AI_Bridge\Support\Backup_Archive;
use WP_AI_Bridge\Support\Backup_Schema;

final class WPAI_Backup_Page_Native {
	public $database = 'page_source';
	public $prefix = 'wp_';
	public $rows = array();
	public $queries = array();
	public $group_queries = 0;
	public $limit = 0;
	public $corrupt_locator = null;
	public function table( $table ) { return Backup_Schema::qualified( $this->database, $table ); }
	public function run( $sql, $parameters = array(), $sources = array(), $max_rows = 4096 ) {
		$this->queries[] = array( $sql, $parameters, $max_rows );
		$groups = array();
		foreach ( $this->rows as $row ) {
			$identity = 'wpai-row-v1;';
			foreach ( $row['values'] as $value ) { $identity .= null === $value ? 'N;' : 'V' . strlen( $value ) . ':' . hash( 'sha256', $value ) . ';'; }
			$digest = hash( 'sha256', $identity );
			if ( ! isset( $groups[ $digest ] ) ) { $groups[ $digest ] = array( 'digest' => $digest, 'multiplicity' => '0', 'locator' => $row['pk'], 'values' => $row['values'] ); }
			$groups[ $digest ]['multiplicity'] = (string) ( (int) $groups[ $digest ]['multiplicity'] + 1 );
		}
		ksort( $groups, SORT_STRING );
		if ( false !== strpos( $sql, 'COUNT(*) AS multiplicity' ) ) {
			++$this->group_queries;
			preg_match( '/LIMIT ([0-9]+)$/D', $sql, $match ); $this->limit = (int) $match[1];
			$out = array();
			foreach ( $groups as $digest => $group ) {
				if ( strcmp( $digest, $parameters[0] ) <= 0 ) { continue; }
				$result = array( 'digest' => $digest, 'multiplicity' => $group['multiplicity'] );
				if ( false !== strpos( $sql, ' AS locator' ) ) { $result['locator'] = $this->corrupt_locator ?? $group['locator']; }
				$out[] = $result;
				if ( count( $out ) === $this->limit ) { break; }
			}
			return array( 'rows' => $out );
		}
		$indexed = false !== strpos( $sql, '=CAST(? AS ' );
		$digest = $parameters[ $indexed ? 3 : 2 ];
		$group = $groups[ $digest ] ?? null;
		if ( null === $group || $indexed && $parameters[2] !== $group['locator'] ) { return array( 'rows' => array() ); }
		$value = $group['values'][1];
		return array( 'rows' => array( array( 'value' => null === $value ? null : substr( $value, (int) $parameters[0] - 1, (int) $parameters[1] ),
			'bytes' => null === $value ? null : (string) strlen( $value ), 'digest' => null === $value ? null : hash( 'sha256', $value ) ) ) );
	}
}
$checks = 0;
$assert = static function ( $value, $message ) use ( &$checks ) { ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } };
$error = static function ( $callback ) { try { $callback(); } catch ( Throwable $caught ) { return $caught->getMessage(); } return null; };
$schema = static function ( $name = 'wp_pages', $kind = 'bigint unsigned', $key = 'PRIMARY KEY (`id`)' ) {
	$ddl = 'CREATE TABLE `' . $name . '` (`id` ' . $kind . ' NOT NULL,`payload` longblob DEFAULT NULL' .
		( '' === $key ? '' : ',' . $key ) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
	return array( 'table' => $name, 'ddl' => $ddl, 'hash' => hash( 'sha256', $ddl ), 'blueprint' => Backup_Schema::blueprint( $ddl, $name ),
		'columns' => array( array( 'name' => 'id', 'type' => strtok( $kind, ' (' ), 'charset' => null ),
			array( 'name' => 'payload', 'type' => 'longblob', 'charset' => null ) ) );
};
$make = static function ( $budget = 8192 ) {
	$r = new ReflectionClass( Backup_Database::class ); $db = $r->newInstanceWithoutConstructor(); $native = new WPAI_Backup_Page_Native();
	$r->getProperty( 'connection' )->setValue( $db, $native ); $r->getProperty( 'budget' )->setValue( $db, $budget );
	return array( $db, $native );
};
$native_rows = array();
foreach ( array( '0', '1', '2', '3', '4', '5', '6', '9223372036854775808', '18446744073709551615' ) as $i => $pk ) {
	$native_rows[] = array( 'pk' => $pk, 'values' => array( $pk, 0 === $i ? null : ( 1 === $i ? '' : "raw\0\xff:" . $pk ) ) );
}
list( $db, $native ) = $make(); $native->rows = $native_rows; $s = $schema();
$after = ''; $groups = array();
do {
	$group = $db->next_group( $s, $after );
	if ( null !== $group ) { $groups[] = $group; $after = $group['digest']; }
} while ( null !== $group );
$assert( $native->limit > 1 && $native->group_queries < count( $native_rows ), 'A finite processing budget must fetch/cache multiple digest groups instead of one full scan per row.' );
$digests = array_column( $groups, 'digest' ); $sorted = $digests; sort( $sorted, SORT_STRING );
$assert( count( $native_rows ) === count( $groups ) && $digests === $sorted && count( array_unique( $digests ) ) === count( $digests ),
	'Pages must retain every exact lexicographic digest once without an aggregate row ceiling.' );
$assert( $native->group_queries <= (int) ceil( count( $native_rows ) / $native->limit ) + 1, 'Cache misses must follow bounded page edges, including final EOF.' );
$max_group = null;
foreach ( $groups as $group ) { if ( '18446744073709551615' === ( $group['locator'] ?? null ) ) { $max_group = $group; } }
$assert( is_array( $max_group ), 'Unsigned64 native PK locators must remain exact decimal strings beyond PHP_INT_MAX.' );
$cell = $db->cell( $s, $max_group['digest'], 1, 0, 4096, $max_group['locator'] );
$last = end( $native->queries );
$assert( $cell['value'] === "raw\0\xff:18446744073709551615" && $last[1][2] === '18446744073709551615' &&
	false !== strpos( $last[0], '`id`=CAST(? AS UNSIGNED) AND ' ) && false !== strpos( $last[0], 'SHA2(CONCAT(' ),
	'Integer cell lookup must bind exact native PK type and retain the original full-row hash predicate.' );
$assert( 'backup_source_stale' === $error( static function () use ( $db, $s, $max_group ) { $db->cell( $s, $max_group['digest'], 1, 0, 4096, '0' ); } ),
	'A wrong but valid locator must fail the original group identity instead of silently rescanning another row.' );
$assert( 'backup_source_locator_invalid' === $error( static function () use ( $db, $s, $max_group ) { $db->cell( $s, $max_group['digest'], 1, 0, 4096, '18446744073709551616' ); } ),
	'Unsigned64 locator overflow must reject without PHP integer/float conversion.' );
$native->rows[8]['values'][1] = 'changed';
$assert( 'backup_source_stale' === $error( static function () use ( $db, $s, $max_group ) { $db->cell( $s, $max_group['digest'], 1, 0, 4096, $max_group['locator'] ); } ),
	'An indexed PK hit must still reject changed source row bytes.' );

list( $db, $native ) = $make( 16384 ); $native->rows = $native_rows;
$first = $db->next_group( $s, '' ); $second = $db->next_group( $s, $first['digest'] );
$assert( 1 === $native->group_queries, 'Adjacent groups in one page must reuse a single native bounded result.' );
$repeat = $db->next_group( $s, '' );
$assert( $repeat === $first && 2 === $native->group_queries, 'A restarted/replayed cursor must invalidate the consumed page rather than skip rows.' );
$fresh_schema = $schema( 'wp_other' ); $db->next_group( $fresh_schema, $repeat['digest'] );
$assert( 3 === $native->group_queries, 'Page cache identities must separate native table/schema contexts.' );
$db->next_group( $fresh_schema, $repeat['digest'], 'isolated_target' );
$assert( 4 === $native->group_queries, 'Destination verification must not consume source cache entries.' );

foreach ( array( array( 'bigint', 'PRIMARY KEY (`id`)', '-9223372036854775808' ), array( 'int', 'PRIMARY KEY (`id`)', '-2147483648' ) ) as $case ) {
	list( $db, $native ) = $make(); $s = $schema( 'wp_signed', $case[0], $case[1] );
	$native->rows = array( array( 'pk' => $case[2], 'values' => array( $case[2], 'signed' ) ) );
	$group = $db->next_group( $s, '' ); $db->cell( $s, $group['digest'], 1, 0, 4096, $group['locator'] );
	$last = end( $native->queries );
	$assert( false !== strpos( $last[0], '=CAST(? AS SIGNED) AND ' ) && $case[2] === $last[1][2], 'Signed native integer boundaries must remain exact and indexed.' );
}
foreach ( array(
	array( 'bigint unsigned', '' ),
	array( 'varchar(64)', 'PRIMARY KEY (`id`)' ),
	array( 'bigint unsigned', 'PRIMARY KEY (`id`,`payload`(16))' ),
	array( 'decimal(30,0)', 'PRIMARY KEY (`id`)' ),
	array( 'bit(64)', 'PRIMARY KEY (`id`)' ),
) as $case ) {
	list( $db, $native ) = $make(); $s = $schema( 'wp_fallback', $case[0], $case[1] );
	$native->rows = array( array( 'pk' => '1', 'values' => array( '1', "fallback\0" ) ), array( 'pk' => '1', 'values' => array( '1', "fallback\0" ) ) );
	$group = $db->next_group( $s, '' ); $db->cell( $s, $group['digest'], 1, 0, 4096, $group['locator'] ?? null );
	$last = end( $native->queries );
	$assert( '2' === $group['multiplicity'] && ! isset( $group['locator'] ) && false === strpos( $last[0], '=CAST(? AS ' ),
		'Keyless/text/composite/unproven keys must keep duplicate multiplicities and the existing exact-hash fallback.' );
}
list( $db, $native ) = $make(); $s = $schema(); $native->rows = $native_rows; $native->corrupt_locator = '18446744073709551616';
$assert( 'backup_source_locator_invalid' === $error( static function () use ( $db, $s ) { $db->next_group( $s, '' ); } ),
	'Native page metadata outside the proved PK range must fail instead of being accepted as a lookup shortcut.' );

list( $db, $native ) = $make( 16384 ); $s = $schema(); $native->rows = $native_rows;
$one = $db->next_group( $s, '' );
$db->reset_groups(); $db->next_group( $s, '' );
$assert( 2 === $native->group_queries, 'An explicit verification reset must fetch fresh native hashes rather than reuse capture evidence.' );
$reflection = new ReflectionClass( Backup_Exporter::class ); $exporter = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty( 'database' )->setValue( $exporter, $db );
$state = array( 'schema' => array( 'untrusted-cache-placeholder' ), 'group' => $one );
$args = array( &$state ); $reflection->getMethod( 'rewind' )->invokeArgs( $exporter, $args );
$db->next_group( $s, '' );
$assert( 3 === $native->group_queries && ! isset( $state['group'], $state['schema'] ),
	'Capture/source-verification and footer-crash rewind must discard private page evidence and the current locator.' );

// Simulate a frame appended before its cursor CAS survived. Only scalar JSON
// checkpoint state is recovered; a fresh request has no in-memory native page.
$packed = array( 'table' => base64_encode( $s['table'] ), 'ddl' => base64_encode( $s['ddl'] ), 'hash' => $s['hash'],
	'columns' => array( array( 'name' => base64_encode( 'id' ), 'type' => 'bigint', 'charset' => null ),
		array( 'name' => base64_encode( 'payload' ), 'type' => 'longblob', 'charset' => null ) ) );
$target = null; $after = '';
while ( null !== ( $candidate = $db->next_group( $s, $after ) ) ) {
	$after = $candidate['digest']; if ( '18446744073709551615' === ( $candidate['locator'] ?? null ) ) { $target = $candidate; break; }
}
$state = array( 'phase' => 'cell', 'schema' => $packed, 'group' => $target, 'column' => 1, 'cell_offset' => 0, 'chunk_bytes' => 4, 'after' => '' );
$checkpoint = json_encode( $state, JSON_THROW_ON_ERROR );
$next = $reflection->getMethod( 'next' ); $args = array( &$state ); $record = $next->invokeArgs( $exporter, $args );
$record['record']['seq'] = 0; $frame = Backup_Archive::encode( $record['record'], $record['payload'], 16384 );
$assert( '' === $state['after'] && 4 === $state['cell_offset'] && ! isset( $record['record']['locator'] ),
	'An unfinished row must not advance the digest cursor or expose its private locator in v1 cell records.' );
list( $fresh, $fresh_native ) = $make(); $fresh_native->rows = $native_rows;
$reflection->getProperty( 'database' )->setValue( $exporter, $fresh );
$recovered = json_decode( $checkpoint, true, 512, JSON_THROW_ON_ERROR ); $args = array( &$recovered );
$replay = $next->invokeArgs( $exporter, $args ); $replay['record']['seq'] = 0;
$assert( $frame === Backup_Archive::encode( $replay['record'], $replay['payload'], 16384 ) && 0 === $fresh_native->group_queries &&
	'18446744073709551615' === $recovered['group']['locator'],
	'JSON-only recovery must reproduce an unacknowledged exact frame using its private locator without a warm page/native cursor.' );
while ( $recovered['column'] < 2 ) { $args = array( &$recovered ); $next->invokeArgs( $exporter, $args ); $assert( '' === $recovered['after'], 'The row cursor must remain before its digest until all cells finish.' ); }
$args = array( &$recovered ); $row_end = $next->invokeArgs( $exporter, $args );
$assert( 'row_end' === $row_end['record']['type'] && $target['digest'] === $recovered['after'],
	'Only completed row_end may advance the lexicographic digest cursor.' );
foreach ( array( '0' => null, '1' => '' ) as $pk => $expected ) {
	list( $db, $native ) = $make(); $s = $schema(); $native->rows = array( $native_rows[ (int) $pk ] );
	$group = $db->next_group( $s, '' ); $cell = $db->cell( $s, $group['digest'], 1, 0, 4, $group['locator'] );
	$assert( $expected === $cell['value'] && ( null === $expected ? null === $cell['bytes'] : '0' === $cell['bytes'] ),
		'Indexed lookup must keep NULL, empty and zero-PK distinctions.' );
}
$alias_ddl = 'CREATE TABLE `wp_alias_pages` (`wpai_row_pk` bigint unsigned NOT NULL,`digest` varbinary(64) DEFAULT NULL,`multiplicity` varbinary(64) DEFAULT NULL,`wpai_row_sha256` varbinary(64) DEFAULT NULL,`wpai_backup_rows` varbinary(64) DEFAULT NULL,PRIMARY KEY (`wpai_row_pk`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
$alias_schema = array( 'table' => 'wp_alias_pages', 'hash' => hash( 'sha256', $alias_ddl ), 'blueprint' => Backup_Schema::blueprint( $alias_ddl, 'wp_alias_pages' ),
	'columns' => array_map( static function ( $name ) { return array( 'name' => $name ); }, array( 'wpai_row_pk', 'digest', 'multiplicity', 'wpai_row_sha256', 'wpai_backup_rows' ) ) );
list( $db, $native ) = $make();
$native->rows = array( array( 'pk' => '1', 'values' => array( '1', 'same', 'same', 'same', 'same' ) ),
	array( 'pk' => '2', 'values' => array( '2', 'same', 'same', 'same', 'same' ) ) );
$first = $db->next_group( $alias_schema, '' ); $second = $db->next_group( $alias_schema, $first['digest'] );
$sql = $native->queries[0][0];
$assert( $first['digest'] !== $second['digest'] && false !== strpos( $sql, 'MIN(`wpai_backup_rows`.`wpai_row_pk`) AS locator' ) &&
	false !== strpos( $sql, '`wpai_row_pk` AS `wpai_row_pk`' ) && false === strpos( $sql, 'GROUP BY digest' ),
	'Generated-looking source names must retain isolated native row-hash/PK namespaces and distinct rows.' );


list( $db, $native ) = $make(); $s = $schema( 'wp_signed_overflow', 'bigint' ); $native->rows = array( array( 'pk' => '-1', 'values' => array( '-1', 'signed' ) ) );
$group = $db->next_group( $s, '' );
foreach ( array( '-9223372036854775809', '9223372036854775808', '-0', '+1' ) as $bad_locator ) {
	$assert( 'backup_source_locator_invalid' === $error( static function () use ( $db, $s, $group, $bad_locator ) { $db->cell( $s, $group['digest'], 1, 0, 4, $bad_locator ); } ),
		'Signed overflow and noncanonical decimal locators must reject before native query conversion.' );
}
$escaped_ddl = 'CREATE TABLE `wp_escaped_pk` (`a``b` bigint unsigned NOT NULL,`payload` longblob DEFAULT NULL,PRIMARY KEY (`a``b`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
$s = array( 'table' => 'wp_escaped_pk', 'hash' => hash( 'sha256', $escaped_ddl ), 'blueprint' => Backup_Schema::blueprint( $escaped_ddl, 'wp_escaped_pk' ),
	'columns' => array( array( 'name' => 'a`b' ), array( 'name' => 'payload' ) ) );
list( $db, $native ) = $make(); $native->rows = array( array( 'pk' => '1', 'values' => array( '1', 'escaped' ) ) );
$group = $db->next_group( $s, '' ); $db->cell( $s, $group['digest'], 1, 0, 20, $group['locator'] ); $query = end( $native->queries );
$assert( '1' === $group['locator'] && false !== strpos( $query[0], '`a``b`=CAST(? AS UNSIGNED) AND ' ),
	'An escaped native integer PK identifier must retain exact quoting and the bounded locator path.' );
list( $db, $native ) = $make(); $s = $schema(); $native->rows = $native_rows;
$reflection->getProperty( 'database' )->setValue( $exporter, $db );
$state = array( 'phase' => 'group', 'schema' => $packed, 'after' => '', 'db_digest' => hash( 'sha256', 'wpai-backup-database-v1' ) );
$args = array( &$state ); $row_record = $next->invokeArgs( $exporter, $args );
$assert( isset( $state['group']['locator'] ) && array( 'type', 'digest', 'multiplicity' ) === array_keys( $row_record['record'] ),
	'Private page locators must not change the portable v1 row record or duplicate multiplicity fields.' );

echo "issue199-backup-pages: {$checks} checks passed\n";
