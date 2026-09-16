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
	const ABSOLUTE_MAX_PACKAGE_BYTES = 104857600;

	/** @var Permissions */
	private $permissions;

	/** @var Mutation_Log */
	private $log;

	/**
	 * @param Permissions  $permissions Permissions.
	 * @param Mutation_Log $log         Log.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

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
				'description'         => __( 'Installs WordPress.org extensions by slug or explicitly authorized external HTTPS packages, and manages installed extensions through WordPress Core APIs. Deletion additionally requires destructive access.', 'wp-native-builder-bridge' ),
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

	/** @return bool */
	public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return bool
	 */
	public function can_mutate( $input ) {
		if ( ! is_array( $input ) || empty( $input['kind'] ) || empty( $input['action'] ) ) {
			return false;
		}
		$kind   = (string) $input['kind'];
		$action = (string) $input['action'];
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || ! in_array( $action, $this->actions_for( $kind ), true ) ) {
			return false;
		}
		$capability = $this->capability( $kind, $action );
		if ( ! $this->permissions->allowed( Settings::GROUP_CODE_EXTENSIONS, $capability ) ) {
			return false;
		}
		if ( 'install' === $action && ! empty( $input['package_url'] ) ) {
			return $this->permissions->allowed( Settings::GROUP_EXTERNAL_PACKAGES, $capability );
		}
		if ( 'delete' === $action ) {
			return $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, $this->capability( $kind, 'delete' ) );
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	public function read( $input ) {
		$this->load_admin_files( false );
		$kind = isset( $input['kind'] ) ? (string) $input['kind'] : 'all';
		return array(
			'plugins' => in_array( $kind, array( 'all', 'plugin' ), true ) ? $this->plugins() : array(),
			'themes'  => in_array( $kind, array( 'all', 'theme' ), true ) ? $this->themes() : array(),
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function mutate( $input ) {
		$kind   = isset( $input['kind'] ) ? (string) $input['kind'] : '';
		$action = isset( $input['action'] ) ? (string) $input['action'] : '';
		$target = isset( $input['target'] ) ? sanitize_text_field( (string) $input['target'] ) : '';
		$slug   = isset( $input['slug'] ) ? sanitize_key( (string) $input['slug'] ) : '';

		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) {
			return new WP_Error( 'invalid_extension_kind', __( 'Extension kind must be plugin or theme.', 'wp-native-builder-bridge' ) );
		}
		if ( ! in_array( $action, $this->actions_for( $kind ), true ) ) {
			return new WP_Error( 'invalid_extension_action', __( 'That lifecycle action is not supported for the selected extension kind.', 'wp-native-builder-bridge' ) );
		}

		if ( 'install' === $action ) {
			$has_slug    = '' !== $slug;
			$package_url = isset( $input['package_url'] ) && is_string( $input['package_url'] ) ? $input['package_url'] : '';
			$has_package = '' !== $package_url;
			if ( ! $has_slug && ! $has_package ) {
				return new WP_Error( 'extension_slug_required', __( 'A WordPress.org slug or an external package URL is required for installation.', 'wp-native-builder-bridge' ) );
			}
			if ( $has_slug && $has_package ) {
				return new WP_Error( 'extension_install_source_required', __( 'Choose exactly one install source: a WordPress.org slug or an external package URL.', 'wp-native-builder-bridge' ) );
			}
			if ( $has_package ) {
				return $this->install_external_package( $kind, $input );
			}
			return $this->install( $kind, $slug );
		}

		if ( isset( $input['slug'] ) || isset( $input['package_url'] ) ) {
			return new WP_Error( 'extension_install_source_not_applicable', __( 'Install source fields are accepted only for the install action.', 'wp-native-builder-bridge' ) );
		}
		if ( '' === $target ) {
			return new WP_Error( 'extension_target_required', __( 'An installed plugin file or theme stylesheet is required.', 'wp-native-builder-bridge' ) );
		}

		$this->load_admin_files( true );
		$result = 'plugin' === $kind ? $this->mutate_plugin( $action, $target ) : $this->mutate_theme( $action, $target );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->log->record( 'wp-native-builder/extension-lifecycle', $kind, 0, true, '' );
		return array(
			'kind'                              => $kind,
			'action'                            => $action,
			'target'                            => (string) $result,
			'success'                           => true,
			'requires_manual_filesystem_access' => false,
		);
	}

	/**
	 * Installs one WordPress.org extension by slug.
	 *
	 * @param string $kind Kind.
	 * @param string $slug Slug.
	 * @return array<string,mixed>|WP_Error
	 */
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
			}
			if ( empty( $api->download_link ) ) {
				return new WP_Error( 'plugin_package_missing', __( 'WordPress.org did not return an install package for that plugin slug.', 'wp-native-builder-bridge' ) );
			}
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$ok       = $upgrader->install( $api->download_link );
			$target   = $upgrader->plugin_info();
		} else {
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
			}
			if ( empty( $api->download_link ) ) {
				return new WP_Error( 'theme_package_missing', __( 'WordPress.org did not return an install package for that theme slug.', 'wp-native-builder-bridge' ) );
			}
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Theme_Upgrader( $skin );
			$ok       = $upgrader->install( $api->download_link );
			$target   = $slug;
		}
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( ! $ok ) {
			return $this->filesystem_error( $wp_filesystem );
		}
		$this->log->record( 'wp-native-builder/extension-lifecycle', $kind, 0, true, '' );
		return array(
			'kind'                              => $kind,
			'action'                            => 'install',
			'target'                            => (string) $target,
			'success'                           => true,
			'requires_manual_filesystem_access' => false,
		);
	}

	/**
	 * Installs one package from a separately authorized external HTTPS source.
	 *
	 * @param string              $kind  Extension kind.
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	private function install_external_package( $kind, $input ) {
		$state      = array(
			'temp_file'        => '',
			'install_started'  => false,
			'installed_target' => '',
		);
		$result     = null;
		$unexpected = false;

		try {
			$result = $this->install_external_package_checked( $kind, $input, $state );
		} catch ( \Throwable $error ) {
			$unexpected = true;
		}

		try {
			$cleaned = $this->cleanup_external_package( $state );
		} catch ( \Throwable $error ) {
			$cleaned = false;
		}

		if ( $unexpected || ! $cleaned ) {
			return $this->external_package_recovery_error( $kind, $state, $cleaned );
		}

		if ( ! is_wp_error( $result ) ) {
			try {
				$this->log->record( 'wp-native-builder/extension-lifecycle', $kind, 0, true, '' );
			} catch ( \Throwable $error ) {
				return $this->external_package_recovery_error( $kind, $state, $cleaned );
			}
		}

		return $result;
	}

	/**
	 * Runs the bounded external package download and Core install lifecycle.
	 *
	 * @param string              $kind  Extension kind.
	 * @param array<string,mixed> $input Ability input.
	 * @param array<string,mixed> $state Invocation-owned state.
	 * @return array<string,mixed>|WP_Error
	 */
	private function install_external_package_checked( $kind, $input, &$state ) {
		$url = isset( $input['package_url'] ) && is_string( $input['package_url'] ) ? $input['package_url'] : '';
		if ( ! $this->can_mutate( $input ) ) {
			return $this->external_package_error( $kind, 'external_package_permission_denied', __( 'External package installation requires Code & Extensions, External Packages, and the native WordPress install capability.', 'wp-native-builder-bridge' ) );
		}
		if ( '' === $url || strlen( $url ) > 8192 || preg_match( '/[\x00-\x20\x7f]/', $url ) || ! $this->is_safe_package_destination( $url ) ) {
			return $this->external_package_error( $kind, 'unsafe_external_package_url', __( 'WordPress did not accept the external package URL as a safe public HTTPS destination.', 'wp-native-builder-bridge' ) );
		}

		$max_bytes = min( (int) wp_max_upload_size(), self::ABSOLUTE_MAX_PACKAGE_BYTES );
		if ( $max_bytes < 1 ) {
			return $this->external_package_error( $kind, 'external_package_limit_unavailable', __( 'WordPress must provide a finite positive upload limit before an external package can be installed.', 'wp-native-builder-bridge' ) );
		}

		$this->load_external_package_files();
		$temp_file          = wp_tempnam( 'wp-ai-bridge-package.zip' );
		$state['temp_file'] = $temp_file ? (string) $temp_file : '';
		if ( ! $temp_file ) {
			return $this->external_package_error( $kind, 'external_package_temp_failed', __( 'WordPress could not allocate a temporary upload file.', 'wp-native-builder-bridge' ) );
		}
		if ( ! $this->can_mutate( $input ) ) {
			return $this->external_package_error( $kind, 'external_package_permission_denied', __( 'External package installation requires Code & Extensions, External Packages, and the native WordPress install capability.', 'wp-native-builder-bridge' ) );
		}

		$redirect_failure = '';
		$redirect_guard   = function ( $location, $headers, $data, $options ) use ( $temp_file, $input, &$redirect_failure ) {
			if ( ( $options['filename'] ?? null ) !== $temp_file ) {
				return;
			}
			if ( ! $this->can_mutate( $input ) ) {
				$redirect_failure = 'permission';
			} elseif ( ! $this->is_safe_package_destination( $location ) ) {
				$redirect_failure = 'destination';
			}
			if ( '' !== $redirect_failure ) {
				throw new \WpOrg\Requests\Exception( 'The external package redirect was refused.', 'wpnb_external_package_redirect_refused' );
			}
		};
		add_action( 'requests-requests.before_redirect', $redirect_guard, PHP_INT_MAX, 4 );
		try {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 30,
					'redirection'         => 5,
					'sslverify'           => true,
					'stream'              => true,
					'filename'            => $temp_file,
					'limit_response_size' => $max_bytes + 1,
					'decompress'          => false,
					'headers'             => array( 'Accept-Encoding' => 'identity' ),
					'cookies'             => array(),
				)
			);
		} finally {
			remove_action( 'requests-requests.before_redirect', $redirect_guard, PHP_INT_MAX );
		}

		if ( 'permission' === $redirect_failure ) {
			return $this->external_package_error( $kind, 'external_package_permission_denied', __( 'External package installation requires Code & Extensions, External Packages, and the native WordPress install capability.', 'wp-native-builder-bridge' ) );
		}
		if ( 'destination' === $redirect_failure ) {
			return $this->external_package_error( $kind, 'unsafe_external_package_url', __( 'WordPress did not accept the external package URL as a safe public HTTPS destination.', 'wp-native-builder-bridge' ) );
		}
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->external_package_error( $kind, 'external_package_http_failed', __( 'WordPress could not download a complete external package response.', 'wp-native-builder-bridge' ) );
		}

		clearstatcache( true, $temp_file );
		$bytes  = is_file( $temp_file ) ? filesize( $temp_file ) : false;
		$length = wp_remote_retrieve_header( $response, 'content-length' );
		if ( false === $bytes || $bytes < 1 || $bytes > $max_bytes ) {
			return $this->external_package_error( $kind, 'external_package_size_invalid', __( 'The downloaded external package is empty or exceeds the permitted package size.', 'wp-native-builder-bridge' ) );
		}
		if ( '' !== $length && ( ! is_scalar( $length ) || ! ctype_digit( (string) $length ) || ltrim( (string) $length, '0' ) !== (string) $bytes ) ) {
			return $this->external_package_error( $kind, 'external_package_incomplete', __( 'The downloaded external package length does not match the complete response.', 'wp-native-builder-bridge' ) );
		}
		if ( ! $this->can_mutate( $input ) ) {
			return $this->external_package_error( $kind, 'external_package_permission_denied', __( 'External package installation requires Code & Extensions, External Packages, and the native WordPress install capability.', 'wp-native-builder-bridge' ) );
		}

		$state['install_started'] = true;
		$skin                     = new \Automatic_Upgrader_Skin();
		if ( 'plugin' === $kind ) {
			$upgrader = new \Plugin_Upgrader( $skin );
			$ok       = $upgrader->install( $temp_file );
			$target   = $upgrader->plugin_info();
		} else {
			$upgrader = new \Theme_Upgrader( $skin );
			$ok       = $upgrader->install( $temp_file );
			$theme    = $upgrader->theme_info();
			$target   = is_object( $theme ) && method_exists( $theme, 'get_stylesheet' ) ? $theme->get_stylesheet() : '';
		}

		if ( is_wp_error( $ok ) || ! $ok ) {
			// Once Core installation begins, a failure can leave extension state that this Bridge cannot prove absent.
			throw new \RuntimeException( 'Core package installation did not complete with a verifiable success state.' );
		}
		if ( ! is_string( $target ) || '' === $target ) {
			throw new \RuntimeException( 'Installed extension identity was unavailable after Core reported success.' );
		}

		$state['installed_target'] = $target;
		return array(
			'kind'                              => $kind,
			'action'                            => 'install',
			'target'                            => $target,
			'success'                           => true,
			'requires_manual_filesystem_access' => false,
		);
	}

	/**
	 * Rejects unsafe/private destinations and restricts external packages to HTTPS.
	 *
	 * @param string $url Initial or redirected package URL.
	 * @return bool
	 */
	private function is_safe_package_destination( $url ) {
		if ( ! is_string( $url ) || strlen( $url ) > 8192 || preg_match( '/[\x00-\x20\x7f]/', $url ) || ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$host = rtrim( (string) $parts['host'], '.' );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$addresses = array( $host );
		} else {
			$addresses = gethostbynamel( $host );
			$records   = dns_get_record( $host, DNS_A | DNS_AAAA );
			if ( ! is_array( $addresses ) || empty( $addresses ) || ! is_array( $records ) ) {
				return false;
			}
			foreach ( $records as $record ) {
				if ( isset( $record['ip'] ) ) {
					$addresses[] = $record['ip'];
				}
				if ( isset( $record['ipv6'] ) ) {
					$addresses[] = $record['ipv6'];
				}
			}
		}
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if ( defined( 'FILTER_FLAG_GLOBAL_RANGE' ) ) {
			$flags |= FILTER_FLAG_GLOBAL_RANGE;
		}
		foreach ( array_unique( $addresses ) as $address ) {
			if ( ! filter_var( $address, FILTER_VALIDATE_IP, $flags ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Removes the invocation-owned package staging file and verifies retirement.
	 *
	 * @param array<string,mixed> $state Invocation state.
	 * @return bool
	 */
	private function cleanup_external_package( $state ) {
		$path = isset( $state['temp_file'] ) ? (string) $state['temp_file'] : '';
		if ( '' === $path ) {
			return true;
		}
		clearstatcache( true, $path );
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
			clearstatcache( true, $path );
		}
		return ! is_file( $path );
	}

	/**
	 * Creates a fixed redacted external-package error and best-effort audit entry.
	 *
	 * @param string $kind    Extension kind.
	 * @param string $code    Fixed Bridge error code.
	 * @param string $message Fixed safe message.
	 * @return WP_Error
	 */
	private function external_package_error( $kind, $code, $message ) {
		try {
			$this->log->record( 'wp-native-builder/extension-lifecycle', $kind, 0, false, $code );
		} catch ( \Throwable $error ) {
			// Error reporting must never disclose downstream package diagnostics.
		}
		return new WP_Error( $code, $message );
	}

	/**
	 * Returns bounded recovery information after uncertain install/cleanup state.
	 *
	 * @param string              $kind    Extension kind.
	 * @param array<string,mixed> $state   Invocation state.
	 * @param bool                $cleaned Known staging cleanup completion.
	 * @return WP_Error
	 */
	private function external_package_recovery_error( $kind, $state, $cleaned ) {
		try {
			$this->log->record( 'wp-native-builder/extension-lifecycle', $kind, 0, false, 'external_package_recovery_required' );
		} catch ( \Throwable $error ) {
			// Recovery reporting must remain independent of audit availability.
		}
		return new WP_Error(
			'external_package_recovery_required',
			__( 'The external package install could not finish safely. Inspect installed extensions and temporary storage before retrying.', 'wp-native-builder-bridge' ),
			array(
				'kind'                   => $kind,
				'install_state'          => ! empty( $state['installed_target'] ) ? 'installed' : ( ! empty( $state['install_started'] ) ? 'unconfirmed' : 'not_started' ),
				'installed_target'       => isset( $state['installed_target'] ) ? (string) $state['installed_target'] : '',
				'known_cleanup_complete' => (bool) $cleaned,
			)
		);
	}

	/** Loads the helpers used only by the external-package path. */
	private function load_external_package_files() {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'wp_get_theme' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		if ( ! class_exists( '\Plugin_Upgrader', false ) || ! class_exists( '\Theme_Upgrader', false ) || ! class_exists( '\Automatic_Upgrader_Skin', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
	}

	/** @param string $action Action. @param string $plugin Plugin. @return string|WP_Error */
	private function mutate_plugin( $action, $plugin ) {
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) {
			return new WP_Error( 'plugin_not_found', __( 'The installed plugin target was not found.', 'wp-native-builder-bridge' ) );
		}
		if ( 'activate' === $action ) {
			$result = activate_plugin( $plugin );
			return is_wp_error( $result ) ? $result : $plugin;
		}
		if ( 'deactivate' === $action ) {
			deactivate_plugins( $plugin );
			return is_plugin_active( $plugin ) ? new WP_Error( 'plugin_deactivate_failed', __( 'WordPress did not deactivate the plugin.', 'wp-native-builder-bridge' ) ) : $plugin;
		}
		if ( 'update' === $action ) {
			wp_update_plugins();
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->upgrade( $plugin );
			return is_wp_error( $result ) ? $result : ( $result ? $plugin : new WP_Error( 'plugin_update_unavailable', __( 'No applicable plugin update was installed.', 'wp-native-builder-bridge' ) ) );
		}
		if ( is_plugin_active( $plugin ) ) {
			return new WP_Error( 'plugin_must_be_inactive', __( 'Deactivate the plugin before deleting it.', 'wp-native-builder-bridge' ) );
		}
		$result = delete_plugins( array( $plugin ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( null === $result ) {
			return new WP_Error( 'filesystem_access_required', __( 'WordPress requires manual filesystem credentials or access for this plugin deletion.', 'wp-native-builder-bridge' ) );
		}
		return true === $result ? $plugin : new WP_Error( 'plugin_delete_failed', __( 'WordPress did not delete the plugin.', 'wp-native-builder-bridge' ) );
	}

	/** @param string $action Action. @param string $stylesheet Stylesheet. @return string|WP_Error */
	private function mutate_theme( $action, $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'theme_not_found', __( 'The installed theme target was not found.', 'wp-native-builder-bridge' ) );
		}
		if ( 'activate' === $action ) {
			$requirements = validate_theme_requirements( $stylesheet );
			if ( is_wp_error( $requirements ) ) {
				return $requirements;
			}
			switch_theme( $stylesheet );
			return get_stylesheet() === $stylesheet ? $stylesheet : new WP_Error( 'theme_switch_failed', __( 'WordPress did not activate the theme.', 'wp-native-builder-bridge' ) );
		}
		if ( 'update' === $action ) {
			wp_update_themes();
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Theme_Upgrader( $skin );
			$result   = $upgrader->upgrade( $stylesheet );
			return is_wp_error( $result ) ? $result : ( $result ? $stylesheet : new WP_Error( 'theme_update_unavailable', __( 'No applicable theme update was installed.', 'wp-native-builder-bridge' ) ) );
		}
		if ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ) {
			return new WP_Error( 'active_theme_delete_denied', __( 'Activate another theme before deleting the current theme or its parent.', 'wp-native-builder-bridge' ) );
		}
		$result = delete_theme( $stylesheet );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( null === $result ) {
			return new WP_Error( 'filesystem_access_required', __( 'WordPress requires manual filesystem credentials or access for this theme deletion.', 'wp-native-builder-bridge' ) );
		}
		return true === $result ? $stylesheet : new WP_Error( 'theme_delete_failed', __( 'WordPress did not delete the theme.', 'wp-native-builder-bridge' ) );
	}

	/** @param mixed $filesystem Filesystem. @return WP_Error */
	private function filesystem_error( $filesystem ) {
		if ( is_object( $filesystem ) && isset( $filesystem->errors ) && is_wp_error( $filesystem->errors ) && $filesystem->errors->has_errors() ) {
			return new WP_Error( 'filesystem_access_required', $filesystem->errors->get_error_message() );
		}
		return new WP_Error( 'filesystem_access_required', __( 'WordPress could not obtain non-interactive filesystem access. Complete filesystem setup manually and retry.', 'wp-native-builder-bridge' ) );
	}

	/** @param bool $upgrader Include upgrader/install APIs. @return void */
	private function load_admin_files( $upgrader ) {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		if ( $upgrader ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
	}

	/** @return array<int,array<string,mixed>> */
	private function plugins() {
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
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	private function themes() {
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
		}
		return $out;
	}

	/** @param string $kind Kind. @param string $action Action. @return string */
	private function capability( $kind, $action ) {
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
		return isset( $map[ $action ] ) ? $map[ $action ] : 'do_not_allow';
	}

	/** @param string $kind Kind. @return array<int,string> */
	private function actions_for( $kind ) {
		return 'plugin' === $kind ? array( 'install', 'update', 'activate', 'deactivate', 'delete' ) : array( 'install', 'update', 'activate', 'delete' );
	}

	/** @return array<string,mixed> */
	private function mutate_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'        => array(
					'type' => 'string',
					'enum' => array( 'plugin', 'theme' ),
				),
				'action'      => array(
					'type' => 'string',
					'enum' => array( 'install', 'update', 'activate', 'deactivate', 'delete' ),
				),
				'target'      => array(
					'type'      => 'string',
					'maxLength' => 300,
				),
				'slug'        => array(
					'type'      => 'string',
					'maxLength' => 200,
					'pattern'   => '^[a-z0-9-]+$',
				),
				'package_url' => array(
					'type'      => 'string',
					'maxLength' => 8192,
				),
			),
			'required'             => array( 'kind', 'action' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function read_output_schema() {
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
		);
	}

	/** @param bool $is_readonly Read-only. @param bool $destructive D. @param bool $idempotent I. @return array<string,mixed> */
	private function meta( $is_readonly, $destructive, $idempotent ) {
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
		);
	}
}
