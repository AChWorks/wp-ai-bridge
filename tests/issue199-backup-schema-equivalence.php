<?php
/**
 * Narrow native CHARACTER SET redundancy, with raw archive hash compatibility.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Backup_Schema;
use WP_AI_Bridge\Support\Backup_Exporter;
use WP_AI_Bridge\Support\Backup_Restore;

$checks = 0;
$assert = static function ( $value, $message ) use ( &$checks ) { ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } };
$error = static function ( $callback ) { try { $callback(); } catch ( Throwable $caught ) { return $caught->getMessage(); } return null; };
$source = "CREATE TABLE `wp_native_charset` (\n `id` bigint unsigned NOT NULL,\n " .
	"`note` longtext COLLATE utf8mb4_unicode_ci COMMENT 'CHARACTER SET latin1 AUTO_INCREMENT=9',\n " .
	"`label` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',\n " .
	"`choice` enum('CHARACTER SET latin1','COLLATE fake') COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n " .
	"PRIMARY KEY (`id`),KEY `label` (`label`(100))\n) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CHARACTER SET latin1'";
$target = str_replace( array( 'longtext COLLATE', 'varchar(200) COLLATE', "enum('CHARACTER SET latin1','COLLATE fake') COLLATE" ),
	array( 'longtext CHARACTER SET utf8mb4 COLLATE', 'varchar(200) CHARACTER SET utf8mb4 COLLATE',
		"enum('CHARACTER SET latin1','COLLATE fake') CHARACTER SET utf8mb4 COLLATE" ), $source );
$expected = Backup_Schema::blueprint( $source, 'wp_native_charset' );
$actual = Backup_Schema::blueprint( $target, 'wp_native_charset' );
$assert( hash( 'sha256', $source ) !== hash( 'sha256', $target ) &&
	Backup_Schema::equivalent( $expected, $actual ) && Backup_Schema::equivalent( $actual, $expected ),
	'Native redundant charset declarations with the same explicit collation must compare equally without changing their raw hashes.' );
$assert( Backup_Schema::equivalent( $expected, $expected ), 'An exact native blueprint must retain its ordinary comparison path.' );
foreach ( array(
	str_replace( 'longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'longtext CHARACTER SET latin1 COLLATE latin1_swedish_ci', $target ),
	str_replace( 'longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin', $target ),
	str_replace( 'longtext CHARACTER SET utf8mb4', 'mediumtext CHARACTER SET utf8mb4', $target ),
	str_replace( 'varchar(200)', 'varchar(201)', $target ),
	str_replace( "DEFAULT 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'", "DEFAULT 'CHARACTER SET latin1 COLLATE utf8mb4_unicode_ci'", $target ),
	str_replace( "COMMENT 'CHARACTER SET latin1 AUTO_INCREMENT=9'", "COMMENT 'CHARACTER SET latin1 AUTO_INCREMENT=10'", $target ),
	str_replace( "'COLLATE fake'", "'COLLATE changed'", $target ),
	str_replace( 'AUTO_INCREMENT=42', 'AUTO_INCREMENT=43', $target ),
	str_replace( "COMMENT='CHARACTER SET latin1'", "COMMENT='CHARACTER SET utf8mb4'", $target ),
	str_replace( 'KEY `label` (`label`(100))', 'KEY `label` (`label`(101))', $target ),
	str_replace( 'COLLATE=utf8mb4_unicode_ci', 'COLLATE=utf8mb4_bin', $target ),
	str_replace( 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT', 'CHARACTER SET utf8mb4 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT', $target ),
) as $different ) {
	$assert( ! Backup_Schema::equivalent( $expected, Backup_Schema::blueprint( $different, 'wp_native_charset' ) ),
		'Real charset/collation/type/width/default/comment/enum/index/table-counter changes or duplicate clauses must remain unequal.' );
}
$without_collate = 'CREATE TABLE `wp_plain` (`text` longtext DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$plain_explicit = str_replace( 'longtext DEFAULT', 'longtext CHARACTER SET utf8mb4 DEFAULT', $without_collate );
$assert( ! Backup_Schema::equivalent( Backup_Schema::blueprint( $without_collate, 'wp_plain' ), Backup_Schema::blueprint( $plain_explicit, 'wp_plain' ) ),
	'The exception must not generalize to columns without the explicit unchanged COLLATE evidence.' );
$quoted_name = "CREATE TABLE `wp_quoted_charset` (`CHARACTER SET utf8mb4 COLLATE x` longtext COLLATE utf8mb4_unicode_ci COMMENT 'one \\' quote') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$quoted_actual = str_replace( 'longtext COLLATE', 'longtext CHARACTER SET utf8mb4 COLLATE', $quoted_name );
$assert( Backup_Schema::equivalent( Backup_Schema::blueprint( $quoted_name, 'wp_quoted_charset' ), Backup_Schema::blueprint( $quoted_actual, 'wp_quoted_charset' ) ),
	'Quoted identifier/literal contents must remain opaque to the clause comparison.' );

$packed = array( 'table' => base64_encode( 'wp_native_charset' ), 'ddl' => base64_encode( $source ), 'hash' => hash( 'sha256', $source ),
	'columns' => array_map( static function ( $column ) { $column['name'] = base64_encode( $column['name'] ); return $column; }, $expected['column_metadata'] ) );
$unpacked = Backup_Exporter::unpack_schema( $packed );
$assert( hash( 'sha256', $source ) === $unpacked['hash'] && $source === $unpacked['ddl'], 'V1 import must retain the exact original DDL and raw schema hash.' );
$wrong = $packed; $wrong['ddl'] = base64_encode( $target );
$assert( 'backup_schema_integrity' === $error( static function () use ( $wrong ) { Backup_Exporter::unpack_schema( $wrong ); } ),
	'Semantically equivalent re-framing must not bypass the original immutable imported DDL hash.' );

final class WPAI_Backup_Equivalent_Database {
	public $schema;
	public function assert_session( $expected ) { return true; }
	public function schema( $table, $database ) { return $this->schema; }
	public function next_group( $schema, $after, $database ) { return null; }
}
$native = new WPAI_Backup_Equivalent_Database();
$native->schema = array( 'hash' => hash( 'sha256', $target ), 'blueprint' => $actual );
$r = new ReflectionClass( Backup_Restore::class ); $restore = $r->newInstanceWithoutConstructor();
$r->getProperty( 'database' )->setValue( $restore, $native );
$verify = $r->getMethod( 'verify_database' );
$table = $packed['table']; $seed = hash( 'sha256', 'wpai-backup-database-v1' );
$original_digest = hash( 'sha256', $seed . ':table:' . $table . ':' . $packed['hash'] );
$initial = array( 'header' => array( 'plan' => array( 'tables' => array( $table ), 'database' => array( 'session' => array( 'sql_mode' => '', 'time_zone' => '+00:00' ) ) ) ),
	'schemas' => array( $table => $packed ), 'target_schema' => 'isolated_target', 'verify_table' => 0, 'verify_after' => '',
	'verify_db' => $seed, 'verify_schema_started' => false, 'footer' => array( 'database_digest' => $original_digest ) );
$state = $initial; $args = array( &$state ); $verify->invokeArgs( $restore, $args );
$assert( 1 === $state['verify_table'] && $original_digest === $state['verify_db'],
	'Actual restore schema verification must accept only the proved equivalent native form and retain the archived hash in its DB digest.' );
$args = array( &$state ); $verify->invokeArgs( $restore, $args );
$assert( 'verify_files' === $state['phase'], 'The original V1 footer digest must still verify after native equivalent-DDL comparison.' );
$different = str_replace( 'varchar(200)', 'varchar(201)', $target );
$native->schema = array( 'hash' => hash( 'sha256', $different ), 'blueprint' => Backup_Schema::blueprint( $different, 'wp_native_charset' ) );
$state = $initial; $args = array( &$state );
$assert( 'backup_restore_schema_mismatch' === $error( static function () use ( $verify, $restore, &$args ) { $verify->invokeArgs( $restore, $args ); } ) &&
	$seed === $state['verify_db'] && ! $state['verify_schema_started'], 'Material target schema changes must still stop before advancing verification evidence.' );
echo "issue199-backup-schema-equivalence: {$checks} checks passed\n";
