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

	$class_name_tokens = array(
		T_STRING,
		T_NAME_FULLY_QUALIFIED,
		T_NAME_QUALIFIED,
		T_NAME_RELATIVE,
	);
	$normalized_class_name = static function ( $token ) use ( $class_name_tokens ) {
		if ( ! is_array( $token ) || ! in_array( $token[0], $class_name_tokens, true ) ) {
			return null;
		}
		return strtolower( (string) $token[1] );
	};
	$terminal_class_name = static function ( $token ) use ( $class_name_tokens ) {
		if ( ! is_array( $token ) || ! in_array( $token[0], $class_name_tokens, true ) ) {
			return null;
		}
		$name  = ltrim( (string) $token[1], '\\' );
		$parts = preg_split( '/\\\\+/', $name );
		if ( false === $parts || empty( $parts ) ) {
			return null;
		}
		return strtolower( (string) end( $parts ) );
	};
	$allowed_constructor_names = array(
		'\\invalidargumentexception' => true,
		'\\wp_error'                 => true,
	);

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
		$next  = $tokens[ $i + 1 ] ?? null;

		// Every object call in this fixed-purpose store must be directly bound to the store
		// itself or the exact $wpdb surface checked below. This blocks database calls through
		// aliases, factories, class aliases, subclasses, reflection-produced handles, or other
		// alternate receivers without trying to enumerate every acquisition spelling.
		$is_object_operator = is_array( $token ) && (
			T_OBJECT_OPERATOR === $token[0] ||
			( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $token[0] )
		);
		if ( $is_object_operator ) {
			$receiver = $tokens[ $i - 1 ] ?? null;
			if (
				! is_array( $receiver ) ||
				T_VARIABLE !== $receiver[0] ||
				! in_array( $receiver[1], array( '$this', '$wpdb' ), true )
			) {
				return 'object access outside $this/$wpdb confinement is not permitted near line ' . $line( $token );
			}
		}

		// These local-symbol helpers can recover the imported global $wpdb by name without an
		// executable T_VARIABLE("$wpdb") occurrence. They are unnecessary in this store.
		if ( is_array( $token ) && in_array( $token[0], $class_name_tokens, true ) && '(' === $text( $next ) ) {
			$call_name = $terminal_class_name( $token );
			if ( in_array( $call_name, array( 'get_defined_vars', 'compact' ), true ) ) {
				return 'indirect local-symbol lookup is not permitted in the OAuth store';
			}
		}
		// The OAuth store has no legitimate reason to use the global symbol table. Reject the
		// entire entry point so literal and computed $GLOBALS keys cannot create an untracked
		// database handle.
		if ( is_array( $token ) && T_VARIABLE === $token[0] && '$GLOBALS' === $token[1] ) {
			return '$GLOBALS access is not permitted in the OAuth store';
		}

		// Variable-variable syntax can manufacture a $wpdb reference without an executable
		// T_VARIABLE("$wpdb") token. This fixed-purpose store does not require that mechanism.
		if ( '$' === $text( $token ) ) {
			if ( ( is_array( $next ) && T_VARIABLE === $next[0] ) || '{' === $text( $next ) ) {
				return 'dynamic variable-variable access is not permitted in the OAuth store';
			}
		}

		// Constructor targets are fail-closed. The current OAuth store legitimately constructs
		// only WP_Error and InvalidArgumentException. Any other static class, dynamic target, or
		// anonymous class requires an explicit confinement-contract update before it can ship.
		if ( is_array( $token ) && T_NEW === $token[0] ) {
			$class_token = $next;
			$class_name  = $normalized_class_name( $class_token );
			if ( null === $class_name ) {
				return 'dynamic or anonymous class construction is not permitted in the OAuth store';
			}
			if ( 'wpdb' === $terminal_class_name( $class_token ) ) {
				return 'constructing a separate wpdb instance is not permitted';
			}
			if ( ! isset( $allowed_constructor_names[ $class_name ] ) ) {
				return 'unapproved class construction is not permitted in the OAuth store: ' . $class_name;
			}
		}

		if ( ! is_array( $token ) || T_VARIABLE !== $token[0] || '$wpdb' !== $token[1] ) {
			continue;
		}
		++$wpdb_count;

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
