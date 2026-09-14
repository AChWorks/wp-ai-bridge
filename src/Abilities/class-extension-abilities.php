<?php
/**
 * WordPress plugin/theme lifecycle abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Uses supported WordPress administration APIs; never edits extension files directly.
 */
final class Extension_Abilities {
	/** @var Permissions */ private $permissions;
	/** @var Mutation_Log */ private $log;
	/** @param Permissions $permissions Permissions. @param Mutation_Log $log Log. */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-native-builder/extensions-read',
			array(
				'label'               => __( 'Read Plugins and Themes', 'wp-native-builder-bridge' ),
				'description'         => __( 'Lists installed WordPress plugins and themes with bounded lifecycle state.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'kind' => array(
							'type' => 'string',
							'enum' => array( 'plugin', 'theme', 'all' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/extension-lifecycle',
			array(
				'label'               => __( 'Manage Plugin or Theme Lifecycle', 'wp-native-builder-bridge' ),
				'description'         => __( 'Installs WordPress.org extensions by slug or updates/activates/deactivates installed extensions through WordPress Core APIs. Deletion additionally requires destructive access.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->mutate_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'kind'    => array( 'type' => 'string' ),
						'action'  => array( 'type' => 'string' ),
						'target'  => array( 'type' => 'string' ),
						'success' => array( 'type' => 'boolean' ),
						'requires_manual_filesystem_access' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'kind', 'action', 'target', 'success', 'requires_manual_filesystem_access' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'mutate' ),
				'permission_callback' => array( $this, 'can_mutate' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}
	/** @return bool */ public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );}
	/** @param array<string,mixed> $input Input. @return bool */
	public function can_mutate( $input ) {
		if ( ! is_array( $input ) || empty( $input['kind'] ) || empty( $input['action'] ) || ! $this->permissions->allowed( Settings::GROUP_CODE_EXTENSIONS, $this->capability( (string) $input['kind'], (string) $input['action'] ) ) ) {
			return false;}
		if ( 'delete' === $input['action'] ) {
			return $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, $this->capability( (string) $input['kind'], 'delete' ) );}
		return true;
	}
	/** @param array<string,mixed> $input Input. @return array<string,mixed> */
	public function read( $input ) {
		$this->load_admin_files( false );
		$kind = isset( $input['kind'] ) ? (string) $input['kind'] : 'all';
		return array(
			'plugins' => in_array( $kind, array( 'all', 'plugin' ), true ) ? $this->plugins() : array(),
			'themes'  => in_array( $kind, array( 'all', 'theme' ), true ) ? $this->themes() : array(),
		);}
	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function mutate( $input ) {
		$kind   = (string) $input['kind'];
		$action = (string) $input['action'];
		$target = isset( $input['target'] ) ? sanitize_text_field( (string) $input['target'] ) : '';
		$slug   = isset( $input['slug'] ) ? sanitize_key( (string) $input['slug'] ) : '';
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) {
			return new WP_Error( 'invalid_extension_kind', __( 'Extension kind must be plugin or theme.', 'wp-native-builder-bridge' ) );}
		if ( ! in_array( $action, $this->actions_for( $kind ), true ) ) {
			return new WP_Error( 'invalid_extension_action', __( 'That lifecycle action is not supported for the selected extension kind.', 'wp-native-builder-bridge' ) );}
		if ( 'install' === $action ) {
			if ( '' === $slug ) {
				return new WP_Error( 'extension_slug_required', __( 'A WordPress.org slug is required for installation.', 'wp-native-builder-bridge' ) );
			}return $this->install( $kind, $slug );}
		if ( '' === $target ) {
			return new WP_Error( 'extension_target_required', __( 'An installed plugin file or theme stylesheet is required.', 'wp-native-builder-bridge' ) );}
		$this->load_admin_files( true );
		$result = 'plugin' === $kind ? $this->mutate_plugin( $action, $target ) : $this->mutate_theme( $action, $target );
		if ( is_wp_error( $result ) ) {
			return $result;}
		$this->log->record( 'wp-native-builder/extension-lifecycle', $kind, 0, true, '' );
		return array(
			'kind'                              => $kind,
			'action'                            => $action,
			'target'                            => (string) $result,
			'success'                           => true,
			'requires_manual_filesystem_access' => false,
		);
	}
	/** @param string $kind Kind. @param string $slug Slug. @return array<string,mixed>|WP_Error */
	private function install( $kind, $slug ) {
		$this->load_admin_files( true );
		global $wp_filesystem;
		if ( 'plugin' === $kind ) {
			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array(
						'sections'       => false,
						'language_packs' => false,
					),
				)
			);
			if ( is_wp_error( $api ) ) {
				return $api;
			}if ( empty( $api->download_link ) ) {
				return new WP_Error( 'plugin_package_missing', __( 'WordPress.org did not return an install package for that plugin slug.', 'wp-native-builder-bridge' ) );
			}$skin    = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$ok       = $upgrader->install( $api->download_link );
			$target   = $upgrader->plugin_info();} else {
			$api = themes_api(
				'theme_information',
				array(
					'slug'   => $slug,
					'fields' => array(
						'sections'     => false,
						'downloadlink' => true,
					),
				)
			);
			if ( is_wp_error( $api ) ) {
				return $api;
			}if ( empty( $api->download_link ) ) {
				return new WP_Error( 'theme_package_missing', __( 'WordPress.org did not return an install package for that theme slug.', 'wp-native-builder-bridge' ) );
			}$skin    = new \Automatic_Upgrader_Skin();
			$upgrader = new \Theme_Upgrader( $skin );
			$ok       = $upgrader->install( $api->download_link );
			$target   = $slug;}
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}if ( ! $ok ) {
				return $this->filesystem_error( $wp_filesystem );}
			$this->log->record( 'wp-native-builder/extension-lifecycle', $kind, 0, true, '' );
			return array(
				'kind'                              => $kind,
				'action'                            => 'install',
				'target'                            => (string) $target,
				'success'                           => true,
				'requires_manual_filesystem_access' => false,
			);
	}
	/** @param string $action Action. @param string $plugin Plugin. @return string|WP_Error */
	private function mutate_plugin( $action, $plugin ) {
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) {
			return new WP_Error( 'plugin_not_found', __( 'The installed plugin target was not found.', 'wp-native-builder-bridge' ) );}
		if ( 'activate' === $action ) {
			$result = activate_plugin( $plugin );
			return is_wp_error( $result ) ? $result : $plugin;}
		if ( 'deactivate' === $action ) {
			deactivate_plugins( $plugin );
			return is_plugin_active( $plugin ) ? new WP_Error( 'plugin_deactivate_failed', __( 'WordPress did not deactivate the plugin.', 'wp-native-builder-bridge' ) ) : $plugin;}
		if ( 'update' === $action ) {
			wp_update_plugins();
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->upgrade( $plugin );
			return is_wp_error( $result ) ? $result : ( $result ? $plugin : new WP_Error( 'plugin_update_unavailable', __( 'No applicable plugin update was installed.', 'wp-native-builder-bridge' ) ) );}
		if ( is_plugin_active( $plugin ) ) {
			return new WP_Error( 'plugin_must_be_inactive', __( 'Deactivate the plugin before deleting it.', 'wp-native-builder-bridge' ) );
		}$result = delete_plugins( array( $plugin ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}if ( null === $result ) {
			return new WP_Error( 'filesystem_access_required', __( 'WordPress requires manual filesystem credentials or access for this plugin deletion.', 'wp-native-builder-bridge' ) );
		}return true === $result ? $plugin : new WP_Error( 'plugin_delete_failed', __( 'WordPress did not delete the plugin.', 'wp-native-builder-bridge' ) );
	}
	/** @param string $action Action. @param string $stylesheet Stylesheet. @return string|WP_Error */
	private function mutate_theme( $action, $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'theme_not_found', __( 'The installed theme target was not found.', 'wp-native-builder-bridge' ) );}
		if ( 'activate' === $action ) {
			$requirements = validate_theme_requirements( $stylesheet );
			if ( is_wp_error( $requirements ) ) {
				return $requirements;
			}switch_theme( $stylesheet );
			return get_stylesheet() === $stylesheet ? $stylesheet : new WP_Error( 'theme_switch_failed', __( 'WordPress did not activate the theme.', 'wp-native-builder-bridge' ) );}
		if ( 'update' === $action ) {
			wp_update_themes();
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Theme_Upgrader( $skin );
			$result   = $upgrader->upgrade( $stylesheet );
			return is_wp_error( $result ) ? $result : ( $result ? $stylesheet : new WP_Error( 'theme_update_unavailable', __( 'No applicable theme update was installed.', 'wp-native-builder-bridge' ) ) );}
		if ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ) {
			return new WP_Error( 'active_theme_delete_denied', __( 'Activate another theme before deleting the current theme or its parent.', 'wp-native-builder-bridge' ) );
		}$result = delete_theme( $stylesheet );
		if ( is_wp_error( $result ) ) {
			return $result;
		}if ( null === $result ) {
			return new WP_Error( 'filesystem_access_required', __( 'WordPress requires manual filesystem credentials or access for this theme deletion.', 'wp-native-builder-bridge' ) );
		}return true === $result ? $stylesheet : new WP_Error( 'theme_delete_failed', __( 'WordPress did not delete the theme.', 'wp-native-builder-bridge' ) );
	}
	/** @param mixed $filesystem Filesystem. @return WP_Error */ private function filesystem_error( $filesystem ) {
		if ( is_object( $filesystem ) && isset( $filesystem->errors ) && is_wp_error( $filesystem->errors ) && $filesystem->errors->has_errors() ) {
			return new WP_Error( 'filesystem_access_required', $filesystem->errors->get_error_message() );
		}return new WP_Error( 'filesystem_access_required', __( 'WordPress could not obtain non-interactive filesystem access. Complete filesystem setup manually and retry.', 'wp-native-builder-bridge' ) );}
	/** @param bool $upgrader Include upgrader/install APIs. @return void */
	private function load_admin_files( $upgrader ) {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		if ( $upgrader ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';}}
	/** @return array<int,array<string,mixed>> */ private function plugins() {
		$active = (array) get_option( 'active_plugins', array() );
		$out    = array();
		foreach ( get_plugins() as $file => $data ) {
			$out[] = array(
				'file'           => (string) $file,
				'name'           => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version'        => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'active'         => in_array( $file, $active, true ),
				'network_active' => is_multisite() && is_plugin_active_for_network( $file ),
			);
		}return $out;}
	/** @return array<int,array<string,mixed>> */ private function themes() {
		$out     = array();
		$current = get_stylesheet();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$out[] = array(
				'stylesheet' => (string) $stylesheet,
				'name'       => (string) $theme->get( 'Name' ),
				'version'    => (string) $theme->get( 'Version' ),
				'active'     => $stylesheet === $current,
				'parent'     => (string) $theme->get_template(),
			);
		}return $out;}
	/** @param string $kind Kind. @param string $action Action. @return string */ private function capability( $kind, $action ) {
		$plugin = array(
			'install'    => 'install_plugins',
			'update'     => 'update_plugins',
			'activate'   => 'activate_plugins',
			'deactivate' => 'activate_plugins',
			'delete'     => 'delete_plugins',
		);
		$theme  = array(
			'install'  => 'install_themes',
			'update'   => 'update_themes',
			'activate' => 'switch_themes',
			'delete'   => 'delete_themes',
		);
		$map    = 'plugin' === $kind ? $plugin : $theme;
		return isset( $map[ $action ] ) ? $map[ $action ] : 'do_not_allow';}
	/** @param string $kind Kind. @return array<int,string> */ private function actions_for( $kind ) {
		return 'plugin' === $kind ? array( 'install', 'update', 'activate', 'deactivate', 'delete' ) : array( 'install', 'update', 'activate', 'delete' );}
	/** @return array<string,mixed> */ private function mutate_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'   => array(
					'type' => 'string',
					'enum' => array( 'plugin', 'theme' ),
				),
				'action' => array(
					'type' => 'string',
					'enum' => array( 'install', 'update', 'activate', 'deactivate', 'delete' ),
				),
				'target' => array(
					'type'      => 'string',
					'maxLength' => 300,
				),
				'slug'   => array(
					'type'      => 'string',
					'maxLength' => 200,
					'pattern'   => '^[a-z0-9-]+$',
				),
			),
			'required'             => array( 'kind', 'action' ),
			'additionalProperties' => false,
		);}
	/** @return array<string,mixed> */ private function read_output_schema() {
		$plugin = array(
			'type'                 => 'object',
			'properties'           => array(
				'file'           => array( 'type' => 'string' ),
				'name'           => array( 'type' => 'string' ),
				'version'        => array( 'type' => 'string' ),
				'active'         => array( 'type' => 'boolean' ),
				'network_active' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'file', 'name', 'version', 'active', 'network_active' ),
			'additionalProperties' => false,
		);
		$theme  = array(
			'type'                 => 'object',
			'properties'           => array(
				'stylesheet' => array( 'type' => 'string' ),
				'name'       => array( 'type' => 'string' ),
				'version'    => array( 'type' => 'string' ),
				'active'     => array( 'type' => 'boolean' ),
				'parent'     => array( 'type' => 'string' ),
			),
			'required'             => array( 'stylesheet', 'name', 'version', 'active', 'parent' ),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'plugins' => array(
					'type'  => 'array',
					'items' => $plugin,
				),
				'themes'  => array(
					'type'  => 'array',
					'items' => $theme,
				),
			),
			'required'             => array( 'plugins', 'themes' ),
			'additionalProperties' => false,
		);}
	/** @param bool $is_readonly Read-only. @param bool $destructive D. @param bool $idempotent I. @return array<string,mixed> */ private function meta( $is_readonly, $destructive, $idempotent ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => $is_readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);}
}
