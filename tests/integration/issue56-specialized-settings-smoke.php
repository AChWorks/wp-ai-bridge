<?php
/**
 * Real WordPress regression coverage for specialized Core setting ownership.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpai_issue56_specialized_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue56_specialized_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue56_specialized_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

$settings            = new Settings();
$original_access     = get_option( Settings::OPTION_NAME, $settings->defaults() );
$specialized_options = array(
	'blogname',
	'blogdescription',
	'show_on_front',
	'page_on_front',
	'page_for_posts',
	'posts_per_page',
	'permalink_structure',
);
$original_values = array();
$original_exists = array();
foreach ( $specialized_options as $option_name ) {
	$original_values[ $option_name ] = get_option( $option_name, null );
	$original_exists[ $option_name ] = false !== get_option( $option_name, false );
}

$created_ids = array();
$admin_id    = get_current_user_id();

try {
	$access                                = $settings->defaults();
	$access[ Settings::GROUP_SITE_READ ]   = 1;
	$access[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );

	rest_get_server();
	$registered = get_registered_settings();
	$rest_names = array();
	foreach ( $specialized_options as $option_name ) {
		if ( ! isset( $registered[ $option_name ] ) || empty( $registered[ $option_name ]['show_in_rest'] ) ) {
			continue;
		}
		$rest_name = $option_name;
		if ( is_array( $registered[ $option_name ]['show_in_rest'] ) && ! empty( $registered[ $option_name ]['show_in_rest']['name'] ) ) {
			$rest_name = (string) $registered[ $option_name ]['show_in_rest']['name'];
		}
		$rest_names[ $option_name ] = $rest_name;
	}

	wpai_issue56_specialized_assert( isset( $rest_names['page_on_front'], $rest_names['page_for_posts'], $rest_names['posts_per_page'] ), 'Expected Core front-page settings were not REST registered.' );

	$list = wpai_issue56_specialized_execute( 'wp-ai-bridge/registered-settings-list', array( 'per_page' => 100 ) );
	wpai_issue56_specialized_assert( ! is_wp_error( $list ), 'Registered settings discovery failed for specialized Core settings.' );
	$listed_names = array();
	foreach ( $list['items'] as $item ) {
		$listed_names[] = $item['name'];
	}
	wpai_issue56_specialized_assert( in_array( $rest_names['page_on_front'], $listed_names, true ), 'Specialized front-page setting disappeared from generic discovery.' );

	$read = wpai_issue56_specialized_execute(
		'wp-ai-bridge/registered-setting-read',
		array( 'name' => $rest_names['page_on_front'] )
	);
	wpai_issue56_specialized_assert( ! is_wp_error( $read ) && true === $read['value_available'], 'Specialized front-page setting was not generically readable.' );
	wpai_issue56_specialized_assert( wp_json_encode( (int) get_option( 'page_on_front', 0 ) ) === $read['value_json'], 'Generic read returned the wrong front-page value.' );

	foreach ( $rest_names as $option_name => $rest_name ) {
		$before = get_option( $option_name );
		$blocked = wpai_issue56_specialized_execute(
			'wp-ai-bridge/registered-setting-update',
			array(
				'name'       => $rest_name,
				'value_json' => wp_json_encode( $before ),
			)
		);
		wpai_issue56_specialized_assert( is_wp_error( $blocked ) && 'registered_setting_specialized_update_required' === $blocked->get_error_code(), 'Generic update did not preserve specialized ownership for ' . $option_name . '.' );
		wpai_issue56_specialized_assert( $before === get_option( $option_name ), 'Blocked generic update mutated specialized option ' . $option_name . '.' );
	}

	$front_id = wp_insert_post(
		array(
			'post_title'  => 'Issue 56 front page',
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	);
	$posts_id = wp_insert_post(
		array(
			'post_title'  => 'Issue 56 posts page',
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	);
	$post_id = wp_insert_post(
		array(
			'post_title'  => 'Issue 56 non-page target',
			'post_type'   => 'post',
			'post_status' => 'publish',
		)
	);
	wpai_issue56_specialized_assert( ! is_wp_error( $front_id ) && $front_id > 0 && ! is_wp_error( $posts_id ) && $posts_id > 0 && ! is_wp_error( $post_id ) && $post_id > 0, 'Could not create front-page regression fixtures.' );
	$created_ids = array( (int) $front_id, (int) $posts_id, (int) $post_id );

	$valid = wpai_issue56_specialized_execute(
		'wp-ai-bridge/site-settings-update',
		array(
			'show_on_front'  => 'page',
			'page_on_front'  => (int) $front_id,
			'page_for_posts' => (int) $posts_id,
		)
	);
	wpai_issue56_specialized_assert( ! is_wp_error( $valid ), 'Specialized front-page update rejected a valid distinct transition.' );
	wpai_issue56_specialized_assert( (int) $front_id === (int) get_option( 'page_on_front' ) && (int) $posts_id === (int) get_option( 'page_for_posts' ), 'Specialized valid front-page transition was not persisted.' );

	$blocked_equal = wpai_issue56_specialized_execute(
		'wp-ai-bridge/registered-setting-update',
		array(
			'name'       => $rest_names['page_on_front'],
			'value_json' => wp_json_encode( (int) $posts_id ),
		)
	);
	wpai_issue56_specialized_assert( is_wp_error( $blocked_equal ) && 'registered_setting_specialized_update_required' === $blocked_equal->get_error_code(), 'Generic update bypassed specialized equal-page protection.' );
	wpai_issue56_specialized_assert( (int) $front_id === (int) get_option( 'page_on_front' ) && (int) $posts_id === (int) get_option( 'page_for_posts' ), 'Blocked equal-page generic update changed front-page state.' );

	$blocked_non_page = wpai_issue56_specialized_execute(
		'wp-ai-bridge/registered-setting-update',
		array(
			'name'       => $rest_names['page_for_posts'],
			'value_json' => wp_json_encode( (int) $post_id ),
		)
	);
	wpai_issue56_specialized_assert( is_wp_error( $blocked_non_page ) && 'registered_setting_specialized_update_required' === $blocked_non_page->get_error_code(), 'Generic update bypassed specialized page-target validation.' );
	wpai_issue56_specialized_assert( (int) $posts_id === (int) get_option( 'page_for_posts' ), 'Blocked non-page generic update changed posts-page state.' );

	$posts_per_page_before = get_option( 'posts_per_page' );
	$blocked_posts_per_page = wpai_issue56_specialized_execute(
		'wp-ai-bridge/registered-setting-update',
		array(
			'name'       => $rest_names['posts_per_page'],
			'value_json' => '0',
		)
	);
	wpai_issue56_specialized_assert( is_wp_error( $blocked_posts_per_page ) && 'registered_setting_specialized_update_required' === $blocked_posts_per_page->get_error_code(), 'Generic update bypassed specialized posts_per_page bounds.' );
	wpai_issue56_specialized_assert( $posts_per_page_before === get_option( 'posts_per_page' ), 'Blocked posts_per_page generic update mutated the option.' );

	$invalid_specialized = wpai_issue56_specialized_execute(
		'wp-ai-bridge/site-settings-update',
		array(
			'page_on_front'  => (int) $posts_id,
			'page_for_posts' => (int) $posts_id,
		)
	);
	wpai_issue56_specialized_assert( is_wp_error( $invalid_specialized ) && 'front_pages_must_differ' === $invalid_specialized->get_error_code(), 'Specialized equal-page invariant regressed.' );
	wpai_issue56_specialized_assert( (int) $front_id === (int) get_option( 'page_on_front' ) && (int) $posts_id === (int) get_option( 'page_for_posts' ), 'Rejected specialized transition changed front-page state.' );

	echo "PASS: Issue #56 specialized site-setting ownership.\n";
} finally {
	wp_set_current_user( $admin_id );
	foreach ( $original_values as $option_name => $value ) {
		if ( $original_exists[ $option_name ] ) {
			update_option( $option_name, $value, false );
		} else {
			delete_option( $option_name );
		}
	}
	update_option( Settings::OPTION_NAME, $original_access, false );
	foreach ( $created_ids as $post_id ) {
		if ( get_post( $post_id ) ) {
			wp_delete_post( $post_id, true );
		}
	}
}
