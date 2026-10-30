<?php
/**
 * Shared exact native configuration transport and dispatch boundary.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/** Fixed configuration families only; no caller-selected callable or policy registry. */
final class Native_Configuration_Operation {
	/** @var Permissions */ private $permissions;
	/** @var Administration_Access */ private $access;
	/** @var Mutation_Log */ private $log;
	/** @var bool */ private $customizer;
	/** @var bool */ private $running     = false;
	/** @var bool */ private $dispatched  = false;
	/** @var string */ private $reference = '';
	/** @var Private_Transfer_Session|null Own new creation cleanup proof. */ private $created_store;
	/** @var string */ private $created_id = '';

	/** @param Permissions $permissions Delegation. @param Mutation_Log $log Activity. @param bool $customizer Fixed family. */
	public function __construct( Permissions $permissions, Mutation_Log $log, $customizer = false ) {
		$this->permissions = $permissions;
		$this->access      = new Administration_Access( $permissions );
		$this->log         = $log;
		$this->customizer  = true === $customizer;
	}

	/** @param array $input Closed validated input. @return bool */
	public function allowed( array $input ) {
		$network      = 'site_transient' === $input['target'] && is_multisite();
		$write        = in_array( $input['action'], array( 'set', 'delete', 'update' ), true ) || 0 === strpos( $input['action'], 'input_' );
		$requirements = array( array( Settings::GROUP_NATIVE_OPTIONS_READ, 'manage_options' ) );
		if ( $write ) {
			$requirements[] = array( Settings::GROUP_NATIVE_OPTIONS_WRITE, 'manage_options' );
			$requirements[] = array( Settings::GROUP_SITE_CONFIG, 'manage_options' );
		}
		if ( $network ) {
			$requirements[] = array( Settings::GROUP_NATIVE_NETWORK_READ, 'manage_network_options' );
			if ( $write ) {
				$requirements[] = array( Settings::GROUP_NATIVE_NETWORK_WRITE, 'manage_network_options' );
			}
		}
		if ( 'delete' === $input['action'] ) {
			$requirements[] = array( Settings::GROUP_USERS_DESTRUCTIVE, 'manage_options' );
		}
		if ( 'theme_mod' === $input['target'] || $this->customizer ) {
			$requirements[] = array( $this->group(), 'edit_theme_options' );
		}
		if ( $this->customizer ) {
			$requirements[] = array( $this->group(), 'customize' );
			if ( in_array( $input['action'], array( 'list', 'read', 'snapshot', 'update' ), true ) ) {
				$requirements = array_merge( $requirements, self::provider_requirements() );
			}
		}
		return $this->access->allowed( $this->group(), $network, $requirements );
	}

	/** @return array Existing conservative unclassified-provider authority. */
	private static function provider_requirements() {
		return array(
			array( Settings::GROUP_BUILDER_WRITE, 'edit_posts' ),
			array( Settings::GROUP_REMOTE_MEDIA, 'upload_files' ),
			array( Settings::GROUP_LIVE_CONTENT, 'publish_posts' ),
			array( Settings::GROUP_SITE_CONFIG, 'manage_options' ),
			array( Settings::GROUP_ADVANCED_METADATA, 'manage_options' ),
			array( Settings::GROUP_AUTHENTICATION, 'manage_options' ),
			array( Settings::GROUP_CODE_EXTENSIONS, 'activate_plugins' ),
			array( Settings::GROUP_EXTERNAL_PACKAGES, 'install_plugins' ),
			array( Settings::GROUP_SOURCE_EDITING, 'edit_plugins' ),
			array( Settings::GROUP_SOURCE_EDITING, 'edit_themes' ),
			array( Settings::GROUP_COMMENTS, 'moderate_comments' ),
			array( Settings::GROUP_USERS_DESTRUCTIVE, 'delete_users' ),
		);
	}

	/** @return string Fixed group, never request-selected. */
	private function group() {
		return $this->customizer ? Settings::GROUP_CUSTOMIZER_WRITE : Settings::GROUP_NATIVE_CONFIGURATION;
	}

