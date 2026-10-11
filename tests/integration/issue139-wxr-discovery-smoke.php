<?php
/**
 * Real WordPress + official Adapter test for read-only native WXR discovery.
 * Never triggers export_wp, import, uploads, or remote URL retrieval.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai139_discover_assert( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
}

$settings        = new Settings();
$original_groups = get_option( Settings::OPTION_NAME, $settings->defaults() );
$original_user   = get_current_user_id();

try {
	$ability = wp_get_ability( 'wp-ai-bridge/wxr-export-discover' );
	wpai139_discover_assert( $ability instanceof WP_Ability, 'WXR native discovery Ability missing.' );
	$meta = $ability->get_meta();
	wpai139_discover_assert(
		true === ( $meta['annotations']['readonly'] ?? false ) &&
		false === ( $meta['annotations']['destructive'] ?? true ),
		'WXR metadata discovery declared an effectful operation.'
	);
	$defaults = $settings->defaults();
	update_option( Settings::OPTION_NAME, $defaults, false );
	wpai139_discover_assert(
		true !== $ability->check_permissions( array() ) && is_wp_error( $ability->execute( array() ) ),
		'Native WXR discovery ignored default-off Site Configuration grant.'
	);
	$defaults[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $defaults, false );
	wpai139_discover_assert(
		true === $ability->check_permissions( array() ),
		'Authorized administrator with export permission was denied.'
	);
	register_post_type(
		'wpai139_export',
		array(
			'public'     => true,
			'can_export' => true,
			'label'      => '<b>Visible WXR Test</b>',
		)
	);
	register_post_type(
		'wpai139_private',
		array(
			'public'     => false,
			'can_export' => false,
		)
	);
	$page = $ability->execute( array( 'limit' => 1 ) );
	wpai139_discover_assert(
		! is_wp_error( $page ) && 1 === count( $page['items'] ) && $page['has_more'] &&
		$page['total'] > 1 && 1 === $page['next_offset'] &&
		true === $page['native_export_available'] && false === $page['generates_file'] &&
		get_current_blog_id() === $page['blog_id'],
		'Exportable registry metadata, privacy or paging is incorrect.'
	);
	$offset = 0;
	$items  = array();
	for ( $i = 0; $i < 30; ++$i ) {
		$response = $ability->execute(
			array(
				'offset'                 => $offset,
				'limit'                  => 1,
				'expected_registry_hash' => $page['registry_hash'],
			)
		);
		wpai139_discover_assert( ! is_wp_error( $response ), 'Stable native WXR registry continuation failed.' );
		$items[] = $response['items'][0];
		$offset  = $response['next_offset'];
		if ( ! $response['has_more'] ) {
			break;
		}
	}
	wpai139_discover_assert( count( $items ) === $page['total'], 'WXR catalog pagination lost a registered exportable post type.' );
	$by_type = array();
	foreach ( $items as $item ) {
		$by_type[ $item['post_type'] ] = $item;
	}
	wpai139_discover_assert(
		isset( $by_type['wpai139_export'], $by_type['post'], $by_type['page'] ) &&
		! isset( $by_type['wpai139_private'] ),
		'Native export policy was bypassed or legitimate types disappeared.'
	);
	wpai139_discover_assert(
		'Visible WXR Test' === $by_type['wpai139_export']['label'] &&
		! $by_type['wpai139_export']['supports_author'] &&
		! $by_type['wpai139_export']['supports_status'] &&
		$by_type['post']['supports_category'] &&
		$by_type['post']['supports_status'] &&
		$by_type['page']['supports_author'] && ! $by_type['page']['supports_category'],
		'Provider labels were not sanitized or Core filter semantics were misstated.'
	);
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	wpai139_discover_assert( $adapter instanceof WP_Ability, 'Official MCP Adapter missing.' );
	$result = $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/wxr-export-discover',
			'parameters'   => array( 'limit' => 50 ),
		)
	);
	wpai139_discover_assert(
		! is_wp_error( $result ) && true === ( $result['success'] ?? false ) &&
		$page['registry_hash'] === ( $result['data']['registry_hash'] ?? '' ),
		'Native Adapter did not preserve registered WXR discovery and its identity.'
	);
	register_post_type( 'wpai139_new', array( 'public' => true, 'can_export' => true ) );
	$stale = $ability->execute( array( 'offset' => 1, 'expected_registry_hash' => $page['registry_hash'] ) );
	wpai139_discover_assert(
		is_wp_error( $stale ) && 'wxr_stale_registry' === $stale->get_error_code(),
		'Registry mutation did not invalidate WXR paging continuation.'
	);
	wp_set_current_user( 0 );
	wpai139_discover_assert(
		is_wp_error( $ability->execute( array() ) ),
		'Anonymous user bypassed native WordPress export permission.'
	);
	wp_set_current_user( $original_user );
	$defaults[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $defaults, false );
	wpai139_discover_assert(
		is_wp_error( $ability->execute( array() ) ),
		'WXR access grant revocation did not take effect.'
	);
	echo "PASS: #139 native WXR export discovery, exact filter matrix, Adapter and permission isolation.\n";
} finally {
	wp_set_current_user( $original_user );
	update_option( Settings::OPTION_NAME, $original_groups, false );
	unregister_post_type( 'wpai139_new' );
	unregister_post_type( 'wpai139_export' );
	unregister_post_type( 'wpai139_private' );
}
