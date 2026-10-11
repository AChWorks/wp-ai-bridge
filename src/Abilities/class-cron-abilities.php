<?php
/**
 * Safe, bounded native WP-Cron inventory.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Read-only inventory with source-bound pagination and opaque event identity.
 *
 * WordPress stores all cron events in one option; this class never exposes
 * serialized callback arguments or supplies a generic callback executor.
 */
final class Cron_Abilities {
	const PAGE_LIMIT = 25;

	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Existing Bridge grant enforcement. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/cron-events-read',
			array(
				'label'               => __( 'Inspect Scheduled WordPress Events', 'wp-ai-bridge' ),
				'description'         => __( 'Reads bounded WP-Cron event metadata with opaque identities, verified inventory pagination and no callback arguments or execution.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'action'            => array(
							'type' => 'string',
							'enum' => array( 'list', 'get' ),
						),
						'event_id'          => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
						'hook'              => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'offset'            => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'limit'             => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::PAGE_LIMIT,
						),
						'expected_snapshot' => array(
							'type'      => 'string',
							'minLength' => 64,
							'maxLength' => 64,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'can_read' ),
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

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */
	public function can_read() {
		if ( ! $this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'manage_options' ) ) {
			return false;
		}
		// A network-wide plugin's Cron jobs can carry network effects. Do not
		// grant a multisite site administrator inventory access by implication.
		return ! is_multisite() || current_user_can( 'manage_network_options' );
	}

	/**
	 * @param array<string,mixed> $input Source-bound selector.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input = array() ) {
		if ( ! $this->can_read() ) {
			return new WP_Error( 'cron_read_denied', __( 'Site Configuration and WordPress administrator permissions are required.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'cron_invalid_input', __( 'Invalid Cron inventory selector.', 'wp-ai-bridge' ) );
		}
		$action = isset( $input['action'] ) ? $input['action'] : 'list';
		if ( ! is_string( $action ) || ! in_array( $action, array( 'list', 'get' ), true ) ) {
			return new WP_Error( 'cron_invalid_input', __( 'Invalid Cron inventory selector.', 'wp-ai-bridge' ) );
		}
		$allowed = 'get' === $action ? array( 'action', 'event_id' ) : array( 'action', 'offset', 'limit', 'hook', 'expected_snapshot' );
		if ( array_diff( array_keys( $input ), $allowed ) ) {
			return new WP_Error( 'cron_invalid_input', __( 'Invalid Cron inventory selector.', 'wp-ai-bridge' ) );
		}
		$offset   = $input['offset'] ?? 0;
		$limit    = $input['limit'] ?? 10;
		$hook     = $input['hook'] ?? '';
		$id       = $input['event_id'] ?? '';
		$expected = $input['expected_snapshot'] ?? '';
		if ( 'list' === $action && (
			! is_int( $offset ) || $offset < 0 || ! is_int( $limit ) ||
			$limit < 1 || $limit > self::PAGE_LIMIT || ! is_string( $hook ) ||
			strlen( $hook ) > 200 || ! is_string( $expected ) ||
			( '' !== $expected && ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) )
		) ) {
			return new WP_Error( 'cron_invalid_input', __( 'Invalid Cron inventory selector.', 'wp-ai-bridge' ) );
		}
		if ( 'get' === $action && ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{64}$/D', $id ) ) ) {
			return new WP_Error( 'cron_invalid_input', __( 'Invalid Cron event identity.', 'wp-ai-bridge' ) );
		}

		$raw = function_exists( '_get_cron_array' ) ? _get_cron_array() : false;
		if ( false !== $raw && ! is_array( $raw ) ) {
			return new WP_Error( 'cron_inventory_unavailable', __( 'Native WordPress Cron inventory was unavailable.', 'wp-ai-bridge' ) );
		}


		// WordPress itself loads the native Cron option as an array. Do not
		// amplify that unavoidable cost by accumulating and sorting a second
		// copy of every event just to return a small result page.
		$rows          = array();
		$matching      = 0;
		$exact         = null;
		$now           = time();
		$site_id       = get_current_blog_id();
		$salt          = wp_salt( 'auth' );
		$snapshot_hash = hash_init( 'sha256', HASH_HMAC, $salt );
		hash_update( $snapshot_hash, 'wpai-cron-inventory-v2|' . $site_id . '|' );

		// Native WordPress Cron preserves the order of its stored timestamp,
		// hook and event keys. A change in this ordering invalidates a
		// continuation conservatively via expected_snapshot.
		foreach ( (array) $raw as $timestamp => $hooks ) {
			if ( ! ctype_digit( (string) $timestamp ) || ! is_array( $hooks ) ) {
				return new WP_Error( 'cron_inventory_invalid', __( 'Native Cron inventory contains unexpected data.', 'wp-ai-bridge' ) );
			}
			foreach ( $hooks as $name => $events ) {
				if ( ! is_string( $name ) || ! is_array( $events ) || 1 !== preg_match( '//u', $name ) ) {
					return new WP_Error( 'cron_inventory_invalid', __( 'Native Cron inventory contains unexpected event data.', 'wp-ai-bridge' ) );
				}
				foreach ( $events as $key => $event ) {
					if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{32}$/D', $key ) ||
						! is_array( $event ) || ! isset( $event['args'] ) || ! is_array( $event['args'] ) ||
						! array_key_exists( 'schedule', $event ) ||
						( false !== $event['schedule'] && ! is_string( $event['schedule'] ) ) ||
						( isset( $event['interval'] ) && ( ! is_int( $event['interval'] ) || $event['interval'] < 0 ) ) ) {
						return new WP_Error( 'cron_inventory_invalid', __( 'Native Cron inventory contains an invalid event.', 'wp-ai-bridge' ) );
					}
					$recurrence = false === $event['schedule'] ? '' : $event['schedule'];
					if ( 1 !== preg_match( '//u', $recurrence ) ) {
						return new WP_Error( 'cron_inventory_invalid', __( 'Native Cron inventory contains an invalid event.', 'wp-ai-bridge' ) );
					}
					$interval = isset( $event['interval'] ) ? $event['interval'] : 0;
					$identity = hash_hmac( 'sha256', $site_id . '|' . $timestamp . '|' . $name . '|' . $key, $salt );

					// Length-delimited serialized scalar metadata. Arguments
					// and their values never enter the result or snapshot.
					hash_update( $snapshot_hash, serialize( array( $identity, $recurrence, $interval ) ) );

					if ( 'get' === $action ) {
						if ( hash_equals( $identity, $id ) ) {
							$exact = $this->event_row( $identity, $timestamp, $name, $recurrence, $interval, count( $event['args'] ), $now );
						}
						continue;
					}
					if ( '' !== $hook && $name !== $hook ) {
						continue;
					}
					if ( $matching >= $offset && count( $rows ) < $limit ) {
						$rows[] = $this->event_row( $identity, $timestamp, $name, $recurrence, $interval, count( $event['args'] ), $now );
					}
					++$matching;
				}
			}
		}
		$snapshot = hash_final( $snapshot_hash );

		if ( 'get' === $action ) {
			return null !== $exact
				? $this->result( array( $exact ), 1, false, 1, $snapshot )
				: new WP_Error( 'cron_event_not_found', __( 'The selected Cron event no longer exists.', 'wp-ai-bridge' ) );
		}
		if ( '' !== $expected && ! hash_equals( $expected, $snapshot ) ) {
			return new WP_Error( 'cron_stale_snapshot', __( 'The Cron inventory changed. Restart pagination from offset zero.', 'wp-ai-bridge' ) );
		}
		return $this->result( $rows, $matching, $offset + count( $rows ) < $matching, $offset + count( $rows ), $snapshot );
	}

	/**
	 * Construct sensitive-argument-free output only for selected page entries.
	 *
	 * @return array<string,mixed>
	 */
	private function event_row( $identity, $timestamp, $name, $recurrence, $interval, $args_count, $now ) {
		$visible_hook = $name;
		while ( strlen( $visible_hook ) > 200 ) {
			$visible_hook = substr( $visible_hook, 0, 200 );
			while ( '' !== $visible_hook && 1 !== preg_match( '//u', $visible_hook ) ) {
				$visible_hook = substr( $visible_hook, 0, -1 );
			}
		}
		$visible_recurrence = substr( $recurrence, 0, 80 );
		while ( '' !== $visible_recurrence && 1 !== preg_match( '//u', $visible_recurrence ) ) {
			$visible_recurrence = substr( $visible_recurrence, 0, -1 );
		}
		$when = (int) $timestamp;
		return array(
			'event_id'              => $identity,
			'hook'                  => $visible_hook,
			'hook_truncated'        => strlen( $name ) > 200,
			'next_run_utc'          => gmdate( 'c', $when ),
			'next_run_timestamp'    => $when,
			'recurrence'            => $visible_recurrence,
			'recurrence_truncated'  => strlen( $recurrence ) > 80,
			'interval_seconds'      => $interval,
			'arguments_count'       => $args_count,
			'callback_registered'   => false !== has_action( $name ),
			'overdue'               => $when < $now,
		);
	}

	/** @return array<string,mixed> */
	private function result( $items, $total, $has_more, $next_offset, $snapshot ) {
		return array(
			'items'                     => array_values( $items ),
			'total'                     => $total,
			'has_more'                  => $has_more,
			'next_offset'               => $next_offset,
			'snapshot'                  => $snapshot,
			'wordpress_cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'external_trigger_verified' => false,
		);
	}

	/** @return array<string,mixed> */
	private function output_schema() {
		$entry = array(
			'type'                 => 'object',
			'properties'           => array(
				'event_id'            => array( 'type' => 'string' ),
				'hook'                => array( 'type' => 'string' ),
				'hook_truncated'      => array( 'type' => 'boolean' ),
				'next_run_utc'        => array( 'type' => 'string' ),
				'next_run_timestamp'  => array( 'type' => 'integer' ),
				'recurrence'          => array( 'type' => 'string' ),
				'recurrence_truncated' => array( 'type' => 'boolean' ),
				'arguments_count'     => array( 'type' => 'integer' ),
				'interval_seconds'    => array( 'type' => 'integer' ),
				'callback_registered' => array( 'type' => 'boolean' ),
				'overdue'             => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'event_id', 'hook', 'hook_truncated', 'next_run_utc', 'next_run_timestamp', 'recurrence', 'recurrence_truncated', 'interval_seconds', 'arguments_count', 'callback_registered', 'overdue' ),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items'                     => array(
					'type'  => 'array',
					'items' => $entry,
				),
				'total'                     => array( 'type' => 'integer' ),
				'has_more'                  => array( 'type' => 'boolean' ),
				'next_offset'               => array( 'type' => 'integer' ),
				'snapshot'                  => array( 'type' => 'string' ),
				'wordpress_cron_disabled'   => array( 'type' => 'boolean' ),
				'external_trigger_verified' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'items', 'total', 'has_more', 'next_offset', 'snapshot', 'wordpress_cron_disabled', 'external_trigger_verified' ),
			'additionalProperties' => false,
		);
	}
}
