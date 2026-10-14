<?php
/**
 * Provider-opted-in, exact-identity native WordPress scheduled-event lifecycle.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Admission_Lock;
use WP_AI_Bridge\Support\Cron_Run_Claim;
use WP_AI_Bridge\Support\Cron_Targeted_Runner;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Never accepts a caller-provided callback or serialized event arguments.
 *
 * A trusted installed provider explicitly registers a profile and supplies its
 * immutable arguments via a native filter. WordPress remains the sole scheduler.
 */
final class Cron_Mutation_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Existing Bridge delegated authority. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/cron-events-manage',
			array(
				'label'               => __( 'Manage Authorized WordPress Cron Events', 'wp-ai-bridge' ),
				'description'         => __( 'Manage exact authorized WordPress Cron events; targeted run-now requires a separately approved installed-provider runner, durable replay fence and verified effect.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'action'            => array(
							'type' => 'string',
							'enum' => array( 'profiles', 'schedule', 'unschedule', 'reschedule', 'run_now' ),
						),
						'profile_id'        => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 80,
						),
						'offset'            => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'limit'             => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
						'event_id'          => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
						'expected_snapshot' => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
						'timestamp'         => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'recurrence'        => array(
							'type'      => 'string',
							'maxLength' => 80,
						),
						'operation_id'      => array(
							'type'      => 'string',
							'minLength' => 8,
							'maxLength' => 128,
						),
						'confirm'           => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'action' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'action'              => array( 'type' => 'string' ),
						'status'              => array( 'type' => 'string' ),
						'event_id'            => array( 'type' => 'string' ),
						'timestamp'           => array( 'type' => 'integer' ),
						'snapshot'            => array( 'type' => 'string' ),
						'profile_total'       => array( 'type' => 'integer' ),
						'profile_next_offset' => array( 'type' => 'integer' ),
						'profile_has_more'    => array( 'type' => 'boolean' ),
						'profiles'            => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'profile_id' => array( 'type' => 'string' ),
									'hook'       => array( 'type' => 'string' ),
									'actions'    => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
								),
								'required'             => array( 'profile_id', 'hook', 'actions' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'action', 'status', 'event_id', 'timestamp', 'snapshot', 'profiles' ),
					'additionalProperties' => false,
				),
				'permission_callback' => array( $this, 'can_manage' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */
	public function can_manage() {
		return $this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'manage_options' ) &&
			( ! is_multisite() || current_user_can( 'manage_network_options' ) );
	}

	/**
	 * Only trusted provider-registered profile IDs are selectable remotely.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function profiles() {
		$raw = apply_filters( 'wp_ai_bridge_cron_profiles', array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$profiles = array();
		foreach ( $raw as $id => $profile ) {
			if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,79}$/D', $id ) ||
				! is_array( $profile ) || ! isset( $profile['hook'], $profile['args'], $profile['actions'] ) ||
				! is_string( $profile['hook'] ) || 1 !== preg_match( '/^[a-zA-Z0-9_\/.-]{1,180}$/D', $profile['hook'] ) ||
				! is_array( $profile['args'] ) || ! is_array( $profile['actions'] ) ||
				! array_is_list( $profile['args'] ) || count( $profile['args'] ) > 16 ||
				! $this->safe_args( $profile['args'] ) || strlen( serialize( $profile['args'] ) ) > 4096 || false === has_action( $profile['hook'] ) ) {
				continue;
			}
			$actions = array_values( array_unique( $profile['actions'] ) );
			if ( array_diff( $actions, array( 'schedule', 'unschedule', 'reschedule', 'run_now' ) ) ||
				! count( $actions ) || count( $actions ) !== count( $profile['actions'] ) ) {
				continue;
			}
			$runner          = null;
			$runner_revision = '';
			if ( in_array( 'run_now', $actions, true ) ) {
				$runner          = $profile['targeted_runner'] ?? null;
				$runner_revision = $profile['runner_revision'] ?? '';
				if ( ! $runner instanceof Cron_Targeted_Runner ||
					! is_string( $runner_revision ) ||
					1 !== preg_match( '/^[a-zA-Z0-9_.:-]{1,64}$/D', $runner_revision ) ||
					! isset( $profile['run_budget_seconds'], $profile['run_destructive'] ) ||
					! is_int( $profile['run_budget_seconds'] ) || $profile['run_budget_seconds'] < 1 ||
					$profile['run_budget_seconds'] > 10 || ! is_bool( $profile['run_destructive'] ) ) {
					continue;
				}
			}
			$min = $profile['min_interval_seconds'] ?? 60;
			if ( ! is_int( $min ) || $min < 60 || $min > DAY_IN_SECONDS ) {
				continue;
			}
			$profiles[ $id ] = array(
				'hook'               => $profile['hook'],
				'args'               => $profile['args'],
				'actions'            => $actions,
				'interval'           => $min,
				'runner'             => $runner,
				'runner_revision'    => $runner_revision,
				'run_budget_seconds' => null !== $runner ? $profile['run_budget_seconds'] : 0,
				'run_destructive'    => null !== $runner ? $profile['run_destructive'] : false,
			);
		}
		return $profiles;
	}

	/** @param array<int,mixed> $args Immutable provider args. @return bool */
	private function safe_args( $args ) {
		foreach ( $args as $arg ) {
			if ( ! is_null( $arg ) && ! is_bool( $arg ) && ! is_int( $arg ) &&
				( ! is_string( $arg ) || strlen( $arg ) > 1024 || 1 !== preg_match( '//u', $arg ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $input Validated native Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input ) {
		if ( ! $this->can_manage() ) {
			return new WP_Error( 'cron_mutation_denied', __( 'Site Configuration and native administrator authority are required for Cron changes.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) || ! isset( $input['action'] ) || ! is_string( $input['action'] ) ) {
			return $this->invalid();
		}
		$action   = $input['action'];
		$profiles = $this->profiles();
		if ( 'profiles' === $action ) {
			if ( array_diff( array_keys( $input ), array( 'action', 'offset', 'limit', 'expected_snapshot' ) ) ) {
				return $this->invalid();
			}
			$offset   = $input['offset'] ?? 0;
			$limit    = $input['limit'] ?? 10;
			$expected = $input['expected_snapshot'] ?? '';
			if ( ! is_int( $offset ) || $offset < 0 || ! is_int( $limit ) || $limit < 1 || $limit > 50 ||
				! is_string( $expected ) || ( '' !== $expected && 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) ) {
				return $this->invalid();
			}
			if ( $offset > 0 && '' === $expected ) {
				return new WP_Error( 'cron_profiles_identity_required', __( 'Resuming Cron profile discovery requires the previous profile snapshot.', 'wp-ai-bridge' ) );
			}
			// Stable provider-owned IDs form a source-bound paginated inventory;
			// private fixed callback arguments influence the opaque digest only.
			ksort( $profiles, SORT_STRING );
			// The trusted Runner object must never be serialized (or exposed).
			// Its installed-provider revision marks contract changes across requests.
			$metadata = array();
			foreach ( $profiles as $key => $known ) {
				$metadata[ $key ] = $known;
				unset( $metadata[ $key ]['runner'] );
			}
			$snapshot = hash_hmac( 'sha256', serialize( array( get_current_blog_id(), $metadata ) ), wp_salt( 'auth' ) );
			if ( '' !== $expected && ! hash_equals( $expected, $snapshot ) ) {
				return new WP_Error( 'cron_profiles_stale', __( 'The registered Cron profiles changed; restart at offset zero.', 'wp-ai-bridge' ) );
			}
			$total = count( $profiles );
			if ( $offset > $total ) {
				return $this->invalid();
			}
			$public = array();
			foreach ( array_slice( $profiles, $offset, $limit, true ) as $id => $profile ) {
				$public[] = array(
					'profile_id' => $id,
					'hook'       => $profile['hook'],
					'actions'    => $profile['actions'],
				);
			}
			// The provider registry may grow beyond one site's encoded MCP
			// response budget. Never truncate silently or force a total cap:
			// reduce this page until the projected Adapter wire response fits.
			while ( true ) {
				$next                          = $offset + count( $public );
				$result                        = $this->receipt( $action, 'read', '', 0, $snapshot, $public );
				$result['profile_total']       = $total;
				$result['profile_next_offset'] = $next;
				$result['profile_has_more']    = $next < $total;
				if ( $this->profile_response_fits( $result ) ) {
					return $result;
				}
				if ( ! $public ) {
					return new WP_Error( 'cron_profiles_response_too_large', __( 'Cron profile metadata exceeds the configured response budget.', 'wp-ai-bridge' ) );
				}
				array_pop( $public );
			}
		}
		if ( ! in_array( $action, array( 'schedule', 'unschedule', 'reschedule', 'run_now' ), true ) ) {
			return $this->invalid();
		}
		$id = $input['profile_id'] ?? '';
		if ( ! is_string( $id ) || ! isset( $profiles[ $id ] ) ) {
			return new WP_Error( 'cron_profile_unsupported', __( 'The provider has not authorized this Cron operation profile.', 'wp-ai-bridge' ) );
		}
		if ( 'run_now' === $action ) {
			return $this->run_now( $input, $id, $profiles[ $id ] );
		}
		$profile = $profiles[ $id ];
		if ( ! in_array( $action, $profile['actions'], true ) ) {
			return new WP_Error( 'cron_profile_unsupported', __( 'The provider has not authorized this Cron operation profile.', 'wp-ai-bridge' ) );
		}
		$allowed = 'schedule' === $action ? array( 'action', 'profile_id', 'timestamp', 'recurrence', 'expected_snapshot' )
			: ( 'reschedule' === $action ? array( 'action', 'profile_id', 'event_id', 'expected_snapshot', 'timestamp', 'recurrence' )
				: array( 'action', 'profile_id', 'event_id', 'expected_snapshot' ) );
		if ( array_diff( array_keys( $input ), $allowed ) ||
			! isset( $input['expected_snapshot'] ) || ! is_string( $input['expected_snapshot'] ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['expected_snapshot'] ) ) {
			return $this->invalid();
		}
		if ( 'schedule' !== $action && ( ! isset( $input['event_id'] ) || ! is_string( $input['event_id'] ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['event_id'] ) ) ) {
			return $this->invalid();
		}
		$timestamp  = $input['timestamp'] ?? 0;
		$recurrence = $input['recurrence'] ?? '';
		if ( 'unschedule' !== $action ) {
			if ( ! is_int( $timestamp ) || $timestamp < time() + 60 ||
				! is_string( $recurrence ) || strlen( $recurrence ) > 80 ) {
				return $this->invalid();
			}
			if ( '' !== $recurrence ) {
				$schedules = wp_get_schedules();
				if ( ! isset( $schedules[ $recurrence ]['interval'] ) ||
					! is_int( $schedules[ $recurrence ]['interval'] ) ||
					$schedules[ $recurrence ]['interval'] < $profile['interval'] ) {
					return new WP_Error( 'cron_recurrence_unsupported', __( 'The selected provider recurrence is unavailable or below its supported minimum interval.', 'wp-ai-bridge' ) );
				}
			}
		}
		$lock = $this->acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			if ( ! $this->can_manage() || false === has_action( $profile['hook'] ) ) {
				return new WP_Error( 'cron_mutation_denied', __( 'Site Configuration and native administrator authority are required for Cron changes.', 'wp-ai-bridge' ) );
			}
			$inventory = ( new Cron_Abilities( $this->permissions ) )->execute(
				array(
					'action' => 'list',
					'limit'  => 1,
				)
			);
			if ( is_wp_error( $inventory ) || ! hash_equals( $input['expected_snapshot'], $inventory['snapshot'] ) ) {
				return new WP_Error( 'cron_stale_snapshot', __( 'The Cron inventory changed. Restart pagination from offset zero.', 'wp-ai-bridge' ) );
			}
			$events = $this->matching( $profile );
			if ( is_wp_error( $events ) ) {
				return $events;
			}
			if ( 'schedule' === $action ) {
				if ( $events ) {
					return new WP_Error( 'cron_duplicate_profile', __( 'An event for this exact provider-managed Cron profile already exists.', 'wp-ai-bridge' ) );
				}
				if ( ! $this->lock_owned( $lock ) ) {
					return $this->lock_lost();
				}
				$created = $this->schedule( $profile, $timestamp, $recurrence );
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				return $this->confirmed_receipt( $action, 'scheduled', $profile, $created['event_id'], $timestamp, $recurrence, $lock );
			}
			$event_id = $input['event_id'];
			if ( ! isset( $events[ $event_id ] ) ) {
				return new WP_Error( 'cron_event_not_found', __( 'The selected Cron event no longer exists.', 'wp-ai-bridge' ) );
			}
			$prior = $events[ $event_id ];
			if ( 'unschedule' === $action ) {
				if ( ! $this->lock_owned( $lock ) ) {
					return $this->lock_lost();
				}
				$result = wp_unschedule_event( $prior['timestamp'], $profile['hook'], $profile['args'], true );
				if ( true !== $result ) {
					return new WP_Error( 'cron_outcome_unknown', __( 'WordPress did not verify cancellation of the exact selected Cron event. Inspect current schedules before any retry.', 'wp-ai-bridge' ) );
				}
				return $this->confirmed_receipt( $action, 'unscheduled', $profile, $event_id, $prior['timestamp'], '', $lock );
			}
			if ( 1 !== count( $events ) ) {
				return new WP_Error( 'cron_duplicate_profile', __( 'An event for this exact provider-managed Cron profile already exists.', 'wp-ai-bridge' ) );
			}
			if ( $timestamp === $prior['timestamp'] && $recurrence === $prior['recurrence'] ) {
				return $this->confirmed_receipt( $action, 'unchanged', $profile, $event_id, $timestamp, $recurrence, $lock );
			}
			if ( ! $this->lock_owned( $lock ) ) {
				return $this->lock_lost();
			}
			$created = $this->schedule( $profile, $timestamp, $recurrence );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			if ( ! $this->lock_owned( $lock ) ) {
				return new WP_Error( 'cron_outcome_unknown', __( 'Rescheduling may have partially changed WordPress Cron. Inspect exact current events before any retry.', 'wp-ai-bridge' ) );
			}
			$removed = wp_unschedule_event( $prior['timestamp'], $profile['hook'], $profile['args'], true );
			$old     = wp_get_scheduled_event( $profile['hook'], $profile['args'], $prior['timestamp'] );
			$new     = wp_get_scheduled_event( $profile['hook'], $profile['args'], $timestamp );
			if ( true !== $removed || false !== $old || ! is_object( $new ) ) {
				return new WP_Error( 'cron_outcome_unknown', __( 'Rescheduling may have partially changed WordPress Cron. Inspect exact current events before any retry.', 'wp-ai-bridge' ) );
			}
			return $this->confirmed_receipt( $action, 'rescheduled', $profile, $created['event_id'], $timestamp, $recurrence, $lock );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Higher-trust event-specific execution is NEVER native Core wp_cron,
	 * do_action, WP-CLI, a remote callback, or a general PHP callable.
	 *
	 * The installed provider explicitly implements Cron_Targeted_Runner.
	 * Bridge fences one site/profile/event identity durably before the effect.
	 * Provider code owns BOTH scheduled-path idempotency and its own effect
	 * verification. No PHP synchronous request can hard-kill untrusted provider
	 * code; registered 1-10 s budget is passed as a deadline and checked
	 * afterward. Long/ambiguous execution retains its never-replayed fence.
	 *
	 * @param array<string,mixed> $input Validated raw request.
	 * @param string              $id Registered provider profile.
	 * @param array<string,mixed> $profile Trusted provider definition.
	 * @return array<string,mixed>|WP_Error
	 */
	private function run_now( $input, $id, $profile ) {
		if ( ! in_array( 'run_now', $profile['actions'], true ) ||
			! $profile['runner'] instanceof Cron_Targeted_Runner ) {
			return new WP_Error( 'cron_run_now_unsupported', __( 'This installed provider has not enabled a verified targeted Cron runner.', 'wp-ai-bridge' ) );
		}
		if ( ! $this->can_run_now( $profile ) ) {
			return new WP_Error( 'cron_run_now_denied', __( 'Immediate Cron execution also requires Code & Extensions and the provider-specific administrator consent.', 'wp-ai-bridge' ) );
		}
		$allowed      = array( 'action', 'profile_id', 'event_id', 'expected_snapshot', 'operation_id', 'confirm' );
		$event_id     = $input['event_id'] ?? '';
		$expected     = $input['expected_snapshot'] ?? '';
		$operation_id = $input['operation_id'] ?? '';
		if ( array_diff( array_keys( $input ), $allowed ) ||
			true !== ( $input['confirm'] ?? null ) ||
			! is_string( $event_id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $event_id ) ||
			! is_string( $expected ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) ||
			! is_string( $operation_id ) || strlen( $operation_id ) < 8 || strlen( $operation_id ) > 128 ||
			1 !== preg_match( '/^[a-zA-Z0-9_.:-]+$/D', $operation_id ) ) {
			return $this->invalid();
		}
		$lock = $this->acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			// Provider registrations and every grant may change across the
			// initial permission check and admission lock acquisition.
			$fresh = $this->profiles();
			if ( ! isset( $fresh[ $id ] ) || $fresh[ $id ]['runner_revision'] !== $profile['runner_revision'] ||
				$fresh[ $id ]['hook'] !== $profile['hook'] || $fresh[ $id ]['args'] !== $profile['args'] ||
				$fresh[ $id ]['actions'] !== $profile['actions'] ||
				$fresh[ $id ]['run_budget_seconds'] !== $profile['run_budget_seconds'] ||
				$fresh[ $id ]['run_destructive'] !== $profile['run_destructive'] ||
				! in_array( 'run_now', $fresh[ $id ]['actions'], true ) ||
				! $fresh[ $id ]['runner'] instanceof Cron_Targeted_Runner ||
				! $this->can_run_now( $fresh[ $id ] ) || false === has_action( $profile['hook'] ) ||
				! $this->lock_owned( $lock ) ) {
				return new WP_Error( 'cron_run_now_denied', __( 'The installed Cron provider or current execution permission changed; no targeted run was admitted.', 'wp-ai-bridge' ) );
			}
			$profile   = $fresh[ $id ];
			$inventory = $this->snapshot();
			if ( ! is_string( $inventory ) || ! hash_equals( $expected, $inventory ) ) {
				return new WP_Error( 'cron_stale_snapshot', __( 'The Cron inventory changed. Restart pagination from offset zero.', 'wp-ai-bridge' ) );
			}
			$events = $this->matching( $profile );
			if ( is_wp_error( $events ) ) {
				return $events;
			}
			if ( ! isset( $events[ $event_id ] ) || 1 !== count( $events ) ) {
				return new WP_Error( 'cron_run_now_event_stale', __( 'The exact provider event is missing or no longer unique; inspect the native Cron list.', 'wp-ai-bridge' ) );
			}
			$event = $events[ $event_id ];
			// An already-due event can be in WordPress's own uncoordinated
			// due-event worker. Only a future event is eligible for manual run.
			if ( $event['timestamp'] <= time() + 60 ) {
				return new WP_Error( 'cron_run_now_due', __( 'The selected event is due or too near its native run time for a safe targeted run.', 'wp-ai-bridge' ) );
			}
			$principal = get_current_user_id();
			if ( ! is_int( $principal ) || $principal <= 0 || ! $this->lock_owned( $lock ) ) {
				return new WP_Error( 'cron_run_now_denied', __( 'Immediate Cron execution requires a current authenticated administrator.', 'wp-ai-bridge' ) );
			}
			$client_id   = (string) OAuth_Server::authenticated_mcp_client_id();
			$fingerprint = hash( 'sha256', wp_json_encode( array( get_current_blog_id(), $principal, $client_id, $id, $event_id, $operation_id, $profile['runner_revision'] ) ) );
			$name        = Cron_Run_Claim::name( $id, $event_id );
			$initial     = wp_json_encode(
				array(
					'state'       => 'in_progress',
					'fingerprint' => $fingerprint,
					'created_at'  => time(),
				)
			);
			if ( ! is_string( $initial ) ) {
				return $this->invalid();
			}
			$context  = array(
				'site_id'        => get_current_blog_id(),
				'principal_id'   => $principal,
				'client_id'      => $client_id,
				'profile_id'     => $id,
				'event_id'       => $event_id,
				'operation_id'   => $operation_id,
				'hook'           => $profile['hook'],
				'args'           => $profile['args'],
				'timestamp'      => $event['timestamp'],
				'recurrence'     => $event['recurrence'],
				'budget_seconds' => $profile['run_budget_seconds'],
				'deadline'       => microtime( true ) + $profile['run_budget_seconds'],
			);
			$existing = Cron_Run_Claim::read( $name );
			if ( false !== $existing ) {
				return $this->run_now_replay( $existing, $fingerprint, $profile, $context, $event, $inventory, $lock );
			}
			$reserved = Cron_Run_Claim::reserve( $name, $initial );
			if ( 'acquired' !== $reserved ) {
				if ( 'existing' === $reserved ) {
					$existing = Cron_Run_Claim::read( $name );
					return is_array( $existing )
						? $this->run_now_replay( $existing, $fingerprint, $profile, $context, $event, $inventory, $lock )
						: $this->run_now_unknown();
				}
				return 'capacity' === $reserved
					? new WP_Error( 'cron_run_now_capacity', __( 'The finite Cron execution receipt capacity is exhausted; reconcile retained run receipts before enabling more executions.', 'wp-ai-bridge' ) )
					: $this->run_now_unknown();
			}
			// From here, even a provider error could follow a real effect.
			// The durable event fence MUST NOT be released on failure/timeout.
			$started = microtime( true );
			try {
				$result = $profile['runner']->run_once( $context );
			} catch ( \Throwable $failure ) {
				return $this->run_now_unknown();
			}
			$effect_ref = is_array( $result ) ? ( $result['effect_ref'] ?? '' ) : '';
			if ( ! is_array( $result ) || 'completed' !== ( $result['status'] ?? '' ) ||
				! is_string( $effect_ref ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $effect_ref ) ||
				microtime( true ) - $started > $profile['run_budget_seconds'] ||
				! $this->lock_owned( $lock ) ) {
				return $this->run_now_unknown();
			}
			if ( ! $this->verify_run( $profile, $context, $effect_ref, $lock ) ) {
				return $this->run_now_unknown();
			}
			// A provider must not silently alter the original scheduled event.
			// An unrelated native Core writer can still race us; a receipt is
			// certified only for the exact stable observed point in time.
			$end       = $this->snapshot();
			$matching  = $this->matching( $profile );
			$end_again = $this->snapshot();
			if ( ! is_string( $end ) || ! hash_equals( $inventory, $end ) ||
				! is_string( $end_again ) || ! hash_equals( $end, $end_again ) ||
				is_wp_error( $matching ) || 1 !== count( $matching ) ||
				! isset( $matching[ $event_id ] ) ||
				$matching[ $event_id ] !== $event || microtime( true ) - $started > $profile['run_budget_seconds'] ||
				! $this->can_run_now( $profile ) || ! $this->lock_owned( $lock ) ||
				! Cron_Run_Claim::finish( $name, $initial, $effect_ref ) ) {
				return $this->run_now_unknown();
			}
			return $this->receipt( 'run_now', 'provider_verified', $event_id, $event['timestamp'], $end );
		} finally {
			$lock->release();
		}
	}

	/** @param array<string,mixed> $profile Registered installed provider. @return bool */
	private function can_run_now( $profile ) {
		if ( ! $this->can_manage() || ! $this->permissions->allowed_all(
			array(
				array( Settings::GROUP_SITE_CONFIG, 'manage_options' ),
				array( Settings::GROUP_CODE_EXTENSIONS, 'activate_plugins' ),
			)
		) ) {
			return false;
		}
		return empty( $profile['run_destructive'] ) ||
			$this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'manage_options' );
	}

	/** @return bool */
	private function verify_run( $profile, $context, $effect_ref, $lock ) {
		if ( ! $this->lock_owned( $lock ) ) {
			return false;
		}
		try {
			$verified = $profile['runner']->verify( $context, $effect_ref );
		} catch ( \Throwable $failure ) {
			return false;
		}
		return true === $verified && microtime( true ) <= $context['deadline'] && $this->lock_owned( $lock );
	}

	/** @return array<string,mixed>|WP_Error */
	private function run_now_replay( $stored, $fingerprint, $profile, $context, $event, $snapshot, $lock ) {
		if ( ! hash_equals( $fingerprint, (string) ( $stored['fingerprint'] ?? '' ) ) ||
			'verified' !== ( $stored['state'] ?? '' ) || ! is_string( $stored['effect_ref'] ?? null ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $stored['effect_ref'] ) ||
			! $this->can_run_now( $profile ) ||
			! $this->verify_run( $profile, $context, $stored['effect_ref'], $lock ) ) {
			return $this->run_now_unknown();
		}
		return $this->receipt( 'run_now', 'already_verified', $context['event_id'], $event['timestamp'], $snapshot );
	}

	/** @return WP_Error */
	private function run_now_unknown() {
		return new WP_Error( 'cron_run_now_outcome_unknown', __( 'Targeted Cron execution may already have happened. Its event is reserved; inspect the provider receipt before retrying.', 'wp-ai-bridge' ) );
	}

	/**
	 * Bound profile listing even when the separate large-payload proposal is
	 * not installed. The shared operator-controlled budget takes precedence
	 * once available, without making this independent PR depend on its class.
	 *
	 * @param array<string,mixed> $result Proposed profile response.
	 * @return bool
	 */
	private function profile_response_fits( $result ) {
		if ( class_exists( Bounded_Payload::class ) ) {
			return Bounded_Payload::fits( $result );
		}
		// Lowest supported projected MCP JSON budget in the optional shared
		// payload proposal. This guards standalone Cron installs and does not
		// limit total profiles, events or native WordPress object storage.
		$structured = wp_json_encode(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
		if ( ! is_string( $structured ) ) {
			return false;
		}
		$text = wp_json_encode( $structured );
		return is_string( $text ) && strlen( $structured ) + strlen( $text ) + 2048 <= 16 * 1024;
	}

	/** @return WP_Error */
	private function invalid() {
		return new WP_Error( 'cron_mutation_invalid', __( 'Invalid or incomplete authorized Cron mutation request.', 'wp-ai-bridge' ) );
	}

	/** @return string|WP_Error */
	private function snapshot() {
		$result = ( new Cron_Abilities( $this->permissions ) )->execute(
			array(
				'action' => 'list',
				'limit'  => 1,
			)
		);
		return is_wp_error( $result ) ? '' : $result['snapshot'];
	}

	/** @param array<string,mixed> $profile Provider profile. @return array<string,array<string,mixed>>|WP_Error */
	private function matching( $profile ) {
		$crons = _get_cron_array();
		if ( false === $crons ) {
			$crons = array();
		}
		if ( ! is_array( $crons ) ) {
			return new WP_Error( 'cron_inventory_unavailable', __( 'Native WordPress Cron inventory was unavailable.', 'wp-ai-bridge' ) );
		}
		$events = array();
		$key    = md5( serialize( $profile['args'] ) );
		foreach ( $crons as $when => $hooks ) {
			if ( ! isset( $hooks[ $profile['hook'] ][ $key ] ) ) {
				continue;
			}
			$native = $hooks[ $profile['hook'] ][ $key ];
			if ( ! is_array( $native ) || ! isset( $native['args'] ) || $native['args'] !== $profile['args'] ||
				! array_key_exists( 'schedule', $native ) || ( false !== $native['schedule'] && ! is_string( $native['schedule'] ) ) ) {
				return $this->invalid();
			}
			$identity            = hash_hmac( 'sha256', get_current_blog_id() . '|' . $when . '|' . $profile['hook'] . '|' . $key, wp_salt( 'auth' ) );
			$events[ $identity ] = array(
				'timestamp'  => (int) $when,
				'recurrence' => false === $native['schedule'] ? '' : $native['schedule'],
			);
		}
		return $events;
	}

	/** @param array<string,mixed> $profile Provider profile. @param int $when Future UTC. @param string $recurrence Exact registered cadence. @return array<string,mixed>|WP_Error */
	private function schedule( $profile, $when, $recurrence ) {
		$ok     = '' === $recurrence
			? wp_schedule_single_event( $when, $profile['hook'], $profile['args'], true )
			: wp_schedule_event( $when, $recurrence, $profile['hook'], $profile['args'], true );
		$actual = wp_get_scheduled_event( $profile['hook'], $profile['args'], $when );
		if ( true !== $ok || ! is_object( $actual ) ||
			( '' === $recurrence ? false !== $actual->schedule : $recurrence !== $actual->schedule ) ) {
			return new WP_Error( 'cron_outcome_unknown', __( 'WordPress did not verify the requested scheduled event. Inspect current schedules before retrying.', 'wp-ai-bridge' ) );
		}
		$key      = md5( serialize( $profile['args'] ) );
		$identity = hash_hmac( 'sha256', get_current_blog_id() . '|' . $when . '|' . $profile['hook'] . '|' . $key, wp_salt( 'auth' ) );
		// A new event existing at its timestamp is not yet a success receipt:
		// a native/provider writer outside Admission_Lock may have scheduled
		// another event for this exact profile in the same mutation window.
		return array( 'event_id' => $identity );
	}

	/**
	 * Re-read native Cron while the Bridge lock is held before reporting success.
	 *
	 * The Bridge lock serializes Bridge writers only. Ordinary WP-Cron writers
	 * do not participate, so an immediately observed competing event must be
	 * reported as an unknown outcome rather than a misleading clean success.
	 * WordPress event keys carry no writer provenance: deleting the new event
	 * automatically could also remove a competing writer's event at that key.
	 * Do not claim rollback or blindly retry an ambiguous native write.
	 *
	 * Two full inventory digests bracket the exact-profile read so a visible
	 * uncoordinated change during verification cannot yield a stale receipt.
	 *
	 * @param string                      $action     Requested action.
	 * @param string                      $status     Proposed verified status.
	 * @param array<string,mixed>         $profile    Fixed provider hook/args.
	 * @param string                      $event_id   Exact HMAC event identity.
	 * @param int                         $when       Expected event timestamp.
	 * @param string                      $recurrence Expected native schedule.
	 * @param Admission_Lock              $lock       Bridge-owned lock.
	 * @return array<string,mixed>|WP_Error
	 */
	private function confirmed_receipt( $action, $status, $profile, $event_id, $when, $recurrence, $lock ) {
		if ( ! $this->lock_owned( $lock ) ) {
			return $this->unverified_outcome();
		}
		$before = $this->snapshot();
		$events = $this->matching( $profile );
		$after  = $this->snapshot();
		if ( ! is_string( $before ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $before ) ||
			! is_string( $after ) || ! hash_equals( $before, $after ) ||
			is_wp_error( $events ) || ! $this->lock_owned( $lock ) ) {
			return $this->unverified_outcome();
		}
		if ( 'unscheduled' === $status ) {
			if ( $events ) {
				return $this->unverified_outcome();
			}
		} elseif ( 1 !== count( $events ) || ! isset( $events[ $event_id ] ) ||
			$when !== $events[ $event_id ]['timestamp'] ||
			$recurrence !== $events[ $event_id ]['recurrence'] ) {
			return $this->unverified_outcome();
		}
		return $this->receipt( $action, $status, $event_id, $when, $after );
	}

	/** @return WP_Error */
	private function unverified_outcome() {
		return new WP_Error( 'cron_outcome_unknown', __( 'The exact provider-managed Cron state changed or could not be verified. Inspect current events before any retry.', 'wp-ai-bridge' ) );
	}

	/** @return array<string,mixed> */
	private function receipt( $action, $status, $event_id, $timestamp, $snapshot, $profiles = array() ) {
		return array(
			'action'    => $action,
			'status'    => $status,
			'event_id'  => $event_id,
			'timestamp' => $timestamp,
			'snapshot'  => $snapshot,
			'profiles'  => $profiles,
		);
	}

	/** @return Admission_Lock|WP_Error */
	private function acquire_lock() {
		$lock = Admission_Lock::acquire( 'cron' );
		if ( false === $lock ) {
			return new WP_Error( 'cron_lock_busy', __( 'Another Cron change is in progress; inspect current schedules before retrying.', 'wp-ai-bridge' ) );
		}
		return $lock;
	}

	/** @return WP_Error */
	private function lock_lost() {
		return new WP_Error( 'cron_lock_lost', __( 'The Cron mutation lock was lost; inspect current events before retrying.', 'wp-ai-bridge' ) );
	}

	/** @param Admission_Lock $lock Site-bound coordinator. @return bool */
	private function lock_owned( $lock ) {
		return $lock instanceof Admission_Lock && $lock->is_owned();
	}
}