	/**
	 * Execute callbacks selected by the two first-party family providers only.
	 * Preparation (including provider registration/validation) precedes arm().
	 *
	 * @param array $input Closed input.
	 * @param callable $read Native observation.
	 * @param callable $dispatch One native setter/publication call.
	 * @param callable $prepare Pre-dispatch native registration/validation/guard.
	 * @param callable $listing Native identity listing.
	 * @param callable|null $pre_arm Hook-free fixed context identity check.
	 * @return array|WP_Error
	 */
	public function execute( array $input, callable $read, callable $dispatch, callable $prepare, callable $listing, ?callable $pre_arm = null ) {
		if ( $this->running ) {
			return $this->error( 'configuration_recursive' );
		}
		$this->running       = true;
		$this->dispatched    = false;
		$this->reference     = '';
		$this->created_store = null;
		$this->created_id    = '';
		try {
			$result = Hook_Dispatch_Recovery::run(
				function () use ( $input, $read, $dispatch, $prepare, $listing, $pre_arm ) {
					$origin = $this->access->context();
					if ( ! $this->authorized( $input, $origin ) ) {
						return $this->error( 'configuration_denied' );
					}
					$theme = $this->theme( $input );
					if ( is_wp_error( $theme ) || ! $this->authorized( $input, $origin, $theme ) ) {
						return $this->error( 'configuration_theme_changed' );
					}
					$policy = ( new Transfer_Settings() )->bytes();
					if ( ! $this->request_fits( $input, $policy['request'] ) ) {
						return $this->error( 'configuration_request_budget' );
					}
					$memory   = Operation_Content_Limits::effective_memory_bytes();
					$free     = $memory > 0 ? max( 0, $memory - memory_get_usage( true ) ) : 0;
					$budget   = $memory > 0 ? min( $policy['file'], intdiv( $free, 8 ) ) : $policy['file'];
					$metadata = max( $policy['request'], Bounded_Payload::response_limit() );
					if ( $memory > 0 ) {
						$metadata = min( $metadata, intdiv( $free, 8 ) );
					}
					if ( $budget < 1 || $metadata < 1 ) {
						return $this->error( 'configuration_budget' );
					}
					$identity = array_merge( array( 'scope' => 'site_transient' === $input['target'] && is_multisite() ? 'network' : 'site' ), $origin );
					$journal  = new Administration_Operation_Journal( $this->customizer ? 'customizer' : 'configuration', $identity, $metadata );
					$physical = $journal->check_context();
					if ( is_wp_error( $physical ) ) {
						return $this->error( 'configuration_storage_unavailable' );
					}
					$action = $input['action'];
					if ( 'status' === $action ) {
						$result = $journal->status( $input['operation_id'] );
						if ( is_array( $result ) && ( $result['receipt']['descriptor']['target'] ?? null ) !== $input['target'] ) {
							$result = $this->error( 'configuration_operation_target_mismatch' );
						}
						if ( null === $result ) {
							$result = array(
								'state'         => 'not_recorded',
								'receipt'       => null,
								'operation_ref' => '',
								'historical'    => true,
							);
						}
					} elseif ( 0 === strpos( $action, 'input_' ) || 0 === strpos( $action, 'snapshot_' ) ) {
						$result = $this->transfer( $input, $theme, $policy );
						if ( ! is_wp_error( $result ) ) {
							$result = $this->public_transfer( $result );
						}
					} elseif ( 'list' === $action ) {
						$result = $prepare( null );
						if ( ! is_wp_error( $result ) && $this->authorized( $input, $origin, $theme ) ) {
							$result = $listing();
						}
					} elseif ( in_array( $action, array( 'read', 'snapshot' ), true ) ) {
						$result = $prepare( null );
						if ( ! is_wp_error( $result ) && $this->authorized( $input, $origin, $theme ) ) {
							$result = $this->observation( $read, $budget );
							if ( ! is_wp_error( $result ) ) {
								$result = 'snapshot' === $action ? $this->snapshot( $result, $input, $theme, $policy ) : $this->inline( $result );
							}
						}
					} else {
						$result = $this->mutate( $input, $origin, $theme, $budget, $policy, $journal, $read, $dispatch, $prepare, $pre_arm );
					}
					if ( ! $this->authorized( $input, $origin, $theme ) || true !== $journal->check_context() ) {
						return $this->error( 'configuration_context_changed' );
					}
					if ( is_array( $result ) && isset( $result['operation_ref'] ) ) {
						$this->reference = $result['operation_ref'];
					}
					if ( ! $this->audit( $result ) ) {
						return $this->error( 'configuration_audit_unavailable' );
					}
					if ( ! $this->authorized( $input, $origin, $theme ) || true !== $journal->check_context() ) {
						return $this->error( 'configuration_context_changed' );
					}
					if ( is_wp_error( $result ) ) {
						return $this->error( $this->safe_code( $result->get_error_code() ) );
					}
					if ( ! is_array( $result ) ) {
						return $this->error( 'configuration_context_changed' );
					}
					$result = array_merge(
						array(
							'action'     => $action,
							'target'     => $input['target'],
							'blog_id'    => $origin['blog'],
							'network_id' => $origin['network'],
							'stylesheet' => $theme,
						),
						$result
					);
					if ( Bounded_Payload::projected_mcp_bytes( $result ) > Bounded_Payload::response_limit() ) {
						return $this->error( 'configuration_response_budget' );
					}
					return $result;
				}
			);
		} catch ( \Throwable $error ) {
			$result = $this->error( 'configuration_callback_failed' );
		} finally {
			$this->running = false;
		}
		if ( is_wp_error( $result ) && $this->created_store && '' !== $this->created_id ) {
			try {
				$cleaned = $this->created_store->discard_administration_creation( $this->created_id );
			} catch ( \Throwable $error ) {
				$cleaned = false;
			}
			if ( true !== $cleaned ) {
				return $this->error( 'configuration_stage_cleanup_uncertain' );
			}
		}
		return $result;
	}

