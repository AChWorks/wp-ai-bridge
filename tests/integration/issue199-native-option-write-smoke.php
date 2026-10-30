<?php
/**
 * Real native Options callbacks, durable replay fencing and complete inputs.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Option_Inputs;
use WP_AI_Bridge\Support\Native_Option_Snapshots;
use WP_AI_Bridge\Support\Native_Option_Value;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Transfer_Session;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-native-option-write-helper.php';

global $wpdb, $wp_current_filter, $wp_filter;
$actor = get_current_user_id();
$ordinary = get_option( Settings::OPTION_NAME, null );
$log = get_option( Mutation_Log::OPTION_NAME, null );
$journals = wpai199nw_journals_before();
$legacy_receipt_count = WP_AI_Bridge\Support\Create_Claim_Store::count();
$prefix = 'native199nw_' . bin2hex( random_bytes( 5 ) );
$name = $prefix . '_neutral';
$names = array( $name, $prefix . '_upsert', $prefix . '_effect', $prefix . '_large', $prefix . '_Café' );
$hooks = array();
$inputs = array();
$read_id = '';
$other_user = 0;
$setter_calls = 0;
$pre_update = static function ( $value ) use ( &$setter_calls ) { ++$setter_calls; return $value; };

try {
	wpai199n_assert( ! is_multisite() && current_user_can( 'manage_options' ), 'Use a disposable native administrator single-site installation.' );
	$settings = new Settings();
	$settings->register();
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	$request = wpai199nw_request( 'add', $name, 'synthetic native secret' );
	wpai199n_error( wpai199nw_call( $request ), 'Default-off native mutation reached a target.' );
	$legacy = array_fill_keys( array_keys( $settings->defaults() ), 1 );
	unset( $legacy[ Settings::GROUP_NATIVE_OPTIONS_WRITE ] );
	update_option( Settings::OPTION_NAME, $legacy, false );
	wpai199n_error( wpai199nw_call( $request ), 'Old all-enabled flags inherited new native write consent.' );
	wpai199nw_grants();
	$added = wpai199nw_returned( wpai199nw_call( $request ), true );
	wpai199n_same( 'synthetic native secret', get_option( $name ), 'Native add changed exact intended bytes.' );
	wpai199n_assert( false === $added['historical'] && $name === $added['receipt']['name'], 'First native attempt lost its authorized private target identity.' );
	$add_again = wpai199nw_request( 'add', $name, 'must remain original' );
	wpai199nw_returned( wpai199nw_call( $add_again ), false );
	wpai199n_same( 'synthetic native secret', get_option( $name ), 'Native add-existing false was converted into overwrite.' );

	// Direct public provider boundary: Core rejects malformed inputs before
	// reaching its execute callback, so exercise per-instance state explicitly.
	$direct_provider = new WP_AI_Bridge\Abilities\Native_Option_Write_Abilities( new Permissions( $settings ) );
	$direct_calls = 0;
	$direct_counter = static function ( $value ) use ( &$direct_calls ) { ++$direct_calls; return $value; };
	add_filter( 'pre_update_option_' . $name, $direct_counter );
	try {
		wpai199nw_returned( $direct_provider->execute( wpai199nw_request( 'update', $name, 'direct provider state reset' ) ), true );
		wpai199n_assert( 1 === $direct_calls, 'Direct provider state-reset prerequisite did not dispatch a real native setter.' );
		$malformed_after_success = wpai199n_error( $direct_provider->execute( array( 'action' => 'update', 'scope' => 'site' ) ), 'Direct provider accepted malformed input after a native success.' );
		$malformed_data = $malformed_after_success->get_error_data();
		wpai199n_assert( is_array( $malformed_data ) && array_key_exists( 'dispatch_attempted', $malformed_data ) && false === $malformed_data['dispatch_attempted'] && 1 === $direct_calls && 'direct provider state reset' === get_option( $name ), 'Malformed direct-provider call inherited the previous invocation dispatch flag or resent a native setter.' );
	} finally { remove_filter( 'pre_update_option_' . $name, $direct_counter ); }

	add_filter( 'pre_update_option_' . $name, $pre_update );
	$hooks[] = array( 'pre_update_option_' . $name, $pre_update, 10 );
	$update = wpai199nw_request( 'update', $name, "native\x00سلام" );
	$first = wpai199nw_returned( wpai199nw_call( $update ), true );
	wpai199n_assert( 1 === $setter_calls, 'First native update did not dispatch exactly one setter callback.' );
	$replay = wpai199nw_receipt( wpai199nw_call( $update ) );
	wpai199n_assert( true === $replay['historical'] && $first['operation_ref'] === $replay['operation_ref'] && 1 === $setter_calls, 'Same operation ID resent the native mutator.' );
	$conflict = $update;
	$conflict['value_source'] = wpai199nw_source( 'changed same ID intent' );
	wpai199n_error( wpai199nw_call( $conflict ), 'Changed same-ID input was not fenced.', array( 'native_options_write_operation_conflict' ) );
	wpai199n_assert( 1 === $setter_calls, 'Same-ID conflict dispatched a native setter.' );
	$getter_calls = 0;
	$forbid_getter = static function () use ( &$getter_calls ) { ++$getter_calls; throw new RuntimeException( 'status must not call target' ); };
	add_filter( 'pre_option_' . $name, $forbid_getter );
	try {
		$status = wpai199nw_receipt( wpai199nw_status( $update['operation_id'] ) );
		wpai199n_assert( 0 === $getter_calls && 1 === $setter_calls && true === $status['historical'], 'Historical status reran native getter/setter callbacks.' );
	} finally { remove_filter( 'pre_option_' . $name, $forbid_getter ); }

	$stale = wpai199nw_request( 'update', $name, 'stale intent' );
	$stale['expected_observed_sha256'] = str_repeat( '0', 64 );
	wpai199n_error( wpai199nw_call( $stale ), 'Mismatched native-view stale intent dispatched.' );
	wpai199n_assert( 1 === $setter_calls, 'Stale-intent failure reached native setter.' );
	$sanitizer = static function () { return array( 'sanitized' => true, 'bytes' => "valid\x00سلام" ); };
	add_filter( 'sanitize_option_' . $name, $sanitizer );
	try {
		$rewritten = wpai199nw_returned( wpai199nw_call( wpai199nw_request( 'update', $name, 'provider changes this type' ) ), true );
		wpai199n_same( array( 'sanitized' => true, 'bytes' => "valid\x00سلام" ), get_option( $name ), 'Core sanitizer was bypassed or its transformed type was lost.' );
		wpai199n_assert( true === $rewritten['receipt']['observed_after_available'] && wpai199nw_hash( $name ) === $rewritten['receipt']['observed_after_sha256'], 'Post-call observed hash is not the separate filtered native view.' );
	} finally { remove_filter( 'sanitize_option_' . $name, $sanitizer ); }

	// False does not mean no effects: this callback changes another native key
	// and returns the old value, causing Core's target update to return false.
	$false_callback = static function ( $value, $old ) use ( $names ) { update_option( $names[2], 'callback effect retained', false ); return $old; };
	add_filter( 'pre_update_option_' . $name, $false_callback, 20, 2 );
	try {
		$false = wpai199nw_returned( wpai199nw_call( wpai199nw_request( 'update', $name, 'Core returns false' ) ), false );
		wpai199n_assert( 'callback effect retained' === get_option( $names[2] ) && 'native_call_returned' === $false['receipt']['outcome'], 'Native false was mislabeled as no callback effects.' );
	} finally { remove_filter( 'pre_update_option_' . $name, $false_callback, 20 ); }
	wpai199nw_returned( wpai199nw_call( wpai199nw_request( 'update', $names[1], 'native update can add absent keys' ) ), true );
	wpai199n_same( 'native update can add absent keys', get_option( $names[1] ), 'Native update-on-absent lifecycle was replaced by exclusive update.' );

	// Target policy must run before native target callbacks for control names,
	// Core protected/deprecated names and the seven specialized settings.
	$ignorable_alias = "wp_ai_brid\u{200D}ge_settings";
	$ignorable_equality = $wpdb->get_var( $wpdb->prepare( 'SELECT option_name = %s FROM %i WHERE BINARY option_name = BINARY %s LIMIT 1', $ignorable_alias, $wpdb->options, Settings::OPTION_NAME ) );
	wpai199n_assert( '1' === (string) $ignorable_equality, 'Protected Unicode alias precondition failed: U+200D is not equal under the actual option_name column collation.' );
	$blocked = array( Settings::OPTION_NAME, strtoupper( Settings::OPTION_NAME ), 'wp_ai_brídge_settings', $ignorable_alias, str_repeat( 'column_name_', 20 ), 'WP_AI_BRIDGE_ABSENT_' . $prefix, 'alloptions', 'notoptions', 'blacklist_keys', 'comment_whitelist', 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'permalink_structure' );
	foreach ( $blocked as $blocked_index => $protected ) {
		$calls = 0;
		$sentinel = static function ( $value = false ) use ( &$calls ) {
			// Authority may legitimately read the Settings control row. Count
			// target observations/setters rather than that prerequisite read.
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
				if ( ( WP_AI_Bridge\Abilities\Native_Option_Write_Abilities::class === ( $frame['class'] ?? '' ) && 'observe' === ( $frame['function'] ?? '' ) ) || in_array( $frame['function'] ?? '', array( 'add_option', 'update_option', 'delete_option' ), true ) ) { ++$calls; break; }
			}
			return $value;
		};
		foreach ( array( 'pre_option_', 'pre_add_option_', 'pre_update_option_' ) as $hook ) { add_filter( $hook . $protected, $sentinel, 999 ); }
		try {
			wpai199n_error( wpai199nw_call( wpai199nw_request( 'update', $protected, 'must never reach native target' ) ), 'Protected/owning-API target was accepted at blocked-name index ' . $blocked_index . '.' );
			wpai199n_assert( 0 === $calls, 'Protected/alias target reached native getter/setter callbacks at blocked-name index ' . $blocked_index . '.' );
		} finally {
			foreach ( array( 'pre_option_', 'pre_add_option_', 'pre_update_option_' ) as $hook ) { remove_filter( $hook . $protected, $sentinel, 999 ); }
		}
	}
	wpai199n_assert( true === add_option( $names[4], 'exact stored alias target', '', false ), 'Cannot create Unicode collating alias fixture.' );
	$alias = $prefix . '_cafe';
	$collates = $wpdb->get_var( $wpdb->prepare( 'SELECT option_name = %s FROM %i WHERE BINARY option_name = BINARY %s', $alias, $wpdb->options, $names[4] ) );
	wpai199n_assert( '1' === (string) $collates, 'Native fixture collation does not expose the required Unicode/case alias.' );
	$alias_calls = 0;
	$alias_sentinel = static function ( $value = false ) use ( &$alias_calls ) { ++$alias_calls; return $value; };
	add_filter( 'pre_option_' . $alias, $alias_sentinel );
	try {
		wpai199n_error( wpai199nw_call( wpai199nw_request( 'update', $alias, 'wrong physical spelling' ) ), 'Non-exact Unicode/case storage alias was mutated.' );
		wpai199n_assert( 0 === $alias_calls && 'exact stored alias target' === get_option( $names[4] ), 'Collation alias reached a native target or changed stored bytes.' );
	} finally { remove_filter( 'pre_option_' . $alias, $alias_sentinel ); }

	// Facade begin can succeed before the outer Ability audit/output gate fails.
	// Only its newly allocated input may be discarded, even after revocation.
	$input_store = Private_Transfer_Session::for_native_options_write( new Permissions( $settings ), 'site' );
	$input_root = ( new ReflectionMethod( $input_store, 'root' ) )->invoke( $input_store );
	wpai199n_assert( is_string( $input_root ), 'Cannot locate exact private native input fixture root.' );
	foreach ( array( 'false', 'transform', 'throw', 'revoke' ) as $begin_failure ) {
		$before_files = glob( $input_root . '/*' );
		$before_audit = get_option( Mutation_Log::OPTION_NAME, array() );
		$before_flags = get_option( Settings::OPTION_NAME );
		$before_value = get_option( $name );
		$before_journals = wpai199nw_journals_before();
		$before_blog = get_current_blog_id();
		$before_client = WP_AI_Bridge\Auth\OAuth_Server::authenticated_mcp_client_id();
		$write_seen = 0;
		$allocated_before_failure = array();
		$begin_error_secret = 'inert-input-audit-secret-' . bin2hex( random_bytes( 6 ) );
		$begin_veto = static function ( $value, $old ) use ( $begin_failure, $begin_error_secret, $input_root, $before_files, &$allocated_before_failure ) {
			$allocated_before_failure = array_values( array_diff( glob( $input_root . '/*.meta' ), $before_files ) );
			if ( 'false' === $begin_failure ) { return $old; }
			if ( 'throw' === $begin_failure ) { throw new RuntimeException( $begin_error_secret ); }
			if ( 'revoke' === $begin_failure ) { wpai199nw_grants( false ); }
			if ( 'transform' === $begin_failure ) { $value[0]['event_ref'] = bin2hex( random_bytes( 8 ) ); }
			return $value;
		};
		$begin_observer = static function ( $option ) use ( &$write_seen ) { if ( Mutation_Log::OPTION_NAME === $option ) { ++$write_seen; } };
		add_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $begin_veto, PHP_INT_MAX, 2 );
		add_action( 'updated_option', $begin_observer, PHP_INT_MAX, 1 );
		try {
			$failed_begin = wpai199nw_call( array( 'action' => 'input_begin', 'scope' => 'site', 'total_bytes' => 137, 'sha256' => hash( 'sha256', 'inert bytes not uploaded yet' ) ) );
			wpai199n_assert( 1 === count( $allocated_before_failure ), 'Outer input-begin regression did not observe a successfully allocated native lease before its audit gate.' );
			wpai199n_error( $failed_begin, 'Failed outer audit/authority gate exposed newly allocated inert input.' );
			wpai199nw_public_private( $failed_begin, array( $name, $begin_error_secret, basename( $allocated_before_failure[0], '.meta' ) ) );
			$failed_data = $failed_begin->get_error_data();
			wpai199n_assert( ! is_array( $failed_data ) || ! array_key_exists( 'transfer_id', $failed_data ), 'Failed inert input begin returned a usable private ID.' );
			if ( 'transform' === $begin_failure ) { wpai199n_assert( 1 === $write_seen && $before_audit !== get_option( Mutation_Log::OPTION_NAME ), 'Transformed input-begin audit did not exercise an actual successful changed log write.' ); }
			if ( 'false' === $begin_failure ) { wpai199n_assert( 0 === $write_seen && $before_audit === get_option( Mutation_Log::OPTION_NAME ), 'False input-begin veto did not leave the original log untouched.' ); }
			$new_files = array_diff( glob( $input_root . '/*' ), $before_files );
			$new_lease_files = array_filter( $new_files, static function ( $path ) { return 1 === preg_match( '/^[a-f0-9]{64}\.(meta(?:\.state\.[01])?|bytes|scratch|claim|tombstone)$/D', basename( $path ) ); } );
			wpai199n_assert( array() === array_values( $new_lease_files ), 'Failed outer input-begin gate left new private bytes/checkpoints/reservation.' );
			wpai199n_assert( $before_journals === wpai199nw_journals_before() && $before_value === get_option( $name ), 'Inert input failure deleted/created operation fences or affected a native target.' );
		} finally {
			remove_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $begin_veto, PHP_INT_MAX );
			remove_action( 'updated_option', $begin_observer, PHP_INT_MAX );
			wp_set_current_user( $actor );
			wpai199n_restore_option( Settings::OPTION_NAME, $before_flags );
			// Negative old-candidate reproduction can strand an undisclosed ID.
			// Release only exact new native lease coordinators from this call.
			foreach ( array_diff( glob( $input_root . '/*.meta' ), $before_files ) as $orphan ) {
				if ( 1 === preg_match( '/^([a-f0-9]{64})\.meta$/D', basename( $orphan ), $match ) ) { wpai199nw_call( array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => $match[1] ) ); }
			}
			wpai199n_restore_option( Mutation_Log::OPTION_NAME, $before_audit );
			wpai199n_assert( $before_flags === get_option( Settings::OPTION_NAME ) && $before_audit === get_option( Mutation_Log::OPTION_NAME ) && $before_value === get_option( $name ) && $actor === get_current_user_id() && $before_blog === get_current_blog_id() && $before_client === WP_AI_Bridge\Auth\OAuth_Server::authenticated_mcp_client_id(), 'Input-begin failure fixture did not restore exact authority/audit/value/caller state.' );
		}
	}

	// Complete staged canonical input exceeds the real inline request budget.
	$large = str_repeat( "native\x00سلام", 250000 );
	$document = wpai199n_ok( Native_Option_Value::encode( $large, 16 * 1024 * 1024 ), 'Cannot encode complete multi-MiB native input.' );
	wpai199n_assert( $document['bytes'] > 4 * 1024 * 1024 && $document['bytes'] > Bounded_Payload::response_limit(), 'Large staged fixture must exceed four MiB and inline policy.' );
	$large_request = wpai199nw_request( 'update', $names[3], '' );
	$large_request['value_source'] = array( 'value_json' => $document['json'] );
	wpai199n_error( wpai199nw_call( $large_request ), 'Oversized inline native document bypassed current request policy.' );
	wpai199n_assert( false === get_option( $names[3] ), 'Rejected inline document partially changed a native target.' );
	$source = wpai199nw_stage( $document['json'] );
	$inputs[] = $source['transfer_id'];
	$input_api = new Native_Option_Inputs( new Permissions( $settings ), 'site' );
	$other_user = wp_insert_user( array( 'user_login' => $prefix . '_other', 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) );
	wpai199n_assert( is_int( $other_user ) && $other_user > 0, 'Cannot create native input owner-isolation administrator.' );
	wp_set_current_user( $other_user );
	try {
		wpai199n_error( wpai199nw_call( array( 'action' => 'input_status', 'scope' => 'site', 'transfer_id' => $source['transfer_id'] ) ), 'Equally privileged wrong owner accessed native input.' );
		$wrong_owner = wpai199n_ok( wpai199nw_status( $update['operation_id'] ), 'Wrong-owner journal lookup failed its bounded result.' );
		wpai199n_assert( 'not_recorded' === $wrong_owner['state'] && null === $wrong_owner['receipt'], 'Wrong owner exposed private native journal names/outcomes.' );
	} finally { wp_set_current_user( $actor ); }
	wpai199n_error( ( new Private_Transfer_Session( new Permissions( $settings ) ) )->status( $source['transfer_id'] ), 'Generic private transfer authority accessed native-write input.' );
	wpai199n_error( Private_Transfer_Session::for_database( new Permissions( $settings ) )->status( $source['transfer_id'] ), 'Typed database purpose consumed native-write input.' );
	wpai199n_error( ( new Native_Option_Snapshots( new Permissions( $settings ), 'site' ) )->status( $source['transfer_id'] ), 'Native read purpose consumed native-write input.' );
	$bad_source = $large_request;
	$bad_source['operation_id'] .= '-bad-hash';
	$bad_source['value_source'] = array_replace( $source, array( 'sha256' => str_repeat( '0', 64 ) ) );
	wpai199n_error( wpai199nw_call( $bad_source ), 'Mismatched staged SHA reached native dispatch.' );
	$bad_source['operation_id'] .= '-size';
	$bad_source['value_source'] = array_replace( $source, array( 'total_bytes' => $source['total_bytes'] - 1 ) );
	wpai199n_error( wpai199nw_call( $bad_source ), 'Mismatched staged size reached native dispatch.' );
	wpai199n_assert( false === get_option( $names[3] ), 'Invalid staged descriptor partially affected target.' );
	$large_request['operation_id'] .= '-staged';
	$large_request['value_source'] = $source;
	$large_result = wpai199nw_returned( wpai199nw_call( $large_request ), true );
	wpai199n_assert( $large === get_option( $names[3] ) && hash( 'sha256', $large ) === hash( 'sha256', get_option( $names[3] ) ) && $document['sha256'] === $large_result['receipt']['observed_after_sha256'], 'Full staged native value or observed identity was truncated/changed.' );
	wpai199n_ok( wpai199nw_call( array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => $source['transfer_id'] ) ), 'Native input release failed.' );
	$inputs = array();
	$released_request = $large_request;
	$released_request['operation_id'] .= '-released';
	wpai199n_error( wpai199nw_call( $released_request ), 'Released source was materialized for a new mutation.' );
	wpai199n_assert( $large === get_option( $names[3] ), 'Released-source rejection changed target bytes.' );
	$read_snapshot = wpai199n_ok( ( new Native_Option_Snapshots( new Permissions( $settings ), 'site' ) )->create( wpai199nw_source( 'read-purpose-only' )['value_json'] ), 'Cannot create wrong-purpose source fixture.' );
	$read_id = $read_snapshot['transfer_id'];
	$wrong_purpose = wpai199nw_request( 'update', $name, '' );
	$wrong_purpose['value_source'] = array( 'transfer_id' => $read_id, 'sha256' => $read_snapshot['sha256'], 'total_bytes' => $read_snapshot['total_bytes'] );
	wpai199n_error( wpai199nw_call( $wrong_purpose ), 'Native read snapshot was accepted as writable value input.' );
	wpai199n_error( Private_Transfer_Session::for_native_options_write( new Permissions( $settings ), 'site' )->status( $read_id ), 'Native write factory inherited native-read purpose access.' );

	// Integrity verification is not semantic admission. A fully verified
	// opaque document can still fail closed decoding before any target call.
	$malformed_source = wpai199nw_stage( 'not a canonical native option value document' );
	$inputs[] = $malformed_source['transfer_id'];
	$malformed_request = wpai199nw_request( 'update', $name, '' );
	$malformed_request['value_source'] = $malformed_source;
	$before_decode_calls = $setter_calls;
	$before_decode_value = get_option( $name );
	wpai199n_error( wpai199nw_call( $malformed_request ), 'Verified opaque noncanonical input reached a native setter.' );
	wpai199n_assert( $before_decode_calls === $setter_calls && $before_decode_value === get_option( $name ), 'Semantic decode failure had a partial native target effect.' );
	wpai199n_ok( wpai199nw_call( array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => $malformed_source['transfer_id'] ) ), 'Malformed verified input cleanup failed.' );
	$inputs = array();

	// A post-persistence callback throw must remain unknown, including retries.
	$throw_secret = 'provider-exception-secret-' . bin2hex( random_bytes( 8 ) );
	$after_write = static function ( $option ) use ( $name, $throw_secret ) { if ( $name === $option ) { throw new RuntimeException( $throw_secret ); } };
	add_action( 'updated_option', $after_write, 10, 1 );
	$post_throw = wpai199nw_request( 'update', $name, 'stored before callback throw' );
	$before_calls = $setter_calls;
	$stack = $wp_current_filter;
	try { $thrown_result = wpai199nw_call( $post_throw ); } finally { remove_action( 'updated_option', $after_write, 10 ); }
	wpai199nw_public_private( is_wp_error( $thrown_result ) ? $thrown_result : array(), array( $throw_secret ) );
	wpai199n_assert( 'stored before callback throw' === get_option( $name ) && $before_calls + 1 === $setter_calls && $stack === $wp_current_filter, 'Postwrite throw lost native effects/one-dispatch/hook recovery.' );
	$unknown = wpai199nw_receipt( wpai199nw_status( $post_throw['operation_id'] ) );
	wpai199n_assert( 'unknown' === $unknown['receipt']['outcome'] && true === $unknown['receipt']['callback_threw'], 'Postwrite throw was described as not applied or fully durable.' );
	wpai199nw_call( $post_throw );
	wpai199n_assert( $before_calls + 1 === $setter_calls, 'Unknown same-ID retry resent native callback.' );

	// Required audit failure after native effect is a reporting failure.
	foreach ( array( 'false', 'transform', 'throw' ) as $audit_mode ) {
		$audit_before = get_option( Mutation_Log::OPTION_NAME, array() );
		$veto = static function ( $value, $old ) use ( $audit_mode, $throw_secret ) {
			if ( 'throw' === $audit_mode ) { throw new RuntimeException( $throw_secret ); }
			if ( 'false' === $audit_mode ) { return $old; }
			$value[0]['event_ref'] = bin2hex( random_bytes( 8 ) );
			return $value;
		};
		add_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $veto, PHP_INT_MAX, 2 );
		$audit_request = wpai199nw_request( 'update', $name, 'effect despite audit ' . $audit_mode );
		try { $audit_result = wpai199nw_call( $audit_request ); } finally { remove_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $veto, PHP_INT_MAX ); }
		wpai199n_assert( 'effect despite audit ' . $audit_mode === get_option( $name ), 'Audit failure rolled back or hid a native provider effect.' );
		wpai199n_error( $audit_result, 'Required after-effect audit failure was silently reported as successful.' );
		wpai199nw_public_private( $audit_result, array( $name, $throw_secret ) );
		$historical = wpai199nw_receipt( wpai199nw_status( $audit_request['operation_id'] ) );
		wpai199n_assert( 'not_dispatched' !== $historical['receipt']['outcome'], 'After-effect audit failure was labeled not applied.' );
		$count = $setter_calls;
		wpai199nw_call( $audit_request );
		wpai199n_assert( $count === $setter_calls, 'After-effect reporting retry resent a native setter.' );
		wpai199n_restore_option( Mutation_Log::OPTION_NAME, $audit_before );
	}

	wpai199nw_grants( true, false );
	wpai199n_error( wpai199nw_call( wpai199nw_request( 'delete', $name ) ), 'Native delete bypassed Users & Destructive consent.' );
	wpai199nw_grants();
	wpai199nw_returned( wpai199nw_call( wpai199nw_request( 'delete', $names[1] ) ), true );
	wpai199nw_returned( wpai199nw_call( wpai199nw_request( 'delete', $names[1] ) ), false );
	wpai199nw_grants( false );
	wpai199nw_receipt( wpai199nw_status( $update['operation_id'] ) );
	wpai199n_error( wpai199nw_call( wpai199nw_request( 'update', $name, 'revoked' ) ), 'Current write revocation was bypassed.' );
	$flags = $settings->all();
	$flags[ Settings::GROUP_NATIVE_OPTIONS_READ ] = 0;
	update_option( Settings::OPTION_NAME, $settings->sanitize( $flags ), false );
	wpai199n_error( wpai199nw_status( $update['operation_id'] ), 'Historical status bypassed current sensitive-read revocation.' );
	wpai199nw_grants();
	$activity = get_option( Mutation_Log::OPTION_NAME, array() );
	wpai199nw_public_private( $activity, array( $name, $throw_secret, 'stored before callback throw', $large ) );
	$new_journals = array_diff( wpai199nw_journals_before(), $journals );
	wpai199n_assert( count( $new_journals ) > 1, 'Native journal never persisted durable operation identities.' );
	wpai199n_assert( $legacy_receipt_count === WP_AI_Bridge\Support\Create_Claim_Store::count(), 'Native journal consumed the unrelated lifetime create-receipt quota.' );
	foreach ( $new_journals as $journal_name ) {
		$journal = json_decode( get_option( $journal_name ), true );
		wpai199n_assert( ! isset( $journal['admission_domain'] ) && ! str_contains( wp_json_encode( $journal ), 'stored before callback throw' ), 'Native journal consumed generic lifetime admission or stored a setting value.' );
	}
	echo 'PASS: native Options mutations, callbacks, staged inputs and durable historical outcomes (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
} finally {
	wp_set_current_user( $actor );
	foreach ( $hooks as $hook ) { remove_filter( $hook[0], $hook[1], $hook[2] ); }
	wpai199nw_grants();
	foreach ( $inputs as $id ) { wpai199nw_call( array( 'action' => 'input_release', 'scope' => 'site', 'transfer_id' => $id ) ); }
	if ( '' !== $read_id ) { ( new Native_Option_Snapshots( new Permissions( new Settings() ), 'site' ) )->release( $read_id ); }
	foreach ( $names as $fixture_name ) { delete_option( $fixture_name ); }
	if ( $other_user > 0 ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $other_user ); }
	wpai199nw_cleanup_journals( $journals );
	wpai199n_restore_option( Settings::OPTION_NAME, $ordinary, null !== $ordinary );
	wpai199n_restore_option( Mutation_Log::OPTION_NAME, $log, null !== $log );
}
