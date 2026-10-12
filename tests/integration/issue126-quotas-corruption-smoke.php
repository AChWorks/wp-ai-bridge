<?php
/**
 * Real WordPress 0/25/200/201+ scalable-count completeness and invalid-state smoke.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Workspace\Store;
use WP_AI_Bridge\Support\Workspace_Limits;
use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Settings;

function wpai126_quota_assert( $ok, $reason ) {
	if ( ! $ok ) {
		throw new RuntimeException( $reason );
	}
}
// #153 R2: started by the integration runner with php -d memory_limit=96M.
// A missing/ineffective clamp is a FAIL, never an optional skipped check.
$required_memory = getenv( 'WPAI153_MEMORY_CEILING' );
$actual_limit    = (string) ini_get( 'memory_limit' );
$limit_bytes     = wp_convert_hr_to_bytes( $actual_limit );
wpai126_quota_assert(
	'96M' === $required_memory && $limit_bytes > 0 && $limit_bytes <= 96 * 1048576,
	'Workspace real integration MUST execute at or below 96MiB, with no fallback.'
);
echo 'WPAI153_MEMORY_BEGIN effective=' . $actual_limit .
	' bytes=' . $limit_bytes . ' bootstrap_peak=' . memory_get_peak_usage( true ) . "\n";

$store                 = new Store();
$created               = array();
$extra                 = 0;
$task_id               = 0;
$scaled_task_ids       = array();
$prior_capacity_option = get_option( Workspace_Limits::OPTION_NAME, null );
try {
	// A failed native coordinator must reject BEFORE the first WP insert.
	$failure_filter = static function ( $query ) {
		return 0 === strpos( $query, 'SELECT GET_LOCK(' ) && false !== strpos( $query, 'wpai_admit_' )
			? 'SELECT invalid_admission_lock_function(' : $query;
	};
	global $wpdb;
	$wpdb->suppress_errors( true );
	add_filter( 'query', $failure_filter );
	try {
		$no_document = $store->create_document( array( 'title' => 'Must not write on failed document lock' ) );
		$no_task     = $store->create_task( array( 'title' => 'Must not write on failed task lock' ) );
	} finally {
		remove_filter( 'query', $failure_filter );
		$wpdb->suppress_errors( false );
	}
	wpai126_quota_assert(
		is_wp_error( $no_document ) && 'workspace_admission_unavailable' === $no_document->get_error_code() &&
		is_wp_error( $no_task ) && 'workspace_admission_unavailable' === $no_task->get_error_code(),
		'WordPress created an object after an unavailable quota coordinator.'
	);

	$empty = $store->page_documents( array( 'limit' => 25 ) );
	wpai126_quota_assert(
		! is_wp_error( $empty ) && 0 === $empty['total'] &&
		empty( $empty['items'] ) && ! $empty['has_more'],
		'Empty Workspace state is not truthful.'
	);
	for ( $i = 0; $i < Store::MAX_RECORDS; ++$i ) {
		$input = array(
			'title'   => 'WPAI126 quota ' . $i,
			'content' => 'Bounded canonical fixture ' . $i,
		);
		if ( $i >= 1 && $i <= 30 ) {
			$input['project_ref'] = 'wpai-project-' . sprintf( '%03d', $i );
		}
		$record = $store->create_document( $input );
		wpai126_quota_assert( ! is_wp_error( $record ) && (int) $record['id'] > 0, 'Failed to create a documented-capacity record.' );
		$created[] = (int) $record['id'];
	}

	$seen   = array();
	$cursor = 0;
	for ( $page_no = 0; $page_no < 8; ++$page_no ) {
		$page = $store->page_documents(
			array(
				'limit'     => 25,
				'before_id' => $cursor,
			)
		);
		wpai126_quota_assert(
			! is_wp_error( $page ) && 200 === $page['total'] &&
			25 === count( $page['items'] ) && Bounded_Payload::fits( $page ),
			'Full-capacity small-page enumeration lost or exceeded response budget.'
		);
		foreach ( $page['items'] as $item ) {
			wpai126_quota_assert( ! isset( $seen[ $item['id'] ] ), 'ID continuation duplicated a Workspace document.' );
			$seen[ $item['id'] ] = true;
		}
		wpai126_quota_assert( $page_no < 7 ? $page['has_more'] : ! $page['has_more'], 'Page completeness/cursor incorrectly advertised.' );
		$cursor = $page['next_cursor'];
	}
	wpai126_quota_assert( 200 === count( $seen ), 'A full-capacity enumeration silently omitted records.' );
	$resume = $store->resume();
	wpai126_quota_assert(
		! is_wp_error( $resume ) && 200 === $resume['counts']['documents_total'] &&
		200 === $resume['sections']['documents']['total'] && $resume['sections']['documents']['has_more'],
		'Resume concealed index truncation at the quota.'
	);
	wpai126_quota_assert(
		! empty( $resume['projects_truncated'] ) && 25 === count( $resume['project_refs'] ) &&
		'wpai-project-001' === $resume['project_refs'][0] &&
		'wpai-project-025' === $resume['project_refs'][24] &&
		'mixed' === $resume['scope'],
		'Resume did not preserve the exact first 25 project references or truthful truncation.'
	);

	$at_limit = $store->create_document( array( 'title' => 'Over quota' ) );
	wpai126_quota_assert(
		is_wp_error( $at_limit ) && 'workspace_document_limit' === $at_limit->get_error_code(),
		'Normal create exceeded the 200-record documented site cap.'
	);

	// Administrators can safely increase the per-site document count policy.
	// Existing 200 records and their hash/version identities remain unchanged.
	$policy = new Workspace_Limits();
	$larger = $policy->sanitize( array( Workspace_Limits::DOCUMENTS_KEY => '250' ) );
	update_option( Workspace_Limits::OPTION_NAME, $larger, false );
	wpai126_quota_assert( 250 === $policy->all()[ Workspace_Limits::DOCUMENTS_KEY ], 'Site-local capacity increase was not saved.' );
	for ( $i = 200; $i < 250; ++$i ) {
		$record = $store->create_document(
			array(
				'title'       => 'WPAI153 large-count ' . $i,
				'content'     => str_repeat( 'x', $i >= 240 ? 450 * 1024 : 65536 ) . ' ' . $i,
				'project_ref' => 'wpai-scale-153',
			)
		);
		wpai126_quota_assert( ! is_wp_error( $record ) && ! empty( $record['id'] ), 'A valid post-200 WordPress document was rejected.' );
		$created[] = (int) $record['id'];
	}
	// This ENTIRE fixture has used the same externally enforced memory
	// bound since PHP startup; no opportunistic mid-test ini_set().
	$large_page   = $store->page_documents( array( 'limit' => 25 ) );
	$large_resume = $store->resume();
	$large_export = $store->export_snapshot();
	wpai126_quota_assert(
		! is_wp_error( $large_page ) && 250 === $large_page['total'] &&
		! is_wp_error( $large_resume ) && 250 === $large_resume['counts']['documents_total'] &&
		! is_wp_error( $large_export ) && 250 === $large_export['counts']['documents'] &&
		true === $large_export['complete'],
		'The site cannot page, resume, and completely export a valid 250-document Workspace.'
	);
	// The real RTL WordPress administrator view must also page rather than
	// render 250 complete documents and their private Markdown in one table.
	$admin_page  = new Settings_Page( new Environment(), new Settings(), null, $store );
	$saved_query = $_GET;
	try {
		$_GET = array();
		ob_start();
		$admin_page->render_documents();
		$admin_html = ob_get_clean();
		wpai126_quota_assert(
			25 === substr_count( $admin_html, '<td dir="auto"' ) &&
			false !== strpos( $admin_html, 'workspace_cursor=' ) &&
			false !== strpos( $admin_html, '250' ),
			'Administrator Documents page is not bounded to 25 records or hides continuation.'
		);
		$_GET = array( 'workspace_cursor' => (int) $large_page['next_cursor'] );
		ob_start();
		$admin_page->render_documents();
		$older_html = ob_get_clean();
		wpai126_quota_assert( 25 === substr_count( $older_html, '<td dir="auto"' ), 'Admin Documents cursor was not preserved.' );
	} finally {
		$_GET = $saved_query;
	}
	ob_start();
	$admin_page->render_settings();
	$settings_html = ob_get_clean();
	wpai126_quota_assert(
		false !== strpos( $settings_html, Workspace_Limits::OPTION_NAME . '[' . Workspace_Limits::DOCUMENTS_KEY . ']' ) &&
		false !== strpos( $settings_html, Workspace_Limits::OPTION_NAME . '[' . Workspace_Limits::TASKS_KEY . ']' ),
		'Independent per-site Workspace count Settings API controls were not rendered.'
	);

	$project_page = $store->page_documents(
		array(
			'limit'       => 25,
			'project_ref' => 'wpai-scale-153',
		)
	);
	wpai126_quota_assert(
		! is_wp_error( $project_page ) && 50 === $project_page['total'] && $project_page['has_more'],
		'Post-200 project-filtered pagination is not complete and accurate.'
	);
	// A separate task policy must likewise work beyond the historical 200 cap.
	$task_settings = $policy->sanitize( array( Workspace_Limits::TASKS_KEY => '210' ) );
	update_option( Workspace_Limits::OPTION_NAME, $task_settings, false );
	for ( $i = 0; $i < 201; ++$i ) {
		$created_task = $store->create_task(
			array(
				'title'       => 'WPAI153 scalable task ' . $i,
				'project_ref' => 'wpai-scale-153',
				'progress'    => 'done',
			)
		);
		wpai126_quota_assert( ! is_wp_error( $created_task ), 'Post-200 Workspace task capacity unexpectedly rejected a valid record.' );
		$scaled_task_ids[] = (int) $created_task['id'];
	}
	$task_page = $store->page_tasks(
		array(
			'limit'       => 25,
			'project_ref' => 'wpai-scale-153',
		)
	);
	wpai126_quota_assert(
		! is_wp_error( $task_page ) && 201 === $task_page['total'] && $task_page['has_more'],
		'Post-200 Workspace task summaries omitted or duplicated records.'
	);
	wpai126_quota_assert(
		wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) <= 96 * 1048576,
		'The Workspace 250/201 task workload escaped the 96MiB limit.'
	);
	echo 'WPAI153_MEMORY_WORKLOAD effective=' . ini_get( 'memory_limit' ) .
		' documents=250 tasks=201 projects=30 large_docs=10 large_doc_bytes=460800' .
		' peak_bytes=' . memory_get_peak_usage( true ) . "\n";

	// These task fixtures are isolated from subsequent semantic corruption tests.
	foreach ( $scaled_task_ids as $scaled_task_id ) {
		wp_delete_post( $scaled_task_id, true );
	}
	$scaled_task_ids = array();
	update_option( Workspace_Limits::OPTION_NAME, $larger, false );

	$at_extended_limit = $store->create_document( array( 'title' => 'Over extended count policy' ) );
	wpai126_quota_assert(
		is_wp_error( $at_extended_limit ) && 'workspace_document_limit' === $at_extended_limit->get_error_code(),
		'Concurrent admission policy can be exceeded at an administrator-set record count.'
	);

	// Lowering the admission policy must not hide or delete historical data.
	update_option( Workspace_Limits::OPTION_NAME, $policy->defaults(), false );
	$lowered_page   = $store->page_documents( array( 'limit' => 1 ) );
	$lowered_create = $store->create_document( array( 'title' => 'Should be blocked while above lower policy' ) );
	wpai126_quota_assert(
		! is_wp_error( $lowered_page ) && 250 === $lowered_page['total'] &&
		is_wp_error( $lowered_create ) && 'workspace_document_limit' === $lowered_create->get_error_code(),
		'Lowering the admission limit erased records or allowed over-quota inserts.'
	);

	// Simulate unexpected records from other WordPress code. Do not claim
	// completeness if extra entries or malformed state exist.
	$extra = wp_insert_post(
		array(
			'post_type'   => Store::DOCUMENT_POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'WPAI126 unexpected 201st record',
		),
		true
	);
	wpai126_quota_assert( ! is_wp_error( $extra ) && $extra > 0, 'Overflow fixture did not create a WordPress post.' );
	$overflow = $store->page_documents( array( 'limit' => 1 ) );
	$export   = $store->export_snapshot();
	wpai126_quota_assert(
		is_wp_error( $overflow ) && 'workspace_incomplete' === $overflow->get_error_code() &&
		is_wp_error( $export ) && 'workspace_incomplete' === $export->get_error_code(),
		'A malformed unexpected post was silently skipped instead of making reads fail closed.'
	);

	wp_delete_post( $extra, true );
	$extra             = 0;
	$original_document = (string) get_post_meta( $created[0], Store::META_STATE, true );
	update_post_meta( $created[0], Store::META_STATE, '{broken JSON' );
	$damaged        = $store->page_documents( array( 'limit' => 1 ) );
	$damaged_export = $store->export_snapshot();
	wpai126_quota_assert(
		is_wp_error( $damaged ) && 'workspace_incomplete' === $damaged->get_error_code() &&
		is_wp_error( $damaged_export ) && 'workspace_incomplete' === $damaged_export->get_error_code(),
		'Corrupt Workspace backing state was silently omitted or exported as complete.'
	);

	// The bounded native reader must never materialize an anomalously huge
	// private meta payload into the PHP process or claim a complete result.
	update_post_meta( $created[0], Store::META_STATE, str_repeat( 'x', 8388609 ) );
	$huge_page = $store->page_documents( array( 'limit' => 1 ) );
	wpai126_quota_assert(
		is_wp_error( $huge_page ) && 'workspace_incomplete' === $huge_page->get_error_code(),
		'Unexpected oversized Workspace metadata bypassed the bounded reader.'
	);
	update_post_meta( $created[0], Store::META_STATE, wp_slash( $original_document ) );

	// Syntactically valid JSON with a damaged project/key must NOT become
	// an unbound legacy record, nor permit complete export or mixed resume.
	$document = json_decode( $original_document, true );
	foreach ( array(
		array( 'project_ref', array( 'unexpected' ) ),
		array( 'project_ref', (object) array( 'unexpected' => true ) ),
		array( 'project_ref', '-bad-project' ),
		array( 'key', 'NOT A VALID KEY!' ),
	) as $mutation ) {
		$mutated                 = $document;
		$mutated[ $mutation[0] ] = $mutation[1];
		update_post_meta( $created[0], Store::META_STATE, wp_slash( wp_json_encode( $mutated ) ) );
		$bad_get    = $store->get_document( $created[0] );
		$bad_page   = $store->page_documents( array( 'limit' => 1 ) );
		$bad_resume = $store->resume();
		$bad_export = $store->export_snapshot();
		wpai126_quota_assert(
			is_wp_error( $bad_get ) && 'workspace_state_invalid' === $bad_get->get_error_code() &&
			is_wp_error( $bad_page ) && 'workspace_incomplete' === $bad_page->get_error_code() &&
			is_wp_error( $bad_resume ) && 'workspace_incomplete' === $bad_resume->get_error_code() &&
			is_wp_error( $bad_export ) && 'workspace_incomplete' === $bad_export->get_error_code(),
			'Semantic document corruption was normalized into an apparently complete project.'
		);
	}
	unset( $document['project_ref'] );
	update_post_meta( $created[0], Store::META_STATE, wp_slash( wp_json_encode( $document ) ) );
	$legacy = $store->get_document( $created[0] );
	wpai126_quota_assert(
		! is_wp_error( $legacy ) && '' === $legacy['project_ref'],
		'Genuine historical document without project_ref became unreadable.'
	);
	update_post_meta( $created[0], Store::META_STATE, wp_slash( $original_document ) );

	$task = $store->create_task( array( 'title' => 'WPAI126 semantic task' ) );
	wpai126_quota_assert( ! is_wp_error( $task ), 'Could not prepare semantic task fixture.' );
	$task_id       = (int) $task['id'];
	$original_task = (string) get_post_meta( $task_id, Store::META_STATE, true );
	$task_state    = json_decode( $original_task, true );
	foreach ( array(
		array( 'project_ref', array( 'unexpected' ) ),
		array( 'progress', 'inexistent-progress' ),
		array( 'review', 'inexistent-review' ),
		array( 'delivery', 'inexistent-delivery' ),
		array( 'next_action', array( 'wrong-type' ) ),
		array( 'blocker', 7 ),
		array( 'acceptance', array( 'bad-key' => 'value' ) ),
	) as $mutation ) {
		$mutated                 = $task_state;
		$mutated[ $mutation[0] ] = $mutation[1];
		update_post_meta( $task_id, Store::META_STATE, wp_slash( wp_json_encode( $mutated ) ) );
		$bad_get    = $store->get_task( $task_id );
		$bad_page   = $store->page_tasks( array( 'limit' => 1 ) );
		$bad_resume = $store->resume();
		$bad_export = $store->export_snapshot();
		wpai126_quota_assert(
			is_wp_error( $bad_get ) && 'workspace_state_invalid' === $bad_get->get_error_code() &&
			is_wp_error( $bad_page ) && 'workspace_incomplete' === $bad_page->get_error_code() &&
			is_wp_error( $bad_resume ) && 'workspace_incomplete' === $bad_resume->get_error_code() &&
			is_wp_error( $bad_export ) && 'workspace_incomplete' === $bad_export->get_error_code(),
			'Semantic task corruption was normalized into valid state.'
		);
	}
	unset( $task_state['project_ref'], $task_state['next_action'], $task_state['blocker'] );
	update_post_meta( $task_id, Store::META_STATE, wp_slash( wp_json_encode( $task_state ) ) );
	$legacy_task = $store->get_task( $task_id );
	wpai126_quota_assert(
		! is_wp_error( $legacy_task ) && '' === $legacy_task['project_ref'] &&
		'' === $legacy_task['next_action'] && '' === $legacy_task['blocker'],
		'Genuine historical task without the new optional fields became unreadable.'
	);
	update_post_meta( $task_id, Store::META_STATE, wp_slash( $original_task ) );

	wpai126_quota_assert(
		wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) <= 96 * 1048576 &&
		memory_get_peak_usage( true ) <= 96 * 1048576,
		'The Workspace integrity and recovery fixture exceeded its 96MiB ceiling.'
	);
	echo 'WPAI153_MEMORY_FINAL effective=' . ini_get( 'memory_limit' ) .
		' peak_bytes=' . memory_get_peak_usage( true ) . "\n";
	echo "PASS: #126/#153 0/25/200/250+ scalable quota, project pagination, legacy compatibility and fail-closed export.\n";
} finally {
	foreach ( $scaled_task_ids as $scaled_task_id ) {
		wp_delete_post( (int) $scaled_task_id, true );
	}
	if ( null === $prior_capacity_option ) {
		delete_option( Workspace_Limits::OPTION_NAME );
	} else {
		update_option( Workspace_Limits::OPTION_NAME, $prior_capacity_option, false );
	}
	if ( $task_id > 0 ) {
		wp_delete_post( $task_id, true );
	}
	if ( $extra > 0 && ! is_wp_error( $extra ) ) {
		wp_delete_post( $extra, true );
	}
	foreach ( $created as $id ) {
		wp_delete_post( $id, true );
	}
}
