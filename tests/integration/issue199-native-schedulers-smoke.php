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
$context_hook = $hook . '_context';
$context_change = '';
$effect = static function ( $marker = '' ) use ( $counter ) { update_option( $counter, (int) get_option( $counter, 0 ) + 1, false ); };
$partial_failure = static function () use ( $counter ) {
	update_option( $counter, (int) get_option( $counter, 0 ) + 1, false );
	throw new RuntimeException( 'Synthetic scheduler callback failure after a private fixture effect.' );
};
$context_effect = static function () use ( $effect, &$context_change ) {
	$effect();
	if ( 'user' === $context_change ) {
		wp_set_current_user( 0 );
	} else {
		$groups = array( 'grant' => Settings::GROUP_SCHEDULER_MANAGE, 'run' => Settings::GROUP_SCHEDULER_RUN,
			'destructive' => Settings::GROUP_USERS_DESTRUCTIVE, 'code' => Settings::GROUP_CODE_EXTENSIONS );
		$option = in_array( $context_change, array( 'grant', 'run' ), true ) ? Settings::ADMINISTRATION_OPTION_NAME : Settings::OPTION_NAME;
		$grants = get_option( $option, array() );
		$grants[ $groups[ $context_change ] ] = 0;
		update_option( $option, $grants, false );
	}
};
add_action( $context_hook, $context_effect );
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
		foreach ( array( 'user', 'grant', 'run', 'destructive', 'code' ) as $context_change ) {
			$scheduled = $provider->manage( array( 'action' => 'schedule', 'provider' => 'action_scheduler', 'hook' => $context_hook,
				'group' => $group, 'timestamp' => $at, 'operation_id' => $hook . '-context-' . $context_change, 'confirm' => true ) );
			wpai199_scheduler_assert( ! is_wp_error( $scheduled ), 'Context-changing native callback could not be scheduled.' );
			$id = $scheduled['receipt']['result']['target_id'];
			$selected = $provider->read( array( 'action' => 'get', 'provider' => 'action_scheduler', 'target_id' => $id ) );
			wpai199_scheduler_assert( ! is_wp_error( $selected ), 'Context-changing native action could not be selected.' );
			$run_request = array( 'action' => 'run', 'provider' => 'action_scheduler', 'target_id' => $id,
				'expected_snapshot' => $selected['item']['snapshot'], 'operation_id' => $hook . '-context-run-' . $context_change, 'confirm' => true );
			$audit_before = get_option( Mutation_Log::OPTION_NAME, array() );
			$effects_before = (int) get_option( $counter, 0 );
			try {
				$changed = $provider->manage( $run_request );
			} finally {
				wp_set_current_user( $actor );
				update_option( Settings::OPTION_NAME, $grants, false );
				update_option( Settings::ADMINISTRATION_OPTION_NAME, $admin, false );
			}
			wpai199_scheduler_assert( is_wp_error( $changed ) && 'scheduler_context_changed' === $changed->get_error_code() &&
				true === $changed->get_error_data()['dispatch_attempted'] && 'unknown' === ( $changed->get_error_data()['outcome'] ?? null ),
				'Native callback context loss did not report an explicit unknown dispatched outcome.' );
			wpai199_scheduler_assert( $effects_before + 1 === (int) get_option( $counter, 0 ) &&
				$audit_before === get_option( Mutation_Log::OPTION_NAME, array() ), 'Native callback context loss hid its effect or wrote an unauthorized follow-up audit.' );
			$status = $provider->manage( array( 'action' => 'status', 'provider' => 'action_scheduler', 'operation_id' => $run_request['operation_id'] ) );
			wpai199_scheduler_assert( ! is_wp_error( $status ) && 'dispatch_armed' === $status['state'] && null === $status['receipt']['result'],
				'Native callback context loss completed or discarded its armed fence.' );
			$again = $provider->manage( $run_request );
			// The real provider may reject the now-complete target as stale
			// before journal lookup. Either result must preserve no redispatch.
			wpai199_scheduler_assert( ( is_wp_error( $again ) ? in_array( $again->get_error_code(), array( 'scheduler_target_stale', 'scheduler_target_not_pending_or_claimed' ), true ) :
				( ! $again['dispatch_attempted'] && 'dispatch_armed' === $again['state'] ) ) &&
				$effects_before + 1 === (int) get_option( $counter, 0 ), 'Context-changing native callback replayed after authority restoration.' );
		}
		echo "PASS: #199 actual Core/Action Scheduler native lifecycle, recurrence and partial failure and post-effect context fencing.\n";
	}
	$admin[ Settings::GROUP_SCHEDULER_MANAGE ] = 0;
	update_option( Settings::ADMINISTRATION_OPTION_NAME, $admin, false );
	wpai199_scheduler_assert( is_wp_error( $provider->manage( array( 'action' => 'status', 'provider' => 'wp_cron', 'operation_id' => $hook . '-core' ) ) ), 'Revoked scheduler grant exposed historical receipt.' );
} finally {
	wp_set_current_user( $actor );
	wp_clear_scheduled_hook( $hook, $args );
	remove_action( $hook, $effect, 10 );
	remove_action( $failure_hook, $partial_failure, 10 );
	remove_action( $context_hook, $context_effect, 10 );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $hook, array(), $group );
		as_unschedule_all_actions( $failure_hook, array(), $group );
		as_unschedule_all_actions( $context_hook, array(), $group );
	}
	delete_option( $counter );
	if ( false === $original ) { delete_option( Settings::OPTION_NAME ); } else { update_option( Settings::OPTION_NAME, $original, false ); }
	if ( false === $original_admin ) { delete_option( Settings::ADMINISTRATION_OPTION_NAME ); } else { update_option( Settings::ADMINISTRATION_OPTION_NAME, $original_admin, false ); }
}
