<?php
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Ability_Resolver;
use WP_Native_Builder_Bridge\Abilities\Content_Eligibility;
use WP_Native_Builder_Bridge\Abilities\Registrar;
use WP_Native_Builder_Bridge\Admin\Settings_Page;
use WP_Native_Builder_Bridge\Support\Environment;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

$failures = 0;
$tests    = 0;

function wpnb_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function wpnb_test_ability( $name, array $properties = array(), array $meta = array(), $category = 'test' ) {
	return new WP_Native_Builder_Test_Ability(
		$name,
		$name,
		'Test ability ' . $name,
		$category,
		array(
			'type'       => 'object',
			'properties' => $properties,
		),
		$meta
	);
}

$environment = new Environment();
wpnb_assert( false === $environment->abilities_api_available(), 'Abilities API is absent when WP_Ability is unavailable.' );
wpnb_assert( false === $environment->mcp_adapter_available(), 'MCP Adapter is absent when its class is unavailable.' );

eval( 'class WP_Ability {}' );
wpnb_assert( true === $environment->abilities_api_available(), 'Abilities API is detected on the supported WordPress baseline.' );

eval( 'namespace WP\\MCP\\Core; class McpAdapter {}' );
define( 'WP_MCP_VERSION', '0.6.1' );
wpnb_assert( true === $environment->mcp_adapter_available(), 'MCP Adapter class detection works.' );
wpnb_assert( '0.6.1' === $environment->mcp_adapter_version(), 'MCP Adapter version detection uses WP_MCP_VERSION.' );

wpnb_test_reset_state();
$settings = new Settings();
$defaults = $settings->defaults();
wpnb_assert( 1 === $defaults[ Settings::GROUP_SITE_READ ], 'Site Read defaults to enabled.' );
wpnb_assert( 0 === $defaults[ Settings::GROUP_BUILDER_WRITE ], 'Builder Write defaults to disabled.' );
wpnb_assert( 0 === $defaults[ Settings::GROUP_LIVE_CONTENT ], 'Live Content defaults to disabled.' );
wpnb_assert( 0 === $defaults[ Settings::GROUP_SOURCE_EDITING ], 'Source Editing defaults to disabled.' );
$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = array( Settings::GROUP_CODE_EXTENSIONS => 1 );
$upgrade_settings = $settings->all();
wpnb_assert( 1 === $upgrade_settings[ Settings::GROUP_CODE_EXTENSIONS ], 'Existing Code & Extensions consent is preserved on upgrade.' );
wpnb_assert( 0 === $upgrade_settings[ Settings::GROUP_SOURCE_EDITING ], 'Existing Code & Extensions consent does not silently enable Source Editing on upgrade.' );
$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = array();

$sanitized = $settings->sanitize(
	array(
		Settings::GROUP_SITE_READ      => '1',
		Settings::GROUP_BUILDER_WRITE => 'yes',
		'unknown_group'                => '1',
	)
);
wpnb_assert( ! isset( $sanitized['unknown_group'] ), 'Unknown access groups are discarded.' );
wpnb_assert( 1 === $sanitized[ Settings::GROUP_BUILDER_WRITE ], 'Known enabled access group is persisted as a boolean integer.' );
wpnb_assert( 0 === $sanitized[ Settings::GROUP_LIVE_CONTENT ], 'Missing checkbox values persist as disabled.' );

$settings->register();
$registered_setting = $GLOBALS['wpnb_test']['registered_settings'][ Settings::OPTION_NAME ];
wpnb_assert( Settings::OPTION_GROUP === $registered_setting['group'], 'Settings register in the bridge option group.' );
wpnb_assert( false === $registered_setting['args']['show_in_rest'], 'Bridge access settings are not exposed for REST writes.' );
wpnb_assert( is_callable( $registered_setting['args']['sanitize_callback'] ), 'Settings have a sanitize callback.' );

