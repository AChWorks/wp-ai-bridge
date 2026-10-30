<?php
/**
 * Purpose-bound filesystem byte exchange using the existing private backend.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support;

use WP_Error;

/** Ingress is inert; publication is always a separately journalled filesystem effect. */
final class Filesystem_Transfer {
	private $permissions;
	private $roots;
	private $fs;

	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
		$this->roots       = new Filesystem_Roots( $permissions );
		$this->fs          = new Scoped_Filesystem( $permissions );
	}

	public function store( $root_id, $export = false ) {
		$root = $this->roots->resolve( $root_id, 'filesystem', $export ? 'read' : 'write' );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		return Private_Transfer_Session::for_administration(
			$this->permissions,
			$export ? 'filesystem_export' : 'filesystem_input',
			array(
				'root_id'         => $root_id,
				'revision'        => $root['revision'],
				'require_network' => 'network' === $root['scope'],
			)
		);
	}

	public function execute( array $input ) {
		$action = $input['action'] ?? '';
		$export = in_array( $action, array( 'export_begin', 'export_step', 'read' ), true ) || ! empty( $input['export'] );
		$store  = $this->store( $input['root_id'] ?? '', $export );
		if ( is_wp_error( $store ) ) {
			return $store;
		}
		$policy = ( new Transfer_Settings() )->bytes();
		$id     = $input['transfer_id'] ?? '';
		switch ( $action ) {
			case 'limits':
				return $policy;
			case 'begin':
				return $store->begin(
					'filesystem_input',
					$input['total_bytes'] ?? null,
					$input['sha256'] ?? null,
					$policy['file'],
					$policy['chunk'],
					$policy['sessions'],
					$policy['ttl'],
					$policy['active']
				);
			case 'append':
				$encoded = $input['base64'] ?? null;
				if ( ! is_string( $encoded ) || strlen( $encoded ) > $policy['request'] ) {
					return new WP_Error( 'filesystem_transfer_invalid', 'A bounded canonical Base64 chunk is required.' );
				}
				$bytes = base64_decode( $encoded, true );
				if ( ! is_string( $bytes ) || base64_encode( $bytes ) !== $encoded ) {
					return new WP_Error( 'filesystem_transfer_invalid', 'A canonical Base64 chunk is required.' );
				}
				return $store->append( $id, $input['offset'] ?? null, $bytes );
			case 'verify':
				return $store->verify( $id );
			case 'status':
				return $store->status( $id );
			case 'release':
				$released = $store->cancel( $id );
				return is_wp_error( $released ) ? $released : array( 'released' => (bool) $released );
			case 'read':
				return $store->read_administration( $id, $input['offset'] ?? null, $input['max_bytes'] ?? null );
			case 'export_begin':
				return $this->export_begin( $store, $input, $policy );
			case 'export_step':
				return $this->export_step( $store, $input, $policy );
		}
		return new WP_Error( 'filesystem_transfer_invalid', 'Select a supported filesystem transfer action.' );
	}

	private function export_begin( Private_Transfer_Session $store, array $input, array $policy ) {
		$path = $input['components'] ?? ( $input['path'] ?? '' );
		$hash = $this->fs->hash( $input['root_id'], $path, 'filesystem', $input['expected_identity'] ?? null );
		if ( is_wp_error( $hash ) ) {
			return $hash;
		}
		$lease = $store->begin(
			'filesystem_export',
			$hash['size'],
			$hash['sha256'],
			$policy['file'],
			$policy['chunk'],
			$policy['sessions'],
			$policy['ttl'],
			$policy['active']
		);
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		$saved = $store->checkpoint(
			$lease['id'],
			array(
				'export' => array(
					'components' => $hash['components'],
					'identity'   => $hash['identity'],
					'sha256'     => $hash['sha256'],
					'size'       => $hash['size'],
				),
			),
			0
		);
		if ( is_wp_error( $saved ) ) {
			$store->discard_administration_creation( $lease['id'] );
			return $saved;
		}
		return $lease;
	}

	private function export_step( Private_Transfer_Session $store, array $input, array $policy ) {
		$id     = $input['transfer_id'] ?? '';
		$status = $store->status( $id );
		if ( is_wp_error( $status ) || 'verified' === ( $status['state'] ?? '' ) ) {
			return $status;
		}
		$checkpoint = $store->read_checkpoint( $id );
		if ( is_wp_error( $checkpoint ) || empty( $checkpoint['state']['export'] ) ) {
			return is_wp_error( $checkpoint ) ? $checkpoint : new WP_Error( 'filesystem_transfer_invalid', 'This is not an export continuation.' );
		}
		$source = $checkpoint['state']['export'];
		if ( $status['received'] === $source['size'] ) {
			return $store->verify( $id );
		}
		$window = $this->fs->read_window(
			$input['root_id'],
			$source['components'],
			'filesystem',
			$source['identity'],
			$status['received'],
			min( $policy['chunk'], max( 1, intdiv( Bounded_Payload::response_limit(), 3 ) ) )
		);
		return is_wp_error( $window ) ? $window : $store->append( $id, $status['received'], $window['bytes'] );
	}
}
