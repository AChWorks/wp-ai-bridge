<?php
/**
 * Real WordPress smoke coverage for Issue #4 core administration and optional-provider absence.
 *
 * Run with:
 * wp eval-file tests/integration/issue4-core-admin-smoke.php --user=<administrator>
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpai_issue4_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue4_execute( $name, array $input = array() ) {
	$ability = wp_get_ability( $name );
	wpai_issue4_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

$settings = new Settings();
$original_access = get_option( Settings::OPTION_NAME, $settings->defaults() );
$original_options = array(
	'blogname'            => get_option( 'blogname' ),
	'blogdescription'     => get_option( 'blogdescription' ),
	'show_on_front'       => get_option( 'show_on_front' ),
	'page_on_front'       => get_option( 'page_on_front' ),
	'page_for_posts'      => get_option( 'page_for_posts' ),
	'posts_per_page'      => get_option( 'posts_per_page' ),
	'permalink_structure' => get_option( 'permalink_structure' ),
);
$created_user = 0;
$created_page_one = 0;
$created_page_two = 0;
$original_theme = get_stylesheet();
$pre_mail = static function () { return true; };

try {
	$access = $settings->defaults();
	$access[ Settings::GROUP_SITE_READ ]         = 1;
	$access[ Settings::GROUP_SITE_CONFIG ]       = 0;
	$access[ Settings::GROUP_CODE_EXTENSIONS ]   = 0;
	$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
	update_option( Settings::OPTION_NAME, $access, false );

	$status = wpai_issue4_execute( 'wp-ai-bridge/integration-status' );
	foreach ( array( 'astra', 'gravity_forms', 'code_snippets', 'woocommerce' ) as $provider ) {
		wpai_issue4_assert( isset( $status[ $provider ]['mode'] ), 'Integration status omitted provider: ' . $provider );
		wpai_issue4_assert( in_array( $status[ $provider ]['mode'], array( 'ability', 'api_fallback', 'unavailable' ), true ), 'Integration status returned an invalid mode.' );
	}
	wpai_issue4_assert( 'unavailable' === $status['gravity_forms']['mode'], 'Missing Gravity Forms did not produce a graceful unavailable state.' );
	wpai_issue4_assert( 'unavailable' === $status['code_snippets']['mode'], 'Missing Code Snippets did not produce a graceful unavailable state.' );

	$site = wpai_issue4_execute( 'wp-ai-bridge/site-settings-read' );
	wpai_issue4_assert( isset( $site['site_title'], $site['permalink_structure'] ), 'Bounded site settings read omitted expected fields.' );
	$blocked = wpai_issue4_execute( 'wp-ai-bridge/site-settings-update', array( 'site_title' => 'Must not apply' ) );
	wpai_issue4_assert( is_wp_error( $blocked ), 'Site settings update bypassed the Site Configuration group.' );

	$created_page_one = wp_insert_post( array( 'post_type'=>'page', 'post_status'=>'publish', 'post_title'=>'WPNB Issue 4 Front' ), true );
	$created_page_two = wp_insert_post( array( 'post_type'=>'page', 'post_status'=>'publish', 'post_title'=>'WPNB Issue 4 Posts' ), true );
	wpai_issue4_assert( ! is_wp_error($created_page_one) && ! is_wp_error($created_page_two), 'Could not create page fixtures.' );
	$created_page_one = (int) $created_page_one;
	$created_page_two = (int) $created_page_two;

	$access[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );
	$updated = wpai_issue4_execute(
		'wp-ai-bridge/site-settings-update',
		array(
			'site_title'          => 'WPNB Issue 4 Test Site',
			'tagline'             => 'Typed settings smoke',
			'show_on_front'       => 'page',
			'page_on_front'       => $created_page_one,
			'page_for_posts'      => $created_page_two,
			'posts_per_page'      => 7,
			'permalink_structure' => '/%postname%/',
		)
	);
	wpai_issue4_assert( ! is_wp_error( $updated ), 'Site Configuration update failed.' );
	wpai_issue4_assert( 'WPNB Issue 4 Test Site' === get_option( 'blogname' ), 'Site title did not update.' );
	wpai_issue4_assert( $created_page_one === (int) get_option( 'page_on_front' ), 'Front page did not update.' );
	wpai_issue4_assert( true === $updated['rewrite_flushed'], 'Permalink update did not report rewrite impact.' );
	$invalid_same_page = wpai_issue4_execute( 'wp-ai-bridge/site-settings-update', array( 'page_on_front'=>$created_page_one, 'page_for_posts'=>$created_page_one ) );
	wpai_issue4_assert( is_wp_error( $invalid_same_page ), 'Identical front and posts page IDs were accepted.' );

	$extensions = wpai_issue4_execute( 'wp-ai-bridge/extensions-read', array( 'kind' => 'all' ) );
	wpai_issue4_assert( ! empty( $extensions['plugins'] ) && ! empty( $extensions['themes'] ), 'Extension inspection did not return installed plugin/theme state.' );
	$bridge_found = false;
	foreach ( $extensions['plugins'] as $plugin ) {
		if ( false !== strpos( $plugin['file'], 'wp-ai-bridge' ) ) { $bridge_found = true; break; }
	}
	wpai_issue4_assert( $bridge_found, 'Extension inspection omitted the Bridge itself.' );
	$extension_denied = wpai_issue4_execute( 'wp-ai-bridge/extension-lifecycle', array( 'kind'=>'plugin', 'action'=>'activate', 'target'=>'not-installed/not-installed.php' ) );
	wpai_issue4_assert( is_wp_error( $extension_denied ), 'Extension lifecycle bypassed Code & Extensions.' );


	$inactive_fixture = '';
	foreach ( $extensions['plugins'] as $plugin ) {
		if ( empty( $plugin['active'] ) && false === strpos( $plugin['file'], 'wp-ai-bridge' ) ) {
			$inactive_fixture = (string) $plugin['file'];
			break;
		}
	}
	wpai_issue4_assert( '' !== $inactive_fixture, 'The isolated environment needs one inactive plugin fixture for reversible lifecycle validation.' );
	$access[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );
	$activated_plugin = wpai_issue4_execute( 'wp-ai-bridge/extension-lifecycle', array( 'kind'=>'plugin', 'action'=>'activate', 'target'=>$inactive_fixture ) );
	wpai_issue4_assert( ! is_wp_error( $activated_plugin ) && is_plugin_active( $inactive_fixture ), 'Core plugin activation failed under Code & Extensions.' );
	$deactivated_plugin = wpai_issue4_execute( 'wp-ai-bridge/extension-lifecycle', array( 'kind'=>'plugin', 'action'=>'deactivate', 'target'=>$inactive_fixture ) );
	wpai_issue4_assert( ! is_wp_error( $deactivated_plugin ) && ! is_plugin_active( $inactive_fixture ), 'Core plugin deactivation failed or did not restore the inactive fixture.' );
	$delete_plugin_denied = wpai_issue4_execute( 'wp-ai-bridge/extension-lifecycle', array( 'kind'=>'plugin', 'action'=>'delete', 'target'=>$inactive_fixture ) );
	wpai_issue4_assert( is_wp_error( $delete_plugin_denied ) && isset( get_plugins()[ $inactive_fixture ] ), 'Plugin deletion bypassed Users & Destructive.' );


	$inactive_theme = '';
	foreach ( $extensions['themes'] as $theme ) {
		if ( empty( $theme['active'] ) ) {
			$inactive_theme = (string) $theme['stylesheet'];
			break;
		}
	}
	wpai_issue4_assert( '' !== $inactive_theme, 'The isolated environment needs one inactive theme fixture for reversible lifecycle validation.' );
	$activated_theme = wpai_issue4_execute( 'wp-ai-bridge/extension-lifecycle', array( 'kind'=>'theme', 'action'=>'activate', 'target'=>$inactive_theme ) );
	wpai_issue4_assert( ! is_wp_error( $activated_theme ) && $inactive_theme === get_stylesheet(), 'Core theme activation failed under Code & Extensions.' );
	$restored_theme = wpai_issue4_execute( 'wp-ai-bridge/extension-lifecycle', array( 'kind'=>'theme', 'action'=>'activate', 'target'=>$original_theme ) );
	wpai_issue4_assert( ! is_wp_error( $restored_theme ) && $original_theme === get_stylesheet(), 'Core theme activation did not restore the original theme fixture.' );

	$users = wpai_issue4_execute( 'wp-ai-bridge/users-read', array( 'action'=>'list', 'limit'=>10 ) );
	wpai_issue4_assert( ! empty( $users['users'] ), 'User inspection did not return the current site user.' );
	foreach ( $users['users'] as $user ) {
		wpai_issue4_assert( ! isset( $user['user_pass'], $user['password'], $user['application_passwords'], $user['session_tokens'] ), 'User inspection exposed credential material.' );
	}
	$roles = wpai_issue4_execute( 'wp-ai-bridge/users-read', array( 'action'=>'roles' ) );
	wpai_issue4_assert( ! empty( $roles['roles'] ), 'Editable role inspection returned no roles.' );
	$user_denied = wpai_issue4_execute( 'wp-ai-bridge/user-upsert', array( 'action'=>'create', 'username'=>'wpai-denied', 'email'=>'wpai-denied@example.invalid', 'role'=>'subscriber' ) );
	wpai_issue4_assert( is_wp_error( $user_denied ), 'User create bypassed Users & Destructive.' );

	$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );
	add_filter( 'pre_wp_mail', $pre_mail, 10, 2 );
	$suffix = strtolower( wp_generate_password( 8, false, false ) );
	$created = wpai_issue4_execute(
		'wp-ai-bridge/user-upsert',
		array(
			'action'       => 'create',
			'username'     => 'wpai_' . $suffix,
			'email'        => 'wpai_' . $suffix . '@example.invalid',
			'display_name' => 'WPNB Issue 4 User',
			'role'         => 'subscriber',
		)
	);
	remove_filter( 'pre_wp_mail', $pre_mail, 10 );
	wpai_issue4_assert( ! is_wp_error( $created ), 'User creation failed under the correct group/capability.' );
	$created_user = (int) $created['user']['id'];
	wpai_issue4_assert( ! array_key_exists( 'password', $created ) && ! array_key_exists( 'user_pass', $created ), 'User creation response exposed generated credential material.' );
	$changed = wpai_issue4_execute( 'wp-ai-bridge/user-upsert', array( 'action'=>'update', 'id'=>$created_user, 'display_name'=>'WPNB Updated User', 'role'=>'contributor' ) );
	wpai_issue4_assert( ! is_wp_error($changed) && 'WPNB Updated User' === $changed['user']['display_name'], 'User update failed.' );
	wpai_issue4_assert( in_array( 'contributor', $changed['user']['roles'], true ), 'Role assignment failed.' );
	$self_delete = wpai_issue4_execute( 'wp-ai-bridge/user-remove', array( 'id'=>get_current_user_id(), 'reassign_id'=>$created_user ) );
	wpai_issue4_assert( is_wp_error( $self_delete ), 'Acting user self-removal was accepted.' );
	$removed = wpai_issue4_execute( 'wp-ai-bridge/user-remove', array( 'id'=>$created_user, 'reassign_id'=>get_current_user_id() ) );
	wpai_issue4_assert( ! is_wp_error( $removed ) && ! get_userdata( $created_user ), 'User removal with explicit reassignment failed.' );
	$created_user = 0;

	echo "PASS: Issue #4 core administration smoke.\n";
} finally {
	remove_filter( 'pre_wp_mail', $pre_mail, 10 );
	if ( get_stylesheet() !== $original_theme ) { switch_theme( $original_theme ); }
	if ( $created_user > 0 && get_userdata( $created_user ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $created_user, get_current_user_id() );
	}
	if ( $created_page_one > 0 ) { wp_delete_post( $created_page_one, true ); }
	if ( $created_page_two > 0 ) { wp_delete_post( $created_page_two, true ); }
	foreach ( $original_options as $key => $value ) { update_option( $key, $value ); }
	flush_rewrite_rules( false );
	update_option( Settings::OPTION_NAME, $original_access, false );
}
