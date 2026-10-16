<?php
/**
 * The durable final native-effect fence must refuse a WordPress write when
 * the filesystem cannot fsync the directory AFTER atomic claim publication.
 *
 * The namespace-local fsync() override is compiled solely in this isolated
 * WP-CLI fixture. Production invokes the native global PHP fsync().
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue134-fsync-fault-shim.php';
require_once __DIR__ . '/issue134-review-helper.php';


$administrator                            = get_current_user_id();
$settings                                 = new Settings();
$old                                      = get_option( Settings::OPTION_NAME, false );
$enabled                                  = $settings->defaults();
$enabled[ Settings::GROUP_BUILDER_WRITE ] = 1;
update_option( Settings::OPTION_NAME, $enabled, false );

$tokens                                   = array();
$store                                    = null;
$made                                     = array();
$GLOBALS['wpai134_test_dirsync_attempts'] = 0;
$GLOBALS['wpai134_test_fail_dirsync']     = false;

try {
	list( $token, $session, $store ) = wpai134_review_connect( $administrator );
	$tokens[]                        = $token;

	$content = 'claim namespace durability / no WordPress write on directory-sync failure';
	$staged  = wpai134_review_stage( $token, $session, 'text', $content );
	$label   = 'wpai134-dir-fsync-fail-' . wp_rand( 100000, 999999 );

	// Simulate an unavailable durable directory fsync after the final
	// private .claim has been renamed. Native Core MUST NOT be called.
	$GLOBALS['wpai134_test_fail_dirsync'] = true;
	$failed                               = wpai134_review_call(
		$token,
		$session,
		'wp-ai-bridge/transfer-content-commit',
		array(
			'action'      => 'create',
			'transfer_id' => $staged,
			'post_type'   => 'post',
			'title'       => $label,
			'status'      => 'draft',
		)
	);
	$GLOBALS['wpai134_test_fail_dirsync'] = false;

	wpai134_review_require( ! empty( $failed['result']['isError'] ), 'Directory fsync refusal reached a native WordPress write.' );
	wpai134_review_require( $GLOBALS['wpai134_test_dirsync_attempts'] > 0, 'Directory fsync fault injection did not reach the durable publication boundary.' );

	$root = \WP_AI_Bridge\Support\Safe_Temporary_Staging::directory() . '/wpai-transfer-' .
		substr( hash( 'sha256', serialize( array( DB_NAME, ABSPATH, get_current_blog_id(), wp_salt( 'auth' ) ) ) ), 0, 40 );
	wpai134_review_require( file_exists( $root . '/' . $staged . '.claim' ), 'The interrupted durable publication removed the one-way claim fence.' );
	$post_query = get_posts(
		array(
			'title'          => $label,
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => 2,
		)
	);
	wpai134_review_require( 0 === count( $post_query ), 'A WordPress post was committed despite failed directory fsync.' );

	$status = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'status',
			'transfer_id' => $staged,
		)
	);
	wpai134_review_require(
		'committing' === ( $status['result']['structuredContent']['data']['state'] ?? '' ) &&
		'unknown' === ( $status['result']['structuredContent']['data']['outcome'] ?? '' ),
		'Failed durable publication did not retain the conservative unknown-effect state.'
	);
	$replay = wpai134_review_call(
		$token,
		$session,
		'wp-ai-bridge/transfer-content-commit',
		array(
			'action'      => 'create',
			'transfer_id' => $staged,
			'post_type'   => 'post',
			'title'       => $label,
			'status'      => 'draft',
		)
	);
	wpai134_review_require( ! empty( $replay['result']['isError'] ), 'A failed directory fsync let a claimed WordPress effect replay.' );
	echo "PASS: #134 A1 directory fsync failure blocks native writes and preserves one-way unknown claim.\n";

	$normal_source = wpai134_review_stage( $token, $session, 'text', $content );
	$normal_label  = 'wpai134-dir-fsync-success-' . wp_rand( 100000, 999999 );
	$normal        = wpai134_review_call(
		$token,
		$session,
		'wp-ai-bridge/transfer-content-commit',
		array(
			'action'      => 'create',
			'transfer_id' => $normal_source,
			'post_type'   => 'post',
			'title'       => $normal_label,
			'status'      => 'draft',
		)
	);
	wpai134_review_require(
		true === ( $normal['result']['structuredContent']['success'] ?? false ),
		'Successful native directory fsync did not permit normal WordPress commit.'
	);
	$post_id = (int) ( $normal['result']['structuredContent']['data']['post_id'] ?? 0 );
	wpai134_review_require( 0 < $post_id && get_post_field( 'post_content', $post_id ) === $content, 'Native WordPress success returned a false content receipt.' );
	$made[] = $post_id;
	echo "PASS: #134 A1 file+directory durability permits an exactly-once native WordPress commit.\n";

	$released = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'              => 'admin_release',
			'transfer_id'         => $staged,
			'acknowledge_unknown' => true,
		)
	);
	wpai134_review_require( true === ( $released['result']['structuredContent']['success'] ?? false ), 'Injected unknown claim cannot be administratively recovered.' );
} finally {
	$GLOBALS['wpai134_test_fail_dirsync'] = false;
	wp_set_current_user( $administrator );
	foreach ( $made as $id ) {
		wp_delete_post( $id, true );
	}
	if ( false === $old ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $old, false );
	}
	foreach ( $tokens as $issued ) {
		if ( $store ) {
			$store->revoke( $issued );
		}
	}
}
