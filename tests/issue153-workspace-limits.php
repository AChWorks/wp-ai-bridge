<?php
/**
 * Strict, per-blog Workspace-count and correlated create-receipt policy.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Workspace_Limits;
use WP_AI_Bridge\Support\Create_Claim;
use WP_AI_Bridge\Support\Settings;

$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
wpai_test_reset_state();
$settings = new Workspace_Limits();
$assert( array(
	Workspace_Limits::DOCUMENTS_KEY => 200,
	Workspace_Limits::TASKS_KEY     => 200,
) === $settings->all(), 'No stored policy must preserve the historical 200-per-type default.' );
$assert( Create_Claim::MAX_CLAIMS === Create_Claim::receipt_limit(), 'Historical create-claim ceiling changed without an administrator adjustment.' );
$settings->register();
$registered = $GLOBALS['wpai_test']['registered_settings'][ Workspace_Limits::OPTION_NAME ];
$assert( Workspace_Limits::OPTION_GROUP === $registered['group'] && false === $registered['args']['show_in_rest'], 'Workspace capacity must have its own non-REST Settings API group.' );
$assert( is_callable( $registered['args']['sanitize_callback'] ), 'Workspace capacity setting must sanitize untrusted submitted values.' );

$tuned = $settings->sanitize( array( Workspace_Limits::DOCUMENTS_KEY => '2200', Workspace_Limits::TASKS_KEY => '5100' ) );
$assert( 2200 === $tuned[ Workspace_Limits::DOCUMENTS_KEY ] && 5100 === $tuned[ Workspace_Limits::TASKS_KEY ], 'Site administrator cannot move beyond the old 200 limit independently.' );
$GLOBALS['wpai_test']['options'][ Workspace_Limits::OPTION_NAME ] = $tuned;
$assert( $settings->all() === $tuned, 'Persisted settings were not reread exactly.' );
$assert( Create_Claim::receipt_limit() >= 2 * 2200 + 5100, 'Canonical keyed creates hit an unrelated hidden 2048 receipt cap when the operator increases capacity.' );
$invalid_values = array( '', '-1', '3.5', '2e3', '0', ' 100', '1_000', '100000000000000000000', array( 1 ), 1.5 );
foreach ( $invalid_values as $value ) {
	$next = $settings->sanitize( array( Workspace_Limits::DOCUMENTS_KEY => $value ) );
	$assert( 2200 === $next[ Workspace_Limits::DOCUMENTS_KEY ], 'Malformed or out-of-range quota overwrote a valid policy.' );
}
$unaffected = $settings->sanitize( array( Workspace_Limits::DOCUMENTS_KEY => '1000' ) );
$assert( 1000 === $unaffected[ Workspace_Limits::DOCUMENTS_KEY ] && 5100 === $unaffected[ Workspace_Limits::TASKS_KEY ], 'Saving one policy unexpectedly reset the other.' );
$boundary = $settings->sanitize( array(
	Workspace_Limits::DOCUMENTS_KEY => (string) Workspace_Limits::MAX_DOCUMENTS,
	Workspace_Limits::TASKS_KEY     => (string) Workspace_Limits::MAX_TASKS,
) );
$assert( $boundary[ Workspace_Limits::DOCUMENTS_KEY ] === Workspace_Limits::MAX_DOCUMENTS &&
	$boundary[ Workspace_Limits::TASKS_KEY ] === Workspace_Limits::MAX_TASKS, 'The documented finite policy boundary is not writable.' );
$too_high = $settings->sanitize( array( Workspace_Limits::TASKS_KEY => (string) ( Workspace_Limits::MAX_TASKS + 1 ) ) );
$assert( 5100 === $too_high[ Workspace_Limits::TASKS_KEY ], 'Out-of-range task capacity did not preserve the previous value.' );
$assert( ! isset( $GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] ) ||
	array() === $GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ], 'Tuning Workspace counts must never modify Bridge authority grants.' );
echo "PASS: #153 {$assertions} strict Workspace document/task count, Settings API, and scaled create-receipt assertions.\n";
