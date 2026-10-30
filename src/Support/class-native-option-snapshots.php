<?php
/**
 * Native Options immutable snapshots over the existing private byte store.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/** No native getter, incoming serialized value or filesystem path is accepted. */
final class Native_Option_Snapshots {
	/** @var Permissions */
	private $permissions;

	/** @var string */
	private $scope;

	/** @var Private_Transfer_Session Fixed settings-only authority. */
	private $store;

	/** @param Permissions $permissions Native consent. @param string $scope Site or network. */
	public function __construct( Permissions $permissions, string $scope ) {
		$this->permissions = $permissions;
		$this->scope       = $scope;
		$this->store       = Private_Transfer_Session::for_native_options( $permissions, $scope );
	}

	/** @param string $encoded_json Complete encoder document. @return array<string,mixed>|WP_Error */
	public function create( string $encoded_json ) {
		return $this->run(
			function ( &$created_id ) use ( $encoded_json ) {
				$policy  = ( new Transfer_Settings() )->bytes();
				$receipt = $this->store->stage_encoded_settings( $encoded_json, $policy );
				if ( is_wp_error( $receipt ) ) {
					return $receipt;
				}
				$created_id = $receipt['id'];
				return $this->summary( $receipt );
			},
			true
		);
	}

	/**
	 * Read an exact continuation, adapting only its byte count to current
	 * operator limits and the duplicated official MCP result envelope.
	 *
	 * @param string   $id Opaque native snapshot ID.
	 * @param int      $offset Exact byte offset.
	 * @param int|null $optional_length Requested upper bound, not a promise.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read( $id, $offset, $optional_length = null ) {
		return $this->run(
			function ( &$created_id, $response_limit, $origin ) use ( $id, $offset, $optional_length ) {
				if ( ! is_int( $offset ) || $offset < 0 ||
					( null !== $optional_length && ( ! is_int( $optional_length ) || $optional_length < 1 ) ) ) {
					return new WP_Error( 'transfer_invalid', 'Invalid private snapshot offset or length.' );
				}
				$policy  = ( new Transfer_Settings() )->bytes();
				$receipt = $this->store->status( $id );
				if ( is_wp_error( $receipt ) ) {
					return $receipt;
				}
				$high = min(
					$policy['chunk'],
					$receipt['chunk'],
					max( 1, intdiv( max( 1, $policy['request'] - 4096 ), 4 ) * 3 ),
					$response_limit,
					null === $optional_length ? PHP_INT_MAX : $optional_length
				);
				// One bounded store read per public window. Fitting prefixes is
				// local CPU/memory work, not repeated storage/authentication I/O.
				$window = $this->store->read_native_options( $id, $offset, $high );
				if ( is_wp_error( $window ) ) {
					return $window;
				}
				$bytes = base64_decode( $window['base64'], true );
				if ( ! is_string( $bytes ) ) {
					return new WP_Error( 'transfer_storage_uncertain', 'Private snapshot window encoding is invalid.' );
				}
				$high = max( 1, strlen( $bytes ) );
				$low  = 1;
				$best = null;
				$size = 0;
				while ( $low <= $high ) {
					$middle    = $low + intdiv( $high - $low, 2 );
					$wanted    = min( $middle, strlen( $bytes ) );
					$candidate = array(
						'transfer_id'  => $window['id'],
						'offset'       => $window['offset'],
						'next_offset'  => $offset + $wanted,
						'complete'     => $offset + $wanted === $window['total'],
						'base64'       => base64_encode( substr( $bytes, 0, $wanted ) ),
						// Fixed-width digest placeholder while sizing. Hash the
						// selected byte prefix once after its length is known.
						'chunk_sha256' => $window['chunk_sha256'],
						'total_bytes'  => $window['total'],
						'sha256'       => $window['sha256'],
					);
					if ( $this->fits( $candidate, $response_limit, $origin ) ) {
						$best = $candidate;
						$size = $wanted;
						$low  = $middle + 1;
					} else {
						$high = $middle - 1;
					}
				}
				if ( ! is_array( $best ) ||
					( $offset < $receipt['total'] && $best['next_offset'] <= $offset ) ) {
					return new WP_Error( 'transfer_message_budget', 'The private snapshot window cannot fit the current MCP response budget.' );
				}
				$best['chunk_sha256'] = hash( 'sha256', substr( $bytes, 0, $size ) );
				return $best;
			}
		);
	}

	/** @param string $id Opaque snapshot ID. @return array<string,mixed>|WP_Error */
	public function status( $id ) {
		return $this->run(
			function () use ( $id ) {
				$receipt = $this->store->status( $id );
				return is_wp_error( $receipt ) ? $receipt : $this->summary( $receipt );
			}
		);
	}

