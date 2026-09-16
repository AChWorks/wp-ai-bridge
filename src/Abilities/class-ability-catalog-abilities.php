<?php
/**
 * Read-only inspection of public native Ability contracts.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Native_Ability_Delegation;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Paginates the native registry without invoking provider callbacks.
 */
final class Ability_Catalog_Abilities {
	const MAX_RESPONSE_BYTES = 1048576;

	/** @var Ability_Resolver */
	private $resolver;

	/** @var Permissions */
	private $permissions;

	/** @var Native_Ability_Delegation|null */
	private $native_ability_delegation;

	/**
	 * Creates the existing-registry inspection provider.
	 *
	 * @param Ability_Resolver                $resolver                  Shared exposure-policy resolver.
	 * @param Permissions                     $permissions               Bridge permission service.
	 * @param Native_Ability_Delegation|null $native_ability_delegation Authoritative Bridge provenance service.
	 */
	public function __construct( Ability_Resolver $resolver, Permissions $permissions, ?Native_Ability_Delegation $native_ability_delegation = null ) {
		$this->resolver                  = $resolver;
		$this->permissions               = $permissions;
		$this->native_ability_delegation = $native_ability_delegation;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/abilities-read',
			array(
				'label'               => __( 'Read Ability Contracts', 'wp-ai-bridge' ),
				'description'         => __( 'Lists public native Ability contracts with pagination or reads one exact contract. Discovery does not execute operations or establish target permissions.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'read' ),
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

	/** @return bool Whether public contract inspection is allowed. */
	public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
	}

	/**
	 * Reads public registry data, not execution or permission-callback results.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read( $input ) {
		if ( ! $this->can_read() ) {
			return new WP_Error( 'ability_catalog_permission_denied', __( 'Site Read access and the WordPress read capability are required to inspect Ability contracts.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) ) {
			return $this->invalid_input();
		}
		$action = isset( $input['action'] ) ? $input['action'] : 'list';
		if ( ! in_array( $action, array( 'list', 'get' ), true ) ) {
			return $this->invalid_input();
		}
		if ( ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error( 'ability_registry_unavailable', __( 'The WordPress Abilities registry is not available.', 'wp-ai-bridge' ) );
		}

		if ( 'get' === $action ) {
			if ( empty( $input['name'] ) || ! is_string( $input['name'] ) ) {
				return $this->invalid_input();
			}
			$ability = wp_get_ability( $input['name'] );
			if ( ! $this->resolver->is_mcp_exposed( $ability ) || $ability->get_name() !== $input['name'] ) {
				return new WP_Error( 'ability_contract_not_found', __( 'The requested public Ability contract is not available.', 'wp-ai-bridge' ) );
			}
			if ( ! method_exists( $ability, 'get_output_schema' ) ) {
				return $this->invalid_contract();
			}
			$item                  = $this->summary( $ability );
			$item['input_schema']  = $ability->get_input_schema();
			$item['output_schema'] = $ability->get_output_schema();
			if ( ! is_array( $item['input_schema'] ) || ! is_array( $item['output_schema'] ) ) {
				return $this->invalid_contract();
			}
			return $this->result( array( $item ), 1, 1, 1 );
		}

		$page      = isset( $input['page'] ) ? $input['page'] : 1;
		$per_page  = isset( $input['per_page'] ) ? $input['per_page'] : 25;
		$namespace = isset( $input['namespace'] ) ? $input['namespace'] : '';
		$search    = isset( $input['search'] ) ? $input['search'] : '';
		if ( ! is_int( $page ) || $page < 1 || ! is_int( $per_page ) || $per_page < 1 || $per_page > 100
			|| ! is_string( $namespace ) || ! is_string( $search ) ) {
			return $this->invalid_input();
		}
		$abilities = wp_get_abilities();
		if ( ! is_array( $abilities ) ) {
			return $this->invalid_contract();
		}
		$matches = array();
		foreach ( $abilities as $ability ) {
			if ( ! $this->resolver->is_mcp_exposed( $ability ) ) {
				continue;
			}
			$name = $ability->get_name();
			if ( '' !== $namespace && explode( '/', $name, 2 )[0] !== $namespace ) {
				continue;
			}
			if ( '' !== $search && false === stripos( $name . ' ' . $ability->get_label() . ' ' . $ability->get_description(), $search ) ) {
				continue;
			}
			$matches[ $name ] = $ability;
		}
		ksort( $matches, SORT_STRING );
		$total = count( $matches );
		$items = array();
		// Check the page before multiplying so an out-of-range integer cannot overflow.
		if ( $page <= (int) ceil( $total / $per_page ) ) {
			foreach ( array_slice( $matches, ( $page - 1 ) * $per_page, $per_page ) as $ability ) {
				$items[] = $this->summary( $ability );
			}
		}
		return $this->result( $items, $page, $per_page, $total );
	}

	/**
	 * Selects only public contract fields; arbitrary provider meta is never returned.
	 *
	 * @param object $ability Native Ability object.
	 * @return array<string,mixed>
	 */
	private function summary( $ability ) {
		$name        = $ability->get_name();
		$meta        = $ability->get_meta();
		$type        = $meta['mcp']['type'] ?? 'tool';
		$annotations = array();
		foreach ( array( 'readonly', 'destructive', 'idempotent' ) as $key ) {
			$value               = $meta['annotations'][ $key ] ?? null;
			$annotations[ $key ] = is_bool( $value ) ? $value : null;
		}
		return array(
			'name'              => $name,
			'namespace'         => explode( '/', $name, 2 )[0],
			'label'             => $ability->get_label(),
			'description'       => $ability->get_description(),
			'category'          => $ability->get_category(),
			'mcp_type'          => in_array( $type, array( 'tool', 'resource', 'prompt' ), true ) ? $type : 'unknown',
			'annotations'       => $annotations,
			'bridge_delegation' => $this->native_ability_delegation && $this->native_ability_delegation->is_bridge_owned_ability( $ability ) ? 'ability_specific' : 'native_abilities',
		);
	}

	/**
	 * Rejects unsupported contract data rather than returning lossy partial schemas.
	 *
	 * The budget is a lower bound on JSON bytes, checked before allocating encoded data.
	 *
	 * @param mixed $value     Contract data.
	 * @param int   $remaining Remaining traversal/byte budget.
	 * @param int   $depth     Current nesting depth.
	 * @return bool
	 */
	private function is_json_data( $value, &$remaining, $depth = 0 ) {
		if ( $depth > 64 || is_resource( $value ) || ( is_object( $value ) && 'stdClass' !== get_class( $value ) ) ) {
			return false;
		}
		$remaining -= is_string( $value ) ? strlen( $value ) : 1;
		if ( $remaining < 0 ) {
			return false;
		}
		if ( is_array( $value ) || $value instanceof \stdClass ) {
			foreach ( (array) $value as $key => $child ) {
				$remaining -= is_string( $key ) ? strlen( $key ) : 1;
				if ( $remaining < 0 || ! $this->is_json_data( $child, $remaining, $depth + 1 ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Builds a bounded result with an explicit non-authorization statement.
	 *
	 * @param array<int,array<string,mixed>> $items    Public contracts.
	 * @param int                           $page     Current page.
	 * @param int                           $per_page Page size.
	 * @param int                           $total    Matching count.
	 * @return array<string,mixed>|WP_Error
	 */
	private function result( array $items, $page, $per_page, $total ) {
		$result    = array(
			'items'                => $items,
			'page'                 => $page,
			'per_page'             => $per_page,
			'total'                => $total,
			'total_pages'          => (int) ceil( $total / $per_page ),
			'execution_permission' => 'not_evaluated',
		);
		$remaining = self::MAX_RESPONSE_BYTES;
		if ( ! $this->is_json_data( $result, $remaining ) ) {
			return $remaining < 0 ? $this->response_too_large() : $this->invalid_contract();
		}
		try {
			$json = json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			return $this->invalid_contract();
		}
		if ( strlen( $json ) > self::MAX_RESPONSE_BYTES ) {
			return $this->response_too_large();
		}
		return $result;
	}

	/** @return WP_Error */
	private function response_too_large() {
		return new WP_Error( 'ability_catalog_response_too_large', __( 'The public Ability contract exceeds the bounded inspection response size. Reduce the list page size or use the provider native contract documentation.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function invalid_input() {
		return new WP_Error( 'invalid_ability_catalog_input', __( 'Use list with valid pagination and filters, or get with an exact Ability name.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function invalid_contract() {
		return new WP_Error( 'ability_contract_unrepresentable', __( 'The public Ability contract cannot be represented safely. No partial schema was returned.', 'wp-ai-bridge' ) );
	}

	/** @return array<string,mixed> */
	private function input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'    => array(
					'type'    => 'string',
					'enum'    => array( 'list', 'get' ),
					'default' => 'list',
				),
				'name'      => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'namespace' => array( 'type' => 'string' ),
				'search'    => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 25,
				),
			),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function output_schema() {
		$item = array(
			'type'                 => 'object',
			'properties'           => array(
				'name'              => array( 'type' => 'string' ),
				'namespace'         => array( 'type' => 'string' ),
				'label'             => array( 'type' => 'string' ),
				'description'       => array( 'type' => 'string' ),
				'category'          => array( 'type' => 'string' ),
				'mcp_type'          => array(
					'type' => 'string',
					'enum' => array( 'tool', 'resource', 'prompt', 'unknown' ),
				),
				'bridge_delegation' => array(
					'type' => 'string',
					'enum' => array( 'ability_specific', 'native_abilities' ),
				),
				'annotations'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'readonly'    => array( 'type' => array( 'boolean', 'null' ) ),
						'destructive' => array( 'type' => array( 'boolean', 'null' ) ),
						'idempotent'  => array( 'type' => array( 'boolean', 'null' ) ),
					),
					'required'             => array( 'readonly', 'destructive', 'idempotent' ),
					'additionalProperties' => false,
				),
				// Core represents an absent schema as an empty PHP array; preserve it exactly.
				'input_schema'      => array( 'type' => array( 'object', 'array' ) ),
				'output_schema'     => array( 'type' => array( 'object', 'array' ) ),
			),
			'required'             => array( 'name', 'namespace', 'label', 'description', 'category', 'mcp_type', 'bridge_delegation', 'annotations' ),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items'                => array(
					'type'  => 'array',
					'items' => $item,
				),
				'page'                 => array( 'type' => 'integer' ),
				'per_page'             => array( 'type' => 'integer' ),
				'total'                => array( 'type' => 'integer' ),
				'total_pages'          => array( 'type' => 'integer' ),
				'execution_permission' => array(
					'type' => 'string',
					'enum' => array( 'not_evaluated' ),
				),
			),
			'required'             => array( 'items', 'page', 'per_page', 'total', 'total_pages', 'execution_permission' ),
			'additionalProperties' => false,
		);
	}
}