$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = array( Settings::GROUP_SITE_READ => 1 );
$permissions = new Permissions( $settings );
wpnb_assert( true === $permissions->allowed( Settings::GROUP_SITE_READ, 'read' ), 'Enabled group plus WordPress capability authorizes an ability.' );
$GLOBALS['wpnb_test']['capabilities']['read'] = false;
wpnb_assert( false === $permissions->allowed( Settings::GROUP_SITE_READ, 'read' ), 'Missing WordPress capability denies an ability.' );
$GLOBALS['wpnb_test']['capabilities']['read'] = true;
$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_SITE_READ ] = 0;
wpnb_assert( false === $permissions->allowed( Settings::GROUP_SITE_READ, 'read' ), 'Disabled bridge group denies an ability.' );
wpnb_assert( false === $permissions->allowed( 'unknown_group', 'read' ), 'Unknown bridge group is denied.' );

$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = $settings->defaults();
$registrar = new Registrar( $environment, $settings, $permissions );
$registrar->register_category();
$registrar->register_abilities();
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_categories'][ Registrar::CATEGORY ] ), 'Bridge ability category is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/bridge-info'] ), 'Bridge discovery ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/site-context'] ), 'Site context ability is registered.' );
$ability = $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/bridge-info'];
wpnb_assert( true === $ability['meta']['mcp']['public'], 'Bridge discovery ability explicitly opts into MCP exposure.' );
wpnb_assert( true === $ability['meta']['annotations']['readonly'], 'Bridge discovery ability is marked read-only.' );
wpnb_assert( true === call_user_func( $ability['permission_callback'] ), 'Bridge discovery ability permission callback honors Site Read.' );
$bridge_info = call_user_func( $ability['execute_callback'] );
wpnb_assert( '0.2.0' === $bridge_info['plugin_version'], 'Bridge discovery ability returns plugin version.' );
wpnb_assert( true === $bridge_info['mcp_adapter']['available'], 'Bridge discovery ability reports adapter availability.' );

wpnb_test_reset_state();
$GLOBALS['wpnb_test']['abilities'] = array(
	'vendor/private' => wpnb_test_ability(
		'vendor/private',
		array( 'post_type' => array( 'type' => 'string' ) ),
		array( 'public' => false )
	),
	'vendor/mcp-optout' => wpnb_test_ability(
		'vendor/mcp-optout',
		array( 'post_type' => array( 'type' => 'string' ) ),
		array( 'public' => true, 'mcp' => array( 'public' => false ) )
	),
	'vendor/compatible' => wpnb_test_ability(
		'vendor/compatible',
		array(
			'post_type' => array( 'type' => 'string' ),
			'fields'    => array( 'type' => 'array' ),
		),
		array( 'public' => true ),
		'content'
	),
	'wp-native-builder/internal' => wpnb_test_ability( 'wp-native-builder/internal', array(), array( 'public' => true ) ),
	'mcp-adapter/meta' => wpnb_test_ability( 'mcp-adapter/meta', array(), array( 'public' => true ) ),
);
$resolver = new Ability_Resolver();
$resolved = $resolver->find(
	array( 'vendor/private', 'vendor/mcp-optout', 'vendor/compatible' ),
	array( 'post_type', 'fields' )
);
wpnb_assert( $resolved instanceof WP_Native_Builder_Test_Ability, 'Resolver finds a compatible external Ability.' );
wpnb_assert( 'vendor/compatible' === $resolved->get_name(), 'Resolver skips private and MCP-opted-out candidates.' );
wpnb_assert( null === $resolver->find( array( 'vendor/compatible' ), array( 'missing_input' ) ), 'Resolver rejects an incompatible input contract.' );
wpnb_assert( false === $resolver->is_mcp_exposed( $GLOBALS['wpnb_test']['abilities']['vendor/mcp-optout'] ), 'Explicit MCP opt-out overrides general public exposure.' );

$catalog       = $resolver->public_catalog( 50 );
$catalog_names = array_map(
	static function ( $item ) {
		return $item['name'];
	},
	$catalog
);
wpnb_assert( in_array( 'vendor/compatible', $catalog_names, true ), 'Catalog surfaces an already-installed third-party public Ability.' );
wpnb_assert( ! in_array( 'wp-native-builder/internal', $catalog_names, true ), 'Catalog does not mirror Bridge-owned Abilities.' );
wpnb_assert( ! in_array( 'mcp-adapter/meta', $catalog_names, true ), 'Catalog omits MCP Adapter meta abilities.' );

