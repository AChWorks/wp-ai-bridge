<?php
/**
 * Per-site typed generic REST input budget, host/memory clamp, and Settings API.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\REST_Invocation_Input_Limits;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;

$assertions = 0;
$assert = static function ( $ok, $reason ) use ( &$assertions ) {
	++$assertions;
	if ( ! $ok ) {
		throw new RuntimeException( $reason );
	}
};
wpai_test_reset_state();
$setting = new REST_Invocation_Input_Limits();
$assert( 16 === $setting->requested_kib() && 16384 === $setting->effective_bytes(), 'Default REST request admission must remain the historical 16KiB.' );
$setting->register();
$registered = $GLOBALS['wpai_test']['registered_settings'][ REST_Invocation_Input_Limits::OPTION_NAME ];
$assert( REST_Invocation_Input_Limits::OPTION_GROUP === $registered['group'] && false === $registered['args']['show_in_rest'], 'Generic high-trust REST setting must be a separate private native option.' );
$assert( 'integer' === $registered['args']['type'] && is_callable( $registered['args']['sanitize_callback'] ), 'Settings API must require strict integer sanitizer.' );
$GLOBALS['wpai_test']['options'][ REST_Invocation_Input_Limits::OPTION_NAME ] = $setting->sanitize( '48' );
$assert( 48 === $setting->requested_kib() && 48 * 1024 === $setting->effective_bytes(), 'Raise beyond 16KiB must work within configured incoming-MCP allowance.' );
$invalid = array( '', '0', '-1', '+48', '048', ' 48', '48 ', '48.0', '4.8e1', str_repeat( '9', 60 ), true, 48.1, null, array( 48 ) );
foreach ( $invalid as $candidate ) {
	$assert( 48 === $setting->sanitize( $candidate ), 'Malformed REST budget overwrote previously valid policy.' );
}
$assert( 1 === $setting->sanitize( '1' ), 'One KiB lower policy should be accepted.' );
$assert( REST_Invocation_Input_Limits::max_kib() === $setting->sanitize( (string) REST_Invocation_Input_Limits::max_kib() ), 'Machine-safe upper bound should be accepted without overflow.' );
$assert( 48 === $setting->sanitize( (string) ( REST_Invocation_Input_Limits::max_kib() + 1 ) ), 'Overflow-prone size accepted.' );
$GLOBALS['wpai_test']['options'][ REST_Invocation_Input_Limits::OPTION_NAME ] = 256;
$assert( 128 * 1024 === $setting->effective_bytes(), 'The independent incoming MCP request limit must clamp high-trust REST input.' );
$GLOBALS['wpai_test']['options'][ Transfer_Settings::OPTION_NAME ] = array( Transfer_Settings::REQUEST_KIB => 32 );
$assert( 32 * 1024 === $setting->effective_bytes(), 'Lowered transport budget must clamp a previously larger REST policy.' );
$GLOBALS['wpai_test']['options'][ REST_Invocation_Input_Limits::OPTION_NAME ] = 'invalid';
$assert( 16 === $setting->requested_kib() && 16 * 1024 === $setting->effective_bytes(), 'Corrupted persisted policy must fall back to secure historical 16KiB.' );
$assert( ! isset( $GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] ), 'Changing generic REST capacity unexpectedly granted another WordPress permission.' );
echo "PASS: #147 {$assertions} native REST input budget parser, host/input clamp and independent grant assertions.\n";
