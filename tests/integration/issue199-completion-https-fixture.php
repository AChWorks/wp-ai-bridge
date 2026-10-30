<?php
/** Private setup/cleanup for a separate standards HTTPS client; never prints tokens. */
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;
require_once __DIR__ . '/issue199-completion-helper.php';
ini_set( 'memory_limit', '512M' );
global $wpdb;
$mode = $args[0] ?? '';
$origin = $args[1] ?? '';
$database = getenv( 'WPAI199C_EXPECT_DATABASE' );
wpai199c_assert( defined( 'WPAI_ADMIN13_DISPOSABLE' ) && true === WPAI_ADMIN13_DISPOSABLE &&
	1 === get_current_user_id() && current_user_can( 'manage_options' ) && ! is_multisite() &&
	is_string( $database ) && '' !== $database && $wpdb->get_var( 'SELECT DATABASE()' ) === $database &&
	in_array( $mode, array( 'setup', 'cleanup', 'revoke_grants' ), true ), 'Explicit disposable HTTPS fixture identity required.' );
$path = get_temp_dir() . 'admin13-https-fixture.json';
if ( 'setup' === $mode ) {
	wpai199c_assert( ! file_exists( $path ) && 1 === preg_match( '#^https://(?:localhost|127\\.0\\.0\\.1)(?::[0-9]+)?$#D', $origin ), 'Fresh loopback HTTPS fixture required.' );
	$before = wpai199c_options_before();
	$suffix = bin2hex( random_bytes( 4 ) );
	$seed = wpai199c_seed_provider( $suffix );
	$state = array( 'before' => $before, 'suffix' => $suffix, 'seed' => $seed,
		'siteurl' => get_option( 'siteurl' ), 'home' => get_option( 'home' ), 'theme_mod' => get_theme_mod( 'wpai199c_customizer_text', null ) );
	update_option( 'siteurl', $origin ); update_option( 'home', $origin );
	wpai199c_configure( $seed['directory'], getenv( 'WPAI199C_RESTORE_ROOT' ), getenv( 'WPAI199C_RESTORE_SCHEMA' ), $seed['tables'] );
	wpai199c_grants(); wpai199c_provider_lifecycle_grants();
	$store = new OAuth_Store(); $server = new OAuth_Server( $store );
	$state['token'] = $store->issue( OAuth_Store::TYPE_ACCESS, array( 'user_id' => 1, 'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
		'resource' => $server->mcp_endpoint_url(), 'scope' => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE ), 600 );
	$state['endpoint'] = $server->mcp_endpoint_url();
	$state['stylesheet'] = get_stylesheet();
	$state['sql_enabled'] = defined( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL' ) && true === WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL;
	wpai199c_assert( is_string( $state['token'] ) && strlen( $state['token'] ) > 20, 'Synthetic native OAuth issuance failed.' );
	$bytes = json_encode( $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
	wpai199c_assert( file_put_contents( $path, $bytes, LOCK_EX ) === strlen( $bytes ) && chmod( $path, 0600 ), 'Private HTTPS fixture checkpoint failed.' );
	echo 'Prepared private HTTPS client fixture: ' . $path . "\n";
} elseif ( 'revoke_grants' === $mode ) {
	wpai199c_assert( is_file( $path ) && ! is_link( $path ), 'Private HTTPS fixture missing.' );
	wpai199c_grants( false ); echo "Revoked current completion delegation.\n";
} else {
	wpai199c_assert( is_file( $path ) && ! is_link( $path ) && filesize( $path ) < 1048576, 'Exact private HTTPS fixture checkpoint required.' );
	$state = json_decode( file_get_contents( $path ), true, 64, JSON_THROW_ON_ERROR );
	( new OAuth_Store() )->revoke( $state['token'] );
	wp_clear_scheduled_hook( 'wpai199c_owned_queue_effect', array( 'https-' . $state['suffix'], 'normal' ) );
	delete_transient( 'admin13_https_' . $state['suffix'] );
	null === $state['theme_mod'] ? remove_theme_mod( 'wpai199c_customizer_text' ) : set_theme_mod( 'wpai199c_customizer_text', $state['theme_mod'] );
	wpai199c_cleanup_seed( $state['seed'], $state['suffix'] );
	wpai199c_restore_options( $state['before'] );
	update_option( 'siteurl', $state['siteurl'] ); update_option( 'home', $state['home'] );
	wpai199c_assert( unlink( $path ), 'Private synthetic OAuth checkpoint cleanup failed.' );
	echo "Cleaned only the owned external HTTPS fixture.\n";
}
