<?php
/**
 * Fixed-purpose native administration dispatch journals.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/**
 * Acknowledges a durable fence before one effect. It never executes the effect.
 *
 * Native authorization belongs to the caller. There are no application callbacks
 * on the successful arm path after the acknowledged journal commit.
 */
final class Administration_Operation_Journal {
	const FORMAT   = 'wpai-administration-operation-v1';
	const PREFIX   = 'wpai_administration_operation_';
	const PURPOSES = array( 'schema', 'filesystem', 'scheduler', 'configuration', 'customizer' );

	/** @var string */
	private $purpose;
	/** @var array */
	private $identity;
	/** @var int */
	private $budget;
	/** @var Database_Connection|null */
	private $connection;
	/** @var array */
	private $claims = array();

	/** @param string $purpose Fixed server-owned purpose. @param array $identity Captured native identity. @param int $metadata_budget Finite compact metadata budget. */
	public function __construct( $purpose, array $identity, $metadata_budget ) {
		if ( ! in_array( $purpose, self::PURPOSES, true ) ||
			array_diff( array_keys( $identity ), array( 'scope', 'blog', 'network', 'user', 'client' ) ) ||
			! in_array( $identity['scope'] ?? '', array( 'site', 'network' ), true ) ||
			! is_int( $identity['blog'] ?? null ) || $identity['blog'] < 1 ||
			! is_int( $identity['network'] ?? null ) || $identity['network'] < 0 ||
			( 'network' === $identity['scope'] && $identity['network'] < 1 ) ||
			! is_int( $identity['user'] ?? null ) || $identity['user'] < 1 ||
			! is_string( $identity['client'] ?? null ) ||
			! is_int( $metadata_budget ) || $metadata_budget < 1 || $metadata_budget >= PHP_INT_MAX ) {
			throw new \InvalidArgumentException( 'Invalid fixed administration journal identity or budget.' );
		}
		$this->purpose  = $purpose;
		$this->identity = $identity;
		$this->budget   = $metadata_budget;
	}

