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

	const GROUP_DATABASE_SCHEMA = 'database_schema';
	const GROUP_DATABASE_SQL    = 'database_native_sql';

	const GROUP_FILESYSTEM_READ      = 'filesystem_read';
	const GROUP_FILESYSTEM_WRITE     = 'filesystem_write';
	const GROUP_FILESYSTEM_DELETE    = 'filesystem_delete';
	const GROUP_FILESYSTEM_EXECUTE   = 'filesystem_executable';
	const GROUP_LOGS_READ            = 'sensitive_logs_read';
	const GROUP_SCHEDULER_MANAGE     = 'scheduler_manage';
	const GROUP_SCHEDULER_RUN        = 'scheduler_run';
	const GROUP_BACKUP_EXPORT        = 'full_backup';
	const GROUP_BACKUP_RESTORE       = 'full_restore';
	const GROUP_NATIVE_CONFIGURATION = 'native_configuration';
	const GROUP_CUSTOMIZER_WRITE     = 'customizer_write';

	const ADMINISTRATION_OPTION_NAME  = 'wp_ai_bridge_administration_access';
	const ADMINISTRATION_OPTION_GROUP = 'wp_ai_bridge_administration_access';
	const ADMINISTRATION_GROUPS       = array( self::GROUP_FILESYSTEM_READ, self::GROUP_FILESYSTEM_WRITE, self::GROUP_FILESYSTEM_DELETE, self::GROUP_FILESYSTEM_EXECUTE, self::GROUP_LOGS_READ, self::GROUP_SCHEDULER_MANAGE, self::GROUP_SCHEDULER_RUN, self::GROUP_BACKUP_EXPORT, self::GROUP_BACKUP_RESTORE, self::GROUP_NATIVE_CONFIGURATION, self::GROUP_CUSTOMIZER_WRITE );

	const GROUP_NATIVE_OPTIONS_READ  = 'sensitive_native_settings_read';
	const GROUP_NATIVE_NETWORK_READ  = 'sensitive_network_settings_read';
	const GROUP_NATIVE_OPTIONS_WRITE = 'sensitive_native_settings_write';
	const GROUP_NATIVE_NETWORK_WRITE = 'sensitive_network_settings_write';

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
	const DATABASE_GROUPS       = array( self::GROUP_DATABASE_INSPECTION, self::GROUP_DATABASE_READ, self::GROUP_DATABASE_WRITE, self::GROUP_DATABASE_DELETE, self::GROUP_DATABASE_SCHEMA, self::GROUP_DATABASE_SQL );


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
				'default'           => array_diff_key(
					$this->defaults(),
					$this->database_defaults(),
					$this->administration_defaults(),
					array(
						self::GROUP_NATIVE_NETWORK_READ  => 0,
						self::GROUP_NATIVE_NETWORK_WRITE => 0,
					)
				),
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
		register_setting(
			self::ADMINISTRATION_OPTION_GROUP,
			self::ADMINISTRATION_OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => $this->administration_defaults(),
				'sanitize_callback' => array( $this, 'sanitize_administration_grants' ),
				'show_in_rest'      => false,
			)
		);
		$this->register_database_guard();
		$this->register_administration_guard();
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



	/** Register protected administration grants on every WordPress request. @return void */
	public function register_administration_guard() {
		add_filter( 'sanitize_option_' . self::ADMINISTRATION_OPTION_NAME, array( $this, 'sanitize_administration_grants' ) );
		add_filter( 'option_page_capability_' . self::ADMINISTRATION_OPTION_GROUP, array( $this, 'administration_configuration_capability' ) );
	}

	/** @return array<string,int> */
	public function administration_defaults() {
		return array_fill_keys( self::ADMINISTRATION_GROUPS, 0 );
	}

	/** @param string $unused Native default capability. @return string */
	public function administration_configuration_capability( $unused = 'manage_options' ) {
		return $this->can_configure_group( self::GROUP_FILESYSTEM_READ ) ? ( function_exists( 'is_multisite' ) && is_multisite() ? 'manage_network_options' : 'manage_options' ) : 'do_not_allow';
	}

	/** @param mixed $input Submitted explicitly protected grants. @return array */
	public function sanitize_administration_grants( $input ) {
		if ( ! $this->can_configure_group( self::GROUP_FILESYSTEM_READ ) ) {
			wp_die( esc_html__( 'Changing advanced administration access requires current administrator and network authority.', 'wp-ai-bridge' ), esc_html__( 'Administration access', 'wp-ai-bridge' ), array( 'response' => 403 ) );
			return $this->administration_values();
		}
		$input = is_array( $input ) ? $input : array();
		$value = $this->administration_defaults();
		foreach ( self::ADMINISTRATION_GROUPS as $key ) {
			$value[ $key ] = isset( $input[ $key ] ) && in_array( $input[ $key ], array( 1, '1', true ), true ) ? 1 : 0;
		}
		$value['_blog_id']    = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$value['_network_id'] = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 0;
		return $value;
	}

	/** Original site/network binding prevents adoption after reassignment. @return array<string,int> */
	private function administration_values() {
		$stored = get_option( self::ADMINISTRATION_OPTION_NAME, array() );
		$value  = $this->administration_defaults();
		if ( ! is_array( $stored ) || ! isset( $stored['_blog_id'], $stored['_network_id'] ) ||
			! function_exists( 'get_current_blog_id' ) || ! function_exists( 'get_current_network_id' ) ||
			(int) get_current_blog_id() !== $stored['_blog_id'] || (int) get_current_network_id() !== $stored['_network_id'] ) {
			return $value;
		}
		foreach ( self::ADMINISTRATION_GROUPS as $key ) {
			$value[ $key ] = 1 === ( $stored[ $key ] ?? null ) ? 1 : 0;
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
			self::GROUP_DATABASE_SCHEMA      => array(
				'label'       => __( 'Database schema changes', 'wp-ai-bridge' ),
				'description' => __( 'Create and alter approved database schemas through explicit native operations. Native DDL is not a rollback or atomic compare-and-swap contract.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_DATABASE_SQL         => array(
				'label'       => __( 'Native-account SQL', 'wp-ai-bridge' ),
				'description' => __( 'Run explicitly acknowledged SQL with the database account\'s actual privileges only when server policy separately enables it. This can affect Bridge controls and other schemas accessible to that account.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_FILESYSTEM_READ      => array(
				'label'       => __( 'Sensitive filesystem read', 'wp-ai-bridge' ),
				'description' => __( 'Read exact bytes within administrator-approved filesystem roots. This may expose configuration and credentials.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_FILESYSTEM_WRITE     => array(
				'label'       => __( 'Filesystem write', 'wp-ai-bridge' ),
				'description' => __( 'Create and replace files within approved writable roots. Staging alone never publishes bytes.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_FILESYSTEM_DELETE    => array(
				'label'       => __( 'Filesystem deletion and moves', 'wp-ai-bridge' ),
				'description' => __( 'Delete or move exact approved filesystem targets. Interrupted tree operations can require recovery.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_FILESYSTEM_EXECUTE   => array(
				'label'       => __( 'Executable filesystem changes', 'wp-ai-bridge' ),
				'description' => __( 'Additionally permit code and configuration changes under approved roots and native file-editing authority. These changes are not a PHP sandbox.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_LOGS_READ            => array(
				'label'       => __( 'Sensitive log access', 'wp-ai-bridge' ),
				'description' => __( 'Read exact authorized WordPress, PHP and provider log windows within separately approved log roots.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_SCHEDULER_MANAGE     => array(
				'label'       => __( 'Scheduler management', 'wp-ai-bridge' ),
				'description' => __( 'Schedule and cancel native events and supported provider actions using their own public lifecycles.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_SCHEDULER_RUN        => array(
				'label'       => __( 'Immediate scheduled-action execution', 'wp-ai-bridge' ),
				'description' => __( 'Run supported targeted provider actions. Native callbacks may have effects even when final reporting fails.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_BACKUP_EXPORT        => array(
				'label'       => __( 'Full backup export', 'wp-ai-bridge' ),
				'description' => __( 'Capture approved database objects and installation files, including credentials, under an explicit quiescence contract.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_BACKUP_RESTORE       => array(
				'label'       => __( 'Full backup restore', 'wp-ai-bridge' ),
				'description' => __( 'Restore a verified backup into an explicitly approved empty schema and nonserved file root. Destination effects can be nontransactional.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_NATIVE_CONFIGURATION => array(
				'label'       => __( 'Native appearance and transient settings', 'wp-ai-bridge' ),
				'description' => __( 'Read and manage native theme modifications and exact transients under current sensitive settings authority.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_CUSTOMIZER_WRITE     => array(
				'label'       => __( 'Registered Customizer settings', 'wp-ai-bridge' ),
				'description' => __( 'Inspect and publish registered current-theme settings through native validation, capabilities and provider hooks.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_NATIVE_OPTIONS_WRITE => array(
				'label'       => __( 'Sensitive Native Site Settings Write', 'wp-ai-bridge' ),
				'description' => __( 'Add, update and delete exact native site settings, including sensitive plugin and account configuration, through installed native callbacks. Sensitive Native Site Settings Read, Site Configuration and current administrator authority are required. Deletion also requires Users & Destructive.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_NATIVE_NETWORK_WRITE => array(
				'label'       => __( 'Sensitive Native Network Settings Write', 'wp-ai-bridge' ),
				'description' => __( 'Add, update and delete current-network native settings only with separate network-owned consent and actual Super Admin authority. Native site write access and both sensitive read grants are also required. Ordinary site saves cannot change this grant.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_NATIVE_OPTIONS_READ  => array(
				'label'       => __( 'Sensitive Native Site Settings Read', 'wp-ai-bridge' ),
				'description' => __( 'Read and privately export exact native site settings, including credentials and structured private plugin data under any option name. WordPress administrator authority is required. This grants no settings writes.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_NATIVE_NETWORK_READ  => array(
				'label'       => __( 'Sensitive Native Network Settings Read', 'wp-ai-bridge' ),
				'description' => __( 'Read and privately export current-network native settings, including secrets. Separate current-network consent, site settings read access and actual Super Admin network authority are required. Local site saves cannot change this grant.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_DATABASE_INSPECTION  => array(
				'label'       => __( 'Database Inspection', 'wp-ai-bridge' ),
				'description' => __( 'Inspect installation table, column and index metadata using the WordPress database connection. Multisite requires Super Admin. This does not expose stored record values.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_DATABASE_READ        => array(
				'label'       => __( 'Sensitive Database Read', 'wp-ai-bridge' ),
				'description' => __( 'Read exact raw installation database values, including possible credentials and private plugin data. Database Inspection and administrator authority are also required. This grants no database writes.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_DATABASE_WRITE       => array(
				'label'       => __( 'Database Write', 'wp-ai-bridge' ),
				'description' => __( 'Insert and update exact installation database rows, including sensitive plugin and account data, through typed transactions. Database Inspection, Sensitive Database Read and administrator authority are also required. This does not enable deletion.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_DATABASE_DELETE      => array(
				'label'       => __( 'Database Delete', 'wp-ai-bridge' ),
				'description' => __( 'Delete exact installation database rows inside typed transactions. Database Inspection, Sensitive Database Read, Database Write and administrator authority are also required. Deletion can remove live data.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_SITE_READ            => array(
				'label'       => __( 'Site Read', 'wp-ai-bridge' ),
				'description' => __( 'Inspect site, content, blocks, media, navigation, plugins, themes, and supported integrations.', 'wp-ai-bridge' ),
				'default'     => true,
				'warning'     => false,
			),
			self::GROUP_BUILDER_WRITE        => array(
				'label'       => __( 'Builder Write', 'wp-ai-bridge' ),
				'description' => __( 'Create and update drafts, content, blocks, media, taxonomies, navigation, and forms.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_REMOTE_MEDIA         => array(
				'label'       => __( 'Remote Media', 'wp-ai-bridge' ),
				'description' => __( 'Allow downloads from safe HTTP(S) URLs into the Media Library. Builder Write and WordPress upload capabilities are also required.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_LIVE_CONTENT         => array(
				'label'       => __( 'Live Content', 'wp-ai-bridge' ),
				'description' => __( 'Allow publishing and other live content status changes when WordPress capabilities also permit them.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_SITE_CONFIG          => array(
				'label'       => __( 'Site Configuration', 'wp-ai-bridge' ),
				'description' => __( 'Allow supported global WordPress, theme, and Astra configuration changes.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_ADVANCED_METADATA    => array(
				'label'       => __( 'Advanced Metadata', 'wp-ai-bridge' ),
				'description' => __( 'Allow authorized MCP clients to inspect and update protected/private post, term, user, and comment metadata for exact WordPress objects the connected user may edit. Options, Workspace internals, role/capability/session/application-password state, and credential-like keys remain outside this surface.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_AUTHENTICATION       => array(
				'label'       => __( 'Authentication & Credentials', 'wp-ai-bridge' ),
				'description' => __( 'Allow authorized MCP clients to list, create, rename, and revoke WordPress Application Passwords through Core. Generated credentials are returned only once at creation.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_CODE_EXTENSIONS      => array(
				'label'       => __( 'Code & Extensions', 'wp-ai-bridge' ),
				'description' => __( 'Allow supported managed snippets and plugin/theme lifecycle operations. This does not expose arbitrary code execution.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_EXTERNAL_PACKAGES    => array(
				'label'       => __( 'External Packages', 'wp-ai-bridge' ),
				'description' => __( 'Allow plugin/theme installation from explicit safe public HTTPS package URLs. Code & Extensions and native WordPress install authority are also required. Installed package code runs with normal WordPress runtime authority.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_SOURCE_EDITING       => array(
				'label'       => __( 'Source Editing', 'wp-ai-bridge' ),
				'description' => __( 'Allow installed plugin/theme source read, preview, apply, and recovery. Code & Extensions and WordPress file-editor capabilities are also required. Executable PHP is administrator-level code trust, not a sandbox.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_NATIVE_ABILITIES     => array(
				'label'       => __( 'Native Abilities', 'wp-ai-bridge' ),
				'description' => __( 'Allow registered Core and provider Abilities to execute through WP AI Bridge when their own WordPress/provider permission checks also allow it. This is broad registered-operation trust, not a sandbox.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_REST_DISCOVERY       => array(
				'label'       => __( 'Registered REST Discovery', 'wp-ai-bridge' ),
				'description' => __( 'Allow WordPress administrators to inspect bounded registered REST route contracts. This does not authorize executing REST routes.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_REST_INVOCATION      => array(
				'label'       => __( 'High-Trust Registered REST Invocation', 'wp-ai-bridge' ),
				'description' => __( 'Allow guarded registered REST execution only after every protected lifecycle Bridge consent and corresponding WordPress capability is separately enabled. Generic provider effects are unclassified and require full administrator-equivalent trust; this is not a sandbox.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_COMMENTS             => array(
				'label'       => __( 'Comments', 'wp-ai-bridge' ),
				'description' => __( 'Allow bounded comment discovery, replies, and moderation through WordPress Core. Permanent deletion also requires Users & Destructive.', 'wp-ai-bridge' ),
				'default'     => false,
				'warning'     => true,
			),
			self::GROUP_USERS_DESTRUCTIVE    => array(
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
			if ( ! in_array( $key, array( self::GROUP_NATIVE_NETWORK_READ, self::GROUP_NATIVE_NETWORK_WRITE ), true ) ) {
				$defaults[ $key ] = ! empty( $group['default'] ) ? 1 : 0;
			}
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
			if ( ! in_array( $key, array( self::GROUP_NATIVE_NETWORK_READ, self::GROUP_NATIVE_NETWORK_WRITE ), true ) && ! in_array( $key, self::DATABASE_GROUPS, true ) && ! in_array( $key, self::ADMINISTRATION_GROUPS, true ) ) {
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
		if ( in_array( $group, array( self::GROUP_NATIVE_NETWORK_READ, self::GROUP_NATIVE_NETWORK_WRITE ), true ) ) {
			return Native_Option_Access::can_configure_network();
		}
		if ( ! in_array( $group, self::DATABASE_GROUPS, true ) && ! in_array( $group, self::ADMINISTRATION_GROUPS, true ) ) {
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
			if ( ! in_array( $key, array( self::GROUP_NATIVE_NETWORK_READ, self::GROUP_NATIVE_NETWORK_WRITE ), true ) && ! in_array( $key, self::DATABASE_GROUPS, true ) && ! in_array( $key, self::ADMINISTRATION_GROUPS, true ) && array_key_exists( $key, $stored ) ) {
				$value[ $key ] = ! empty( $stored[ $key ] ) ? 1 : 0;
			}
		}
		// Never inherit embedded legacy/unreleased bits from the ordinary row.
		$value                                     = array_replace( $value, $this->database_values(), $this->administration_values() );
		$value[ self::GROUP_NATIVE_NETWORK_READ ]  = Native_Option_Access::network_enabled() ? 1 : 0;
		$value[ self::GROUP_NATIVE_NETWORK_WRITE ] = Native_Option_Access::network_write_enabled() ? 1 : 0;
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
