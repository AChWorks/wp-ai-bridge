<?php
/**
 * Real native WordPress #147 operator source-capacity and recovery integration.
 *
 * Disposable fixture only: never alter an installed production extension.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Source_Editing_Abilities;
use WP_AI_Bridge\Support\Operation_Content_Limits;
use WP_AI_Bridge\Support\Settings;

function wpai147_content_assert( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( '#147 operation content: ' . $message );
	}
}

$settings       = new Settings();
$policy         = new Operation_Content_Limits();
$original_grant = get_option( Settings::OPTION_NAME, array() );
$original_limit = get_option( Operation_Content_Limits::OPTION_NAME, null );
$plugin_id      = 'wpai147-content-capacity-fixture/wpai147-content-capacity-fixture.php';
$directory      = WP_PLUGIN_DIR . '/wpai147-content-capacity-fixture';
$entry          = $directory . '/wpai147-content-capacity-fixture.php';
$helper         = $directory . '/large-helper.php';
$receipt_name   = Source_Editing_Abilities::RECOVERY_OPTION;

wpai147_content_assert( ! file_exists( $directory ) && false === get_option( $receipt_name, false ), 'Fixture namespace or recovery receipt is already owned by another source operation.' );
$read_count = 0;

try {
	wpai147_content_assert( wp_mkdir_p( $directory ), 'Could not create isolated inactive plugin fixture.' );
	file_put_contents( $entry, "<?php\n/*\nPlugin Name: WPAI 147 Content Capacity Fixture\n*/\n" );
	$original = "<?php\n/*\n" . str_repeat( 'x', 2320000 ) . "\n*/\n";
	$candidate = $original . "\n// applied by isolated #147 acceptance\n";
	file_put_contents( $helper, $original );
	wp_clean_plugins_cache( true );

	$grants                                 = $settings->defaults();
	$grants[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	$grants[ Settings::GROUP_SOURCE_EDITING ]  = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$read    = wp_get_ability( 'wp-ai-bridge/source-files-read' );
	$preview = wp_get_ability( 'wp-ai-bridge/source-file-preview' );
	$apply   = wp_get_ability( 'wp-ai-bridge/source-file-apply' );
	$recover = wp_get_ability( 'wp-ai-bridge/source-file-recover' );
	wpai147_content_assert( $read && $preview && $apply && $recover, 'Bounded native source editing abilities are unavailable.' );
	$target = array( 'kind' => 'plugin', 'extension' => $plugin_id, 'file' => 'large-helper.php' );

	$default_read = $read->execute( array_merge( $target, array( 'action' => 'read' ) ) );
	wpai147_content_assert( is_wp_error( $default_read ) && 'source_file_too_large' === $default_read->get_error_code(), 'Original 2 MiB fail-closed source guard not enforced.' );

	update_option( Operation_Content_Limits::OPTION_NAME, $policy->sanitize( array( Operation_Content_Limits::SOURCE_MIB => 4 ) ), false );
	$raised = $read->execute( array_merge( $target, array( 'action' => 'read' ) ) );
	wpai147_content_assert( ! is_wp_error( $raised ) && $raised['content'] === $original, 'Real installed source larger than 2 MiB was not readable under operator policy.' );
	++$read_count;
	$plan = $preview->execute( array_merge( $target, array( 'candidate' => $candidate ) ) );
	wpai147_content_assert( ! is_wp_error( $plan ) && $plan['candidate_bytes'] > 2097152, 'Native preview could not validate above-default exact source candidate.' );
	$binding = array(
		'candidate'        => $candidate,
		'preimage_sha256'  => $plan['preimage_sha256'],
		'candidate_sha256' => $plan['candidate_sha256'],
		'candidate_id'     => $plan['candidate_id'],
	);
	$committed = $apply->execute( array_merge( $target, $binding ) );
	wpai147_content_assert( ! is_wp_error( $committed ) && 'success' === $committed['outcome'] && $candidate === file_get_contents( $helper ), 'Native CAS source apply failed for above-default inert fixture.' );

	// Simulate a durable exact-owner receipt after lost acknowledgment, then
	// lower the policy. Normal reads must reject while recovery can still
	// verify/restore the exact original bytes and clear only its own receipt.
	$receipt = array(
		'version'          => 1,
		'token'            => wp_generate_uuid4(),
		'kind'             => 'plugin',
		'extension'        => $plugin_id,
		'file'             => 'large-helper.php',
		'canonical_root'   => wp_normalize_path( realpath( $directory ) ),
		'canonical_path'   => wp_normalize_path( realpath( $helper ) ),
		'preimage_sha256'  => hash( 'sha256', $original ),
		'candidate_sha256' => hash( 'sha256', $candidate ),
		'candidate_bytes'  => strlen( $candidate ),
		'preimage'         => $original,
		'created_gmt'      => gmdate( 'c' ),
	);
	wpai147_content_assert( add_option( $receipt_name, $receipt, '', false ), 'Could not persist synthetic exact private pending recovery claim.' );
	update_option( Operation_Content_Limits::OPTION_NAME, $policy->sanitize( array( Operation_Content_Limits::SOURCE_MIB => 1 ) ), false );
	$small = $read->execute( array_merge( $target, array( 'action' => 'read' ) ) );
	wpai147_content_assert( is_wp_error( $small ) && 'source_file_too_large' === $small->get_error_code(), 'Lowered new-read admission unexpectedly allowed above-policy source.' );

	$grants[ Settings::GROUP_SOURCE_EDITING ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wpai147_content_assert( is_wp_error( $recover->execute( array( 'candidate_sha256' => $receipt['candidate_sha256'] ) ) ), 'Revoked Source Editing grant allowed recovery.' );
	$grants[ Settings::GROUP_SOURCE_EDITING ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );
	$restored = $recover->execute( array( 'candidate_sha256' => $receipt['candidate_sha256'] ) );
	wpai147_content_assert( ! is_wp_error( $restored ) && true === $restored['recovered'], 'Exact prior pending recovery was stranded when the administrator lowered source limit.' );
	wpai147_content_assert( $original === file_get_contents( $helper ) && false === get_option( $receipt_name, false ), 'Recovery did not restore exact bytes and clear its own receipt.' );

	echo "PASS: #147 real WordPress source >2MiB read/preview/apply, lower-cap new admission and exact recovery, revocation.\n";
} finally {
	update_option( Settings::OPTION_NAME, $original_grant, false );
	if ( null === $original_limit ) {
		delete_option( Operation_Content_Limits::OPTION_NAME );
	} else {
		update_option( Operation_Content_Limits::OPTION_NAME, $original_limit, false );
	}
	// Delete the named test receipt only when it still belongs to this exact
	// synthetic claim. An unresolved claim is a test failure, not safe cleanup.
	$current_receipt = get_option( $receipt_name, false );
	if ( is_array( $current_receipt ) && isset( $receipt ) && isset( $current_receipt['token'] ) && $current_receipt['token'] === $receipt['token'] ) {
		delete_option( $receipt_name );
	}
	if ( is_file( $helper ) ) {
		unlink( $helper );
	}
	if ( is_file( $entry ) ) {
		unlink( $entry );
	}
	if ( is_dir( $directory ) ) {
		rmdir( $directory );
	}
	wp_clean_plugins_cache( true );
}
