<?php
/**
 * Client-neutral inline binary read fallback via WordPress Abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Inline_File_Read;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

/**
 * Bounded opaque file source windows. Public API is a draft proposal; not
 * enabled in released Bridge until reviewed and explicitly accepted.
 */
final class File_Transfer_Abilities {
	/** @var Inline_File_Read */
	private $reader;

	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Current site grants. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
		$this->reader      = new Inline_File_Read( $permissions );
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/file-bytes-read',
			array(
				'label'               => __( 'Read File Bytes', 'wp-ai-bridge' ),
				'description'         => __( 'Reads existing authorized WordPress Media Library file bytes using identity-bound, Base64 MCP windows. Not a downloadable attachment or server path.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'action'            => array(
							'type' => 'string',
							'enum' => array( 'inspect', 'chunk' ),
						),
						'source_type'       => array(
							'type' => 'string',
							'enum' => array( 'media' ),
						),
						'source_id'         => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'offset'            => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'max_bytes'         => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => Bounded_Payload::response_limit(),
						),
						'expected_snapshot' => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
						'expected_sha256'   => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
					),
					'required'             => array( 'action', 'source_type', 'source_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'mode'           => array( 'type' => 'string' ),
						'source_type'    => array( 'type' => 'string' ),
						'source_id'      => array( 'type' => 'integer' ),
						'snapshot'       => array( 'type' => 'string' ),
						'content_sha256' => array( 'type' => 'string' ),
						'total_bytes'    => array( 'type' => 'integer' ),
						'mime_type'      => array( 'type' => 'string' ),
						'file_name'      => array( 'type' => 'string' ),
						'offset'         => array( 'type' => 'integer' ),
						'next_offset'    => array( 'type' => 'integer' ),
						'content_base64' => array( 'type' => 'string' ),
						'complete'       => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'mode', 'source_type', 'source_id', 'snapshot', 'content_sha256', 'total_bytes', 'mime_type', 'file_name', 'offset', 'next_offset', 'content_base64', 'complete' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this->reader, 'execute' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */
	public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
	}
}
