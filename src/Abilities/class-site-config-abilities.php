<?php
/**
 * Typed WordPress site configuration abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Provides a bounded allowlist of builder-relevant Core site settings.
 */
final class Site_Config_Abilities {
	/** @var Permissions */ private $permissions;
	/** @var Mutation_Log */ private $log;

	/** @param Permissions $permissions Permissions. @param Mutation_Log $log Mutation log. */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/site-settings-read',
			array(
				'label'               => __( 'Read Site Settings', 'wp-ai-bridge' ),
				'description'         => __( 'Reads the bounded Core WordPress settings used for site building without exposing arbitrary options.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->settings_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-ai-bridge/site-settings-update',
			array(
				'label'               => __( 'Update Site Settings', 'wp-ai-bridge' ),
				'description'         => __( 'Updates only the named builder-relevant Core WordPress settings and reports permalink/front-page impact.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->update_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'settings'           => $this->settings_schema(),
						'changed'            => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'rewrite_flushed'    => array( 'type' => 'boolean' ),
						'front_page_changed' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'settings', 'changed', 'rewrite_flushed', 'front_page_changed' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'update' ),
				'permission_callback' => array( $this, 'can_update' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/core-update-status',
			array(
				'label'               => __( 'Read WordPress Core Update Status', 'wp-ai-bridge' ),
				'description'         => __( 'Reads cached WordPress Core update offers and check freshness; never checks for or installs an update.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->core_update_status_schema(),
				'execute_callback'    => array( $this, 'core_update_status' ),
				'permission_callback' => array( $this, 'can_read_core_update_status' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$generic    = new Registered_Settings_Abilities( $this->permissions, $this->log );
		$registered = array_merge( $registered, $generic->register() );

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */ public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'manage_options' ); }
	/** @return bool */ public function can_update() {
		return $this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'manage_options' ); }


	/**
	 * Site Configuration is default-off. On multisite only network administrators
	 * may inspect the shared Core update cache; no site-admin escalation.
	 *
	 * @return bool
	 */
	public function can_read_core_update_status() {
		return $this->permissions->allowed(
			Settings::GROUP_SITE_CONFIG,
			is_multisite() ? 'manage_network_options' : 'manage_options'
		);
	}

	/**
	 * Projects only already-cached Core update information. No network, update
	 * check, installer, filesystem or persistence side effects are initiated.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function core_update_status() {
		if ( ! $this->can_read_core_update_status() ) {
			return new WP_Error( 'core_update_status_denied', __( 'Site Configuration and native WordPress administration permission are required.', 'wp-ai-bridge' ) );
		}

		$raw               = get_site_transient( 'update_core' );
		$installed_version = (string) get_bloginfo( 'version' );
		$now               = time();
		$checked           = is_object( $raw ) && isset( $raw->last_checked ) && is_numeric( $raw->last_checked ) ? (int) $raw->last_checked : 0;
		$valid_check       = $checked > 0 && $checked <= $now + 300;
		$age               = $valid_check ? max( 0, $now - $checked ) : 0;
		$has_offer_data    = is_object( $raw ) && isset( $raw->updates ) && is_array( $raw->updates ) && count( $raw->updates ) <= 128;
		$version_matches   = $has_offer_data && isset( $raw->version_checked ) && is_string( $raw->version_checked ) && $installed_version === $raw->version_checked;
		$valid_offers      = $has_offer_data;
		if ( $valid_offers ) {
			foreach ( $raw->updates as $offer ) {
				// get_core_updates() reads these exact fields without validating them.
				if (
					! is_object( $offer ) || ! isset( $offer->response, $offer->current, $offer->locale ) ||
					! is_string( $offer->response ) || ! is_string( $offer->current ) || ! is_string( $offer->locale )
				) {
					$valid_offers = false;
					break;
				}
			}
		}
		$fresh       = $valid_offers && $valid_check && $version_matches && $age <= DAY_IN_SECONDS;
		$cache_state = ! $valid_offers || ! $valid_check ? 'missing_or_invalid' : ( $fresh ? 'fresh' : 'stale' );

		$offers      = array();
		$total       = 0;
		$has_upgrade = false;
		$seen_latest = false;
		$status      = 'unknown_or_stale';
		if ( $fresh ) {
			// Core's native helper only reads the update_core transient and dismissed preferences.
			if ( ! function_exists( 'get_core_updates' ) && is_file( ABSPATH . 'wp-admin/includes/update.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}
			if ( function_exists( 'get_core_updates' ) ) {
				$updates = get_core_updates( array( 'dismissed' => true ) );
				if ( is_array( $updates ) ) {
					foreach ( $updates as $update ) {
						if ( ! is_object( $update ) || ! isset( $update->response, $update->current ) || ! is_string( $update->response ) || ! is_string( $update->current ) ) {
							$valid_offers = false;
							$offers       = array();
							$total        = 0;
							break;
						}
						++$total;
						if ( 'upgrade' === $update->response ) {
							$has_upgrade = true;
						} elseif ( 'latest' === $update->response ) {
							$seen_latest = true;
						}
						if ( count( $offers ) < 8 ) {
								$offers[] = array(
									'version'   => substr( $update->current, 0, 48 ),
									'response'  => substr( $update->response, 0, 32 ),
									'locale'    => isset( $update->locale ) && is_string( $update->locale ) ? substr( $update->locale, 0, 32 ) : '',
									'dismissed' => ! empty( $update->dismissed ),
								);
						}
					}
					if ( $valid_offers ) {
						$status = $has_upgrade ? 'update_available' : ( $seen_latest ? 'no_update_offered' : 'unknown_or_stale' );
					}
				}
			}
		}

		return array(
			'installed_version'            => $installed_version,
			'status'                       => $status,
			'cache_state'                  => $cache_state,
			'last_checked_utc'             => $valid_check ? gmdate( 'c', $checked ) : '',
			'check_age_seconds'            => $age,
			'offers'                       => $offers,
			'offer_count'                  => $total,
			'offers_truncated'             => $total > count( $offers ),
			'native_update_core_allowed'   => current_user_can( 'update_core' ),
			'file_modifications_allowed'   => function_exists( 'wp_is_file_mod_allowed' ) ? (bool) wp_is_file_mod_allowed( 'core' ) : ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
			'automatic_updater_disabled'   => defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED,
			'upgrade_execution_via_bridge' => false,
		);
	}

	/** @return array<string,mixed> */
	private function core_update_status_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'installed_version'            => array( 'type' => 'string' ),
				'status'                       => array(
					'type' => 'string',
					'enum' => array( 'update_available', 'no_update_offered', 'unknown_or_stale' ),
				),
				'cache_state'                  => array(
					'type' => 'string',
					'enum' => array( 'fresh', 'stale', 'missing_or_invalid' ),
				),
				'last_checked_utc'             => array( 'type' => 'string' ),
				'check_age_seconds'            => array( 'type' => 'integer' ),
				'offers'                       => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'version'   => array( 'type' => 'string' ),
							'response'  => array( 'type' => 'string' ),
							'locale'    => array( 'type' => 'string' ),
							'dismissed' => array( 'type' => 'boolean' ),
						),
						'required'             => array( 'version', 'response', 'locale', 'dismissed' ),
						'additionalProperties' => false,
					),
				),
				'offer_count'                  => array( 'type' => 'integer' ),
				'offers_truncated'             => array( 'type' => 'boolean' ),
				'native_update_core_allowed'   => array( 'type' => 'boolean' ),
				'file_modifications_allowed'   => array( 'type' => 'boolean' ),
				'automatic_updater_disabled'   => array( 'type' => 'boolean' ),
				'upgrade_execution_via_bridge' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'installed_version', 'status', 'cache_state', 'last_checked_utc', 'check_age_seconds', 'offers', 'offer_count', 'offers_truncated', 'native_update_core_allowed', 'file_modifications_allowed', 'automatic_updater_disabled', 'upgrade_execution_via_bridge' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	public function read() {
		return array(
			'site_title'          => (string) get_option( 'blogname', '' ),
			'tagline'             => (string) get_option( 'blogdescription', '' ),
			'show_on_front'       => (string) get_option( 'show_on_front', 'posts' ),
			'page_on_front'       => (int) get_option( 'page_on_front', 0 ),
			'page_for_posts'      => (int) get_option( 'page_for_posts', 0 ),
			'posts_per_page'      => (int) get_option( 'posts_per_page', 10 ),
			'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
		);
	}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function update( $input ) {
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'invalid_site_settings', __( 'Site settings input must be an object.', 'wp-ai-bridge' ) );
		}
		$allowed = array( 'site_title', 'tagline', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'permalink_structure' );
		$changed = array();
		$rewrite = false;
		$front   = false;

