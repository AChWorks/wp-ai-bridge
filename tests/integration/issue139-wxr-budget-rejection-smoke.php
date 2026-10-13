<?php
/**
 * In a separate PHP process: Core WXR must fail closed at a finite byte cap.
 *
 * Core export_wp() defines global helper functions at runtime, so this case
 * must not share the PHP request with a successful export.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\WXR_Export_Stager;

$old_user    = get_current_user_id();
$settings    = new Settings();
$old_grants  = get_option( Settings::OPTION_NAME, $settings->defaults() );
$type        = 'wpai139_budget';
$post_id     = 0;
$temp_before = glob( trailingslashit( get_temp_dir() ) . 'wpai-private-artifact*' );
try {
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
			'post_title'   => 'Disposable small-budget WXR',
			'post_content' => str_repeat( 'سلام 🌍 WordPress ', 1024 ),
		),
		true
	);
	if ( is_wp_error( $post_id ) || $post_id < 1 ) {
		throw new RuntimeException( 'Core could not create disposable WXR budget source.' );
	}

	$producer = new WXR_Export_Stager( new Permissions( $settings ) );
	$result   = $producer->stage( $type, 1024 );
	clearstatcache();
	$temp_after = glob( trailingslashit( get_temp_dir() ) . 'wpai-private-artifact*' );
	if (
		! is_wp_error( $result ) ||
		'wxr_stage_too_large' !== $result->get_error_code() ||
		$temp_before !== $temp_after
	) {
		throw new RuntimeException( 'Bounded WXR producer did not reject overflow and clean its private artifact.' );
	}
	echo "PASS: #139 native Core WXR request budget overflow rejects and deletes partial bytes.\n";
} finally {
	wp_set_current_user( $old_user );
	if ( is_int( $post_id ) && $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	unregister_post_type( $type );
	if ( false === $old_grants ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $old_grants, false );
	}
}
