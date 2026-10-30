<?php
/**
 * Native-connection database capture and isolated restore consumer.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * No credentials, second connection, shell, arbitrary SQL, hooks or deserialization.
 * The caller owns current permission, immutable source plans and private streams.
 */
final class Backup_Database {
	/** @var Database_Connection */
	private $connection;
	/** @var int */
	private $budget;
	/** @var array One request-local metadata page; never a native cursor. */
	private $group_page = array();
	/** @var bool A discarded shared handle must receive no later cleanup SQL. */
	private $session_discarded = false;

	/** @param int $budget Finite native metadata/processing byte allowance. */
	public function __construct( $budget ) {
		$this->budget     = $budget;
		$this->connection = new Database_Connection();
	}

	/** @return array Captured connection/session representation. */
	public function identity() {
		$row = $this->connection->run( 'SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.time_zone AS time_zone, VERSION() AS version', array(), array(), 1 )['rows'][0];
		return array(
			'database' => $this->connection->database,
			'prefix'   => $this->connection->prefix,
			'session'  => $row,
		);
	}

	/** Known failure diagnostics must also be unchanged at final seal admission. */
	public function activity_digest() {
		$rows = $this->connection->run(
			'SELECT SHA2(CAST(option_value AS BINARY),256) AS digest FROM ' .
			$this->connection->table( $this->connection->options ) . ' WHERE option_name=?',
			array( Mutation_Log::OPTION_NAME ),
			array(),
			2
		)['rows'];
		if ( count( $rows ) > 1 || $rows && ! Backup_Archive::digest( $rows[0]['digest'] ) ) {
			throw new \RuntimeException( 'backup_source_digest_unavailable' );
		}
		return $rows ? $rows[0]['digest'] : null;
	}

	/**
	 * Capture every prefix object plus explicitly approved extra tables.
	 * Presence of unsupported executable objects is a full-backup preflight gap.
	 *
	 * @param array $extras Owner-configured exact additional installation tables.
	 * @return array
	 */
	public function inventory( array $extras ) {
		$rows   = $this->connection->run(
			'SELECT TABLE_NAME AS name,TABLE_TYPE AS kind FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY BINARY TABLE_NAME LIMIT ' . ( max( 1, intdiv( $this->budget, 256 ) ) + 1 ),
			array( $this->connection->database ),
			array(),
			max( 1, intdiv( $this->budget, 256 ) )
		)['rows'];
		$tables = array();
		foreach ( $rows as $row ) {
			if ( 0 !== strpos( $row['name'], $this->connection->prefix ) && ! in_array( $row['name'], $extras, true ) ) {
				continue;
			}
			if ( 'BASE TABLE' !== $row['kind'] ) {
				throw new \RuntimeException( 'backup_unsupported_view:' . base64_encode( $row['name'] ) );
			}
			try {
				$this->schema( $row['name'] );
			} catch ( \RuntimeException $error ) {
				$reason = $error->getMessage();
				if ( 0 === strpos( $reason, 'backup_schema_' ) && false === strpos( $reason, ':' ) ) {
					$reason .= ':' . base64_encode( $row['name'] );
				}
				throw new \RuntimeException( $reason );
			}
			$tables[] = $row['name'];
		}
		if ( array_diff( $extras, $tables ) || ! $tables ) {
			throw new \RuntimeException( 'backup_source_inventory_incomplete' );
		}
		if ( ! $this->database_privilege( 'EVENT', $this->connection->database ) ||
			! $this->database_privilege( 'CREATE ROUTINE', $this->connection->database ) ) {
			throw new \RuntimeException( 'backup_object_visibility' );
		}
		// Routines/events have no provable installation-prefix ownership.
		// A schema containing them needs an explicit supported object contract.
		foreach ( array(
			'ROUTINES' => 'ROUTINE_NAME',
			'EVENTS'   => 'EVENT_NAME',
		) as $view => $column ) {
			$schema_column = 'ROUTINES' === $view ? 'ROUTINE_SCHEMA' : 'EVENT_SCHEMA';
			$objects       = $this->connection->run( 'SELECT ' . $column . ' AS name FROM information_schema.' . $view . ' WHERE ' . $schema_column . '=? LIMIT 1', array( $this->connection->database ), array(), 1 )['rows'];
			if ( $objects ) {
				throw new \RuntimeException( 'backup_unsupported_' . strtolower( $view ) . ':' . base64_encode( $objects[0]['name'] ) );
			}
		}
		return $tables;
	}

