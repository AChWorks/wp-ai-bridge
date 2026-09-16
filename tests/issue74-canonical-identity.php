<?php
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Registrar;
use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

$failures = 0;
$tests    = 0;

function wpai_issue74_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

wpai_issue74_assert( 'wp-ai-bridge-direct' === OAuth_Server::MCP_SERVER_ID, 'MCP server ID is canonical.' );
wpai_issue74_assert( 'wp-ai-bridge/v1' === OAuth_Server::MCP_ROUTE_NAMESPACE, 'MCP namespace is canonical.' );
wpai_issue74_assert( '/wp-ai-bridge/v1/mcp' === OAuth_Server::MCP_REQUEST_ROUTE, 'MCP request route is canonical.' );
wpai_issue74_assert( '/wp-ai-bridge/oauth/authorize' === OAuth_Server::AUTHORIZATION_PATH, 'OAuth authorization path is canonical.' );
wpai_issue74_assert( 'wp-ai-bridge' === Settings_Page::PAGE_SLUG, 'Admin dashboard slug is canonical.' );
wpai_issue74_assert( 'wp-ai-bridge-settings' === Settings_Page::SETTINGS_SLUG, 'Admin settings slug is canonical.' );
wpai_issue74_assert( 'wp-ai-bridge' === Registrar::CATEGORY, 'Ability category is canonical.' );
wpai_issue74_assert( 'wp_ai_bridge_settings' === Settings::OPTION_NAME, 'Settings storage is canonical.' );
wpai_issue74_assert( 'wp_ai_bridge_recent_actions' === Mutation_Log::OPTION_NAME, 'Activity storage is canonical.' );
wpai_issue74_assert( 'wp_ai_bridge_oauth_instance' === OAuth_Store::INSTANCE_OPTION, 'OAuth instance storage is canonical.' );
wpai_issue74_assert( 'wpai_doc' === Store::DOCUMENT_POST_TYPE, 'Workspace document type is canonical.' );
wpai_issue74_assert( 'wpai_task' === Store::TASK_POST_TYPE, 'Workspace task type is canonical.' );
wpai_issue74_assert( '_wpai_workspace_state' === Store::META_STATE, 'Workspace metadata key is canonical.' );

$plugin = file_get_contents( dirname( __DIR__ ) . '/wp-ai-bridge.php' );
wpai_issue74_assert( false !== strpos( $plugin, 'Plugin Name: WP AI Bridge' ), 'Plugin header exposes WP AI Bridge.' );
wpai_issue74_assert( false !== strpos( $plugin, 'Text Domain: wp-ai-bridge' ), 'Plugin text domain is canonical.' );
wpai_issue74_assert( false !== strpos( $plugin, "define( 'WP_AI_BRIDGE_VERSION'" ), 'Plugin constants are canonical.' );
wpai_issue74_assert( false === strpos( $plugin, 'WP_NATIVE_BUILDER' ), 'Plugin entrypoint contains no legacy constants.' );

$oauth_source = file_get_contents( dirname( __DIR__ ) . '/src/Auth/class-oauth-server.php' );
wpai_issue74_assert( false === strpos( $oauth_source, 'LEGACY_' ), 'OAuth runtime contains no legacy route aliases.' );
$admin_source = file_get_contents( dirname( __DIR__ ) . '/src/Admin/class-settings-page.php' );
wpai_issue74_assert( false === strpos( $admin_source, 'LEGACY_' ), 'Admin runtime contains no legacy page aliases.' );

if ( $failures > 0 ) {
	fwrite( STDERR, sprintf( "%d of %d Issue #74 canonical identity assertions failed.\n", $failures, $tests ) );
	exit( 1 );
}

echo sprintf( "PASS: %d Issue #74 canonical identity assertions.\n", $tests );
