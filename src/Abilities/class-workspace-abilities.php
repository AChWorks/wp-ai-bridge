<?php
/**
 * Persistent Workspace abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Error;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Native_Builder_Bridge\Workspace\Store;

/**
 * Exposes a compact typed Workspace contract.
 */
final class Workspace_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @var Store */
	private $store;

	/** @var Mutation_Log */
	private $log;

	/**
	 * Creates the Workspace ability provider.
	 *
	 * @param Permissions  $permissions Permission service.
	 * @param Store        $store       Workspace store.
	 * @param Mutation_Log $log         Mutation log.
	 */
	public function __construct( Permissions $permissions, Store $store, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->store       = $store;
		$this->log         = $log;
	}

	/**
	 * Registers Workspace abilities.
	 *
	 * @return void
	 */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/workspace-resume',
			array(
				'label'               => __( 'Resume Workspace', 'wp-ai-bridge' ),
				'description'         => __( 'Returns a compact project orientation packet with active tasks and a document index, without dumping Workspace history.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->empty_input_schema(),
				'output_schema'       => $this->resume_schema(),
				'execute_callback'    => array( $this, 'resume' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/workspace-document',
			array(
				'label'               => __( 'Workspace Document', 'wp-ai-bridge' ),
				'description'         => __( 'Lists, reads, creates, updates, or archives durable Markdown-oriented Workspace documents with stale-write protection.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->document_input_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'items' => array(
							'type'  => 'array',
							'items' => $this->document_schema(),
						),
					),
					'required'             => array( 'items' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'document' ),
				'permission_callback' => array( $this, 'can_document' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/workspace-task',
			array(
				'label'               => __( 'Workspace Task', 'wp-ai-bridge' ),
				'description'         => __( 'Lists, reads, creates, updates, transitions, or archives lightweight Workspace tasks with independent progress, review, and delivery state.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->task_input_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'items' => array(
							'type'  => 'array',
							'items' => $this->task_schema(),
						),
					),
					'required'             => array( 'items' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'task' ),
				'permission_callback' => array( $this, 'can_task' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/** @return bool */
	public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'edit_posts' );
	}

	/**
	 * Checks document action permission.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public function can_document( $input ) {
		$action = is_array( $input ) && isset( $input['action'] ) ? (string) $input['action'] : '';
		if ( in_array( $action, array( 'list', 'get' ), true ) ) {
			return $this->can_read();
		}
		if ( in_array( $action, array( 'create', 'update', 'archive' ), true ) ) {
			return $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'edit_posts' );
		}
		return false;
	}

	/**
	 * Checks task action permission.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public function can_task( $input ) {
		$action = is_array( $input ) && isset( $input['action'] ) ? (string) $input['action'] : '';
		if ( in_array( $action, array( 'list', 'get' ), true ) ) {
			return $this->can_read();
		}
		if ( in_array( $action, array( 'create', 'update', 'transition', 'archive' ), true ) ) {
			return $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'edit_posts' );
		}
		return false;
	}

	/**
	 * Returns the compact Workspace resume packet.
	 *
	 * @return array<string,mixed>
	 */
	public function resume() {
		return $this->store->resume();
	}

	/**
	 * Executes one document action.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function document( $input ) {
		$action = isset( $input['action'] ) ? (string) $input['action'] : '';
		if ( 'list' === $action ) {
			return array( 'items' => $this->store->list_documents( ! empty( $input['include_archived'] ) ) );
		}
		if ( 'get' === $action ) {
			$item = $this->store->get_document( (int) ( $input['id'] ?? 0 ) );
			return is_wp_error( $item ) ? $item : array( 'items' => array( $item ) );
		}
		if ( 'create' === $action ) {
			$item = $this->store->create_document( $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-document', 'workspace_document', $item['id'], true, '' );
			return array( 'items' => array( $item ) );
		}
		if ( 'update' === $action ) {
			$item = $this->store->update_document( (int) ( $input['id'] ?? 0 ), $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-document', 'workspace_document', $item['id'], true, '' );
			return array( 'items' => array( $item ) );
		}
		if ( 'archive' === $action ) {
			$item = $this->store->archive_document( (int) ( $input['id'] ?? 0 ), $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-document', 'workspace_document', $item['id'], true, '' );
			return array( 'items' => array( $item ) );
		}

		return new WP_Error( 'workspace_invalid_action', __( 'The requested Workspace document action is not supported.', 'wp-ai-bridge' ) );
	}

	/**
	 * Executes one task action.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function task( $input ) {
		$action = isset( $input['action'] ) ? (string) $input['action'] : '';
		if ( 'list' === $action ) {
			return array(
				'items' => $this->store->list_tasks(
					array(
						'include_archived' => ! empty( $input['include_archived'] ),
						'progress'         => isset( $input['progress'] ) ? (string) $input['progress'] : '',
						'review'           => isset( $input['review'] ) ? (string) $input['review'] : '',
						'delivery'         => isset( $input['delivery'] ) ? (string) $input['delivery'] : '',
					)
				),
			);
		}
		if ( 'get' === $action ) {
			$item = $this->store->get_task( (int) ( $input['id'] ?? 0 ) );
			return is_wp_error( $item ) ? $item : array( 'items' => array( $item ) );
		}
		if ( 'create' === $action ) {
			$item = $this->store->create_task( $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-task', 'workspace_task', $item['id'], true, '' );
			return array( 'items' => array( $item ) );
		}
		if ( 'update' === $action ) {
			$item = $this->store->update_task( (int) ( $input['id'] ?? 0 ), $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-task', 'workspace_task', $item['id'], true, '' );
			return array( 'items' => array( $item ) );
		}
		if ( 'transition' === $action ) {
			$item = $this->store->transition_task( (int) ( $input['id'] ?? 0 ), $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-task', 'workspace_task', $item['id'], true, '' );
			return array( 'items' => array( $item ) );
		}
		if ( 'archive' === $action ) {
			$item = $this->store->archive_task( (int) ( $input['id'] ?? 0 ), $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-task', 'workspace_task', $item['id'], true, '' );
			return array( 'items' => array( $item ) );
		}

		return new WP_Error( 'workspace_invalid_action', __( 'The requested Workspace task action is not supported.', 'wp-ai-bridge' ) );
	}

	/** @return array<string,mixed> */
	private function empty_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function document_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'              => array(
					'type' => 'string',
					'enum' => array( 'list', 'get', 'create', 'update', 'archive' ),
				),
				'id'                  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'include_archived'    => array( 'type' => 'boolean' ),
				'key'                 => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'title'               => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'content'             => array(
					'type'      => 'string',
					'maxLength' => Store::MAX_DOCUMENT_BYTES,
				),
				'expected_version'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_state_hash' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function task_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'              => array(
					'type' => 'string',
					'enum' => array( 'list', 'get', 'create', 'update', 'transition', 'archive' ),
				),
				'id'                  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'include_archived'    => array( 'type' => 'boolean' ),
				'title'               => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'goal'                => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'acceptance'          => $this->string_list_schema(),
				'dependencies'        => $this->string_list_schema(),
				'target_refs'         => $this->string_list_schema(),
				'notes'               => array(
					'type'      => 'string',
					'maxLength' => Store::MAX_NOTES_BYTES,
				),
				'progress'            => array(
					'type' => 'string',
					'enum' => array( 'todo', 'in_progress', 'blocked', 'done' ),
				),
				'review'              => array(
					'type' => 'string',
					'enum' => array( 'not_required', 'pending', 'changes_requested', 'approved' ),
				),
				'delivery'            => array(
					'type' => 'string',
					'enum' => array( 'not_applicable', 'draft_preview', 'live' ),
				),
				'expected_version'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_state_hash' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function string_list_schema() {
		return array(
			'type'     => 'array',
			'maxItems' => Store::MAX_LIST_ITEMS,
			'items'    => array(
				'type'      => 'string',
				'maxLength' => Store::MAX_ITEM_BYTES,
			),
		);
	}

	/** @return array<string,mixed> */
	private function identity_properties() {
		return array(
			'id'           => array( 'type' => 'integer' ),
			'version'      => array( 'type' => 'integer' ),
			'state_hash'   => array( 'type' => 'string' ),
			'created_gmt'  => array( 'type' => 'string' ),
			'modified_gmt' => array( 'type' => 'string' ),
			'archived'     => array( 'type' => 'boolean' ),
			'kind'         => array( 'type' => 'string' ),
		);
	}

	/** @return array<string,mixed> */
	private function document_schema() {
		$properties = array_merge(
			$this->identity_properties(),
			array(
				'key'     => array( 'type' => 'string' ),
				'title'   => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
			)
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function task_schema() {
		$properties = array_merge(
			$this->identity_properties(),
			array(
				'title'        => array( 'type' => 'string' ),
				'goal'         => array( 'type' => 'string' ),
				'acceptance'   => $this->string_list_schema(),
				'dependencies' => $this->string_list_schema(),
				'target_refs'  => $this->string_list_schema(),
				'notes'        => array( 'type' => 'string' ),
				'progress'     => array( 'type' => 'string' ),
				'review'       => array( 'type' => 'string' ),
				'delivery'     => array( 'type' => 'string' ),
			)
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function task_summary_schema() {
		$properties = array(
			'id'           => array( 'type' => 'integer' ),
			'title'        => array( 'type' => 'string' ),
			'progress'     => array( 'type' => 'string' ),
			'review'       => array( 'type' => 'string' ),
			'delivery'     => array( 'type' => 'string' ),
			'version'      => array( 'type' => 'integer' ),
			'state_hash'   => array( 'type' => 'string' ),
			'modified_gmt' => array( 'type' => 'string' ),
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function document_summary_schema() {
		$properties = array(
			'id'           => array( 'type' => 'integer' ),
			'key'          => array( 'type' => 'string' ),
			'title'        => array( 'type' => 'string' ),
			'version'      => array( 'type' => 'integer' ),
			'state_hash'   => array( 'type' => 'string' ),
			'modified_gmt' => array( 'type' => 'string' ),
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function resume_schema() {
		$count_properties = array(
			'documents'     => array( 'type' => 'integer' ),
			'tasks'         => array( 'type' => 'integer' ),
			'active_tasks'  => array( 'type' => 'integer' ),
			'blocked'       => array( 'type' => 'integer' ),
			'review_needed' => array( 'type' => 'integer' ),
			'done_tasks'    => array( 'type' => 'integer' ),
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'site'              => array(
					'type'                 => 'object',
					'properties'           => array(
						'name' => array( 'type' => 'string' ),
						'url'  => array( 'type' => 'string' ),
					),
					'required'             => array( 'name', 'url' ),
					'additionalProperties' => false,
				),
				'current_focus'     => array( 'type' => 'string' ),
				'counts'            => array(
					'type'                 => 'object',
					'properties'           => $count_properties,
					'required'             => array_keys( $count_properties ),
					'additionalProperties' => false,
				),
				'active_tasks'      => array(
					'type'  => 'array',
					'items' => $this->task_summary_schema(),
				),
				'blocked_tasks'     => array(
					'type'  => 'array',
					'items' => $this->task_summary_schema(),
				),
				'review_needed'     => array(
					'type'  => 'array',
					'items' => $this->task_summary_schema(),
				),
				'documents'         => array(
					'type'  => 'array',
					'items' => $this->document_summary_schema(),
				),
				'last_modified_gmt' => array( 'type' => 'string' ),
			),
			'required'             => array( 'site', 'current_focus', 'counts', 'active_tasks', 'blocked_tasks', 'review_needed', 'documents', 'last_modified_gmt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds MCP metadata.
	 *
	 * @param bool $is_readonly Read-only.
	 * @param bool $destructive Destructive.
	 * @param bool $idempotent  Idempotent.
	 * @return array<string,mixed>
	 */
	private function meta( $is_readonly, $destructive, $idempotent ) {
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
		);
	}
}