	/** @param string $id Opaque snapshot ID. @return array<string,mixed>|WP_Error */
	public function release( $id ) {
		return $this->run(
			function () use ( $id ) {
				$result = $this->store->cancel( $id );
				return is_wp_error( $result ) ? $result : array(
					'transfer_id' => $id,
					'released'    => true,
				);
			}
		);
	}

	/**
	 * Internal cleanup capability for this facade's own newly created bytes.
	 * It never grants continuation access or cancellation of arbitrary IDs.
	 *
	 * @param string $id A lease created by this facade instance.
	 * @return bool|WP_Error
	 */
	public function discard_created( $id ) {
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $id ) {
					return $this->store->discard_native_option_creation( $id );
				}
			);
		} catch ( \Throwable $error ) {
			return new WP_Error( 'transfer_cleanup_uncertain', 'Failed private settings creation requires cleanup.' );
		}
	}

	/** @return array<string,mixed> Exact call context, including optional network. */
	private function binding() {
		return array(
			'blog'    => (int) get_current_blog_id(),
			'user'    => (int) get_current_user_id(),
			'client'  => OAuth_Server::authenticated_mcp_client_id(),
			'network' => (int) get_current_network_id(),
		);
	}

	/** @param array<string,mixed> $origin Entry context. @return bool */
	private function authorized( $origin ) {
		return ( new Native_Option_Access( $this->permissions ) )->allowed( $this->scope ) &&
			$origin === $this->binding();
	}

	/**
	 * Every hook-capable policy, authorization and readback is recoverable.
	 * No raw callback exception can enter a tool error.
	 *
	 * @param callable $operation Synchronous operation.
	 * @param bool     $creating Cleanup this operation's bytes on output failure.
	 * @return array<string,mixed>|WP_Error
	 */
	private function run( $operation, $creating = false ) {
		$created_id = null;
		$success    = false;
		try {
			$result  = Hook_Dispatch_Recovery::run(
				function () use ( $operation, &$created_id ) {
					$origin = $this->binding();
					if ( ! $this->authorized( $origin ) ) {
						return $this->denied();
					}
					$response_limit = Bounded_Payload::response_limit();
					$result         = $operation( $created_id, $response_limit, $origin );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					if ( ! $this->fits( $result, $response_limit, $origin ) ) {
						return new WP_Error( 'transfer_message_budget', 'The private snapshot result exceeds the current MCP response budget.' );
					}
					return $this->authorized( $origin ) ? $result : $this->denied();
				}
			);
			$success = ! is_wp_error( $result );
		} catch ( \Throwable $error ) {
			$result = new WP_Error( 'native_option_snapshot_failed', 'The private native settings snapshot operation could not complete.' );
		}
		if ( $creating && ! $success && is_string( $created_id ) ) {
			$cleanup = $this->discard_created( $created_id );
			if ( is_wp_error( $cleanup ) ) {
				return $cleanup;
			}
		}
		return $result;
	}

	/** @param array<string,mixed> $result Snapshot result. @return bool */
	private function fits( $result, $response_limit, $origin ) {
		// Reserve every public Ability wrapper field from the captured call
		// context. The Ability also checks its final response shape.
		$projected = array_merge(
			$result,
			array(
				'action'     => 'snapshot_status',
				'scope'      => $this->scope,
				'blog_id'    => $origin['blog'],
				'network_id' => $origin['network'],
			)
		);
		return Bounded_Payload::projected_mcp_bytes( $projected ) <= $response_limit;
	}

	/** @param array<string,mixed> $receipt Private store receipt. @return array<string,mixed> */
	private function summary( $receipt ) {
		return array(
			'transfer_id' => $receipt['id'],
			'total_bytes' => $receipt['total'],
			'sha256'      => $receipt['sha256'],
			'expires_at'  => $receipt['expires'],
			'state'       => $receipt['state'],
		);
	}

	/** @return WP_Error */
	private function denied() {
		return new WP_Error( 'native_option_snapshot_denied', 'Current native settings authorization and unchanged principal context are required.' );
	}
}
