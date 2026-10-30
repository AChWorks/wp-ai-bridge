<?php
/**
 * Core-sized complete metadata processing is independent of message windows.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';
if ( ! defined( 'WP_MAX_MEMORY_LIMIT' ) ) { define( 'WP_MAX_MEMORY_LIMIT', '-1' ); }
if ( ! defined( 'WP_MEMORY_LIMIT' ) ) { define( 'WP_MEMORY_LIMIT', '-1' ); }

use WP_AI_Bridge\Support\Backup_Database;
use WP_AI_Bridge\Support\Backup_Exporter;
use WP_AI_Bridge\Support\Backup_Operation;
use WP_AI_Bridge\Support\Backup_Schema;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

final class WPAI_Backup_Capacity_Access {
	public function configuration() { return array( 'step_bytes' => 1048576, 'step_seconds' => 5, 'backup_tables' => array() ); }
}

/** Query-only 23-column native Core posts metadata fixture. */
final class WPAI_Backup_Capacity_Native {
	public $database = 'wp_capacity';
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $materialized = false;
	public $column_reads = 0;
	public $ddl;
	public $columns = array();

	public function __construct() {
		$types = array( 'ID' => 'bigint unsigned', 'post_author' => 'bigint unsigned', 'post_date' => 'datetime', 'post_date_gmt' => 'datetime',
			'post_content' => 'longtext', 'post_title' => 'text', 'post_excerpt' => 'text', 'post_status' => 'varchar(20)',
			'comment_status' => 'varchar(20)', 'ping_status' => 'varchar(20)', 'post_password' => 'varchar(255)', 'post_name' => 'varchar(200)',
			'to_ping' => 'text', 'pinged' => 'text', 'post_modified' => 'datetime', 'post_modified_gmt' => 'datetime',
			'post_content_filtered' => 'longtext', 'post_parent' => 'bigint unsigned', 'guid' => 'varchar(255)', 'menu_order' => 'int',
			'post_type' => 'varchar(20)', 'post_mime_type' => 'varchar(100)', 'comment_count' => 'bigint' );
		$q = static function ( $name ) { return chr( 96 ) . $name . chr( 96 ); };
		$definitions = array();
		foreach ( $types as $name => $type ) {
			$base = preg_split( '/[ (]/', $type )[0];
			$charset = in_array( $base, array( 'varchar', 'text', 'longtext' ), true ) ? 'utf8mb4' : null;
			$this->columns[] = array( 'name' => $name, 'type' => $base, 'charset' => $charset,
				'collation' => null === $charset ? null : 'utf8mb4_unicode_ci', 'extra' => 'ID' === $name ? 'auto_increment' : '', 'expression' => '' );
			$definitions[] = $q( $name ) . ' ' . $type . ' NOT NULL' . ( 'ID' === $name ? ' AUTO_INCREMENT' : '' );
		}
		$definitions[] = 'PRIMARY KEY (' . $q( 'ID' ) . ')';
		$definitions[] = 'KEY ' . $q( 'post_name' ) . ' (' . $q( 'post_name' ) . '(191))';
		$definitions[] = 'KEY ' . $q( 'type_status_date' ) . ' (' . implode( ',', array_map( $q, array( 'post_type', 'post_status', 'post_date', 'ID' ) ) ) . ')';
		$definitions[] = 'KEY ' . $q( 'post_parent' ) . ' (' . $q( 'post_parent' ) . ')';
		$definitions[] = 'KEY ' . $q( 'post_author' ) . ' (' . $q( 'post_author' ) . ')';
		$definitions[] = 'KEY ' . $q( 'type_status_author' ) . ' (' . implode( ',', array_map( $q, array( 'post_type', 'post_status', 'post_author' ) ) ) . ')';
		$this->ddl = 'CREATE TABLE ' . $q( 'wp_posts' ) . ' (' . implode( ',', $definitions ) . ') ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
	}
	public function has_privilege( $privilege, $table ) { return true; }
	public function table( $name ) { return Backup_Schema::qualified( $this->database, $name ); }
	public function run( $sql, array $parameters = array(), array $sources = array(), $max_rows = 4096 ) {
		if ( 0 !== strpos( $sql, 'SELECT ' ) && 0 !== strpos( $sql, 'SHOW ' ) ) { throw new RuntimeException( 'Capacity preflight attempted an effect.' ); }
		if ( false !== strpos( $sql, '@@SESSION.sql_mode AS sql_mode' ) ) {
			$rows = array( array( 'sql_mode' => '', 'time_zone' => '+00:00', 'version' => '8.4.6-capacity-fixture' ) );
		} elseif ( 0 === strpos( $sql, 'SELECT TABLE_NAME AS name,TABLE_TYPE AS kind' ) ) {
			$rows = array( array( 'name' => 'wp_posts', 'kind' => 'BASE TABLE' ) );
		} elseif ( 0 === strpos( $sql, 'SELECT ENGINE AS engine' ) ) {
			$rows = array( array( 'engine' => 'InnoDB', 'kind' => 'BASE TABLE', 'options' => '', 'comment_bytes' => '0' ) );
		} elseif ( false !== strpos( $sql, 'SUM(OCTET_LENGTH(INDEX_COMMENT)' ) ) {
			$rows = array( array( 'n' => '5632' ) );
		} elseif ( false !== strpos( $sql, 'SUM(OCTET_LENGTH(COLUMN_TYPE)' ) ) {
			// Actual Core 6.9.4/MySQL 8.4.6 admission aggregates, not value bytes.
			$rows = array( array( 'n' => '23861' ) );
		} elseif ( 0 === strpos( $sql, 'SELECT COLUMN_NAME AS name,DATA_TYPE AS type' ) ) {
			++$this->column_reads;
			$rows = $this->columns;
		} elseif ( 0 === strpos( $sql, 'SHOW CREATE TABLE ' ) ) {
			$this->materialized = true;
			$rows = array( array( 'Table' => 'wp_posts', 'Create Table' => $this->ddl ) );
		} else {
			$rows = array();
		}
		return array( 'rows' => $rows, 'affected' => 0, 'insert_id' => '0' );
	}
}

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$database = static function ( $budget, $native ) {
	$r = new ReflectionClass( Backup_Database::class );
	$db = $r->newInstanceWithoutConstructor();
	$r->getProperty( 'budget' )->setValue( $db, $budget );
	$r->getProperty( 'connection' )->setValue( $db, $native );
	return $db;
};
$permissions = new Permissions( new Settings() );
$operation = new Backup_Operation( $permissions );
$r = new ReflectionClass( $operation );
$r->getProperty( 'access' )->setValue( $operation, new WPAI_Backup_Capacity_Access() );
$policy_method = $r->getMethod( 'policy' );
$original_memory = ini_get( 'memory_limit' );
ini_set( 'memory_limit', '256M' );
try {
	$policy = $policy_method->invoke( $operation );
	$assert( 131072 === $policy['request'] && 131072 === Bounded_Payload::response_limit() && 49152 === $policy['chunk'], 'Default transport was enlarged to bypass internal processing capacity.' );
	$assert( 1048576 === $policy['processing'] && $policy['memory_known'], 'Configured work and actual PHP headroom must provide a distinct finite processing allowance.' );
	$charged = ( 23861 + 5632 ) * 8 + 8192;
	$assert( 244136 === $charged && $charged > $policy['request'] && $charged < $policy['processing'], 'Actual Core admission must distinguish message and processing budgets.' );
	$native = new WPAI_Backup_Capacity_Native();
	$schema = $database( $policy['processing'], $native )->schema( 'wp_posts' );
	$assert( 23 === count( $schema['columns'] ) && $native->materialized && 1 === $native->column_reads, 'Complete ordinary Core-sized metadata must pass with default transport.' );
	$assert( hash( 'sha256', $native->ddl ) === $schema['hash'], 'The processing repair must retain complete schema bytes and integrity.' );
	$old = new WPAI_Backup_Capacity_Native();
	$old_failed = false;
	try { $database( $policy['request'], $old )->schema( 'wp_posts' ); }
	catch ( RuntimeException $error ) { $old_failed = 'backup_metadata_capacity' === $error->getMessage(); }
	$assert( $old_failed && ! $old->materialized && 0 === $old->column_reads, 'Former message-sized admission must reproduce rejection before materialization.' );

	$assert( false !== ini_set( 'memory_limit', '-1' ), 'Could not establish unknown/unlimited effective ceilings.' );
	$unknown = $policy_method->invoke( $operation );
	$assert( ! $unknown['memory_known'] && null === $unknown['memory_headroom'] && 1048576 === $unknown['processing'],
		'Unknown ceilings must retain the operator finite processing allowance instead of reverting to the unrelated message quantum.' );
	$unknown_native = new WPAI_Backup_Capacity_Native();
	$unknown_schema = $database( $unknown['processing'], $unknown_native )->schema( 'wp_posts' );
	$assert( 23 === count( $unknown_schema['columns'] ) && $unknown_native->materialized,
		'An unknown host ceiling must not reintroduce message-sized rejection of the complete Core-sized schema.' );
	$low_native = new WPAI_Backup_Capacity_Native();
	$low_limit = memory_get_usage( true ) + 1048576;
	$assert( false !== ini_set( 'memory_limit', (string) $low_limit ), 'Could not establish actual finite PHP low-headroom conditions.' );
	$low = $policy_method->invoke( $operation );
	$assert( $low['memory_headroom'] <= 1048576 && $low['processing'] <= 131072 && $low['processing'] < $charged,
		'Real remaining PHP memory must constrain the larger configured work allowance.' );
	$e = new ReflectionClass( Backup_Exporter::class );
	$exporter = $e->newInstanceWithoutConstructor();
	foreach ( array( 'permissions' => $permissions, 'access' => new WPAI_Backup_Capacity_Access(),
		'database' => $database( $low['processing'], $low_native ), 'policy' => $low, 'budget' => min( $low['chunk'], $low['step_bytes'] ) ) as $name => $value ) {
		$e->getProperty( $name )->setValue( $exporter, $value );
	}
	$failed = false;
	try { $exporter->begin( array( 'source_roots' => array( 'installation' ), 'expected_plan_hash' => str_repeat( '0', 64 ),
		'quiescence_confirmed' => true, 'bootstrap_writers_confirmed' => true ) ); }
	catch ( RuntimeException $error ) { $failed = 'backup_metadata_capacity' === $error->getMessage(); }
	$assert( $failed && ! $low_native->materialized && 0 === $low_native->column_reads,
		'Low-memory export must reject during source planning before private capture reservation or effects.' );
} finally {
	ini_set( 'memory_limit', $original_memory );
}
echo "issue199-backup-capacity: {$checks} checks passed\n";
