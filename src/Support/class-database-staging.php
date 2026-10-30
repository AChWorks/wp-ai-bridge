<?php
/**
 * Database-only facade for existing private transfer storage.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Stages database input bytes without claiming or recording a database effect.
 */
final class Database_Staging {
	/** @var Permissions */
	private $permissions;

	/** @var Private_Transfer_Session */
	private $store;

	/** @param Permissions $permissions Current database delegation. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
		$this->store       = Private_Transfer_Session::for_database( $permissions );
	}

	/**
	 * Uses a fixed database purpose; never routes a caller-supplied purpose.
	 *
	 * @param array<string,mixed> $input Closed staging action.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( array $input ) {
		if ( ! $this->allowed() ) {
			return new WP_Error( 'database_access_denied', __( 'Current Database Inspection, Sensitive Database Read and Database Write delegation and administrator authority are required.', 'wp-ai-bridge' ) );
		}
		$fields = array(
			'stage_open'    => array( 'action', 'total_bytes', 'sha256' ),
			'stage_append'  => array( 'action', 'transfer_id', 'offset', 'content_base64' ),
			'stage_status'  => array( 'action', 'transfer_id' ),
			'stage_verify'  => array( 'action', 'transfer_id' ),
			'stage_cancel'  => array( 'action', 'transfer_id' ),
			'stage_release' => array( 'action', 'transfer_id' ),
		);
		$action = $input['action'] ?? '';
		if ( ! is_string( $action ) || ! isset( $fields[ $action ] ) ||
			count( $input ) !== count( $fields[ $action ] ) ||
			array_diff( array_keys( $input ), $fields[ $action ] ) ) {
			return $this->invalid();
		}
		$policy   = ( new Transfer_Settings() )->bytes();
		$envelope = max( 1, $policy['request'] - 4096 );
		// Multiply after division to remain integer-safe even with unlimited
		// PHP memory and the largest representable operator request policy.
		$chunk = min( $policy['chunk'], $policy['file'], intdiv( $envelope, 4 ) * 3 + intdiv( $envelope % 4 * 3, 4 ) );
		$chunk = max( 1, $chunk );
		if ( 'stage_open' === $action ) {
			if ( ! is_int( $input['total_bytes'] ) || $input['total_bytes'] < 0 ||
				! is_string( $input['sha256'] ) ||
				1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['sha256'] ) ) {
				return $this->invalid();
			}
			if ( $input['total_bytes'] > $policy['file'] ) {
				return new WP_Error( 'transfer_size_setting', __( 'The database value exceeds the current administrator-configured transfer size. Adjust the transfer file and reservation budgets in WP AI Bridge Settings.', 'wp-ai-bridge' ) );
			}
			$receipt = $this->store->begin(
				'database_value',
				$input['total_bytes'],
				$input['sha256'],
				$policy['file'],
				$chunk,
				$policy['sessions'],
				$policy['ttl'],
				$policy['active']
			);
		} else {
			$id = $input['transfer_id'];
			if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $id ) ) {
				return $this->invalid();
			}
			if ( 'stage_append' === $action ) {
				if ( ! is_int( $input['offset'] ) || $input['offset'] < 0 ||
					! is_string( $input['content_base64'] ) || '' === $input['content_base64'] ) {
					return $this->invalid();
				}
				$encoded = $input['content_base64'];
				if ( strlen( $encoded ) > $envelope ||
					strlen( $encoded ) > intdiv( $chunk, 3 ) * 4 + ( $chunk % 3 ? 4 : 0 ) ) {
					return new WP_Error( 'transfer_message_budget', __( 'The database staging chunk exceeds the configured incoming request budget. Use a smaller chunk or adjust the administrator transfer budget.', 'wp-ai-bridge' ) );
				}
				$body = wp_json_encode( $input );
				if ( ! is_string( $body ) || strlen( $body ) > $policy['request'] - 2048 ) {
					return new WP_Error( 'transfer_message_budget', __( 'The encoded database staging message exceeds the configured incoming request budget.', 'wp-ai-bridge' ) );
				}
				$bytes = base64_decode( $encoded, true );
				if ( ! is_string( $bytes ) || base64_encode( $bytes ) !== $encoded || strlen( $bytes ) > $chunk ) {
					return $this->invalid();
				}
				$receipt = $this->store->append( $id, $input['offset'], $bytes );
			} elseif ( 'stage_status' === $action ) {
				$receipt = $this->store->status( $id );
			} elseif ( 'stage_verify' === $action ) {
				$receipt = $this->store->verify( $id );
			} else {
				// These are disposable input bytes. There is no native-effect
				// claim or staging receipt journal to acknowledge or retain.
				$receipt = $this->store->cancel( $id );
				if ( true === $receipt ) {
					$receipt = array(
						'id'               => $id,
						'state'            => 'stage_release' === $action ? 'released' : 'cancelled',
						'payload_released' => true,
					);
				}
			}
		}
		return is_wp_error( $receipt ) ? $receipt : $this->result( $action, $receipt, $policy, $chunk );
	}

	/**
	 * Validate and hold all staged values before entering a transaction.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Exact source identities.
	 * @param callable $callback Receives transfer-ID-indexed stream/bytes/sha256.
	 * @return mixed|WP_Error The callback result.
	 */
	public function with_sources( array $descriptors, callable $callback ) {
		return $this->store->with_database_sources( $descriptors, $callback );
	}

	/** @return bool */
	private function allowed() {
		return get_current_user_id() > 0 &&
			( ! is_multisite() || ( is_super_admin() && current_user_can( 'manage_network_options' ) ) ) &&
			$this->permissions->allowed_all(
				array(
					array( Settings::GROUP_DATABASE_INSPECTION, 'manage_options' ),
					array( Settings::GROUP_DATABASE_READ, 'manage_options' ),
					array( Settings::GROUP_DATABASE_WRITE, 'manage_options' ),
				)
			);
	}

	/** @return WP_Error */
	private function invalid() {
		return new WP_Error( 'transfer_invalid', __( 'The database staging request or expected source identity is invalid.', 'wp-ai-bridge' ) );
	}

	/**
	 * @param string $action Fixed action.
	 * @param array<string,mixed> $receipt Private storage receipt.
	 * @param array<string,int> $policy Current transfer policy.
	 * @param int $chunk Incoming raw chunk budget.
	 * @return array<string,mixed> No path, stream or database-effect outcome.
	 */
	private function result( $action, $receipt, $policy, $chunk ) {
		return array(
			'action'           => $action,
			'state'            => (string) $receipt['state'],
			'transfer_id'      => (string) $receipt['id'],
			'purpose'          => 'database_value',
			'complete'         => ! empty( $receipt['complete'] ),
			'received_bytes'   => (int) ( $receipt['received'] ?? 0 ),
			'total_bytes'      => (int) ( $receipt['total'] ?? 0 ),
			'sha256'           => (string) ( $receipt['sha256'] ?? '' ),
			'expires_at'       => (int) ( $receipt['expires'] ?? 0 ),
			'max_chunk_bytes'  => min( $chunk, (int) ( $receipt['chunk'] ?? $chunk ) ),
			'request_bytes'    => $policy['request'],
			'max_file_bytes'   => $policy['file'],
			'max_active_bytes' => $policy['active'],
			'max_sessions'     => $policy['sessions'],
			'lease_seconds'    => $policy['ttl'],
			'payload_released' => ! empty( $receipt['payload_released'] ),
		);
	}
}
