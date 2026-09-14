<?php
/**
 * Main plugin bootstrap.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge;

use WP_Native_Builder_Bridge\Abilities\Registrar;
use WP_Native_Builder_Bridge\Admin\Settings_Page;
use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Support\Environment;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Native_Builder_Bridge\Workspace\Store;

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

		$this->booted        = true;
		$this->environment   = new Environment();
		$this->settings      = new Settings();
		$this->permissions   = new Permissions( $this->settings );
		$this->workspace     = new Store();
		$this->registrar     = new Registrar( $this->environment, $this->settings, $this->permissions, $this->workspace );
		$this->oauth_server  = new OAuth_Server();
		$this->settings_page = new Settings_Page( $this->environment, $this->settings, $this->oauth_server, $this->workspace, new Mutation_Log() );

		$this->oauth_server->boot();

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( $this->workspace, 'register_post_types' ), 5 );
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_menu', array( $this->settings_page, 'register_menu' ) );
		add_action( 'admin_post_wpnb_workspace_export', array( $this->settings_page, 'handle_export' ) );
		add_action( 'admin_post_wpnb_workspace_clear', array( $this->settings_page, 'handle_clear' ) );
		add_action( 'admin_notices', array( $this, 'render_dependency_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WP_NATIVE_BUILDER_BRIDGE_FILE ), array( $this, 'plugin_action_links' ) );
		add_action( 'wp_abilities_api_categories_init', array( $this->registrar, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this->registrar, 'register_abilities' ), 100 );
	}

	/**
	 * Loads the stable legacy text domain so existing translation files continue to work.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'wp-native-builder-bridge',
			false,
			dirname( plugin_basename( WP_NATIVE_BUILDER_BRIDGE_FILE ) ) . '/languages/'
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
				esc_html__( 'Settings', 'wp-native-builder-bridge' )
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
				esc_html__( 'WP AI Bridge requires WordPress 6.9 or newer with the Abilities API available.', 'wp-native-builder-bridge' )
			);
			return;
		}

		if ( ! $this->environment->mcp_adapter_available() ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p></div>',
				esc_html__( 'WP AI Bridge is active, but the official WordPress MCP Adapter is not available.', 'wp-native-builder-bridge' ),
				esc_url( 'https://github.com/WordPress/mcp-adapter/releases/latest' ),
				esc_html__( 'Install or activate MCP Adapter', 'wp-native-builder-bridge' )
			);
			return;
		}

		if ( ! $this->oauth_server->is_https_ready() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Direct ChatGPT App connections require the public WP AI Bridge MCP endpoint to use HTTPS. Check the WordPress Site URL and reverse-proxy HTTPS configuration before creating the App.', 'wp-native-builder-bridge' )
			);
		}
	}
}
