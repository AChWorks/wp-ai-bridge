<?php
/**
 * Explicit native-account SQL preparation, single dispatch and private results.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/**
 * This is deliberately NOT installation-confined. Native account privileges
 * include whatever other schemas, routines, files or control state the host grants.
 */
final class Database_Native_SQL_Operation {
	const FORMAT = 'wpai-native-account-sql-v1';
	const PREFIX = 'wpai_native_account_sql_';

	/** @var Permissions */
	private $permissions;
	/** @var Database_Connection|null */
	private $db;
	/** @var array */
	private $origin;
	/** @var int */
	private $budget;
	/** @var int */
	private $memory;

	/** @param Permissions $permissions Existing grant engine. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/** @param array $input Closed public input. @param callable $allowed Current complete authority. @return array|WP_Error */
	public function execute( array $input, callable $allowed ) {
		$native_token = null;
		$armed        = false;
		$captured     = null;
		$preparation  = null;
		$result_id    = null;
		try {
			class_exists( Database_Connection::class );
			$this->origin = array(
				'blog'    => (int) get_current_blog_id(),
				'network' => (int) get_current_network_id(),
				'user'    => (int) get_current_user_id(),
				'client'  => (string) OAuth_Server::authenticated_mcp_client_id(),
			);
			if ( ! $allowed() || true !== ( $input['acknowledge_native_account_scope'] ?? null ) ) {
				throw new Database_Write_Failure( 'database_native_sql_denied' );
			}
			$this->budget = max( ( new Transfer_Settings() )->bytes()['request'], Bounded_Payload::response_limit() );
			$this->memory = Operation_Content_Limits::effective_memory_bytes();
			if ( $this->memory > 0 ) {
				$this->budget = min( $this->budget, intdiv( $this->memory, 16 ) );
			}
			$input     = $this->validate( $input );
			$reference = $this->reference( $input['operation_id'] );
			$store     = Private_Transfer_Session::for_administration(
				$this->permissions,
				'native_sql_result',
				array(
					'operation_ref'   => $reference,
					'require_network' => is_multisite(),
				)
			);
			if ( 0 === strpos( $input['action'], 'result_' ) ) {
				if ( 'result_status' === $input['action'] ) {
					return $store->status( $input['result_id'] );
				}
				if ( 'result_release' === $input['action'] ) {
					// Immutable exports have no unknown-effect input fence. Cancellation
					// disposes only this bound result and does not alter SQL effect history.
					$cancelled = $store->cancel( $input['result_id'] );
					return is_wp_error( $cancelled ) ? $cancelled : array(
						'result_id'        => $input['result_id'],
						'state'            => 'cancelled',
						'payload_released' => true,
					);
				}
				$length = $input['length'] ?? max( 1, intdiv( Bounded_Payload::response_limit(), 8 ) );
				$length = min( $length, ( new Transfer_Settings() )->bytes()['chunk'], max( 1, intdiv( Bounded_Payload::response_limit(), 8 ) ) );
				$result = $store->read_administration( $input['result_id'], $input['offset'], $length );
				if ( ! is_wp_error( $result ) && isset( $input['expected_sha256'] ) && ( $result['sha256'] ?? null ) !== $input['expected_sha256'] ) {
					throw new Database_Write_Failure( 'database_native_sql_result_stale' );
				}
				return $result;
			}
			$this->db = new Database_Connection();
			if ( 'recover' === $input['action'] ) {
				$raw = $this->receipt_transaction( 'read', $reference );
				return null === $raw ? array(
					'format'                         => self::FORMAT,
					'state'                          => 'not_recorded',
					'operation_ref'                  => $reference,
					'recovery_is_conditional'        => true,
					'reexecution_allowed'            => false,
					'control_store_may_have_changed' => true,
				) : $this->wrapper( $raw, $reference, true );
			}
			$sql        = $this->bytes( $input['statement_base64'] );
			$parameters = array();
			foreach ( $input['parameters'] as $parameter ) {
				$parameters[] = null === $parameter ? null : $this->bytes( $parameter );
			}
			$fingerprint = hash(
				'sha256',
				$this->encode(
					array(
						self::FORMAT,
						$this->stored_origin(),
						hash( 'sha256', $sql ),
						array_map(
							static function ( $value ) {
								return null === $value ? null : array(
									'bytes'  => strlen( $value ),
									'sha256' => hash( 'sha256', $value ),
								); },
							$parameters
						),
						$input['max_result_bytes'],
					)
				)
			);
			// Read historical state before native preparation, target inspection or result reservation.
			// Execute NEVER creates/recreates a missing preparation.
			$raw = $this->receipt_transaction( 'read', $reference );
			if ( null !== $raw ) {
				$record = $this->decode( $raw );
				if ( ! hash_equals( $record['fingerprint'], $fingerprint ) ||
					( 'execute' === $input['action'] && ! hash_equals( $record['token_sha256'], hash( 'sha256', $input['preparation_token'] ) ) ) ) {
					throw new Database_Write_Failure( 'database_native_sql_conflict' );
				}
				if ( 'prepare' === $input['action'] || 'prepared' !== $record['state'] ) {
					$result                      = $this->wrapper( $raw, $reference, true );
					$result['preparation_token'] = null;
					return $result;
				}
			} elseif ( 'execute' === $input['action'] ) {
				throw new Database_Write_Failure( 'database_native_sql_preparation_missing' );
			}
			$this->db->assert_clean_session();
			$prepared     = $this->db->prepare_native_sql( $sql, $parameters, $this->budget, $this->memory );
			$native_token = $prepared['token'];
			if ( $input['max_result_bytes'] < $prepared['minimum_result_bytes'] ) {
				throw new Database_Write_Failure( 'database_native_sql_result_capacity', array( 'minimum_result_bytes' => $prepared['minimum_result_bytes'] ) );
			}
			if ( 'prepare' === $input['action'] ) {
				$this->db->cancel_native_sql( $native_token );
				$native_token = null;
				$token        = bin2hex( random_bytes( 32 ) );
				$record       = array(
					'format'       => self::FORMAT,
					'identity'     => $this->stored_origin(),
					'fingerprint'  => $fingerprint,
					'token_sha256' => hash( 'sha256', $token ),
					'state'        => 'prepared',
					'created_at'   => gmdate( 'c' ),
					'result'       => null,
				);
				$reserved     = $this->receipt_transaction( 'reserve', $reference, $this->encode( $record ) );
				$existing     = $this->decode( $reserved['record'] );
				if ( ! hash_equals( $fingerprint, $existing['fingerprint'] ) ) {
					throw new Database_Write_Failure( 'database_native_sql_conflict' );
				}
				$result                       = $this->wrapper( $reserved['record'], $reference, ! $reserved['acquired'] );
				$result['preparation_token']  = $reserved['acquired'] ? $token : null;
				$result['resource_admission'] = array_diff_key( $prepared, array( 'token' => true ) );
				return $result;
			}
			$reservation = $store->begin_administration_stream( $input['max_result_bytes'] );
			if ( is_wp_error( $reservation ) ) {
				return $reservation;
			}
			$result_id = $reservation['id'];
			$output    = $store->with_administration_writer(
				$result_id,
				function ( $writer ) use ( $allowed, $reference, $record, $raw, &$armed, &$captured, &$preparation, &$native_token ) {
					// File checks/authority may invoke hooks; all occur before arm.
					if ( ! $allowed() || ! $this->same_origin() ) {
						throw new Database_Write_Failure( 'database_native_sql_denied' );
					}
					$this->db->assert_clean_session();
					$next          = $record;
					$next['state'] = 'dispatch_armed';
					$preparation   = $this->encode( $next );
					$this->receipt_transaction( 'replace', $reference, $preparation, $raw );
					$armed        = true;
					$captured     = $this->db->stream_native_sql_once( $native_token, $writer );
					$native_token = null;
					return $captured;
				}
			);
			if ( ! $armed ) {
				return $output;
			}
			$record['state']   = 'completed';
			$record['result']  = array(
				'native'           => $captured['native'] ?? null,
				'result_complete'  => ! is_wp_error( $output ) && ! empty( $captured['result_complete'] ),
				'result_id'        => $result_id,
				'result_available' => ! is_wp_error( $output ),
				'result_error'     => is_wp_error( $output ) ? $output->get_error_code() : ( $captured['result_error'] ?? '' ),
				'rows'             => $captured['rows'] ?? null,
			);
			$reporting_pending = false;
			try {
				$this->db->assert_callback_context();
				$this->receipt_transaction( 'replace', $reference, $this->encode( $record ), $preparation );
			} catch ( \Throwable $error ) {
				$reporting_pending = true;
				$this->db->abandon_native_sql_session();
			}
			$result                      = $this->wrapper( $this->encode( $record ), $reference, false );
			$result['reporting_pending'] = $reporting_pending;
			if ( ! is_wp_error( $output ) ) {
				$result['private_result'] = $output['receipt'];
			}
			return $result;
		} catch ( \Throwable $error ) {
			$reason                             = $error instanceof Database_Write_Failure ? $error->reason : 'database_native_sql_unknown';
			$details                            = $error instanceof Database_Write_Failure ? $error->details : array();
			$details['effect_scope']            = 'native_account';
			$details['recovery_is_conditional'] = true;
			$details['reexecution_allowed']     = false;
			if ( null !== $result_id ) {
				$details['result_id'] = $result_id;
			}
			if ( $armed ) {
				$details['native']  = $captured['native'] ?? null;
				$details['outcome'] = null === $captured ? 'unknown' : $captured['native']['outcome'];
			}
			return new WP_Error( $reason, 'Native-account SQL is not installation-confined. Preserve the preparation identity; never resend an armed statement. Recovery depends on control storage that this SQL may alter.', $details );
		} finally {
			if ( null !== $native_token && null !== $this->db ) {
				$this->db->cancel_native_sql( $native_token );
			}
		}
	}

