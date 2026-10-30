<?php
/**
 * Protected administration grants, scopes and stale form regression checks.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/integration/issue199-native-options-helper.php';

use WP_AI_Bridge\Support\Administration_Access;
use WP_AI_Bridge\Support\Administration_Configuration;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Admin\Administration_Page;
use WP_AI_Bridge\Auth\OAuth_Server;

function is_multisite() { return ! empty( $GLOBALS['wpai_test']['multisite'] ); }
function is_super_admin( $user = false ) { return ! empty( $GLOBALS['wpai_test']['super_admin'] ); }
function get_current_blog_id() { return $GLOBALS['wpai_test']['blog'] ?? 1; }
function get_current_network_id() { return $GLOBALS['wpai_test']['network'] ?? 1; }
function get_network_option( $network, $name, $default = false ) { return $GLOBALS['wpai_test']['network_options'][ $network ][ $name ] ?? $default; }
function check_admin_referer( $action = -1, $name = '_wpnonce' ) { return true; }

function administration_rejects( $callback, $message ) {
    try { $callback(); } catch ( RuntimeException $error ) {
        wpai199n_assert( '' !== $error->getMessage(), $message );
        return;
    }
    throw new RuntimeException( $message );
}

wpai_test_reset_state();
$settings = new Settings();
$access = new Administration_Access( new Permissions( $settings ) );
$config = new Administration_Configuration();
$groups = Settings::ADMINISTRATION_GROUPS;
$all = array_fill_keys( array_keys( $settings->groups() ), 1 );
update_option( Settings::OPTION_NAME, $all );
foreach ( $groups as $group ) {
    wpai199n_assert( ! $settings->is_enabled( $group ), 'Ordinary all-enabled settings granted advanced administration.' );
    wpai199n_assert( ! array_key_exists( $group, $settings->sanitize( $all ) ), 'Ordinary save rewrites a protected grant.' );
}
update_option( Settings::ADMINISTRATION_OPTION_NAME, array_fill_keys( $groups, 1 ) );
wpai199n_assert( ! $access->allowed( Settings::GROUP_FILESYSTEM_READ ), 'Unbound historical flags grant advanced access.' );
$grants = $settings->sanitize_administration_grants( array_fill_keys( $groups, 1 ) );
update_option( Settings::ADMINISTRATION_OPTION_NAME, $grants );
wpai199n_assert( $access->allowed( Settings::GROUP_FILESYSTEM_READ ), 'Explicit bound grant denied an actual administrator.' );
$context = $access->context();
$GLOBALS['wpai_test']['blog'] = 2;
wpai199n_assert( ! $access->allowed( Settings::GROUP_FILESYSTEM_READ ) && ! $access->same_context( $context ), 'Site reassignment adopted old grants.' );
$GLOBALS['wpai_test']['blog'] = 1;
$GLOBALS['wpai_test']['network'] = 2;
wpai199n_assert( ! $access->allowed( Settings::GROUP_FILESYSTEM_READ ), 'Network reassignment adopted old grants.' );
$GLOBALS['wpai_test']['network'] = 1;
$GLOBALS['wpai_test']['user_id'] = 0;
wpai199n_assert( ! $access->allowed( Settings::GROUP_FILESYSTEM_READ ), 'Anonymous invocation adopted administrator authority.' );
$GLOBALS['wpai_test']['user_id'] = 1;
$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
wpai199n_assert( ! $access->allowed( Settings::GROUP_FILESYSTEM_READ ), 'Delegation replaced current native capability.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
wpai199n_assert( ! $access->allowed( Settings::GROUP_FILESYSTEM_READ, false, array( array( Settings::GROUP_FILESYSTEM_WRITE, 'delete_users' ) ) ), 'Additional native capability was skipped.' );
$client = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
$client->setAccessible( true );
$client->setValue( null, 'other-authenticated-client' );
wpai199n_assert( ! $access->same_context( $context ), 'Authenticated client drift went undetected.' );
$client->setValue( null, '' );
$GLOBALS['wpai_test']['multisite'] = true;
administration_rejects( fn() => $settings->sanitize_administration_grants( $all ), 'A site administrator configured network-wide delegation.' );
wpai199n_assert( ! $access->allowed( Settings::GROUP_FILESYSTEM_READ, true ), 'Site administrator acquired actual network authority.' );
$GLOBALS['wpai_test']['super_admin'] = true;
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
wpai199n_assert( $access->allowed( Settings::GROUP_FILESYSTEM_READ, true ), 'Actual network administrator was denied.' );
$GLOBALS['wpai_test']['multisite'] = false;

$root = sys_get_temp_dir() . '/bridge-administration-' . bin2hex( random_bytes( 8 ) );
mkdir( $root, 0700 );
$outside = $root . '/restore';
mkdir( $outside, 0700 );
try {
    $input = array(
        'roots' => array( array(
            'id' => 'data', 'label' => 'داده', 'path' => $root,
            'purposes' => array( 'filesystem', 'logs', 'backup_export' ),
            'scope' => 'site', 'read' => '1', 'write' => '1',
        ), array(
            'id' => 'isolated', 'path' => $outside, 'purposes' => array( 'backup_restore' ),
            'write' => true, 'nonserved' => true,
        ) ),
        'restore_schemas' => "restore_one\nrestore_two",
        'backup_tables' => array( 'provider_data' ), 'step_bytes' => '2097152', 'step_seconds' => '7',
    );
    wpai199n_assert( ! $config->all()['valid'] && array() === $config->all()['roots'], 'Missing configuration silently approved paths.' );
    $canonical = $config->sanitize( $input );
    update_option( Administration_Configuration::OPTION_NAME, $canonical );
    $stored = $config->all();
    $stat = lstat( $root );
    wpai199n_assert( $stored['valid'] && $stored['roots']['data']['device'] === $stat['dev'] && $stored['roots']['data']['inode'] === $stat['ino'], 'Configured root identity was not captured.' );
    wpai199n_assert( 2097152 === $stored['step_bytes'] && 7 === $stored['step_seconds'] && array( 'restore_one', 'restore_two' ) === $stored['restore_schemas'], 'Exact scopes or processing policy changed.' );
    wpai199n_assert( $stored['revision'] === $config->all()['revision'], 'Unchanged configuration revision was unstable.' );
    foreach ( array(
        fn( $v ) => array_replace( $v, array( 'step_bytes' => '0' ) ),
        fn( $v ) => array_replace( $v, array( 'restore_schemas' => array( array( 'nested' ) ) ) ),
        fn( $v ) => array_replace( $v, array( 'roots' => array( array_replace( $v['roots'][0], array( 'path' => 'relative/path' ) ) ) ) ),
        fn( $v ) => array_replace( $v, array( 'roots' => array( array_replace( $v['roots'][0], array( 'path' => 'php://filter/resource=x' ) ) ) ) ),
        fn( $v ) => array_replace( $v, array( 'roots' => array( array_replace( $v['roots'][0], array( 'purposes' => array( array( 'logs' ) ) ) ) ) ) ),
        fn( $v ) => array_replace( $v, array( 'roots' => array( $v['roots'][0], $v['roots'][0] ) ) ),
        fn( $v ) => array_replace( $v, array( 'roots' => array( array_replace( $v['roots'][1], array( 'nonserved' => false ) ) ) ) ),
    ) as $bad ) {
        administration_rejects( fn() => $config->sanitize( $bad( $input ) ), 'Invalid configuration was accepted.' );
        wpai199n_assert( $canonical === get_option( Administration_Configuration::OPTION_NAME ), 'A rejected candidate partially changed native configuration.' );
    }
    $bad_stored = $canonical;
    $bad_stored['roots']['data']['purposes'] = array( array( 'logs' ) );
    update_option( Administration_Configuration::OPTION_NAME, $bad_stored );
    wpai199n_assert( ! $config->all()['valid'], 'Malformed stored purposes were used as scopes.' );
    update_option( Administration_Configuration::OPTION_NAME, $canonical );
    $GLOBALS['wpai_test']['blog'] = 2;
    wpai199n_assert( ! $config->all()['valid'] && array() === $config->all()['roots'], 'Old site configuration was adopted.' );
    $GLOBALS['wpai_test']['blog'] = 1;
    $page = new Administration_Page( $settings );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array_merge( $input, array( 'expected_revision' => 'stale', 'blog_id' => '1', 'network_id' => '1', 'form_complete' => '1' ) );
    administration_rejects( fn() => $page->save_configuration(), 'Stale administration form was accepted.' );
    $_POST['expected_revision'] = $stored['revision'];
    unset( $_POST['form_complete'] );
    administration_rejects( fn() => $page->save_configuration(), 'Truncated administration form was accepted.' );
    wpai199n_assert( $canonical === get_option( Administration_Configuration::OPTION_NAME ), 'Invalid form changed configured scopes.' );
    $GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
    administration_rejects( fn() => $config->sanitize( $input ), 'Revoked administrator still changed scopes.' );
    administration_rejects( fn() => $page->save_configuration(), 'Revoked administrator still saved a form.' );
    $GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
    $empty = $config->sanitize( array( 'roots' => array(), 'restore_schemas' => array(), 'backup_tables' => array() ) );
    update_option( Administration_Configuration::OPTION_NAME, $empty );
    wpai199n_assert( $config->all()['valid'] && array() === $config->all()['roots'] && $stored['revision'] !== $config->all()['revision'], 'Removing scopes failed to revoke them.' );
} finally {
    rmdir( $outside );
    rmdir( $root );
}
echo 'PASS protected administration boundary (' . $GLOBALS['wpai199n_checks'] . " assertions).\n";
