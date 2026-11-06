<?php
/**
 * Public WordPress Ability and authenticated Adapter database-input contract.
 *
 * Run in separate WordPress bootstraps at default and reduced response limits.
 * Tests only the disposable site; all temporary grants/tokens are restored.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue134-review-helper.php';

function wpai213_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai213_restore_option( $name, $original ) {
	if ( false === $original ) {
		delete_option( $name );
		return;
	}
	// Restore the protected Bridge settings snapshot exactly.
	$restore = static function () use ( $original ) {
		return $original;
	};
	add_filter( 'pre_update_option_' . $name, $restore, PHP_INT_MAX );
	try {
		update_option( $name, $original, false );
	} finally {
		remove_filter( 'pre_update_option_' . $name, $restore, PHP_INT_MAX );
	}
}

$actor             = get_current_user_id();
$original_settings = get_option( Settings::OPTION_NAME, false );
$original_database = get_option( Settings::DATABASE_OPTION_NAME, false );
$settings          = new Settings();
$max_page          = max( 1, intdiv( Bounded_Payload::response_limit(), 2048 ) );
$max_window        = intdiv( Bounded_Payload::response_limit(), 4 );
$token             = '';
$session           = '';
$store             = null;

try {
	wpai213_assert( current_user_can( 'manage_options' ), 'Public database contract fixture requires a disposable administrator.' );
	$inspect = wp_get_ability( 'wp-ai-bridge/database-inspect' );
	$read    = wp_get_ability( 'wp-ai-bridge/database-read' );
	wpai213_assert( $inspect instanceof WP_Ability && $read instanceof WP_Ability, 'Native WordPress database Abilities not registered.' );

	// Fresh bootstrap must register the live policy, not a cached maximum.
	foreach ( array( $inspect, $read ) as $ability ) {
		$properties = $ability->get_input_schema()['properties'];
		wpai213_assert( $max_page === $properties['limit']['maximum'], 'Public page ceiling did not follow response policy.' );
		wpai213_assert( $max_window === $properties['length']['maximum'], 'Public byte-window ceiling did not follow response policy.' );
	}
	$groups                                     = $settings->defaults();
	$groups[ Settings::GROUP_NATIVE_ABILITIES ] = 0;
	update_option( Settings::OPTION_NAME, $groups, false );
	$grants                                        = $settings->database_defaults();
	$grants[ Settings::GROUP_DATABASE_INSPECTION ] = 1;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );

	$valid_input = array(
		'action' => 'tables',
		'limit'  => $max_page,
	);
	$bad_input   = array(
		'action' => 'tables',
		'limit'  => $max_page + 1,
	);
	$valid       = $inspect->execute( $valid_input );
	wpai213_assert( ! is_wp_error( $valid ) && isset( $valid['items'] ), 'Published maximum failed through native WP_Ability::execute().' );

	$bad = $inspect->execute( $bad_input );
	wpai213_assert(
		is_wp_error( $bad ) && 'ability_invalid_input' === $bad->get_error_code() &&
		str_contains( $bad->get_error_message(), 'limit' ),
		'Core did not reject maximum+1 with actionable ability_invalid_input.'
	);
	// General schema admits this input; the action-specific Bridge validator does not.
	$handler_error = $inspect->execute(
		array(
			'action' => 'schema',
			'limit'  => 1,
		)
	);
	wpai213_assert(
		is_wp_error( $handler_error ) && 'database_input_invalid' === $handler_error->get_error_code(),
		'Action-specific handler error code changed.'
	);

	// Same Core boundary on the sensitive read surface, without reading values.
	global $wpdb;
	$raw_bad = $read->execute(
		array(
			'action'  => 'rows',
			'table'   => $wpdb->options,
			'columns' => array( 'option_id' ),
			'limit'   => $max_page + 1,
		)
	);
	wpai213_assert(
		is_wp_error( $raw_bad ) && 'ability_invalid_input' === $raw_bad->get_error_code(),
		'Raw-read out-of-range page bypassed Core schema validation.'
	);
	$raw_denied = $read->execute(
		array(
			'action'  => 'rows',
			'table'   => $wpdb->options,
			'columns' => array( 'option_id' ),
			'limit'   => 1,
		)
	);
	wpai213_assert( is_wp_error( $raw_denied ) && 'ability_invalid_input' !== $raw_denied->get_error_code(), 'Sensitive database read grant was silently widened.' );

	list( $token, $session, $store ) = wpai134_review_connect( $actor );
	$wpai_call                       = static function ( array $input ) use ( &$token, &$session ) {
		wp_set_current_user( 0 );
		return wpai134_review_call( $token, $session, 'wp-ai-bridge/database-inspect', $input );
	};
	$via_adapter                     = $wpai_call( $valid_input );
	wpai213_assert(
		true === ( $via_adapter['result']['structuredContent']['success'] ?? false ),
		'Maximum request failed through authenticated official MCP Adapter.'
	);
	$adapter_bad = $wpai_call( $bad_input );
	$code        = $adapter_bad['result']['structuredContent']['error']['code'] ?? null;
	wpai213_assert(
		true === ( $adapter_bad['result']['isError'] ?? false ) &&
		'ability_invalid_input' === $code,
		'Authenticated Adapter failed to preserve Core ability_invalid_input.'
	);
	$adapter_handler_error = $wpai_call(
		array(
			'action' => 'schema',
			'limit'  => 1,
		)
	);
	wpai213_assert(
		true === ( $adapter_handler_error['result']['isError'] ?? false ) &&
		'database_input_invalid' === ( $adapter_handler_error['result']['structuredContent']['error']['code'] ?? null ),
		'Authenticated Adapter failed to preserve Bridge database_input_invalid.'
	);

	wp_set_current_user( $actor );
	$grants[ Settings::GROUP_DATABASE_INSPECTION ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	$denied = $inspect->execute(
		array(
			'action' => 'tables',
			'limit'  => 1,
		)
	);
	wpai213_assert( is_wp_error( $denied ) && 'ability_invalid_input' !== $denied->get_error_code(), 'Native permission callback bypassed revocation.' );
	$adapter_denied = $wpai_call(
		array(
			'action' => 'tables',
			'limit'  => 1,
		)
	);
	wpai213_assert( true === ( $adapter_denied['result']['isError'] ?? false ), 'Existing OAuth session bypassed revoked inspection consent.' );

	echo 'PASS: #213 native/Adapter Core and handler error codes, bounds and grants (active ' .
		intdiv( Bounded_Payload::response_limit(), 1024 ) . " KiB).\n";
} finally {
	if ( '' !== $token && '' !== $session ) {
		$request = new WP_REST_Request( 'DELETE', OAuth_Server::MCP_REQUEST_ROUTE );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$request->set_header( 'Mcp-Session-Id', $session );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		rest_do_request( $request );
	}
	if ( '' !== $token && null !== $store ) {
		$store->revoke( $token );
	}
	wp_set_current_user( $actor );
	wpai213_restore_option( Settings::OPTION_NAME, $original_settings );
	wpai213_restore_option( Settings::DATABASE_OPTION_NAME, $original_database );
}
