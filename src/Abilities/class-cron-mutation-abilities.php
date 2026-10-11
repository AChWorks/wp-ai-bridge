<?php
/**
 * Provider-opted-in, exact-identity native WordPress scheduled-event lifecycle.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Admission_Lock;
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
				'description'         => __( 'Schedules, reschedules or cancels only provider-opted-in exact WordPress Cron events. Arguments are never supplied by a remote client. Targeted execution is not available without a safe provider runner.', 'wp-ai-bridge' ),
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
			if ( array_diff( $actions, array( 'schedule', 'unschedule', 'reschedule' ) ) ||
				! count( $actions ) || count( $actions ) !== count( $profile['actions'] ) ) {
				continue;
			}
			$min = $profile['min_interval_seconds'] ?? 60;
			if ( ! is_int( $min ) || $min < 60 || $min > DAY_IN_SECONDS ) {
				continue;
			}
			$profiles[ $id ] = array(
				'hook'     => $profile['hook'],
				'args'     => $profile['args'],
				'actions'  => $actions,
				'interval' => $min,
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
			$snapshot = hash_hmac( 'sha256', serialize( array( get_current_blog_id(), $profiles ) ), wp_salt( 'auth' ) );
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
				if ( Bounded_Payload::fits( $result ) ) {
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
			// Core wp-cron.php runs all due callbacks under a global lock,
			// not one verified event. Running do_action() would bypass the
			// registered provider's scheduling/locking/idempotency contract.
			return new WP_Error( 'cron_run_now_unsupported', __( 'Targeted execution requires a provider-owned, idempotent runner and verified receipt. WordPress Core cannot safely run this selected event on demand.', 'wp-ai-bridge' ) );
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
				return $this->schedule( $action, $profile, $timestamp, $recurrence );
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
				$exists = wp_get_scheduled_event( $profile['hook'], $profile['args'], $prior['timestamp'] );
				if ( true !== $result || false !== $exists ) {
					return new WP_Error( 'cron_outcome_unknown', __( 'WordPress did not verify cancellation of the exact selected Cron event. Inspect current schedules before any retry.', 'wp-ai-bridge' ) );
				}
				return $this->receipt( $action, 'unscheduled', $event_id, $prior['timestamp'], $this->snapshot() );
			}
			if ( 1 !== count( $events ) ) {
				return new WP_Error( 'cron_duplicate_profile', __( 'An event for this exact provider-managed Cron profile already exists.', 'wp-ai-bridge' ) );
			}
			if ( $timestamp === $prior['timestamp'] && $recurrence === $prior['recurrence'] ) {
				return $this->receipt( $action, 'unchanged', $event_id, $timestamp, $inventory['snapshot'] );
			}
			if ( ! $this->lock_owned( $lock ) ) {
				return $this->lock_lost();
			}
			$created = $this->schedule( $action, $profile, $timestamp, $recurrence );
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
			return $this->receipt( $action, 'rescheduled', $created['event_id'], $timestamp, $this->snapshot() );
		} finally {
			$lock->release();
		}
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

	/** @param string $action Operation. @param array<string,mixed> $profile Provider profile. @param int $when Future UTC. @param string $recurrence Exact registered cadence. @return array<string,mixed>|WP_Error */
	private function schedule( $action, $profile, $when, $recurrence ) {
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
		return $this->receipt( $action, 'scheduled', $identity, $when, $this->snapshot() );
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
