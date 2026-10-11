<?php
/**
 * Independent WordPress worker: distinct identity at the final claim slot.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Create_Claim;
use WP_AI_Bridge\Workspace\Store;

$key  = getenv( 'WPAI_CAP_KEY' );
$mode = getenv( 'WPAI_CAP_MODE' );
$slot = getenv( 'WPAI_CAP_SLOT' );
if ( ! is_string( $key ) || ! preg_match( '/^cap-[a-z0-9-]{8,55}$/D', $key ) ||
	! in_array( $mode, array( 'operation', 'canonical' ), true ) ||
	! in_array( $slot, array( 'one', 'two' ), true ) ) {
	throw new RuntimeException( 'Invalid isolated capacity-race worker.' );
}
$dir = WP_CONTENT_DIR . '/wpai128-capacity-' . $key . '-' . $mode;
if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
	throw new RuntimeException( 'Could not establish capacity barrier.' );
}
file_put_contents( $dir . '/' . $slot . '.ready', 'ready' );
$other    = 'one' === $slot ? 'two' : 'one';
$deadline = microtime( true ) + 45;
while ( ! is_file( $dir . '/' . $other . '.ready' ) ) {
	if ( microtime( true ) > $deadline ) {
		throw new RuntimeException( 'Capacity race peer did not start.' );
	}
	usleep( 20000 );
}
$title = 'WPAI128 cap ' . $key . ' ' . $slot;
if ( 'operation' === $mode ) {
	$result = Create_Claim::run(
		'capacity-race-create',
		array(
			'title'        => $title,
			'operation_id' => $key . '-' . $slot,
		),
		static function () {
			return true; },
		static function ( $input ) {
			$id = wp_insert_post(
				array(
					'post_type'   => 'post',
					'post_status' => 'draft',
					'post_title'  => $input['title'],
				),
				true
			);
			return is_wp_error( $id ) ? $id : array( 'id' => (int) $id );
		},
		static function ( $id ) {
			return current_user_can( 'edit_post', $id ) && get_post( $id ) ? array( 'id' => (int) $id ) : null;
		}
	);
} else {
	$store  = new Store();
	$result = $store->create_document(
		array(
			'project_ref' => $key . '-' . $slot,
			'key'         => 'capacity-brief',
			'title'       => $title,
			'content'     => 'One newly admitted canonical document at the site-wide cap.',
		)
	);
}
if ( is_wp_error( $result ) ) {
	if ( 'create_claim_capacity' !== $result->get_error_code() ) {
		throw new RuntimeException( 'Unexpected capacity race result: ' . $result->get_error_code() );
	}
	echo "WPAI_CAP_RESULT=capacity\n";
} elseif ( is_array( $result ) && ! empty( $result['id'] ) ) {
	echo 'WPAI_CAP_RESULT=created:' . (int) $result['id'] . "\n";
} else {
	throw new RuntimeException( 'Unexpected capacity worker return value.' );
}
