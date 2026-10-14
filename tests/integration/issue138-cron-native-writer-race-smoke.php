<?php
/**
 * Deterministic real WP-Cron native-writer races against the Bridge-only lock.
 *
 * An unrelated installed WordPress provider can write the same hook/args without
 * taking Admission_Lock('cron'). Inject that write within Core schedule/unschedule
 * filters, then require honest unknown outcomes and inspect the final native
 * option. No callback is ever dispatched; only this fixture hook is cleared.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Admission_Lock;
use WP_AI_Bridge\Support\Settings;

function wpai138_race_assert( $condition, $why ) {
	if ( ! $condition ) {
		throw new RuntimeException( $why );
	}
}

/** @return array<int,array<string,mixed>> */
function wpai138_race_native_events( $hook, $args ) {
	$crons = _get_cron_array();
	wpai138_race_assert( is_array( $crons ), 'Core Cron inventory could not be inspected.' );
	$key    = md5( serialize( $args ) );
	$result = array();
	foreach ( $crons as $when => $hooks ) {
		if ( isset( $hooks[ $hook ][ $key ] ) ) {
			$event = $hooks[ $hook ][ $key ];
			wpai138_race_assert( $event['args'] === $args, 'Fixture encountered foreign native Cron arguments.' );
			$result[ (int) $when ] = $event;
		}
	}
	return $result;
}

/** @return string */
function wpai138_race_snapshot( $reader ) {
	$read = $reader->execute( array( 'action' => 'list', 'limit' => 1 ) );
	wpai138_race_assert(
		! is_wp_error( $read ) && is_string( $read['snapshot'] ?? null ) &&
		1 === preg_match( '/^[a-f0-9]{64}$/D', $read['snapshot'] ),
		'Exact native Cron inventory snapshot is unavailable.'
	);
	return $read['snapshot'];
}

/** @return string */
function wpai138_race_event_id( $reader, $hook ) {
	$found = $reader->execute( array( 'action' => 'list', 'hook' => $hook, 'limit' => 5 ) );
	wpai138_race_assert( ! is_wp_error( $found ) && 1 === $found['total'], 'Expected exactly one initial fixture event.' );
	return $found['items'][0]['event_id'];
}

