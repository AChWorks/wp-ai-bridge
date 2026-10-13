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
	const RESPONSE_BYTES = Payload_Settings::DEFAULT_RESPONSE_KIB * 1024;

	/**
	 * A safe upper bound for a single UTF-8 text part within the response envelope.
	 */
	const TEXT_WINDOW_BYTES = Payload_Settings::DEFAULT_WINDOW_KIB * 1024;

	/**
	 * Space for JSON-RPC, tool-result field names, request ID and routing headers.
	 * This is a per-response safety reserve, never an aggregate file-size cap.
	 */
	const MCP_ENVELOPE_HEADROOM_BYTES = 2048;

	/**
	 * Effective per-response encoded-JSON limit, for the current WordPress site.
	 * A larger administrator maximum does not allocate memory until used.
	 *
	 * @return int
	 */
	public static function response_limit() {
		$settings = new Payload_Settings();
		return $settings->all()[ Payload_Settings::RESPONSE_KEY ] * 1024;
	}

	/**
	 * Maximum requested bytes of one source UTF-8 text window. The actual
	 * encoded reply may need a shorter adaptive window to fit the response.
	 *
	 * @return int
	 */
	public static function window_limit() {
		$settings = new Payload_Settings();
		return $settings->all()[ Payload_Settings::WINDOW_KEY ] * 1024;
	}

	/**
	 * Project the official MCP Adapter generic tool result bytes, including
	 * its two representations of the same result: structuredContent and a
	 * JSON-encoded text block. A raw Ability result alone is NOT the final
	 * wire envelope. This projection covers the normal 2025-11-25 Adapter
	 * route and includes a finite extra JSON-RPC/metadata safety allowance.
	 *
	 * @param mixed $value JSON-safe WordPress Bridge Ability result.
	 * @return int Conservative encoded result size, or PHP_INT_MAX on failure.
	 */
	public static function projected_mcp_bytes( $value ) {
		$structured = wp_json_encode(
			array(
				'success' => true,
				'data'    => $value,
			)
		);
		if ( ! is_string( $structured ) ) {
			return PHP_INT_MAX;
		}
		// ToolsHandler encodes the structured JSON into a text content block
		// as well, escaping the backslashes/quotes for the outer JSON string.
		$text = wp_json_encode( $structured );
		if ( ! is_string( $text ) ) {
			return PHP_INT_MAX;
		}
		return strlen( $structured ) + strlen( $text ) + self::MCP_ENVELOPE_HEADROOM_BYTES;
	}

	/**
	 * Constrain the conservatively projected final MCP result, rather than
	 * claiming a smaller raw WordPress JSON object is the full wire payload.
	 * External clients/Gateways may still impose their own smaller limits.
	 *
	 * @param mixed $value JSON-safe Ability result.
	 * @return bool Whether the projected result fits this site's MCP budget.
	 */
	public static function fits( $value ) {
		return self::projected_mcp_bytes( $value ) <= self::response_limit();
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
			! is_int( $limit ) || $limit < 1 || $limit > self::window_limit() ) {
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
