<?php
/**
 * Configuration authorization, canonical v2 values and one-dispatch regressions.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support {
	/** Model a provider whose early-only dependency is unavailable to late registration. */
	function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
	function remove_action( $hook, $callback ) { return true; }
	function do_action( $hook, $manager ) {
		if ( 'provider' === $GLOBALS['config_bootstrap_failure'] ) { throw new \Error( 'private-theme-path/provider-secret' ); }
	}
	/** Test-only journal model. Real native journal has a separate integration suite. */
	final class Administration_Operation_Journal {
		public static $records = array();
		public static $arms = 0;
		private $prefix;
		public function __construct( $purpose, array $identity, $budget ) { $this->prefix = $purpose . json_encode( $identity ); }
		public function check_context() { return true; }
		public function status( $id, $fingerprint = null ) {
			$result = self::$records[ $this->prefix . $id ] ?? null;
			if ( $result && null !== $fingerprint && $fingerprint !== $result['receipt']['fingerprint'] ) { return new \WP_Error( 'administration_operation_conflict', '' ); }
			if ( $result ) { $result['historical'] = true; }
			return $result;
		}
		public function reserve( $id, $fingerprint, array $descriptor ) {
			$old = $this->status( $id, $fingerprint );
			if ( $old ) { return is_wp_error( $old ) ? $old : array_merge( $old, array( 'dispatch_allowed' => false, 'claim' => null ) ); }
			$key = $this->prefix . $id;
			$result = array( 'state' => 'prepared', 'operation_ref' => hash( 'sha256', $key ), 'historical' => false, 'receipt' => array( 'fingerprint' => $fingerprint, 'descriptor' => $descriptor, 'result' => null ) );
			self::$records[ $key ] = $result;
			return array_merge( $result, array( 'dispatch_allowed' => true, 'claim' => array( 'token' => $key ) ) );
		}
		public function arm( array $claim ) { ++self::$arms; self::$records[ $claim['token'] ]['state'] = 'dispatch_armed'; return true; }
		public function complete( array $claim, array $result ) { self::$records[ $claim['token'] ]['state'] = 'completed'; self::$records[ $claim['token'] ]['receipt']['result'] = $result; return self::$records[ $claim['token'] ]; }
	}
	/** Test records that the adapter uses the existing native physical target guard. */
	final class Native_Option_Operation {
		public static $targets = array();
		public function __construct( array $identity, $budget ) {}
		public function check_target( $scope, $name ) { self::$targets[] = array( $scope, $name ); return array( 'scope' => $scope, 'name' => $name ); }
	}
}
namespace {
	require __DIR__ . '/bootstrap.php';
	require __DIR__ . '/integration/issue199-native-options-helper.php';

	use WP_AI_Bridge\Abilities\Native_Configuration_Abilities;
	use WP_AI_Bridge\Abilities\Customizer_Abilities;
	use WP_AI_Bridge\Support\Administration_Operation_Journal;
	use WP_AI_Bridge\Support\Native_Option_Access;
	use WP_AI_Bridge\Support\Native_Option_Value;
	use WP_AI_Bridge\Support\Permissions;
	use WP_AI_Bridge\Support\Settings;

