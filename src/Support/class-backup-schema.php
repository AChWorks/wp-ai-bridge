<?php
/**
 * Conservative native CREATE TABLE blueprint validation and qualification.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Accepts a deliberately explicit SQL subset. Archived SQL is always untrusted.
 * Unsupported objects prevent full completion; this is not a general SQL parser.
 */
final class Backup_Schema {
	/**
	 * Validate a native definition without executing it.
	 *
	 * @param string $ddl Native SHOW CREATE text.
	 * @param string $table Exact approved table name.
	 * @return array Definition body/options and deferred foreign keys.
	 */
	public static function blueprint( $ddl, $table ) {
		$head = 'CREATE TABLE ' . Database_Connection::quote( $table ) . ' (';
		if ( ! is_string( $ddl ) || 0 !== strpos( $ddl, $head ) || ! self::valid_identifier( $table ) ) {
			throw new \RuntimeException( 'backup_schema_unsupported' );
		}
		$tokens = self::tokens( $ddl );
		// Comments and statement separators are rejected by tokens(), including
		// executable MySQL comments and alternate dialect escape mechanisms.
		$upper = array_map( 'strtoupper', array_column( $tokens, 'word' ) );
		foreach ( array( 'SELECT', 'INTO', 'DIRECTORY', 'TABLESPACE', 'CONNECTION', 'FEDERATED', 'DEFINER', 'TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT', 'PARTITION', 'UNION', 'SYSTEM', 'VERSIONING' ) as $unsafe ) {
			if ( in_array( $unsafe, $upper, true ) ) {
				throw new \RuntimeException( 'backup_schema_unsupported' );
			}
		}
		$offset = strlen( $head );
		$parts  = array();
		$start  = $offset;
		$depth  = 1;
		$end    = null;
		foreach ( $tokens as $token ) {
			if ( $token['offset'] < $offset ) {
				continue;
			}
			if ( '(' === $token['raw'] ) {
				++$depth;
			} elseif ( ')' === $token['raw'] ) {
				--$depth;
				if ( 0 === $depth ) {
					$parts[] = trim( substr( $ddl, $start, $token['offset'] - $start ) );
					$end     = $token['offset'] + 1;
					break;
				}
			} elseif ( ',' === $token['raw'] && 1 === $depth ) {
				$parts[] = trim( substr( $ddl, $start, $token['offset'] - $start ) );
				$start   = $token['offset'] + 1;
			}
		}
		if ( null === $end || ! $parts ) {
			throw new \RuntimeException( 'backup_schema_unsupported' );
		}
		$options = trim( substr( $ddl, $end ) );
		if ( 1 !== preg_match( '/^ENGINE=InnoDB(?: AUTO_INCREMENT=[0-9]+)? DEFAULT CHARSET=[A-Za-z0-9_]+(?: COLLATE=[A-Za-z0-9_]+)?(?: ROW_FORMAT=(?:DYNAMIC|COMPACT|REDUNDANT|COMPRESSED))?(?: KEY_BLOCK_SIZE=[0-9]+)?(?: COMMENT=\'(?:[^\'\\\\]|\\\\.|\'\')*\')?$/D', $options ) ) {
			throw new \RuntimeException( 'backup_schema_options_unsupported' );
		}
		$columns  = array();
		$foreign  = array();
		$ordinary = array();
		foreach ( $parts as $part ) {
			if ( preg_match( '/^CONSTRAINT (\x60(?:[^\x60]|\x60\x60)+\x60) FOREIGN KEY /D', $part ) ) {
				// Parse each FK independently, never substitute arbitrary text.
				$foreign[] = self::foreign( $part );
				continue;
			}
			if ( preg_match( '/^\x60((?:[^\x60]|\x60\x60)+)\x60\s+/D', $part, $match ) ) {
				$name      = str_replace( '``', '`', $match[1] );
				$columns[] = $name;
				self::column( $part );
			} elseif ( ! self::index( $part ) ) {
				throw new \RuntimeException( 'backup_schema_definition_unsupported' );
			}
			$ordinary[] = $part;
		}
		if ( ! $columns ) {
			throw new \RuntimeException( 'backup_schema_unsupported' );
		}
		return array(
			'definitions' => $ordinary,
			'options'     => $options,
			'foreign'     => $foreign,
			'columns'     => $columns,
		);
	}

	/** @param string $definition Native ordinary index. @return bool */
	private static function index( $definition ) {
		$id     = '\x60(?:[^\x60]|\x60\x60)+\x60';
		$column = $id . '(?:\\([0-9]+\\))?(?: (?:ASC|DESC))?';
		return 1 === preg_match( '/^(?:PRIMARY KEY|(?:UNIQUE KEY|KEY|FULLTEXT KEY|SPATIAL KEY) ' . $id . ') \\(' . $column . '(?:, ?' . $column . ')*\\)(?: USING (?:BTREE|HASH))?$/D', $definition );
	}

