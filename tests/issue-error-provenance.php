<?php
/**
 * Regression for faithful, redacted Bridge and Adapter error provenance.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities {
	/** Exact first-party callback class provenance, not caller-controlled metadata. */
	final class Error_Provenance_Test_Provider {
		public function execute( $input = null ) {
			return new \WP_Error(
				'unlisted_provider_code.v2',
				'Native task rejected: invalid file state; Bearer VERY_PRIVATE_TOKEN',
				array(
					'reason'       => 'disk quota exhausted',
					'worker'       => 'php_version',
					'api_key'      => 'NEVER_EXPOSE_API_KEY',
					'inner'        => array( 'path' => '/srv/private/auth/private.txt', 'attempt' => 2 ),
				)
			);
		}
		public function permission( $input = null ) {
			return true;
		}
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	use WP_AI_Bridge\Abilities\Diagnostics_Abilities;
	use WP_AI_Bridge\Abilities\Error_Provenance_Test_Provider;
	use WP_AI_Bridge\Abilities\Site_Health_Abilities;
	use WP_AI_Bridge\Auth\OAuth_Server;
	use WP_AI_Bridge\Support\Error_Details;
	use WP_AI_Bridge\Support\Mutation_Log;
	use WP_AI_Bridge\Support\Native_Ability_Delegation;
	use WP_AI_Bridge\Support\Permissions;
	use WP_AI_Bridge\Support\Settings;

	class WP_REST_Request {
		private $params;
		public function __construct( $params ) { $this->params = $params; }
		public function get_method() { return 'POST'; }
		public function get_route() { return '/wp-ai-bridge/v1/mcp'; }
		public function get_json_params() { return $this->params; }
	}
	class WP_REST_Response {
		private $data;
		public function __construct( $data ) { $this->data = $data; }
		public function get_data() { return $this->data; }
		public function set_data( $value ) { $this->data = $value; }
	}
	class WP_AI_Test_MCP_Server {
		public function get_server_id() { return Native_Ability_Delegation::BRIDGE_MCP_SERVER_ID; }
	}

	function wp_salt( $scheme = 'auth' ) { return 'isolated-diagnostic-test-only-site-salt'; }

	$checks = 0;
	$assert = static function ( $value, $message ) use ( &$checks ) {
		++$checks;
		if ( ! $value ) { throw new \RuntimeException( $message ); }
	};
	wpai_test_reset_state();
	$authenticated_client = new \ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );
	$authenticated_client->setValue( null, 'authorized-client-A' );
	$settings   = new Settings();
	$log        = new Mutation_Log();
	$delegation = new Native_Ability_Delegation( $settings );

	$provider = new Site_Health_Abilities( new Permissions( $settings ) );
	$args = $delegation->filter_ability_args(
		array(
			'execute_callback' => array( $provider, 'execute' ),
			'permission_callback' => array( $provider, 'can_inspect' ),
		),
		'wp-ai-bridge/site-health'
	);
	$registered = new \stdClass();
	$delegation->remember_bridge_abilities( array( $registered ) );
	$GLOBALS['wpai_test']['abilities']['wp-ai-bridge/site-health'] = $registered;

	$original = ( new Error_Provenance_Test_Provider() )->execute();
	$details  = Error_Details::from_wp_error( $original );
	$assert( 'unlisted_provider_code.v2' === $details['code'], 'Unlisted original codes were normalized.' );
	$assert( str_contains( $details['message'], 'invalid file state' ), 'Non-sensitive error message was lost.' );
	$assert( ! str_contains( $details['message'], 'VERY_PRIVATE_TOKEN' ), 'Bearer token leaked.' );
	$assert( 'disk quota exhausted' === $details['data']['reason'], 'Useful error data lost.' );
	$assert( '[REDACTED]' === $details['data']['api_key'] &&
		'[REDACTED]' === $details['data']['inner']['path'], 'Sensitive fields leaked.' );
	$header_error = Error_Details::from_wp_error(
		new WP_Error( 'upstream_response', 'HTTP response header failure', array(
			'headers' => array( 'content-type' => 'application/json', 'Authorization' => 'Bearer HEADER_SECRET' ),
			'http_status' => 503,
		) )
	);
	$assert( 'application/json' === $header_error['data']['headers']['content-type'] &&
		503 === $header_error['data']['http_status'] &&
		'[REDACTED]' === $header_error['data']['headers']['Authorization'],
		'Non-sensitive header/status information was removed or sensitive Authorization leaked.' );
	// R3 B-01: free-text diagnostic assignment keys must receive the same
	// selective secret protection as structured error fields.
	$free_text_error = Error_Details::from_wp_error( new WP_Error(
		'provider_text_failure',
		'Backend rejected request; cookie_header=wordpress_logged_in=FREE_TEXT_COOKIE_SECRET; private_path=/data/tenant/text-config.php; response HTTP 403; retry after review',
		array( 'status' => 403, 'safe_hint' => 'inspect request' )
	) );
	$assert( $free_text_error['redacted'] && ! $free_text_error['truncated'] &&
		'provider_text_failure' === $free_text_error['code'] &&
		str_contains( $free_text_error['message'], 'cookie_header=[REDACTED]' ) &&
		str_contains( $free_text_error['message'], 'private_path=[REDACTED]' ) &&
		str_contains( $free_text_error['message'], 'response HTTP 403; retry after review' ) &&
		403 === $free_text_error['data']['status'] &&
		! str_contains( json_encode( $free_text_error ), 'FREE_TEXT_COOKIE_SECRET' ) &&
		! str_contains( json_encode( $free_text_error ), '/data/tenant/text-config.php' ),
		'WP_Error free-text cookie/private-path assignment escaped redaction or useful context was discarded.' );
	$free_adapter = Error_Details::from_adapter_message(
		'Adapter denied: requestCookie=session=FREE_REQUEST_COOKIE_SECRET; absoluteFilePath=/data/tenant/adapter-config.php; status 429'
	);
	$assert( null === $free_adapter['code'] && $free_adapter['redacted'] && ! $free_adapter['truncated'] &&
		str_contains( $free_adapter['message'], 'requestCookie=[REDACTED]' ) &&
		str_contains( $free_adapter['message'], 'absoluteFilePath=[REDACTED]' ) &&
		str_contains( $free_adapter['message'], 'status 429' ) &&
		! str_contains( json_encode( $free_adapter ), 'FREE_REQUEST_COOKIE_SECRET' ) &&
		! str_contains( json_encode( $free_adapter ), '/data/tenant/adapter-config.php' ),
		'Adapter free-text camelCase credential or private path leaked.' );
	$quoted_adapter = Error_Details::from_adapter_message(
		'Provider payload {"cookieHeader":"session=FREE_QUOTED_COOKIE_SECRET","privatePath":"/data/tenant/quoted.php","status":503}'
	);
	$assert( $quoted_adapter['redacted'] &&
		str_contains( $quoted_adapter['message'], '"cookieHeader":"[REDACTED]"' ) &&
		str_contains( $quoted_adapter['message'], '"privatePath":"[REDACTED]"' ) &&
		str_contains( $quoted_adapter['message'], '"status":503' ) &&
		! str_contains( $quoted_adapter['message'], 'FREE_QUOTED_COOKIE_SECRET' ) &&
		! str_contains( $quoted_adapter['message'], '/data/tenant/quoted.php' ),
		'Quoted free-text diagnostic keys were not selectively sanitized.' );
	// R4 B-01: every semicolon-separated cookie pair must be masked,
	// without consuming subsequent independent diagnostics or status text.
	$multi_cookie = Error_Details::from_wp_error( new WP_Error(
		'provider_multi_cookie_failure',
		'Backend denied; cookie_header=theme=light; wordpress_logged_in=R4_SECOND_COOKIE_SECRET; session_id_other=R4_THIRD_COOKIE_SECRET; private_path=/data/tenant/private-config.php; response HTTP 403',
		array( 'status' => 403, 'safe_context' => 'request validation' )
	) );
	$multi_json = json_encode( $multi_cookie );
	$assert( 'provider_multi_cookie_failure' === $multi_cookie['code'] &&
		$multi_cookie['redacted'] && ! $multi_cookie['truncated'] &&
		str_contains( $multi_cookie['message'], 'cookie_header=[REDACTED]' ) &&
		str_contains( $multi_cookie['message'], 'private_path=[REDACTED]' ) &&
		str_contains( $multi_cookie['message'], 'response HTTP 403' ) &&
		403 === $multi_cookie['data']['status'] &&
		'request validation' === $multi_cookie['data']['safe_context'] &&
		! str_contains( $multi_json, 'R4_SECOND_COOKIE_SECRET' ) &&
		! str_contains( $multi_json, 'R4_THIRD_COOKIE_SECRET' ) &&
		! str_contains( $multi_json, '/data/tenant/private-config.php' ),
		'Unquoted multiple-cookie WP_Error leaked a later cookie or removed safe context.' );
	$multi_adapter = Error_Details::from_adapter_message(
		'Adapter rejected: requestCookie=locale=fa;wordpress_sec_opaque=R4_ADAPTER_COOKIE_SECRET; absoluteFilePath=/data/tenant/adapter-config.php; response HTTP 429'
	);
	$assert( null === $multi_adapter['code'] && $multi_adapter['redacted'] && ! $multi_adapter['truncated'] &&
		str_contains( $multi_adapter['message'], 'requestCookie=[REDACTED]' ) &&
		str_contains( $multi_adapter['message'], 'absoluteFilePath=[REDACTED]' ) &&
		str_contains( $multi_adapter['message'], 'response HTTP 429' ) &&
		! str_contains( json_encode( $multi_adapter ), 'R4_ADAPTER_COOKIE_SECRET' ) &&
		! str_contains( json_encode( $multi_adapter ), '/data/tenant/adapter-config.php' ),
		'Adapter fallback leaked a later unquoted session cookie or lost safe status.' );
	$multi_quoted = Error_Details::from_adapter_message(
		'Provider payload {"cookieHeader":"theme=light; wordpress_logged_in=R4_QUOTED_COOKIE_SECRET","status":503}; اعتبارسنجی'
	);
	$assert( $multi_quoted['redacted'] && ! $multi_quoted['truncated'] &&
		str_contains( $multi_quoted['message'], '"cookieHeader":"[REDACTED]"' ) &&
		str_contains( $multi_quoted['message'], '"status":503' ) &&
		str_contains( $multi_quoted['message'], 'اعتبارسنجی' ) &&
		! str_contains( $multi_quoted['message'], 'R4_QUOTED_COOKIE_SECRET' ),
		'Quoted multi-cookie value, JSON-like status or Unicode handling regressed.' );
	// An independently labeled path may appear between cookie pairs in
	// free-text diagnostics. It must not open a leak for later cookie pairs.
	$interleaved = Error_Details::from_adapter_message(
		'Backend denied; cookie_header=theme=light; wordpress_logged_in=R4_FIRST_INTERLEAVED_SECRET; private_path=/data/tenant/interleaved.php; session=R4_SECOND_INTERLEAVED_SECRET; status=403; response HTTP 403'
	);
	$assert( $interleaved['redacted'] && ! $interleaved['truncated'] &&
		str_contains( $interleaved['message'], 'cookie_header=[REDACTED]' ) &&
		str_contains( $interleaved['message'], 'private_path=[REDACTED]' ) &&
		! str_contains( $interleaved['message'], 'status=403' ) &&
		str_contains( $interleaved['message'], 'response HTTP 403' ) &&
		! str_contains( json_encode( $interleaved ), 'R4_FIRST_INTERLEAVED_SECRET' ) &&
		! str_contains( json_encode( $interleaved ), 'R4_SECOND_INTERLEAVED_SECRET' ) &&
		! str_contains( json_encode( $interleaved ), '/data/tenant/interleaved.php' ),
		'Interleaved private-path diagnostic broke complete cookie redaction.' );
	// RFC cookie-octet permits apostrophes and braces, and cookie-value may
	// be empty; none may terminate redaction before a later session cookie.
	$cookie_octets = Error_Details::from_adapter_message(
		"Adapter failure; cookie_header=theme=light; empty_cookie=; wordpress_logged_in=R4_APOSTROPHE'COOKIE}SECRET; response HTTP 403"
	);
	$assert( $cookie_octets['redacted'] && ! $cookie_octets['truncated'] &&
		str_contains( $cookie_octets['message'], 'cookie_header=[REDACTED]' ) &&
		str_contains( $cookie_octets['message'], 'response HTTP 403' ) &&
		! str_contains( $cookie_octets['message'], 'R4_APOSTROPHE' ) &&
		! str_contains( $cookie_octets['message'], 'COOKIE}SECRET' ),
		'Valid cookie-octet or empty preceding cookie exposed part of a later credential.' );
	// R5 B-01: the *first* cookie pair uses RFC 6265 cookie-value grammar,
	// including ampersands and quoted first values. A subsequent session
	// cookie must not escape the complete Cookie assignment.
	$r5_cases = array(
		'ampersand-first' => 'Provider denied; cookie_header=theme=light&dark; wordpress_logged_in=R5_AMP_COOKIE_SECRET; response HTTP 403',
		'quoted-first' => 'Provider denied; cookie_header=theme="light"; wordpress_logged_in=R5_QUOTED_FIRST_COOKIE_SECRET; response HTTP 403',
		'empty-first' => 'Provider denied; requestCookie=theme=; wordpress_logged_in=R5_EMPTY_FIRST_COOKIE_SECRET; response HTTP 403',
		'whole-header-quoted' => 'Provider denied; cookieHeader:"theme=light&dark; wordpress_logged_in=R5_WHOLE_QUOTED_SECRET"; response HTTP 403',
		'json-escaped-whole' => 'Provider denied; {"cookieHeader":"theme=\\"light\\"; wordpress_logged_in=R5_ESCAPED_JSON_SECRET","status":503}; response HTTP 403',
		'single-escaped-whole' => "Provider denied; cookieHeader='theme=it\\'s; wordpress_logged_in=R5_ESCAPED_SINGLE_SECRET'; response HTTP 403",
		'escaped-first-value' => 'Provider denied; cookie_header=theme=\\"light\\"; wordpress_logged_in=R5_ESCAPED_FIRST_SECRET; response HTTP 403',
		'status-cookie' => 'Provider denied; cookie_header=theme=light; status=403; response HTTP 403',
		'opaque-legacy-value' => 'Provider denied; cookie_header=R5_OPAQUE_COOKIE_SECRET; response HTTP 403',
		'opaque-legacy-multipair' => 'Provider denied; cookie_header=R5_OPAQUE_COOKIE_SECRET&dark; wordpress_logged_in=R5_OPAQUE_TRAILING_SECRET; response HTTP 403',
	);
	foreach ( $r5_cases as $name => $message ) {
		$clean = Error_Details::from_adapter_message( $message );
		$assert( $clean['redacted'] && ! $clean['truncated'] &&
			null === $clean['code'] &&
			str_contains( $clean['message'], 'response HTTP 403' ) &&
			! str_contains( $clean['message'], 'R5_' ) &&
			! str_contains( $clean['message'], 'status=403' ),
			'R5 B-01: first Cookie grammar or ambiguous status-cookie escaped masking: ' . $name );
	}
	$r5_wp = Error_Details::from_wp_error( new WP_Error(
		'observed_cookie_pair_failure',
		'Provider denied; cookie_header=theme=light&dark; wordpress_logged_in=R5_WP_FIRST_COOKIE_SECRET; private_path=/data/tenant/r5-config.php; response HTTP 403; اعتبارسنجی',
		array( 'status' => 403, 'safe_hint' => 'inspect provider output' )
	) );
	$assert( $r5_wp['redacted'] && ! $r5_wp['truncated'] &&
		'observed_cookie_pair_failure' === $r5_wp['code'] &&
		str_contains( $r5_wp['message'], 'cookie_header=[REDACTED]' ) &&
		str_contains( $r5_wp['message'], 'private_path=[REDACTED]' ) &&
		str_contains( $r5_wp['message'], 'response HTTP 403; اعتبارسنجی' ) &&
		403 === $r5_wp['data']['status'] &&
		'inspect provider output' === $r5_wp['data']['safe_hint'] &&
		! str_contains( json_encode( $r5_wp ), 'R5_WP_FIRST_COOKIE_SECRET' ) &&
		! str_contains( json_encode( $r5_wp ), '/data/tenant/r5-config.php' ),
		'R5 B-01: WP_Error first Cookie pair leaked or lost safe code/status/Unicode.' );
	$r5_ref = $log->record_mcp_failure(
		'wp-ai-bridge/site-health', $r5_wp, 'bridge_ability',
		'ability_execution', 'inspect_provider_diagnostics', 'authorized-client-A'
	);
	$r5_saved = json_encode( $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ] );
	$r5_event = $log->diagnostic_view(
		1, $r5_ref, 'authorized-client-A', get_current_user_id()
	)['events'][0] ?? array();
	$assert( '' !== $r5_ref &&
		! str_contains( $r5_saved, 'R5_WP_FIRST_COOKIE_SECRET' ) &&
		! str_contains( json_encode( $r5_event ), 'R5_WP_FIRST_COOKIE_SECRET' ) &&
		'observed_cookie_pair_failure' === ( $r5_event['error_code'] ?? '' ) &&
		str_contains( $r5_event['message'] ?? '', 'response HTTP 403' ),
		'R5 B-01: persisted/authorized diagnostic leaked a later cookie.' );
	// Exercise every RFC 6265 cookie-octet at the first-pair position and
	// prevent a valid punctuation character from ending the redaction early.
	$r5_octets_safe = true;
	foreach ( range( 0x21, 0x7e ) as $byte ) {
		if ( 0x22 === $byte || 0x2c === $byte || 0x3b === $byte || 0x5c === $byte ) {
			continue;
		}
		$marker   = 'R5_OCTET_' . $byte . '_SECRET';
		$pair     = 'cookie_header=theme=pre' . chr( $byte ) . 'post; wordpress_logged_in=' . $marker;
		$clean    = Error_Details::from_adapter_message( 'Provider denied; ' . $pair . '; response HTTP 403' );
		if ( ! $clean['redacted'] || $clean['truncated'] ||
			str_contains( $clean['message'], $marker ) ||
			! str_contains( $clean['message'], 'response HTTP 403' ) ) {
			$r5_octets_safe = false;
			break;
		}
	}
	$assert( $r5_octets_safe, 'R5 B-01: RFC 6265 first-pair cookie-octet terminated masking.' );

	// R6 B-01: a leading apostrophe is an ordinary RFC cookie-octet.
	// A later quote in that same value must never stop masking before the
	// remaining Cookie pairs, even when "status" is a Cookie name.
	$r6_adapter_cases = array(
		'leading-apostrophe' => "Provider denied; cookie_header=theme='foo'bar; wordpress_logged_in=R6_ADAPTER_COOKIE_SECRET; status=403; response HTTP 403",
		'multiple-apostrophes' => "Provider denied; requestCookie=theme='foo'bar'baz; session=R6_ADAPTER_SESSION_SECRET; response HTTP 403",
		'opaque-double-quote' => 'Provider denied; cookie_header=theme="foo"bar; wordpress_logged_in=R6_OPAQUE_DOUBLE_SECRET; response HTTP 403',
		'opaque-whole-single' => "Provider denied; cookie_header='theme='foo'bar; wordpress_logged_in=R6_OPAQUE_WHOLE_SECRET'; response HTTP 403",
		'quoted-prefix-brace' => 'Provider denied; cookie_header=theme="foo"}bar; session=R6_BRACE_SECRET; response HTTP 403',
		'quoted-prefix-comma' => 'Provider denied; cookie_header=theme="foo",bar; session=R6_COMMA_SECRET; response HTTP 403',
		'valid-leading-apostrophe-name' => "Provider denied; cookie_header='theme=foo'; wordpress_logged_in=R6_COOKIE_NAME_SECRET; response HTTP 403",
		'opaque-whole-double-trailing-cookie' => 'Provider denied; cookie_header="theme=foo"; wordpress_logged_in=R6_DOUBLE_FIELD_SECRET; response HTTP 403',
	);
	foreach ( $r6_adapter_cases as $name => $message ) {
		$clean = Error_Details::from_adapter_message( $message );
		$assert( null === $clean['code'] && $clean['redacted'] && ! $clean['truncated'] &&
			str_contains( $clean['message'], 'response HTTP 403' ) &&
			! str_contains( $clean['message'], 'R6_' ) &&
			! str_contains( $clean['message'], 'status=403' ),
			'R6 B-01: a first Cookie value beginning with an apostrophe exposed later cookies: ' . $name );
	}
	$r6_wp = Error_Details::from_wp_error( new WP_Error(
		'r6_original_provider_code',
		"Provider denied; cookie_header=theme='foo'bar; wordpress_logged_in=R6_WP_COOKIE_SECRET; session=R6_WP_SESSION_SECRET; status=403; private_path=/data/tenant/r6-config.php; response HTTP 403; اعتبارسنجی",
		array( 'http_status' => 403, 'safe_hint' => 'inspect provider message' )
	) );
	$assert( 'r6_original_provider_code' === $r6_wp['code'] && $r6_wp['redacted'] && ! $r6_wp['truncated'] &&
		str_contains( $r6_wp['message'], 'cookie_header=[REDACTED]' ) &&
		str_contains( $r6_wp['message'], 'private_path=[REDACTED]' ) &&
		str_contains( $r6_wp['message'], 'response HTTP 403; اعتبارسنجی' ) &&
		! str_contains( $r6_wp['message'], 'status=403' ) &&
		403 === $r6_wp['data']['http_status'] &&
		'inspect provider message' === $r6_wp['data']['safe_hint'] &&
		! str_contains( json_encode( $r6_wp ), 'R6_' ) &&
		! str_contains( json_encode( $r6_wp ), '/data/tenant/r6-config.php' ),
		'R6 B-01: WP_Error apostrophe-first Cookie leaked or lost provenance and safe context.' );
	$r6_ref = $log->record_mcp_failure(
		'wp-ai-bridge/site-health', $r6_wp, 'bridge_ability',
		'ability_execution', 'inspect_provider_diagnostics', 'authorized-client-A'
	);
	$r6_stored = json_encode( $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ] );
	$r6_event  = $log->diagnostic_view(
		1, $r6_ref, 'authorized-client-A', get_current_user_id()
	)['events'][0] ?? array();
	$assert( '' !== $r6_ref &&
		! str_contains( $r6_stored, 'R6_WP_COOKIE_SECRET' ) &&
		! str_contains( $r6_stored, 'R6_WP_SESSION_SECRET' ) &&
		! str_contains( json_encode( $r6_event ), 'R6_WP_COOKIE_SECRET' ) &&
		! str_contains( json_encode( $r6_event ), 'R6_WP_SESSION_SECRET' ) &&
		'r6_original_provider_code' === ( $r6_event['error_code'] ?? '' ) &&
		str_contains( $r6_event['message'] ?? '', 'response HTTP 403' ),
		'R6 B-01: Mutation_Log or authorized diagnostic lookup disclosed a later Cookie.' );

	$multi_ref = $log->record_mcp_failure(
		'wp-ai-bridge/site-health', $multi_cookie, 'bridge_ability', 'ability_execution',
		'inspect_provider_diagnostics', 'authorized-client-A'
	);
	$multi_log = json_encode( $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ] );
	$multi_event = $log->diagnostic_view(
		1, $multi_ref, 'authorized-client-A', get_current_user_id()
	)['events'][0] ?? array();
	$assert( '' !== $multi_ref &&
		! str_contains( $multi_log, 'R4_SECOND_COOKIE_SECRET' ) &&
		! str_contains( $multi_log, 'R4_THIRD_COOKIE_SECRET' ) &&
		! str_contains( json_encode( $multi_event ), 'R4_SECOND_COOKIE_SECRET' ) &&
		! str_contains( json_encode( $multi_event ), 'R4_THIRD_COOKIE_SECRET' ) &&
		str_contains( $multi_event['message'] ?? '', 'response HTTP 403' ) &&
		'provider_multi_cookie_failure' === ( $multi_event['error_code'] ?? '' ),
		'Shared Mutation_Log or authorized diagnostic lookup leaked later cookie pairs.' );

	$free_ref = $log->record_mcp_failure(
		'wp-ai-bridge/site-health', $free_text_error, 'bridge_ability',
		'ability_execution', 'inspect_provider_diagnostics', 'authorized-client-A'
	);
	$free_log = json_encode( $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ] );
	$free_view = $log->diagnostic_view(
		1, $free_ref, 'authorized-client-A', get_current_user_id()
	)['events'][0] ?? array();
	$assert( '' !== $free_ref &&
		! str_contains( $free_log, 'FREE_TEXT_COOKIE_SECRET' ) &&
		! str_contains( $free_log, '/data/tenant/text-config.php' ) &&
		! str_contains( json_encode( $free_view ), 'FREE_TEXT_COOKIE_SECRET' ) &&
		! str_contains( json_encode( $free_view ), '/data/tenant/text-config.php' ) &&
		str_contains( $free_view['message'] ?? '', 'response HTTP 403' ) &&
		'provider_text_failure' === ( $free_view['error_code'] ?? '' ),
		'Persisted or authorized diagnostic free-text fields leaked secret/path information.' );




	// R-01: diagnostic-specific structured keys must not leak opaque cookies
	// or private paths outside the free-text directory-prefix heuristics.
	$private_data = array(
		'private_path' => '/data/tenant/config.php',
		'cookie_header' => 'wordpress_logged_in=TEST_OPAQUE_COOKIE_SECRET',
		'token' => 'TEST_OPAQUE_TOKEN_SECRET',
		'nonce' => 'TEST_WORDPRESS_NONCE_SECRET',
		'token_count' => 7,
		'status' => 409,
		'public_hint' => 'retry only after reviewing the request',
		'nested' => (object) array(
			'privatePath' => '/data/tenant/nested.php',
			'requestCookie' => 'session=TEST_NESTED_COOKIE_SECRET',
			'attempt' => 2,
			'array' => array( 'absoluteFilePath' => '/data/tenant/config.local.php', 'ok' => true ),
		),
	);
	$structured = Error_Details::from_wp_error( new WP_Error( 'custom_provider_denial', 'Validation failed at stage 2', $private_data ) );
	$structured_json = json_encode( $structured );
	$assert( $structured['redacted'] && ! $structured['truncated'] &&
		'custom_provider_denial' === $structured['code'] && 'Validation failed at stage 2' === $structured['message'] &&
		'[REDACTED]' === ( $structured['data']['private_path'] ?? null ) &&
		'[REDACTED]' === ( $structured['data']['cookie_header'] ?? null ) &&
		'[REDACTED]' === ( $structured['data']['token'] ?? null ) &&
		'[REDACTED]' === ( $structured['data']['nonce'] ?? null ) &&
		7 === ( $structured['data']['token_count'] ?? null ) &&
		$structured['data']['nested'] instanceof \stdClass &&
		'[REDACTED]' === ( $structured['data']['nested']->privatePath ?? null ) &&
		'[REDACTED]' === ( $structured['data']['nested']->requestCookie ?? null ) &&
		'[REDACTED]' === ( $structured['data']['nested']->array['absoluteFilePath'] ?? null ) &&
		409 === ( $structured['data']['status'] ?? null ) &&
		2 === ( $structured['data']['nested']->attempt ?? null ) &&
		true === ( $structured['data']['nested']->array['ok'] ?? null ) &&
		false !== strpos( $structured_json, 'retry only after reviewing the request' ) &&
		false === strpos( $structured_json, 'TEST_OPAQUE_COOKIE_SECRET' ) &&
		false === strpos( $structured_json, 'TEST_OPAQUE_TOKEN_SECRET' ) &&
		false === strpos( $structured_json, 'TEST_WORDPRESS_NONCE_SECRET' ) &&
		false === strpos( $structured_json, 'TEST_NESTED_COOKIE_SECRET' ) &&
		false === strpos( $structured_json, '/data/tenant/' ),
		'Structured diagnostic secret-key variants leaked or safe context was lost.' );
	$structured_ref = $log->record_mcp_failure(
		'wp-ai-bridge/site-health', $structured, 'bridge_ability', 'ability_execution',
		'inspect_provider_diagnostics', 'authorized-client-A'
	);
	$persisted_json = json_encode( $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ] ?? array() );
	$persisted_event = $log->diagnostic_view( 1, $structured_ref, 'authorized-client-A', get_current_user_id() )['events'][0] ?? array();
	$assert( '' !== $structured_ref && ! str_contains( $persisted_json, 'TEST_OPAQUE_COOKIE_SECRET' ) &&
		! str_contains( $persisted_json, 'TEST_NESTED_COOKIE_SECRET' ) &&
		! str_contains( $persisted_json, 'TEST_OPAQUE_TOKEN_SECRET' ) &&
		! str_contains( $persisted_json, 'TEST_WORDPRESS_NONCE_SECRET' ) &&
		! str_contains( $persisted_json, '/data/tenant/' ) &&
		'[REDACTED]' === ( $persisted_event['data']['private_path'] ?? null ) &&
		'[REDACTED]' === ( $persisted_event['data']['cookie_header'] ?? null ) &&
		409 === ( $persisted_event['data']['status'] ?? null ),
		'Scoped Mutation_Log retained or returned sensitive structured data.' );

	// R-02: MAX_DATA is a bound on final JSON bytes, not only string payloads.
	$huge_numbers = array_fill( 0, 100, array_fill( 0, 100, 1.2345678901234567e+300 ) );
	$numeric = Error_Details::from_wp_error( new WP_Error( 'large_numeric_data', 'Numeric provider data', $huge_numbers ) );
	$numeric_json = json_encode( $numeric['data'] );
	$assert( false !== $numeric_json && strlen( $numeric_json ) <= Error_Details::MAX_DATA &&
		true === $numeric['truncated'] && false === $numeric['redacted'] &&
		is_float( $numeric['data'][0][0] ?? null ) &&
		1.2345678901234567e+300 === ( $numeric['data'][0][0] ?? null ),
		'Nested numeric data bypassed the global JSON byte budget or changed valid scalar types.' );
	$odd_numbers = Error_Details::from_wp_error( new WP_Error(
		'non_finite', 'Provider returned unrepresentable numbers',
		array( 'finite' => 12.25, 'integer' => 42, 'boolean' => false, 'nil' => null, 'nan' => NAN, 'positive_inf' => INF, 'negative_inf' => -INF )
	) );
	$odd_json = json_encode( $odd_numbers['data'] );
	$assert( false !== $odd_json && $odd_numbers['truncated'] && ! $odd_numbers['redacted'] &&
		12.25 === ( $odd_numbers['data']['finite'] ?? null ) &&
		42 === ( $odd_numbers['data']['integer'] ?? null ) &&
		false === ( $odd_numbers['data']['boolean'] ?? null ) &&
		array_key_exists( 'nil', $odd_numbers['data'] ) &&
		'[NON_FINITE_NUMBER]' === ( $odd_numbers['data']['nan'] ?? null ) &&
		'[NON_FINITE_NUMBER]' === ( $odd_numbers['data']['positive_inf'] ?? null ) &&
		'[NON_FINITE_NUMBER]' === ( $odd_numbers['data']['negative_inf'] ?? null ),
		'Non-finite floats made JSON invalid or valid numeric types were lost.' );
	// R3 R-02: an originally non-list numeric map must never become a JSON
	// array merely because MAX_ITEMS or MAX_DATA drops its sparse tail.
	$sparse_map = array_fill( 0, 100, 7 );
	$sparse_map[101] = 9;
	$assert( '{' === substr( json_encode( $sparse_map ), 0, 1 ), 'Sparse-map test fixture must be a JSON object.' );
	$sparse_result = Error_Details::from_wp_error( new WP_Error( 'sparse_map', 'Safe numeric map', $sparse_map ) );
	$sparse_json = json_encode( $sparse_result['data'] );
	$sparse_decoded = json_decode( $sparse_json );
	$assert( $sparse_result['truncated'] && ! $sparse_result['redacted'] &&
		false !== $sparse_json && strlen( $sparse_json ) <= Error_Details::MAX_DATA &&
		$sparse_decoded instanceof \stdClass &&
		100 === count( (array) $sparse_decoded ) &&
		7 === ( $sparse_decoded->{'0'} ?? null ) &&
		7 === ( $sparse_decoded->{'99'} ?? null ) &&
		! isset( $sparse_decoded->{'101'} ),
		'MAX_ITEMS truncation converted the original sparse JSON object to an array.' );
	$budget_sparse = array(
		'pad' => array_fill( 0, 4, str_repeat( 'p', 8182 ) ),
		'map' => array( 0 => 7, 101 => 8 ),
	);
	$budget_result = Error_Details::from_wp_error( new WP_Error( 'budget_map', 'Safe map', $budget_sparse ) );
	$budget_decoded = json_decode( json_encode( $budget_result['data'] ) );
	$assert( $budget_result['truncated'] &&
		false !== json_encode( $budget_result['data'] ) &&
		strlen( json_encode( $budget_result['data'] ) ) <= Error_Details::MAX_DATA &&
		$budget_decoded instanceof \stdClass &&
		( $budget_decoded->map ?? null ) instanceof \stdClass &&
		7 === ( $budget_decoded->map->{'0'} ?? null ) &&
		! isset( $budget_decoded->map->{'101'} ),
		'Byte-budget truncation converted a sparse nested JSON map into a list.' );
	$empty_sparse = array(
		'pad' => array_fill( 0, 4, str_repeat( 'p', 8184 ) ),
		'map' => array( 0 => 7, 101 => 8 ),
	);
	$empty_result = Error_Details::from_wp_error( new WP_Error( 'empty_sparse', 'Safe map', $empty_sparse ) );
	$empty_json = json_encode( $empty_result['data'] );
	$empty_decoded = json_decode( $empty_json );
	$assert( $empty_result['truncated'] && false !== $empty_json &&
		strlen( $empty_json ) <= Error_Details::MAX_DATA &&
		$empty_decoded instanceof \stdClass &&
		( $empty_decoded->map ?? null ) instanceof \stdClass &&
		0 === count( (array) $empty_decoded->map ),
		'An empty truncated JSON object was encoded as an array.' );
	$nested_shape = Error_Details::from_wp_error( new WP_Error(
		'nested_shapes', 'Safe nested map', (object) array(
			'map' => $sparse_map,
			'list' => array( 1, 2, 3 ),
			'empty_object' => new \stdClass(),
			'status' => 422,
		)
	) );
	$assert( $nested_shape['truncated'] &&
		$nested_shape['data'] instanceof \stdClass &&
		$nested_shape['data']->map instanceof \stdClass &&
		is_array( $nested_shape['data']->list ) &&
		'[1,2,3]' === json_encode( $nested_shape['data']->list ) &&
		$nested_shape['data']->empty_object instanceof \stdClass &&
		422 === $nested_shape['data']->status &&
		false !== json_encode( $nested_shape['data'] ) &&
		strlen( json_encode( $nested_shape['data'] ) ) <= Error_Details::MAX_DATA,
		'Nested stdClass/object/list identities or finite numeric values were corrupted.' );
	$untouched_map = Error_Details::from_wp_error( new WP_Error(
		'small_assoc', 'Safe small map', array( 'status' => 503, 'attempt' => 2 )
	) );
	$assert( is_array( $untouched_map['data'] ) && ! $untouched_map['truncated'] &&
		503 === $untouched_map['data']['status'] && 2 === $untouched_map['data']['attempt'],
		'An intact associative PHP array was unnecessarily converted or altered.' );



	$only_values = Error_Details::from_adapter_message(
		'upstream rejected: Authorization: Bearer TOKEN_PRIVATE_X; response HTTP 403; api_key=SECRET_VALUE'
	);
	$assert( $only_values['redacted'] &&
		str_contains( $only_values['message'], 'Authorization: Bearer [REDACTED]' ) &&
		str_contains( $only_values['message'], 'response HTTP 403' ) &&
		str_contains( $only_values['message'], 'api_key=[REDACTED]' ) &&
		! str_contains( $only_values['message'], 'TOKEN_PRIVATE_X' ) &&
		! str_contains( $only_values['message'], 'SECRET_VALUE' ),
		'Credential redaction hid error context or leaked a token value.' );

	$control = Error_Details::from_adapter_message( "Disk quota exceeded\x00; retry after cleanup" );
	$assert( true === $control['redacted'] && false === $control['truncated'] &&
		'Disk quota exceeded; retry after cleanup' === $control['message'],
		'Control-byte removal was not reported or modified ordinary error context.' );
	$normal = Error_Details::from_wp_error(
		new WP_Error( 'resource_unavailable', 'Operation failed: timeout at step 3',
			(object) array( 'status' => 503, 'nested' => (object) array(), 'list' => array() ) )
	);
	$assert( 'resource_unavailable' === $normal['code'] && ! $normal['redacted'] &&
		! $normal['truncated'] && 'Operation failed: timeout at step 3' === $normal['message'] &&
		$normal['data'] instanceof \stdClass && $normal['data']->nested instanceof \stdClass &&
		'{"status":503,"nested":{},"list":[]}' === json_encode( $normal['data'] ),
		'Clean text and JSON object/array semantics were modified.' );
	$uri = Error_Details::from_adapter_message(
		'GET https://user:INTERNAL_URI_CREDENTIAL@backend.example/api failed; path=/var/private/file.log'
	);
	$assert( $uri['redacted'] && ! str_contains( json_encode( $uri ), 'INTERNAL_URI_CREDENTIAL' ) &&
		! str_contains( json_encode( $uri ), '/var/private/file.log' ),
		'URI userinfo/private path redaction failed.' );
	$long = Error_Details::from_adapter_message( str_repeat( 'x', Error_Details::MAX_MESSAGE + 50 ) );
	$assert( $long['truncated'] && ! $long['redacted'] &&
		str_contains( $long['message'], '[TRUNCATED]' ), 'Truncation was not disclosed.' );

	$params = array(
		'jsonrpc' => '2.0',
		'id' => 17,
		'method' => 'tools/call',
		'params' => array(
			'name' => 'mcp-adapter-execute-ability',
			'arguments' => array( 'ability_name' => 'wp-ai-bridge/site-health', 'parameters' => array() ),
		),
	);
	$route = $delegation->filter_rest_endpoints(
		array(
			'/wp-ai-bridge/v1/mcp' => array(
				array( 'callback' => static function () use ( $args, $params ) {
					$raw = $args['execute_callback']( array() );
					return new WP_REST_Response(
						array(
							'jsonrpc' => '2.0', 'id' => $params['id'],
							'result' => array(
								'isError' => true,
								'content' => array( array( 'type' => 'text', 'text' => $raw->get_error_message() ) ),
							),
						)
					);
				} ),
			),
		)
	);
	$invoke = $route['/wp-ai-bridge/v1/mcp'][0]['callback'];
	$result = $invoke( new WP_REST_Request( $params ) )->get_data()['result'];
	$error  = $result['structuredContent']['error'] ?? array();
	$assert( true === $result['isError'] && 'site_health_denied' === ( $error['code'] ?? null ),
		'First-party WP_Error code lost at Adapter projection.' );
	$assert( str_contains( $result['content'][0]['text'] ?? '', 'Site Configuration' ) &&
		! str_contains( json_encode( $result ), 'VERY_PRIVATE_TOKEN' ) &&
		! str_contains( json_encode( $result ), 'NEVER_EXPOSE_API_KEY' ),
		'Response did not preserve useful text or leaked secret.' );
	$assert( false === $error['redacted'] && false === $error['truncated'] &&
		true === $error['code_available'] && true === $error['diagnostics_persisted'],
		'Redaction, source-code and persistence truth missing.' );

	$ref = $error['diagnostic_reference'] ?? '';
	$diagnostics = new Diagnostics_Abilities( new Permissions( $settings ), $log );
	$diagnostics->register();
	$view  = $diagnostics->execute( array( 'reference' => $ref, 'limit' => 5 ) );
	$entry = $view['events'][0] ?? array();
	$assert( 1 === $view['returned'] && $entry['event_ref'] === $ref &&
		'site_health_denied' === $entry['error_code'], 'Diagnostic reference lookup lost exact code.' );
	$assert( str_contains( $entry['message'] ?? '', 'Site Configuration' ) &&
		! str_contains( json_encode( $view ), 'VERY_PRIVATE_TOKEN' ) &&
		null === ( $entry['data'] ?? null ),
		'Privileged diagnostics lost useful safe detail.' );
	// Treat stored diagnostic records as untrusted: a poisoned credential
	// fragment in an option entry must not leak through reference lookup.
	$stored = $GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ];
	$GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ][0]['error_code'] = 'provider_token=TOP_SECRET_DATA';
	$poisoned = $diagnostics->execute( array( 'reference' => $ref ) );
	$assert( 1 === $poisoned['returned'] && ! str_contains( json_encode( $poisoned ), 'TOP_SECRET_DATA' ) &&
		str_contains( json_encode( $poisoned ), '[REDACTED]' ),
		'Poisoned persisted error code exposed secret through privileged lookup.' );
	$GLOBALS['wpai_test']['options'][ Mutation_Log::OPTION_NAME ] = $stored;

	$authenticated_client->setValue( null, 'other-client-B' );
	$assert( 0 === $diagnostics->execute( array( 'reference' => $ref ) )['returned'],
		'Other OAuth client enumerated a diagnostic reference.' );
	$authenticated_client->setValue( null, 'authorized-client-A' );
	$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
	$assert( is_wp_error( $diagnostics->execute( array( 'reference' => $ref ) ) ),
		'Non-admin can read diagnostics.' );
	$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
	$assert( is_wp_error( $diagnostics->execute( array( 'reference' => '../secret' ) ) ),
		'Unbounded/path diagnostic reference accepted.' );

	// WordPress 7.1 native hook observes the exact WP_Error before Adapter loss.
	$native_name = 'core/get-environment-info';
	$native     = new WP_Native_Builder_Test_Ability( $native_name );
	$GLOBALS['wpai_test']['abilities'][ $native_name ] = $native;
	$native_error = new WP_Error( 'provider_custom_failure.v3', 'Native environment check: wrong type', array( 'status' => 422 ) );
	$native_args = $params;
	$native_args['id'] = 18;
	$native_args['params']['arguments']['ability_name'] = $native_name;
	$native_route = $delegation->filter_rest_endpoints(
		array(
			'/wp-ai-bridge/v1/mcp' => array(
				array( 'callback' => static function () use ( $delegation, $native, $native_error, $native_name, $native_args ) {
					$delegation->observe_ability_execute_result( $native_error, $native_name, array(), $native );
					$filtered = $delegation->filter_tool_call_result(
						array( 'success' => false, 'error' => $native_error->get_error_message() ),
						array( 'ability_name' => $native_name ),
						Native_Ability_Delegation::ADAPTER_EXECUTE_TOOL,
						new \stdClass(), new WP_AI_Test_MCP_Server()
					);
					return new WP_REST_Response(
						array( 'jsonrpc' => '2.0', 'id' => 18, 'result' => array(
							'isError' => true,
							'content' => array( array( 'type' => 'text', 'text' => $filtered['error'] ) ),
						) )
					);
				} ),
			),
		)
	);
	$observed = $native_route['/wp-ai-bridge/v1/mcp'][0]['callback']( new WP_REST_Request( $native_args ) )
		->get_data()['result']['structuredContent']['error'] ?? array();
	$assert( 'provider_custom_failure.v3' === ( $observed['code'] ?? null ) &&
		'native_ability' === ( $observed['origin'] ?? null ) &&
		422 === ( $observed['data']['status'] ?? null ), 'WordPress 7.1 hook lost native code/data.' );
	// R-02: ensure global numeric data caps persist through the projected MCP
	// reply and same-client diagnostic lookup, not just standalone sanitizing.
	$numeric_args = $native_args;
	$numeric_args['id'] = 24;
	$numeric_error = new WP_Error( 'large_numeric_data', 'Numeric provider data', $huge_numbers );
	$numeric_route = $delegation->filter_rest_endpoints( array(
		'/wp-ai-bridge/v1/mcp' => array(
			array( 'callback' => static function () use ( $delegation, $native, $native_name, $numeric_error ) {
				$delegation->observe_ability_execute_result( $numeric_error, $native_name, array(), $native );
				$filtered = $delegation->filter_tool_call_result(
					array( 'success' => false, 'error' => $numeric_error->get_error_message() ),
					array( 'ability_name' => $native_name ),
					Native_Ability_Delegation::ADAPTER_EXECUTE_TOOL,
					new \stdClass(), new WP_AI_Test_MCP_Server()
				);
				return new WP_REST_Response( array(
					'jsonrpc' => '2.0', 'id' => 24,
					'result' => array( 'isError' => true, 'content' => array(
						array( 'type' => 'text', 'text' => $filtered['error'] ),
					) ),
				) );
			} ),
		),
	) );
	$numeric_wire = $numeric_route['/wp-ai-bridge/v1/mcp'][0]['callback']( new WP_REST_Request( $numeric_args ) )->get_data();
	$wire_error = $numeric_wire['result']['structuredContent']['error'] ?? array();
	$wire_data = json_encode( $wire_error['data'] ?? null );
	$wire_ref = $wire_error['diagnostic_reference'] ?? '';
	$stored_numeric = $log->diagnostic_view( 1, $wire_ref, 'authorized-client-A', get_current_user_id() )['events'][0] ?? array();
	$stored_numeric_data = json_encode( $stored_numeric['data'] ?? null );
	$assert( true === ( $numeric_wire['result']['isError'] ?? false ) &&
		'large_numeric_data' === ( $wire_error['code'] ?? null ) &&
		true === ( $wire_error['truncated'] ?? null ) &&
		true === ( $wire_error['diagnostics_persisted'] ?? null ) &&
		is_float( $wire_error['data'][0][0] ?? null ) &&
		false !== $wire_data && strlen( $wire_data ) <= Error_Details::MAX_DATA &&
		false !== $stored_numeric_data && strlen( $stored_numeric_data ) <= Error_Details::MAX_DATA &&
		true === ( $stored_numeric['truncated'] ?? false ) &&
		$wire_ref === ( $stored_numeric['event_ref'] ?? null ) &&
		false !== json_encode( $numeric_wire ),
		'Numeric error bypassed JSON byte budget or corrupted MCP/Mutation_Log persistence.' );
	// R3 R-02: preserve numeric-map JSON identity through MCP structured
	// projection, persisted Mutation_Log and same-client diagnostic lookup.
	$sparse_args = $native_args;
	$sparse_args['id'] = 25;
	$sparse_error = new WP_Error( 'sparse_map', 'Native sparse numeric map', $sparse_map );
	$sparse_route = $delegation->filter_rest_endpoints( array(
		'/wp-ai-bridge/v1/mcp' => array(
			array( 'callback' => static function () use ( $delegation, $native, $native_name, $sparse_error ) {
				$delegation->observe_ability_execute_result( $sparse_error, $native_name, array(), $native );
				$filtered = $delegation->filter_tool_call_result(
					array( 'success' => false, 'error' => $sparse_error->get_error_message() ),
					array( 'ability_name' => $native_name ),
					Native_Ability_Delegation::ADAPTER_EXECUTE_TOOL,
					new \stdClass(), new WP_AI_Test_MCP_Server()
				);
				return new WP_REST_Response( array(
					'jsonrpc' => '2.0', 'id' => 25,
					'result' => array( 'isError' => true, 'content' => array(
						array( 'type' => 'text', 'text' => $filtered['error'] ),
					) ),
				) );
			} ),
		),
	) );
	$sparse_wire = $sparse_route['/wp-ai-bridge/v1/mcp'][0]['callback']( new WP_REST_Request( $sparse_args ) )->get_data();
	$sparse_wire_error = $sparse_wire['result']['structuredContent']['error'] ?? array();
	$sparse_wire_data = json_encode( $sparse_wire_error['data'] ?? null );
	$sparse_ref = $sparse_wire_error['diagnostic_reference'] ?? '';
	$stored_sparse = $log->diagnostic_view( 1, $sparse_ref, 'authorized-client-A', get_current_user_id() )['events'][0] ?? array();
	$stored_sparse_data = json_encode( $stored_sparse['data'] ?? null );
	$assert( true === ( $sparse_wire['result']['isError'] ?? false ) &&
		'sparse_map' === ( $sparse_wire_error['code'] ?? null ) &&
		! empty( $sparse_wire_error['truncated'] ) &&
		! empty( $sparse_wire_error['diagnostics_persisted'] ) &&
		is_string( $sparse_ref ) && '' !== $sparse_ref &&
		false !== $sparse_wire_data && '{' === substr( $sparse_wire_data, 0, 1 ) &&
		strlen( $sparse_wire_data ) <= Error_Details::MAX_DATA &&
		false !== $stored_sparse_data && '{' === substr( $stored_sparse_data, 0, 1 ) &&
		strlen( $stored_sparse_data ) <= Error_Details::MAX_DATA &&
		$sparse_ref === ( $stored_sparse['event_ref'] ?? '' ) &&
		false !== json_encode( $sparse_wire ),
		'Sparse map object/list identity was lost across MCP projection or persisted diagnostics.' );



	// Supported older runtime: Adapter has *already* stripped the WP_Error code.
	$legacy_params = $native_args;
	$legacy_params['id'] = 19;
	$legacy_route = $delegation->filter_rest_endpoints(
		array(
			'/wp-ai-bridge/v1/mcp' => array(
				array( 'callback' => static function () use ( $delegation, $native_name ) {
					$filtered = $delegation->filter_tool_call_result(
						array( 'success' => false, 'error' => 'Unsupported provider state: token=DO_NOT_LEAK' ),
						array( 'ability_name' => $native_name ),
						Native_Ability_Delegation::ADAPTER_EXECUTE_TOOL,
						new \stdClass(), new WP_AI_Test_MCP_Server()
					);
					return new WP_REST_Response(
						array( 'jsonrpc' => '2.0', 'id' => 19, 'result' => array(
							'isError' => true,
							'content' => array( array( 'type' => 'text', 'text' => $filtered['error'] ) ),
						) )
					);
				} ),
			),
		)
	);
	$legacy_response = $legacy_route['/wp-ai-bridge/v1/mcp'][0]['callback']( new WP_REST_Request( $legacy_params ) )
		->get_data()['result'];
	$legacy_error = $legacy_response['structuredContent']['error'] ?? array();
	$assert( null === ( $legacy_error['code'] ?? null ) && false === $legacy_error['code_available'] &&
		'adapter_boundary' === $legacy_error['origin'], 'Adapter-stripped code was falsely reconstructed.' );
	$assert( str_contains( $legacy_error['message'], 'Unsupported provider state' ) &&
		! str_contains( json_encode( $legacy_response ), 'DO_NOT_LEAK' ),
		'Adapter fallback discarded useful message or leaked secret.' );

	// Adapter check_permission() may deny before the provider callback runs.
	// Never attribute its identity/capability/exposure checks to native_ability.
	$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_NATIVE_ABILITIES ] = 1;
	$adapter_cases = array(
		array( 'code' => 'insufficient_capability', 'source' => 'observed_at_adapter' ),
		array( 'code' => 'ability_not_found', 'source' => 'observed_at_adapter' ),
		array( 'code' => null, 'source' => 'unavailable' ),
		array( 'code' => 'wp_ai_bridge_adapter_permission_exception', 'source' => 'bridge_generated' ),
	);
	foreach ( $adapter_cases as $idx => $case ) {
		$outer = $delegation->filter_ability_args(
			array( 'permission_callback' => static function () use ( $idx, $case ) {
				if ( 3 === $idx ) {
					throw new \RuntimeException( 'Adapter permission execution crashed; token=SECRET_DO_NOT_LEAK' );
				}
				return 2 === $idx ? false :
					new WP_Error( $case['code'], 'Adapter denied the operation; client_secret=SECRET_DO_NOT_LEAK' );
			} ),
			Native_Ability_Delegation::ADAPTER_EXECUTE_ABILITY
		);
		$case_request = $native_args;
		$case_request['id'] = 30 + $idx;
		$case_route = $delegation->filter_rest_endpoints(
			array( '/wp-ai-bridge/v1/mcp' => array(
				array( 'callback' => static function () use ( $outer, $native_name, $case_request ) {
					$permission_result = $outer['permission_callback']( array( 'ability_name' => $native_name ) );
					$message = is_wp_error( $permission_result ) ?
						$permission_result->get_error_message() : 'Adapter returned a boolean denial.';
					return new WP_REST_Response( array(
						'jsonrpc' => '2.0', 'id' => $case_request['id'],
						'result' => array( 'isError' => true, 'content' => array(
							array( 'type' => 'text', 'text' => $message ),
						) ),
					) );
				} ),
			) )
		);
		$case_response = $case_route['/wp-ai-bridge/v1/mcp'][0]['callback'](
			new WP_REST_Request( $case_request )
		)->get_data()['result'];
		$case_error = $case_response['structuredContent']['error'] ?? array();
		$assert( $case['code'] === ( $case_error['code'] ?? null ) &&
			'adapter_boundary' === ( $case_error['origin'] ?? '' ) &&
			'authorization' === ( $case_error['stage'] ?? '' ) &&
			$case['source'] === ( $case_error['code_source'] ?? '' ) &&
			! str_contains( json_encode( $case_response ), 'SECRET_DO_NOT_LEAK' ),
			'Adapter error was mislabeled as provider, fabricated, or leaked a credential.' );
	}

	// These WP7.1 Core hook observations are not execute-callback outcomes.
	$core_stage_cases = array(
		array( 'method' => 'observe_ability_validate_input', 'stage' => 'input_validation' ),
		array( 'method' => 'observe_ability_validate_output', 'stage' => 'output_validation' ),
		array( 'method' => 'observe_ability_normalize_input', 'stage' => 'normalization' ),
		array( 'method' => 'observe_ability_pre_execute', 'stage' => 'pre_execution' ),
	);
	foreach ( $core_stage_cases as $i => $case ) {
		$err = new WP_Error( 'native_stage_' . $i, 'A native validation stage rejected input' );
		$request_args = $native_args;
		$request_args['id'] = 40 + $i;
		$stage_route = $delegation->filter_rest_endpoints(
			array( '/wp-ai-bridge/v1/mcp' => array(
				array( 'callback' => static function () use ( $delegation, $native, $native_name, $err, $case, $request_args ) {
					if ( 'pre_execution' === $case['stage'] ) {
						$actual = $delegation->{$case['method']}( $err, $native_name, array(), $native );
					} elseif ( 'normalization' === $case['stage'] ) {
						$actual = $delegation->{$case['method']}( $err, $native_name, $native );
					} else {
						$actual = $delegation->{$case['method']}( $err, array(), $native_name );
					}
					$delegation->filter_tool_call_result(
						array( 'success' => false, 'error' => $actual->get_error_message() ),
						array( 'ability_name' => $native_name ),
						Native_Ability_Delegation::ADAPTER_EXECUTE_TOOL,
						new \stdClass(), new WP_AI_Test_MCP_Server()
					);
					return new WP_REST_Response( array(
						'jsonrpc' => '2.0', 'id' => $request_args['id'],
						'result' => array( 'isError' => true, 'content' => array(
							array( 'type' => 'text', 'text' => $actual->get_error_message() ),
						) ),
					) );
				} ),
			) )
		);
		$stage_error = $stage_route['/wp-ai-bridge/v1/mcp'][0]['callback'](
			new WP_REST_Request( $request_args )
		)->get_data()['result']['structuredContent']['error'] ?? array();
		$assert( 'native_stage_' . $i === ( $stage_error['code'] ?? '' ) &&
			$case['stage'] === ( $stage_error['stage'] ?? '' ) &&
			'observed_wordpress' === ( $stage_error['code_source'] ?? '' ) &&
			'wordpress_core' === ( $stage_error['origin'] ?? '' ),
			'Core lifecycle error source/stage was lost or fabricated.' );
	}

	// The real MCP wire layer can hand REST a JsonSerializable/Record result,
	// not just the plain PHP arrays used by the older mock callbacks.
	$record_params = $native_args;
	$record_params['id'] = 28;
	$record_route = $delegation->filter_rest_endpoints(
		array( '/wp-ai-bridge/v1/mcp' => array(
			array( 'callback' => static function () {
				return new WP_REST_Response( array(
					'jsonrpc' => '2.0', 'id' => 28,
					'result' => (object) array(
						'isError' => true,
						'content' => array(
							(object) array( 'type' => 'text', 'text' => 'Provider refused request; token=REDACT_RECORD_SECRET' ),
						),
					),
				) );
			} ),
		) )
	);
	$record_result = $record_route['/wp-ai-bridge/v1/mcp'][0]['callback'](
		new WP_REST_Request( $record_params )
	)->get_data()['result'];
	$record_result = is_object( $record_result ) ? (array) $record_result : $record_result;
	$record_error = $record_result['structuredContent']['error'] ?? array();
	$assert( true === ( $record_result['isError'] ?? false ) &&
		null === ( $record_error['code'] ?? null ) &&
		! empty( $record_error['diagnostic_reference'] ) &&
		true === ( $record_error['redacted'] ?? false ) &&
		! str_contains( json_encode( $record_result ), 'REDACT_RECORD_SECRET' ),
		'Wire-layer object-shaped MCP errors bypassed Bridge diagnostics/redaction.' );

	$serializable_params = $native_args;
	$serializable_params['id'] = 29;
	$serializable_route = $delegation->filter_rest_endpoints(
		array( '/wp-ai-bridge/v1/mcp' => array(
			array( 'callback' => static function () {
				$record = new class implements \JsonSerializable {
					public function jsonSerialize(): mixed {
						return (object) array( 'isError' => true, 'content' => array(
							new class implements \JsonSerializable {
								public function jsonSerialize(): mixed {
									return (object) array( 'type' => 'text', 'text' => 'Auth layer stopped: password=NO_LEAK_JSON_RECORD' );
								}
							},
						) );
					}
				};
				return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => 29, 'result' => $record ) );
			} ),
		) )
	);
	$serialized = $serializable_route['/wp-ai-bridge/v1/mcp'][0]['callback'](
		new WP_REST_Request( $serializable_params )
	)->get_data()['result'];
	$serialized_error = $serialized['structuredContent']['error'] ?? array();
	$assert( true === ( $serialized['isError'] ?? false ) &&
		! empty( $serialized_error['redacted'] ) &&
		! empty( $serialized_error['diagnostic_reference'] ) &&
		! str_contains( json_encode( $serialized ), 'NO_LEAK_JSON_RECORD' ) &&
		str_contains( $serialized['content'][0]['text'] ?? '', 'Auth layer stopped' ),
		'JsonSerializable MCP result was not preserved and selectively redacted.' );

	// php-mcp-schema v0.2.0 Record::jsonSerialize() returns stdClass,
	// including for the OUTER JSON-RPC response envelope.
	$outer_object_params = $native_args;
	$outer_object_params['id'] = 31;
	$outer_object_route = $delegation->filter_rest_endpoints(
		array( '/wp-ai-bridge/v1/mcp' => array(
			array( 'callback' => static function () {
				return new WP_REST_Response( (object) array(
					'jsonrpc' => '2.0', 'id' => 31,
					'result' => (object) array(
						'isError' => true,
						'content' => array(
							(object) array( 'type' => 'text', 'text' => 'Storage failed: client_secret=OUTER_OBJECT_SECRET' ),
						),
					),
				) );
			} ),
		) )
	);
	$outer_obj = $outer_object_route['/wp-ai-bridge/v1/mcp'][0]['callback'](
		new WP_REST_Request( $outer_object_params )
	)->get_data();
	$outer_obj = is_object( $outer_obj ) ? (array) $outer_obj : $outer_obj;
	$projected = is_object( $outer_obj['result'] ) ? (array) $outer_obj['result'] : $outer_obj['result'];
	$outer_error = $projected['structuredContent']['error'] ?? array();
	$assert( true === ( $projected['isError'] ?? false ) &&
		! empty( $outer_error['redacted'] ) &&
		! empty( $outer_error['diagnostic_reference'] ) &&
		! str_contains( json_encode( $outer_obj ), 'OUTER_OBJECT_SECRET' ),
		'StdClass top-level JSON-RPC envelope skipped selective error projection.' );

	// Context is request-scoped, not leaked into successful or unrelated calls.
	$success_route = $delegation->filter_rest_endpoints(
		array( '/wp-ai-bridge/v1/mcp' => array( array( 'callback' => static function () {
			return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => 20,
				'result' => array( 'isError' => false, 'content' => array() ) ) );
		} ) ) )
	);
	$success_params = $params;
	$success_params['id'] = 20;
	$success = $success_route['/wp-ai-bridge/v1/mcp'][0]['callback']( new WP_REST_Request( $success_params ) )->get_data();
	$assert( ! isset( $success['result']['structuredContent'] ), 'Previous error leaked into later successful request.' );
	// A Bridge capability returning boolean false is an observed denial,
	// never a fabricated WordPress WP_Error code or guessed root cause.
	$permission_params = $params;
	$permission_params['id'] = 21;
	$permission_route = $delegation->filter_rest_endpoints(
		array( '/wp-ai-bridge/v1/mcp' => array(
			array( 'callback' => static function () use ( $args ) {
				$allow = $args['permission_callback']( array() );
				return new WP_REST_Response( array(
					'jsonrpc' => '2.0', 'id' => 21,
					'result' => array( 'isError' => true, 'content' => array(
						array( 'type' => 'text', 'text' => $allow ? 'allowed' : 'Permission denied' ),
					) ),
				) );
			} ),
		) )
	);
	$permission_result = $permission_route['/wp-ai-bridge/v1/mcp'][0]['callback'](
		new WP_REST_Request( $permission_params )
	)->get_data()['result']['structuredContent']['error'] ?? array();
	$assert( null === ( $permission_result['code'] ?? null ) &&
		'bridge_ability' === ( $permission_result['origin'] ?? '' ) &&
		'authorization' === ( $permission_result['stage'] ?? '' ) &&
		'inspect_permissions' === ( $permission_result['next_step'] ?? '' ),
		'Boolean permission rejection fabricated a code or lost observed stage.' );

	$authenticated_client->setValue( null, '' );

	echo "PASS: faithful Bridge/Adapter errors, scoped diagnostics and selective redaction ({$checks} assertions).\n";
}
