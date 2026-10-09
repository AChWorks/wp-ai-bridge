<?php
/** Unit regression: bounded revision discovery, UTF-8 windows and auth. */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Content_Abilities;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

$checks = 0;
function wpai105_assert( $pass, $message ) {
	global $checks;
	++$checks;
	if ( ! $pass ) {
		throw new RuntimeException( $message );
	}
}
function wpai105_error( $result, $code ) {
	wpai105_assert( is_wp_error( $result ) && $code === $result->get_error_code(), 'Wrong error: ' . $code . ' / ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'success' ) );
}
function get_post( $id ) {
	return $GLOBALS['wpai105_posts'][ (int) $id ] ?? null;
}
function wp_get_post_revision( &$revision_id ) {
	return $GLOBALS['wpai105_revisions'][ (int) $revision_id ] ?? null;
}
function wp_get_post_revisions( $post_id, $args = array() ) {
	$matching = array_filter(
		$GLOBALS['wpai105_revisions'],
		static function ( $revision ) use ( $post_id ) {
			return (int) $revision->post_parent === (int) $post_id; }
	);
	return array_slice( $matching, (int) ( $args['offset'] ?? 0 ), (int) ( $args['posts_per_page'] ?? 10 ), true );
}
function wpai105_rev( $id, $content, $title = 'Revision title', $excerpt = 'Revision excerpt', $parent_id = 900 ) {
	return (object) array(
		'ID'                => $id,
		'post_parent'       => $parent_id,
		'post_date_gmt'     => '2026-10-09 11:00:00',
		'post_modified_gmt' => '2026-10-09 11:00:00',
		'post_author'       => 1,
		'post_title'        => $title,
		'post_excerpt'      => $excerpt,
		'post_content'      => $content,
	);
}
wpai_test_reset_state();
$GLOBALS['wpai_test']['capabilities']['read_post'] = true;
$GLOBALS['wpai_test']['capabilities']['edit_post'] = true;
$GLOBALS['wpai105_posts']                          = array(
	900 => (object) array(
		'ID'          => 900,
		'post_status' => 'publish',
	),
);
$GLOBALS['wpai105_revisions']                      = array( 1 => wpai105_rev( 1, 'Small reversible sample' ) );
$settings = new Settings();
$ability  = new Content_Abilities( new Permissions( $settings ), new Mutation_Log() );
$ability->register();
$schema = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/revisions-read'];
wpai105_assert( isset( $schema['input_schema']['properties']['revision_id'], $schema['input_schema']['properties']['expected_content_hash'], $schema['input_schema']['properties']['text_field'] ), 'Revision selector input contract is missing.' );
wpai105_assert( isset( $schema['output_schema']['items']['properties']['text_chunk'], $schema['output_schema']['items']['properties']['content_next_offset'] ), 'Revision output window contract is missing.' );

$small = $ability->read_revisions(
	array(
		'post_id'         => 900,
		'include_content' => true,
	)
);
wpai105_assert( ! is_wp_error( $small ) && 1 === count( $small ) && 'Small reversible sample' === $small[0]['content'] && ! isset( $small[0]['projection_truncated'] ), 'Small revisions must preserve the full legacy list response.' );
wpai105_assert( Bounded_Payload::fits( $small ) && ! isset( $ability->read_revisions( array( 'post_id' => 900 ) )[0]['content'] ), 'Ordinary default revision read changed.' );

$body                            = str_repeat( 'سلام 🌍 به دنیای وردپرس! ', 2700 );
$title                           = str_repeat( 'عنوان فارسی 🌍 ', 2600 );
$excerpt                         = str_repeat( 'خلاصه فارسی 🌍 ', 2500 );
$GLOBALS['wpai105_revisions'][2] = wpai105_rev( 2, $body, $title, $excerpt );
$listed                          = $ability->read_revisions(
	array(
		'post_id'         => 900,
		'include_content' => true,
	)
);
wpai105_assert( ! is_wp_error( $listed ) && Bounded_Payload::fits( $listed ) && 2 === count( $listed ), 'Large revision poisoned ordinary listing.' );
wpai105_assert( ! empty( $listed[1]['projection_truncated'] ) && in_array( 'content', $listed[1]['omitted_fields'], true ) && strlen( $body ) === $listed[1]['content_total_bytes'] && hash( 'sha256', $body ) === $listed[1]['content_hash'], 'Large revision has no complete discoverable identity or explicit omissions.' );
wpai105_assert( ! isset( $listed[1]['content'], $listed[1]['title'] ), 'Large historical projection leaked complete body or oversized metadata.' );

$selected = $ability->read_revisions(
	array(
		'post_id'     => 900,
		'revision_id' => 2,
	)
);
wpai105_assert( ! is_wp_error( $selected ) && Bounded_Payload::fits( $selected ) && ! empty( $selected[0]['projection_truncated'] ), 'Selected large revision metadata must be bounded.' );
$reassembled = '';
$offset      = 0;
$hash        = $selected[0]['content_hash'];
for ( $i = 0; $i < 100; ++$i ) {
	$params = array(
		'post_id'           => 900,
		'revision_id'       => 2,
		'include_content'   => true,
		'content_offset'    => $offset,
		'content_max_bytes' => 4096,
	);
	if ( $offset > 0 ) {
		$params['expected_content_hash'] = $hash;
	}
	$part = $ability->read_revisions( $params );
	wpai105_assert( ! is_wp_error( $part ) && Bounded_Payload::fits( $part ) && preg_match( '//u', $part[0]['content'] ) && $part[0]['content_next_offset'] > $offset, 'Revision UTF-8 window was invalid, oversized, or stalled.' );
	wpai105_assert( $hash === $part[0]['content_hash'] && 2 === $part[0]['id'] && 900 === $part[0]['parent_id'], 'Revision changed identity between windows.' );
	$reassembled .= $part[0]['content'];
	$offset       = $part[0]['content_next_offset'];
	if ( $part[0]['content_complete'] ) {
		break;
	}
}
wpai105_assert( $body === $reassembled && strlen( $body ) === $offset, 'UTF-8 windows could not reconstruct exact stored bytes.' );

wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'        => 900,
			'content_offset' => 0,
		)
	),
	'revision_selector_required'
);
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'        => 900,
			'revision_id'    => 2,
			'content_offset' => 0,
		)
	),
	'invalid_revision_query'
);
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'         => 900,
			'revision_id'     => 2,
			'include_content' => true,
			'content_offset'  => 4096,
		)
	),
	'revision_identity_required'
);
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'               => 900,
			'revision_id'           => 2,
			'include_content'       => true,
			'content_offset'        => 1,
			'expected_content_hash' => $hash,
		)
	),
	'invalid_content_window'
);
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'               => 900,
			'revision_id'           => 2,
			'include_content'       => true,
			'content_offset'        => strlen( $body ) + 1,
			'expected_content_hash' => $hash,
		)
	),
	'invalid_content_window'
);
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'               => 900,
			'revision_id'           => 2,
			'include_content'       => true,
			'content_offset'        => 4096,
			'expected_content_hash' => str_repeat( '0', 64 ),
		)
	),
	'stale_revision_conflict'
);
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'     => 900,
			'revision_id' => 2,
			'text_offset' => 0,
		)
	),
	'invalid_revision_query'
);
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'         => 900,
			'revision_id'     => 2,
			'text_field'      => 'title',
			'include_content' => true,
		)
	),
	'invalid_revision_query'
);

