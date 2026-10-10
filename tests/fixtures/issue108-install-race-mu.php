<?php
/**
 * CI-only, cookie/nonce-protected two-worker native Core install contention.
 * This file is copied to isolated WordPress mu-plugins only during CI.
 *
 * @package WP_AI_Bridge
 */

add_action(
	'admin_post_wpai108_install_race',
	static function () {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'install_plugins' ) || ! is_ssl() ) {
			wp_send_json( array( 'error' => 'unauthorized' ), 403 );
		}
		check_admin_referer( \WP_AI_Bridge\Abilities\Private_Package_Abilities::NONCE_ACTION );
		$mode      = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$root      = 'wpai108-race-plugin';
		$marker    = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpai108-race-core-entered';
		$ids       = array(
			'a' => str_repeat( 'a', 48 ),
			'b' => str_repeat( 'b', 48 ),
		);
		$client    = \WP_AI_Bridge\Auth\OAuth_Server::CHATGPT_CLIENT_ID;
		$store     = new \WP_AI_Bridge\Support\Private_Package_Store( new \WP_AI_Bridge\Support\Permissions( new \WP_AI_Bridge\Support\Settings() ) );
		$directory = ( new ReflectionMethod( $store, 'directory' ) )->invoke( $store );
		if ( is_wp_error( $directory ) ) {
			wp_send_json( array( 'error' => 'directory_unavailable' ), 500 );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( 'setup' === $mode ) {
			if ( is_dir( WP_PLUGIN_DIR . '/' . $root ) ) {
				wp_send_json( array( 'error' => 'fixture_installed' ), 409 );
			}
			$settings = new \WP_AI_Bridge\Support\Settings();
			$grants   = $settings->defaults();
			$grants[ \WP_AI_Bridge\Support\Settings::GROUP_CODE_EXTENSIONS ]   = 1;
			$grants[ \WP_AI_Bridge\Support\Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
			update_option( \WP_AI_Bridge\Support\Settings::OPTION_NAME, $grants, false );
			foreach ( $ids as $worker => $id ) {
				$path = $directory . '/' . $id . '.zip';
				if ( false !== get_option( \WP_AI_Bridge\Support\Private_Package_Store::OPTION_PREFIX . $id, false ) ) {
					wp_send_json( array( 'error' => 'fixture_exists' ), 409 );
				}
				$archive = new ZipArchive();
				if ( true !== $archive->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
					wp_send_json( array( 'error' => 'zip_failure' ), 500 );
				}
				$archive->addEmptyDir( $root );
				$archive->addFromString( $root . '/main.php', "<?php\n/*\nPlugin Name: WP AI Bridge Race Test\nVersion: 1.0\n*/\n" );
				$archive->addFromString( $root . '/' . $worker . '.marker', $worker );
				$archive->close();
				chmod( $path, 0600 );
				$meta = array(
					'id'              => $id,
					'kind'            => 'plugin',
					'user_id'         => get_current_user_id(),
					'blog_id'         => get_current_blog_id(),
					'client_id'       => $client,
					'client_revision' => 0,
					'filename'        => '',
					'sha256'          => hash_file( 'sha256', $path ),
					'bytes'           => filesize( $path ),
					'root'            => $root,
					'created'         => time(),
					'expires'         => time() + 180,
					'status'          => 'staged',
				);
				if ( ! add_option( \WP_AI_Bridge\Support\Private_Package_Store::OPTION_PREFIX . $id, $meta, '', false ) ) {
					wp_send_json( array( 'error' => 'metadata_failure' ), 500 );
				}
			}
			wp_send_json( array( 'setup' => true ) );
		}

		if ( in_array( $mode, array( 'install-a', 'install-a-disconnect' ), true ) ) {
			add_filter(
				'upgrader_pre_install',
				static function ( $result ) use ( $marker, $mode ) {
					global $wpdb;
					$db_session = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
					// A separate worker terminates this *actual* session while
					// the original PHP worker continues inside native Core.
					file_put_contents( $marker, 'DBID:' . $db_session );
					clearstatcache( true, $marker );
					sleep( 'install-a-disconnect' === $mode ? 22 : 8 );
					return $result;
				},
				10,
				1
			);
		}
		if ( in_array( $mode, array( 'install-a', 'install-a-disconnect', 'install-b' ), true ) ) {
			$id     = $ids[ 'install-b' !== $mode ? 'a' : 'b' ];
			$meta   = get_option( \WP_AI_Bridge\Support\Private_Package_Store::OPTION_PREFIX . $id, false );
			$result = is_array( $meta ) ? $store->install( $id, $meta['sha256'], 'plugin', $client ) : new WP_Error( 'fixture_missing' );
			$code   = is_wp_error( $result ) ? $result->get_error_code() : '';
			wp_send_json(
				array(
					'ok'           => ! is_wp_error( $result ) && ! empty( $result['installed'] ),
					'code'         => $code,
					'unclaimed'    => false === get_option( \WP_AI_Bridge\Support\Private_Package_Store::CLAIM_PREFIX . $id, false ),
					'still_staged' => is_file( $directory . '/' . $id . '.zip' ),
				)
			);
		}
		if ( 'public-while-locked' === $mode ) {
			$extensions = new \WP_AI_Bridge\Abilities\Extension_Abilities( new \WP_AI_Bridge\Support\Permissions( new \WP_AI_Bridge\Support\Settings() ), new \WP_AI_Bridge\Support\Mutation_Log() );
			$result     = $extensions->mutate(
				array(
					'kind'   => 'plugin',
					'action' => 'install',
					'slug'   => 'wpai108-race-fake',
				)
			);
			wp_send_json( array( 'code' => is_wp_error( $result ) ? $result->get_error_code() : 'unexpected_success' ) );
		}
		if ( 'verify' === $mode ) {
			$installed_root = WP_PLUGIN_DIR . '/' . $root;
			$files          = is_dir( $installed_root ) ? scandir( $installed_root ) : array();
			sort( $files );
			wp_send_json(
				array(
					'exact_tree'  => array( '.', '..', 'a.marker', 'main.php' ) === $files &&
						'a' === (string) @file_get_contents( $installed_root . '/a.marker' ) &&
						! is_file( $installed_root . '/b.marker' ),
					'b_unclaimed' => false === get_option( \WP_AI_Bridge\Support\Private_Package_Store::CLAIM_PREFIX . $ids['b'], false ),
				)
			);
		}
		if ( 'cleanup' === $mode ) {
			$plugin = $root . '/main.php';
			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				delete_plugins( array( $plugin ) );
			}
			foreach ( $ids as $id ) {
				$path = $directory . '/' . $id . '.zip';
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
				delete_option( \WP_AI_Bridge\Support\Private_Package_Store::OPTION_PREFIX . $id );
				delete_option( \WP_AI_Bridge\Support\Private_Package_Store::CLAIM_PREFIX . $id );
			}
			if ( is_file( $marker ) ) {
				wp_delete_file( $marker );
			}
			if ( is_dir( WP_PLUGIN_DIR . '/' . $root ) || is_file( $marker ) ) {
				wp_send_json( array( 'error' => 'cleanup_tree_or_marker_remains' ), 500 );
			}
			foreach ( $ids as $id ) {
				if ( is_file( $directory . '/' . $id . '.zip' ) ||
					false !== get_option( \WP_AI_Bridge\Support\Private_Package_Store::OPTION_PREFIX . $id, false ) ||
					false !== get_option( \WP_AI_Bridge\Support\Private_Package_Store::CLAIM_PREFIX . $id, false ) ) {
					wp_send_json( array( 'error' => 'cleanup_artifact_remains' ), 500 );
				}
			}
			wp_send_json( array( 'cleaned' => true ) );
		}
		wp_send_json( array( 'error' => 'invalid_mode' ), 400 );
	}
);
