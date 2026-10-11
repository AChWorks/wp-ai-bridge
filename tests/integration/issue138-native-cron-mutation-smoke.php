<?php
/**
 * Real Core WP-Cron lifecycle, official Adapter, and default-off permissions.
 *
 * This fixture never runs a Cron callback and restores the native option.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai138_manage_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$actor        = get_current_user_id();
$settings     = new Settings();
$original     = get_option( Settings::OPTION_NAME, false );
$hook         = 'wpai138_managed_fixture_' . get_current_blog_id();
$args         = array( 'secret-wpai138-' . bin2hex( random_bytes( 6 ) ) );
$when         = time() + 5400;
$rescheduled  = $when + 1800;
$before       = get_option( 'cron', array() );
$side_effects = 0;
$callback     = static function () use ( &$side_effects ) {
	++$side_effects;
};
$provider     = static function ( $profiles ) use ( $hook, $args ) {
	$profiles['fixture'] = array(
		'hook'                 => $hook,
		'args'                 => $args,
		'actions'              => array( 'schedule', 'reschedule', 'unschedule' ),
		'min_interval_seconds' => 300,
	);
	return $profiles;
};
add_action( $hook, $callback );
add_filter( 'wp_ai_bridge_cron_profiles', $provider );

try {
	wpai138_manage_assert( $actor > 0, 'Native Cron mutation requires a WordPress administrator fixture.' );
	$ability = wp_get_ability( 'wp-ai-bridge/cron-events-manage' );
	$reader  = wp_get_ability( 'wp-ai-bridge/cron-events-read' );
	wpai138_manage_assert( $ability instanceof WP_Ability && $reader instanceof WP_Ability, 'Draft native Cron Abilities were not registered.' );

	$groups = $settings->defaults();
	update_option( Settings::OPTION_NAME, $groups, false );
	wpai138_manage_assert( true !== $ability->check_permissions( array( 'action' => 'profiles' ) ), 'Default-off Cron mutation was exposed.' );
	wpai138_manage_assert( is_wp_error( $ability->execute( array( 'action' => 'profiles' ) ) ), 'Direct Cron callback bypassed default-off access.' );
	$groups[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $groups, false );

	$catalog = $ability->execute( array( 'action' => 'profiles' ) );
	wpai138_manage_assert( ! is_wp_error( $catalog ) && 'fixture' === $catalog['profiles'][0]['profile_id'], 'Provider-managed Cron profile was not discoverable.' );
	wpai138_manage_assert( false === strpos( wp_json_encode( $catalog ), $args[0] ), 'Profile listing leaked WordPress provider secrets.' );
	$reader_initial = $reader->execute(
		array(
			'action' => 'list',
			'hook'   => $hook,
		)
	);
	wpai138_manage_assert( ! is_wp_error( $reader_initial ) && 0 === $reader_initial['total'], 'Cron fixture hook unexpectedly had an existing event.' );
	$initial_snapshot = $reader_initial['snapshot'];

	$malicious = $ability->execute(
		array(
			'action'            => 'schedule',
			'profile_id'        => 'unregistered_hook',
			'timestamp'         => $when,
			'recurrence'        => '',
			'expected_snapshot' => $initial_snapshot,
		)
	);
	wpai138_manage_assert( is_wp_error( $malicious ) && 'cron_profile_unsupported' === $malicious->get_error_code(), 'Caller could schedule an arbitrary hook.' );
	$invalid = $ability->execute(
		array(
			'action'            => 'schedule',
			'profile_id'        => 'fixture',
			'timestamp'         => $when,
			'recurrence'        => '',
			'expected_snapshot' => $initial_snapshot,
			'args'              => array( 'untrusted' ),
		)
	);
	wpai138_manage_assert( is_wp_error( $invalid ) && 'cron_mutation_invalid' === $invalid->get_error_code(), 'Caller could inject runtime Cron arguments.' );
	$short = $ability->execute(
		array(
			'action'            => 'schedule',
			'profile_id'        => 'fixture',
			'timestamp'         => $when,
			'recurrence'        => 'every_minute',
			'expected_snapshot' => $initial_snapshot,
		)
	);
	wpai138_manage_assert( is_wp_error( $short ), 'Unknown or excessive Cron recurrence was accepted.' );
	$run = $ability->execute(
		array(
			'action'     => 'run_now',
			'profile_id' => 'fixture',
		)
	);
	wpai138_manage_assert( is_wp_error( $run ) && 'cron_run_now_unsupported' === $run->get_error_code() && 0 === $side_effects, 'Generic callback injection or unverified run-now became available.' );

	$scheduled = $ability->execute(
		array(
			'action'            => 'schedule',
			'profile_id'        => 'fixture',
			'timestamp'         => $when,
			'recurrence'        => 'hourly',
			'expected_snapshot' => $initial_snapshot,
		)
	);
	wpai138_manage_assert( ! is_wp_error( $scheduled ) && 'scheduled' === $scheduled['status'], 'Native recurring event was not scheduled through provider profile.' );
	$native = wp_get_scheduled_event( $hook, $args, $when );
	wpai138_manage_assert( $native instanceof stdClass && 'hourly' === $native->schedule && $args === $native->args, 'Native registered arguments or recurrence were not preserved.' );
	$event_id = $scheduled['event_id'];
	$current  = $reader->execute(
		array(
			'action'   => 'get',
			'event_id' => $event_id,
		)
	);
	wpai138_manage_assert( ! is_wp_error( $current ) && $event_id === $current['items'][0]['event_id'], 'Mutator event identity did not match native reader.' );
	wpai138_manage_assert( false === strpos( wp_json_encode( $scheduled ), $args[0] ), 'Mutation receipt disclosed private callback arguments.' );

	$duplicate = $ability->execute(
		array(
			'action'            => 'schedule',
			'profile_id'        => 'fixture',
			'timestamp'         => $when + 5 * 3600,
			'recurrence'        => 'daily',
			'expected_snapshot' => $scheduled['snapshot'],
		)
	);
	wpai138_manage_assert( is_wp_error( $duplicate ) && 'cron_duplicate_profile' === $duplicate->get_error_code(), 'Multiple Cron events for the same fixed provider profile were admitted.' );
	$stale = $ability->execute(
		array(
			'action'            => 'unschedule',
			'profile_id'        => 'fixture',
			'event_id'          => $event_id,
			'expected_snapshot' => $initial_snapshot,
		)
	);
	wpai138_manage_assert( is_wp_error( $stale ) && 'cron_stale_snapshot' === $stale->get_error_code(), 'Stale snapshot was accepted for cancellation.' );

	$changed = $ability->execute(
		array(
			'action'            => 'reschedule',
			'profile_id'        => 'fixture',
			'event_id'          => $event_id,
			'expected_snapshot' => $scheduled['snapshot'],
			'timestamp'         => $rescheduled,
			'recurrence'        => 'daily',
		)
	);
	wpai138_manage_assert( ! is_wp_error( $changed ) && 'rescheduled' === $changed['status'], 'Native exact reschedule failed.' );
	wpai138_manage_assert( false === wp_get_scheduled_event( $hook, $args, $when ), 'Old exact Cron event was not removed after reschedule.' );
	$updated = wp_get_scheduled_event( $hook, $args, $rescheduled );
	wpai138_manage_assert( $updated instanceof stdClass && 'daily' === $updated->schedule, 'New native recurring event did not retain modified recurrence.' );
	$cancelled = $ability->execute(
		array(
			'action'            => 'unschedule',
			'profile_id'        => 'fixture',
			'event_id'          => $changed['event_id'],
			'expected_snapshot' => $changed['snapshot'],
		)
	);
	wpai138_manage_assert( ! is_wp_error( $cancelled ) && 'unscheduled' === $cancelled['status'] && false === wp_get_scheduled_event( $hook, $args, $rescheduled ), 'Native exact unschedule did not work.' );

	// Native recurring and one-off both work without any caller-supplied args.
	$single = $ability->execute(
		array(
			'action'            => 'schedule',
			'profile_id'        => 'fixture',
			'timestamp'         => $when,
			'recurrence'        => '',
			'expected_snapshot' => $cancelled['snapshot'],
		)
	);
	wpai138_manage_assert( ! is_wp_error( $single ) && false === wp_get_scheduled_event( $hook, $args, $when )->schedule, 'Native exact one-off schedule was not preserved.' );
	$groups[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $groups, false );
	wpai138_manage_assert(
		is_wp_error(
			$ability->execute(
				array(
					'action'            => 'unschedule',
					'profile_id'        => 'fixture',
					'event_id'          => $single['event_id'],
					'expected_snapshot' => $single['snapshot'],
				)
			)
		),
		'Permission revocation failed for already issued event identity.'
	);
	wpai138_manage_assert( wp_get_scheduled_event( $hook, $args, $when ) instanceof stdClass, 'Revocation mutated live Cron unexpectedly.' );
	$groups[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $groups, false );
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	wpai138_manage_assert( $adapter instanceof WP_Ability, 'Official Adapter Ability was unavailable.' );
	$via_adapter = $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/cron-events-manage',
			'parameters'   => array( 'action' => 'profiles' ),
		)
	);
	wpai138_manage_assert( ! is_wp_error( $via_adapter ) && true === ( $via_adapter['success'] ?? false ) && 'fixture' === ( $via_adapter['data']['profiles'][0]['profile_id'] ?? '' ), 'Official MCP Adapter failed to delegate Cron profile discovery.' );
	wpai138_manage_assert( 0 === $side_effects, 'Callback executed unexpectedly during a management operation.' );
	echo "PASS: #138 Core Cron native provider-owned schedule, exact reschedule/unschedule, one-off, default-off, revocation, private args and Adapter delegation.\n";
} finally {
	wp_set_current_user( $actor );
	wp_clear_scheduled_hook( $hook, $args );
	remove_action( $hook, $callback );
	remove_filter( 'wp_ai_bridge_cron_profiles', $provider );
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
}
