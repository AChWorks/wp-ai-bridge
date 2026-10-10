<?php
/**
 * Read-only OAuth connection truth and WordPress Core update-cache diagnostics.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Site_Config_Abilities;
use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
function is_multisite() { return ! empty( $GLOBALS['wpai_test']['multisite'] ); }
function get_site_transient( $name ) {
	return 'update_core' === $name ? ( $GLOBALS['wpai_test']['core_transient'] ?? false ) : false;
}
function wp_is_file_mod_allowed( $context ) { return empty( $GLOBALS['wpai_test']['file_mods_blocked'] ); }
function get_core_updates( $options = array() ) {
	++$GLOBALS['wpai_test']['core_calls'];
	return $GLOBALS['wpai_test']['core_offers'] ?? false;
}

$assertions = 0;
function wpai130141_assert( $ok, $why ) {
	global $assertions;
	++$assertions;
	if ( ! $ok ) {
		throw new RuntimeException( $why );
	}
}
function wpai130141_admin_html( $page, $summary = false ) {
	ob_start();
	if ( ! $summary ) {
		$page->render_settings();
	} else {
		$ref = new ReflectionMethod( $page, 'render_connection_summary' );
		$ref->invoke( $page );
	}
	return ob_get_clean();
}

wpai_test_reset_state();
$GLOBALS['wpai_test']['core_calls'] = 0;
$settings   = new Settings();
$permission = new Permissions( $settings );
$provider   = new Site_Config_Abilities( $permission, new Mutation_Log() );
$provider->register();
$ability = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/core-update-status'];
wpai130141_assert( true === $ability['meta']['annotations']['readonly'] && false === $ability['meta']['annotations']['destructive'], 'Core status must register only as read-only.' );
wpai130141_assert( false === $ability['input_schema']['additionalProperties'], 'Core status must not accept action-like input.' );
wpai130141_assert( ! $provider->can_read_core_update_status(), 'Default-off Site Configuration bypass.' );
wpai130141_assert( is_wp_error( $provider->core_update_status() ), 'Direct execution bypassed revoked permission.' );
wpai130141_assert( 0 === $GLOBALS['wpai_test']['core_calls'], 'Denied request read update offers.' );

$groups = $settings->defaults();
$groups[ Settings::GROUP_SITE_CONFIG ] = 1;
update_option( Settings::OPTION_NAME, $groups );
wpai130141_assert( $provider->can_read_core_update_status(), 'Site administrator with explicit grant denied.' );

$now = time();
$GLOBALS['wpai_test']['core_transient'] = (object) array( 'last_checked' => $now - 60, 'version_checked' => '7.1', 'updates' => array() );
$GLOBALS['wpai_test']['core_offers'] = array(
	(object) array( 'response' => 'upgrade', 'current' => '7.2', 'locale' => 'en_US' ),
	(object) array( 'response' => 'latest', 'current' => '7.1', 'locale' => 'en_US' ),
);
$available = $provider->core_update_status();
wpai130141_assert( 'update_available' === $available['status'] && 'fresh' === $available['cache_state'], 'Fresh upgrade offer not detected.' );
wpai130141_assert( 2 === $available['offer_count'] && '7.2' === $available['offers'][0]['version'], 'Offered versions not projected.' );
wpai130141_assert( false === $available['upgrade_execution_via_bridge'] && false === $available['native_update_core_allowed'], 'Executable Core permission fabricated.' );
wpai130141_assert( ! $available['offers_truncated'] && 0 < $available['check_age_seconds'], 'Freshness/count dishonest.' );

$GLOBALS['wpai_test']['file_mods_blocked'] = true;
wpai130141_assert( false === $provider->core_update_status()['file_modifications_allowed'], 'Native blocked file-modification policy ignored.' );
unset( $GLOBALS['wpai_test']['file_mods_blocked'] );
$GLOBALS['wpai_test']['core_offers'] = array();
wpai130141_assert( 'unknown_or_stale' === $provider->core_update_status()['status'], 'Empty offer cache falsely declared up to date.' );
$GLOBALS['wpai_test']['core_offers'] = array( (object) array( 'response' => 'latest', 'current' => '7.1', 'locale' => 'en_US' ) );
wpai130141_assert( 'no_update_offered' === $provider->core_update_status()['status'], 'Fresh current cache misclassified.' );
$GLOBALS['wpai_test']['core_transient'] = false;
$before = $GLOBALS['wpai_test']['core_calls'];
$missing = $provider->core_update_status();
wpai130141_assert( 'unknown_or_stale' === $missing['status'] && 'missing_or_invalid' === $missing['cache_state'], 'Missing cache displayed as current.' );
wpai130141_assert( $before === $GLOBALS['wpai_test']['core_calls'], 'Missing cache triggered update lookup.' );

$GLOBALS['wpai_test']['core_transient'] = (object) array( 'last_checked' => $now - DAY_IN_SECONDS - 1, 'version_checked' => '7.1', 'updates' => array() );
$stale = $provider->core_update_status();
wpai130141_assert( 'unknown_or_stale' === $stale['status'] && 'stale' === $stale['cache_state'], 'Stale offers treated as current.' );
wpai130141_assert( $before === $GLOBALS['wpai_test']['core_calls'], 'Stale cache triggered offer fetch.' );
$GLOBALS['wpai_test']['core_transient'] = (object) array( 'last_checked' => $now + 60, 'version_checked' => '7.1', 'updates' => array() );
wpai130141_assert( 'unknown_or_stale' === $provider->core_update_status()['status'], 'Slightly future-dated cache was trusted.' );
$GLOBALS['wpai_test']['core_transient'] = (object) array( 'last_checked' => $now + 600, 'version_checked' => '7.1', 'updates' => array() );
wpai130141_assert( 'unknown_or_stale' === $provider->core_update_status()['status'], 'Future-dated cache trusted.' );

$GLOBALS['wpai_test']['core_transient'] = (object) array( 'last_checked' => $now - 60, 'version_checked' => '7.1', 'updates' => array() );
$GLOBALS['wpai_test']['core_offers'] = array( (object) array( 'response' => 'upgrade', 'current' => '7.2' ), (object) array( 'broken' => true ) );
wpai130141_assert( 'unknown_or_stale' === $provider->core_update_status()['status'], 'Malformed offer reported valid.' );
$GLOBALS['wpai_test']['core_transient'] = (object) array(
	'last_checked' => $now - 60,
	'version_checked' => '6.8',
	'updates' => array(),
);
wpai130141_assert( 'unknown_or_stale' === $provider->core_update_status()['status'], 'Different checked Core version was trusted.' );
$GLOBALS['wpai_test']['core_transient'] = (object) array(
	'last_checked' => $now - 60,
	'version_checked' => '7.1',
	'updates' => array( (object) array( 'response' => 'upgrade', 'current' => '7.2' ) ),
);
wpai130141_assert( 'unknown_or_stale' === $provider->core_update_status()['status'], 'Malformed cached offer was delegated to unsafe native parser.' );
$GLOBALS['wpai_test']['core_transient'] = (object) array( 'last_checked' => $now - 60, 'version_checked' => '7.1', 'updates' => array() );
$GLOBALS['wpai_test']['core_offers'] = array_fill( 0, 10, (object) array( 'response' => 'upgrade', 'current' => '7.2', 'locale' => 'en_US' ) );
$many = $provider->core_update_status();
wpai130141_assert( 10 === $many['offer_count'] && 8 === count( $many['offers'] ) && $many['offers_truncated'], 'Offer projection silently truncated.' );

$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
wpai130141_assert( ! $provider->can_read_core_update_status() && is_wp_error( $provider->core_update_status() ), 'Revoked admin cap allowed read.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
$GLOBALS['wpai_test']['multisite'] = true;
wpai130141_assert( ! $provider->can_read_core_update_status(), 'Multisite site admin saw network cache.' );
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
wpai130141_assert( $provider->can_read_core_update_status(), 'Multisite network admin denied.' );
$GLOBALS['wpai_test']['multisite'] = false;

$page = new Settings_Page( new Environment(), $settings, new OAuth_Server(), new Store(), new Mutation_Log() );
$GLOBALS['wpai_test']['capabilities']['view_site_health_checks'] = true;
$GLOBALS['wpai_test']['rest_http'] = true;
$html = wpai130141_admin_html( $page );
wpai130141_assert( false === strpos( $html, 'Public HTTPS' ) && false === strpos( $html, 'Connected' ), 'False-ready claims in Settings.' );
wpai130141_assert( false !== strpos( $html, 'HTTPS not configured' ) && false !== strpos( $html, 'Not verified here' ), 'HTTP endpoint not reported honestly.' );
wpai130141_assert( false !== strpos( $html, 'site-health.php' ), 'Native Site Health guidance missing.' );

$GLOBALS['wpai_test']['rest_http'] = false;
$html = wpai130141_admin_html( $page, true );
wpai130141_assert( false !== strpos( $html, 'HTTPS configured; public reachability not verified' ), 'HTTPS scheme became public availability.' );
wpai130141_assert( false === strpos( $html, 'Public HTTPS' ) && false !== strpos( $html, 'Not verified here' ), 'Dashboard false-green remains.' );
$GLOBALS['wpai_test']['capabilities']['view_site_health_checks'] = false;
$html = wpai130141_admin_html( $page );
wpai130141_assert( false === strpos( $html, 'site-health.php' ) && false !== strpos( $html, 'Site Health diagnostics cannot be verified' ), 'Unavailable Site Health misrepresented.' );

$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
try {
	wpai130141_admin_html( $page );
	throw new RuntimeException( 'Unprivileged user rendered configuration.' );
} catch ( RuntimeException $error ) {
	wpai130141_assert( false !== strpos( $error->getMessage(), 'not allowed' ), 'Wrong settings permission failure.' );
}
echo "PASS: #130/#141 bounded read-only diagnostics ({$assertions} assertions).\n";
