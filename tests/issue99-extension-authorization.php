<?php
/**
 * Issue #99: readonly extension lifecycle authorization preflight.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Extension_Abilities;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

$assertions = 0;
function wpai99_check( $condition, $message ) {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

wpai_test_reset_state();
$settings   = new Settings();
$provider   = new Extension_Abilities( new Permissions( $settings ), new Mutation_Log() );
$registered = $provider->register();
$ability    = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/extension-authorization'];
wpai99_check( 3 === count( $registered ), 'Expected read, mutation and readonly authorization contracts.' );
wpai99_check( true === $ability['meta']['annotations']['readonly'] && false === $ability['meta']['annotations']['destructive'], 'Authorization inspection must be read-only.' );
wpai99_check( isset( $ability['input_schema']['properties']['install_source'] ) && false === $ability['input_schema']['additionalProperties'], 'Authorization selectors must be closed and typed.' );

$input     = array( 'kind' => 'plugin', 'action' => 'install' );
$initial   = call_user_func( $ability['execute_callback'], $input );
wpai99_check( ! is_wp_error( $initial ), 'Default-off authorization read denied the ordinary Site Read user.' );
wpai99_check( 'bridge_delegation_disabled' === $initial['status'] && false === $initial['permission_callback_allows'], 'Default-off Code & Extensions grant was not observed.' );
wpai99_check( 'install_plugins' === $initial['native_capability'] && false === $initial['native_capability_granted'], 'Native plugin-install capability was fabricated.' );
wpai99_check( 'not_evaluated' === $initial['execution_permission'], 'Preflight claimed execution authority.' );
wpai99_check( array( Settings::GROUP_CODE_EXTENSIONS ) === $initial['required_groups'], 'Wrong default install delegation.' );

$enabled = $settings->defaults();
$enabled[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
update_option( Settings::OPTION_NAME, $enabled, false );
$bridge_on = $provider->inspect_authorization( $input );
wpai99_check( 'native_authority_denied' === $bridge_on['status'], 'Native WordPress capability denial was not distinguished from delegation.' );

$GLOBALS['wpai_test']['capabilities']['install_plugins'] = true;
$allowed = $provider->inspect_authorization( $input );
wpai99_check( 'permission_preflight_passed' === $allowed['status'] && true === $allowed['permission_callback_allows'], 'Exact native + Bridge grant was not reflected.' );
wpai99_check( 'not_evaluated' === $allowed['execution_permission'], 'Passed predicate was misreported as installed/executable.' );

$external = $provider->inspect_authorization( array( 'kind' => 'plugin', 'action' => 'install', 'install_source' => 'public_https' ) );
wpai99_check( 'bridge_delegation_disabled' === $external['status'] && false === $external['group_grants'][ Settings::GROUP_EXTERNAL_PACKAGES ], 'Public package permission bypassed External Packages.' );
$enabled[ Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
update_option( Settings::OPTION_NAME, $enabled, false );
$external = $provider->inspect_authorization( array( 'kind' => 'plugin', 'action' => 'install', 'install_source' => 'public_https' ) );
wpai99_check( 'permission_preflight_passed' === $external['status'] && true === $external['permission_callback_allows'], 'External package permission should match existing pure mutation callback.' );

$GLOBALS['wpai_test']['capabilities']['delete_plugins'] = true;
$delete = $provider->inspect_authorization( array( 'kind' => 'plugin', 'action' => 'delete' ) );
wpai99_check( 'bridge_delegation_disabled' === $delete['status'] && false === $delete['permission_callback_allows'], 'Delete cannot bypass Users & Destructive.' );
$enabled[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $enabled, false );
$delete = $provider->inspect_authorization( array( 'kind' => 'plugin', 'action' => 'delete' ) );
wpai99_check( 'permission_preflight_passed' === $delete['status'] && true === $delete['permission_callback_allows'], 'Delete preflight differs from same-action callback.' );
wpai99_check( in_array( Settings::GROUP_USERS_DESTRUCTIVE, $delete['required_groups'], true ), 'Destructive operation did not list its elevated grant.' );

$GLOBALS['wpai_test']['capabilities']['install_themes'] = true;
$theme = $provider->inspect_authorization( array( 'kind' => 'theme', 'action' => 'install' ) );
wpai99_check( 'install_themes' === $theme['native_capability'] && 'permission_preflight_passed' === $theme['status'], 'Theme install authority mapped incorrectly.' );
foreach ( array(
	array( 'kind' => 'theme', 'action' => 'deactivate' ),
	array( 'kind' => 'plugin', 'action' => 'noop' ),
	array( 'kind' => 'plugin', 'action' => 'install', 'install_source' => 'private_zip' ),
	array( 'kind' => 'plugin', 'action' => 'delete', 'install_source' => 'public_https' ),
	array( 'kind' => 'plugin', 'action' => 'install', 'unknown' => 'x' ),
	array( 'kind' => array(), 'action' => 'install' ),
	array( 'kind' => 'theme', 'action' => 42 ),
	array( 'kind' => 'plugin' ),
) as $bad ) {
	$failure = $provider->inspect_authorization( $bad );
	wpai99_check( is_wp_error( $failure ) && 'invalid_extension_authorization_input' === $failure->get_error_code(), 'Malformed preflight selector was accepted.' );
}

$enabled[ Settings::GROUP_SITE_READ ] = 0;
update_option( Settings::OPTION_NAME, $enabled, false );
wpai99_check( is_wp_error( $provider->inspect_authorization( $input ) ), 'Disabled Site Read disclosed installation authorization.' );
update_option( Settings::OPTION_NAME, $settings->defaults(), false );
$GLOBALS['wpai_test']['capabilities']['read'] = false;
wpai99_check( is_wp_error( $provider->inspect_authorization( $input ) ), 'Native read revocation disclosed authorization.' );
$GLOBALS['wpai_test']['capabilities']['read'] = true;
$GLOBALS['wpai_test']['user_id'] = 0;
$GLOBALS['wpai_test']['capabilities'] = array();
wpai99_check( is_wp_error( $provider->inspect_authorization( $input ) ), 'Anonymous preflight disclosed grants.' );

wpai99_check( false === strpos( wp_json_encode( $initial ), 'credential' ), 'Read-only preflight leaked arbitrary credential material.' );
echo "PASS: Issue #99 extension authorization preflight ({$assertions} assertions).\n";
