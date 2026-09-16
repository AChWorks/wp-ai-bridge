<?php
/**
 * Regression coverage for canonical Gutenberg block paths (Issue #29).
 */

require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Block_Abilities;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

$failures = 0;
$tests    = 0;

function wpai_issue29_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function get_post( $post_id ) {
	return isset( $GLOBALS['wpai_issue29_post'] ) && (int) $GLOBALS['wpai_issue29_post']->ID === (int) $post_id
		? $GLOBALS['wpai_issue29_post']
		: null;
}

function parse_blocks( $content ) {
	$decoded = json_decode( (string) $content, true );
	return is_array( $decoded ) ? $decoded : array();
}

function serialize_block( $block ) {
	return json_encode( $block, JSON_UNESCAPED_SLASHES );
}

function serialize_blocks( $blocks ) {
	return json_encode( array_values( $blocks ), JSON_UNESCAPED_SLASHES );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function wp_update_post( $postarr, $wp_error = false ) {
	$post = get_post( isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0 );
	if ( ! $post ) {
		return $wp_error ? new WP_Error( 'post_not_found', 'Post not found.' ) : 0;
	}
	if ( array_key_exists( 'post_content', $postarr ) ) {
		$post->post_content = (string) $postarr['post_content'];
	}
	$timestamp               = strtotime( $post->post_modified_gmt . ' UTC' );
	$post->post_modified_gmt = gmdate( 'Y-m-d H:i:s', false === $timestamp ? 1 : $timestamp + 1 );
	return (int) $post->ID;
}

function wpai_issue29_fixture_blocks() {
	return array(
		array(
			'blockName'    => 'core/group',
			'attrs'        => array(),
			'innerBlocks'  => array(
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerContent' => array( '<p>One</p>' ),
				),
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerContent' => array( '<p>Two</p>' ),
				),
			),
			'innerContent' => array( '<div class="wp-block-group">', null, null, '</div>' ),
		),
		array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerContent' => array( '<p>Outside</p>' ),
		),
	);
}

function wpai_issue29_reset_post() {
	$GLOBALS['wpai_issue29_post'] = (object) array(
		'ID'                => 29,
		'post_type'         => 'page',
		'post_status'       => 'draft',
		'post_modified_gmt' => '2026-09-11 08:00:00',
		'post_content'      => serialize_blocks( wpai_issue29_fixture_blocks() ),
	);
}

function wpai_issue29_new_block_markup() {
	return serialize_blocks(
		array(
			array(
				'blockName'    => 'core/heading',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerContent' => array( '<h2>Changed</h2>' ),
			),
		)
	);
}

wpai_test_reset_state();
$GLOBALS['wpai_test']['post_types']['page'] = (object) array(
	'name'               => 'page',
	'cap'                => (object) array( 'publish_posts' => 'publish_pages' ),
	'public'             => true,
	'publicly_queryable' => true,
	'show_in_rest'       => true,
);
$GLOBALS['wpai_test']['post_type_supports']['page'] = array( 'editor' => true );

$settings    = new Settings();
$permissions = new Permissions( $settings );
$blocks      = new Block_Abilities( $permissions, new Mutation_Log() );

wpai_issue29_reset_post();
$tree = $blocks->read( array( 'post_id' => 29 ) );
wpai_issue29_assert( ! is_wp_error( $tree ), 'Fixture block tree could not be read.' );
wpai_issue29_assert( '0' === $tree['blocks'][0]['path'], 'The canonical first top-level block path must be 0.' );
wpai_issue29_assert( '0.0' === $tree['blocks'][0]['inner_blocks'][0]['path'], 'The canonical first nested block path must be 0.0.' );
wpai_issue29_assert( '1' === $tree['blocks'][1]['path'], 'The canonical second top-level block path must be 1.' );

foreach ( array( '00', '01', '0.00' ) as $alias ) {
	wpai_issue29_reset_post();
	$tree   = $blocks->read( array( 'post_id' => 29 ) );
	$before = get_post( 29 )->post_content;
	$result = $blocks->mutate(
		array(
			'post_id'               => 29,
			'action'                => 'replace',
			'path'                  => $alias,
			'block_markup'          => wpai_issue29_new_block_markup(),
			'expected_modified_gmt' => $tree['modified_gmt'],
			'expected_content_hash' => $tree['content_hash'],
			'expected_block_hash'   => $tree['blocks'][0]['block_hash'],
		)
	);
	wpai_issue29_assert( is_wp_error( $result ) && 'invalid_block_path' === $result->get_error_code(), 'Non-canonical path ' . $alias . ' must be rejected as invalid.' );
	wpai_issue29_assert( $before === get_post( 29 )->post_content, 'Rejected non-canonical path ' . $alias . ' changed content.' );
}

