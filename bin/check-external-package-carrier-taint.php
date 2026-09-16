<?php
/**
 * Fail closed when protected callable identities are transported through local
 * variables, array offsets, or object/static properties into a PHP call.
 *
 * This development-only check supplements bin/static-safety-check.sh. It is
 * intentionally conservative at the root-container level and never executes
 * the analyzed source.
 */

final class WP_AI_Bridge_External_Package_Carrier_Taint_Exception extends RuntimeException {}

final class WP_AI_Bridge_External_Package_Carrier_Taint {
	/**
	 * Assert that one PHP source string contains no protected callable carrier.
	 *
	 * @param string $source PHP source.
	 * @return void
	 */
	public static function assert_source( $source ) {
		$ignored_tokens = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG );
		$name_tokens    = array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE );
		$protected_callable_names = array_fill_keys(
			array(
				'shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen', 'eval',
				'file_put_contents', 'fopen', 'fwrite', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir',
				'curl_exec', 'curl_init', 'fsockopen', 'stream_socket_client', 'file_get_contents', 'wp_tempnam',
				'wp_remote_request', 'wp_remote_get', 'wp_remote_post', 'wp_remote_head',
				'wp_safe_remote_request', 'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_head',
				'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array',
			),
			true
		);

		$tokens = array();
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], $ignored_tokens, true ) ) {
				continue;
			}
			$tokens[] = $token;
		}

		$token_text = static function ( $token ) {
			return is_array( $token ) ? $token[1] : $token;
		};
		$literal_value = static function ( $token ) {
			$text  = $token[1];
			$value = substr( $text, 1, -1 );
			return 34 === ord( $text[0] ) ? stripcslashes( $value ) : str_replace( '\\\\', '\\', $value );
		};
		$slice_has_protected_callable = static function ( $slice ) use ( $protected_callable_names, $literal_value ) {
			$values = array();
			foreach ( $slice as $token ) {
				if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$values[] = strtolower( $literal_value( $token ) );
				}
			}
			$count = count( $values );
			for ( $i = 0; $i < $count; ++$i ) {
				$joined = '';
				for ( $j = $i; $j < $count && $j < $i + 4; ++$j ) {
					$joined .= $values[ $j ];
					if ( isset( $protected_callable_names[ $joined ] ) ) {
						return true;
					}
				}
			}
			return false;
		};
		$skip_balanced = static function ( $all_tokens, $start, $open, $close ) use ( $token_text ) {
			$depth = 0;
			$count = count( $all_tokens );
			for ( $i = $start; $i < $count; ++$i ) {
				$text = $token_text( $all_tokens[ $i ] );
				if ( $open === $text ) {
					++$depth;
				} elseif ( $close === $text ) {
					--$depth;
					if ( 0 === $depth ) {
						return $i + 1;
					}
				}
			}
			return null;
		};
		$assignment_rhs_start = static function ( $all_tokens, $start ) use ( $token_text, $skip_balanced ) {
			$count = count( $all_tokens );
			if ( $start >= $count || ! is_array( $all_tokens[ $start ] ) || T_VARIABLE !== $all_tokens[ $start ][0] ) {
				return null;
			}
			if ( $start > 0 && is_array( $all_tokens[ $start - 1 ] ) ) {
				$previous_id = $all_tokens[ $start - 1 ][0];
				if ( T_OBJECT_OPERATOR === $previous_id || T_DOUBLE_COLON === $previous_id || ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $previous_id ) ) {
					return null;
				}
			}

			$assignment_operators = array( '=', '.=', '+=', '-=', '*=', '/=', '%=', '**=', '&=', '|=', '^=', '<<=', '>>=', '??=' );
			for ( $i = $start + 1; $i < $count; ) {
				$part = $all_tokens[ $i ];
				$text = $token_text( $part );
				if ( in_array( $text, $assignment_operators, true ) ) {
					return $i + 1;
				}
				if ( '[' === $text ) {
					$i = $skip_balanced( $all_tokens, $i, '[', ']' );
					if ( null === $i ) {
						return null;
					}
					continue;
				}
				if ( is_array( $part ) && ( T_OBJECT_OPERATOR === $part[0] || T_DOUBLE_COLON === $part[0] ) ) {
					++$i;
					if ( $i >= $count ) {
						return null;
					}
					$member      = $all_tokens[ $i ];
					$member_text = $token_text( $member );
					if ( '{' === $member_text ) {
						$i = $skip_balanced( $all_tokens, $i, '{', '}' );
						if ( null === $i ) {
							return null;
						}
						continue;
					}
					if ( is_array( $member ) && ( T_STRING === $member[0] || T_VARIABLE === $member[0] ) ) {
						++$i;
						continue;
					}
					return null;
				}
				return null;
			}
			return null;
		};

		$assignments = array();
		$token_count = count( $tokens );
		for ( $i = 0; $i < $token_count; ++$i ) {
			$rhs_start = $assignment_rhs_start( $tokens, $i );
			if ( null === $rhs_start ) {
				continue;
			}
			$depth      = array( '(' => 0, '[' => 0, '{' => 0 );
			$expression = array();
			for ( $j = $rhs_start; $j < $token_count; ++$j ) {
				$part      = $tokens[ $j ];
				$part_text = $token_text( $part );
				if ( ';' === $part_text && 0 === $depth['('] && 0 === $depth['['] && 0 === $depth['{'] ) {
					break;
				}
				if ( '(' === $part_text ) {
					++$depth['('];
				} elseif ( ')' === $part_text ) {
					--$depth['('];
				} elseif ( '[' === $part_text ) {
					++$depth['['];
				} elseif ( ']' === $part_text ) {
					--$depth['['];
				} elseif ( '{' === $part_text ) {
					++$depth['{'];
				} elseif ( '}' === $part_text ) {
					--$depth['{'];
				}
				$expression[] = $part;
			}
			$assignments[] = array( $tokens[ $i ][1], $expression );
		}

		$tainted_variables = array();
		do {
			$changed = false;
			foreach ( $assignments as $assignment ) {
				$variable   = $assignment[0];
				$expression = $assignment[1];
				if ( isset( $tainted_variables[ $variable ] ) ) {
					continue;
				}
				$tainted = $slice_has_protected_callable( $expression );
				if ( ! $tainted ) {
					foreach ( $expression as $part ) {
						if ( is_array( $part ) && T_VARIABLE === $part[0] && isset( $tainted_variables[ $part[1] ] ) ) {
							$tainted = true;
							break;
						}
					}
				}
				if ( $tainted ) {
					$tainted_variables[ $variable ] = true;
					$changed = true;
				}
			}
		} while ( $changed );

		$parentheses = array();
		for ( $i = 0; $i < $token_count; ++$i ) {
			$token = $tokens[ $i ];
			$text  = $token_text( $token );
			if ( '(' === $text ) {
				$previous        = $i > 0 ? $tokens[ $i - 1 ] : null;
				$before_previous = $i > 1 ? $tokens[ $i - 2 ] : null;
				$is_call         = false;
				if ( is_array( $previous ) && in_array( $previous[0], $name_tokens, true ) ) {
					$before_id = is_array( $before_previous ) ? $before_previous[0] : null;
					$is_call   = T_FUNCTION !== $before_id;
				}
				$parentheses[] = array( 'start' => $i, 'call' => $is_call );
			} elseif ( ')' === $text && ! empty( $parentheses ) ) {
				$frame = array_pop( $parentheses );
				if ( ! $frame['call'] ) {
					continue;
				}
				$arguments = array_slice( $tokens, $frame['start'] + 1, $i - $frame['start'] - 1 );
				foreach ( $arguments as $argument ) {
					if ( is_array( $argument ) && T_VARIABLE === $argument[0] && isset( $tainted_variables[ $argument[1] ] ) ) {
						throw new WP_AI_Bridge_External_Package_Carrier_Taint_Exception( 'Protected callable identity can reach a call through a local variable/container/property carrier.' );
					}
				}
			}
		}
	}
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	if ( ! isset( $argv[1] ) ) {
		fwrite( STDERR, "ERROR: external package provider path is required.\n" );
		exit( 2 );
	}
	$source = file_get_contents( $argv[1] );
	if ( false === $source ) {
		fwrite( STDERR, "ERROR: could not read external package provider.\n" );
		exit( 2 );
	}
	try {
		WP_AI_Bridge_External_Package_Carrier_Taint::assert_source( $source );
	} catch ( WP_AI_Bridge_External_Package_Carrier_Taint_Exception $error ) {
		fwrite( STDERR, 'ERROR: ' . $error->getMessage() . "\n" );
		exit( 1 );
	}
}
