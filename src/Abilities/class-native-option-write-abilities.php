<?php
/**
 * Fenced native Options mutations and inert complete-value inputs.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Hook_Dispatch_Recovery;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Option_Access;
use WP_AI_Bridge\Support\Native_Option_Inputs;
use WP_AI_Bridge\Support\Native_Option_Operation;
use WP_AI_Bridge\Support\Native_Option_Value;
use WP_AI_Bridge\Support\Operation_Content_Limits;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_Error;

/** One native setter dispatch per positively fenced identity, outside transactions. */
final class Native_Option_Write_Abilities {
	/** @var Permissions */
	private $permissions;
	/** @var Native_Option_Access */
	private $access;
	/** @var Mutation_Log */
	private $log;
	/** @var bool */
	private $in_flight = false;
	/** @var bool Whether this outer call attempted a native setter. */
	private $dispatch_attempted = false;
	/** @var string Current attempt reference, never an option name/value. */
	private $operation_reference = '';
	/** @var string Fixed error phase for unexpected callback failure. */
	private $phase = 'execution';

	/** @param Permissions $permissions Native delegation. @param Mutation_Log|null $log Required redacted audit. */
	public function __construct( Permissions $permissions, ?Mutation_Log $log = null ) {
		$this->permissions = $permissions;
		$this->access      = new Native_Option_Access( $permissions );
		$this->log         = $log ? $log : new Mutation_Log();
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/native-options-write',
			array(
				'label'               => __( 'Manage Native Settings', 'wp-ai-bridge' ),
				'description'         => __( 'Adds, updates or deletes one explicitly delegated native site or network option with a durable one-dispatch fence. Native callbacks, defaults and sanitization apply; outcomes are not transactional or atomic CAS.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'permission_callback' => array( $this, 'can_execute' ),
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

	/** @param mixed $input Closed action. @return bool */
	public function can_execute( $input = array() ) {
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $input ) {
					return ! is_wp_error( $this->parse( $input ) ) &&
						$this->authorized( $input, $this->context( $input['scope'] ) );
				}
			);
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** @param mixed $input Closed action. @return array|WP_Error */
	public function execute( $input ) {
		if ( $this->in_flight ) {
			return $this->error( 'native_option_write_recursive', 'authorization', '', array( 'dispatch_attempted' => false ) );
		}
		$this->dispatch_attempted  = false;
		$this->operation_reference = '';
		$this->phase               = 'authorization';
		$parsed                    = $this->parse( $input );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$this->in_flight = true;
		$inputs          = null;
		$created_id      = '';
		try {
			$result = Hook_Dispatch_Recovery::run(
				function () use ( $parsed, &$inputs, &$created_id ) {
					$context = $this->context( $parsed['scope'] );
					if ( ! $this->authorized( $parsed, $context ) ) {
						return $this->error( 'native_option_write_denied', 'authorization' );
					}
					$capacity = $this->capacity( $parsed );
					if ( is_wp_error( $capacity ) ) {
						return $capacity;
					}
					if ( ! $this->authorized( $parsed, $context ) ) {
						return $this->error( 'native_option_write_context_changed', 'authorization' );
					}
					$journal = new Native_Option_Operation( $this->journal_identity( $context ), $capacity['metadata'] );
					if ( 0 === strpos( $parsed['action'], 'input_' ) ) {
						$this->phase = 'input_preparation';
						$result      = $this->input_action( $parsed, $inputs );
						if ( 'input_begin' === $parsed['action'] && is_array( $result ) && $this->digest( $result['transfer_id'] ?? null ) ) {
							$created_id = $result['transfer_id'];
						}
						if ( is_wp_error( $result ) ) {
							$result = $this->public_error( $result, 'input_preparation' );
						}
					} else {
						$this->phase = 'journal_status';

						if ( 'status' === $parsed['action'] ) {
							$result = $journal->status( $parsed['operation_id'] );
							if ( is_wp_error( $result ) ) {
								$result = $this->public_error( $result, 'journal_status' );
							}
							if ( null === $result ) {
								$result = array(
									'state'         => 'not_recorded',
									'operation_ref' => '',
									'historical'    => true,
									'receipt'       => null,
								);
							}
						} else {
							$result = $this->mutate( $parsed, $context, $capacity, $journal );
						}
					}
					$reference = is_array( $result ) ? ( $result['operation_ref'] ?? '' ) : $this->operation_reference;
					$outcome   = is_array( $result ) ? ( $result['receipt']['outcome'] ?? 'unknown' ) : 'unknown';
					if ( $this->digest( $reference ) ) {
						$this->operation_reference = $reference;
					}
					$this->phase = 'audit';
					try {
						$audited = Hook_Dispatch_Recovery::run(
							function () use ( $parsed, $result ) {
								return $this->audit( $parsed, $result );
							}
						);
					} catch ( \Throwable $error ) {
						$audited = false;
					}
					$this->phase = 'sensitive_output';
					if ( ! $this->authorized( $parsed, $context ) ) {
						return $this->error( 'native_option_write_context_changed', 'sensitive_output', $reference );
					}
					$physical = $journal->check_context();
					if ( is_wp_error( $physical ) || true !== $physical ) {
						return $this->error( 'native_option_write_context_changed', 'sensitive_output', $reference );
					}
					if ( ! $audited ) {
						if ( is_wp_error( $result ) ) {
							$primary                 = $this->public_error( $result );
							$data                    = $primary->get_error_data();
							$data['audit_confirmed'] = false;
							return new WP_Error( $primary->get_error_code(), $primary->get_error_message(), $data );
						}
						return $this->error(
							'native_option_write_reporting_unavailable',
							'audit',
							$reference,
							array(
								'outcome'         => $outcome,
								'audit_confirmed' => false,
							)
						);
					}
					if ( is_wp_error( $result ) ) {
						return $this->public_error( $result );
					}
					$result['processing_limit_bytes']        = $capacity['value'];
					$result['processing_memory_limit_known'] = $capacity['memory'] > 0;
					$result                                  = array_merge(
						array(
							'action'     => $parsed['action'],
							'scope'      => $parsed['scope'],
							'blog_id'    => $context['blog'],
							'network_id' => $context['network'],
						),
						$result
					);
					if ( Bounded_Payload::projected_mcp_bytes( $result ) > $capacity['response'] ) {
						return $this->error( 'native_option_write_response_budget', 'sensitive_output', $reference, array( 'outcome' => $outcome ) );
					}
					return $result;
				}
			);
		} catch ( \Throwable $error ) {
			$code   = 'audit' === $this->phase ? 'native_option_write_reporting_unavailable' : 'native_option_write_callback_failed';
			$result = $this->error( $code, $this->phase, $this->operation_reference );
		} finally {
			$this->in_flight = false;
		}
		if ( is_wp_error( $result ) && '' !== $created_id ) {
			try {
				$cleaned = Hook_Dispatch_Recovery::run(
					function () use ( $inputs, $created_id ) {
						return $inputs->discard_created( $created_id );
					}
				);
			} catch ( \Throwable $error ) {
				$cleaned = false;
			}
			if ( true !== $cleaned ) {
				$result = $this->error( 'native_option_write_input_cleanup_uncertain', 'input_cleanup' );
			}
		}
		return $result;
	}

	/** @param array $input Mutation. @param array $context Original context. @param array $capacity Admitted policy. @param Native_Option_Operation $journal Purpose-owned fence. @return array|WP_Error */
	private function mutate( array $input, array $context, array $capacity, Native_Option_Operation $journal ) {
		$source = $input['value_source'] ?? null;
		$hash   = null;
		$bytes  = 0;
		if ( is_array( $source ) ) {
			$hash  = isset( $source['value_json'] ) ? hash( 'sha256', $source['value_json'] ) : $source['sha256'];
			$bytes = isset( $source['value_json'] ) ? strlen( $source['value_json'] ) : $source['total_bytes'];
		}
		$fingerprint = hash(
			'sha256',
			wp_json_encode( array( 'native_options_write_v1', $this->journal_identity( $context ), $input['action'], $input['name'], $hash, $bytes, $input['expected_observed_sha256'] ?? null ) )
		);
		// Historical recovery must not reload an expired lease or invoke a target getter.
		$this->phase = 'journal_status';
		$prior       = $journal->status( $input['operation_id'], $fingerprint );
		if ( null !== $prior ) {
			if ( is_array( $prior ) ) {
				$this->operation_reference = $prior['operation_ref'];
			}
			return is_wp_error( $prior ) ? $this->public_error( $prior, 'journal_status' ) : $prior;
		}
		$this->phase = 'target_guard';
		$target      = $journal->check_target( $input['scope'], $input['name'] );
		if ( is_wp_error( $target ) ) {
			return $this->public_error( $target, 'target_guard' );
		}
		$target['action'] = $input['action'];
		$value            = null;
		if ( null !== $source ) {
			$this->phase = 'input_decode';
			$decoded     = isset( $source['value_json'] )
				? Native_Option_Value::decode( $source['value_json'], $capacity['value'], $capacity['memory'] )
				: ( new Native_Option_Inputs( $this->permissions, $input['scope'] ) )->value(
					$source['transfer_id'],
					$source['sha256'],
					$source['total_bytes'],
					$capacity['value'],
					$capacity['memory']
				);
			if ( is_wp_error( $decoded ) ) {
				return $this->public_error( $decoded, 'input_decode' );
			}
			if ( $decoded['bytes'] !== $bytes || ! hash_equals( $hash, $decoded['sha256'] ) ) {
				return $this->error( 'native_option_value_invalid', 'input_decode' );
			}
			$value = $decoded['value'];
		}
		if ( ! $this->authorized( $input, $context ) ) {
			return $this->error( 'native_option_write_context_changed', 'pre_dispatch' );
		}
		if ( isset( $input['expected_observed_sha256'] ) ) {
			$this->phase = 'precondition';
			$observed    = $this->observe( $input, $context, $capacity, $journal );
			if ( ! $observed['observed_after_available'] ) {
				$reason = $observed['observation_error'];
				return $this->error( '' !== $reason ? $reason : 'native_option_write_observation_unavailable', 'precondition', '', array( 'outcome' => 'not_dispatched' ) );
			}
			if ( ! hash_equals( $input['expected_observed_sha256'], $observed['observed_after_sha256'] ) ) {
				return $this->error( 'native_option_write_precondition', 'precondition', '', array( 'outcome' => 'not_dispatched' ) );
			}
		}
		if ( ! $this->authorized( $input, $context ) ) {
			return $this->error( 'native_option_write_context_changed', 'pre_dispatch' );
		}
		$this->phase = 'journal_reservation';
		$reserved    = $journal->reserve( $input['operation_id'], $fingerprint, $target );
		if ( is_wp_error( $reserved ) || ! $reserved['dispatch_allowed'] ) {
			return is_wp_error( $reserved ) ? $this->public_error( $reserved, 'journal_reservation' ) : $this->historical( $reserved );
		}
		$claim                     = $reserved['claim'];
		$reference                 = $reserved['operation_ref'];
		$this->operation_reference = $reference;
		$captured                  = $this->captured();
		$gate_error                = null;
		$last_error_present        = null;
		$this->phase               = 'pre_dispatch';
		try {
			$allowed = Hook_Dispatch_Recovery::run(
				function () use ( $input, $context ) {
					return $this->authorized( $input, $context );
				}
			);
			if ( ! $allowed ) {
				$gate_error = $this->error( 'native_option_write_context_changed', 'pre_dispatch', $reference );
			}
		} catch ( \Throwable $error ) {
			$gate_error = $this->error( 'native_option_write_callback_failed', 'pre_dispatch', $reference );
		}
		if ( null === $gate_error ) {
			// Everything inside ready() is hook-free. The following switch calls
			// exactly one Core setter; no authorization/getter/audit runs between.
			try {
				$ready = $journal->ready( $claim );
				if ( is_wp_error( $ready ) ) {
					$gate_error = $ready;
				} elseif ( true !== $ready ) {
					$gate_error = $this->error( 'native_option_write_journal_unavailable', 'pre_dispatch', $reference );
				} else {
					$this->phase         = 'native_dispatch';
					$captured['outcome'] = 'unknown';
					try {
						$captured['native_return'] = Hook_Dispatch_Recovery::run(
							function () use ( $input, $context, $value ) {
								$this->dispatch_attempted = true;
								switch ( $input['action'] ) {
									case 'add':
										return 'network' === $input['scope']
											? add_network_option( $context['network'], $input['name'], $value )
											: add_option( $input['name'], $value );
									case 'update':
										return 'network' === $input['scope']
											? update_network_option( $context['network'], $input['name'], $value )
											: update_option( $input['name'], $value );
									default:
										return 'network' === $input['scope']
											? delete_network_option( $context['network'], $input['name'] )
											: delete_option( $input['name'] );
								}
							}
						);
						if ( is_bool( $captured['native_return'] ) ) {
							$captured['outcome'] = 'native_call_returned';
						} else {
							$captured['native_return'] = null;
						}
					} catch ( \Throwable $error ) {
						$captured['callback_threw'] = true;
					}
				}
			} catch ( \Throwable $error ) {
				$gate_error = $this->error( 'native_option_write_journal_unavailable', 'pre_dispatch', $reference );
			}
		}
		if ( 'not_dispatched' !== $captured['outcome'] ) {
			$last_error_present = is_string( $context['wpdb']->last_error ?? null ) && '' !== $context['wpdb']->last_error;
		}

		if ( null !== $gate_error ) {
			$captured['observation_error'] = 'native_option_pre_dispatch_denied';
		} else {
			$captured = array_merge( $captured, $this->observe( $input, $context, $capacity, $journal ) );
		}
		// Journal completion owns its short clean-session proof. It must not
		// commit a transaction left by a provider or retry the native setter.
		$this->phase = 'journal_completion';
		$result      = $journal->complete( $claim, $captured );
		if ( is_wp_error( $result ) ) {
			return $this->public_error( $result, 'journal_completion', $reference );
		}
		if ( null !== $gate_error ) {
			return $this->public_error( $gate_error, 'pre_dispatch', $reference );
		}
		$result['diagnostic_phase']             = 'after_native_call';
		$result['native_diagnostics_available'] = false;
		$result['last_error_present']           = $last_error_present;
		return $result;
	}

	/** @return array Fixed journal completion fields; no option value. */
	private function captured() {
		return array(
			'outcome'                  => 'not_dispatched',
			'native_return'            => null,
			'callback_threw'           => false,
			'observed_after_sha256'    => null,
			'observed_after_available' => false,
			'observation_error'        => '',
		);
	}

	/** @param array $input Target. @param array $context Original context. @param array $capacity Capacity. @return array Point-in-time native observation only. */
	private function observe( array $input, array $context, array $capacity, Native_Option_Operation $journal ) {
		$result = array(
			'observed_after_sha256'    => null,
			'observed_after_available' => false,
			'observation_error'        => '',
		);
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $input, $context, $capacity, $result, $journal ) {
					if ( ! $this->read_authorized( $input['scope'], $context ) ) {
						$result['observation_error'] = 'native_option_observation_denied';
						return $result;
					}
					$physical = $journal->check_context();
					if ( is_wp_error( $physical ) || true !== $physical ) {
						$result['observation_error'] = is_wp_error( $physical ) ? $physical->get_error_code() : 'native_options_write_context_changed';
						return $result;
					}
					$value = 'network' === $input['scope']
						? get_network_option( $context['network'], $input['name'] )
						: get_option( $input['name'] );
					if ( ! $this->read_authorized( $input['scope'], $context ) ) {
						$result['observation_error'] = 'native_option_observation_denied';
						return $result;
					}
					$physical = $journal->check_context();
					if ( is_wp_error( $physical ) || true !== $physical ) {
						$result['observation_error'] = is_wp_error( $physical ) ? $physical->get_error_code() : 'native_options_write_context_changed';
						return $result;
					}
					$memory = Operation_Content_Limits::effective_memory_bytes();
					$limit  = $capacity['value'];
					if ( $memory > 0 ) {
						$limit = min( $limit, intdiv( max( 0, $memory - memory_get_usage( true ) ), 8 ) );
					}
					$encoded = Native_Option_Value::encode( $value, $limit );
					if ( is_wp_error( $encoded ) ) {
						$result['observation_error'] = $encoded->get_error_code();
					} else {
						$result['observed_after_sha256']    = $encoded['sha256'];
						$result['observed_after_available'] = true;
					}
					return $result;
				}
			);
		} catch ( \Throwable $error ) {
			$result['observation_error'] = 'native_option_observation_callback_failed';
			return $result;
		}
	}

	/** @param array $input Inert input operation. @return array|WP_Error */
	private function input_action( array $input, &$inputs ) {
		$inputs = new Native_Option_Inputs( $this->permissions, $input['scope'] );
		switch ( $input['action'] ) {
			case 'input_begin':
				return $inputs->begin( $input['total_bytes'], $input['sha256'] );
			case 'input_append':
				return $inputs->append( $input['transfer_id'], $input['offset'], $input['base64'] );
			case 'input_verify':
				return $inputs->verify( $input['transfer_id'] );
			case 'input_status':
				return $inputs->status( $input['transfer_id'] );
			default:
				return $inputs->release( $input['transfer_id'] );
		}
	}

	/** @param array $input Closed request. @return array|WP_Error */
	private function capacity( array $input ) {
		$policy         = ( new Transfer_Settings() )->bytes();
		$response       = Bounded_Payload::response_limit();
		$request_budget = $policy['request'] - Bounded_Payload::MCP_ENVELOPE_HEADROOM_BYTES;
		$memory         = Operation_Content_Limits::effective_memory_bytes();
		$raw_bytes      = 0;
		foreach ( $input as $part ) {
			$parts = is_array( $part ) ? $part : array( $part );
			foreach ( $parts as $scalar ) {
				if ( is_string( $scalar ) ) {
					$length = strlen( $scalar );
					if ( $length > $request_budget - $raw_bytes ) {
						return $this->error( 'native_option_write_request_budget', 'input_validation' );
					}
					$raw_bytes += $length;
				}
			}
		}
		if ( $memory > 0 && $raw_bytes > intdiv( max( 0, $memory - memory_get_usage( true ) ), 8 ) ) {
			return $this->error( 'native_option_value_budget', 'input_validation' );
		}
		$json = wp_json_encode( $input );
		if ( ! is_string( $json ) || strlen( $json ) > $policy['request'] - Bounded_Payload::MCP_ENVELOPE_HEADROOM_BYTES ) {
			return $this->error( 'native_option_write_request_budget', 'input_validation' );
		}
		$memory   = Operation_Content_Limits::effective_memory_bytes();
		$maximum  = $policy['file'];
		$metadata = max( $policy['request'], $response );
		$ceiling  = 0;
		if ( $memory > 0 ) {
			$free     = max( 0, $memory - memory_get_usage( true ) );
			$maximum  = min( $maximum, intdiv( $free, 8 ) );
			$metadata = min( $metadata, intdiv( $free, 8 ) );
			$ceiling  = $memory - intdiv( $free, 2 );
		}
		if ( $maximum < 1 ) {
			return $this->error( 'native_option_value_budget', 'input_validation' );
		}
		return array(
			'value'    => $maximum,
			'metadata' => $metadata,
			'memory'   => $ceiling,
			'response' => $response,
		);
	}

	/** @param array $input Valid action. @param array $context Original context. @return bool */
	private function authorized( array $input, array $context ) {
		if ( ! $this->same_context( $context ) ) {
			return false;
		}
		$allowed = 'status' === $input['action'] ? $this->access->allowed( $input['scope'] ) : $this->access->allowed_write( $input['scope'] );
		if ( $allowed && 'delete' === $input['action'] ) {
			$allowed = $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'manage_options' );
		}
		return $allowed && $this->same_context( $context );
	}

	/** @param string $scope Current scope. @param array $context Original context. @return bool */
	private function read_authorized( $scope, array $context ) {
		return $this->same_context( $context ) && $this->access->allowed( $scope ) && $this->same_context( $context );
	}

	/** @param string $scope Scope. @return array */
	private function context( $scope ) {
		global $wpdb;
		return array(
			'scope'           => $scope,
			'blog'            => get_current_blog_id(),
			'network'         => is_multisite() ? get_current_network_id() : 0,
			'user'            => get_current_user_id(),
			'client'          => OAuth_Server::authenticated_mcp_client_id(),
			'runtime_network' => function_exists( 'get_current_network_id' ) ? get_current_network_id() : 0,
			'wpdb'            => $wpdb,
			'options'         => $wpdb->options ?? '',
			'sitemeta'        => $wpdb->sitemeta ?? '',
			'handle'          => $wpdb->dbh ?? null,
			'database'        => $wpdb->dbname ?? '',
			'prefix'          => $wpdb->prefix ?? '',
			'base_prefix'     => $wpdb->base_prefix ?? '',
		);
	}

	/** @param array $context Original context. @return bool */
	private function same_context( array $context ) {
		return $context === $this->context( $context['scope'] );
	}

	/** @param array $context Original context. @return array */
	private function journal_identity( array $context ) {
		return array_intersect_key( $context, array_fill_keys( array( 'scope', 'blog', 'network', 'user', 'client' ), true ) );
	}

	/** @param array $result Internal reservation. @return array */
	private function historical( array $result ) {
		return array_intersect_key( $result, array_fill_keys( array( 'state', 'operation_ref', 'historical', 'receipt' ), true ) );
	}

	/** @param array $input Valid request. @param mixed $result Outcome. @return bool */
	private function audit( array $input, $result ) {
		$name = isset( $input['name'] ) ? substr( hash( 'sha256', $input['name'] ), 0, 16 ) : '';
		return $this->log->record(
			'wp-ai-bridge/native-options-write',
			'native_' . $input['scope'] . '_' . $input['action'] . '_' . $name,
			0,
			! is_wp_error( $result ),
			is_wp_error( $result ) ? $this->public_error( $result )->get_error_code() : ''
		);
	}

	/** @param mixed $input Closed native action. @return array|WP_Error */
	private function parse( $input ) {
		$fields = array(
			'add'           => array( 'name', 'operation_id', 'value_source', 'expected_observed_sha256' ),
			'update'        => array( 'name', 'operation_id', 'value_source', 'expected_observed_sha256' ),
			'delete'        => array( 'name', 'operation_id', 'confirm', 'expected_observed_sha256' ),
			'status'        => array( 'operation_id' ),
			'input_begin'   => array( 'total_bytes', 'sha256' ),
			'input_append'  => array( 'transfer_id', 'offset', 'base64' ),
			'input_verify'  => array( 'transfer_id' ),
			'input_status'  => array( 'transfer_id' ),
			'input_release' => array( 'transfer_id' ),
		);
		if ( ! is_array( $input ) || ! is_string( $input['action'] ?? null ) || ! isset( $fields[ $input['action'] ] ) ||
			! in_array( $input['scope'] ?? '', array( 'site', 'network' ), true ) ||
			array_diff( array_keys( $input ), array_merge( array( 'action', 'scope' ), $fields[ $input['action'] ] ) ) ||
			in_array( null, $input, true ) ) {
			return $this->error( 'native_option_write_invalid', 'input_validation' );
		}
		$action = $input['action'];
		if ( 0 !== strpos( $action, 'input_' ) &&
			( ! is_string( $input['operation_id'] ?? null ) || 1 !== preg_match( '/^[A-Za-z0-9_.:-]{8,128}$/D', $input['operation_id'] ) ) ) {
			return $this->error( 'native_option_write_invalid', 'input_validation' );
		}
		if ( in_array( $action, array( 'add', 'update', 'delete' ), true ) &&
			( ! is_string( $input['name'] ?? null ) || '' === $input['name'] || trim( $input['name'] ) !== $input['name'] ||
			false !== strpos( $input['name'], "\0" ) || 1 !== preg_match( '//u', $input['name'] ) ||
			( isset( $input['expected_observed_sha256'] ) && ! $this->digest( $input['expected_observed_sha256'] ) ) ||
			( 'delete' === $action && true !== ( $input['confirm'] ?? null ) ) ) ) {
			return $this->error( 'native_option_write_invalid', 'input_validation' );
		}
		if ( in_array( $action, array( 'add', 'update' ), true ) ) {
			$source = $input['value_source'] ?? null;
			if ( ! is_array( $source ) ||
				! ( ( count( $source ) === 1 && is_string( $source['value_json'] ?? null ) && '' !== $source['value_json'] ) ||
				( count( $source ) === 3 && ! array_diff( array_keys( $source ), array( 'transfer_id', 'sha256', 'total_bytes' ) ) &&
				$this->digest( $source['transfer_id'] ?? null ) && $this->digest( $source['sha256'] ?? null ) &&
				is_int( $source['total_bytes'] ?? null ) && $source['total_bytes'] >= 0 ) ) ) {
				return $this->error( 'native_option_write_invalid', 'input_validation' );
			}
		}
		if ( 'input_begin' === $action &&
			( ! is_int( $input['total_bytes'] ?? null ) || $input['total_bytes'] < 0 || ! $this->digest( $input['sha256'] ?? null ) ) ) {
			return $this->error( 'native_option_write_invalid', 'input_validation' );
		}
		if ( in_array( $action, array( 'input_append', 'input_verify', 'input_status', 'input_release' ), true ) &&
			! $this->digest( $input['transfer_id'] ?? null ) ) {
			return $this->error( 'native_option_write_invalid', 'input_validation' );
		}
		if ( 'input_append' === $action &&
			( ! is_int( $input['offset'] ?? null ) || $input['offset'] < 0 || ! is_string( $input['base64'] ?? null ) || '' === $input['base64'] ) ) {
			return $this->error( 'native_option_write_invalid', 'input_validation' );
		}
		return $input;
	}

	/** @param mixed $value Lowercase SHA-256 or opaque ID. @return bool */
	private function digest( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	/** @param WP_Error $error Source-owned error. @param string $phase Fixed phase. @param string $reference Operation ref. @return WP_Error */
	private function public_error( WP_Error $error, $phase = 'execution', $reference = '' ) {
		$code = $error->get_error_code();
		if ( ! is_string( $code ) || strlen( $code ) > 80 || ! preg_match( '/^(native_option[s]?_|transfer_|database_write_)[a-z0-9_]+$/D', $code ) ) {
			$code = 'native_option_write_unavailable';
		}
		$safe = array();
		$data = $error->get_error_data();
		if ( is_array( $data ) ) {
			if ( $this->digest( $data['operation_ref'] ?? null ) ) {
				$reference = $data['operation_ref'];
			}
			foreach ( array( 'native_errno', 'processing_limit_bytes', 'max_allowed_packet', 'required_bytes', 'available_bytes' ) as $field ) {
				if ( is_int( $data[ $field ] ?? null ) && $data[ $field ] >= 0 ) {
					$safe[ $field ] = $data[ $field ];
				}
			}
			if ( is_string( $data['native_sqlstate'] ?? null ) && preg_match( '/^[A-Z0-9]{5}$/D', $data['native_sqlstate'] ) ) {
				$safe['native_sqlstate'] = $data['native_sqlstate'];
			}
			if ( in_array( $data['storage_reason'] ?? '', array( 'root_web_served', 'root_symlink', 'root_noncanonical', 'root_mode', 'lease_file_mode' ), true ) ) {
				$safe['storage_reason'] = $data['storage_reason'];
			}
			if ( in_array( $data['owning_contract'] ?? '', array( 'core', 'site-settings-update', 'disallowed_keys', 'comment_previously_approved' ), true ) ) {
				$safe['owning_contract'] = $data['owning_contract'];
			}
			if ( in_array( $data['phase'] ?? '', array( 'authorization', 'input_validation', 'input_preparation', 'input_cleanup', 'input_decode', 'precondition', 'pre_dispatch', 'native_dispatch', 'target_guard', 'journal_status', 'journal_reservation', 'journal_completion', 'audit', 'sensitive_output' ), true ) ) {
				$phase = $data['phase'];
			}
			if ( in_array( $data['outcome'] ?? '', array( 'unknown', 'not_dispatched', 'native_call_returned' ), true ) ) {
				$safe['outcome'] = $data['outcome'];
			}
		}
		return $this->error( $code, $phase, $reference, $safe );
	}

	/** @param string $code Fixed classification. @param string $phase Fixed phase. @param string $reference Public operation hash. @param array $data Sanitized metadata. @return WP_Error */
	private function error( $code, $phase = 'execution', $reference = '', array $data = array() ) {
		if ( false !== strpos( $code, 'denied' ) || false !== strpos( $code, 'context' ) ) {
			$message = __( 'Current sensitive read and write grants, Site Configuration and unchanged native administrator context are required. Network writes also need separate current-network consent; delete needs Users & Destructive.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'precondition' ) ) {
			$message = __( 'The current native observed value does not match the requested precondition. Read it again and review a new intention; this check is not atomic CAS.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'unsupported_session' ) || false !== strpos( $code, 'unsupported_storage' ) ) {
			$message = __( 'The current native connection or receipt storage cannot establish a safe callback dispatch fence. Preserve any caller transaction and inspect the original operation status or use the owning WordPress administration workflow.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'deprecated_name' ) ) {
			$message = __( 'Use the canonical Core setting identified by the owning contract. Deprecated option aliases are not accepted by this exact-name mutation contract.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'target_changed' ) ) {
			$message = __( 'The native target metadata changed after preflight. Inspect the canonical target again and retain the original operation identity for any admitted attempt.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'invalid' ) || false !== strpos( $code, 'unrepresentable' ) ) {
			$message = __( 'Use the exact closed action fields, canonical complete native value document and valid operation or transfer identity. Names cannot be trimmed or contain NUL bytes.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'unsupported' ) || false !== strpos( $code, 'protected' ) || false !== strpos( $code, 'alias' ) || false !== strpos( $code, 'control' ) || false !== strpos( $code, 'specialized' ) ) {
			$message = __( 'This target or representation requires its canonical owning native API. Bridge control state and specialized site settings cannot be bypassed; use the owner workflow or a supported lossless value.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'budget' ) || false !== strpos( $code, 'capacity' ) || false !== strpos( $code, 'size' ) || false !== strpos( $code, 'disk' ) ) {
			$message = __( 'The complete request, value or result exceeds current host or administrator capacity. Check request/file/reservation/PHP limits, use staged input or smaller windows, and inspect historical status before resending a mutation.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'expired' ) || false !== strpos( $code, 'cancelled' ) ) {
			$message = __( 'This private input is expired or released. Recover an existing operation by its original ID before preparing a new input; history does not require surviving staged bytes.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'cleanup_uncertain' ) ) {
			$message = __( 'Cleanup of this newly created private input could not be verified. Inspect Bridge private storage and active reservations before preparing another input; native mutation journals and actual effects were not undone.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'reporting' ) || false !== strpos( $code, 'audit' ) ) {
			$message = __( 'The required Activity record could not be confirmed. A native effect may already have occurred; inspect the original operation status and restore reporting before proceeding.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'conflict' ) ) {
			$message = __( 'This operation or private input identity is already bound to different content. Inspect its original status; never change an operation ID automatically to bypass an uncertain outcome.', 'wp-ai-bridge' );
		} elseif ( false !== strpos( $code, 'recursive' ) ) {
			$message = __( 'Nested native option mutations are denied. Adjust the installed provider callback to avoid reentering the same mutation Ability.', 'wp-ai-bridge' );
		} else {
			$message = __( 'Native callback or journal completion could not be verified. Inspect trusted provider diagnostics and the original operation status; do not automatically replay or replace the operation ID.', 'wp-ai-bridge' );
		}
		$data['phase']              = $phase;
		$data['dispatch_attempted'] = is_bool( $data['dispatch_attempted'] ?? null ) ? $data['dispatch_attempted'] : $this->dispatch_attempted;
		if ( $this->digest( $reference ) ) {
			$data['operation_ref'] = $reference;
		}
		return new WP_Error( $code, $message, $data );
	}

	/** @return array Root union and independently closed action schemas. */
	private function input_schema() {
		$string     = array( 'type' => 'string' );
		$id         = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		$int        = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		$source     = array(
			'type'  => 'object',
			'oneOf' => array(
				array(
					'type'                 => 'object',
					'properties'           => array(
						'value_json' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
					),
					'required'             => array( 'value_json' ),
					'additionalProperties' => false,
				),
				array(
					'type'                 => 'object',
					'properties'           => array(
						'transfer_id' => $id,
						'sha256'      => $id,
						'total_bytes' => $int,
					),
					'required'             => array( 'transfer_id', 'sha256', 'total_bytes' ),
					'additionalProperties' => false,
				),
			),
		);
		$properties = array(
			'action'                   => array(
				'type' => 'string',
				'enum' => array( 'add', 'update', 'delete', 'status', 'input_begin', 'input_append', 'input_verify', 'input_status', 'input_release' ),
			),
			'scope'                    => array(
				'type' => 'string',
				'enum' => array( 'site', 'network' ),
			),
			'name'                     => array(
				'type'      => 'string',
				'minLength' => 1,
			),
			'operation_id'             => array(
				'type'    => 'string',
				'pattern' => '^[A-Za-z0-9_.:-]{8,128}$',
			),
			'expected_observed_sha256' => $id,
			'value_source'             => $source,
			'confirm'                  => array(
				'type' => 'boolean',
				'enum' => array( true ),
			),
			'transfer_id'              => $id,
			'sha256'                   => $id,
			'total_bytes'              => $int,
			'offset'                   => $int,
			'base64'                   => array(
				'type'      => 'string',
				'minLength' => 1,
			),
		);
		$fields     = array(
			'add'           => array( 'name', 'operation_id', 'value_source' ),
			'update'        => array( 'name', 'operation_id', 'value_source' ),
			'delete'        => array( 'name', 'operation_id', 'confirm' ),
			'status'        => array( 'operation_id' ),
			'input_begin'   => array( 'total_bytes', 'sha256' ),
			'input_append'  => array( 'transfer_id', 'offset', 'base64' ),
			'input_verify'  => array( 'transfer_id' ),
			'input_status'  => array( 'transfer_id' ),
			'input_release' => array( 'transfer_id' ),
		);
		$branches   = array();
		foreach ( $fields as $action => $required ) {
			$required                 = array_merge( array( 'action', 'scope' ), $required );
			$allowed                  = in_array( $action, array( 'add', 'update', 'delete' ), true ) ? array_merge( $required, array( 'expected_observed_sha256' ) ) : $required;
			$branch                   = array_intersect_key( $properties, array_fill_keys( $allowed, true ) );
			$branch['action']['enum'] = array( $action );
			$branches[]               = array(
				'type'                 => 'object',
				'properties'           => $branch,
				'required'             => $required,
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'action', 'scope' ),
			'additionalProperties' => false,
			'oneOf'                => $branches,
		);
	}

	/** @return array */
	private function output_schema() {
		$string  = array( 'type' => 'string' );
		$int     = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		$bool    = array( 'type' => 'boolean' );
		$receipt = array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'format'                   => $string,
				'purpose'                  => $string,
				'scope'                    => $string,
				'blog'                     => $int,
				'network'                  => $int,
				'user'                     => $int,
				'client_fingerprint'       => $string,
				'action'                   => $string,
				'name'                     => $string,
				'fingerprint'              => $string,
				'created_at'               => $string,
				'state'                    => $string,
				'outcome'                  => $string,
				'native_return'            => array( 'type' => array( 'boolean', 'null' ) ),
				'callback_threw'           => $bool,
				'observed_after_sha256'    => array( 'type' => array( 'string', 'null' ) ),
				'observed_after_available' => $bool,
				'observation_error'        => $string,
				'native_errno'             => $int,
				'native_sqlstate'          => $string,
			),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'                        => $string,
				'scope'                         => $string,
				'blog_id'                       => $int,
				'network_id'                    => $int,
				'state'                         => $string,
				'operation_ref'                 => $string,
				'historical'                    => $bool,
				'receipt'                       => $receipt,
				'transfer_id'                   => $string,
				'total_bytes'                   => $int,
				'sha256'                        => $string,
				'received_bytes'                => $int,
				'chunk_bytes'                   => $int,
				'expires_at'                    => $int,
				'released'                      => $bool,
				'processing_limit_bytes'        => $int,
				'processing_memory_limit_known' => $bool,
				'diagnostic_phase'              => $string,
				'native_diagnostics_available'  => $bool,
				'last_error_present'            => array( 'type' => array( 'boolean', 'null' ) ),
			),
			'required'             => array( 'action', 'scope', 'blog_id', 'network_id' ),
			'additionalProperties' => false,
		);
	}
}
