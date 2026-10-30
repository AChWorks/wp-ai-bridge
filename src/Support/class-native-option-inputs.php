<?php
/**
 * Inert, purpose-bound native Options write inputs.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/**
 * Preparation never calls an option target getter or setter. Verification is
 * byte integrity only; every native consumer must strictly decode the value.
 */
final class Native_Option_Inputs {
	/** @var Permissions */
	private $permissions;

	/** @var string */
	private $scope;

	/** @var Private_Transfer_Session Fixed native-write input store. */
	private $store;

	/** @param Permissions $permissions Current delegation. @param string $scope Site or network. */
	public function __construct( Permissions $permissions, string $scope ) {
		$this->permissions = $permissions;
		$this->scope       = $scope;
		$this->store       = Private_Transfer_Session::for_native_options_write( $permissions, $scope );
	}

	/** @param int $total_bytes Exact opaque bytes. @param string $sha256 Expected digest. @return array|WP_Error */
	public function begin( $total_bytes, $sha256 ) {
		return $this->run(
			function ( &$created_id ) use ( $total_bytes, $sha256 ) {
				$policy  = ( new Transfer_Settings() )->bytes();
				$chunk   = min( $policy['chunk'], $policy['file'], max( 1, intdiv( max( 1, $policy['request'] - 4096 ), 4 ) * 3 ) );
				$receipt = $this->store->begin(
					'native_' . $this->scope . '_settings_write',
					$total_bytes,
					$sha256,
					$policy['file'],
					$chunk,
					$policy['sessions'],
					$policy['ttl'],
					$policy['active']
				);
				if ( is_wp_error( $receipt ) ) {
					return $receipt;
				}
				$created_id = $receipt['id'];
				return $this->receipt( $receipt );
			},
			true,
			true
		);
	}

	/** @param string $id Input ID. @param int $offset Exact offset. @param string $base64 Canonical bytes. @return array|WP_Error */
	public function append( $id, $offset, $base64 ) {
		return $this->run(
			function () use ( $id, $offset, $base64 ) {
				$policy = ( new Transfer_Settings() )->bytes();
				if ( ! is_string( $base64 ) || '' === $base64 ||
					strlen( $base64 ) > max( 0, $policy['request'] - 4096 ) ) {
					return new WP_Error( 'transfer_message_budget', 'The input chunk exceeds the current request byte policy.' );
				}
				$bytes = base64_decode( $base64, true );
				if ( ! is_string( $bytes ) || base64_encode( $bytes ) !== $base64 ) {
					return new WP_Error( 'transfer_invalid', 'The input chunk must contain canonical Base64 bytes.' );
				}
				if ( strlen( $bytes ) > $policy['chunk'] ) {
					return new WP_Error( 'transfer_message_budget', 'The input chunk exceeds the current chunk byte policy.' );
				}
				$receipt = $this->store->append( $id, $offset, $bytes );
				return is_wp_error( $receipt ) ? $receipt : $this->receipt( $receipt );
			}
		);
	}

	/** @param string $id Input ID. @return array|WP_Error Integrity verification, not semantic decoding. */
	public function verify( $id ) {
		return $this->run(
			function () use ( $id ) {
				$receipt = $this->store->verify( $id );
				return is_wp_error( $receipt ) ? $receipt : $this->receipt( $receipt );
			}
		);
	}

	/** @param string $id Input ID. @return array|WP_Error */
	public function status( $id ) {
		return $this->run(
			function () use ( $id ) {
				$receipt = $this->store->status( $id );
				return is_wp_error( $receipt ) ? $receipt : $this->receipt( $receipt );
			}
		);
	}

	/** @param string $id Input ID. @return array|WP_Error */
	public function release( $id ) {
		return $this->run(
			function () use ( $id ) {
				$receipt = $this->store->status( $id );
				if ( is_wp_error( $receipt ) ) {
					return $receipt;
				}
				$released = $this->store->cancel( $id );
				if ( is_wp_error( $released ) ) {
					return $released;
				}
				$result             = $this->receipt( $receipt );
				$result['state']    = 'released';
				$result['released'] = true;
				return $result;
			}
		);
	}

