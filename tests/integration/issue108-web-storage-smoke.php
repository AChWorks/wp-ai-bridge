<?php
/**
 * N1: real web ZIPs must survive a rejected separate- /tmp CLI deactivation,
 * and be retired by deactivation from the verified shared storage domain.
 *
 * @package WP_AI_Bridge
 */

require_once __DIR__ . '/../../src/Support/class-private-package-storage.php';

use WP_AI_Bridge\Support\Private_Package_Storage;
use WP_AI_Bridge\Support\Private_Package_Store;

$mode = getenv( 'WPAI108_WEB_STORAGE_MODE' );
if ( ! in_array( $mode, array( 'staged', 'retired' ), true ) ) {
	throw new RuntimeException( 'Invalid isolated web storage-domain verification mode.' );
}
global $wpdb;
$names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( Private_Package_Store::OPTION_PREFIX ) . '%'
	)
);
if ( ! is_array( $names ) ) {
	throw new RuntimeException( 'Could not inspect private artifact option identities.' );
}
$found     = array(
	'wpai108-browser-plugin' => false,
	'wpai108-browser-theme'  => false,
);
$site_root = rtrim( (string) realpath( sys_get_temp_dir() ), '/\\' ) . '/wpai-private-zip-' .
	substr( hash( 'sha256', (string) realpath( ABSPATH ) . '|' . get_current_blog_id() ), 0, 24 );
foreach ( $names as $name ) {
	$meta = get_option( $name, false );
	$root = is_array( $meta ) ? ( $meta['root'] ?? '' ) : '';
	if ( ! array_key_exists( $root, $found ) ) {
		continue;
	}
	$found[ $root ] = true;
	$zip            = $site_root . '/' . $meta['id'] . '.zip';
	if ( 'staged' === $mode ) {
		if ( 'staged' !== $meta['status'] || ! is_file( $zip ) ||
			! hash_equals( $meta['sha256'], (string) hash_file( 'sha256', $zip ) ) ) {
			throw new RuntimeException( 'Original web ZIP bytes or metadata changed during cross-domain CLI lifecycle denial.' );
		}
	} else {
		throw new RuntimeException( 'Web ZIP metadata survived confirmed same-domain lifecycle cleanup.' );
	}
}
if ( 'staged' === $mode ) {
	$resolved = Private_Package_Storage::existing_directory();
	if ( is_wp_error( $resolved ) || $resolved !== $site_root ||
		in_array( false, $found, true ) || ! wp_next_scheduled( 'wpai_private_zip_cleanup' ) ) {
		throw new RuntimeException( 'Cannot prove authentic web multipart staging and durable private storage binding.' );
	}
} elseif ( false !== get_option( Private_Package_Storage::DOMAIN_OPTION, false ) ||
	is_dir( $site_root ) || wp_next_scheduled( 'wpai_private_zip_cleanup' ) ) {
	throw new RuntimeException( 'Completed same-domain deactivation retained executable ZIP domain or cron.' );
}
echo 'PASS: Issue #108 N1 real web staged ZIPs ' . $mode . ' with durable private storage identity.' . "\n";
