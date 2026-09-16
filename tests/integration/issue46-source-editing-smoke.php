<?php
/** Real WordPress source-editing lifecycle coverage for Issue #46. */

use WP_Native_Builder_Bridge\Abilities\Source_Editing_Abilities;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

function wpai_issue46_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings            = new Settings();
$original_settings   = get_option( Settings::OPTION_NAME, array() );
$original_siteurl    = get_option( 'siteurl' );
$original_home       = get_option( 'home' );
$original_theme      = get_stylesheet();
$plugin              = 'wpai-source-fixture/wpai-source-fixture.php';
$plugin_dir          = WP_PLUGIN_DIR . '/wpai-source-fixture';
$plugin_file         = $plugin_dir . '/wpai-source-fixture.php';
$plugin_helper       = $plugin_dir . '/helper.php';
$post_publish_explicit_removed = $plugin_dir . '/post-publish-explicit-removed.php';
$post_publish_shutdown_removed = $plugin_dir . '/post-publish-shutdown-removed.php';
$outside_file        = WP_CONTENT_DIR . '/wpai-source-outside.php';
$symlink_file        = $plugin_dir . '/escape.php';
$outside_plugin_dir  = WP_CONTENT_DIR . '/wpai-source-outside-plugin';
$outside_plugin_file = $outside_plugin_dir . '/wpai-source-linked.php';
$linked_plugin_dir   = WP_PLUGIN_DIR . '/wpai-source-linked';
$linked_plugin       = 'wpai-source-linked/wpai-source-linked.php';
$theme_slug                 = 'wpai-source-theme';
$theme_dir                  = get_theme_root() . '/' . $theme_slug;
$theme_style                = $theme_dir . '/style.css';
$theme_functions            = $theme_dir . '/functions.php';
$registered_theme_root      = WP_CONTENT_DIR . '/wpai-registered-theme-root';
$registered_theme_slug      = 'wpai-registered-source-theme';
$registered_theme_dir       = $registered_theme_root . '/' . $registered_theme_slug;
$registered_theme_style     = $registered_theme_dir . '/style.css';
$registered_theme_functions = $registered_theme_dir . '/functions.php';
$unregistered_theme_root    = WP_CONTENT_DIR . '/wpai-unregistered-theme-root';
$unregistered_theme_slug    = 'wpai-unregistered-source-theme';
$unregistered_theme_dir     = $unregistered_theme_root . '/' . $unregistered_theme_slug;
$unregistered_theme_style   = $unregistered_theme_dir . '/style.css';
$unregistered_theme_file    = $unregistered_theme_dir . '/functions.php';
$plugin_original     = "<?php\n/*\nPlugin Name: WPNB Source Fixture\n*/\nfunction wpai_source_fixture_value() { return 'original'; }\n";
$helper_original     = "<?php\nfunction wpai_source_fixture_helper() { return 'helper'; }\n";
$theme_original                = "<?php\nfunction wpai_source_theme_value() { return 'theme-original'; }\n";
$registered_theme_style_original = "/*\nTheme Name: WPNB Registered Source Theme\nVersion: 1.0.0\n*/\n";
$external_lock                 = null;
$retained_inode      = null;
$late_stale_inode    = null;

