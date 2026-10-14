<?php
/**
 * Issue #126: exact Workspace deletion must fail closed without authority.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Workspace_Delete_Abilities;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

function get_current_blog_id() { return 1; }

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
wpai_test_reset_state();
$permissions = new Permissions( new Settings() );
$log = new Mutation_Log();
$api = new Workspace_Delete_Abilities( $permissions, new Store(), $log );
$auth = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
$auth->setValue( null, '' );
$registered = $api->register();
$metadata = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/workspace-delete'];
$assert( 1 === count( $registered ) && true === $metadata['meta']['annotations']['destructive'] &&
	false === $metadata['meta']['annotations']['readonly'] &&
	false === $metadata['meta']['annotations']['idempotent'], 'Workspace deletion must remain explicit and destructive.' );
$assert( false === $metadata['input_schema']['additionalProperties'] &&
	7 === count( $metadata['input_schema']['required'] ), 'Deletion needs all seven exact identity and confirmation fields.' );

$input = array(
	'kind'                => 'document',
	'id'                  => 123,
	'blog_id'             => 1,
	'project_ref'         => 'issue126-project-a',
	'expected_version'    => 1,
	'expected_state_hash' => str_repeat( '0', 64 ),
	'confirmation'        => 'PERMANENTLY DELETE DOCUMENT 123',
);
$assert( ! $api->can_delete( $input ) && is_wp_error( $api->execute( $input ) ), 'WordPress admin alone must not bypass MCP origin.' );

$auth->setValue( null, 'test-client' );
$GLOBALS['wpai_test']['capabilities'] = array(
	'read' => true, 'edit_posts' => true, 'delete_posts' => true,
	'delete_post' => true, 'manage_options' => true,
);
update_option( Settings::OPTION_NAME, array(
	Settings::GROUP_BUILDER_WRITE => 1,
	Settings::GROUP_USERS_DESTRUCTIVE => 1,
) );
$assert( $api->can_delete( $input ), 'Exact authenticated administrative delete grant was rejected.' );
$bad_site = array_merge( $input, array( 'blog_id' => 2 ) );
$assert( ! $api->can_delete( $bad_site ) && is_wp_error( $api->execute( $bad_site ) ), 'Cross-blog input was accepted.' );
$bad_kind = array_merge( $input, array( 'kind' => 'post' ) );
$assert( ! $api->can_delete( $bad_kind ), 'Ordinary content post type accepted by private delete API.' );
$bad_id = array_merge( $input, array( 'id' => 0 ) );
$assert( ! $api->can_delete( $bad_id ), 'Zero record ID accepted.' );

$missing_confirmation = array_merge( $input, array( 'confirmation' => 'delete' ) );
$result = $api->execute( $missing_confirmation );
$assert( is_wp_error( $result ) && 'workspace_delete_invalid' === $result->get_error_code(), 'Weak confirmation was accepted.' );
$unbound = array_merge( $input, array( 'project_ref' => '' ) );
$result = $api->execute( $unbound );
$assert( is_wp_error( $result ) && 'workspace_delete_invalid' === $result->get_error_code(), 'Legacy/unbound Workspace deletion was accepted.' );
$invalid_hash = array_merge( $input, array( 'expected_state_hash' => 'not-a-real-hash' ) );
$result = $api->execute( $invalid_hash );
$assert( is_wp_error( $result ) && 'workspace_delete_invalid' === $result->get_error_code(), 'Invalid version/hash fence was accepted.' );
$extra = array_merge( $input, array( 'all' => true ) );
$result = $api->execute( $extra );
$assert( is_wp_error( $result ) && 'workspace_delete_invalid' === $result->get_error_code(), 'Bulk/broad deletion fields were accepted.' );

$recorded = $api->execute( $input );
$assert( is_wp_error( $recorded ) && 'workspace_delete_busy' === $recorded->get_error_code(),
	'Deletion must fail closed when no named DB admission lock can be acquired.' );
$recent = $log->recent( 1 );
$assert( 'wp-ai-bridge/workspace-delete' === $recent[0]['ability'] &&
	'workspace_delete_busy' === $recent[0]['error_code'], 'Failure should enter existing safe Activity.' );

$GLOBALS['wpai_test']['capabilities']['delete_post'] = false;
$assert( ! $api->can_delete( $input ), 'Specific post deletion capability was ignored.' );
$GLOBALS['wpai_test']['capabilities']['delete_post'] = true;
$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
$assert( ! $api->can_delete( $input ), 'Remote nonadmin with delete_posts must not use exact Workspace delete.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
update_option( Settings::OPTION_NAME, array( Settings::GROUP_BUILDER_WRITE => 1, Settings::GROUP_USERS_DESTRUCTIVE => 0 ) );
$assert( ! $api->can_delete( $input ), 'Revoked destructive Bridge group was ignored.' );
$auth->setValue( null, '' );
echo "PASS: #126 {$checks} exact-delete authorization, confirmation and no-DB-denial assertions.\n";
