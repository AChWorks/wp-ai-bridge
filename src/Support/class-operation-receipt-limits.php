<?php
/**
 * Administrator-controlled admission capacity for generic keyed create receipts.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Site-local operation receipts are durable identity tombstones, not a cache.
 * A lowered policy never deletes existing receipts or blocks authorized retry
 * readback; it only closes admission for new independent operation IDs.
 */
final class Operation_Receipt_Limits {
	const OPTION_NAME      = 'wp_ai_bridge_operation_receipt_limits';
	const OPTION_GROUP     = 'wp_ai_bridge_operation_receipt_capacity';
	const DEFAULT_RECEIPTS = 2048;
	const MIN_RECEIPTS     = 128;
	const MAX_RECEIPTS     = 100000;

	/** @return int */
	public function limit() {
		$parsed = $this->parse( get_option( self::OPTION_NAME, self::DEFAULT_RECEIPTS ) );
		return null === $parsed ? self::DEFAULT_RECEIPTS : $parsed;
	}

	/** Register native, non-REST per-blog WordPress Settings API policy. */
	public function register() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_RECEIPTS,
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Preserve the last valid setting on invalid or out-of-range submissions.
	 *
	 * @param mixed $incoming Unsanitized WordPress options.php input.
	 * @return int
	 */
	public function sanitize( $incoming ) {
		$parsed = $this->parse( $incoming );
		if ( null === $parsed ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION_NAME,
					'wpai_operation_receipts_invalid',
					__( 'Generic create receipt capacity must be a whole number in the shown range. The invalid value was not saved.', 'wp-ai-bridge' )
				);
			}
			return $this->limit();
		}
		return $parsed;
	}

	/**
	 * Reject floats, exponent notation, signed, ambiguous or oversized input.
	 * A finite operator guard prevents untested million-row receipt scans.
	 *
	 * @param mixed $value Candidate integer or strictly decimal input.
	 * @return int|null
	 */
	private function parse( $value ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return null;
		}
		$text = (string) $value;
		$max  = (string) self::MAX_RECEIPTS;
		if ( '' === $text || 1 !== preg_match( '/^[0-9]+$/D', $text ) ||
			strlen( $text ) > strlen( $max ) ||
			( strlen( $text ) === strlen( $max ) && strcmp( $text, $max ) > 0 ) ) {
			return null;
		}
		$number = (int) $text;
		return $number >= self::MIN_RECEIPTS && $number <= self::MAX_RECEIPTS ? $number : null;
	}
}