try {
	wp_mkdir_p( $plugin_dir );
	wp_mkdir_p( $theme_dir );
	wp_mkdir_p( $registered_theme_dir );
	wp_mkdir_p( $unregistered_theme_dir );
	wp_mkdir_p( $outside_plugin_dir );
	file_put_contents( $plugin_file, $plugin_original );
	file_put_contents( $plugin_helper, $helper_original );
	file_put_contents( $outside_file, "<?php\n// outside fixture\n" );
	file_put_contents( $outside_plugin_file, "<?php\n/* Plugin Name: WPNB Linked Outside Source */\n" );
	file_put_contents( $theme_style, "/*\nTheme Name: WPNB Source Theme\nVersion: 1.0.0\n*/\n" );
	file_put_contents( $theme_functions, $theme_original );
	file_put_contents( $registered_theme_style, $registered_theme_style_original );
	file_put_contents( $registered_theme_functions, $theme_original );
	file_put_contents( $unregistered_theme_style, "/*\nTheme Name: WPNB Unregistered Source Theme\nVersion: 1.0.0\n*/\n" );
	file_put_contents( $unregistered_theme_file, $theme_original );
	@unlink( $linked_plugin_dir );
	symlink( $outside_plugin_dir, $linked_plugin_dir );
	@unlink( $symlink_file );
	symlink( $outside_file, $symlink_file );
	chmod( $plugin_file, 0666 );
	chmod( $plugin_helper, 0666 );
	chmod( $outside_plugin_file, 0666 );
	chmod( $theme_style, 0666 );
	chmod( $theme_functions, 0666 );
	chmod( $registered_theme_style, 0666 );
	chmod( $registered_theme_functions, 0666 );
	chmod( $unregistered_theme_style, 0666 );
	chmod( $unregistered_theme_file, 0666 );
	wpai_issue46_assert( register_theme_directory( $registered_theme_root ), 'Could not register non-default theme root fixture.' );
	wp_clean_plugins_cache( true );
	wp_clean_themes_cache( true );

	$defaults = $settings->defaults();
	wpai_issue46_assert( 0 === $defaults[ Settings::GROUP_SOURCE_EDITING ], 'Source Editing must default off.' );
	$legacy                                    = $defaults;
	$legacy[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	unset( $legacy[ Settings::GROUP_SOURCE_EDITING ] );
	update_option( Settings::OPTION_NAME, $legacy, false );
	wpai_issue46_assert( 0 === $settings->all()[ Settings::GROUP_SOURCE_EDITING ], 'Legacy Code & Extensions consent silently enabled Source Editing.' );

	$read    = wp_get_ability( 'wp-ai-bridge/source-files-read' );
	$preview = wp_get_ability( 'wp-ai-bridge/source-file-preview' );
	$apply   = wp_get_ability( 'wp-ai-bridge/source-file-apply' );
	$recover = wp_get_ability( 'wp-ai-bridge/source-file-recover' );
	wpai_issue46_assert( $read instanceof WP_Ability && $preview instanceof WP_Ability && $apply instanceof WP_Ability && $recover instanceof WP_Ability, 'Issue #46 native Abilities were not registered.' );

	$target = array(
		'kind'      => 'plugin',
		'extension' => $plugin,
		'file'      => 'wpai-source-fixture.php',
	);
	wpai_issue46_assert( is_wp_error( $read->execute( array_merge( array( 'action' => 'read' ), $target ) ) ), 'Code & Extensions alone exposed source content.' );

	$enabled                                    = $settings->defaults();
	$enabled[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	$enabled[ Settings::GROUP_SOURCE_EDITING ]  = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );

	$list = $read->execute(
		array(
			'action'    => 'list',
			'kind'      => 'plugin',
			'extension' => $plugin,
			'per_page'  => 100,
		)
	);
	wpai_issue46_assert( ! is_wp_error( $list ) && $list['total'] >= 2, 'Generic plugin source inventory did not expose fixture files.' );
	wpai_issue46_assert( false === strpos( wp_json_encode( $list ), "return 'original'" ), 'Source inventory leaked source payloads.' );

	$read_result = $read->execute( array_merge( array( 'action' => 'read' ), $target ) );
	wpai_issue46_assert( ! is_wp_error( $read_result ) && $plugin_original === $read_result['content'], 'Elevated exact source read did not return current bytes.' );

	$adapter_info = wp_get_ability( 'mcp-adapter/get-ability-info' )->execute( array( 'ability_name' => 'wp-ai-bridge/source-files-read' ) );
	wpai_issue46_assert( ! is_wp_error( $adapter_info ) && 'wp-ai-bridge/source-files-read' === $adapter_info['name'], 'MCP Adapter could not inspect the source-reading Ability.' );
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	$wrapped = $adapter->execute(
		array(
			'ability_name' => 'wp-ai-bridge/source-files-read',
			'parameters'   => array(
				'action'    => 'list',
				'kind'      => 'plugin',
				'extension' => $plugin,
			),
		)
	);
	wpai_issue46_assert( ! is_wp_error( $wrapped ) && true === $wrapped['success'] && $wrapped['data']['total'] >= 2, 'MCP Adapter did not preserve elevated source inventory execution.' );

	$syntax_bad = $preview->execute( array_merge( $target, array( 'candidate' => "<?php\nfunction broken( {\n" ) ) );
	wpai_issue46_assert( is_wp_error( $syntax_bad ) && 'source_php_syntax_invalid' === $syntax_bad->get_error_code(), 'Invalid PHP candidate bypassed TOKEN_PARSE preflight.' );
	wpai_issue46_assert( $plugin_original === file_get_contents( $plugin_file ), 'Syntax rejection changed source bytes.' );

	$traversal = $read->execute(
		array(
			'action'    => 'read',
			'kind'      => 'plugin',
			'extension' => $plugin,
			'file'      => '../wp-config.php',
		)
	);
	wpai_issue46_assert( is_wp_error( $traversal ), 'Traversal source target was accepted.' );
	$symlink = $read->execute(
		array(
			'action'    => 'read',
			'kind'      => 'plugin',
			'extension' => $plugin,
			'file'      => 'escape.php',
		)
	);
	wpai_issue46_assert( is_wp_error( $symlink ) && 'source_path_escape' === $symlink->get_error_code(), 'Symlink escape was not rejected by canonical containment.' );
	wpai_issue46_assert( isset( get_plugins()[ $linked_plugin ] ), 'Symlinked plugin-directory fixture was not discovered by WordPress.' );
	$linked_root_escape = $read->execute(
		array(
			'action'    => 'read',
			'kind'      => 'plugin',
			'extension' => $linked_plugin,
			'file'      => 'wpai-source-linked.php',
		)
	);
	wpai_issue46_assert( is_wp_error( $linked_root_escape ) && 'source_path_escape' === $linked_root_escape->get_error_code(), 'Symlinked plugin root escaped the canonical plugins directory.' );

	$candidate_a = str_replace( "'original'", "'candidate-a'", $plugin_original );
	$preview_a   = $preview->execute( array_merge( $target, array( 'candidate' => $candidate_a ) ) );
	wpai_issue46_assert( ! is_wp_error( $preview_a ), 'Valid inactive-plugin preview failed.' );
	$concurrent = str_replace( "'original'", "'concurrent'", $plugin_original );
	file_put_contents( $plugin_file, $concurrent );
	$stale = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $candidate_a,
				'preimage_sha256'  => $preview_a['preimage_sha256'],
				'candidate_sha256' => $preview_a['candidate_sha256'],
				'candidate_id'     => $preview_a['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( is_wp_error( $stale ) && 'source_preimage_stale' === $stale->get_error_code(), 'Stale preview overwrote a concurrent edit.' );
	wpai_issue46_assert( $concurrent === file_get_contents( $plugin_file ), 'Stale apply changed the concurrent source bytes.' );
	file_put_contents( $plugin_file, $plugin_original );

	$locked_candidate = str_replace( "'original'", "'locked-attempt'", $plugin_original );
	$locked_preview   = $preview->execute( array_merge( $target, array( 'candidate' => $locked_candidate ) ) );
	wpai_issue46_assert( ! is_wp_error( $locked_preview ), 'Lock-contention preview failed.' );
	$external_lock = new SplFileObject( $plugin_file, 'rb' );
	wpai_issue46_assert( $external_lock->flock( LOCK_EX | LOCK_NB ), 'Could not acquire external lock fixture.' );
	$locked_apply = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $locked_candidate,
				'preimage_sha256'  => $locked_preview['preimage_sha256'],
				'candidate_sha256' => $locked_preview['candidate_sha256'],
				'candidate_id'     => $locked_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( is_wp_error( $locked_apply ) && 'source_edit_locked' === $locked_apply->get_error_code(), 'Source apply bypassed an existing cooperative file lock.' );
	wpai_issue46_assert( $plugin_original === file_get_contents( $plugin_file ), 'Lock contention changed source bytes.' );
	$external_lock->flock( LOCK_UN );
	$external_lock = null;

	// A non-cooperating writer that recreates the target after Bridge's final ownership check wins.
	$apply_race_bytes     = str_replace( "'original'", "'third-party-apply-race'", $plugin_original );
	$apply_race           = new Source_Editing_Abilities(
		new Permissions( $settings ),
		new Mutation_Log(),
		static function ( $phase ) use ( $plugin_file, $apply_race_bytes ) {
			if ( 'apply' === $phase ) {
				file_put_contents( $plugin_file, $apply_race_bytes );
			}
		}
	);
	$apply_race_candidate = str_replace( "'original'", "'bridge-apply-race'", $plugin_original );
	$apply_race_preview   = $apply_race->preview( array_merge( $target, array( 'candidate' => $apply_race_candidate ) ) );
	wpai_issue46_assert( ! is_wp_error( $apply_race_preview ), 'Adversarial apply preview failed.' );
	$apply_race_result = $apply_race->apply(
		array_merge(
			$target,
			array(
				'candidate'        => $apply_race_candidate,
				'preimage_sha256'  => $apply_race_preview['preimage_sha256'],
				'candidate_sha256' => $apply_race_preview['candidate_sha256'],
				'candidate_id'     => $apply_race_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( is_wp_error( $apply_race_result ) && 'source_concurrent_write_detected' === $apply_race_result->get_error_code(), 'Non-locking apply race was not rejected at the publish boundary.' );
	wpai_issue46_assert( $apply_race_bytes === file_get_contents( $plugin_file ), 'Bridge overwrote the non-locking apply race bytes.' );
	wpai_issue46_assert( is_array( get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ) ), 'Apply race did not retain exact recovery ownership.' );
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );
	file_put_contents( $plugin_file, $plugin_original );
	chmod( $plugin_file, 0666 );

	// A writer retaining the pre-replacement inode may mutate that inode while also recreating the live pathname.
	$retained_inode_bytes = str_replace( "'original'", "'third-party-retained-inode'", $plugin_original );
	$retained_path_bytes  = str_replace( "'original'", "'third-party-retained-path'", $plugin_original );
	$retained_inode       = fopen( $plugin_file, 'r+b' );
	wpai_issue46_assert( is_resource( $retained_inode ), 'Could not open retained-inode race fixture.' );
	$retained_race = new Source_Editing_Abilities(
		new Permissions( $settings ),
		new Mutation_Log(),
		static function ( $phase ) use ( $plugin_file, $retained_inode, $retained_inode_bytes, $retained_path_bytes ) {
			if ( 'apply' !== $phase ) {
				return;
			}
			rewind( $retained_inode );
			ftruncate( $retained_inode, 0 );
			fwrite( $retained_inode, $retained_inode_bytes );
			fflush( $retained_inode );
			file_put_contents( $plugin_file, $retained_path_bytes );
		}
	);
	$retained_candidate = str_replace( "'original'", "'bridge-retained-race'", $plugin_original );
	$retained_preview   = $retained_race->preview( array_merge( $target, array( 'candidate' => $retained_candidate ) ) );
	wpai_issue46_assert( ! is_wp_error( $retained_preview ), 'Retained-inode adversarial preview failed.' );
	$retained_result = $retained_race->apply(
		array_merge(
			$target,
			array(
				'candidate'        => $retained_candidate,
				'preimage_sha256'  => $retained_preview['preimage_sha256'],
				'candidate_sha256' => $retained_preview['candidate_sha256'],
				'candidate_id'     => $retained_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( is_wp_error( $retained_result ) && 'source_recovery_required' === $retained_result->get_error_code(), 'Retained-inode race did not fail closed for reconciliation.' );
	wpai_issue46_assert( $retained_path_bytes === file_get_contents( $plugin_file ), 'Bridge overwrote the recreated live pathname in the retained-inode race.' );
	$retained_holds = glob( $plugin_dir . '/.ht-wpai-source-cas-*.hold' );
	wpai_issue46_assert( is_array( $retained_holds ) && 1 === count( $retained_holds ), 'Retained-inode race did not preserve exactly one quarantined version.' );
	wpai_issue46_assert( $retained_inode_bytes === file_get_contents( $retained_holds[0] ), 'Bridge discarded or altered newer bytes written through the retained inode.' );
	wpai_issue46_assert( is_array( get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ) ), 'Retained-inode race discarded pending recovery ownership.' );
	fclose( $retained_inode );
	$retained_inode = null;
	@unlink( $retained_holds[0] );
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );
	file_put_contents( $plugin_file, $plugin_original );
	chmod( $plugin_file, 0666 );

	// Private artifact cleanup is part of the recovery boundary: probe/stage/hold removal failures
	// must keep recovery ownership and must never produce terminal success.
	$cleanup_repair = new Source_Editing_Abilities( new Permissions( $settings ), new Mutation_Log() );

	$probe_cleanup_candidate = str_replace( "'original'", "'probe-cleanup-candidate'", $plugin_original );
	$probe_cleanup = new Source_Editing_Abilities(
		new Permissions( $settings ),
		new Mutation_Log(),
		null,
		static function ( $artifact ) {
			return '.probe' !== substr( $artifact, -6 );
		}
	);
	$probe_cleanup_preview = $probe_cleanup->preview( array_merge( $target, array( 'candidate' => $probe_cleanup_candidate ) ) );
	wpai_issue46_assert( ! is_wp_error( $probe_cleanup_preview ), 'Probe-cleanup failure preview failed.' );
	$probe_cleanup_result = $probe_cleanup->apply(
		array_merge(
			$target,
			array(
				'candidate'        => $probe_cleanup_candidate,
				'preimage_sha256'  => $probe_cleanup_preview['preimage_sha256'],
				'candidate_sha256' => $probe_cleanup_preview['candidate_sha256'],
				'candidate_id'     => $probe_cleanup_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( is_wp_error( $probe_cleanup_result ) && 'source_recovery_artifact_pending' === $probe_cleanup_result->get_error_code(), 'Probe cleanup failure did not fail closed.' );
	wpai_issue46_assert( $plugin_original === file_get_contents( $plugin_file ), 'Probe cleanup failure crossed into live-path mutation.' );
	$probe_cleanup_record = get_option( Source_Editing_Abilities::RECOVERY_OPTION, false );
	wpai_issue46_assert( is_array( $probe_cleanup_record ), 'Probe cleanup failure discarded recovery ownership.' );
	wpai_issue46_assert( count( glob( $plugin_dir . '/.ht-wpai-source-cas-*.*' ) ) >= 2, 'Probe cleanup failure did not retain identifiable private artifacts.' );
	$probe_cleanup_recovered = $cleanup_repair->recover( array( 'candidate_sha256' => $probe_cleanup_record['candidate_sha256'] ) );
	wpai_issue46_assert( ! is_wp_error( $probe_cleanup_recovered ) && true === $probe_cleanup_recovered['recovered'], 'Probe cleanup artifacts could not be reconciled by the retained recovery owner.' );
	wpai_issue46_assert( false === get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ), 'Probe cleanup reconciliation left recovery ownership behind.' );
	wpai_issue46_assert( 0 === count( glob( $plugin_dir . '/.ht-wpai-source-cas-*.*' ) ), 'Probe cleanup reconciliation left private artifacts behind.' );

	file_put_contents( $plugin_file, $plugin_original );
	chmod( $plugin_file, 0666 );
	$stage_cleanup_candidate = str_replace( "'original'", "'stage-cleanup-candidate'", $plugin_original );
	$stage_cleanup = new Source_Editing_Abilities(
		new Permissions( $settings ),
		new Mutation_Log(),
		null,
		static function ( $artifact ) {
			return '.stage' !== substr( $artifact, -6 );
		}
	);
	$stage_cleanup_preview = $stage_cleanup->preview( array_merge( $target, array( 'candidate' => $stage_cleanup_candidate ) ) );
	$stage_cleanup_result  = $stage_cleanup->apply(
		array_merge(
			$target,
			array(
				'candidate'        => $stage_cleanup_candidate,
				'preimage_sha256'  => $stage_cleanup_preview['preimage_sha256'],
				'candidate_sha256' => $stage_cleanup_preview['candidate_sha256'],
				'candidate_id'     => $stage_cleanup_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( is_wp_error( $stage_cleanup_result ) && 'source_recovery_artifact_pending' === $stage_cleanup_result->get_error_code(), 'Stage cleanup failure produced terminal success.' );
	wpai_issue46_assert( $stage_cleanup_candidate === file_get_contents( $plugin_file ), 'Stage cleanup failure did not preserve the published candidate generation.' );
	$stage_cleanup_record = get_option( Source_Editing_Abilities::RECOVERY_OPTION, false );
	wpai_issue46_assert( is_array( $stage_cleanup_record ), 'Stage cleanup failure discarded recovery ownership.' );
	$stage_cleanup_recovered = $cleanup_repair->recover( array( 'candidate_sha256' => $stage_cleanup_record['candidate_sha256'] ) );
	wpai_issue46_assert( ! is_wp_error( $stage_cleanup_recovered ) && true === $stage_cleanup_recovered['recovered'], 'Stage cleanup failure could not be reconciled through recovery.' );
	wpai_issue46_assert( $plugin_original === file_get_contents( $plugin_file ), 'Stage cleanup reconciliation did not restore the exact preimage.' );

	file_put_contents( $plugin_file, $plugin_original );
	chmod( $plugin_file, 0666 );
	$hold_cleanup_candidate = str_replace( "'original'", "'hold-cleanup-candidate'", $plugin_original );
	$hold_cleanup = new Source_Editing_Abilities(
		new Permissions( $settings ),
		new Mutation_Log(),
		null,
		static function ( $artifact ) {
			return '.hold' !== substr( $artifact, -5 );
		}
	);
	$hold_cleanup_preview = $hold_cleanup->preview( array_merge( $target, array( 'candidate' => $hold_cleanup_candidate ) ) );
	$hold_cleanup_result  = $hold_cleanup->apply(
		array_merge(
			$target,
			array(
				'candidate'        => $hold_cleanup_candidate,
				'preimage_sha256'  => $hold_cleanup_preview['preimage_sha256'],
				'candidate_sha256' => $hold_cleanup_preview['candidate_sha256'],
				'candidate_id'     => $hold_cleanup_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( is_wp_error( $hold_cleanup_result ) && 'source_recovery_artifact_pending' === $hold_cleanup_result->get_error_code(), 'Hold cleanup failure produced terminal success.' );
	wpai_issue46_assert( $hold_cleanup_candidate === file_get_contents( $plugin_file ), 'Hold cleanup failure changed the published candidate generation.' );
	$hold_cleanup_record = get_option( Source_Editing_Abilities::RECOVERY_OPTION, false );
	wpai_issue46_assert( is_array( $hold_cleanup_record ), 'Hold cleanup failure discarded recovery ownership.' );
	wpai_issue46_assert( 1 === count( glob( $plugin_dir . '/.ht-wpai-source-cas-*.hold' ) ), 'Hold cleanup failure did not preserve the exact private hold.' );
	$hold_cleanup_recovered = $cleanup_repair->recover( array( 'candidate_sha256' => $hold_cleanup_record['candidate_sha256'] ) );
	wpai_issue46_assert( ! is_wp_error( $hold_cleanup_recovered ) && true === $hold_cleanup_recovered['recovered'], 'Hold cleanup failure could not be reconciled through recovery.' );
	wpai_issue46_assert( $plugin_original === file_get_contents( $plugin_file ), 'Hold cleanup reconciliation did not restore the exact preimage.' );
	wpai_issue46_assert( false === get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ), 'Hold cleanup reconciliation discarded terminal ownership state incorrectly.' );
	wpai_issue46_assert( 0 === count( glob( $plugin_dir . '/.ht-wpai-source-cas-*.*' ) ), 'Hold cleanup reconciliation left private artifacts behind.' );

	// Atomic publication defines a new installed generation. A descriptor opened before replacement
	// remains attached to the superseded inode and must not be able to mutate the live pathname later.
	$late_stale_inode = fopen( $plugin_file, 'r+b' );
	wpai_issue46_assert( is_resource( $late_stale_inode ), 'Could not open late stale-descriptor fixture.' );
	$candidate_b = str_replace( "'original'", "'candidate-b-private-marker'", $plugin_original );
	$preview_b   = $preview->execute( array_merge( $target, array( 'candidate' => $candidate_b ) ) );
	$applied_b   = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $candidate_b,
				'preimage_sha256'  => $preview_b['preimage_sha256'],
				'candidate_sha256' => $preview_b['candidate_sha256'],
				'candidate_id'     => $preview_b['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( ! is_wp_error( $applied_b ) && 'success' === $applied_b['outcome'], 'Inactive plugin source apply failed.' );
	wpai_issue46_assert( hash( 'sha256', $candidate_b ) === hash_file( 'sha256', $plugin_file ), 'Successful source apply did not persist exact candidate bytes.' );
	wpai_issue46_assert( 0666 === ( fileperms( $plugin_file ) & 0777 ), 'Successful source apply changed the existing file mode.' );
	$late_stale_bytes = str_replace( "'original'", "'late-stale-descriptor'", $plugin_original );
	rewind( $late_stale_inode );
	ftruncate( $late_stale_inode, 0 );
	fwrite( $late_stale_inode, $late_stale_bytes );
	fflush( $late_stale_inode );
	rewind( $late_stale_inode );
	wpai_issue46_assert( $late_stale_bytes === stream_get_contents( $late_stale_inode ), 'Late stale-descriptor fixture did not mutate its superseded inode.' );
	wpai_issue46_assert( $candidate_b === file_get_contents( $plugin_file ), 'A late write through a superseded descriptor changed the installed source pathname.' );
	fclose( $late_stale_inode );
	$late_stale_inode = null;
	$log_json = wp_json_encode( get_option( Mutation_Log::OPTION_NAME, array() ) );
	wpai_issue46_assert( false === strpos( $log_json, 'candidate-b-private-marker' ) && false === strpos( $log_json, $candidate_b ), 'Mutation log retained source payload or diff material.' );

	file_put_contents( $plugin_file, $plugin_original );
	activate_plugin( $plugin );
	wpai_issue46_assert( is_plugin_active( $plugin ), 'Fixture plugin could not be activated.' );
	update_option( 'siteurl', 'http://wordpress', false );
	update_option( 'home', 'http://wordpress', false );

	$active_candidate = str_replace( "'original'", "'active-valid'", $plugin_original );
	$active_preview   = $preview->execute( array_merge( $target, array( 'candidate' => $active_candidate ) ) );
	wpai_issue46_assert( ! is_wp_error( $active_preview ) && true === $active_preview['runtime_validation_required'], 'Active PHP preview did not require runtime validation.' );
	$active_apply = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $active_candidate,
				'preimage_sha256'  => $active_preview['preimage_sha256'],
				'candidate_sha256' => $active_preview['candidate_sha256'],
				'candidate_id'     => $active_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( ! is_wp_error( $active_apply ) && 'success' === $active_apply['outcome'] && true === $active_apply['control_plane_risk'], 'Valid active plugin source edit did not pass runtime validation.' );

	$fatal_candidate = "<?php\n/* Plugin Name: WPNB Source Fixture */\nthrow new RuntimeException('wpnb issue46 runtime fatal');\n";
	$fatal_preview   = $preview->execute( array_merge( $target, array( 'candidate' => $fatal_candidate ) ) );
	wpai_issue46_assert( ! is_wp_error( $fatal_preview ), 'Parse-valid runtime-fatal candidate failed preview unexpectedly.' );
	// Integration mutates through the separate WP-CLI container while runtime validation boots through Apache.
	// Wait past the image's OPcache revalidation interval so Apache cannot reuse the immediately prior valid fixture.
	$opcache_revalidate_freq = max( 0, (int) ini_get( 'opcache.revalidate_freq' ) );
	if ( $opcache_revalidate_freq > 0 ) {
		sleep( $opcache_revalidate_freq + 1 );
	}
	$before_fatal = file_get_contents( $plugin_file );
	$fatal_apply  = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $fatal_candidate,
				'preimage_sha256'  => $fatal_preview['preimage_sha256'],
				'candidate_sha256' => $fatal_preview['candidate_sha256'],
				'candidate_id'     => $fatal_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( is_wp_error( $fatal_apply ) && 'source_runtime_validation_failed' === $fatal_apply->get_error_code(), 'Runtime-fatal active plugin edit was not rejected.' );
	wpai_issue46_assert( $before_fatal === file_get_contents( $plugin_file ), 'Runtime validation failure did not restore exact active-plugin preimage.' );
	wpai_issue46_assert( false === get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ), 'Verified runtime restoration left recovery material behind.' );

	deactivate_plugins( $plugin, true );
	file_put_contents( $plugin_file, $plugin_original );
	update_option( 'siteurl', $original_siteurl, false );
	update_option( 'home', $original_home, false );

	$theme_target    = array(
		'kind'      => 'theme',
		'extension' => $theme_slug,
		'file'      => 'functions.php',
	);
	$theme_candidate = str_replace( "'theme-original'", "'theme-edited'", $theme_original );
	$theme_preview   = $preview->execute( array_merge( $theme_target, array( 'candidate' => $theme_candidate ) ) );
	wpai_issue46_assert( ! is_wp_error( $theme_preview ), 'Theme source preview failed.' );
	$theme_apply = $apply->execute(
		array_merge(
			$theme_target,
			array(
				'candidate'        => $theme_candidate,
				'preimage_sha256'  => $theme_preview['preimage_sha256'],
				'candidate_sha256' => $theme_preview['candidate_sha256'],
				'candidate_id'     => $theme_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( ! is_wp_error( $theme_apply ) && 'success' === $theme_apply['outcome'], 'Inactive theme source apply failed.' );
	wpai_issue46_assert( $theme_candidate === file_get_contents( $theme_functions ), 'Theme source apply did not persist exact candidate bytes.' );

	// WordPress supports full-path registered theme roots outside the default wp-content/themes root.
	$registered_theme = wp_get_theme( $registered_theme_slug );
	wpai_issue46_assert( $registered_theme->exists(), 'Registered non-default theme root was not discovered by WordPress.' );
	wpai_issue46_assert( wp_normalize_path( realpath( $registered_theme_root ) ) === wp_normalize_path( realpath( get_theme_root( $registered_theme_slug ) ) ), 'WordPress did not resolve the exact registered theme root.' );
	$registered_target = array(
		'kind'      => 'theme',
		'extension' => $registered_theme_slug,
		'file'      => 'functions.php',
	);
	$registered_candidate = str_replace( "'theme-original'", "'registered-theme-edited'", $theme_original );
	$registered_preview   = $preview->execute( array_merge( $registered_target, array( 'candidate' => $registered_candidate ) ) );
	wpai_issue46_assert( ! is_wp_error( $registered_preview ), 'Registered non-default theme preview failed.' );
	$registered_apply = $apply->execute(
		array_merge(
			$registered_target,
			array(
				'candidate'        => $registered_candidate,
				'preimage_sha256'  => $registered_preview['preimage_sha256'],
				'candidate_sha256' => $registered_preview['candidate_sha256'],
				'candidate_id'     => $registered_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( ! is_wp_error( $registered_apply ) && 'success' === $registered_apply['outcome'], 'Registered non-default theme apply failed.' );
	wpai_issue46_assert( $registered_candidate === file_get_contents( $registered_theme_functions ), 'Registered non-default theme apply did not persist exact bytes.' );

	$registered_record = array(
		'version'          => 1,
		'token'            => wp_generate_uuid4(),
		'kind'             => 'theme',
		'extension'        => $registered_theme_slug,
		'file'             => 'functions.php',
		'canonical_root'   => realpath( $registered_theme_dir ),
		'canonical_path'   => realpath( $registered_theme_functions ),
		'preimage_sha256'  => hash( 'sha256', $theme_original ),
		'candidate_sha256' => hash( 'sha256', $registered_candidate ),
		'preimage'         => $theme_original,
		'created_gmt'      => gmdate( 'c' ),
	);
	update_option( Source_Editing_Abilities::RECOVERY_OPTION, $registered_record, false );
	$registered_recovered = $recover->execute( array( 'candidate_sha256' => $registered_record['candidate_sha256'] ) );
	wpai_issue46_assert( ! is_wp_error( $registered_recovered ) && true === $registered_recovered['recovered'], 'Registered non-default theme explicit recovery failed.' );
	wpai_issue46_assert( $theme_original === file_get_contents( $registered_theme_functions ), 'Registered non-default theme recovery did not restore exact preimage.' );
	wpai_issue46_assert( false === get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ), 'Registered non-default theme recovery left recovery ownership behind.' );

	// Crash reconciliation must trust the exact registered root even when style.css itself is quarantined.
	// Cleaning the theme cache after the rename forces recovery to rely on current registered roots rather
	// than a previously discovered theme object.
	$registered_style_candidate = $registered_theme_style_original . "/* bridge-crash-candidate */\n";
	file_put_contents( $registered_theme_style, $registered_style_candidate );
	$registered_shutdown_record = array(
		'version'          => 1,
		'token'            => wp_generate_uuid4(),
		'kind'             => 'theme',
		'extension'        => $registered_theme_slug,
		'file'             => 'style.css',
		'canonical_root'   => realpath( $registered_theme_dir ),
		'canonical_path'   => realpath( $registered_theme_style ),
		'preimage_sha256'  => hash( 'sha256', $registered_theme_style_original ),
		'candidate_sha256' => hash( 'sha256', $registered_style_candidate ),
		'preimage'         => $registered_theme_style_original,
		'created_gmt'      => gmdate( 'c' ),
	);
	update_option( Source_Editing_Abilities::RECOVERY_OPTION, $registered_shutdown_record, false );
	$replacement_paths = new ReflectionMethod( Source_Editing_Abilities::class, 'replacement_paths' );
	$registered_paths  = $replacement_paths->invoke( new Source_Editing_Abilities( new Permissions( $settings ), new Mutation_Log() ), $registered_shutdown_record['canonical_path'], $registered_shutdown_record['token'], 'apply' );
	wpai_issue46_assert( is_array( $registered_paths ), 'Could not derive registered-theme crash reconciliation paths.' );
	wpai_issue46_assert( rename( $registered_theme_style, $registered_paths['hold'] ), 'Could not create registered-theme style quarantine fixture.' );
	wp_clean_themes_cache( true );
	$registered_shutdown = new Source_Editing_Abilities( new Permissions( $settings ), new Mutation_Log() );
	$registered_absent_recovery = $registered_shutdown->recover( array( 'candidate_sha256' => $registered_shutdown_record['candidate_sha256'] ) );
	wpai_issue46_assert( is_wp_error( $registered_absent_recovery ) && 'source_recovery_artifact_pending' === $registered_absent_recovery->get_error_code(), 'Registered non-default theme absent-path recovery did not reach fail-closed artifact reconciliation.' );
	wpai_issue46_assert( ! file_exists( $registered_theme_style ), 'Registered non-default theme absent-path recovery recreated style.css speculatively.' );
	wpai_issue46_assert( $registered_style_candidate === file_get_contents( $registered_paths['hold'] ), 'Registered non-default theme absent-path recovery changed the retained hold.' );
	wpai_issue46_assert( is_array( get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ) ), 'Registered non-default theme absent-path recovery discarded ownership.' );
	$registered_shutdown->shutdown_recover( $registered_shutdown_record['token'] );
	wpai_issue46_assert( ! file_exists( $registered_theme_style ), 'Registered non-default theme shutdown recovery recreated an ambiguous absent style.css pathname.' );
	wpai_issue46_assert( $registered_style_candidate === file_get_contents( $registered_paths['hold'] ), 'Registered non-default theme shutdown recovery changed the retained hold.' );
	wpai_issue46_assert( is_array( get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ) ), 'Registered non-default theme shutdown recovery discarded ambiguous ownership.' );
	wpai_issue46_assert( link( $registered_paths['hold'], $registered_theme_style ), 'Could not restore registered-theme style fixture after fail-closed reconciliation test.' );
	@unlink( $registered_paths['hold'] );
	file_put_contents( $registered_theme_style, $registered_theme_style_original );
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );
	wp_clean_themes_cache( true );

	// An unregistered out-of-default-root theme record must remain fail-closed.
	$unregistered_record = array(
		'version'          => 1,
		'token'            => wp_generate_uuid4(),
		'kind'             => 'theme',
		'extension'        => $unregistered_theme_slug,
		'file'             => 'functions.php',
		'canonical_root'   => realpath( $unregistered_theme_dir ),
		'canonical_path'   => realpath( $unregistered_theme_file ),
		'preimage_sha256'  => hash( 'sha256', $theme_original ),
		'candidate_sha256' => hash( 'sha256', $theme_original ),
		'preimage'         => $theme_original,
		'created_gmt'      => gmdate( 'c' ),
	);
	update_option( Source_Editing_Abilities::RECOVERY_OPTION, $unregistered_record, false );
	$unregistered_recovery = $recover->execute( array( 'candidate_sha256' => $unregistered_record['candidate_sha256'] ) );
	wpai_issue46_assert( is_wp_error( $unregistered_recovery ) && 'source_recovery_target_changed' === $unregistered_recovery->get_error_code(), 'Unregistered theme root was trusted during recovery.' );
	wpai_issue46_assert( $theme_original === file_get_contents( $unregistered_theme_file ), 'Denied unregistered theme recovery changed source bytes.' );
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );

	file_put_contents( $plugin_file, $candidate_b );
	$recovery_record = array(
		'version'          => 1,
		'token'            => wp_generate_uuid4(),
		'kind'             => 'plugin',
		'extension'        => $plugin,
		'file'             => 'wpai-source-fixture.php',
		'canonical_root'   => realpath( $plugin_dir ),
		'canonical_path'   => realpath( $plugin_file ),
		'preimage_sha256'  => hash( 'sha256', $plugin_original ),
		'candidate_sha256' => hash( 'sha256', $candidate_b ),
		'preimage'         => $plugin_original,
		'created_gmt'      => gmdate( 'c' ),
	);
	// Once publication has happened, later pathname deletion/rename belongs to the current generation.
	// If Bridge still owns a hold but cannot prove whether absence is pre- or post-publication, recovery
	// must preserve the ambiguity rather than recreating the pathname from the old preimage.
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );
	file_put_contents( $plugin_file, $plugin_original );
	@unlink( $post_publish_explicit_removed );
	$post_publish_candidate = str_replace( "'original'", "'post-publish-explicit-candidate'", $plugin_original );
	$post_publish_explicit = new Source_Editing_Abilities(
		new Permissions( $settings ),
		new Mutation_Log(),
		null,
		static function ( $artifact ) use ( $plugin_file, $post_publish_explicit_removed ) {
			if ( '.stage' === substr( $artifact, -6 ) && file_exists( $plugin_file ) && ! file_exists( $post_publish_explicit_removed ) ) {
				rename( $plugin_file, $post_publish_explicit_removed );
			}
			return true;
		}
	);
	$post_publish_preview = $post_publish_explicit->preview( array_merge( $target, array( 'candidate' => $post_publish_candidate ) ) );
	$post_publish_result  = $post_publish_explicit->apply(
		array_merge(
			$target,
			array(
				'candidate'        => $post_publish_candidate,
				'preimage_sha256'  => $post_publish_preview['preimage_sha256'],
				'candidate_sha256' => $post_publish_preview['candidate_sha256'],
				'candidate_id'     => $post_publish_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( is_wp_error( $post_publish_result ) && 'source_write_state_uncertain' === $post_publish_result->get_error_code(), 'Post-publication rename did not produce uncertain state.' );
	$post_publish_record = get_option( Source_Editing_Abilities::RECOVERY_OPTION, false );
	wpai_issue46_assert( is_array( $post_publish_record ), 'Post-publication rename discarded recovery ownership.' );
	wpai_issue46_assert( ! file_exists( $plugin_file ) && $post_publish_candidate === file_get_contents( $post_publish_explicit_removed ), 'Post-publication rename fixture did not preserve the published candidate under its new pathname.' );
	$post_publish_holds = glob( $plugin_dir . '/.ht-wpai-source-cas-*.hold' );
	wpai_issue46_assert( is_array( $post_publish_holds ) && 1 === count( $post_publish_holds ), 'Post-publication rename did not retain the preimage hold.' );
	$post_publish_recover = ( new Source_Editing_Abilities( new Permissions( $settings ), new Mutation_Log() ) )->recover( array( 'candidate_sha256' => $post_publish_record['candidate_sha256'] ) );
	wpai_issue46_assert( is_wp_error( $post_publish_recover ) && 'source_recovery_artifact_pending' === $post_publish_recover->get_error_code(), 'Explicit recovery recreated an ambiguously absent post-publication pathname.' );
	wpai_issue46_assert( ! file_exists( $plugin_file ) && $post_publish_candidate === file_get_contents( $post_publish_explicit_removed ), 'Explicit recovery changed the concurrent post-publication rename state.' );
	wpai_issue46_assert( is_array( get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ) ), 'Explicit recovery discarded ambiguous post-publication ownership.' );
	rename( $post_publish_explicit_removed, $plugin_file );
	foreach ( $post_publish_holds as $post_publish_hold ) { @unlink( $post_publish_hold ); }
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );

	file_put_contents( $plugin_file, $plugin_original );
	@unlink( $post_publish_shutdown_removed );
	$post_shutdown_candidate = str_replace( "'original'", "'post-publish-shutdown-candidate'", $plugin_original );
	$post_publish_shutdown = new Source_Editing_Abilities(
		new Permissions( $settings ),
		new Mutation_Log(),
		null,
		static function ( $artifact ) use ( $plugin_file, $post_publish_shutdown_removed ) {
			if ( '.stage' === substr( $artifact, -6 ) && file_exists( $plugin_file ) && ! file_exists( $post_publish_shutdown_removed ) ) {
				rename( $plugin_file, $post_publish_shutdown_removed );
			}
			return true;
		}
	);
	$post_shutdown_preview = $post_publish_shutdown->preview( array_merge( $target, array( 'candidate' => $post_shutdown_candidate ) ) );
	$post_shutdown_result  = $post_publish_shutdown->apply(
		array_merge(
			$target,
			array(
				'candidate'        => $post_shutdown_candidate,
				'preimage_sha256'  => $post_shutdown_preview['preimage_sha256'],
				'candidate_sha256' => $post_shutdown_preview['candidate_sha256'],
				'candidate_id'     => $post_shutdown_preview['candidate_id'],
			)
		)
	);
	wpai_issue46_assert( is_wp_error( $post_shutdown_result ) && 'source_write_state_uncertain' === $post_shutdown_result->get_error_code(), 'Post-publication shutdown rename did not produce uncertain state.' );
	$post_shutdown_record = get_option( Source_Editing_Abilities::RECOVERY_OPTION, false );
	$post_shutdown_holds  = glob( $plugin_dir . '/.ht-wpai-source-cas-*.hold' );
	wpai_issue46_assert( is_array( $post_shutdown_record ) && is_array( $post_shutdown_holds ) && 1 === count( $post_shutdown_holds ), 'Post-publication shutdown fixture did not retain recoverable ownership.' );
	$post_publish_shutdown->shutdown_recover( $post_shutdown_record['token'] );
	wpai_issue46_assert( ! file_exists( $plugin_file ) && $post_shutdown_candidate === file_get_contents( $post_publish_shutdown_removed ), 'Shutdown recovery recreated or changed an ambiguously absent post-publication pathname.' );
	wpai_issue46_assert( is_array( get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ) ), 'Shutdown recovery discarded ambiguous post-publication ownership.' );
	rename( $post_publish_shutdown_removed, $plugin_file );
	foreach ( $post_shutdown_holds as $post_shutdown_hold ) { @unlink( $post_shutdown_hold ); }
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );

	file_put_contents( $plugin_file, $candidate_b );
	update_option( Source_Editing_Abilities::RECOVERY_OPTION, $recovery_record, false );
	$newer = str_replace( "'original'", "'newer-legitimate-edit'", $plugin_original );
	file_put_contents( $plugin_file, $newer );
	$conflict = $recover->execute( array( 'candidate_sha256' => $recovery_record['candidate_sha256'] ) );
	wpai_issue46_assert( is_wp_error( $conflict ) && 'source_recovery_conflict' === $conflict->get_error_code(), 'Recovery overwrote or accepted newer legitimate bytes.' );
	wpai_issue46_assert( $newer === file_get_contents( $plugin_file ), 'Recovery conflict changed newer legitimate bytes.' );
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );

	// Explicit recovery must also lose to a non-cooperating writer at the exact publish boundary.
	file_put_contents( $plugin_file, $candidate_b );
	$recovery_race_record          = $recovery_record;
	$recovery_race_record['token'] = wp_generate_uuid4();
	update_option( Source_Editing_Abilities::RECOVERY_OPTION, $recovery_race_record, false );
	$recovery_race_bytes  = str_replace( "'original'", "'third-party-recovery-race'", $plugin_original );
	$recovery_race        = new Source_Editing_Abilities(
		new Permissions( $settings ),
		new Mutation_Log(),
		static function ( $phase ) use ( $plugin_file, $recovery_race_bytes ) {
			if ( 'recovery' === $phase ) {
				file_put_contents( $plugin_file, $recovery_race_bytes );
			}
		}
	);
	$recovery_race_result = $recovery_race->recover( array( 'candidate_sha256' => $recovery_race_record['candidate_sha256'] ) );
	wpai_issue46_assert( is_wp_error( $recovery_race_result ) && 'source_concurrent_write_detected' === $recovery_race_result->get_error_code(), 'Non-locking explicit-recovery race was not rejected.' );
	wpai_issue46_assert( $recovery_race_bytes === file_get_contents( $plugin_file ), 'Explicit recovery overwrote newer non-locking bytes.' );
	wpai_issue46_assert( is_array( get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ) ), 'Explicit recovery race discarded pending recovery ownership.' );
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );

	// Shutdown recovery is routed through the same guarded primitive and must preserve a racing writer.
	file_put_contents( $plugin_file, $candidate_b );
	$shutdown_race_record          = $recovery_record;
	$shutdown_race_record['token'] = wp_generate_uuid4();
	update_option( Source_Editing_Abilities::RECOVERY_OPTION, $shutdown_race_record, false );
	$shutdown_race_bytes = str_replace( "'original'", "'third-party-shutdown-race'", $plugin_original );
	$shutdown_race       = new Source_Editing_Abilities(
		new Permissions( $settings ),
		new Mutation_Log(),
		static function ( $phase ) use ( $plugin_file, $shutdown_race_bytes ) {
			if ( 'recovery' === $phase ) {
				file_put_contents( $plugin_file, $shutdown_race_bytes );
			}
		}
	);
	$shutdown_race->shutdown_recover( $shutdown_race_record['token'] );
	wpai_issue46_assert( $shutdown_race_bytes === file_get_contents( $plugin_file ), 'Shutdown recovery overwrote newer non-locking bytes.' );
	wpai_issue46_assert( is_array( get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ) ), 'Shutdown recovery race discarded pending recovery ownership.' );
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );

	file_put_contents( $plugin_file, $candidate_b );
	update_option( Source_Editing_Abilities::RECOVERY_OPTION, $recovery_record, false );
	$recovered = $recover->execute( array( 'candidate_sha256' => $recovery_record['candidate_sha256'] ) );
	wpai_issue46_assert( ! is_wp_error( $recovered ) && true === $recovered['recovered'], 'Exact candidate recovery failed.' );
	wpai_issue46_assert( $plugin_original === file_get_contents( $plugin_file ), 'Exact recovery did not restore the preimage.' );
	wpai_issue46_assert( false === get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ), 'Successful recovery left private recovery material behind.' );

	echo "PASS: Issue #46 source editing gates, confinement, concurrency, locking, persistence, runtime validation, recovery, MCP exposure, theme handling, and log privacy.\n";
} finally {
	if ( is_resource( $retained_inode ) ) {
		fclose( $retained_inode );
	}
	if ( is_resource( $late_stale_inode ) ) {
		fclose( $late_stale_inode );
	}
	if ( $external_lock instanceof SplFileObject ) {
		$external_lock->flock( LOCK_UN );
	}
	deactivate_plugins( $plugin, true );
	if ( $original_theme && get_stylesheet() !== $original_theme ) {
		switch_theme( $original_theme );
	}
	update_option( 'siteurl', $original_siteurl, false );
	update_option( 'home', $original_home, false );
	update_option( Settings::OPTION_NAME, $original_settings, false );
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );
	@unlink( $symlink_file );
	@unlink( $linked_plugin_dir );
	@unlink( $outside_plugin_file );
	@rmdir( $outside_plugin_dir );
	@unlink( $plugin_helper );
	@unlink( $post_publish_explicit_removed );
	@unlink( $post_publish_shutdown_removed );
	foreach ( (array) glob( $plugin_dir . '/.ht-wpai-source-cas-*.*' ) as $leftover_artifact ) { @unlink( $leftover_artifact ); }
	@unlink( $plugin_file );
	@rmdir( $plugin_dir );
	@unlink( $outside_file );
	@unlink( $theme_functions );
	@unlink( $theme_style );
	@rmdir( $theme_dir );
	@unlink( $registered_theme_functions );
	@unlink( $registered_theme_style );
	@rmdir( $registered_theme_dir );
	@rmdir( $registered_theme_root );
	@unlink( $unregistered_theme_file );
	@unlink( $unregistered_theme_style );
	@rmdir( $unregistered_theme_dir );
	@rmdir( $unregistered_theme_root );
	wp_clean_plugins_cache( true );
	wp_clean_themes_cache( true );
}