foreach ( array(
	'title'   => $title,
	'excerpt' => $excerpt,
) as $field => $value ) {
	$reassembled = '';
	$offset      = 0;
	$text_hash   = hash( 'sha256', $value );
	for ( $i = 0; $i < 60; ++$i ) {
		$params = array(
			'post_id'        => 900,
			'revision_id'    => 2,
			'text_field'     => $field,
			'text_offset'    => $offset,
			'text_max_bytes' => 4096,
		);
		if ( $offset > 0 ) {
			$params['expected_text_hash'] = $text_hash;
		}
		$part = $ability->read_revisions( $params );
		wpai105_assert( ! is_wp_error( $part ) && Bounded_Payload::fits( $part ) && $part[0]['text_next_offset'] > $offset && $text_hash === $part[0]['text_hash'], 'Revision metadata window failed.' );
		$reassembled .= $part[0]['text_chunk'];
		$offset       = $part[0]['text_next_offset'];
		if ( $part[0]['text_complete'] ) {
			break;
		}
	}
	wpai105_assert( $value === $reassembled, 'Revision metadata was silently truncated: ' . $field );
}
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'     => 900,
			'revision_id' => 2,
			'text_field'  => 'title',
			'text_offset' => 4096,
		)
	),
	'revision_identity_required'
);
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'            => 900,
			'revision_id'        => 2,
			'text_field'         => 'title',
			'text_offset'        => 4096,
			'expected_text_hash' => str_repeat( '0', 64 ),
		)
	),
	'stale_revision_conflict'
);

