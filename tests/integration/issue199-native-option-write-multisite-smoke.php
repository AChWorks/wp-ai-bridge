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
$site_inputs = array();
$site_target = $name . '_site_stage';
$site_target_before = get_option( $site_target, null );
$site_target_hook = null;
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

	// SITE-purpose inputs also retain the original network. Prepare both a
	// verified value and fully uploaded/open input before changing only network.
	$site_value = array( 'original_network' => $network, 'bytes' => "site\0سلام" );
	$site_document = wpai199nw_source( $site_value )['value_json'];
	$site_verified = wpai199nw_stage( $site_document, 'site' );
	$site_inputs[] = $site_verified['transfer_id'];
	$pending_document = wpai199nw_source( array( 'pending' => true, 'bytes' => "pending\0سلام" ) )['value_json'];
	$site_open = wpai199n_ok( wpai199nw_call( array( 'action' => 'input_begin', 'scope' => 'site', 'total_bytes' => strlen( $pending_document ), 'sha256' => hash( 'sha256', $pending_document ) ) ), 'Cannot prepare original-network open site input.' );
	$site_inputs[] = $site_open['transfer_id'];
	$pending_append = array( 'action' => 'input_append', 'scope' => 'site', 'transfer_id' => $site_open['transfer_id'], 'offset' => 0, 'base64' => base64_encode( $pending_document ) );
	$uploaded_open = wpai199n_ok( wpai199nw_call( $pending_append ), 'Cannot upload complete unverified site input.' );
	wpai199n_assert( 'open' === $uploaded_open['state'] && strlen( $pending_document ) === $uploaded_open['received_bytes'], 'Site input must permit identical append retry and successful verification before the network probe.' );
	$site_client = WP_AI_Bridge\Auth\OAuth_Server::authenticated_mcp_client_id();
	$site_target_calls = 0;
	$site_target_hook = static function ( $value ) use ( &$site_target_calls ) { ++$site_target_calls; return $value; };
	add_filter( 'pre_update_option_' . $site_target, $site_target_hook );

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
	wpai199n_assert( $actor === get_current_user_id() && $site_client === WP_AI_Bridge\Auth\OAuth_Server::authenticated_mcp_client_id() && $access->allowed_write( 'site' ), 'Site-input network-only probe changed owner/client or lacks independent valid site authority.' );
	$site_authorization_errors = array( 'transfer_denied', 'native_option_input_denied' );
	wpai199n_error( wpai199nw_call( array( 'action' => 'input_status', 'scope' => 'site', 'transfer_id' => $site_verified['transfer_id'] ) ), 'SITE verified input status crossed original network with unchanged blog/user/client.', $site_authorization_errors );
	$wrong_site_consume = wpai199nw_request( 'update', $site_target, '' );
	$wrong_site_consume['value_source'] = $site_verified;
	wpai199n_error( wpai199nw_call( $wrong_site_consume ), 'SITE verified input consumption crossed original network.', $site_authorization_errors );
	wpai199n_assert( 0 === $site_target_calls && $site_target_before === get_option( $site_target, null ), 'Cross-network site input consumption reached native target callbacks/effects.' );
	wpai199n_error( wpai199nw_call( array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => $site_verified['transfer_id'] ) ), 'SITE verified input release crossed original network.', $site_authorization_errors );
	wpai199n_error( wpai199nw_call( $pending_append ), 'SITE open input append crossed original network.', $site_authorization_errors );
	wpai199n_error( wpai199nw_call( array( 'action' => 'input_verify', 'scope' => 'site', 'transfer_id' => $site_open['transfer_id'] ) ), 'SITE complete open input verification crossed original network.', $site_authorization_errors );
	$other_network_status = wpai199n_ok( wpai199nw_status( $request['operation_id'], 'network' ), 'Cross-network status failed its bounded response.' );
	wpai199n_assert( 'not_recorded' === $other_network_status['state'] && null === $other_network_status['receipt'], 'Another current network exposed original private native receipt/name.' );
	wpai199n_error( wpai199nw_call( array( 'action' => 'input_status', 'scope' => 'network', 'transfer_id' => $input_id ) ), 'Another current network reopened original-blog native input.' );
	wpai199n_protected_denial( static function () use ( $network ) { update_network_option( $network, Native_Option_Access::NETWORK_WRITE_OPTION_NAME, 0 ); } );
	$current_site = $original_network_context;
	// Activity permits sanitized stdClass diagnostics. A fresh native read
	// recreates object identities, so whole-log snapshots compare exact bytes.
	$roundtrip_details = WP_AI_Bridge\Support\Error_Details::from_wp_error( new WP_Error( 'native_fixture_roundtrip', 'Inert diagnostic', array( 'observation' => (object) array( 'state' => 'inert' ) ) ) );
	$roundtrip_ref = ( new Mutation_Log() )->record_mcp_failure( 'fixture/native-audit-roundtrip', $roundtrip_details, 'native_ability', 'native_result', 'inspect_native_result', 'native199-audit-roundtrip-fixture' );
	wpai199n_assert( '' !== $roundtrip_ref, 'Cannot persist the sanitized nested-object Activity fixture.' );
	$roundtrip_log = get_option( Mutation_Log::OPTION_NAME, array() );
	wp_cache_delete( Mutation_Log::OPTION_NAME, 'options' );
	$fresh_roundtrip_log = get_option( Mutation_Log::OPTION_NAME, array() );
	wpai199n_assert( $roundtrip_ref === ( $fresh_roundtrip_log[0]['event_ref'] ?? '' ) && ( $fresh_roundtrip_log[0]['error_data']['observation'] ?? null ) instanceof stdClass && $roundtrip_log[0]['error_data']['observation'] !== $fresh_roundtrip_log[0]['error_data']['observation'] && maybe_serialize( $roundtrip_log ) === maybe_serialize( $fresh_roundtrip_log ), 'Fresh Activity read did not preserve exact content across a nested-object roundtrip.' );

	// The outer audit can change only network after a successful SITE begin.
	// Its originating facade must still erase just its own new allocation.
	$begin_store = WP_AI_Bridge\Support\Private_Transfer_Session::for_native_options_write( new WP_AI_Bridge\Support\Permissions( $settings ), 'site' );
	$begin_root = ( new ReflectionMethod( $begin_store, 'root' ) )->invoke( $begin_store );
	wpai199n_assert( is_string( $begin_root ), 'Cannot inspect the exact site-input private root for network-drift cleanup.' );
	$begin_files = glob( $begin_root . '/*' );
	$begin_audit = get_option( Mutation_Log::OPTION_NAME, array() );
	$begin_network_context = $current_site;
	$authorized_other_context = get_network( $second_network );
	$allocated_during_audit = array();
	$begin_network_drift = static function ( $value ) use ( &$current_site, $authorized_other_context, $begin_root, $begin_files, &$allocated_during_audit ) {
		$allocated_during_audit = array_values( array_diff( glob( $begin_root . '/*.meta' ), $begin_files ) );
		$current_site = $authorized_other_context;
		return $value;
	};
	add_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $begin_network_drift, PHP_INT_MAX );
	try {
		$denied_begin = wpai199nw_call( array( 'action' => 'input_begin', 'scope' => 'site', 'total_bytes' => 137, 'sha256' => hash( 'sha256', 'network drift inert input' ) ) );
		wpai199n_assert( 1 === count( $allocated_during_audit ), 'Network-drift cleanup case did not observe a successful SITE allocation before the audit hook.' );
		wpai199n_assert( $second_network === get_current_network_id() && $blog === get_current_blog_id() && $actor === get_current_user_id() && $site_client === WP_AI_Bridge\Auth\OAuth_Server::authenticated_mcp_client_id() && $access->allowed_write( 'site' ), 'Input-begin audit drift changed more than network or lost independently valid site authority.' );
		wpai199n_error( $denied_begin, 'Outer SITE input begin disclosed an allocation after audit network drift.' );
		wpai199nw_public_private( $denied_begin, array( basename( $allocated_during_audit[0], '.meta' ) ) );
		wpai199n_assert( ! array_diff( glob( $begin_root . '/*' ), $begin_files ), 'Original-network creation proof failed to erase its new SITE lease/reservation after network drift.' );
		wpai199n_assert( ! array_diff( $begin_files, glob( $begin_root . '/*' ) ), 'Network-drift begin cleanup erased an earlier retained input.' );
	} finally {
		remove_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $begin_network_drift, PHP_INT_MAX );
		$current_site = $begin_network_context;
		// Negative reproduction cleanup, confined to exact new coordinators.
		foreach ( array_diff( glob( $begin_root . '/*.meta' ), $begin_files ) as $orphan ) {
			if ( 1 === preg_match( '/^([a-f0-9]{64})\.meta$/D', basename( $orphan ), $match ) ) { wpai199nw_call( array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => $match[1] ) ); }
		}
		wpai199n_restore_option( Mutation_Log::OPTION_NAME, $begin_audit );
		wp_cache_delete( Mutation_Log::OPTION_NAME, 'options' );
		wpai199n_assert( $current_site === $begin_network_context && maybe_serialize( $begin_audit ) === maybe_serialize( get_option( Mutation_Log::OPTION_NAME ) ), 'Network-drift begin fixture did not restore exact current network/audit state.' );
	}
	$site_facade = new WP_AI_Bridge\Support\Native_Option_Inputs( new WP_AI_Bridge\Support\Permissions( $settings ), 'site' );
	$retained_verified = wpai199n_ok( $site_facade->status( $site_verified['transfer_id'] ), 'Network-drift cleanup lost the prior verified SITE input.' );
	$retained_open = wpai199n_ok( $site_facade->status( $site_open['transfer_id'] ), 'Network-drift cleanup lost the prior open SITE input.' );
	$retained_network = wpai199n_ok( ( new WP_AI_Bridge\Support\Native_Option_Inputs( new WP_AI_Bridge\Support\Permissions( $settings ), 'network' ) )->status( $input_id ), 'Network-drift cleanup lost the prior NETWORK input.' );
	wpai199n_assert( 'verified' === $retained_verified['state'] && $site_verified['sha256'] === $retained_verified['sha256'] && 'open' === $retained_open['state'] && strlen( $pending_document ) === $retained_open['received_bytes'] && 'verified' === $retained_network['state'] && $source['sha256'] === $retained_network['sha256'], 'Network-drift creation cleanup changed earlier SITE/NETWORK input identities or states.' );
	$recovered_site = wpai199n_ok( $site_facade->value( $site_verified['transfer_id'], $site_verified['sha256'], $site_verified['total_bytes'], 1024 * 1024 ), 'Original network could not recover its verified site input bytes.' );
	wpai199n_assert( $site_document === $recovered_site['json'] && $site_verified['sha256'] === $recovered_site['sha256'], 'Cross-network denials changed original site input bytes/hash.' );
	wpai199n_same( $site_value, $recovered_site['value'], 'Original-network site input lost native value types/bytes.' );
	$original_site_consume = wpai199nw_request( 'update', $site_target, '' );
	$original_site_consume['value_source'] = $site_verified;
	wpai199nw_returned( wpai199nw_call( $original_site_consume ), true );
	wpai199n_assert( 1 === $site_target_calls, 'Restored original network did not permit exactly one site input consumption.' );
	wpai199n_same( $site_value, get_option( $site_target ), 'Restored site input consumption changed native value.' );
	$still_open = wpai199n_ok( wpai199nw_call( array( 'action' => 'input_status', 'scope' => 'site', 'transfer_id' => $site_open['transfer_id'] ) ), 'Original network lost its open site input.' );
	wpai199n_assert( 'open' === $still_open['state'] && strlen( $pending_document ) === $still_open['received_bytes'], 'Wrong-network verify/append altered the original open input state.' );
	wpai199n_ok( wpai199nw_call( $pending_append ), 'Original network could not retry its complete site append.' );
	$verified_open = wpai199n_ok( wpai199nw_call( array( 'action' => 'input_verify', 'scope' => 'site', 'transfer_id' => $site_open['transfer_id'] ) ), 'Original network could not verify its complete site input.' );
	$recovered_open = wpai199n_ok( $site_facade->value( $site_open['transfer_id'], $verified_open['sha256'], $verified_open['total_bytes'], 1024 * 1024 ), 'Original network could not materialize continued site input.' );
	wpai199n_assert( $pending_document === $recovered_open['json'], 'Site input continuation changed complete document bytes.' );
	foreach ( $site_inputs as $site_input ) { wpai199n_ok( wpai199nw_call( array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => $site_input ) ), 'Original network cannot release its own site input.' ); }
	$site_inputs = array();
	remove_filter( 'pre_update_option_' . $site_target, $site_target_hook );
	$site_target_hook = null;
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
	if ( null !== $site_target_hook ) { remove_filter( 'pre_update_option_' . $site_target, $site_target_hook ); }
	foreach ( $site_inputs as $site_input ) { wpai199nw_call( array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => $site_input ) ); }
	wpai199n_restore_option( $site_target, $site_target_before, null !== $site_target_before );
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
