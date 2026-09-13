<?php
/**
 * Generic term metadata abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Metadata_Key_Policy;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Term_Meta_Store;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Exposes provider-neutral term metadata behind explicit advanced access.
 */
final class Term_Meta_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @var Mutation_Log */
	private $log;

	/** @var Term_Meta_Store */
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
		$this->store       = new Term_Meta_Store();
	}

	/** @return void */
	public function register() {
		wp_register_ability(
			'wp-native-builder/term-meta-read',
			array(
				'label'               => __( 'Read Term Metadata', 'wp-native-builder-bridge' ),
				'description'         => __( 'Lists metadata keys or reads one exact metadata key for a WordPress taxonomy term when Advanced Metadata access is enabled.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-native-builder/term-meta-update',
			array(
				'label'               => __( 'Update Term Metadata', 'wp-native-builder-bridge' ),
				'description'         => __( 'Creates or replaces one single-value metadata key after checking Advanced Metadata access, WordPress authority, and the expected metadata state.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->update_input_schema(),
				'output_schema'       => $this->item_schema(),
				'execute_callback'    => array( $this, 'update' ),
				'permission_callback' => array( $this, 'can_update' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-native-builder/term-meta-delete',
			array(
				'label'               => __( 'Delete Term Metadata', 'wp-native-builder-bridge' ),
				'description'         => __( 'Deletes one single-value metadata key after checking Advanced Metadata, destructive access, WordPress authority, and the expected metadata state.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->delete_input_schema(),
				'output_schema'       => $this->delete_output_schema(),
				'execute_callback'    => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);
	}

	/**
	 * Checks term metadata read permission.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public function can_read( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) || ! is_array( $input ) ) {
			return false;
		}

		$term = $this->authorized_term( $input );
		if ( ! $term ) {
			return false;
		}

		if ( empty( $input['key'] ) ) {
			return true;
		}

		$key = (string) $input['key'];
		if ( $this->is_sensitive_key( $key ) ) {
			return false;
		}

		$rows = $this->store->rows( $term->term_id, $key );
		if ( is_wp_error( $rows ) ) {
			return false;
		}

		return $this->can_access_meta_key( $term, $key, empty( $rows ) ? 'add' : 'edit' );
	}

	/**
	 * Checks term metadata update permission.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public function can_update( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) || ! is_array( $input ) || empty( $input['key'] ) ) {
			return false;
		}

		$term = $this->authorized_term( $input );
		$key  = (string) $input['key'];
		if ( ! $term || $this->is_sensitive_key( $key ) ) {
			return false;
		}

		$rows = $this->store->rows( $term->term_id, $key );
		if ( is_wp_error( $rows ) ) {
			return false;
		}

		return $this->can_access_meta_key( $term, $key, empty( $rows ) ? 'add' : 'edit' );
	}

	/**
	 * Checks destructive term metadata permission.
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

		$term = $this->authorized_term( $input );
		$key  = (string) $input['key'];
		return $term && ! $this->is_sensitive_key( $key ) && $this->can_access_meta_key( $term, $key, 'delete' );
	}

	/**
	 * Lists keys or reads one exact physical metadata key.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read( $input ) {
		$term = $this->validated_term( $input );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$key            = isset( $input['key'] ) ? (string) $input['key'] : '';
		$include_values = ! empty( $input['include_values'] );
		if ( $include_values && '' === $key ) {
			return new WP_Error( 'term_meta_key_required_for_values', __( 'Specify one exact metadata key before requesting metadata values.', 'wp-native-builder-bridge' ) );
		}

		if ( '' !== $key ) {
			if ( $this->is_sensitive_key( $key ) ) {
				return $this->sensitive_key_error();
			}
			$rows = $this->store->rows( $term->term_id, $key );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			$key_error = $this->validate_key_access( $term, $key, empty( $rows ) ? 'add' : 'edit' );
			if ( is_wp_error( $key_error ) ) {
				return $key_error;
			}
			if ( count( $rows ) > 1 ) {
				return new WP_Error( 'term_meta_multiple_values_unsupported', __( 'This term metadata key has multiple physical rows. The generic Bridge refuses ambiguous metadata.', 'wp-native-builder-bridge' ) );
			}
			$item = $this->item_from_rows( $key, $rows, $include_values );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			return array(
				'term_id'  => (int) $term->term_id,
				'taxonomy' => (string) $term->taxonomy,
				'items'    => array( $item ),
				'page'     => 1,
				'per_page' => 1,
				'has_more' => false,
			);
		}

		$page     = isset( $input['page'] ) ? max( 1, min( 10000, (int) $input['page'] ) ) : 1;
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 50;
		$keys     = $this->store->list_keys( $term->term_id, $per_page + 1, ( $page - 1 ) * $per_page );
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}
		$has_more = count( $keys ) > $per_page;
		$items    = array();
		foreach ( array_slice( $keys, 0, $per_page ) as $summary ) {
			$key = (string) $summary['meta_key'];
			if ( '' !== $key && ! $this->is_sensitive_key( $key ) && $this->can_access_meta_key( $term, $key, 'edit' ) ) {
				$items[] = array(
					'key'   => $key,
					'count' => (int) $summary['row_count'],
				);
			}
		}
		return array(
			'term_id'  => (int) $term->term_id,
			'taxonomy' => (string) $term->taxonomy,
			'items'    => $items,
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => $has_more,
		);
	}

	/**
	 * Creates or replaces one single-value metadata key.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update( $input ) {
		$term = $this->validated_term( $input );
		if ( is_wp_error( $term ) ) {
			return $this->logged_error( $term, $this->input_term_id( $input ), 'wp-native-builder/term-meta-update' );
		}

		$key = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( '' === $key ) {
			return $this->logged_error( new WP_Error( 'term_meta_key_required', __( 'A metadata key is required.', 'wp-native-builder-bridge' ) ), $term->term_id, 'wp-native-builder/term-meta-update' );
		}
		if ( $this->is_sensitive_key( $key ) ) {
			return $this->logged_error( $this->sensitive_key_error(), $term->term_id, 'wp-native-builder/term-meta-update' );
		}

		$rows = $this->store->rows( $term->term_id, $key );
		if ( is_wp_error( $rows ) ) {
			return $this->logged_error( $rows, $term->term_id, 'wp-native-builder/term-meta-update' );
		}
		$key_error = $this->validate_key_access( $term, $key, empty( $rows ) ? 'add' : 'edit' );
		if ( is_wp_error( $key_error ) ) {
			return $this->logged_error( $key_error, $term->term_id, 'wp-native-builder/term-meta-update' );
		}
		if ( count( $rows ) > 1 ) {
			return $this->logged_error( new WP_Error( 'term_meta_multiple_values_unsupported', __( 'This metadata key has multiple rows. The generic updater refuses to guess which row should be replaced.', 'wp-native-builder-bridge' ) ), $term->term_id, 'wp-native-builder/term-meta-update' );
		}
		if ( $rows ) {
			$value_error = $this->unsupported_row_error( $rows[0] );
			if ( is_wp_error( $value_error ) ) {
				return $this->logged_error( $value_error, $term->term_id, 'wp-native-builder/term-meta-update' );
			}
		}

		$current_hash = $this->state_hash( $rows );
		$expected     = isset( $input['expected_state_hash'] ) ? (string) $input['expected_state_hash'] : '';
		if ( '' === $expected || ! hash_equals( $current_hash, $expected ) ) {
			return $this->logged_error( $this->stale_error( 'update' ), $term->term_id, 'wp-native-builder/term-meta-update' );
		}

		$value = $this->decode_value( isset( $input['value_json'] ) ? (string) $input['value_json'] : '' );
		if ( is_wp_error( $value ) ) {
			return $this->logged_error( $value, $term->term_id, 'wp-native-builder/term-meta-update' );
		}
		if ( ! $this->is_losslessly_json_compatible( $value ) ) {
			return $this->logged_error( new WP_Error( 'term_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) ), $term->term_id, 'wp-native-builder/term-meta-update' );
		}

		if ( empty( $rows ) ) {
			return $this->create_value( $term, $key, $value, $current_hash );
		}

		$prepared_value = $this->store->prepare_value(
			$term->term_id,
			$key,
			$value,
			function ( $sanitized ) {
				return ! is_wp_error( $this->unsupported_stored_value_error( $sanitized ) );
			}
		);
		if ( is_wp_error( $prepared_value ) ) {
			return $this->logged_error( $prepared_value, $term->term_id, 'wp-native-builder/term-meta-update' );
		}
		$prepared_error = $this->unsupported_stored_value_error( $prepared_value['value'] );
		if ( strlen( (string) $prepared_value['raw_value'] ) > Term_Meta_Store::MAX_VALUE_BYTES ) {
			$prepared_error = new WP_Error( 'term_meta_value_too_large', __( 'Term metadata values are limited to 1 MiB per key.', 'wp-native-builder-bridge' ) );
		}
		if ( is_wp_error( $prepared_error ) ) {
			return $this->logged_error( $prepared_error, $term->term_id, 'wp-native-builder/term-meta-update' );
		}

		if ( ! $this->can_access_meta_key( $term, $key, 'edit' ) ) {
			return $this->logged_error( new WP_Error( 'term_meta_permission_denied', __( 'The current WordPress user is not allowed to perform this metadata operation.', 'wp-native-builder-bridge' ) ), $term->term_id, 'wp-native-builder/term-meta-update' );
		}

		$verified_row = $this->store->replace_row(
			$term->term_id,
			$key,
			$this->with_target_identity( $term, $rows[0] ),
			$prepared_value,
			function () use ( $term, $key ) {
				return $this->can_access_meta_key( $term, $key, 'edit' );
			}
		);
		if ( is_wp_error( $verified_row ) ) {
			return $this->logged_error( $verified_row, $term->term_id, 'wp-native-builder/term-meta-update' );
		}

		$this->log->record( 'wp-native-builder/term-meta-update', 'term_meta', (int) $term->term_id, true, '' );
		return $this->item_from_rows( $key, array( $verified_row ), true );
	}

	/**
	 * Deletes one single-value metadata key.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete( $input ) {
		$term_id = $this->input_term_id( $input );
		if ( ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' ) ) {
			return $this->logged_error( new WP_Error( 'destructive_access_disabled', __( 'Users & Destructive access is required before deleting term metadata.', 'wp-native-builder-bridge' ) ), $term_id, 'wp-native-builder/term-meta-delete' );
		}

		$term = $this->validated_term( $input );
		if ( is_wp_error( $term ) ) {
			return $this->logged_error( $term, $term_id, 'wp-native-builder/term-meta-delete' );
		}
		$key = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( '' === $key ) {
			return $this->logged_error( new WP_Error( 'term_meta_key_required', __( 'A metadata key is required.', 'wp-native-builder-bridge' ) ), $term->term_id, 'wp-native-builder/term-meta-delete' );
		}
		if ( $this->is_sensitive_key( $key ) ) {
			return $this->logged_error( $this->sensitive_key_error(), $term->term_id, 'wp-native-builder/term-meta-delete' );
		}

		$key_error = $this->validate_key_access( $term, $key, 'delete' );
		if ( is_wp_error( $key_error ) ) {
			return $this->logged_error( $key_error, $term->term_id, 'wp-native-builder/term-meta-delete' );
		}

		$rows = $this->store->rows( $term->term_id, $key );
		if ( is_wp_error( $rows ) ) {
			return $this->logged_error( $rows, $term->term_id, 'wp-native-builder/term-meta-delete' );
		}
		if ( count( $rows ) > 1 ) {
			return $this->logged_error( new WP_Error( 'term_meta_multiple_values_unsupported', __( 'This metadata key has multiple rows. The generic deleter refuses to remove an ambiguous multi-row value set.', 'wp-native-builder-bridge' ) ), $term->term_id, 'wp-native-builder/term-meta-delete' );
		}

		$current_hash = $this->state_hash( $rows );
		$expected     = isset( $input['expected_state_hash'] ) ? (string) $input['expected_state_hash'] : '';
		if ( '' === $expected || ! hash_equals( $current_hash, $expected ) ) {
			return $this->logged_error( $this->stale_error( 'delete' ), $term->term_id, 'wp-native-builder/term-meta-delete' );
		}
		if ( empty( $rows ) ) {
			return array(
				'term_id'    => (int) $term->term_id,
				'key'        => $key,
				'deleted'    => false,
				'state_hash' => $current_hash,
			);
		}

		$value_error = $this->unsupported_row_error( $rows[0] );
		if ( is_wp_error( $value_error ) ) {
			return $this->logged_error( $value_error, $term->term_id, 'wp-native-builder/term-meta-delete' );
		}

		$result = $this->store->delete_row(
			$term->term_id,
			$key,
			$this->with_target_identity( $term, $rows[0] ),
			function () use ( $term, $key ) {
				return $this->can_access_meta_key( $term, $key, 'delete' );
			}
		);
		if ( is_wp_error( $result ) ) {
			return $this->logged_error( $result, $term->term_id, 'wp-native-builder/term-meta-delete' );
		}

		$this->log->record( 'wp-native-builder/term-meta-delete', 'term_meta', (int) $term->term_id, true, '' );
		return array(
			'term_id'    => (int) $term->term_id,
			'key'        => $key,
			'deleted'    => true,
			'state_hash' => $this->state_hash( array() ),
		);
	}

	/**
	 * Creates one row, then removes only this invocation's unchanged row if Core's unique check races.
	 *
	 * @param object $term         Canonical term object.
	 * @param string $key          Exact unslashed key.
	 * @param mixed  $value        JSON-decoded value.
	 * @param string $current_hash Empty-state hash.
	 * @return array<string,mixed>|WP_Error
	 */
	private function create_value( $term, $key, $value, $current_hash ) {
		$target_identity = $this->with_target_identity( $term, array() );
		$creation        = $this->store->create_unique_row(
			$term->term_id,
			$key,
			$value,
			function ( $sanitized ) use ( $term, $key ) {
				return $this->can_access_meta_key( $term, $key, 'add' ) && ! is_wp_error( $this->unsupported_stored_value_error( $sanitized ) );
			},
			$target_identity
		);
		if ( is_wp_error( $creation ) ) {
			return $this->logged_error( $creation, $term->term_id, 'wp-native-builder/term-meta-update' );
		}

		$result       = $creation['result'];
		$expected_row = $creation['expected_row'] + $target_identity;
		$after        = $this->store->rows( $term->term_id, $key );
		if ( is_wp_error( $after ) ) {
			if ( is_int( $result ) && $result > 0 && ! empty( $creation['owned'] ) ) {
				$cleanup = $this->store->cleanup_created_row( $expected_row );
				if ( is_wp_error( $cleanup ) ) {
					return $this->logged_error( $cleanup, $term->term_id, 'wp-native-builder/term-meta-update' );
				}
			}
			return $this->logged_error( $after, $term->term_id, 'wp-native-builder/term-meta-update' );
		}

		if ( ! is_int( $result ) || $result < 1 ) {
			if ( $this->state_hash( $after ) !== $current_hash ) {
				return $this->logged_error( $this->stale_error( 'update' ), $term->term_id, 'wp-native-builder/term-meta-update' );
			}
			return $this->logged_error( new WP_Error( 'term_meta_update_failed', __( 'WordPress could not update the requested metadata key.', 'wp-native-builder-bridge' ) ), $term->term_id, 'wp-native-builder/term-meta-update' );
		}

		if ( empty( $creation['owned'] ) ) {
			return $this->logged_error( $this->stale_error( 'update' ), $term->term_id, 'wp-native-builder/term-meta-update' );
		}
		if ( 1 !== count( $after ) || ! $this->can_access_meta_key( $term, $key, 'edit' ) ) {
			$cleanup = $this->store->cleanup_created_row( $expected_row );
			if ( is_wp_error( $cleanup ) ) {
				return $this->logged_error( $cleanup, $term->term_id, 'wp-native-builder/term-meta-update' );
			}
			return $this->logged_error( $this->stale_error( 'update' ), $term->term_id, 'wp-native-builder/term-meta-update' );
		}
		$created_row = $after[0];
		if ( ! $this->store->row_matches( $created_row, $expected_row ) ) {
			return $this->logged_error( $this->stale_error( 'update' ), $term->term_id, 'wp-native-builder/term-meta-update' );
		}

		$created_value_error = $this->unsupported_row_error( $created_row );
		if ( is_wp_error( $created_value_error ) ) {
			$cleanup = $this->store->cleanup_created_row( $expected_row );
			if ( is_wp_error( $cleanup ) ) {
				return $this->logged_error( $cleanup, $term->term_id, 'wp-native-builder/term-meta-update' );
			}
			return $this->logged_error( $created_value_error, $term->term_id, 'wp-native-builder/term-meta-update' );
		}

		$this->log->record( 'wp-native-builder/term-meta-update', 'term_meta', (int) $term->term_id, true, '' );
		return $this->item_from_rows( $key, $after, true );
	}

	/**
	 * Pins scalar target identity for compensation without extending the public schema.
	 *
	 * @param object              $term Authorized term snapshot.
	 * @param array<string,mixed> $row  Internal row snapshot.
	 * @return array<string,mixed>
	 */
	private function with_target_identity( $term, array $row ) {
		$row['target_taxonomy']         = (string) $term->taxonomy;
		$row['target_term_taxonomy_id'] = (int) $term->term_taxonomy_id;
		return $row;
	}

	/**
	 * Resolves the same unambiguous term used by Core subtype/capability mapping.
	 *
	 * @param array<string,mixed> $input Input identity.
	 * @return object|null
	 */
	private function authorized_term( $input ) {
		if ( ! is_array( $input ) || ! isset( $input['term_id'], $input['taxonomy'] ) || ! is_int( $input['term_id'] ) || $input['term_id'] < 1 || ! is_string( $input['taxonomy'] ) || '' === $input['taxonomy'] ) {
			return null;
		}
		$taxonomy = get_taxonomy( $input['taxonomy'] );
		if ( ! $taxonomy || wp_term_is_shared( $input['term_id'] ) ) {
			return null;
		}
		$term      = get_term( $input['term_id'], $input['taxonomy'] );
		$canonical = get_term( $input['term_id'] );
		if ( ! $term || is_wp_error( $term ) || ! $canonical || is_wp_error( $canonical )
			|| (int) $term->term_id !== $input['term_id'] || $term->taxonomy !== $input['taxonomy']
			|| (int) $canonical->term_taxonomy_id !== (int) $term->term_taxonomy_id
			|| $canonical->taxonomy !== $term->taxonomy || get_object_subtype( 'term', $term->term_id ) !== $term->taxonomy
			|| ! current_user_can( 'edit_term', $term->term_id ) ) {
			return null;
		}
		return $term;
	}

	/**
	 * @param array<string,mixed> $input Ability input.
	 * @return object|WP_Error
	 */
	private function validated_term( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) ) {
			return new WP_Error( 'advanced_metadata_access_disabled', __( 'Advanced Metadata access is disabled in WP AI Bridge settings.', 'wp-native-builder-bridge' ) );
		}
		$term = $this->authorized_term( $input );
		if ( ! $term ) {
			return new WP_Error( 'term_meta_target_not_allowed', __( 'The requested term and taxonomy do not identify one unambiguous WordPress term that the current user may edit.', 'wp-native-builder-bridge' ) );
		}
		return $term;
	}

	/**
	 * @param object $term      Term object.
	 * @param string $key       Exact key.
	 * @param string $operation add, edit, or delete.
	 * @return true|WP_Error
	 */
	private function validate_key_access( $term, $key, $operation ) {
		if ( $this->is_sensitive_key( $key ) ) {
			return $this->sensitive_key_error();
		}
		if ( ! $this->can_access_meta_key( $term, $key, $operation ) ) {
			return new WP_Error( 'term_meta_permission_denied', __( 'The current WordPress user is not allowed to perform this metadata operation.', 'wp-native-builder-bridge' ) );
		}
		return true;
	}

	/**
	 * Preserves explicit provider/Core authorization while overriding only Core's
	 * generic protected-unregistered-meta default after admin opt-in.
	 *
	 * @param object $term      Term object.
	 * @param string $key       Exact key.
	 * @param string $operation add, edit, or delete.
	 * @return bool
	 */
	private function can_access_meta_key( $term, $key, $operation ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' )
			|| ( 'delete' === $operation && ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' ) )
			|| ! $this->authorized_term(
				array(
					'term_id'  => (int) $term->term_id,
					'taxonomy' => $term->taxonomy,
				)
			) ) {
			return false;
		}
		$capability = 'edit_term_meta';
		if ( 'add' === $operation ) {
			$capability = 'add_term_meta';
		} elseif ( 'delete' === $operation ) {
			$capability = 'delete_term_meta';
		}

		if ( ! is_protected_meta( $key, 'term' ) || $this->has_explicit_meta_auth_contract( $term->taxonomy, $key ) ) {
			$mapped = map_meta_cap( $capability, get_current_user_id(), $term->term_id, $key );
			// An explicit metadata denial remains a denial even if a primitive meta cap was granted.
			return ! in_array( $capability, $mapped, true ) && ! in_array( 'do_not_allow', $mapped, true )
				&& current_user_can( $capability, $term->term_id, $key );
		}

		$user_id = get_current_user_id();
		$hook    = 'auth_term_meta_' . $key;
		$opt_in  = static function ( $allowed, $meta_key, $object_id, $user, $cap ) use ( $term, $key, $capability, $user_id ) {
			// Replace only the default protected/unregistered denial, before capability mapping.
			// All later provider authorization and map_meta_cap requirements still apply.
			return $cap === $capability && (int) $user === $user_id
				&& (int) $object_id === (int) $term->term_id && $meta_key === $key
				? true : $allowed;
		};
		add_filter( $hook, $opt_in, PHP_INT_MIN, 6 );
		try {
			$mapped = map_meta_cap( $capability, $user_id, $term->term_id, $key );
			// Keep explicit meta-cap denial and user_has_cap's original object/key context.
			return ! in_array( $capability, $mapped, true ) && ! in_array( 'do_not_allow', $mapped, true )
				&& current_user_can( $capability, $term->term_id, $key );
		} finally {
			remove_filter( $hook, $opt_in, PHP_INT_MIN );
		}
	}

	/**
	 * @param string $taxonomy Taxonomy subtype.
	 * @param string $key       Metadata key.
	 * @return bool
	 */
	private function has_explicit_meta_auth_contract( $taxonomy, $key ) {
		if ( function_exists( 'get_registered_meta_keys' ) ) {
			$global  = get_registered_meta_keys( 'term' );
			$subtype = get_registered_meta_keys( 'term', $taxonomy );
			if ( ( is_array( $global ) && isset( $global[ $key ] ) ) || ( is_array( $subtype ) && isset( $subtype[ $key ] ) ) ) {
				return true;
			}
		}
		if ( ! function_exists( 'has_filter' ) ) {
			return false;
		}
		return false !== has_filter( 'auth_term_meta_' . $key . '_for_' . $taxonomy )
			|| false !== has_filter( 'auth_term_meta_' . $key )
			|| false !== has_filter( 'auth_term_' . $taxonomy . '_meta_' . $key );
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
			$value_types[] = ! empty( $row['serialization_loss'] ) ? 'opaque' : $this->value_type( $value );
			if ( $include_values ) {
				$valid = $this->unsupported_row_error( $row );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
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
	 * Rejects malformed or non-canonical serialized bytes before replacement/deletion.
	 *
	 * @param array<string,mixed> $row Physical row.
	 * @return true|WP_Error
	 */
	private function unsupported_row_error( array $row ) {
		if ( ! empty( $row['serialization_loss'] ) ) {
			return new WP_Error( 'term_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) );
		}
		return $this->unsupported_stored_value_error( $row['value'] );
	}

	/**
	 * @param mixed $value Stored value.
	 * @return true|WP_Error
	 */
	private function unsupported_stored_value_error( $value ) {
		if ( $this->contains_object_or_resource( $value ) ) {
			return new WP_Error( 'term_meta_object_value_unsupported', __( 'This metadata key contains a PHP object and cannot be losslessly replaced through the generic JSON metadata contract.', 'wp-native-builder-bridge' ) );
		}
		if ( ! $this->is_losslessly_json_compatible( $value ) ) {
			return new WP_Error( 'term_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) );
		}
		return true;
	}

	/** @return WP_Error */
	private function sensitive_key_error() {
		return new WP_Error( 'sensitive_term_meta_key', __( 'Credential-like metadata keys are outside the generic Bridge metadata surface.', 'wp-native-builder-bridge' ) );
	}

	/**
	 * @param string $operation update or delete.
	 * @return WP_Error
	 */
	private function stale_error( $operation ) {
		$message = 'delete' === $operation
			? __( 'Term metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-native-builder-bridge' )
			: __( 'Term metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-native-builder-bridge' );
		return new WP_Error( 'stale_term_meta_conflict', $message );
	}

	/**
	 * @param mixed $value Metadata value.
	 * @return string|WP_Error
	 */
	private function encode_lossless_value( $value ) {
		if ( ! $this->is_losslessly_json_compatible( $value ) ) {
			return new WP_Error( 'term_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) );
		}
		try {
			return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			return new WP_Error( 'term_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) );
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
		if ( $this->contains_object_or_resource( $value, $depth ) ) {
			return false;
		}
		try {
			$json    = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR );
			$decoded = $this->decode_value( $json );
		} catch ( \JsonException $exception ) {
			return false;
		}
		return ! is_wp_error( $decoded ) && $decoded === $value && serialize( $decoded ) === serialize( $value );
	}

	/**
	 * @param string $value_json JSON value.
	 * @return mixed|WP_Error
	 */
	private function decode_value( $value_json ) {
		if ( strlen( $value_json ) > Term_Meta_Store::MAX_VALUE_BYTES ) {
			return new WP_Error( 'term_meta_value_too_large', __( 'Term metadata values are limited to 1 MiB per key.', 'wp-native-builder-bridge' ) );
		}
		try {
			$value    = json_decode( $value_json, true, 64, JSON_THROW_ON_ERROR );
			$bigints  = json_decode( $value_json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
			$original = json_decode( $value_json, false, 64, JSON_THROW_ON_ERROR );
			$flags    = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
			if ( $value !== $bigints || json_encode( $original, $flags ) !== json_encode( $value, $flags ) ) {
				return new WP_Error( 'term_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) );
			}
			return $value;
		} catch ( \JsonException $exception ) {
			return new WP_Error( 'invalid_term_meta_value_json', __( 'value_json must contain one valid JSON value.', 'wp-native-builder-bridge' ) );
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
	private function input_term_id( $input ) {
		return is_array( $input ) && isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
	}

	/**
	 * Logs mutation failure without key/value data.
	 *
	 * @param WP_Error $error   Error.
	 * @param int      $term_id Term ID.
	 * @param string   $ability Ability name.
	 * @return WP_Error
	 */
	private function logged_error( WP_Error $error, $term_id, $ability ) {
		$this->log->record( $ability, 'term_meta', (int) $term_id, false, $error->get_error_code() );
		return $error;
	}

	/** @return array<string,mixed> */
	private function read_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page'           => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10000,
					'default' => 1,
				),
				'per_page'       => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 50,
				),
				'taxonomy'       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 32,
				),
				'term_id'        => array(
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
			'required'             => array( 'taxonomy', 'term_id' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function update_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'            => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 32,
				),
				'term_id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'key'                 => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
				'value_json'          => array(
					'maxLength' => Term_Meta_Store::MAX_VALUE_BYTES,
					'type'      => 'string',
					'minLength' => 1,
				),
				'expected_state_hash' => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			'required'             => array( 'taxonomy', 'term_id', 'key', 'value_json', 'expected_state_hash' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function delete_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'            => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 32,
				),
				'term_id'             => array(
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
			'required'             => array( 'taxonomy', 'term_id', 'key', 'expected_state_hash' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function read_output_schema() {
		$item             = $this->item_schema();
		$item['required'] = array( 'key', 'count' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
				'has_more' => array( 'type' => 'boolean' ),
				'term_id'  => array( 'type' => 'integer' ),
				'taxonomy' => array( 'type' => 'string' ),
				'items'    => array(
					'type'  => 'array',
					'items' => $item,
				),
			),
			'required'             => array( 'term_id', 'taxonomy', 'items', 'page', 'per_page', 'has_more' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function delete_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'term_id'    => array( 'type' => 'integer' ),
				'key'        => array( 'type' => 'string' ),
				'deleted'    => array( 'type' => 'boolean' ),
				'state_hash' => array( 'type' => 'string' ),
			),
			'required'             => array( 'term_id', 'key', 'deleted', 'state_hash' ),
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
