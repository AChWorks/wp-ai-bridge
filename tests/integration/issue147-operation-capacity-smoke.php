<?php
/**
 * Actual WordPress/MySQL test of administrator-adjusted generic receipt
 * admission over the historical 2048 lifetime budget.
 *
 * Runs only in the disposable WordPress integration container.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Create_Claim;
use WP_AI_Bridge\Support\Create_Claim_Store;
use WP_AI_Bridge\Support\Operation_Receipt_Limits;

$assertions = 0;
$assert     = static function ( $condition, $reason ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
};
wp_set_current_user( 1 );
$assert( current_user_can( 'edit_posts' ), 'Disposable integration administrator cannot create test posts.' );
$policy     = new Operation_Receipt_Limits();
$prior      = get_option( Operation_Receipt_Limits::OPTION_NAME, null );
$seeded     = array();
$created_id = 0;
$created_key = '';
global $wpdb;
try {
	$initial_count = Create_Claim_Store::count_domain( Create_Claim::DOMAIN_OPERATION );
	$assert( $initial_count < Create_Claim::MAX_CLAIMS, 'Fixture requires headroom before the historical default threshold.' );
	// Use the same SQL-native option table as production, but ONLY synthetic
	// test-owned identities and no pre-existing WordPress content.
	for ( $index = $initial_count; $index < 2050; ++$index ) {
		$name = Create_Claim::PREFIX . hash( 'sha256', 'issue147-real-db-fixture-' . $index );
		$value = wp_json_encode(
			array(
				'fingerprint'      => str_repeat( 'c', 64 ),
				'admission_domain' => Create_Claim::DOMAIN_OPERATION,
				'state'            => 'expired',
				'created_at'       => 1,
				'id'               => 0,
			)
		);
		$inserted = $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'off' ), array( '%s', '%s', '%s' ) );
		$assert( 1 === $inserted, 'Cannot insert unique disposable typed receipt fixture.' );
		$seeded[] = $name;
	}
	$assert( 2050 === Create_Claim_Store::count_domain( Create_Claim::DOMAIN_OPERATION ), 'Real database did not count typed receipts beyond the old 2048 threshold.' );
	update_option( Operation_Receipt_Limits::OPTION_NAME, 4096, false );
	$assert( 4096 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_OPERATION ), 'Raised site setting did not reach real create admission.' );
	$assert( 2048 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_CANONICAL ), 'Tuning generic receipts changed canonical Workspace admission.' );
	$call_count = 0;
	$allowed    = static function () {
		return current_user_can( 'edit_posts' );
	};
	$perform = static function ( $input ) use ( &$call_count, &$created_id ) {
		++$call_count;
		$created_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Issue 147 disposable receipt integration fixture' ), true );
		if ( is_wp_error( $created_id ) ) {
			return $created_id;
		}
		return array( 'id' => (int) $created_id );
	};
	$recover = static function ( $id ) {
		$post = get_post( $id );
		return $post && current_user_can( 'edit_post', $id ) ? array( 'id' => $id ) : null;
	};
	$input = array( 'action' => 'create', 'operation_id' => 'issue147-over-old-default', 'title' => 'Disposable receipt' );
	$created = Create_Claim::run( 'content-create', $input, $allowed, $perform, $recover );
	$assert( is_array( $created ) && $created['id'] > 0 && 1 === $call_count, 'Raised capacity did not admit exactly one real WordPress create.' );
	$created_key = Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( array( get_current_blog_id(), get_current_user_id(), (string) OAuth_Server::authenticated_mcp_client_id(), 'content-create', $input['operation_id'] ) ) );
	update_option( Operation_Receipt_Limits::OPTION_NAME, 128, false );
	$assert( 128 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_OPERATION ), 'Lowered policy ignored at real database admission.' );
	$recovered = Create_Claim::run( 'content-create', $input, $allowed, $perform, $recover );
	$denied = Create_Claim::run(
		'content-create',
		array( 'action' => 'create', 'operation_id' => 'issue147-new-denied-over-limit', 'title' => 'Should never exist' ),
		$allowed,
		$perform,
		$recover
	);
	$assert( is_array( $recovered ) && $recovered['id'] === $created['id'], 'Lowering policy broke authorized receipt recovery.' );
	$assert( is_wp_error( $denied ) && 'create_claim_capacity' === $denied->get_error_code() && 1 === $call_count, 'Lowering the policy did not block the next independent effect.' );
	$assert( 2051 === Create_Claim_Store::count_domain( Create_Claim::DOMAIN_OPERATION ), 'Real receipt count changed on denied readback/new create.' );
	echo "PASS: #147 real WordPress SQL, >2048 generic receipts, tuned admission, retry and lowering ({$assertions} assertions).\n";
} finally {
	if ( is_int( $created_id ) && $created_id > 0 ) {
		wp_delete_post( $created_id, true );
	}
	if ( '' !== $created_key ) {
		delete_option( $created_key );
	}
	if ( null === $prior ) {
		delete_option( Operation_Receipt_Limits::OPTION_NAME );
	} else {
		update_option( Operation_Receipt_Limits::OPTION_NAME, $prior, false );
	}
	// Every deleted identity was created here; never touch other Bridge receipts.
	foreach ( array_chunk( $seeded, 100 ) as $group ) {
		$placeholders = implode( ',', array_fill( 0, count( $group ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", ...$group ) );
	}
}
