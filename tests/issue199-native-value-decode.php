<?php
/**
 * Pure-code canonical native value decoder and resource boundary regressions.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/integration/issue199-native-options-helper.php';

use WP_AI_Bridge\Support\Native_Option_Value;

function wpai199d_document( $tag ) {
	return '{"format":"wpai-native-option-value-v1","value":' . $tag . '}';
}
function wpai199d_decode( $json, $budget = null, $ceiling = 0 ) {
	return Native_Option_Value::decode( $json, null === $budget ? max( 1, strlen( $json ) ) : $budget, $ceiling );
}
function wpai199d_invalid( $json, $code = 'native_option_value_invalid' ) {
	$result = wpai199n_error( wpai199d_decode( $json ), 'Malformed native input was accepted.', array( $code ) );
	wpai199n_assert( false === strpos( $result->get_error_message(), 'secret-marker' ), 'Decoder error leaked input contents.' );
}
function wpai199d_string( $bytes ) {
	return '{"type":"string","base64":"' . base64_encode( $bytes ) . '"}';
}
function wpai199d_entry( $key, $value = '{"type":"null"}' ) {
	return '{"key":' . $key . ',"value":' . $value . '}';
}

$cases = array(
	null, false, true, 0, -1, PHP_INT_MIN, PHP_INT_MAX,
	'', "\0\xff\x80\r\n", "سلام 🌍", 'O:12:"secret-marker":0:{}', 'a:1:{i:0;R:1;}',
	0.0, -0.0, 1.0 / 3.0, INF, -INF, NAN,
	array(),
	array( 8 => null, -9 => false, '08' => 'sparse', '' => "\0\xff", '1.0' => 0.0, '+1' => true ),
	array( 'z' => array( 99 => true, 'a' => array( null, 0, false ) ), 'a' => array( 'second', 'first' ) ),
);
foreach ( $cases as $value ) {
	$encoded = wpai199n_ok( Native_Option_Value::encode( $value, 1048576 ), 'Supported native value failed to encode.' );
	$decoded = wpai199n_ok( wpai199d_decode( $encoded['json'] ), 'Canonical value failed to decode.' );
	wpai199n_same( $value, $decoded['value'], 'Native decoding changed type, bytes, keys, order or float bits.' );
	wpai199n_assert( $decoded['json'] === $encoded['json'] && $decoded['bytes'] === $encoded['bytes'] &&
		$decoded['sha256'] === $encoded['sha256'], 'Canonical document identity changed.' );
	$again = wpai199n_ok( Native_Option_Value::encode( $decoded['value'], $encoded['bytes'] ), 'Decoded native value failed to re-encode.' );
	wpai199n_assert( $again['json'] === $encoded['json'], 'Decode/encode canonical identity changed.' );
	wpai199n_error( wpai199d_decode( $encoded['json'], $encoded['bytes'] - 1 ), 'Short byte budget bypassed admission.', array( 'native_option_value_budget' ) );
}
foreach ( array(
	'0000000000000000', '8000000000000000', '0000000000000001', '8000000000000001',
	'0010000000000000', '7fefffffffffffff', 'ffefffffffffffff',
	'7ff0000000000000', 'fff0000000000000',
	'7ff8000000000042', 'fff8000000000042', '7ff0000000000001', 'fff0000000000001',
) as $hex ) {
	$bits = hex2bin( $hex );
	$json = wpai199d_document( '{"type":"float","base64":"' . base64_encode( $bits ) . '"}' );
	$native = unpack( 'Evalue', $bits )['value'];
	$result = wpai199d_decode( $json );
	if ( pack( 'E', $native ) !== $bits ) {
		wpai199n_error( $result, 'Unrepresentable float bits were silently normalized.', array( 'native_option_value_unsupported' ) );
	} else {
		$result = wpai199n_ok( $result, 'Representable native float bits were rejected.' );
		wpai199n_assert( is_float( $result['value'] ) && pack( 'E', $result['value'] ) === $bits, 'Native float bits changed.' );
		wpai199n_assert( Native_Option_Value::encode( $result['value'], 4096 )['json'] === $json, 'Float canonical identity changed.' );
	}
}

$null = wpai199d_document( '{"type":"null"}' );
foreach ( array(
	'', 'null', '{}', '[]', $null . "\n", ' ' . $null, "\xef\xbb\xbf" . $null,
	str_replace( '"format":', '"format" :', $null ),
	str_replace( '"format"', '"f\u006frmat"', $null ),
	str_replace( ',"value":', ',"extra":0,"value":', $null ),
	str_replace( ',"value":', ',"format":"wpai-native-option-value-v1","value":', $null ),
	'{"value":{"type":"null"},"format":"wpai-native-option-value-v1"}',
	wpai199d_document( '{"type":"null","value":null}' ),
	wpai199d_document( '{"type":"null","type":"null"}' ),
	wpai199d_document( '{"type":"boolean","value":1}' ),
	wpai199d_document( '{"type":"boolean","value":"false"}' ),
	wpai199d_document( '{"value":true,"type":"boolean"}' ),
	wpai199d_document( '{"type":"object","class":"secret-marker"}' ),
	wpai199d_document( '{"type":"reference","value":0}' ),
	wpai199d_document( '{"type":"resource"}' ),
	wpai199d_document( '{"type":"array","entries":{}}' ),
	wpai199d_document( '{"type":"array","entries":[null]}' ),
	wpai199d_document( '{"type":"array","entries":[], "extra":0}' ),
) as $malformed ) {
	wpai199d_invalid( $malformed );
}
for ( $offset = 0; $offset < strlen( $null ); ++$offset ) {
	wpai199d_invalid( substr( $null, 0, $offset ) );
}
foreach ( array( '', '-0', '+0', '+1', '00', '01', '-01', '--1', '1.0', '1e3', ' 1', '1 ', '١' ) as $decimal ) {
	wpai199d_invalid( wpai199d_document( '{"type":"integer","decimal":"' . $decimal . '"}' ) );
}
foreach ( array( (string) PHP_INT_MAX . '0', (string) PHP_INT_MIN . '0' ) as $decimal ) {
	wpai199d_invalid( wpai199d_document( '{"type":"integer","decimal":"' . $decimal . '"}' ), 'native_option_value_unsupported' );
}
foreach ( array( '=', '====', 'AA', 'AA=', 'AA===', 'AB==', 'AAB=', 'AA== ', "AA==\n", 'A_A=', 'A-A=', 'A===', 'AA\u003d\u003d', 'secret-marker' ) as $base64 ) {
	wpai199d_invalid( wpai199d_document( '{"type":"string","base64":"' . $base64 . '"}' ) );
}
foreach ( array( '', 'AA==', 'AAAAAAAAAA==', 'AAAAAAAAAAAA' ) as $base64 ) {
	wpai199d_invalid( wpai199d_document( '{"type":"float","base64":"' . $base64 . '"}' ) );
}

$int = '{"type":"integer","decimal":"1"}';
$str = wpai199d_string( 'secret-marker' );
foreach ( array(
	wpai199d_entry( $int ) . ',' . wpai199d_entry( $int, '{"type":"boolean","value":true}' ),
	wpai199d_entry( $str ) . ',' . wpai199d_entry( $str ),
	wpai199d_entry( $int ) . ',' . wpai199d_entry( wpai199d_string( '1' ) ),
	wpai199d_entry( '{"type":"boolean","value":true}' ),
	wpai199d_entry( '{"type":"null"}' ),
	wpai199d_entry( '{"type":"array","entries":[]}' ),
	wpai199d_entry( '{"type":"float","base64":"P/AAAAAAAAA="}' ),
	wpai199d_entry( $int ) . ',',
	'{"value":{"type":"null"},"key":' . $int . '}',
	'{"key":' . $int . ',"value":{"type":"null"},"extra":true}',
) as $entries ) {
	wpai199d_invalid( wpai199d_document( '{"type":"array","entries":[' . $entries . ']}' ) );
}
foreach ( array( '0', '1', '-1', (string) PHP_INT_MIN, (string) PHP_INT_MAX ) as $coercing ) {
	wpai199d_invalid( wpai199d_document( '{"type":"array","entries":[' . wpai199d_entry( wpai199d_string( $coercing ) ) . ']}' ) );
}
foreach ( array( '01', '+1', '-0', '1.0', ' 1', '', "\0\xff", (string) PHP_INT_MAX . '0' ) as $key ) {
	$value = array( $key => 'exact-key' );
	$json  = Native_Option_Value::encode( $value, 4096 )['json'];
	wpai199n_same( $value, wpai199n_ok( wpai199d_decode( $json ), 'Representable string key was rejected.' )['value'], 'Representable key identity changed.' );
}

$deep = '{"type":"string","base64":"eA=="}';
for ( $depth = 0; $depth < 64; ++$depth ) {
	$deep = '{"type":"array","entries":[' . wpai199d_entry( '{"type":"integer","decimal":"0"}', $deep ) . ']}';
}
wpai199n_ok( wpai199d_decode( wpai199d_document( $deep ) ), 'Supported depth64 was rejected.' );
$deep = '{"type":"array","entries":[' . wpai199d_entry( '{"type":"integer","decimal":"0"}', $deep ) . ']}';
wpai199d_invalid( wpai199d_document( $deep ), 'native_option_value_unsupported' );
unset( $deep );

final class WPAI199D_Magic implements JsonSerializable {
	public static $calls = 0;
	public function jsonSerialize(): mixed { ++self::$calls; return 'secret-marker'; }
	public function __toString() { ++self::$calls; return 'secret-marker'; }
	public function __serialize() { ++self::$calls; return array(); }
	public function __wakeup() { ++self::$calls; }
}
foreach ( array( new WPAI199D_Magic(), new stdClass(), array(), null, false, 1, STDIN ) as $not_string ) {
	wpai199n_error( Native_Option_Value::decode( $not_string, 4096 ), 'Non-string document was cast or accepted.', array( 'native_option_value_invalid' ) );
}
wpai199n_assert( 0 === WPAI199D_Magic::$calls, 'Decoder invoked object magic.' );
foreach ( array( 0, -1, '4096', 4.0, PHP_INT_MAX * 1.0 ) as $bad_budget ) {
	wpai199n_error( Native_Option_Value::decode( $null, $bad_budget ), 'Invalid byte budget accepted.', array( 'native_option_value_budget' ) );
}
foreach ( array( -1, '0', 1.0 ) as $bad_ceiling ) {
	wpai199n_error( Native_Option_Value::decode( $null, 4096, $bad_ceiling ), 'Invalid memory ceiling accepted.', array( 'native_option_value_budget' ) );
}
wpai199n_error( Native_Option_Value::decode( $null, 4096, memory_get_usage( true ) + 128 ), 'Known insufficient memory did not refuse admission.', array( 'native_option_value_budget' ) );
wpai199n_ok( Native_Option_Value::decode( $null, 4096, 0 ), 'Unknown finite memory incorrectly locked out finite operator admission.' );

$wide    = array_fill( 0, 30000, null );
$encoded = wpai199n_ok( Native_Option_Value::encode( $wide, 16 * 1048576 ), 'Wide canonical value failed to encode.' );
unset( $wide );
$decoded = wpai199n_ok( wpai199d_decode( $encoded['json'], $encoded['bytes'], memory_get_usage( true ) + 64 * 1048576 ), 'Wide finite value hit an invented node ceiling.' );
wpai199n_assert( count( $decoded['value'] ) === 30000 && array_keys( $decoded['value'] ) === range( 0, 29999 ), 'Wide value lost ordered native entries.' );
wpai199n_assert( Native_Option_Value::encode( $decoded['value'], $encoded['bytes'] )['json'] === $encoded['json'], 'Wide canonical identity changed.' );
wpai199n_error( wpai199d_decode( $encoded['json'], 4096 ), 'Wide input bypassed actual byte admission.', array( 'native_option_value_budget' ) );
wpai199n_error( wpai199d_decode( $encoded['json'], $encoded['bytes'], memory_get_usage( true ) + 128 ), 'Wide input bypassed actual memory admission.', array( 'native_option_value_budget' ) );
unset( $encoded, $decoded );

$binary  = str_repeat( "\0\xff", 600000 );
$encoded = wpai199n_ok( Native_Option_Value::encode( $binary, 4 * 1048576 ), 'Large binary document failed to encode.' );
$decoded = wpai199n_ok( wpai199d_decode( $encoded['json'] ), 'A document above1MiB hit an arbitrary total-size ceiling.' );
wpai199n_assert( $decoded['value'] === $binary && $decoded['bytes'] > 1048576, 'Large exact binary value changed.' );

echo 'PASS: Issue #199 canonical native value decode (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
