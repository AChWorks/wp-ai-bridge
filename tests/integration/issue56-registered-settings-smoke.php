<?php
/**
 * Real WordPress coverage for provider-neutral registered REST settings.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue56_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue56_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpnb_issue56_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpnb_issue56_register_settings() {
	register_setting(
		'wpnb_issue56',
		'wpnb_issue56_option',
		array(
			'type'              => 'string',
			'label'             => 'Issue 56 public setting',
			'description'       => 'A controlled provider setting for Bridge integration coverage.',
			'default'           => 'provider-default-must-not-leak',
			'sanitize_callback' => static function ( $value ) {
				return strtoupper( sanitize_text_field( (string) $value ) );
			},
			'show_in_rest'      => array(
				'name'   => 'wpnb_issue56_public',
				'schema' => array(
					'type'      => 'string',
					'maxLength' => 64,
				),
			),
		)
	);
	register_setting(
		'wpnb_issue56',
		'wpnb_issue56_hooked_storage',
		array(
			'type'         => 'string',
			'label'        => 'Issue 56 hooked setting',
			'description'  => 'Exercises REST setting get/update provider hooks.',
			'default'      => 'hooked-default-must-not-leak',
			'show_in_rest' => array(
				'name'   => 'wpnb_issue56_hooked',
				'schema' => array( 'type' => 'string' ),
			),
		)
	);
	register_setting(
		'wpnb_issue56',
		'wpnb_issue56_other',
		array(
			'type'         => 'string',
			'label'        => 'Issue 56 unrelated setting',
			'default'      => '',
			'show_in_rest' => true,
		)
	);
	register_setting(
		'wpnb_issue56',
		'wpnb_issue56_hidden',
		array(
			'type'         => 'string',
			'default'      => '',
			'show_in_rest' => false,
		)
	);
	register_setting(
		'wpnb_issue56',
		'wpnb_issue56_api_key',
		array(
			'type'         => 'string',
			'label'        => 'Issue 56 private setting',
			'default'      => '',
			'show_in_rest' => true,
		)
	);
}

$register_callback = static function () {
	wpnb_issue56_register_settings();
};
add_action( 'rest_api_init', $register_callback, 5 );
if ( did_action( 'rest_api_init' ) ) {
	wpnb_issue56_register_settings();
}
rest_get_server();

$pre_get = static function ( $result, $name ) {
	if ( 'wpnb_issue56_hooked' === $name ) {
		return get_option( 'wpnb_issue56_hooked_virtual', 'virtual-initial' );
	}
	return $result;
};
$pre_update = static function ( $updated, $name, $value ) {
	if ( 'wpnb_issue56_hooked' !== $name ) {
		return $updated;
	}
	update_option( 'wpnb_issue56_hooked_virtual', (string) $value, false );
	return true;
};
add_filter( 'rest_pre_get_setting', $pre_get, 10, 3 );
add_filter( 'rest_pre_update_setting', $pre_update, 10, 4 );

$settings = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$original_values = array(
	'wpnb_issue56_option'         => get_option( 'wpnb_issue56_option', null ),
	'wpnb_issue56_hooked_storage' => get_option( 'wpnb_issue56_hooked_storage', null ),
	'wpnb_issue56_hooked_virtual' => get_option( 'wpnb_issue56_hooked_virtual', null ),
	'wpnb_issue56_other'          => get_option( 'wpnb_issue56_other', null ),
	'wpnb_issue56_hidden'         => get_option( 'wpnb_issue56_hidden', null ),
	'wpnb_issue56_api_key'        => get_option( 'wpnb_issue56_api_key', null ),
);
$original_exists = array();
foreach ( array_keys( $original_values ) as $option_name ) {
	$original_exists[ $option_name ] = false !== get_option( $option_name, false );
}
$created_user = 0;
$admin_id = get_current_user_id();

try {
	update_option( 'wpnb_issue56_option', 'initial', false );
	update_option( 'wpnb_issue56_hooked_storage', 'physical-unchanged', false );
	update_option( 'wpnb_issue56_hooked_virtual', 'virtual-initial', false );
	update_option( 'wpnb_issue56_other', 'unrelated-sentinel', false );
	update_option( 'wpnb_issue56_hidden', 'hidden-sentinel', false );
	update_option( 'wpnb_issue56_api_key', 'private-sentinel', false );

	$access = $settings->defaults();
	$access[ Settings::GROUP_SITE_READ ]   = 1;
	$access[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $access, false );

	$list = wpnb_issue56_execute( 'wp-native-builder/registered-settings-list', array( 'per_page' => 100 ) );
	wpnb_issue56_assert( ! is_wp_error( $list ), 'Registered settings discovery failed under Site Read.' );
	$items = array();
	foreach ( $list['items'] as $item ) {
		$items[ $item['name'] ] = $item;
		wpnb_issue56_assert( ! isset( $item['value'], $item['value_json'] ), 'Discovery returned a setting value.' );
	}
	wpnb_issue56_assert( isset( $items['wpnb_issue56_public'], $items['wpnb_issue56_hooked'], $items['wpnb_issue56_other'] ), 'REST-registered safe settings were not discovered.' );
	wpnb_issue56_assert( ! isset( $items['wpnb_issue56_hidden'] ), 'show_in_rest=false setting was discovered.' );
	wpnb_issue56_assert( ! isset( $items['wpnb_issue56_api_key'] ), 'Sensitive registered setting was exposed by discovery.' );
	wpnb_issue56_assert( 'string' === $items['wpnb_issue56_public']['type'], 'Registered REST schema type was not preserved.' );
	wpnb_issue56_assert( true === $items['wpnb_issue56_public']['schema_available'], 'Registered REST schema was unexpectedly unavailable.' );
	wpnb_issue56_assert( false === strpos( $items['wpnb_issue56_public']['schema_json'], 'provider-default-must-not-leak' ), 'Registered setting default leaked through discovery schema.' );
	wpnb_issue56_assert( false === strpos( wp_json_encode( $list ), 'private-sentinel' ), 'Sensitive setting value leaked through discovery.' );

	$blocked_read = wpnb_issue56_execute( 'wp-native-builder/registered-setting-read', array( 'name' => 'wpnb_issue56_public' ) );
	wpnb_issue56_assert( is_wp_error( $blocked_read ), 'Exact registered setting read bypassed Site Configuration.' );
	$blocked_update = wpnb_issue56_execute( 'wp-native-builder/registered-setting-update', array( 'name' => 'wpnb_issue56_public', 'value_json' => '"blocked"' ) );
	wpnb_issue56_assert( is_wp_error( $blocked_update ) && 'initial' === get_option( 'wpnb_issue56_option' ), 'Registered setting update bypassed Site Configuration.' );

	$access[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );

	$read = wpnb_issue56_execute( 'wp-native-builder/registered-setting-read', array( 'name' => 'wpnb_issue56_public' ) );
	wpnb_issue56_assert( ! is_wp_error( $read ) && '"initial"' === $read['value_json'], 'Exact registered setting read did not return the requested Core value.' );
	wpnb_issue56_assert( 'wpnb_issue56_public' === $read['setting']['name'], 'Exact registered setting read returned the wrong contract.' );
	wpnb_issue56_assert( false === strpos( wp_json_encode( $read ), 'unrelated-sentinel' ), 'Exact read leaked an unrelated registered setting value.' );
	wpnb_issue56_assert( false === strpos( wp_json_encode( $read ), 'private-sentinel' ), 'Exact read leaked a sensitive registered setting value.' );

	$sensitive_read = wpnb_issue56_execute( 'wp-native-builder/registered-setting-read', array( 'name' => 'wpnb_issue56_api_key' ) );
	wpnb_issue56_assert( is_wp_error( $sensitive_read ), 'Sensitive registered setting was readable through the generic contract.' );
	$hidden_read = wpnb_issue56_execute( 'wp-native-builder/registered-setting-read', array( 'name' => 'wpnb_issue56_hidden' ) );
	wpnb_issue56_assert( is_wp_error( $hidden_read ), 'Non-REST setting was readable through the generic contract.' );

	$updated = wpnb_issue56_execute(
		'wp-native-builder/registered-setting-update',
		array(
			'name'       => 'wpnb_issue56_public',
			'value_json' => '"  hello <b>world</b>  "',
		)
	);
	wpnb_issue56_assert( ! is_wp_error( $updated ), 'Registered setting update failed.' );
	wpnb_issue56_assert( 'HELLO WORLD' === get_option( 'wpnb_issue56_option' ), 'Provider sanitize callback was not authoritative.' );
	wpnb_issue56_assert( '"HELLO WORLD"' === $updated['value_json'], 'Update did not return the sanitized requested setting value.' );
	wpnb_issue56_assert( true === $updated['changed'], 'Changed registered setting did not report changed=true.' );
	wpnb_issue56_assert( 'unrelated-sentinel' === get_option( 'wpnb_issue56_other' ), 'Exact update mutated an unrelated REST setting.' );
	wpnb_issue56_assert( 'private-sentinel' === get_option( 'wpnb_issue56_api_key' ), 'Exact update mutated a sensitive REST setting.' );
	wpnb_issue56_assert( false === strpos( wp_json_encode( $updated ), 'unrelated-sentinel' ), 'Exact update response leaked unrelated REST setting data.' );
	wpnb_issue56_assert( false === strpos( wp_json_encode( $updated ), 'private-sentinel' ), 'Exact update response leaked sensitive REST setting data.' );

	$hooked = wpnb_issue56_execute(
		'wp-native-builder/registered-setting-update',
		array(
			'name'       => 'wpnb_issue56_hooked',
			'value_json' => '"hooked-new"',
		)
	);
	wpnb_issue56_assert( ! is_wp_error( $hooked ) && '"hooked-new"' === $hooked['value_json'], 'REST provider get/update hooks were not preserved.' );
	wpnb_issue56_assert( 'hooked-new' === get_option( 'wpnb_issue56_hooked_virtual' ), 'rest_pre_update_setting did not own the hooked update.' );
	wpnb_issue56_assert( 'physical-unchanged' === get_option( 'wpnb_issue56_hooked_storage' ), 'Bridge bypassed the provider REST update hook.' );

	$null_update = wpnb_issue56_execute( 'wp-native-builder/registered-setting-update', array( 'name' => 'wpnb_issue56_public', 'value_json' => 'null' ) );
	wpnb_issue56_assert( is_wp_error( $null_update ) && 'HELLO WORLD' === get_option( 'wpnb_issue56_option' ), 'Generic update accepted an implicit null/delete reset.' );

	$created_user = wp_insert_user(
		array(
			'user_login' => 'wpnb_issue56_subscriber',
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'wpnb-issue56@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpnb_issue56_assert( ! is_wp_error( $created_user ), 'Could not create low-authority Issue #56 fixture.' );
	$created_user = (int) $created_user;
	wp_set_current_user( $created_user );

	$denied_list = wpnb_issue56_execute( 'wp-native-builder/registered-settings-list', array() );
	$denied_read = wpnb_issue56_execute( 'wp-native-builder/registered-setting-read', array( 'name' => 'wpnb_issue56_public' ) );
	$denied_update = wpnb_issue56_execute( 'wp-native-builder/registered-setting-update', array( 'name' => 'wpnb_issue56_public', 'value_json' => '"subscriber-change"' ) );
	wpnb_issue56_assert( is_wp_error( $denied_list ) && is_wp_error( $denied_read ) && is_wp_error( $denied_update ), 'Native manage_options authority was bypassed.' );
	wpnb_issue56_assert( 'HELLO WORLD' === get_option( 'wpnb_issue56_option' ), 'Unauthorized principal mutated the registered setting.' );

	wp_set_current_user( $admin_id );
	$compat = wpnb_issue56_execute( 'wp-native-builder/site-settings-read' );
	wpnb_issue56_assert( ! is_wp_error( $compat ) && isset( $compat['site_title'], $compat['permalink_structure'] ), 'Existing bounded site-settings compatibility surface regressed.' );

	echo "PASS: Issue #56 registered REST settings.\n";
} finally {
	wp_set_current_user( $admin_id );
	remove_filter( 'rest_pre_get_setting', $pre_get, 10 );
	remove_filter( 'rest_pre_update_setting', $pre_update, 10 );
	remove_action( 'rest_api_init', $register_callback, 5 );
	if ( $created_user > 0 && get_userdata( $created_user ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $created_user );
	}
	foreach ( $original_values as $option_name => $value ) {
		if ( $original_exists[ $option_name ] ) {
			update_option( $option_name, $value, false );
		} else {
			delete_option( $option_name );
		}
	}
	update_option( Settings::OPTION_NAME, $original_access, false );
}