$actor         = get_current_user_id();
$original      = get_option( Settings::OPTION_NAME, false );
$hook          = 'wpai138_external_race_' . get_current_blog_id() . '_' . bin2hex( random_bytes( 4 ) );
$args          = array( 'provider-owned-private-' . bin2hex( random_bytes( 5 ) ) );
$callback_runs = 0;
$callback      = static function () use ( &$callback_runs ) {
	++$callback_runs;
};
$provider      = static function ( $profiles ) use ( $hook, $args ) {
	$profiles['race_fixture'] = array(
		'hook'                 => $hook,
		'args'                 => $args,
		'actions'              => array( 'schedule', 'reschedule', 'unschedule' ),
		'min_interval_seconds' => 300,
	);
	return $profiles;
};
$on_schedule   = null;
$on_unschedule = null;
add_action( $hook, $callback );
add_filter( 'wp_ai_bridge_cron_profiles', $provider );
try {
	wpai138_race_assert( $actor > 0, 'Native race fixture requires authenticated WordPress admin.' );
	$ability = wp_get_ability( 'wp-ai-bridge/cron-events-manage' );
	$reader  = wp_get_ability( 'wp-ai-bridge/cron-events-read' );
	wpai138_race_assert( $ability instanceof WP_Ability && $reader instanceof WP_Ability, 'Cron Abilities were not registered.' );
	$groups = ( new Settings() )->defaults();
	$groups[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $groups, false );

	// A coordinator-owned lock does not silently cause a second Bridge
	// execution to perform an effect, and must not claim rollback.
	$lock = Admission_Lock::acquire( 'cron' );
	wpai138_race_assert( $lock instanceof Admission_Lock, 'Cron coordinator was not available for lock-busy regression.' );
	try {
		$blocked = $ability->execute(
			array(
				'action'            => 'schedule',
				'profile_id'        => 'race_fixture',
				'timestamp'         => time() + 5400,
				'recurrence'        => 'hourly',
				'expected_snapshot' => wpai138_race_snapshot( $reader ),
			)
		);
		wpai138_race_assert(
			is_wp_error( $blocked ) && 'cron_lock_busy' === $blocked->get_error_code() &&
			! wpai138_race_native_events( $hook, $args ),
			'Bridge lock busy outcome falsely reported or changed the native schedule.'
		);
	} finally {
		$lock->release();
	}

	// RACE A: a Core provider schedules a second event during Bridge's
	// wp_schedule_event call, WITHOUT joining the Bridge advisory lock.
	$when         = time() + 7200;
	$outside_when = $when + 4 * HOUR_IN_SECONDS;
	$armed        = true;
	$injected     = false;
	$on_schedule  = static function ( $event ) use ( $hook, $args, $when, $outside_when, &$armed, &$injected ) {
		if ( $armed && $event->hook === $hook && $event->timestamp === $when ) {
			$armed    = false;
			$injected = wp_schedule_event( $outside_when, 'daily', $hook, $args, true );
		}
		return $event;
	};
	add_filter( 'schedule_event', $on_schedule );
	$scheduled = $ability->execute(
		array(
			'action'            => 'schedule',
			'profile_id'        => 'race_fixture',
			'timestamp'         => $when,
			'recurrence'        => 'hourly',
			'expected_snapshot' => wpai138_race_snapshot( $reader ),
		)
	);
	remove_filter( 'schedule_event', $on_schedule );
	$on_schedule = null;
	$actual      = wpai138_race_native_events( $hook, $args );
	wpai138_race_assert(
		true === $injected && is_wp_error( $scheduled ) && 'cron_outcome_unknown' === $scheduled->get_error_code() &&
		2 === count( $actual ) && isset( $actual[ $when ], $actual[ $outside_when ] ) &&
		0 === $callback_runs,
		'Concurrent native schedule changed the exact provider profile but Bridge falsely reported success or executed a callback.'
	);
	wp_clear_scheduled_hook( $hook, $args );
	wpai138_race_assert( ! wpai138_race_native_events( $hook, $args ), 'Fixture schedule race left data after explicit test cleanup.' );
	echo "PASS: #138 uncoordinated native writer during schedule never receives a clean success receipt.\n";

	// RACE B: Bridge creates a new event for reschedule while a native
	// provider inserts a third event. Removing the old event must not make
	// the final two-event state look like a successful exact reschedule.
	$old          = time() + 8000;
	$new          = $old + 2 * HOUR_IN_SECONDS;
	$outside_when = $old + 4 * HOUR_IN_SECONDS;
	wpai138_race_assert( true === wp_schedule_event( $old, 'hourly', $hook, $args, true ), 'Native reschedule baseline failed.' );
	$old_id  = wpai138_race_event_id( $reader, $hook );
	$armed   = true;
	$injected = false;
	$on_schedule = static function ( $event ) use ( $hook, $args, $new, $outside_when, &$armed, &$injected ) {
		if ( $armed && $event->hook === $hook && $event->timestamp === $new ) {
			$armed    = false;
			$injected = wp_schedule_event( $outside_when, 'daily', $hook, $args, true );
		}
		return $event;
	};
	add_filter( 'schedule_event', $on_schedule );
	$rescheduled = $ability->execute(
		array(
			'action'            => 'reschedule',
			'profile_id'        => 'race_fixture',
			'event_id'          => $old_id,
			'timestamp'         => $new,
			'recurrence'        => 'daily',
			'expected_snapshot' => wpai138_race_snapshot( $reader ),
		)
	);
	remove_filter( 'schedule_event', $on_schedule );
	$on_schedule = null;
	$actual      = wpai138_race_native_events( $hook, $args );
	wpai138_race_assert(
		true === $injected && is_wp_error( $rescheduled ) && 'cron_outcome_unknown' === $rescheduled->get_error_code() &&
		2 === count( $actual ) && ! isset( $actual[ $old ] ) && isset( $actual[ $new ], $actual[ $outside_when ] ) &&
		0 === $callback_runs,
		'Uncoordinated native reschedule race was given a false successful postcondition.'
	);
	wp_clear_scheduled_hook( $hook, $args );
	wpai138_race_assert( ! wpai138_race_native_events( $hook, $args ), 'Fixture reschedule race left data after explicit test cleanup.' );
	echo "PASS: #138 uncoordinated native writer during reschedule reports uncertain partial effect, not rollback.\n";

	// RACE C: an ordinary provider writes a new event just before the
	// Bridge Core unschedule reads its option. Its own selected old event
	// disappears, but the fixed provider profile is not empty afterward.
	$old          = time() + 9000;
	$outside_when = $old + 3 * HOUR_IN_SECONDS;
	wpai138_race_assert( true === wp_schedule_event( $old, 'hourly', $hook, $args, true ), 'Native unschedule baseline failed.' );
	$old_id       = wpai138_race_event_id( $reader, $hook );
	$armed        = true;
	$injected     = false;
	$on_unschedule = static function ( $pre, $timestamp, $name, $native_args ) use ( $hook, $args, $old, $outside_when, &$armed, &$injected ) {
		if ( $armed && $timestamp === $old && $name === $hook && $native_args === $args ) {
			$armed    = false;
			$injected = wp_schedule_event( $outside_when, 'daily', $hook, $args, true );
		}
		return $pre;
	};
	add_filter( 'pre_unschedule_event', $on_unschedule, 10, 4 );
	$unscheduled = $ability->execute(
		array(
			'action'            => 'unschedule',
			'profile_id'        => 'race_fixture',
			'event_id'          => $old_id,
			'expected_snapshot' => wpai138_race_snapshot( $reader ),
		)
	);
	remove_filter( 'pre_unschedule_event', $on_unschedule, 10 );
	$on_unschedule = null;
	$actual        = wpai138_race_native_events( $hook, $args );
	wpai138_race_assert(
		true === $injected && is_wp_error( $unscheduled ) && 'cron_outcome_unknown' === $unscheduled->get_error_code() &&
		1 === count( $actual ) && ! isset( $actual[ $old ] ) && isset( $actual[ $outside_when ] ) &&
		0 === $callback_runs,
		'Uncoordinated native unschedule race left a profile event but received clean success.'
	);
	echo "PASS: #138 uncoordinated native writer during unschedule reports unknown while another profile event remains.\n";
	wpai138_race_assert( 0 === $callback_runs, 'A Cron callback ran during race fixtures.' );
} finally {
	if ( null !== $on_schedule ) {
		remove_filter( 'schedule_event', $on_schedule );
	}
	if ( null !== $on_unschedule ) {
		remove_filter( 'pre_unschedule_event', $on_unschedule, 10 );
	}
	wp_clear_scheduled_hook( $hook, $args );
	remove_filter( 'wp_ai_bridge_cron_profiles', $provider );
	remove_action( $hook, $callback );
	wp_set_current_user( $actor );
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
}
