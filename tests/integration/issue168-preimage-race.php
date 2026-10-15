<?php
/**
 * Issue #168: deterministic TWO PROCESS preflight/download/lock race.
 *
 * The same fixture is executed by distinct WP-CLI PHP processes against
 * shared disposable WordPress/Core storage. Caller A pauses after its first
 * version read but BEFORE acquiring the Core-overwrite lock, while B commits
 * a newer package under that lock. A must not overwrite B with its older ZIP.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai168race_check( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
}

function wpai168race_package( $version, $payload ) {
	$file = wp_tempnam( 'wpai168-race.zip' );
	wpai168race_check( is_string( $file ) && class_exists( 'ZipArchive' ), 'Cannot stage test-only package.' );
	$archive = new ZipArchive();
	wpai168race_check( true === $archive->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Cannot open race ZIP.' );
	$archive->addFromString( 'wpai168-race/wpai168-race.php', "<?php\n/*\nPlugin Name: WP AI Bridge Race Fixture\nVersion: " . $version . "\n*/\n" );
	$archive->addFromString( 'wpai168-race/revision.txt', $payload );
	$archive->close();
	$bytes = file_get_contents( $file );
	wp_delete_file( $file );
	wpai168race_check( is_string( $bytes ) && '' !== $bytes, 'Cannot read race ZIP.' );
	return $bytes;
}

$phase = getenv( 'WPAI168_RACE_PHASE' );
$control = WP_CONTENT_DIR . '/wpai168-race-signals';
$target = 'wpai168-race/wpai168-race.php';
$target_dir = WP_PLUGIN_DIR . '/wpai168-race';
$original_plugin = $target_dir . '/wpai168-race.php';
$ready = $control . '/a-ready';
$b_complete = $control . '/b-complete';
$a_rejected = $control . '/a-rejected';
$state_key = 'wpai168_race_original_grants';
$settings = new Settings();
$ability = wp_get_ability( 'wp-ai-bridge/extension-lifecycle' );

wpai168race_check( $ability instanceof WP_Ability, 'Extension lifecycle is unavailable in race regression.' );

