<?php
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Auth\OAuth_Store;

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$GLOBALS['wpai_issue54_transients']     = array();
$GLOBALS['wpai_issue54_option_autoload'] = array();
$GLOBALS['wpai_issue54_cleanup_events']  = array();

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value, $deprecated = '', $autoload = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress test stub signature.
		if ( ! empty( $GLOBALS['wpai_issue54_fail_refresh_add'] ) && 0 === strpos( $name, 'wpai_oauth_refresh_' ) ) {
			return false;
		}
		if ( array_key_exists( $name, $GLOBALS['wpai_test']['options'] ) ) {
			return false;
		}
		$GLOBALS['wpai_test']['options'][ $name ] = $value;
		$GLOBALS['wpai_issue54_option_autoload'][ $name ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['wpai_test']['options'] ) ) {
			return false;
		}
		unset( $GLOBALS['wpai_test']['options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $ttl = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress test stub signature.
		$GLOBALS['wpai_issue54_transients'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		return array_key_exists( $name, $GLOBALS['wpai_issue54_transients'] ) ? $GLOBALS['wpai_issue54_transients'][ $name ] : false;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['wpai_issue54_transients'] ) ) {
			return false;
		}
		unset( $GLOBALS['wpai_issue54_transients'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress test stub signature.
		return 'issue54-test-salt';
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
		$GLOBALS['wpai_issue54_cleanup_events'][] = array( $timestamp, $hook, $args );
		return true;
	}
}

$failures = 0;
$tests    = 0;

function wpai_issue54_store_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

wpai_test_reset_state();
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
	wpai_issue54_store_assert(
		false === $store->read( $type, $artifact, true ),
		'Another authenticated client must not consume a one-time OAuth artifact.'
	);

	$store->set_authenticated_client( $client_a );
	$claims = $store->read( $type, $artifact, true );
	wpai_issue54_store_assert(
		is_array( $claims ) && $client_a === ( $claims['client_id'] ?? '' ),
		'The owning client must still be able to consume its artifact after a cross-client attempt.'
	);
	wpai_issue54_store_assert(
		false === $store->read( $type, $artifact, true ),
		'A correctly consumed one-time OAuth artifact must not be reusable.'
	);
}

$explicit = $store->issue( OAuth_Store::TYPE_CODE, array( 'client_id' => $client_a ), 300 );
$store->set_authenticated_client( $client_a );
wpai_issue54_store_assert(
	false === $store->read( OAuth_Store::TYPE_CODE, $explicit, true, $client_b ),
	'An explicit expected-client binding must override request-local authenticated state.'
);
wpai_issue54_store_assert(
	is_array( $store->read( OAuth_Store::TYPE_CODE, $explicit, true, $client_a ) ),
	'An explicit matching client binding must consume the artifact.'
);

// Durable 30-day refresh grants must survive complete transient/cache loss.
$refresh_ttl   = 30 * 24 * 60 * 60;
$durable_token = $store->issue( OAuth_Store::TYPE_REFRESH, array( 'client_id' => $client_a, 'user_id' => 1 ), $refresh_ttl );
$durable_parts = explode( '.', $durable_token );
$durable_key   = 'wpai_oauth_refresh_' . $durable_parts[1];
$durable_record = get_option( $durable_key, false );
wpai_issue54_store_assert(
	is_array( $durable_record ) && $durable_record['expires_at'] >= time() + $refresh_ttl - 1,
	'The 30-day refresh grant was not durably stored with its original absolute expiry.'
);
wpai_issue54_store_assert(
	false === ( $GLOBALS['wpai_issue54_option_autoload'][ $durable_key ] ?? null ) && false === get_transient( $durable_key ),
	'Durable refresh claims must use non-autoloaded options, not transients.'
);
wpai_issue54_store_assert(
	false === strpos( serialize( $durable_record ), $durable_token ) && ! empty( $durable_record['secret_hash'] ),
	'The persistent refresh record leaked the bearer token or omitted the keyed secret hash.'
);
wpai_issue54_store_assert(
	count( array_filter( $GLOBALS['wpai_issue54_cleanup_events'], static function ( $entry ) use ( $durable_key ) {
		return 'wpai_oauth_cleanup_refresh_artifact' === $entry[1] && $durable_key === $entry[2][0];
	} ) ) === 1,
	'Durable refresh cleanup was not scheduled for the exact token.'
);
$GLOBALS['wpai_issue54_transients'] = array(); // Cache flush after a long idle period.
wpai_issue54_store_assert(
	false === $store->read( OAuth_Store::TYPE_REFRESH, $durable_token, false, $client_b ),
	'An incorrect client read a cache-independent refresh grant.'
);
wpai_issue54_store_assert(
	is_array( $store->read( OAuth_Store::TYPE_REFRESH, $durable_token, false, $client_a ) ),
	'The authorized 30-day refresh grant was lost after a full cache flush.'
);
wpai_issue54_store_assert(
	is_array( $store->read( OAuth_Store::TYPE_REFRESH, $durable_token, true, $client_a ) ) &&
	false === $store->read( OAuth_Store::TYPE_REFRESH, $durable_token, true, $client_a ) &&
	false === get_option( $durable_key, false ),
	'An authorized refresh grant was not consumed exactly once from durable storage.'
);

