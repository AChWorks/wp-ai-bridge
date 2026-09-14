<?php
/**
 * Provider-neutral WordPress registered REST settings abilities.
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
 * Delegates exact non-sensitive settings through WordPress Core's REST contract.
 */
final class Registered_Settings_Abilities {
	const SCHEMA_MAX_BYTES = 262144;
	const VALUE_MAX_BYTES  = 1048576;

	/** @var Permissions */
	private $permissions;

	/** @var Mutation_Log */
	private $log;

	/**
	 * Creates the registered-settings provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Bounded mutation logger.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/**
	 * Registers provider-neutral settings abilities.
	 *
	 * @return array<int,object> Registered Ability objects.
	 */
	public function register() {
		$registered   = array();
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
				'output_schema'       => $this->list_schema(),
				'execute_callback'    => array( $this, 'list_settings' ),
				'permission_callback' => array( $this, 'can_list' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/registered-setting-read',
			array(
				'label'               => __( 'Read Registered Setting', 'wp-native-builder-bridge' ),
				'description'         => __( 'Reads one exact non-sensitive REST-registered WordPress setting under Site Configuration authority.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->name_input_schema(),
				'output_schema'       => $this->value_schema(),
				'execute_callback'    => array( $this, 'read_setting' ),
				'permission_callback' => array( $this, 'can_manage' ),
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
							'maxLength' => self::VALUE_MAX_BYTES,
						),
					),
					'required'             => array( 'name', 'value_json' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->value_schema(),
				'execute_callback'    => array( $this, 'update_setting' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */
	public function can_list() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'manage_options' );
	}

	/** @return bool */
	public function can_manage() {
		return $this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'manage_options' );
	}

	/**
	 * Lists non-sensitive live REST setting contracts without values.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function list_settings( $input ) {
		if ( ! $this->can_list() ) {
			return new WP_Error( 'registered_settings_list_denied', __( 'Site Read access and WordPress settings permission are required to discover registered settings.', 'wp-native-builder-bridge' ) );
		}

		$input    = is_array( $input ) ? $input : array();
		$page     = isset( $input['page'] ) ? (int) $input['page'] : 1;
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 50;
		if ( $page < 1 || $per_page < 1 || $per_page > 100 ) {
			return new WP_Error( 'registered_settings_invalid_pagination', __( 'Registered settings pagination is invalid.', 'wp-native-builder-bridge' ) );
		}
		if ( $page - 1 > intdiv( PHP_INT_MAX, $per_page ) ) {
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
			$items[] = $this->contract( $entry );
		}

		$total  = count( $items );
		$pages  = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
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
	 * Reads one exact non-sensitive registered setting.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read_setting( $input ) {
		if ( ! $this->can_manage() ) {
			return new WP_Error( 'registered_setting_read_denied', __( 'Site Configuration access and WordPress settings permission are required to read a registered setting value.', 'wp-native-builder-bridge' ) );
		}

		$entry = $this->resolve_setting( is_array( $input ) && isset( $input['name'] ) ? (string) $input['name'] : '' );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		$result = $this->dispatch( 'GET', array(), $entry['rest_name'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! array_key_exists( $entry['rest_name'], $result ) ) {
			return $this->unavailable();
		}

		$value = $this->bounded_value( $result[ $entry['rest_name'] ] );

		return array(
			'setting'         => $this->contract( $entry ),
			'value_json'      => $value['value_json'],
			'value_available' => $value['value_available'],
		);
	}

	/**
	 * Updates one exact non-sensitive registered setting.
	 *
	 * A successful Core POST is reported as success even when a provider returns a
	 * post-mutation value that is too large or not JSON-representable. This avoids
	 * claiming that a committed mutation failed merely because ordinary output
	 * could not safely carry its resulting value.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update_setting( $input ) {
		if ( ! $this->can_manage() ) {
			return new WP_Error( 'registered_setting_update_denied', __( 'Site Configuration access and WordPress settings permission are required to update a registered setting.', 'wp-native-builder-bridge' ) );
		}

		$entry = $this->resolve_setting( is_array( $input ) && isset( $input['name'] ) ? (string) $input['name'] : '' );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		if ( $this->requires_specialized_update( $entry ) ) {
			return new WP_Error(
				'registered_setting_specialized_update_required',
				__( 'The requested setting is unavailable through the bounded registered settings contract.', 'wp-native-builder-bridge' ),
				array( 'ability' => 'wp-native-builder/site-settings-update' )
			);
		}

		$value = $this->decode_value( is_array( $input ) && isset( $input['value_json'] ) ? (string) $input['value_json'] : '' );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$result = $this->dispatch(
			'POST',
			array( $entry['rest_name'] => $value ),
			$entry['rest_name']
		);
		if ( is_wp_error( $result ) ) {
			$this->log->record( 'wp-native-builder/registered-setting-update', 'site', 0, false, $result->get_error_code() );
			return $result;
		}

		$output_value = array(
			'value_json'      => '',
			'value_available' => false,
		);
		if ( array_key_exists( $entry['rest_name'], $result ) ) {
			$output_value = $this->bounded_value( $result[ $entry['rest_name'] ] );
		}

		$this->log->record( 'wp-native-builder/registered-setting-update', 'site', 0, true, '' );

		return array(
			'setting'         => $this->contract( $entry ),
			'value_json'      => $output_value['value_json'],
			'value_available' => $output_value['value_available'],
		);
	}

	/**
	 * Returns Core's live REST-registered settings with exact aliases and schemas.
	 *
	 * @return array<string,array<string,mixed>>|WP_Error
	 */
	private function registered_rest_settings() {
		if ( ! function_exists( 'rest_get_server' ) || ! function_exists( 'get_registered_settings' ) || ! class_exists( 'WP_REST_Settings_Controller' ) ) {
			return new WP_Error( 'registered_settings_rest_unavailable', __( 'The WordPress registered settings REST contract is unavailable.', 'wp-native-builder-bridge' ) );
		}

		rest_get_server();
		$controller = new \WP_REST_Settings_Controller();
		$schema     = $controller->get_item_schema();
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$entries    = array();

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

	/**
	 * Resolves one exact REST-visible setting and applies the generic secret boundary.
	 *
	 * @param string $name REST-visible setting name.
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolve_setting( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name || strlen( $name ) > 200 ) {
			return $this->unavailable();
		}

		$entries = $this->registered_rest_settings();
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		if ( ! isset( $entries[ $name ] ) || $this->is_sensitive_setting( $entries[ $name ] ) ) {
			return $this->unavailable();
		}

		return $entries[ $name ];
	}

	/**
	 * Keeps existing bounded Core site-setting semantics owned by the specialized Ability.
	 *
	 * The generic surface must not become an alternate mutation path around validation,
	 * bounds, or side effects already implemented by `site-settings-update`.
	 *
	 * @param array<string,mixed> $entry Registered setting entry.
	 * @return bool
	 */
	private function requires_specialized_update( array $entry ) {
		return in_array(
			$entry['option_name'],
			array(
				'blogname',
				'blogdescription',
				'show_on_front',
				'page_on_front',
				'page_for_posts',
				'posts_per_page',
				'permalink_structure',
			),
			true
		);
	}

	/**
	 * Checks identities and structured schemas for credential/security material.
	 *
	 * @param array<string,mixed> $entry Registered setting entry.
	 * @return bool
	 */
	private function is_sensitive_setting( array $entry ) {
		return $this->is_sensitive_name( $entry['rest_name'] )
			|| $this->is_sensitive_name( $entry['option_name'] )
			|| $this->schema_contains_sensitive_contract( $entry['schema'] );
	}

	/**
	 * Checks one setting identity for credential/security key forms.
	 *
	 * @param string $name Setting identity.
	 * @return bool
	 */
	private function is_sensitive_name( $name ) {
		if ( Metadata_Key_Policy::is_sensitive( $name ) ) {
			return true;
		}

		$bounded    = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '_', (string) $name );
		$normalized = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $bounded ) );
		$normalized = trim( $normalized, '_' );

