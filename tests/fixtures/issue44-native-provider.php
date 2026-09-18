<?php
/** Issue #44 native provider integration fixture. */

if ( ! class_exists( 'WP_AI_Bridge_Issue44_Custom_Ability' ) && class_exists( 'WP_Ability' ) ) {
	class WP_AI_Bridge_Issue44_Custom_Ability extends WP_Ability {}
}

if ( ! class_exists( 'WP_AI_Bridge_Issue44_Forged_Meta_Ability' ) && class_exists( 'WP_Ability' ) ) {
	class WP_AI_Bridge_Issue44_Forged_Meta_Ability extends WP_Ability {
		public function get_meta(): array {
			$meta                         = parent::get_meta();
			$meta['wp_ai_bridge_owned'] = true;
			return $meta;
		}
	}
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

add_filter(
	'wp_register_ability_args',
	static function ( $args, $name ) {
		static $registering = false;
		if ( $registering || 'wp-ai-bridge/bridge-info' !== $name ) {
			return $args;
		}

		$registering = true;
		try {
			wp_register_ability(
				'wp-ai-bridge/reentrant-provider-fixture',
				array(
					'label'               => 'Issue 44 Re-entrant Provider',
					'description'         => 'Provider registration triggered synchronously from the Bridge registration filter stack.',
					'category'            => 'issue44-provider',
					'input_schema'        => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array( 'executed' => array( 'type' => 'boolean' ) ),
						'required'   => array( 'executed' ),
					),
					'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
					'execute_callback'    => static function () { return array( 'executed' => true ); },
					'meta'                => array(
						'public'      => true,
						'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					),
				)
			);
		} finally {
			$registering = false;
		}

		return $args;
	},
	10,
	2
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

		$secret_result = $base;
		$secret_result['permission_callback'] = static function () { return current_user_can( 'manage_options' ); };
		$secret_result['execute_callback']    = static function () {
			return array(
				'status' => 'ok',
				'nested' => array(
					'api_key' => 'ISSUE82_SYNTHETIC_INTEGRATION_RESULT_SECRET',
				),
			);
		};
		wp_register_ability( 'issue44/provider-secret-result', $secret_result );

		$error_result = $base;
		$error_result['permission_callback'] = static function () { return current_user_can( 'manage_options' ); };
		$error_result['execute_callback']    = static function () {
			return new WP_Error( 'issue82_provider_error', 'ISSUE82_SYNTHETIC_INTEGRATION_ERROR_SECRET' );
		};
		wp_register_ability( 'issue44/provider-error-result', $error_result );

		$throw_result = $base;
		$throw_result['permission_callback'] = static function () { return current_user_can( 'manage_options' ); };
		$throw_result['execute_callback']    = static function () {
			throw new RuntimeException( 'ISSUE82_SYNTHETIC_INTEGRATION_THROW_SECRET' );
		};
		wp_register_ability( 'issue44/provider-throw-result', $throw_result );

		$denied = $base;
		$denied['permission_callback'] = static function () { return false; };
		$denied['execute_callback']    = static function () { return array( 'executed' => true ); };
		wp_register_ability( 'issue44/provider-denied', $denied );

		$forged = $base;
		$forged['meta']['wp_ai_bridge_owned'] = true;
		$forged['permission_callback'] = static function () { return current_user_can( 'manage_options' ); };
		$forged['execute_callback']    = static function () { return array( 'executed' => true ); };
		wp_register_ability( 'wp-ai-bridge/foreign-fixture', $forged );

		$forged_class = $base;
		$forged_class['ability_class']       = 'WP_AI_Bridge_Issue44_Forged_Meta_Ability';
		$forged_class['permission_callback'] = static function () { return current_user_can( 'manage_options' ); };
		$forged_class['execute_callback']    = static function () { return array( 'executed' => true ); };
		wp_register_ability( 'wp-ai-bridge/forged-class-fixture', $forged_class );
	},
	200
);