		if ( isset( $input['show_on_front'] ) && ! in_array( $input['show_on_front'], array( 'posts', 'page' ), true ) ) {
			return new WP_Error( 'invalid_front_mode', __( 'show_on_front must be posts or page.', 'wp-ai-bridge' ) );
		}
		foreach ( array( 'page_on_front', 'page_for_posts' ) as $key ) {
			if ( array_key_exists( $key, $input ) && ! $this->valid_page_id( (int) $input[ $key ] ) ) {
				return new WP_Error( 'invalid_front_page', __( 'Front-page and posts-page IDs must reference published or editable WordPress pages, or be zero.', 'wp-ai-bridge' ) );
			}
		}
		$future_front = array_key_exists( 'page_on_front', $input ) ? (int) $input['page_on_front'] : (int) get_option( 'page_on_front', 0 );
		$future_posts = array_key_exists( 'page_for_posts', $input ) ? (int) $input['page_for_posts'] : (int) get_option( 'page_for_posts', 0 );
		if ( $future_front > 0 && $future_front === $future_posts ) {
			return new WP_Error( 'front_pages_must_differ', __( 'The front page and posts page must be different pages.', 'wp-ai-bridge' ) );
		}
		if ( isset( $input['posts_per_page'] ) && ( (int) $input['posts_per_page'] < 1 || (int) $input['posts_per_page'] > 100 ) ) {
			return new WP_Error( 'invalid_posts_per_page', __( 'posts_per_page must be between 1 and 100.', 'wp-ai-bridge' ) );
		}

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$option = $this->option_name( $key );
			$value  = $this->sanitize_value( $key, $input[ $key ] );
			$old    = get_option( $option );
			if ( (string) $old === (string) $value ) {
				continue;
			}
			update_option( $option, $value );
			$changed[] = $key;
			if ( 'permalink_structure' === $key ) {
				$rewrite = true; }
			if ( in_array( $key, array( 'show_on_front', 'page_on_front', 'page_for_posts' ), true ) ) {
				$front = true; }
		}
		if ( $rewrite && function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		$this->log->record( 'wp-ai-bridge/site-settings-update', 'site', 0, true, '' );
		return array(
			'settings'           => $this->read(),
			'changed'            => $changed,
			'rewrite_flushed'    => $rewrite,
			'front_page_changed' => $front,
		);
	}

	/** @param int $id Page ID. @return bool */
	private function valid_page_id( $id ) {
		if ( 0 === $id ) {
			return true; }
		$post = get_post( $id );
		return $post && 'page' === $post->post_type && current_user_can( 'edit_post', $id );
	}
	/** @param string $key Key. @return string */
	private function option_name( $key ) {
		$map = array(
			'site_title'          => 'blogname',
			'tagline'             => 'blogdescription',
			'show_on_front'       => 'show_on_front',
			'page_on_front'       => 'page_on_front',
			'page_for_posts'      => 'page_for_posts',
			'posts_per_page'      => 'posts_per_page',
			'permalink_structure' => 'permalink_structure',
		);
		return $map[ $key ];
	}
	/** @param string $key Key. @param mixed $value Value. @return mixed */
	private function sanitize_value( $key, $value ) {
		if ( in_array( $key, array( 'page_on_front', 'page_for_posts', 'posts_per_page' ), true ) ) {
			return absint( $value ); }
		if ( 'show_on_front' === $key ) {
			return (string) $value; }
		if ( 'permalink_structure' === $key ) {
			return (string) sanitize_option( 'permalink_structure', (string) $value ); }
		return sanitize_text_field( (string) $value );
	}
	/** @return array<string,mixed> */
	private function settings_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'site_title'          => array( 'type' => 'string' ),
				'tagline'             => array( 'type' => 'string' ),
				'show_on_front'       => array(
					'type' => 'string',
					'enum' => array( 'posts', 'page' ),
				),
				'page_on_front'       => array( 'type' => 'integer' ),
				'page_for_posts'      => array( 'type' => 'integer' ),
				'posts_per_page'      => array( 'type' => 'integer' ),
				'permalink_structure' => array( 'type' => 'string' ),
			),
			'required'             => array( 'site_title', 'tagline', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'permalink_structure' ),
			'additionalProperties' => false,
		);
	}
	/** @return array<string,mixed> */
	private function update_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'site_title'          => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'tagline'             => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'show_on_front'       => array(
					'type' => 'string',
					'enum' => array( 'posts', 'page' ),
				),
				'page_on_front'       => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'page_for_posts'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'posts_per_page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
				'permalink_structure' => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			),
			'minProperties'        => 1,
			'additionalProperties' => false,
		);
	}
	/** @param bool $is_readonly Read-only. @param bool $destructive Destructive. @param bool $idempotent Idempotent. @return array<string,mixed> */
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
