<?php
/**
 * Public registered-setting schema redaction regression for Issue #56.
 *
 * @package WP_AI_Bridge
 */

require_once __DIR__ . '/../src/Abilities/class-registered-settings-abilities.php';

use WP_AI_Bridge\Abilities\Registered_Settings_Abilities;

function wpai_issue56_public_schema_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$reflection = new ReflectionClass( Registered_Settings_Abilities::class );
$provider   = $reflection->newInstanceWithoutConstructor();
$method     = $reflection->getMethod( 'public_schema' );

$schema = array(
	'type'       => 'object',
	'default'    => array( 'default' => 'runtime-default-must-not-leak' ),
	'example'    => array( 'default' => 'runtime-example-must-not-leak' ),
	'properties' => array(
		'default' => array(
			'type'    => 'string',
			'default' => 'nested-runtime-default-must-not-leak',
		),
		'example' => array(
			'type'    => 'string',
			'example' => 'nested-runtime-example-must-not-leak',
		),
		'mode'    => array(
			'type' => 'string',
			'enum' => array( 'default', 'example', 'custom' ),
		),
	),
);

$public = $method->invoke( $provider, $schema );

wpai_issue56_public_schema_assert( ! array_key_exists( 'default', $public ), 'Top-level runtime default leaked through public schema.' );
wpai_issue56_public_schema_assert( ! array_key_exists( 'example', $public ), 'Top-level runtime example leaked through public schema.' );
wpai_issue56_public_schema_assert( isset( $public['properties']['default'] ), 'Legitimate property named default was removed from public schema.' );
wpai_issue56_public_schema_assert( isset( $public['properties']['example'] ), 'Legitimate property named example was removed from public schema.' );
wpai_issue56_public_schema_assert( ! array_key_exists( 'default', $public['properties']['default'] ), 'Nested schema-level default leaked through public schema.' );
wpai_issue56_public_schema_assert( ! array_key_exists( 'example', $public['properties']['example'] ), 'Nested schema-level example leaked through public schema.' );
wpai_issue56_public_schema_assert( array( 'default', 'example', 'custom' ) === $public['properties']['mode']['enum'], 'Ordinary enum values were rewritten during schema redaction.' );

echo "PASS: Issue #56 public schema redaction.\n";
