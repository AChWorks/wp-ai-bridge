<?php
/**
 * Bridge access-group settings.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Owns the small grouped access-control settings model.
 */
final class Settings {
	const OPTION_NAME  = 'wp_ai_bridge_settings';
	const OPTION_GROUP = 'wp_ai_bridge';

	const GROUP_DATABASE_INSPECTION = 'database_inspection';
	const GROUP_DATABASE_READ       = 'sensitive_database_read';
	const GROUP_DATABASE_WRITE      = 'database_write';
	const GROUP_DATABASE_DELETE     = 'database_delete';

	const GROUP_SITE_READ         = 'site_read';
	const GROUP_BUILDER_WRITE     = 'builder_write';
	const GROUP_REMOTE_MEDIA      = 'remote_media';
	const GROUP_LIVE_CONTENT      = 'live_content';
	const GROUP_SITE_CONFIG       = 'site_configuration';
	const GROUP_ADVANCED_METADATA = 'advanced_metadata';
	const GROUP_AUTHENTICATION    = 'authentication_credentials';
	const GROUP_CODE_EXTENSIONS   = 'code_extensions';
	const GROUP_EXTERNAL_PACKAGES = 'external_packages';
	const GROUP_SOURCE_EDITING    = 'source_editing';
	const GROUP_NATIVE_ABILITIES  = 'native_abilities';
	const GROUP_REST_DISCOVERY    = 'rest_discovery';
	const GROUP_REST_INVOCATION   = 'rest_invocation';
	const GROUP_COMMENTS          = 'comments';
	const GROUP_USERS_DESTRUCTIVE = 'users_destructive';

