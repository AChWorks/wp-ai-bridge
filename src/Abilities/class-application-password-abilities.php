<?php
/**
 * WordPress Application Password lifecycle abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Provides a typed facade over the fixed Core Application Password REST contract.
 */
final class Application_Password_Abilities {
	const MAX_ITEMS = 100;

	/** @var Permissions */
	private $permissions;

	/** @var Mutation_Log */
	private $log;

	/**
	 * Creates the Application Password provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Bounded mutation logger.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-native-builder/application-passwords-read',
			array(
				'label'               => __( 'Read Application Passwords', 'wp-native-builder-bridge' ),
				'description'         => __( 'Lists or retrieves bounded WordPress Application Password metadata without exposing stored hashes or reusable credentials.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_access' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/application-password-create',
			array(
				'label'               => __( 'Create Application Password', 'wp-native-builder-bridge' ),
				'description'         => __( 'Creates one WordPress Application Password through Core and returns the generated credential once in this response only.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->create_input_schema(),
				'output_schema'       => $this->create_output_schema(),
				'execute_callback'    => array( $this, 'create' ),
				'permission_callback' => array( $this, 'can_access' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/application-password-update',
			array(
				'label'               => __( 'Update Application Password', 'wp-native-builder-bridge' ),
				'description'         => __( 'Renames one exact WordPress Application Password through the fixed Core REST contract.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->update_input_schema(),
				'output_schema'       => $this->item_schema(),
				'execute_callback'    => array( $this, 'update' ),
				'permission_callback' => array( $this, 'can_access' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/application-password-delete',
			array(
				'label'               => __( 'Revoke Application Password', 'wp-native-builder-bridge' ),
				'description'         => __( 'Revokes one exact WordPress Application Password through Core.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->item_target_schema(),
				'output_schema'       => $this->delete_output_schema( false ),
				'execute_callback'    => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_access' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/application-passwords-delete-all',
			array(
				'label'               => __( 'Revoke All Application Passwords', 'wp-native-builder-bridge' ),
				'description'         => __( 'Revokes every WordPress Application Password for one exact user only after an explicit revoke-all confirmation token.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->delete_all_input_schema(),
				'output_schema'       => $this->delete_output_schema( true ),
				'execute_callback'    => array( $this, 'delete_all' ),
				'permission_callback' => array( $this, 'can_access' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @param array<string,mixed> $input Ability input. @return bool */
	public function can_access( $input ) {
		return is_array( $input )
			&& ! empty( $input['user_id'] )
			&& $this->permissions->allowed( Settings::GROUP_AUTHENTICATION, 'read' );
	}

