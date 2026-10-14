<?php
/**
 * Real OAuth/official MCP Adapter source-file transfer regression. The full
 * >2 MiB source never enters one MCP JSON message; all bytes are SHA checked.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;
use WP_AI_Bridge\Support\Operation_Content_Limits;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Source_PHP_Syntax_Validator;

function wpai147_source_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( '#147 MCP source: ' . $message );
	}
}

function wpai147_source_mcp( $token, $session, $seq, $ability, $args = array() ) {
	$req = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$req->set_header( 'Authorization', 'Bearer ' . $token );
	$req->set_header( 'Accept', 'application/json, text/event-stream' );
	$req->set_header( 'Content-Type', 'application/json' );
	if ( '' !== $session ) {
		$req->set_header( 'Mcp-Session-Id', $session );
		$req->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	}
	$req->set_body(
		wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => $seq,
				'method'  => '' === $ability ? 'initialize' : 'tools/call',
				'params'  => '' === $ability ?
					array(
						'protocolVersion' => '2025-11-25',
						'capabilities'    => (object) array(),
						'clientInfo'      => array( 'name' => 'wpai147-source-mcp', 'version' => '1' ),
					) :
					array(
						'name'      => 'mcp-adapter-execute-ability',
						'arguments' => array(
							'ability_name' => $ability,
							'parameters'   => $args,
						),
					),
			)
		)
	);
	$response = rest_do_request( $req );
	$data     = json_decode( wp_json_encode( $response->get_data() ), true );
	wpai147_source_assert( 200 === $response->get_status() && is_array( $data ), 'MCP request failed: ' . $ability );
	return array( $data, $response->get_headers() );
}

$actor         = get_current_user_id();
$settings      = new Settings();
$old_grants    = get_option( Settings::OPTION_NAME, null );
$old_capacity  = get_option( Operation_Content_Limits::OPTION_NAME, null );
$fixture       = WP_PLUGIN_DIR . '/wpai147-source-mcp-fixture';
$entry         = $fixture . '/wpai147-source-mcp-fixture.php';
$helper        = $fixture . '/large-helper.php';
$plugin        = 'wpai147-source-mcp-fixture/wpai147-source-mcp-fixture.php';
$original      = "<?php\n/*\n" . str_repeat( 'q', 2320000 ) . "\n*/\n";
$candidate     = $original . "// source transfer complete\n";
$target        = array(
	'kind'      => 'plugin',
	'extension' => $plugin,
	'file'      => 'large-helper.php',
);
$ids           = array();
$sequence      = 10;
$session       = '';
$token         = '';
$grant         = $settings->defaults();
$grant[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
$grant[ Settings::GROUP_SOURCE_EDITING ]  = 1;

wpai147_source_assert( $actor > 0 && current_user_can( 'edit_plugins' ), 'Administrator source fixture is required.' );
wpai147_source_assert( ! is_dir( $fixture ), 'Source fixture directory already exists.' );
wpai147_source_assert( wp_mkdir_p( $fixture ), 'Source fixture directory could not be created.' );
file_put_contents( $entry, "<?php\n/* Plugin Name: Source Transfer Acceptance */\n" );
file_put_contents( $helper, $original );
wp_clean_plugins_cache( true );

try {
	update_option( Settings::OPTION_NAME, $grant, false );
	update_option( Operation_Content_Limits::OPTION_NAME, array( Operation_Content_Limits::SOURCE_MIB => 4 ), false );
	$oauth = new OAuth_Server( new OAuth_Store() );
	$token = ( new OAuth_Store() )->issue(
		OAuth_Store::TYPE_ACCESS,
		array(
			'user_id'   => $actor,
			'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
			'resource'  => $oauth->mcp_endpoint_url(),
			'scope'     => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
		),
		180
	);
	wpai147_source_assert( is_string( $token ) && '' !== $token, 'Could not mint scoped test-only MCP token.' );
	$before = class_exists( '\\WP\\MCP\\Transport\\Infrastructure\\SessionManager' ) ?
		\WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor ) : array();
	wp_set_current_user( 0 );
	list( $init, $headers ) = wpai147_source_mcp( $token, '', 1, '' );
	wpai147_source_assert( '2025-11-25' === ( $init['result']['protocolVersion'] ?? null ), 'Native Adapter initialize failed.' );
	$session = (string) ( $headers['Mcp-Session-Id'] ?? '' );
	if ( '' === $session && class_exists( '\\WP\\MCP\\Transport\\Infrastructure\\SessionManager' ) ) {
		$after   = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor );
		$session = (string) array_key_first( array_diff_key( $after, $before ) );
	}
	wpai147_source_assert( '' !== $session, 'MCP session missing.' );
	$notice = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$notice->set_header( 'Authorization', 'Bearer ' . $token );
	$notice->set_header( 'Mcp-Session-Id', $session );
	$notice->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	$notice->set_header( 'Content-Type', 'application/json' );
	$notice->set_body( '{"jsonrpc":"2.0","method":"notifications/initialized"}' );
	wpai147_source_assert( 202 === rest_do_request( $notice )->get_status(), 'MCP initialization notification failed.' );

	$call = static function ( $name, $args ) use ( $token, $session, &$sequence ) {
		list( $wire ) = wpai147_source_mcp( $token, $session, ++$sequence, $name, $args );
		// Official MCP Adapter uses result.isError=true for denied/invalid
		// native Abilities, frequently without structuredContent at all.
		// Never interpret an absent success key as a successful denial.
		if ( true === ( $wire['result']['isError'] ?? false ) ) {
			return array( 'success' => false, 'isError' => true );
		}
		return $wire['result']['structuredContent'] ?? array();
	};

	// Frozen source egress in actual native MCP windows.
	$snapshot = $call( 'wp-ai-bridge/source-file-snapshot', $target );
	wpai147_source_assert( true === ( $snapshot['success'] ?? false ), 'Authenticated source snapshot denied.' );
	$meta = $snapshot['data'];
	$ids[] = $meta['transfer_id'];
	wpai147_source_assert( strlen( $original ) === $meta['total_bytes'] && hash( 'sha256', $original ) === $meta['sha256'], 'Snapshot identity was incorrect.' );
	$received = '';
	$offset   = 0;
	$windows  = 0;
	while ( $offset < strlen( $original ) ) {
		$window = $call(
			'wp-ai-bridge/private-transfer',
			array( 'action' => 'read', 'transfer_id' => $meta['transfer_id'], 'offset' => $offset, 'max_bytes' => 16000 )
		);
		wpai147_source_assert( true === ( $window['success'] ?? false ), 'Bounded snapshot read failed.' );
		$data = $window['data'];
		$part = base64_decode( $data['content_base64'], true );
		wpai147_source_assert( is_string( $part ) && hash( 'sha256', $part ) === $data['chunk_sha256'], 'Snapshot chunk checksum mismatch.' );
		$received .= $part;
		wpai147_source_assert( $data['read_next_offset'] > $offset, 'Snapshot offset made no progress.' );
		$offset = $data['read_next_offset'];
		++$windows;
	}
	wpai147_source_assert( $windows > 100 && $received === $original && hash( 'sha256', $received ) === $meta['sha256'], 'Large MCP source reconstruction was not byte exact.' );
	unset( $received );
	$closed_snapshot = $call( 'wp-ai-bridge/private-transfer', array( 'action' => 'cancel', 'transfer_id' => $meta['transfer_id'] ) );
	wpai147_source_assert( true === ( $closed_snapshot['success'] ?? false ), 'Source snapshot private lease was not cleaned up.' );

	$begin = $call(
		'wp-ai-bridge/private-transfer',
		array( 'action' => 'begin', 'purpose' => 'source_plugin', 'total_bytes' => strlen( $candidate ), 'sha256' => hash( 'sha256', $candidate ) )
	);
	wpai147_source_assert( true === ( $begin['success'] ?? false ), 'Source-purpose private ingress was denied.' );
	$id    = $begin['data']['transfer_id'];
	$ids[] = $id;
	$offset = 0;
	while ( $offset < strlen( $candidate ) ) {
		$part = substr( $candidate, $offset, min( 32000, $begin['data']['max_chunk_bytes'] ) );
		$sent = $call(
			'wp-ai-bridge/private-transfer',
			array(
				'action'         => 'append',
				'transfer_id'    => $id,
				'offset'         => $offset,
				'content_base64' => base64_encode( $part ),
			)
		);
		wpai147_source_assert( true === ( $sent['success'] ?? false ), 'MCP inbound source append failed at offset ' . $offset );
		$offset += strlen( $part );
	}
	$verified = $call( 'wp-ai-bridge/private-transfer', array( 'action' => 'verify', 'transfer_id' => $id ) );
	wpai147_source_assert( true === ( $verified['success'] ?? false ), 'Source transfer SHA verification failed.' );
	$preview = $call( 'wp-ai-bridge/source-file-preview-transfer', array_merge( $target, array( 'transfer_id' => $id ) ) );
	wpai147_source_assert( true === ( $preview['success'] ?? false ), 'Large source preview over MCP failed.' );
	$plan = $preview['data'];
	$apply = array_merge(
		$target,
		array(
			'transfer_id'      => $id,
			'preimage_sha256'  => $plan['preimage_sha256'],
			'candidate_sha256' => $plan['candidate_sha256'],
			'candidate_id'     => $plan['candidate_id'],
		)
	);
	$wrong = $call( 'wp-ai-bridge/source-file-preview-transfer', array_merge( $target, array( 'transfer_id' => $id, 'file' => 'wpai147-source-mcp-fixture.php' ) ) );
	wpai147_source_assert( true === ( $wrong['isError'] ?? false ), 'Verified source transfer was rebound to a different file.' );
	$bad_digest = $call( 'wp-ai-bridge/source-file-apply-transfer', array_merge( $apply, array( 'candidate_sha256' => str_repeat( '0', 64 ) ) ) );
	wpai147_source_assert( true === ( $bad_digest['isError'] ?? false ), 'Incorrect source digest passed CAS.' );
	wp_set_current_user( $actor );
	$grant[ Settings::GROUP_SOURCE_EDITING ] = 0;
	update_option( Settings::OPTION_NAME, $grant, false );
	wp_set_current_user( 0 );
	$revoked = $call( 'wp-ai-bridge/source-file-apply-transfer', $apply );
	wpai147_source_assert( true === ( $revoked['isError'] ?? false ), 'Revoked Source Editing grant allowed transfer apply.' );
	wp_set_current_user( $actor );
	$grant[ Settings::GROUP_SOURCE_EDITING ] = 1;
	update_option( Settings::OPTION_NAME, $grant, false );
	wp_set_current_user( 0 );
	$committed = $call( 'wp-ai-bridge/source-file-apply-transfer', $apply );
	wpai147_source_assert( true === ( $committed['success'] ?? false ) && 'success' === $committed['data']['outcome'], 'Authenticated source transfer CAS/apply failed.' );
	wpai147_source_assert( $candidate === file_get_contents( $helper ), 'Source transfer persisted different native file bytes.' );
	$again = $call( 'wp-ai-bridge/source-file-apply-transfer', $apply );
	wpai147_source_assert( true === ( $again['isError'] ?? false ), 'Previously claimed private source was replayed.' );
	$receipt = $call( 'wp-ai-bridge/private-transfer', array( 'action' => 'status', 'transfer_id' => $id ) );
	wpai147_source_assert( 'committed' === ( $receipt['data']['state'] ?? '' ), 'Durable source transfer receipt was not committed.' );
	$released = $call( 'wp-ai-bridge/private-transfer', array( 'action' => 'release', 'transfer_id' => $id ) );
	wpai147_source_assert( true === ( $released['success'] ?? false ) && true === $released['data']['payload_released'], 'Committed source lease was not safely released.' );

	wp_set_current_user( $actor );
	// High token density is rejected before allocating a risky full token array
	// inside the WordPress PHP worker. A resource failure must be no-write.
	$peak_before = memory_get_peak_usage( true );
	$dense       = "<?php\n" . str_repeat( '$n=1;', 490000 );
	$limited     = ini_set( 'memory_limit', '128M' );
	$syntax      = ( new Source_PHP_Syntax_Validator() )->validate( $dense );
	if ( false !== $limited ) {
		ini_set( 'memory_limit', $limited );
	}
	wpai147_source_assert(
		is_wp_error( $syntax ) &&
		in_array( $syntax->get_error_code(), array( 'source_php_validation_capacity', 'source_php_validation_timeout' ), true ),
		'High-density PHP source was not boundedly rejected.'
	);
	wpai147_source_assert( memory_get_peak_usage( true ) - $peak_before < 32 * 1048576, 'Parent PHP parser memory grew like an unbounded token array.' );
	wpai147_source_assert( $candidate === file_get_contents( $helper ), 'Rejected high-token-density candidate affected installed source.' );

	echo 'PASS: #147 real OAuth/MCP source egress >2MiB in ' . $windows . ' bounded windows, staged ingress/CAS, wrong target/digest, revocation, replay, bounded PHP lexer; peak=' . memory_get_peak_usage( true ) . "\n";
} finally {
	wp_set_current_user( $actor );
	if ( null === $old_grants ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $old_grants, false );
	}
	if ( null === $old_capacity ) {
		delete_option( Operation_Content_Limits::OPTION_NAME );
	} else {
		update_option( Operation_Content_Limits::OPTION_NAME, $old_capacity, false );
	}
	foreach ( $ids as $transfer_id ) {
		( new \WP_AI_Bridge\Support\Private_Transfer_Session( new \WP_AI_Bridge\Support\Permissions( new Settings() ) ) )->cancel( $transfer_id );
	}
	foreach ( array( $helper, $entry ) as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
	if ( is_dir( $fixture ) ) {
		rmdir( $fixture );
	}
	wp_clean_plugins_cache( true );
}
