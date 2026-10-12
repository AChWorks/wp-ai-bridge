<?php
/**
 * Disposable native WordPress state and post identity for two-process CAS.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Abilities\Content_Abilities;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

$key    = getenv( 'WPAI134_CAS_KEY' );
$action = getenv( 'WPAI134_CAS_ACTION' );
$mode   = getenv( 'WPAI134_CAS_MODE' );
if ( ! is_string( $key ) || 1 !== preg_match( '/^cas-[a-z0-9-]{10,55}$/D', $key ) ||
	! in_array( $action, array( 'setup', 'verify', 'cleanup' ), true ) ||
	! in_array( $mode, array( 'two_leases', 'ordinary' ), true ) ) {
	throw new RuntimeException( 'Invalid disposable native CAS fixture identity.' );
}
$dir = WP_CONTENT_DIR . '/wpai134-cas-' . $key;
if ( 'setup' === $action ) {
	if ( ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
		throw new RuntimeException( 'Cannot create isolated CAS barrier directory.' );
	}
	$grants                                  = ( new Settings() )->defaults();
	$grants[ Settings::GROUP_SITE_READ ]     = 1;
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => 'WPAI134 CAS ' . $key,
			'post_content' => 'race-base',
		),
		true
	);
	if ( is_wp_error( $id ) || ! is_int( $id ) ) {
		throw new RuntimeException( 'Could not make test-only WordPress CAS target.' );
	}
	$content  = new Content_Abilities( new Permissions( new Settings() ), new Mutation_Log() );
	$identity = $content->read(
		array(
			'action'          => 'get',
			'id'              => $id,
			'include_content' => false,
		)
	);
	$item     = $identity['items'][0] ?? array();
	if ( empty( $item['state_hash'] ) || empty( $item['modified_gmt'] ) ) {
		throw new RuntimeException( 'Native WordPress expected identity unavailable.' );
	}
	$state = array(
		'post_id'      => $id,
		'modified_gmt' => $item['modified_gmt'],
		'state_hash'   => $item['state_hash'],
	);
	if ( ! file_put_contents( $dir . '/state.json', wp_json_encode( $state ) ) ) {
		throw new RuntimeException( 'Cannot share synthetic CAS identity with workers.' );
	}
	echo "PASS: native CAS target and expected state created.\n";
	return;
}
$data = is_file( $dir . '/state.json' ) ? json_decode( (string) file_get_contents( $dir . '/state.json' ), true ) : null;
if ( ! is_array( $data ) || empty( $data['post_id'] ) ) {
	throw new RuntimeException( 'Native CAS state could not be recovered.' );
}
if ( 'verify' === $action ) {
	clean_post_cache( (int) $data['post_id'] );
	$post     = get_post( (int) $data['post_id'] );
	$expected = 'ordinary' === $mode ? 'ordinary native winner' : 'CAS first worker winner';
	if ( ! $post || $expected !== $post->post_content ) {
		throw new RuntimeException( 'Race loser overwrote an ordinary/first-worker post with stale content.' );
	}
	echo "PASS: two-worker conditional native WordPress write preserved authoritative winner ($mode).\n";
	return;
}
wp_delete_post( (int) $data['post_id'], true );
foreach ( array( 'state.json', 'one.locked', 'two.ready', 'ordinary.complete' ) as $name ) {
	if ( is_file( $dir . '/' . $name ) ) {
		unlink( $dir . '/' . $name );
	}
}
if ( is_dir( $dir ) ) {
	rmdir( $dir );
}
