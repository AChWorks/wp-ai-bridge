<?php
/**
 * Permanent exact-native-event run-now replay fences.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * One unique option per site/native event, never per profile or request key.
 *
 * The caller holds Admission_Lock('cron') for both the bounded count and
 * this table's unique-key insertion. Another PHP writer cannot be assumed to
 * share that lock, hence the INSERT IGNORE winner remains the durable fence.
 * Never retire an uncertain or verified claim automatically: a stale client
 * may retry with an older key, or a native provider may recreate an identity.
 */
final class Cron_Run_Claim {
	const PREFIX = 'wpai_cron_run_';

	/** @param string $event_id HMAC native event identity. @return string */
	public static function name( $event_id ) {
		return self::PREFIX . hash( 'sha256', wp_json_encode( array( get_current_blog_id(), $event_id ) ) );
	}

	/** @param string $name Derived option key. @return array<string,mixed>|false */
	public static function read( $name ) {
		if ( ! self::valid_name( $name ) ) {
			return false;
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$raw   = get_option( $name, false );
		$value = is_string( $raw ) && strlen( $raw ) <= 512 ? json_decode( $raw, true ) : false;
		return is_array( $value ) ? $value : false;
	}

	/**
	 * Atomically claim precisely one event. Called under the owned Cron lock.
	 *
	 * @param string $name Fixed derived key.
	 * @param string $initial Encoded pending receipt.
	 * @return string acquired|existing|error
	 */
	public static function reserve( $name, $initial ) {
		if ( ! self::valid_name( $name ) || ! is_string( $initial ) ||
			strlen( $initial ) > 512 || '' === $initial ) {
			return 'error';
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return 'error';
		}
		// Existing exact-event identities remain reserved indefinitely.
		if ( false !== self::read( $name ) ) {
			return 'existing';
		}
		// No lifetime-count ceiling: permanent event fences are never purged
		// to make room. Native storage failures remain fail-closed.
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic unique-key event replay fence.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- INSERT IGNORE cannot overwrite a racing claim.
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$name,
				$initial,
				'off'
			)
		);
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $inserted ? 'acquired' : ( 0 === $inserted ? 'existing' : 'error' );
	}

	/**
	 * CAS finalization never overwrites an inconsistent/competing receipt.
	 *
	 * @param string $name Original event claim key.
	 * @param string $before Original pending JSON.
	 * @param string $effect_ref Provider durable effect identity.
	 * @return bool
	 */
	public static function finish( $name, $before, $effect_ref ) {
		if ( ! self::valid_name( $name ) || ! is_string( $before ) || strlen( $before ) > 512 ||
			! is_string( $effect_ref ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $effect_ref ) ) {
			return false;
		}
		$state = json_decode( $before, true );
		if ( ! is_array( $state ) || 'in_progress' !== ( $state['state'] ?? '' ) ) {
			return false;
		}
		$state['state']      = 'verified';
		$state['effect_ref'] = $effect_ref;
		$after               = wp_json_encode( $state );
		if ( ! is_string( $after ) || strlen( $after ) > 512 ) {
			return false;
		}
		global $wpdb;
		$changed  = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact compare-and-swap receipt.
			$wpdb->options,
			array( 'option_value' => $after ),
			array(
				'option_name'  => $name,
				'option_value' => $before,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		$observed = self::read( $name );
		return 1 === $changed && is_array( $observed ) && $observed === $state;
	}

	/** @param string $name Generated claim key. @return bool */
	private static function valid_name( $name ) {
		return is_string( $name ) && 1 === preg_match( '/^wpai_cron_run_[a-f0-9]{64}$/D', $name );
	}
}
