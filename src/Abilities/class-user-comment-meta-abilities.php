<?php
/**
 * Generic user and comment metadata abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Metadata_Key_Policy;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Native_Builder_Bridge\Support\User_Comment_Meta_Store;
use WP_Error;

/** Provider-neutral bounded user/comment metadata surface. */
final class User_Comment_Meta_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @var Mutation_Log */
	private $log;

	/** @var User_Comment_Meta_Store */
	private $store;

	/**
	 * Creates the provider.
	 *
	 * @param Permissions  $permissions Permission service.
	 * @param Mutation_Log $log         Mutation log.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
		$this->store       = new User_Comment_Meta_Store();
	}

	/** @return array<int,object> */
	public function register() {
		$registered = array();
		foreach ( array( 'user', 'comment' ) as $type ) {
			$registered[] = wp_register_ability(
				'wp-native-builder/' . $type . '-meta-read',
				array(
					'label'               => 'user' === $type ? __( 'Read User Metadata', 'wp-native-builder-bridge' ) : __( 'Read Comment Metadata', 'wp-native-builder-bridge' ),
					'description'         => __( 'Lists bounded metadata state or reads one exact authorized metadata key when Advanced Metadata access is enabled.', 'wp-native-builder-bridge' ),
					'category'            => Registrar::CATEGORY,
					'input_schema'        => $this->read_input_schema( $type ),
					'output_schema'       => $this->read_output_schema(),
					'execute_callback'    => array( $this, 'user' === $type ? 'read_user' : 'read_comment' ),
					'permission_callback' => array( $this, 'user' === $type ? 'can_read_user' : 'can_read_comment' ),
					'meta'                => $this->meta( true, false, true ),
				)
			);
			$registered[] = wp_register_ability(
				'wp-native-builder/' . $type . '-meta-update',
				array(
					'label'               => 'user' === $type ? __( 'Update User Metadata', 'wp-native-builder-bridge' ) : __( 'Update Comment Metadata', 'wp-native-builder-bridge' ),
					'description'         => __( 'Creates or replaces one authorized single-value metadata key with exact stale-state protection.', 'wp-native-builder-bridge' ),
					'category'            => Registrar::CATEGORY,
					'input_schema'        => $this->update_input_schema( $type ),
					'output_schema'       => $this->item_schema(),
					'execute_callback'    => array( $this, 'user' === $type ? 'update_user' : 'update_comment' ),
					'permission_callback' => array( $this, 'user' === $type ? 'can_update_user' : 'can_update_comment' ),
					'meta'                => $this->meta( false, false, false ),
				)
			);
			$registered[] = wp_register_ability(
				'wp-native-builder/' . $type . '-meta-delete',
				array(
					'label'               => 'user' === $type ? __( 'Delete User Metadata', 'wp-native-builder-bridge' ) : __( 'Delete Comment Metadata', 'wp-native-builder-bridge' ),
					'description'         => __( 'Deletes one authorized single-value metadata key with exact stale-state protection and destructive access.', 'wp-native-builder-bridge' ),
					'category'            => Registrar::CATEGORY,
					'input_schema'        => $this->delete_input_schema( $type ),
					'output_schema'       => $this->delete_output_schema(),
					'execute_callback'    => array( $this, 'user' === $type ? 'delete_user' : 'delete_comment' ),
					'permission_callback' => array( $this, 'user' === $type ? 'can_delete_user' : 'can_delete_comment' ),
					'meta'                => $this->meta( false, true, false ),
				)
			);
		}

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @param array<string,mixed> $input Input. @return bool */
	public function can_read_user( $input ) {
		return $this->can_read( 'user', $input );
	}

	/** @param array<string,mixed> $input Input. @return bool */
	public function can_read_comment( $input ) {
		return $this->can_read( 'comment', $input );
	}

	/** @param array<string,mixed> $input Input. @return bool */
	public function can_update_user( $input ) {
		return $this->can_update( 'user', $input );
	}

	/** @param array<string,mixed> $input Input. @return bool */
	public function can_update_comment( $input ) {
		return $this->can_update( 'comment', $input );
	}

	/** @param array<string,mixed> $input Input. @return bool */
	public function can_delete_user( $input ) {
		return $this->can_delete( 'user', $input );
	}

	/** @param array<string,mixed> $input Input. @return bool */
	public function can_delete_comment( $input ) {
		return $this->can_delete( 'comment', $input );
	}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function read_user( $input ) {
		return $this->read( 'user', $input );
	}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function read_comment( $input ) {
		return $this->read( 'comment', $input );
	}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function update_user( $input ) {
		return $this->update( 'user', $input );
	}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function update_comment( $input ) {
		return $this->update( 'comment', $input );
	}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function delete_user( $input ) {
		return $this->delete( 'user', $input );
	}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function delete_comment( $input ) {
		return $this->delete( 'comment', $input );
	}

	/** @return bool */
	private function can_read( $type, $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) || ! is_array( $input ) ) {
			return false;
		}
		$id     = $this->input_id( $type, $input );
		$target = $this->authorized_target( $type, $id );
		if ( ! $target ) {
			return false;
		}
		$key = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( '' === $key ) {
			return true;
		}
		if ( $this->is_sensitive_key( $type, $key ) ) {
			return false;
		}
		$rows = $this->store->rows( $type, $id, $key );
		return ! is_wp_error( $rows ) && $this->can_access_meta_key( $type, $id, $key, empty( $rows ) ? 'add' : 'edit' );
	}

	/** @return bool */
	private function can_update( $type, $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) || ! is_array( $input ) || empty( $input['key'] ) ) {
			return false;
		}
		$id  = $this->input_id( $type, $input );
		$key = (string) $input['key'];
		if ( ! $this->authorized_target( $type, $id ) || $this->is_sensitive_key( $type, $key ) ) {
			return false;
		}
		$rows = $this->store->rows( $type, $id, $key );
		return ! is_wp_error( $rows ) && $this->can_access_meta_key( $type, $id, $key, empty( $rows ) ? 'add' : 'edit' );
	}

	/** @return bool */
	private function can_delete( $type, $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) || ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' ) || ! is_array( $input ) || empty( $input['key'] ) ) {
			return false;
		}
		$id  = $this->input_id( $type, $input );
		$key = (string) $input['key'];
		return (bool) $this->authorized_target( $type, $id )
			&& ! $this->is_sensitive_key( $type, $key )
			&& $this->can_access_meta_key( $type, $id, $key, 'delete' );
	}

	/** @return array<string,mixed>|WP_Error */
	private function read( $type, $input ) {
		$target = $this->validated_target( $type, $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$id             = $this->input_id( $type, $input );
		$key            = isset( $input['key'] ) ? (string) $input['key'] : '';
		$include_values = ! empty( $input['include_values'] );
		if ( $include_values && '' === $key ) {
			return new WP_Error( 'object_meta_key_required_for_values', __( 'Specify one exact metadata key before requesting metadata values.', 'wp-native-builder-bridge' ) );
		}

		if ( '' !== $key ) {
			if ( $this->is_sensitive_key( $type, $key ) ) {
				return $this->sensitive_key_error();
			}
			$rows = $this->store->rows( $type, $id, $key );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			$allowed = $this->validate_key_access( $type, $id, $key, empty( $rows ) ? 'add' : 'edit' );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
			$item = $this->item_from_rows( $key, $rows, $include_values );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			return array(
				'object_type' => $type,
				'object_id'   => $id,
				'items'       => array( $item ),
			);
		}

		$keys = $this->store->rows( $type, $id, null, 200 );
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}
		$seen  = array();
		$items = array();
		foreach ( $keys as $row ) {
			$candidate = (string) $row['key'];
			if ( isset( $seen[ $candidate ] ) || $this->is_sensitive_key( $type, $candidate ) || ! $this->can_access_meta_key( $type, $id, $candidate, 'edit' ) ) {
				continue;
			}
			$seen[ $candidate ] = true;
			$rows               = $this->store->rows( $type, $id, $candidate );
			if ( is_wp_error( $rows ) ) {
				continue;
			}
			$item = $this->item_from_rows( $candidate, $rows, false );
			if ( ! is_wp_error( $item ) ) {
				$items[] = $item;
			}
		}

		return array(
			'object_type' => $type,
			'object_id'   => $id,
			'items'       => $items,
		);
	}

	/** @return array<string,mixed>|WP_Error */
	private function update( $type, $input ) {
		$id      = $this->input_id( $type, $input );
		$ability = 'wp-native-builder/' . $type . '-meta-update';
		$target  = $this->validated_target( $type, $input );
		if ( is_wp_error( $target ) ) {
			return $this->logged_error( $target, $type, $id, $ability );
		}
		$key = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( '' === $key ) {
			return $this->logged_error( new WP_Error( 'object_meta_key_required', __( 'A metadata key is required.', 'wp-native-builder-bridge' ) ), $type, $id, $ability );
		}
		if ( $this->is_sensitive_key( $type, $key ) ) {
			return $this->logged_error( $this->sensitive_key_error(), $type, $id, $ability );
		}

		$rows = $this->store->rows( $type, $id, $key );
		if ( is_wp_error( $rows ) ) {
			return $this->logged_error( $rows, $type, $id, $ability );
		}
		$allowed = $this->validate_key_access( $type, $id, $key, empty( $rows ) ? 'add' : 'edit' );
		if ( is_wp_error( $allowed ) ) {
			return $this->logged_error( $allowed, $type, $id, $ability );
		}
		if ( count( $rows ) > 1 ) {
			return $this->logged_error( new WP_Error( 'object_meta_multiple_values_unsupported', __( 'This metadata key has multiple rows. The generic updater refuses an ambiguous value set.', 'wp-native-builder-bridge' ) ), $type, $id, $ability );
		}
		if ( $rows ) {
			$value_error = $this->supported_value( $rows[0]['value'] );
			if ( is_wp_error( $value_error ) ) {
				return $this->logged_error( $value_error, $type, $id, $ability );
			}
		}

		$current  = $this->state_hash( $rows );
		$expected = isset( $input['expected_state_hash'] ) ? (string) $input['expected_state_hash'] : '';
		if ( '' === $expected || ! hash_equals( $current, $expected ) ) {
			return $this->logged_error( $this->stale_error(), $type, $id, $ability );
		}
		$value = $this->decode_value( isset( $input['value_json'] ) ? (string) $input['value_json'] : '' );
		if ( is_wp_error( $value ) ) {
			return $this->logged_error( $value, $type, $id, $ability );
		}
		if ( ! $this->is_losslessly_json_compatible( $value ) ) {
			$error = new WP_Error( 'object_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) );
			return $this->logged_error( $error, $type, $id, $ability );
		}
		if ( empty( $rows ) ) {
			return $this->create_value( $type, $id, $key, $value, $current, $ability );
		}

		$prepared = $this->store->prepare_value( $type, $id, $key, $value );
		if ( is_wp_error( $prepared ) ) {
			return $this->logged_error( $prepared, $type, $id, $ability );
		}
		$prepared_error = $this->supported_value( $prepared['value'] );
		if ( is_wp_error( $prepared_error ) ) {
			return $this->logged_error( $prepared_error, $type, $id, $ability );
		}
		if ( $rows[0]['raw_value'] === $prepared['raw_value'] ) {
			return $this->item_from_rows( $key, $rows, true );
		}

		$verified = $this->store->replace_row( $type, $id, $key, $rows[0], $prepared );
		if ( is_wp_error( $verified ) ) {
			return $this->logged_error( $verified, $type, $id, $ability );
		}
		$this->log->record( $ability, $type . '_meta', $id, true, '' );
		return $this->item_from_rows( $key, array( $verified ), true );
	}

	/** @return array<string,mixed>|WP_Error */
	private function delete( $type, $input ) {
		$id      = $this->input_id( $type, $input );
		$ability = 'wp-native-builder/' . $type . '-meta-delete';
		if ( ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' ) ) {
			$error = new WP_Error( 'destructive_access_disabled', __( 'Users & Destructive access is required before deleting metadata.', 'wp-native-builder-bridge' ) );
			return $this->logged_error( $error, $type, $id, $ability );
		}
		$target = $this->validated_target( $type, $input );
		if ( is_wp_error( $target ) ) {
			return $this->logged_error( $target, $type, $id, $ability );
		}
		$key = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( '' === $key || $this->is_sensitive_key( $type, $key ) ) {
			$error = '' === $key
				? new WP_Error( 'object_meta_key_required', __( 'A metadata key is required.', 'wp-native-builder-bridge' ) )
				: $this->sensitive_key_error();
			return $this->logged_error( $error, $type, $id, $ability );
		}

		$allowed = $this->validate_key_access( $type, $id, $key, 'delete' );
		if ( is_wp_error( $allowed ) ) {
			return $this->logged_error( $allowed, $type, $id, $ability );
		}
		$rows = $this->store->rows( $type, $id, $key );
		if ( is_wp_error( $rows ) ) {
			return $this->logged_error( $rows, $type, $id, $ability );
		}
		if ( count( $rows ) > 1 ) {
			return $this->logged_error( new WP_Error( 'object_meta_multiple_values_unsupported', __( 'This metadata key has multiple rows. The generic deleter refuses an ambiguous value set.', 'wp-native-builder-bridge' ) ), $type, $id, $ability );
		}

		$current  = $this->state_hash( $rows );
		$expected = isset( $input['expected_state_hash'] ) ? (string) $input['expected_state_hash'] : '';
		if ( '' === $expected || ! hash_equals( $current, $expected ) ) {
			return $this->logged_error( $this->stale_error(), $type, $id, $ability );
		}
		if ( empty( $rows ) ) {
			return array(
				'object_type' => $type,
				'object_id'   => $id,
				'key'         => $key,
				'deleted'     => false,
				'state_hash'  => $current,
			);
		}

		$value_error = $this->supported_value( $rows[0]['value'] );
		if ( is_wp_error( $value_error ) ) {
			return $this->logged_error( $value_error, $type, $id, $ability );
		}
		$result = $this->store->delete_row( $type, $id, $key, $rows[0] );
		if ( is_wp_error( $result ) ) {
			return $this->logged_error( $result, $type, $id, $ability );
		}
		$this->log->record( $ability, $type . '_meta', $id, true, '' );
		return array(
			'object_type' => $type,
			'object_id'   => $id,
			'key'         => $key,
			'deleted'     => true,
			'state_hash'  => $this->state_hash( array() ),
		);
	}

	/** @return array<string,mixed>|WP_Error */
	private function create_value( $type, $id, $key, $value, $current_hash, $ability ) {
		$creation = $this->store->create_unique_row( $type, $id, $key, $value );
		if ( is_wp_error( $creation ) ) {
			return $this->logged_error( $creation, $type, $id, $ability );
		}
		$result       = $creation['result'];
		$expected_row = $creation['expected_row'];
		$after        = $this->store->rows( $type, $id, $key );
		if ( is_wp_error( $after ) ) {
			return $this->logged_error( $after, $type, $id, $ability );
		}

		if ( ! is_int( $result ) || $result < 1 ) {
			$error = $this->state_hash( $after ) === $current_hash
				? new WP_Error( 'object_meta_update_failed', __( 'WordPress could not update the requested metadata key.', 'wp-native-builder-bridge' ) )
				: $this->stale_error();
			return $this->logged_error( $error, $type, $id, $ability );
		}

		$created = null;
		foreach ( $after as $row ) {
			if ( (int) $row['meta_id'] === $result ) {
				$created = $row;
				break;
			}
		}
		if ( null === $created || ! $this->store->row_matches( $created, $expected_row ) || 1 !== count( $after ) ) {
			if ( null !== $created ) {
				$cleanup = $this->store->cleanup_created_row( $type, $created );
				if ( is_wp_error( $cleanup ) ) {
					return $this->logged_error( $cleanup, $type, $id, $ability );
				}
			}
			return $this->logged_error( $this->stale_error(), $type, $id, $ability );
		}

		$value_error = $this->supported_value( $created['value'] );
		if ( is_wp_error( $value_error ) ) {
			$cleanup = $this->store->cleanup_created_row( $type, $created );
			if ( is_wp_error( $cleanup ) ) {
				return $this->logged_error( $cleanup, $type, $id, $ability );
			}
			return $this->logged_error( $value_error, $type, $id, $ability );
		}
		$this->log->record( $ability, $type . '_meta', $id, true, '' );
		return $this->item_from_rows( $key, $after, true );
	}

	/** @return object|null */
	private function authorized_target( $type, $id ) {
		$id = (int) $id;
		if ( $id < 1 ) {
			return null;
		}
		if ( 'user' === $type ) {
			$target = get_userdata( $id );
			return $target && current_user_can( 'edit_user', $id ) ? $target : null;
		}
		$target = get_comment( $id );
		return $target && current_user_can( 'edit_comment', $id ) ? $target : null;
	}

	/** @return object|WP_Error */
	private function validated_target( $type, $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) ) {
			return new WP_Error( 'advanced_metadata_access_disabled', __( 'Advanced Metadata access is disabled in WP AI Bridge settings.', 'wp-native-builder-bridge' ) );
		}
		$id     = $this->input_id( $type, $input );
		$target = $this->authorized_target( $type, $id );
		return $target
			? $target
			: new WP_Error( 'object_meta_target_not_allowed', __( 'The requested metadata target does not exist or cannot be edited by the current WordPress user.', 'wp-native-builder-bridge' ) );
	}

	/** @return true|WP_Error */
	private function validate_key_access( $type, $id, $key, $operation ) {
		return $this->can_access_meta_key( $type, $id, $key, $operation )
			? true
			: new WP_Error( 'object_meta_permission_denied', __( 'The current WordPress user is not allowed to perform this metadata operation.', 'wp-native-builder-bridge' ) );
	}

	/** @return bool */
	private function can_access_meta_key( $type, $id, $key, $operation ) {
		$object_capability = 'user' === $type ? 'edit_user' : 'edit_comment';
		if ( ! current_user_can( $object_capability, (int) $id ) ) {
			return false;
		}

		$capability = $operation . '_' . $type . '_meta';
		$protected  = function_exists( 'is_protected_meta' ) && is_protected_meta( $key, $type );
		if ( ! $protected || $this->has_explicit_meta_auth_contract( $type, $id, $key ) ) {
			return current_user_can( $capability, (int) $id, (string) $key );
		}
		if ( ! function_exists( 'map_meta_cap' ) || ! function_exists( 'get_current_user_id' ) ) {
			return true;
		}

		$mapped = map_meta_cap( $capability, get_current_user_id(), (int) $id, (string) $key );
		foreach ( array_unique( (array) $mapped ) as $required ) {
			if ( $required === $capability ) {
				continue;
			}
			if ( 'do_not_allow' === $required || ! current_user_can( $required ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return bool */
	private function has_explicit_meta_auth_contract( $type, $id, $key ) {
		$subtype = function_exists( 'get_object_subtype' ) ? get_object_subtype( $type, (int) $id ) : '';
		if ( function_exists( 'get_registered_meta_keys' ) ) {
			$global = get_registered_meta_keys( $type );
			$local  = '' !== $subtype ? get_registered_meta_keys( $type, $subtype ) : array();
			if ( ( is_array( $global ) && isset( $global[ $key ] ) ) || ( is_array( $local ) && isset( $local[ $key ] ) ) ) {
				return true;
			}
		}
		if ( ! function_exists( 'has_filter' ) ) {
			return false;
		}
		return (bool) has_filter( 'auth_' . $type . '_meta_' . $key )
			|| ( '' !== $subtype && (bool) has_filter( 'auth_' . $type . '_meta_' . $key . '_for_' . $subtype ) )
			|| ( '' !== $subtype && (bool) has_filter( 'auth_' . $type . '_' . $subtype . '_meta_' . $key ) );
	}

	/** @return bool */
	private function is_sensitive_key( $type, $key ) {
		if ( Metadata_Key_Policy::is_sensitive( $key ) ) {
			return true;
		}
		if ( 'user' !== $type ) {
			return false;
		}
		$bounded    = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '_', (string) $key );
		$normalized = strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $bounded ), '_' ) );
		return 1 === preg_match( '/(^|_)(capabilities|user_level|session_tokens|application_passwords?)($|_)/', $normalized );
	}

	/** @return array<string,mixed>|WP_Error */
	private function item_from_rows( $key, array $rows, $include_values ) {
		$types  = array();
		$values = array();
		foreach ( $rows as $row ) {
			$value   = $row['value'];
			$types[] = $this->value_type( $value );
			if ( $include_values ) {
				$json = $this->encode_lossless_value( $value );
				if ( is_wp_error( $json ) ) {
					return $json;
				}
				$values[] = array(
					'type'       => $this->value_type( $value ),
					'value_json' => $json,
				);
			}
		}
		return array(
			'key'         => $key,
			'count'       => count( $rows ),
			'state_hash'  => $this->state_hash( $rows ),
			'value_types' => array_values( array_unique( $types ) ),
			'values'      => $values,
		);
	}

	/** @return string */
	private function state_hash( array $rows ) {
		if ( empty( $rows ) ) {
			return hash( 'sha256', (string) maybe_serialize( array() ) );
		}
		$identity = array();
		foreach ( $rows as $row ) {
			$identity[] = array(
				(int) $row['meta_id'],
				null === $row['raw_value'] ? 'null' : 'string',
				$row['raw_value'],
			);
		}
		return hash( 'sha256', (string) maybe_serialize( $identity ) );
	}

	/** @return true|WP_Error */
	private function supported_value( $value ) {
		if ( $this->contains_object_or_resource( $value ) ) {
			return new WP_Error( 'object_meta_object_value_unsupported', __( 'This metadata value contains a PHP object or resource and cannot be losslessly mutated through the generic JSON metadata contract.', 'wp-native-builder-bridge' ) );
		}
		return $this->is_losslessly_json_compatible( $value )
			? true
			: new WP_Error( 'object_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) );
	}

	/** @return WP_Error */
	private function sensitive_key_error() {
		return new WP_Error( 'sensitive_object_meta_key', __( 'Authentication, authorization, session, or credential-like metadata keys are outside the generic Bridge metadata surface.', 'wp-native-builder-bridge' ) );
	}

	/** @return WP_Error */
	private function stale_error() {
		return new WP_Error( 'stale_object_meta_conflict', __( 'Metadata changed after it was read. Refresh the metadata state before mutating it.', 'wp-native-builder-bridge' ) );
	}

	/** @return string|WP_Error */
	private function encode_lossless_value( $value ) {
		if ( ! $this->is_losslessly_json_compatible( $value ) ) {
			return new WP_Error( 'object_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) );
		}
		try {
			return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			return new WP_Error( 'object_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) );
		}
	}

	/** @return mixed|WP_Error */
	private function decode_value( $json ) {
		try {
			return json_decode( (string) $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			return new WP_Error( 'invalid_object_meta_value_json', __( 'value_json must contain one valid JSON value.', 'wp-native-builder-bridge' ) );
		}
	}

	/** @return bool */
	private function contains_object_or_resource( $value, $depth = 0 ) {
		if ( $depth > 64 || is_object( $value ) || is_resource( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $nested ) {
				if ( $this->contains_object_or_resource( $nested, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @return bool */
	private function is_losslessly_json_compatible( $value, $depth = 0 ) {
		if ( $depth > 64 || is_object( $value ) || is_resource( $value ) ) {
			return false;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $nested ) {
				if ( ! $this->is_losslessly_json_compatible( $nested, $depth + 1 ) ) {
					return false;
				}
			}
		}
		try {
			$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
			return json_decode( $json, true, 512, JSON_THROW_ON_ERROR ) === $value;
		} catch ( \JsonException $exception ) {
			return false;
		}
	}

	/** @return string */
	private function value_type( $value ) {
		if ( is_null( $value ) ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		if ( is_array( $value ) ) {
			return 'array';
		}
		if ( is_object( $value ) ) {
			return 'object';
		}
		return 'string';
	}

	/** @return int */
	private function input_id( $type, $input ) {
		$field = 'user' === $type ? 'user_id' : 'comment_id';
		return is_array( $input ) && isset( $input[ $field ] ) ? (int) $input[ $field ] : 0;
	}

	/** @return WP_Error */
	private function logged_error( WP_Error $error, $type, $id, $ability ) {
		$this->log->record( $ability, $type . '_meta', (int) $id, false, $error->get_error_code() );
		return $error;
	}

	/** @return array<string,mixed> */
	private function read_input_schema( $type ) {
		$field = 'user' === $type ? 'user_id' : 'comment_id';
		return array(
			'type'                 => 'object',
			'properties'           => array(
				$field           => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'key'            => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
				'include_values' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'             => array( $field ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function update_input_schema( $type ) {
		$schema = $this->read_input_schema( $type );
		unset( $schema['properties']['include_values'] );
		$schema['properties']['expected_state_hash'] = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		$schema['properties']['value_json']          = array(
			'type'      => 'string',
			'maxLength' => 1048576,
		);

		$schema['required'][] = 'key';
		$schema['required'][] = 'expected_state_hash';
		$schema['required'][] = 'value_json';
		return $schema;
	}

	/** @return array<string,mixed> */
	private function delete_input_schema( $type ) {
		$schema = $this->read_input_schema( $type );
		unset( $schema['properties']['include_values'] );
		$schema['properties']['expected_state_hash'] = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);

		$schema['required'][] = 'key';
		$schema['required'][] = 'expected_state_hash';
		return $schema;
	}

	/** @return array<string,mixed> */
	private function item_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key'         => array( 'type' => 'string' ),
				'count'       => array( 'type' => 'integer' ),
				'state_hash'  => array( 'type' => 'string' ),
				'value_types' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'values'      => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'type'       => array( 'type' => 'string' ),
							'value_json' => array( 'type' => 'string' ),
						),
						'required'             => array( 'type', 'value_json' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'key', 'count', 'state_hash', 'value_types', 'values' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function read_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'object_type' => array(
					'type' => 'string',
					'enum' => array( 'user', 'comment' ),
				),
				'object_id'   => array( 'type' => 'integer' ),
				'items'       => array(
					'type'  => 'array',
					'items' => $this->item_schema(),
				),
			),
			'required'             => array( 'object_type', 'object_id', 'items' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function delete_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'object_type' => array( 'type' => 'string' ),
				'object_id'   => array( 'type' => 'integer' ),
				'key'         => array( 'type' => 'string' ),
				'deleted'     => array( 'type' => 'boolean' ),
				'state_hash'  => array( 'type' => 'string' ),
			),
			'required'             => array( 'object_type', 'object_id', 'key', 'deleted', 'state_hash' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function meta( $read_only, $destructive, $idempotent ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => (bool) $read_only,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent,
			),
		);
	}
}
