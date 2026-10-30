<?php
/**
 * Typed installation schema execution with nontransactional acknowledgements.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/** Each operation has a durable one-dispatch fence, never transactional DDL CAS. */
final class Database_Schema_Operation {
	const FORMAT = 'wpai-database-schema-v1';

	/** @param array $input Closed public input. @param callable $allowed Current authority. @return array|WP_Error */
	public function execute( array $input, callable $allowed ) {
		$claim   = null;
		$armed   = false;
		$journal = null;
		$ack     = null;
		try {
			class_exists( Database_Connection::class );
			$origin = array(
				'scope'   => is_multisite() ? 'network' : 'site',
				'blog'    => (int) get_current_blog_id(),
				'network' => (int) get_current_network_id(),
				'user'    => (int) get_current_user_id(),
				'client'  => (string) OAuth_Server::authenticated_mcp_client_id(),
			);
			if ( ! $allowed() ) {
				throw new Database_Write_Failure( 'database_schema_denied' );
			}
			$input  = $this->validate( $input );
			$budget = max( ( new Transfer_Settings() )->bytes()['request'], Bounded_Payload::response_limit() );
			$memory = Operation_Content_Limits::effective_memory_bytes();
			if ( $memory > 0 ) {
				$budget = min( $budget, intdiv( $memory, 8 ) );
			}
			if ( $budget < 1 || strlen( $this->encode( $input ) ) > ( new Transfer_Settings() )->bytes()['request'] ) {
				throw new Database_Write_Failure( 'database_schema_budget' );
			}
			$journal = new Administration_Operation_Journal( 'schema', $origin, $budget );
			if ( 'recover' === $input['action'] ) {
				$record = $journal->status( $input['operation_id'] );
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				return null === $record ? array(
					'format'                               => self::FORMAT,
					'status'                               => 'not_recorded',
					'absence_is_not_reexecution_authority' => true,
				) : $record;
			}
			$db      = new Database_Connection();
			$storage = new Database_Control_Storage( $db, $budget );
			if ( 'describe' === $input['action'] ) {
				$db->begin_callback_journal();
				try {
					$result = $storage->observe_table( $input['table'] );
				} finally {
					if ( ! $db->rollback() ) {
						throw new Database_Write_Failure( 'database_write_outcome_unknown' );
					}
				}
				$result['format']              = self::FORMAT;
				$result['control_dependency']  = '' !== $storage->table_policy( $input['table'] );
				$result['rename_precondition'] = 'requires_proven_native_fence_or_exclusive_ddl';
				return $result;
			}
			$operation   = $input['operation'];
			$fingerprint = hash( 'sha256', $this->encode( $this->canonical( array( self::FORMAT, $origin, $operation ) ) ) );
			$existing    = $journal->status( $input['operation_id'], $fingerprint );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			if ( null !== $existing ) {
				return $existing;
			}
			$storage->assert_schema_target( $operation['table'] ?? '' );
			$mode = $db->run( 'SELECT @@SESSION.sql_mode AS mode, @@SESSION.character_set_client AS charset', array(), array(), 1 )['rows'][0];
			if ( ! in_array( $mode['charset'], array( 'utf8mb4', 'utf8', 'utf8mb3' ), true ) ) {
				throw new Database_Write_Failure( 'database_schema_client_charset' );
			}
			$compiler = new Database_Schema_Definition( ! in_array( 'NO_BACKSLASH_ESCAPES', explode( ',', $mode['mode'] ), true ) );
			$sql      = $compiler->compile( $operation, $db->database, $db->prefix );
			if ( strlen( $sql ) > $budget ) {
				throw new Database_Write_Failure( 'database_schema_budget' );
			}
			$rename = in_array( $operation['action'], array( 'rename_table', 'rename_column' ), true );
			if ( $rename && ! $this->rename_supported( $db, $operation['action'] ) ) {
				throw new Database_Write_Failure(
					'database_schema_exclusive_ddl_required',
					array(
						'precondition'          => 'unproven_engine_specific_rename_fence',
						'native_version'        => $db->version,
						'precondition_strength' => 'observed_not_atomic',
					)
				);
			}
			$db->begin_callback_journal();
			try {
				$before = $storage->observe_table( $operation['table'] );
				$this->precondition( $operation, $before );
				$this->dependencies( $operation, $storage );
				if ( 'rename_table' === $operation['action'] && $storage->observe_table( $operation['new_name'] )['exists'] ) {
					throw new Database_Write_Failure( 'database_schema_stale' );
				}
				if ( $before['exists'] && 'InnoDB' !== $before['engine'] ) {
					throw new Database_Write_Failure( 'database_schema_engine' );
				}
				if ( $before['exists'] && ! in_array( $operation['action'], array( 'drop_table', 'truncate_table' ), true ) ) {
					$this->inert_schema( $db, $operation['table'], $budget );
				}
			} finally {
				if ( ! $db->rollback() ) {
					throw new Database_Write_Failure( 'database_write_outcome_unknown' );
				}
			}
			$reserved = $journal->reserve(
				$input['operation_id'],
				$fingerprint,
				array(
					'action'                => $operation['action'],
					'table'                 => $operation['table'],
					'observed_before'       => $before,
					'precondition_strength' => 'observed_not_atomic',
					'effect_scope'          => 'installation_named_objects',
				)
			);
			if ( is_wp_error( $reserved ) || ! $reserved['dispatch_allowed'] ) {
				return $reserved;
			}
			$claim = $reserved['claim'];
			// All callback-capable current authorization precedes arm.
			$authorized = $allowed();
			$current    = array(
				'scope'   => is_multisite() ? 'network' : 'site',
				'blog'    => (int) get_current_blog_id(),
				'network' => (int) get_current_network_id(),
				'user'    => (int) get_current_user_id(),
				'client'  => (string) OAuth_Server::authenticated_mcp_client_id(),
			);
			if ( ! $authorized || $origin !== $current ) {
				throw new Database_Write_Failure( 'database_schema_denied' );
			}
			$db->assert_clean_session();
			// Fresh observation still cannot exclude intervening external DDL.
			$this->precondition( $operation, $storage->observe_table( $operation['table'] ) );
			$ready = $journal->arm( $claim );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			$armed     = true;
			$ack       = $rename ? $this->rename_locked( $db, $storage, $operation, $sql, $budget ) : $db->dispatch_ddl_once( $sql );
			$result    = array(
				'outcome'               => $ack['outcome'],
				'native'                => $ack,
				'precondition_strength' => 'observed_not_atomic',
			);
			$completed = $journal->complete( $claim, $result );
			if ( is_wp_error( $completed ) ) {
				return array(
					'format'              => self::FORMAT,
					'state'               => 'dispatch_armed',
					'operation_ref'       => $reserved['operation_ref'],
					'historical'          => false,
					'native'              => $ack,
					'reporting_pending'   => true,
					'reexecution_allowed' => false,
				);
			}
			$completed['format'] = self::FORMAT;
			return $completed;
		} catch ( \Throwable $error ) {
			$reason  = $error instanceof Database_Write_Failure ? $error->reason : 'database_schema_unknown';
			$details = $error instanceof Database_Write_Failure ? $error->details : array();
			if ( null !== $claim && null !== $journal && ! $armed ) {
				$journal->complete(
					$claim,
					array(
						'outcome' => 'not_dispatched',
						'reason'  => $reason,
					)
				);
			}
			if ( $armed ) {
				$details['outcome']             = null === $ack ? 'unknown' : $ack['outcome'];
				$details['native']              = $ack;
				$details['reexecution_allowed'] = false;
			}
			return new WP_Error( $reason, 'The typed schema operation could not complete. Native DDL is not a transactional rollback or atomic schema compare-and-swap; recover an armed operation instead of resending.', $details );
		}
	}


