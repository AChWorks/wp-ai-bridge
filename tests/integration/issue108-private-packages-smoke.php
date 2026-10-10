<?php
/**
 * Real native WordPress Core plugin/theme upgrade and fail-closed staged ZIP checks.
 *
 * Requires actual WordPress, the official Adapter, ZipArchive and an isolated site.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Package_Store;
use WP_AI_Bridge\Support\Settings;

$GLOBALS['wpai108_live_checks'] = 0;
function wpai108_live_check( $condition, $message ) {
	++$GLOBALS['wpai108_live_checks'];
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai108_live_zip( $kind, $root ) {
	$path = wp_tempnam( 'wpai108-fixture.zip' );
	wpai108_live_check( is_string( $path ) && '' !== $path, 'WordPress could not create an isolated ZIP fixture.' );
	$archive = new ZipArchive();
	wpai108_live_check( true === $archive->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'ZipArchive cannot write fixture.' );
	$archive->addEmptyDir( $root );
	if ( 'plugin' === $kind ) {
		$archive->addFromString( $root . '/' . $root . '.php', "<?php\n/*\nPlugin Name: WP AI Bridge Private Test\nVersion: 1.0\n*/\n" );
	} else {
		$archive->addFromString( $root . '/style.css', "/*\nTheme Name: WP AI Bridge Private Test\nVersion: 1.0\n*/\n" );
		$archive->addFromString( $root . '/index.php', "<?php\n" );
	}
	$archive->close();
	return $path;
}

function wpai108_live_stage( Private_Package_Store $store, $kind, $id, $client, $root ) {
	$property  = new ReflectionMethod( Private_Package_Store::class, 'directory' );
	$directory = $property->invoke( $store );
	wpai108_live_check( ! is_wp_error( $directory ), 'Private directory must be accessible and non-public.' );
	$source = wpai108_live_zip( $kind, $root );
	$dest   = $directory . '/' . $id . '.zip';
	wpai108_live_check( copy( $source, $dest ), 'Fixture setup could not stage a private ZIP.' );
	wp_delete_file( $source );
	wpai108_live_check( chmod( $dest, 0600 ), 'Fixture private file mode must be restrictive.' );
	$check = $store->inspect_zip( $dest, $kind );
	wpai108_live_check( ! is_wp_error( $check ) && $root === $check['root'], 'Real ZipArchive rejected legitimate package.' );
	$meta = array(
		'id'              => $id,
		'kind'            => $kind,
		'user_id'         => get_current_user_id(),
		'blog_id'         => get_current_blog_id(),
		'client_id'       => $client,
		'client_revision' => 0,
		'filename'        => $root . '.zip',
		'sha256'          => hash_file( 'sha256', $dest ),
		'bytes'           => filesize( $dest ),
		'root'            => $root,
		'created'         => time(),
		'expires'         => time() + 3600,
		'status'          => 'staged',
	);
	wpai108_live_check( add_option( Private_Package_Store::OPTION_PREFIX . $id, $meta, '', false ), 'Fixture metadata insertion must be unique.' );
	return array( $meta, $dest );
}

