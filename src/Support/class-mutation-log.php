<?php
/**
 * Bounded, metadata-only Bridge activity and operational diagnostics.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * One existing per-blog, non-autoload option; never stores request payloads.
 */
final class Mutation_Log {
	const OPTION_NAME    = 'wp_ai_bridge_recent_actions';
	const LIMIT          = 50;
	const RETENTION_DAYS = 30;

	/**
	 * Preserve the existing activity-record contract.
	 *
	 * @param string $ability Ability name.
	 * @param string $target_type Optional target type.
	 * @param int    $target_id Optional target ID.
	 * @param bool   $success Whether mutation succeeded.
	 * @param string $error_code Existing bounded error code.
	 * @return bool Whether the option write was confirmed.
	 */
	public function record( $ability, $target_type = '', $target_id = 0, $success = true, $error_code = '' ) {
		return $this->write(
			array(
				'timestamp'   => gmdate( 'c' ),
				'user_id'     => get_current_user_id(),
				'ability'     => substr( sanitize_text_field( (string) $ability ), 0, 160 ),
				'target_type' => substr( sanitize_key( (string) $target_type ), 0, 64 ),
				'target_id'   => absint( $target_id ),
				'success'     => (bool) $success,
				'error_code'  => substr( sanitize_key( (string) $error_code ), 0, 100 ),
			)
		);
	}

	/**
	 * Log a high-signal private-transfer provider result, not caller data.
	 * Permission callbacks that reject before provider execution are NOT logged:
	 * no unauthenticated probing, no implication that Gateway reached WordPress.
	 * Successful ordinary read/stage operations are omitted except begin.
	 *
	 * @param string $operation Allowlisted session operation.
	 * @param mixed  $result Provider result or WP_Error.
	 * @param string $phase Internal phase, validation or provider.
	 * @return bool True if no persistence was needed or the write was confirmed.
	 */
	public function record_transfer( $operation, $result, $phase = 'provider' ) {
		$operations = array( 'begin', 'append', 'status', 'verify', 'cancel', 'read', 'release', 'admin_release' );
		if ( ! is_string( $operation ) || ! in_array( $operation, $operations, true ) ) {
			return false;
		}
		$success = ! is_wp_error( $result );
		if ( $success && 'begin' !== $operation ) {
			return true;
		}
		$code = '';
		if ( ! $success ) {
			$provided = (string) $result->get_error_code();
			// Static provider-code allowlist. A plugin-supplied WP_Error code
			// may contain sensitive text and must NEVER flow into this option.
			$code = in_array( $provided, self::safe_error_codes(), true ) ? $provided : 'transfer_error_unspecified';
		}
		return $this->write(
			array(
				'timestamp'   => gmdate( 'c' ),
				'user_id'     => get_current_user_id(),
				'ability'     => 'wp-ai-bridge/private-transfer',
				'target_type' => '',
				'target_id'   => 0,
				'success'     => $success,
				'error_code'  => $code,
				'operation'   => $operation,
				'phase'       => 'validation' === $phase ? 'validation' : 'provider',
				'event_ref'   => bin2hex( random_bytes( 8 ) ),
				'event_type'  => 'diagnostic',
			)
		);
	}

	/** @return array<int,string> Canonical provider error-code allowlist. */
	private static function safe_error_codes() {
		return array(
			'transfer_admin_recovery_denied',
			'transfer_cancelled',
			'transfer_capacity',
			'transfer_checksum_mismatch',
			'transfer_cleanup_uncertain',
			'transfer_conflict',
			'transfer_consume_uncertain',
			'transfer_consumed',
			'transfer_denied',
			'transfer_disk_insufficient',
			'transfer_disk_unknown',
			'transfer_expired',
			'transfer_incomplete',
			'transfer_invalid',
			'transfer_message_budget',
			'transfer_native_transformed',
			'transfer_outcome_unknown',
			'transfer_payload_released',
			'transfer_session_busy',
			'transfer_size_setting',
			'transfer_source_changed',
			'transfer_storage_unsafe',
			'transfer_storage_unavailable',
			'transfer_storage_uncertain',
			'source_transfer_target_changed',
			'transfer_error_unspecified',
		);
	}

