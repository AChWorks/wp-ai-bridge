<?php
/**
 * Authorized WordPress Media snapshot producer for the shared private transfer.
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
 * Native source identity and permissions stay separate from byte transport.
 */
final class Transfer_Media_Export_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @var Private_Transfer_Session */
	private $store;

	/** @param Permissions $permissions Delegation service. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
		$this->store       = new Private_Transfer_Session( $permissions );
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/transfer-media-export',
			array(
				'label'               => __( 'Export Media as Private Transfer', 'wp-ai-bridge' ),
				'description'         => __( 'Creates an authenticated private byte snapshot of one readable WordPress Media attachment; retrieve bounded windows using private-transfer read.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'media_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'media_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'transfer_id' => array( 'type' => 'string' ),
						'media_id'    => array( 'type' => 'integer' ),
						'total_bytes' => array( 'type' => 'integer' ),
						'sha256'      => array( 'type' => 'string' ),
						'expires_at'  => array( 'type' => 'integer' ),
						'state'       => array( 'type' => 'string' ),
					),
					'required'             => array( 'transfer_id', 'media_id', 'total_bytes', 'sha256', 'expires_at', 'state' ),
					'additionalProperties' => false,
				),
				'permission_callback' => array( $this, 'can_export' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @param mixed $input Native Ability input. @return bool */
	public function can_export( $input = array() ) {
		return get_current_user_id() > 0 &&
			'' !== OAuth_Server::authenticated_mcp_client_id() &&
			$this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' ) &&
			is_array( $input ) &&
			isset( $input['media_id'] ) &&
			is_int( $input['media_id'] ) &&
			$input['media_id'] > 0 &&
			'attachment' === get_post_type( $input['media_id'] ) &&
			current_user_can( 'read_post', $input['media_id'] );
	}

	/**
	 * @param mixed $input Exact native Media identity, never a client-supplied path.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input ) {
		if ( ! $this->can_export( $input ) || 1 !== count( $input ) ) {
			return new WP_Error( 'transfer_export_denied', __( 'A readable WordPress Media attachment and a currently authorized MCP client are required.', 'wp-ai-bridge' ) );
		}
		$media_id = $input['media_id'];
		$file     = get_attached_file( $media_id );
		$uploads  = wp_get_upload_dir();
		$basedir  = realpath( (string) ( $uploads['basedir'] ?? '' ) );
		$real     = is_string( $file ) ? realpath( $file ) : false;

		// Do not let an attachment meta filter or a compromised _wp_attached_file
		// turn this producer into an arbitrary private server-file reader.
		if ( ! is_string( $basedir ) || ! is_string( $real ) ||
			! str_starts_with( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $basedir ) ) ) ||
			! is_file( $real ) || ! is_readable( $real ) ) {
			return new WP_Error( 'transfer_export_unavailable', __( 'The Media source is unavailable inside the current WordPress uploads directory.', 'wp-ai-bridge' ) );
		}
		$policy  = ( new Transfer_Settings() )->bytes();
		$receipt = $this->store->stage_media_export( $real, $policy, $media_id );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		return array(
			'transfer_id' => $receipt['id'],
			'media_id'    => $media_id,
			'total_bytes' => $receipt['total'],
			'sha256'      => $receipt['sha256'],
			'expires_at'  => $receipt['expires'],
			'state'       => $receipt['state'],
		);
	}
}
