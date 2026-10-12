<?php
/**
 * Controlled two-worker native Media hold and unrelated private admission.
 *
 * @package WP_AI_Bridge
 */
require_once __DIR__ . '/issue134-review-helper.php';

use WP_AI_Bridge\Support\Settings;

$key  = getenv( 'WPAI134_MEDIA_KEY' );
$mode = getenv( 'WPAI134_MEDIA_ACTION' );
wpai134_review_require(
	is_string( $key ) && 1 === preg_match( '/^media-[a-z0-9-]{10,50}$/D', $key ) &&
	in_array( $mode, array( 'setup', 'importer', 'contender', 'verify', 'cleanup' ), true ),
	'Invalid disposable Media lock test request.'
);
$dir      = WP_CONTENT_DIR . '/wpai134-media-' . $key;
$admin    = get_current_user_id();
$settings = new Settings();
if ( 'setup' === $mode ) {
	wpai134_review_require( wp_mkdir_p( $dir ), 'Could not create disposable Media coordination.' );
	$old                                     = get_option( Settings::OPTION_NAME, false );
	$grants                                  = $settings->defaults();
	$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	list( $token, $session, $store ) = wpai134_review_connect( $admin );
	$png                             = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=', true );
	$source                          = wpai134_review_stage( $token, $session, 'media', $png );
	$unrelated                       = wpai134_review_stage( $token, $session, 'text', 'unrelated prior reservation' );
	$state                           = array(
		'source'    => $source,
		'unrelated' => $unrelated,
		'sha256'    => hash( 'sha256', $png ),
		'original'  => $old,
	);
	wpai134_review_require( false !== file_put_contents( $dir . '/state.json', wp_json_encode( $state ) ), 'Media fixture state was not saved.' );
	$store->revoke( $token );
	echo "PASS: Media fixture staged two independent private leases.\n";
	return;
}
$state = is_file( $dir . '/state.json' ) ? json_decode( (string) file_get_contents( $dir . '/state.json' ), true ) : null;
wpai134_review_require( is_array( $state ) && ! empty( $state['source'] ), 'Media fixture identity missing.' );

if ( 'cleanup' === $mode ) {
	if ( is_file( $dir . '/result.json' ) ) {
		$result = json_decode( (string) file_get_contents( $dir . '/result.json' ), true );
		if ( ! empty( $result['media_id'] ) ) {
			wp_delete_attachment( (int) $result['media_id'], true );
		}
	}
	if ( false === $state['original'] ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $state['original'], false );
	}
	foreach ( array( 'state.json', 'result.json', 'native.locked', 'native.release' ) as $file ) {
		if ( is_file( $dir . '/' . $file ) ) {
			unlink( $dir . '/' . $file );
		}
	}
	rmdir( $dir );
	return;
}

if ( 'verify' === $mode ) {
	$result = json_decode( (string) file_get_contents( $dir . '/result.json' ), true );
	$id     = (int) ( $result['media_id'] ?? 0 );
	wpai134_review_require( $id > 0 && 'attachment' === get_post_type( $id ), 'Native Media effect was not committed exactly once.' );
	$path = get_attached_file( $id );
	wpai134_review_require( is_string( $path ) && hash_file( 'sha256', $path ) === $state['sha256'], 'Native Media import changed source digest.' );
	list( $token, $session, $store ) = wpai134_review_connect( $admin );
	$replay                          = wpai134_review_call(
		$token,
		$session,
		'wp-ai-bridge/transfer-media-import',
		array(
			'transfer_id' => $state['source'],
			'filename'    => 'duplicate.png',
		)
	);
	wpai134_review_require( ! empty( $replay['result']['isError'] ), 'Concurrent import lock allowed native duplicate.' );
	$store->revoke( $token );
	echo "PASS: Media source admitted only one effect and retained exact SHA256.\n";
	return;
}
list( $token, $session, $store ) = wpai134_review_connect( $admin );
if ( 'importer' === $mode ) {
	// WordPress runs this native provider callback inside the exact
	// media_handle_sideload() call while the importer owns the .meta flock.
	$hold = static function ( $metadata, $attachment_id ) use ( $dir ) {
		wpai134_review_require(
			false !== file_put_contents( $dir . '/native.locked', (string) $attachment_id ),
			'Cannot publish native Media-phase barrier.'
		);
		$deadline = microtime( true ) + 20;
		while ( ! file_exists( $dir . '/native.release' ) ) {
			if ( microtime( true ) > $deadline ) {
				throw new RuntimeException( 'Concurrent admission never released native provider hold.' );
			}
			usleep( 20000 );
		}
		return $metadata;
	};
	add_filter( 'wp_generate_attachment_metadata', $hold, 10, 2 );
	try {
		$response = wpai134_review_call(
			$token,
			$session,
			'wp-ai-bridge/transfer-media-import',
			array(
				'transfer_id' => $state['source'],
				'filename'    => 'concurrent-review.png',
			)
		);
		wpai134_review_require(
			true === ( $response['result']['structuredContent']['success'] ?? false ),
			'Native Media importer failed after concurrent admission.'
		);
		$data = $response['result']['structuredContent']['data'];
		wpai134_review_require( false !== file_put_contents( $dir . '/result.json', wp_json_encode( $data ) ), 'Cannot persist imported WordPress identity.' );
		echo "WPAI134_MEDIA_RESULT=committed\n";
	} finally {
		remove_filter( 'wp_generate_attachment_metadata', $hold );
		$store->revoke( $token );
	}
	return;
}
$deadline = microtime( true ) + 15;
while ( ! file_exists( $dir . '/native.locked' ) ) {
	if ( microtime( true ) > $deadline ) {
		throw new RuntimeException( 'Native Media effect did not enter the controlled slow provider phase.' );
	}
	usleep( 25000 );
}
try {
	$began         = microtime( true );
	$lease         = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'begin',
			'purpose'     => 'text',
			'total_bytes' => 1,
			'sha256'      => hash( 'sha256', 'B' ),
		)
	);
	$begin_elapsed = microtime( true ) - $began;
	wpai134_review_require( true === ( $lease['result']['structuredContent']['success'] ?? false ), 'Unrelated admission failed during active native Media effect.' );
	$new_id = $lease['result']['structuredContent']['data']['transfer_id'];

	$cancel_begin   = microtime( true );
	$unrelated      = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'cancel',
			'transfer_id' => $state['unrelated'],
		)
	);
	$cancel_elapsed = microtime( true ) - $cancel_begin;
	wpai134_review_require( true === ( $unrelated['result']['structuredContent']['success'] ?? false ), 'Unrelated cancellation was convoyed by Media lock.' );

	$busy_begin   = microtime( true );
	$active       = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'cancel',
			'transfer_id' => $state['source'],
		)
	);
	$busy_elapsed = microtime( true ) - $busy_begin;
	wpai134_review_require( ! empty( $active['result']['isError'] ), 'Active Media effect cancellation was incorrectly accepted.' );
	$own = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'cancel',
			'transfer_id' => $new_id,
		)
	);
	wpai134_review_require( true === ( $own['result']['structuredContent']['success'] ?? false ), 'New unrelated lease did not cancel.' );
	wpai134_review_require(
		$begin_elapsed < 3 && $cancel_elapsed < 3 && $busy_elapsed < 3,
		'Media effect held the global admission lock or blocked cancellation.'
	);
	echo "PASS: #134 C independent begin/cancel proceeded during held native Media provider callback (all bounded).\n";
} finally {
	file_put_contents( $dir . '/native.release', 'continue' );
	$store->revoke( $token );
}
