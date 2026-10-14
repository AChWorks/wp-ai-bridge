<?php
/**
 * Site-local administrator pacing budget for one high-trust registered REST request.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * This adjusts only the finite serializable input envelope; it never loosens
 * route identity, sensitive-key, nesting, node-count, permission or SSRF gates.
 */
final class REST_Invocation_Input_Limits {
	const OPTION_NAME  = 'wp_ai_bridge_rest_invocation_input_limit';
	const OPTION_GROUP = 'wp_ai_bridge_rest_invocation_input';
	const DEFAULT_KIB  = 16;
	const MIN_KIB      = 1;
	const KIB          = 1024;

	/** @return int */
	public static function max_kib() {
		return intdiv( PHP_INT_MAX - 1, self::KIB );
	}

	/** @return int */
	public function requested_kib() {
		$parsed = $this->parse( get_option( self::OPTION_NAME, self::DEFAULT_KIB ) );
		return null === $parsed ? self::DEFAULT_KIB : $parsed;
	}

	/**
	 * The existing independently-configurable incoming MCP message budget
	 * caps this nested operation's entire input; cannot bypass hosting/client
	 * or PHP memory safety by changing just the REST setting.
	 *
	 * @return int
	 */
	public function effective_bytes() {
		$transport = ( new Transfer_Settings() )->bytes();
		return min( $transport['request'], $this->requested_kib() * self::KIB );
	}

	/** Register independent non-REST WordPress Settings API policy. */
	public function register() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_KIB,
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/** @param mixed $incoming Settings input. @return int */
	public function sanitize( $incoming ) {
		$parsed = $this->parse( $incoming );
		if ( null !== $parsed ) {
			return $parsed;
		}
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'wpai_rest_invocation_input_invalid',
				__( 'Registered REST input budget must be a positive whole number of KiB within the current PHP integer range. The invalid value was not saved.', 'wp-ai-bridge' )
			);
		}
		return $this->requested_kib();
	}

	/** @param mixed $input Incoming option. @return int|null */
	private function parse( $input ) {
		if ( ! is_int( $input ) && ! is_string( $input ) ) {
			return null;
		}
		$text = (string) $input;
		$max  = (string) self::max_kib();
		if (
			'' === $text || 1 !== preg_match( '/^[1-9][0-9]*$/D', $text ) ||
			strlen( $text ) > strlen( $max ) ||
			( strlen( $text ) === strlen( $max ) && strcmp( $text, $max ) > 0 )
		) {
			return null;
		}
		$value = (int) $text;
		return $value >= self::MIN_KIB && $value <= self::max_kib() ? $value : null;
	}
}
