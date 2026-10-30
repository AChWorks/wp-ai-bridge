<?php
/**
 * Prepared metadata/packet admission without arbitrary result materialization.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Database_Connection;
use WP_AI_Bridge\Support\Database_Write_Failure;

$reflection = new ReflectionClass( Database_Connection::class );
$connection = $reflection->newInstanceWithoutConstructor();
$connection->packet_bytes = 67108864;
$bound = $reflection->getMethod( 'native_sql_row_bound' );
$capacity = $reflection->getMethod( 'native_sql_capacity' );
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$assert( 1 === $bound->invoke( $connection, array( array( 'declared_bytes' => '1', 'type' => 3 ) ), false ), 'A small known scalar required the full native packet.' );
$assert( 0 === $bound->invoke( $connection, array(), false ), 'A known no-result statement allocated a native packet.' );
$assert( 0 === $bound->invoke( $connection, array( array( 'declared_bytes' => '0', 'type' => 6 ) ), false ), 'Native NULL was treated as unknown arbitrary bytes.' );
$assert( 67108864 === $bound->invoke( $connection, array( array( 'declared_bytes' => '0', 'type' => 253 ) ), false ), 'An unknown zero-width string was falsely proved empty.' );
$assert( 67108864 === $bound->invoke( $connection, array( array( 'declared_bytes' => '1', 'type' => 253 ) ), true ), 'Parameter-sensitive result metadata lost its native packet fallback.' );
$assert( 67108864 === $bound->invoke( $connection, array( array( 'declared_bytes' => '4294967295', 'type' => 252 ) ), false ), 'A declared long value exceeded the actual native packet admission ceiling.' );
$assert( 8388612 === $capacity->invoke( $connection, 1048576, 134217728, 1 ), 'Known tiny results were refused on common finite PHP memory.' );
try {
	$capacity->invoke( $connection, 1048576, 134217728, 67108864 );
	throw new RuntimeException( 'An unknown packet-sized row exceeded proven headroom.' );
} catch ( Database_Write_Failure $error ) {
	$assert( 'database_native_sql_resource_proof' === $error->reason && isset( $error->details['required_headroom'] ), 'Resource rejection lost its actionable native/PHP evidence.' );
}
try {
	$capacity->invoke( $connection, 1048576, 0, 1 );
	throw new RuntimeException( 'Unknown memory ceiling was treated as a proof.' );
} catch ( Database_Write_Failure $error ) {
	$assert( 'database_native_sql_resource_proof' === $error->reason, 'Unknown-headroom admission was not refused before dispatch.' );
}
echo "PASS: native SQL prepared metadata, packet fallback and finite PHP headroom ({$checks} checks).\n";
