<?php
/**
 * Native WordPress/official MCP Adapter smoke for read-only WP-Cron inspection.
 *
 * Runs only in the repository's disposable WordPress integration lanes.
 * Scheduling below is test-fixture setup, never a public Bridge mutation.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

/**
 * @param bool   $condition Assertion.
 * @param string $reason    Failure reason.
 * @return void
 */
function wpai138_native_assert( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
}

$settings        = new Settings();
$original_groups = get_option( Settings::OPTION_NAME, false );
$actor           = get_current_user_id();
$hook            = 'wpai138_cron_test_' . get_current_blog_id() . '_' . time();
$secret_marker   = 'wpai-secret-fixture-' . bin2hex( random_bytes( 8 ) );
$single_args     = array( 'private_note' => $secret_marker );
$recurring_args  = array( 'type' => 'recurring' );
$single_at       = time() + 3600;
$recurring_at    = $single_at + 120;
$callback        = static function () {
	// The test only inspects future events; never run an effectful callback.
};
add_action( $hook, $callback );

try {
	$ability = wp_get_ability( 'wp-ai-bridge/cron-events-read' );
	wpai138_native_assert( $ability instanceof WP_Ability, 'Cron read Ability not registered on native WordPress.' );
	$grants = $settings->defaults();
	update_option( Settings::OPTION_NAME, $grants, false );
	wpai138_native_assert(
		true !== $ability->check_permissions( array( 'action' => 'list' ) ),
		'Default-off Site Configuration allowed Cron inspection.'
	);
	wpai138_native_assert(
		is_wp_error( $ability->execute( array( 'action' => 'list' ) ) ),
		'Direct execution bypassed default-off permission.'
	);

	$grants[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	wpai138_native_assert(
		true === $ability->check_permissions( array( 'action' => 'list' ) ),
		'Authorized WordPress administrator cannot inspect Cron.'
	);

	wpai138_native_assert(
		true === wp_schedule_single_event( $single_at, $hook, $single_args, true ),
		'WordPress native single-event fixture could not be scheduled.'
	);
	wpai138_native_assert(
		true === wp_schedule_event( $recurring_at, 'hourly', $hook, $recurring_args, true ),
		'WordPress native recurring fixture could not be scheduled.'
	);
	wpai138_native_assert(
		wp_get_scheduled_event( $hook, $single_args, $single_at ) instanceof stdClass &&
		wp_get_scheduled_event( $hook, $recurring_args, $recurring_at ) instanceof stdClass,
		'Native WP-Cron did not persist both fixture events.'
	);

	$page1 = $ability->execute( array( 'hook' => $hook, 'limit' => 1 ) );
	wpai138_native_assert(
		! is_wp_error( $page1 ) && 2 === $page1['total'] && $page1['has_more'] &&
		1 === $page1['next_offset'] && 1 === count( $page1['items'] ),
		'Native Cron first-page identity and completeness failed.'
	);
	wpai138_native_assert(
		$hook === $page1['items'][0]['hook'] && $page1['items'][0]['callback_registered'] &&
		'' === $page1['items'][0]['recurrence'] &&
		false === $page1['items'][0]['recurrence_truncated'],
		'Native single-event metadata was misreported.'
	);
	$page2 = $ability->execute(
		array(
			'hook'              => $hook,
			'offset'            => 1,
			'limit'             => 1,
			'expected_snapshot' => $page1['snapshot'],
		)
	);
	wpai138_native_assert(
		! is_wp_error( $page2 ) && 2 === $page2['total'] &&
		! $page2['has_more'] && 2 === $page2['next_offset'] &&
		3600 === $page2['items'][0]['interval_seconds'] &&
		'hourly' === $page2['items'][0]['recurrence'],
		'Native recurring event or verified snapshot page failed.'
	);
	wpai138_native_assert(
		false === strpos( wp_json_encode( $page1 ), $secret_marker ) &&
		false === strpos( wp_json_encode( $page2 ), $secret_marker ) &&
		64 === strlen( $page1['items'][0]['event_id'] ),
		'Cron inspection revealed protected arguments or an unbound event ID.'
	);

	$exact = $ability->execute(
		array(
			'action'   => 'get',
			'event_id' => $page2['items'][0]['event_id'],
		)
	);
	wpai138_native_assert(
		! is_wp_error( $exact ) && 1 === $exact['total'] &&
		$page2['items'][0]['event_id'] === $exact['items'][0]['event_id'],
		'Native exact event readback did not retain identity.'
	);

	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	wpai138_native_assert( $adapter instanceof WP_Ability, 'Official MCP Adapter Ability was not installed.' );
	$via_adapter = $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/cron-events-read',
			'parameters'   => array( 'action' => 'list', 'hook' => $hook, 'limit' => 2 ),
		)
	);
	wpai138_native_assert(
		! is_wp_error( $via_adapter ) && true === ( $via_adapter['success'] ?? false ) &&
		2 === ( $via_adapter['data']['total'] ?? null ),
		'Official Adapter rejected bounded authorized Cron inventory.'
	);

	// WP Cron is mutable independently of the Bridge. Stale continuations
	// must fail rather than splice a new event into the previous snapshot.
	wpai138_native_assert(
		true === wp_schedule_single_event( $single_at + 300, $hook, array( 'kind' => 'third' ), true ),
		'Third native event setup failed.'
	);
	$stale = $ability->execute(
		array(
			'hook'              => $hook,
			'offset'            => 1,
			'expected_snapshot' => $page1['snapshot'],
		)
	);
	wpai138_native_assert(
		is_wp_error( $stale ) && 'cron_stale_snapshot' === $stale->get_error_code(),
		'Native event mutation did not invalidate stale Cron paging.'
	);
	wp_set_current_user( 0 );
	wpai138_native_assert(
		true !== $ability->check_permissions( array( 'action' => 'list' ) ),
		'Anonymous principal gained Cron inventory access.'
	);
	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wpai138_native_assert(
		is_wp_error( $ability->execute( array( 'action' => 'list', 'hook' => $hook ) ) ),
		'Revoked Site Configuration grant did not block native Cron execution.'
	);

	echo "PASS: #138 native WordPress/official Adapter read-only Cron inventory smoke.\n";
} finally {
	wp_set_current_user( $actor );
	// Only remove synthetic fixture events for this unique test hook.
	wp_clear_scheduled_hook( $hook, $single_args );
	wp_clear_scheduled_hook( $hook, $recurring_args );
	wp_clear_scheduled_hook( $hook, array( 'kind' => 'third' ) );
	remove_action( $hook, $callback );
	if ( false === $original_groups ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original_groups, false );
	}
}
