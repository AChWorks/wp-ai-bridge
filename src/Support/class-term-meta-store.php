<?php
/**
 * Physical term metadata storage helpers for exact-row mutations.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Support;

use WP_Error;

/**
 * Keeps Advanced Metadata mutations bound to one physical termmeta row.
 *
 * This class is intentionally not a generic database abstraction. It only operates on
 * fixed wp_termmeta columns after the Ability layer has authorized one exact term/taxonomy/key.
 * Native terms/term_taxonomy joins only prove that same live identity at persistence.
 */
final class Term_Meta_Store {
	const MAX_VALUE_BYTES = 1048576;
	/**
	 * Returns at most two rows: enough to distinguish absence, singleton, and ambiguity.
	 * A bounded CASE prevents an oversized stored value from being loaded into PHP.
	 *
	 * @param int    $term_id Canonical term ID.
	 * @param string $key     Exact key.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function rows( $term_id, $key ) {
		global $wpdb;
		if ( (int) $term_id < 1 || ! is_string( $key ) || '' === $key ) {
			return $this->state_error();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed, uncached physical termmeta identity; at most two bounded rows.
		$metadata = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_id, term_id, meta_key, CASE WHEN OCTET_LENGTH(meta_value) <= 1048576 THEN meta_value ELSE NULL END AS meta_value, OCTET_LENGTH(meta_value) AS value_bytes FROM %i WHERE term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id LIMIT 2',
				$wpdb->termmeta,
				(int) $term_id,
				$key
			),
			ARRAY_A
		);
		if ( ! is_array( $metadata ) || '' !== $wpdb->last_error ) {
			return $this->state_error();
		}
		$rows = array();
		foreach ( $metadata as $meta ) {
			if ( ! isset( $meta['meta_id'], $meta['term_id'], $meta['meta_key'] ) || ! array_key_exists( 'meta_value', $meta ) || ! array_key_exists( 'value_bytes', $meta )
				|| (int) $meta['term_id'] !== (int) $term_id || $meta['meta_key'] !== $key ) {
				return $this->state_error();
			}
			if ( (int) $meta['value_bytes'] > self::MAX_VALUE_BYTES ) {
				return new WP_Error( 'term_meta_value_too_large', __( 'Term metadata values are limited to 1 MiB per key.', 'wp-ai-bridge' ) );
			}
			$raw   = null === $meta['meta_value'] ? null : (string) $meta['meta_value'];
			$value = $raw;
			$loss  = false;
			if ( is_serialized( $raw ) ) {
				$offset = 0;
				// allowed_classes=false does not prevent enum autoloading. Prove that the
				// complete stream contains only scalar/array tokens before native decoding.
				if ( ! $this->safe_serialized_fragment( $raw, $offset ) || strlen( $raw ) !== $offset ) {
					$value = null;
					$loss  = true;
				} else {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Complete primitive-only stream preflight; classes disabled and depth limited as defense in depth.
					$value = @unserialize(
						$raw,
						array(
							'allowed_classes' => false,
							'max_depth'       => 64,
						)
					);
					$loss  = serialize( $value ) !== $raw;
				}
			}
			$rows[] = array(
				'meta_id'            => (int) $meta['meta_id'],
				'term_id'            => (int) $meta['term_id'],
				'key'                => $key,
				'raw_value'          => $raw,
				'value'              => $value,
				'serialization_loss' => $loss,
			);
		}
		return $rows;
	}

	/**
	 * Preflights scalar/array serialization without instantiating application classes.
	 * String bodies are skipped by byte length. Object, enum and reference tokens
	 * are refused at every depth. Native decoding then checks canonical bytes.
	 *
	 * @param string $raw    Bounded serialized bytes.
	 * @param int    $offset Current byte offset.
	 * @param int    $depth  Array nesting depth.
	 * @return bool
	 */
	private function safe_serialized_fragment( $raw, &$offset, $depth = 0 ) {
		if ( $depth > 64 || $offset >= strlen( $raw ) ) {
			return false;
		}
		if ( 'N;' === substr( $raw, $offset, 2 ) ) {
			$offset += 2;
			return true;
		}
		if ( preg_match( '/\G(?:b:[01]|i:-?[0-9]+|d:[+-]?[0-9]+(?:\.[0-9]*)?(?:[Ee][+-]?[0-9]+)?);/', $raw, $match, 0, $offset ) ) {
			$offset += strlen( $match[0] );
			return true;
		}
		if ( preg_match( '/\Gs:([0-9]+):"/', $raw, $match, 0, $offset ) ) {
			$offset += strlen( $match[0] );
			$length  = (int) $match[1];
			if ( $length > strlen( $raw ) - $offset - 2 || '";' !== substr( $raw, $offset + $length, 2 ) ) {
				return false;
			}
			$offset += $length + 2;
			return true;
		}
		if ( ! preg_match( '/\Ga:([0-9]+):\{/', $raw, $match, 0, $offset ) ) {
			return false;
		}
		$offset += strlen( $match[0] );
		$count   = (int) $match[1];
		if ( $count > intdiv( strlen( $raw ) - $offset, 2 ) ) {
			return false;
		}
		for ( $index = 0; $index < $count; ++$index ) {
			if ( ! in_array( $raw[ $offset ] ?? '', array( 'i', 's' ), true )
				|| ! $this->safe_serialized_fragment( $raw, $offset, $depth + 1 )
				|| ! $this->safe_serialized_fragment( $raw, $offset, $depth + 1 ) ) {
				return false;
			}
		}
		if ( '}' !== ( $raw[ $offset ] ?? '' ) ) {
			return false;
		}
		++$offset;
		return true;
	}

