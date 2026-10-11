<?php
/**
 * Real WordPress/MariaDB 199/200 finite admission proof for docs and tasks.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Workspace\Store;

$key    = getenv( 'WPAI_QUOTA_KEY' );
$mode   = getenv( 'WPAI_QUOTA_MODE' );
$action = getenv( 'WPAI_QUOTA_ACTION' );
if ( ! is_string( $key ) || ! preg_match( '/^quota-[a-z0-9-]{8,55}$/D', $key ) ||
	! in_array( $mode, array( 'document', 'task' ), true ) ||
	! in_array( $action, array( 'setup', 'verify' ), true ) ) {
	throw new RuntimeException( 'Invalid Workspace quota state fixture.' );
}
// Multisite fixture chooses one actual secondary blog, never a fabricated site.
if ( '1' === getenv( 'WPAI_QUOTA_MULTISITE' ) ) {
	if ( ! is_multisite() ) {
		throw new RuntimeException( 'Multisite quota fixture requires an active network.' );
	}
	$base_blog      = get_current_blog_id();
	$secondary_blog = 0;
	foreach ( get_sites(
		array(
			'number' => 10,
			'fields' => 'ids',
		)
	) as $blog_id ) {
		if ( (int) $blog_id !== (int) $base_blog ) {
			$secondary_blog = (int) $blog_id;
			break;
		}
	}
	if ( $secondary_blog < 1 ) {
		throw new RuntimeException( 'Multisite quota fixture requires a second blog.' );
	}
	switch_to_blog( $secondary_blog );
}
$store         = new Store();
$type          = 'document' === $mode ? Store::DOCUMENT_POST_TYPE : Store::TASK_POST_TYPE;
$record_option = 'wpai126_test_quota_' . $key . '_' . $mode;
$all_ids       = static function () use ( $type ) {
	return get_posts(
		array(
			'post_type'      => $type,
			'post_status'    => array_keys( get_post_stati( array(), 'names' ) ),
			'posts_per_page' => Store::MAX_RECORDS + 1,
			'fields'         => 'ids',
		)
	);
};
if ( 'setup' === $action ) {
	if ( false !== get_option( $record_option, false ) ) {
		throw new RuntimeException( 'Workspace quota fixture has prior state.' );
	}
	$baseline = count( $all_ids() );
	if ( $baseline >= Store::MAX_RECORDS - 1 ) {
		throw new RuntimeException( 'Insufficient disposable Workspace capacity.' );
	}
	$ids = array();
	for ( $i = $baseline; $i < Store::MAX_RECORDS - 1; ++$i ) {
		$input  = array( 'title' => 'WPAI126 quota seed ' . $key . ' ' . $i );
		$result = 'document' === $mode ? $store->create_document( $input ) : $store->create_task( $input );
		if ( is_wp_error( $result ) || empty( $result['id'] ) ) {
			throw new RuntimeException( 'Failed to provision one Workspace quota fixture object.' );
		}
		$ids[] = (int) $result['id'];
	}
	if ( ! add_option(
		$record_option,
		wp_json_encode(
			array(
				'baseline' => $baseline,
				'ids'      => $ids,
			)
		),
		'',
		'off'
	) ||
		Store::MAX_RECORDS - 1 !== count( $all_ids() ) ) {
		throw new RuntimeException( 'Fixture did not reach the 199-record boundary.' );
	}
	echo "PASS: Workspace seeded to 199 records.\n";
	return;
}
$before = get_option( $record_option, false );
$state  = is_string( $before ) ? json_decode( $before, true ) : null;
if ( ! is_array( $state ) || ! isset( $state['ids'], $state['baseline'] ) ) {
	throw new RuntimeException( 'Missing Workspace quota seed manifest.' );
}
$ids = $all_ids();
if ( Store::MAX_RECORDS !== count( $ids ) ) {
	throw new RuntimeException( 'Concurrent Bridge creates exceeded or missed the 200-record cap.' );
}
$page   = 'document' === $mode ? $store->page_documents( array( 'limit' => 10 ) ) : $store->page_tasks( array( 'limit' => 10 ) );
$resume = $store->resume();
$export = $store->export_snapshot();
if ( is_wp_error( $page ) || Store::MAX_RECORDS !== (int) $page['total'] ||
	is_wp_error( $resume ) || is_wp_error( $export ) || empty( $export['complete'] ) ) {
	throw new RuntimeException( 'Concurrent-capacity Workspace cannot be fully listed, resumed or exported.' );
}
$created = array();
foreach ( $ids as $id ) {
	$post = get_post( $id );
	if ( $post && 0 === strpos( $post->post_title, 'WPAI126 quota race ' . $key . ' ' ) ) {
		$created[] = (int) $id;
	}
}
if ( 1 !== count( $created ) ) {
	throw new RuntimeException( 'Workspace quota race created more than one WordPress object.' );
}
foreach ( array_merge( $state['ids'], $created ) as $id ) {
	wp_delete_post( (int) $id, true );
}
delete_option( $record_option );
if ( count( $all_ids() ) !== (int) $state['baseline'] ) {
	throw new RuntimeException( 'Workspace fixture cleanup modified preexisting objects.' );
}
echo "PASS: Workspace 199->200 atomic {$mode} admission; complete page/resume/export.\n";
