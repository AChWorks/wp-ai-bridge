<?php
/**
 * Focused regression for Issue #44 Bridge ownership-provenance forgery.
 *
 * @package WP_AI_Bridge
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/wp/' );

$GLOBALS['wpai_issue44_marker_options']   = array();
$GLOBALS['wpai_issue44_marker_abilities'] = array();
$GLOBALS['wpai_issue44_marker_caps']      = array( 'read' => true );

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
	return array_key_exists( $name, $GLOBALS['wpai_issue44_marker_options'] )
		? $GLOBALS['wpai_issue44_marker_options'][ $name ]
		: $default;
}

function wp_get_ability( $name ) {
	return $GLOBALS['wpai_issue44_marker_abilities'][ $name ] ?? null;
}

function wp_get_abilities() {
	return array_values( $GLOBALS['wpai_issue44_marker_abilities'] );
}

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['wpai_issue44_marker_caps'][ $capability ] );
}

require dirname( __DIR__ ) . '/src/Support/class-settings.php';
require dirname( __DIR__ ) . '/src/Support/class-native-ability-delegation.php';
require dirname( __DIR__ ) . '/src/Support/class-permissions.php';
require dirname( __DIR__ ) . '/src/Abilities/class-ability-resolver.php';
require dirname( __DIR__ ) . '/src/Abilities/class-ability-catalog-abilities.php';

use WP_AI_Bridge\Abilities\Ability_Catalog_Abilities;
use WP_AI_Bridge\Abilities\Ability_Resolver;
use WP_AI_Bridge\Support\Native_Ability_Delegation;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

$failures = 0;
$tests    = 0;

function wpai_issue44_marker_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

final class WP_AI_Bridge_Issue44_Forged_Meta_Ability {
	private $name;
	private $meta_reads = 0;

	public function __construct( $name ) {
		$this->name = (string) $name;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_label() {
		return $this->name;
	}

	public function get_description() {
		return 'Issue 44 provider-controlled virtual metadata fixture.';
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
		++$this->meta_reads;
		return array(
			'public'             => true,
			'wp_ai_bridge_owned' => true,
			'annotations'        => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	public function meta_reads() {
		return $this->meta_reads;
	}
}

$settings   = new Settings();
$delegation = new Native_Ability_Delegation( $settings );
$GLOBALS['wpai_issue44_marker_options'][ Settings::OPTION_NAME ] = array(
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
			'public'             => true,
			'wp_ai_bridge_owned' => true,
		),
	),
	'wp-ai-bridge/forged-provider'
);
wpai_issue44_marker_assert(
	true === ( $forged_args['meta']['wp_ai_bridge_owned'] ?? false ),
	'Provider metadata should remain ordinary untrusted data instead of being rewritten as an ownership protocol.'
);

$forged_ability = new WP_AI_Bridge_Issue44_Forged_Meta_Ability( 'wp-ai-bridge/forged-provider' );
$GLOBALS['wpai_issue44_marker_abilities']['wp-ai-bridge/forged-provider'] = $forged_ability;

wpai_issue44_marker_assert( 0 === $forged_ability->meta_reads(), 'Fixture metadata was read before the ownership check.' );
wpai_issue44_marker_assert(
	! $delegation->is_bridge_owned_ability( $forged_ability ),
	'Provider virtual metadata was accepted as Bridge ownership provenance.'
);
wpai_issue44_marker_assert(
	0 === $forged_ability->meta_reads(),
	'Ownership classification invoked the provider-controlled get_meta() method.'
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
							'ability_name' => 'wp-ai-bridge/forged-provider',
							'parameters'   => array(),
						)
					);
				},
			),
		),
	)
);
$execution_result   = $endpoints['/wp-ai-bridge/v1/mcp'][0]['callback']( null );

wpai_issue44_marker_assert(
	$execution_result instanceof WP_Error
		&& 'wp_ai_bridge_native_abilities_disabled' === $execution_result->get_error_code(),
	'Provider-controlled virtual metadata bypassed disabled Native Abilities execution policy.'
);

$catalog        = new Ability_Catalog_Abilities( new Ability_Resolver(), new Permissions( $settings ), $delegation );
$catalog_result = $catalog->read(
	array(
		'action'   => 'list',
		'page'     => 1,
		'per_page' => 100,
	)
);
$bridge_delegation = null;
foreach ( $catalog_result['items'] as $item ) {
	if ( 'wp-ai-bridge/forged-provider' === $item['name'] ) {
		$bridge_delegation = $item['bridge_delegation'];
		break;
	}
}

wpai_issue44_marker_assert(
	'native_abilities' === $bridge_delegation,
	'Discovery misclassified provider-controlled virtual metadata as ability-specific Bridge provenance.'
);
wpai_issue44_marker_assert(
	$forged_ability->meta_reads() > 0,
	'Catalog fixture did not exercise provider metadata for ordinary public contract inspection.'
);

if ( $failures ) {
	fwrite( STDERR, "{$failures} of {$tests} Issue #44 ownership-provenance assertions failed.\n" );
	exit( 1 );
}

echo "PASS: {$tests} Issue #44 ownership-provenance forgery assertions.\n";
