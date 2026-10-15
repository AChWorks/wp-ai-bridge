<?php
/**
 * #134 transient feasibility probe on disposable WordPress/official Adapter.
 * Never ship: reconstructed Core Media path lacks the durable effect claim.
 */
require_once __DIR__ . '/issue134-review-helper.php';

use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Private_Artifact_Writer;

global $wpdb;
$settings = new Settings();
$prior = get_option( Settings::OPTION_NAME, false );
$grants = $settings->defaults();
$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
update_option( Settings::OPTION_NAME, $grants, false );
$leases = array();
$token = '';
$store = null;
$scratch = '';
$attachment = 0;
try {
	wpai134_review_require( defined( 'SAVEQUERIES' ) && SAVEQUERIES, 'Test-only SAVEQUERIES required.' );
	wpai134_review_require( realpath( get_temp_dir() ) === realpath( WP_CONTENT_DIR ), 'Served-temp test fixture missing.' );
	list( $token, $session, $store ) = wpai134_review_connect( get_current_user_id() );

	$marker = 'WPAI134_TEST_ONLY_NON_SECRET_' . bin2hex( random_bytes( 6 ) );
	$leases[] = wpai134_review_stage( $token, $session, 'text', $marker );
	$needle = bin2hex( $marker );
	$exposure = false;
	$sql_bytes = 0;
	foreach ( (array) $wpdb->queries as $entry ) {
		$sql = is_array( $entry ) ? (string) ( $entry[0] ?? '' ) : '';
		$sql_bytes += strlen( $sql );
		$exposure = $exposure || false !== stripos( $sql, $needle );
	}
	echo 'SAVEQUERIES_RECOVERABLE_PAYLOAD=' . ( $exposure ? 'YES' : 'NO' ) . PHP_EOL;
	echo 'SAVED_QUERY_COUNT=' . count( $wpdb->queries ) . ' SQL_BYTES=' . $sql_bytes . PHP_EOL;
	echo 'MAX_ALLOWED_PACKET=' . $wpdb->get_var( 'SELECT @@max_allowed_packet' ) . PHP_EOL;

	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jphkAAAAASUVORK5CYII=', true );
	wpai134_review_require( is_string( $png ) && strlen( $png ) > 50, 'Synthetic image missing.' );
	$media_id = wpai134_review_stage( $token, $session, 'media', $png );
	$leases[] = $media_id;

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$scratch = wp_tempnam( 'wpai134-gateA.png', sys_get_temp_dir() );
	wpai134_review_require( is_string( $scratch ) && is_file( $scratch ), 'Safe scratch unavailable.' );
	$canonical = realpath( $scratch );
	wpai134_review_require( is_string( $canonical ) && 0 !== strpos( $canonical . '/', rtrim( realpath( ABSPATH ), '/' ) . '/' ), 'Scratch is under public root.' );
	wpai134_review_require( chmod( $scratch, 0600 ) && 0600 === ( fileperms( $scratch ) & 0777 ), 'Scratch permissions wrong.' );
	$offset = 0;
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT byte_offset,data FROM %i WHERE id = %s ORDER BY byte_offset',
		$wpdb->prefix . 'wpai_private_chunk', $media_id ) );
	foreach ( (array) $rows as $row ) {
		$part = (string) $row->data;
		wpai134_review_require( (int) $row->byte_offset === $offset && strlen( $part ) === file_put_contents( $scratch, $part, FILE_APPEND ), 'Private DB source not reconstructed.' );
		$offset += strlen( $part );
	}
	wpai134_review_require( $offset === strlen( $png ) && hash_file( 'sha256', $scratch ) === hash( 'sha256', $png ), 'Scratch byte parity failed.' );
	$attachment = media_handle_sideload( array( 'name' => 'wpai134-gateA.png', 'tmp_name' => $scratch, 'error' => 0, 'size' => strlen( $png ) ), 0 );
	wpai134_review_require( ! is_wp_error( $attachment ) && is_int( $attachment ) && $attachment > 0, 'Core Media rejected DB-backed reconstructed private file.' );
	$destination = get_attached_file( $attachment );
	wpai134_review_require( is_string( $destination ) && is_file( $destination ) && hash_file( 'sha256', $destination ) === hash( 'sha256', $png ), 'Core Media byte parity failed.' );
	echo 'DB_SCRATCH_NATIVE_MEDIA_PATH_FEASIBLE=YES' . PHP_EOL;
	echo 'DURABLE_NO_REPLAY_EFFECT_PROVIDED_BY_THIS_PROBE=NO' . PHP_EOL;

	$writer = Private_Artifact_Writer::begin( 1024 );
	echo 'PUBLISHED_OUTBOUND_WRITER_ON_SERVED_TEMP=' . ( is_wp_error( $writer ) ? $writer->get_error_code() : 'unexpected_success' ) . PHP_EOL;
	echo 'PEAK_PHP_MEMORY_BYTES=' . memory_get_peak_usage( true ) . PHP_EOL;
	if ( $exposure ) {
		throw new RuntimeException( 'Gate A security blocker: synthetic private bytes are recoverable from SAVEQUERIES SQL.' );
	}
} finally {
	if ( is_int( $attachment ) && $attachment > 0 ) { wp_delete_attachment( $attachment, true ); }
	if ( is_string( $scratch ) && is_file( $scratch ) ) { wp_delete_file( $scratch ); }
	if ( '' !== $token ) {
		foreach ( $leases as $lease ) { wpai134_review_transfer( $token, $session, array( 'action' => 'cancel', 'transfer_id' => $lease ) ); }
		if ( is_object( $store ) ) { $store->revoke( $token ); }
	}
	if ( false === $prior ) { delete_option( Settings::OPTION_NAME ); }
	else { update_option( Settings::OPTION_NAME, $prior, false ); }
}
