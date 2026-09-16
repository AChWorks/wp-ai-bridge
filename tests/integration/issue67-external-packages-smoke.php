<?php
/** Real WordPress external plugin/theme package installation boundary tests. */
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Settings;

$GLOBALS['wpnb67_integration_checks'] = 0;
function wpnb67_integration_assert( $condition, $message ) {
	++$GLOBALS['wpnb67_integration_checks'];
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function wpnb67_fixture_zip( $kind ) {
	$file = wp_tempnam( 'wpnb67-' . $kind . '.zip' );
	wpnb67_integration_assert( $file && class_exists( 'ZipArchive' ), 'Could not allocate ZIP fixture.' );
	$zip = new ZipArchive();
	wpnb67_integration_assert( true === $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Could not open ZIP fixture.' );
	if ( 'plugin' === $kind ) {
		$zip->addFromString(
			'wpnb-external-plugin/wpnb-external-plugin.php',
			"<?php\n/*\nPlugin Name: WPNB External Package Fixture\nVersion: 1.0.0\n*/\n"
		);
	} else {
		$zip->addFromString(
			'wpnb-external-theme/style.css',
			"/*\nTheme Name: WPNB External Package Fixture\nVersion: 1.0.0\n*/\n"
		);
		$zip->addFromString( 'wpnb-external-theme/index.php', "<?php\n" );
	}
	$zip->close();
	$bytes = file_get_contents( $file );
	wp_delete_file( $file );
	wpnb67_integration_assert( is_string( $bytes ) && '' !== $bytes, 'ZIP fixture is empty.' );
	return $bytes;
}

$settings      = new Settings();
$original      = get_option( Settings::OPTION_NAME, $settings->defaults() );
$original_log  = get_option( Mutation_Log::OPTION_NAME, array() );
$plugin_source = 'https://s.w.org/wpnb-external-plugin.zip?signature=PRIVATE_PACKAGE_MARKER';
$theme_source  = 'https://s.w.org/wpnb-external-theme.zip?signature=PRIVATE_PACKAGE_MARKER';
$plugin_bytes  = wpnb67_fixture_zip( 'plugin' );
$theme_bytes   = wpnb67_fixture_zip( 'theme' );
$enabled       = $settings->defaults();
$enabled[ Settings::GROUP_CODE_EXTENSIONS ]   = 1;
$enabled[ Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
$calls              = 0;
$unexpected_calls   = 0;
$staging            = array();
$mode               = 'success';
$redirect_target    = null;
$redirect_forwarded = 0;

$mock = static function ( $pre, $args, $url ) use ( &$calls, &$unexpected_calls, &$staging, &$mode, &$redirect_target, &$redirect_forwarded, $plugin_source, $theme_source, $plugin_bytes, $theme_bytes ) {
	++$calls;
	if ( ! in_array( $url, array( $plugin_source, $theme_source, 'https://wordpress.org/wpnb-external-redirect.zip' ), true ) ) {
		++$unexpected_calls;
		return new WP_Error( 'unexpected_fixture_http', 'Unexpected fixture HTTP request was blocked.' );
	}
	wpnb67_integration_assert( ! empty( $args['reject_unsafe_urls'] ) && true === $args['sslverify'], 'Safe URL/TLS validation was disabled.' );
	wpnb67_integration_assert( true === $args['stream'] && false === $args['decompress'], 'External package download must use bounded uncompressed streaming.' );
	wpnb67_integration_assert( array() === $args['cookies'] && array( 'Accept-Encoding' => 'identity' ) === $args['headers'], 'External package download forwarded credentials or arbitrary headers.' );
	wpnb67_integration_assert( 30 === $args['timeout'] && 5 === $args['redirection'], 'External package network budgets changed.' );
	wpnb67_integration_assert( wp_max_upload_size() >= $args['limit_response_size'] - 1, 'External package limit exceeded the hosting upload limit.' );
	$staging[] = $args['filename'];

	if ( null !== $redirect_target ) {
		$headers  = array();
		$data     = null;
		$options  = array( 'filename' => $args['filename'] );
		$response = new \WpOrg\Requests\Response();
		$location = $redirect_target;
		$hooks    = new WP_HTTP_Requests_Hooks( $url, $args );
		$hooks->register( 'requests.before_redirect', array( 'WP_Http', 'validate_redirects' ) );
		try {
			$hooks->dispatch( 'requests.before_redirect', array( &$location, &$headers, &$data, &$options, $response ) );
		} catch ( \WpOrg\Requests\Exception $error ) {
			return new WP_Error( 'http_request_failed', 'PRIVATE_PACKAGE_MARKER redirect refused' );
		}
		++$redirect_forwarded;
	}

	$body = $url === $theme_source ? $theme_bytes : $plugin_bytes;
	if ( 'invalid_package' === $mode ) {
		$body = 'PRIVATE_PACKAGE_MARKER not a zip';
	}
	if ( 'oversized' === $mode ) {
		$body = str_repeat( 'x', $args['limit_response_size'] );
	}
	file_put_contents( $args['filename'], $body );
	if ( 'revoke_http' === $mode ) {
		$access = get_option( Settings::OPTION_NAME );
		$access[ Settings::GROUP_EXTERNAL_PACKAGES ] = 0;
		update_option( Settings::OPTION_NAME, $access, false );
	}
	if ( 'http_throw' === $mode ) {
		throw new RuntimeException( 'PRIVATE_PACKAGE_MARKER ' . $url . ' ' . $args['filename'] );
	}
	if ( 'http_error' === $mode ) {
		return new WP_Error( 'PRIVATE_PACKAGE_MARKER', $url . ' ' . $args['filename'] );
	}
	return array(
		'response' => array( 'code' => 200, 'message' => 'Fixture' ),
		'headers'  => array( 'content-length' => (string) strlen( $body ) ),
		'body'     => '',
		'cookies'  => array(),
		'filename' => $args['filename'],
	);
};

$ability          = wp_get_ability( 'wp-native-builder/extension-lifecycle' );
$plugin_input     = array( 'kind' => 'plugin', 'action' => 'install', 'package_url' => $plugin_source );
$theme_input      = array( 'kind' => 'theme', 'action' => 'install', 'package_url' => $theme_source );
$installed_plugin = '';
$installed_theme  = '';
wpnb67_integration_assert( $ability instanceof WP_Ability, 'Extension lifecycle Ability is not registered.' );

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		if ( 0 === ( error_reporting() & $severity ) ) {
			return false;
		}
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);
try {
	add_filter( 'pre_http_request', $mock, 10, 3 );
	wpnb67_integration_assert( 0 === $settings->defaults()[ Settings::GROUP_EXTERNAL_PACKAGES ], 'External Packages must default off.' );
	foreach ( array( $settings->defaults(), array( Settings::GROUP_CODE_EXTENSIONS => 1 ), array( Settings::GROUP_EXTERNAL_PACKAGES => 1 ) ) as $access ) {
		update_option( Settings::OPTION_NAME, $access, false );
		wpnb67_integration_assert( is_wp_error( $ability->execute( $plugin_input ) ), 'Missing explicit package consent permitted install.' );
	}
	wpnb67_integration_assert( 0 === $calls, 'Disabled external package path performed network activity.' );

	update_option( Settings::OPTION_NAME, $enabled, false );
	$unsafe_calls = $calls;
	foreach ( array( 'http://s.w.org/package.zip', 'file:///tmp/package.zip', 'ftp://s.w.org/package.zip', 'https://user:pass@s.w.org/package.zip', 'https://127.0.0.1/package.zip', 'https://169.254.169.254/package.zip' ) as $unsafe ) {
		$result = $ability->execute( array_replace( $plugin_input, array( 'package_url' => $unsafe ) ) );
		wpnb67_integration_assert( is_wp_error( $result ) && 'unsafe_external_package_url' === $result->get_error_code(), 'Unsafe external package URL did not fail at preflight.' );
	}
	wpnb67_integration_assert( $unsafe_calls === $calls && 0 === $unexpected_calls, 'Unsafe package URL reached fixture HTTP.' );

	$result = $ability->execute( $plugin_input );
	wpnb67_integration_assert( ! is_wp_error( $result ) && true === $result['success'], 'External plugin package install failed.' );
	$installed_plugin = (string) $result['target'];
	wpnb67_integration_assert( isset( get_plugins()[ $installed_plugin ] ), 'Installed plugin target is not present in Core plugin inventory.' );
	wpnb67_integration_assert( ! is_plugin_active( $installed_plugin ), 'External plugin install activated code implicitly.' );
	foreach ( $staging as $file ) {
		wpnb67_integration_assert( ! is_file( $file ), 'Successful plugin install retained staging package.' );
	}
	wpnb67_integration_assert( false === strpos( json_encode( get_option( Mutation_Log::OPTION_NAME, array() ) ), 'PRIVATE_PACKAGE_MARKER' ), 'Successful plugin audit leaked package URL secret.' );

	$result = $ability->execute( $theme_input );
	wpnb67_integration_assert( ! is_wp_error( $result ) && true === $result['success'], 'External theme package install failed.' );
	$installed_theme = (string) $result['target'];
	wpnb67_integration_assert( wp_get_theme( $installed_theme )->exists(), 'Installed theme target is not present in Core theme inventory.' );
	wpnb67_integration_assert( get_stylesheet() !== $installed_theme, 'External theme install activated the theme implicitly.' );
	foreach ( $staging as $file ) {
		wpnb67_integration_assert( ! is_file( $file ), 'Successful theme install retained staging package.' );
	}

	foreach ( array( 'http_error', 'http_throw' ) as $failure ) {
		$mode = $failure;
		update_option( Settings::OPTION_NAME, $enabled, false );
		$result   = $ability->execute( $plugin_input );
		$expected = 'http_error' === $failure ? 'external_package_http_failed' : 'external_package_recovery_required';
		wpnb67_integration_assert( is_wp_error( $result ) && $expected === $result->get_error_code(), 'External package transport failure crossed the wrong boundary.' );
		wpnb67_integration_assert( false === strpos( $result->get_error_message(), 'PRIVATE_PACKAGE_MARKER' ), 'Transport failure leaked secret-bearing diagnostics.' );
		wpnb67_integration_assert( false === strpos( json_encode( get_option( Mutation_Log::OPTION_NAME, array() ) ), 'PRIVATE_PACKAGE_MARKER' ), 'Transport failure audit leaked secret-bearing diagnostics.' );
	}

	$mode = 'revoke_http';
	update_option( Settings::OPTION_NAME, $enabled, false );
	$before_plugins = array_keys( get_plugins() );
	$result         = $ability->execute( $plugin_input );
	wpnb67_integration_assert( is_wp_error( $result ) && 'external_package_permission_denied' === $result->get_error_code(), 'Mid-download package consent revocation did not fail closed.' );
	wpnb67_integration_assert( $before_plugins === array_keys( get_plugins() ), 'Revoked package consent reached Core installation.' );

	$mode = 'success';
	update_option( Settings::OPTION_NAME, $enabled, false );
	$redirect_target        = 'https://127.0.0.1/redirected.zip';
	$redirect_forwarded     = 0;
	$native_redirect_denied = false;
	try {
		WP_Http::validate_redirects( $redirect_target );
	} catch ( \WpOrg\Requests\Exception $error ) {
		$native_redirect_denied = true;
	}
	$result            = $ability->execute( $plugin_input );
	$expected_redirect = $native_redirect_denied ? 'external_package_http_failed' : 'unsafe_external_package_url';
	wpnb67_integration_assert( is_wp_error( $result ) && $expected_redirect === $result->get_error_code(), 'Unsafe package redirect did not fail at the native/Bridge boundary.' );
	wpnb67_integration_assert( 0 === $redirect_forwarded, 'Unsafe package redirect was forwarded.' );
	$redirect_target = null;

	$mode = 'invalid_package';
	update_option( Settings::OPTION_NAME, $enabled, false );
	$result = $ability->execute( $plugin_input );
	wpnb67_integration_assert( is_wp_error( $result ) && 'external_package_recovery_required' === $result->get_error_code(), 'Core package rejection was not converted to a bounded recovery result.' );
	wpnb67_integration_assert( false === strpos( $result->get_error_message(), 'PRIVATE_PACKAGE_MARKER' ), 'Core package failure leaked package diagnostics.' );

	$mode = 'oversized';
	update_option( Settings::OPTION_NAME, $enabled, false );
	$small_limit = static function () { return 256; };
	add_filter( 'upload_size_limit', $small_limit );
	$result = $ability->execute( $plugin_input );
	remove_filter( 'upload_size_limit', $small_limit );
	wpnb67_integration_assert( is_wp_error( $result ) && 'external_package_size_invalid' === $result->get_error_code(), 'Oversized external package was not rejected.' );

	foreach ( $staging as $file ) {
		wpnb67_integration_assert( ! is_file( $file ), 'Failed external package flow retained a staging file.' );
	}
} finally {
	restore_error_handler();
	remove_filter( 'pre_http_request', $mock, 10 );
	update_option( Settings::OPTION_NAME, $enabled, false );
	if ( '' !== $installed_plugin && isset( get_plugins()[ $installed_plugin ] ) ) {
		if ( is_plugin_active( $installed_plugin ) ) {
			deactivate_plugins( $installed_plugin );
		}
		delete_plugins( array( $installed_plugin ) );
	}
	if ( '' !== $installed_theme && wp_get_theme( $installed_theme )->exists() && get_stylesheet() !== $installed_theme && get_template() !== $installed_theme ) {
		delete_theme( $installed_theme );
	}
	foreach ( $staging as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	update_option( Mutation_Log::OPTION_NAME, $original_log, false );
	update_option( Settings::OPTION_NAME, $original, false );
}

echo 'PASS: Issue #67 external package integration (' . $GLOBALS['wpnb67_integration_checks'] . " assertions).\n";