	/**
	 * Lists physical key/count summaries only; no values are loaded or hashed.
	 *
	 * @param int $term_id Canonical term ID.
	 * @param int $limit   Page size plus one look-ahead row, at most 101.
	 * @param int $offset  Bounded physical-key offset.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function list_keys( $term_id, $limit, $offset ) {
		global $wpdb;
		if ( (int) $term_id < 1 ) {
			return $this->state_error();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-schema bounded key summaries; never retrieves metadata values.
		$keys = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT MIN(meta_key) AS meta_key, COUNT(*) AS row_count FROM %i WHERE term_id = %d AND meta_key IS NOT NULL GROUP BY CAST(meta_key AS BINARY) ORDER BY CAST(meta_key AS BINARY) LIMIT %d OFFSET %d',
				$wpdb->termmeta,
				(int) $term_id,
				max( 1, min( 101, (int) $limit ) ),
				max( 0, min( 999900, (int) $offset ) )
			),
			ARRAY_A
		);
		return is_array( $keys ) && '' === $wpdb->last_error ? $keys : $this->state_error();
	}

	/** @return WP_Error */
	private function state_error() {
		return new WP_Error( 'term_meta_physical_state_unavailable', __( 'Physical term metadata state could not be established safely.', 'wp-ai-bridge' ) );
	}

