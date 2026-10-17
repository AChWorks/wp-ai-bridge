<?php
/**
 * Preserve diagnostic facts while redacting credential-bearing fragments.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * This is not an error-code mapper. A WP_Error code remains the same unless it
 * contains sensitive text; omitted upstream codes are represented by null.
 */
final class Error_Details {
	const MAX_CODE    = 160;
	const MAX_MESSAGE = 8192;
	const MAX_DATA    = 32768;
	const MAX_DEPTH   = 8;
	const MAX_ITEMS   = 100;

	/** @param \WP_Error $error Actual observed WordPress error. @return array<string,mixed> */
	public static function from_wp_error( $error ) {
		$redacted  = false;
		$truncated = false;
		$budget    = self::MAX_DATA;
		$code      = self::clean_string( (string) $error->get_error_code(), self::MAX_CODE, $redacted, $truncated );
		$message   = self::clean_string( (string) $error->get_error_message(), self::MAX_MESSAGE, $redacted, $truncated );
		$data      = self::clean_data( $error->get_error_data(), $budget, 0, $redacted, $truncated );

		return array(
			'code'      => '' === $code ? null : $code,
			'message'   => $message,
			'data'      => $data,
			'redacted'  => $redacted,
			'truncated' => $truncated,
		);
	}

	/** @param string $message Adapter text; original WP_Error code already unavailable. @return array<string,mixed> */
	public static function from_adapter_message( $message ) {
		$redacted  = false;
		$truncated = false;
		return array(
			'code'      => null,
			'message'   => self::clean_string( $message, self::MAX_MESSAGE, $redacted, $truncated ),
			'data'      => null,
			'redacted'  => $redacted,
			'truncated' => $truncated,
		);
	}

	/**
	 * Redact only identified sensitive values, never map error codes to
	 * generic classifications. Signal any unavoidable truncation explicitly.
	 *
	 * @param string $value Original string.
	 * @param int $limit Byte cap.
	 * @param bool $redacted Whether sensitive content was found.
	 * @param bool $truncated Whether the byte cap was hit.
	 * @return string
	 */
	private static function clean_string( $value, $limit, &$redacted, &$truncated ) {
		$original = (string) $value;
		$text     = function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( $original, true ) : $original;
		if ( $text !== $original ) {
			$redacted = true;
		}
		// Strip non-printing control characters, not ordinary Unicode diagnostics.
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		$patterns = array(
			// Credentials in HTTP headers and URI userinfo.
			'/\b(Authorization|Proxy-Authorization|Cookie|Set-Cookie)\s*:\s*[^\r\n]+/i',
			'/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i',
			'~(https?://)[^\s/@:]+:[^\s/@]+@~i',
			// Keyed assignments in free-form exceptions and log messages.
			'/\b(?:[A-Za-z0-9]+_)*(?:password|passwd|pwd|access_token|refresh_token|client_secret|api_key|private_key|authorization|session_token|nonce|secret|token)\s*["\x27]?\s*[:=]\s*["\x27]?[^\s,;&\r\n"\x27}]+/i',
			// Absolute private filesystem paths. HTTP routes /wp-json/... stay visible.
			'~(?<![A-Za-z0-9])/(?:home|root|srv|var|etc|tmp|private|secret|opt|mnt|Users|www)/[^\s,;&"\x27<>)]*~i',
			'~\b[A-Z]:\\\\(?:[^\\\s]+\\\\)+[^\\\s]+~i',
		);
		foreach ( $patterns as $pattern ) {
			$next = preg_replace( $pattern, '[REDACTED]', $text );
			if ( null !== $next && $next !== $text ) {
				$redacted = true;
				$text     = $next;
			}
		}

		if ( strlen( $text ) > $limit ) {
			$truncated = true;
			$text      = substr( $text, 0, max( 0, $limit - 13 ) );
			while ( '' !== $text && 1 !== preg_match( '//u', $text ) ) {
				$text = substr( $text, 0, -1 );
			}
			$text .= '[TRUNCATED]';
		}
		return $text;
	}

	/**
	 * Preserve JSON data types and safe provider detail fields. Reject dangerous
	 * objects/resources rather than invoking third-party serializers.
	 *
	 * @param mixed $value WordPress error data.
	 * @param int $budget Overall textual byte budget.
	 * @param int $depth Current recursion depth.
	 * @param bool $redacted Flag.
	 * @param bool $truncated Flag.
	 * @return mixed
	 */
	private static function clean_data( $value, &$budget, $depth, &$redacted, &$truncated ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			if ( $budget <= 0 ) {
				$truncated = true;
				return '[TRUNCATED]';
			}
			$clean   = self::clean_string( $value, min( $budget, self::MAX_MESSAGE ), $redacted, $truncated );
			$budget -= strlen( $clean );
			return $clean;
		}
		if ( ! is_array( $value ) && ! $value instanceof \stdClass ) {
			$redacted = true;
			return '[REDACTED_UNSUPPORTED_TYPE]';
		}
		if ( $depth >= self::MAX_DEPTH ) {
			$truncated = true;
			return '[TRUNCATED]';
		}
		$output = array();
		$count  = 0;
		foreach ( (array) $value as $key => $child ) {
			if ( ++$count > self::MAX_ITEMS || $budget <= 0 ) {
				$truncated = true;
				break;
			}
			$key_text = is_int( $key ) ? $key : self::clean_string( $key, 128, $redacted, $truncated );
			$budget  -= is_int( $key_text ) ? 1 : strlen( $key_text );
			if ( is_string( $key ) && ( Metadata_Key_Policy::is_sensitive( $key ) ||
				1 === preg_match( '/^(?:path|file_path|absolute_path|cookie|set.cookie|authorization|credentials|private_key|dsn)$/i', $key ) ) ) {
				$output[ $key_text ] = '[REDACTED]';
				$redacted            = true;
			} else {
				$output[ $key_text ] = self::clean_data( $child, $budget, $depth + 1, $redacted, $truncated );
			}
		}
		return $output;
	}
}
