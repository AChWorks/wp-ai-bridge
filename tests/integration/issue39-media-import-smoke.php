<?php
/** Real WordPress URL import lifecycle, authorization, transport and cleanup tests. */
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Native_Builder_Bridge\Support\Mutation_Log;

$GLOBALS['wpnb39_integration_checks'] = 0;
function wpnb39_integration_assert( $condition, $message ) {
    ++$GLOBALS['wpnb39_integration_checks'];
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$original_log = get_option( Mutation_Log::OPTION_NAME, array() );
$original_user = get_current_user_id();
$created = array();
$staging = array();
$destinations = array();
$calls = 0;
$unexpected_calls = 0;
$redirect_target = null;
$redirect_forwarded = 0;
$mode = 'success';
$source = 'https://s.w.org/wpai-media-import-fixture?signature=PRIVATE_MEDIA_MARKER';
$input = array( 'url' => $source, 'filename' => 'wpai-import.png' );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=' );
$enabled = $settings->defaults();
$enabled[ Settings::GROUP_BUILDER_WRITE ] = 1;
$enabled[ Settings::GROUP_REMOTE_MEDIA ] = 1;
$mock = static function ( $pre, $args, $url ) use ( &$calls, &$mode, &$staging, &$unexpected_calls, &$redirect_target, &$redirect_forwarded, $source, $png ) {
    ++$calls;
    if ( ! in_array( $url, array( $source, 'https://wordpress.org/wpai-media-import-fixture' ), true ) ) {
        ++$unexpected_calls;
        return new WP_Error( 'unexpected_fixture_http', 'Unexpected fixture HTTP request was blocked.' );
    }
    wpnb39_integration_assert( ! empty( $args['reject_unsafe_urls'] ) && true === $args['sslverify'], 'Native safe HTTP or TLS validation was disabled.' );
    wpnb39_integration_assert( true === $args['stream'] && false === $args['decompress'], 'Expected bounded uncompressed streaming.' );
    wpnb39_integration_assert( array() === $args['cookies'] && array( 'Accept-Encoding' => 'identity' ) === $args['headers'], 'Unexpected credential or caller-header forwarding.' );
    wpnb39_integration_assert( 30 === $args['timeout'] && 5 === $args['redirection'], 'Network budgets must remain finite.' );
    wpnb39_integration_assert( wp_max_upload_size() + 1 === $args['limit_response_size'], 'Streaming must use the current WordPress limit and overflow sentinel.' );
    $staging[] = $args['filename'];
    if ( null !== $redirect_target ) {
        // Dispatch the real Core/Requests redirect hooks without sending a redirect request.
        $headers = array(); $data = null;
        $options = array( 'filename' => $args['filename'] );
        $response = new \WpOrg\Requests\Response();
        $location = $redirect_target;
        $unrelated = new WP_HTTP_Requests_Hooks( $url, $args );
        $unrelated_options = array( 'filename' => 'unrelated-fixture-stream' );
        $unrelated->dispatch( 'requests.before_redirect', array( &$location, &$headers, &$data, &$unrelated_options, $response ) );
        $hooks = new WP_HTTP_Requests_Hooks( $url, $args );
        $hooks->register( 'requests.before_redirect', array( 'WP_Http', 'validate_redirects' ) );
        try {
            $hooks->dispatch( 'requests.before_redirect', array( &$location, &$headers, &$data, &$options, $response ) );
        } catch ( \WpOrg\Requests\Exception $error ) {
            return new WP_Error( 'http_request_failed', 'The fixture redirect was refused.' );
        }
        ++$redirect_forwarded;
    }
    if ( 'http_throw' === $mode || 'translation_throw' === $mode ) { throw new RuntimeException( 'PRIVATE_MEDIA_MARKER ' . $url . ' ' . $args['filename'] ); }
    if ( in_array( $mode, array( 'http_error', 'cleanup_throw', 'cleanup_noop' ), true ) ) { return new WP_Error( 'PRIVATE_MEDIA_MARKER', $url . ' ' . $args['filename'] ); }
    if ( 'large_file' === $mode ) {
        $stream = fopen( $args['filename'], 'wb' );
        wpnb39_integration_assert( is_resource( $stream ), 'Large fixture stream could not be opened.' );
        try {
            $chunk = str_repeat( 'x', 1024 * 1024 );
            for ( $index = 0; $index < 21; ++$index ) {
                wpnb39_integration_assert( strlen( $chunk ) === fwrite( $stream, $chunk ), 'Large fixture write was incomplete.' );
            }
        } finally { fclose( $stream ); }
        return array( 'response' => array( 'code' => 200, 'message' => 'Fixture' ), 'headers' => array( 'content-length' => (string) ( 21 * 1024 * 1024 ) ), 'body' => '', 'cookies' => array(), 'filename' => $args['filename'] );
    }
    if ( 'missing_staging' === $mode ) { wp_delete_file( $args['filename'] ); }
    $body = 'empty' === $mode ? '' : $png;
    if ( 'oversized' === $mode ) { $body = str_repeat( 'x', $args['limit_response_size'] ); }
    if ( 'invalid_type' === $mode ) { $body = 'This is not an image.'; }
    if ( 'missing_staging' !== $mode ) { file_put_contents( $args['filename'], $body ); }
    if ( 'revoke_http' === $mode ) {
        $access = get_option( Settings::OPTION_NAME );
        $access[ Settings::GROUP_REMOTE_MEDIA ] = 0;
        update_option( Settings::OPTION_NAME, $access, false );
    }
    return array(
        'response' => array( 'code' => 'http_status' === $mode ? 500 : 200, 'message' => 'Fixture' ),
        'headers' => array( 'content-length' => 'truncated' === $mode ? '99999' : (string) strlen( $body ) ),
        'body' => '', 'cookies' => array(), 'filename' => $args['filename'],
    );
};
$observe_upload = static function ( $upload, $context ) use ( &$destinations, &$mode ) {
    if ( 'sideload' === $context && ! empty( $upload['file'] ) ) {
        $destinations[] = $upload['file'];
        if ( 'sideload_throw_after' === $mode ) { throw new RuntimeException( 'PRIVATE_MEDIA_MARKER ' . $upload['file'] ); }
        if ( 'revoke_sideload' === $mode ) {
            $access = get_option( Settings::OPTION_NAME );
            $access[ Settings::GROUP_REMOTE_MEDIA ] = 0;
            update_option( Settings::OPTION_NAME, $access, false );
        }
    }
    return $upload;
};
$reject_sideload = static function ( $file ) use ( &$mode ) {
    if ( 'sideload_throw_before' === $mode ) { throw new RuntimeException( 'PRIVATE_MEDIA_MARKER ' . $file['tmp_name'] ); }
    if ( 'sideload_error' === $mode ) { $file['error'] = 'PRIVATE_MEDIA_MARKER ' . $file['tmp_name']; }
    return $file;
};
$reject_insert = static function ( $empty, $postarr ) use ( &$mode ) {
    if ( 'insert_throw_before' === $mode && 'attachment' === ( $postarr['post_type'] ?? '' ) ) { throw new RuntimeException( 'PRIVATE_MEDIA_MARKER before attachment insert' ); }
    return 'insert_error' === $mode && 'attachment' === ( $postarr['post_type'] ?? '' ) ? true : $empty;
};
$small_limit = static function () { return 128; };
$deny_upload = static function ( $caps, $cap ) { return 'upload_files' === $cap ? array( 'do_not_allow' ) : $caps; };
$parent = 0;
$last_created = 0;
$large_limit = static function () { return 24 * 1024 * 1024; };
$observe_insert = static function ( $id ) use ( &$mode, &$created, &$last_created ) {
    $created[] = $id;
    $last_created = $id;
    if ( 'insert_throw_after' === $mode ) { throw new RuntimeException( 'PRIVATE_MEDIA_MARKER after committed attachment' ); }
};
$metadata_fault = static function ( $metadata, $id ) use ( &$mode ) {
    if ( 'metadata_throw' === $mode ) { throw new RuntimeException( 'PRIVATE_MEDIA_MARKER ' . get_attached_file( $id ) ); }
    return $metadata;
};
$output_fault = static function ( $url ) use ( &$mode ) {
    if ( 'output_throw' === $mode ) { throw new RuntimeException( 'PRIVATE_MEDIA_MARKER ' . $url ); }
    return $url;
};
$cleanup_fault = static function ( $file ) use ( &$mode, &$staging ) {
    if ( in_array( $file, $staging, true ) ) {
        if ( 'cleanup_throw' === $mode ) { throw new RuntimeException( 'PRIVATE_MEDIA_MARKER ' . $file ); }
        if ( 'cleanup_noop' === $mode ) { return ''; }
    }
    return $file;
};
$audit_fault = static function ( $value ) use ( &$mode ) {
    if ( 'audit_throw' === $mode ) { throw new RuntimeException( 'PRIVATE_MEDIA_MARKER audit' ); }
    return $value;
};
$translation_fault = static function ( $translated, $text, $domain ) use ( &$mode ) {
    if ( 'translation_throw' === $mode && 'wp-ai-bridge' === $domain && 0 === strpos( $text, 'The media import' ) ) { throw new RuntimeException( 'PRIVATE_MEDIA_MARKER translation' ); }
    return $translated;
};
$permission_fault = static function ( $caps, $cap ) use ( &$mode ) {
    if ( 'permission_throw' === $mode && 'upload_files' === $cap ) { throw new RuntimeException( 'PRIVATE_MEDIA_MARKER authority' ); }
    return $caps;
};
set_error_handler( static function ( $severity, $message, $file, $line ) {
    if ( 0 === ( error_reporting() & $severity ) ) { return false; }
    throw new ErrorException( $message, 0, $severity, $file, $line );
} );
try {
    $ability = wp_get_ability( 'wp-ai-bridge/media-import-url' );
    wpnb39_integration_assert( $ability instanceof WP_Ability, 'Import Ability is not registered.' );
    add_filter( 'pre_http_request', $mock, 10, 3 );
    add_filter( 'wp_handle_sideload_prefilter', $reject_sideload );
    add_filter( 'wp_handle_upload', $observe_upload, 10, 2 );
    add_filter( 'wp_insert_post_empty_content', $reject_insert, 10, 2 );
    foreach ( array( $settings->defaults(), array( 'builder_write' => 1 ), array( 'remote_media' => 1 ) ) as $access ) {
        update_option( Settings::OPTION_NAME, $access, false );
        wpnb39_integration_assert( is_wp_error( $ability->execute( $input ) ), 'Missing or legacy opt-in permitted import.' );
    }
    wpnb39_integration_assert( 0 === $calls, 'Disabled import performed network activity.' );
    update_option( Settings::OPTION_NAME, $enabled, false );
    add_filter( 'map_meta_cap', $deny_upload, 10, 2 );
    wpnb39_integration_assert( is_wp_error( $ability->execute( $input ) ), 'Missing upload authority permitted import.' );
    remove_filter( 'map_meta_cap', $deny_upload, 10 );
    wpnb39_integration_assert( is_wp_error( $ability->execute( $input + array( 'post_id' => PHP_INT_MAX ) ) ), 'A nonexistent parent permitted import.' );
    wpnb39_integration_assert( 0 === $calls, 'Native authority must be checked before HTTP.' );
    wpnb39_integration_assert( true === $ability->check_permissions( $input ), 'Unsafe URL fixture requires its authorized baseline before testing input rejection.' );
    foreach ( array( 'file:///etc/passwd', 'ftp://wordpress.org/media', 'http://127.0.0.1/media', 'http://169.254.169.254/media', 'https://user:pass@wordpress.org/media' ) as $vector_index => $unsafe ) {
        $denied = $ability->execute( array_replace( $input, array( 'url' => $unsafe ) ) );
        $denial_code = is_wp_error( $denied ) ? $denied->get_error_code() : gettype( $denied );
        wpnb39_integration_assert( is_wp_error( $denied ) && 'unsafe_media_import_url' === $denial_code, sprintf( 'Unsafe URL fixture %d returned %s instead of its preflight error (HTTP entries: %d).', $vector_index, $denial_code, $calls ) );
        wpnb39_integration_assert( 0 === $calls && 0 === $unexpected_calls, 'Unsafe preflight attempted HTTP.' );
        $redirect_denied = false;
        try { WP_Http::validate_redirects( $unsafe ); }
        catch ( \WpOrg\Requests\Exception $error ) { $redirect_denied = true; }
        // Core historically omits the link-local range; Bridge must still reject it above.
        if ( 3 !== $vector_index ) { wpnb39_integration_assert( $redirect_denied, 'Native redirect validator accepted a Core-invalid destination.' ); }
    }
    wpnb39_integration_assert( 0 === $calls, 'Unsafe URL reached fixture transport.' );
    foreach ( array( '../image.png', 'image.php', 'image.phtml', 'package.phar' ) as $filename ) {
        wpnb39_integration_assert( is_wp_error( $ability->execute( array_replace( $input, array( 'filename' => $filename ) ) ) ), 'Invalid or executable filename was accepted.' );
    }
    foreach ( array( 'http://169.254.169.254/media', 'http://100.64.0.1/media', 'http://192.0.2.1/media' ) as $target ) {
        $native_redirect_denied = false;
        try { WP_Http::validate_redirects( $target ); }
        catch ( \WpOrg\Requests\Exception $error ) { $native_redirect_denied = true; }
        $calls = 0; $redirect_forwarded = 0; $redirect_target = $target;
        $before_hooks = has_action( 'requests-requests.before_redirect' );
        $denied = $ability->execute( $input );
        $expected_redirect_error = $native_redirect_denied ? 'media_import_http_failed' : 'unsafe_media_import_url';
        wpnb39_integration_assert( is_wp_error( $denied ) && $expected_redirect_error === $denied->get_error_code(), 'Reserved redirect did not fail at the expected native/Bridge boundary.' );
        wpnb39_integration_assert( 1 === $calls && 0 === $redirect_forwarded && 0 === $unexpected_calls, 'An unsafe redirect passed the real native hook boundary.' );
        wpnb39_integration_assert( $before_hooks === has_action( 'requests-requests.before_redirect' ), 'Import redirect guard was not removed after refusal.' );
        foreach ( $staging as $file ) { wpnb39_integration_assert( ! is_file( $file ), 'Rejected redirect retained staging.' ); }
    }
    $redirect_target = 'https://wordpress.org/wpai-media-import-fixture';
    $redirect_forwarded = 0;
    $result = $ability->execute( $input );
    wpnb39_integration_assert( ! is_wp_error( $result ) && 1 === $redirect_forwarded, 'A public redirect failed the native hook validation.' );
    $created[] = $result['id'];
    $redirect_target = null;
    foreach ( array( 'http_error', 'http_status', 'empty', 'oversized', 'truncated', 'invalid_type', 'revoke_http', 'revoke_sideload', 'sideload_error', 'insert_error', 'missing_staging' ) as $failure ) {
        $mode = $failure;
        update_option( Settings::OPTION_NAME, $enabled, false );
        add_filter( 'upload_size_limit', $small_limit );
        $staging = array(); $destinations = array();
        $result = $ability->execute( $input );
        remove_filter( 'upload_size_limit', $small_limit );
        wpnb39_integration_assert( is_wp_error( $result ), 'Import failure was not reported: ' . $failure );
        wpnb39_integration_assert( false === strpos( $result->get_error_message(), 'PRIVATE_MEDIA_MARKER' ), 'Failure leaked source/provider data.' );
        foreach ( array_merge( $staging, $destinations ) as $file ) {
            wpnb39_integration_assert( ! is_file( $file ), 'Import failure leaked a staging/destination file: ' . $failure );
        }
    }
    $mode = 'success';
    update_option( Settings::OPTION_NAME, $enabled, false );
    $parent = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'URL import test parent' ), true );
    wpnb39_integration_assert( ! is_wp_error( $parent ) && $parent > 0, 'Could not create parent fixture.' );
    foreach ( array( $source, 'https://wordpress.org/wpai-media-import-fixture' ) as $url ) {
        $staging = array(); $destinations = array();
        $result = $ability->execute( array_replace( $input, array( 'url' => $url, 'post_id' => (int) $parent, 'title' => 'Imported title', 'description' => 'Description', 'caption' => 'Caption', 'alt_text' => '<b>Alternative</b>' ) ) );
        wpnb39_integration_assert( ! is_wp_error( $result ), 'Valid import failed native execution/schema/lifecycle.' );
        $created[] = $result['id'];
        wpnb39_integration_assert( 'attachment' === get_post_type( $result['id'] ) && is_file( get_attached_file( $result['id'] ) ), 'Import did not create a real attachment/file.' );
        wpnb39_integration_assert( (int) $parent === $result['parent_id'] && 'Imported title' === $result['title'] && 'Alternative' === $result['alt_text'], 'Normal attachment fields did not persist.' );
        wpnb39_integration_assert( 1 === $result['width'] && 1 === $result['height'], 'WordPress image metadata was not generated.' );
        wpnb39_integration_assert( false === strpos( wp_json_encode( $result ), 'PRIVATE_MEDIA_MARKER' ) && false === strpos( wp_json_encode( $result ), '/var/www/' ), 'Attachment result leaked a URL credential or server path.' );
        foreach ( $staging as $file ) { wpnb39_integration_assert( ! is_file( $file ), 'Successful import leaked staging.' ); }
    }
    // The fixture never relaxes URL/TLS policy; the real transfer uses an official public origin.
    remove_filter( 'pre_http_request', $mock, 10 );
    $result = $ability->execute( array( 'url' => 'https://s.w.org/images/wmark.png', 'filename' => 'wpai-public-download.png' ) );
    wpnb39_integration_assert( ! is_wp_error( $result ), 'Actual public HTTPS media transfer failed.' );
    $created[] = $result['id'];
    wpnb39_integration_assert( is_file( get_attached_file( $result['id'] ) ) && $result['width'] > 0, 'Actual transfer did not persist usable media.' );
    $audit = wp_json_encode( get_option( Mutation_Log::OPTION_NAME ) );
    wpnb39_integration_assert( false === strpos( $audit, 'PRIVATE_MEDIA_MARKER' ) && false === strpos( $audit, 'wmark.png' ), 'Audit retained request URL or payload details.' );
    $adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
    $disabled = $enabled; $disabled[ Settings::GROUP_REMOTE_MEDIA ] = 0;
    update_option( Settings::OPTION_NAME, $disabled, false );
    wpnb39_integration_assert( is_wp_error( $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/media-import-url', 'parameters' => $input ) ) ), 'Adapter bypassed revoked import access.' );
    // Exercise actual native callbacks and the pinned Adapter, not a fake transport result wrapper.
    add_filter( 'pre_http_request', $mock, 10, 3 );
    add_action( 'add_attachment', $observe_insert );
    add_filter( 'wp_generate_attachment_metadata', $metadata_fault, 10, 2 );
    add_filter( 'wp_get_attachment_url', $output_fault );
    add_filter( 'wp_delete_file', $cleanup_fault );
    add_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $audit_fault );
    add_filter( 'gettext', $translation_fault, 10, 3 );
    add_filter( 'map_meta_cap', $permission_fault, 11, 2 );
    foreach ( array( 'direct', 'adapter' ) as $route ) {
        foreach ( array( 'http_throw', 'sideload_throw_before', 'sideload_throw_after', 'insert_throw_before', 'insert_throw_after', 'metadata_throw', 'output_throw', 'cleanup_throw', 'cleanup_noop', 'audit_throw', 'translation_throw' ) as $failure ) {
            $mode = $failure;
            $staging = array(); $destinations = array(); $last_created = 0;
            update_option( Settings::OPTION_NAME, $enabled, false );
            $result = 'direct' === $route ? $ability->execute( $input ) : $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/media-import-url', 'parameters' => $input ) );
            if ( 'direct' === $route ) {
                wpnb39_integration_assert( is_wp_error( $result ) && 'media_import_recovery_required' === $result->get_error_code(), 'Native exception did not become a recovery error: ' . $failure );
                $message = $result->get_error_message();
                $data = $result->get_error_data();
                wpnb39_integration_assert( is_array( $data ) && isset( $data['attachment_state'], $data['known_cleanup_complete'] ), 'Bounded recovery state is missing.' );
                if ( in_array( $failure, array( 'cleanup_throw', 'cleanup_noop' ), true ) ) {
                    wpnb39_integration_assert( false === $data['known_cleanup_complete'], 'Failed cleanup was reported complete.' );
                }
                if ( 'insert_throw_after' === $failure ) {
                    wpnb39_integration_assert( 'unconfirmed' === $data['attachment_state'] && 0 === $data['attachment_id'], 'A pre-return committed ID was guessed.' );
                }
            } else {
                wpnb39_integration_assert( is_array( $result ) && false === ( $result['success'] ?? null ) && isset( $result['error'] ), 'Adapter did not return a bounded failed execution: ' . $failure );
                $message = $result['error'];
            }
            wpnb39_integration_assert( false === strpos( $message, 'PRIVATE_MEDIA_MARKER' ) && false === strpos( $message, '/var/www/' ) && false === strpos( $message, 'signature=' ), 'Native/Adapter exception disclosed private diagnostics.' );
            $committed = in_array( $failure, array( 'insert_throw_after', 'metadata_throw', 'output_throw', 'audit_throw' ), true );
            if ( $committed ) {
                wpnb39_integration_assert( $last_created > 0 && 'attachment' === get_post_type( $last_created ) && is_file( get_attached_file( $last_created ) ), 'Committed native attachment/file was deleted or not established.' );
                if ( 'insert_throw_after' !== $failure ) {
                    wpnb39_integration_assert( false !== strpos( $message, (string) $last_created ), 'Known attachment ID is missing from Adapter-visible recovery guidance.' );
                }
            } else {
                wpnb39_integration_assert( 0 === $last_created, 'Pre-attachment failure unexpectedly committed an attachment.' );
            }
            if ( $committed || in_array( $failure, array( 'sideload_throw_after', 'insert_throw_before' ), true ) ) {
                wpnb39_integration_assert( 1 === count( $destinations ) && is_file( $destinations[0] ), 'Unconfirmed/committed destination was blindly removed.' );
            }
            foreach ( $staging as $file ) {
                $expected_remaining = in_array( $failure, array( 'cleanup_throw', 'cleanup_noop' ), true );
                wpnb39_integration_assert( $expected_remaining === is_file( $file ), 'Unexpected native staging cleanup outcome.' );
            }
            wpnb39_integration_assert( false === strpos( wp_json_encode( get_option( Mutation_Log::OPTION_NAME ) ), 'PRIVATE_MEDIA_MARKER' ), 'Exception details reached the mutation log.' );
            // Only the test owner removes the verified fixture state after checking preservation.
            $mode = 'success';
            if ( $last_created > 0 ) { wp_delete_attachment( $last_created, true ); }
            foreach ( array_merge( $staging, $destinations ) as $file ) { if ( is_file( $file ) ) { wp_delete_file( $file ); } }
        }
        $mode = 'permission_throw';
        $before_calls = $calls;
        $result = 'direct' === $route ? $ability->execute( $input ) : $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/media-import-url', 'parameters' => $input ) );
        wpnb39_integration_assert( is_wp_error( $result ) && false === strpos( $result->get_error_message(), 'PRIVATE_MEDIA_MARKER' ), 'Permission exception escaped or became authority.' );
        wpnb39_integration_assert( $before_calls === $calls, 'Permission exception reached HTTP.' );
        $mode = 'success';
    }
    // A real native attachment larger than the Base64 transport ceiling, with bounded fixture writes.
    $mode = 'large_file'; $staging = array(); $destinations = array();
    update_option( Settings::OPTION_NAME, $enabled, false );
    add_filter( 'upload_size_limit', $large_limit );
    $result = $ability->execute( array_replace( $input, array( 'filename' => 'wpai-large-import.txt' ) ) );
    remove_filter( 'upload_size_limit', $large_limit );
    wpnb39_integration_assert( ! is_wp_error( $result ), 'Native streamed import above 20 MiB failed.' );
    $created[] = $result['id'];
    $stored_file = get_attached_file( $result['id'] );
    $hash = hash_init( 'sha256' );
    for ( $index = 0; $index < 21; ++$index ) { hash_update( $hash, str_repeat( 'x', 1024 * 1024 ) ); }
    wpnb39_integration_assert( 21 * 1024 * 1024 === filesize( $stored_file ) && hash_final( $hash ) === hash_file( 'sha256', $stored_file ), 'Large import did not preserve exact bytes.' );
    foreach ( $staging as $file ) { wpnb39_integration_assert( ! is_file( $file ), 'Large import retained staging.' ); }
    wpnb39_integration_assert( 0 === $unexpected_calls, 'The fixture observed unexpected HTTP attempts.' );
    $mode = 'success';
    echo 'PASS: URL media import native authority, lifecycle, cleanup and HTTPS transfer (' . $GLOBALS['wpnb39_integration_checks'] . " assertions).\n";
} finally {
    $mode = 'success';
    remove_action( 'add_attachment', $observe_insert );
    remove_filter( 'wp_generate_attachment_metadata', $metadata_fault, 10 );
    remove_filter( 'wp_get_attachment_url', $output_fault );
    remove_filter( 'wp_delete_file', $cleanup_fault );
    remove_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $audit_fault );
    remove_filter( 'gettext', $translation_fault, 10 );
    remove_filter( 'map_meta_cap', $permission_fault, 11 );
    remove_filter( 'upload_size_limit', $large_limit );
    remove_filter( 'pre_http_request', $mock, 10 );
    remove_filter( 'wp_handle_sideload_prefilter', $reject_sideload );
    remove_filter( 'wp_handle_upload', $observe_upload, 10 );
    remove_filter( 'wp_insert_post_empty_content', $reject_insert, 10 );
    remove_filter( 'upload_size_limit', $small_limit );
    remove_filter( 'map_meta_cap', $deny_upload, 10 );
    wp_set_current_user( $original_user );
    foreach ( array_unique( $created ) as $id ) { wp_delete_attachment( $id, true ); }
    if ( $parent && ! is_wp_error( $parent ) ) { wp_delete_post( $parent, true ); }
    foreach ( $staging as $file ) { if ( is_file( $file ) ) { wp_delete_file( $file ); } }
    update_option( Settings::OPTION_NAME, $original, false );
    update_option( Mutation_Log::OPTION_NAME, $original_log, false );
    restore_error_handler();
}
