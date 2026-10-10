<?php
/**
 * Real Bridge deactivation/uninstall lifecycle and multisite recovery fixtures.
 *
 * @package WP_AI_Bridge
 */

$mode     = getenv( 'WPAI108_LIFECYCLE_MODE' );
$network  = '1' === getenv( 'WPAI108_LIFECYCLE_NETWORK' );
$ids      = array(
	'staged'  => str_repeat( 'c', 48 ),
	'expired' => str_repeat( 'd', 48 ),
	'claimed' => str_repeat( 'e', 48 ),
	'orphan'  => str_repeat( 'f', 48 ),
);
$site_ids = $network && is_multisite() ? get_sites(
	array(
		'fields' => 'ids',
		'number' => 0,
	)
) : array( get_current_blog_id() );
if ( ! $site_ids || ! in_array( $mode, array( 'setup', 'verify', 'cleanup' ), true ) ) {
	throw new RuntimeException( 'Invalid isolated private package lifecycle fixture.' );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once __DIR__ . '/../../src/Support/class-private-package-lifecycle.php';

foreach ( $site_ids as $site_id ) {
	$switched = get_current_blog_id() !== (int) $site_id;
	if ( $switched ) {
		switch_to_blog( (int) $site_id );
	}
	try {
		$temp      = rtrim( (string) realpath( sys_get_temp_dir() ), '/\\' );
		$web       = (string) realpath( ABSPATH );
		$directory = $temp . '/wpai-private-zip-' . substr( hash( 'sha256', $web . '|' . get_current_blog_id() ), 0, 24 );
		if ( 'setup' === $mode ) {
			$settings = new \WP_AI_Bridge\Support\Settings();
			$grants   = $settings->defaults();
			$grants[ \WP_AI_Bridge\Support\Settings::GROUP_CODE_EXTENSIONS ] = 1;
			update_option( \WP_AI_Bridge\Support\Settings::OPTION_NAME, $grants, false );
			if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
				throw new RuntimeException( 'Could not create fixture private staging directory.' );
			}
			chmod( $directory, 0700 );
			foreach ( $ids as $name => $id ) {
				$path = $directory . '/' . $id . '.zip';
				$zip  = new ZipArchive();
				if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
					throw new RuntimeException( 'Could not create private lifecycle ZIP fixture.' );
				}
				$zip->addFromString( 'wpai108-lifecycle/main.php', "<?php\n/*\nPlugin Name: Private Lifecycle Test\n*/\n" );
				$zip->close();
				chmod( $path, 0600 );
				if ( 'orphan' === $name ) {
					continue;
				}
				$meta = array(
					'id'              => $id,
					'kind'            => 'plugin',
					'user_id'         => get_current_user_id(),
					'blog_id'         => get_current_blog_id(),
					'client_id'       => \WP_AI_Bridge\Auth\OAuth_Server::CHATGPT_CLIENT_ID,
					'client_revision' => 0,
					'filename'        => 'private-fixture.zip',
					'sha256'          => hash_file( 'sha256', $path ),
					'bytes'           => filesize( $path ),
					'root'            => 'wpai108-lifecycle',
					'created'         => time() - 100,
					'expires'         => 'expired' === $name ? time() - 10 : time() + 3600,
					'status'          => 'claimed' === $name ? 'installing' : 'staged',
				);
				if ( ! add_option( \WP_AI_Bridge\Support\Private_Package_Lifecycle::META_PREFIX . $id, $meta, '', false ) ) {
					throw new RuntimeException( 'Existing lifecycle fixture metadata must be cleaned before setup.' );
				}
				if ( 'claimed' === $name && ! add_option( \WP_AI_Bridge\Support\Private_Package_Lifecycle::CLAIM_PREFIX . $id, time(), '', false ) ) {
					throw new RuntimeException( 'Could not create once-only claim fixture.' );
				}
			}
			if ( ! wp_next_scheduled( \WP_AI_Bridge\Support\Private_Package_Lifecycle::CRON_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', \WP_AI_Bridge\Support\Private_Package_Lifecycle::CRON_HOOK );
			}
			if ( ! wp_next_scheduled( \WP_AI_Bridge\Support\Private_Package_Lifecycle::CRON_HOOK ) ) {
				throw new RuntimeException( 'Private ZIP cleanup cron fixture missing.' );
			}
		} elseif ( 'verify' === $mode ) {
			foreach ( $ids as $name => $id ) {
				if ( is_file( $directory . '/' . $id . '.zip' ) || is_link( $directory . '/' . $id . '.zip' ) ) {
					throw new RuntimeException( 'Private ZIP survived lifecycle cleanup: ' . $name );
				}
				if ( 'claimed' === $name ) {
					$meta = get_option( \WP_AI_Bridge\Support\Private_Package_Lifecycle::META_PREFIX . $id, false );
					if ( ! is_array( $meta ) || 'outcome_unknown' !== ( $meta['status'] ?? '' ) ||
						0 !== ( $meta['bytes'] ?? -1 ) || '' !== ( $meta['filename'] ?? '-' ) ||
						false === get_option( \WP_AI_Bridge\Support\Private_Package_Lifecycle::CLAIM_PREFIX . $id, false ) ) {
						throw new RuntimeException( 'Claimed install lost its exact non-executable recovery identity.' );
					}
				} elseif ( false !== get_option( \WP_AI_Bridge\Support\Private_Package_Lifecycle::META_PREFIX . $id, false ) ||
					false !== get_option( \WP_AI_Bridge\Support\Private_Package_Lifecycle::CLAIM_PREFIX . $id, false ) ) {
					throw new RuntimeException( 'Disposable package metadata or claim survived cleanup.' );
				}
			}
			if ( is_dir( $directory ) || wp_next_scheduled( \WP_AI_Bridge\Support\Private_Package_Lifecycle::CRON_HOOK ) ) {
				throw new RuntimeException( 'Private staging directory/cron survived plugin lifecycle.' );
			}
		} elseif ( 'cleanup' === $mode ) {
			foreach ( $ids as $id ) {
				delete_option( \WP_AI_Bridge\Support\Private_Package_Lifecycle::META_PREFIX . $id );
				delete_option( \WP_AI_Bridge\Support\Private_Package_Lifecycle::CLAIM_PREFIX . $id );
			}
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}
echo 'PASS: Issue #108 real ' . $mode . ' private ZIP lifecycle across ' . count( $site_ids ) . " site(s).\n";
