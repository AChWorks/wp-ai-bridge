<?php
/**
 * Purpose-owned native Options target checks and durable dispatch fences.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Internal persistence only. Callers own native authorization and callbacks.
 */
final class Native_Option_Operation {
	/** @var array */
	private $identity;
	/** @var int */
	private $budget;
	/** @var Database_Connection|null */
	private $connection;
	/** @var string */
	private $network_table;
	/** @var array */
	private $targets = array();
	/** @var array */
	private $claims = array();

	/** @param array $identity Captured scope/blog/network/user/client. @param int $metadata_budget Finite allowance. */
	public function __construct( array $identity, $metadata_budget ) {
		class_exists( Database_Connection::class );
		class_exists( Database_Control_Storage::class );
		class_exists( Create_Claim_Store::class );
		if ( array_diff( array_keys( $identity ), array( 'scope', 'blog', 'network', 'user', 'client' ) ) ||
			! in_array( $identity['scope'] ?? '', array( 'site', 'network' ), true ) ||
			! is_int( $identity['blog'] ?? null ) || $identity['blog'] < 1 ||
			! is_int( $identity['network'] ?? null ) || $identity['network'] < 0 ||
			( 'network' === $identity['scope'] && $identity['network'] < 1 ) ||
			! is_int( $identity['user'] ?? null ) || $identity['user'] < 1 ||
			! is_string( $identity['client'] ?? null ) ||
			! is_int( $metadata_budget ) || $metadata_budget < 1 || $metadata_budget >= PHP_INT_MAX ) {
			throw new \InvalidArgumentException( 'Invalid native Options journal identity or metadata budget.' );
		}
		$this->identity = $identity;
		$this->budget   = $metadata_budget;
		global $wpdb;
		$this->network_table = isset( $wpdb->sitemeta ) && is_string( $wpdb->sitemeta ) ? $wpdb->sitemeta : '';
	}

