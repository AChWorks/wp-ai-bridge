<?php
/** Security-critical real WordPress coverage for Issue #34 / Contract Revision 3. */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "WordPress is not loaded.\n" ); exit( 1 ); }

$failures = array();
$ok = static function ( $condition, $message ) use ( &$failures ) { if ( ! $condition ) { $failures[] = $message; } };
$settings_class = 'WP_Native_Builder_Bridge\\Support\\Settings';
$original = class_exists( $settings_class ) ? get_option( $settings_class::OPTION_NAME, null ) : null;
$post_id = 0;
$revision_id = 0;
$registered = array();
$filters = array();
$actions = array();
$guard_filter = static function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) use ( &$filters ) { add_filter( $hook, $callback, $priority, $accepted_args ); $filters[] = array( $hook, $callback, $priority ); };
$guard_action = static function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) use ( &$actions ) { add_action( $hook, $callback, $priority, $accepted_args ); $actions[] = array( $hook, $callback, $priority ); };
$physical = static function ( $post_id, $key ) { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s ORDER BY meta_id", $post_id, $key ) ); };
$physical_map = static function ( $post_id, $key ) use ( $physical ) { $map = array(); foreach ( $physical( $post_id, $key ) as $row ) { $map[ (int) $row->meta_id ] = null === $row->meta_value ? '__SQL_NULL__' : (string) $row->meta_value; } ksort( $map ); return $map; };
$ordinary_matches = static function ( $meta_id, $expected_raw ) { global $wpdb; return 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_id = %d AND meta_value = %s", $meta_id, $expected_raw ) ); };
$set_raw = static function ( $post_id, $meta_id, $raw_value ) { global $wpdb; $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $raw_value ), array( 'meta_id' => (int) $meta_id ), array( '%s' ), array( '%d' ) ); wp_cache_delete( (int) $post_id, 'post_meta' ); };
$raw_marker = static function ( $value ) { $raw = maybe_serialize( $value ); if ( null === $raw ) { return '__SQL_NULL__'; } if ( false === $raw ) { return ''; } if ( true === $raw ) { return '1'; } return is_string( $raw ) ? $raw : (string) $raw; };
$watched = array();
$events = array();
$mirror = array();

