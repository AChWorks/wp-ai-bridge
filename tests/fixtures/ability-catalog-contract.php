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
