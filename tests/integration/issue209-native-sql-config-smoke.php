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
$before = hash_file( 'sha256', $path );
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
$result = $setup->activate( $payload );
if ( $state['can_auto'] ) {
    $assert( $result['result'] === 'written', 'disk publication' );
    $assert( ! defined( Native_SQL_Config_Setup::CONSTANT ), 'same-request constant unchanged' );
    $assert( substr_count( file_get_contents( $path ), Native_SQL_Config_Setup::DEFINITION ) === 1, 'single definition' );
    echo "WPAI209_WRITTEN=1\n";
} else {
    $assert( $result['result'] === 'denied' && ! empty( $result['reason'] ), 'precise manual fallback' );
    $assert( hash_equals( $before, hash_file( 'sha256', $path ) ), 'no file effect' );
    echo "WPAI209_WRITTEN=0\n";
}
$assert( $grants === get_option( Settings::DATABASE_OPTION_NAME, false ), 'all six database grants unchanged' );
