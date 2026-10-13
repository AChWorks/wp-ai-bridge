<?php
/**
 * Deterministic claim/receipt/authorization regression, no WordPress bootstrap.
 *
 * @package WP_AI_Bridge
 */
define( 'DB_NAME', 'wpai_fake_schema' );
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Create_Claim;

function get_current_blog_id() {
	return (int) ( $GLOBALS['wpai_test']['blog_id'] ?? 1 ); }
function wp_cache_delete( $key, $group = '' ) {
	return true; }
// This deliberately mirrors WordPress's duplicate-key overwrite behavior:
// if production accidentally reuses add_option(), this test fails immediately.
function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	$GLOBALS['wpai_test']['legacy_add_option_calls'] = ( $GLOBALS['wpai_test']['legacy_add_option_calls'] ?? 0 ) + 1;
	$GLOBALS['wpai_test']['options'][ $name ]        = $value;
	return true;
}
class WPAI_Claim_Fake_DB {
	public $options    = 'wp_options';
	public $posts      = 'wp_posts';
	public $dbname     = 'wpai_fake_schema';
	public $last_error = '';
	public function esc_like( $input ) {
		return (string) $input; }
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
			$GLOBALS['wpai_test']['options'][ $name ]          = $parts[2];
			return 0;
		}
		if ( array_key_exists( $name, $GLOBALS['wpai_test']['options'] ) ) {
			return 0;
		}
		$GLOBALS['wpai_test']['options'][ $name ] = $parts[2];
		return 1;
	}
	public function get_var( $sql ) {
		if ( 0 === strpos( $sql, 'SELECT GET_LOCK(' ) ) {
			if ( preg_match( "/GET_LOCK\\('([^']+)'/D", $sql, $parts ) ) {
				$GLOBALS['wpai_test']['observed_lock_name'] = $parts[1];
			}
			return empty( $GLOBALS['wpai_test']['simulate_lock_failure'] ) ? 1 : 0;
		}
		if ( 0 === strpos( $sql, 'SELECT IS_USED_LOCK(' ) ||
			'SELECT CONNECTION_ID()' === $sql ) {
			return 45;
		}
		if ( 0 === strpos( $sql, 'SELECT RELEASE_LOCK(' ) ) {
			return 1;
		}
		if ( ! ( 0 === strpos( $sql, 'SELECT COUNT(*)' ) ) ) {
			throw new RuntimeException( 'Unexpected SQL in create claim count: ' . $sql );
		}
		if ( ! empty( $GLOBALS['wpai_test']['force_capacity_full'] ) ) {
			return Create_Claim::MAX_CLAIMS;
		}
		$domain = null;
		if ( false !== strpos( $sql, 'option_value LIKE' ) ) {
			foreach ( array( Create_Claim::DOMAIN_OPERATION, Create_Claim::DOMAIN_CANONICAL ) as $candidate ) {
				if ( false !== strpos( $sql, '"admission_domain":"' . $candidate . '"' ) ) {
					$domain = $candidate;
				}
			}
			if ( null === $domain ) {
				throw new RuntimeException( 'Invalid admission domain SQL: ' . $sql );
			}
		}
		return count(
			array_filter(
				$GLOBALS['wpai_test']['options'],
				static function ( $value, $name ) use ( $domain ) {
					if ( 0 !== strpos( $name, Create_Claim::PREFIX ) ) {
						return false;
					}
					if ( null === $domain ) {
						return true;
					}
					$state = json_decode( $value, true );
					return is_array( $state ) && ( $state['admission_domain'] ?? '' ) === $domain;
				},
				ARRAY_FILTER_USE_BOTH
			)
		);
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
$assertions      = 0;
function wpai128_assert( $ok, $why ) {
	global $assertions;
	++$assertions;
	if ( ! $ok ) {
		throw new RuntimeException( $why );
	}
}
function wpai128_create_input( $key = 'example-key-101', $content = 'one' ) {
	return array(
		'action'       => 'create',
		'post_type'    => 'post',
		'title'        => $content,
		'operation_id' => $key,
	);
}
wpai_test_reset_state();
$GLOBALS['wpai_test']['blog_id'] = 1;
$GLOBALS['wpai_test']['user_id'] = 1;
$writes                          = 0;
$allowed                         = true;
$objects                         = array();
$permission                      = static function () use ( &$allowed ) {
	return $allowed;
};
$perform                         = static function ( $input ) use ( &$writes, &$objects ) {
	++$writes;
	$result                   = array(
		'id'        => 100 + $writes,
		'post_type' => $input['post_type'] ?? 'post',
		'title'     => $input['title'],
	);
	$objects[ $result['id'] ] = $result;
	return $result;
};
$recover                         = static function ( $id ) use ( &$objects ) {
	return $objects[ $id ] ?? null;
};

$first = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( 101 === $first['id'] && 1 === $writes, 'First keyed create must execute exactly once.' );
$again = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( 101 === $again['id'] && 1 === $writes, 'Lost-response retry created a second object.' );
$conflict = Create_Claim::run( 'content-create', wpai128_create_input( 'example-key-101', 'other' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $conflict ) && 'create_claim_conflict' === $conflict->get_error_code() && 1 === $writes, 'Reused key with different intent was accepted.' );

$permission = static function () use ( &$allowed ) {
	return $allowed;
};
$allowed    = false;
$denied     = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $denied ) && 'create_claim_denied' === $denied->get_error_code(), 'Permission revocation disclosed receipt.' );
$allowed = true;

