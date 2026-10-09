<?php
/**
 * Actual WordPress Abilities/Adapter coverage for Issue #100.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Settings;

function wpai_issue100_real_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$old_settings                            = get_option( Settings::OPTION_NAME, array() );
$settings                                = new Settings();
$access                                  = $settings->defaults();
$access[ Settings::GROUP_SITE_READ ]     = 1;
$access[ Settings::GROUP_BUILDER_WRITE ] = 1;
update_option( Settings::OPTION_NAME, $access, false );

$post_id = 0;
try {
	$markup = '';
	for ( $i = 0; $i < 960; ++$i ) {
		$markup .= '<!-- wp:paragraph --><p>Text ' . $i . '</p><!-- /wp:paragraph -->';
	}
	$markup .= '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link">Target Button</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Issue 100 bounded integration',
			'post_content' => $markup,
		),
		true
	);
	wpai_issue100_real_assert( ! is_wp_error( $post_id ), 'Could not create the isolated large Gutenberg draft.' );

	$blocks_read   = wp_get_ability( 'wp-ai-bridge/blocks-read' );
	$blocks_find   = wp_get_ability( 'wp-ai-bridge/blocks-find' );
	$blocks_mutate = wp_get_ability( 'wp-ai-bridge/blocks-mutate' );
	$content_read  = wp_get_ability( 'wp-ai-bridge/content-read' );
	wpai_issue100_real_assert( $blocks_read && $blocks_find && $blocks_mutate && $content_read, 'Bounded Abilities were not registered.' );

	$adapter_info = wp_get_ability( 'mcp-adapter/get-ability-info' )->execute( array( 'ability_name' => 'wp-ai-bridge/blocks-find' ) );
	wpai_issue100_real_assert( ! is_wp_error( $adapter_info ) && 'wp-ai-bridge/blocks-find' === $adapter_info['name'], 'MCP Adapter cannot discover the new contract.' );
	$adapter_find = wp_get_ability( 'mcp-adapter/execute-ability' )->execute(
		array(
			'ability_name' => 'wp-ai-bridge/blocks-find',
			'parameters'   => array(
				'post_id'    => $post_id,
				'block_name' => 'core/button',
				'scan_limit' => 25,
			),
		)
	);
	wpai_issue100_real_assert( ! is_wp_error( $adapter_find ) && true === $adapter_find['success'] && 25 === $adapter_find['data']['scanned'], 'MCP Adapter could not execute a bounded block search.' );

	$legacy = $blocks_read->execute( array( 'post_id' => $post_id ) );
	wpai_issue100_real_assert( is_wp_error( $legacy ) && 'blocks_response_too_large' === $legacy->get_error_code(), 'The large full tree must provide a bounded error and alternative.' );
	$first = $blocks_find->execute(
		array(
			'post_id'    => $post_id,
			'block_name' => 'core/button',
			'scan_limit' => 400,
		)
	);
	wpai_issue100_real_assert( ! is_wp_error( $first ) && empty( $first['items'] ) && ! $first['complete'], 'Bounded discovery did not return a continuation.' );
	$cursor = $first['next_offset'];
	$found  = null;
	for ( $step = 0; $step < 4; ++$step ) {
		$batch = $blocks_find->execute(
			array(
				'post_id'               => $post_id,
				'block_name'            => 'core/button',
				'offset'                => $cursor,
				'scan_limit'            => 400,
				'expected_content_hash' => $first['content_hash'],
			)
		);
		wpai_issue100_real_assert( ! is_wp_error( $batch ) && Bounded_Payload::fits( $batch ), 'A block-discovery continuation did not complete within bounds.' );
		if ( ! empty( $batch['items'] ) ) {
			$found = $batch['items'][0];
			break;
		}
		$cursor = $batch['next_offset'];
	}
	wpai_issue100_real_assert( is_array( $found ) && '960.0' === $found['path'], 'Late nested button could not be found by canonical path.' );

	$target = $blocks_read->execute(
		array(
			'post_id'               => $post_id,
			'path'                  => $found['path'],
			'max_depth'             => 0,
			'expected_content_hash' => $first['content_hash'],
		)
	);
	wpai_issue100_real_assert( ! is_wp_error( $target ) && $found['block_hash'] === $target['blocks'][0]['block_hash'], 'Target path/read hash did not agree with the finder.' );


	// Validate the real MCP Adapter response, not merely the direct WP_Ability.
	$adapter_mutate = wp_get_ability( 'mcp-adapter/execute-ability' )->execute(
		array(
			'ability_name' => 'wp-ai-bridge/blocks-mutate',
			'parameters'   => array(
				'post_id'               => $post_id,
				'action'                => 'replace',
				'path'                  => $found['path'],
				'block_markup'          => '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link">Changed Button</a></div><!-- /wp:button -->',
				'expected_modified_gmt' => $target['modified_gmt'],
				'expected_content_hash' => $target['content_hash'],
				'expected_block_hash'   => $found['block_hash'],
			),
		)
	);
	wpai_issue100_real_assert(
		! is_wp_error( $adapter_mutate ) && true === $adapter_mutate['success'] && Bounded_Payload::fits( $adapter_mutate ),
		'MCP Adapter did not return a bounded successful block-write result.'
	);
	$reply = $adapter_mutate['data'];
	wpai_issue100_real_assert( ! is_wp_error( $reply ) && ! empty( $reply['truncated'] ) && Bounded_Payload::fits( $reply ), 'Mutation was not acknowledged inside a bounded response.' );
	wpai_issue100_real_assert( false !== strpos( get_post( $post_id )->post_content, 'Changed Button' ), 'Mutation was not persisted in WordPress.' );

	$stale = $blocks_read->execute(
		array(
			'post_id'               => $post_id,
			'path'                  => $found['path'],
			'expected_content_hash' => $target['content_hash'],
		)
	);
	wpai_issue100_real_assert( is_wp_error( $stale ) && 'stale_content_conflict' === $stale->get_error_code(), 'Targeted reads did not honor content revisions.' );

	$meta = $content_read->execute(
		array(
			'action' => 'get',
			'id'     => $post_id,
		)
	);
	wpai_issue100_real_assert( ! is_wp_error( $meta ) && false === $meta['items'][0]['content_complete'], 'Long raw content must return a bounded metadata-first response.' );
	$part = $content_read->execute(
		array(
			'action'                => 'get',
			'id'                    => $post_id,
			'content_offset'        => 0,
			'content_max_bytes'     => 4096,
			'expected_content_hash' => $meta['items'][0]['content_hash'],
		)
	);
	wpai_issue100_real_assert( ! is_wp_error( $part ) && Bounded_Payload::fits( $part ) && $part['items'][0]['content_next_offset'] > 0, 'Content byte windows were not exposed by Core WordPress.' );

	// Confirm a successful large full-content mutation never emits oversized readback.

	$huge_body = str_repeat( 'سلام گوتنبرگ! ', 5000 );
	$huge_title = str_repeat( 'Really long saved WordPress title ', 5000 );
	$huge_excerpt = str_repeat( 'Very large saved WordPress excerpt ', 5000 );
	// A real WordPress fixture may have much larger stored metadata than
	// the ordinary MCP mutation request body can safely carry.
	$seeded = wp_update_post(
		array(
			'ID'           => $post_id,
			'post_title'   => $huge_title,
			'post_excerpt' => $huge_excerpt,
			'post_content' => $huge_body,
		),
		true
	);
	wpai_issue100_real_assert( ! is_wp_error( $seeded ), 'Could not seed large persisted post metadata.' );
	$pre_update = $content_read->execute( array( 'action' => 'get', 'id' => $post_id ) );
	wpai_issue100_real_assert(
		! is_wp_error( $pre_update ) && ! empty( $pre_update['items'][0]['projection_truncated'] ),
		'Large seeded metadata was not discoverable before mutation.'
	);
	$prior = $pre_update['items'][0];
	$adapter_content = wp_get_ability( 'mcp-adapter/execute-ability' )->execute(
		array(
			'ability_name' => 'wp-ai-bridge/content-upsert',
			'parameters'   => array(
				'action'                => 'update',
				'id'                    => $post_id,
				'content'               => 'Updated successfully through MCP Adapter after large metadata was already stored.',
				'expected_modified_gmt' => $prior['modified_gmt'],
				'expected_state_hash'   => $prior['state_hash'],
			),
		)
	);
	wpai_issue100_real_assert(
		! is_wp_error( $adapter_content ) && true === $adapter_content['success'] && Bounded_Payload::fits( $adapter_content ),
		'MCP Adapter did not acknowledge a committed large-metadata/content write inside its encoded bound.'
	);
	$changed_content = $adapter_content['data'];
	wpai_issue100_real_assert(
		false === $changed_content['content_complete'] &&
		! empty( $changed_content['projection_truncated'] ) &&
		Bounded_Payload::fits( $changed_content ) &&
		hash( 'sha256', (string) get_post( $post_id )->post_content ) === $changed_content['content_hash'],
		'The compact content-write acknowledgment did not identify the persisted state.'
	);
	wpai_issue100_real_assert(
		$huge_title === get_post( $post_id )->post_title && $huge_excerpt === get_post( $post_id )->post_excerpt,
		'WordPress did not persist the large title and excerpt used by the acknowledgment regression.'
	);
	$large_metadata = $content_read->execute( array( 'action' => 'get', 'id' => $post_id, 'content_offset' => 0, 'content_max_bytes' => 4096 ) );
	wpai_issue100_real_assert(
		! is_wp_error( $large_metadata ) &&
		Bounded_Payload::fits( $large_metadata ) &&
		! empty( $large_metadata['items'][0]['projection_truncated'] ) &&
		$changed_content['state_hash'] === $large_metadata['items'][0]['state_hash'],
		'Large metadata prevented a bounded and identity-consistent read.'
	);
	$title_chunk = wp_get_ability( 'mcp-adapter/execute-ability' )->execute(
		array(
			'ability_name' => 'wp-ai-bridge/content-read',
			'parameters'   => array(
				'action'              => 'get',
				'id'                  => $post_id,
				'text_field'          => 'title',
				'text_offset'         => 0,
				'text_max_bytes'      => 4096,
				'expected_state_hash' => $changed_content['state_hash'],
			),
		)
	);
	wpai_issue100_real_assert(
		! is_wp_error( $title_chunk ) && true === $title_chunk['success'] &&
		Bounded_Payload::fits( $title_chunk ) &&
		$title_chunk['data']['items'][0]['text_next_offset'] > 0,
		'A large title is not available in bounded windows through the MCP Adapter.'
	);

	echo "PASS: native WordPress/Gutenberg large-payload discovery, mutation, and content windows.\n";
} finally {
	if ( $post_id && ! is_wp_error( $post_id ) ) {
		wp_delete_post( (int) $post_id, true );
	}
	update_option( Settings::OPTION_NAME, $old_settings, false );
}
