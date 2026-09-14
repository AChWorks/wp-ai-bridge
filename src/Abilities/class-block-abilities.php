<?php
/**
 * Gutenberg block abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Provides generic Gutenberg parsing and targeted block-tree mutation.
 */
final class Block_Abilities {
	/**
	 * Bridge permission service.
	 *
	 * @var Permissions
	 */
	private $permissions;

	/**
	 * Bounded mutation logger.
	 *
	 * @var Mutation_Log
	 */
	private $log;

	/**
	 * Creates the Gutenberg Ability provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Mutation logger.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/**
	 * Registers Gutenberg abilities.
	 *
	 * @return void
	 */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-native-builder/blocks-read',
			array(
				'label'               => __( 'Read Gutenberg Blocks', 'wp-native-builder-bridge' ),
				'description'         => __( 'Parses one content object into a structured Gutenberg block tree with stable change fingerprints.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->tree_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-native-builder/blocks-mutate',
			array(
				'label'               => __( 'Mutate Gutenberg Blocks', 'wp-native-builder-bridge' ),
				'description'         => __( 'Appends, inserts, replaces, or removes one Gutenberg block while preserving unrelated blocks and rejecting stale writes.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->mutate_input_schema(),
				'output_schema'       => $this->tree_schema(),
				'execute_callback'    => array( $this, 'mutate' ),
				'permission_callback' => array( $this, 'can_mutate' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/**
	 * Checks permission for block inspection.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether the current user may read the target blocks.
	 */
	public function can_read( $input ) {
		if ( ! is_array( $input ) || empty( $input['post_id'] ) || ! $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' ) ) {
			return false;
		}

		$post = get_post( (int) $input['post_id'] );
		return $post
			&& Content_Eligibility::supports_blocks( $post->post_type )
			&& current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Checks permission for targeted block mutation.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether the current user may mutate the target blocks.
	 */
	public function can_mutate( $input ) {
		if ( ! is_array( $input ) || empty( $input['post_id'] ) || ! $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'read' ) ) {
			return false;
		}

		$post = get_post( (int) $input['post_id'] );
		if ( ! $post || ! Content_Eligibility::supports_blocks( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		if ( $this->requires_live_access( $post->post_status ) ) {
			$obj = get_post_type_object( $post->post_type );
			return $obj
				&& isset( $obj->cap->publish_posts )
				&& $this->permissions->allowed( Settings::GROUP_LIVE_CONTENT, $obj->cap->publish_posts );
		}

		return true;
	}

	/**
	 * Reads a structured block tree.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post || ! Content_Eligibility::supports_blocks( $post->post_type ) ) {
			return new WP_Error( 'unsupported_block_target', __( 'The requested content type is not eligible for generic Gutenberg operations.', 'wp-native-builder-bridge' ) );
		}

		return $this->format_tree( $post );
	}

	/**
	 * Applies one targeted block-tree mutation.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function mutate( $input ) {
		$ability = 'wp-native-builder/blocks-mutate';
		$post_id = (int) $input['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post || ! Content_Eligibility::supports_blocks( $post->post_type ) ) {
			return $this->logged_error( 'unsupported_block_target', __( 'The requested content type is not eligible for generic Gutenberg operations.', 'wp-native-builder-bridge' ), $post_id );
		}

		$current_hash = hash( 'sha256', (string) $post->post_content );
		if ( (string) $post->post_modified_gmt !== (string) $input['expected_modified_gmt'] || $current_hash !== (string) $input['expected_content_hash'] ) {
			$this->log->record( $ability, 'post', $post_id, false, 'stale_content_conflict' );
			return new WP_Error(
				'stale_content_conflict',
				__( 'The content changed after it was inspected. Refresh the block tree before applying this mutation.', 'wp-native-builder-bridge' ),
				array(
					'current_modified_gmt' => (string) $post->post_modified_gmt,
					'current_content_hash' => $current_hash,
				)
			);
		}

		$action = (string) $input['action'];
		$blocks = parse_blocks( (string) $post->post_content );

		if ( 'append' === $action ) {
			$new_block = $this->parse_single_block( isset( $input['block_markup'] ) ? $input['block_markup'] : '' );
			if ( is_wp_error( $new_block ) ) {
				$this->log->record( $ability, 'post', $post_id, false, $new_block->get_error_code() );
				return $new_block;
			}
			$blocks[] = $new_block;
		} else {
			if ( ! isset( $input['path'] ) || ! is_string( $input['path'] ) || '' === $input['path'] ) {
				return $this->logged_error( 'block_path_required', __( 'path is required for targeted block mutations.', 'wp-native-builder-bridge' ), $post_id );
			}

			$segments = $this->parse_path( $input['path'] );
			if ( is_wp_error( $segments ) ) {
				$this->log->record( $ability, 'post', $post_id, false, $segments->get_error_code() );
				return $segments;
			}

			$target = $this->get_block_at_path( $blocks, $segments );
			if ( is_wp_error( $target ) ) {
				$this->log->record( $ability, 'post', $post_id, false, $target->get_error_code() );
				return $target;
			}

			if ( empty( $input['expected_block_hash'] ) || hash( 'sha256', serialize_block( $target ) ) !== (string) $input['expected_block_hash'] ) {
				$this->log->record( $ability, 'post', $post_id, false, 'stale_block_conflict' );
				return new WP_Error( 'stale_block_conflict', __( 'The target block no longer matches the inspected block fingerprint.', 'wp-native-builder-bridge' ) );
			}

			$new_block = null;
			if ( 'remove' !== $action ) {
				$new_block = $this->parse_single_block( isset( $input['block_markup'] ) ? $input['block_markup'] : '' );
				if ( is_wp_error( $new_block ) ) {
					$this->log->record( $ability, 'post', $post_id, false, $new_block->get_error_code() );
					return $new_block;
				}
			}

			$result = $this->mutate_at_path( $blocks, $segments, $action, $new_block );
			if ( is_wp_error( $result ) ) {
				$this->log->record( $ability, 'post', $post_id, false, $result->get_error_code() );
				return $result;
			}
		}

		$serialized = serialize_blocks( $blocks );
		$result     = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $serialized,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			$this->log->record( $ability, 'post', $post_id, false, $result->get_error_code() );
			return $result;
		}

		$this->log->record( $ability, 'post', $post_id, true, '' );
		return $this->format_tree( get_post( $post_id ) );
	}

	/**
	 * Formats the current parsed tree and stable object fingerprints.
	 *
	 * @param object $post Post object.
	 * @return array<string,mixed>
	 */
	private function format_tree( $post ) {
		$content = (string) $post->post_content;
		return array(
			'post_id'      => (int) $post->ID,
			'modified_gmt' => (string) $post->post_modified_gmt,
			'content_hash' => hash( 'sha256', $content ),
			'blocks'       => $this->format_blocks( parse_blocks( $content ) ),
		);
	}

	/**
	 * Recursively formats blocks with path and block fingerprint.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param string                         $prefix Path prefix.
	 * @return array<int,array<string,mixed>>
	 */
	private function format_blocks( array $blocks, $prefix = '' ) {
		$result = array();
		foreach ( $blocks as $index => $block ) {
			$path       = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			$serialized = serialize_block( $block );
			$summary    = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $serialized ) ) );
			if ( strlen( $summary ) > 240 ) {
				$summary = substr( $summary, 0, 237 ) . '...';
			}
			$result[] = array(
				'path'            => $path,
				'name'            => isset( $block['blockName'] ) && null !== $block['blockName'] ? (string) $block['blockName'] : '',
				'attrs'           => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(),
				'content_summary' => $summary,
				'block_hash'      => hash( 'sha256', $serialized ),
				'inner_blocks'    => $this->format_blocks( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array(), $path ),
			);
		}
		return $result;
	}

	/**
	 * Parses exactly one named Gutenberg block.
	 *
	 * @param mixed $markup Serialized block markup.
	 * @return array<string,mixed>|WP_Error Parsed block or error.
	 */
	private function parse_single_block( $markup ) {
		if ( ! is_string( $markup ) || '' === trim( $markup ) ) {
			return new WP_Error( 'block_markup_required', __( 'block_markup must contain exactly one serialized Gutenberg block.', 'wp-native-builder-bridge' ) );
		}
		$blocks = parse_blocks( $markup );
		if ( 1 !== count( $blocks ) || empty( $blocks[0]['blockName'] ) ) {
			return new WP_Error( 'invalid_block_markup', __( 'block_markup must contain exactly one named Gutenberg block.', 'wp-native-builder-bridge' ) );
		}
		return $blocks[0];
	}

	/**
	 * Parses a dot-separated numeric block path.
	 *
	 * @param mixed $path Block path.
	 * @return array<int,int>|WP_Error Numeric path or error.
	 */
	private function parse_path( $path ) {
		if ( ! is_string( $path ) || ! preg_match( '/^(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))*$/', $path ) ) {
			return new WP_Error( 'invalid_block_path', __( 'path must be a dot-separated numeric block path such as 0 or 1.2.', 'wp-native-builder-bridge' ) );
		}
		return array_map( 'intval', explode( '.', $path ) );
	}

	/**
	 * Gets one parsed block by numeric path.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param array<int,int>                 $path   Numeric path.
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_block_at_path( array $blocks, array $path ) {
		$current = $blocks;
		$block   = null;
		foreach ( $path as $index ) {
			if ( ! array_key_exists( $index, $current ) ) {
				return new WP_Error( 'block_not_found', __( 'The target block path does not exist.', 'wp-native-builder-bridge' ) );
			}
			$block   = $current[ $index ];
			$current = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		}
		return is_array( $block ) ? $block : new WP_Error( 'block_not_found', __( 'The target block path does not exist.', 'wp-native-builder-bridge' ) );
	}

	/**
	 * Mutates a block or nested block while keeping parent innerContent placeholders aligned.
	 *
	 * @param array<int,array<string,mixed>> $blocks    Parsed blocks, by reference.
	 * @param array<int,int>                 $path      Numeric path.
	 * @param string                         $action    Mutation action.
	 * @param array<string,mixed>|null       $new_block Optional new block.
	 * @return true|WP_Error
	 */
	private function mutate_at_path( array &$blocks, array $path, $action, $new_block ) {
		$index = array_shift( $path );
		if ( ! array_key_exists( $index, $blocks ) ) {
			return new WP_Error( 'block_not_found', __( 'The target block path does not exist.', 'wp-native-builder-bridge' ) );
		}

		if ( empty( $path ) ) {
			if ( 'replace' === $action ) {
				$blocks[ $index ] = $new_block;
				return true;
			}
			if ( 'remove' === $action ) {
				array_splice( $blocks, $index, 1 );
				return true;
			}
			if ( 'insert_before' === $action ) {
				array_splice( $blocks, $index, 0, array( $new_block ) );
				return true;
			}
			if ( 'insert_after' === $action ) {
				array_splice( $blocks, $index + 1, 0, array( $new_block ) );
				return true;
			}
			return new WP_Error( 'invalid_block_action', __( 'The requested block mutation action is not supported.', 'wp-native-builder-bridge' ) );
		}

		if ( empty( $blocks[ $index ]['innerBlocks'] ) || ! is_array( $blocks[ $index ]['innerBlocks'] ) ) {
			return new WP_Error( 'block_not_found', __( 'The target nested block path does not exist.', 'wp-native-builder-bridge' ) );
		}

		$child_index           = $path[0];
		$direct_child_mutation = 1 === count( $path );
		$result                = $this->mutate_at_path( $blocks[ $index ]['innerBlocks'], $path, $action, $new_block );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $direct_child_mutation && in_array( $action, array( 'remove', 'insert_before', 'insert_after' ), true ) ) {
			$adjusted = $this->adjust_inner_content( $blocks[ $index ], $child_index, $action );
			if ( is_wp_error( $adjusted ) ) {
				return $adjusted;
			}
		}

		return true;
	}

	/**
	 * Keeps the direct-child null placeholders in innerContent aligned after direct child insertion/removal.
	 *
	 * @param array<string,mixed> $parent_block Parent block, by reference.
	 * @param int                 $child_index Direct child index before mutation.
	 * @param string              $action      Mutation action.
	 * @return true|WP_Error
	 */
	private function adjust_inner_content( array &$parent_block, $child_index, $action ) {
		if ( ! isset( $parent_block['innerContent'] ) || ! is_array( $parent_block['innerContent'] ) ) {
			return new WP_Error( 'unsupported_nested_block_shape', __( 'WordPress did not provide a serializable nested block placeholder for this mutation.', 'wp-native-builder-bridge' ) );
		}

		$null_positions = array();
		foreach ( $parent_block['innerContent'] as $position => $piece ) {
			if ( null === $piece ) {
				$null_positions[] = $position;
			}
		}
		if ( ! array_key_exists( $child_index, $null_positions ) ) {
			return new WP_Error( 'unsupported_nested_block_shape', __( 'The nested block placeholder layout does not match the parsed child tree.', 'wp-native-builder-bridge' ) );
		}

		$position = $null_positions[ $child_index ];
		if ( 'remove' === $action ) {
			array_splice( $parent_block['innerContent'], $position, 1 );
			return true;
		}
		if ( 'insert_before' === $action ) {
			array_splice( $parent_block['innerContent'], $position, 0, array( null ) );
			return true;
		}
		if ( 'insert_after' === $action ) {
			$insert_at = isset( $null_positions[ $child_index + 1 ] ) ? $null_positions[ $child_index + 1 ] : $position + 1;
			array_splice( $parent_block['innerContent'], $insert_at, 0, array( null ) );
			return true;
		}

		return true;
	}

	/**
	 * Determines whether editing the current status requires Live Content access.
	 *
	 * @param string $status Post status.
	 * @return bool Whether Live Content access is required.
	 */
	private function requires_live_access( $status ) {
		$status = (string) $status;
		return '' !== $status && ! in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true );
	}

	/**
	 * Returns the block-mutation input schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function mutate_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'action'                => array(
					'type' => 'string',
					'enum' => array( 'append', 'insert_before', 'insert_after', 'replace', 'remove' ),
				),
				'path'                  => array( 'type' => 'string' ),
				'block_markup'          => array( 'type' => 'string' ),
				'expected_modified_gmt' => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'expected_content_hash' => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
				'expected_block_hash'   => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			'required'             => array( 'post_id', 'action', 'expected_modified_gmt', 'expected_content_hash' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the structured block-tree output schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function tree_schema() {
		$block                                        = array(
			'type'                 => 'object',
			'properties'           => array(
				'path'            => array( 'type' => 'string' ),
				'name'            => array( 'type' => 'string' ),
				'attrs'           => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'content_summary' => array( 'type' => 'string' ),
				'block_hash'      => array( 'type' => 'string' ),
				'inner_blocks'    => array( 'type' => 'array' ),
			),
			'required'             => array( 'path', 'name', 'attrs', 'content_summary', 'block_hash', 'inner_blocks' ),
			'additionalProperties' => false,
		);
		$block['properties']['inner_blocks']['items'] = $block;

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'      => array( 'type' => 'integer' ),
				'modified_gmt' => array( 'type' => 'string' ),
				'content_hash' => array( 'type' => 'string' ),
				'blocks'       => array(
					'type'  => 'array',
					'items' => $block,
				),
			),
			'required'             => array( 'post_id', 'modified_gmt', 'content_hash', 'blocks' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds MCP exposure metadata and tool annotations.
	 *
	 * @param bool $read_only   Whether the Ability is read-only.
	 * @param bool $destructive Whether the Ability is destructive.
	 * @param bool $idempotent  Whether repeated execution is idempotent.
	 * @return array<string,mixed> Ability metadata.
	 */
	private function meta( $read_only, $destructive, $idempotent ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => (bool) $read_only,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent,
			),
		);
	}

	/**
	 * Records a bounded block-mutation failure and returns its WordPress error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $post_id Target post ID.
	 * @return WP_Error Error object.
	 */
	private function logged_error( $code, $message, $post_id ) {
		$this->log->record( 'wp-native-builder/blocks-mutate', 'post', (int) $post_id, false, $code );
		return new WP_Error( $code, $message );
	}
}
