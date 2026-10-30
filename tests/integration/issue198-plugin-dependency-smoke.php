<?php
/**
 * Real Core dependency parsing, activation and Plugins table behavior.
 * Run each phase in a new WordPress request; do not reset Core's private caches.
 *
 * @package WP_AI_Bridge
 */

require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-plugins-list-table.php';

$phase  = getenv( 'WPAI198_DEPENDENCY_PHASE' );
$bridge = 'wp-ai-bridge/wp-ai-bridge.php';
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( 'Issue #198: ' . $message );
	}
};
$assert( in_array( $phase, array( 'missing', 'inactive', 'active', 'bypassed' ), true ), 'Explicit native dependency phase required.' );
$assert( ! is_multisite() && current_user_can( 'activate_plugins' ) && current_user_can( 'install_plugins' ), 'Disposable single-site native administrator required.' );
$assert( class_exists( 'WP_Plugin_Dependencies' ), 'Native Core dependency manager unavailable.' );
$header = get_plugin_data( WP_PLUGIN_DIR . '/' . $bridge, false, false );
$assert( 'mcp-adapter' === ( $header['RequiresPlugins'] ?? '' ), 'Installed release ZIP did not retain the exact dependency header.' );

// Isolate UI rendering from availability of the public Plugin Information API.
// This supplies metadata only, never installs or substitutes an Adapter binary.
$cache_name    = 'wp_plugin_dependencies_plugin_data';
$timeout_name  = 'wp_plugin_dependencies_plugin_timeout_mcp-adapter';
$before_cache  = get_site_transient( $cache_name );
$before_expiry = get_site_transient( $timeout_name );
$metadata      = is_array( $before_cache ) ? $before_cache : array();
$metadata['mcp-adapter'] = array(
	'Name'         => 'MCP Adapter',
	'name'         => 'MCP Adapter',
	'slug'         => 'mcp-adapter',
	'version'      => '0.0.0',
	'last_updated' => '2026-01-01',
);
set_site_transient( $cache_name, $metadata );
set_site_transient( $timeout_name, true, HOUR_IN_SECONDS );
$GLOBALS['pagenow'] = 'plugins.php';
set_current_screen( 'plugins' );
$GLOBALS['status'] = 'all';
$GLOBALS['page']   = 1;
$GLOBALS['s']      = '';
$GLOBALS['totals'] = array( 'all' => count( get_plugins() ) );
$row = static function ( $file ) {
	$table = new WP_Plugins_List_Table( array( 'screen' => get_current_screen() ) );
	$data  = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
	ob_start();
	try {
		$table->single_row( array( $file, $data ) );
		return ob_get_contents();
	} finally {
		ob_end_clean();
	}
};
$has_action = static function ( $html, $action, $file ) {
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	try {
		$document->loadHTML( '<table>' . $html . '</table>' );
		$xpath = new DOMXPath( $document );
		foreach ( $xpath->query( '//a[@href]' ) as $link ) {
			$query = wp_parse_url( html_entity_decode( $link->getAttribute( 'href' ), ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY );
			parse_str( is_string( $query ) ? $query : '', $arguments );
			$selected = ( $arguments['plugin'] ?? null ) === $file || in_array( $file, (array) ( $arguments['checked'] ?? array() ), true );
			if ( ( $arguments['action'] ?? null ) === $action && $selected ) {
				return true;
			}
		}
		return false;
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}
};

try {
	WP_Plugin_Dependencies::initialize();
	$assert( WP_Plugin_Dependencies::has_dependencies( $bridge ), 'Core did not discover installed Bridge requirements.' );
	$assert( array( 'mcp-adapter' ) === WP_Plugin_Dependencies::get_dependencies( $bridge ), 'Core dependency slug changed.' );
	$adapter = WP_Plugin_Dependencies::get_dependency_filepath( 'mcp-adapter' );
	$unmet   = 'active' !== $phase;
	$assert( $unmet === WP_Plugin_Dependencies::has_unmet_dependencies( $bridge ), 'Core unmet-dependency state disagrees with the fixture.' );
	$requirements = validate_plugin_requirements( $bridge );
	if ( $unmet ) {
		$assert( is_wp_error( $requirements ) && 'plugin_missing_dependencies' === $requirements->get_error_code(), 'Core did not return its native dependency error.' );
		$reason = 'missing' === $phase ? 'not_installed' : 'inactive';
		$assert( isset( $requirements->get_error_data()[ $reason ]['mcp-adapter'] ), 'Core native error lost the specific missing/inactive dependency.' );
	} else {
		$assert( true === $requirements, 'Core rejected an active compatible Adapter.' );
	}
	$bridge_row = $row( $bridge );
	$assert( false !== strpos( $bridge_row, 'class="requires"' ) && false !== strpos( $bridge_row, 'MCP Adapter' ), 'Actual native Plugins row lacks its dependency name.' );
	$assert( false !== strpos( $bridge_row, 'tab=plugin-information' ) && false !== strpos( $bridge_row, 'plugin=mcp-adapter' ), 'Native dependency details/install route is missing.' );

	if ( in_array( $phase, array( 'missing', 'inactive' ), true ) ) {
		$assert( ! is_plugin_active( $bridge ), 'Fresh dependency test started with Bridge active.' );
		$assert( ( 'missing' === $phase ) === ( false === $adapter ), 'Actual Adapter installation state is incorrect.' );
		$activation = activate_plugin( $bridge );
		$assert( is_wp_error( $activation ) && 'plugin_missing_dependencies' === $activation->get_error_code(), 'Actual Core activation failed to enforce the declared dependency.' );
		$assert( ! is_plugin_active( $bridge ), 'Rejected native activation still enabled Bridge.' );
		$assert( ! $has_action( $bridge_row, 'activate', $bridge ), 'Actual Core Plugins UI still offers activation with unmet dependencies.' );
		$assert( false !== strpos( $bridge_row, 'This plugin cannot be activated because required plugins are missing or inactive.' ), 'Native missing-dependency guidance is absent.' );
	} else {
		$assert( is_plugin_active( $bridge ) && false !== $adapter, 'Existing active Bridge and installed Adapter required.' );
		$assert( WP_Plugin_Dependencies::has_active_dependents( $adapter ), 'Core did not protect the Adapter of an active Bridge.' );
		$adapter_row = $row( $adapter );
		$assert( false !== strpos( $adapter_row, 'class="required-by"' ) && false !== strpos( $adapter_row, 'WP AI Bridge' ), 'Native Adapter row does not identify its active dependent.' );
		if ( 'active' === $phase ) {
			$assert( is_plugin_active( $adapter ), 'Required Adapter is not active.' );
			$assert( ! current_user_can( 'deactivate_plugin', $adapter ), 'Native deactivation capability permits breaking an active dependent.' );
			$assert( ! $has_action( $adapter_row, 'deactivate', $adapter ), 'Native Adapter UI offers deactivation while Bridge is active.' );
			$assert( ( new WP_AI_Bridge\Support\Environment() )->mcp_adapter_available(), 'Real activated Adapter did not provide its native implementation.' );
		} else {
			$assert( ! is_plugin_active( $adapter ), 'Abnormal-state fixture requires inactive Adapter without automatic Bridge deactivation.' );
			$assert( ! $has_action( $adapter_row, 'delete-selected', $adapter ), 'Core UI offers deletion of a required inactive Adapter.' );
			$assert( false !== strpos( $bridge_row, 'This plugin is active but may not function correctly' ), 'Core does not explain an already-active abnormal dependency state.' );
			$plugin = WP_AI_Bridge\Plugin::instance();
			ob_start();
			$plugin->render_dependency_notices();
			$native_screen_notice = ob_get_clean();
			$assert( '' === $native_screen_notice, 'Bridge duplicates Core dependency guidance on Plugins.' );
			set_current_screen( 'dashboard' );
			ob_start();
			$plugin->render_dependency_notices();
			$fallback_notice = ob_get_clean();
			$assert( false !== strpos( $fallback_notice, 'official WordPress MCP Adapter is not available' ), 'Abnormal-state administrator fallback vanished outside Plugins.' );
		}
	}
} finally {
	if ( false === $before_cache ) {
		delete_site_transient( $cache_name );
	} else {
		set_site_transient( $cache_name, $before_cache );
	}
	if ( false === $before_expiry ) {
		delete_site_transient( $timeout_name );
	} else {
		set_site_transient( $timeout_name, $before_expiry, HOUR_IN_SECONDS );
	}
}

echo "PASS: Issue #198 actual Core dependency phase {$phase} ({$checks} checks).\n";
