<?php
/**
 * Read-only native WordPress Workspace record scanning.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Workspace;

use WP_Error;

/**
 * Narrow SQL seam: only two private WordPress post types and fixed state meta.
 * No caller-selected SQL, table, column, path or arbitrary WordPress post type.
 */
final class Record_Scan_Store {
	const SCAN_BATCH = 16;
	// A 1-MiB UTF-8 field may require several MiB in JSON when characters
	// need escaping. This envelope permits historical valid states, while
	// refusing unexpectedly enormous private metadata before fetching it.
	const MAX_STATE_BYTES = 8388608;

	/**
	 * Do not fetch 16 large states in one PHP allocation on low-memory sites.
	 * The size-only DB projection gives a bounded live-byte budget for each
	 * subsequent fixed-shape state query. One valid large record may exceed
	 * this per-group target, but never MAX_STATE_BYTES.
	 *
	 * @return int
	 */
	private function group_budget() {
		$memory = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) : -1;
		return is_int( $memory ) && $memory > 0
			? min( self::MAX_STATE_BYTES, max( 1048576, intdiv( $memory, 32 ) ) )
			: 4194304;
	}


	/** @param string $post_type Fixed private type. @return bool */
	private function valid_type( $post_type ) {
		return in_array( $post_type, array( Store::DOCUMENT_POST_TYPE, Store::TASK_POST_TYPE ), true );
	}

	/** @param string $post_type Fixed type. @return int|WP_Error */
	public function count( $post_type ) {
		if ( ! $this->valid_type( $post_type ) ) {
			return new WP_Error( 'workspace_invalid_type', __( 'Workspace storage type is invalid.', 'wp-ai-bridge' ) );
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return new WP_Error( 'workspace_count_unavailable', __( 'Workspace admission count is unavailable; no create was attempted.', 'wp-ai-bridge' ) );
		}
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				$post_type
			)
		);
		return '' === $wpdb->last_error && is_numeric( $count ) && (float) $count <= PHP_INT_MAX
			? (int) $count
			: new WP_Error( 'workspace_count_unavailable', __( 'Workspace admission count is unavailable; no create was attempted.', 'wp-ai-bridge' ) );
	}

	/**
	 * Keyset scan 16 IDs at a time; materialize only length-budgeted state groups.
	 * Fail closed if a row is absent, duplicated or the total count changes.
	 * This is a best-effort read, not a transactional consistent snapshot.
	 *
	 * @param string   $post_type Fixed private type.
	 * @param callable $consume  Receives ID and authoritative stored JSON.
	 * @return int|WP_Error
	 */
	public function walk( $post_type, $consume ) {
		if ( ! $this->valid_type( $post_type ) ) {
			return new WP_Error( 'workspace_invalid_type', __( 'Workspace storage type is invalid.', 'wp-ai-bridge' ) );
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ||
			! method_exists( $wpdb, 'get_results' ) ) {
			return new WP_Error( 'workspace_scan_unavailable', __( 'Workspace database enumeration failed; retry without assuming completeness.', 'wp-ai-bridge' ) );
		}
		$before = $this->count( $post_type );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		$cursor  = PHP_INT_MAX;
		$scanned = 0;
		do {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID < %d ORDER BY ID DESC LIMIT %d",
					$post_type,
					$cursor,
					self::SCAN_BATCH
				)
			);
			if ( ! is_array( $ids ) || '' !== $wpdb->last_error ) {
				return new WP_Error( 'workspace_scan_unavailable', __( 'Workspace database enumeration failed; retry without assuming completeness.', 'wp-ai-bridge' ) );
			}
			if ( ! $ids ) {
				break;
			}
			$ids = array_map( 'intval', $ids );
			// Read only lengths first. A full group of maximum-sized notes
			// must not cross the PHP memory budget before it can be checked.
			$length_rows = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Fixed 17 placeholders and exactly 17 array members.
					"SELECT post_id, OCTET_LENGTH(meta_value) AS payload_bytes FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)",
					array_merge( array( Store::META_STATE ), array_pad( $ids, self::SCAN_BATCH, 0 ) )
				),
				ARRAY_A
			);
			if ( ! is_array( $length_rows ) || '' !== $wpdb->last_error ) {
				return new WP_Error( 'workspace_scan_unavailable', __( 'Workspace database enumeration failed; retry without assuming completeness.', 'wp-ai-bridge' ) );
			}
			$lengths = array();
			foreach ( $length_rows as $row ) {
				$id = (int) $row['post_id'];
				if ( isset( $lengths[ $id ] ) || ! is_numeric( $row['payload_bytes'] ) ||
					(float) $row['payload_bytes'] > self::MAX_STATE_BYTES ) {
					return new WP_Error( 'workspace_incomplete', __( 'One or more Workspace records could not be decoded; results cannot be claimed complete.', 'wp-ai-bridge' ), array( 'id' => $id ) );
				}
				$lengths[ $id ] = (int) $row['payload_bytes'];
			}
			$groups = array();
			$group  = array();
			$bytes  = 0;
			$budget = $this->group_budget();
			foreach ( $ids as $id ) {
				if ( ! isset( $lengths[ $id ] ) ) {
					return new WP_Error( 'workspace_incomplete', __( 'One or more Workspace records could not be decoded; results cannot be claimed complete.', 'wp-ai-bridge' ), array( 'id' => $id ) );
				}
				if ( $group && $bytes + $lengths[ $id ] > $budget ) {
					$groups[] = $group;
					$group    = array();
					$bytes    = 0;
				}
				$group[] = $id;
				$bytes  += $lengths[ $id ];
			}
			if ( $group ) {
				$groups[] = $group;
			}

			foreach ( $groups as $group_ids ) {
				// Fixed 16-ID statement; padded zeros cannot select a real
				// WordPress ID. A too-large row is withheld, never truncated.
				$rows = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Fixed 17 placeholders and exactly 17 array members.
						"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d) AND OCTET_LENGTH(meta_value) <= 8388608",
						array_merge( array( Store::META_STATE ), array_pad( $group_ids, self::SCAN_BATCH, 0 ) )
					),
					ARRAY_A
				);
				if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
					return new WP_Error( 'workspace_scan_unavailable', __( 'Workspace database enumeration failed; retry without assuming completeness.', 'wp-ai-bridge' ) );
				}
				$meta = array();
				foreach ( $rows as $row ) {
					$id = (int) $row['post_id'];
					if ( isset( $meta[ $id ] ) || ! is_string( $row['meta_value'] ) ||
						strlen( $row['meta_value'] ) !== $lengths[ $id ] ) {
						return new WP_Error( 'workspace_scan_changed', __( 'Workspace records changed during enumeration; retry the operation.', 'wp-ai-bridge' ) );
					}
					$meta[ $id ] = $row['meta_value'];
				}
				foreach ( $group_ids as $id ) {
					if ( ! isset( $meta[ $id ] ) ) {
						return new WP_Error( 'workspace_incomplete', __( 'One or more Workspace records could not be decoded; results cannot be claimed complete.', 'wp-ai-bridge' ), array( 'id' => $id ) );
					}
					$result = call_user_func( $consume, $id, $meta[ $id ] );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					++$scanned;
				}
				unset( $meta, $rows );
			}
			$cursor = end( $ids );
			unset( $groups, $lengths, $length_rows, $ids );
		} while ( true );

		$after = $this->count( $post_type );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		if ( $before !== $scanned || $after !== $scanned ) {
			return new WP_Error( 'workspace_scan_changed', __( 'Workspace records changed during enumeration; retry the operation.', 'wp-ai-bridge' ) );
		}
		return $scanned;
	}
}
