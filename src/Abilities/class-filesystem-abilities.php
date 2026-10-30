<?php
/**
 * Typed administrator filesystem and resumable exchange Abilities.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Administration_Access;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Filesystem_Operation;
use WP_AI_Bridge\Support\Filesystem_Roots;
use WP_AI_Bridge\Support\Filesystem_Transfer;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Scoped_Filesystem;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_Error;

/** Root configuration is admin-only; this surface accepts no absolute server paths. */
final class Filesystem_Abilities {
	private $permissions;
	private $access;
	private $fs;
	private $log;

	public function __construct( Permissions $permissions, ?Mutation_Log $log = null ) {
		$this->permissions = $permissions;
		$this->access      = new Administration_Access( $permissions );
		$this->fs          = new Scoped_Filesystem( $permissions );
		$this->log         = $log;
	}

	public function register() {
		$registered = array();
		foreach ( array(
			'filesystem-read'     => array( 'read', 'Read Scoped Filesystem', true ),
			'filesystem-write'    => array( 'write', 'Manage Scoped Filesystem', false ),
			'filesystem-transfer' => array( 'transfer', 'Exchange Scoped File Bytes', false ),
		) as $name => $definition ) {
			$registered[] = wp_register_ability(
				'wp-ai-bridge/' . $name,
				array(
					'label'               => $definition[1],
					'description'         => 'Administrator-configured filesystem roots, current native authority, exact identities and explicit recovery.',
					'category'            => Registrar::CATEGORY,
					'input_schema'        => $this->input_schema(),
					'output_schema'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
					'permission_callback' => array( $this, 'can_' . $definition[0] ),
					'execute_callback'    => array( $this, $definition[0] ),
					'meta'                => array(
						'mcp'         => array(
							'public' => true,
							'type'   => 'tool',
						),
						'annotations' => array(
							'readonly'    => $definition[2],
							'destructive' => ! $definition[2],
							'idempotent'  => $definition[2],
						),
					),
				)
			);
		}
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	public function can_read() {
		return $this->access->allowed( 'filesystem_read' );
	}

	public function can_write() {
		return $this->access->allowed( 'filesystem_read' ) && $this->access->allowed( 'filesystem_write' );
	}

	public function can_transfer( $input ) {
		if ( ! is_array( $input ) ) {
			return false; }
		$read = in_array( $input['action'] ?? '', array( 'export_begin', 'export_step', 'read' ), true ) || ! empty( $input['export'] );
		return $read ? $this->can_read() : $this->can_write();
	}

	private function path( array $input, $prefix = '' ) {
		if ( isset( $input[ $prefix . 'path' ], $input[ $prefix . 'components' ] ) ) {
			return new WP_Error( 'filesystem_path_invalid', 'Choose one exact relative-path representation.' );
		}
		$path  = $input[ $prefix . 'components' ] ?? ( $input[ $prefix . 'path' ] ?? '' );
		$parts = Scoped_Filesystem::components( $path );
		return is_wp_error( $parts ) ? $parts : array_map( 'base64_encode', $parts );
	}

	public function read( $input ) {
		if ( ! is_array( $input ) || ! $this->can_read() ) {
			return new WP_Error( 'filesystem_denied', 'Current filesystem read delegation is required.' );
		}
		if ( 'roots' === ( $input['action'] ?? '' ) ) {
			return array( 'roots' => ( new Filesystem_Roots( $this->permissions ) )->available() );
		}
		$path = $this->path( $input );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$root     = $input['root_id'] ?? '';
		$expected = $input['expected_identity'] ?? null;
		switch ( $input['action'] ?? '' ) {
			case 'stat':
				$result = $this->fs->stat( $root, $path );
				return is_wp_error( $result ) ? $result : Scoped_Filesystem::public_descriptor( $result );
			case 'hash':
				return $this->fs->hash( $root, $path, 'filesystem', $expected );
			case 'read':
				$result = $this->fs->read_window( $root, $path, 'filesystem', $expected, $input['offset'] ?? 0, $input['max_bytes'] ?? 1 );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array(
					'base64'       => base64_encode( $result['bytes'] ),
					'chunk_sha256' => hash( 'sha256', $result['bytes'] ),
					'offset'       => $result['offset'],
					'next_offset'  => $result['next_offset'],
					'complete'     => $result['complete'],
					'file'         => Scoped_Filesystem::public_descriptor( $result['descriptor'] ),
				);
			case 'list':
				$entries = array();
				$budget  = Bounded_Payload::response_limit();
				$result  = $this->fs->iterate_entries(
					$root,
					$path,
					'filesystem',
					$expected,
					function ( $entry ) use ( &$entries, &$budget ) {
						$public = Scoped_Filesystem::public_descriptor( $entry );
						$bytes  = strlen( wp_json_encode( $public ) );
						if ( $bytes + 4096 > $budget ) {
							return null;
						}
						$budget   -= $bytes;
						$entries[] = $public;
						return true;
					},
					array( 'cursor' => $input['cursor'] ?? 0 )
				);
				return is_wp_error( $result ) ? $result : array_merge( $result, array( 'entries' => $entries ) );
		}
		return new WP_Error( 'filesystem_input_invalid', 'Select roots, stat, list, read or hash.' );
	}

	public function transfer( $input ) {
		if ( ! is_array( $input ) || ! $this->can_transfer( $input ) ) {
			return new WP_Error( 'filesystem_denied', 'Current purpose-specific filesystem transfer authority is required.' );
		}
		return ( new Filesystem_Transfer( $this->permissions ) )->execute( $input );
	}

	public function write( $input ) {
		if ( ! is_array( $input ) || ! $this->can_write() ) {
			return new WP_Error( 'filesystem_denied', 'Current filesystem write delegation is required.' );
		}
		$path = $this->path( $input );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$root     = $input['root_id'] ?? '';
		$id       = $input['operation_id'] ?? '';
		$action   = $input['action'] ?? '';
		$expected = $input['expected_identity'] ?? null;
		if ( in_array( $action, array( 'delete', 'move', 'rename', 'recover' ), true ) && ! $this->access->allowed( 'filesystem_delete' ) ) {
			return new WP_Error( 'filesystem_denied', 'Destructive filesystem authority is required.' );
		}
		switch ( $action ) {
			case 'status':
				return $this->fs->status( $root, 'filesystem', $id );
			case 'mkdir':
				$result = $this->fs->mkdir( $root, $path, 'filesystem', $input['expected_parent_identity'] ?? null, $id );
				break;
			case 'delete':
				$preimage = isset( $input['expected_sha256'] ) ?
					array(
						'identity' => $expected,
						'sha256'   => $input['expected_sha256'],
					) : $expected;
				$result   = $this->fs->delete( $root, $path, 'filesystem', $preimage, $id );
				break;
			case 'write':
				$store = ( new Filesystem_Transfer( $this->permissions ) )->store( $root );
				if ( is_wp_error( $store ) ) {
					return $store;
				}
				$source_status = $store->status( $input['transfer_id'] ?? '' );
				if ( is_wp_error( $source_status ) ) {
					return $source_status; }
				$history = $this->fs->write_status(
					$root,
					$path,
					'filesystem',
					null === $expected ? null : array(
						'identity' => $expected,
						'sha256'   => $input['expected_sha256'] ?? null,
					),
					array(
						'bytes'     => $source_status['total'],
						'sha256'    => $source_status['sha256'],
						'integrity' => $source_status['integrity'],
					),
					$id
				);
				if ( null !== $history ) {
					return $history; }
				$result = $store->with_administration_source(
					$input['transfer_id'] ?? '',
					function ( $source ) use ( $root, $path, $expected, $input, $id ) {
						return $this->fs->apply_verified_source(
							$root,
							$path,
							'filesystem',
							null === $expected ? null : array(
								'identity' => $expected,
								'sha256'   => $input['expected_sha256'] ?? null,
							),
							$source,
							$id
						);
					}
				);
				break;
			case 'copy':
			case 'move':
			case 'rename':
				$operations = new Filesystem_Operation( $this->permissions );
				if ( ! empty( $input['transfer_id'] ) ) {
					$result = $operations->step( $root, $input['transfer_id'], in_array( $action, array( 'move', 'rename' ), true ) );
				} else {
					$destination = $this->path( $input, 'destination_' );
					if ( is_wp_error( $destination ) ) {
						return $destination;
					}
					$result = $operations->begin( $root, $path, $destination, in_array( $action, array( 'move', 'rename' ), true ), $expected, $id );
				}
				break;
			case 'recover':
				$result = $this->fs->recover( $root, $path, 'filesystem', $id, $input['recovery_id'] ?? '', $expected );
				break;
			default:
				return new WP_Error( 'filesystem_input_invalid', 'Select a supported typed filesystem write action.' );
		}
		if ( null !== $this->log && ( is_wp_error( $result ) || ( empty( $result['preparing'] ) && 'incomplete' !== ( $result['outcome'] ?? '' ) ) ) ) {
			$this->log->record(
				'wp-ai-bridge/filesystem-write',
				'filesystem',
				0,
				! is_wp_error( $result ) && 'committed' === ( $result['outcome'] ?? '' ),
				is_wp_error( $result ) ? $result->get_error_code() : ( 'committed' === ( $result['outcome'] ?? '' ) ? '' : 'filesystem_' . ( $result['outcome'] ?? 'unknown' ) )
			);
		}
		return $result;
	}

	private function input_schema() {
		$properties = array();
		foreach ( array(
			'action',
			'root_id',
			'path',
			'destination_path',
			'operation_id',
			'recovery_id',
			'transfer_id',
			'expected_identity',
			'expected_parent_identity',
			'expected_sha256',
			'sha256',
			'base64',
		) as $key ) {
			$properties[ $key ] = array( 'type' => 'string' );
		}
		foreach ( array( 'components', 'destination_components' ) as $key ) {
			$properties[ $key ] = array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			);
		}
		foreach ( array( 'offset', 'max_bytes', 'cursor', 'total_bytes' ) as $key ) {
			$properties[ $key ] = array(
				'type'    => 'integer',
				'minimum' => 0,
			);
		}
		$properties['export'] = array( 'type' => 'boolean' );
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}
}
