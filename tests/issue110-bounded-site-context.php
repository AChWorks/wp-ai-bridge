<?php
/**
 * Dependency-free large-site site-context projection, paging and authorization tests.
 *
 * @package WP_AI_Bridge
 */

require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Ability_Resolver;
use WP_AI_Bridge\Abilities\Site_Abilities;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

$assertions = 0;
function wpai110_assert( $condition, $message ) {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function wpai110_ability( $name, $label, $description, $is_public = true ) {
	return new WP_Native_Builder_Test_Ability(
		$name,
		$label,
		$description,
		'test-provider',
		array(),
		array(
			'mcp'           => array( 'public' => $is_public ),
			'private_token' => 'DO_NOT_DISCLOSE',
		)
	);
}

wpai_test_reset_state();
$settings     = new Settings();
$permissions  = new Permissions( $settings );
$site         = new Site_Abilities( new Ability_Resolver(), $permissions );
$registration = $site->register();
$ability      = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/site-context'];
wpai110_assert( 1 === count( $registration ), 'Site-context registration missing.' );
wpai110_assert( ! isset( $ability['input_schema']['properties']['offset']['maximum'] ), 'Pagination must not silently cap discoverable high offsets.' );
wpai110_assert( isset( $ability['output_schema']['properties']['plugins']['items']['properties']['truncated_fields'] ), 'Native schema must describe lossy provider projections.' );
wpai110_assert( isset( $ability['input_schema']['properties']['section'] ) && isset( $ability['output_schema']['properties']['projection'] ), 'Page contract is not discoverable in native Ability schemas.' );

$legacy = $site->execute();
wpai110_assert( ! is_wp_error( $legacy ) && Bounded_Payload::fits( $legacy ), 'Small legacy context failed.' );
wpai110_assert( array( 'site', 'current_user', 'theme', 'plugins', 'post_types', 'taxonomies', 'reuse', 'external_abilities' ) === array_keys( $legacy ), 'Small default output changed shape.' );
wpai110_assert( ! isset( $legacy['projection'] ) && ! isset( $legacy['page'] ), 'Legacy small-site callers received unnecessary metadata.' );

$many_plugins = array();
for ( $i = 0; $i < 230; ++$i ) {
	$filename                  = sprintf( 'long-plugin-%03d/main.php', $i );
	$many_plugins[ $filename ] = array(
		'Name'    => 'Name ' . $i . ' ' . str_repeat( 'نام 🌏', 140 ),
		'Version' => '1.0.' . $i,
	);
}
$GLOBALS['wpai_test']['plugins'] = $many_plugins;
for ( $i = 0; $i < 155; ++$i ) {
	$name                                       = sprintf( 'public-provider/operation-%03d', $i );
	$GLOBALS['wpai_test']['abilities'][ $name ] = wpai110_ability( $name, 'Tool ' . $i, str_repeat( 'Description 🌏 ', 90 ) );
}
$GLOBALS['wpai_test']['abilities']['public-provider/operation-000'] = wpai110_ability( 'public-provider/operation-000', 'Huge label', str_repeat( 'X', 1024 * 1024 ) );
$GLOBALS['wpai_test']['abilities']['private-provider/secret']       = wpai110_ability( 'private-provider/secret', 'Hidden', 'DO_NOT_DISCLOSE', false );
$GLOBALS['wpai_test']['abilities']['wp-ai-bridge/private']          = wpai110_ability( 'wp-ai-bridge/private', 'Own', 'DO_NOT_DISCLOSE' );
$GLOBALS['wpai_test']['abilities']['mcp-adapter/private']           = wpai110_ability( 'mcp-adapter/private', 'Adapter', 'DO_NOT_DISCLOSE' );

$summary = $site->execute();
wpai110_assert( ! is_wp_error( $summary ) && Bounded_Payload::fits( $summary ), 'Oversized default was not bounded.' );
wpai110_assert( array( 'current_user', 'projection' ) === array_keys( $summary ) && 'all' === $summary['projection']['section'], 'Oversized default must retain principal capability summary with explicit omissions.' );
wpai110_assert( in_array( 'plugins', $summary['projection']['omitted_sections'], true ) && in_array( 'external_abilities', $summary['projection']['omitted_sections'], true ), 'Oversized default silently omitted collections.' );
wpai110_assert( array_key_exists( 'install_plugins', $summary['current_user']['capabilities'] ) && false === $summary['current_user']['capabilities']['install_plugins'], 'Summary fabricated native plugin installation authority.' );
$flags = $site->execute( array( 'section' => 'current_user' ) );
wpai110_assert( ! is_wp_error( $flags ) && array( 'current_user' ) === array_keys( $flags ) && Bounded_Payload::fits( $flags ), 'Current native capability flags must remain small, independent of provider count.' );

$offset           = 0;
$observed_plugins = array();
for ( $step = 0; $step < 25; ++$step ) {
	$page = $site->execute(
		array(
			'section' => 'plugins',
			'offset'  => $offset,
			'limit'   => 50,
		)
	);
	wpai110_assert( ! is_wp_error( $page ) && Bounded_Payload::fits( $page ) && strlen( wp_json_encode( $page ) ) <= 30720, 'Plugin page exceeded finite JSON response envelope.' );
	wpai110_assert( 0 < count( $page['plugins'] ) && count( $page['plugins'] ) === $page['page']['returned'], 'Paged plugin selection made no progress.' );
	foreach ( $page['plugins'] as $record ) {
		$observed_plugins[] = $record['file'];
		wpai110_assert( in_array( 'name', $record['truncated_fields'] ?? array(), true ), 'Oversized plugin label was silently truncated.' );
		wpai110_assert( 1 === preg_match( '//u', $record['name'] ), 'Projected string cut across a UTF-8 boundary.' );
	}
	if ( ! $page['page']['has_more'] ) {
		wpai110_assert( ! isset( $page['page']['next_offset'] ), 'Last page fabricated a continuation.' );
		break;
	}
	wpai110_assert( $page['page']['next_offset'] === $offset + count( $page['plugins'] ), 'Next offset incorrectly skipped unseen items.' );
	$offset = $page['page']['next_offset'];
}
wpai110_assert( 230 === count( $observed_plugins ) && 230 === count( array_unique( $observed_plugins ) ), 'Paging lost/duplicated plugin identity.' );
$empty = $site->execute(
	array(
		'section' => 'plugins',
		'offset'  => 600,
		'limit'   => 10,
	)
);
wpai110_assert( ! is_wp_error( $empty ) && array() === $empty['plugins'] && false === $empty['page']['has_more'], 'Out-of-range page should explicitly be empty.' );

$offset       = 0;
$public_names = array();
for ( $step = 0; $step < 20; ++$step ) {
	$page = $site->execute(
		array(
			'section' => 'external_abilities',
			'offset'  => $offset,
			'limit'   => 35,
		)
	);
	wpai110_assert( ! is_wp_error( $page ) && Bounded_Payload::fits( $page ) && count( $page['external_abilities'] ) > 0, 'External provider page failed.' );
	foreach ( $page['external_abilities'] as $record ) {
		$public_names[] = $record['name'];
		wpai110_assert( in_array( 'description', $record['truncated_fields'] ?? array(), true ), 'Very long public provider description was not explicitly projected.' );
		wpai110_assert( ! isset( $record['private_token'] ), 'Private metadata escaped through the public catalog.' );
	}
	if ( ! $page['page']['has_more'] ) {
		break;
	}
	$offset = $page['page']['next_offset'];
}
wpai110_assert( 155 === count( $public_names ) && 155 === count( array_unique( $public_names ) ), 'Page scan did not reach beyond legacy first-50 provider limit.' );
wpai110_assert( ! in_array( 'private-provider/secret', $public_names, true ) && ! in_array( 'wp-ai-bridge/private', $public_names, true ), 'Private/Bridge-only registered Abilities leaked.' );
wpai110_assert( array() === ( new Ability_Resolver() )->public_catalog( 0 ), 'Zero-limit resolver response must be empty.' );

$GLOBALS['wpai_test']['post_types']['mega']   = (object) array(
	'name'         => 'mega',
	'label'        => str_repeat( 'نوع آزمایشی', 1200 ),
	'show_ui'      => true,
	'show_in_rest' => true,
	'rest_base'    => str_repeat( 'base', 400 ),
	'hierarchical' => false,
);
$GLOBALS['wpai_test']['taxonomies']['nested'] = (object) array(
	'name'        => 'nested',
	'label'       => str_repeat( 'Taxon ', 1200 ),
	'object_type' => array_fill( 0, 500, str_repeat( 'type-', 120 ) ),
	'show_ui'     => true,
);
$post_types                                   = $site->execute(
	array(
		'section' => 'post_types',
		'limit'   => 2,
	)
);
$taxonomies                                   = $site->execute(
	array(
		'section' => 'taxonomies',
		'limit'   => 2,
	)
);
wpai110_assert( Bounded_Payload::fits( $post_types ) && in_array( 'label', $post_types['post_types'][0]['truncated_fields'], true ), 'Large post-type label must have loss metadata.' );
wpai110_assert( Bounded_Payload::fits( $taxonomies ) && in_array( 'object_types', $taxonomies['taxonomies'][0]['truncated_fields'], true ) && 16 === count( $taxonomies['taxonomies'][0]['object_types'] ), 'Giant taxonomy object-type list lacks an explicit bound.' );

$GLOBALS['wpai_test']['theme']['Name'] = str_repeat( 'موضوع', 2000 );
$theme                                 = $site->execute( array( 'section' => 'theme' ) );
wpai110_assert( Bounded_Payload::fits( $theme ) && in_array( 'name', $theme['projection']['truncated_fields'], true ), 'Oversized scalar context lacked omission metadata.' );
foreach ( array(
	array( 'section' => 'none' ),
	array( 'offset' => 1 ),
	array(
		'section' => 'current_user',
		'limit'   => 2,
	),
	array(
		'section' => 'plugins',
		'offset'  => -1,
	),
	array(
		'section' => 'plugins',
		'limit'   => 0,
	),
	array(
		'section' => 'plugins',
		'limit'   => 51,
	),
	array(
		'section' => 'plugins',
		'offset'  => '1',
	),
	array(
		'section' => 'external_abilities',
		'unknown' => 1,
	),
) as $bad ) {
	$invalid = $site->execute( $bad );
	wpai110_assert( is_wp_error( $invalid ) && 'site_context_invalid_input' === $invalid->get_error_code(), 'Malformed selected context did not fail.' );
}

$group                              = $settings->defaults();
$group[ Settings::GROUP_SITE_READ ] = 0;
update_option( Settings::OPTION_NAME, $group, false );
foreach ( array( array(), array( 'section' => 'plugins' ), array( 'section' => 'current_user' ) ) as $input ) {
	$denied = $site->execute( $input );
	wpai110_assert( is_wp_error( $denied ) && 'site_context_permission_denied' === $denied->get_error_code(), 'Revoked Site Read leaked paginated or current-principal context.' );
}
update_option( Settings::OPTION_NAME, $settings->defaults(), false );
$GLOBALS['wpai_test']['user_id']                         = 12;
$GLOBALS['wpai_test']['capabilities']['install_plugins'] = true;
$now = $site->execute( array( 'section' => 'current_user' ) );
wpai110_assert( 12 === $now['current_user']['id'] && true === $now['current_user']['capabilities']['install_plugins'], 'New principal did not receive fresh native permission flags.' );
$GLOBALS['wpai_test']['capabilities']['read'] = false;
wpai110_assert(
	is_wp_error(
		$site->execute(
			array(
				'section' => 'plugins',
				'offset'  => 30,
			)
		)
	),
	'Native read revocation was ignored on continuation.'
);

echo "PASS: Issue #110 bounded site-context ({$assertions} assertions).\n";
