<?php
/**
 * Isolated native restoration with complete package preflight and no activation.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support;

/** The active source authentication and journals are never restoration targets. */
final class Backup_Restore {
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

	public function prepare( array $input ) {
		$config = $this->access->configuration();
		if ( ! in_array( $input['target_schema'] ?? '', $config['restore_schemas'], true ) ) {
			throw new \RuntimeException( 'backup_restore_target_denied' );
		}
		$root = Backup_Operation::checked( ( new Filesystem_Roots( $this->permissions ) )->resolve( $input['target_root'] ?? '', 'backup_restore', 'write' ) );
		$this->database->empty_target( $input['target_schema'] );
		$this->empty_directory( $input['target_root'], array() );
		$purpose = $input['archive_purpose'] ?? 'backup_archive';
		if ( ! in_array( $purpose, array( 'backup_archive', 'backup_input' ), true ) ) {
			throw new \RuntimeException( 'backup_archive_purpose_invalid' );
		}
		$archive = $this->store( $purpose );
		if ( 'backup_archive' === $purpose ) {
			$export = Backup_Operation::checked( $archive->read_checkpoint( $input['archive_id'] ) );
			if ( 'sealed' !== ( $export['state']['status'] ?? '' ) ) {
				throw new \RuntimeException( 'backup_archive_incomplete' );
			}
		}
		$source = Backup_Operation::checked(
			$archive->with_administration_source(
				$input['archive_id'],
				static function ( $source ) {
					return array(
						'bytes'     => $source['bytes'],
						'sha256'    => $source['sha256'],
						'integrity' => $source['integrity'],
					);
				}
			)
		);
		if ( Backup_Archive::CHAIN_FORMAT !== $source['integrity'] || ! hash_equals( $input['archive_sha256'] ?? '', $source['sha256'] ) ) {
			throw new \RuntimeException( 'backup_archive_integrity' );
		}
		$state = array(
			'kind'                   => 'restore',
			'status'                 => 'preflighting',
			'phase'                  => 'preflight',
			'archive_id'             => $input['archive_id'],
			'archive_purpose'        => $purpose,
			'archive'                => $source,
			'target_schema'          => $input['target_schema'],
			'target_root'            => $input['target_root'],
			'target_root_identity'   => Backup_Operation::checked( $this->filesystem->stat( $input['target_root'], array(), 'backup_restore' ) )['identity'],
			'origin'                 => $this->access->context(),
			'configuration_revision' => $config['revision'],
			'schemas'                => array(),
			'offset'                 => 0,
			'seq'                    => 0,
			'chain'                  => Backup_Archive::initial_chain(),
			'grammar'                => 'header',
			'table_index'            => 0,
			'root_index'             => 0,
			'stack'                  => array(),
			'db_digest'              => hash( 'sha256', 'wpai-backup-database-v1' ),
			'files_digest'           => hash( 'sha256', 'wpai-backup-files-v1' ),
			'activation_pending'     => true,
		);
		$store = $this->store( 'backup_state' );
		$lease = Backup_Operation::checked( $store->begin_administration_stream( 1, $this->policy ) );
		Backup_Operation::checked( $store->seal_administration_stream( $lease['id'], 0, Backup_Archive::initial_chain() ) );
		Backup_Operation::checked( $store->checkpoint( $lease['id'], $state, 0 ) );
		return $this->receipt( $lease['id'], $state );
	}

	public function begin( array $input ) {
		$store      = $this->store( 'backup_state' );
		$checkpoint = Backup_Operation::checked( $store->read_checkpoint( $input['session_id'] ) );
		$state      = $checkpoint['state'];
		$this->context( $state );
		if ( 'ready' !== $state['status'] || true !== ( $input['quiescence_confirmed'] ?? false ) ||
			! hash_equals( $state['plan_hash'], $input['expected_plan_hash'] ?? '' ) ) {
			throw new \RuntimeException( 'backup_restore_plan_not_approved' );
		}
		$this->database->empty_target( $state['target_schema'] );
		$this->empty_directory( $state['target_root'], array() );
		$root = Backup_Operation::checked( $this->filesystem->stat( $state['target_root'], array(), 'backup_restore' ) );
		if ( $root['identity'] !== $state['target_root_identity'] ) {
			throw new \RuntimeException( 'backup_restore_target_stale' );
		}
		$state['status']  = 'restoring';
		$state['phase']   = 'wrapper';
		$state['offset']  = 0;
		$state['seq']     = 0;
		$state['chain']   = Backup_Archive::initial_chain();
		$state['pending'] = array(
			'parent'     => $root['identity'],
			'components' => array( base64_encode( 'roots' ) ),
		);
		Backup_Operation::checked( $store->checkpoint( $input['session_id'], $state, $checkpoint['revision'] ) );
		return $this->receipt( $input['session_id'], $state );
	}

