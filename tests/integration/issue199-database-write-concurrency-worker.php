<?php
/**
 * Independent WordPress native session for bounded database-write races.
 *
 * @package WP_AI_Bridge
 */

$directory = getenv( 'WPAI199W_RACE_DIRECTORY' );
$slot = getenv( 'WPAI199W_RACE_SLOT' );
$input = json_decode( base64_decode( (string) getenv( 'WPAI199W_RACE_REQUEST' ), true ), true );
if ( ! is_string( $directory ) || 1 !== preg_match( '/^wpai199w-race-[a-f0-9]{12}$/D', basename( $directory ) ) ||
	! in_array( $slot, array( 'one', 'two' ), true ) || ! is_array( $input ) || ! is_dir( $directory ) ) {
	throw new RuntimeException( 'Invalid private typed-write race assignment.' );
}
$ability = wp_get_ability( 'wp-ai-bridge/database-write' );
if ( ! $ability instanceof WP_Ability ) {
	throw new RuntimeException( 'Missing typed-write race Ability.' );
}
file_put_contents( $directory . '/' . $slot . '.ready', (string) $GLOBALS['wpdb']->get_var( 'SELECT CONNECTION_ID()' ) );
$peer = 'one' === $slot ? 'two' : 'one';
$deadline = microtime( true ) + 25;
while ( ! is_file( $directory . '/' . $peer . '.ready' ) ) {
	if ( microtime( true ) > $deadline ) {
		throw new RuntimeException( 'Typed-write race peer did not arrive.' );
	}
	usleep( 10000 );
}
echo "WPAI199W_RACE_ENTERED=1\n";
flush();
$result = $ability->execute( $input );
$summary = is_wp_error( $result )
	? array( 'error' => $result->get_error_code() )
	: array( 'status' => $result['status'] ?? null, 'historical' => $result['historical'] ?? null, 'operation_ref' => $result['operation_ref'] ?? null );
echo 'WPAI199W_RACE_RESULT=' . wp_json_encode( $summary ) . "\n";
