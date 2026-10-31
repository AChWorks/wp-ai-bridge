<?php
/**
 * The official WordPress Docker configuration has an optional eval block;
 * the production analyzer must reject it, even if that branch is inactive.
 * Run this only on the disposable CI WordPress volume before normalization.
 */
use WP_AI_Bridge\Admin\Native_SQL_Config_Setup;

$setup = new Native_SQL_Config_Setup();
$state = $setup->inspect();
$path  = realpath( ABSPATH . 'wp-config.php' );
if ( ! $path || $state['path'] !== $path || $state['can_auto'] ||
	'complex' !== $state['state'] || ! str_contains( $state['reason'], 'evaluates dynamically generated PHP' ) ) {
	throw new RuntimeException( 'Issue 209: official Docker eval configuration must be refused without a write.' );
}
$before = hash_file( 'sha256', $path );
$post   = array(
	'action'             => Native_SQL_Config_Setup::ACTION,
	'_wpnonce'           => wp_create_nonce( $setup->nonce_action() ),
	'user'               => (string) get_current_user_id(),
	'blog'               => (string) get_current_blog_id(),
	'network'            => (string) get_current_network_id(),
	'expected_digest'    => $state['digest'],
	'expected_path_hash' => $state['path_hash'],
);
$_SERVER['REQUEST_METHOD'] = 'POST';
$result = $setup->activate( $post );
if ( 'denied' !== ( $result['result'] ?? '' ) || ! hash_equals( $before, hash_file( 'sha256', $path ) ) ) {
	throw new RuntimeException( 'Issue 209: unsupported Docker config was incorrectly changed.' );
}
echo "WPAI209_UNSUPPORTED=1\n";