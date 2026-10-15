<?php
/**
 * Issue #168: real Core replacement with exact, administrator-approved ZIPs.
 * This fixture runs ONLY in disposable WordPress/MariaDB CI installations.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Extension_Install_Lock;

function wpai168_check( $condition, $message ) {
	$GLOBALS['wpai168_checks']++;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai168_zip( $kind, $root, $version, $extra = array() ) {
	$file = wp_tempnam( 'wpai168-package.zip' );
	wpai168_check( is_string( $file ) && '' !== $file && class_exists( 'ZipArchive' ), 'ZIP staging unavailable.' );
	$zip = new ZipArchive();
	wpai168_check( true === $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Cannot construct disposable test ZIP.' );
	if ( 'plugin' === $kind ) {
		$zip->addFromString( $root . '/' . $root . '.php', "<?php\n/*\nPlugin Name: WP AI Bridge Exact Replacement Fixture\nVersion: " . $version . "\n*/\n" );
	} else {
		$zip->addFromString( $root . '/style.css', "/*\nTheme Name: WP AI Bridge Exact Replacement Fixture\nVersion: " . $version . "\n*/\n" );
		$zip->addFromString( $root . '/index.php', "<?php\n" );
	}
	foreach ( $extra as $name => $contents ) {
		$zip->addFromString( $name, $contents );
	}
	$zip->close();
	$bytes = file_get_contents( $file );
	wp_delete_file( $file );
	wpai168_check( is_string( $bytes ) && '' !== $bytes, 'Disposable ZIP did not contain bytes.' );
	return $bytes;
}

