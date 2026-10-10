<?php
/**
 * Disposable multisite continuity and keyed-create isolation.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Support\Settings;

function wpai126_network_assert( $ok, $message ) {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
}
wpai126_network_assert( is_multisite(), 'Multisite fixture required.' );
$base = get_current_blog_id();
$sites = get_sites( array( 'number' => 5, 'fields' => 'ids' ) );
$other = 0;
foreach ( $sites as $site ) {
	if ( (int) $site !== $base ) { $other = (int) $site; break; }
}
wpai126_network_assert( $other > 0, 'Expected existing secondary disposable site.' );
$originals = array();
$created = array();
$settings = new Settings();
$document = wp_get_ability( 'wp-ai-bridge/workspace-document' );
$resume = wp_get_ability( 'wp-ai-bridge/workspace-resume' );
wpai126_network_assert( $document instanceof WP_Ability && $resume instanceof WP_Ability, 'Network Workspace Abilities missing.' );
try {
	foreach ( array( $base, $other ) as $site_id ) {
		switch_to_blog( $site_id );
		$originals[ $site_id ] = get_option( Settings::OPTION_NAME, $settings->defaults() );
		$groups = $settings->defaults();
		$groups[ Settings::GROUP_SITE_READ ] = 1;
		$groups[ Settings::GROUP_BUILDER_WRITE ] = 1;
		update_option( Settings::OPTION_NAME, $groups, false );
		$before = $resume->execute( array( 'project_ref' => 'same-cross-site-project' ) );
		wpai126_network_assert( ! is_wp_error( $before ) && 0 === $before['counts']['documents'], 'Workspace from another network site leaked.' );
		$item = $document->execute( array(
			'action' => 'create',
			'title' => 'Site ' . $site_id . ' private brief',
			'project_ref' => 'same-cross-site-project',
			'key' => 'project-brief',
			'content' => 'Exact network-bound record for blog ' . $site_id,
			'operation_id' => 'multisite-brief-operation-01',
		) );
		wpai126_network_assert( ! is_wp_error( $item ) && ! empty( $item['items'][0]['id'] ), 'Site-local canonical keyed create failed.' );
		$created[ $site_id ] = (int) $item['items'][0]['id'];
		$after = $resume->execute( array( 'project_ref' => 'same-cross-site-project' ) );
		wpai126_network_assert( ! is_wp_error( $after ) && 1 === $after['counts']['documents'], 'Site-local project recovery failed.' );
		restore_current_blog();
	}
	echo "PASS: #126/#128 multisite site+claim+project boundaries.\n";
} finally {
	if ( get_current_blog_id() !== $base ) {
		restore_current_blog();
	}
	foreach ( array( $base, $other ) as $site_id ) {
		switch_to_blog( $site_id );
		if ( isset( $created[ $site_id ] ) ) { wp_delete_post( $created[ $site_id ], true ); }
		if ( isset( $originals[ $site_id ] ) ) { update_option( Settings::OPTION_NAME, $originals[ $site_id ], false ); }
		restore_current_blog();
	}
}