$GLOBALS['wpai_test']['blog_id'] = 2;
$second_site                     = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( 102 === $second_site['id'] && 2 === $writes, 'Different site identity collided.' );
$GLOBALS['wpai_test']['blog_id'] = 1;
$GLOBALS['wpai_test']['user_id'] = 2;
$second_principal                = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
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
$claim_name                                     = Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( array( 1, 1, '', 'content-create', 'example-key-101' ) ) );
$original_receipt                               = $GLOBALS['wpai_test']['options'][ $claim_name ];
$partial_state                                  = json_decode( $original_receipt, true );
$partial_state['state']                         = 'partial';
$GLOBALS['wpai_test']['options'][ $claim_name ] = wp_json_encode( $partial_state );
$partial_authorized                             = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $partial_authorized ) && 'create_claim_partial' === $partial_authorized->get_error_code(), 'Authenticated partial result lost its repair signal.' );
$objects[101]    = null;
$partial_missing = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $partial_missing ) && 'create_claim_outcome_unknown' === $partial_missing->get_error_code(), 'Deleted partial result leaked an unverifiable receipt.' );
$GLOBALS['wpai_test']['options'][ $claim_name ] = $original_receipt;
$missing                                        = Create_Claim::run( 'content-create', wpai128_create_input(), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $missing ) && 'create_claim_outcome_unknown' === $missing->get_error_code() && 5 === $writes, 'Deleted object was silently recreated on retry.' );