	const DATABASE_OPTION_NAME  = 'wp_ai_bridge_database_access';
	const DATABASE_OPTION_GROUP = 'wp_ai_bridge_database_access';
	const DATABASE_GROUPS       = array( self::GROUP_DATABASE_INSPECTION, self::GROUP_DATABASE_READ, self::GROUP_DATABASE_WRITE, self::GROUP_DATABASE_DELETE );


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
				'default'           => array_diff_key( $this->defaults(), $this->database_defaults() ),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::DATABASE_OPTION_GROUP,
			self::DATABASE_OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => $this->database_defaults(),
				'sanitize_callback' => array( $this, 'sanitize_database_grants' ),
				'show_in_rest'      => false,
			)
		);
		$this->register_database_guard();
	}

	/**
	 * Guard the protected native option on every request, including non-admin APIs.
	 *
	 * @return void
	 */
	public function register_database_guard() {
		add_filter( 'sanitize_option_' . self::DATABASE_OPTION_NAME, array( $this, 'sanitize_database_grants' ) );
		add_filter( 'option_page_capability_' . self::DATABASE_OPTION_GROUP, array( $this, 'database_configuration_capability' ) );
	}

	/** @return array<string,int> */
	public function database_defaults() {
		return array_fill_keys( self::DATABASE_GROUPS, 0 );
	}

	/**
	 * Return the actual native authority for the distinct database settings form.
	 *
	 * @param string $unused Native default capability.
	 * @return string
	 */
	public function database_configuration_capability( $unused = 'manage_options' ) {
		if ( ! $this->can_configure_group( self::GROUP_DATABASE_INSPECTION ) ) {
			return 'do_not_allow';
		}
		return function_exists( 'is_multisite' ) && is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Only the actual administrator may write the protected grant row.
	 *
	 * @param mixed $input Submitted database grants.
	 * @return array<string,int>
	 */
	public function sanitize_database_grants( $input ) {
		if ( ! $this->can_configure_group( self::GROUP_DATABASE_INSPECTION ) ) {
			wp_die(
				esc_html__( 'Changing database access requires a current WordPress administrator and, on multisite, actual Super Admin and network authority.', 'wp-ai-bridge' ),
				esc_html__( 'Database access', 'wp-ai-bridge' ),
				array( 'response' => 403 )
			);
			return $this->database_values();
		}
		$input = is_array( $input ) ? $input : array();
		$value = $this->database_defaults();
		foreach ( self::DATABASE_GROUPS as $key ) {
			$value[ $key ] = isset( $input[ $key ] ) && in_array( $input[ $key ], array( 1, '1', true ), true ) ? 1 : 0;
		}
		return $value;
	}

	/** @return array<string,int> Current grants; malformed stored values fail closed. */
	private function database_values() {
		$stored = get_option( self::DATABASE_OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$value  = $this->database_defaults();
		foreach ( self::DATABASE_GROUPS as $key ) {
			$value[ $key ] = isset( $stored[ $key ] ) && 1 === $stored[ $key ] ? 1 : 0;
		}
		return $value;
	}


	/**
	 * Returns the supported access groups and their UI metadata.
	 *
	 * @return array<string,array<string,mixed>> Access-group definitions.
	 */
	public function groups() {
		return array(
			self::GROUP_DATABASE_INSPECTION => array(
				'label'       => __( 'Database Inspection', 'wp-ai-bridge' ),
				'description' => __( 'Inspect installation table, column and index metadata using the WordPress database connection. Multisite requires Super Admin. This does not expose stored record values.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_DATABASE_READ       => array(
				'label'       => __( 'Sensitive Database Read', 'wp-ai-bridge' ),
				'description' => __( 'Read exact raw installation database values, including possible credentials and private plugin data. Database Inspection and administrator authority are also required. This grants no database writes.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_DATABASE_WRITE      => array(
				'label'       => __( 'Database Write', 'wp-ai-bridge' ),
				'description' => __( 'Insert and update exact installation database rows, including sensitive plugin and account data, through typed transactions. Database Inspection, Sensitive Database Read and administrator authority are also required. This does not enable deletion.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_DATABASE_DELETE     => array(
				'label'       => __( 'Database Delete', 'wp-ai-bridge' ),
				'description' => __( 'Delete exact installation database rows inside typed transactions. Database Inspection, Sensitive Database Read, Database Write and administrator authority are also required. Deletion can remove live data.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_SITE_READ           => array(
				'label'       => __( 'Site Read', 'wp-ai-bridge' ),
				'description' => __( 'Inspect site, content, blocks, media, navigation, plugins, themes, and supported integrations.', 'wp-ai-bridge' ),
				'default'     => true,
				'warning'     => false,
			),
			self::GROUP_BUILDER_WRITE       => array(
				'label'       => __( 'Builder Write', 'wp-ai-bridge' ),
				'description' => __( 'Create and update drafts, content, blocks, media, taxonomies, navigation, and forms.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_REMOTE_MEDIA        => array(
				'label'       => __( 'Remote Media', 'wp-ai-bridge' ),
				'description' => __( 'Allow downloads from safe HTTP(S) URLs into the Media Library. Builder Write and WordPress upload capabilities are also required.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_LIVE_CONTENT        => array(
				'label'       => __( 'Live Content', 'wp-ai-bridge' ),
				'description' => __( 'Allow publishing and other live content status changes when WordPress capabilities also permit them.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_SITE_CONFIG         => array(
				'label'       => __( 'Site Configuration', 'wp-ai-bridge' ),
				'description' => __( 'Allow supported global WordPress, theme, and Astra configuration changes.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_ADVANCED_METADATA   => array(
				'label'       => __( 'Advanced Metadata', 'wp-ai-bridge' ),
				'description' => __( 'Allow authorized MCP clients to inspect and update protected/private post, term, user, and comment metadata for exact WordPress objects the connected user may edit. Options, Workspace internals, role/capability/session/application-password state, and credential-like keys remain outside this surface.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_AUTHENTICATION      => array(
				'label'       => __( 'Authentication & Credentials', 'wp-ai-bridge' ),
				'description' => __( 'Allow authorized MCP clients to list, create, rename, and revoke WordPress Application Passwords through Core. Generated credentials are returned only once at creation.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_CODE_EXTENSIONS     => array(
				'label'       => __( 'Code & Extensions', 'wp-ai-bridge' ),
				'description' => __( 'Allow supported managed snippets and plugin/theme lifecycle operations. This does not expose arbitrary code execution.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_EXTERNAL_PACKAGES   => array(
				'label'       => __( 'External Packages', 'wp-ai-bridge' ),
				'description' => __( 'Allow plugin/theme installation from explicit safe public HTTPS package URLs. Code & Extensions and native WordPress install authority are also required. Installed package code runs with normal WordPress runtime authority.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_SOURCE_EDITING      => array(
				'label'       => __( 'Source Editing', 'wp-ai-bridge' ),
				'description' => __( 'Allow installed plugin/theme source read, preview, apply, and recovery. Code & Extensions and WordPress file-editor capabilities are also required. Executable PHP is administrator-level code trust, not a sandbox.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_NATIVE_ABILITIES    => array(
				'label'       => __( 'Native Abilities', 'wp-ai-bridge' ),
				'description' => __( 'Allow registered Core and provider Abilities to execute through WP AI Bridge when their own WordPress/provider permission checks also allow it. This is broad registered-operation trust, not a sandbox.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_REST_DISCOVERY      => array(
				'label'       => __( 'Registered REST Discovery', 'wp-ai-bridge' ),
				'description' => __( 'Allow WordPress administrators to inspect bounded registered REST route contracts. This does not authorize executing REST routes.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_REST_INVOCATION     => array(
				'label'       => __( 'High-Trust Registered REST Invocation', 'wp-ai-bridge' ),
				'description' => __( 'Allow guarded registered REST execution only after every protected lifecycle Bridge consent and corresponding WordPress capability is separately enabled. Generic provider effects are unclassified and require full administrator-equivalent trust; this is not a sandbox.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_COMMENTS            => array(
				'label'       => __( 'Comments', 'wp-ai-bridge' ),
				'description' => __( 'Allow bounded comment discovery, replies, and moderation through WordPress Core. Permanent deletion also requires Users & Destructive.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_USERS_DESTRUCTIVE   => array(
				'label'       => __( 'Users & Destructive', 'wp-ai-bridge' ),
				'description' => __( 'Allow supported user/role administration and destructive operations when WordPress capabilities also permit them.', 'wp-ai-bridge' ),
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
			if ( ! in_array( $key, self::DATABASE_GROUPS, true ) ) {
				$value[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
			}
		}

		return $value;
	}

	/**
	 * Restrict network-wide database delegation without changing site groups.
	 *
	 * The native Settings API already requires manage_options for this form.
	 * Network database grants additionally require the actual network authority,
	 * even when a connected execution principal is already a Super Admin.
	 *
	 * @param string $group Access-group key.
	 * @return bool
	 */
	public function can_configure_group( $group ) {
		if ( ! in_array( $group, self::DATABASE_GROUPS, true ) ) {
			return true;
		}
		return get_current_user_id() > 0 && current_user_can( 'manage_options' ) &&
			( ! function_exists( 'is_multisite' ) || ! is_multisite() ||
				( is_super_admin() && current_user_can( 'manage_network_options' ) ) );
	}

	/**
	 * Gets effective settings merged with safe defaults.
	 *
	 * @return array<string,int> Effective settings.
	 */
	public function all() {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$value  = $this->defaults();

		foreach ( array_keys( $this->groups() ) as $key ) {
			if ( ! in_array( $key, self::DATABASE_GROUPS, true ) && array_key_exists( $key, $stored ) ) {
				$value[ $key ] = ! empty( $stored[ $key ] ) ? 1 : 0;
			}
		}
		// Never inherit embedded legacy/unreleased bits from the ordinary row.
		return array_replace( $value, $this->database_values() );
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
