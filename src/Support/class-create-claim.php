<?php
/**
 * Shared bounded, fail-closed identity for Bridge-owned non-idempotent creates.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/**
 * Native options have a database-unique option_name, unlike post-meta.
 * A claim is reserved before invoking WordPress's first committing API.
 */
final class Create_Claim {
	const PREFIX           = 'wpai_create_claim_';
	const MAX_CLAIMS       = 2048;
	const RECOVERY_SECONDS = 2592000; // 30 days; retired fingerprints remain reserved.
	const MAX_ID_BYTES     = 128;

	/**
	 * Executes the legacy effect only once per stable, authorized identity.
	 *
	 * @param string   $operation Exact Bridge-owned operation.
	 * @param array    $input     Request, including optional operation_id.
	 * @param callable $allowed   Current authorization gate.
	 * @param callable $perform   Original creating operation, with key removed.
	 * @param callable $recover   Authorized current-object readback by ID.
	 * @return mixed|WP_Error
	 */
	public static function run( $operation, $input, $allowed, $perform, $recover ) {
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'create_claim_invalid', __( 'Create request must be an object.', 'wp-ai-bridge' ) );
		}
		$clean = $input;
		unset( $clean['operation_id'] );
		if ( ! array_key_exists( 'operation_id', $input ) ) {
			return call_user_func( $perform, $clean );
		}

		$operation_id = $input['operation_id'];
		if ( ! is_string( $operation_id ) || strlen( $operation_id ) < 8 || strlen( $operation_id ) > self::MAX_ID_BYTES ||
			! preg_match( '/^[A-Za-z0-9_.:-]+$/D', $operation_id ) ) {
			return new WP_Error( 'create_claim_invalid', __( 'operation_id must be 8 to 128 ASCII letters, digits, underscores, hyphens, dots or colons.', 'wp-ai-bridge' ) );
		}
		if ( ! call_user_func( $allowed, $clean ) ) {
			return new WP_Error( 'create_claim_denied', __( 'The current WordPress principal and Bridge access groups must authorize this create.', 'wp-ai-bridge' ) );
		}
		$principal = (int) get_current_user_id();
		$blog_id   = (int) get_current_blog_id();
		if ( $principal <= 0 || $blog_id <= 0 ) {
			return new WP_Error( 'create_claim_denied', __( 'A current authenticated WordPress site and principal are required.', 'wp-ai-bridge' ) );
		}

		$client   = OAuth_Server::authenticated_mcp_client_id();
		$identity = wp_json_encode( array( $blog_id, $principal, (string) $client, $operation, $operation_id ) );
		$request  = wp_json_encode( self::normalize( $clean ) );
		if ( ! is_string( $identity ) || ! is_string( $request ) ) {
			return new WP_Error( 'create_claim_invalid', __( 'The create input cannot be represented losslessly.', 'wp-ai-bridge' ) );
		}
		$name        = self::PREFIX . hash( 'sha256', $identity );
		$fingerprint = hash( 'sha256', $request );
		$initial     = wp_json_encode(
			array(
				'fingerprint' => $fingerprint,
				'state'       => 'in_progress',
				'created_at'  => time(),
				'id'          => 0,
			)
		);
		if ( ! is_string( $initial ) ) {
			return new WP_Error( 'create_claim_invalid', __( 'The create identity cannot be recorded.', 'wp-ai-bridge' ) );
		}

		// Under the configured capacity cap, each option key is unique at the
		// WordPress options table's database unique index, even across PHP workers.
		$existing = self::fresh_option( $name );
		if ( false !== $existing ) {
			return self::adopt( $name, $existing, $fingerprint, $allowed, $clean, $recover );
		}
		if ( self::claim_count() >= self::MAX_CLAIMS ) {
			return new WP_Error( 'create_claim_capacity', __( 'Create recovery capacity is full. No new create was attempted; administrative reconciliation is required.', 'wp-ai-bridge' ) );
		}
		if ( ! add_option( $name, $initial, '', false ) ) {
			$winner = self::fresh_option( $name );
			return false !== $winner
				? self::adopt( $name, $winner, $fingerprint, $allowed, $clean, $recover )
				: new WP_Error( 'create_claim_outcome_unknown', __( 'Could not establish a durable exclusive create claim; do not blindly retry.', 'wp-ai-bridge' ) );
		}

		try {
			$result = call_user_func( $perform, $clean );
		} catch ( \Throwable $error ) {
			return new WP_Error( 'create_claim_outcome_unknown', __( 'The create result is uncertain; do not repeat the operation with a new key.', 'wp-ai-bridge' ) );
		}
		$partial = false;
		$id      = 0;
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( 'featured_media_failed' === $result->get_error_code() && is_array( $data ) && ! empty( $data['content_saved'] ) ) {
				$id      = isset( $data['id'] ) ? (int) $data['id'] : 0;
				$partial = $id > 0;
			}
		} elseif ( is_array( $result ) ) {
			$id = isset( $result['id'] ) ? (int) $result['id'] : (int) ( $result['items'][0]['id'] ?? 0 );
		}

		if ( $id <= 0 ) {
			// A failed callback or failed readback can still follow a committed
			// insert; retain the claim forever as outcome_unknown.
			return new WP_Error( 'create_claim_outcome_unknown', __( 'The create result is uncertain; do not repeat the operation with a new key.', 'wp-ai-bridge' ) );
		}
		$next = wp_json_encode(
			array(
				'fingerprint' => $fingerprint,
				'state'       => $partial ? 'partial' : 'committed',
				'created_at'  => time(),
				'id'          => $id,
			)
		);
		if ( ! is_string( $next ) || ! self::compare_swap( $name, $initial, $next ) ) {
			return new WP_Error( 'create_claim_outcome_unknown', __( 'WordPress may have created the object, but its recovery receipt could not be verified.', 'wp-ai-bridge' ) );
		}
		return $result;
	}

	/**
	 * Durable site/project/key exclusion uses the SAME native option-claim
	 * primitive as client operation IDs, but is shared across principals.
	 *
	 * @param string $project Exact project reference.
	 * @param string $key Canonical document key.
	 * @return array<string,string>|WP_Error
	 */
	public static function reserve_document_key( $project, $key ) {
		$identity = wp_json_encode( array( 'canonical-document', get_current_blog_id(), $project, $key ) );
		if ( ! is_string( $identity ) ) {
			return new WP_Error( 'workspace_key_invalid', __( 'Document key cannot be represented.', 'wp-ai-bridge' ) );
		}
		$name = self::PREFIX . hash( 'sha256', $identity );
		if ( false !== self::fresh_option( $name ) ) {
			return new WP_Error( 'workspace_key_reserved', __( 'This project document key is already reserved or has an uncertain create outcome; read or reconcile it instead.', 'wp-ai-bridge' ) );
		}
		if ( self::claim_count() >= self::MAX_CLAIMS ) {
			return new WP_Error( 'create_claim_capacity', __( 'Create recovery capacity is full. No new create was attempted; administrative reconciliation is required.', 'wp-ai-bridge' ) );
		}
		$initial = wp_json_encode(
			array(
				'fingerprint' => hash( 'sha256', $identity ),
				'state'       => 'in_progress',
				'created_at'  => time(),
				'id'          => 0,
			)
		);
		if ( ! is_string( $initial ) || ! add_option( $name, $initial, '', false ) ) {
			return new WP_Error( 'workspace_key_reserved', __( 'Another create owns this project document key; do not retry blindly.', 'wp-ai-bridge' ) );
		}
		return array(
			'name'    => $name,
			'initial' => $initial,
		);
	}

	/** @param array<string,string> $claim Reserved claim. @param int $id Created or bound record. @return bool */
	public static function commit_document_key( $claim, $id ) {
		$state = json_decode( $claim['initial'], true );
		if ( ! is_array( $state ) || $id <= 0 ) {
			return false;
		}
		$state['state'] = 'committed';
		$state['id']    = $id;
		$committed      = wp_json_encode( $state );
		return is_string( $committed ) && self::compare_swap( $claim['name'], $claim['initial'], $committed );
	}

	/** @param string $name Name. @return string|false */
	private static function fresh_option( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$value = get_option( $name, false );
		return is_string( $value ) && '' !== $value ? $value : false;
	}

	/** @param string $name Option. @param string $before Old JSON. @param string $after New JSON. @return bool */
	private static function compare_swap( $name, $before, $after ) {
		return Create_Claim_Store::compare_swap( $name, $before, $after );
	}

	/** @return int */
	private static function claim_count() {
		return Create_Claim_Store::count();
	}


	/**
	 * Adopt a known create only after rechecking current WordPress authority
	 * and the actual existing object's identity; never cache private response.
	 *
	 * @return mixed|WP_Error
	 */
	private static function adopt( $name, $raw, $fingerprint, $allowed, $input, $recover ) {
		$state = json_decode( $raw, true );
		if ( ! is_array( $state ) || ! isset( $state['fingerprint'], $state['state'], $state['created_at'] ) ) {
			return new WP_Error( 'create_claim_outcome_unknown', __( 'Stored recovery state is invalid. Do not retry this create.', 'wp-ai-bridge' ) );
		}
		if ( ! hash_equals( (string) $state['fingerprint'], $fingerprint ) ) {
			return new WP_Error( 'create_claim_conflict', __( 'operation_id was already used for a different create request.', 'wp-ai-bridge' ) );
		}
		if ( ! call_user_func( $allowed, $input ) ) {
			return new WP_Error( 'create_claim_denied', __( 'The current WordPress principal and Bridge access groups must authorize this create.', 'wp-ai-bridge' ) );
		}
		if ( time() - (int) $state['created_at'] > self::RECOVERY_SECONDS ) {
			if ( 'expired' !== $state['state'] ) {
				$compact = wp_json_encode(
					array(
						'fingerprint' => $fingerprint,
						'state'       => 'expired',
						'created_at'  => $state['created_at'],
						'id'          => 0,
					)
				);
				if ( is_string( $compact ) ) {
					self::compare_swap( $name, $raw, $compact );
				}
			}
			return new WP_Error( 'create_claim_expired', __( 'The recovery window expired; the key is permanently reserved and cannot be reused.', 'wp-ai-bridge' ) );
		}
		if ( 'partial' === $state['state'] ) {
			return new WP_Error( 'create_claim_partial', __( 'The object was created with an incomplete follow-up effect. Inspect its current ID before repairing manually.', 'wp-ai-bridge' ), array( 'id' => (int) $state['id'] ) );
		}
		if ( 'committed' !== $state['state'] || empty( $state['id'] ) ) {
			return new WP_Error( 'create_claim_outcome_unknown', __( 'An earlier create may still be in progress or have an uncertain outcome. Do not replay.', 'wp-ai-bridge' ) );
		}
		try {
			$result = call_user_func( $recover, (int) $state['id'], $input );
		} catch ( \Throwable $error ) {
			$result = null;
		}
		return ! is_wp_error( $result ) && is_array( $result )
			? $result
			: new WP_Error( 'create_claim_outcome_unknown', __( 'The created object no longer has a verifiable authorized readback; do not replay.', 'wp-ai-bridge' ) );
	}

	/** @param mixed $value JSON-compatible input. @return mixed */
	private static function normalize( $value ) {
		if ( is_string( $value ) && strlen( $value ) > 2048 ) {
			return array(
				'sha256' => hash( 'sha256', $value ),
				'bytes'  => strlen( $value ),
			);
		}
		if ( is_array( $value ) ) {
			if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::normalize( $item );
			}
		}
		return $value;
	}
}
