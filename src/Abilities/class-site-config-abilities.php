<?php
/**
 * Typed WordPress site configuration abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
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
			'wp-native-builder/site-settings-read',
			array(
				'label'               => __( 'Read Site Settings', 'wp-native-builder-bridge' ),
				'description'         => __( 'Reads the bounded Core WordPress settings used for site building without exposing arbitrary options.', 'wp-native-builder-bridge' ),
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
			'wp-native-builder/site-settings-update',
			array(
				'label'               => __( 'Update Site Settings', 'wp-native-builder-bridge' ),
				'description'         => __( 'Updates only the named builder-relevant Core WordPress settings and reports permalink/front-page impact.', 'wp-native-builder-bridge' ),
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

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */ public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'manage_options' ); }
	/** @return bool */ public function can_update() {
		return $this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'manage_options' ); }

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
			return new WP_Error( 'invalid_site_settings', __( 'Site settings input must be an object.', 'wp-native-builder-bridge' ) );
		}
		$allowed = array( 'site_title', 'tagline', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'permalink_structure' );
		$changed = array();
		$rewrite = false;
		$front   = false;

		if ( isset( $input['show_on_front'] ) && ! in_array( $input['show_on_front'], array( 'posts', 'page' ), true ) ) {
			return new WP_Error( 'invalid_front_mode', __( 'show_on_front must be posts or page.', 'wp-native-builder-bridge' ) );
		}
		foreach ( array( 'page_on_front', 'page_for_posts' ) as $key ) {
			if ( array_key_exists( $key, $input ) && ! $this->valid_page_id( (int) $input[ $key ] ) ) {
				return new WP_Error( 'invalid_front_page', __( 'Front-page and posts-page IDs must reference published or editable WordPress pages, or be zero.', 'wp-native-builder-bridge' ) );
			}
		}
		$future_front = array_key_exists( 'page_on_front', $input ) ? (int) $input['page_on_front'] : (int) get_option( 'page_on_front', 0 );
		$future_posts = array_key_exists( 'page_for_posts', $input ) ? (int) $input['page_for_posts'] : (int) get_option( 'page_for_posts', 0 );
		if ( $future_front > 0 && $future_front === $future_posts ) {
			return new WP_Error( 'front_pages_must_differ', __( 'The front page and posts page must be different pages.', 'wp-native-builder-bridge' ) );
		}
		if ( isset( $input['posts_per_page'] ) && ( (int) $input['posts_per_page'] < 1 || (int) $input['posts_per_page'] > 100 ) ) {
			return new WP_Error( 'invalid_posts_per_page', __( 'posts_per_page must be between 1 and 100.', 'wp-native-builder-bridge' ) );
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
		$this->log->record( 'wp-native-builder/site-settings-update', 'site', 0, true, '' );
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
