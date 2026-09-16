<?php
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Registrar;
use WP_Native_Builder_Bridge\Abilities\Source_Editing_Abilities;
use WP_Native_Builder_Bridge\Admin\Settings_Page;
use WP_Native_Builder_Bridge\Auth\Approved_OAuth_Clients;
use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Auth\OAuth_Store;
use WP_Native_Builder_Bridge\Support\Identity_Migration;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Native_Builder_Bridge\Workspace\Store;

$failures = 0;
$tests    = 0;

function wpai_issue72_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

wpai_issue72_assert( 'wp-ai-bridge-direct' === OAuth_Server::MCP_SERVER_ID, 'MCP server ID uses WP AI Bridge identity.' );
wpai_issue72_assert( 'wp-ai-bridge/v1' === OAuth_Server::MCP_ROUTE_NAMESPACE, 'MCP namespace uses WP AI Bridge identity.' );
wpai_issue72_assert( '/wp-ai-bridge/v1/mcp' === OAuth_Server::MCP_REQUEST_ROUTE, 'MCP request route uses WP AI Bridge identity.' );
wpai_issue72_assert( '/wp-ai-bridge/oauth/authorize' === OAuth_Server::AUTHORIZATION_PATH, 'Authorization route uses WP AI Bridge identity.' );
wpai_issue72_assert( 'wp-ai-bridge' === Settings_Page::PAGE_SLUG, 'Admin dashboard slug uses WP AI Bridge identity.' );
wpai_issue72_assert( 'wp-ai-bridge-settings' === Settings_Page::SETTINGS_SLUG, 'Admin settings slug uses WP AI Bridge identity.' );
wpai_issue72_assert( 'wp-ai-bridge' === Registrar::CATEGORY, 'Ability category uses WP AI Bridge identity.' );
wpai_issue72_assert( 'wp_ai_bridge_settings' === Settings::OPTION_NAME, 'Settings option uses WP AI Bridge identity.' );
wpai_issue72_assert( 'wp_ai_bridge_recent_actions' === Mutation_Log::OPTION_NAME, 'Mutation-log option uses WP AI Bridge identity.' );
wpai_issue72_assert( 'wp_ai_bridge_oauth_instance' === OAuth_Store::INSTANCE_OPTION, 'OAuth installation identity uses WP AI Bridge storage.' );
wpai_issue72_assert( 'wp_ai_bridge_oauth_clients' === Approved_OAuth_Clients::OPTION_NAME, 'Approved OAuth clients use WP AI Bridge storage.' );
wpai_issue72_assert( 'wp_ai_bridge_source_recovery' === Source_Editing_Abilities::RECOVERY_OPTION, 'Source recovery uses WP AI Bridge storage.' );
wpai_issue72_assert( 'wpai_doc' === Store::DOCUMENT_POST_TYPE, 'Workspace document post type uses WP AI Bridge identity.' );
wpai_issue72_assert( 'wpai_task' === Store::TASK_POST_TYPE, 'Workspace task post type uses WP AI Bridge identity.' );
wpai_issue72_assert( '_wpai_workspace_state' === Store::META_STATE, 'Workspace state meta uses WP AI Bridge identity.' );
wpai_issue72_assert( 'wp_ai_bridge_identity_migration' === Identity_Migration::STATUS_OPTION, 'Migration completion uses canonical storage.' );

$plugin = file_get_contents( dirname( __DIR__ ) . '/wp-ai-bridge.php' );
wpai_issue72_assert( false !== strpos( $plugin, 'Plugin Name: WP AI Bridge' ), 'Plugin header exposes WP AI Bridge.' );
wpai_issue72_assert( false !== strpos( $plugin, 'Version: 0.4.0' ), 'Migration build declares v0.4.0.' );
wpai_issue72_assert( false !== strpos( $plugin, 'Text Domain: wp-ai-bridge' ), 'Text domain uses WP AI Bridge.' );
wpai_issue72_assert( ! is_file( dirname( __DIR__ ) . '/wp-native-builder-bridge.php' ), 'Retired plugin entrypoint is absent from the source tree.' );

$build = file_get_contents( dirname( __DIR__ ) . '/bin/build-zip.sh' );
wpai_issue72_assert( false !== strpos( $build, 'wp-ai-bridge/wp-ai-bridge.php' ), 'Release build requires the canonical plugin entrypoint.' );
wpai_issue72_assert( false !== strpos( $build, "'^wp-native-builder-bridge/'" ), 'Release build rejects the retired plugin directory.' );

if ( $failures > 0 ) {
	fwrite( STDERR, sprintf( "%d of %d Issue #72 clean-identity assertions failed.\n", $failures, $tests ) );
	exit( 1 );
}

echo sprintf( "PASS: %d Issue #72 clean-identity contract assertions.\n", $tests );
