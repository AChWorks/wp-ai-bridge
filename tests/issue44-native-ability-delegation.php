<?php
error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/wp/' );

$GLOBALS['wpnb_issue44_options']      = array();
$GLOBALS['wpnb_issue44_abilities']    = array();
$GLOBALS['wpnb_issue44_capabilities'] = array( 'read' => true );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function __( $text, $domain = null ) { return $text; }
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wpnb_issue44_options'] ) ? $GLOBALS['wpnb_issue44_options'][ $name ] : $default;
}
function wp_get_ability( $name ) {
	return $GLOBALS['wpnb_issue44_abilities'][ $name ] ?? null;
}
function wp_get_abilities() {
	return array_values( $GLOBALS['wpnb_issue44_abilities'] );
}
function current_user_can( $capability ) {
	return ! empty( $GLOBALS['wpnb_issue44_capabilities'][ $capability ] );
}

require dirname( __DIR__ ) . '/src/Support/class-settings.php';
require dirname( __DIR__ ) . '/src/Support/class-native-ability-delegation.php';
require dirname( __DIR__ ) . '/src/Support/class-permissions.php';
require dirname( __DIR__ ) . '/src/Abilities/class-ability-resolver.php';
require dirname( __DIR__ ) . '/src/Abilities/class-ability-catalog-abilities.php';

use WP_Native_Builder_Bridge\Abilities\Ability_Catalog_Abilities;
use WP_Native_Builder_Bridge\Abilities\Ability_Resolver;
use WP_Native_Builder_Bridge\Support\Native_Ability_Delegation;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

$failures = 0;
$tests    = 0;
function wpnb_issue44_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

class WP_Native_Builder_Issue44_Ability {
	private $name;
	private $meta;
	public function __construct( $name, array $meta = array() ) { $this->name = (string) $name; $this->meta = $meta; }
	public function get_name() { return $this->name; }
	public function get_label() { return $this->name; }
	public function get_description() { return 'Issue 44 fixture ' . $this->name; }
	public function get_category() { return 'issue44'; }
	public function get_input_schema() { return array( 'type' => 'object', 'properties' => array() ); }
	public function get_output_schema() { return array( 'type' => 'object', 'properties' => array() ); }
	public function get_meta() { return $this->meta; }
}

class WP_Native_Builder_Issue44_Custom_Ability extends WP_Native_Builder_Issue44_Ability {}

final class WP_Native_Builder_Issue44_Callback_Owner {
	public function permission() { return true; }
	public function execute() { return true; }
}

$settings   = new Settings();
$delegation = new Native_Ability_Delegation( $settings );

$defaults = $settings->defaults();
wpnb_issue44_assert( isset( $defaults[ Settings::GROUP_NATIVE_ABILITIES ] ), 'Native Abilities group is missing from defaults.' );
wpnb_issue44_assert( 0 === $defaults[ Settings::GROUP_NATIVE_ABILITIES ], 'Native Abilities must default to disabled.' );
$GLOBALS['wpnb_issue44_options'][ Settings::OPTION_NAME ] = array( Settings::GROUP_SITE_READ => 1 );
wpnb_issue44_assert( 0 === $settings->all()[ Settings::GROUP_NATIVE_ABILITIES ], 'Existing settings silently enabled Native Abilities on upgrade.' );

