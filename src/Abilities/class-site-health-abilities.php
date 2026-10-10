<?php
/**
 * Native WordPress Site Health inspection through purpose-bounded Core contracts.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_Site_Health;

/**
 * Exposes only trusted WordPress Core health tests, without a generic PHP caller.
 */
final class Site_Health_Abilities {
	const PAGE_LIMIT = 25;

	/** @var Permissions */
	private $permissions;
	/** @var bool */
	private $registry_truncated = false;

	/** @param Permissions $permissions Bridge permission service. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/site-health',
			array(
				'label'               => __( 'Inspect WordPress Site Health', 'wp-ai-bridge' ),
				'description'         => __( 'Discovers and explicitly runs one supported native Site Health test using its WordPress permissions; unavailable provider tests are not fabricated.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'action' => array(
							'type' => 'string',
							'enum' => array( 'list', 'run' ),
						),
						'test'   => array(
							'type'      => 'string',
							'maxLength' => 80,
						),
						'offset' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::PAGE_LIMIT,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'items'              => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'test'             => array( 'type' => 'string' ),
									'category'         => array( 'type' => 'string' ),
									'label'            => array( 'type' => 'string' ),
									'source'           => array( 'type' => 'string' ),
									'executable'       => array( 'type' => 'boolean' ),
									'status'           => array( 'type' => 'string' ),
									'checked_at_utc'   => array( 'type' => 'string' ),
									'details_in_admin' => array( 'type' => 'boolean' ),
								),
								'required'             => array( 'test', 'category', 'label', 'source', 'executable', 'status', 'checked_at_utc', 'details_in_admin' ),
								'additionalProperties' => false,
							),
						),
						'total'              => array( 'type' => 'integer' ),
						'has_more'           => array( 'type' => 'boolean' ),
						'next_offset'        => array( 'type' => 'integer' ),
						'registry_truncated' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'items', 'total', 'has_more', 'next_offset', 'registry_truncated' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'can_inspect' ),
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */
	public function can_inspect() {
		return $this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'view_site_health_checks' );
	}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function execute( $input = array() ) {
		if ( ! $this->can_inspect() ) {
			return new WP_Error( 'site_health_denied', __( 'Site Configuration and native Site Health permission are required.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'site_health_invalid_input', __( 'Invalid Site Health selector.', 'wp-ai-bridge' ) );
		}
		$action = isset( $input['action'] ) ? $input['action'] : 'list';
		if ( ! in_array( $action, array( 'list', 'run' ), true ) ) {
			return new WP_Error( 'site_health_invalid_input', __( 'Invalid Site Health selector.', 'wp-ai-bridge' ) );
		}
		$allowed_keys = 'run' === $action ? array( 'action', 'test' ) : array( 'action', 'offset', 'limit' );
		if ( array_diff( array_keys( $input ), $allowed_keys ) ) {
			return new WP_Error( 'site_health_invalid_input', __( 'Invalid Site Health selector.', 'wp-ai-bridge' ) );
		}
		if ( ! class_exists( 'WP_Site_Health' ) && is_file( ABSPATH . 'wp-admin/includes/class-wp-site-health.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			return new WP_Error( 'site_health_unavailable', __( 'Native Site Health is unavailable in this WordPress runtime.', 'wp-ai-bridge' ) );
		}
		$tests              = $this->tests();
		$registry_truncated = $this->registry_truncated;
		if ( 'list' === $action ) {
			$offset = isset( $input['offset'] ) ? $input['offset'] : 0;
			$limit  = isset( $input['limit'] ) ? $input['limit'] : 10;
			if ( ! is_int( $offset ) || $offset < 0 || ! is_int( $limit ) || $limit < 1 || $limit > self::PAGE_LIMIT ) {
				return new WP_Error( 'site_health_invalid_input', __( 'Invalid Site Health selector.', 'wp-ai-bridge' ) );
			}
			$total = count( $tests );
			$page  = array_slice( $tests, $offset, $limit );
			return array(
				'items'              => array_values( $page ),
				'total'              => $total,
				'has_more'           => $offset + count( $page ) < $total,
				'next_offset'        => $offset + count( $page ),
				'registry_truncated' => $registry_truncated,
			);
		}
		$test = isset( $input['test'] ) ? $input['test'] : '';
		if ( ! is_string( $test ) || ! preg_match( '/^[a-z0-9_]{1,80}$/D', $test ) ) {
			return new WP_Error( 'site_health_invalid_input', __( 'Invalid Site Health selector.', 'wp-ai-bridge' ) );
		}
		foreach ( $tests as $entry ) {
			if ( $entry['test'] !== $test ) {
				continue;
			}
			if ( ! $entry['executable'] ) {
				return new WP_Error( 'site_health_test_unavailable', __( 'This Site Health test has no verified safe Core execution contract. Open Tools Site Health instead.', 'wp-ai-bridge' ) );
			}
			$raw = $this->run_core_test( $entry );
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}
			$entry['status']         = in_array( $raw['status'] ?? '', array( 'good', 'recommended', 'critical' ), true ) ? $raw['status'] : 'outcome_unknown';
			$entry['label']          = $this->safe_label( isset( $raw['label'] ) ? $raw['label'] : $entry['label'] );
			$entry['checked_at_utc'] = gmdate( 'c' );
			return array(
				'items'              => array( $entry ),
				'total'              => 1,
				'has_more'           => false,
				'next_offset'        => 1,
				'registry_truncated' => $registry_truncated,
			);
		}
		return new WP_Error( 'site_health_test_unavailable', __( 'The requested native Site Health test is not registered.', 'wp-ai-bridge' ) );
	}

	/**
	 * Names and support flags come from Core's filtered registry. Never execute
	 * arbitrary plugin callback names, provider REST endpoints or admin-ajax actions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function tests() {
		$registered               = WP_Site_Health::get_tests();
		$result                   = array();
		$this->registry_truncated = false;
		// Stable native test ID -> fixed Core method suffix. Never accept
		// provider-authored PHP callback/class/method names from the registry.
		$allowed    = array(
			'wordpress_version'            => 'wordpress_version',
			'plugin_version'               => 'plugin_version',
			'theme_version'                => 'theme_version',
			'php_version'                  => 'php_version',
			'php_extensions'               => 'php_extensions',
			'php_default_timezone'         => 'php_default_timezone',
			'php_sessions'                 => 'php_sessions',
			'sql_server'                   => 'sql_server',
			'ssl_support'                  => 'ssl_support',
			'utf8mb4_support'              => 'utf8mb4_support',
			'scheduled_events'             => 'scheduled_events',
			'http_requests'                => 'http_requests',
			'rest_availability'            => 'rest_availability',
			'debug_enabled'                => 'is_in_debug_mode',
			'file_uploads'                 => 'file_uploads',
			'plugin_theme_auto_updates'    => 'plugin_theme_auto_updates',
			'update_temp_backup_writable'  => 'update_temp_backup_writable',
			'available_updates_disk_space' => 'available_updates_disk_space',
			'autoloaded_options'           => 'autoloaded_options',
			'insecure_registration'        => 'insecure_registration',
			'search_engine_visibility'     => 'search_engine_visibility',
			'opcode_cache'                 => 'opcode_cache',
			'persistent_object_cache'      => 'persistent_object_cache',
		);
		$rest_tests = array(
			'background_updates'   => 'background-updates',
			'dotorg_communication' => 'dotorg-communication',
			'loopback_requests'    => 'loopback-requests',
			'https_status'         => 'https-status',
			'authorization_header' => 'authorization-header',
			'page_cache'           => 'page-cache',
		);
		foreach ( array( 'direct', 'async' ) as $category ) {
			if ( ! isset( $registered[ $category ] ) || ! is_array( $registered[ $category ] ) ) {
				continue;
			}
			foreach ( $registered[ $category ] as $key => $definition ) {
				if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9_]{1,80}$/D', $key ) || ! is_array( $definition ) ) {
					continue;
				}
				$direct_ok = 'direct' === $category && isset( $allowed[ $key ] ) &&
					isset( $definition['test'] ) && is_string( $definition['test'] ) &&
					$allowed[ $key ] === $definition['test'] &&
					is_callable( array( WP_Site_Health::get_instance(), 'get_test_' . $allowed[ $key ] ) );
				// Authorization-header test explicitly relies on an actual
				// inbound HTTP header; a loopback dispatch is not equivalent.
				$rest_ok  = 'async' === $category && 'authorization_header' !== $key &&
					isset( $rest_tests[ $key ] ) && ! empty( $definition['has_rest'] ) &&
					isset( $definition['test'] ) && is_string( $definition['test'] ) &&
					rest_url( 'wp-site-health/v1/tests/' . $rest_tests[ $key ] ) === $definition['test'];
				$result[] = array(
					'test'             => $key,
					'category'         => $category,
					'label'            => $this->safe_label( isset( $definition['label'] ) ? $definition['label'] : $key ),
					'source'           => $direct_ok ? 'core_direct' : ( $rest_ok ? 'core_rest' : 'unverified_provider' ),
					'executable'       => $direct_ok || $rest_ok,
					'status'           => 'not_run',
					'checked_at_utc'   => '',
					'details_in_admin' => true,
				);
				if ( count( $result ) >= 128 ) {
					$this->registry_truncated = true;
					break 2;
				}
			}
		}
		usort(
			$result,
			static function ( $a, $b ) {
				return strcmp( $a['test'], $b['test'] );
			}
		);
		return $result;
	}

	/** @param array<string,mixed> $entry Registry entry. @return array<string,mixed>|WP_Error */
	private function run_core_test( $entry ) {
		if ( 'core_direct' === $entry['source'] ) {
			$health  = WP_Site_Health::get_instance();
			$aliases = array( 'debug_enabled' => 'is_in_debug_mode' );
			$method  = 'get_test_' . ( $aliases[ $entry['test'] ] ?? $entry['test'] );
			// Direct method name and callable were vetted against the fixed Core allowlist.
			$result = $health->$method();
			return is_array( $result ) ? $result : new WP_Error( 'site_health_unavailable', __( 'Native Site Health returned an invalid result.', 'wp-ai-bridge' ) );
		}
		$routes = array(
			'background_updates'   => 'background-updates',
			'dotorg_communication' => 'dotorg-communication',
			'loopback_requests'    => 'loopback-requests',
			'https_status'         => 'https-status',
			'authorization_header' => 'authorization-header',
			'page_cache'           => 'page-cache',
		);
		$route  = '/wp-site-health/v1/tests/' . $routes[ $entry['test'] ];
		// Ensure Core owns the selected route; plugins may otherwise replace handlers.
		$handlers = rest_get_server()->get_routes();
		$valid    = false;
		$method   = 'test_' . str_replace( '-', '_', $routes[ $entry['test'] ] );
		foreach ( $handlers[ $route ] ?? array() as $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['callback'], $handler['methods'] ) ) {
				continue;
			}
			$methods = $handler['methods'];
			$has_get = is_array( $methods ) ? ! empty( $methods['GET'] ) : false !== strpos( strtoupper( (string) $methods ), 'GET' );
			if ( ! $has_get ) {
				continue;
			}
			if ( ! is_array( $handler['callback'] ) || ! isset( $handler['callback'][0], $handler['callback'][1] ) ||
				! $handler['callback'][0] instanceof \WP_REST_Site_Health_Controller ||
				$handler['callback'][1] !== $method ) {
				return new WP_Error( 'site_health_unavailable', __( 'Native Site Health REST handler was not verified.', 'wp-ai-bridge' ) );
			}
			$valid = true;
		}

		if ( ! $valid ) {
			return new WP_Error( 'site_health_unavailable', __( 'Native Site Health REST handler was not verified.', 'wp-ai-bridge' ) );
		}
		$request = new WP_REST_Request( 'GET', $route );
		$result  = rest_do_request( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result instanceof \WP_REST_Response || $result->is_error() ) {
			return new WP_Error( 'site_health_blocked', __( 'Native Site Health REST test failed or denied the current user.', 'wp-ai-bridge' ) );
		}
		$data = $result->get_data();
		return is_array( $data ) ? $data : new WP_Error( 'site_health_unavailable', __( 'Native Site Health returned an invalid result.', 'wp-ai-bridge' ) );
	}

	/** @param mixed $label Label. @return string */
	private function safe_label( $label ) {
		$text = is_scalar( $label ) ? wp_strip_all_tags( (string) $label ) : '';
		$text = wp_check_invalid_utf8( $text, true );
		if ( strlen( $text ) <= 256 ) {
			return $text;
		}
		$prefix = substr( $text, 0, 256 );
		while ( '' !== $prefix && 1 !== preg_match( '//u', $prefix ) ) {
			$prefix = substr( $prefix, 0, -1 );
		}
		return $prefix;
	}
}
