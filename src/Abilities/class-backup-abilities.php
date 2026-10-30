<?php
/**
 * Public approved installation backup and isolated restoration contract.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Backup_Operation;
use WP_AI_Bridge\Support\Permissions;

/** One consumer-neutral Ability; action-specific authority is checked again. */
final class Backup_Abilities {
	private $permissions;

	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/full-backup',
			array(
				'label'               => __( 'Full Installation Backup and Isolated Restore', 'wp-ai-bridge' ),
				'description'         => __( 'Capture every approved database table and installation file under explicit all-writer quiescence, with private bounded continuation and verified integrity. Restore into a separately approved empty database schema and nonserved root; activation is a separate operation. Unsupported native objects prevent full completion.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->schema(),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_execute' ),
				'execute_callback'    => array( $this, 'execute' ),
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
		return $registered;
	}

	public function can_execute( $input = array() ) {
		return ( new Backup_Operation( $this->permissions ) )->allowed( is_array( $input ) ? $input : array() );
	}

	public function execute( $input ) {
		return ( new Backup_Operation( $this->permissions ) )->execute( is_array( $input ) ? $input : array() );
	}

	private function schema() {
		$id = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'action' ),
			'properties'           => array(
				'action'                      => array(
					'type' => 'string',
					'enum' => array( 'inspect', 'export_begin', 'export_step', 'export_status', 'read', 'input_open', 'input_append', 'input_seal', 'input_status', 'restore_prepare', 'restore_step', 'restore_begin', 'restore_status', 'cancel' ),
				),
				'source_roots'                => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'minItems'    => 1,
					'uniqueItems' => true,
				),
				'expected_plan_hash'          => $id,
				'session_id'                  => $id,
				'archive_id'                  => $id,
				'archive_sha256'              => $id,
				'archive_purpose'             => array(
					'type' => 'string',
					'enum' => array( 'backup_archive', 'backup_input' ),
				),
				'purpose'                     => array(
					'type' => 'string',
					'enum' => array( 'backup_archive', 'backup_input', 'backup_state' ),
				),
				'target_schema'               => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
				'target_root'                 => array( 'type' => 'string' ),
				'quiescence_confirmed'        => array( 'type' => 'boolean' ),
				'bootstrap_writers_confirmed' => array( 'type' => 'boolean' ),
				'max_total_bytes'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'offset'                      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'max_bytes'                   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'content_base64'              => array( 'type' => 'string' ),
				'sha256'                      => $id,
			),
		);
	}
}
