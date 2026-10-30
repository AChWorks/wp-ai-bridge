<?php
/**
 * Dependency-free native value precision, rejection and budget regressions.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/integration/issue199-native-options-helper.php';

use WP_AI_Bridge\Support\Native_Option_Value;
use WP_AI_Bridge\Support\Settings;

function is_multisite() { return false; }
function get_current_blog_id() { return 1; }
function wp_salt( $scheme = 'auth' ) { return 'synthetic-native-options-199-salt'; }

final class WPAI199N_Magic_Value implements JsonSerializable {
	public static $calls = 0;
	public function jsonSerialize(): mixed { ++self::$calls; return 'must-not-run'; }
	public function __toString() { ++self::$calls; return 'must-not-cast'; }
	public function __serialize() { ++self::$calls; return array( 'must-not-run' ); }
	public function __get( $name ) { ++self::$calls; return 'must-not-read'; }
}

wpai_test_reset_state();
$settings = new Settings();
wpai199n_assert( 0 === $settings->defaults()[ Settings::GROUP_NATIVE_OPTIONS_READ ], 'Native sensitive settings read must default off.' );
$legacy = $settings->defaults();
unset( $legacy[ Settings::GROUP_NATIVE_OPTIONS_READ ] );
$legacy = array_fill_keys( array_keys( $legacy ), 1 );
update_option( Settings::OPTION_NAME, $legacy, false );
wpai199n_assert( ! $settings->is_enabled( Settings::GROUP_NATIVE_OPTIONS_READ ), 'Historical all-enabled flags implicitly granted native secrets.' );

$cases = array(
	null, false, true, 0, -1, PHP_INT_MAX, PHP_INT_MIN,
	'', "\x00\xff\x80\r\n", "native سلام 🌍",
	0.0, -0.0, INF, -INF, NAN, 1.0 / 3.0,
	array(), array( false, 0, '0', null ),
	array( 9 => 'sparse', -4 => false, '01' => 'numeric-looking', '' => "binary\x00", '1.0' => 1.0 ),
	array( 'second' => array( 3 => true ), 'first' => array( 'x' => null ) ),
);
foreach ( $cases as $value ) {
	$encoded = wpai199n_ok( Native_Option_Value::encode( $value, 1024 * 1024 ), 'Supported native value was rejected.' );
	wpai199n_assert( is_string( $encoded['json'] ) && strlen( $encoded['json'] ) === $encoded['bytes'] && hash( 'sha256', $encoded['json'] ) === $encoded['sha256'], 'Codec byte count or SHA is not bound to the complete wire bytes.' );
	wpai199n_same( $value, wpai199n_decode( $encoded['json'] ), 'Codec roundtrip lost a native type, bytes, order or float bits.' );
	$exact = Native_Option_Value::encode( $value, $encoded['bytes'] );
	wpai199n_assert( ! is_wp_error( $exact ) && $exact['json'] === $encoded['json'], 'Exact inclusive processing budget rejected its complete representation.' );
	wpai199n_error( Native_Option_Value::encode( $value, $encoded['bytes'] - 1 ), 'One-byte-short processing budget silently truncated a value.', array( 'native_option_value_budget' ) );
}

// Literal consumer expectations detect a mutually wrong encoder/decoder pair.
$integer = json_decode( Native_Option_Value::encode( PHP_INT_MAX, 4096 )['json'], true );
wpai199n_assert( array( 'type' => 'integer', 'decimal' => (string) PHP_INT_MAX ) === $integer['value'], 'PHP_INT_MAX is not preserved as decimal text.' );
$negative_zero = json_decode( Native_Option_Value::encode( -0.0, 4096 )['json'], true );
wpai199n_assert( array( 'type' => 'float', 'base64' => 'gAAAAAAAAAA=' ) === $negative_zero['value'], 'Negative zero sign bit was lost.' );
$positive_infinity = json_decode( Native_Option_Value::encode( INF, 4096 )['json'], true );
wpai199n_assert( 'f/AAAAAAAAA=' === $positive_infinity['value']['base64'], 'Positive infinity is not its IEEE754 big-endian bit pattern.' );
$binary = json_decode( Native_Option_Value::encode( "\x00\xff", 4096 )['json'], true );
wpai199n_assert( array( 'type' => 'string', 'base64' => 'AP8=' ) === $binary['value'], 'Binary string representation changed.' );
$keys = json_decode( Native_Option_Value::encode( array( 8 => 1, '08' => 2, -1 => 3 ), 4096 )['json'], true )['value']['entries'];
wpai199n_assert( array( 'type' => 'integer', 'decimal' => '8' ) === $keys[0]['key'] && array( 'type' => 'string', 'base64' => 'MDg=' ) === $keys[1]['key'] && array( 'type' => 'integer', 'decimal' => '-1' ) === $keys[2]['key'], 'Sparse/numeric-looking keys changed type or iteration order.' );

$magic = new WPAI199N_Magic_Value();
foreach ( array( $magic, new stdClass(), array( 'nested' => $magic ) ) as $unsupported ) {
	wpai199n_error( Native_Option_Value::encode( $unsupported, 1024 * 1024 ), 'Object was silently cast/serialized.', array( 'native_option_value_unsupported' ) );
}
wpai199n_assert( 0 === WPAI199N_Magic_Value::$calls, 'Native codec executed object magic.' );
$resource = fopen( 'php://memory', 'r+' );
try {
	wpai199n_error( Native_Option_Value::encode( $resource, 4096 ), 'Resource was silently cast.', array( 'native_option_value_unsupported' ) );
	wpai199n_error( Native_Option_Value::encode( array( 'nested' => $resource ), 4096 ), 'Nested resource was silently cast.', array( 'native_option_value_unsupported' ) );
} finally {
	fclose( $resource );
}
$shared = 'shared';
$reference = array( 'a' => &$shared, 'b' => &$shared );
wpai199n_error( Native_Option_Value::encode( $reference, 4096 ), 'Shared reference representation was silently flattened.', array( 'native_option_value_unsupported' ) );
$cycle = array();
$cycle['self'] = &$cycle;
wpai199n_error( Native_Option_Value::encode( $cycle, 4096 ), 'Array cycle was not explicitly rejected.', array( 'native_option_value_unsupported' ) );
unset( $cycle['self'], $cycle, $reference );

$edge = 'leaf';
for ( $depth = 0; $depth < 64; ++$depth ) { $edge = array( $edge ); }
wpai199n_ok( Native_Option_Value::encode( $edge, 1024 * 1024 ), 'Supported depth64 boundary was rejected.' );
wpai199n_error( Native_Option_Value::encode( array( $edge ), 1024 * 1024 ), 'Unsupported depth65 boundary was accepted.', array( 'native_option_value_unsupported' ) );
unset( $edge );
$deep = 'leaf';
for ( $depth = 0; $depth < 1024; ++$depth ) {
	$deep = array( $deep );
}
wpai199n_error( Native_Option_Value::encode( $deep, 16 * 1024 * 1024 ), 'Excessive nesting did not explicitly fail.', array( 'native_option_value_budget', 'native_option_value_unsupported' ) );
$wide = array_fill( 0, 200000, null );
wpai199n_error( Native_Option_Value::encode( $wide, 4096 ), 'Many entries bypassed the actual encoded-byte processing budget.', array( 'native_option_value_budget' ) );
$moderate = array_fill( 0, 2000, null );
wpai199n_ok( Native_Option_Value::encode( $moderate, 1024 * 1024 ), 'A finite representable array was rejected by an invented count quota.' );
unset( $wide, $deep );
wpai199n_error( Native_Option_Value::encode( 'budget', 0 ), 'Zero processing budget was accepted.', array( 'native_option_value_budget' ) );

echo 'PASS: Issue #199 native value and default-off boundaries (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
