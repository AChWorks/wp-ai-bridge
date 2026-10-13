<?php
/**
 * Finite host-bound external package admission; no global ceiling or ACL edit.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\External_Package_Limits;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;

$checks = 0;
$assert = static function ( $ok, $why ) use ( &$checks ) {
	++$checks;
	if ( ! $ok ) {
		throw new RuntimeException( $why );
	}
};
wpai_test_reset_state();
$settings = new External_Package_Limits();
$assert( 100 === $settings->requested_mib(), 'Existing 100 MiB default was not preserved.' );
$assert( External_Package_Limits::max_mib() > 100, 'Host-sized package capacity cannot exceed former fixed maximum.' );
$settings->register();
$registered = $GLOBALS['wpai_test']['registered_settings'][ External_Package_Limits::OPTION_NAME ];
$assert( External_Package_Limits::OPTION_GROUP === $registered['group'], 'External package budget must be its own native Settings API form.' );
$assert( 'integer' === $registered['args']['type'] && false === $registered['args']['show_in_rest'], 'External package option cannot be public REST or untyped.' );
$assert( is_callable( $registered['args']['sanitize_callback'] ), 'External package option lacks native sanitization.' );
$assert( 192 === $settings->sanitize( '192' ), 'Administrator cannot raise 100 MiB policy to 192 MiB.' );
$GLOBALS['wpai_test']['options'][ External_Package_Limits::OPTION_NAME ] = 192;
$assert( 192 === $settings->requested_mib(), 'Independent per-site option was not honored.' );
$invalid = array( '-1', '+192', '0192', ' 192', '192 ', '0', '', '192.0', '1.92e2', '1_92', '9999999999999999999999999999999', 192.3, true, false, null, array( 192 ) );
foreach ( $invalid as $input ) {
	$assert( 192 === $settings->sanitize( $input ), 'Malformed or excessive native setting replaced the previous valid value.' );
}
$assert( 1 === $settings->sanitize( '1' ), 'Minimum MiB setting was rejected.' );
$max = External_Package_Limits::max_mib();
$assert( $max === $settings->sanitize( (string) $max ), 'Host-integer-safe theoretical maximum cannot be persisted.' );
$assert( 192 === $settings->sanitize( (string) ( $max + 1 ) ), 'Overflow-prone PHP MiB limit accepted.' );
$GLOBALS['wpai_test']['options'][ External_Package_Limits::OPTION_NAME ] = str_repeat( '9', 60 );
$assert( 100 === $settings->requested_mib(), 'Tampered persisted limit did not fail back to finite default.' );
$GLOBALS['wpai_test']['options'][ External_Package_Limits::OPTION_NAME ] = 192;
$assert( 192 === $settings->requested_mib(), 'Valid setting did not recover after invalid persisted data.' );
$assert(
	! isset( $GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] ) &&
	! isset( $GLOBALS['wpai_test']['options'][ Transfer_Settings::OPTION_NAME ] ),
	'Changing only external package budget altered Bridge grants or private transfer quota.'
);
echo "PASS: #147 {$checks} strict type-specific external-package Settings API, parser, rollback and separation assertions.\n";
