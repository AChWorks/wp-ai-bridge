<?php
/**
 * Generic post metadata abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Metadata_Key_Policy;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Post_Meta_Store;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Exposes provider-neutral post metadata behind explicit advanced access.
 */
final class Post_Meta_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @var Mutation_Log */
	private $log;

	/** @var Post_Meta_Store */
	private $store;

	/**
	 * Creates the metadata Ability provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Mutation logger.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
		$this->store       = new Post_Meta_Store();
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/post-meta-read',
			array(
				'label'               => __( 'Read Post Metadata', 'wp-ai-bridge' ),
				'description'         => __( 'Lists metadata keys or reads one exact metadata key for a WordPress post object when Advanced Metadata access is enabled.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/post-meta-update',
			array(
				'label'               => __( 'Update Post Metadata', 'wp-ai-bridge' ),
				'description'         => __( 'Creates or replaces one single-value metadata key after checking Advanced Metadata access, WordPress authority, and the expected metadata state.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->update_input_schema(),
				'output_schema'       => $this->item_schema(),
				'execute_callback'    => array( $this, 'update' ),
				'permission_callback' => array( $this, 'can_update' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/post-meta-delete',
			array(
				'label'               => __( 'Delete Post Metadata', 'wp-ai-bridge' ),
				'description'         => __( 'Deletes one single-value metadata key after checking Advanced Metadata, destructive access, WordPress authority, and the expected metadata state.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->delete_input_schema(),
				'output_schema'       => $this->delete_output_schema(),
				'execute_callback'    => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/**
	 * Checks post metadata read permission.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public function can_read( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) || ! is_array( $input ) ) {
			return false;
		}

		$post = $this->authorized_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( ! $post ) {
			return false;
		}

		if ( empty( $input['key'] ) ) {
			return true;
		}

		$key = (string) $input['key'];
		if ( $this->is_sensitive_key( $key ) ) {
			return false;
		}

		$rows = $this->store->rows( $post->ID, $key );
		if ( is_wp_error( $rows ) ) {
			return false;
		}

		return $this->can_access_meta_key( $post, $key, empty( $rows ) ? 'add' : 'edit' );
	}

	/**
	 * Checks post metadata update permission.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public function can_update( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) || ! is_array( $input ) || empty( $input['key'] ) ) {
			return false;
		}

		$post = $this->authorized_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		$key  = (string) $input['key'];
		if ( ! $post || $this->is_sensitive_key( $key ) ) {
			return false;
		}

		$rows = $this->store->rows( $post->ID, $key );
		if ( is_wp_error( $rows ) ) {
			return false;
		}

		return $this->can_access_meta_key( $post, $key, empty( $rows ) ? 'add' : 'edit' );
	}

	/**
	 * Checks destructive post metadata permission.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public function can_delete( $input ) {
		if (
			! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' )
			|| ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' )
			|| ! is_array( $input )
			|| empty( $input['key'] )
		) {
			return false;
		}

		$post = $this->authorized_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		$key  = (string) $input['key'];
		return $post && ! $this->is_sensitive_key( $key ) && $this->can_access_meta_key( $post, $key, 'delete' );
	}

	/**
	 * Lists keys or reads one exact physical metadata key.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read( $input ) {
		$post = $this->validated_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$key            = isset( $input['key'] ) ? (string) $input['key'] : '';
		$include_values = ! empty( $input['include_values'] );
		if ( $include_values && '' === $key ) {
			return new WP_Error( 'post_meta_key_required_for_values', __( 'Specify one exact metadata key before requesting metadata values.', 'wp-ai-bridge' ) );
		}

		if ( '' !== $key ) {
			if ( $this->is_sensitive_key( $key ) ) {
				return $this->sensitive_key_error();
			}
			$rows = $this->store->rows( $post->ID, $key );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			$key_error = $this->validate_key_access( $post, $key, empty( $rows ) ? 'add' : 'edit' );
			if ( is_wp_error( $key_error ) ) {
				return $key_error;
			}
			$item = $this->item_from_rows( $key, $rows, $include_values );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			return array(
				'post_id'   => (int) $post->ID,
				'post_type' => (string) $post->post_type,
				'items'     => array( $item ),
			);
		}

		$rows = $this->store->rows( $post->ID );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$grouped = array();
		foreach ( $rows as $row ) {
			$candidate = (string) $row['key'];
			if ( ! isset( $grouped[ $candidate ] ) ) {
				$grouped[ $candidate ] = array();
			}
			$grouped[ $candidate ][] = $row;
		}
		ksort( $grouped, SORT_STRING );

		$items = array();
		foreach ( $grouped as $candidate => $candidate_rows ) {
			if ( $this->is_sensitive_key( $candidate ) || ! $this->can_access_meta_key( $post, $candidate, 'edit' ) ) {
				continue;
			}
			$item = $this->item_from_rows( $candidate, $candidate_rows, false );
			if ( ! is_wp_error( $item ) ) {
				$items[] = $item;
			}
		}

		return array(
			'post_id'   => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'items'     => $items,
		);
	}

	/**
	 * Creates or replaces one single-value metadata key.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update( $input ) {
		$post = $this->validated_post( $input );
		if ( is_wp_error( $post ) ) {
			return $this->logged_error( $post, $this->input_post_id( $input ), 'wp-ai-bridge/post-meta-update' );
		}

		$key = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( '' === $key ) {
			return $this->logged_error( new WP_Error( 'post_meta_key_required', __( 'A metadata key is required.', 'wp-ai-bridge' ) ), $post->ID, 'wp-ai-bridge/post-meta-update' );
		}
		if ( $this->is_sensitive_key( $key ) ) {
			return $this->logged_error( $this->sensitive_key_error(), $post->ID, 'wp-ai-bridge/post-meta-update' );
		}

		$rows = $this->store->rows( $post->ID, $key );
		if ( is_wp_error( $rows ) ) {
			return $this->logged_error( $rows, $post->ID, 'wp-ai-bridge/post-meta-update' );
		}
		$key_error = $this->validate_key_access( $post, $key, empty( $rows ) ? 'add' : 'edit' );
		if ( is_wp_error( $key_error ) ) {
			return $this->logged_error( $key_error, $post->ID, 'wp-ai-bridge/post-meta-update' );
		}
		if ( count( $rows ) > 1 ) {
			return $this->logged_error( new WP_Error( 'post_meta_multiple_values_unsupported', __( 'This metadata key has multiple rows. The generic updater refuses to guess which row should be replaced.', 'wp-ai-bridge' ) ), $post->ID, 'wp-ai-bridge/post-meta-update' );
		}
		if ( $rows ) {
			$value_error = $this->unsupported_stored_value_error( $rows[0]['value'] );
			if ( is_wp_error( $value_error ) ) {
				return $this->logged_error( $value_error, $post->ID, 'wp-ai-bridge/post-meta-update' );
			}
		}

		$current_hash = $this->state_hash( $rows );
		$expected     = isset( $input['expected_state_hash'] ) ? (string) $input['expected_state_hash'] : '';
		if ( '' === $expected || ! hash_equals( $current_hash, $expected ) ) {
			return $this->logged_error( $this->stale_error( 'update' ), $post->ID, 'wp-ai-bridge/post-meta-update' );
		}

		$value = $this->decode_value( isset( $input['value_json'] ) ? (string) $input['value_json'] : '' );
		if ( is_wp_error( $value ) ) {
			return $this->logged_error( $value, $post->ID, 'wp-ai-bridge/post-meta-update' );
		}
		if ( ! $this->is_losslessly_json_compatible( $value ) ) {
			return $this->logged_error( new WP_Error( 'post_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-ai-bridge' ) ), $post->ID, 'wp-ai-bridge/post-meta-update' );
		}

		if ( empty( $rows ) ) {
			return $this->create_value( $post, $key, $value, $current_hash );
		}

		$prepared_value = $this->store->prepare_value( $post->ID, $key, $value );
		$prepared_error = $this->unsupported_stored_value_error( $prepared_value['value'] );
		if ( is_wp_error( $prepared_error ) ) {
			return $this->logged_error( $prepared_error, $post->ID, 'wp-ai-bridge/post-meta-update' );
		}

		if ( $rows[0]['raw_value'] === $prepared_value['raw_value'] ) {
			return $this->item_from_rows( $key, $rows, true );
		}

		$verified_row = $this->store->replace_row( $post->ID, $key, $rows[0], $prepared_value );
		if ( is_wp_error( $verified_row ) ) {
			return $this->logged_error( $verified_row, $post->ID, 'wp-ai-bridge/post-meta-update' );
		}

		$this->log->record( 'wp-ai-bridge/post-meta-update', 'post_meta', (int) $post->ID, true, '' );
		return $this->item_from_rows( $key, array( $verified_row ), true );
	}

	/**
	 * Deletes one single-value metadata key.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete( $input ) {
		$post_id = $this->input_post_id( $input );
		if ( ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' ) ) {
			return $this->logged_error( new WP_Error( 'destructive_access_disabled', __( 'Users & Destructive access is required before deleting post metadata.', 'wp-ai-bridge' ) ), $post_id, 'wp-ai-bridge/post-meta-delete' );
		}

		$post = $this->validated_post( $input );
		if ( is_wp_error( $post ) ) {
			return $this->logged_error( $post, $post_id, 'wp-ai-bridge/post-meta-delete' );
		}
		$key = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( '' === $key ) {
			return $this->logged_error( new WP_Error( 'post_meta_key_required', __( 'A metadata key is required.', 'wp-ai-bridge' ) ), $post->ID, 'wp-ai-bridge/post-meta-delete' );
		}
		if ( $this->is_sensitive_key( $key ) ) {
			return $this->logged_error( $this->sensitive_key_error(), $post->ID, 'wp-ai-bridge/post-meta-delete' );
		}

		$key_error = $this->validate_key_access( $post, $key, 'delete' );
		if ( is_wp_error( $key_error ) ) {
			return $this->logged_error( $key_error, $post->ID, 'wp-ai-bridge/post-meta-delete' );
		}

		$rows = $this->store->rows( $post->ID, $key );
		if ( is_wp_error( $rows ) ) {
			return $this->logged_error( $rows, $post->ID, 'wp-ai-bridge/post-meta-delete' );
		}
		if ( count( $rows ) > 1 ) {
			return $this->logged_error( new WP_Error( 'post_meta_multiple_values_unsupported', __( 'This metadata key has multiple rows. The generic deleter refuses to remove an ambiguous multi-row value set.', 'wp-ai-bridge' ) ), $post->ID, 'wp-ai-bridge/post-meta-delete' );
		}

		$current_hash = $this->state_hash( $rows );
		$expected     = isset( $input['expected_state_hash'] ) ? (string) $input['expected_state_hash'] : '';
		if ( '' === $expected || ! hash_equals( $current_hash, $expected ) ) {
			return $this->logged_error( $this->stale_error( 'delete' ), $post->ID, 'wp-ai-bridge/post-meta-delete' );
		}
		if ( empty( $rows ) ) {
			return array(
				'post_id'    => (int) $post->ID,
				'key'        => $key,
				'deleted'    => false,
				'state_hash' => $current_hash,
			);
		}

		$value_error = $this->unsupported_stored_value_error( $rows[0]['value'] );
		if ( is_wp_error( $value_error ) ) {
			return $this->logged_error( $value_error, $post->ID, 'wp-ai-bridge/post-meta-delete' );
		}

		$result = $this->store->delete_row( $post->ID, $key, $rows[0] );
		if ( is_wp_error( $result ) ) {
			return $this->logged_error( $result, $post->ID, 'wp-ai-bridge/post-meta-delete' );
		}

		$this->log->record( 'wp-ai-bridge/post-meta-delete', 'post_meta', (int) $post->ID, true, '' );
		return array(
			'post_id'    => (int) $post->ID,
			'key'        => $key,
			'deleted'    => true,
			'state_hash' => $this->state_hash( array() ),
		);
	}

	/**
	 * Creates one row, then removes only this invocation's unchanged row if Core's unique check races.
	 *
	 * @param object $post         Canonical post object.
	 * @param string $key          Exact unslashed key.
	 * @param mixed  $value        JSON-decoded value.
	 * @param string $current_hash Empty-state hash.
	 * @return array<string,mixed>|WP_Error
	 */
	private function create_value( $post, $key, $value, $current_hash ) {
		$creation = $this->store->create_unique_row( $post->ID, $key, $value );
		if ( is_wp_error( $creation ) ) {
			return $this->logged_error( $creation, $post->ID, 'wp-ai-bridge/post-meta-update' );
		}

		$result       = $creation['result'];
		$expected_row = $creation['expected_row'];
		$after        = $this->store->rows( $post->ID, $key );
		if ( is_wp_error( $after ) ) {
			return $this->logged_error( $after, $post->ID, 'wp-ai-bridge/post-meta-update' );
		}

		if ( ! is_int( $result ) || $result < 1 ) {
			if ( $this->state_hash( $after ) !== $current_hash ) {
				return $this->logged_error( $this->stale_error( 'update' ), $post->ID, 'wp-ai-bridge/post-meta-update' );
			}
			return $this->logged_error( new WP_Error( 'post_meta_update_failed', __( 'WordPress could not update the requested metadata key.', 'wp-ai-bridge' ) ), $post->ID, 'wp-ai-bridge/post-meta-update' );
		}

		$created_row = null;
		foreach ( $after as $candidate ) {
			if ( (int) $candidate['meta_id'] === $result ) {
				$created_row = $candidate;
				break;
			}
		}

		if ( null === $created_row || ! $this->store->row_matches( $created_row, $expected_row ) ) {
			return $this->logged_error( $this->stale_error( 'update' ), $post->ID, 'wp-ai-bridge/post-meta-update' );
		}

		if ( 1 !== count( $after ) ) {
			$cleanup = $this->store->cleanup_created_row( $expected_row );
			if ( is_wp_error( $cleanup ) ) {
				return $this->logged_error( $cleanup, $post->ID, 'wp-ai-bridge/post-meta-update' );
			}
			return $this->logged_error( $this->stale_error( 'update' ), $post->ID, 'wp-ai-bridge/post-meta-update' );
		}

		$created_value_error = $this->unsupported_stored_value_error( $created_row['value'] );
		if ( is_wp_error( $created_value_error ) ) {
			$cleanup = $this->store->cleanup_created_row( $expected_row );
			if ( is_wp_error( $cleanup ) ) {
				return $this->logged_error( $cleanup, $post->ID, 'wp-ai-bridge/post-meta-update' );
			}
			return $this->logged_error( $created_value_error, $post->ID, 'wp-ai-bridge/post-meta-update' );
		}

		$this->log->record( 'wp-ai-bridge/post-meta-update', 'post_meta', (int) $post->ID, true, '' );
		return $this->item_from_rows( $key, $after, true );
	}

	/** @return object|null */
	private function authorized_post( $post_id ) {
		if ( $post_id < 1 ) {
			return null;
		}
		if ( function_exists( 'wp_is_post_revision' ) ) {
			$parent_id = wp_is_post_revision( $post_id );
			if ( $parent_id ) {
				$post_id = (int) $parent_id;
			}
		}
		$post = get_post( $post_id );
		if ( ! $post || in_array( $post->post_type, array( 'wpai_doc', 'wpai_task' ), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return null;
		}
		return $post;
	}

	/**
	 * @param array<string,mixed> $input Ability input.
	 * @return object|WP_Error
	 */
	private function validated_post( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) ) {
			return new WP_Error( 'advanced_metadata_access_disabled', __( 'Advanced Metadata access is disabled in WP AI Bridge settings.', 'wp-ai-bridge' ) );
		}
		$post = $this->authorized_post( $this->input_post_id( $input ) );
		if ( ! $post ) {
			return new WP_Error( 'post_meta_target_not_allowed', __( 'The requested post does not exist, belongs to Bridge-private Workspace storage, or cannot be edited by the current WordPress user.', 'wp-ai-bridge' ) );
		}
		return $post;
	}

	/**
	 * @param object $post      Post object.
	 * @param string $key       Exact key.
	 * @param string $operation add, edit, or delete.
	 * @return true|WP_Error
	 */
	private function validate_key_access( $post, $key, $operation ) {
		if ( $this->is_sensitive_key( $key ) ) {
			return $this->sensitive_key_error();
		}
		if ( ! $this->can_access_meta_key( $post, $key, $operation ) ) {
			return new WP_Error( 'post_meta_permission_denied', __( 'The current WordPress user is not allowed to perform this metadata operation.', 'wp-ai-bridge' ) );
		}
		return true;
	}

	/**
	 * Preserves explicit provider/Core authorization while overriding only Core's
	 * generic protected-unregistered-meta denial sentinel after admin opt-in.
	 *
	 * @param object $post      Post object.
	 * @param string $key       Exact key.
	 * @param string $operation add, edit, or delete.
	 * @return bool
	 */
	private function can_access_meta_key( $post, $key, $operation ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		$capability = 'edit_post_meta';
		if ( 'add' === $operation ) {
			$capability = 'add_post_meta';
		} elseif ( 'delete' === $operation ) {
			$capability = 'delete_post_meta';
		}

		$protected = function_exists( 'is_protected_meta' ) && is_protected_meta( $key, 'post' );
		if ( ! $protected || $this->has_explicit_meta_auth_contract( $post->post_type, $key ) ) {
			return current_user_can( $capability, $post->ID, $key );
		}

		if ( ! function_exists( 'map_meta_cap' ) || ! function_exists( 'get_current_user_id' ) ) {
			return true;
		}

		$mapped_caps = map_meta_cap( $capability, get_current_user_id(), $post->ID, $key );
		foreach ( array_unique( (array) $mapped_caps ) as $required_cap ) {
			if ( $capability === $required_cap ) {
				continue;
			}
			if ( 'do_not_allow' === $required_cap || ! current_user_can( $required_cap ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $post_type Post subtype.
	 * @param string $key       Metadata key.
	 * @return bool
	 */
	private function has_explicit_meta_auth_contract( $post_type, $key ) {
		if ( function_exists( 'get_registered_meta_keys' ) ) {
			$global  = get_registered_meta_keys( 'post' );
			$subtype = get_registered_meta_keys( 'post', $post_type );
			if ( ( is_array( $global ) && isset( $global[ $key ] ) ) || ( is_array( $subtype ) && isset( $subtype[ $key ] ) ) ) {
				return true;
			}
		}
		if ( ! function_exists( 'has_filter' ) ) {
			return false;
		}
		return (bool) has_filter( 'auth_post_meta_' . $key . '_for_' . $post_type )
			|| (bool) has_filter( 'auth_post_meta_' . $key )
			|| (bool) has_filter( 'auth_post_' . $post_type . '_meta_' . $key );
	}

	/**
	 * Denies provider-neutral credential/session/security token names without blocking
	 * unrelated metadata merely because it contains the generic word "token".
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	private function is_sensitive_key( $key ) {
		return Metadata_Key_Policy::is_sensitive( $key );
	}

	/**
	 * @param string                         $key            Key.
	 * @param array<int,array<string,mixed>> $rows           Physical rows.
	 * @param bool                           $include_values Include values.
	 * @return array<string,mixed>|WP_Error
	 */
	private function item_from_rows( $key, array $rows, $include_values ) {
		$value_types = array();
		$encoded     = array();
		foreach ( $rows as $row ) {
			$value         = $row['value'];
			$value_types[] = $this->value_type( $value );
			if ( $include_values ) {
				$json = $this->encode_lossless_value( $value );
				if ( is_wp_error( $json ) ) {
					return $json;
				}
				$encoded[] = array(
					'type'       => $this->value_type( $value ),
					'value_json' => $json,
				);
			}
		}

		return array(
			'key'         => $key,
			'count'       => count( $rows ),
			'state_hash'  => $this->state_hash( $rows ),
			'value_types' => array_values( array_unique( $value_types ) ),
			'values'      => $encoded,
		);
	}

	/**
	 * Returns stable physical-row identity. Empty identity deliberately preserves the
	 * original Issue #34 empty-state hash for compatibility with existing clients/tests.
	 * SQL NULL carries an explicit type tag so it cannot alias the empty string.
	 *
	 * @param array<int,array<string,mixed>> $rows Physical rows.
	 * @return string
	 */
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

	/**
	 * @param mixed $value Stored value.
	 * @return true|WP_Error
	 */
	private function unsupported_stored_value_error( $value ) {
		if ( $this->contains_object_or_resource( $value ) ) {
			return new WP_Error( 'post_meta_object_value_unsupported', __( 'This metadata key contains a PHP object and cannot be losslessly replaced through the generic JSON metadata contract.', 'wp-ai-bridge' ) );
		}
		if ( ! $this->is_losslessly_json_compatible( $value ) ) {
			return new WP_Error( 'post_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-ai-bridge' ) );
		}
		return true;
	}

	/** @return WP_Error */
	private function sensitive_key_error() {
		return new WP_Error( 'sensitive_post_meta_key', __( 'Credential-like metadata keys are outside the generic Bridge metadata surface.', 'wp-ai-bridge' ) );
	}

	/**
	 * @param string $operation update or delete.
	 * @return WP_Error
	 */
	private function stale_error( $operation ) {
		$message = 'delete' === $operation
			? __( 'Post metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-ai-bridge' )
			: __( 'Post metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-ai-bridge' );
		return new WP_Error( 'stale_post_meta_conflict', $message );
	}

	/**
	 * @param mixed $value Metadata value.
	 * @return string|WP_Error
	 */
	private function encode_lossless_value( $value ) {
		if ( ! $this->is_losslessly_json_compatible( $value ) ) {
			return new WP_Error( 'post_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-ai-bridge' ) );
		}
		try {
			return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			return new WP_Error( 'post_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-ai-bridge' ) );
		}
	}

	/**
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return bool
	 */
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

	/**
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return bool
	 */
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
			$json    = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			return false;
		}
		return $decoded === $value;
	}

	/**
	 * @param string $value_json JSON value.
	 * @return mixed|WP_Error
	 */
	private function decode_value( $value_json ) {
		try {
			return json_decode( $value_json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			return new WP_Error( 'invalid_post_meta_value_json', __( 'value_json must contain one valid JSON value.', 'wp-ai-bridge' ) );
		}
	}

	/**
	 * @param mixed $value Value.
	 * @return string
	 */
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

	/**
	 * @param array<string,mixed> $input Input.
	 * @return int
	 */
	private function input_post_id( $input ) {
		return is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	}

	/**
	 * Logs mutation failure without key/value data.
	 *
	 * @param WP_Error $error   Error.
	 * @param int      $post_id Post ID.
	 * @param string   $ability Ability name.
	 * @return WP_Error
	 */
	private function logged_error( WP_Error $error, $post_id, $ability ) {
		$this->log->record( $ability, 'post_meta', (int) $post_id, false, $error->get_error_code() );
		return $error;
	}

	/** @return array<string,mixed> */
	private function read_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'        => array(
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
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function update_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'key'                 => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
				'value_json'          => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'expected_state_hash' => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			'required'             => array( 'post_id', 'key', 'value_json', 'expected_state_hash' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function delete_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'key'                 => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
				'expected_state_hash' => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			'required'             => array( 'post_id', 'key', 'expected_state_hash' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function read_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'   => array( 'type' => 'integer' ),
				'post_type' => array( 'type' => 'string' ),
				'items'     => array(
					'type'  => 'array',
					'items' => $this->item_schema(),
				),
			),
			'required'             => array( 'post_id', 'post_type', 'items' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function delete_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'    => array( 'type' => 'integer' ),
				'key'        => array( 'type' => 'string' ),
				'deleted'    => array( 'type' => 'boolean' ),
				'state_hash' => array( 'type' => 'string' ),
			),
			'required'             => array( 'post_id', 'key', 'deleted', 'state_hash' ),
			'additionalProperties' => false,
		);
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

	/**
	 * @param bool $read_only   Readonly.
	 * @param bool $destructive Destructive.
	 * @param bool $idempotent  Idempotent.
	 * @return array<string,mixed>
	 */
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
