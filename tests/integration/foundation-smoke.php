<?php
/**
 * Run inside a disposable WordPress environment, for example:
 * wp --user=1 eval-file tests/integration/foundation-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress is not loaded.\n" );
	exit( 1 );
}

$failures = array();
$assert   = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( version_compare( get_bloginfo( 'version' ), '6.9', '>=' ), 'WordPress 6.9+ is required.' );
$assert( class_exists( 'WP_Ability' ), 'WP_Ability is unavailable.' );
$assert( function_exists( 'wp_get_ability' ), 'wp_get_ability() is unavailable.' );
$assert( class_exists( 'WP\\MCP\\Core\\McpAdapter' ), 'Official MCP Adapter is unavailable.' );
$assert( class_exists( 'WP_AI_Bridge\\Plugin' ), 'WP AI Bridge is not loaded.' );

$bridge_info = null;
$meta_read   = null;
$meta_update = null;
$meta_delete = null;

if ( function_exists( 'wp_get_ability' ) ) {
	$bridge_info = wp_get_ability( 'wp-ai-bridge/bridge-info' );
	$meta_read   = wp_get_ability( 'wp-ai-bridge/post-meta-read' );
	$meta_update = wp_get_ability( 'wp-ai-bridge/post-meta-update' );
	$meta_delete = wp_get_ability( 'wp-ai-bridge/post-meta-delete' );

	$assert( null !== $bridge_info, 'wp-ai-bridge/bridge-info is not registered.' );
	$assert( null !== $meta_read, 'wp-ai-bridge/post-meta-read is not registered.' );
	$assert( null !== $meta_update, 'wp-ai-bridge/post-meta-update is not registered.' );
	$assert( null !== $meta_delete, 'wp-ai-bridge/post-meta-delete is not registered.' );
}

$settings_class   = 'WP_AI_Bridge\\Support\\Settings';
$permission_class = 'WP_AI_Bridge\\Support\\Permissions';

if ( class_exists( $settings_class ) && class_exists( $permission_class ) ) {
	$original    = get_option( $settings_class::OPTION_NAME, null );
	$settings    = new $settings_class();
	$permissions = new $permission_class( $settings );
	$post_id     = 0;

	try {
		$defaults = $settings->defaults();
		update_option( $settings_class::OPTION_NAME, $defaults, false );

		$assert( true === $permissions->allowed( $settings_class::GROUP_SITE_READ, 'read' ), 'Enabled Site Read did not authorize a user with read capability.' );
		$assert( isset( $defaults[ $settings_class::GROUP_ADVANCED_METADATA ] ), 'Advanced Metadata group is missing from defaults.' );
		$assert( 0 === $defaults[ $settings_class::GROUP_ADVANCED_METADATA ], 'Advanced Metadata must be disabled by default.' );

		$disabled = $defaults;
		$disabled[ $settings_class::GROUP_SITE_READ ] = 0;
		update_option( $settings_class::OPTION_NAME, $disabled, false );
		$assert( false === $permissions->allowed( $settings_class::GROUP_SITE_READ, 'read' ), 'Disabled Site Read still authorized the ability.' );

		register_post_type(
			'wpai_meta_fixture',
			array(
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => array(),
			)
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'wpai_meta_fixture',
				'post_status' => 'draft',
				'post_title'  => 'Advanced Metadata Integration Fixture',
			),
			true
		);
		$assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Private non-REST metadata fixture post could not be created.' );

		if ( ! is_wp_error( $post_id ) && $post_id > 0 && $meta_read && $meta_update && $meta_delete ) {
			add_post_meta( $post_id, '_wpai_builder_payload', '<footer>Old</footer>', true );

			$denied = $meta_read->execute(
				array(
					'post_id'        => $post_id,
					'key'            => '_wpai_builder_payload',
					'include_values' => true,
				)
			);
			$assert( is_wp_error( $denied ), 'Advanced Metadata read succeeded while the group was disabled.' );

			$enabled                                      = $defaults;
			$enabled[ $settings_class::GROUP_ADVANCED_METADATA ] = 1;
			update_option( $settings_class::OPTION_NAME, $enabled, false );

			$read = $meta_read->execute(
				array(
					'post_id'        => $post_id,
					'key'            => '_wpai_builder_payload',
					'include_values' => true,
				)
			);
			$assert( ! is_wp_error( $read ), 'Advanced Metadata could not read protected metadata from a private non-REST CPT.' );

			if ( ! is_wp_error( $read ) ) {
				$assert( 'wpai_meta_fixture' === $read['post_type'], 'Advanced Metadata returned the wrong target post type.' );
				$assert( 1 === $read['items'][0]['count'], 'Advanced Metadata returned the wrong metadata row count.' );
				$assert( '<footer>Old</footer>' === json_decode( $read['items'][0]['values'][0]['value_json'], true ), 'Advanced Metadata returned the wrong protected metadata value.' );

				$old_hash = $read['items'][0]['state_hash'];
				$updated  = $meta_update->execute(
					array(
						'post_id'             => $post_id,
						'key'                 => '_wpai_builder_payload',
						'value_json'          => wp_json_encode( '<footer>New</footer>' ),
						'expected_state_hash' => $old_hash,
					)
				);
				$assert( ! is_wp_error( $updated ), 'Advanced Metadata update failed for a private non-REST CPT.' );
				$assert( '<footer>New</footer>' === get_post_meta( $post_id, '_wpai_builder_payload', true ), 'Advanced Metadata update did not persist the expected value.' );

				$stale = $meta_update->execute(
					array(
						'post_id'             => $post_id,
						'key'                 => '_wpai_builder_payload',
						'value_json'          => wp_json_encode( '<footer>Stale</footer>' ),
						'expected_state_hash' => $old_hash,
					)
				);
				$assert( is_wp_error( $stale ), 'Advanced Metadata accepted a stale update state.' );
				$assert( '<footer>New</footer>' === get_post_meta( $post_id, '_wpai_builder_payload', true ), 'Stale Advanced Metadata update changed stored data.' );

				$fresh = $meta_read->execute(
					array(
						'post_id' => $post_id,
						'key'     => '_wpai_builder_payload',
					)
				);

				if ( ! is_wp_error( $fresh ) ) {
					$delete_denied = $meta_delete->execute(
						array(
							'post_id'             => $post_id,
							'key'                 => '_wpai_builder_payload',
							'expected_state_hash' => $fresh['items'][0]['state_hash'],
						)
					);
					$assert( is_wp_error( $delete_denied ), 'Advanced Metadata delete succeeded without Users & Destructive access.' );

					$enabled[ $settings_class::GROUP_USERS_DESTRUCTIVE ] = 1;
					update_option( $settings_class::OPTION_NAME, $enabled, false );
					$deleted = $meta_delete->execute(
						array(
							'post_id'             => $post_id,
							'key'                 => '_wpai_builder_payload',
							'expected_state_hash' => $fresh['items'][0]['state_hash'],
						)
					);
					$assert( ! is_wp_error( $deleted ) && true === $deleted['deleted'], 'Advanced Metadata delete failed after destructive access was enabled.' );
					$assert( '' === get_post_meta( $post_id, '_wpai_builder_payload', true ), 'Advanced Metadata delete left the metadata value behind.' );
				}
			}
		}
	} finally {
		if ( is_int( $post_id ) && $post_id > 0 ) {
			wp_delete_post( $post_id, true );
		}
		if ( post_type_exists( 'wpai_meta_fixture' ) ) {
			unregister_post_type( 'wpai_meta_fixture' );
		}
		if ( null === $original ) {
			delete_option( $settings_class::OPTION_NAME );
		} else {
			update_option( $settings_class::OPTION_NAME, $original, false );
		}
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo 'PASS: foundation integration smoke check.' . PHP_EOL;