	/** @param string $table Exact approved table. @param string|null $database Internal approved destination or source. @return array */
	public function schema( $table, $database = null ) {
		$database = null === $database ? $this->connection->database : $database;
		if ( ! Backup_Schema::valid_identifier( $table ) ) {
			throw new \RuntimeException( 'backup_schema_identifier' );
		}
		$native = $this->connection->run(
			'SELECT ENGINE AS engine,TABLE_TYPE AS kind,CREATE_OPTIONS AS options,OCTET_LENGTH(TABLE_COMMENT) AS comment_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ?',
			array( $database, $table ),
			array(),
			2
		)['rows'];
		if ( 1 !== count( $native ) || 'InnoDB' !== $native[0]['engine'] || 'BASE TABLE' !== $native[0]['kind'] ||
			'' !== ( $native[0]['options'] ?? '' ) && false !== stripos( $native[0]['options'], 'version' ) ) {
			throw new \RuntimeException( 'backup_schema_engine_unsupported' );
		}
		if ( $this->connection->run(
			"SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ? AND CONSTRAINT_TYPE='CHECK' LIMIT 1",
			array( $database, $table ),
			array(),
			1
		)['rows'] ||
			$this->connection->run(
				'SELECT 1 FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ? AND PARTITION_NAME IS NOT NULL LIMIT 1',
				array( $database, $table ),
				array(),
				1
			)['rows'] ) {
			throw new \RuntimeException( 'backup_schema_constraint_or_partition_unsupported' );
		}
		$other = $this->connection->run(
			'SELECT COALESCE(SUM(OCTET_LENGTH(INDEX_COMMENT)+512),0) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ?',
			array( $database, $table ),
			array(),
			1
		)['rows'][0]['n'];
		$size  = $this->connection->run(
			'SELECT COALESCE(SUM(OCTET_LENGTH(COLUMN_TYPE)+COALESCE(OCTET_LENGTH(COLUMN_DEFAULT),0)+COALESCE(OCTET_LENGTH(GENERATION_EXPRESSION),0)+COALESCE(OCTET_LENGTH(COLUMN_COMMENT),0)+1024),0) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ?',
			array( $database, $table ),
			array(),
			1
		)['rows'][0]['n'];
		if ( ! ctype_digit( $size ) || ( (float) $size + (float) $other + (float) $native[0]['comment_bytes'] ) * 8 + 8192 > $this->budget ) {
			throw new \RuntimeException( 'backup_metadata_capacity' );
		}
		// Positive TRIGGER visibility is needed; an empty filtered listing alone
		// cannot prove that loading a row has no executable side effects.
		if ( $database === $this->connection->database && ! $this->connection->has_privilege( 'TRIGGER', $table ) ) {
			throw new \RuntimeException( 'backup_trigger_visibility' );
		}
		$triggers = $this->connection->run(
			'SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND BINARY EVENT_OBJECT_TABLE=BINARY ? LIMIT 1',
			array( $database, $table ),
			array(),
			1
		)['rows'];
		if ( $triggers ) {
			throw new \RuntimeException( 'backup_unsupported_trigger:' . base64_encode( $triggers[0]['name'] ) );
		}
		$columns = $this->connection->run(
			'SELECT COLUMN_NAME AS name,DATA_TYPE AS type,CHARACTER_SET_NAME AS charset,COLLATION_NAME AS collation,EXTRA AS extra,GENERATION_EXPRESSION AS expression FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ? ORDER BY ORDINAL_POSITION',
			array( $database, $table ),
			array(),
			max( 1, intdiv( $this->budget, 512 ) )
		)['rows'];
		foreach ( $columns as $column ) {
			if ( '' !== ( $column['expression'] ?? '' ) || false !== stripos( $column['extra'] ?? '', 'generated' ) && false === stripos( $column['extra'], 'DEFAULT_GENERATED' ) ||
				! in_array( strtolower( $column['type'] ), array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'bit', 'char', 'varchar', 'binary', 'varbinary', 'tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'date', 'datetime', 'timestamp', 'time', 'year', 'enum', 'set', 'json' ), true ) ) {
				throw new \RuntimeException( 'backup_schema_column_unsupported' );
			}
		}
		$creation  = $this->connection->run( 'SHOW CREATE TABLE ' . Backup_Schema::qualified( $database, $table ), array(), array(), 1 )['rows'];
		$ddl       = (string) end( $creation[0] );
		$blueprint = Backup_Schema::blueprint( $ddl, $table );
		if ( array_column( $columns, 'name' ) !== $blueprint['columns'] ) {
			throw new \RuntimeException( 'backup_schema_metadata_mismatch' );
		}
		Backup_Schema::reconcile_columns( $blueprint, $columns );
		return array(
			'table'     => $table,
			'ddl'       => $ddl,
			'blueprint' => $blueprint,
			'columns'   => $columns,
			'hash'      => hash( 'sha256', $ddl ),
		);
	}

	/** @param array $columns Native ordered columns. @return string Internally generated content-addressed row identity. */
	public static function row_expression( array $columns ) {
		$parts = array( "'wpai-row-v1;'" );
		foreach ( $columns as $column ) {
			$name    = Database_Connection::quote( $column['name'] );
			$parts[] = 'IF(' . $name . " IS NULL,'N;',CONCAT('V',OCTET_LENGTH(CAST(" . $name . " AS BINARY)),':',SHA2(CAST(" . $name . " AS BINARY),256),';'))";
		}
		return 'SHA2(CONCAT(' . implode( ',', $parts ) . '),256)';
	}

	/** Discard prefetched source evidence before any independent verification. */
	public function reset_groups() {
		$this->group_page = array();
	}

	/** @param array $schema Validated schema. @return array|null Exact single integer PK. */
	private function integer_primary( array $schema ) {
		$definitions = $schema['blueprint']['definitions'] ?? array();
		$primary     = null;
		foreach ( $definitions as $definition ) {
			if ( 1 === preg_match( '/^PRIMARY KEY \\((\x60(?:[^\x60]|\x60\x60)+\x60)(?: (?:ASC|DESC))?\\)(?: USING BTREE)?$/D', $definition, $match ) ) {
				$primary = str_replace( '``', '`', substr( $match[1], 1, -1 ) );
			}
		}
		if ( null === $primary ) {
			return null;
		}
		$bounds = array(
			'tinyint'   => array( '127', '128', '255' ),
			'smallint'  => array( '32767', '32768', '65535' ),
			'mediumint' => array( '8388607', '8388608', '16777215' ),
			'int'       => array( '2147483647', '2147483648', '4294967295' ),
			'bigint'    => array( '9223372036854775807', '9223372036854775808', '18446744073709551615' ),
		);
		foreach ( $definitions as $definition ) {
			if ( 1 === preg_match( '/^' . preg_quote( Database_Connection::quote( $primary ), '/' ) . '\\s+(tinyint|smallint|mediumint|int|bigint)(?:\\([0-9]+\\))?( unsigned)? NOT NULL(?: |$)/iD', $definition, $match ) ) {
				$type     = strtolower( $match[1] );
				$unsigned = ! empty( $match[2] );
				return array(
					'name'     => $primary,
					'cast'     => $unsigned ? 'UNSIGNED' : 'SIGNED',
					'positive' => $bounds[ $type ][ $unsigned ? 2 : 0 ],
					'negative' => $unsigned ? null : $bounds[ $type ][1],
				);
			}
		}
		return null;
	}

	/** @param mixed $value Native decimal bytes. @param array $primary Verified type. @return string */
	private function locator( $value, array $primary ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*|-[1-9][0-9]*)$/D', $value ) ) {
			throw new \RuntimeException( 'backup_source_locator_invalid' );
		}
		$negative = '-' === $value[0];
		$digits   = $negative ? substr( $value, 1 ) : $value;
		$bound    = $primary[ $negative ? 'negative' : 'positive' ];
		if ( null === $bound || strlen( $digits ) > strlen( $bound ) ||
			( strlen( $digits ) === strlen( $bound ) && strcmp( $digits, $bound ) > 0 ) ) {
			throw new \RuntimeException( 'backup_source_locator_invalid' );
		}
		return $value;
	}

	/** @param array $schema Validated schema. @param string $after Prior digest or empty. @param string|null $database Approved database. @return array|null */
	public function next_group( array $schema, $after, $database = null ) {
		$database   = null === $database ? $this->connection->database : $database;
		$expression = self::row_expression( $schema['columns'] );
		$primary    = $this->integer_primary( $schema );
		$key        = hash( 'sha256', serialize( array( $database, $schema['table'], $schema['hash'] ?? null, $expression, $primary ) ) );
		if ( ( $this->group_page['key'] ?? null ) !== $key || ( $this->group_page['after'] ?? null ) !== $after ) {
			$this->reset_groups();
		}
		if ( ! $this->group_page ) {
			// Each fixed-width digest/count/optional integer row receives a
			// conservative 4KiB processing charge, including PHP/native copies.
			// This is a page quantum, never a limit on total rows or groups.
			$limit        = max( 1, intdiv( $this->budget, 4096 ) );
			$derived_hash = Backup_Schema::qualified( 'wpai_backup_rows', 'wpai_row_sha256' );
			$pk_alias     = Backup_Schema::qualified( 'wpai_backup_rows', 'wpai_row_pk' );
			$rows         = $this->connection->run(
				'SELECT ' . $derived_hash . ' AS digest,COUNT(*) AS multiplicity' .
				( null === $primary ? '' : ',MIN(' . $pk_alias . ') AS locator' ) .
				' FROM (SELECT ' . $expression . ' AS ' . Database_Connection::quote( 'wpai_row_sha256' ) .
				( null === $primary ? '' : ',' . Database_Connection::quote( $primary['name'] ) . ' AS ' . Database_Connection::quote( 'wpai_row_pk' ) ) .
				' FROM ' . Backup_Schema::qualified( $database, $schema['table'] ) . ') AS ' . Database_Connection::quote( 'wpai_backup_rows' ) .
				' WHERE ' . $derived_hash . ' > ? GROUP BY ' . $derived_hash . ' ORDER BY ' . $derived_hash . ' LIMIT ' . $limit,
				array( $after ),
				array(),
				$limit
			)['rows'];
			if ( count( $rows ) > $limit ) {
				throw new \RuntimeException( 'backup_metadata_capacity' );
			}
			$previous = $after;
			foreach ( $rows as &$row ) {
				if ( ! Backup_Archive::digest( $row['digest'] ?? null ) || strcmp( $row['digest'], $previous ) <= 0 ||
					! is_string( $row['multiplicity'] ?? null ) || ! ctype_digit( $row['multiplicity'] ) || '0' === $row['multiplicity'] ) {
					throw new \RuntimeException( 'backup_source_digest_unavailable' );
				}
				$previous = $row['digest'];
				if ( null !== $primary ) {
					$row['locator'] = $this->locator( $row['locator'] ?? null, $primary );
					if ( '1' !== $row['multiplicity'] ) {
						unset( $row['locator'] );
					}
				}
			}
			unset( $row );
			if ( ! $rows ) {
				return null;
			}
			$this->group_page = array(
				'key'   => $key,
				'after' => $after,
				'rows'  => $rows,
				'index' => 0,
				'last'  => count( $rows ) < $limit,
			);
		}
		$index = $this->group_page['index'];
		if ( ! isset( $this->group_page['rows'][ $index ] ) ) {
			$last = $this->group_page['last'];
			$this->reset_groups();
			return $last ? null : $this->next_group( $schema, $after, $database );
		}
		$row = $this->group_page['rows'][ $index ];
		++$this->group_page['index'];
		$this->group_page['after'] = $row['digest'];
		return $row;
	}

	/** @param array $schema Validated schema. @param string $group Exact row-group digest. @param int $column Ordered column. @param int $offset Cell offset. @param int $length Bounded bytes. @param string|null $locator Private current-row integer locator. @return array */
	public function cell( array $schema, $group, $column, $offset, $length, $locator = null ) {
		$name       = Database_Connection::quote( $schema['columns'][ $column ]['name'] );
		$predicate  = '';
		$parameters = array( (string) ( $offset + 1 ), (string) $length );
		if ( null !== $locator ) {
			$primary = $this->integer_primary( $schema );
			if ( null === $primary ) {
				throw new \RuntimeException( 'backup_source_locator_invalid' );
			}
			$predicate    = Database_Connection::quote( $primary['name'] ) . '=CAST(? AS ' . $primary['cast'] . ') AND ';
			$parameters[] = $this->locator( $locator, $primary );
		}
		$parameters[] = $group;
		$rows         = $this->connection->run(
			'SELECT SUBSTRING(CAST(' . $name . ' AS BINARY),?,?) AS value,OCTET_LENGTH(CAST(' . $name . ' AS BINARY)) AS bytes,SHA2(CAST(' . $name . ' AS BINARY),256) AS digest FROM ' .
			$this->connection->table( $schema['table'] ) . ' WHERE ' . $predicate . self::row_expression( $schema['columns'] ) . '=? LIMIT 1',
			$parameters,
			array(),
			1
		)['rows'];
		if ( 1 !== count( $rows ) || null !== $rows[0]['bytes'] && ! Backup_Archive::digest( $rows[0]['digest'] ) ) {
			throw new \RuntimeException( 'backup_source_stale' );
		}
		return $rows[0];
	}

	/** @param string $database Owner-approved target. @return void */
	public function empty_target( $database ) {
		if ( ! Backup_Schema::valid_identifier( $database ) ||
			hash_equals( $this->connection->database, $database ) ) {
			throw new \RuntimeException( 'backup_restore_target_denied' );
		}
		foreach ( array( 'SELECT', 'CREATE', 'ALTER', 'INSERT', 'TRIGGER', 'EVENT', 'CREATE ROUTINE' ) as $privilege ) {
			if ( ! $this->database_privilege( $privilege, $database ) ) {
				throw new \RuntimeException( 'backup_restore_native_privilege' );
			}
		}
		$found = $this->connection->run( 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE BINARY SCHEMA_NAME=BINARY ?', array( $database ), array(), 2 )['rows'];
		if ( 1 !== count( $found ) ) {
			throw new \RuntimeException( 'backup_restore_target_unavailable' );
		}
		foreach ( array(
			'TABLES'   => 'TABLE_SCHEMA',
			'ROUTINES' => 'ROUTINE_SCHEMA',
			'EVENTS'   => 'EVENT_SCHEMA',
		) as $view => $column ) {
			if ( $this->connection->run( 'SELECT 1 FROM information_schema.' . $view . ' WHERE ' . $column . '=? LIMIT 1', array( $database ), array(), 1 )['rows'] ) {
				throw new \RuntimeException( 'backup_restore_target_not_empty' );
			}
		}
	}

	/** Positive direct native database grants, including metadata visibility. */
	private function database_privilege( $privilege, $database ) {
		$grants = $this->connection->run( 'SHOW GRANTS FOR CURRENT_USER' )['rows'];
		foreach ( $grants as $row ) {
			if ( 0 === stripos( (string) reset( $row ), 'REVOKE ' ) ) {
				return false;
			}
		}
		$objects = array(
			'*.*',
			Database_Connection::quote( $database ) . '.*',
			Database_Connection::quote( str_replace( array( '\\', '_', '%' ), array( '\\\\', '\\_', '\\%' ), $database ) ) . '.*',
		);
		foreach ( $grants as $row ) {
			$grant = (string) reset( $row );
			if ( preg_match( '/^GRANT (.+) ON (.+) TO /iD', $grant, $match ) && in_array( $match[2], $objects, true ) ) {
				$values = explode( ', ', strtoupper( $match[1] ) );
				if ( in_array( 'ALL PRIVILEGES', $values, true ) || in_array( $privilege, $values, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Exact complete isolated destination objects; metadata visibility is mandatory. */
	public function assert_inventory( $database, array $tables ) {
		if ( ! $this->database_privilege( 'EVENT', $database ) || ! $this->database_privilege( 'CREATE ROUTINE', $database ) ||
			! $this->database_privilege( 'TRIGGER', $database ) ) {
			throw new \RuntimeException( 'backup_restore_object_visibility' );
		}
		$rows  = $this->connection->run(
			'SELECT TABLE_NAME AS name,TABLE_TYPE AS kind FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY BINARY TABLE_NAME',
			array( $database ),
			array(),
			max( 1, intdiv( $this->budget, 256 ) )
		)['rows'];
		$found = array();
		foreach ( $rows as $row ) {
			if ( 'BASE TABLE' !== $row['kind'] ) {
				throw new \RuntimeException( 'backup_restore_extra_objects' );
			}
			$found[] = $row['name'];
		}
		sort( $tables, SORT_STRING );
		sort( $found, SORT_STRING );
		if ( $tables !== $found ) {
			throw new \RuntimeException( 'backup_restore_extra_objects' );
		}
		foreach ( array(
			'ROUTINES' => 'ROUTINE_SCHEMA',
			'EVENTS'   => 'EVENT_SCHEMA',
			'TRIGGERS' => 'TRIGGER_SCHEMA',
		) as $view => $column ) {
			if ( $this->connection->run( 'SELECT 1 FROM information_schema.' . $view . ' WHERE ' . $column . '=? LIMIT 1', array( $database ), array(), 1 )['rows'] ) {
				throw new \RuntimeException( 'backup_restore_extra_objects' );
			}
		}
	}

	/** @param string $sql Verified generated DDL. @param string $id Stable server-owned operation ID. @param array $identity Current origin. @return array */
	public function ddl( $sql, $id, array $identity ) {
		$identity['scope'] = is_multisite() ? 'network' : 'site';
		$journal           = new Administration_Operation_Journal( 'schema', $identity, $this->budget );
		$fingerprint       = hash( 'sha256', $sql );
		$claim             = $journal->reserve(
			$id,
			$fingerprint,
			array(
				'kind'           => 'isolated_backup_restore',
				'statement_hash' => $fingerprint,
			)
		);
		self::checked( $claim );
		if ( empty( $claim['dispatch_allowed'] ) ) {
			$result = $claim['receipt']['result'] ?? array();
			if ( 'acknowledged' !== ( $result['outcome'] ?? '' ) ) {
				throw new \RuntimeException( 'backup_restore_effect_unknown' );
			}
			return $result;
		}
		$this->connection->assert_clean_session();
		self::checked( $journal->arm( $claim['claim'] ) );
		$result = $this->connection->dispatch_ddl_once( $sql );
		// No postprocessing is permitted between arm and the native send.
		$completed = $journal->complete( $claim['claim'], $result );
		if ( is_wp_error( $completed ) || 'acknowledged' !== $result['outcome'] ) {
			throw new \RuntimeException( 'backup_restore_effect_unknown' );
		}
		return $result;
	}

	/**
	 * One exact row, including duplicate/keyless rows; receipt and target are
	 * committed together. Stream offsets are server-derived from verified bytes.
	 *
	 * @param string $database Approved destination.
	 * @param array $schema Validated schema.
	 * @param array $cells Complete per-column NULL/offset/bytes/digest.
	 * @param array $source Locked verified row staging stream.
	 * @param string $group Expected typed row digest.
	 * @param string $operation Stable package/group/occurrence identity.
	 * @return void
	 */
	public function insert( $database, array $schema, array $cells, array $source, $group, $operation ) {
		$store = new Database_Control_Storage( $this->connection, $this->budget );
		$store->receipt_schema();
		$name        = 'wpai_backup_row_' . hash( 'sha256', $operation );
		$fingerprint = hash( 'sha256', json_encode( array( $database, $schema['table'], $schema['hash'], $group, $cells ) ) );
		$receipt     = json_encode(
			array(
				'format'      => 'wpai-backup-row-v1',
				'fingerprint' => $fingerprint,
				'committed'   => true,
			)
		);
		$parameters  = array();
		$expressions = array();
		$sources     = array();
		foreach ( $schema['columns'] as $i => $column ) {
			$cell = $cells[ $i ] ?? null;
			if ( ! is_array( $cell ) ) {
				throw new \RuntimeException( 'backup_restore_row_incomplete' );
			}
			if ( ! empty( $cell['null'] ) ) {
				$parameters[]  = null;
				$expressions[] = '?';
				continue;
			}
			$sources[ $i ] = array(
				'stream'          => $source['stream'],
				'offset'          => $cell['offset'],
				'bytes'           => $cell['bytes'],
				'container_bytes' => $source['bytes'],
			);
			$parameters[]  = array( 'source' => $i );
			if ( 'bit' === $column['type'] ) {
				$expressions[] = 'CAST(CONV(HEX(?),16,10) AS UNSIGNED)';
			} elseif ( 'json' === $column['type'] ) {
				$expressions[] = 'CONVERT(? USING utf8mb4)';
			} elseif ( is_string( $column['charset'] ) ) {
				if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $column['charset'] ) ) {
					throw new \RuntimeException( 'backup_restore_charset' );
				}
				$expressions[] = 'CONVERT(? USING ' . $column['charset'] . ')';
			} else {
				$expressions[] = '?';
			}
		}
		$this->connection->begin_callback_journal();
		try {
			$existing = $this->connection->run( 'SELECT option_value FROM ' . $this->connection->table( $this->connection->options ) . ' WHERE option_name=? FOR UPDATE', array( $name ), array(), 1 )['rows'];
			if ( $existing ) {
				if ( ! hash_equals( $receipt, $existing[0]['option_value'] ) ) {
					throw new \RuntimeException( 'backup_restore_receipt_conflict' );
				}
				$this->connection->rollback();
				return;
			}
			$this->connection->run( 'INSERT INTO ' . $this->connection->table( $this->connection->options ) . ' (option_name,option_value,autoload) VALUES (?,?,?)', array( $name, $receipt, 'off' ) );
			$target     = Backup_Schema::qualified( $database, $schema['table'] );
			$expression = self::row_expression( $schema['columns'] );
			$before     = $this->connection->run( 'SELECT COUNT(*) AS n FROM ' . $target . ' WHERE ' . $expression . '=?', array( $group ), array(), 1 )['rows'][0]['n'];
			$this->connection->run( 'INSERT INTO ' . $target . ' (' . implode( ',', array_map( array( Database_Connection::class, 'quote' ), array_column( $schema['columns'], 'name' ) ) ) . ') VALUES (' . implode( ',', $expressions ) . ')', $parameters, $sources );
			$verified = $this->connection->run( 'SELECT (COUNT(*)=CAST(? AS DECIMAL(65,0))+1) AS ok FROM ' . $target . ' WHERE ' . $expression . '=?', array( $before, $group ), array(), 1 )['rows'][0]['ok'];
			if ( '1' !== $verified ) {
				throw new \RuntimeException( 'backup_restore_value_normalized' );
			}
			$this->connection->commit();
		} catch ( \Throwable $error ) {
			$this->connection->rollback();
			throw $error;
		}
	}

	/**
	 * Read-only invariant after hook-capable private/authority boundaries.
	 * This observes selected DB and named session variables; it does not prove
	 * absence of an explicit empty transaction or SQL table locks. Native DDL
	 * and row-journal admission retain their existing positive clean fences.
	 *
	 * @param array $expected Admitted archive/capture representation.
	 * @return true
	 */
	public function assert_session( array $expected ) {
		$rows   = $this->connection->run(
			'SELECT DATABASE() AS db,@@SESSION.sql_mode AS sql_mode,@@SESSION.time_zone AS time_zone,@@SESSION.autocommit AS ac,@@SESSION.foreign_key_checks AS fk,@@SESSION.unique_checks AS uq',
			array(),
			array(),
			1
		)['rows'];
		$actual = 1 === count( $rows ) ? $rows[0] : array();
		if ( ! is_string( $actual['db'] ?? null ) || ! hash_equals( $this->connection->database, $actual['db'] ) ||
			'1' !== ( $actual['ac'] ?? null ) || '1' !== ( $actual['fk'] ?? null ) || '1' !== ( $actual['uq'] ?? null ) ) {
			// Do not repair caller state with USE, SET flags or transaction SQL.
			// Discard it so outer request callbacks cannot reuse unsafe wpdb.
			$this->session_discarded = true;
			try {
				$this->connection->quarantine_backup_session();
			} finally {
				throw new \RuntimeException( 'backup_native_session_changed' );
			}
		}
		if ( ! is_string( $expected['time_zone'] ?? null ) || ( $actual['time_zone'] ?? null ) !== $expected['time_zone'] ) {
			throw new \RuntimeException( 'backup_native_session_changed' );
		}
		$sets = array();
		foreach ( array( $expected['sql_mode'] ?? null, $actual['sql_mode'] ?? null ) as $mode ) {
			if ( ! is_string( $mode ) || strlen( $mode ) > 4096 ||
				( '' !== $mode && 1 !== preg_match( '/^[A-Z][A-Z0-9_]*(?:,[A-Z][A-Z0-9_]*)*$/D', $mode ) ) ) {
				throw new \RuntimeException( 'backup_native_session_changed' );
			}
			$names = '' === $mode ? array() : explode( ',', $mode );
			if ( count( $names ) !== count( array_unique( $names ) ) ) {
				throw new \RuntimeException( 'backup_native_session_changed' );
			}
			sort( $names, SORT_STRING );
			$sets[] = $names;
		}
		if ( $sets[0] !== $sets[1] ) {
			throw new \RuntimeException( 'backup_native_session_changed' );
		}
		return true;
	}

	/** @param array $session Archive native session. @param callable $callback Verified restore action. @return mixed */
	public function with_session( array $session, callable $callback ) {
		$original = $this->identity()['session'];
		foreach ( array( 'sql_mode', 'time_zone' ) as $key ) {
			if ( ! is_string( $session[ $key ] ?? null ) || strlen( $session[ $key ] ) > $this->budget ) {
				throw new \RuntimeException( 'backup_restore_session' );
			}
		}
		// A rejected caller-owned transaction/lock session receives no backup
		// setters or cleanup. Only an admitted clean session is ours to change.
		$this->connection->assert_clean_session();
		try {
			$this->connection->set_backup_sql_mode( $session['sql_mode'] );
			$this->connection->run( 'SET SESSION time_zone=?', array( $session['time_zone'] ) );
			return $callback();
		} finally {
			if ( ! $this->session_discarded ) {
				try {
					$this->connection->set_backup_sql_mode( $original['sql_mode'] );
					$this->connection->run( 'SET SESSION time_zone=?', array( $original['time_zone'] ) );
				} catch ( \Throwable $cleanup_error ) {
					try {
						$this->connection->quarantine_backup_session();
					} finally {
						throw $cleanup_error;
					}
				}
			}
		}
	}

	/** @param mixed $value API result. @return mixed */
	private static function checked( $value ) {
		if ( is_wp_error( $value ) ) {
			throw new \RuntimeException( $value->get_error_code() );
		}
		return $value;
	}
}
