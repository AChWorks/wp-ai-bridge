<?php
/**
 * Administrator-opted-in schema inspection and sensitive raw database reads.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Database_Reader;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/** One typed, read-only database surface; no caller-supplied SQL. */
final class Database_Abilities {
	/** @var Permissions */
	private $permissions;
	/** @var Mutation_Log */
	private $log;

	/** @param Permissions $permissions Current delegation. @param Mutation_Log $log Existing activity store. */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/** @return array<int,object> */
	public function register() {
		$registered = array();
		foreach ( array( 'database-inspect', 'database-read' ) as $name ) {
			$raw          = 'database-read' === $name;
			$registered[] = wp_register_ability(
				'wp-ai-bridge/' . $name,
				array(
					'label'               => $raw ? __( 'Read Sensitive Database Values', 'wp-ai-bridge' ) : __( 'Inspect Installation Database', 'wp-ai-bridge' ),
					'description'         => $raw ? __( 'Read exact installation/plugin table bytes, including potential secrets, with bounded pages and hash-checked cell windows. Requires explicit Sensitive Database Read and Database Inspection; multisite requires Super Admin.', 'wp-ai-bridge' ) : __( 'Discover installation-prefixed base tables, columns and indexes through the current WordPress connection. No record/default values; multisite requires Super Admin.', 'wp-ai-bridge' ),
					'category'            => Registrar::CATEGORY,
					'input_schema'        => $this->schema( $raw ),
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => $raw ? array( $this, 'can_read' ) : array( $this, 'can_inspect' ),
					'execute_callback'    => $raw ? array( $this, 'read' ) : array( $this, 'inspect' ),
					'meta'                => array(
						'mcp'         => array(
							'public' => true,
							'type'   => 'tool',
						),
						'annotations' => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			);
		}
		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @param bool $raw Whether this schema returns stored values. @return array */
	private function schema( $raw ) {
		$identifier = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 64,
		);
		$natural    = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		$properties = array(
			'action' => array(
				'type' => 'string',
				'enum' => $raw ? array( 'rows', 'cell' ) : array( 'tables', 'schema' ),
			),
			'table'  => $identifier,
			'limit'  => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'offset' => $natural,
		);
		if ( $raw ) {
			$key         = array(
				'type'                 => 'object',
				'additionalProperties' => array( 'type' => array( 'string', 'null' ) ),
			);
			$properties += array(
				'columns'         => array(
					'type'        => 'array',
					'minItems'    => 1,
					'uniqueItems' => true,
					'items'       => $identifier,
				),
				'column'          => $identifier,
				'filters'         => $key,
				'key'             => $key,
				'key_hashes'      => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'    => 'string',
						'pattern' => '^[a-f0-9]{64}$',
					),
				),
				'after_id'        => array(
					'type'    => 'string',
					'pattern' => '^-?(0|[1-9][0-9]*)$',
				),
				'length'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_sha256' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
			);
		} else {
			$properties += array(
				'after_table' => array(
					'type'      => 'string',
					'maxLength' => 64,
				),
				'section'     => array(
					'type' => 'string',
					'enum' => array( 'columns', 'indexes' ),
				),
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/** @return bool */
	public function can_inspect() {
		return get_current_user_id() > 0 &&
			$this->permissions->allowed( Settings::GROUP_DATABASE_INSPECTION, 'manage_options' ) &&
			( ! is_multisite() || ( is_super_admin() && current_user_can( 'manage_network_options' ) ) );
	}

	/** @return bool */
	public function can_read() {
		return $this->can_inspect() && $this->permissions->allowed( Settings::GROUP_DATABASE_READ, 'manage_options' );
	}

	/** @param mixed $input Request. @return array|WP_Error */
	public function inspect( $input ) {
		return $this->execute( $input, false );
	}

	/** @param mixed $input Request. @return array|WP_Error */
	public function read( $input ) {
		return $this->execute( $input, true );
	}

	/** @param mixed $input Request. @param bool $raw Stored-value authority. @return array|WP_Error */
	private function execute( $input, $raw ) {
		if ( $raw ? ! $this->can_read() : ! $this->can_inspect() ) {
			return new WP_Error( 'database_access_denied', __( 'Enable the corresponding database grants in WP AI Bridge Settings and use a current WordPress administrator. Multisite also requires actual Super Admin and network authority.', 'wp-ai-bridge' ) );
		}
		$validated = $this->validate( $input, $raw );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$started = microtime( true );
		$reader  = new Database_Reader( max( 1, intdiv( Bounded_Payload::response_limit(), 16 ) ) );
		$result  = $reader->execute( $validated );
		if ( ! is_wp_error( $result ) ) {
			$result['elapsed_ms'] = round( ( microtime( true ) - $started ) * 1000, 3 );
			if ( ! Bounded_Payload::fits( $result ) ) {
				$result = new WP_Error( 'database_response_budget', __( 'This page exceeds the configured response budget. Request fewer records/columns or a shorter cell window, or adjust the response policy in WP AI Bridge Settings.', 'wp-ai-bridge' ) );
			}
		}
		if ( $raw && ! $this->log->record(
			'wp-ai-bridge/database-read',
			'db_' . $validated['action'] . '_' . substr( hash( 'sha256', $validated['table'] ), 0, 20 ),
			0,
			! is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() : ''
		) ) {
			return new WP_Error( 'database_audit_unavailable', __( 'The database read audit could not be saved. Restore WordPress option storage before retrying this read.', 'wp-ai-bridge' ) );
		}
		return $result;
	}

	/** @param mixed $input Request. @param bool $raw Stored-value surface. @return array|WP_Error */
	private function validate( $input, $raw ) {
		$actions = $raw ? array(
			'rows' => array( 'action', 'table', 'columns', 'limit', 'offset', 'after_id', 'filters' ),
			'cell' => array( 'action', 'table', 'column', 'key', 'key_hashes', 'offset', 'length', 'expected_sha256' ),
		) : array(
			'tables' => array( 'action', 'limit', 'after_table' ),
			'schema' => array( 'action', 'table', 'section', 'limit', 'offset' ),
		);
		if ( ! is_array( $input ) || ! isset( $input['action'] ) || ! is_string( $input['action'] ) ||
			! isset( $actions[ $input['action'] ] ) || array_diff( array_keys( $input ), $actions[ $input['action'] ] ) ) {
			return $this->invalid();
		}
		$max = max( 1, intdiv( Bounded_Payload::response_limit(), 2048 ) );
		foreach ( array( 'offset', 'length', 'limit' ) as $name ) {
			if ( array_key_exists( $name, $input ) && ( ! is_int( $input[ $name ] ) || $input[ $name ] < ( 'offset' === $name ? 0 : 1 ) ||
				$input[ $name ] > ( 'offset' === $name ? PHP_INT_MAX - $max : ( 'limit' === $name ? $max : intdiv( Bounded_Payload::response_limit(), 16 ) ) ) ) ) {
				return $this->invalid();
			}
		}
		if ( 'cell' !== $input['action'] ) {
			$input['limit'] = $input['limit'] ?? min( 25, $max );
		}
		foreach ( array( 'table', 'column' ) as $name ) {
			if ( ( 'table' === $name && 'tables' !== $input['action'] ) || ( 'column' === $name && 'cell' === $input['action'] ) ) {
				if ( ! isset( $input[ $name ] ) || ! $this->identifier( $input[ $name ] ) ) {
					return $this->invalid();
				}
			}
		}
		if ( array_key_exists( 'after_table', $input ) && ( ! is_string( $input['after_table'] ) || ! preg_match( '/^[^\x00-\x1f\x7f]{0,64}$/uD', $input['after_table'] ) ) ) {
			return $this->invalid();
		}
		if ( array_key_exists( 'section', $input ) && ! in_array( $input['section'], array( 'columns', 'indexes' ), true ) ) {
			return $this->invalid();
		}
		if ( array_key_exists( 'after_id', $input ) && ( ! is_string( $input['after_id'] ) || strlen( $input['after_id'] ) > 21 || 1 !== preg_match( '/^-?(0|[1-9][0-9]*)$/D', $input['after_id'] ) ) ) {
			return $this->invalid();
		}
		if ( 'rows' === $input['action'] ) {
			if ( empty( $input['columns'] ) || ! is_array( $input['columns'] ) || ! array_is_list( $input['columns'] ) ||
				count( $input['columns'] ) > $max ) {
				return $this->invalid();
			}
			foreach ( $input['columns'] as $column ) {
				if ( ! $this->identifier( $column ) ) {
					return $this->invalid();
				}
			}
			if ( count( array_unique( $input['columns'] ) ) !== count( $input['columns'] ) ) {
				return $this->invalid();
			}
			if ( ( $input['limit'] + 1 ) * count( $input['columns'] ) > intdiv( Bounded_Payload::response_limit(), 512 ) ) {
				return new WP_Error( 'database_response_budget', __( 'This page exceeds the configured response budget. Request fewer records/columns or a shorter cell window, or adjust the response policy in WP AI Bridge Settings.', 'wp-ai-bridge' ) );
			}
		}
		if ( 'cell' === $input['action'] && empty( $input['key'] ) && empty( $input['key_hashes'] ) ) {
			return $this->invalid();
		}
		$total_key_bytes = 0;
		foreach ( array( 'filters', 'key' ) as $name ) {
			if ( array_key_exists( $name, $input ) ) {
				if ( ! is_array( $input[ $name ] ) || count( $input[ $name ] ) > $max ) {
					return $this->invalid();
				}
				foreach ( $input[ $name ] as $column => $value ) {
					if ( ! $this->identifier( $column ) || ( null !== $value && ( ! is_string( $value ) ||
						strlen( $value ) > Bounded_Payload::response_limit() || false === base64_decode( $value, true ) ||
						base64_encode( base64_decode( $value, true ) ) !== $value ) ) ) {
						return $this->invalid();
					}
					$total_key_bytes += strlen( (string) $value ) + strlen( $column );
					if ( $total_key_bytes > Bounded_Payload::response_limit() ) {
						return $this->invalid();
					}
				}
			}
		}
		if ( array_key_exists( 'key_hashes', $input ) ) {
			if ( ! is_array( $input['key_hashes'] ) || count( $input['key_hashes'] ) > $max ) {
				return $this->invalid();
			}
			foreach ( $input['key_hashes'] as $column => $hash ) {
				if ( ! $this->identifier( $column ) || ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
					return $this->invalid();
				}
				$total_key_bytes += strlen( $column ) + 64;
				if ( $total_key_bytes > Bounded_Payload::response_limit() ) {
					return $this->invalid();
				}
			}
		}
		if ( array_key_exists( 'expected_sha256', $input ) && ( ! is_string( $input['expected_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['expected_sha256'] ) ) ) {
			return $this->invalid();
		}
		if ( 'cell' === $input['action'] && ! empty( $input['offset'] ) && ! isset( $input['expected_sha256'] ) ) {
			return $this->invalid();
		}
		return $input;
	}

	/** @param mixed $value Exact native identifier. @return bool */
	private function identifier( $value ) {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= 256 &&
			1 === preg_match( '/^[^\x00-\x1f\x7f]{1,64}$/uD', $value );
	}

	/** @return WP_Error */
	private function invalid() {
		return new WP_Error( 'database_input_invalid', __( 'Use the action-specific database schema: exact table/columns, canonical base64 filter/key values, finite page/window sizes within the response policy, and the previous SHA-256 for cell continuation.', 'wp-ai-bridge' ) );
	}
}
