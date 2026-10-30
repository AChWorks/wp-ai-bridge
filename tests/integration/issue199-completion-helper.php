<?php
/**
 * Real protected-grant/root setup shared by disposable completion fixtures.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Administration_Configuration;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-completion-oracle.php';

function wpai199c_options_before() {
	$before = array();
	foreach ( array( Settings::OPTION_NAME, Settings::DATABASE_OPTION_NAME, Settings::ADMINISTRATION_OPTION_NAME, Administration_Configuration::OPTION_NAME ) as $name ) {
		$marker = new stdClass();
		$value = get_option( $name, $marker );
		$before[ $name ] = array( 'present' => $value !== $marker, 'value' => $value );
	}
	return $before;
}

/** Restore only exact saved options under the original actor/blog context. */
function wpai199c_restore_options( array $before ) {
	foreach ( $before as $name => $saved ) {
		if ( $saved['present'] ) {
			update_option( $name, $saved['value'], false );
		} else {
			delete_option( $name );
		}
	}
}

function wpai199c_grants( $enabled = true ) {
	wpai199c_assert( current_user_can( 'manage_options' ), 'Completion fixture requires its actual native administrator.' );
	$settings = new Settings();
	$ordinary = $settings->all();
	foreach ( array( Settings::GROUP_SITE_READ, Settings::GROUP_SITE_CONFIG, Settings::GROUP_CODE_EXTENSIONS, Settings::GROUP_NATIVE_OPTIONS_READ, Settings::GROUP_NATIVE_OPTIONS_WRITE, Settings::GROUP_USERS_DESTRUCTIVE ) as $group ) {
		$ordinary[ $group ] = $enabled ? 1 : 0;
	}
	$ordinary[ Settings::GROUP_NATIVE_ABILITIES ] = 0;
	update_option( Settings::OPTION_NAME, $settings->sanitize( $ordinary ), false );
	update_option( Settings::DATABASE_OPTION_NAME, $settings->sanitize_database_grants( array_fill_keys( Settings::DATABASE_GROUPS, $enabled ? 1 : 0 ) ), false );
	$protected = $settings->sanitize_administration_grants( array_fill_keys( Settings::ADMINISTRATION_GROUPS, $enabled ? 1 : 0 ) );
	update_option( Settings::ADMINISTRATION_OPTION_NAME, $protected, false );
	return $protected;
}

/** Existing directories are explicitly operator configured, never AI path inputs. */
function wpai199c_configure( $fixture_directory, $restore_root, $restore_schema, array $provider_tables, $scope = 'site' ) {
	wpai199c_assert( in_array( $scope, array( 'site', 'network' ), true ), 'Invalid synthetic root scope.' );
	$configuration = new Administration_Configuration();
	$roots = array(
		array( 'id' => 'installation', 'label' => 'Disposable installation', 'path' => rtrim( ABSPATH, '/\\' ), 'purposes' => array( 'backup_export' ), 'read' => true, 'write' => false, 'destructive' => false, 'executable' => false, 'scope' => $scope, 'nonserved' => false ),
		array( 'id' => 'fixtures', 'label' => 'Synthetic provider files and logs', 'path' => $fixture_directory, 'purposes' => array( 'filesystem', 'logs' ), 'read' => true, 'write' => true, 'destructive' => true, 'executable' => true, 'scope' => $scope, 'nonserved' => false ),
		array( 'id' => 'restore', 'label' => 'Separate nonserved restore destination', 'path' => $restore_root, 'purposes' => array( 'backup_restore' ), 'read' => true, 'write' => true, 'destructive' => true, 'executable' => true, 'scope' => $scope, 'nonserved' => true ),
	);
	$stored = $configuration->sanitize( array( 'roots' => $roots, 'restore_schemas' => array( $restore_schema ), 'backup_tables' => $provider_tables, 'step_bytes' => 1048576, 'step_seconds' => 5 ) );
	wpai199c_assert( is_array( $stored ), 'Synthetic operator root configuration failed.' );
	update_option( Administration_Configuration::OPTION_NAME, $stored, false );
	$current = $configuration->all();
	wpai199c_assert( true === ( $current['valid'] ?? false ) && count( $current['roots'] ) === 3, 'Stored canonical root configuration is not currently valid.' );
	return $current;
}

function wpai199c_ability( $id, array $input ) {
	$ability = wp_get_ability( 'wp-ai-bridge/' . $id );
	wpai199c_assert( $ability instanceof WP_Ability, 'Completion Ability missing: ' . $id );
	return $ability->execute( $input );
}

function wpai199c_ok( $value, $message ) {
	wpai199c_assert( ! is_wp_error( $value ), $message . ( is_wp_error( $value ) ? ' [' . $value->get_error_code() . ']' : '' ) );
	wpai199c_assert( is_array( $value ), 'Completion result must be an object-shaped array.' );
	return $value;
}

/** Test-owned cleanup refuses paths/tables outside the seed's exact run identity. */
function wpai199c_cleanup_seed( array $seed, $suffix ) {
	global $wpdb;
	wpai199c_assert( is_string( $suffix ) && preg_match( '/^[a-f0-9]{8}$/D', $suffix ), 'Cleanup run identity invalid.' );
	foreach ( $seed['tables'] as $table ) {
		wpai199c_assert( in_array( $table, array( 'provider_admin13_private_' . $suffix, 'provider_admin13_keyless_' . $suffix, 'provider_admin13_effects_' . $suffix ), true ), 'Refusing unrelated table cleanup.' );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
	$directory = ABSPATH . 'wp-content/uploads/wpai199c-' . $suffix;
	wpai199c_assert( $directory === $seed['directory'] && is_dir( $directory ) && ! is_link( $directory ), 'Refusing ambiguous fixture directory cleanup.' );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $entry ) {
		if ( $entry->isDir() && ! $entry->isLink() ) {
			rmdir( $entry->getPathname() );
		} else {
			unlink( $entry->getPathname() );
		}
	}
	rmdir( $directory );
}
