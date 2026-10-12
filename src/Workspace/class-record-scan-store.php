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
	 * Keyset scan 16 IDs at a time without caching complete post/meta objects.
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
			// Fixed 16-ID SQL statement; pad incomplete pages with impossible ID 0.
			// No dynamic SQL fragments can originate from a caller.
			$args = array_merge( array( Store::META_STATE ), array_pad( $ids, self::SCAN_BATCH, 0 ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The fixed query has 17 placeholders; the array contains exactly one meta key plus 16 padded IDs.
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)",
					$args
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				return new WP_Error( 'workspace_scan_unavailable', __( 'Workspace database enumeration failed; retry without assuming completeness.', 'wp-ai-bridge' ) );
			}
			$meta = array();
			foreach ( $rows as $row ) {
				$id = (int) $row['post_id'];
				if ( isset( $meta[ $id ] ) || ! is_string( $row['meta_value'] ) ) {
					return new WP_Error( 'workspace_incomplete', __( 'One or more Workspace records could not be decoded; results cannot be claimed complete.', 'wp-ai-bridge' ), array( 'id' => $id ) );
				}
				$meta[ $id ] = $row['meta_value'];
			}
			foreach ( $ids as $id ) {
				if ( ! isset( $meta[ $id ] ) ) {
					return new WP_Error( 'workspace_incomplete', __( 'One or more Workspace records could not be decoded; results cannot be claimed complete.', 'wp-ai-bridge' ), array( 'id' => $id ) );
				}
				$result = call_user_func( $consume, $id, $meta[ $id ] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				++$scanned;
			}
			$cursor = end( $ids );
			unset( $meta, $rows, $ids );
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
