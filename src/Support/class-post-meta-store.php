<?php
/**
 * Physical post metadata storage helpers for exact-row mutations.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Keeps Advanced Metadata mutations bound to one physical postmeta row.
 *
 * This class is intentionally not a generic database abstraction. It only operates on
 * fixed wp_postmeta columns after the Ability layer has authorized one exact post/key.
 */
final class Post_Meta_Store {
	/**
	 * Returns physical postmeta rows, bypassing get_post_metadata virtual read filters.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $key     Optional exact unslashed key.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function rows( $post_id, $key = null ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return new WP_Error( 'post_meta_physical_state_unavailable', __( 'Physical post metadata state could not be established safely.', 'wp-ai-bridge' ) );
		}

		if ( $this->is_test_mode() ) {
			return $this->test_rows( $post_id, $key );
		}

		if ( ! function_exists( 'has_meta' ) && defined( 'ABSPATH' ) ) {
			$admin_post_file = ABSPATH . 'wp-admin/includes/post.php';
			if ( is_readable( $admin_post_file ) ) {
				require_once $admin_post_file;
			}
		}

		if ( ! function_exists( 'has_meta' ) ) {
			return new WP_Error( 'post_meta_physical_state_unavailable', __( 'Physical post metadata state could not be established safely.', 'wp-ai-bridge' ) );
		}

		$metadata = has_meta( $post_id );
		if ( ! is_array( $metadata ) ) {
			return new WP_Error( 'post_meta_physical_state_unavailable', __( 'Physical post metadata state could not be established safely.', 'wp-ai-bridge' ) );
		}

		$rows = array();
		foreach ( $metadata as $meta ) {
			if ( is_object( $meta ) ) {
				$meta = get_object_vars( $meta );
			}
			if ( ! is_array( $meta ) || ! isset( $meta['meta_id'], $meta['post_id'], $meta['meta_key'] ) || ! array_key_exists( 'meta_value', $meta ) ) {
				continue;
			}
			$meta_key = (string) $meta['meta_key'];
			if ( null !== $key && $meta_key !== (string) $key ) {
				continue;
			}
			$raw_value = $meta['meta_value'];
			if ( ! is_scalar( $raw_value ) && null !== $raw_value ) {
				return new WP_Error( 'post_meta_physical_state_unavailable', __( 'Physical post metadata state could not be established safely.', 'wp-ai-bridge' ) );
			}
			if ( null !== $raw_value ) {
				$raw_value = (string) $raw_value;
			}
			$rows[] = array(
				'meta_id'   => (int) $meta['meta_id'],
				'post_id'   => (int) $meta['post_id'],
				'key'       => $meta_key,
				'raw_value' => $raw_value,
				'value'     => maybe_unserialize( $raw_value ),
			);
		}

		usort(
			$rows,
			static function ( $left, $right ) {
				return (int) $left['meta_id'] <=> (int) $right['meta_id'];
			}
		);

		return $rows;
	}

	/**
	 * Prepares a JSON-decoded value exactly as WordPress stores post metadata.
	 *
	 * Existing-row direct persistence needs to run the same sanitizer Core would run.
	 * Creation does not call this method because add_post_meta() owns its one sanitizer pass.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Exact unslashed key.
	 * @param mixed  $value   Canonical unslashed value.
	 * @return array{value:mixed,raw_value:string|null}
	 */
	public function prepare_value( $post_id, $key, $value ) {
		$subtype = function_exists( 'get_object_subtype' ) ? get_object_subtype( 'post', (int) $post_id ) : '';
		if ( function_exists( 'sanitize_meta' ) ) {
			$value = sanitize_meta( (string) $key, $value, 'post', $subtype );
		}

		return $this->stored_value( $value );
	}

