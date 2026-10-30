<?php
/**
 * Native current-site/current-network settings read and private snapshots.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Hook_Dispatch_Recovery;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Option_Access;
use WP_AI_Bridge\Support\Native_Option_Snapshots;
use WP_AI_Bridge\Support\Native_Option_Value;
use WP_AI_Bridge\Support\Operation_Content_Limits;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_Error;

/**
 * Native values are filtered observations, never physical-existence or CAS claims.
 */
final class Native_Option_Abilities {
	/** @var Permissions */
	private $permissions;
	/** @var Native_Option_Access */
	private $access;
	/** @var Mutation_Log */
	private $log;
	/** @var bool Avoid recursive native reads through provider callbacks. */
	private $in_flight = false;

	/** @param Permissions $permissions Current authority. @param Mutation_Log|null $log Redacted activity. */
	public function __construct( Permissions $permissions, ?Mutation_Log $log = null ) {
		$this->permissions = $permissions;
		$this->access      = new Native_Option_Access( $permissions );
		$this->log         = $log ? $log : new Mutation_Log();
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/native-options-read',
			array(
				'label'               => __( 'Read Native Settings', 'wp-ai-bridge' ),
				'description'         => __( 'Reads explicitly delegated sensitive native site or network settings and creates complete private value snapshots. Native filters and defaults apply; no settings are changed.', 'wp-ai-bridge' ),
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
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @param mixed $input Exact action/scope. @return bool */
	public function can_execute( $input = array() ) {
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $input ) {
					$parsed = $this->parse_input( $input );
					if ( is_wp_error( $parsed ) ) {
						return false;
					}
					return $this->reauthorize( $parsed['scope'], $this->identity() );
				}
			);
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}

	/** @param mixed $input Closed native read action. @return array<string,mixed>|WP_Error */
	public function execute( $input ) {
		if ( $this->in_flight ) {
			return $this->error( 'native_options_recursive' );
		}
		$parsed = $this->parse_input( $input );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$this->in_flight = true;
		$snapshots       = null;
		$created_id      = '';
		try {
			$result = Hook_Dispatch_Recovery::run(
				function () use ( $parsed, &$snapshots, &$created_id ) {
					$identity = $this->identity();
					$scope    = $parsed['scope'];
					$action   = $parsed['action'];
					if ( ! $this->reauthorize( $scope, $identity ) ) {
						return $this->error( 'native_options_access_denied' );
					}
					// Capture policy before snapshot publication. Native/provider callbacks
					// can allocate more memory; no constant-memory native getter is claimed.
					$response_limit = Bounded_Payload::response_limit();
					if ( ! $this->reauthorize( $scope, $identity ) ) {
						return $this->error( 'native_options_context_changed' );
					}
					if ( 'list' === $action ) {
						$result = $this->list_options( $parsed, $identity, $response_limit );
					} elseif ( in_array( $action, array( 'read', 'snapshot' ), true ) ) {
						$encoded = $this->read_value( $parsed, $identity );
						if ( is_wp_error( $encoded ) ) {
							$result = $encoded;
						} elseif ( 'read' === $action ) {
							$result = array_merge(
								$this->base( $action, $scope, $identity ),
								array(
									'name'              => $parsed['name'],
									'view'              => 'native_options_api',
									'storage_presence'  => 'unknown',
									'format'            => 'wpai-native-option-value-v1',
									'value_json'        => $encoded['json'],
									'value_available'   => true,
									'snapshot_required' => false,
									'total_bytes'       => $encoded['bytes'],
									'sha256'            => $encoded['sha256'],
									'processing_limit_bytes' => $encoded['processing_limit_bytes'],
									'processing_memory_limit_known' => $encoded['processing_memory_limit_known'],
								)
							);
							if ( Bounded_Payload::projected_mcp_bytes( $result ) > $response_limit ) {
								$result['value_json']        = '';
								$result['value_available']   = false;
								$result['snapshot_required'] = true;
							}
						} else {
							$snapshots = new Native_Option_Snapshots( $this->permissions, $scope );
							if ( ! $this->reauthorize( $scope, $identity ) ) {
								return $this->error( 'native_options_context_changed' );
							}
							$result = $snapshots->create( $encoded['json'] );
							if ( ! is_wp_error( $result ) ) {
								$created_id = $result['transfer_id'];
								$result     = array_merge( $this->base( $action, $scope, $identity ), $result );
							}
						}
					} else {
						$snapshots = new Native_Option_Snapshots( $this->permissions, $scope );
						switch ( $action ) {
							case 'snapshot_read':
								$result = $snapshots->read( $parsed['transfer_id'], $parsed['offset'], $parsed['length'] ?? null );
								break;
							case 'snapshot_status':
								$result = $snapshots->status( $parsed['transfer_id'] );
								break;
							default:
								$result = $snapshots->release( $parsed['transfer_id'] );
						}
						if ( ! is_wp_error( $result ) ) {
							$result = array_merge( $this->base( $action, $scope, $identity ), $result );
						}
					}
					$audited = $this->audit( $parsed, $result );
					if ( ! $this->reauthorize( $scope, $identity ) ) {
						return $this->error( 'native_options_context_changed' );
					}
					if ( ! $audited ) {
						return $this->error( 'native_options_audit_unavailable' );
					}
					if ( is_wp_error( $result ) ) {
						return $this->public_error( $result );
					}
					if ( Bounded_Payload::projected_mcp_bytes( $result ) > $response_limit ) {
						return $this->error( 'native_options_response_budget' );
					}
					return $result;
				}
			);
		} catch ( \Throwable $throwable ) {
			// Never return provider exception messages, option values or raw names.
			$result = $this->error( 'native_options_callback_failed' );
		} finally {
			$this->in_flight = false;
		}
		if ( is_wp_error( $result ) && '' !== $created_id ) {
			try {
				$cleaned = Hook_Dispatch_Recovery::run(
					function () use ( $snapshots, $created_id ) {
						return $snapshots->discard_created( $created_id );
					}
				);
			} catch ( \Throwable $throwable ) {
				$cleaned = false;
			}
			if ( true !== $cleaned ) {
				return $this->error( 'native_options_snapshot_cleanup_uncertain' );
			}
		}
		return $result;
	}

