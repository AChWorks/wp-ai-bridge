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
try {
	$empty = $store->page_documents( array( 'limit' => 25 ) );
	wpai126_quota_assert( ! is_wp_error( $empty ) && 0 === $empty['total'] &&
		empty( $empty['items'] ) && ! $empty['has_more'], 'Empty Workspace state is not truthful.' );
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

	$seen = array();
	$cursor = 0;
	for ( $page_no = 0; $page_no < 8; ++$page_no ) {
		$page = $store->page_documents( array( 'limit' => 25, 'before_id' => $cursor ) );
		wpai126_quota_assert( ! is_wp_error( $page ) && 200 === $page['total'] &&
			25 === count( $page['items'] ) && Bounded_Payload::fits( $page ),
			'Full-capacity small-page enumeration lost or exceeded response budget.' );
		foreach ( $page['items'] as $item ) {
			wpai126_quota_assert( ! isset( $seen[ $item['id'] ] ), 'ID continuation duplicated a Workspace document.' );
			$seen[ $item['id'] ] = true;
		}
		wpai126_quota_assert( $page_no < 7 ? $page['has_more'] : ! $page['has_more'], 'Page completeness/cursor incorrectly advertised.' );
		$cursor = $page['next_cursor'];
	}
	wpai126_quota_assert( 200 === count( $seen ), 'A full-capacity enumeration silently omitted records.' );
	$resume = $store->resume();
	wpai126_quota_assert( ! is_wp_error( $resume ) && 200 === $resume['counts']['documents_total'] &&
		200 === $resume['sections']['documents']['total'] && $resume['sections']['documents']['has_more'],
		'Resume concealed index truncation at the quota.' );

	$at_limit = $store->create_document( array( 'title' => 'Over quota' ) );
	wpai126_quota_assert( is_wp_error( $at_limit ) && 'workspace_document_limit' === $at_limit->get_error_code(),
		'Normal create exceeded the 200-record documented site cap.' );

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
	$export = $store->export_snapshot();
	wpai126_quota_assert( is_wp_error( $overflow ) && 'workspace_overflow' === $overflow->get_error_code() &&
		is_wp_error( $export ) && 'workspace_overflow' === $export->get_error_code(),
		'Over-quota results claimed completeness or exported omitted records.' );

	wp_delete_post( $extra, true );
	$extra = 0;
	update_post_meta( $created[0], Store::META_STATE, '{broken JSON' );
	$damaged = $store->page_documents( array( 'limit' => 1 ) );
	$damaged_export = $store->export_snapshot();
	wpai126_quota_assert( is_wp_error( $damaged ) && 'workspace_incomplete' === $damaged->get_error_code() &&
		is_wp_error( $damaged_export ) && 'workspace_incomplete' === $damaged_export->get_error_code(),
		'Corrupt Workspace backing state was silently omitted or exported as complete.' );

	echo "PASS: #126 0/25/200/201 WordPress record quota, cursor, corrupt-state and export truth.\n";
} finally {
	if ( $extra > 0 && ! is_wp_error( $extra ) ) {
		wp_delete_post( $extra, true );
	}
	foreach ( $created as $id ) {
		wp_delete_post( $id, true );
	}
}
