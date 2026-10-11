<?php
/**
 * Real WordPress 0/25/200/201-record completeness and invalid-state smoke.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Workspace\Store;

function wpai126_quota_assert( $ok, $reason ) {
	if ( ! $ok ) {
		throw new RuntimeException( $reason );
	}
}
$store   = new Store();
$created = array();
$extra   = 0;
$task_id = 0;
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
		$record = $store->create_document(
			array(
				'title'   => 'WPAI126 quota ' . $i,
				'content' => 'Bounded canonical fixture ' . $i,
			)
		);
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

	$at_limit = $store->create_document( array( 'title' => 'Over quota' ) );
	wpai126_quota_assert(
		is_wp_error( $at_limit ) && 'workspace_document_limit' === $at_limit->get_error_code(),
		'Normal create exceeded the 200-record documented site cap.'
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
		is_wp_error( $overflow ) && 'workspace_overflow' === $overflow->get_error_code() &&
		is_wp_error( $export ) && 'workspace_overflow' === $export->get_error_code(),
		'Over-quota results claimed completeness or exported omitted records.'
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

	echo "PASS: #126 0/25/200/201 quota, canonical semantics, legacy compatibility and fail-closed export.\n";
} finally {
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
