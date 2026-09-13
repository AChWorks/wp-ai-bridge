<?php
/** WordPress deployment-policy denial coverage for Issue #46. */
use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue46_policy_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, array() );
try {
	$enabled = $settings->defaults();
	$enabled[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	$enabled[ Settings::GROUP_SOURCE_EDITING ]   = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );
	$read = wp_get_ability( 'wp-native-builder/source-files-read' );
	wpnb_issue46_policy_assert( $read instanceof WP_Ability, 'Source reading Ability missing.' );
	$result = $read->execute( array( 'action' => 'list', 'kind' => 'plugin' ) );
	wpnb_issue46_policy_assert( is_wp_error( $result ), 'Deployment policy constant did not deny source editing.' );
	echo "PASS: Issue #46 deployment policy denial.\n";
} finally {
	update_option( Settings::OPTION_NAME, $original, false );
}
