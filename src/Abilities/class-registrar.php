<?php
/**
 * Ability registration.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Environment;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Native_Ability_Delegation;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Native_Builder_Bridge\Workspace\Store;

/**
 * Registers bridge ability categories and providers.
 */
final class Registrar {
	const CATEGORY = 'wp-native-builder';

	/** @var Environment */
	private $environment;
	/** @var Settings */
	private $settings;
	/** @var Permissions */
	private $permissions;
	/** @var Ability_Resolver */
	private $resolver;
	/** @var Site_Abilities */
	private $site_abilities;
	/** @var Ability_Catalog_Abilities */
	private $catalog_abilities;
	/** @var Content_Abilities */
	private $content_abilities;
	/** @var Post_Meta_Abilities */
	private $post_meta_abilities;
	/** @var Term_Meta_Abilities */
	private $term_meta_abilities;
	/** @var User_Comment_Meta_Abilities */
	private $user_comment_meta_abilities;
	/** @var Block_Abilities */
	private $block_abilities;
	/** @var Media_Abilities */
	private $media_abilities;
	/** @var Taxonomy_Abilities */
	private $taxonomy_abilities;
	/** @var Navigation_Abilities */
	private $navigation_abilities;
	/** @var Integration_Abilities */
	private $integration_abilities;
	/** @var Site_Config_Abilities */
	private $site_config_abilities;
	/** @var Extension_Abilities */
	private $extension_abilities;
	/** @var Source_Editing_Abilities */
	private $source_editing_abilities;
	/** @var User_Abilities */
	private $user_abilities;
	/** @var Application_Password_Abilities */
	private $app_password_abilities;
	/** @var Comment_Abilities */
	private $comment_abilities;
	/** @var Gravity_Forms_Abilities */
	private $gravity_forms_abilities;
	/** @var Code_Snippets_Abilities */
	private $code_snippets_abilities;
	/** @var Workspace_Abilities */
	private $workspace_abilities;
	/** @var Native_Ability_Delegation|null */
	private $native_ability_delegation;

	/**
	 * Creates the registrar.
	 *
	 * @param Environment $environment Runtime dependency inspector.
	 * @param Settings    $settings    Bridge settings service.
	 * @param Permissions                     $permissions               Ability permission service.
	 * @param Store|null                      $workspace                 Optional shared Workspace store.
	 * @param Native_Ability_Delegation|null $native_ability_delegation Authoritative Bridge provenance service.
	 */
	public function __construct( Environment $environment, Settings $settings, Permissions $permissions, ?Store $workspace = null, ?Native_Ability_Delegation $native_ability_delegation = null ) {
		$this->environment                 = $environment;
		$this->settings                    = $settings;
		$this->permissions                 = $permissions;
		$this->native_ability_delegation   = $native_ability_delegation;
		$this->resolver                    = new Ability_Resolver();
		$mutation_log                      = new Mutation_Log();
		$this->site_abilities              = new Site_Abilities( $this->resolver, $this->permissions );
		$this->catalog_abilities           = new Ability_Catalog_Abilities( $this->resolver, $this->permissions, $native_ability_delegation );
		$this->content_abilities           = new Content_Abilities( $this->permissions, $mutation_log );
		$this->post_meta_abilities         = new Post_Meta_Abilities( $this->permissions, $mutation_log );
		$this->term_meta_abilities         = new Term_Meta_Abilities( $this->permissions, $mutation_log );
		$this->user_comment_meta_abilities = new User_Comment_Meta_Abilities( $this->permissions, $mutation_log );
		$this->block_abilities             = new Block_Abilities( $this->permissions, $mutation_log );
		$this->media_abilities             = new Media_Abilities( $this->permissions, $mutation_log );
		$this->taxonomy_abilities          = new Taxonomy_Abilities( $this->permissions, $mutation_log );
		$this->navigation_abilities        = new Navigation_Abilities( $this->permissions, $mutation_log );
		$this->integration_abilities       = new Integration_Abilities( $this->resolver, $this->permissions );
		$this->site_config_abilities       = new Site_Config_Abilities( $this->permissions, $mutation_log );
		$this->extension_abilities         = new Extension_Abilities( $this->permissions, $mutation_log );
		$this->source_editing_abilities    = new Source_Editing_Abilities( $this->permissions, $mutation_log );
		$this->user_abilities              = new User_Abilities( $this->permissions, $mutation_log );
		$this->app_password_abilities      = new Application_Password_Abilities( $this->permissions, $mutation_log );
		$this->comment_abilities           = new Comment_Abilities( $this->permissions, $mutation_log );
		$this->gravity_forms_abilities     = new Gravity_Forms_Abilities( $this->permissions, $mutation_log );
		$this->code_snippets_abilities     = new Code_Snippets_Abilities( $this->permissions, $mutation_log );
		$this->workspace_abilities         = new Workspace_Abilities( $this->permissions, $workspace ? $workspace : new Store(), $mutation_log );
	}

