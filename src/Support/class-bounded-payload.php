<?php
/**
 * Shared MCP-friendly response and UTF-8 content window helpers.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Bounded responses without imposing a maximum size on stored WordPress content.
 *
 * Binary files use the appropriate WordPress streaming/upload APIs, not this helper.
 */
final class Bounded_Payload {
	/**
	 * Leave headroom for MCP JSON-RPC envelope and Gateway transport metadata.
	 */
	const RESPONSE_BYTES = 32768;

	/**
	 * A safe upper bound for a single UTF-8 text part within the response envelope.
	 */
	const TEXT_WINDOW_BYTES = 16384;

	/**
	 * Check the actual serialized JSON byte count, not only PHP string lengths.
	 *
	 * @param mixed $value JSON-safe value.
	 * @return bool Whether the result fits the normal downstream MCP envelope.
	 */
	public static function fits( $value ) {
		// Default JSON escaping is intentionally conservative for Unicode-heavy payloads.
		$json = wp_json_encode( $value );
		return is_string( $json ) && strlen( $json ) <= self::RESPONSE_BYTES;
	}

	/**
	 * Return a byte-offset UTF-8 window that can be joined exactly with later windows.
	 *
	 * @param string $content Entire validated UTF-8 content value.
	 * @param int    $offset  Byte offset from the start of the exact content.
	 * @param int    $limit   Maximum bytes requested.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function text_window( $content, $offset, $limit ) {
		$content = (string) $content;
		$total   = strlen( $content );

		if ( ! is_int( $offset ) || $offset < 0 || $offset > $total ||
			! is_int( $limit ) || $limit < 1 || $limit > self::TEXT_WINDOW_BYTES ) {
			return new WP_Error( 'invalid_content_window', __( 'The requested content window is outside the supported bounds.', 'wp-ai-bridge' ) );
		}
		if ( $offset < $total && 0x80 === ( ord( $content[ $offset ] ) & 0xC0 ) ) {
			return new WP_Error( 'invalid_content_window', __( 'The content window must start at a UTF-8 character boundary.', 'wp-ai-bridge' ) );
		}

		$chunk = substr( $content, $offset, $limit );
		// Complete the JSON-safe UTF-8 prefix without consuming part of the next character.
		while ( '' !== $chunk && 1 !== preg_match( '//u', $chunk ) ) {
			$chunk = substr( $chunk, 0, -1 );
		}
		if ( '' === $chunk && $offset < $total ) {
			return new WP_Error( 'invalid_content_encoding', __( 'The selected content cannot be represented as a UTF-8 window.', 'wp-ai-bridge' ) );
		}

		$next = $offset + strlen( $chunk );
		return array(
			'content'     => $chunk,
			'total_bytes' => $total,
			'next_offset' => $next,
			'complete'    => $next === $total,
		);
	}
}
