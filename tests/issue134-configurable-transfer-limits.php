<?php
/**
 * Site-administrator resource policy must not inherit a fixed file cap or
 * numeric parser-width ceiling from a previous product release.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Payload_Settings;
use WP_AI_Bridge\Support\Transfer_Settings;

$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$payload = new Payload_Settings();
$staged  = new Transfer_Settings();
$assert( Payload_Settings::max_inline_file_mib() === intdiv( PHP_INT_MAX, 1048576 ), 'Inline source maximum is not PHP numeric bounded.' );
$assert( Payload_Settings::max_inline_media_mib() >= Payload_Settings::DEFAULT_INLINE_MEDIA_MIB, 'Legacy Media upload capacity unexpectedly excludes the prior default.' );

$large_mib = min( 12345678, Payload_Settings::max_inline_file_mib() );
$accepted  = $payload->sanitize( array( Payload_Settings::INLINE_FILE_KEY => (string) $large_mib ) );
$assert( $accepted[ Payload_Settings::INLINE_FILE_KEY ] === $large_mib, 'Seven-digit legacy parser still hard-caps Media source budget.' );
$bogus    = $payload->sanitize( array( Payload_Settings::INLINE_FILE_KEY => '9e9' ) );
$assert( $bogus[ Payload_Settings::INLINE_FILE_KEY ] === Payload_Settings::DEFAULT_INLINE_FILE_MIB, 'Scientific notation should be rejected without widening the prior setting.' );
$overflow = $payload->sanitize( array( Payload_Settings::INLINE_FILE_KEY => str_repeat( '9', 40 ) ) );
$assert( $overflow[ Payload_Settings::INLINE_FILE_KEY ] === Payload_Settings::DEFAULT_INLINE_FILE_MIB, 'Unrepresentable numeric input was accepted.' );

$upper = $staged->upper_bounds();
$max   = min( 65536, $upper[ Transfer_Settings::FILE_MIB ] );
$valid = $staged->sanitize(
	array(
		Transfer_Settings::FILE_MIB   => (string) $max,
		Transfer_Settings::ACTIVE_MIB => (string) $max,
		Transfer_Settings::SESSIONS   => '16',
		Transfer_Settings::LEASE_MIN  => '360',
	)
);
$assert( $valid[ Transfer_Settings::FILE_MIB ] === $max && $valid[ Transfer_Settings::ACTIVE_MIB ] === $max, 'Aggregate staged capacity cannot grow with current PHP integer range.' );
$assert( 16 === $valid[ Transfer_Settings::SESSIONS ] && 360 === $valid[ Transfer_Settings::LEASE_MIN ], 'Session count/TTL was not independently operator-configurable.' );
$invalid = $staged->sanitize( array( Transfer_Settings::FILE_MIB => '999999999999999999999999' ) );
$assert( $invalid[ Transfer_Settings::FILE_MIB ] === $staged->defaults()[ Transfer_Settings::FILE_MIB ], 'Out-of-range staged size silently expanded its reservation.' );

echo "PASS: #134 {$assertions} machine-derived file capacity, strict numeric and operator-setting assertions.\n";
