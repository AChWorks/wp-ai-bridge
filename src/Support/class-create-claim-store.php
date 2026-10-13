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
}
