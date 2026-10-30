<?php
/**
 * Shared authorization and public dispatch for full approved backup/restore.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support;

use WP_Error;

/** Closed actions; every continuation rechecks current native delegated authority. */
final class Backup_Operation {
	private $permissions;
	private $access;

	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
		$this->access      = new Administration_Access( $permissions );
	}

	public function allowed( array $input ) {
		$action  = $input['action'] ?? '';
		$restore = 0 === strpos( $action, 'restore_' ) || 0 === strpos( $action, 'input_' ) ||
			'cancel' === $action && in_array( $input['purpose'] ?? '', array( 'backup_state', 'backup_input' ), true );
		if ( 'read' === $action && 'backup_input' === ( $input['purpose'] ?? '' ) ) {
			$restore = true;
		}
		$requirements = array( array( Settings::GROUP_DATABASE_INSPECTION, 'manage_options' ), array( Settings::GROUP_DATABASE_READ, 'manage_options' ) );
		if ( $restore ) {
			$requirements[] = array( Settings::GROUP_DATABASE_WRITE, 'manage_options' );
		}
		return $this->access->allowed( $restore ? Settings::GROUP_BACKUP_RESTORE : Settings::GROUP_BACKUP_EXPORT, is_multisite(), $requirements );
	}

	public function execute( array $input ) {
		try {
			if ( ! $this->allowed( $input ) ) {
				return new WP_Error( 'backup_denied', __( 'Current full backup or full restore delegation, sensitive database consent and actual administrator/network authority are required.', 'wp-ai-bridge' ) );
			}
			$this->validate( $input );
			$policy = $this->policy();
			$action = $input['action'];
			$budget = $policy['processing'];
			if ( $budget < 1 && ( in_array( $action, array( 'inspect', 'export_begin', 'export_step' ), true ) ||
				0 === strpos( $action, 'restore_' ) && 'restore_status' !== $action ) ) {
				throw new \RuntimeException( 'backup_processing_capacity' );
			}
			if ( in_array( $action, array( 'inspect', 'export_begin', 'export_step' ), true ) ) {
				$exporter = new Backup_Exporter( $this->permissions, $policy, $budget );
				if ( 'inspect' === $action ) {
					$plan = $exporter->plan( $input['source_roots'] );
					return array(
						'format'                          => Backup_Archive::FORMAT,
						'state'                           => 'observed_plan',
						'plan_hash'                       => $plan['plan_hash'],
						'tables'                          => $plan['tables'],
						'roots'                           => $plan['roots'],
						'complete'                        => false,
						'quiescence_assumption'           => true,
						'consistency'                     => 'declared_quiescent_verified_capture',
						'activation_pending'              => true,
						'unsupported_objects_preflighted' => true,
						'max_frame_bytes'                 => min( $policy['chunk'], $policy['step_bytes'] ),
					);
				}
				return 'export_begin' === $action ? $exporter->begin( $input ) : $exporter->step( $input['session_id'] );
			}
			if ( 0 === strpos( $action, 'restore_' ) && 'restore_status' !== $action ) {
				$restore = new Backup_Restore( $this->permissions, $policy, $budget );
				if ( 'restore_prepare' === $action ) {
					return $restore->prepare( $input );
				}
				return 'restore_begin' === $action ? $restore->begin( $input ) : $restore->step( $input['session_id'] );
			}
			if ( in_array( $action, array( 'export_status', 'restore_status' ), true ) ) {
				$purpose    = 'export_status' === $action ? 'backup_archive' : 'backup_state';
				$store      = $this->store( $purpose );
				$checkpoint = self::checked( $store->read_checkpoint( $input['session_id'] ) );
				$state      = $checkpoint['state'];
				return array(
					'format'                => Backup_Archive::FORMAT,
					'session_id'            => $input['session_id'],
					'state'                 => $state['status'],
					'phase'                 => $state['phase'],
					'complete'              => in_array( $state['status'], array( 'sealed', 'verified' ), true ),
					'plan_hash'             => $state['plan_hash'] ?? $state['plan']['plan_hash'] ?? null,
					'archive_id'            => 'backup_archive' === $purpose ? $input['session_id'] : $state['archive_id'],
					'bytes'                 => $state['offset'],
					'sha256'                => $state['chain'],
					'integrity'             => Backup_Archive::CHAIN_FORMAT,
					'consistency'           => 'declared_quiescent_verified_capture',
					'quiescence_assumption' => true,
					'activation_pending'    => true,
				);
			}
			if ( 'read' === $action ) {
				$max = min( $policy['chunk'], max( 1, intdiv( Bounded_Payload::response_limit() - 4096, 4 ) ) );
				if ( $input['max_bytes'] > $max ) {
					throw new \RuntimeException( 'backup_response_capacity' );
				}
				return self::checked( $this->store( $input['purpose'] )->read_administration( $input['session_id'], $input['offset'], $input['max_bytes'] ) );
			}
			if ( 'cancel' === $action ) {
				$checkpoint = self::checked( $this->store( $input['purpose'] )->read_checkpoint( $input['session_id'] ) );
				$state      = $checkpoint['state'];
				if ( 'restore' === ( $state['kind'] ?? '' ) && ! in_array( $state['status'], array( 'preflighting', 'ready' ), true ) ) {
					throw new \RuntimeException( 'backup_restore_cleanup_requires_owner' );
				}
				self::checked( $this->store( $input['purpose'] )->cancel( $input['session_id'] ) );
				return array(
					'state'              => 'cancelled',
					'complete'           => false,
					'activation_pending' => true,
				);
			}
			$store = $this->store( 'backup_input' );
			if ( 'input_open' === $action ) {
				$result = self::checked( $store->begin_administration_stream( $input['max_total_bytes'], $policy ) );
			} elseif ( 'input_append' === $action ) {
				$raw = base64_decode( $input['content_base64'], true );
				if ( ! is_string( $raw ) || base64_encode( $raw ) !== $input['content_base64'] || strlen( $raw ) > min( $policy['chunk'], $policy['step_bytes'] ) ||
					! hash_equals( hash( 'sha256', $raw ), $input['sha256'] ) ) {
					throw new \RuntimeException( 'backup_input_frame_invalid' );
				}
				// A transport append must contain exactly one complete frame so
				// the sealed transport chain equals the portable archive chain.
				$stream = fopen( 'php://memory', 'w+b' );
				try {
					fwrite( $stream, $raw );
					rewind( $stream );
					$frame = Backup_Archive::read( $stream, min( $policy['chunk'], $policy['step_bytes'] ) );
					if ( null === $frame || ftell( $stream ) !== strlen( $raw ) ) {
						throw new \RuntimeException( 'backup_input_frame_invalid' );
					}
				} finally {
					fclose( $stream );
				}
				$result = self::checked( $store->append_verified_frame( $input['session_id'], $input['offset'], $raw, $input['sha256'] ) );
			} elseif ( 'input_seal' === $action ) {
				$result = self::checked( $store->seal_administration_stream( $input['session_id'], $input['offset'], $input['sha256'] ) );
			} else {
				$result = self::checked( $store->status( $input['session_id'] ) );
			}
			return array(
				'format'             => Backup_Archive::FORMAT,
				'session_id'         => $result['id'],
				'state'              => $result['state'],
				'bytes'              => $result['received'] ?? $result['received_bytes'] ?? 0,
				'sha256'             => $result['stream_chain'] ?? $result['sha256'],
				'integrity'          => $result['integrity'] ?? Backup_Archive::CHAIN_FORMAT,
				'complete'           => false,
				'activation_pending' => true,
			);
		} catch ( \Throwable $error ) {
			if ( 'export_step' === ( $input['action'] ?? '' ) && Backup_Archive::digest( $input['session_id'] ?? null ) ) {
				try {
					$store      = $this->store( 'backup_archive' );
					$checkpoint = $store->read_checkpoint( $input['session_id'] );
					if ( ! is_wp_error( $checkpoint ) && 'export' === ( $checkpoint['state']['kind'] ?? '' ) ) {
						$failed                     = $checkpoint['state'];
						$failed['status']           = 'incomplete';
						$failed['phase']            = 'restart_required';
						$failed['failure_observed'] = true;
						$store->checkpoint( $input['session_id'], $failed, $checkpoint['revision'] );
					}
				} catch ( \Throwable $ignored ) {
					// Preserve the functional error even when private diagnostics
					// cannot be checkpointed; never erase Activity evidence.
				}
			}
			$reason = $error instanceof Database_Write_Failure ? $error->reason : $error->getMessage();
			$parts  = explode( ':', $reason, 2 );
			$code   = 1 === preg_match( '/^(?:backup|transfer|filesystem|database|administration)_[a-z0-9_]+$/D', $parts[0] ) ? $parts[0] : 'backup_failed';
			$data   = array(
				'complete'              => false,
				'activation_pending'    => true,
				'quiescence_assumption' => true,
			);
			if ( in_array( $code, array( 'backup_metadata_capacity', 'backup_processing_capacity' ), true ) ) {
				$data['capacity_domain']    = 'internal_complete_schema_processing';
				$data['required_resources'] = 'administration_step_bytes_and_current_php_memory_headroom';
				$data['processing_bytes']   = $policy['processing'] ?? null;
				$data['memory_known']       = $policy['memory_known'] ?? null;
				$data['memory_headroom']    = $policy['memory_headroom'] ?? null;
			} elseif ( 'backup_plan_checkpoint_capacity' === $code ) {
				$data['capacity_domain']    = 'private_checkpoint_plan_metadata';
				$data['required_resources'] = 'private_transfer_checkpoint_metadata_reservation';
			}
			if ( in_array( $code, array( 'backup_cell_verification_resources', 'backup_cell_verification_unavailable' ), true ) ) {
				$data['verification_work']  = 'one_complete_cell_native_sha256_replay';
				$data['required_resources'] = 'seekable_retained_archive_and_sufficient_step_seconds_and_php_execution_time';
				$data['recovery']           = 'restart_preflight_with_the_retained_immutable_archive_after_adjusting_resources';
			}
			if ( isset( $parts[1] ) && ( 0 === strpos( $parts[0], 'backup_schema_' ) || in_array( $parts[0], array( 'backup_unsupported_view', 'backup_unsupported_trigger', 'backup_unsupported_routines', 'backup_unsupported_events', 'backup_external_dependency' ), true ) ) ) {
				$data['object_base64'] = $parts[1];
			}
			return new WP_Error( $code, __( 'Backup or isolated restore did not complete. Preserve its session and operation receipts. Check the explicit quiescence prerequisite, supported native objects, configured source/destination scope and current transfer/processing capacity; an uncertain native effect must be reconciled before disposal or restart.', 'wp-ai-bridge' ), $data );
		}
	}

	private function policy() {
		$policy                    = ( new Transfer_Settings() )->bytes();
		$config                    = $this->access->configuration();
		$memory                    = Operation_Content_Limits::effective_memory_bytes();
		$used                      = memory_get_usage( true );
		$transport                 = max( $policy['request'], Bounded_Payload::response_limit() );
		$policy['processing']      = max( $config['step_bytes'], $transport );
		$policy['memory_known']    = $memory > 0;
		$policy['memory_headroom'] = $memory > 0 ? max( 0, $memory - $used ) : null;
		if ( $memory > 0 ) {
			// Complete native definitions/maps have multiple live representations.
			// This is independent of a JSON message or archive frame quantum.
			$policy['processing'] = min( $policy['processing'], intdiv( $policy['memory_headroom'], 8 ) );
		}
		// Unknown/unlimited ceilings do not discard the operator's already
		// finite processing quantum or replace it with a message-sized cap.
		$policy['step_bytes']   = min( $config['step_bytes'], $policy['chunk'] * max( 1, intdiv( $config['step_bytes'], max( 1, $policy['chunk'] ) ) ) );
		$policy['step_seconds'] = $config['step_seconds'];
		if ( $memory > 0 ) {
			$policy['chunk'] = min( $policy['chunk'], max( 1, intdiv( $memory, 16 ) ) );
		}
		if ( min( $policy['step_bytes'], $policy['chunk'] ) < 16384 ) {
			throw new \RuntimeException( 'backup_frame_capacity' );
		}
		return $policy;
	}

	private function validate( array $input ) {
		$fields = array(
			'inspect'         => array( 'action', 'source_roots' ),
			'export_begin'    => array( 'action', 'source_roots', 'expected_plan_hash', 'quiescence_confirmed', 'bootstrap_writers_confirmed' ),
			'export_step'     => array( 'action', 'session_id' ),
			'export_status'   => array( 'action', 'session_id' ),
			'read'            => array( 'action', 'purpose', 'session_id', 'offset', 'max_bytes' ),
			'input_open'      => array( 'action', 'max_total_bytes' ),
			'input_append'    => array( 'action', 'session_id', 'offset', 'content_base64', 'sha256' ),
			'input_seal'      => array( 'action', 'session_id', 'offset', 'sha256' ),
			'input_status'    => array( 'action', 'session_id' ),
			'restore_prepare' => array( 'action', 'archive_id', 'archive_sha256', 'archive_purpose', 'target_schema', 'target_root' ),
			'restore_begin'   => array( 'action', 'session_id', 'expected_plan_hash', 'quiescence_confirmed' ),
			'restore_step'    => array( 'action', 'session_id' ),
			'restore_status'  => array( 'action', 'session_id' ),
			'cancel'          => array( 'action', 'session_id', 'purpose' ),
		);
		$action = $input['action'] ?? '';
		if ( ! is_string( $action ) || ! isset( $fields[ $action ] ) || array_diff( $fields[ $action ], array_keys( $input ) ) || array_diff( array_keys( $input ), $fields[ $action ] ) ) {
			throw new \RuntimeException( 'backup_input_invalid' );
		}
		foreach ( array( 'session_id', 'archive_id', 'archive_sha256', 'sha256', 'expected_plan_hash' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! Backup_Archive::digest( $input[ $key ] ) ) {
				throw new \RuntimeException( 'backup_input_invalid' );
			}
		}
		foreach ( array( 'offset', 'max_bytes', 'max_total_bytes' ) as $key ) {
			if ( isset( $input[ $key ] ) && ( ! is_int( $input[ $key ] ) || $input[ $key ] < ( 'offset' === $key ? 0 : 1 ) ) ) {
				throw new \RuntimeException( 'backup_input_invalid' );
			}
		}
		if ( isset( $input['purpose'] ) && ! in_array( $input['purpose'], array( 'backup_archive', 'backup_input', 'backup_state' ), true ) ||
			isset( $input['source_roots'] ) && ! is_array( $input['source_roots'] ) ) {
			throw new \RuntimeException( 'backup_input_invalid' );
		}
		$wire = json_encode( $input );
		if ( ! is_string( $wire ) || strlen( $wire ) > ( new Transfer_Settings() )->bytes()['request'] - 2048 ) {
			throw new \RuntimeException( 'backup_request_capacity' );
		}
	}

	private function store( $purpose ) {
		return Private_Transfer_Session::for_administration( $this->permissions, $purpose, $this->access->context() );
	}

	public static function checked( $result ) {
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_code() );
		}
		return $result;
	}

	public static function native_int( $value ) {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( ! is_string( $value ) || ! ctype_digit( $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) ||
			strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) {
			throw new \RuntimeException( 'backup_native_integer_capacity' );
		}
		return (int) $value;
	}
}
