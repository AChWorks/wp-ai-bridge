<?php
/**
 * Scoped late initialization of the native current-theme Customizer.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/** Public Core lifecycle only; no direct private publication or storage writes. */
final class Customizer_Context {
	/** @var bool Core multidimensional roots cannot be reset through a supported production API. */
	private static $initialized = false;
	/** @var Permissions */ private $permissions;
	/** @var \WP_Customize_Manager|null */ private $manager;
	/** @var string */ private $stylesheet;
	/** @var array */ private $post;
	/** @var array */ private $request;
	/** @var mixed */ private $previous;
	/** @var bool */ private $had_global   = false;
	/** @var bool */ private $opened       = false;
	/** @var array */ private $publication = array();

	/** @param Permissions $permissions Native delegation. @param string $stylesheet Exact active stylesheet. */
	public function __construct( Permissions $permissions, $stylesheet ) {
		$this->permissions = $permissions;
		$this->stylesheet  = $stylesheet;
	}

	/** @return true|WP_Error Register Core before providers, as Core's late publication path does. */
	public function open() {
		if ( $this->opened ) {
			return $this->current() ? true : $this->error( 'customizer_context_changed' );
		}
		if ( self::$initialized ) {
			return $this->error( 'customizer_request_context_used' );
		}
		if ( ! $this->current() || ! empty( $GLOBALS['wp_customize'] ) ) {
			return $this->error( 'customizer_context_unavailable' );
		}
		$this->had_global    = array_key_exists( 'wp_customize', $GLOBALS );
		$this->previous      = $GLOBALS['wp_customize'] ?? null;
		$this->post          = $_POST;
		$this->request       = $_REQUEST;
		$this->opened        = true;
		self::$initialized   = true;
		$_POST['customized'] = '{}';
		foreach ( array( 'customize_changeset_uuid', 'customize_theme', 'theme', 'customize_messenger_channel' ) as $key ) {
			unset( $_POST[ $key ], $_REQUEST[ $key ] );
		}
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		$this->manager           = new \WP_Customize_Manager(
			array(
				'changeset_uuid'     => wp_generate_uuid4(),
				'theme'              => $this->stylesheet,
				'messenger_channel'  => '',
				'settings_previewed' => false,
				'autosaved'          => false,
				'branching'          => true,
			)
		);
		$GLOBALS['wp_customize'] = $this->manager;
		// The manager is constructed after after_setup_theme/wp_loaded. Calling
		// wp_loaded() naively reverses same-priority Core/provider ordering.
		remove_action( 'customize_register', array( $this->manager, 'register_controls' ) );
		$this->manager->register_controls();
		do_action( 'customize_register', $this->manager );
		return $this->current() ? true : $this->error( 'customizer_context_changed' );
	}

	/** @return bool The current operation never previews or switches another theme. */
	private function current() {
		return ( ! $this->manager || ( $GLOBALS['wp_customize'] ?? null ) === $this->manager ) && get_stylesheet() === $this->stylesheet && get_option( 'stylesheet' ) === $this->stylesheet && ( ! $this->manager || ( $this->manager->get_stylesheet() === $this->stylesheet && $this->manager->is_theme_active() && ! $this->manager->settings_previewed() ) );
	}

	/** @param string $name Exact runtime setting ID. @return \WP_Customize_Setting|WP_Error */
	private function setting( $name ) {
		$opened = $this->open();
		if ( is_wp_error( $opened ) ) {
			return $opened;
		}
		if ( 'active_theme' === $name ) {
			return $this->error( 'customizer_theme_switch_unsupported' );
		}
		$setting = $this->manager->get_setting( $name );
		if ( ! $setting ) {
			$this->manager->add_dynamic_settings( array( $name ) );
			$setting = $this->manager->get_setting( $name );
		}
		if ( ! $setting instanceof \WP_Customize_Setting || $setting->id !== $name || ! $setting->check_capabilities() || ! $this->current() ) {
			return $this->error( 'customizer_setting_unavailable' );
		}
		if ( 'option' === $setting->type || 'theme_mod' === $setting->type ) {
			$data = $setting->id_data();
			$base = $data['base'] ?? null;
			if ( ! Native_Configuration_Operation::text( $base ) ) {
				return $this->error( 'customizer_setting_unavailable' );
			}
			$identity = array_merge( array( 'scope' => 'site' ), ( new Administration_Access( $this->permissions ) )->context() );
			$guard    = new Native_Option_Operation( $identity, max( 1, ( new Transfer_Settings() )->bytes()['request'] ) );
			$checked  = $guard->check_target( 'site', 'option' === $setting->type ? $base : 'theme_mods_' . $this->stylesheet );
			if ( is_wp_error( $checked ) ) {
				return $this->error( 'customizer_protected_setting' );
			}
		}
		return $setting;
	}

