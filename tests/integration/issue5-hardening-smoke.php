<?php
/**
 * Real WordPress hardening checks for the complete v0.1 Bridge surface.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue5_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue5_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpnb_issue5_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpnb_issue5_schema_keys( $schema ) {
	$keys = array();
	if ( ! is_array( $schema ) ) {
		return $keys;
	}
	if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
		foreach ( $schema['properties'] as $key => $child ) {
			$keys[] = (string) $key;
			$keys = array_merge( $keys, wpnb_issue5_schema_keys( $child ) );
		}
	}
	if ( isset( $schema['items'] ) ) {
		$keys = array_merge( $keys, wpnb_issue5_schema_keys( $schema['items'] ) );
	}
	return $keys;
}

$settings        = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$original_log    = get_option( Mutation_Log::OPTION_NAME, array() );
$created_post    = 0;
$secret_marker   = 'WPNB_SECRET_' . wp_generate_password( 24, false, false );

try {
	$names = array();
	foreach ( wp_get_abilities() as $ability ) {
		$name = $ability->get_name();
		if ( 0 !== strpos( $name, 'wp-native-builder/' ) ) {
			continue;
		}
		$names[] = $name;
		$meta    = $ability->get_meta();
		wpnb_issue5_assert( true === (bool) ( $meta['mcp']['public'] ?? false ), $name . ' is not explicitly MCP-public.' );
		wpnb_issue5_assert( 'tool' === (string) ( $meta['mcp']['type'] ?? '' ), $name . ' is not typed as an MCP tool.' );
		wpnb_issue5_assert( isset( $meta['annotations']['readonly'], $meta['annotations']['destructive'], $meta['annotations']['idempotent'] ), $name . ' is missing MCP behavior annotations.' );

		$input = $ability->get_input_schema();
		wpnb_issue5_assert( is_array( $input ) && 'object' === ( $input['type'] ?? '' ), $name . ' does not expose an object input schema.' );
		wpnb_issue5_assert( false === ( $input['additionalProperties'] ?? null ), $name . ' does not reject unknown top-level input properties.' );

		$forbidden_input = array( 'password', 'user_pass', 'application_password', 'application_passwords', 'session_token', 'session_tokens', 'access_token', 'refresh_token', 'api_key', 'api_secret', 'server_path', 'file_path', 'package_url', 'shell_command', 'sql_query' );
		foreach ( wpnb_issue5_schema_keys( $input ) as $key ) {
			wpnb_issue5_assert( ! in_array( strtolower( $key ), $forbidden_input, true ), $name . ' exposes forbidden input field: ' . $key );
		}

		$forbidden_output = array( 'password', 'user_pass', 'application_password', 'application_passwords', 'session_token', 'session_tokens', 'access_token', 'refresh_token', 'api_key', 'api_secret', 'cookie', 'cookies' );
		$credential_output_exceptions = array(
			'wp-native-builder/application-password-create' => array( 'password' ),
		);
		foreach ( wpnb_issue5_schema_keys( $ability->get_output_schema() ) as $key ) {
			$normalized_key = strtolower( $key );
			if ( ! in_array( $normalized_key, $forbidden_output, true ) ) {
				continue;
			}
			$allowed = isset( $credential_output_exceptions[ $name ] )
				&& in_array( $normalized_key, $credential_output_exceptions[ $name ], true );
			wpnb_issue5_assert( $allowed, $name . ' exposes credential/session output field: ' . $key );
		}
	}
	sort( $names );
	wpnb_issue5_assert( ! empty( $names ), 'Bridge registry unexpectedly contains no Bridge-owned abilities.' );
	wpnb_issue5_assert( count( $names ) === count( array_unique( $names ) ), 'Bridge ability names are not unique.' );
	$term_metadata_names = array( 'wp-native-builder/term-meta-read', 'wp-native-builder/term-meta-update', 'wp-native-builder/term-meta-delete' );
	wpnb_issue5_assert( 3 === count( array_intersect( $names, $term_metadata_names ) ), 'Term metadata Ability family is incomplete.' );
	wpnb_issue5_assert( in_array( 'wp-native-builder/abilities-read', $names, true ), 'Integrated public Ability catalog is missing.' );
	wpnb_issue5_assert( in_array( 'wp-native-builder/media-import-url', $names, true ), 'URL import Ability is missing.' );
	$source_editing_names = array( 'wp-native-builder/source-files-read', 'wp-native-builder/source-file-preview', 'wp-native-builder/source-file-apply', 'wp-native-builder/source-file-recover' );
	wpnb_issue5_assert( 4 === count( array_intersect( $names, $source_editing_names ) ), 'Issue #46 source-editing Ability family is incomplete.' );
	$comment_names = array( 'wp-native-builder/comments-read', 'wp-native-builder/comment-reply', 'wp-native-builder/comment-status', 'wp-native-builder/comment-delete' );
	wpnb_issue5_assert( 4 === count( array_intersect( $names, $comment_names ) ), 'Issue #52 comment administration Ability family is incomplete.' );
	$application_password_names = array( 'wp-native-builder/application-passwords-read', 'wp-native-builder/application-password-create', 'wp-native-builder/application-password-update', 'wp-native-builder/application-password-delete', 'wp-native-builder/application-passwords-delete-all' );
	wpnb_issue5_assert( 5 === count( array_intersect( $names, $application_password_names ) ), 'Issue #61 Application Password Ability family is incomplete.' );
	$create_ability = wp_get_ability( 'wp-native-builder/application-password-create' );
	wpnb_issue5_assert( $create_ability instanceof WP_Ability, 'Issue #61 Application Password create Ability is unavailable for one-time credential exception verification.' );
	$create_output_keys = array_map( 'strtolower', wpnb_issue5_schema_keys( $create_ability->get_output_schema() ) );
	wpnb_issue5_assert( in_array( 'password', $create_output_keys, true ), 'Issue #61 one-time create credential output is missing from its exact bounded Ability.' );
	foreach ( array_diff( $create_output_keys, array( 'password' ) ) as $create_output_key ) {
		wpnb_issue5_assert( ! in_array( $create_output_key, $forbidden_output, true ), 'Issue #61 create Ability broadened the credential-output exception beyond password.' );
	}

	$defaults = $settings->defaults();
	wpnb_issue5_assert( 1 === $defaults[ Settings::GROUP_SITE_READ ], 'Site Read is not the sole enabled default group.' );
	foreach ( array( Settings::GROUP_BUILDER_WRITE, Settings::GROUP_REMOTE_MEDIA, Settings::GROUP_LIVE_CONTENT, Settings::GROUP_SITE_CONFIG, Settings::GROUP_ADVANCED_METADATA, Settings::GROUP_AUTHENTICATION, Settings::GROUP_CODE_EXTENSIONS, Settings::GROUP_SOURCE_EDITING, Settings::GROUP_NATIVE_ABILITIES, Settings::GROUP_COMMENTS, Settings::GROUP_USERS_DESTRUCTIVE ) as $group ) {
		wpnb_issue5_assert( 0 === $defaults[ $group ], 'Sensitive group is enabled by default: ' . $group );
	}
	update_option( Settings::OPTION_NAME, $defaults, false );

	wpnb_issue5_assert( is_wp_error( wpnb_issue5_execute( 'wp-native-builder/content-upsert', array( 'action'=>'create', 'post_type'=>'post', 'title'=>'Denied draft', 'status'=>'draft' ) ) ), 'Builder Write disabled group allowed content mutation.' );
	wpnb_issue5_assert( is_wp_error( wpnb_issue5_execute( 'wp-native-builder/workspace-document', array( 'action'=>'create', 'title'=>'Denied Workspace document' ) ) ), 'Builder Write disabled group allowed Workspace mutation.' );
	wpnb_issue5_assert( is_wp_error( wpnb_issue5_execute( 'wp-native-builder/site-settings-update', array( 'tagline'=>'Denied config' ) ) ), 'Site Configuration disabled group allowed configuration mutation.' );
	wpnb_issue5_assert( is_wp_error( wpnb_issue5_execute( 'wp-native-builder/extension-lifecycle', array( 'kind'=>'plugin', 'action'=>'activate', 'target'=>'mcp-adapter/mcp-adapter.php' ) ) ), 'Code & Extensions disabled group allowed extension mutation.' );
	wpnb_issue5_assert( is_wp_error( wpnb_issue5_execute( 'wp-native-builder/source-files-read', array( 'action'=>'list', 'kind'=>'plugin' ) ) ), 'Source Editing disabled group allowed source inspection.' );
	wpnb_issue5_assert( is_wp_error( wpnb_issue5_execute( 'wp-native-builder/user-upsert', array( 'action'=>'create', 'username'=>'wpnb_denied_issue5', 'email'=>'wpnb_denied_issue5@example.invalid', 'role'=>'subscriber' ) ) ), 'Users & Destructive disabled group allowed user creation.' );
	wpnb_issue5_assert( is_wp_error( wpnb_issue5_execute( 'wp-native-builder/comment-reply', array( 'post' => 1, 'content' => 'Denied comment reply' ) ) ), 'Comments disabled group allowed comment mutation.' );

	wpnb_issue5_assert( is_wp_error( wpnb_issue5_execute( 'wp-native-builder/content-upsert', array( 'action'=>'update' ) ) ), 'Incomplete content update was not rejected.' );
	wpnb_issue5_assert( is_wp_error( wpnb_issue5_execute( 'wp-native-builder/term-upsert', array( 'action'=>'create' ) ) ), 'Incomplete taxonomy create was not rejected.' );
	wpnb_issue5_assert( is_wp_error( wpnb_issue5_execute( 'wp-native-builder/media-upload', array( 'filename'=>'missing-bytes.png' ) ) ), 'Media upload without bytes was not rejected.' );
	wpnb_issue5_assert( is_wp_error( wpnb_issue5_execute( 'wp-native-builder/extension-lifecycle', array( 'kind'=>'plugin', 'action'=>'install' ) ) ), 'Extension install without a WordPress.org slug was not rejected.' );

	$write = $defaults;
	$write[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $write, false );
	update_option( Mutation_Log::OPTION_NAME, array(), false );

	$created = wpnb_issue5_execute(
		'wp-native-builder/content-upsert',
		array(
			'action'    => 'create',
			'post_type' => 'post',
			'title'     => 'Hardening fixture ' . $secret_marker,
			'content'   => '<!-- wp:paragraph --><p>' . esc_html( $secret_marker ) . '</p><!-- /wp:paragraph -->',
			'status'    => 'draft',
		)
	);
	wpnb_issue5_assert( ! is_wp_error( $created ), 'Builder Write could not create a draft fixture.' );
	$created_post = (int) $created['id'];

	$log = ( new Mutation_Log() )->recent( 10 );
	wpnb_issue5_assert( ! empty( $log ), 'Successful mutation did not produce bounded mutation metadata.' );
	$serialized_log = wp_json_encode( $log );
	wpnb_issue5_assert( false === strpos( $serialized_log, $secret_marker ), 'Mutation log retained content/title payload material.' );
	foreach ( $log as $entry ) {
		wpnb_issue5_assert( array( 'timestamp', 'user_id', 'ability', 'target_type', 'target_id', 'success', 'error_code' ) === array_keys( $entry ), 'Mutation log contains an unexpected field.' );
		wpnb_issue5_assert( strlen( $entry['ability'] ) <= 160 && strlen( $entry['target_type'] ) <= 64 && strlen( $entry['error_code'] ) <= 100, 'Mutation log field length is not bounded.' );
	}

	$publish = wpnb_issue5_execute(
		'wp-native-builder/content-upsert',
		array(
			'action'    => 'create',
			'post_type' => 'post',
			'title'     => 'Denied live publish',
			'status'    => 'publish',
		)
	);
	wpnb_issue5_assert( is_wp_error( $publish ), 'Live Content disabled group allowed publish.' );

	$delete = wpnb_issue5_execute( 'wp-native-builder/content-delete', array( 'id'=>$created_post, 'force'=>false ) );
	wpnb_issue5_assert( is_wp_error( $delete ) && get_post( $created_post ), 'Users & Destructive disabled group allowed content deletion.' );

	echo "PASS: Issue #5 hardening smoke.\n";
} finally {
	if ( $created_post > 0 ) {
		wp_delete_post( $created_post, true );
	}
	update_option( Mutation_Log::OPTION_NAME, $original_log, false );
	update_option( Settings::OPTION_NAME, $original_access, false );
}
