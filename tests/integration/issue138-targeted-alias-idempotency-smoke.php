<?php
/**
 * Disposable real-WordPress regression for canonical Cron event claims.
 *
 * The fixture itself dispatches its registered native callback to prove
 * provider-side idempotency. Bridge must never dispatch arbitrary hooks.
 * Never execute this test on a production WordPress installation.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Cron_Run_Claim;
use WP_AI_Bridge\Support\Cron_Targeted_Runner;
use WP_AI_Bridge\Support\Settings;

function wpai138_alias_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$admin = get_current_user_id();
$original = get_option( Settings::OPTION_NAME, false );
$hook = 'wpai138_alias_' . bin2hex( random_bytes( 6 ) );
$args = array( 'alias-job-a' );
$effects_option = 'wpai138_alias_effects_' . bin2hex( random_bytes( 5 ) );
$active_profiles = array(
	'alias_a' => array( 'revision' => 'fixture-a-v1', 'destructive' => false ),
	'alias_b' => array( 'revision' => 'fixture-b-v2', 'destructive' => true ),
);

$runner = new class( $effects_option ) implements Cron_Targeted_Runner {
	public $run_calls = 0;
	public $scheduled_calls = 0;
	private $option;

	public function __construct( $option ) {
		$this->option = $option;
	}

	/** One durable business-effect primitive shared by BOTH execution paths. */
	private function apply_effect( $business_id ) {
		$state = get_option( $this->option, array( 'effects' => array(), 'writes' => 0 ) );
		if ( isset( $state['effects'][ $business_id ] ) ) {
			return $state['effects'][ $business_id ];
		}
		$ref = hash( 'sha256', 'fixture-business-effect|' . get_current_blog_id() . '|' . $business_id );
		$state['effects'][ $business_id ] = $ref;
		++$state['writes'];
		update_option( $this->option, $state, false );
		return $ref;
	}

	public function run_once( $context ) {
		++$this->run_calls;
		return array(
			'status'     => 'completed',
			'effect_ref' => $this->apply_effect( $context['args'][0] ),
		);
	}

	public function verify( $context, $effect_ref ) {
		$state = get_option( $this->option, array() );
		return isset( $state['effects'][ $context['args'][0] ] ) &&
			is_string( $effect_ref ) &&
			hash_equals( $state['effects'][ $context['args'][0] ], $effect_ref );
	}

	public function scheduled_callback( $business_id ) {
		++$this->scheduled_calls;
		return $this->apply_effect( $business_id );
	}
};

$callback = static function ( $business_id ) use ( $runner ) {
	wpai138_alias_assert( 1 === preg_match( '/^alias-job-[abc]$/D', $business_id ), 'Native callback received wrong provider args.' );
	$runner->scheduled_callback( $business_id );
};

$registration = static function ( $profiles ) use ( &$active_profiles, $hook, &$args, $runner ) {
	foreach ( $active_profiles as $id => $policy ) {
		$profiles[ $id ] = array(
			'hook'                 => $hook,
			'args'                 => $args,
			'actions'              => array( 'schedule', 'unschedule', 'run_now' ),
			'min_interval_seconds' => 300,
			'targeted_runner'      => $runner,
			'runner_revision'      => $policy['revision'],
			'run_budget_seconds'   => 5,
			'run_destructive'      => $policy['destructive'],
		);
	}
	return $profiles;
};
$input = static function ( $profile, $scheduled, $operation ) {
	return array(
		'action'            => 'run_now',
		'profile_id'        => $profile,
		'event_id'          => $scheduled['event_id'],
		'expected_snapshot' => $scheduled['snapshot'],
		'operation_id'      => $operation,
		'confirm'           => true,
	);
};