if ( 'setup' === $phase ) {
	wpai168race_check( ! is_dir( $control ) && ! is_dir( $target_dir ), 'A previous race fixture unexpectedly remains.' );
	wpai168race_check( wp_mkdir_p( $control ) && wp_mkdir_p( $target_dir ), 'Cannot create isolated race storage.' );
	$original = "<?php\n/*\nPlugin Name: WP AI Bridge Race Fixture\nVersion: 1.0.0\n*/\n";
	file_put_contents( $original_plugin, $original );
	file_put_contents( $target_dir . '/revision.txt', 'original' );
	wp_clean_plugins_cache( false );
	wpai168race_check( '1.0.0' === (string) ( get_plugins()[ $target ]['Version'] ?? '' ), 'Original race plugin was not registered.' );
	wpai168race_check( null === activate_plugin( $target ), 'Original race plugin could not be activated.' );
	wpai168race_check( is_plugin_active( $target ), 'Race fixture is not active.' );
	wpai168race_check( add_option( $state_key, get_option( Settings::OPTION_NAME, $settings->defaults() ), '', false ), 'Cannot capture isolated original grants.' );
	$enabled = $settings->defaults();
	$enabled[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	$enabled[ Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	echo "PASS: race fixture original 1.0.0 is active; awaiting two distinct PHP processes.\n";
	return;
}

if ( 'verify' === $phase ) {
	wpai168race_check( is_file( $ready ) && is_file( $b_complete ) && is_file( $a_rejected ), 'Both process barriers and A stale-version refusal are required.' );
	wp_clean_plugins_cache( false );
	wpai168race_check( '1.2.0' === (string) ( get_plugins()[ $target ]['Version'] ?? '' ), 'A overwrote B or B never committed version 1.2.0.' );
	wpai168race_check( 'committed-by-b' === file_get_contents( $target_dir . '/revision.txt' ), 'A damaged B package files.' );
	wpai168race_check( is_plugin_active( $target ), 'The race changed original plugin activation.' );
	$grants = get_option( $state_key, false );
	wpai168race_check( is_array( $grants ), 'Original grant fixture is missing.' );
	update_option( Settings::OPTION_NAME, $grants, false );
	delete_option( $state_key );
	deactivate_plugins( $target );
	wpai168race_check( true === delete_plugins( array( $target ) ), 'Disposable race plugin cleanup failed.' );
	foreach ( array( $ready, $b_complete, $a_rejected ) as $marker ) {
		wpai168race_check( unlink( $marker ), 'Cannot retire a race barrier marker.' );
	}
	wpai168race_check( rmdir( $control ), 'Cannot retire race directory.' );
	echo "PASS: REAL overlapping-process stale-preimage race rejected A; B 1.2.0 code and activation survived.\n";
	return;
}

wpai168race_check( in_array( $phase, array( 'a', 'b' ), true ), 'Unsupported race phase.' );
$version = 'a' === $phase ? '1.1.0' : '1.2.0';
$payload = 'a' === $phase ? 'unapproved-older-a' : 'committed-by-b';
$bytes = wpai168race_package( $version, $payload );
$url = 'a' === $phase ? 'https://s.w.org/wpai168-race-a.zip' : 'https://s.w.org/wpai168-race-b.zip';
$called = 0;
$mock = static function ( $pre, $args, $request_url ) use ( $phase, $url, $ready, $b_complete, $bytes, &$called ) {
	if ( $request_url !== $url ) {
		return $pre;
	}
	++$called;
	wpai168race_check( ! empty( $args['reject_unsafe_urls'] ) && ! empty( $args['stream'] ) && true === $args['sslverify'], 'Native safe-HTTP streaming flags changed in race fixture.' );
	if ( 'a' === $phase ) {
		// The initial get_plugins() preflight already ran. B will now
		// commit in a separate process BEFORE A may acquire the lock.
		wpai168race_check( false !== file_put_contents( $ready, 'a-preflight-done' ), 'Could not publish A barrier.' );
		$observed = false;
		for ( $poll = 0; $poll < 400; ++$poll ) {
			if ( is_file( $b_complete ) ) {
				$observed = true;
				break;
			}
			usleep( 100000 );
		}
		wpai168race_check( $observed, 'B did not commit before A attempted its under-lock read.' );
	}
	wpai168race_check( strlen( $bytes ) === file_put_contents( $args['filename'], $bytes ), 'Mock ZIP streaming was incomplete.' );
	return array(
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'headers'  => array( 'content-length' => (string) strlen( $bytes ) ),
		'body'     => '',
		'cookies'  => array(),
		'filename' => $args['filename'],
	);
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
	$result = $ability->execute(
		array(
			'kind'                       => 'plugin',
			'action'                     => 'update',
			'target'                     => $target,
			'package_url'                => $url,
			'expected_installed_version' => '1.0.0',
			'expected_package_version'   => $version,
			'expected_sha256'            => hash( 'sha256', $bytes ),
			'confirm_replace'            => true,
		)
	);
	wpai168race_check( 1 === $called, 'Each race process must perform exactly one bounded download.' );
	if ( 'b' === $phase ) {
		wpai168race_check( ! is_wp_error( $result ) && true === ( $result['success'] ?? false ), 'B failed to commit its approved newer package.' );
		wp_clean_plugins_cache( false );
		wpai168race_check( '1.2.0' === (string) ( get_plugins()[ $target ]['Version'] ?? '' ), 'B committed an unexpected extension version.' );
		wpai168race_check( is_plugin_active( $target ), 'B unexpectedly deactivated the installed plugin.' );
		wpai168race_check( false !== file_put_contents( $b_complete, 'committed' ), 'B could not release A to continue.' );
		echo "PASS: process B committed version 1.2.0 while A was downloading.\n";
	} else {
		wpai168race_check( is_wp_error( $result ) && 'external_package_target_stale' === $result->get_error_code(), 'Process A was not denied at its authoritative under-lock stale-version check.' );
		wpai168race_check( false !== file_put_contents( $a_rejected, 'stale-rejected' ), 'A could not record a stale-version denial.' );
		echo "PASS: process A refused a preimage made stale by independent process B.\n";
	}
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
}