	/**
	 * Internal cleanup capability for this facade's own newly created input.
	 * Original store proof, not current authority, permits only its deletion.
	 *
	 * @param string $id A lease created by this facade instance.
	 * @return bool|WP_Error True only after confirmed cleanup.
	 */
	public function discard_created( $id ) {
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $id ) {
					return $this->store->discard_native_option_creation( $id );
				}
			);
		} catch ( \Throwable $error ) {
			return new WP_Error( 'transfer_cleanup_uncertain', 'Failed private input creation requires cleanup.' );
		}
	}

	/**
	 * Materialize and strictly decode one complete immutable verified input.
	 * INTERNAL ONLY. No private path or callback escapes into the native setter.
	 *
	 * @param string $id Input ID.
	 * @param string $expected_sha256 Exact caller-bound complete document digest.
	 * @param int    $expected_total Exact caller-bound complete document bytes.
	 * @param int    $max_bytes Finite processing admission, independent of request size.
	 * @param int    $memory_ceiling Absolute PHP allocation ceiling, or zero if unknown.
	 * @return array|WP_Error Internal {value,json,bytes,sha256}.
	 */
	public function value( $id, $expected_sha256, $expected_total, $max_bytes, $memory_ceiling = 0 ) {
		return $this->run(
			function () use ( $id, $expected_sha256, $expected_total, $max_bytes, $memory_ceiling ) {
				if ( ! is_string( $expected_sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 ) ||
					! is_int( $expected_total ) || $expected_total < 0 ) {
					return new WP_Error( 'transfer_invalid', 'The expected complete input identity is invalid.' );
				}
				if ( ! is_int( $max_bytes ) || $max_bytes < 1 || ! is_int( $memory_ceiling ) || $memory_ceiling < 0 ) {
					return $this->budget();
				}
				$policy    = ( new Transfer_Settings() )->bytes();
				$admission = min( $max_bytes, $policy['file'] );
				$memory    = Operation_Content_Limits::effective_memory_bytes();
				if ( $memory > 0 ) {
					$free           = max( 0, $memory - memory_get_usage( true ) );
					$admission      = min( $admission, intdiv( $free, 8 ) );
					$derived        = $memory - intdiv( $free, 2 );
					$memory_ceiling = $memory_ceiling > 0 ? min( $memory_ceiling, $derived ) : $derived;
				}
				if ( $expected_total > $admission || ! $this->memory_fits( $memory_ceiling, 4096 ) ) {
					return $this->budget();
				}
				$receipt = $this->store->status( $id );
				if ( is_wp_error( $receipt ) ) {
					return $receipt;
				}
				if ( ! $this->matches( $receipt, $expected_sha256, $expected_total ) ) {
					return new WP_Error( 'transfer_source_changed', 'A complete verified input with the exact expected identity is required.' );
				}
				$chunk    = min( $policy['chunk'], $receipt['chunk'], max( 1, $admission ) );
				$document = '';
				$offset   = 0;
				while ( $offset < $expected_total ) {
					$wanted = min( $chunk, $expected_total - $offset );
					if ( $wanted > intdiv( PHP_INT_MAX - strlen( $document ) - 4096, 6 ) ||
						! $this->memory_fits( $memory_ceiling, strlen( $document ) + $wanted * 6 + 4096 ) ) {
						return $this->budget();
					}
					$window = $this->store->read_native_options( $id, $offset, $wanted );
					if ( is_wp_error( $window ) ) {
						return $window;
					}
					$bytes = base64_decode( $window['base64'], true );
					if ( ! is_string( $bytes ) || strlen( $bytes ) !== $wanted ||
						base64_encode( $bytes ) !== $window['base64'] ||
						$window['offset'] !== $offset || $window['next_offset'] !== $offset + $wanted ||
						$window['total'] !== $expected_total || ! hash_equals( $expected_sha256, $window['sha256'] ) ||
						! hash_equals( hash( 'sha256', $bytes ), $window['chunk_sha256'] ) ||
						( $offset + $wanted === $expected_total ) !== $window['complete'] ) {
						return new WP_Error( 'transfer_source_changed', 'The private input bytes changed while being materialized.' );
					}
					$document .= $bytes;
					$offset   += $wanted;
				}
				if ( strlen( $document ) !== $expected_total || ! hash_equals( $expected_sha256, hash( 'sha256', $document ) ) ) {
					return new WP_Error( 'transfer_source_changed', 'The complete private input digest does not match.' );
				}
				$decoded = Native_Option_Value::decode( $document, $admission, $memory_ceiling );
				if ( is_wp_error( $decoded ) ) {
					return $decoded;
				}
				// Catch cancellation or identity changes during assembly/decoding.
				// Nothing remains locked or pending across later native callbacks.
				$final = $this->store->status( $id );
				if ( is_wp_error( $final ) ) {
					return $final;
				}
				return $this->matches( $final, $expected_sha256, $expected_total )
					? $decoded
					: new WP_Error( 'transfer_source_changed', 'The verified private input is no longer available.' );
			},
			false
		);
	}

	/** @param array $receipt Store identity. @param string $sha256 Expected digest. @param int $total Exact bytes. @return bool */
	private function matches( array $receipt, $sha256, $total ) {
		return 'verified' === $receipt['state'] && $receipt['total'] === $total &&
			$receipt['received'] === $total && hash_equals( $sha256, $receipt['sha256'] );
	}

	/** @param int $ceiling Absolute memory ceiling. @param int $reserve Upcoming allocation. @return bool */
	private function memory_fits( $ceiling, $reserve ) {
		return 0 === $ceiling || ( $reserve >= 0 && $reserve <= $ceiling - memory_get_usage( true ) );
	}

	/** @return array Current original principal and source identity. */
	private function binding() {
		return array(
			'blog'    => (int) get_current_blog_id(),
			'network' => (int) get_current_network_id(),
			'user'    => (int) get_current_user_id(),
			'client'  => OAuth_Server::authenticated_mcp_client_id(),
		);
	}

	/** @param array $origin Original call context. @return bool */
	private function authorized( array $origin ) {
		return $origin === $this->binding() &&
			( new Native_Option_Access( $this->permissions ) )->allowed_write( $this->scope ) &&
			$origin === $this->binding();
	}

	/** @param callable $operation Preparation callback. @param bool $public_output Bound receipt output. @param bool $creating Cleanup on failure. @return array|WP_Error */
	private function run( $operation, $public_output = true, $creating = false ) {
		$created_id = null;
		try {
			$result = Hook_Dispatch_Recovery::run(
				function () use ( $operation, $public_output, &$created_id ) {
					$origin = $this->binding();
					if ( ! $this->authorized( $origin ) ) {
						return $this->denied();
					}
					$limit  = $public_output ? Bounded_Payload::response_limit() : 0;
					$result = $operation( $created_id );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					if ( $public_output ) {
						$projected = array_merge(
							$result,
							array(
								'action'     => 'input_release',
								'scope'      => $this->scope,
								'blog_id'    => $origin['blog'],
								'network_id' => $origin['network'],
							)
						);
						if ( Bounded_Payload::projected_mcp_bytes( $projected ) > $limit ) {
							return new WP_Error( 'transfer_message_budget', 'The input receipt exceeds the current response byte policy.' );
						}
					}
					return $this->authorized( $origin ) ? $result : $this->denied();
				}
			);
		} catch ( \Throwable $error ) {
			$result = new WP_Error( 'native_option_input_failed', 'The private native option input operation could not complete.' );
		}
		if ( $creating && is_wp_error( $result ) && is_string( $created_id ) ) {
			try {
				$cleanup = Hook_Dispatch_Recovery::run(
					function () use ( $created_id ) {
						return $this->store->discard_native_option_creation( $created_id );
					}
				);
			} catch ( \Throwable $error ) {
				$cleanup = false;
			}
			if ( true !== $cleanup ) {
				return new WP_Error( 'transfer_cleanup_uncertain', 'Failed private input creation requires cleanup.' );
			}
		}
		return $result;
	}

	/** @param array $stored Existing private receipt. @return array */
	private function receipt( array $stored ) {
		return array(
			'transfer_id'    => $stored['id'],
			'total_bytes'    => $stored['total'],
			'sha256'         => $stored['sha256'],
			'received_bytes' => $stored['received'],
			'chunk_bytes'    => $stored['chunk'],
			'expires_at'     => $stored['expires'],
			'state'          => $stored['state'],
		);
	}

	/** @return WP_Error */
	private function denied() {
		return new WP_Error( 'native_option_input_denied', 'Current native write authorization and unchanged original context are required.' );
	}

	/** @return WP_Error */
	private function budget() {
		return new WP_Error( 'native_option_value_budget', 'The complete input exceeds the current file or PHP processing allowance.' );
	}
}
