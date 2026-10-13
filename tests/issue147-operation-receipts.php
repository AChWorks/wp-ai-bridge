<?php
/**
 * Independent generic-create receipt capacity Settings API and parser gates.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Create_Claim;
use WP_AI_Bridge\Support\Operation_Receipt_Limits;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Workspace_Limits;

$assertions = 0;
$assert     = static function ( $ok, $reason ) use ( &$assertions ) {
	++$assertions;
	if ( ! $ok ) {
		throw new RuntimeException( $reason );
	}
};
wpai_test_reset_state();
$limits = new Operation_Receipt_Limits();
$assert( 2048 === $limits->limit(), 'Unconfigured generic receipts must preserve the existing 2048 capacity.' );
$assert( 2048 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_OPERATION ), 'Default generic receipt admission changed.' );
$assert( 2048 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_CANONICAL ), 'Default canonical admission changed.' );
$limits->register();
$registered = $GLOBALS['wpai_test']['registered_settings'][ Operation_Receipt_Limits::OPTION_NAME ];
$assert( Operation_Receipt_Limits::OPTION_GROUP === $registered['group'], 'Operation receipts must have their own native Settings API form.' );
$assert( 'integer' === $registered['args']['type'] && false === $registered['args']['show_in_rest'], 'Operation receipt setting leaked through public REST or has wrong type.' );
$assert( is_callable( $registered['args']['sanitize_callback'] ), 'Registered operation receipt setting cannot be sanitized.' );

$raised = $limits->sanitize( '8192' );
$assert( 8192 === $raised, 'Administrator cannot raise operation receipt admission beyond 2048.' );
$GLOBALS['wpai_test']['options'][ Operation_Receipt_Limits::OPTION_NAME ] = $raised;
$assert( 8192 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_OPERATION ), 'Create admission ignores site-local policy.' );
$assert( 2048 === Create_Claim::receipt_limit( Create_Claim::DOMAIN_CANONICAL ), 'Generic quota adjustment silently changed canonical document admission.' );
$invalid = array( '-1', '+9000', ' 8192', '8.2e3', '8192.0', '8192 ', '', '0', '127', '100001', str_repeat( '9', 32 ), 8192.1, array( 8192 ), true, null );
foreach ( $invalid as $candidate ) {
	$assert( 8192 === $limits->sanitize( $candidate ), 'Malformed option overwrote existing valid operation receipt capacity.' );
}
$GLOBALS['wpai_test']['options'][ Operation_Receipt_Limits::OPTION_NAME ] = (string) Operation_Receipt_Limits::MAX_RECEIPTS;
$assert( Operation_Receipt_Limits::MAX_RECEIPTS === $limits->limit(), 'Finite upper bound is unreadable.' );
$assert( Operation_Receipt_Limits::MAX_RECEIPTS === $limits->sanitize( (string) Operation_Receipt_Limits::MAX_RECEIPTS ), 'Upper boundary cannot be submitted.' );
$GLOBALS['wpai_test']['options'][ Operation_Receipt_Limits::OPTION_NAME ] = 'invalid';
$assert( 2048 === $limits->limit(), 'Tampered persisted data bypassed strict stored-value validation.' );
$assert( 2048 === $limits->sanitize( 'bad' ), 'Invalid submitted data did not preserve safe fallback.' );
$GLOBALS['wpai_test']['options'][ Operation_Receipt_Limits::OPTION_NAME ] = 8192;
$assert( Operation_Receipt_Limits::MIN_RECEIPTS === $limits->sanitize( (string) Operation_Receipt_Limits::MIN_RECEIPTS ), 'Documented minimum cannot be submitted.' );
$assert(
	! isset( $GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] ) &&
	! isset( $GLOBALS['wpai_test']['options'][ Workspace_Limits::OPTION_NAME ] ),
	'Operation receipt tuning unexpectedly changed WordPress grants or Workspace quotas.'
);
echo "PASS: #147 {$assertions} strict independent generic-create Settings API and finite capacity assertions.\n";
