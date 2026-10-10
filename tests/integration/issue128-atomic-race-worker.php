<?php
/**
 * Two independent WP-CLI PHP/DB workers synchronize before one atomic claim.
 *
 * @package WP_AI_Bridge
 */
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Create_Claim;
use WP_AI_Bridge\Workspace\Store;

$key  = getenv( 'WPAI128_RACE_KEY' );
$slot = getenv( 'WPAI128_RACE_SLOT' );
$mode = getenv( 'WPAI128_RACE_MODE' );
if ( ! is_string( $key ) || ! preg_match( '/^race-[a-z0-9-]{8,55}$/D', $key ) ||
	! in_array( $slot, array( 'one', 'two' ), true ) ||
	! in_array( $mode, array( 'operation', 'canonical' ), true ) ) {
	throw new RuntimeException( 'Invalid isolated race configuration.' );
}
$identity = 'operation' === $mode
	? array( get_current_blog_id(), get_current_user_id(), (string) OAuth_Server::authenticated_mcp_client_id(), 'race-create', $key )
	: array( 'canonical-document', get_current_blog_id(), $key, 'race-brief' );
$name = Create_Claim::PREFIX . hash( 'sha256', wp_json_encode( $identity ) );
$dir  = WP_CONTENT_DIR . '/wpai128-race-' . $key . '-' . $mode;
// Both PHP workers can pass is_dir() concurrently. wp_mkdir_p() may
// legitimately return false when the other worker created the directory
// milliseconds earlier; recheck rather than failing the race fixture.
if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
	throw new RuntimeException( 'Could not establish isolated test barrier.' );
}
add_filter(
	'pre_option_' . $name,
	static function ( $pre ) use ( $dir, $slot ) {
		static $once = false;
		if ( ! $once ) {
			$once = true;
			file_put_contents( $dir . '/' . $slot . '.ready', 'ready' );
			$other    = 'one' === $slot ? 'two' : 'one';
			$deadline = microtime( true ) + 45; // Finite timeout, tolerant of runner container startup skew.
			while ( ! is_file( $dir . '/' . $other . '.ready' ) ) {
				if ( microtime( true ) >= $deadline ) {
					throw new RuntimeException( 'Second PHP worker never reached barrier.' );
				}
				usleep( 20000 );
			}
		}
		return $pre;
	},
	10,
	1
);
$performed = false;
if ( 'operation' === $mode ) {
	$result = Create_Claim::run(
		'race-create',
		array( 'title' => 'WPAI128 atomic ' . $key, 'operation_id' => $key ),
		static function () { return true; },
		static function ( $input ) use ( &$performed ) {
			$performed = true;
			usleep( 500000 );
			$id = wp_insert_post(
				array(
					'post_type'   => 'post',
					'post_status' => 'draft',
					'post_title'  => $input['title'],
				),
				true
			);
			return is_wp_error( $id ) ? $id : array( 'id' => (int) $id );
		},
		static function ( $id ) {
			$post = get_post( $id );
			return $post && current_user_can( 'edit_post', $id ) ? array( 'id' => (int) $id ) : null;
		}
	);
} else {
	$store  = new Store();
	$result = $store->create_document(
		array(
			'key'         => 'race-brief',
			'project_ref' => $key,
			'title'       => 'WPAI128 canonical ' . $key,
			'content'     => 'One canonical project brief across concurrent PHP workers.',
		)
	);
}
if ( is_wp_error( $result ) ) {
	if ( ! in_array( $result->get_error_code(), array( 'create_claim_outcome_unknown', 'workspace_key_reserved', 'workspace_key_exists' ), true ) ) {
		throw new RuntimeException( 'Unexpected race error: ' . $result->get_error_code() );
	}
	echo 'WPAI128_RACE_RESULT=contended:' . $result->get_error_code() . "\n";
} elseif ( is_array( $result ) && ! empty( $result['id'] ) ) {
	echo 'WPAI128_RACE_RESULT=' . ( $performed || 'canonical' === $mode ? 'created:' : 'contended:recovered:' ) . (int) $result['id'] . "\n";
} else {
	throw new RuntimeException( 'Unexpected race result.' );
}
