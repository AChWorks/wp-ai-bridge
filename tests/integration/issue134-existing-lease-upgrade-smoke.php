<?php
/**
 * Two invocation, disposable v0.6.5 filesystem lease continuity probe.
 *
 * First invocation: create an incomplete native FS lease on a safe WordPress
 * default temp. Second invocation (after a candidate ZIP upgrade and changing
 * WP_TEMP_DIR to the served wp-content path): finish/verify/cancel same lease.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Transfer_Session;

function wpai134_upgrade_assert( $valid, $reason ) {
	if ( ! $valid ) {
		throw new RuntimeException( $reason );
	}
}

wpai134_upgrade_assert( current_user_can( 'upload_files' ), 'Fixture requires an authenticated WordPress administrator.' );
$settings = new Settings();
$store = new Private_Transfer_Session( new Permissions( $settings ) );
$before = get_option( Settings::OPTION_NAME, false );
$grant = $settings->defaults();
$grant[ Settings::GROUP_BUILDER_WRITE ] = 1;
update_option( Settings::OPTION_NAME, $grant, false );
$key = 'wpai134_upgrade_test_lease';
$receipt = get_option( $key, false );
$bytes = str_repeat( "WPAI134 synthetic v0.6.5 continuity \x00\xff\nسلام", 4300 );
$first = 8192;
try {
	if ( false === $receipt ) {
		wpai134_upgrade_assert(
			realpath( get_temp_dir() ) === realpath( sys_get_temp_dir() ),
			'Create phase requires a safe pre-upgrade WordPress temp directory.'
		);
		$opened = $store->begin( 'media', strlen( $bytes ), hash( 'sha256', $bytes ), strlen( $bytes ) + 1, $first, 3, 600 );
		wpai134_upgrade_assert( ! is_wp_error( $opened ) && isset( $opened['id'] ), 'Could not allocate pre-upgrade private lease.' );
		$written = $store->append( $opened['id'], 0, substr( $bytes, 0, $first ) );
		wpai134_upgrade_assert( ! is_wp_error( $written ) && $first === $written['received'], 'Could not persist incomplete pre-upgrade payload.' );
		update_option(
			$key,
			array( 'id' => $opened['id'], 'actor' => (int) get_current_user_id(), 'saved_settings' => $before ),
			false
		);
		echo 'PASS: #134 pre-upgrade native FS lease created and partially received.' . PHP_EOL;
	} else {
		wpai134_upgrade_assert(
			is_array( $receipt ) && isset( $receipt['id'], $receipt['actor'] ) &&
			(int) $receipt['actor'] === (int) get_current_user_id() &&
			realpath( get_temp_dir() ) === realpath( WP_CONTENT_DIR ),
			'Resume phase requires same principal and deliberately served WordPress temp.'
		);
		$id = $receipt['id'];
		$status = $store->status( $id );
		wpai134_upgrade_assert( ! is_wp_error( $status ) && $first === $status['received'], 'Existing lease was not recovered at its original private root.' );
		$offset = $first;
		while ( $offset < strlen( $bytes ) ) {
			$part = substr( $bytes, $offset, min( $first, strlen( $bytes ) - $offset ) );
			$next = $store->append( $id, $offset, $part );
			wpai134_upgrade_assert( ! is_wp_error( $next ), 'Original lease append failed after upgrade.' );
			$offset += strlen( $part );
		}
		$verified = $store->verify( $id );
		$source = $store->verified_private_source( $id, 'media' );
		wpai134_upgrade_assert(
			! is_wp_error( $verified ) && ! is_wp_error( $source ) &&
			'verified' === $verified['state'] &&
			hash_equals( hash( 'sha256', $bytes ), (string) hash_file( 'sha256', $source['path'] ) ) &&
			0600 === ( fileperms( $source['path'] ) & 0777 ) &&
			0 !== strpos( realpath( $source['path'] ) . '/', rtrim( realpath( ABSPATH ), '/' ) . '/' ),
			'Old lease lost private bytes, digest or permissions after temp change.'
		);
		wpai134_upgrade_assert( true === $store->cancel( $id ), 'Existing lease cleanup failed after upgrade.' );
		delete_option( $key );
		echo 'PASS: #134 old FS lease resumed after hostile WordPress temp change; SHA-256, permission and cleanup parity.' . PHP_EOL;
	}
} finally {
	$restore = is_array( $receipt ) ? ( $receipt['saved_settings'] ?? false ) : $before;
	if ( false === $restore ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $restore, false );
	}
}
