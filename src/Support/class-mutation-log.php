<?php
/**
 * Bounded, metadata-only Bridge activity and operational diagnostics.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;

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
		$entry = array(
			'timestamp'   => gmdate( 'c' ),
			'user_id'     => get_current_user_id(),
			'ability'     => substr( sanitize_text_field( (string) $ability ), 0, 160 ),
			'target_type' => substr( sanitize_key( (string) $target_type ), 0, 64 ),
			'target_id'   => absint( $target_id ),
			'success'     => (bool) $success,
			'error_code'  => Error_Details::from_wp_error( new \WP_Error( (string) $error_code, '' ) )['code'] ?? '',
		);
		if ( in_array( $ability, array( 'wp-ai-bridge/database-read', 'wp-ai-bridge/database-write', 'wp-ai-bridge/native-options-read' ), true ) ) {
			$entry['client_fingerprint'] = substr( hash( 'sha256', OAuth_Server::authenticated_mcp_client_id() ), 0, 16 );
			$entry['event_ref']          = bin2hex( random_bytes( 8 ) );
		}
		// Reuse the existing bounded activity option for a few fixed, high-signal
		// Media staging denials. No caller data, paths or filenames are recorded.
		if ( 'wp-ai-bridge/media-upload' === $ability && ! $success &&
			in_array( $error_code, array( 'media_temp_failed', 'media_temp_write_failed' ), true ) ) {
			$entry['event_type'] = 'diagnostic';
			$entry['operation']  = 'media-upload';
			$entry['phase']      = 'provider';
			$entry['event_ref']  = bin2hex( random_bytes( 8 ) );
		}
		return $this->write( $entry );
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
		$code    = '';
		$reason  = '';
		$details = null;
		if ( ! $success ) {
			$details = Error_Details::from_wp_error( $result );
			$code    = $details['code'] ?? '';
			if ( 'transfer_storage_unsafe' === $code ) {
				$data            = $result->get_error_data();
				$provided_reason = is_array( $data ) ? ( $data['storage_reason'] ?? null ) : null;
				$reason          = in_array( $provided_reason, self::safe_storage_reasons(), true ) ? $provided_reason : '';
			}
		}
		$entry = array(
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
		);
		// Legacy transfer/Media diagnostics are intentionally site-wide and
		// remain metadata-only. New detailed failure diagnostics belong to the
		// exact authenticated WordPress user + OAuth client.
		$client_id = OAuth_Server::authenticated_mcp_client_id();
		if ( is_array( $details ) && get_current_user_id() > 0 && '' !== $client_id ) {
			$entry['client_hash']   = self::client_identity_hash( $client_id );
			$entry['error_message'] = $details['message'];
			$entry['error_data']    = $details['data'];
			$entry['redacted']      = $details['redacted'];
			$entry['truncated']     = $details['truncated'];
		}
		if ( '' !== $reason ) {
			$entry['storage_reason'] = $reason;
		}
		return $this->write( $entry );
	}

	/**
	 * Record the observed code, redacted message and redacted WP_Error data
	 * without converting unfamiliar error codes to generic identifiers.
	 * Returns a reference ONLY when the bounded option write succeeded.
	 *
	 * @param string $ability Exact Ability identifier (never request body).
	 * @param array<string,mixed> $details Already sanitized by Error_Details.
	 * @param string $origin Proven origin of this observation.
	 * @param string $stage Proven phase, not an inferred root cause.
	 * @param string $hint Actionable next-step identifier.
	 * @param string $client_id Authenticated MCP client (stored as keyed digest only).
	 * @param string $code_source WordPress-observed, Adapter-observed, Bridge-generated or unavailable.
	 * @return string
	 */
	public function record_mcp_failure( $ability, $details, $origin, $stage, $hint, $client_id, $code_source = 'observed_wordpress' ) {
		$uid = get_current_user_id();
		if ( $uid < 1 || ! is_string( $client_id ) || '' === $client_id ||
			! is_string( $ability ) || 1 !== preg_match( '/^[a-z0-9_-]+\/[a-z0-9_-]+$/D', $ability ) ||
			! is_array( $details ) ||
			( null !== ( $details['code'] ?? null ) &&
				( ! is_string( $details['code'] ) || strlen( $details['code'] ) > Error_Details::MAX_CODE ) ) ||
			! is_string( $details['message'] ?? null ) ||
			strlen( $details['message'] ) > Error_Details::MAX_MESSAGE ||
			! is_bool( $details['redacted'] ?? null ) || ! is_bool( $details['truncated'] ?? null ) ||
			! in_array( $origin, array( 'bridge_ability', 'native_ability', 'wordpress_core', 'adapter_boundary', 'bridge_policy' ), true ) ||
			! in_array( $stage, array( 'normalization', 'input_validation', 'authorization', 'ability_execution', 'output_validation', 'pre_execution', 'native_result', 'adapter_execute' ), true ) ||
			! in_array( $code_source, array( 'observed_wordpress', 'observed_at_adapter', 'bridge_generated', 'unavailable' ), true ) ||
			! in_array( $hint, array( 'inspect_request', 'inspect_permissions', 'inspect_private_storage', 'inspect_provider_diagnostics', 'inspect_native_result' ), true )
		) {
			return '';
		}
		$reference = bin2hex( random_bytes( 8 ) );
		$entry     = array(
			'timestamp'     => gmdate( 'c' ),
			'user_id'       => $uid,
			'ability'       => $ability,
			'target_type'   => '',
			'target_id'     => 0,
			'success'       => false,
			'error_code'    => $details['code'] ?? null,
			'error_message' => $details['message'],
			'error_data'    => $details['data'] ?? null,
			'redacted'      => $details['redacted'],
			'truncated'     => $details['truncated'],
			'operation'     => 'mcp_error',
			'phase'         => $stage,
			'origin'        => $origin,
			'code_source'   => $code_source,
			'next_step'     => $hint,
			'event_ref'     => $reference,
			'event_type'    => 'mcp_diagnostic',
			'client_hash'   => self::client_identity_hash( $client_id ),
		);
		return $this->write( $entry ) ? $reference : '';
	}

	/** @param string $client_id Authenticated client ID. @return string Stable site-keyed digest. */
	private static function client_identity_hash( $client_id ) {
		return hash_hmac( 'sha256', (string) $client_id, wp_salt( 'auth' ) );
	}

	/** @return array<int,string> Static redacted storage denial reasons. */
	private static function safe_storage_reasons() {
		return array( 'root_web_served', 'root_symlink', 'root_noncanonical', 'root_mode', 'lease_file_mode' );
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
	public function diagnostic_view( $limit = 10, $reference = '', $client_id = '', $user_id = 0 ) {
		$limit  = max( 1, min( absint( $limit ), self::LIMIT ) );
		$events = array();
		$total  = 0;
		foreach ( $this->retained() as $entry ) {
			if ( '' !== $reference && ( $entry['event_ref'] ?? '' ) !== $reference ) {
				continue;
			}
			$is_mcp      = 'mcp_diagnostic' === ( $entry['event_type'] ?? '' ) &&
				'mcp_error' === ( $entry['operation'] ?? '' ) &&
				false === ( $entry['success'] ?? null ) &&
				is_int( $entry['user_id'] ?? null ) && $entry['user_id'] === $user_id && $user_id > 0 &&
				is_string( $client_id ) && '' !== $client_id &&
				is_string( $entry['client_hash'] ?? null ) &&
				hash_equals( self::client_identity_hash( $client_id ), $entry['client_hash'] ) &&
				is_string( $entry['ability'] ?? null ) &&
				1 === preg_match( '/^[a-z0-9_-]+\/[a-z0-9_-]+$/D', $entry['ability'] ) &&
				( null === ( $entry['error_code'] ?? null ) ||
				( is_string( $entry['error_code'] ) && strlen( $entry['error_code'] ) <= Error_Details::MAX_CODE ) ) &&
				is_string( $entry['error_message'] ?? null ) &&
				strlen( $entry['error_message'] ) <= Error_Details::MAX_MESSAGE &&
				is_bool( $entry['redacted'] ?? null ) && is_bool( $entry['truncated'] ?? null ) &&
				in_array( $entry['origin'] ?? '', array( 'bridge_ability', 'native_ability', 'wordpress_core', 'adapter_boundary', 'bridge_policy' ), true ) &&
				in_array( $entry['phase'] ?? '', array( 'normalization', 'input_validation', 'authorization', 'ability_execution', 'output_validation', 'pre_execution', 'native_result', 'adapter_execute' ), true ) &&
				in_array( $entry['code_source'] ?? '', array( 'observed_wordpress', 'observed_at_adapter', 'bridge_generated', 'unavailable' ), true ) &&
				in_array( $entry['next_step'] ?? '', array( 'inspect_request', 'inspect_permissions', 'inspect_private_storage', 'inspect_provider_diagnostics', 'inspect_native_result' ), true );
			$is_transfer = 'wp-ai-bridge/private-transfer' === ( $entry['ability'] ?? '' ) &&
				in_array( $entry['operation'] ?? '', array( 'begin', 'append', 'status', 'verify', 'cancel', 'read', 'release', 'admin_release' ), true ) &&
				is_string( $entry['error_code'] ?? null ) &&
				strlen( $entry['error_code'] ) <= Error_Details::MAX_CODE;
			$is_media    = 'wp-ai-bridge/media-upload' === ( $entry['ability'] ?? '' ) &&
				'media-upload' === ( $entry['operation'] ?? '' ) &&
				false === ( $entry['success'] ?? null ) &&
				in_array( $entry['error_code'] ?? null, array( 'media_temp_failed', 'media_temp_write_failed' ), true );
			if ( ( ! $is_mcp && 'diagnostic' !== ( $entry['event_type'] ?? '' ) ) ||
				( ! $is_mcp && ! $is_transfer && ! $is_media ) ||
				( ! $is_mcp && ! in_array( $entry['phase'] ?? '', array( 'provider', 'validation' ), true ) ) ||
				! isset( $entry['success'] ) || ! is_bool( $entry['success'] ) ||
				( ! $is_mcp && ! is_string( $entry['error_code'] ?? null ) ) ||
				! is_string( $entry['event_ref'] ?? null ) ||
				1 !== preg_match( '/^[a-f0-9]{16}$/D', (string) ( $entry['event_ref'] ?? '' ) ) ) {
				continue;
			}
			++$total;
			if ( count( $events ) < $limit ) {
				$event = array(
					'timestamp'  => (string) $entry['timestamp'],
					'operation'  => (string) $entry['operation'],
					'phase'      => (string) $entry['phase'],
					'success'    => (bool) $entry['success'],
					'error_code' => $is_mcp ? ( $entry['error_code'] ?? null ) :
					( Error_Details::from_wp_error( new \WP_Error( (string) $entry['error_code'], '' ) )['code'] ?? '' ),
					'event_ref'  => (string) $entry['event_ref'],
				);
				if ( $is_mcp ) {
					$event['ability']     = $entry['ability'];
					$event['origin']      = $entry['origin'];
					$event['code_source'] = $entry['code_source'];
					$event['next_step']   = $entry['next_step'];
					$event['message']     = Error_Details::from_adapter_message( $entry['error_message'] )['message'];
					$bounded_data         = Error_Details::from_wp_error(
						new \WP_Error( (string) ( $entry['error_code'] ?? '' ), $entry['error_message'], $entry['error_data'] ?? null )
					);
					$event['error_code']  = $bounded_data['code'];
					$event['data']        = $bounded_data['data'];
					$event['redacted']    = $entry['redacted'] || $bounded_data['redacted'];
					$event['truncated']   = $entry['truncated'] || $bounded_data['truncated'];
				}
				$can_see_transfer_details = $is_transfer &&
					is_int( $entry['user_id'] ?? null ) && $entry['user_id'] > 0 &&
					$entry['user_id'] === $user_id &&
					is_string( $client_id ) && '' !== $client_id &&
					is_string( $entry['client_hash'] ?? null ) &&
					hash_equals( self::client_identity_hash( $client_id ), $entry['client_hash'] );
				if ( $can_see_transfer_details && ! empty( $entry['error_message'] ) &&
					is_string( $entry['error_message'] ) ) {
					$safe               = Error_Details::from_wp_error(
						new \WP_Error( (string) $entry['error_code'], $entry['error_message'], $entry['error_data'] ?? null )
					);
					$event['message']   = $safe['message'];
					$event['data']      = $safe['data'];
					$event['redacted']  = ! empty( $entry['redacted'] ) || $safe['redacted'];
					$event['truncated'] = ! empty( $entry['truncated'] ) || $safe['truncated'];
				}
				if ( $is_transfer && 'transfer_storage_unsafe' === $entry['error_code'] &&
					in_array( $entry['storage_reason'] ?? null, self::safe_storage_reasons(), true ) ) {
					$event['storage_reason'] = $entry['storage_reason'];
				}
				$events[] = $event;
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
