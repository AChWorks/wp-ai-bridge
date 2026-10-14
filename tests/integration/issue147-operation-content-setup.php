<?php
/**
 * #147 WordPress invocation A: native preexisting limit and site operator
 * change. The next WP-CLI invocation reloads the dynamic Ability schemas as
 * a real authenticated HTTP request would after an administrator update.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Source_Editing_Abilities;
use WP_AI_Bridge\Support\Operation_Content_Limits;
use WP_AI_Bridge\Support\Settings;

$settings      = new Settings();
$policy        = new Operation_Content_Limits();
$state_name    = 'wpai147_operation_content_fixture_state';
$plugin_id     = 'wpai147-content-capacity-fixture/wpai147-content-capacity-fixture.php';
$directory     = WP_PLUGIN_DIR . '/wpai147-content-capacity-fixture';
$entry         = $directory . '/wpai147-content-capacity-fixture.php';
$helper        = $directory . '/large-helper.php';
$receipt_name  = Source_Editing_Abilities::RECOVERY_OPTION;
$original_grant = get_option( Settings::OPTION_NAME, null );
$original_limit = get_option( Operation_Content_Limits::OPTION_NAME, null );

if ( file_exists( $directory ) || false !== get_option( $state_name, false ) || false !== get_option( $receipt_name, false ) ) {
	throw new RuntimeException( '#147 fixture namespace or unrelated source-recovery receipt already exists.' );
}
if ( ! wp_mkdir_p( $directory ) ) {
	throw new RuntimeException( '#147 cannot create disposable inactive plugin fixture.' );
}
file_put_contents( $entry, "<?php\n/*\nPlugin Name: WPAI 147 Content Capacity Fixture\n*/\n" );
file_put_contents( $helper, "<?php\n/*\n" . str_repeat( 'x', 2320000 ) . "\n*/\n" );
wp_clean_plugins_cache( true );
$grants                                     = $settings->defaults();
$grants[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
$grants[ Settings::GROUP_SOURCE_EDITING ]  = 1;
update_option( Settings::OPTION_NAME, $grants, false );
$read = wp_get_ability( 'wp-ai-bridge/source-files-read' );
if ( ! $read instanceof WP_Ability ) {
	throw new RuntimeException( '#147 source reader not registered in real WordPress.' );
}
$target = array( 'kind' => 'plugin', 'extension' => $plugin_id, 'file' => 'large-helper.php', 'action' => 'read' );
$default_read = $read->execute( $target );
if ( ! is_wp_error( $default_read ) || 'source_file_too_large' !== $default_read->get_error_code() ) {
	throw new RuntimeException( '#147 historical 2 MiB source safety default did not reject oversized plugin fixture.' );
}
update_option( Operation_Content_Limits::OPTION_NAME, $policy->sanitize( array( Operation_Content_Limits::SOURCE_MIB => 4 ) ), false );
add_option(
	$state_name,
	array(
		'original_grant' => $original_grant,
		'original_limit' => $original_limit,
		'file'           => 'large-helper.php',
	),
	'',
	false
);
echo "PASS: #147 real WordPress 2MiB native denial, raised site-admin operator policy; ready to test fresh Ability registry.\n";
