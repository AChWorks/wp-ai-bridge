<?php
/**
 * Optional provider discovery and capability-mode reporting.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

/**
 * Reports optional provider availability without inventing unsupported operations.
 */
final class Integration_Abilities {
	/** @var Ability_Resolver */
	private $resolver;
	/** @var Permissions */
	private $permissions;

	/**
	 * @param Ability_Resolver $resolver Ability resolver.
	 * @param Permissions      $permissions Permission service.
	 */
	public function __construct( Ability_Resolver $resolver, Permissions $permissions ) {
		$this->resolver    = $resolver;
		$this->permissions = $permissions;
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/integration-status',
			array(
				'label'               => __( 'Integration Status', 'wp-ai-bridge' ),
				'description'         => __( 'Reports supported optional provider integration modes and observed public provider abilities without requiring those providers.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'can_execute' ),
				'meta'                => $this->meta(),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */
	public function can_execute() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
	}

	/** @return array<string,mixed> */
	public function execute() {
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		return array(
			'astra'         => $this->provider(
				$theme && in_array( (string) $theme->get_template(), array( 'astra' ), true ),
				$theme && 'astra' === (string) $theme->get_template() ? (string) $theme->get( 'Version' ) : '',
				'astra/',
				false
			),
			'gravity_forms' => $this->provider( class_exists( 'GFAPI' ), defined( 'GF_VERSION' ) ? (string) GF_VERSION : '', 'gravityforms/', class_exists( 'GFAPI' ) && ! $this->registered_provider_present( 'gravityforms/' ) ),
			'code_snippets' => $this->provider(
				$this->code_snippets_model_available() && function_exists( 'Code_Snippets\\get_snippets' ),
				defined( 'Code_Snippets\\PLUGIN_VERSION' ) ? (string) constant( 'Code_Snippets\\PLUGIN_VERSION' ) : '',
				'',
				$this->code_snippets_api_available()
			),
			'woocommerce'   => $this->provider_exact( class_exists( 'WooCommerce' ), defined( 'WC_VERSION' ) ? (string) WC_VERSION : '', array( 'woocommerce/products-query', 'woocommerce/product-create', 'woocommerce/product-update' ) ),
		);
	}

	/**
	 * @param bool   $installed Installed/detected.
	 * @param string $version Version.
	 * @param string $ability_prefix Provider Ability prefix.
	 * @param bool   $fallback_api Whether Bridge has a verified API fallback.
	 * @return array<string,mixed>
	 */
	private function provider( $installed, $version, $ability_prefix, $fallback_api ) {
		$names = array();
		if ( '' !== $ability_prefix ) {
			foreach ( $this->resolver->public_catalog( 100 ) as $item ) {
				if ( 0 === strpos( $item['name'], $ability_prefix ) ) {
					$names[] = $item['name'];
				}
			}
		}
		$mode = $names ? 'ability' : ( $installed && $fallback_api ? 'api_fallback' : 'unavailable' );
		return array(
			'installed'     => (bool) $installed,
			'version'       => (string) $version,
			'mode'          => $mode,
			'ability_names' => $names,
		);
	}

	/**
	 * Reports only exact verified provider abilities that belong to the ordinary builder workflow.
	 *
	 * @param bool              $installed Provider installed.
	 * @param string            $version Provider version.
	 * @param array<int,string> $candidates Exact stable ability names.
	 * @return array<string,mixed>
	 */
	private function provider_exact( $installed, $version, array $candidates ) {
		$public = array();
		foreach ( $this->resolver->public_catalog( 100 ) as $item ) {
			if ( in_array( $item['name'], $candidates, true ) ) {
				$public[] = $item['name'];
			}
		}
		return array(
			'installed'     => (bool) $installed,
			'version'       => (string) $version,
			'mode'          => $public ? 'ability' : 'unavailable',
			'ability_names' => $public,
		);
	}

	/**
	 * Checks all registered abilities, including provider abilities intentionally hidden from MCP.
	 *
	 * @param string $prefix Verified provider namespace prefix.
	 * @return bool Whether the provider has a native registered Ability surface.
	 */
	private function registered_provider_present( $prefix ) {
		foreach ( wp_get_abilities() as $ability ) {
			if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) && 0 === strpos( $ability->get_name(), $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return bool */
	private function code_snippets_model_available() {
		return class_exists( 'Code_Snippets\\Model\\Snippet' ) || class_exists( 'Code_Snippets\\Snippet' );
	}

	/** @return bool */
	private function code_snippets_api_available() {
		$functions = array(
			'Code_Snippets\\code_snippets',
			'Code_Snippets\\get_snippet',
			'Code_Snippets\\get_snippets',
			'Code_Snippets\\save_snippet',
			'Code_Snippets\\activate_snippet',
			'Code_Snippets\\deactivate_snippet',
			'Code_Snippets\\trash_snippet',
			'Code_Snippets\\restore_snippet',
			'Code_Snippets\\delete_snippet',
		);
		if ( ! $this->code_snippets_model_available() ) {
			return false;
		}
		foreach ( $functions as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string,mixed> */
	private function output_schema() {
		$item = array(
			'type'                 => 'object',
			'properties'           => array(
				'installed'     => array( 'type' => 'boolean' ),
				'version'       => array( 'type' => 'string' ),
				'mode'          => array(
					'type' => 'string',
					'enum' => array( 'ability', 'api_fallback', 'unavailable' ),
				),
				'ability_names' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'installed', 'version', 'mode', 'ability_names' ),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'astra'         => $item,
				'gravity_forms' => $item,
				'code_snippets' => $item,
				'woocommerce'   => $item,
			),
			'required'             => array( 'astra', 'gravity_forms', 'code_snippets', 'woocommerce' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function meta() {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}
}
