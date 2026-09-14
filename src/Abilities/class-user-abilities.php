<?php
/**
 * Bounded WordPress user and role administration abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Manages users through Core APIs without exposing authentication secrets.
 */
final class User_Abilities {
	/** @var Permissions */ private $permissions;
	/** @var Mutation_Log */ private $log;
	/** @param Permissions $permissions Permissions. @param Mutation_Log $log Log. */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-native-builder/users-read',
			array(
				'label'               => __( 'Read Users and Roles', 'wp-native-builder-bridge' ),
				'description'         => __( 'Lists or retrieves bounded WordPress user and editable-role information without password, token, session, or application-password material.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'action' => array(
							'type' => 'string',
							'enum' => array( 'list', 'get', 'roles' ),
						),
						'id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'search' => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 50,
						),
					),
					'required'             => array( 'action' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->read_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/user-upsert',
			array(
				'label'               => __( 'Create or Update User', 'wp-native-builder-bridge' ),
				'description'         => __( 'Creates or updates a WordPress user and assigns an existing editable role. New credentials are generated internally and never returned or logged.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->upsert_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'user'              => $this->user_schema(),
						'notification_sent' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'user', 'notification_sent' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'upsert' ),
				'permission_callback' => array( $this, 'can_upsert' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/user-remove',
			array(
				'label'               => __( 'Remove User', 'wp-native-builder-bridge' ),
				'description'         => __( 'Removes a user only with an explicit valid content reassignment target; on multisite this removes the user from the current site rather than deleting the network account.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'reassign_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'id', 'reassign_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'removed'                   => array( 'type' => 'boolean' ),
						'id'                        => array( 'type' => 'integer' ),
						'reassign_id'               => array( 'type' => 'integer' ),
						'network_account_preserved' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'removed', 'id', 'reassign_id', 'network_account_preserved' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'remove' ),
				'permission_callback' => array( $this, 'can_remove' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}
	/** @return bool */ public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'list_users' );}
	/** @param array<string,mixed> $input Input. @return bool */
	public function can_upsert( $input ) {
		if ( ! is_array( $input ) || empty( $input['action'] ) ) {
			return false;
		}return $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'create' === $input['action'] ? 'create_users' : 'edit_users' );}
	/** @return bool */ public function can_remove() {
		return $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'delete_users' );}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function read( $input ) {
		$this->load_user_admin();
		$action = (string) $input['action'];
		if ( 'roles' === $action ) {
			$roles = array();
			foreach ( get_editable_roles() as $key => $role ) {
				$roles[] = array(
					'role'         => (string) $key,
					'name'         => isset( $role['name'] ) ? (string) $role['name'] : '',
					'capabilities' => isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? array_keys( array_filter( $role['capabilities'] ) ) : array(),
				);
			}return array(
				'users' => array(),
				'roles' => $roles,
			);}
		if ( 'get' === $action ) {
			$user = get_userdata( (int) $input['id'] );
			if ( ! $user ) {
				return new WP_Error( 'user_not_found', __( 'The requested WordPress user was not found.', 'wp-native-builder-bridge' ) );
			}return array(
				'users' => array( $this->format_user( $user ) ),
				'roles' => array(),
			);}
		$args = array(
			'number'  => isset( $input['limit'] ) ? (int) $input['limit'] : 50,
			'orderby' => 'ID',
			'order'   => 'ASC',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search']         = '*' . sanitize_text_field( (string) $input['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
		}$query = new \WP_User_Query( $args );
		$users  = array();
		foreach ( $query->get_results() as $user ) {
			$users[] = $this->format_user( $user );
		}return array(
			'users' => $users,
			'roles' => array(),
		);}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function upsert( $input ) {
		$this->load_user_admin();
		$action = (string) $input['action'];
		$role   = isset( $input['role'] ) ? (string) $input['role'] : '';
		if ( '' !== $role && ! $this->valid_role( $role ) ) {
			return new WP_Error( 'invalid_user_role', __( 'The requested role is not editable by the current WordPress user.', 'wp-native-builder-bridge' ) );}
		if ( 'create' === $action ) {
			if ( ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'create_users' ) ) {
				return new WP_Error( 'user_create_denied', __( 'User creation is not permitted.', 'wp-native-builder-bridge' ) );
			}if ( empty( $input['username'] ) || empty( $input['email'] ) ) {
				return new WP_Error( 'user_identity_required', __( 'username and email are required for user creation.', 'wp-native-builder-bridge' ) );
			}$data = array(
				'user_login'   => sanitize_user( (string) $input['username'], true ),
				'user_email'   => sanitize_email( (string) $input['email'] ),
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => isset( $input['display_name'] ) ? sanitize_text_field( (string) $input['display_name'] ) : '',
				'first_name'   => isset( $input['first_name'] ) ? sanitize_text_field( (string) $input['first_name'] ) : '',
				'last_name'    => isset( $input['last_name'] ) ? sanitize_text_field( (string) $input['last_name'] ) : '',
			);
			if ( '' !== $role ) {
				$data['role'] = $role;
			}$id = wp_insert_user( $data );
			if ( is_wp_error( $id ) ) {
				return $id;
			}$notification = false;
			if ( function_exists( 'wp_new_user_notification' ) ) {
				wp_new_user_notification( (int) $id, null, 'user' );
				$notification = true;
			}$this->log->record( 'wp-native-builder/user-upsert', 'user', (int) $id, true, '' );
			return array(
				'user'              => $this->format_user( get_userdata( (int) $id ) ),
				'notification_sent' => $notification,
			);}
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$user = get_userdata( $id );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', __( 'The WordPress user to update was not found.', 'wp-native-builder-bridge' ) );
		}if ( ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'edit_users' ) || ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'user_edit_denied', __( 'The current WordPress user cannot edit that user.', 'wp-native-builder-bridge' ) );
		}if ( '' !== $role ) {
			if ( get_current_user_id() === $id ) {
				return new WP_Error( 'self_role_change_denied', __( 'The acting user role cannot be changed through this ability.', 'wp-native-builder-bridge' ) );
			}if ( ! current_user_can( 'promote_user', $id ) ) {
				return new WP_Error( 'user_role_change_denied', __( 'The current WordPress user cannot change that user role.', 'wp-native-builder-bridge' ) );}
		}
		$data = array( 'ID' => $id );
		foreach ( array(
			'email'        => 'user_email',
			'display_name' => 'display_name',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
		) as $input_key => $field ) {
			if ( array_key_exists( $input_key, $input ) ) {
				$data[ $field ] = 'email' === $input_key ? sanitize_email( (string) $input[ $input_key ] ) : sanitize_text_field( (string) $input[ $input_key ] );
			}
		}if ( '' !== $role ) {
			$data['role'] = $role;
		}$result = wp_update_user( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}$this->log->record( 'wp-native-builder/user-upsert', 'user', $id, true, '' );
		return array(
			'user'              => $this->format_user( get_userdata( $id ) ),
			'notification_sent' => false,
		);}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function remove( $input ) {
		$this->load_user_admin();
		$id       = (int) $input['id'];
		$reassign = (int) $input['reassign_id'];
		if ( get_current_user_id() === $id ) {
			return new WP_Error( 'self_delete_denied', __( 'The acting WordPress user cannot remove itself through this ability.', 'wp-native-builder-bridge' ) );
		}$user  = get_userdata( $id );
		$target = get_userdata( $reassign );
		if ( ! $user || ! $target || $id === $reassign ) {
			return new WP_Error( 'invalid_user_reassignment', __( 'Both the user and a different valid reassignment user are required.', 'wp-native-builder-bridge' ) );
		}if ( ! current_user_can( 'delete_user', $id ) ) {
			return new WP_Error( 'user_delete_denied', __( 'The current WordPress user cannot remove that user.', 'wp-native-builder-bridge' ) );}
		if ( is_multisite() ) {
			$result    = remove_user_from_blog( $id, get_current_blog_id(), $reassign );
			$preserved = true;
		} else {
			$result    = wp_delete_user( $id, $reassign );
			$preserved = false;
		}if ( is_wp_error( $result ) || true !== $result ) {
			return is_wp_error( $result ) ? $result : new WP_Error( 'user_remove_failed', __( 'WordPress did not remove the user.', 'wp-native-builder-bridge' ) );
		}$this->log->record( 'wp-native-builder/user-remove', 'user', $id, true, '' );
		return array(
			'removed'                   => true,
			'id'                        => $id,
			'reassign_id'               => $reassign,
			'network_account_preserved' => $preserved,
		);}

	/** @param string $role Role. @return bool */ private function valid_role( $role ) {
		$roles = get_editable_roles();
		return isset( $roles[ $role ] );}
	/** @param object $user User. @return array<string,mixed> */ private function format_user( $user ) {
		return array(
			'id'           => (int) $user->ID,
			'username'     => (string) $user->user_login,
			'email'        => (string) $user->user_email,
			'display_name' => (string) $user->display_name,
			'first_name'   => (string) $user->first_name,
			'last_name'    => (string) $user->last_name,
			'roles'        => array_values( (array) $user->roles ),
		);}
	/** @return void */ private function load_user_admin() {
		if ( defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';}}
	/** @return array<string,mixed> */ private function user_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array( 'type' => 'integer' ),
				'username'     => array( 'type' => 'string' ),
				'email'        => array( 'type' => 'string' ),
				'display_name' => array( 'type' => 'string' ),
				'first_name'   => array( 'type' => 'string' ),
				'last_name'    => array( 'type' => 'string' ),
				'roles'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'id', 'username', 'email', 'display_name', 'first_name', 'last_name', 'roles' ),
			'additionalProperties' => false,
		);}
	/** @return array<string,mixed> */ private function read_schema() {
		$role = array(
			'type'                 => 'object',
			'properties'           => array(
				'role'         => array( 'type' => 'string' ),
				'name'         => array( 'type' => 'string' ),
				'capabilities' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'role', 'name', 'capabilities' ),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'users' => array(
					'type'  => 'array',
					'items' => $this->user_schema(),
				),
				'roles' => array(
					'type'  => 'array',
					'items' => $role,
				),
			),
			'required'             => array( 'users', 'roles' ),
			'additionalProperties' => false,
		);}
	/** @return array<string,mixed> */ private function upsert_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'       => array(
					'type' => 'string',
					'enum' => array( 'create', 'update' ),
				),
				'id'           => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'username'     => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 60,
				),
				'email'        => array(
					'type'      => 'string',
					'format'    => 'email',
					'maxLength' => 254,
				),
				'display_name' => array(
					'type'      => 'string',
					'maxLength' => 250,
				),
				'first_name'   => array(
					'type'      => 'string',
					'maxLength' => 250,
				),
				'last_name'    => array(
					'type'      => 'string',
					'maxLength' => 250,
				),
				'role'         => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);}
	/** @param bool $is_readonly Read-only. @param bool $destructive D. @param bool $idempotent I. @return array<string,mixed> */ private function meta( $is_readonly, $destructive, $idempotent ) {
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
		);}
}
