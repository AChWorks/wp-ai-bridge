<?php
/**
 * Disposable two-site WordPress #134 private temporary root isolation.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Safe_Temporary_Staging;
use WP_AI_Bridge\Support\Private_Transfer_Session;

function wpai134_ms_check( $ok, $reason ) {
	if ( ! $ok ) {
		throw new RuntimeException( $reason );
	}
}
wpai134_ms_check( is_multisite() && is_super_admin(), 'Network super administrator and multisite are required.' );
wpai134_ms_check(
	defined( 'WP_TEMP_DIR' ) && realpath( WP_TEMP_DIR ) === realpath( WP_CONTENT_DIR ),
	'Served WordPress temp fixture required.'
);
$root = Safe_Temporary_Staging::directory();
wpai134_ms_check( is_string( $root ) && 0 !== strpos( $root . '/', rtrim( realpath( ABSPATH ), '/' ) . '/' ),
	'No off-webroot private scratch.' );
$blogs = get_sites( array( 'fields' => 'ids', 'number' => 4 ) );
wpai134_ms_check( count( $blogs ) >= 2 && (int) $blogs[0] !== (int) $blogs[1], 'Expected separate blogs.' );
$settings = new Settings();
$leases = array();
$previous = array();
$bytes = "WPAI134 synthetically private \x00\xff سلام";
try {
	foreach ( array( (int) $blogs[0], (int) $blogs[1] ) as $blog ) {
		switch_to_blog( $blog );
		$previous[$blog] = get_option( Settings::OPTION_NAME, false );
		$grants = $settings->defaults();
		$grants[Settings::GROUP_BUILDER_WRITE] = 1;
		update_option( Settings::OPTION_NAME, $grants, false );
		$store = new Private_Transfer_Session( new Permissions( $settings ) );
		$receipt = $store->begin( 'media', strlen($bytes), hash('sha256', $bytes), 2048, 1024, 2, 600 );
		wpai134_ms_check( !is_wp_error($receipt) && isset($receipt['id']), 'Blog-scoped begin failed.' );
		$leases[$blog] = $receipt['id'];
		$append = $store->append($receipt['id'], 0, $bytes);
		$verify = $store->verify($receipt['id']);
		wpai134_ms_check( !is_wp_error($append) && !is_wp_error($verify) && $verify['state']==='verified', 'Blog digest failed.' );
		restore_current_blog();
	}
	wpai134_ms_check( $leases[(int)$blogs[0]] !== $leases[(int)$blogs[1]], 'Shared opaque IDs.' );
	foreach ( array( (int)$blogs[0], (int)$blogs[1] ) as $blog ) {
		$other = $blog === (int)$blogs[0] ? (int)$blogs[1] : (int)$blogs[0];
		switch_to_blog($blog);
		$store = new Private_Transfer_Session(new Permissions($settings));
		wpai134_ms_check( is_wp_error($store->status($leases[$other])) &&
			is_wp_error($store->cancel($leases[$other])), 'Cross-blog lease accessible.' );
		$source = $store->verified_private_source($leases[$blog], 'media');
		wpai134_ms_check( !is_wp_error($source) &&
			hash_equals(hash('sha256',$bytes), hash_file('sha256',$source['path'])) &&
			0600 === (fileperms($source['path']) & 0777), 'Wrong blog digest or permission.' );
		restore_current_blog();
	}
	echo 'PASS: #134 two-site private staging blog isolation, binary digest, protected files, denied cross-blog access.' . PHP_EOL;
} finally {
	foreach (array((int)$blogs[0],(int)$blogs[1]) as $blog) {
		switch_to_blog($blog);
		if(isset($leases[$blog])) {
			(new Private_Transfer_Session(new Permissions($settings)))->cancel($leases[$blog]);
		}
		if(array_key_exists($blog,$previous)) {
			if(false === $previous[$blog]) delete_option(Settings::OPTION_NAME);
			else update_option(Settings::OPTION_NAME,$previous[$blog],false);
		}
		restore_current_blog();
	}
}