	/**
	 * These families have operation-specific native two-session evidence.
	 * An unproven engine/version remains an explicit compatibility prerequisite.
	 *
	 * @param Database_Connection $db Native server. @param string $action Rename shape. @return bool
	 */
	private function rename_supported( $db, $action ) {
		return $db->mariadb ? ( 'rename_column' === $action && 1 === preg_match( '/^11\.8\./', $db->version ) ) :
			1 === preg_match( '/^8\.4\./', $db->version );
	}

	/**
	 * The owned WRITE lock blocks external replacement/new inbound FKs while
	 * definition and complete graph checks run. No journal transaction encloses
	 * this DDL. Only this request's acknowledged SQL lock may be released.
	 *
	 * @param Database_Connection $db Native handle.
	 * @param Database_Control_Storage $storage Verified name policy.
	 * @param array $operation Typed rename.
	 * @param string $sql Fixed generated statement.
	 * @param int $budget Metadata allowance.
	 * @return array Captured schema acknowledgement, independent of lock cleanup.
	 */
	private function rename_locked( $db, $storage, $operation, $sql, $budget ) {
		$lock = $db->acquire_schema_write_lock( $operation['table'] );
		if ( 'acknowledged' !== $lock['outcome'] ) {
			return array(
				'outcome'       => 'not_dispatched',
				'lock'          => $lock,
				'errno'         => $lock['errno'],
				'sqlstate'      => $lock['sqlstate'],
				'warning_count' => 0,
				'affected'      => null,
			);
		}
		$ack = null;
		try {
			$this->precondition( $operation, $storage->observe_table( $operation['table'] ) );
			$this->inert_schema( $db, $operation['table'], $budget );
			$reference = $db->foreign_reference( $operation['table'] );
			$view      = $db->mariadb ? 'INNODB_SYS_FOREIGN' : 'INNODB_FOREIGN';
			try {
				$references = $db->run(
					'SELECT ID AS id FROM information_schema.' . $view . ' WHERE BINARY FOR_NAME=BINARY ? OR BINARY REF_NAME=BINARY ? LIMIT 1',
					array( $reference, $reference ),
					array(),
					1
				)['rows'];
			} catch ( Database_Write_Failure $error ) {
				throw new Database_Write_Failure( 'database_schema_fk_visibility', $error->details );
			}
			if ( $references ) {
				throw new Database_Write_Failure( 'database_schema_fk_dependency' );
			}
			if ( 'rename_table' === $operation['action'] ) {
				$destination = $db->run(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ? LIMIT 1',
					array( $db->database, $operation['new_name'] ),
					array(),
					1
				)['rows'];
				if ( $destination ) {
					throw new Database_Write_Failure( 'database_schema_stale' );
				}
			}
			$ack                 = $db->dispatch_ddl_once( $sql );
			$ack['rename_fence'] = 'owned_write_lock_definition_and_complete_fk_checks';
		} catch ( \Throwable $error ) {
			$details = $error instanceof Database_Write_Failure ? $error->details : array();
			$ack     = array(
				'outcome'       => 'not_dispatched',
				'reason'        => $error instanceof Database_Write_Failure ? $error->reason : 'database_schema_fence_failed',
				'errno'         => $details['errno'] ?? 0,
				'sqlstate'      => $details['sqlstate'] ?? '',
				'warning_count' => 0,
				'affected'      => null,
			);
		} finally {
			try {
				$unlock = $db->release_schema_write_lock();
				if ( 'acknowledged' !== $unlock['outcome'] && null !== $ack ) {
					$ack['session_cleanup_pending'] = true;
				}
			} catch ( \Throwable $error ) {
				if ( null !== $ack ) {
					$ack['session_cleanup_pending'] = true; }
			}
		}
		return $ack;
	}

