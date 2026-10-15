<?php
/**
 * WordPress plugin/theme lifecycle abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Extension_Install_Lock;
use WP_AI_Bridge\Support\Extension_Package_Inspector;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Uses supported WordPress administration APIs; never edits extension files directly.
 */
final class Extension_Abilities {

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
			'wp-ai-bridge/extensions-read',
			array(
				'label'               => __( 'Read Plugins and Themes', 'wp-ai-bridge' ),
				'description'         => __( 'Lists installed WordPress plugins and themes with bounded lifecycle state.', 'wp-ai-bridge' ),
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
			'wp-ai-bridge/extension-lifecycle',
			array(
				'label'               => __( 'Manage Plugin or Theme Lifecycle', 'wp-ai-bridge' ),
				'description'         => __( 'Installs WordPress.org extensions or authorized public HTTPS packages and supports explicitly confirmed, identity-checked replacement of an installed extension from a public HTTPS ZIP. Existing update offers remain supported; deletion requires destructive access.', 'wp-ai-bridge' ),
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
		if ( ! empty( $input['package_url'] ) && in_array( $action, array( 'install', 'update' ), true ) ) {
			// Explicit package replacement requires both the update permission and
			// the same native install authority as a WordPress-admin ZIP upload.
			return $this->permissions->allowed( Settings::GROUP_EXTERNAL_PACKAGES, $this->capability( $kind, 'install' ) );
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
			return new WP_Error( 'invalid_extension_kind', __( 'Extension kind must be plugin or theme.', 'wp-ai-bridge' ) );
		}
		if ( ! in_array( $action, $this->actions_for( $kind ), true ) ) {
			return new WP_Error( 'invalid_extension_action', __( 'That lifecycle action is not supported for the selected extension kind.', 'wp-ai-bridge' ) );
		}

		if ( 'install' === $action ) {
			if ( isset( $input['expected_sha256'], $input['expected_installed_version'] ) || isset( $input['confirm_replace'] ) || isset( $input['allow_non_upgrade'] ) || isset( $input['expected_package_version'] ) ) {
				return new WP_Error( 'extension_replace_arguments_not_applicable', __( 'Replacement confirmation and identities cannot be used for a new installation.', 'wp-ai-bridge' ) );
			}
			$has_slug    = '' !== $slug;
			$package_url = isset( $input['package_url'] ) && is_string( $input['package_url'] ) ? $input['package_url'] : '';
			$has_package = '' !== $package_url;
			if ( ! $has_slug && ! $has_package ) {
				return new WP_Error( 'extension_slug_required', __( 'A WordPress.org slug or an external package URL is required for installation.', 'wp-ai-bridge' ) );
			}
			if ( $has_slug && $has_package ) {
				return new WP_Error( 'extension_install_source_required', __( 'Choose exactly one install source: a WordPress.org slug or an external package URL.', 'wp-ai-bridge' ) );
			}
			if ( $has_package ) {
				return $this->install_external_package( $kind, $input );
			}
			return $this->install( $kind, $slug );
		}

		if ( 'update' === $action && ! empty( $input['package_url'] ) ) {
			if ( isset( $input['slug'] ) ) {
				return new WP_Error( 'extension_install_source_not_applicable', __( 'A replacement ZIP must identify the installed target, not a WordPress.org slug.', 'wp-ai-bridge' ) );
			}
			return $this->install_external_package( $kind, $input );
		}
		if ( isset( $input['slug'] ) || isset( $input['package_url'] ) || isset( $input['expected_sha256'] ) || isset( $input['expected_installed_version'] ) || isset( $input['expected_package_version'] ) || isset( $input['confirm_replace'] ) || isset( $input['allow_non_upgrade'] ) ) {
			return new WP_Error( 'extension_install_source_not_applicable', __( 'Package source and replacement fields require an explicit install or package-update action.', 'wp-ai-bridge' ) );
		}
		if ( '' === $target ) {
			return new WP_Error( 'extension_target_required', __( 'An installed plugin file or theme stylesheet is required.', 'wp-ai-bridge' ) );
		}

		$this->load_admin_files( true );
		// Core updates/deletes mutate the same extension directories as installs.
		// Cover those overlapping Bridge-owned filesystem transitions as well.
		$lock = in_array( $action, array( 'update', 'delete' ), true ) ? new Extension_Install_Lock() : null;
		if ( $lock && ! $lock->acquire() ) {
			return new WP_Error( 'extension_install_busy', __( 'Another extension installation is in progress. Retry this request after it finishes.', 'wp-ai-bridge' ) );
		}
		try {
			if ( $lock && ( ! $this->can_mutate( $input ) || ! $lock->is_owned() ) ) {
				return new WP_Error( 'extension_permission_denied', __( 'Extension installation authorization is unavailable.', 'wp-ai-bridge' ) );
			}
			$result = 'plugin' === $kind ? $this->mutate_plugin( $action, $target ) : $this->mutate_theme( $action, $target );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( $lock && ! $lock->is_owned() ) {
				return new WP_Error( 'extension_install_recovery_required', __( 'Extension installation needs administrator recovery before a retry.', 'wp-ai-bridge' ) );
			}
		} finally {
			if ( $lock ) {
				$lock->release();
			}
		}
		$this->log->record( 'wp-ai-bridge/extension-lifecycle', $kind, 0, true, '' );
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
		$lock = new Extension_Install_Lock();
		if ( ! $lock->acquire() ) {
			return new WP_Error( 'extension_install_busy', __( 'Another extension installation is in progress. Retry this request after it finishes.', 'wp-ai-bridge' ) );
		}
		try {
			if ( ! $this->can_mutate(
				array(
					'kind'   => $kind,
					'action' => 'install',
					'slug'   => $slug,
				)
			) ) {
				return new WP_Error( 'extension_permission_denied', __( 'Extension installation authorization is unavailable.', 'wp-ai-bridge' ) );
			}
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
					return new WP_Error( 'plugin_package_missing', __( 'WordPress.org did not return an install package for that plugin slug.', 'wp-ai-bridge' ) );
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
					return new WP_Error( 'theme_package_missing', __( 'WordPress.org did not return an install package for that theme slug.', 'wp-ai-bridge' ) );
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
			if ( ! $lock->is_owned() ) {
				return new WP_Error( 'extension_install_recovery_required', __( 'Extension installation needs administrator recovery before a retry.', 'wp-ai-bridge' ) );
			}
			$this->log->record( 'wp-ai-bridge/extension-lifecycle', $kind, 0, true, '' );
			return array(
				'kind'                              => $kind,
				'action'                            => 'install',
				'target'                            => (string) $target,
				'success'                           => true,
				'requires_manual_filesystem_access' => false,
			);
		} finally {
			$lock->release();
		}
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
				$this->log->record( 'wp-ai-bridge/extension-lifecycle', $kind, 0, true, '' );
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
		$url         = isset( $input['package_url'] ) && is_string( $input['package_url'] ) ? $input['package_url'] : '';
		$replacement = 'update' === ( $input['action'] ?? '' );
		if ( ! $this->can_mutate( $input ) ) {
			return $this->external_package_error( $kind, 'external_package_permission_denied', __( 'External packages require Code & Extensions, External Packages and the native WordPress install/update capabilities.', 'wp-ai-bridge' ) );
		}
		$this->load_external_package_files();
		if ( $replacement ) {
			$preflight = $this->preflight_replacement( $kind, $input );
			if ( is_wp_error( $preflight ) ) {
				return $preflight;
			}
		}
		if ( '' === $url || strlen( $url ) > 8192 || preg_match( '/[\x00-\x20\x7f]/', $url ) || ! $this->is_safe_package_destination( $url ) ) {
			return $this->external_package_error( $kind, 'unsafe_external_package_url', __( 'WordPress did not accept the external package URL as a safe public HTTPS destination.', 'wp-ai-bridge' ) );
		}

		$max_bytes = ( new \WP_AI_Bridge\Support\External_Package_Limits() )->effective_bytes();
		if ( $max_bytes < 1 ) {
			return $this->external_package_error( $kind, 'external_package_limit_unavailable', __( 'WordPress must provide a finite positive upload limit before an external package can be installed.', 'wp-ai-bridge' ) );
		}

		$this->load_external_package_files();
		$temp_file          = wp_tempnam( 'wp-ai-bridge-package.zip' );
		$state['temp_file'] = $temp_file ? (string) $temp_file : '';
		if ( ! $temp_file ) {
			return $this->external_package_error( $kind, 'external_package_temp_failed', __( 'WordPress could not allocate a temporary upload file.', 'wp-ai-bridge' ) );
		}
		if ( ! $this->can_mutate( $input ) ) {
			return $this->external_package_error( $kind, 'external_package_permission_denied', __( 'External package installation requires Code & Extensions, External Packages, and the native WordPress install capability.', 'wp-ai-bridge' ) );
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
				throw new \WpOrg\Requests\Exception( 'The external package redirect was refused.', 'wpai_external_package_redirect_refused' );
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
			return $this->external_package_error( $kind, 'external_package_permission_denied', __( 'External package installation requires Code & Extensions, External Packages, and the native WordPress install capability.', 'wp-ai-bridge' ) );
		}
		if ( 'destination' === $redirect_failure ) {
			return $this->external_package_error( $kind, 'unsafe_external_package_url', __( 'WordPress did not accept the external package URL as a safe public HTTPS destination.', 'wp-ai-bridge' ) );
		}
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->external_package_error( $kind, 'external_package_http_failed', __( 'WordPress could not download a complete external package response.', 'wp-ai-bridge' ) );
		}

		clearstatcache( true, $temp_file );
		$bytes  = is_file( $temp_file ) ? filesize( $temp_file ) : false;
		$length = wp_remote_retrieve_header( $response, 'content-length' );
		if ( false === $bytes || $bytes < 1 || $bytes > $max_bytes ) {
			return $this->external_package_error( $kind, 'external_package_size_invalid', __( 'The downloaded external package is empty or exceeds the permitted package size.', 'wp-ai-bridge' ) );
		}
		if ( '' !== $length && ( ! is_scalar( $length ) || ! ctype_digit( (string) $length ) || ltrim( (string) $length, '0' ) !== (string) $bytes ) ) {
			return $this->external_package_error( $kind, 'external_package_incomplete', __( 'The downloaded external package length does not match the complete response.', 'wp-ai-bridge' ) );
		}
		if ( $replacement ) {
			$download_hash = hash_file( 'sha256', $temp_file );
			if ( ! is_string( $download_hash ) || ! hash_equals( strtolower( (string) $input['expected_sha256'] ), $download_hash ) ) {
				return $this->external_package_error( $kind, 'external_package_hash_mismatch', __( 'The downloaded ZIP does not match the explicitly approved SHA-256.', 'wp-ai-bridge' ) );
			}
			$inspected = ( new Extension_Package_Inspector() )->inspect( $temp_file, $kind, (string) $input['target'], (string) $input['expected_package_version'], $max_bytes );
			if ( is_wp_error( $inspected ) ) {
				return $this->external_package_error( $kind, $inspected->get_error_code(), __( 'The replacement archive identity or safety inspection failed.', 'wp-ai-bridge' ) );
			}
		}
		if ( ! $this->can_mutate( $input ) ) {
			return $this->external_package_error( $kind, 'external_package_permission_denied', __( 'External package authority was revoked before the native installer.', 'wp-ai-bridge' ) );
		}

		$lock = new Extension_Install_Lock();
		if ( ! $lock->acquire() ) {
			return $this->external_package_error( $kind, 'external_package_busy', __( 'Another extension installation is in progress. Retry this request after it finishes.', 'wp-ai-bridge' ) );
		}
		try {
			if ( ! $this->can_mutate( $input ) || ! $lock->is_owned() ) {
				return $this->external_package_error( $kind, 'external_package_permission_denied', __( 'External package installation requires Code & Extensions, External Packages, and the native WordPress install capability.', 'wp-ai-bridge' ) );
			}
			if ( $replacement ) {
				// Recheck the exact installed preimage while holding the shared,
				// lock-coherent WordPress content filesystem admission lock.
				$current = $this->preflight_replacement( $kind, $input );
				if ( is_wp_error( $current ) ) {
					return $current;
				}
			}
			$state['install_started'] = true;
			$skin                     = new \Automatic_Upgrader_Skin();
			$args                     = $replacement ? array( 'overwrite_package' => true ) : array();
			if ( 'plugin' === $kind ) {
				$upgrader = new \Plugin_Upgrader( $skin );
				$ok       = $upgrader->install( $temp_file, $args );
				$target   = $upgrader->plugin_info();
			} else {
				$upgrader = new \Theme_Upgrader( $skin );
				$ok       = $upgrader->install( $temp_file, $args );
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

			if ( ! $lock->is_owned() ) {
				throw new \RuntimeException( 'Native Core install lock was lost before durable outcome.' );
			}
			if ( $replacement ) {
				if ( ! hash_equals( (string) $input['target'], $target ) ) {
					throw new \RuntimeException( 'The upgrader returned a different installed extension identity.' );
				}
				if ( 'plugin' === $kind ) {
					wp_clean_plugins_cache( true );
					$installed      = get_plugins();
					$actual_version = (string) ( $installed[ $target ]['Version'] ?? '' );
					$active         = is_plugin_active( $target );
					$network        = is_multisite() && is_plugin_active_for_network( $target );
					if ( ! hash_equals( (string) $input['expected_package_version'], $actual_version )
						|| $active !== $current['active'] || $network !== $current['network_active'] ) {
						throw new \RuntimeException( 'Plugin identity, version or activation state changed unexpectedly.' );
					}
				} else {
					wp_clean_themes_cache( true );
					$installed = wp_get_theme( $target );
					if ( ! $installed->exists()
						|| ! hash_equals( (string) $input['expected_package_version'], (string) $installed->get( 'Version' ) )
						|| get_stylesheet() !== $current['active_stylesheet']
						|| get_template() !== $current['active_template'] ) {
						throw new \RuntimeException( 'Theme identity, version or activation state changed unexpectedly.' );
					}
				}
			}
			$state['installed_target'] = $target;
			return array(
				'kind'                              => $kind,
				'action'                            => $replacement ? 'update' : 'install',
				'target'                            => $target,
				'success'                           => true,
				'requires_manual_filesystem_access' => false,
			);
		} finally {
			$lock->release();
		}
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
	 * Validates a single explicit existing-extension replacement preimage.
	 *
	 * @param string              $kind  Plugin or theme.
	 * @param array<string,mixed> $input Administrator-bound confirmation.
	 * @return array<string,mixed>|WP_Error
	 */
	private function preflight_replacement( $kind, $input ) {
		$target        = isset( $input['target'] ) && is_string( $input['target'] ) ? $input['target'] : '';
		$plugin_target = 'plugin' === $kind && 1 === preg_match( '/\\A[a-zA-Z0-9][a-zA-Z0-9._-]*\\/[a-zA-Z0-9][a-zA-Z0-9._-]*\\.php\\z/', $target );
		$theme_target  = 'theme' === $kind && 1 === preg_match( '/\\A[a-zA-Z0-9][a-zA-Z0-9._-]*\\z/', $target );
		if ( ( ! $plugin_target && ! $theme_target ) || true !== ( $input['confirm_replace'] ?? null )
			|| ! is_string( $input['expected_sha256'] ?? null ) || 1 !== preg_match( '/\\A[a-fA-F0-9]{64}\\z/', $input['expected_sha256'] )
			|| ! is_string( $input['expected_installed_version'] ?? null ) || ! is_string( $input['expected_package_version'] ?? null ) ) {
			return $this->external_package_error( $kind, 'external_package_confirmation_required', __( 'An exact installed extension, two versions, SHA-256 and explicit replacement confirmation are required.', 'wp-ai-bridge' ) );
		}
		$before = (string) $input['expected_installed_version'];
		$after  = (string) $input['expected_package_version'];
		if ( ! preg_match( '/\\A[0-9A-Za-z][0-9A-Za-z._+-]{0,63}\\z/', $before ) || ! preg_match( '/\\A[0-9A-Za-z][0-9A-Za-z._+-]{0,63}\\z/', $after ) ) {
			return $this->external_package_error( $kind, 'external_package_confirmation_required', __( 'Expected extension versions are invalid.', 'wp-ai-bridge' ) );
		}
		if ( ! version_compare( $after, $before, '>' ) && true !== ( $input['allow_non_upgrade'] ?? false ) ) {
			return $this->external_package_error( $kind, 'external_package_downgrade_denied', __( 'Replacing with the same or an older version requires separate explicit approval.', 'wp-ai-bridge' ) );
		}
		if ( 'plugin' === $kind ) {
			$installed = get_plugins();
			if ( ! isset( $installed[ $target ] ) ) {
				return $this->external_package_error( $kind, 'external_package_target_missing', __( 'The exact installed plugin does not exist.', 'wp-ai-bridge' ) );
			}
			$version = (string) $installed[ $target ]['Version'];
			$state   = array(
				'active'         => is_plugin_active( $target ),
				'network_active' => is_multisite() && is_plugin_active_for_network( $target ),
			);
		} else {
			$installed = wp_get_theme( $target );
			if ( ! $installed->exists() ) {
				return $this->external_package_error( $kind, 'external_package_target_missing', __( 'The exact installed theme does not exist.', 'wp-ai-bridge' ) );
			}
			$version = (string) $installed->get( 'Version' );
			$state   = array(
				'active_stylesheet' => get_stylesheet(),
				'active_template'   => get_template(),
			);
		}
		if ( ! hash_equals( $before, $version ) ) {
			return $this->external_package_error( $kind, 'external_package_target_stale', __( 'The installed extension version changed; inspect it before replacing.', 'wp-ai-bridge' ) );
		}
		// Core's direct filesystem deletion can descend into installed
		// symlinked directories. Never authorize overwrite of a linked tree.
		if ( ! $this->safe_installed_extension_tree( $kind, $target ) ) {
			return $this->external_package_error( $kind, 'external_package_unsafe_installed_tree', __( 'The installed extension contains a symbolic link or an uninspectable filesystem tree; replace it manually after checking the deployment.', 'wp-ai-bridge' ) );
		}
		return $state;
	}

	/**
	 * Fail closed when the existing extension contains links or an unreadable tree.
	 *
	 * WordPress Core's direct filesystem upgrader recursively deletes an
	 * overwritten directory. A preexisting directory symlink can make that
	 * deletion cross the installed extension's trusted root. This is rechecked
	 * with the Bridge lock immediately before Core starts the overwrite.
	 *
	 * @param string $kind Plugin or theme.
	 * @param string $target Exact installed identifier.
	 * @return bool
	 */
	private function safe_installed_extension_tree( $kind, $target ) {
		$parent = 'plugin' === $kind ? WP_PLUGIN_DIR : get_theme_root( $target );
		$folder = 'plugin' === $kind ? dirname( $target ) : $target;
		$root   = rtrim( $parent, '/\\' ) . '/' . $folder;
		if ( is_link( $root ) || ! is_dir( $root ) ) {
			return false;
		}
		try {
			$walk    = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			$entries = 0;
			foreach ( $walk as $node ) {
				if ( $node->isLink() || ++$entries > 50000 ) {
					return false;
				}
			}
		} catch ( \Throwable $error ) {
			return false;
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
			$this->log->record( 'wp-ai-bridge/extension-lifecycle', $kind, 0, false, $code );
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
			$this->log->record( 'wp-ai-bridge/extension-lifecycle', $kind, 0, false, 'external_package_recovery_required' );
		} catch ( \Throwable $error ) {
			// Recovery reporting must remain independent of audit availability.
		}
		return new WP_Error(
			'external_package_recovery_required',
			__( 'The external package install could not finish safely. Inspect installed extensions and temporary storage before retrying.', 'wp-ai-bridge' ),
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
			return new WP_Error( 'plugin_not_found', __( 'The installed plugin target was not found.', 'wp-ai-bridge' ) );
		}
		if ( 'activate' === $action ) {
			$result = activate_plugin( $plugin );
			return is_wp_error( $result ) ? $result : $plugin;
		}
		if ( 'deactivate' === $action ) {
			deactivate_plugins( $plugin );
			return is_plugin_active( $plugin ) ? new WP_Error( 'plugin_deactivate_failed', __( 'WordPress did not deactivate the plugin.', 'wp-ai-bridge' ) ) : $plugin;
		}
		if ( 'update' === $action ) {
			wp_update_plugins();
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->upgrade( $plugin );
			return is_wp_error( $result ) ? $result : ( $result ? $plugin : new WP_Error( 'plugin_update_unavailable', __( 'No applicable plugin update was installed.', 'wp-ai-bridge' ) ) );
		}
		if ( is_plugin_active( $plugin ) ) {
			return new WP_Error( 'plugin_must_be_inactive', __( 'Deactivate the plugin before deleting it.', 'wp-ai-bridge' ) );
		}
		$result = delete_plugins( array( $plugin ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( null === $result ) {
			return new WP_Error( 'filesystem_access_required', __( 'WordPress requires manual filesystem credentials or access for this plugin deletion.', 'wp-ai-bridge' ) );
		}
		return true === $result ? $plugin : new WP_Error( 'plugin_delete_failed', __( 'WordPress did not delete the plugin.', 'wp-ai-bridge' ) );
	}

	/** @param string $action Action. @param string $stylesheet Stylesheet. @return string|WP_Error */
	private function mutate_theme( $action, $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'theme_not_found', __( 'The installed theme target was not found.', 'wp-ai-bridge' ) );
		}
		if ( 'activate' === $action ) {
			$requirements = validate_theme_requirements( $stylesheet );
			if ( is_wp_error( $requirements ) ) {
				return $requirements;
			}
			switch_theme( $stylesheet );
			return get_stylesheet() === $stylesheet ? $stylesheet : new WP_Error( 'theme_switch_failed', __( 'WordPress did not activate the theme.', 'wp-ai-bridge' ) );
		}
		if ( 'update' === $action ) {
			wp_update_themes();
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Theme_Upgrader( $skin );
			$result   = $upgrader->upgrade( $stylesheet );
			return is_wp_error( $result ) ? $result : ( $result ? $stylesheet : new WP_Error( 'theme_update_unavailable', __( 'No applicable theme update was installed.', 'wp-ai-bridge' ) ) );
		}
		if ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ) {
			return new WP_Error( 'active_theme_delete_denied', __( 'Activate another theme before deleting the current theme or its parent.', 'wp-ai-bridge' ) );
		}
		$result = delete_theme( $stylesheet );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( null === $result ) {
			return new WP_Error( 'filesystem_access_required', __( 'WordPress requires manual filesystem credentials or access for this theme deletion.', 'wp-ai-bridge' ) );
		}
		return true === $result ? $stylesheet : new WP_Error( 'theme_delete_failed', __( 'WordPress did not delete the theme.', 'wp-ai-bridge' ) );
	}

	/** @param mixed $filesystem Filesystem. @return WP_Error */
	private function filesystem_error( $filesystem ) {
		if ( is_object( $filesystem ) && isset( $filesystem->errors ) && is_wp_error( $filesystem->errors ) && $filesystem->errors->has_errors() ) {
			return new WP_Error( 'filesystem_access_required', $filesystem->errors->get_error_message() );
		}
		return new WP_Error( 'filesystem_access_required', __( 'WordPress could not obtain non-interactive filesystem access. Complete filesystem setup manually and retry.', 'wp-ai-bridge' ) );
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
				'kind'                       => array(
					'type' => 'string',
					'enum' => array( 'plugin', 'theme' ),
				),
				'action'                     => array(
					'type' => 'string',
					'enum' => array( 'install', 'update', 'activate', 'deactivate', 'delete' ),
				),
				'target'                     => array(
					'type'      => 'string',
					'maxLength' => 300,
				),
				'slug'                       => array(
					'type'      => 'string',
					'maxLength' => 200,
					'pattern'   => '^[a-z0-9-]+$',
				),
				'package_url'                => array(
					'type'      => 'string',
					'maxLength' => 8192,
				),
				'expected_sha256'            => array(
					'type'    => 'string',
					'pattern' => '^[a-fA-F0-9]{64}$',
				),
				'expected_installed_version' => array(
					'type'      => 'string',
					'maxLength' => 64,
				),
				'expected_package_version'   => array(
					'type'      => 'string',
					'maxLength' => 64,
				),
				'confirm_replace'            => array( 'type' => 'boolean' ),
				'allow_non_upgrade'          => array( 'type' => 'boolean' ),
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
