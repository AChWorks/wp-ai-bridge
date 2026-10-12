<?php
/**
 * Native WordPress content consumer for verified multipart UTF-8 text.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Transfer_Session;
use WP_AI_Bridge\Support\Native_Content_CAS;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Separates authorized WordPress effects from private, lossless byte staging.
 * Content is committed at most once and only with the existing content-upsert
 * permission, native eligibility and optimistic state checks.
 */
final class Transfer_Content_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @var Private_Transfer_Session */
	private $store;

	/** @var Content_Abilities */
	private $content;

	/** @param Permissions $permissions Bridge delegation. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
		$this->store       = new Private_Transfer_Session( $permissions );
		$this->content     = new Content_Abilities( $permissions, new Mutation_Log() );
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/transfer-content-commit',
			array(
				'label'               => __( 'Commit Verified Text to WordPress Content', 'wp-ai-bridge' ),
				'description'         => __( 'Creates a draft or updates one editable WordPress post using a verified private text transfer. Updates require an exact current content identity. A claimed effect is never blindly retried.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'action'                => array(
							'type' => 'string',
							'enum' => array( 'create', 'update' ),
						),
						'transfer_id'           => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
						'post_id'               => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'post_type'             => array( 'type' => 'string' ),
						'title'                 => array( 'type' => 'string' ),
						'status'                => array( 'type' => 'string' ),
						'expected_modified_gmt' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'expected_state_hash'   => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
					),
					'required'             => array( 'action', 'transfer_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'transfer_id'    => array( 'type' => 'string' ),
						'state'          => array( 'type' => 'string' ),
						'post_id'        => array( 'type' => 'integer' ),
						'content_bytes'  => array( 'type' => 'integer' ),
						'content_sha256' => array( 'type' => 'string' ),
						'modified_gmt'   => array( 'type' => 'string' ),
						'state_hash'     => array( 'type' => 'string' ),
					),
					'required'             => array( 'transfer_id', 'state', 'post_id', 'content_bytes', 'content_sha256', 'modified_gmt', 'state_hash' ),
					'additionalProperties' => false,
				),
				'permission_callback' => array( $this, 'can_commit' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @param mixed $input Source + exact target intent. @return bool */
	public function can_commit( $input = array() ) {
		if ( get_current_user_id() < 1 ||
			'' === OAuth_Server::authenticated_mcp_client_id() ||
			! $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'edit_posts' ) ||
			! is_array( $input ) ) {
			return false;
		}
		$action = $input['action'] ?? '';
		if ( 'create' === $action ) {
			return $this->content->can_upsert(
				array(
					'action'    => 'create',
					'post_type' => $input['post_type'] ?? '',
					'status'    => $input['status'] ?? 'draft',
				)
			);
		}
		if ( 'update' === $action ) {
			return $this->content->can_upsert(
				array(
					'action' => 'update',
					'id'     => $input['post_id'] ?? 0,
				)
			);
		}
		return false;
	}

	/**
	 * @param mixed $input Fully validated single WordPress post intent.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input ) {
		if ( ! is_array( $input ) || ! isset( $input['action'], $input['transfer_id'] ) ||
			! is_string( $input['transfer_id'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['transfer_id'] ) ) {
			return $this->invalid();
		}
		$action = $input['action'];
		if ( 'create' === $action ) {
			if ( array_diff( array_keys( $input ), array( 'action', 'transfer_id', 'post_type', 'title', 'status' ) ) ||
				! isset( $input['post_type'], $input['title'] ) ||
				! is_string( $input['post_type'] ) || ! is_string( $input['title'] ) ||
				( isset( $input['status'] ) && ( ! is_string( $input['status'] ) || 'publish' === $input['status'] && ! $this->can_commit( $input ) ) ) ) {
				return $this->invalid();
			}
		} elseif ( 'update' === $action ) {
			if ( 5 !== count( $input ) || ! isset( $input['post_id'], $input['expected_modified_gmt'], $input['expected_state_hash'] ) ||
				! is_int( $input['post_id'] ) || $input['post_id'] < 1 ||
				! is_string( $input['expected_modified_gmt'] ) ||
				! is_string( $input['expected_state_hash'] ) ||
				1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['expected_state_hash'] ) ) {
				return $this->invalid();
			}
		} else {
			return $this->invalid();
		}
		if ( ! $this->can_commit( $input ) ) {
			return new WP_Error( 'transfer_content_denied', __( 'Current OAuth client, Builder Write and WordPress content editing authority are required.', 'wp-ai-bridge' ) );
		}
		$lease = $this->store->verified_private_source( $input['transfer_id'], 'text' );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		// WordPress's native post APIs require one in-memory complete string.
		// Detect an inadequate actual PHP memory budget before claiming the
		// irreversible effect; there is NO independent Bridge text size cap.
		$memory = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) : -1;
		if ( is_int( $memory ) && $memory > 0 &&
			$lease['bytes'] > intdiv( max( 0, $memory - memory_get_usage( true ) ), 4 ) ) {
			return new WP_Error( 'transfer_content_host_memory', __( 'The current PHP memory limit cannot safely materialize this WordPress content update. Increase host memory or use a suitable native provider.', 'wp-ai-bridge' ) );
		}
		$body = file_get_contents( $lease['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Audited exact private transfer source; host memory checked before materialization.
		if ( ! is_string( $body ) || strlen( $body ) !== $lease['bytes'] ||
			! hash_equals( $lease['sha256'], hash( 'sha256', $body ) ) ) {
			return new WP_Error( 'transfer_source_changed', __( 'The verified text transfer changed while preparing its WordPress consumer.', 'wp-ai-bridge' ) );
		}
		if ( 1 !== preg_match( '//u', $body ) || false !== strpos( $body, "\0" ) ) {
			return new WP_Error( 'transfer_content_encoding', __( 'WordPress content requires complete UTF-8 text without NUL bytes; the private transfer remains unchanged.', 'wp-ai-bridge' ) );
		}
		$args = array(
			'action'  => $action,
			'content' => wp_slash( $body ),
		);
		if ( 'create' === $action ) {
			$args['post_type'] = $input['post_type'];
			$args['title']     = wp_slash( $input['title'] );
			$args['status']    = $input['status'] ?? 'draft';
		} else {
			$args['id']                    = $input['post_id'];
			$args['expected_modified_gmt'] = $input['expected_modified_gmt'];
			$args['expected_state_hash']   = $input['expected_state_hash'];
			// Check before one-way claim; regular content-upsert repeats the
			// precondition directly before the native WordPress write.
			$preflight = $this->content->check_expected_identity(
				get_post( $args['id'] ),
				$args['expected_modified_gmt'],
				$args['expected_state_hash']
			);
			if ( is_wp_error( $preflight ) ) {
				return $preflight;
			}
		}
		// Last no-effect authorization check before the effect claim.
		if ( ! $this->can_commit( $input ) ) {
			return new WP_Error( 'transfer_content_denied', __( 'WordPress content authority changed before the transfer could be committed.', 'wp-ai-bridge' ) );
		}
		if ( 'update' === $action ) {
			$result = $this->native_cas_update( $input, $args );
		} else {
			$claim = $this->store->claim_content( $input['transfer_id'], 'create', 0 );
			if ( is_wp_error( $claim ) ) {
				return $claim;
			}
			try {
				$result = $this->content->upsert( $args );
			} catch ( \Throwable $error ) {
				return $this->uncertain();
			}
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		try {
			if ( ! is_array( $result ) || ! isset( $result['id'] ) ) {
				return $this->uncertain();
			}
			$post_id = (int) $result['id'];
			$receipt = $this->store->finish_content( $input['transfer_id'], $post_id );
			if ( is_wp_error( $receipt ) ) {
				return $receipt;
			}
			return array(
				'transfer_id'    => $input['transfer_id'],
				'state'          => $receipt['state'],
				'post_id'        => $post_id,
				'content_bytes'  => $receipt['total'],
				'content_sha256' => $receipt['sha256'],
				'modified_gmt'   => (string) ( $result['modified_gmt'] ?? '' ),
				'state_hash'     => (string) ( $result['state_hash'] ?? '' ),
			);
		} catch ( \Throwable $error ) {
			return $this->uncertain();
		}
	}

	/**
	 * One conditional native WordPress update, not merely a pre-write check.
	 * The physical post and metadata locks cover both the last state check
	 * and the Core writer; the claim fence remains one-way on any uncertainty.
	 *
	 * @param array<string,mixed> $input Authorized intended target identity.
	 * @param array<string,mixed> $args Native WordPress content arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	private function native_cas_update( $input, $args ) {
		$cas = new Native_Content_CAS();
		return $cas->with_locked_post(
			$input['post_id'],
			function () use ( $input, $args ) {
				$check = $this->content->check_expected_identity(
					get_post( $input['post_id'] ),
					$input['expected_modified_gmt'],
					$input['expected_state_hash']
				);
				if ( is_wp_error( $check ) ) {
					return $check; // No lease claim or Core write.
				}
				if ( ! $this->can_commit( $input ) ) {
					return new WP_Error( 'transfer_content_denied', 'Native editing authority changed before the conditional write.' );
				}
				$claim = $this->store->claim_content(
					$input['transfer_id'],
					'update',
					$input['post_id']
				);
				if ( is_wp_error( $claim ) ) {
					return $claim;
				}
				$result = $this->content->upsert( $args );
				if ( is_wp_error( $result ) ) {
					// The original content adapter raises this particular
					// conflict strictly before wp_update_post() is invoked.
					if ( 'stale_content_conflict' === $result->get_error_code() ) {
						$known = $this->store->record_known_no_effect( $input['transfer_id'] );
						return is_wp_error( $known ) ? $known : $result;
					}
					return $this->uncertain();
				}
				return $result;
			}
		);
	}

	/** @return WP_Error */
	private function invalid() {
		return new WP_Error( 'transfer_content_invalid', __( 'A valid text lease and exact, authorized WordPress content target are required.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function uncertain() {
		return new WP_Error( 'transfer_outcome_unknown', __( 'The WordPress text effect may have occurred. Inspect the exact post and transfer receipt; do not automatically retry.', 'wp-ai-bridge' ) );
	}
}
