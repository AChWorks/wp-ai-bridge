<?php
/**
 * Verify that OAuth direct database access is limited to the exact Issue #80
 * named-lock coordination surface.
 *
 * @package WP_AI_Bridge
 */

/**
 * Validate the complete executable $wpdb surface in the OAuth store.
 *
 * Comments and whitespace are intentionally ignored. Every executable $wpdb
 * occurrence must therefore be either one of the fixed availability guards or
 * one of the exact named-lock expressions below.
 *
 * @param string $source PHP source to inspect.
 * @return string Empty string on success, otherwise a failure description.
 */
function wpai_issue80_check_oauth_store_db_confinement( $source ) {
	$ignored = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG );
	$tokens  = array();
	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], $ignored, true ) ) {
			continue;
		}
		$tokens[] = $token;
	}

	$text = static function ( $token ) {
		return is_array( $token ) ? $token[1] : $token;
	};
	$line = static function ( $token ) {
		return is_array( $token ) ? (int) $token[2] : 0;
	};
	$matching_paren = static function ( array $items, $start ) use ( $text ) {
		$depth = 0;
		$count = count( $items );
		for ( $i = $start; $i < $count; ++$i ) {
			$current = $text( $items[ $i ] );
			if ( '(' === $current ) {
				++$depth;
			} elseif ( ')' === $current ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return false;
	};
	$normalize = static function ( array $items, $start, $end ) use ( $text ) {
		$result = '';
		for ( $i = $start; $i <= $end; ++$i ) {
			$result .= $text( $items[ $i ] );
		}
		return $result;
	};

	$literal_string = static function ( $token ) {
		if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
			return null;
		}
		$raw = $token[1];
		if ( strlen( $raw ) < 2 ) {
			return null;
		}
		$value = substr( $raw, 1, -1 );
		return '"' === $raw[0] ? stripcslashes( $value ) : str_replace( "\\\\", "\\", $value );
	};

	$allowed_calls = array(
		"get_var|\$wpdb->get_var(\$wpdb->prepare('SELECT GET_LOCK(%s, %d)',\$name,self::REFRESH_LOCK_TIMEOUT))" => 1,
		"prepare|\$wpdb->prepare('SELECT GET_LOCK(%s, %d)',\$name,self::REFRESH_LOCK_TIMEOUT)"              => 1,
		"get_var|\$wpdb->get_var(\$wpdb->prepare('SELECT RELEASE_LOCK(%s)',\$name))"                         => 1,
		"prepare|\$wpdb->prepare('SELECT RELEASE_LOCK(%s)',\$name)"                                          => 1,
		"get_var|\$wpdb->get_var(\$wpdb->prepare('SELECT IS_USED_LOCK(%s)',\$name))"                         => 1,
		"prepare|\$wpdb->prepare('SELECT IS_USED_LOCK(%s)',\$name)"                                          => 1,
		"get_var|\$wpdb->get_var('SELECT CONNECTION_ID()')"                                                   => 1,
	);
	$observed_calls  = array();
	$global_count    = 0;
	$isset_count     = 0;
	$is_object_count = 0;
	$wpdb_count      = 0;
	$count           = count( $tokens );

	for ( $i = 0; $i < $count; ++$i ) {
		$token = $tokens[ $i ];

		// Alternate entry points would evade an exact $wpdb call inventory, so reject them too.
		if ( is_array( $token ) && T_VARIABLE === $token[0] && '$GLOBALS' === $token[1] ) {
			$open  = $tokens[ $i + 1 ] ?? null;
			$key   = $tokens[ $i + 2 ] ?? null;
			$close = $tokens[ $i + 3 ] ?? null;
			if ( '[' === $text( $open ) && 'wpdb' === $literal_string( $key ) && ']' === $text( $close ) ) {
				return 'direct $GLOBALS[\'wpdb\'] access is not permitted';
			}
		}
		if ( is_array( $token ) && T_STRING === $token[0] && 'wpdb' === strtolower( $token[1] ) ) {
			$previous = $tokens[ $i - 1 ] ?? null;
			if ( is_array( $previous ) && T_NEW === $previous[0] ) {
				return 'constructing a separate wpdb instance is not permitted';
			}
		}

		if ( ! is_array( $token ) || T_VARIABLE !== $token[0] || '$wpdb' !== $token[1] ) {
			continue;
		}
		++$wpdb_count;
		$next = $tokens[ $i + 1 ] ?? null;

		if ( is_array( $next ) && T_OBJECT_OPERATOR === $next[0] ) {
			$method = $tokens[ $i + 2 ] ?? null;
			$open   = $tokens[ $i + 3 ] ?? null;
			if ( ! is_array( $method ) || T_STRING !== $method[0] || '(' !== $text( $open ) ) {
				return 'dynamic or non-call $wpdb member access near line ' . $line( $token );
			}
			$end = $matching_paren( $tokens, $i + 3 );
			if ( false === $end ) {
				return 'unterminated $wpdb call near line ' . $line( $token );
			}
			$method_name = strtolower( $method[1] );
			$expression  = $normalize( $tokens, $i, $end );
			$key         = $method_name . '|' . $expression;
			if ( ! array_key_exists( $key, $allowed_calls ) ) {
				return 'unapproved $wpdb call near line ' . $line( $token ) . ': ' . $expression;
			}
			$observed_calls[ $key ] = ( $observed_calls[ $key ] ?? 0 ) + 1;
			continue;
		}

		$previous = $tokens[ $i - 1 ] ?? null;
		if ( is_array( $previous ) && T_GLOBAL === $previous[0] ) {
			++$global_count;
			continue;
		}

		$before      = $tokens[ $i - 1 ] ?? null;
		$before_prev = $tokens[ $i - 2 ] ?? null;
		$after       = $tokens[ $i + 1 ] ?? null;
		if ( '(' === $text( $before ) && ')' === $text( $after ) && is_array( $before_prev ) ) {
			if ( T_ISSET === $before_prev[0] ) {
				++$isset_count;
				continue;
			}
			if ( T_STRING === $before_prev[0] && 'is_object' === strtolower( $before_prev[1] ) ) {
				++$is_object_count;
				continue;
			}
		}

		return '$wpdb used outside the exact Issue #80 confinement surface near line ' . $line( $token );
	}

	foreach ( $allowed_calls as $key => $expected ) {
		$actual = $observed_calls[ $key ] ?? 0;
		if ( $expected !== $actual ) {
			return "advisory-lock expression inventory changed: expected {$expected}, observed {$actual}: {$key}";
		}
	}
	if ( count( $observed_calls ) !== count( $allowed_calls ) ) {
		return 'unexpected advisory-lock call shape';
	}
	if ( 3 !== $global_count || 3 !== $isset_count || 3 !== $is_object_count || 16 !== $wpdb_count ) {
		return 'wpdb usage inventory changed outside the exact Issue #80 contract';
	}
	return '';
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	if ( 2 !== $argc ) {
		fwrite( STDERR, "Usage: php bin/check-oauth-store-db-confinement.php <oauth-store.php>\n" );
		exit( 2 );
	}
	$source = file_get_contents( $argv[1] );
	if ( false === $source ) {
		fwrite( STDERR, "ERROR: could not read OAuth store source.\n" );
		exit( 2 );
	}
	$error = wpai_issue80_check_oauth_store_db_confinement( $source );
	if ( '' !== $error ) {
		fwrite( STDERR, 'ERROR: OAuth store DB confinement failed: ' . $error . "\n" );
		exit( 1 );
	}
	echo "PASS: Issue #80 OAuth DB advisory-lock confinement.\n";
}