	/** @param string $definition Column definition. @return void */
	private static function column( $definition ) {
		$tokens            = self::tokens( $definition );
		$allowed_functions = array( 'CURRENT_TIMESTAMP' );
		foreach ( $tokens as $index => $token ) {
			$word = strtoupper( $token['word'] );
			if ( in_array( $word, array( 'GENERATED', 'AS', 'CHECK', 'REFERENCES', 'SRID', 'GEOMETRY', 'POINT', 'POLYGON', 'LINESTRING', 'MULTIPOINT', 'MULTIPOLYGON', 'MULTILINESTRING', 'GEOMETRYCOLLECTION' ), true ) ) {
				throw new \RuntimeException( 'backup_schema_column_unsupported' );
			}
			if ( '' !== $word && '(' === ( $tokens[ $index + 1 ]['raw'] ?? '' ) &&
				! in_array( $word, array_merge( $allowed_functions, array( 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'INTEGER', 'BIGINT', 'DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE', 'BIT', 'CHAR', 'VARCHAR', 'BINARY', 'VARBINARY', 'DATETIME', 'TIMESTAMP', 'TIME', 'ENUM', 'SET' ) ), true ) ) {
				throw new \RuntimeException( 'backup_schema_expression_unsupported' );
			}
		}
	}

	/** @param string $definition Native FK clause. @return array */
	private static function foreign( $definition ) {
		$id      = '\x60(?:[^\x60]|\x60\x60)+\x60';
		$list    = $id . '(?:, ?' . $id . ')*';
		$pattern = '/^CONSTRAINT (' . $id . ') FOREIGN KEY \((' . $list . ')\) REFERENCES (' . $id . ') \((' . $list . ')\)((?: ON (?:DELETE|UPDATE) (?:RESTRICT|CASCADE|SET NULL|NO ACTION)){0,2})$/D';
		if ( ! preg_match( $pattern, $definition, $match ) ) {
			throw new \RuntimeException( 'backup_schema_fk_unsupported' );
		}
		return array(
			'name'           => $match[1],
			'columns'        => $match[2],
			'table'          => str_replace( '``', '`', substr( $match[3], 1, -1 ) ),
			'parent_columns' => $match[4],
			'actions'        => $match[5],
		);
	}

	/** Native identifiers are limited in characters, not UTF8 bytes. */
	public static function valid_identifier( $name ) {
		if ( ! is_string( $name ) || '' === $name || false !== strpos( $name, "\\0" ) ) {
			return false;
		}
		$count = preg_match_all( '/./us', $name );
		return false !== $count && $count <= 64;
	}

	/** @param array $blueprint Validated definition. @param string $database Approved destination. @param string $table Exact name. @return string */
	public static function create( array $blueprint, $database, $table ) {
		return 'CREATE TABLE ' . self::qualified( $database, $table ) . ' (' . implode( ', ', $blueprint['definitions'] ) . ') ' . $blueprint['options'];
	}

	/** @param array $foreign Validated FK. @param string $database Approved destination. @param string $table Exact child. @return string */
	public static function add_foreign( array $foreign, $database, $table ) {
		return 'ALTER TABLE ' . self::qualified( $database, $table ) . ' ADD CONSTRAINT ' . $foreign['name'] . ' FOREIGN KEY (' . $foreign['columns'] . ') REFERENCES ' . self::qualified( $database, $foreign['table'] ) . ' (' . $foreign['parent_columns'] . ')' . $foreign['actions'];
	}

	/** @param string $database Approved schema. @param string $table Exact name. @return string */
	public static function qualified( $database, $table ) {
		return Database_Connection::quote( $database ) . '.' . Database_Connection::quote( $table );
	}

	/**
	 * Lex only native quoted identifiers, quoted literals and ordinary tokens.
	 * Words inside literals/identifiers never become executable grammar tokens.
	 *
	 * @param string $sql Definition.
	 * @return array
	 */
	private static function tokens( $sql ) {
		$tokens = array();
		$length = strlen( $sql );
		for ( $i = 0; $i < $length; ) {
			if ( ctype_space( $sql[ $i ] ) ) {
				++$i;
				continue;
			}
			$start = $i;
			$char  = $sql[ $i ];
			$word  = '';
			if ( "'" === $char || '`' === $char ) {
				$quote = $char;
				++$i;
				$closed = false;
				while ( $i < $length ) {
					if ( "'" === $quote && '\\' === $sql[ $i ] ) {
						$i += 2;
						continue;
					}
					if ( $quote === $sql[ $i ] ) {
						if ( $i + 1 < $length && $quote === $sql[ $i + 1 ] ) {
							$i += 2;
							continue;
						}
						++$i;
						$closed = true;
						break;
					}
					++$i;
				}
				if ( ! $closed || $i > $length ) {
					throw new \RuntimeException( 'backup_schema_quoted_token' );
				}
			} elseif ( ctype_alnum( $char ) || '_' === $char ) {
				while ( $i < $length && ( ctype_alnum( $sql[ $i ] ) || '_' === $sql[ $i ] ) ) {
					++$i;
				}
				$word = substr( $sql, $start, $i - $start );
			} elseif ( false !== strpos( '(),=.+-*/', $char ) ) {
				if ( ( '/' === $char && '*' === ( $sql[ $i + 1 ] ?? '' ) ) || ( '-' === $char && '-' === ( $sql[ $i + 1 ] ?? '' ) ) ) {
					throw new \RuntimeException( 'backup_schema_comment' );
				}
				++$i;
			} else {
				throw new \RuntimeException( 'backup_schema_token_unsupported' );
			}
			$tokens[] = array(
				'raw'    => substr( $sql, $start, $i - $start ),
				'word'   => $word,
				'offset' => $start,
			);
		}
		return $tokens;
	}
}
