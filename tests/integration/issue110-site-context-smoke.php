<?php
/** Native WordPress and official MCP Adapter large-site context regression. */

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Settings;

function wpai110_live_assert( $passed, $message ) {
	if ( ! $passed ) {
		throw new RuntimeException( $message );
	}
}

$settings    = new Settings();
$original    = get_option( Settings::OPTION_NAME, $settings->defaults() );
$original_id = get_current_user_id();
$viewer_id   = 0;
$added_files = array();
try {
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	$site    = wp_get_ability( 'wp-ai-bridge/site-context' );
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	wpai110_live_assert( $site instanceof WP_Ability && $adapter instanceof WP_Ability, 'Native site-context or official Adapter is unavailable.' );
	$schema = wp_get_ability( 'mcp-adapter/get-ability-info' )->execute( array( 'ability_name' => 'wp-ai-bridge/site-context' ) );
	wpai110_live_assert( ! is_wp_error( $schema ) && isset( $site->get_input_schema()['properties']['section'] ), 'Official Adapter did not expose the section selector.' );

	// Fixture files are installed only in this temporary Docker test site and never activated.
	for ( $i = 0; $i < 90; ++$i ) {
		$dir  = WP_PLUGIN_DIR . '/wpai110-test-' . sprintf( '%03d', $i );
		$file = $dir . '/entry.php';
		wpai110_live_assert( wp_mkdir_p( $dir ), 'Could not create temporary plugin metadata fixture.' );
		$header = "<?php\n/*\nPlugin Name: Issue 110 temporary " . $i . ' ' . str_repeat( 'Test label ', 45 ) . "\nVersion: 1.0\n*/\n";
		wpai110_live_assert( false !== file_put_contents( $file, $header ), 'Could not write temporary plugin metadata fixture.' );
		$added_files[] = $file;
	}
	wp_clean_plugins_cache( false );

	$default = $site->execute( array() );
	wpai110_live_assert( ! is_wp_error( $default ) && Bounded_Payload::fits( $default ), 'Oversized native legacy site-context escaped transport bounds.' );
	wpai110_live_assert( isset( $default['projection']['omitted_sections'] ) && isset( $default['current_user']['capabilities']['install_plugins'] ), 'Oversized default lacked explicit omitted sections or direct current-principal flags.' );
	wpai110_live_assert( current_user_can( 'install_plugins' ) === $default['current_user']['capabilities']['install_plugins'], 'Reported installation authority diverged from current WordPress principal.' );

	$native_flags = $site->execute( array( 'section' => 'current_user' ) );
	$wrapped      = $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/site-context',
			'parameters'   => array( 'section' => 'current_user' ),
		)
	);
	wpai110_live_assert( ! is_wp_error( $native_flags ) && ! is_wp_error( $wrapped ) && true === ( $wrapped['success'] ?? false ) && Bounded_Payload::fits( $wrapped ), 'Current-user capabilities were unavailable through official Adapter.' );
	wpai110_live_assert( $native_flags['current_user'] === $wrapped['data']['current_user'], 'Adapter current-user capability identity changed.' );

	$offset = 0;
	$seen   = array();
	for ( $page_no = 0; $page_no < 30; ++$page_no ) {
		$wrapped_page = $adapter->execute(
			array(
				'ability_name' => 'wp-ai-bridge/site-context',
				'parameters'   => array(
					'section' => 'external_abilities',
					'offset'  => $offset,
					'limit'   => 25,
				),
			)
		);
		wpai110_live_assert( ! is_wp_error( $wrapped_page ) && true === ( $wrapped_page['success'] ?? false ) && Bounded_Payload::fits( $wrapped_page ), 'Adapter returned an oversized or rejected native provider page.' );
		$data = $wrapped_page['data'];
		wpai110_live_assert( ! empty( $data['external_abilities'] ) && count( $data['external_abilities'] ) === $data['page']['returned'], 'Provider page did not advance.' );
		foreach ( $data['external_abilities'] as $item ) {
			$seen[] = $item['name'];
			wpai110_live_assert( ! isset( $item['private_configuration'] ), 'Private provider metadata leaked in public projection.' );
		}
		if ( ! $data['page']['has_more'] ) {
			break;
		}
		$next = $data['page']['next_offset'];
		wpai110_live_assert( $next === $offset + count( $data['external_abilities'] ) && $next > $offset, 'Provider continuation skipped/duplicated registry entries.' );
		$offset = $next;
	}
	wpai110_live_assert( count( $seen ) === count( array_unique( $seen ) ) && in_array( 'catalog-fixture/operation-136', $seen, true ), 'Provider pagination never reached fixture beyond the old 50-row ceiling.' );
	wpai110_live_assert( ! in_array( 'catalog-hidden/private', $seen, true ) && ! in_array( 'catalog-hidden/optout', $seen, true ), 'MCP-hidden registry entries escaped the privacy filter.' );

	$plugins = $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/site-context',
			'parameters'   => array(
				'section' => 'plugins',
				'limit'   => 40,
			),
		)
	);
	wpai110_live_assert( ! is_wp_error( $plugins ) && true === ( $plugins['success'] ?? false ) && Bounded_Payload::fits( $plugins ) && $plugins['data']['page']['has_more'], 'Real plugin inventory was not page-bounded.' );

	$viewer_id = wp_insert_user(
		array(
			'user_login' => 'wpai110_viewer_' . wp_generate_password( 8, false, false ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'wpai110-viewer-' . wp_generate_password( 12, false, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	wpai110_live_assert( ! is_wp_error( $viewer_id ) && $viewer_id > 0, 'Could not create isolated read-only principal.' );
	wp_set_current_user( $viewer_id );
	wpai110_live_assert( current_user_can( 'read' ) && ! current_user_can( 'install_plugins' ), 'Subscriber authority fixture is invalid.' );
	$viewer = $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/site-context',
			'parameters'   => array( 'section' => 'current_user' ),
		)
	);
	wpai110_live_assert( ! is_wp_error( $viewer ) && true === ( $viewer['success'] ?? false ) && $viewer_id === $viewer['data']['current_user']['id'] && false === $viewer['data']['current_user']['capabilities']['install_plugins'], 'Cached Administrator authority leaked to the read-only WordPress principal.' );

	$off                              = $settings->defaults();
	$off[ Settings::GROUP_SITE_READ ] = 0;
	update_option( Settings::OPTION_NAME, $off, false );
	$revoked = $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/site-context',
			'parameters'   => array(
				'section' => 'plugins',
				'offset'  => 35,
			),
		)
	);
	wpai110_live_assert( is_wp_error( $revoked ) || true !== ( $revoked['success'] ?? false ), 'A continuation inherited old Site Read authorization.' );
	wp_set_current_user( $original_id );
	echo "PASS: Real WordPress/official Adapter bounded site context, >50 public providers, current principal and permission revocation.\n";
} finally {
	wp_set_current_user( $original_id );
	update_option( Settings::OPTION_NAME, $original, false );
	if ( $viewer_id && ! is_wp_error( $viewer_id ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $viewer_id );
	}
	foreach ( $added_files as $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
		@rmdir( dirname( $file ) );
	}
	wp_clean_plugins_cache( false );
}