	/** @param array $input Exact native key. @param array $identity Original context. @return array|WP_Error */
	private function read_value( array $input, array $identity ) {
		$policy = ( new Transfer_Settings() )->bytes();
		$memory = Operation_Content_Limits::effective_memory_bytes();
		$budget = $policy['file'];
		if ( $memory > 0 ) {
			$free   = max( 0, $memory - memory_get_usage( true ) );
			$budget = min( $budget, intdiv( $free, 8 ) );
		}
		if ( $budget < 1 || strlen( $input['name'] ) > $policy['request'] ) {
			return $this->error(
				'native_option_value_budget',
				array(
					'limiting_layer'         => $budget < 1 ? 'php_processing' : 'request_policy',
					'processing_limit_bytes' => $budget,
				)
			);
		}
		if ( ! $this->reauthorize( $input['scope'], $identity ) ) {
			return $this->error( 'native_options_context_changed' );
		}
		$stored_bytes = $this->storage_bytes( $input['scope'], $input['name'], $identity );
		if ( is_wp_error( $stored_bytes ) ) {
			return $stored_bytes;
		}
		// Physical bytes are only an admission hint. A filter/default may return a
		// different value, and serialized/native values can expand in memory.
		if ( null !== $stored_bytes && $this->decimal_greater( $stored_bytes, (string) $budget ) ) {
			return $this->error(
				'native_option_value_budget',
				array(
					'limiting_layer'         => $budget === $policy['file'] ? 'transfer_file_policy' : 'php_processing',
					'processing_limit_bytes' => $budget,
				)
			);
		}
		if ( ! $this->reauthorize( $input['scope'], $identity ) ) {
			return $this->error( 'native_options_context_changed' );
		}
		$value = 'network' === $input['scope']
			? get_network_option( $identity['network'], $input['name'] )
			: get_option( $input['name'] );
		if ( ! $this->reauthorize( $input['scope'], $identity ) ) {
			return $this->error( 'native_options_context_changed' );
		}
		// Recompute after the native getter: provider filters may consume memory.
		$memory = Operation_Content_Limits::effective_memory_bytes();
		if ( $memory > 0 ) {
			$free   = max( 0, $memory - memory_get_usage( true ) );
			$budget = min( $budget, intdiv( $free, 8 ) );
		}
		if ( $budget < 1 ) {
			return $this->error(
				'native_option_value_budget',
				array(
					'limiting_layer'         => 'php_processing',
					'processing_limit_bytes' => 0,
				)
			);
		}
		$encoded = Native_Option_Value::encode( $value, $budget );
		if ( is_wp_error( $encoded ) ) {
			return 'native_option_value_budget' === $encoded->get_error_code()
				? $this->error(
					'native_option_value_budget',
					array(
						'limiting_layer'         => $budget === $policy['file'] ? 'transfer_file_policy' : 'php_processing',
						'processing_limit_bytes' => $budget,
					)
				)
				: $encoded;
		}
		$encoded['processing_limit_bytes']        = $budget;
		$encoded['processing_memory_limit_known'] = $memory > 0;
		return $encoded;
	}

