<?php
/**
 * Actual WordPress+Adapter bounded provider diagnostics (#165).
 *
 * Run ONLY in a disposable WordPress CI integration environment. No live
 * fixture is created, and no file source bytes are staged.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;

$checks = 0;
$assert = static function ( $test, $why ) use ( &$checks ) {
	++$checks;
	if ( ! $test ) {
		throw new RuntimeException( $why );
	}
};
$actor = get_current_user_id();
$assert( $actor > 0 && current_user_can( 'manage_options' ), 'Disposable integration requires a WordPress administrator.' );
$settings = new Settings();
$old      = get_option( Settings::OPTION_NAME, null );
$grant    = $settings->defaults();
$grant[ Settings::GROUP_SITE_READ ]     = 1;
$grant[ Settings::GROUP_BUILDER_WRITE ] = 1;
update_option( Settings::OPTION_NAME, $grant, false );
$auth = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
$auth->setValue( null, 'disposable-wp165-client' );
try {
	$transfer = wp_get_ability( 'wp-ai-bridge/private-transfer' );
	$read     = wp_get_ability( 'wp-ai-bridge/diagnostics-read' );
	$assert( $transfer instanceof WP_Ability && $read instanceof WP_Ability, 'Transfer or diagnostic Ability not registered through WordPress.' );
	$bad = $transfer->execute( array(
		'action'      => 'begin',
		'purpose'     => 'media',
		'total_bytes' => -1,
		'sha256'      => hash( 'sha256', '' ),
	) );
	$assert( is_wp_error( $bad ) && 'transfer_invalid' === $bad->get_error_code(), 'Authenticated invalid begin did not fail without creating a fixture.' );
	$diagnostic = $read->execute( array( 'limit' => 2 ) );
	$assert( ! is_wp_error( $diagnostic ) && count( $diagnostic['events'] ) > 0 &&
		'begin' === $diagnostic['events'][0]['operation'] &&
		'validation' === $diagnostic['events'][0]['phase'] &&
		'transfer_invalid' === $diagnostic['events'][0]['error_code'], 'The actual WordPress callback did not record sanitized begin failure.' );
	$assert( ! isset( $diagnostic['events'][0]['user_id'] ) &&
		! isset( $diagnostic['events'][0]['target_id'] ) &&
		16 === strlen( $diagnostic['events'][0]['event_ref'] ), 'Read-only Ability exposed identity or omitted correlation ref.' );
	$option = get_option( Mutation_Log::OPTION_NAME, array() );
	$assert( is_array( $option ) && count( $option ) <= 50 && ! str_contains( wp_json_encode( $option ), hash( 'sha256', '' ) ), 'Private input/hash was recorded in site Activity.' );
	$auth->setValue( null, '' );
	$denied = $read->execute( array( 'limit' => 1 ) );
	$assert( is_wp_error( $denied ), 'Unauthenticated client read diagnostic events.' );
	$auth->setValue( null, 'disposable-wp165-client' );
	$grant[ Settings::GROUP_SITE_READ ] = 0;
	update_option( Settings::OPTION_NAME, $grant, false );
	$assert( is_wp_error( $read->execute( array( 'limit' => 1 ) ) ), 'Revoked Site Read retained diagnostic access.' );
	echo "PASS: #165 {$checks} disposable real WordPress diagnostic callback, authorization, schema and redaction assertions.\n";
} finally {
	$auth->setValue( null, '' );
	if ( null === $old ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $old, false );
	}
}
