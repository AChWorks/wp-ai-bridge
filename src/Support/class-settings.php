<?php
/**
 * Bridge access-group settings.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Support;

/**
 * Owns the small grouped access-control settings model.
 */
final class Settings {
	const OPTION_NAME  = 'wp_native_builder_bridge_settings';
	const OPTION_GROUP = 'wp_native_builder_bridge';

	const GROUP_SITE_READ         = 'site_read';
	const GROUP_BUILDER_WRITE     = 'builder_write';
	const GROUP_REMOTE_MEDIA      = 'remote_media';
	const GROUP_LIVE_CONTENT      = 'live_content';
	const GROUP_SITE_CONFIG       = 'site_configuration';
	const GROUP_ADVANCED_METADATA = 'advanced_metadata';
	const GROUP_CODE_EXTENSIONS   = 'code_extensions';
	const GROUP_SOURCE_EDITING    = 'source_editing';
	const GROUP_USERS_DESTRUCTIVE = 'users_destructive';

	/**
	 * Registers the bridge option through the WordPress Settings API.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => $this->defaults(),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Returns the supported access groups and their UI metadata.
	 *
	 * @return array<string,array<string,mixed>> Access-group definitions.
	 */
	public function groups() {
		return array(
			self::GROUP_SITE_READ         => array(
				'label'       => __( 'Site Read', 'wp-native-builder-bridge' ),
				'description' => __( 'Inspect site, content, blocks, media, navigation, plugins, themes, and supported integrations.', 'wp-native-builder-bridge' ),
				'default'     => true,
				'warning'     => false,
			),
			self::GROUP_BUILDER_WRITE     => array(
				'label'       => __( 'Builder Write', 'wp-native-builder-bridge' ),
				'description' => __( 'Create and update drafts, content, blocks, media, taxonomies, navigation, and forms.', 'wp-native-builder-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_REMOTE_MEDIA      => array(
				'label'       => __( 'Remote Media', 'wp-native-builder-bridge' ),
				'description' => __( 'Allow downloads from safe HTTP(S) URLs into the Media Library. Builder Write and WordPress upload capabilities are also required.', 'wp-native-builder-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_LIVE_CONTENT      => array(
				'label'       => __( 'Live Content', 'wp-native-builder-bridge' ),
				'description' => __( 'Allow publishing and other live content status changes when WordPress capabilities also permit them.', 'wp-native-builder-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_SITE_CONFIG       => array(
				'label'       => __( 'Site Configuration', 'wp-native-builder-bridge' ),
				'description' => __( 'Allow supported global WordPress, theme, and Astra configuration changes.', 'wp-native-builder-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_ADVANCED_METADATA => array(
				'label'       => __( 'Advanced Metadata', 'wp-native-builder-bridge' ),
				'description' => __( 'Allow authorized MCP clients to inspect and update protected/private post and term metadata for exact WordPress objects the connected user may edit. Options, user meta, Workspace internals, and credential-like keys remain outside this surface.', 'wp-native-builder-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_CODE_EXTENSIONS   => array(
				'label'       => __( 'Code & Extensions', 'wp-native-builder-bridge' ),
				'description' => __( 'Allow supported managed snippets and plugin/theme lifecycle operations. This does not expose arbitrary code execution.', 'wp-native-builder-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_SOURCE_EDITING    => array(
				'label'       => __( 'Source Editing', 'wp-native-builder-bridge' ),
				'description' => __( 'Allow installed plugin/theme source read, preview, apply, and recovery. Code & Extensions and WordPress file-editor capabilities are also required. Executable PHP is administrator-level code trust, not a sandbox.', 'wp-native-builder-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_USERS_DESTRUCTIVE => array(
				'label'       => __( 'Users & Destructive', 'wp-native-builder-bridge' ),
				'description' => __( 'Allow supported user/role administration and destructive operations when WordPress capabilities also permit them.', 'wp-native-builder-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
		);
	}

	/**
	 * Returns safe default access-group values.
	 *
	 * @return array<string,int> Default values.
	 */
	public function defaults() {
		$defaults = array();

		foreach ( $this->groups() as $key => $group ) {
			$defaults[ $key ] = ! empty( $group['default'] ) ? 1 : 0;
		}

		return $defaults;
	}

	/**
	 * Sanitizes settings to the known boolean access-group keys.
	 *
	 * @param mixed $input Submitted settings value.
	 * @return array<string,int> Sanitized settings.
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$value = array();

		foreach ( $this->groups() as $key => $group ) {
			$value[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		return $value;
	}

	/**
	 * Gets effective settings merged with safe defaults.
	 *
	 * @return array<string,int> Effective access-group values.
	 */
	public function all() {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$value  = $this->defaults();

		foreach ( array_keys( $this->groups() ) as $key ) {
			if ( array_key_exists( $key, $stored ) ) {
				$value[ $key ] = ! empty( $stored[ $key ] ) ? 1 : 0;
			}
		}

		return $value;
	}

	/**
	 * Checks whether a known access group is enabled.
	 *
	 * @param string $group Access-group key.
	 * @return bool
	 */
	public function is_enabled( $group ) {
		if ( ! array_key_exists( $group, $this->groups() ) ) {
			return false;
		}

		$settings = $this->all();
		return ! empty( $settings[ $group ] );
	}
}
