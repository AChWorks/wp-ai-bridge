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

	/**
	 * Registers native custom-host update discovery.
	 *
	 * @return void
	 */
	public function boot() {
		add_filter( 'update_plugins_github.com', array( $this, 'filter_update' ), 10, 4 );
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

		$response = wp_safe_remote_get(
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

		set_site_transient( self::CACHE_KEY, $valid, 6 * HOUR_IN_SECONDS );
		return $valid;
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
