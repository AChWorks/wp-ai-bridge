<?php
/**
 * Replays the exact identity from a new real PHP process after process death.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Create_Claim;

$key  = getenv( 'WPAI_CRASH_KEY' );
$mode = getenv( 'WPAI_CRASH_MODE' );
if ( ! is_string( $key ) || ! preg_match( '/^crash-[a-z0-9-]{8,55}$/D', $key ) ||
	! in_array( $mode, array( 'before', 'after' ), true ) ) {
	throw new RuntimeException( 'Invalid crash verifier configuration.' );
}
$title    = 'WPAI128 crash ' . $key . ' ' . $mode;
$find     = static function () use ( $title ) {
	$posts = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		)
	);
	return array_values(
		array_filter(
			$posts,
			static function ( $post ) use ( $title ) {
				return $post->post_title === $title;
			}
		)
	);
};
$expected = 'after' === $mode ? 1 : 0;
if ( count( $find() ) !== $expected ) {
	throw new RuntimeException( 'Native crash checkpoint did not reach its documented commit boundary.' );
}
$identity = array( get_current_blog_id(), get_current_user_id(), (string) OAuth_Server::authenticated_mcp_client_id(), 'crash-ambiguity-create', $key . '-' . $mode );
$name     = Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( $identity ) );
$raw      = get_option( $name, false );
$receipt  = is_string( $raw ) ? json_decode( $raw, true ) : null;
if ( ! is_array( $receipt ) || 'in_progress' !== ( $receipt['state'] ?? '' ) || 0 !== (int) ( $receipt['id'] ?? -1 ) ) {
	throw new RuntimeException( 'Crash lost or falsely committed the pre-effect durable claim.' );
}
$perform_called = false;
$retry          = Create_Claim::run(
	'crash-ambiguity-create',
	array(
		'title'        => $title,
		'operation_id' => $key . '-' . $mode,
	),
	static function () {
		return true; },
	static function ( $input ) use ( &$perform_called ) {
		$perform_called = true;
		return array(
			'id' => wp_insert_post(
				array(
					'post_type'   => 'post',
					'post_title'  => $input['title'],
					'post_status' => 'draft',
				)
			),
		);
	},
	static function ( $id ) {
		return current_user_can( 'edit_post', $id ) && get_post( $id ) ? array( 'id' => (int) $id ) : null;
	}
);
if ( ! is_wp_error( $retry ) || 'create_claim_outcome_unknown' !== $retry->get_error_code() ||
	$perform_called || count( $find() ) !== $expected ) {
	throw new RuntimeException( 'Crash recovery retried a possibly committed WordPress operation.' );
}
// A separate fully committed keyed create MUST still read back exactly when
// the current WordPress authorization and actual object identity permit it.
$known_key   = $key . '-committed';
$known_title = 'WPAI128 verified ' . $key;
$known_input = array(
	'title'        => $known_title,
	'operation_id' => $known_key,
);
$allowed     = true;
$performed   = 0;
$perform     = static function ( $input ) use ( &$performed ) {
	++$performed;
	$id = wp_insert_post(
		array(
			'post_type'   => 'post',
			'post_status' => 'draft',
			'post_title'  => $input['title'],
		),
		true
	);
	return is_wp_error( $id ) ? $id : array( 'id' => (int) $id );
};
$permission  = static function () use ( &$allowed ) {
	return $allowed;
};
$readback    = static function ( $id ) {
	return current_user_can( 'edit_post', $id ) && get_post( $id ) ? array( 'id' => (int) $id ) : null;
};
$known       = Create_Claim::run( 'crash-verified-create', $known_input, $permission, $perform, $readback );
if ( is_wp_error( $known ) || empty( $known['id'] ) ) {
	throw new RuntimeException( 'Could not create the known committed recovery control.' );
}
$allowed   = false;
$denied    = Create_Claim::run( 'crash-verified-create', $known_input, $permission, $perform, $readback );
$allowed   = true;
$recovered = Create_Claim::run( 'crash-verified-create', $known_input, $permission, $perform, $readback );
if ( ! is_wp_error( $denied ) || 'create_claim_denied' !== $denied->get_error_code() ||
	is_wp_error( $recovered ) || $recovered['id'] !== $known['id'] || 1 !== $performed ) {
	throw new RuntimeException( 'Committed receipt ignored current authority or replayed the effect.' );
}
$known_identity = array( get_current_blog_id(), get_current_user_id(), (string) OAuth_Server::authenticated_mcp_client_id(), 'crash-verified-create', $known_key );
wp_delete_post( (int) $known['id'], true );
delete_option( Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( $known_identity ) ) );
if ( $expected ) {
	foreach ( $find() as $post ) {
		wp_delete_post( $post->ID, true );
	}
}
delete_option( $name );
echo "PASS: real {$mode}-Core-commit worker death kept the claim, prohibited replay, and verified authorized known receipt.\n";
