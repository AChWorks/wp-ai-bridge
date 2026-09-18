<?php
/**
 * Test-only public Ability registry fixtures. Never included in the release ZIP.
 * Installed only inside the isolated catalog integration test environment.
 */
$GLOBALS['wpai_catalog_permission_calls'] = 0;
$GLOBALS['wpai_catalog_execute_calls'] = 0;
add_action( 'wp_abilities_api_categories_init', static function () {
	wp_register_ability_category( 'catalog-fixture', array( 'label' => 'Catalog fixture', 'description' => 'Integration-only registry fixture.' ) );
} );
add_action( 'wp_abilities_api_init', static function () {
	$base = array(
		'label' => 'Catalog fixture',
		'description' => 'Public contract for a test-only unknown provider.',
		'category' => 'catalog-fixture',
		'input_schema' => array( 'type' => 'object', 'properties' => array( 'target' => array( 'type' => 'integer' ) ), 'additionalProperties' => false ),
		'output_schema' => array( 'type' => 'string' ),
		'permission_callback' => static function () { ++$GLOBALS['wpai_catalog_permission_calls']; return false; },
		'execute_callback' => static function () { ++$GLOBALS['wpai_catalog_execute_calls']; return 'This operation must not execute during inspection.'; },
		'meta' => array( 'mcp' => array( 'public' => true, 'type' => 'tool' ), 'private_configuration' => 'CATALOG_PRIVATE_METADATA_MUST_NOT_LEAK' ),
	);
	for ( $i = 136; $i >= 0; --$i ) {
		wp_register_ability( sprintf( 'catalog-fixture/operation-%03d', $i ), $base );
	}

	$public_fallback = $base;
	$public_fallback['input_schema'] = array(
		'type'                 => 'object',
		'properties'           => array( 'probe' => array( 'type' => 'string' ) ),
		'additionalProperties' => false,
	);
	$public_fallback['output_schema'] = array(
		'type'                 => 'object',
		'properties'           => array(
			'ok'    => array( 'type' => 'boolean' ),
			'probe' => array( 'type' => 'string' ),
		),
		'required'             => array( 'ok', 'probe' ),
		'additionalProperties' => false,
	);
	$public_fallback['permission_callback'] = static function () { return current_user_can( 'read' ); };
	$public_fallback['execute_callback'] = static function ( $input ) {
		return array(
			'ok'    => true,
			'probe' => isset( $input['probe'] ) ? (string) $input['probe'] : '',
		);
	};
	$public_fallback['meta'] = array(
		'public'      => true,
		'annotations' => array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		),
	);
	wp_register_ability( 'catalog-public-fallback/operation', $public_fallback );

	$hidden = $base;
	$hidden['meta'] = array( 'public' => true, 'mcp' => array( 'public' => false ) );
	wp_register_ability( 'catalog-hidden/optout', $hidden );
	$hidden['meta'] = array( 'public' => false );
	wp_register_ability( 'catalog-hidden/private', $hidden );
	$hidden['meta'] = array( 'public' => true, 'mcp' => 'malformed' );
	wp_register_ability( 'catalog-hidden/malformed', $hidden );
	$hidden['meta'] = array( 'public' => 'true' );
	wp_register_ability( 'catalog-hidden/string-public', $hidden );
	$empty = $base;
	unset( $empty['input_schema'], $empty['output_schema'] );
	wp_register_ability( 'catalog-empty/schema', $empty );
	$large = $base;
	$large['input_schema']['description'] = str_repeat( 'x', 1048576 );
	wp_register_ability( 'catalog-large/schema', $large );
} );
