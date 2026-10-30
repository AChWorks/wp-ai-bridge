<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Colocated internal failure carrier is loaded with the one-shot connection boundary.
/**
 * One-shot native database execution on Core's existing connection.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.PHP.NoSilencedErrors -- Native one-shot calls are mandatory: wpdb reconnects/replays. Native diagnostics are caught and redacted.
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound -- The private hook-free failure carrier belongs to this native boundary.


/**
 * Internal failures deliberately avoid WP_Error/hooks while a transaction is owned.
 */
final class Database_Write_Failure extends \RuntimeException {
	/** @var string */
	public $reason;
	/** @var array */
	public $details;
	/** @param string $reason Fixed public code. @param array $details Redacted metadata. */
	public function __construct( $reason, array $details = array() ) {
		parent::__construct( $reason );
		$this->reason  = $reason;
		$this->details = $details;
	}
}

/**
 * Never reconnects, retries, reads credentials or invokes WordPress query filters.
 */
final class Database_Connection {
	/** @var \mysqli */
	private $handle;
	/** @var object */
	private $wpdb;
	/** @var int */
	private $session;
	/** @var bool */
	private $mode_changed = false;
	/** @var bool */
	private $owned = false;
	/** @var bool */
	private static $busy = false;
	/** @var string */
	public $database;
	/** @var string */
	public $prefix;
	/** @var string */
	public $options;
	/** @var bool */
	public $mariadb;
	/** @var string */
	public $version;
	/** @var bool */
	private $schema_lock_owned = false;
	/** @var bool */
	private $quarantined = false;
	/** @var int */
	public $packet_bytes;
	/** @var int */
	private $case_mode;
	/** @var array */
	private $grants = array();
	/** @var array Request-local admitted native SQL statements. */
	private $native_statements = array();

