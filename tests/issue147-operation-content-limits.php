<?php
/**
 * #147 bounded content budgets: independent native settings, fail-closed input,
 * unchanged defaults, actual source/registered-setting/catalog enforcement.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Ability_Catalog_Abilities;
use WP_AI_Bridge\Abilities\Ability_Resolver;
use WP_AI_Bridge\Abilities\Registered_Settings_Abilities;
use WP_AI_Bridge\Abilities\Source_Editing_Abilities;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Operation_Content_Limits;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

$tests = 0;
$assert = static function ( $passed, $reason ) use ( &$tests ) {
	++$tests;
	if ( ! $passed ) {
		throw new RuntimeException( $reason );
	}
};

wpai_test_reset_state();
$policy   = new Operation_Content_Limits();
$defaults = $policy->defaults();
$assert( 2 * 1048576 === $policy->bytes( Operation_Content_Limits::SOURCE_MIB ), 'Existing source safety default changed.' );
$assert( 1048576 === $policy->bytes( Operation_Content_Limits::SETTING_VALUE_KIB ), 'Registered setting default changed.' );
$assert( 262144 === $policy->bytes( Operation_Content_Limits::SETTING_SCHEMA_KIB ), 'Registered schema default changed.' );
$assert( 1048576 === $policy->bytes( Operation_Content_Limits::CATALOG_KIB ), 'Public catalog response default changed.' );
$policy->register();
$setting = $GLOBALS['wpai_test']['registered_settings'][ Operation_Content_Limits::OPTION_NAME ];
$assert( Operation_Content_Limits::OPTION_GROUP === $setting['group'] && false === $setting['args']['show_in_rest'] && 'array' === $setting['args']['type'], 'Independent site-private native Settings API registration missing.' );
$maxima = $policy->upper_bounds();
$assert( $maxima[ Operation_Content_Limits::SOURCE_MIB ] >= 4, 'Test PHP host cannot accommodate a 4 MiB source with bounded recovery copies.' );
$assert( $maxima[ Operation_Content_Limits::SETTING_VALUE_KIB ] >= 2048, 'Test PHP host cannot accommodate bounded 2 MiB settings.' );
$updated = $policy->sanitize(
	array(
		Operation_Content_Limits::SOURCE_MIB        => '4',
		Operation_Content_Limits::SETTING_VALUE_KIB => '2048',
		Operation_Content_Limits::SETTING_SCHEMA_KIB => '512',
		Operation_Content_Limits::CATALOG_KIB       => '2048',
	)
);
$GLOBALS['wpai_test']['options'][ Operation_Content_Limits::OPTION_NAME ] = $updated;
$assert( 4 * 1048576 === $policy->bytes( Operation_Content_Limits::SOURCE_MIB ), 'Source budget could not rise past the old 2 MiB.' );
$assert( 2 * 1048576 === $policy->bytes( Operation_Content_Limits::SETTING_VALUE_KIB ), 'Registered value budget could not rise past 1 MiB.' );
$assert( 512 * 1024 === $policy->bytes( Operation_Content_Limits::SETTING_SCHEMA_KIB ), 'Schema policy could not rise past 256 KiB.' );
$assert( 2 * 1048576 === $policy->bytes( Operation_Content_Limits::CATALOG_KIB ), 'Catalog policy could not rise past 1 MiB.' );

$bad = array( '', '0', '-1', '+4', ' 4', '04', '4.0', '4e0', true, null, 4.5, array( 4 ), str_repeat( '9', 50 ), (string) PHP_INT_MAX );
foreach ( $bad as $candidate ) {
	$next = $policy->sanitize( array( Operation_Content_Limits::SOURCE_MIB => $candidate ) );
	$assert( 4 === $next[ Operation_Content_Limits::SOURCE_MIB ], 'Invalid source budget replaced a safe existing policy.' );
}
$next = $policy->sanitize( array( Operation_Content_Limits::SOURCE_MIB => '1' ) );
$assert( 1 === $next[ Operation_Content_Limits::SOURCE_MIB ] && 2048 === $next[ Operation_Content_Limits::CATALOG_KIB ], 'Lowering a source budget changed an independent operation policy.' );
$assert( $updated === $policy->sanitize( 'not-an-array' ), 'Invalid form lost existing settings.' );
$assert( ! isset( $GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] ), 'Changing operation capacity granted unrelated access.' );

$permissions = new Permissions( new Settings() );
$source      = new Source_Editing_Abilities( $permissions, new Mutation_Log() );
$schema      = new ReflectionMethod( Source_Editing_Abilities::class, 'candidate_input_schema' );
$assert( 4 * 1048576 === $schema->invoke( $source, false )['properties']['candidate']['maxLength'], 'Native source schema ignores configured capacity.' );
$validate = new ReflectionMethod( Source_Editing_Abilities::class, 'validate_candidate' );
$piece    = str_repeat( 'a', 3 * 1048576 );
$assert( true === $validate->invoke( $source, array( 'php' => false ), $piece ), 'Safe above-default source candidate was refused.' );
$GLOBALS['wpai_test']['options'][ Operation_Content_Limits::OPTION_NAME ] = $defaults;
$assert( 'source_candidate_too_large' === $validate->invoke( $source, array( 'php' => false ), $piece )->get_error_code(), 'Default source guard stopped enforcing.' );
$GLOBALS['wpai_test']['options'][ Operation_Content_Limits::OPTION_NAME ] = $updated;

// A later administrator reduction may reject NEW source reads, but must
// retain a bounded route to restore an already-authorized durable preimage.
$recovery = new ReflectionMethod( Source_Editing_Abilities::class, 'recovery_read_limit' );
$GLOBALS['wpai_test']['options'][ Operation_Content_Limits::OPTION_NAME ] = $policy->sanitize( array( Operation_Content_Limits::SOURCE_MIB => '1' ) );
$max_host_bytes = $policy->upper_bounds()[ Operation_Content_Limits::SOURCE_MIB ] * 1048576;
$previous = array( 'preimage' => str_repeat( 'b', 2400000 ), 'candidate_bytes' => 3400000 );
$assert( min( $max_host_bytes, 3400000 ) === $recovery->invoke( $source, $previous ), 'Lowering source capacity stranded a previously admitted durable candidate.' );
$assert( 1048576 === $policy->bytes( Operation_Content_Limits::SOURCE_MIB ), 'An existing recovery claim silently raised new source-read admission.' );
$assert( 2097152 === $recovery->invoke( $source, array( 'preimage' => 'legacy' ) ), 'Old-format pre-existing 2 MiB source recovery claim was stranded.' );
$assert( $max_host_bytes === $recovery->invoke( $source, array( 'preimage' => '', 'candidate_bytes' => PHP_INT_MAX ) ), 'Recovery claim bypassed current PHP runtime memory envelope.' );
$GLOBALS['wpai_test']['options'][ Operation_Content_Limits::OPTION_NAME ] = $updated;

$settings_provider = new Registered_Settings_Abilities( $permissions, new Mutation_Log() );
$settings_provider->register();
$native_schema = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/registered-setting-update']['input_schema'];
$assert( 2 * 1048576 === $native_schema['properties']['value_json']['maxLength'], 'Native registered settings schema ignores raised value limit.' );
$decode = new ReflectionMethod( Registered_Settings_Abilities::class, 'decode_value' );
$json   = '"' . str_repeat( 'x', 1050000 ) . '"';
$assert( 1050000 === strlen( $decode->invoke( $settings_provider, $json ) ), 'Above-default registered setting value did not decode safely.' );
$GLOBALS['wpai_test']['options'][ Operation_Content_Limits::OPTION_NAME ] = $defaults;
$assert( 'registered_setting_invalid_value' === $decode->invoke( $settings_provider, $json )->get_error_code(), 'Default JSON size check stopped enforcing.' );
$GLOBALS['wpai_test']['options'][ Operation_Content_Limits::OPTION_NAME ] = $updated;

$catalog = new Ability_Catalog_Abilities( new Ability_Resolver(), $permissions );
$result  = new ReflectionMethod( Ability_Catalog_Abilities::class, 'result' );
$large   = array( array( 'name' => 'test/large', 'input_schema' => array( 'type' => 'string', 'description' => str_repeat( 'x', 1050000 ) ) ) );
$assert( ! is_wp_error( $result->invoke( $catalog, $large, 1, 1, 1 ) ), 'Above-default public contract was refused after operator raised its budget.' );
$GLOBALS['wpai_test']['options'][ Operation_Content_Limits::OPTION_NAME ] = $defaults;
$assert( 'ability_catalog_response_too_large' === $result->invoke( $catalog, $large, 1, 1, 1 )->get_error_code(), 'Catalog safety guard stopped enforcing with default policy.' );
$GLOBALS['wpai_test']['options'][ Operation_Content_Limits::OPTION_NAME ] = $updated;

echo "PASS: #147 {$tests} content-policy setting, legacy, oversized, scope-isolation and real provider-limit assertions.\n";
