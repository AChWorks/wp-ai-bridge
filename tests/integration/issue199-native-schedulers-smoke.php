<?php
/**
 * Actual native Core/Action Scheduler administration smoke.
 *
 * Requires the disposable WordPress harness; never loads an optional provider
 * itself or executes host/production hooks. All effects target unique fixtures.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Scheduler_Abilities;
use WP_AI_Bridge\Support\Hook_Dispatch_Recovery;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

function wpai199_scheduler_assert( $condition, $reason ) {
	if ( ! $condition ) { throw new RuntimeException( $reason ); }
}

$settings = new Settings();
$actor = get_current_user_id();
$original = get_option( Settings::OPTION_NAME, false );
$original_admin = get_option( Settings::ADMINISTRATION_OPTION_NAME, false );
$hook = 'wpai199_scheduler_' . bin2hex( random_bytes( 6 ) );
$group = $hook;
$counter = $hook . '_counter';
$failure_hook = $hook . '_failure';
$effect = static function ( $marker = '' ) use ( $counter ) { update_option( $counter, (int) get_option( $counter, 0 ) + 1, false ); };
$partial_failure = static function () use ( $counter ) {
	update_option( $counter, (int) get_option( $counter, 0 ) + 1, false );
	throw new RuntimeException( 'Synthetic scheduler callback failure after a private fixture effect.' );
};
add_action( $hook, $effect, 10, 1 );
add_action( $failure_hook, $partial_failure );
$provider = new Scheduler_Abilities( new Permissions( $settings ), new Mutation_Log() );
$at = time() + 3600;
$args = array( 'private-fixture-marker-' . bin2hex( random_bytes( 4 ) ) );

try {
	$grants = $settings->defaults();
	$grants[ Settings::GROUP_SITE_CONFIG ] = 1;
	$grants[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	$grants[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	update_option( Settings::ADMINISTRATION_OPTION_NAME, $settings->administration_defaults(), false );
	wpai199_scheduler_assert( is_wp_error( $provider->read( array( 'action' => 'providers' ) ) ), 'Default-off scheduler grant was bypassed.' );
	$admin = $settings->administration_defaults();
	$admin[ Settings::GROUP_SCHEDULER_MANAGE ] = 1;
	$admin[ Settings::GROUP_SCHEDULER_RUN ] = 1;
	update_option( Settings::ADMINISTRATION_OPTION_NAME, $admin, false );
	$providers = $provider->read( array( 'action' => 'providers' ) );
	wpai199_scheduler_assert( ! is_wp_error( $providers ), 'Current native administrator could not discover schedulers.' );
	$core_request = array( 'action' => 'schedule', 'provider' => 'wp_cron', 'hook' => $hook, 'args' => $args,
		'timestamp' => $at, 'schedule_type' => 'single', 'operation_id' => $hook . '-core', 'confirm' => true );
	$core = $provider->manage( $core_request );
	wpai199_scheduler_assert( ! is_wp_error( $core ) && $core['dispatch_attempted'] && 'native_acknowledged' === $core['receipt']['result']['outcome'], 'Native Core schedule did not acknowledge/read back.' );
	$again = $provider->manage( $core_request );
	wpai199_scheduler_assert( ! is_wp_error( $again ) && $again['historical'] && ! $again['dispatch_attempted'], 'Historical schedule repeated native dispatch.' );
	$rows = $provider->read( array( 'action' => 'list', 'provider' => 'wp_cron', 'hook' => $hook, 'limit' => 10 ) );
	wpai199_scheduler_assert( ! is_wp_error( $rows ) && 1 === count( $rows['items'] ) && false === strpos( wp_json_encode( $rows ), $args[0] ), 'Native Core metadata leaked arguments or lost exact event.' );
	$row = $rows['items'][0];
	$unsupported = $provider->manage( array( 'action' => 'run', 'provider' => 'wp_cron', 'target_id' => $row['target_id'],
		'expected_snapshot' => $row['snapshot'], 'operation_id' => $hook . '-core-run', 'confirm' => true ) );
	wpai199_scheduler_assert( is_wp_error( $unsupported ) && 'scheduler_core_targeted_runner_required' === $unsupported->get_error_code() && 0 === (int) get_option( $counter, 0 ), 'Ordinary Core path exposed generic callback execution.' );
	$cancel = $provider->manage( array( 'action' => 'cancel', 'provider' => 'wp_cron', 'target_id' => $row['target_id'],
		'expected_snapshot' => $row['snapshot'], 'operation_id' => $hook . '-core-cancel', 'confirm' => true ) );
	wpai199_scheduler_assert( ! is_wp_error( $cancel ) && false === wp_get_scheduled_event( $hook, $args, $at ), 'Exact native Core cancellation failed.' );

	$as_ready = class_exists( 'ActionScheduler', false ) && ActionScheduler::is_initialized();
	if ( ! $as_ready ) {
		wpai199_scheduler_assert( is_wp_error( $provider->read( array( 'action' => 'list', 'provider' => 'action_scheduler' ) ) ), 'Absent Action Scheduler simulated a queue.' );
		echo "PASS: #199 native Core scheduler and honest absent optional provider.\n";
	} else {
		foreach ( array( 'single', 'async', 'interval', 'cron' ) as $type ) {
			$request = array( 'action' => 'schedule', 'provider' => 'action_scheduler', 'hook' => $hook, 'args' => $args,
				'group' => $group, 'schedule_type' => $type, 'operation_id' => $hook . '-as-' . $type, 'confirm' => true );
			if ( 'async' !== $type ) { $request['timestamp'] = $at; }
			if ( 'interval' === $type ) { $request['interval'] = 3600; }
			if ( 'cron' === $type ) { $request['cron'] = '0 * * * *'; }
			$scheduled = $provider->manage( $request );
			wpai199_scheduler_assert( ! is_wp_error( $scheduled ) && 'native_acknowledged' === $scheduled['receipt']['result']['outcome'], 'Actual Action Scheduler scheduling type failed: ' . $type );
			$id = $scheduled['receipt']['result']['target_id'];
			$selected = $provider->read( array( 'action' => 'get', 'provider' => 'action_scheduler', 'target_id' => $id ) );
			wpai199_scheduler_assert( ! is_wp_error( $selected ), 'Actual Action Scheduler exact get failed.' );
			$action = 'single' === $type || 'interval' === $type ? 'run' : 'cancel';
			$managed = $provider->manage( array( 'action' => $action, 'provider' => 'action_scheduler', 'target_id' => $id,
				'expected_snapshot' => $selected['item']['snapshot'], 'operation_id' => $hook . '-as-effect-' . $type, 'confirm' => true ) );
			wpai199_scheduler_assert( ! is_wp_error( $managed ) && ( 'run' !== $action || 'provider_status_observed' === $managed['receipt']['result']['outcome'] ), 'Void provider processor was misclassified or cancellation failed.' );
			$status = $provider->manage( array( 'action' => 'status', 'provider' => 'action_scheduler', 'operation_id' => $hook . '-as-effect-' . $type ) );
			wpai199_scheduler_assert( ! is_wp_error( $status ) && $status['historical'] && ! $status['dispatch_attempted'], 'Provider status recovery dispatched again.' );
		}
		wpai199_scheduler_assert( 2 === (int) get_option( $counter, 0 ), 'Actual native runner did not execute exactly the two requested fixture callbacks.' );
		$failed = $provider->manage( array( 'action' => 'schedule', 'provider' => 'action_scheduler', 'hook' => $failure_hook,
			'group' => $group, 'timestamp' => $at, 'operation_id' => $hook . '-failure-schedule', 'confirm' => true ) );
		wpai199_scheduler_assert( ! is_wp_error( $failed ), 'Partial-failure fixture could not be scheduled.' );
		$id = $failed['receipt']['result']['target_id'];
		$selected = $provider->read( array( 'action' => 'get', 'provider' => 'action_scheduler', 'target_id' => $id ) );
		$baseline = Hook_Dispatch_Recovery::snapshot();
		$failed_run = $provider->manage( array( 'action' => 'run', 'provider' => 'action_scheduler', 'target_id' => $id,
			'expected_snapshot' => $selected['item']['snapshot'], 'operation_id' => $hook . '-failure-run', 'confirm' => true ) );
		wpai199_scheduler_assert( ! is_wp_error( $failed_run ) && 'provider_failed' === $failed_run['receipt']['result']['outcome'] &&
			'unverified' === $failed_run['receipt']['result']['business_effect'] && 3 === (int) get_option( $counter, 0 ), 'Caught provider failure hid a partial callback effect or claimed success.' );
		wpai199_scheduler_assert( Hook_Dispatch_Recovery::snapshot()->stack === $baseline->stack, 'Internally caught Action Scheduler callback exception leaked WP_Hook state.' );
		echo "PASS: #199 actual Core/Action Scheduler native lifecycle, recurrence and partial failure.\n";
	}
	$admin[ Settings::GROUP_SCHEDULER_MANAGE ] = 0;
	update_option( Settings::ADMINISTRATION_OPTION_NAME, $admin, false );
	wpai199_scheduler_assert( is_wp_error( $provider->manage( array( 'action' => 'status', 'provider' => 'wp_cron', 'operation_id' => $hook . '-core' ) ) ), 'Revoked scheduler grant exposed historical receipt.' );
} finally {
	wp_set_current_user( $actor );
	wp_clear_scheduled_hook( $hook, $args );
	remove_action( $hook, $effect, 10 );
	remove_action( $failure_hook, $partial_failure, 10 );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $hook, array(), $group );
		as_unschedule_all_actions( $failure_hook, array(), $group );
	}
	delete_option( $counter );
	if ( false === $original ) { delete_option( Settings::OPTION_NAME ); } else { update_option( Settings::OPTION_NAME, $original, false ); }
	if ( false === $original_admin ) { delete_option( Settings::ADMINISTRATION_OPTION_NAME ); } else { update_option( Settings::ADMINISTRATION_OPTION_NAME, $original_admin, false ); }
}
