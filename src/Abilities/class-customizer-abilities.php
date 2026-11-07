<?php
/**
 * Runtime registered current-theme Customizer settings.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Customizer_Context;
use WP_AI_Bridge\Support\Hook_Dispatch_Recovery;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Native_Configuration_Operation;
use WP_AI_Bridge\Support\Permissions;
use WP_Error;

/** Preserves native validation/publication; never edits provider-private settings. */
final class Customizer_Abilities {
	/** @var Permissions */ private $permissions;
	/** @var Native_Configuration_Operation */ private $operation;

	/** @param Permissions $permissions Current delegation. @param Mutation_Log|null $log Activity. */
	public function __construct( Permissions $permissions, ?Mutation_Log $log = null ) {
		$this->permissions = $permissions;
		$this->operation   = new Native_Configuration_Operation( $permissions, $log ? $log : new Mutation_Log(), true );
	}

	/** @return array<int,object> */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/customizer-settings',
			array(
				'label'               => __( 'Registered Customizer Settings', 'wp-ai-bridge' ),
				'description'         => __( 'Discovers registered current-theme Customizer settings or updates them with native validation/publication. If registration fails, authorized list requests may return labeled stored-only theme-mod keys; named reads and writes never fall back. Provider initialization requires explicit conservative lifecycle consent.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => Native_Configuration_Abilities::schema( true ),
				'output_schema'       => Native_Configuration_Abilities::output_schema(),
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

	/** @param mixed $input Closed setting operation. @return bool */
	public function can_execute( $input = array() ) {
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $input ) {
					$parsed = Native_Configuration_Abilities::parse( $input, true );
					return ! is_wp_error( $parsed ) && $this->operation->allowed( $parsed );
				}
			);
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** @param mixed $input Closed setting operation. @return array|WP_Error */
	public function execute( $input ) {
		$input = Native_Configuration_Abilities::parse( $input, true );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$context = new Customizer_Context( $this->permissions, $input['expected_stylesheet'] );
		try {
			$result = $this->operation->execute(
				$input,
				function () use ( $context, $input ) {
					return $context->read( $input['name'] ); },
				function ( $unused ) use ( $context ) {
					return $context->publish(); },
				function ( $value ) use ( $context, $input ) {
					return 'update' === $input['action'] ? $context->prepare( $input['name'], $value ) : $context->open();
				},
				function () use ( $context, $input ) {
					return $context->listing( $input ); },
				function () use ( $context ) {
					return $context->ready(); }
			);
		} finally {
			$context->close();
		}

		if ( 'list' !== $input['action'] ) {
			return $result;
		}
		if ( ! is_wp_error( $result ) ) {
			$result['provenance'] = 'registered_customizer';
			$result['coverage']   = 'registered_settings_only';
			return $result;
		}
		if ( 'customizer_late_bootstrap_unavailable' !== $result->get_error_code() ) {
			return $result;
		}

		// A stored theme-mod key is not necessarily a registered Customizer
		// setting. Only inventory can degrade safely, never named reads or writes.
		$native_input = array(
			'action'              => 'list',
			'target'              => 'theme_mod',
			'expected_stylesheet' => $input['expected_stylesheet'],
		);
		foreach ( array( 'after_name', 'limit' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$native_input[ $field ] = $input[ $field ];
			}
		}
		$native = new Native_Configuration_Abilities( $this->permissions );
		if ( ! $native->can_execute( $native_input ) ) {
			return $result;
		}
		$stored = $native->execute( $native_input );
		if ( is_wp_error( $stored ) ) {
			return $result;
		}
		$stored['customizer_error_code'] = $result->get_error_code();
		return Bounded_Payload::projected_mcp_bytes( $stored ) <= Bounded_Payload::response_limit() ? $stored : $result;
	}
}
