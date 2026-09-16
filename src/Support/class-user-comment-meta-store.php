<?php
/**
 * Fixed-purpose user/comment metadata storage helpers.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

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
	 * Returns bounded physical rows for one exact key or a bounded value-free key list.
	 *
	 * @param string      $type      User or comment.
	 * @param int         $object_id Exact object ID.
	 * @param string|null $key       Exact key; null returns a bounded key list.
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
					'SELECT umeta_id AS meta_id, user_id AS object_id, meta_key, CASE WHEN OCTET_LENGTH(meta_value) <= 1048576 THEN meta_value ELSE NULL END AS meta_value, OCTET_LENGTH(meta_value) AS value_bytes FROM %i WHERE user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY umeta_id LIMIT 2',
					$wpdb->usermeta,
					$object_id,
					$key
				);
			} else {
				$prepared = $wpdb->prepare(
					'SELECT meta_id, comment_id AS object_id, meta_key, CASE WHEN OCTET_LENGTH(meta_value) <= 1048576 THEN meta_value ELSE NULL END AS meta_value, OCTET_LENGTH(meta_value) AS value_bytes FROM %i WHERE comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id LIMIT 2',
					$wpdb->commentmeta,
					$object_id,
					$key
				);
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query shape and every value are fixed/prepared above.
			$raw_rows = $wpdb->get_results( $prepared, ARRAY_A );
		} else {
			$limit = max( 1, min( 200, (int) $limit ) );
			if ( 'user' === $type ) {
				$prepared = $wpdb->prepare(
					'SELECT umeta_id AS meta_id, user_id AS object_id, meta_key, NULL AS meta_value, NULL AS value_bytes FROM %i WHERE user_id = %d AND meta_key IS NOT NULL ORDER BY CAST(meta_key AS BINARY), umeta_id LIMIT %d',
					$wpdb->usermeta,
					$object_id,
					$limit
				);
			} else {
				$prepared = $wpdb->prepare(
					'SELECT meta_id, comment_id AS object_id, meta_key, NULL AS meta_value, NULL AS value_bytes FROM %i WHERE comment_id = %d AND meta_key IS NOT NULL ORDER BY CAST(meta_key AS BINARY), meta_id LIMIT %d',
					$wpdb->commentmeta,
					$object_id,
					$limit
				);
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query shape and every value are fixed/prepared above.
			$raw_rows = $wpdb->get_results( $prepared, ARRAY_A );
		}
		if ( ! is_array( $raw_rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->state_error();
		}
		$rows = array();
		foreach ( $raw_rows as $row ) {
			if ( ! isset( $row['meta_id'], $row['object_id'], $row['meta_key'] ) || ! array_key_exists( 'meta_value', $row ) || ! array_key_exists( 'value_bytes', $row ) ) {
				return $this->state_error();
			}
			if ( null !== $row['value_bytes'] && (int) $row['value_bytes'] > self::MAX_VALUE_BYTES ) {
				return $this->value_too_large_error();
			}
			$raw_value = $row['meta_value'];
			if ( null !== $raw_value && ! is_scalar( $raw_value ) ) {
				return $this->state_error();
			}
			if ( null !== $raw_value ) {
				$raw_value = (string) $raw_value;
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
	 * @param string $type      User or comment.
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
	 * Creates one unique row through Core while capturing Core's one-pass sanitized value.
	 *
	 * @param string $type      User or comment.
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
		$captured  = array();
		$capture   = function ( $meta_id, $candidate_id, $meta_key, $meta_value ) use ( $object_id, $key, &$captured ) {
			if ( (int) $candidate_id !== $object_id || (string) $meta_key !== $key ) {
				return;
			}
			$prepared                   = $this->stored_value_unbounded( $meta_value );
			$captured[ (int) $meta_id ] = array(
				'meta_id'   => (int) $meta_id,
				'object_id' => $object_id,
				'key'       => $key,
				'raw_value' => $prepared['raw_value'],
				'value'     => $prepared['value'],
			);
		};
		$hook      = 'added_' . $type . '_meta';
		add_action( $hook, $capture, PHP_INT_MIN, 4 );
		try {
			$result = add_metadata( $type, $object_id, wp_slash( $key ), wp_slash( $value ), true );
		} finally {
			remove_action( $hook, $capture, PHP_INT_MIN );
		}
		if ( ! is_int( $result ) || $result < 1 || ! array_key_exists( $result, $captured ) ) {
			return $this->state_error();
		}
		$expected          = $captured[ $result ];
		$post_commit_error = null !== $expected['raw_value'] && strlen( $expected['raw_value'] ) > self::MAX_VALUE_BYTES
			? $this->value_too_large_error()
			: null;
		return array(
			'result'            => $result,
			'expected_row'      => $expected,
			'post_commit_error' => $post_commit_error,
		);
	}

	/**
	 * Replaces one exact row with byte-exact CAS semantics while preserving Core hooks.
	 *
	 * @param string              $type      User or comment.
	 * @param int                 $object_id Object ID.
	 * @param string              $key       Exact key.
	 * @param array<string,mixed> $row       Inspected row.
	 * @param array<string,mixed> $prepared  Sanitized value storage representation.
	 * @return array<string,mixed>|WP_Error
	 */
	public function replace_row( $type, $object_id, $key, array $row, array $prepared ) {
		$type = $this->type( $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$current = $this->rows( $type, $object_id, $key );
		if ( is_wp_error( $current ) || 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return $this->stale_error();
		}
		$short_circuit = apply_filters( "update_{$type}_metadata", null, (int) $object_id, (string) $key, $prepared['value'], $row['value'] );
		if ( null !== $short_circuit ) {
			return new WP_Error( 'object_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata row atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-ai-bridge' ) );
		}
		$this->before_update( $type, $row, $prepared['value'] );
		$result = 'user' === $type
			? $this->update_user_row( $row, $prepared['raw_value'] )
			: $this->update_comment_row( $row, $prepared['raw_value'] );
		wp_cache_delete( (int) $object_id, $type . '_meta' );
		if ( 1 !== $result ) {
			return $this->stale_error();
		}
		$this->after_update( $type, $row, $prepared['value'] );
		$after                 = $this->rows( $type, $object_id, $key );
		$expected              = $row;
		$expected['raw_value'] = $prepared['raw_value'];
		$expected['value']     = $prepared['value'];
		if ( is_wp_error( $after ) || 1 !== count( $after ) || ! $this->row_matches( $after[0], $expected ) ) {
			if ( ! $this->restore_updated_row( $type, $row, $prepared['raw_value'] ) ) {
				return $this->compensation_error();
			}
			return $this->stale_error();
		}
		return $after[0];
	}

	/**
	 * Deletes one exact row with byte-exact CAS semantics while preserving Core hooks.
	 *
	 * @param string              $type      User or comment.
	 * @param int                 $object_id Object ID.
	 * @param string              $key       Exact key.
	 * @param array<string,mixed> $row       Inspected row.
	 * @return true|WP_Error
	 */
	public function delete_row( $type, $object_id, $key, array $row ) {
		$type = $this->type( $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$current = $this->rows( $type, $object_id, $key );
		if ( is_wp_error( $current ) || 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return $this->stale_error();
		}
		$short_circuit = apply_filters( "delete_{$type}_metadata", null, (int) $object_id, (string) $key, $row['value'], false );
		if ( null !== $short_circuit ) {
			return new WP_Error( 'object_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata row atomically. The generic Bridge refuses the mutation to avoid a stale delete.', 'wp-ai-bridge' ) );
		}
		$this->before_delete( $type, $row );
		$result = 'user' === $type ? $this->delete_user_row( $row ) : $this->delete_comment_row( $row );
		wp_cache_delete( (int) $object_id, $type . '_meta' );
		if ( 1 !== $result ) {
			return $this->stale_error();
		}
		$this->after_delete( $type, $row );
		$after = $this->rows( $type, $object_id, $key );
		if ( is_wp_error( $after ) || ! empty( $after ) ) {
			if ( ! $this->restore_deleted_row( $type, $row ) ) {
				return $this->compensation_error();
			}
			return $this->stale_error();
		}
		return true;
	}

	/**
	 * Removes only the exact unchanged row originally created by this store.
	 *
	 * @param string              $type     User or comment.
	 * @param array<string,mixed> $expected Exact row captured from Core's added-meta lifecycle.
	 * @return true|WP_Error
	 */
	public function cleanup_created_row( $type, array $expected ) {
		$type = $this->type( $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$this->before_delete( $type, $expected );
		$result = 'user' === $type ? $this->delete_user_row( $expected ) : $this->delete_comment_row( $expected );
		wp_cache_delete( (int) $expected['object_id'], $type . '_meta' );
		if ( false === $result ) {
			return new WP_Error( 'object_meta_cleanup_failed', __( 'The Bridge could not remove its exact raced metadata row safely.', 'wp-ai-bridge' ) );
		}
		if ( 1 !== $result ) {
			return $this->stale_error();
		}
		$this->after_delete( $type, $expected );
		return true;
	}

	/** Checks whether two physical row snapshots are identical. */
	public function row_matches( array $left, array $right ) {
		return (int) $left['meta_id'] === (int) $right['meta_id']
			&& (int) $left['object_id'] === (int) $right['object_id']
			&& (string) $left['key'] === (string) $right['key']
			&& $left['raw_value'] === $right['raw_value'];
	}

	/** Restores only the row changed by this invocation after verification detects drift. */
	private function restore_updated_row( $type, array $row, $expected_new_raw ) {
		$this->before_update( $type, $row, $row['value'] );
		$restore_row              = $row;
		$restore_row['raw_value'] = $expected_new_raw;
		$result                   = 'user' === $type
			? $this->update_user_row( $restore_row, $row['raw_value'] )
			: $this->update_comment_row( $restore_row, $row['raw_value'] );
		wp_cache_delete( (int) $row['object_id'], $type . '_meta' );
		if ( 1 !== $result ) {
			return false;
		}
		$this->after_update( $type, $row, $row['value'] );
		return true;
	}

	/** Restores only the row deleted by this invocation after verification detects drift. */
	private function restore_deleted_row( $type, array $row ) {
		do_action( "add_{$type}_meta", (int) $row['object_id'], (string) $row['key'], $row['value'] );
		$result = 'user' === $type ? $this->insert_user_row( $row ) : $this->insert_comment_row( $row );
		wp_cache_delete( (int) $row['object_id'], $type . '_meta' );
		if ( 1 !== $result ) {
			return false;
		}
		do_action( "added_{$type}_meta", (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'], $row['value'] );
		return true;
	}

	/** Emits the standard dynamic metadata update lifecycle hook. */
	private function before_update( $type, array $row, $value ) {
		do_action( "update_{$type}_meta", (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'], $value );
	}

	/** Emits the standard dynamic metadata updated lifecycle hook. */
	private function after_update( $type, array $row, $value ) {
		do_action( "updated_{$type}_meta", (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'], $value );
	}

	/** Emits the standard dynamic metadata delete lifecycle hook. */
	private function before_delete( $type, array $row ) {
		do_action( "delete_{$type}_meta", array( (int) $row['meta_id'] ), (int) $row['object_id'], (string) $row['key'], $row['value'] );
	}

	/** Emits the standard dynamic metadata deleted lifecycle hook. */
	private function after_delete( $type, array $row ) {
		do_action( "deleted_{$type}_meta", array( (int) $row['meta_id'] ), (int) $row['object_id'], (string) $row['key'], $row['value'] );
	}

	/** Converts an already-sanitized WordPress metadata value to its bounded exact DB representation. */
	private function stored_value( $value ) {
		$stored = $this->stored_value_unbounded( $value );
		if ( null !== $stored['raw_value'] && strlen( $stored['raw_value'] ) > self::MAX_VALUE_BYTES ) {
			return $this->value_too_large_error();
		}
		return $stored;
	}

	/** Converts a Core-sanitized value to exact storage bytes for row-owned compensation. */
	private function stored_value_unbounded( $value ) {
		$raw_value = maybe_serialize( $value );
		if ( null === $raw_value ) {
			return array(
				'value'     => $value,
				'raw_value' => null,
			);
		}
		if ( false === $raw_value ) {
			$raw_value = '';
		} elseif ( true === $raw_value ) {
			$raw_value = '1';
		} elseif ( ! is_string( $raw_value ) ) {
			$raw_value = (string) $raw_value;
		}
		return array(
			'value'     => $value,
			'raw_value' => $raw_value,
		);
	}

	/** @return string|WP_Error */
	private function type( $type ) {
		$type = (string) $type;
		return in_array( $type, array( 'user', 'comment' ), true )
			? $type
			: new WP_Error( 'unsupported_object_meta_type', __( 'The requested metadata object type is not supported.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function state_error() {
		return new WP_Error( 'object_meta_physical_state_unavailable', __( 'Physical metadata state could not be established safely.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function value_too_large_error() {
		return new WP_Error( 'object_meta_value_too_large', __( 'This metadata value is too large for the bounded generic metadata contract.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function stale_error() {
		return new WP_Error( 'stale_object_meta_conflict', __( 'Metadata changed after it was read. Refresh the metadata state before mutating it.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function compensation_error() {
		return new WP_Error( 'object_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
	}

	/** Performs one fixed-schema usermeta row update. */
	private function update_user_row( array $row, $new_raw ) {
		global $wpdb;
		if ( null === $row['raw_value'] ) {
			if ( null === $new_raw ) {
				return 0;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed byte-exact usermeta CAS.
			return $wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_value = %s WHERE umeta_id = %d AND user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL', $wpdb->usermeta, $new_raw, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'] ) );
		}
		if ( null === $new_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed byte-exact usermeta CAS.
			return $wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_value = NULL WHERE umeta_id = %d AND user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->usermeta, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'], $row['raw_value'] ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed byte-exact usermeta CAS.
		return $wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_value = %s WHERE umeta_id = %d AND user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->usermeta, $new_raw, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'], $row['raw_value'] ) );
	}

	/** Performs one fixed-schema commentmeta row update. */
	private function update_comment_row( array $row, $new_raw ) {
		global $wpdb;
		if ( null === $row['raw_value'] ) {
			if ( null === $new_raw ) {
				return 0;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed byte-exact commentmeta CAS.
			return $wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_value = %s WHERE meta_id = %d AND comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL', $wpdb->commentmeta, $new_raw, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'] ) );
		}
		if ( null === $new_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed byte-exact commentmeta CAS.
			return $wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_value = NULL WHERE meta_id = %d AND comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->commentmeta, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'], $row['raw_value'] ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed byte-exact commentmeta CAS.
		return $wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_value = %s WHERE meta_id = %d AND comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->commentmeta, $new_raw, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'], $row['raw_value'] ) );
	}

	/** @return int|false */
	private function delete_user_row( array $row ) {
		global $wpdb;
		if ( null === $row['raw_value'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed byte-exact usermeta CAS.
			return $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE umeta_id = %d AND user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL', $wpdb->usermeta, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'] ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed byte-exact usermeta CAS.
		return $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE umeta_id = %d AND user_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->usermeta, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'], $row['raw_value'] ) );
	}

	/** @return int|false */
	private function delete_comment_row( array $row ) {
		global $wpdb;
		if ( null === $row['raw_value'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed byte-exact commentmeta CAS.
			return $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_id = %d AND comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL', $wpdb->commentmeta, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'] ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed byte-exact commentmeta CAS.
		return $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_id = %d AND comment_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->commentmeta, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'], $row['raw_value'] ) );
	}

	/** @return int|false */
	private function insert_user_row( array $row ) {
		global $wpdb;
		if ( null === $row['raw_value'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact compensation of this invocation's usermeta row.
			return $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (umeta_id, user_id, meta_key, meta_value) VALUES (%d, %d, %s, NULL)', $wpdb->usermeta, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'] ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact compensation of this invocation's usermeta row.
		return $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (umeta_id, user_id, meta_key, meta_value) VALUES (%d, %d, %s, %s)', $wpdb->usermeta, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'], $row['raw_value'] ) );
	}

	/** @return int|false */
	private function insert_comment_row( array $row ) {
		global $wpdb;
		if ( null === $row['raw_value'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact compensation of this invocation's commentmeta row.
			return $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (meta_id, comment_id, meta_key, meta_value) VALUES (%d, %d, %s, NULL)', $wpdb->commentmeta, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'] ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact compensation of this invocation's commentmeta row.
		return $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (meta_id, comment_id, meta_key, meta_value) VALUES (%d, %d, %s, %s)', $wpdb->commentmeta, (int) $row['meta_id'], (int) $row['object_id'], (string) $row['key'], $row['raw_value'] ) );
	}
}