$bridge_args      = null;
$bridge_object    = null;
$reentrant_object = null;
$bridge_owner     = new WP_Native_Builder_Issue44_Callback_Owner();
$provider_owner   = new WP_Native_Builder_Issue44_Callback_Owner();
$delegation->capture_bridge_registrations(
	static function () use ( $delegation, $bridge_owner, $provider_owner, &$bridge_args, &$bridge_object, &$reentrant_object ) {
		$bridge_args = $delegation->filter_ability_args(
			array(
				'permission_callback' => array( $bridge_owner, 'permission' ),
				'execute_callback'    => array( $bridge_owner, 'execute' ),
				'meta'                => array( 'public' => true, 'annotations' => array( 'readonly' => true ) ),
			),
			'wp-native-builder/fixture'
		);
		$bridge_object = new WP_Native_Builder_Issue44_Ability( 'wp-native-builder/fixture', $bridge_args['meta'] );
		$GLOBALS['wpnb_issue44_abilities']['wp-native-builder/fixture'] = $bridge_object;

		$reentrant_args = $delegation->filter_ability_args(
			array(
				'permission_callback' => array( $provider_owner, 'permission' ),
				'execute_callback'    => array( $provider_owner, 'execute' ),
				'meta'                => array( 'public' => true, 'wp_ai_bridge_owned' => true ),
			),
			'wp-native-builder/reentrant-provider'
		);
		$reentrant_object = new WP_Native_Builder_Issue44_Ability( 'wp-native-builder/reentrant-provider', $reentrant_args['meta'] );
		$GLOBALS['wpnb_issue44_abilities']['wp-native-builder/reentrant-provider'] = $reentrant_object;

		$delegation->capture_bridge_registrations(
			static function () use ( $delegation, $provider_owner ) {
				$args = $delegation->filter_ability_args(
					array(
						'permission_callback' => array( $provider_owner, 'permission' ),
						'execute_callback'    => array( $provider_owner, 'execute' ),
						'meta'                => array( 'public' => true ),
					),
					'wp-native-builder/nested-capture-provider'
				);
				$GLOBALS['wpnb_issue44_abilities']['wp-native-builder/nested-capture-provider'] = new WP_Native_Builder_Issue44_Ability(
					'wp-native-builder/nested-capture-provider',
					$args['meta']
				);
			},
			array( $provider_owner )
		);
	},
	array( $bridge_owner )
);
wpnb_issue44_assert( ! isset( $bridge_args['meta']['wp_ai_bridge_owned'] ), 'Bridge registration leaked a metadata ownership authority.' );
wpnb_issue44_assert(
	$delegation->is_bridge_owned_ability( $bridge_object ),
	'Genuine Bridge registration was not bound to the actual registered object.'
);
wpnb_issue44_assert(
	! $delegation->is_bridge_owned_ability( $reentrant_object ),
	'Re-entrant provider registration borrowed Bridge provenance from registration depth.'
);
wpnb_issue44_assert(
	! $delegation->is_bridge_owned_ability( $GLOBALS['wpnb_issue44_abilities']['wp-native-builder/nested-capture-provider'] ),
	'Nested capture replaced the authoritative trusted-owner context.'
);

$replacement = new WP_Native_Builder_Issue44_Ability(
	'wp-native-builder/fixture',
	array( 'public' => true, 'wp_ai_bridge_owned' => true )
);
$GLOBALS['wpnb_issue44_abilities']['wp-native-builder/fixture'] = $replacement;
wpnb_issue44_assert(
	! $delegation->is_bridge_owned_ability( $replacement ),
	'A later same-name replacement inherited Bridge ownership from the original object.'
);
$GLOBALS['wpnb_issue44_abilities']['wp-native-builder/fixture'] = $bridge_object;
wpnb_issue44_assert(
	$delegation->is_bridge_owned_ability( $bridge_object ),
	'Restoring the original registered object lost its exact-object provenance.'
);

try {
	$delegation->capture_bridge_registrations(
		static function () use ( $delegation, $bridge_owner ) {
			$delegation->filter_ability_args(
				array(
					'permission_callback' => array( $bridge_owner, 'permission' ),
					'execute_callback'    => array( $bridge_owner, 'execute' ),
					'meta'                => array( 'public' => true ),
				),
				'wp-native-builder/failed-registration'
			);
			throw new RuntimeException( 'registration-fixture' );
		},
		array( $bridge_owner )
	);
} catch ( RuntimeException $exception ) {
	wpnb_issue44_assert( 'registration-fixture' === $exception->getMessage(), 'Bridge registration capture changed an exception.' );
}
$GLOBALS['wpnb_issue44_abilities']['wp-native-builder/failed-registration'] = new WP_Native_Builder_Issue44_Ability(
	'wp-native-builder/failed-registration',
	array( 'public' => true )
);
wpnb_issue44_assert(
	! $delegation->is_bridge_owned_ability( $GLOBALS['wpnb_issue44_abilities']['wp-native-builder/failed-registration'] ),
	'Failed Bridge registration leaked provenance into a later provider object.'
);

$provider_args = $delegation->filter_ability_args(
	array(
		'permission_callback' => static function () { return true; },
		'execute_callback'    => static function () { return true; },
		'meta'                => array( 'public' => true, 'wp_ai_bridge_owned' => true ),
	),
	'wp-native-builder/forged-prefix'
);
$GLOBALS['wpnb_issue44_abilities']['wp-native-builder/forged-prefix'] = new WP_Native_Builder_Issue44_Ability(
	'wp-native-builder/forged-prefix',
	$provider_args['meta']
);
wpnb_issue44_assert( true === $provider_args['meta']['wp_ai_bridge_owned'], 'Provider metadata was unexpectedly rewritten instead of remaining untrusted ordinary data.' );
wpnb_issue44_assert(
	! $delegation->is_bridge_owned_ability( $GLOBALS['wpnb_issue44_abilities']['wp-native-builder/forged-prefix'] ),
	'Provider registration outside the Bridge phase inherited Bridge provenance.'
);

