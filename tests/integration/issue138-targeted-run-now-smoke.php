<?php
/**
 * Real WP-Cron provider-owned run_now, persistent exact-event replay fence.
 *
 * Must run only inside disposable WordPress. No global or actual Cron runner
 * is invoked; the installed synthetic provider verifies its own durable
 * business effect and controls its normally scheduled callback.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Cron_Targeted_Runner;
use WP_AI_Bridge\Support\Settings;

function wpai138_run_assert( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
}

$admin = get_current_user_id();
$original = get_option( Settings::OPTION_NAME, false );
$hook = 'wpai138_targeted_test_' . bin2hex( random_bytes( 5 ) );
$args = array( 'fixed-provider-value' );
$when = time() + 7200;
$effects_option = 'wpai138_disposable_run_effects_' . bin2hex( random_bytes( 3 ) );
$callback_calls = 0;
$callback = static function () use ( &$callback_calls ) {
	++$callback_calls;
};
$runner = new class( $effects_option ) implements Cron_Targeted_Runner {
	public $runs = 0;
	public $verified = 0;
	public $fail_after_effect = false;
	private $option;

	public function __construct( $option ) {
		$this->option = $option;
	}

	public function run_once( $context ) {
		++$this->runs;
		$effect = hash( 'sha256', $context['site_id'] . '|' . $context['event_id'] . '|' . $context['operation_id'] );
		$stored = get_option( $this->option, array() );
		$stored[ $context['event_id'] ] = $effect;
		update_option( $this->option, $stored, false );
		if ( $this->fail_after_effect ) {
			// The effect is deliberately committed BEFORE this failure.
			return new WP_Error( 'fixture_after_effect', 'Simulated lost provider acknowledgement.' );
		}
		return array(
			'status'     => 'completed',
			'effect_ref' => $effect,
		);
	}

	public function verify( $context, $effect_ref ) {
		++$this->verified;
		$stored = get_option( $this->option, array() );
		return is_array( $stored ) &&
			isset( $stored[ $context['event_id'] ] ) &&
			hash_equals( $stored[ $context['event_id'] ], $effect_ref );
	}
};
$profile_provider = static function ( $profiles ) use ( $hook, $args, $runner ) {
	$profiles['targeted_fixture'] = array(
		'hook'                 => $hook,
		'args'                 => $args,
		'actions'              => array( 'schedule', 'unschedule', 'reschedule', 'run_now' ),
		'min_interval_seconds' => 300,
		'targeted_runner'      => $runner,
		'runner_revision'      => 'fixture-v1',
		'run_budget_seconds'   => 3,
		'run_destructive'      => false,
	);
	$profiles['no_runner'] = array(
		'hook'                 => $hook,
		'args'                 => array( 'other-provider-args' ),
		'actions'              => array( 'schedule', 'unschedule', 'reschedule' ),
	);
	$profiles['destructive_runner'] = array(
		'hook'                 => $hook,
		'args'                 => array( 'destructive-provider-args' ),
		'actions'              => array( 'schedule', 'unschedule', 'run_now' ),
		'targeted_runner'      => $runner,
		'runner_revision'      => 'fixture-destructive-v1',
		'run_budget_seconds'   => 3,
		'run_destructive'      => true,
	);
	return $profiles;
};
add_action( $hook, $callback, 10, 1 );
add_filter( 'wp_ai_bridge_cron_profiles', $profile_provider );
try {
	wpai138_run_assert( $admin > 0, 'Authenticated administrator is required.' );
	$ability = wp_get_ability( 'wp-ai-bridge/cron-events-manage' );
	$reader = wp_get_ability( 'wp-ai-bridge/cron-events-read' );
	wpai138_run_assert( $ability instanceof WP_Ability && $reader instanceof WP_Ability, 'Cron Abilities not installed.' );

	$grants = ( new Settings() )->defaults();
	$grants[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$profiles = $ability->execute( array( 'action' => 'profiles' ) );
	wpai138_run_assert(
		! is_wp_error( $profiles ) && count( $profiles['profiles'] ) >= 2 &&
		false === strpos( wp_json_encode( $profiles ), 'fixed-provider-value' ) &&
		false === strpos( wp_json_encode( $profiles ), 'Cron_Targeted_Runner' ),
		'Trusted runner registration was not discoverable and/or leaked provider data.'
	);
	$initial = $reader->execute( array( 'action' => 'list', 'hook' => $hook ) );
	wpai138_run_assert( ! is_wp_error( $initial ) && 0 === $initial['total'], 'Expected empty fixture hook.' );
	$scheduled = $ability->execute( array(
		'action'            => 'schedule',
		'profile_id'        => 'targeted_fixture',
		'timestamp'         => $when,
		'recurrence'        => 'hourly',
		'expected_snapshot' => $initial['snapshot'],
	) );
	wpai138_run_assert( ! is_wp_error( $scheduled ) && 'scheduled' === $scheduled['status'], 'Could not schedule fixture.' );
	$run_input = array(
		'action'            => 'run_now',
		'profile_id'        => 'targeted_fixture',
		'event_id'          => $scheduled['event_id'],
		'expected_snapshot' => $scheduled['snapshot'],
		'operation_id'      => 'test-run-once-20261014',
		'confirm'           => true,
	);

	// The weaker Site Configuration group is NOT execution consent.
	$denied = $ability->execute( $run_input );
	wpai138_run_assert( is_wp_error( $denied ) && 'cron_run_now_denied' === $denied->get_error_code() && 0 === $runner->runs, 'Site Configuration alone executed provider PHP.' );
	$grants[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );

	$not_confirmed = $run_input;
	$not_confirmed['confirm'] = false;
	$denied = $ability->execute( $not_confirmed );
	wpai138_run_assert( is_wp_error( $denied ) && 0 === $runner->runs, 'Missing explicit per-effect acknowledgment executed provider.' );
	$with_code = $run_input;
	$with_code['callback'] = 'wp_delete_user';
	$denied = $ability->execute( $with_code );
	wpai138_run_assert( is_wp_error( $denied ) && 0 === $runner->runs, 'Remote callback injection reached a provider runner.' );
	$bad_event = $run_input;
	$bad_event['event_id'] = str_repeat( 'f', 64 );
	$denied = $ability->execute( $bad_event );
	wpai138_run_assert( is_wp_error( $denied ) && 0 === $runner->runs, 'Unrecognized native event identity executed provider.' );
	$unsupported = $run_input;
	$unsupported['profile_id'] = 'no_runner';
	$denied = $ability->execute( $unsupported );
	wpai138_run_assert( is_wp_error( $denied ) && 'cron_run_now_unsupported' === $denied->get_error_code(), 'Unregistered provider was given a generic runner.' );

	$first = $ability->execute( $run_input );
	wpai138_run_assert(
		! is_wp_error( $first ) && 'provider_verified' === $first['status'] &&
		1 === $runner->runs && 1 === $runner->verified &&
		wp_get_scheduled_event( $hook, $args, $when ) instanceof stdClass &&
		0 === $callback_calls,
		'Exact targeted provider effect did not commit and verify once without calling the native callback.'
	);
	$again = $ability->execute( $run_input );
	wpai138_run_assert(
		! is_wp_error( $again ) && 'already_verified' === $again['status'] &&
		1 === $runner->runs && 2 === $runner->verified,
		'Identical replay reran the provider instead of read-only receipt verification.'
	);
	$different = $run_input;
	$different['operation_id'] = 'different-request-identity';
	$blocked = $ability->execute( $different );
	wpai138_run_assert(
		is_wp_error( $blocked ) && 'cron_run_now_outcome_unknown' === $blocked->get_error_code() &&
		1 === $runner->runs,
		'New client key re-executed an already reserved exact native event.'
	);
	echo "PASS: #138 exact provider-owned targeted run executes once with separate grants, native identity, confirmation and durable replay denial.\n";

	// A separate native event with an effect but lost acknowledgement must
	// NEVER be rerun or silently have its claim deleted.
	wp_clear_scheduled_hook( $hook, $args );
	$next = time() + 9000;
	$now = $reader->execute( array( 'action' => 'list', 'hook' => $hook ) );
	$new_event = $ability->execute( array(
		'action'            => 'schedule',
		'profile_id'        => 'targeted_fixture',
		'timestamp'         => $next,
		'recurrence'        => 'hourly',
		'expected_snapshot' => $now['snapshot'],
	) );
	wpai138_run_assert( ! is_wp_error( $new_event ), 'Could not create fresh fixture event.' );
	$uncertain = array(
		'action'            => 'run_now',
		'profile_id'        => 'targeted_fixture',
		'event_id'          => $new_event['event_id'],
		'expected_snapshot' => $new_event['snapshot'],
		'operation_id'      => 'simulated-lost-response',
		'confirm'           => true,
	);
	$runner->fail_after_effect = true;
	$unknown = $ability->execute( $uncertain );
	$still_unknown = $ability->execute( $uncertain );
	wpai138_run_assert(
		is_wp_error( $unknown ) && 'cron_run_now_outcome_unknown' === $unknown->get_error_code() &&
		is_wp_error( $still_unknown ) && 'cron_run_now_outcome_unknown' === $still_unknown->get_error_code() &&
		2 === $runner->runs && 2 === $runner->verified &&
		wp_get_scheduled_event( $hook, $args, $next ) instanceof stdClass &&
		0 === $callback_calls,
		'Durable unknown run outcome was retried or claimed as verified.'
	);
	echo "PASS: #138 provider effect with lost acknowledgement remains permanently fenced and outcome_unknown without blind retry.\n";

	$grants[ Settings::GROUP_CODE_EXTENSIONS ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	$denied = $ability->execute( $uncertain );
	wpai138_run_assert( is_wp_error( $denied ) && 'cron_run_now_denied' === $denied->get_error_code(), 'Revoked Code & Extensions permission was not enforced.' );
	$grants[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$stale = $uncertain;
	$stale['expected_snapshot'] = $initial['snapshot'];
	$denied = $ability->execute( $stale );
	wpai138_run_assert( is_wp_error( $denied ) && 'cron_stale_snapshot' === $denied->get_error_code(), 'Stale native Cron snapshot was accepted.' );
	echo "PASS: #138 targeted run revocation, stale source rejection and no arbitrary Cron callback execution.\n";
	$runner->fail_after_effect = false;
	// A provider that declares the effect destructive additionally requires
	// the independently default-off Users & Destructive grant. Mere Code &
	// Extensions consent must not silently enable it.
	$destructive_args = array( 'destructive-provider-args' );
	$destructive_when = time() + 11400;
	$source = $reader->execute( array( 'action' => 'list', 'hook' => $hook ) );
	$created = $ability->execute( array(
		'action'            => 'schedule',
		'profile_id'        => 'destructive_runner',
		'timestamp'         => $destructive_when,
		'recurrence'        => 'hourly',
		'expected_snapshot' => $source['snapshot'],
	) );
	wpai138_run_assert( ! is_wp_error( $created ), 'Could not create destructive provider fixture.' );
	$destructive_run = array(
		'action'            => 'run_now',
		'profile_id'        => 'destructive_runner',
		'event_id'          => $created['event_id'],
		'expected_snapshot' => $created['snapshot'],
		'operation_id'      => 'destructive-separate-consent',
		'confirm'           => true,
	);
	$no_destructive_grant = $ability->execute( $destructive_run );
	wpai138_run_assert(
		is_wp_error( $no_destructive_grant ) &&
		'cron_run_now_denied' === $no_destructive_grant->get_error_code() &&
		2 === $runner->runs,
		'Destructive provider effect ran without the third access group.'
	);
	$grants[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$allowed_destructive = $ability->execute( $destructive_run );
	wpai138_run_assert(
		! is_wp_error( $allowed_destructive ) &&
		'provider_verified' === $allowed_destructive['status'] &&
		3 === $runner->runs && 0 === $callback_calls,
		'Explicit destructive provider consent did not produce one verified effect.'
	);
	$grants[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	$revoked = $ability->execute( $destructive_run );
	wpai138_run_assert(
		is_wp_error( $revoked ) && 'cron_run_now_denied' === $revoked->get_error_code() &&
		3 === $runner->runs,
		'After-the-fact destructive-grant revocation allowed replay.'
	);
	wp_clear_scheduled_hook( $hook, $destructive_args );
	echo "PASS: #138 destructive-run provider requires separate Users & Destructive consent; its revocation blocks execution/replay.\n";

} finally {
	wp_clear_scheduled_hook( $hook, $args );
	wp_clear_scheduled_hook( $hook, array( 'destructive-provider-args' ) );
	remove_action( $hook, $callback, 10 );
	remove_filter( 'wp_ai_bridge_cron_profiles', $profile_provider );
	delete_option( $effects_option );
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
	wp_set_current_user( $admin );
}
