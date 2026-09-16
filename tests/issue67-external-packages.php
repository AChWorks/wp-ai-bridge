<?php
namespace WP_Native_Builder_Bridge\Abilities {
	function gethostbynamel( $host ) {
		return $GLOBALS['wpnb67']['resolved_ipv4'] ?? array( '93.184.216.34' );
	}
	function dns_get_record( $host, $type ) {
		return $GLOBALS['wpnb67']['dns_records'] ?? array();
	}
}
namespace WpOrg\Requests {
	class Exception extends \Exception {
		public function __construct( $message, $type ) {
			parent::__construct( $message );
		}
	}
}
namespace {
/** Dependency-free external package installation boundary regressions. */
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Extension_Abilities;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

$checks = 0;
function wpnb67_assert( $condition, $message ) {
	++$GLOBALS['checks'];
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function wpnb67_reset() {
	foreach ( $GLOBALS['wpnb67']['files'] ?? array() as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
	wpnb_test_reset_state();
	$GLOBALS['wpnb_test']['capabilities']['install_plugins'] = true;
	$GLOBALS['wpnb_test']['capabilities']['install_themes']  = true;
	$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = array(
		'code_extensions'   => 1,
		'external_packages' => 1,
	);
	$GLOBALS['wpnb67'] = array(
		'files'         => array(),
		'http_calls'    => 0,
		'installs'      => 0,
		'payload'       => 'synthetic-package-bytes',
		'max'           => 64,
		'status'        => 200,
		'mode'          => 'success',
		'args'          => array(),
		'redirect'      => null,
		'redirected'    => 0,
		'resolved_ipv4' => array( '93.184.216.34' ),
		'dns_records'   => array(),
	);
}
function wp_max_upload_size() { return $GLOBALS['wpnb67']['max']; }
function wp_tempnam( $name ) {
	$file = tempnam( sys_get_temp_dir(), 'wpnb67-' );
	$GLOBALS['wpnb67']['files'][] = $file;
	return $file;
}
function wp_delete_file( $file ) {
	if ( 'cleanup_noop' === $GLOBALS['wpnb67']['mode'] ) {
		return;
	}
	if ( 'cleanup_throw' === $GLOBALS['wpnb67']['mode'] ) {
		throw new RuntimeException( 'PRIVATE_PACKAGE_MARKER cleanup ' . $file );
	}
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}
function wp_http_validate_url( $url ) {
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return false;
	}
	return $url;
}
function remove_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['wpnb_test']['actions'][ $hook ] = array_values(
		array_filter(
			$GLOBALS['wpnb_test']['actions'][ $hook ] ?? array(),
			static function ( $registered ) use ( $callback ) { return $registered !== $callback; }
		)
	);
	return true;
}
function wp_safe_remote_get( $url, $args ) {
	++$GLOBALS['wpnb67']['http_calls'];
	$GLOBALS['wpnb67']['args'] = $args;
	if ( null !== $GLOBALS['wpnb67']['redirect'] ) {
		$location = $GLOBALS['wpnb67']['redirect'];
		try {
			foreach ( $GLOBALS['wpnb_test']['actions']['requests-requests.before_redirect'] ?? array() as $callback ) {
				$callback( $location, array(), null, array( 'filename' => $args['filename'] ) );
			}
		} catch ( \WpOrg\Requests\Exception $error ) {
			return new WP_Error( 'http_request_failed', 'PRIVATE_PACKAGE_MARKER ' . $url );
		}
		++$GLOBALS['wpnb67']['redirected'];
	}
	file_put_contents( $args['filename'], substr( $GLOBALS['wpnb67']['payload'], 0, $args['limit_response_size'] ) );
	if ( 'revoke_http' === $GLOBALS['wpnb67']['mode'] ) {
		$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ]['external_packages'] = 0;
	}
	if ( 'http_throw' === $GLOBALS['wpnb67']['mode'] ) {
		throw new RuntimeException( 'PRIVATE_PACKAGE_MARKER ' . $url . ' ' . $args['filename'] );
	}
	if ( 'http_error' === $GLOBALS['wpnb67']['mode'] ) {
		return new WP_Error( 'PRIVATE_PACKAGE_MARKER', $url . ' ' . $args['filename'] );
	}
	return array(
		'response' => array( 'code' => $GLOBALS['wpnb67']['status'] ),
		'headers'  => array( 'content-length' => (string) filesize( $args['filename'] ) ),
	);
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ $name ] ?? ''; }

