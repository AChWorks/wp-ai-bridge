<?php
/**
 * Native WordPress/official MCP Adapter permission-only extension diagnostic.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Bounded_Payload;

function wpai99_live_assert( $truth, $message ) {
	if ( ! $truth ) {
		throw new RuntimeException( $message );
	}
}

$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$actor    = get_current_user_id();
try {
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	$preflight = wp_get_ability( 'wp-ai-bridge/extension-authorization' );
	$lifecycle = wp_get_ability( 'wp-ai-bridge/extension-lifecycle' );
	$adapter   = wp_get_ability( 'mcp-adapter/execute-ability' );
	wpai99_live_assert( $preflight instanceof WP_Ability && $lifecycle instanceof WP_Ability && $adapter instanceof WP_Ability, 'Missing native preflight/lifecycle/Adapter contract.' );
	$contract = wp_get_ability( 'mcp-adapter/get-ability-info' )->execute( array( 'ability_name' => 'wp-ai-bridge/extension-authorization' ) );
	wpai99_live_assert( ! is_wp_error( $contract ) && 'wp-ai-bridge/extension-authorization' === $contract['name'], 'Official Adapter cannot inspect preflight.' );
	$input  = array( 'kind' => 'plugin', 'action' => 'install' );
	$result = $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/extension-authorization', 'parameters' => $input ) );
	wpai99_live_assert( ! is_wp_error( $result ) && true === ( $result['success'] ?? false ) && Bounded_Payload::fits( $result ), 'Official Adapter rejected bounded readonly preflight.' );
	wpai99_live_assert( 'bridge_delegation_disabled' === $result['data']['status'], 'Default-off executable delegation was not observed through Adapter.' );
	wpai99_live_assert( true !== $lifecycle->check_permissions( $input ), 'Default-off lifecycle permission unexpectedly passed.' );

	$enabled = $settings->defaults();
	$enabled[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	$result = $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/extension-authorization', 'parameters' => $input ) );
	wpai99_live_assert( ! is_wp_error( $result ) && true === ( $result['success'] ?? false ), 'Enabled preflight failed through Adapter.' );
	wpai99_live_assert( current_user_can( 'install_plugins' ) === $result['data']['native_capability_granted'], 'Native install authority must reflect the current WordPress principal.' );
	wpai99_live_assert( $lifecycle->check_permissions( $input ) === $result['data']['permission_callback_allows'], 'Read-only preflight must match lifecycle permission predicate.' );
	wpai99_live_assert( 'not_evaluated' === $result['data']['execution_permission'], 'Permission preflight incorrectly claimed actual execution.' );

	$external = $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/extension-authorization', 'parameters' => array( 'kind' => 'theme', 'action' => 'install', 'install_source' => 'public_https' ) ) );
	wpai99_live_assert( ! is_wp_error( $external ) && true === ( $external['success'] ?? false ) && 'bridge_delegation_disabled' === $external['data']['status'], 'External Packages separation was lost.' );

	$enabled[ Settings::GROUP_SITE_READ ] = 0;
	update_option( Settings::OPTION_NAME, $enabled, false );
	$denied = $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/extension-authorization', 'parameters' => $input ) );
	wpai99_live_assert( is_wp_error( $denied ) || true !== ( $denied['success'] ?? false ), 'Revoked Site Read permitted the diagnostic.' );
	echo "PASS: Issue #99 real WordPress/official Adapter extension authorization diagnostic.\n";
} finally {
	wp_set_current_user( $actor );
	update_option( Settings::OPTION_NAME, $original, false );
}