	/** @param array $input Public input. @return array */
	private function validate( $input ) {
		$action = $input['action'] ?? null;
		$fields = array(
			'describe' => array( 'action', 'table' ),
			'execute'  => array( 'action', 'operation_id', 'operation' ),
			'recover'  => array( 'action', 'operation_id' ),
		);
		if ( ! isset( $fields[ $action ] ) || array_diff( array_keys( $input ), $fields[ $action ] ) || array_diff( $fields[ $action ], array_keys( $input ) ) ) {
			throw new Database_Write_Failure( 'database_schema_invalid' );
		}
		if ( 'describe' === $action && ( ! is_string( $input['table'] ) || ! preg_match( '/^.{1,64}$/usD', $input['table'] ) || false !== strpos( $input['table'], "\0" ) ) ) {
			throw new Database_Write_Failure( 'database_schema_invalid' );
		}
		if ( 'describe' !== $action && ( ! is_string( $input['operation_id'] ) || ! preg_match( '/^[A-Za-z0-9_.:-]{8,128}$/D', $input['operation_id'] ) ) ) {
			throw new Database_Write_Failure( 'database_schema_invalid' );
		}
		if ( 'execute' === $action ) {
			if ( $input['operation'] instanceof \stdClass && 'stdClass' === get_class( $input['operation'] ) ) {
				$input['operation'] = (array) $input['operation'];
			}
			if ( ! is_array( $input['operation'] ) ) {
				throw new Database_Write_Failure( 'database_schema_invalid' );
			}
		}
		return $input;
	}

