<?php
/** Direct-filesystem denial and control-plane marker coverage for Issue #46. */
use WP_AI_Bridge\Support\Settings;

function wpai_issue46_fs_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, array() );
$target   = array(
	'kind'      => 'plugin',
	'extension' => 'wp-ai-bridge/wp-ai-bridge.php',
	'file'      => 'wp-ai-bridge.php',
);
try {
	$enabled = $settings->defaults();
	$enabled[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	$enabled[ Settings::GROUP_SOURCE_EDITING ]   = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );

	$read    = wp_get_ability( 'wp-ai-bridge/source-files-read' );
	$preview = wp_get_ability( 'wp-ai-bridge/source-file-preview' );
	$apply   = wp_get_ability( 'wp-ai-bridge/source-file-apply' );
	$current = $read->execute( array_merge( array( 'action' => 'read' ), $target ) );
	wpai_issue46_fs_assert( ! is_wp_error( $current ), 'Could not read the Bridge control-plane target.' );
	wpai_issue46_fs_assert( true === $current['target']['control_plane_risk'], 'Active Bridge target did not expose control-plane risk.' );
	wpai_issue46_fs_assert( false === $current['target']['writable'], 'Filesystem-denial smoke must run as an OS user that cannot write the installed Bridge file.' );

	$candidate = $current['content'] . "\n";
	$bound     = $preview->execute( array_merge( $target, array( 'candidate' => $candidate ) ) );
	wpai_issue46_fs_assert( ! is_wp_error( $bound ), 'Read-only preview should remain available for a non-writable exact target.' );
	$before = hash( 'sha256', $current['content'] );
	$result = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $candidate,
				'preimage_sha256'  => $bound['preimage_sha256'],
				'candidate_sha256' => $bound['candidate_sha256'],
				'candidate_id'     => $bound['candidate_id'],
			)
		)
	);
	wpai_issue46_fs_assert( is_wp_error( $result ) && 'source_file_not_directly_writable' === $result->get_error_code(), 'Non-writable source apply did not return the direct-filesystem denial.' );
	wpai_issue46_fs_assert( $before === hash_file( 'sha256', WP_PLUGIN_DIR . '/wp-ai-bridge/wp-ai-bridge.php' ), 'Filesystem denial changed the control-plane source file.' );
	echo "PASS: Issue #46 direct filesystem denial and control-plane marker.\n";
} finally {
	update_option( Settings::OPTION_NAME, $original, false );
}