	/**
	 * Creates through Core while capturing the exact once-sanitized value Core attempted to store.
	 *
	 * The capture filter never changes Core's decision or value. It only observes the already
	 * sanitized value after Core has unslashed and sanitized it, avoiding a second sanitizer pass.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Exact unslashed key.
	 * @param mixed  $value   Canonical unslashed input value.
	 * @return array{result:mixed,expected_row:array<string,mixed>}|WP_Error
	 */
	public function create_unique_row( $post_id, $key, $value ) {
		$post_id = (int) $post_id;
		$key     = (string) $key;

		if ( $this->is_test_mode() ) {
			$result   = add_post_meta( $post_id, $key, $value, true );
			$prepared = $this->stored_value( $value );
			return array(
				'result'       => $result,
				'expected_row' => array(
					'meta_id'   => is_int( $result ) ? $result : 0,
					'post_id'   => $post_id,
					'key'       => $key,
					'raw_value' => $prepared['raw_value'],
					'value'     => $prepared['value'],
				),
			);
		}

		$captured       = false;
		$sanitized      = null;
		$capture_filter = static function ( $check, $object_id, $meta_key, $meta_value, $unique ) use ( $post_id, $key, &$captured, &$sanitized ) {
			if ( (int) $object_id === $post_id && (string) $meta_key === $key && true === (bool) $unique ) {
				$captured  = true;
				$sanitized = $meta_value;
			}
			return $check;
		};

		add_filter( 'add_post_metadata', $capture_filter, PHP_INT_MAX, 5 );
		try {
			$result = add_post_meta( $post_id, wp_slash( $key ), wp_slash( $value ), true );
		} finally {
			remove_filter( 'add_post_metadata', $capture_filter, PHP_INT_MAX );
		}

		if ( ! $captured ) {
			return new WP_Error( 'post_meta_physical_state_unavailable', __( 'Physical post metadata state could not be established safely.', 'wp-ai-bridge' ) );
		}

		$prepared = $this->stored_value( $sanitized );
		return array(
			'result'       => $result,
			'expected_row' => array(
				'meta_id'   => is_int( $result ) ? $result : 0,
				'post_id'   => $post_id,
				'key'       => $key,
				'raw_value' => $prepared['raw_value'],
				'value'     => $prepared['value'],
			),
		);
	}

	/**
	 * Replaces one exact physical row with byte-exact compare-and-swap semantics.
	 *
	 * Verification and any compensation stay inside this persistence boundary so a
	 * later unrelated write after the verified linearization point is simply a new state.
	 *
	 * @param int                 $post_id  Post ID.
	 * @param string              $key      Exact unslashed key.
	 * @param array<string,mixed> $row      Previously inspected physical row.
	 * @param array<string,mixed> $prepared Sanitized value and exact raw storage representation.
	 * @return array<string,mixed>|WP_Error Verified physical row after the update.
	 */
	public function replace_row( $post_id, $key, array $row, array $prepared ) {
		if ( $this->is_test_mode() ) {
			$result = update_post_meta( (int) $post_id, (string) $key, $prepared['value'], $row['value'] );
			if ( false === $result ) {
				return new WP_Error( 'post_meta_update_failed', __( 'WordPress could not update the requested metadata key.', 'wp-ai-bridge' ) );
			}
			$after = $this->rows( $post_id, $key );
			return isset( $after[0] ) ? $after[0] : new WP_Error( 'post_meta_update_failed', __( 'WordPress could not update the requested metadata key.', 'wp-ai-bridge' ) );
		}

		$current = $this->rows( $post_id, $key );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-ai-bridge' ) );
		}

