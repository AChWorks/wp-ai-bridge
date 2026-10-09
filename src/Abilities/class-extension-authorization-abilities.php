<?php
/**
 * Read-only extension lifecycle authorization diagnostics.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Independent read-only provider; the package installer callback surface
 * remains entirely unchanged under the existing strict safety guard.
 */
final class Extension_Authorization_Abilities {
	/** @var Extension_Abilities */
	private $extensions;

	/** @var Permissions */
	private $permissions;

	/** @var Settings */
	private $settings;

	/**
	 * @param Extension_Abilities $extensions Existing authoritative lifecycle predicate.
	 * @param Permissions         $permissions Current WordPress + Bridge permission service.
	 * @param Settings            $settings Existing Bridge access-group settings.
	 */
	public function __construct( Extension_Abilities $extensions, Permissions $permissions, Settings $settings ) {
		$this->extensions = $extensions;
		$this->permissions = $permissions;
		$this->settings = $settings;
	}

	/** @return array<int,object> */
	public function register() {
		$registered = array();

		$registered[] = wp_register_ability(
			'wp-ai-bridge/extension-authorization',
			array(
				'label'               => __( 'Inspect Extension Authorization', 'wp-ai-bridge' ),
				'description'         => __( 'Checks current Bridge access groups and native WordPress capability for an exact plugin or theme lifecycle action without executing it. Target and environment remain unverified.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->authorization_input_schema(),
				'output_schema'       => $this->authorization_output_schema(),
				'execute_callback'    => array( $this, 'inspect_authorization' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta(),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool Current read-only diagnostic requires ordinary Site Read. */
	public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
	}

	/**
	 * Mirrors only the fixed Core action-to-capability lookup. The authoritative
	 * lifecycle permission decision is still checked by Extension_Abilities.
	 *
	 * @param string $kind Exact extension kind.
	 * @param string $action Existing lifecycle action.
	 * @return string Native WordPress capability.
	 */
	private function native_capability( $kind, $action ) {
		$plugin = array(
			'install'    => 'install_plugins',
			'update'     => 'update_plugins',
			'activate'   => 'activate_plugins',
			'deactivate' => 'activate_plugins',
			'delete'     => 'delete_plugins',
		);
		$theme = array(
			'install'  => 'install_themes',
			'update'   => 'update_themes',
			'activate' => 'switch_themes',
			'delete'   => 'delete_themes',
		);
		$map = 'plugin' === $kind ? $plugin : $theme;
		return isset( $map[ $action ] ) ? $map[ $action ] : 'do_not_allow';
	}

	/**
	 * @param string $kind Extension kind.
	 * @return array<int,string> Supported actions.
	 */
	private function actions_for( $kind ) {
		return 'plugin' === $kind
			? array( 'install', 'update', 'activate', 'deactivate', 'delete' )
			: array( 'install', 'update', 'activate', 'delete' );
	}

	/** @return array<string,mixed> */
	private function meta() {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * Read-only action-specific permission preflight. Never invokes the lifecycle action.
	 *
	 * The WordPress target, installation policy, filesystem, network, dependency
	 * and current upstream ability validation are deliberately NOT evaluated.
	 *
	 * @param array<string,mixed> $input Exact kind, action and optional installation source.
	 * @return array<string,mixed>|WP_Error Bounded authorization facts or typed denial.
	 */
	public function inspect_authorization( $input ) {
		if ( ! $this->can_read() ) {
			return new WP_Error( 'extension_authorization_read_denied', __( 'Site Read and the WordPress read capability are required to inspect extension authorization.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) ) {
			return $this->invalid_authorization_input();
		}
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, array( 'kind', 'action', 'install_source' ), true ) ) {
				return $this->invalid_authorization_input();
			}
		}
		if ( ! isset( $input['kind'], $input['action'] )
			|| ! is_string( $input['kind'] )
			|| ! is_string( $input['action'] ) ) {
			return $this->invalid_authorization_input();
		}

		$kind   = $input['kind'];
		$action = $input['action'];
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true )
			|| ! in_array( $action, $this->actions_for( $kind ), true ) ) {
			return $this->invalid_authorization_input();
		}

		$source = 'not_applicable';
		if ( 'install' === $action ) {
			$source = isset( $input['install_source'] ) ? $input['install_source'] : 'wordpress_org';
			if ( ! is_string( $source ) || ! in_array( $source, array( 'wordpress_org', 'public_https' ), true ) ) {
				return $this->invalid_authorization_input();
			}
		} elseif ( array_key_exists( 'install_source', $input ) ) {
			return $this->invalid_authorization_input();
		}

		$required_groups = array( Settings::GROUP_CODE_EXTENSIONS );
		if ( 'delete' === $action ) {
			$required_groups[] = Settings::GROUP_USERS_DESTRUCTIVE;
		}
		if ( 'public_https' === $source ) {
			$required_groups[] = Settings::GROUP_EXTERNAL_PACKAGES;
		}

		$settings      = $this->settings;
		$group_grants  = array();
		$bridge_allows = true;
		foreach ( $required_groups as $group ) {
			$group_grants[ $group ] = $settings->is_enabled( $group );
			$bridge_allows          = $bridge_allows && $group_grants[ $group ];
		}

		$capability    = $this->native_capability( $kind, $action );
		$native_allows = current_user_can( $capability );
		// Only the existing pure permission predicate is invoked. The package
		// sentinel selects its public-HTTPS delegation branch, NOT a download.
		$probe = array(
			'kind'   => $kind,
			'action' => $action,
		);
		if ( 'public_https' === $source ) {
			$probe['package_url'] = 'permission-check-only';
		}
		$permission_callback_allows = $this->extensions->can_mutate( $probe );

		$status = 'permission_preflight_passed';
		if ( ! $bridge_allows ) {
			$status = 'bridge_delegation_disabled';
		} elseif ( ! $native_allows ) {
			$status = 'native_authority_denied';
		}
		if ( ! $permission_callback_allows ) {
			// Unaccounted-for future authorization rules must never look passed.
			if ( 'permission_preflight_passed' === $status ) {
				$status = 'additional_authorization_denied';
			}
		}

		return array(
			'ability_name'               => 'wp-ai-bridge/extension-lifecycle',
			'kind'                       => $kind,
			'action'                     => $action,
			'install_source'             => $source,
			'required_groups'            => $required_groups,
			'group_grants'               => $group_grants,
			'native_capability'          => $capability,
			'native_capability_granted'  => $native_allows,
			'permission_callback_allows' => $permission_callback_allows,
			'status'                     => $status,
			'execution_permission'       => 'not_evaluated',
		);
	}

	/** @return WP_Error Validated input is required for one exact lifecycle action. */
	private function invalid_authorization_input() {
		return new WP_Error( 'invalid_extension_authorization_input', __( 'Choose one valid plugin or theme lifecycle action and, for installation only, a supported source.', 'wp-ai-bridge' ) );
	}

	/** @return array<string,mixed> */
	private function authorization_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'           => array(
					'type' => 'string',
					'enum' => array( 'plugin', 'theme' ),
				),
				'action'         => array(
					'type' => 'string',
					'enum' => array( 'install', 'update', 'activate', 'deactivate', 'delete' ),
				),
				'install_source' => array(
					'type' => 'string',
					'enum' => array( 'wordpress_org', 'public_https' ),
				),
			),
			'required'             => array( 'kind', 'action' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function authorization_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'ability_name'               => array( 'type' => 'string' ),
				'kind'                       => array( 'type' => 'string' ),
				'action'                     => array( 'type' => 'string' ),
				'install_source'             => array( 'type' => 'string' ),
				'required_groups'            => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'group_grants'               => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'boolean' ),
				),
				'native_capability'          => array( 'type' => 'string' ),
				'native_capability_granted'  => array( 'type' => 'boolean' ),
				'permission_callback_allows' => array( 'type' => 'boolean' ),
				'status'                     => array(
					'type' => 'string',
					'enum' => array( 'bridge_delegation_disabled', 'native_authority_denied', 'additional_authorization_denied', 'permission_preflight_passed' ),
				),
				'execution_permission'       => array(
					'type' => 'string',
					'enum' => array( 'not_evaluated' ),
				),
			),
			'required'             => array( 'ability_name', 'kind', 'action', 'install_source', 'required_groups', 'group_grants', 'native_capability', 'native_capability_granted', 'permission_callback_allows', 'status', 'execution_permission' ),
			'additionalProperties' => false,
		);
	}

}
