<?php
/**
 * Native WordPress update discovery for published GitHub Releases.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Advertises only this plugin's stable, official GitHub release ZIP to Core.
 *
 * WordPress remains responsible for update consent, scheduling, downloading,
 * replacing the installed plugin and recovery. No background updater or
 * alternative installation engine is registered here.
 */
final class GitHub_Updates {
	const UPDATE_URI  = 'https://github.com/AChWorks/wp-ai-bridge';
	const RELEASE_API = 'https://api.github.com/repos/AChWorks/wp-ai-bridge/releases/latest';
	const CACHE_KEY   = 'wp_ai_bridge_github_stable_release';
	const ASSET_API   = 'https://api.github.com/repos/AChWorks/wp-ai-bridge/releases/assets/';

	/**
	 * Registers native custom-host update discovery.
	 *
	 * @return void
	 */
	public function boot() {
		add_filter( 'update_plugins_github.com', array( $this, 'filter_update' ), 10, 4 );
		// Core returns before its custom-host filter when WordPress.org fails.
		// Present our own bounded update offer through Core's normal transient.
		add_filter( 'site_transient_update_plugins', array( $this, 'filter_site_transient' ) );
		add_filter( 'upgrader_pre_download', array( $this, 'filter_package_download' ), 20, 4 );
		add_action( 'load-update-core.php', array( $this, 'clear_cache_on_force_check' ), 1 );
	}

	/**
	 * Provide canonical Bridge update metadata even without a WordPress.org
	 * response, without altering update offers for unrelated plugins.
	 *
	 * @param mixed $transient Existing WordPress update transient.
	 * @return mixed
	 */
	public function filter_site_transient( $transient ) {
		if ( ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $transient;
		}
		if ( false !== $transient && ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$file    = 'wp-ai-bridge/wp-ai-bridge.php';
		$data    = is_array( $plugins ) ? ( $plugins[ $file ] ?? null ) : null;
		if ( ! is_array( $data )
			|| ! isset( $data['Version'] )
			|| ! is_string( $data['Version'] )
			|| ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $data['Version'] )
		) {
			return $transient;
		}

		$offer = $this->filter_update( false, $data, $file, array() );
		if ( ! is_array( $offer ) ) {
			return $transient;
		}

		$updates = false === $transient ? new \stdClass() : clone $transient;
		if ( isset( $updates->response ) && ! is_array( $updates->response ) ) {
			return $transient;
		}
		if ( isset( $updates->no_update ) && ! is_array( $updates->no_update ) ) {
			return $transient;
		}
		$updates->response    = $updates->response ?? array();
		$updates->no_update   = $updates->no_update ?? array();
		$offer['plugin']      = $file;
		$offer['new_version'] = $offer['version'];
		$offer                = (object) $offer;

		if ( version_compare( $offer->new_version, $data['Version'], '>' ) ) {
			$updates->response[ $file ] = $offer;
			unset( $updates->no_update[ $file ] );
		} else {
			unset( $updates->response[ $file ] );
			$updates->no_update[ $file ] = $offer;
		}

		return $updates;
	}

	/**
	 * Recover one failed native Bridge ZIP download with the official asset API.
	 * Core still owns update authority, replacement and rollback.
	 *
	 * @param false|string|\WP_Error $reply Earlier download result.
	 * @param string                $package Release ZIP URL.
	 * @param \WP_Upgrader          $upgrader Core upgrader (unused).
	 * @param array<string,mixed>   $hook_extra Core plugin update context.
	 * @return false|string|\WP_Error
	 */
	public function filter_package_download( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader );
		if ( false !== $reply
			|| ! is_string( $package )
			|| ! is_array( $hook_extra )
			|| 'wp-ai-bridge/wp-ai-bridge.php' !== ( $hook_extra['plugin'] ?? null )
			|| ( isset( $hook_extra['type'] ) && 'plugin' !== $hook_extra['type'] )
			|| ( isset( $hook_extra['action'] ) && 'update' !== $hook_extra['action'] )
			|| ! preg_match( '~^https://github\.com/AChWorks/wp-ai-bridge/releases/download/v[0-9]+\.[0-9]+\.[0-9]+/wp-ai-bridge\.zip$~D', $package )
		) {
			return $reply;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$direct = download_url( $package, 60 );
		if ( ! is_wp_error( $direct ) ) {
			return $direct;
		}
		return $this->download_official_api_asset( $package, $direct );
	}

