<?php
/**
 * Review A/B: atomic native claim publication, fail-closed legacy tears,
 * and non-admin-origin unknown outcome recovery over real OAuth MCP.
 *
 * @package WP_AI_Bridge
 */

require_once __DIR__ . '/issue134-review-helper.php';

use WP_AI_Bridge\Support\Settings;

$admin                                   = get_current_user_id();
$settings                                = new Settings();
$previous                                = get_option( Settings::OPTION_NAME, false );
$grants                                  = $settings->defaults();
$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
update_option( Settings::OPTION_NAME, $grants, false );

$editor = 0;
$tokens = array();
$client = null;
try {
	list( $admin_token, $admin_session, $client ) = wpai134_review_connect( $admin );
	$tokens[]                                     = $admin_token;
	$root = realpath( get_temp_dir() ) . '/wpai-transfer-' .
		substr( hash( 'sha256', serialize( array( DB_NAME, ABSPATH, get_current_blog_id(), wp_salt( 'auth' ) ) ) ), 0, 40 );

	$pending_id = wpai134_review_stage( $admin_token, $admin_session, 'text', 'unpublished claimant' );
	$pending    = $root . '/' . $pending_id . '.claim.pending.0123456789abcdef';
	wpai134_review_require( false !== file_put_contents( $pending, '{"partial":' ), 'Cannot emulate process death before claim publication.' );
	touch( $pending, time() - 300 );
	$before = wpai134_review_transfer(
		$admin_token,
		$admin_session,
		array(
			'action'      => 'status',
			'transfer_id' => $pending_id,
		)
	);
	wpai134_review_require( 'verified' === ( $before['result']['structuredContent']['data']['state'] ?? '' ), 'Unpublished temporary was incorrectly interpreted as committed effect.' );
	$admission = wpai134_review_stage( $admin_token, $admin_session, 'text', '' );
	wpai134_review_require( ! file_exists( $pending ), 'Orphan unpublished claim file was not reconciled at admission.' );
	wpai134_review_transfer(
		$admin_token,
		$admin_session,
		array(
			'action'      => 'cancel',
			'transfer_id' => $pending_id,
		)
	);
	wpai134_review_transfer(
		$admin_token,
		$admin_session,
		array(
			'action'      => 'cancel',
			'transfer_id' => $admission,
		)
	);

	$published_id = wpai134_review_stage( $admin_token, $admin_session, 'text', 'published-before-metadata-commit' );
	wpai134_review_require(
		false !== file_put_contents(
			$root . '/' . $published_id . '.claim',
			wp_json_encode(
				array(
					'consumer'  => 'content',
					'intent'    => 'create',
					'target_id' => 0,
				)
			)
		),
		'Cannot emulate death just after atomic final-claim publication.'
	);
	$published = wpai134_review_transfer(
		$admin_token,
		$admin_session,
		array(
			'action'      => 'status',
			'transfer_id' => $published_id,
		)
	);
	wpai134_review_require(
		'committing' === ( $published['result']['structuredContent']['data']['state'] ?? '' ) &&
		'unknown' === ( $published['result']['structuredContent']['data']['outcome'] ?? '' ),
		'Published fence failed to recover as one-way unknown effect.'
	);
	$no_reverify = wpai134_review_transfer(
		$admin_token,
		$admin_session,
		array(
			'action'      => 'verify',
			'transfer_id' => $published_id,
		)
	);
	wpai134_review_require( ! empty( $no_reverify['result']['isError'] ), 'A published fence allowed re-verification and potential native replay.' );

	$torn_id   = wpai134_review_stage( $admin_token, $admin_session, 'text', 'damaged-legacy-final-fence' );
	$torn_path = $root . '/' . $torn_id . '.claim';
	wpai134_review_require( false !== file_put_contents( $torn_path, '{"consumer":' ), 'Cannot simulate legacy partial final claim.' );
	$torn = wpai134_review_transfer(
		$admin_token,
		$admin_session,
		array(
			'action'      => 'status',
			'transfer_id' => $torn_id,
		)
	);
	wpai134_review_require( 'committing' === ( $torn['result']['structuredContent']['data']['state'] ?? '' ), 'Partial final native claim was not fenced fail-closed.' );
	$unrelated = wpai134_review_stage( $admin_token, $admin_session, 'text', '' );
	wpai134_review_transfer(
		$admin_token,
		$admin_session,
		array(
			'action'      => 'cancel',
			'transfer_id' => $unrelated,
		)
	);
	foreach ( array( $published_id, $torn_id ) as $id ) {
		$released = wpai134_review_transfer(
			$admin_token,
			$admin_session,
			array(
				'action'              => 'admin_release',
				'transfer_id'         => $id,
				'acknowledge_unknown' => true,
			)
		);
		wpai134_review_require(
			true === ( $released['result']['structuredContent']['success'] ?? false ) &&
			true === ( $released['result']['structuredContent']['data']['payload_released'] ?? false ) &&
			file_exists( $root . '/' . $id . '.claim' ),
			'Unknown native effect was released without retaining the one-way immutable fence.'
		);
	}
	echo "PASS: #134 A unpublished/torn claim recovery, unrelated admissions and no native-effect replay.\n";

	wp_set_current_user( $admin );
	$editor = wp_create_user( 'wpai134_review_editor_' . wp_rand( 100000, 999999 ), wp_generate_password( 28 ), 'wpai134-review@example.invalid' );
	wpai134_review_require( is_int( $editor ) && $editor > 0, 'Could not create disposable non-administrator principal.' );
	$user = get_user_by( 'id', $editor );
	$user->set_role( 'editor' );
	wpai134_review_require( user_can( $editor, 'edit_posts' ) && ! user_can( $editor, 'manage_options' ), 'Editor fixture has unexpected WordPress grants.' );

	list( $editor_token, $editor_session ) = wpai134_review_connect( $editor );
	$tokens[]                              = $editor_token;
	$editor_id                             = wpai134_review_stage( $editor_token, $editor_session, 'text', 'owner-private-editor-content' );
	wpai134_review_require(
		false !== file_put_contents(
			$root . '/' . $editor_id . '.claim',
			wp_json_encode(
				array(
					'consumer'  => 'content',
					'intent'    => 'create',
					'target_id' => 0,
				)
			)
		),
		'Could not persist an editor-origin native claim fence.'
	);
	$denied_editor = wpai134_review_transfer(
		$editor_token,
		$editor_session,
		array(
			'action'              => 'admin_release',
			'transfer_id'         => $editor_id,
			'acknowledge_unknown' => true,
		)
	);
	wpai134_review_require( ! empty( $denied_editor['result']['isError'] ), 'Editor was incorrectly granted administrator cleanup.' );
	$denied_legacy = wpai134_review_transfer(
		$editor_token,
		$editor_session,
		array(
			'action'              => 'release',
			'transfer_id'         => $editor_id,
			'acknowledge_unknown' => true,
		)
	);
	wpai134_review_require( ! empty( $denied_legacy['result']['isError'] ), 'Editor bypassed prior unknown-effect manager requirement.' );
	$denied_admin_read = wpai134_review_transfer(
		$admin_token,
		$admin_session,
		array(
			'action'      => 'status',
			'transfer_id' => $editor_id,
		)
	);
	wpai134_review_require( ! empty( $denied_admin_read['result']['isError'] ), 'Other administrator could inspect Editor private lease through normal status.' );
	$bad_ack = wpai134_review_transfer(
		$admin_token,
		$admin_session,
		array(
			'action'              => 'admin_release',
			'transfer_id'         => $editor_id,
			'acknowledge_unknown' => false,
		)
	);
	wpai134_review_require( ! empty( $bad_ack['result']['isError'] ), 'Administrator cleanup did not require affirmative unknown-outcome acknowledgement.' );
	$admin_release = wpai134_review_transfer(
		$admin_token,
		$admin_session,
		array(
			'action'              => 'admin_release',
			'transfer_id'         => $editor_id,
			'acknowledge_unknown' => true,
		)
	);
	$minimal       = $admin_release['result']['structuredContent']['data'] ?? array();
	wpai134_review_require(
		true === ( $admin_release['result']['structuredContent']['success'] ?? false ) &&
		true === ( $minimal['payload_released'] ?? false ) &&
		'' === ( $minimal['sha256'] ?? null ) &&
		! file_exists( $root . '/' . $editor_id . '.bytes' ) &&
		file_exists( $root . '/' . $editor_id . '.claim' ),
		'Admin release leaked private content identity or failed to retain the effect fence.'
	);
	$editor_after = wpai134_review_transfer(
		$editor_token,
		$editor_session,
		array(
			'action'      => 'status',
			'transfer_id' => $editor_id,
		)
	);
	wpai134_review_require(
		true === ( $editor_after['result']['structuredContent']['data']['payload_released'] ?? false ) &&
		'committing' === ( $editor_after['result']['structuredContent']['data']['state'] ?? '' ),
		'Editor claim/status disappeared after private byte cleanup.'
	);
	$no_replay = wpai134_review_transfer(
		$editor_token,
		$editor_session,
		array(
			'action'      => 'verify',
			'transfer_id' => $editor_id,
		)
	);
	wpai134_review_require( ! empty( $no_replay['result']['isError'] ), 'Admin recovery reopened the originating Editor lease.' );

	$revoked_id = wpai134_review_stage( $editor_token, $editor_session, 'text', 'editor-before-revocation' );
	wpai134_review_require(
		false !== file_put_contents(
			$root . '/' . $revoked_id . '.claim',
			wp_json_encode(
				array(
					'consumer'  => 'content',
					'intent'    => 'create',
					'target_id' => 0,
				)
			)
		),
		'Could not simulate revoked Editor unknown-effect receipt.'
	);
	wp_set_current_user( $admin );
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wp_set_current_user( 0 );
	$revoked = wpai134_review_transfer(
		$admin_token,
		$admin_session,
		array(
			'action'              => 'admin_release',
			'transfer_id'         => $revoked_id,
			'acknowledge_unknown' => true,
		)
	);
	wpai134_review_require(
		true === ( $revoked['result']['structuredContent']['success'] ?? false ) &&
		! file_exists( $root . '/' . $revoked_id . '.bytes' ),
		'Revoked Builder Write unexpectedly blocked separate same-site administrator recovery.'
	);
	echo "PASS: #134 B Editor unknown-effect admin-only release, cross-principal privacy and grant-revocation recovery.\n";
} finally {
	wp_set_current_user( $admin );
	if ( false === $previous ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $previous, false );
	}
	foreach ( $tokens as $issued ) {
		$client->revoke( $issued );
	}
	if ( $editor ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $editor );
	}
}
