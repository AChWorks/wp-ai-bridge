<?php
/**
 * Explicitly server-enabled native database-account SQL.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Administration_Access;
use WP_AI_Bridge\Support\Database_Native_SQL_Operation;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/** This distinct high-trust surface makes no installation-confinement claim. */
final class Database_Native_SQL_Abilities {
	/** @var Administration_Access */
	private $access;
	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Existing current delegation. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
		$this->access      = new Administration_Access( $permissions );
	}

	/** @return array */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/database-native-sql',
			array(
				'label'               => __( 'Execute Native Database Account SQL', 'wp-ai-bridge' ),
				'description'         => __( 'Server-enabled native-account SQL, NOT installation-confined. All Database grants, Schema and Native SQL consent plus explicit account-scope acknowledgement are required. Native privileges can reach other schemas, server files, routines and Bridge control storage. Prepare a one-use token, execute one prepared native statement, and read exact private result windows. Recovery is conditional when SQL changes its control store; never blindly resend.', 'wp-ai-bridge' ),
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
		if ( ! defined( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL' ) || true !== WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL ) {
			return false;
		}
		$input = $input instanceof \stdClass ? (array) $input : $input;
		if ( ! is_array( $input ) || true !== ( $input['acknowledge_native_account_scope'] ?? null ) ) {
			return false;
		}
		$requirements = array();
		foreach ( array(
			Settings::GROUP_DATABASE_INSPECTION,
			Settings::GROUP_DATABASE_READ,
			Settings::GROUP_DATABASE_WRITE,
			Settings::GROUP_DATABASE_DELETE,
			Settings::GROUP_DATABASE_SCHEMA,
		) as $group ) {
			$requirements[] = array( $group, 'manage_options' );
		}
		return $this->access->allowed( Settings::GROUP_DATABASE_SQL, is_multisite(), $requirements );
	}

	/** @param mixed $input Public request. @return array|WP_Error */
	public function execute( $input ) {
		if ( $input instanceof \stdClass && 'stdClass' === get_class( $input ) ) {
			$input = (array) $input;
		}
		if ( ! is_array( $input ) || ! $this->can_execute( $input ) ) {
			return new WP_Error( 'database_native_sql_denied', __( 'Native-account SQL requires explicit server enablement, all current database consents, actual administrator/network authority and account-scope acknowledgement.', 'wp-ai-bridge' ) );
		}
		$origin = $this->access->context();
		return ( new Database_Native_SQL_Operation( $this->permissions ) )->execute(
			$input,
			function () use ( $origin, $input ) {
				return $this->can_execute( $input ) && $this->access->same_context( $origin );
			}
		);
	}

	/** @return array Closed public statement/preparation/result contracts. */
	private function schema() {
		$digest     = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		$properties = array(
			'action'                           => array(
				'type' => 'string',
				'enum' => array( 'prepare', 'execute', 'recover', 'result_status', 'result_read', 'result_release' ),
			),
			'operation_id'                     => array(
				'type'    => 'string',
				'pattern' => '^[A-Za-z0-9_.:-]{8,128}$',
			),
			'acknowledge_native_account_scope' => array(
				'type' => 'boolean',
				'enum' => array( true ),
			),
			'statement_base64'                 => array(
				'type'        => 'string',
				'description' => 'One complete native prepared statement as canonical Base64; no multi_query or automatic resend.',
			),
			'parameters'                       => array(
				'type'        => 'array',
				'items'       => array( 'type' => array( 'string', 'null' ) ),
				'description' => 'Ordered exact canonical Base64 scalar bytes or SQL NULL. Numeric JSON values are not accepted.',
			),
			'max_result_bytes'                 => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Finite private result reservation admitted before dispatch. Overflow produces an incomplete result and does not roll back acknowledged effects.',
			),
			'preparation_token'                => $digest,
			'result_id'                        => $digest,
			'offset'                           => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'length'                           => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'expected_sha256'                  => $digest,
		);
		$variants   = array();
		$common     = array( 'action', 'operation_id', 'acknowledge_native_account_scope' );
		$forms      = array(
			'prepare'        => array( 'statement_base64', 'parameters', 'max_result_bytes' ),
			'execute'        => array( 'statement_base64', 'parameters', 'max_result_bytes', 'preparation_token' ),
			'recover'        => array(),
			'result_status'  => array( 'result_id' ),
			'result_read'    => array( 'result_id', 'offset', 'length', 'expected_sha256' ),
			'result_release' => array( 'result_id' ),
		);
		foreach ( $forms as $action => $fields ) {
			$all                        = array_merge( $common, $fields );
			$selected                   = array_intersect_key( $properties, array_flip( $all ) );
			$selected['action']['enum'] = array( $action );
			$required                   = $all;
			if ( 'result_read' === $action ) {
				$required = array_diff( $required, array( 'length', 'expected_sha256' ) );
			}
			$variants[] = array(
				'type'                 => 'object',
				'properties'           => $selected,
				'required'             => array_values( $required ),
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $common,
			'additionalProperties' => false,
			'oneOf'                => $variants,
		);
	}
}
