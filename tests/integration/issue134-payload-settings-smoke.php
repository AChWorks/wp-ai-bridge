<?php
/**
 * Real WordPress administrator settings, independent quotas and UTF-8 reads.
 *
 * This is source/native integration evidence, not an authenticated external
 * client + Gateway data-plane proof. Run only on disposable CI WordPress.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Payload_Settings;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

function wpai134_limits_assert( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
}

$settings         = new Settings();
$payload          = new Payload_Settings();
$original         = get_option( Payload_Settings::OPTION_NAME, false );
$original_grants  = get_option( Settings::OPTION_NAME, array() );
$document_id      = 0;
$original_network = is_multisite() ? get_current_blog_id() : 0;

try {
	$payload->register();
	$registered = get_registered_settings();
	wpai134_limits_assert( isset( $registered[ Payload_Settings::OPTION_NAME ] ) && false === $registered[ Payload_Settings::OPTION_NAME ]['show_in_rest'], 'Budget option became public REST writable.' );
	$chosen = $payload->sanitize(
		array(
			Payload_Settings::RESPONSE_KEY     => '96',
			Payload_Settings::WINDOW_KEY       => '128',
			Payload_Settings::DOCUMENT_KEY     => '512',
			Payload_Settings::NOTES_KEY        => '128',
			Payload_Settings::INLINE_FILE_KEY  => '64',
			Payload_Settings::INLINE_MEDIA_KEY => '12',
		)
	);
	update_option( Payload_Settings::OPTION_NAME, $chosen, false );
	wpai134_limits_assert( 96 * 1024 === Bounded_Payload::response_limit() && 128 * 1024 === Bounded_Payload::window_limit() && 64 === $payload->all()[ Payload_Settings::INLINE_FILE_KEY ] && 12 === $payload->all()[ Payload_Settings::INLINE_MEDIA_KEY ], 'Native get_option did not affect live transport budget.' );
	wpai134_limits_assert( 512 * 1024 === Store::document_limit() && 128 * 1024 === Store::notes_limit(), 'WordPress record quotas ignored the saved operator option.' );
	wpai134_limits_assert( get_option( Settings::OPTION_NAME, array() ) === $original_grants, 'Budget change mutated access grants.' );

	$page = new Settings_Page( new Environment(), $settings );
	ob_start();
	$page->render_settings();
	$html = ob_get_clean();
	wpai134_limits_assert(
		false !== strpos( $html, 'wpai-response-kib' ) &&
		false !== strpos( $html, 'wpai-text-window-kib' ) &&
		false !== strpos( $html, 'wpai-document-kib' ) &&
		false !== strpos( $html, 'wpai-notes-kib' ) &&
		false !== strpos( $html, 'wpai-inline-file-mib' ) &&
		false !== strpos( $html, 'wpai-inline-media-mib' ),
		'Native administrator configuration form is missing a budget field.'
	);

	$store = new Store();
	// Deliberately below the 512 KiB current record limit but above the
	// older 100,000-byte hardcoded ceiling.
	$body = str_repeat( "مدرک پروژه - text \n", 8000 );
	wpai134_limits_assert( strlen( $body ) > 100000 && strlen( $body ) < Store::document_limit(), 'Large private Workspace test fixture outside measured limit.' );
	$record = $store->create_document(
		array(
			'title'   => 'Source-only configurable payload test',
			'content' => $body,
		)
	);
	wpai134_limits_assert( ! is_wp_error( $record ), 'Higher verified WordPress record limit rejected a valid document.' );
	$document_id = (int) $record['id'];
	// Native Store-path test: the public Ability is initialized before the
	// operator settings change in this WP CLI invocation, and its initial
	// JSON schema may retain the prior maximum until the next request.
	$ident = $store->read_view( 'document', $document_id, array( 'view' => 'summary' ) );
	wpai134_limits_assert( ! is_wp_error( $ident ), 'Workspace summary was unavailable.' );
	$state    = $ident['items'][0];
	$offset   = 0;
	$received = '';
	for ( $part = 0; $part < 120; ++$part ) {
		$result = $store->read_view(
			'document',
			$document_id,
			array(
				'action'              => 'get',
				'id'                  => $document_id,
				'view'                => 'window',
				'field'               => 'content',
				'offset'              => $offset,
				'max_bytes'           => 128 * 1024,
				'expected_version'    => $state['version'],
				'expected_state_hash' => $state['state_hash'],
			)
		);
		wpai134_limits_assert( ! is_wp_error( $result ) && Bounded_Payload::fits( $result ), 'Guarded text window exceeded the configured JSON budget.' );
		$w = $result['items'][0]['field_window'];
		wpai134_limits_assert( $w['next_offset'] > $offset, 'Configured large source window did not advance.' );
		$received .= $w['text'];
		$offset    = $w['next_offset'];
		if ( $w['complete'] ) {
			break;
		}
	}
	wpai134_limits_assert( $body === $received, 'Encoded Persian text bytes were lost after adaptive window reconstruction.' );
	update_option( Payload_Settings::OPTION_NAME, $payload->sanitize( array( Payload_Settings::DOCUMENT_KEY => '64' ) ), false );
	wpai134_limits_assert( ! is_wp_error( $store->get_document( $document_id ) ), 'Lowering a quota invalidated an existing readable document.' );
	$too_large = $store->create_document(
		array(
			'title'   => 'Must reject overflow',
			'content' => str_repeat( 'x', 65 * 1024 ),
		)
	);
	wpai134_limits_assert( is_wp_error( $too_large ) && 'workspace_lossy_input' === $too_large->get_error_code(), 'Reduced per-record quota silently truncated new input.' );
	wpai134_limits_assert( get_option( Settings::OPTION_NAME, array() ) === $original_grants, 'Workspace budgets changed grants.' );
	if ( $original_network ) {
		wpai134_limits_assert( get_current_blog_id() === $original_network, 'Budget changes crossed WordPress blog identity.' );
		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 10,
			)
		);
		foreach ( $sites as $other_blog_id ) {
			if ( (int) $other_blog_id === $original_network ) {
				continue;
			}
			switch_to_blog( (int) $other_blog_id );
			try {
				$other_settings = ( new Payload_Settings() )->all();
				wpai134_limits_assert(
					Bounded_Payload::response_limit() === $other_settings[ Payload_Settings::RESPONSE_KEY ] * 1024 &&
					Store::document_limit() === $other_settings[ Payload_Settings::DOCUMENT_KEY ] * 1024,
					'Another WordPress blog inherited the first site response or record-size quota.'
				);
			} finally {
				restore_current_blog();
			}
			break;
		}
		wpai134_limits_assert( get_current_blog_id() === $original_network, 'Switching the WordPress blog left a different site active.' );
	}
	echo "PASS: Issue #134 native WordPress admin response and Workspace quotas.\n";
} finally {
	if ( $document_id ) {
		wp_delete_post( $document_id, true );
	}
	if ( false === $original ) {
		delete_option( Payload_Settings::OPTION_NAME );
	} else {
		update_option( Payload_Settings::OPTION_NAME, $original, false );
	}
}
