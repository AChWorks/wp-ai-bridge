<?php
/**
 * Settings UI integration: actual render/submission boundaries, not a source-string snapshot.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Admin\Administration_Page;
use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Admin\Settings_Save;
use WP_AI_Bridge\Auth\Approved_OAuth_Clients;
use WP_AI_Bridge\Support\Administration_Configuration;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Native_Option_Access;
use WP_AI_Bridge\Support\Payload_Settings;
use WP_AI_Bridge\Support\Settings;

function is_multisite() { return ! empty( $GLOBALS['wpai_test']['multisite'] ); }
function is_super_admin( $user = false ) { return ! empty( $GLOBALS['wpai_test']['super_admin'] ); }
function get_current_blog_id() { return $GLOBALS['wpai_test']['blog'] ?? 1; }
function get_current_network_id() { return $GLOBALS['wpai_test']['network'] ?? 1; }
function get_network_option( $network, $name, $default = false ) { return $GLOBALS['wpai_test']['network_options'][ $network ][ $name ] ?? $default; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function remove_filter( $hook, $callback, $priority = 10 ) { return true; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function wp_salt( $scheme = 'auth' ) { return 'synthetic-settings-ui-test'; }
function nocache_headers() {}
function wp_verify_nonce( $nonce, $action = -1 ) { return hash_equals( hash( 'sha256', 'nonce-' . $action ), $nonce ); }
function esc_attr__( $value, $domain = '' ) { return esc_attr( $value ); }
function esc_textarea( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_max_upload_size() { return 200 * 1024 * 1024; }
function remove_submenu_page( $parent, $slug ) {
	foreach ( $GLOBALS['submenu'][ $parent ] ?? array() as $index => $entry ) {
		if ( $entry[2] === $slug ) {
			unset( $GLOBALS['submenu'][ $parent ][ $index ] );
			return $entry;
		}
	}
	return false;
}

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$render = static function () {
	$page = new Settings_Page( new Environment(), new Settings() );
	ob_start();
	$page->render_settings();
	$html = ob_get_clean();
	$dom  = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	return array( $html, new DOMXPath( $dom ) );
};
wpai_test_reset_state();
$GLOBALS['wp_filter'] = array();
$GLOBALS['wp_current_filter'] = array();
$GLOBALS['wpai_test']['blog'] = 1;
$GLOBALS['wpai_test']['network'] = 1;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array( 'page' => Settings_Page::SETTINGS_SLUG );
$_POST = array();
$page = new Settings_Page( new Environment(), new Settings() );
$page->register_menu();
( new Administration_Page( new Settings() ) )->register_menu();
( new Approved_OAuth_Clients() )->register_menu();
$before_order = array_values( $GLOBALS['submenu'][ Settings_Page::PAGE_SLUG ] );
$registered_routes = $GLOBALS['wpai_test']['submenu_pages'];
$page->order_settings_submenu();
$entries = array_values( $GLOBALS['submenu'][ Settings_Page::PAGE_SLUG ] );
$assert( Settings_Page::SETTINGS_SLUG === $entries[ count( $entries ) - 1 ][2], 'Settings must be the last visible plugin submenu.' );
$assert( array_values( array_filter( $before_order, static function ( $entry ) { return Settings_Page::SETTINGS_SLUG !== $entry[2]; } ) ) === array_slice( $entries, 0, -1 ), 'Final ordering must preserve all other submenu rows and their relative order.' );
$assert( $registered_routes === $GLOBALS['wpai_test']['submenu_pages'], 'Final ordering must preserve native registered routes and callbacks.' );
$assert( ! in_array( Administration_Page::SLUG, array_column( $entries, 2 ), true ), 'Legacy Administration must not remain visible.' );

list( $html, $xp ) = $render();
$assert( 8 === $xp->query( '//section[contains(@class,"wpai-settings-section")]' )->length, 'All eight sections must exist without JavaScript.' );
$assert( 0 === $xp->query( '//section[@hidden]' )->length, 'No-JS must expose every settings section.' );
$assert( 1 === $xp->query( '//button[@id="wpai-settings-save"]' )->length, 'There must be exactly one Save changes action.' );
$assert( 1 === $xp->query( '(//input[@type="submit"] | //button[not(@type) or @type="submit"])[1][@id="wpai-settings-default-submit" and @hidden and not(@name)]' )->length, 'The first native submitter must trigger the main Save action without Add scope draft intent.' );
$assert( 3 === $xp->query( '//form' )->length, 'Settings, Export and Clear must have distinct forms.' );
$assert( 0 === $xp->query( '//form//form' )->length, 'Operation forms must not nest in settings.' );
$assert( 0 === $xp->query( '//form[@id="wpai-settings-form"]//input[@value="wpai_workspace_clear" or @value="wpai_workspace_export"]' )->length, 'Save must never execute a Workspace operation.' );
$assert( 1 === $xp->query( '//form[@id="wpai-settings-form"]/input[last()][@name="form_complete"]' )->length, 'Completeness sentinel must follow every settings input.' );
$assert( 1 === $xp->query( '//form[@id="wpai-settings-form"]//input[@name="wp_ai_bridge_settings[site_read]" and @value="0"]' )->length, 'Unchecked grant must have an explicit native-store zero.' );
$assert( 1 === $xp->query( '//form[@id="wpai-settings-form"]//input[@name="wp_ai_bridge_settings[source_editing]" and @type="checkbox"]' )->length, 'A grant on a different section must share the same settings form.' );
$assert( 0 === $xp->query( '//input[@name="wp_ai_bridge_network_settings_read"]' )->length, 'Unavailable network consent must not submit on single site.' );
$assert( 2 === $xp->query( '//input[starts-with(@id,"wpai-grant-sensitive_network") and @disabled]' )->length, 'Saved unavailable network states must remain visible.' );
$assert( 1 === $xp->query( '//input[@name="wp_ai_bridge_administration_configuration[roots]" and @value=""]' )->length, 'Explicit empty collection must allow revoking every directory scope.' );
$assert( 1 === $xp->query( '//button[@name="wpai_add_scope" and @formnovalidate]' )->length, 'No-JS must have a draft-only scope add path.' );

$coordinator = new Settings_Save( new Settings() );
$snapshot = $coordinator->snapshot();
$post = array(
	'action' => Settings_Save::ACTION,
	'_wpnonce' => hash( 'sha256', 'nonce-' . $coordinator->nonce_action() ),
	'expected_revision' => $snapshot['revision'],
	'blog_id' => '1', 'network_id' => '1', 'user_id' => '1',
	'form_complete' => '1', 'selected_section' => 'files-backups',
	'wpai_add_scope' => '1',
	Payload_Settings::OPTION_NAME => array( Payload_Settings::RESPONSE_KEY => '256' ),
	Administration_Configuration::OPTION_NAME => array( 'roots' => array( array( 'id' => '', 'path' => '' ) ) ),
);
$before = $GLOBALS['wpai_test']['options'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = $post;
list( $html, $xp ) = $render();
$assert( $before === $GLOBALS['wpai_test']['options'], 'Adding a scope row must not persist any settings.' );
$assert( 2 === $xp->query( '//div[@id="wpai-settings-roots"]/fieldset' )->length, 'No-JS Add must append a request-local scope row.' );
$assert( 1 === $xp->query( '//input[@name="wp_ai_bridge_payload_limits[response_kib]" and @value="256"]' )->length, 'Draft add roundtrip must retain another section’s pending value.' );
$assert( 1 === $xp->query( '//input[@name="expected_revision" and @value="' . $snapshot['revision'] . '"]' )->length, 'Draft add must preserve the expected revision.' );
$assert( str_contains( $html, 'No settings have been saved.' ), 'Draft roundtrip must not claim save success.' );

$_POST = $post;
unset( $_POST['wpai_add_scope'] );
$_POST['expected_revision'] = str_repeat( 'f', 64 );
$_POST[ Payload_Settings::OPTION_NAME ][ Payload_Settings::RESPONSE_KEY ] = '333';
list( $html, $xp ) = $render();
$assert( $before === $GLOBALS['wpai_test']['options'], 'Stale settings submit must not write.' );
$assert( 1 === $xp->query( '//input[@name="expected_revision" and @value="' . str_repeat( 'f', 64 ) . '"]' )->length, 'Stale draft must not silently bind to a fresh revision.' );
$assert( 1 === $xp->query( '//input[@name="wp_ai_bridge_payload_limits[response_kib]" and @value="333"]' )->length, 'Stale error must preserve pending values.' );
$assert( 1 === $xp->query( '//button[@id="wpai-settings-save" and @disabled]' )->length, 'Stale draft must require conscious reconciliation.' );
$assert( ! str_contains( $html, '>Changes saved.<' ), 'Stale result must not claim saved.' );

$_POST = $post;
unset( $_POST['wpai_add_scope'] );
$_POST[ Payload_Settings::OPTION_NAME ][ Payload_Settings::RESPONSE_KEY ] = '0';
list( $html, $xp ) = $render();
$assert( $before === $GLOBALS['wpai_test']['options'], 'Invalid hidden-section input must not write.' );
$assert( 1 === $xp->query( '//input[@name="wp_ai_bridge_payload_limits[response_kib]" and @value="0"]' )->length, 'Invalid response budget must remain recoverable in its native field.' );
$assert( 1 === $xp->query( '//div[@id="wpai-settings-errors"]//a[@href="#capacity"]' )->length, 'Server validation must link to the hidden section containing invalid settings.' );
$assert( 0 === $xp->query( '//button[@id="wpai-settings-save" and @disabled]' )->length, 'Ordinary validation errors must allow correction and retry.' );

$_POST = $post;
unset( $_POST['form_complete'] );
list( $html, $xp ) = $render();
$assert( $before === $GLOBALS['wpai_test']['options'], 'An incomplete Add draft must never persist state.' );
$assert( 1 === $xp->query( '//button[@id="wpai-settings-save" and @disabled]' )->length, 'Truncated no-JS draft must not be rebound as complete.' );

$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = array();
$GLOBALS['wpai_test']['multisite'] = true;
$GLOBALS['wpai_test']['super_admin'] = false;
$GLOBALS['wpai_test']['network_options'][1][ Native_Option_Access::NETWORK_OPTION_NAME ] = 1;
$GLOBALS['wpai_test']['options'][ Settings::DATABASE_OPTION_NAME ] = array( Settings::GROUP_DATABASE_READ => 1 );
list( $html, $xp ) = $render();
$assert( 0 === $xp->query( '//input[starts-with(@name,"wp_ai_bridge_database_access")]' )->length, 'Unauthorized database controls must be omitted.' );
$assert( 1 === $xp->query( '//input[@id="wpai-grant-sensitive_database_read" and @disabled and @checked]' )->length, 'Unauthorized saved database grant must remain inspectable.' );
$assert( 0 === $xp->query( '//input[@name="wp_ai_bridge_network_settings_read"]' )->length, 'Ordinary multisite admin must not submit network grant.' );
$assert( 1 === $xp->query( '//input[@id="wpai-grant-sensitive_network_settings_read" and @disabled and @checked]' )->length, 'Read-only network consent must show its actual saved value.' );

echo "Settings UI render checks passed: {$checks}\n";