$GLOBALS['wpai168_checks'] = 0;
$settings = new Settings();
$old_settings = get_option( Settings::OPTION_NAME, $settings->defaults() );
$old_activity = get_option( Mutation_Log::OPTION_NAME, array() );
$enabled = $settings->defaults();
$enabled[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
$enabled[ Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
$plugin_root = 'wpai-issue168-fixture';
$plugin_file = $plugin_root . '/' . $plugin_root . '.php';
$plugin_directory = WP_PLUGIN_DIR . '/' . $plugin_root;
$theme_root = 'wpai-issue168-theme';
$theme_directory = get_theme_root() . '/' . $theme_root;
$old_stylesheet = get_stylesheet();
$old_template = get_template();
$plugin_url = 'https://s.w.org/issue168-plugin.zip';
$theme_url = 'https://s.w.org/issue168-theme.zip';
$plugin_bytes = wpai168_zip( 'plugin', $plugin_root, '1.1.0', array( $plugin_root . '/fixture-data.txt' => 'candidate-version' ) );
$theme_bytes = wpai168_zip( 'theme', $theme_root, '1.1.0' );
$mode = 'normal';
$calls = 0;
$staged = array();
$headers = array();
$mock = static function ( $pre, $args, $url ) use ( &$calls, &$mode, &$staged, &$headers, $plugin_url, $theme_url, &$plugin_bytes, &$theme_bytes, $settings ) {
	if ( ! in_array( $url, array( $plugin_url, $theme_url ), true ) ) {
		return $pre;
	}
	++$calls;
	$staged[] = $args['filename'];
	wpai168_check( ! empty( $args['reject_unsafe_urls'] ) && true === $args['sslverify'] && true === $args['stream'], 'ZIP transport lost its safe bounded flags.' );
	$bytes = $url === $theme_url ? $theme_bytes : $plugin_bytes;
	file_put_contents( $args['filename'], $bytes );
	if ( 'revoke' === $mode ) {
		$grants = $settings->all();
		$grants[ Settings::GROUP_EXTERNAL_PACKAGES ] = 0;
		update_option( Settings::OPTION_NAME, $grants, false );
	}
	return array(
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'headers' => array( 'content-length' => (string) strlen( $bytes ) ),
		'body' => '',
		'cookies' => array(),
		'filename' => $args['filename'],
	);
};
$ability = wp_get_ability( 'wp-ai-bridge/extension-lifecycle' );
wpai168_check( $ability instanceof WP_Ability, 'Missing installed native extension-lifecycle Ability.' );
wpai168_check( wp_mkdir_p( $plugin_directory ) && wp_mkdir_p( $theme_directory ), 'Could not allocate disposable extension directories.' );
file_put_contents( WP_PLUGIN_DIR . '/' . $plugin_file, "<?php\n/*\nPlugin Name: WP AI Bridge Exact Replacement Fixture\nVersion: 1.0.0\n*/\n" );
file_put_contents( $plugin_directory . '/fixture-data.txt', 'original-version' );
file_put_contents( $theme_directory . '/style.css', "/*\nTheme Name: WP AI Bridge Exact Replacement Fixture\nVersion: 1.0.0\n*/\n" );
file_put_contents( $theme_directory . '/index.php', "<?php\n" );
wp_clean_plugins_cache( true );
wp_clean_themes_cache( true );
wpai168_check( '1.0.0' === get_plugins()[ $plugin_file ]['Version'], 'Original plugin fixture not discoverable.' );
$plugin_input = array(
	'kind' => 'plugin',
	'action' => 'update',
	'target' => $plugin_file,
	'package_url' => $plugin_url,
	'expected_installed_version' => '1.0.0',
	'expected_package_version' => '1.1.0',
	'expected_sha256' => hash( 'sha256', $plugin_bytes ),
	'confirm_replace' => true,
);
$theme_input = array(
	'kind' => 'theme',
	'action' => 'update',
	'target' => $theme_root,
	'package_url' => $theme_url,
	'expected_installed_version' => '1.0.0',
	'expected_package_version' => '1.1.0',
	'expected_sha256' => hash( 'sha256', $theme_bytes ),
	'confirm_replace' => true,
);
$removed = false;
$assert_error = static function ( $input, $code ) use ( $ability ) {
	$result = $ability->execute( $input );
	wpai168_check( is_wp_error( $result ) && $code === $result->get_error_code(), 'Wrong replacement rejection: ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'success' ) . ', expected ' . $code );
	wpai168_check( false === strpos( $result->get_error_message(), 'issue168-plugin.zip' ), 'Error disclosed package URL.' );
};
set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);
try {
	add_filter( 'pre_http_request', $mock, 10, 3 );
	wpai168_check( null === activate_plugin( $plugin_file ), 'Could not activate original fixture.' );
	wpai168_check( is_plugin_active( $plugin_file ), 'Original fixture is not active.' );

	foreach ( array( $settings->defaults(), array( Settings::GROUP_CODE_EXTENSIONS => 1 ), array( Settings::GROUP_EXTERNAL_PACKAGES => 1 ) ) as $grants ) {
		update_option( Settings::OPTION_NAME, $grants, false );
		wpai168_check( is_wp_error( $ability->execute( $plugin_input ) ), 'Denied replacement ran without both Bridge access grants.' );
	}
	wpai168_check( 0 === $calls, 'Denied replacement performed a network call.' );
	update_option( Settings::OPTION_NAME, $enabled, false );
	$assert_error( array_replace( $plugin_input, array( 'confirm_replace' => false ) ), 'external_package_confirmation_required' );
	$assert_error( array_replace( $plugin_input, array( 'expected_installed_version' => '0.9.0' ) ), 'external_package_target_stale' );
	$assert_error( array_replace( $plugin_input, array( 'expected_package_version' => '0.9.0' ) ), 'external_package_downgrade_denied' );
	$assert_error( array_replace( $plugin_input, array( 'target' => 'wpai-missing/wpai-missing.php' ) ), 'external_package_target_missing' );
	wpai168_check( 0 === $calls, 'Preflight rejection performed an HTTP download.' );
	// WordPress Core's direct recursive overwrite could follow a linked
	// directory and erase data outside the installed extension root.
	$outside = sys_get_temp_dir() . '/wpai168-outside-' . uniqid( '', true );
	wpai168_check( wp_mkdir_p( $outside ), 'Disposable outside-root fixture is unavailable.' );
	file_put_contents( $outside . '/untouched.txt', 'must-survive' );
	$linked = $plugin_directory . '/linked-outside';
	wpai168_check( symlink( $outside, $linked ), 'Cannot construct disposable nested-symlink regression.' );
	$prior_calls = $calls;
	$assert_error( $plugin_input, 'external_package_unsafe_installed_tree' );
	wpai168_check( $calls === $prior_calls, 'Nested linked-directory rejection reached HTTP transport.' );
	wpai168_check( 'must-survive' === file_get_contents( $outside . '/untouched.txt' ), 'A rejected Core overwrite escaped through a symlink.' );
	unlink( $linked );
	unlink( $outside . '/untouched.txt' );
	rmdir( $outside );

	// Core overwrites an entire directory even when the caller supplied
	// only one exact plugin basename. Independently registered siblings MUST
	// prevent it, including an active sibling whose files must survive.
	$sibling_basename = $plugin_root . '/sibling.php';
	$sibling_path     = $plugin_directory . '/sibling.php';
	$sibling_content  = "<?php\\n/*\\nPlugin Name: WP AI Bridge Shared Directory Sibling\\nVersion: 9.9.9\\n*/\\n";
	file_put_contents( $sibling_path, $sibling_content );
	wp_clean_plugins_cache( false );
	try {
		wpai168_check( '9.9.9' === (string) ( get_plugins()[ $sibling_basename ]['Version'] ?? '' ), 'Core did not register the sibling plugin.' );
		wpai168_check( null === activate_plugin( $sibling_basename ), 'Could not activate the sibling fixture.' );
		wpai168_check( is_plugin_active( $sibling_basename ), 'Sibling was not active.' );
		$before_shared_calls = $calls;
		$assert_error( $plugin_input, 'external_package_shared_plugin_directory' );
		wpai168_check( $before_shared_calls === $calls, 'Shared-directory refusal downloaded a package.' );
		wpai168_check( $sibling_content === file_get_contents( $sibling_path ), 'Shared-directory refusal changed sibling source.' );
		wpai168_check( 'original-version' === file_get_contents( $plugin_directory . '/fixture-data.txt' ), 'Shared-directory refusal changed target source.' );
		wpai168_check( is_plugin_active( $sibling_basename ) && is_plugin_active( $plugin_file ), 'Shared-directory refusal changed activation.' );
		wpai168_check( '1.0.0' === (string) get_plugins()[ $plugin_file ]['Version'], 'Shared-directory refusal changed target version.' );
	} finally {
		if ( is_plugin_active( $sibling_basename ) ) {
			deactivate_plugins( $sibling_basename );
		}
		if ( is_file( $sibling_path ) ) {
			unlink( $sibling_path );
		}
		wp_clean_plugins_cache( false );
	}

	$assert_error( array_replace( $plugin_input, array( 'expected_sha256' => str_repeat( 'a', 64 ) ) ), 'external_package_hash_mismatch' );
	wpai168_check( is_plugin_active( $plugin_file ) && 'original-version' === file_get_contents( $plugin_directory . '/fixture-data.txt' ), 'Rejected SHA changed active plugin.' );

	$wrong = wpai168_zip( 'plugin', 'wpai-wrong-root', '1.1.0' );
	$plugin_bytes = $wrong;
	$assert_error( array_replace( $plugin_input, array( 'expected_sha256' => hash( 'sha256', $wrong ) ) ), 'external_package_archive_invalid' );
	$plugin_bytes = wpai168_zip( 'plugin', $plugin_root, '1.1.0', array( $plugin_root . '/../escape.php' => '<?php' ) );
	$assert_error( array_replace( $plugin_input, array( 'expected_sha256' => hash( 'sha256', $plugin_bytes ) ) ), 'external_package_archive_invalid' );
	$plugin_bytes = wpai168_zip( 'plugin', $plugin_root, '1.1.0', array( $plugin_root . '/fixture-data.txt' => 'candidate-version' ) );
	$mode = 'revoke';
	$assert_error( $plugin_input, 'external_package_permission_denied' );
	wpai168_check( 'original-version' === file_get_contents( $plugin_directory . '/fixture-data.txt' ), 'Revoked consent reached Core.' );
	$mode = 'normal';
	update_option( Settings::OPTION_NAME, $enabled, false );

	$hold = new Extension_Install_Lock();
	wpai168_check( $hold->acquire(), 'Could not acquire isolated native lock.' );
	$assert_error( $plugin_input, 'external_package_busy' );
	$hold->release();
	wpai168_check( 'original-version' === file_get_contents( $plugin_directory . '/fixture-data.txt' ), 'Concurrent admission changed target.' );

	$result = $ability->execute( $plugin_input );
	wpai168_check( ! is_wp_error( $result ) && true === $result['success'] && $plugin_file === $result['target'], 'Native plugin ZIP replacement failed (' . ( is_wp_error( $result ) ? $result->get_error_code() : 'no-error' ) . ').' );
	wp_clean_plugins_cache( true );
	wpai168_check( '1.1.0' === get_plugins()[ $plugin_file ]['Version'], 'Plugin replacement did not install exact new version.' );
	wpai168_check( is_plugin_active( $plugin_file ), 'Replacing an active plugin lost activation.' );
	wpai168_check( 'candidate-version' === file_get_contents( $plugin_directory . '/fixture-data.txt' ), 'Core installer did not replace existing files.' );
	$assert_error( $plugin_input, 'external_package_target_stale' );
	wpai168_check( '1.1.0' === get_plugins()[ $plugin_file ]['Version'], 'A replay changed already-replaced plugin.' );

	$theme = $ability->execute( $theme_input );
	wpai168_check( ! is_wp_error( $theme ) && true === $theme['success'] && $theme_root === $theme['target'], 'Native theme ZIP replacement failed.' );
	wpai168_check( '1.1.0' === wp_get_theme( $theme_root )->get( 'Version' ), 'Theme version did not upgrade.' );
	wpai168_check( $old_stylesheet === get_stylesheet() && $old_template === get_template(), 'Replacing an inactive theme changed the active site theme.' );
	foreach ( $staged as $file ) {
		wpai168_check( ! is_file( $file ), 'Temporary downloaded package was not removed.' );
	}
	wpai168_check( false === strpos( wp_json_encode( get_option( Mutation_Log::OPTION_NAME, array() ) ), $plugin_url ), 'Audit leaked original package URL.' );
} finally {
	restore_error_handler();
	remove_filter( 'pre_http_request', $mock, 10 );
	update_option( Settings::OPTION_NAME, $old_settings, false );
	update_option( Mutation_Log::OPTION_NAME, $old_activity, false );
	if ( is_plugin_active( $plugin_file ) ) {
		deactivate_plugins( $plugin_file );
	}
	if ( isset( get_plugins()[ $plugin_file ] ) ) {
		delete_plugins( array( $plugin_file ) );
	}
	if ( wp_get_theme( $theme_root )->exists() && $old_stylesheet !== $theme_root && $old_template !== $theme_root ) {
		delete_theme( $theme_root );
	}
	foreach ( $staged as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
}
echo 'PASS: Issue #168 exact WordPress plugin/theme ZIP replacement (' . $GLOBALS['wpai168_checks'] . " assertions).\n";