add_action( $hook, $callback, 10, 1 );
add_filter( 'wp_ai_bridge_cron_profiles', $registration );
try {
	wpai138_alias_assert( $admin > 0, 'Test requires authenticated WordPress administrator.' );
	$manage = wp_get_ability( 'wp-ai-bridge/cron-events-manage' );
	$reader = wp_get_ability( 'wp-ai-bridge/cron-events-read' );
	wpai138_alias_assert( $manage instanceof WP_Ability && $reader instanceof WP_Ability, 'Native Cron Abilities absent.' );

	$grants = ( new Settings() )->defaults();
	$grants[ Settings::GROUP_SITE_CONFIG ] = 1;
	$grants[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	$grants[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );

	$initial = $reader->execute( array( 'action' => 'list', 'hook' => $hook ) );
	wpai138_alias_assert( ! is_wp_error( $initial ) && 0 === $initial['total'], 'New fixture should have no Cron events.' );
	$scheduled_a = $manage->execute( array(
		'action'            => 'schedule',
		'profile_id'        => 'alias_a',
		'timestamp'         => time() + 7200,
		'recurrence'        => '',
		'expected_snapshot' => $initial['snapshot'],
	) );
	wpai138_alias_assert( ! is_wp_error( $scheduled_a ) && 'scheduled' === $scheduled_a['status'], 'Could not schedule native alias fixture.' );
	$a = $input( 'alias_a', $scheduled_a, 'alias-a-initial-operation' );
	$b = $input( 'alias_b', $scheduled_a, 'alias-b-conflicting-op' );

	// Simultaneous opt-in aliases are ambiguous even when one claims a
	// weaker destructive policy or a different runner revision.
	$reject_a = $manage->execute( $a );
	$reject_b = $manage->execute( $b );
	wpai138_alias_assert(
		is_wp_error( $reject_a ) && 'cron_run_now_ambiguous_provider' === $reject_a->get_error_code() &&
		is_wp_error( $reject_b ) && 'cron_run_now_ambiguous_provider' === $reject_b->get_error_code() &&
		0 === $runner->run_calls &&
		false === Cron_Run_Claim::read( Cron_Run_Claim::name( $scheduled_a['event_id'] ) ),
		'Duplicate run-now profile aliases admitted an execution or claimed the native event.'
	);
	echo "PASS: #138 duplicate runnable provider aliases fail closed, including conflicting destructive policy.\n";

	// A alone may execute, but the registered WordPress callback then
	// exercises precisely the SAME durable provider business-effect primitive.
	$active_profiles = array( 'alias_a' => array( 'revision' => 'fixture-a-v1', 'destructive' => false ) );
	$grants[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	$first = $manage->execute( $a );
	$before_callback = get_option( $effects_option, array() );
	wpai138_alias_assert(
		! is_wp_error( $first ) && 'provider_verified' === $first['status'] &&
		1 === $runner->run_calls && 0 === $runner->scheduled_calls &&
		1 === (int) ( $before_callback['writes'] ?? 0 ),
		'Manual provider execution failed to create exactly one durable effect.'
	);
	wpai138_alias_assert( false !== has_action( $hook, $callback ), 'Fixture normal WP-Cron callback is not registered.' );
	// Test-only native WordPress Hook dispatch, not a Bridge execution API.
	do_action_ref_array( $hook, $args );
	$after_callback = get_option( $effects_option, array() );
	wpai138_alias_assert(
		1 === $runner->scheduled_calls && 1 === $runner->run_calls &&
		1 === (int) ( $after_callback['writes'] ?? 0 ) &&
		$before_callback === $after_callback &&
		wp_get_scheduled_event( $hook, $args, $scheduled_a['timestamp'] ) instanceof stdClass,
		'Normal provider Cron callback duplicated the already completed manual business effect.'
	);
	echo "PASS: #138 native registered callback AFTER manual run shares the verified durable effect exactly once.\n";

	// Rename with identical authorization/revision: no new native-event
	// admission even when a new profile ID and operation ID are supplied.
	$active_profiles = array( 'renamed_alias' => array( 'revision' => 'fixture-a-v1', 'destructive' => false ) );
	$renamed = $manage->execute( $input( 'renamed_alias', $scheduled_a, 'renamed-profile-operation' ) );
	wpai138_alias_assert(
		is_wp_error( $renamed ) && 'cron_run_now_outcome_unknown' === $renamed->get_error_code() &&
		1 === $runner->run_calls,
		'Profile rename bypassed the canonical native-event fence.'
	);

	// Re-registration with a DIFFERENT destructive policy is not a
	// new business intention and cannot override a prior event reservation.
	$active_profiles = array( 'alias_b' => array( 'revision' => 'fixture-b-v2', 'destructive' => true ) );
	$without_grant = $manage->execute( $b );
	wpai138_alias_assert( is_wp_error( $without_grant ) && 'cron_run_now_denied' === $without_grant->get_error_code(), 'Destructive renamed provider evaded consent.' );
	$grants[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$replayed_b = $manage->execute( $b );
	wpai138_alias_assert(
		is_wp_error( $replayed_b ) && 'cron_run_now_outcome_unknown' === $replayed_b->get_error_code() &&
		1 === $runner->run_calls && 1 === (int) get_option( $effects_option )['writes'],
		'Execution through profile B after A created a second claim or effect.'
	);

	// Reverse order: a newly scheduled native event first executes through
	// B with destructive consent, then A cannot downgrade that identity.
	wp_clear_scheduled_hook( $hook, $args );
	$args = array( 'alias-job-b' );
	$source_b = $reader->execute( array( 'action' => 'list', 'hook' => $hook ) );
	$scheduled_b = $manage->execute( array(
		'action'            => 'schedule',
		'profile_id'        => 'alias_b',
		'timestamp'         => time() + 9000,
		'recurrence'        => '',
		'expected_snapshot' => $source_b['snapshot'],
	) );
	wpai138_alias_assert( ! is_wp_error( $scheduled_b ), 'Could not schedule reverse-direction native event.' );
	$forward_b = $manage->execute( $input( 'alias_b', $scheduled_b, 'first-destructive-native-run' ) );
	wpai138_alias_assert(
		! is_wp_error( $forward_b ) && 'provider_verified' === $forward_b['status'] &&
		2 === $runner->run_calls && 2 === (int) get_option( $effects_option )['writes'],
		'Provider B initial authorized run was not verified.'
	);
	do_action_ref_array( $hook, $args );
	wpai138_alias_assert(
		2 === $runner->scheduled_calls && 2 === (int) get_option( $effects_option )['writes'],
		'Callback following destructive run created an extra business effect.'
	);
	$active_profiles = array( 'alias_a' => array( 'revision' => 'fixture-a-v1', 'destructive' => false ) );
	$grants[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	$replayed_a = $manage->execute( $input( 'alias_a', $scheduled_b, 'second-nondestructive-alias-run' ) );
	wpai138_alias_assert(
		is_wp_error( $replayed_a ) && 'cron_run_now_outcome_unknown' === $replayed_a->get_error_code() &&
		2 === $runner->run_calls && 2 === (int) get_option( $effects_option )['writes'],
		'Reverse B-to-A alias policy downgrade bypassed native-event admission.'
	);
	echo "PASS: #138 alias A-to-B, B-to-A, rename, runner revision and destructive-policy changes cannot re-execute.\n";

	// Complementary real provider contract: normal callback has already
	// committed its effect before the first manual request arrives.
	wp_clear_scheduled_hook( $hook, $args );
	$args = array( 'alias-job-c' );
	$source_c = $reader->execute( array( 'action' => 'list', 'hook' => $hook ) );
	$scheduled_c = $manage->execute( array(
		'action'            => 'schedule',
		'profile_id'        => 'alias_a',
		'timestamp'         => time() + 10800,
		'recurrence'        => '',
		'expected_snapshot' => $source_c['snapshot'],
	) );
	wpai138_alias_assert( ! is_wp_error( $scheduled_c ), 'Could not schedule normal-first native event.' );
	do_action_ref_array( $hook, $args );
	$normal_effect = get_option( $effects_option, array() );
	wpai138_alias_assert(
		3 === $runner->scheduled_calls && 3 === (int) ( $normal_effect['writes'] ?? 0 ),
		'Fixture normal callback did not create the initial durable effect.'
	);
	$manual_after_normal = $manage->execute( $input( 'alias_a', $scheduled_c, 'scheduled-first-manual-later' ) );
	wpai138_alias_assert(
		! is_wp_error( $manual_after_normal ) && 'provider_verified' === $manual_after_normal['status'] &&
		3 === $runner->run_calls &&
		$normal_effect === get_option( $effects_option ) &&
		wp_get_scheduled_event( $hook, $args, $scheduled_c['timestamp'] ) instanceof stdClass,
		'Manual targeted runner duplicated a business effect already made by normal callback.'
	);
	echo "PASS: #138 normal scheduled callback BEFORE manual run is also provider-idempotent.\n";
} finally {
	wp_clear_scheduled_hook( $hook, $args );
	remove_action( $hook, $callback, 10 );
	remove_filter( 'wp_ai_bridge_cron_profiles', $registration );
	delete_option( $effects_option );
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
	wp_set_current_user( $admin );
}
