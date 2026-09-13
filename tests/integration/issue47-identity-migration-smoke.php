<?php
/**
 * Real WordPress identity migration smoke for Issue #47.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Admin\Settings_Page;
use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Auth\OAuth_Store;
use WP_Native_Builder_Bridge\Support\Environment;
use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue47_integration_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$user_id = get_current_user_id();
wpnb_issue47_integration_assert( $user_id > 0, 'Run Issue #47 identity smoke as an authenticated WordPress user.' );

$store = new OAuth_Store();
$oauth = new OAuth_Server( $store );

$canonical_resource = $oauth->mcp_endpoint_url();
$legacy_resource    = $oauth->legacy_mcp_endpoint_url();
wpnb_issue47_integration_assert( rest_url( 'wp-ai-bridge/v1/mcp' ) === $canonical_resource, 'Canonical MCP resource URL is incorrect.' );
wpnb_issue47_integration_assert( rest_url( 'wp-native-builder/v1/mcp' ) === $legacy_resource, 'Legacy MCP resource URL is not preserved.' );
wpnb_issue47_integration_assert( $canonical_resource !== $legacy_resource, 'Canonical and legacy MCP resources collapsed to one audience.' );

$protected = $oauth->protected_resource_metadata();
$legacy_protected = $oauth->legacy_protected_resource_metadata();
wpnb_issue47_integration_assert( 'WP AI Bridge' === ( $protected['resource_name'] ?? '' ), 'Canonical protected-resource metadata did not migrate product identity.' );
wpnb_issue47_integration_assert( $canonical_resource === ( $protected['resource'] ?? '' ), 'Canonical protected-resource metadata advertises the wrong resource.' );
wpnb_issue47_integration_assert( $legacy_resource === ( $legacy_protected['resource'] ?? '' ), 'Legacy protected-resource metadata advertises the wrong resource.' );

$authorization = $oauth->authorization_server_metadata();
wpnb_issue47_integration_assert( $oauth->authorization_endpoint_url() === ( $authorization['authorization_endpoint'] ?? '' ), 'Authorization metadata did not advertise canonical authorization endpoint.' );
wpnb_issue47_integration_assert( $oauth->token_endpoint_url() === ( $authorization['token_endpoint'] ?? '' ), 'Authorization metadata did not advertise canonical token endpoint.' );
wpnb_issue47_integration_assert( $oauth->revocation_endpoint_url() === ( $authorization['revocation_endpoint'] ?? '' ), 'Authorization metadata did not advertise canonical revocation endpoint.' );

$routes = rest_get_server()->get_routes();
foreach (
	array(
		OAuth_Server::MCP_REQUEST_ROUTE,
		OAuth_Server::LEGACY_MCP_REQUEST_ROUTE,
		'/wp-ai-bridge/v1/oauth/token',
		'/wp-native-builder/v1/oauth/token',
		'/wp-ai-bridge/v1/oauth/revoke',
		'/wp-native-builder/v1/oauth/revoke',
	) as $route
) {
	wpnb_issue47_integration_assert( isset( $routes[ $route ] ), 'Expected canonical/legacy REST route missing: ' . $route );
}

$claims = array(
	'user_id'   => $user_id,
	'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
	'scope'     => OAuth_Server::SCOPE_MCP,
);

$canonical_token = $store->issue(
	OAuth_Store::TYPE_ACCESS,
	array_merge( $claims, array( 'resource' => $canonical_resource ) ),
	OAuth_Server::ACCESS_TTL
);
$legacy_token = $store->issue(
	OAuth_Store::TYPE_ACCESS,
	array_merge( $claims, array( 'resource' => $legacy_resource ) ),
	OAuth_Server::ACCESS_TTL
);

$canonical_request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$canonical_request->set_header( 'Authorization', 'Bearer ' . $canonical_token );
wpnb_issue47_integration_assert( true === $oauth->authenticate_mcp_request( $canonical_request ), 'Canonical token did not authenticate canonical MCP route.' );

$canonical_on_legacy = new WP_REST_Request( 'POST', OAuth_Server::LEGACY_MCP_REQUEST_ROUTE );
$canonical_on_legacy->set_header( 'Authorization', 'Bearer ' . $canonical_token );
wpnb_issue47_integration_assert( false === $oauth->authenticate_mcp_request( $canonical_on_legacy ), 'Canonical token was accepted on legacy MCP audience.' );

$legacy_request = new WP_REST_Request( 'POST', OAuth_Server::LEGACY_MCP_REQUEST_ROUTE );
$legacy_request->set_header( 'Authorization', 'Bearer ' . $legacy_token );
wpnb_issue47_integration_assert( true === $oauth->authenticate_mcp_request( $legacy_request ), 'Legacy token did not authenticate retained legacy MCP route.' );

$legacy_on_canonical = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$legacy_on_canonical->set_header( 'Authorization', 'Bearer ' . $legacy_token );
wpnb_issue47_integration_assert( false === $oauth->authenticate_mcp_request( $legacy_on_canonical ), 'Legacy token was accepted on canonical MCP audience.' );

// The unauthenticated challenge must keep discovery tied to the same exact resource,
// otherwise a reconnect against the legacy endpoint could mint a canonical token that
// cannot be used on the legacy endpoint.
$missing_canonical = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
wpnb_issue47_integration_assert( false === $oauth->authenticate_mcp_request( $missing_canonical ), 'Missing canonical bearer unexpectedly authenticated.' );
$canonical_challenge = $oauth->add_mcp_authentication_challenge( new WP_REST_Response( array(), 403 ), rest_get_server(), $missing_canonical );
$canonical_header    = (string) ( $canonical_challenge->get_headers()['WWW-Authenticate'] ?? '' );
wpnb_issue47_integration_assert( false !== strpos( $canonical_header, $oauth->protected_resource_metadata_url() ), 'Canonical challenge does not advertise canonical protected-resource metadata.' );

$missing_legacy = new WP_REST_Request( 'POST', OAuth_Server::LEGACY_MCP_REQUEST_ROUTE );
wpnb_issue47_integration_assert( false === $oauth->authenticate_mcp_request( $missing_legacy ), 'Missing legacy bearer unexpectedly authenticated.' );
$legacy_challenge = $oauth->add_mcp_authentication_challenge( new WP_REST_Response( array(), 403 ), rest_get_server(), $missing_legacy );
$legacy_header    = (string) ( $legacy_challenge->get_headers()['WWW-Authenticate'] ?? '' );
wpnb_issue47_integration_assert( false !== strpos( $legacy_header, $oauth->legacy_protected_resource_metadata_url() ), 'Legacy challenge does not advertise legacy protected-resource metadata.' );
wpnb_issue47_integration_assert( false === strpos( $legacy_header, 'resource_metadata="' . $oauth->protected_resource_metadata_url() . '"' ), 'Legacy challenge incorrectly advertises canonical protected-resource metadata.' );

// Register the admin area explicitly in this WP-CLI request. Public navigation must
// use canonical slugs, while the old bookmarks remain registered as hidden pages.
$settings_page = new Settings_Page( new Environment(), new Settings(), $oauth );
$settings_page->register_menu();
wpnb_issue47_integration_assert( isset( $GLOBALS['admin_page_hooks'][ Settings_Page::PAGE_SLUG ] ), 'Canonical WP AI Bridge admin menu was not registered.' );
foreach (
	array(
		Settings_Page::LEGACY_PAGE_SLUG,
		Settings_Page::LEGACY_DOCUMENTS_SLUG,
		Settings_Page::LEGACY_TASKS_SLUG,
		Settings_Page::LEGACY_ACTIVITY_SLUG,
		Settings_Page::LEGACY_SETTINGS_SLUG,
	) as $legacy_slug
) {
	$hook = get_plugin_page_hookname( $legacy_slug, '' );
	wpnb_issue47_integration_assert( ! empty( $GLOBALS['_registered_pages'][ $hook ] ), 'Legacy admin bookmark alias is not registered: ' . $legacy_slug );
}

// Stored identities deliberately remain the pre-rename values.
wpnb_issue47_integration_assert( 'wp_native_builder_bridge_settings' === Settings::OPTION_NAME, 'Settings storage identity changed during public rename.' );
wpnb_issue47_integration_assert( false === is_dir( WP_PLUGIN_DIR . '/wp-ai-bridge' ), 'Candidate installed a second wp-ai-bridge plugin directory.' );
wpnb_issue47_integration_assert( is_dir( WP_PLUGIN_DIR . '/wp-native-builder-bridge' ), 'Stable plugin installation directory disappeared.' );

echo "PASS: Issue #47 canonical identity, legacy OAuth/MCP audiences, admin aliases, and stable storage/install identity.\n";
