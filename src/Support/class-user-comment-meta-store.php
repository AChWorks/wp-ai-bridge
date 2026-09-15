<?php
/**
 * Fixed-purpose user/comment metadata storage helpers.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Support;

use WP_Error;

/**
 * Keeps Issue #58 metadata mutations bound to one physical usermeta/commentmeta row.
 *
 * This is intentionally not a generic database abstraction. The only supported object
 * types are user and comment, and every table/column/query shape is selected internally.
 */
final class User_Comment_Meta_Store {
	const MAX_VALUE_BYTES = 1048576;

	/**
	 * Returns at most the bounded physical rows for one object/key, or a bounded key summary.
	 *
	 * @param string      $type      user or comment.
	 * @param int         $object_id Exact object ID.
	 * @param string|null $key       Exact key; null lists bounded rows for grouping.
	 * @param int         $limit     Maximum rows for a broad list.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function rows( $type, $object_id, $key = null, $limit = 200 ) {
		global $wpdb;

		$type      = $this->type( $type );
		$object_id = (int) $object_id;
		if ( is_wp_error( $type ) || $object_id < 1 ) {
			return $this->state_error();
		}

		if ( null !== $key ) {
			$key = (string) $key;
			if ( 'user' === $type ) {
				$prepared = $wpdb->prepare(
					"SELECT umeta_id AS meta_id, user_id AS object_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY umeta_id LIMIT 2",
					$object_id,
					$key
				);
			} else {
				$prepared = $wpdb->prepare(
					"SELECT meta_id, comment_id AS object_id, meta_key, meta_value FROM {$wpdb->commentmeta} WHERE comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id LIMIT 2",
					$object_id,
					$key
				);
			}
			$raw_rows = $wpdb->get_results( $prepared, ARRAY_A );
		} else {
			$limit = max( 1, min( 200, (int) $limit ) );
			if ( 'user' === $type ) {
				$prepared = $wpdb->prepare(
					"SELECT umeta_id AS meta_id, user_id AS object_id, meta_key, NULL AS meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key IS NOT NULL ORDER BY CAST(meta_key AS BINARY), umeta_id LIMIT %d",
					$object_id,
					$limit
				);
			} else {
				$prepared = $wpdb->prepare(
					"SELECT meta_id, comment_id AS object_id, meta_key, NULL AS meta_value FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key IS NOT NULL ORDER BY CAST(meta_key AS BINARY), meta_id LIMIT %d",
					$object_id,
					$limit
				);
			}
			$raw_rows = $wpdb->get_results( $prepared, ARRAY_A );
		}

		if ( ! is_array( $raw_rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->state_error();
		}

		$rows = array();
		foreach ( $raw_rows as $row ) {
			if ( ! isset( $row['meta_id'], $row['object_id'], $row['meta_key'] ) || ! array_key_exists( 'meta_value', $row ) ) {
				return $this->state_error();
			}
			$raw_value = $row['meta_value'];
			if ( null !== $raw_value && ! is_scalar( $raw_value ) ) {
				return $this->state_error();
			}
			if ( null !== $raw_value ) {
				$raw_value = (string) $raw_value;
				if ( strlen( $raw_value ) > self::MAX_VALUE_BYTES ) {
					return new WP_Error( 'object_meta_value_too_large', __( 'This metadata value is too large for the bounded generic metadata contract.', 'wp-native-builder-bridge' ) );
				}
			}
			$rows[] = array(
				'meta_id'   => (int) $row['meta_id'],
				'object_id' => (int) $row['object_id'],
				'key'       => (string) $row['meta_key'],
				'raw_value' => $raw_value,
				'value'     => null === $raw_value ? null : maybe_unserialize( $raw_value ),
			);
		}
		return $rows;
	}

	/**
	 * Sanitizes and serializes one value exactly for its supported metadata type.
	 *
	 * @param string $type      user or comment.
	 * @param int    $object_id Object ID.
	 * @param string $key       Exact key.
	 * @param mixed  $value     Canonical unslashed value.
	 * @return array{value:mixed,raw_value:string|null}|WP_Error
	 */
	public function prepare_value( $type, $object_id, $key, $value ) {
		$type = $this->type( $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$subtype = function_exists( 'get_object_subtype' ) ? get_object_subtype( $type, (int) $object_id ) : '';
		if ( function_exists( 'sanitize_meta' ) ) {
			$value = sanitize_meta( (string) $key, $value, $type, $subtype );
		}
		return $this->stored_value( $value );
	}

	/**
	 * Creates one unique row through Core so provider sanitization/lifecycle hooks remain authoritative.
	 *
	 * @param string $type      user or comment.
	 * @param int    $object_id Object ID.
	 * @param string $key       Exact key.
	 * @param mixed  $value     Canonical unslashed value.
	 * @return array{result:mixed,expected_row:array<string,mixed>}|WP_Error
	 */
	public function create_unique_row( $type, $object_id, $key, $value ) {
		$type = $this->type( $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$object_id = (int) $object_id;
		$key       = (string) $key;
		$result    = add_metadata( $type, $object_id, $key, $value, true );
		$after     = $this->rows( $type, $object_id, $key );
		if ( is_wp_error( $after ) ) {
			return $after;
		}

		$expected = null;
		if ( is_int( $result ) && $result > 0 ) {
			foreach ( $after as $row ) {
				if ( (int) $row['meta_id'] === $result ) {
					$expected = $row;
					break;
				}
			}
		}
		if ( null === $expected ) {
			$prepared = $this->prepare_value( $type, $object_id, $key, $value );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$expected = array(
				'meta_id'   => is_int( $result ) ? $result : 0,
				'object_id' => $object_id,
				'key'       => $key,
				'raw_value' => $prepared['raw_value'],
				'value'     => $prepared['value'],
			);
		}
		return array( 'result' => $result, 'expected_row' => $expected );
	}

	/**
	 * Replaces one exact row with byte-exact CAS semantics while preserving Core hooks.
	 *
	 * @param string              $type      user or comment.
	 * @param int                 $object_id Object ID.
	 * @param string              $key       Exact key.
	 * @param array<string,mixed> $row       Inspected row.
	 * @param array<string,mixed> $prepared  Sanitized value storage representation.
	 * @return array<string,mixed>|WP_Error
	 */
	public function replace_row( $type, $object_id, $key, array $row, array $prepared ) {
		global $wpdb;
		$type = $this->type( $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$current = $this->rows( $type, $object_id, $key );
		if ( is_wp_error( $current ) || 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return $this->stale_error();
		}

		$short = apply_filters( "update_{$type}_metadata", null, (int) $object_id, (string) $key, $prepared['value'], $row['value'] );
		if ( null !== $short ) {
			return new WP_Error( 'object_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata row atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-native-builder-bridge' ) );
		}

		do_action( "update_{$type}_meta", (int) $row['meta_id'], (int) $object_id, (string) $key, $prepared['value'] );
		if ( 'comment' === $type ) {
			do_action( 'update_commentmeta', (int) $row['meta_id'], (int) $object_id, (string) $key, $prepared['raw_value'] );
		}

		if ( 'user' === $type ) {
			$result = $this->update_user_row( $wpdb, $row, $prepared['raw_value'] );
		} else {
			$result = $this->update_comment_row( $wpdb, $row, $prepared['raw_value'] );
		}
		wp_cache_delete( (int) $object_id, $type . '_meta' );
		if ( 1 !== $result ) {
			return $this->stale_error();
		}

		do_action( "updated_{$type}_meta", (int) $row['meta_id'], (int) $object_id, (string) $key, $prepared['value'] );
		if ( 'comment' === $type ) {
			do_action( 'updated_commentmeta', (int) $row['meta_id'], (int) $object_id, (string) $key, $prepared['raw_value'] );
		}

		$after = $this->rows( $type, $object_id, $key );
		if ( is_wp_error( $after ) || 1 !== count( $after ) ) {
			return new WP_Error( 'object_meta_verification_failed', __( 'The metadata mutation could not be verified safely.', 'wp-native-builder-bridge' ) );
		}
		$expected              = $row;
		$expected['raw_value'] = $prepared['raw_value'];
		$expected['value']     = $prepared['value'];
		if ( ! $this->row_matches( $after[0], $expected ) ) {
			return new WP_Error( 'object_meta_verification_failed', __( 'The metadata mutation could not be verified safely.', 'wp-native-builder-bridge' ) );
		}
		return $after[0];
	}

	/**
	 * Deletes one exact row with byte-exact CAS semantics while preserving Core hooks.
	 *
	 * @param string              $type      user or comment.
	 * @param int                 $object_id Object ID.
	 * @param string              $key       Exact key.
	 * @param array<string,mixed> $row       Inspected row.
	 * @return true|WP_Error
	 */
	public function delete_row( $type, $object_id, $key, array $row ) {
		global $wpdb;
		$type = $this->type( $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$current = $this->rows( $type, $object_id, $key );
		if ( is_wp_error( $current ) || 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return $this->stale_error();
		}

		$short = apply_filters( "delete_{$type}_metadata", null, (int) $object_id, (string) $key, $row['value'], false );
		if ( null !== $short ) {
			return new WP_Error( 'object_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata row atomically. The generic Bridge refuses the mutation to avoid a stale delete.', 'wp-native-builder-bridge' ) );
		}
		do_action( "delete_{$type}_meta", array( (int) $row['meta_id'] ), (int) $object_id, (string) $key, $row['value'] );
		if ( 'comment' === $type ) {
			do_action( 'delete_commentmeta', (int) $row['meta_id'] );
		}

		if ( 'user' === $type ) {
			$result = $this->delete_user_row( $wpdb, $row );
		} else {
			$result = $this->delete_comment_row( $wpdb, $row );
		}
		wp_cache_delete( (int) $object_id, $type . '_meta' );
		if ( 1 !== $result ) {
			return $this->stale_error();
		}
		do_action( "deleted_{$type}_meta", array( (int) $row['meta_id'] ), (int) $object_id, (string) $key, $row['value'] );
		if ( 'comment' === $type ) {
			do_action( 'deleted_commentmeta', (int) $row['meta_id'] );
		}
		$after = $this->rows( $type, $object_id, $key );
		if ( is_wp_error( $after ) || ! empty( $after ) ) {
			return new WP_Error( 'object_meta_verification_failed', __( 'The metadata mutation could not be verified safely.', 'wp-native-builder-bridge' ) );
		}
		return true;
	}

	/** Removes only an exact row created by this invocation during a uniqueness race. */
	public function cleanup_created_row( $type, array $row ) {
		$current = get_metadata_by_mid( (string) $type, (int) $row['meta_id'] );
		if ( ! $current ) {
			return true;
		}
		$current_raw = maybe_serialize( $current->meta_value );
		if ( (string) $current->meta_key !== (string) $row['key'] || $current_raw !== $row['raw_value'] ) {
			return $this->stale_error();
		}
		return delete_metadata_by_mid( (string) $type, (int) $row['meta_id'] ) ? true : new WP_Error( 'object_meta_cleanup_failed', __( 'The Bridge could not remove its exact raced metadata row safely.', 'wp-native-builder-bridge' ) );
	}

	/** Checks whether two physical row snapshots are identical. */
	public function row_matches( array $left, array $right ) {
		return (int) $left['meta_id'] === (int) $right['meta_id']
			&& (int) $left['object_id'] === (int) $right['object_id']
			&& (string) $left['key'] === (string) $right['key']
			&& $left['raw_value'] === $right['raw_value'];
	}

	private function stored_value( $value ) {
		$raw = maybe_serialize( $value );
		return array( 'value' => $value, 'raw_value' => null === $raw ? null : (string) $raw );
	}

	private function type( $type ) {
		$type = (string) $type;
		return in_array( $type, array( 'user', 'comment' ), true ) ? $type : new WP_Error( 'unsupported_object_meta_type', __( 'The requested metadata object type is not supported.', 'wp-native-builder-bridge' ) );
	}

	private function state_error() {
		return new WP_Error( 'object_meta_physical_state_unavailable', __( 'Physical metadata state could not be established safely.', 'wp-native-builder-bridge' ) );
	}

	private function stale_error() {
		return new WP_Error( 'stale_object_meta_conflict', __( 'Metadata changed after it was read. Refresh the metadata state before mutating it.', 'wp-native-builder-bridge' ) );
	}

	private function update_user_row( $wpdb, array $row, $new_raw ) {
		if ( null === $row['raw_value'] ) {
			if ( null === $new_raw ) {
				return 0;
			}
			return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d AND user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL", $new_raw, $row['meta_id'], $row['object_id'], $row['key'] ) );
		}
		if ( null === $new_raw ) {
			return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value = NULL WHERE umeta_id = %d AND user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)", $row['meta_id'], $row['object_id'], $row['key'], $row['raw_value'] ) );
		}
		return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d AND user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)", $new_raw, $row['meta_id'], $row['object_id'], $row['key'], $row['raw_value'] ) );
	}

