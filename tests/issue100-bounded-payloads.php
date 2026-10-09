<?php
/**
 * Isolated regression for Issue #100 bounded MCP payloads.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Block_Abilities;
use WP_AI_Bridge\Abilities\Content_Abilities;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

$assertions = 0;

function wpai_issue100_assert( $passed, $message ) {
	global $assertions;
	++$assertions;
	if ( ! $passed ) {
		throw new RuntimeException( $message );
	}
}

function get_post( $id ) {
	return $GLOBALS['wpai_issue100_posts'][ (int) $id ] ?? null;
}

function parse_blocks( $markup ) {
	$value = json_decode( (string) $markup, true );
	return is_array( $value ) ? $value : array();
}

function serialize_block( $block ) {
	return json_encode( $block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

function serialize_blocks( $blocks ) {
	return json_encode( array_values( $blocks ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function wp_update_post( $data, $return_error = false ) {
	$post = get_post( $data['ID'] );
	if ( ! $post ) {
		return new WP_Error( 'missing_post' );
	}
	foreach ( $data as $key => $value ) {
		if ( 'ID' !== $key ) {
			$post->{$key} = $value;
		}
	}
	$post->post_modified_gmt = '2026-10-09 16:05:00';
	return $post->ID;
}

function wpai_issue100_block( $name, $text ) {
	return array(
		'blockName'    => $name,
		'attrs'        => array( 'className' => 'target-' . $name ),
		'innerBlocks'  => array(),
		'innerHTML'    => '<p>' . $text . '</p>',
		'innerContent' => array( '<p>' . $text . '</p>' ),
	);
}

wpai_test_reset_state();
$GLOBALS['wpai_test']['capabilities']['read_post']  = true;
$GLOBALS['wpai_test']['capabilities']['edit_post']  = true;
$GLOBALS['wpai_test']['capabilities']['edit_pages'] = true;
$GLOBALS['wpai_test']['post_types']['page']         = (object) array(
	'name'               => 'page',
	'cap'                => (object) array(
		'publish_posts' => 'publish_pages',
		'edit_posts'    => 'edit_pages',
	),
	'public'             => true,
	'publicly_queryable' => true,
	'show_ui'            => true,
	'show_in_rest'       => true,
);
$GLOBALS['wpai_test']['post_type_supports']['page'] = array( 'editor' => true );
$settings                                = new Settings();
$access                                  = $settings->defaults();
$access[ Settings::GROUP_BUILDER_WRITE ] = 1;
update_option( Settings::OPTION_NAME, $access, false );
$permissions = new Permissions( $settings );
$blocks      = new Block_Abilities( $permissions, new Mutation_Log() );
$content     = new Content_Abilities( $permissions, new Mutation_Log() );

$fixture = array();
for ( $i = 0; $i < 2400; ++$i ) {
	$fixture[] = wpai_issue100_block( 'core/paragraph', 'Normal ' . $i );
}
$group                          = wpai_issue100_block( 'core/buttons', '' );
$group['innerBlocks']           = array( wpai_issue100_block( 'core/button', 'دکمه هدف نهایی' ) );
$group['innerContent']          = array( '<div>', null, '</div>' );
$fixture[]                      = $group;
$post                           = (object) array(
	'ID'                => 100,
	'post_type'         => 'page',
	'post_status'       => 'draft',
	'post_modified_gmt' => '2026-10-09 12:00:00',
	'post_content'      => serialize_blocks( $fixture ),
	'post_name'         => 'large-page',
	'post_title'        => 'Large page',
	'post_excerpt'      => '',
	'post_parent'       => 0,
	'menu_order'        => 0,
);
$GLOBALS['wpai_issue100_posts'] = array( 100 => $post );

wpai_issue100_assert( isset( $GLOBALS['wpai_test']['post_types']['page'] ), 'Missing post-type fixture.' );
$registered = $blocks->register();
wpai_issue100_assert( 3 === count( $registered ), 'Bounded blocks-find must be registered alongside read/mutate.' );
$legacy = $blocks->read( array( 'post_id' => 100 ) );
wpai_issue100_assert( is_wp_error( $legacy ) && 'blocks_response_too_large' === $legacy->get_error_code(), 'Oversized legacy read should return a specific actionable error.' );

$first = $blocks->find(
	array(
		'post_id'    => 100,
		'block_name' => 'core/button',
		'scan_limit' => 300,
	)
);
wpai_issue100_assert( ! is_wp_error( $first ) && ! $first['complete'] && $first['scanned'] === 300 && empty( $first['items'] ), 'Initial bounded block scan must continue without expanding the tree.' );
// Efficient resumable cursor: every continuation visits only the requested
// new batch, even after thousands of preceding document nodes.
$cursor = $first['next_cursor'];
$cursor_found = null;
$cursor_walked = 0;
for ( $batch_number = 0; $batch_number < 11; ++$batch_number ) {
	$cursor_batch = $blocks->find(
		array(
			'post_id'               => 100,
			'after_path'            => $cursor,
			'scan_limit'            => 300,
			'block_name'            => 'core/button',
			'expected_content_hash' => $first['content_hash'],
		)
	);
	wpai_issue100_assert( ! is_wp_error( $cursor_batch ) && $cursor_batch['walked'] <= 301, 'A path continuation replayed already visited blocks.' );
	$cursor_walked += $cursor_batch['walked'];
	if ( ! empty( $cursor_batch['items'] ) ) {
		$cursor_found = $cursor_batch['items'][0];
		break;
	}
	$cursor = $cursor_batch['next_cursor'];
}
wpai_issue100_assert( is_array( $cursor_found ) && '2400.0' === $cursor_found['path'] && $cursor_walked <= 2600, 'Path cursor failed to find a late nested target without quadratic traversal.' );
$bad_filters = $blocks->find( array( 'post_id' => 100, 'attribute_value_contains' => 'target' ) );
wpai_issue100_assert( is_wp_error( $bad_filters ) && 'attribute_key_required' === $bad_filters->get_error_code(), 'Dependent attribute filter must not be silently ignored.' );
$cursor_with_offset = $blocks->find( array( 'post_id' => 100, 'after_path' => $cursor, 'offset' => 1, 'expected_content_hash' => $first['content_hash'] ) );
wpai_issue100_assert( is_wp_error( $cursor_with_offset ) && 'invalid_scan_cursor' === $cursor_with_offset->get_error_code(), 'Two conflicting continuation modes must be rejected.' );

$parent_match = $blocks->find( array( 'post_id' => 100, 'block_name' => 'core/buttons', 'scan_limit' => 2000 ) );
wpai_issue100_assert( ! is_wp_error( $parent_match ) && empty( $parent_match['items'] ), 'The container should be beyond the early search window.' );
$parent_match = $blocks->find( array( 'post_id' => 100, 'block_name' => 'core/buttons', 'after_path' => $parent_match['next_cursor'], 'expected_content_hash' => $first['content_hash'] ) );
wpai_issue100_assert( ! is_wp_error( $parent_match ) && true === $parent_match['items'][0]['hash_deferred'] && '' === $parent_match['items'][0]['block_hash'], 'Container fingerprint should be deferred until targeted inspection.' );
$parent_read = $blocks->read( array( 'post_id' => 100, 'path' => '2400', 'max_depth' => 0 ) );
wpai_issue100_assert( ! is_wp_error( $parent_read ) && 64 === strlen( $parent_read['blocks'][0]['block_hash'] ), 'A deferred container fingerprint must be available by path.' );

$offset = $first['next_offset'];
for ( $step = 0; $step < 10; ++$step ) {
	$next = $blocks->find(
		array(
			'post_id'               => 100,
			'offset'                => $offset,
			'scan_limit'            => 300,
			'block_name'            => 'core/button',
			'expected_content_hash' => $first['content_hash'],
		)
	);
	wpai_issue100_assert( ! is_wp_error( $next ) && Bounded_Payload::fits( $next ), 'Continuation must stay inside response budget.' );
	if ( ! empty( $next['items'] ) ) {
		break;
	}
	$offset = $next['next_offset'];
}
wpai_issue100_assert( ! empty( $next['items'] ) && '2400.0' === $next['items'][0]['path'], 'Search failed to locate the target beyond the early tree pages.' );
$target      = $next['items'][0];
$text_result = $blocks->find(
	array(
		'post_id'       => 100,
		'text_contains' => 'هدف نهایی',
		'limit'         => 2,
		'scan_limit'    => 2000,
	)
);
wpai_issue100_assert( ! is_wp_error( $text_result ) && empty( $text_result['items'] ) && $text_result['next_offset'] === 2000, 'Text-filtered scans must remain bounded and offer continuation.' );
$text_result = $blocks->find(
	array(
		'post_id'               => 100,
		'text_contains'         => 'هدف نهایی',
		'offset'                => $text_result['next_offset'],
		'expected_content_hash' => $first['content_hash'],
	)
);
wpai_issue100_assert( ! is_wp_error( $text_result ) && '2400.0' === $text_result['items'][0]['path'], 'Unicode text-filtered discovery missed a late block.' );
$read = $blocks->read(
	array(
		'post_id'               => 100,
		'path'                  => $target['path'],
		'max_depth'             => 0,
		'expected_content_hash' => $first['content_hash'],
	)
);
wpai_issue100_assert( ! is_wp_error( $read ) && 'core/button' === $read['blocks'][0]['name'] && Bounded_Payload::fits( $read ), 'Targeted read must fit and preserve the exact numeric path.' );
wpai_issue100_assert( $target['block_hash'] === $read['blocks'][0]['block_hash'], 'Finder fingerprint must interoperate with targeted-read fingerprint.' );

$changed = $blocks->mutate(
	array(
		'post_id'               => 100,
		'action'                => 'replace',
		'path'                  => '2400.0',
		'block_markup'          => serialize_blocks( array( wpai_issue100_block( 'core/button', 'جایگزین' ) ) ),
		'expected_modified_gmt' => $read['modified_gmt'],
		'expected_content_hash' => $read['content_hash'],
		'expected_block_hash'   => $target['block_hash'],
	)
);
wpai_issue100_assert( ! is_wp_error( $changed ) && ! empty( $changed['truncated'] ) && empty( $changed['blocks'] ), 'Successful large mutation must return a bounded explicit confirmation.' );
wpai_issue100_assert( Bounded_Payload::fits( $changed ), 'Large mutation confirmation exceeded the budget.' );
wpai_issue100_assert( 'core/paragraph' === parse_blocks( $post->post_content )[0]['blockName'], 'Targeted write damaged unrelated blocks.' );
wpai_issue100_assert( 'جایگزین' === strip_tags( parse_blocks( $post->post_content )[2400]['innerBlocks'][0]['innerHTML'] ), 'Targeted write was not persisted.' );
wpai_issue100_assert( hash( 'sha256', $post->post_content ) === $changed['content_hash'], 'Mutation confirmation did not identify the committed content.' );

$stale = $blocks->find(
	array(
		'post_id'               => 100,
		'offset'                => 300,
		'expected_content_hash' => $first['content_hash'],
	)
);
wpai_issue100_assert( is_wp_error( $stale ) && 'stale_content_conflict' === $stale->get_error_code(), 'Stale scan must be rejected.' );
$stale_read = $blocks->read(
	array(
		'post_id'               => 100,
		'path'                  => '2400.0',
		'expected_content_hash' => $first['content_hash'],
	)
);
wpai_issue100_assert( is_wp_error( $stale_read ) && 'stale_content_conflict' === $stale_read->get_error_code(), 'Stale targeted read must be rejected.' );
$without_identity = $blocks->find(
	array(
		'post_id' => 100,
		'offset'  => 300,
	)
);
wpai_issue100_assert( is_wp_error( $without_identity ) && 'content_identity_required' === $without_identity->get_error_code(), 'Continuation cannot be unguarded.' );

$GLOBALS['wpai_test']['capabilities']['read_post'] = false;
wpai_issue100_assert( ! $blocks->can_read( array( 'post_id' => 100 ) ), 'Block discovery bypassed WordPress read authority.' );
$GLOBALS['wpai_test']['capabilities']['read_post'] = true;
$access[ Settings::GROUP_SITE_READ ]               = 0;
update_option( Settings::OPTION_NAME, $access, false );
wpai_issue100_assert( ! $blocks->can_read( array( 'post_id' => 100 ) ), 'Block discovery bypassed Bridge delegation.' );
$access[ Settings::GROUP_SITE_READ ] = 1;
update_option( Settings::OPTION_NAME, $access, false );

$body               = str_repeat( 'سلام WordPress دنیا! ', 2100 );
$post->post_content = $body;
$metadata           = $content->read(
	array(
		'action' => 'get',
		'id'     => 100,
	)
);
wpai_issue100_assert( ! is_wp_error( $metadata ) && false === $metadata['items'][0]['content_complete'], 'Large raw content should be discoverable through metadata, not dumped.' );
$hash   = $metadata['items'][0]['content_hash'];
$joined = '';
$offset = 0;
do {
	$part = $content->read(
		array(
			'action'                => 'get',
			'id'                    => 100,
			'content_offset'        => $offset,
			'content_max_bytes'     => 4096,
			'expected_content_hash' => $hash,
		)
	);
	wpai_issue100_assert( ! is_wp_error( $part ) && Bounded_Payload::fits( $part ), 'UTF-8 content window did not fit.' );
	$item = $part['items'][0];
	wpai_issue100_assert( $item['content_next_offset'] > $offset, 'Window offset did not advance.' );
	$joined .= $item['content'];
	$offset  = $item['content_next_offset'];
} while ( ! $item['content_complete'] );
wpai_issue100_assert( $body === $joined && hash( 'sha256', $joined ) === $hash, 'UTF-8 windows did not reconstruct exact content.' );
$adaptive = $content->read(
	array(
		'action'            => 'get',
		'id'                => 100,
		'content_offset'    => 0,
		'content_max_bytes' => Bounded_Payload::TEXT_WINDOW_BYTES,
	)
);
wpai_issue100_assert( ! is_wp_error( $adaptive ) && Bounded_Payload::fits( $adaptive ) && $adaptive['items'][0]['content_next_offset'] > 0, 'A maximal Unicode window must shrink automatically to fit the encoded response budget.' );
$invalid_offset = $content->read(
	array(
		'action'                => 'get',
		'id'                    => 100,
		'content_offset'        => 1,
		'expected_content_hash' => $hash,
	)
);
wpai_issue100_assert( is_wp_error( $invalid_offset ) && 'invalid_content_window' === $invalid_offset->get_error_code(), 'Middle-of-codepoint offsets must be rejected.' );
$post->post_content = 'changed';
$stale_part         = $content->read(
	array(
		'action'                => 'get',
		'id'                    => 100,
		'content_offset'        => 3,
		'expected_content_hash' => $hash,
	)
);
wpai_issue100_assert( is_wp_error( $stale_part ) && 'stale_content_conflict' === $stale_part->get_error_code(), 'Windows must fail closed on stale content.' );

// A 100 KiB title and excerpt are legitimate stored content, not reasons
// to turn a committed write into an ambiguous downstream transport error.
$post->post_content = $body;
$post->post_title   = str_repeat( 'عنوان طولانی فارسی ', 8000 );
$post->post_excerpt = str_repeat( 'خلاصه گسترده ', 9000 );
$oversized_metadata = $content->read( array( 'action' => 'get', 'id' => 100, 'content_offset' => 0, 'content_max_bytes' => 4096 ) );
wpai_issue100_assert( ! is_wp_error( $oversized_metadata ) && Bounded_Payload::fits( $oversized_metadata ) && ! empty( $oversized_metadata['items'][0]['projection_truncated'] ), 'A giant metadata projection must fall back to compact identity without blocking content windows.' );
$compact_item = $oversized_metadata['items'][0];
wpai_issue100_assert( $compact_item['content_hash'] === hash( 'sha256', $body ) && in_array( 'title', $compact_item['omitted_fields'], true ), 'Compact projection lost content identity or omitted-field metadata.' );
$metadata_ack = new ReflectionMethod( Content_Abilities::class, 'format_post_after_mutation' );
$committed_ack = $metadata_ack->invoke( $content, $post );
wpai_issue100_assert( Bounded_Payload::fits( $committed_ack ) && ! empty( $committed_ack['projection_truncated'] ) && false === $committed_ack['content_complete'], 'A successful large-content write must have a bounded compact confirmation.' );
wpai_issue100_assert( $committed_ack['state_hash'] === $compact_item['state_hash'] && $committed_ack['content_hash'] === hash( 'sha256', $body ), 'Committed identity must describe actual stored content and metadata.' );
foreach ( array( 'title' => $post->post_title, 'excerpt' => $post->post_excerpt ) as $field => $expected_text ) {
	$joined_field = '';
	$field_offset = 0;
	do {
		$field_part = $content->read(
			array(
				'action'              => 'get',
				'id'                  => 100,
				'text_field'          => $field,
				'text_offset'         => $field_offset,
				'text_max_bytes'      => 4096,
				'expected_state_hash' => $compact_item['state_hash'],
			)
		);
		wpai_issue100_assert( ! is_wp_error( $field_part ) && Bounded_Payload::fits( $field_part ), 'Targeted metadata window exceeded the MCP bound.' );
		$field_item = $field_part['items'][0];
		wpai_issue100_assert( $field_item['text_next_offset'] > $field_offset && $field_item['text_hash'] === hash( 'sha256', $expected_text ), 'Targeted field continuation lacks monotonic progress or stable field identity.' );
		$joined_field .= $field_item['text_chunk'];
		$field_offset = $field_item['text_next_offset'];
	} while ( ! $field_item['text_complete'] );
	wpai_issue100_assert( $joined_field === $expected_text, 'Large title or excerpt could not be reconstructed byte for byte.' );
}
$bad_field_identity = $content->read( array( 'action' => 'get', 'id' => 100, 'text_field' => 'title', 'text_offset' => 4, 'expected_state_hash' => str_repeat( 'f', 64 ) ) );
wpai_issue100_assert( is_wp_error( $bad_field_identity ) && 'stale_content_conflict' === $bad_field_identity->get_error_code(), 'Stale title/excerpt windows must fail closed.' );



// Deeply nested fixture: the finder must remain iterative and the target read flat.
$leaf = wpai_issue100_block( 'core/button', 'deep-target' );
for ( $depth = 0; $depth < 96; ++$depth ) {
	$group                 = wpai_issue100_block( 'core/group', '' );
	$group['innerBlocks']  = array( $leaf );
	$group['innerContent'] = array( '<div>', null, '</div>' );
	$leaf                  = $group;
}
$post->post_content = serialize_blocks( array( $leaf ) );
$first_deep         = $blocks->find(
	array(
		'post_id'    => 100,
		'block_name' => 'core/button',
		'scan_limit' => 50,
	)
);
wpai_issue100_assert( ! is_wp_error( $first_deep ) && false === $first_deep['complete'] && 50 === $first_deep['next_offset'], 'Deep traversal should stop at the scan budget.' );
$second_deep = $blocks->find(
	array(
		'post_id'               => 100,
		'offset'                => $first_deep['next_offset'],
		'scan_limit'            => 60,
		'block_name'            => 'core/button',
		'expected_content_hash' => $first_deep['content_hash'],
	)
);
wpai_issue100_assert( ! is_wp_error( $second_deep ) && count( $second_deep['items'] ) === 1, 'Deep subtree continuation lost the leaf.' );
$deep_path = str_repeat( '0.', 96 ) . '0';
wpai_issue100_assert( $deep_path === $second_deep['items'][0]['path'], 'Deep path was not canonical.' );
$deep_read = $blocks->read(
	array(
		'post_id'   => 100,
		'path'      => $deep_path,
		'max_depth' => 0,
	)
);
wpai_issue100_assert( ! is_wp_error( $deep_read ) && Bounded_Payload::fits( $deep_read ), 'A deep targeted read overflowed or returned an invalid block.' );

echo "PASS: {$assertions} bounded Gutenberg/raw-content assertions.\n";