	/** @param array $input Closed flat request plus one value-source object. @param int $maximum Native request policy. @return bool */
	private function request_fits( array $input, $maximum ) {
		$maximum -= Bounded_Payload::MCP_ENVELOPE_HEADROOM_BYTES;
		$raw      = 0;
		foreach ( $input as $value ) {
			foreach ( is_array( $value ) ? $value : array( $value ) as $part ) {
				if ( is_string( $part ) ) {
					if ( strlen( $part ) > $maximum - $raw ) {
						return false;
					}
					$raw += strlen( $part );
				}
			}
		}
		$memory = Operation_Content_Limits::effective_memory_bytes();
		if ( $memory > 0 && $raw > intdiv( max( 0, $memory - memory_get_usage( true ) ), 8 ) ) {
			return false;
		}
		$encoded = wp_json_encode( $input );
		return is_string( $encoded ) && strlen( $encoded ) <= $maximum;
	}

	/** @param array $input Target. @return string|WP_Error Captured current stylesheet. */
	private function theme( array $input ) {
		if ( ! $this->customizer && 'theme_mod' !== $input['target'] ) {
			return '';
		}
		$theme = get_stylesheet();
		if ( ! self::text( $theme ) || ( isset( $input['expected_stylesheet'] ) && $theme !== $input['expected_stylesheet'] ) || get_option( 'stylesheet' ) !== $theme ) {
			return $this->error( 'configuration_theme_changed' );
		}
		return $theme;
	}

	/** @param array $input Exact operation. @param array $origin Native identity. @param string|null $theme Captured theme. @return bool */
	private function authorized( array $input, array $origin, $theme = null ) {
		if ( ! $this->access->same_context( $origin ) || ! $this->allowed( $input ) || ! $this->access->same_context( $origin ) ) {
			return false;
		}
		if ( null !== $theme && '' !== $theme && ( get_stylesheet() !== $theme || get_option( 'stylesheet' ) !== $theme ) ) {
			return false;
		}
		return $this->access->same_context( $origin );
	}

	/** @param callable $read Getter. @param int $budget Resource admission. @return array|WP_Error */
	private function observation( callable $read, $budget ) {
		$value = $read();
		return is_wp_error( $value ) ? $this->error( 'configuration_observation_unavailable' ) : Native_Option_Value::encode( $value, $budget, true );
	}

	/** @param array $encoded Complete document. @return array */
	private function inline( array $encoded ) {
		$result = array(
			'format'            => 'wpai-native-option-value-v2',
			'value_json'        => $encoded['json'],
			'total_bytes'       => $encoded['bytes'],
			'sha256'            => $encoded['sha256'],
			'value_available'   => true,
			'snapshot_required' => false,
			'storage_presence'  => 'unknown',
		);
		if ( $encoded['bytes'] > max( 0, Bounded_Payload::response_limit() - 4096 ) || Bounded_Payload::projected_mcp_bytes( $result ) + 1024 > Bounded_Payload::response_limit() ) {
			$result['value_json']        = '';
			$result['value_available']   = false;
			$result['snapshot_required'] = true;
		}
		return $result;
	}