$GLOBALS['wpai105_revisions'][2]->post_content .= 'changed';
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'               => 900,
			'revision_id'           => 2,
			'include_content'       => true,
			'content_offset'        => 4096,
			'expected_content_hash' => $hash,
		)
	),
	'stale_revision_conflict'
);
$GLOBALS['wpai105_revisions'][2]->post_parent = 901;
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'     => 900,
			'revision_id' => 2,
		)
	),
	'revision_not_found'
);
$GLOBALS['wpai105_revisions'][2]->post_parent      = 900;
$GLOBALS['wpai_test']['capabilities']['edit_post'] = false;
wpai105_assert( ! $ability->can_read_revisions( array( 'post_id' => 900 ) ), 'Read-only public post permission must not grant private revision access.' );
wpai105_error( $ability->read_revisions( array( 'post_id' => 900 ) ), 'revision_read_forbidden' );
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'         => 900,
			'revision_id'     => 2,
			'include_content' => true,
			'content_offset'  => 0,
		)
	),
	'revision_read_forbidden'
);
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'               => 900,
			'revision_id'           => 2,
			'include_content'       => true,
			'content_offset'        => 4096,
			'expected_content_hash' => $hash,
		)
	),
	'revision_read_forbidden'
);
$GLOBALS['wpai_test']['capabilities']['edit_post']        = true;
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] = array( Settings::GROUP_SITE_READ => 0 );
wpai105_error(
	$ability->read_revisions(
		array(
			'post_id'     => 900,
			'revision_id' => 2,
		)
	),
	'revision_read_forbidden'
);
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] = $settings->defaults();

// Fifty revisions with individually modest but collectively oversized titles.
$GLOBALS['wpai105_revisions'] = array();
for ( $i = 1; $i <= 50; ++$i ) {
	$GLOBALS['wpai105_revisions'][ $i ] = wpai105_rev( $i, 'Tiny body', str_repeat( 'History title ' . $i, 55 ), 'Excerpt ' . $i );
}
$batch = $ability->read_revisions(
	array(
		'post_id'         => 900,
		'limit'           => 50,
		'include_content' => true,
	)
);
wpai105_assert( ! is_wp_error( $batch ) && 50 === count( $batch ) && Bounded_Payload::fits( $batch ), 'Fifty revisions must fit a finite aggregate envelope without dropping IDs.' );
wpai105_assert( ! empty( $batch[0]['projection_truncated'] ) && in_array( 'title', $batch[0]['omitted_fields'], true ), 'Oversized metadata batch has no recovery path.' );
$tail = $ability->read_revisions(
	array(
		'post_id' => 900,
		'offset'  => 49,
		'limit'   => 1,
	)
);
wpai105_assert( ! is_wp_error( $tail ) && 50 === $tail[0]['id'], 'Revision paging could not reach older historical entries.' );

echo 'PASS: Issue #105 bounded revisions (' . $checks . " assertions).\n";
