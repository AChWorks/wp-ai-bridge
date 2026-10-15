<?php
/**
 * Deliberate exact-record Workspace deletion, independent of generic content.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;
use WP_Error;

/**
 * One site-local record per call; never bulk clear, never generic CPT access.
 */
final class Workspace_Delete_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @var Store */
	private $store;

	/** @var Mutation_Log */
	private $log;

	/**
	 * @param Permissions $permissions Bridge delegation.
	 * @param Store        $store       Existing private Workspace store.
	 * @param Mutation_Log $log         Existing metadata-only Activity log.
	 */
	public function __construct( Permissions $permissions, Store $store, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->store       = $store;
		$this->log         = $log;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/workspace-delete',
			array(
				'label'               => __( 'Permanently Delete Exact Workspace Record', 'wp-ai-bridge' ),
				'description'         => __( 'Permanently deletes one explicitly confirmed, project-bound private Workspace record with exact blog, record, and version/hash checks. Never clears all Workspace.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'kind'                => array(
							'type' => 'string',
							'enum' => array( 'document', 'task' ),
						),
						'id'                  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'blog_id'             => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'project_ref'         => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 128,
							'pattern'   => '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$',
						),
						'expected_version'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'expected_state_hash' => array(
							'type'    => 'string',
							'pattern' => '^[a-f0-9]{64}$',
						),
						'confirmation'        => array(
							'type'      => 'string',
							'maxLength' => 96,
						),
					),
					'required'             => array( 'kind', 'id', 'blog_id', 'project_ref', 'expected_version', 'expected_state_hash', 'confirmation' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'deleted' => array( 'type' => 'boolean' ),
						'id'      => array( 'type' => 'integer' ),
						'kind'    => array( 'type' => 'string' ),
						'blog_id' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'deleted', 'id', 'kind', 'blog_id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'can_delete' ),
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
	 * Permission checks intentionally run again in execute, not just the
	 * official Ability permission callback. No legacy/unbound cleanup.
	 *
	 * @param mixed $input Ability parameters.
	 * @return bool
	 */
	public function can_delete( $input ) {
		if ( ! is_array( $input ) || ! isset( $input['id'], $input['blog_id'], $input['kind'] ) ||
			! is_int( $input['id'] ) || $input['id'] < 1 ||
			! is_int( $input['blog_id'] ) || (int) get_current_blog_id() !== $input['blog_id'] ||
			! in_array( $input['kind'], array( 'document', 'task' ), true ) ||
			0 === get_current_user_id() || '' === OAuth_Server::authenticated_mcp_client_id() ) {
			return false;
		}
		return current_user_can( 'delete_post', $input['id'] ) &&
			current_user_can( 'manage_options' ) &&
			$this->permissions->allowed_all(
				array(
					array( Settings::GROUP_BUILDER_WRITE, 'edit_posts' ),
					array( Settings::GROUP_USERS_DESTRUCTIVE, 'delete_posts' ),
				)
			);
	}

	/**
	 * @param mixed $input Validated exact identity.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input ) {
		if ( ! $this->can_delete( $input ) ) {
			return new WP_Error( 'workspace_delete_denied', __( 'Deletion requires authenticated MCP site-administrator authority and enabled destructive grants.', 'wp-ai-bridge' ) );
		}
		$result = $this->store->delete_exact( $input );
		$kind   = 'document' === $input['kind'] ? 'workspace_document' : 'workspace_task';
		// An Activity failure cannot undo a permanent WordPress deletion.
		// Never replace a verified delete receipt with a logging exception.
		try {
			$this->log->record(
				'wp-ai-bridge/workspace-delete',
				$kind,
				(int) $input['id'],
				! is_wp_error( $result ),
				is_wp_error( $result ) ? (string) $result->get_error_code() : ''
			);
		} catch ( \Throwable $exception ) {
			// Metadata diagnostics are best-effort, not a deletion commit gate.
		}
		return $result;
	}
}
