<?php
/**
 * Replay-safe WordPress Application Password abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_Error;

/**
 * Registers the Application Password surface with hardened create provenance.
 *
 * Non-create operations delegate to the original fixed-route provider. Create is
 * implemented here so cleanup identity never depends on a replayable public action.
 */
final class Secure_Application_Password_Abilities {
	const MAX_ITEMS = 100;

	/** @var Application_Password_Abilities */
	private $delegate;

	/** @var Mutation_Log */
	private $log;

	/**
	 * Creates the provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Bounded mutation logger.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->delegate = new Application_Password_Abilities( $permissions, $log );
		$this->log      = $log;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/application-passwords-read',
			array(
				'label'               => __( 'Read Application Passwords', 'wp-ai-bridge' ),
				'description'         => __( 'Lists or retrieves bounded WordPress Application Password metadata without exposing stored hashes or reusable credentials.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this->delegate, 'read' ),
				'permission_callback' => array( $this->delegate, 'can_access' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-ai-bridge/application-password-create',
			array(
				'label'               => __( 'Create Application Password', 'wp-ai-bridge' ),
				'description'         => __( 'Creates one WordPress Application Password through Core and returns the generated credential once in this response only.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->create_input_schema(),
				'output_schema'       => $this->create_output_schema(),
				'execute_callback'    => array( $this, 'create' ),
				'permission_callback' => array( $this->delegate, 'can_access' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-ai-bridge/application-password-update',
			array(
				'label'               => __( 'Update Application Password', 'wp-ai-bridge' ),
				'description'         => __( 'Renames one exact WordPress Application Password through the fixed Core REST contract.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->update_input_schema(),
				'output_schema'       => $this->item_schema(),
				'execute_callback'    => array( $this->delegate, 'update' ),
				'permission_callback' => array( $this->delegate, 'can_access' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-ai-bridge/application-password-delete',
			array(
				'label'               => __( 'Revoke Application Password', 'wp-ai-bridge' ),
				'description'         => __( 'Revokes one exact WordPress Application Password through Core.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->item_target_schema(),
				'output_schema'       => $this->delete_output_schema( false ),
				'execute_callback'    => array( $this->delegate, 'delete' ),
				'permission_callback' => array( $this->delegate, 'can_access' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-ai-bridge/application-passwords-delete-all',
			array(
				'label'               => __( 'Revoke All Application Passwords', 'wp-ai-bridge' ),
				'description'         => __( 'Revokes every WordPress Application Password for one exact user only after an explicit revoke-all confirmation token.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->delete_all_input_schema(),
				'output_schema'       => $this->delete_output_schema( true ),
				'execute_callback'    => array( $this->delegate, 'delete_all' ),
				'permission_callback' => array( $this->delegate, 'can_access' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/**
	 * Creates one Application Password while preserving exact cleanup provenance.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create( $input ) {
		$user_id = (int) $input['user_id'];
		$params  = array( 'name' => (string) $input['name'] );
		if ( ! empty( $input['app_id'] ) ) {
			$params['app_id'] = (string) $input['app_id'];
		}

		$permission = $this->preflight_create_permission( $user_id, $params );
		if ( is_wp_error( $permission ) ) {
			return $this->logged_error( $permission, $user_id );
		}

		$baseline = $this->credential_snapshot( $user_id );
		if ( is_wp_error( $baseline ) ) {
			return $this->logged_error( $this->create_recovery_required_error(), $user_id );
		}

		$capture = array(
			'write_count'   => 0,
			'uuid'          => '',
			'password_hash' => '',
			'fingerprint'   => '',
			'ambiguous'     => false,
			'late_count'    => 0,
			'late_uuid'     => '',
			'late_password' => '',
		);

		$result = $this->dispatch_create( $user_id, $params, $capture );
		if ( is_wp_error( $result ) ) {
			return $this->resolve_failed_create( $result, $user_id, $baseline, $capture );
		}

		if ( ! $this->capture_is_complete( $capture ) ) {
			return $this->logged_error( $this->create_recovery_required_error(), $user_id );
		}

		$data = $result['data'];
		if ( ! is_array( $data )
			|| empty( $data['password'] )
			|| ! is_string( $data['password'] )
			|| empty( $data['uuid'] )
			|| ! is_string( $data['uuid'] )
			|| $capture['uuid'] !== $capture['late_uuid']
			|| $capture['uuid'] !== $data['uuid']
			|| $capture['late_password'] !== $data['password'] ) {
			return $this->resolve_invalid_success( $this->invalid_response_error(), $user_id, $baseline, $capture );
		}

		$current = $this->credential_snapshot( $user_id );
		if ( is_wp_error( $current )
			|| ! $this->captured_credential_is_current( $current, $capture )
			|| ! $this->is_expected_success_state( $baseline, $current, $capture ) ) {
			return $this->resolve_invalid_success( $this->create_recovery_required_error(), $user_id, $baseline, $capture );
		}

		$item = $this->normalize_item( $data );
		if ( is_wp_error( $item ) ) {
			return $this->resolve_invalid_success( $item, $user_id, $baseline, $capture );
		}

		$this->log->record( 'wp-ai-bridge/application-password-create', 'user', $user_id, true, '' );
		return array(
			'user_id'  => $user_id,
			'password' => $data['password'],
			'item'     => $item,
		);
	}

	/**
	 * Runs Core's exact create authorization before inspecting credential storage.
	 *
	 * Core's storage reader can backfill UUIDs into legacy Application Password rows,
	 * so even the baseline snapshot must not run until the target-specific native
	 * create permission has succeeded. The real REST dispatch repeats this check.
	 *
	 * @param int                 $user_id Exact target user.
	 * @param array<string,mixed> $params  Fixed create parameters.
	 * @return true|WP_Error
	 */
	private function preflight_create_permission( $user_id, array $params ) {
		$request = $this->build_request( 'POST', $this->collection_route( $user_id ), $params );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		if ( ! class_exists( 'WP_REST_Application_Passwords_Controller' ) ) {
			return new WP_Error( 'application_passwords_rest_unavailable', __( 'The WordPress Application Password REST contract is unavailable.', 'wp-ai-bridge' ) );
		}

		$request->set_param( 'user_id', (int) $user_id );
		$controller = new \WP_REST_Application_Passwords_Controller();
		$permission = $controller->create_item_permissions_check( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		if ( true !== $permission ) {
			return new WP_Error( 'application_passwords_rest_request_failed', __( 'WordPress rejected the Application Password REST request.', 'wp-ai-bridge' ) );
		}

		return true;
	}

	/**
	 * Executes the Core create route with pre-write and late exact-request observers.
	 *
	 * @param int                 $user_id Exact target user.
	 * @param array<string,mixed> $params  Fixed create parameters.
	 * @param array<string,mixed> $capture Request-scoped provenance state.
	 * @return array{data:mixed,headers:array<string,mixed>}|WP_Error
	 */
	private function dispatch_create( $user_id, array $params, array &$capture ) {
		$request = $this->build_request( 'POST', $this->collection_route( $user_id ), $params );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		if ( ! function_exists( 'add_filter' )
			|| ! function_exists( 'remove_filter' )
			|| ! function_exists( 'add_action' )
			|| ! function_exists( 'remove_action' )
			|| ! class_exists( 'WP_Application_Passwords' ) ) {
			return $this->create_recovery_required_error();
		}

		$write_observer = function ( $check, $observed_user_id, $meta_key, $meta_value ) use ( &$capture, $request, $user_id ) {
			if ( (int) $observed_user_id !== (int) $user_id
				|| \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS !== $meta_key
				|| ! $this->is_exact_outer_create_write( $request ) ) {
				return $check;
			}

			++$capture['write_count'];
			if ( 1 !== (int) $capture['write_count'] || null !== $check || ! is_array( $meta_value ) ) {
				$this->invalidate_capture( $capture );
				return false;
			}

			$current  = $this->credential_snapshot( $user_id );
			$proposed = $this->snapshot_from_items( $meta_value );
			if ( is_wp_error( $current ) || is_wp_error( $proposed ) ) {
				$this->invalidate_capture( $capture );
				return false;
			}

			$candidate = $this->find_exact_append_candidate( $current, $proposed );
			if ( is_wp_error( $candidate ) ) {
				$this->invalidate_capture( $capture );
				return false;
			}

			$capture['uuid']          = $candidate['uuid'];
			$capture['password_hash'] = $candidate['password_hash'];
			$capture['fingerprint']   = $candidate['fingerprint'];
			return null;
		};

		$late_observer = function ( $item, $observed_request, $creating ) use ( &$capture, $request ) {
			if ( true !== $creating || $observed_request !== $request ) {
				return;
			}
			++$capture['late_count'];
			if ( 1 !== (int) $capture['late_count']
				|| ! is_array( $item )
				|| empty( $item['uuid'] )
				|| ! is_string( $item['uuid'] )
				|| ! $this->valid_uuid_token( $item['uuid'] )
				|| empty( $item['new_password'] )
				|| ! is_string( $item['new_password'] ) ) {
				$capture['ambiguous'] = true;
				return;
			}
			$capture['late_uuid']     = $item['uuid'];
			$capture['late_password'] = $item['new_password'];
		};

		add_filter( 'update_user_metadata', $write_observer, PHP_INT_MAX, 4 );
		add_action( 'rest_after_insert_application_password', $late_observer, PHP_INT_MIN, 3 );
		try {
			try {
				return $this->send_request( $request );
			} catch ( \Throwable $throwable ) {
				return new WP_Error( 'application_passwords_rest_request_failed', __( 'WordPress rejected the Application Password REST request.', 'wp-ai-bridge' ) );
			}
		} finally {
			remove_filter( 'update_user_metadata', $write_observer, PHP_INT_MAX );
			remove_action( 'rest_after_insert_application_password', $late_observer, PHP_INT_MIN );
		}
	}

	/**
	 * Verifies that the observed metadata write is the exact outer Core REST create write.
	 *
	 * @param \WP_REST_Request $request Exact Bridge request object.
	 * @return bool
	 */
	private function is_exact_outer_create_write( $request ) {
		return $this->is_exact_application_password_storage_write(
			$request,
			'create_new_application_password',
			'create_item'
		);
	}

	/**
	 * Binds a metadata write to the exact Core Application Password persistence chain.
	 *
	 * Re-entrant update_user_meta() calls and same-request REST redispatches can run
	 * while the outer lifecycle remains present. The nearest metadata write must be the
	 * direct Core persistence chain, and the full stack must contain exactly one matching
	 * controller operation plus one rest_do_request() for the exact request object.
	 *
	 * @param \WP_REST_Request $request           Exact Bridge request object.
	 * @param string           $storage_operation Core storage operation method.
	 * @param string           $rest_operation    Core REST controller method.
	 * @return bool
	 */
	private function is_exact_application_password_storage_write( $request, $storage_operation, $rest_operation ) {
		$trace                     = debug_backtrace( 0, 64 );
		$nearest_metadata_index    = null;
		$matching_controller_calls = 0;
		$matching_rest_dispatches  = 0;

		foreach ( $trace as $index => $frame ) {
			if ( null === $nearest_metadata_index
				&& isset( $frame['function'] )
				&& 'update_metadata' === $frame['function'] ) {
				$nearest_metadata_index = $index;
			}

			if ( isset( $frame['class'], $frame['function'], $frame['args'][0] )
				&& 'WP_REST_Application_Passwords_Controller' === ltrim( (string) $frame['class'], '\\' )
				&& $rest_operation === $frame['function']
				&& $frame['args'][0] === $request ) {
				++$matching_controller_calls;
			}

			if ( isset( $frame['function'], $frame['args'][0] )
				&& 'rest_do_request' === $frame['function']
				&& $frame['args'][0] === $request ) {
				++$matching_rest_dispatches;
			}
		}

		if ( null === $nearest_metadata_index ) {
			return false;
		}

		$update_user = isset( $trace[ $nearest_metadata_index + 1 ] ) ? $trace[ $nearest_metadata_index + 1 ] : array();
		$set_store   = isset( $trace[ $nearest_metadata_index + 2 ] ) ? $trace[ $nearest_metadata_index + 2 ] : array();
		$operation   = isset( $trace[ $nearest_metadata_index + 3 ] ) ? $trace[ $nearest_metadata_index + 3 ] : array();
		$controller  = isset( $trace[ $nearest_metadata_index + 4 ] ) ? $trace[ $nearest_metadata_index + 4 ] : array();

		$direct_chain = isset( $update_user['function'] )
			&& 'update_user_meta' === $update_user['function']
			&& isset( $set_store['class'], $set_store['function'] )
			&& 'WP_Application_Passwords' === ltrim( (string) $set_store['class'], '\\' )
			&& 'set_user_application_passwords' === $set_store['function']
			&& isset( $operation['class'], $operation['function'] )
			&& 'WP_Application_Passwords' === ltrim( (string) $operation['class'], '\\' )
			&& $storage_operation === $operation['function']
			&& isset( $controller['class'], $controller['function'], $controller['args'][0] )
			&& 'WP_REST_Application_Passwords_Controller' === ltrim( (string) $controller['class'], '\\' )
			&& $rest_operation === $controller['function']
			&& $controller['args'][0] === $request;

		return $direct_chain
			&& 1 === $matching_controller_calls
			&& 1 === $matching_rest_dispatches;
	}

	/** @param array<string,mixed> $capture Capture state. @return void */
	private function invalidate_capture( array &$capture ) {
		$capture['ambiguous']     = true;
		$capture['uuid']          = '';
		$capture['password_hash'] = '';
		$capture['fingerprint']   = '';
	}

	/**
	 * Returns a bounded fingerprint snapshot of current Core credential storage.
	 *
	 * @param int $user_id Target user ID.
	 * @return array<string,array<string,string>>|WP_Error
	 */
	private function credential_snapshot( $user_id ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) || ! method_exists( 'WP_Application_Passwords', 'get_user_application_passwords' ) ) {
			return $this->create_recovery_required_error();
		}
		$items = \WP_Application_Passwords::get_user_application_passwords( (int) $user_id );
		return $this->snapshot_from_items( is_array( $items ) ? $items : array() );
	}

	/**
	 * Converts credential items into an exact UUID/hash/full-state fingerprint map.
	 *
	 * @param array<int,mixed> $items Credential items.
	 * @return array<string,array<string,string>>|WP_Error
	 */
	private function snapshot_from_items( array $items ) {
		$snapshot = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item )
				|| empty( $item['uuid'] )
				|| ! is_string( $item['uuid'] )
				|| ! $this->valid_uuid_token( $item['uuid'] )
				|| empty( $item['password'] )
				|| ! is_string( $item['password'] )
				|| isset( $snapshot[ $item['uuid'] ] ) ) {
				return $this->create_recovery_required_error();
			}
			$snapshot[ $item['uuid'] ] = array(
				'password_hash' => $item['password'],
				'fingerprint'   => hash( 'sha256', serialize( $item ) ),
			);
		}
		ksort( $snapshot );
		return $snapshot;
	}

	/**
	 * Requires the proposed write to equal current state plus exactly one new UUID.
	 *
	 * @param array<string,array<string,string>> $current  Current persisted snapshot.
	 * @param array<string,array<string,string>> $proposed Proposed write snapshot.
	 * @return array<string,string>|WP_Error
	 */
	private function find_exact_append_candidate( array $current, array $proposed ) {
		if ( count( $proposed ) !== count( $current ) + 1 ) {
			return $this->create_recovery_required_error();
		}
		foreach ( $current as $uuid => $state ) {
			if ( ! isset( $proposed[ $uuid ] ) || $proposed[ $uuid ] !== $state ) {
				return $this->create_recovery_required_error();
			}
		}
		$new = array_diff_key( $proposed, $current );
		if ( 1 !== count( $new ) ) {
			return $this->create_recovery_required_error();
		}
		$uuid  = (string) array_key_first( $new );
		$state = $new[ $uuid ];
		return array(
			'uuid'          => $uuid,
			'password_hash' => $state['password_hash'],
			'fingerprint'   => $state['fingerprint'],
		);
	}

	/** @param array<string,mixed> $capture Capture state. @return bool */
	private function capture_is_complete( array $capture ) {
		return empty( $capture['ambiguous'] )
			&& 1 === (int) $capture['write_count']
			&& $this->valid_uuid_token( $capture['uuid'] )
			&& '' !== $capture['password_hash']
			&& '' !== $capture['fingerprint']
			&& 1 === (int) $capture['late_count']
			&& $this->valid_uuid_token( $capture['late_uuid'] )
			&& '' !== $capture['late_password'];
	}

	/**
	 * Checks that the captured UUID still names the exact pre-write fingerprint.
	 *
	 * @param array<string,array<string,string>> $snapshot Current snapshot.
	 * @param array<string,mixed>                $capture  Capture state.
	 * @return bool
	 */
	private function captured_credential_is_current( array $snapshot, array $capture ) {
		return isset( $snapshot[ $capture['uuid'] ] )
			&& hash_equals( $capture['password_hash'], $snapshot[ $capture['uuid'] ]['password_hash'] )
			&& hash_equals( $capture['fingerprint'], $snapshot[ $capture['uuid'] ]['fingerprint'] );
	}

	/**
	 * Checks that create changed storage by exactly the captured credential.
	 *
	 * @param array<string,array<string,string>> $baseline Baseline snapshot.
	 * @param array<string,array<string,string>> $current  Current snapshot.
	 * @param array<string,mixed>                $capture  Capture state.
	 * @return bool
	 */
	private function is_expected_success_state( array $baseline, array $current, array $capture ) {
		$expected                     = $baseline;
		$expected[ $capture['uuid'] ] = array(
			'password_hash' => $capture['password_hash'],
			'fingerprint'   => $capture['fingerprint'],
		);
		ksort( $expected );
		return $expected === $current;
	}

	/**
	 * Requires a proposed cleanup write to remove only the exact captured credential.
	 *
	 * @param array<string,array<string,string>> $current  Current persisted snapshot.
	 * @param array<string,array<string,string>> $proposed Proposed persisted snapshot.
	 * @param array<string,mixed>                $capture  Capture state.
	 * @return bool
	 */
	private function is_exact_cleanup_state( array $current, array $proposed, array $capture ) {
		if ( ! $this->captured_credential_is_current( $current, $capture ) ) {
			return false;
		}

		$expected = $current;
		unset( $expected[ $capture['uuid'] ] );
		ksort( $expected );

		return $expected === $proposed;
	}

	/**
	 * Resolves a failed REST create without guessing a cleanup target.
	 *
	 * @param WP_Error            $error    Original bounded REST error.
	 * @param int                 $user_id  Exact user.
	 * @param array<string,mixed> $baseline Baseline snapshot.
	 * @param array<string,mixed> $capture  Capture state.
	 * @return WP_Error
	 */
	private function resolve_failed_create( WP_Error $error, $user_id, array $baseline, array $capture ) {
		if ( ! empty( $capture['ambiguous'] ) ) {
			return $this->logged_error( $this->create_recovery_required_error(), $user_id );
		}

		if ( $this->valid_uuid_token( $capture['uuid'] ) && '' !== $capture['password_hash'] ) {
			$cleanup = $this->cleanup_captured_credential( $user_id, $capture );
			if ( is_wp_error( $cleanup ) ) {
				return $this->logged_error( $cleanup, $user_id );
			}
		}

		$after = $this->credential_snapshot( $user_id );
		if ( is_wp_error( $after ) || $after !== $baseline ) {
			return $this->logged_error( $this->create_recovery_required_error(), $user_id );
		}

		if ( 0 !== (int) $capture['write_count'] && ! $this->valid_uuid_token( $capture['uuid'] ) ) {
			return $this->logged_error( $this->create_recovery_required_error(), $user_id );
		}

		return $this->logged_error( $error, $user_id );
	}

	/**
	 * Cleans an invalid successful response only when exact provenance remains valid.
	 *
	 * @param WP_Error            $error    Error to return when cleanup restores baseline.
	 * @param int                 $user_id  Exact user.
	 * @param array<string,mixed> $baseline Baseline snapshot.
	 * @param array<string,mixed> $capture  Capture state.
	 * @return WP_Error
	 */
	private function resolve_invalid_success( WP_Error $error, $user_id, array $baseline, array $capture ) {
		if ( ! empty( $capture['ambiguous'] )
			|| ! $this->valid_uuid_token( $capture['uuid'] )
			|| '' === $capture['password_hash'] ) {
			return $this->logged_error( $this->create_recovery_required_error(), $user_id );
		}

		$cleanup = $this->cleanup_captured_credential( $user_id, $capture );
		if ( is_wp_error( $cleanup ) ) {
			return $this->logged_error( $cleanup, $user_id );
		}
		$after = $this->credential_snapshot( $user_id );
		if ( is_wp_error( $after ) || $after !== $baseline ) {
			return $this->logged_error( $this->create_recovery_required_error(), $user_id );
		}
		return $this->logged_error( $error, $user_id );
	}

	/**
	 * Revokes only the captured credential when persistence-boundary identity remains exact.
	 *
	 * @param int                 $user_id Exact user.
	 * @param array<string,mixed> $capture Capture state.
	 * @return true|WP_Error
	 */
	private function cleanup_captured_credential( $user_id, array $capture ) {
		$current = $this->credential_snapshot( $user_id );
		if ( is_wp_error( $current ) ) {
			return $this->create_recovery_required_error();
		}
		if ( ! isset( $current[ $capture['uuid'] ] ) ) {
			return true;
		}
		if ( ! $this->captured_credential_is_current( $current, $capture ) ) {
			return $this->create_recovery_required_error();
		}

		$guard = array(
			'write_count' => 0,
			'allowed'     => false,
			'denied'      => false,
		);

		try {
			$result = $this->dispatch_cleanup_delete( $user_id, $capture, $guard );
		} catch ( \Throwable $throwable ) {
			return $this->cleanup_failed_error();
		}
		if ( ! empty( $guard['denied'] ) ) {
			return $this->create_recovery_required_error();
		}
		if ( is_wp_error( $result ) || ! is_array( $result['data'] ) || empty( $result['data']['deleted'] ) ) {
			return $this->cleanup_failed_error();
		}
		if ( 1 !== (int) $guard['write_count'] || empty( $guard['allowed'] ) ) {
			return $this->create_recovery_required_error();
		}

		$after = $this->credential_snapshot( $user_id );
		if ( is_wp_error( $after ) || isset( $after[ $capture['uuid'] ] ) ) {
			return $this->cleanup_failed_error();
		}
		return true;
	}

	/**
	 * Detects callbacks that could still run after a persistence guard at one priority.
	 *
	 * Normal plugin callbacks are registered before the request-scoped Bridge guard.
	 * A callback registered later at the same maximum priority would otherwise get an
	 * interposition window after the decisive check. Production WordPress exposes the
	 * WP_Hook callback order; if the guard cannot be found there, fail closed.
	 *
	 * @param string   $hook_name Hook name.
	 * @param int      $priority  Guard priority.
	 * @param callable $callback  Guard callback.
	 * @return bool
	 */
	private function has_later_filter_callback_at_priority( $hook_name, $priority, $callback ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return false;
		}
		$hook = $wp_filter[ $hook_name ];
		if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
			return true;
		}
		if ( empty( $hook->callbacks[ $priority ] ) || ! is_array( $hook->callbacks[ $priority ] ) ) {
			return true;
		}

		$seen = false;
		foreach ( $hook->callbacks[ $priority ] as $registered ) {
			$registered_callback = is_array( $registered ) && array_key_exists( 'function', $registered ) ? $registered['function'] : null;
			if ( ! $seen ) {
				if ( $registered_callback === $callback ) {
					$seen = true;
				}
				continue;
			}
			return true;
		}

		return ! $seen;
	}
	/**
	 * Dispatches the fixed Core cleanup DELETE with a persistence-boundary guard.
	 *
	 * @param int                 $user_id Exact target user.
	 * @param array<string,mixed> $capture Exact create provenance.
	 * @param array<string,mixed> $guard   Delete guard state.
	 * @return array{data:mixed,headers:array<string,mixed>}|WP_Error
	 */
	private function dispatch_cleanup_delete( $user_id, array $capture, array &$guard ) {
		$request = $this->build_request( 'DELETE', $this->collection_route( $user_id ) . '/' . $capture['uuid'], array() );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		if ( ! function_exists( 'add_filter' )
			|| ! function_exists( 'remove_filter' )
			|| ! class_exists( 'WP_Application_Passwords' ) ) {
			return $this->create_recovery_required_error();
		}

		$write_guard = null;
		$write_guard = function ( $check, $observed_user_id, $meta_key, $meta_value ) use ( &$write_guard, &$guard, $request, $user_id, $capture ) {
			if ( (int) $observed_user_id !== (int) $user_id
				|| \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS !== $meta_key
				|| ! $this->is_exact_application_password_storage_write( $request, 'delete_application_password', 'delete_item' ) ) {
				return $check;
			}

			++$guard['write_count'];
			if ( 1 !== (int) $guard['write_count'] || null !== $check || ! is_array( $meta_value ) ) {
				$guard['denied'] = true;
				return false;
			}

			$current  = $this->credential_snapshot( $user_id );
			$proposed = $this->snapshot_from_items( $meta_value );
			if ( is_wp_error( $current )
				|| is_wp_error( $proposed )
				|| ! $this->is_exact_cleanup_state( $current, $proposed, $capture )
				|| $this->has_later_filter_callback_at_priority( 'update_user_metadata', PHP_INT_MAX, $write_guard ) ) {
				$guard['denied'] = true;
				return false;
			}

			$guard['allowed'] = true;
			return null;
		};

		add_filter( 'update_user_metadata', $write_guard, PHP_INT_MAX, 4 );
		try {
			return $this->send_request( $request );
		} finally {
			remove_filter( 'update_user_metadata', $write_guard, PHP_INT_MAX );
		}
	}

	/** @return string */
	private function collection_route( $user_id ) {
		return '/wp/v2/users/' . (int) $user_id . '/application-passwords';
	}

	/** @return bool */
	private function valid_uuid_token( $uuid ) {
		return is_string( $uuid ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $uuid );
	}

	/**
	 * Dispatches only the fixed Core Application Password route family.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $route  Fixed route.
	 * @param array<string,mixed> $params Parameters.
	 * @return array{data:mixed,headers:array<string,mixed>}|WP_Error
	 */
	private function dispatch( $method, $route, array $params ) {
		$request = $this->build_request( $method, $route, $params );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		return $this->send_request( $request );
	}

	/** @return \WP_REST_Request|WP_Error */
	private function build_request( $method, $route, array $params ) {
		if ( ! class_exists( 'WP_REST_Request' ) || ! function_exists( 'rest_do_request' ) ) {
			return new WP_Error( 'application_passwords_rest_unavailable', __( 'The WordPress Application Password REST contract is unavailable.', 'wp-ai-bridge' ) );
		}
		if ( ! in_array( $method, array( 'GET', 'POST', 'DELETE' ), true )
			|| 1 !== preg_match( '#^/wp/v2/users/[1-9][0-9]*/application-passwords(?:/[A-Za-z0-9_-]{1,64})?$#', $route ) ) {
			return new WP_Error( 'application_passwords_rest_route_denied', __( 'The requested internal REST operation is outside the bounded Application Password contract.', 'wp-ai-bridge' ) );
		}
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Sends one bounded internal REST request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{data:mixed,headers:array<string,mixed>}|WP_Error
	 */
	private function send_request( $request ) {
		try {
			$response = rest_do_request( $request );
		} catch ( \Throwable $throwable ) {
			return new WP_Error( 'application_passwords_rest_request_failed', __( 'WordPress rejected the Application Password REST request.', 'wp-ai-bridge' ) );
		}
		if ( is_wp_error( $response ) ) {
			return $this->normalize_rest_error( $response );
		}
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
			return $this->invalid_response_error();
		}
		if ( method_exists( $response, 'is_error' ) && $response->is_error() ) {
			$error = method_exists( $response, 'as_error' ) ? $response->as_error() : null;
			return $this->normalize_rest_error( $error );
		}
		return array(
			'data'    => $response->get_data(),
			'headers' => method_exists( $response, 'get_headers' ) && is_array( $response->get_headers() ) ? $response->get_headers() : array(),
		);
	}

	/** @param mixed $raw Core response item. @return array<string,mixed>|WP_Error */
	private function normalize_item( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['uuid'] ) || ! is_string( $raw['uuid'] ) || ! isset( $raw['name'] ) ) {
			return $this->invalid_response_error();
		}
		return array(
			'uuid'      => (string) $raw['uuid'],
			'app_id'    => isset( $raw['app_id'] ) && is_string( $raw['app_id'] ) ? $raw['app_id'] : '',
			'name'      => (string) $raw['name'],
			'created'   => isset( $raw['created'] ) && is_string( $raw['created'] ) ? $raw['created'] : '',
			'last_used' => isset( $raw['last_used'] ) && is_string( $raw['last_used'] ) ? $raw['last_used'] : null,
		);
	}

	/**
	 * Normalizes downstream REST failures before they cross the credential boundary.
	 *
	 * Application Password REST callbacks can see plaintext credentials and stored
	 * hashes. Their WP_Error message/data are therefore untrusted for Bridge output.
	 * Preserve only a bounded numeric HTTP status when one is explicitly present.
	 *
	 * @param mixed $error Downstream REST error.
	 * @return WP_Error
	 */
	private function normalize_rest_error( $error ) {
		$status = 0;
		if ( is_wp_error( $error ) && method_exists( $error, 'get_error_data' ) ) {
			$data = $error->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) && is_numeric( $data['status'] ) ) {
				$status = (int) $data['status'];
			}
		}

		$message = __( 'WordPress rejected the Application Password REST request.', 'wp-ai-bridge' );
		if ( $status >= 400 && $status <= 599 ) {
			return new WP_Error( 'application_passwords_rest_request_failed', $message, array( 'status' => $status ) );
		}
		return new WP_Error( 'application_passwords_rest_request_failed', $message );
	}

	/** @return WP_Error */
	private function invalid_response_error() {
		return new WP_Error( 'application_passwords_rest_invalid_response', __( 'WordPress returned an invalid Application Password response.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function create_recovery_required_error() {
		return new WP_Error( 'application_password_create_recovery_required', __( 'WordPress may have created an Application Password, but the Bridge could not verify its exact identity safely. Inspect the target user\'s Application Passwords before retrying.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function cleanup_failed_error() {
		return new WP_Error( 'application_password_create_cleanup_failed', __( 'WordPress created an Application Password, but the Bridge could not safely revoke it after an invalid create response.', 'wp-ai-bridge' ) );
	}

	/** @param WP_Error $error Error. @param int $user_id User. @return WP_Error */
	private function logged_error( WP_Error $error, $user_id ) {
		$this->log->record( 'wp-ai-bridge/application-password-create', 'user', (int) $user_id, false, $error->get_error_code() );
		return $error;
	}

	/** @return array<string,mixed> */
	private function read_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'  => array(
					'type'    => 'string',
					'enum'    => array( 'list', 'get' ),
					'default' => 'list',
				),
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'uuid'    => $this->uuid_schema(),
			),
			'required'             => array( 'user_id' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function create_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'app_id'  => array(
					'type'      => 'string',
					'maxLength' => 64,
				),
			),
			'required'             => array( 'user_id', 'name' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function update_input_schema() {
		$schema                       = $this->item_target_schema();
		$schema['properties']['name'] = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 200,
		);
		$schema['required'][]         = 'name';
		return $schema;
	}

	/** @return array<string,mixed> */
	private function item_target_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'uuid'    => $this->uuid_schema(),
			),
			'required'             => array( 'user_id', 'uuid' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function delete_all_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'confirm' => array(
					'type' => 'string',
					'enum' => array( 'revoke_all' ),
				),
			),
			'required'             => array( 'user_id', 'confirm' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function uuid_schema() {
		return array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 64,
			'pattern'   => '^[A-Za-z0-9_-]+$',
		);
	}

	/** @return array<string,mixed> */
	private function read_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id'   => array(
					'type' => 'integer',
				),
				'items'     => array(
					'type'     => 'array',
					'items'    => $this->item_schema(),
					'maxItems' => self::MAX_ITEMS,
				),
				'count'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_ITEMS,
				),
				'truncated' => array(
					'type' => 'boolean',
				),
			),
			'required'             => array( 'user_id', 'items', 'count', 'truncated' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function create_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id'  => array(
					'type' => 'integer',
				),
				'password' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
				'item'     => $this->item_schema(),
			),
			'required'             => array( 'user_id', 'password', 'item' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function delete_output_schema( $include_count ) {
		$properties = array(
			'user_id' => array(
				'type' => 'integer',
			),
			'deleted' => array(
				'type' => 'boolean',
			),
		);
		$required   = array( 'user_id', 'deleted' );
		if ( $include_count ) {
			$properties['count'] = array(
				'type'    => 'integer',
				'minimum' => 0,
			);
			$required[]          = 'count';
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function item_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'uuid'      => array(
					'type' => 'string',
				),
				'app_id'    => array(
					'type' => 'string',
				),
				'name'      => array(
					'type' => 'string',
				),
				'created'   => array(
					'type' => 'string',
				),
				'last_used' => array(
					'type' => array( 'string', 'null' ),
				),
			),
			'required'             => array( 'uuid', 'app_id', 'name', 'created', 'last_used' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function meta( $is_readonly, $destructive, $idempotent ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => (bool) $is_readonly,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent,
			),
		);
	}
}
