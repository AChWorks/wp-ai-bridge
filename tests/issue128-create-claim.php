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
function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['wpai_test']['options'] ) ) {
		return false;
	}
	$GLOBALS['wpai_test']['options'][ $name ] = $value;
	return true;
}
class WPAI_Claim_Fake_DB {
	public $options = 'wp_options';
	public function esc_like( $input ) { return (string) $input; }
	public function prepare( $sql, $arg ) { return str_replace( '%s', "'" . $arg . "'", $sql ); }
	public function get_var( $sql ) {
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
$objects[101] = null;
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
wpai128_assert( 5 === $writes, 'Validation/recovery unexpectedly repeated a create.' );
echo "PASS: #128 durable keyed-create safety ({$assertions} assertions).\n";