	/**
	 * Hook-free physical binding check before/after hook-capable native work.
	 *
	 * @return true|WP_Error
	 */
	public function check_context() {
		try {
			$this->assert_context();
			return true;
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/** @param string $scope Bound scope. @param string $name Exact native name. @return array|WP_Error */
	public function check_target( $scope, $name ) {
		try {
			$target                  = $this->target( $scope, $name );
			$token                   = bin2hex( random_bytes( 32 ) );
			$target['target_token']  = $token;
			$this->targets[ $token ] = $target;
			return $target;
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/** @param string $operation_id Stable ID. @param string|null $fingerprint Exact request identity. @return array|null|WP_Error */
	public function status( $operation_id, $fingerprint = null ) {
		try {
			$this->assert_context();
			$reference = $this->reference( $operation_id );
			if ( null !== $fingerprint && ! $this->digest( $fingerprint ) ) {
				throw new Database_Write_Failure( 'native_options_write_invalid' );
			}
			$raw = Create_Claim_Store::read_native_option_operation( $this->db(), $this->receipt_name( $reference ), $this->budget );
			return null === $raw ? null : $this->result( $raw, $reference, $fingerprint, true );
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/** @param string $operation_id Stable ID. @param string $fingerprint Exact request. @param array $target Owned descriptor plus action. @return array|WP_Error */
	public function reserve( $operation_id, $fingerprint, array $target ) {
		try {
			$reference = $this->reference( $operation_id );
			if ( ! $this->digest( $fingerprint ) ) {
				throw new Database_Write_Failure( 'native_options_write_invalid' );
			}
			$target = $this->owned_target( $target );
			// Recheck native representation and aliases after any provider reads.
			$current = $this->target( $target['scope'], $target['name'] );
			foreach ( array( 'table', 'name_column', 'charset', 'collation', 'max_characters' ) as $field ) {
				if ( $current[ $field ] !== $target[ $field ] ) {
					throw new Database_Write_Failure( 'native_options_write_target_changed' );
				}
			}
			$receipt                    = array(
				'format'                   => Create_Claim_Store::NATIVE_OPTION_FORMAT,
				'purpose'                  => Create_Claim_Store::NATIVE_OPTION_PURPOSE,
				'scope'                    => $this->identity['scope'],
				'blog'                     => $this->identity['blog'],
				'network'                  => $this->identity['network'],
				'user'                     => $this->identity['user'],
				'client_fingerprint'       => hash( 'sha256', $this->identity['client'] ),
				'action'                   => $target['action'],
				'name'                     => $target['name'],
				'fingerprint'              => $fingerprint,
				'created_at'               => gmdate( 'c' ),
				'state'                    => 'fenced',
				'outcome'                  => 'unknown',
				'native_return'            => null,
				'callback_threw'           => false,
				'observed_after_sha256'    => null,
				'observed_after_available' => false,
				'observation_error'        => '',
			);
			$initial                    = $this->encode( $receipt );
			$stored                     = Create_Claim_Store::reserve_native_option_operation(
				$this->db(),
				$this->receipt_name( $reference ),
				$initial,
				$this->budget
			);
			$result                     = $this->result( $stored['record'], $reference, $fingerprint, ! $stored['acquired'] );
			$result['dispatch_allowed'] = false;
			$result['claim']            = null;
			if ( $stored['acquired'] ) {
				$token                      = bin2hex( random_bytes( 32 ) );
				$claim                      = array( 'token' => $token );
				$this->claims[ $token ]     = array(
					'reference' => $reference,
					'name'      => $this->receipt_name( $reference ),
					'initial'   => $initial,
					'receipt'   => $receipt,
					'target'    => $target,
					'ready'     => false,
				);
				$result['dispatch_allowed'] = true;
				$result['claim']            = $claim;
			}
			return $result;
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/**
	 * Hook-free success path after the API's final hook-capable authorization.
	 *
	 * @param array $claim This object's private reservation token.
	 * @return true|WP_Error
	 */
	public function ready( array $claim ) {
		try {
			$this->assert_context();
			$owned = $this->claim( $claim );
			if ( $owned['ready'] ) {
				throw new Database_Write_Failure( 'native_options_write_operation_conflict' );
			}
			// This is observational again, not an atomic native Options CAS.
			$this->target( $owned['target']['scope'], $owned['target']['name'] );
			Create_Claim_Store::ready_native_option_operation( $this->db(), $owned['name'], $owned['initial'], $this->budget );
			$this->claims[ $claim['token'] ]['ready'] = true;
			return true;
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/** @param array $claim Private reservation token. @param array $captured_result Fixed native outcome metadata. @return array|WP_Error */
	public function complete( array $claim, array $captured_result ) {
		try {
			$this->assert_context();
			$owned  = $this->claim( $claim );
			$result = $this->captured( $captured_result );
			if ( ! $owned['ready'] && 'not_dispatched' !== $result['outcome'] ) {
				throw new Database_Write_Failure( 'native_options_write_invalid' );
			}
			$receipt          = array_merge( $owned['receipt'], $result );
			$receipt['state'] = 'completed';
			$after            = $this->encode( $receipt );
			$stored           = Create_Claim_Store::complete_native_option_operation(
				$this->db(),
				$owned['name'],
				$owned['initial'],
				$after,
				$this->budget
			);
			return $this->result( $stored, $owned['reference'], $owned['receipt']['fingerprint'], false );
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/** Assert original physical namespace without invoking WordPress callbacks. */
	private function assert_context() {
		$this->db()->assert_callback_context();
		global $wpdb;
		if ( 'network' === $this->identity['scope'] &&
			( ! isset( $wpdb->sitemeta ) || $this->network_table !== $wpdb->sitemeta ) ) {
			throw new Database_Write_Failure( 'native_options_write_context_changed' );
		}
	}

	/** @return Database_Connection Retain one physical handle through this request. */
	private function db() {
		if ( null === $this->connection ) {
			$this->connection = new Database_Connection();
		}
		return $this->connection;
	}

	/** @param array $target Descriptor created by this object. @return array */
	private function owned_target( $target ) {
		$token = $target['target_token'] ?? null;
		$owned = is_string( $token ) ? ( $this->targets[ $token ] ?? null ) : null;
		if ( ! is_array( $owned ) || ! in_array( $target['action'] ?? '', array( 'add', 'update', 'delete' ), true ) ) {
			throw new Database_Write_Failure( 'native_options_write_invalid' );
		}
		$copy = $target;
		unset( $copy['action'] );
		if ( $copy !== $owned ) {
			throw new Database_Write_Failure( 'native_options_write_invalid' );
		}
		return $target;
	}

	/** @param array $claim Private claim. @return array */
	private function claim( $claim ) {
		if ( array_keys( $claim ) !== array( 'token' ) || ! is_string( $claim['token'] ) || ! isset( $this->claims[ $claim['token'] ] ) ) {
			throw new Database_Write_Failure( 'native_options_write_invalid' );
		}
		return $this->claims[ $claim['token'] ];
	}

	/**
	 * Validate physical name representation and aliases, never read a value.
	 *
	 * @param string $scope Bound native scope.
	 * @param string $name Exact name.
	 * @return array
	 */
	private function target( $scope, $name ) {
		if ( $scope !== $this->identity['scope'] || ! is_string( $name ) || '' === $name || trim( $name ) !== $name ||
			false !== strpos( $name, "\0" ) || 1 !== preg_match( '//u', $name ) || strlen( $name ) > $this->budget ) {
			throw new Database_Write_Failure( 'native_options_write_invalid_name' );
		}
		$this->assert_context();
		$connection = $this->db();
		$table      = 'site' === $scope ? $connection->options : $this->network_table;
		$column     = 'site' === $scope ? 'option_name' : 'meta_key';
		if ( ! is_string( $table ) || '' === $table || 0 !== strpos( $table, $connection->prefix ) ) {
			throw new Database_Write_Failure( 'native_options_write_unsupported_storage' );
		}
		try {
			$connection->begin_callback_journal();
			( new Database_Control_Storage( $connection, $this->budget ) )->pin_table( $table, false );
			$columns = $connection->run(
				'SELECT DATA_TYPE AS type, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation, CHARACTER_MAXIMUM_LENGTH AS characters FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ? AND BINARY COLUMN_NAME=BINARY ?',
				array( $connection->database, $table, $column ),
				array(),
				2
			)['rows'];
			if ( count( $columns ) !== 1 || ! in_array( $columns[0]['type'], array( 'char', 'varchar' ), true ) ||
				! ctype_digit( $columns[0]['characters'] ?? '' ) ||
				! is_string( $columns[0]['charset'] ) || ! preg_match( '/^[a-z0-9_]+$/iD', $columns[0]['charset'] ) ||
				! is_string( $columns[0]['collation'] ) || ! preg_match( '/^[a-z0-9_]+$/iD', $columns[0]['collation'] ) ) {
				throw new Database_Write_Failure( 'native_options_write_unsupported_storage' );
			}
			$charset   = $columns[0]['charset'];
			$collation = $columns[0]['collation'];
			$converted = 'CONVERT(CONVERT(CAST(? AS BINARY) USING utf8mb4) USING ' . $charset . ') COLLATE ' . $collation;
			$proposed  = 'proposed.name COLLATE ' . $collation;
			$policy    = Database_Control_Storage::name_expression( $proposed );
			$special   = "CASE WHEN $proposed IN ('alloptions','notoptions') THEN 'core' " .
				"WHEN $proposed = 'blacklist_keys' THEN 'disallowed_keys' " .
				"WHEN $proposed = 'comment_whitelist' THEN 'comment_previously_approved' ";
			if ( 'site' === $scope ) {
				$special .= "WHEN $proposed IN ('blogname','blogdescription','show_on_front','page_on_front','page_for_posts','posts_per_page','permalink_structure') THEN 'site-settings-update' ";
			}
			$special .= "ELSE '' END";
			try {
				$checked = $connection->run(
					'SELECT CAST(CONVERT(proposed.name USING utf8mb4) AS BINARY) AS restored, CHAR_LENGTH(proposed.name) AS characters, (' . $policy . ') AS blocked, ' .
						$special . ' AS special FROM (SELECT ' . $converted . ' AS name) AS proposed',
					array( $name ),
					array(),
					1
				)['rows'][0] ?? null;
			} catch ( Database_Write_Failure $error ) {
				if ( 'database_write_warning' === $error->reason || in_array( $error->details['errno'] ?? 0, array( 1300, 1366 ), true ) ) {
					throw new Database_Write_Failure( 'native_options_write_name_unrepresentable' );
				}
				throw $error;
			}
			if ( ! is_array( $checked ) || ! is_string( $checked['restored'] ) || ! hash_equals( $name, $checked['restored'] ) ||
				! ctype_digit( $checked['characters'] ?? '' ) || (float) $checked['characters'] > (float) $columns[0]['characters'] ) {
				throw new Database_Write_Failure( 'native_options_write_name_unrepresentable' );
			}
			if ( '1' === $checked['blocked'] ) {
				throw new Database_Write_Failure( 'native_options_write_control_state' );
			}
			if ( '' !== $checked['special'] ) {
				throw new Database_Write_Failure(
					'core' === $checked['special'] ? 'native_options_write_core_protected' :
						( 'site-settings-update' === $checked['special'] ? 'native_options_write_specialized_setting' : 'native_options_write_deprecated_name' ),
					array( 'owning_contract' => $checked['special'] )
				);
			}
			$quoted = Database_Connection::quote( $column );
			$where  = $quoted . ' = ' . $converted . ' AND (BINARY ' . $quoted . ' <> BINARY ' . $converted . ' OR ' .
				Database_Control_Storage::name_expression( $quoted ) . ')';
			$params = array( $name, $name );
			if ( 'network' === $scope ) {
				$where   .= ' AND site_id = ?';
				$params[] = (string) $this->identity['network'];
			}
			$aliases = $connection->run( 'SELECT 1 AS blocked FROM ' . $connection->table( $table ) . ' WHERE ' . $where . ' LIMIT 1', $params, array(), 1 )['rows'];
			if ( $aliases ) {
				throw new Database_Write_Failure( 'native_options_write_name_alias' );
			}
			if ( ! $connection->rollback() ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			return array(
				'scope'          => $scope,
				'name'           => $name,
				'blog'           => $this->identity['blog'],
				'network'        => $this->identity['network'],
				'table'          => $table,
				'name_column'    => $column,
				'charset'        => $charset,
				'collation'      => $collation,
				'max_characters' => $columns[0]['characters'],
			);
		} catch ( \Throwable $error ) {
			if ( ! $connection->rollback() ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			throw $error;
		}
	}

	/** @param string $id Stable operation ID. @return string */
	private function reference( $id ) {
		if ( ! is_string( $id ) || strlen( $id ) < 8 || strlen( $id ) > 128 || ! preg_match( '/^[A-Za-z0-9_.:-]+$/D', $id ) ) {
			throw new Database_Write_Failure( 'native_options_write_invalid' );
		}
		return hash(
			'sha256',
			json_encode(
				array(
					Create_Claim_Store::NATIVE_OPTION_FORMAT,
					$this->identity['scope'],
					$this->identity['blog'],
					$this->identity['network'],
					$this->identity['user'],
					base64_encode( $this->identity['client'] ),
					$id,
				)
			)
		);
	}

	/** @param string $reference Hashed identity. @return string */
	private function receipt_name( $reference ) {
		return Create_Claim_Store::NATIVE_OPTION_PREFIX . $reference;
	}

	/** @param string $raw Exact receipt. @param string $reference Key identity. @param string|null $fingerprint Expected request. @param bool $historical Readback. @return array */
	private function result( $raw, $reference, $fingerprint, $historical ) {
		$receipt = json_decode( $raw, true );
		$allowed = array(
			'format',
			'purpose',
			'scope',
			'blog',
			'network',
			'user',
			'client_fingerprint',
			'action',
			'name',
			'fingerprint',
			'created_at',
			'state',
			'outcome',
			'native_return',
			'callback_threw',
			'observed_after_sha256',
			'observed_after_available',
			'observation_error',
			'native_errno',
			'native_sqlstate',
		);
		if ( ! is_array( $receipt ) || array_diff( array_keys( $receipt ), $allowed ) || Create_Claim_Store::NATIVE_OPTION_FORMAT !== ( $receipt['format'] ?? '' ) ||
			Create_Claim_Store::NATIVE_OPTION_PURPOSE !== ( $receipt['purpose'] ?? '' ) ||
			array_key_exists( 'admission_domain', $receipt ) ||
			! in_array( $receipt['state'] ?? '', array( 'fenced', 'completed' ), true ) ||
			! in_array( $receipt['action'] ?? '', array( 'add', 'update', 'delete' ), true ) ||
			! $this->digest( $receipt['fingerprint'] ?? null ) ||
			! is_string( $receipt['name'] ?? null ) || '' === $receipt['name'] ||
			! is_string( $receipt['created_at'] ?? null ) ||
			! is_string( $receipt['client_fingerprint'] ?? null ) ||
			! hash_equals( hash( 'sha256', $this->identity['client'] ), $receipt['client_fingerprint'] ) ) {
			throw new Database_Write_Failure( 'database_write_outcome_unknown' );
		}
		foreach ( array( 'scope', 'blog', 'network', 'user' ) as $field ) {
			if ( ( $receipt[ $field ] ?? null ) !== $this->identity[ $field ] ) {
				throw new Database_Write_Failure( 'native_options_write_context_changed' );
			}
		}
		$this->captured(
			array_intersect_key(
				$receipt,
				array_flip(
					array(
						'outcome',
						'native_return',
						'callback_threw',
						'observed_after_sha256',
						'observed_after_available',
						'observation_error',
						'native_errno',
						'native_sqlstate',
					)
				)
			)
		);
		if ( null !== $fingerprint && ! hash_equals( $fingerprint, $receipt['fingerprint'] ) ) {
			throw new Database_Write_Failure( 'native_options_write_operation_conflict' );
		}
		return array(
			'state'         => $receipt['state'],
			'operation_ref' => $reference,
			'historical'    => $historical,
			'receipt'       => $receipt,
		);
	}

	/** @param array $captured Closed server-owned outcome. @return array */
	private function captured( $captured ) {
		$required = array( 'outcome', 'native_return', 'callback_threw', 'observed_after_sha256', 'observed_after_available', 'observation_error' );
		if ( array_diff( $required, array_keys( $captured ) ) ||
			array_diff( array_keys( $captured ), array_merge( $required, array( 'native_errno', 'native_sqlstate' ) ) ) ||
			! in_array( $captured['outcome'], array( 'not_dispatched', 'native_call_returned', 'unknown' ), true ) ||
			( null !== $captured['native_return'] && ! is_bool( $captured['native_return'] ) ) ||
			! is_bool( $captured['callback_threw'] ) || ! is_bool( $captured['observed_after_available'] ) ||
			( null !== $captured['observed_after_sha256'] && ! $this->digest( $captured['observed_after_sha256'] ) ) ||
			( null !== $captured['observed_after_sha256'] ) !== $captured['observed_after_available'] ||
			! is_string( $captured['observation_error'] ) || ! preg_match( '/^[a-z0-9_]{0,80}$/D', $captured['observation_error'] ) ||
			( array_key_exists( 'native_errno', $captured ) && ( ! is_int( $captured['native_errno'] ) || $captured['native_errno'] < 0 ) ) ||
			( array_key_exists( 'native_sqlstate', $captured ) && ( ! is_string( $captured['native_sqlstate'] ) || ! preg_match( '/^[A-Z0-9]{5}$/D', $captured['native_sqlstate'] ) ) ) ) {
			throw new Database_Write_Failure( 'native_options_write_invalid' );
		}
		return $captured;
	}

	/** @param mixed $value SHA256. @return bool */
	private function digest( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	/** @param array $value Bounded metadata. @return string */
	private function encode( $value ) {
		$json = json_encode( $value );
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > $this->budget ) {
			throw new Database_Write_Failure( 'native_options_write_budget' );
		}
		return $json;
	}

	/** @param \Throwable $error Fixed native failure. @return WP_Error */
	private function failure( $error ) {
		$code = $error instanceof Database_Write_Failure ? $error->reason : 'native_options_write_outcome_unknown';
		$data = array();
		if ( $error instanceof Database_Write_Failure ) {
			foreach ( array( 'errno', 'sqlstate', 'warning_count', 'max_allowed_packet', 'owning_contract' ) as $key ) {
				if ( isset( $error->details[ $key ] ) && ( is_int( $error->details[ $key ] ) || is_string( $error->details[ $key ] ) ) ) {
					$data[ $key ] = $error->details[ $key ];
				}
			}
		}
		if ( 'database_write_unsupported_session' === $code ) {
			$code = 'native_options_write_unsupported_session';
		} elseif ( 0 === strpos( $code, 'database_write_' ) ) {
			$code = 'database_write_outcome_unknown' === $code ? 'native_options_write_outcome_unknown' : 'native_options_write_unsupported_storage';
		}
		return new WP_Error( $code, 'The native Options journal or target check could not be verified.', $data );
	}
}
