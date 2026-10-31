<?php
/** Disposable WordPress native-config setup validation (Issue #209). */
use WP_AI_Bridge\Admin\Native_SQL_Config_Setup;
use WP_AI_Bridge\Support\Settings;
$assert = static function ( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( 'issue209: ' . $message ); }
};
$assert( get_current_user_id() === 1 && current_user_can( 'manage_options' ), 'admin context' );
$setup = new Native_SQL_Config_Setup();
$state = $setup->inspect();
$path = realpath( ABSPATH . 'wp-config.php' );
$assert( $path && $state['path'] === $path, 'actual WordPress config path' );
$assert( ! $state['enabled'], 'pre-disabled policy' );
$original = file_get_contents( $path );
$before = hash( 'sha256', $original );
$original_stat = stat( $path );
$assert( $state['can_auto'], 'disposable supported WordPress fixture must permit automatic editing: ' . $state['reason'] );
$assert( $original_stat && is_writable( $path ) && is_writable( dirname( $path ) ), 'supported WordPress filesystem ownership/permissions' );
$grants = get_option( Settings::DATABASE_OPTION_NAME, false );
$payload = array(
    'action' => Native_SQL_Config_Setup::ACTION,
    '_wpnonce' => wp_create_nonce( $setup->nonce_action() ),
    'user' => (string) get_current_user_id(),
    'blog' => (string) get_current_blog_id(),
    'network' => (string) get_current_network_id(),
    'expected_digest' => $state['digest'],
    'expected_path_hash' => $state['path_hash'],
);
$_SERVER['REQUEST_METHOD'] = 'POST';
$forged = $payload;
$forged['_wpnonce'] = 'invalid';
$denied = $setup->activate( $forged );
$assert( $denied['result'] === 'denied' && hash_equals( $before, hash_file( 'sha256', $path ) ), 'nonce denial and no edit' );
$stale = $payload;
$stale['expected_digest'] = str_repeat( '0', 64 );
$denied = $setup->activate( $stale );
$assert( 'denied' === $denied['result'] && hash_equals( $before, hash_file( 'sha256', $path ) ), 'stale source digest must not edit' );
$deny_mods = static function ( $allowed, $context ) {
    return Native_SQL_Config_Setup::ACTION === $context ? false : $allowed;
};
add_filter( 'file_mod_allowed', $deny_mods, PHP_INT_MAX, 2 );
try {
    $denied = $setup->activate( $payload );
    $assert( 'denied' === $denied['result'] && hash_equals( $before, hash_file( 'sha256', $path ) ), 'native Core policy must refuse direct config edit' );
} finally {
    remove_filter( 'file_mod_allowed', $deny_mods, PHP_INT_MAX );
}
// Test a separate native-filesystem denial on this disposable WordPress
// volume; restore its original permission before the mandatory positive path.
$original_mode = $original_stat['mode'] & 07777;
$assert( chmod( $path, 0444 ), 'enter read-only WordPress config fixture' );
try {
    clearstatcache( true, $path );
    $denied_state = $setup->inspect();
    $assert( ! $denied_state['can_auto'], 'read-only WordPress config must deny automatic setup' );
    $denied = $setup->activate( $payload );
    $assert( 'denied' === $denied['result'] && hash_equals( $before, hash_file( 'sha256', $path ) ), 'read-only fallback preserves configuration' );
} finally {
    $assert( chmod( $path, $original_mode ), 'restore WordPress config fixture permissions' );
    clearstatcache( true, $path );
}
echo "WPAI209_FALLBACK=1\n";

$state = $setup->inspect();
$assert( $state['can_auto'], 'positive scenario must remain eligible after restoring permissions: ' . $state['reason'] );
$result = $setup->activate( $payload );
$assert( $result['result'] === 'written', 'automatic disk publication must succeed (no fallback-only pass): ' . ( $result['reason'] ?? '' ) );
$assert( ! defined( Native_SQL_Config_Setup::CONSTANT ), 'same-request constant unchanged' );
$written = file_get_contents( $path );
$assert( 1 === substr_count( $written, Native_SQL_Config_Setup::DEFINITION ), 'single definition' );
$newline = str_contains( $original, "\r\n" ) ? "\r\n" : "\n";
$assert( str_replace( Native_SQL_Config_Setup::DEFINITION . $newline . $newline, '', $written ) === $original, 'all unrelated wp-config.php bytes preserved' );
clearstatcache( true, $path );
$written_stat = stat( $path );
$assert( $written_stat && $written_stat['uid'] === $original_stat['uid'] && $written_stat['gid'] === $original_stat['gid'] &&
    ( $written_stat['mode'] & 07777 ) === $original_mode, 'original WordPress config uid/gid/mode preserved' );
$assert( $grants === get_option( Settings::DATABASE_OPTION_NAME, false ), 'all six database grants unchanged' );
echo "WPAI209_WRITTEN=1\n";
