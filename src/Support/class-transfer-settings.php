<?php
/**
 * Administrator-owned, site-specific private transfer capacity policy.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Independent of WordPress object quotas and legacy inline-MCP budgets.
 *
 * All configurable numbers must remain finite. The upper representable values
 * are derived from the current PHP/runtime resource constraints rather than
 * arbitrary product file-size, extension or timeout ceilings.
 */
final class Transfer_Settings {
	const OPTION_NAME  = 'wp_ai_bridge_transfer_limits';
	const OPTION_GROUP = 'wp_ai_bridge_transfer';

	const REQUEST_KIB = 'request_kib';
	const CHUNK_KIB   = 'chunk_kib';
	const FILE_MIB    = 'file_mib';
	const ACTIVE_MIB  = 'active_mib';
	const SESSIONS    = 'sessions';
	const LEASE_MIN   = 'lease_minutes';

	/** @return array<string,int> */
	public function defaults() {
		return array(
			self::REQUEST_KIB => 128,
			self::CHUNK_KIB   => 48,
			self::FILE_MIB    => 256,
			self::ACTIVE_MIB  => 1024,
			self::SESSIONS    => 8,
			self::LEASE_MIN   => 180,
		);
	}

	/** @return array<string,int> */
	public function upper_bounds() {
		// This is a numeric/host upper bound, not a product-specific file cap.
		$machine_kib = intdiv( PHP_INT_MAX, 1024 );
		$machine_mib = intdiv( PHP_INT_MAX, 1048576 );
		$memory      = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) : -1;
		// JSON/Base64 envelopes have multiple live copies in PHP. Limit one
		// incoming message to a fraction of the effective PHP process memory.
		$input_max_kib = $memory > 0
			? max( 16, min( $machine_kib, intdiv( $memory, 8 * 1024 ) ) )
			: $machine_kib;
		return array(
			self::REQUEST_KIB => $input_max_kib,
			self::CHUNK_KIB   => $input_max_kib,
			self::FILE_MIB    => $machine_mib,
			self::ACTIVE_MIB  => $machine_mib,
			self::SESSIONS    => PHP_INT_MAX,
			self::LEASE_MIN   => intdiv( PHP_INT_MAX - time(), 60 ),
		);
	}

	/** @return array<string,int> */
	public function all() {
		$stored   = get_option( self::OPTION_NAME, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = $this->defaults();
		$maximums = $this->upper_bounds();
		foreach ( $defaults as $key => $fallback ) {
			if ( array_key_exists( $key, $stored ) ) {
				$parsed = $this->parse( $stored[ $key ], $maximums[ $key ], self::REQUEST_KIB === $key ? 16 : 1 );
				if ( null !== $parsed ) {
					$defaults[ $key ] = $parsed;
				}
			}
		}
		return $defaults;
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
	 * Strict native Settings API validation. Other settings and grants remain
	 * unchanged. An invalid field retains its prior effective value.
	 *
	 * @param mixed $incoming Untrusted settings form values.
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
			$parsed = $this->parse( $incoming[ $key ], $maxima[ $key ], self::REQUEST_KIB === $key ? 16 : 1 );
			if ( null === $parsed ) {
				$this->invalid();
			} else {
				$previous[ $key ] = $parsed;
			}
		}
		return $previous;
	}

	/**
	 * Exact decimal integer, within PHP and current PHP memory envelope.
	 *
	 * @param mixed $value Submitted scalar.
	 * @param int   $maximum Dynamic resource limit.
	 * @param int   $minimum Lower finite envelope bound.
	 * @return int|null
	 */
	private function parse( $value, $maximum, $minimum ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return null;
		}
		$decimal = (string) $value;
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', $decimal ) ) {
			return null;
		}
		$bound = (string) $maximum;
		if ( strlen( $decimal ) > strlen( $bound ) ||
			( strlen( $decimal ) === strlen( $bound ) && strcmp( $decimal, $bound ) > 0 ) ) {
			return null;
		}
		$parsed = (int) $decimal;
		return $parsed >= $minimum ? $parsed : null;
	}

	/** @return void */
	private function invalid() {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'wpai_transfer_invalid_capacity',
				__( 'Transfer capacity must be a positive whole number within the current PHP resource limits. The previous setting was kept.', 'wp-ai-bridge' )
			);
		}
	}

	/** @return array<string,int> */
	public function bytes() {
		$s = $this->all();
		// Multipliers cannot overflow after upper_bounds() validation.
		return array(
			'request'  => $s[ self::REQUEST_KIB ] * 1024,
			'chunk'    => $s[ self::CHUNK_KIB ] * 1024,
			'file'     => $s[ self::FILE_MIB ] * 1048576,
			'active'   => $s[ self::ACTIVE_MIB ] * 1048576,
			'sessions' => $s[ self::SESSIONS ],
			'ttl'      => $s[ self::LEASE_MIN ] * 60,
		);
	}
}
