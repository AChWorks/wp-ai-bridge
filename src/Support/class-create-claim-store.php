<?php
/**
 * Exactly scoped native-option claim persistence for Bridge-owned creates.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * The only direct SQL involved in durable create receipts. No generalized SQL.
 */
final class Create_Claim_Store {
	/**
	 * Replaces one exact, exclusively owned native option receipt using a
	 * database condition on its previous value; safe across PHP workers.
	 *
	 * @param string $name SHA-256-derived claim key.
	 * @param string $before Expected original JSON.
	 * @param string $after Replacement JSON.
	 * @return bool
	 */
	public static function compare_swap( $name, $before, $after ) {
		if ( ! is_string( $name ) ||
			1 !== preg_match( '/^wpai_create_claim_[a-f0-9]{64}$/D', $name ) ||
			! is_string( $before ) || ! is_string( $after ) ||
			strlen( $before ) > 1024 || strlen( $after ) > 1024 ) {
			return false;
		}
		global $wpdb;
		$changed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $after ),
			array(
				'option_name'  => $name,
				'option_value' => $before,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		if ( 1 !== $changed ) {
			return false;
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$observed = get_option( $name, false );
		return $observed === $after;
	}

	/**
	 * Bounded, site-local capacity count. Prefix is constant and escaped.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( Create_Claim::PREFIX ) . '%'
			)
		);
		return is_numeric( $count ) ? max( 0, (int) $count ) : Create_Claim::MAX_CLAIMS;
	}
}
