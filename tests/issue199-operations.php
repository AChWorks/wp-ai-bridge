<?php
/**
 * Discriminating log-byte and native scheduler adapter regressions.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Log_Reader;
use WP_AI_Bridge\Support\Native_Scheduler;
use WP_AI_Bridge\Support\Cron_Run_Claim;

function get_current_blog_id() { return 1; }
function wp_salt( $scheme = 'auth' ) { return 'operations-test-salt'; }
function has_action( $hook ) { return in_array( $hook, array( 'operations_fixture', 'wp_maybe_auto_update' ), true ) ? 10 : false; }
function _get_cron_array() { return $GLOBALS['operations_cron'] ?? array(); }
function wp_get_schedules() { return array( 'custom_fixture' => array( 'interval' => 17, 'display' => 'Fixture' ) ); }
function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	++$GLOBALS['operations_dispatches'];
	$GLOBALS['operations_cron'][ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = array( 'schedule' => false, 'args' => $args );
	return true;
}
function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
	++$GLOBALS['operations_dispatches'];
	$GLOBALS['operations_cron'][ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = array( 'schedule' => $recurrence, 'args' => $args, 'interval' => 17 );
	return true;
}
function wp_unschedule_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	++$GLOBALS['operations_dispatches'];
	unset( $GLOBALS['operations_cron'][ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] );
	return true;
}
function wp_get_scheduled_event( $hook, $args = array(), $timestamp = null ) {
	$event = $GLOBALS['operations_cron'][ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] ?? false;
	return false === $event ? false : (object) $event;
}
$assertions = 0;
function operations_assert( $condition, $message ) {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$stream = tmpfile();
operations_assert( is_resource( $stream ), 'Pure test stream unavailable.' );
fwrite( $stream, "zero\none\ntwo\nthree\n" );
$tail = Log_Reader::tail_bytes( $stream, 19, 19, 2, microtime( true ) + 2 );
operations_assert( ! is_wp_error( $tail ) && "two\nthree\n" === $tail['bytes'] && 9 === $tail['offset'] && ! $tail['leading_partial'], 'Trailing newline tail boundary incorrect.' );
ftruncate( $stream, 0 );
rewind( $stream );
fwrite( $stream, "one\ntwo\nthree" );
$tail = Log_Reader::tail_bytes( $stream, 13, 13, 2, microtime( true ) + 2 );
operations_assert( "two\nthree" === $tail['bytes'] && 4 === $tail['offset'], 'Unterminated last line tail boundary incorrect.' );
$tail = Log_Reader::tail_bytes( $stream, 13, 3, 2, microtime( true ) + 2 );
operations_assert( 'ree' === $tail['bytes'] && $tail['leading_partial'] && $tail['limited'] && 3 === $tail['inspected_bytes'], 'Long line must remain a bounded explicit partial window.' );
$binary = "\x00\xff\xf0\x9f\x98\x80\r\n";
rewind( $stream );
ftruncate( $stream, 0 );
fwrite( $stream, $binary );
operations_assert( $binary === Log_Reader::stream_bytes( $stream, 0, strlen( $binary ) ), 'Binary and UTF-8 bytes changed.' );
operations_assert( '' === Log_Reader::stream_bytes( $stream, strlen( $binary ), 0 ), 'EOF empty window failed.' );
fseek( $stream, 64 * 1024 * 1024 );
fwrite( $stream, "\nlast\n" );
$size = ftell( $stream );
$memory = memory_get_usage( true );
$tail = Log_Reader::tail_bytes( $stream, $size, 4096, 1, microtime( true ) + 2 );
operations_assert( "last\n" === $tail['bytes'] && $tail['inspected_bytes'] <= 4096 && memory_get_usage( true ) - $memory < 1024 * 1024, 'Large sparse tail amplified source size.' );
fclose( $stream );

$GLOBALS['operations_cron'] = array();
$GLOBALS['operations_dispatches'] = 0;
$scheduler = new Native_Scheduler( 65536 );
$providers = $scheduler->providers();
operations_assert( ! $providers[1]['available'] && array() === $providers[1]['actions'], 'Absent provider advertised an execution path.' );
$missing = $scheduler->read( array( 'action' => 'list', 'provider' => 'action_scheduler' ) );
operations_assert( is_wp_error( $missing ), 'Absent provider must not simulate queue data.' );
$args = array( array( 'nested' => true, 'binary' => "\x00\xff" ), 'secret-fixture-not-public' );
$plan = $scheduler->plan( array( 'provider' => 'wp_cron', 'action' => 'schedule', 'hook' => 'operations_fixture', 'args' => $args, 'timestamp' => 12345 ) );
operations_assert( ! is_wp_error( $plan ), 'Registered native schedule rejected.' );
$native = $scheduler->dispatch( $plan );
$observed = $scheduler->observe( $plan, $native, false );
operations_assert( 'native_acknowledged' === $observed['outcome'] && 1 === $GLOBALS['operations_dispatches'], 'Native schedule acknowledgement not observed.' );
$list = $scheduler->read( array( 'action' => 'list', 'provider' => 'wp_cron' ) );
operations_assert( 1 === count( $list['items'] ) && false === strpos( json_encode( $list ), 'secret-fixture' ) && false === strpos( json_encode( $list ), 'nested' ), 'Metadata inventory leaked callback arguments.' );
$row = $list['items'][0];
$stale = $scheduler->plan( array( 'provider' => 'wp_cron', 'action' => 'cancel', 'target_id' => $row['target_id'], 'expected_snapshot' => str_repeat( 'a', 64 ) ) );
operations_assert( is_wp_error( $stale ) && 1 === $GLOBALS['operations_dispatches'], 'Stale native selection dispatched a mutation.' );
$run = $scheduler->plan( array( 'provider' => 'wp_cron', 'action' => 'run', 'target_id' => $row['target_id'], 'expected_snapshot' => $row['snapshot'] ) );
operations_assert( is_wp_error( $run ) && 'scheduler_core_targeted_runner_required' === $run->get_error_code(), 'Core exposed a guessed generic callback executor.' );
$cancel = $scheduler->plan( array( 'provider' => 'wp_cron', 'action' => 'cancel', 'target_id' => $row['target_id'], 'expected_snapshot' => $row['snapshot'] ) );
$native = $scheduler->dispatch( $cancel );
operations_assert( 'native_acknowledged' === $scheduler->observe( $cancel, $native, false )['outcome'] && 2 === $GLOBALS['operations_dispatches'], 'Exact native cancellation failed.' );
$blocked = $scheduler->plan( array( 'provider' => 'wp_cron', 'action' => 'schedule', 'hook' => 'wp_maybe_auto_update', 'timestamp' => 12345 ) );
operations_assert( is_wp_error( $blocked ), 'Core upgrade workflow reached scheduling adapter.' );
$object = $scheduler->plan( array( 'provider' => 'wp_cron', 'action' => 'schedule', 'hook' => 'operations_fixture', 'args' => array( new stdClass() ), 'timestamp' => 12345 ) );
operations_assert( is_wp_error( $object ), 'Object arguments reached native scheduling.' );
$recurring = $scheduler->plan( array( 'provider' => 'wp_cron', 'action' => 'schedule', 'hook' => 'operations_fixture', 'timestamp' => 12346, 'schedule_type' => 'interval', 'recurrence' => 'custom_fixture' ) );
operations_assert( ! is_wp_error( $recurring ) && true === $scheduler->dispatch( $recurring ), 'Installed native recurrence was not reused.' );
$rows = $scheduler->read( array( 'action' => 'list', 'provider' => 'wp_cron', 'limit' => 1 ) );
operations_assert( 'custom_fixture' === $rows['items'][0]['recurrence'], 'Recurrence metadata lost.' );

operations_assert( ! defined( Cron_Run_Claim::class . '::MAX_RECEIPTS' ), 'Permanent Cron receipt lifetime ceiling remains.' );
echo 'Operations byte/native adapter regressions passed: ' . $assertions . "\n";
