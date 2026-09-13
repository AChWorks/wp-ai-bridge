<?php
/** Multisite/network-active source-editing coverage for Issue #46. */

use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue46_network_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings          = new Settings();
$original_settings = get_option( Settings::OPTION_NAME, array() );
$original_user     = get_current_user_id();
$plugin            = 'wpnb-network-source/wpnb-network-source.php';
$plugin_file       = WP_PLUGIN_DIR . '/' . $plugin;
$original          = file_get_contents( $plugin_file );
$network_lock      = null;

try {
	wpnb_issue46_network_assert( is_multisite(), 'Network-active smoke must run on multisite.' );
	wpnb_issue46_network_assert( is_super_admin(), 'Network-active smoke must begin as Super Admin.' );
	wpnb_issue46_network_assert( is_plugin_active_for_network( $plugin ), 'Fixture plugin is not network active.' );

	$enabled                                    = $settings->defaults();
	$enabled[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	$enabled[ Settings::GROUP_SOURCE_EDITING ]  = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );

	$preview = wp_get_ability( 'wp-native-builder/source-file-preview' );
	$apply   = wp_get_ability( 'wp-native-builder/source-file-apply' );
	$read    = wp_get_ability( 'wp-native-builder/source-files-read' );
	wpnb_issue46_network_assert( $preview instanceof WP_Ability && $apply instanceof WP_Ability && $read instanceof WP_Ability, 'Source-editing Abilities missing on multisite.' );

	$target    = array(
		'kind'      => 'plugin',
		'extension' => $plugin,
		'file'      => 'wpnb-network-source.php',
	);
	$candidate = str_replace( "'network-original'", "'network-valid'", $original );
	$bound     = $preview->execute( array_merge( $target, array( 'candidate' => $candidate ) ) );
	wpnb_issue46_network_assert( ! is_wp_error( $bound ), 'Network-active valid preview failed.' );
	wpnb_issue46_network_assert( true === $bound['runtime_validation_required'], 'Network-active PHP did not require runtime validation.' );
	$applied = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $candidate,
				'preimage_sha256'  => $bound['preimage_sha256'],
				'candidate_sha256' => $bound['candidate_sha256'],
				'candidate_id'     => $bound['candidate_id'],
			)
		)
	);
	wpnb_issue46_network_assert( ! is_wp_error( $applied ) && 'success' === $applied['outcome'], 'Valid network-active source edit failed.' );
	wpnb_issue46_network_assert( $candidate === file_get_contents( $plugin_file ), 'Valid network-active edit did not persist exact bytes.' );

	$fatal       = "<?php\n/* Plugin Name: WPNB Network Source */\nthrow new RuntimeException('wpnb issue46 network fatal');\n";
	$bound_fatal = $preview->execute( array_merge( $target, array( 'candidate' => $fatal ) ) );
	wpnb_issue46_network_assert( ! is_wp_error( $bound_fatal ), 'Parse-valid network fatal candidate failed preview.' );
	$before_fatal = file_get_contents( $plugin_file );
	$failed       = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $fatal,
				'preimage_sha256'  => $bound_fatal['preimage_sha256'],
				'candidate_sha256' => $bound_fatal['candidate_sha256'],
				'candidate_id'     => $bound_fatal['candidate_id'],
			)
		)
	);
	wpnb_issue46_network_assert( is_wp_error( $failed ) && 'source_runtime_validation_failed' === $failed->get_error_code(), 'Network-active fatal-before-scraper path was not rejected.' );
	wpnb_issue46_network_assert( $before_fatal === file_get_contents( $plugin_file ), 'Network-active fatal validation did not restore exact preimage.' );

	// The shared file itself is the cooperative lock boundary across every site in the network.
	$site_ids          = get_sites(
		array(
			'fields' => 'ids',
			'number' => 10,
		)
	);
	$secondary_blog_id = 0;
	foreach ( $site_ids as $site_id ) {
		if ( (int) $site_id !== get_current_blog_id() ) {
			$secondary_blog_id = (int) $site_id;
			break;
		}
	}
	wpnb_issue46_network_assert( $secondary_blog_id > 0, 'Secondary multisite fixture is missing.' );
	$network_lock = new SplFileObject( $plugin_file, 'rb' );
	wpnb_issue46_network_assert( $network_lock->flock( LOCK_EX | LOCK_NB ), 'Could not acquire external shared-file lock fixture.' );
	switch_to_blog( $secondary_blog_id );
	$secondary_original_settings = get_option( Settings::OPTION_NAME, array() );
	update_option( Settings::OPTION_NAME, $enabled, false );
	$secondary_current   = file_get_contents( $plugin_file );
	$secondary_candidate = str_replace( "'network-valid'", "'secondary-attempt'", $secondary_current );
	$secondary_bound     = $preview->execute( array_merge( $target, array( 'candidate' => $secondary_candidate ) ) );
	wpnb_issue46_network_assert( ! is_wp_error( $secondary_bound ), 'Secondary-site preview failed before shared-file lock test.' );
	$secondary_locked = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $secondary_candidate,
				'preimage_sha256'  => $secondary_bound['preimage_sha256'],
				'candidate_sha256' => $secondary_bound['candidate_sha256'],
				'candidate_id'     => $secondary_bound['candidate_id'],
			)
		)
	);
	wpnb_issue46_network_assert( is_wp_error( $secondary_locked ) && 'source_edit_locked' === $secondary_locked->get_error_code(), 'A second site bypassed the shared-file advisory lock.' );
	wpnb_issue46_network_assert( $secondary_current === file_get_contents( $plugin_file ), 'Cross-site lock denial changed shared source bytes.' );
	update_option( Settings::OPTION_NAME, $secondary_original_settings, false );
	restore_current_blog();
	$network_lock->flock( LOCK_UN );
	$network_lock = null;

	$pending = array(
		'version'          => 1,
		'token'            => wp_generate_uuid4(),
		'kind'             => 'plugin',
		'extension'        => $plugin,
		'file'             => 'wpnb-network-source.php',
		'preimage_sha256'  => hash( 'sha256', $before_fatal ),
		'candidate_sha256' => hash( 'sha256', $before_fatal ),
		'preimage'         => $before_fatal,
		'created_gmt'      => gmdate( 'c' ),
	);
	wpnb_issue46_network_assert( add_site_option( 'wp_native_builder_bridge_source_recovery', $pending ), 'Could not create network-scoped recovery fixture.' );
	switch_to_blog( $secondary_blog_id );
	$secondary_original_settings = get_option( Settings::OPTION_NAME, array() );
	update_option( Settings::OPTION_NAME, $enabled, false );
	$secondary_recovery_block = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $secondary_candidate,
				'preimage_sha256'  => $secondary_bound['preimage_sha256'],
				'candidate_sha256' => $secondary_bound['candidate_sha256'],
				'candidate_id'     => $secondary_bound['candidate_id'],
			)
		)
	);
	wpnb_issue46_network_assert( is_wp_error( $secondary_recovery_block ) && 'source_recovery_required' === $secondary_recovery_block->get_error_code(), 'A second site bypassed network-wide pending recovery ownership.' );
	update_option( Settings::OPTION_NAME, $secondary_original_settings, false );
	restore_current_blog();
	delete_site_option( 'wp_native_builder_bridge_source_recovery' );

	// A site administrator who is not a Super Admin must not inherit network file-editor authority.
	$login = 'wpnb_issue46_site_admin';
	$user  = get_user_by( 'login', $login );
	if ( ! $user ) {
		$user_id = wp_create_user( $login, wp_generate_password( 32, true, true ), 'wpnb-issue46-site-admin@example.invalid' );
		wpnb_issue46_network_assert( ! is_wp_error( $user_id ), 'Could not create multisite non-Super-Admin fixture.' );
		$user = get_userdata( $user_id );
		$user->set_role( 'administrator' );
	}
	wp_set_current_user( $user->ID );
	wpnb_issue46_network_assert( ! is_super_admin(), 'Non-Super-Admin fixture unexpectedly became Super Admin.' );
	$denied = $read->execute(
		array(
			'action'    => 'list',
			'kind'      => 'plugin',
			'extension' => $plugin,
		)
	);
	wpnb_issue46_network_assert( is_wp_error( $denied ), 'Multisite site administrator bypassed native source-editor authority.' );
	wp_set_current_user( $original_user );

	echo "PASS: Issue #46 multisite Super Admin and network-active runtime/recovery behavior.\n";
} finally {
	if ( $network_lock instanceof SplFileObject ) {
		$network_lock->flock( LOCK_UN );
	}
	wp_set_current_user( $original_user );
	file_put_contents( $plugin_file, $original );
	wp_opcache_invalidate( $plugin_file, true );
	update_option( Settings::OPTION_NAME, $original_settings, false );
	delete_site_option( 'wp_native_builder_bridge_source_recovery' );
}