	/** @param array $input Keyset filters. @param array $identity Bound native tables. @param int $response_limit MCP envelope. @return array|WP_Error */
	private function list_options( array $input, array $identity, $response_limit ) {
		global $wpdb;
		$maximum = max( 1, intdiv( max( 0, $response_limit - Bounded_Payload::MCP_ENVELOPE_HEADROOM_BYTES ), 512 ) );
		$limit   = $input['limit'] ?? min( 50, $maximum );
		if ( $limit > $maximum ) {
			return $this->error( 'native_options_invalid_limit' );
		}
		$after  = $input['after_id'] ?? '0';
		$prefix = $input['name_prefix'] ?? '';
		$like   = $wpdb->esc_like( $prefix ) . '%';
		if ( 'network' === $input['scope'] ) {
			$sql = $wpdb->prepare(
				"SELECT CAST(meta_id AS CHAR) AS id, meta_key AS name, CAST(OCTET_LENGTH(meta_value) AS CHAR) AS value_bytes, '' AS autoload FROM %i WHERE site_id = %d AND meta_id > CAST(%s AS DECIMAL(20,0)) AND meta_key LIKE %s ORDER BY meta_id ASC LIMIT %d",
				$identity['sitemeta'],
				$identity['network'],
				$after,
				$like,
				$limit + 1
			);
		} else {
			$sql = $wpdb->prepare(
				'SELECT CAST(option_id AS CHAR) AS id, option_name AS name, CAST(OCTET_LENGTH(option_value) AS CHAR) AS value_bytes, autoload FROM %i WHERE option_id > CAST(%s AS DECIMAL(20,0)) AND option_name LIKE %s ORDER BY option_id ASC LIMIT %d',
				$identity['options'],
				$after,
				$like,
				$limit + 1
			);
		}
		if ( ! $this->reauthorize( $input['scope'], $identity ) ) {
			return $this->error( 'native_options_context_changed' );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed metadata-only current-site/network keyset query; all identifiers and values were prepared.
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return $this->error( 'native_options_metadata_unavailable' );
		}
		$result = array_merge(
			$this->base( 'list', $input['scope'], $identity ),
			array(
				'items'         => array(),
				'returned'      => 0,
				'next_after_id' => $after,
				'has_more'      => false,
				'inventory'     => 'physical_storage_only',
			)
		);
		$used   = Bounded_Payload::projected_mcp_bytes( $result ) + 128;
		foreach ( $rows as $row ) {
			if ( count( $result['items'] ) >= $limit ) {
				break;
			}
			if ( ! is_array( $row ) || ! $this->decimal( $row['id'] ?? null ) ||
				! $this->decimal_greater( $row['id'], $result['next_after_id'] ) ||
				! $this->valid_text( $row['name'] ?? null, true ) ||
				( ! array_key_exists( 'value_bytes', $row ) || ( null !== $row['value_bytes'] && ! $this->decimal( $row['value_bytes'] ) ) ) || ! is_string( $row['autoload'] ?? null ) ||
				! $this->valid_text( $row['autoload'], true ) ) {
				return $this->error( 'native_options_metadata_unavailable' );
			}
			$item = array_intersect_key( $row, array_fill_keys( array( 'id', 'name', 'value_bytes', 'autoload' ), true ) );
			$json = wp_json_encode( $item );
			if ( ! is_string( $json ) ) {
				return $this->error( 'native_options_metadata_unavailable' );
			}
			$escaped = wp_json_encode( $json );
			$cost    = is_string( $escaped ) ? strlen( $json ) + strlen( $escaped ) + 16 : PHP_INT_MAX;
			if ( $cost > $response_limit - $used ) {
				break;
			}
			$used                   += $cost;
			$result['items'][]       = $item;
			$result['next_after_id'] = $row['id'];
		}
		$result['returned'] = count( $result['items'] );
		$result['has_more'] = count( $rows ) > $result['returned'];
		if ( $rows && 0 === $result['returned'] ) {
			return $this->error( 'native_options_response_budget' );
		}
		return $result;
	}