	/** Retain only the existing supported handle. */
	public function __construct() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || 'wpdb' !== get_class( $wpdb ) ||
			( defined( 'WP_CONTENT_DIR' ) && is_file( WP_CONTENT_DIR . '/db.php' ) ) ||
			! ( $wpdb->dbh instanceof \mysqli ) ||
			0 !== strpos( mysqli_get_client_info(), 'mysqlnd' ) ||
			! is_string( $wpdb->base_prefix ) || '' === $wpdb->base_prefix ) {
			throw new Database_Write_Failure( 'database_write_unsupported_connection' );
		}
		$this->wpdb     = $wpdb;
		$this->handle   = $wpdb->dbh;
		$this->session  = mysqli_thread_id( $this->handle );
		$this->prefix   = $wpdb->base_prefix;
		$this->options  = $wpdb->options;
		$rows           = $this->run( 'SELECT DATABASE() AS db, @@SESSION.max_allowed_packet AS packet, VERSION() AS version, @@lower_case_table_names AS case_mode' )['rows'];
		$this->database = $rows[0]['db'] ?? '';
		if ( '' === $this->database || ! isset( $wpdb->dbname ) || $wpdb->dbname !== $this->database ) {
			throw new Database_Write_Failure( 'database_write_unsupported_connection' );
		}
		$this->version      = (string) ( $rows[0]['version'] ?? '' );
		$this->mariadb      = false !== stripos( $this->version, 'MariaDB' );
		$this->packet_bytes = (int) ( $rows[0]['packet'] ?? 0 );
		$this->case_mode    = (int) ( $rows[0]['case_mode'] ?? -1 );
		foreach ( $this->run( 'SHOW GRANTS FOR CURRENT_USER' )['rows'] as $row ) {
			$this->grants[] = (string) reset( $row );
		}
	}


	/** @param string $database Verified native schema. @param string $table Verified table. @return string */
	public static function qualified( $database, $table ) {
		return self::quote( $database ) . '.' . self::quote( $table );
	}

	/**
	 * A positive clean-session probe; it never commits caller-owned work.
	 *
	 * @return true
	 */
	public function assert_clean_session() {
		try {
			$this->begin_callback_journal();
			if ( ! $this->rollback() ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			return true;
		} catch ( \Throwable $error ) {
			$this->rollback();
			throw $error;
		}
	}

	/**
	 * Send one internally generated DDL statement without warning-as-rollback.
	 *
	 * The caller must complete admission and arm its durable journal first.
	 * An acknowledged SQL error is captured, not called a transactional rollback.
	 * No callbacks, reconnect, retries or subsequent inspection happen here.
	 *
	 * @param string $sql Internally generated, qualified DDL.
	 * @return array Native acknowledgement, independent of later reporting.
	 */
	public function dispatch_ddl_once( $sql ) {
		$sent = false;
		$ack  = null;
		set_error_handler(
			static function () {
				return true;
			}
		);
		try {
			global $wpdb;
			if ( self::$busy || $this->owned || $this->mode_changed ||
				$wpdb !== $this->wpdb || $this->wpdb->dbh !== $this->handle ||
				mysqli_thread_id( $this->handle ) !== $this->session ||
				$this->wpdb->base_prefix !== $this->prefix || $this->wpdb->options !== $this->options ||
				! is_string( $sql ) || '' === $sql ) {
				throw new Database_Write_Failure( 'database_write_unsupported_connection' );
			}
			if ( $this->packet_bytes > 0 && strlen( $sql ) >= $this->packet_bytes - 1024 ) {
				throw new Database_Write_Failure( 'database_write_packet_limit', array( 'max_allowed_packet' => $this->packet_bytes ) );
			}
			$sent   = true;
			$result = @mysqli_query( $this->handle, $sql );
			if ( false === $result ) {
				$errno = mysqli_errno( $this->handle );
				return $this->ddl_error( $errno, mysqli_sqlstate( $this->handle ) );
			}
			$ack = array(
				'outcome'       => 'acknowledged',
				'errno'         => 0,
				'sqlstate'      => '00000',
				'warning_count' => mysqli_warning_count( $this->handle ),
				'affected'      => (string) mysqli_affected_rows( $this->handle ),
			);
			if ( $result instanceof \mysqli_result ) {
				mysqli_free_result( $result );
			}
			return $ack;
		} catch ( Database_Write_Failure $error ) {
			if ( ! $sent ) {
				throw $error;
			}
			$this->quarantine();
			return array(
				'outcome'       => 'unknown',
				'errno'         => 0,
				'sqlstate'      => '',
				'warning_count' => 0,
				'affected'      => null,
			);
		} catch ( \mysqli_sql_exception $error ) {
			return $this->ddl_error( (int) $error->getCode(), $error->getSqlState() );
		} catch ( \Throwable $error ) {
			if ( $sent ) {
				$this->quarantine();
			}
			if ( null !== $ack ) {
				$ack['cleanup_pending'] = true;
				return $ack;
			}
			return array(
				'outcome'       => $sent ? 'unknown' : 'not_dispatched',
				'errno'         => 0,
				'sqlstate'      => '',
				'warning_count' => 0,
				'affected'      => null,
			);
		} finally {
			restore_error_handler();
		}
	}

	/** @param int $errno Native numeric diagnostic. @param string $sqlstate Native SQLSTATE. @return array */
	private function ddl_error( $errno, $sqlstate ) {
		$unknown = ( $errno >= 2000 && $errno < 3000 ) || in_array( $errno, array( 0, 4031 ), true );
		if ( $unknown ) {
			$this->quarantine();
		}
		return array(
			'outcome'       => $unknown ? 'unknown' : 'native_error',
			'errno'         => $errno,
			'sqlstate'      => $sqlstate,
			'warning_count' => 0,
			'affected'      => null,
		);
	}


	/**
	 * Prepare exactly one account-authorized statement before dispatch admission.
	 * Native result rows are never buffered as a complete result.
	 *
	 * @param string $sql Complete native statement, not installation confined.
	 * @param array $parameters Exact scalar bytes/NULL.
	 * @param int $metadata_budget Finite metadata allowance.
	 * @param int $memory_ceiling Known finite PHP memory ceiling.
	 * @return array Private token plus pre-dispatch resource evidence.
	 */
	public function prepare_native_sql( $sql, array $parameters, $metadata_budget, $memory_ceiling ) {
		$this->native_sql_capacity( $metadata_budget, $memory_ceiling );
		$statement = null;
		set_error_handler(
			static function () {
				return true;
			}
		);
		try {
			$inline = strlen( $sql ) + 64 * count( $parameters );
			foreach ( $parameters as $parameter ) {
				if ( null !== $parameter && ! is_string( $parameter ) ) {
					throw new Database_Write_Failure( 'database_native_sql_invalid' );
				}
				$inline += null === $parameter ? 0 : strlen( $parameter );
			}
			if ( '' === $sql || $inline >= $this->packet_bytes - 1024 ) {
				throw new Database_Write_Failure( 'database_write_packet_limit', array( 'max_allowed_packet' => $this->packet_bytes ) );
			}
			$statement = @mysqli_prepare( $this->handle, $sql );
			if ( false === $statement ) {
				$this->native_failure();
			}
			if ( mysqli_stmt_param_count( $statement ) !== count( $parameters ) ) {
				throw new Database_Write_Failure( 'database_native_sql_parameters' );
			}
			$values = array_values( $parameters );
			if ( $values && ! @mysqli_stmt_bind_param( $statement, str_repeat( 's', count( $values ) ), ...$values ) ) {
				$this->native_failure( $statement );
			}
			$fields = $this->native_sql_fields( $statement, $metadata_budget );
			$kind   = preg_match( '/^\s*([a-z]+)/i', $sql, $match ) ? strtoupper( $match[1] ) : '';
			if ( ! $fields && ! in_array( $kind, array( 'CREATE', 'ALTER', 'DROP', 'RENAME', 'TRUNCATE', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'SET', 'DO', 'GRANT', 'REVOKE', 'COMMIT', 'ROLLBACK', 'BEGIN', 'START', 'LOCK', 'UNLOCK', 'USE', 'FLUSH', 'RESET', 'KILL', 'INSTALL', 'UNINSTALL', 'WITH' ), true ) ) {
				throw new Database_Write_Failure( 'database_native_sql_metadata_unavailable', array( 'compatibility' => 'unknown_preexecute_result_shape' ) );
			}
			$row_bound = $this->native_sql_row_bound( $fields, ! empty( $parameters ) );
			$this->native_sql_capacity( $metadata_budget, $memory_ceiling, $row_bound );
			$token                             = bin2hex( random_bytes( 32 ) );
			$this->native_statements[ $token ] = array(
				'statement'       => $statement,
				'parameters'      => $values,
				'fields'          => $fields,
				'metadata_budget' => $metadata_budget,
				'memory_ceiling'  => $memory_ceiling,
				'row_bound'       => $row_bound,
			);
			$statement                         = null;
			return array(
				'token'                => $token,
				'field_count'          => count( $fields ),
				'max_allowed_packet'   => $this->packet_bytes,
				'row_bound_bytes'      => $row_bound,
				'required_headroom'    => $this->native_sql_capacity( $metadata_budget, $memory_ceiling, $row_bound ),
				'minimum_result_bytes' => strlen(
					$this->native_sql_frame(
						array(
							'format' => 'wpai-native-sql-result-v1',
							'fields' => $fields,
						)
					)
				) + 128,
			);
		} catch ( Database_Write_Failure $error ) {
			throw $error;
		} catch ( \mysqli_sql_exception $error ) {
			throw new Database_Write_Failure(
				'database_native_sql_prepare_failed',
				array(
					'errno'    => (int) $error->getCode(),
					'sqlstate' => $error->getSqlState(),
				)
			);
		} catch ( \Throwable $error ) {
			throw new Database_Write_Failure( 'database_native_sql_prepare_failed' );
		} finally {
			if ( $statement instanceof \mysqli_stmt ) {
				@mysqli_stmt_close( $statement );
			}
			restore_error_handler();
		}
	}

	/** @param string $token This request's private native statement. @return void */
	public function cancel_native_sql( $token ) {
		if ( isset( $this->native_statements[ $token ] ) ) {
			set_error_handler(
				static function () {
					return true;
				}
			);
			try {
				@mysqli_stmt_close( $this->native_statements[ $token ]['statement'] );
				unset( $this->native_statements[ $token ] );
			} finally {
				restore_error_handler();
			}
		}
	}

	/**
	 * Execute once and stream exact typed row frames to an admitted private sink.
	 * Only one packet-sized row is live; FLOAT/DOUBLE retain IEEE bytes rather
	 * than losing precision through PHP's decimal string conversion.
	 *
	 * @param string $token Private admitted statement.
	 * @param Administration_Transfer_Writer $writer Already reserved raw sink.
	 * @return array Captured acknowledgement and independent result completeness.
	 */
	public function stream_native_sql_once( $token, Administration_Transfer_Writer $writer ) {
		$owned = $this->native_statements[ $token ] ?? null;
		if ( ! is_array( $owned ) ) {
			throw new Database_Write_Failure( 'database_native_sql_invalid' );
		}
		$this->native_sql_capacity( $owned['metadata_budget'], $owned['memory_ceiling'], $owned['row_bound'] );
		unset( $this->native_statements[ $token ] );
		$statement = $owned['statement'];
		$sent      = false;
		$ack       = null;
		$rows      = '0';
		$complete  = false;
		$failure   = '';
		set_error_handler(
			static function () {
				return true;
			}
		);
		try {
			global $wpdb;
			if ( self::$busy || $this->owned || $this->mode_changed || $wpdb !== $this->wpdb ||
				$this->wpdb->dbh !== $this->handle || mysqli_thread_id( $this->handle ) !== $this->session ) {
				throw new Database_Write_Failure( 'database_write_unsupported_connection' );
			}
			$sent = true;
			if ( ! @mysqli_stmt_execute( $statement ) ) {
				$ack = $this->ddl_error( mysqli_stmt_errno( $statement ), mysqli_stmt_sqlstate( $statement ) );
			} else {
				$ack    = array(
					'outcome'       => 'acknowledged',
					'errno'         => 0,
					'sqlstate'      => '00000',
					'warning_count' => mysqli_warning_count( $this->handle ),
					'affected'      => (string) mysqli_stmt_affected_rows( $statement ),
					'insert_id'     => (string) mysqli_stmt_insert_id( $statement ),
				);
				$fields = $this->native_sql_fields( $statement, $owned['metadata_budget'] );
				if ( count( $fields ) !== count( $owned['fields'] ) ||
					$this->native_sql_row_bound( $fields, ! empty( $owned['parameters'] ) ) > $owned['row_bound'] ) {
					throw new Database_Write_Failure( 'database_native_sql_metadata_changed' );
				}
				$this->native_sql_append(
					$writer,
					array(
						'format' => 'wpai-native-sql-result-v1',
						'fields' => $fields,
					)
				);
				if ( $fields ) {
					$values = array_fill( 0, count( $fields ), null );
					if ( ! @mysqli_stmt_bind_result( $statement, ...$values ) ) {
						throw new Database_Write_Failure( 'database_native_sql_result_incomplete' );
					}
					while ( true ) {
						$fetched = @mysqli_stmt_fetch( $statement );
						if ( null === $fetched ) {
							break;
						}
						if ( false === $fetched ) {
							throw new Database_Write_Failure(
								'database_native_sql_result_incomplete',
								array(
									'errno'    => mysqli_stmt_errno( $statement ),
									'sqlstate' => mysqli_stmt_sqlstate( $statement ),
								)
							);
						}
						$row_bytes = 0;
						foreach ( $values as $value ) {
							$row_bytes += is_string( $value ) ? strlen( $value ) : 32;
						}
						if ( $row_bytes > $owned['row_bound'] + 32 * count( $fields ) ) {
							throw new Database_Write_Failure( 'database_native_sql_result_packet' );
						}
						foreach ( $values as $index => $value ) {
							$representation = 'native_scalar_bytes';
							if ( null !== $value && in_array( $fields[ $index ]['type'], array( 4, 5 ), true ) ) {
								$representation = 4 === $fields[ $index ]['type'] ? 'ieee754_binary32_be' : 'ieee754_binary64_be';
								$value          = pack( 4 === $fields[ $index ]['type'] ? 'G' : 'E', (float) $value );
							} elseif ( null !== $value ) {
								$value = (string) $value;
							}
							$length = null === $value ? 0 : strlen( $value );
							$sha    = null === $value ? null : hash( 'sha256', $value );
							$offset = 0;
							$window = max( 1, min( 49152, intdiv( $owned['metadata_budget'], 4 ) ) );
							do {
								$chunk = null === $value ? '' : substr( $value, $offset, $window );
								$this->native_sql_append(
									$writer,
									array(
										'row'            => $rows,
										'column'         => $index,
										'is_null'        => null === $value,
										'representation' => $representation,
										'encoding'       => 'base64',
										'bytes'          => $length,
										'offset'         => $offset,
										'data'           => base64_encode( $chunk ),
										'sha256'         => $sha,
									)
								);
								$offset += strlen( $chunk );
							} while ( $offset < $length );
						}
						$rows = $this->native_sql_increment( $rows );
					}
				}
				if ( mysqli_stmt_more_results( $statement ) ) {
					throw new Database_Write_Failure( 'database_native_sql_multiple_results' );
				}
				$this->native_sql_append(
					$writer,
					array(
						'rows'     => $rows,
						'complete' => true,
					)
				);
				$complete = true;
			}
		} catch ( \mysqli_sql_exception $error ) {
			if ( null === $ack ) {
				$ack = $this->ddl_error( (int) $error->getCode(), $error->getSqlState() );
			} else {
				$failure = 'database_native_sql_result_incomplete';
			}
		} catch ( \Throwable $error ) {
			$failure = $error instanceof Database_Write_Failure ? $error->reason : 'database_native_sql_result_incomplete';
			if ( null === $ack ) {
				$ack = array(
					'outcome'       => $sent ? 'unknown' : 'not_dispatched',
					'errno'         => 0,
					'sqlstate'      => '',
					'warning_count' => 0,
					'affected'      => null,
				);
			}
		} finally {
			try {
				if ( $complete || ( null !== $ack && 'native_error' === $ack['outcome'] ) ) {
					@mysqli_stmt_close( $statement );
				} elseif ( $sent ) {
					$this->quarantine();
				} else {
					@mysqli_stmt_close( $statement );
				}
			} catch ( \Throwable $error ) {
				$this->quarantine();
				$failure = 'database_native_sql_result_cleanup';
			} finally {
				restore_error_handler();
			}
		}
		return array(
			'native'          => $ack,
			'result_complete' => $complete,
			'rows'            => $rows,
			'result_error'    => $failure,
		);
	}

	/** @param int $budget Metadata allowance. @param int $ceiling Finite known memory ceiling. @param int $row_bound Admitted native row bytes. @return int */
	private function native_sql_capacity( $budget, $ceiling, $row_bound = 0 ) {
		if ( ! is_int( $budget ) || $budget < 1 || ! is_int( $ceiling ) || $ceiling < 1 ||
			$this->packet_bytes < 1024 || ! is_int( $row_bound ) || $row_bound < 0 || $row_bound > intdiv( PHP_INT_MAX, 8 ) ||
			$budget > intdiv( PHP_INT_MAX, 16 ) ) {
			throw new Database_Write_Failure( 'database_native_sql_resource_proof' );
		}
		$required = 4 * $row_bound + 8 * $budget;
		if ( $required > $ceiling || memory_get_usage( true ) > $ceiling - $required ) {
			throw new Database_Write_Failure(
				'database_native_sql_resource_proof',
				array(
					'required_headroom'  => $required,
					'memory_ceiling'     => $ceiling,
					'max_allowed_packet' => $this->packet_bytes,
				)
			);
		}
		return $required;
	}


	/** @param array $fields Native prepared metadata. @param bool $parameters Parameter-sensitive metadata needs native packet fallback. @return int */
	private function native_sql_row_bound( $fields, $parameters ) {
		$bound = 0;
		foreach ( $fields as $field ) {
			$length = $field['declared_bytes'];
			if ( ! ctype_digit( $length ) || ( '0' === $length && 6 !== $field['type'] ) || $parameters ) {
				return $this->packet_bytes;
			}
			$bound += min( $this->packet_bytes, (int) $length );
			if ( $bound >= $this->packet_bytes ) {
				return $this->packet_bytes;
			}
		}
		return $bound;
	}

	/** @param \mysqli_stmt $statement Native statement. @param int $budget Finite metadata allowance. @return array */
	private function native_sql_fields( $statement, $budget ) {
		$count = mysqli_stmt_field_count( $statement );
		if ( $count > intdiv( $budget, 1024 ) ) {
			throw new Database_Write_Failure( 'database_native_sql_metadata_budget' );
		}
		if ( 0 === $count ) {
			return array();
		}
		$metadata = mysqli_stmt_result_metadata( $statement );
		if ( ! $metadata instanceof \mysqli_result ) {
			throw new Database_Write_Failure( 'database_native_sql_metadata_unavailable' );
		}
		try {
			$fields = array();
			foreach ( mysqli_fetch_fields( $metadata ) as $index => $field ) {
				$fields[] = array(
					'ordinal'        => $index,
					'name_base64'    => base64_encode( $field->name ),
					'type'           => $field->type,
					'flags'          => $field->flags,
					'charset'        => $field->charsetnr,
					'declared_bytes' => (string) $field->length,
					'decimals'       => $field->decimals,
				);
			}
			if ( strlen( $this->native_sql_frame( $fields ) ) > $budget ) {
				throw new Database_Write_Failure( 'database_native_sql_metadata_budget' );
			}
			return $fields;
		} finally {
			mysqli_free_result( $metadata );
		}
	}

	/** @param Administration_Transfer_Writer $writer Reserved raw sink. @param array $frame Exact typed frame. @return void */
	private function native_sql_append( $writer, $frame ) {
		$bytes = $this->native_sql_frame( $frame );
		if ( strlen( $bytes ) > $writer->remaining() || ! $writer->append_native( $bytes ) ) {
			throw new Database_Write_Failure( 'database_native_sql_result_capacity' );
		}
	}

	/** @param array $frame Typed inert frame. @return string */
	private function native_sql_frame( $frame ) {
		$encoded = json_encode( $frame );
		if ( ! is_string( $encoded ) ) {
			throw new Database_Write_Failure( 'database_native_sql_result_encoding' );
		}
		return $encoded . "\n";
	}

	/** @param string $value Nonnegative exact decimal. @return string */
	private function native_sql_increment( $value ) {
		for ( $index = strlen( $value ) - 1; $index >= 0; --$index ) {
			if ( '9' !== $value[ $index ] ) {
				$value[ $index ] = chr( ord( $value[ $index ] ) + 1 );
				return $value;
			}
			$value[ $index ] = '0';
		}
		return '1' . $value;
	}

	/** Discard a native-account session whose state/control namespace changed. */
	public function abandon_native_sql_session() {
		set_error_handler(
			static function () {
				return true;
			}
		);
		try {
			$this->quarantine();
		} finally {
			restore_error_handler();
		}
	}


	/**
	 * Acquire only our own lock after clean-session admission and durable arming.
	 * This primitive is used solely by version-qualified rename fencing.
	 *
	 * @param string $table Verified installation table.
	 * @return array Native acknowledgement.
	 */
	public function acquire_schema_write_lock( $table ) {
		if ( $this->schema_lock_owned || ! is_string( $table ) || 0 !== strpos( $table, $this->prefix ) ) {
			throw new Database_Write_Failure( 'database_write_unsupported_session' );
		}
		$ack = $this->dispatch_ddl_once( 'LOCK TABLES ' . $this->table( $table ) . ' WRITE' );
		if ( 'acknowledged' === $ack['outcome'] ) {
			$this->schema_lock_owned = true;
		}
		return $ack;
	}

	/** @return array Release only an acknowledged lock created by this instance. */
	public function release_schema_write_lock() {
		if ( ! $this->schema_lock_owned ) {
			throw new Database_Write_Failure( 'database_write_unsupported_session' );
		}
		$ack                     = $this->dispatch_ddl_once( 'UNLOCK TABLES' );
		$this->schema_lock_owned = false;
		if ( 'acknowledged' !== $ack['outcome'] ) {
			$this->quarantine();
		}
		return $ack;
	}

	/** @param string $identifier Verified native identifier. @return string */
	public static function quote( $identifier ) {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/** @param string $table Exact table. @return string */
	public function table( $table ) {
		return self::quote( $this->database ) . '.' . self::quote( $table );
	}

	/**
	 * Direct explicit grants are a conservative visibility proof. Role-only or
	 * partial-revocation configurations are not guessed from an empty listing.
	 *
	 * @param string $privilege Native privilege.
	 * @param string $table Exact table.
	 * @return bool
	 */
	public function has_privilege( $privilege, $table ) {
		foreach ( $this->grants as $grant ) {
			if ( 0 === stripos( $grant, 'REVOKE ' ) ) {
				return false;
			}
		}
		$escaped_database = str_replace( array( '\\', '_', '%' ), array( '\\\\', '\\_', '\\%' ), $this->database );
		$objects          = array( '*.*', self::quote( $this->database ) . '.*', self::quote( $escaped_database ) . '.*', $this->table( $table ) );
		foreach ( $this->grants as $grant ) {
			if ( preg_match( '/^GRANT (.+) ON (.+) TO /iD', $grant, $match ) &&
				in_array( $match[2], $objects, true ) ) {
				$privileges = explode( ', ', strtoupper( $match[1] ) );
				if ( in_array( 'ALL PRIVILEGES', $privileges, true ) || in_array( $privilege, $privileges, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Resolve the dictionary identity before testing the FK graph. MariaDB's
	 * native filename charset includes compact letter encodings, not just hex.
	 * innodb_convert_name() applies that conversion independently to both names.
	 *
	 * @param string $table Pinned native table.
	 * @return string Verified native foreign-key parent name.
	 */
	public function foreign_reference( $table ) {
		if ( 0 !== $this->case_mode ||
			0 === strpos( $this->database, '#mysql50#' ) || 0 === strpos( $table, '#mysql50#' ) ) {
			throw new Database_Write_Failure(
				'database_write_fk_identity',
				array(
					'identity_reason'        => 'unverified_case_or_legacy_mode',
					'lower_case_table_names' => $this->case_mode,
				)
			);
		}
		if ( ! $this->mariadb ) {
			return $this->database . '/' . $table;
		}
		$fields = array();
		$params = array();
		foreach ( array( $this->database, $table ) as $index => $name ) {
			$filename = 'CONVERT(CONVERT(CAST(? AS BINARY) USING utf8mb4) USING filename)';
			$fields[] = 'CAST(' . $filename . ' AS BINARY) AS encoded' . $index;
			$fields[] = 'CAST(CONVERT(' . $filename . ' USING utf8mb4) AS BINARY) AS restored' . $index;
			$params[] = $name;
			$params[] = $name;
		}
		try {
			$rows = $this->run( 'SELECT ' . implode( ', ', $fields ), $params, array(), 1 )['rows'];
		} catch ( Database_Write_Failure $error ) {
			throw new Database_Write_Failure( 'database_write_fk_identity', array( 'identity_reason' => 'native_filename_conversion_unavailable' ) );
		}
		$parts = array();
		foreach ( array( $this->database, $table ) as $index => $name ) {
			$encoded  = $rows[0][ 'encoded' . $index ] ?? null;
			$restored = $rows[0][ 'restored' . $index ] ?? null;
			if ( ! is_string( $encoded ) || ! preg_match( '/^[A-Za-z0-9_@]+$/D', $encoded ) ||
				! is_string( $restored ) || ! hash_equals( $name, $restored ) ) {
				throw new Database_Write_Failure( 'database_write_fk_identity', array( 'identity_reason' => 'filename_roundtrip_failed' ) );
			}
			$parts[] = $encoded;
		}
		$reference = implode( '/', $parts );
		// Positive dictionary resolution is mandatory: a failed conversion,
		// legacy or partition representation must not become "no foreign keys".
		$tables = $this->run(
			'SELECT TABLE_ID AS id, NAME AS name FROM information_schema.INNODB_SYS_TABLES WHERE BINARY NAME = BINARY ?',
			array( $reference ),
			array(),
			2
		)['rows'];
		if ( count( $tables ) !== 1 || ! is_string( $tables[0]['name'] ) ||
			! hash_equals( $reference, $tables[0]['name'] ) || ! ctype_digit( $tables[0]['id'] ) ) {
			throw new Database_Write_Failure(
				'database_write_fk_identity',
				array(
					'identity_reason'        => 'dictionary_identity_unresolved',
					'lower_case_table_names' => $this->case_mode,
				)
			);
		}
		return $reference;
	}

	/**
	 * Guard active transactions without START's implicit unlock. SET0 preserves
	 * prior SQL table locks; native failure for an unlocked table is harmless.
	 * Only this fixed critical section may subsequently commit/restore the mode.
	 */
	public function begin() {
		$this->begin_owned( false );
	}

	/** Enter a short native journal with no inherited SQL table locks. */
	public function begin_callback_journal() {
		$this->assert_callback_context();
		$this->begin_owned( true );
	}

	/** Verify unqualified native APIs still resolve in the captured database. */
	public function assert_callback_context() {
		$rows = $this->run( 'SELECT DATABASE() AS db', array(), array(), 1 )['rows'];
		if ( count( $rows ) !== 1 || ! is_string( $rows[0]['db'] ?? null ) || ! hash_equals( $this->database, $rows[0]['db'] ) ) {
			throw new Database_Write_Failure( 'native_options_write_context_changed' );
		}
	}

	/** @param bool $callback_clean Require positive unlocked-table admission. @return void */
	private function begin_owned( $callback_clean ) {
		$alias = $callback_clean ? 'wpai_native_guard_' . bin2hex( random_bytes( 12 ) ) : '';
		if ( self::$busy ) {
			throw new Database_Write_Failure( 'database_write_unsupported_session' );
		}
		$row = $this->run( 'SELECT @@SESSION.autocommit AS ac, @@SESSION.foreign_key_checks AS fk, @@SESSION.unique_checks AS uq' )['rows'][0];
		if ( '1' !== $row['ac'] || '1' !== $row['fk'] || '1' !== $row['uq'] ) {
			throw new Database_Write_Failure( 'database_write_unsupported_session' );
		}
		// Unqualified SET fails with1568 even for an explicit empty transaction.
		// There is no callback or SQL between the guard and SET0.
		try {
			$this->run( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' );
		} catch ( Database_Write_Failure $error ) {
			if ( 1568 === ( $error->details['errno'] ?? 0 ) ) {
				throw new Database_Write_Failure( 'database_write_unsupported_session', $error->details );
			}
			throw $error;
		}
		if ( $callback_clean ) {
			$opened = false;
			try {
				$this->run( 'HANDLER ' . $this->table( $this->options ) . ' OPEN AS ' . self::quote( $alias ) );
				$opened = true;
				$this->run( 'HANDLER ' . self::quote( $alias ) . ' CLOSE' );
				$opened = false;
			} catch ( \Throwable $error ) {
				// Never close a caller's locks/handler on rejected OPEN. An
				// acknowledged owned OPEN with uncertain CLOSE is unusable.
				if ( $opened ) {
					$this->quarantine();
				}
				if ( $error instanceof Database_Write_Failure && 1192 === ( $error->details['errno'] ?? 0 ) ) {
					throw new Database_Write_Failure( 'database_write_unsupported_session', $error->details );
				}
				throw $error;
			}
		}
		self::$busy         = true;
		$this->mode_changed = true;
		$this->run( 'SET autocommit = 0' );
		$this->owned = true;
	}

	/**
	 * Commit exactly once. A restoration failure cannot undo an acknowledged
	 * commit; quarantine the handle and report committed/reporting pending.
	 *
	 * @return bool Whether session restoration was acknowledged.
	 */
	public function commit() {
		if ( ! $this->owned ) {
			throw new Database_Write_Failure( 'database_write_unsupported_session' );
		}
		$this->run( 'COMMIT AND NO CHAIN NO RELEASE' );
		$this->owned = false;
		try {
			$this->run( 'SET autocommit = 1' );
			$this->mode_changed = false;
			self::$busy         = false;
			return true;
		} catch ( \Throwable $error ) {
			$this->quarantine();
			return false;
		}
	}

	/**
	 * Never SET1 until our rollback is acknowledged. An uncertain COMMIT remains
	 * uncertain even when this cleanup succeeds. No cleanup statement is retried.
	 *
	 * @return bool
	 */
	public function rollback() {
		if ( ! $this->mode_changed ) {
			return true;
		}
		try {
			$this->run( 'ROLLBACK AND NO CHAIN NO RELEASE' );
			$this->owned = false;
			$this->run( 'SET autocommit = 1' );
			$this->mode_changed = false;
			self::$busy         = false;
			return true;
		} catch ( \Throwable $error ) {
			$this->quarantine();
			return false;
		}
	}

	/** End use of a handle whose transaction/session state cannot be confirmed. */
	private function quarantine() {
		if ( $this->quarantined ) {
			return;
		}
		$this->quarantined       = true;
		$this->schema_lock_owned = false;
		try {
			@mysqli_close( $this->handle );
		} catch ( \Throwable $error ) {
			// No reconnect, ping, compensating DML or second close.
		}
		if ( $this->wpdb->dbh === $this->handle ) {
			// Core query() checks ready before touching the native handle. Do not
			// leave an already-closed mysqli available to outer request callbacks.
			$this->wpdb->ready = false;
			$this->wpdb->dbh   = null;
		}
		$this->owned        = false;
		$this->mode_changed = false;
		self::$busy         = false;
	}

	/**
	 * Execute once, capturing diagnostics before any subsequent statement.
	 * Sources are verified stream records provided before the critical section.
	 *
	 * @param string $sql Internally generated SQL only.
	 * @param array $parameters Scalar bytes/NULL or internal source descriptors.
	 * @param array $sources Verified sources.
	 * @param int $max_rows Finite expected result bound.
	 * @return array
	 */
	public function run( $sql, array $parameters = array(), array $sources = array(), $max_rows = 4096 ) {
		$statement = null;
		$result    = null;
		// @ does not stop PHP from dispatching an installed application error
		// handler. Native mysqli modes can emit data-bearing E_WARNING instead
		// of exceptions; source I/O and result cleanup can also warn. Keep the
		// fixed internal handler through cleanup, then restore the caller stack.
		// Do not change the caller's process-wide mysqli reporting mode.
		set_error_handler(
			static function () {
				return true;
			}
		);
		try {
			global $wpdb;
			if ( $wpdb !== $this->wpdb || $this->wpdb->base_prefix !== $this->prefix || $this->wpdb->options !== $this->options ||
				$this->wpdb->dbh !== $this->handle || mysqli_thread_id( $this->handle ) !== $this->session ) {
				throw new Database_Write_Failure( 'database_write_unsupported_connection' );
			}
			$inline_bytes = strlen( $sql ) + 64 * count( $parameters );
			foreach ( $parameters as $parameter ) {
				if ( ! is_array( $parameter ) && null !== $parameter ) {
					$inline_bytes += strlen( $parameter );
				}
			}
			if ( $this->packet_bytes > 0 && $inline_bytes >= $this->packet_bytes - 1024 ) {
				throw new Database_Write_Failure( 'database_write_packet_limit', array( 'max_allowed_packet' => $this->packet_bytes ) );
			}
			if ( $parameters ) {
				$statement = @mysqli_prepare( $this->handle, $sql );
				if ( false === $statement ) {
					$this->native_failure();
				}
				$types  = '';
				$values = array();
				foreach ( $parameters as $parameter ) {
					$types   .= is_array( $parameter ) ? 'b' : 's';
					$values[] = is_array( $parameter ) ? null : $parameter;
				}
				if ( ! @mysqli_stmt_bind_param( $statement, $types, ...$values ) ) {
					$this->native_failure( $statement );
				}
				foreach ( $parameters as $index => $parameter ) {
					if ( ! is_array( $parameter ) ) {
						continue;
					}
					$source = $sources[ $parameter['source'] ] ?? null;
					if ( is_array( $source ) && $source['bytes'] >= $this->packet_bytes - 1024 ) {
						throw new Database_Write_Failure( 'database_write_packet_limit', array( 'max_allowed_packet' => $this->packet_bytes ) );
					}
					$source_offset = is_array( $source ) ? ( $source['offset'] ?? 0 ) : 0;
					$container     = is_array( $source ) ? ( $source['container_bytes'] ?? $source['bytes'] ) : null;
					if ( ! is_array( $source ) || ! is_resource( $source['stream'] ) ||
						! is_int( $source['bytes'] ) || $source['bytes'] < 0 ||
						! is_int( $source_offset ) || $source_offset < 0 ||
						! is_int( $container ) || $container < $source['bytes'] ||
						$source_offset > $container - $source['bytes'] ||
						( $source_offset > 0 && ( fstat( $source['stream'] )['size'] ?? null ) !== $container ) ||
						0 !== fseek( $source['stream'], $source_offset ) ) {
						throw new Database_Write_Failure( 'database_write_source_unavailable' );
					}
					$sent = 0;
					while ( $sent < $source['bytes'] ) {
						$chunk = fread( $source['stream'], min( 65536, max( 1, $this->packet_bytes - 1024 ), $source['bytes'] - $sent ) );
						if ( false === $chunk || '' === $chunk ||
							! @mysqli_stmt_send_long_data( $statement, $index, $chunk ) ) {
							throw new Database_Write_Failure( 'database_write_outcome_unknown' );
						}
						$sent += strlen( $chunk );
					}
					if ( 0 === $sent && ! @mysqli_stmt_send_long_data( $statement, $index, '' ) ) {
						$this->native_failure( $statement );
					}
				}
				if ( ! @mysqli_stmt_execute( $statement ) ) {
					$this->native_failure( $statement );
				}
				$affected = mysqli_stmt_affected_rows( $statement );
				$insert   = (string) mysqli_stmt_insert_id( $statement );
				$warnings = mysqli_warning_count( $this->handle );
				$result   = mysqli_stmt_get_result( $statement );
			} else {
				$result = @mysqli_query( $this->handle, $sql );
				if ( false === $result ) {
					$this->native_failure();
				}
				$affected = mysqli_affected_rows( $this->handle );
				$insert   = (string) mysqli_insert_id( $this->handle );
				$warnings = mysqli_warning_count( $this->handle );
			}
			if ( $warnings > 0 ) {
				throw new Database_Write_Failure( 'database_write_warning', array( 'warning_count' => $warnings ) );
			}
			$rows = array();
			if ( $result instanceof \mysqli_result ) {
				while ( true ) {
					$row = mysqli_fetch_assoc( $result );
					if ( null === $row || false === $row ) {
						break;
					}
					if ( count( $rows ) >= $max_rows ) {
						throw new Database_Write_Failure( 'database_write_budget' );
					}
					foreach ( $row as &$value ) {
						$value = null === $value ? null : (string) $value;
					}
					unset( $value );
					$rows[] = $row;
				}
			}
			return array(
				'rows'      => $rows,
				'affected'  => $affected,
				'insert_id' => $insert,
			);
		} catch ( Database_Write_Failure $error ) {
			throw $error;
		} catch ( \mysqli_sql_exception $error ) {
			throw new Database_Write_Failure(
				'database_write_query_failed',
				array(
					'errno'    => (int) $error->getCode(),
					'sqlstate' => $error->getSqlState(),
				)
			);
		} catch ( \Throwable $error ) {
			throw new Database_Write_Failure( 'database_write_outcome_unknown' );
		} finally {
			try {
				if ( $result instanceof \mysqli_result ) {
					mysqli_free_result( $result );
				}
				if ( $statement instanceof \mysqli_stmt ) {
					@mysqli_stmt_close( $statement );
				}
			} finally {
				restore_error_handler();
			}
		}
	}

	/** @param \mysqli_stmt|null $statement Native statement. @return never */
	private function native_failure( $statement = null ) {
		throw new Database_Write_Failure(
			'database_write_query_failed',
			array(
				'errno'    => $statement ? mysqli_stmt_errno( $statement ) : mysqli_errno( $this->handle ),
				'sqlstate' => $statement ? mysqli_stmt_sqlstate( $statement ) : mysqli_sqlstate( $this->handle ),
			)
		);
	}
}