	/**
	 * Preserve meaningful failure stages without leaking signed asset URLs.
	 *
	 * @param \WP_Error $direct Failed direct download.
	 * @param string    $stage Official API failure stage.
	 * @return \WP_Error
	 */
	private function download_error( $direct, $stage ) {
		return new \WP_Error(
			'wp_ai_bridge_release_download_failed',
			sprintf(
				/* translators: 1: direct download error code, 2: official GitHub API failure stage. */
				__( 'WP AI Bridge update download failed via GitHub (%1$s) and the official API fallback (%2$s).', 'wp-ai-bridge' ),
				$direct->get_error_code(),
				$stage
			),
			array(
				'direct_error' => $direct->get_error_code(),
				'api_stage'    => $stage,
			)
		);
	}

	/**
	 * Stream a checksum-verified official asset only after direct download fails.
	 *
	 * @param string    $package Exact official release package URL.
	 * @param \WP_Error $direct Failed direct GitHub download.
	 * @return string|\WP_Error Local ZIP file or diagnostic failure.
	 */
	private function download_official_api_asset( $package, $direct ) {
		$response = $this->request_release_metadata();
		if ( is_wp_error( $response ) ) {
			return $this->download_error( $direct, 'metadata_transport:' . $response->get_error_code() );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $this->download_error( $direct, 'metadata_http_' . wp_remote_retrieve_response_code( $response ) );
		}
		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		$valid   = $this->validate_release( $release );
		if ( ! $valid || $package !== $valid['package'] ) {
			return $this->download_error( $direct, 'release_or_package_mismatch' );
		}
		$asset = null;
		foreach ( $release['assets'] as $candidate ) {
			if ( is_array( $candidate )
				&& 'wp-ai-bridge.zip' === ( $candidate['name'] ?? null )
				&& ( $candidate['browser_download_url'] ?? null ) === $package
				&& 'uploaded' === ( $candidate['state'] ?? null )
			) {
				$asset = $candidate;
				break;
			}
		}
		if ( ! is_array( $asset )
			|| ! isset( $asset['id'], $asset['size'], $asset['digest'], $asset['url'] )
			|| ! is_int( $asset['id'] ) || 0 >= $asset['id']
			|| ! is_int( $asset['size'] ) || 0 >= $asset['size']
			|| ! is_string( $asset['digest'] )
			|| ! preg_match( '/^sha256:([a-f0-9]{64})$/D', $asset['digest'], $digest )
		) {
			return $this->download_error( $direct, 'asset_integrity_metadata_missing' );
		}
		$api_url = self::ASSET_API . $asset['id'];
		if ( $api_url !== $asset['url'] ) {
			return $this->download_error( $direct, 'asset_api_url_mismatch' );
		}
		$temp = wp_tempnam( 'wp-ai-bridge.zip' );
		if ( ! is_string( $temp ) || '' === $temp ) {
			return $this->download_error( $direct, 'temporary_file_unavailable' );
		}
		// GitHub may stream 200 or redirect 302 to its asset CDN.
		$result = wp_safe_remote_get(
			$api_url,
			array(
				'timeout'             => 90,
				'redirection'         => 5,
				'stream'              => true,
				'filename'            => $temp,
				'limit_response_size' => $asset['size'] + 1,
				'headers'             => array(
					'Accept'               => 'application/octet-stream',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'WP-AI-Bridge-WordPress-Updater',
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			@unlink( $temp );
			return $this->download_error( $direct, 'asset_transport:' . $result->get_error_code() );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $result ) ) {
			@unlink( $temp );
			return $this->download_error( $direct, 'asset_http_' . wp_remote_retrieve_response_code( $result ) );
		}
		if ( filesize( $temp ) !== $asset['size'] ) {
			@unlink( $temp );
			return $this->download_error( $direct, 'asset_size_mismatch' );
		}
		$actual = hash_file( 'sha256', $temp );
		if ( ! is_string( $actual ) || ! hash_equals( $digest[1], $actual ) ) {
			@unlink( $temp );
			return $this->download_error( $direct, 'asset_sha256_mismatch' );
		}
		return $temp;
	}

	/**
	 * The Core Check Again button must also refresh our separate release cache.
	 *
	 * @return void
	 */
	public function clear_cache_on_force_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only refreshes cached public version metadata for an update administrator.
		if ( ! isset( $_GET['force-check'] ) || ! is_string( $_GET['force-check'] ) || '1' !== sanitize_text_field( wp_unslash( $_GET['force-check'] ) ) ) {
			return;
		}
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Returns Core-compatible metadata for the canonical Bridge plugin only.
	 *
	 * @param array|false   $update      Earlier host-filter result.
	 * @param array         $plugin_data Installed plugin headers.
	 * @param string        $plugin_file Relative installed plugin file.
	 * @param array<string> $locales     Available locales (unused).
	 * @return array|false
	 */
	public function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $locales );