// A live pre-upgrade transient is promoted on the first use with identical
// token identity, expiry and client binding. An already-evicted one cannot be recovered.
$legacy_token  = $store->issue( OAuth_Store::TYPE_REFRESH, array( 'client_id' => $client_a ), $refresh_ttl );
$legacy_parts  = explode( '.', $legacy_token );
$legacy_key    = 'wpai_oauth_refresh_' . $legacy_parts[1];
$legacy_record = get_option( $legacy_key, false );
delete_option( $legacy_key );
set_transient( $legacy_key, $legacy_record, $refresh_ttl );
wpai_issue54_store_assert(
	is_array( $store->read( OAuth_Store::TYPE_REFRESH, $legacy_token, false, $client_a ) ) &&
	$legacy_record === get_option( $legacy_key, false ) && false === get_transient( $legacy_key ),
	'An existing valid transient-backed refresh grant failed to migrate to durable storage.'
);
$GLOBALS['wpai_issue54_transients'] = array();
wpai_issue54_store_assert(
	is_array( $store->read( OAuth_Store::TYPE_REFRESH, $legacy_token, true, $client_a ) ) &&
	false === $store->read( OAuth_Store::TYPE_REFRESH, $legacy_token, false, $client_a ),
	'A migrated grant was not one-shot after subsequent cache eviction.'
);

// Simulate an authorization still valid after nearly 30 days of inactivity.
$late_token  = $store->issue( OAuth_Store::TYPE_REFRESH, array( 'client_id' => $client_a ), $refresh_ttl );
$late_parts  = explode( '.', $late_token );
$late_key    = 'wpai_oauth_refresh_' . $late_parts[1];
$late_record = get_option( $late_key, false );
$late_record['expires_at'] = time() + 30;
$GLOBALS['wpai_test']['options'][ $late_key ] = $late_record;
$GLOBALS['wpai_issue54_transients'] = array();
wpai_issue54_store_assert(
	is_array( $store->read( OAuth_Store::TYPE_REFRESH, $late_token, false, $client_a ) ),
	'Late-life non-expired refresh grant was lost after simulated inactivity and cache loss.'
);
$late_record['expires_at'] = time() - 1;
$GLOBALS['wpai_test']['options'][ $late_key ] = $late_record;
wpai_issue54_store_assert(
	false === $store->read( OAuth_Store::TYPE_REFRESH, $late_token, false, $client_a ) &&
	false === get_option( $late_key, false ),
	'Expired durable refresh grant remained usable or stored after its absolute expiry.'
);

// Scheduled cleanup must never delete a live grant or an unrelated option.
$cleanup_token = $store->issue( OAuth_Store::TYPE_REFRESH, array( 'client_id' => $client_a ), $refresh_ttl );
$cleanup_parts = explode( '.', $cleanup_token );
$cleanup_key   = 'wpai_oauth_refresh_' . $cleanup_parts[1];
$cleanup_claims = get_option( $cleanup_key, false );
$store->cleanup_refresh_artifact( $cleanup_key, time() - 1 );
$store->cleanup_refresh_artifact( OAuth_Store::INSTANCE_OPTION, time() - 1 );
wpai_issue54_store_assert(
	is_array( get_option( $cleanup_key, false ) ) && false !== get_option( OAuth_Store::INSTANCE_OPTION, false ),
	'Cleanup removed an unexpired refresh grant or a non-grant option.'
);
$cleanup_claims['expires_at'] = time() - 10;
$GLOBALS['wpai_test']['options'][ $cleanup_key ] = $cleanup_claims;
$store->cleanup_refresh_artifact( $cleanup_key, $cleanup_claims['expires_at'] - 1 );
wpai_issue54_store_assert( is_array( get_option( $cleanup_key, false ) ), 'A mismatched cleanup deadline deleted a durable grant.' );
$store->cleanup_refresh_artifact( $cleanup_key, $cleanup_claims['expires_at'] );
wpai_issue54_store_assert( false === get_option( $cleanup_key, false ), 'Due cleanup failed to remove the expired refresh record.' );

