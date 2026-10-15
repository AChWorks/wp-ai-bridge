<?php
/**
 * Explicitly privileged, bounded read-only Bridge diagnostics Ability.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Only the current WordPress site's administrator and authenticated MCP client
 * may inspect provider-side diagnostic metadata. This cannot see Gateway errors.
 */
final class Diagnostics_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @var Mutation_Log */
	private $log;

	/** @param Permissions $permissions Bridge delegation. @param Mutation_Log $log Existing Activity log. */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/diagnostics-read',
			array(
				'label'               => __( 'Read Bridge Diagnostics', 'wp-ai-bridge' ),
				'description'         => __( 'Administrator-only, recent sanitized WordPress-side transfer results. No payloads, private paths, or upstream Gateway errors.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => Mutation_Log::LIMIT,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'events'         => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'timestamp'  => array( 'type' => 'string' ),
									'operation'  => array( 'type' => 'string' ),
									'phase'      => array( 'type' => 'string' ),
									'success'    => array( 'type' => 'boolean' ),
									'error_code' => array( 'type' => 'string' ),
									'event_ref'  => array( 'type' => 'string' ),
								),
								'required'             => array( 'timestamp', 'operation', 'phase', 'success', 'error_code', 'event_ref' ),
								'additionalProperties' => false,
							),
						),
						'returned'       => array( 'type' => 'integer' ),
						'has_more'       => array( 'type' => 'boolean' ),
						'retained_limit' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'events', 'returned', 'has_more', 'retained_limit' ),
					'additionalProperties' => false,
				),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute' ),
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
		return get_current_user_id() > 0 &&
			'' !== OAuth_Server::authenticated_mcp_client_id() &&
			current_user_can( 'manage_options' ) &&
			$this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
	}

	/**
	 * @param mixed $input Schema-validated read request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input = array() ) {
		if ( ! $this->can_read() ) {
			return new WP_Error( 'diagnostics_denied', __( 'Authenticated site administrator and delegated Site Read are required.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'limit' ) ) ||
			( isset( $input['limit'] ) &&
				( ! is_int( $input['limit'] ) || $input['limit'] < 1 || $input['limit'] > Mutation_Log::LIMIT ) ) ) {
			return new WP_Error( 'diagnostics_invalid', __( 'The requested diagnostic limit is invalid.', 'wp-ai-bridge' ) );
		}
		return $this->log->diagnostic_view( $input['limit'] ?? 10 );
	}
}
