<?php
/**
 * Setup and verification of 2047/2048 real WordPress option claims.
 * Run only on the disposable integration database.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Create_Claim;
use WP_AI_Bridge\Support\Create_Claim_Store;
use WP_AI_Bridge\Workspace\Store;

$key    = getenv( 'WPAI_CAP_KEY' );
$mode   = getenv( 'WPAI_CAP_MODE' );
$action = getenv( 'WPAI_CAP_ACTION' );
if ( ! is_string( $key ) || ! preg_match( '/^cap-[a-z0-9-]{8,55}$/D', $key ) ||
	! in_array( $mode, array( 'operation', 'canonical' ), true ) ||
	! in_array( $action, array( 'setup', 'verify' ), true ) ) {
	throw new RuntimeException( 'Invalid isolated capacity-race setup.' );
}
$record_key = 'wpai128_test_seed_' . $key . '_' . $mode;
$domain     = 'operation' === $mode ? Create_Claim::DOMAIN_OPERATION : Create_Claim::DOMAIN_CANONICAL;
if ( Create_Claim::MAX_CLAIMS !== Create_Claim::receipt_limit( $domain ) ) {
	throw new RuntimeException( 'Historical default 2048 limit must remain available for the independent typed receipt race.' );
}
if ( 'setup' === $action ) {
	if ( false !== get_option( $record_key, false ) ) {
		throw new RuntimeException( 'Cannot reuse old capacity fixture state.' );
	}
	$before = Create_Claim_Store::count_domain( $domain );
	if ( $before >= Create_Claim::MAX_CLAIMS - 1 ) {
		throw new RuntimeException( 'Disposable DB has insufficient claim slots for fixture.' );
	}
	$seed_count = Create_Claim::MAX_CLAIMS - 1 - $before;
	$receipt    = wp_json_encode(
		array(
			'fingerprint'      => str_repeat( '0', 64 ),
			'admission_domain' => $domain,
			'state'            => 'in_progress',
			'created_at'       => time(),
			'id'               => 0,
		)
	);
	for ( $i = 0; $i < $seed_count; ++$i ) {
		$name = Create_Claim::PREFIX . hash( 'sha256', $key . '|' . $mode . '|seed|' . $i );
		if ( ! add_option( $name, $receipt, '', 'off' ) ) {
			throw new RuntimeException( 'Failed to provision one disposable test claim.' );
		}
	}
	if ( ! add_option( $record_key, (string) $seed_count, '', 'off' ) ||
		Create_Claim::MAX_CLAIMS - 1 !== Create_Claim_Store::count_domain( $domain ) ) {
		throw new RuntimeException( 'Did not reach exact 2047-claim admission boundary.' );
	}
	// Force an actual SQL admission-coordinator failure before the effect;
	// the site-wide claim total and native object count must remain unchanged.
	$failure_filter = static function ( $query ) {
		return 0 === strpos( $query, 'SELECT GET_LOCK(' ) && false !== strpos( $query, 'wpai_admit_' )
			? 'SELECT invalid_admission_lock_function(' : $query;
	};
	global $wpdb;
	$wpdb->suppress_errors( true );
	add_filter( 'query', $failure_filter );
	$performed = false;
	try {
		$denied = Create_Claim::run(
			'capacity-race-create',
			array(
				'title'        => 'Must never create',
				'operation_id' => $key . '-db-error',
			),
			static function () {
				return true; },
			static function () use ( &$performed ) {
				$performed = true;
				return array( 'id' => 123456 );
			},
			static function () {
				return null; }
		);
	} finally {
		remove_filter( 'query', $failure_filter );
		$wpdb->suppress_errors( false );
	}
	if ( ! is_wp_error( $denied ) || 'create_claim_outcome_unknown' !== $denied->get_error_code() ||
		$performed || Create_Claim::MAX_CLAIMS - 1 !== Create_Claim_Store::count_domain( $domain ) ) {
		throw new RuntimeException( 'Unavailable claim coordinator executed an effect or changed capacity.' );
	}
	echo "PASS: seeded exactly 2047 claims.\n";
	return;
}
if ( Create_Claim::MAX_CLAIMS !== Create_Claim_Store::count_domain( $domain ) ) {
	throw new RuntimeException( 'Concurrent creates exceeded or failed to fill the hard 2048-claim bound.' );
}
$matching = array();
foreach ( array( 'one', 'two' ) as $slot ) {
	$identity = 'operation' === $mode
		? array( get_current_blog_id(), get_current_user_id(), (string) OAuth_Server::authenticated_mcp_client_id(), 'capacity-race-create', $key . '-' . $slot )
		: array( 'canonical-document', get_current_blog_id(), $key . '-' . $slot, 'capacity-brief' );
	$name     = Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( $identity ) );
	$value    = get_option( $name, false );
	if ( is_string( $value ) && '' !== $value ) {
		$state = json_decode( $value, true );
		if ( ! is_array( $state ) || 'committed' !== ( $state['state'] ?? '' ) || empty( $state['id'] ) ) {
			throw new RuntimeException( 'Admission winner has no durable committed receipt.' );
		}
		$matching[] = array(
			'option' => $name,
			'id'     => (int) $state['id'],
		);
	}
}
if ( 1 !== count( $matching ) ) {
	throw new RuntimeException( 'Distinct operation names were admitted across the hard capacity bound.' );
}
$post = get_post( $matching[0]['id'] );
if ( ! $post || substr( $post->post_title, 0, strlen( 'WPAI128 cap ' . $key . ' ' ) ) !== 'WPAI128 cap ' . $key . ' ' ||
	( 'canonical' === $mode && Store::DOCUMENT_POST_TYPE !== $post->post_type ) ) {
	throw new RuntimeException( 'The unique allowed claim does not identify the created WordPress object.' );
}
wp_delete_post( $matching[0]['id'], true );
delete_option( $matching[0]['option'] );
$seed_count = (int) get_option( $record_key, -1 );
if ( $seed_count <= 0 ) {
	throw new RuntimeException( 'No trustworthy seed receipt to safely clean up.' );
}
for ( $i = 0; $i < $seed_count; ++$i ) {
	delete_option( Create_Claim::PREFIX . hash( 'sha256', $key . '|' . $mode . '|seed|' . $i ) );
}
delete_option( $record_key );
if ( Create_Claim_Store::count_domain( $domain ) !== Create_Claim::MAX_CLAIMS - 1 - $seed_count ) {
	throw new RuntimeException( 'Fixture cleanup changed the prior claim count.' );
}
echo "PASS: 2047->2048 distinct-key atomic admission, one WordPress effect; restored fixture DB.\n";