	/**
	 * Registers the bridge ability category. The stable category slug is retained
	 * so existing clients keep the same Ability identifiers across the public rename.
	 *
	 * @return void
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WP AI Bridge', 'wp-native-builder-bridge' ),
				'description' => __( 'Typed WordPress administration abilities exposed by WP AI Bridge.', 'wp-native-builder-bridge' ),
			)
		);
	}

	/**
	 * Registers bridge abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$registered = array();

		$registered[] = wp_register_ability(
			'wp-native-builder/bridge-info',
			array(
				'label'               => __( 'Bridge Info', 'wp-native-builder-bridge' ),
				'description'         => __( 'Returns the bridge dependency state and enabled access groups without exposing secrets.', 'wp-native-builder-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'plugin_version'    => array( 'type' => 'string' ),
						'wordpress_version' => array( 'type' => 'string' ),
						'abilities_api'     => array( 'type' => 'boolean' ),
						'mcp_adapter'       => array(
							'type'       => 'object',
							'properties' => array(
								'available' => array( 'type' => 'boolean' ),
								'version'   => array( 'type' => 'string' ),
							),
							'required'   => array( 'available', 'version' ),
						),
						'access_groups'     => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'boolean' ),
						),
					),
					'required'   => array( 'plugin_version', 'wordpress_version', 'abilities_api', 'mcp_adapter', 'access_groups' ),
				),
				'execute_callback'    => array( $this, 'bridge_info' ),
				'permission_callback' => array( $this, 'can_read_bridge_info' ),
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		if ( $this->native_ability_delegation ) {
			$this->native_ability_delegation->remember_bridge_abilities( array_values( array_filter( $registered, 'is_object' ) ) );
		}

		$providers = array(
			$this->site_abilities,
			$this->catalog_abilities,
			$this->content_abilities,
			$this->post_meta_abilities,
			$this->term_meta_abilities,
			$this->user_comment_meta_abilities,
			$this->block_abilities,
			$this->media_abilities,
			$this->taxonomy_abilities,
			$this->navigation_abilities,
			$this->integration_abilities,
			$this->site_config_abilities,
			$this->extension_abilities,
			$this->source_editing_abilities,
			$this->user_abilities,
			$this->app_password_abilities,
			$this->comment_abilities,
			$this->gravity_forms_abilities,
			$this->code_snippets_abilities,
			$this->workspace_abilities,
		);

		foreach ( $providers as $provider ) {
			$provider_abilities = $provider->register();
			if ( $this->native_ability_delegation ) {
				$this->native_ability_delegation->remember_bridge_abilities( $provider_abilities );
			}
		}
	}


	/**
	 * Checks access to the bridge information ability.
	 *
	 * @return bool
	 */
	public function can_read_bridge_info() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
	}

	/**
	 * Returns non-secret bridge dependency and access-group information.
	 *
	 * @return array<string,mixed> Bridge information.
	 */
	public function bridge_info() {
		$environment = $this->environment->summary();
		$groups      = array();

		foreach ( $this->settings->all() as $key => $enabled ) {
			$groups[ $key ] = (bool) $enabled;
		}

		return array(
			'plugin_version'    => defined( 'WP_NATIVE_BUILDER_BRIDGE_VERSION' ) ? WP_NATIVE_BUILDER_BRIDGE_VERSION : '',
			'wordpress_version' => $environment['wordpress_version'],
			'abilities_api'     => (bool) $environment['abilities_api'],
			'mcp_adapter'       => $environment['mcp_adapter'],
			'access_groups'     => $groups,
		);
	}
}
