<?php
/** Issue #44 native provider integration fixture. */

if ( ! class_exists( 'WP_AI_Bridge_Issue44_Custom_Ability' ) && class_exists( 'WP_Ability' ) ) {
	class WP_AI_Bridge_Issue44_Custom_Ability extends WP_Ability {}
}

add_action(
	'wp_abilities_api_categories_init',
	static function () {
		wp_register_ability_category(
			'issue44-provider',
			array(
				'label'       => 'Issue 44 Provider',
				'description' => 'Integration-only provider fixture.',
			)
		);
	},
	200
);

add_action(
	'wp_abilities_api_init',
	static function () {
		$base = array(
			'label'         => 'Issue 44 Provider Operation',
			'description'   => 'Integration-only provider operation.',
			'category'      => 'issue44-provider',
			'input_schema'  => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array( 'executed' => array( 'type' => 'boolean' ) ),
				'required'   => array( 'executed' ),
			),
			'meta'          => array(
				'public'      => true,
				'annotations' => array(
					// Intentionally optimistic: Bridge policy must never trust these hints.
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		);

		$allowed = $base;
		$allowed['ability_class']       = 'WP_AI_Bridge_Issue44_Custom_Ability';
		$allowed['permission_callback'] = static function () { return current_user_can( 'manage_options' ); };
		$allowed['execute_callback']    = static function () {
			update_option( 'wp_ai_bridge_issue44_provider_executed', (int) get_option( 'wp_ai_bridge_issue44_provider_executed', 0 ) + 1, false );
			return array( 'executed' => true );
		};
		wp_register_ability( 'issue44/provider-allowed', $allowed );

		$denied = $base;
		$denied['permission_callback'] = static function () { return false; };
		$denied['execute_callback']    = static function () { return array( 'executed' => true ); };
		wp_register_ability( 'issue44/provider-denied', $denied );

		$forged = $base;
		$forged['permission_callback'] = static function () { return current_user_can( 'manage_options' ); };
		$forged['execute_callback']    = static function () { return array( 'executed' => true ); };
		wp_register_ability( 'wp-native-builder/foreign-fixture', $forged );
	},
	200
);
