<?php
/**
 * Separately permissioned official WordPress Importer plan and execution.
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\WXR_Import_Service;
use WP_Error;

/**
 * The imported WXR is always a verified, client-scoped private transfer.
 * A read-only plan has no WordPress mutation and cannot authorize execution
 * without the separate exact-plan-hash + explicit confirmation step.
 */
final class WXR_Import_Abilities {
	/** @var WXR_Import_Service */
	private $service;

	/** @param Permissions $permissions Delegated Bridge/native privileges. */
	public function __construct( Permissions $permissions ) {
		$this->service = new WXR_Import_Service( $permissions );
	}

	/** @return array<int,object> Registered public WordPress native Abilities. */
	public function register() {
		$choices                                     = array(
			'transfer_id'    => array(
				'type'      => 'string',
				'minLength' => 64,
				'maxLength' => 64,
			),
			'author_mapping' => array(
				'type'                 => 'object',
				'additionalProperties' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'on_conflict'    => array(
				'type' => 'string',
				'enum' => array( 'reject', 'allow_existing' ),
			),
		);
		$base_input                                  = array(
			'type'                 => 'object',
			'properties'           => $choices,
			'required'             => array( 'transfer_id' ),
			'additionalProperties' => false,
		);
		$plan_output                                 = array(
			'type'                 => 'object',
			'properties'           => array(
				'transfer_id'           => array( 'type' => 'string' ),
				'plan_token'            => array( 'type' => 'string' ),
				'blog_id'               => array( 'type' => 'integer' ),
				'content_sha256'        => array( 'type' => 'string' ),
				'content_bytes'         => array( 'type' => 'integer' ),
				'item_count'            => array( 'type' => 'integer' ),
				'post_type_counts'      => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'integer' ),
				),
				'author_count'          => array( 'type' => 'integer' ),
				'author_names'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'author_mapping'        => $choices['author_mapping'],
				'default_author_id'     => array( 'type' => 'integer' ),
				'conflict_count'        => array( 'type' => 'integer' ),
				'publish_candidates'    => array( 'type' => 'integer' ),
				'on_conflict'           => array( 'type' => 'string' ),
				'attachment_candidates' => array( 'type' => 'integer' ),
				'fetch_attachments'     => array( 'type' => 'boolean' ),
				'create_users'          => array( 'type' => 'boolean' ),
				'rewrite_urls'          => array( 'type' => 'boolean' ),
				'native_provider'       => array( 'type' => 'string' ),
				'import_performed'      => array( 'type' => 'boolean' ),
				'partial_possible'      => array( 'type' => 'boolean' ),
			),
			'required'             => array(
				'transfer_id',
				'plan_token',
				'blog_id',
				'content_sha256',
				'content_bytes',
				'item_count',
				'post_type_counts',
				'author_count',
				'author_names',
				'author_mapping',
				'default_author_id',
				'conflict_count',
				'publish_candidates',
				'on_conflict',
				'attachment_candidates',
				'fetch_attachments',
				'create_users',
				'rewrite_urls',
				'native_provider',
				'import_performed',
				'partial_possible',
			),
			'additionalProperties' => false,
		);
		$execution_input                             = $base_input;
		$execution_input['properties']['plan_token'] = array(
			'type'      => 'string',
			'minLength' => 64,
			'maxLength' => 64,
		);
		$execution_input['properties']['confirm']    = array(
			'type'  => 'boolean',
			'const' => true,
		);
		$execution_input['required']                 = array( 'transfer_id', 'plan_token', 'confirm' );

		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/wxr-import-plan',
			array(
				'label'               => __( 'Plan Official WordPress WXR Import', 'wp-ai-bridge' ),
				'description'         => __( 'Reads a verified WXR private transfer with the installed official Importer, detects conflicts and author mappings, and returns a principal-bound consent token without mutating WordPress.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $base_input,
				'output_schema'       => $plan_output,
				'permission_callback' => array( $this, 'can_import' ),
				'execute_callback'    => array( $this, 'plan' ),
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
		$registered[] = wp_register_ability(
			'wp-ai-bridge/wxr-import-execute',
			array(
				'label'               => __( 'Execute Reviewed WordPress WXR Import', 'wp-ai-bridge' ),
				'description'         => __( 'Once-only, explicitly confirmed native WordPress Importer mutation on the exact private WXR plan. No new users or remote media downloads. Native effects are non-atomic; a timeout must NOT be retried.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $execution_input,
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'transfer_id'              => array( 'type' => 'string' ),
						'plan_token'               => array( 'type' => 'string' ),
						'state'                    => array( 'type' => 'string' ),
						'outcome'                  => array( 'type' => 'string' ),
						'planned_items'            => array( 'type' => 'integer' ),
						'processed_posts'          => array( 'type' => 'integer' ),
						'conflict_count'           => array( 'type' => 'integer' ),
						'created_users'            => array( 'type' => 'boolean' ),
						'fetched_attachments'      => array( 'type' => 'boolean' ),
						'native_provider_returned' => array( 'type' => 'boolean' ),
						'partial_possible'         => array( 'type' => 'boolean' ),
						'needs_inspection'         => array( 'type' => 'boolean' ),
					),
					'required'             => array(
						'transfer_id',
						'plan_token',
						'state',
						'outcome',
						'planned_items',
						'processed_posts',
						'conflict_count',
						'created_users',
						'fetched_attachments',
						'native_provider_returned',
						'partial_possible',
						'needs_inspection',
					),
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
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */
	public function can_import() {
		return $this->service->can_execute();
	}

	/** @param mixed $input Exact verified transfer/author/conflict selectors. @return array<string,mixed>|WP_Error */
	public function plan( $input ) {
		$result = $this->service->plan( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! Bounded_Payload::fits( $result ) ) {
			return new WP_Error( 'wxr_import_plan_response_budget', __( 'Native import plan exceeds the configured MCP response budget.', 'wp-ai-bridge' ) );
		}
		return $result;
	}

	/** @param mixed $input Explicit SHA-bound confirmation. @return array<string,mixed>|WP_Error */
	public function execute( $input ) {
		return $this->service->execute( $input );
	}
}