class Automatic_Upgrader_Skin {}
class Plugin_Upgrader {
	public function __construct( $skin ) {}
	public function install( $package ) {
		++$GLOBALS['wpnb67']['installs'];
		if ( 'upgrader_throw' === $GLOBALS['wpnb67']['mode'] ) {
			throw new RuntimeException( 'PRIVATE_PACKAGE_MARKER ' . $package );
		}
		if ( 'upgrader_error' === $GLOBALS['wpnb67']['mode'] ) {
			return new WP_Error( 'PRIVATE_PACKAGE_MARKER', $package );
		}
		return true;
	}
	public function plugin_info() { return 'wpnb-external-plugin/wpnb-external-plugin.php'; }
}
class Theme_Upgrader {
	public function __construct( $skin ) {}
	public function install( $package ) {
		++$GLOBALS['wpnb67']['installs'];
		if ( 'upgrader_throw' === $GLOBALS['wpnb67']['mode'] ) {
			throw new RuntimeException( 'PRIVATE_PACKAGE_MARKER ' . $package );
		}
		if ( 'upgrader_error' === $GLOBALS['wpnb67']['mode'] ) {
			return new WP_Error( 'PRIVATE_PACKAGE_MARKER', $package );
		}
		return true;
	}
	public function theme_info() {
		return new WP_Native_Builder_Test_Theme(
			array(
				'Name'       => 'External Theme',
				'Version'    => '1.0.0',
				'stylesheet' => 'wpnb-external-theme',
				'template'   => 'wpnb-external-theme',
			)
		);
	}
}

function wpnb67_error( $result, $code ) {
	wpnb67_assert( is_wp_error( $result ), 'Expected a WP_Error for ' . $code );
	wpnb67_assert( $code === $result->get_error_code(), 'Unexpected error code: ' . $result->get_error_code() );
	wpnb67_assert( false === strpos( $result->get_error_message(), 'PRIVATE_PACKAGE_MARKER' ), 'Response leaked a package secret.' );
	wpnb67_assert( false === strpos( json_encode( get_option( Mutation_Log::OPTION_NAME ) ), 'PRIVATE_PACKAGE_MARKER' ), 'Mutation log leaked a package secret.' );
}
function wpnb67_no_files() {
	foreach ( $GLOBALS['wpnb67']['files'] as $file ) {
		wpnb67_assert( ! is_file( $file ), 'Invocation-owned package file leaked.' );
	}
}