	/** @param string $scope Current scope. @param string $name Exact native name. @param array $identity Original native tables. @return string|null|WP_Error */
	private function storage_bytes( $scope, $name, array $identity ) {
		global $wpdb;
		$sql   = 'network' === $scope
			? $wpdb->prepare( 'SELECT CAST(MAX(OCTET_LENGTH(meta_value)) AS CHAR) FROM %i WHERE site_id = %d AND meta_key = %s', $identity['sitemeta'], $identity['network'], $name )
			: $wpdb->prepare( 'SELECT CAST(OCTET_LENGTH(option_value) AS CHAR) FROM %i WHERE option_name = %s LIMIT 1', $identity['options'], $name );
		$bytes = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed physical byte-count admission only; never retrieves option values.
		if ( '' !== $wpdb->last_error || ( null !== $bytes && ! $this->decimal( $bytes ) ) ) {
			return $this->error( 'native_options_metadata_unavailable' );
		}
		return $bytes;
	}

	/** @return array<string,mixed> Exact request-local native identity, never public. */
	private function identity() {
		global $wpdb;
		return array(
			'user'     => get_current_user_id(),
			'blog'     => get_current_blog_id(),
			'network'  => function_exists( 'get_current_network_id' ) ? get_current_network_id() : 0,
			'client'   => OAuth_Server::authenticated_mcp_client_id(),
			'wpdb'     => $wpdb,
			'options'  => $wpdb->options ?? '',
			'sitemeta' => $wpdb->sitemeta ?? '',
		);
	}

	/** @param string $scope Bound scope. @param array $identity Original context. @return bool */
	private function reauthorize( $scope, array $identity ) {
		return $this->same_identity( $identity ) && $this->access->allowed( $scope ) && $this->same_identity( $identity );
	}

	/** @param array $identity Original context. @return bool */
	private function same_identity( array $identity ) {
		return $identity === $this->identity();
	}

	/** @param string $action Operation. @param string $scope Scope. @param array $identity Bound context. @return array */
	private function base( $action, $scope, array $identity ) {
		return array(
			'action'     => $action,
			'scope'      => $scope,
			'blog_id'    => $identity['blog'],
			'network_id' => $identity['network'],
		);
	}

	/** @param mixed $input Closed operation fields. @return array|WP_Error */
	private function parse_input( $input ) {
		$fields = array(
			'list'             => array( 'action', 'scope', 'after_id', 'name_prefix', 'limit' ),
			'read'             => array( 'action', 'scope', 'name' ),
			'snapshot'         => array( 'action', 'scope', 'name' ),
			'snapshot_read'    => array( 'action', 'scope', 'transfer_id', 'offset', 'length' ),
			'snapshot_status'  => array( 'action', 'scope', 'transfer_id' ),
			'snapshot_release' => array( 'action', 'scope', 'transfer_id' ),
		);
		if ( ! is_array( $input ) || ! is_string( $input['action'] ?? null ) ||
			! isset( $fields[ $input['action'] ] ) ||
			! in_array( $input['scope'] ?? '', array( 'site', 'network' ), true ) ||
			array_diff( array_keys( $input ), $fields[ $input['action'] ] ) ) {
			return $this->error( 'native_options_invalid_input' );
		}
		$action = $input['action'];
		if ( in_array( $action, array( 'read', 'snapshot' ), true ) &&
			( ! $this->valid_text( $input['name'] ?? null ) || trim( $input['name'] ) !== $input['name'] ) ) {
			return $this->error( 'native_options_invalid_input' );
		}
		if ( 'list' === $action &&
			( ( isset( $input['after_id'] ) && ! $this->decimal( $input['after_id'] ) ) ||
			( isset( $input['name_prefix'] ) && ! $this->valid_text( $input['name_prefix'], true ) ) ||
			( isset( $input['limit'] ) && ( ! is_int( $input['limit'] ) || $input['limit'] < 1 ) ) ) ) {
			return $this->error( 'native_options_invalid_input' );
		}
		if ( 0 === strpos( $action, 'snapshot_' ) &&
			( ! is_string( $input['transfer_id'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['transfer_id'] ) ||
			( 'snapshot_read' === $action && ( ! is_int( $input['offset'] ?? null ) || $input['offset'] < 0 ||
			( isset( $input['length'] ) && ( ! is_int( $input['length'] ) || $input['length'] < 1 ) ) ) ) ) ) {
			return $this->error( 'native_options_invalid_input' );
		}
		// Explicit null is not the same as omitting an optional field.
		foreach ( $input as $value ) {
			if ( null === $value ) {
				return $this->error( 'native_options_invalid_input' );
			}
		}
		return $input;
	}

	/** @param mixed $text Native UTF-8 text. @param bool $empty_allowed Whether empty is valid. @return bool */
	private function valid_text( $text, $empty_allowed = false ) {
		return is_string( $text ) && ( $empty_allowed || '' !== $text ) &&
			false === strpos( $text, "\0" ) && 1 === preg_match( '//u', $text );
	}

	/** @param mixed $value Unsigned native bigint decimal string. @return bool */
	private function decimal( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) &&
			! $this->decimal_greater( $value, '18446744073709551615' );
	}

