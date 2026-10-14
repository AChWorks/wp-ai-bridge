<?php
/**
 * Per-site finite budgets for bounded, high-trust operation bodies.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * These are independent operator policies, never authorization grants.
 *
 * Runtime ceilings are based on the PHP process memory budget because source
 * editing and native metadata/settings inspection hold complete values in PHP.
 * Larger files that cannot fit an MCP message still need a purpose-bound
 * authenticated private transfer consumer, not a bypass of the wire budget.
 */
final class Operation_Content_Limits {
	const OPTION_NAME  = 'wp_ai_bridge_operation_content_limits';
	const OPTION_GROUP = 'wp_ai_bridge_operation_content';

	const SOURCE_MIB         = 'source_mib';
	const SETTING_VALUE_KIB  = 'registered_setting_value_kib';
	const SETTING_SCHEMA_KIB = 'registered_setting_schema_kib';
	const CATALOG_KIB        = 'ability_catalog_kib';

	const KIB = 1024;
	const MIB = 1048576;

	/** @return array<string,int> */
	public function defaults() {
		return array(
			self::SOURCE_MIB         => 2,
			self::SETTING_VALUE_KIB  => 1024,
			self::SETTING_SCHEMA_KIB => 256,
			self::CATALOG_KIB        => 1024,
		);
	}

	/**
	 * No product-defined upper source/value caps: the largest operator value
	 * depends on the current PHP process memory and integer representation.
	 * Other host, transport, native/provider and DB bounds still apply.
	 *
	 * @return array<string,int>
	 */
	public function upper_bounds() {
		$memory = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) : -1;
		$memory = is_int( $memory ) && $memory > 0 ? $memory : 0;
		$source = intdiv( PHP_INT_MAX, self::MIB );
		$body   = intdiv( PHP_INT_MAX, self::KIB );
		if ( $memory > 0 ) {
			// Source edits hold preimage, candidate, recovery and validation
			// copies; settings/contract JSON also have transient copies.
			$source = min( $source, max( 2, intdiv( $memory, 16 * self::MIB ) ) );
			$body   = min( $body, max( 1024, intdiv( $memory, 8 * self::KIB ) ) );
		}
		return array(
			self::SOURCE_MIB         => $source,
			self::SETTING_VALUE_KIB  => $body,
			self::SETTING_SCHEMA_KIB => $body,
			self::CATALOG_KIB        => $body,
		);
	}

	/** @return array<string,int> */
	public function all() {
		$stored   = get_option( self::OPTION_NAME, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = $this->defaults();
		$maxima   = $this->upper_bounds();
		foreach ( $defaults as $key => $fallback ) {
			if ( array_key_exists( $key, $stored ) ) {
				$value = $this->parse( $stored[ $key ], $maxima[ $key ] );
				if ( null !== $value ) {
					$defaults[ $key ] = $value;
				}
			}
		}
		return $defaults;
	}

	/** @param string $key Known operation budget key. @return int */
	public function bytes( $key ) {
		$values = $this->all();
		return isset( $values[ $key ] )
			? $values[ $key ] * ( self::SOURCE_MIB === $key ? self::MIB : self::KIB )
			: 0;
	}

	/** @return void */
	public function register() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => $this->defaults(),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Reject malformed values without touching other settings or grants.
	 * Lowering a limit never deletes pre-existing values or recovery state.
	 *
	 * @param mixed $incoming Native Settings API input.
	 * @return array<string,int>
	 */
	public function sanitize( $incoming ) {
		$previous = $this->all();
		if ( ! is_array( $incoming ) ) {
			$this->invalid();
			return $previous;
		}
		$maxima = $this->upper_bounds();
		foreach ( $previous as $key => $old ) {
			if ( ! array_key_exists( $key, $incoming ) ) {
				continue;
			}
			$parsed = $this->parse( $incoming[ $key ], $maxima[ $key ] );
			if ( null === $parsed ) {
				$this->invalid();
				continue;
			}
			$previous[ $key ] = $parsed;
		}
		return $previous;
	}

	/** @param mixed $value Untrusted value. @param int $maximum Runtime ceiling. @return int|null */
	private function parse( $value, $maximum ) {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		$text = (string) $value;
		$max  = (string) $maximum;
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', $text )
			|| strlen( $text ) > strlen( $max )
			|| ( strlen( $text ) === strlen( $max ) && strcmp( $text, $max ) > 0 ) ) {
			return null;
		}
		$number = (int) $text;
		return $number >= 1 ? $number : null;
	}

	/** @return void */
	private function invalid() {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'wpai_operation_content_invalid',
				__( 'Operation content capacity must be a positive whole number within current PHP memory limits. The previous setting was kept.', 'wp-ai-bridge' )
			);
		}
	}
}
