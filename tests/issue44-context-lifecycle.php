<?php
/**
 * Focused Issue #44 request-context lifecycle regressions.
 *
 * @package WP_Native_Builder_Bridge
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/wp/' );

$GLOBALS['wpnb_issue44_context_options']   = array();
$GLOBALS['wpnb_issue44_context_abilities'] = array();

class WP_Error {
	private $code;

	public function __construct( $code = '', $message = '' ) {
		$this->code = (string) $code;
	}

	public function get_error_code() {
		return $this->code;
	}
}

function __( $text, $domain = null ) {
	return $text;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wpnb_issue44_context_options'] )
		? $GLOBALS['wpnb_issue44_context_options'][ $name ]
		: $default;
}

function wp_get_ability( $name ) {
	return $GLOBALS['wpnb_issue44_context_abilities'][ $name ] ?? null;
}

require dirname( __DIR__ ) . '/src/Support/class-settings.php';
require dirname( __DIR__ ) . '/src/Support/class-native-ability-delegation.php';

use WP_Native_Builder_Bridge\Support\Native_Ability_Delegation;
use WP_Native_Builder_Bridge\Support\Settings;

$failures = 0;
$tests    = 0;

function wpnb_issue44_context_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

final class WP_AI_Bridge_Issue44_Context_Ability {
	public function get_meta() {
		return array( 'public' => true );
	}
}

$settings   = new Settings();
$delegation = new Native_Ability_Delegation( $settings );
$GLOBALS['wpnb_issue44_context_options'][ Settings::OPTION_NAME ] = array(
	Settings::GROUP_NATIVE_ABILITIES => 0,
);
$GLOBALS['wpnb_issue44_context_abilities']['issue44/context-provider'] = new WP_AI_Bridge_Issue44_Context_Ability();

$adapter_args = $delegation->filter_ability_args(
	array(
		'permission_callback' => static function () {
			return true;
		},
	),
	Native_Ability_Delegation::ADAPTER_EXECUTE_ABILITY
);
$permission = $adapter_args['permission_callback'];
$input      = array(
	'ability_name' => 'issue44/context-provider',
	'parameters'   => array(),
);

wpnb_issue44_context_assert( true === $permission( $input ), 'Direct Adapter permission unexpectedly inherited Bridge context.' );

$batch = $delegation->filter_rest_endpoints(
	array(
		'/wp-ai-bridge/v1/mcp' => array(
			array(
				'callback' => static function () use ( $permission, $input ) {
					return array( $permission( $input ), $permission( $input ) );
				},
			),
		),
	)
);
$batch_results = $batch['/wp-ai-bridge/v1/mcp'][0]['callback']( null );
wpnb_issue44_context_assert(
	2 === count( $batch_results )
		&& $batch_results[0] instanceof WP_Error
		&& $batch_results[1] instanceof WP_Error,
	'Batch-style execution did not retain Bridge context for every item.'
);
wpnb_issue44_context_assert( true === $permission( $input ), 'Bridge context leaked after batch-style callback completion.' );

$inner = $delegation->filter_rest_endpoints(
	array(
		'/wp-native-builder/v1/mcp' => array(
			array(
				'callback' => static function () use ( $permission, $input ) {
					return $permission( $input );
				},
			),
		),
	)
);
$inner_callback = $inner['/wp-native-builder/v1/mcp'][0]['callback'];
$outer          = $delegation->filter_rest_endpoints(
	array(
		'/wp-ai-bridge/v1/mcp' => array(
			array(
				'callback' => static function () use ( $inner_callback, $permission, $input ) {
					$inner_result = $inner_callback( null );
					return array( $inner_result, $permission( $input ) );
				},
			),
		),
	)
);
$nested_results = $outer['/wp-ai-bridge/v1/mcp'][0]['callback']( null );
wpnb_issue44_context_assert(
	$nested_results[0] instanceof WP_Error && $nested_results[1] instanceof WP_Error,
	'Nested Bridge callback lost the outer Bridge request context.'
);
wpnb_issue44_context_assert( true === $permission( $input ), 'Bridge context leaked after nested callback completion.' );

$throwing = $delegation->filter_rest_endpoints(
	array(
		'/wp-ai-bridge/v1/mcp' => array(
			array(
				'callback' => static function () {
					throw new RuntimeException( 'issue44-context-fixture' );
				},
			),
		),
	)
);
try {
	$throwing['/wp-ai-bridge/v1/mcp'][0]['callback']( null );
} catch ( RuntimeException $exception ) {
	wpnb_issue44_context_assert(
		'issue44-context-fixture' === $exception->getMessage(),
		'Bridge wrapper changed an exception while unwinding context.'
	);
}
wpnb_issue44_context_assert( true === $permission( $input ), 'Bridge context leaked after exception unwinding.' );

if ( $failures ) {
	fwrite( STDERR, "{$failures} of {$tests} Issue #44 context-lifecycle assertions failed.\n" );
	exit( 1 );
}

echo "PASS: {$tests} Issue #44 request-context lifecycle assertions.\n";
