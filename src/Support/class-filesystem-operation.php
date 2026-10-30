<?php
/**
 * Bounded copy/move continuation over the existing private transfer journal.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support;

use WP_Error;

/** Directory moves are copy-exclusive, verify, then conditional-delete; never overwrite rename. */
final class Filesystem_Operation {
	private $permissions;
	private $access;
	private $fs;
	private $roots;

	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
		$this->access      = new Administration_Access( $permissions );
		$this->fs          = new Scoped_Filesystem( $permissions );
		$this->roots       = new Filesystem_Roots( $permissions );
	}

	private function store( $root_id, $move ) {
		$root = $this->roots->resolve( $root_id, 'filesystem', $move ? 'destructive' : 'write' );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		return Private_Transfer_Session::for_administration(
			$this->permissions,
			'filesystem_input',
			array(
				'root_id'         => $root_id,
				'revision'        => $root['revision'],
				'require_network' => 'network' === $root['scope'],
				'operation'       => $move ? 'move' : 'copy',
			)
		);
	}


	private function journal( $root_id, $move ) {
		$root = $this->roots->resolve( $root_id, 'filesystem', $move ? 'destructive' : 'write' );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		return new Administration_Operation_Journal(
			'filesystem',
			array_merge( array( 'scope' => $root['scope'] ), $root['origin'] ),
			( new Transfer_Settings() )->bytes()['request']
		);
	}

	public function begin( $root_id, $source, $destination, $move, $expected_identity, $operation_id ) {
		$store   = $this->store( $root_id, $move );
		$journal = $this->journal( $root_id, $move );
		if ( is_wp_error( $store ) || is_wp_error( $journal ) ) {
			return is_wp_error( $store ) ? $store : $journal;
		}
		$source_parts = Scoped_Filesystem::components( $source );
		$parts        = Scoped_Filesystem::components( $destination );
		if ( is_wp_error( $source_parts ) || ! $source_parts || is_wp_error( $parts ) || ! $parts ||
			! is_bool( $move ) || ! is_string( $expected_identity ) ||
			! is_string( $operation_id ) || ! preg_match( '/^[A-Za-z0-9_.:-]{8,128}$/D', $operation_id ) ) {
			return new WP_Error( 'filesystem_operation_invalid', 'Select exact source, absent destination and stable operation identity.' );
		}
		$source_parts = array_map( 'base64_encode', $source_parts );
		$destination  = array_map( 'base64_encode', $parts );
		if ( $destination === $source_parts || array_slice( $destination, 0, count( $source_parts ) ) === $source_parts ) {
			return new WP_Error( 'filesystem_operation_recursive', 'The copy destination must be outside the source subtree.' );
		}
		$request     = array(
			'action'            => 'directory_copy',
			'root_id'           => $root_id,
			'source'            => $source_parts,
			'destination'       => $destination,
			'move'              => $move,
			'expected_identity' => $expected_identity,
			'root_revision'     => $this->access->configuration()['revision'],
		);
		$fingerprint = hash( 'sha256', serialize( $request ) );
		$existing    = $journal->status( $operation_id, $fingerprint );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			$id = $existing['receipt']['result']['continuation_id'] ?? null;
			if ( ! is_string( $id ) ) {
				return new WP_Error( 'filesystem_operation_recovery_required', 'The initial continuation receipt is incomplete; inspect it before allocating another operation.' );
			}
			$done = $journal->status( 'fs-done-' . hash( 'sha256', $operation_id ) );
			if ( is_wp_error( $done ) ) {
				return $done; }
			return array(
				'transfer_id' => $id,
				'outcome'     => is_array( $done ) &&
					'committed' === ( $done['receipt']['result']['copy_outcome'] ?? '' ) ? 'committed' : 'incomplete',
				'historical'  => true,
				'move'        => $move,
			);
		}
		$from = $this->fs->stat( $root_id, $source_parts );
		if ( is_wp_error( $from ) || $from['identity'] !== $expected_identity ) {
			return new WP_Error( 'filesystem_stale', 'The selected source changed before copy preparation.' );
		}
		$existing_path = $this->fs->stat( $root_id, $destination );
		if ( ! is_wp_error( $existing_path ) || 'filesystem_not_found' !== $existing_path->get_error_code() ) {
			return new WP_Error( 'filesystem_destination_exists', 'The destination must be absent; existing trees are never merged or overwritten.' );
		}
		$claim = $journal->reserve( $operation_id, $fingerprint, $request );
		if ( is_wp_error( $claim ) || empty( $claim['dispatch_allowed'] ) ) {
			return is_wp_error( $claim ) ? $claim :
				new WP_Error( 'filesystem_operation_recovery_required', 'Another request owns continuation creation; inspect its receipt.' );
		}
		$policy = ( new Transfer_Settings() )->bytes();
		$lease  = $store->begin(
			'filesystem_input',
			0,
			hash( 'sha256', '' ),
			$policy['file'],
			$policy['chunk'],
			$policy['sessions'],
			$policy['ttl'],
			$policy['active']
		);
		if ( is_wp_error( $lease ) ) {
			$journal->complete(
				$claim['claim'],
				array(
					'outcome' => 'not_dispatched',
					'reason'  => $lease->get_error_code(),
				)
			);
			return $lease;
		}
		$verified = $store->verify( $lease['id'] );
		if ( is_wp_error( $verified ) ) {
			$store->discard_administration_creation( $lease['id'] );
			$journal->complete(
				$claim['claim'],
				array(
					'outcome' => 'not_dispatched',
					'reason'  => $verified->get_error_code(),
				)
			);
			return $verified;
		}
		$state = array(
			'format'             => 'wpai-filesystem-copy-v1',
			'root_id'            => $root_id,
			'move'               => $move,
			'operation_id'       => $operation_id,
			'parent_fingerprint' => $fingerprint,
			'outcome'            => 'incomplete',
			'processed'          => 0,
			'stack'              => array(),
			'file'               => null,
		);
		$entry = array(
			'source'      => $source_parts,
			'destination' => $destination,
			'identity'    => $from['identity'],
			'mode'        => $from['mode'],
			'created'     => false,
			'cursor'      => 0,
		);
		if ( 'directory' === $from['type'] ) {
			$state['stack'][] = $entry;
		} else {
			$state['file'] = $entry;
		}
		$saved = $store->checkpoint( $lease['id'], $state, 0 );
		if ( is_wp_error( $saved ) ) {
			$store->discard_administration_creation( $lease['id'] );
			$journal->complete(
				$claim['claim'],
				array(
					'outcome' => 'not_dispatched',
					'reason'  => $saved->get_error_code(),
				)
			);
			return $saved;
		}
		// This receipt creates only inert continuation state. Every visible child effect has its own arm.
		$recorded = $journal->complete(
			$claim['claim'],
			array(
				'outcome'         => 'not_dispatched',
				'continuation_id' => $lease['id'],
				'copy_state'      => 'started',
			)
		);
		if ( is_wp_error( $recorded ) ) {
			return $recorded;
		}
		return array(
			'transfer_id' => $lease['id'],
			'outcome'     => 'incomplete',
			'processed'   => 0,
			'move'        => $move,
		);
	}

	private function finish( array $state, $id, ?callable $release = null ) {
		$journal = $this->journal( $state['root_id'], $state['move'] );
		if ( is_wp_error( $journal ) ) {
			return $journal;
		}
		$descriptor = array(
			'action'             => 'directory_copy_completed',
			'parent_operation'   => $state['operation_id'],
			'parent_fingerprint' => $state['parent_fingerprint'],
			'continuation_id'    => $id,
			'processed'          => $state['processed'],
		);
		$claim      = $journal->reserve(
			'fs-done-' . hash( 'sha256', $state['operation_id'] ),
			hash( 'sha256', serialize( $descriptor ) ),
			$descriptor
		);
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}
		if ( ! empty( $claim['dispatch_allowed'] ) ) {
			$recorded = $journal->complete(
				$claim['claim'],
				array(
					'outcome'      => 'not_dispatched',
					'copy_outcome' => 'committed',
				)
			);
			if ( is_wp_error( $recorded ) ) {
				return $recorded;
			}
		} elseif ( 'committed' !== ( $claim['receipt']['result']['copy_outcome'] ?? '' ) ) {
			return new WP_Error( 'filesystem_operation_recovery_required', 'Copy completion receipt remains uncertain.' );
		}
		if ( null !== $release ) {
			$released = $release();
			if ( is_wp_error( $released ) ) {
				return $released;
			}
		}
		return array(
			'transfer_id' => $id,
			'outcome'     => 'committed',
			'processed'   => $state['processed'],
			'move'        => $state['move'],
		);
	}

	public function step( $root_id, $id, $move ) {
		$store = $this->store( $root_id, $move );
		if ( is_wp_error( $store ) ) {
			return $store;
		}
		$checkpoint = $store->read_checkpoint( $id );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint; }
		if ( 'committed' === ( $checkpoint['state']['outcome'] ?? '' ) ) {
			return $this->finish( $checkpoint['state'], $id );
		}
		return $store->with_administration_source(
			$id,
			function ( $lease ) use ( $root_id, $move, $id ) {
				$state = $lease['checkpoint'];
				if ( 'wpai-filesystem-copy-v1' !== ( $state['format'] ?? '' ) ||
					$root_id !== $state['root_id'] || $move !== $state['move'] ) {
					return new WP_Error( 'filesystem_operation_invalid', 'The private continuation belongs to another copy/move.' );
				}
				if ( 'committed' === $state['outcome'] ) {
					return array(
						'transfer_id' => $id,
						'outcome'     => 'committed',
						'processed'   => $state['processed'],
						'move'        => $move,
					);
				}
				if ( ! empty( $state['file'] ) ) {
					$result = $this->copy_file( $state, $lease['save_checkpoint'] );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					if ( ! empty( $result['preparing'] ) ) {
						return array(
							'transfer_id' => $id,
							'outcome'     => 'incomplete',
							'processed'   => $state['processed'],
							'move'        => $move,
						);
					}
				} elseif ( $state['stack'] ) {
					$index = count( $state['stack'] ) - 1;
					$frame = $state['stack'][ $index ];
					if ( $move && isset( $frame['delete_identity'] ) ) {
						$result = $this->fs->delete(
							$root_id,
							$frame['source'],
							'filesystem',
							$frame['delete_identity'],
							$this->child_id( $state, 'rmdir', $frame['source'] )
						);
						if ( is_wp_error( $result ) || 'committed' !== ( $result['outcome'] ?? '' ) ) {
							return is_wp_error( $result ) ? $result : new WP_Error( 'filesystem_operation_recovery_required', 'Source directory deletion remains uncertain.' );
						}
						array_pop( $state['stack'] );
						++$state['processed'];
						if ( ! $state['stack'] ) {
							$state['outcome'] = 'committed'; }
						$saved = $lease['save_checkpoint']( $state );
						if ( ! is_wp_error( $saved ) && 'committed' === $state['outcome'] ) {
							return $this->finish( $state, $id, $lease['release_payload'] );
						}
						return is_wp_error( $saved ) ? $saved : array(
							'transfer_id' => $id,
							'outcome'     => $state['outcome'],
							'processed'   => $state['processed'],
							'move'        => true,
						);
					}
					if ( ! $frame['created'] ) {
						$parent_parts = $frame['destination'];
						array_pop( $parent_parts );
						$parent = $this->fs->stat( $root_id, $parent_parts );
						if ( is_wp_error( $parent ) ) {
							return $parent;
						}
						if ( empty( $frame['parent_identity'] ) ) {
							$state['stack'][ $index ]['parent_identity'] = $parent['identity'];
							$saved                                       = $lease['save_checkpoint']( $state );
							if ( is_wp_error( $saved ) ) {
								return $saved; }
							$frame = $state['stack'][ $index ];
						}
						$result = $this->fs->mkdir(
							$root_id,
							$frame['destination'],
							'filesystem',
							$frame['parent_identity'],
							$this->child_id( $state, 'mkdir', $frame['source'] ),
							0755
						);
						if ( is_wp_error( $result ) || 'committed' !== ( $result['outcome'] ?? '' ) ) {
							return is_wp_error( $result ) ? $result : new WP_Error( 'filesystem_operation_recovery_required', 'Directory creation has an uncertain or rejected receipt; inspect it before continuing.' );
						}
						$state['stack'][ $index ]['created'] = true;
					} else {
						$entry   = null;
						$listing = $this->fs->iterate_entries(
							$root_id,
							$frame['source'],
							'filesystem',
							$move ? null : $frame['identity'],
							function ( $candidate ) use ( &$entry ) {
								$entry = $candidate;
								return false;
							},
							array(
								'cursor'           => $frame['cursor'],
								'entries'          => 1,
								'reject_protected' => true,
							)
						);
						if ( is_wp_error( $listing ) ) {
							return $listing;
						}
						if ( is_array( $entry ) ) {
							// Move deletes prior source children, so each scan starts from zero.
							$state['stack'][ $index ]['cursor'] = $move ? 0 : $listing['cursor'];
							$destination                        = $frame['destination'];
							$destination[]                      = end( $entry['components'] );
							$child                              = array(
								'source'      => $entry['components'],
								'destination' => $destination,
								'identity'    => $entry['identity'],
								'mode'        => $entry['mode'],
								'created'     => false,
								'cursor'      => 0,
							);
							if ( 'directory' === $entry['type'] ) {
								$state['stack'][] = $child;
							} else {
								$state['file'] = $child;
							}
						} elseif ( $listing['complete'] ) {
							// Directory mode changes are deferred to avoid blocking child construction.
							$mode_target = $this->fs->stat( $root_id, $frame['destination'] );
							if ( is_wp_error( $mode_target ) ) {
								return $mode_target; }
							if ( empty( $frame['mode_identity'] ) ) {
								$state['stack'][ $index ]['mode_identity'] = $mode_target['identity'];
								$saved                                     = $lease['save_checkpoint']( $state );
								if ( is_wp_error( $saved ) ) {
									return $saved; }
								$frame = $state['stack'][ $index ];
							}
							$result = $this->fs->set_mode(
								$root_id,
								$frame['destination'],
								'filesystem',
								$frame['mode_identity'],
								$frame['mode'],
								$this->child_id( $state, 'mode', $frame['source'] )
							);
							if ( is_wp_error( $result ) || 'committed' !== ( $result['outcome'] ?? '' ) ) {
								return is_wp_error( $result ) ? $result : new WP_Error( 'filesystem_operation_recovery_required', 'Directory mode completion is uncertain.' );
							}
							if ( $move ) {
								$current = $this->fs->stat( $root_id, $frame['source'] );
								if ( is_wp_error( $current ) ) {
									return $current;
								}
								$state['stack'][ $index ]['delete_identity'] = $current['identity'];
								$saved                                       = $lease['save_checkpoint']( $state );
								if ( is_wp_error( $saved ) ) {
									return $saved; }
								$result = $this->fs->delete(
									$root_id,
									$frame['source'],
									'filesystem',
									$current['identity'],
									$this->child_id( $state, 'rmdir', $frame['source'] )
								);
								if ( is_wp_error( $result ) || 'committed' !== ( $result['outcome'] ?? '' ) ) {
									return is_wp_error( $result ) ? $result : new WP_Error( 'filesystem_operation_recovery_required', 'Source directory removal is uncertain.' );
								}
							}
							array_pop( $state['stack'] );
							++$state['processed'];
						} else {
							$state['stack'][ $index ]['cursor'] = $listing['cursor'];
						}
					}
				}
				if ( ! $state['stack'] && empty( $state['file'] ) ) {
					$state['outcome'] = 'committed';
				}
				$saved = $lease['save_checkpoint']( $state );
				if ( ! is_wp_error( $saved ) && 'committed' === $state['outcome'] ) {
					return $this->finish( $state, $id, $lease['release_payload'] );
				}
				return is_wp_error( $saved ) ? $saved :
					array(
						'transfer_id' => $id,
						'outcome'     => $state['outcome'],
						'processed'   => $state['processed'],
						'move'        => $move,
					);
			}
		);
	}

	private function child_id( array $state, $action, array $source ) {
		return 'fs-' . hash( 'sha256', serialize( array( $state['operation_id'], $action, $source ) ) );
	}


	private function copy_file( array &$state, callable $save ) {
		$file = $state['file'];
		if ( $state['move'] && ! empty( $file['sha256'] ) ) {
			$deleted = $this->fs->status( $state['root_id'], 'filesystem', $this->child_id( $state, 'delete', $file['source'] ) );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
			if ( is_array( $deleted ) ) {
				if ( 'committed' !== ( $deleted['outcome'] ?? '' ) ) {
					return new WP_Error( 'filesystem_operation_recovery_required', 'Historical source deletion is uncertain; preserve both trees.' );
				}
				$verified = $this->fs->hash( $state['root_id'], $file['destination'] );
				if ( is_wp_error( $verified ) || ! hash_equals( $file['sha256'], $verified['sha256'] ) ) {
					return new WP_Error( 'filesystem_operation_recovery_required', 'Moved destination changed after recorded deletion.' );
				}
				$state['file'] = null;
				++$state['processed'];
				return $deleted;
			}
		}
		if ( empty( $file['sha256'] ) ) {
			$hash = $this->fs->hash( $state['root_id'], $file['source'], 'filesystem', $file['identity'] );
			if ( is_wp_error( $hash ) ) {
				return $hash;
			}
			$state['file']['sha256'] = $hash['sha256'];
			$state['file']['bytes']  = $hash['size'];
			$file                    = $state['file'];
		}
		$source_metadata = array(
			'bytes'     => $file['bytes'],
			'sha256'    => $file['sha256'],
			'integrity' => 'sha256',
			'mode'      => $file['mode'],
		);
		$result          = $this->fs->write_status(
			$state['root_id'],
			$file['destination'],
			'filesystem',
			null,
			$source_metadata,
			$this->child_id( $state, 'copy', $file['source'] )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$store = ( new Filesystem_Transfer( $this->permissions ) )->store( $state['root_id'] );
		if ( is_wp_error( $store ) ) {
			return $store;
		}
		if ( null === $result ) {
			$policy = ( new Transfer_Settings() )->bytes();
			if ( empty( $file['input_id'] ) ) {
				$input = $store->begin(
					'filesystem_input',
					$file['bytes'],
					$file['sha256'],
					$policy['file'],
					$policy['chunk'],
					$policy['sessions'],
					$policy['ttl'],
					$policy['active']
				);
				if ( is_wp_error( $input ) ) {
					return $input;
				}
				$state['file']['input_id'] = $input['id'];
				$saved                     = $save( $state );
				if ( is_wp_error( $saved ) ) {
					$store->discard_administration_creation( $input['id'] );
					return $saved;
				}
				$file = $state['file'];
			}
			$input = $store->status( $file['input_id'] );
			if ( is_wp_error( $input ) ) {
				return $input;
			}
			if ( $input['received'] < $file['bytes'] ) {
				$config = $this->access->configuration();
				$copied = $this->fs->with_read_stream(
					$state['root_id'],
					$file['source'],
					'filesystem',
					$file['identity'],
					function ( $stream ) use ( $store, $file, $input, $policy, $config ) {
						$offset = $input['received'];
						if ( 0 !== fseek( $stream, $offset ) ) {
							return new WP_Error( 'filesystem_source_changed', 'Copy source continuation offset changed.' );
						}
						$start = microtime( true );
						$used  = 0;
						while ( $offset < $file['bytes'] && $used < $config['step_bytes'] &&
							microtime( true ) - $start < $config['step_seconds'] ) {
							$length = min( $policy['chunk'], $config['step_bytes'] - $used, $file['bytes'] - $offset );
							$bytes  = fread( $stream, $length );
							if ( ! is_string( $bytes ) || strlen( $bytes ) !== $length ) {
								return new WP_Error( 'filesystem_source_changed', 'The selected copy source ended early.' );
							}
							$appended = $store->append( $file['input_id'], $offset, $bytes );
							if ( is_wp_error( $appended ) ) {
								return $appended;
							}
							$offset += $length;
							$used   += $length;
						}
						return array( 'received' => $offset );
					}
				);
				if ( is_wp_error( $copied ) ) {
					return $copied;
				}
				if ( $copied['received'] < $file['bytes'] ) {
					return array(
						'preparing' => true,
						'outcome'   => 'not_dispatched',
					);
				}
			}
			$verified = $store->verify( $file['input_id'] );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			$result = $store->with_administration_source(
				$file['input_id'],
				function ( $source ) use ( $file, $state ) {
					$source['mode'] = $file['mode'];
					return $this->fs->apply_verified_source(
						$state['root_id'],
						$file['destination'],
						'filesystem',
						null,
						$source,
						$this->child_id( $state, 'copy', $file['source'] )
					);
				}
			);
		}
		if ( is_wp_error( $result ) || 'committed' !== ( $result['outcome'] ?? '' ) ) {
			return is_wp_error( $result ) ? $result :
				new WP_Error( 'filesystem_operation_recovery_required', 'The destination has a rejected or uncertain receipt; never blindly repeat it.' );
		}
		$target = $this->fs->hash( $state['root_id'], $file['destination'] );
		if ( is_wp_error( $target ) || ! hash_equals( $file['sha256'], $target['sha256'] ) ) {
			return new WP_Error( 'filesystem_operation_recovery_required', 'Destination verification failed; preserve the source.' );
		}
		if ( isset( $file['input_id'] ) ) {
			$cancelled = $store->cancel( $file['input_id'] );
			if ( is_wp_error( $cancelled ) && 'transfer_storage_uncertain' !== $cancelled->get_error_code() ) {
				return $cancelled;
			}
		}
		if ( $state['move'] ) {
			$deleted = $this->fs->delete(
				$state['root_id'],
				$file['source'],
				'filesystem',
				array(
					'identity' => $file['identity'],
					'sha256'   => $file['sha256'],
				),
				$this->child_id( $state, 'delete', $file['source'] )
			);
			if ( is_wp_error( $deleted ) || 'committed' !== ( $deleted['outcome'] ?? '' ) ) {
				return is_wp_error( $deleted ) ? $deleted :
					new WP_Error( 'filesystem_operation_recovery_required', 'Verified copy exists but conditional source deletion is uncertain.' );
			}
		}
		$state['file'] = null;
		++$state['processed'];
		return $result;
	}
}