		$short_circuit = apply_filters( 'update_post_metadata', null, (int) $post_id, (string) $key, $prepared['value'], $row['value'] );
		if ( null !== $short_circuit ) {
			return new WP_Error( 'post_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata value atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-ai-bridge' ) );
		}

		$meta_id = (int) $row['meta_id'];
		do_action( 'update_post_meta', $meta_id, (int) $post_id, (string) $key, $prepared['value'] );
		do_action( 'update_postmeta', $meta_id, (int) $post_id, (string) $key, $prepared['raw_value'] );

		$result = $this->exact_update_raw_row( $meta_id, (int) $post_id, (string) $key, $row['raw_value'], $prepared['raw_value'] );
		if ( false === $result ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
			return new WP_Error( 'post_meta_update_failed', __( 'WordPress could not update the requested metadata key.', 'wp-ai-bridge' ) );
		}
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
			return new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-ai-bridge' ) );
		}

		wp_cache_delete( (int) $post_id, 'post_meta' );
		do_action( 'updated_post_meta', $meta_id, (int) $post_id, (string) $key, $prepared['value'] );
		do_action( 'updated_postmeta', $meta_id, (int) $post_id, (string) $key, $prepared['raw_value'] );

		$after = $this->rows( $post_id, $key );
		if ( is_wp_error( $after ) ) {
			if ( ! $this->restore_updated_row( $row, $prepared['raw_value'] ) ) {
				return new WP_Error( 'post_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
			}
			return $after;
		}
		$expected_row              = $row;
		$expected_row['raw_value'] = $prepared['raw_value'];
		if ( 1 !== count( $after ) || ! $this->row_matches( $after[0], $expected_row ) ) {
			if ( ! $this->restore_updated_row( $row, $prepared['raw_value'] ) ) {
				return new WP_Error( 'post_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
			}
			return new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-ai-bridge' ) );
		}

		return $after[0];
	}

	/**
	 * Deletes one exact physical row with byte-exact compare-and-swap semantics.
	 *
	 * Verification and restoration stay inside the same persistence boundary.
	 *
	 * @param int                 $post_id Post ID.
	 * @param string              $key     Exact unslashed key.
	 * @param array<string,mixed> $row     Previously inspected physical row.
	 * @return true|WP_Error
	 */
	public function delete_row( $post_id, $key, array $row ) {
		if ( $this->is_test_mode() ) {
			return delete_post_meta( (int) $post_id, (string) $key, $row['value'] ) ? true : new WP_Error( 'post_meta_delete_failed', __( 'WordPress could not delete the requested metadata key.', 'wp-ai-bridge' ) );
		}

		$current = $this->rows( $post_id, $key );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-ai-bridge' ) );
		}

		$short_circuit = apply_filters( 'delete_post_metadata', null, (int) $post_id, (string) $key, $row['value'], false );
		if ( null !== $short_circuit ) {
			return new WP_Error( 'post_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata value atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-ai-bridge' ) );
		}

		$meta_id  = (int) $row['meta_id'];
		$meta_ids = array( $meta_id );
		do_action( 'delete_post_meta', $meta_ids, (int) $post_id, (string) $key, $row['value'] );
		do_action( 'delete_postmeta', $meta_ids );

		$result = $this->exact_delete_raw_row( $meta_id, (int) $post_id, (string) $key, $row['raw_value'] );
		if ( false === $result ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
			return new WP_Error( 'post_meta_delete_failed', __( 'WordPress could not delete the requested metadata key.', 'wp-ai-bridge' ) );
		}
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
			return new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-ai-bridge' ) );
		}

		wp_cache_delete( (int) $post_id, 'post_meta' );
		do_action( 'deleted_post_meta', $meta_ids, (int) $post_id, (string) $key, $row['value'] );
		do_action( 'deleted_postmeta', $meta_ids );

		$after = $this->rows( $post_id, $key );
		if ( is_wp_error( $after ) ) {
			if ( ! $this->restore_deleted_row( $row ) ) {
				return new WP_Error( 'post_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
			}
			return $after;
		}
		if ( ! empty( $after ) ) {
			if ( ! $this->restore_deleted_row( $row ) ) {
				return new WP_Error( 'post_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
			}
			return new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-ai-bridge' ) );
		}

		return true;
	}

	/**
	 * Restores one deleted row and emits the matching add lifecycle when compensation succeeds.
	 *
	 * @param array<string,mixed> $row Previously inspected physical row.
	 * @return bool
	 */
	public function restore_deleted_row( array $row ) {
		if ( $this->is_test_mode() ) {
			return false !== add_post_meta( (int) $row['post_id'], (string) $row['key'], $row['value'], false );
		}

		global $wpdb;
		do_action( 'add_post_meta', (int) $row['post_id'], (string) $row['key'], $row['value'] );
		$result = $wpdb->insert(
			$wpdb->postmeta,
			array(
				'meta_id'    => (int) $row['meta_id'],
				'post_id'    => (int) $row['post_id'],
				'meta_key'   => (string) $row['key'],
				'meta_value' => $row['raw_value'],
			),
			array( '%d', '%d', '%s', '%s' )
		);
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $row['post_id'], 'post_meta' );
			return false;
		}
		wp_cache_delete( (int) $row['post_id'], 'post_meta' );
		do_action( 'added_post_meta', (int) $row['meta_id'], (int) $row['post_id'], (string) $row['key'], $row['value'] );
		return true;
	}

	/**
	 * Rolls back only the row changed by this invocation and emits an update lifecycle
	 * that reflects the restored final value. Short-circuit filters do not veto integrity
	 * compensation after the Bridge has already committed its first exact mutation.
	 *
	 * @param array<string,mixed> $row              Original physical row.
	 * @param string|null         $expected_new_raw Raw value written by this invocation.
	 * @return bool
	 */
	public function restore_updated_row( array $row, $expected_new_raw ) {
		if ( $this->is_test_mode() ) {
			return false !== update_post_meta( (int) $row['post_id'], (string) $row['key'], $row['value'] );
		}

		$meta_id = (int) $row['meta_id'];
		do_action( 'update_post_meta', $meta_id, (int) $row['post_id'], (string) $row['key'], $row['value'] );
		do_action( 'update_postmeta', $meta_id, (int) $row['post_id'], (string) $row['key'], $row['raw_value'] );

		$result = $this->exact_update_raw_row( $meta_id, (int) $row['post_id'], (string) $row['key'], $expected_new_raw, $row['raw_value'] );
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $row['post_id'], 'post_meta' );
			return false;
		}
		wp_cache_delete( (int) $row['post_id'], 'post_meta' );
		do_action( 'updated_post_meta', $meta_id, (int) $row['post_id'], (string) $row['key'], $row['value'] );
		do_action( 'updated_postmeta', $meta_id, (int) $row['post_id'], (string) $row['key'], $row['raw_value'] );
		return true;
	}

	/**
	 * Removes only an unchanged row created by this invocation during create-race cleanup.
	 *
	 * @param array<string,mixed> $row Expected physical row created by this invocation.
	 * @return true|WP_Error
	 */
	public function cleanup_created_row( array $row ) {
		if ( $this->is_test_mode() ) {
			return delete_post_meta( (int) $row['post_id'], (string) $row['key'], $row['value'] )
				? true
				: new WP_Error( 'post_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
		}

		$meta_id  = (int) $row['meta_id'];
		$meta_ids = array( $meta_id );
		do_action( 'delete_post_meta', $meta_ids, (int) $row['post_id'], (string) $row['key'], $row['value'] );
		do_action( 'delete_postmeta', $meta_ids );

		$result = $this->exact_delete_raw_row( $meta_id, (int) $row['post_id'], (string) $row['key'], $row['raw_value'] );
		if ( false === $result ) {
			wp_cache_delete( (int) $row['post_id'], 'post_meta' );
			return new WP_Error( 'post_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
		}
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $row['post_id'], 'post_meta' );
			return new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-ai-bridge' ) );
		}

		wp_cache_delete( (int) $row['post_id'], 'post_meta' );
		do_action( 'deleted_post_meta', $meta_ids, (int) $row['post_id'], (string) $row['key'], $row['value'] );
		do_action( 'deleted_postmeta', $meta_ids );
		return true;
	}

	/**
	 * Checks whether two physical row snapshots are identical.
	 *
	 * @param array<string,mixed> $left  Row.
	 * @param array<string,mixed> $right Row.
	 * @return bool
	 */
	public function row_matches( array $left, array $right ) {
		return array_key_exists( 'raw_value', $left )
			&& array_key_exists( 'raw_value', $right )
			&& (int) $left['meta_id'] === (int) $right['meta_id']
			&& (int) $left['post_id'] === (int) $right['post_id']
			&& (string) $left['key'] === (string) $right['key']
			&& $left['raw_value'] === $right['raw_value'];
	}

	/**
	 * Converts an already-sanitized WordPress metadata value to its exact DB representation.
	 *
	 * @param mixed $value Sanitized metadata value.
	 * @return array{value:mixed,raw_value:string|null}
	 */
	private function stored_value( $value ) {
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

	/**
	 * Performs one fixed-schema byte-exact row update.
	 *
	 * Every branch contains a complete literal query template so neither the caller nor
	 * an internal string fragment can select SQL structure. SQL NULL is represented by
	 * the two branches that use SET ... NULL or ... IS NULL rather than a text value.
	 *
	 * @param int         $meta_id      Physical meta ID.
	 * @param int         $post_id      Post ID.
	 * @param string      $key          Exact key.
	 * @param string|null $expected_raw Expected old raw storage.
	 * @param string|null $new_raw      New raw storage.
	 * @return int|false
	 */
	private function exact_update_raw_row( $meta_id, $post_id, $key, $expected_raw, $new_raw ) {
		global $wpdb;

		if ( null === $expected_raw && null === $new_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_postmeta CAS.
			return $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET meta_value = NULL WHERE meta_id = %d AND post_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL',
					$wpdb->postmeta,
					(int) $meta_id,
					(int) $post_id,
					(string) $key
				)
			);
		}

		if ( null === $expected_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_postmeta CAS.
			return $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET meta_value = %s WHERE meta_id = %d AND post_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL',
					$wpdb->postmeta,
					$new_raw,
					(int) $meta_id,
					(int) $post_id,
					(string) $key
				)
			);
		}

		if ( null === $new_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_postmeta CAS.
			return $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET meta_value = NULL WHERE meta_id = %d AND post_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)',
					$wpdb->postmeta,
					(int) $meta_id,
					(int) $post_id,
					(string) $key,
					$expected_raw
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_postmeta CAS.
		return $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET meta_value = %s WHERE meta_id = %d AND post_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)',
				$wpdb->postmeta,
				$new_raw,
				(int) $meta_id,
				(int) $post_id,
				(string) $key,
				$expected_raw
			)
		);
	}

	/**
	 * Performs one fixed-schema byte-exact row delete.
	 *
	 * @param int         $meta_id      Physical meta ID.
	 * @param int         $post_id      Post ID.
	 * @param string      $key          Exact key.
	 * @param string|null $expected_raw Expected raw storage.
	 * @return int|false
	 */
	private function exact_delete_raw_row( $meta_id, $post_id, $key, $expected_raw ) {
		global $wpdb;

		if ( null === $expected_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_postmeta CAS.
			return $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE meta_id = %d AND post_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL',
					$wpdb->postmeta,
					(int) $meta_id,
					(int) $post_id,
					(string) $key
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_postmeta CAS.
		return $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE meta_id = %d AND post_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)',
				$wpdb->postmeta,
				(int) $meta_id,
				(int) $post_id,
				(string) $key,
				$expected_raw
			)
		);
	}

	/** @return bool */
	private function is_test_mode() {
		return defined( 'WP_AI_BRIDGE_TEST_MODE' ) && WP_AI_BRIDGE_TEST_MODE;
	}

	/**
	 * Dependency-free harness adapter. Never used in WordPress runtime.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $key     Optional key.
	 * @return array<int,array<string,mixed>>
	 */
	private function test_rows( $post_id, $key ) {
		$all  = get_post_meta( (int) $post_id );
		$all  = is_array( $all ) ? $all : array();
		$rows = array();
		foreach ( $all as $meta_key => $values ) {
			if ( null !== $key && (string) $meta_key !== (string) $key ) {
				continue;
			}
			foreach ( (array) $values as $index => $value ) {
				$stored = $this->stored_value( $value );
				$rows[] = array(
					'meta_id'   => abs( crc32( $post_id . "\0" . $meta_key . "\0" . $index ) ) + 1,
					'post_id'   => (int) $post_id,
					'key'       => (string) $meta_key,
					'raw_value' => $stored['raw_value'],
					'value'     => $value,
				);
			}
		}
		return $rows;
	}
}
