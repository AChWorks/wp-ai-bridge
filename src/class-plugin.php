<?php
/**
 * Main plugin bootstrap.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge;

use WP_AI_Bridge\Abilities\Registrar;
use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Admin\Administration_Page;
use WP_AI_Bridge\Support\Administration_Configuration;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Ability_Delegation;
use WP_AI_Bridge\Support\Native_Option_Access;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Payload_Settings;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_AI_Bridge\Support\External_Package_Limits;
use WP_AI_Bridge\Support\GitHub_Updates;
use WP_AI_Bridge\Support\REST_Invocation_Input_Limits;
use WP_AI_Bridge\Support\Operation_Receipt_Limits;
use WP_AI_Bridge\Support\Operation_Content_Limits;
use WP_AI_Bridge\Support\Workspace_Limits;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

/**
 * Wires the bridge services into WordPress.
 */
final class Plugin {
	/** @var self|null */
	private static $instance = null;
	/** @var bool */
	private $booted = false;
	/** @var Environment */
	private $environment;
	/** @var Settings */
	private $settings;
	/** @var Permissions */
	private $permissions;
	/** @var Native_Ability_Delegation */
	private $native_ability_delegation;
	/** @var Registrar */
	private $registrar;
	/** @var OAuth_Server */
	private $oauth_server;
	/** @var Settings_Page */
	private $settings_page;
	/** @var Store */
	private $workspace;

	/** @return self */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Prevents direct construction. */
	private function __construct() {}

	/**
	 * Registers the plugin services and WordPress hooks.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		( new GitHub_Updates() )->boot();
		$this->environment = new Environment();
		$this->settings    = new Settings();
		$this->settings->register_database_guard();
		$this->settings->register_administration_guard();
		( new Administration_Configuration() )->boot();
		$this->permissions = new Permissions( $this->settings );
		( new Native_Option_Access( $this->permissions ) )->register_network_guards();
		$this->native_ability_delegation = new Native_Ability_Delegation( $this->settings );
		$this->workspace                 = new Store();
		$this->registrar                 = new Registrar( $this->environment, $this->settings, $this->permissions, $this->workspace, $this->native_ability_delegation );
		$this->oauth_server              = new OAuth_Server();
		$this->settings_page             = new Settings_Page( $this->environment, $this->settings, $this->oauth_server, $this->workspace, new Mutation_Log() );

		$this->native_ability_delegation->boot();
		$this->oauth_server->boot();

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( $this->workspace, 'register_post_types' ), 5 );
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_init', array( new Payload_Settings(), 'register' ) );
		add_action( 'admin_init', array( new Transfer_Settings(), 'register' ) );
		add_action( 'admin_init', array( new Workspace_Limits(), 'register' ) );
		add_action( 'admin_init', array( new Operation_Receipt_Limits(), 'register' ) );
		add_action( 'admin_init', array( new Operation_Content_Limits(), 'register' ) );
		add_action( 'admin_init', array( new External_Package_Limits(), 'register' ) );
		add_action( 'admin_init', array( new REST_Invocation_Input_Limits(), 'register' ) );
		add_action( 'admin_init', array( $this->settings_page, 'prepare_settings_screen' ) );
		add_action( 'admin_enqueue_scripts', array( $this->settings_page, 'enqueue_settings_assets' ) );
		add_action( 'admin_menu', array( $this->settings_page, 'register_menu' ) );
		add_action( 'admin_menu', array( $this->settings_page, 'order_settings_submenu' ), 999 );
		$administration_page = new Administration_Page( $this->settings );
		// Core checks hidden-page access while building the menu, before admin_init.
		add_action( 'admin_menu', array( $administration_page, 'redirect_legacy' ), 1000 );
		add_action( 'admin_menu', array( $administration_page, 'register_menu' ), 20 );
		add_action( 'admin_post_wpai_administration_configuration', array( $administration_page, 'save_configuration' ) );
		add_action( 'admin_post_wpai_workspace_export', array( $this->settings_page, 'handle_export' ) );
		add_action( 'admin_post_wpai_native_network_write', array( $this->settings_page, 'handle_native_network_write' ) );
		add_action( 'admin_post_wpai_native_network_read', array( $this->settings_page, 'handle_native_network_read' ) );
		add_action( 'admin_post_wpai_workspace_clear', array( $this->settings_page, 'handle_clear' ) );
		add_action( 'admin_notices', array( $this, 'render_dependency_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WP_AI_BRIDGE_FILE ), array( $this, 'plugin_action_links' ) );
		add_action( 'wp_abilities_api_categories_init', array( $this->registrar, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this->registrar, 'register_abilities' ), 100 );
	}

	/**
	 * Loads the WP AI Bridge text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'wp-ai-bridge',
			false,
			dirname( plugin_basename( WP_AI_BRIDGE_FILE ) ) . '/languages/'
		);
	}

	/**
	 * Adds a direct Settings link on the WordPress Plugins screen.
	 *
	 * @param array<int,string> $links Existing plugin action links.
	 * @return array<int,string>
	 */
	public function plugin_action_links( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=' . Settings_Page::SETTINGS_SLUG ) ),
				esc_html__( 'Settings', 'wp-ai-bridge' )
			)
		);

		return $links;
	}

	/**
	 * Renders dependency notices for administrators.
	 *
	 * @return void
	 */
	public function render_dependency_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->environment->abilities_api_available() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'WP AI Bridge requires WordPress 6.9 or newer with the Abilities API available.', 'wp-ai-bridge' )
			);
			return;
		}

		if ( ! $this->environment->mcp_adapter_available() ) {
			// The native Plugins table already explains unmet declared requirements.
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'plugins' === $screen->base && class_exists( '\WP_Plugin_Dependencies' )
				&& \WP_Plugin_Dependencies::has_dependencies( plugin_basename( WP_AI_BRIDGE_FILE ) )
				&& \WP_Plugin_Dependencies::has_unmet_dependencies( plugin_basename( WP_AI_BRIDGE_FILE ) ) ) {
				return;
			}

			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p></div>',
				esc_html__( 'WP AI Bridge is active, but the official WordPress MCP Adapter is not available.', 'wp-ai-bridge' ),
				esc_url( 'https://github.com/WordPress/mcp-adapter/releases/latest' ),
				esc_html__( 'Install or activate MCP Adapter', 'wp-ai-bridge' )
			);
			return;
		}

		if ( ! $this->oauth_server->is_https_ready() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Remote AI/MCP connections require the WP AI Bridge endpoint to use HTTPS. Check the WordPress Site URL and reverse-proxy HTTPS configuration before connecting a remote client.', 'wp-ai-bridge' )
			);
		}
	}
}