	/** @param array $input Validated mutation. @param array $origin Identity. @param string $theme Current theme. @param int $budget Processing. @param array $policy Transfer policy. @param Administration_Operation_Journal $journal Fence. @param callable $read Getter. @param callable $dispatch One setter. @param callable $prepare Native preparation. @param callable|null $pre_arm Hook-free context check. @return array|WP_Error */
	private function mutate( array $input, array $origin, $theme, $budget, array $policy, Administration_Operation_Journal $journal, callable $read, callable $dispatch, callable $prepare, ?callable $pre_arm ) {
		$source      = $input['value_source'] ?? null;
		$hash        = is_array( $source ) ? ( $source['sha256'] ?? hash( 'sha256', $source['value_json'] ) ) : null;
		$bytes       = is_array( $source ) ? ( $source['total_bytes'] ?? strlen( $source['value_json'] ) ) : 0;
		$descriptor  = array(
			'action'                   => $input['action'],
			'target'                   => $input['target'],
			'name'                     => $input['name'],
			'stylesheet'               => $theme,
			'value_sha256'             => $hash,
			'value_bytes'              => $bytes,
			'expiration_seconds'       => $input['expiration_seconds'] ?? null,
			'expected_observed_sha256' => $input['expected_observed_sha256'] ?? null,
		);
		$fingerprint = hash( 'sha256', json_encode( $descriptor, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
		$historical  = $journal->status( $input['operation_id'], $fingerprint );
		if ( is_wp_error( $historical ) || null !== $historical ) {
			return $historical;
		}
		$value = null;
		if ( null !== $source ) {
			$decoded = $this->decode_source( $source, $input, $theme, $budget );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}
			$value = $decoded['value'];
		}
		$prepared = $prepare( $value );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( ! $this->authorized( $input, $origin, $theme ) ) {
			return $this->error( 'configuration_context_changed' );
		}
		if ( isset( $input['expected_observed_sha256'] ) ) {
			$observed = $this->observation( $read, $budget );
			if ( is_wp_error( $observed ) || ! hash_equals( $input['expected_observed_sha256'], $observed['sha256'] ) ) {
				return $this->error( 'configuration_precondition' );
			}
		}
		if ( ! $this->authorized( $input, $origin, $theme ) ) {
			return $this->error( 'configuration_context_changed' );
		}
		$reserved = $journal->reserve( $input['operation_id'], $fingerprint, $descriptor );
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}
		if ( ! $reserved['dispatch_allowed'] ) {
			unset( $reserved['claim'], $reserved['dispatch_allowed'] );
			return $reserved;
		}
		$this->reference = $reserved['operation_ref'];
		$claim           = $reserved['claim'];
		$result          = array(
			'outcome'               => 'not_dispatched',
			'native_return'         => null,
			'callback_threw'        => false,
			'observed_after_sha256' => null,
			'observation_error'     => '',
		);
		if ( ! $this->authorized( $input, $origin, $theme ) ) {
			return $journal->complete( $claim, $result );
		}
		if ( null !== $pre_arm && true !== $pre_arm() ) {
			return $journal->complete( $claim, $result );
		}
		$armed = $journal->arm( $claim );
		if ( is_wp_error( $armed ) || true !== $armed ) {
			return is_wp_error( $armed ) ? $armed : $this->error( 'configuration_journal_unavailable' );
		}
		// No hook/getter/authorization call occurs after arm acknowledgement and
		// before entering this one server-selected native effect.
		$this->dispatched  = true;
		$result['outcome'] = 'unknown';
		try {
			$return                  = Hook_Dispatch_Recovery::run(
				function () use ( $dispatch, $value ) {
					return $dispatch( $value );
				}
			);
			$result['outcome']       = 'native_call_returned';
			$result['native_return'] = is_bool( $return ) ? $return : null;
			$result['native_error']  = is_wp_error( $return );
			if ( is_array( $return ) && isset( $return['changeset_id'] ) && is_int( $return['changeset_id'] ) ) {
				$result['changeset_id'] = $return['changeset_id'];
			}
		} catch ( \Throwable $error ) {
			$result['callback_threw'] = true;
		}
		if ( $this->authorized( $input, $origin, $theme ) && true === $journal->check_context() ) {
			try {
				$observed = $this->observation( $read, $budget );
				if ( is_wp_error( $observed ) ) {
					$result['observation_error'] = 'configuration_observation_unavailable';
				} else {
					$result['observed_after_sha256'] = $observed['sha256'];
				}
			} catch ( \Throwable $error ) {
				$result['observation_error'] = 'configuration_callback_failed';
			}
		} else {
			$result['observation_error'] = 'configuration_context_changed';
		}
		return $journal->complete( $claim, $result );
	}