// An unavailable durable backend must never mint a refresh bearer that was not stored.
$GLOBALS['wpai_issue54_fail_refresh_add'] = true;
$storage_result = $store->issue( OAuth_Store::TYPE_REFRESH, array( 'client_id' => $client_a ), $refresh_ttl );
$GLOBALS['wpai_issue54_fail_refresh_add'] = false;
wpai_issue54_store_assert(
	is_wp_error( $storage_result ) && 'oauth_refresh_storage_unavailable' === $storage_result->get_error_code(),
	'A durable storage failure returned an unusable refresh bearer.'
);

$replay_jti = 'issue54-max-window-replay';
$replay_key = 'wpai_oauth_assertion_' . substr( hash( 'sha256', $client_a . "\0" . $replay_jti ), 0, 40 );
$before     = time();
wpai_issue54_store_assert(
	$store->claim_client_assertion( $replay_jti, OAuth_Store::CLIENT_ASSERTION_REPLAY_TTL_CAP, $client_a ),
	'A saturated client-assertion replay claim was not created.'
);
$replay_expiry = (int) get_option( $replay_key, 0 );
$after         = time();
wpai_issue54_store_assert(
	$replay_expiry >= $before + OAuth_Store::CLIENT_ASSERTION_REPLAY_TTL_CAP + OAuth_Store::CLIENT_ASSERTION_REPLAY_SKEW,
	'A saturated replay claim expires before the maximum assertion acceptance window closes.'
);
wpai_issue54_store_assert(
	$replay_expiry <= $after + OAuth_Store::CLIENT_ASSERTION_REPLAY_TTL_CAP + OAuth_Store::CLIENT_ASSERTION_REPLAY_SKEW,
	'A saturated replay claim exceeded its bounded maximum retention window.'
);
wpai_issue54_store_assert(
	false === $store->claim_client_assertion( $replay_jti, OAuth_Store::CLIENT_ASSERTION_REPLAY_TTL_CAP, $client_a ),
	'A retained client-assertion replay claim was reclaimable.'
);

$legacy_jti    = 'issue54-pre-fix-replay-marker';
$legacy_key    = 'wpai_oauth_assertion_' . substr( hash( 'sha256', $client_a . "\0" . $legacy_jti ), 0, 40 );
$legacy_expiry = time() - 1;
$GLOBALS['wpai_test']['options'][ $legacy_key ] = $legacy_expiry;
wpai_issue54_store_assert(
	false === $store->claim_client_assertion( $legacy_jti, OAuth_Store::CLIENT_ASSERTION_REPLAY_TTL_CAP, $client_a ),
	'An expired pre-fix replay marker was reclaimed synchronously inside authentication.'
);
wpai_issue54_store_assert(
	$legacy_expiry === get_option( $legacy_key, false ),
	'Authentication deleted an existing pre-fix replay marker before scheduled cleanup owned its removal.'
);
$store->cleanup_client_assertion( $legacy_key, $legacy_expiry );
wpai_issue54_store_assert(
	false === get_option( $legacy_key, false ),
	'Scheduled replay cleanup did not remove the expired pre-fix marker.'
);
wpai_issue54_store_assert(
	$store->claim_client_assertion( $legacy_jti, OAuth_Store::CLIENT_ASSERTION_REPLAY_TTL_CAP, $client_a ),
	'A replay identifier remained blocked after its authoritative scheduled cleanup.'
);
delete_option( $legacy_key );

if ( $failures ) {
	fwrite( STDERR, "{$failures} of {$tests} Issue #54 OAuth store isolation assertions failed.\n" );
	exit( 1 );
}

echo "PASS: {$tests} Issue #54 OAuth store isolation assertions.\n";
