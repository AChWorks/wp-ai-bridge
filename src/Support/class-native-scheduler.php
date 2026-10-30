<?php
/**
 * Focused public WordPress Cron and Action Scheduler adapters.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support;

use WP_Error;

/** Native stores and callbacks own effects; this adapter never writes queue SQL. */
final class Native_Scheduler {
	/** @var int */
	private $budget;
	/** @param int $budget Finite native metadata/input allowance. */
	public function __construct( $budget ) {
		$this->budget = $budget;
	}
	/** @return array Feature discovery, not authorization. */
	public function providers() {
		$as = $this->action_scheduler();
		return array(
			array(
				'provider'     => 'wp_cron',
				'available'    => function_exists( '_get_cron_array' ),
				'actions'      => array( 'list', 'get', 'recurrences', 'schedule', 'cancel' ),
				'targeted_run' => 'installed_cron_targeted_runner_only',
			),
			array(
				'provider'     => 'action_scheduler',
				'available'    => $as,
				'actions'      => $as ? array( 'list', 'get', 'schedule', 'cancel', 'run' ) : array(),
				'targeted_run' => $as ? 'native_provider_processor' : 'unavailable',
			),
		);
	}
	/** Supported built-in stores own site selection; unknown custom stores do not establish tenant isolation.
	 * @return bool Whether multisite use needs network-wide native authority.
	 */
	public function requires_network() {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return false;
		}
		if ( ! $this->action_scheduler() ) {
			return true;
		}
		$store = \ActionScheduler::store();
		$class = get_class( $store );
		if ( ! in_array( $class, array( 'ActionScheduler_DBStore', 'ActionScheduler_wpPostStore', 'ActionScheduler_HybridStore' ), true ) ) {
			return true;
		}
		global $wpdb;
		// This is the built-in provider's declared table mapping, not a guess
		// that arbitrary similarly-prefixed tables belong to this site.
		return 'ActionScheduler_wpPostStore' !== $class &&
			( ! isset( $wpdb->actionscheduler_actions, $wpdb->prefix ) || $wpdb->actionscheduler_actions !== $wpdb->prefix . 'actionscheduler_actions' );
	}
	/** @return bool Current active public Action Scheduler contract. */
	private function action_scheduler() {
		return class_exists( 'ActionScheduler', false ) && method_exists( 'ActionScheduler', 'is_initialized' ) &&
			\ActionScheduler::is_initialized() && function_exists( 'as_get_scheduled_actions' ) &&
			function_exists( 'as_schedule_single_action' ) && function_exists( 'as_schedule_recurring_action' ) &&
			function_exists( 'as_schedule_cron_action' ) && function_exists( 'as_enqueue_async_action' );
	}
	/** @param array $input Valid read input. @return array|WP_Error */
	public function read( array $input ) {
		$action = $input['action'];
		if ( 'providers' === $action ) {
			return array(
				'providers'       => $this->providers(),
				'other_providers' => 'native_abilities_and_registered_rest',
			);
		}
		if ( 'recurrences' === $action ) {
			if ( 'wp_cron' !== $input['provider'] ) {
				return array(
					'items'          => array(),
					'schedule_types' => array( 'async', 'single', 'interval', 'cron' ),
				);
			}
			$items = array();
			foreach ( wp_get_schedules() as $name => $schedule ) {
				if ( is_string( $name ) && 1 === preg_match( '//u', $name ) && is_array( $schedule ) && is_int( $schedule['interval'] ?? null ) && $schedule['interval'] > 0 ) {
					$items[] = array(
						'recurrence' => $name,
						'interval'   => $schedule['interval'],
					);
				}
			}
			return array( 'items' => $items );
		}
		if ( 'get' === $action ) {
			$target = $this->target( $input['provider'], $input['target_id'] );
			return is_wp_error( $target ) ? $target : array( 'item' => $target['public'] );
		}
		$offset = $input['offset'] ?? 0;
		$limit  = $input['limit'] ?? 10;
		$items  = array();
		if ( 'wp_cron' === $input['provider'] ) {
			$raw = _get_cron_array();
			if ( false !== $raw && ! is_array( $raw ) ) {
				return $this->error( 'scheduler_inventory_unavailable' );
			}
			$matching = 0;
			foreach ( (array) $raw as $timestamp => $hooks ) {
				if ( ! is_array( $hooks ) ) {
					return $this->error( 'scheduler_inventory_invalid' );
				}
				foreach ( $hooks as $hook => $events ) {
					if ( ! is_array( $events ) ) {
						return $this->error( 'scheduler_inventory_invalid' );
					}
					foreach ( $events as $key => $event ) {
						if ( isset( $input['hook'] ) && $hook !== $input['hook'] ) {
							continue;
						}
						if ( $matching++ < $offset ) {
							continue;
						}
						$target = $this->core_target( $timestamp, $hook, $key, $event );
						if ( is_wp_error( $target ) ) {
							return $target;
						}
						$items[] = $target['public'];
						if ( count( $items ) > $limit ) {
							break 3;
						}
					}
				}
			}
		} else {
			if ( ! $this->action_scheduler() ) {
				return $this->error( 'scheduler_provider_unavailable' );
			}
			$query = array(
				'per_page' => $limit + 1,
				'offset'   => $offset,
				'orderby'  => 'date',
				'order'    => 'ASC',
			);
			foreach ( array( 'hook', 'group', 'status' ) as $key ) {
				if ( isset( $input[ $key ] ) ) {
					$query[ $key ] = $input[ $key ];
				}
			}
			$ids = as_get_scheduled_actions( $query, 'ids' );
			if ( ! is_array( $ids ) ) {
				return $this->error( 'scheduler_inventory_unavailable' );
			}
			foreach ( $ids as $id ) {
				$target = $this->target( 'action_scheduler', (string) $id );
				if ( is_wp_error( $target ) ) {
					return $target;
				}
				$items[] = $target['public'];
			}
		}
		$more = count( $items ) > $limit;
		if ( $more ) {
			array_pop( $items );
		}
		return array(
			'items'       => $items,
			'next_offset' => $offset + count( $items ),
			'has_more'    => $more,
			'pagination'  => 'live_offset_not_snapshot',
		);
	}
	/** @param string $provider Native provider. @param string $id Opaque Core ID or provider action ID. @return array|WP_Error Internal target. */
	public function target( $provider, $id ) {
		if ( 'wp_cron' === $provider ) {
			foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
				if ( ! is_array( $hooks ) ) {
					return $this->error( 'scheduler_inventory_invalid' );
				}
				foreach ( $hooks as $hook => $events ) {
					if ( ! is_array( $events ) ) {
						return $this->error( 'scheduler_inventory_invalid' );
					}
					foreach ( $events as $key => $event ) {
						$identity = hash_hmac( 'sha256', get_current_blog_id() . '|' . $timestamp . '|' . $hook . '|' . $key, wp_salt( 'auth' ) );
						if ( hash_equals( $identity, $id ) ) {
							return $this->core_target( $timestamp, $hook, $key, $event );
						}
					}
				}
			}
			return $this->error( 'scheduler_target_not_found' );
		}
		if ( ! $this->action_scheduler() || ! ctype_digit( $id ) || (string) (int) $id !== $id || (int) $id < 1 ) {
			return $this->error( 'scheduler_provider_or_target_unavailable' );
		}
		$store = \ActionScheduler::store();
		foreach ( array( 'fetch_action', 'get_status', 'get_claim_id', 'cancel_action' ) as $method ) {
			if ( ! is_callable( array( $store, $method ) ) ) {
				return $this->error( 'scheduler_provider_unavailable' );
			}
		}
		$action = $store->fetch_action( (int) $id );
		if ( ! is_object( $action ) || is_a( $action, 'ActionScheduler_NullAction' ) ) {
			return $this->error( 'scheduler_target_not_found' );
		}
		$args    = $action->get_args();
		$encoded = Native_Option_Value::encode( $args, $this->budget );
		if ( is_wp_error( $encoded ) || ! is_array( $args ) ) {
			return $this->error( 'scheduler_arguments_unavailable' );
		}
		$schedule   = $action->get_schedule();
		$date       = $schedule->get_date();
		$recurrence = $schedule->is_recurring() && method_exists( $schedule, 'get_recurrence' ) ? $schedule->get_recurrence() : '';
		if ( ! is_string( $recurrence ) && ! is_int( $recurrence ) ) {
			return $this->error( 'scheduler_schedule_unsupported' );
		}
		$target = array(
			'provider'   => $provider,
			'id'         => $id,
			'hook'       => $action->get_hook(),
			'args'       => $args,
			'group'      => $action->get_group(),
			'priority'   => method_exists( $action, 'get_priority' ) ? $action->get_priority() : 10,
			'timestamp'  => $date instanceof \DateTimeInterface ? $date->getTimestamp() : 0,
			'recurrence' => $recurrence,
			'status'     => $store->get_status( (int) $id ),
			'claim_id'   => (int) $store->get_claim_id( (int) $id ),
			'store'      => $store,
			'runner'     => \ActionScheduler::runner(),
		);
		return $this->normalize( $target, $encoded['sha256'] );
	}
	/** @param mixed $timestamp Native timestamp. @param mixed $hook Native hook. @param mixed $key Native argument key. @param mixed $event Native row. @return array|WP_Error */
	private function core_target( $timestamp, $hook, $key, $event ) {
		if ( ! ctype_digit( (string) $timestamp ) || (string) (int) $timestamp !== (string) $timestamp || ! is_string( $hook ) || ! is_array( $event ) ||
			! is_array( $event['args'] ?? null ) || ! array_key_exists( 'schedule', $event ) ||
			( false !== $event['schedule'] && ! is_string( $event['schedule'] ) ) ) {
			return $this->error( 'scheduler_inventory_invalid' );
		}
		$encoded = Native_Option_Value::encode( $event['args'], $this->budget );
		if ( is_wp_error( $encoded ) || ! is_string( $key ) || md5( serialize( $event['args'] ) ) !== $key ) {
			return $this->error( 'scheduler_arguments_unavailable' );
		}
		$id = hash_hmac( 'sha256', get_current_blog_id() . '|' . $timestamp . '|' . $hook . '|' . $key, wp_salt( 'auth' ) );
		return $this->normalize(
			array(
				'provider'   => 'wp_cron',
				'id'         => $id,
				'hook'       => $hook,
				'args'       => $event['args'],
				'group'      => '',
				'priority'   => 10,
				'timestamp'  => (int) $timestamp,
				'recurrence' => false === $event['schedule'] ? '' : $event['schedule'],
				'status'     => 'scheduled',
				'claim_id'   => 0,
			),
			$encoded['sha256']
		);
	}
	/** @param array $target Internal native target. @param string $args_hash Complete encoded arguments digest. @return array|WP_Error */
	private function normalize( array $target, $args_hash ) {
		foreach ( array( 'hook', 'group', 'status' ) as $key ) {
			if ( ! is_string( $target[ $key ] ) || 1 !== preg_match( '//u', $target[ $key ] ) ) {
				return $this->error( 'scheduler_metadata_unavailable' );
			}
		}
		$row              = array(
			'provider'            => $target['provider'],
			'target_id'           => $target['id'],
			'hook'                => $target['hook'],
			'group'               => $target['group'],
			'timestamp'           => $target['timestamp'],
			'recurrence'          => $target['recurrence'],
			'status'              => $target['status'],
			'claimed'             => 0 !== $target['claim_id'],
			'args_count'          => count( $target['args'] ),
			'callback_registered' => false !== has_action( $target['hook'] ),
		);
		$row['snapshot']  = hash_hmac( 'sha256', wp_json_encode( array( get_current_blog_id(), $row, $target['priority'], $target['claim_id'], $args_hash ) ), wp_salt( 'auth' ) );
		$target['public'] = $row;
		return $target;
	}
	/** @param array $input Valid action. @return array|WP_Error Plan holds only trusted public-API dispatch data. */
	public function plan( array $input ) {
		$provider = $input['provider'];
		$action   = $input['action'];
		if ( 'wp_cron' !== $provider && ! $this->action_scheduler() ) {
			return $this->error( 'scheduler_provider_unavailable' );
		}
		$target = null;
		$id     = $input['target_id'] ?? ( $input['source_id'] ?? '' );
		if ( '' !== $id ) {
			$target = $this->target( $provider, $id );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			if ( ! hash_equals( $target['public']['snapshot'], $input['expected_snapshot'] ?? '' ) ) {
				return $this->error( 'scheduler_target_stale' );
			}
		}
		if ( 'schedule' !== $action ) {
			if ( null === $target ) {
				return $this->error( 'scheduler_target_not_found' );
			}
			if ( 'run' === $action && 'wp_cron' === $provider ) {
				return $this->error( 'scheduler_core_targeted_runner_required' );
			}
			if ( 'action_scheduler' === $provider && ( 'pending' !== $target['status'] || 0 !== $target['claim_id'] ) ) {
				return $this->error( 'scheduler_target_not_pending_or_claimed' );
			}
			if ( 'run' === $action && ( ! is_callable( array( $target['runner'], 'process_action' ) ) || false === has_action( $target['hook'] ) ||
				'wp_maybe_auto_update' === $target['hook'] ) ) {
				return $this->error( 'scheduler_run_unsupported' );
			}
			return array(
				'provider' => $provider,
				'action'   => $action,
				'target'   => $target,
			);
		}
		$hook    = null !== $target ? $target['hook'] : ( $input['hook'] ?? '' );
		$args    = null !== $target ? $target['args'] : ( $input['args'] ?? array() );
		$encoded = Native_Option_Value::encode( $args, $this->budget );
		if ( ! is_string( $hook ) || '' === $hook || false !== strpos( $hook, "\0" ) || 1 !== preg_match( '//u', $hook ) ||
			strlen( $hook ) > $this->budget || ! is_array( $args ) || is_wp_error( $encoded ) || false === has_action( $hook ) || 'wp_maybe_auto_update' === $hook ) {
			return $this->error( 'scheduler_hook_or_arguments_unsupported' );
		}
		$type       = $input['schedule_type'] ?? 'single';
		$timestamp  = $input['timestamp'] ?? 0;
		$recurrence = $input['recurrence'] ?? '';
		$interval   = $input['interval'] ?? 0;
		$cron       = $input['cron'] ?? '';
		if ( 'wp_cron' === $provider ) {
			if ( ! in_array( $type, array( 'single', 'interval' ), true ) || $timestamp < 1 ) {
				return $this->error( 'scheduler_schedule_unsupported' );
			}
			if ( 'interval' === $type ) {
				$schedules = wp_get_schedules();
				if ( '' === $recurrence || ! isset( $schedules[ $recurrence ] ) ) {
					return $this->error( 'scheduler_recurrence_unavailable' );
				}
			}
		} elseif ( ( 'async' !== $type && $timestamp < 1 ) || ( 'interval' === $type && $interval < 1 ) || ( 'cron' === $type && '' === $cron ) ) {
			return $this->error( 'scheduler_schedule_unsupported' );
		}
		return array(
			'provider'      => $provider,
			'action'        => $action,
			'hook'          => $hook,
			'args'          => $args,
			'group'         => null !== $target ? $target['group'] : ( $input['group'] ?? '' ),
			'priority'      => $input['priority'] ?? ( null !== $target ? $target['priority'] : 10 ),
			'timestamp'     => $timestamp,
			'schedule_type' => $type,
			'recurrence'    => $recurrence,
			'interval'      => $interval,
			'cron'          => $cron,
			'unique'        => $input['unique'] ?? false,
		);
	}
	/** Exactly one supported native mutating API. No discovery/permission hooks before that API.
	 * @param array $plan Prepared private plan. @return mixed Native return.
	 */
	public function dispatch( array $plan ) {
		if ( 'schedule' !== $plan['action'] ) {
			$target = $plan['target'];
			if ( 'wp_cron' === $plan['provider'] ) {
				return wp_unschedule_event( $target['timestamp'], $target['hook'], $target['args'], true );
			}
			if ( 'cancel' === $plan['action'] ) {
				return $target['store']->cancel_action( (int) $target['id'] );
			}
			return $target['runner']->process_action( (int) $target['id'], 'WP AI Bridge' );
		}
		if ( 'wp_cron' === $plan['provider'] ) {
			return 'single' === $plan['schedule_type']
				? wp_schedule_single_event( $plan['timestamp'], $plan['hook'], $plan['args'], true )
				: wp_schedule_event( $plan['timestamp'], $plan['recurrence'], $plan['hook'], $plan['args'], true );
		}
		switch ( $plan['schedule_type'] ) {
			case 'async':
				return as_enqueue_async_action( $plan['hook'], $plan['args'], $plan['group'], $plan['unique'], $plan['priority'] );
			case 'interval':
				return as_schedule_recurring_action( $plan['timestamp'], $plan['interval'], $plan['hook'], $plan['args'], $plan['group'], $plan['unique'], $plan['priority'] );
			case 'cron':
				return as_schedule_cron_action( $plan['timestamp'], $plan['cron'], $plan['hook'], $plan['args'], $plan['group'], $plan['unique'], $plan['priority'] );
			default:
				return as_schedule_single_action( $plan['timestamp'], $plan['hook'], $plan['args'], $plan['group'], $plan['unique'], $plan['priority'] );
		}
	}
	/** @param array $plan Dispatched plan. @param mixed $native Native return. @param bool $threw Escaped exception. @return array Fixed non-sensitive observations. */
	public function observe( array $plan, $native, $threw ) {
		$result = array(
			'outcome'               => 'unknown',
			'native_return'         => is_bool( $native ) || is_int( $native ) || null === $native ? $native : null,
			'native_api_threw'      => $threw,
			'provider_status'       => '',
			'observation_available' => false,
			'business_effect'       => 'unverified',
		);
		if ( 'schedule' === $plan['action'] ) {
			if ( 'wp_cron' === $plan['provider'] ) {
				$event                           = wp_get_scheduled_event( $plan['hook'], $plan['args'], $plan['timestamp'] );
				$result['observation_available'] = true;
				$result['provider_status']       = is_object( $event ) ? 'scheduled' : 'absent';
				$result['outcome']               = true === $native && is_object( $event ) && ! $threw ? 'native_acknowledged' : 'unknown';
			} elseif ( is_int( $native ) && $native > 0 ) {
				$target                          = $this->target( 'action_scheduler', (string) $native );
				$result['observation_available'] = ! is_wp_error( $target );
				$result['provider_status']       = is_wp_error( $target ) ? '' : $target['status'];
				$result['target_id']             = (string) $native;
				$result['outcome']               = ! is_wp_error( $target ) && $target['hook'] === $plan['hook'] && $target['args'] === $plan['args'] &&
					$target['group'] === $plan['group'] && ! $threw ? 'native_acknowledged' : 'unknown';
			}
		} elseif ( 'wp_cron' === $plan['provider'] ) {
			$target                          = $plan['target'];
			$event                           = wp_get_scheduled_event( $target['hook'], $target['args'], $target['timestamp'] );
			$result['observation_available'] = true;
			$result['provider_status']       = false === $event ? 'absent' : 'scheduled';
			$result['outcome']               = true === $native && false === $event && ! $threw ? 'native_acknowledged' : 'unknown';
		} else {
			$status                          = $plan['target']['store']->get_status( (int) $plan['target']['id'] );
			$result['provider_status']       = $status;
			$result['observation_available'] = true;
			$result['outcome']               = ! $threw && ( ( 'cancel' === $plan['action'] && 'canceled' === $status ) ||
				( 'run' === $plan['action'] && 'complete' === $status ) ) ? ( 'run' === $plan['action'] ? 'provider_status_observed' : 'native_acknowledged' ) : ( 'failed' === $status ? 'provider_failed' : 'unknown' );
		}
		return $result;
	}
	/** @param string $code Fixed error. @return WP_Error */
	private function error( $code ) {
		return new WP_Error( $code, __( 'The selected native scheduler contract, target or observation is unavailable.', 'wp-ai-bridge' ) );
	}
}
