<?php
/**
 * Draft, client-neutral private byte-session lifecycle over existing MCP OAuth.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Transfer_Session;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_Error;

/**
 * Session staging is NOT permission to consume, execute or publish its bytes.
 * This public Ability neither emits private paths nor accepts remote URLs.
 */
final class Transfer_Session_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @var Private_Transfer_Session */
	private $store;

	/** @param Permissions $permissions Bridge delegation. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
		$this->store       = new Private_Transfer_Session( $permissions );
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/private-transfer',
			array(
				'label'               => __( 'Transfer Private File Bytes', 'wp-ai-bridge' ),
				'description'         => __( 'Authenticated, resumable byte staging using begin/append/status/verify/cancel. Never installs, imports, executes or publishes staged data.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'action'         => array(
							'type' => 'string',
							'enum' => array( 'limits', 'begin', 'append', 'status', 'verify', 'cancel' ),
						),
						'purpose'        => array(
							'type' => 'string',
							'enum' => array( 'media', 'text', 'wxr' ),
						),
						'total_bytes'    => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'sha256'         => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
						'transfer_id'    => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
						'offset'         => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'content_base64' => array( 'type' => 'string' ),
					),
					'required'             => array( 'action' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'action'           => array( 'type' => 'string' ),
						'state'            => array( 'type' => 'string' ),
						'transfer_id'      => array( 'type' => 'string' ),
						'purpose'          => array( 'type' => 'string' ),
						'complete'         => array( 'type' => 'boolean' ),
						'received_bytes'   => array( 'type' => 'integer' ),
						'total_bytes'      => array( 'type' => 'integer' ),
						'sha256'           => array( 'type' => 'string' ),
						'expires_at'       => array( 'type' => 'integer' ),
						'max_chunk_bytes'  => array( 'type' => 'integer' ),
						'request_bytes'    => array( 'type' => 'integer' ),
						'max_file_bytes'   => array( 'type' => 'integer' ),
						'max_active_bytes' => array( 'type' => 'integer' ),
						'max_sessions'     => array( 'type' => 'integer' ),
						'lease_seconds'    => array( 'type' => 'integer' ),
						'destination_id'   => array( 'type' => 'integer' ),
					),
					'required'             => array( 'action', 'state', 'transfer_id', 'purpose', 'complete', 'received_bytes', 'total_bytes', 'sha256', 'expires_at', 'max_chunk_bytes', 'request_bytes', 'max_file_bytes', 'max_active_bytes', 'max_sessions', 'lease_seconds', 'destination_id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'can_transfer' ),
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/**
	 * Requires an *authenticated* MCP client, not merely a native admin/cookie
	 * request. Session operations then reauthorize their exact stored purpose.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 */
	public function can_transfer( $input = array() ) {
		if ( get_current_user_id() < 1 || '' === OAuth_Server::authenticated_mcp_client_id() ) {
			return false;
		}
		if ( ! is_array( $input ) ) {
			return false;
		}
		$action = $input['action'] ?? '';
		if ( 'begin' === $action ) {
			return $this->can_purpose( $input['purpose'] ?? '' );
		}
		// These are availability checks only; the opaque lease store binds and
		// rechecks exact blog, WordPress user, OAuth client and purpose.
		return $this->can_purpose( 'media' ) ||
			$this->can_purpose( 'text' ) ||
			$this->can_purpose( 'wxr' );
	}

	/** @param string $purpose Authorized purpose. @return bool */
	private function can_purpose( $purpose ) {
		if ( 'media' === $purpose ) {
			return $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'upload_files' );
		}
		if ( 'text' === $purpose ) {
			return $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'edit_posts' );
		}
		if ( 'wxr' === $purpose ) {
			return $this->permissions->allowed_all(
				array(
					array( Settings::GROUP_SITE_CONFIG, 'import' ),
					array( Settings::GROUP_BUILDER_WRITE, 'edit_posts' ),
				)
			);
		}
		return false;
	}

	/**
	 * @param mixed $input JSON Schema-validated operation.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input ) {
		if ( ! $this->can_transfer( $input ) ) {
			return new WP_Error( 'transfer_denied', __( 'An authenticated MCP client and current delegated WordPress authority are required.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) || ! isset( $input['action'] ) || ! is_string( $input['action'] ) ) {
			return new WP_Error( 'transfer_invalid', __( 'Invalid transfer operation.', 'wp-ai-bridge' ) );
		}
		$action = $input['action'];
		$policy = ( new Transfer_Settings() )->bytes();
		$budget = $policy['request'];
		// Leave space for nested execute-ability arguments and MCP headers.
		$chunk        = min( $policy['chunk'], max( 1, intdiv( max( 1, $budget - 4096 ) * 3, 4 ) ) );
		$allowed_keys = array(
			'limits' => array( 'action' ),
			'begin'  => array( 'action', 'purpose', 'total_bytes', 'sha256' ),
			'append' => array( 'action', 'transfer_id', 'offset', 'content_base64' ),
			'status' => array( 'action', 'transfer_id' ),
			'verify' => array( 'action', 'transfer_id' ),
			'cancel' => array( 'action', 'transfer_id' ),
		);
		if ( ! isset( $allowed_keys[ $action ] ) ||
			array_diff( array_keys( $input ), $allowed_keys[ $action ] ) ) {
			return $this->invalid();
		}
		if ( 'limits' === $action ) {
			return $this->result( $action, null, $policy, $chunk );
		}
		if ( 'begin' === $action ) {
			if ( count( $input ) !== 4 || ! isset( $input['purpose'], $input['total_bytes'], $input['sha256'] ) ||
				! $this->can_purpose( $input['purpose'] ) ||
				! is_int( $input['total_bytes'] ) || $input['total_bytes'] < 0 ||
				! is_string( $input['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['sha256'] ) ) {
				return $this->invalid();
			}
			if ( $input['total_bytes'] > $policy['file'] ) {
				return new WP_Error( 'transfer_size_setting', __( 'The file exceeds the current administrator-configured transfer size. Increase the transfer file and reservation budgets in WP AI Bridge Settings.', 'wp-ai-bridge' ) );
			}
			$receipt = $this->store->begin(
				$input['purpose'],
				$input['total_bytes'],
				$input['sha256'],
				$policy['file'],
				$chunk,
				$policy['sessions'],
				$policy['ttl'],
				$policy['active']
			);
			return is_wp_error( $receipt ) ? $receipt : $this->result( $action, $receipt, $policy, $chunk );
		}
		$id = $input['transfer_id'] ?? '';
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $id ) ) {
			return $this->invalid();
		}
		if ( 'append' === $action ) {
			if ( count( $input ) !== 4 || ! is_int( $input['offset'] ) ||
				$input['offset'] < 0 || ! is_string( $input['content_base64'] ) ) {
				return $this->invalid();
			}
			$encoded = $input['content_base64'];
			if ( '' === $encoded || strlen( $encoded ) > $budget - 4096 ||
				strlen( $encoded ) > intdiv( $chunk, 3 ) * 4 + ( $chunk % 3 ? 4 : 0 ) ) {
				return new WP_Error( 'transfer_message_budget', __( 'The transfer chunk exceeds the configured incoming MCP message budget. Use a smaller chunk or adjust the WordPress administrator budget.', 'wp-ai-bridge' ) );
			}
			$body = wp_json_encode( $input );
			if ( ! is_string( $body ) || strlen( $body ) > $budget - 2048 ) {
				return new WP_Error( 'transfer_message_budget', __( 'The encoded incoming transfer message exceeds the configured MCP request budget.', 'wp-ai-bridge' ) );
			}
			$decoded = base64_decode( $encoded, true );
			if ( ! is_string( $decoded ) || base64_encode( $decoded ) !== $encoded ||
				strlen( $decoded ) > $chunk ) {
				return $this->invalid();
			}
			$receipt = $this->store->append( $id, $input['offset'], $decoded );
		} elseif ( 'status' === $action ) {
			$receipt = $this->store->status( $id );
		} elseif ( 'verify' === $action ) {
			$receipt = $this->store->verify( $id );
		} else {
			$receipt = $this->store->cancel( $id );
			if ( true === $receipt ) {
				$receipt = array(
					'id'    => $id,
					'state' => 'cancelled',
				);
			}
		}
		return is_wp_error( $receipt ) ? $receipt : $this->result( $action, $receipt, $policy, $chunk );
	}

	/** @return WP_Error */
	private function invalid() {
		return new WP_Error( 'transfer_invalid', __( 'The private transfer request or expected session identity is invalid.', 'wp-ai-bridge' ) );
	}

	/** @param string $action Action. @param array<string,mixed>|null $receipt Lease. @param array<string,int> $policy Effective policy. @param int $chunk Window. @return array<string,mixed> */
	private function result( $action, $receipt, $policy, $chunk ) {
		$receipt = is_array( $receipt ) ? $receipt : array();
		return array(
			'action'           => $action,
			'state'            => (string) ( $receipt['state'] ?? 'limits' ),
			'transfer_id'      => (string) ( $receipt['id'] ?? '' ),
			'purpose'          => (string) ( $receipt['purpose'] ?? '' ),
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
			'destination_id'   => (int) ( $receipt['destination_id'] ?? 0 ),
		);
	}
}