	/** @param array<string,mixed> $input Ability input. @return array<string,mixed>|WP_Error */
	public function read( $input ) {
		$user_id = (int) $input['user_id'];
		$action  = isset( $input['action'] ) ? (string) $input['action'] : 'list';
		if ( ! in_array( $action, array( 'list', 'get' ), true ) ) {
			return new WP_Error( 'application_password_read_action_invalid', __( 'The Application Password read action is invalid.', 'wp-native-builder-bridge' ) );
		}
		$route = $this->collection_route( $user_id );
		if ( 'get' === $action ) {
			if ( empty( $input['uuid'] ) || ! $this->valid_uuid_token( (string) $input['uuid'] ) ) {
				return new WP_Error( 'application_password_uuid_required', __( 'A valid Application Password UUID is required for an exact read.', 'wp-native-builder-bridge' ) );
			}
			$route .= '/' . (string) $input['uuid'];
		}

		$result = $this->dispatch( 'GET', $route, array( 'context' => 'edit' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = $result['data'];
		if ( 'get' === $action ) {
			$item = $this->normalize_item( $data );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			return array(
				'user_id'   => $user_id,
				'items'     => array( $item ),
				'count'     => 1,
				'truncated' => false,
			);
		}
		if ( ! is_array( $data ) ) {
			return $this->invalid_response_error();
		}
		$total = count( $data );
		$items = array();
		foreach ( array_slice( $data, 0, self::MAX_ITEMS ) as $raw ) {
			$item = $this->normalize_item( $raw );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$items[] = $item;
		}
		return array(
			'user_id'   => $user_id,
			'items'     => $items,
			'count'     => count( $items ),
			'truncated' => $total > self::MAX_ITEMS,
		);
	}

	/** @param array<string,mixed> $input Ability input. @return array<string,mixed>|WP_Error */
	public function create( $input ) {
		$user_id = (int) $input['user_id'];
		$params  = array( 'name' => (string) $input['name'] );
		if ( ! empty( $input['app_id'] ) ) {
			$params['app_id'] = (string) $input['app_id'];
		}
		$created_uuid     = '';
		$created_password = '';
		$result           = $this->dispatch_create( $user_id, $params, $created_uuid, $created_password );
		if ( is_wp_error( $result ) ) {
			if ( $this->valid_uuid_token( $created_uuid ) ) {
				$cleanup = $this->cleanup_invalid_created_response( $user_id, $created_uuid );
				if ( is_wp_error( $cleanup ) ) {
					return $this->logged_error( $cleanup, 'wp-native-builder/application-password-create', $user_id );
				}
			}
			return $this->logged_error( $result, 'wp-native-builder/application-password-create', $user_id );
		}
		if ( ! $this->valid_uuid_token( $created_uuid ) ) {
			return $this->logged_error( $this->create_recovery_required_error(), 'wp-native-builder/application-password-create', $user_id );
		}
		$data = $result['data'];
		if ( '' === $created_password
			|| ! is_array( $data )
			|| empty( $data['password'] )
			|| ! is_string( $data['password'] )
			|| $created_password !== $data['password'] ) {
			$cleanup = $this->cleanup_invalid_created_response( $user_id, $created_uuid );
			if ( is_wp_error( $cleanup ) ) {
				return $this->logged_error( $cleanup, 'wp-native-builder/application-password-create', $user_id );
			}
			return $this->logged_error( $this->invalid_response_error(), 'wp-native-builder/application-password-create', $user_id );
		}
		if ( empty( $data['uuid'] ) || ! is_string( $data['uuid'] ) || $created_uuid !== $data['uuid'] ) {
			$cleanup = $this->cleanup_invalid_created_response( $user_id, $created_uuid );
			if ( is_wp_error( $cleanup ) ) {
				return $this->logged_error( $cleanup, 'wp-native-builder/application-password-create', $user_id );
			}
			return $this->logged_error( $this->invalid_response_error(), 'wp-native-builder/application-password-create', $user_id );
		}
		$item = $this->normalize_item( $data );
		if ( is_wp_error( $item ) ) {
			$cleanup = $this->cleanup_invalid_created_response( $user_id, $created_uuid );
			if ( is_wp_error( $cleanup ) ) {
				return $this->logged_error( $cleanup, 'wp-native-builder/application-password-create', $user_id );
			}
			return $this->logged_error( $item, 'wp-native-builder/application-password-create', $user_id );
		}
		$this->log->record( 'wp-native-builder/application-password-create', 'user', $user_id, true, '' );
		return array(
			'user_id'  => $user_id,
			'password' => $data['password'],
			'item'     => $item,
		);
	}
	/** @param array<string,mixed> $input Ability input. @return array<string,mixed>|WP_Error */
	public function update( $input ) {
		$user_id = (int) $input['user_id'];
		$uuid    = (string) $input['uuid'];
		if ( ! $this->valid_uuid_token( $uuid ) ) {
			return $this->logged_error( new WP_Error( 'application_password_uuid_invalid', __( 'The Application Password UUID is invalid.', 'wp-native-builder-bridge' ) ), 'wp-native-builder/application-password-update', $user_id );
		}
		$result = $this->dispatch( 'POST', $this->collection_route( $user_id ) . '/' . $uuid, array( 'name' => (string) $input['name'] ) );
		if ( is_wp_error( $result ) ) {
			return $this->logged_error( $result, 'wp-native-builder/application-password-update', $user_id );
		}
		$item = $this->normalize_item( $result['data'] );
		if ( is_wp_error( $item ) ) {
			return $this->logged_error( $item, 'wp-native-builder/application-password-update', $user_id );
		}
		$this->log->record( 'wp-native-builder/application-password-update', 'user', $user_id, true, '' );
		return $item;
	}

	/** @param array<string,mixed> $input Ability input. @return array<string,mixed>|WP_Error */
	public function delete( $input ) {
		$user_id = (int) $input['user_id'];
		$uuid    = (string) $input['uuid'];
		if ( ! $this->valid_uuid_token( $uuid ) ) {
			return $this->logged_error( new WP_Error( 'application_password_uuid_invalid', __( 'The Application Password UUID is invalid.', 'wp-native-builder-bridge' ) ), 'wp-native-builder/application-password-delete', $user_id );
		}
		$result = $this->dispatch( 'DELETE', $this->collection_route( $user_id ) . '/' . $uuid, array() );
		if ( is_wp_error( $result ) ) {
			return $this->logged_error( $result, 'wp-native-builder/application-password-delete', $user_id );
		}
		$data = $result['data'];
		if ( ! is_array( $data ) || empty( $data['deleted'] ) ) {
			$error = new WP_Error( 'application_password_delete_unconfirmed', __( 'WordPress did not confirm Application Password revocation.', 'wp-native-builder-bridge' ) );
			return $this->logged_error( $error, 'wp-native-builder/application-password-delete', $user_id );
		}
		$this->log->record( 'wp-native-builder/application-password-delete', 'user', $user_id, true, '' );
		return array(
			'user_id' => $user_id,
			'deleted' => true,
		);
	}

	/** @param array<string,mixed> $input Ability input. @return array<string,mixed>|WP_Error */
	public function delete_all( $input ) {
		$user_id = (int) $input['user_id'];
		if ( ! isset( $input['confirm'] ) || 'revoke_all' !== $input['confirm'] ) {
			return $this->logged_error( new WP_Error( 'application_password_revoke_all_confirmation_required', __( 'Explicit revoke-all confirmation is required.', 'wp-native-builder-bridge' ) ), 'wp-native-builder/application-passwords-delete-all', $user_id );
		}
		$result = $this->dispatch( 'DELETE', $this->collection_route( $user_id ), array() );
		if ( is_wp_error( $result ) ) {
			return $this->logged_error( $result, 'wp-native-builder/application-passwords-delete-all', $user_id );
		}
		$data = $result['data'];
		if ( ! is_array( $data ) || empty( $data['deleted'] ) || ! isset( $data['count'] ) ) {
			$error = new WP_Error( 'application_password_delete_all_unconfirmed', __( 'WordPress did not confirm bulk Application Password revocation.', 'wp-native-builder-bridge' ) );
			return $this->logged_error( $error, 'wp-native-builder/application-passwords-delete-all', $user_id );
		}
		$this->log->record( 'wp-native-builder/application-passwords-delete-all', 'user', $user_id, true, '' );
		return array(
			'user_id' => $user_id,
			'deleted' => true,
			'count'   => max( 0, (int) $data['count'] ),
		);
	}

	/** @return string */
	private function collection_route( $user_id ) {
		return '/wp/v2/users/' . (int) $user_id . '/application-passwords';
	}

	/** @return bool */
	private function valid_uuid_token( $uuid ) {
		return is_string( $uuid ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $uuid );
	}

	/**
	 * Dispatches only the fixed Core Application Password route family.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $route  Fixed route built by Bridge code.
	 * @param array<string,mixed> $params Request parameters.
	 * @return array{data:mixed,headers:array<string,mixed>}|WP_Error
	 */
	private function dispatch( $method, $route, array $params ) {
		$request = $this->build_request( $method, $route, $params );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		return $this->send_request( $request );
	}

	/**
	 * Dispatches create while capturing Core's exact pre-response credential identity.
	 *
	 * The REST response is filterable after creation, so cleanup must never trust the
	 * response UUID. Core's rest_after_insert_application_password action fires after
	 * persistence and before prepare_item_for_response() filters the response. Matching
	 * the exact WP_REST_Request object binds the captured UUID to this invocation.
	 *
	 * @param int                 $user_id      Exact user ID.
	 * @param array<string,mixed> $params       Request parameters.
	 * @param string              $created_uuid     Exact created UUID captured from Core.
	 * @param string              $created_password Exact generated password captured from Core.
	 * @return array{data:mixed,headers:array<string,mixed>}|WP_Error
	 */
	private function dispatch_create( $user_id, array $params, &$created_uuid, &$created_password ) {
		$created_uuid     = '';
		$created_password = '';
		$request          = $this->build_request( 'POST', $this->collection_route( $user_id ), $params );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		if ( ! function_exists( 'add_action' ) || ! function_exists( 'remove_action' ) ) {
			return $this->create_recovery_required_error();
		}

		$observer = function ( $item, $observed_request, $creating ) use ( &$created_uuid, &$created_password, $request ) {
			if ( true !== $creating || $observed_request !== $request || '' !== $created_uuid || ! is_array( $item ) ) {
				return;
			}
			if ( isset( $item['uuid'] ) && is_string( $item['uuid'] ) && $this->valid_uuid_token( $item['uuid'] ) ) {
				$created_uuid     = $item['uuid'];
				$created_password = isset( $item['new_password'] ) && is_string( $item['new_password'] ) ? $item['new_password'] : '';
			}
		};

		add_action( 'rest_after_insert_application_password', $observer, PHP_INT_MIN, 3 );
		try {
			return $this->send_request( $request );
		} finally {
			remove_action( 'rest_after_insert_application_password', $observer, PHP_INT_MIN );
		}
	}

	/** @return \WP_REST_Request|WP_Error */
	private function build_request( $method, $route, array $params ) {
		if ( ! class_exists( 'WP_REST_Request' ) || ! function_exists( 'rest_do_request' ) ) {
			return new WP_Error( 'application_passwords_rest_unavailable', __( 'The WordPress Application Password REST contract is unavailable.', 'wp-native-builder-bridge' ) );
		}
		if ( ! in_array( $method, array( 'GET', 'POST', 'DELETE' ), true )
			|| 1 !== preg_match( '#^/wp/v2/users/[1-9][0-9]*/application-passwords(?:/[A-Za-z0-9_-]{1,64})?$#', $route ) ) {
			return new WP_Error( 'application_passwords_rest_route_denied', __( 'The requested internal REST operation is outside the bounded Application Password contract.', 'wp-native-builder-bridge' ) );
		}
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Executes one already-bounded internal Core REST request.
	 *
	 * @param \WP_REST_Request $request Core request.
	 * @return array{data:mixed,headers:array<string,mixed>}|WP_Error
	 */
	private function send_request( $request ) {
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
			return $this->invalid_response_error();
		}
		if ( method_exists( $response, 'is_error' ) && $response->is_error() ) {
			return method_exists( $response, 'as_error' ) ? $response->as_error() : new WP_Error( 'application_passwords_rest_request_failed', __( 'WordPress rejected the Application Password REST request.', 'wp-native-builder-bridge' ) );
		}
		return array(
			'data'    => $response->get_data(),
			'headers' => method_exists( $response, 'get_headers' ) && is_array( $response->get_headers() ) ? $response->get_headers() : array(),
		);
	}
	/** @param mixed $raw Core response item. @return array<string,mixed>|WP_Error */
	private function normalize_item( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['uuid'] ) || ! is_string( $raw['uuid'] ) || ! isset( $raw['name'] ) ) {
			return $this->invalid_response_error();
		}
		return array(
			'uuid'      => (string) $raw['uuid'],
			'app_id'    => isset( $raw['app_id'] ) && is_string( $raw['app_id'] ) ? $raw['app_id'] : '',
			'name'      => (string) $raw['name'],
			'created'   => isset( $raw['created'] ) && is_string( $raw['created'] ) ? $raw['created'] : '',
			'last_used' => isset( $raw['last_used'] ) && is_string( $raw['last_used'] ) ? $raw['last_used'] : null,
		);
	}

