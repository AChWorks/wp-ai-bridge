<?php
/**
 * Representative real private Workspace project/fidelity/receipt tests.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;
use WP_AI_Bridge\Support\Bounded_Payload;

function wpai126_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function wpai126_execute( $ability, $input ) {
	$contract = wp_get_ability( 'wp-ai-bridge/' . $ability );
	wpai126_assert( $contract instanceof WP_Ability, 'Missing Workspace ability ' . $ability );
	return $contract->execute( $input );
}
$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$access = $settings->defaults();
$access[ Settings::GROUP_SITE_READ ] = 1;
$access[ Settings::GROUP_BUILDER_WRITE ] = 1;
$created_ids = array();
$store = new Store();
$project_a = 'wpai-project-a';
$project_b = 'wpai-project-b';
try {
	update_option( Settings::OPTION_NAME, $access, false );
	$a = wpai126_execute( 'workspace-document', array(
		'action' => 'create', 'title' => 'Project Brief A', 'project_ref' => $project_a,
		'key' => 'project-brief', 'content' => "# Project Brief A\n\nشروع پروژهٔ اول.",
		'operation_id' => 'project-a-doc-101',
	) );
	wpai126_assert( ! is_wp_error( $a ), 'First project brief not created: ' . ( is_wp_error( $a ) ? $a->get_error_code() : '' ) );
	$created_ids[] = $a['items'][0]['id'];
	$retry = wpai126_execute( 'workspace-document', array(
		'action' => 'create', 'title' => 'Project Brief A', 'project_ref' => $project_a,
		'key' => 'project-brief', 'content' => "# Project Brief A\n\nشروع پروژهٔ اول.",
		'operation_id' => 'project-a-doc-101',
	) );
	wpai126_assert( ! is_wp_error( $retry ) && $retry['items'][0]['id'] === $created_ids[0], 'Lost response duplicated a project brief.' );
	$duplicate = wpai126_execute( 'workspace-document', array(
		'action' => 'create', 'title' => 'Duplicate', 'project_ref' => $project_a,
		'key' => 'project-brief', 'content' => 'Another brief.',
	) );
	wpai126_assert( is_wp_error( $duplicate ), 'Canonical project key allowed a second document.' );

	$b = wpai126_execute( 'workspace-document', array(
		'action' => 'create', 'title' => 'Project Brief B', 'project_ref' => $project_b,
		'key' => 'project-brief', 'content' => 'Second, distinct project.',
	) );
	wpai126_assert( ! is_wp_error( $b ), 'Separate project could not own its own brief.' );
	$created_ids[] = $b['items'][0]['id'];

	$task_a = wpai126_execute( 'workspace-task', array(
		'action' => 'create', 'project_ref' => $project_a, 'title' => 'Complete A',
		'next_action' => 'Inspect design in target A', 'blocker' => 'Waiting for review',
		'progress' => 'in_progress', 'review' => 'pending',
		'notes' => 'No prior conversation required.',
		'operation_id' => 'task-a-operation-101',
	) );
	wpai126_assert( ! is_wp_error( $task_a ), 'Project A task failed.' );
	$created_ids[] = $task_a['items'][0]['id'];
	$task_b = wpai126_execute( 'workspace-task', array(
		'action' => 'create', 'project_ref' => $project_b, 'title' => 'Complete B',
		'progress' => 'in_progress', 'next_action' => 'Review target B',
	) );
	wpai126_assert( ! is_wp_error( $task_b ), 'Project B task failed.' );
	$created_ids[] = $task_b['items'][0]['id'];

	$resume_a = wpai126_execute( 'workspace-resume', array( 'project_ref' => $project_a ) );
	$resume_b = wpai126_execute( 'workspace-resume', array( 'project_ref' => $project_b ) );
	wpai126_assert( ! is_wp_error( $resume_a ) && ! is_wp_error( $resume_b ), 'Project-scoped resume failed.' );
	wpai126_assert( 1 === $resume_a['counts']['documents'] && 1 === $resume_a['counts']['tasks'] && 'Complete A' === $resume_a['current_focus'], 'Project A orientation incorrect.' );
	wpai126_assert( 'Inspect design in target A' === $resume_a['active_tasks'][0]['next_action'], 'Project A next action not recoverable.' );
	wpai126_assert( 'Complete B' === $resume_b['current_focus'] && 'Review target B' === $resume_b['active_tasks'][0]['next_action'], 'Project B focus leaked or lost.' );
	wpai126_assert( false === strpos( wp_json_encode( $resume_a ), 'Complete B' ), 'Cross-project current task leaked.' );
	$site_resume = wpai126_execute( 'workspace-resume', array() );
	wpai126_assert( ! is_wp_error( $site_resume ) && 'mixed' === $site_resume['scope'] && '' === $site_resume['current_focus'], 'Unfiltered mixed workspace fabricated one global focus.' );

	$persian = str_repeat( "سلام دنیا\n", 5000 );
	$large = wpai126_execute( 'workspace-document', array(
		'action' => 'create', 'key' => 'long-persian', 'title' => 'Long document',
		'project_ref' => $project_a, 'content' => $persian,
	) );
	wpai126_assert( ! is_wp_error( $large ) && false === ( $large['items'][0]['projection_complete'] ?? true ), 'Long post-commit result was not compacted.' );
	$created_ids[] = $large['items'][0]['id'];
	wpai126_assert( Bounded_Payload::fits( $large ), 'Long create response exceeded real MCP budget.' );
	$large_get = wpai126_execute( 'workspace-document', array( 'action' => 'get', 'id' => $large['items'][0]['id'] ) );
	wpai126_assert( is_wp_error( $large_get ) && 'workspace_pagination_required' === $large_get->get_error_code(), 'Oversized full get silently truncated.' );
	$summary = wpai126_execute( 'workspace-document', array( 'action' => 'get', 'id' => $large['items'][0]['id'], 'view' => 'summary' ) );
	wpai126_assert( ! is_wp_error( $summary ) && 64 === strlen( $summary['items'][0]['state_hash'] ), 'Stable guarded summary missing.' );
	$version = $summary['items'][0]['version'];
	$hash = $summary['items'][0]['state_hash'];
	$offset = 0;
	$collected = '';
	for ( $part = 0; $part < 100; ++$part ) {
		$window = wpai126_execute( 'workspace-document', array(
			'action' => 'get', 'id' => $large['items'][0]['id'], 'view' => 'window',
			'field' => 'content', 'offset' => $offset, 'max_bytes' => 8192,
			'expected_version' => $version, 'expected_state_hash' => $hash,
		) );
		wpai126_assert( ! is_wp_error( $window ), 'Guarded UTF-8 window failed.' );
		$w = $window['items'][0]['field_window'];
		wpai126_assert( Bounded_Payload::fits( $window ), 'Window exceeded transport budget.' );
		$collected .= $w['text'];
		$offset = $w['next_offset'];
		if ( $w['complete'] ) { break; }
	}
	wpai126_assert( $collected === $persian, 'Multibyte Markdown windows did not reconstruct exact committed bytes.' );

	$invalid = $store->create_document( array( 'title' => 'Invalid', 'content' => "\xC3\x28", 'project_ref' => $project_a ) );
	wpai126_assert( is_wp_error( $invalid ) && 'workspace_lossy_input' === $invalid->get_error_code(), 'Invalid UTF-8 was silently persisted.' );
	$oversize = $store->create_document( array( 'title' => 'Oversize', 'content' => str_repeat( 'x', 100001 ) ) );
	wpai126_assert( is_wp_error( $oversize ), 'Oversized document was silently truncated.' );
	$unsafe = $store->create_document( array( 'title' => 'Sanitize', 'content' => '<script>side effect</script>' ) );
	wpai126_assert( is_wp_error( $unsafe ), 'Material HTML filtering happened silently.' );
	$invalid_list = $store->create_task( array( 'title' => 'Too many', 'acceptance' => array_fill( 0, 26, 'criterion' ) ) );
	wpai126_assert( is_wp_error( $invalid_list ), 'Oversized task list silently dropped elements.' );

	$archived = wpai126_execute( 'workspace-document', array(
		'action' => 'archive', 'id' => $created_ids[0],
		'expected_version' => $a['items'][0]['version'],
		'expected_state_hash' => $a['items'][0]['state_hash'],
	) );
	wpai126_assert( ! is_wp_error( $archived ) && true === $archived['items'][0]['archived'], 'Archive failed.' );
	$still_reserved = wpai126_execute( 'workspace-document', array(
		'action' => 'create', 'key' => 'project-brief', 'project_ref' => $project_a,
		'title' => 'Archived duplication',
	) );
	wpai126_assert( is_wp_error( $still_reserved ), 'Archived canonical key was incorrectly released.' );
	$restored = wpai126_execute( 'workspace-document', array(
		'action' => 'unarchive', 'id' => $created_ids[0],
		'expected_version' => $archived['items'][0]['version'],
		'expected_state_hash' => $archived['items'][0]['state_hash'],
	) );
	wpai126_assert( ! is_wp_error( $restored ) && false === $restored['items'][0]['archived'], 'Version-guarded unarchive failed.' );
	$stale = wpai126_execute( 'workspace-document', array(
		'action' => 'update', 'id' => $created_ids[0],
		'expected_version' => $a['items'][0]['version'],
		'expected_state_hash' => $a['items'][0]['state_hash'], 'title' => 'Stale',
	) );
	wpai126_assert( is_wp_error( $stale ), 'Stale version/hash was accepted.' );

	for ( $i = 0; $i < 28; ++$i ) {
		$doc = wpai126_execute( 'workspace-document', array(
			'action' => 'create', 'title' => 'Page ' . $i, 'project_ref' => $project_a,
			'key' => 'page-' . $i, 'content' => 'Payload ' . $i,
		) );
		wpai126_assert( ! is_wp_error( $doc ), 'Paged fixture document failed.' );
		$created_ids[] = $doc['items'][0]['id'];
	}
	$first = wpai126_execute( 'workspace-document', array(
		'action' => 'list', 'project_ref' => $project_a, 'view' => 'summary', 'limit' => 25,
	) );
	wpai126_assert( ! is_wp_error( $first ) && 25 === count( $first['items'] ) && $first['has_more'], 'First project page incompleteness not surfaced.' );
	$second = wpai126_execute( 'workspace-document', array(
		'action' => 'list', 'project_ref' => $project_a, 'view' => 'summary',
		'limit' => 25, 'before_id' => $first['next_cursor'],
	) );
	wpai126_assert( ! is_wp_error( $second ) && ! $second['has_more'] && count( $second['items'] ) >= 5, 'Second project page omitted records.' );
	$all_ids = array_column( array_merge( $first['items'], $second['items'] ), 'id' );
	wpai126_assert( count( $all_ids ) === count( array_unique( $all_ids ) ), 'ID cursor duplicated records.' );
	$legacy = wpai126_execute( 'workspace-document', array( 'action' => 'list' ) );
	wpai126_assert( is_wp_error( $legacy ) && 'workspace_pagination_required' === $legacy->get_error_code(), 'Oversized legacy full list silently truncated.' );
	$export = $store->export_snapshot();
	wpai126_assert( ! is_wp_error( $export ) && true === $export['complete'] && $export['counts']['documents'] >= 31, 'Workspace export missing completeness/counts.' );
	echo "PASS: #126/#128 WordPress project-scoped lossless continuity, canonical identity and pagination smoke.\n";
} finally {
	foreach ( array_reverse( $created_ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	update_option( Settings::OPTION_NAME, $original, false );
}
