<?php
/**
 * Typed WordPress site configuration abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Metadata_Key_Policy;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Provides bounded Core site settings plus provider-neutral registered REST settings.
 */
final class Site_Config_Abilities {
	const REGISTERED_SCHEMA_MAX_BYTES = 262144;
	const REGISTERED_VALUE_MAX_BYTES  = 1048576;

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
		$registered[] = wp_register_ability(
			'wp-native-builder/registered-settings-list',
			array(
				'label'               => __( 'List Registered Settings', 'wp-native-builder-bridge' ),
				'description'         => __( 'Discovers non-sensitive WordPress settings currently registered for the REST API without returning their values.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 50,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->registered_list_schema(),
				'execute_callback'    => array( $this, 'registered_settings_list' ),
				'permission_callback' => array( $this, 'can_registered_list' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/registered-setting-read',
			array(
				'label'               => __( 'Read Registered Setting', 'wp-native-builder-bridge' ),
				'description'         => __( 'Reads one exact non-sensitive REST-registered WordPress setting under Site Configuration authority.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->registered_name_input_schema(),
				'output_schema'       => $this->registered_value_schema( false ),
				'execute_callback'    => array( $this, 'registered_setting_read' ),
				'permission_callback' => array( $this, 'can_registered_manage' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/registered-setting-update',
			array(
				'label'               => __( 'Update Registered Setting', 'wp-native-builder-bridge' ),
				'description'         => __( 'Updates one exact non-sensitive REST-registered WordPress setting through the fixed Core settings route.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'       => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 200,
						),
						'value_json' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => self::REGISTERED_VALUE_MAX_BYTES,
						),
					),
					'required'             => array( 'name', 'value_json' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->registered_value_schema( true ),
				'execute_callback'    => array( $this, 'registered_setting_update' ),
				'permission_callback' => array( $this, 'can_registered_manage' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */ public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'manage_options' ); }
	/** @return bool */ public function can_update() {
		return $this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'manage_options' ); }
	/** @return bool */ public function can_registered_list() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'manage_options' ); }
	/** @return bool */ public function can_registered_manage() {
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

	/**
	 * Lists non-sensitive settings exposed through the live Core REST settings contract.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function registered_settings_list( $input ) {
		if ( ! $this->can_registered_list() ) {
			return new WP_Error( 'registered_settings_list_denied', __( 'Site Read access and WordPress settings permission are required to discover registered settings.', 'wp-native-builder-bridge' ) );
		}
		$input = is_array( $input ) ? $input : array();
		$page = isset( $input['page'] ) ? (int) $input['page'] : 1;
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 50;
		if ( $page < 1 || $per_page < 1 || $per_page > 100 ) {
			return new WP_Error( 'registered_settings_invalid_pagination', __( 'Registered settings pagination is invalid.', 'wp-native-builder-bridge' ) );
		}

		$entries = $this->registered_rest_settings();
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		$items = array();
		foreach ( $entries as $entry ) {
			if ( $this->is_sensitive_setting( $entry ) ) {
				continue;
			}
			$items[] = $this->registered_contract( $entry );
		}
		$total = count( $items );
		$pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
		$offset = ( $page - 1 ) * $per_page;

		return array(
			'items'       => array_slice( $items, $offset, $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $pages,
		);
	}

	/**
	 * Reads one exact non-sensitive registered setting through Core REST.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function registered_setting_read( $input ) {
		if ( ! $this->can_registered_manage() ) {
			return new WP_Error( 'registered_setting_read_denied', __( 'Site Configuration access and WordPress settings permission are required to read a registered setting value.', 'wp-native-builder-bridge' ) );
		}
		$entry = $this->resolve_registered_setting( is_array( $input ) && isset( $input['name'] ) ? (string) $input['name'] : '' );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$result = $this->dispatch_settings( 'GET', array(), $entry['rest_name'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! array_key_exists( $entry['rest_name'], $result ) ) {
			return $this->registered_setting_unavailable();
		}
		$value_json = $this->encode_registered_value( $result[ $entry['rest_name'] ] );
		if ( is_wp_error( $value_json ) ) {
			return $value_json;
		}

		return array(
			'setting'    => $this->registered_contract( $entry ),
			'value_json' => $value_json,
		);
	}

	/**
	 * Updates one exact non-sensitive registered setting through Core REST.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function registered_setting_update( $input ) {
		if ( ! $this->can_registered_manage() ) {
			return new WP_Error( 'registered_setting_update_denied', __( 'Site Configuration access and WordPress settings permission are required to update a registered setting.', 'wp-native-builder-bridge' ) );
		}
		$entry = $this->resolve_registered_setting( is_array( $input ) && isset( $input['name'] ) ? (string) $input['name'] : '' );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$value = $this->decode_registered_value( is_array( $input ) && isset( $input['value_json'] ) ? (string) $input['value_json'] : '' );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$before = $this->dispatch_settings( 'GET', array(), $entry['rest_name'] );
		if ( is_wp_error( $before ) || ! array_key_exists( $entry['rest_name'], is_array( $before ) ? $before : array() ) ) {
			$error = is_wp_error( $before ) ? $before : $this->registered_setting_unavailable();
			$this->log->record( 'wp-native-builder/registered-setting-update', 'site', 0, false, $error->get_error_code() );
			return $error;
		}

		$result = $this->dispatch_settings(
			'POST',
			array( $entry['rest_name'] => $value ),
			$entry['rest_name']
		);
		if ( is_wp_error( $result ) || ! array_key_exists( $entry['rest_name'], is_array( $result ) ? $result : array() ) ) {
			$error = is_wp_error( $result ) ? $result : $this->registered_setting_unavailable();
			$this->log->record( 'wp-native-builder/registered-setting-update', 'site', 0, false, $error->get_error_code() );
			return $error;
		}

		$before_json = $this->encode_registered_value( $before[ $entry['rest_name'] ] );
		$value_json  = $this->encode_registered_value( $result[ $entry['rest_name'] ] );
		if ( is_wp_error( $before_json ) || is_wp_error( $value_json ) ) {
			$error = is_wp_error( $value_json ) ? $value_json : $before_json;
			$this->log->record( 'wp-native-builder/registered-setting-update', 'site', 0, false, $error->get_error_code() );
			return $error;
		}
		$this->log->record( 'wp-native-builder/registered-setting-update', 'site', 0, true, '' );

		return array(
			'setting'    => $this->registered_contract( $entry ),
			'value_json' => $value_json,
			'changed'    => $before_json !== $value_json,
		);
	}

	/**
	 * Returns Core's current registered REST settings with exact REST aliases and schema.
	 *
	 * @return array<string,array<string,mixed>>|WP_Error
	 */
	private function registered_rest_settings() {
		if ( ! function_exists( 'rest_get_server' ) || ! function_exists( 'get_registered_settings' ) || ! class_exists( 'WP_REST_Settings_Controller' ) ) {
			return new WP_Error( 'registered_settings_rest_unavailable', __( 'The WordPress registered settings REST contract is unavailable.', 'wp-native-builder-bridge' ) );
		}

		rest_get_server();
		$controller = new \WP_REST_Settings_Controller();
		$schema = $controller->get_item_schema();
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$entries = array();

		foreach ( get_registered_settings() as $option_name => $args ) {
			if ( ! is_array( $args ) || empty( $args['show_in_rest'] ) ) {
				continue;
			}
			$rest_name = (string) $option_name;
			if ( is_array( $args['show_in_rest'] ) && ! empty( $args['show_in_rest']['name'] ) ) {
				$rest_name = (string) $args['show_in_rest']['name'];
			}
			if ( '' === $rest_name || ! isset( $properties[ $rest_name ] ) || ! is_array( $properties[ $rest_name ] ) ) {
				continue;
			}
			$entries[ $rest_name ] = array(
				'rest_name'   => $rest_name,
				'option_name' => (string) $option_name,
				'schema'      => $properties[ $rest_name ],
			);
		}
		ksort( $entries, SORT_STRING );

		return $entries;
	}

	/** @param string $name REST-visible setting name. @return array<string,mixed>|WP_Error */
	private function resolve_registered_setting( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name || strlen( $name ) > 200 ) {
			return $this->registered_setting_unavailable();
		}
		$entries = $this->registered_rest_settings();
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		if ( ! isset( $entries[ $name ] ) || $this->is_sensitive_setting( $entries[ $name ] ) ) {
			return $this->registered_setting_unavailable();
		}

		return $entries[ $name ];
	}

	/** @param array<string,mixed> $entry Registered setting entry. @return bool */
	private function is_sensitive_setting( array $entry ) {
		return Metadata_Key_Policy::is_sensitive( $entry['rest_name'] ) || Metadata_Key_Policy::is_sensitive( $entry['option_name'] );
	}

	/** @param array<string,mixed> $entry Registered setting entry. @return array<string,mixed> */
	private function registered_contract( array $entry ) {
		$schema = $this->public_registered_schema( $entry['schema'] );
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES );
		$schema_available = is_string( $json ) && strlen( $json ) <= self::REGISTERED_SCHEMA_MAX_BYTES;

		return array(
			'name'             => $entry['rest_name'],
			'type'             => isset( $entry['schema']['type'] ) ? (string) $entry['schema']['type'] : '',
			'title'            => $this->bounded_text( isset( $entry['schema']['title'] ) ? $entry['schema']['title'] : '', 200 ),
			'description'      => $this->bounded_text( isset( $entry['schema']['description'] ) ? $entry['schema']['description'] : '', 1000 ),
			'schema_json'      => $schema_available ? $json : '',
			'schema_available' => $schema_available,
		);
	}

	/** @param mixed $schema Schema value. @return mixed */
	private function public_registered_schema( $schema ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}
		$clean = array();
		foreach ( $schema as $key => $value ) {
			if ( in_array( (string) $key, array( 'default', 'example', 'examples', 'arg_options' ), true ) ) {
				continue;
			}
			$clean[ $key ] = is_array( $value ) ? $this->public_registered_schema( $value ) : $value;
		}
		return $clean;
	}

	/** @param mixed $value Value. @return string|WP_Error */
	private function encode_registered_value( $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || strlen( $json ) > self::REGISTERED_VALUE_MAX_BYTES ) {
			return new WP_Error( 'registered_setting_value_too_large', __( 'The registered setting value is too large for the bounded generic settings contract.', 'wp-native-builder-bridge' ) );
		}
		return $json;
	}

