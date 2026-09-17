<?php
/**
 * Runtime environment inspection.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Detects supported WordPress and MCP Adapter runtime capabilities.
 */
final class Environment {
	const MINIMUM_WORDPRESS_VERSION = '6.9';

	/**
	 * Gets the current WordPress version.
	 *
	 * @return string WordPress version, or an empty string when unavailable.
	 */
	public function wordpress_version() {
		global $wp_version;

		if ( is_string( $wp_version ) && '' !== $wp_version ) {
			return $wp_version;
		}

		if ( function_exists( 'get_bloginfo' ) ) {
			return (string) get_bloginfo( 'version' );
		}

		return '';
	}

	/**
	 * Checks the minimum supported WordPress version.
	 *
	 * @return bool
	 */
	public function wordpress_supported() {
		$version = $this->wordpress_version();
		return '' !== $version && version_compare( $version, self::MINIMUM_WORDPRESS_VERSION, '>=' );
	}

	/**
	 * Checks whether the required Abilities API surface is available.
	 *
	 * @return bool
	 */
	public function abilities_api_available() {
		return $this->wordpress_supported()
			&& class_exists( 'WP_Ability' )
			&& function_exists( 'wp_register_ability' )
			&& function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Checks whether the official MCP Adapter is active.
	 *
	 * @return bool
	 */
	public function mcp_adapter_available() {
		return class_exists( 'WP\\MCP\\Core\\McpAdapter' );
	}

	/**
	 * Gets the MCP Adapter version when available.
	 *
	 * @return string MCP Adapter version, or an empty string when unavailable.
	 */
	public function mcp_adapter_version() {
		if ( defined( 'WP_MCP_VERSION' ) ) {
			return (string) WP_MCP_VERSION;
		}

		return '';
	}

	/**
	 * Gets the official Adapter default-server endpoint.
	 *
	 * @return string Endpoint URL, or an empty string when REST helpers are unavailable.
	 */
	public function default_mcp_endpoint() {
		if ( ! function_exists( 'rest_url' ) ) {
			return '';
		}

		return rest_url( 'mcp/mcp-adapter-default-server' );
	}

	/**
	 * Returns the non-secret runtime dependency summary.
	 *
	 * @return array<string,mixed> Environment summary.
	 */
	public function summary() {
		return array(
			'wordpress_version' => $this->wordpress_version(),
			'abilities_api'     => $this->abilities_api_available(),
			'mcp_adapter'       => array(
				'available' => $this->mcp_adapter_available(),
				'version'   => $this->mcp_adapter_version(),
			),
		);
	}
}
