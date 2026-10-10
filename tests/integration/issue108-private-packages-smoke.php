<?php
/**
 * Real native WordPress Core plugin/theme upgrade and fail-closed staged ZIP checks.
 *
 * Requires actual WordPress, the official Adapter, ZipArchive and an isolated site.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Package_Store;
use WP_AI_Bridge\Support\Settings;

$GLOBALS['wpai108_live_checks'] = 0;
function wpai108_live_check( $condition, $message ) {
	++$GLOBALS['wpai108_live_checks'];
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai108_live_zip( $kind, $root ) {
	$path = wp_tempnam( 'wpai108-fixture.zip' );
	wpai108_live_check( is_string( $path ) && '' !== $path, 'WordPress could not create an isolated ZIP fixture.' );
	$archive = new ZipArchive();
	wpai108_live_check( true === $archive->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'ZipArchive cannot write fixture.' );
	$archive->addEmptyDir( $root );
	if ( 'plugin' === $kind ) {
		$archive->addFromString( $root . '/' . $root . '.php', "<?php\n/*\nPlugin Name: WP AI Bridge Private Test\nVersion: 1.0\n*/\n" );
	} else {
		$archive->addFromString( $root . '/style.css', "/*\nTheme Name: WP AI Bridge Private Test\nVersion: 1.0\n*/\n" );
		$archive->addFromString( $root . '/index.php', "<?php\n" );
	}
	$archive->close();
	return $path;
}

function wpai108_live_stage( Private_Package_Store $store, $kind, $id, $client, $root ) {
	$property  = new ReflectionMethod( Private_Package_Store::class, 'directory' );
	$directory = $property->invoke( $store );
	wpai108_live_check( ! is_wp_error( $directory ), 'Private directory must be accessible and non-public.' );
	$source = wpai108_live_zip( $kind, $root );
	$dest   = $directory . '/' . $id . '.zip';
	wpai108_live_check( copy( $source, $dest ), 'Fixture setup could not stage a private ZIP.' );
	wp_delete_file( $source );
	wpai108_live_check( chmod( $dest, 0600 ), 'Fixture private file mode must be restrictive.' );
	$check = $store->inspect_zip( $dest, $kind );
	wpai108_live_check( ! is_wp_error( $check ) && $root === $check['root'], 'Real ZipArchive rejected legitimate package.' );
	$meta = array(
		'id'              => $id,
		'kind'            => $kind,
		'user_id'         => get_current_user_id(),
		'blog_id'         => get_current_blog_id(),
		'client_id'       => $client,
		'client_revision' => 0,
		'filename'        => $root . '.zip',
		'sha256'          => hash_file( 'sha256', $dest ),
		'bytes'           => filesize( $dest ),
		'root'            => $root,
		'created'         => time(),
		'expires'         => time() + 3600,
		'status'          => 'staged',
	);
	wpai108_live_check( add_option( Private_Package_Store::OPTION_PREFIX . $id, $meta, '', false ), 'Fixture metadata insertion must be unique.' );
	return array( $meta, $dest );
}

