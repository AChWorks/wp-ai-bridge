<?php
/**
 * Real multisite native consent, per-blog/network binding and native guards.
 *
 * Synthetic second-network rows exist only in this disposable CI installation.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Admin\Settings_Save;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Option_Access;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-native-options-helper.php';

function wpai199nms_form_denial( Settings_Page $page, array $post ) {
	$before_post = $_POST;
	$before_request = $_REQUEST;
	$before_method = $_SERVER['REQUEST_METHOD'] ?? null;
	$codes = array();
	$handler = static function () use ( &$codes ) {
		return static function ( $message, $title = '', $args = array() ) use ( &$codes ) {
			$codes[] = is_array( $args ) ? ( $args['response'] ?? 500 ) : 500;
			throw new RuntimeException( 'wpai199n-form-denied' );
		};
	};
	add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	$_POST = $post;
	$_REQUEST = $post;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try {
		$page->handle_native_network_read();
	} catch ( RuntimeException $error ) {
		wpai199n_assert( 'wpai199n-form-denied' === $error->getMessage(), 'Unexpected exception in negative network form fixture.' );
	} finally {
		$_POST = $before_post;
		$_REQUEST = $before_request;
		if ( null === $before_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $before_method; }
		remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	}
	wpai199n_assert( in_array( 403, $codes, true ), 'Network form did not reject invalid consent before its redirect/exit.' );
}
function wpai199nms_form( Settings $settings ) {
	$page = new Settings_Page( new Environment(), $settings );
	ob_start();
	try {
		$page->render_settings();
		return ob_get_contents();
	} finally {
		ob_end_clean();
	}
}

/** Inspect the current network's saved consent within the shared Save form. */
function wpai199nms_assert_network_controls( $html, Settings $settings, $editable ) {
	wpai199n_assert( 1 === preg_match( '~<form\\b[^>]*\\bid="wpai-settings-form"[^>]*>(.*?)</form>~s', $html, $form ), 'Network consent must be presented in the unified settings form.' );
	$context = array();
	$controls = array();
	$enabled_named = 0;
	$zero_controls = 0;
	$processor = new WP_HTML_Tag_Processor( $form[1] );
	while ( $processor->next_tag( 'INPUT' ) ) {
		$name = $processor->get_attribute( 'name' );
		if ( in_array( $name, array( 'action', '_wpnonce', 'expected_revision', 'blog_id', 'network_id', 'user_id' ), true ) ) {
			$context[ $name ][] = $processor->get_attribute( 'value' );
		}
		if ( Native_Option_Access::NETWORK_OPTION_NAME === $name && null === $processor->get_attribute( 'disabled' ) ) {
			++$enabled_named;
			if ( 'hidden' === $processor->get_attribute( 'type' ) && '0' === $processor->get_attribute( 'value' ) ) {
				++$zero_controls;
			}
		}
		if ( 'wpai-grant-' . Settings::GROUP_NATIVE_NETWORK_READ === $processor->get_attribute( 'id' ) ) {
			$controls[] = array(
				'name' => $name,
				'type' => $processor->get_attribute( 'type' ),
				'disabled' => null !== $processor->get_attribute( 'disabled' ),
				'checked' => null !== $processor->get_attribute( 'checked' ),
			);
		}
	}
	wpai199n_assert( 1 === count( $controls ) && 'checkbox' === $controls[0]['type'] && Native_Option_Access::network_enabled() === $controls[0]['checked'], 'The current network saved consent was omitted or misrepresented.' );
	wpai199n_assert( ( ! $editable ) === $controls[0]['disabled'], 'Network consent editing did not follow current native authority.' );
	if ( $editable ) {
		wpai199n_assert( Native_Option_Access::NETWORK_OPTION_NAME === $controls[0]['name'] && 2 === $enabled_named && 1 === $zero_controls, 'Authorized network consent must retain its independent native option name and explicit unchecked value.' );
	} else {
		wpai199n_assert( ( null === $controls[0]['name'] || Native_Option_Access::NETWORK_OPTION_NAME === $controls[0]['name'] ) && 0 === $enabled_named, 'Unauthorized network controls exposed an actionable consent value.' );
	}
	$save = new Settings_Save( $settings );
	$snapshot = $save->snapshot();
	foreach ( array( 'action' => Settings_Save::ACTION, 'expected_revision' => $snapshot['revision'], 'blog_id' => (string) get_current_blog_id(), 'network_id' => (string) get_current_network_id(), 'user_id' => (string) get_current_user_id() ) as $field => $expected ) {
		wpai199n_assert( array( $expected ) === ( $context[ $field ] ?? array() ), 'Unified network consent lost its exact current-context field: ' . $field );
	}
	wpai199n_assert( 1 === count( $context['_wpnonce'] ?? array() ) && wp_verify_nonce( $context['_wpnonce'][0], $save->nonce_action() ), 'Unified network consent lost its current-principal/site/network nonce.' );
}

