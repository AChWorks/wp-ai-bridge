<?php
/**
 * Disposable native WordPress WXR exporter byte-integrity feasibility.
 *
 * Core export_wp() emits headers and XML, so capturing output is appropriate
 * here in WP-CLI only. This is NOT a safe general MCP/REST export endpoint,
 * a private file-transfer implementation, or importer acceptance.
 *
 * @package WP_AI_Bridge
 */

function wpai139_assert( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
}

$actor        = get_current_user_id();
$created_post = 0;
$temporary    = '';
$marker       = 'wpai139-native-' . bin2hex( random_bytes( 6 ) );
$post_type    = 'wpai139_fixture';
$taxonomy     = 'wpai139_topic';

try {
	wpai139_assert( $actor > 0 && current_user_can( 'export' ), 'WordPress native export capability required.' );
	register_post_type(
		$post_type,
		array(
			'public'      => true,
			'can_export'  => true,
			'show_ui'     => true,
			'supports'    => array( 'title', 'editor', 'custom-fields' ),
		)
	);
	register_taxonomy( $taxonomy, array( $post_type ), array( 'public' => true ) );
	$content = "WXR exact UTF-8 - سلام ایران 🌍\n" . $marker;
	$created_post = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_title'   => 'WXR native export fixture',
			'post_content' => wp_slash( $content ),
		),
		true
	);
	wpai139_assert( ! is_wp_error( $created_post ) && (int) $created_post > 0, 'WordPress fixture content insert failed.' );
	wpai139_assert( ! is_wp_error( wp_set_object_terms( $created_post, 'Native WXR Fixture', $taxonomy, false ) ), 'Native taxonomy fixture failed.' );
	update_post_meta( $created_post, 'wpai139_export_marker', $marker );

	// Test-only CLI capture; export_wp() may set download headers, so the
	// regular MCP JSON request must NOT use this method in production.
	require_once ABSPATH . 'wp-admin/includes/export.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	ob_start();
	try {
		export_wp( array( 'content' => $post_type ) );
		$xml = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	wpai139_assert( is_string( $xml ) && strlen( $xml ) > 200, 'Core WXR generated no content.' );
	wpai139_assert(
		false !== strpos( $xml, $marker ) &&
		false !== strpos( $xml, '<wp:post_type>' . $post_type . '</wp:post_type>' ),
		'Core exporter dropped selected CPT or non-secret fixture metadata.'
	);

	// Treat the export as opaque exact bytes, not as an MCP JSON string.
	$temporary = wp_tempnam( 'wpai139-wxr.xml' );
	wpai139_assert( is_string( $temporary ) && '' !== $temporary, 'Could not create disposable WXR file.' );
	wpai139_assert( strlen( $xml ) === file_put_contents( $temporary, $xml ), 'Short WXR file write.' );
	wpai139_assert(
		strlen( $xml ) === filesize( $temporary ) &&
		hash( 'sha256', $xml ) === hash_file( 'sha256', $temporary ),
		'Native WXR artifact file lost bytes or SHA-256 integrity.'
	);

	// Secure preliminary XML walk for format provenance. Native import
	// semantics are separately owned by the official importer plugin.
	wpai139_assert( class_exists( 'XMLReader' ), 'XMLReader unavailable for test-only WXR format inspection.' );
	$reader = new XMLReader();
	wpai139_assert( $reader->open( $temporary, null, LIBXML_NONET ), 'Generated native WXR was not readable XML.' );
	$reader->setParserProperty( XMLReader::SUBST_ENTITIES, false );
	$seen_rss     = false;
	$seen_version = false;
	$seen_item    = false;
	try {
		while ( $reader->read() ) {
			wpai139_assert(
				XMLReader::DOC_TYPE !== $reader->nodeType &&
				XMLReader::ENTITY !== $reader->nodeType,
				'Native export included a forbidden DTD/entity declaration.'
			);
			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}
			if ( 'rss' === $reader->localName ) {
				$seen_rss = true;
			}
			if ( 'wxr_version' === $reader->localName && 'http://wordpress.org/export/1.2/' === $reader->namespaceURI ) {
				$seen_version = true;
			}
			if ( 'item' === $reader->localName ) {
				$seen_item = true;
			}
		}
	} finally {
		$reader->close();
	}
	wpai139_assert( $seen_rss && $seen_version && $seen_item, 'Exporter output lacks canonical WXR RSS/version/content identity.' );
	echo 'PASS: #139 native Core WXR CPT export; byte length and SHA-256 artifact integrity (CLI only).' . "\n";
} finally {
	if ( (int) $created_post > 0 ) {
		wp_delete_post( $created_post, true );
	}
	if ( '' !== $temporary ) {
		wp_delete_file( $temporary );
	}
}
