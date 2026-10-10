<?php
/**
 * Issue #135: removed manual ZIP staging must not return on fresh install.
 *
 * @package WP_AI_Bridge
 */

$removed = array(
    'wp-ai-bridge/private-packages-read',
    'wp-ai-bridge/private-package-install',
);
foreach ( $removed as $ability ) {
    if ( wp_get_ability( $ability ) ) {
        throw new RuntimeException( 'Removed ZIP staging Ability is still registered.' );
    }
}
if ( false !== has_action( 'admin_post_wpai_private_zip_upload' ) ||
    false !== has_action( 'wpai_private_zip_cleanup' ) ||
    false !== wp_next_scheduled( 'wpai_private_zip_cleanup' ) ) {
    throw new RuntimeException( 'Removed ZIP staging hooks unexpectedly exist on fresh install.' );
}
if ( false !== get_option( 'wpai_private_zip_storage_v1', false ) ) {
    throw new RuntimeException( 'Fresh installation initialized removed ZIP staging state.' );
}
foreach ( array( 'wp-ai-bridge/extension-lifecycle', 'wp-ai-bridge/media-upload', 'wp-ai-bridge/media-import-url' ) as $ability ) {
    if ( ! ( wp_get_ability( $ability ) instanceof WP_Ability ) ) {
        throw new RuntimeException( 'Removal damaged an approved extension/media Ability.' );
    }
}
echo "PASS: Removed ZIP staging absent; native extension and media Abilities registered.\n";
