<?php
/**
 * Deterministic claim/receipt/authorization regression, no WordPress bootstrap.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Create_Claim;

function get_current_blog_id() { return (int) ( $GLOBALS['wpai_test']['blog_id'] ?? 1 ); }
function wp_cache_delete( $key, $group = '' ) { return true; }
// This deliberately mirrors WordPress's duplicate-key overwrite behavior:
// if production accidentally reuses add_option(), this test fails immediately.
function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	$GLOBALS['wpai_test']['legacy_add_option_calls'] = ( $GLOBALS['wpai_test']['legacy_add_option_calls'] ?? 0 ) + 1;
	$GLOBALS['wpai_test']['options'][ $name ] = $value;
	return true;
}
class WPAI_Claim_Fake_DB {
	public $options = 'wp_options';
	public function esc_like( $input ) { return (string) $input; }
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $arg ) . "'", $sql, 1 );
		}
		return $sql;
	}
	public function query( $sql ) {
		if ( ! preg_match( "/^INSERT IGNORE INTO wp_options .* VALUES \\('([^']+)', '([^']+)', 'off'\\)$/D", $sql, $parts ) ) {
			throw new RuntimeException( 'Unexpected SQL in claim insert: ' . $sql );
		}
		$name = $parts[1];
		if ( ! empty( $GLOBALS['wpai_test']['simulate_insert_error'] ) ) {
			return false;
		}
		if ( ! empty( $GLOBALS['wpai_test']['simulate_concurrent_claim'] ) ) {
			// Other worker won the unique key between get_option and insert.
			$GLOBALS['wpai_test']['simulate_concurrent_claim'] = false;
			$GLOBALS['wpai_test']['options'][ $name ] = $parts[2];
			return 0;
		}
		if ( array_key_exists( $name, $GLOBALS['wpai_test']['options'] ) ) {
			return 0;
		}
		$GLOBALS['wpai_test']['options'][ $name ] = $parts[2];
		return 1;
	}
	public function get_var( $sql ) {
		if ( ! empty( $GLOBALS['wpai_test']['force_capacity_full'] ) ) {
			return Create_Claim::MAX_CLAIMS;
		}
		return count( array_filter( array_keys( $GLOBALS['wpai_test']['options'] ), static function ( $name ) {
			return 0 === strpos( $name, Create_Claim::PREFIX );
		} ) );
	}
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$name = $where['option_name'];
		if ( ! isset( $GLOBALS['wpai_test']['options'][ $name ] ) ||
			$GLOBALS['wpai_test']['options'][ $name ] !== $where['option_value'] ) {
			return 0;
		}
		$GLOBALS['wpai_test']['options'][ $name ] = $data['option_value'];
		return 1;
	}
}
$GLOBALS['wpdb'] = new WPAI_Claim_Fake_DB();
$assertions = 0;
function wpai128_assert( $ok, $why ) {
	global $assertions;
	++$assertions;
	if ( ! $ok ) {
		throw new RuntimeException( $why );
	}
}
function wpai128_create_input( $key = 'example-key-101', $content = 'one' ) {
	return array( 'action' => 'create', 'post_type' => 'post', 'title' => $content, 'operation_id' => $key );
}
wpai_test_reset_state();
$GLOBALS['wpai_test']['blog_id'] = 1;
$GLOBALS['wpai_test']['user_id'] = 1;
$writes = 0;
$allowed = true;
$objects = array();
$permission = static function () use ( &$allowed ) { return $allowed; };
$perform = static function ( $input ) use ( &$writes, &$objects ) {
	++$writes;
	$result = array( 'id' => 100 + $writes, 'post_type' => $input['post_type'] ?? 'post', 'title' => $input['title'] );
	$objects[ $result['id'] ] = $result;
	return $result;
};
$recover = static function ( $id ) use ( &$objects ) { return $objects[ $id ] ?? null; };

$first = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( 101 === $first['id'] && 1 === $writes, 'First keyed create must execute exactly once.' );
$again = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( 101 === $again['id'] && 1 === $writes, 'Lost-response retry created a second object.' );
$conflict = Create_Claim::run( 'content-create', wpai128_create_input( 'example-key-101', 'other' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $conflict ) && 'create_claim_conflict' === $conflict->get_error_code() && 1 === $writes, 'Reused key with different intent was accepted.' );

$permission = static function () use ( &$allowed ) { return $allowed; };
$allowed = false;
$denied = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $denied ) && 'create_claim_denied' === $denied->get_error_code(), 'Permission revocation disclosed receipt.' );
$allowed = true;

$GLOBALS['wpai_test']['blog_id'] = 2;
$second_site = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( 102 === $second_site['id'] && 2 === $writes, 'Different site identity collided.' );
$GLOBALS['wpai_test']['blog_id'] = 1;
$GLOBALS['wpai_test']['user_id'] = 2;
$second_principal = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( 103 === $second_principal['id'] && 3 === $writes, 'Different current WordPress principal collided.' );
$GLOBALS['wpai_test']['user_id'] = 1;

$second_intent = Create_Claim::run( 'content-create', wpai128_create_input( 'example-key-102' ), $permission, $perform, $recover );
wpai128_assert( 104 === $second_intent['id'] && 4 === $writes, 'Different stable key should allow an intentional duplicate title.' );
$no_key = Create_Claim::run( 'content-create', array( 'title' => 'legacy' ), $permission, $perform, $recover );
wpai128_assert( 105 === $no_key['id'] && 5 === $writes, 'Legacy keyless request changed.' );

$bad = Create_Claim::run( 'content-create', wpai128_create_input( 'x' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $bad ) && 'create_claim_invalid' === $bad->get_error_code(), 'Malformed operation_id was accepted.' );
// A partial post-commit receipt must not disclose a private object ID after
// the actual target disappears or the readback resolves to another object.
$claim_name = Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( array( 1, 1, '', 'content-create', 'example-key-101' ) ) );
$original_receipt = $GLOBALS['wpai_test']['options'][ $claim_name ];
$partial_state = json_decode( $original_receipt, true );
$partial_state['state'] = 'partial';
$GLOBALS['wpai_test']['options'][ $claim_name ] = wp_json_encode( $partial_state );
$partial_authorized = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $partial_authorized ) && 'create_claim_partial' === $partial_authorized->get_error_code(), 'Authenticated partial result lost its repair signal.' );
$objects[101] = null;
$partial_missing = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $partial_missing ) && 'create_claim_outcome_unknown' === $partial_missing->get_error_code(), 'Deleted partial result leaked an unverifiable receipt.' );
$GLOBALS['wpai_test']['options'][ $claim_name ] = $original_receipt;
$missing = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $missing ) && 'create_claim_outcome_unknown' === $missing->get_error_code() && 5 === $writes, 'Deleted object was silently recreated on retry.' );

$throwing = static function () { throw new RuntimeException( 'simulated commit uncertainty' ); };
$unknown = Create_Claim::run( 'media-upload', array( 'filename' => 'a.png', 'operation_id' => 'media-key-001' ), $permission, $throwing, $recover );
wpai128_assert( is_wp_error( $unknown ) && 'create_claim_outcome_unknown' === $unknown->get_error_code(), 'Thrown callback leaked error or lost unknown state.' );
$after_unknown = Create_Claim::run( 'media-upload', array( 'filename' => 'a.png', 'operation_id' => 'media-key-001' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $after_unknown ) && 'create_claim_outcome_unknown' === $after_unknown->get_error_code() && 5 === $writes, 'Unknown persisted claim was replayed.' );
$canonical = Create_Claim::reserve_document_key( 'project-one', 'project-brief' );
wpai128_assert( is_array( $canonical ), 'First canonical scoped key not reserved.' );
$duplicate = Create_Claim::reserve_document_key( 'project-one', 'project-brief' );
wpai128_assert( is_wp_error( $duplicate ) && 'workspace_key_reserved' === $duplicate->get_error_code(), 'Concurrent canonical key was not excluded.' );
wpai128_assert( Create_Claim::commit_document_key( $canonical, 200 ), 'Durable canonical key receipt not committed.' );
wpai128_assert( is_wp_error( Create_Claim::reserve_document_key( 'project-one', 'project-brief' ) ), 'Committed document key was reused.' );
wpai128_assert( is_array( Create_Claim::reserve_document_key( 'project-two', 'project-brief' ) ), 'Two distinct projects incorrectly share one canonical key.' );
wpai128_assert( 0 === ( $GLOBALS['wpai_test']['legacy_add_option_calls'] ?? 0 ), 'Unsafe WordPress add_option upsert was invoked for a claim.' );
// Simulate a second worker winning between the optimistic existence read
// and our INSERT IGNORE. Only one worker may enter the effect callback.
$GLOBALS['wpai_test']['simulate_concurrent_claim'] = true;
$race = Create_Claim::run( 'content-create', wpai128_create_input( 'claim-race-128' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $race ) && 'create_claim_outcome_unknown' === $race->get_error_code() && 5 === $writes, 'Concurrent loser must never execute a second create.' );
wpai128_assert( 0 === ( $GLOBALS['wpai_test']['legacy_add_option_calls'] ?? 0 ), 'Race must never use Core duplicate-key overwrite.' );
$GLOBALS['wpai_test']['simulate_insert_error'] = true;
$storage = Create_Claim::run( 'content-create', wpai128_create_input( 'claim-fail-128' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $storage ) && 'create_claim_outcome_unknown' === $storage->get_error_code() && 5 === $writes, 'Failed atomic storage must stop all effects.' );
$GLOBALS['wpai_test']['simulate_insert_error'] = false;
$GLOBALS['wpai_test']['force_capacity_full'] = true;
$full = Create_Claim::run( 'content-create', wpai128_create_input( 'capacity-limit-128' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $full ) && 'create_claim_capacity' === $full->get_error_code() && 5 === $writes, 'Saturated durable receipt store did not fail closed.' );
$GLOBALS['wpai_test']['force_capacity_full'] = false;
// A fully known original result remains non-replayable after retention:
$old_key = Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( array( 1, 1, '', 'content-create', 'example-key-102' ) ) );
$receipt = json_decode( $GLOBALS['wpai_test']['options'][ $old_key ], true );
$receipt['created_at'] = time() - Create_Claim::RECOVERY_SECONDS - 1;
$GLOBALS['wpai_test']['options'][ $old_key ] = wp_json_encode( $receipt );
$expired = Create_Claim::run( 'content-create', wpai128_create_input( 'example-key-102' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $expired ) && 'create_claim_expired' === $expired->get_error_code() && 5 === $writes, 'Expired operation key was silently replayed.' );
wpai128_assert( 'expired' === json_decode( $GLOBALS['wpai_test']['options'][ $old_key ], true )['state'], 'Expired claim was not compacted into a durable tombstone.' );
wpai128_assert( 5 === $writes, 'Validation/recovery unexpectedly repeated a create.' );
// Only the current authenticated OAuth client binds an operation receipt.
// Two distinct clients using the same textual ID cannot adopt each other.
$client_property = new ReflectionProperty( \WP_AI_Bridge\Auth\OAuth_Server::class, 'authenticated_mcp_client_id' );
$original_client = $client_property->getValue();
try {
	$client_property->setValue( null, 'client-A' );
	$client_a = Create_Claim::run( 'content-create', wpai128_create_input( 'client-isolation-128' ), $permission, $perform, $recover );
	$client_property->setValue( null, 'client-B' );
	$client_b = Create_Claim::run( 'content-create', wpai128_create_input( 'client-isolation-128' ), $permission, $perform, $recover );
	wpai128_assert( is_array( $client_a ) && is_array( $client_b ) &&
		$client_a['id'] !== $client_b['id'] && 7 === $writes,
		'Distinct authenticated clients shared one operation receipt.' );
	$client_property->setValue( null, 'client-A' );
	$client_a_again = Create_Claim::run( 'content-create', wpai128_create_input( 'client-isolation-128' ), $permission, $perform, $recover );
	wpai128_assert( is_array( $client_a_again ) && $client_a_again['id'] === $client_a['id'] &&
		7 === $writes, 'Returning authenticated client did not recover its own object.' );
} finally {
	$client_property->setValue( null, $original_client );
}
echo "PASS: #128 durable keyed-create safety ({$assertions} assertions).\n";