$rogue_delegation = new Native_Ability_Delegation( new Settings() );
$rogue_owner      = new WP_Native_Builder_Issue44_Callback_Owner();
$rogue_object     = null;
$rogue_delegation->capture_bridge_registrations(
	static function () use ( $rogue_delegation, $rogue_owner, &$rogue_object ) {
		$args = $rogue_delegation->filter_ability_args(
			array(
				'permission_callback' => array( $rogue_owner, 'permission' ),
				'execute_callback'    => array( $rogue_owner, 'execute' ),
				'meta'                => array( 'public' => true ),
			),
			'wp-native-builder/rogue-capture'
		);
		$rogue_object = new WP_Native_Builder_Issue44_Ability( 'wp-native-builder/rogue-capture', $args['meta'] );
		$GLOBALS['wpnb_issue44_abilities']['wp-native-builder/rogue-capture'] = $rogue_object;
	},
	array( $rogue_owner )
);
wpnb_issue44_assert(
	! $delegation->is_bridge_owned_ability( $rogue_object ),
	'A separately constructed delegation instance poisoned authoritative Bridge ownership.'
);

$provider_allowed = true;
$provider_checks  = 0;
$adapter_args     = $delegation->filter_ability_args(
	array(
		'permission_callback' => static function ( $input ) use ( &$provider_allowed, &$provider_checks ) {
			++$provider_checks;
			return $provider_allowed;
		},
	),
	Native_Ability_Delegation::ADAPTER_EXECUTE_ABILITY
);
$adapter_permission = $adapter_args['permission_callback'];

$GLOBALS['wpnb_issue44_abilities']['vendor/late-provider'] = new WP_Native_Builder_Issue44_Custom_Ability(
	'vendor/late-provider',
	array(
		'public'      => true,
		'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
	)
);

wpnb_issue44_assert(
	true === $adapter_permission( array( 'ability_name' => 'vendor/late-provider', 'parameters' => array() ) ),
	'Ordinary direct Adapter permission was changed outside a Bridge MCP request.'
);

$invoke = static function ( $route, $permission, $ability_name ) use ( $delegation ) {
	$endpoints = $delegation->filter_rest_endpoints(
		array(
			$route => array(
				array(
					'callback' => static function () use ( $permission, $ability_name ) {
						return $permission( array( 'ability_name' => $ability_name, 'parameters' => array() ) );
					},
				),
			),
		)
	);
	return $endpoints[ $route ][0]['callback']( null );
};

foreach ( array( '/wp-ai-bridge/v1/mcp', '/wp-native-builder/v1/mcp' ) as $route ) {
	$result = $invoke( $route, $adapter_permission, 'vendor/late-provider' );
	wpnb_issue44_assert( $result instanceof WP_Error && 'wp_ai_bridge_native_abilities_disabled' === $result->get_error_code(), 'Native provider execution bypassed the disabled group on ' . $route . '.' );
	$result = $invoke( $route, $adapter_permission, 'wp-native-builder/reentrant-provider' );
	wpnb_issue44_assert( $result instanceof WP_Error && 'wp_ai_bridge_native_abilities_disabled' === $result->get_error_code(), 'Re-entrant provider registration bypassed the disabled group on ' . $route . '.' );
	$result = $invoke( $route, $adapter_permission, 'wp-native-builder/nested-capture-provider' );
	wpnb_issue44_assert( $result instanceof WP_Error && 'wp_ai_bridge_native_abilities_disabled' === $result->get_error_code(), 'Nested capture provider bypassed the disabled group on ' . $route . '.' );
}

$result = $invoke( '/wp-ai-bridge/v1/mcp', $adapter_permission, 'wp-native-builder/fixture' );
wpnb_issue44_assert( true === $result, 'Bridge-owned Ability incorrectly required Native Abilities.' );
$result = $invoke( '/wp-ai-bridge/v1/mcp', $adapter_permission, 'wp-native-builder/forged-prefix' );
wpnb_issue44_assert( $result instanceof WP_Error, 'Third-party Ability escaped the gate through namespace/metadata forgery.' );

