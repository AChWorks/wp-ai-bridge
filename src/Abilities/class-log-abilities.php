<?php
/**
 * Explicit sensitive-log access through the single scoped filesystem.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Administration_Access;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Hook_Dispatch_Recovery;
use WP_AI_Bridge\Support\Log_Reader;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/** Exact selected bytes never enter the general Activity or error channel. */
final class Log_Abilities {
	/** @var Administration_Access */
	private $access;
	/** @var Log_Reader */
	private $reader;
	/** @param Permissions $permissions Delegated authority. */
	public function __construct( Permissions $permissions ) {
		$this->access = new Administration_Access( $permissions );
		$this->reader = new Log_Reader( $permissions );
	}
	/** @return array */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/logs-read',
			array(
				'label'               => __( 'Read Authorized Logs', 'wp-ai-bridge' ),
				'description'         => __( 'Reads exact bounded bytes or a backwards tail from an approved sensitive-log root. Continuations verify observed file and boundary identity; they are not immutable snapshots.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'action'    => array(
							'type' => 'string',
							'enum' => array( 'sources', 'read', 'tail' ),
						),
						'root_id'   => array( 'type' => 'string' ),
						'path'      => array(
							'type'  => array( 'string', 'array' ),
							'items' => array( 'type' => 'string' ),
						),
						'offset'    => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'max_bytes' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'lines'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'cursor'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'action' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->output_schema(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute' ),
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
		return $registered;
	}
	/** @return array Exact byte response and configured-source metadata. */
	private function output_schema() {
		$properties = array();
		foreach ( array( 'root_id', 'data_base64', 'sha256', 'identity', 'cursor', 'continuity', 'provider_logs' ) as $name ) {
			$properties[ $name ] = array( 'type' => 'string' );
		}
		foreach ( array( 'offset', 'next_offset', 'observed_size', 'bytes', 'inspected_bytes' ) as $name ) {
			$properties[ $name ] = array( 'type' => 'integer' );
		}
		foreach ( array( 'eof', 'limited', 'leading_partial', 'redacted' ) as $name ) {
			$properties[ $name ] = array( 'type' => 'boolean' );
		}
		$properties['roots']   = array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'            => array( 'type' => 'string' ),
					'label'         => array( 'type' => 'string' ),
					'scope'         => array( 'type' => 'string' ),
					'root_revision' => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
		);
		$properties['sources'] = array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'source'    => array( 'type' => 'string' ),
					'available' => array( 'type' => 'boolean' ),
					'reason'    => array( 'type' => 'string' ),
					'root_id'   => array( 'type' => 'string' ),
					'path'      => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}
	/** @return bool */
	public function can_read() {
		try {
			return Hook_Dispatch_Recovery::run(
				function () {
					return $this->access->allowed( Settings::GROUP_LOGS_READ, false );
				}
			);
		} catch ( \Throwable $error ) {
			return false;
		}
	}
	/** @param mixed $input Closed request. @return array|WP_Error */
	public function execute( $input ) {
		if ( ! is_array( $input ) || ! in_array( $input['action'] ?? null, array( 'sources', 'read', 'tail' ), true ) ||
			array_diff( array_keys( $input ), array( 'action', 'root_id', 'path', 'offset', 'max_bytes', 'lines', 'cursor' ) ) ) {
			return $this->error( 'logs_invalid' );
		}
		$action = $input['action'];
		if ( 'sources' === $action && 1 !== count( $input ) ) {
			return $this->error( 'logs_invalid' );
		}
		if ( 'sources' !== $action ) {
			if ( ! is_string( $input['root_id'] ?? null ) || '' === $input['root_id'] ||
				! isset( $input['path'] ) || ( ! is_string( $input['path'] ) && ! is_array( $input['path'] ) ) ||
				( is_array( $input['path'] ) && ( ! array_is_list( $input['path'] ) || array_filter(
					$input['path'],
					static function ( $part ) {
						return ! is_string( $part ); }
				) ) ) ||
				( array_key_exists( 'cursor', $input ) && ( ! is_string( $input['cursor'] ) || strlen( $input['cursor'] ) > Bounded_Payload::window_limit() || 'read' !== $action || array_key_exists( 'offset', $input ) ) ) ||
				( 'tail' === $action && array_key_exists( 'offset', $input ) ) ||
				( 'read' === $action && array_key_exists( 'lines', $input ) ) ) {
				return $this->error( 'logs_invalid' );
			}
			foreach ( array(
				'offset'    => 0,
				'max_bytes' => 1,
				'lines'     => 1,
			) as $key => $min ) {
				if ( array_key_exists( $key, $input ) && ( ! is_int( $input[ $key ] ) || $input[ $key ] < $min ) ) {
					return $this->error( 'logs_invalid' );
				}
			}
		}
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $input, $action ) {
					$origin = $this->access->context();
					if ( ! $this->access->allowed( Settings::GROUP_LOGS_READ, false ) || ! $this->access->same_context( $origin ) ) {
							return $this->error( 'logs_denied' );
					}
					$result = 'sources' === $action ? $this->reader->sources() : $this->reader->read( $input, $origin );
					if ( ! $this->access->allowed( Settings::GROUP_LOGS_READ, false ) || ! $this->access->same_context( $origin ) ) {
						return $this->error( 'logs_context_changed' );
					}
					return is_wp_error( $result ) || Bounded_Payload::fits( $result ) ? $result : $this->error( 'logs_response_capacity' );
				}
			);
		} catch ( \Throwable $error ) {
			return $this->error( 'logs_unavailable' );
		}
	}
	/** @param string $code Fixed error. @return WP_Error */
	private function error( $code ) {
		return new WP_Error( $code, __( 'The selected log operation is unavailable under the current input, scope or authority.', 'wp-ai-bridge' ) );
	}
}