$throwing = static function () {
	throw new RuntimeException( 'simulated commit uncertainty' );
};
$unknown  = Create_Claim::run(
	'media-upload',
	array(
		'filename'     => 'a.png',
		'operation_id' => 'media-key-001',
	),
	$permission,
	$throwing,
	$recover
);
wpai128_assert( is_wp_error( $unknown ) && 'create_claim_outcome_unknown' === $unknown->get_error_code(), 'Thrown callback leaked error or lost unknown state.' );
$after_unknown = Create_Claim::run(
	'media-upload',
	array(
		'filename'     => 'a.png',
		'operation_id' => 'media-key-001',
	),
	$permission,
	$perform,
	$recover
);
wpai128_assert( is_wp_error( $after_unknown ) && 'create_claim_outcome_unknown' === $after_unknown->get_error_code() && 5 === $writes, 'Unknown persisted claim was replayed.' );
$canonical = Create_Claim::reserve_document_key( 'project-one', 'project-brief' );
wpai128_assert( is_array( $canonical ), 'First canonical scoped key not reserved.' );
$duplicate = Create_Claim::reserve_document_key( 'project-one', 'project-brief' );
wpai128_assert( is_wp_error( $duplicate ) && 'workspace_key_reserved' === $duplicate->get_error_code(), 'Concurrent canonical key was not excluded.' );
wpai128_assert( Create_Claim::commit_document_key( $canonical, 200 ), 'Durable canonical key receipt not committed.' );
wpai128_assert( is_wp_error( Create_Claim::reserve_document_key( 'project-one', 'project-brief' ) ), 'Committed document key was reused.' );
wpai128_assert( is_array( Create_Claim::reserve_document_key( 'project-two', 'project-brief' ) ), 'Two distinct projects incorrectly share one canonical key.' );
wpai128_assert( 0 === ( $GLOBALS['wpai_test']['legacy_add_option_calls'] ?? 0 ), 'Unsafe WordPress add_option upsert was invoked for a claim.' );
// Legacy untyped receipts remain authoritative for identity, yet thousands
// of unrelated historical keys must not strand a newly tuned Workspace.
$marker_generic_before   = \WP_AI_Bridge\Support\Create_Claim_Store::count_domain( Create_Claim::DOMAIN_OPERATION );
$marker_canonical_before = \WP_AI_Bridge\Support\Create_Claim_Store::count_domain( Create_Claim::DOMAIN_CANONICAL );
$historical              = array();
for ( $i = 0; $i < 2400; ++$i ) {
	$name                                     = Create_Claim::PREFIX . hash( 'sha256', 'legacy-generic-test-' . $i );
	$historical[]                             = $name;
	$GLOBALS['wpai_test']['options'][ $name ] = wp_json_encode(
		array(
			'fingerprint' => str_repeat( 'a', 64 ),
			'state'       => 'expired',
			'created_at'  => 1,
			'id'          => 0,
		)
	);
}
wpai128_assert(
	$marker_generic_before === \WP_AI_Bridge\Support\Create_Claim_Store::count_domain( Create_Claim::DOMAIN_OPERATION ) &&
	$marker_canonical_before === \WP_AI_Bridge\Support\Create_Claim_Store::count_domain( Create_Claim::DOMAIN_CANONICAL ),
	'Historical untyped receipts consumed a post-upgrade typed admission domain.'
);
$policy_key                                     = \WP_AI_Bridge\Support\Workspace_Limits::OPTION_NAME;
$GLOBALS['wpai_test']['options'][ $policy_key ] = array(
	'documents' => 2200,
	'tasks'     => 5100,
);
wpai128_assert(
	9500 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_CANONICAL ) &&
	2048 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_OPERATION ),
	'Workspace quota incorrectly changed generic receipt quota.'
);
$historical_canonical                                     = Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( array( 'canonical-document', 1, 'legacy-bound-project', 'source-brief' ) ) );
$GLOBALS['wpai_test']['options'][ $historical_canonical ] = wp_json_encode(
	array(
		'fingerprint' => str_repeat( '0', 64 ),
		'state'       => 'committed',
		'created_at'  => time(),
		'id'          => 321,
	)
);
wpai128_assert(
	is_wp_error( Create_Claim::reserve_document_key( 'legacy-bound-project', 'source-brief' ) ),
	'Legacy canonical document key became reusable after independent receipt policies.'
);
$large_canonical = Create_Claim::reserve_document_key( 'new-capacity-project', 'source-brief' );
wpai128_assert(
	is_array( $large_canonical ) && Create_Claim::commit_document_key( $large_canonical, 345 ),
	'Tuned canonical capacity was consumed by historical generic receipts.'
);
$GLOBALS['wpai_test']['options'][ $policy_key ] = array(
	'documents' => 200,
	'tasks'     => 200,
);
$generic_after_lower                            = Create_Claim::run(
	'content-create',
	wpai128_create_input( 'lowering-safe-operation' ),
	$permission,
	$perform,
	$recover
);
wpai128_assert(
	is_array( $generic_after_lower ) &&
	$marker_generic_before + 1 === \WP_AI_Bridge\Support\Create_Claim_Store::count_domain( Create_Claim::DOMAIN_OPERATION ),
	'Lowering Workspace policy blocked unrelated generic keyed creates.'
);
wpai128_assert(
	2048 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_OPERATION ),
	'Workspace lowering changed independent generic admission capacity.'
);
// Restore isolated fixture side effects; the admitted durable receipt remains.
unset( $objects[ $generic_after_lower['id'] ] );
--$writes;
foreach ( $historical as $name ) {
	unset( $GLOBALS['wpai_test']['options'][ $name ] );
}
unset( $GLOBALS['wpai_test']['options'][ $historical_canonical ], $GLOBALS['wpai_test']['options'][ $policy_key ] );

