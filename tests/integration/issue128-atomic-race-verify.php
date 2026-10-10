<?php
/**
 * Verify that the race produced exactly one real WP object, then clean it up.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Create_Claim;
use WP_AI_Bridge\Workspace\Store;

$key  = getenv( 'WPAI128_RACE_KEY' );
$mode = getenv( 'WPAI128_RACE_MODE' );
if ( ! is_string( $key ) || ! preg_match( '/^race-[a-z0-9-]{8,55}$/D', $key ) ||
	! in_array( $mode, array( 'operation', 'canonical' ), true ) ) {
	throw new RuntimeException( 'Invalid race verification identity.' );
}
$identity = 'operation' === $mode
	? array( get_current_blog_id(), get_current_user_id(), (string) OAuth_Server::authenticated_mcp_client_id(), 'race-create', $key )
	: array( 'canonical-document', get_current_blog_id(), $key, 'race-brief' );
$option = get_option( Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( $identity ) ), '' );
$receipt = is_string( $option ) ? json_decode( $option, true ) : null;
if ( ! is_array( $receipt ) || 'committed' !== ( $receipt['state'] ?? '' ) || empty( $receipt['id'] ) ) {
	throw new RuntimeException( 'Race winner did not durably commit a unique WordPress option receipt.' );
}
$id = (int) $receipt['id'];
if ( 'operation' === $mode ) {
	$matches = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'title'          => 'WPAI128 atomic ' . $key,
		)
	);
	if ( 1 !== count( $matches ) || (int) $matches[0]->ID !== $id ) {
		throw new RuntimeException( 'Two WordPress posts were produced by one operation ID.' );
	}
} else {
	$store = new Store();
	$all   = $store->list_documents( true );
	if ( is_wp_error( $all ) ) {
		throw new RuntimeException( 'Cannot enumerate project documents after concurrent create.' );
	}
	$matches = array_filter(
		$all,
		static function ( $doc ) use ( $key ) {
			return $key === $doc['project_ref'] && 'race-brief' === $doc['key'];
		}
	);
	if ( 1 !== count( $matches ) || (int) current( $matches )['id'] !== $id ) {
		throw new RuntimeException( 'Two project briefs were produced for one canonical project key.' );
	}
}
wp_delete_post( $id, true );
echo "PASS: #128 {$mode} atomically admitted only one creating PHP process (id {$id}).\n";
