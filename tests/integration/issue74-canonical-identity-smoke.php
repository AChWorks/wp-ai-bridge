<?php
/**
 * Real WordPress canonical identity smoke for Issue #74.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Registrar;
use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

function wpai_issue74_integration_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

wpai_issue74_integration_assert( 'wp-ai-bridge' === Registrar::CATEGORY, 'Ability category is not canonical.' );
wpai_issue74_integration_assert( 'wp_ai_bridge_settings' === Settings::OPTION_NAME, 'Settings option is not canonical.' );
wpai_issue74_integration_assert( 'wpai_doc' === Store::DOCUMENT_POST_TYPE, 'Workspace document post type is not canonical.' );
wpai_issue74_integration_assert( 'wpai_task' === Store::TASK_POST_TYPE, 'Workspace task post type is not canonical.' );
wpai_issue74_integration_assert( '_wpai_workspace_state' === Store::META_STATE, 'Workspace state metadata key is not canonical.' );

$oauth = new OAuth_Server( new OAuth_Store() );
wpai_issue74_integration_assert( rest_url( 'wp-ai-bridge/v1/mcp' ) === $oauth->mcp_endpoint_url(), 'Canonical MCP resource URL is incorrect.' );
wpai_issue74_integration_assert( '/wp-ai-bridge/v1/mcp' === OAuth_Server::MCP_REQUEST_ROUTE, 'Canonical MCP request route is incorrect.' );

$routes = rest_get_server()->get_routes();
foreach ( array( '/wp-ai-bridge/v1/oauth/token', '/wp-ai-bridge/v1/oauth/revoke' ) as $route ) {
	wpai_issue74_integration_assert( isset( $routes[ $route ] ), 'Canonical OAuth REST route missing: ' . $route );
}
wpai_issue74_integration_assert( ! isset( $routes['/wp-native-builder/v1/oauth/token'] ), 'Legacy OAuth token route is still registered.' );
wpai_issue74_integration_assert( ! isset( $routes['/wp-native-builder/v1/oauth/revoke'] ), 'Legacy OAuth revoke route is still registered.' );

$settings_page = new Settings_Page( new Environment(), new Settings(), $oauth );
$settings_page->register_menu();
wpai_issue74_integration_assert( isset( $GLOBALS['admin_page_hooks'][ Settings_Page::PAGE_SLUG ] ), 'Canonical WP AI Bridge admin menu was not registered.' );
foreach ( array( 'wp-native-builder', 'wp-native-builder-documents', 'wp-native-builder-tasks', 'wp-native-builder-activity', 'wp-native-builder-settings' ) as $legacy_slug ) {
	$hook = get_plugin_page_hookname( $legacy_slug, '' );
	wpai_issue74_integration_assert( empty( $GLOBALS['_registered_pages'][ $hook ] ), 'Legacy admin alias remains registered: ' . $legacy_slug );
}

wpai_issue74_integration_assert( is_dir( WP_PLUGIN_DIR . '/wp-ai-bridge' ), 'Canonical plugin directory is missing.' );
wpai_issue74_integration_assert( ! is_file( WP_PLUGIN_DIR . '/wp-ai-bridge/wp-native-builder-bridge.php' ), 'Legacy plugin entrypoint exists in canonical plugin directory.' );
wpai_issue74_integration_assert( is_file( WP_PLUGIN_DIR . '/wp-ai-bridge/wp-ai-bridge.php' ), 'Canonical plugin entrypoint is missing.' );

$plugin = get_plugin_data( WP_PLUGIN_DIR . '/wp-ai-bridge/wp-ai-bridge.php', false, false );
wpai_issue74_integration_assert( 'WP AI Bridge' === ( $plugin['Name'] ?? '' ), 'Canonical plugin name is incorrect.' );
wpai_issue74_integration_assert( 'wp-ai-bridge' === ( $plugin['TextDomain'] ?? '' ), 'Canonical plugin text domain is incorrect.' );

echo "PASS: Issue #74 canonical WordPress plugin identity and route surface.\n";
