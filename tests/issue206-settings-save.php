<?php
/**
 * Unified save behavioral/security checks with native setter hook semantics.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Admin {
	// The common test bootstrap has a deliberately hook-free setter. This
	// scoped test adapter exercises this controller through native sanitizers,
	// pre-write rejection/mutation and post-write hooks without changing it.
	function update_option( $name, $value, $autoload = null ) {
		$old   = \get_option( $name, null );
		$value = \apply_filters( 'sanitize_option_' . $name, $value, $name, $value );
		$value = \apply_filters( 'pre_update_option_' . $name, $value, $old, $name );
		$value = \apply_filters( 'pre_update_option', $value, $name, $old );
		if ( $value === $old ) {
			return false;
		}
		$GLOBALS['wpai_test']['options'][ $name ] = $value;
		$GLOBALS['wpai206_writes'][] = $name;
		\do_action( 'updated_option', $name, $old, $value );
		return true;
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	use WP_AI_Bridge\Admin\Settings_Save;
	use WP_AI_Bridge\Auth\OAuth_Server;
	use WP_AI_Bridge\Support\Administration_Configuration;
	use WP_AI_Bridge\Support\External_Package_Limits;
	use WP_AI_Bridge\Support\Native_Option_Access;
	use WP_AI_Bridge\Support\Operation_Content_Limits;
	use WP_AI_Bridge\Support\Operation_Receipt_Limits;
	use WP_AI_Bridge\Support\Payload_Settings;
	use WP_AI_Bridge\Support\REST_Invocation_Input_Limits;
	use WP_AI_Bridge\Support\Settings;
	use WP_AI_Bridge\Support\Transfer_Settings;
	use WP_AI_Bridge\Support\Workspace_Limits;

	function is_multisite() { return ! empty( $GLOBALS['wpai_test']['multisite'] ); }
	function is_super_admin( $user = false ) { return ! empty( $GLOBALS['wpai_test']['super_admin'] ); }
	function get_current_blog_id() { return $GLOBALS['wpai_test']['blog'] ?? 1; }
	function get_current_network_id() { return $GLOBALS['wpai_test']['network'] ?? 1; }
	function get_network_option( $network, $name, $default = false ) { return $GLOBALS['wpai_test']['network_options'][ $network ][ $name ] ?? $default; }
	function wp_salt( $scheme = 'auth' ) { return 'synthetic-unified-settings-salt'; }
	function nocache_headers() { ++$GLOBALS['wpai206_nocache']; }
	function wp_verify_nonce( $nonce, $action = -1 ) { return hash_equals( hash( 'sha256', 'nonce-' . $action ), $nonce ); }
	function add_settings_error( $setting, $code, $message, $type = 'error' ) { $GLOBALS['wpai206_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' ); }
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['wpai206_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args ); }
	function remove_filter( $hook, $callback, $priority = 10 ) {
		foreach ( $GLOBALS['wpai206_filters'][ $hook ][ $priority ] ?? array() as $index => $registered ) {
			if ( $callback === $registered[0] ) {
				unset( $GLOBALS['wpai206_filters'][ $hook ][ $priority ][ $index ] );
			}
		}
	}
	function apply_filters( $hook, $value, ...$args ) {
		$priorities = $GLOBALS['wpai206_filters'][ $hook ] ?? array();
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = call_user_func_array( $callback[0], array_slice( array_merge( array( $value ), $args ), 0, $callback[1] ) );
			}
		}
		return $value;
	}
	function do_action( $hook, ...$args ) {
		$priorities = $GLOBALS['wpai206_filters'][ $hook ] ?? array();
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				call_user_func_array( $callback[0], array_slice( $args, 0, $callback[1] ) );
			}
		}
	}
	function update_network_option( $network, $name, $value ) {
		$old   = get_network_option( $network, $name, null );
		$value = apply_filters( 'pre_update_site_option_' . $name, $value, $old, $name, $network );
		if ( $value === $old ) {
			return false;
		}
		$value = apply_filters( 'sanitize_option_' . $name, $value, $name, $value );
		$GLOBALS['wpai_test']['network_options'][ $network ][ $name ] = $value;
		$GLOBALS['wpai206_writes'][] = $name;
		do_action( 'updated_site_option', $name, $value, $old, $network );
		return true;
	}
	function wpai206_assert( $condition, $message ) {
		++$GLOBALS['wpai206_assertions'];
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
	function wpai206_fixture( $multisite = false ) {
		wpai_test_reset_state();
		$GLOBALS['wpai_test']['user_id'] = 1;
		$GLOBALS['wpai_test']['blog'] = 1;
		$GLOBALS['wpai_test']['network'] = 1;
		$GLOBALS['wpai_test']['multisite'] = $multisite;
		$GLOBALS['wpai_test']['super_admin'] = $multisite;
		$GLOBALS['wpai_test']['network_options'] = array();
		$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = $multisite;
		$GLOBALS['wpai206_filters'] = array();
		$GLOBALS['wpai206_writes'] = array();
		$GLOBALS['wpai206_settings_errors'] = array();
		$GLOBALS['wp_current_filter'] = array();
		$GLOBALS['wp_filter'] = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$settings = new Settings();
		$settings->register();
		foreach ( array( new Payload_Settings(), new Transfer_Settings(), new Workspace_Limits(), new Operation_Receipt_Limits(), new Operation_Content_Limits(), new REST_Invocation_Input_Limits(), new External_Package_Limits() ) as $service ) {
			$service->register();
		}
		foreach ( $GLOBALS['wpai_test']['registered_settings'] as $name => $registration ) {
			add_filter( 'sanitize_option_' . $name, $registration['args']['sanitize_callback'] );
		}
		( new Administration_Configuration() )->boot();
		( new Native_Option_Access( new \WP_AI_Bridge\Support\Permissions( $settings ) ) )->register_network_guards();
		return new Settings_Save( $settings );
	}
	function wpai206_post( Settings_Save $controller, array $fields ) {
		$snapshot = $controller->snapshot();
		wpai206_assert( $snapshot['valid'], 'The fixture must have a valid administrator snapshot.' );
		return array_merge(
			array(
				'action' => Settings_Save::ACTION,
				'_wpnonce' => hash( 'sha256', 'nonce-' . $controller->nonce_action() ),
				'expected_revision' => $snapshot['revision'],
				'blog_id' => (string) $snapshot['blog_id'],
				'network_id' => (string) $snapshot['network_id'],
				'user_id' => (string) $snapshot['user_id'],
				'form_complete' => '1',
			),
			$fields
		);
	}

	$GLOBALS['wpai206_assertions'] = 0;
	$GLOBALS['wpai206_nocache'] = 0;
	$root = sys_get_temp_dir() . '/bridge-settings-save-' . bin2hex( random_bytes( 8 ) );
	mkdir( $root, 0700 );
	try {
		$controller = wpai206_fixture( true );
		$all = array(
			Settings::OPTION_NAME => array( Settings::GROUP_SITE_READ => '0', Settings::GROUP_SITE_CONFIG => '1' ),
			Settings::DATABASE_OPTION_NAME => array( Settings::GROUP_DATABASE_READ => '1' ),
			Settings::ADMINISTRATION_OPTION_NAME => array( Settings::GROUP_FILESYSTEM_READ => '1' ),
			Payload_Settings::OPTION_NAME => array( Payload_Settings::RESPONSE_KEY => '256' ),
			Transfer_Settings::OPTION_NAME => array( Transfer_Settings::SESSIONS => '12' ),
			Workspace_Limits::OPTION_NAME => array( Workspace_Limits::DOCUMENTS_KEY => '300' ),
			Operation_Receipt_Limits::OPTION_NAME => '4096',
			Operation_Content_Limits::OPTION_NAME => array( Operation_Content_Limits::SETTING_SCHEMA_KIB => '512' ),
			REST_Invocation_Input_Limits::OPTION_NAME => '24',
			External_Package_Limits::OPTION_NAME => '200',
			Administration_Configuration::OPTION_NAME => array(
				'roots' => array( array( 'id' => 'logs', 'path' => $root, 'purposes' => array( 'logs' ), 'read' => '1' ) ),
				'restore_schemas' => "restore_one\nrestore_two",
				'backup_tables' => "provider_data",
				'step_bytes' => '2097152', 'step_seconds' => '7',
			),
			Native_Option_Access::NETWORK_OPTION_NAME => '1',
			Native_Option_Access::NETWORK_WRITE_OPTION_NAME => '1',
		);
		$post = wpai206_post( $controller, $all );
		$result = $controller->save( $post );
		wpai206_assert( 'saved' === $result['status'] && ! $result['errors'] && 13 === count( $GLOBALS['wpai206_writes'] ), 'One save did not persist all thirteen existing option stores: ' . json_encode( $result ) );
		wpai206_assert( 0 === ( new Settings() )->all()[ Settings::GROUP_SITE_READ ] && 1 === ( new Settings() )->all()[ Settings::GROUP_DATABASE_READ ], 'Explicit unchecked grants or protected database grants were lost.' );
		wpai206_assert( array( 'restore_one', 'restore_two' ) === ( new Administration_Configuration() )->all()['restore_schemas'], 'Restore schema scope textarea did not retain exact identifiers.' );
		wpai206_assert( $all === $result['draft'] && ! isset( $result['draft']['_wpnonce'] ), 'Recoverable draft leaked transport/security fields.' );
		wpai206_assert( $controller->snapshot()['revision'] === $result['revision'], 'Verified response revision is not the persisted snapshot.' );
		$before = $GLOBALS['wpai_test']['options'];
		$result = $controller->save( wpai206_post( $controller, $all ) );
		wpai206_assert( 'unchanged' === $result['status'] && $before === $GLOBALS['wpai_test']['options'], 'Unchanged full form must not pretend to make new writes.' );

		// Missing fields preserve previous values, including within retained roots.
		$result = $controller->save( wpai206_post( $controller, array(
			Settings::OPTION_NAME => array( Settings::GROUP_SITE_CONFIG => '0' ),
			Administration_Configuration::OPTION_NAME => array( 'step_seconds' => '8' ),
		) ) );
		wpai206_assert( 'saved' === $result['status'] && $before[ Payload_Settings::OPTION_NAME ] === get_option( Payload_Settings::OPTION_NAME ), 'An omitted option was rewritten.' );
		$config = ( new Administration_Configuration() )->all();
		wpai206_assert( $config['roots']['logs']['read'] && 8 === $config['step_seconds'] && array( 'provider_data' ) === $config['backup_tables'], 'Omitted scopes or policy fields reset.' );
		$result = $controller->save( wpai206_post( $controller, array(
			Administration_Configuration::OPTION_NAME => array( 'roots' => array( array( 'id' => 'logs', 'label' => 'Updated label' ) ) ),
		) ) );
		wpai206_assert( 'saved' === $result['status'] && ( new Administration_Configuration() )->all()['roots']['logs']['read'], 'An omitted checkbox in a retained scope was revoked.' );

		// A later invalid section must prevent every earlier valid write.
		$before = $GLOBALS['wpai_test']['options'];
		$writes = count( $GLOBALS['wpai206_writes'] );
		$invalid = array( Settings::OPTION_NAME => array( Settings::GROUP_SITE_READ => '1' ), Payload_Settings::OPTION_NAME => array( Payload_Settings::RESPONSE_KEY => '512' ), Administration_Configuration::OPTION_NAME => array( 'roots' => array( array( 'id' => 'missing', 'path' => $root . '/not-present' ) ) ) );
		$result = $controller->save( wpai206_post( $controller, $invalid ) );
		wpai206_assert( 'invalid' === $result['status'] && $before === $GLOBALS['wpai_test']['options'] && $writes === count( $GLOBALS['wpai206_writes'] ), 'An invalid later directory scope allowed an earlier grant/budget write.' );
		wpai206_assert( $invalid === $result['draft'] && isset( $result['errors'][ Administration_Configuration::OPTION_NAME ] ), 'Validation feedback/draft did not remain recoverable.' );

		foreach ( array(
			Payload_Settings::OPTION_NAME => array( Payload_Settings::RESPONSE_KEY => '0' ),
			Transfer_Settings::OPTION_NAME => array( Transfer_Settings::SESSIONS => '012' ),
			Workspace_Limits::OPTION_NAME => array( Workspace_Limits::TASKS_KEY => '50001' ),
			Operation_Receipt_Limits::OPTION_NAME => '100001',
			Operation_Content_Limits::OPTION_NAME => array( Operation_Content_Limits::SOURCE_MIB => '2e3' ),
			REST_Invocation_Input_Limits::OPTION_NAME => (string) PHP_INT_MAX,
			External_Package_Limits::OPTION_NAME => array( 1 ),
			Settings::OPTION_NAME => array( Settings::GROUP_SITE_READ => 'yes' ),
			Administration_Configuration::OPTION_NAME => array( 'step_bytes' => '0' ),
		) as $name => $value ) {
			$result = $controller->save( wpai206_post( $controller, array( $name => $value ) ) );
			wpai206_assert( 'invalid' === $result['status'] && $before === $GLOBALS['wpai_test']['options'], 'A malformed/out-of-range field was accepted: ' . $name );
		}
		$result = $controller->save( wpai206_post( $controller, array( Administration_Configuration::OPTION_NAME => array( 'roots' => array( array( 'id' => 'logs', 'purposes' => '', 'read' => '0' ) ) ) ) ) );
		wpai206_assert( 'saved' === $result['status'] && array() === ( new Administration_Configuration() )->all()['roots']['logs']['purposes'] && ! ( new Administration_Configuration() )->all()['roots']['logs']['read'], 'Explicit empty purposes/unchecked flags did not revoke.' );
		$result = $controller->save( wpai206_post( $controller, array( Administration_Configuration::OPTION_NAME => array( 'roots' => '' ) ) ) );
		wpai206_assert( 'saved' === $result['status'] && array() === ( new Administration_Configuration() )->all()['roots'], 'An explicit empty roots collection was not cleared.' );

		// CSRF, transport, truncation and binding checks all happen before writes.
		foreach ( array( '_wpnonce' => 'bad', 'action' => 'wpai_workspace_clear', 'form_complete' => '0', 'blog_id' => '2', 'network_id' => '2', 'user_id' => '2', 'expected_revision' => 'old' ) as $field => $bad ) {
			$controller = wpai206_fixture();
			$post = wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096' ) );
			$post[ $field ] = $bad;
			$result = $controller->save( $post );
			wpai206_assert( in_array( $result['status'], array( 'forbidden', 'stale' ), true ) && ! $GLOBALS['wpai206_writes'], 'Invalid transport/context caused a write: ' . $field );
		}
		$controller = wpai206_fixture();
		$post = wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wpai206_assert( 'forbidden' === $controller->save( $post )['status'] && ! $GLOBALS['wpai206_writes'], 'GET request wrote settings.' );
		foreach ( array( 'anonymous', 'capability', 'principal', 'client', 'site', 'network' ) as $change ) {
			$controller = wpai206_fixture();
			$post = wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096' ) );
			switch ( $change ) {
				case 'anonymous': $GLOBALS['wpai_test']['user_id'] = 0; break;
				case 'capability': $GLOBALS['wpai_test']['capabilities']['manage_options'] = false; break;
				case 'principal': $GLOBALS['wpai_test']['user_id'] = 2; break;
				case 'site': $GLOBALS['wpai_test']['blog'] = 2; break;
				case 'network': $GLOBALS['wpai_test']['network'] = 2; break;
				case 'client':
					$client = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
					$client->setAccessible( true );
					$client->setValue( null, 'changed-client' );
					break;
			}
			$result = $controller->save( $post );
			wpai206_assert( in_array( $result['status'], array( 'forbidden', 'stale' ), true ) && ! $GLOBALS['wpai206_writes'], 'Changed authority or context caused a write: ' . $change );
			if ( 'client' === $change ) { $client->setValue( null, '' ); }
		}

		$controller = wpai206_fixture( true );
		$GLOBALS['wpai_test']['super_admin'] = false;
		$post = wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096' ) );
		wpai206_assert( ! in_array( Settings::DATABASE_OPTION_NAME, $controller->snapshot()['editable_options'], true ), 'Site admin can edit protected multisite grants.' );
		$result = $controller->save( $post );
		wpai206_assert( 'saved' === $result['status'] && ! isset( $GLOBALS['wpai_test']['options'][ Settings::DATABASE_OPTION_NAME ] ) && ! $GLOBALS['wpai_test']['network_options'], 'An ordinary multisite save changed unavailable grants.' );
		foreach ( array( Settings::DATABASE_OPTION_NAME => array( Settings::GROUP_DATABASE_READ => '1' ), Settings::ADMINISTRATION_OPTION_NAME => array( Settings::GROUP_FILESYSTEM_READ => '1' ), Administration_Configuration::OPTION_NAME => array( 'roots' => '' ), Native_Option_Access::NETWORK_OPTION_NAME => '1', Native_Option_Access::NETWORK_WRITE_OPTION_NAME => '0' ) as $name => $value ) {
			$writes = $GLOBALS['wpai206_writes'];
			$result = $controller->save( wpai206_post( $controller, array( $name => $value, Workspace_Limits::OPTION_NAME => array( Workspace_Limits::TASKS_KEY => '300' ) ) ) );
			wpai206_assert( 'forbidden' === $result['status'] && $writes === $GLOBALS['wpai206_writes'], 'Unauthorized group did not reject before ordinary writes: ' . $name );
		}
		$controller = wpai206_fixture();
		add_filter( 'option_page_capability_' . Operation_Receipt_Limits::OPTION_GROUP, fn( $cap ) => 'do_not_allow' );
		$result = $controller->save( wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096' ) ) );
		wpai206_assert( 'forbidden' === $result['status'] && ! $GLOBALS['wpai206_writes'], 'Native group capability filter was bypassed.' );

		$controller = wpai206_fixture();
		$post = wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096' ) );
		$GLOBALS['wpai_test']['options'][ Workspace_Limits::OPTION_NAME ] = array( 'documents' => 400, 'tasks' => 200 );
		wpai206_assert( 'stale' === $controller->save( $post )['status'] && ! $GLOBALS['wpai206_writes'], 'Stale form overwrote changed stored settings.' );

		// A native filter can reject or mutate the candidate; never claim saved.
		$controller = wpai206_fixture();
		$GLOBALS['wpai_test']['options'][ Operation_Receipt_Limits::OPTION_NAME ] = 2048;
		add_filter( 'pre_update_option_' . Operation_Receipt_Limits::OPTION_NAME, fn( $value, $old ) => $old, 10, 2 );
		$result = $controller->save( wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096' ) ) );
		wpai206_assert( 'rejected' === $result['status'] && 'rejected' === $result['outcomes'][ Operation_Receipt_Limits::OPTION_NAME ] && ! $GLOBALS['wpai206_writes'], 'Hook rejection was reported as a successful save.' );
		$controller = wpai206_fixture();
		add_filter( 'pre_update_option_' . Operation_Receipt_Limits::OPTION_NAME, fn( $value ) => 8192 );
		$result = $controller->save( wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096' ) ) );
		wpai206_assert( 'partial' === $result['status'] && 'unverified' === $result['outcomes'][ Operation_Receipt_Limits::OPTION_NAME ] && 8192 === get_option( Operation_Receipt_Limits::OPTION_NAME ), 'Hook-mutated persisted value was reported as requested/saved.' );
		$controller = wpai206_fixture();
		$GLOBALS['wpai_test']['options'][ Operation_Receipt_Limits::OPTION_NAME ] = 2048;
		add_filter( 'pre_update_option_' . Operation_Receipt_Limits::OPTION_NAME, fn( $value, $old ) => $old, 10, 2 );
		$result = $controller->save( wpai206_post( $controller, array( Workspace_Limits::OPTION_NAME => array( Workspace_Limits::DOCUMENTS_KEY => '300' ), Operation_Receipt_Limits::OPTION_NAME => '4096', External_Package_Limits::OPTION_NAME => '200' ) ) );
		wpai206_assert( 'partial' === $result['status'] && 'saved' === $result['outcomes'][ Workspace_Limits::OPTION_NAME ] && 'not_attempted' === $result['outcomes'][ External_Package_Limits::OPTION_NAME ], 'Partial persistence hid the successful/rejected/unattempted sections.' );
		$controller = wpai206_fixture();
		add_filter( 'updated_option', static function ( $name ) {
			if ( Operation_Receipt_Limits::OPTION_NAME === $name ) {
				$GLOBALS['wpai_test']['options'][ Workspace_Limits::OPTION_NAME ] = array( 'documents' => 500, 'tasks' => 200 );
			}
		} );
		$result = $controller->save( wpai206_post( $controller, array( Workspace_Limits::OPTION_NAME => array( Workspace_Limits::DOCUMENTS_KEY => '300' ), Operation_Receipt_Limits::OPTION_NAME => '4096' ) ) );
		wpai206_assert( 'partial' === $result['status'] && 'unverified' === $result['outcomes'][ Workspace_Limits::OPTION_NAME ], 'A later hook changed an earlier saved value without invalidating its outcome.' );

		$controller = wpai206_fixture();
		add_filter( 'pre_update_option_' . Operation_Receipt_Limits::OPTION_NAME, static function ( $value ) { $GLOBALS['wpai_test']['user_id'] = 2; return $value; } );
		$result = $controller->save( wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096', External_Package_Limits::OPTION_NAME => '200' ) ) );
		wpai206_assert( 'partial' === $result['status'] && ! $GLOBALS['wpai206_writes'] && 'not_attempted' === $result['outcomes'][ External_Package_Limits::OPTION_NAME ], 'Hook-time principal drift reached native storage or remaining writes.' );
		$controller = wpai206_fixture();
		add_filter( 'pre_update_option', static function ( $value ) { $GLOBALS['wpai_test']['network'] = 2; return $value; } );
		$result = $controller->save( wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096' ) ) );
		wpai206_assert( 'partial' === $result['status'] && ! $GLOBALS['wpai206_writes'], 'Generic pre-update filter context drift reached storage.' );

		$controller = wpai206_fixture();
		add_filter( 'updated_option', static function () { throw new RuntimeException( 'secret-provider-message' ); } );
		$result = $controller->save( wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096', External_Package_Limits::OPTION_NAME => '200' ) ) );
		wpai206_assert( 'partial' === $result['status'] && 4096 === get_option( Operation_Receipt_Limits::OPTION_NAME ) && false === strpos( json_encode( $result ), 'secret-provider-message' ), 'Post-write exception fabricated success or exposed provider exception content.' );
		$controller = wpai206_fixture();
		add_filter( 'updated_option', static function () {
			$handler = apply_filters( 'wp_die_handler', null );
			wpai206_assert( is_callable( $handler ), 'The native termination handler must be scoped around persistence.' );
			$handler( 'secret-native-denial' );
		} );
		$result = $controller->save( wpai206_post( $controller, array( Operation_Receipt_Limits::OPTION_NAME => '4096' ) ) );
		wpai206_assert( 'partial' === $result['status'] && false === strpos( json_encode( $result ), 'secret-native-denial' ), 'Native termination lost truthful partial recovery or exposed its message.' );
		wpai206_assert( null === apply_filters( 'wp_die_handler', null ), 'The temporary native termination handler leaked beyond this Save.' );

		wpai206_assert( $GLOBALS['wpai206_nocache'] > 0, 'Save responses must disable caching.' );
		echo "PASS: #206 {$GLOBALS['wpai206_assertions']} unified save, authority, validation, omission, stale and native hook outcome assertions.\n";
	} finally {
		rmdir( $root );
	}
}
