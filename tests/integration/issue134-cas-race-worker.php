<?php
/**
 * Two actual independent WordPress/OAuth/MCP PHP workers against one post.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;

$key  = getenv( 'WPAI134_CAS_KEY' );
$slot = getenv( 'WPAI134_CAS_SLOT' );
$mode = getenv( 'WPAI134_CAS_MODE' );
if ( ! is_string( $key ) || 1 !== preg_match( '/^cas-[a-z0-9-]{10,55}$/D', $key ) ||
	! in_array( $slot, array( 'one', 'two' ), true ) ||
	! in_array( $mode, array( 'two_leases', 'ordinary' ), true ) ) {
	throw new RuntimeException( 'Untrusted CAS race worker configuration.' );
}
$dir   = WP_CONTENT_DIR . '/wpai134-cas-' . $key;
$state = json_decode( (string) file_get_contents( $dir . '/state.json' ), true );
if ( ! is_array( $state ) || ! isset( $state['post_id'], $state['modified_gmt'], $state['state_hash'] ) ) {
	throw new RuntimeException( 'CAS race shared fixture did not contain a full exact post identity.' );
}

$deadline = microtime( true ) + 35;
$await    = static function ( $marker ) use ( $dir, $deadline ) {
	while ( ! is_file( $dir . '/' . $marker ) ) {
		if ( microtime( true ) > $deadline ) {
			throw new RuntimeException( 'Native CAS cross-process ordering barrier was not reached: ' . $marker );
		}
		usleep( 20000 );
	}
};
$signal   = static function ( $marker ) use ( $dir ) {
	if ( false === file_put_contents( $dir . '/' . $marker, 'ready' ) ) {
		throw new RuntimeException( 'Cannot persist test-only WordPress ordering signal.' );
	}
};

if ( 'ordinary' === $mode && 'two' === $slot ) {
	$written = wp_update_post(
		array(
			'ID'           => (int) $state['post_id'],
			'post_content' => 'ordinary native winner',
		),
		true
	);
	if ( is_wp_error( $written ) ) {
		throw new RuntimeException( 'Ordinary native WordPress writer could not publish its competing update.' );
	}
	$signal( 'ordinary.complete' );
	echo "WPAI134_CAS_RESULT=ordinary-native\n";
	return;
}

$actor = get_current_user_id();
$store = new OAuth_Store();
$oauth = new OAuth_Server( $store );
$token = $store->issue(
	OAuth_Store::TYPE_ACCESS,
	array(
		'user_id'   => $actor,
		'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
		'resource'  => $oauth->mcp_endpoint_url(),
		'scope'     => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
	),
	180
);
if ( ! is_string( $token ) || '' === $token ) {
	throw new RuntimeException( 'Disposable OAuth client could not be authorized.' );
}
$rpc    = static function ( $session, $seq, $action, $args = array() ) use ( $token ) {
	$request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	$request->set_header( 'Content-Type', 'application/json' );
	if ( '' !== $session ) {
		$request->set_header( 'Mcp-Session-Id', $session );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	}
	if ( 'init' === $action ) {
		$call = array(
			'jsonrpc' => '2.0',
			'id'      => $seq,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => (object) array(),
				'clientInfo'      => array(
					'name'    => 'wpai134-cas-test',
					'version' => '1',
				),
			),
		);
	} else {
		$call = array(
			'jsonrpc' => '2.0',
			'id'      => $seq,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'mcp-adapter-execute-ability',
				'arguments' => array(
					'ability_name' => $action,
					'parameters'   => $args,
				),
			),
		);
	}
	$request->set_body( wp_json_encode( $call ) );
	$response = rest_do_request( $request );
	return array( json_decode( wp_json_encode( $response->get_data() ), true ), $response->get_headers() );
};
$before = class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' )
	? \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor ) : array();
wp_set_current_user( 0 );
list( $init, $headers ) = $rpc( '', 1, 'init' );
$session                = (string) ( $headers['Mcp-Session-Id'] ?? '' );
if ( '' === $session && class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ) {
	$after   = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor );
	$session = (string) array_key_first( array_diff_key( $after, $before ) );
}
if ( empty( $session ) || '2025-11-25' !== ( $init['result']['protocolVersion'] ?? '' ) ) {
	throw new RuntimeException( 'Disposable native MCP session could not be initialized.' );
}
$notice = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$notice->set_header( 'Authorization', 'Bearer ' . $token );
$notice->set_header( 'Mcp-Session-Id', $session );
$notice->set_header( 'MCP-Protocol-Version', '2025-11-25' );
$notice->set_header( 'Content-Type', 'application/json' );
$notice->set_body( '{"jsonrpc":"2.0","method":"notifications/initialized"}' );
if ( 202 !== rest_do_request( $notice )->get_status() ) {
	throw new RuntimeException( 'Native MCP session notification could not be completed.' );
}
$body          = 'one' === $slot ? 'CAS first worker winner' : 'CAS second worker stale';
list( $begun ) = $rpc(
	$session,
	2,
	'wp-ai-bridge/private-transfer',
	array(
		'action'      => 'begin',
		'purpose'     => 'text',
		'total_bytes' => strlen( $body ),
		'sha256'      => hash( 'sha256', $body ),
	)
);
$id            = (string) ( $begun['result']['structuredContent']['data']['transfer_id'] ?? '' );
if ( 64 !== strlen( $id ) ) {
	throw new RuntimeException( 'Independent CAS worker could not stage its own lease.' );
}
list( $appended ) = $rpc(
	$session,
	3,
	'wp-ai-bridge/private-transfer',
	array(
		'action'         => 'append',
		'transfer_id'    => $id,
		'offset'         => 0,
		'content_base64' => base64_encode( $body ),
	)
);
list( $verified ) = $rpc(
	$session,
	4,
	'wp-ai-bridge/private-transfer',
	array(
		'action'      => 'verify',
		'transfer_id' => $id,
	)
);
if ( empty( $appended['result']['structuredContent']['success'] ) || empty( $verified['result']['structuredContent']['success'] ) ) {
	throw new RuntimeException( 'Race worker could not verify its independent source lease.' );
}

if ( 'ordinary' === $mode ) {
	$await( 'ordinary.complete' );
} elseif ( 'one' === $slot ) {
	// This filter is invoked INSIDE native wp_update_post() while holding
	// the physical InnoDB post row lock. Two must enter with the same H0.
	$pause = static function ( $data ) use ( $body, $signal, $await ) {
		if ( isset( $data['post_content'] ) && $data['post_content'] === $body ) {
			$signal( 'one.locked' );
			$await( 'two.ready' );
			usleep( 350000 );
		}
		return $data;
	};
	add_filter( 'wp_insert_post_data', $pause );
} else {
	$await( 'one.locked' );
	$signal( 'two.ready' );
}

list( $result ) = $rpc(
	$session,
	5,
	'wp-ai-bridge/transfer-content-commit',
	array(
		'action'                => 'update',
		'transfer_id'           => $id,
		'post_id'               => (int) $state['post_id'],
		'expected_modified_gmt' => $state['modified_gmt'],
		'expected_state_hash'   => $state['state_hash'],
	)
);
if ( 'two_leases' === $mode && 'one' === $slot ) {
	if ( empty( $result['result']['structuredContent']['success'] ) ) {
		throw new RuntimeException( 'First database-row-locked WordPress update unexpectedly failed.' );
	}
	echo "WPAI134_CAS_RESULT=committed\n";
} else {
	if ( empty( $result['result']['isError'] ) ) {
		throw new RuntimeException( 'Race loser overwrote a concurrent native WordPress authoring state.' );
	}
	list( $status ) = $rpc(
		$session,
		6,
		'wp-ai-bridge/private-transfer',
		array(
			'action'      => 'status',
			'transfer_id' => $id,
		)
	);
	if ( ( $status['result']['structuredContent']['data']['state'] ?? '' ) !== 'verified' ) {
		throw new RuntimeException( 'Losing CAS transfer was claimed before detecting authoritative competing edit.' );
	}
	echo "WPAI134_CAS_RESULT=conflict\n";
}
$store->revoke( $token );
