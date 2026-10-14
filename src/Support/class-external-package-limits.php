<?php
/**
 * Administrator-controlled, host-constrained public HTTPS package capacity.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Changing the per-site package download budget never changes WordPress
 * upload policy or grants, and never authorizes an extension installation.
 */
final class External_Package_Limits {
	const OPTION_NAME  = 'wp_ai_bridge_external_package_limits';
	const OPTION_GROUP = 'wp_ai_bridge_external_package_capacity';
	const DEFAULT_MIB  = 100;
	const MIN_MIB      = 1;
	const MIB          = 1048576;

	/** Last byte must be reserved for the streaming overflow sentinel. @return int */
	public static function max_mib() {
		return intdiv( PHP_INT_MAX - 1, self::MIB );
	}

	/** @return int */
	public function requested_mib() {
		$parsed = $this->parse( get_option( self::OPTION_NAME, self::DEFAULT_MIB ) );
		return null === $parsed ? self::DEFAULT_MIB : $parsed;
	}

	/**
	 * The installed WordPress host is authoritative for its upload budget.
	 * Do not allocate or buffer the whole package in PHP.
	 *
	 * @return int Finite raw byte budget, or zero when host policy is unavailable.
	 */
	public function effective_bytes() {
		$host = function_exists( 'wp_max_upload_size' ) ? wp_max_upload_size() : 0;
		if ( ! is_int( $host ) || $host <= 0 ) {
			return 0;
		}
		return min( $host, $this->requested_mib() * self::MIB );
	}

	/** Register one per-blog WordPress Settings API option, never through REST. */
	public function register() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_MIB,
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Preserve the previous valid value when forms submit unsafe quantities.
	 *
	 * @param mixed $incoming Unsanitized options.php value.
	 * @return int
	 */
	public function sanitize( $incoming ) {
		$parsed = $this->parse( $incoming );
		if ( null !== $parsed ) {
			return $parsed;
		}
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'wpai_external_package_limit_invalid',
				__( 'External package capacity must be a positive whole number of MiB within the displayed PHP integer range. The invalid value was not saved.', 'wp-ai-bridge' )
			);
		}
		return $this->requested_mib();
	}

	/**
	 * Reject signed, exponential, float, ambiguous or out-of-PHP-bound values.
	 *
	 * @param mixed $value Candidate option.
	 * @return int|null
	 */
	private function parse( $value ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return null;
		}
		$text = (string) $value;
		$max  = (string) self::max_mib();
		if (
			'' === $text ||
			1 !== preg_match( '/^[1-9][0-9]*$/D', $text ) ||
			strlen( $text ) > strlen( $max ) ||
			( strlen( $text ) === strlen( $max ) && strcmp( $text, $max ) > 0 )
		) {
			return null;
		}
		$number = (int) $text;
		return $number >= self::MIN_MIB && $number <= self::max_mib() ? $number : null;
	}
}
