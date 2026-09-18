<?php
/** Real WordPress and Adapter contract-inspection tests. */
use WP_AI_Bridge\Support\Settings;

function wpai_issue42_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$user = get_current_user_id();
try {
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	$catalog = wp_get_ability( 'wp-ai-bridge/abilities-read' );
	wpai_issue42_assert( $catalog instanceof WP_Ability, 'Missing native catalog Ability.' );
	wpai_issue42_assert( wp_get_ability( 'catalog-fixture/operation-136' ) instanceof WP_Ability, 'Isolated catalog fixture was not registered.' );
	$first = $catalog->execute( array( 'namespace' => 'catalog-fixture', 'per_page' => 100 ) );
	wpai_issue42_assert( ! is_wp_error( $first ), 'Catalog list failed native input/output validation.' );
	wpai_issue42_assert( 137 === $first['total'] && 100 === count( $first['items'] ) && 2 === $first['total_pages'], 'Native catalog pagination is incorrect.' );
	wpai_issue42_assert( 'catalog-fixture/operation-000' === $first['items'][0]['name'], 'Catalog ordering must be independent of registration order.' );
	wpai_issue42_assert( 'not_evaluated' === $first['execution_permission'], 'Discovery must not claim permission.' );
	wpai_issue42_assert( ! isset( $first['items'][0]['input_schema'] ), 'List must not dump all schemas.' );
	$second = $catalog->execute( array( 'namespace' => 'catalog-fixture', 'per_page' => 100, 'page' => 2 ) );
	wpai_issue42_assert( 37 === count( $second['items'] ) && 'catalog-fixture/operation-100' === $second['items'][0]['name'], 'Second page did not reach the remainder of the native registry.' );
	$filtered = $catalog->execute( array( 'namespace' => 'catalog-fixture', 'search' => 'OPERATION-13' ) );
	wpai_issue42_assert( 7 === $filtered['total'], 'Search and namespace filters failed.' );
	$empty_page = $catalog->execute( array( 'namespace' => 'catalog-fixture', 'page' => PHP_INT_MAX ) );
	wpai_issue42_assert( array() === $empty_page['items'], 'Very large page must be empty and not overflow.' );
	$detail = $catalog->execute( array( 'action' => 'get', 'name' => 'catalog-fixture/operation-125' ) );
	wpai_issue42_assert( ! is_wp_error( $detail ), 'Exact schema lookup failed native output validation.' );
	$provider = wp_get_ability( 'catalog-fixture/operation-125' );
	wpai_issue42_assert( $provider->get_input_schema() === $detail['items'][0]['input_schema'], 'Native input schema was changed.' );
	wpai_issue42_assert( $provider->get_output_schema() === $detail['items'][0]['output_schema'], 'Native output schema was changed.' );
	wpai_issue42_assert( false === strpos( wp_json_encode( $detail ), 'CATALOG_PRIVATE_METADATA_MUST_NOT_LEAK' ), 'Arbitrary provider metadata leaked.' );
	$empty_schema = $catalog->execute( array( 'action' => 'get', 'name' => 'catalog-empty/schema' ) );
	wpai_issue42_assert( ! is_wp_error( $empty_schema ) && array() === $empty_schema['items'][0]['input_schema'] && array() === $empty_schema['items'][0]['output_schema'], 'Absent native schemas were not preserved.' );
	$large_schema = $catalog->execute( array( 'action' => 'get', 'name' => 'catalog-large/schema' ) );
	wpai_issue42_assert( is_wp_error( $large_schema ) && 'ability_catalog_response_too_large' === $large_schema->get_error_code(), 'Oversized contract must fail rather than truncate.' );
	foreach ( array( 'catalog-hidden/malformed', 'catalog-hidden/string-public', 'catalog-hidden/optout', 'catalog-fixture/operation-000' ) as $probe_name ) {
		$probe = wp_get_ability( $probe_name );
		if ( null === $probe ) {
			// Newer Core rejects non-boolean meta.public during registration itself.
			// This is an earlier native denial, not permission to skip hidden-contract tests.
			wpai_issue42_assert( 'catalog-hidden/string-public' === $probe_name, 'A valid exposure fixture unexpectedly failed to register.' );
			wpai_issue42_assert( is_wp_error( $catalog->execute( array( 'action' => 'get', 'name' => $probe_name ) ) ), 'A Core-rejected contract must remain unavailable through the Bridge.' );
			continue;
		}
		$native_public = \WP\MCP\Abilities\McpAbilityExposure::is_public( $probe );
		$bridge_public = ( new \WP_AI_Bridge\Abilities\Ability_Resolver() )->is_mcp_exposed( $probe );
		wpai_issue42_assert( $native_public === $bridge_public, 'Bridge exposure must match the pinned Adapter on the actual registered object.' );
	}
	$public_fallback = wp_get_ability( 'catalog-public-fallback/operation' );
	wpai_issue42_assert( $public_fallback instanceof WP_Ability, 'Public fallback fixture was not registered.' );
	wpai_issue42_assert( \WP\MCP\Abilities\McpAbilityExposure::is_public( $public_fallback ), 'Pinned Adapter did not expose meta.public fallback fixture.' );
	wpai_issue42_assert( ( new \WP_AI_Bridge\Abilities\Ability_Resolver() )->is_mcp_exposed( $public_fallback ), 'Bridge did not follow the active Adapter exposure resolver.' );
	$public_fallback_contract = $catalog->execute( array( 'action' => 'get', 'name' => 'catalog-public-fallback/operation' ) );
	wpai_issue42_assert( ! is_wp_error( $public_fallback_contract ), 'Catalog omitted an Ability the active Adapter exposes.' );

	$hidden_list = $catalog->execute( array( 'namespace' => 'catalog-hidden' ) );
	wpai_issue42_assert( 0 === $hidden_list['total'], 'Hidden provider contracts leaked in list.' );
	foreach ( array( 'catalog-hidden/private', 'catalog-hidden/optout', 'catalog-hidden/malformed', 'catalog-hidden/string-public', 'catalog-missing/name' ) as $name ) {
		$result = $catalog->execute( array( 'action' => 'get', 'name' => $name ) );
		wpai_issue42_assert( is_wp_error( $result ) && 'ability_contract_not_found' === $result->get_error_code(), 'Hidden and unknown names must have the same error.' );
	}
	foreach ( array( array( 'per_page' => 101 ), array( 'page' => 0 ), array( 'action' => 'execute' ), array( 'unknown' => true ), array( 'action' => 'get' ) ) as $input ) {
		wpai_issue42_assert( is_wp_error( $catalog->execute( $input ) ), 'Malformed native catalog input must fail.' );
	}
	$adapter_info = wp_get_ability( 'mcp-adapter/get-ability-info' )->execute( array( 'ability_name' => 'wp-ai-bridge/abilities-read' ) );
	wpai_issue42_assert( ! is_wp_error( $adapter_info ) && 'wp-ai-bridge/abilities-read' === $adapter_info['name'], 'Official Adapter could not inspect the catalog contract.' );
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	$wrapped = $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/abilities-read', 'parameters' => array( 'namespace' => 'catalog-fixture', 'page' => 2, 'per_page' => 100 ) ) );
	wpai_issue42_assert( ! is_wp_error( $wrapped ) && true === $wrapped['success'] && 37 === count( $wrapped['data']['items'] ), 'Official Adapter execution did not preserve the catalog response.' );
	$public_fallback_execution = $adapter->execute(
		array(
			'ability_name' => 'catalog-public-fallback/operation',
			'parameters'   => array( 'probe' => 'issue81' ),
		)
	);
	wpai_issue42_assert(
		! is_wp_error( $public_fallback_execution )
		&& true === ( $public_fallback_execution['success'] ?? false )
		&& true === ( $public_fallback_execution['data']['ok'] ?? false )
		&& 'issue81' === ( $public_fallback_execution['data']['probe'] ?? '' ),
		'Catalog advertised an Ability that the active Adapter execution path could not invoke.'
	);
	wpai_issue42_assert( 0 === $GLOBALS['wpai_catalog_permission_calls'] && 0 === $GLOBALS['wpai_catalog_execute_calls'], 'Catalog invoked a target callback during inspection.' );
	$provider_denial = $provider->execute( array( 'target' => 1 ) );
	wpai_issue42_assert( is_wp_error( $provider_denial ) && $GLOBALS['wpai_catalog_permission_calls'] > 0 && 0 === $GLOBALS['wpai_catalog_execute_calls'], 'Discovery must not change a provider execution denial.' );
	$off = $settings->defaults();
	$off[ Settings::GROUP_SITE_READ ] = 0;
	update_option( Settings::OPTION_NAME, $off, false );
	wpai_issue42_assert( is_wp_error( $catalog->execute( array() ) ), 'Disabled Site Read must deny inspection.' );
	wpai_issue42_assert( is_wp_error( $adapter->execute( array( 'ability_name' => 'wp-ai-bridge/abilities-read', 'parameters' => array() ) ) ), 'Adapter bypassed revoked Site Read.' );
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	wp_set_current_user( 0 );
	wpai_issue42_assert( is_wp_error( $catalog->execute( array() ) ), 'Anonymous caller could inspect the public registry through the Bridge.' );
	echo "PASS: Ability catalog native registry, privacy, pagination, permissions, and Adapter integration.\n";
} finally {
	wp_set_current_user( $user );
	update_option( Settings::OPTION_NAME, $original, false );
}
