<?php
/**
 * Disposable native WXR semantic roundtrip with the official WordPress Importer.
 *
 * Only in an isolated CI WordPress site. No Bridge import API/transport is
 * implemented here, and no production import semantics are claimed.
 *
 * @package WP_AI_Bridge
 */

function wpai139_roundtrip_assert( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
}

$actor         = get_current_user_id();
$filename      = '';
$original_id   = 0;
$imported_id   = 0;
$taxonomy_name = 'wpai139_roundtrip_tax';
$post_type     = 'wpai139_roundtrip';
$title         = 'Native WXR semantic roundtrip';
$source        = "WXR real importer byte-precise UTF-8 \n سلام ایران 🌍";
$marker        = 'roundtrip-' . bin2hex( random_bytes( 6 ) );
$http_calls    = 0;
$deny_http     = static function () use ( &$http_calls ) {
	++$http_calls;
	return new WP_Error( 'fixture_blocked_network', 'Network fetch prohibited for this isolated WXR fixture.' );
};

try {
	wpai139_roundtrip_assert(
		$actor > 0 && current_user_can( 'export' ) && current_user_can( 'import' ),
		'Official importer and Core exporter require native admin authority in this test.'
	);
	register_post_type(
		$post_type,
		array(
			'public'     => true,
			'can_export' => true,
			'label'      => 'WXR Roundtrip Fixture',
		)
	);
	register_taxonomy( $taxonomy_name, array( $post_type ), array( 'public' => true ) );
	$original_id = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_author'  => $actor,
			'post_title'   => $title,
			'post_content' => wp_slash( $source ),
		),
		true
	);
	wpai139_roundtrip_assert( ! is_wp_error( $original_id ) && (int) $original_id > 0, 'Native source post creation failed.' );
	wpai139_roundtrip_assert(
		! is_wp_error( wp_set_object_terms( $original_id, 'WXR Test Taxonomy Term', $taxonomy_name ) ),
		'Native WordPress term association failed.'
	);
	update_post_meta( $original_id, 'wpai139_roundtrip_key', $marker );

	require_once ABSPATH . 'wp-admin/includes/export.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	ob_start();
	try {
		export_wp( array( 'content' => $post_type ) );
		$xml = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	wpai139_roundtrip_assert( is_string( $xml ) && false !== strpos( $xml, $marker ), 'Core exporter omitted exact fixture.' );
	$filename = wp_tempnam( 'wpai139-semantic-roundtrip.xml' );
	wpai139_roundtrip_assert( '' !== $filename && strlen( $xml ) === file_put_contents( $filename, $xml ), 'Native exporter file was not byte-identical.' );
	$expected_hash = hash( 'sha256', $xml );
	wpai139_roundtrip_assert( hash_file( 'sha256', $filename ) === $expected_hash, 'Core WXR file digest changed before import.' );

	// WordPress Importer is initialized through its *normal* entrypoint when
	// WP_LOAD_IMPORTERS is defined before WordPress/bootstrap loads plugins.
	// The disposable CLI runner sets this flag in wp-config.php before this
	// fresh process. Re-including the plugin main file after bootstrap would
	// redeclare functions; require_once cannot re-run an early-returned file.
	wpai139_roundtrip_assert(
		defined( 'WP_LOAD_IMPORTERS' ) && WP_LOAD_IMPORTERS && class_exists( 'WP_Import', false ),
		'Official WordPress Importer must be bootstrapped by the native loader.'
	);
	$importer = new WP_Import();

	// Provider owns parsing. We do not recreate WXR/WordPress post semantics.
	$parsed = $importer->parse( $filename );
	wpai139_roundtrip_assert(
		! is_wp_error( $parsed ) && is_array( $parsed ) &&
		! empty( $parsed['posts'] ) && 1.2 === (float) ( $parsed['version'] ?? 0 ),
		'Official importer did not parse a valid Core-generated WXR document.'
	);
	$matched = false;
	foreach ( $parsed['posts'] as $post ) {
		if ( ( $post['post_type'] ?? '' ) === $post_type && false !== strpos( (string) ( $post['post_content'] ?? '' ), 'سلام ایران' ) ) {
			$matched = true;
			break;
		}
	}
	wpai139_roundtrip_assert( $matched, 'Official WXR parser changed the multilingual source content.' );

	// Remove synthetic source so Core import must create the content instead
	// of treating its original title/date as an already-existing post.
	wp_delete_post( $original_id, true );
	$original_id = 0;
	// No remote attachments and no user creation/mapping from untrusted input.
	unset( $_POST['imported_authors'], $_POST['user_new'], $_POST['fetch_attachments'] );
	add_filter( 'pre_http_request', $deny_http, 1 );
	ob_start();
	try {
		$importer->import( $filename, array( 'rewrite_urls' => false ) );
		$reported_output = ob_get_contents();
	} finally {
		ob_end_clean();
		remove_filter( 'pre_http_request', $deny_http, 1 );
	}
	wpai139_roundtrip_assert(
		is_string( $reported_output ) && 0 === $http_calls,
		'Native importer initiated a remote network fetch or failed its normal output.'
	);
	$recreated = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'title'          => $title,
			'posts_per_page' => 4,
		)
	);
	wpai139_roundtrip_assert( 1 === count( $recreated ), 'Official importer failed to restore exactly one synthetic CPT post.' );
	$imported_id = (int) $recreated[0]->ID;
	wpai139_roundtrip_assert(
		$source === (string) $recreated[0]->post_content &&
		get_post_meta( $imported_id, 'wpai139_roundtrip_key', true ) === $marker &&
		(int) $recreated[0]->post_author === $actor,
		'Official importer changed content, metadata or author-mapping default.'
	);
	$terms = wp_get_object_terms( $imported_id, $taxonomy_name, array( 'fields' => 'names' ) );
	wpai139_roundtrip_assert(
		! is_wp_error( $terms ) && in_array( 'WXR Test Taxonomy Term', $terms, true ),
		'Official importer lost the native taxonomy relationship.'
	);
	wpai139_roundtrip_assert( hash_file( 'sha256', $filename ) === $expected_hash, 'Official importer modified source bytes.' );
	echo "PASS: #139 native Core export → official WordPress Importer parse/create CPT, UTF-8, term and metadata roundtrip.\n";
} finally {
	remove_filter( 'pre_http_request', $deny_http, 1 );
	if ( $original_id > 0 ) {
		wp_delete_post( $original_id, true );
	}
	if ( $imported_id > 0 ) {
		wp_delete_post( $imported_id, true );
	}
	if ( '' !== $filename ) {
		wp_delete_file( $filename );
	}
}