	/** @param array $source Closed value source. @param array $input Target. @param string $theme Bound theme. @param int $budget Processing. @return array|WP_Error */
	private function decode_source( array $source, array $input, $theme, $budget ) {
		if ( isset( $source['value_json'] ) ) {
			return Native_Option_Value::decode( $source['value_json'], $budget, $this->memory_ceiling(), true );
		}
		$store = $this->store( false, $input, $theme );
		return $store->with_administration_source(
			$source['transfer_id'],
			function ( array $descriptor ) use ( $source, $budget ) {
				if ( $descriptor['bytes'] !== $source['total_bytes'] || ! hash_equals( $source['sha256'], $descriptor['sha256'] ) || $descriptor['bytes'] > $budget ) {
					return $this->error( 'configuration_input_identity' );
				}
				$document = stream_get_contents( $descriptor['stream'], $descriptor['bytes'] + 1 );
				if ( ! is_string( $document ) || strlen( $document ) !== $descriptor['bytes'] || ! hash_equals( $source['sha256'], hash( 'sha256', $document ) ) ) {
					return $this->error( 'configuration_input_identity' );
				}
				return Native_Option_Value::decode( $document, $budget, $this->memory_ceiling(), true );
			}
		);
	}

	/** @return int Absolute conservative allocation ceiling or zero. */
	private function memory_ceiling() {
		$memory = Operation_Content_Limits::effective_memory_bytes();
		return $memory > 0 ? $memory - intdiv( max( 0, $memory - memory_get_usage( true ) ), 2 ) : 0;
	}

	/** @param bool $snapshot Export or input. @param array $input Target. @param string $theme Bound theme. @return Private_Transfer_Session */
	private function store( $snapshot, array $input, $theme ) {
		return Private_Transfer_Session::for_administration(
			$this->permissions,
			( $this->customizer ? 'customizer_' : 'configuration_' ) . ( $snapshot ? 'snapshot' : 'input' ),
			array(
				'require_network' => 'site_transient' === $input['target'] && is_multisite(),
				'theme'           => $theme,
			)
		);
	}

	/** @param array $input Stage operation. @param string $theme Bound theme. @param array $policy Operator policy. @return array|WP_Error */
	private function transfer( array $input, $theme, array $policy ) {
		$action   = $input['action'];
		$snapshot = 0 === strpos( $action, 'snapshot_' );
		$store    = $this->store( $snapshot, $input, $theme );
		switch ( $action ) {
			case 'input_begin':
				$receipt = $store->begin( ( $this->customizer ? 'customizer_' : 'configuration_' ) . 'input', $input['total_bytes'], $input['sha256'], $policy['file'], min( $policy['chunk'], max( 1, intdiv( $policy['request'], 8 ) ) ), $policy['sessions'], $policy['ttl'], $policy['active'] );
				if ( ! is_wp_error( $receipt ) ) {
					$this->created_store = $store;
					$this->created_id    = $receipt['id'];
				}
				return $receipt;
			case 'input_append':
				$bytes = base64_decode( $input['base64'], true );
				if ( ! is_string( $bytes ) || base64_encode( $bytes ) !== $input['base64'] || strlen( $bytes ) > $policy['chunk'] ) {
					return $this->error( 'configuration_input_invalid' );
				}
				return $store->append( $input['transfer_id'], $input['offset'], $bytes );
			case 'input_verify':
				return $store->verify( $input['transfer_id'] );
			case 'snapshot_read':
				return $store->read_administration( $input['transfer_id'], $input['offset'], min( $input['length'] ?? $policy['chunk'], $policy['chunk'], max( 1, intdiv( Bounded_Payload::response_limit(), 8 ) ) ) );
			case 'input_release':
			case 'snapshot_release':
				$result = $store->cancel( $input['transfer_id'] );
				return is_wp_error( $result ) ? $result : array(
					'transfer_id' => $input['transfer_id'],
					'released'    => true,
				);
			default:
				return $store->status( $input['transfer_id'] );
		}
	}

