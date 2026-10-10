<?php
/**
 * Persistent Workspace abilities.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_Error;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Workspace\Store;

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
				'input_schema'        => $this->resume_input_schema(),
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
				'output_schema'       => $this->list_output_schema( $this->document_schema() ),
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
				'output_schema'       => $this->list_output_schema( $this->task_schema() ),
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
		if ( in_array( $action, array( 'create', 'update', 'archive', 'unarchive' ), true ) ) {
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
	public function resume( $input = array() ) {
		if ( ! $this->can_read() ) {
			return new WP_Error( 'workspace_read_denied', __( 'Workspace read access is required.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'project_ref' ) ) ) {
			return new WP_Error( 'workspace_invalid_project', __( 'Requested Workspace project reference is invalid.', 'wp-ai-bridge' ) );
		}
		return $this->store->resume( array_key_exists( 'project_ref', $input ) ? $input['project_ref'] : null );
	}

	/**
	 * Executes one document action.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function document( $input ) {
		if ( is_array( $input ) && 'create' === ( $input['action'] ?? '' ) && array_key_exists( 'operation_id', $input ) ) {
			return \WP_AI_Bridge\Support\Create_Claim::run(
				'workspace-document-create',
				$input,
				array( $this, 'can_document' ),
				function ( $clean ) {
					return $this->document( $clean ); },
				function ( $id ) {
					$item = $this->store->get_document( $id );
					return ! is_wp_error( $item ) ? $this->bounded_item_response( 'document', $item ) : null;
				}
			);
		}
		if ( is_array( $input ) && array_key_exists( 'operation_id', $input ) ) {
			return new WP_Error( 'create_claim_invalid', __( 'operation_id is only supported for create.', 'wp-ai-bridge' ) );
		}
		$action = isset( $input['action'] ) ? (string) $input['action'] : '';
		if ( 'list' === $action ) {
			$needs_page = array_key_exists( 'view', $input ) ||
				array_key_exists( 'project_ref', $input ) || array_key_exists( 'limit', $input ) ||
				array_key_exists( 'before_id', $input );
			if ( $needs_page && ( $input['view'] ?? 'summary' ) !== 'summary' ) {
				return new WP_Error( 'workspace_invalid_page', __( 'Workspace pages must use view=summary.', 'wp-ai-bridge' ) );
			}
			return $needs_page ? $this->store->page_documents( $input ) : $this->store->legacy_list( 'document', $input );
		}
		if ( 'get' === $action ) {
			return $this->store->read_view( 'document', (int) ( $input['id'] ?? 0 ), $input );
		}
		if ( 'create' === $action ) {
			$item = $this->store->create_document( $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-document', 'workspace_document', $item['id'], true, '' );
			return $this->bounded_item_response( 'document', $item );
		}
		if ( 'update' === $action ) {
			$item = $this->store->update_document( (int) ( $input['id'] ?? 0 ), $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-document', 'workspace_document', $item['id'], true, '' );
			return $this->bounded_item_response( 'document', $item );
		}
		if ( 'unarchive' === $action ) {
			$item = $this->store->unarchive_document( (int) ( $input['id'] ?? 0 ), $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-document', 'workspace_document', $item['id'], true, '' );
			return $this->bounded_item_response( 'document', $item );
		}
		if ( 'archive' === $action ) {
			$item = $this->store->archive_document( (int) ( $input['id'] ?? 0 ), $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-document', 'workspace_document', $item['id'], true, '' );
			return $this->bounded_item_response( 'document', $item );
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
		if ( is_array( $input ) && 'create' === ( $input['action'] ?? '' ) && array_key_exists( 'operation_id', $input ) ) {
			return \WP_AI_Bridge\Support\Create_Claim::run(
				'workspace-task-create',
				$input,
				array( $this, 'can_task' ),
				function ( $clean ) {
					return $this->task( $clean ); },
				function ( $id ) {
					$item = $this->store->get_task( $id );
					return ! is_wp_error( $item ) ? $this->bounded_item_response( 'task', $item ) : null;
				}
			);
		}
		if ( is_array( $input ) && array_key_exists( 'operation_id', $input ) ) {
			return new WP_Error( 'create_claim_invalid', __( 'operation_id is only supported for create.', 'wp-ai-bridge' ) );
		}
		$action = isset( $input['action'] ) ? (string) $input['action'] : '';
		if ( 'list' === $action ) {
			$needs_page = array_key_exists( 'view', $input ) ||
				array_key_exists( 'project_ref', $input ) || array_key_exists( 'limit', $input ) ||
				array_key_exists( 'before_id', $input );
			if ( $needs_page && ( $input['view'] ?? 'summary' ) !== 'summary' ) {
				return new WP_Error( 'workspace_invalid_page', __( 'Workspace pages must use view=summary.', 'wp-ai-bridge' ) );
			}
			return $needs_page ? $this->store->page_tasks( $input ) : $this->store->legacy_list( 'task', $input );
		}
		if ( 'get' === $action ) {
			return $this->store->read_view( 'task', (int) ( $input['id'] ?? 0 ), $input );
		}
		if ( 'create' === $action ) {
			$item = $this->store->create_task( $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-task', 'workspace_task', $item['id'], true, '' );
			return $this->bounded_item_response( 'task', $item );
		}
		if ( 'update' === $action ) {
			$item = $this->store->update_task( (int) ( $input['id'] ?? 0 ), $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-task', 'workspace_task', $item['id'], true, '' );
			return $this->bounded_item_response( 'task', $item );
		}
		if ( 'transition' === $action ) {
			$item = $this->store->transition_task( (int) ( $input['id'] ?? 0 ), $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-task', 'workspace_task', $item['id'], true, '' );
			return $this->bounded_item_response( 'task', $item );
		}
		if ( 'archive' === $action ) {
			$item = $this->store->archive_task( (int) ( $input['id'] ?? 0 ), $input );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->log->record( 'wp-ai-bridge/workspace-task', 'workspace_task', $item['id'], true, '' );
			return $this->bounded_item_response( 'task', $item );
		}

		return new WP_Error( 'workspace_invalid_action', __( 'The requested Workspace task action is not supported.', 'wp-ai-bridge' ) );
	}

	/**
	 * Never report an oversized post-commit reply as a failed create/update.
	 * Return verified stored ID/version/hash and an explicit loss marker instead.
	 *
	 * @param string              $kind Document or task.
	 * @param array<string,mixed> $item Committed authoritative record.
	 * @return array<string,mixed>
	 */
	private function bounded_item_response( $kind, $item ) {
		$full = array( 'items' => array( $item ) );
		if ( Bounded_Payload::fits( $full ) ) {
			return $full;
		}
		$projection = $item;
		$omitted    = array();
		foreach ( 'document' === $kind ?
			array( 'content' ) : array( 'notes', 'goal', 'acceptance', 'dependencies', 'target_refs' ) as $field ) {
			if ( array_key_exists( $field, $projection ) ) {
				unset( $projection[ $field ] );
				$omitted[] = $field;
			}
		}
		$projection['projection_complete'] = false;
		$projection['omitted_fields']      = $omitted;
		$result                            = array( 'items' => array( $projection ) );
		if ( Bounded_Payload::fits( $result ) ) {
			return $result;
		}
		// Metadata may contain very large admin-provided labels; preserve
		// only machine-verifiable identity and a complete omission marker.
		$minimal = array(
			'id'                  => (int) $item['id'],
			'kind'                => $kind,
			'project_ref'         => (string) ( $item['project_ref'] ?? '' ),
			'version'             => (int) $item['version'],
			'state_hash'          => (string) $item['state_hash'],
			'modified_gmt'        => (string) $item['modified_gmt'],
			'projection_complete' => false,
			'omitted_fields'      => array( 'content', 'notes', 'goal', 'title', 'acceptance', 'dependencies', 'target_refs' ),
		);
		return array( 'items' => array( $minimal ) );
	}

	/** @return array<string,mixed> */
	private function resume_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_ref' => array(
					'type'      => 'string',
					'maxLength' => 128,
				),
			),
			'additionalProperties' => false,
		);
	}

	/** @param array<string,mixed> $item_schema Item fields. @return array<string,mixed> */
	private function list_output_schema( $item_schema ) {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items'       => array(
					'type'  => 'array',
					'items' => $item_schema,
				),
				'total'       => array( 'type' => 'integer' ),
				'returned'    => array( 'type' => 'integer' ),
				'has_more'    => array( 'type' => 'boolean' ),
				'next_cursor' => array( 'type' => 'integer' ),
				'complete'    => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'items' ),
			'additionalProperties' => false,
		);
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
					'enum' => array( 'list', 'get', 'create', 'update', 'archive', 'unarchive' ),
				),
				'id'                  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'include_archived'    => array( 'type' => 'boolean' ),
				'project_ref'         => array(
					'type'      => 'string',
					'maxLength' => 128,
				),
				'view'                => array(
					'type' => 'string',
					'enum' => array( 'summary', 'full', 'window' ),
				),
				'limit'               => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 25,
				),
				'before_id'           => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'field'               => array(
					'type' => 'string',
					'enum' => array( 'content', 'notes' ),
				),
				'offset'              => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'max_bytes'           => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 16384,
				),
				'operation_id'        => array(
					'type'      => 'string',
					'minLength' => 8,
					'maxLength' => 128,
				),
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
				'project_ref'         => array(
					'type'      => 'string',
					'maxLength' => 128,
				),
				'view'                => array(
					'type' => 'string',
					'enum' => array( 'summary', 'full', 'window' ),
				),
				'limit'               => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 25,
				),
				'before_id'           => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'field'               => array(
					'type' => 'string',
					'enum' => array( 'content', 'notes' ),
				),
				'offset'              => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'max_bytes'           => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 16384,
				),
				'operation_id'        => array(
					'type'      => 'string',
					'minLength' => 8,
					'maxLength' => 128,
				),
				'title'               => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'next_action'         => array(
					'type'      => 'string',
					'maxLength' => 1000,
				),
				'blocker'             => array(
					'type'      => 'string',
					'maxLength' => 1000,
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
	private function field_window_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'field'       => array( 'type' => 'string' ),
				'text'        => array( 'type' => 'string' ),
				'total_bytes' => array( 'type' => 'integer' ),
				'next_offset' => array( 'type' => 'integer' ),
				'complete'    => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'field', 'text', 'total_bytes', 'next_offset', 'complete' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function document_schema() {
		$properties = array_merge(
			$this->identity_properties(),
			array(
				'project_ref'         => array( 'type' => 'string' ),
				'key'                 => array( 'type' => 'string' ),
				'title'               => array( 'type' => 'string' ),
				'content'             => array( 'type' => 'string' ),
				'truncated_fields'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'field_window'        => $this->field_window_schema(),
				'projection_complete' => array( 'type' => 'boolean' ),
				'omitted_fields'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			)
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'id', 'version', 'state_hash', 'modified_gmt' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function task_schema() {
		$properties = array_merge(
			$this->identity_properties(),
			array(
				'project_ref'         => array( 'type' => 'string' ),
				'title'               => array( 'type' => 'string' ),
				'goal'                => array( 'type' => 'string' ),
				'acceptance'          => $this->string_list_schema(),
				'dependencies'        => $this->string_list_schema(),
				'target_refs'         => $this->string_list_schema(),
				'notes'               => array( 'type' => 'string' ),
				'next_action'         => array( 'type' => 'string' ),
				'blocker'             => array( 'type' => 'string' ),
				'progress'            => array( 'type' => 'string' ),
				'review'              => array( 'type' => 'string' ),
				'delivery'            => array( 'type' => 'string' ),
				'truncated_fields'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'field_window'        => $this->field_window_schema(),
				'projection_complete' => array( 'type' => 'boolean' ),
				'omitted_fields'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			)
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'id', 'version', 'state_hash', 'modified_gmt' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function task_summary_schema() {
		return $this->task_schema();
	}

	/** @return array<string,mixed> */
	private function document_summary_schema() {
		return $this->document_schema();
	}

	/** @return array<string,mixed> */
	private function resume_schema() {
		$count_properties = array(
			'documents'          => array( 'type' => 'integer' ),
			'tasks'              => array( 'type' => 'integer' ),
			'active_tasks'       => array( 'type' => 'integer' ),
			'blocked'            => array( 'type' => 'integer' ),
			'review_needed'      => array( 'type' => 'integer' ),
			'done_tasks'         => array( 'type' => 'integer' ),
			'documents_total'    => array( 'type' => 'integer' ),
			'tasks_total'        => array( 'type' => 'integer' ),
			'documents_archived' => array( 'type' => 'integer' ),
			'tasks_archived'     => array( 'type' => 'integer' ),
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'site'                    => array(
					'type'                 => 'object',
					'properties'           => array(
						'name' => array( 'type' => 'string' ),
						'url'  => array( 'type' => 'string' ),
					),
					'required'             => array( 'name', 'url' ),
					'additionalProperties' => false,
				),
				'project_ref'             => array( 'type' => 'string' ),
				'scope'                   => array( 'type' => 'string' ),
				'project_refs'            => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'projects_truncated'      => array( 'type' => 'boolean' ),
				'sections'                => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'                 => 'object',
						'properties'           => array(
							'total'       => array( 'type' => 'integer' ),
							'returned'    => array( 'type' => 'integer' ),
							'has_more'    => array( 'type' => 'boolean' ),
							'next_cursor' => array( 'type' => 'integer' ),
						),
						'required'             => array( 'total', 'returned', 'has_more', 'next_cursor' ),
						'additionalProperties' => false,
					),
				),
				'current_focus_truncated' => array( 'type' => 'boolean' ),
				'current_focus'           => array( 'type' => 'string' ),
				'counts'                  => array(
					'type'                 => 'object',
					'properties'           => $count_properties,
					'required'             => array_keys( $count_properties ),
					'additionalProperties' => false,
				),
				'active_tasks'            => array(
					'type'  => 'array',
					'items' => $this->task_summary_schema(),
				),
				'blocked_tasks'           => array(
					'type'  => 'array',
					'items' => $this->task_summary_schema(),
				),
				'review_needed'           => array(
					'type'  => 'array',
					'items' => $this->task_summary_schema(),
				),
				'documents'               => array(
					'type'  => 'array',
					'items' => $this->document_summary_schema(),
				),
				'last_modified_gmt'       => array( 'type' => 'string' ),
			),
			'required'             => array( 'site', 'current_focus', 'counts', 'active_tasks', 'blocked_tasks', 'review_needed', 'documents', 'last_modified_gmt', 'project_ref', 'scope', 'project_refs', 'projects_truncated', 'sections', 'current_focus_truncated' ),
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