	/** @param string $left Canonical decimal. @param string $right Canonical decimal. @return bool */
	private function decimal_greater( $left, $right ) {
		return strlen( $left ) > strlen( $right ) ||
			( strlen( $left ) === strlen( $right ) && strcmp( $left, $right ) > 0 );
	}

	/** @param array $input Valid action. @param mixed $result Redacted outcome. @return bool */
	private function audit( array $input, $result ) {
		$fingerprint = isset( $input['name'] ) ? substr( hash( 'sha256', $input['name'] ), 0, 16 ) : '';
		return $this->log->record(
			'wp-ai-bridge/native-options-read',
			'native_' . $input['scope'] . '_' . $input['action'] . '_' . $fingerprint,
			0,
			! is_wp_error( $result ),
			is_wp_error( $result ) ? $this->public_error( $result )->get_error_code() : ''
		);
	}

	/** @param WP_Error $error Internal codec/staging error, never provider messages. @return WP_Error */
	private function public_error( WP_Error $error ) {
		$code = $error->get_error_code();
		if ( ! is_string( $code ) || strlen( $code ) > 80 ||
			1 !== preg_match( '/^(native_options?_[a-z0-9_]+|transfer_[a-z0-9_]+)$/D', $code ) ) {
			$code = 'native_options_unavailable';
		}
		$data = $error->get_error_data();
		$safe = array();
		if ( is_array( $data ) ) {
			if ( in_array( $data['limiting_layer'] ?? '', array( 'request_policy', 'php_processing', 'transfer_file_policy' ), true ) ) {
				$safe['limiting_layer'] = $data['limiting_layer'];
			}
			if ( in_array( $data['storage_reason'] ?? '', array( 'root_web_served', 'root_symlink', 'root_noncanonical', 'root_mode', 'lease_file_mode' ), true ) ) {
				$safe['storage_reason'] = $data['storage_reason'];
			}
			foreach ( array( 'processing_limit_bytes', 'file_limit_bytes', 'request_limit_bytes', 'chunk_limit_bytes', 'active_limit_bytes', 'available_bytes', 'required_bytes', 'session_limit', 'active_sessions' ) as $field ) {
				if ( is_int( $data[ $field ] ?? null ) && $data[ $field ] >= 0 ) {
					$safe[ $field ] = $data[ $field ];
				}
			}
		}
		return $this->error( $code, $safe ? $safe : null );
	}

