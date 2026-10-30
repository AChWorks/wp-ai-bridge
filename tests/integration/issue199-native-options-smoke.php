<?php
/**
 * Disposable native Options API semantics, immutable snapshots and authority.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Option_Snapshots;
use WP_AI_Bridge\Support\Native_Option_Value;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Transfer_Session;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;

require_once __DIR__ . '/issue199-native-options-helper.php';

global $wpdb, $wp_filter, $wp_current_filter;
$actor = get_current_user_id();
$original = get_option( Settings::OPTION_NAME, null );
$original_log = get_option( Mutation_Log::OPTION_NAME, null );
$original_transfer = get_option( Transfer_Settings::OPTION_NAME, null );
$prefix = 'wpai199n_' . bin2hex( random_bytes( 5 ) ) . '_';
$names = array( $prefix . 'neutral', $prefix . 'false', $prefix . 'empty', $prefix . 'structured' );
$virtual = $prefix . 'virtual';
$default_name = $prefix . 'default';
$missing = $prefix . 'missing';
$large_name = $prefix . 'large_virtual';
$hooked = array();
$transfers = array();
$other_user = 0;

try {
	wpai199n_assert( ! is_multisite() && current_user_can( 'manage_options' ), 'Use only a disposable single-site administrator fixture.' );
	$settings = new Settings();
	$settings->register();
	$ability = wp_get_ability( 'wp-ai-bridge/native-options-read' );
	wpai199n_assert( $ability instanceof WP_Ability, 'Native option Ability did not register through Core.' );
	$annotations = $ability->get_meta()['annotations'];
	wpai199n_assert( false === $annotations['readonly'] && false === $annotations['destructive'] && false === $annotations['idempotent'], 'Mixed read/private-snapshot annotations hide persistence.' );
	$schema = $ability->get_input_schema();
	wpai199n_assert( 6 === count( $schema['oneOf'] ?? array() ), 'Native input omitted a closed action contract.' );
	foreach ( $schema['oneOf'] as $variant ) {
		wpai199n_assert( false === $variant['additionalProperties'], 'Native action input is not closed.' );
		foreach ( array( 'blog_id', 'network_id', 'path', 'table', 'value', 'purpose', 'user_id', 'client_id' ) as $forbidden ) {
			wpai199n_assert( ! isset( $variant['properties'][ $forbidden ] ), 'Caller-owned native authority/source selector entered the schema.' );
		}
	}

	$secret = 'synthetic-native-secret-' . bin2hex( random_bytes( 8 ) );
	wpai199n_assert( true === add_option( $names[0], $secret, '', false ), 'Cannot store the synthetic neutral-key option.' );
	wpai199n_assert( true === add_option( $names[1], false, '', false ), 'Cannot store the synthetic false option.' );
	wpai199n_assert( true === add_option( $names[2], '', '', true ), 'Cannot store the synthetic empty option.' );
	// Core serializes arrays into the installation's UTF-8 LONGTEXT column;
	// arbitrary invalid UTF-8 belongs in native filter observations below.
	$stored_structure = array( 9 => "\x00native سلام 🌍", '09' => false, -2 => PHP_INT_MAX );
	wpai199n_assert( true === add_option( $names[3], $stored_structure, '', false ), 'Cannot store the synthetic UTF-8/NUL structured option.' );
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $names[0] ) ), 'Default-off grant disclosed a secret under a neutral key.' );
	$legacy = $settings->defaults();
	unset( $legacy[ Settings::GROUP_NATIVE_OPTIONS_READ ] );
	$legacy = array_fill_keys( array_keys( $legacy ), 1 );
	update_option( Settings::OPTION_NAME, $legacy, false );
	wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $names[0] ) ), 'Upgrade inherited new native consent from all old grants.' );
	wpai199n_grant_site();

	$deny_site = static function ( $caps, $cap ) { return 'manage_options' === $cap ? array( 'do_not_allow' ) : $caps; };
	add_filter( 'map_meta_cap', $deny_site, 999, 2 );
	try {
		wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $names[0] ) ), 'Explicit native site capability denial was ignored.' );
	} finally {
		remove_filter( 'map_meta_cap', $deny_site, 999 );
	}
	wp_set_current_user( 0 );
	try {
		wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $names[0] ) ), 'Anonymous native read inherited site consent.' );
	} finally {
		wp_set_current_user( $actor );
	}
	$seen = array();
	$after = '0';
	do {
		$page = wpai199n_ok( wpai199n_call( array( 'action' => 'list', 'scope' => 'site', 'name_prefix' => $prefix, 'limit' => 1, 'after_id' => $after ) ), 'Physical native inventory failed.' );
		wpai199n_assert( 'physical_storage_only' === $page['inventory'] && 1 >= $page['returned'] && $page['returned'] === count( $page['items'] ) && Bounded_Payload::fits( $page ), 'Physical inventory budget/metadata contract changed.' );
		foreach ( $page['items'] as $item ) {
			wpai199n_assert( array( 'id', 'name', 'value_bytes', 'autoload' ) === array_keys( $item ), 'Physical native inventory disclosed undocumented payload fields.' );
			wpai199n_assert( is_string( $item['id'] ) && ctype_digit( $item['id'] ) && is_string( $item['value_bytes'] ) && ctype_digit( $item['value_bytes'] ) && is_string( $item['autoload'] ), 'Physical metadata did not preserve decimal strings.' );
			wpai199n_assert( ! isset( $seen[ $item['name'] ] ), 'Native keyset duplicated physical names.' );
			$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT option_id, OCTET_LENGTH(option_value) AS value_bytes, autoload FROM %i WHERE BINARY option_name = BINARY %s', $wpdb->options, $item['name'] ), ARRAY_A );
			wpai199n_assert( is_array( $stored ) && (string) $stored['option_id'] === $item['id'] && (string) $stored['value_bytes'] === $item['value_bytes'] && $stored['autoload'] === $item['autoload'], 'Native inventory metadata differs from its physical fixture.' );
			$seen[ $item['name'] ] = true;
		}
		wpai199n_assert( ! str_contains( wp_json_encode( $page ), $secret ), 'Physical inventory exposed a stored value.' );
		$after = $page['next_after_id'];
		wpai199n_assert( is_string( $after ) && ctype_digit( $after ), 'Native continuation cursor was rounded/coerced.' );
	} while ( $page['has_more'] );
	wpai199n_assert( count( $names ) === count( $seen ) && ! array_diff( $names, array_keys( $seen ) ), 'Physical inventory lost fixture records or invented virtual ones.' );

	// Adjacent decimal BIGINT identities beyond JavaScript's exact range must
	// survive SQL keyset comparison without a floating-point coercion.
	$high_prefix = $prefix . 'large_id_';
	$high_ids = array( '9007199254740993', '9007199254740994' );
	$before_increment = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->options ) );
	wpai199n_assert( ctype_digit( $before_increment ) && (int) $before_increment > 0, 'Cannot capture disposable options AUTO_INCREMENT before large cursor fixture.' );
	try {
		foreach ( $high_ids as $index => $id ) {
			wpai199n_assert( 1 === $wpdb->insert( $wpdb->options, array( 'option_id' => $id, 'option_name' => $high_prefix . $index, 'option_value' => 'synthetic-physical-metadata', 'autoload' => 'off' ), array( '%s', '%s', '%s', '%s' ) ), 'Cannot create exact high-ID metadata fixture.' );
		}
		$high_seen = array();
		$cursor = '9007199254740992';
		do {
			$high_page = wpai199n_ok( wpai199n_call( array( 'action' => 'list', 'scope' => 'site', 'name_prefix' => $high_prefix, 'limit' => 1, 'after_id' => $cursor ) ), 'High decimal cursor inventory failed.' );
			foreach ( $high_page['items'] as $item ) { $high_seen[] = $item['id']; }
			$cursor = $high_page['next_after_id'];
		} while ( $high_page['has_more'] );
		wpai199n_assert( $high_ids === $high_seen && '9007199254740994' === $cursor, 'Adjacent IDs beyond2^53 were rounded, skipped or duplicated.' );
		$high_end = wpai199n_ok( wpai199n_call( array( 'action' => 'list', 'scope' => 'site', 'name_prefix' => $high_prefix, 'limit' => 1, 'after_id' => $cursor ) ), 'Final high decimal cursor failed.' );
		wpai199n_assert( array() === $high_end['items'] && false === $high_end['has_more'], 'High-ID inventory repeated its final row.' );
	} finally {
		foreach ( $high_ids as $index => $id ) { $wpdb->delete( $wpdb->options, array( 'option_id' => $id, 'option_name' => $high_prefix . $index ), array( '%s', '%s' ) ); }
		wpai199n_assert( false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i AUTO_INCREMENT = %d', $wpdb->options, (int) $before_increment ) ), 'Could not restore disposable options AUTO_INCREMENT after exact high-ID cleanup.' );
		$after_increment = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->options ) );
		wpai199n_assert( $before_increment === $after_increment, 'Large cursor fixture did not restore the original options AUTO_INCREMENT.' );
	}

	foreach ( array_merge( $names, array( $missing ) ) as $name ) {
		$native = get_option( $name );
		$read = wpai199n_ok( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $name ) ), 'Native scalar/structured observed read failed.' );
		wpai199n_assert( 'native_options_api' === $read['view'] && 'unknown' === $read['storage_presence'] && $name === $read['name'] && true === $read['value_available'] && false === $read['snapshot_required'], 'Read invented physical absence or hid an inline value.' );
		wpai199n_same( $native, wpai199n_decode( $read['value_json'] ), 'Read changed Core-observed scalar/array semantics.' );
		wpai199n_assert( strlen( $read['value_json'] ) === $read['total_bytes'] && hash( 'sha256', $read['value_json'] ) === $read['sha256'] && Bounded_Payload::fits( $read ), 'Inline identity/budget is not bound to the full observed value.' );
	}
	// pre_option can return bytes that Core's UTF-8 physical storage rejects.
	$virtual_value = array( 'filtered' => true, 'binary' => "\x00\xff", 'numeric' => PHP_INT_MAX );
	$virtual_filter = static function () use ( $virtual_value ) { return $virtual_value; };
	add_filter( 'pre_option_' . $virtual, $virtual_filter, 10, 0 );
	$hooked[] = array( 'pre_option_' . $virtual, $virtual_filter, 10 );
	$default_filter = static function () { return false; };
	add_filter( 'default_option_' . $default_name, $default_filter, 10, 0 );
	$hooked[] = array( 'default_option_' . $default_name, $default_filter, 10 );
	foreach ( array( $virtual => $virtual_value, $default_name => false ) as $name => $expected ) {
		$read = wpai199n_ok( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $name ) ), 'Virtual/default native option was inaccessible.' );
		wpai199n_same( $expected, wpai199n_decode( $read['value_json'] ), 'Core filtered/default false value was mistaken for absence.' );
	}
	$physical_only = wpai199n_ok( wpai199n_call( array( 'action' => 'list', 'scope' => 'site', 'name_prefix' => $virtual ) ), 'Virtual-name inventory failed.' );
	wpai199n_assert( 0 === count( $physical_only['items'] ), 'Physical inventory invented a filter-only option row.' );

	$bad = array(
		array( 'action' => 'update', 'scope' => 'site', 'name' => $virtual ),
		array( 'action' => 'read', 'scope' => 'network', 'name' => $virtual ),
		array( 'action' => 'read', 'scope' => 'site', 'name' => ' ' . $virtual ),
		array( 'action' => 'read', 'scope' => 'site', 'name' => $virtual . ' ' ),
		array( 'action' => 'read', 'scope' => 'site', 'name' => "invalid\0name" ),
		array( 'action' => 'read', 'scope' => 'site', 'name' => "\xff" ),
		array( 'action' => 'read', 'scope' => 'site', 'name' => '' ),
		array( 'action' => 'list', 'scope' => 'site', 'after_id' => 1 ),
		array( 'action' => 'list', 'scope' => 'site', 'after_id' => '01' ),
		array( 'action' => 'list', 'scope' => 'site', 'after_id' => '1 OR 1=1' ),
		array( 'action' => 'list', 'scope' => 'site', 'limit' => PHP_INT_MAX ),
		array( 'action' => 'snapshot_read', 'scope' => 'site', 'transfer_id' => str_repeat( 'a', 64 ), 'offset' => -1 ),
		array( 'action' => 'snapshot_read', 'scope' => 'site', 'transfer_id' => str_repeat( 'a', 64 ), 'offset' => 0, 'length' => 0 ),
	);
	foreach ( array( 'blog_id', 'network_id', 'table', 'path', 'value', 'purpose' ) as $field ) {
		$bad[] = array( 'action' => 'read', 'scope' => 'site', 'name' => $virtual, $field => 'caller-owned' );
	}
	foreach ( $bad as $input ) {
		wpai199n_error( wpai199n_call( $input ), 'Malformed/cross-scope native input was accepted.' );
	}

	// A native filter is the authoritative source, invoked exactly once when
	// snapshotting. Windows must never rerun it, even after the source changes.
	$large_bytes = str_repeat( "\x00\xffNativeOptions", 225000 );
	wpai199n_assert( strlen( base64_encode( $large_bytes ) ) > 4 * 1024 * 1024, 'Snapshot fixture must exceed four MiB of encoded native value.' );
	$provider_calls = 0;
	$large_filter = static function () use ( &$provider_calls, &$large_bytes ) { ++$provider_calls; return $large_bytes; };
	add_filter( 'pre_option_' . $large_name, $large_filter, 10, 0 );
	$hooked[] = array( 'pre_option_' . $large_name, $large_filter, 10 );
	$inline = wpai199n_ok( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $large_name ) ), 'Large observed read did not give an explicit export decision.' );
	wpai199n_assert( false === $inline['value_available'] && true === $inline['snapshot_required'] && ( ! isset( $inline['value_json'] ) || '' === $inline['value_json'] ) && Bounded_Payload::fits( $inline ), 'Large native read silently truncated or exceeded the envelope.' );
	$provider_calls = 0;
	$snapshot = wpai199n_ok( wpai199n_call( array( 'action' => 'snapshot', 'scope' => 'site', 'name' => $large_name ) ), 'Private native snapshot creation failed.' );
	$transfers[] = $snapshot['transfer_id'];
	wpai199n_assert( 1 === $provider_calls && $snapshot['total_bytes'] > 4 * 1024 * 1024 && is_int( $snapshot['expires_at'] ) && $snapshot['expires_at'] > time(), 'Snapshot creation repeated its native getter or lost finite exact metadata.' );
	$original_large = $large_bytes;
	$large_bytes = 'changed after snapshot';
	$assembled = wpai199n_collect( $snapshot );
	wpai199n_same( $original_large, wpai199n_decode( $assembled ), 'Snapshot changed with its live provider or lost bytes.' );
	wpai199n_assert( 1 === $provider_calls, 'Continuation re-executed a native filter.' );
	wpai199n_assert( ! str_contains( wp_json_encode( $snapshot ), ABSPATH ) && ! isset( $snapshot['path'], $snapshot['url'], $snapshot['value_json'] ), 'Snapshot exposed a storage path/public URL/full native value.' );

	$generic = new Private_Transfer_Session( new Permissions( $settings ) );
	wpai199n_error( $generic->status( $snapshot['transfer_id'] ), 'Generic private-transfer grants accessed a native purpose.' );
	wpai199n_error( $generic->verified_private_source( $snapshot['transfer_id'], 'media' ), 'Media purpose consumed native snapshot.' );
	wpai199n_error( Private_Transfer_Session::for_database( new Permissions( $settings ) )->status( $snapshot['transfer_id'] ), 'Database grants accessed a native purpose.' );
	$other_user = wp_insert_user( array( 'user_login' => $prefix . 'other', 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) );
	wpai199n_assert( is_int( $other_user ) && $other_user > 0, 'Cannot create distinct native administrator fixture.' );
	wp_set_current_user( $other_user );
	wpai199n_error( wpai199n_call( array( 'action' => 'snapshot_status', 'scope' => 'site', 'transfer_id' => $snapshot['transfer_id'] ) ), 'Equally privileged wrong owner accessed native snapshot.' );
	wp_set_current_user( $actor );
	wpai199n_grant_site( false );
	foreach ( array( 'snapshot_status', 'snapshot_read', 'snapshot_release' ) as $action ) {
		$input = array( 'action' => $action, 'scope' => 'site', 'transfer_id' => $snapshot['transfer_id'] );
		if ( 'snapshot_read' === $action ) { $input['offset'] = 0; }
		wpai199n_error( wpai199n_call( $input ), 'Revoked current grant authorized snapshot continuation/status/release.' );
	}
	wpai199n_grant_site();
	$release = wpai199n_ok( wpai199n_call( array( 'action' => 'snapshot_release', 'scope' => 'site', 'transfer_id' => $snapshot['transfer_id'] ) ), 'Authorized native snapshot release failed.' );
	wpai199n_assert( true === $release['released'], 'Native snapshot release did not confirm cleanup.' );
	wpai199n_error( wpai199n_call( array( 'action' => 'snapshot_status', 'scope' => 'site', 'transfer_id' => $snapshot['transfer_id'] ) ), 'Released native snapshot remained readable.' );
	$transfers = array();

	$expiry = wpai199n_ok( wpai199n_call( array( 'action' => 'snapshot', 'scope' => 'site', 'name' => $names[0] ) ), 'Cannot create expiry fixture.' );
	$transfers[] = $expiry['transfer_id'];
	$before_expiry = wpai199n_lease_expiry( $expiry['transfer_id'], time() - 1 );
	foreach ( array( 'snapshot_status', 'snapshot_read', 'snapshot_release' ) as $action ) {
		$input = array( 'action' => $action, 'scope' => 'site', 'transfer_id' => $expiry['transfer_id'] );
		if ( 'snapshot_read' === $action ) { $input['offset'] = 0; }
		wpai199n_error( wpai199n_call( $input ), 'Expired native snapshot remained usable.' );
	}
	wpai199n_lease_expiry( $expiry['transfer_id'], $before_expiry );
	wpai199n_ok( wpai199n_call( array( 'action' => 'snapshot_release', 'scope' => 'site', 'transfer_id' => $expiry['transfer_id'] ) ), 'Restored expiry fixture could not release its own bytes.' );
	$transfers = array();

	// Cleanup capability is request-local to the exact facade that created
	// the bytes. Another facade cannot use it to bypass owner/grant checks.
	$facade = new Native_Option_Snapshots( new Permissions( $settings ), 'site' );
	$encoded_cleanup = wpai199n_ok( Native_Option_Value::encode( 'owned-cleanup-fixture', 4096 ), 'Cleanup fixture encoding failed.' );
	$cleanup = wpai199n_ok( $facade->create( $encoded_cleanup['json'] ), 'Cleanup facade could not create its own bytes.' );
	$transfers[] = $cleanup['transfer_id'];
	$other_facade = new Native_Option_Snapshots( new Permissions( $settings ), 'site' );
	wpai199n_error( $other_facade->discard_created( $cleanup['transfer_id'] ), 'Unrelated facade inherited failed-creation cleanup authority.' );
	wpai199n_grant_site( false );
	wpai199n_assert( true === $facade->discard_created( $cleanup['transfer_id'] ), 'Original facade could not erase its own incomplete/revoked creation.' );
	wpai199n_grant_site();
	wpai199n_error( wpai199n_call( array( 'action' => 'snapshot_status', 'scope' => 'site', 'transfer_id' => $cleanup['transfer_id'] ) ), 'Discarded failed-creation bytes remained accessible.' );
	$transfers = array();

	// Revoke after bytes have been published, during audit readback. The
	// public failure must erase its own unpublished transfer reservation.
	$lease_store = Private_Transfer_Session::for_native_options( new Permissions( $settings ), 'site' );
	$root_method = new ReflectionMethod( $lease_store, 'root' );
	$lease_root = $root_method->invoke( $lease_store );
	wpai199n_assert( is_string( $lease_root ), 'Cannot inspect the exact disposable private root.' );
	$before_leases = glob( $lease_root . '/*.meta' );
	$revoke_audit = static function ( $value ) { wpai199n_grant_site( false ); return $value; };
	add_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $revoke_audit );
	try {
		wpai199n_error( wpai199n_call( array( 'action' => 'snapshot', 'scope' => 'site', 'name' => $names[0] ) ), 'Audit-time revocation disclosed a newly published native snapshot.' );
	} finally {
		remove_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $revoke_audit );
		wpai199n_grant_site();
	}
	wpai199n_assert( ! array_diff( glob( $lease_root . '/*.meta' ), $before_leases ), 'Revoked creation left an unpublished native snapshot reservation/bytes.' );

	// A silent native log-write veto must withhold values just like a failed
	// provider boundary. Throw-only tests cannot detect an ignored false.
	$audit_before_veto = get_option( Mutation_Log::OPTION_NAME, array() );
	$flags_before_veto = get_option( Settings::OPTION_NAME );
	$blog_before_veto = get_current_blog_id();
	$files_before_veto = glob( $lease_root . '/*' );
	$veto_audit = static function ( $value, $old_value ) { return $old_value; };
	add_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $veto_audit, PHP_INT_MAX, 2 );
	try {
		foreach ( array( 'read', 'snapshot' ) as $action ) {
			$withheld = wpai199n_error( wpai199n_call( array( 'action' => $action, 'scope' => 'site', 'name' => $names[0] ) ), 'Silent audit persistence failure exposed a native value/snapshot.', array( 'native_options_audit_unavailable' ) );
			$failure_json = wp_json_encode( array( $withheld->get_error_code(), $withheld->get_error_message(), $withheld->get_error_data() ) );
			$failure_data = $withheld->get_error_data();
			wpai199n_assert( ! str_contains( $failure_json, $secret ) && ! str_contains( $failure_json, $names[0] ) && ( ! is_array( $failure_data ) || ( ! array_key_exists( 'transfer_id', $failure_data ) && ! array_key_exists( 'value_json', $failure_data ) ) ), 'Audit failure exposed secret, raw name or a usable native snapshot/value.' );
			wpai199n_assert( ! array_diff( glob( $lease_root . '/*' ), $files_before_veto ), 'Silent audit veto left newly created native snapshot bytes/checkpoints/reservations.' );
		}
	} finally {
		remove_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $veto_audit, PHP_INT_MAX );
	}
	wpai199n_assert( $audit_before_veto === get_option( Mutation_Log::OPTION_NAME ) && $flags_before_veto === get_option( Settings::OPTION_NAME ) && $secret === get_option( $names[0] ) && $actor === get_current_user_id() && $blog_before_veto === get_current_blog_id(), 'Silent audit-veto fixture changed the original log, grants, native option or caller context.' );

	// Exceptions must leave WordPress's dispatcher reusable and never return
	// the provider's exception text in output or persisted activity.
	$throw_name = $prefix . 'throw';
	$throw_marker = 'synthetic-exception-secret-' . bin2hex( random_bytes( 8 ) );
	$throw_filter = static function () use ( $throw_marker ) { throw new RuntimeException( $throw_marker ); };
	add_filter( 'pre_option_' . $throw_name, $throw_filter, 10, 0 );
	$hooked[] = array( 'pre_option_' . $throw_name, $throw_filter, 10 );
	$stack = $wp_current_filter;
	$result = wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $throw_name ) ), 'Throwing native filter did not produce a safe failure.' );
	wpai199n_assert( $stack === $wp_current_filter && false === $wp_filter[ 'pre_option_' . $throw_name ]->current_priority(), 'Throwing native callback left hook iteration/stack state behind.' );
	wpai199n_assert( ! str_contains( wp_json_encode( array( $result->get_error_message(), $result->get_error_data() ) ), $throw_marker ), 'Native callback exception secret escaped into response.' );
	$drift_name = $prefix . 'drift';
	$drift = static function () use ( $other_user, $secret ) { wp_set_current_user( $other_user ); return $secret; };
	add_filter( 'pre_option_' . $drift_name, $drift, 10, 0 );
	$hooked[] = array( 'pre_option_' . $drift_name, $drift, 10 );
	wpai199n_error( wpai199n_call( array( 'action' => 'snapshot', 'scope' => 'site', 'name' => $drift_name ) ), 'Native getter principal drift persisted/disclosed a snapshot.' );
	wp_set_current_user( $actor );
	$revoke_name = $prefix . 'revoke';
	$revoke = static function () use ( $secret ) { wpai199n_grant_site( false ); return $secret; };
	add_filter( 'pre_option_' . $revoke_name, $revoke, 10, 0 );
	$hooked[] = array( 'pre_option_' . $revoke_name, $revoke, 10 );
	wpai199n_error( wpai199n_call( array( 'action' => 'read', 'scope' => 'site', 'name' => $revoke_name ) ), 'Native filter revocation escaped fresh output authorization.' );
	wpai199n_grant_site();

	$entries = array_values( array_filter( (array) get_option( Mutation_Log::OPTION_NAME, array() ), static function ( $entry ) { return 'wp-ai-bridge/native-options-read' === ( $entry['ability'] ?? '' ); } ) );
	wpai199n_assert( count( $entries ) > 1, 'Native read/export activity was not persisted.' );
	$audit = wp_json_encode( $entries );
	wpai199n_assert( ! str_contains( $audit, $secret ) && ! str_contains( $audit, $prefix ) && ! str_contains( $audit, $throw_marker ) && ! str_contains( $audit, base64_encode( substr( $original_large, 0, 48 ) ) ), 'Native activity exposed raw option names/values/exception data.' );
	$refs = array_column( $entries, 'event_ref' );
	wpai199n_assert( count( $entries ) === count( $refs ) && count( $refs ) === count( array_unique( $refs ) ), 'Native audit events lack distinct event fingerprints.' );
	echo 'PASS: Issue #199 native Options API, private snapshots, current authority and hook recovery (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
} finally {
	wp_set_current_user( $actor );
	foreach ( $hooked as $hook ) { remove_filter( $hook[0], $hook[1], $hook[2] ); }
	wpai199n_grant_site();
	foreach ( $transfers as $id ) { wpai199n_call( array( 'action' => 'snapshot_release', 'scope' => 'site', 'transfer_id' => $id ) ); }
	foreach ( $names as $name ) { delete_option( $name ); }
	if ( $other_user > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $other_user );
	}
	wpai199n_restore_option( Settings::OPTION_NAME, $original, null !== $original );
	wpai199n_restore_option( Transfer_Settings::OPTION_NAME, $original_transfer, null !== $original_transfer );
	wpai199n_restore_option( Mutation_Log::OPTION_NAME, $original_log, null !== $original_log );
}
