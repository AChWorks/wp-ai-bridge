<?php
/**
 * Real WordPress coverage for provider-neutral registered REST settings.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpai_issue56_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue56_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue56_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpai_issue56_register_settings() {
	register_setting(
		'wpai_issue56',
		'wpai_issue56_option',
		array(
			'type'              => 'string',
			'label'             => 'Issue 56 public setting',
			'description'       => 'A controlled provider setting for Bridge integration coverage.',
			'default'           => 'provider-default-must-not-leak',
			'sanitize_callback' => static function ( $value ) {
				return strtoupper( sanitize_text_field( (string) $value ) );
			},
			'show_in_rest'      => array(
				'name'   => 'wpai_issue56_public',
				'schema' => array(
					'type'      => 'string',
					'maxLength' => 64,
				),
			),
		)
	);
	register_setting(
		'wpai_issue56',
		'wpai_issue56_hooked_storage',
		array(
			'type'         => 'string',
			'label'        => 'Issue 56 hooked setting',
			'description'  => 'Exercises REST setting get/update provider hooks.',
			'default'      => 'hooked-default-must-not-leak',
			'show_in_rest' => array(
				'name'   => 'wpai_issue56_hooked',
				'schema' => array( 'type' => 'string' ),
			),
		)
	);
	register_setting(
		'wpai_issue56',
		'wpai_issue56_other',
		array(
			'type'         => 'string',
			'label'        => 'Issue 56 unrelated setting',
			'default'      => '',
			'show_in_rest' => true,
		)
	);
	register_setting(
		'wpai_issue56',
		'wpai_issue56_hidden',
		array(
			'type'         => 'string',
			'default'      => '',
			'show_in_rest' => false,
		)
	);
	register_setting(
		'wpai_issue56',
		'wpai_issue56_api_key',
		array(
			'type'         => 'string',
			'label'        => 'Issue 56 private setting',
			'default'      => '',
			'show_in_rest' => true,
		)
	);
	register_setting(
		'wpai_issue56',
		'wpai_issue56_license_key',
		array(
			'type'         => 'string',
			'label'        => 'Issue 56 license credential',
			'default'      => '',
			'show_in_rest' => true,
		)
	);
	register_setting(
		'wpai_issue56',
		'wpai_issue56_provider_token',
		array(
			'type'         => 'string',
			'label'        => 'Issue 56 provider token',
			'default'      => '',
			'show_in_rest' => true,
		)
	);
	register_setting(
		'wpai_issue56',
		'wpai_issue56_pattern_bundle',
		array(
			'type'         => 'object',
			'label'        => 'Issue 56 dynamic structured configuration',
			'default'      => array(),
			'show_in_rest' => array(
				'name'   => 'wpai_issue56_pattern_bundle',
				'schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'display_name' => array( 'type' => 'string' ),
					),
					'patternProperties'    => array(
						'^custom_' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			),
		)
	);
	register_setting(
		'wpai_issue56',
		'wpai_issue56_composed_bundle',
		array(
			'type'         => 'object',
			'label'        => 'Issue 56 composed structured configuration',
			'default'      => array(),
			'show_in_rest' => array(
				'name'   => 'wpai_issue56_composed_bundle',
				'schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'payload' => array(
							'type'                 => array( 'string', 'object' ),
							'properties'           => array(
								'display_name' => array( 'type' => 'string' ),
							),
							'oneOf'                => array(
								array( 'type' => 'string' ),
								array(
									'type'                 => 'object',
									'properties'           => array(
										'api_key' => array( 'type' => 'string' ),
									),
									'additionalProperties' => false,
								),
							),
							'additionalProperties' => false,
						),
					),
					'additionalProperties' => false,
				),
			),
		)
	);
	register_setting(
		'wpai_issue56',
		'wpai_issue56_bundle',
		array(
			'type'         => 'object',
			'label'        => 'Issue 56 structured configuration',
			'default'      => array(),
			'show_in_rest' => array(
				'name'   => 'wpai_issue56_bundle',
				'schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'display_name' => array( 'type' => 'string' ),
						'api_key'      => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			),
		)
	);
}

$register_callback = static function () {
	wpai_issue56_register_settings();
};
add_action( 'rest_api_init', $register_callback, 5 );
if ( did_action( 'rest_api_init' ) ) {
	wpai_issue56_register_settings();
}
rest_get_server();

$pre_get = static function ( $result, $name ) {
	if ( 'wpai_issue56_hooked' === $name ) {
		return get_option( 'wpai_issue56_hooked_virtual', 'virtual-initial' );
	}
	return $result;
};
$pre_update = static function ( $updated, $name, $value ) {
	if ( 'wpai_issue56_hooked' !== $name ) {
		return $updated;
	}
	update_option( 'wpai_issue56_hooked_virtual', (string) $value, false );
	return true;
};
add_filter( 'rest_pre_get_setting', $pre_get, 10, 3 );
add_filter( 'rest_pre_update_setting', $pre_update, 10, 4 );

$settings        = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$original_values = array(
	'wpai_issue56_option'          => get_option( 'wpai_issue56_option', null ),
	'wpai_issue56_hooked_storage'  => get_option( 'wpai_issue56_hooked_storage', null ),
	'wpai_issue56_hooked_virtual'  => get_option( 'wpai_issue56_hooked_virtual', null ),
	'wpai_issue56_other'           => get_option( 'wpai_issue56_other', null ),
	'wpai_issue56_hidden'          => get_option( 'wpai_issue56_hidden', null ),
	'wpai_issue56_api_key'         => get_option( 'wpai_issue56_api_key', null ),
	'wpai_issue56_license_key'     => get_option( 'wpai_issue56_license_key', null ),
	'wpai_issue56_provider_token'  => get_option( 'wpai_issue56_provider_token', null ),
	'wpai_issue56_pattern_bundle'  => get_option( 'wpai_issue56_pattern_bundle', null ),
	'wpai_issue56_composed_bundle' => get_option( 'wpai_issue56_composed_bundle', null ),
	'wpai_issue56_bundle'          => get_option( 'wpai_issue56_bundle', null ),
);
$original_exists = array();
foreach ( array_keys( $original_values ) as $option_name ) {
	$original_exists[ $option_name ] = false !== get_option( $option_name, false );
}
$created_user = 0;
$admin_id     = get_current_user_id();

try {
	update_option( 'wpai_issue56_option', 'initial', false );
	update_option( 'wpai_issue56_hooked_storage', 'physical-unchanged', false );
	update_option( 'wpai_issue56_hooked_virtual', 'virtual-initial', false );
	update_option( 'wpai_issue56_other', 'unrelated-sentinel', false );
	update_option( 'wpai_issue56_hidden', 'hidden-sentinel', false );
	update_option( 'wpai_issue56_api_key', 'private-sentinel', false );
	update_option( 'wpai_issue56_license_key', 'license-sentinel', false );
	update_option( 'wpai_issue56_provider_token', 'provider-token-sentinel', false );
	update_option(
		'wpai_issue56_pattern_bundle',
		array(
			'display_name' => 'Visible label',
			'custom_note'  => 'pattern-sentinel',
		),
		false
	);
	update_option(
		'wpai_issue56_composed_bundle',
		array(
			'payload' => array(
				'api_key' => 'composed-private-sentinel',
			),
		),
		false
	);
	update_option(
		'wpai_issue56_bundle',
		array(
			'display_name' => 'Visible label',
			'api_key'      => 'nested-private-sentinel',
		),
		false
	);

	$access                                = $settings->defaults();
	$access[ Settings::GROUP_SITE_READ ]   = 1;
	$access[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $access, false );

	$list = wpai_issue56_execute( 'wp-ai-bridge/registered-settings-list', array( 'per_page' => 100 ) );
	wpai_issue56_assert( ! is_wp_error( $list ), 'Registered settings discovery failed under Site Read.' );
	$overflow_page = wpai_issue56_execute(
		'wp-ai-bridge/registered-settings-list',
		array(
			'page'     => PHP_INT_MAX,
			'per_page' => 100,
		)
	);
	wpai_issue56_assert( is_wp_error( $overflow_page ), 'Overflowing registered settings pagination was not rejected.' );
	$items = array();
	foreach ( $list['items'] as $item ) {
		$items[ $item['name'] ] = $item;
		wpai_issue56_assert( ! isset( $item['value'], $item['value_json'] ), 'Discovery returned a setting value.' );
	}
	wpai_issue56_assert( isset( $items['wpai_issue56_public'], $items['wpai_issue56_hooked'], $items['wpai_issue56_other'] ), 'REST-registered safe settings were not discovered.' );
	wpai_issue56_assert( ! isset( $items['wpai_issue56_hidden'] ), 'show_in_rest=false setting was discovered.' );
	wpai_issue56_assert( ! isset( $items['wpai_issue56_api_key'] ), 'API-key setting was exposed by discovery.' );
	wpai_issue56_assert( ! isset( $items['wpai_issue56_license_key'] ), 'License credential setting was exposed by discovery.' );
	wpai_issue56_assert( ! isset( $items['wpai_issue56_provider_token'] ), 'Provider token setting was exposed by discovery.' );
	wpai_issue56_assert( ! isset( $items['wpai_issue56_pattern_bundle'] ), 'Dynamic structured setting was exposed by discovery.' );
	wpai_issue56_assert( ! isset( $items['wpai_issue56_composed_bundle'] ), 'Composed structured setting was exposed by discovery.' );
	wpai_issue56_assert( ! isset( $items['wpai_issue56_bundle'] ), 'Structured setting with a nested credential field was exposed by discovery.' );
	wpai_issue56_assert( 'string' === $items['wpai_issue56_public']['type'], 'Registered REST schema type was not preserved.' );
	wpai_issue56_assert( true === $items['wpai_issue56_public']['schema_available'], 'Registered REST schema was unexpectedly unavailable.' );
	wpai_issue56_assert( false === strpos( $items['wpai_issue56_public']['schema_json'], 'provider-default-must-not-leak' ), 'Registered setting default leaked through discovery schema.' );
	wpai_issue56_assert( false === strpos( wp_json_encode( $list ), 'private-sentinel' ), 'Sensitive setting value leaked through discovery.' );
	wpai_issue56_assert( false === strpos( wp_json_encode( $list ), 'license-sentinel' ), 'License credential value leaked through discovery.' );
	wpai_issue56_assert( false === strpos( wp_json_encode( $list ), 'provider-token-sentinel' ), 'Provider token value leaked through discovery.' );
	wpai_issue56_assert( false === strpos( wp_json_encode( $list ), 'pattern-sentinel' ), 'Dynamic structured setting value leaked through discovery.' );
	wpai_issue56_assert( false === strpos( wp_json_encode( $list ), 'composed-private-sentinel' ), 'Composed credential value leaked through discovery.' );
	wpai_issue56_assert( false === strpos( wp_json_encode( $list ), 'nested-private-sentinel' ), 'Nested credential value leaked through discovery.' );

	$blocked_read = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-read', array( 'name' => 'wpai_issue56_public' ) );
	wpai_issue56_assert( is_wp_error( $blocked_read ), 'Exact registered setting read bypassed Site Configuration.' );
	$blocked_before = get_option( 'wpai_issue56_option' );
	$blocked_update = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-update', array( 'name' => 'wpai_issue56_public', 'value_json' => '"blocked"' ) );
	wpai_issue56_assert( is_wp_error( $blocked_update ), 'Registered setting update bypassed Site Configuration.' );
	wpai_issue56_assert( $blocked_before === get_option( 'wpai_issue56_option' ), 'Denied registered setting update mutated the option.' );

	$access[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );

	$read = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-read', array( 'name' => 'wpai_issue56_public' ) );
	wpai_issue56_assert( ! is_wp_error( $read ) && true === $read['value_available'] && wp_json_encode( $blocked_before ) === $read['value_json'], 'Exact registered setting read did not return the requested Core value.' );
	wpai_issue56_assert( 'wpai_issue56_public' === $read['setting']['name'], 'Exact registered setting read returned the wrong contract.' );
	wpai_issue56_assert( false === strpos( wp_json_encode( $read ), 'unrelated-sentinel' ), 'Exact read leaked an unrelated registered setting value.' );
	wpai_issue56_assert( false === strpos( wp_json_encode( $read ), 'private-sentinel' ), 'Exact read leaked a sensitive registered setting value.' );

	$sensitive_read = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-read', array( 'name' => 'wpai_issue56_api_key' ) );
	wpai_issue56_assert( is_wp_error( $sensitive_read ), 'Sensitive registered setting was readable through the generic contract.' );
	$license_read = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-read', array( 'name' => 'wpai_issue56_license_key' ) );
	wpai_issue56_assert( is_wp_error( $license_read ), 'License credential setting was readable through the generic contract.' );
	$token_read = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-read', array( 'name' => 'wpai_issue56_provider_token' ) );
	wpai_issue56_assert( is_wp_error( $token_read ), 'Provider token setting was readable through the generic contract.' );
	$token_update = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-update', array( 'name' => 'wpai_issue56_provider_token', 'value_json' => '"changed"' ) );
	wpai_issue56_assert( is_wp_error( $token_update ) && 'provider-token-sentinel' === get_option( 'wpai_issue56_provider_token' ), 'Provider token setting was writable through the generic contract.' );
	$pattern_read = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-read', array( 'name' => 'wpai_issue56_pattern_bundle' ) );
	wpai_issue56_assert( is_wp_error( $pattern_read ), 'Dynamic structured setting was readable through the generic contract.' );
	$composed_read = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-read', array( 'name' => 'wpai_issue56_composed_bundle' ) );
	wpai_issue56_assert( is_wp_error( $composed_read ), 'Composed credential setting was readable through the generic contract.' );
	$composed_update = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-update', array( 'name' => 'wpai_issue56_composed_bundle', 'value_json' => '{"payload":"safe"}' ) );
	wpai_issue56_assert( is_wp_error( $composed_update ) && 'composed-private-sentinel' === get_option( 'wpai_issue56_composed_bundle' )['payload']['api_key'], 'Composed credential setting was writable through the generic contract.' );
	$bundle_read = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-read', array( 'name' => 'wpai_issue56_bundle' ) );
	wpai_issue56_assert( is_wp_error( $bundle_read ), 'Structured setting with a nested credential field was readable through the generic contract.' );
	$hidden_read = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-read', array( 'name' => 'wpai_issue56_hidden' ) );
	wpai_issue56_assert( is_wp_error( $hidden_read ), 'Non-REST setting was readable through the generic contract.' );

	$updated = wpai_issue56_execute(
		'wp-ai-bridge/registered-setting-update',
		array(
			'name'       => 'wpai_issue56_public',
			'value_json' => '"  hello <b>world</b>  "',
		)
	);
	wpai_issue56_assert( ! is_wp_error( $updated ), 'Registered setting update failed.' );
	wpai_issue56_assert( 'HELLO WORLD' === get_option( 'wpai_issue56_option' ), 'Provider sanitize callback was not authoritative.' );
	wpai_issue56_assert( true === $updated['value_available'] && '"HELLO WORLD"' === $updated['value_json'], 'Update did not return the sanitized requested setting value.' );
	wpai_issue56_assert( 'unrelated-sentinel' === get_option( 'wpai_issue56_other' ), 'Exact update mutated an unrelated REST setting.' );
	wpai_issue56_assert( 'private-sentinel' === get_option( 'wpai_issue56_api_key' ), 'Exact update mutated a sensitive REST setting.' );
	wpai_issue56_assert( 'license-sentinel' === get_option( 'wpai_issue56_license_key' ), 'Exact update mutated a license credential setting.' );
	wpai_issue56_assert( 'provider-token-sentinel' === get_option( 'wpai_issue56_provider_token' ), 'Exact update mutated a provider token setting.' );
	wpai_issue56_assert( 'pattern-sentinel' === get_option( 'wpai_issue56_pattern_bundle' )['custom_note'], 'Exact update mutated a dynamic structured setting.' );
	wpai_issue56_assert( 'composed-private-sentinel' === get_option( 'wpai_issue56_composed_bundle' )['payload']['api_key'], 'Exact update mutated a composed credential setting.' );
	wpai_issue56_assert( 'nested-private-sentinel' === get_option( 'wpai_issue56_bundle' )['api_key'], 'Exact update mutated a nested credential setting.' );
	wpai_issue56_assert( false === strpos( wp_json_encode( $updated ), 'unrelated-sentinel' ), 'Exact update response leaked unrelated REST setting data.' );
	wpai_issue56_assert( false === strpos( wp_json_encode( $updated ), 'private-sentinel' ), 'Exact update response leaked sensitive REST setting data.' );

	$hooked = wpai_issue56_execute(
		'wp-ai-bridge/registered-setting-update',
		array(
			'name'       => 'wpai_issue56_hooked',
			'value_json' => '"hooked-new"',
		)
	);
	wpai_issue56_assert( ! is_wp_error( $hooked ) && true === $hooked['value_available'] && '"hooked-new"' === $hooked['value_json'], 'REST provider get/update hooks were not preserved.' );
	wpai_issue56_assert( 'hooked-new' === get_option( 'wpai_issue56_hooked_virtual' ), 'rest_pre_update_setting did not own the hooked update.' );
	wpai_issue56_assert( 'physical-unchanged' === get_option( 'wpai_issue56_hooked_storage' ), 'Bridge bypassed the provider REST update hook.' );

	$null_update = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-update', array( 'name' => 'wpai_issue56_public', 'value_json' => 'null' ) );
	wpai_issue56_assert( is_wp_error( $null_update ) && 'HELLO WORLD' === get_option( 'wpai_issue56_option' ), 'Generic update accepted an implicit null/delete reset.' );

	$created_user = wp_insert_user(
		array(
			'user_login' => 'wpai_issue56_subscriber',
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'wpai-issue56@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpai_issue56_assert( ! is_wp_error( $created_user ), 'Could not create low-authority Issue #56 fixture.' );
	$created_user = (int) $created_user;
	wp_set_current_user( $created_user );

	$denied_list   = wpai_issue56_execute( 'wp-ai-bridge/registered-settings-list', array() );
	$denied_read   = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-read', array( 'name' => 'wpai_issue56_public' ) );
	$denied_update = wpai_issue56_execute( 'wp-ai-bridge/registered-setting-update', array( 'name' => 'wpai_issue56_public', 'value_json' => '"subscriber-change"' ) );
	wpai_issue56_assert( is_wp_error( $denied_list ) && is_wp_error( $denied_read ) && is_wp_error( $denied_update ), 'Native manage_options authority was bypassed.' );
	wpai_issue56_assert( 'HELLO WORLD' === get_option( 'wpai_issue56_option' ), 'Unauthorized principal mutated the registered setting.' );

	wp_set_current_user( $admin_id );
	$compat = wpai_issue56_execute( 'wp-ai-bridge/site-settings-read' );
	wpai_issue56_assert( ! is_wp_error( $compat ) && isset( $compat['site_title'], $compat['permalink_structure'] ), 'Existing bounded site-settings compatibility surface regressed.' );

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