	function is_multisite() { return ! empty( $GLOBALS['wpai_test']['multisite'] ); }
	function is_super_admin( $id = false ) { return ! empty( $GLOBALS['wpai_test']['super_admin'] ); }
	function get_current_blog_id() { return $GLOBALS['wpai_test']['blog'] ?? 1; }
	function get_current_network_id() { return $GLOBALS['wpai_test']['network'] ?? 1; }
	function get_network_option( $network, $name, $default = false ) { return $GLOBALS['wpai_test']['network_options'][ $network ][ $name ] ?? $default; }
	function get_stylesheet() { return $GLOBALS['wpai_test']['theme']['stylesheet']; }
	function wp_salt( $scheme = 'auth' ) { return 'configuration-unit-salt'; }
	function get_theme_mods() { return $GLOBALS['config_mods'] ?? array(); }
	function get_theme_mod( $name ) { return $GLOBALS['config_mods'][ $name ] ?? false; }
	function set_theme_mod( $name, $value ) { ++$GLOBALS['config_writes']; $GLOBALS['config_mods'][ $name ] = $value; return true; }
	function remove_theme_mod( $name ) { ++$GLOBALS['config_writes']; unset( $GLOBALS['config_mods'][ $name ] ); }
	function get_transient( $name ) { return $GLOBALS['config_transients'][ $name ] ?? false; }
	function get_site_transient( $name ) { return $GLOBALS['config_network_transients'][ $name ] ?? false; }
	function set_transient( $name, $value, $expiration ) {
		++$GLOBALS['config_writes'];
		$GLOBALS['config_transients'][ $name ] = $value;
		$GLOBALS['config_expiration'] = $expiration;
		if ( ! empty( $GLOBALS['config_throw'] ) ) { throw new RuntimeException( 'secret-marker-after-write' ); }
		return true;
	}
	function set_site_transient( $name, $value, $expiration ) { ++$GLOBALS['config_writes']; $GLOBALS['config_network_transients'][ $name ] = $value; return true; }
	function delete_transient( $name ) { ++$GLOBALS['config_writes']; unset( $GLOBALS['config_transients'][ $name ] ); return true; }
	function delete_site_transient( $name ) { ++$GLOBALS['config_writes']; unset( $GLOBALS['config_network_transients'][ $name ] ); return true; }

	function configuration_same( $expected, $actual ) {
		if ( $expected instanceof stdClass ) {
			wpai199n_assert( $actual instanceof stdClass && get_class( $actual ) === 'stdClass', 'stdClass type was lost.' );
			$expected = (array) $expected;
			$actual = (array) $actual;
		}
		if ( is_array( $expected ) ) {
			wpai199n_assert( is_array( $actual ) && array_keys( $actual ) === array_keys( $expected ), 'Ordered binary keys changed.' );
			foreach ( $expected as $key => $value ) { configuration_same( $value, $actual[ $key ] ); }
		} else { wpai199n_same( $expected, $actual, 'v2 native scalar changed.' ); }
	}

	final class Configuration_Magic implements JsonSerializable {
		public static $calls = 0;
		public function jsonSerialize(): mixed { ++self::$calls; return 'secret-marker'; }
		public function __serialize() { ++self::$calls; return array(); }
		public function __get( $name ) { ++self::$calls; return null; }
	}
	$object = (object) array( 'z' => array( false, PHP_INT_MAX, -0.0 ), '0' => 'numeric-property', '' => null, "\0private\0bytes" => "\xff\0", 'nested' => (object) array( 'last' => true ) );
	$encoded = wpai199n_ok( Native_Option_Value::encode( $object, 1048576, true ), 'Opt-in stdClass encoding failed.' );
	wpai199n_assert( str_starts_with( $encoded['json'], '{"format":"wpai-native-option-value-v2"' ), 'New contract did not select v2.' );
	$decoded = wpai199n_ok( Native_Option_Value::decode( $encoded['json'], $encoded['bytes'], 0, true ), 'Opt-in stdClass decoding failed.' );
	configuration_same( $object, $decoded['value'] );
	wpai199n_assert( Native_Option_Value::encode( $decoded['value'], $encoded['bytes'], true )['json'] === $encoded['json'], 'v2 canonical identity changed.' );
	wpai199n_error( Native_Option_Value::encode( $object, 1048576 ), 'Existing v1 unexpectedly accepts objects.' );
	wpai199n_error( Native_Option_Value::decode( $encoded['json'], 1048576 ), 'Existing v1 unexpectedly accepts v2.' );
	wpai199n_error( Native_Option_Value::decode( $encoded['json'], $encoded['bytes'] - 1, 0, true ), 'v2 byte budget was bypassed.' );
	foreach ( array( new Configuration_Magic(), new class() extends stdClass {} ) as $value ) { wpai199n_error( Native_Option_Value::encode( $value, 4096, true ), 'Arbitrary class was accepted.' ); }
	wpai199n_assert( 0 === Configuration_Magic::$calls, 'Codec invoked object magic.' );
	$cycle = new stdClass(); $cycle->self = $cycle;
	wpai199n_error( Native_Option_Value::encode( $cycle, 4096, true ), 'Object cycle accepted.' );
	$shared = new stdClass();
	wpai199n_error( Native_Option_Value::encode( array( $shared, $shared ), 4096, true ), 'Shared object alias accepted.' );
	$referenced = 'secret-marker'; $reference = new stdClass(); $reference->property = &$referenced;
	wpai199n_error( Native_Option_Value::encode( $reference, 4096, true ), 'Object reference accepted.' );
	$duplicate = '{"format":"wpai-native-option-value-v2","value":{"type":"stdClass","entries":[{"key":{"type":"string","base64":"MA=="},"value":{"type":"null"}},{"key":{"type":"string","base64":"MA=="},"value":{"type":"null"}}]}}';
	wpai199n_error( Native_Option_Value::decode( $duplicate, 4096, 0, true ), 'Duplicate object property accepted.' );
	wpai199n_error( Native_Option_Value::decode( str_replace( '"stdClass"', '"Configuration_Magic"', $duplicate ), 4096, 0, true ), 'Class-name injection accepted.' );