$settings                                    = new Settings();
$permissions                                 = new Permissions( $settings );
$store                                       = new Private_Package_Store( $permissions );
$original                                    = get_option( Settings::OPTION_NAME, $settings->defaults() );
$client                                      = OAuth_Server::CHATGPT_CLIENT_ID;
$grants                                      = $settings->defaults();
$grants[ Settings::GROUP_CODE_EXTENSIONS ]   = 1;
$grants[ Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
$plugin_root                                 = 'wpai108-private-plugin';
$theme_root                                  = 'wpai108-private-theme';
$plugin_key                                  = $plugin_root . '/' . $plugin_root . '.php';
$fixture_ids                                 = array();
$fixture_files                               = array();
$installed_plugin                            = false;
$installed_theme                             = false;

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

wpai108_live_check( class_exists( 'ZipArchive' ), 'Real WordPress suite requires PHP zip support.' );
wpai108_live_check( ! isset( get_plugins()[ $plugin_key ] ), 'Use an isolated WordPress site without fixture plugin.' );
wpai108_live_check( ! wp_get_theme( $theme_root )->exists(), 'Use an isolated WordPress site without fixture theme.' );
wpai108_live_check( wp_get_ability( 'wp-ai-bridge/private-packages-read' ) instanceof WP_Ability, 'Inspector WordPress Ability not registered.' );
wpai108_live_check( wp_get_ability( 'wp-ai-bridge/private-package-install' ) instanceof WP_Ability, 'Installer WordPress Ability not registered.' );
wpai108_live_check( ! $store->allowed( 'plugin' ), 'Default-off consent must deny WordPress installer.' );

try {
	update_option( Settings::OPTION_NAME, $grants, false );
	foreach ( array(
		'plugin' => $plugin_root,
		'theme'  => $theme_root,
	) as $kind => $root ) {
		if ( ! $store->allowed( $kind ) ) {
			// Multisite native install caps are only present for Super Admin.
			wpai108_live_check( false, 'Isolated administrator lacks native ' . $kind . ' install authority.' );
		}
		$id                  = bin2hex( random_bytes( 24 ) );
		$fixture_ids[]       = $id;
		list( $meta, $path ) = wpai108_live_stage( $store, $kind, $id, $client, $root );
		$fixture_files[]     = $path;

		$bad = $store->install( $id, str_repeat( '0', 64 ), $kind, $client );
		wpai108_live_check( is_wp_error( $bad ) && 'private_package_hash_mismatch' === $bad->get_error_code(), 'Wrong SHA must be rejected before claim.' );

		$grants[ Settings::GROUP_EXTERNAL_PACKAGES ] = 0;
		update_option( Settings::OPTION_NAME, $grants, false );
		$denied = $store->install( $id, $meta['sha256'], $kind, $client );
		wpai108_live_check( is_wp_error( $denied ), 'Revoking package permission must prevent Core install.' );
		$grants[ Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
		update_option( Settings::OPTION_NAME, $grants, false );

		$ok = $store->install( $id, $meta['sha256'], $kind, $client );
		wpai108_live_check( ! is_wp_error( $ok ) && true === $ok['installed'] && false === $ok['activated'], 'Native Core must install without activating the extension.' );
		if ( 'plugin' === $kind ) {
			$installed_plugin = true;
			wpai108_live_check( $plugin_key === $ok['target'] && isset( get_plugins()[ $plugin_key ] ), 'Native plugin installed identity must match reviewed archive.' );
			wpai108_live_check( ! is_plugin_active( $plugin_key ), 'Private plugin installation silently activated code.' );
		} else {
			$installed_theme = true;
			wpai108_live_check( $theme_root === $ok['target'] && wp_get_theme( $theme_root )->exists(), 'Native theme installed identity must match reviewed archive.' );
			wpai108_live_check( get_stylesheet() !== $theme_root, 'Private theme install silently switched active theme.' );
		}
		wpai108_live_check( ! is_file( $path ), 'Native successful install left staged executable ZIP.' );
		$second = $store->install( $id, $meta['sha256'], $kind, $client );
		wpai108_live_check( is_wp_error( $second ) && 'private_package_not_staged' === $second->get_error_code(), 'Replay must not begin second native install.' );
	}
} finally {
	update_option( Settings::OPTION_NAME, $original, false );
	if ( $installed_plugin && isset( get_plugins()[ $plugin_key ] ) && ! is_plugin_active( $plugin_key ) ) {
		delete_plugins( array( $plugin_key ) );
	}
	if ( $installed_theme && wp_get_theme( $theme_root )->exists() && get_stylesheet() !== $theme_root ) {
		delete_theme( $theme_root );
	}
	foreach ( $fixture_ids as $id ) {
		delete_option( Private_Package_Store::OPTION_PREFIX . $id );
		delete_option( Private_Package_Store::CLAIM_PREFIX . $id );
	}
	foreach ( $fixture_files as $path ) {
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
echo 'PASS: Issue #108 real native WordPress plugin/theme integration (' . $GLOBALS['wpai108_live_checks'] . " assertions).\n";