	/** @param string $code Fixed internal classification. @param array|null $data Server-owned resource metadata. @return WP_Error */
	private function error( $code, $data = null ) {
		switch ( $code ) {
			case 'native_options_access_denied':
			case 'native_option_snapshot_denied':
			case 'transfer_denied':
				$message = __( 'Enable sensitive native site settings read for the current administrator. Network reads also require current-network consent and actual Super Admin network authority.', 'wp-ai-bridge' );
				break;
			case 'native_options_context_changed':
				$message = __( 'The site, network, user, client or delegated authority changed during this request. Resume from the intended current context and use only snapshots owned by that context.', 'wp-ai-bridge' );
				break;
			case 'native_options_invalid_input':
				$message = __( 'Use only the fields for the selected native settings action and scope. Names must be exact nonempty UTF-8 without NUL or surrounding whitespace; cursors must be canonical decimal strings within the native unsigned integer range.', 'wp-ai-bridge' );
				break;
			case 'native_options_invalid_limit':
				$message = __( 'The requested list limit exceeds the current response policy. Request a smaller page or omit limit to use the current default.', 'wp-ai-bridge' );
				break;
			case 'native_option_value_unsupported':
				$message = __( 'This native value cannot be represented losslessly by the current codec. Objects, resources, references, cycles and nesting beyond the supported depth require an owning provider contract.', 'wp-ai-bridge' );
				break;
			case 'native_option_value_budget':
				if ( 'php_processing' === ( $data['limiting_layer'] ?? '' ) ) {
					$message = __( 'The complete native value exceeds the current PHP processing allowance. Check host memory and provider value expansion; smaller transfer windows do not reduce native value materialization.', 'wp-ai-bridge' );
				} elseif ( 'request_policy' === ( $data['limiting_layer'] ?? '' ) ) {
					$message = __( 'The exact native settings request exceeds the current incoming request policy. Check the requested native name and the administrator transfer request budget.', 'wp-ai-bridge' );
				} else {
					$message = __( 'The complete native value exceeds the current transfer file or processing budget. Check the reported limiting layer and administrator capacity before exporting it.', 'wp-ai-bridge' );
				}
				break;
			case 'native_options_response_budget':
			case 'transfer_message_budget':
				$message = __( 'The complete result cannot fit the current MCP response budget. Request a smaller list page or snapshot window, or use a private snapshot for a full native value.', 'wp-ai-bridge' );
				break;
			case 'native_options_metadata_unavailable':
				$message = __( 'Native option storage metadata could not be read. Check the current WordPress options or network metadata schema and database read privileges.', 'wp-ai-bridge' );
				break;
			case 'native_options_callback_failed':
			case 'native_option_snapshot_failed':
				$message = __( 'An installed native settings, permission or snapshot callback failed. Inspect trusted provider diagnostics and private snapshot status before repeating the operation.', 'wp-ai-bridge' );
				break;
			case 'native_options_recursive':
				$message = __( 'A provider callback attempted a nested native settings read. Adjust that callback to avoid reentering the same native settings Ability.', 'wp-ai-bridge' );
				break;
			case 'native_options_audit_unavailable':
				$message = __( 'Bridge Activity could not persist the access record. Restore Activity storage and verify current authority before repeating the read or export.', 'wp-ai-bridge' );
				break;
			case 'native_options_snapshot_cleanup_uncertain':
			case 'transfer_cleanup_uncertain':
				$message = __( 'Private snapshot cleanup could not be verified. Inspect Bridge private storage and existing snapshot status before reserving another export.', 'wp-ai-bridge' );
				break;
			case 'transfer_expired':
				$message = __( 'The private snapshot lease expired. Create a new snapshot under current authority and restart reading from its own byte identity.', 'wp-ai-bridge' );
				break;
			case 'transfer_cancelled':
				$message = __( 'This private snapshot was released or cancelled. Use another owned live snapshot or create a new one under current authority.', 'wp-ai-bridge' );
				break;
			case 'transfer_invalid':
				$message = __( 'The snapshot ID, byte offset, requested length or transfer policy is invalid. Check the exact owned transfer identity and use a valid bounded byte window.', 'wp-ai-bridge' );
				break;
			case 'transfer_size_setting':
				$message = __( 'The complete snapshot exceeds the administrator transfer file budget. Adjust that budget within actual host capacity before creating the export.', 'wp-ai-bridge' );
				break;
			case 'transfer_capacity':
				$message = __( 'The current private transfer session or aggregate reservation budget is full. Release completed owned snapshots or adjust the administrator reservation policy.', 'wp-ai-bridge' );
				break;
			case 'transfer_disk_unknown':
				$message = __( 'Available private disk capacity could not be measured. Check the host private temporary storage before creating a snapshot.', 'wp-ai-bridge' );
				break;
			case 'transfer_disk_insufficient':
				$message = __( 'Private temporary storage has insufficient free capacity for this reservation. Release completed owned snapshots or provide enough private disk space.', 'wp-ai-bridge' );
				break;
			case 'transfer_storage_unsafe':
				$message = __( 'The host cannot currently provide verified private snapshot storage. Inspect the reported storage reason and configure writable owner-only storage outside served web directories.', 'wp-ai-bridge' );
				break;
			case 'transfer_storage_unavailable':
				$message = __( 'Private snapshot storage or its admission coordinator is unavailable. Check host permissions and private temporary storage availability.', 'wp-ai-bridge' );
				break;
			case 'transfer_session_busy':
				$message = __( 'The private transfer coordinator is busy. Inspect the existing snapshot status and retry its continuation after the active operation completes.', 'wp-ai-bridge' );
				break;
			case 'transfer_checksum_mismatch':
			case 'transfer_source_changed':
				$message = __( 'The private snapshot bytes or identity no longer match the verified source. Inspect its status; reconstruct a new complete snapshot instead of combining changed windows.', 'wp-ai-bridge' );
				break;
			case 'transfer_storage_uncertain':
			case 'transfer_outcome_unknown':
				$message = __( 'Private snapshot persistence or byte integrity could not be verified. Inspect current snapshot status and private storage before creating or releasing more bytes.', 'wp-ai-bridge' );
				break;
			default:
				$message = __( 'The native settings operation could not complete. Inspect current grants, trusted provider diagnostics and private snapshot status before repeating it.', 'wp-ai-bridge' );
		}
		return new WP_Error( $code, $message, $data );
	}

