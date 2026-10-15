<?php
/**
 * Crash after native Media claim but before normal scratch cleanup.
 *
 * Modes run in distinct installed WP-CLI processes sharing one disposable
 * WordPress DB and one private /tmp volume. No real-site effect is exercised.
 *
 * @package WP_AI_Bridge
 */

require_once __DIR__ . '/issue134-review-helper.php';

use WP_AI_Bridge\Support\Settings;

$key  = getenv( 'WPAI134_RECOVERY_KEY' );
$mode = getenv( 'WPAI134_RECOVERY_ACTION' );
wpai134_review_require(
	is_string( $key ) && 1 === preg_match( '/^crash-[a-z0-9-]{10,55}$/D', $key ) &&
	in_array( $mode, array( 'setup', 'worker', 'verify', 'cleanup' ), true ),
	'Invalid disposable crash-recovery test command.'
);
$directory = WP_CONTENT_DIR . '/wpai134-recovery-' . $key;
$statefile = $directory . '/state.json';
$admin     = get_current_user_id();
$settings  = new Settings();

if ( 'setup' === $mode ) {
	wpai134_review_require( wp_mkdir_p( $directory ), 'Private crash-test coordination directory was not created.' );
	$previous                                = get_option( Settings::OPTION_NAME, false );
	$grants                                  = $settings->defaults();
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );

	$editor = wp_create_user(
		'wpai134_crash_editor_' . wp_rand( 100000, 999999 ),
		wp_generate_password( 24 ),
		'wpai134-crash-' . wp_rand( 100000, 999999 ) . '@example.invalid'
	);
	wpai134_review_require( is_int( $editor ) && $editor > 0, 'Could not create disposable Editor principal.' );
	get_user_by( 'id', $editor )->set_role( 'editor' );
	wpai134_review_require( ! user_can( $editor, 'manage_options' ) && user_can( $editor, 'upload_files' ), 'Media-crash Editor permissions were not enforced.' );

	list( $token, $session, $oauth ) = wpai134_review_connect( $editor );
	$png                             = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=', true );
	$id                              = wpai134_review_stage( $token, $session, 'media', $png );
	$oauth->revoke( $token );
	$state = array(
		'editor'   => $editor,
		'id'       => $id,
		'previous' => $previous,
		'count'    => count(
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 100,
				)
			)
		),
	);
	wpai134_review_require( false !== file_put_contents( $statefile, wp_json_encode( $state ) ), 'Cannot persist crash-test identity.' );
	echo "PASS: Media crash fixture staged an Editor's verified private source.\n";
	return;
}
$state = is_file( $statefile ) ? json_decode( (string) file_get_contents( $statefile ), true ) : null;
wpai134_review_require( is_array( $state ) && ! empty( $state['id'] ), 'Media crash state was unavailable.' );
$id   = $state['id'];
$root = \WP_AI_Bridge\Support\Safe_Temporary_Staging::directory() . '/wpai-transfer-' .
	substr( hash( 'sha256', serialize( array( DB_NAME, ABSPATH, get_current_blog_id(), wp_salt( 'auth' ) ) ) ), 0, 40 );

if ( 'cleanup' === $mode ) {
	wp_set_current_user( $admin );
	if ( false === $state['previous'] ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $state['previous'], false );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $state['editor'] );
	foreach ( array( 'state.json', 'worker-died' ) as $filename ) {
		if ( is_file( $directory . '/' . $filename ) ) {
			unlink( $directory . '/' . $filename );
		}
	}
	rmdir( $directory );
	return;
}

if ( 'worker' === $mode ) {
	list( $token, $session, $oauth ) = wpai134_review_connect( (int) $state['editor'] );

	// WordPress Core calls this provider hook only *after* the Bridge has
	// created, verified and claimed .scratch; but BEFORE Core moves the file.
	$on_sideload = static function ( $file ) use ( $root, $id, $directory ) {
		wpai134_review_require(
			( $file['tmp_name'] ?? null ) === $root . '/' . $id . '.scratch' &&
			is_file( $root . '/' . $id . '.scratch' ) &&
			is_file( $root . '/' . $id . '.claim' ),
			'Crash barrier did not see the claimed private Media scratch.'
		);
		wpai134_review_require(
			false !== file_put_contents( $directory . '/worker-died', 'after-claim-before-core-move' ),
			'Crash barrier could not persist its test-only marker.'
		);
		// An abrupt process termination skips Media ability finally/cleanup.
		// The OS closes the stable session lock; the scratch file remains.
		exit( 0 );
	};
	add_filter( 'wp_handle_sideload_prefilter', $on_sideload, 10, 1 );
	wpai134_review_call(
		$token,
		$session,
		'wp-ai-bridge/transfer-media-import',
		array(
			'transfer_id' => $id,
			'filename'    => 'crash-review.png',
		)
	);
	throw new RuntimeException( 'Media sideload provider crash barrier was not reached.' );
}