	/** @param array $input Public input. @return array */
	private function validate( $input ) {
		$common = array( 'action', 'operation_id', 'acknowledge_native_account_scope' );
		$forms  = array(
			'prepare'        => array( 'statement_base64', 'parameters', 'max_result_bytes' ),
			'execute'        => array( 'statement_base64', 'parameters', 'max_result_bytes', 'preparation_token' ),
			'recover'        => array(),
			'result_read'    => array( 'result_id', 'offset', 'length', 'expected_sha256' ),
			'result_status'  => array( 'result_id' ),
			'result_release' => array( 'result_id' ),
		);
		$action = $input['action'] ?? null;
		if ( ! is_string( $action ) || ! isset( $forms[ $action ] ) || array_diff( array_keys( $input ), array_merge( $common, $forms[ $action ] ) ) ||
			! is_string( $input['operation_id'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_.:-]{8,128}$/D', $input['operation_id'] ) ||
			strlen( $this->encode( $input ) ) > ( new Transfer_Settings() )->bytes()['request'] ) {
			throw new Database_Write_Failure( 'database_native_sql_invalid' );
		}
		if ( in_array( $action, array( 'prepare', 'execute' ), true ) ) {
			if ( ! is_string( $input['statement_base64'] ?? null ) || ! is_array( $input['parameters'] ?? null ) || ! array_is_list( $input['parameters'] ) ||
				! is_int( $input['max_result_bytes'] ?? null ) || $input['max_result_bytes'] < 1 || $input['max_result_bytes'] > ( new Transfer_Settings() )->bytes()['file'] ) {
				throw new Database_Write_Failure( 'database_native_sql_invalid' );
			}
			foreach ( $input['parameters'] as $value ) {
				if ( null !== $value ) {
					$this->bytes( $value ); }
			}
			if ( 'execute' === $action && ! $this->digest( $input['preparation_token'] ?? null ) ) {
				throw new Database_Write_Failure( 'database_native_sql_invalid' );
			}
		}
		if ( 0 === strpos( $action, 'result_' ) && ! $this->digest( $input['result_id'] ?? null ) ) {
			throw new Database_Write_Failure( 'database_native_sql_invalid' );
		}
		if ( 'result_read' === $action && ( ! is_int( $input['offset'] ?? null ) || $input['offset'] < 0 ||
			( isset( $input['length'] ) && ( ! is_int( $input['length'] ) || $input['length'] < 1 ) ) ||
			( isset( $input['expected_sha256'] ) && ! $this->digest( $input['expected_sha256'] ) ) ||
			( $input['offset'] > 0 && ! isset( $input['expected_sha256'] ) ) ) ) {
			throw new Database_Write_Failure( 'database_native_sql_invalid' );
		}
		return $input;
	}

	/** @param string $action Internal read/reserve/replace. @param string $reference Native identity. @param string|null $after Proposed bytes. @param string|null $before Expected bytes. @return mixed */
	private function receipt_transaction( $action, $reference, $after = null, $before = null ) {
		$commit_submitted = false;
		$name             = self::PREFIX . $reference;
		try {
			$this->db->begin_callback_journal();
			( new Database_Control_Storage( $this->db, $this->budget ) )->receipt_schema();
			$raw = $this->read_locked( $name );
			if ( 'read' === $action || ( 'reserve' === $action && null !== $raw ) ) {
				if ( ! $this->db->rollback() ) {
					throw new Database_Write_Failure( 'database_native_sql_unknown' );
				}
				return 'read' === $action ? $raw : array(
					'record'   => $raw,
					'acquired' => false,
				);
			}
			if ( 'reserve' === $action ) {
				try {
					$this->db->run( 'INSERT INTO ' . $this->db->table( $this->db->options ) . ' (option_name,option_value,autoload) VALUES (?,?,?)', array( $name, $after, 'off' ) );
				} catch ( Database_Write_Failure $error ) {
					if ( 1062 !== ( $error->details['errno'] ?? 0 ) ) {
						throw $error; }
					$raw = $this->read_locked( $name );
					if ( null === $raw || ! $this->db->rollback() ) {
						throw new Database_Write_Failure( 'database_native_sql_unknown' ); }
					return array(
						'record'   => $raw,
						'acquired' => false,
					);
				}
			} else {
				if ( ! is_string( $raw ) || ! hash_equals( $before, $raw ) ) {
					throw new Database_Write_Failure( 'database_native_sql_unknown' ); }
				$updated = $this->db->run( 'UPDATE ' . $this->db->table( $this->db->options ) . ' SET option_value=? WHERE option_name=? AND BINARY option_name=BINARY ? AND BINARY option_value=BINARY ?', array( $after, $name, $name, $before ) );
				if ( 1 !== $updated['affected'] ) {
					throw new Database_Write_Failure( 'database_native_sql_unknown' ); }
			}
			$raw = $this->read_locked( $name );
			if ( ! is_string( $raw ) || ! hash_equals( $after, $raw ) ) {
				throw new Database_Write_Failure( 'database_native_sql_unknown' ); }
			$commit_submitted = true;
			if ( ! $this->db->commit() ) {
				throw new Database_Write_Failure( 'database_native_sql_unknown' ); }
			return 'reserve' === $action ? array(
				'record'   => $raw,
				'acquired' => true,
			) : $raw;
		} catch ( \Throwable $error ) {
			$restored = $this->db->rollback();
			if ( $commit_submitted || ! $restored ) {
				throw new Database_Write_Failure( 'database_native_sql_unknown' ); }
			throw $error;
		}
	}

	/** @param string $name Fixed preparation identity. @return string|null */
	private function read_locked( $name ) {
		$rows = $this->db->run(
			'SELECT option_name AS name,OCTET_LENGTH(option_value) AS bytes,SUBSTRING(CAST(option_value AS BINARY),1,?) AS value FROM ' .
			$this->db->table( $this->db->options ) . ' WHERE option_name=? LIMIT 2 FOR UPDATE',
			array( (string) ( $this->budget + 1 ), $name ),
			array(),
			2
		)['rows'];
		if ( ! $rows ) {
			return null; }
		if ( 1 !== count( $rows ) || ! hash_equals( $name, $rows[0]['name'] ) || ! ctype_digit( $rows[0]['bytes'] ?? '' ) || (float) $rows[0]['bytes'] > $this->budget ) {
			throw new Database_Write_Failure( 'database_native_sql_unknown' );
		}
		$this->decode( $rows[0]['value'] );
		return $rows[0]['value'];
	}

	/** @param string $raw Exact receipt. @return array */
	private function decode( $raw ) {
		$record = json_decode( $raw, true );
		$fields = array( 'format', 'identity', 'fingerprint', 'token_sha256', 'state', 'created_at', 'result' );
		if ( ! is_array( $record ) || count( $record ) !== count( $fields ) || array_diff( array_keys( $record ), $fields ) ||
			self::FORMAT !== ( $record['format'] ?? null ) || $this->stored_origin() !== ( $record['identity'] ?? null ) ||
			! $this->digest( $record['fingerprint'] ?? null ) || ! $this->digest( $record['token_sha256'] ?? null ) ||
			! in_array( $record['state'] ?? null, array( 'prepared', 'dispatch_armed', 'completed' ), true ) ||
			! is_string( $record['created_at'] ?? null ) || ( 'completed' === $record['state'] ? ! is_array( $record['result'] ) : null !== $record['result'] ) ) {
			throw new Database_Write_Failure( 'database_native_sql_unknown' );
		}
		return $record;
	}

	/** @param string $raw Receipt. @param string $reference Native identity. @param bool $historical Recovery. @return array */
	private function wrapper( $raw, $reference, $historical ) {
		$record = $this->decode( $raw );
		unset( $record['token_sha256'] );
		return array(
			'format'                  => self::FORMAT,
			'state'                   => $record['state'],
			'operation_ref'           => $reference,
			'historical'              => $historical,
			'effect_scope'            => 'native_account',
			'installation_confined'   => false,
			'recovery_is_conditional' => true,
			'reexecution_allowed'     => false,
			'receipt'                 => $record,
		);
	}

	/** @return array */
	private function stored_origin() {
		$origin           = $this->origin;
		$origin['client'] = hash( 'sha256', $origin['client'] );
		return $origin; }

	/** @return bool */
	private function same_origin() {
		$current = array(
			'blog'    => (int) get_current_blog_id(),
			'network' => (int) get_current_network_id(),
			'user'    => (int) get_current_user_id(),
			'client'  => (string) OAuth_Server::authenticated_mcp_client_id(),
		);
		return $this->origin === $current;
	}

	/** @param string $operation_id Stable ID. @return string */
	private function reference( $operation_id ) {
		return hash( 'sha256', $this->encode( array( self::FORMAT, $this->stored_origin(), $operation_id ) ) ); }

	/** @param mixed $value Encoded exact bytes. @return string */
	private function bytes( $value ) {
		if ( ! is_string( $value ) ) {
			throw new Database_Write_Failure( 'database_native_sql_invalid' ); }
		$bytes = base64_decode( $value, true );
		if ( false === $bytes || base64_encode( $bytes ) !== $value ) {
			throw new Database_Write_Failure( 'database_native_sql_invalid' ); }
		return $bytes;
	}

	/** @param mixed $value Inert input/receipt. @return string */
	private function encode( $value ) {
		$this->inert( $value );
		$json = json_encode( $value );
		if ( ! is_string( $json ) || '' === $json || ( isset( $this->budget ) && strlen( $json ) > $this->budget ) ) {
			throw new Database_Write_Failure( 'database_native_sql_budget' ); }
		return $json;
	}

	/** @param mixed $value Inert metadata/input. @param int $depth Finite recursion. @return void */
	private function inert( $value, $depth = 0 ) {
		if ( $depth > 64 || is_object( $value ) || is_resource( $value ) || ( is_float( $value ) && ! is_finite( $value ) ) ) {
			throw new Database_Write_Failure( 'database_native_sql_invalid' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->inert( $item, $depth + 1 ); }
		}
	}

	/** @param mixed $value Digest or private token. @return bool */
	private function digest( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }
}
