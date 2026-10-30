<?php
/**
 * Finite typed DDL compilation; no caller SQL fragments.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/** Pure statement builder. Execution, privilege and effect checks belong to its caller. */
final class Database_Schema_Definition {
	/** @var bool */
	private $backslashes;

	/** @param bool $backslash_escapes Observed native SQL mode. */
	public function __construct( $backslash_escapes = true ) {
		$this->backslashes = $backslash_escapes;
	}

	/** @param array $operation Typed operation. @param string $database Captured schema. @param string $prefix Installation prefix. @return string */
	public function compile( array $operation, $database, $prefix ) {
		$this->identifier( $database );
		$table = $operation['table'] ?? null;
		$this->target( $table, $prefix );
		$qualified = Database_Connection::qualified( $database, $table );
		$action    = $operation['action'] ?? '';
		$common    = array( 'action', 'table', 'expected_schema_hash' );
		$allowed   = array(
			'create_table'     => array( 'columns', 'indexes', 'foreign_keys', 'charset', 'collation' ),
			'add_column'       => array( 'column', 'after', 'first' ),
			'modify_column'    => array( 'column', 'after', 'first' ),
			'drop_column'      => array( 'name' ),
			'rename_column'    => array( 'name', 'new_name' ),
			'add_index'        => array( 'index' ),
			'drop_index'       => array( 'name' ),
			'add_foreign_key'  => array( 'foreign_key' ),
			'drop_foreign_key' => array( 'name' ),
			'rename_table'     => array( 'new_name', 'expected_destination_absent' ),
			'drop_table'       => array(),
			'truncate_table'   => array(),
		);
		if ( ! isset( $allowed[ $action ] ) || array_diff( array_keys( $operation ), array_merge( $common, $allowed[ $action ] ) ) ) {
			$this->invalid();
		}
		$expected = $operation['expected_schema_hash'] ?? null;
		if ( 'create_table' === $action ? null !== $expected : ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) {
			$this->invalid();
		}
		switch ( $action ) {
			case 'create_table':
				$columns = $operation['columns'] ?? null;
				if ( ! is_array( $columns ) || ! array_is_list( $columns ) || ! $columns ) {
					$this->invalid();
				}
				$parts = array();
				$names = array();
				foreach ( $columns as $column ) {
					$column = $this->map( $column );
					$name   = $column['name'] ?? null;
					$this->identifier( $name );
					if ( isset( $names[ $name ] ) ) {
						$this->invalid();
					}
					$names[ $name ] = true;
					$parts[]        = $this->column( $column );
				}
				foreach ( array( 'indexes', 'foreign_keys' ) as $collection ) {
					$items = $operation[ $collection ] ?? array();
					if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
						$this->invalid();
					}
					foreach ( $items as $item ) {
						$parts[] = 'indexes' === $collection ? $this->index( $this->map( $item ) ) : $this->foreign_key( $this->map( $item ), $database, $prefix );
					}
				}
				$sql = 'CREATE TABLE ' . $qualified . ' (' . implode( ', ', $parts ) . ') ENGINE=InnoDB';
				foreach ( array(
					'charset'   => ' DEFAULT CHARACTER SET ',
					'collation' => ' COLLATE ',
				) as $field => $clause ) {
					if ( isset( $operation[ $field ] ) ) {
						$sql .= $clause . $this->word( $operation[ $field ] );
					}
				}
				return $sql;
			case 'add_column':
			case 'modify_column':
				$sql = 'ALTER TABLE ' . $qualified . ( 'add_column' === $action ? ' ADD COLUMN ' : ' MODIFY COLUMN ' ) . $this->column( $this->map( $operation['column'] ?? null ) );
				if ( isset( $operation['first'] ) && ! is_bool( $operation['first'] ) ) {
					$this->invalid();
				}
				if ( ! empty( $operation['first'] ) ) {
					if ( isset( $operation['after'] ) ) {
						$this->invalid();
					}
					$sql .= ' FIRST';
				} elseif ( isset( $operation['after'] ) ) {
					$this->identifier( $operation['after'] );
					$sql .= ' AFTER ' . Database_Connection::quote( $operation['after'] );
				}
				return $sql;
			case 'drop_column':
			case 'drop_foreign_key':
				$this->identifier( $operation['name'] ?? null );
				return 'ALTER TABLE ' . $qualified . ( 'drop_column' === $action ? ' DROP COLUMN ' : ' DROP FOREIGN KEY ' ) . Database_Connection::quote( $operation['name'] );
			case 'rename_column':
				$this->identifier( $operation['name'] ?? null );
				$this->identifier( $operation['new_name'] ?? null );
				return 'ALTER TABLE ' . $qualified . ' RENAME COLUMN ' . Database_Connection::quote( $operation['name'] ) . ' TO ' . Database_Connection::quote( $operation['new_name'] );
			case 'add_index':
				return 'ALTER TABLE ' . $qualified . ' ADD ' . $this->index( $this->map( $operation['index'] ?? null ) );
			case 'drop_index':
				$this->identifier( $operation['name'] ?? null );
				return 'ALTER TABLE ' . $qualified . ( 'PRIMARY' === $operation['name'] ? ' DROP PRIMARY KEY' : ' DROP INDEX ' . Database_Connection::quote( $operation['name'] ) );
			case 'add_foreign_key':
				return 'ALTER TABLE ' . $qualified . ' ADD ' . $this->foreign_key( $this->map( $operation['foreign_key'] ?? null ), $database, $prefix );
			case 'rename_table':
				$this->target( $operation['new_name'] ?? null, $prefix );
				if ( true !== ( $operation['expected_destination_absent'] ?? null ) ) {
					$this->invalid();
				}
				return 'RENAME TABLE ' . $qualified . ' TO ' . Database_Connection::qualified( $database, $operation['new_name'] );
			case 'drop_table':
				return 'DROP TABLE ' . $qualified;
			case 'truncate_table':
				return 'TRUNCATE TABLE ' . $qualified;
		}
		$this->invalid();
	}

	/** @param array $column Typed complete column definition. @return string */
	public function column( array $column ) {
		if ( array_diff( array_keys( $column ), array( 'name', 'type', 'length', 'precision', 'scale', 'unsigned', 'nullable', 'default', 'auto_increment', 'charset', 'collation', 'members', 'comment' ) ) ) {
			$this->invalid();
		}
		$this->identifier( $column['name'] ?? null );
		$type  = $column['type'] ?? null;
		$types = array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'bit', 'char', 'varchar', 'binary', 'varbinary', 'tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'date', 'datetime', 'timestamp', 'time', 'year', 'enum', 'set', 'json', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' );
		if ( ! in_array( $type, $types, true ) ) {
			$this->invalid();
		}
		$sql = Database_Connection::quote( $column['name'] ) . ' ' . strtoupper( $type );
		if ( in_array( $type, array( 'enum', 'set' ), true ) ) {
			if ( ! is_array( $column['members'] ?? null ) || ! array_is_list( $column['members'] ) || ! $column['members'] ) {
				$this->invalid();
			}
			$members = array();
			foreach ( $column['members'] as $member ) {
				$members[] = $this->text( $this->bytes( $member ) );
			}
			$sql .= '(' . implode( ',', $members ) . ')';
		} elseif ( array_key_exists( 'members', $column ) ) {
			$this->invalid();
		}
		if ( isset( $column['length'] ) ) {
			if ( ! in_array( $type, array( 'bit', 'char', 'varchar', 'binary', 'varbinary', 'datetime', 'timestamp', 'time' ), true ) ||
				! is_int( $column['length'] ) || $column['length'] < ( in_array( $type, array( 'datetime', 'timestamp', 'time' ), true ) ? 0 : 1 ) ) {
				$this->invalid();
			}
			$sql .= '(' . $column['length'] . ')';
		} elseif ( in_array( $type, array( 'varchar', 'varbinary' ), true ) ) {
			$this->invalid();
		}
		if ( isset( $column['precision'] ) || isset( $column['scale'] ) ) {
			if ( 'decimal' !== $type || ! is_int( $column['precision'] ?? null ) || $column['precision'] < 1 ||
				! is_int( $column['scale'] ?? null ) || $column['scale'] < 0 || $column['scale'] > $column['precision'] ) {
				$this->invalid();
			}
			$sql .= '(' . $column['precision'] . ',' . $column['scale'] . ')';
		}
		if ( isset( $column['unsigned'] ) ) {
			if ( ! is_bool( $column['unsigned'] ) || ! in_array( $type, array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double' ), true ) ) {
				$this->invalid();
			}
			$sql .= $column['unsigned'] ? ' UNSIGNED' : '';
		}
		if ( ! is_bool( $column['nullable'] ?? null ) ) {
			$this->invalid();
		}
		foreach ( array(
			'charset'   => ' CHARACTER SET ',
			'collation' => ' COLLATE ',
		) as $field => $clause ) {
			if ( isset( $column[ $field ] ) ) {
				if ( ! in_array( $type, array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set' ), true ) ) {
					$this->invalid();
				}
				$sql .= $clause . $this->word( $column[ $field ] );
			}
		}
		$sql .= $column['nullable'] ? ' NULL' : ' NOT NULL';
		if ( array_key_exists( 'default', $column ) ) {
			$default = $column['default'];
			if ( null === $default ) {
				$sql .= ' DEFAULT NULL';
			} else {
				$sql .= ' DEFAULT ' . $this->text( $this->bytes( $default ) );
			}
		}
		if ( isset( $column['auto_increment'] ) ) {
			if ( ! is_bool( $column['auto_increment'] ) || ! in_array( $type, array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint' ), true ) ) {
				$this->invalid();
			}
			$sql .= $column['auto_increment'] ? ' AUTO_INCREMENT' : '';
		}
		if ( isset( $column['comment'] ) ) {
			$sql .= ' COMMENT ' . $this->text( $column['comment'] );
		}
		return $sql;
	}

	/** @param array $index Typed index. @return string */
	public function index( array $index ) {
		if ( array_diff( array_keys( $index ), array( 'name', 'kind', 'columns' ) ) ||
			! in_array( $index['kind'] ?? null, array( 'index', 'unique', 'primary', 'fulltext', 'spatial' ), true ) ) {
			$this->invalid();
		}
		$this->identifier( $index['name'] ?? null );
		$columns = $this->column_list( $index['columns'] ?? null, true );
		$kind    = array(
			'index'    => 'INDEX',
			'unique'   => 'UNIQUE INDEX',
			'primary'  => 'PRIMARY KEY',
			'fulltext' => 'FULLTEXT INDEX',
			'spatial'  => 'SPATIAL INDEX',
		)[ $index['kind'] ];
		if ( 'primary' === $index['kind'] && 'PRIMARY' !== $index['name'] ) {
			$this->invalid();
		}
		return $kind . ( 'primary' === $index['kind'] ? '' : ' ' . Database_Connection::quote( $index['name'] ) ) . ' (' . $columns . ')';
	}

	/** @param array $key Typed FK. @param string $database Captured schema. @param string $prefix Installation prefix. @return string */
	private function foreign_key( array $key, $database, $prefix ) {
		if ( array_diff( array_keys( $key ), array( 'name', 'columns', 'reference_table', 'reference_columns', 'on_delete', 'on_update' ) ) ) {
			$this->invalid();
		}
		$this->identifier( $key['name'] ?? null );
		$this->target( $key['reference_table'] ?? null, $prefix );
		$sql = 'CONSTRAINT ' . Database_Connection::quote( $key['name'] ) . ' FOREIGN KEY (' . $this->column_list( $key['columns'] ?? null ) . ') REFERENCES ' .
			Database_Connection::qualified( $database, $key['reference_table'] ) . ' (' . $this->column_list( $key['reference_columns'] ?? null ) . ')';
		foreach ( array(
			'on_delete' => ' ON DELETE ',
			'on_update' => ' ON UPDATE ',
		) as $field => $clause ) {
			if ( isset( $key[ $field ] ) ) {
				if ( ! in_array( $key[ $field ], array( 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION' ), true ) ) {
					$this->invalid();
				}
				$sql .= $clause . $key[ $field ];
			}
		}
		return $sql;
	}

	/** @param mixed $columns Ordered exact columns. @param bool $prefixes Index prefix support. @return string */
	private function column_list( $columns, $prefixes = false ) {
		if ( ! is_array( $columns ) || ! array_is_list( $columns ) || ! $columns ) {
			$this->invalid();
		}
		$result = array();
		foreach ( $columns as $column ) {
			if ( is_string( $column ) ) {
				$this->identifier( $column );
				$result[] = Database_Connection::quote( $column );
			} elseif ( $prefixes ) {
				$column = $this->map( $column );
				if ( 2 !== count( $column ) || array_diff( array_keys( $column ), array( 'name', 'prefix' ) ) || ! is_int( $column['prefix'] ) || $column['prefix'] < 1 ) {
					$this->invalid();
				}
				$this->identifier( $column['name'] );
				$result[] = Database_Connection::quote( $column['name'] ) . '(' . $column['prefix'] . ')';
			} else {
				$this->invalid();
			}
		}
		return implode( ',', $result );
	}

	/** @param mixed $value Exact text. @return string Escaped for the observed client mode. */
	private function text( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) {
			$this->invalid();
		}
		if ( $this->backslashes ) {
			$value = str_replace( array( '\\', "\0" ), array( '\\\\', '\\0' ), $value );
		}
		return "_utf8mb4'" . str_replace( "'", "''", $value ) . "'";
	}

	/** @param mixed $value Canonical encoded bytes. @return string */
	private function bytes( $value ) {
		if ( ! is_string( $value ) ) {
			$this->invalid();
		}
		$decoded = base64_decode( $value, true );
		if ( false === $decoded || base64_encode( $decoded ) !== $value ) {
			$this->invalid();
		}
		return $decoded;
	}

	/** @param mixed $value Definition object. @return array */
	private function map( $value ) {
		if ( $value instanceof \stdClass && 'stdClass' === get_class( $value ) ) {
			$value = (array) $value;
		}
		if ( ! is_array( $value ) ) {
			$this->invalid();
		}
		return $value;
	}

	/** @param mixed $value Native word. @return string */
	private function word( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-z0-9_]+$/iD', $value ) ) {
			$this->invalid();
		}
		return Database_Connection::quote( $value );
	}

	/** @param mixed $table Exact table. @param string $prefix Installation prefix. @return void */
	private function target( $table, $prefix ) {
		$this->identifier( $table );
		if ( ! is_string( $prefix ) || '' === $prefix || 0 !== strpos( $table, $prefix ) ) {
			$this->invalid();
		}
	}

	/** @param mixed $value Exact native identifier. @return void */
	private function identifier( $value ) {
		if ( ! is_string( $value ) || false !== strpos( $value, "\0" ) || 1 !== preg_match( '/^.{1,64}$/usD', $value ) ) {
			$this->invalid();
		}
	}

	/** @return never */
	private function invalid() {
		class_exists( Database_Connection::class );
		throw new Database_Write_Failure( 'database_schema_invalid' );
	}
}
