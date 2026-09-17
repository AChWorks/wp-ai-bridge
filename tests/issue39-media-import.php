<?php
namespace WP_AI_Bridge\Abilities {
    function gethostbynamel( $host ) { return $GLOBALS['wpnb39']['resolved_ipv4'] ?? array( '93.184.216.34' ); }
    function dns_get_record( $host, $type ) { return $GLOBALS['wpnb39']['dns_records'] ?? array(); }
}
namespace WpOrg\Requests {
    class Exception extends \Exception {
        public function __construct( $message, $type ) { parent::__construct( $message ); }
    }
}
namespace {
/** Dependency-free URL media import regressions. */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Media_Abilities;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

$checks = 0;
function wpnb39_assert( $condition, $message ) {
	++$GLOBALS['checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function wpnb39_reset() {
	foreach ( $GLOBALS['wpnb39']['files'] ?? array() as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
	wpai_test_reset_state();
	$GLOBALS['wpai_test']['capabilities']['upload_files'] = true;
	$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] = array( 'builder_write' => 1, 'remote_media' => 1 );
	$GLOBALS['wpnb39'] = array( 'files' => array(), 'staging' => array(), 'http_calls' => 0, 'payload' => 'media', 'max' => 32, 'status' => 200, 'length' => '', 'posts' => array(), 'meta' => array(), 'args' => array(), 'mode' => '', 'type' => true );
}
function sanitize_file_name( $name ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', $name ); }
function wp_max_upload_size() {
	if ( 'limit_throw' === $GLOBALS['wpnb39']['mode'] ) { throw new RuntimeException( 'private-token limit' ); }
	return $GLOBALS['wpnb39']['max'];
}
function wp_tempnam( $name ) {
	if ( 'temp_throw' === $GLOBALS['wpnb39']['mode'] ) { throw new RuntimeException( 'private-token temp' ); }
	if ( 'temp_failure' === $GLOBALS['wpnb39']['mode'] ) { return false; }
	$file = tempnam( sys_get_temp_dir(), 'wpnb39-' );
	$GLOBALS['wpnb39']['files'][] = $file;
	$GLOBALS['wpnb39']['staging'][] = $file;
	if ( 'revoke_temp' === $GLOBALS['wpnb39']['mode'] ) { $GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ]['remote_media'] = 0; }
	return $file;
}
function wp_delete_file( $file ) {
	if ( 'throw' === ( $GLOBALS['wpnb39']['cleanup_mode'] ?? '' ) ) { throw new RuntimeException( 'private-token ' . $file ); }
	if ( 'noop' === ( $GLOBALS['wpnb39']['cleanup_mode'] ?? '' ) ) { return; }
	if ( is_file( $file ) ) { unlink( $file ); }
}
function wp_http_validate_url( $url ) {
	$parts = parse_url( $url );
	return is_array( $parts ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) && ! empty( $parts['host'] ) && ! isset( $parts['user'], $parts['pass'] ) && ! isset( $parts['user'] ) && ! in_array( $parts['host'], array( 'localhost', '127.0.0.1' ), true ) ? $url : false;
}
function remove_action( $hook, $callback, $priority = 10 ) {
    $GLOBALS['wpai_test']['actions'][ $hook ] = array_values( array_filter( $GLOBALS['wpai_test']['actions'][ $hook ] ?? array(), static fn( $registered ) => $registered !== $callback ) );
    return true;
}
function wp_safe_remote_get( $url, $args ) {
	++$GLOBALS['wpnb39']['http_calls'];
	$GLOBALS['wpnb39']['args'] = $args;
	if ( isset( $GLOBALS['wpnb39']['redirect'] ) ) {
		if ( 'revoke_redirect' === $GLOBALS['wpnb39']['mode'] ) { $GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ]['remote_media'] = 0; }
		try {
			foreach ( $GLOBALS['wpai_test']['actions']['requests-requests.before_redirect'] ?? array() as $callback ) {
				$callback( $GLOBALS['wpnb39']['redirect'], array(), null, array( 'filename' => $args['filename'] ) );
			}
		} catch ( \WpOrg\Requests\Exception $error ) { return new WP_Error( 'http_request_failed', $error->getMessage() ); }
		++$GLOBALS['wpnb39']['http_calls'];
	}

	file_put_contents( $args['filename'], substr( $GLOBALS['wpnb39']['payload'], 0, $args['limit_response_size'] ) );
	if ( 'revoke_http' === $GLOBALS['wpnb39']['mode'] ) { $GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ]['remote_media'] = 0; }
	if ( 'missing_staging' === $GLOBALS['wpnb39']['mode'] ) { wp_delete_file( $args['filename'] ); }
	if ( 'http_throw' === $GLOBALS['wpnb39']['mode'] ) { throw new RuntimeException( 'private-token ' . $url . ' ' . $args['filename'] ); }
	if ( 'http_error' === $GLOBALS['wpnb39']['mode'] ) { return new WP_Error( 'provider_url_private-token', 'private-token ' . $url . ' ' . $args['filename'] ); }
	return array( 'response' => array( 'code' => $GLOBALS['wpnb39']['status'] ), 'headers' => array( 'content-length' => $GLOBALS['wpnb39']['length'] ) );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_header( $response, $key ) { return $response['headers'][ $key ] ?? ''; }
function get_allowed_mime_types() { return array( 'png' => 'image/png' ); }
function wp_check_filetype_and_ext( $file, $name, $mimes ) {
	if ( 'type_throw' === $GLOBALS['wpnb39']['mode'] ) { throw new RuntimeException( 'private-token ' . $file ); }
	if ( ! $GLOBALS['wpnb39']['type'] ) { return array( 'ext' => false, 'type' => false, 'proper_filename' => false ); }
	return array( 'ext' => 'png', 'type' => 'image/png', 'proper_filename' => $GLOBALS['wpnb39']['proper_filename'] ?? false );
}
function media_handle_sideload( $file, $parent = 0, $description = null, $post_data = array() ) { throw new RuntimeException( 'The URL importer must use its cleanup-aware WordPress lifecycle.' ); }
function wp_handle_sideload( &$file, $overrides = false, $time = null ) {
	if ( 'sideload_throw_before' === $GLOBALS['wpnb39']['mode'] ) { throw new RuntimeException( 'private-token before sideload' ); }
	if ( 'sideload_error' === $GLOBALS['wpnb39']['mode'] ) { return array( 'error' => 'private-token ' . $file['tmp_name'] ); }
	$target = $file['tmp_name'] . '-uploaded.png';
	rename( $file['tmp_name'], $target );
	$GLOBALS['wpnb39']['files'][] = $target;
	if ( 'sideload_throw_after' === $GLOBALS['wpnb39']['mode'] ) { throw new RuntimeException( 'private-token ' . $target ); }
	if ( 'revoke_sideload' === $GLOBALS['wpnb39']['mode'] ) { $GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ]['remote_media'] = 0; }
	return array( 'file' => $target, 'type' => 'image/png', 'url' => 'https://site.test/media.png' );
}
function wp_insert_attachment( $data, $file, $parent, $wp_error ) {
	if ( 'insert_throw_before' === $GLOBALS['wpnb39']['mode'] ) { throw new RuntimeException( 'private-token before insert' ); }
	if ( 'insert_error' === $GLOBALS['wpnb39']['mode'] ) { return new WP_Error( 'private-token', $file ); }
	$id = 100 + count( $GLOBALS['wpnb39']['posts'] );
	$GLOBALS['wpnb39']['posts'][ $id ] = (object) array_merge( $data, array( 'ID' => $id, 'post_parent' => $parent, 'post_type' => 'attachment', 'post_modified_gmt' => '2026-01-01 00:00:00' ) );
	if ( 'insert_throw_after' === $GLOBALS['wpnb39']['mode'] ) { throw new RuntimeException( 'private-token committed insert' ); }
	return $id;
}
function wp_generate_attachment_metadata( $id, $file ) {
	if ( 'metadata_throw' === $GLOBALS['wpnb39']['mode'] ) { throw new RuntimeException( 'private-token ' . $file ); }
	return array( 'width' => 1, 'height' => 1, 'filesize' => filesize( $file ) ); }
