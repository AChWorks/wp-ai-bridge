<?php
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Auth\OAuth_Store;

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$GLOBALS['wpnb_issue54_transients'] = array();

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value, $deprecated = '', $autoload = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress test stub signature.
		if ( array_key_exists( $name, $GLOBALS['wpnb_test']['options'] ) ) {
			return false;
		}
		$GLOBALS['wpnb_test']['options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['wpnb_test']['options'] ) ) {
			return false;
		}
		unset( $GLOBALS['wpnb_test']['options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $ttl = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress test stub signature.
		$GLOBALS['wpnb_issue54_transients'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		return array_key_exists( $name, $GLOBALS['wpnb_issue54_transients'] ) ? $GLOBALS['wpnb_issue54_transients'][ $name ] : false;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['wpnb_issue54_transients'] ) ) {
			return false;
		}
		unset( $GLOBALS['wpnb_issue54_transients'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress test stub signature.
		return 'issue54-test-salt';
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress test stub signature.
		return true;
	}
}

$failures = 0;
$tests    = 0;

function wpnb_issue54_store_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

wpnb_test_reset_state();
$store    = new OAuth_Store();
$client_a = 'https://gateway-a.example/oauth/client.json';
$client_b = 'https://gateway-b.example/oauth/client.json';

foreach ( array( OAuth_Store::TYPE_CODE, OAuth_Store::TYPE_REFRESH ) as $type ) {
	$artifact = $store->issue(
		$type,
		array(
			'client_id' => $client_a,
			'user_id'   => 1,
		),
		300
	);

	$store->set_authenticated_client( $client_b );
	wpnb_issue54_store_assert(
		false === $store->read( $type, $artifact, true ),
		'Another authenticated client must not consume a one-time OAuth artifact.'
	);

	$store->set_authenticated_client( $client_a );
	$claims = $store->read( $type, $artifact, true );
	wpnb_issue54_store_assert(
		is_array( $claims ) && $client_a === ( $claims['client_id'] ?? '' ),
		'The owning client must still be able to consume its artifact after a cross-client attempt.'
	);
	wpnb_issue54_store_assert(
		false === $store->read( $type, $artifact, true ),
		'A correctly consumed one-time OAuth artifact must not be reusable.'
	);
}

$explicit = $store->issue( OAuth_Store::TYPE_CODE, array( 'client_id' => $client_a ), 300 );
$store->set_authenticated_client( $client_a );
wpnb_issue54_store_assert(
	false === $store->read( OAuth_Store::TYPE_CODE, $explicit, true, $client_b ),
	'An explicit expected-client binding must override request-local authenticated state.'
);
wpnb_issue54_store_assert(
	is_array( $store->read( OAuth_Store::TYPE_CODE, $explicit, true, $client_a ) ),
	'An explicit matching client binding must consume the artifact.'
);

if ( $failures ) {
	fwrite( STDERR, "{$failures} of {$tests} Issue #54 OAuth store isolation assertions failed.\n" );
	exit( 1 );
}

echo "PASS: {$tests} Issue #54 OAuth store isolation assertions.\n";
