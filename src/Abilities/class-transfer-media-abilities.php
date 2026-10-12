<?php
/**
 * Explicit WordPress Media Library consumer for a verified private transfer.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Transfer_Session;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Neither the staging Ability nor arbitrary clients may directly call Core
 * filesystem APIs. This consumer only handles a verified current-user lease
 * with normal native Media sideload authority. No archive execution.
 */
final class Transfer_Media_Abilities {
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
			'wp-ai-bridge/transfer-media-import',
			array(
				'label'               => __( 'Import Verified Transfer into Media', 'wp-ai-bridge' ),
				'description'         => __( 'Consumes one previously verified private transfer as a new WordPress Media Library file. Side effects are not automatically retried. Does not install, unpack or run archives.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'transfer_id' => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
						'filename'    => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 255,
						),
					),
					'required'             => array( 'transfer_id', 'filename' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'transfer_id' => array( 'type' => 'string' ),
						'state'       => array( 'type' => 'string' ),
						'media_id'    => array( 'type' => 'integer' ),
						'filename'    => array( 'type' => 'string' ),
						'bytes'       => array( 'type' => 'integer' ),
						'sha256'      => array( 'type' => 'string' ),
					),
					'required'             => array( 'transfer_id', 'state', 'media_id', 'filename', 'bytes', 'sha256' ),
					'additionalProperties' => false,
				),
				'permission_callback' => array( $this, 'can_import' ),
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

	/** @return bool */
	public function can_import() {
		return get_current_user_id() > 0 &&
			'' !== OAuth_Server::authenticated_mcp_client_id() &&
			$this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'upload_files' );
	}

	/**
	 * @param mixed $input Explicit transfer and WordPress destination metadata.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input ) {
		if ( ! $this->can_import() ) {
			return new WP_Error( 'transfer_media_denied', __( 'Authenticated client, Builder Write and WordPress upload authority are required for Media import.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) ||
			2 !== count( $input ) ||
			! isset( $input['transfer_id'], $input['filename'] ) ||
			! is_string( $input['transfer_id'] ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['transfer_id'] ) ||
			! is_string( $input['filename'] ) ||
			'' === $input['filename'] || strlen( $input['filename'] ) > 255 ) {
			return $this->invalid();
		}
		// Let native WordPress normalize legitimate names (including RTL
		// scripts), but never reinterpret a caller string as a filesystem path.
		if ( 1 !== preg_match( '//u', $input['filename'] ) ||
			strpbrk( $input['filename'], '/\\' ) !== false ||
			preg_match( '/[\\x00-\\x1F\\x7F]/', $input['filename'] ) ) {
			return $this->invalid();
		}
		$name = sanitize_file_name( $input['filename'] );
		if ( '' === $name || strlen( $name ) > 255 || false === strpos( $name, '.' ) ) {
			return $this->invalid();
		}
		$mime = wp_check_filetype( $name, get_allowed_mime_types() );
		if ( empty( $mime['type'] ) ) {
			return new WP_Error( 'transfer_media_unsupported_type', __( 'WordPress does not allow this file type for the current upload principal.', 'wp-ai-bridge' ) );
		}

		$info = $this->store->status( $input['transfer_id'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( 'verified' !== $info['state'] || 'media' !== $info['purpose'] ) {
			return new WP_Error( 'transfer_consume_uncertain', __( 'Media consumption requires an unclaimed, fully verified private transfer. Inspect its status before another action.', 'wp-ai-bridge' ) );
		}
		if ( $info['total'] < 1 ) {
			return new WP_Error( 'transfer_media_empty', __( 'WordPress Media Library does not support an empty sideload; keep the verified zero-byte transfer private.', 'wp-ai-bridge' ) );
		}
		// Respect current WordPress/hosting upload policy; do NOT impose a
		// second fixed Bridge media-size ceiling on the staged byte protocol.
		$wp_size = wp_max_upload_size();
		if ( $wp_size <= 0 || $info['total'] > $wp_size ) {
			return new WP_Error( 'transfer_media_wp_limit', __( 'The transfer is larger than the current native WordPress upload limit. Increase the WordPress/host setting or use another authorized consumer.', 'wp-ai-bridge' ) );
		}
		// The one audited private-byte service owns the filesystem boundary;
		// this WordPress Ability never handles raw file descriptors or paths
		// selected by a client. A claimed effect cannot be blindly retried.
		$claim = $this->store->prepare_media_sideload( $input['transfer_id'] );
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}
		$temp    = $claim['temp'];
		$result  = null;
		$cleaned = false;
		try {
			if ( ! function_exists( 'media_handle_sideload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			// The per-lease coordinator remains held until Core returns:
			// no concurrent scratch copies or duplicate effects are admitted.
			$result = media_handle_sideload(
				array(
					'name'     => $name,
					'tmp_name' => $temp,
					'error'    => 0,
					'size'     => $claim['bytes'],
				),
				0
			);
		} catch ( \Throwable $error ) {
			$result = new WP_Error( 'transfer_outcome_unknown', 'Native Media sideload raised an exception after a durable claim.' );
		} finally {
			// Native Core may have moved the scratch source to uploads. Cleanup
			// always releases the stable per-lease lock and scratch reservation.
			$cleaned = $this->store->cleanup_media_temporary( $temp, $claim );
		}
		if ( is_wp_error( $result ) || ! is_int( $result ) || $result < 1 ) {
			return $this->uncertain();
		}
		// Record the observed WordPress ID whether or not its image editor
		// kept the original upload as the currently selected Media filename.
		$receipt = $this->store->finish_media( $input['transfer_id'], $result );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		if ( ! $cleaned ) {
			return new WP_Error( 'transfer_cleanup_uncertain', 'Native Media destination was committed, but private scratch cleanup requires inspection.' );
		}
		return array(
			'transfer_id' => $input['transfer_id'],
			'state'       => $receipt['state'],
			'media_id'    => (int) $receipt['destination_id'],
			'filename'    => $name,
			'bytes'       => $claim['bytes'],
			'sha256'      => $claim['sha256'],
		);
	}

	/** @return WP_Error */
	private function invalid() {
		return new WP_Error( 'transfer_media_invalid', __( 'The requested Media consumer identity or filename is invalid.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function uncertain() {
		return new WP_Error( 'transfer_outcome_unknown', __( 'The Media operation may be incomplete. Inspect the exact WordPress Media destination and transfer status; do not retry blindly.', 'wp-ai-bridge' ) );
	}
}