	/** @return array */
	private function input_schema() {
		$scope  = array(
			'type' => 'string',
			'enum' => array( 'site', 'network' ),
		);
		$id     = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		$specs  = array(
			'list'             => array(
				'after_id'    => array(
					'type'      => 'string',
					'pattern'   => '^(0|[1-9][0-9]*)$',
					'maxLength' => 20,
				),
				'name_prefix' => array( 'type' => 'string' ),
				'limit'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'read'             => array(
				'name' => array(
					'type'      => 'string',
					'minLength' => 1,
				),
			),
			'snapshot'         => array(
				'name' => array(
					'type'      => 'string',
					'minLength' => 1,
				),
			),
			'snapshot_read'    => array(
				'transfer_id' => $id,
				'offset'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'length'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'snapshot_status'  => array( 'transfer_id' => $id ),
			'snapshot_release' => array( 'transfer_id' => $id ),
		);
		$one_of = array();
		foreach ( $specs as $action => $properties ) {
			$required = array( 'action', 'scope' );
			if ( in_array( $action, array( 'read', 'snapshot' ), true ) ) {
				$required[] = 'name';
			} elseif ( 0 === strpos( $action, 'snapshot_' ) ) {
				$required[] = 'transfer_id';
				if ( 'snapshot_read' === $action ) {
					$required[] = 'offset';
				}
			}
			$one_of[] = array(
				'type'                 => 'object',
				'properties'           => array_merge(
					array(
						'action' => array(
							'type' => 'string',
							'enum' => array( $action ),
						),
						'scope'  => $scope,
					),
					$properties
				),
				'required'             => $required,
				'additionalProperties' => false,
			);
		}
		return array(
			'type'  => 'object',
			'oneOf' => $one_of,
		);
	}

	/** @return array */
	private function output_schema() {
		$string = array( 'type' => 'string' );
		$int    = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		$bool   = array( 'type' => 'boolean' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'                        => $string,
				'scope'                         => array(
					'type' => 'string',
					'enum' => array( 'site', 'network' ),
				),
				'blog_id'                       => $int,
				'network_id'                    => $int,
				'items'                         => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'          => $string,
							'name'        => $string,
							'value_bytes' => array( 'type' => array( 'string', 'null' ) ),
							'autoload'    => $string,
						),
						'required'             => array( 'id', 'name', 'value_bytes', 'autoload' ),
						'additionalProperties' => false,
					),
				),
				'returned'                      => $int,
				'next_after_id'                 => $string,
				'has_more'                      => $bool,
				'inventory'                     => $string,
				'name'                          => $string,
				'view'                          => $string,
				'storage_presence'              => $string,
				'format'                        => $string,
				'value_json'                    => $string,
				'value_available'               => $bool,
				'snapshot_required'             => $bool,
				'total_bytes'                   => $int,
				'sha256'                        => $string,
				'processing_limit_bytes'        => $int,
				'processing_memory_limit_known' => $bool,
				'transfer_id'                   => $string,
				'expires_at'                    => $int,
				'state'                         => $string,
				'offset'                        => $int,
				'next_offset'                   => $int,
				'complete'                      => $bool,
				'base64'                        => $string,
				'chunk_sha256'                  => $string,
				'released'                      => $bool,
			),
			'required'             => array( 'action', 'scope', 'blog_id', 'network_id' ),
			'additionalProperties' => false,
		);
	}
}