wpnb67_reset();
$settings = new Settings();
$ability  = new Extension_Abilities( new Permissions( $settings ), new Mutation_Log() );
$package  = array(
	'kind'        => 'plugin',
	'action'      => 'install',
	'package_url' => 'https://packages.example.test/plugin.zip?signature=PRIVATE_PACKAGE_MARKER',
);
set_error_handler(
	static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); }
);
try {
	wpnb67_assert( 0 === $settings->defaults()[ Settings::GROUP_EXTERNAL_PACKAGES ], 'External Packages must default off.' );
	$ability->register();
	$definition = $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/extension-lifecycle'];
	wpnb67_assert( isset( $definition['input_schema']['properties']['package_url'] ), 'Lifecycle schema omitted package_url.' );
	wpnb67_assert( false === $definition['input_schema']['additionalProperties'], 'Lifecycle schema must reject arbitrary package request controls.' );
	wpnb67_assert( ! isset( $definition['input_schema']['properties']['headers'], $definition['input_schema']['properties']['cookies'], $definition['input_schema']['properties']['path'] ), 'Lifecycle schema exposed generic transport/filesystem inputs.' );

	$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = array( 'code_extensions' => 1 );
	wpnb67_assert( true === $ability->can_mutate( array( 'kind' => 'plugin', 'action' => 'install', 'slug' => 'hello-dolly' ) ), 'WordPress.org slug install incorrectly inherited External Packages consent.' );
	wpnb67_assert( false === $ability->can_mutate( $package ), 'External package install bypassed External Packages consent.' );
	$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ]['external_packages'] = 1;
	$GLOBALS['wpnb_test']['capabilities']['install_plugins'] = false;
	wpnb67_assert( false === $ability->can_mutate( $package ), 'External package install bypassed native install_plugins authority.' );
	$GLOBALS['wpnb_test']['capabilities']['install_plugins'] = true;
	wpnb67_assert( true === $ability->can_mutate( $package ), 'Authorized external package input was denied.' );

	foreach ( array( 'http://packages.example.test/plugin.zip', 'file:///tmp/plugin.zip', 'ftp://packages.example.test/plugin.zip', 'https://user:pass@packages.example.test/plugin.zip', 'https://127.0.0.1/plugin.zip', 'https://169.254.169.254/plugin.zip' ) as $unsafe ) {
		wpnb67_reset();
		wpnb67_error( $ability->mutate( array_replace( $package, array( 'package_url' => $unsafe ) ) ), 'unsafe_external_package_url' );
		wpnb67_assert( 0 === $GLOBALS['wpnb67']['http_calls'], 'Unsafe package URL reached HTTP transport.' );
	}

	wpnb67_reset();
	$result = $ability->mutate( $package );
	wpnb67_assert( ! is_wp_error( $result ) && true === $result['success'], 'Synthetic external plugin install failed.' );
	wpnb67_assert( 'wpnb-external-plugin/wpnb-external-plugin.php' === $result['target'], 'Plugin install returned the wrong target.' );
	wpnb67_assert( 1 === $GLOBALS['wpnb67']['http_calls'] && 1 === $GLOBALS['wpnb67']['installs'], 'Plugin install did not use one bounded download and one Core install.' );
	wpnb67_assert( true === $GLOBALS['wpnb67']['args']['stream'] && true === $GLOBALS['wpnb67']['args']['sslverify'] && false === $GLOBALS['wpnb67']['args']['decompress'], 'Package download weakened stream/TLS/compression constraints.' );
	wpnb67_assert( array() === $GLOBALS['wpnb67']['args']['cookies'] && array( 'Accept-Encoding' => 'identity' ) === $GLOBALS['wpnb67']['args']['headers'], 'Package download forwarded cookies or arbitrary headers.' );
	wpnb67_assert( $GLOBALS['wpnb67']['max'] + 1 === $GLOBALS['wpnb67']['args']['limit_response_size'], 'Package download did not use an overflow sentinel.' );
	wpnb67_no_files();
	wpnb67_assert( false === strpos( json_encode( get_option( Mutation_Log::OPTION_NAME ) ), 'PRIVATE_PACKAGE_MARKER' ), 'Successful audit leaked package URL secrets.' );

	wpnb67_reset();
	$theme = $ability->mutate( array_replace( $package, array( 'kind' => 'theme' ) ) );
	wpnb67_assert( ! is_wp_error( $theme ) && 'wpnb-external-theme' === $theme['target'], 'Synthetic external theme install failed.' );
	wpnb67_no_files();

	foreach ( array( 'http_error', 'http_throw' ) as $mode ) {
		wpnb67_reset();
		$GLOBALS['wpnb67']['mode'] = $mode;
		wpnb67_error( $ability->mutate( $package ), 'http_throw' === $mode ? 'external_package_recovery_required' : 'external_package_http_failed' );
		wpnb67_no_files();
	}

	wpnb67_reset();
	$GLOBALS['wpnb67']['max']     = 8;
	$GLOBALS['wpnb67']['payload'] = str_repeat( 'x', 20 );
	wpnb67_error( $ability->mutate( $package ), 'external_package_size_invalid' );
	wpnb67_no_files();

	wpnb67_reset();
	$GLOBALS['wpnb67']['mode'] = 'revoke_http';
	wpnb67_error( $ability->mutate( $package ), 'external_package_permission_denied' );
	wpnb67_assert( 0 === $GLOBALS['wpnb67']['installs'], 'Revoked package authority reached Core installation.' );
	wpnb67_no_files();

	wpnb67_reset();
	$GLOBALS['wpnb67']['redirect'] = 'https://127.0.0.1/redirected.zip';
	wpnb67_error( $ability->mutate( $package ), 'unsafe_external_package_url' );
	wpnb67_assert( 0 === $GLOBALS['wpnb67']['redirected'], 'Unsafe package redirect was forwarded.' );
	wpnb67_no_files();

	foreach ( array( 'upgrader_error', 'upgrader_throw' ) as $mode ) {
		wpnb67_reset();
		$GLOBALS['wpnb67']['mode'] = $mode;
		wpnb67_error( $ability->mutate( $package ), 'external_package_recovery_required' );
		wpnb67_no_files();
	}

	wpnb67_reset();
	$GLOBALS['wpnb67']['mode'] = 'cleanup_noop';
	wpnb67_error( $ability->mutate( $package ), 'external_package_recovery_required' );
	wpnb67_assert( 1 === $GLOBALS['wpnb67']['installs'], 'Cleanup failure fixture did not reach committed install state.' );
	foreach ( $GLOBALS['wpnb67']['files'] as $file ) {
		if ( is_file( $file ) ) { unlink( $file ); }
	}

	wpnb67_reset();
	wpnb67_error( $ability->mutate( array( 'kind' => 'plugin', 'action' => 'install', 'slug' => 'hello-dolly', 'package_url' => $package['package_url'] ) ), 'extension_install_source_required' );
	wpnb67_error( $ability->mutate( array( 'kind' => 'plugin', 'action' => 'update', 'target' => 'fixture/fixture.php', 'package_url' => $package['package_url'] ) ), 'extension_install_source_not_applicable' );
} finally {
	restore_error_handler();
	foreach ( $GLOBALS['wpnb67']['files'] ?? array() as $file ) {
		if ( is_file( $file ) ) { unlink( $file ); }
	}
}

echo 'PASS: Issue #67 external package boundary (' . $checks . " assertions).\n";
}
