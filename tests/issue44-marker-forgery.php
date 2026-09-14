<?php
/**
 * Focused regression for Issue #44 Bridge ownership-marker forgery.
 *
 * @package WP_Native_Builder_Bridge
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/wp/' );

$GLOBALS['wpnb_issue44_marker_options']   = array();
$GLOBALS['wpnb_issue44_marker_abilities'] = array();
$GLOBALS['wpnb_issue44_marker_caps']      = array( 'read' => true );

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function __( $text, $domain = null ) {
	return $text;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wpnb_issue44_marker_options'] )
		? $GLOBALS['wpnb_issue44_marker_options'][ $name ]
		: $default;
}

function wp_get_ability( $name ) {
	return $GLOBALS['wpnb_issue44_marker_abilities'][ $name ] ?? null;
}

function wp_get_abilities() {
	return array_values( $GLOBALS['wpnb_issue44_marker_abilities'] );
}

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['wpnb_issue44_marker_caps'][ $capability ] );
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

function wpnb_issue44_marker_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

final class WP_AI_Bridge_Issue44_Marker_Ability {
	private $name;
	private $meta;

	public function __construct( $name, array $meta ) {
		$this->name = (string) $name;
		$this->meta = $meta;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_label() {
		return $this->name;
	}

	public function get_description() {
		return 'Issue 44 marker-forgery fixture.';
	}

	public function get_category() {
		return 'issue44';
	}

	public function get_input_schema() {
		return array( 'type' => 'object', 'properties' => array() );
	}

	public function get_output_schema() {
		return array( 'type' => 'object', 'properties' => array() );
	}

	public function get_meta() {
		return $this->meta;
	}
}

$settings   = new Settings();
$delegation = new Native_Ability_Delegation( $settings );
$GLOBALS['wpnb_issue44_marker_options'][ Settings::OPTION_NAME ] = array(
	Settings::GROUP_SITE_READ        => 1,
	Settings::GROUP_NATIVE_ABILITIES => 0,
);

$forged_args = $delegation->filter_ability_args(
	array(
		'permission_callback' => static function () {
			return true;
		},
		'execute_callback'    => static function () {
			return true;
		},
		'meta'                => array(
			'public'                                      => true,
			Native_Ability_Delegation::BRIDGE_OWNED_META => true,
		),
	),
	'wp-native-builder/marker-forged-provider'
);

wpnb_issue44_marker_assert(
	empty( $forged_args['meta'][ Native_Ability_Delegation::BRIDGE_OWNED_META ] ),
	'Provider-supplied Bridge ownership marker was not stripped.'
);

$forged_ability = new WP_AI_Bridge_Issue44_Marker_Ability(
	'wp-native-builder/marker-forged-provider',
	$forged_args['meta']
);
$GLOBALS['wpnb_issue44_marker_abilities']['wp-native-builder/marker-forged-provider'] = $forged_ability;

wpnb_issue44_marker_assert(
	! Native_Ability_Delegation::is_bridge_owned_ability( $forged_ability ),
	'Provider-supplied marker was accepted as Bridge ownership.'
);

$adapter_args = $delegation->filter_ability_args(
	array(
		'permission_callback' => static function () {
			return true;
		},
	),
	Native_Ability_Delegation::ADAPTER_EXECUTE_ABILITY
);
$adapter_permission = $adapter_args['permission_callback'];
$endpoints          = $delegation->filter_rest_endpoints(
	array(
		'/wp-ai-bridge/v1/mcp' => array(
			array(
				'callback' => static function () use ( $adapter_permission ) {
					return $adapter_permission(
						array(
							'ability_name' => 'wp-native-builder/marker-forged-provider',
							'parameters'   => array(),
						)
					);
				},
			),
		),
	)
);
$execution_result   = $endpoints['/wp-ai-bridge/v1/mcp'][0]['callback']( null );

wpnb_issue44_marker_assert(
	$execution_result instanceof WP_Error
		&& 'wp_ai_bridge_native_abilities_disabled' === $execution_result->get_error_code(),
	'Forged ownership marker bypassed disabled Native Abilities execution policy.'
);

$catalog        = new Ability_Catalog_Abilities( new Ability_Resolver(), new Permissions( $settings ) );
$catalog_result = $catalog->read(
	array(
		'action'   => 'list',
		'page'     => 1,
		'per_page' => 100,
	)
);
$bridge_delegation = null;
foreach ( $catalog_result['items'] as $item ) {
	if ( 'wp-native-builder/marker-forged-provider' === $item['name'] ) {
		$bridge_delegation = $item['bridge_delegation'];
		break;
	}
}

wpnb_issue44_marker_assert(
	'native_abilities' === $bridge_delegation,
	'Discovery misclassified a provider-forged marker as ability-specific Bridge policy.'
);

if ( $failures ) {
	fwrite( STDERR, "{$failures} of {$tests} Issue #44 marker-forgery assertions failed.\n" );
	exit( 1 );
}

echo "PASS: {$tests} Issue #44 ownership-marker forgery assertions.\n";
