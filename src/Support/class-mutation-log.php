<?php
/**
 * Bounded bridge mutation logging.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Stores a small metadata-only recent mutation log.
 */
final class Mutation_Log {
	const OPTION_NAME = 'wp_ai_bridge_recent_actions';
	const LIMIT       = 50;

	/**
	 * Records one mutation without retaining request payloads or secrets.
	 *
	 * @param string $ability     Ability name.
	 * @param string $target_type Optional target object type.
	 * @param int    $target_id   Optional target object ID.
	 * @param bool   $success     Whether the mutation succeeded.
	 * @param string $error_code  Optional bounded error code.
	 * @return void
	 */
	public function record( $ability, $target_type = '', $target_id = 0, $success = true, $error_code = '' ) {
		$entries = get_option( self::OPTION_NAME, array() );
		$entries = is_array( $entries ) ? $entries : array();

		array_unshift(
			$entries,
			array(
				'timestamp'   => gmdate( 'c' ),
				'user_id'     => get_current_user_id(),
				'ability'     => substr( sanitize_text_field( (string) $ability ), 0, 160 ),
				'target_type' => substr( sanitize_key( (string) $target_type ), 0, 64 ),
				'target_id'   => absint( $target_id ),
				'success'     => (bool) $success,
				'error_code'  => substr( sanitize_key( (string) $error_code ), 0, 100 ),
			)
		);

		$entries = array_slice( $entries, 0, self::LIMIT );
		update_option( self::OPTION_NAME, $entries, false );
	}

	/**
	 * Gets recent mutation metadata.
	 *
	 * @param int $limit Maximum number of entries to return.
	 * @return array<int,array<string,mixed>> Recent entries.
	 */
	public function recent( $limit = 10 ) {
		$entries = get_option( self::OPTION_NAME, array() );
		$entries = is_array( $entries ) ? $entries : array();
		$limit   = max( 0, min( absint( $limit ), self::LIMIT ) );

		return array_slice( $entries, 0, $limit );
	}
}
