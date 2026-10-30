<?php
/**
 * Explicit native site/network settings read delegation.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Keeps network consent network-owned and independent of local Settings saves.
 */
final class Native_Option_Access {
	const NETWORK_OPTION_NAME       = 'wp_ai_bridge_network_settings_read';
	const NETWORK_WRITE_OPTION_NAME = 'wp_ai_bridge_network_settings_write';

	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Current Bridge/native authority. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/** @param string $scope Current site or current network. @return bool */
	public function allowed( $scope ) {
		$user    = get_current_user_id();
		$blog    = get_current_blog_id();
		$network = function_exists( 'get_current_network_id' ) ? get_current_network_id() : 0;
		if ( $user < 1 || ! in_array( $scope, array( 'site', 'network' ), true ) ||
			! $this->permissions->allowed( Settings::GROUP_NATIVE_OPTIONS_READ, 'manage_options' ) ) {
			return false;
		}
		$allowed = 'site' === $scope ||
			( self::can_configure_network( $network ) && self::network_enabled() );
		return $allowed && get_current_user_id() === $user && get_current_blog_id() === $blog &&
			( function_exists( 'get_current_network_id' ) ? get_current_network_id() : 0 ) === $network;
	}

	/** @param string $scope Current site or network. @return bool */
	public function allowed_write( $scope ) {
		$user    = get_current_user_id();
		$blog    = get_current_blog_id();
		$network = function_exists( 'get_current_network_id' ) ? get_current_network_id() : 0;
		if ( ! $this->allowed( $scope ) ) {
			return false;
		}
		$requirements = array(
			array( Settings::GROUP_NATIVE_OPTIONS_READ, 'manage_options' ),
			array( Settings::GROUP_NATIVE_OPTIONS_WRITE, 'manage_options' ),
			array( Settings::GROUP_SITE_CONFIG, 'manage_options' ),
		);
		if ( 'network' === $scope ) {
			$requirements[] = array( Settings::GROUP_NATIVE_NETWORK_READ, 'manage_network_options' );
			$requirements[] = array( Settings::GROUP_NATIVE_NETWORK_WRITE, 'manage_network_options' );
		}
		return $this->permissions->allowed_all( $requirements ) &&
			( 'site' === $scope || self::can_configure_network( $network ) ) &&
			get_current_user_id() === $user && get_current_blog_id() === $blog &&
			( function_exists( 'get_current_network_id' ) ? get_current_network_id() : 0 ) === $network;
	}

