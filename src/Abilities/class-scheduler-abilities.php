<?php
/**
 * Current-authority scheduler discovery and one-dispatch native lifecycle.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Administration_Access;
use WP_AI_Bridge\Support\Administration_Operation_Journal;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Hook_Dispatch_Recovery;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Option_Value;
use WP_AI_Bridge\Support\Native_Scheduler;
use WP_AI_Bridge\Support\Operation_Content_Limits;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/** No queue DML, caller callables, background subprocess or implicit retry. */
final class Scheduler_Abilities {
	/** @var Administration_Access */
	private $access;
	/** @var Mutation_Log */
	private $log;
	/** @var bool */
	private $in_flight = false;
	/** @var bool */
	private $dispatched = false;
	/** @var string */
	private $reference = '';
	/** @param Permissions $permissions Delegation. @param Mutation_Log|null $log Shared metadata audit. */
	public function __construct( Permissions $permissions, ?Mutation_Log $log = null ) {
		$this->access = new Administration_Access( $permissions );
		$this->log    = $log ? $log : new Mutation_Log();
	}
	/** @return array */
	public function register() {
		$registered = array();
		foreach ( array( 'read', 'manage' ) as $mode ) {
			$registered[] = wp_register_ability(
				'wp-ai-bridge/scheduler-' . $mode,
				array(
					'label'               => 'read' === $mode ? __( 'Inspect Native Schedulers', 'wp-ai-bridge' ) : __( 'Manage Native Schedulers', 'wp-ai-bridge' ),
					'description'         => 'read' === $mode
						? __( 'Discovers active native Core and Action Scheduler contracts and bounded live event metadata without callback arguments.', 'wp-ai-bridge' )
						: __( 'Schedules or cancels through public native APIs, or processes one Action Scheduler action. Mandatory operation IDs fence Bridge replay; native callbacks are not transactional or exactly once.', 'wp-ai-bridge' ),
					'category'            => Registrar::CATEGORY,
					'input_schema'        => $this->schema( $mode ),
					'output_schema'       => $this->output_schema( $mode ),
					'permission_callback' => array( $this, 'read' === $mode ? 'can_read' : 'can_manage' ),
					'execute_callback'    => array( $this, 'read' === $mode ? 'read' : 'manage' ),
					'meta'                => array(
						'mcp'         => array(
							'public' => true,
							'type'   => 'tool',
						),
						'annotations' => array(
							'readonly'    => 'read' === $mode,
							'destructive' => 'manage' === $mode,
							'idempotent'  => 'read' === $mode,
						),
					),
				)
			);
		}
		return array_values( array_filter( $registered, 'is_object' ) );
	}
	/** @param string $mode Fixed mode. @return array */
	private function schema( $mode ) {
		$properties = array(
			'action'            => array(
				'type' => 'string',
				'enum' => 'read' === $mode ? array( 'providers', 'list', 'get', 'recurrences' ) : array( 'schedule', 'cancel', 'run', 'status' ),
			),
			'provider'          => array(
				'type' => 'string',
				'enum' => array( 'wp_cron', 'action_scheduler' ),
			),
			'target_id'         => array( 'type' => 'string' ),
			'source_id'         => array( 'type' => 'string' ),
			'expected_snapshot' => array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			),
			'hook'              => array( 'type' => 'string' ),
			'group'             => array( 'type' => 'string' ),
			'status'            => array(
				'type' => 'string',
				'enum' => array( 'pending', 'in-progress', 'complete', 'failed', 'canceled' ),
			),
			'offset'            => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'limit'             => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'args'              => array( 'type' => array( 'array', 'object' ) ),
			'timestamp'         => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'schedule_type'     => array(
				'type' => 'string',
				'enum' => array( 'async', 'single', 'interval', 'cron' ),
			),
			'recurrence'        => array( 'type' => 'string' ),
			'interval'          => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'cron'              => array( 'type' => 'string' ),
			'priority'          => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 255,
			),
			'unique'            => array( 'type' => 'boolean' ),
			'operation_id'      => array(
				'type'      => 'string',
				'minLength' => 8,
				'maxLength' => 128,
			),
			'confirm'           => array( 'type' => 'boolean' ),
		);
		$keys       = 'read' === $mode ? array( 'action', 'provider', 'target_id', 'hook', 'group', 'status', 'offset', 'limit' ) : array( 'action', 'provider', 'target_id', 'source_id', 'expected_snapshot', 'hook', 'group', 'args', 'timestamp', 'schedule_type', 'recurrence', 'interval', 'cron', 'priority', 'unique', 'operation_id', 'confirm' );
		return array(
			'type'                 => 'object',
			'properties'           => array_intersect_key( $properties, array_flip( $keys ) ),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}
	/** @param string $mode Fixed public mode. @return array */
	private function output_schema( $mode ) {
		$event = array(
			'type'                 => 'object',
			'properties'           => array(
				'provider'            => array( 'type' => 'string' ),
				'target_id'           => array( 'type' => 'string' ),
				'hook'                => array( 'type' => 'string' ),
				'group'               => array( 'type' => 'string' ),
				'timestamp'           => array( 'type' => 'integer' ),
				'recurrence'          => array( 'type' => array( 'string', 'integer' ) ),
				'interval'            => array( 'type' => 'integer' ),
				'status'              => array( 'type' => 'string' ),
				'claimed'             => array( 'type' => 'boolean' ),
				'args_count'          => array( 'type' => 'integer' ),
				'callback_registered' => array( 'type' => 'boolean' ),
				'snapshot'            => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
		if ( 'read' === $mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(
					'providers'       => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'provider'     => array( 'type' => 'string' ),
								'available'    => array( 'type' => 'boolean' ),
								'actions'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'targeted_run' => array( 'type' => 'string' ),
							),
							'additionalProperties' => false,
						),
					),
					'other_providers' => array( 'type' => 'string' ),
					'items'           => array(
						'type'  => 'array',
						'items' => $event,
					),
					'item'            => $event,
					'schedule_types'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'next_offset'     => array( 'type' => 'integer' ),
					'has_more'        => array( 'type' => 'boolean' ),
					'pagination'      => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			);
		}
		$result = array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'outcome'               => array( 'type' => 'string' ),
				'reason'                => array( 'type' => 'string' ),
				'native_return'         => array( 'type' => array( 'integer', 'boolean', 'null' ) ),
				'native_api_threw'      => array( 'type' => 'boolean' ),
				'provider_status'       => array( 'type' => 'string' ),
				'observation_available' => array( 'type' => 'boolean' ),
				'business_effect'       => array( 'type' => 'string' ),
				'target_id'             => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'state'              => array( 'type' => 'string' ),
				'operation_ref'      => array( 'type' => 'string' ),
				'historical'         => array( 'type' => 'boolean' ),
				'dispatch_attempted' => array( 'type' => 'boolean' ),
				'audit_confirmed'    => array( 'type' => 'boolean' ),
				'receipt'            => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'created_at' => array( 'type' => 'string' ),
						'result'     => $result,
						'descriptor' => array(
							'type'                 => 'object',
							'properties'           => array(
								'action'    => array( 'type' => 'string' ),
								'provider'  => array( 'type' => 'string' ),
								'target_id' => array( 'type' => 'string' ),
								'source_id' => array( 'type' => 'string' ),
							),
							'additionalProperties' => false,
						),
					),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'state', 'operation_ref', 'historical', 'receipt', 'dispatch_attempted' ),
			'additionalProperties' => false,
		);
	}
	/** @return bool */
	public function can_read( $input = array() ) {
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $input ) {
					return $this->authorized( is_array( $input ) ? ( $input['action'] ?? 'read' ) : 'read', is_array( $input ) ? ( $input['provider'] ?? 'wp_cron' ) : 'wp_cron' );
				}
			);
		} catch ( \Throwable $error ) {
			return false;
		}
	}
	/** @param mixed $input Input action. @return bool */
	public function can_manage( $input = array() ) {
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $input ) {
					return is_array( $input ) && $this->authorized( $input['action'] ?? '', $input['provider'] ?? 'wp_cron' );
				}
			);
		} catch ( \Throwable $error ) {
			return false;
		}
	}
	/** @param string $action Fixed action. @return bool */
	private function authorized( $action, $provider = 'wp_cron' ) {
		$requirements = array( array( Settings::GROUP_SITE_CONFIG, 'manage_options' ) );
		if ( in_array( $action, array( 'cancel', 'run' ), true ) ) {
			$requirements[] = array( Settings::GROUP_USERS_DESTRUCTIVE, 'manage_options' );
		}
		if ( 'run' === $action ) {
			$requirements[] = array( Settings::GROUP_SCHEDULER_RUN, 'manage_options' );
			$requirements[] = array( Settings::GROUP_CODE_EXTENSIONS, 'activate_plugins' );
		}
		$network = 'providers' !== $action && is_multisite() && ( 'action_scheduler' !== $provider || ( new Native_Scheduler( 4096 ) )->requires_network() );
		return $this->access->allowed( Settings::GROUP_SCHEDULER_MANAGE, $network, $requirements );
	}
	/** @param mixed $input Read request. @return array|WP_Error */
	public function read( $input ) {
		if ( $this->in_flight ) {
			return $this->recursive_error();
		}
		$this->dispatched = false;
		$this->reference  = '';
		$valid            = $this->parse( $input, 'read' );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$this->in_flight = true;
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $input ) {
					$origin = $this->access->context();
					if ( ! $this->authorized( $input['action'], $input['provider'] ?? 'wp_cron' ) || ! $this->access->same_context( $origin ) ) {
							return $this->error( 'scheduler_denied' );
					}
					$config = $this->access->configuration();
					$budget = $this->budget( $config );
					if ( $budget < 1 || ( $input['limit'] ?? 10 ) > max( 1, intdiv( $budget, 512 ) ) ) {
						return $this->error( 'scheduler_capacity' );
					}
					$result = ( new Native_Scheduler( $budget ) )->read( $input );
					if ( ! $this->authorized( $input['action'], $input['provider'] ?? 'wp_cron' ) || ! $this->access->same_context( $origin ) ) {
						return $this->error( 'scheduler_context_changed' );
					}
					return is_wp_error( $result ) || Bounded_Payload::fits( $result ) ? $result : $this->error( 'scheduler_response_capacity' );
				}
			);
		} catch ( \Throwable $error ) {
			return $this->error( 'scheduler_read_unavailable' );
		} finally {
			$this->in_flight = false;
		}
	}
	/** @param mixed $input Effect or historical status. @return array|WP_Error */
	public function manage( $input ) {
		if ( $this->in_flight ) {
			return $this->recursive_error();
		}
		$this->dispatched = false;
		$this->reference  = '';
		$valid            = $this->parse( $input, 'manage' );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$this->in_flight = true;
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $input ) {
					$origin = $this->access->context();
					if ( ! $this->authorized( $input['action'], $input['provider'] ?? 'wp_cron' ) || ! $this->access->same_context( $origin ) ) {
							return $this->error( 'scheduler_denied' );
					}
					$config = $this->access->configuration();
					$budget = $this->budget( $config );
					if ( $budget < 4096 ) {
						return $this->error( 'scheduler_capacity' );
					}
					$journal = new Administration_Operation_Journal( 'scheduler', array_merge( array( 'scope' => 'site' ), $origin ), $budget );
					if ( 'status' === $input['action'] ) {
						$result = $journal->status( $input['operation_id'] );
						if ( null === $result ) {
							$result = array(
								'state'         => 'not_recorded',
								'operation_ref' => '',
								'historical'    => true,
								'receipt'       => null,
							);
						}
					} else {
						$result = $this->effect( $input, $origin, $budget, $journal );
					}
					if ( ! $this->authorized( 'status', $input['provider'] ?? 'wp_cron' ) || ! $this->access->same_context( $origin ) || true !== $journal->check_context() ) {
						return $this->error( 'scheduler_context_changed' );
					}
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					if ( is_array( $result['receipt'] ?? null ) ) {
						$receipt = $result['receipt'];
						if ( ( $receipt['descriptor']['provider'] ?? null ) !== $input['provider'] ) {
							return $this->error( 'scheduler_operation_provider_mismatch' );
						}
						$result['receipt'] = array(
							'descriptor' => $receipt['descriptor'],
							'result'     => $receipt['result'],
							'created_at' => $receipt['created_at'],
						);
					}
					unset( $result['claim'], $result['dispatch_allowed'] );
					$result['dispatch_attempted'] = $this->dispatched;
					return Bounded_Payload::fits( $result ) ? $result : $this->error( 'scheduler_response_capacity' );
				}
			);
		} catch ( \Throwable $error ) {
			return $this->error( 'scheduler_outcome_unavailable' );
		} finally {
			$this->in_flight = false;
		}
	}
	/** @param array $input Parsed request. @param array $origin Captured identity. @param int $budget Metadata allowance. @param Administration_Operation_Journal $journal Shared journal. @return array|WP_Error */
	private function effect( array $input, array $origin, $budget, Administration_Operation_Journal $journal ) {
		$scheduler = new Native_Scheduler( $budget );
		$plan      = $scheduler->plan( $input );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		ksort( $input );
		$document = Native_Option_Value::encode( $input, $budget );
		if ( is_wp_error( $document ) ) {
			return $this->error( 'scheduler_input_capacity' );
		}
		$reserved = $journal->reserve(
			$input['operation_id'],
			$document['sha256'],
			array(
				'action'    => $input['action'],
				'provider'  => $input['provider'],
				'target_id' => $input['target_id'] ?? '',
				'source_id' => $input['source_id'] ?? '',
			)
		);
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}
		$this->reference = $reserved['operation_ref'];
		if ( empty( $reserved['dispatch_allowed'] ) ) {
			return $reserved;
		}
		$claim = $reserved['claim'];
		// Fresh target observation after the journal reservation. Native APIs have
		// no target CAS; later provider changes are not hidden as atomicity.
		$plan = $scheduler->plan( $input );
		if ( is_wp_error( $plan ) || ! $this->authorized( $input['action'], $input['provider'] ?? 'wp_cron' ) || ! $this->access->same_context( $origin ) ) {
			return $journal->complete(
				$claim,
				array(
					'outcome' => 'not_dispatched',
					'reason'  => 'target_or_authority_changed',
				)
			);
		}
		$baseline = Hook_Dispatch_Recovery::begin_scope();
		$native   = null;
		$threw    = false;
		try {
			$armed = $journal->arm( $claim );
			if ( true !== $armed ) {
				return is_wp_error( $armed ) ? $armed : $this->error( 'scheduler_arm_unavailable' );
			}
			$this->dispatched = true;
			$native           = $scheduler->dispatch( $plan );
		} catch ( \Throwable $error ) {
			Hook_Dispatch_Recovery::restore_or_terminate( $baseline );
			$threw = true;
		} finally {
			// Action Scheduler may catch provider exceptions internally, leaving
			// an escaped WP_Hook stack even though its API returned normally.
			$current = Hook_Dispatch_Recovery::snapshot();
			if ( $current->stack !== $baseline->stack ) {
				Hook_Dispatch_Recovery::restore_or_terminate( $baseline );
			}
			Hook_Dispatch_Recovery::end_scope( $baseline );
		}
		if ( ! $this->dispatched ) {
			return $this->error( 'scheduler_arm_unavailable' );
		}
		$captured = array(
			'outcome'               => 'unknown',
			'native_return'         => null,
			'native_api_threw'      => $threw,
			'provider_status'       => '',
			'observation_available' => false,
			'business_effect'       => 'unverified',
		);
		try {
			$captured = Hook_Dispatch_Recovery::run(
				function () use ( $scheduler, $plan, $native, $threw ) {
					return $scheduler->observe( $plan, $native, $threw );
				}
			);
		} catch ( \Throwable $error ) {
			// The dispatch remains fenced when provider readback fails.
		}
		$completed = $journal->complete( $claim, $captured );
		$audited   = false;
		try {
			$audited = Hook_Dispatch_Recovery::run(
				function () use ( $captured ) {
					return $this->log->record( 'wp-ai-bridge/scheduler-manage', 'scheduler', 0, 'native_acknowledged' === $captured['outcome'], 'unknown' === $captured['outcome'] ? 'scheduler_outcome_unknown' : '' );
				}
			);
		} catch ( \Throwable $error ) {
			$audited = false;
		}
		if ( is_array( $completed ) ) {
			$completed['audit_confirmed'] = $audited;
		}
		return $completed;
	}
	/** @param mixed $input Request. @param string $mode Fixed mode. @return true|WP_Error */
	private function parse( $input, $mode ) {
		if ( ! is_array( $input ) || ! is_string( $input['action'] ?? null ) ) {
			return $this->error( 'scheduler_invalid' );
		}
		$action      = $input['action'];
		$read_keys   = array(
			'providers'   => array(),
			'list'        => array( 'provider', 'hook', 'group', 'status', 'offset', 'limit' ),
			'get'         => array( 'provider', 'target_id' ),
			'recurrences' => array( 'provider' ),
		);
		$manage_keys = array(
			'status'   => array( 'provider', 'operation_id' ),
			'cancel'   => array( 'provider', 'target_id', 'expected_snapshot', 'operation_id', 'confirm' ),
			'run'      => array( 'provider', 'target_id', 'expected_snapshot', 'operation_id', 'confirm' ),
			'schedule' => array( 'provider', 'source_id', 'expected_snapshot', 'hook', 'args', 'timestamp', 'schedule_type', 'recurrence', 'interval', 'cron', 'group', 'priority', 'unique', 'operation_id', 'confirm' ),
		);
		$keys        = 'read' === $mode ? $read_keys : $manage_keys;
		if ( ! isset( $keys[ $action ] ) || array_diff( array_keys( $input ), array_merge( array( 'action' ), $keys[ $action ] ) ) ) {
			return $this->error( 'scheduler_invalid' );
		}
		if ( ! in_array( $action, array( 'providers' ), true ) && ! in_array( $input['provider'] ?? null, array( 'wp_cron', 'action_scheduler' ), true ) ) {
			return $this->error( 'scheduler_invalid' );
		}
		foreach ( array( 'target_id', 'source_id', 'hook', 'group', 'recurrence', 'cron', 'expected_snapshot', 'operation_id' ) as $key ) {
			if ( array_key_exists( $key, $input ) && ( ! is_string( $input[ $key ] ) || 1 !== preg_match( '//u', $input[ $key ] ) ) ) {
				return $this->error( 'scheduler_invalid' );
			}
		}
		foreach ( array(
			'offset'    => 0,
			'limit'     => 1,
			'timestamp' => 1,
			'interval'  => 1,
			'priority'  => 0,
		) as $key => $min ) {
			if ( array_key_exists( $key, $input ) && ( ! is_int( $input[ $key ] ) || $input[ $key ] < $min || PHP_INT_MAX === $input[ $key ] ) ) {
				return $this->error( 'scheduler_invalid' );
			}
		}
		if ( ( array_key_exists( 'priority', $input ) && $input['priority'] > 255 ) ||
			( array_key_exists( 'unique', $input ) && ! is_bool( $input['unique'] ) ) ||
			( array_key_exists( 'args', $input ) && ! is_array( $input['args'] ) ) ||
			( array_key_exists( 'schedule_type', $input ) && ! in_array( $input['schedule_type'], array( 'async', 'single', 'interval', 'cron' ), true ) ) ||
			( array_key_exists( 'status', $input ) && ! in_array( $input['status'], array( 'pending', 'in-progress', 'complete', 'failed', 'canceled' ), true ) ) ) {
			return $this->error( 'scheduler_invalid' );
		}
		if ( 'manage' === $mode && ( ! is_string( $input['operation_id'] ?? null ) ||
			1 !== preg_match( '/^[A-Za-z0-9_.:-]{8,128}$/D', $input['operation_id'] ) ||
			( 'status' !== $action && true !== ( $input['confirm'] ?? null ) ) ) ) {
			return $this->error( 'scheduler_invalid' );
		}
		if ( in_array( $action, array( 'cancel', 'run', 'get' ), true ) && '' === ( $input['target_id'] ?? '' ) ) {
			return $this->error( 'scheduler_invalid' );
		}
		if ( ( in_array( $action, array( 'cancel', 'run' ), true ) || array_key_exists( 'source_id', $input ) ) &&
			( ! isset( $input['expected_snapshot'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['expected_snapshot'] ) ) ) {
			return $this->error( 'scheduler_invalid' );
		}
		if ( array_key_exists( 'source_id', $input ) && ( isset( $input['hook'] ) || array_key_exists( 'args', $input ) || isset( $input['group'] ) ) ) {
			return $this->error( 'scheduler_invalid' );
		}
		if ( 'list' === $action && 'wp_cron' === $input['provider'] && ( isset( $input['group'] ) || isset( $input['status'] ) ) ) {
			return $this->error( 'scheduler_invalid' );
		}
		if ( 'schedule' === $action ) {
			$type = $input['schedule_type'] ?? 'single';
			if ( 'wp_cron' === $input['provider'] ) {
				if ( isset( $input['group'] ) || isset( $input['priority'] ) || isset( $input['unique'] ) || isset( $input['interval'] ) || isset( $input['cron'] ) ||
					! in_array( $type, array( 'single', 'interval' ), true ) || ( 'single' === $type && isset( $input['recurrence'] ) ) ) {
					return $this->error( 'scheduler_invalid' );
				}
			} elseif ( isset( $input['recurrence'] ) || ( 'interval' !== $type && isset( $input['interval'] ) ) ||
				( 'cron' !== $type && isset( $input['cron'] ) ) || ( 'async' === $type && isset( $input['timestamp'] ) ) ) {
				return $this->error( 'scheduler_invalid' );
			}
		}
		return true;
	}
	/** @param array $config Protected finite work configuration. @return int Resource-admitted metadata allowance. */
	private function budget( array $config ) {
		$budget = (int) ( $config['step_bytes'] ?? 0 );
		$memory = Operation_Content_Limits::effective_memory_bytes();
		return $memory > 0 ? min( $budget, max( 0, intdiv( $memory - memory_get_usage( true ), 16 ) ) ) : $budget;
	}
	/** @return WP_Error Nested invocation has not dispatched its own effect. */
	private function recursive_error() {
		return new WP_Error(
			'scheduler_recursive',
			__( 'A native scheduler operation is already active in this invocation.', 'wp-ai-bridge' ),
			array(
				'dispatch_attempted' => false,
				'operation_ref'      => '',
			)
		);
	}
	/** @param string $code Fixed safe error. @return WP_Error */
	private function error( $code ) {
		return new WP_Error(
			$code,
			__( 'The native scheduler request is invalid, unavailable or no longer authorized. Inspect its operation status before repeating an uncertain effect.', 'wp-ai-bridge' ),
			array(
				'dispatch_attempted' => $this->dispatched,
				'operation_ref'      => $this->reference,
			)
		);
	}
}
