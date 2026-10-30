<?php
/**
 * Typed, atomic administrator database mutations and durable recovery.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/** One focused package, not a caller-SQL or WordPress business API. */
final class Database_Writer {
	const FORMAT         = 'wpai-database-write-v1';
	const RECEIPT_PREFIX = 'wpai_database_write_';
	/** @var Database_Connection|null */
	private $connection;
	/** @var Database_Control_Storage|null */
	private $control_storage;
	/** @var int */
	private $budget;
	/** @var int */
	private $request_budget;
	/** @var int */
	private $response_budget;
	/** @var array */
	private $context;
	/** @var array */
	private $messages = array();
	/** @var bool */
	private $commit_submitted = false;
	/** @var bool */
	private $effect_submitted = false;

	/**
	 * Authorization and translations run outside the native critical section.
	 *
	 * @param array $input Typed request.
	 * @param callable $allowed Fresh native/client/network authorization.
	 * @return array|WP_Error
	 */
	public function execute( array $input, callable $allowed ) {
		class_exists( Database_Connection::class );
		class_exists( Database_Control_Storage::class );
		$this->connection       = null;
		$this->control_storage  = null;
		$this->commit_submitted = false;
		$this->effect_submitted = false;
		$this->messages         = $this->messages();
		$this->request_budget   = ( new Transfer_Settings() )->bytes()['request'];
		$this->response_budget  = Bounded_Payload::response_limit();
		// Metadata and compact receipts have several live PHP representations.
		// Their processing budget follows the current transport policies, bounded
		// by one eighth of known PHP memory; it never caps a streamed cell value.
		$memory       = Operation_Content_Limits::effective_memory_bytes();
		$this->budget = max( $this->request_budget, $this->response_budget );
		if ( $memory > 0 ) {
			$this->budget = min( $this->budget, intdiv( $memory, 8 ) );
		}
		$this->context = array(
			'blog'   => (int) get_current_blog_id(),
			'user'   => (int) get_current_user_id(),
			'client' => (string) OAuth_Server::authenticated_mcp_client_id(),
		);
		try {
			if ( $this->context['blog'] < 1 || $this->context['user'] < 1 || ! $this->authorize( $allowed ) ) {
				throw new Database_Write_Failure( 'database_write_access_denied' );
			}
			$input            = $this->validate( $input );
			$this->connection = new Database_Connection();
			if ( 'execute' === $input['action'] ) {
				$fingerprint = $this->fingerprint( $input );
				// Historical readback precedes source access, including expired stages.
				$existing = $this->transaction_read( $input, $fingerprint );
				if ( null !== $existing ) {
					return $existing;
				}
				$descriptors = $this->sources( $input );
				if ( $descriptors ) {
					$staging = new Database_Staging( new Permissions( new Settings() ) );
					return $staging->with_sources(
						$descriptors,
						function ( $sources ) use ( $input, $fingerprint, $allowed ) {
							return $this->perform( $input, $fingerprint, $sources, $allowed );
						}
					);
				}
				return $this->perform( $input, $fingerprint, array(), $allowed );
			}
			$descriptors = $this->sources( $input );
			if ( $descriptors ) {
				return ( new Database_Staging( new Permissions( new Settings() ) ) )->with_sources(
					$descriptors,
					function ( $sources ) use ( $input, $allowed ) {
						return $this->read_action( $input, $allowed, $sources );
					}
				);
			}
			return $this->read_action( $input, $allowed, array() );
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/**
	 * Bind admission to the captured site/principal/client even if a permission
	 * filter or source acquisition changed WordPress context before entry.
	 *
	 * @param callable $allowed Current authorization.
	 * @return bool
	 */
	private function authorize( $allowed ) {
		return $allowed() &&
			(int) get_current_blog_id() === $this->context['blog'] &&
			(int) get_current_user_id() === $this->context['user'] &&
			(string) OAuth_Server::authenticated_mcp_client_id() === $this->context['client'];
	}

	/** @param array $input Request. @param callable $allowed Gate. @param array $sources Retained streams. @return array|WP_Error */
	private function read_action( $input, $allowed, $sources ) {
		try {
			if ( ! $this->authorize( $allowed ) ) {
				throw new Database_Write_Failure( 'database_write_access_denied' );
			}
			$this->connection->begin();
			if ( 'recover' === $input['action'] ) {
				$result = $this->read_receipt( $input, null );
				$result = null === $result ? array(
					'format'        => self::FORMAT,
					'status'        => 'not_recorded',
					'operation_ref' => $this->reference( $input['operation_id'] ),
				) : $result;
			} else {
				$schema = $this->schema( $input['table'] );
				$result = array(
					'format'      => self::FORMAT,
					'table'       => $input['table'],
					'schema_hash' => $schema['hash'],
				);
				if ( 'describe' === $input['action'] ) {
					$result['primary_key']   = $schema['primary'];
					$result['compatible']    = true;
					$result['compatibility'] = array(
						'engine'             => 'InnoDB',
						'trigger_visibility' => 'verified',
						'fk_metadata'        => 'checked_when_required',
					);
				} else {
					$key                  = $this->key( $input['key'], $schema );
					$row                  = $this->row( $schema, $key, $sources );
					$result['state_hash'] = $row['hash'];
				}
			}
			if ( ! $this->connection->rollback() ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			$this->fit( $result );
			return $result;
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/** @return Database_Control_Storage Current transaction metadata verifier. */
	private function storage() {
		if ( null === $this->control_storage ) {
			$this->control_storage = new Database_Control_Storage( $this->connection, $this->budget );
		}
		return $this->control_storage;
	}

	/** @param array $input Request. @return array Complete retained key/value descriptors. */
	private function sources( $input ) {
		$descriptors = array();
		$steps       = 'execute' === $input['action'] ? $input['operations'] : array( $input );
		foreach ( $steps as $step ) {
			foreach ( array( 'key', 'values' ) as $field ) {
				foreach ( $step[ $field ] ?? array() as $value ) {
					if ( is_array( $value ) ) {
						$descriptors[] = $value;
					}
				}
			}
		}
		return $descriptors;
	}

	/** @param array $input Validated input. @param string $fingerprint Request identity. @return array|null */
	private function transaction_read( $input, $fingerprint ) {
		$this->connection->begin();
		try {
			$result = $this->read_receipt( $input, $fingerprint );
		} finally {
			if ( ! $this->connection->rollback() ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
		}
		return $result;
	}

	/**
	 * No authorization, translation, option, cache or logging callbacks after begin.
	 *
	 * @param array $input Validated input.
	 * @param string $fingerprint Canonical fingerprint.
	 * @param array $sources Retained verified native resources.
	 * @param callable $allowed Authorization immediately before native entry.
	 * @return array|WP_Error
	 */
	private function perform( $input, $fingerprint, $sources, $allowed ) {
		try {
			if ( ! $this->authorize( $allowed ) ) {
				throw new Database_Write_Failure( 'database_write_access_denied' );
			}
			$reference             = $this->reference( $input['operation_id'] );
			$receipt_name          = self::RECEIPT_PREFIX . $reference;
			$event                 = bin2hex( random_bytes( 8 ) );
			$time                  = gmdate( 'c' );
			$this->control_storage = null;
			$this->connection->begin();
			$this->receipt_schema();
			$initial = $this->encode(
				array(
					'format'      => self::FORMAT,
					'state'       => 'pending',
					'fingerprint' => $fingerprint,
				)
			);
			try {
				// FIRST DML. The database unique index arbitrates unresolved attempts.
				$this->connection->run(
					'INSERT INTO ' . $this->connection->table( $this->connection->options ) . ' (option_name, option_value, autoload) VALUES (?, ?, ?)',
					array( $receipt_name, $initial, 'off' )
				);
			} catch ( Database_Write_Failure $error ) {
				if ( 1062 !== ( $error->details['errno'] ?? 0 ) ) {
					if ( in_array( $error->details['errno'] ?? 0, array( 1205, 1213 ), true ) ) {
						throw new Database_Write_Failure( 'database_write_outcome_unknown', $error->details );
					}
					throw $error;
				}
				$prior = $this->read_receipt( $input, $fingerprint );
				if ( null === $prior ) {
					throw new Database_Write_Failure( 'database_write_outcome_unknown' );
				}
				if ( ! $this->connection->rollback() ) {
					throw new Database_Write_Failure( 'database_write_outcome_unknown' );
				}
				return $prior;
			}
			// Pin every target before the first target effect.
			foreach ( $input['operations'] as $operation ) {
				$schema = $this->schema( $operation['table'] );
				if ( ! hash_equals( $schema['hash'], $operation['expected_schema_hash'] ) ) {
					throw new Database_Write_Failure( 'database_write_schema_stale' );
				}
			}
			$steps       = array();
			$destructive = false;
			foreach ( $input['operations'] as $index => $operation ) {
				$steps[]     = $this->step( $operation, $index, $sources );
				$destructive = $destructive || 'delete' === $operation['action'];
			}
			$result = array(
				'format'        => self::FORMAT,
				'status'        => 'committed',
				'historical'    => false,
				'operation_ref' => $reference,
				'steps'         => $steps,
				'audit'         => array(
					'event_ref'          => $event,
					'timestamp'          => $time,
					'blog_id'            => $this->context['blog'],
					'user_id'            => $this->context['user'],
					'client_fingerprint' => substr( hash( 'sha256', $this->context['client'] ), 0, 16 ),
				),
			);
			$this->fit( $result );
			$final = $this->encode(
				array(
					'format'      => self::FORMAT,
					'state'       => 'committed',
					'fingerprint' => $fingerprint,
					'destructive' => $destructive,
					'result'      => $result,
				)
			);
			if ( ! is_string( $final ) || strlen( $final ) > $this->budget ) {
				throw new Database_Write_Failure( 'database_write_budget' );
			}
			$updated = $this->connection->run(
				'UPDATE ' . $this->connection->table( $this->connection->options ) . ' SET option_value = ? WHERE option_name = ? AND BINARY option_value = BINARY ?',
				array( $final, $receipt_name, $initial )
			);
			if ( 1 !== $updated['affected'] ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown' );
			}
			$this->commit_submitted      = true;
			$restored                    = $this->connection->commit();
			$this->commit_submitted      = false;
			$result['reporting_pending'] = ! $restored;
			// Outside the transaction. Projection failure cannot turn commit into failure.
			if ( $restored ) {
				try {
					wp_cache_delete( $receipt_name, 'options' );
					wp_cache_delete( 'notoptions', 'options' );
					$result['reporting_pending'] = ! ( new Mutation_Log() )->record( 'wp-ai-bridge/database-write', 'database_write', 0, true );
				} catch ( \Throwable $error ) {
					$result['reporting_pending'] = true;
				}
			}
			return $result;
		} catch ( \Throwable $error ) {
			return $this->failure( $error );
		}
	}

	/** @param array $input Request. @param string|null $fingerprint Optional exact request. @return array|null */
	private function read_receipt( $input, $fingerprint ) {
		$this->receipt_schema();
		try {
			$rows = $this->connection->run(
				'SELECT SUBSTRING(option_value,1,?) AS value, OCTET_LENGTH(option_value) AS bytes FROM ' .
					$this->connection->table( $this->connection->options ) . ' WHERE option_name = ? FOR UPDATE',
				array( (string) ( $this->budget + 1 ), self::RECEIPT_PREFIX . $this->reference( $input['operation_id'] ) ),
				array(),
				2
			)['rows'];
		} catch ( Database_Write_Failure $error ) {
			// This timeout may concern an earlier in-flight invocation.
			if ( in_array( $error->details['errno'] ?? 0, array( 1205, 1213 ), true ) ) {
				throw new Database_Write_Failure( 'database_write_outcome_unknown', $error->details );
			}
			throw $error;
		}
		if ( ! $rows ) {
			return null;
		}
		if ( (int) $rows[0]['bytes'] > $this->budget ) {
			throw new Database_Write_Failure( 'database_write_budget' );
		}
		if ( count( $rows ) !== 1 ) {
			throw new Database_Write_Failure( 'database_write_outcome_unknown' );
		}
		$receipt = json_decode( $rows[0]['value'], true );
		if ( ! is_array( $receipt ) || self::FORMAT !== ( $receipt['format'] ?? '' ) ||
			'committed' !== ( $receipt['state'] ?? '' ) || ! is_array( $receipt['result'] ?? null ) ||
			! $this->digest( $receipt['fingerprint'] ?? null ) ) {
			throw new Database_Write_Failure( 'database_write_outcome_unknown' );
		}
		if ( null !== $fingerprint && ! hash_equals( $receipt['fingerprint'], $fingerprint ) ) {
			throw new Database_Write_Failure( 'database_write_operation_conflict' );
		}
		if ( ! empty( $receipt['destructive'] ) ) {
			$delete_allowed = 'execute' === $input['action'] ?
				in_array( 'delete', array_column( $input['operations'], 'action' ), true ) : ! empty( $input['destructive'] );
			if ( ! $delete_allowed ) {
				throw new Database_Write_Failure( 'database_write_access_denied' );
			}
		}
		$result               = $receipt['result'];
		$result['historical'] = true;
		$this->fit( $result );
		return $result;
	}

	/** Receipt storage must satisfy exactly the same engine/effect rules. */
	private function receipt_schema() {
		$this->storage()->receipt_schema();
	}

	/** @param string $table Exact table. @return array */
	private function schema( $table ) {
		return $this->storage()->schema( $table );
	}

	/** @param array $schema Native schema. @param array $key Decoded full key. @return array */
	private function row( $schema, $key, $sources = array() ) {
		$select = array();
		$names  = array_keys( $schema['columns'] );
		foreach ( $names as $index => $name ) {
			$column   = Database_Connection::quote( (string) $name );
			$select[] = $column . ' IS NULL AS n' . $index;
			$select[] = 'OCTET_LENGTH(CAST(' . $column . ' AS BINARY)) AS l' . $index;
			$select[] = 'SHA2(CAST(' . $column . ' AS BINARY),256) AS h' . $index;
		}
		$policy   = $this->policy( $schema['table'] );
		$select[] = $this->protected_expression( $policy, $schema ) . ' AS protected_row';
		if ( 'postmeta' === $policy ) {
			$select[] = 'CAST(post_id AS BINARY) AS parent_id';
		}
		$where = $this->where( $schema, $key );
		$rows  = $this->connection->run(
			'SELECT ' . implode( ', ', $select ) . ' FROM ' . $this->connection->table( $schema['table'] ) . ' WHERE ' . $where[0] . ' LIMIT 2 FOR UPDATE',
			$where[1],
			$sources,
			2
		)['rows'];
		if ( count( $rows ) !== 1 ) {
			throw new Database_Write_Failure( 'database_write_row_missing' );
		}
		$row    = $rows[0];
		$states = array();
		foreach ( $names as $index => $name ) {
			$is_null = '1' === $row[ 'n' . $index ];
			if ( ! $is_null && ( ! $this->digest( $row[ 'h' . $index ] ) || ! ctype_digit( $row[ 'l' . $index ] ) ) ) {
				throw new Database_Write_Failure( 'database_write_unsupported_schema' );
			}
			$states[ $name ] = array(
				'null'   => $is_null,
				'bytes'  => $row[ 'l' . $index ],
				'sha256' => $row[ 'h' . $index ],
			);
		}
		return array(
			'hash'      => hash( 'sha256', $this->encode( array( self::FORMAT, $schema['hash'], $states ), 'database_write_unsupported_schema' ) ),
			'cells'     => $states,
			'protected' => '1' === $row['protected_row'],
			'parent'    => $row['parent_id'] ?? null,
		);
	}

	/** @param array $schema Schema. @param array $key Decoded key. @return array */
	private function where( $schema, $key ) {
		$where  = array();
		$params = array();
		foreach ( $schema['primary'] as $name ) {
			$column   = Database_Connection::quote( $name );
			$where[]  = '(' . $column . ' = ? AND CAST(' . $column . ' AS BINARY) = CAST(? AS BINARY))';
			$params[] = $key[ $name ];
			$params[] = $key[ $name ];
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/** @param array $operation Step. @param int $index Ordered index. @param array $sources Streams. @return array */
	private function step( $operation, $index, $sources ) {
		$schema = $this->schema( $operation['table'] );
		$values = array();
		foreach ( $operation['values'] ?? array() as $name => $value ) {
			$name = (string) $name;
			if ( ! isset( $schema['columns'][ $name ] ) || ( 'update' === $operation['action'] && in_array( $name, $schema['primary'], true ) ) ) {
				throw new Database_Write_Failure( 'database_write_invalid' );
			}
			if ( is_array( $value ) && ! preg_match( '/^(?:tiny|medium|long)?(?:text|blob)$/D', $schema['columns'][ $name ]['type'] ) ) {
				throw new Database_Write_Failure( 'database_write_source_type' );
			}
			$values[ $name ] = is_array( $value ) ? array( 'source' => $value['transfer_id'] ) : $this->scalar( $value );
		}
		$action    = $operation['action'];
		$auto_name = null;
		$auto_id   = null;
		$before    = null;
		if ( 'insert' === $action ) {
			$key = array();
			foreach ( $schema['primary'] as $name ) {
				if ( array_key_exists( $name, $values ) && ( is_string( $values[ $name ] ) || is_array( $values[ $name ] ) ) ) {
					$key[ $name ] = $values[ $name ];
				} elseif ( ! array_key_exists( $name, $values ) && null === $auto_name &&
					false !== stripos( $schema['columns'][ $name ]['extra'], 'auto_increment' ) &&
					in_array( $schema['columns'][ $name ]['type'], array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint' ), true ) ) {
					$auto_name = $name;
				} else {
					throw new Database_Write_Failure( 'database_write_primary_key_required' );
				}
			}
		} else {
			$key    = $this->key( $operation['key'], $schema );
			$before = $this->row( $schema, $key, $sources );
			if ( ! hash_equals( $before['hash'], $operation['expected_state_hash'] ) ) {
				throw new Database_Write_Failure( 'database_write_row_stale' );
			}
			if ( $before['protected'] ) {
				throw new Database_Write_Failure( 'database_write_control_state' );
			}
		}
		$this->control_guard( $schema, $values, $before );
		$unchanged = 'update' === $action;
		if ( $unchanged ) {
			foreach ( $operation['values'] as $name => $value ) {
				if ( ! $this->same( $before['cells'][ $name ], $value ) ) {
					$unchanged = false;
				}
			}
		}
		if ( $unchanged ) {
			return array(
				'index'      => $index,
				'action'     => $action,
				'outcome'    => 'unchanged',
				'affected'   => 0,
				'auto_id'    => null,
				'state_hash' => $before['hash'],
			);
		}
		$indexed_update = 'update' === $action && array_intersect( array_keys( $values ), array_keys( $schema['indexed'] ) );
		if ( 'delete' === $action || $indexed_update ) {
			$this->foreign_keys( $schema, $action );
		}
		$where  = 'insert' === $action ? null : $this->where( $schema, $key );
		$table  = $this->connection->table( $schema['table'] );
		$params = array_values( $values );
		if ( 'insert' === $action ) {
			$sql = 'INSERT INTO ' . $table . ' (' . implode( ', ', array_map( array( Database_Connection::class, 'quote' ), array_map( 'strval', array_keys( $values ) ) ) ) .
				') VALUES (' . implode( ', ', array_fill( 0, count( $values ), '?' ) ) . ')';
		} elseif ( 'update' === $action ) {
			$set = array();
			foreach ( array_keys( $values ) as $name ) {
				$set[] = Database_Connection::quote( (string) $name ) . ' = ?';
			}
			$sql    = 'UPDATE ' . $table . ' SET ' . implode( ', ', $set ) . ' WHERE ' . $where[0];
			$params = array_merge( $params, $where[1] );
		} else {
			$sql    = 'DELETE FROM ' . $table . ' WHERE ' . $where[0];
			$params = $where[1];
		}
		$this->effect_submitted = true;
		$native                 = $this->connection->run( $sql, $params, $sources );
		if ( 'insert' === $action && null !== $auto_name ) {
			$auto_id = $native['insert_id'];
			if ( ! preg_match( '/^[1-9][0-9]*$/D', $auto_id ) ) {
				throw new Database_Write_Failure( 'database_write_normalization' );
			}
			$key[ $auto_name ] = $auto_id;
		}
		if ( 'delete' === $action ) {
			if ( 1 !== $native['affected'] ) {
				throw new Database_Write_Failure( 'database_write_row_stale' );
			}
			$after = null;
		} else {
			$after = $this->row( $schema, $key, $sources );
			if ( $after['protected'] ) {
				throw new Database_Write_Failure( 'database_write_control_state' );
			}
			foreach ( $operation['values'] as $name => $value ) {
				if ( ! $this->same( $after['cells'][ $name ], $value ) ) {
					throw new Database_Write_Failure( 'database_write_normalization' );
				}
			}
		}
		return array(
			'index'      => $index,
			'action'     => $action,
			'outcome'    => array(
				'insert' => 'inserted',
				'update' => 'updated',
				'delete' => 'deleted',
			)[ $action ],
			'affected'   => 1,
			'auto_id'    => $auto_id,
			'state_hash' => $after['hash'] ?? null,
		);
	}

	/** @param array $cell Hashed SQL-visible value. @param mixed $value Canonical supplied value. @return bool */
	private function same( $cell, $value ) {
		if ( null === $value ) {
			return $cell['null'];
		}
		if ( $cell['null'] ) {
			return false;
		}
		$bytes = is_array( $value ) ? $value['total_bytes'] : strlen( $this->scalar( $value ) );
		$hash  = is_array( $value ) ? $value['sha256'] : hash( 'sha256', $this->scalar( $value ) );
		return (string) $bytes === $cell['bytes'] && hash_equals( $hash, $cell['sha256'] );
	}

	/** Full graph visibility prevents invisible cross-schema cascade effects. */
	private function foreign_keys( $schema, $action ) {
		$this->storage()->foreign_keys( $schema, $action );
	}

	/** @param string $table Native table. @return string */
	private function policy( $table ) {
		if ( preg_match( '/^' . preg_quote( $this->connection->prefix, '/' ) . '(?:[0-9]+_)?(options|sitemeta|blogmeta|posts|postmeta)$/D', $table, $match ) ) {
			return $match[1];
		}
		if ( 0 === strpos( $table, $this->connection->prefix . 'wpai_' ) ||
			0 === strpos( $table, $this->connection->prefix . 'wp_ai_bridge' ) ) {
			return 'bridge';
		}
		return '';
	}

	/** @param string $policy Fixed policy. @param array $schema Native metadata. @return string */
	private function protected_expression( $policy, $schema ) {
		if ( 'bridge' === $policy ) {
			return '1';
		}
		if ( 'posts' === $policy ) {
			return "post_type IN ('wpai_doc','wpai_task')";
		}
		$name = 'options' === $policy ? 'option_name' : ( in_array( $policy, array( 'sitemeta', 'blogmeta', 'postmeta' ), true ) ? 'meta_key' : null );
		if ( null === $name ) {
			return '0';
		}
		if ( ! isset( $schema['columns'][ $name ] ) ) {
			throw new Database_Write_Failure( 'database_write_unsupported_schema' );
		}
		return $this->name_expression( Database_Connection::quote( $name ), 'postmeta' === $policy );
	}

	/** @param string $column Internally generated expression. @param bool $meta Workspace metadata. @return string */
	private function name_expression( $column, $meta = false ) {
		return Database_Control_Storage::name_expression( $column, $meta );
	}

	/** Locked old row and proposed collation identities protect control state. */
	private function control_guard( $schema, $values, $before ) {
		$policy = $this->policy( $schema['table'] );
		if ( 'bridge' === $policy ) {
			throw new Database_Write_Failure( 'database_write_control_state' );
		}
		$identity = 'posts' === $policy ? 'post_type' : ( 'options' === $policy ? 'option_name' : ( in_array( $policy, array( 'sitemeta', 'blogmeta', 'postmeta' ), true ) ? 'meta_key' : null ) );
		if ( null !== $identity && array_key_exists( $identity, $values ) ) {
			if ( is_array( $values[ $identity ] ) ) {
				throw new Database_Write_Failure( 'database_write_control_state' );
			}
			// Convert through the actual native column collation, not PHP casing.
			$column    = $schema['columns'][ $identity ];
			$charset   = $column['charset'];
			$collation = $column['collation'];
			if ( ! is_string( $charset ) || ! preg_match( '/^[a-z0-9_]+$/iD', $charset ) ||
				! is_string( $collation ) || ! preg_match( '/^[a-z0-9_]+$/iD', $collation ) ) {
				throw new Database_Write_Failure( 'database_write_control_state' );
			}
			$expression = 'CONVERT(? USING ' . $charset . ') COLLATE ' . $collation;
			$expression = 'posts' === $policy ? $expression . " IN ('wpai_doc','wpai_task')" : $this->name_expression( $expression, 'postmeta' === $policy );
			$params     = array_fill( 0, substr_count( $expression, '?' ), $values[ $identity ] );
			$protected  = $this->connection->run( 'SELECT (' . $expression . ') AS blocked', $params )['rows'][0]['blocked'] ?? null;
			if ( '0' !== $protected ) {
				throw new Database_Write_Failure( 'database_write_control_state' );
			}
		}
		if ( null === $before && null !== $identity && ! array_key_exists( $identity, $values ) ) {
			throw new Database_Write_Failure( 'database_write_control_state' );
		}
		if ( 'postmeta' === $policy ) {
			$parents = array();
			if ( null !== $before ) {
				$parents[] = $before['parent'];
			}
			if ( array_key_exists( 'post_id', $values ) ) {
				$parents[] = $values['post_id'];
			}
			if ( ! $parents ) {
				throw new Database_Write_Failure( 'database_write_control_state' );
			}
			$posts = substr( $schema['table'], 0, -strlen( 'postmeta' ) ) . 'posts';
			$this->schema( $posts );
			foreach ( $parents as $parent ) {
				if ( ! is_string( $parent ) || ! ctype_digit( $parent ) ) {
					throw new Database_Write_Failure( 'database_write_control_state' );
				}
				$row = $this->connection->run(
					"SELECT post_type IN ('wpai_doc','wpai_task') AS blocked FROM " . $this->connection->table( $posts ) . ' WHERE ID=? FOR UPDATE',
					array( $parent ),
					array(),
					2
				)['rows'];
				if ( count( $row ) !== 1 || '0' !== $row[0]['blocked'] ) {
					throw new Database_Write_Failure( 'database_write_control_state' );
				}
			}
		}
	}

	/** @param mixed $key Complete encoded key. @param array $schema Schema. @return array */
	private function key( $key, $schema ) {
		if ( $key instanceof \stdClass ) {
			$key = (array) $key;
		}
		if ( ! is_array( $key ) || count( $key ) !== count( $schema['primary'] ) ||
			array_diff( array_map( 'strval', array_keys( $key ) ), $schema['primary'] ) ) {
			throw new Database_Write_Failure( 'database_write_primary_key_required' );
		}
		$decoded = array();
		foreach ( $schema['primary'] as $name ) {
			if ( ! array_key_exists( $name, $key ) || null === $key[ $name ] ) {
				throw new Database_Write_Failure( 'database_write_primary_key_required' );
			}
			if ( is_array( $key[ $name ] ) ) {
				if ( ! preg_match( '/^(?:tiny|medium|long)?(?:text|blob)$/D', $schema['columns'][ $name ]['type'] ) ) {
					throw new Database_Write_Failure( 'database_write_source_type' );
				}
				$decoded[ $name ] = array( 'source' => $key[ $name ]['transfer_id'] );
			} else {
				$decoded[ $name ] = $this->scalar( $key[ $name ] );
			}
		}
		return $decoded;
	}

	/** @param mixed $value Base64/NULL. @return string|null */
	private function scalar( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			throw new Database_Write_Failure( 'database_write_invalid' );
		}
		$decoded = base64_decode( $value, true );
		if ( false === $decoded || base64_encode( $decoded ) !== $value ) {
			throw new Database_Write_Failure( 'database_write_invalid' );
		}
		return $decoded;
	}

	/** @param array $input Public request. @return array */
	private function validate( $input ) {
		$encoded = $this->encode( $input );
		if ( is_string( $encoded ) && strlen( $encoded ) > $this->request_budget ) {
			throw new Database_Write_Failure( 'database_write_budget' );
		}
		if ( ! is_string( $encoded ) || ! isset( $input['action'] ) ||
			! in_array( $input['action'], array( 'describe', 'row_state', 'execute', 'recover' ), true ) ) {
			throw new Database_Write_Failure( 'database_write_invalid' );
		}
		$action = $input['action'];
		$fields = array(
			'describe'  => array( 'action', 'table' ),
			'row_state' => array( 'action', 'table', 'key' ),
			'execute'   => array( 'action', 'operation_id', 'operations' ),
			'recover'   => array( 'action', 'operation_id', 'destructive' ),
		)[ $action ];
		if ( array_diff( array_keys( $input ), $fields ) ) {
			throw new Database_Write_Failure( 'database_write_invalid' );
		}
		if ( in_array( $action, array( 'describe', 'row_state' ), true ) && ! $this->identifier( $input['table'] ?? null ) ) {
			throw new Database_Write_Failure( 'database_write_invalid' );
		}
		if ( 'row_state' === $action && ! isset( $input['key'] ) ) {
			throw new Database_Write_Failure( 'database_write_invalid' );
		}
		if ( in_array( $action, array( 'execute', 'recover' ), true ) &&
			( ! is_string( $input['operation_id'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_.:-]{8,128}$/D', $input['operation_id'] ) ) ) {
			throw new Database_Write_Failure( 'database_write_invalid' );
		}
		if ( 'recover' === $action && isset( $input['destructive'] ) && ! is_bool( $input['destructive'] ) ) {
			throw new Database_Write_Failure( 'database_write_invalid' );
		}
		if ( 'row_state' === $action ) {
			$input['key'] = $this->validate_map( $input['key'] );
		}
		if ( 'execute' !== $action ) {
			return $input;
		}
		if ( ! is_array( $input['operations'] ?? null ) || ! array_is_list( $input['operations'] ) || ! $input['operations'] ) {
			throw new Database_Write_Failure( 'database_write_invalid' );
		}
		foreach ( $input['operations'] as &$operation ) {
			if ( $operation instanceof \stdClass ) {
				$operation = (array) $operation;
			}
			if ( ! is_array( $operation ) || ! in_array( $operation['action'] ?? null, array( 'insert', 'update', 'delete' ), true ) ||
				! $this->identifier( $operation['table'] ?? null ) || ! $this->digest( $operation['expected_schema_hash'] ?? null ) ) {
				throw new Database_Write_Failure( 'database_write_invalid' );
			}
			$required = array( 'action', 'table', 'expected_schema_hash' );
			if ( 'insert' !== $operation['action'] ) {
				$required = array_merge( $required, array( 'key', 'expected_state_hash' ) );
				if ( ! isset( $operation['key'] ) || ! $this->digest( $operation['expected_state_hash'] ?? null ) ) {
					throw new Database_Write_Failure( 'database_write_invalid' );
				}
				$operation['key'] = $this->validate_map( $operation['key'] );
			}
			if ( 'delete' !== $operation['action'] ) {
				$required[]          = 'values';
				$operation['values'] = $this->validate_map( $operation['values'] ?? null, 'insert' === $operation['action'] );
			}
			if ( array_diff( array_keys( $operation ), $required ) ) {
				throw new Database_Write_Failure( 'database_write_invalid' );
			}
		}
		unset( $operation );
		return $input;
	}

	/** @param mixed $map Complete key/value map. @param bool $empty_allowed All-default insert. @return array */
	private function validate_map( $map, $empty_allowed = false ) {
		if ( $map instanceof \stdClass ) {
			$map = (array) $map;
		}
		if ( ! is_array( $map ) || ( ! $empty_allowed && ! $map ) ) {
			throw new Database_Write_Failure( 'database_write_invalid' );
		}
		foreach ( $map as $name => &$value ) {
			if ( ! $this->identifier( (string) $name ) ) {
				throw new Database_Write_Failure( 'database_write_invalid' );
			}
			if ( $value instanceof \stdClass ) {
				$value = (array) $value;
			}
			if ( is_array( $value ) ) {
				$keys = array_keys( $value );
				sort( $keys );
				if ( array( 'sha256', 'total_bytes', 'transfer_id' ) !== $keys ||
					! $this->digest( $value['transfer_id'] ) ||
					! is_int( $value['total_bytes'] ) || $value['total_bytes'] < 0 || ! $this->digest( $value['sha256'] ) ) {
					throw new Database_Write_Failure( 'database_write_invalid' );
				}
			} else {
				$this->scalar( $value );
			}
		}
		unset( $value );
		return $map;
	}

	/** @param mixed $value Exact native identifier. @return bool */
	private function identifier( $value ) {
		return is_string( $value ) && false === strpos( $value, "\0" ) && 1 === preg_match( '/^.{1,64}$/usD', $value );
	}
	/** @param mixed $value SHA256. @return bool */
	private function digest( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}
	/** @param string $id Stable operation ID. @return string */
	private function reference( $id ) {
		return hash( 'sha256', $this->encode( array( self::FORMAT, $this->context, $id ) ) );
	}
	/** @param array $input Canonical input. @return string */
	private function fingerprint( $input ) {
		$operations = array();
		foreach ( $input['operations'] as $operation ) {
			foreach ( array( 'values', 'key' ) as $field ) {
				if ( ! isset( $operation[ $field ] ) ) {
					continue;
				}
				$map = (array) $operation[ $field ];
				ksort( $map, SORT_STRING );
				foreach ( $map as &$value ) {
					$value = is_array( $value ) ? array(
						'bytes'  => $value['total_bytes'],
						'sha256' => $value['sha256'],
					) :
						( null === $value ? null : array(
							'bytes'  => strlen( $this->scalar( $value ) ),
							'sha256' => hash( 'sha256', $this->scalar( $value ) ),
						) );
				}
				unset( $value );
				$operation[ $field ] = (object) $map;
			}
			ksort( $operation, SORT_STRING );
			$operations[] = $operation;
		}
		return hash( 'sha256', $this->encode( array( self::FORMAT, $this->context, $input['operation_id'], $operations ) ) );
	}
	/** @param mixed $value Native metadata tree. @return mixed Lossless JSON-safe metadata. */
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

	/** @param mixed $value Bounded identity/result. @param string $reason Fixed failure code. @return string */
	private function encode( $value, $reason = 'database_write_invalid' ) {
		$json = json_encode( $value );
		if ( ! is_string( $json ) || '' === $json ) {
			throw new Database_Write_Failure( $reason );
		}
		return $json;
	}

	/** @param array $result Compact result. @return void */
	private function fit( $result ) {
		$json = $this->encode( $result, 'database_write_budget' );
		if ( ! is_string( $json ) || 4 * strlen( $json ) + 4096 > $this->response_budget ) {
			throw new Database_Write_Failure( 'database_write_budget' );
		}
	}
	/** @param \Throwable $error Internal error. @return WP_Error */
	private function failure( $error ) {
		$rolled_back = null === $this->connection || $this->connection->rollback();
		$reason      = $error instanceof Database_Write_Failure ? $error->reason : 'database_write_outcome_unknown';
		$details     = $error instanceof Database_Write_Failure ? $error->details : array();
		if ( $this->commit_submitted || ! $rolled_back ||
			( $this->effect_submitted && ( $details['errno'] ?? 0 ) >= 2000 ) ) {
			$reason = 'database_write_outcome_unknown';
		}
		$details['outcome'] = 'database_write_outcome_unknown' === $reason ? 'unknown' : 'not_committed';
		return new WP_Error( $reason, $this->messages[ $reason ] ?? $this->messages['database_write_query_failed'], $details );
	}

	/** Translated before the critical section; no translation hooks within it. @return array */
	private function messages() {
		return array(
			'database_write_invalid'                => __( 'Use the published typed database request, complete keys and canonical Base64 or NULL values.', 'wp-ai-bridge' ),
			'database_write_access_denied'          => __( 'Current database write consent and native administrator/network authority are required. Delete receipt recovery also requires destructive=true and Delete consent.', 'wp-ai-bridge' ),
			'database_write_unsupported_connection' => __( 'Typed writes require Core wpdb with its existing mysqlnd connection and current database. Database drop-ins and unverified routing are unsupported.', 'wp-ai-bridge' ),
			'database_write_unsupported_session'    => __( 'Typed writes require an independent autocommit session with native constraint checks enabled. Finish the existing transaction before retrying the same request.', 'wp-ai-bridge' ),
			'database_write_unsupported_table'      => __( 'Typed writes require an actual persistent installation-prefixed InnoDB base table. Temporary shadows, views and other engines are unsupported.', 'wp-ai-bridge' ),
			'database_write_unsupported_schema'     => __( 'This typed package cannot verify the table schema, executable defaults, generated columns, checks or receipt unique key. Use a supported native administration contract.', 'wp-ai-bridge' ),
			'database_write_trigger_visibility'     => __( 'The current database account must have a verifiable direct TRIGGER privilege for this table. An empty hidden trigger listing is insufficient.', 'wp-ai-bridge' ),
			'database_write_trigger_effects'        => __( 'This typed package cannot safely scope table trigger effects. Use the owning native operation or a separately supported database contract.', 'wp-ai-bridge' ),
			'database_write_fk_visibility'          => __( 'Delete and indexed-column updates require existing-account access to the complete native InnoDB foreign-key metadata. No credentials or privileges were changed.', 'wp-ai-bridge' ),
			'database_write_fk_identity'            => __( 'The native foreign-key parent identity could not be verified losslessly. This package requires lower_case_table_names=0 and, on MariaDB, supported filename conversion with a resolved InnoDB dictionary entry. Unverified case, legacy and partition representations are unsupported.', 'wp-ai-bridge' ),
			'database_write_fk_effects'             => __( 'This typed package does not execute mutating inbound foreign-key cascades or SET NULL effects.', 'wp-ai-bridge' ),
			'database_write_primary_key_required'   => __( 'Provide every complete PRIMARY KEY component. Inserts may omit one native integer auto-increment component; updates cannot change primary keys.', 'wp-ai-bridge' ),
			'database_write_schema_stale'           => __( 'The table schema changed after inspection. Describe the table again before submitting a new request.', 'wp-ai-bridge' ),
			'database_write_row_missing'            => __( 'The complete primary key does not identify exactly one current row. Refresh the row state.', 'wp-ai-bridge' ),
			'database_write_row_stale'              => __( 'The physical row changed after inspection. Refresh its state before submitting a new request.', 'wp-ai-bridge' ),
			'database_write_control_state'          => __( 'Bridge authorization, recovery and Workspace control state must be changed through its owning native lifecycle.', 'wp-ai-bridge' ),
			'database_write_packet_limit'           => __( 'The database value or statement exceeds the current native max_allowed_packet capacity. Ask the database administrator to configure sufficient packet capacity, then recover or use the same operation_id and exact request.', 'wp-ai-bridge' ),
			'database_write_source_type'            => __( 'Staged database values are supported only for TEXT and BLOB columns in this typed package.', 'wp-ai-bridge' ),
			'database_write_source_unavailable'     => __( 'The verified database value source is unavailable or exceeds the native packet capacity. Reconcile the operation before changing its identity.', 'wp-ai-bridge' ),
			'database_write_operation_conflict'     => __( 'This operation_id already committed a different request. Recover the original operation instead of changing its input.', 'wp-ai-bridge' ),
			'database_write_warning'                => __( 'The database reported a warning. The typed batch was rejected to prevent silent coercion or partial results.', 'wp-ai-bridge' ),
			'database_write_normalization'          => __( 'The stored SQL-visible bytes differ from the supplied value. The entire typed batch was rejected.', 'wp-ai-bridge' ),
			'database_write_budget'                 => __( 'The request, schema or compact result exceeds the current operator payload budget. Use fewer steps/fields, staged values, or an appropriate administrator budget.', 'wp-ai-bridge' ),
			'database_write_query_failed'           => __( 'The native database statement failed. Inspect its redacted numeric diagnostic and database availability or privileges.', 'wp-ai-bridge' ),
			'database_write_outcome_unknown'        => __( 'The database outcome is uncertain. Recover or resubmit only the same operation_id and exact request; never use a new ID to bypass recovery.', 'wp-ai-bridge' ),
		);
	}
}
