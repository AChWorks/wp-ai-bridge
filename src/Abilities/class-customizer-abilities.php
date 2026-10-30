<?php
/**
 * Runtime registered current-theme Customizer settings.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

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
				'description'         => __( 'Discovers or updates exact registered current-theme Customizer settings using fresh owned native changesets, provider validation and native publication hooks. Provider initialization requires explicit conservative lifecycle consent.', 'wp-ai-bridge' ),
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
			return $this->operation->execute(
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
	}
}
