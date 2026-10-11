<?php
/**
 * Independent WordPress process competing for the last Workspace record.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Workspace\Store;

$key  = getenv( 'WPAI_QUOTA_KEY' );
$mode = getenv( 'WPAI_QUOTA_MODE' );
$slot = getenv( 'WPAI_QUOTA_SLOT' );
if ( ! is_string( $key ) || ! preg_match( '/^quota-[a-z0-9-]{8,55}$/D', $key ) ||
	! in_array( $mode, array( 'document', 'task' ), true ) ||
	! in_array( $slot, array( 'one', 'two' ), true ) ) {
	throw new RuntimeException( 'Invalid Workspace quota worker.' );
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
$dir = WP_CONTENT_DIR . '/wpai126-quota-' . $key . '-' . $mode;
if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
	throw new RuntimeException( 'Could not initialize Workspace quota barrier.' );
}
file_put_contents( $dir . '/' . $slot . '.ready', 'ready' );
$other    = 'one' === $slot ? 'two' : 'one';
$deadline = microtime( true ) + 45;
while ( ! is_file( $dir . '/' . $other . '.ready' ) ) {
	if ( microtime( true ) > $deadline ) {
		throw new RuntimeException( 'Workspace quota peer never started.' );
	}
	usleep( 20000 );
}
$store = new Store();
$input = array( 'title' => 'WPAI126 quota race ' . $key . ' ' . $slot );
if ( 'document' === $mode ) {
	$input['content'] = 'Exactly one document may occupy the final capacity slot.';
	$result           = $store->create_document( $input );
} else {
	$result = $store->create_task( $input );
}
if ( is_wp_error( $result ) ) {
	$expected = 'document' === $mode ? 'workspace_document_limit' : 'workspace_task_limit';
	if ( $result->get_error_code() !== $expected ) {
		throw new RuntimeException( 'Unexpected Workspace quota rejection: ' . $result->get_error_code() );
	}
	echo "WPAI_QUOTA_RESULT=capacity\n";
} elseif ( is_array( $result ) && ! empty( $result['id'] ) ) {
	echo 'WPAI_QUOTA_RESULT=created:' . (int) $result['id'] . "\n";
} else {
	throw new RuntimeException( 'Unexpected Workspace quota result.' );
}