	wpai_test_reset_state();
	$GLOBALS['wp_current_filter'] = array(); $GLOBALS['wp_filter'] = array();
	$GLOBALS['config_writes'] = 0;
	update_option( 'stylesheet', 'test-theme' );
	$settings = new Settings();
	$permissions = new Permissions( $settings );
	$provider = new Native_Configuration_Abilities( $permissions );
	$customizer = new Customizer_Abilities( $permissions );
	wpai199n_assert( 1 === count( $provider->register() ) && 1 === count( $customizer->register() ), 'Native families did not register exactly one Ability each.' );
	foreach ( array( 'wp-ai-bridge/native-configuration', 'wp-ai-bridge/customizer-settings' ) as $name ) {
		$args = $GLOBALS['wpai_test']['registered_abilities'][ $name ];
		wpai199n_assert( array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ) === $args['meta']['annotations'], 'Mixed configuration effects were mislabeled.' );
		wpai199n_assert( isset( $args['input_schema']['oneOf'] ) && false === $args['input_schema']['additionalProperties'], 'Native action schema is not closed.' );
	}
	$value = Native_Option_Value::encode( (object) array( 'message' => 'secret-marker', 'enabled' => true ), 4096, true )['json'];
	$set = array( 'action' => 'set', 'target' => 'transient', 'name' => 'example_provider', 'operation_id' => 'config-example-0001', 'expiration_seconds' => 120, 'value_source' => array( 'value_json' => $value ) );
	wpai199n_assert( ! $provider->can_execute( $set ), 'New configuration grant defaulted on.' );
	$all = array_fill_keys( array_keys( $settings->defaults() ), 1 );
	update_option( Settings::OPTION_NAME, $all );
	wpai199n_assert( ! $provider->can_execute( $set ), 'Historical ordinary grants enabled protected configuration.' );
	update_option( Settings::ADMINISTRATION_OPTION_NAME, array( Settings::GROUP_NATIVE_CONFIGURATION => 1, '_blog_id' => 1, '_network_id' => 1 ) );
	wpai199n_assert( $provider->can_execute( $set ), 'Exact current configuration grant failed.' );
	$result = wpai199n_ok( $provider->execute( $set ), 'Native transient setter failed.' );
	wpai199n_assert( 'completed' === $result['state'] && 'native_call_returned' === $result['receipt']['result']['outcome'] && 1 === $GLOBALS['config_writes'] && 120 === $GLOBALS['config_expiration'], 'Native effect/outcome lost its one-call contract.' );
	$again = wpai199n_ok( $provider->execute( $set ), 'Historical status recovery failed.' );
	wpai199n_assert( true === $again['historical'] && 1 === $GLOBALS['config_writes'], 'Same ID repeated a native setter.' );
	$changed = $set; $changed['expiration_seconds'] = 121;
	wpai199n_error( $provider->execute( $changed ), 'Same ID changed fingerprint without conflict.' );
	$stale = $set; $stale['operation_id'] = 'config-stale-0001'; $stale['expected_observed_sha256'] = str_repeat( '0', 64 );
	wpai199n_error( $provider->execute( $stale ), 'Stale native intent dispatched.' );
	wpai199n_assert( 1 === $GLOBALS['config_writes'], 'Stale precondition mutated native state.' );
	$read = array( 'action' => 'read', 'target' => 'transient', 'name' => 'example_provider' );
	$result = wpai199n_ok( $provider->execute( $read ), 'Native transient object read failed.' );
	configuration_same( $GLOBALS['config_transients']['example_provider'], Native_Option_Value::decode( $result['value_json'], 4096, 0, true )['value'] );
	$read['name'] = 'missing_provider';
	$result = wpai199n_ok( $provider->execute( $read ), 'Missing transient read failed.' );
	wpai199n_assert( 'unknown' === $result['storage_presence'] && false === Native_Option_Value::decode( $result['value_json'], 4096, 0, true )['value'], 'False transient value was mislabeled absent.' );
	$GLOBALS['config_throw'] = true;
	$throwing = $set; $throwing['operation_id'] = 'config-throw-0001';
	$result = wpai199n_ok( $provider->execute( $throwing ), 'Post-effect exception lost recovery.' );
	wpai199n_assert( 'unknown' === $result['receipt']['result']['outcome'] && true === $result['receipt']['result']['callback_threw'], 'Post-effect exception was relabeled uncommitted.' );
	wpai199n_assert( ! str_contains( json_encode( $result ), 'secret-marker' ), 'Receipt leaked provider value or exception.' );
	$provider->execute( $throwing );
	wpai199n_assert( 2 === $GLOBALS['config_writes'], 'Unknown effect was dispatched again.' );
	$GLOBALS['config_throw'] = false;
	$mod = array_replace( $set, array( 'target' => 'theme_mod', 'name' => 'header_tint', 'operation_id' => 'config-theme-0001', 'expected_stylesheet' => 'test-theme' ) ); unset( $mod['expiration_seconds'] );
	$GLOBALS['wpai_test']['capabilities']['edit_theme_options'] = true;
	wpai199n_ok( $provider->execute( $mod ), 'Exact current-theme update failed.' );
	$wrong_theme = $mod; $wrong_theme['expected_stylesheet'] = 'different-theme'; $wrong_theme['operation_id'] = 'config-theme-0002';
	wpai199n_error( $provider->execute( $wrong_theme ), 'Stale theme identity mutated current theme.' );
	$flags = $all; $flags[ Settings::GROUP_NATIVE_OPTIONS_WRITE ] = 0; $flags[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $flags );
	wpai199n_assert( ! $provider->can_execute( $set ) && $provider->can_execute( array( 'action' => 'status', 'target' => 'transient', 'operation_id' => $set['operation_id'] ) ), 'Write and historical read authority were conflated.' );
	update_option( Settings::OPTION_NAME, $all );
	$network = array_replace( $set, array( 'target' => 'site_transient', 'operation_id' => 'config-network-0001' ) );
	wpai199n_assert( $provider->can_execute( $network ), 'Single-site site-wide transients were excluded.' );
	$GLOBALS['wpai_test']['multisite'] = true;
	wpai199n_assert( ! $provider->can_execute( $network ), 'Multisite network consent was bypassed.' );
	$GLOBALS['wpai_test']['super_admin'] = true; $GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
	$GLOBALS['wpai_test']['network_options'][1][ Native_Option_Access::NETWORK_OPTION_NAME ] = 1;
	$GLOBALS['wpai_test']['network_options'][1][ Native_Option_Access::NETWORK_WRITE_OPTION_NAME ] = 1;
	wpai199n_assert( $provider->can_execute( $network ), 'Current network authority rejected.' );
	$GLOBALS['wpai_test']['network_options'][1][ Native_Option_Access::NETWORK_WRITE_OPTION_NAME ] = 0;
	wpai199n_assert( ! $provider->can_execute( $network ), 'Network grant revocation cached.' );

	foreach ( array(
		array_replace( $set, array( 'target' => 'unknown' ) ), array_replace( $set, array( 'callback' => 'set_option' ) ),
		array_diff_key( $set, array( 'expiration_seconds' => true ) ), array_replace( $set, array( 'expiration_seconds' => -1 ) ),
		array_replace( $set, array( 'name' => 'wpai_oauth_access_fake' ) ), array_replace( $set, array( 'name' => str_repeat( 'x', 173 ) ) ),
		array_diff_key( $mod, array( 'expected_stylesheet' => true ) ), array_replace( $mod, array( 'expiration_seconds' => 1 ) ),
		array( 'action' => 'list', 'target' => 'transient' ), array( 'action' => 'delete', 'target' => 'transient', 'name' => 'x', 'operation_id' => 'unit-delete-0001', 'confirm' => false ),
	) as $invalid ) { wpai199n_error( Native_Configuration_Abilities::parse( $invalid ), 'Unrelated/missing/invalid native fields accepted.' ); }
	wpai199n_assert( ! is_wp_error( Native_Configuration_Abilities::parse( array( 'action' => 'input_append', 'target' => 'transient', 'transfer_id' => str_repeat( 'a', 64 ), 'offset' => 0, 'base64' => 'MA==' ) ) ), 'Byte string zero was treated as empty.' );
	wpai199n_error( Native_Configuration_Abilities::parse( array( 'action' => 'update', 'expected_stylesheet' => 'test-theme', 'name' => 'setting', 'operation_id' => 'unit-custom-0001', 'value_source' => array( 'value_json' => $value ), 'changeset_uuid' => 'foreign' ), true ), 'Caller-selected Customizer changeset accepted.' );

	// Late registration failures are a typed prerequisite, before any journal
	// reservation/arm. No provider exception or ambient request state can escape.
	final class WP_Customize_Manager {
		public function __construct( array $args ) {
			++$GLOBALS['config_manager_attempts'];
			if ( 'constructor' === $GLOBALS['config_bootstrap_failure'] ) { throw new Error( 'private-theme-path/constructor-secret' ); }
		}
		public function register_controls() {
			if ( 'core' === $GLOBALS['config_bootstrap_failure'] ) { throw new RuntimeException( 'private-theme-path/core-secret' ); }
		}
		public function get_stylesheet() { return 'test-theme'; }
		public function is_theme_active() { return true; }
		public function settings_previewed() { return false; }
	}
	wpai_test_reset_state();
	$GLOBALS['wp_current_filter'] = array(); $GLOBALS['wp_filter'] = array();
	update_option( 'stylesheet', 'test-theme' );
	update_option( Settings::OPTION_NAME, $all );
	update_option( Settings::ADMINISTRATION_OPTION_NAME, array( Settings::GROUP_CUSTOMIZER_WRITE => 1, '_blog_id' => 1, '_network_id' => 1 ) );
	foreach ( array( 'customize', 'edit_theme_options', 'upload_files', 'edit_posts', 'publish_posts', 'activate_plugins', 'edit_plugins', 'edit_themes', 'install_plugins', 'moderate_comments', 'delete_users' ) as $capability ) { $GLOBALS['wpai_test']['capabilities'][ $capability ] = true; }
	$fixture_root = sys_get_temp_dir() . '/wpai-customizer-unit-' . bin2hex( random_bytes( 8 ) );
	mkdir( $fixture_root );
	file_put_contents( $fixture_root . '/class-wp-customize-manager.php', '<?php // Test manager is declared above.' );
	define( 'WP_AI_Bridge\\Support\\ABSPATH', $fixture_root . '/' );
	define( 'WP_AI_Bridge\\Support\\WPINC', '.' );
	$initialized = new ReflectionProperty( WP_AI_Bridge\Support\Customizer_Context::class, 'initialized' );
	$prior_post = $_POST; $prior_request = $_REQUEST;
	$had_global = array_key_exists( 'wp_customize', $GLOBALS ); $prior_global = $GLOBALS['wp_customize'] ?? null;
	try {
		foreach ( array( 'constructor', 'core', 'provider' ) as $failure ) {
			foreach ( array( false, true ) as $null_global ) {
				$initialized->setValue( null, false ); // Only the test may reset request-static Core isolation.
				$GLOBALS['config_bootstrap_failure'] = $failure; $GLOBALS['config_manager_attempts'] = 0;
				if ( $null_global ) { $GLOBALS['wp_customize'] = null; } else { unset( $GLOBALS['wp_customize'] ); }
				$_POST = array( 'customized' => 'private-ambient-value', 'theme' => 'other-theme', 'sentinel' => array( 0 ) );
				$_REQUEST = array( 'customize_theme' => 'other-theme', 'customize_changeset_uuid' => 'foreign', 'sentinel' => array( false ) );
				$ambient_post = $_POST; $ambient_request = $_REQUEST;
				$records = Administration_Operation_Journal::$records; $arms = Administration_Operation_Journal::$arms;
				$failed = wpai199n_error( $customizer->execute( array( 'action' => 'update', 'expected_stylesheet' => 'test-theme', 'name' => 'provider_setting', 'operation_id' => 'unit-customizer-' . $failure . ( $null_global ? '-null' : '-absent' ), 'value_source' => array( 'value_json' => $value ) ) ), 'Late bootstrap exception did not return safely.', array( 'customizer_late_bootstrap_unavailable' ) );
				wpai199n_assert( false === $failed->get_error_data()['dispatch_attempted'] && '' === $failed->get_error_data()['operation_ref'], 'Bootstrap failure claimed a native dispatch.' );
				wpai199n_assert( $records === Administration_Operation_Journal::$records && $arms === Administration_Operation_Journal::$arms, 'Bootstrap failure reserved or armed a journal.' );
				wpai199n_assert( $_POST === $ambient_post && $_REQUEST === $ambient_request && array_key_exists( 'wp_customize', $GLOBALS ) === $null_global && null === ( $GLOBALS['wp_customize'] ?? null ), 'Failed bootstrap did not exactly restore request/global state.' );
				wpai199n_assert( ! str_contains( $failed->get_error_message(), 'private-' ), 'Bootstrap diagnostic leaked provider details.' );
				$context = new WP_AI_Bridge\Support\Customizer_Context( $permissions, 'test-theme' );
				wpai199n_error( $context->open(), 'Failed bootstrap allowed another fresh manager.', array( 'customizer_request_context_used' ) );
				wpai199n_assert( 1 === $GLOBALS['config_manager_attempts'], 'Failed bootstrap was attempted twice in one request.' );
			}
		}
	} finally {
		$_POST = $prior_post; $_REQUEST = $prior_request;
		if ( $had_global ) { $GLOBALS['wp_customize'] = $prior_global; } else { unset( $GLOBALS['wp_customize'] ); }
		unlink( $fixture_root . '/class-wp-customize-manager.php' ); rmdir( $fixture_root );
	}
	echo 'PASS: native configuration and stdClass v2 (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
}
