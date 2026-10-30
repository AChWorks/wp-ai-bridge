<?php
/**
 * Typed database-write delegation and closed request-boundary regressions.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Database_Write_Abilities;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Permissions;

function is_multisite() { return ! empty( $GLOBALS['wpai_test']['multisite'] ); }
function is_super_admin( $id = false ) { return ! empty( $GLOBALS['wpai_test']['super_admin'] ); }
function wp_salt( $scheme = 'auth' ) { return 'synthetic-database-write-199-salt'; }

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

wpai_test_reset_state();
$settings = new Settings();
$provider = new Database_Write_Abilities( new Permissions( $settings ) );
$registered = $provider->register();
$assert( 1 === count( $registered ), 'Typed writes must expose exactly one public Ability.' );
$args = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/database-write'];
$assert( false === $args['meta']['annotations']['readonly'] && true === $args['meta']['annotations']['destructive'], 'Database writes must advertise mutation/destructive authority.' );
$assert( false === $args['input_schema']['additionalProperties'], 'Typed database input must remain closed.' );
foreach ( array( 'sql', 'database', 'connection', 'path', 'allow_partial', 'upsert' ) as $field ) {
	$assert( ! isset( $args['input_schema']['properties'][ $field ] ), 'An untyped execution escape entered the public schema.' );
}
foreach ( array( Settings::GROUP_DATABASE_WRITE, Settings::GROUP_DATABASE_DELETE ) as $group ) {
	$assert( 0 === $settings->database_defaults()[ $group ], 'A new powerful database group defaulted on.' );
	$assert( in_array( $group, Settings::DATABASE_GROUPS, true ), 'A database write group escaped protected option storage.' );
}

$describe = array( 'action' => 'describe', 'table' => 'wp_fixture' );
$delete = array( 'action' => 'execute', 'operation_id' => 'database-write-unit-delete', 'operations' => array( array( 'action' => 'delete', 'table' => 'wp_fixture', 'expected_schema_hash' => str_repeat( 'a', 64 ), 'key' => array( 'id' => base64_encode( '1' ) ), 'expected_state_hash' => str_repeat( 'b', 64 ) ) ) );
$historical_delete = array( 'action' => 'recover', 'operation_id' => 'database-write-unit-delete', 'destructive' => true );
$legacy = $settings->defaults();
foreach ( $settings->groups() as $group => $definition ) {
	$legacy[ $group ] = 1;
}
update_option( Settings::OPTION_NAME, $legacy, false );
$assert( ! $provider->can_write( $describe ), 'Legacy embedded grants automatically authorized database writes.' );
$assert( is_wp_error( $provider->execute( $describe ) ), 'Default-off invocation reached the database engine.' );
$grants = $settings->database_defaults();
$requirements = array( Settings::GROUP_DATABASE_INSPECTION, Settings::GROUP_DATABASE_READ, Settings::GROUP_DATABASE_WRITE );
foreach ( $requirements as $group ) {
	$grants[ $group ] = 1;
}
update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
$assert( $provider->can_write( $describe ), 'An explicit administrator write grant was denied.' );
$assert( ! $provider->can_write( $delete ) && ! $provider->can_write( $historical_delete ), 'Write authority silently included destructive execution/recovery.' );
$grants[ Settings::GROUP_DATABASE_DELETE ] = 1;
update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
$assert( $provider->can_write( $delete ) && $provider->can_write( $historical_delete ), 'Explicit destructive consent was ignored.' );
foreach ( $requirements as $group ) {
	$revoked = $grants;
	$revoked[ $group ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $revoked, false );
	$assert( ! $provider->can_write( $describe ) && is_wp_error( $provider->execute( $describe ) ), 'A previously checked request bypassed current prerequisite revocation.' );
}
update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
$assert( ! $provider->can_write( $describe ), 'Nonadministrator inherited write delegation.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
$GLOBALS['wpai_test']['user_id'] = 0;
$assert( ! $provider->can_write( $describe ), 'Anonymous principal inherited write delegation.' );
$GLOBALS['wpai_test']['user_id'] = 1;
$GLOBALS['wpai_test']['multisite'] = true;
$GLOBALS['wpai_test']['super_admin'] = false;
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
$assert( ! $provider->can_write( $describe ), 'A local administrator with a network primitive became Super Admin.' );
$GLOBALS['wpai_test']['super_admin'] = true;
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = false;
$assert( ! $provider->can_write( $describe ), 'Super Admin bypassed explicit native network denial.' );
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
$assert( $provider->can_write( $describe ), 'Actual Super Admin/network authority was denied.' );
$GLOBALS['wpai_test']['multisite'] = false;

$validate = new ReflectionMethod( Database_Write_Abilities::class, 'validate' );
$insert = array(
	'action' => 'execute', 'operation_id' => 'database-write-unit-insert',
	'operations' => array( array(
		'action' => 'insert', 'table' => 'wp_fixture', 'expected_schema_hash' => str_repeat( 'a', 64 ),
		'values' => (object) array( '0' => base64_encode( "\x00\xff" ), '01' => '', 'nullable' => null ),
	) ),
);
$normalized = $validate->invoke( $provider, (object) $insert );
$assert( ! is_wp_error( $normalized ) && 3 === count( $normalized['operations'][0]['values'] ), 'Native request objects conflated exact numeric column names/NULL/empty bytes.' );
$assert( array_key_exists( '0', $normalized['operations'][0]['values'] ) && array_key_exists( '01', $normalized['operations'][0]['values'] ) && null === $normalized['operations'][0]['values']['nullable'], 'Numeric maps or explicit NULL changed during normalization.' );
$staged = $insert;
$staged['operations'][0]['values'] = (object) array( 'payload' => (object) array( 'transfer_id' => str_repeat( 'a', 64 ), 'total_bytes' => 4194304, 'sha256' => str_repeat( 'b', 64 ) ) );
$assert( ! is_wp_error( $validate->invoke( $provider, $staged ) ), 'An exact staged-value descriptor was rejected by the closed boundary.' );
$staged_key = array( 'action' => 'row_state', 'table' => 'wp_fixture', 'key' => (object) array( 'large_key' => (object) array( 'transfer_id' => str_repeat( 'a', 64 ), 'total_bytes' => 4194304, 'sha256' => str_repeat( 'b', 64 ) ) ) );
$assert( ! is_wp_error( $validate->invoke( $provider, $staged_key ) ), 'A complete staged primary-key descriptor was rejected.' );
foreach ( array( 'stage_status', 'stage_verify', 'stage_cancel', 'stage_release' ) as $action ) {
	$assert( ! is_wp_error( $validate->invoke( $provider, array( 'action' => $action, 'transfer_id' => str_repeat( 'a', 64 ) ) ) ), 'A typed database stage lifecycle action was rejected.' );
}
$assert( ! is_wp_error( $validate->invoke( $provider, array( 'action' => 'stage_open', 'total_bytes' => 4194304, 'sha256' => str_repeat( 'a', 64 ) ) ) ), 'Staging imposed an inline payload ceiling.' );
$assert( ! is_wp_error( $validate->invoke( $provider, array( 'action' => 'stage_append', 'transfer_id' => str_repeat( 'a', 64 ), 'offset' => 0, 'content_base64' => base64_encode( "\0bytes" ) ) ) ), 'Typed stage append fields were rejected.' );

$bad = array(
	null, 'execute', array(), array( 'action' => 'query' ), $describe + array( 'sql' => 'SELECT 1' ),
	$describe + array( 'database' => 'other' ), $describe + array( 'key' => array( 'id' => 'MQ==' ) ),
	array( 'action' => 'row_state', 'table' => 'wp_fixture' ),
	array( 'action' => 'execute', 'operations' => $insert['operations'] ),
	array( 'action' => 'execute', 'operation_id' => 'x', 'operations' => array() ),
	array( 'action' => 'execute', 'operation_id' => 'x', 'operations' => (object) array( 'named' => $insert['operations'][0] ) ),
	array( 'action' => 'recover' ),
	array( 'action' => 'recover', 'operation_id' => 'x', 'destructive' => 1 ),
	array( 'action' => 'stage_open', 'total_bytes' => 1, 'sha256' => str_repeat( 'a', 64 ), 'purpose' => 'media' ),
	array( 'action' => 'stage_release', 'transfer_id' => str_repeat( 'a', 64 ), 'acknowledge_unknown' => true ),
	array( 'action' => 'stage_status', 'transfer_id' => str_repeat( 'a', 64 ), 'path' => '/tmp/caller' ),
);
foreach ( array( 'sql', 'upsert', 'where', 'limit', 'allow_partial', 'expression' ) as $field ) {
	$malformed = $insert;
	$malformed['operations'][0][ $field ] = 'caller-controlled';
	$bad[] = $malformed;
}
foreach ( array(
	array( 'action' => 'update', 'table' => 'wp_fixture', 'expected_schema_hash' => str_repeat( 'a', 64 ), 'values' => array( 'payload' => 'MQ==' ) ),
	array( 'action' => 'delete', 'table' => 'wp_fixture', 'expected_schema_hash' => str_repeat( 'a', 64 ), 'key' => array( 'id' => 'MQ==' ) ),
	array( 'action' => 'insert', 'table' => 'wp_fixture', 'expected_schema_hash' => str_repeat( 'a', 64 ), 'values' => array( 'payload' => array( 'path' => '/tmp/caller' ) ) ),
) as $step ) {
	$bad[] = array( 'action' => 'execute', 'operation_id' => 'x', 'operations' => array( $step ) );
}
foreach ( $bad as $input ) {
	$assert( is_wp_error( $validate->invoke( $provider, $input ) ), 'Malformed or caller-extended write input passed validation.' );
}
echo "PASS: Issue #199 typed database-write delegation/request boundaries ({$checks} checks).\n";