	/**
	 * Revokes a just-created credential when Core returned an unusable response shape.
	 *
	 * @param int    $user_id      Exact user ID.
	 * @param string $created_uuid Exact UUID captured from Core before response filters.
	 * @return true|WP_Error
	 */
	private function cleanup_invalid_created_response( $user_id, $created_uuid ) {
		if ( ! $this->valid_uuid_token( $created_uuid ) ) {
			return $this->create_recovery_required_error();
		}

		$result = $this->dispatch( 'DELETE', $this->collection_route( $user_id ) . '/' . $created_uuid, array() );
		if ( is_wp_error( $result ) || ! is_array( $result['data'] ) || empty( $result['data']['deleted'] ) ) {
			return new WP_Error( 'application_password_create_cleanup_failed', __( 'WordPress created an Application Password, but the Bridge could not safely revoke it after an invalid create response.', 'wp-native-builder-bridge' ) );
		}

		return true;
	}
	/** @return WP_Error */
	private function invalid_response_error() {
		return new WP_Error( 'application_passwords_rest_invalid_response', __( 'WordPress returned an invalid Application Password response.', 'wp-native-builder-bridge' ) );
	}

	/** @return WP_Error */
	private function create_recovery_required_error() {
		return new WP_Error( 'application_password_create_recovery_required', __( 'WordPress may have created an Application Password, but the Bridge could not verify its exact identity safely. Inspect the target user\'s Application Passwords before retrying.', 'wp-native-builder-bridge' ) );
	}
	/** @return WP_Error */
	private function logged_error( WP_Error $error, $ability, $user_id ) {
		$this->log->record( (string) $ability, 'user', (int) $user_id, false, $error->get_error_code() );
		return $error;
	}

