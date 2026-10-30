<?php
/**
 * Explicit database administration consents and published closed schemas.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Database_Schema_Abilities;
use WP_AI_Bridge\Abilities\Database_Native_SQL_Abilities;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

function get_current_blog_id() { return $GLOBALS['dbadmin_blog'] ?? 1; }
function get_current_network_id() { return $GLOBALS['dbadmin_network'] ?? 1; }
function is_multisite() { return ! empty( $GLOBALS['dbadmin_multisite'] ); }
function is_super_admin( $id = false ) { return ! empty( $GLOBALS['dbadmin_super'] ); }

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$settings = new Settings();
$permissions = new Permissions( $settings );
$schema = new Database_Schema_Abilities( $permissions );
$sql = new Database_Native_SQL_Abilities( $permissions );
$create = array( 'action' => 'execute', 'operation_id' => 'admin-create-one', 'operation' => array( 'action' => 'create_table' ) );
$drop = array( 'action' => 'execute', 'operation_id' => 'admin-drop-one', 'operation' => array( 'action' => 'drop_table' ) );
$native = array( 'action' => 'prepare', 'operation_id' => 'admin-native-one', 'acknowledge_native_account_scope' => true );
$assert( ! $schema->can_execute( $create ) && ! $sql->can_execute( $native ), 'New administration authority defaulted on.' );
update_option( Settings::OPTION_NAME, array_fill_keys( array_keys( $settings->defaults() ), 1 ), false );
$assert( ! $schema->can_execute( $create ), 'Ordinary settings enabled separate schema consent.' );
$grants = array_fill_keys( array_keys( $settings->database_defaults() ), 1 );
update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
$assert( $schema->can_execute( $create ) && $schema->can_execute( $drop ), 'Complete schema/native prerequisites were refused.' );
$assert( ! $sql->can_execute( $native ), 'Database grants bypassed server enablement.' );
define( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL', true );
$assert( $sql->can_execute( $native ), 'Explicit server enablement and all consents were refused.' );
$without_ack = $native; unset( $without_ack['acknowledge_native_account_scope'] );
$assert( ! $sql->can_execute( $without_ack ), 'Native account scope was silently acknowledged.' );
foreach ( array_keys( $settings->database_defaults() ) as $group ) {
	$revoked = $grants;
	$revoked[ $group ] = 0;
	update_option( Settings::DATABASE_OPTION_NAME, $revoked, false );
	$assert( ! $sql->can_execute( $native ), 'A current native SQL prerequisite revocation was cached or skipped.' );
}
update_option( Settings::DATABASE_OPTION_NAME, array_replace( $grants, array( Settings::GROUP_DATABASE_DELETE => 0 ) ), false );
$assert( $schema->can_execute( $create ) && ! $schema->can_execute( $drop ) && ! $schema->can_execute( array( 'action' => 'recover' ) ), 'Destructive schema authority was conflated with additive operations.' );
update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
$GLOBALS['dbadmin_multisite'] = true;
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
$assert( ! $schema->can_execute( $create ) && ! $sql->can_execute( $native ), 'A local multisite administrator acquired network database authority.' );
$GLOBALS['dbadmin_super'] = true;
$assert( $schema->can_execute( $create ) && $sql->can_execute( $native ), 'Actual current network authority was refused.' );
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = false;
$assert( ! $schema->can_execute( $create ) && ! $sql->can_execute( $native ), 'Super Admin bypassed a native network capability denial.' );
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
$GLOBALS['dbadmin_multisite'] = false;
$schema->register();
$sql->register();
foreach ( array( 'wp-ai-bridge/database-schema', 'wp-ai-bridge/database-native-sql' ) as $name ) {
	$args = $GLOBALS['wpai_test']['registered_abilities'][ $name ];
	$assert( array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ) === $args['meta']['annotations'], 'Mixed administration contract falsely advertised read-only/idempotent behavior.' );
	$assert( false === $args['input_schema']['additionalProperties'] && ! empty( $args['input_schema']['oneOf'] ), 'Administration public action schema was open or unavailable.' );
	foreach ( $args['input_schema']['oneOf'] as $variant ) {
		$assert( false === $variant['additionalProperties'], 'An administration action accepted caller extensions.' );
	}
	foreach ( array( 'credentials', 'host', 'password', 'database', 'callback', 'php', 'shell' ) as $field ) {
		$assert( ! isset( $args['input_schema']['properties'][ $field ] ), 'Connection/proxy authority was caller-selected.' );
	}
}
echo "PASS: database administration consent, server enablement, network authority and closed schemas ({$checks} checks).\n";
