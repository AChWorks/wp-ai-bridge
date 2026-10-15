<?php
/**
 * Issue #165: bounded Bridge diagnostics, OAuth authorization and redaction.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Diagnostics_Abilities;
use WP_AI_Bridge\Abilities\Transfer_Session_Abilities;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

wpai_test_reset_state();
$log  = new Mutation_Log();
$auth = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
$auth->setValue( null, '' );

$assert( $log->record_transfer( 'begin', new WP_Error( 'transfer_disk_unknown', 'private path /secret/dir bearer-token=NEVER-LOG' ) ), 'Logging source error failed.' );
$raw = $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ];
$assert( 1 === count( $raw ) && 'transfer_disk_unknown' === $raw[0]['error_code'], 'Canonical provider error was not recorded.' );
$assert( ! str_contains( wp_json_encode( $raw ), '/secret/' ) && ! str_contains( wp_json_encode( $raw ), 'NEVER-LOG' ), 'Provider messages or private paths leaked.' );
$assert( '' === $raw[0]['target_type'] && 0 === $raw[0]['target_id'] && 16 === strlen( $raw[0]['event_ref'] ), 'Diagnostic exposed target identity or lacked an opaque reference.' );

$assert( $log->record_transfer( 'begin', new WP_Error( 'private=/secret/bearer-key', 'NOPE' ) ), 'Unknown provider error failed to persist.' );
$read = $log->diagnostic_view( 1 );
$assert( 1 === $read['returned'] && $read['has_more'] && 'transfer_error_unspecified' === $read['events'][0]['error_code'], 'Unknown errors must be redacted, never forwarded verbatim.' );
$assert( ! array_key_exists( 'user_id', $read['events'][0] ) && ! array_key_exists( 'target_id', $read['events'][0] ), 'Remote diagnostics disclosed user or target identity.' );
$assert( $log->record_transfer( 'begin', array( 'state' => 'open' ) ), 'Successful begin should be recorded.' );
$count = count( $log->recent( Mutation_Log::LIMIT ) );
$assert( $log->record_transfer( 'append', array( 'state' => 'open' ) ) && $count === count( $log->recent( Mutation_Log::LIMIT ) ), 'Ordinary successful chunk traffic was logged.' );

$settings    = new Settings();
$permissions = new Permissions( $settings );
$diagnostics = new Diagnostics_Abilities( $permissions, $log );
$registration = $diagnostics->register();
$schema = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/diagnostics-read'];
$assert( count( $registration ) === 1 && true === $schema['meta']['annotations']['readonly'] &&
	false === $schema['meta']['annotations']['destructive'], 'Diagnostic Ability must be readonly and registered.' );
$assert( ! $diagnostics->can_read() && is_wp_error( $diagnostics->execute() ), 'Cookie-based site admin must not access remote diagnostics without an authenticated MCP client.' );
$auth->setValue( null, 'test-authenticated-mcp-client' );
$assert( $diagnostics->can_read() && ! is_wp_error( $diagnostics->execute( array( 'limit' => 2 ) ) ), 'Proper same-site MCP admin should read sanitized diagnostics.' );
$assert( is_wp_error( $diagnostics->execute( array( 'limit' => 51 ) ) ) &&
	is_wp_error( $diagnostics->execute( array( 'limit' => '5' ) ) ) &&
	is_wp_error( $diagnostics->execute( array( 'private_path' => '/secret' ) ) ), 'Diagnostic inputs must reject unbounded, noninteger or path filters.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
$assert( ! $diagnostics->can_read() && is_wp_error( $diagnostics->execute() ), 'A non-admin MCP user may not read diagnostics.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
update_option( Settings::OPTION_NAME, array( Settings::GROUP_SITE_READ => 0 ) );
$assert( ! $diagnostics->can_read(), 'Disabling Site Read must revoke diagnostic visibility.' );

update_option( Settings::OPTION_NAME, array( Settings::GROUP_SITE_READ => 1, Settings::GROUP_BUILDER_WRITE => 1 ) );
$GLOBALS['wpai_test']['capabilities']['upload_files'] = true;
$GLOBALS['wpai_test']['capabilities']['edit_posts']   = true;
$transfer = new Transfer_Session_Abilities( $permissions, $log );
$bad = $transfer->execute( array( 'action' => 'begin', 'purpose' => 'text', 'total_bytes' => -1, 'sha256' => str_repeat( '0', 64 ) ) );
$assert( is_wp_error( $bad ) && 'transfer_invalid' === $bad->get_error_code(), 'Authenticated malformed begin must be rejected.' );
$latest = $log->diagnostic_view( 1 )['events'][0];
$assert( 'begin' === $latest['operation'] && 'validation' === $latest['phase'] &&
	'transfer_invalid' === $latest['error_code'], 'Validly authenticated begin failure was not instrumented.' );
$auth->setValue( null, '' );
$before = count( $log->recent( Mutation_Log::LIMIT ) );
$denied = $transfer->execute( array( 'action' => 'begin', 'purpose' => 'text', 'total_bytes' => -1, 'sha256' => str_repeat( '0', 64 ) ) );
$assert( is_wp_error( $denied ) && $before === count( $log->recent( Mutation_Log::LIMIT ) ), 'Unauthenticated probing must not produce diagnostic spam.' );

// Existing option data is not trusted by the remote diagnostic projection.
$auth->setValue( null, 'test-authenticated-mcp-client' );
$poison = $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ][0];
$poison['error_code'] = 'transfer_token_secret_injected';
array_unshift( $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ], $poison );
$view = $log->diagnostic_view( 50 );
$assert( ! in_array( 'transfer_token_secret_injected', array_column( $view['events'], 'error_code' ), true ), 'Poisoned option values leaked into remote diagnostic results.' );
$poisoned_time = $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ][0];
$poisoned_time['timestamp'] = 'now';
array_unshift( $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ], $poisoned_time );
$view = $log->diagnostic_view( 50 );
$assert( ! in_array( 'now', array_column( $view['events'], 'timestamp' ), true ),
	'Untrusted natural-language timestamp was exposed as UTC diagnostic metadata.' );
array_shift( $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ] );
for ( $i = 0; $i < 75; ++$i ) {
	$log->record_transfer( 'begin', new WP_Error( 'transfer_capacity', 'secret' ) );
}
$assert( 50 === count( $log->recent( 50 )) && 50 === count( $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ] ), 'Log must remain bounded at exactly 50 records.' );
$old = $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ][0];
$old['timestamp'] = gmdate( 'c', time() - 40 * 86400 );
array_unshift( $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ], $old );
$assert( 49 === count( $log->recent( 50 ) ), 'Expired records must be pruned without leaking stale entries.' );
$auth->setValue( null, '' );

echo "PASS: #165 {$checks} diagnostic security, retention and provider-instrumentation assertions.\n";