wpnb_test_reset_state();
$GLOBALS['wpnb_test']['options'] = array(
	Settings::OPTION_NAME   => $settings->defaults(),
	'permalink_structure'   => '/%postname%/',
	'show_on_front'         => 'page',
	'page_on_front'         => 42,
	'page_for_posts'        => 43,
	'active_plugins'        => array( 'acme/acme.php' ),
);
$GLOBALS['wpnb_test']['capabilities'] = array(
	'read'              => true,
	'edit_posts'        => true,
	'publish_posts'     => true,
	'upload_files'      => true,
	'manage_categories' => true,
);
$GLOBALS['wpnb_test']['plugins'] = array(
	'acme/acme.php' => array( 'Name' => 'Acme Content', 'Version' => '2.3.4' ),
);
$GLOBALS['wpnb_test']['theme'] = array(
	'Name'       => 'Twenty Twenty-Six',
	'Version'    => '1.0',
	'stylesheet' => 'twentytwentysix',
	'template'   => 'twentytwentysix',
	'block'      => true,
);
$GLOBALS['wpnb_test']['post_types'] = array(
	'book' => (object) array(
		'name'         => 'book',
		'label'        => 'Books',
		'hierarchical' => false,
		'show_in_rest' => true,
		'rest_base'    => 'books',
	),
);
$GLOBALS['wpnb_test']['post_type_supports'] = array(
	'book' => array( 'editor' => true, 'thumbnail' => true ),
);
$admin_record = (object) array(
	'name'               => 'admin_record',
	'cap'                => (object) array( 'edit_posts' => 'edit_posts' ),
	'public'             => false,
	'publicly_queryable' => false,
	'show_ui'            => true,
	'show_in_rest'       => false,
);
$content_record = (object) array(
	'name'               => 'content_record',
	'cap'                => (object) array( 'edit_posts' => 'edit_posts' ),
	'public'             => true,
	'publicly_queryable' => true,
	'show_ui'            => true,
	'show_in_rest'       => true,
);
$GLOBALS['wpnb_test']['post_types']['admin_record']   = $admin_record;
$GLOBALS['wpnb_test']['post_types']['content_record'] = $content_record;
$GLOBALS['wpnb_test']['post_type_supports']['content_record'] = array( 'editor' => true );
wpnb_assert( null === Content_Eligibility::post_type_object( 'admin_record' ), 'show_ui alone does not make an administrative CPT generic Builder content.' );
wpnb_assert( $content_record === Content_Eligibility::post_type_object( 'content_record' ), 'A content-facing CPT remains eligible for generic Builder content.' );
wpnb_assert( false === Content_Eligibility::supports_blocks( 'admin_record' ), 'Administrative non-editor CPTs are rejected as Gutenberg targets.' );
wpnb_assert( true === Content_Eligibility::supports_blocks( 'content_record' ), 'Content-facing editor CPTs remain valid Gutenberg targets.' );
wpnb_assert( null === Content_Eligibility::post_type_object( 'wpnb_doc' ), 'Workspace documents are explicitly rejected as generic content.' );
wpnb_assert( null === Content_Eligibility::post_type_object( 'wpnb_task' ), 'Workspace tasks are explicitly rejected as generic content.' );
wpnb_assert( false === Content_Eligibility::supports_blocks( 'wpnb_doc' ), 'Workspace documents cannot be targeted by generic Gutenberg abilities.' );

