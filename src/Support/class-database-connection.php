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
	/** @var int */
	public $packet_bytes;
	/** @var int */
	private $case_mode;
	/** @var array */
	private $grants = array();

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
		$this->mariadb      = false !== stripos( $rows[0]['version'] ?? '', 'MariaDB' );
		$this->packet_bytes = (int) ( $rows[0]['packet'] ?? 0 );
		$this->case_mode    = (int) ( $rows[0]['case_mode'] ?? -1 );
		foreach ( $this->run( 'SHOW GRANTS FOR CURRENT_USER' )['rows'] as $row ) {
			$this->grants[] = (string) reset( $row );
		}
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
					if ( ! is_array( $source ) || ! is_resource( $source['stream'] ) || 0 !== fseek( $source['stream'], 0 ) ) {
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