	/** @return true|WP_Error Current native and transport identity check. */
	public function check_context() {
		try {
			$this->assert_context();
			return true;
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/** @param string $operation_id Stable identity. @param string|null $fingerprint Exact expected request. @return array|null|WP_Error */
	public function status( $operation_id, $fingerprint = null ) {
		try {
			$this->assert_context();
			$reference = $this->reference( $operation_id );
			if ( null !== $fingerprint && ! $this->digest( $fingerprint ) ) {
				throw new Database_Write_Failure( 'administration_operation_invalid' );
			}
			$record = $this->transaction( 'read', $reference );
			return null === $record ? null : $this->wrapper( $record, $reference, $fingerprint, true );
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/** @param string $operation_id Stable identity. @param string $fingerprint Exact request digest. @param array $descriptor Compact server-owned metadata. @return array|WP_Error */
	public function reserve( $operation_id, $fingerprint, array $descriptor ) {
		try {
			$this->assert_context();
			$reference = $this->reference( $operation_id );
			if ( ! $this->digest( $fingerprint ) ) {
				throw new Database_Write_Failure( 'administration_operation_invalid' );
			}
			$record                     = array(
				'format'      => self::FORMAT,
				'purpose'     => $this->purpose,
				'identity'    => $this->stored_identity(),
				'fingerprint' => $fingerprint,
				'created_at'  => gmdate( 'c' ),
				'state'       => 'prepared',
				'descriptor'  => $descriptor,
				'result'      => null,
			);
			$initial                    = $this->encode( $record );
			$stored                     = $this->transaction( 'reserve', $reference, $initial );
			$result                     = $this->wrapper( $stored['record'], $reference, $fingerprint, ! $stored['acquired'] );
			$result['dispatch_allowed'] = false;
			$result['claim']            = null;
			if ( $stored['acquired'] ) {
				$token                      = bin2hex( random_bytes( 32 ) );
				$this->claims[ $token ]     = array(
					'reference' => $reference,
					'raw'       => $initial,
					'record'    => $record,
					'armed'     => false,
					'completed' => false,
				);
				$result['dispatch_allowed'] = true;
				$result['claim']            = array( 'token' => $token );
			}
			return $result;
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/**
	 * Call only after the final hook-capable authorization and target checks.
	 * The next caller action must be its single native effect.
	 *
	 * @param array $claim This instance's private winning claim.
	 * @return true|WP_Error
	 */
	public function arm( array $claim ) {
		try {
			$this->assert_context();
			$owned = $this->claim( $claim );
			if ( $owned['armed'] || $owned['completed'] ) {
				throw new Database_Write_Failure( 'administration_operation_conflict' );
			}
			$record          = $owned['record'];
			$record['state'] = 'dispatch_armed';
			$after           = $this->encode( $record );
			$this->transaction( 'replace', $owned['reference'], $after, $owned['raw'] );
			$this->claims[ $claim['token'] ]['record'] = $record;
			$this->claims[ $claim['token'] ]['raw']    = $after;
			$this->claims[ $claim['token'] ]['armed']  = true;
			return true;
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/** @param array $claim Private owned claim. @param array $result Captured compact outcome, never submitted values/SQL. @return array|WP_Error */
	public function complete( array $claim, array $result ) {
		try {
			$this->assert_context();
			$owned = $this->claim( $claim );
			if ( $owned['completed'] || ( ! $owned['armed'] && 'not_dispatched' !== ( $result['outcome'] ?? null ) ) ) {
				throw new Database_Write_Failure( 'administration_operation_conflict' );
			}
			$record           = $owned['record'];
			$record['state']  = 'completed';
			$record['result'] = $result;
			$after            = $this->encode( $record );
			$this->transaction( 'replace', $owned['reference'], $after, $owned['raw'] );
			$this->claims[ $claim['token'] ]['completed'] = true;
			return $this->wrapper( $after, $owned['reference'], $record['fingerprint'], false );
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/** @return array Stored identity never contains the client secret/token. */
	private function stored_identity() {
		$identity           = $this->identity;
		$identity['client'] = hash( 'sha256', $identity['client'] );
		return $identity;
	}

	/** @return void */
	private function assert_context() {
		if ( (int) get_current_blog_id() !== $this->identity['blog'] ||
			(int) get_current_user_id() !== $this->identity['user'] ||
			(int) get_current_network_id() !== $this->identity['network'] ||
			(string) OAuth_Server::authenticated_mcp_client_id() !== $this->identity['client'] ) {
			throw new Database_Write_Failure( 'administration_operation_context_changed' );
		}
		$this->db()->assert_callback_context();
	}

	/** @return Database_Connection */
	private function db() {
		if ( null === $this->connection ) {
			$this->connection = new Database_Connection();
		}
		return $this->connection;
	}

	/**
	 * Each mutation has its own short transaction; none encloses a native effect.
	 *
	 * @param string $action Fixed read/reserve/replace.
	 * @param string $reference Hashed native identity.
	 * @param string|null $after Exact proposed record.
	 * @param string|null $before Exact previously acknowledged record.
	 * @return mixed
	 */
	private function transaction( $action, $reference, $after = null, $before = null ) {
		$db               = $this->db();
		$commit_submitted = false;
		$name             = self::PREFIX . $reference;
		try {
			$db->begin_callback_journal();
			( new Database_Control_Storage( $db, $this->budget ) )->receipt_schema();
			$record = $this->read_locked( $name );
			if ( 'read' === $action || ( 'reserve' === $action && null !== $record ) ) {
				if ( ! $db->rollback() ) {
					throw new Database_Write_Failure( 'administration_operation_unknown' );
				}
				return 'read' === $action ? $record : array(
					'acquired' => false,
					'record'   => $record,
				);
			}
			if ( 'reserve' === $action ) {
				try {
					$db->run(
						'INSERT INTO ' . $db->table( $db->options ) . ' (option_name, option_value, autoload) VALUES (?, ?, ?)',
						array( $name, $after, 'off' )
					);
				} catch ( Database_Write_Failure $error ) {
					if ( 1062 !== ( $error->details['errno'] ?? 0 ) ) {
						throw $error;
					}
					$record = $this->read_locked( $name );
					if ( null === $record || ! $db->rollback() ) {
						throw new Database_Write_Failure( 'administration_operation_unknown' );
					}
					return array(
						'acquired' => false,
						'record'   => $record,
					);
				}
			} elseif ( 'replace' === $action ) {
				if ( ! is_string( $record ) || ! hash_equals( $before, $record ) ) {
					throw new Database_Write_Failure( 'administration_operation_unknown' );
				}
				$updated = $db->run(
					'UPDATE ' . $db->table( $db->options ) . ' SET option_value=? WHERE option_name=? AND BINARY option_name=BINARY ? AND BINARY option_value=BINARY ?',
					array( $after, $name, $name, $before )
				);
				if ( 1 !== $updated['affected'] ) {
					throw new Database_Write_Failure( 'administration_operation_unknown' );
				}
			} else {
				throw new Database_Write_Failure( 'administration_operation_invalid' );
			}
			$stored = $this->read_locked( $name );
			if ( ! is_string( $stored ) || ! hash_equals( $after, $stored ) ) {
				throw new Database_Write_Failure( 'administration_operation_unknown' );
			}
			$commit_submitted = true;
			if ( ! $db->commit() ) {
				throw new Database_Write_Failure( 'administration_operation_unknown' );
			}
			return 'reserve' === $action ? array(
				'acquired' => true,
				'record'   => $stored,
			) : $stored;
		} catch ( \Throwable $error ) {
			$restored = $db->rollback();
			if ( $commit_submitted || ! $restored ) {
				throw new Database_Write_Failure( 'administration_operation_unknown' );
			}
			throw $error;
		}
	}

	/** @param string $name Fixed internal identity. @return string|null */
	private function read_locked( $name ) {
		$db   = $this->db();
		$rows = $db->run(
			'SELECT option_name AS name, OCTET_LENGTH(option_value) AS bytes, SUBSTRING(CAST(option_value AS BINARY),1,?) AS value FROM ' .
				$db->table( $db->options ) . ' WHERE option_name=? LIMIT 2 FOR UPDATE',
			array( (string) ( $this->budget + 1 ), $name ),
			array(),
			2
		)['rows'];
		if ( ! $rows ) {
			return null;
		}
		if ( 1 !== count( $rows ) || ! is_string( $rows[0]['name'] ) || ! hash_equals( $name, $rows[0]['name'] ) ||
			! ctype_digit( $rows[0]['bytes'] ?? '' ) || (float) $rows[0]['bytes'] > $this->budget ) {
			throw new Database_Write_Failure( 'administration_operation_unknown' );
		}
		return $rows[0]['value'];
	}

	/** @param array $claim Private claim. @return array */
	private function claim( $claim ) {
		if ( array_keys( $claim ) !== array( 'token' ) || ! is_string( $claim['token'] ) || ! isset( $this->claims[ $claim['token'] ] ) ) {
			throw new Database_Write_Failure( 'administration_operation_invalid' );
		}
		return $this->claims[ $claim['token'] ];
	}

	/** @param string $operation_id Native stable ID. @return string */
	private function reference( $operation_id ) {
		if ( ! is_string( $operation_id ) || ! preg_match( '/^[A-Za-z0-9_.:-]{8,128}$/D', $operation_id ) ) {
			throw new Database_Write_Failure( 'administration_operation_invalid' );
		}
		return hash( 'sha256', $this->encode( array( self::FORMAT, $this->purpose, $this->stored_identity(), $operation_id ) ) );
	}

	/** @param string $raw Exact stored bytes. @param string $reference Identity. @param string|null $fingerprint Expected request. @param bool $historical Readback. @return array */
	private function wrapper( $raw, $reference, $fingerprint, $historical ) {
		$record = json_decode( $raw, true );
		$fields = array( 'format', 'purpose', 'identity', 'fingerprint', 'created_at', 'state', 'descriptor', 'result' );
		if ( ! is_array( $record ) || array_diff( array_keys( $record ), $fields ) || count( $record ) !== count( $fields ) ||
			self::FORMAT !== $record['format'] || $this->purpose !== $record['purpose'] ||
			$this->stored_identity() !== $record['identity'] || ! $this->digest( $record['fingerprint'] ) ||
			! is_string( $record['created_at'] ) || ! is_array( $record['descriptor'] ) ||
			! in_array( $record['state'], array( 'prepared', 'dispatch_armed', 'completed' ), true ) ||
			( 'completed' === $record['state'] ? ! is_array( $record['result'] ) : null !== $record['result'] ) ) {
			throw new Database_Write_Failure( 'administration_operation_unknown' );
		}
		if ( null !== $fingerprint && ! hash_equals( $fingerprint, $record['fingerprint'] ) ) {
			throw new Database_Write_Failure( 'administration_operation_conflict' );
		}
		return array(
			'state'         => $record['state'],
			'operation_ref' => $reference,
			'historical'    => $historical,
			'receipt'       => $record,
		);
	}

	/** @param mixed $value Compact inert metadata. @return string */
	private function encode( $value ) {
		$this->inert( $value );
		$json = json_encode( $value );
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > $this->budget ) {
			throw new Database_Write_Failure( 'administration_operation_budget' );
		}
		return $json;
	}

	/** @param mixed $value Inert metadata. @param int $depth Finite recursion. @return void */
	private function inert( $value, $depth = 0 ) {
		if ( $depth > 64 || is_object( $value ) || is_resource( $value ) || ( is_float( $value ) && ! is_finite( $value ) ) ) {
			throw new Database_Write_Failure( 'administration_operation_invalid' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->inert( $item, $depth + 1 );
			}
		}
	}

	/** @param mixed $value Digest. @return bool */
	private function digest( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	/** @param \Throwable $error Internal error. @return WP_Error */
	private function failure( $error ) {
		$reason  = $error instanceof Database_Write_Failure ? $error->reason : 'administration_operation_unknown';
		$details = $error instanceof Database_Write_Failure ? $error->details : array();
		if ( 0 === strpos( $reason, 'database_write_' ) ) {
			$details['native_reason'] = $reason;
			$reason                   = 'database_write_unsupported_session' === $reason ? 'administration_operation_unsupported_session' : 'administration_operation_unknown';
		}
		return new WP_Error( $reason, 'The administration operation journal could not be verified. Recover this identity; never blindly repeat an armed effect.', $details );
	}
}