	/** @return array<string,mixed> */
	private function read_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'  => array(
					'type'    => 'string',
					'enum'    => array( 'list', 'get' ),
					'default' => 'list',
				),
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'uuid'    => $this->uuid_schema(),
			),
			'required'             => array( 'user_id' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function create_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'app_id'  => array(
					'type'      => 'string',
					'maxLength' => 64,
				),
			),
			'required'             => array( 'user_id', 'name' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function update_input_schema() {
		$schema                       = $this->item_target_schema();
		$schema['properties']['name'] = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 200,
		);
		$schema['required'][]         = 'name';
		return $schema;
	}

	/** @return array<string,mixed> */
	private function item_target_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'uuid'    => $this->uuid_schema(),
			),
			'required'             => array( 'user_id', 'uuid' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function delete_all_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'confirm' => array(
					'type' => 'string',
					'enum' => array( 'revoke_all' ),
				),
			),
			'required'             => array( 'user_id', 'confirm' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function uuid_schema() {
		return array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 64,
			'pattern'   => '^[A-Za-z0-9_-]+$',
		);
	}

	/** @return array<string,mixed> */
	private function read_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id'   => array( 'type' => 'integer' ),
				'items'     => array(
					'type'     => 'array',
					'items'    => $this->item_schema(),
					'maxItems' => self::MAX_ITEMS,
				),
				'count'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_ITEMS,
				),
				'truncated' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'user_id', 'items', 'count', 'truncated' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function create_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id'  => array( 'type' => 'integer' ),
				'password' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
				'item'     => $this->item_schema(),
			),
			'required'             => array( 'user_id', 'password', 'item' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function delete_output_schema( $include_count ) {
		$properties = array(
			'user_id' => array( 'type' => 'integer' ),
			'deleted' => array( 'type' => 'boolean' ),
		);
		$required   = array( 'user_id', 'deleted' );
		if ( $include_count ) {
			$properties['count'] = array(
				'type'    => 'integer',
				'minimum' => 0,
			);
			$required[]          = 'count';
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function item_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'uuid'      => array( 'type' => 'string' ),
				'app_id'    => array( 'type' => 'string' ),
				'name'      => array( 'type' => 'string' ),
				'created'   => array( 'type' => 'string' ),
				'last_used' => array( 'type' => array( 'string', 'null' ) ),
			),
			'required'             => array( 'uuid', 'app_id', 'name', 'created', 'last_used' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function meta( $is_readonly, $destructive, $idempotent ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => (bool) $is_readonly,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent,
			),
		);
	}
}