	/** @param string $json JSON value. @return mixed|WP_Error */
	private function decode_registered_value( $json ) {
		if ( '' === $json || strlen( $json ) > self::REGISTERED_VALUE_MAX_BYTES ) {
			return new WP_Error( 'registered_setting_invalid_value', __( 'Registered setting value_json must contain one bounded JSON value.', 'wp-native-builder-bridge' ) );
		}
		$value = json_decode( $json, true, 64 );
		if ( JSON_ERROR_NONE !== json_last_error() || null === $value ) {
			return new WP_Error( 'registered_setting_invalid_value', __( 'Registered setting value_json must contain one bounded non-null JSON value.', 'wp-native-builder-bridge' ) );
		}
		return $value;
	}

	/**
	 * Dispatches one request to the fixed Core settings route and returns only one requested field.
	 *
	 * @param string              $method HTTP method.
	 * @param array<string,mixed> $params Exact setting payload.
	 * @param string              $field  Exact REST-visible setting field.
	 * @return array<string,mixed>|WP_Error
	 */
	private function dispatch_settings( $method, array $params, $field ) {
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) || ! class_exists( 'WP_REST_Request' ) || ! function_exists( 'rest_do_request' ) ) {
			return new WP_Error( 'registered_settings_rest_unavailable', __( 'The WordPress registered settings REST contract is unavailable.', 'wp-native-builder-bridge' ) );
		}
		$request = new \WP_REST_Request( $method, '/wp/v2/settings' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$request->set_param( '_fields', (string) $field );
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
			return new WP_Error( 'registered_settings_invalid_response', __( 'WordPress returned an invalid registered settings REST response.', 'wp-native-builder-bridge' ) );
		}
		if ( method_exists( $response, 'is_error' ) && $response->is_error() ) {
			return method_exists( $response, 'as_error' ) ? $response->as_error() : new WP_Error( 'registered_settings_request_failed', __( 'WordPress rejected the registered settings REST request.', 'wp-native-builder-bridge' ) );
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! array_key_exists( (string) $field, $data ) ) {
			return $this->registered_setting_unavailable();
		}

		return array( (string) $field => $data[ $field ] );
	}

	/** @return WP_Error */
	private function registered_setting_unavailable() {
		return new WP_Error( 'registered_setting_unavailable', __( 'The requested setting is unavailable through the bounded registered settings contract.', 'wp-native-builder-bridge' ) );
	}

	/** @param mixed $value Text. @param int $limit Character limit. @return string */
	private function bounded_text( $value, $limit ) {
		$text = wp_strip_all_tags( (string) $value, true );
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, $limit, 'UTF-8' );
		}
		return (string) substr( $text, 0, $limit );
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
	/** @return array<string,mixed> */
	private function registered_name_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}
	/** @return array<string,mixed> */
	private function registered_contract_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'             => array( 'type' => 'string' ),
				'type'             => array(
					'type' => 'string',
					'enum' => array( 'string', 'boolean', 'integer', 'number', 'array', 'object' ),
				),
				'title'            => array( 'type' => 'string' ),
				'description'      => array( 'type' => 'string' ),
				'schema_json'      => array( 'type' => 'string' ),
				'schema_available' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'name', 'type', 'title', 'description', 'schema_json', 'schema_available' ),
			'additionalProperties' => false,
		);
	}
	/** @return array<string,mixed> */
	private function registered_list_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items'       => array(
					'type'  => 'array',
					'items' => $this->registered_contract_schema(),
				),
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
				'total'       => array( 'type' => 'integer' ),
				'total_pages' => array( 'type' => 'integer' ),
			),
			'required'             => array( 'items', 'page', 'per_page', 'total', 'total_pages' ),
			'additionalProperties' => false,
		);
	}
	/** @param bool $include_changed Include changed flag. @return array<string,mixed> */
	private function registered_value_schema( $include_changed ) {
		$properties = array(
			'setting'    => $this->registered_contract_schema(),
			'value_json' => array( 'type' => 'string' ),
		);
		$required = array( 'setting', 'value_json' );
		if ( $include_changed ) {
			$properties['changed'] = array( 'type' => 'boolean' );
			$required[] = 'changed';
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
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
