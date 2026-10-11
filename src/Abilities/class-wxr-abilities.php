<?php
/**
 * Purpose-scoped native WordPress WXR export discovery.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
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
}