	/** @param string $name Exact setting. @return mixed Native effective value or fixed error. */
	public function read( $name ) {
		$setting = $this->setting( $name );
		return is_wp_error( $setting ) ? $setting : $setting->value();
	}

	/** @param string $name Exact setting. @param mixed $value Input native value. @return true|WP_Error */
	public function prepare( $name, $value ) {
		$setting = $this->setting( $name );
		if ( is_wp_error( $setting ) ) {
			return $setting;
		}
		// Native changesets are JSON documents. Transport byte fidelity does not
		// manufacture support for non-JSON changeset values.
		try {
			json_encode( $value, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return $this->error( 'customizer_value_unrepresentable' );
		}
		if ( null === $value ) {
			return $this->error( 'customizer_value_invalid' );
		}
		$this->manager->set_post_value( $name, $value );
		$validities = $this->manager->validate_setting_values(
			array( $name => $value ),
			array(
				'validate_existence'  => true,
				'validate_capability' => true,
			)
		);
		if ( true !== ( $validities[ $name ] ?? false ) || ! $setting->check_capabilities() || ! $this->current() ) {
			return $this->error( 'customizer_value_invalid' );
		}
		$post_values = $this->manager->unsanitized_post_values(
			array(
				'exclude_changeset' => true,
				'exclude_post_data' => false,
			)
		);
		if ( array( $name ) !== array_keys( $post_values ) ) {
			return $this->error( 'customizer_ambient_values_unsupported' );
		}
		$this->publication = array(
			'status' => 'publish',
			'data'   => array( $name => array( 'value' => $value ) ),
		);
		return true;
	}

	/** @return bool Hook-free manager identity check after final hook-capable authorization. */
	public function ready() {
		return $this->opened && $this->manager && ( $GLOBALS['wp_customize'] ?? null ) === $this->manager;
	}

	/** @return array|WP_Error One public native effect; all preparation is before arm(). */
	public function publish() {
		return $this->manager->save_changeset_post( $this->publication );
	}

	/** @param array $input Page cursor/limit. @return array|WP_Error */
	public function listing( array $input ) {
		$opened = $this->open();
		if ( is_wp_error( $opened ) ) {
			return $opened;
		}
		$settings = $this->manager->settings();
		ksort( $settings, SORT_STRING );
		$result = array(
			'items'                    => array(),
			'next_after_name'          => $input['after_name'] ?? '',
			'has_more'                 => false,
			'inventory'                => 'registered_current_theme_settings',
			'dynamic_names_enumerated' => false,
		);
		foreach ( $settings as $name => $setting ) {
			if ( 'active_theme' === $name || ( isset( $input['after_name'] ) && strcmp( $name, $input['after_name'] ) <= 0 ) ) {
				continue;
			}
			if ( ! $setting instanceof \WP_Customize_Setting || ! Native_Configuration_Operation::text( $name ) || ! is_string( $setting->type ) || ! is_string( $setting->capability ) || ! $setting->check_capabilities() ) {
				continue;
			}
			$item                 = array(
				'name'       => $name,
				'type'       => $setting->type,
				'capability' => $setting->capability,
			);
			$candidate            = $result;
			$candidate['items'][] = $item;
			if ( count( $result['items'] ) >= ( $input['limit'] ?? PHP_INT_MAX ) || Bounded_Payload::projected_mcp_bytes( $candidate ) + 1024 > Bounded_Payload::response_limit() ) {
				$result['has_more'] = true;
				break;
			}
			$result['items'][]         = $item;
			$result['next_after_name'] = $name;
		}
		return $result['has_more'] && ! $result['items'] ? $this->error( 'customizer_response_budget' ) : $result;
	}

	/** Restore ambient request/global state; preserve native deferred hook effects. */
	public function close() {
		if ( ! $this->opened ) {
			return;
		}
		// Native/provider hooks can schedule legitimate shutdown work. Keep
		// those registrations through request end rather than canceling effects.
		$_POST    = $this->post;
		$_REQUEST = $this->request;
		if ( $this->had_global ) {
			$GLOBALS['wp_customize'] = $this->previous;
		} else {
			unset( $GLOBALS['wp_customize'] );
		}
		$this->opened  = false;
		$this->manager = null;
	}

	/** @param string $code Fixed error code. @return WP_Error */
	private function error( $code ) {
		return new WP_Error( $code, __( 'The current-theme Customizer operation is unavailable, denied or rejected by its native contract.', 'wp-ai-bridge' ) );
	}
}
