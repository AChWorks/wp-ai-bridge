<?php
/**
 * Finite DDL grammar and exact identifier/literal isolation.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Database_Schema_Definition;
use WP_AI_Bridge\Support\Database_Write_Failure;

class_exists( WP_AI_Bridge\Support\Database_Connection::class );
$checks = 0;
$assert = static function ( $value, $message ) use ( &$checks ) { ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } };
$compiler = new Database_Schema_Definition();
$tick = chr( 96 );
$base = array( 'action' => 'create_table', 'table' => 'wp_typed_fixture', 'expected_schema_hash' => null, 'columns' => array(
	array( 'name' => 'id', 'type' => 'bigint', 'nullable' => false, 'unsigned' => true, 'auto_increment' => true ),
	array( 'name' => 'quote' . $tick . "'column", 'type' => 'varchar', 'length' => 255, 'nullable' => true, 'default' => base64_encode( "literal'\\value" ) ),
), 'indexes' => array( array( 'name' => 'PRIMARY', 'kind' => 'primary', 'columns' => array( 'id' ) ) ) );
$sql = $compiler->compile( $base, 'fixture', 'wp_' );
$assert( 0 === strpos( $sql, 'CREATE TABLE ' ) && str_contains( $sql, 'ENGINE=InnoDB' ), 'Typed creation escaped its fixed persistent engine contract.' );
$assert( str_contains( $sql, 'quote' . $tick . $tick ) && str_contains( $sql, "literal''\\\\value" ), 'Identifier or literal quotes were not isolated.' );
$assert( str_contains( $sql, 'PRIMARY KEY' ) && ! str_contains( $sql, 'IF NOT EXISTS' ), 'Create silently adopted an existing object or lost its index.' );
$no_escapes = ( new Database_Schema_Definition( false ) )->compile( $base, 'fixture', 'wp_' );
$assert( str_contains( $no_escapes, "literal''\\value" ) && ! str_contains( $no_escapes, "literal''\\\\value" ), 'NO_BACKSLASH_ESCAPES changed literal bytes.' );
$hash = str_repeat( 'a', 64 );
foreach ( array(
	array( 'action' => 'drop_table', 'table' => 'wp_typed_fixture', 'expected_schema_hash' => $hash ),
	array( 'action' => 'truncate_table', 'table' => 'wp_typed_fixture', 'expected_schema_hash' => $hash ),
	array( 'action' => 'add_index', 'table' => 'wp_typed_fixture', 'expected_schema_hash' => $hash, 'index' => array( 'name' => 'i', 'kind' => 'index', 'columns' => array( array( 'name' => 'body', 'prefix' => 64 ) ) ) ),
	array( 'action' => 'rename_table', 'table' => 'wp_typed_fixture', 'expected_schema_hash' => $hash, 'new_name' => 'wp_destination', 'expected_destination_absent' => true ),
	array( 'action' => 'add_foreign_key', 'table' => 'wp_typed_fixture', 'expected_schema_hash' => $hash, 'foreign_key' => array( 'name' => 'fk', 'columns' => array( 'id' ), 'reference_table' => 'wp_parent', 'reference_columns' => array( 'id' ), 'on_delete' => 'RESTRICT' ) ),
) as $operation ) {
	$assert( is_string( $compiler->compile( $operation, 'fixture', 'wp_' ) ), 'Supported finite operation did not compile.' );
}
$bad = array(
	array_replace( $base, array( 'sql' => 'DROP DATABASE elsewhere' ) ),
	array_replace( $base, array( 'table' => 'another_installation' ) ),
	array_replace( $base, array( 'engine' => 'FEDERATED' ) ),
	array_replace( $base, array( 'data_directory' => '/outside' ) ),
	array_replace( $base, array( 'columns' => array( array( 'name' => 'x', 'type' => 'int) ; DROP TABLE x; --', 'nullable' => true ) ) ) ),
	array_replace( $base, array( 'columns' => array( array( 'name' => 'x', 'type' => 'int', 'nullable' => true, 'expression' => 'routine()' ) ) ) ),
	array_replace( $base, array( 'columns' => array( array( 'name' => 'x', 'type' => 'enum', 'nullable' => true, 'members' => array( 'not canonical base64' ) ) ) ) ),
	array( 'action' => 'drop_table', 'table' => 'wp_typed_fixture', 'expected_schema_hash' => null ),
);
foreach ( $bad as $operation ) {
	try {
		$compiler->compile( $operation, 'fixture', 'wp_' );
		throw new RuntimeException( 'Untrusted statement fragment or malformed definition was accepted.' );
	} catch ( Database_Write_Failure $error ) {
		$assert( 'database_schema_invalid' === $error->reason, 'Invalid grammar lost its precise rejection.' );
	}
}
echo "PASS: typed schema compilation and fragment/literal isolation ({$checks} checks).\n";
