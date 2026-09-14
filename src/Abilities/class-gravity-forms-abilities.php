<?php
/**
 * Gravity Forms API fallback abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Registers GFAPI fallbacks only when Gravity Forms has no observed public native Ability surface.
 */
final class Gravity_Forms_Abilities {
	/** @var Permissions */ private $permissions;
	/** @var Mutation_Log */ private $log;

	/** @param Permissions $permissions Permissions. @param Mutation_Log $log Log. */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/** @return array<int,object> */
	public function register() {
		$registered = array();
		if ( ! class_exists( 'GFAPI' ) || $this->native_abilities_present() ) {
			return array(); }
		$registered[] = wp_register_ability(
			'wp-native-builder/gravity-forms-read',
			array(
				'label'               => __( 'Read Gravity Forms', 'wp-native-builder-bridge' ),
				'description'         => __( 'Lists or retrieves Gravity Forms through the documented GFAPI fallback when no native Gravity Forms Ability surface is available.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_schema(),
				'output_schema'       => $this->forms_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/gravity-form-upsert',
			array(
				'label'               => __( 'Create or Update Gravity Form', 'wp-native-builder-bridge' ),
				'description'         => __( 'Creates or updates a complete Gravity Forms form object through GFAPI, including fields and supported form metadata.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->upsert_schema(),
				'output_schema'       => $this->form_result_schema(),
				'execute_callback'    => array( $this, 'upsert' ),
				'permission_callback' => array( $this, 'can_upsert' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/gravity-form-status',
			array(
				'label'               => __( 'Set Gravity Form Status', 'wp-native-builder-bridge' ),
				'description'         => __( 'Activates or deactivates one Gravity Form through GFAPI.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'active' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'id', 'active' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->form_result_schema(),
				'execute_callback'    => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_update' ),
				'meta'                => $this->meta( false, false, true ),
			)
		);
		$registered[] = wp_register_ability(
			'wp-native-builder/gravity-form-delete',
			array(
				'label'               => __( 'Delete Gravity Form', 'wp-native-builder-bridge' ),
				'description'         => __( 'Permanently deletes one Gravity Form through GFAPI under destructive access.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'deleted' => array( 'type' => 'boolean' ),
						'id'      => array( 'type' => 'integer' ),
					),
					'required'             => array( 'deleted', 'id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */ public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'gravityforms_edit_forms' ); }
	/** @param array<string,mixed> $input Input. @return bool */
	public function can_upsert( $input ) {
		return is_array( $input ) && isset( $input['action'] ) && $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'create' === $input['action'] ? 'gravityforms_create_form' : 'gravityforms_edit_forms' ); }
	/** @return bool */ public function can_update() {
		return $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'gravityforms_edit_forms' ); }
	/** @return bool */ public function can_delete() {
		return $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'gravityforms_delete_forms' ); }

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function read( $input ) {
		if ( 'get' === $input['action'] ) {
			$form = \GFAPI::get_form( (int) $input['id'] );
			if ( ! is_array( $form ) ) {
				return new WP_Error( 'gravity_form_not_found', __( 'The requested Gravity Form was not found.', 'wp-native-builder-bridge' ) ); }
			return array( 'items' => array( $this->normalize_form( $form ) ) );
		}
		$active = array_key_exists( 'active', $input ) ? (bool) $input['active'] : null;
		$trash  = ! empty( $input['include_trash'] ) ? null : false;
		$forms  = \GFAPI::get_forms( $active, $trash, 'id', 'ASC' );
		$items  = array();
		foreach ( is_array( $forms ) ? $forms : array() as $form ) {
			if ( is_array( $form ) ) {
				$items[] = $this->normalize_form( $form ); }
		}
		return array( 'items' => $items );
	}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function upsert( $input ) {
		$form = isset( $input['form'] ) && is_array( $input['form'] ) ? $input['form'] : array();
		if ( 'create' === $input['action'] ) {
			unset( $form['id'] );
			if ( empty( $form['title'] ) ) {
				return new WP_Error( 'gravity_form_title_required', __( 'A Gravity Form title is required.', 'wp-native-builder-bridge' ) ); }
			$id = \GFAPI::add_form( $form );
			if ( is_wp_error( $id ) ) {
				return $id; }
			$this->log->record( 'wp-native-builder/gravity-form-upsert', 'gravity_form', (int) $id, true, '' );
			return $this->form_result( (int) $id );
		}
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id < 1 || ! \GFAPI::form_id_exists( $id ) ) {
			return new WP_Error( 'gravity_form_not_found', __( 'The Gravity Form to update was not found.', 'wp-native-builder-bridge' ) ); }
		$form['id'] = $id;
		$result     = \GFAPI::update_form( $form );
		if ( is_wp_error( $result ) || false === $result ) {
			return is_wp_error( $result ) ? $result : new WP_Error( 'gravity_form_update_failed', __( 'Gravity Forms did not update the form.', 'wp-native-builder-bridge' ) ); }
		$this->log->record( 'wp-native-builder/gravity-form-upsert', 'gravity_form', $id, true, '' );
		return $this->form_result( $id );
	}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function status( $input ) {
		$id = (int) $input['id'];
		if ( ! \GFAPI::form_id_exists( $id ) ) {
			return new WP_Error( 'gravity_form_not_found', __( 'The Gravity Form was not found.', 'wp-native-builder-bridge' ) );}
		$result = \GFAPI::update_form_property( $id, 'is_active', (bool) $input['active'] );
		if ( is_wp_error( $result ) || false === $result ) {
			return is_wp_error( $result ) ? $result : new WP_Error( 'gravity_form_status_failed', __( 'Gravity Forms did not update the form status.', 'wp-native-builder-bridge' ) );}
		$this->log->record( 'wp-native-builder/gravity-form-status', 'gravity_form', $id, true, '' );
		return $this->form_result( $id );
	}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function delete( $input ) {
		$id = (int) $input['id'];
		if ( ! \GFAPI::form_id_exists( $id ) ) {
			return new WP_Error( 'gravity_form_not_found', __( 'The Gravity Form was not found.', 'wp-native-builder-bridge' ) );}
		$result = \GFAPI::delete_form( $id );
		if ( is_wp_error( $result ) || true !== $result ) {
			return is_wp_error( $result ) ? $result : new WP_Error( 'gravity_form_delete_failed', __( 'Gravity Forms did not delete the form.', 'wp-native-builder-bridge' ) );}
		$this->log->record( 'wp-native-builder/gravity-form-delete', 'gravity_form', $id, true, '' );
		return array(
			'deleted' => true,
			'id'      => $id,
		);
	}

	/** @return bool */
	private function native_abilities_present() {
		foreach ( wp_get_abilities() as $ability ) {
			if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) && 0 === strpos( $ability->get_name(), 'gravityforms/' ) ) {
				return true;
			}
		} return false; }
	/** @param int $id ID. @return array<string,mixed>|WP_Error */
	private function form_result( $id ) {
		$form = \GFAPI::get_form( $id );
		return is_array( $form ) ? array( 'form' => $this->normalize_form( $form ) ) : new WP_Error( 'gravity_form_readback_failed', __( 'The saved Gravity Form could not be read back.', 'wp-native-builder-bridge' ) );}
	/** @param array<string,mixed> $form Form. @return array<string,mixed> */
	private function normalize_form( array $form ) {
		return array(
			'id'          => isset( $form['id'] ) ? (int) $form['id'] : 0,
			'title'       => isset( $form['title'] ) ? (string) $form['title'] : '',
			'description' => isset( $form['description'] ) ? (string) $form['description'] : '',
			'active'      => ! empty( $form['is_active'] ),
			'trash'       => ! empty( $form['is_trash'] ),
			'form'        => $form,
		);}
	/** @return array<string,mixed> */ private function read_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'        => array(
					'type' => 'string',
					'enum' => array( 'list', 'get' ),
				),
				'id'            => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'active'        => array( 'type' => 'boolean' ),
				'include_trash' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);}
	/** @return array<string,mixed> */ private function upsert_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action' => array(
					'type' => 'string',
					'enum' => array( 'create', 'update' ),
				),
				'id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'form'   => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'action', 'form' ),
			'additionalProperties' => false,
		);}
	/** @return array<string,mixed> */ private function normalized_form_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'active'      => array( 'type' => 'boolean' ),
				'trash'       => array( 'type' => 'boolean' ),
				'form'        => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'id', 'title', 'description', 'active', 'trash', 'form' ),
			'additionalProperties' => false,
		);}
	/** @return array<string,mixed> */ private function forms_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items' => array(
					'type'  => 'array',
					'items' => $this->normalized_form_schema(),
				),
			),
			'required'             => array( 'items' ),
			'additionalProperties' => false,
		);}
	/** @return array<string,mixed> */ private function form_result_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'form' => $this->normalized_form_schema() ),
			'required'             => array( 'form' ),
			'additionalProperties' => false,
		);}
	/** @param bool $is_readonly Read-only. @param bool $destructive D. @param bool $idempotent I. @return array<string,mixed> */ private function meta( $is_readonly, $destructive, $idempotent ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => $is_readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);}
}