		if ( 'wp-ai-bridge/wp-ai-bridge.php' !== $plugin_file
			|| ! is_array( $plugin_data )
			|| self::UPDATE_URI !== ( $plugin_data['UpdateURI'] ?? null )
			|| ! isset( $plugin_data['Version'] )
		) {
			return $update;
		}

		$release = $this->get_stable_release();
		if ( ! $release ) {
			// This plugin owns its canonical update identity. A previous
			// github.com host-filter callback must not supply an unrelated
			// package when the authenticated release check is unavailable.
			return false;
		}

		return array(
			'id'      => self::UPDATE_URI,
			'slug'    => 'wp-ai-bridge',
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		);
	}

	/**
	 * Fetches the latest published, non-prerelease asset with bounded caching.
	 *
	 * @return array<string,string>|false
	 */
	private function get_stable_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			// Never trust cached package URLs: only the exact canonical GitHub
			// paths derived from a strict version may reach Core's Upgrader.
			$version = $cached['version'] ?? null;
			if ( ! is_string( $version ) || ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version ) ) {
				return false;
			}
			$tag = 'v' . $version;
			return array(
				'version' => $version,
				'url'     => self::UPDATE_URI . '/releases/tag/' . $tag,
				'package' => self::UPDATE_URI . '/releases/download/' . $tag . '/wp-ai-bridge.zip',
			);
		}

		$response = $this->request_release_metadata();

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::CACHE_KEY, array( 'unavailable' => true ), 15 * MINUTE_IN_SECONDS );
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		$valid   = $this->validate_release( $release );
		if ( ! $valid ) {
			set_site_transient( self::CACHE_KEY, array( 'unavailable' => true ), 15 * MINUTE_IN_SECONDS );
			return false;
		}

		set_site_transient( self::CACHE_KEY, $valid, HOUR_IN_SECONDS );
		return $valid;
	}

	/**
	 * Read the public stable-release metadata through bounded WordPress HTTP.
	 *
	 * @return array|\WP_Error HTTP response or error.
	 */
	private function request_release_metadata() {
		return wp_safe_remote_get(
			self::RELEASE_API,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 524288,
				'headers'             => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WP-AI-Bridge-WordPress-Updater',
				),
			)
		);
	}

	/**
	 * Validates stable tag and the one canonical installable release asset.
	 *
	 * Never offer GitHub's automatically generated source archives, arbitrary
	 * asset links, draft releases, or prereleases to the WordPress Upgrader.
	 *
	 * @param mixed $release Decoded GitHub response.
	 * @return array<string,string>|false
	 */
	private function validate_release( $release ) {
		if ( ! is_array( $release )
			|| ! array_key_exists( 'draft', $release )
			|| false !== $release['draft']
			|| ! array_key_exists( 'prerelease', $release )
			|| false !== $release['prerelease']
			|| ! isset( $release['tag_name'], $release['html_url'], $release['assets'] )
			|| ! is_string( $release['tag_name'] )
			|| ! is_array( $release['assets'] )
			|| ! preg_match( '/^v([0-9]+\.[0-9]+\.[0-9]+)$/D', $release['tag_name'], $matches )
		) {
			return false;
		}

		$tag          = $release['tag_name'];
		$expected_url = self::UPDATE_URI . '/releases/tag/' . $tag;
		$expected_zip = self::UPDATE_URI . '/releases/download/' . $tag . '/wp-ai-bridge.zip';
		if ( $expected_url !== $release['html_url'] ) {
			return false;
		}

		foreach ( $release['assets'] as $asset ) {
			if ( is_array( $asset )
				&& 'wp-ai-bridge.zip' === ( $asset['name'] ?? null )
				&& 'uploaded' === ( $asset['state'] ?? null )
				&& isset( $asset['browser_download_url'] )
				&& $expected_zip === $asset['browser_download_url']
				&& isset( $asset['size'] )
				&& is_int( $asset['size'] )
				&& 0 < $asset['size']
			) {
				return array(
					'version' => $matches[1],
					'url'     => $expected_url,
					'package' => $expected_zip,
				);
			}
		}

		return false;
	}
}
