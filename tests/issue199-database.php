<?php
/**
 * Database delegation, migration and request-boundary regression tests.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Database_Abilities;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

function is_multisite() { return ! empty( $GLOBALS['wpai_test']['multisite'] ); }
function is_super_admin( $id = false ) { return ! empty( $GLOBALS['wpai_test']['super_admin'] ); }
function wp_salt( $scheme = 'auth' ) { return 'synthetic-database-199-salt'; }

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

wpai_test_reset_state();
$settings = new Settings();
$provider = new Database_Abilities( new Permissions( $settings ), new Mutation_Log() );
$registered = $provider->register();
$assert( 2 === count( $registered ), 'Both database contracts must register through the native registry.' );
foreach ( array( 'database-inspect', 'database-read' ) as $name ) {
	$args = $GLOBALS['wpai_test']['registered_abilities'][ 'wp-ai-bridge/' . $name ];
	$assert( true === $args['meta']['annotations']['readonly'] && false === $args['meta']['annotations']['destructive'], 'Database reads must not advertise mutation authority.' );
	$assert( false === $args['input_schema']['additionalProperties'], 'Database input must remain closed.' );
	$assert( ! isset( $args['input_schema']['properties']['sql'], $args['input_schema']['properties']['database'] ), 'A raw query or database selector entered the public schema.' );
}

$assert( 0 === $settings->defaults()[ Settings::GROUP_DATABASE_INSPECTION ], 'Database Inspection defaults enabled.' );
$assert( 0 === $settings->defaults()[ Settings::GROUP_DATABASE_READ ], 'Sensitive Database Read defaults enabled.' );
$old = $settings->defaults();
unset( $old[ Settings::GROUP_DATABASE_INSPECTION ], $old[ Settings::GROUP_DATABASE_READ ] );
foreach ( $old as $key => $unused ) {
	$old[ $key ] = 1;
}
update_option( Settings::OPTION_NAME, $old, false );
$assert( ! $provider->can_inspect() && ! $provider->can_read(), 'Old unrelated grants automatically inherited database access.' );
$assert( is_wp_error( $provider->inspect( array( 'action' => 'tables' ) ) ), 'Disabled inspector reached execution.' );
$assert( is_wp_error( $provider->read( array( 'action' => 'rows', 'table' => 'wp_any', 'columns' => array( 'value' ) ) ) ), 'Old grants disclosed raw values.' );

$access = $settings->defaults();
$access[ Settings::GROUP_DATABASE_INSPECTION ] = 1;
update_option( Settings::OPTION_NAME, $access, false );
$assert( $provider->can_inspect() && ! $provider->can_read(), 'Schema inspection also granted sensitive rows.' );
$access[ Settings::GROUP_DATABASE_READ ] = 1;
update_option( Settings::OPTION_NAME, $access, false );
$assert( $provider->can_read(), 'Explicit administrator grants did not enable raw reads.' );

$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
$assert( ! $provider->can_read(), 'A nonadministrator inherited database grants.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
$GLOBALS['wpai_test']['user_id'] = 0;
$assert( ! $provider->can_inspect(), 'Anonymous requests obtained database access.' );
$GLOBALS['wpai_test']['user_id'] = 1;

$GLOBALS['wpai_test']['multisite'] = true;
$GLOBALS['wpai_test']['super_admin'] = false;
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
$assert( ! $provider->can_inspect(), 'A site administrator with a forged network primitive became Super Admin.' );
$GLOBALS['wpai_test']['super_admin'] = true;
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = false;
$assert( ! $provider->can_read(), 'Super Admin bypassed an explicit native network denial.' );
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
$assert( $provider->can_read(), 'Actual Super Admin/network authority was denied.' );
$GLOBALS['wpai_test']['multisite'] = false;

$validate = new ReflectionMethod( Database_Abilities::class, 'validate' );
$rows = array( 'action' => 'rows', 'table' => 'wp_fixture', 'columns' => array( 'id' ) );
$valid = $validate->invoke( $provider, $rows, true );
$assert( ! is_wp_error( $valid ), 'A minimal typed row request was rejected.' );
$binary = $rows + array( 'filters' => array( 'id' => base64_encode( "\x00\xff" ) ) );
$assert( ! is_wp_error( $validate->invoke( $provider, $binary, true ) ), 'Canonical binary filter values were rejected.' );
$unicode = array( 'action' => 'schema', 'table' => 'wp_داده', 'limit' => 1 );
$assert( ! is_wp_error( $validate->invoke( $provider, $unicode, false ) ), 'Legitimate UTF-8 identifiers were rejected.' );

$bad = array(
	null, 'rows', array(), array( 'action' => array() ), array( 'action' => 'query' ),
	$rows + array( 'sql' => 'SELECT 1' ),
	$rows + array( 'database' => 'other_tenant' ),
	$rows + array( 'filters' => null ),
	$rows + array( 'filters' => array( 'id' => 'not base64' ) ),
	$rows + array( 'filters' => array( 'id' => "MQ==\n" ) ),
	$rows + array( 'filters' => array( 'id' => 1 ) ),
	$rows + array( 'after_id' => null ),
	$rows + array( 'after_id' => '1 OR 1=1' ),
	$rows + array( 'after_id' => 1 ),
	$rows + array( 'after_id' => '01' ),
	$rows + array( 'limit' => null ),
	$rows + array( 'limit' => 0 ),
	$rows + array( 'limit' => 1.5 ),
	$rows + array( 'limit' => '1' ),
	$rows + array( 'limit' => intdiv( Bounded_Payload::response_limit(), 2048 ) + 1 ),
	$rows + array( 'offset' => -1 ),
	$rows + array( 'offset' => PHP_INT_MAX ),
	array_replace( $rows, array( 'columns' => array( 'id', 'id' ) ) ),
	array_replace( $rows, array( 'columns' => array( array() ) ) ),
	array_replace( $rows, array( 'columns' => array( 'named' => 'id' ) ) ),
	array_replace( $rows, array( 'table' => "wp_bad\0name" ) ),
	array_replace( $rows, array( 'table' => "wp_bad\nname" ) ),
	array_replace( $rows, array( 'table' => str_repeat( 'x', 65 ) ) ),
	array( 'action' => 'tables', 'table' => 'wp_fixture' ),
	array( 'action' => 'tables', 'after_table' => null ),
	array( 'action' => 'schema', 'table' => 'wp_fixture', 'section' => null ),
	array( 'action' => 'schema', 'table' => 'wp_fixture', 'section' => 'defaults' ),
	array( 'action' => 'cell', 'table' => 'wp_fixture', 'column' => 'payload', 'key' => array() ),
	array( 'action' => 'cell', 'table' => 'wp_fixture', 'column' => 'payload', 'key' => array( 'id' => base64_encode( '1' ) ), 'offset' => 1 ),
	array( 'action' => 'cell', 'table' => 'wp_fixture', 'column' => 'payload', 'key' => array( 'id' => base64_encode( '1' ) ), 'expected_sha256' => null ),
	array( 'action' => 'cell', 'table' => 'wp_fixture', 'column' => 'payload', 'key' => array( 'id' => base64_encode( '1' ) ), 'length' => intdiv( Bounded_Payload::response_limit(), 16 ) + 1 ),
);
$oversized = base64_encode( str_repeat( 'x', intdiv( Bounded_Payload::response_limit(), 2 ) ) );
$bad[] = $rows + array( 'filters' => array( 'id' => $oversized, 'value' => $oversized ) );
$bad[] = array_replace( $rows, array( 'limit' => intdiv( Bounded_Payload::response_limit(), 2048 ), 'columns' => array( 'id', 'a', 'b', 'c' ) ) );
$bad[] = array( 'action' => 'cell', 'table' => 'wp_fixture', 'column' => 'payload', 'key_hashes' => array( 'id' => null ) );
$bad[] = array( 'action' => 'cell', 'table' => 'wp_fixture', 'column' => 'payload', 'key_hashes' => array( 'id' => 'not-a-sha256' ) );
$bad[] = array( 'action' => 'cell', 'table' => 'wp_fixture', 'column' => 'payload', 'key_hashes' => array() );
$hash_key = array( 'action' => 'cell', 'table' => 'wp_fixture', 'column' => 'payload', 'key_hashes' => array( 'id' => hash( 'sha256', 'exact value' ) ) );
$assert( ! is_wp_error( $validate->invoke( $provider, $hash_key, true ) ), 'An exact hash-only cell selector was rejected.' );

foreach ( $bad as $input ) {
	$raw = ! is_array( $input ) || ! isset( $input['action'] ) || ! in_array( $input['action'], array( 'tables', 'schema' ), true );
	$assert( is_wp_error( $validate->invoke( $provider, $input, $raw ) ), 'Malformed/oversized database input passed the boundary.' );
}

$access[ Settings::GROUP_DATABASE_READ ] = 0;
update_option( Settings::OPTION_NAME, $access, false );
$assert( ! $provider->can_read() && is_wp_error( $provider->read( $rows ) ), 'Revocation was cached after a previous permission check.' );
$access[ Settings::GROUP_DATABASE_READ ] = 1;
$access[ Settings::GROUP_DATABASE_INSPECTION ] = 0;
update_option( Settings::OPTION_NAME, $access, false );
$assert( ! $provider->can_read(), 'Sensitive read bypassed revoked inspection authority.' );

echo "PASS: Issue #199 database delegation/input boundaries ({$checks} checks).\n";