global $wpdb, $current_site;
$actor = get_current_user_id();
$blog = get_current_blog_id();
$network = get_current_network_id();
$original_current_site = $current_site;
$original = get_option( Settings::OPTION_NAME, null );
$original_log = get_option( Mutation_Log::OPTION_NAME, null );
$network_original = get_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, null );
$suffix = bin2hex( random_bytes( 5 ) );
$name = 'wpai199nms_' . $suffix;
$secondary = 0;
$second_network = 0;
$second_network_blog = 0;
$site_admin = 0;
$switched = false;
$transfers = array();
$deny = null;
$interleave = null;
$network_filter = null;
$network_calls = 0;

try {
	wpai199n_assert( is_multisite() && is_super_admin() && current_user_can( 'manage_network_options' ), 'Use only a disposable actual Super Admin multisite fixture.' );
	$settings = new Settings();
	$settings->register();
	$access = new Native_Option_Access( new WP_AI_Bridge\Support\Permissions( $settings ) );
	wpai199n_assert( false !== has_filter( 'pre_add_site_option_' . Native_Option_Access::NETWORK_OPTION_NAME ) && false !== has_filter( 'pre_update_site_option_' . Native_Option_Access::NETWORK_OPTION_NAME ), 'Native network pre-add/update guards were absent at bootstrap.' );
	wpai199n_assert( ! array_key_exists( Settings::GROUP_NATIVE_NETWORK_READ, $settings->defaults() ) && ! array_key_exists( Settings::GROUP_NATIVE_NETWORK_READ, $settings->sanitize( array( Settings::GROUP_NATIVE_NETWORK_READ => 1 ) ) ), 'Network consent entered ordinary site flags/defaults.' );
	update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 0 );
	// Native sitemeta serializes into UTF-8 LONGTEXT; invalid byte sequences
	// are observed through Core's documented pre_site_option filter below.
	$stored_network_value = array( 'network' => $network, 'bytes' => "\x00network سلام 🌍" );
	wpai199n_assert( true === add_network_option( $network, $name, $stored_network_value ), 'Cannot store the synthetic UTF-8/NUL network option.' );
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'network', 'name' => $name ) ), 'Default-off network/site authority was bypassed.' );
	wpai199n_grant_site();
	wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'network', 'name' => $name ) ), 'Site consent alone enabled native network reads.' );
	update_option( Settings::OPTION_NAME, array( Settings::GROUP_NATIVE_OPTIONS_READ => 1, Settings::GROUP_NATIVE_NETWORK_READ => 1 ), false );
	wpai199n_assert( ! Native_Option_Access::network_enabled(), 'Ordinary native Settings save enabled independent network consent.' );
	update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 1 );
	wpai199n_grant_site( false );
	wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'network', 'name' => $name ) ), 'Network consent alone bypassed missing site consent.' );
	wpai199n_grant_site();
	$read = wpai199n_ok( wpai199n_call( array( 'action' => 'read', 'scope' => 'network', 'name' => $name ) ), 'Explicitly granted current-network read failed.' );
	wpai199n_same( get_network_option( $network, $name ), wpai199n_decode( $read['value_json'] ), 'Network read changed native value types/bytes.' );
	wpai199n_assert( $network === $read['network_id'] && $blog === $read['blog_id'] && 'unknown' === $read['storage_presence'], 'Network result invented physical existence or lost context.' );

	foreach ( array( true, 'true', array( 'enabled' => 1 ), 1.0, false, 0, '0', 1, '1' ) as $flag ) {
		$filter = static function () use ( $flag ) { return $flag; };
		add_filter( 'site_option_' . Native_Option_Access::NETWORK_OPTION_NAME, $filter, 10, 0 );
		try {
			wpai199n_assert( in_array( $flag, array( 1, '1' ), true ) === Native_Option_Access::network_enabled(), 'Current-network flag accepted a noncanonical truthy native value.' );
		} finally {
			remove_filter( 'site_option_' . Native_Option_Access::NETWORK_OPTION_NAME, $filter, 10 );
		}
	}

	$list = wpai199n_ok( wpai199n_call( array( 'action' => 'list', 'scope' => 'network', 'name_prefix' => $name, 'limit' => 1 ) ), 'Network physical inventory failed.' );
	wpai199n_assert( 1 === count( $list['items'] ) && $name === $list['items'][0]['name'] && '' === $list['items'][0]['autoload'] && is_string( $list['items'][0]['id'] ), 'Network inventory lost its physical-only metadata shape.' );
	$null_name = $name . '_null';
	wpai199n_assert( true === add_network_option( $network, $null_name, null ), 'Cannot store the synthetic nullable network option.' );
	$null_metadata = wpai199n_ok( wpai199n_call( array( 'action' => 'list', 'scope' => 'network', 'name_prefix' => $null_name ) ), 'Nullable network metadata fixture failed.' );
	wpai199n_assert( 1 === count( $null_metadata['items'] ) && null === $null_metadata['items'][0]['value_bytes'], 'Physical SQL NULL network metadata was normalized to zero/absence.' );
	$virtual = $name . '_virtual';
	$network_value = array( 'virtual_network' => $network, 'false' => false, 'binary' => "\x00\xff\x80" );
	// Core get_network_option supplies pre-value, exact name, network ID and
	// default value to this dynamic hook (option.php, since WordPress4.9).
	$network_filter = static function ( $pre, $option, $network_id, $default_value ) use ( &$network_calls, $network_value, $virtual, $network ) {
		wpai199n_assert( false === $pre && $virtual === $option && $network === $network_id && false === $default_value, 'Core native network filter arguments lost their source/default binding.' );
		++$network_calls;
		return $network_value;
	};
	add_filter( 'pre_site_option_' . $virtual, $network_filter, 10, 4 );
	$virtual_read = wpai199n_ok( wpai199n_call( array( 'action' => 'read', 'scope' => 'network', 'name' => $virtual ) ), 'Native filter-only binary network observation failed.' );
	wpai199n_same( $network_value, wpai199n_decode( $virtual_read['value_json'] ), 'Native network observation changed invalid UTF-8/NUL byte strings.' );
	$virtual_inventory = wpai199n_ok( wpai199n_call( array( 'action' => 'list', 'scope' => 'network', 'name_prefix' => $virtual ) ), 'Filter-only network inventory failed.' );
	wpai199n_assert( array() === $virtual_inventory['items'], 'Physical network inventory invented a filter-only byte value.' );
	$network_calls = 0;
	$network_snapshot = wpai199n_ok( wpai199n_call( array( 'action' => 'snapshot', 'scope' => 'network', 'name' => $virtual ) ), 'Network filter-backed snapshot failed.' );
	$transfers[] = array( $blog, 'network', $network_snapshot['transfer_id'] );
	wpai199n_same( $network_value, wpai199n_decode( wpai199n_collect( $network_snapshot, 'network' ) ), 'Network snapshot changed native filtered value.' );
	wpai199n_assert( 1 === $network_calls, 'Network snapshot/continuations reran get_network_option filter.' );

	$secondary = wp_insert_site( array( 'domain' => 'wpai199n-' . $suffix . '.invalid', 'path' => '/', 'network_id' => $network, 'title' => 'Native option test secondary' ) );
	wpai199n_assert( is_int( $secondary ) && $secondary > 0, 'Cannot create disposable second blog.' );
	switch_to_blog( $secondary );
	$switched = true;
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	wpai199n_assert( true === add_option( $name, 'secondary-site-value', '', false ), 'Cannot store the synthetic secondary-site option.' );
	wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $name ) ), 'Main blog site consent leaked into another blog.' );
	wpai199n_grant_site();
	wpai199n_same( 'secondary-site-value', wpai199n_decode( wpai199n_ok( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $name ) ), 'Explicit secondary site grant failed.' )['value_json'] ), 'Site scope used another blog source.' );
	wpai199n_error( wpai199n_call( array( 'action' => 'snapshot_status', 'scope' => 'network', 'transfer_id' => $network_snapshot['transfer_id'] ) ), 'Network snapshot crossed original-blog binding.' );

	$site_admin = wp_insert_user( array( 'user_login' => 'wpai199nms_' . $suffix, 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'wpai199nms-' . $suffix . '@example.invalid' ) );
	wpai199n_assert( is_int( $site_admin ) && $site_admin > 0 && ! is_wp_error( add_user_to_blog( $secondary, $site_admin, 'administrator' ) ), 'Cannot create disposable local site administrator.' );
	wp_set_current_user( $site_admin );
	wpai199n_assert( current_user_can( 'manage_options' ) && ! is_super_admin(), 'Local fixture unexpectedly has network authority.' );
	wpai199n_ok( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $name ) ), 'Local administrator could not use explicitly granted site settings.' );
	wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'network', 'name' => $name ) ), 'Local administrator inherited Super Admin network read.' );
	wpai199n_protected_denial( static function () use ( $network ) { update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 0 ); } );
	wpai199n_assert( Native_Option_Access::network_enabled(), 'Unauthorized native network update changed consent.' );
	$html = wpai199nms_form( $settings );
	wpai199nms_assert_network_controls( $html, $settings, false );
	$page = new Settings_Page( new Environment(), $settings );
	wpai199nms_form_denial( $page, array( 'network_id' => (string) $network, 'enabled' => '1', '_wpnonce' => wp_create_nonce( 'wpai-native-network-read-' . $network ) ) );

	// Complete an older ordinary save after a Super Admin changes the separate
	// network flag. The outer site row must never retain/write stale consent.
	$interleave = static function ( $value ) use ( $actor, $site_admin, $network ) {
		wpai199n_assert( ! array_key_exists( Settings::GROUP_NATIVE_NETWORK_READ, $value ), 'Ordinary save carried stale network grant bits.' );
		wp_set_current_user( $actor );
		try { update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 0 ); } finally { wp_set_current_user( $site_admin ); }
		return $value;
	};
	add_filter( 'pre_update_option_' . Settings::OPTION_NAME, $interleave );
	update_option( Settings::OPTION_NAME, array( Settings::GROUP_NATIVE_OPTIONS_READ => 1, Settings::GROUP_NATIVE_NETWORK_READ => 1, Settings::GROUP_SITE_READ => 1 ), false );
	remove_filter( 'pre_update_option_' . Settings::OPTION_NAME, $interleave );
	$interleave = null;
	wpai199n_assert( ! Native_Option_Access::network_enabled(), 'Completion of stale ordinary save resurrected revoked network consent.' );

	wp_set_current_user( $actor );
	delete_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME );
	wp_set_current_user( $site_admin );
	wpai199n_protected_denial( static function () use ( $network ) { add_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 1 ); } );
	wpai199n_assert( null === get_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, null ), 'Unauthorized native pre-add persisted network consent.' );
	wp_set_current_user( $actor );
	update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 1 );
	foreach ( array( true, '1', 1.0, array( 1 ) ) as $bad_flag ) {
		wpai199n_protected_denial( static function () use ( $network, $bad_flag ) { update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, $bad_flag ); } );
	}
	$deny = static function ( $caps, $cap ) { return 'manage_network_options' === $cap ? array( 'do_not_allow' ) : $caps; };
	add_filter( 'map_meta_cap', $deny, 999, 2 );
	wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'network', 'name' => $name ) ), 'Super Admin bypassed explicit native network capability denial.' );
	wpai199n_protected_denial( static function () use ( $network ) { update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 0 ); } );
	remove_filter( 'map_meta_cap', $deny, 999 );
	$deny = null;
	$page = new Settings_Page( new Environment(), $settings );
	wpai199nms_form_denial( $page, array( 'network_id' => (string) $network, 'enabled' => '1', '_wpnonce' => 'wrong' ) );
	wpai199nms_form_denial( $page, array( 'network_id' => (string) ( $network + 1000 ), 'enabled' => '1', '_wpnonce' => wp_create_nonce( 'wpai-native-network-read-' . $network ) ) );
	$html = wpai199nms_form( $settings );
	wpai199nms_assert_network_controls( $html, $settings, true );
	restore_current_blog();
	$switched = false;

	update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 0 );
	wpai199n_error( wpai199n_call( array( 'action' => 'snapshot_status', 'scope' => 'network', 'transfer_id' => $network_snapshot['transfer_id'] ) ), 'Revoked network grant authorized existing private snapshot.' );
	update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 1 );

	// Core has no network-create API. A fixture-only native table insert plus
	// wp_insert_site gives a real disposable second-network context.
	wpai199n_assert( 1 === $wpdb->insert( $wpdb->site, array( 'domain' => 'network-' . $suffix . '.invalid', 'path' => '/' ), array( '%s', '%s' ) ), 'Cannot create synthetic second-network row.' );
	$second_network = (int) $wpdb->insert_id;
	clean_network_cache( $second_network );
	// Core's missing ms_files_rewriting default assumes a legacy network and
	// references UPLOADBLOGSDIR during site initialization. Bootstrap this
	// disposable network's own modern metadata before creating its first site.
	// Temporarily expose physical absence only for this missing Core key:
	// otherwise update_network_option sees filtered old=true and cannot insert.
	$modern_default = static function ( $default, $option, $network_id ) use ( $second_network ) {
		return $second_network === $network_id && 'ms_files_rewriting' === $option ? false : $default;
	};
	add_filter( 'default_site_option_ms_files_rewriting', $modern_default, PHP_INT_MAX, 3 );
	try {
		$network_metadata = array(
			'ms_files_rewriting' => 0,
			'site_name' => 'Native option second network',
			'siteurl' => 'https://network-' . $suffix . '.invalid/',
			'admin_email' => wp_get_current_user()->user_email,
			'site_admins' => array( wp_get_current_user()->user_login ),
			'subdomain_install' => is_subdomain_install() ? 1 : 0,
			'WPLANG' => get_locale(),
		);
		foreach ( $network_metadata as $meta_key => $meta_value ) {
			wpai199n_assert( true === update_network_option( $second_network, $meta_key, $meta_value ), 'Cannot initialize synthetic second-network native metadata: ' . $meta_key );
		}
	} finally {
		remove_filter( 'default_site_option_ms_files_rewriting', $modern_default, PHP_INT_MAX );
	}
	wpai199n_assert( in_array( get_network_option( $second_network, 'ms_files_rewriting' ), array( 0, '0' ), true ), 'Synthetic network did not persist its modern native upload mode.' );
	$second_network_blog = wp_insert_site( array( 'domain' => 'network-' . $suffix . '.invalid', 'path' => '/', 'network_id' => $second_network, 'title' => 'Native option second network' ) );
	wpai199n_assert( is_int( $second_network_blog ) && $second_network_blog > 0, 'Cannot create synthetic second-network site.' );
	switch_to_blog( $second_network_blog );
	$switched = true;
	$current_site = get_network( $second_network );
	wpai199n_assert( $second_network === get_current_network_id(), 'Second-network context did not become current.' );
	update_network_option( $second_network, 'site_admins', array( wp_get_current_user()->user_login ) );
	wpai199n_grant_site();
	update_network_option( $second_network, Native_Option_Access::NETWORK_OPTION_NAME, 1 );
	wpai199n_assert( $access->allowed( 'network' ), 'Synthetic second network did not acquire its own genuine grants.' );
	wpai199n_error( wpai199n_call( array( 'action' => 'snapshot_read', 'scope' => 'network', 'transfer_id' => $network_snapshot['transfer_id'], 'offset' => 0 ) ), 'Snapshot crossed both original blog and network.' );
	// Preserve the original blog while selecting another real current network:
	// this isolates the additional network binding from the existing blog guard.
	restore_current_blog();
	$switched = false;
	wpai199n_assert( $blog === get_current_blog_id() && $second_network === get_current_network_id() && $access->allowed( 'network' ), 'Isolated network binding probe lacks independent valid current-network consent.' );
	wpai199n_error( wpai199n_call( array( 'action' => 'snapshot_status', 'scope' => 'network', 'transfer_id' => $network_snapshot['transfer_id'] ) ), 'Another network consent reopened a snapshot with the original blog/user.' );
	wpai199n_protected_denial( static function () use ( $network ) { update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 0 ); } );
	$current_site = $original_current_site;
	wpai199n_ok( wpai199n_call( array( 'action' => 'snapshot_release', 'scope' => 'network', 'transfer_id' => $network_snapshot['transfer_id'] ) ), 'Original network could not release its own snapshot.' );
	$transfers = array();
	echo 'PASS: Issue #199 native multisite consent, current native guards, site/network binding (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
} finally {
	wp_set_current_user( $actor );
	if ( null !== $deny ) { remove_filter( 'map_meta_cap', $deny, 999 ); }
	if ( null !== $interleave ) { remove_filter( 'pre_update_option_' . Settings::OPTION_NAME, $interleave ); }
	if ( null !== $network_filter ) { remove_filter( 'pre_site_option_' . $name . '_virtual', $network_filter, 10 ); }
	if ( $switched ) { restore_current_blog(); }
	$current_site = $original_current_site;
	wpai199n_grant_site();
	update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, 1 );
	foreach ( $transfers as $item ) {
		wpai199n_call( array( 'action' => 'snapshot_release', 'scope' => $item[1], 'transfer_id' => $item[2] ) );
	}
	delete_network_option( $network, $name );
	delete_network_option( $network, $name . '_null' );
	if ( $second_network_blog > 0 ) { wp_delete_site( $second_network_blog ); }
	if ( $second_network > 0 ) {
		$wpdb->delete( $wpdb->sitemeta, array( 'site_id' => $second_network ), array( '%d' ) );
		$wpdb->delete( $wpdb->site, array( 'id' => $second_network ), array( '%d' ) );
		clean_network_cache( $second_network );
	}
	if ( $secondary > 0 ) { wp_delete_site( $secondary ); }
	if ( $site_admin > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $site_admin );
	}
	if ( null === $network_original ) { delete_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME ); } else { update_network_option( $network, Native_Option_Access::NETWORK_OPTION_NAME, (int) $network_original ); }
	wpai199n_restore_option( Settings::OPTION_NAME, $original, null !== $original );
	wpai199n_restore_option( Mutation_Log::OPTION_NAME, $original_log, null !== $original_log );
}
