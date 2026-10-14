<?php
/**
 * Memory-bounded PHP source syntax check without subprocess or code execution.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * token_get_all(TOKEN_PARSE) materializes potentially one PHP token per input
 * byte plus parser state. Never call it before establishing a conservative
 * upper bound on the memory required by that operation.
 */
final class Source_PHP_Syntax_Validator {
	/**
	 * @param string $bytes Exact candidate; already subject to source budget.
	 * @param string $verified_path Internal source path, intentionally unused:
	 *                              no client path or subprocess is permitted.
	 * @return true|WP_Error
	 */
	public function validate( $bytes, $verified_path = '' ) {
		if ( ! is_string( $bytes ) ) {
			return new WP_Error( 'source_php_validation_capacity', __( 'PHP source cannot be validated inside the available bounded memory budget.', 'wp-ai-bridge' ) );
		}
		// An empty PHP file contains no tokens, is valid PHP syntax and was
		// accepted by the original Source Editing API. It requires no parser
		// allocation, including when the host memory limit is unknown.
		if ( '' === $bytes ) {
			return true;
		}
		$limit = Operation_Content_Limits::effective_memory_bytes();
		if ( $limit < 1 ) {
			return new WP_Error( 'source_php_validation_capacity', __( 'PHP source cannot be validated inside the available bounded memory budget.', 'wp-ai-bridge' ) );
		}
		// An initial, syntactically unambiguous block comment is one lexical
		// token regardless of length. Replacing only that exact comment with
		// whitespace preserves PHP parser syntax while avoiding an overly
		// conservative per-byte token estimate for a large license/header.
		$syntax = $this->without_initial_block_comment( $bytes );
		$free   = $limit - memory_get_usage( true ) - ( 8 * Operation_Content_Limits::MIB );
		// Worst case: every byte is a token, with a conservative 512-byte
		// parser/token/array allowance per byte plus the reserved host margin.
		// This rejects dense input before any potentially unbounded token
		// array is allocated; a host may raise its real memory budget instead.
		if ( $free < 0 || strlen( $syntax ) > intdiv( $free, 512 ) ) {
			return new WP_Error( 'source_php_validation_capacity', __( 'PHP source cannot be validated inside the available bounded memory budget.', 'wp-ai-bridge' ) );
		}
		try {
			token_get_all( $syntax, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			return new WP_Error( 'source_php_syntax_invalid', __( 'The PHP source candidate has invalid syntax and was not written.', 'wp-ai-bridge' ) );
		}
		return true;
	}

	/**
	 * We recognize ONLY a block comment immediately after a <?php opening
	 * tag and whitespace. No general PHP lexer reimplementation or stripping
	 * of arbitrary /* within strings/heredocs is attempted.
	 *
	 * @param string $bytes Exact original source.
	 * @return string Semantically syntax-equivalent, or unchanged input.
	 */
	private function without_initial_block_comment( $bytes ) {
		if ( ! preg_match( '/\A<\?php[ \t\r\n]+/D', $bytes, $start ) ) {
			return $bytes;
		}
		$begin = strlen( $start[0] );
		if ( '/*' !== substr( $bytes, $begin, 2 ) ) {
			return $bytes;
		}
		$end = strpos( $bytes, '*/', $begin + 2 );
		if ( false === $end ) {
			return $bytes;
		}
		return substr( $bytes, 0, $begin ) . ' ' . substr( $bytes, $end + 2 );
	}
}
