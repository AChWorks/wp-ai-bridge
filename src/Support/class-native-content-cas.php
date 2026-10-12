<?php
/**
 * Narrow WordPress native content transaction boundary.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Uses the database locks that ordinary WordPress writes respect.
 * This is deliberately not an arbitrary SQL facility or Bridge-only mutex.
 */
final class Native_Content_CAS {
	/**
	 * Lock an existing WordPress post and its identity-bearing metadata
	 * across the caller's Core wp_update_post() operation. No nested
	 * transactions, no nontransactional database engines, no silent fallback.
	 *
	 * @param int $id Native post ID.
	 * @param callable $operation Closure rechecks CAS and uses native Core APIs.
	 * @return mixed Native result or WP_Error.
	 */
	public function with_locked_post( $id, $operation ) {
		global $wpdb;
		if ( ! is_int( $id ) || $id < 1 || ! is_callable( $operation ) ||
			! is_object( $wpdb ) ||
			'0' !== (string) $wpdb->get_var( 'SELECT @@in_transaction' ) ) {
			return new WP_Error( 'transfer_cas_unavailable', 'Native WordPress conditional update requires an independent database transaction.' );
		}
		foreach ( array( $wpdb->posts, $wpdb->postmeta ) as $table ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);
			if ( 'InnoDB' !== $engine ) {
				return new WP_Error( 'transfer_cas_unavailable', 'Native WordPress post storage cannot enforce transactional row locks.' );
			}
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transfer_cas_unavailable', 'Native WordPress conditional update transaction could not start.' );
		}
		try {
			// Physical row locks are respected even by ordinary WordPress
			// wp_insert_post()/wp_update_post() writers that do not know Bridge.
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $id )
			);
			if ( ! $row || '' !== $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'transfer_cas_unavailable', 'WordPress post row could not be locked.' );
			}
			$wpdb->get_results(
				$wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $id )
			);
			if ( '' !== $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'transfer_cas_unavailable', 'WordPress metadata rows could not be locked.' );
			}
			// The row is locked; force reads of the authoritative database,
			// not any stale persistent object/meta caches.
			wp_cache_delete( $id, 'posts' );
			wp_cache_delete( $id, 'post_meta' );
			$result = $operation();
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $result;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'transfer_outcome_unknown', 'WordPress conditional post commit could not be confirmed.' );
			}
			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'transfer_outcome_unknown', 'WordPress conditional native update failed with an uncertain transaction result.' );
		}
	}
}
