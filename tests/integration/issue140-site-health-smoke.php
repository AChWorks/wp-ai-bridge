<?php
/**
 * Real Core health registry and authenticated native REST dispatch.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Support\Settings;

function wpai140_assert( $ok, $reason ) {
	if ( ! $ok ) {
		throw new RuntimeException( $reason );
	}
}
$actor    = get_current_user_id();
$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$provider = wp_get_ability( 'wp-ai-bridge/site-health' );
wpai140_assert( $provider instanceof WP_Ability, 'Site Health Ability not registered.' );
try {
	$groups = $settings->defaults();
	update_option( Settings::OPTION_NAME, $groups, false );
	wpai140_assert( true !== $provider->check_permissions( array( 'action' => 'list' ) ), 'Default-off Site Configuration exposed Site Health.' );
	$groups[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $groups, false );
	wpai140_assert( true === $provider->check_permissions( array( 'action' => 'list' ) ), 'Authorized WordPress admin blocked.' );

	$registry = $provider->execute( array( 'action' => 'list', 'limit' => 25 ) );
	wpai140_assert( ! is_wp_error( $registry ) && isset( $registry['items'], $registry['total'] ), 'Native registry was not projected.' );
	wpai140_assert( $registry['total'] >= count( $registry['items'] ) && count( $registry['items'] ) <= 25, 'Health registry page not bounded.' );
	wpai140_assert( isset( $registry['has_more'], $registry['registry_truncated'], $registry['next_offset'] ), 'Health registry completeness hidden.' );

	$found_direct = false;
	$found_rest = false;
	$next = 0;
	for ( $batch = 0; $batch < 7; ++$batch ) {
		$page = $provider->execute( array( 'action' => 'list', 'offset' => $next, 'limit' => 25 ) );
		wpai140_assert( ! is_wp_error( $page ), 'Paging native tests failed.' );
		foreach ( $page['items'] as $test ) {
			wpai140_assert( 'not_run' === $test['status'] && '' === $test['checked_at_utc'], 'Inspection fabricated a test outcome.' );
			if ( 'php_default_timezone' === $test['test'] && $test['executable'] ) {
				$found_direct = true;
			}
			if ( 'https_status' === $test['test'] && $test['executable'] ) {
				$found_rest = true;
			}
		}
		if ( ! $page['has_more'] ) {
			break;
		}
		wpai140_assert( $page['next_offset'] > $next, 'Site Health page cursor did not progress.' );
		$next = $page['next_offset'];
	}
	wpai140_assert( $found_direct, 'Native direct PHP timezone test unavailable.' );
	$header = $provider->execute( array( 'action' => 'run', 'test' => 'authorization_header' ) );
	wpai140_assert( is_wp_error( $header ) && 'site_health_test_unavailable' === $header->get_error_code(), 'Local-only header test fabricated public proxy evidence.' );
	$direct = $provider->execute( array( 'action' => 'run', 'test' => 'php_default_timezone' ) );
	wpai140_assert( ! is_wp_error( $direct ) && 1 === count( $direct['items'] ) && 'core_direct' === $direct['items'][0]['source'], 'Exact native direct test did not execute.' );
	wpai140_assert( in_array( $direct['items'][0]['status'], array( 'good', 'recommended', 'critical', 'outcome_unknown' ), true ), 'Native status not represented.' );
	wpai140_assert( '' !== $direct['items'][0]['checked_at_utc'], 'Executed test omitted timestamp.' );

	$provider_filter = static function ( $tests ) {
		$tests['direct']['wpai_provider_unsafe'] = array(
			'label' => '<script>confidential</script><b>Provider private test</b>',
			'test'  => static function () { throw new RuntimeException( 'Untrusted callback executed.' ); },
		);
		return $tests;
	};
	add_filter( 'site_status_tests', $provider_filter );
	$provider_list = $provider->execute( array( 'action' => 'list', 'limit' => 25 ) );
	wpai140_assert( ! is_wp_error( $provider_list ), 'Provider discovery failed.' );
	$unverified = $provider->execute( array( 'action' => 'run', 'test' => 'wpai_provider_unsafe' ) );
	wpai140_assert( is_wp_error( $unverified ) && 'site_health_test_unavailable' === $unverified->get_error_code(), 'Untrusted provider PHP callable was executed.' );
	remove_filter( 'site_status_tests', $provider_filter );

	if ( $found_rest ) {
		$native = $provider->execute( array( 'action' => 'run', 'test' => 'https_status' ) );
		wpai140_assert( ! is_wp_error( $native ) && 'core_rest' === $native['items'][0]['source'], 'Native REST controller route was not used.' );
		$deny = static function () { return 'do_not_allow'; };
		add_filter( 'site_health_test_rest_capability_https_status', $deny );
		$blocked = $provider->execute( array( 'action' => 'run', 'test' => 'https_status' ) );
		wpai140_assert( is_wp_error( $blocked ), 'Overridden Core per-test REST capability was bypassed.' );
		remove_filter( 'site_health_test_rest_capability_https_status', $deny );
	}
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	wpai140_assert( $adapter instanceof WP_Ability, 'Official MCP Adapter unavailable.' );
	$through_adapter = $adapter->execute( array(
		'ability_name' => 'wp-ai-bridge/site-health',
		'parameters' => array( 'action' => 'run', 'test' => 'php_default_timezone' ),
	) );
	wpai140_assert( ! is_wp_error( $through_adapter ) && true === ( $through_adapter['success'] ?? false ), 'Official Adapter rejected authorized Site Health inspection.' );

	wp_set_current_user( 0 );
	wpai140_assert( true !== $provider->check_permissions( array( 'action' => 'list' ) ), 'Anonymous principal could inspect native health.' );
	wp_set_current_user( $actor );
	$groups[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $groups, false );
	wpai140_assert( is_wp_error( $provider->execute( array( 'action' => 'run', 'test' => 'php_default_timezone' ) ) ), 'Revoked Bridge group did not block direct invocation.' );
	echo "PASS: #140 Core Site Health direct+REST+Adapter permission and output smoke.\n";
} finally {
	wp_set_current_user( $actor );
	update_option( Settings::OPTION_NAME, $original, false );
}
