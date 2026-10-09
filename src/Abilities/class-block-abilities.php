<?php
/**
 * Gutenberg block abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
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
			'wp-ai-bridge/blocks-read',
			array(
				'label'               => __( 'Read Gutenberg Blocks', 'wp-ai-bridge' ),
				'description'         => __( 'Parses one content object into a structured Gutenberg block tree with stable change fingerprints.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->tree_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/blocks-find',
			array(
				'label'               => __( 'Find Gutenberg Blocks', 'wp-ai-bridge' ),
				'description'         => __( 'Scans bounded batches of blocks and returns stable paths and fingerprints without expanding the block tree.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->find_input_schema(),
				'output_schema'       => $this->find_output_schema(),
				'execute_callback'    => array( $this, 'find' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/blocks-mutate',
			array(
				'label'               => __( 'Mutate Gutenberg Blocks', 'wp-ai-bridge' ),
				'description'         => __( 'Appends, inserts, replaces, or removes one Gutenberg block while preserving unrelated blocks and rejecting stale writes.', 'wp-ai-bridge' ),
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
			return new WP_Error( 'unsupported_block_target', __( 'The requested content type is not eligible for generic Gutenberg operations.', 'wp-ai-bridge' ) );
		}
		$identity = $this->tree_identity( $post );
		if ( isset( $input['expected_content_hash'] ) && ! hash_equals( $identity['content_hash'], (string) $input['expected_content_hash'] ) ) {
			return new WP_Error( 'stale_content_conflict', __( 'The content changed after inspection; restart the bounded read.', 'wp-ai-bridge' ) );
		}

		if ( isset( $input['path'] ) ) {
			$segments = $this->parse_path( $input['path'] );
			if ( is_wp_error( $segments ) ) {
				return $segments;
			}
			$target = $this->get_block_at_path( parse_blocks( (string) $post->post_content ), $segments );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			$depth              = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : 0;
			$include_attrs      = ! isset( $input['include_attrs'] ) || true === $input['include_attrs'];
			$identity['blocks'] = array( $this->format_block( $target, (string) $input['path'], 0, $depth, $include_attrs ) );
		} else {
			// Preserve small-page legacy reads, but diagnose oversized reads locally.
			if ( strlen( (string) $post->post_content ) > Bounded_Payload::RESPONSE_BYTES ) {
				return $this->oversized_read_error();
			}
			$identity = $this->format_tree( $post );
		}

		return Bounded_Payload::fits( $identity ) ? $identity : $this->oversized_read_error();
	}

	/**
	 * Scan bounded portions of the complete parsed tree without serializing its output.
	 *
	 * Offsets count visited nodes in canonical pre-order, including non-matches.
	 * Resumed scans require the exact content hash to prevent stale paths.
	 *
	 * @param array<string,mixed> $input Validated discovery filters and window.
	 * @return array<string,mixed>|WP_Error
	 */
	public function find( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post || ! Content_Eligibility::supports_blocks( $post->post_type ) ) {
			return new WP_Error( 'unsupported_block_target', __( 'The requested content type is not eligible for generic Gutenberg operations.', 'wp-ai-bridge' ) );
		}
		if ( isset( $input['attribute_value_contains'] ) && empty( $input['attribute_key'] ) ) {
			return new WP_Error( 'attribute_key_required', __( 'attribute_key is required when attribute_value_contains is set.', 'wp-ai-bridge' ) );
		}
		$identity = $this->tree_identity( $post );
		$offset   = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
		$limit    = isset( $input['limit'] ) ? (int) $input['limit'] : 25;
		$scan     = isset( $input['scan_limit'] ) ? (int) $input['scan_limit'] : 1000;
		$after    = isset( $input['after_path'] ) ? (string) $input['after_path'] : '';
		if ( '' !== $after && $offset > 0 ) {
			return new WP_Error( 'invalid_scan_cursor', __( 'Use either after_path or offset for block discovery, not both.', 'wp-ai-bridge' ) );
		}
		if ( ( $offset > 0 || '' !== $after ) && empty( $input['expected_content_hash'] ) ) {
			return new WP_Error( 'content_identity_required', __( 'A continuation scan requires expected_content_hash from the previous response.', 'wp-ai-bridge' ) );
		}
		if ( isset( $input['expected_content_hash'] ) && ! hash_equals( $identity['content_hash'], (string) $input['expected_content_hash'] ) ) {
			return new WP_Error( 'stale_content_conflict', __( 'The content changed after inspection; restart the block search.', 'wp-ai-bridge' ) );
		}

		$blocks = parse_blocks( (string) $post->post_content );
		if ( '' !== $after ) {
			$segments = $this->parse_path( $after );
			if ( is_wp_error( $segments ) ) {
				return $segments;
			}
			$previous = $this->get_block_at_path( $blocks, $segments );
			if ( is_wp_error( $previous ) ) {
				return $previous;
			}
		}
		$result = $identity + array(
			'items'       => array(),
			'next_offset' => $offset,
			'next_cursor' => $after,
			'scanned'     => 0,
			'walked'      => 0,
			'complete'    => true,
		);
		$index  = 0;
		foreach ( $this->walk_blocks( $blocks, $after ) as $entry ) {
			++$result['walked'];
			// Older offset-only consumers continue to work; cursor consumers
			// start at their path in O(depth), not at document node zero.
			if ( '' === $after && $index++ < $offset ) {
				continue;
			}
			if ( $result['scanned'] >= $scan || count( $result['items'] ) >= $limit ) {
				$result['complete'] = false;
				break;
			}
			$block = $entry['block'];
			if ( $this->matches_find_filters( $block, $input ) ) {
				$children                = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
				$deferred                = ! empty( $children ) && empty( $input['include_container_hash'] );
				$item                    = array(
					'path'            => $entry['path'],
					'name'            => isset( $block['blockName'] ) ? (string) $block['blockName'] : '',
					'block_hash'      => $deferred ? '' : hash( 'sha256', serialize_block( $block ) ),
					'hash_deferred'   => $deferred,
					'content_summary' => $this->direct_block_summary( $block, 160 ),
					'child_count'     => count( $children ),
				);
				$proposed                = $result;
				$proposed['items'][]     = $item;
				$proposed['next_cursor'] = $entry['path'];
				++$proposed['scanned'];
				++$proposed['next_offset'];
				if ( ! Bounded_Payload::fits( $proposed ) ) {
					if ( empty( $result['items'] ) ) {
						return $this->oversized_read_error();
					}
					$result['complete'] = false;
					break;
				}
				$result['items'][] = $item;
			}
			++$result['scanned'];
			++$result['next_offset'];
			$result['next_cursor'] = $entry['path'];
		}

		return $result;
	}

	/**
	 * Iterative pre-order traversal. A cursor initializes the traversal stack
	 * directly at its numeric path: earlier siblings are never revisited.
	 * WordPress still parses the full document before this walker is used.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param string                         $after  Last visited canonical path.
	 * @return iterable<array<string,mixed>>
	 */
	private function walk_blocks( array $blocks, $after = '' ) {
		$stack = array(
			array(
				'blocks' => $blocks,
				'index'  => 0,
				'prefix' => '',
			),
		);
		if ( '' !== $after ) {
			$prefix = '';
			foreach ( explode( '.', $after ) as $part ) {
				$index                  = (int) $part;
				$top                    = count( $stack ) - 1;
				$block                  = $stack[ $top ]['blocks'][ $index ];
				$stack[ $top ]['index'] = $index + 1;
				$prefix                 = '' === $prefix ? (string) $index : $prefix . '.' . $index;
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$stack[] = array(
						'blocks' => $block['innerBlocks'],
						'index'  => 0,
						'prefix' => $prefix,
					);
				}
			}
		}
		while ( ! empty( $stack ) ) {
			$top = count( $stack ) - 1;
			if ( $stack[ $top ]['index'] >= count( $stack[ $top ]['blocks'] ) ) {
				array_pop( $stack );
				continue;
			}
			$index = $stack[ $top ]['index']++;
			$block = $stack[ $top ]['blocks'][ $index ];
			$path  = '' === $stack[ $top ]['prefix'] ? (string) $index : $stack[ $top ]['prefix'] . '.' . $index;
			yield array(
				'path'  => $path,
				'block' => $block,
			);
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$stack[] = array(
					'blocks' => $block['innerBlocks'],
					'index'  => 0,
					'prefix' => $path,
				);
			}
		}
	}

	/** @param array<string,mixed> $block Block. @param array<string,mixed> $filters Search filters. @return bool */
	private function matches_find_filters( array $block, array $filters ) {
		if ( ! empty( $filters['block_name'] ) && (string) ( $block['blockName'] ?? '' ) !== (string) $filters['block_name'] ) {
			return false;
		}
		if ( ! empty( $filters['class_name'] ) ) {
			$class = $block['attrs']['className'] ?? '';
			if ( ! is_string( $class ) || false === stripos( $class, (string) $filters['class_name'] ) ) {
				return false;
			}
		}
		if ( ! empty( $filters['text_contains'] ) && false === stripos( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ), (string) $filters['text_contains'] ) ) {
			return false;
		}
		if ( ! empty( $filters['attribute_key'] ) ) {
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$key   = (string) $filters['attribute_key'];
			if ( ! array_key_exists( $key, $attrs ) ) {
				return false;
			}
			if ( isset( $filters['attribute_value_contains'] ) ) {
				if ( ! is_scalar( $attrs[ $key ] ) || false === stripos( (string) $attrs[ $key ], (string) $filters['attribute_value_contains'] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** @param array<string,mixed> $block Block. @param int $length Max summary bytes. @return string */
	private function direct_block_summary( array $block, $length ) {
		$summary = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) ) );
		if ( strlen( $summary ) <= $length ) {
			return $summary;
		}
		$part = Bounded_Payload::text_window( $summary, 0, $length - 3 );
		return is_wp_error( $part ) ? '' : $part['content'] . '...';
	}

	/** @return WP_Error */
	private function oversized_read_error() {
		return new WP_Error( 'blocks_response_too_large', __( 'The Gutenberg result is too large for a normal MCP response. Use blocks-find with continuation, then blocks-read with path and max_depth, or disable attrs for a large target.', 'wp-ai-bridge' ) );
	}

	/**
	 * Applies one targeted block-tree mutation.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function mutate( $input ) {
		$ability = 'wp-ai-bridge/blocks-mutate';
		$post_id = (int) $input['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post || ! Content_Eligibility::supports_blocks( $post->post_type ) ) {
			return $this->logged_error( 'unsupported_block_target', __( 'The requested content type is not eligible for generic Gutenberg operations.', 'wp-ai-bridge' ), $post_id );
		}

		$current_hash = hash( 'sha256', (string) $post->post_content );
		if ( (string) $post->post_modified_gmt !== (string) $input['expected_modified_gmt'] || $current_hash !== (string) $input['expected_content_hash'] ) {
			$this->log->record( $ability, 'post', $post_id, false, 'stale_content_conflict' );
			return new WP_Error(
				'stale_content_conflict',
				__( 'The content changed after it was inspected. Refresh the block tree before applying this mutation.', 'wp-ai-bridge' ),
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
				return $this->logged_error( 'block_path_required', __( 'path is required for targeted block mutations.', 'wp-ai-bridge' ), $post_id );
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
				return new WP_Error( 'stale_block_conflict', __( 'The target block no longer matches the inspected block fingerprint.', 'wp-ai-bridge' ) );
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

		$this->log->record( 'wp-ai-bridge/blocks-mutate', 'post', $post_id, true, '' );
		$updated              = get_post( $post_id );
		$compact              = $this->tree_identity( $updated );
		$compact['blocks']    = array();
		$compact['truncated'] = true;
		if ( 'summary' === ( $input['response_mode'] ?? 'auto' ) || strlen( (string) $updated->post_content ) > Bounded_Payload::RESPONSE_BYTES ) {
			return $compact;
		}
		$tree = $this->format_tree( $updated );
		return Bounded_Payload::fits( $tree ) ? $tree : $compact;
	}

	/**
	 * Formats the current parsed tree and stable object fingerprints.
	 *
	 * @param object $post Post object.
	 * @return array<string,mixed>
	 */

	private function format_tree( $post ) {
		$result           = $this->tree_identity( $post );
		$result['blocks'] = $this->format_blocks( parse_blocks( (string) $post->post_content ) );
		return $result;
	}

	/** @param object $post Post object. @return array<string,mixed> */
	private function tree_identity( $post ) {
		return array(
			'post_id'      => (int) $post->ID,
			'modified_gmt' => (string) $post->post_modified_gmt,
			'content_hash' => hash( 'sha256', (string) $post->post_content ),
		);
	}

	/**
	 * Recursively format blocks; targeted reads limit depth and optionally attrs.
	 *
	 * @param array<int,array<string,mixed>> $blocks        Parsed blocks.
	 * @param string                         $prefix        Parent path prefix.
	 * @param int                            $depth         Current depth.
	 * @param int|null                       $max_depth     Maximum descendant depth.
	 * @param bool                           $include_attrs Include raw block attributes.
	 * @return array<int,array<string,mixed>>
	 */
	private function format_blocks( array $blocks, $prefix = '', $depth = 0, $max_depth = null, $include_attrs = true ) {
		$result = array();
		foreach ( $blocks as $index => $block ) {
			$path     = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			$result[] = $this->format_block( $block, $path, $depth, $max_depth, $include_attrs );
		}
		return $result;
	}

	/**
	 * @param array<string,mixed> $block Parsed block.
	 * @param string              $path Stable numeric path.
	 * @param int                 $depth Current depth.
	 * @param int|null            $max_depth Maximum child traversal depth.
	 * @param bool                $include_attrs Include attributes.
	 * @return array<string,mixed>
	 */
	private function format_block( array $block, $path, $depth, $max_depth, $include_attrs ) {
		$serialized = serialize_block( $block );
		$summary    = null === $max_depth
			? trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $serialized ) ) )
			: $this->direct_block_summary( $block, 240 );
		if ( strlen( $summary ) > 240 ) {
			$part    = Bounded_Payload::text_window( $summary, 0, 237 );
			$summary = is_wp_error( $part ) ? '' : $part['content'] . '...';
		}
		$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		return array(
			'path'            => $path,
			'name'            => isset( $block['blockName'] ) && null !== $block['blockName'] ? (string) $block['blockName'] : '',
			'attrs'           => $include_attrs && isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(),
			'content_summary' => $summary,
			'block_hash'      => hash( 'sha256', $serialized ),
			'inner_blocks'    => null === $max_depth || $depth < $max_depth
				? $this->format_blocks( $children, $path, $depth + 1, $max_depth, $include_attrs )
				: array(),
		);
	}

	/**
	 * Parses exactly one named Gutenberg block.
	 *
	 * @param mixed $markup Serialized block markup.
	 * @return array<string,mixed>|WP_Error Parsed block or error.
	 */
	private function parse_single_block( $markup ) {
		if ( ! is_string( $markup ) || '' === trim( $markup ) ) {
			return new WP_Error( 'block_markup_required', __( 'block_markup must contain exactly one serialized Gutenberg block.', 'wp-ai-bridge' ) );
		}
		$blocks = parse_blocks( $markup );
		if ( 1 !== count( $blocks ) || empty( $blocks[0]['blockName'] ) ) {
			return new WP_Error( 'invalid_block_markup', __( 'block_markup must contain exactly one named Gutenberg block.', 'wp-ai-bridge' ) );
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
			return new WP_Error( 'invalid_block_path', __( 'path must be a dot-separated numeric block path such as 0 or 1.2.', 'wp-ai-bridge' ) );
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
				return new WP_Error( 'block_not_found', __( 'The target block path does not exist.', 'wp-ai-bridge' ) );
			}
			$block   = $current[ $index ];
			$current = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		}
		return is_array( $block ) ? $block : new WP_Error( 'block_not_found', __( 'The target block path does not exist.', 'wp-ai-bridge' ) );
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
			return new WP_Error( 'block_not_found', __( 'The target block path does not exist.', 'wp-ai-bridge' ) );
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
			return new WP_Error( 'invalid_block_action', __( 'The requested block mutation action is not supported.', 'wp-ai-bridge' ) );
		}

		if ( empty( $blocks[ $index ]['innerBlocks'] ) || ! is_array( $blocks[ $index ]['innerBlocks'] ) ) {
			return new WP_Error( 'block_not_found', __( 'The target nested block path does not exist.', 'wp-ai-bridge' ) );
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
			return new WP_Error( 'unsupported_nested_block_shape', __( 'WordPress did not provide a serializable nested block placeholder for this mutation.', 'wp-ai-bridge' ) );
		}

		$null_positions = array();
		foreach ( $parent_block['innerContent'] as $position => $piece ) {
			if ( null === $piece ) {
				$null_positions[] = $position;
			}
		}
		if ( ! array_key_exists( $child_index, $null_positions ) ) {
			return new WP_Error( 'unsupported_nested_block_shape', __( 'The nested block placeholder layout does not match the parsed child tree.', 'wp-ai-bridge' ) );
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
	 * @return array<string,mixed> Gutenberg targeted-read input contract.
	 */
	private function read_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'path'                  => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 2048,
				),
				'max_depth'             => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 8,
					'default' => 0,
				),
				'include_attrs'         => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'expected_content_hash' => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * @return array<string,mixed> Bounded block-discovery input contract.
	 */
	private function find_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'                  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'block_name'               => array(
					'type'      => 'string',
					'maxLength' => 120,
				),
				'class_name'               => array(
					'type'      => 'string',
					'maxLength' => 120,
				),
				'text_contains'            => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'attribute_key'            => array(
					'type'      => 'string',
					'maxLength' => 120,
				),
				'attribute_value_contains' => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'after_path'               => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 2048,
				),
				'include_container_hash'   => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'offset'                   => array(
					'type'    => 'integer',
					'minimum' => 0,
					'default' => 0,
				),
				'limit'                    => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 25,
				),
				'scan_limit'               => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 2000,
					'default' => 1000,
				),
				'expected_content_hash'    => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * @return array<string,mixed> Flat match entries plus a guarded continuation.
	 */
	private function find_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'      => array( 'type' => 'integer' ),
				'modified_gmt' => array( 'type' => 'string' ),
				'content_hash' => array( 'type' => 'string' ),
				'next_offset'  => array( 'type' => 'integer' ),
				'next_cursor'  => array( 'type' => 'string' ),
				'walked'       => array( 'type' => 'integer' ),
				'scanned'      => array( 'type' => 'integer' ),
				'complete'     => array( 'type' => 'boolean' ),
				'items'        => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'path'            => array( 'type' => 'string' ),
							'name'            => array( 'type' => 'string' ),
							'block_hash'      => array( 'type' => 'string' ),
							'hash_deferred'   => array( 'type' => 'boolean' ),
							'content_summary' => array( 'type' => 'string' ),
							'child_count'     => array( 'type' => 'integer' ),
						),
						'required'             => array( 'path', 'name', 'block_hash', 'content_summary', 'child_count' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'post_id', 'modified_gmt', 'content_hash', 'next_offset', 'next_cursor', 'walked', 'scanned', 'complete', 'items' ),
			'additionalProperties' => false,
		);
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
				'response_mode'         => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'summary' ),
					'default' => 'auto',
				),
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
				'truncated'    => array( 'type' => 'boolean' ),
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
		$this->log->record( 'wp-ai-bridge/blocks-mutate', 'post', (int) $post_id, false, $code );
		return new WP_Error( $code, $message );
	}
}
