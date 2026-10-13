<?php
/**
 * Read-only private WXR preflight, including adversarial XML and source drift.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Artifact_Writer;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\WXR_Export_Stager;
use WP_AI_Bridge\Support\WXR_Import_Preflight;

function wpai139_preview_assert( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
}

$actor          = get_current_user_id();
$settings       = new Settings();
$original       = get_option( Settings::OPTION_NAME, $settings->defaults() );
$producer       = new WXR_Export_Stager( new Permissions( $settings ) );
$preflight      = new WXR_Import_Preflight( new Permissions( $settings ) );
$type           = 'wpai139_preview';
$marker         = 'wpai139-inspection-' . bin2hex( random_bytes( 5 ) );
$post_id        = 0;
$written        = array();
$before_private = glob( trailingslashit( get_temp_dir() ) . 'wpai-private-artifact*' );

try {
	wpai139_preview_assert( $actor > 0 && current_user_can( 'import' ), 'Native WordPress import capability required in disposable site.' );
	$blocked = $preflight->inspect( array(), 4096, 4096, 5 );
	wpai139_preview_assert( is_wp_error( $blocked ) && 'wxr_preview_denied' === $blocked->get_error_code(), 'Disabled Site Configuration grant did not deny WXR preview.' );
	$grants                                = $settings->defaults();
	$grants[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );

	register_post_type(
		$type,
		array(
			'public'     => true,
			'can_export' => true,
		)
	);
	$post_id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_title'   => 'Disposable WXR import preflight fixture',
			'post_content' => "سلام WordPress 🌍\n" . $marker,
		),
		true
	);
	wpai139_preview_assert( ! is_wp_error( $post_id ) && (int) $post_id > 0, 'Could not create disposable WXR preview source.' );
	$artifact = $producer->stage( $type, 512 * 1024 );
	wpai139_preview_assert( ! is_wp_error( $artifact ), 'Internal Core WXR export producer failed prior to read-only preflight.' );
	$written[] = $artifact['path'];
	$preview   = $preflight->inspect( $artifact, 512 * 1024, 25000, 5 );
	wpai139_preview_assert(
		! is_wp_error( $preview ) &&
		1 === $preview['item_count'] &&
		1 === ( $preview['post_type_counts'][ $type ] ?? 0 ) &&
		'1.2' === $preview['wxr_version'] &&
		'structural_only' === $preview['preview_type'] &&
		false === $preview['authoritative_plan'] &&
		false === $preview['mutation_performed'] &&
		$artifact['bytes'] === $preview['content_bytes'] &&
		$artifact['sha256'] === $preview['content_sha256'] &&
		get_current_blog_id() === $preview['blog_id'] &&
		$preview['inspected_nodes'] > 4,
		'Read-only WXR source inspection lost native structure or integrity.'
	);
	wpai139_preview_assert(
		! is_wp_error( $preflight->inspect( $artifact, 512 * 1024, 25000, 5 ) ),
		'Read-only preflight was not safely repeatable.'
	);
	$wrong_blog            = $artifact;
	$wrong_blog['blog_id'] = $artifact['blog_id'] + 1;
	$mismatch              = $preflight->inspect( $wrong_blog, 512 * 1024, 25000, 5 );
	wpai139_preview_assert( is_wp_error( $mismatch ) && 'wxr_preview_wrong_site' === $mismatch->get_error_code(), 'Private WXR preview crossed blog identity.' );
	$stale           = $artifact;
	$stale['sha256'] = str_repeat( '0', 64 );
	$bad_hash        = $preflight->inspect( $stale, 512 * 1024, 25000, 5 );
	wpai139_preview_assert( is_wp_error( $bad_hash ) && 'wxr_preview_stale' === $bad_hash->get_error_code(), 'WXR preview accepted wrong pinned SHA-256.' );
	$too_small = $preflight->inspect( $artifact, 128, 25000, 5 );
	wpai139_preview_assert( is_wp_error( $too_small ) && 'wxr_preview_size' === $too_small->get_error_code(), 'WXR preview bypassed supplied finite byte budget.' );
	$too_few = $preflight->inspect( $artifact, 512 * 1024, 3, 5 );
	wpai139_preview_assert( is_wp_error( $too_few ) && 'wxr_preview_complex' === $too_few->get_error_code(), 'WXR preview bypassed XML node budget.' );
	$too_many_posts = $preflight->inspect( $artifact, 512 * 1024, 25000, 0 );
	wpai139_preview_assert( is_wp_error( $too_many_posts ) && 'wxr_preview_invalid' === $too_many_posts->get_error_code(), 'Invalid zero-item limit was not rejected.' );

	$rogue = Private_Artifact_Writer::begin( 4096 );
	wpai139_preview_assert( ! is_wp_error( $rogue ), 'Could not stage safe hostile XML fixture.' );
	$dangerous_xml = '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><item>&x;</item></channel></rss>';
	wpai139_preview_assert( true === $rogue->append( $dangerous_xml ), 'Could not stage malformed input safely.' );
	$malicious = $rogue->finish();
	wpai139_preview_assert( ! is_wp_error( $malicious ), 'Could not finalize malicious test fixture privately.' );
	$malicious['blog_id'] = get_current_blog_id();
	$written[]            = $malicious['path'];
	$rejected             = $preflight->inspect( $malicious, 4096, 4096, 5 );
	wpai139_preview_assert(
		is_wp_error( $rejected ) && 'wxr_preview_unsafe_xml' === $rejected->get_error_code(),
		'XMLReader did not reject DTD/XXE before any import was possible.'
	);

	wp_set_current_user( 0 );
	wpai139_preview_assert( is_wp_error( $preflight->inspect( $artifact, 512 * 1024, 25000, 5 ) ), 'Anonymous principal was allowed to inspect private WXR.' );
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wpai139_preview_assert( is_wp_error( $preflight->inspect( $artifact, 512 * 1024, 25000, 5 ) ), 'Revoked grant still allowed private WXR preview.' );
	echo "PASS: #139 native WXR read-only preflight, pinned bytes/site/digest, finite XML bounds, XXE and revocation.\n";
} finally {
	wp_set_current_user( $actor );
	foreach ( $written as $path ) {
		wp_delete_file( $path );
	}
	if ( is_int( $post_id ) && $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	unregister_post_type( $type );
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
	$after_private = glob( trailingslashit( get_temp_dir() ) . 'wpai-private-artifact*' );
	if ( $before_private !== $after_private ) {
		throw new RuntimeException( 'WXR preflight leaked a private staged file.' );
	}
}
