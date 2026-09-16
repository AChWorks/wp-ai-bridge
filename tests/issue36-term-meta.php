<?php
/** Focused term-meta contract checks. Real WordPress tests own capability and CAS proofs. */
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Term_Meta_Abilities;
use WP_Native_Builder_Bridge\Support\Metadata_Key_Policy;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Native_Builder_Bridge\Support\Term_Meta_Store;

define( 'ARRAY_A', 'ARRAY_A' );
$assertions = 0;
function wpnb36_assert( $condition, $message ) { global $assertions; ++$assertions; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function get_taxonomy( $name ) { return in_array( $name, array( 'fixture', 'other' ), true ) ? (object) array( 'name' => $name ) : false; }
function get_term( $id, $taxonomy = '' ) { return 101 === $id && in_array( $taxonomy, array( '', 'fixture' ), true ) ? (object) array( 'term_id' => 101, 'taxonomy' => 'fixture', 'term_taxonomy_id' => 201 ) : null; }
function get_object_subtype( $type, $id ) { return 'term' === $type && 101 === $id ? 'fixture' : ''; }
function wp_term_is_shared( $id ) { return ! empty( $GLOBALS['wpnb36_shared'] ); }
function is_protected_meta( $key, $type ) { return str_starts_with( $key, '_' ); }
function get_registered_meta_keys( $type, $subtype = '' ) { return $GLOBALS['wpnb36_registered'] ?? array(); }
function has_filter( $hook, $callback = false ) { return $GLOBALS['wpnb36_filters'][ $hook ] ?? false; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['wpnb36_filters'][ $hook ] = $priority; }
function remove_filter( $hook, $callback, $priority = 10 ) { unset( $GLOBALS['wpnb36_filters'][ $hook ] ); }
function map_meta_cap( $cap, $user, ...$args ) { return $GLOBALS['wpnb36_mapped'] ?? array( 'read' ); }
function maybe_serialize( $data ) { return is_array( $data ) || is_object( $data ) || is_serialized( $data ) ? serialize( $data ) : $data; }
function is_serialized( $data ) { return is_string( $data ) && ( 'N;' === $data || 1 === preg_match( '/^[aOEbidsCR]:/', $data ) ); }
function sanitize_meta( $key, $value, $type, $subtype = '' ) { return isset( $GLOBALS['wpnb36_sanitizer'] ) ? $GLOBALS['wpnb36_sanitizer']( $value ) : $value; }

final class WPNB36_Physical_Read_Fixture {
    public $termmeta = 'wp_termmeta';
    public $last_error = '';
    public $data = array();
    public $queries = array();
    public function prepare( $query, ...$args ) { return array( $query, $args ); }
    public function get_results( $prepared, $output ) {
        list( $query, $args ) = $prepared;
        $this->queries[] = $query;
        $rows = array_values( array_filter( $this->data, static fn( $row ) => (int) $row['term_id'] === $args[1] ) );
        if ( str_starts_with( $query, 'SELECT MIN' ) ) {
            $counts = array();
            foreach ( $rows as $row ) { $counts[ $row['meta_key'] ] = ( $counts[ $row['meta_key'] ] ?? 0 ) + 1; }
            ksort( $counts, SORT_STRING );
            $result = array();
            foreach ( $counts as $key => $count ) { $result[] = array( 'meta_key' => (string) $key, 'row_count' => $count ); }
            return array_slice( $result, $args[3], $args[2] );
        }
        $rows = array_values( array_filter( $rows, static fn( $row ) => $row['meta_key'] === $args[2] ) );
        usort( $rows, static fn( $a, $b ) => $a['meta_id'] <=> $b['meta_id'] );
        return array_map( static function ( $row ) { $row['value_bytes'] = null === $row['meta_value'] ? null : strlen( $row['meta_value'] ); if ( $row['value_bytes'] > 1048576 ) { $row['meta_value'] = null; } return $row; }, array_slice( $rows, 0, 2 ) );
    }
}
$GLOBALS['wpdb'] = new WPNB36_Physical_Read_Fixture();
$wpdb = $GLOBALS['wpdb'];
$put = static function ( $key, $value, $id = null ) use ( $wpdb ) { $wpdb->data[] = array( 'meta_id' => $id ?? count( $wpdb->data ) + 1, 'term_id' => 101, 'meta_key' => $key, 'meta_value' => $value ); };
$settings = new Settings();
$abilities = new Term_Meta_Abilities( new Permissions( $settings ), new Mutation_Log() );
$abilities->register();
foreach ( array( 'read', 'update', 'delete' ) as $operation ) {
    $schema = $GLOBALS['wpai_test']['registered_abilities'][ 'wp-ai-bridge/term-meta-' . $operation ]['input_schema'];
    wpnb36_assert( false === $schema['additionalProperties'] && in_array( 'taxonomy', $schema['required'], true ) && in_array( 'term_id', $schema['required'], true ), 'Closed exact target schema missing.' );
}
$base = array( 'term_id' => 101, 'taxonomy' => 'fixture' );
$key_input = $base + array( 'key' => 'ordinary', 'value_json' => '"next"', 'expected_state_hash' => str_repeat( '0', 64 ) );
$groups = $settings->defaults();
wpnb36_assert( 0 === $groups[ Settings::GROUP_ADVANCED_METADATA ], 'Advanced access must default off.' );
foreach ( array( 'read', 'update', 'delete' ) as $operation ) {
    wpnb36_assert( false === $abilities->{ 'can_' . $operation }( $key_input ), 'Disabled permission passed.' );
    wpnb36_assert( is_wp_error( $abilities->$operation( $key_input ) ), 'Disabled execution passed.' );
}
$groups[ Settings::GROUP_ADVANCED_METADATA ] = 1;
$groups[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $groups );
foreach ( array( 'edit_term', 'edit_term_meta', 'add_term_meta', 'delete_term_meta' ) as $cap ) { $GLOBALS['wpai_test']['capabilities'][ $cap ] = true; }
foreach ( array( array( 'term_id' => 101 ), array( 'term_id' => '101', 'taxonomy' => 'fixture' ), array( 'term_id' => 101, 'taxonomy' => 'other' ), array( 'term_id' => 102, 'taxonomy' => 'fixture' ) ) as $bad ) {
    wpnb36_assert( ! $abilities->can_read( $bad ) && is_wp_error( $abilities->read( $bad ) ), 'Unverified term/taxonomy target passed.' );
}
$GLOBALS['wpnb36_shared'] = true;
wpnb36_assert( ! $abilities->can_read( $base ), 'Shared term identity passed.' );
$GLOBALS['wpnb36_shared'] = false;
$GLOBALS['wpai_test']['capabilities']['edit_term'] = false;
wpnb36_assert( ! $abilities->can_read( $base ), 'Term edit authority was ignored.' );
$GLOBALS['wpai_test']['capabilities']['edit_term'] = true;
$put( 'ordinary', 'first', 10 );
$put( '_private', 'private-value', 11 );
$put( 'sessionToken', 'do-not-disclose', 12 );
$listed = $abilities->read( $base + array( 'per_page' => 1 ) );
wpnb36_assert( ! is_wp_error( $listed ) && 1 === count( $listed['items'] ) && $listed['has_more'], 'Bounded page did not work.' );
wpnb36_assert( array( 'key', 'count' ) === array_keys( $listed['items'][0] ), 'Listing exposed more than a key/count summary.' );
wpnb36_assert( false === strpos( $wpdb->queries[0], 'meta_value' ), 'Broad listing loaded values.' );
wpnb36_assert( is_wp_error( $abilities->read( $base + array( 'include_values' => true ) ) ), 'Broad value retrieval passed.' );
$private = $abilities->read( $base + array( 'key' => '_private', 'include_values' => true ) );
wpnb36_assert( '"private-value"' === $private['items'][0]['values'][0]['value_json'], 'Opt-in private metadata read failed.' );
wpnb36_assert( empty( $GLOBALS['wpnb36_filters']['map_meta_cap'] ), 'Temporary mapping filter leaked.' );
$secrets = array( 'sessionToken', 'sessionTokens', 'SESSION__TOKENS', 'session\\tokens', 'idToken', 'identity_tokens', 'jwttokens', 'csrfToken', 'accessTokens', 'privateKeys', 'clientSecret', 'applicationPasswords', 'api_key', 'password', 'credentials' );
foreach ( $secrets as $key ) {
    wpnb36_assert( Metadata_Key_Policy::is_sensitive( $key ), 'Shared secret policy missed a known secret.' );
    foreach ( array( 'read', 'update', 'delete' ) as $op ) { wpnb36_assert( ! $abilities->{ 'can_' . $op }( array_replace( $key_input, array( 'key' => $key ) ) ), 'Sensitive key permission passed.' ); }
}
wpnb36_assert( ! Metadata_Key_Policy::is_sensitive( 'design_token' ), 'Unrelated design token was blocked.' );
$GLOBALS['wpnb36_filters']['auth_term_meta__private_for_fixture'] = 0;
$GLOBALS['wpnb36_mapped'] = array( 'read', 'edit_term_meta' );
wpnb36_assert( is_wp_error( $abilities->read( $base + array( 'key' => '_private' ) ) ), 'Priority-zero provider denial was ignored.' );
unset( $GLOBALS['wpnb36_filters']['auth_term_meta__private_for_fixture'], $GLOBALS['wpnb36_mapped'] );
$read = $abilities->read( $base + array( 'key' => 'ordinary', 'include_values' => true ) );
wpnb36_assert( '"first"' === $read['items'][0]['values'][0]['value_json'] && 64 === strlen( $read['items'][0]['state_hash'] ), 'Exact value/state result was wrong.' );
foreach ( array( 'update', 'delete' ) as $op ) { $result = $abilities->$op( $key_input ); wpnb36_assert( is_wp_error( $result ) && 'stale_term_meta_conflict' === $result->get_error_code(), 'Stale operation passed.' ); }
$put( 'multi', 'one', 20 ); $put( 'multi', 'two', 21 );
foreach ( array( 'read', 'update', 'delete' ) as $op ) { wpnb36_assert( is_wp_error( $abilities->$op( array_replace( $key_input, array( 'key' => 'multi' ) ) ) ), 'Ambiguous multi-row operation passed.' ); }
$put( 'nullable', null, 30 );
$n = $abilities->read( $base + array( 'key' => 'nullable' ) );
$wpdb->data[ count( $wpdb->data ) - 1 ]['meta_value'] = '';
$e = $abilities->read( $base + array( 'key' => 'nullable' ) );
wpnb36_assert( $n['items'][0]['state_hash'] !== $e['items'][0]['state_hash'], 'NULL aliased empty string.' );
$wpdb->data[ count( $wpdb->data ) - 1 ]['meta_id'] = 31;
$e2 = $abilities->read( $base + array( 'key' => 'nullable' ) );
wpnb36_assert( $e['items'][0]['state_hash'] !== $e2['items'][0]['state_hash'], 'Physical row replacement did not change identity.' );
$put( 'huge', str_repeat( 'x', 1048577 ), 40 );
wpnb36_assert( is_wp_error( $abilities->read( $base + array( 'key' => 'huge' ) ) ), 'Oversized value was loaded.' );
final class WPNB36_Magic_Fixture { public function __wakeup() { throw new RuntimeException( 'Stored object instantiated.' ); } public function __serialize() { throw new RuntimeException( 'Unsafe sanitizer object serialized.' ); } }
$put( 'opaque', 'O:20:"WPNB36_Magic_Fixture":0:{}', 41 );
wpnb36_assert( is_wp_error( $abilities->read( $base + array( 'key' => 'opaque', 'include_values' => true ) ) ), 'Opaque value was exposed.' );
$decode = new ReflectionMethod( $abilities, 'decode_value' );
foreach ( array( '{}', '{"0":"x"}', '9223372036854775808', '{"bad":{}}', 'invalid', str_repeat( 'x', 1048577 ) ) as $json ) { wpnb36_assert( is_wp_error( $decode->invoke( $abilities, $json ) ), 'Lossy/invalid JSON accepted.' ); }
foreach ( array( 'null', 'false', '[]', '1.0', '{"nested":[1,true,"x"]}' ) as $json ) { wpnb36_assert( ! is_wp_error( $decode->invoke( $abilities, $json ) ), 'Lossless JSON was refused.' ); }
$validate = new ReflectionMethod( $abilities, 'unsupported_stored_value_error' );
$resource = fopen( 'php://memory', 'r+' );
foreach ( array( array( 'resource' => $resource ), array( 'object' => new stdClass() ), INF, "\xff" ) as $unsafe ) { wpnb36_assert( is_wp_error( $validate->invoke( $abilities, $unsafe ) ), 'Unsafe stored value was accepted.' ); }
fclose( $resource );
$store = new Term_Meta_Store();
$GLOBALS['wpnb36_sanitizer'] = static fn( $v ) => new WPNB36_Magic_Fixture();
wpnb36_assert( is_wp_error( $store->prepare_value( 101, 'ordinary', 'x', static fn( $v ) => ! is_wp_error( $validate->invoke( $abilities, $v ) ) ) ), 'Unsafe sanitizer output was serialized.' );
unset( $GLOBALS['wpnb36_sanitizer'] );
$wpdb->last_error = 'simulated database failure';
wpnb36_assert( is_wp_error( $abilities->read( $base ) ) && is_wp_error( $abilities->read( $base + array( 'key' => 'ordinary' ) ) ), 'Failed database read became an empty metadata state.' );
$wpdb->last_error = '';
// Verify enum inspection never dispatches an application autoloader.
$autoloads = array();
$loader = static function ( $class ) use ( &$autoloads ) { $autoloads[] = $class; };
spl_autoload_register( $loader );
try {
    $case = 'WPNB36_Unloaded_Enum:Value';
    $encoded = sprintf( 'E:%d:"%s";', strlen( $case ), $case );
    $put( 'enum', $encoded, 50 );
    $put( 'nested_enum', 'a:1:{i:0;' . $encoded . '}', 51 );
    foreach ( array( 'enum', 'nested_enum' ) as $key ) {
        wpnb36_assert( is_wp_error( $abilities->read( $base + array( 'key' => $key, 'include_values' => true ) ) ), 'Serialized enum was accepted.' );
    }
    wpnb36_assert( array() === $autoloads, 'Metadata inspection dispatched an autoloader.' );
    $safe = serialize( array( 'literal' => $encoded, 'nested' => array( true, false, null, 1, 1.5, 'text' ) ) );
    $put( 'serialized_literal', $safe, 52 );
    wpnb36_assert( ! is_wp_error( $abilities->read( $base + array( 'key' => 'serialized_literal', 'include_values' => true ) ) ), 'Harmless enum-like text inside a string was overblocked.' );
} finally { spl_autoload_unregister( $loader ); }
$groups[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0; update_option( Settings::OPTION_NAME, $groups );
wpnb36_assert( ! $abilities->can_delete( $key_input ) && is_wp_error( $abilities->delete( $key_input ) ), 'Destructive group was bypassed.' );
$log = wp_json_encode( ( new Mutation_Log() )->recent( 50 ) );
wpnb36_assert( false === strpos( $log, 'private-value' ) && false === strpos( $log, 'ordinary' ) && false === strpos( $log, 'expected_state_hash' ), 'Mutation log retained key/value/payload data.' );
// Exercise the primitive-only preflight across canonical scalar/array encodings.
$preflight = new ReflectionMethod( $store, 'safe_serialized_fragment' );
$cases = array( null, false, true, 0, PHP_INT_MIN, PHP_INT_MAX, -0.0, 0.5, PHP_FLOAT_MIN, PHP_FLOAT_MAX, '', "quote\";O:1:;E:2:\0", array(), array( '0' => 'x', 'two' => array( 1, 'x', false ) ) );
foreach ( $cases as $case ) {
    foreach ( array( $case, array( 'nested' => $case ), array( $case, array( 'again' => $case ) ) ) as $value ) {
        $raw = serialize( $value ); $offset = 0;
        wpnb36_assert( $preflight->invokeArgs( $store, array( $raw, &$offset ) ) && strlen( $raw ) === $offset, 'Primitive serialization was overblocked.' );
    }
}
$nul_key = array( "\0property" => 'x' );
wpnb36_assert( is_wp_error( $validate->invoke( $abilities, $nul_key ) ), 'Stored value could not round-trip through the actual input contract.' );
// These are malformed source samples for the static inspector; they are never executed.
$store_source = file_get_contents( dirname( __DIR__ ) . '/src/Support/class-term-meta-store.php' );
$fixture = tempnam( sys_get_temp_dir(), 'wpnb36-static-' );
try {
    foreach ( array(
        str_replace( 'AND term_id = %d', 'OR term_id = %d', $store_source ),
        str_replace( '$wpdb->termmeta', '$wpdb->options', $store_source ),
        str_replace( '$wpdb->get_results(', '$database->get_results(', $store_source ),
        str_replace( '$wpdb->prepare(', '$wpdb->$method(', $store_source ),
        str_replace( 'AND tt.term_taxonomy_id = %d', 'OR tt.term_taxonomy_id = %d', $store_source ),
        str_replace( '$wpdb->term_taxonomy', '$wpdb->options', $store_source ),
        str_replace( ' LOCK IN SHARE MODE', '', $store_source ),
        str_replace( "\$target['target_term_taxonomy_id']", '0', $store_source ),
    ) as $invalid_source ) {
        file_put_contents( $fixture, $invalid_source );
        $output = array(); $status = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bin/check-term-meta-confinement.php' ) . ' ' . escapeshellarg( $fixture ) . ' 2>&1', $output, $status );
        wpnb36_assert( 0 !== $status, 'Static confinement accepted a changed SQL template/table/member.' );
    }
} finally { unlink( $fixture ); }
echo "PASS: {$assertions} Issue #36 term metadata contract assertions.\n";
