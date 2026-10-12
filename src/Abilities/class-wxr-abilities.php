<?php
/**
 * Purpose-scoped native WordPress WXR export discovery.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Transfer_Session;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_AI_Bridge\Support\WXR_Export_Stager;
use WP_AI_Bridge\Support\WXR_Import_Preflight;
use WP_Error;

/**
 * Discovers exportable native WordPress content; never generates an export.
 */
final class WXR_Abilities {
	const PAGE_LIMIT = 50;

	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Existing Bridge and native authorization. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/wxr-export-discover',
			array(
				'label'               => __( 'Discover WordPress WXR Exports', 'wp-ai-bridge' ),
				'description'         => __( 'Lists WordPress-native exportable content types and exact supported filters; this read-only operation does not create or deliver a WXR file.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'offset'                 => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'limit'                  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::PAGE_LIMIT,
						),
						'expected_registry_hash' => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'blog_id'                 => array( 'type' => 'integer' ),
						'native_export_available' => array( 'type' => 'boolean' ),
						'supports_all_content'    => array( 'type' => 'boolean' ),
						'items'                   => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'post_type'         => array( 'type' => 'string' ),
									'label'             => array( 'type' => 'string' ),
									'supports_author'   => array( 'type' => 'boolean' ),
									'supports_dates'    => array( 'type' => 'boolean' ),
									'supports_status'   => array( 'type' => 'boolean' ),
									'supports_category' => array( 'type' => 'boolean' ),
								),
								'required'             => array( 'post_type', 'label', 'supports_author', 'supports_dates', 'supports_status', 'supports_category' ),
								'additionalProperties' => false,
							),
						),
						'total'                   => array( 'type' => 'integer' ),
						'has_more'                => array( 'type' => 'boolean' ),
						'next_offset'             => array( 'type' => 'integer' ),
						'registry_hash'           => array( 'type' => 'string' ),
						'generates_file'          => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'blog_id', 'native_export_available', 'supports_all_content', 'items', 'total', 'has_more', 'next_offset', 'registry_hash', 'generates_file' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'discover' ),
				'permission_callback' => array( $this, 'can_discover' ),
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
			'wp-ai-bridge/wxr-export-create',
			array(
				'label'               => __( 'Create Private WordPress WXR Export', 'wp-ai-bridge' ),
				'description'         => __( 'Produces a Core WXR export as an authenticated private transfer; read and verify byte windows using private-transfer, then cancel the lease.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'content_type' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 64,
						),
					),
					'required'             => array( 'content_type' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'transfer_id'  => array( 'type' => 'string' ),
						'total_bytes'  => array( 'type' => 'integer' ),
						'sha256'       => array( 'type' => 'string' ),
						'expires_at'   => array( 'type' => 'integer' ),
						'state'        => array( 'type' => 'string' ),
						'blog_id'      => array( 'type' => 'integer' ),
						'content_type' => array( 'type' => 'string' ),
						'mime'         => array( 'type' => 'string' ),
					),
					'required'             => array( 'transfer_id', 'total_bytes', 'sha256', 'expires_at', 'state', 'blog_id', 'content_type', 'mime' ),
					'additionalProperties' => false,
				),
				'permission_callback' => array( $this, 'can_create_export' ),
				'execute_callback'    => array( $this, 'create_export' ),
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
		$registered[] = wp_register_ability(
			'wp-ai-bridge/wxr-import-preview',
			array(
				'label'               => __( 'Preview Verified Private WXR Import', 'wp-ai-bridge' ),
				'description'         => __( 'Read-only structural inspection of an exact verified authenticated WXR lease; no content is imported or modified.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'transfer_id' => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
					),
					'required'             => array( 'transfer_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'blog_id'            => array( 'type' => 'integer' ),
						'content_sha256'     => array( 'type' => 'string' ),
						'content_bytes'      => array( 'type' => 'integer' ),
						'wxr_version'        => array( 'type' => 'string' ),
						'item_count'         => array( 'type' => 'integer' ),
						'post_type_counts'   => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'integer' ),
						),
						'inspected_nodes'    => array( 'type' => 'integer' ),
						'preview_type'       => array( 'type' => 'string' ),
						'authoritative_plan' => array( 'type' => 'boolean' ),
						'mutation_performed' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'blog_id', 'content_sha256', 'content_bytes', 'wxr_version', 'item_count', 'post_type_counts', 'inspected_nodes', 'preview_type', 'authoritative_plan', 'mutation_performed' ),
					'additionalProperties' => false,
				),
				'permission_callback' => array( $this, 'can_preview_import' ),
				'execute_callback'    => array( $this, 'preview_import' ),
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
	public function can_discover() {
		return $this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'export' );
	}

	/**
	 * Export capability is distinct from mere post reading. The actual exporter
	 * emits download headers and XML and must NOT execute from this MCP Ability.
	 *
	 * @param array<string,mixed> $input Requested bounded projection.
	 * @return array<string,mixed>|WP_Error
	 */
	public function discover( $input = array() ) {
		if ( ! $this->can_discover() ) {
			return new WP_Error( 'wxr_export_denied', __( 'Site Configuration and native WordPress export permission are required.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'offset', 'limit', 'expected_registry_hash' ) ) ) {
			return new WP_Error( 'wxr_invalid_input', __( 'Invalid WXR export discovery selector.', 'wp-ai-bridge' ) );
		}

		$offset   = $input['offset'] ?? 0;
		$limit    = $input['limit'] ?? 20;
		$expected = $input['expected_registry_hash'] ?? '';
		if ( ! is_int( $offset ) || $offset < 0 || ! is_int( $limit ) || $limit < 1 || $limit > self::PAGE_LIMIT ||
			! is_string( $expected ) || ( '' !== $expected && 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) ) {
			return new WP_Error( 'wxr_invalid_input', __( 'Invalid WXR export discovery selector.', 'wp-ai-bridge' ) );
		}

		$types = get_post_types( array( 'can_export' => true ), 'objects' );
		if ( ! is_array( $types ) ) {
			return new WP_Error( 'wxr_registry_unavailable', __( 'Native exportable WordPress content types are unavailable.', 'wp-ai-bridge' ) );
		}
		ksort( $types, SORT_STRING );
		$items = array();
		foreach ( $types as $name => $type ) {
			if ( ! is_string( $name ) || ! is_object( $type ) || ! isset( $type->name ) || $name !== $type->name ) {
				return new WP_Error( 'wxr_registry_unavailable', __( 'Native exportable WordPress content types are unavailable.', 'wp-ai-bridge' ) );
			}
			$label = sanitize_text_field( isset( $type->label ) && is_string( $type->label ) ? $type->label : $name );
			if ( strlen( $label ) > 160 ) {
				$label = substr( $label, 0, 160 );
				while ( '' !== $label && 1 !== preg_match( '//u', $label ) ) {
					$label = substr( $label, 0, -1 );
				}
			}
			$native_filtered = in_array( $name, array( 'post', 'page', 'attachment' ), true );
			$items[]         = array(
				'post_type'         => $name,
				'label'             => $label,
				'supports_author'   => $native_filtered,
				'supports_dates'    => $native_filtered,
				'supports_status'   => in_array( $name, array( 'post', 'page' ), true ),
				'supports_category' => 'post' === $name,
			);
		}
		$blog_id  = (int) get_current_blog_id();
		$encoded  = wp_json_encode( array( $blog_id, $items ) );
		$snapshot = hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
		if ( '' !== $expected && ! hash_equals( $expected, $snapshot ) ) {
			return new WP_Error( 'wxr_stale_registry', __( 'Exportable content types changed; restart discovery from offset zero.', 'wp-ai-bridge' ) );
		}

		$total = count( $items );
		$page  = array_slice( $items, $offset, $limit );
		return array(
			'blog_id'                 => $blog_id,
			'native_export_available' => is_readable( ABSPATH . 'wp-admin/includes/export.php' ),
			'supports_all_content'    => true,
			'items'                   => array_values( $page ),
			'total'                   => $total,
			'has_more'                => $offset + count( $page ) < $total,
			'next_offset'             => $offset + count( $page ),
			'registry_hash'           => $snapshot,
			'generates_file'          => false,
		);
	}

	/**
	 * Export includes drafts/private data and must not inherit mere Site Read.
	 *
	 * @return bool Whether the exact current OAuth/client/user/blog may export.
	 */
	public function can_create_export() {
		return get_current_user_id() > 0 &&
			'' !== OAuth_Server::authenticated_mcp_client_id() &&
			$this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'export' ) &&
			( ! is_multisite() || current_user_can( 'manage_network_options' ) );
	}

	/**
	 * Core producer -> private source -> shared frozen byte transport.
	 * No WordPress content import, public URL or arbitrary source paths.
	 *
	 * @param mixed $input Exact requested native content type.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_export( $input ) {
		if ( ! $this->can_create_export() || ! is_array( $input ) ||
			1 !== count( $input ) || ! isset( $input['content_type'] ) ||
			! is_string( $input['content_type'] ) ) {
			return new WP_Error( 'wxr_export_denied', __( 'An authorized WordPress export principal and one native content type are required.', 'wp-ai-bridge' ) );
		}
		$policy   = ( new Transfer_Settings() )->bytes();
		$artifact = ( new WXR_Export_Stager( $this->permissions ) )->stage( $input['content_type'], $policy['file'] );
		if ( is_wp_error( $artifact ) ) {
			return $artifact;
		}
		$store   = new Private_Transfer_Session( $this->permissions );
		$receipt = $store->stage_wxr_export( $artifact, $policy );
		// This generator copy is task-private but NOT the frozen client lease.
		// Do not report success if its confidential bytes could not be erased.
		wp_delete_file( $artifact['path'] );
		if ( file_exists( $artifact['path'] ) ) {
			if ( ! is_wp_error( $receipt ) ) {
				$store->cancel( $receipt['id'] );
			}
			return new WP_Error( 'wxr_export_cleanup_uncertain', __( 'Private WXR producer cleanup requires administrator inspection.', 'wp-ai-bridge' ) );
		}
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		return array(
			'transfer_id'  => $receipt['id'],
			'total_bytes'  => $receipt['total'],
			'sha256'       => $receipt['sha256'],
			'expires_at'   => $receipt['expires'],
			'state'        => $receipt['state'],
			'blog_id'      => (int) get_current_blog_id(),
			'content_type' => $input['content_type'],
			'mime'         => 'application/xml',
		);
	}


	/** Read-only preview requires the same delegated authority as WXR ingress. */
	public function can_preview_import() {
		return get_current_user_id() > 0 &&
			'' !== OAuth_Server::authenticated_mcp_client_id() &&
			$this->permissions->allowed_all(
				array(
					array( Settings::GROUP_SITE_CONFIG, 'import' ),
					array( Settings::GROUP_BUILDER_WRITE, 'edit_posts' ),
				)
			) && ( ! is_multisite() || current_user_can( 'manage_network_options' ) );
	}

	/**
	 * No native importer is executed. This is only a structural preview of
	 * immutable verified client-owned bytes, not consent for a later import.
	 *
	 * @param mixed $input Opaque lease identifier, never an arbitrary path.
	 * @return array<string,mixed>|WP_Error
	 */
	public function preview_import( $input ) {
		if ( ! $this->can_preview_import() || ! is_array( $input ) ||
			1 !== count( $input ) || ! isset( $input['transfer_id'] ) ||
			! is_string( $input['transfer_id'] ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['transfer_id'] ) ) {
			return new WP_Error( 'wxr_preview_denied', __( 'An authorized verified WXR transfer is required to preview an import.', 'wp-ai-bridge' ) );
		}
		$policy = ( new Transfer_Settings() )->bytes();
		// Resource limits track the operator-owned file allowance instead of
		// creating a fixed product-wide maximum object or XML node count.
		$nodes  = $policy['file'] > intdiv( PHP_INT_MAX, 4 ) ? PHP_INT_MAX : $policy['file'] * 4;
		$result = ( new WXR_Import_Preflight( $this->permissions ) )->inspect_transfer(
			$input['transfer_id'],
			$policy['file'],
			$nodes,
			$policy['file']
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! Bounded_Payload::fits( $result ) ) {
			return new WP_Error( 'wxr_preview_response_budget', __( 'WXR summary exceeds the configured MCP response budget.', 'wp-ai-bridge' ) );
		}
		return $result;
	}
}
