<?php
/**
 * Disposable provider-native parser-only preview; no actual import.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\WXR_Export_Stager;
use WP_AI_Bridge\Support\WXR_Native_Import_Plan;
function plan139_check( $ok, $reason ) {
	if ( ! $ok ) {
		throw new RuntimeException( $reason );
	}
}
$actor    = get_current_user_id();
$settings = new Settings();
$prior    = get_option( Settings::OPTION_NAME, $settings->defaults() );
$type     = 'wpai139_native_plan';
$tax      = 'wpai139_plan_tax';
$id       = 0;
$artifact = null;
try {
	plan139_check( $actor > 0 && current_user_can( 'import' ) && current_user_can( 'export' ) && defined( 'WP_LOAD_IMPORTERS' ) && WP_LOAD_IMPORTERS && class_exists( 'WP_Import', false ), 'Native WordPress Importer is unavailable.' );
	$perms   = new Permissions( $settings );
	$planner = new WXR_Native_Import_Plan( $perms );
	$denied  = $planner->inspect( array(), 1, 1, 1, 1, 1 );
	plan139_check( is_wp_error( $denied ) && 'wxr_preview_denied' === $denied->get_error_code(), 'Default deny not enforced.' );
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
	register_taxonomy( $tax, array( $type ), array( 'public' => true ) );
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_title'   => 'WXR Native Plan',
			'post_content' => 'Iran WXR Test',
		),
		true
	);
	plan139_check( ! is_wp_error( $id ) && $id > 0, 'Failed creating test content.' );
	plan139_check( ! is_wp_error( wp_set_object_terms( $id, 'WXR Plan Tax Term', $tax ) ), 'Failed native taxonomy.' );
	update_post_meta( $id, 'wxr139_meta', 'do-not-return-this-value' );
	$artifact = ( new WXR_Export_Stager( $perms ) )->stage( $type, 512 * 1024 );
	plan139_check( ! is_wp_error( $artifact ), 'Failed native WXR stage.' );
	$before = wp_count_posts( $type )->publish;
	$plan   = $planner->inspect( $artifact, 512 * 1024, 35000, 10, 512 * 1024, 8 );
	plan139_check(
		! is_wp_error( $plan ) &&
		'native_parser_informational' === $plan['preview_type'] &&
		false === $plan['authoritative_import_plan'] &&
		false === $plan['import_executed'] &&
		1 === $plan['post_count'] &&
		1 === ( $plan['post_type_counts'][ $type ] ?? 0 ) &&
		0 === $plan['missing_post_type_count'] &&
		0 === $plan['missing_taxonomy_count'] &&
		$plan['postmeta_count'] >= 1 &&
		$plan['author_definitions'] >= 1 &&
		$plan['content_sha256'] === $artifact['sha256'] &&
		wp_count_posts( $type )->publish === $before,
		'Native parser-only preview changed site or returned invalid summary: ' .
		( is_wp_error( $plan ) ? $plan->get_error_code() : 'summary' )
	);
	plan139_check(
		false === strpos( wp_json_encode( $plan ), 'do-not-return-this-value' ),
		'Private post metadata leaked into plan.'
	);
	$small = $planner->inspect( $artifact, 512 * 1024, 35000, 10, 512, 8 );
	plan139_check( is_wp_error( $small ) && 'wxr_plan_budget' === $small->get_error_code(), 'Native parser budget not enforced.' );
	unregister_taxonomy( $tax );
	unregister_post_type( $type );
	$missing = $planner->inspect( $artifact, 512 * 1024, 35000, 10, 512 * 1024, 1 );
	plan139_check(
		! is_wp_error( $missing ) &&
		1 === $missing['missing_post_type_count'] &&
		array( $type ) === $missing['missing_post_types'] &&
		$missing['missing_taxonomy_count'] >= 1 &&
		1 === count( $missing['missing_taxonomies'] ),
		'Missing provider dependencies were not detected.'
	);
	wp_set_current_user( 0 );
	plan139_check( is_wp_error( $planner->inspect( $artifact, 512 * 1024, 35000, 10, 512 * 1024, 8 ) ), 'Anonymous user was allowed parser analysis.' );
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	plan139_check( is_wp_error( $planner->inspect( $artifact, 512 * 1024, 35000, 10, 512 * 1024, 8 ) ), 'Revoked grants were not enforced.' );
	echo "PASS: #139 official native WordPress Importer parser-only WXR summary, quota, dependencies and privacy without import.\n";
} finally {
	wp_set_current_user( $actor );
	if ( ! post_type_exists( $type ) ) {
		register_post_type(
			$type,
			array(
				'public'     => true,
				'can_export' => true,
			)
		);
	}
	if ( ! taxonomy_exists( $tax ) ) {
		register_taxonomy( $tax, array( $type ), array( 'public' => true ) );
	}
	if ( is_int( $id ) && $id > 0 ) {
		wp_delete_post( $id, true );
	}
	unregister_taxonomy( $tax );
	unregister_post_type( $type );
	if ( is_array( $artifact ) && isset( $artifact['path'] ) ) {
		wp_delete_file( $artifact['path'] );
	}
	if ( false === $prior ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $prior, false );
	}
}
