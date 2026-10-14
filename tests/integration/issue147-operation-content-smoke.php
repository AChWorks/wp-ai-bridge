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

$settings        = new Settings();
$policy          = new Operation_Content_Limits();
$state_name      = 'wpai147_operation_content_fixture_state';
$state           = get_option( $state_name, false );
wpai147_content_assert( is_array( $state ) && array_key_exists( 'original_grant', $state ) && array_key_exists( 'original_limit', $state ), 'Cross-request fixture preparation is missing.' );
$original_grant = $state['original_grant'];
$original_limit = $state['original_limit'];
$plugin_id      = 'wpai147-content-capacity-fixture/wpai147-content-capacity-fixture.php';
$directory      = WP_PLUGIN_DIR . '/wpai147-content-capacity-fixture';
$entry          = $directory . '/wpai147-content-capacity-fixture.php';
$helper         = $directory . '/large-helper.php';
$receipt_name   = Source_Editing_Abilities::RECOVERY_OPTION;
$original       = "<?php\n/*\n" . str_repeat( 'x', 2320000 ) . "\n*/\n";
$candidate      = $original . "\n// applied by isolated #147 acceptance\n";
$grants         = get_option( Settings::OPTION_NAME, array() );
$target         = array( 'kind' => 'plugin', 'extension' => $plugin_id, 'file' => 'large-helper.php' );

try {
	wpai147_content_assert( is_file( $entry ) && is_file( $helper ), 'Staged fixture from previous WordPress request was not preserved.' );
	$read    = wp_get_ability( 'wp-ai-bridge/source-files-read' );
	$preview = wp_get_ability( 'wp-ai-bridge/source-file-preview' );
	$apply   = wp_get_ability( 'wp-ai-bridge/source-file-apply' );
	$recover = wp_get_ability( 'wp-ai-bridge/source-file-recover' );
	wpai147_content_assert( $read instanceof WP_Ability && $preview instanceof WP_Ability && $apply instanceof WP_Ability && $recover instanceof WP_Ability, 'Native WordPress source editing abilities are unavailable.' );
	wpai147_content_assert( $preview->get_input_schema()['properties']['candidate']['maxLength'] >= strlen( $candidate ), 'Fresh WordPress registry did not bind the new operator policy to native JSON schema.' );
	$raised = $read->execute( array_merge( $target, array( 'action' => 'read' ) ) );
	wpai147_content_assert( ! is_wp_error( $raised ) && $raised['content'] === $original, 'Real installed source larger than 2 MiB was not readable under operator policy.' );
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
	if ( null === $original_grant ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original_grant, false );
	}
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
	delete_option( $state_name );
}