wpai_issue29_reset_post();
$tree   = $blocks->read( array( 'post_id' => 29 ) );
$before = get_post( 29 )->post_content;
$missing = $blocks->mutate(
	array(
		'post_id'               => 29,
		'action'                => 'replace',
		'path'                  => '',
		'block_markup'          => wpai_issue29_new_block_markup(),
		'expected_modified_gmt' => $tree['modified_gmt'],
		'expected_content_hash' => $tree['content_hash'],
		'expected_block_hash'   => $tree['blocks'][0]['block_hash'],
	)
);
wpai_issue29_assert( is_wp_error( $missing ) && 'block_path_required' === $missing->get_error_code(), 'An empty targeted path must remain a missing-path error.' );
wpai_issue29_assert( $before === get_post( 29 )->post_content, 'Rejected empty path changed content.' );

wpai_issue29_reset_post();
$tree   = $blocks->read( array( 'post_id' => 29 ) );
$before = get_post( 29 )->post_content;
$stale_block = $blocks->mutate(
	array(
		'post_id'               => 29,
		'action'                => 'replace',
		'path'                  => '0',
		'block_markup'          => wpai_issue29_new_block_markup(),
		'expected_modified_gmt' => $tree['modified_gmt'],
		'expected_content_hash' => $tree['content_hash'],
		'expected_block_hash'   => str_repeat( '0', 64 ),
	)
);
wpai_issue29_assert( is_wp_error( $stale_block ) && 'stale_block_conflict' === $stale_block->get_error_code(), 'A stale fingerprint for canonical path 0 must remain rejected.' );
wpai_issue29_assert( $before === get_post( 29 )->post_content, 'Stale block fingerprint changed content.' );

foreach ( array( 'replace', 'insert_before', 'insert_after', 'remove' ) as $action ) {
	wpai_issue29_reset_post();
	$tree  = $blocks->read( array( 'post_id' => 29 ) );
	$input = array(
		'post_id'               => 29,
		'action'                => $action,
		'path'                  => '0',
		'expected_modified_gmt' => $tree['modified_gmt'],
		'expected_content_hash' => $tree['content_hash'],
		'expected_block_hash'   => $tree['blocks'][0]['block_hash'],
	);
	if ( 'remove' !== $action ) {
		$input['block_markup'] = wpai_issue29_new_block_markup();
	}
	$result = $blocks->mutate( $input );
	wpai_issue29_assert( ! is_wp_error( $result ), 'Canonical top-level path 0 failed for ' . $action . '.' );
	if ( ! is_wp_error( $result ) ) {
		wpai_issue29_assert( '0' === $result['blocks'][0]['path'], 'Top-level mutation did not preserve canonical path formatting.' );
	}
}

wpai_issue29_reset_post();
$tree = $blocks->read( array( 'post_id' => 29 ) );
$nested = $blocks->mutate(
	array(
		'post_id'               => 29,
		'action'                => 'replace',
		'path'                  => '0.0',
		'block_markup'          => wpai_issue29_new_block_markup(),
		'expected_modified_gmt' => $tree['modified_gmt'],
		'expected_content_hash' => $tree['content_hash'],
		'expected_block_hash'   => $tree['blocks'][0]['inner_blocks'][0]['block_hash'],
	)
);
wpai_issue29_assert( ! is_wp_error( $nested ), 'Canonical nested path 0.0 must continue to work.' );
if ( ! is_wp_error( $nested ) ) {
	wpai_issue29_assert( 'core/heading' === $nested['blocks'][0]['inner_blocks'][0]['name'], 'Nested replacement targeted the wrong block.' );
	wpai_issue29_assert( 'core/paragraph' === $nested['blocks'][1]['name'], 'Nested replacement changed an unrelated root block.' );
}

wpai_issue29_reset_post();
$stale_tree = $blocks->read( array( 'post_id' => 29 ) );
get_post( 29 )->post_content = serialize_blocks(
	array_merge(
		wpai_issue29_fixture_blocks(),
		array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerContent' => array( '<p>Concurrent change</p>' ),
			),
		)
	)
);
get_post( 29 )->post_modified_gmt = '2026-09-11 08:00:01';
$stale_content = $blocks->mutate(
	array(
		'post_id'               => 29,
		'action'                => 'replace',
		'path'                  => '0',
		'block_markup'          => wpai_issue29_new_block_markup(),
		'expected_modified_gmt' => $stale_tree['modified_gmt'],
		'expected_content_hash' => $stale_tree['content_hash'],
		'expected_block_hash'   => $stale_tree['blocks'][0]['block_hash'],
	)
);
wpai_issue29_assert( is_wp_error( $stale_content ) && 'stale_content_conflict' === $stale_content->get_error_code(), 'Object-level stale-write protection must remain intact.' );

if ( 0 !== $failures ) {
	fwrite( STDERR, sprintf( "FAIL: %d of %d Issue #29 assertions failed.\n", $failures, $tests ) );
	exit( 1 );
}

printf( "PASS: %d Issue #29 block-path assertions.\n", $tests );
