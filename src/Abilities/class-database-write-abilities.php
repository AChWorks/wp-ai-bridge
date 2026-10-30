<?php
/**
 * Administrator-delegated typed database transactions and private byte staging.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Database_Staging;
use WP_AI_Bridge\Support\Database_Writer;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/** One public contract; execution and staging keep their own narrow stores. */
final class Database_Write_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Current Bridge delegation. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/database-write',
			array(
				'label'               => __( 'Manage Typed Database Transactions', 'wp-ai-bridge' ),
				'description'         => __( 'Describe supported installation tables, inspect exact row state, stage private value bytes, and execute receipt-bound insert/update/delete batches. Requires explicit Database Inspection, Sensitive Database Read and Database Write; deletion and destructive recovery additionally require Database Delete. Multisite requires actual Super Admin and network authority.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->schema(),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_write' ),
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
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return array */
	private function schema() {
		$identifier = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 64,
		);
		$digest     = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		$transfer   = array(
			'transfer_id' => $digest,
			'total_bytes' => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'sha256'      => $digest,
		);
		$values     = array(
			'type'                 => 'object',
			'additionalProperties' => array(
				'type'                 => array( 'string', 'null', 'object' ),
				'properties'           => $transfer,
				'required'             => array_keys( $transfer ),
				'additionalProperties' => false,
			),
		);
		$key        = $values;
		$operation  = array(
			'type'                 => 'object',
			'properties'           => array(
				'action'               => array(
					'type' => 'string',
					'enum' => array( 'insert', 'update', 'delete' ),
				),
				'table'                => $identifier,
				'expected_schema_hash' => $digest,
				'key'                  => $key,
				'expected_state_hash'  => $digest,
				'values'               => $values,
			),
			'required'             => array( 'action', 'table', 'expected_schema_hash' ),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'         => array(
					'type' => 'string',
					'enum' => array( 'describe', 'row_state', 'execute', 'recover', 'stage_open', 'stage_append', 'stage_status', 'stage_verify', 'stage_cancel', 'stage_release' ),
				),
				'table'          => $identifier,
				'key'            => $key,
				'operation_id'   => array(
					'type'      => 'string',
					'minLength' => 8,
					'maxLength' => 128,
					'pattern'   => '^[A-Za-z0-9_.:-]+$',
				),
				'operations'     => array(
					'type'     => 'array',
					'minItems' => 1,
					'items'    => $operation,
				),
				'destructive'    => array( 'type' => 'boolean' ),
				'transfer_id'    => $digest,
				'total_bytes'    => $transfer['total_bytes'],
				'sha256'         => $digest,
				'offset'         => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'content_base64' => array( 'type' => 'string' ),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Evaluate the complete required conjunction from one current policy snapshot.
	 *
	 * @param mixed $input Ability request; never an authority declaration.
	 * @return bool
	 */
	public function can_write( $input = array() ) {
		if ( $input instanceof \stdClass ) {
			$input = (array) $input;
		}
		if ( ! is_array( $input ) || get_current_user_id() < 1 ||
			( is_multisite() && ( ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) ) ) {
			return false;
		}
		$requirements = array(
			array( Settings::GROUP_DATABASE_INSPECTION, 'manage_options' ),
			array( Settings::GROUP_DATABASE_READ, 'manage_options' ),
			array( Settings::GROUP_DATABASE_WRITE, 'manage_options' ),
		);
		if ( $this->requires_delete( $input ) ) {
			$requirements[] = array( Settings::GROUP_DATABASE_DELETE, 'manage_options' );
		}
		return $this->permissions->allowed_all( $requirements );
	}

	/**
	 * Dispatch only a normalized public shape; stores enforce semantic contracts.
	 *
	 * @param mixed $input Ability request.
	 * @return array|WP_Error
	 */
	public function execute( $input ) {
		if ( ! $this->can_write( $input ) ) {
			return $this->denied();
		}
		$input = $this->validate( $input );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		if ( str_starts_with( $input['action'], 'stage_' ) ) {
			return ( new Database_Staging( $this->permissions ) )->execute( $input );
		}
		$principal = get_current_user_id();
		$blog      = get_current_blog_id();
		$client    = OAuth_Server::authenticated_mcp_client_id();
		$allowed   = function () use ( $input, $principal, $blog, $client ) {
			return get_current_user_id() === $principal && get_current_blog_id() === $blog &&
				OAuth_Server::authenticated_mcp_client_id() === $client && $this->can_write( $input );
		};
		return ( new Database_Writer() )->execute( $input, $allowed );
	}

	/** @param array $input Request. @return bool */
	private function requires_delete( array $input ) {
		if ( 'recover' === ( $input['action'] ?? null ) ) {
			return true === ( $input['destructive'] ?? false );
		}
		if ( 'execute' !== ( $input['action'] ?? null ) || ! is_array( $input['operations'] ?? null ) ) {
			return false;
		}
		foreach ( $input['operations'] as $operation ) {
			if ( $operation instanceof \stdClass ) {
				$operation = (array) $operation;
			}
			if ( is_array( $operation ) && 'delete' === ( $operation['action'] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enforce action-specific fields and normalize native JSON dictionaries.
	 *
	 * The writer owns canonical bytes, identifiers, schema/state and resource
	 * validation. No database or source file is opened by this shape validator.
	 *
	 * @param mixed $input Public request.
	 * @return array|WP_Error
	 */
	private function validate( $input ) {
		if ( $input instanceof \stdClass ) {
			$input = (array) $input;
		}
		$actions = array(
			'describe'      => array( 'action', 'table' ),
			'row_state'     => array( 'action', 'table', 'key' ),
			'execute'       => array( 'action', 'operation_id', 'operations' ),
			'recover'       => array( 'action', 'operation_id', 'destructive' ),
			'stage_open'    => array( 'action', 'total_bytes', 'sha256' ),
			'stage_append'  => array( 'action', 'transfer_id', 'offset', 'content_base64' ),
			'stage_status'  => array( 'action', 'transfer_id' ),
			'stage_verify'  => array( 'action', 'transfer_id' ),
			'stage_cancel'  => array( 'action', 'transfer_id' ),
			'stage_release' => array( 'action', 'transfer_id' ),
		);
		if ( ! is_array( $input ) || ! is_string( $input['action'] ?? null ) ||
			! isset( $actions[ $input['action'] ] ) ) {
			return $this->invalid();
		}
		$fields   = $actions[ $input['action'] ];
		$required = array_diff( $fields, array( 'destructive' ) );
		if ( array_diff( array_keys( $input ), $fields ) || array_diff( $required, array_keys( $input ) ) ) {
			return $this->invalid();
		}
		foreach ( array( 'table', 'operation_id', 'transfer_id', 'sha256', 'content_base64' ) as $name ) {
			if ( array_key_exists( $name, $input ) && ! is_string( $input[ $name ] ) ) {
				return $this->invalid();
			}
		}
		foreach ( array( 'offset', 'total_bytes' ) as $name ) {
			if ( array_key_exists( $name, $input ) && ( ! is_int( $input[ $name ] ) || $input[ $name ] < 0 ) ) {
				return $this->invalid();
			}
		}
		if ( array_key_exists( 'destructive', $input ) && ! is_bool( $input['destructive'] ) ) {
			return $this->invalid();
		}
		if ( array_key_exists( 'key', $input ) ) {
			$input['key'] = $this->map( $input['key'], true );
			if ( is_wp_error( $input['key'] ) ) {
				return $input['key'];
			}
		}
		if ( 'execute' !== $input['action'] ) {
			return $input;
		}
		if ( ! is_array( $input['operations'] ) || ! array_is_list( $input['operations'] ) || ! $input['operations'] ) {
			return $this->invalid();
		}
		$step_fields = array(
			'insert' => array( 'action', 'table', 'expected_schema_hash', 'values' ),
			'update' => array( 'action', 'table', 'expected_schema_hash', 'key', 'expected_state_hash', 'values' ),
			'delete' => array( 'action', 'table', 'expected_schema_hash', 'key', 'expected_state_hash' ),
		);
		foreach ( $input['operations'] as &$step ) {
			if ( $step instanceof \stdClass ) {
				$step = (array) $step;
			}
			if ( ! is_array( $step ) || ! is_string( $step['action'] ?? null ) || ! isset( $step_fields[ $step['action'] ] ) ) {
				return $this->invalid();
			}
			$fields = $step_fields[ $step['action'] ];
			if ( array_diff( array_keys( $step ), $fields ) || array_diff( $fields, array_keys( $step ) ) ) {
				return $this->invalid();
			}
			foreach ( array( 'table', 'expected_schema_hash', 'expected_state_hash' ) as $name ) {
				if ( array_key_exists( $name, $step ) && ! is_string( $step[ $name ] ) ) {
					return $this->invalid();
				}
			}
			foreach ( array( 'key', 'values' ) as $name ) {
				if ( array_key_exists( $name, $step ) ) {
					$step[ $name ] = $this->map( $step[ $name ], true );
					if ( is_wp_error( $step[ $name ] ) ) {
						return $step[ $name ];
					}
				}
			}
		}
		unset( $step );
		return $input;
	}

	/**
	 * @param mixed $value Native object or decoded map.
	 * @param bool  $transfers Whether verified transfer descriptors are accepted.
	 * @return array|WP_Error
	 */
	private function map( $value, $transfers ) {
		if ( $value instanceof \stdClass ) {
			$value = (array) $value;
		}
		if ( ! is_array( $value ) ) {
			return $this->invalid();
		}
		foreach ( $value as &$scalar ) {
			if ( null === $scalar || is_string( $scalar ) ) {
				continue;
			}
			if ( $scalar instanceof \stdClass ) {
				$scalar = (array) $scalar;
			}
			$fields = array( 'transfer_id', 'total_bytes', 'sha256' );
			if ( ! $transfers || ! is_array( $scalar ) || array_diff( array_keys( $scalar ), $fields ) ||
				array_diff( $fields, array_keys( $scalar ) ) || ! is_string( $scalar['transfer_id'] ) ||
				! is_int( $scalar['total_bytes'] ) || $scalar['total_bytes'] < 0 || ! is_string( $scalar['sha256'] ) ) {
				return $this->invalid();
			}
		}
		unset( $scalar );
		return $value;
	}

	/** @return WP_Error */
	private function denied() {
		return new WP_Error( 'database_access_denied', __( 'Enable the required database grants in WP AI Bridge Settings and use a current WordPress administrator. Deletion and destructive recovery also require Database Delete. Multisite requires actual Super Admin and network authority.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function invalid() {
		return new WP_Error( 'database_input_invalid', __( 'Use the action-specific typed database schema with exact fields, an ordered insert/update/delete batch, complete keys and Base64, NULL or private-transfer value descriptors. Numbers are not database scalar values.', 'wp-ai-bridge' ) );
	}
}
