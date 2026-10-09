<?php
/** Native WordPress + official MCP Adapter large historical revision regression. */

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Settings;

function wpai105_live_assert( $pass, $message ) {
	if ( ! $pass ) {
		throw new RuntimeException( $message );
	}
}
function wpai105_live_adapt( $adapter, $post_id, $revision_id, array $more = array() ) {
	return $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/revisions-read',
			'parameters'   => array_merge(
				array(
					'post_id'     => $post_id,
					'revision_id' => $revision_id,
				),
				$more
			),
		)
	);
}

$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$user     = get_current_user_id();
$post_id  = 0;
$other_id = 0;
try {
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Issue 105 fixture draft',
			'post_content' => 'Initial revision seed.',
		),
		true
	);
	wpai105_live_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Could not create the isolated revision parent.' );

	$large_body    = str_repeat( 'سلام 🌍 نسخه آزمایشی ', 3800 );
	$large_title   = str_repeat( 'Revision title 🌍 ', 2350 );
	$large_excerpt = str_repeat( 'Revision excerpt 🌍 ', 2250 );
	for ( $i = 0; $i < 6; ++$i ) {
		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $large_title . ' / ' . $i,
				'post_excerpt' => $large_excerpt,
				'post_content' => $large_body . ' #' . $i,
			),
			true
		);
		wpai105_live_assert( ! is_wp_error( $updated ) && $post_id === $updated, 'Could not seed large historical revisions.' );
	}
	$revisions = wp_get_post_revisions( $post_id );
	wpai105_live_assert( count( $revisions ) >= 3, 'Core did not persist the expected revision history.' );
	$selected = null;
	foreach ( $revisions as $revision ) {
		if ( strlen( (string) $revision->post_content ) > Bounded_Payload::RESPONSE_BYTES &&
			strlen( (string) $revision->post_title ) > Bounded_Payload::RESPONSE_BYTES &&
			strlen( (string) $revision->post_excerpt ) > Bounded_Payload::RESPONSE_BYTES ) {
			$selected = $revision;
			break;
		}
	}
	wpai105_live_assert( null !== $selected, 'Core did not save a genuinely oversized title, excerpt and body.' );

	$reader  = wp_get_ability( 'wp-ai-bridge/revisions-read' );
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	wpai105_live_assert( $reader instanceof WP_Ability && $adapter instanceof WP_Ability, 'Revision or Adapter read contract was not registered.' );
	$contract = wp_get_ability( 'mcp-adapter/get-ability-info' )->execute( array( 'ability_name' => 'wp-ai-bridge/revisions-read' ) );
	wpai105_live_assert( ! is_wp_error( $contract ) && 'wp-ai-bridge/revisions-read' === $contract['name'] && isset( $reader->get_input_schema()['properties']['revision_id'] ), 'Official MCP Adapter did not inspect the new revision selector.' );

	$listed = $reader->execute(
		array(
			'post_id'         => $post_id,
			'limit'           => 50,
			'include_content' => true,
		)
	);
	wpai105_live_assert( ! is_wp_error( $listed ) && count( $listed ) >= 3 && Bounded_Payload::fits( $listed ), 'Large native revision batch overflowed the MCP response budget.' );
	wpai105_live_assert( ! empty( $listed[0]['projection_truncated'] ) && in_array( 'content', $listed[0]['omitted_fields'], true ), 'Native revision batch omitted its continuation instructions.' );

	$expected  = (string) $selected->post_content;
	$hash      = hash( 'sha256', $expected );
	$recovered = '';
	$offset    = 0;
	for ( $i = 0; $i < 150; ++$i ) {
		$input = array(
			'include_content'   => true,
			'content_offset'    => $offset,
			'content_max_bytes' => 4096,
		);
		if ( $offset > 0 ) {
			$input['expected_content_hash'] = $hash;
		}
		$wrapped = wpai105_live_adapt( $adapter, $post_id, (int) $selected->ID, $input );
		wpai105_live_assert( ! is_wp_error( $wrapped ) && true === ( $wrapped['success'] ?? false ) && Bounded_Payload::fits( $wrapped ), 'Official MCP Adapter could not transport the bounded revision window.' );
		$item = $wrapped['data'][0];
		wpai105_live_assert( $hash === $item['content_hash'] && (int) $selected->ID === $item['id'] && $offset < $item['content_next_offset'], 'Revision continuation lost its stable identity or made no progress.' );
		$recovered .= $item['content'];
		$offset     = $item['content_next_offset'];
		if ( $item['content_complete'] ) {
			break;
		}
	}
	wpai105_live_assert( $expected === $recovered && strlen( $expected ) === $offset, 'MCP Adapter windows did not reconstruct byte-exact UTF-8 revision content.' );

	$title_part = wpai105_live_adapt(
		$adapter,
		$post_id,
		(int) $selected->ID,
		array(
			'text_field'     => 'title',
			'text_offset'    => 0,
			'text_max_bytes' => 4096,
		)
	);
	wpai105_live_assert( ! is_wp_error( $title_part ) && true === ( $title_part['success'] ?? false ) && Bounded_Payload::fits( $title_part ) && $title_part['data'][0]['text_next_offset'] > 0, 'Real Adapter could not read giant revision metadata windows.' );
	$bad = $reader->execute(
		array(
			'post_id'               => $post_id,
			'revision_id'           => $selected->ID,
			'include_content'       => true,
			'content_offset'        => 8,
			'expected_content_hash' => str_repeat( '0', 64 ),
		)
	);
	wpai105_live_assert( is_wp_error( $bad ) && 'stale_revision_conflict' === $bad->get_error_code(), 'Revision continuation did not reject a stale hash.' );

	$other_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'Issue 105 foreign target',
		),
		true
	);
	wpai105_live_assert( ! is_wp_error( $other_id ) && $other_id > 0, 'Could not create foreign parent fixture.' );
	$foreign = $reader->execute(
		array(
			'post_id'     => $other_id,
			'revision_id' => $selected->ID,
		)
	);
	wpai105_live_assert( is_wp_error( $foreign ) && 'revision_not_found' === $foreign->get_error_code(), 'The selected revision crossed the parent-post boundary.' );

	$off                              = $settings->defaults();
	$off[ Settings::GROUP_SITE_READ ] = 0;
	update_option( Settings::OPTION_NAME, $off, false );
	$revoked = $reader->execute(
		array(
			'post_id'               => $post_id,
			'revision_id'           => $selected->ID,
			'include_content'       => true,
			'content_offset'        => 4096,
			'expected_content_hash' => $hash,
		)
	);
	wpai105_live_assert( is_wp_error( $revoked ), 'A continued revision read bypassed disabled Site Read.' );
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	wp_set_current_user( 0 );
	$anonymous = $reader->execute(
		array(
			'post_id'     => $post_id,
			'revision_id' => $selected->ID,
		)
	);
	wpai105_live_assert( is_wp_error( $anonymous ), 'An unauthenticated principal read protected revision metadata.' );
	echo "PASS: WordPress and official Adapter bounded historical revision discovery, windows, identity and authority.\n";
} finally {
	wp_set_current_user( $user );
	if ( $other_id && ! is_wp_error( $other_id ) ) {
		wp_delete_post( (int) $other_id, true );
	}
	if ( $post_id && ! is_wp_error( $post_id ) ) {
		wp_delete_post( (int) $post_id, true );
	}
	update_option( Settings::OPTION_NAME, $original, false );
}