		return 1 === preg_match( '/(^|_)(tokens?|(?:access|auth|authentication|consumer|license|encryption|signing)_(?:keys?))($|_)/', $normalized );
	}

	/**
	 * Fails closed when a structured setting contains sensitive nested identities.
	 *
	 * WordPress Core constrains REST object schemas, but a safe top-level setting
	 * name can still wrap a credential field. Generic read/update must not become a
	 * secret-management path merely because that containing option has a neutral name.
	 *
	 * @param mixed $schema          Registered REST schema fragment.
	 * @param bool  $allow_untyped  Whether a composition parent supplies the type.
	 * @return bool
	 */
	private function schema_contains_sensitive_contract( $schema, $allow_untyped = false ) {
		if ( ! is_array( $schema ) ) {
			return false;
		}

		$types         = isset( $schema['type'] ) ? (array) $schema['type'] : array();
		$allowed_types = array( 'array', 'object', 'string', 'number', 'integer', 'boolean', 'null' );
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) || ! in_array( $type, $allowed_types, true ) ) {
				return true;
			}
		}

		$has_composition = false;
		foreach ( array( 'anyOf', 'oneOf' ) as $composition_key ) {
			if ( ! array_key_exists( $composition_key, $schema ) ) {
				continue;
			}
			$has_composition = true;
			$branches        = $schema[ $composition_key ];
			if ( ! is_array( $branches ) || empty( $branches ) ) {
				return true;
			}
			foreach ( $branches as $branch ) {
				if ( ! is_array( $branch ) || $this->schema_contains_sensitive_contract( $branch, ! empty( $types ) ) ) {
					return true;
				}
			}
		}

		if ( empty( $types ) && ! $has_composition && ! $allow_untyped ) {
			return true;
		}

		$is_object_schema = in_array( 'object', $types, true ) || isset( $schema['properties'] ) || isset( $schema['patternProperties'] ) || array_key_exists( 'additionalProperties', $schema );
		if ( $is_object_schema ) {
			$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
			if ( empty( $properties ) || ! empty( $schema['patternProperties'] ) ) {
				return true;
			}
			if ( ! array_key_exists( 'additionalProperties', $schema ) || false !== $schema['additionalProperties'] ) {
				return true;
			}
			foreach ( $properties as $property_name => $property_schema ) {
				if ( $this->is_sensitive_name( (string) $property_name ) || $this->schema_contains_sensitive_contract( $property_schema ) ) {
					return true;
				}
			}
		}

		$is_array_schema = in_array( 'array', $types, true ) || isset( $schema['items'] );
		if ( $is_array_schema ) {
			if ( ! isset( $schema['items'] ) || ! is_array( $schema['items'] ) ) {
				return true;
			}
			if ( $this->schema_contains_sensitive_contract( $schema['items'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a value-free public contract for one registered setting.
	 *
	 * @param array<string,mixed> $entry Registered setting entry.
	 * @return array<string,mixed>
	 */
	private function contract( array $entry ) {
		$schema           = $this->public_schema( $entry['schema'] );
		$json             = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES );
		$schema_available = is_string( $json ) && strlen( $json ) <= self::SCHEMA_MAX_BYTES;

		return array(
			'name'             => $entry['rest_name'],
			'type'             => isset( $entry['schema']['type'] ) ? (string) $entry['schema']['type'] : '',
			'title'            => $this->bounded_text( isset( $entry['schema']['title'] ) ? $entry['schema']['title'] : '', 200 ),
			'description'      => $this->bounded_text( isset( $entry['schema']['description'] ) ? $entry['schema']['description'] : '', 1000 ),
			'schema_json'      => $schema_available ? $json : '',
			'schema_available' => $schema_available,
		);
	}

	/**
	 * Removes schema-level runtime/default values without rewriting property names.
	 *
	 * JSON Schema maps such as `properties` use arbitrary provider field names, so a
	 * legitimate field named `default` or `example` must not be mistaken for the
	 * schema keyword of the same name.
	 *
	 * @param mixed $schema       Schema value.
	 * @param bool  $property_map Whether the current array is a map of schema names.
	 * @return mixed
	 */
	private function public_schema( $schema, $property_map = false ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		$clean = array();
		foreach ( $schema as $key => $value ) {
			if ( ! $property_map && in_array( (string) $key, array( 'default', 'example', 'examples', 'arg_options' ), true ) ) {
				continue;
			}

			if ( $property_map ) {
				$clean[ $key ] = is_array( $value ) ? $this->public_schema( $value ) : $value;
				continue;
			}

			if ( in_array( (string) $key, array( 'properties', 'patternProperties', 'definitions', '$defs', 'dependentSchemas' ), true ) ) {
				$clean[ $key ] = is_array( $value ) ? $this->public_schema( $value, true ) : $value;
				continue;
			}

			if ( in_array( (string) $key, array( 'anyOf', 'oneOf', 'allOf' ), true ) && is_array( $value ) ) {
				$branches = array();
				foreach ( $value as $branch_key => $branch ) {
					$branches[ $branch_key ] = is_array( $branch ) ? $this->public_schema( $branch ) : $branch;
				}
				$clean[ $key ] = $branches;
				continue;
			}

			if ( in_array( (string) $key, array( 'items', 'additionalProperties', 'not', 'if', 'then', 'else', 'contains', 'propertyNames' ), true ) && is_array( $value ) ) {
				$clean[ $key ] = $this->public_schema( $value );
				continue;
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * Encodes one ordinary response value without turning output bounds into mutation failure.
	 *
	 * @param mixed $value Setting value.
	 * @return array{value_json:string,value_available:bool}
	 */
	private function bounded_value( $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || strlen( $json ) > self::VALUE_MAX_BYTES ) {
			return array(
				'value_json'      => '',
				'value_available' => false,
			);
		}

		return array(
			'value_json'      => $json,
			'value_available' => true,
		);
	}

	/**
	 * Decodes one bounded non-null JSON input value.
	 *
	 * @param string $json JSON value.
	 * @return mixed|WP_Error
	 */
	private function decode_value( $json ) {
		if ( '' === $json || strlen( $json ) > self::VALUE_MAX_BYTES ) {
			return new WP_Error( 'registered_setting_invalid_value', __( 'Registered setting value_json must contain one bounded JSON value.', 'wp-native-builder-bridge' ) );
		}

		$value = json_decode( $json, true, 64 );
		if ( JSON_ERROR_NONE !== json_last_error() || null === $value ) {
			return new WP_Error( 'registered_setting_invalid_value', __( 'Registered setting value_json must contain one bounded non-null JSON value.', 'wp-native-builder-bridge' ) );
		}

		return $value;
	}

	/**
	 * Dispatches one request to the fixed Core settings route.
	 *
	 * The request includes `_fields` for normal Core shaping, but Bridge still
	 * selects only the exact target field before returning any value to callers.
	 *
	 * @param string              $method HTTP method.
	 * @param array<string,mixed> $params Exact setting payload.
	 * @param string              $field  Exact REST-visible setting field.
	 * @return array<string,mixed>|WP_Error
	 */
	private function dispatch( $method, array $params, $field ) {
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
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'registered_settings_invalid_response', __( 'WordPress returned an invalid registered settings REST response.', 'wp-native-builder-bridge' ) );
		}

		return array_key_exists( (string) $field, $data ) ? array( (string) $field => $data[ $field ] ) : array();
	}

	/** @return WP_Error */
	private function unavailable() {
		return new WP_Error( 'registered_setting_unavailable', __( 'The requested setting is unavailable through the bounded registered settings contract.', 'wp-native-builder-bridge' ) );
	}

	/**
	 * Returns bounded plain-text contract metadata.
	 *
	 * @param mixed $value Text.
	 * @param int   $limit Character limit.
	 * @return string
	 */
	private function bounded_text( $value, $limit ) {
		$text = wp_strip_all_tags( (string) $value, true );
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, $limit, 'UTF-8' );
		}

		return (string) substr( $text, 0, $limit );
	}

	/** @return array<string,mixed> */
	private function name_input_schema() {
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
	private function contract_schema() {
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
	private function list_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items'       => array(
					'type'  => 'array',
					'items' => $this->contract_schema(),
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

	/** @return array<string,mixed> */
	private function value_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'setting'         => $this->contract_schema(),
				'value_json'      => array( 'type' => 'string' ),
				'value_available' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'setting', 'value_json', 'value_available' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns MCP annotations for one settings Ability.
	 *
	 * @param bool $is_readonly Read-only.
	 * @param bool $destructive Destructive.
	 * @param bool $idempotent  Idempotent.
	 * @return array<string,mixed>
	 */
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