	/** @param array $operation Typed request. @param array $observed Exact current observation. @return void */
	private function precondition( $operation, $observed ) {
		if ( 'create_table' === $operation['action'] ? $observed['exists'] : ( ! $observed['exists'] || $observed['schema_hash'] !== $operation['expected_schema_hash'] ) ) {
			throw new Database_Write_Failure( 'database_schema_stale', array( 'precondition_strength' => 'observed_not_atomic' ) );
		}
	}

	/** @param array $operation Typed operation. @param Database_Control_Storage $storage Current table policy. @return void */
	private function dependencies( $operation, $storage ) {
		$keys = 'create_table' === $operation['action'] ? ( $operation['foreign_keys'] ?? array() ) :
			( 'add_foreign_key' === $operation['action'] ? array( $operation['foreign_key'] ) : array() );
		foreach ( $keys as $key ) {
			$key = (array) $key;
			$storage->assert_schema_target( $key['reference_table'] );
		}
		if ( 'rename_table' === $operation['action'] ) {
			$storage->assert_schema_target( $operation['new_name'] );
		}
	}

	/**
	 * Rebuilding executable generated/default/check expressions is not guessed.
	 * Trigger-free and global FK metadata are not required for ordinary indexes.
	 *
	 * @param Database_Connection $db Captured handle. @param string $table Target. @param int $budget Metadata policy. @return void
	 */
	private function inert_schema( $db, $table, $budget ) {
		$columns = $db->run(
			'SELECT GENERATION_EXPRESSION AS expression, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ?',
			array( $db->database, $table ),
			array(),
			max( 1, intdiv( $budget, 128 ) )
		)['rows'];
		foreach ( $columns as $column ) {
			if ( '' !== ( $column['expression'] ?? '' ) || preg_match( '/generated|versioning/i', str_replace( 'DEFAULT_GENERATED', '', $column['extra'] ) ) ) {
				throw new Database_Write_Failure( 'database_schema_executable_expression' );
			}
		}
		if ( $db->run( "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ? AND CONSTRAINT_TYPE='CHECK' LIMIT 1", array( $db->database, $table ), array(), 1 )['rows'] ) {
			throw new Database_Write_Failure( 'database_schema_executable_expression' );
		}
	}

	/** @param mixed $value Lossless compact input. @return string */
	private function encode( $value ) {
		$json = json_encode( $this->canonical( $value ) );
		if ( ! is_string( $json ) || '' === $json ) {
			throw new Database_Write_Failure( 'database_schema_invalid' );
		}
		return $json;
	}

	/** @param mixed $value Canonical metadata. @return mixed */
	private function canonical( $value, $depth = 0 ) {
		if ( $depth > 64 ) {
			throw new Database_Write_Failure( 'database_schema_invalid' );
		}
		if ( $value instanceof \stdClass && 'stdClass' === get_class( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as &$item ) {
				$item = $this->canonical( $item, $depth + 1 );
			}
			unset( $item );
		} elseif ( is_object( $value ) || is_resource( $value ) ) {
			throw new Database_Write_Failure( 'database_schema_invalid' );
		}
		return $value;
	}
}
