<?php
/**
 * Exact theme modifications and native transient lifecycle.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Administration_Access;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Hook_Dispatch_Recovery;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Configuration_Operation;
use WP_AI_Bridge\Support\Native_Option_Operation;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_Error;

/** Uses Core APIs, including cache expiry/filters, without provider storage guesses. */
final class Native_Configuration_Abilities {
	/** @var Permissions */ private $permissions;
	/** @var Native_Configuration_Operation */ private $operation;

	/** @param Permissions $permissions Current delegation. @param Mutation_Log|null $log Activity. */
	public function __construct( Permissions $permissions, ?Mutation_Log $log = null ) {
		$this->permissions = $permissions;
		$this->operation   = new Native_Configuration_Operation( $permissions, $log ? $log : new Mutation_Log() );
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/native-configuration',
			array(
				'label'               => __( 'Native Theme and Transient Configuration', 'wp-ai-bridge' ),
				'description'         => __( 'Reads or changes exact current-theme modifications and native site or site-wide transients with separately delegated authority and recoverable one-dispatch receipts. Native reads may expire storage; false does not prove absence.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => self::schema(),
				'output_schema'       => self::output_schema(),
				'permission_callback' => array( $this, 'can_execute' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
		return $registered;
	}

	/** @param mixed $input Operation input. @return bool */
	public function can_execute( $input = array() ) {
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $input ) {
					$parsed = self::parse( $input );
					return ! is_wp_error( $parsed ) && $this->operation->allowed( $parsed );
				}
			);
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** @param mixed $input Operation input. @return array|WP_Error */
	public function execute( $input ) {
		$input = self::parse( $input );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		return $this->operation->execute(
			$input,
			function () use ( $input ) {
				switch ( $input['target'] ) {
					case 'theme_mod':
						return get_theme_mod( $input['name'] );
					case 'site_transient':
						return get_site_transient( $input['name'] );
					default:
						return get_transient( $input['name'] );
				}
			},
			function ( $value ) use ( $input ) {
				if ( 'theme_mod' === $input['target'] ) {
					return 'delete' === $input['action'] ? remove_theme_mod( $input['name'] ) : set_theme_mod( $input['name'], $value );
				}
				if ( 'site_transient' === $input['target'] ) {
					return 'delete' === $input['action'] ? delete_site_transient( $input['name'] ) : set_site_transient( $input['name'], $value, $input['expiration_seconds'] );
				}
				return 'delete' === $input['action'] ? delete_transient( $input['name'] ) : set_transient( $input['name'], $value, $input['expiration_seconds'] );
			},
			function ( $unused ) use ( $input ) {
				return $this->guard( $input ); },
			function () use ( $input ) {
				return $this->listing( $input ); }
		);
	}

	/** @param array $input Fixed native target. @return true|WP_Error */
	private function guard( array $input ) {
		$access   = new Administration_Access( $this->permissions );
		$origin   = $access->context();
		$network  = 'site_transient' === $input['target'] && is_multisite();
		$identity = array_merge( array( 'scope' => $network ? 'network' : 'site' ), $origin );
		$guard    = new Native_Option_Operation( $identity, max( 1, ( new Transfer_Settings() )->bytes()['request'] ) );
		if ( 'theme_mod' === $input['target'] ) {
			$names = array( 'theme_mods_' . get_stylesheet() );
		} else {
			$prefix = 'site_transient' === $input['target'] ? '_site_transient_' : '_transient_';
			$names  = array( $prefix . $input['name'], $prefix . 'timeout_' . $input['name'] );
		}
		foreach ( $names as $name ) {
			$checked = $guard->check_target( $identity['scope'], $name );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
		}
		return $access->same_context( $origin ) ? true : $this->operation->error( 'configuration_context_changed' );
	}

	/** @param array $input Native theme-key page. @return array|WP_Error */
	private function listing( array $input ) {
		$mods = get_theme_mods();
		$keys = array_keys( $mods );
		$keys = array_map( 'strval', $keys );
		sort( $keys, SORT_STRING );
		$result = array(
			'items'                   => array(),
			'next_after_name'         => $input['after_name'] ?? '',
			'has_more'                => false,
			'inventory'               => 'stored_theme_mod_keys',
			'virtual_keys_enumerated' => false,
		);
		$limit  = $input['limit'] ?? PHP_INT_MAX;
		$budget = Bounded_Payload::response_limit();
		foreach ( $keys as $name ) {
			if ( isset( $input['after_name'] ) && strcmp( $name, $input['after_name'] ) <= 0 ) {
				continue;
			}
			if ( ! Native_Configuration_Operation::text( $name ) ) {
				return $this->operation->error( 'configuration_inventory_unrepresentable' );
			}
			$candidate            = $result;
			$candidate['items'][] = array( 'name' => $name );
			if ( count( $result['items'] ) >= $limit || Bounded_Payload::projected_mcp_bytes( $candidate ) + 1024 > $budget ) {
				$result['has_more'] = true;
				break;
			}
			$result['items'][]         = array( 'name' => $name );
			$result['next_after_name'] = $name;
		}
		return $result['has_more'] && ! $result['items'] ? $this->operation->error( 'configuration_response_budget' ) : $result;
	}

	/** Shared closed parsing; Customizer substitutes only its fixed target/action vocabulary. @param mixed $input Public input. @param bool $customizer Internal fixed mode. @return array|WP_Error */
	public static function parse( $input, $customizer = false ) {
		$base    = $customizer ? array( 'action', 'expected_stylesheet' ) : array( 'action', 'target', 'expected_stylesheet' );
		$actions = array(
			'list'             => array( 'after_name', 'limit' ),
			'read'             => array( 'name' ),
			'snapshot'         => array( 'name' ),
			'set'              => array( 'name', 'operation_id', 'value_source', 'expiration_seconds', 'expected_observed_sha256' ),
			'delete'           => array( 'name', 'operation_id', 'confirm', 'expected_observed_sha256' ),
			'update'           => array( 'name', 'operation_id', 'value_source', 'expected_observed_sha256' ),
			'status'           => array( 'operation_id' ),
			'input_begin'      => array( 'total_bytes', 'sha256' ),
			'input_append'     => array( 'transfer_id', 'offset', 'base64' ),
			'input_verify'     => array( 'transfer_id' ),
			'input_status'     => array( 'transfer_id' ),
			'input_release'    => array( 'transfer_id' ),
			'snapshot_read'    => array( 'transfer_id', 'offset', 'length' ),
			'snapshot_status'  => array( 'transfer_id' ),
			'snapshot_release' => array( 'transfer_id' ),
		);
		$action  = is_array( $input ) ? ( $input['action'] ?? '' ) : '';
		if ( ! is_array( $input ) || ! is_string( $action ) || ! isset( $actions[ $action ] ) || array_diff( array_keys( $input ), array_merge( $base, $actions[ $action ] ) ) || ( $customizer ? in_array( $action, array( 'set', 'delete' ), true ) : 'update' === $action ) ) {
			return self::invalid();
		}
		foreach ( $input as $value ) {
			if ( null === $value ) {
				return self::invalid();
			}
		}
		if ( $customizer ) {
			$input['target'] = 'customizer_setting';
		} elseif ( ! in_array( $input['target'] ?? null, array( 'theme_mod', 'transient', 'site_transient' ), true ) ) {
			return self::invalid();
		}
		$theme = $customizer || 'theme_mod' === $input['target'];
		if ( ( isset( $input['expected_stylesheet'] ) && ( ! $theme || ! Native_Configuration_Operation::text( $input['expected_stylesheet'] ) ) ) || ( $theme && ( in_array( $action, array( 'set', 'delete', 'update' ), true ) || 0 === strpos( $action, 'input_' ) || 0 === strpos( $action, 'snapshot_' ) || $customizer ) && ! isset( $input['expected_stylesheet'] ) ) ) {
			return self::invalid();
		}
		if ( 'list' === $action && ( ! $theme || ( isset( $input['after_name'] ) && ! Native_Configuration_Operation::text( $input['after_name'] ) ) || ( isset( $input['limit'] ) && ( ! is_int( $input['limit'] ) || $input['limit'] < 1 ) ) ) ) {
			return self::invalid();
		}
		if ( in_array( $action, array( 'read', 'snapshot', 'set', 'delete', 'update' ), true ) ) {
			if ( ! Native_Configuration_Operation::text( $input['name'] ?? null ) ) {
				return self::invalid();
			}
			if ( ! $theme && ( preg_match( '/^(?:wpai|wp_ai_bridge)/i', $input['name'] ) || preg_match_all( '/./us', $input['name'] ) > ( 'transient' === $input['target'] ? 172 : 167 ) ) ) {
				return self::invalid();
			}
		}
		if ( in_array( $action, array( 'set', 'delete', 'update', 'status' ), true ) && ( ! is_string( $input['operation_id'] ?? null ) || 1 !== preg_match( '/^[A-Za-z0-9_.:-]{8,128}$/D', $input['operation_id'] ) ) ) {
			return self::invalid();
		}
		if ( in_array( $action, array( 'set', 'update' ), true ) && ! Native_Configuration_Operation::valid_source( $input['value_source'] ?? null ) ) {
			return self::invalid();
		}
		if ( ( isset( $input['expected_observed_sha256'] ) && ! Native_Configuration_Operation::digest( $input['expected_observed_sha256'] ) ) || ( 'delete' === $action && true !== ( $input['confirm'] ?? null ) ) ) {
			return self::invalid();
		}
		if ( 'set' === $action && ( $theme ? isset( $input['expiration_seconds'] ) : ( ! is_int( $input['expiration_seconds'] ?? null ) || $input['expiration_seconds'] < 0 || $input['expiration_seconds'] > PHP_INT_MAX - time() ) ) ) {
			return self::invalid();
		}
		if ( 'input_begin' === $action && ( ! is_int( $input['total_bytes'] ?? null ) || $input['total_bytes'] < 1 || ! Native_Configuration_Operation::digest( $input['sha256'] ?? null ) ) ) {
			return self::invalid();
		}
		if ( ( 0 === strpos( $action, 'snapshot_' ) || ( 0 === strpos( $action, 'input_' ) && 'input_begin' !== $action ) ) && ! Native_Configuration_Operation::digest( $input['transfer_id'] ?? null ) ) {
			return self::invalid();
		}
		if ( in_array( $action, array( 'input_append', 'snapshot_read' ), true ) && ( ! is_int( $input['offset'] ?? null ) || $input['offset'] < 0 || ( isset( $input['length'] ) && ( ! is_int( $input['length'] ) || $input['length'] < 1 ) ) ) ) {
			return self::invalid();
		}
		if ( 'input_append' === $action && ( ! is_string( $input['base64'] ?? null ) || '' === $input['base64'] ) ) {
			return self::invalid();
		}
		return $input;
	}

	/** @return WP_Error */
	private static function invalid() {
		return new WP_Error( 'configuration_invalid_input', __( 'Use one exact native configuration action with only its documented fields and current-theme identity where required.', 'wp-ai-bridge' ) );
	}

	/** @param bool $customizer Fixed native setting family. @return array */
	public static function schema( $customizer = false ) {
		$properties = array(
			'action'                   => array(
				'type' => 'string',
				'enum' => $customizer ? array( 'list', 'read', 'snapshot', 'update', 'status', 'input_begin', 'input_append', 'input_verify', 'input_status', 'input_release', 'snapshot_read', 'snapshot_status', 'snapshot_release' ) : array( 'list', 'read', 'snapshot', 'set', 'delete', 'status', 'input_begin', 'input_append', 'input_verify', 'input_status', 'input_release', 'snapshot_read', 'snapshot_status', 'snapshot_release' ),
			),
			'name'                     => array(
				'type'      => 'string',
				'minLength' => 1,
			),
			'expected_stylesheet'      => array(
				'type'      => 'string',
				'minLength' => 1,
			),
			'operation_id'             => array(
				'type'      => 'string',
				'minLength' => 8,
				'maxLength' => 128,
			),
			'value_source'             => array(
				'type'                 => 'object',
				'properties'           => array(
					'value_json'  => array( 'type' => 'string' ),
					'transfer_id' => array( 'type' => 'string' ),
					'sha256'      => array( 'type' => 'string' ),
					'total_bytes' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'additionalProperties' => false,
			),
			'expected_observed_sha256' => array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			),
			'confirm'                  => array( 'type' => 'boolean' ),
			'expiration_seconds'       => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'after_name'               => array(
				'type'      => 'string',
				'minLength' => 1,
			),
			'limit'                    => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'transfer_id'              => array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			),
			'offset'                   => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'length'                   => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'base64'                   => array( 'type' => 'string' ),
			'sha256'                   => array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			),
			'total_bytes'              => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$required   = array( 'action' );
		if ( $customizer ) {
			unset( $properties['confirm'], $properties['expiration_seconds'] );
			$required[] = 'expected_stylesheet';
		} else {
			$properties['target'] = array(
				'type' => 'string',
				'enum' => array( 'theme_mod', 'transient', 'site_transient' ),
			);
			$required[]           = 'target';
		}
		$properties['value_source']['oneOf'] = array(
			array(
				'type'                 => 'object',
				'properties'           => array( 'value_json' => $properties['value_source']['properties']['value_json'] ),
				'required'             => array( 'value_json' ),
				'additionalProperties' => false,
			),
			array(
				'type'                 => 'object',
				'properties'           => array_intersect_key( $properties['value_source']['properties'], array_flip( array( 'transfer_id', 'sha256', 'total_bytes' ) ) ),
				'required'             => array( 'transfer_id', 'sha256', 'total_bytes' ),
				'additionalProperties' => false,
			),
		);
		$fields                              = array(
			'list'             => array( 'after_name', 'limit' ),
			'read'             => array( 'name' ),
			'snapshot'         => array( 'name' ),
			'set'              => array( 'name', 'operation_id', 'value_source', 'expiration_seconds', 'expected_observed_sha256' ),
			'delete'           => array( 'name', 'operation_id', 'confirm', 'expected_observed_sha256' ),
			'update'           => array( 'name', 'operation_id', 'value_source', 'expected_observed_sha256' ),
			'status'           => array( 'operation_id' ),
			'input_begin'      => array( 'total_bytes', 'sha256' ),
			'input_append'     => array( 'transfer_id', 'offset', 'base64' ),
			'input_verify'     => array( 'transfer_id' ),
			'input_status'     => array( 'transfer_id' ),
			'input_release'    => array( 'transfer_id' ),
			'snapshot_read'    => array( 'transfer_id', 'offset', 'length' ),
			'snapshot_status'  => array( 'transfer_id' ),
			'snapshot_release' => array( 'transfer_id' ),
		);
		$needed                              = array(
			'list'             => array(),
			'read'             => array( 'name' ),
			'snapshot'         => array( 'name' ),
			'set'              => array( 'name', 'operation_id', 'value_source' ),
			'delete'           => array( 'name', 'operation_id', 'confirm' ),
			'update'           => array( 'name', 'operation_id', 'value_source' ),
			'status'           => array( 'operation_id' ),
			'input_begin'      => array( 'total_bytes', 'sha256' ),
			'input_append'     => array( 'transfer_id', 'offset', 'base64' ),
			'input_verify'     => array( 'transfer_id' ),
			'input_status'     => array( 'transfer_id' ),
			'input_release'    => array( 'transfer_id' ),
			'snapshot_read'    => array( 'transfer_id', 'offset' ),
			'snapshot_status'  => array( 'transfer_id' ),
			'snapshot_release' => array( 'transfer_id' ),
		);
		$variants                            = array();
		foreach ( $properties['action']['enum'] as $action ) {
			$targets = $customizer ? array( 'customizer_setting' ) : ( 'list' === $action ? array( 'theme_mod' ) : array( 'theme_mod', 'transient', 'site_transient' ) );
			foreach ( $targets as $target ) {
				$names     = array_merge( array( 'action' ), $fields[ $action ] );
				$mandatory = array_merge( array( 'action' ), $needed[ $action ] );
				if ( ! $customizer ) {
					$names[]     = 'target';
					$mandatory[] = 'target';
				}
				$theme = $customizer || 'theme_mod' === $target;
				if ( $theme ) {
					$names[] = 'expected_stylesheet';
					if ( $customizer || in_array( $action, array( 'set', 'delete' ), true ) || 0 === strpos( $action, 'input_' ) || 0 === strpos( $action, 'snapshot_' ) ) {
						$mandatory[] = 'expected_stylesheet';
					}
					$names = array_diff( $names, array( 'expiration_seconds' ) );
				} elseif ( 'set' === $action ) {
					$mandatory[] = 'expiration_seconds';
				}
				$variant                                 = array(
					'type'                 => 'object',
					'properties'           => array_intersect_key( $properties, array_flip( $names ) ),
					'required'             => $mandatory,
					'additionalProperties' => false,
				);
				$variant['properties']['action']['enum'] = array( $action );
				if ( ! $customizer ) {
					$variant['properties']['target']['enum'] = array( $target );
				}
				if ( 'delete' === $action ) {
					$variant['properties']['confirm']['enum'] = array( true );
				}
				$variants[] = $variant;
			}
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
			'oneOf'                => $variants,
		);
	}
	/** @return array Closed public result vocabulary; native receipts remain inert metadata. */
	public static function output_schema() {
		$properties = array();
		foreach ( array( 'action', 'target', 'stylesheet', 'state', 'operation_ref', 'format', 'value_json', 'sha256', 'storage_presence', 'next_after_name', 'inventory', 'transfer_id', 'base64', 'chunk_sha256', 'integrity' ) as $name ) {
			$properties[ $name ] = array( 'type' => 'string' );
		}
		foreach ( array( 'blog_id', 'network_id', 'total_bytes', 'received_bytes', 'chunk_bytes', 'offset', 'next_offset', 'expires_at' ) as $name ) {
			$properties[ $name ] = array(
				'type'    => 'integer',
				'minimum' => 0,
			);
		}
		foreach ( array( 'historical', 'value_available', 'snapshot_required', 'has_more', 'virtual_keys_enumerated', 'dynamic_names_enumerated', 'released', 'complete' ) as $name ) {
			$properties[ $name ] = array( 'type' => 'boolean' );
		}
		$properties['receipt'] = array( 'type' => array( 'object', 'null' ) );
		$properties['items']   = array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'       => array( 'type' => 'string' ),
					'type'       => array( 'type' => 'string' ),
					'capability' => array( 'type' => 'string' ),
				),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'action', 'target', 'blog_id', 'network_id', 'stylesheet' ),
			'additionalProperties' => false,
		);
	}
}