	/**
	 * @param array<string,mixed> $entry Fixed schema, never raw provider data.
	 * @return bool
	 */
	private function write( $entry ) {
		$entries = $this->retained();
		array_unshift( $entries, $entry );
		return true === update_option( self::OPTION_NAME, array_slice( $entries, 0, self::LIMIT ), false );
	}

	/**
	 * @return array<int,array<string,mixed>> Bounded, age-filtered records.
	 */
	private function retained() {
		$entries = get_option( self::OPTION_NAME, array() );
		$entries = is_array( $entries ) ? array_slice( $entries, 0, self::LIMIT ) : array();
		$cutoff  = time() - self::RETENTION_DAYS * 86400;
		return array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $cutoff ) {
					if ( ! is_array( $entry ) || ! isset( $entry['timestamp'] ) || ! is_string( $entry['timestamp'] ) ) {
						return false;
					}
					// Remote diagnostics must not echo attacker-controlled/freeform
					// option text as a purported UTC timestamp. Existing records
					// were written using gmdate('c') with a fixed +00:00 offset.
					if ( 25 !== strlen( $entry['timestamp'] ) ||
						1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00$/D', $entry['timestamp'] ) ) {
						return false;
					}
					$when = strtotime( $entry['timestamp'] );
					return false !== $when && gmdate( 'c', $when ) === $entry['timestamp'] &&
						$when >= $cutoff && $when <= time() + 86400;
				}
			)
		);
	}

	/**
	 * @param int $limit Maximum entries.
	 * @return array<int,array<string,mixed>> Existing admin activity contract.
	 */
	public function recent( $limit = 10 ) {
		$limit = max( 0, min( absint( $limit ), self::LIMIT ) );
		return array_slice( $this->retained(), 0, $limit );
	}

	/**
	 * Return ONLY fixed, non-sensitive fields from local diagnostic entries.
	 * Deliberately excludes user IDs, legacy target IDs and private sources.
	 *
	 * @param int $limit Number of recent events to return.
	 * @return array<string,mixed> Bounded sanitized diagnostic view.
	 */
	public function diagnostic_view( $limit = 10 ) {
		$limit  = max( 1, min( absint( $limit ), self::LIMIT ) );
		$events = array();
		$total  = 0;
		foreach ( $this->retained() as $entry ) {
			if ( 'diagnostic' !== ( $entry['event_type'] ?? '' ) ||
				'wp-ai-bridge/private-transfer' !== ( $entry['ability'] ?? '' ) ||
				! in_array( $entry['operation'] ?? '', array( 'begin', 'append', 'status', 'verify', 'cancel', 'read', 'release', 'admin_release' ), true ) ||
				! in_array( $entry['phase'] ?? '', array( 'provider', 'validation' ), true ) ||
				! isset( $entry['success'] ) || ! is_bool( $entry['success'] ) ||
				! is_string( $entry['error_code'] ?? null ) ||
				! in_array( $entry['error_code'], self::safe_error_codes(), true ) && '' !== $entry['error_code'] ||
				! is_string( $entry['event_ref'] ?? null ) ||
				1 !== preg_match( '/^[a-f0-9]{16}$/D', (string) ( $entry['event_ref'] ?? '' ) ) ) {
				continue;
			}
			++$total;
			if ( count( $events ) < $limit ) {
				$events[] = array(
					'timestamp'  => (string) $entry['timestamp'],
					'operation'  => (string) $entry['operation'],
					'phase'      => (string) $entry['phase'],
					'success'    => (bool) $entry['success'],
					'error_code' => (string) $entry['error_code'],
					'event_ref'  => (string) $entry['event_ref'],
				);
			}
		}
		return array(
			'events'         => $events,
			'returned'       => count( $events ),
			'has_more'       => $total > count( $events ),
			'retained_limit' => self::LIMIT,
		);
	}
}
