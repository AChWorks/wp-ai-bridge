<?php
/**
 * Separate normal Core WPDB client for private COMMIT fault checkpoints.
 *
 * @package WP_AI_Bridge
 */

$input = json_decode( base64_decode( (string) getenv( 'WPAI199W_TRANSPORT_REQUEST' ), true ), true );
if ( ! is_array( $input ) || ! current_user_can( 'manage_options' ) ) {
	throw new RuntimeException( 'Invalid private transport worker assignment.' );
}
$result = wp_get_ability( 'wp-ai-bridge/database-write' )->execute( $input );
$summary = is_wp_error( $result )
	? array( 'error' => $result->get_error_code() )
	: array( 'status' => $result['status'] ?? null, 'historical' => $result['historical'] ?? null );
echo 'WPAI199W_TRANSPORT_RESULT=' . wp_json_encode( $summary ) . "\n";