wpai134_review_require( is_file( $directory . '/worker-died' ), 'A separate Media worker did not terminate inside the Core provider.' );
$scratch = $root . '/' . $id . '.scratch';
$bytes   = $root . '/' . $id . '.bytes';
$claim   = $root . '/' . $id . '.claim';
wpai134_review_require( is_file( $scratch ) && is_file( $bytes ) && is_file( $claim ), 'Crash did not preserve both private representations and its claim.' );
$old_claim = file_get_contents( $claim );
$count     = count(
	get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 100,
		)
	)
);
wpai134_review_require( $count === $state['count'], 'Crash barrier unexpectedly created a native attachment.' );

// Capture the newest complete metadata journal generation.
$checkpoint = static function () use ( $root, $id ) {
	$latest = null;
	foreach ( array( 0, 1 ) as $slot ) {
		$path = $root . '/' . $id . '.meta.state.' . $slot;
		if ( ! is_file( $path ) ) {
			continue;
		}
		$wire = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $wire ) || ! is_string( $wire['body'] ?? null ) ||
			! hash_equals( (string) ( $wire['sha256'] ?? '' ), hash( 'sha256', $wire['body'] ) ) ) {
			continue;
		}
		$body = json_decode( $wire['body'], true );
		if ( is_array( $body ) && ( null === $latest || (int) $body['generation'] > (int) $latest['generation'] ) ) {
			$latest = $body;
		}
	}
	return $latest;
};

$before = $checkpoint();
wpai134_review_require(
	'committing' === ( $before['state'] ?? null ) &&
	'unknown' === ( $before['outcome'] ?? null ) &&
	(int) ( $before['scratch_reserved'] ?? -1 ) === (int) $before['total'],
	'Crash did not retain a durable claimed scratch reservation.'
);
wp_set_current_user( $admin );
$grants                                  = $settings->defaults();
$grants[ Settings::GROUP_BUILDER_WRITE ] = 0;
update_option( Settings::OPTION_NAME, $grants, false );
list( $admin_token, $admin_session, $oauth ) = wpai134_review_connect( $admin );

$deny_delete = static function ( $path ) use ( $scratch ) {
	return $path === $scratch ? '' : $path;
};
add_filter( 'wp_delete_file', $deny_delete );
$blocked = wpai134_review_transfer(
	$admin_token,
	$admin_session,
	array(
		'action'              => 'admin_release',
		'transfer_id'         => $id,
		'acknowledge_unknown' => true,
	)
);
remove_filter( 'wp_delete_file', $deny_delete );
wpai134_review_require(
	! empty( $blocked['result']['isError'] ) &&
	is_file( $scratch ) && is_file( $bytes ) &&
	empty( $checkpoint()['payload_released'] ),
	'Deletion veto was wrongly reported as a complete private payload release.'
);
echo "PASS: #134 B1 scratch-deletion veto fails closed and retains the active reservation.\n";

$released = wpai134_review_transfer(
	$admin_token,
	$admin_session,
	array(
		'action'              => 'admin_release',
		'transfer_id'         => $id,
		'acknowledge_unknown' => true,
	)
);
$data     = $released['result']['structuredContent']['data'] ?? array();
$after    = $checkpoint();
wpai134_review_require(
	true === ( $released['result']['structuredContent']['success'] ?? false ) &&
	true === ( $data['payload_released'] ?? false ) &&
	! isset( $data['sha256'], $data['total_bytes'], $data['user'], $data['path'] ) &&
	! is_file( $scratch ) && ! is_file( $bytes ) &&
	0 === ( $after['scratch_reserved'] ?? -1 ) &&
	true === ( $after['payload_released'] ?? false ) &&
	'committing' === ( $after['state'] ?? '' ) &&
	'unknown' === ( $after['outcome'] ?? '' ) &&
	is_file( $claim ) && hash_equals( $old_claim, (string) file_get_contents( $claim ) ),
	'Admin recovery did not fully reclaim every private Media representation and retain claim privacy.'
);
$second = wpai134_review_transfer(
	$admin_token,
	$admin_session,
	array(
		'action'              => 'admin_release',
		'transfer_id'         => $id,
		'acknowledge_unknown' => true,
	)
);
wpai134_review_require( true === ( $second['result']['structuredContent']['success'] ?? false ), 'Admin retry did not preserve completed cleanup.' );
list( $editor_token, $editor_session ) = wpai134_review_connect( (int) $state['editor'] );
$retry                                 = wpai134_review_call(
	$editor_token,
	$editor_session,
	'wp-ai-bridge/transfer-media-import',
	array(
		'transfer_id' => $id,
		'filename'    => 'duplicate-crash-review.png',
	)
);
wpai134_review_require( ! empty( $retry['result']['isError'] ), 'Revoked Editor could replay the committed one-way Media claim.' );
echo "PASS: #134 B1 real crashed Media worker, grant revocation, exact admin byte/scratch release, reservation zero, retained claim/no replay.\n";
$oauth->revoke( $admin_token );