function wp_update_attachment_metadata( $id, $metadata ) { $GLOBALS['wpnb39']['meta'][ $id ] = $metadata; return true; }
function wp_get_attachment_metadata( $id ) { return $GLOBALS['wpnb39']['meta'][ $id ] ?? array(); }
function wp_get_attachment_url( $id ) {
	if ( 'output_throw' === $GLOBALS['wpnb39']['mode'] ) { throw new RuntimeException( 'private-token output' ); }
	return 'https://site.test/media/' . $id . '.png'; }
function get_post( $id ) {
	if ( 'output_missing' === $GLOBALS['wpnb39']['mode'] ) { return null; }
	return $GLOBALS['wpnb39']['posts'][ $id ] ?? null;
}
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['wpnb39']['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['wpnb39']['meta'][ $id ][ $key ] = $value; return true; }
function wpnb39_no_files() {
	foreach ( $GLOBALS['wpnb39']['files'] as $file ) { wpnb39_assert( ! is_file( $file ), 'Failed import leaked a staging/destination file.' ); }
}
function wpnb39_error( $result, $code ) {
	wpnb39_assert( is_wp_error( $result ) && $code === $result->get_error_code(), 'Expected ' . $code );
	wpnb39_assert( false === strpos( $result->get_error_message(), 'private-token' ), 'Response disclosed a provider secret.' );
	wpnb39_assert( false === strpos( json_encode( get_option( Mutation_Log::OPTION_NAME ) ), 'private-token' ), 'Audit log disclosed a provider secret.' );
	wpnb39_no_files();
}

wpnb39_reset();
$settings = new Settings();
$media = new Media_Abilities( new Permissions( $settings ), new Mutation_Log() );
$input = array( 'url' => 'https://cdn.example.test/file?signature=private-token', 'filename' => 'image.png' );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
try {
	wpnb39_assert( 0 === $settings->defaults()['remote_media'], 'Remote Media must default off.' );
	$media->register();
	$definition = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/media-import-url'];
	wpnb39_assert( ! isset( $definition['input_schema']['properties']['content_base64'] ), 'Import must not share the Base64 payload contract.' );
	wpnb39_assert( array( 'url', 'filename' ) === $definition['input_schema']['required'], 'Import schema must require URL and filename.' );
	wpnb39_assert( false === $definition['input_schema']['additionalProperties'], 'Import schema must reject arbitrary headers/paths.' );
	foreach ( array( array(), array( 'builder_write' => 1 ), array( 'remote_media' => 1 ) ) as $stored ) {
		wpnb39_reset();
		update_option( Settings::OPTION_NAME, $stored );
		wpnb39_error( $media->import_url( $input ), 'media_import_permission_denied' );
		wpnb39_assert( 0 === $GLOBALS['wpnb39']['http_calls'], 'Disabled/upgrade state performed network activity.' );
	}
	wpnb39_reset();
	$GLOBALS['wpai_test']['capabilities']['upload_files'] = false;
	wpnb39_error( $media->import_url( $input ), 'media_import_permission_denied' );
	wpnb39_reset();
	wpnb39_error( $media->import_url( $input + array( 'post_id' => 42 ) ), 'media_import_permission_denied' );
	wpnb39_assert( 0 === $GLOBALS['wpnb39']['http_calls'], 'Unauthorized parent performed network activity.' );
	foreach ( array( '42', -1, array( 42 ) ) as $parent ) {
		wpnb39_reset();
		wpnb39_error( $media->import_url( $input + array( 'post_id' => $parent ) ), 'media_import_permission_denied' );
	}
	foreach ( array( 'file:///etc/passwd', 'ftp://example.test/media', 'http://127.0.0.1/media', 'https://user:pass@example.test/media' ) as $url ) {
		wpnb39_reset();
		wpnb39_error( $media->import_url( array_replace( $input, array( 'url' => $url ) ) ), 'unsafe_media_import_url' );
		wpnb39_assert( 0 === $GLOBALS['wpnb39']['http_calls'], 'Unsafe URL reached transport.' );
	}
	foreach ( array( 'http://169.254.169.254/media', 'http://100.64.0.1/media', 'http://192.0.2.1/media', 'http://240.0.0.1/media' ) as $url ) {
		wpnb39_reset();
		wpnb39_error( $media->import_url( array_replace( $input, array( 'url' => $url ) ) ), 'unsafe_media_import_url' );
		wpnb39_assert( 0 === $GLOBALS['wpnb39']['http_calls'], 'Reserved address reached HTTP.' );
		wpnb39_reset(); $GLOBALS['wpnb39']['redirect'] = $url;
		wpnb39_error( $media->import_url( $input ), 'unsafe_media_import_url' );
		wpnb39_assert( 1 === $GLOBALS['wpnb39']['http_calls'], 'Unsafe redirect reached a second transport.' );
		wpnb39_assert( empty( $GLOBALS['wpai_test']['actions']['requests-requests.before_redirect'] ), 'Redirect guard survived the request.' );
	}
	foreach ( array( array( 'resolved_ipv4' => array( '93.184.216.34', '169.254.1.1' ) ), array( 'dns_records' => array( array( 'ipv6' => 'fe80::1' ) ) ), array( 'dns_records' => false ) ) as $resolution ) {
		wpnb39_reset(); $GLOBALS['wpnb39'] = array_replace( $GLOBALS['wpnb39'], $resolution );
		wpnb39_error( $media->import_url( $input ), 'unsafe_media_import_url' );
		wpnb39_assert( 0 === $GLOBALS['wpnb39']['http_calls'], 'Non-public or failed resolution reached HTTP.' );
	}
	wpnb39_reset(); $GLOBALS['wpnb39']['redirect'] = $input['url']; $GLOBALS['wpnb39']['mode'] = 'revoke_redirect';
	wpnb39_error( $media->import_url( $input ), 'media_import_permission_denied' );
	wpnb39_assert( 1 === $GLOBALS['wpnb39']['http_calls'], 'Revoked redirect reached transport.' );
	foreach ( array( '../image.png', 'folder\\image.png', "image\0.png", str_repeat( 'a', 256 ) ) as $filename ) {
		wpnb39_reset();
		wpnb39_error( $media->import_url( array_replace( $input, array( 'filename' => $filename ) ) ), 'invalid_media_import_input' );
	}
	foreach ( array( 'run.php', 'image.phtml', 'package.phar', 'no-extension' ) as $filename ) {
		wpnb39_reset();
		wpnb39_error( $media->import_url( array_replace( $input, array( 'filename' => $filename ) ) ), 'invalid_media_filename' );
	}
	foreach ( array( 0, -1, PHP_INT_MAX ) as $max ) {
		wpnb39_reset(); $GLOBALS['wpnb39']['max'] = $max;
		wpnb39_error( $media->import_url( $input ), 'media_import_limit_unavailable' );
	}
	foreach ( array( 'temp_failure' => 'media_temp_failed', 'http_error' => 'media_import_http_failed', 'http_throw' => 'media_import_recovery_required', 'missing_staging' => 'media_import_size_invalid', 'revoke_http' => 'media_import_permission_denied', 'revoke_sideload' => 'media_import_permission_denied', 'sideload_error' => 'media_import_sideload_failed', 'insert_error' => 'media_import_attachment_failed' ) as $mode => $code ) {
		wpnb39_reset(); $GLOBALS['wpnb39']['mode'] = $mode;
		wpnb39_error( $media->import_url( $input ), $code );
	}
	foreach ( array( 'limit_throw', 'temp_throw', 'type_throw', 'sideload_throw_before' ) as $mode ) {
		wpnb39_reset(); $GLOBALS['wpnb39']['mode'] = $mode;
		wpnb39_error( $media->import_url( $input ), 'media_import_recovery_required' );
	}
	wpnb39_reset(); $GLOBALS['wpnb39']['mode'] = 'revoke_temp';
	wpnb39_error( $media->import_url( $input ), 'media_import_permission_denied' );
	wpnb39_assert( 0 === $GLOBALS['wpnb39']['http_calls'], 'Revocation during staging allocation still reached HTTP.' );
	foreach ( array( 'sideload_throw_after', 'insert_throw_before', 'insert_throw_after', 'metadata_throw', 'output_throw', 'output_missing' ) as $mode ) {
		wpnb39_reset(); $GLOBALS['wpnb39']['mode'] = $mode;
		$result = $media->import_url( $input );
		wpnb39_assert( is_wp_error( $result ) && 'media_import_recovery_required' === $result->get_error_code(), 'Partial import did not require recovery.' );
		wpnb39_assert( false === strpos( $result->get_error_message(), 'private-token' ) && false === strpos( $result->get_error_message(), sys_get_temp_dir() ), 'Partial import leaked diagnostics.' );
		$remaining = array_values( array_filter( $GLOBALS['wpnb39']['files'], 'is_file' ) );
		wpnb39_assert( 1 === count( $remaining ), 'Unconfirmed or committed native state was blindly deleted.' );
		foreach ( $GLOBALS['wpnb39']['staging'] as $file ) { wpnb39_assert( ! is_file( $file ), 'Partial import retained temporary staging.' ); }
		if ( in_array( $mode, array( 'insert_throw_after', 'metadata_throw', 'output_throw', 'output_missing' ), true ) ) {
			wpnb39_assert( 1 === count( $GLOBALS['wpnb39']['posts'] ), 'Committed attachment was not preserved.' );
		}
		if ( in_array( $mode, array( 'metadata_throw', 'output_throw', 'output_missing' ), true ) ) {
			wpnb39_assert( false !== strpos( $result->get_error_message(), '100' ), 'Known attachment identity was lost from recovery guidance.' );
		}
		$audit = get_option( Mutation_Log::OPTION_NAME, array() );
		wpnb39_assert( false === $audit[0]['success'] && false === strpos( json_encode( $audit ), 'private-token' ), 'Partial failure audit was unsafe or claimed success.' );
	}
	foreach ( array( 'throw', 'noop' ) as $cleanup_mode ) {
		wpnb39_reset();
		$GLOBALS['wpnb39']['mode'] = 'http_error';
		$GLOBALS['wpnb39']['cleanup_mode'] = $cleanup_mode;
		$result = $media->import_url( $input );
		wpnb39_assert( is_wp_error( $result ) && 'media_import_recovery_required' === $result->get_error_code(), 'Cleanup failure was hidden.' );
		wpnb39_assert( false === strpos( $result->get_error_message(), 'private-token' ), 'Cleanup exception leaked diagnostics.' );
		wpnb39_assert( 1 === count( array_filter( $GLOBALS['wpnb39']['files'], 'is_file' ) ), 'Cleanup fixture did not retain the expected owned file.' );
	}
	foreach ( array( 206, 301, 404, 500 ) as $status ) {
		wpnb39_reset(); $GLOBALS['wpnb39']['status'] = $status;
		wpnb39_error( $media->import_url( $input ), 'media_import_http_failed' );
	}
	foreach ( array( '', str_repeat( 'x', 33 ) ) as $payload ) {
		wpnb39_reset(); $GLOBALS['wpnb39']['payload'] = $payload;
		wpnb39_error( $media->import_url( $input ), 'media_import_size_invalid' );
	}
	foreach ( array( '99', 'broken', array( '5', '6' ) ) as $length ) {
		wpnb39_reset(); $GLOBALS['wpnb39']['length'] = $length;
		wpnb39_error( $media->import_url( $input ), 'media_import_incomplete' );
	}
	wpnb39_reset(); $GLOBALS['wpnb39']['type'] = false;
	wpnb39_error( $media->import_url( $input ), 'media_import_type_denied' );
	wpnb39_reset(); $GLOBALS['wpnb39']['proper_filename'] = 'replacement.php';
	wpnb39_error( $media->import_url( $input ), 'media_import_type_denied' );
	foreach ( array( 'https://one.example.test/media', 'https://another.example.test/media' ) as $url ) {
		wpnb39_reset();
		$GLOBALS['wpai_test']['capabilities']['edit_post'] = true;
		$GLOBALS['wpnb39']['max'] = 30 * 1024 * 1024;
		$GLOBALS['wpnb39']['length'] = '5';
		$result = $media->import_url( array_replace( $input, array( 'url' => $url, 'post_id' => 42, 'title' => 'Imported title', 'caption' => 'Caption', 'description' => 'Description', 'alt_text' => '<b>Alternative</b>' ) ) );
		wpnb39_assert( ! is_wp_error( $result ), 'Valid provider-neutral import failed.' );
		wpnb39_assert( 42 === $result['parent_id'] && 'Imported title' === $result['title'] && 'Alternative' === $result['alt_text'], 'Attachment fields were not preserved/sanitized.' );
		wpnb39_assert( 30 * 1024 * 1024 + 1 === $GLOBALS['wpnb39']['args']['limit_response_size'], 'Streaming incorrectly inherited the Base64 20 MiB limit.' );
		wpnb39_assert( true === $GLOBALS['wpnb39']['args']['sslverify'] && true === $GLOBALS['wpnb39']['args']['stream'], 'TLS verification/streaming was disabled.' );
		wpnb39_assert( false === $GLOBALS['wpnb39']['args']['decompress'] && array() === $GLOBALS['wpnb39']['args']['cookies'], 'Download unexpectedly enabled decompression or forwarded cookies.' );
		wpnb39_assert( 30 === $GLOBALS['wpnb39']['args']['timeout'] && 5 === $GLOBALS['wpnb39']['args']['redirection'], 'Finite network budgets changed.' );
		foreach ( $GLOBALS['wpnb39']['staging'] as $file ) { wpnb39_assert( ! is_file( $file ), 'Success leaked staging file.' ); }
		wpnb39_assert( false === strpos( json_encode( $result ), sys_get_temp_dir() ), 'Success response exposed a server path.' );
	}
	echo 'PASS: Issue #39 media import (' . $GLOBALS['checks'] . " assertions).\n";
} finally {
	restore_error_handler();
	foreach ( $GLOBALS['wpnb39']['files'] as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
}

}