$settings                                    = new Settings();
$permissions                                 = new Permissions( $settings );
$store                                       = new Private_Package_Store( $permissions );
$original                                    = get_option( Settings::OPTION_NAME, $settings->defaults() );
$client                                      = OAuth_Server::CHATGPT_CLIENT_ID;
$grants                                      = $settings->defaults();
$grants[ Settings::GROUP_CODE_EXTENSIONS ]   = 1;
$grants[ Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
$plugin_root                                 = 'wpai108-private-plugin';
$theme_root                                  = 'wpai108-private-theme';
$plugin_key                                  = $plugin_root . '/' . $plugin_root . '.php';
$fixture_ids                                 = array();
$fixture_files                               = array();
$installed_plugin                            = false;
$installed_theme                             = false;
$original_log                                = get_option( Mutation_Log::OPTION_NAME, array() );
$mcp_client_context                          = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
$mcp_client_context->setValue( null, '' );

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

wpai108_live_check( class_exists( 'ZipArchive' ), 'Real WordPress suite requires PHP zip support.' );
wpai108_live_check( ! isset( get_plugins()[ $plugin_key ] ), 'Use an isolated WordPress site without fixture plugin.' );
wpai108_live_check( ! wp_get_theme( $theme_root )->exists(), 'Use an isolated WordPress site without fixture theme.' );
wpai108_live_check( wp_get_ability( 'wp-ai-bridge/private-packages-read' ) instanceof WP_Ability, 'Inspector WordPress Ability not registered.' );
wpai108_live_check( wp_get_ability( 'wp-ai-bridge/private-package-install' ) instanceof WP_Ability, 'Installer WordPress Ability not registered.' );
wpai108_live_check( ! $store->allowed( 'plugin' ), 'Default-off consent must deny WordPress installer.' );

try {
	update_option( Settings::OPTION_NAME, $grants, false );
	$mcp_client_context->setValue( null, $client );
	foreach ( array(
		'plugin' => $plugin_root,
		'theme'  => $theme_root,
	) as $kind => $root ) {
		if ( ! $store->allowed( $kind ) ) {
			// Multisite native install caps are only present for Super Admin.
			wpai108_live_check( false, 'Isolated administrator lacks native ' . $kind . ' install authority.' );
		}
		$id                  = bin2hex( random_bytes( 24 ) );
		$fixture_ids[]       = $id;
		list( $meta, $path ) = wpai108_live_stage( $store, $kind, $id, $client, $root );
		$fixture_files[]     = $path;

		$inspector = wp_get_ability( 'wp-ai-bridge/private-packages-read' );
		$details   = $inspector->execute(
			array(
				'action'      => 'get',
				'artifact_id' => $id,
			)
		);
		wpai108_live_check( ! is_wp_error( $details ) && ( $details['item']['sha256'] ?? '' ) === $meta['sha256'], 'Real Core WordPress Ability must expose exact reviewed metadata.' );
		wpai108_live_check( false === strpos( wp_json_encode( $details ), $path ), 'Native Ability must not expose staging path.' );
		$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
		wpai108_live_check( $adapter instanceof WP_Ability, 'Official MCP Adapter execute wrapper is unavailable.' );
		$wrapped = $adapter->execute(
			array(
				'ability_name' => 'wp-ai-bridge/private-packages-read',
				'parameters'   => array(
					'action'      => 'get',
					'artifact_id' => $id,
				),
			)
		);
		wpai108_live_check( ! is_wp_error( $wrapped ) && true === ( $wrapped['success'] ?? false ), 'Official MCP Adapter must preserve bounded private ZIP inspection.' );

		$bad = $store->install( $id, str_repeat( '0', 64 ), $kind, $client );
		wpai108_live_check( is_wp_error( $bad ) && 'private_package_hash_mismatch' === $bad->get_error_code(), 'Wrong SHA must be rejected before claim.' );

		$grants[ Settings::GROUP_EXTERNAL_PACKAGES ] = 0;
		update_option( Settings::OPTION_NAME, $grants, false );
		$denied = $store->install( $id, $meta['sha256'], $kind, $client );
		wpai108_live_check( is_wp_error( $denied ), 'Revoking package permission must prevent Core install.' );
		$grants[ Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
		update_option( Settings::OPTION_NAME, $grants, false );

		$installer = wp_get_ability( 'wp-ai-bridge/private-package-install' );
		$ok        = $installer->execute(
			array(
				'artifact_id' => $id,
				'sha256'      => $meta['sha256'],
				'kind'        => $kind,
			)
		);
		wpai108_live_check( ! is_wp_error( $ok ) && true === $ok['installed'] && false === $ok['activated'], 'Native Core must install without activating the extension.' );
		if ( 'plugin' === $kind ) {
			$installed_plugin = true;
			wpai108_live_check( $plugin_key === $ok['target'] && isset( get_plugins()[ $plugin_key ] ), 'Native plugin installed identity must match reviewed archive.' );
			wpai108_live_check( ! is_plugin_active( $plugin_key ), 'Private plugin installation silently activated code.' );
		} else {
			$installed_theme = true;
			wpai108_live_check( $theme_root === $ok['target'] && wp_get_theme( $theme_root )->exists(), 'Native theme installed identity must match reviewed archive.' );
			wpai108_live_check( get_stylesheet() !== $theme_root, 'Private theme install silently switched active theme.' );
		}
		wpai108_live_check( ! is_file( $path ), 'Native successful install left staged executable ZIP.' );
		$recent = ( new Mutation_Log() )->recent( 1 );
		wpai108_live_check( ! empty( $recent ) && 'wp-ai-bridge/private-package-install' === $recent[0]['ability'] && true === $recent[0]['success'], 'Successful Core install must produce a secret-free audit entry.' );
		$second = $store->install( $id, $meta['sha256'], $kind, $client );
		wpai108_live_check( is_wp_error( $second ) && 'private_package_not_staged' === $second->get_error_code(), 'Replay must not begin second native install.' );
	}

	// A second ZIP targeting an already installed plugin must never be silently
	// retried after the native Upgrader reports an uncertain/failed outcome.
	$collision_id                            = bin2hex( random_bytes( 24 ) );
	$fixture_ids[]                           = $collision_id;
	list( $collision_meta, $collision_file ) = wpai108_live_stage( $store, 'plugin', $collision_id, $client, $plugin_root );
	$fixture_files[]                         = $collision_file;
	$failed_install                          = $store->install( $collision_id, $collision_meta['sha256'], 'plugin', $client );
	wpai108_live_check( is_wp_error( $failed_install ) && 'private_package_recovery_required' === $failed_install->get_error_code(), 'Native destination conflict must become a bounded recovery-required outcome.' );
	$failed_record = $store->inspect( $collision_id, $client );
	wpai108_live_check( ! is_wp_error( $failed_record ) && 'outcome_unknown' === $failed_record['status'], 'Ambiguous Core failure must persist an unretryable outcome.' );
	wpai108_live_check( is_wp_error( $store->install( $collision_id, $collision_meta['sha256'], 'plugin', $client ) ), 'A failed/ambiguous native install must reject replay.' );
	wpai108_live_check( isset( get_plugins()[ $plugin_key ] ) && ! is_plugin_active( $plugin_key ), 'Failed second installation must not modify/activate the original fixture.' );
	$recent_failure = ( new Mutation_Log() )->recent( 1 );
	wpai108_live_check( ! empty( $recent_failure ) && false === $recent_failure[0]['success'] && 'private_package_recovery_required' === $recent_failure[0]['error_code'], 'Uncertain Core effect must leave a bounded audit record.' );

	// R2: WordPress can report option persistence failure without throwing.
	// Core has already installed the package, but its audit write must fail
	// closed with a retained claim, a visible recovery record and no replay.
	$audit_root                      = 'wpai108-audit-failure';
	$audit_key                       = $audit_root . '/' . $audit_root . '.php';
	$audit_id                        = bin2hex( random_bytes( 24 ) );
	$fixture_ids[]                   = $audit_id;
	list( $audit_meta, $audit_path ) = wpai108_live_stage( $store, 'plugin', $audit_id, $client, $audit_root );
	$fixture_files[]                 = $audit_path;
	$audit_denial                    = static function ( $value, $old ) {
		return $old;
	};
	add_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $audit_denial, 10, 2 );
	try {
		$audit_result = $store->install( $audit_id, $audit_meta['sha256'], 'plugin', $client );
	} finally {
		remove_filter( 'pre_update_option_' . Mutation_Log::OPTION_NAME, $audit_denial, 10 );
	}
	wpai108_live_check( is_wp_error( $audit_result ) && 'private_package_recovery_required' === $audit_result->get_error_code(), 'Silent audit persistence refusal after native Core must return recovery-required.' );
	wpai108_live_check( isset( get_plugins()[ $audit_key ] ) && ! is_plugin_active( $audit_key ), 'Audit failure must not falsely roll back Core or activate its installed package.' );
	$audit_record = $store->inspect( $audit_id, $client );
	wpai108_live_check( ! is_wp_error( $audit_record ) && 'outcome_unknown' === $audit_record['status'], 'Audit persistence refusal must leave exact private artifact outcome unknown.' );
	wpai108_live_check( false !== get_option( Private_Package_Store::CLAIM_PREFIX . $audit_id, false ), 'Audit failure must retain the one-way Core installation claim.' );
	wpai108_live_check( is_wp_error( $store->install( $audit_id, $audit_meta['sha256'], 'plugin', $client ) ), 'Silent audit failure must not permit blind installation replay.' );
	wpai108_live_check( ! is_file( $audit_path ), 'Audit failure must not retain private executable ZIP bytes.' );
	wpai108_live_check( ( new Mutation_Log() )->record( 'wp-ai-bridge/private-package-test', 'plugin', 0, true, '' ), 'Normal durable audit must still succeed when the test failure injection is removed.' );

	// R4-A: refusing the pre-Core installing status must stop Core entirely.
	// A one-way claim persists, but private executable ZIP bytes are retired.
	$pre_root                    = 'wpai108-prestate-failure';
	$pre_key                     = $pre_root . '/' . $pre_root . '.php';
	$pre_id                      = bin2hex( random_bytes( 24 ) );
	$fixture_ids[]               = $pre_id;
	list( $pre_meta, $pre_path ) = wpai108_live_stage( $store, 'plugin', $pre_id, $client, $pre_root );
	$fixture_files[]             = $pre_path;
	$pre_state_refusal           = static function ( $value, $old ) {
		return 'installing' === ( $value['status'] ?? '' ) ? $old : $value;
	};
	add_filter( 'pre_update_option_' . Private_Package_Store::OPTION_PREFIX . $pre_id, $pre_state_refusal, 10, 2 );
	try {
		$pre_result = $store->install( $pre_id, $pre_meta['sha256'], 'plugin', $client );
	} finally {
		remove_filter( 'pre_update_option_' . Private_Package_Store::OPTION_PREFIX . $pre_id, $pre_state_refusal, 10 );
	}
	wpai108_live_check( is_wp_error( $pre_result ) && 'private_package_recovery_required' === $pre_result->get_error_code(), 'Pre-Core state persistence refusal must return recovery-required.' );
	wpai108_live_check( ! isset( get_plugins()[ $pre_key ] ), 'Pre-Core metadata failure must never begin native installation.' );
	$pre_record = $store->inspect( $pre_id, $client );
	wpai108_live_check( ! is_wp_error( $pre_record ) && 'outcome_unknown' === $pre_record['status'], 'Pre-Core state refusal must leave a bounded recovery tombstone.' );
	wpai108_live_check( false !== get_option( Private_Package_Store::CLAIM_PREFIX . $pre_id, false ), 'Pre-Core metadata refusal must preserve one-way claim.' );
	wpai108_live_check( ! is_file( $pre_path ), 'Pre-Core metadata refusal must retire private ZIP bytes.' );
	wpai108_live_check( is_wp_error( $store->install( $pre_id, $pre_meta['sha256'], 'plugin', $client ) ), 'Pre-Core state refusal must never permit retry.' );

	// R4-B: Core may commit successfully while WordPress refuses the final
	// installed-state option update. The result must never claim installed=true.
	$post_root                     = 'wpai108-poststate-failure';
	$post_key                      = $post_root . '/' . $post_root . '.php';
	$post_id                       = bin2hex( random_bytes( 24 ) );
	$fixture_ids[]                 = $post_id;
	list( $post_meta, $post_path ) = wpai108_live_stage( $store, 'plugin', $post_id, $client, $post_root );
	$fixture_files[]               = $post_path;
	$post_state_refusal            = static function ( $value, $old ) {
		return 'installed' === ( $value['status'] ?? '' ) ? $old : $value;
	};
	add_filter( 'pre_update_option_' . Private_Package_Store::OPTION_PREFIX . $post_id, $post_state_refusal, 10, 2 );
	try {
		$post_result = $store->install( $post_id, $post_meta['sha256'], 'plugin', $client );
	} finally {
		remove_filter( 'pre_update_option_' . Private_Package_Store::OPTION_PREFIX . $post_id, $post_state_refusal, 10 );
	}
	wpai108_live_check( is_wp_error( $post_result ) && 'private_package_recovery_required' === $post_result->get_error_code(), 'Post-Core durable installed-state refusal must never report success.' );
	wpai108_live_check( isset( get_plugins()[ $post_key ] ) && ! is_plugin_active( $post_key ), 'Post-Core refusal must retain the real installed inactive plugin for manual reconciliation.' );
	$post_record = $store->inspect( $post_id, $client );
	wpai108_live_check( ! is_wp_error( $post_record ) && 'outcome_unknown' === $post_record['status'], 'Post-Core metadata failure must retain a bounded recoverable uncertain outcome.' );
	wpai108_live_check( false !== get_option( Private_Package_Store::CLAIM_PREFIX . $post_id, false ), 'Post-Core metadata failure must retain one-way claim.' );
	wpai108_live_check( ! is_file( $post_path ), 'Post-Core metadata failure must retire private executable ZIP bytes.' );
	wpai108_live_check( is_wp_error( $store->install( $post_id, $post_meta['sha256'], 'plugin', $client ) ), 'Post-Core metadata failure must never allow a replay.' );

	// Expiry must retire bytes while retaining only a short-lived metadata
	// record. The expiry path does not need to execute or unpack the archive.
	$expired_id                          = bin2hex( random_bytes( 24 ) );
	$fixture_ids[]                       = $expired_id;
	list( $expired_meta, $expired_file ) = wpai108_live_stage( $store, 'theme', $expired_id, $client, 'wpai108-expired-theme' );
	$fixture_files[]                     = $expired_file;
	$expired_meta['expires']             = time() - 2;
	update_option( Private_Package_Store::OPTION_PREFIX . $expired_id, $expired_meta, false );
	$store->cleanup_expired();
	wpai108_live_check( ! is_file( $expired_file ), 'Expired private ZIP must be retired by bounded Core-owned cleanup.' );
	$expired_review = $store->inspect( $expired_id, $client );
	wpai108_live_check( ! is_wp_error( $expired_review ) && 'expired' === $expired_review['status'], 'Expired artifact must never be reported staged.' );
} finally {
	$mcp_client_context->setValue( null, '' );
	update_option( Mutation_Log::OPTION_NAME, $original_log, false );
	update_option( Settings::OPTION_NAME, $original, false );
	if ( $installed_plugin && isset( get_plugins()[ $plugin_key ] ) && ! is_plugin_active( $plugin_key ) ) {
		delete_plugins( array( $plugin_key ) );
	}
	if ( isset( $audit_key ) && isset( get_plugins()[ $audit_key ] ) && ! is_plugin_active( $audit_key ) ) {
		delete_plugins( array( $audit_key ) );
	}
	if ( isset( $post_key ) && isset( get_plugins()[ $post_key ] ) && ! is_plugin_active( $post_key ) ) {
		delete_plugins( array( $post_key ) );
	}
	if ( $installed_theme && wp_get_theme( $theme_root )->exists() && get_stylesheet() !== $theme_root ) {
		delete_theme( $theme_root );
	}
	foreach ( $fixture_ids as $id ) {
		delete_option( Private_Package_Store::OPTION_PREFIX . $id );
		delete_option( Private_Package_Store::CLAIM_PREFIX . $id );
	}
	foreach ( $fixture_files as $path ) {
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
echo 'PASS: Issue #108 real native WordPress plugin/theme integration (' . $GLOBALS['wpai108_live_checks'] . " assertions).\n";
