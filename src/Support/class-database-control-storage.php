<?php
/**
 * Shared verified native control storage and name policy.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Internal generated metadata only; never a public SQL or permission surface.
 */
final class Database_Control_Storage {
	const FORMAT = 'wpai-database-write-v1';
	/** @var Database_Connection */
	private $connection;
	/** @var int */
	private $budget;
	/** @var array */
	private $schemas = array();

	/** @param Database_Connection $connection Retained native handle. @param int $budget Metadata allowance. */
	public function __construct( Database_Connection $connection, $budget ) {
		$this->connection = $connection;
		$this->budget     = $budget;
	}


	/** @param string $table Exact native table. @return string Fixed control dependency class. */
	public function table_policy( $table ) {
		if ( preg_match( '/^' . preg_quote( $this->connection->prefix, '/' ) . '(?:[0-9]+_)?(options|sitemeta|blogmeta|posts|postmeta)$/D', $table, $match ) ) {
			return $match[1];
		}
		if ( 0 === strpos( $table, $this->connection->prefix . 'wpai_' ) ||
			0 === strpos( $table, $this->connection->prefix . 'wp_ai_bridge' ) ) {
			return 'bridge';
		}
		return '';
	}

	/** @param string $table Exact schema target. @return true */
	public function assert_schema_target( $table ) {
		if ( ! $this->identifier( $table ) || 0 !== strpos( $table, $this->connection->prefix ) ) {
			throw new Database_Write_Failure( 'database_schema_scope' );
		}
		if ( '' !== $this->table_policy( $table ) ) {
			throw new Database_Write_Failure( 'database_schema_control_dependency' );
		}
		return true;
	}

	/**
	 * Observe a name and complete native definition under the caller's section.
	 * The observation is not an atomic precondition for a later DDL statement.
	 *
	 * @param string $table Exact persistent installation table.
	 * @return array
	 */
	public function observe_table( $table ) {
		if ( ! $this->identifier( $table ) || 0 !== strpos( $table, $this->connection->prefix ) ) {
			throw new Database_Write_Failure( 'database_schema_scope' );
		}
		$rows = $this->connection->run(
			'SELECT TABLE_TYPE AS kind FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ?',
			array( $this->connection->database, $table ),
			array(),
			2
		)['rows'];
		if ( ! $rows ) {
			return array(
				'table'                 => $table,
				'exists'                => false,
				'schema_hash'           => null,
				'precondition_strength' => 'observed_not_atomic',
			);
		}
		$native = $this->pin_table( $table, false );
		$stable = $this->stable_native_definition( $native['ddl'] );
		if ( ! is_string( $stable ) ) {
			throw new Database_Write_Failure( 'database_write_unsupported_schema' );
		}
		return array(
			'table'                 => $table,
			'exists'                => true,
			'schema_hash'           => hash( 'sha256', $this->encode( $this->metadata_bytes( array( 'wpai-database-schema-v1', $this->connection->database, $table, $native['native'], $stable ) ) ) ),
			'engine'                => $native['native'][0]['engine'],
			'precondition_strength' => 'observed_not_atomic',
		);
	}

	/** @return array Pinned verified receipt schema. */
	public function receipt_schema() {
		$schema = $this->schema( $this->connection->options );
		foreach ( array( 'option_name', 'option_value', 'autoload' ) as $name ) {
			if ( ! isset( $schema['columns'][ $name ] ) ) {
				throw new Database_Write_Failure( 'database_write_unsupported_schema' );
			}
		}
		$unique = false;
		foreach ( $schema['indexes'] as $index ) {
			if ( '0' === $index['non_unique'] && array( 'option_name' ) === $index['columns'] && ! $index['prefix'] ) {
				$unique = true;
			}
		}
		if ( ! $unique ) {
			throw new Database_Write_Failure( 'database_write_unsupported_schema' );
		}
		// A customized index on option_value makes receipt finalization a
		// referenced-key update and needs the same complete effect proof.
		if ( isset( $schema['indexed']['option_value'] ) ) {
			$this->foreign_keys( $schema, 'update' );
		}
		return $schema;
	}

