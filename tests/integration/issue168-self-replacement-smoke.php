<?php
/**
 * Disposable end-of-lane proof: the Bridge can replace its own active code,
 * retaining installed identity, OAuth and stored Workspace state.
 * This MUST run last because the native replacement removes this test file.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

$ability = wp_get_ability( 'wp-ai-bridge/extension-lifecycle' );
if ( ! $ability instanceof WP_Ability ) {
	throw new RuntimeException( 'Cannot inspect the native extension lifecycle.' );
}
$old_version = WP_AI_BRIDGE_VERSION;
$new_version = $old_version . '.1';
$target = 'wp-ai-bridge/wp-ai-bridge.php';
$installed = get_plugins();
if ( ! isset( $installed[ $target ] ) || $old_version !== (string) $installed[ $target ]['Version'] || ! is_plugin_active( $target ) ) {
	throw new RuntimeException( 'Canonical Bridge self-update preimage is unavailable.' );
}
$old_settings = get_option( Settings::OPTION_NAME, array() );
$old_oauth_instance = get_option( 'wp_ai_bridge_oauth_instance', false );
$root = WP_PLUGIN_DIR . '/wp-ai-bridge';
$zipfile = wp_tempnam( 'wpai168-self.zip' );
if ( ! is_string( $zipfile ) || ! class_exists( 'ZipArchive' ) ) {
	throw new RuntimeException( 'Isolated self-replacement ZIP fixture cannot run.' );
}
$zip = new ZipArchive();
if ( true !== $zip->open( $zipfile, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	throw new RuntimeException( 'Could not create self-replacement fixture.' );
}
$number = 0;
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $node ) {
	if ( ! $node->isFile() || $node->isLink() ) {
		continue;
	}
	$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $node->getPathname(), strlen( $root ) + 1 ) );
	if ( str_starts_with( $relative, 'tests/' ) ) {
		continue;
	}
	$content = file_get_contents( $node->getPathname() );
	if ( ! is_string( $content ) ) {
		throw new RuntimeException( 'Failed to read the isolated candidate package.' );
	}
	if ( 'wp-ai-bridge.php' === $relative ) {
		$header = ' * Version: ' . $old_version;
		$replacement = ' * Version: ' . $new_version;
		if ( ! str_contains( $content, $header ) || ! str_contains( $content, "define( 'WP_AI_BRIDGE_VERSION', '" . $old_version . "' );" ) ) {
			throw new RuntimeException( 'Installed release version is not canonically pinned.' );
		}
		$content = str_replace( $header, $replacement, $content );
		$content = str_replace( "define( 'WP_AI_BRIDGE_VERSION', '" . $old_version . "' );", "define( 'WP_AI_BRIDGE_VERSION', '" . $new_version . "' );", $content );
	}
	$zip->addFromString( 'wp-ai-bridge/' . $relative, $content );
	++$number;
}
$zip->close();
if ( $number < 50 ) {
	throw new RuntimeException( 'The self-replacement ZIP is unexpectedly incomplete.' );
}
$bytes = file_get_contents( $zipfile );
wp_delete_file( $zipfile );
if ( ! is_string( $bytes ) || '' === $bytes ) {
	throw new RuntimeException( 'The self-replacement package is empty.' );
}
$package_url = 'https://s.w.org/wpai-issue168-self.zip';
$requests = 0;
$mock = static function ( $pre, $args, $url ) use ( $bytes, &$requests, $package_url ) {
	if ( $url !== $package_url ) {
		return $pre;
	}
	++$requests;
	file_put_contents( $args['filename'], $bytes );
	return array(
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'headers' => array( 'content-length' => (string) strlen( $bytes ) ),
		'body' => '',
		'cookies' => array(),
		'filename' => $args['filename'],
	);
};
$enabled = ( new Settings() )->defaults();
$enabled[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
$enabled[ Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
update_option( Settings::OPTION_NAME, $enabled, false );
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
	$updated = $ability->execute(
		array(
			'kind' => 'plugin',
			'action' => 'update',
			'target' => $target,
			'package_url' => $package_url,
			'expected_installed_version' => $old_version,
			'expected_package_version' => $new_version,
			'expected_sha256' => hash( 'sha256', $bytes ),
			'confirm_replace' => true,
		)
	);
	if ( is_wp_error( $updated ) || empty( $updated['success'] ) || $target !== ( $updated['target'] ?? '' ) ) {
		throw new RuntimeException( 'Native active Bridge self-replacement failed: ' . ( is_wp_error( $updated ) ? $updated->get_error_code() : 'invalid_result' ) );
	}
	wp_clean_plugins_cache( true );
	if ( 1 !== $requests || $new_version !== get_plugins()[ $target ]['Version'] || ! is_plugin_active( $target ) ) {
		throw new RuntimeException( 'Self-replacement did not commit exactly one active new version.' );
	}
	if ( $old_oauth_instance !== get_option( 'wp_ai_bridge_oauth_instance', false ) ) {
		throw new RuntimeException( 'Self-replacement changed OAuth installation identity.' );
	}
	if ( $enabled !== get_option( Settings::OPTION_NAME, array() ) ) {
		throw new RuntimeException( 'Self-replacement changed explicit administrator grants.' );
	}
	update_option( 'wpai168_self_upgrade_fixture', array( 'expected_version' => $new_version, 'original_settings' => $old_settings ), false );
	echo "PASS: same-request active WP AI Bridge replacement through its own native Ability.\n";
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
}