	/** @return bool Current-network mutation consent, never a local-site flag. */
	public static function network_write_enabled() {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ||
			! function_exists( 'get_current_network_id' ) || ! function_exists( 'get_network_option' ) ) {
			return false;
		}
		$network = get_current_network_id();
		$value   = get_network_option( $network, self::NETWORK_WRITE_OPTION_NAME, 0 );
		return get_current_network_id() === $network && in_array( $value, array( 1, '1' ), true );
	}

	/**
	 * Read only the current network's canonical native flag. No Settings recursion.
	 *
	 * Core may return stored scalar integers as strings after cache reload.
	 *
	 * @return bool
	 */
	public static function network_enabled() {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ||
			! function_exists( 'get_current_network_id' ) || ! function_exists( 'get_network_option' ) ) {
			return false;
		}
		$network = get_current_network_id();
		$value   = get_network_option( $network, self::NETWORK_OPTION_NAME, 0 );
		return get_current_network_id() === $network && in_array( $value, array( 1, '1' ), true );
	}

	/** @param int|null $network_id Exact current-network binding. @return bool */
	public static function can_configure_network( $network_id = null ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ||
			! function_exists( 'get_current_network_id' ) || get_current_user_id() < 1 ) {
			return false;
		}
		$network = get_current_network_id();
		$user    = get_current_user_id();
		$blog    = get_current_blog_id();
		if ( null !== $network_id && ( ! is_int( $network_id ) || $network_id !== $network ) ) {
			return false;
		}
		return current_user_can( 'manage_options' ) && is_super_admin() &&
			current_user_can( 'manage_network_options' ) &&
			get_current_network_id() === $network && get_current_user_id() === $user &&
			get_current_blog_id() === $blog;
	}

	/** @return void Register on bootstrap, including non-admin native API requests. */
	public function register_network_guards() {
		add_filter( 'pre_add_site_option_' . self::NETWORK_OPTION_NAME, array( $this, 'guard_network_add' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_update_site_option_' . self::NETWORK_OPTION_NAME, array( $this, 'guard_network_update' ), PHP_INT_MAX, 4 );
		add_filter( 'sanitize_option_' . self::NETWORK_OPTION_NAME, array( $this, 'sanitize_network_grant' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_add_site_option_' . self::NETWORK_WRITE_OPTION_NAME, array( $this, 'guard_network_write_add' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_update_site_option_' . self::NETWORK_WRITE_OPTION_NAME, array( $this, 'guard_network_write_update' ), PHP_INT_MAX, 4 );
		add_filter( 'sanitize_option_' . self::NETWORK_WRITE_OPTION_NAME, array( $this, 'sanitize_network_write_grant' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Core pre_add_site_option signature is value, option, network_id.
	 *
	 * @param mixed $value Proposed flag.
	 * @param string $option Exact owned option.
	 * @param int $network_id Target network.
	 * @return int
	 */
	public function guard_network_add( $value, $option, $network_id ) {
		return $this->guard_value( $value, $option, $network_id );
	}

	/**
	 * Core pre_update_site_option signature is value, old_value, option, network_id.
	 *
	 * @param mixed $value Proposed flag.
	 * @param mixed $old_value Unused prior value.
	 * @param string $option Exact owned option.
	 * @param int $network_id Target network.
	 * @return int
	 */
	public function guard_network_update( $value, $old_value, $option, $network_id ) {
		return $this->guard_value( $value, $option, $network_id );
	}

	/**
	 * Native network setters sanitize after the pre-add/update filters.
	 *
	 * @param mixed $value Proposed flag.
	 * @param string $option Exact owned option.
	 * @param mixed $original_value Unused unsanitized value.
	 * @return int
	 */
	public function sanitize_network_grant( $value, $option = self::NETWORK_OPTION_NAME, $original_value = null ) {
		return $this->guard_value( $value, $option, get_current_network_id() );
	}

	/** @param mixed $value Proposed flag. @param string $option Owned name. @param int $network_id Exact network. @return int */
	private function guard_value( $value, $option, $network_id ) {
		if ( self::NETWORK_OPTION_NAME !== $option || ! self::can_configure_network( $network_id ) ||
			! is_int( $value ) || ! in_array( $value, array( 0, 1 ), true ) ) {
			wp_die(
				esc_html__( 'Changing native network settings access requires a canonical flag and current Super Admin network authority.', 'wp-ai-bridge' ),
				esc_html__( 'Native network settings access', 'wp-ai-bridge' ),
				array( 'response' => 403 )
			);
			return 0;
		}
		return $value;
	}
	/** @param mixed $value Flag. @param string $option Owned name. @param int $network_id Exact network. @return int */
	public function guard_network_write_add( $value, $option, $network_id ) {
		return $this->guard_write_value( $value, $option, $network_id );
	}

	/** @param mixed $value Flag. @param mixed $old_value Unused previous flag. @param string $option Owned name. @param int $network_id Exact network. @return int */
	public function guard_network_write_update( $value, $old_value, $option, $network_id ) {
		return $this->guard_write_value( $value, $option, $network_id );
	}

	/** @param mixed $value Flag. @param string $option Owned name. @param mixed $original_value Unused initial flag. @return int */
	public function sanitize_network_write_grant( $value, $option = self::NETWORK_WRITE_OPTION_NAME, $original_value = null ) {
		return $this->guard_write_value( $value, $option, get_current_network_id() );
	}

	/** @param mixed $value Flag. @param string $option Owned name. @param int $network_id Exact network. @return int */
	private function guard_write_value( $value, $option, $network_id ) {
		if ( self::NETWORK_WRITE_OPTION_NAME !== $option || ! self::can_configure_network( $network_id ) ||
			! is_int( $value ) || ! in_array( $value, array( 0, 1 ), true ) ) {
			wp_die(
				esc_html__( 'Changing native network settings write access requires a canonical flag and current Super Admin network authority.', 'wp-ai-bridge' ),
				esc_html__( 'Native network settings write access', 'wp-ai-bridge' ),
				array( 'response' => 403 )
			);
			return 0;
		}
		return $value;
	}
}