try {
	$ok( class_exists( $settings_class ) && function_exists( 'wp_get_ability' ), 'Issue #34 prerequisites are unavailable.' );
	$read = wp_get_ability( 'wp-ai-bridge/post-meta-read' );
	$update = wp_get_ability( 'wp-ai-bridge/post-meta-update' );
	$delete = wp_get_ability( 'wp-ai-bridge/post-meta-delete' );
	if ( ! $read || ! $update || ! $delete ) { throw new RuntimeException( 'Issue #34 Abilities are unavailable.' ); }
	$settings = new $settings_class();
	$enabled = $settings->defaults();
	$enabled[ $settings_class::GROUP_ADVANCED_METADATA ] = 1;
	$enabled[ $settings_class::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( $settings_class::OPTION_NAME, $enabled, false );
	register_post_type( 'wpai_meta_security', array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'supports' => array( 'title', 'revisions' ) ) );
	$post_id = wp_insert_post( array( 'post_type' => 'wpai_meta_security', 'post_status' => 'draft', 'post_title' => 'Issue 34 Rev3 fixture' ), true );
	if ( is_wp_error( $post_id ) || $post_id < 1 ) { throw new RuntimeException( 'Fixture post creation failed.' ); }

	/* Observer mirror used to verify compensation lifecycle semantics. */
	$record_added = static function ( $meta_id, $object_id, $meta_key, $meta_value ) use ( &$watched, &$events, &$mirror, $post_id, $raw_marker ) { if ( (int) $object_id !== (int) $post_id || empty( $watched[ $meta_key ] ) ) { return; } $raw = $raw_marker( $meta_value ); $mirror[ $meta_key ][ (int) $meta_id ] = $raw; $events[ $meta_key ][] = 'added:' . $raw; };
	$record_updated = static function ( $meta_id, $object_id, $meta_key, $meta_value ) use ( &$watched, &$events, &$mirror, $post_id, $raw_marker ) { if ( (int) $object_id !== (int) $post_id || empty( $watched[ $meta_key ] ) ) { return; } $raw = $raw_marker( $meta_value ); $mirror[ $meta_key ][ (int) $meta_id ] = $raw; $events[ $meta_key ][] = 'updated:' . $raw; };
	$record_deleted = static function ( $meta_ids, $object_id, $meta_key, $meta_value ) use ( &$watched, &$events, &$mirror, $post_id, $raw_marker ) { if ( (int) $object_id !== (int) $post_id || empty( $watched[ $meta_key ] ) ) { return; } $raw = $raw_marker( $meta_value ); foreach ( (array) $meta_ids as $meta_id ) { unset( $mirror[ $meta_key ][ (int) $meta_id ] ); } $events[ $meta_key ][] = 'deleted:' . $raw; };
	$guard_action( 'added_post_meta', $record_added, 20, 4 );
	$guard_action( 'updated_post_meta', $record_updated, 20, 4 );
	$guard_action( 'deleted_post_meta', $record_deleted, 20, 4 );

	/* F-001/F-004: canonical backslashes plus singular/plural secret morphology. */
	$literal = 'literal\\path';
	add_post_meta( $post_id, wp_slash( $literal ), 'old', true );
	$literal_read = $read->execute( array( 'post_id' => $post_id, 'key' => $literal ) );
	$literal_update = $update->execute( array( 'post_id' => $post_id, 'key' => $literal, 'value_json' => wp_json_encode( 'new' ), 'expected_state_hash' => $literal_read['items'][0]['state_hash'] ) );
	$ok( ! is_wp_error( $literal_update ) && 'new' === get_post_meta( $post_id, $literal, true ), 'Literal-backslash key identity regressed.' );
	$secrets = array(
		'sessionToken', 'session_token', 'session-token', 'session.token', 'SESSION__TOKEN', 'session\\token',
		'sessionTokens', 'session_tokens', 'session-tokens', 'SESSION__TOKENS', 'session\\tokens',
		'identityToken', 'identityTokens', 'identity_tokens', 'idToken', 'id_token', 'idtoken', 'idTokens', 'idtokens',
		'jwtToken', 'jwt_token', 'jwttoken', 'jwtTokens', 'jwttokens', 'securityTokens', 'csrfTokens',
		'clientSecret', 'clientSecrets', 'client_secrets', 'consumerSecrets',
		'accessToken', 'accessTokens', 'apiKeys', 'privateKeys', 'applicationPasswords',
	);
	foreach ( $secrets as $key ) {
		add_post_meta( $post_id, wp_slash( $key ), 'secret', true );
		$read_input = array( 'post_id' => $post_id, 'key' => $key, 'include_values' => true );
		$update_input = array( 'post_id' => $post_id, 'key' => $key, 'value_json' => wp_json_encode( 'replacement' ), 'expected_state_hash' => str_repeat( '0', 64 ) );
		$delete_input = array( 'post_id' => $post_id, 'key' => $key, 'expected_state_hash' => str_repeat( '0', 64 ) );
		$ok( false === $read->check_permissions( $read_input ), 'Sensitive read permission passed: ' . $key );
		$ok( false === $update->check_permissions( $update_input ), 'Sensitive update permission passed: ' . $key );
		$ok( false === $delete->check_permissions( $delete_input ), 'Sensitive delete permission passed: ' . $key );
		$ok( is_wp_error( $read->execute( $read_input ) ), 'Sensitive read execution was not denied: ' . $key );
		$ok( is_wp_error( $update->execute( $update_input ) ), 'Sensitive update execution was not denied: ' . $key );
		$ok( is_wp_error( $delete->execute( $delete_input ) ), 'Sensitive delete execution was not denied: ' . $key );
	}
	$secret_listing = $read->execute( array( 'post_id' => $post_id ) );
	$listed_keys = is_wp_error( $secret_listing ) ? array() : array_column( $secret_listing['items'], 'key' );
	foreach ( $secrets as $key ) { $ok( ! in_array( $key, $listed_keys, true ), 'Sensitive key leaked through discovery: ' . $key ); }
	$design_token = 'design_token'; add_post_meta( $post_id, $design_token, 'blue', true ); $ok( ! is_wp_error( $read->execute( array( 'post_id' => $post_id, 'key' => $design_token ) ) ), 'Unrelated design_token was overblocked.' );

	/* F-002: revisions canonicalize to parent before subtype metadata authorization. */
	$revision_key = '_wpai_revision_locked';
	register_post_meta( 'wpai_meta_security', $revision_key, array( 'type' => 'string', 'single' => true, 'auth_callback' => '__return_false' ) ); $registered[] = $revision_key;
	add_post_meta( $post_id, $revision_key, 'locked', true );
	$revision_id = wp_insert_post( array( 'post_type' => 'revision', 'post_parent' => $post_id, 'post_status' => 'inherit', 'post_title' => 'rev' ), true );
	$ok( ! is_wp_error( $revision_id ) && wp_is_post_revision( $revision_id ), 'Revision fixture failed.' );
	if ( ! is_wp_error( $revision_id ) ) { $ok( is_wp_error( $read->execute( array( 'post_id' => $revision_id, 'key' => $revision_key ) ) ), 'Revision bypassed parent metadata authorization.' ); }

	/* F-003/F-008: physical state ignores registered defaults and virtual get_post_metadata reads. */
	$default_key = '_wpai_default';
	register_post_meta( 'wpai_meta_security', $default_key, array( 'type' => 'string', 'single' => true, 'default' => 'registered-default', 'auth_callback' => '__return_true' ) ); $registered[] = $default_key;
	$before = $read->execute( array( 'post_id' => $post_id, 'key' => $default_key ) );
	$ok( ! is_wp_error( $before ) && 0 === $before['items'][0]['count'], 'Registered default was mistaken for a physical row.' );
	$created = $update->execute( array( 'post_id' => $post_id, 'key' => $default_key, 'value_json' => wp_json_encode( 'registered-default' ), 'expected_state_hash' => $before['items'][0]['state_hash'] ) );
	$ok( ! is_wp_error( $created ) && 1 === $created['count'] && $created['state_hash'] !== $before['items'][0]['state_hash'], 'Physical default row identity failed.' );
	$virtual_key = '_wpai_virtual';
	add_post_meta( $post_id, $virtual_key, 'one', false ); add_post_meta( $post_id, $virtual_key, 'two', false );
	$virtual = static function ( $check, $object_id, $meta_key ) use ( $post_id, $virtual_key ) { return (int) $object_id === (int) $post_id && (string) $meta_key === $virtual_key ? array( 'virtual-one' ) : $check; };
	$guard_filter( 'get_post_metadata', $virtual, 10, 5 );
	$virtual_read = $read->execute( array( 'post_id' => $post_id, 'key' => $virtual_key ) );
	$ok( ! is_wp_error( $virtual_read ) && 2 === $virtual_read['items'][0]['count'], 'Virtual metadata filter hid physical row cardinality.' );
	$virtual_update = $update->execute( array( 'post_id' => $post_id, 'key' => $virtual_key, 'value_json' => wp_json_encode( 'replace' ), 'expected_state_hash' => $virtual_read['items'][0]['state_hash'] ) );
	$ok( is_wp_error( $virtual_update ) && 'post_meta_multiple_values_unsupported' === $virtual_update->get_error_code(), 'Virtual read permitted ambiguous physical mutation.' );
	remove_filter( 'get_post_metadata', $virtual, 10 );

	/* F-005/F-009: opaque/non-lossless state is neither updateable nor deletable. */
	$opaque_key = '_wpai_opaque'; $opaque = array( 'nested' => (object) array( 'keep' => true ) ); add_post_meta( $post_id, $opaque_key, $opaque, true );
	$opaque_read = $read->execute( array( 'post_id' => $post_id, 'key' => $opaque_key ) );
	$opaque_update = $update->execute( array( 'post_id' => $post_id, 'key' => $opaque_key, 'value_json' => wp_json_encode( array( 'nested' => array( 'replace' => true ) ) ), 'expected_state_hash' => $opaque_read['items'][0]['state_hash'] ) );
	$opaque_delete = $delete->execute( array( 'post_id' => $post_id, 'key' => $opaque_key, 'expected_state_hash' => $opaque_read['items'][0]['state_hash'] ) );
	$ok( is_wp_error( $opaque_update ) && is_wp_error( $opaque_delete ) && is_object( get_post_meta( $post_id, $opaque_key, true )['nested'] ), 'Opaque metadata did not fail closed.' );

	/* F-006: ordinary collation-equal but byte-different values must fail the update CAS. */
	foreach ( array( '_wpai_case_cas' => array( 'old', 'OLD' ), '_wpai_accent_cas' => array( 'café', 'cafe' ) ) as $cas_key => $pair ) {
		add_post_meta( $post_id, $cas_key, $pair[0], true );
		$cas_read = $read->execute( array( 'post_id' => $post_id, 'key' => $cas_key ) );
		$cas_rows = $physical( $post_id, $cas_key );
		$cas_id = (int) $cas_rows[0]->meta_id;
		$cas_race = null;
		$cas_race = static function ( $check, $object_id, $meta_key ) use ( &$cas_race, $post_id, $cas_key, $cas_id, $pair, $set_raw ) { if ( (int) $object_id === (int) $post_id && (string) $meta_key === $cas_key ) { remove_filter( 'update_post_metadata', $cas_race, 1 ); $set_raw( $post_id, $cas_id, $pair[1] ); } return $check; };
		$guard_filter( 'update_post_metadata', $cas_race, 1, 5 );
		$cas_result = $update->execute( array( 'post_id' => $post_id, 'key' => $cas_key, 'value_json' => wp_json_encode( 'bridge' ), 'expected_state_hash' => $cas_read['items'][0]['state_hash'] ) );
		$cas_after = $physical( $post_id, $cas_key );
		$ok( $ordinary_matches( $cas_id, $pair[0] ), 'CAS fixture is not collation-equivalent under the integration database: ' . $cas_key );
		$ok( is_wp_error( $cas_result ) && 'stale_post_meta_conflict' === $cas_result->get_error_code() && $pair[1] === $cas_after[0]->meta_value, 'Byte-exact update CAS overwrote a collation-equivalent concurrent value: ' . $cas_key );
	}

	/* F-006: trailing-space collation equivalence must not pass the delete CAS. */
	$trail_key = '_wpai_trailing_delete'; add_post_meta( $post_id, $trail_key, 'trail', true ); $trail_read = $read->execute( array( 'post_id' => $post_id, 'key' => $trail_key ) ); $trail_rows = $physical( $post_id, $trail_key ); $trail_id = (int) $trail_rows[0]->meta_id;
	$trail_race = null; $trail_race = static function ( $check, $object_id, $meta_key ) use ( &$trail_race, $post_id, $trail_key, $trail_id, $set_raw ) { if ( (int) $object_id === (int) $post_id && (string) $meta_key === $trail_key ) { remove_filter( 'delete_post_metadata', $trail_race, 1 ); $set_raw( $post_id, $trail_id, 'trail ' ); } return $check; };
	$guard_filter( 'delete_post_metadata', $trail_race, 1, 5 );
	$trail_result = $delete->execute( array( 'post_id' => $post_id, 'key' => $trail_key, 'expected_state_hash' => $trail_read['items'][0]['state_hash'] ) );
	$trail_after = $physical( $post_id, $trail_key );
	$ok( $ordinary_matches( $trail_id, 'trail' ), 'Trailing-space CAS fixture is not collation-equivalent under the integration database.' );
	$ok( is_wp_error( $trail_result ) && 'stale_post_meta_conflict' === $trail_result->get_error_code() && 'trail ' === $trail_after[0]->meta_value, 'Byte-exact delete CAS removed a trailing-space concurrent value.' );

	/* F-006: compensation itself must not overwrite a collation-equivalent post-write change. */
	$comp_key = '_wpai_comp_binary'; add_post_meta( $post_id, $comp_key, 'old', true ); $comp_read = $read->execute( array( 'post_id' => $post_id, 'key' => $comp_key ) ); $comp_rows = $physical( $post_id, $comp_key ); $comp_id = (int) $comp_rows[0]->meta_id;
	$comp_race = null; $comp_race = static function ( $meta_id, $object_id, $meta_key, $meta_value ) use ( &$comp_race, $post_id, $comp_key, $comp_id, $set_raw ) { if ( (int) $object_id === (int) $post_id && (int) $meta_id === $comp_id && (string) $meta_key === $comp_key && 'bridge' === $meta_value ) { remove_action( 'updated_post_meta', $comp_race, 1 ); $set_raw( $post_id, $comp_id, 'BRIDGE' ); } };
	$guard_action( 'updated_post_meta', $comp_race, 1, 4 );
	$comp_result = $update->execute( array( 'post_id' => $post_id, 'key' => $comp_key, 'value_json' => wp_json_encode( 'bridge' ), 'expected_state_hash' => $comp_read['items'][0]['state_hash'] ) );
	$comp_after = $physical( $post_id, $comp_key );
	$ok( $ordinary_matches( $comp_id, 'bridge' ), 'Compensation fixture is not collation-equivalent under the integration database.' );
	$ok( is_wp_error( $comp_result ) && 'post_meta_compensation_failed' === $comp_result->get_error_code() && 'BRIDGE' === $comp_after[0]->meta_value, 'Compensation overwrote a byte-different concurrent value.' );

	/* F-011: SQL NULL is distinct from empty string and remains mutable through exact CAS. */
	global $wpdb;
	$null_key = '_wpai_sql_null';
	$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $null_key, 'meta_value' => null ), array( '%d', '%s', '%s' ) );
	$null_id = (int) $wpdb->insert_id; wp_cache_delete( $post_id, 'post_meta' );
	$null_read = $read->execute( array( 'post_id' => $post_id, 'key' => $null_key, 'include_values' => true ) );
	$null_rows = $physical( $post_id, $null_key );
	$ok( ! is_wp_error( $null_read ) && null === $null_rows[0]->meta_value && 'null' === $null_read['items'][0]['values'][0]['value_json'], 'SQL NULL was not preserved as a physical null value.' );
	$null_hash = $null_read['items'][0]['state_hash'];
	$set_raw( $post_id, $null_id, '' );
	$empty_same_id = $read->execute( array( 'post_id' => $post_id, 'key' => $null_key ) );
	$ok( $null_hash !== $empty_same_id['items'][0]['state_hash'], 'SQL NULL and empty string shared one state identity on the same meta ID.' );
	$set_raw( $post_id, $null_id, null );
	$null_again = $read->execute( array( 'post_id' => $post_id, 'key' => $null_key ) );
	$null_update = $update->execute( array( 'post_id' => $post_id, 'key' => $null_key, 'value_json' => wp_json_encode( 'filled' ), 'expected_state_hash' => $null_again['items'][0]['state_hash'] ) );
	$ok( ! is_wp_error( $null_update ) && 'filled' === get_post_meta( $post_id, $null_key, true ), 'SQL NULL exact update failed.' );
	$null_fresh = $read->execute( array( 'post_id' => $post_id, 'key' => $null_key ) );
	$null_delete = $delete->execute( array( 'post_id' => $post_id, 'key' => $null_key, 'expected_state_hash' => $null_fresh['items'][0]['state_hash'] ) );
	$ok( ! is_wp_error( $null_delete ) && empty( $physical( $post_id, $null_key ) ), 'SQL NULL-derived row could not complete update/delete lifecycle.' );

	/* F-011: Core-safe empty-like/scalar/serialized physical states remain editable and deletable. */
	$safe_values = array(
		'_wpai_empty_string' => array( '', '' ),
		'_wpai_zero_string'  => array( '0', '0' ),
		'_wpai_false'        => array( false, '' ),
		'_wpai_true'         => array( true, '1' ),
		'_wpai_integer'      => array( 7, '7' ),
		'_wpai_float'        => array( 1.5, '1.5' ),
		'_wpai_empty_array'  => array( array(), array() ),
		'_wpai_array'        => array( array( 'x' => 'y' ), array( 'x' => 'y' ) ),
	);
	foreach ( $safe_values as $safe_key => $fixture ) {
		add_post_meta( $post_id, $safe_key, $fixture[0], true );
		$safe_read = $read->execute( array( 'post_id' => $post_id, 'key' => $safe_key, 'include_values' => true ) );
		$safe_value = is_wp_error( $safe_read ) ? null : json_decode( $safe_read['items'][0]['values'][0]['value_json'], true );
		$ok( ! is_wp_error( $safe_read ) && $fixture[1] === $safe_value, 'Physical Core representation was wrong for ' . $safe_key );
		$safe_update = $update->execute( array( 'post_id' => $post_id, 'key' => $safe_key, 'value_json' => wp_json_encode( 'next-' . $safe_key ), 'expected_state_hash' => $safe_read['items'][0]['state_hash'] ) );
		$ok( ! is_wp_error( $safe_update ), 'Safe physical state was not editable: ' . $safe_key );
		$safe_fresh = $read->execute( array( 'post_id' => $post_id, 'key' => $safe_key ) );
		$safe_delete = $delete->execute( array( 'post_id' => $post_id, 'key' => $safe_key, 'expected_state_hash' => $safe_fresh['items'][0]['state_hash'] ) );
		$ok( ! is_wp_error( $safe_delete ) && empty( $physical( $post_id, $safe_key ) ), 'Safe physical state was not deletable: ' . $safe_key );
	}

	/* F-010: create uses Core sanitizer once and validates exact returned-row ownership. */
	$sanitize_key = '_wpai_sanitize_once'; $sanitize_count = 0;
	$sanitizer = static function ( $value ) use ( &$sanitize_count ) { ++$sanitize_count; return (string) $value . '-sanitized'; };
	register_post_meta( 'wpai_meta_security', $sanitize_key, array( 'type' => 'string', 'single' => true, 'auth_callback' => '__return_true', 'sanitize_callback' => $sanitizer ) ); $registered[] = $sanitize_key;
	$sanitize_read = $read->execute( array( 'post_id' => $post_id, 'key' => $sanitize_key ) );
	$sanitize_create = $update->execute( array( 'post_id' => $post_id, 'key' => $sanitize_key, 'value_json' => wp_json_encode( 'input' ), 'expected_state_hash' => $sanitize_read['items'][0]['state_hash'] ) );
	$sanitize_rows = $physical( $post_id, $sanitize_key );
	$ok( ! is_wp_error( $sanitize_create ) && 1 === $sanitize_count && 'input-sanitized' === $sanitize_rows[0]->meta_value, 'Create did not preserve one-pass Core sanitization semantics.' );

	$observer_key = '_wpai_create_observer'; $observer_read = $read->execute( array( 'post_id' => $post_id, 'key' => $observer_key ) );
	$observer_change = null; $observer_change = static function ( $meta_id, $object_id, $meta_key ) use ( &$observer_change, $post_id, $observer_key, $set_raw ) { if ( (int) $object_id === (int) $post_id && (string) $meta_key === $observer_key ) { remove_action( 'added_post_meta', $observer_change, 1 ); $set_raw( $post_id, (int) $meta_id, 'observer' ); } };
	$guard_action( 'added_post_meta', $observer_change, 1, 4 );
	$observer_result = $update->execute( array( 'post_id' => $post_id, 'key' => $observer_key, 'value_json' => wp_json_encode( 'bridge' ), 'expected_state_hash' => $observer_read['items'][0]['state_hash'] ) );
	$observer_rows = $physical( $post_id, $observer_key );
	$ok( is_wp_error( $observer_result ) && 'stale_post_meta_conflict' === $observer_result->get_error_code() && 1 === count( $observer_rows ) && 'observer' === $observer_rows[0]->meta_value, 'Create falsely owned or cleaned an observer-modified returned meta ID.' );

	$opaque_create_key = '_wpai_create_opaque_observer'; $opaque_create_read = $read->execute( array( 'post_id' => $post_id, 'key' => $opaque_create_key ) );
	$opaque_observer = null; $opaque_observer = static function ( $meta_id, $object_id, $meta_key ) use ( &$opaque_observer, $post_id, $opaque_create_key, $set_raw ) { if ( (int) $object_id === (int) $post_id && (string) $meta_key === $opaque_create_key ) { remove_action( 'added_post_meta', $opaque_observer, 1 ); $set_raw( $post_id, (int) $meta_id, serialize( (object) array( 'observer' => true ) ) ); } };
	$guard_action( 'added_post_meta', $opaque_observer, 1, 4 );
	$opaque_create_result = $update->execute( array( 'post_id' => $post_id, 'key' => $opaque_create_key, 'value_json' => wp_json_encode( 'bridge' ), 'expected_state_hash' => $opaque_create_read['items'][0]['state_hash'] ) );
	$opaque_create_rows = $physical( $post_id, $opaque_create_key );
	$ok( is_wp_error( $opaque_create_result ) && 'stale_post_meta_conflict' === $opaque_create_result->get_error_code() && 1 === count( $opaque_create_rows ) && is_object( maybe_unserialize( $opaque_create_rows[0]->meta_value ) ), 'Create cleanup deleted an opaque observer-modified returned meta ID.' );

	/* F-006/F-012: update rollback restores physical row and compensating lifecycle. */
	$update_key = '_wpai_same_update'; $watched[ $update_key ] = true; add_post_meta( $post_id, $update_key, 'old', true ); $events[ $update_key ] = array(); $update_read = $read->execute( array( 'post_id' => $post_id, 'key' => $update_key ) );
	$race_update = null; $race_update = static function ( $check, $object_id, $meta_key ) use ( &$race_update, &$mirror, $post_id, $update_key ) { if ( (int) $object_id === (int) $post_id && (string) $meta_key === $update_key ) { global $wpdb; remove_filter( 'update_post_metadata', $race_update, 1 ); $wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $update_key, 'meta_value' => 'old' ), array( '%d', '%s', '%s' ) ); $mirror[ $update_key ][ (int) $wpdb->insert_id ] = 'old'; wp_cache_delete( $post_id, 'post_meta' ); } return $check; };
	$guard_filter( 'update_post_metadata', $race_update, 1, 5 );
	$update_result = $update->execute( array( 'post_id' => $post_id, 'key' => $update_key, 'value_json' => wp_json_encode( 'bridge' ), 'expected_state_hash' => $update_read['items'][0]['state_hash'] ) );
	$update_rows = $physical( $post_id, $update_key ); ksort( $mirror[ $update_key ] );
	$ok( is_wp_error( $update_result ) && 'stale_post_meta_conflict' === $update_result->get_error_code() && 2 === count( $update_rows ) && 'old' === $update_rows[0]->meta_value && 'old' === $update_rows[1]->meta_value, 'Same-value update race changed concurrent state.' );
	$ok( array( 'updated:bridge', 'updated:old' ) === $events[ $update_key ], 'Update compensation lifecycle did not expose update then restoration.' );
	$ok( $mirror[ $update_key ] === $physical_map( $post_id, $update_key ), 'Update compensation left observer mirror inconsistent with physical rows.' );

	/* F-006/F-012: delete rollback restores original row and emits delete/add lifecycle. */
	$delete_key = '_wpai_same_delete'; $watched[ $delete_key ] = true; add_post_meta( $post_id, $delete_key, 'old', true ); $events[ $delete_key ] = array(); $delete_read = $read->execute( array( 'post_id' => $post_id, 'key' => $delete_key ) );
	$race_delete = null; $race_delete = static function ( $check, $object_id, $meta_key ) use ( &$race_delete, &$mirror, $post_id, $delete_key ) { if ( (int) $object_id === (int) $post_id && (string) $meta_key === $delete_key ) { global $wpdb; remove_filter( 'delete_post_metadata', $race_delete, 1 ); $wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $delete_key, 'meta_value' => 'old' ), array( '%d', '%s', '%s' ) ); $mirror[ $delete_key ][ (int) $wpdb->insert_id ] = 'old'; wp_cache_delete( $post_id, 'post_meta' ); } return $check; };
	$guard_filter( 'delete_post_metadata', $race_delete, 1, 5 );
	$delete_result = $delete->execute( array( 'post_id' => $post_id, 'key' => $delete_key, 'expected_state_hash' => $delete_read['items'][0]['state_hash'] ) );
	$delete_rows = $physical( $post_id, $delete_key ); ksort( $mirror[ $delete_key ] );
	$ok( is_wp_error( $delete_result ) && 'stale_post_meta_conflict' === $delete_result->get_error_code() && 2 === count( $delete_rows ) && 'old' === $delete_rows[0]->meta_value && 'old' === $delete_rows[1]->meta_value, 'Same-value delete race removed concurrent/original state.' );
	$ok( array( 'deleted:old', 'added:old' ) === $events[ $delete_key ], 'Delete compensation lifecycle did not expose deletion then restoration.' );
	$ok( $mirror[ $delete_key ] === $physical_map( $post_id, $delete_key ), 'Delete compensation left observer mirror inconsistent with physical rows.' );

	/* F-010/F-012: unique add contention cleans only unchanged Bridge row and mirrors cleanup. */
	$create_key = '_wpai_create_race'; $watched[ $create_key ] = true; $events[ $create_key ] = array(); $mirror[ $create_key ] = array(); $create_read = $read->execute( array( 'post_id' => $post_id, 'key' => $create_key ) );
	$race_create = null; $race_create = static function ( $object_id, $meta_key ) use ( &$race_create, &$mirror, $post_id, $create_key ) { if ( (int) $object_id === (int) $post_id && (string) $meta_key === $create_key ) { global $wpdb; remove_action( 'add_post_meta', $race_create, 1 ); $wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $create_key, 'meta_value' => 'concurrent' ), array( '%d', '%s', '%s' ) ); $mirror[ $create_key ][ (int) $wpdb->insert_id ] = 'concurrent'; wp_cache_delete( $post_id, 'post_meta' ); } };
	$guard_action( 'add_post_meta', $race_create, 1, 3 );
	$create_result = $update->execute( array( 'post_id' => $post_id, 'key' => $create_key, 'value_json' => wp_json_encode( 'bridge' ), 'expected_state_hash' => $create_read['items'][0]['state_hash'] ) );
	$create_rows = $physical( $post_id, $create_key ); ksort( $mirror[ $create_key ] );
	$ok( is_wp_error( $create_result ) && 'stale_post_meta_conflict' === $create_result->get_error_code() && 1 === count( $create_rows ) && 'concurrent' === $create_rows[0]->meta_value, 'Create contention left a Bridge duplicate row.' );
	$ok( array( 'added:bridge', 'deleted:bridge' ) === $events[ $create_key ], 'Create cleanup lifecycle did not expose add then compensating delete.' );
	$ok( $mirror[ $create_key ] === $physical_map( $post_id, $create_key ), 'Create cleanup left observer mirror inconsistent with physical rows.' );

	/* Final destructive group gate still applies. */
	$gate_key = '_wpai_delete_gate'; add_post_meta( $post_id, $gate_key, 'keep', true ); $gate_read = $read->execute( array( 'post_id' => $post_id, 'key' => $gate_key ) ); $enabled[ $settings_class::GROUP_USERS_DESTRUCTIVE ] = 0; update_option( $settings_class::OPTION_NAME, $enabled, false );
	$gate_result = $delete->execute( array( 'post_id' => $post_id, 'key' => $gate_key, 'expected_state_hash' => $gate_read['items'][0]['state_hash'] ) );
	$ok( is_wp_error( $gate_result ) && metadata_exists( 'post', $post_id, $gate_key ), 'Destructive access gate was bypassed.' );
} catch ( Throwable $exception ) { $failures[] = 'Unexpected Issue #34 test exception: ' . $exception->getMessage(); }
finally {
	foreach ( array_reverse( $filters ) as $filter ) { remove_filter( $filter[0], $filter[1], $filter[2] ); }
	foreach ( array_reverse( $actions ) as $action ) { remove_action( $action[0], $action[1], $action[2] ); }
	foreach ( array_unique( $registered ) as $key ) { unregister_post_meta( 'wpai_meta_security', $key ); }
	if ( is_int( $revision_id ) && $revision_id > 0 ) { wp_delete_post( $revision_id, true ); }
	if ( is_int( $post_id ) && $post_id > 0 ) { wp_delete_post( $post_id, true ); }
	unregister_post_type( 'wpai_meta_security' );
	if ( null === $original ) { delete_option( $settings_class::OPTION_NAME ); } else { update_option( $settings_class::OPTION_NAME, $original, false ); }
}
if ( $failures ) { foreach ( $failures as $failure ) { fwrite( STDERR, 'FAIL: ' . $failure . "\n" ); } exit( 1 ); }
echo "PASS: Issue #34 post metadata security semantics.\n";