	/**
	 * Prepares a JSON-decoded value exactly as WordPress stores term metadata.
	 *
	 * Existing-row direct persistence needs to run the same sanitizer Core would run.
	 * Creation owns its one sanitizer pass before the completed native creation filters.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Exact unslashed key.
	 * @param mixed  $value   Canonical unslashed value.
	 * @param callable $guard Internal lossless-value guard.
	 * @return array{value:mixed,raw_value:string|null}|WP_Error
	 */
	public function prepare_value( $term_id, $key, $value, callable $guard ) {
		$subtype = function_exists( 'get_object_subtype' ) ? get_object_subtype( 'term', (int) $term_id ) : '';
		if ( function_exists( 'sanitize_meta' ) ) {
			$value = sanitize_meta( (string) $key, $value, 'term', $subtype );
		}

		if ( ! $guard( $value ) ) {
			return new WP_Error( 'term_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-ai-bridge' ) );
		}
		return $this->stored_value( $value );
	}

	/**
	 * Preserves Core's creation lifecycle around a target-conditioned physical insert.
	 *
	 * Core add_term_meta() cannot bind taxonomy identity in its INSERT. Do not rewrite
	 * its queries or run persistence inside the provider's filter pipeline. Complete
	 * that pipeline first, then keep Core's uniqueness and lifecycle order explicitly.
	 *
	 * @param int                 $term_id Term ID.
	 * @param string              $key     Exact unslashed key.
	 * @param mixed               $value   JSON-decoded unslashed value.
	 * @param callable            $guard   Internal target/authorization/value guard.
	 * @param array<string,mixed> $target  Original authorized scalar target identity.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_unique_row( $term_id, $key, $value, callable $guard, array $target ) {
		global $wpdb;
		$term_id = (int) $term_id;
		$key     = (string) $key;
		if ( ! $this->valid_target_identity( $target ) ) {
			return $this->state_error();
		}
		// Match the native termmeta key column; direct SQL must not silently truncate it.
		if ( 1 !== preg_match( '/\A.{1,255}\z/us', $key ) ) {
			return new WP_Error( 'term_meta_key_not_storable', __( 'The metadata key must fit the native WordPress 255-character storage limit.', 'wp-ai-bridge' ) );
		}
		// Inputs are already unslashed, as after Core's one unslash pass. Use the
		// authorized subtype, and recheck the same target after extensible callbacks.
		$sanitized = sanitize_meta( $key, $value, 'term', $target['target_taxonomy'] );
		$check     = apply_filters( 'add_term_metadata', null, $term_id, $key, $sanitized, true );
		if ( null !== $check ) {
			return is_wp_error( $check ) ? $check : new WP_Error( 'term_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata value atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-ai-bridge' ) );
		}
		if ( ! $guard( $sanitized ) ) {
			return new WP_Error( 'term_meta_create_refused', __( 'The sanitized term metadata value or current target authority does not permit safe creation.', 'wp-ai-bridge' ) );
		}
		$prepared = $this->stored_value( $sanitized );
		if ( strlen( (string) $prepared['raw_value'] ) > self::MAX_VALUE_BYTES ) {
			return new WP_Error( 'term_meta_create_refused', __( 'The sanitized term metadata value or current target authority does not permit safe creation.', 'wp-ai-bridge' ) );
		}
		// Core's unique check intentionally uses the column's normal key collation.
		// It is still a precheck, not a new unique constraint or serializable protocol.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core-equivalent fixed termmeta uniqueness precheck.
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key = %s AND term_id = %d', $wpdb->termmeta, $key, $term_id ) );
		if ( null === $existing || '' !== $wpdb->last_error ) {
			return $this->state_error();
		}
		$row    = array(
			'meta_id'   => 0,
			'term_id'   => $term_id,
			'key'       => $key,
			'raw_value' => $prepared['raw_value'],
			'value'     => $prepared['value'],
		) + $target;
		$result = false;
		if ( 0 === (int) $existing ) {
			do_action( 'add_term_meta', $term_id, $key, $sanitized );
			if ( ! $guard( $sanitized ) ) {
				return new WP_Error( 'term_meta_create_refused', __( 'The sanitized term metadata value or current target authority does not permit safe creation.', 'wp-ai-bridge' ) );
			}
			$inserted = $this->insert_raw_row( $row );
			if ( 1 === $inserted ) {
				// Capture this statement's ID before an observer can perform a nested insert.
				$result         = (int) $wpdb->insert_id;
				$row['meta_id'] = $result;
				wp_cache_delete( $term_id, 'term_meta' );
				do_action( 'added_term_meta', $result, $term_id, $key, $sanitized );
			}
		}
		return array(
			'result'       => $result,
			'owned'        => is_int( $result ) && $result > 0,
			'expected_row' => $row,
		);
	}

	/**
	 * Replaces one exact physical row with byte-exact compare-and-swap semantics.
	 *
	 * Verification and any compensation stay inside this persistence boundary so a
	 * later unrelated write after the verified linearization point is simply a new state.
	 *
	 * @param int                 $term_id  Term ID.
	 * @param string              $key      Exact unslashed key.
	 * @param array<string,mixed> $row      Previously inspected physical row.
	 * @param array<string,mixed> $prepared Sanitized value and exact raw storage representation.
	 * @return array<string,mixed>|WP_Error Verified physical row after the update.
	 */
	public function replace_row( $term_id, $key, array $row, array $prepared, callable $guard ) {
		$current = $this->rows( $term_id, $key );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-ai-bridge' ) );
		}

		if ( ! $guard() ) {
			return new WP_Error( 'term_meta_permission_denied', __( 'The current WordPress user is not allowed to perform this metadata operation.', 'wp-ai-bridge' ) );
		}
		if ( $row['raw_value'] === $prepared['raw_value'] ) {
			return $current[0];
		}

		$short_circuit = apply_filters( 'update_term_metadata', null, (int) $term_id, (string) $key, $prepared['value'], $row['value'] );
		if ( null !== $short_circuit ) {
			return new WP_Error( 'term_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata value atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-ai-bridge' ) );
		}

		$meta_id = (int) $row['meta_id'];
		do_action( 'update_term_meta', $meta_id, (int) $term_id, (string) $key, $prepared['value'] );

		if ( ! $guard() ) {
			return new WP_Error( 'term_meta_permission_denied', __( 'The current WordPress user is not allowed to perform this metadata operation.', 'wp-ai-bridge' ) );
		}

		$result = $this->exact_update_raw_row( $meta_id, (int) $term_id, (string) $key, $row['raw_value'], $prepared['raw_value'], $row );
		if ( false === $result ) {
			wp_cache_delete( (int) $term_id, 'term_meta' );
			return new WP_Error( 'term_meta_update_failed', __( 'WordPress could not update the requested metadata key.', 'wp-ai-bridge' ) );
		}
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $term_id, 'term_meta' );
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-ai-bridge' ) );
		}

		wp_cache_delete( (int) $term_id, 'term_meta' );
		do_action( 'updated_term_meta', $meta_id, (int) $term_id, (string) $key, $prepared['value'] );

		$after = $this->rows( $term_id, $key );
		if ( is_wp_error( $after ) ) {
			if ( ! $this->restore_updated_row( $row, $prepared['raw_value'] ) ) {
				return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
			}
			return $after;
		}
		$expected_row              = $row;
		$expected_row['raw_value'] = $prepared['raw_value'];
		if ( 1 !== count( $after ) || ! $this->row_matches( $after[0], $expected_row ) ) {
			if ( ! $this->restore_updated_row( $row, $prepared['raw_value'] ) ) {
				return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
			}
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-ai-bridge' ) );
		}

		return $after[0];
	}

	/**
	 * Deletes one exact physical row with byte-exact compare-and-swap semantics.
	 *
	 * Verification and restoration stay inside the same persistence boundary.
	 *
	 * @param int                 $term_id Term ID.
	 * @param string              $key     Exact unslashed key.
	 * @param array<string,mixed> $row     Previously inspected physical row.
	 * @return true|WP_Error
	 */
	public function delete_row( $term_id, $key, array $row, callable $guard ) {
		$current = $this->rows( $term_id, $key );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-ai-bridge' ) );
		}

		$short_circuit = apply_filters( 'delete_term_metadata', null, (int) $term_id, (string) $key, $row['value'], false );
		if ( null !== $short_circuit ) {
			return new WP_Error( 'term_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata value atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-ai-bridge' ) );
		}

		$meta_id  = (int) $row['meta_id'];
		$meta_ids = array( $meta_id );
		do_action( 'delete_term_meta', $meta_ids, (int) $term_id, (string) $key, $row['value'] );

		if ( ! $guard() ) {
			return new WP_Error( 'term_meta_permission_denied', __( 'The current WordPress user is not allowed to perform this metadata operation.', 'wp-ai-bridge' ) );
		}

		$result = $this->exact_delete_raw_row( $meta_id, (int) $term_id, (string) $key, $row['raw_value'], $row );
		if ( false === $result ) {
			wp_cache_delete( (int) $term_id, 'term_meta' );
			return new WP_Error( 'term_meta_delete_failed', __( 'WordPress could not delete the requested metadata key.', 'wp-ai-bridge' ) );
		}
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $term_id, 'term_meta' );
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-ai-bridge' ) );
		}

		wp_cache_delete( (int) $term_id, 'term_meta' );
		do_action( 'deleted_term_meta', $meta_ids, (int) $term_id, (string) $key, $row['value'] );

		$after = $this->rows( $term_id, $key );
		if ( is_wp_error( $after ) ) {
			if ( ! $this->restore_deleted_row( $row ) ) {
				return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
			}
			return $after;
		}
		if ( ! empty( $after ) ) {
			if ( ! $this->restore_deleted_row( $row ) ) {
				return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
			}
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-ai-bridge' ) );
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
		if ( ! $this->target_matches( $row ) ) {
			return false;
		}
		do_action( 'add_term_meta', (int) $row['term_id'], (string) $row['key'], $row['value'] );
		if ( ! $this->target_matches( $row ) ) {
			return false;
		}
		$result = $this->insert_raw_row( $row );
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $row['term_id'], 'term_meta' );
			return false;
		}
		wp_cache_delete( (int) $row['term_id'], 'term_meta' );
		do_action( 'added_term_meta', (int) $row['meta_id'], (int) $row['term_id'], (string) $row['key'], $row['value'] );
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
		if ( ! $this->target_matches( $row ) ) {
			return false;
		}
		$meta_id = (int) $row['meta_id'];
		do_action( 'update_term_meta', $meta_id, (int) $row['term_id'], (string) $row['key'], $row['value'] );
		if ( ! $this->target_matches( $row ) ) {
			return false;
		}

		$result = $this->exact_update_raw_row( $meta_id, (int) $row['term_id'], (string) $row['key'], $expected_new_raw, $row['raw_value'], $row );
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $row['term_id'], 'term_meta' );
			return false;
		}
		wp_cache_delete( (int) $row['term_id'], 'term_meta' );
		do_action( 'updated_term_meta', $meta_id, (int) $row['term_id'], (string) $row['key'], $row['value'] );
		return true;
	}

	/**
	 * Removes only an unchanged row created by this invocation during create-race cleanup.
	 *
	 * @param array<string,mixed> $row Expected physical row created by this invocation.
	 * @return true|WP_Error
	 */
	public function cleanup_created_row( array $row ) {
		if ( ! $this->target_matches( $row, true ) ) {
			return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
		}
		$meta_id  = (int) $row['meta_id'];
		$meta_ids = array( $meta_id );
		do_action( 'delete_term_meta', $meta_ids, (int) $row['term_id'], (string) $row['key'], $row['value'] );
		if ( ! $this->target_matches( $row, true ) ) {
			return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
		}

		$result = $this->exact_delete_raw_row( $meta_id, (int) $row['term_id'], (string) $row['key'], $row['raw_value'], $row, true );
		if ( false === $result ) {
			wp_cache_delete( (int) $row['term_id'], 'term_meta' );
			return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-ai-bridge' ) );
		}
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $row['term_id'], 'term_meta' );
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-ai-bridge' ) );
		}

		wp_cache_delete( (int) $row['term_id'], 'term_meta' );
		do_action( 'deleted_term_meta', $meta_ids, (int) $row['term_id'], (string) $row['key'], $row['value'] );
		return true;
	}

	/**
	 * Revalidates the original taxonomy and term-taxonomy row before compensation.
	 * Only cleanup of this invocation's unchanged newly-created orphan may proceed
	 * after the term disappears; restoration never recreates an orphan. A term ID
	 * reused or moved to another taxonomy is not the original authorized target.
	 *
	 * @param array<string,mixed> $row           Owned row plus internal target snapshot.
	 * @param bool                $allow_missing Permit removal of an owned create orphan.
	 * @return bool
	 */
	private function target_matches( array $row, $allow_missing = false ) {
		if ( ! $this->valid_target_identity( $row ) ) {
			return false;
		}
		$term_id = (int) $row['term_id'];
		$term    = get_term( $term_id );
		if ( ! $term ) {
			return $allow_missing;
		}
		return ! is_wp_error( $term ) && ! wp_term_is_shared( $term_id )
			&& $term_id === (int) $term->term_id
			&& $row['target_taxonomy'] === $term->taxonomy
			&& $row['target_term_taxonomy_id'] === (int) $term->term_taxonomy_id
			&& get_object_subtype( 'term', $term_id ) === $row['target_taxonomy'];
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
			&& (int) $left['term_id'] === (int) $right['term_id']
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
	 * Validates the internal scalar identity carried from the authorized term.
	 *
	 * @param array<string,mixed> $target Original target snapshot, never public input.
	 * @return bool
	 */
	private function valid_target_identity( array $target ) {
		return isset( $target['target_taxonomy'], $target['target_term_taxonomy_id'] )
			&& is_string( $target['target_taxonomy'] ) && '' !== $target['target_taxonomy']
			&& is_int( $target['target_term_taxonomy_id'] ) && $target['target_term_taxonomy_id'] > 0;
	}

	/**
	 * Inserts one row only while its original live unshared term identity exists.
	 *
	 * NULLIF maps only the internal new-row ID zero to AUTO_INCREMENT; restoration
	 * retains its original positive ID and cannot overwrite a different row owner.
	 * The locking source read is explicit, including under READ COMMITTED. No
	 * application transaction, query rewrite, or caller-selected SQL is introduced.
	 *
	 * @param array<string,mixed> $row Metadata and original scalar target identity.
	 * @return int|false
	 */
	private function insert_raw_row( array $row ) {
		global $wpdb;
		if ( ! $this->valid_target_identity( $row ) ) {
			return false;
		}
		if ( null === $row['raw_value'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed termmeta write with current native identity predicates.
			return $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (meta_id, term_id, meta_key, meta_value) SELECT NULLIF(%d, 0), tt.term_id, %s, NULL FROM %i AS tt INNER JOIN %i AS t ON t.term_id = tt.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = tt.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id WHERE tt.term_id = %d AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL LOCK IN SHARE MODE',
					$wpdb->termmeta,
					(int) $row['meta_id'],
					(string) $row['key'],
					$wpdb->term_taxonomy,
					$wpdb->terms,
					$wpdb->term_taxonomy,
					(int) $row['term_id'],
					$row['target_term_taxonomy_id'],
					$row['target_taxonomy']
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed termmeta write with current native identity predicates.
		return $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (meta_id, term_id, meta_key, meta_value) SELECT NULLIF(%d, 0), tt.term_id, %s, %s FROM %i AS tt INNER JOIN %i AS t ON t.term_id = tt.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = tt.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id WHERE tt.term_id = %d AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL LOCK IN SHARE MODE',
				$wpdb->termmeta,
				(int) $row['meta_id'],
				(string) $row['key'],
				$row['raw_value'],
				$wpdb->term_taxonomy,
				$wpdb->terms,
				$wpdb->term_taxonomy,
				(int) $row['term_id'],
				$row['target_term_taxonomy_id'],
				$row['target_taxonomy']
			)
		);
	}

	/**
	 * Updates only an exact metadata row joined to the originally authorized term.
	 *
	 * @param int                 $meta_id      Physical meta ID.
	 * @param int                 $term_id      Term ID.
	 * @param string              $key          Exact key.
	 * @param string|null         $expected_raw Expected old storage bytes.
	 * @param string|null         $new_raw      New storage bytes.
	 * @param array<string,mixed> $target       Original scalar target identity.
	 * @return int|false
	 */
	private function exact_update_raw_row( $meta_id, $term_id, $key, $expected_raw, $new_raw, array $target ) {
		global $wpdb;
		if ( ! $this->valid_target_identity( $target ) ) {
			return false;
		}
		if ( null === $expected_raw && null === $new_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed termmeta write with current native identity predicates.
			return $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i AS m INNER JOIN %i AS tt ON tt.term_id = m.term_id INNER JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id SET m.meta_value = NULL WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND m.meta_value IS NULL AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL',
					$wpdb->termmeta,
					$wpdb->term_taxonomy,
					$wpdb->terms,
					$wpdb->term_taxonomy,
					(int) $meta_id,
					(int) $term_id,
					(string) $key,
					$target['target_term_taxonomy_id'],
					$target['target_taxonomy']
				)
			);
		}

		if ( null === $expected_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed termmeta write with current native identity predicates.
			return $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i AS m INNER JOIN %i AS tt ON tt.term_id = m.term_id INNER JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id SET m.meta_value = %s WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND m.meta_value IS NULL AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL',
					$wpdb->termmeta,
					$wpdb->term_taxonomy,
					$wpdb->terms,
					$wpdb->term_taxonomy,
					$new_raw,
					(int) $meta_id,
					(int) $term_id,
					(string) $key,
					$target['target_term_taxonomy_id'],
					$target['target_taxonomy']
				)
			);
		}

		if ( null === $new_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed termmeta write with current native identity predicates.
			return $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i AS m INNER JOIN %i AS tt ON tt.term_id = m.term_id INNER JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id SET m.meta_value = NULL WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(m.meta_value AS BINARY) = CAST(%s AS BINARY) AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL',
					$wpdb->termmeta,
					$wpdb->term_taxonomy,
					$wpdb->terms,
					$wpdb->term_taxonomy,
					(int) $meta_id,
					(int) $term_id,
					(string) $key,
					$expected_raw,
					$target['target_term_taxonomy_id'],
					$target['target_taxonomy']
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed termmeta write with current native identity predicates.
		return $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i AS m INNER JOIN %i AS tt ON tt.term_id = m.term_id INNER JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id SET m.meta_value = %s WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(m.meta_value AS BINARY) = CAST(%s AS BINARY) AND tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL',
				$wpdb->termmeta,
				$wpdb->term_taxonomy,
				$wpdb->terms,
				$wpdb->term_taxonomy,
				$new_raw,
				(int) $meta_id,
				(int) $term_id,
				(string) $key,
				$expected_raw,
				$target['target_term_taxonomy_id'],
				$target['target_taxonomy']
			)
		);
	}

	/**
	 * Deletes an exact row only at its original live identity or an owned orphan.
	 *
	 * The missing-target alternative is internal to create compensation. Both native
	 * term and taxonomy rows must be absent; a transferred or shared live target never
	 * qualifies. All conditions are evaluated by the same physical DELETE statement.
	 *
	 * @param int                 $meta_id      Physical meta ID.
	 * @param int                 $term_id      Term ID.
	 * @param string              $key          Exact key.
	 * @param string|null         $expected_raw Expected storage bytes.
	 * @param array<string,mixed> $target       Original scalar target identity.
	 * @param bool                $allow_missing Remove only this invocation's orphan.
	 * @return int|false
	 */
	private function exact_delete_raw_row( $meta_id, $term_id, $key, $expected_raw, array $target, $allow_missing = false ) {
		global $wpdb;
		if ( ! $this->valid_target_identity( $target ) ) {
			return false;
		}
		if ( null === $expected_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed termmeta write with current native identity predicates.
			return $wpdb->query(
				$wpdb->prepare(
					'DELETE m FROM %i AS m LEFT JOIN %i AS tt ON tt.term_id = m.term_id LEFT JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND m.meta_value IS NULL AND ( ( tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL AND t.term_id IS NOT NULL ) OR ( %d = 1 AND tt.term_taxonomy_id IS NULL AND t.term_id IS NULL ) )',
					$wpdb->termmeta,
					$wpdb->term_taxonomy,
					$wpdb->terms,
					$wpdb->term_taxonomy,
					(int) $meta_id,
					(int) $term_id,
					(string) $key,
					$target['target_term_taxonomy_id'],
					$target['target_taxonomy'],
					(int) $allow_missing
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed termmeta write with current native identity predicates.
		return $wpdb->query(
			$wpdb->prepare(
				'DELETE m FROM %i AS m LEFT JOIN %i AS tt ON tt.term_id = m.term_id LEFT JOIN %i AS t ON t.term_id = m.term_id LEFT JOIN %i AS other_tt ON other_tt.term_id = m.term_id AND other_tt.term_taxonomy_id <> tt.term_taxonomy_id WHERE m.meta_id = %d AND m.term_id = %d AND CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(m.meta_value AS BINARY) = CAST(%s AS BINARY) AND ( ( tt.term_taxonomy_id = %d AND CAST(tt.taxonomy AS BINARY) = CAST(%s AS BINARY) AND other_tt.term_taxonomy_id IS NULL AND t.term_id IS NOT NULL ) OR ( %d = 1 AND tt.term_taxonomy_id IS NULL AND t.term_id IS NULL ) )',
				$wpdb->termmeta,
				$wpdb->term_taxonomy,
				$wpdb->terms,
				$wpdb->term_taxonomy,
				(int) $meta_id,
				(int) $term_id,
				(string) $key,
				$expected_raw,
				$target['target_term_taxonomy_id'],
				$target['target_taxonomy'],
				(int) $allow_missing
			)
		);
	}
}
