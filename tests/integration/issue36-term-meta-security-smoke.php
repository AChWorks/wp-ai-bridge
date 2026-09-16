<?php
/** Real WordPress term-meta authorization, byte-exact CAS, lifecycle and ownership proofs. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Mutation_Log;

$failures = array(); $assertions = 0;
$ok = static function ( $condition, $message ) use ( &$failures, &$assertions ) { ++$assertions; if ( ! $condition ) { $failures[] = $message; } };
$require = static function ( $value, $message ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $message . ': ' . $value->get_error_code() ); } return $value; };
$original_settings = get_option( Settings::OPTION_NAME, null );
$original_log = get_option( Mutation_Log::OPTION_NAME, null );
$original_user = get_current_user_id();
$taxonomy = 'wpai_meta_fixture'; $other_taxonomy = 'wpai_meta_other';
$term_id = 0; $actor_id = 0; $extra_terms = array(); $registered = array(); $hooks = array();
$hook = static function ( $name, $callback, $priority = 10, $args = 6 ) use ( &$hooks ) { add_filter( $name, $callback, $priority, $args ); $hooks[] = array( $name, $callback, $priority ); return $callback; };
$unhook = static function ( $name, $callback, $priority = 10 ) { remove_filter( $name, $callback, $priority ); };
$raw_rows = static function ( $id, $key ) { global $wpdb; return $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, term_id, meta_key, meta_value FROM %i WHERE term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id', $wpdb->termmeta, $id, $key ), ARRAY_A ); };
$set_raw = static function ( $id, $meta_id, $raw ) { global $wpdb; $wpdb->update( $wpdb->termmeta, array( 'meta_value' => $raw ), array( 'meta_id' => $meta_id ), array( '%s' ), array( '%d' ) ); wp_cache_delete( $id, 'term_meta' ); };
$insert_raw = static function ( $id, $key, $raw, $meta_id = null ) { global $wpdb; $row = array( 'term_id' => $id, 'meta_key' => $key, 'meta_value' => $raw ); if ( null !== $meta_id ) { $row['meta_id'] = $meta_id; } $result = $wpdb->insert( $wpdb->termmeta, $row ); wp_cache_delete( $id, 'term_meta' ); if ( 1 !== $result ) { throw new RuntimeException( 'Physical test fixture insert failed.' ); } return (int) $wpdb->insert_id; };
$register = static function ( $key, $args, $subtype = null ) use ( &$registered, $taxonomy ) { $subtype = null === $subtype ? $taxonomy : $subtype; register_term_meta( $subtype, $key, $args ); $registered[] = array( $subtype, $key ); };

try {
    $read = wp_get_ability( 'wp-ai-bridge/term-meta-read' );
    $update = wp_get_ability( 'wp-ai-bridge/term-meta-update' );
    $delete = wp_get_ability( 'wp-ai-bridge/term-meta-delete' );
    if ( ! $read || ! $update || ! $delete ) { throw new RuntimeException( 'Term metadata Abilities are unavailable.' ); }
    register_taxonomy( $taxonomy, 'post', array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'capabilities' => array( 'manage_terms' => 'wpai_manage_fixture', 'edit_terms' => 'wpai_edit_fixture', 'delete_terms' => 'wpai_delete_fixture', 'assign_terms' => 'wpai_assign_fixture' ) ) );
    register_taxonomy( $other_taxonomy, 'post', array( 'public' => false ) );
    $created = $require( wp_insert_term( 'Term metadata fixture', $taxonomy ), 'Create fixture' );
    $term_id = (int) $created['term_id'];
    $target = array( 'term_id' => $term_id, 'taxonomy' => $taxonomy );
    $actor_id = $require( wp_insert_user( array( 'user_login' => 'wpnb36_' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) ), 'Create actor' );
    $actor = get_user_by( 'id', $actor_id ); $actor->add_cap( 'wpai_edit_fixture' ); wp_set_current_user( $actor_id );
    // Use the actual current WP_User instance, not a separately cached instance.
    $actor = wp_get_current_user();
    $ok( ! current_user_can( 'manage_categories' ) && current_user_can( 'edit_term', $term_id ), 'Custom term authority fixture depends on a global capability.' );
    $settings = new Settings(); $enabled = $settings->defaults();
    $enabled[ Settings::GROUP_ADVANCED_METADATA ] = 1; $enabled[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
    $inspect = static function ( $key, $values = false ) use ( $read, $target ) { return $read->execute( $target + array( 'key' => $key, 'include_values' => $values ) ); };
    $state = static function ( $key ) use ( $inspect, $require ) { return $require( $inspect( $key ), 'Inspect ' . $key )['items'][0]['state_hash']; };
    $write = static function ( $key, $value, $expected = null ) use ( $update, $target, $state ) { return $update->execute( $target + array( 'key' => $key, 'value_json' => wp_json_encode( $value, JSON_PRESERVE_ZERO_FRACTION ), 'expected_state_hash' => $expected ?? $state( $key ) ) ); };
    $remove = static function ( $key, $expected = null ) use ( $delete, $target, $state ) { return $delete->execute( $target + array( 'key' => $key, 'expected_state_hash' => $expected ?? $state( $key ) ) ); };
    $fake = $target + array( 'key' => 'ordinary', 'expected_state_hash' => str_repeat( '0', 64 ), 'value_json' => '"next"' );
    update_option( Settings::OPTION_NAME, $settings->defaults(), false );
    foreach ( array( $read, $update, $delete ) as $ability ) { $ok( false === $ability->check_permissions( $fake ), 'Disabled Advanced Metadata permission passed.' ); }
    $ok( is_wp_error( $inspect( 'ordinary' ) ) && is_wp_error( $write( 'ordinary', 'x', str_repeat( '0', 64 ) ) ) && is_wp_error( $remove( 'ordinary', str_repeat( '0', 64 ) ) ), 'Disabled Advanced Metadata executed an operation.' );
    update_option( Settings::OPTION_NAME, $enabled, false );

    // Ordinary/private term metadata works without public/UI/REST exposure or provider code.
    $require( $write( 'ordinary', 'first' ), 'Create ordinary metadata' );
    $require( $write( '_private', array( 'layout' => 'grid' ) ), 'Create protected metadata' );
    $ok( 'first' === get_term_meta( $term_id, 'ordinary', true ), 'Ordinary creation did not persist.' );
    $ok( array( 'layout' => 'grid' ) === get_term_meta( $term_id, '_private', true ), 'Protected metadata did not persist.' );
    $ok( ! current_user_can( 'edit_term_meta', $term_id, '_private' ) && false === has_filter( 'auth_term_meta__private' ), 'Temporary administrator opt-in leaked into ordinary WordPress authorization.' );
    foreach ( array( array( 'term_id' => $term_id ), array( 'term_id' => $term_id, 'taxonomy' => $other_taxonomy ), array( 'term_id' => 999999999, 'taxonomy' => $taxonomy ) ) as $bad ) { $ok( is_wp_error( $read->execute( $bad ) ), 'Unverified term/taxonomy identity was accepted.' ); }
    $actor->remove_cap( 'wpai_edit_fixture' );
    $ok( is_wp_error( $inspect( 'ordinary' ) ), 'Missing exact term capability did not deny read.' );
    $actor->add_cap( 'wpai_edit_fixture' );

    // Explicit registered and priority-zero provider denials remain authoritative.
    foreach ( array( '_registered_deny', 'registered_public_deny' ) as $key ) {
        $register( $key, array( 'type' => 'string', 'single' => true, 'auth_callback' => '__return_false' ) ); add_term_meta( $term_id, $key, 'keep', true );
        $ok( is_wp_error( $inspect( $key ) ) && is_wp_error( $write( $key, 'x', str_repeat( '0', 64 ) ) ) && is_wp_error( $remove( $key, str_repeat( '0', 64 ) ) ), 'Registered authorization denial was bypassed.' );
    }
    $register( '_global_deny', array( 'auth_callback' => '__return_false' ), '' ); add_term_meta( $term_id, '_global_deny', 'keep', true );
    $ok( is_wp_error( $inspect( '_global_deny' ) ), 'Global metadata registration denial was bypassed.' );
    add_term_meta( $term_id, '_priority_deny', 'keep', true );
    $hook( 'auth_term_meta__priority_deny_for_' . $taxonomy, '__return_false', 0 );
    $ok( is_wp_error( $inspect( '_priority_deny' ) ), 'Priority-zero auth denial was ignored.' );
    $register( '_registered_allow', array( 'auth_callback' => '__return_true', 'type' => 'string', 'single' => true ) );
    $require( $write( '_registered_allow', 'allowed' ), 'Authorized registered metadata' );
    foreach ( array( 'wpai_missing_cap', 'do_not_allow', 'edit_term_meta' ) as $required_cap ) {
        $mapped = $hook( 'map_meta_cap', static function ( $caps, $cap, $user, $args ) use ( $term_id, $required_cap ) { if ( 'edit_term_meta' === $cap && (int) ( $args[0] ?? 0 ) === $term_id && '_private' === ( $args[1] ?? '' ) ) { $caps[] = $required_cap; } return $caps; }, PHP_INT_MAX, 4 );
        $ok( is_wp_error( $inspect( '_private' ) ), 'Extra mapped requirement or explicit meta-cap denial was bypassed: ' . $required_cap );
        $unhook( 'map_meta_cap', $mapped, PHP_INT_MAX );
    }
    $user_gate = $hook( 'user_has_cap', static function ( $allcaps, $caps, $args ) use ( $term_id ) { if ( 'edit_term_meta' === ( $args[0] ?? '' ) && (int) ( $args[2] ?? 0 ) === $term_id && '_private' === ( $args[3] ?? '' ) ) { foreach ( $caps as $cap ) { $allcaps[ $cap ] = false; } } return $allcaps; }, 10, 4 );
    $ok( is_wp_error( $inspect( '_private' ) ) && ! is_wp_error( $inspect( 'ordinary' ) ), 'Original user_has_cap term/key context was lost.' );
    $unhook( 'user_has_cap', $user_gate );

    // An actual legacy shared term is not a sufficient identity, even with taxonomy supplied.
    global $wpdb;
    $split_option = get_option( 'finished_splitting_shared_terms', null );
    update_option( 'finished_splitting_shared_terms', 0 );
    $wpdb->insert( $wpdb->term_taxonomy, array( 'term_id' => $term_id, 'taxonomy' => $other_taxonomy, 'description' => '' ) );
    $shared_tt = (int) $wpdb->insert_id;
    try { clean_term_cache( $term_id, $taxonomy ); $ok( wp_term_is_shared( $term_id ) && is_wp_error( $inspect( 'ordinary' ) ), 'Shared term identity was not refused.' ); }
    finally { $wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => $shared_tt ) ); clean_term_cache( $term_id, $taxonomy ); if ( null === $split_option ) { delete_option( 'finished_splitting_shared_terms' ); } else { update_option( 'finished_splitting_shared_terms', $split_option ); } }

    $secrets = array( 'sessionToken', 'session_tokens', 'SESSION__TOKENS', 'session\\tokens', 'idTokens', 'identity_tokens', 'jwtToken', 'jwttokens', 'csrfTokens', 'securityTokens', 'clientSecrets', 'consumerSecrets', 'accessTokens', 'apiKeys', 'privateKeys', 'applicationPasswords' );
    foreach ( $secrets as $key ) {
        add_term_meta( $term_id, wp_slash( $key ), 'sensitive-fixture', true );
        $ok( is_wp_error( $inspect( $key, true ) ) && is_wp_error( $write( $key, 'x', str_repeat( '0', 64 ) ) ) && is_wp_error( $remove( $key, str_repeat( '0', 64 ) ) ), 'Secret-like key was not denied in every operation.' );
    }
    add_term_meta( $term_id, 'design_token', 'blue', true );
    $ok( ! is_wp_error( $inspect( 'design_token', true ) ), 'Unrelated design_token was overblocked.' );
    $listed = $require( $read->execute( $target + array( 'per_page' => 100 ) ), 'List metadata' );
    foreach ( $listed['items'] as $item ) { $ok( array( 'key', 'count' ) === array_keys( $item ) && ! in_array( $item['key'], $secrets, true ), 'List leaked values/state or secret-like names.' ); }
    $page = $require( $read->execute( $target + array( 'per_page' => 2 ) ), 'Bounded list page' );
    $ok( count( $page['items'] ) <= 2 && $page['has_more'], 'List pagination bounds failed.' );
    $ok( is_wp_error( $read->execute( $target + array( 'include_values' => true ) ) ), 'Broad exact values were exposed.' );
    $old_hash = $state( 'ordinary' ); update_term_meta( $term_id, 'ordinary', 'external' );
    $ok( is_wp_error( $write( 'ordinary', 'stale', $old_hash ) ) && is_wp_error( $remove( 'ordinary', $old_hash ) ) && 'external' === get_term_meta( $term_id, 'ordinary', true ), 'Stale update/delete changed newer state.' );
    add_term_meta( $term_id, 'multi', 'one' ); add_term_meta( $term_id, 'multi', 'two' );
    $ok( is_wp_error( $inspect( 'multi' ) ) && is_wp_error( $write( 'multi', 'x', str_repeat( '0', 64 ) ) ) && is_wp_error( $remove( 'multi', str_repeat( '0', 64 ) ) ) && 2 === count( $raw_rows( $term_id, 'multi' ) ), 'Ambiguous multi-row metadata was touched.' );
    $virtual = $hook( 'get_term_metadata', static function ( $value, $id, $key ) use ( $term_id ) { return (int) $id === $term_id && 'multi' === $key ? array( 'virtual' ) : $value; } );
    $ok( is_wp_error( $inspect( 'multi' ) ), 'Virtual read hid physical ambiguity.' ); $unhook( 'get_term_metadata', $virtual );
    $register( '_default', array( 'single' => true, 'type' => 'string', 'default' => 'default', 'auth_callback' => '__return_true' ) );
    $default_before = $require( $inspect( '_default' ), 'Default physical absence' );
    $default_after = $require( $write( '_default', 'default' ), 'Create physical default' );
    $ok( 0 === $default_before['items'][0]['count'] && 1 === $default_after['count'] && $default_before['items'][0]['state_hash'] !== $default_after['state_hash'], 'Registered defaults aliased physical identity.' );

    // No stored PHP class can be instantiated through generic metadata reads.
    class WPNB36_Stored_Object { public $keep = true; public function __wakeup() { $GLOBALS['wpnb36_wakeup'] = true; } }
    add_term_meta( $term_id, '_opaque', array( 'nested' => new WPNB36_Stored_Object() ), true );
    $opaque_hash = $state( '_opaque' );
    $ok( is_wp_error( $inspect( '_opaque', true ) ) && is_wp_error( $write( '_opaque', 'x', $opaque_hash ) ) && is_wp_error( $remove( '_opaque', $opaque_hash ) ) && empty( $GLOBALS['wpnb36_wakeup'] ), 'Opaque PHP metadata was exposed, mutated, or instantiated.' );
    foreach ( array( '_malformed' => 'a:99:{}', '_nan' => serialize( array( 'nested' => NAN ) ) ) as $key => $raw ) {
        $insert_raw( $term_id, $key, $raw ); $hash = $state( $key );
        $ok( is_wp_error( $inspect( $key, true ) ) && is_wp_error( $write( $key, 'x', $hash ) ) && is_wp_error( $remove( $key, $hash ) ) && $raw === $raw_rows( $term_id, $key )[0]['meta_value'], 'Lossy serialized state was mutated: ' . $key );
    }
    foreach ( array( '{}', '{"0":"x"}', '{"x":{}}', '9223372036854775808' ) as $json ) {
        $bad = $update->execute( $target + array( 'key' => '_json_shape', 'value_json' => $json, 'expected_state_hash' => $state( '_json_shape' ) ) );
        $ok( is_wp_error( $bad ) && empty( $raw_rows( $term_id, '_json_shape' ) ), 'Lossy JSON input was stored.' );
    }
    $insert_raw( $term_id, '_oversized', str_repeat( 'x', 1048577 ) );
    $ok( is_wp_error( $inspect( '_oversized', true ) ), 'Oversized stored value was returned.' );
    $calls = 0;
    $register( '_sanitize_once', array( 'single' => true, 'type' => 'string', 'auth_callback' => '__return_true', 'sanitize_callback' => static function ( $value ) use ( &$calls ) { ++$calls; return $value . '-sanitized'; } ) );
    $require( $write( '_sanitize_once', 'input' ), 'One-pass creation sanitizer' );
    $ok( 1 === $calls && 'input-sanitized' === get_term_meta( $term_id, '_sanitize_once', true ), 'Creation did not sanitize exactly once.' );
    $require( $write( '_sanitize_once', 'next' ), 'One-pass update sanitizer' );
    $ok( 2 === $calls && 'next-sanitized' === get_term_meta( $term_id, '_sanitize_once', true ), 'Update did not sanitize exactly once.' );
    $resource = fopen( 'php://memory', 'r+' );
    $register( '_bad_sanitizer', array( 'auth_callback' => '__return_true', 'sanitize_callback' => static fn( $value ) => array( 'resource' => $resource ) ) );
    $ok( is_wp_error( $write( '_bad_sanitizer', 'x' ) ) && empty( $raw_rows( $term_id, '_bad_sanitizer' ) ), 'Unsafe sanitizer output was persisted.' );
    fclose( $resource );

    // WordPress scalar coercion, slashing, SQL NULL, no-op and all raw CAS branches.
    foreach ( array( '_empty' => '', '_zero' => '0', '_false' => false, '_true' => true, '_int' => 7, '_float' => 1.5, '_empty_array' => array(), '_array' => array( 'x' => 'y' ), 'literal\\path' => 'literal\\value' ) as $key => $value ) {
        $require( $write( $key, $value ), 'Create safe state' );
        $fresh = $state( $key ); $require( $write( $key, $value ), 'No-op safe state' );
        $ok( $fresh === $state( $key ), 'No-op changed physical row identity.' );
        $require( $write( $key, 'replacement' ), 'Update safe state' );
        $require( $remove( $key ), 'Delete safe state' );
        $ok( empty( $raw_rows( $term_id, $key ) ), 'Safe state deletion left a row.' );
    }
    $null_id = $insert_raw( $term_id, '_null', null ); $null_hash = $state( '_null' );
    $null_read = $require( $inspect( '_null', true ), 'Read SQL NULL' );
    $ok( 'null' === $null_read['items'][0]['values'][0]['value_json'], 'SQL NULL was lost.' );
    $set_raw( $term_id, $null_id, '' ); $ok( $null_hash !== $state( '_null' ), 'NULL aliased empty string.' );
    $require( $write( '_null', null ), 'String to NULL' ); $require( $write( '_null', null ), 'NULL no-op' );
    $require( $write( '_null', 'filled' ), 'NULL to string' ); $require( $write( '_null', null ), 'Restore NULL' );
    $require( $remove( '_null' ), 'Delete SQL NULL' );

    // Byte-different values must not pass the physical CAS under a loose text collation.
    foreach ( array( 'case' => array( 'old', 'OLD' ), 'accent' => array( "caf\xc3\xa9", 'cafe' ), 'trailing' => array( 'trail', 'trail ' ) ) as $suffix => $pair ) {
        foreach ( array( 'update', 'delete' ) as $operation ) {
            $key = '_binary_' . $suffix . '_' . $operation; add_term_meta( $term_id, $key, $pair[0], true ); $hash = $state( $key ); $id = (int) $raw_rows( $term_id, $key )[0]['meta_id'];
            $name = $operation . '_term_metadata';
            $race = null; $race = $hook( $name, static function ( $check, $object_id, $meta_key ) use ( &$race, $name, $term_id, $key, $id, $pair, $set_raw ) { if ( (int) $object_id === $term_id && $meta_key === $key ) { remove_filter( $name, $race, 1 ); $set_raw( $term_id, $id, $pair[1] ); } return $check; }, 1 );
            $result = 'update' === $operation ? $write( $key, 'bridge', $hash ) : $remove( $key, $hash );
            $ok( is_wp_error( $result ) && 'stale_term_meta_conflict' === $result->get_error_code() && $pair[1] === $raw_rows( $term_id, $key )[0]['meta_value'], 'Byte-exact CAS overwrote or removed a concurrent value.' );
        }
    }

    // Concurrent duplicate writes require compensation of only the Bridge's physical row.
    foreach ( array( 'update', 'delete', 'create' ) as $operation ) {
        $key = '_race_' . $operation; if ( 'create' !== $operation ) { add_term_meta( $term_id, $key, 'old', true ); }
        $hash = $state( $key ); $events = array(); $mirror = array();
        foreach ( $raw_rows( $term_id, $key ) as $row ) { $mirror[ (int) $row['meta_id'] ] = $row['meta_value']; }
        $observers = array();
        foreach ( array( 'added', 'updated', 'deleted' ) as $event ) {
            $observers[ $event ] = $hook( $event . '_term_meta', static function ( $ids, $object_id, $meta_key, $value ) use ( &$events, &$mirror, $key, $term_id, $event ) { if ( (int) $object_id !== $term_id || $meta_key !== $key ) { return; } $events[] = $event . ':' . $value; foreach ( (array) $ids as $id ) { if ( 'deleted' === $event ) { unset( $mirror[ (int) $id ] ); } else { $mirror[ (int) $id ] = $value; } } }, 20, 4 );
        }
        $name = 'create' === $operation ? 'add_term_meta' : $operation . '_term_metadata';
        $race = null; $race = $hook( $name, static function ( ...$args ) use ( &$race, $name, $operation, $term_id, $key, $insert_raw, &$mirror ) { $object_id = 'create' === $operation ? $args[0] : $args[1]; $meta_key = 'create' === $operation ? $args[1] : $args[2]; if ( (int) $object_id === $term_id && $meta_key === $key ) { remove_filter( $name, $race, 1 ); $value = 'create' === $operation ? 'concurrent' : 'old'; $mid = $insert_raw( $term_id, $key, $value ); $mirror[ $mid ] = $value; if ( 'create' === $operation ) { $mid = $insert_raw( $term_id, $key, 'third' ); $mirror[ $mid ] = 'third'; } } return 'create' === $operation ? null : $args[0]; }, 1 );
        $result = 'delete' === $operation ? $remove( $key, $hash ) : $write( $key, 'bridge', $hash );
        $physical = array(); foreach ( $raw_rows( $term_id, $key ) as $row ) { $physical[ (int) $row['meta_id'] ] = $row['meta_value']; } ksort( $physical ); ksort( $mirror );
        $expected_events = 'create' === $operation ? array( 'added:bridge', 'deleted:bridge' ) : ( 'update' === $operation ? array( 'updated:bridge', 'updated:old' ) : array( 'deleted:old', 'added:old' ) );
        $ok( is_wp_error( $result ) && 'stale_term_meta_conflict' === $result->get_error_code() && 2 === count( $physical ) && ! in_array( 'bridge', $physical, true ), 'Duplicate race compensation modified concurrent rows or left Bridge state.' );
        $ok( $expected_events === $events && $mirror === $physical, 'Compensation lifecycle mirror disagrees with physical state.' );
        foreach ( $observers as $event => $callback ) { $unhook( $event . '_term_meta', $callback, 20 ); }
        $cached = get_term_meta( $term_id, $key, false ); $ok( array_values( $physical ) === $cached, 'Compensation left term metadata cache stale.' );
    }

    // A provider-returned row ID and a nested add are never ownership evidence.
    $key = '_virtual_create'; $hash = $state( $key ); $provider_mid = 0;
    $provider = null; $provider = $hook( 'add_term_metadata', static function ( $check, $id, $meta_key ) use ( &$provider, &$provider_mid, $term_id, $key ) { if ( (int) $id === $term_id && $key === $meta_key ) { remove_filter( 'add_term_metadata', $provider, 1 ); $provider_mid = add_term_meta( $term_id, $key, 'provider', true ); return $provider_mid; } return $check; }, 1 );
    $result = $write( $key, 'bridge', $hash );
    $ok( is_wp_error( $result ) && 1 === count( $raw_rows( $term_id, $key ) ) && 'provider' === $raw_rows( $term_id, $key )[0]['meta_value'], 'Provider short circuit was falsely owned or compensated.' );
    foreach ( array( 'create', 'update' ) as $operation ) {
        $key = '_observer_' . $operation; if ( 'update' === $operation ) { add_term_meta( $term_id, $key, 'old', true ); } $hash = $state( $key );
        $event = 'create' === $operation ? 'added_term_meta' : 'updated_term_meta';
        $observer = null; $observer = $hook( $event, static function ( $id, $object_id, $meta_key ) use ( &$observer, $event, $term_id, $key, $set_raw ) { if ( (int) $object_id === $term_id && $meta_key === $key ) { remove_filter( $event, $observer, 1 ); $set_raw( $term_id, (int) $id, 'BRIDGE' ); } }, 1, 4 );
        $result = $write( $key, 'bridge', $hash );
        $ok( is_wp_error( $result ) && 'BRIDGE' === $raw_rows( $term_id, $key )[0]['meta_value'], 'Ownership/compensation overwrote an observer-modified row.' );
    }

    // Revocation/deletion during the pre-mutation hook is rechecked before physical CAS.
    $key = '_revoked'; add_term_meta( $term_id, $key, 'keep', true ); $hash = $state( $key );
    $revoke = null; $revoke = $hook( 'update_term_meta', static function ( $mid, $id, $meta_key ) use ( &$revoke, $term_id, $key, $actor ) { if ( (int) $id === $term_id && $meta_key === $key ) { remove_filter( 'update_term_meta', $revoke, 1 ); $actor->remove_cap( 'wpai_edit_fixture' ); } }, 1, 4 );
    $result = $write( $key, 'x', $hash ); $actor->add_cap( 'wpai_edit_fixture' );
    $ok( is_wp_error( $result ) && 'keep' === get_term_meta( $term_id, $key, true ), 'Authority revocation before physical mutation was ignored.' );
    $temp = $require( wp_insert_term( 'Deleted target fixture', $taxonomy ), 'Create disappearing term' ); $temp_id = (int) $temp['term_id']; $extra_terms[] = $temp_id;
    add_term_meta( $temp_id, '_target', 'keep', true ); $temp_target = array( 'term_id' => $temp_id, 'taxonomy' => $taxonomy );
    $temp_read = $require( $read->execute( $temp_target + array( 'key' => '_target' ) ), 'Read disappearing term' );
    $disappear = null; $disappear = $hook( 'update_term_meta', static function ( $mid, $id, $key ) use ( &$disappear, $temp_id, $taxonomy ) { if ( (int) $id === $temp_id ) { remove_filter( 'update_term_meta', $disappear, 1 ); wp_delete_term( $temp_id, $taxonomy ); } }, 1, 4 );
    $result = $update->execute( $temp_target + array( 'key' => '_target', 'value_json' => '"x"', 'expected_state_hash' => $temp_read['items'][0]['state_hash'] ) );
    $ok( is_wp_error( $result ) && empty( $raw_rows( $temp_id, '_target' ) ), 'Deleted term received an orphan metadata write.' );

    // The preflight blocks enum autoloading without rejecting token-like string content.
    $autoloads = array();
    $enum_loader = static function ( $class ) use ( &$autoloads ) { if ( str_starts_with( $class, 'WPNB36_Unloaded_' ) ) { $autoloads[] = $class; } };
    spl_autoload_register( $enum_loader );
    try {
        $enum_case = 'WPNB36_Unloaded_Integration_Enum:Value';
        $encoded = sprintf( 'E:%d:"%s";', strlen( $enum_case ), $enum_case );
        $insert_raw( $term_id, '_enum', $encoded );
        $insert_raw( $term_id, '_nested_enum', 'a:1:{i:0;' . $encoded . '}' );
        foreach ( array( '_enum', '_nested_enum' ) as $key ) {
            $hash = $state( $key );
            $ok( is_wp_error( $inspect( $key, true ) ) && is_wp_error( $write( $key, 'x', $hash ) ) && is_wp_error( $remove( $key, $hash ) ), 'Serialized enum was exposed or mutated.' );
        }
        $ok( array() === $autoloads, 'Serialized enum inspection invoked an application autoloader.' );
        $require( $write( '_enum_literal', array( 'literal' => $encoded ) ), 'Store harmless token-like text' );
        $ok( ! is_wp_error( $inspect( '_enum_literal', true ) ), 'Token-like string content was overblocked.' );
    } finally { spl_autoload_unregister( $enum_loader ); }

    // Explicit meta authorization denial remains denial even if the primitive meta cap was granted.
    $actor->add_cap( 'edit_term_meta' );
    $explicit = $hook( 'map_meta_cap', static function ( $caps, $cap, $user, $args ) use ( $term_id ) { if ( 'edit_term_meta' === $cap && (int) ( $args[0] ?? 0 ) === $term_id && '_private' === ( $args[1] ?? '' ) ) { $caps[] = $cap; } return $caps; }, PHP_INT_MAX, 4 );
    $ok( is_wp_error( $inspect( '_private' ) ) && is_wp_error( $inspect( '_registered_deny' ) ), 'Explicit metadata denial was defeated by a granted primitive meta capability.' );
    $unhook( 'map_meta_cap', $explicit, PHP_INT_MAX ); $actor->remove_cap( 'edit_term_meta' );

    // A sanitizer-induced race cannot bypass CAS through the no-op path.
    $key = '_noop_race'; add_term_meta( $term_id, $key, 'old', true ); $mid = (int) $raw_rows( $term_id, $key )[0]['meta_id']; $hash = $state( $key );
    $register( $key, array( 'auth_callback' => '__return_true', 'sanitize_callback' => static function ( $value ) use ( $term_id, $mid, $set_raw ) { $set_raw( $term_id, $mid, 'concurrent' ); return $value; } ) );
    $result = $write( $key, 'old', $hash );
    $ok( is_wp_error( $result ) && 'stale_term_meta_conflict' === $result->get_error_code() && 'concurrent' === $raw_rows( $term_id, $key )[0]['meta_value'], 'No-op path ignored the post-sanitizer physical state.' );

    // Deleted-row compensation cannot replace a new owner's row at the old physical ID.
    $key = '_delete_collision'; add_term_meta( $term_id, $key, 'old', true ); $hash = $state( $key );
    $collision = null; $collision = $hook( 'deleted_term_meta', static function ( $ids, $id, $meta_key ) use ( &$collision, $term_id, $key, $insert_raw ) { if ( (int) $id === $term_id && $meta_key === $key ) { remove_filter( 'deleted_term_meta', $collision, 1 ); $insert_raw( $term_id, '_new_row_owner', 'owner-value', (int) $ids[0] ); $insert_raw( $term_id, $key, 'concurrent' ); } }, 1, 4 );
    $previous_suppression = $wpdb->suppress_errors( true );
    try { $result = $remove( $key, $hash ); } finally { $wpdb->suppress_errors( $previous_suppression ); }
    $ok( is_wp_error( $result ) && 'term_meta_compensation_failed' === $result->get_error_code() && 'owner-value' === $raw_rows( $term_id, '_new_row_owner' )[0]['meta_value'] && 'concurrent' === $raw_rows( $term_id, $key )[0]['meta_value'], 'Deleted-row compensation overwrote a different physical row owner.' );

    // A term removed during create or during delete compensation receives no orphan row.
    foreach ( array( 'create', 'delete_compensation' ) as $scenario ) {
        $temp = $require( wp_insert_term( 'Disappearing ' . $scenario, $taxonomy ), 'Create disappearing fixture' ); $id = (int) $temp['term_id']; $extra_terms[] = $id;
        $t = array( 'term_id' => $id, 'taxonomy' => $taxonomy );
        if ( 'delete_compensation' === $scenario ) { add_term_meta( $id, '_gone', 'old', true ); }
        $r = $require( $read->execute( $t + array( 'key' => '_gone' ) ), 'Inspect disappearing fixture' );
        if ( 'delete_compensation' === $scenario ) {
            $duplicate = null; $duplicate = $hook( 'delete_term_metadata', static function ( $check, $object_id, $key ) use ( &$duplicate, $id, $insert_raw ) { if ( (int) $object_id === $id && '_gone' === $key ) { remove_filter( 'delete_term_metadata', $duplicate, 1 ); $insert_raw( $id, '_gone', 'concurrent' ); } return $check; }, 1 );
        }
        $disappear = null; $disappear = $hook( 'add_term_meta', static function ( $object_id, $key ) use ( &$disappear, $id, $taxonomy ) { if ( (int) $object_id === $id && '_gone' === $key ) { remove_filter( 'add_term_meta', $disappear, 1 ); wp_delete_term( $id, $taxonomy ); } }, 1, 3 );
        $input = $t + array( 'key' => '_gone', 'expected_state_hash' => $r['items'][0]['state_hash'] );
        $result = 'create' === $scenario ? $update->execute( $input + array( 'value_json' => '"bridge"' ) ) : $delete->execute( $input );
        $ok( is_wp_error( $result ) && empty( $raw_rows( $id, '_gone' ) ), 'A disappearing term received orphan metadata during ' . $scenario );
    }

    // Metadata lifecycle events preserve Core's taxonomy-query cache generation too.
    foreach ( array( 'create', 'update', 'delete' ) as $operation ) {
        $before = wp_cache_get_last_changed( 'terms' );
        $result = 'delete' === $operation ? $remove( '_cache_generation' ) : $write( '_cache_generation', $operation );
        $require( $result, 'Cache lifecycle operation' );
        $ok( $before !== wp_cache_get_last_changed( 'terms' ), 'Core term query cache generation did not advance.' );
    }

    // Compensation must retain target identity both before and after its pre-hook.
    foreach ( array( 'create', 'update', 'delete' ) as $operation ) {
        foreach ( array( 'taxonomy', 'term_taxonomy_id' ) as $drift ) {
            foreach ( array( 'before_compensation', 'during_compensation' ) as $stage ) {
                $converted = $require( wp_insert_term( 'Target transfer ' . $operation . $drift . $stage, $taxonomy ), 'Create transfer fixture' );
                $converted_id = (int) $converted['term_id']; $converted_tt = (int) $converted['term_taxonomy_id'];
                $replacement_tt = (int) $wpdb->get_var( "SELECT MAX(term_taxonomy_id) FROM {$wpdb->term_taxonomy}" ) + 100;
                try {
                    if ( 'create' !== $operation ) { add_term_meta( $converted_id, '_transfer', 'original', true ); }
                    $t = array( 'term_id' => $converted_id, 'taxonomy' => $taxonomy );
                    $r = $require( $read->execute( $t + array( 'key' => '_transfer' ) ), 'Inspect transfer fixture' );
                    $change_target = static function () use ( $converted_id, $converted_tt, $replacement_tt, $taxonomy, $other_taxonomy, $drift ) {
                        global $wpdb;
                        $changes = 'taxonomy' === $drift ? array( 'taxonomy' => $other_taxonomy ) : array( 'term_taxonomy_id' => $replacement_tt );
                        $wpdb->update( $wpdb->term_taxonomy, $changes, array( 'term_taxonomy_id' => $converted_tt ) );
                        clean_term_cache( $converted_id, $taxonomy ); clean_term_cache( $converted_id, $other_taxonomy );
                    };
                    $event = array( 'create' => 'added_term_meta', 'update' => 'updated_term_meta', 'delete' => 'deleted_term_meta' )[ $operation ];
                    $contend = null;
                    $contend = $hook( $event, static function ( $ids, $id, $key ) use ( &$contend, $event, $converted_id, $insert_raw, $change_target, $stage ) {
                        if ( (int) $id !== $converted_id || '_transfer' !== $key ) { return; }
                        remove_filter( $event, $contend, 1 );
                        if ( 'before_compensation' === $stage ) { $change_target(); }
                        $insert_raw( $converted_id, '_transfer', 'new-target-value' );
                    }, 1, 4 );
                    if ( 'during_compensation' === $stage ) {
                        $inverse = array( 'create' => 'delete_term_meta', 'update' => 'update_term_meta', 'delete' => 'add_term_meta' )[ $operation ];
                        $transfer = null;
                        $transfer = $hook( $inverse, static function ( ...$args ) use ( &$transfer, $inverse, $operation, $converted_id, $change_target ) {
                            $id = 'delete' === $operation ? $args[0] : $args[1];
                            $key = 'delete' === $operation ? $args[1] : $args[2];
                            $value = 'delete' === $operation ? $args[2] : $args[3];
                            $expected = 'create' === $operation ? 'bridge' : 'original';
                            if ( (int) $id !== $converted_id || '_transfer' !== $key || $expected !== $value ) { return; }
                            remove_filter( $inverse, $transfer, 1 ); $change_target();
                        }, 1, 4 );
                    }
                    $input = $t + array( 'key' => '_transfer', 'expected_state_hash' => $r['items'][0]['state_hash'] );
                    $result = 'delete' === $operation ? $delete->execute( $input ) : $update->execute( $input + array( 'value_json' => '"bridge"' ) );
                    $current = get_term( $converted_id );
                    $ok( $current && ! is_wp_error( $current ) && ( 'taxonomy' === $drift ? $other_taxonomy === $current->taxonomy : $replacement_tt === (int) $current->term_taxonomy_id ), 'Target-transfer fixture did not change the current WordPress identity.' );
                    $values = array_column( $raw_rows( $converted_id, '_transfer' ), 'meta_value' ); sort( $values );
                    $expected = 'delete' === $operation ? array( 'new-target-value' ) : array( 'bridge', 'new-target-value' );
                    $ok( is_wp_error( $result ) && 'term_meta_compensation_failed' === $result->get_error_code() && $expected === $values, 'Compensation crossed a changed target: ' . $operation . '/' . $drift . '/' . $stage );
                } finally {
                    $wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => $taxonomy, 'term_taxonomy_id' => $converted_tt ), array( 'term_id' => $converted_id ) );
                    clean_term_cache( $converted_id, $taxonomy ); clean_term_cache( $converted_id, $other_taxonomy );
                    wp_delete_term( $converted_id, $taxonomy );
                }
            }
        }
    }

    // Core category/tag terms use the same provider-neutral surface for authorized actors.
    wp_set_current_user( $original_user );
    foreach ( array( 'category', 'post_tag' ) as $core_taxonomy ) {
        $core = $require( wp_insert_term( 'Bridge metadata ' . wp_generate_password( 8, false ), $core_taxonomy ), 'Create Core term' ); $core_id = (int) $core['term_id'];
        try { $t = array( 'term_id' => $core_id, 'taxonomy' => $core_taxonomy ); $r = $require( $read->execute( $t + array( 'key' => '_generic' ) ), 'Read Core term' ); $u = $update->execute( $t + array( 'key' => '_generic', 'value_json' => '"supported"', 'expected_state_hash' => $r['items'][0]['state_hash'] ) ); $ok( ! is_wp_error( $u ) && 'supported' === get_term_meta( $core_id, '_generic', true ), 'Core taxonomy metadata was not supported.' ); }
        finally { wp_delete_term( $core_id, $core_taxonomy ); }
    }
    wp_set_current_user( $actor_id );
    $enabled[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0; update_option( Settings::OPTION_NAME, $enabled, false );
    $ok( is_wp_error( $remove( 'ordinary' ) ) && metadata_exists( 'term', $term_id, 'ordinary' ), 'Destructive group gate was bypassed.' );
    $log = wp_json_encode( get_option( Mutation_Log::OPTION_NAME, array() ) );
    foreach ( array( '_private', 'sensitive-fixture', '_revoked', '_sanitize_once', 'expected_state_hash', 'value_json' ) as $sensitive ) { $ok( false === strpos( $log, $sensitive ), 'Mutation log contains a key, value or payload.' ); }
} catch ( Throwable $exception ) { $failures[] = 'Unexpected Issue #36 exception: ' . $exception->getMessage(); }
finally {
    foreach ( array_reverse( $hooks ) as $entry ) { remove_filter( $entry[0], $entry[1], $entry[2] ); }
    foreach ( $registered as $entry ) { unregister_term_meta( $entry[0], $entry[1] ); }
    wp_set_current_user( $original_user );
    foreach ( $extra_terms as $id ) { wp_delete_term( $id, $taxonomy ); }
    if ( $term_id > 0 ) { wp_delete_term( $term_id, $taxonomy ); }
    unregister_taxonomy( $taxonomy ); unregister_taxonomy( $other_taxonomy );
    if ( $actor_id > 0 ) { if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; } wp_delete_user( $actor_id ); }
    foreach ( array( Settings::OPTION_NAME => $original_settings, Mutation_Log::OPTION_NAME => $original_log ) as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value, false ); } }
}
if ( $failures ) { foreach ( $failures as $failure ) { fwrite( STDERR, 'FAIL: ' . $failure . "\n" ); } exit( 1 ); }
echo "PASS: {$assertions} Issue #36 real WordPress term metadata security assertions.\n";
