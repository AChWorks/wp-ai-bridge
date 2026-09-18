<?php
/**
 * Real-WordPress integration coverage for Issue #80 refresh recovery.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Auth\Approved_OAuth_Clients;
use WP_AI_Bridge\Auth\Client_Assertion_Validator;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Auth\OAuth_Store;

function wpai_issue80_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpai_issue80_data( $response ) {
	return json_decode( wp_json_encode( $response->get_data() ), true );
}

function wpai_issue80_b64( $value ) {
	return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
}

function wpai_issue80_key( $kid ) {
	$key = openssl_pkey_new(
		array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		)
	);
	wpai_issue80_assert( false !== $key, 'Could not generate an Issue #80 RSA fixture.' );
	$details = openssl_pkey_get_details( $key );
	wpai_issue80_assert( is_array( $details ) && ! empty( $details['rsa']['n'] ) && ! empty( $details['rsa']['e'] ), 'Issue #80 RSA details are unavailable.' );
	return array(
		'private' => $key,
		'jwk'     => array(
			'kty' => 'RSA',
			'kid' => $kid,
			'use' => 'sig',
			'alg' => 'RS256',
			'n'   => wpai_issue80_b64( $details['rsa']['n'] ),
			'e'   => wpai_issue80_b64( $details['rsa']['e'] ),
		),
	);
}

function wpai_issue80_assertion( $client_id, $audience, array $key, $jti = '' ) {
	$header    = array(
		'alg' => 'RS256',
		'kid' => $key['jwk']['kid'],
		'typ' => 'JWT',
	);
	$claims    = array(
		'iss' => $client_id,
		'sub' => $client_id,
		'aud' => $audience,
		'iat' => time(),
		'exp' => time() + 120,
		'jti' => '' !== $jti ? $jti : bin2hex( random_bytes( 16 ) ),
	);
	$input     = wpai_issue80_b64( wp_json_encode( $header ) ) . '.' . wpai_issue80_b64( wp_json_encode( $claims ) );
	$signature = '';
	wpai_issue80_assert( openssl_sign( $input, $signature, $key['private'], OPENSSL_ALGO_SHA256 ), 'Could not sign an Issue #80 client assertion.' );
	return $input . '.' . wpai_issue80_b64( $signature );
}

function wpai_issue80_token_request( OAuth_Server $oauth, $client_id, array $key, $refresh_token, $resource_url, array $extra = array(), $assertion = '' ) {
	$request = new WP_REST_Request( 'POST', '/wp-ai-bridge/v1/oauth/token' );
	$params  = array_merge(
		array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id'     => $client_id,
			'resource'      => $resource_url,
		),
		$extra
	);
	foreach ( $params as $name => $value ) {
		$request->set_param( $name, $value );
	}
	$request->set_param( 'client_assertion_type', Client_Assertion_Validator::ASSERTION_TYPE );
	$request->set_param(
		'client_assertion',
		'' !== $assertion ? $assertion : wpai_issue80_assertion( $client_id, $oauth->token_endpoint_url(), $key )
	);
	return $oauth->handle_token_request( $request );
}

function wpai_issue80_seed_refresh( OAuth_Store $store, $user_id, $client_id, $revision, $resource_url ) {
	return $store->issue(
		OAuth_Store::TYPE_REFRESH,
		array(
			'user_id'         => $user_id,
			'client_id'       => $client_id,
			'client_revision' => $revision,
			'resource'        => $resource_url,
			'scope'           => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
		),
		OAuth_Server::REFRESH_TTL
	);
}

function wpai_issue80_recovery_identity( OAuth_Store $store, $refresh_token ) {
	$method = new ReflectionMethod( OAuth_Store::class, 'refresh_recovery_id' );
	$method->setAccessible( true );
	return $method->invoke( $store, $refresh_token );
}

function wpai_issue80_delete_artifact_backing( OAuth_Store $store, $type, $token ) {
	$parse = new ReflectionMethod( OAuth_Store::class, 'parse' );
	$parse->setAccessible( true );
	$parsed = $parse->invoke( $store, $type, $token );
	wpai_issue80_assert( is_array( $parsed ) && isset( $parsed[0] ), 'Could not resolve Issue #80 artifact selector.' );
	delete_transient( 'wpai_oauth_' . sanitize_key( $type ) . '_' . $parsed[0] );
}

function wpai_issue80_transient_count( $prefix ) {
	global $wpdb;
	$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
	if ( 'wpai_oauth_refresh_' === $prefix ) {
		$recovery_like = $wpdb->esc_like( '_transient_' . OAuth_Store::REFRESH_RECOVERY_PREFIX ) . '%';
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Integration-only count proves recovery does not mint hidden successor generations.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s", $like, $recovery_like ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table identifier is the current WordPress options table; values are prepared.
		);
	}
	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Integration-only count proves recovery does not mint hidden successor generations.
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table identifier is the current WordPress options table; the value is prepared.
	);
}

function wpai_issue80_error_is( $response, $code ) {
	$data = wpai_issue80_data( $response );
	return 400 === $response->get_status() && ( $data['error'] ?? '' ) === $code;
}

wpai_issue80_assert( get_current_user_id() > 0, 'Run Issue #80 integration as an authenticated WordPress user.' );
wpai_issue80_assert( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ), 'OpenSSL AEAD support is required for refresh recovery.' );

$user_id    = get_current_user_id();
$client_a   = 'https://www.example.com/wp-ai-bridge/issue80-client-a.json';
$client_b   = 'https://www.example.org/wp-ai-bridge/issue80-client-b.json';
$jwks_a     = 'https://www.example.com/wp-ai-bridge/issue80-jwks-a.json';
$jwks_b     = 'https://www.example.org/wp-ai-bridge/issue80-jwks-b.json';
$redirect_a = 'https://www.example.com/wp-ai-bridge/issue80-callback-a';
$redirect_b = 'https://www.example.org/wp-ai-bridge/issue80-callback-b';
$key_a      = wpai_issue80_key( 'issue80-a' );
$key_b      = wpai_issue80_key( 'issue80-b' );

$original_clients  = get_option( Approved_OAuth_Clients::OPTION_NAME, null );
$original_revision = get_option( Approved_OAuth_Clients::REVISION_OPTION, null );
$original_activity = get_option( 'wp_ai_bridge_recent_actions', null );

$registry = new Approved_OAuth_Clients();
$store    = new OAuth_Store();
$oauth    = new OAuth_Server( $store, $registry );
$approved = $registry->sanitize( array( $client_a, $client_b ) );
update_option( Approved_OAuth_Clients::OPTION_NAME, $approved, false );
$revision = $registry->revision();

$http_mock = static function ( $preempt, $args, $url ) use ( $client_a, $client_b, $jwks_a, $jwks_b, $redirect_a, $redirect_b, $key_a, $key_b ) {
	if ( $client_a === $url || $client_b === $url ) {
		$is_a = $client_a === $url;
		$body = array(
			'client_id'                  => $is_a ? $client_a : $client_b,
			'client_name'                => $is_a ? 'Issue 80 Client A' : 'Issue 80 Client B',
			'redirect_uris'              => array( $is_a ? $redirect_a : $redirect_b ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'private_key_jwt',
			'jwks_uri'                   => $is_a ? $jwks_a : $jwks_b,
		);
	} elseif ( $jwks_a === $url || $jwks_b === $url ) {
		$body = array( 'keys' => array( $jwks_a === $url ? $key_a['jwk'] : $key_b['jwk'] ) );
	} else {
		return $preempt;
	}
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( $body ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $http_mock, 10, 3 );

$resource     = $oauth->mcp_endpoint_url();
$issued_pairs = array();

try {
	// Normal rotation commits exactly one successor generation and consumes the old token.
	$old             = wpai_issue80_seed_refresh( $store, $user_id, $client_a, $revision, $resource );
	$first_assertion = wpai_issue80_assertion( $client_a, $oauth->token_endpoint_url(), $key_a, 'issue80-first-assertion' );
	$first           = wpai_issue80_token_request( $oauth, $client_a, $key_a, $old, $resource, array(), $first_assertion );
	wpai_issue80_assert( 200 === $first->get_status(), 'Issue #80 normal refresh rotation failed.' );
	$first_data = wpai_issue80_data( $first );
	wpai_issue80_assert( ! empty( $first_data['access_token'] ) && ! empty( $first_data['refresh_token'] ), 'Issue #80 rotation did not return a successor pair.' );
	wpai_issue80_assert( false === $store->read( OAuth_Store::TYPE_REFRESH, $old ), 'Issue #80 normal rotation did not consume the old refresh token.' );
	$issued_pairs[] = $first_data;

	$recovery = $store->read_refresh_recovery( $old );
	wpai_issue80_assert( is_array( $recovery ), 'Issue #80 did not stage committed recovery state.' );
	wpai_issue80_assert( 'committed' === ( $recovery['phase'] ?? '' ), 'Issue #80 recovery state was not committed before returning success.' );
	wpai_issue80_assert( (int) $recovery['expires_at'] > time(), 'Issue #80 recovery state was already expired.' );
	wpai_issue80_assert( (int) $recovery['expires_at'] - (int) $recovery['created_at'] <= OAuth_Server::REFRESH_RECOVERY_TTL, 'Issue #80 recovery lifetime exceeded its bounded contract.' );

	$recovery_id  = wpai_issue80_recovery_identity( $store, $old );
	$recovery_key = OAuth_Store::REFRESH_RECOVERY_PREFIX . $recovery_id;
	$raw_recovery = get_transient( $recovery_key );
	wpai_issue80_assert( is_array( $raw_recovery ) && 'aes-256-gcm' === ( $raw_recovery['cipher'] ?? '' ), 'Issue #80 recovery state is not an authenticated-encryption envelope.' );
	$raw_serialized = serialize( $raw_recovery );
	foreach ( array( $old, $first_data['access_token'], $first_data['refresh_token'], $client_a, $resource, OAuth_Server::SCOPE_OFFLINE ) as $secret_or_binding ) {
		wpai_issue80_assert( false === strpos( $raw_serialized, $secret_or_binding ), 'Issue #80 plaintext token/binding leaked into recovery metadata.' );
	}

	// Reusing the same signed client assertion fails before recovery is touched.
	$stale_auth = wpai_issue80_token_request( $oauth, $client_a, $key_a, $old, $resource, array(), $first_assertion );
	wpai_issue80_assert( wpai_issue80_error_is( $stale_auth, 'invalid_client' ), 'Issue #80 recovery accepted replayed client authentication.' );
	wpai_issue80_assert( is_array( $store->read_refresh_recovery( $old ) ), 'Failed client authentication consumed Issue #80 recovery state.' );

	// Wrong client/resource/scope cannot claim or destroy another exact recovery.
	$wrong_client = wpai_issue80_token_request( $oauth, $client_b, $key_b, $old, $resource );
	wpai_issue80_assert( wpai_issue80_error_is( $wrong_client, 'invalid_grant' ), 'Wrong approved client recovered another client refresh response.' );
	$wrong_resource = wpai_issue80_token_request( $oauth, $client_a, $key_a, $old, 'https://www.example.net/not-the-resource' );
	wpai_issue80_assert( wpai_issue80_error_is( $wrong_resource, 'invalid_target' ), 'Wrong resource recovered an Issue #80 response.' );
	$wrong_scope = wpai_issue80_token_request( $oauth, $client_a, $key_a, $old, $resource, array( 'scope' => OAuth_Server::SCOPE_MCP ) );
	wpai_issue80_assert( wpai_issue80_error_is( $wrong_scope, 'invalid_scope' ), 'Different refresh scope recovered an Issue #80 response.' );
	wpai_issue80_assert( is_array( $store->read_refresh_recovery( $old ) ), 'Binding mismatch consumed valid Issue #80 recovery state.' );

	$access_count_before  = wpai_issue80_transient_count( 'wpai_oauth_access_' );
	$refresh_count_before = wpai_issue80_transient_count( 'wpai_oauth_refresh_' );
	$recovered            = wpai_issue80_token_request( $oauth, $client_a, $key_a, $old, $resource );
	wpai_issue80_assert( 200 === $recovered->get_status(), 'Exact approved-client lost-response retry did not recover.' );
	wpai_issue80_assert( wpai_issue80_data( $recovered ) === $first_data, 'Issue #80 recovery returned a different successor generation.' );
	wpai_issue80_assert( wpai_issue80_transient_count( 'wpai_oauth_access_' ) === $access_count_before, 'Issue #80 recovery created a second access-token generation.' );
	wpai_issue80_assert( wpai_issue80_transient_count( 'wpai_oauth_refresh_' ) === $refresh_count_before, 'Issue #80 recovery created a second refresh-token generation.' );
	wpai_issue80_assert( false === $store->read_refresh_recovery( $old ), 'Issue #80 recovery allowance was not one-shot.' );
	$post_recovery_replay = wpai_issue80_token_request( $oauth, $client_a, $key_a, $old, $resource );
	wpai_issue80_assert( wpai_issue80_error_is( $post_recovery_replay, 'invalid_grant' ), 'Replay after the one-shot recovery allowance was accepted.' );

	// Expired/absent recovery state never makes the consumed old token reusable.
	$expired_old    = wpai_issue80_seed_refresh( $store, $user_id, $client_a, $revision, $resource );
	$expired_first  = wpai_issue80_token_request( $oauth, $client_a, $key_a, $expired_old, $resource );
	$expired_data   = wpai_issue80_data( $expired_first );
	$issued_pairs[] = $expired_data;
	wpai_issue80_assert( 200 === $expired_first->get_status(), 'Expiry fixture rotation failed.' );
	$expired_id = wpai_issue80_recovery_identity( $store, $expired_old );
	delete_transient( OAuth_Store::REFRESH_RECOVERY_PREFIX . $expired_id );
	wpai_issue80_assert( wpai_issue80_error_is( wpai_issue80_token_request( $oauth, $client_a, $key_a, $expired_old, $resource ), 'invalid_grant' ), 'Expired recovery state resurrected an old refresh token.' );

	// Tampered AEAD state fails closed and is removed.
	$tampered_old   = wpai_issue80_seed_refresh( $store, $user_id, $client_a, $revision, $resource );
	$tampered_first = wpai_issue80_token_request( $oauth, $client_a, $key_a, $tampered_old, $resource );
	$tampered_data  = wpai_issue80_data( $tampered_first );
	$issued_pairs[] = $tampered_data;
	wpai_issue80_assert( 200 === $tampered_first->get_status(), 'Tamper fixture rotation failed.' );
	$tampered_id  = wpai_issue80_recovery_identity( $store, $tampered_old );
	$tampered_key = OAuth_Store::REFRESH_RECOVERY_PREFIX . $tampered_id;
	$tampered     = get_transient( $tampered_key );
	wpai_issue80_assert( is_array( $tampered ) && ! empty( $tampered['ciphertext'] ), 'Tamper fixture recovery envelope is unavailable.' );
	$tampered['ciphertext'][0] = 'A' === $tampered['ciphertext'][0] ? 'B' : 'A';
	set_transient( $tampered_key, $tampered, OAuth_Server::REFRESH_RECOVERY_TTL );
	wpai_issue80_assert( wpai_issue80_error_is( wpai_issue80_token_request( $oauth, $client_a, $key_a, $tampered_old, $resource ), 'invalid_grant' ), 'Tampered recovery state did not fail closed.' );
	wpai_issue80_assert( false === get_transient( $tampered_key ), 'Tampered recovery state was retained.' );

	// Current WordPress user authorization is revalidated; failure burns recovery and successors.
	$user_old   = wpai_issue80_seed_refresh( $store, $user_id, $client_a, $revision, $resource );
	$user_first = wpai_issue80_token_request( $oauth, $client_a, $key_a, $user_old, $resource );
	$user_data  = wpai_issue80_data( $user_first );
	wpai_issue80_assert( 200 === $user_first->get_status(), 'User-revocation fixture rotation failed.' );
	$user_cap_filter = static function ( $allcaps, $caps, $args, $user ) use ( $user_id ) {
		if ( (int) $user->ID === (int) $user_id ) {
			$allcaps['read'] = false;
		}
		return $allcaps;
	};
	add_filter( 'user_has_cap', $user_cap_filter, 100, 4 );
	$user_retry = wpai_issue80_token_request( $oauth, $client_a, $key_a, $user_old, $resource );
	remove_filter( 'user_has_cap', $user_cap_filter, 100 );
	wpai_issue80_assert( wpai_issue80_error_is( $user_retry, 'invalid_grant' ), 'Revoked WordPress user authorization recovered a successor response.' );
	wpai_issue80_assert( false === $store->read( OAuth_Store::TYPE_ACCESS, $user_data['access_token'] ), 'Authorization revocation left prepared successor access live.' );
	wpai_issue80_assert( false === $store->read( OAuth_Store::TYPE_REFRESH, $user_data['refresh_token'] ), 'Authorization revocation left prepared successor refresh live.' );
	wpai_issue80_assert( wpai_issue80_error_is( wpai_issue80_token_request( $oauth, $client_a, $key_a, $user_old, $resource ), 'invalid_grant' ), 'Restored user capability resurrected canceled recovery.' );

	// Approved-client removal blocks fresh auth; re-approval/revision change invalidates the old recovery.
	$approval_old   = wpai_issue80_seed_refresh( $store, $user_id, $client_a, $revision, $resource );
	$approval_first = wpai_issue80_token_request( $oauth, $client_a, $key_a, $approval_old, $resource );
	$approval_data  = wpai_issue80_data( $approval_first );
	wpai_issue80_assert( 200 === $approval_first->get_status(), 'Approval-revision fixture rotation failed.' );
	$without_a = $registry->sanitize( array( $client_b ) );
	update_option( Approved_OAuth_Clients::OPTION_NAME, $without_a, false );
	$removed_retry = wpai_issue80_token_request( $oauth, $client_a, $key_a, $approval_old, $resource );
	wpai_issue80_assert( wpai_issue80_error_is( $removed_retry, 'invalid_client' ), 'Removed approved client reached refresh recovery.' );
	$reapproved = $registry->sanitize( array( $client_a, $client_b ) );
	update_option( Approved_OAuth_Clients::OPTION_NAME, $reapproved, false );
	$revision         = $registry->revision();
	$reapproved_retry = wpai_issue80_token_request( $oauth, $client_a, $key_a, $approval_old, $resource );
	wpai_issue80_assert( wpai_issue80_error_is( $reapproved_retry, 'invalid_grant' ), 'Re-approved client revision resurrected an old recovery.' );
	wpai_issue80_assert( false === $store->read( OAuth_Store::TYPE_ACCESS, $approval_data['access_token'] ), 'Approval revision change left prepared successor access live.' );
	wpai_issue80_assert( false === $store->read( OAuth_Store::TYPE_REFRESH, $approval_data['refresh_token'] ), 'Approval revision change left prepared successor refresh live.' );

	// Revoking either committed successor prevents recovery of a response containing it.
	$access_revoke_old   = wpai_issue80_seed_refresh( $store, $user_id, $client_a, $revision, $resource );
	$access_revoke_first = wpai_issue80_token_request( $oauth, $client_a, $key_a, $access_revoke_old, $resource );
	$access_revoke_data  = wpai_issue80_data( $access_revoke_first );
	wpai_issue80_assert( 200 === $access_revoke_first->get_status(), 'Successor-access revocation fixture rotation failed.' );
	wpai_issue80_assert( $store->revoke( $access_revoke_data['access_token'], $client_a ), 'Could not revoke committed successor access token.' );
	wpai_issue80_assert( wpai_issue80_error_is( wpai_issue80_token_request( $oauth, $client_a, $key_a, $access_revoke_old, $resource ), 'invalid_grant' ), 'Recovery returned a revoked successor access token.' );
	$issued_pairs[] = $access_revoke_data;

	$refresh_revoke_old   = wpai_issue80_seed_refresh( $store, $user_id, $client_a, $revision, $resource );
	$refresh_revoke_first = wpai_issue80_token_request( $oauth, $client_a, $key_a, $refresh_revoke_old, $resource );
	$refresh_revoke_data  = wpai_issue80_data( $refresh_revoke_first );
	wpai_issue80_assert( 200 === $refresh_revoke_first->get_status(), 'Successor-refresh revocation fixture rotation failed.' );
	wpai_issue80_assert( $store->revoke( $refresh_revoke_data['refresh_token'], $client_a ), 'Could not revoke committed successor refresh token.' );
	wpai_issue80_assert( wpai_issue80_error_is( wpai_issue80_token_request( $oauth, $client_a, $key_a, $refresh_revoke_old, $resource ), 'invalid_grant' ), 'Recovery returned a revoked successor refresh token.' );
	$issued_pairs[] = $refresh_revoke_data;

	// Simulated successor expiry/eviction is revalidated before response recovery.
	$successor_expiry_old   = wpai_issue80_seed_refresh( $store, $user_id, $client_a, $revision, $resource );
	$successor_expiry_first = wpai_issue80_token_request( $oauth, $client_a, $key_a, $successor_expiry_old, $resource );
	$successor_expiry_data  = wpai_issue80_data( $successor_expiry_first );
	wpai_issue80_assert( 200 === $successor_expiry_first->get_status(), 'Successor-expiry fixture rotation failed.' );
	wpai_issue80_delete_artifact_backing( $store, OAuth_Store::TYPE_ACCESS, $successor_expiry_data['access_token'] );
	wpai_issue80_assert( wpai_issue80_error_is( wpai_issue80_token_request( $oauth, $client_a, $key_a, $successor_expiry_old, $resource ), 'invalid_grant' ), 'Recovery returned an expired/missing successor access token.' );
	$issued_pairs[] = $successor_expiry_data;

	// OAuth recovery never writes bearer material to the bounded Activity log.
	$activity = serialize( get_option( 'wp_ai_bridge_recent_actions', array() ) );
	foreach ( $issued_pairs as $pair ) {
		foreach ( array( $pair['access_token'] ?? '', $pair['refresh_token'] ?? '' ) as $token ) {
			if ( '' !== $token ) {
				wpai_issue80_assert( false === strpos( $activity, $token ), 'Issue #80 bearer material appeared in the Activity log.' );
			}
		}
	}
} finally {
	foreach ( $issued_pairs as $pair ) {
		if ( ! empty( $pair['access_token'] ) ) {
			$store->revoke( $pair['access_token'], $client_a );
		}
		if ( ! empty( $pair['refresh_token'] ) ) {
			$store->revoke( $pair['refresh_token'], $client_a );
		}
	}
	remove_filter( 'pre_http_request', $http_mock, 10 );
	foreach ( array( $client_a, $client_b ) as $client_id ) {
		delete_transient( 'wpai_oauth_client_meta_' . substr( hash( 'sha256', $client_id ), 0, 24 ) );
		delete_transient( 'wpai_oauth_jwks_' . substr( hash( 'sha256', $client_id ), 0, 24 ) );
		delete_transient( 'wpai_oauth_jwks_refresh_' . substr( hash( 'sha256', $client_id ), 0, 24 ) );
	}
	if ( null === $original_clients ) {
		delete_option( Approved_OAuth_Clients::OPTION_NAME );
	} else {
		update_option( Approved_OAuth_Clients::OPTION_NAME, $original_clients, false );
	}
	if ( null === $original_revision ) {
		delete_option( Approved_OAuth_Clients::REVISION_OPTION );
	} else {
		update_option( Approved_OAuth_Clients::REVISION_OPTION, $original_revision, false );
	}
	if ( null === $original_activity ) {
		delete_option( 'wp_ai_bridge_recent_actions' );
	} else {
		update_option( 'wp_ai_bridge_recent_actions', $original_activity, false );
	}
	wp_set_current_user( $user_id );
}

echo "PASS: Issue #80 retry-safe rotating refresh recovery.\n";
