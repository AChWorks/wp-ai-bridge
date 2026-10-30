<?php
/**
 * Dependency-light native mutation consent and closed schema boundaries.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/integration/issue199-native-options-helper.php';

use WP_AI_Bridge\Abilities\Native_Option_Write_Abilities;
use WP_AI_Bridge\Support\Native_Option_Access;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

function is_multisite() { return ! empty( $GLOBALS['wpai_test']['multisite'] ); }
function is_super_admin( $id = false ) { return ! empty( $GLOBALS['wpai_test']['super_admin'] ); }
function get_current_blog_id() { return $GLOBALS['wpai_test']['blog'] ?? 1; }
function get_current_network_id() { return $GLOBALS['wpai_test']['network'] ?? 1; }
function get_network_option( $network, $name, $default = false ) { return $GLOBALS['wpai_test']['network_options'][ $network ][ $name ] ?? $default; }
function wp_salt( $scheme = 'auth' ) { return 'synthetic-native-write-unit-salt'; }

wpai_test_reset_state();
$settings = new Settings();
$provider = new Native_Option_Write_Abilities( new Permissions( $settings ) );
wpai199n_assert( 1 === count( $provider->register() ), 'Native mutations must register exactly one direct Core Ability.' );
$args = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/native-options-write'];
wpai199n_assert( array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ) === $args['meta']['annotations'], 'Native mutation annotations hide nontransactional/destructive effects.' );
$schema = $args['input_schema'];
wpai199n_assert( false === $schema['additionalProperties'] && 9 === count( $schema['oneOf'] ) && isset( $schema['properties']['action'], $schema['properties']['scope'], $schema['properties']['value_source'] ), 'Root property union/closed action-specific native schema was lost.' );
foreach ( $schema['oneOf'] as $variant ) {
	wpai199n_assert( false === $variant['additionalProperties'], 'Native action variant accepts caller extensions.' );
	foreach ( array( 'blog_id', 'network_id', 'path', 'table', 'callback', 'client_id', 'autoload', 'sql', 'batch', 'patch' ) as $field ) {
		wpai199n_assert( ! isset( $variant['properties'][ $field ] ), 'Caller-owned source/authority/execution selector entered native mutation schema.' );
	}
}
wpai199n_assert( 0 === $settings->defaults()[ Settings::GROUP_NATIVE_OPTIONS_WRITE ] && ! isset( $settings->defaults()[ Settings::GROUP_NATIVE_NETWORK_WRITE ] ), 'Native site write defaulted on or network write entered ordinary storage.' );
$source = array( 'value_json' => '{"format":"wpai-native-option-value-v1","value":{"type":"string","base64":"dGVzdA=="}}' );
$update = array( 'action' => 'update', 'scope' => 'site', 'name' => 'synthetic_native_secret', 'operation_id' => 'native-unit-update', 'value_source' => $source );
$delete = array( 'action' => 'delete', 'scope' => 'site', 'name' => 'synthetic_native_secret', 'operation_id' => 'native-unit-delete', 'confirm' => true );
$status = array( 'action' => 'status', 'scope' => 'site', 'operation_id' => 'native-unit-update' );
$legacy = array_fill_keys( array_keys( $settings->defaults() ), 1 );
unset( $legacy[ Settings::GROUP_NATIVE_OPTIONS_WRITE ] );
update_option( Settings::OPTION_NAME, $legacy, false );
wpai199n_assert( ! $provider->can_execute( $update ), 'Historical grants expanded into native mutation authority.' );
$flags = $settings->defaults();
foreach ( array( Settings::GROUP_NATIVE_OPTIONS_READ, Settings::GROUP_NATIVE_OPTIONS_WRITE, Settings::GROUP_SITE_CONFIG ) as $group ) { $flags[ $group ] = 1; }
update_option( Settings::OPTION_NAME, $flags, false );
wpai199n_assert( $provider->can_execute( $update ) && ! $provider->can_execute( $delete ), 'Native update/delete prerequisites were conflated.' );
$flags[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $flags, false );
wpai199n_assert( $provider->can_execute( $delete ), 'Explicit destructive native delete consent was denied.' );
foreach ( array( Settings::GROUP_NATIVE_OPTIONS_READ, Settings::GROUP_NATIVE_OPTIONS_WRITE, Settings::GROUP_SITE_CONFIG ) as $group ) {
	$revoked = $flags;
	$revoked[ $group ] = 0;
	update_option( Settings::OPTION_NAME, $revoked, false );
	wpai199n_assert( ! $provider->can_execute( $update ), 'Current native mutation prerequisite revocation was cached.' );
}
$read_only = $flags;
$read_only[ Settings::GROUP_NATIVE_OPTIONS_WRITE ] = 0;
$read_only[ Settings::GROUP_SITE_CONFIG ] = 0;
$read_only[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
update_option( Settings::OPTION_NAME, $read_only, false );
wpai199n_assert( $provider->can_execute( $status ), 'Historical status unnecessarily requires current write/destructive consent.' );
update_option( Settings::OPTION_NAME, $flags, false );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
wpai199n_assert( ! $provider->can_execute( $update ) && ! $provider->can_execute( $status ), 'Native capability denial was ignored.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
$GLOBALS['wpai_test']['user_id'] = 0;
wpai199n_assert( ! $provider->can_execute( $update ), 'Anonymous native mutation inherited stored flags.' );
$GLOBALS['wpai_test']['user_id'] = 1;
$GLOBALS['wpai_test']['multisite'] = true;
$GLOBALS['wpai_test']['super_admin'] = true;
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
$network_update = array_replace( $update, array( 'scope' => 'network' ) );
wpai199n_assert( ! $provider->can_execute( $network_update ), 'Site grants enabled network mutation without independent scalar consents.' );
$GLOBALS['wpai_test']['network_options'][1][ Native_Option_Access::NETWORK_OPTION_NAME ] = 1;
$GLOBALS['wpai_test']['network_options'][1][ Native_Option_Access::NETWORK_WRITE_OPTION_NAME ] = 1;
wpai199n_assert( $provider->can_execute( $network_update ), 'Genuine current network read/write flags were ignored.' );
$GLOBALS['wpai_test']['super_admin'] = false;
wpai199n_assert( ! $provider->can_execute( $network_update ), 'A local administrator with a network primitive became Super Admin.' );
$GLOBALS['wpai_test']['super_admin'] = true;
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = false;
wpai199n_assert( ! $provider->can_execute( $network_update ), 'Super Admin bypassed a native network capability denial.' );
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
$GLOBALS['wpai_test']['network'] = 2;
wpai199n_assert( ! $provider->can_execute( $network_update ), 'Another current network inherited the first network write flag.' );
$GLOBALS['wpai_test']['network'] = 1;
$GLOBALS['wpai_test']['multisite'] = false;

$bad = array(
	array_replace( $update, array( 'action' => 'upsert' ) ),
	array_replace( $update, array( 'name' => '' ) ),
	array_replace( $update, array( 'name' => "bad\0name" ) ),
	array_replace( $update, array( 'name' => ' trimmed' ) ),
	array_replace( $update, array( 'name' => "\xff" ) ),
	array_replace( $update, array( 'operation_id' => 'short' ) ),
	array_replace( $update, array( 'operation_id' => 'native operation spaces' ) ),
	array_replace( $update, array( 'value_source' => $source + array( 'transfer_id' => str_repeat( 'a', 64 ), 'sha256' => str_repeat( 'b', 64 ), 'total_bytes' => 1 ) ) ),
	array_replace( $update, array( 'value_source' => array( 'path' => '/tmp/caller' ) ) ),
	array_replace( $update, array( 'expected_observed_sha256' => 'invalid' ) ),
	array_replace( $delete, array( 'confirm' => false ) ),
	$status + array( 'name' => 'caller-target' ),
	array( 'action' => 'input_begin', 'scope' => 'site', 'total_bytes' => -1, 'sha256' => str_repeat( 'a', 64 ) ),
	array( 'action' => 'input_append', 'scope' => 'site', 'transfer_id' => str_repeat( 'a', 64 ), 'offset' => -1, 'base64' => 'AA==' ),
	array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => str_repeat( 'a', 64 ), 'acknowledge_unknown' => true ),
);
foreach ( array( 'blog_id', 'network_id', 'path', 'table', 'sql', 'callback', 'autoload', 'value' ) as $field ) { $bad[] = $update + array( $field => 'caller-owned' ); }
foreach ( $bad as $input ) { wpai199n_assert( ! $provider->can_execute( $input ), 'Closed native mutation boundary accepted malformed or caller-extended input.' ); }
echo 'PASS: native mutation consent and schema boundaries (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
