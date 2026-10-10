<?php
/**
 * Issue #119: measured bounded REST registry behavior on real WordPress.
 *
 * The registry-heavy stages use inert synthetic provider registrations
 * inside an isolated throwaway CI WordPress process. No production mutations.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai119capacity_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai119capacity_measure( $callback ) {
	$start = microtime( true );
	$memory = memory_get_usage( true );
	$result = call_user_func( $callback );
	return array(
		'result'             => $result,
		'elapsed_ms'         => (int) round( 1000 * ( microtime( true ) - $start ) ),
		'memory_delta_bytes' => memory_get_usage( true ) - $memory,
		'memory_peak_bytes'  => memory_get_peak_usage( true ),
	);
}

$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, false );
$ability  = wp_get_ability( 'wp-ai-bridge/rest-route-invoke' );
wpai119capacity_check( $ability instanceof WP_Ability, 'Generic REST Ability unavailable for capacity measurement.' );
$server = rest_get_server();
$routes = $server->get_routes();
wpai119capacity_check( is_array( $routes ) && count( $routes ) < 4096, 'Baseline already exceeds the intended 4096-route safety limit.' );
$original_count = count( $routes );
$calls = 0;
$register = static function ( $path ) use ( $server, &$calls ) {
	$server->register_route(
		'wpai119capacity/v1',
		$path,
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () use ( &$calls ) {
					++$calls;
					return array( 'ok' => true );
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			),
		)
	);
};
$input = array(
	'route'  => '/wpai119capacity/v1/ping',
	'path'   => '/wpai119capacity/v1/ping',
	'method' => 'GET',
);
$measure = static function () use ( $ability, $input ) {
	return wpai119capacity_measure( static function () use ( $ability, $input ) {
		return $ability->execute( $input );
	} );
};

try {
	$grants = $settings->defaults();
	foreach ( $settings->groups() as $key => $definition ) {
		$grants[ $key ] = 1;
	}
	update_option( Settings::OPTION_NAME, $grants, false );
	$register( '/wpai119capacity/v1/ping' );
	$small_count = count( $server->get_routes() );
	$small       = $measure();
	wpai119capacity_check(
		! is_wp_error( $small['result'] ) && 'reported_success' === $small['result']['outcome'] &&
		true === $small['result']['data']['ok'],
		'Small registry baseline unexpectedly failed.'
	);

	$index = 0;
	$heavy_needed = max( 0, 2048 - $small_count );
	for ( $i = 0; $i < $heavy_needed; ++$i ) {
		$register( sprintf( '/wpai119capacity/v1/entry-%04d', $index ) );
		++$index;
	}
	$heavy_count = count( $server->get_routes() );
	wpai119capacity_check( $heavy_count >= 2048 && $heavy_count < 4096, 'Synthetic provider-heavy route count is incorrect.' );
	$heavy       = $measure();
	wpai119capacity_check( ! is_wp_error( $heavy['result'] ), 'Provider-heavy registry call unexpectedly failed.' );

	// Fill to precisely 4096 routes and prove the last allowed registry is
	// bounded, then exceed the limit by ONE and prove no provider dispatch.
	$near_needed = 4096 - $heavy_count;
	for ( $i = 0; $i < $near_needed; ++$i ) {
		$register( sprintf( '/wpai119capacity/v1/entry-%04d', $index ) );
		++$index;
	}
	$near_count = count( $server->get_routes() );
	wpai119capacity_check( 4096 === $near_count, 'Near-ceiling registry did not register exactly 4096 routes.' );
	$near       = $measure();
	wpai119capacity_check( ! is_wp_error( $near['result'] ), 'Precisely 4096 registered routes should remain executable.' );

	$before_over = $calls;
	$register( sprintf( '/wpai119capacity/v1/entry-%04d', $index ) );
	$over_count = count( $server->get_routes() );
	$over       = $measure();
	wpai119capacity_check( 4097 === $over_count, 'Over-ceiling test did not register exactly one additional route.' );
	wpai119capacity_check(
		is_wp_error( $over['result'] ) && 'rest_invocation_registry_unavailable' === $over['result']->get_error_code() &&
		$before_over === $calls,
		'More than 4096 routes executed provider code or did not fail closed.'
	);
	wpai119capacity_check( $small['elapsed_ms'] < 5000 && $heavy['elapsed_ms'] < 5000 && $near['elapsed_ms'] < 5000 && $over['elapsed_ms'] < 5000, 'Representative native route preflight exceeded 5s per bounded sample.' );

	echo 'CAPACITY Issue #119 ' . wp_json_encode(
		array(
			'wordpress' => get_bloginfo( 'version' ),
			'plugins' => count( get_option( 'active_plugins', array() ) ),
			'baseline_registry' => $original_count,
			'small' => array( 'routes' => $small_count, 'ms' => $small['elapsed_ms'], 'memory_delta_bytes' => $small['memory_delta_bytes'], 'memory_peak_bytes' => $small['memory_peak_bytes'] ),
			'provider_heavy_synthetic' => array( 'routes' => $heavy_count, 'ms' => $heavy['elapsed_ms'], 'memory_delta_bytes' => $heavy['memory_delta_bytes'], 'memory_peak_bytes' => $heavy['memory_peak_bytes'] ),
			'near_ceiling' => array( 'routes' => $near_count, 'ms' => $near['elapsed_ms'], 'memory_delta_bytes' => $near['memory_delta_bytes'], 'memory_peak_bytes' => $near['memory_peak_bytes'] ),
			'over_ceiling_fail_closed' => array( 'routes' => $over_count, 'ms' => $over['elapsed_ms'], 'memory_delta_bytes' => $over['memory_delta_bytes'], 'memory_peak_bytes' => $over['memory_peak_bytes'] ),
		)
	) . "\n";
	echo "PASS: Issue #119 measured WordPress registry latency/memory and exact 4096+1 fail-closed capacity boundary.\n";
} finally {
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
}
