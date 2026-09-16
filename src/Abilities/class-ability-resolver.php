<?php
/**
 * Existing Ability discovery and compatibility checks.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

/**
 * Discovers reusable public Abilities without depending on the private registry class.
 */
final class Ability_Resolver {
	/**
	 * Finds the first MCP-exposed Ability whose input contract contains the required keys.
	 *
	 * @param array<int,string> $candidates          Candidate Ability names in preference order.
	 * @param array<int,string> $required_input_keys Input property names required by the caller.
	 * @return object|null Compatible Ability object, or null when none is suitable.
	 */
	public function find( array $candidates, array $required_input_keys = array() ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		foreach ( $candidates as $name ) {
			if ( ! is_string( $name ) || '' === $name || 0 === strpos( $name, 'wp-ai-bridge/' ) ) {
				continue;
			}

			$ability = wp_get_ability( $name );
			if ( ! $this->is_ability_object( $ability ) || ! $this->is_mcp_exposed( $ability ) ) {
				continue;
			}

			$schema = $ability->get_input_schema();
			if ( ! $this->schema_has_properties( $schema, $required_input_keys ) ) {
				continue;
			}

			return $ability;
		}

		return null;
	}

	/**
	 * Returns a compact inventory of externally provided MCP-exposed Abilities.
	 *
	 * @param int $limit Maximum results to return.
	 * @return array<int,array<string,string>> Ability summaries.
	 */
	public function public_catalog( $limit = 50 ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$limit     = max( 0, min( (int) $limit, 100 ) );
		$abilities = wp_get_abilities();
		$results   = array();

		if ( ! is_array( $abilities ) ) {
			return $results;
		}

		foreach ( $abilities as $ability ) {
			if ( ! $this->is_ability_object( $ability ) || ! $this->is_mcp_exposed( $ability ) ) {
				continue;
			}

			$name = $ability->get_name();
			if ( 0 === strpos( $name, 'wp-ai-bridge/' ) || 0 === strpos( $name, 'mcp-adapter/' ) ) {
				continue;
			}

			$results[] = array(
				'name'        => $name,
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'category'    => $ability->get_category(),
			);

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Resolves whether an Ability is exposed through the official MCP Adapter default server.
	 *
	 * Explicit MCP metadata wins over the general public flag, matching Adapter semantics.
	 *
	 * @param object $ability Ability object.
	 * @return bool
	 */
	public function is_mcp_exposed( $ability ) {
		if ( ! $this->is_ability_object( $ability ) ) {
			return false;
		}

		$meta = $ability->get_meta();
		if ( ! is_array( $meta ) ) {
			return false;
		}

		$mcp_meta = $meta['mcp'] ?? array();
		if ( ! is_array( $mcp_meta ) ) {
			return false;
		}
		if ( isset( $mcp_meta['public'] ) ) {
			return (bool) $mcp_meta['public'];
		}

		return true === ( $meta['public'] ?? false );
	}

	/**
	 * Checks the minimum public Ability object contract used by this bridge.
	 *
	 * @param mixed $ability Candidate object.
	 * @return bool
	 */
	private function is_ability_object( $ability ) {
		return is_object( $ability )
			&& method_exists( $ability, 'get_name' )
			&& method_exists( $ability, 'get_label' )
			&& method_exists( $ability, 'get_description' )
			&& method_exists( $ability, 'get_category' )
			&& method_exists( $ability, 'get_input_schema' )
			&& method_exists( $ability, 'get_meta' );
	}

	/**
	 * Checks whether an input schema contains all required top-level properties.
	 *
	 * @param mixed             $schema        Input schema.
	 * @param array<int,string> $required_keys Required property names.
	 * @return bool
	 */
	private function schema_has_properties( $schema, array $required_keys ) {
		if ( empty( $required_keys ) ) {
			return true;
		}

		if ( ! is_array( $schema ) || empty( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return false;
		}

		foreach ( $required_keys as $key ) {
			if ( ! array_key_exists( $key, $schema['properties'] ) ) {
				return false;
			}
		}

		return true;
	}
}
