<?php
/**
 * Real multisite blog isolation, per-principal native install caps and file-mod policy.
 *
 * Run on the isolated network-activated WordPress multisite fixture only.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Package_Store;
use WP_AI_Bridge\Support\Settings;

function wpai108_ms_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

wpai108_ms_check( is_multisite(), 'A true WordPress multisite fixture is required.' );
$original_blog = get_current_blog_id();
$super_admin   = get_current_user_id();
$secondary     = 0;
foreach ( get_sites(
	array(
		'fields' => 'ids',
		'number' => 10,
	)
) as $site_id ) {
	if ( (int) $site_id !== $original_blog ) {
		$secondary = (int) $site_id;
		break;
	}
}
wpai108_ms_check( $secondary > 0, 'Fixture must contain a real secondary multisite blog.' );
wpai108_ms_check( is_super_admin( $super_admin ), 'Fixture must be run as network Super Admin.' );

$settings                                    = new Settings();
$store                                       = new Private_Package_Store( new Permissions( $settings ) );
$original                                    = get_option( Settings::OPTION_NAME, $settings->defaults() );
$grants                                      = $settings->defaults();
$grants[ Settings::GROUP_CODE_EXTENSIONS ]   = 1;
$grants[ Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
$id   = bin2hex( random_bytes( 24 ) );
$meta = array(
	'id'              => $id,
	'kind'            => 'plugin',
	'user_id'         => $super_admin,
	'blog_id'         => $original_blog,
	'client_id'       => OAuth_Server::CHATGPT_CLIENT_ID,
	'client_revision' => 0,
	'filename'        => 'private-fixture.zip',
	'sha256'          => str_repeat( 'a', 64 ),
	'bytes'           => 100,
	'root'            => 'private-fixture',
	'created'         => time(),
	'expires'         => time() + 3600,
	'status'          => 'staged',
);
update_option( Settings::OPTION_NAME, $grants, false );
$second_original = null;
$secondary_open  = false;
$test_user       = 0;

try {
	wpai108_ms_check( $store->allowed( 'plugin' ), 'Network Super Admin must have native plugin install authority on the main site.' );
	wpai108_ms_check( add_option( Private_Package_Store::OPTION_PREFIX . $id, $meta, '', false ), 'Fixture metadata must not collide with existing private records.' );
	wpai108_ms_check( ! is_wp_error( $store->inspect( $id, OAuth_Server::CHATGPT_CLIENT_ID ) ), 'Exact principal must inspect only its current-blog artifact.' );

	switch_to_blog( $secondary );
	$secondary_open  = true;
	$second_original = get_option( Settings::OPTION_NAME, $settings->defaults() );
	update_option( Settings::OPTION_NAME, $grants, false );

	wpai108_ms_check( is_wp_error( $store->inspect( $id, OAuth_Server::CHATGPT_CLIENT_ID ) ), 'Current-blog option namespace must deny a main-site artifact.' );
	wpai108_ms_check( $store->allowed( 'plugin' ), 'Multisite Super Admin must retain network-level install authority.' );

	$login     = 'wpai108-ms-user-' . bin2hex( random_bytes( 4 ) );
	$test_user = wp_create_user( $login, wp_generate_password( 32 ), $login . '@example.invalid' );
	wpai108_ms_check( ! is_wp_error( $test_user ) && $test_user > 0, 'Could not create an isolated ordinary multisite administrator.' );
	wpai108_ms_check( ! is_wp_error( add_user_to_blog( $secondary, $test_user, 'administrator' ) ), 'Could not assign secondary-blog Administrator role.' );

	wp_set_current_user( $test_user );
	wpai108_ms_check( current_user_can( 'manage_options' ), 'Ordinary blog admin fixture lacks expected settings role.' );
	wpai108_ms_check( ! current_user_can( 'install_plugins' ), 'Ordinary multisite blog admin unexpectedly gained plugin installation authority.' );
	wpai108_ms_check( ! $store->allowed( 'plugin' ), 'Both Bridge grants cannot confer network plugin install capability.' );
	wpai108_ms_check( is_wp_error( $store->inspect( $id, OAuth_Server::CHATGPT_CLIENT_ID ) ), 'Blog admin must not inspect another blog or principal artifact.' );

	wp_set_current_user( $super_admin );
	wpai108_ms_check( ! defined( 'DISALLOW_FILE_MODS' ), 'Multisite fixture unexpectedly defines immutable file-modification policy.' );
	define( 'DISALLOW_FILE_MODS', true );
	wpai108_ms_check( ! $store->allowed( 'plugin' ), 'DISALLOW_FILE_MODS must deny even a multisite Super Admin.' );
} finally {
	wp_set_current_user( $super_admin );
	if ( $secondary_open ) {
		if ( null !== $second_original ) {
			update_option( Settings::OPTION_NAME, $second_original, false );
		}
		restore_current_blog();
	}
	delete_option( Private_Package_Store::OPTION_PREFIX . $id );
	update_option( Settings::OPTION_NAME, $original, false );
	if ( $test_user > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $test_user );
	}
}
echo "PASS: Issue #108 real multisite blog/client principal and native policy isolation.\n";
