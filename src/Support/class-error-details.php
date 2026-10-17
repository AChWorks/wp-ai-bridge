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
		$fits      = true;
		$data      = self::clean_data( $error->get_error_data(), $budget, 0, $redacted, $truncated, $fits );
		if ( ! $fits ) {
			$truncated = true;
			$data      = null;
		}

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
		$printable = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		if ( $printable !== $text ) {
			$redacted = true;
			$text     = $printable;
		}

		// Keep the useful header/key/scheme labels, and replace only values
		// confirmed to carry secrets. Private paths are masked as path values.
		$patterns = array(
			'/\b(Authorization|Proxy-Authorization)\s*:\s*((?:[A-Za-z]+\s+)?)[^\s,;&\r\n]+/i'
				=> '$1: $2[REDACTED]',
			'/\b(Cookie|Set-Cookie)\s*:\s*[^\r\n]+/i'
				=> '$1: [REDACTED]',
			// Quoted authorization keys in JSON-like error text.
			'/(["\x27](?:authorization|proxy-authorization)["\x27]\s*[:=]\s*["\x27]?)(?:[A-Za-z]+\s+)?[^\s,;&\r\n"\x27}]+/i'
				=> '$1[REDACTED]',
			'/\b(Bearer)\s+[A-Za-z0-9._~+\/=\-]+/i'
				=> '$1 [REDACTED]',
			'~(https?://)[^\s/@:]+:[^\s/@]+@~i'
				=> '$1[REDACTED]@',
			'/\b((?:[A-Za-z0-9]+_)*(?:password|passwd|pwd|access_token|refresh_token|client_secret|api_key|private_key|session_token|nonce|secret|token)\s*["\x27]?\s*[:=]\s*["\x27]?)[^\s,;&\r\n"\x27}]+/i'
				=> '$1[REDACTED]',
			'~(?<![A-Za-z0-9])/(?:home|root|srv|var|etc|tmp|private|secret|opt|mnt|Users|www)/[^\s,;&"\x27<>)]*~i'
				=> '[REDACTED]',
			'~\b[A-Z]:\\\\(?:[^\\\s]+\\\\)+[^\\\s]+~i'
				=> '[REDACTED]',
		);
		foreach ( $patterns as $pattern => $replacement ) {
			$next = preg_replace( $pattern, $replacement, $text );
			if ( null !== $next && $next !== $text ) {
				$redacted = true;
				$text     = $next;
			}
		}

		// Diagnostic-only assignments: mask a full semicolon-separated Cookie
		// sequence. Retain private-path labels (masked) and independently
		// identifiable HTTP status prose without trusting Cookie pairs.
		$cookie_labels = '(?:cookie[_-]?header|request[_-]?cookie|session[_-]?cookie|set[_-]?cookie)';
		$path_labels   = '(?:(?:private|absolute|local|server|file[_-]?system|physical|full|secret)'
			. '[_-]?(?:file[_-]?)?path|file[_-]?path)';
		// Use the same cookie grammar for the first and later pairs.
		// Quoted provider/JSON text can contain escaped quote characters:
		// never treat an escaped quote as the end of a Cookie field.
		$cookie_name          = '[^\s=;,\r\n"]+';
		$quoted_double        = '"(?:\\\\.|[^"\\\\\r\n])*"';
		$quoted_single        = '\x27(?:\\\\.|[^\x27\\\\\r\n])*\x27';
		$unquoted_value       = '(?:\\\\.|[^\s;,\r\n"\\\\])*';
		$cookie_value         = '(?:' . $quoted_double . '|' . $quoted_single . '|' . $unquoted_value . ')';
		$cookie_pair          = $cookie_name . '\s*=\s*' . $cookie_value;
		$cookie_assignment    = '/\b(' . $cookie_labels . ')(["\x27]?\s*[:=]\s*)'
			. '(' . $quoted_double . '|' . $quoted_single . '|' . $cookie_pair
			. '(?:\s*;\s*' . $cookie_pair . ')*)/i';
		$path_assignment      = '/\b(' . $path_labels . ')(["\x27]?\s*[:=]\s*)'
			. '("[^"\r\n]*"|\x27[^\x27\r\n]*\x27|[^\s,;&\r\n"\x27}]+)/i';
		$mask_assignment      = static function ( $matches ) {
			$value = $matches[3];
			$first = substr( $value, 0, 1 );
			$quote = '"' === $first || "'" === $first ? $first : '';
			return $matches[1] . $matches[2] . $quote . '[REDACTED]' . $quote;
		};
		$mask_cookie          = static function ( $matches ) use ( $mask_assignment, $path_labels ) {
			$masked = $mask_assignment( $matches );
			$value  = $matches[3];
			if ( '"' === substr( $value, 0, 1 ) || "'" === substr( $value, 0, 1 ) ) {
				return $masked;
			}
			// The cookie protocol cannot distinguish a cookie named "status"
			// from an independent status field. Never restore ambiguous
			// cookie values; only retain diagnostic private-path labels
			// with their values masked. Separate "response HTTP 403"
			// prose remains outside the matched cookie assignment.
			preg_match_all(
				'/;\s*(' . $path_labels . ')\s*[:=]\s*([^;\s,]+)/i',
				$value,
				$fields,
				PREG_SET_ORDER
			);
			foreach ( $fields as $field ) {
				$masked .= '; ' . $field[1] . '=[REDACTED]';
			}
			return $masked;
		};
		$assignment_callbacks = array(
			$cookie_assignment => $mask_cookie,
			$path_assignment   => $mask_assignment,
		);
		foreach ( $assignment_callbacks as $assignment => $callback ) {
			$next = preg_replace_callback( $assignment, $callback, $text );
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
	 * Identify only diagnostic-specific credentials, cookie material and
	 * explicitly private filesystem path fields. Do not widen the shared
	 * Metadata_Key_Policy used by normal provider metadata operations.
	 *
	 * @param string $key Original structured error field name.
	 * @return bool
	 */
	private static function is_sensitive_diagnostic_key( $key ) {
		if ( Metadata_Key_Policy::is_sensitive( $key ) ) {
			return true;
		}
		$bounded    = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '_', (string) $key );
		$normalized = trim( strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $bounded ) ), '_' );

		$exact_sensitive = array(
			'path',
			'file_path',
			'absolute_path',
			'cookie',
			'set_cookie',
			'authorization',
			'credentials',
			'private_key',
			'dsn',
			'token',
			'nonce',
			'bearer',
			'session_id',
			'session_key',
			'oauth_code',
			'authorization_code',
		);
		if ( in_array( $normalized, $exact_sensitive, true ) ) {
			return true;
		}
		return 1 === preg_match( '/(?:^|_)(?:cookie|cookies)(?:_header|_value|_values|_string|_jar)?$/', $normalized ) ||
			1 === preg_match( '/^(?:private|absolute|local|server|filesystem|file_system|physical|full|secret)_(?:file_)?path$/', $normalized ) ||
			1 === preg_match( '/(?:^|_)(?:authorization|proxy_authorization|auth)_header$/', $normalized );
	}

	/**
	 * Reserve the exact JSON bytes of a scalar; never invoke serializers on
	 * provider objects or inspect beyond the already bounded data tree.
	 *
	 * @param mixed $value Safe JSON scalar.
	 * @param int $budget Remaining JSON bytes.
	 * @param bool $fits Whether the value fits.
	 * @return mixed
	 */
	private static function reserve_scalar( $value, &$budget, &$fits ) {
		$json = wp_json_encode( $value );
		if ( false === $json || strlen( $json ) > $budget ) {
			$fits = false;
			return null;
		}
		$budget -= strlen( $json );
		return $value;
	}

	/**
	 * Preserve safe text by clipping only as much as is needed for the actual
	 * JSON-escaped bytes. A short budget can omit this field; the caller then
	 * marks the data incomplete and retains a valid JSON container.
	 *
	 * @param string $value Original string.
	 * @param int $budget Remaining JSON bytes.
	 * @param bool $redacted Sensitive or invalid fragments removed.
	 * @param bool $truncated Size-related loss.
	 * @param bool $fits Whether the value fits.
	 * @return string|null
	 */
	private static function bounded_data_string( $value, &$budget, &$redacted, &$truncated, &$fits ) {
		$clean   = self::clean_string( $value, self::MAX_MESSAGE, $redacted, $truncated );
		$encoded = wp_json_encode( $clean );
		if ( false === $encoded ) {
			$redacted = true;
			$clean    = '[REDACTED_INVALID_UTF8]';
			$encoded  = wp_json_encode( $clean );
		}
		if ( strlen( $encoded ) > $budget ) {
			$truncated = true;
			$suffix    = '[TRUNCATED]';
			if ( strlen( wp_json_encode( $suffix ) ) > $budget ) {
				$fits = false;
				return null;
			}
			// Binary-search the longest UTF-8-safe prefix whose encoded JSON
			// representation plus the explicit marker fits the remaining cap.
			$low  = 0;
			$high = strlen( $clean );
			$best = $suffix;
			while ( $low <= $high ) {
				$mid    = (int) floor( ( $low + $high ) / 2 );
				$prefix = substr( $clean, 0, $mid );
				while ( '' !== $prefix && 1 !== preg_match( '//u', $prefix ) ) {
					$prefix = substr( $prefix, 0, -1 );
				}
				$candidate = $prefix . $suffix;
				if ( strlen( wp_json_encode( $candidate ) ) <= $budget ) {
					$best = $candidate;
					$low  = $mid + 1;
				} else {
					$high = $mid - 1;
				}
			}
			$clean = $best;
		}
		return self::reserve_scalar( $clean, $budget, $fits );
	}

	/** @param array<mixed> $value PHP array. @return bool Whether JSON encodes it as a list. */
	private static function is_list_array( $value ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}
		$expected = 0;
		foreach ( $value as $key => $ignored ) {
			if ( $key !== $expected++ ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Preserve JSON-safe types and diagnostic facts within an exact global
	 * serialized-data byte cap, including keys, separators, numbers and braces.
	 * Truncation drops only the unfittable tail; any loss is signaled.
	 *
	 * @param mixed $value WordPress error data.
	 * @param int $budget Remaining JSON bytes.
	 * @param int $depth Current recursion depth.
	 * @param bool $redacted Sensitive or unsupported fragments removed.
	 * @param bool $truncated Size-related loss or non-JSON number conversion.
	 * @param bool $fits Whether this subtree fits.
	 * @return mixed
	 */
	private static function clean_data( $value, &$budget, $depth, &$redacted, &$truncated, &$fits ) {
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			$truncated = true;
			return self::reserve_scalar( '[NON_FINITE_NUMBER]', $budget, $fits );
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return self::reserve_scalar( $value, $budget, $fits );
		}
		if ( is_string( $value ) ) {
			return self::bounded_data_string( $value, $budget, $redacted, $truncated, $fits );
		}
		if ( ! is_array( $value ) && ! $value instanceof \stdClass ) {
			$redacted = true;
			return self::reserve_scalar( '[REDACTED_UNSUPPORTED_TYPE]', $budget, $fits );
		}
		if ( $depth >= self::MAX_DEPTH ) {
			$truncated = true;
			return self::reserve_scalar( '[TRUNCATED]', $budget, $fits );
		}
		// Every JSON object/array costs exactly two enclosing bytes.
		if ( $budget < 2 ) {
			$fits = false;
			return null;
		}
		$budget -= 2;
		$output  = $value instanceof \stdClass ? new \stdClass() : array();
		$is_list = is_array( $value ) && self::is_list_array( $value );

		$count = 0;
		foreach ( (array) $value as $key => $child ) {
			if ( $count >= self::MAX_ITEMS ) {
				$truncated = true;
				break;
			}
			$key_text = is_int( $key ) ? $key : self::clean_string( $key, 128, $redacted, $truncated );
			$overhead = 0 === $count ? 0 : 1; // JSON comma.
			if ( ! $is_list ) {
				$overhead += strlen( wp_json_encode( (string) $key_text ) ) + 1; // Key and colon.
			}
			if ( $budget < $overhead + 2 ) {
				$truncated = true;
				break;
			}
			$child_budget = $budget - $overhead;
			$child_fits   = true;
			if ( is_string( $key ) && self::is_sensitive_diagnostic_key( $key ) ) {
				$redacted = true;
				$clean    = self::reserve_scalar( '[REDACTED]', $child_budget, $child_fits );
			} else {
				$clean = self::clean_data( $child, $child_budget, $depth + 1, $redacted, $truncated, $child_fits );
			}
			if ( ! $child_fits ) {
				$truncated = true;
				break;
			}
			$budget = $child_budget;
			if ( $output instanceof \stdClass ) {
				$output->{ (string) $key_text} = $clean;
			} else {
				$output[ $key_text ] = $clean;
			}
			++$count;
		}
		// An original numeric-key map is JSON {}, not []. Dropping its
		// sparse tail must not turn it into a sequential PHP array on the
		// wire, including when no entries could be retained.
		if ( is_array( $value ) && ! $is_list && self::is_list_array( $output ) ) {
			return (object) $output;
		}
		return $output;
	}
}
