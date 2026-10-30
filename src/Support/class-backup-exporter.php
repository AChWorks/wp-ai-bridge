<?php
/**
 * Resumable approved source capture with private checkpoints only.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support;

/** Does not create/update a source database journal during capture. */
final class Backup_Exporter {
	private $permissions;
	private $access;
	private $filesystem;
	private $database;
	private $policy;
	private $budget;

	public function __construct( Permissions $permissions, array $policy, $budget ) {
		$this->permissions = $permissions;
		$this->access      = new Administration_Access( $permissions );
		$this->filesystem  = new Scoped_Filesystem( $permissions );
		$this->database    = new Backup_Database( $budget );
		$this->policy      = $policy;
		$this->budget      = min( $policy['chunk'], $policy['step_bytes'] );
	}

	/** @param array $root_ids Explicit approved roots. @return array Source plan. */
	public function plan( array $root_ids ) {
		if ( ! $root_ids || array_values( array_unique( $root_ids ) ) !== $root_ids ) {
			throw new \RuntimeException( 'backup_source_roots_required' );
		}
		$config   = $this->access->configuration();
		$identity = $this->database->identity();
		if ( false !== strpos( $identity['session']['sql_mode'], 'NO_BACKSLASH_ESCAPES' ) ) {
			throw new \RuntimeException( 'backup_schema_sql_mode_unsupported' );
		}
		$identity['session']['time_zone'] = '+00:00';
		$modes                            = array_filter( explode( ',', $identity['session']['sql_mode'] ) );
		if ( ! in_array( 'NO_AUTO_VALUE_ON_ZERO', $modes, true ) ) {
			$modes[] = 'NO_AUTO_VALUE_ON_ZERO';
		}
		$identity['session']['sql_mode'] = implode( ',', $modes );
		$tables                          = $this->database->inventory( $config['backup_tables'] ?? array() );
		$schemas                         = array();
		foreach ( $tables as $table ) {
			$schema = $this->database->schema( $table );
			foreach ( $schema['blueprint']['foreign'] as $foreign ) {
				if ( ! in_array( $foreign['table'], $tables, true ) ) {
					throw new \RuntimeException( 'backup_external_dependency:' . base64_encode( $table ) );
				}
			}
			$schemas[ base64_encode( $table ) ] = $schema['hash'];
		}
		$roots      = array();
		$root_paths = array();
		foreach ( $root_ids as $id ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $id ) ) {
				throw new \RuntimeException( 'backup_source_root_invalid' );
			}
			$configured   = Backup_Operation::checked( ( new Filesystem_Roots( $this->permissions ) )->resolve( $id, 'backup_export', 'read' ) );
			$root_paths[] = $configured['path'];
			$root         = Backup_Operation::checked( $this->filesystem->stat( $id, array(), 'backup_export' ) );
			if ( 'directory' !== $root['type'] ) {
				throw new \RuntimeException( 'backup_source_root_invalid' );
			}
			$roots[] = array(
				'id'       => $id,
				'identity' => $root['identity'],
				'mode'     => $root['mode'],
			);
		}
		$this->required_roots( $root_paths );
		$plan = array(
			'format'                 => Backup_Archive::FORMAT,
			'context'                => $this->access->context(),
			'configuration_revision' => $config['revision'],
			'database'               => $identity,
			'activity_digest'        => $this->database->activity_digest(),
			'tables'                 => array_map( 'base64_encode', $tables ),
			'schemas'                => $schemas,
			'roots'                  => $roots,
		);
		$wire = json_encode( $plan );
		if ( ! is_string( $wire ) ) {
			throw new \RuntimeException( 'backup_metadata_encoding' );
		}
		// Reserve at least half of the existing private checkpoint for cursors,
		// the current schema and recovery state; this is not native schema memory.
		if ( strlen( $wire ) > intdiv( $this->policy['request'], 2 ) ) {
			throw new \RuntimeException( 'backup_plan_checkpoint_capacity' );
		}
		$plan['plan_hash'] = hash( 'sha256', $wire );
		return $plan;
	}

	public function begin( array $input ) {
		if ( true !== ( $input['quiescence_confirmed'] ?? false ) || true !== ( $input['bootstrap_writers_confirmed'] ?? false ) ) {
			throw new \RuntimeException( 'backup_quiescence_required' );
		}
		$plan = $this->plan( $input['source_roots'] ?? array() );
		if ( ! hash_equals( $plan['plan_hash'], $input['expected_plan_hash'] ?? '' ) ) {
			throw new \RuntimeException( 'backup_source_plan_stale' );
		}
		$store = $this->store();
		$lease = Backup_Operation::checked( $store->begin_administration_stream( $this->policy['file'], $this->policy ) );
		$state = array(
			'kind'        => 'export',
			'status'      => 'capturing',
			'plan'        => $plan,
			'package'     => bin2hex( random_bytes( 16 ) ),
			'phase'       => 'header',
			'mode'        => 'capture',
			'seq'         => 0,
			'offset'      => 0,
			'chain'       => Backup_Archive::initial_chain(),
			'chunk_bytes' => max( 1, $this->budget - 8192 ),
			'started_at'  => gmdate( 'c' ),
		);
		$this->rewind( $state );
		Backup_Operation::checked( $store->checkpoint( $lease['id'], $state, 0 ) );
		return $this->receipt( $lease['id'], $state );
	}

	public function step( $id ) {
		$store      = $this->store();
		$checkpoint = Backup_Operation::checked( $store->read_checkpoint( $id ) );
		$state      = $checkpoint['state'];
		if ( 'export' !== ( $state['kind'] ?? '' ) || ! $this->access->same_context( $state['plan']['context'] ) ||
			$this->access->configuration()['revision'] !== $state['plan']['configuration_revision'] ) {
			throw new \RuntimeException( 'backup_context_changed' );
		}
		if ( 'sealed' === $state['status'] ) {
			return $this->receipt( $id, $state );
		}
		if ( ! in_array( $state['status'], array( 'capturing', 'verifying_source' ), true ) ) {
			throw new \RuntimeException( 'backup_capture_restart_required' );
		}
		$observed_stream = Backup_Operation::checked( $store->status( $id ) );
		if ( 'open' === $observed_stream['state'] && $observed_stream['received'] > $state['offset'] &&
			'verify' === $state['mode'] && 'root' === $state['phase'] &&
			count( $state['plan']['roots'] ) === $state['root_index'] ) {
			// A footer was appended but no seal/cursor acknowledgement survived.
			// Failure diagnostics may have changed source options meanwhile.
			$state['phase'] = 'table';
			$this->rewind( $state );
			$checkpoint = Backup_Operation::checked( $store->checkpoint( $id, $state, $checkpoint['revision'] ) );
		}
		$deadline = microtime( true ) + $this->policy['step_seconds'];
		$used     = 0;
		do {
			$record = $this->database->with_session(
				$state['plan']['database']['session'],
				function () use ( &$state ) {
					return $this->next( $state );
				}
			);
			if ( null !== $record ) {
				if ( 'verify' === $state['mode'] && 'footer' !== $record['record']['type'] ) {
					$record = null;
				}
			}
			if ( null !== $record ) {
				$record['record']['seq'] = $state['seq'];
				$frame                   = Backup_Archive::encode( $record['record'], $record['payload'], $this->budget );
				$already_sealed          = false;
				if ( 'footer' === $record['record']['type'] ) {
					$status = Backup_Operation::checked( $store->status( $id ) );
					if ( 'verified' === $status['state'] ) {
						$expected_chain = Backup_Archive::fold( $state['chain'], $frame );
						if ( $status['total'] !== $state['offset'] + strlen( $frame ) || ! hash_equals( $expected_chain, $status['sha256'] ) ) {
							throw new \RuntimeException( 'backup_archive_changed' );
						}
						$matched = Backup_Operation::checked(
							$store->with_administration_source(
								$id,
								static function ( $source ) use ( $state, $frame ) {
									return 0 === fseek( $source['stream'], $state['offset'] ) && fread( $source['stream'], strlen( $frame ) ) === $frame;
								}
							)
						);
						if ( true !== $matched ) {
							throw new \RuntimeException( 'backup_archive_changed' );
						}
						$already_sealed = true;
					}
				}
				if ( ! $already_sealed ) {
					Backup_Operation::checked( $store->append_verified_frame( $id, $state['offset'], $frame, hash( 'sha256', $frame ) ) );
				}
				$state['offset'] += strlen( $frame );
				$state['chain']   = Backup_Archive::fold( $state['chain'], $frame );
				++$state['seq'];
				$used += strlen( $frame );
				if ( 'footer' === $record['record']['type'] ) {
					Backup_Operation::checked( $store->seal_administration_stream( $id, $state['offset'], $state['chain'] ) );
					$state['status'] = 'sealed';
					$state['phase']  = 'done';
				}
			}
			$checkpoint = Backup_Operation::checked( $store->checkpoint( $id, $state, $checkpoint['revision'] ) );
		} while ( 'sealed' !== $state['status'] && $used < $this->policy['step_bytes'] && microtime( true ) < $deadline );
		return $this->receipt( $id, $state );
	}

	/** Produce at most one bounded record; cursor-only progression returns null. */
	private function next( array &$state ) {
		$phase = $state['phase'];
		if ( 'header' === $phase ) {
			$state['phase'] = 'table';
			return $this->record(
				'header',
				array(),
				json_encode(
					array(
						'format'                 => Backup_Archive::FORMAT,
						'package'                => $state['package'],
						'plan'                   => $state['plan'],
						'chunk_bytes'            => $state['chunk_bytes'],
						'consistency'            => 'declared_quiescent_verified_capture',
						'quiescence_assumptions' => array( 'all_database_writers_and_ddl_stopped', 'all_file_writers_stopped', 'bootstrap_and_auth_provider_writers_stopped' ),
						'started_at'             => $state['started_at'],
						'activation_pending'     => true,
					)
				)
			);
		}
		if ( 'table' === $phase ) {
			if ( $state['table_index'] >= count( $state['plan']['tables'] ) ) {
				$state['phase'] = 'root';
				return null;
			}
			$table64 = $state['plan']['tables'][ $state['table_index'] ];
			$table   = Backup_Archive::decode_name( $table64 );
			$schema  = $this->database->schema( $table );
			if ( ! hash_equals( $state['plan']['schemas'][ $table64 ], $schema['hash'] ) ) {
				throw new \RuntimeException( 'backup_source_stale' );
			}
			$state['schema']    = $this->pack_schema( $schema );
			$state['db_digest'] = hash( 'sha256', $state['db_digest'] . ':table:' . $table64 . ':' . $schema['hash'] );
			$state['after']     = '';
			$state['phase']     = 'group';
			return $this->record(
				'table',
				array(
					'table'       => $table64,
					'schema_hash' => $schema['hash'],
				),
				json_encode( $state['schema'] )
			);
		}
		if ( 'group' === $phase ) {
			$schema = self::unpack_schema( $state['schema'] );
			$group  = $this->database->next_group( $schema, $state['after'] );
			if ( null === $group ) {
				++$state['table_index'];
				$state['phase'] = 'table';
				return $this->record( 'table_end', array( 'table' => base64_encode( $schema['table'] ) ) );
			}
			$state['group']       = $group;
			$state['db_digest']   = hash( 'sha256', $state['db_digest'] . ':row:' . $group['digest'] . ':' . $group['multiplicity'] );
			$state['column']      = 0;
			$state['cell_offset'] = 0;
			$state['phase']       = 'cell';
			return $this->record(
				'row',
				array(
					'digest'       => $group['digest'],
					'multiplicity' => $group['multiplicity'],
				)
			);
		}
		if ( 'cell' === $phase ) {
			$schema = self::unpack_schema( $state['schema'] );
			if ( $state['column'] >= count( $schema['columns'] ) ) {
				$state['after'] = $state['group']['digest'];
				$state['phase'] = 'group';
				return $this->record( 'row_end', array( 'digest' => $state['group']['digest'] ) );
			}
			$value                 = $this->database->cell( $schema, $state['group']['digest'], $state['column'], $state['cell_offset'], $state['chunk_bytes'] );
			$null                  = null === $value['bytes'];
			$total                 = $null ? 0 : Backup_Operation::native_int( $value['bytes'] );
			$offset                = $state['cell_offset'];
			$payload               = $null ? '' : $value['value'];
			$record                = $this->record(
				'cell',
				array(
					'column' => $state['column'],
					'offset' => $offset,
					'total'  => $total,
					'null'   => $null,
					'sha256' => $value['digest'],
				),
				$payload
			);
			$state['cell_offset'] += strlen( $payload );
			if ( $null || $state['cell_offset'] === $total ) {
				++$state['column'];
				$state['cell_offset'] = 0;
			} elseif ( '' === $payload || $state['cell_offset'] > $total ) {
				throw new \RuntimeException( 'backup_source_stale' );
			}
			return $record;
		}
		if ( 'root' === $phase ) {
			if ( $state['root_index'] >= count( $state['plan']['roots'] ) ) {
				if ( 'capture' === $state['mode'] ) {
					$state['expected_db']    = $state['db_digest'];
					$state['expected_files'] = $state['files_digest'];
					$state['mode']           = 'verify';
					$state['phase']          = 'table';
					$state['status']         = 'verifying_source';
					$this->rewind( $state );
					return null;
				}
				$current = $this->plan( array_column( $state['plan']['roots'], 'id' ) );
				if ( ! hash_equals( $current['plan_hash'], $state['plan']['plan_hash'] ) ||
					! hash_equals( $state['expected_db'], $state['db_digest'] ) ||
					! hash_equals( $state['expected_files'], $state['files_digest'] ) ) {
					throw new \RuntimeException( 'backup_source_stale' );
				}
				return $this->record(
					'footer',
					array(
						'previous_chain'     => $state['chain'],
						'database_digest'    => $state['db_digest'],
						'files_digest'       => $state['files_digest'],
						'source_verified'    => true,
						'activation_pending' => true,
					)
				);
			}
			$root       = $state['plan']['roots'][ $state['root_index'] ];
			$descriptor = Backup_Operation::checked( $this->filesystem->stat( $root['id'], array(), 'backup_export' ) );
			if ( $descriptor['identity'] !== $root['identity'] ) {
				throw new \RuntimeException( 'backup_source_stale' );
			}
			$state['stack']        = array( $this->directory_cursor( $descriptor ) );
			$state['phase']        = 'entries';
			$state['files_digest'] = hash( 'sha256', $state['files_digest'] . ':root:' . $root['id'] . ':' . $root['mode'] );
			return $this->record(
				'root',
				array(
					'root' => $root['id'],
					'mode' => $root['mode'],
				)
			);
		}
		if ( 'entries' === $phase ) {
			if ( ! $state['stack'] ) {
				++$state['root_index'];
				$state['phase'] = 'root';
				return $this->record( 'root_end' );
			}
			$index                 = count( $state['stack'] ) - 1;
			$cursor                = &$state['stack'][ $index ];
			$root                  = $state['plan']['roots'][ $state['root_index'] ]['id'];
			$candidate             = $cursor['candidate'];
			$result                = Backup_Operation::checked(
				$this->filesystem->iterate_entries(
					$root,
					$cursor['components'],
					'backup_export',
					$cursor['identity'],
					function ( $entry ) use ( &$candidate, $cursor ) {
						$name  = base64_decode( end( $entry['components'] ), true );
						$after = null === $cursor['after'] ? null : base64_decode( $cursor['after'], true );
						if ( ( null === $after || strcmp( $name, $after ) > 0 ) &&
							( null === $candidate || strcmp( $name, base64_decode( end( $candidate['components'] ), true ) ) < 0 ) ) {
							$candidate = $this->portable_descriptor( $entry );
						}
					},
					array(
						'bytes'   => $this->policy['step_bytes'],
						'seconds' => $this->policy['step_seconds'],
						'cursor'  => $cursor['scan_cursor'],
						'entries' => max( 1, intdiv( $this->policy['step_bytes'], 512 ) ),
					)
				)
			);
			$cursor['candidate']   = $candidate;
			$cursor['scan_cursor'] = $result['cursor'];
			if ( ! $result['complete'] ) {
				return null;
			}
			if ( null === $candidate ) {
				$components = $cursor['components'];
				array_pop( $state['stack'] );
				$state['files_digest'] = hash( 'sha256', $state['files_digest'] . ':directory_end:' . json_encode( $components ) );
				return $this->record( 'directory_end', array( 'components' => $components ) );
			}
			$cursor['after']       = end( $candidate['components'] );
			$cursor['candidate']   = null;
			$cursor['scan_cursor'] = 0;
			unset( $cursor );
			if ( ! in_array( $candidate['type'], array( 'file', 'directory' ), true ) ) {
				throw new \RuntimeException( 'backup_file_type_unsupported' );
			}
			$state['files_digest'] = hash( 'sha256', $state['files_digest'] . ':entry:' . json_encode( array( $candidate['components'], $candidate['type'], $candidate['mode'], 'file' === $candidate['type'] ? $candidate['bytes'] : 0, $candidate['identity'] ) ) );
			if ( 'directory' === $candidate['type'] ) {
				$state['stack'][] = $this->directory_cursor( $candidate );
				return $this->record(
					'directory',
					array(
						'components'      => $candidate['components'],
						'mode'            => $candidate['mode'],
						'source_identity' => $candidate['identity'],
					)
				);
			}
			$state['file']        = $candidate;
			$state['file_offset'] = 0;
			$state['file_chain']  = Backup_Archive::initial_chain();
			$state['phase']       = 'file';
			return $this->record(
				'file',
				array(
					'components'      => $candidate['components'],
					'mode'            => $candidate['mode'],
					'source_identity' => $candidate['identity'],
					'bytes'           => $candidate['bytes'],
					'chunk_bytes'     => $state['chunk_bytes'],
				)
			);
		}
		if ( 'file' === $phase ) {
			$file = $state['file'];
			if ( $state['file_offset'] === $file['bytes'] ) {
				$state['phase']        = 'entries';
				$state['files_digest'] = hash( 'sha256', $state['files_digest'] . ':file_end:' . $state['file_chain'] );
				return $this->record(
					'file_end',
					array(
						'chain' => $state['file_chain'],
						'bytes' => $state['file_offset'],
					)
				);
			}
			$offset                = $state['file_offset'];
			$length                = min( $state['chunk_bytes'], $file['bytes'] - $offset );
			$root                  = $state['plan']['roots'][ $state['root_index'] ]['id'];
			$payload               = Backup_Operation::checked(
				$this->filesystem->with_read_stream(
					$root,
					$file['components'],
					'backup_export',
					$file['identity'],
					static function ( $stream ) use ( $offset, $length ) {
						if ( 0 !== fseek( $stream, $offset ) ) {
							throw new \RuntimeException( 'backup_file_stale' );
						}
						$value = fread( $stream, $length );
						if ( ! is_string( $value ) || strlen( $value ) !== $length ) {
							throw new \RuntimeException( 'backup_file_stale' );
						}
						return $value;
					}
				)
			);
			$state['file_offset'] += strlen( $payload );
			$state['file_chain']   = Backup_Archive::fold( $state['file_chain'], $payload );
			return $this->record( 'file_chunk', array( 'offset' => $offset ), $payload );
		}
		throw new \RuntimeException( 'backup_checkpoint_invalid' );
	}

	private function rewind( array &$state ) {
		$state['table_index']  = 0;
		$state['root_index']   = 0;
		$state['db_digest']    = hash( 'sha256', 'wpai-backup-database-v1' );
		$state['files_digest'] = hash( 'sha256', 'wpai-backup-files-v1' );
		unset( $state['schema'], $state['group'], $state['stack'], $state['file'] );
	}

	/** Required native installation locations cannot be silently left outside scope. */
	private function required_roots( array $roots ) {
		global $wp_theme_directories;
		$required = array( ABSPATH );
		foreach ( array( 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ) as $constant ) {
			if ( defined( $constant ) && is_dir( constant( $constant ) ) ) {
				$required[] = constant( $constant );
			}
		}
		foreach ( is_array( $wp_theme_directories ) ? $wp_theme_directories : array() as $directory ) {
			if ( is_dir( $directory ) ) {
				$required[] = $directory;
			}
		}
		if ( is_file( ABSPATH . 'wp-config.php' ) ) {
			$required[] = ABSPATH . 'wp-config.php';
		} elseif ( is_file( dirname( rtrim( ABSPATH, '/\\\\' ) ) . '/wp-config.php' ) ) {
			$required[] = dirname( rtrim( ABSPATH, '/\\\\' ) ) . '/wp-config.php';
		}
		$uploads = wp_upload_dir( null, false, true );
		if ( is_array( $uploads ) && is_string( $uploads['basedir'] ?? null ) && is_dir( $uploads['basedir'] ) ) {
			$required[] = $uploads['basedir'];
		}
		foreach ( $required as $path ) {
			$canonical = realpath( $path );
			$covered   = false;
			foreach ( $roots as $root ) {
				if ( is_string( $canonical ) && Filesystem_Roots::within( $canonical, $root ) ) {
					$covered = true;
					break;
				}
			}
			if ( ! $covered ) {
				throw new \RuntimeException( 'backup_required_installation_root_gap' );
			}
		}
	}

	private function directory_cursor( array $descriptor ) {
		return array(
			'components'  => $descriptor['components'],
			'identity'    => $descriptor['identity'],
			'after'       => null,
			'scan_cursor' => 0,
			'candidate'   => null,
		);
	}

	private function portable_descriptor( array $descriptor ) {
		return array_intersect_key( $descriptor, array_fill_keys( array( 'components', 'identity', 'type', 'mode', 'bytes' ), true ) );
	}

	private function record( $type, array $fields = array(), $payload = '' ) {
		if ( ! is_string( $payload ) ) {
			throw new \RuntimeException( 'backup_metadata_encoding' );
		}
		return array(
			'record'  => array_merge( array( 'type' => $type ), $fields ),
			'payload' => $payload,
		);
	}

	private function pack_schema( array $schema ) {
		$columns = $schema['columns'];
		foreach ( $columns as &$column ) {
			$column['name'] = base64_encode( $column['name'] );
		}
		unset( $column );
		return array(
			'table'   => base64_encode( $schema['table'] ),
			'ddl'     => base64_encode( $schema['ddl'] ),
			'columns' => $columns,
			'hash'    => $schema['hash'],
		);
	}

	public static function unpack_schema( array $schema ) {
		if ( array_diff( array_keys( $schema ), array( 'table', 'ddl', 'columns', 'hash' ) ) ||
			! is_array( $schema['columns'] ?? null ) || ! Backup_Archive::digest( $schema['hash'] ?? null ) ) {
			throw new \RuntimeException( 'backup_schema_integrity' );
		}
		$schema['table'] = Backup_Archive::decode_name( $schema['table'] ?? null );
		$schema['ddl']   = Backup_Archive::decode_name( $schema['ddl'] ?? null );
		foreach ( $schema['columns'] as &$column ) {
			if ( ! is_array( $column ) ) {
				throw new \RuntimeException( 'backup_schema_integrity' );
			}
			$column['name'] = Backup_Archive::decode_name( $column['name'] ?? null );
		}
		unset( $column );
		$schema['blueprint'] = Backup_Schema::blueprint( $schema['ddl'], $schema['table'] );
		if ( ! hash_equals( hash( 'sha256', $schema['ddl'] ), $schema['hash'] ) ||
			array_column( $schema['columns'], 'name' ) !== $schema['blueprint']['columns'] ) {
			throw new \RuntimeException( 'backup_schema_integrity' );
		}
		$schema['columns'] = Backup_Schema::reconcile_columns( $schema['blueprint'], $schema['columns'] );
		return $schema;
	}

	private function store() {
		return Private_Transfer_Session::for_administration( $this->permissions, 'backup_archive', $this->access->context() );
	}

	private function receipt( $id, array $state ) {
		return array(
			'format'                => Backup_Archive::FORMAT,
			'session_id'            => $id,
			'archive_id'            => $id,
			'state'                 => $state['status'],
			'complete'              => 'sealed' === $state['status'],
			'bytes'                 => $state['offset'],
			'sha256'                => $state['chain'],
			'integrity'             => Backup_Archive::CHAIN_FORMAT,
			'plan_hash'             => $state['plan']['plan_hash'],
			'source_verified'       => 'sealed' === $state['status'],
			'consistency'           => 'declared_quiescent_verified_capture',
			'quiescence_assumption' => true,
			'activation_pending'    => true,
			'phase'                 => $state['phase'],
			'max_frame_bytes'       => $this->budget,
		);
	}
}
