<?php
/**
 * Actual multisite native write consent, principal/site/network/source binding.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Option_Access;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-native-option-write-helper.php';

function wpai199nwms_form_denial( Settings_Page $page, array $post ) {
	$old_post = $_POST;
	$old_request = $_REQUEST;
	$old_method = $_SERVER['REQUEST_METHOD'] ?? null;
	$codes = array();
	$handler = static function () use ( &$codes ) {
		return static function ( $message, $title = '', $args = array() ) use ( &$codes ) {
			$codes[] = is_array( $args ) ? ( $args['response'] ?? 500 ) : 500;
			throw new RuntimeException( 'native-write-form-denied' );
		};
	};
	add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	$_POST = $post;
	$_REQUEST = $post;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try { $page->handle_native_network_write(); } catch ( RuntimeException $error ) {
		wpai199n_assert( 'native-write-form-denied' === $error->getMessage(), 'Unexpected network form exception.' );
	} finally {
		remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
		$_POST = $old_post;
		$_REQUEST = $old_request;
		if ( null === $old_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $old_method; }
	}
	wpai199n_assert( in_array( 403, $codes, true ), 'Network write form did not reject invalid native authority/nonce/network.' );
}

global $wpdb, $current_site;
$actor = get_current_user_id();
$blog = get_current_blog_id();
$network = get_current_network_id();
$original_network_context = $current_site;
$ordinary = get_option( Settings::OPTION_NAME, null );
$log = get_option( Mutation_Log::OPTION_NAME, null );
$read_grant = get_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, null );
$write_grant = get_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, null );
$journals = wpai199nw_journals_before();
$suffix = bin2hex( random_bytes( 5 ) );
$name = 'native199nwms_' . $suffix;
$secondary = 0;
$second_network = 0;
$second_blog = 0;
$local_admin = 0;
$switched = false;
$input_id = '';
$interleave = null;
$deny = null;

try {
	wpai199n_assert( is_multisite() && is_super_admin() && current_user_can( 'manage_network_options' ), 'Use disposable actual multisite Super Admin.' );
	$settings = new Settings();
	$settings->register();
	$access = new Native_Option_Access( new WP_AI_Bridge\Support\Permissions( $settings ) );
	wpai199n_assert( ! isset( $settings->defaults()[ Settings::GROUP_NATIVE_NETWORK_WRITE ] ) && false !== has_filter( 'pre_add_site_option_' . Native_Option_Access::NETWORK_WRITE_OPTION_NAME ) && false !== has_filter( 'pre_update_site_option_' . Native_Option_Access::NETWORK_WRITE_OPTION_NAME ), 'Network write consent entered ordinary flags or lacked native bootstrap guards.' );
	update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 1 );
	update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 0 );
	wpai199nw_grants();
	$request = wpai199nw_request( 'add', $name, array( 'network' => $network, 'bytes' => "native\0سلام" ), 'network' );
	wpai199n_error( wpai199nw_call( $request ), 'Site consent/read network flag implicitly enabled network writing.' );
	$forged = $settings->all();
	$forged[ Settings::GROUP_NATIVE_NETWORK_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $settings->sanitize( $forged ), false );
	wpai199n_assert( ! $access->allowed_write( 'network' ), 'Ordinary native save forged network-owned write consent.' );
	update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 1 );
	update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 0 );
	wpai199n_error( wpai199nw_call( $request ), 'Network write consent bypassed separate sensitive network read.' );
	update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 1 );
	foreach ( array( true, false, 'true', 1.0, array( 1 ), 0, '0', 1, '1' ) as $flag ) {
		$filtered_flag = static function () use ( $flag ) { return $flag; };
		add_filter( 'site_option_' . Native_Option_Access::NETWORK_WRITE_OPTION_NAME, $filtered_flag, 10, 0 );
		try { wpai199n_assert( in_array( $flag, array( 1, '1' ), true ) === $access->allowed_write( 'network' ), 'Network write consent accepted a noncanonical native truthy value.' ); }
		finally { remove_filter( 'site_option_' . Native_Option_Access::NETWORK_WRITE_OPTION_NAME, $filtered_flag, 10 ); }
	}
	$written = wpai199nw_returned( wpai199nw_call( $request ), true );
	wpai199n_same( array( 'network' => $network, 'bytes' => "native\0سلام" ), get_network_option( $network, $name ), 'Native network add changed valid bytes/types.' );
	// Exact duplicate network keys are legal physical storage. Preserve Core's
	// native update/delete behavior rather than imposing exclusive-row CAS.
	$duplicate_name = $name . '_duplicate';
	wpai199n_assert( true === add_network_option( $network, $duplicate_name, 'duplicate initial' ), 'Cannot initialize exact native network duplicate fixture.' );
	wpai199n_assert( 1 === $wpdb->insert( $wpdb->sitemeta, array( 'site_id' => $network, 'meta_key' => $duplicate_name, 'meta_value' => 'duplicate second' ), array( '%d', '%s', '%s' ) ), 'Cannot create legal exact duplicate native network row.' );
	wpai199nw_returned( wpai199nw_call( wpai199nw_request( 'update', $duplicate_name, 'native duplicates updated', 'network' ) ), true );
	$duplicates = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE site_id=%d AND BINARY meta_key=BINARY %s ORDER BY meta_id', $wpdb->sitemeta, $network, $duplicate_name ) );
	wpai199n_assert( array( 'native duplicates updated', 'native duplicates updated' ) === $duplicates, 'Native network duplicate update was blocked or changed Core semantics.' );
	wpai199nw_returned( wpai199nw_call( wpai199nw_request( 'delete', $duplicate_name, null, 'network' ) ), true );
	wpai199n_assert( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE site_id=%d AND BINARY meta_key=BINARY %s', $wpdb->sitemeta, $network, $duplicate_name ) ), 'Native network duplicate deletion left physical records.' );
	$source = wpai199nw_stage( wpai199nw_source( 'network bound input' )['value_json'], 'network' );
	$input_id = $source['transfer_id'];

	$secondary = wp_insert_site( array( 'domain' => 'native-write-' . $suffix . '.invalid', 'path' => '/', 'network_id' => $network, 'title' => 'Native write second site' ) );
	wpai199n_assert( is_int( $secondary ) && $secondary > 0, 'Cannot create disposable native write second blog.' );
	switch_to_blog( $secondary );
	$switched = true;
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	wpai199n_error( wpai199nw_call( wpai199nw_request( 'add', $name, 'different site' ) ), 'Main blog write grants leaked into another blog.' );
	wpai199nw_grants();
	wpai199nw_returned( wpai199nw_call( wpai199nw_request( 'add', $name, 'different site' ) ), true );
	$other_site_status = wpai199n_ok( wpai199nw_status( $request['operation_id'], 'network' ), 'Wrong-blog status did not return a bounded result.' );
	wpai199n_assert( 'not_recorded' === $other_site_status['state'] && null === $other_site_status['receipt'], 'Native journal crossed original blog binding.' );
	wpai199n_error( wpai199nw_call( array( 'action' => 'input_status', 'scope' => 'network', 'transfer_id' => $input_id ) ), 'Native input crossed original blog binding.' );
	$local_admin = wp_insert_user( array( 'user_login' => 'nwms_' . $suffix, 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'nwms-' . $suffix . '@example.invalid' ) );
	wpai199n_assert( is_int( $local_admin ) && $local_admin > 0 && ! is_wp_error( add_user_to_blog( $secondary, $local_admin, 'administrator' ) ), 'Cannot create actual local native administrator.' );
	wp_set_current_user( $local_admin );
	wpai199n_assert( current_user_can( 'manage_options' ) && ! is_super_admin(), 'Local native administrator fixture has wrong authority.' );
	wpai199nw_returned( wpai199nw_call( wpai199nw_request( 'update', $name, 'local site authorized' ) ), true );
	wpai199n_error( wpai199nw_call( wpai199nw_request( 'update', $name, 'must not affect network', 'network' ) ), 'Local administrator inherited network mutation authority.' );
	wpai199n_protected_denial( static function () use ( $network ) { update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 0 ); } );
	$page = new Settings_Page( new Environment(), $settings );
	wpai199nwms_form_denial( $page, array( 'network_id' => (string) $network, 'enabled' => '1', '_wpnonce' => wp_create_nonce( 'wpai-native-network-write-' . $network ) ) );
	$interleave = static function ( $value ) use ( $actor, $local_admin, $network ) {
		wpai199n_assert( ! array_key_exists( Settings::GROUP_NATIVE_NETWORK_READ, $value ) && ! array_key_exists( Settings::GROUP_NATIVE_NETWORK_WRITE, $value ), 'Stale ordinary save carried network consent fields.' );
		wp_set_current_user( $actor );
		try { update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 0 ); } finally { wp_set_current_user( $local_admin ); }
		return $value;
	};
	add_filter( 'pre_update_option_' . Settings::OPTION_NAME, $interleave );
	update_option( Settings::OPTION_NAME, array( Settings::GROUP_NATIVE_OPTIONS_READ => 1, Settings::GROUP_NATIVE_OPTIONS_WRITE => 1, Settings::GROUP_SITE_CONFIG => 1, Settings::GROUP_NATIVE_NETWORK_READ => 0, Settings::GROUP_NATIVE_NETWORK_WRITE => 1 ), false );
	remove_filter( 'pre_update_option_' . Settings::OPTION_NAME, $interleave );
	$interleave = null;
	wpai199n_assert( in_array( get_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME ), array( 0, '0' ), true ) && Native_Option_Access::network_enabled(), 'Stale ordinary site save rewrote network write/read authority.' );
	wp_set_current_user( $actor );
	delete_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME );
	wp_set_current_user( $local_admin );
	wpai199n_protected_denial( static function () use ( $network ) { add_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 1 ); } );
	wp_set_current_user( $actor );
	update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 1 );
	$deny = static function ( $caps, $cap ) { return 'manage_network_options' === $cap ? array( 'do_not_allow' ) : $caps; };
	add_filter( 'map_meta_cap', $deny, 999, 2 );
	wpai199n_error( wpai199nw_call( wpai199nw_request( 'update', $name, 'denied native capability', 'network' ) ), 'Super Admin bypassed explicit native network denial.' );
	remove_filter( 'map_meta_cap', $deny, 999 );
	$deny = null;
	wpai199nwms_form_denial( $page, array( 'network_id' => (string) $network, 'enabled' => '1', '_wpnonce' => 'invalid' ) );
	wpai199nwms_form_denial( $page, array( 'network_id' => (string) ( $network + 999 ), 'enabled' => '1', '_wpnonce' => wp_create_nonce( 'wpai-native-network-write-' . $network ) ) );
	restore_current_blog();
	$switched = false;
	update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 0 );
	wpai199nw_receipt( wpai199nw_status( $request['operation_id'], 'network' ) );
	wpai199n_error( wpai199nw_call( array( 'action' => 'input_status', 'scope' => 'network', 'transfer_id' => $input_id ) ), 'Write-revoked current authority exposed native input.' );
	update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 1 );

	wpai199n_assert( 1 === $wpdb->insert( $wpdb->site, array( 'domain' => 'native-network-' . $suffix . '.invalid', 'path' => '/' ), array( '%s', '%s' ) ), 'Cannot create disposable second-network identity.' );
	$second_network = (int) $wpdb->insert_id;
	clean_network_cache( $second_network );
	$modern_default = static function ( $default, $option, $id ) use ( $second_network ) { return $second_network === $id ? false : $default; };
	add_filter( 'default_site_option_ms_files_rewriting', $modern_default, PHP_INT_MAX, 3 );
	try {
		foreach ( array( 'ms_files_rewriting' => 0, 'siteurl' => 'https://native-network-' . $suffix . '.invalid/', 'site_name' => 'Native write network', 'site_admins' => array( wp_get_current_user()->user_login ), 'admin_email' => wp_get_current_user()->user_email ) as $key => $value ) {
			wpai199n_assert( true === update_network_option( $second_network, $key, $value ), 'Cannot initialize genuine second-network metadata.' );
		}
	} finally { remove_filter( 'default_site_option_ms_files_rewriting', $modern_default, PHP_INT_MAX ); }
	$second_blog = wp_insert_site( array( 'domain' => 'native-network-' . $suffix . '.invalid', 'path' => '/', 'network_id' => $second_network, 'title' => 'Native write second network' ) );
	wpai199n_assert( is_int( $second_blog ) && $second_blog > 0, 'Cannot create disposable second-network site.' );
	switch_to_blog( $second_blog );
	$switched = true;
	$current_site = get_network( $second_network );
	wpai199nw_grants();
	update_network_option( $second_network, Native_Option_Access::NETWORK_OPTION_NAME, 1 );
	update_network_option( $second_network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 1 );
	wpai199n_assert( $access->allowed_write( 'network' ), 'Second network lacks its own genuine native read/write consents.' );
	wpai199n_error( wpai199nw_call( array( 'action' => 'input_status', 'scope' => 'network', 'transfer_id' => $input_id ) ), 'Another site/network grant reopened native input.' );
	restore_current_blog();
	$switched = false;
	wpai199n_assert( $blog === get_current_blog_id() && $second_network === get_current_network_id() && $access->allowed_write( 'network' ), 'Network-only identity probe lacks valid independent current authority.' );
	$other_network_status = wpai199n_ok( wpai199nw_status( $request['operation_id'], 'network' ), 'Cross-network status failed its bounded response.' );
	wpai199n_assert( 'not_recorded' === $other_network_status['state'] && null === $other_network_status['receipt'], 'Another current network exposed original private native receipt/name.' );
	wpai199n_error( wpai199nw_call( array( 'action' => 'input_status', 'scope' => 'network', 'transfer_id' => $input_id ) ), 'Another current network reopened original-blog native input.' );
	wpai199n_protected_denial( static function () use ( $network ) { update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 0 ); } );
	$current_site = $original_network_context;
	wpai199n_ok( wpai199nw_call( array( 'action' => 'input_release', 'scope' => 'network', 'transfer_id' => $input_id ) ), 'Original network cannot release its own native input.' );
	$input_id = '';
	wpai199n_same( array( 'network' => $network, 'bytes' => "native\0سلام" ), get_network_option( $network, $name ), 'Cross-network denials altered original network value.' );
	echo 'PASS: native write multisite grants, real authority and exact network/site bindings (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
} finally {
	wp_set_current_user( $actor );
	if ( null !== $deny ) { remove_filter( 'map_meta_cap', $deny, 999 ); }
	if ( null !== $interleave ) { remove_filter( 'pre_update_option_' . Settings::OPTION_NAME, $interleave ); }
	if ( $switched ) { restore_current_blog(); }
	$current_site = $original_network_context;
	wpai199nw_grants();
	update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 1 );
	update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 1 );
	if ( '' !== $input_id ) { wpai199nw_call( array( 'action' => 'input_release', 'scope' => 'network', 'transfer_id' => $input_id ) ); }
	delete_network_option( $network, $name );
	delete_network_option( $network, $name . '_duplicate' );
	if ( $second_blog > 0 ) { wp_delete_site( $second_blog ); }
	if ( $second_network > 0 ) {
		$wpdb->delete( $wpdb->sitemeta, array( 'site_id' => $second_network ), array( '%d' ) );
		$wpdb->delete( $wpdb->site, array( 'id' => $second_network ), array( '%d' ) );
		clean_network_cache( $second_network );
	}
	if ( $secondary > 0 ) { wp_delete_site( $secondary ); }
	if ( $local_admin > 0 ) { require_once ABSPATH . 'wp-admin/includes/ms.php'; wpmu_delete_user( $local_admin ); }
	wpai199nw_cleanup_journals( $journals );
	foreach ( array( Native_Option_Access::NETWORK_OPTION_NAME => $read_grant, Native_Option_Access::NETWORK_WRITE_OPTION_NAME => $write_grant ) as $key => $value ) {
		if ( null === $value ) { delete_network_option( $network, $key ); } else { update_network_option( $network, $key, (int) $value ); }
	}
	wpai199n_restore_option( Settings::OPTION_NAME, $ordinary, null !== $ordinary );
	wpai199n_restore_option( Mutation_Log::OPTION_NAME, $log, null !== $log );
}
