<?php
/**
 * Exactly scoped native-option claim persistence for Bridge-owned creates.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * The only direct SQL involved in durable create receipts. No generalized SQL.
 */
final class Create_Claim_Store {
	/**
	 * Atomically reserve an immutable claim only when option_name is absent.
	 *
	 * WordPress add_option() is NOT a usable exclusivity primitive: Core
	 * performs an ON DUPLICATE KEY UPDATE. A losing worker could therefore
	 * overwrite an in-progress receipt and execute a second create.
	 *
	 * The WordPress options table has a native unique index on option_name.
	 * INSERT IGNORE returns exactly one affected row for the sole winner,
	 * zero for a duplicate, and false for a database error. Never act after
	 * anything except an unequivocal one-row insert.
	 *
	 * @param string $name Canonical site-local hash identity.
	 * @param string $initial Bounded uncommitted JSON receipt.
	 * @return string 'acquired', 'existing', or 'error'.
	 */
	public static function try_claim( $name, $initial ) {
		if ( ! self::valid_name( $name ) || ! is_string( $initial ) ||
			'' === $initial || strlen( $initial ) > 1024 ) {
			return 'error';
		}
		global $wpdb;
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$name,
				$initial,
				'off'
			)
		);
		// WordPress object caches are not authoritative for claim ownership.
		// Invalidate both positive and negative cache after any DB outcome.
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( 1 === $inserted ) {
			return 'acquired';
		}
		return 0 === $inserted ? 'existing' : 'error';
	}


	/**
	 * Atomically admits one claim under the site-local finite capacity.
	 * The dedicated coordinator covers DIFFERENT option names; INSERT IGNORE
	 * continues to protect the uniqueness of one identical operation/key.
	 *
	 * @param string $name Fixed hashed option name.
	 * @param string $initial Initial receipt.
	 * @param string $domain Receipt's immutable admission domain.
	 * @return string acquired|existing|capacity|error
	 */
	public static function try_claim_bounded( $name, $initial, $domain ) {
		$state = is_string( $initial ) ? json_decode( $initial, true ) : null;
		if ( ! self::valid_name( $name ) || ! is_string( $initial ) ||
			'' === $initial || strlen( $initial ) > 1024 ||
			! in_array( $domain, array( Create_Claim::DOMAIN_OPERATION, Create_Claim::DOMAIN_CANONICAL ), true ) ||
			! is_array( $state ) || ( $state['admission_domain'] ?? '' ) !== $domain ) {
			return 'error';
		}
		$lock = Admission_Lock::acquire( 'claims' );
		if ( ! $lock ) {
			return 'error';
		}
		try {
			// Preserve idempotent readback when a second caller has already
			// acquired this same name, even if admission is now at capacity.
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$existing = get_option( $name, false );
			if ( is_string( $existing ) && '' !== $existing ) {
				return 'existing';
			}
			// Count ONLY receipts created in this domain after the
			// introduction of typed admission. Old untyped option receipts
			// remain exact-name durable tombstones/readbacks but can no
			// longer exhaust a different type's logical capacity.
			if ( self::count_domain( $domain ) >= Create_Claim::receipt_limit( $domain ) ) {
				return 'capacity';
			}
			if ( ! $lock->is_owned() ) {
				return 'error';
			}
			$result = self::try_claim( $name, $initial );
			return $lock->is_owned() ? $result : 'error';
		} finally {
			$lock->release();
		}
	}

	/** @param string $name Generated option name. @return bool */
	private static function valid_name( $name ) {
		return is_string( $name ) && 1 === preg_match( '/^wpai_create_claim_[a-f0-9]{64}$/D', $name );
	}
	/**
	 * Replaces one exact, exclusively owned native option receipt using a
	 * database condition on its previous value; safe across PHP workers.
	 *
	 * @param string $name SHA-256-derived claim key.
	 * @param string $before Expected original JSON.
	 * @param string $after Replacement JSON.
	 * @return bool
	 */
	public static function compare_swap( $name, $before, $after ) {
		if ( ! self::valid_name( $name ) ||
			! is_string( $before ) || ! is_string( $after ) ||
			strlen( $before ) > 1024 || strlen( $after ) > 1024 ) {
			return false;
		}
		global $wpdb;
		$changed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $after ),
			array(
				'option_name'  => $name,
				'option_value' => $before,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		if ( 1 !== $changed ) {
			return false;
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$observed = get_option( $name, false );
		return $observed === $after;
	}

	/**
	 * Count immutable *new* operation or canonical-key receipts separately.
	 * Both kinds intentionally retain their original, database-unique option
	 * SHA key to prevent cross-version duplicate effects and preserve old
	 * recovery. Legacy untagged keys are still reserved, but do not consume
	 * either new type's independent quota.
	 *
	 * @param string $domain Fixed receipt domain.
	 * @return int Fail-closed PHP_INT_MAX on a DB error.
	 */
	public static function count_domain( $domain ) {
		if ( ! in_array( $domain, array( Create_Claim::DOMAIN_OPERATION, Create_Claim::DOMAIN_CANONICAL ), true ) ) {
			return PHP_INT_MAX;
		}
		global $wpdb;
		// WordPress bootstrap failures must never guess available receipt
		// capacity. The Settings screen reports usage as unavailable.
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ||
			! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			return PHP_INT_MAX;
		}
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s",
				$wpdb->esc_like( Create_Claim::PREFIX ) . '%',
				'%' . $wpdb->esc_like( '"admission_domain":"' . $domain . '"' ) . '%'
			)
		);
		return is_numeric( $count ) && '' === $wpdb->last_error ? max( 0, (int) $count ) : PHP_INT_MAX;
	}

	/**
	 * Bounded, site-local capacity count. Prefix is constant and escaped.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( Create_Claim::PREFIX ) . '%'
			)
		);
		return is_numeric( $count ) && '' === $wpdb->last_error ? max( 0, (int) $count ) : PHP_INT_MAX;
	}
	/** Native Options journal rows never consume legacy create quotas. */
	const NATIVE_OPTION_PREFIX  = 'wpai_native_option_operation_';
	const NATIVE_OPTION_FORMAT  = 'wpai-native-option-operation-v1';
	const NATIVE_OPTION_PURPOSE = 'native_options_write';

	/**
	 * Reserve once and acknowledge our own commit before any native callback.
	 *
	 * @param Database_Connection $connection Retained Core connection.
	 * @param string $name Fixed native journal identity.
	 * @param string $initial Complete compact purpose-owned receipt.
	 * @param int $budget Metadata processing allowance.
	 * @return array Acquired flag and exact committed/existing receipt bytes.
	 */
	public static function reserve_native_option_operation( Database_Connection $connection, $name, $initial, $budget ) {
		self::native_option_record( $name, $initial, $budget );
		class_exists( Database_Control_Storage::class );
		$commit_submitted = false;
		try {
			$connection->begin_callback_journal();
			( new Database_Control_Storage( $connection, $budget ) )->receipt_schema();
			$existing = self::native_option_read_locked( $connection, $name, $budget );
			if ( null !== $existing ) {
				if ( ! $connection->rollback() ) {
					throw new Database_Write_Failure( 'database_write_outcome_unknown' );
				}
				return array(
					'acquired' => false,
					'record'   => $existing,
				);
			}
			try {
				$connection->run(
					'INSERT INTO ' . $connection->table( $connection->options ) . ' (option_name, option_value, autoload) VALUES (?, ?, ?)',
					array( $name, $initial, 'off' )
				);
			} catch ( Database_Write_Failure $error ) {
				if ( 1062 !== ( $error->details['errno'] ?? 0 ) ) {
					throw $error;
				}
				$existing = self::native_option_read_locked( $connection, $name, $budget );
				if ( null === $existing || ! $connection->rollback() ) {
					throw new Database_Write_Failure( 'database_write_outcome_unknown' );
				}
				return array(
					'acquired' => false,
					'record'   => $existing,
				);
			}
			$stored = self::native_option_read_locked( $connection, $name, $budget );
			if ( ! is_string( $stored ) || ! hash_equals( $initial, $stored ) ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			$commit_submitted = true;
			if ( ! $connection->commit() ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			return array(
				'acquired' => true,
				'record'   => $stored,
			);
		} catch ( \Throwable $error ) {
			self::native_option_failure( $connection, $error, $commit_submitted );
		}
	}

	/**
	 * Read current committed journal bytes without Options API/cache callbacks.
	 *
	 * @param Database_Connection $connection Retained Core connection.
	 * @param string $name Fixed native journal identity.
	 * @param int $budget Metadata processing allowance.
	 * @return string|null
	 */
	public static function read_native_option_operation( Database_Connection $connection, $name, $budget ) {
		self::native_option_name( $name, $budget );
		class_exists( Database_Control_Storage::class );
		try {
			$connection->begin_callback_journal();
			( new Database_Control_Storage( $connection, $budget ) )->receipt_schema();
			$record = self::native_option_read_locked( $connection, $name, $budget );
			if ( ! $connection->rollback() ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			return $record;
		} catch ( \Throwable $error ) {
			self::native_option_failure( $connection, $error, false );
		}
	}

	/**
	 * Acknowledge a fresh empty callback-clean section after final authorization.
	 *
	 * @param Database_Connection $connection Retained Core connection.
	 * @param string $name Fixed native journal identity.
	 * @param string $before This invocation's exact committed fence.
	 * @param int $budget Metadata processing allowance.
	 * @return bool
	 */
	public static function ready_native_option_operation( Database_Connection $connection, $name, $before, $budget ) {
		self::native_option_record( $name, $before, $budget );
		class_exists( Database_Control_Storage::class );
		$commit_submitted = false;
		try {
			$connection->begin_callback_journal();
			( new Database_Control_Storage( $connection, $budget ) )->receipt_schema();
			$record = self::native_option_read_locked( $connection, $name, $budget );
			if ( ! is_string( $record ) || ! hash_equals( $before, $record ) ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			$commit_submitted = true;
			if ( ! $connection->commit() ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			return true;
		} catch ( \Throwable $error ) {
			self::native_option_failure( $connection, $error, $commit_submitted );
		}
	}

	/**
	 * Complete only this exact committed fence; no provider callbacks.
	 *
	 * @param Database_Connection $connection Retained Core connection.
	 * @param string $name Fixed native journal identity.
	 * @param string $before Expected exact committed fence.
	 * @param string $after Bounded purpose-owned result metadata.
	 * @param int $budget Metadata processing allowance.
	 * @return string Exact acknowledged completed receipt.
	 */
	public static function complete_native_option_operation( Database_Connection $connection, $name, $before, $after, $budget ) {
		self::native_option_record( $name, $before, $budget );
		self::native_option_record( $name, $after, $budget );
		class_exists( Database_Control_Storage::class );
		$commit_submitted = false;
		try {
			$connection->begin_callback_journal();
			( new Database_Control_Storage( $connection, $budget ) )->receipt_schema();
			$record = self::native_option_read_locked( $connection, $name, $budget );
			if ( ! is_string( $record ) ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			if ( ! hash_equals( $before, $record ) ) {
				if ( ! hash_equals( $after, $record ) || ! $connection->rollback() ) {
					throw new Database_Write_Failure( 'database_write_outcome_unknown' );
				}
				return $record;
			}
			$updated = $connection->run(
				'UPDATE ' . $connection->table( $connection->options ) . ' SET option_value = ? WHERE option_name = ? AND BINARY option_name = BINARY ? AND BINARY option_value = BINARY ?',
				array( $after, $name, $name, $before )
			);
			$record  = self::native_option_read_locked( $connection, $name, $budget );
			if ( 1 !== $updated['affected'] || ! is_string( $record ) || ! hash_equals( $after, $record ) ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			$commit_submitted = true;
			if ( ! $connection->commit() ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			return $record;
		} catch ( \Throwable $error ) {
			self::native_option_failure( $connection, $error, $commit_submitted );
		}
	}

	/** @param string $name Internal key. @param int $budget Finite allowance. @return void */
	private static function native_option_name( $name, $budget ) {
		if ( ! is_string( $name ) || 1 !== preg_match( '/^wpai_native_option_operation_[a-f0-9]{64}$/D', $name ) ||
			! is_int( $budget ) || $budget < 1 || $budget >= PHP_INT_MAX ) {
			throw new Database_Write_Failure( 'native_options_write_invalid' );
		}
	}

	/** @param string $name Internal key. @param string $record Receipt. @param int $budget Finite allowance. @return void */
	private static function native_option_record( $name, $record, $budget ) {
		self::native_option_name( $name, $budget );
		if ( ! is_string( $record ) || '' === $record || strlen( $record ) > $budget ) {
			throw new Database_Write_Failure( 'native_options_write_budget' );
		}
		$value = json_decode( $record, true );
		if ( ! is_array( $value ) || self::NATIVE_OPTION_FORMAT !== ( $value['format'] ?? '' ) ||
			self::NATIVE_OPTION_PURPOSE !== ( $value['purpose'] ?? '' ) ||
			array_key_exists( 'admission_domain', $value ) ||
			! in_array( $value['state'] ?? '', array( 'fenced', 'completed' ), true ) ) {
			throw new Database_Write_Failure( 'native_options_write_invalid' );
		}
	}

	/** @param Database_Connection $connection Owned transaction. @param string $name Key. @param int $budget Bound. @return string|null */
	private static function native_option_read_locked( Database_Connection $connection, $name, $budget ) {
		$rows = $connection->run(
			'SELECT option_name AS name, OCTET_LENGTH(option_value) AS bytes, SUBSTRING(CAST(option_value AS BINARY),1,?) AS value FROM ' .
				$connection->table( $connection->options ) . ' WHERE option_name = ? LIMIT 2 FOR UPDATE',
			array( (string) ( $budget + 1 ), $name ),
			array(),
			2
		)['rows'];
		if ( ! $rows ) {
			return null;
		}
		if ( 1 !== count( $rows ) || ! is_string( $rows[0]['name'] ) || ! hash_equals( $name, $rows[0]['name'] ) ||
			! ctype_digit( $rows[0]['bytes'] ?? '' ) || (float) $rows[0]['bytes'] > $budget ) {
			throw new Database_Write_Failure( 'database_write_outcome_unknown' );
		}
		$record = $rows[0]['value'];
		self::native_option_record( $name, $record, $budget );
		return $record;
	}

	/** @param Database_Connection $connection Journal only. @param \Throwable $error Failure. @param bool $commit_submitted Commit uncertain. @return never */
	private static function native_option_failure( Database_Connection $connection, $error, $commit_submitted ) {
		$restored = $connection->rollback();
		if ( $commit_submitted || ! $restored ) {
			throw new Database_Write_Failure( 'database_write_outcome_unknown' );
		}
		throw $error;
	}
}