// Simulate a second worker winning between the optimistic existence read
// and our INSERT IGNORE. Only one worker may enter the effect callback.
$GLOBALS['wpai_test']['simulate_concurrent_claim'] = true;
$race = Create_Claim::run( 'content-create', wpai128_create_input( 'claim-race-128' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $race ) && 'create_claim_outcome_unknown' === $race->get_error_code() && 5 === $writes, 'Concurrent loser must never execute a second create.' );
wpai128_assert( 0 === ( $GLOBALS['wpai_test']['legacy_add_option_calls'] ?? 0 ), 'Race must never use Core duplicate-key overwrite.' );
$GLOBALS['wpai_test']['simulate_insert_error'] = true;
$storage                                       = Create_Claim::run( 'content-create', wpai128_create_input( 'claim-fail-128' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $storage ) && 'create_claim_outcome_unknown' === $storage->get_error_code() && 5 === $writes, 'Failed atomic storage must stop all effects.' );
$GLOBALS['wpai_test']['simulate_insert_error'] = false;
$GLOBALS['wpai_test']['force_capacity_full']   = true;
$full = Create_Claim::run( 'content-create', wpai128_create_input( 'capacity-limit-128' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $full ) && 'create_claim_capacity' === $full->get_error_code() && 5 === $writes, 'Saturated durable receipt store did not fail closed.' );
$GLOBALS['wpai_test']['force_capacity_full']   = false;
$GLOBALS['wpai_test']['simulate_lock_failure'] = true;
$busy = Create_Claim::run( 'content-create', wpai128_create_input( 'admission-lock-fail-128' ), $permission, $perform, $recover );
wpai128_assert(
	is_wp_error( $busy ) && 'create_claim_outcome_unknown' === $busy->get_error_code() &&
	5 === $writes,
	'Admission lock failure must prohibit creating an object.'
);
$canonical_busy = Create_Claim::reserve_document_key( 'locked-project', 'project-brief' );
wpai128_assert(
	is_wp_error( $canonical_busy ) && 'workspace_key_outcome_unknown' === $canonical_busy->get_error_code(),
	'Canonical key reservation must fail closed if admission coordination fails.'
);
$GLOBALS['wpai_test']['simulate_lock_failure'] = false;
// A fully known original result remains non-replayable after retention:
$old_key                                     = Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( array( 1, 1, '', 'content-create', 'example-key-102' ) ) );
$receipt                                     = json_decode( $GLOBALS['wpai_test']['options'][ $old_key ], true );
$receipt['created_at']                       = time() - Create_Claim::RECOVERY_SECONDS - 1;
$GLOBALS['wpai_test']['options'][ $old_key ] = wp_json_encode( $receipt );
$expired                                     = Create_Claim::run( 'content-create', wpai128_create_input( 'example-key-102' ), $permission, $perform, $recover );
wpai128_assert( is_wp_error( $expired ) && 'create_claim_expired' === $expired->get_error_code() && 5 === $writes, 'Expired operation key was silently replayed.' );
wpai128_assert( 'expired' === json_decode( $GLOBALS['wpai_test']['options'][ $old_key ], true )['state'], 'Expired claim was not compacted into a durable tombstone.' );
wpai128_assert(
	Create_Claim::DOMAIN_OPERATION === json_decode( $GLOBALS['wpai_test']['options'][ $old_key ], true )['admission_domain'],
	'An expired new receipt lost its domain marker and bypassed independent finite admission.'
);
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
	wpai128_assert(
		is_array( $client_a ) && is_array( $client_b ) &&
		$client_a['id'] !== $client_b['id'] && 7 === $writes,
		'Distinct authenticated clients shared one operation receipt.'
	);
	$client_property->setValue( null, 'client-A' );
	$client_a_again = Create_Claim::run( 'content-create', wpai128_create_input( 'client-isolation-128' ), $permission, $perform, $recover );
	wpai128_assert(
		is_array( $client_a_again ) && $client_a_again['id'] === $client_a['id'] &&
		7 === $writes,
		'Returning authenticated client did not recover its own object.'
	);
} finally {
	$client_property->setValue( null, $original_client );
}
// MySQL named-lock namespace is server-wide, not per WordPress schema.
// Two independent databases sharing the exact wp_ table names must NOT
// block each other's admission. Same DB/site/type MUST share the lock.
$lock_identity = new ReflectionMethod( \WP_AI_Bridge\Support\Admission_Lock::class, 'lock_name' );
$schema_a      = $lock_identity->invoke( null, 'claims', 'wp_options', 'wpai_site_a' );
$schema_b      = $lock_identity->invoke( null, 'claims', 'wp_options', 'wpai_site_b' );
wpai128_assert(
	is_string( $schema_a ) && '' !== $schema_a && $schema_a !== $schema_b,
	'Two independent schemas with identical wp_options names shared an admission lock.'
);
wpai128_assert(
	$schema_a === $lock_identity->invoke( null, 'claims', 'wp_options', 'wpai_site_a' ),
	'Same schema/site/scope did not derive a stable lock for horizontal nodes.'
);
wpai128_assert(
	$schema_a !== $lock_identity->invoke( null, 'claims', 'wp_2_options', 'wpai_site_a' ),
	'Secondary multisite blog incorrectly shared its primary blog admission lock.'
);
wpai128_assert(
	$schema_a !== $lock_identity->invoke( null, 'documents', 'wp_posts', 'wpai_site_a' ) &&
	$lock_identity->invoke( null, 'documents', 'wp_posts', 'wpai_site_a' ) !==
	$lock_identity->invoke( null, 'tasks', 'wp_posts', 'wpai_site_a' ),
	'Different admission scopes were not isolated.'
);
wpai128_assert(
	'' === $lock_identity->invoke( null, 'claims', 'wp_options', '' ) &&
	'' === $lock_identity->invoke( null, 'claims', 'wp_options', '   ' ) &&
	'' === $lock_identity->invoke( null, 'claims', '', 'wpai_site_a' ),
	'Unknown schema or table identity did not fail closed.'
);
$actual_lock_name = $lock_identity->invoke( null, 'claims', 'wp_options', DB_NAME );
wpai128_assert(
	$actual_lock_name === ( $GLOBALS['wpai_test']['observed_lock_name'] ?? '' ),
	'Real admission path did not use configured database plus exact table and scope.'
);
$GLOBALS['wpdb']->dbname = 'a_different_router_schema';
$before_mismatch_writes  = $writes;
$router_mismatch         = Create_Claim::run( 'content-create', wpai128_create_input( 'router-mismatch-128' ), $permission, $perform, $recover );
wpai128_assert(
	is_wp_error( $router_mismatch ) &&
	'create_claim_outcome_unknown' === $router_mismatch->get_error_code() &&
	$before_mismatch_writes === $writes,
	'Unexpected DB router schema admitted an unsafe create.'
);
$GLOBALS['wpdb']->dbname = DB_NAME;
echo "PASS: #128 durable keyed-create safety ({$assertions} assertions).\n";
