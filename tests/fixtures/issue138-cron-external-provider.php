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
		return $profiles;
	}
);
