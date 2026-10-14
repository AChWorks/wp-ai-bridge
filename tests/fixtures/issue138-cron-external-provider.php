<?php
/**
 * Disposable provider opt-in to prove HTTPS MCP Cron lifecycle from outside PHP.
 * It MUST only be installed on an isolated WordPress integration fixture.
 *
 * @package WP_AI_Bridge
 */

add_action(
	'wpai138_external_cron_fixture',
	static function () {
		// This callback should not execute during lifecycle management.
		update_option( 'wpai138_unexpected_callback_execution', 1, false );
	}
);

add_filter(
	'wp_ai_bridge_cron_profiles',
	static function ( $profiles ) {
		$profiles['fixture_external'] = array(
			'hook'                 => 'wpai138_external_cron_fixture',
			'args'                 => array(),
			'actions'              => array( 'schedule', 'reschedule', 'unschedule' ),
			'min_interval_seconds' => 300,
		);
		$profiles['fixture_external_targeted'] = array(
			'hook'                 => 'wpai138_external_targeted_fixture',
			'args'                 => array(),
			'actions'              => array( 'schedule', 'unschedule', 'reschedule', 'run_now' ),
			'min_interval_seconds' => 300,
			'runner_revision'      => 'external-provider-v1',
			'run_budget_seconds'   => 5,
			'run_destructive'      => false,
			'targeted_runner'      => new class implements \WP_AI_Bridge\Support\Cron_Targeted_Runner {
				public function run_once( $context ) {
					$ref = hash( 'sha256', 'targeted|' . $context['event_id'] . '|' . $context['operation_id'] );
					update_option( 'wpai138_external_runnow_effect', $ref, false );
					update_option( 'wpai138_external_runnow_count', (int) get_option( 'wpai138_external_runnow_count', 0 ) + 1, false );
					return array( 'status' => 'completed', 'effect_ref' => $ref );
				}
				public function verify( $context, $effect_ref ) {
					return is_string( $effect_ref ) && hash_equals( (string) get_option( 'wpai138_external_runnow_effect', '' ), $effect_ref );
				}
			},
		);
		return $profiles;
	}
);

// The regular Cron route shares the provider's durable manual-run fence.
// This fixture is never allowed to create a second business effect.
add_action(
	'wpai138_external_targeted_fixture',
	static function () {
		if ( get_option( 'wpai138_external_runnow_effect', false ) ) {
			return;
		}
		update_option( 'wpai138_unexpected_callback_execution', 1, false );
	}
);