	private function update_comment_row( $wpdb, array $row, $new_raw ) {
		if ( null === $row['raw_value'] ) {
			if ( null === $new_raw ) {
				return 0;
			}
			return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->commentmeta} SET meta_value = %s WHERE meta_id = %d AND comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL", $new_raw, $row['meta_id'], $row['object_id'], $row['key'] ) );
		}
		if ( null === $new_raw ) {
			return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->commentmeta} SET meta_value = NULL WHERE meta_id = %d AND comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)", $row['meta_id'], $row['object_id'], $row['key'], $row['raw_value'] ) );
		}
		return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->commentmeta} SET meta_value = %s WHERE meta_id = %d AND comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)", $new_raw, $row['meta_id'], $row['object_id'], $row['key'], $row['raw_value'] ) );
	}

	private function delete_user_row( $wpdb, array $row ) {
		if ( null === $row['raw_value'] ) {
			return $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE umeta_id = %d AND user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL", $row['meta_id'], $row['object_id'], $row['key'] ) );
		}
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE umeta_id = %d AND user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)", $row['meta_id'], $row['object_id'], $row['key'], $row['raw_value'] ) );
	}

	private function delete_comment_row( $wpdb, array $row ) {
		if ( null === $row['raw_value'] ) {
			return $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE meta_id = %d AND comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL", $row['meta_id'], $row['object_id'], $row['key'] ) );
		}
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE meta_id = %d AND comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)", $row['meta_id'], $row['object_id'], $row['key'], $row['raw_value'] ) );
	}
}
