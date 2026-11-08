<?php
/**
 * WordPress Core update path for the canonical GitHub-distributed plugin.
 *
 * Runs only in a disposable WordPress integration container. A synthetic next
 * version and response are built locally; no unapproved GitHub publication,
 * live site mutation or third-party download is involved.
 *
 * @package WP_AI_Bridge
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_AI_BRIDGE_FILE' ) ) {
	throw new RuntimeException( 'Expected an active isolated WP AI Bridge installation.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/update.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

$plugin_file = 'wp-ai-bridge/wp-ai-bridge.php';
$plugin_root = WP_PLUGIN_DIR . '/wp-ai-bridge';
$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
$current     = $plugin_data['Version'] ?? '';
if ( ! is_plugin_active( $plugin_file ) || ! preg_match( '/^[0-9]+\\.[0-9]+\\.[0-9]+$/D', $current ) ) {
	throw new RuntimeException( 'Canonical plugin must be active with a stable version.' );
}
if ( 'https://github.com/AChWorks/wp-ai-bridge' !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
	throw new RuntimeException( 'Canonical Update URI is not installed.' );
}

$pieces = array_map( 'intval', explode( '.', $current ) );
++$pieces[2];
$next        = implode( '.', $pieces );
$tag         = 'v' . $next;
$release_url = 'https://github.com/AChWorks/wp-ai-bridge/releases/tag/' . $tag;
$package_url = 'https://github.com/AChWorks/wp-ai-bridge/releases/download/' . $tag . '/wp-ai-bridge.zip';
$archive     = wp_tempnam( 'wpai171-github-update.zip' );
if ( ! is_string( $archive ) || ! class_exists( 'ZipArchive' ) ) {
	throw new RuntimeException( 'ZIP extension/temporary archive unavailable in the isolated WordPress fixture.' );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	throw new RuntimeException( 'Could not create synthetic update ZIP.' );
}
$files       = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin_root, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);
$entry_count = 0;
foreach ( $files as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}

	$relative = 'wp-ai-bridge/' . substr( $file->getPathname(), strlen( $plugin_root ) + 1 );
	$contents = file_get_contents( $file->getPathname() );
	if ( false === $contents ) {
		throw new RuntimeException( 'Could not inspect synthetic plugin source.' );
	}
	if ( 'wp-ai-bridge/wp-ai-bridge.php' === $relative ) {
		$old_header = ' * Version: ' . $current;
		$old_const  = "define( 'WP_AI_BRIDGE_VERSION', '" . $current . "' );";
		if ( 1 !== substr_count( $contents, $old_header ) || 1 !== substr_count( $contents, $old_const ) ) {
			throw new RuntimeException( 'Synthetic version editing was not confined to canonical metadata.' );
		}
		$contents = str_replace( $old_header, ' * Version: ' . $next, $contents );
		$contents = str_replace( $old_const, "define( 'WP_AI_BRIDGE_VERSION', '" . $next . "' );", $contents );
	}
	if ( ! $zip->addFromString( $relative, $contents ) ) {
		throw new RuntimeException( 'Could not add a synthetic plugin archive member.' );
	}
	++$entry_count;
}
if ( $entry_count < 100 || ! $zip->close() ) {
	throw new RuntimeException( 'Synthetic plugin archive is incomplete.' );
}

$fake_api = array(
	'tag_name'   => $tag,
	'html_url'   => $release_url,
	'draft'      => false,
	'prerelease' => false,
	'assets'     => array(
		array(
			'name'                 => 'wp-ai-bridge.zip',
			'state'                => 'uploaded',
			'size'                 => filesize( $archive ),
			'id'                   => 987654,
			'url'                  => 'https://api.github.com/repos/AChWorks/wp-ai-bridge/releases/assets/987654',
			'digest'               => 'sha256:' . hash_file( 'sha256', $archive ),
			'browser_download_url' => $package_url,
		),
	),
);

$github_checks   = 0;
$direct_failures = 0;
$asset_calls     = 0;
$wporg_available = true;
$http_override   = static function ( $preempt, $arguments, $url ) use ( $fake_api, $archive, $package_url, &$github_checks, &$direct_failures, &$asset_calls, &$wporg_available ) {
	if ( 'https://api.github.com/repos/AChWorks/wp-ai-bridge/releases/latest' === $url ) {
		++$github_checks;
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => array(),
			'cookies'  => array(),
			'body'     => wp_json_encode( $fake_api ),
		);
	}
	if ( $package_url === $url ) {
		++$direct_failures;
		return array(
			'response' => array(
				'code'    => 504,
				'message' => 'Synthetic GitHub Release CDN timeout',
			),
			'headers'  => array(),
			'body'     => 'Gateway timed out',
		);
	}
	if ( 'https://api.github.com/repos/AChWorks/wp-ai-bridge/releases/assets/987654' === $url ) {
		++$asset_calls;
		if ( 'application/octet-stream' !== ( $arguments['headers']['Accept'] ?? null )
			|| empty( $arguments['stream'] )
			|| empty( $arguments['filename'] )
			|| ! copy( $archive, $arguments['filename'] )
		) {
			throw new RuntimeException( 'Official fallback did not stream the binary release asset.' );
		}
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => array(),
			'body'     => '',
		);
	}
	if ( 0 === strpos( $url, 'https://api.wordpress.org/plugins/update-check/' ) ) {
		if ( ! $wporg_available ) {
			return array(
				'response' => array(
					'code'    => 503,
					'message' => 'Simulated unavailable WordPress.org',
				),
			);
		}
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => array(),
			'cookies'  => array(),
			'body'     => '{"plugins":[],"no_update":[],"translations":[]}',
		);
	}
	return $preempt;
};

add_filter( 'pre_http_request', $http_override, 10, 3 );

try {
	update_option( 'wpai171_native_update_preservation_probe', 'must-survive-update', false );
	delete_site_transient( 'wp_ai_bridge_github_stable_release' );
	delete_site_transient( 'update_plugins' );
	$wporg_available = false;
	wp_update_plugins();
	$offline_transient = get_site_transient( 'update_plugins' );
	$offline_offer     = $offline_transient->response[ $plugin_file ] ?? null;
	if ( ! is_object( $offline_offer )
		|| ( $offline_offer->new_version ?? '' ) !== $next
		|| ( $offline_offer->package ?? '' ) !== $package_url
		|| 1 !== $github_checks
	) {
		throw new RuntimeException( 'Missing native Bridge update offer when the global WordPress.org update API returns 503.' );
	}
	echo "PASS: WordPress.org update API unavailable; Core still displays the official Bridge update offer.\n";

	$wporg_available = true;
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$transient = get_site_transient( 'update_plugins' );
	$offer     = $transient->response[ $plugin_file ] ?? null;
	if ( ! is_object( $offer )
		|| ( $offer->new_version ?? '' ) !== $next
		|| ( $offer->package ?? '' ) !== $package_url
		|| 1 !== $github_checks
	) {
		throw new RuntimeException( 'Native update check did not discover the exact synthetic GitHub ZIP.' );
	}
	echo "PASS: Core update_plugins recognized the stable GitHub release.\n";

	// Mirrors the WordPress Plugins screen's AJAX updater, not the deactivating
	// single-plugin upgrade() method used by some non-interactive callers.
	$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$result   = $upgrader->bulk_upgrade( array( $plugin_file ) );
	if ( ! is_array( $result ) || ! is_array( $result[ $plugin_file ] ?? null ) ) {
		throw new RuntimeException( 'Core bulk upgrade failed to replace the canonical plugin.' );
	}

	if ( 1 !== $direct_failures || 1 !== $asset_calls || 2 !== $github_checks ) {
		throw new RuntimeException( 'Expected one failed native direct ZIP fetch followed by one verified official asset fallback.' );
	}
	echo "PASS: Core download recovered one GitHub Release HTTP 504 using the official SHA-256-verified asset endpoint.\n";

	$after = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
	if ( ( $after['Version'] ?? '' ) !== $next
		|| ! is_plugin_active( $plugin_file )
		|| 'must-survive-update' !== get_option( 'wpai171_native_update_preservation_probe' )
		|| ! is_dir( $plugin_root )
	) {
		throw new RuntimeException( 'Native update lost canonical identity, active state or saved WordPress data.' );
	}
	echo "PASS: Core bulk_upgrade preserves activation, canonical path and WordPress options.\n";
} finally {
	remove_filter( 'pre_http_request', $http_override, 10 );
	if ( is_file( $archive ) ) {
		unlink( $archive );
	}
}
