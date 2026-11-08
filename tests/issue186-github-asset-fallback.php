<?php
/**
 * Official GitHub release asset fallback regression.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';
use WP_AI_Bridge\Support\GitHub_Updates;

$GLOBALS['wpai186'] = array(
	'filters'        => array(),
	'direct'         => new WP_Error( 'http_404', 'Gateway Timeout', array( 'code' => 504, 'body' => 'PRIVATE_DIAGNOSTIC_BODY_DO_NOT_ECHO https://example.invalid/?token=do-not-leak' ) ),
	'direct_calls'   => 0,
	'metadata'       => array(),
	'metadata_code'  => 200,
	'metadata_calls' => 0,
	'asset_code'     => 200,
	'asset_calls'    => 0,
	'asset_result'   => null,
	'asset_payload'  => '',
	'temps'          => array(),
);
function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wpai186']['filters'][ $name ] = array( $callback, $priority, $accepted_args );
}
function download_url( $url, $timeout = 300 ) {
	$sim = &$GLOBALS['wpai186'];
	if ( 60 !== $timeout || ! is_string( $url ) ) {
		throw new RuntimeException( 'Unexpected direct-download contract.' );
	}
	++$sim['direct_calls'];
	return $sim['direct'];
}
function wp_tempnam( $filename = '' ) {
	if ( 'wp-ai-bridge.zip' !== $filename ) {
		throw new RuntimeException( 'Noncanonical temporary filename.' );
	}
	$path                          = tempnam( sys_get_temp_dir(), 'wpai186-' );
	$GLOBALS['wpai186']['temps'][] = $path;
	return $path;
}
function wp_safe_remote_get( $url, $args ) {
	$sim = &$GLOBALS['wpai186'];
	if ( GitHub_Updates::RELEASE_API === $url ) {
		if ( 8 !== $args['timeout'] || 0 !== $args['redirection']
			|| 524288 !== $args['limit_response_size']
			|| 'application/vnd.github+json' !== $args['headers']['Accept']
		) {
			throw new RuntimeException( 'Unbounded official metadata request.' );
		}
		++$sim['metadata_calls'];
		if ( $sim['metadata'] instanceof WP_Error ) {
			return $sim['metadata'];
		}
		return array(
			'response' => array( 'code' => $sim['metadata_code'] ),
			'body'     => wp_json_encode( $sim['metadata'] ),
		);
	}
	if ( GitHub_Updates::ASSET_API . '987654' !== $url ) {
		throw new RuntimeException( 'Unexpected asset endpoint.' );
	}
	if ( 90 !== $args['timeout'] || 5 !== $args['redirection']
		|| true !== $args['stream'] || ! is_file( $args['filename'] )
		|| 'application/octet-stream' !== $args['headers']['Accept']
		|| '2022-11-28' !== $args['headers']['X-GitHub-Api-Version']
		|| $args['limit_response_size'] !== $sim['metadata']['assets'][0]['size'] + 1
	) {
		throw new RuntimeException( 'Unbounded or unsafe streamed asset request.' );
	}
	++$sim['asset_calls'];
	if ( $sim['asset_result'] instanceof WP_Error ) {
		return $sim['asset_result'];
	}
	file_put_contents( $args['filename'], $sim['asset_payload'] );
	return array( 'response' => array( 'code' => $sim['asset_code'] ) );
}
function wp_remote_retrieve_response_code( $response ) {
	return (int) ( $response['response']['code'] ?? 0 );
}
function wp_remote_retrieve_body( $response ) {
	return (string) ( $response['body'] ?? '' );
}
$checks  = 0;
$assert  = static function ( $condition, $reason ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
};
$updater = new GitHub_Updates();
$updater->boot();
$assert( 20 === $GLOBALS['wpai186']['filters']['upgrader_pre_download'][1], 'Other updater filters must run first.' );
$assert( 4 === $GLOBALS['wpai186']['filters']['upgrader_pre_download'][2], 'Core update context was not registered.' );

$package = GitHub_Updates::UPDATE_URI . '/releases/download/v0.7.4/wp-ai-bridge.zip';
$context = array(
	'type'   => 'plugin',
	'action' => 'update',
	'plugin' => 'wp-ai-bridge/wp-ai-bridge.php',
);
$payload = "PK\x03\x04" . str_repeat( 'official-asset-fixture', 8 );
$release = array(
	'tag_name'   => 'v0.7.4',
	'html_url'   => GitHub_Updates::UPDATE_URI . '/releases/tag/v0.7.4',
	'draft'      => false,
	'prerelease' => false,
	'assets'     => array(
		array(
			'name'                 => 'wp-ai-bridge.zip',
			'state'                => 'uploaded',
			'id'                   => 987654,
			'url'                  => GitHub_Updates::ASSET_API . '987654',
			'size'                 => strlen( $payload ),
			'digest'               => 'sha256:' . hash( 'sha256', $payload ),
			'browser_download_url' => $package,
		),
	),
);
$sim     = &$GLOBALS['wpai186'];
$reset   = static function () use ( &$sim, $release, $payload ) {
	$sim['direct']         = new WP_Error( 'http_404', 'Gateway Timeout', array( 'code' => 504, 'body' => 'PRIVATE_DIAGNOSTIC_BODY_DO_NOT_ECHO https://example.invalid/?token=do-not-leak' ) );
	$sim['direct_calls']   = 0;
	$sim['metadata']       = $release;
	$sim['metadata_code']  = 200;
	$sim['metadata_calls'] = 0;
	$sim['asset_code']     = 200;
	$sim['asset_calls']    = 0;
	$sim['asset_result']   = null;
	$sim['asset_payload']  = $payload;
	$sim['temps']          = array();
};
$reset();
$previous = new WP_Error( 'upstream_override', 'Earlier updater result' );
$assert( $previous === $updater->filter_package_download( $previous, $package, null, $context ), 'A previous updater was overridden.' );
$assert(
	false === $updater->filter_package_download(
		false,
		$package,
		null,
		array(
			'type'   => 'plugin',
			'action' => 'update',
			'plugin' => 'other/other.php',
		)
	),
	'Other plugin was intercepted.'
);
$assert(
	false === $updater->filter_package_download(
		false,
		$package,
		null,
		array(
			'type'   => 'plugin',
			'action' => 'install',
			'plugin' => 'wp-ai-bridge/wp-ai-bridge.php',
		)
	),
	'Plugin upload was intercepted.'
);
$assert( false === $updater->filter_package_download( false, 'https://evil.example/bridge.zip', null, $context ), 'Untrusted package URL was intercepted.' );
$assert( 0 === $sim['direct_calls'], 'Unrelated updates caused direct network operations.' );

$sim['direct'] = '/tmp/native-success-example.zip';
$assert( $sim['direct'] === $updater->filter_package_download( false, $package, null, $context ), 'Successful native download was replaced.' );
$assert( 0 === $sim['metadata_calls'] && 0 === $sim['asset_calls'], 'Successful direct download triggered the API.' );

$reset();
$download = $updater->filter_package_download( false, $package, null, $context );
$assert( is_string( $download ) && is_file( $download ), 'HTTP 504 did not recover an official binary ZIP.' );
$assert( 1 === $sim['direct_calls'] && 1 === $sim['metadata_calls'] && 1 === $sim['asset_calls'], 'Unexpected fallback request count.' );
$assert( file_get_contents( $download ) === $payload, 'Official fallback did not preserve exact bytes.' );
$assert( hash( 'sha256', $payload ) === hash_file( 'sha256', $download ), 'Downloaded archive hash differs.' );
unlink( $download );
$assert( ! file_exists( $download ), 'Successful temp artifact not cleanable by WordPress.' );
$reset();
// WordPress Plugin_Upgrader::bulk_upgrade passes plugin and temp_backup only.
$bulk_context = array(
	'plugin'      => 'wp-ai-bridge/wp-ai-bridge.php',
	'temp_backup' => array(),
);
$bulk_zip     = $updater->filter_package_download( false, $package, null, $bulk_context );
$assert( is_string( $bulk_zip ) && is_file( $bulk_zip ), 'Core bulk upgrade context was rejected by the recovery hook.' );
$assert( 1 === $sim['asset_calls'], 'Bulk upgrade did not retrieve the official binary asset.' );
unlink( $bulk_zip );
$expect_failure = static function ( $stage ) use ( $updater, $package, $context, &$sim, $assert ) {
	$failed = $updater->filter_package_download( false, $package, null, $context );
	$assert( is_wp_error( $failed ) && 'wp_ai_bridge_release_download_failed' === $failed->get_error_code(), 'Invalid asset was accepted: ' . $stage );
	$data = $failed->get_error_data();
	$assert( 'http_404' === ( $data['direct_error'] ?? null ), 'Actual WordPress Core WP_Error code was lost: ' . $stage );
	$assert( 504 === ( $data['direct_http_status'] ?? null ), 'Original Core HTTP 504 status was lost: ' . $stage );
	$assert( false !== strpos( $failed->get_error_message(), 'HTTP 504' ), 'HTTP status absent from operator diagnostic: ' . $stage );
	$assert( false !== strpos( $failed->get_error_message(), 'Gateway Timeout' ), 'Known safe Core reason phrase was dropped: ' . $stage );
	$assert( false === strpos( $failed->get_error_message() . wp_json_encode( $data ), 'PRIVATE_DIAGNOSTIC_BODY_DO_NOT_ECHO' ), 'Sensitive Core error body was exposed: ' . $stage );
	$assert( false === strpos( $failed->get_error_message() . wp_json_encode( $data ), 'token=do-not-leak' ), 'Signed asset URL or token was exposed: ' . $stage );
	$assert( ( $data['api_stage'] ?? null ) === $stage, 'Official API failure stage is missing: ' . $stage );
	$assert( 1 === $sim['direct_calls'], 'Direct download count changed: ' . $stage );
	foreach ( $sim['temps'] as $path ) {
		$assert( ! is_file( $path ), 'Rejected archive left a temporary file: ' . $stage );
	}
};
$reset();
$sim['direct'] = new WP_Error( 'http_transport_failed', 'https://example.invalid/?token=hidden-in-message', array( 'code' => '504', 'body' => 'PRIVATE_DIAGNOSTIC_BODY_DO_NOT_ECHO' ) );
$sim['metadata_code'] = 503;
$transport_failure = $updater->filter_package_download( false, $package, null, $context );
$assert( is_wp_error( $transport_failure ), 'Non-HTTP transport failure must remain an error.' );
$transport_data = $transport_failure->get_error_data();
$assert( 'http_transport_failed' === ( $transport_data['direct_error'] ?? null ), 'Transport error code was lost.' );
$assert( null === ( $transport_data['direct_http_status'] ?? null ), 'Untrusted numeric-string HTTP status was accepted.' );
$assert( 'metadata_http_503' === ( $transport_data['api_stage'] ?? null ), 'Transport fallback failure stage was lost.' );
$assert( false === strpos( $transport_failure->get_error_message() . wp_json_encode( $transport_data ), 'token=hidden-in-message' ), 'Untrusted direct transport message was exposed.' );
$assert( false === strpos( $transport_failure->get_error_message() . wp_json_encode( $transport_data ), 'PRIVATE_DIAGNOSTIC_BODY_DO_NOT_ECHO' ), 'Untrusted direct transport body was exposed.' );
$reset();
$sim['metadata_code'] = 504;
$expect_failure( 'metadata_http_504' );
$reset();
$sim['metadata'] = new WP_Error( 'network_offline', 'API blocked' );
$expect_failure( 'metadata_transport:network_offline' );
$reset();
$sim['metadata']['tag_name'] = 'v0.7.5';
$expect_failure( 'release_or_package_mismatch' );
$reset();
$sim['metadata']['draft'] = true;
$expect_failure( 'release_or_package_mismatch' );
$reset();
unset( $sim['metadata']['assets'][0]['digest'] );
$expect_failure( 'asset_integrity_metadata_missing' );
$reset();
$sim['metadata']['assets'][0]['digest'] = 'sha256:' . str_repeat( 'x', 64 );
$expect_failure( 'asset_integrity_metadata_missing' );
$reset();
$sim['metadata']['assets'][0]['id'] = 0;
$expect_failure( 'asset_integrity_metadata_missing' );
$reset();
$sim['metadata']['assets'][0]['url'] = 'https://untrusted.invalid/asset';
$expect_failure( 'asset_api_url_mismatch' );
$reset();
$sim['asset_payload'] = substr( $payload, 0, -1 );
$expect_failure( 'asset_size_mismatch' );
$reset();
$sim['asset_payload'] = str_repeat( 'X', strlen( $payload ) );
$expect_failure( 'asset_sha256_mismatch' );
$reset();
$sim['asset_payload'] = str_repeat( '{', strlen( $payload ) );
$expect_failure( 'asset_sha256_mismatch' );
$reset();
$sim['asset_code'] = 302;
$expect_failure( 'asset_http_302' );
$reset();
$sim['asset_result'] = new WP_Error( 'asset_unreachable', 'CDN unreachable' );
$expect_failure( 'asset_transport:asset_unreachable' );
$reset();
$sim['asset_code'] = 504;
$expect_failure( 'asset_http_504' );

echo "PASS: #186 {$checks} official-asset fallback confinement, signed-off metadata, SHA-256, transport failure and temporary cleanup assertions.\n";