$GLOBALS['wpnb_issue44_options'][ Settings::OPTION_NAME ][ Settings::GROUP_NATIVE_ABILITIES ] = 1;
$result = $invoke( '/wp-ai-bridge/v1/mcp', $adapter_permission, 'vendor/late-provider' );
wpnb_issue44_assert( true === $result, 'Enabled Native Abilities did not delegate to the provider permission callback.' );
$provider_allowed = false;
$result = $invoke( '/wp-ai-bridge/v1/mcp', $adapter_permission, 'vendor/late-provider' );
wpnb_issue44_assert( false === $result, 'Bridge delegation widened an explicit provider denial.' );
$provider_allowed = true;
$GLOBALS['wpnb_issue44_options'][ Settings::OPTION_NAME ][ Settings::GROUP_NATIVE_ABILITIES ] = 0;
$result = $invoke( '/wp-ai-bridge/v1/mcp', $adapter_permission, 'vendor/late-provider' );
wpnb_issue44_assert( $result instanceof WP_Error, 'Disabling Native Abilities did not revoke subsequent Bridge execution immediately.' );

$unrelated_called = false;
$unrelated        = static function () use ( &$unrelated_called ) { $unrelated_called = true; return 'unrelated'; };
$filtered         = $delegation->filter_rest_endpoints( array( '/unrelated/v1/route' => array( array( 'callback' => $unrelated ) ) ) );
wpnb_issue44_assert( $unrelated === $filtered['/unrelated/v1/route'][0]['callback'], 'Unrelated REST endpoint callback was wrapped.' );

$throwing = $delegation->filter_rest_endpoints(
	array(
		'/wp-ai-bridge/v1/mcp' => array(
			array(
				'callback' => static function () { throw new RuntimeException( 'fixture' ); },
			),
		),
	)
);
try {
	$throwing['/wp-ai-bridge/v1/mcp'][0]['callback']( null );
} catch ( RuntimeException $exception ) {
	wpnb_issue44_assert( 'fixture' === $exception->getMessage(), 'Bridge route wrapper changed the thrown exception.' );
}
wpnb_issue44_assert(
	true === $adapter_permission( array( 'ability_name' => 'vendor/late-provider', 'parameters' => array() ) ),
	'Bridge request context leaked after an exception.'
);

$GLOBALS['wpnb_issue44_options'][ Settings::OPTION_NAME ] = array(
	Settings::GROUP_SITE_READ        => 1,
	Settings::GROUP_NATIVE_ABILITIES => 0,
);
$catalog        = new Ability_Catalog_Abilities( new Ability_Resolver(), new Permissions( $settings ), $delegation );
$catalog_result = $catalog->read( array( 'action' => 'list', 'page' => 1, 'per_page' => 100 ) );
wpnb_issue44_assert( is_array( $catalog_result ) && 'not_evaluated' === $catalog_result['execution_permission'], 'Ability discovery evaluated execution permission.' );
$delegation_by_name = array();
foreach ( $catalog_result['items'] as $item ) {
	$delegation_by_name[ $item['name'] ] = $item['bridge_delegation'];
}
wpnb_issue44_assert( 'native_abilities' === ( $delegation_by_name['vendor/late-provider'] ?? null ), 'Provider discovery did not report the Native Abilities delegation requirement.' );
wpnb_issue44_assert( 'native_abilities' === ( $delegation_by_name['wp-native-builder/forged-prefix'] ?? null ), 'Forged namespace/metadata was misclassified as Bridge-owned.' );
wpnb_issue44_assert( 'native_abilities' === ( $delegation_by_name['wp-native-builder/reentrant-provider'] ?? null ), 'Re-entrant provider registration was misclassified as Bridge-owned.' );
wpnb_issue44_assert( 'native_abilities' === ( $delegation_by_name['wp-native-builder/nested-capture-provider'] ?? null ), 'Nested capture provider was misclassified as Bridge-owned.' );
wpnb_issue44_assert( 'native_abilities' === ( $delegation_by_name['wp-native-builder/rogue-capture'] ?? null ), 'Separate delegation instance altered authoritative discovery ownership.' );
wpnb_issue44_assert( 'ability_specific' === ( $delegation_by_name['wp-native-builder/fixture'] ?? null ), 'Bridge-owned discovery did not retain ability-specific delegation.' );

if ( $failures ) {
	fwrite( STDERR, "{$failures} of {$tests} Issue #44 assertions failed.\n" );
	exit( 1 );
}

echo "PASS: {$tests} Issue #44 native Ability delegation assertions.\n";