$GLOBALS['wpnb_test']['taxonomies'] = array(
	'genre' => (object) array(
		'name'         => 'genre',
		'label'        => 'Genres',
		'hierarchical' => true,
		'show_in_rest' => true,
		'rest_base'    => 'genres',
		'object_type'  => array( 'book' ),
	),
);
$GLOBALS['wpnb_test']['abilities'] = array(
	'core/get-site-info' => wpnb_test_ability( 'core/get-site-info', array( 'fields' => array( 'type' => 'array' ) ), array( 'public' => true ), 'site' ),
	'core/get-user-info' => wpnb_test_ability( 'core/get-user-info', array( 'fields' => array( 'type' => 'array' ) ), array( 'public' => true ), 'user' ),
	'core/get-environment-info' => wpnb_test_ability( 'core/get-environment-info', array( 'fields' => array( 'type' => 'array' ) ), array( 'public' => true ), 'site' ),
	'acme/site-builder-info' => wpnb_test_ability( 'acme/site-builder-info', array(), array( 'public' => true ), 'acme' ),
);
$permissions = new Permissions( $settings );
$registrar   = new Registrar( $environment, $settings, $permissions );
$registrar->register_abilities();
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/content-read'] ), 'Bridge content-read fallback remains registered unless a provider contract is deliberately verified.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/content-upsert'] ), 'Bridge content mutation fallback remains available for uncovered operations.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/blocks-read'] ), 'Generic Gutenberg block inspection ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/blocks-mutate'] ), 'Generic Gutenberg block mutation ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/media-read'] ), 'Generic Media Library inspection ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/media-upload'] ), 'Bounded WordPress media upload ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/media-update'] ), 'Media metadata update ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/media-delete'] ), 'Gated media deletion ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/terms-read'] ), 'Generic taxonomy inspection ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/term-upsert'] ), 'Generic taxonomy term mutation ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/terms-assign'] ), 'Generic taxonomy assignment ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/term-delete'] ), 'Gated taxonomy term deletion ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/navigation-read'] ), 'Theme-neutral navigation inspection ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/classic-navigation-mutate'] ), 'Classic navigation mutation ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/integration-status'] ), 'Optional integration status ability is registered without requiring providers.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/site-settings-read'] ), 'Bounded site settings read ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/site-settings-update'] ), 'Bounded site settings update ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/extensions-read'] ), 'Extension inspection ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/extension-lifecycle'] ), 'Extension lifecycle ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/source-files-read'] ), 'Elevated installed source inspection ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/source-file-preview'] ), 'Elevated source preview ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/source-file-apply'] ), 'Elevated source apply ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/source-file-recover'] ), 'Elevated source recovery ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/users-read'] ), 'User and role inspection ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/user-upsert'] ), 'Bounded user mutation ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/user-remove'] ), 'Explicit reassignment user removal ability is registered.' );
wpnb_assert( ! isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/gravity-forms-read'] ), 'Gravity Forms fallback disappears when GFAPI is unavailable.' );
wpnb_assert( ! isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/snippets-read'] ), 'Code Snippets fallback disappears when its supported API is unavailable.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/workspace-resume'] ), 'Compact Workspace resume ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/workspace-document'] ), 'Workspace document ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/workspace-task'] ), 'Workspace task ability is registered.' );
wpnb_assert( false === $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/workspace-document']['input_schema']['additionalProperties'], 'Workspace document input schema is closed.' );
wpnb_assert( false === $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/workspace-task']['input_schema']['additionalProperties'], 'Workspace task input schema is closed.' );


// Simulate the documented GFAPI surface only after the provider-absent assertions above.
eval( 'class GFAPI {
	public static $forms = array();
	public static $next_id = 1;
	public static function get_forms( $active = null, $trash = false, $sort_column = "id", $sort_dir = "ASC" ) {
		$forms = array_values( self::$forms );
		return array_values( array_filter( $forms, static function ( $form ) use ( $active, $trash ) {
			if ( null !== $active && (bool) $form["is_active"] !== (bool) $active ) { return false; }
			if ( null !== $trash && (bool) $form["is_trash"] !== (bool) $trash ) { return false; }
			return true;
		} ) );
	}
	public static function get_form( $id ) { return isset( self::$forms[ $id ] ) ? self::$forms[ $id ] : false; }
	public static function form_id_exists( $id ) { return isset( self::$forms[ $id ] ); }
	public static function add_form( $form ) { $id = self::$next_id++; $form["id"] = $id; $form["is_active"] = false; $form["is_trash"] = false; self::$forms[ $id ] = $form; return $id; }
	public static function update_form( $form ) { if ( empty( $form["id"] ) || ! isset( self::$forms[ $form["id"] ] ) ) { return false; } $old = self::$forms[ $form["id"] ]; self::$forms[ $form["id"] ] = array_merge( $old, $form ); return true; }
	public static function update_form_property( $id, $property, $value ) { if ( ! isset( self::$forms[ $id ] ) ) { return false; } self::$forms[ $id ][ $property ] = $value; return true; }
	public static function delete_form( $id ) { if ( ! isset( self::$forms[ $id ] ) ) { return false; } unset( self::$forms[ $id ] ); return true; }
}' );
$GLOBALS['wpnb_test']['registered_abilities'] = array();
$gf_registrar = new Registrar( $environment, $settings, $permissions );
$gf_registrar->register_abilities();
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/gravity-forms-read'] ), 'Documented GFAPI surface registers the Bridge fallback when no native Gravity Forms Ability is observed.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/gravity-form-upsert'] ), 'GFAPI fallback includes form creation/update.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/gravity-form-status'] ), 'GFAPI fallback includes form activation state.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/gravity-form-delete'] ), 'GFAPI fallback includes gated form deletion.' );
$gf_read = $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/gravity-forms-read'];
$GLOBALS['wpnb_test']['capabilities']['gravityforms_edit_forms'] = true;
wpnb_assert( true === call_user_func( $gf_read['permission_callback'] ), 'GFAPI fallback form reads use the supported gravityforms_edit_forms capability.' );
unset( $GLOBALS['wpnb_test']['capabilities']['gravityforms_edit_forms'] );
wpnb_assert( false === call_user_func( $gf_read['permission_callback'] ), 'A user without gravityforms_edit_forms is denied GFAPI fallback form reads.' );
$GLOBALS['wpnb_test']['capabilities']['gravityforms_view_forms'] = true;
wpnb_assert( false === call_user_func( $gf_read['permission_callback'] ), 'An invented gravityforms_view_forms capability does not authorize GFAPI fallback form reads.' );
unset( $GLOBALS['wpnb_test']['capabilities']['gravityforms_view_forms'] );
$GLOBALS['wpnb_test']['capabilities']['gravityforms_edit_forms'] = true;
$gf_upsert = $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/gravity-form-upsert'];
$gf_created = call_user_func( $gf_upsert['execute_callback'], array( 'action' => 'create', 'form' => array( 'title' => 'Provider contract fixture', 'description' => 'Fast GFAPI fallback coverage', 'fields' => array() ) ) );
wpnb_assert( ! is_wp_error( $gf_created ) && 1 === $gf_created['form']['id'], 'GFAPI fallback creates and reads back a form without private storage access.' );
$gf_list = call_user_func( $gf_read['execute_callback'], array( 'action' => 'list' ) );
wpnb_assert( ! is_wp_error( $gf_list ) && 1 === count( $gf_list['items'] ) && 'Provider contract fixture' === $gf_list['items'][0]['title'], 'Authorized GFAPI fallback form reads list forms through the documented provider API.' );
$gf_status = $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/gravity-form-status'];
$gf_activated = call_user_func( $gf_status['execute_callback'], array( 'id' => 1, 'active' => true ) );
wpnb_assert( ! is_wp_error( $gf_activated ) && true === $gf_activated['form']['active'], 'GFAPI fallback updates form activation state.' );
$gf_delete = $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/gravity-form-delete'];
$gf_deleted = call_user_func( $gf_delete['execute_callback'], array( 'id' => 1 ) );
wpnb_assert( ! is_wp_error( $gf_deleted ) && true === $gf_deleted['deleted'], 'GFAPI fallback deletes through GFAPI rather than provider storage internals.' );
unset( $GLOBALS['wpnb_test']['capabilities']['gravityforms_edit_forms'] );

// A current public native Gravity Forms Ability must suppress the Bridge GFAPI duplicate.
$GLOBALS['wpnb_test']['abilities']['gravityforms/forms-get'] = wpnb_test_ability( 'gravityforms/forms-get', array( 'id' => array( 'type' => 'integer' ) ), array( 'public' => true ), 'gravityforms' );
$GLOBALS['wpnb_test']['registered_abilities'] = array();
$gf_native_registrar = new Registrar( $environment, $settings, $permissions );
$gf_native_registrar->register_abilities();
wpnb_assert( ! isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/gravity-forms-read'] ), 'Observed public Gravity Forms native Ability suppresses the GFAPI fallback.' );
$integration_status = call_user_func( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/integration-status']['execute_callback'] );
wpnb_assert( 'ability' === $integration_status['gravity_forms']['mode'], 'Integration status prefers observed Gravity Forms native Abilities over GFAPI fallback.' );
wpnb_assert( in_array( 'gravityforms/forms-get', $integration_status['gravity_forms']['ability_names'], true ), 'Integration status exposes the observed stable Gravity Forms Ability name.' );

// A provider-native Ability hidden from MCP must still suppress the Bridge fallback and must not be reported as an active API fallback.
$GLOBALS['wpnb_test']['abilities']['gravityforms/forms-get'] = wpnb_test_ability( 'gravityforms/forms-get', array( 'id' => array( 'type' => 'integer' ) ), array( 'public' => false ), 'gravityforms' );
$GLOBALS['wpnb_test']['registered_abilities'] = array();
$gf_hidden_registrar = new Registrar( $environment, $settings, $permissions );
$gf_hidden_registrar->register_abilities();
wpnb_assert( ! isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/gravity-forms-read'] ), 'MCP-hidden native Gravity Forms Ability still suppresses the GFAPI fallback.' );
$hidden_status = call_user_func( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/integration-status']['execute_callback'] );
wpnb_assert( 'unavailable' === $hidden_status['gravity_forms']['mode'], 'MCP-hidden Gravity Forms native surface is not misreported as an active Bridge API fallback.' );
unset( $GLOBALS['wpnb_test']['abilities']['gravityforms/forms-get'] );
wpnb_assert( true === $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/classic-navigation-mutate']['meta']['annotations']['destructive'], 'Mixed classic-navigation mutation is conservatively marked destructive because remove_item is permanent.' );
$site_ability = $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/site-context'];
wpnb_assert( true === call_user_func( $site_ability['permission_callback'] ), 'Site context honors Site Read plus WordPress read capability.' );
$site_context = call_user_func( $site_ability['execute_callback'] );
wpnb_assert( 'core/get-site-info' === $site_context['reuse']['site_info'], 'Site context advertises compatible Core site-info reuse.' );
wpnb_assert( 'core/get-user-info' === $site_context['reuse']['user_info'], 'Site context advertises compatible Core user-info reuse.' );
wpnb_assert( 'core/get-environment-info' === $site_context['reuse']['environment_info'], 'Site context advertises compatible Core environment-info reuse.' );
wpnb_assert( 'Twenty Twenty-Six' === $site_context['theme']['name'], 'Site context is theme-neutral and reports a non-Astra block theme.' );
wpnb_assert( true === $site_context['theme']['is_block_theme'], 'Site context reports block-theme capability.' );
wpnb_assert( 'book' === $site_context['post_types'][0]['name'], 'Generic site inspection discovers a plugin-provided editable post type.' );
wpnb_assert( true === $site_context['post_types'][0]['supports_editor'], 'Generic post-type discovery reports editor support.' );
wpnb_assert( 'genre' === $site_context['taxonomies'][0]['name'], 'Generic site inspection discovers a plugin-provided taxonomy.' );
wpnb_assert( 'acme/acme.php' === $site_context['plugins'][0]['file'], 'Site context reports the installed provider plugin.' );
wpnb_assert( true === $site_context['plugins'][0]['active'], 'Site context reports provider activation state.' );
$site_catalog_names = array_map(
	static function ( $item ) {
		return $item['name'];
	},
	$site_context['external_abilities']
);
wpnb_assert( in_array( 'acme/site-builder-info', $site_catalog_names, true ), 'Site context exposes a third-party Ability hint without a hardcoded Acme integration.' );

wpnb_assert( ! array_key_exists( 'content_read', $site_context['reuse'] ), 'Site context does not advertise speculative content Ability identifiers.' );

$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_SITE_READ ] = 0;
wpnb_assert( false === call_user_func( $site_ability['permission_callback'] ), 'Disabled Site Read group denies site-context.' );

wpnb_test_reset_state();
$log = new Mutation_Log();
for ( $i = 0; $i < 55; ++$i ) {
	$log->record( 'wp-native-builder/test-' . $i, 'post', $i, true, '' );
}
$entries = $log->recent( 100 );
wpnb_assert( 50 === count( $entries ), 'Mutation log is bounded to 50 records.' );
wpnb_assert(
	array( 'timestamp', 'user_id', 'ability', 'target_type', 'target_id', 'success', 'error_code' ) === array_keys( $entries[0] ),
	'Mutation log stores only bounded metadata fields.'
);

$log->record( str_repeat( 'ability-', 40 ), str_repeat( 'target_', 20 ), 1, false, str_repeat( 'error_', 40 ) );
$bounded_entry = $log->recent( 1 )[0];
wpnb_assert( strlen( $bounded_entry['ability'] ) <= 160, 'Mutation log bounds the ability field length.' );
wpnb_assert( strlen( $bounded_entry['target_type'] ) <= 64, 'Mutation log bounds the target type field length.' );
wpnb_assert( strlen( $bounded_entry['error_code'] ) <= 100, 'Mutation log bounds the error code field length.' );

wpnb_test_reset_state();
$page = new Settings_Page( $environment, $settings );
$page->register_menu();
wpnb_assert( 'manage_options' === $GLOBALS['wpnb_test']['menu_pages'][ Settings_Page::PAGE_SLUG ]['capability'], 'Top-level WP Native Builder admin area requires manage_options.' );
wpnb_assert( 'Dashboard' === $GLOBALS['submenu'][ Settings_Page::PAGE_SLUG ][0][0], 'Top-level parent route is presented as Dashboard in the submenu.' );
foreach ( array( Settings_Page::DOCUMENTS_SLUG, Settings_Page::TASKS_SLUG, Settings_Page::ACTIVITY_SLUG, Settings_Page::SETTINGS_SLUG ) as $submenu_slug ) {
	wpnb_assert( isset( $GLOBALS['wpnb_test']['submenu_pages'][ Settings_Page::PAGE_SLUG ][ $submenu_slug ] ), 'Required WP Native Builder submenu is registered: ' . $submenu_slug );
	wpnb_assert( 'manage_options' === $GLOBALS['wpnb_test']['submenu_pages'][ Settings_Page::PAGE_SLUG ][ $submenu_slug ]['capability'], 'WP Native Builder submenu requires manage_options: ' . $submenu_slug );
}
ob_start();
$page->render_settings();
$html = ob_get_clean();
wpnb_assert( in_array( Settings::OPTION_GROUP, $GLOBALS['wpnb_test']['settings_fields'], true ), 'Settings page uses WordPress Settings API nonce fields.' );
wpnb_assert( false !== strpos( $html, 'options.php' ), 'Settings page posts access groups through the WordPress Settings API.' );
wpnb_assert( false !== strpos( $html, 'wpnb_workspace_export' ), 'Settings page exposes explicit Workspace export.' );
wpnb_assert( false !== strpos( $html, 'wpnb_workspace_clear' ), 'Settings page exposes explicit confirmed Workspace clear.' );

$GLOBALS['wpnb_test']['capabilities']['manage_options'] = false;
try {
	$page->render_settings();
	wpnb_assert( false, 'Settings page must deny users without manage_options.' );
} catch ( RuntimeException $exception ) {
	wpnb_assert( true, 'Settings page denies users without manage_options.' );
}

if ( 0 !== $failures ) {
	fwrite( STDERR, "\n{$failures} failure(s), {$tests} assertion(s).\n" );
	exit( 1 );
}

echo "PASS: {$tests} assertions.\n";