	public function step( $id ) {
		$store      = $this->store( 'backup_state' );
		$checkpoint = Backup_Operation::checked( $store->read_checkpoint( $id ) );
		$state      = $checkpoint['state'];
		$this->context( $state );
		if ( in_array( $state['status'], array( 'ready', 'verified' ), true ) ) {
			return $this->receipt( $id, $state );
		}
		$preflight = 'preflighting' === $state['status'] && 'preflight' === $state['phase'];
		if ( ! $preflight && ( ! in_array( $state['status'], array( 'restoring', 'verifying_restore' ), true ) ||
			! in_array( $state['phase'], array( 'wrapper', 'apply', 'constraints', 'verify_database', 'verify_files' ), true ) ||
			! isset( $state['header']['plan']['database']['session'] ) ) ) {
			throw new \RuntimeException( 'backup_restore_checkpoint_invalid' );
		}
		$deadline = microtime( true ) + $this->policy['step_seconds'];
		$advance  = function () use ( $id, $store, &$state, &$checkpoint, $deadline ) {
			$used = 0;
			do {
				if ( 'wrapper' === $state['phase'] ) {
					$this->committed(
						$this->filesystem->mkdir(
							$state['target_root'],
							$state['pending']['components'],
							'backup_restore',
							$state['pending']['parent'],
							$this->operation( $state, 'wrapper' ),
							0700
						)
					);
					unset( $state['pending'] );
					$state['phase'] = 'apply';
				} elseif ( 'constraints' === $state['phase'] ) {
					$this->constraints( $state );
				} elseif ( 'verify_database' === $state['phase'] ) {
					$this->verify_database( $state );
				} else {
					$archive = $this->store( $state['archive_purpose'] );
					$work    = Backup_Operation::checked(
						$archive->with_administration_source(
							$state['archive_id'],
							function ( $source ) use ( &$state, $id ) {
								if ( $source['bytes'] !== $state['archive']['bytes'] || $source['sha256'] !== $state['archive']['sha256'] ||
								0 !== fseek( $source['stream'], $state['offset'] ) ) {
									throw new \RuntimeException( 'backup_archive_changed' );
								}
								$frame = Backup_Archive::read( $source['stream'], $this->budget );
								if ( null === $frame || $frame['record']['seq'] !== $state['seq'] ) {
									throw new \RuntimeException( 'backup_archive_sequence' );
								}
								$next = true;
								if ( 'preflight' === $state['phase'] ) {
									$this->validate( $state, $frame['record'], $frame['payload'], $source['stream'] );
								} elseif ( 'apply' === $state['phase'] ) {
									$next = $this->apply( $state, $frame['record'], $frame['payload'], $id );
								} elseif ( 'verify_files' === $state['phase'] ) {
									$next = $this->verify_file_record( $state, $frame['record'], $frame['payload'] );
								} else {
									throw new \RuntimeException( 'backup_restore_checkpoint_invalid' );
								}
								if ( $next ) {
									$state['offset'] += strlen( $frame['frame'] );
									$state['chain']   = Backup_Archive::fold( $state['chain'], $frame['frame'] );
									++$state['seq'];
									if ( 'footer' === $frame['record']['type'] ) {
										if ( $state['offset'] !== $source['bytes'] || ! hash_equals( $state['chain'], $source['sha256'] ) ) {
											throw new \RuntimeException( 'backup_archive_integrity' );
										}
										if ( 'preflight' === $state['phase'] ) {
											$state['status']    = 'ready';
											$state['plan_hash'] = hash(
												'sha256',
												json_encode(
													array(
														$state['archive'],
														$state['target_schema'],
														$state['target_root'],
														$state['target_root_identity'],
														$state['origin'],
														$state['configuration_revision'],
														$state['header']['plan']['tables'],
														$state['header']['plan']['roots'],
													)
												)
											);
										} elseif ( 'apply' === $state['phase'] ) {
											$state['phase']            = 'constraints';
											$state['constraint_table'] = 0;
											$state['constraint_index'] = 0;
										} else {
											$this->verify_wrapper( $state );
											$state['status'] = 'verified';
											$state['phase']  = 'done';
										}
									}
								}
								return strlen( $frame['frame'] );
							}
						)
					);
					$used   += $work;
				}
				if ( 'verified' === $state['status'] ) {
					// Native cleanup must succeed before a complete receipt is durable.
					break;
				}
				$checkpoint = Backup_Operation::checked( $store->checkpoint( $id, $state, $checkpoint['revision'] ) );
				// Release staged bytes only after the cursor which no longer needs
				// them is durable. A crash can repeat disposable cleanup, not DML.
				if ( isset( $state['release_stage'] ) ) {
					$released = $this->store( 'backup_input' )->cancel( $state['release_stage'] );
					if ( ! is_wp_error( $released ) || in_array( $released->get_error_code(), array( 'transfer_not_found', 'transfer_unavailable' ), true ) ) {
						unset( $state['release_stage'] );
						$checkpoint = Backup_Operation::checked( $store->checkpoint( $id, $state, $checkpoint['revision'] ) );
					} else {
						throw new \RuntimeException( 'backup_stage_cleanup_pending' );
					}
				}
			} while ( ! in_array( $state['status'], array( 'ready', 'verified' ), true ) && $used < $this->policy['step_bytes'] && microtime( true ) < $deadline );
		};
		if ( $preflight ) {
			// Imported settings have no native authority before complete preflight
			// and the separate ready-plan approval in begin().
			$advance();
		} else {
			$this->database->with_session( $state['header']['plan']['database']['session'], $advance );
		}
		if ( 'verified' === $state['status'] ) {
			Backup_Operation::checked( $store->checkpoint( $id, $state, $checkpoint['revision'] ) );
		}
		return $this->receipt( $id, $state );
	}

