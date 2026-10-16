<?php
/**
 * Stable GitHub release discovery remains scoped to the canonical plugin.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\GitHub_Updates;

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['wpai171'] = array(
	'calls'      => 0,
	'response'   => array(),
	'transient'  => false,
	'expiration' => null,
	'filters'    => array(),
);

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wpai171']['filters'][ $hook ] = array( $callback, $priority, $accepted_args );
}

function get_site_transient( $key ) {
	if ( GitHub_Updates::CACHE_KEY !== $key ) {
		throw new RuntimeException( 'Unexpected transient key.' );
	}
	return $GLOBALS['wpai171']['transient'];
}

function set_site_transient( $key, $value, $expiration ) {
	if ( GitHub_Updates::CACHE_KEY !== $key ) {
		throw new RuntimeException( 'Unexpected transient key.' );
	}
	$GLOBALS['wpai171']['transient']  = $value;
	$GLOBALS['wpai171']['expiration'] = $expiration;
	return true;
}

function wp_safe_remote_get( $url, $args ) {
	if ( GitHub_Updates::RELEASE_API !== $url
		|| 0 !== $args['redirection']
		|| 524288 !== $args['limit_response_size']
		|| 8 !== $args['timeout']
		|| 'application/vnd.github+json' !== $args['headers']['Accept']
		|| ! isset( $args['headers']['User-Agent'] )
	) {
		throw new RuntimeException( 'The updater changed its exact, bounded WordPress HTTPS request contract.' );
	}
	++$GLOBALS['wpai171']['calls'];
	return $GLOBALS['wpai171']['response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( $response ) {
	return (string) ( $response['body'] ?? '' );
}

$checks = 0;
$assert = static function ( $condition, $reason ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
};

$tag     = 'v0.6.8';
$release = array(
	'tag_name'   => $tag,
	'html_url'   => GitHub_Updates::UPDATE_URI . '/releases/tag/' . $tag,
	'draft'      => false,
	'prerelease' => false,
	'assets'     => array(
		array(
			'name'                 => 'wp-ai-bridge.zip',
			'state'                => 'uploaded',
			'size'                 => 123456,
			'browser_download_url' => GitHub_Updates::UPDATE_URI . '/releases/download/' . $tag . '/wp-ai-bridge.zip',
		),
	),
);

$reset = static function ( $payload, $code = 200 ) {
	$GLOBALS['wpai171']['response']   = array(
		'response' => array( 'code' => $code ),
		'body'     => wp_json_encode( $payload ),
	);
	$GLOBALS['wpai171']['transient']  = false;
	$GLOBALS['wpai171']['expiration'] = null;
	$GLOBALS['wpai171']['calls']      = 0;
};

$updater = new GitHub_Updates();
$updater->boot();
$assert( isset( $GLOBALS['wpai171']['filters']['update_plugins_github.com'] ), 'Native hostname filter was not registered.' );
$assert( 4 === $GLOBALS['wpai171']['filters']['update_plugins_github.com'][2], 'Native update hook must receive the plugin identity.' );
$headers = array(
	'UpdateURI' => GitHub_Updates::UPDATE_URI,
	'Version'   => '0.6.7',
);

$reset( $release );
$other = array( 'id' => 'other' );
$assert( $other === $updater->filter_update( $other, $headers, 'another-plugin/another.php', array() ), 'Other GitHub plugins were modified.' );
$assert( 0 === $GLOBALS['wpai171']['calls'], 'Other GitHub plugins caused a network request.' );
$assert(
	$other === $updater->filter_update(
		$other,
		array(
			'UpdateURI' => 'https://github.com/attacker/repo',
			'Version'   => '0.6.7',
		),
		'wp-ai-bridge/wp-ai-bridge.php',
		array()
	),
	'Unexpected custom source URI was accepted.'
);

$update = $updater->filter_update( false, $headers, 'wp-ai-bridge/wp-ai-bridge.php', array() );
$assert( is_array( $update ) && '0.6.8' === $update['version'], 'Published stable release was not discoverable.' );
$assert( 'wp-ai-bridge' === $update['slug'] && GitHub_Updates::UPDATE_URI === $update['id'], 'Canonical WordPress identity changed.' );
$assert( $release['assets'][0]['browser_download_url'] === $update['package'], 'Source archive replaced the installable ZIP.' );
$assert( $release['html_url'] === $update['url'], 'Official release detail URL was not used.' );
$assert( 6 * HOUR_IN_SECONDS === $GLOBALS['wpai171']['expiration'], 'Validated stable release was not cached with a bounded lifetime.' );
$assert( 1 === $GLOBALS['wpai171']['calls'], 'Initial metadata check did not use a single HTTP request.' );
$updater->filter_update( false, $headers, 'wp-ai-bridge/wp-ai-bridge.php', array() );
$assert( 1 === $GLOBALS['wpai171']['calls'], 'Cached release caused another API request.' );

// A stale/malformed WordPress transient is not a new authority to choose the
// ZIP origin. Reconstruct canonical URLs even when the cached links differ.
$GLOBALS['wpai171']['transient'] = array(
	'version' => '0.6.8',
	'url'     => 'https://untrusted.invalid/details',
	'package' => 'https://untrusted.invalid/archive.zip',
);
$cached_update = $updater->filter_update( false, $headers, 'wp-ai-bridge/wp-ai-bridge.php', array() );
$assert( $update['url'] === $cached_update['url'], 'Untrusted cached release page replaced the canonical destination.' );
$assert( $update['package'] === $cached_update['package'], 'Untrusted cached package replaced the official GitHub ZIP.' );
$assert( 1 === $GLOBALS['wpai171']['calls'], 'Malformed cached metadata caused unnecessary network activity.' );
$GLOBALS['wpai171']['transient']['version'] = '../0.6.9';
$assert( false === $updater->filter_update( false, $headers, 'wp-ai-bridge/wp-ai-bridge.php', array() ), 'Invalid cached release version was accepted.' );
$GLOBALS['wpai171']['transient']['version'] = array( '0.6.8' );
$assert( false === $updater->filter_update( false, $headers, 'wp-ai-bridge/wp-ai-bridge.php', array() ), 'Non-string cached release version was accepted.' );

$bad_releases = array(
	'draft'         => array_merge( $release, array( 'draft' => true ) ),
	'prerelease'    => array_merge( $release, array( 'prerelease' => true ) ),
	'wrong_url'     => array_merge( $release, array( 'html_url' => 'https://example.invalid/release' ) ),
	'no_zip'        => array_merge( $release, array( 'assets' => array() ) ),
	'source_zip'    => array_merge(
		$release,
		array(
			'assets' => array(
				array(
					'name'                 => 'Source code (zip)',
					'state'                => 'uploaded',
					'size'                 => 500,
					'browser_download_url' => 'https://example.invalid/source.zip',
				),
			),
		)
	),
	'wrong_zip_url' => array_merge( $release, array( 'assets' => array( array_merge( $release['assets'][0], array( 'browser_download_url' => 'https://evil.invalid/wp-ai-bridge.zip' ) ) ) ) ),
	'unuploaded'    => array_merge( $release, array( 'assets' => array( array_merge( $release['assets'][0], array( 'state' => 'new' ) ) ) ) ),
	'empty_zip'     => array_merge( $release, array( 'assets' => array( array_merge( $release['assets'][0], array( 'size' => 0 ) ) ) ) ),
	'wrong_tag'     => array_merge( $release, array( 'tag_name' => 'v0.6.8-rc1' ) ),
	'missing_data'  => array( 'tag_name' => 'v0.6.8' ),
);
foreach ( $bad_releases as $label => $payload ) {
	$reset( $payload );
	$assert( false === $updater->filter_update( false, $headers, 'wp-ai-bridge/wp-ai-bridge.php', array() ), 'Unsafe or incomplete GitHub release was accepted: ' . $label );
	$assert( 15 * MINUTE_IN_SECONDS === $GLOBALS['wpai171']['expiration'], 'Bad release was not negatively cached: ' . $label );
}

$reset( array() );
$GLOBALS['wpai171']['response'] = new WP_Error( 'synthetic_failure', 'Network unavailable' );
$assert( false === $updater->filter_update( false, $headers, 'wp-ai-bridge/wp-ai-bridge.php', array() ), 'Network failure fabricated an update.' );
$assert( 15 * MINUTE_IN_SECONDS === $GLOBALS['wpai171']['expiration'], 'Unavailable API was not briefly cached.' );
$reset( $release, 403 );
$assert( false === $updater->filter_update( false, $headers, 'wp-ai-bridge/wp-ai-bridge.php', array() ), 'Rate-limited API was treated as success.' );
// Only our canonical Github channel can supply Bridge updates; a separate
// filter for another github.com plugin cannot become a fallback installer.
$spoofed_prior = array(
	'version' => '9.9.9',
	'package' => 'https://other.invalid/bridge.zip',
);
$assert( false === $updater->filter_update( $spoofed_prior, $headers, 'wp-ai-bridge/wp-ai-bridge.php', array() ), 'Previously supplied host-filter package survived a failed official release check.' );
$reset( $release );
$assert( $update === $updater->filter_update( $spoofed_prior, $headers, 'wp-ai-bridge/wp-ai-bridge.php', array() ), 'Earlier host-filter package overrode a valid official stable release.' );
$assert( $spoofed_prior === $updater->filter_update( $spoofed_prior, $headers, 'unrelated/plugin.php', array() ), 'Unrelated GitHub plugin update was changed.' );

echo "PASS: #171 {$checks} GitHub-host update discovery, origin confinement, stable-only metadata, caching, and fail-closed assertions.\n";