	/** @param string $table Native installation table. @return array */
	public function schema( $table ) {
		if ( isset( $this->schemas[ $table ] ) ) {
			return $this->schemas[ $table ];
		}
		$identity = $this->pin_table( $table );
		$native   = $identity['native'];
		$ddl      = $identity['ddl'];
		if ( ! $this->connection->has_privilege( 'TRIGGER', $table ) ) {
			throw new Database_Write_Failure( 'database_write_trigger_visibility' );
		}
		if ( $this->connection->run(
			'SELECT 1 FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND BINARY EVENT_OBJECT_TABLE=BINARY ? LIMIT 1',
			array( $this->connection->database, $table )
		)['rows'] ) {
			throw new Database_Write_Failure( 'database_write_trigger_effects' );
		}
		if ( $this->connection->run(
			"SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ? AND CONSTRAINT_TYPE='CHECK' LIMIT 1",
			array( $this->connection->database, $table )
		)['rows'] ) {
			throw new Database_Write_Failure( 'database_write_unsupported_schema' );
		}
		$columns = $this->connection->run(
			'SELECT COLUMN_NAME AS name, ORDINAL_POSITION AS ordinal, DATA_TYPE AS type, COLUMN_TYPE AS sql_type, IS_NULLABLE AS nullable, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation, COLUMN_DEFAULT AS default_value, EXTRA AS extra, GENERATION_EXPRESSION AS expression FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ? ORDER BY ORDINAL_POSITION',
			array( $this->connection->database, $table ),
			array(),
			max( 1, intdiv( $this->budget, 512 ) )
		)['rows'];
		$map     = array();
		foreach ( $columns as $column ) {
			if ( ! $this->identifier( $column['name'] ) || '' !== ( $column['expression'] ?? '' ) ||
				preg_match( '/generated|versioning/i', str_replace( 'DEFAULT_GENERATED', '', $column['extra'] ) ) ||
				! in_array( strtolower( $column['type'] ), array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'bit', 'char', 'varchar', 'binary', 'varbinary', 'tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'date', 'datetime', 'timestamp', 'time', 'year', 'enum', 'set' ), true ) ||
				! $this->safe_default( $column ) ) {
				throw new Database_Write_Failure( 'database_write_unsupported_schema' );
			}
			$map[ $column['name'] ] = $column;
		}
		$index_rows = $this->connection->run(
			'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS position, COLUMN_NAME AS column_name, SUB_PART AS prefix_bytes, INDEX_TYPE AS type, COLLATION AS collation FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
			array( $this->connection->database, $table ),
			array(),
			max( 1, intdiv( $this->budget, 128 ) )
		)['rows'];
		$indexes    = array();
		$indexed    = array();
		foreach ( $index_rows as $row ) {
			if ( ! isset( $map[ $row['column_name'] ] ) ) {
				throw new Database_Write_Failure( 'database_write_unsupported_schema' );
			}
			if ( ! isset( $indexes[ $row['name'] ] ) ) {
				$indexes[ $row['name'] ] = array(
					'non_unique' => $row['non_unique'],
					'columns'    => array(),
					'prefix'     => false,
				);
			}
			$indexes[ $row['name'] ]['columns'][] = $row['column_name'];
			$indexes[ $row['name'] ]['prefix']    = $indexes[ $row['name'] ]['prefix'] || null !== $row['prefix_bytes'];
			$indexed[ $row['column_name'] ]       = true;
		}
		$primary = $indexes['PRIMARY']['columns'] ?? array();
		if ( ! $map || ! $primary ) {
			throw new Database_Write_Failure( 'database_write_primary_key_required' );
		}
		$stable_ddl = $this->stable_native_definition( $ddl );
		if ( ! is_string( $stable_ddl ) ) {
			throw new Database_Write_Failure( 'database_write_unsupported_schema' );
		}
		// Native defaults/type definitions can contain non-UTF8 bytes. Bind
		// their exact length and Base64, never a failed JSON encoding.
		$binding                 = $this->metadata_bytes( array( self::FORMAT, $this->connection->database, $table, $native, $columns, $index_rows, hash( 'sha256', $stable_ddl ) ) );
		$hash                    = hash( 'sha256', $this->encode( $binding, 'database_write_unsupported_schema' ) );
		$schema                  = array(
			'table'   => $table,
			'hash'    => $hash,
			'columns' => $map,
			'primary' => $primary,
			'indexes' => $indexes,
			'indexed' => $indexed,
		);
		$this->schemas[ $table ] = $schema;
		return $schema;
	}

	/**
	 * Pin one persistent installation table and bound its native definition.
	 *
	 * @param string $table Exact native table.
	 * @param bool $require_innodb Require transactional writer eligibility.
	 * @return array
	 */
	public function pin_table( $table, $require_innodb = true ) {
		if ( ! $this->identifier( $table ) || 0 !== strpos( $table, $this->connection->prefix ) ) {
			throw new Database_Write_Failure( 'database_write_unsupported_table' );
		}
		// Opening the resolved object before metadata checks pins its definition.
		$this->connection->run( 'SELECT 1 FROM ' . $this->connection->table( $table ) . ' LIMIT 0' );
		$native = $this->connection->run(
			'SELECT TABLE_TYPE AS kind, ENGINE AS engine, CREATE_OPTIONS AS options FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ?',
			array( $this->connection->database, $table ),
			array(),
			2
		)['rows'];
		if ( count( $native ) !== 1 || 'BASE TABLE' !== $native[0]['kind'] || ( $require_innodb && 'InnoDB' !== $native[0]['engine'] ) ||
			false !== stripos( $native[0]['options'] ?? '', 'version' ) ) {
			throw new Database_Write_Failure( 'database_write_unsupported_table' );
		}
		$size    = $this->connection->run(
			'SELECT COALESCE(SUM(OCTET_LENGTH(COLUMN_TYPE)+COALESCE(OCTET_LENGTH(COLUMN_DEFAULT),0)+COALESCE(OCTET_LENGTH(GENERATION_EXPRESSION),0)+COALESCE(OCTET_LENGTH(COLUMN_COMMENT),0)+512),0) AS bytes FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ?',
			array( $this->connection->database, $table )
		)['rows'][0]['bytes'] ?? null;
		$other   = $this->connection->run(
			'SELECT COALESCE(SUM(OCTET_LENGTH(INDEX_COMMENT)+256),0) AS bytes FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ?',
			array( $this->connection->database, $table )
		)['rows'][0]['bytes'] ?? null;
		$comment = $this->connection->run(
			'SELECT OCTET_LENGTH(TABLE_COMMENT) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ?',
			array( $this->connection->database, $table ),
			array(),
			1
		)['rows'][0]['bytes'] ?? null;
		if ( ! is_numeric( $size ) || ! is_numeric( $other ) || ! is_numeric( $comment ) ||
			8 * ( (float) $size + (float) $other + (float) $comment ) + 8192 > $this->budget ) {
			throw new Database_Write_Failure( 'database_write_budget' );
		}
		$creation = $this->connection->run( 'SHOW CREATE TABLE ' . $this->connection->table( $table ), array(), array(), 1 )['rows'];
		$ddl      = $creation ? (string) end( $creation[0] ) : '';
		if ( ! preg_match( '/^CREATE TABLE /', $ddl ) ) {
			throw new Database_Write_Failure( 'database_write_unsupported_table' );
		}
		return array(
			'native' => $native,
			'ddl'    => $ddl,
		);
	}


	/**
	 * Remove only an unquoted native table AUTO_INCREMENT counter.
	 *
	 * This scans trusted SHOW CREATE quote/body boundaries, not arbitrary SQL.
	 * Every literal, identifier and comment byte remains part of the identity.
	 * Unknown formatting retains the complete definition rather than weakening
	 * its hash. The native mode determines string escapes and identifier quotes.
	 *
	 * @param string $ddl Complete native SHOW CREATE definition.
	 * @param string $sql_mode Current native session mode.
	 * @return string Exact definition except its ordinary table counter.
	 */
	public static function stable_definition( $ddl, $sql_mode ) {
		$modes       = explode( ',', $sql_mode );
		$backslash   = ! in_array( 'NO_BACKSLASH_ESCAPES', $modes, true );
		$ansi        = in_array( 'ANSI_QUOTES', $modes, true );
		$quote       = '';
		$depth       = 0;
		$body        = false;
		$options     = false;
		$length      = strlen( $ddl );
		$counter     = ' AUTO_INCREMENT=';
		$counter_len = strlen( $counter );
		for ( $offset = 0; $offset < $length; ++$offset ) {
			$byte = $ddl[ $offset ];
			if ( '' !== $quote ) {
				$identifier = chr( 96 ) === $quote || ( '"' === $quote && $ansi );
				if ( ! $identifier && $backslash && '\\' === $byte ) {
					++$offset;
				} elseif ( $quote === $byte ) {
					if ( $offset + 1 < $length && $quote === $ddl[ $offset + 1 ] ) {
						++$offset;
					} else {
						$quote = '';
					}
				}
				continue;
			}
			if ( '/' === $byte && $offset + 1 < $length && '*' === $ddl[ $offset + 1 ] ) {
				$end = strpos( $ddl, '*/', $offset + 2 );
				if ( false === $end ) {
					return $ddl;
				}
				$offset = $end + 1;
				continue;
			}
			if ( "'" === $byte || '"' === $byte || chr( 96 ) === $byte ) {
				$quote = $byte;
			} elseif ( '(' === $byte ) {
				++$depth;
				$body = true;
			} elseif ( ')' === $byte ) {
				if ( $depth < 1 ) {
					return $ddl;
				}
				--$depth;
				$options = $body && 0 === $depth;
			} elseif ( $options && 0 === $depth && 0 === substr_compare( $ddl, $counter, $offset, $counter_len ) ) {
				$end    = $offset + $counter_len;
				$digits = $end;
				while ( $end < $length && $ddl[ $end ] >= '0' && $ddl[ $end ] <= '9' ) {
					++$end;
				}
				if ( $end > $digits && ( $end === $length || false !== strpos( " \t\r\n", $ddl[ $end ] ) ) ) {
					return substr( $ddl, 0, $offset ) . substr( $ddl, $end );
				}
			}
		}
		return $ddl;
	}

	/** @param string $ddl Complete captured definition. @return string */
	private function stable_native_definition( $ddl ) {
		$rows = $this->connection->run( 'SELECT @@SESSION.sql_mode AS mode', array(), array(), 1 )['rows'];
		if ( 1 !== count( $rows ) || ! is_string( $rows[0]['mode'] ?? null ) ) {
			throw new Database_Write_Failure( 'database_write_unsupported_schema' );
		}
		return self::stable_definition( $ddl, $rows[0]['mode'] );
	}

	/** @param array $column Native column. @return bool */
	private function safe_default( $column ) {
		$default = $column['default_value'];
		if ( null === $default || 'NULL' === $default ) {
			return true;
		}
		$timestamp = in_array( $column['type'], array( 'timestamp', 'datetime' ), true ) &&
			1 === preg_match( '/^current_timestamp(?:\\([0-6]?\\))?$/iD', $default );
		if ( false !== stripos( $column['extra'], 'DEFAULT_GENERATED' ) && ! $timestamp ) {
			return false;
		}
		if ( ! $this->connection->mariadb ) {
			return true;
		}
		return $timestamp || 1 === preg_match( "/^(?:'(?:[^'\\\\]|'')*'|[-+]?[0-9]+(?:\\.[0-9]+)?|b'[01]+'|0x[0-9a-f]+)$/isD", $default );
	}

	/** @param array $schema Verified schema. @param string $action Native DML effect. @return void */
	public function foreign_keys( $schema, $action ) {
		$view = $this->connection->mariadb ? 'INNODB_SYS_FOREIGN' : 'INNODB_FOREIGN';
		try {
			$reference = $this->connection->foreign_reference( $schema['table'] );
			$rows      = $this->connection->run(
				'SELECT TYPE AS flags FROM information_schema.' . $view . ' WHERE BINARY REF_NAME = BINARY ?',
				array( $reference ),
				array(),
				max( 1, intdiv( $this->budget, 64 ) )
			)['rows'];
		} catch ( Database_Write_Failure $error ) {
			if ( 'database_write_fk_identity' === $error->reason ) {
				throw $error;
			}
			throw new Database_Write_Failure( 'database_write_fk_visibility', $error->details );
		}
		$mask = 'delete' === $action ? 3 : 12;
		foreach ( $rows as $row ) {
			if ( ! ctype_digit( $row['flags'] ) || ( (int) $row['flags'] & $mask ) ) {
				throw new Database_Write_Failure( 'database_write_fk_effects' );
			}
		}
	}

	/** @param string $column Internal expression. @param bool $meta Include Workspace metadata. @return string */
	public static function name_expression( $column, $meta = false ) {
		$patterns = array( 'wp=_ai=_bridge%', 'wpai=_%', '=_transient=_wpai%', '=_transient=_timeout=_wpai%', '=_site=_transient=_wpai%', '=_site=_transient=_timeout=_wpai%' );
		if ( $meta ) {
			$patterns[] = '=_wpai%';
		}
		$parts = array();
		foreach ( $patterns as $pattern ) {
			$parts[] = $column . " LIKE '" . $pattern . "' ESCAPE '='";
		}
		// LIKE can differ from '=' for Unicode ignorable/expanding weights.
		// These deliberately broader native ordering intervals also cover
		// identities collating equal to a protected namespace plus any suffix.
		$ranges = array(
			array( 'wp_ai_bridge', 'wp_ai_bridgf' ),
			array( 'wpai', 'wpaj' ),
			array( '_transient_wpai', '_transient_wpaj' ),
			array( '_transient_timeout_wpai', '_transient_timeout_wpaj' ),
			array( '_site_transient_wpai', '_site_transient_wpaj' ),
			array( '_site_transient_timeout_wpai', '_site_transient_timeout_wpaj' ),
		);
		if ( $meta ) {
			$ranges[] = array( '_wpai', '_wpaj' );
		}
		foreach ( $ranges as $range ) {
			$parts[] = '(' . $column . " >= '" . $range[0] . "' AND " . $column . " < '" . $range[1] . "')";
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/** @param mixed $value Native identifier. @return bool */
	private function identifier( $value ) {
		return is_string( $value ) && false === strpos( $value, "\0" ) && 1 === preg_match( '/^.{1,64}$/usD', $value );
	}

	/** @param mixed $value Metadata tree. @return mixed */
	private function metadata_bytes( $value ) {
		if ( is_string( $value ) ) {
			return array(
				'bytes'  => strlen( $value ),
				'base64' => base64_encode( $value ),
			);
		}
		if ( is_array( $value ) ) {
			foreach ( $value as &$item ) {
				$item = $this->metadata_bytes( $item );
			}
			unset( $item );
		}
		return $value;
	}

	/** @param mixed $value Metadata. @param string $reason Fixed code. @return string */
	private function encode( $value, $reason = 'database_write_invalid' ) {
		$json = json_encode( $value );
		if ( ! is_string( $json ) || '' === $json ) {
			throw new Database_Write_Failure( $reason );
		}
		return $json;
	}
}