	/** Complete structural/chain/schema preflight, with O(depth + schema) metadata. */
	private function validate( array &$s, array $r, $payload, $archive_stream = null ) {
		$type = $r['type'];
		if ( 'header' === $type ) {
			if ( 'header' !== $s['grammar'] || 0 !== $s['seq'] ) {
				throw new \RuntimeException( 'backup_archive_grammar' );
			}
			$header = json_decode( $payload, true, 32 );
			if ( ! is_array( $header ) || Backup_Archive::FORMAT !== ( $header['format'] ?? '' ) ||
				'declared_quiescent_verified_capture' !== ( $header['consistency'] ?? '' ) ||
				! is_int( $header['chunk_bytes'] ?? null ) || $header['chunk_bytes'] < 1 || $header['chunk_bytes'] > $this->budget ||
				! is_array( $header['plan']['tables'] ?? null ) || ! $header['plan']['tables'] ||
				count( array_unique( $header['plan']['tables'] ) ) !== count( $header['plan']['tables'] ) ||
				! is_array( $header['plan']['roots'] ?? null ) || ! $header['plan']['roots'] ||
				! is_string( $header['plan']['database']['session']['sql_mode'] ?? null ) ||
				'+00:00' !== ( $header['plan']['database']['session']['time_zone'] ?? null ) ||
				1 !== preg_match( '/^[A-Z0-9_,]*$/D', $header['plan']['database']['session']['sql_mode'] ) ||
				false !== strpos( $header['plan']['database']['session']['sql_mode'], 'NO_BACKSLASH_ESCAPES' ) ) {
				throw new \RuntimeException( 'backup_archive_header' );
			}
			foreach ( $header['plan']['tables'] as $name ) {
				$raw = Backup_Archive::decode_name( $name );
				if ( ! Backup_Schema::valid_identifier( $raw ) ) {
					throw new \RuntimeException( 'backup_archive_identifier' );
				}
			}
			$ids = array();
			foreach ( $header['plan']['roots'] as $root ) {
				if ( ! is_int( $root['mode'] ?? null ) || $root['mode'] < 0 || $root['mode'] > 0777 || ! is_string( $root['id'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $root['id'] ) || in_array( $root['id'], $ids, true ) ) {
					throw new \RuntimeException( 'backup_archive_root' );
				}
				$ids[] = $root['id'];
			}
			$s['header']  = $header;
			$s['grammar'] = 'table';
			return;
		}
		if ( 'table' === $type ) {
			if ( 'table' !== $s['grammar'] || ( $s['header']['plan']['tables'][ $s['table_index'] ] ?? null ) !== ( $r['table'] ?? null ) ) {
				throw new \RuntimeException( 'backup_archive_grammar' );
			}
			$packed = json_decode( $payload, true, 32 );
			if ( ! is_array( $packed ) ) {
				throw new \RuntimeException( 'backup_archive_schema' );
			}
			$schema = Backup_Exporter::unpack_schema( $packed );
			if ( base64_encode( $schema['table'] ) !== $r['table'] || $schema['hash'] !== $r['schema_hash'] ||
				( $s['header']['plan']['schemas'][ $r['table'] ] ?? null ) !== $schema['hash'] ) {
				throw new \RuntimeException( 'backup_archive_schema' );
			}
			foreach ( $schema['blueprint']['foreign'] as $foreign ) {
				if ( ! in_array( base64_encode( $foreign['table'] ), $s['header']['plan']['tables'], true ) ) {
					throw new \RuntimeException( 'backup_external_dependency' );
				}
			}
			$s['schemas'][ $r['table'] ] = $packed;
			$s['current_table']          = $r['table'];
			$s['previous_group']         = '';
			$s['db_digest']              = hash( 'sha256', $s['db_digest'] . ':table:' . $r['table'] . ':' . $schema['hash'] );
			$s['grammar']                = 'row';
			return;
		}
		if ( 'row' === $type ) {
			if ( 'row' !== $s['grammar'] || ! Backup_Archive::digest( $r['digest'] ?? null ) || strcmp( $r['digest'], $s['previous_group'] ) <= 0 ) {
				throw new \RuntimeException( 'backup_archive_row' );
			}
			$count = Backup_Operation::native_int( $r['multiplicity'] ?? null );
			if ( $count < 1 ) {
				throw new \RuntimeException( 'backup_archive_row' );
			}
			$s['row']       = array(
				'digest'       => $r['digest'],
				'multiplicity' => $count,
				'column'       => 0,
				'offset'       => 0,
				'identity'     => 'wpai-row-v1;',
				'cell'         => null,
			);
			$s['db_digest'] = hash( 'sha256', $s['db_digest'] . ':row:' . $r['digest'] . ':' . $r['multiplicity'] );
			$s['grammar']   = 'cell';
			return;
		}
		if ( 'cell' === $type ) {
			if ( 'cell' !== $s['grammar'] || ( $r['column'] ?? null ) !== $s['row']['column'] || ( $r['offset'] ?? null ) !== $s['row']['offset'] ||
				! is_int( $r['total'] ?? null ) || $r['total'] < 0 || ! is_bool( $r['null'] ?? null ) ||
				( $r['null'] ? null !== ( $r['sha256'] ?? null ) || 0 !== $r['total'] || '' !== $payload : ! Backup_Archive::digest( $r['sha256'] ?? null ) ) ) {
				throw new \RuntimeException( 'backup_archive_cell' );
			}
			$identity = array( $r['total'], $r['null'], $r['sha256'] );
			if ( null !== $s['row']['cell'] && $identity !== $s['row']['cell'] || strlen( $payload ) > $r['total'] - $r['offset'] ) {
				throw new \RuntimeException( 'backup_archive_cell' );
			}
			if ( 0 === $r['offset'] ) {
				// Only scalar replay coordinates survive HTTP checkpoints.
				$s['row']['cell_start'] = $s['offset'] ?? null;
				$s['row']['cell_seq']   = $s['seq'];
			}
			$s['row']['cell']    = $identity;
			$s['row']['offset'] += strlen( $payload );
			if ( $s['row']['offset'] === $r['total'] ) {
				if ( ! $r['null'] ) {
					if ( 0 === $r['offset'] ) {
						if ( ! hash_equals( $r['sha256'], hash( 'sha256', $payload ) ) ) {
							throw new \RuntimeException( 'backup_archive_cell_integrity' );
						}
					} else {
						$this->verify_cell_replay( $archive_stream, $s, $r );
					}
				}
				unset( $s['row']['cell_start'], $s['row']['cell_seq'] );
				$s['row']['identity'] .= $r['null'] ? 'N;' : 'V' . $r['total'] . ':' . $r['sha256'] . ';';
				++$s['row']['column'];
				$s['row']['offset'] = 0;
				$s['row']['cell']   = null;
			} elseif ( '' === $payload ) {
				throw new \RuntimeException( 'backup_archive_cell' );
			}
			return;
		}
		if ( 'row_end' === $type ) {
			if ( 'cell' !== $s['grammar'] || count( $s['schemas'][ $s['current_table'] ]['columns'] ) !== $s['row']['column'] ||
				0 !== $s['row']['offset'] || ( $r['digest'] ?? '' ) !== $s['row']['digest'] ||
				! hash_equals( $s['row']['digest'], hash( 'sha256', $s['row']['identity'] ) ) ) {
				throw new \RuntimeException( 'backup_archive_row' );
			}
			$s['previous_group'] = $s['row']['digest'];
			unset( $s['row'] );
			$s['grammar'] = 'row';
			return;
		}
		if ( 'table_end' === $type ) {
			if ( 'row' !== $s['grammar'] || ( $r['table'] ?? '' ) !== $s['current_table'] ) {
				throw new \RuntimeException( 'backup_archive_grammar' );
			}
			++$s['table_index'];
			$s['grammar'] = count( $s['header']['plan']['tables'] ) === $s['table_index'] ? 'root' : 'table';
			return;
		}
		if ( 'root' === $type ) {
			$root = $s['header']['plan']['roots'][ $s['root_index'] ] ?? null;
			if ( 'root' !== $s['grammar'] || ! is_array( $root ) || ( $r['root'] ?? '' ) !== $root['id'] || ( $r['mode'] ?? null ) !== $root['mode'] ) {
				throw new \RuntimeException( 'backup_archive_root' );
			}
			$s['stack']        = array(
				array(
					'components' => array(),
					'after'      => null,
					'mode'       => $r['mode'],
				),
			);
			$s['files_digest'] = hash( 'sha256', $s['files_digest'] . ':root:' . $root['id'] . ':' . $root['mode'] );
			$s['grammar']      = 'entry';
			return;
		}
		if ( in_array( $type, array( 'directory', 'file' ), true ) ) {
			$this->entry( $s, $r );
			$s['files_digest'] = hash( 'sha256', $s['files_digest'] . ':entry:' . json_encode( array( $r['components'], $type, $r['mode'], 'file' === $type ? $r['bytes'] : 0, $r['source_identity'] ) ) );
			if ( 'directory' === $type ) {
				$s['stack'][] = array(
					'components' => $r['components'],
					'after'      => null,
					'mode'       => $r['mode'],
				);
			} else {
				if ( ! is_int( $r['bytes'] ?? null ) || $r['bytes'] < 0 || ( $r['chunk_bytes'] ?? null ) !== $s['header']['chunk_bytes'] ) {
					throw new \RuntimeException( 'backup_archive_file' );
				}
				$s['file']    = array(
					'bytes'       => $r['bytes'],
					'offset'      => 0,
					'chain'       => Backup_Archive::initial_chain(),
					'chunk_bytes' => $r['chunk_bytes'],
				);
				$s['grammar'] = 'file';
			}
			return;
		}
		if ( 'file_chunk' === $type ) {
			if ( 'file' !== $s['grammar'] || ( $r['offset'] ?? null ) !== $s['file']['offset'] ||
				strlen( $payload ) !== min( $s['file']['chunk_bytes'], $s['file']['bytes'] - $s['file']['offset'] ) || '' === $payload ) {
				throw new \RuntimeException( 'backup_archive_file' );
			}
			$s['file']['offset'] += strlen( $payload );
			$s['file']['chain']   = Backup_Archive::fold( $s['file']['chain'], $payload );
			return;
		}
		if ( 'file_end' === $type ) {
			if ( 'file' !== $s['grammar'] || $s['file']['offset'] !== $s['file']['bytes'] || ( $r['bytes'] ?? null ) !== $s['file']['bytes'] ||
				! hash_equals( $s['file']['chain'], $r['chain'] ?? '' ) ) {
				throw new \RuntimeException( 'backup_archive_file' );
			}
			$s['files_digest'] = hash( 'sha256', $s['files_digest'] . ':file_end:' . $s['file']['chain'] );
			unset( $s['file'] );
			$s['grammar'] = 'entry';
			return;
		}
		if ( 'directory_end' === $type ) {
			$directory = end( $s['stack'] );
			if ( 'entry' !== $s['grammar'] || ! $directory || ( $r['components'] ?? null ) !== $directory['components'] ) {
				throw new \RuntimeException( 'backup_archive_directory' );
			}
			array_pop( $s['stack'] );
			$s['files_digest'] = hash( 'sha256', $s['files_digest'] . ':directory_end:' . json_encode( $r['components'] ) );
			$s['grammar']      = $s['stack'] ? 'entry' : 'root_end';
			return;
		}
		if ( 'root_end' === $type ) {
			if ( 'root_end' !== $s['grammar'] ) {
				throw new \RuntimeException( 'backup_archive_root' );
			}
			++$s['root_index'];
			$s['grammar'] = count( $s['header']['plan']['roots'] ) === $s['root_index'] ? 'footer' : 'root';
			return;
		}
		if ( 'footer' === $type ) {
			if ( 'footer' !== $s['grammar'] || true !== ( $r['source_verified'] ?? false ) || ! hash_equals( $s['chain'], $r['previous_chain'] ?? '' ) ||
				! hash_equals( $s['db_digest'], $r['database_digest'] ?? '' ) || ! hash_equals( $s['files_digest'], $r['files_digest'] ?? '' ) ) {
				throw new \RuntimeException( 'backup_archive_footer' );
			}
			$s['footer'] = $r;
			return;
		}
		throw new \RuntimeException( 'backup_archive_record_unsupported' );
	}

	/**
	 * One complete cell must be hashed in one native PHP replay. Frames remain
	 * bounded; HashContext is never serialized and no second payload is staged.
	 * A resource failure leaves preflight before any target effect.
	 */
	private function verify_cell_replay( $stream, array $state, array $cell ) {
		$start     = $state['row']['cell_start'] ?? null;
		$first_seq = $state['row']['cell_seq'] ?? null;
		$position  = is_resource( $stream ) ? ftell( $stream ) : false;
		if ( ! is_int( $start ) || $start < 0 || ! is_int( $first_seq ) || $first_seq < 0 ||
			! is_int( $position ) || 0 !== fseek( $stream, $start ) ) {
			throw new \RuntimeException( 'backup_cell_verification_unavailable' );
		}
		$seconds = $this->policy['step_seconds'] ?? null;
		if ( ! is_numeric( $seconds ) || $seconds <= 0 ) {
			$seconds = max( 1, (int) ini_get( 'max_execution_time' ) );
		}
		$deadline = microtime( true ) + $seconds;
		try {
			$hash  = hash_init( 'sha256' );
			$bytes = 0;
			for ( $seq = $first_seq; $seq <= $state['seq']; ++$seq ) {
				$frame  = Backup_Archive::read( $stream, $this->budget );
				$record = $frame['record'] ?? array();
				if ( null === $frame || ( $record['seq'] ?? null ) !== $seq ||
					'cell' !== ( $record['type'] ?? null ) || ( $record['column'] ?? null ) !== $cell['column'] ||
					( $record['offset'] ?? null ) !== $bytes || ( $record['total'] ?? null ) !== $cell['total'] ||
					false !== ( $record['null'] ?? null ) || ( $record['sha256'] ?? null ) !== $cell['sha256'] ||
					strlen( $frame['payload'] ) > $cell['total'] - $bytes ) {
					throw new \RuntimeException( 'backup_archive_cell_integrity' );
				}
				hash_update( $hash, $frame['payload'] );
				$bytes += strlen( $frame['payload'] );
				if ( microtime( true ) > $deadline ) {
					throw new \RuntimeException( 'backup_cell_verification_resources' );
				}
			}
			if ( $bytes !== $cell['total'] || ftell( $stream ) !== $position || ! hash_equals( $cell['sha256'], hash_final( $hash ) ) ) {
				throw new \RuntimeException( 'backup_archive_cell_integrity' );
			}
		} finally {
			if ( 0 !== fseek( $stream, $position ) ) {
				throw new \RuntimeException( 'backup_cell_verification_unavailable' );
			}
		}
	}

	private function entry( array &$s, array $r ) {
		$parts     = Backup_Operation::checked( Scoped_Filesystem::components( $r['components'] ?? null ) );
		$index     = count( $s['stack'] ) - 1;
		$directory = $s['stack'][ $index ] ?? null;
		$encoded   = array_map( 'base64_encode', $parts );
		$parent    = $encoded;
		$name      = array_pop( $parent );
		if ( 'entry' !== $s['grammar'] || ! is_array( $directory ) || $parent !== $directory['components'] ||
			! Backup_Archive::digest( $r['source_identity'] ?? null ) || ! is_int( $r['mode'] ?? null ) || $r['mode'] < 0 || $r['mode'] > 0777 ||
			( null !== $directory['after'] && strcmp( base64_decode( $name, true ), base64_decode( $directory['after'], true ) ) <= 0 ) ) {
			throw new \RuntimeException( 'backup_archive_directory_order' );
		}
		$s['stack'][ $index ]['after'] = $name;
	}

	/** Return false when a durable substep needs continuation on this same frame. */
	private function apply( array &$s, array $r, $payload, $job_id ) {
		$type = $r['type'];
		if ( 'table' === $type ) {
			$s['current_table'] = $r['table'];
			$schema             = Backup_Exporter::unpack_schema( $s['schemas'][ $r['table'] ] );
			$this->database->ddl(
				Backup_Schema::create( $schema['blueprint'], $s['target_schema'], $schema['table'] ),
				$this->operation( $s, 'table:' . $s['seq'] ),
				$s['origin']
			);
		} elseif ( 'row' === $type ) {
			$lease               = Backup_Operation::checked( $this->store( 'backup_input' )->begin_administration_stream( $this->policy['file'], $this->policy ) );
			$s['row_stage']      = $lease['id'];
			$s['row_cells']      = array();
			$s['row_bytes']      = 0;
			$s['row_chain']      = Backup_Archive::initial_chain();
			$s['row_group']      = $r['digest'];
			$s['row_count']      = Backup_Operation::native_int( $r['multiplicity'] );
			$s['row_occurrence'] = 0;
		} elseif ( 'cell' === $type ) {
			$i = $r['column'];
			if ( ! isset( $s['row_cells'][ $i ] ) ) {
				$s['row_cells'][ $i ] = array(
					'null'   => $r['null'],
					'offset' => $s['row_bytes'],
					'bytes'  => $r['total'],
					'sha256' => $r['sha256'],
				);
			}
			if ( '' !== $payload ) {
				Backup_Operation::checked( $this->store( 'backup_input' )->append_verified_frame( $s['row_stage'], $s['row_bytes'], $payload, hash( 'sha256', $payload ) ) );
				$s['row_bytes'] += strlen( $payload );
				$s['row_chain']  = Backup_Archive::fold( $s['row_chain'], $payload );
			}
		} elseif ( 'row_end' === $type ) {
			$row_store = $this->store( 'backup_input' );
			Backup_Operation::checked( $row_store->seal_administration_stream( $s['row_stage'], $s['row_bytes'], $s['row_chain'] ) );
			if ( $s['row_occurrence'] < $s['row_count'] ) {
				$schema = Backup_Exporter::unpack_schema( $s['schemas'][ $s['current_table'] ] );
				Backup_Operation::checked(
					$row_store->with_administration_source(
						$s['row_stage'],
						function ( $source ) use ( &$s, $schema ) {
							$this->database->insert(
								$s['target_schema'],
								$schema,
								$s['row_cells'],
								$source,
								$s['row_group'],
								$this->operation( $s, 'row:' . $s['seq'] . ':' . $s['row_occurrence'] )
							);
							return true;
						}
					)
				);
				++$s['row_occurrence'];
				return false;
			}
			$s['release_stage'] = $s['row_stage'];
			unset( $s['row_stage'], $s['row_cells'] );
		} elseif ( 'root' === $type ) {
			$s['current_root'] = $r['root'];
			$s['apply_stack']  = array(
				array(
					'components' => array(),
					'mode'       => $r['mode'],
				),
			);
			return $this->directory( $s, array(), $r['mode'] );
		} elseif ( 'directory' === $type ) {
			if ( ! $this->directory( $s, $r['components'], $r['mode'] ) ) {
				return false;
			}
			$s['apply_stack'][] = array(
				'components' => $r['components'],
				'mode'       => $r['mode'],
			);
		} elseif ( 'directory_end' === $type ) {
			$directory = end( $s['apply_stack'] );
			$parts     = $this->target_parts( $s, $r['components'] );
			if ( empty( $s['pending'] ) ) {
				$s['pending'] = array( 'identity' => Backup_Operation::checked( $this->filesystem->stat( $s['target_root'], $parts, 'backup_restore' ) )['identity'] );
				return false;
			}
			$this->committed( $this->filesystem->set_mode( $s['target_root'], $parts, 'backup_restore', $s['pending']['identity'], $directory['mode'], $this->operation( $s, 'mode:' . $s['seq'] ) ) );
			unset( $s['pending'] );
			array_pop( $s['apply_stack'] );
		} elseif ( 'file' === $type ) {
			$lease            = Backup_Operation::checked( $this->store( 'backup_input' )->begin_administration_stream( max( 1, $r['bytes'] ), $this->policy ) );
			$s['file_stage']  = $lease['id'];
			$s['file_record'] = $r;
			$s['file_bytes']  = 0;
			$s['file_chain']  = Backup_Archive::initial_chain();
		} elseif ( 'file_chunk' === $type ) {
			Backup_Operation::checked( $this->store( 'backup_input' )->append_verified_frame( $s['file_stage'], $s['file_bytes'], $payload, hash( 'sha256', $payload ) ) );
			$s['file_bytes'] += strlen( $payload );
			$s['file_chain']  = Backup_Archive::fold( $s['file_chain'], $payload );
		} elseif ( 'file_end' === $type ) {
			$history = Backup_Operation::checked( $this->filesystem->status( $s['target_root'], 'backup_restore', $this->operation( $s, 'file:' . $s['seq'] ) ) );
			if ( is_array( $history ) ) {
				$this->committed( $history );
				$descriptor = $history['receipt']['descriptor'] ?? array();
				if ( ( $descriptor['components'] ?? null ) !== $this->target_parts( $s, $s['file_record']['components'] ) ||
					( $descriptor['source_sha256'] ?? null ) !== $s['file_chain'] ||
					( $descriptor['bytes'] ?? null ) !== $s['file_bytes'] ||
					( $descriptor['mode'] ?? null ) !== $s['file_record']['mode'] ) {
					throw new \RuntimeException( 'backup_restore_receipt_conflict' );
				}
				$s['release_stage'] = $s['file_stage'];
				unset( $s['file_stage'], $s['file_record'] );
				return true;
			}
			$store = $this->store( 'backup_input' );
			Backup_Operation::checked( $store->seal_administration_stream( $s['file_stage'], $s['file_bytes'], $s['file_chain'] ) );
			$result = Backup_Operation::checked(
				$store->with_administration_source(
					$s['file_stage'],
					function ( $source ) use ( &$s ) {
						$source['chunk_bytes'] = $s['file_record']['chunk_bytes'];
						$source['mode']        = $s['file_record']['mode'];
						return $this->filesystem->apply_verified_source(
							$s['target_root'],
							$this->target_parts( $s, $s['file_record']['components'] ),
							'backup_restore',
							null,
							$source,
							$this->operation( $s, 'file:' . $s['seq'] )
						);
					}
				)
			);
			if ( ! empty( $result['preparing'] ) ) {
				return false;
			}
			$this->committed( $result );
			$s['release_stage'] = $s['file_stage'];
			unset( $s['file_stage'], $s['file_record'] );
		}
		return true;
	}

	private function directory( array &$s, array $parts, $mode ) {
		$target = $this->target_parts( $s, $parts );
		if ( empty( $s['pending'] ) ) {
			$parent = $target;
			array_pop( $parent );
			$s['pending'] = array( 'parent' => Backup_Operation::checked( $this->filesystem->stat( $s['target_root'], $parent, 'backup_restore' ) )['identity'] );
			return false;
		}
		$this->committed( $this->filesystem->mkdir( $s['target_root'], $target, 'backup_restore', $s['pending']['parent'], $this->operation( $s, 'directory:' . $s['seq'] ), 0700 ) );
		unset( $s['pending'] );
		return true;
	}

	private function constraints( array &$s ) {
		$tables = $s['header']['plan']['tables'];
		if ( $s['constraint_table'] >= count( $tables ) ) {
			$s['phase']                 = 'verify_database';
			$s['verify_table']          = 0;
			$s['verify_after']          = '';
			$s['verify_db']             = hash( 'sha256', 'wpai-backup-database-v1' );
			$s['verify_schema_started'] = false;
			return;
		}
		$schema  = Backup_Exporter::unpack_schema( $s['schemas'][ $tables[ $s['constraint_table'] ] ] );
		$foreign = $schema['blueprint']['foreign'][ $s['constraint_index'] ] ?? null;
		if ( null === $foreign ) {
			++$s['constraint_table'];
			$s['constraint_index'] = 0;
			return;
		}
		$this->database->ddl(
			Backup_Schema::add_foreign( $foreign, $s['target_schema'], $schema['table'] ),
			$this->operation( $s, 'foreign:' . $s['constraint_table'] . ':' . $s['constraint_index'] ),
			$s['origin']
		);
		++$s['constraint_index'];
	}

	private function verify_database( array &$s ) {
		$tables = $s['header']['plan']['tables'];
		if ( $s['verify_table'] >= count( $tables ) ) {
			if ( ! hash_equals( $s['footer']['database_digest'], $s['verify_db'] ) ) {
				throw new \RuntimeException( 'backup_restore_database_mismatch' );
			}
			$s['phase']        = 'verify_files';
			$s['status']       = 'verifying_restore';
			$s['offset']       = 0;
			$s['seq']          = 0;
			$s['chain']        = Backup_Archive::initial_chain();
			$s['verify_stack'] = array();
			return;
		}
		$table64 = $tables[ $s['verify_table'] ];
		$schema  = Backup_Exporter::unpack_schema( $s['schemas'][ $table64 ] );
		if ( ! $s['verify_schema_started'] ) {
			$current = $this->database->schema( $schema['table'], $s['target_schema'] );
			if ( ! hash_equals( $schema['hash'], $current['hash'] ) ) {
				throw new \RuntimeException( 'backup_restore_schema_mismatch' );
			}
			$s['verify_db']             = hash( 'sha256', $s['verify_db'] . ':table:' . $table64 . ':' . $schema['hash'] );
			$s['verify_schema_started'] = true;
		}
		$group = $this->database->next_group( $schema, $s['verify_after'], $s['target_schema'] );
		if ( null === $group ) {
			++$s['verify_table'];
			$s['verify_after']          = '';
			$s['verify_schema_started'] = false;
		} else {
			$s['verify_after'] = $group['digest'];
			$s['verify_db']    = hash( 'sha256', $s['verify_db'] . ':row:' . $group['digest'] . ':' . $group['multiplicity'] );
		}
	}

	private function verify_file_record( array &$s, array $r, $payload ) {
		$type = $r['type'];
		if ( 'root' === $type ) {
			$s['current_root'] = $r['root'];
			$parts             = $this->target_parts( $s, array() );
			$actual            = Backup_Operation::checked( $this->filesystem->stat( $s['target_root'], $parts, 'backup_restore' ) );
			if ( 'directory' !== $actual['type'] || $actual['mode'] !== $r['mode'] ) {
				throw new \RuntimeException( 'backup_restore_file_mismatch' );
			}
			$s['verify_stack'] = array(
				array(
					'parts'    => $parts,
					'expected' => 0,
				),
			);
		} elseif ( in_array( $type, array( 'directory', 'file' ), true ) ) {
			$index = count( $s['verify_stack'] ) - 1;
			++$s['verify_stack'][ $index ]['expected'];
			$parts  = $this->target_parts( $s, $r['components'] );
			$actual = Backup_Operation::checked( $this->filesystem->stat( $s['target_root'], $parts, 'backup_restore' ) );
			if ( $actual['type'] !== $type || $actual['mode'] !== $r['mode'] || 'file' === $type && $actual['bytes'] !== $r['bytes'] ) {
				throw new \RuntimeException( 'backup_restore_file_mismatch' );
			}
			if ( 'directory' === $type ) {
				$s['verify_stack'][] = array(
					'parts'    => $parts,
					'expected' => 0,
				);
			} else {
				$s['verify_file'] = array(
					'parts'    => $parts,
					'identity' => $actual['identity'],
				);
			}
		} elseif ( 'file_chunk' === $type ) {
			$result = Backup_Operation::checked(
				$this->filesystem->with_read_stream(
					$s['target_root'],
					$s['verify_file']['parts'],
					'backup_restore',
					$s['verify_file']['identity'],
					static function ( $stream ) use ( $r, $payload ) {
						return 0 === fseek( $stream, $r['offset'] ) && fread( $stream, strlen( $payload ) ) === $payload;
					}
				)
			);
			if ( true !== $result ) {
				throw new \RuntimeException( 'backup_restore_file_mismatch' );
			}
		} elseif ( 'directory_end' === $type ) {
			$directory = end( $s['verify_stack'] );
			if ( ! isset( $s['directory_count'] ) ) {
				$s['directory_count'] = array(
					'cursor' => 0,
					'count'  => 0,
				);
			}
			$count           = &$s['directory_count'];
			$result          = Backup_Operation::checked(
				$this->filesystem->iterate_entries(
					$s['target_root'],
					$directory['parts'],
					'backup_restore',
					null,
					static function () use ( &$count ) {
						++$count['count'];
						return true;
					},
					array(
						'cursor'  => $count['cursor'],
						'bytes'   => $this->policy['step_bytes'],
						'seconds' => $this->policy['step_seconds'],
					)
				)
			);
			$count['cursor'] = $result['cursor'];
			if ( ! $result['complete'] ) {
				return false;
			}
			if ( $count['count'] !== $directory['expected'] ) {
				throw new \RuntimeException( 'backup_restore_extra_files' );
			}
			unset( $s['directory_count'] );
			array_pop( $s['verify_stack'] );
		}
		return true;
	}

	private function verify_wrapper( array $s ) {
		$this->directory_count( $s['target_root'], array(), 1 );
		$this->directory_count( $s['target_root'], array( base64_encode( 'roots' ) ), count( $s['header']['plan']['roots'] ) );
		// No undeclared target database objects may appear during restoration.
		$this->database->assert_inventory( $s['target_schema'], array_map( array( Backup_Archive::class, 'decode_name' ), $s['header']['plan']['tables'] ) );
	}

	private function directory_count( $root, array $parts, $expected ) {
		$count  = 0;
		$result = Backup_Operation::checked(
			$this->filesystem->iterate_entries(
				$root,
				$parts,
				'backup_restore',
				null,
				static function () use ( &$count ) {
					++$count;
					return true;
				},
				array(
					'cursor'  => 0,
					'bytes'   => $this->policy['step_bytes'],
					'seconds' => $this->policy['step_seconds'],
				)
			)
		);
		if ( ! $result['complete'] || $count !== $expected ) {
			throw new \RuntimeException( 'backup_restore_extra_files' );
		}
	}

	private function empty_directory( $root, array $parts ) {
		$this->directory_count( $root, $parts, 0 );
	}

	private function target_parts( array $s, array $parts ) {
		return array_merge( array( base64_encode( 'roots' ), base64_encode( $s['current_root'] ) ), $parts );
	}

	private function committed( $result ) {
		$result = Backup_Operation::checked( $result );
		if ( 'committed' !== ( $result['outcome'] ?? $result['receipt']['result']['outcome'] ?? '' ) ) {
			throw new \RuntimeException( 'backup_restore_effect_unknown' );
		}
		return $result;
	}

	private function operation( array $s, $suffix ) {
		return 'backup:' . hash( 'sha256', $s['archive']['sha256'] . ':' . $s['target_schema'] . ':' . $s['target_root'] . ':' . $s['plan_hash'] ) . ':' . $suffix;
	}

	private function context( array $state ) {
		$config = $this->access->configuration();
		if ( 'restore' !== ( $state['kind'] ?? '' ) || ! $this->access->same_context( $state['origin'] ) ||
			$config['revision'] !== $state['configuration_revision'] || ! in_array( $state['target_schema'], $config['restore_schemas'], true ) ) {
			throw new \RuntimeException( 'backup_restore_context_changed' );
		}
		Backup_Operation::checked( ( new Filesystem_Roots( $this->permissions ) )->resolve( $state['target_root'], 'backup_restore', 'write' ) );
	}

	private function store( $purpose ) {
		return Private_Transfer_Session::for_administration( $this->permissions, $purpose, $this->access->context() );
	}

	private function receipt( $id, array $state ) {
		return array(
			'format'                => Backup_Archive::FORMAT,
			'session_id'            => $id,
			'state'                 => $state['status'],
			'phase'                 => $state['phase'],
			'complete'              => 'verified' === $state['status'],
			'plan_hash'             => $state['plan_hash'] ?? null,
			'archive_sha256'        => $state['archive']['sha256'],
			'archive_offset'        => $state['offset'],
			'archive_bytes'         => $state['archive']['bytes'],
			'consistency'           => 'declared_quiescent_verified_capture',
			'quiescence_assumption' => true,
			'source_verified'       => isset( $state['footer'] ),
			'restore_verified'      => 'verified' === $state['status'],
			'activation_pending'    => true,
			'target_schema'         => $state['target_schema'],
			'target_root'           => $state['target_root'],
			'logical_root_layout'   => 'roots/<source_root_id>/<original_relative_components>',
			'restored_table_count'  => count( $state['schemas'] ),
		);
	}
}
