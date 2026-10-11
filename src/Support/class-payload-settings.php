<?php
/**
 * Administrator-owned per-site transfer response budgets.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Independently manages Bridge transport budgets and finite per-record Workspace quotas.
 */
final class Payload_Settings {
	const OPTION_NAME     = 'wp_ai_bridge_payload_limits';
	const OPTION_GROUP    = 'wp_ai_bridge_payload';
	const RESPONSE_KEY    = 'response_kib';
	const WINDOW_KEY      = 'text_window_kib';
	const DOCUMENT_KEY    = 'workspace_document_kib';
	const NOTES_KEY       = 'workspace_notes_kib';
	const INLINE_FILE_KEY = 'inline_file_read_mib';

	// The direct Bridge default is intentionally not constrained by the old
	// Gateway 64 KiB ordinary-response default. Older Gateway deployments
	// must use a smaller operator setting (e.g. 48 KiB) until coordinated
	// compatibility and effective response budgets are verified.
	const DEFAULT_RESPONSE_KIB    = 128;
	const DEFAULT_WINDOW_KIB      = 128;
	const DEFAULT_DOCUMENT_KIB    = 512;
	const DEFAULT_NOTES_KIB       = 128;
	const DEFAULT_INLINE_FILE_MIB = 128;

	// Administrator tuning is intentionally finite. These are not aggregate
	// file/document/transfer size ceilings and do not allocate memory at rest.
	const MIN_RESPONSE_KIB = 16;
	const MAX_RESPONSE_KIB = 1024;
	const MIN_WINDOW_KIB   = 4;
	const MAX_WINDOW_KIB   = 512;
	const MIN_DOCUMENT_KIB = 64;
	const MAX_DOCUMENT_KIB = 1024;
	const MIN_NOTES_KIB    = 16;
	const MAX_NOTES_KIB    = 256;
	// Read-only, whole-file hashing inline-MCP fallback; not a universal file cap.
	const MIN_INLINE_FILE_MIB = 8;
	const MAX_INLINE_FILE_MIB = 1024;

	/** Register an independent setting so saving it cannot change access grants. */
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

	/** @return array<string,int> */
	public function defaults() {
		return array(
			self::RESPONSE_KEY    => self::DEFAULT_RESPONSE_KIB,
			self::WINDOW_KEY      => self::DEFAULT_WINDOW_KIB,
			self::DOCUMENT_KEY    => self::DEFAULT_DOCUMENT_KIB,
			self::NOTES_KEY       => self::DEFAULT_NOTES_KIB,
			self::INLINE_FILE_KEY => self::DEFAULT_INLINE_FILE_MIB,
		);
	}

	/**
	 * Return validated per-blog values; old or malformed options fail to
	 * defaults, not to unbounded limits. WordPress get_option is cached.
	 *
	 * @return array<string,int>
	 */
	public function all() {
		$stored   = get_option( self::OPTION_NAME, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = $this->defaults();

		foreach ( $defaults as $key => $default ) {
			if ( array_key_exists( $key, $stored ) ) {
				$parsed = $this->parse( $key, $stored[ $key ] );
				if ( null !== $parsed ) {
					$defaults[ $key ] = $parsed;
				}
			}
		}

		return $defaults;
	}

	/**
	 * Settings API callback: invalid input preserves the previous effective
	 * value rather than silently expanding or discarding operator limits.
	 *
	 * @param mixed $input Native submitted option value.
	 * @return array<string,int>
	 */
	public function sanitize( $input ) {
		$previous = $this->all();
		if ( ! is_array( $input ) ) {
			$this->settings_error();
			return $previous;
		}

		foreach ( $this->defaults() as $key => $unused ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$parsed = $this->parse( $key, $input[ $key ] );
			if ( null === $parsed ) {
				$this->settings_error();
				continue;
			}
			$previous[ $key ] = $parsed;
		}
		return $previous;
	}

	/**
	 * Validate integers without PHP coercion of "4e6", fractions, arrays or
	 * unbounded numeric strings; range-check before casting.
	 *
	 * @param string $key   Recognized budget selector.
	 * @param mixed  $value Untrusted stored/submitted scalar.
	 * @return int|null
	 */
	private function parse( $key, $value ) {
		if ( ! in_array( $key, array( self::RESPONSE_KEY, self::WINDOW_KEY, self::DOCUMENT_KEY, self::NOTES_KEY, self::INLINE_FILE_KEY ), true ) ||
			( ! is_int( $value ) && ! is_string( $value ) ) ) {
			return null;
		}
		$value = (string) $value;
		if ( 1 !== preg_match( '/^[0-9]{1,7}$/D', $value ) ) {
			return null;
		}

		$integer = (int) $value;
		$ranges  = array(
			self::RESPONSE_KEY    => array( self::MIN_RESPONSE_KIB, self::MAX_RESPONSE_KIB ),
			self::WINDOW_KEY      => array( self::MIN_WINDOW_KIB, self::MAX_WINDOW_KIB ),
			self::DOCUMENT_KEY    => array( self::MIN_DOCUMENT_KIB, self::MAX_DOCUMENT_KIB ),
			self::NOTES_KEY       => array( self::MIN_NOTES_KIB, self::MAX_NOTES_KIB ),
			self::INLINE_FILE_KEY => array( self::MIN_INLINE_FILE_MIB, self::MAX_INLINE_FILE_MIB ),
		);
		$min     = $ranges[ $key ][0];
		$max     = $ranges[ $key ][1];

		return $integer >= $min && $integer <= $max ? $integer : null;
	}

	/** Report validation errors only through the native administrator screen. */
	private function settings_error() {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'wpai_payload_limit_invalid',
				__( 'The transfer budget must be a whole number within the indicated KiB range. The previous value was kept.', 'wp-ai-bridge' )
			);
		}
	}
}
