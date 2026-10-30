<?php
/**
 * Separately delegated typed database schema administration.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Administration_Access;
use WP_AI_Bridge\Support\Database_Schema_Operation;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/** One typed DDL boundary, independent of ordinary typed row writes. */
final class Database_Schema_Abilities {
	/** @var Administration_Access */
	private $access;

	/** @param Permissions $permissions Existing current delegation engine. */
	public function __construct( Permissions $permissions ) {
		$this->access = new Administration_Access( $permissions );
	}

	/** @return array */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/database-schema',
			array(
				'label'               => __( 'Manage Database Schema', 'wp-ai-bridge' ),
				'description'         => __( 'Execute separately delegated typed installation table, column, index and constraint changes with observed preconditions and one-dispatch recovery. DDL is not a transactional rollback or atomic schema CAS. Protected control-table dependencies remain unavailable.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->schema(),
				'output_schema'       => array( 'type' => 'object' ),
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

	/** @param mixed $input Public request. @return bool */
	public function can_execute( $input = array() ) {
		$input = $input instanceof \stdClass ? (array) $input : $input;
		if ( ! is_array( $input ) ) {
			return false;
		}
		$requirements = array(
			array( Settings::GROUP_DATABASE_INSPECTION, 'manage_options' ),
			array( Settings::GROUP_DATABASE_READ, 'manage_options' ),
			array( Settings::GROUP_DATABASE_WRITE, 'manage_options' ),
		);
		$operation    = isset( $input['operation'] ) ? (array) $input['operation'] : array();
		if ( 'recover' === ( $input['action'] ?? '' ) ||
			( 'execute' === ( $input['action'] ?? '' ) && ! in_array( $operation['action'] ?? '', array( 'create_table', 'add_column', 'add_index', 'add_foreign_key' ), true ) ) ) {
			$requirements[] = array( Settings::GROUP_DATABASE_DELETE, 'manage_options' );
		}
		return $this->access->allowed( Settings::GROUP_DATABASE_SCHEMA, is_multisite(), $requirements );
	}

	/** @param mixed $input Public request. @return array|WP_Error */
	public function execute( $input ) {
		if ( $input instanceof \stdClass && 'stdClass' === get_class( $input ) ) {
			$input = (array) $input;
		}
		if ( ! is_array( $input ) || ! $this->can_execute( $input ) ) {
			return new WP_Error( 'database_schema_denied', __( 'Current separate Schema consent and all required native administrator/database grants are required.', 'wp-ai-bridge' ) );
		}
		$origin = $this->access->context();
		return ( new Database_Schema_Operation() )->execute(
			$input,
			function () use ( $input, $origin ) {
				return $this->can_execute( $input ) && $this->access->same_context( $origin );
			}
		);
	}

	/** @return array Closed action-specific public schema. */
	private function schema() {
		$id            = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 64,
		);
		$digest        = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		$operation_id  = array(
			'type'    => 'string',
			'pattern' => '^[A-Za-z0-9_.:-]{8,128}$',
		);
		$column        = array(
			'type'                 => 'object',
			'properties'           => array(
				'name'           => $id,
				'type'           => array(
					'type' => 'string',
					'enum' => array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'bit', 'char', 'varchar', 'binary', 'varbinary', 'tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'date', 'datetime', 'timestamp', 'time', 'year', 'enum', 'set', 'json', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' ),
				),
				'length'         => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'precision'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'scale'          => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'nullable'       => array( 'type' => 'boolean' ),
				'unsigned'       => array( 'type' => 'boolean' ),
				'auto_increment' => array( 'type' => 'boolean' ),
				'default'        => array(
					'type'        => array( 'string', 'null' ),
					'description' => 'Canonical Base64 literal bytes, or SQL NULL; omission preserves native no-default semantics.',
				),
				'members'        => array(
					'type'     => 'array',
					'minItems' => 1,
					'items'    => array( 'type' => 'string' ),
				),
				'charset'        => $id,
				'collation'      => $id,
				'comment'        => array( 'type' => 'string' ),
			),
			'required'             => array( 'name', 'type', 'nullable' ),
			'additionalProperties' => false,
		);
		$index_columns = array(
			'type'     => 'array',
			'minItems' => 1,
			'items'    => array(
				'oneOf' => array(
					$id,
					array(
						'type'                 => 'object',
						'properties'           => array(
							'name'   => $id,
							'prefix' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						'required'             => array( 'name', 'prefix' ),
						'additionalProperties' => false,
					),
				),
			),
		);
		$index         = array(
			'type'                 => 'object',
			'properties'           => array(
				'name'    => $id,
				'kind'    => array(
					'type' => 'string',
					'enum' => array( 'index', 'unique', 'primary', 'fulltext', 'spatial' ),
				),
				'columns' => $index_columns,
			),
			'required'             => array( 'name', 'kind', 'columns' ),
			'additionalProperties' => false,
		);
		$key_columns   = array(
			'type'     => 'array',
			'minItems' => 1,
			'items'    => $id,
		);
		$key           = array(
			'type'                 => 'object',
			'properties'           => array(
				'name'              => $id,
				'columns'           => $key_columns,
				'reference_table'   => $id,
				'reference_columns' => $key_columns,
				'on_delete'         => array(
					'type' => 'string',
					'enum' => array( 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION' ),
				),
				'on_update'         => array(
					'type' => 'string',
					'enum' => array( 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION' ),
				),
			),
			'required'             => array( 'name', 'columns', 'reference_table', 'reference_columns' ),
			'additionalProperties' => false,
		);
		$forms         = array(
			'create_table'     => array(
				'columns'      => array(
					'type'     => 'array',
					'minItems' => 1,
					'items'    => $column,
				),
				'indexes'      => array(
					'type'  => 'array',
					'items' => $index,
				),
				'foreign_keys' => array(
					'type'  => 'array',
					'items' => $key,
				),
				'charset'      => $id,
				'collation'    => $id,
			),
			'add_column'       => array(
				'column' => $column,
				'first'  => array( 'type' => 'boolean' ),
				'after'  => $id,
			),
			'modify_column'    => array(
				'column' => $column,
				'first'  => array( 'type' => 'boolean' ),
				'after'  => $id,
			),
			'drop_column'      => array( 'name' => $id ),
			'rename_column'    => array(
				'name'     => $id,
				'new_name' => $id,
			),
			'add_index'        => array( 'index' => $index ),
			'drop_index'       => array( 'name' => $id ),
			'add_foreign_key'  => array( 'foreign_key' => $key ),
			'drop_foreign_key' => array( 'name' => $id ),
			'rename_table'     => array(
				'new_name'                    => $id,
				'expected_destination_absent' => array(
					'type' => 'boolean',
					'enum' => array( true ),
				),
			),
			'drop_table'       => array(),
			'truncate_table'   => array(),
		);
		$required      = array(
			'create_table'     => array( 'columns' ),
			'add_column'       => array( 'column' ),
			'modify_column'    => array( 'column' ),
			'drop_column'      => array( 'name' ),
			'rename_column'    => array( 'name', 'new_name' ),
			'add_index'        => array( 'index' ),
			'drop_index'       => array( 'name' ),
			'add_foreign_key'  => array( 'foreign_key' ),
			'drop_foreign_key' => array( 'name' ),
			'rename_table'     => array( 'new_name', 'expected_destination_absent' ),
			'drop_table'       => array(),
			'truncate_table'   => array(),
		);
		$variants      = array();
		foreach ( $forms as $action => $properties ) {
			$properties = array_merge(
				array(
					'action'               => array(
						'type' => 'string',
						'enum' => array( $action ),
					),
					'table'                => $id,
					'expected_schema_hash' => 'create_table' === $action ? array( 'type' => 'null' ) : $digest,
				),
				$properties
			);
			$variants[] = array(
				'type'                 => 'object',
				'properties'           => $properties,
				'required'             => array_merge( array( 'action', 'table', 'expected_schema_hash' ), $required[ $action ] ),
				'additionalProperties' => false,
			);
		}
		$operation = array( 'oneOf' => $variants );
		$root      = array(
			'action'       => array(
				'type' => 'string',
				'enum' => array( 'describe', 'execute', 'recover' ),
			),
			'table'        => $id,
			'operation_id' => $operation_id,
			'operation'    => $operation,
		);
		$actions   = array();
		foreach ( array(
			'describe' => array( 'action', 'table' ),
			'execute'  => array( 'action', 'operation_id', 'operation' ),
			'recover'  => array( 'action', 'operation_id' ),
		) as $action => $fields ) {
			$properties                   = array_intersect_key( $root, array_flip( $fields ) );
			$properties['action']['enum'] = array( $action );
			$actions[]                    = array(
				'type'                 => 'object',
				'properties'           => $properties,
				'required'             => $fields,
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $root,
			'required'             => array( 'action' ),
			'additionalProperties' => false,
			'oneOf'                => $actions,
		);
	}
}