	/** @param array $encoded Complete inert document. @param array $input Target. @param string $theme Bound theme. @param array $policy Operator limits. @return array|WP_Error */
	private function snapshot( array $encoded, array $input, $theme, array $policy ) {
		$writer = Private_Artifact_Writer::begin( $policy['file'] );
		if ( is_wp_error( $writer ) ) {
			return $writer;
		}
		$appended = $writer->append( $encoded['json'] );
		if ( is_wp_error( $appended ) ) {
			$writer->abort();
			return $appended;
		}
		$artifact = $writer->finish();
		if ( is_wp_error( $artifact ) ) {
			return $artifact;
		}
		try {
			$store   = $this->store( true, $input, $theme );
			$receipt = $store->stage_administration_export( $artifact['path'], $artifact['bytes'], $artifact['sha256'], $policy );
			if ( ! is_wp_error( $receipt ) ) {
				$this->created_store = $store;
				$this->created_id    = $receipt['id'];
				return $this->public_transfer( $receipt );
			}
			return $receipt;
		} finally {
			wp_delete_file( $artifact['path'] );
		}
	}

	/** @param array $receipt Private backend public receipt. @return array Closed family projection. */
	private function public_transfer( array $receipt ) {
		$map    = array(
			'id'       => 'transfer_id',
			'total'    => 'total_bytes',
			'received' => 'received_bytes',
			'chunk'    => 'chunk_bytes',
			'expires'  => 'expires_at',
		);
		$result = array_intersect_key( $receipt, array_flip( array( 'transfer_id', 'sha256', 'state', 'released', 'offset', 'next_offset', 'base64', 'chunk_sha256', 'complete', 'integrity' ) ) );
		foreach ( $map as $from => $to ) {
			if ( array_key_exists( $from, $receipt ) ) {
				$result[ $to ] = $receipt[ $from ];
			}
		}
		return $result;
	}

	/** @param mixed $result Redacted success/failure. @return bool Exact private activity acknowledgement. */
	private function audit( $result ) {
		$ability = $this->customizer ? 'wp-ai-bridge/customizer-settings' : 'wp-ai-bridge/native-configuration';
		$event   = 'configuration_' . bin2hex( random_bytes( 16 ) );
		$user    = get_current_user_id();
		$success = ! is_wp_error( $result );
		if ( ! $this->log->record( $ability, $event, 0, $success, $success ? '' : 'configuration_failed' ) ) {
			return false;
		}
		$entries = get_option( Mutation_Log::OPTION_NAME, array() );
		if ( ! is_array( $entries ) || count( $entries ) > Mutation_Log::LIMIT ) {
			return false;
		}
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) && ( $entry['ability'] ?? null ) === $ability && ( $entry['target_type'] ?? null ) === $event && ( $entry['user_id'] ?? null ) === $user && ( $entry['success'] ?? null ) === $success ) {
				return true;
			}
		}
		return false;
	}

	/** @param string $code Internal fixed failure. @return string */
	private function safe_code( $code ) {
		return is_string( $code ) && preg_match( '/^(?:configuration|customizer|administration_operation|native_option_value|native_options_write|database_write|transfer|artifact)_[a-z0-9_]{1,72}$/D', $code ) ? $code : 'configuration_native_rejected';
	}

	/** @param string $code Fixed redacted code. @return WP_Error */
	public function error( $code ) {
		return new WP_Error(
			$code,
			__( 'The native configuration operation could not be confirmed. Check current grants, target identity, capacity and original operation status before retrying.', 'wp-ai-bridge' ),
			array(
				'dispatch_attempted' => $this->dispatched,
				'operation_ref'      => $this->reference,
			)
		);
	}

	/** @param mixed $value Exact nonempty UTF-8 name. @return bool */
	public static function text( $value ) {
		return is_string( $value ) && '' !== $value && trim( $value ) === $value && false === strpos( $value, "\0" ) && 1 === preg_match( '//u', $value );
	}

	/** @param mixed $source Closed complete value source. @return bool */
	public static function valid_source( $source ) {
		if ( ! is_array( $source ) ) {
			return false;
		}
		if ( array( 'value_json' ) === array_keys( $source ) ) {
			return is_string( $source['value_json'] ) && '' !== $source['value_json'];
		}
		return 3 === count( $source ) && ! array_diff( array_keys( $source ), array( 'transfer_id', 'sha256', 'total_bytes' ) ) && self::digest( $source['transfer_id'] ?? null ) && self::digest( $source['sha256'] ?? null ) && is_int( $source['total_bytes'] ?? null ) && $source['total_bytes'] > 0;
	}

	/** @param mixed $value Digest or private lease ID. @return bool */
	public static function digest( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}
}
