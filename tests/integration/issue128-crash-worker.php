<?php
/**
 * Terminates a real PHP worker before/after the native Core write, but before
 * the create receipt can be marked committed. Disposable WordPress only.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Create_Claim;

$key  = getenv( 'WPAI_CRASH_KEY' );
$mode = getenv( 'WPAI_CRASH_MODE' );
if ( ! is_string( $key ) || ! preg_match( '/^crash-[a-z0-9-]{8,55}$/D', $key ) ||
	! in_array( $mode, array( 'before', 'after' ), true ) ) {
	throw new RuntimeException( 'Invalid native crash checkpoint.' );
}
$title = 'WPAI128 crash ' . $key . ' ' . $mode;
Create_Claim::run(
	'crash-ambiguity-create',
	array(
		'title'        => $title,
		'operation_id' => $key . '-' . $mode,
	),
	static function () {
		return true; },
	static function ( $input ) use ( $mode ) {
		if ( 'before' === $mode ) {
			echo "WPAI_CRASH_CHECKPOINT=before\n";
			flush();
			exit( 37 ); // No native WordPress effect has begun.
		}
		$id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => $input['title'],
			),
			true
		);
		if ( is_wp_error( $id ) || (int) $id < 1 ) {
			throw new RuntimeException( 'Native commit failed before crash checkpoint.' );
		}
		echo "WPAI_CRASH_CHECKPOINT=after\n";
		flush();
		exit( 38 ); // Committed to WordPress, no ID returned/receipt written.
	},
	static function ( $id ) {
		return current_user_can( 'edit_post', $id ) && get_post( $id ) ? array( 'id' => (int) $id ) : null;
	}
);
throw new RuntimeException( 'Unexpectedly returned from a deliberately terminated WordPress worker.' );
