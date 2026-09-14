<?php
/**
 * Typed-object and unregistered-option coverage for Issue #56.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue56_typed_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue56_typed_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpnb_issue56_typed_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

$settings        = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$typed_exists    = false !== get_option( 'wpnb_issue56_typed_option', false );
$typed_original  = get_option( 'wpnb_issue56_typed_option', null );
$raw_exists      = false !== get_option( 'wpnb_issue56_unregistered', false );
$raw_original    = get_option( 'wpnb_issue56_unregistered', null );

register_setting(
	'wpnb_issue56',
	'wpnb_issue56_typed_option',
	array(
		'type'         => 'object',
		'label'        => 'Issue 56 typed object',
		'description'  => 'A safe structured provider setting.',
		'default'      => array(
			'mode'    => 'wide',
			'columns' => 2,
		),
		'show_in_rest' => array(
			'name'   => 'wpnb_issue56_typed',
			'schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'mode'    => array(
						'type' => 'string',
						'enum' => array( 'wide', 'compact' ),
					),
					'columns' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 4,
					),
				),
				'additionalProperties' => false,
			),
		),
	)
);

try {
	update_option(
		'wpnb_issue56_typed_option',
		array(
			'mode'    => 'wide',
			'columns' => 2,
		),
		false
	);
	update_option( 'wpnb_issue56_unregistered', 'raw-sentinel', false );

	$access                                = $settings->defaults();
	$access[ Settings::GROUP_SITE_READ ]   = 1;
	$access[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );

	$list = wpnb_issue56_typed_execute( 'wp-native-builder/registered-settings-list', array( 'per_page' => 100 ) );
	wpnb_issue56_typed_assert( ! is_wp_error( $list ), 'Typed registered settings discovery failed.' );
	$items = array();
	foreach ( $list['items'] as $item ) {
		$items[ $item['name'] ] = $item;
	}
	wpnb_issue56_typed_assert( isset( $items['wpnb_issue56_typed'] ), 'Safe typed object setting was not discovered.' );
	wpnb_issue56_typed_assert( 'object' === $items['wpnb_issue56_typed']['type'], 'Typed object schema type was not preserved.' );
	wpnb_issue56_typed_assert( ! isset( $items['wpnb_issue56_unregistered'] ), 'Unregistered option appeared in discovery.' );

	$read = wpnb_issue56_typed_execute( 'wp-native-builder/registered-setting-read', array( 'name' => 'wpnb_issue56_typed' ) );
	wpnb_issue56_typed_assert( ! is_wp_error( $read ) && true === $read['value_available'], 'Typed object read failed.' );
	$read_value = json_decode( $read['value_json'], true );
	wpnb_issue56_typed_assert( array( 'mode' => 'wide', 'columns' => 2 ) === $read_value, 'Typed object read did not preserve structured value.' );

	$updated = wpnb_issue56_typed_execute(
		'wp-native-builder/registered-setting-update',
		array(
			'name'       => 'wpnb_issue56_typed',
			'value_json' => '{"mode":"compact","columns":3}',
		)
	);
	wpnb_issue56_typed_assert( ! is_wp_error( $updated ) && true === $updated['value_available'], 'Typed object update failed.' );
	$stored = get_option( 'wpnb_issue56_typed_option' );
	wpnb_issue56_typed_assert( is_array( $stored ) && 'compact' === $stored['mode'] && 3 === (int) $stored['columns'], 'Core did not persist the typed object update.' );
	wpnb_issue56_typed_assert( array( 'mode' => 'compact', 'columns' => 3 ) === json_decode( $updated['value_json'], true ), 'Typed update result did not preserve structured value.' );

	$invalid = wpnb_issue56_typed_execute(
		'wp-native-builder/registered-setting-update',
		array(
			'name'       => 'wpnb_issue56_typed',
			'value_json' => '{"mode":"compact","columns":9}',
		)
	);
	wpnb_issue56_typed_assert( is_wp_error( $invalid ), 'Core schema validation did not reject invalid typed object input.' );
	$stored_after_invalid = get_option( 'wpnb_issue56_typed_option' );
	wpnb_issue56_typed_assert( is_array( $stored_after_invalid ) && 3 === (int) $stored_after_invalid['columns'], 'Invalid typed update mutated the setting.' );

	$unregistered_read = wpnb_issue56_typed_execute( 'wp-native-builder/registered-setting-read', array( 'name' => 'wpnb_issue56_unregistered' ) );
	wpnb_issue56_typed_assert( is_wp_error( $unregistered_read ), 'Unregistered option was readable through the generic contract.' );
	$unregistered_update = wpnb_issue56_typed_execute(
		'wp-native-builder/registered-setting-update',
		array(
			'name'       => 'wpnb_issue56_unregistered',
			'value_json' => '"changed"',
		)
	);
	wpnb_issue56_typed_assert( is_wp_error( $unregistered_update ), 'Unregistered option was writable through the generic contract.' );
	wpnb_issue56_typed_assert( 'raw-sentinel' === get_option( 'wpnb_issue56_unregistered' ), 'Rejected unregistered update changed the raw option.' );

	echo "PASS: Issue #56 typed and unregistered settings.\n";
} finally {
	unregister_setting( 'wpnb_issue56', 'wpnb_issue56_typed_option' );
	if ( $typed_exists ) {
		update_option( 'wpnb_issue56_typed_option', $typed_original, false );
	} else {
		delete_option( 'wpnb_issue56_typed_option' );
	}
	if ( $raw_exists ) {
		update_option( 'wpnb_issue56_unregistered', $raw_original, false );
	} else {
		delete_option( 'wpnb_issue56_unregistered' );
	}
	update_option( Settings::OPTION_NAME, $original_access, false );
}
