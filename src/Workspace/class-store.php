<?php
/**
 * Persistent Workspace storage.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Workspace;

use WP_Error;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Create_Claim;

/**
 * Stores compact Workspace documents and tasks in private WordPress objects.
 */
final class Store {
	const DOCUMENT_POST_TYPE = 'wpai_doc';
	const TASK_POST_TYPE     = 'wpai_task';
	const META_STATE         = '_wpai_workspace_state';
	const MAX_RECORDS        = 200;
	const MAX_DOCUMENT_BYTES = 100000;
	const MAX_NOTES_BYTES    = 20000;
	const MAX_LIST_ITEMS     = 25;
	const MAX_ITEM_BYTES     = 1000;

	/**
	 * Registers private/internal Workspace object types.
	 *
	 * @return void
	 */
	public function register_post_types() {
		$common = array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'has_archive'         => false,
			'can_export'          => false,
			'hierarchical'        => false,
			'supports'            => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		);

		register_post_type(
			self::DOCUMENT_POST_TYPE,
			array_merge(
				$common,
				array(
					'label'  => __( 'WP AI Bridge Documents', 'wp-ai-bridge' ),
					'labels' => array(
						'name'          => __( 'Workspace Documents', 'wp-ai-bridge' ),
						'singular_name' => __( 'Workspace Document', 'wp-ai-bridge' ),
					),
				)
			)
		);

		register_post_type(
			self::TASK_POST_TYPE,
			array_merge(
				$common,
				array(
					'label'  => __( 'WP AI Bridge Tasks', 'wp-ai-bridge' ),
					'labels' => array(
						'name'          => __( 'Workspace Tasks', 'wp-ai-bridge' ),
						'singular_name' => __( 'Workspace Task', 'wp-ai-bridge' ),
					),
				)
			)
		);
	}

	/**
	 * Lists Workspace documents.
	 *
	 * @param bool $include_archived Whether archived records are included.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_documents( $include_archived = false ) {
		$items = $this->checked_records( self::DOCUMENT_POST_TYPE );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		if ( ! $include_archived ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( $item ) {
						return empty( $item['archived'] );
					}
				)
			);
		}

		return $items;
	}

	/**
	 * Reads one Workspace document.
	 *
	 * @param int $id Document ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_document( $id ) {
		return $this->read_record( (int) $id, self::DOCUMENT_POST_TYPE, 'workspace_document_not_found' );
	}

	/**
	 * Creates one Workspace document.
	 *
	 * @param array<string,mixed> $input Input fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_document( $input ) {
		$invalid = $this->validate_input_fidelity( $input, 'document' );
		if ( is_wp_error( $invalid ) ) {
			return $invalid;
		}
		if ( $this->record_count( self::DOCUMENT_POST_TYPE ) >= self::MAX_RECORDS ) {
			return new WP_Error( 'workspace_document_limit', __( 'The Workspace document limit has been reached.', 'wp-ai-bridge' ) );
		}

		$title = $this->bounded_text( $input['title'] ?? '', 500, false );
		if ( '' === $title ) {
			return new WP_Error( 'workspace_invalid_document', __( 'A Workspace document title is required.', 'wp-ai-bridge' ) );
		}

		$key = isset( $input['key'] ) ? sanitize_key( (string) $input['key'] ) : '';
		if ( isset( $input['key'] ) && '' === $key ) {
			return new WP_Error( 'workspace_invalid_document', __( 'The Workspace document key must contain URL-safe letters, numbers, dashes, or underscores.', 'wp-ai-bridge' ) );
		}

		$claim       = null;
		$project_ref = isset( $input['project_ref'] ) ? (string) $input['project_ref'] : '';
		if ( '' !== $project_ref && '' !== $key ) {
			$records = $this->checked_records( self::DOCUMENT_POST_TYPE );
			if ( is_wp_error( $records ) ) {
				return $records;
			}
			foreach ( $records as $record ) {
				if ( $record['project_ref'] === $project_ref && $record['key'] === $key ) {
					return new WP_Error( 'workspace_key_exists', __( 'This project already has a document with the same canonical key, possibly archived. Inspect or unarchive that record.', 'wp-ai-bridge' ), array( 'id' => (int) $record['id'] ) );
				}
			}
			$claim = Create_Claim::reserve_document_key( $project_ref, $key );
			if ( is_wp_error( $claim ) ) {
				return $claim;
			}
		}
		$content = $this->bounded_markdown( $input['content'] ?? '', self::MAX_DOCUMENT_BYTES );
		$now     = gmdate( 'c' );
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::DOCUMENT_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return $claim ? new WP_Error( 'workspace_key_outcome_unknown', __( 'A reserved document create may have failed; verify before another attempt.', 'wp-ai-bridge' ) ) :
				( is_wp_error( $post_id ) ? $post_id : new WP_Error( 'workspace_write_failed', __( 'WordPress could not create the Workspace document.', 'wp-ai-bridge' ) ) );
		}

		$state = array(
			'kind'         => 'document',
			'key'          => '' !== $key ? $key : 'document-' . (int) $post_id,
			'project_ref'  => isset( $input['project_ref'] ) ? (string) $input['project_ref'] : '',
			'title'        => $title,
			'content'      => $content,
			'archived'     => false,
			'version'      => 1,
			'created_gmt'  => $now,
			'modified_gmt' => $now,
		);

		$stored = $this->store_initial_state( (int) $post_id, $state );
		if ( is_wp_error( $stored ) ) {
			if ( $claim ) {
				return new WP_Error( 'workspace_key_outcome_unknown', __( 'Document creation may have committed; inspect the project key before another create.', 'wp-ai-bridge' ), array( 'id' => (int) $post_id ) );
			}
			wp_delete_post( (int) $post_id, true );
			return $stored;
		}
		if ( $claim && ! Create_Claim::commit_document_key( $claim, (int) $post_id ) ) {
			return new WP_Error( 'workspace_key_outcome_unknown', __( 'Document exists but its canonical key receipt could not be verified.', 'wp-ai-bridge' ), array( 'id' => (int) $post_id ) );
		}

		return $stored;
	}

	/**
	 * Updates one Workspace document through optimistic compare-and-swap.
	 *
	 * @param int                 $id    Document ID.
	 * @param array<string,mixed> $input Input fields including expected identity.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update_document( $id, $input ) {
		$invalid = $this->validate_input_fidelity( $input, 'document' );
		if ( is_wp_error( $invalid ) ) {
			return $invalid;
		}
		$current = $this->get_document( $id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$existing_project = (string) $current['project_ref'];
		$next_project     = array_key_exists( 'project_ref', $input ) ? $input['project_ref'] : $existing_project;
		$next_key         = array_key_exists( 'key', $input ) ? $input['key'] : $current['key'];
		if ( '' !== $existing_project && ( $existing_project !== $next_project || $next_key !== $current['key'] ) ) {
			return new WP_Error( 'workspace_key_immutable', __( 'Bound document project/key identity is immutable; archive or update the same record instead.', 'wp-ai-bridge' ) );
		}
		$claim = null;
		if ( '' === $existing_project && '' !== $next_project ) {
			// Exact CAS identity is required even before reserving a new key.
			if ( (int) ( $input['expected_version'] ?? 0 ) !== (int) $current['version'] ||
				! is_string( $input['expected_state_hash'] ?? null ) ||
				! hash_equals( $current['state_hash'], $input['expected_state_hash'] ) ) {
				return new WP_Error( 'workspace_stale', __( 'The Workspace record changed after inspection.', 'wp-ai-bridge' ) );
			}
			$records = $this->checked_records( self::DOCUMENT_POST_TYPE );
			if ( is_wp_error( $records ) ) {
				return $records;
			}
			foreach ( $records as $record ) {
				if ( $record['id'] !== (int) $id && $record['project_ref'] === $next_project && $record['key'] === $next_key ) {
					return new WP_Error( 'workspace_key_exists', __( 'A document with this canonical key already exists for the project.', 'wp-ai-bridge' ) );
				}
			}
			$claim = Create_Claim::reserve_document_key( $next_project, $next_key );
			if ( is_wp_error( $claim ) ) {
				return $claim;
			}
		}
		$updated = $this->mutate_record(
			(int) $id,
			self::DOCUMENT_POST_TYPE,
			$input,
			'workspace_document_not_found',
			function ( $state ) use ( $input ) {
				if ( array_key_exists( 'title', $input ) ) {
					$title = $this->bounded_text( $input['title'], 500, false );
					if ( '' === $title ) {
						return new WP_Error( 'workspace_invalid_document', __( 'A Workspace document title is required.', 'wp-ai-bridge' ) );
					}
					$state['title'] = $title;
				}

				if ( array_key_exists( 'key', $input ) ) {
					$key = sanitize_key( (string) $input['key'] );
					if ( '' === $key ) {
						return new WP_Error( 'workspace_invalid_document', __( 'The Workspace document key must contain URL-safe letters, numbers, dashes, or underscores.', 'wp-ai-bridge' ) );
					}
					$state['key'] = $key;
				}

				if ( array_key_exists( 'project_ref', $input ) ) {
					$state['project_ref'] = (string) $input['project_ref'];
				}

				if ( array_key_exists( 'content', $input ) ) {
					$state['content'] = $this->bounded_markdown( $input['content'], self::MAX_DOCUMENT_BYTES );
				}

				return $state;
			}
		);
		if ( $claim ) {
			if ( is_wp_error( $updated ) || ! Create_Claim::commit_document_key( $claim, (int) $id ) ) {
				return new WP_Error( 'workspace_key_outcome_unknown', __( 'Binding this legacy document to a project has an uncertain result; inspect the record before retrying.', 'wp-ai-bridge' ) );
			}
		}
		return $updated;
	}

	/**
	 * Archives one Workspace document.
	 *
	 * @param int                 $id    Document ID.
	 * @param array<string,mixed> $input Expected identity.
	 * @return array<string,mixed>|WP_Error
	 */
	public function archive_document( $id, $input ) {
		return $this->mutate_record(
			(int) $id,
			self::DOCUMENT_POST_TYPE,
			$input,
			'workspace_document_not_found',
			static function ( $state ) {
				$state['archived'] = true;
				return $state;
			}
		);
	}

	/**
	 * Re-activates an archived document without creating another canonical key.
	 *
	 * @param int $id Existing ID.
	 * @param array<string,mixed> $input Expected version and hash.
	 * @return array<string,mixed>|WP_Error
	 */
	public function unarchive_document( $id, $input ) {
		return $this->mutate_record(
			(int) $id,
			self::DOCUMENT_POST_TYPE,
			$input,
			'workspace_document_not_found',
			static function ( $state ) {
				$state['archived'] = false;
				return $state;
			}
		);
	}

	/**
	 * Lists Workspace tasks with bounded filters.
	 *
	 * @param array<string,mixed> $filters Task filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_tasks( $filters = array() ) {
		$items = $this->checked_records( self::TASK_POST_TYPE );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$include_archived = ! empty( $filters['include_archived'] );
		$allowed_filters  = array( 'progress', 'review', 'delivery' );

		$items = array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $filters, $include_archived, $allowed_filters ) {
					if ( ! $include_archived && ! empty( $item['archived'] ) ) {
						return false;
					}
					foreach ( $allowed_filters as $field ) {
						if ( isset( $filters[ $field ] ) && '' !== (string) $filters[ $field ] && (string) $item[ $field ] !== (string) $filters[ $field ] ) {
							return false;
						}
					}
					return true;
				}
			)
		);

		return $items;
	}

	/**
	 * Returns a complete, integrity-checked internal record set. Exceeding quota
	 * or encountering damaged state is an explicit failure, not silent omission.
	 *
	 * @param string $post_type Exact private type.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function checked_records( $post_type ) {
		$ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array_keys( get_post_stati( array(), 'names' ) ),
				'posts_per_page' => self::MAX_RECORDS + 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		if ( ! is_array( $ids ) || count( $ids ) > self::MAX_RECORDS ) {
			return new WP_Error( 'workspace_overflow', __( 'Workspace has more records than its supported quota; full enumeration is unavailable.', 'wp-ai-bridge' ) );
		}
		$items = array();
		foreach ( $ids as $id ) {
			$item = $this->read_record( (int) $id, $post_type, 'workspace_not_found' );
			if ( is_wp_error( $item ) ) {
				return new WP_Error( 'workspace_incomplete', __( 'One or more Workspace records could not be decoded; results cannot be claimed complete.', 'wp-ai-bridge' ), array( 'id' => (int) $id ) );
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Small deterministic pages of IDs; continuation is exclusive before_id.
	 * No full text is projected into list responses.
	 *
	 * @param string              $type Private post type.
	 * @param array<string,mixed> $filters Bounded filters.
	 * @return array<string,mixed>|WP_Error
	 */
	private function page_records( $type, $filters ) {
		$items = $this->checked_records( $type );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$limit     = isset( $filters['limit'] ) ? $filters['limit'] : 10;
		$before_id = isset( $filters['before_id'] ) ? $filters['before_id'] : 0;
		if ( ! is_int( $limit ) || $limit < 1 || $limit > 25 || ! is_int( $before_id ) || $before_id < 0 ||
			( isset( $filters['project_ref'] ) && ! $this->valid_project_filter( $filters['project_ref'] ) ) ) {
			return new WP_Error( 'workspace_invalid_page', __( 'Workspace page selector or project reference is invalid.', 'wp-ai-bridge' ) );
		}
		$all        = array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $filters, $type ) {
					if ( empty( $filters['include_archived'] ) && ! empty( $item['archived'] ) ) {
						return false;
					}
					if ( array_key_exists( 'project_ref', $filters ) && (string) $item['project_ref'] !== $filters['project_ref'] ) {
						return false;
					}
					if ( self::TASK_POST_TYPE === $type ) {
						foreach ( array( 'progress', 'review', 'delivery' ) as $field ) {
							if ( isset( $filters[ $field ] ) && '' !== $filters[ $field ] && $item[ $field ] !== $filters[ $field ] ) {
								return false;
							}
						}
					}
					return true;
				}
			)
		);
		$candidates = array_values(
			array_filter(
				$all,
				static function ( $item ) use ( $before_id ) {
					return 0 === $before_id || (int) $item['id'] < $before_id;
				}
			)
		);
		$page       = array_slice( $candidates, 0, $limit );
		$summary    = self::TASK_POST_TYPE === $type ? 'task_summary' : 'document_summary';
		$page       = array_map( array( $this, $summary ), $page );
		$more       = count( $candidates ) > count( $page );
		$result     = array(
			'items'       => $page,
			'total'       => count( $all ),
			'returned'    => count( $page ),
			'has_more'    => $more,
			'next_cursor' => $more && $page ? (int) $page[ count( $page ) - 1 ]['id'] : 0,
			'complete'    => ! $more,
		);
		if ( ! Bounded_Payload::fits( $result ) ) {
			return new WP_Error( 'workspace_page_too_large', __( 'Workspace summary page exceeds the response budget; request a smaller limit.', 'wp-ai-bridge' ) );
		}
		return $result;
	}

	/** @param array<string,mixed> $filters Page filters. @return array<string,mixed>|WP_Error */
	public function page_documents( $filters ) {
		return $this->page_records( self::DOCUMENT_POST_TYPE, $filters );
	}

	/** @param array<string,mixed> $filters Page filters. @return array<string,mixed>|WP_Error */
	public function page_tasks( $filters ) {
		return $this->page_records( self::TASK_POST_TYPE, $filters );
	}

	/**
	 * Full legacy list is permitted only when the entire result fits.
	 *
	 * @param string              $kind 'document' or 'task'.
	 * @param array<string,mixed> $filters Record filters.
	 * @return array<string,mixed>|WP_Error
	 */
	public function legacy_list( $kind, $filters = array() ) {
		$type  = 'document' === $kind ? self::DOCUMENT_POST_TYPE : self::TASK_POST_TYPE;
		$items = $this->checked_records( $type );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$items  = array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $kind, $filters ) {
					if ( empty( $filters['include_archived'] ) && ! empty( $item['archived'] ) ) {
						return false;
					}
					if ( 'task' === $kind ) {
						foreach ( array( 'progress', 'review', 'delivery' ) as $field ) {
							if ( ! empty( $filters[ $field ] ) && $filters[ $field ] !== $item[ $field ] ) {
								return false;
							}
						}
					}
					return true;
				}
			)
		);
		$result = array( 'items' => $items );
		return Bounded_Payload::fits( $result )
			? $result
			: new WP_Error( 'workspace_pagination_required', __( 'The full Workspace list cannot fit; use view=summary with limit and before_id.', 'wp-ai-bridge' ) );
	}

	/** @param mixed $value Project filter. @return bool */
	private function valid_project_filter( $value ) {
		return is_string( $value ) && ( '' === $value || 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $value ) );
	}

	/**
	 * Reads the exact selected document/task, optionally as bounded text
	 * windows protected by the original version and state_hash.
	 *
	 * @param string              $kind Document or task.
	 * @param int                 $id Record ID.
	 * @param array<string,mixed> $input Read selectors.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read_view( $kind, $id, $input ) {
		$record = 'document' === $kind ? $this->get_document( $id ) : $this->get_task( $id );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		if ( array_key_exists( 'project_ref', $input ) &&
			( ! $this->valid_project_filter( $input['project_ref'] ) || $record['project_ref'] !== $input['project_ref'] ) ) {
			return new WP_Error( 'workspace_project_mismatch', __( 'The selected record does not belong to that exact project.', 'wp-ai-bridge' ) );
		}
		$view = isset( $input['view'] ) ? $input['view'] : 'full';
		if ( 'summary' === $view ) {
			$summary = 'document' === $kind ? $this->document_summary( $record ) : $this->task_summary( $record );
			return array( 'items' => array( $summary ) );
		}
		if ( 'full' === $view && ! isset( $input['field'] ) ) {
			$result = array( 'items' => array( $record ) );
			return Bounded_Payload::fits( $result ) ? $result :
				new WP_Error( 'workspace_pagination_required', __( 'The full Workspace item is too large; read summary then guarded field windows.', 'wp-ai-bridge' ) );
		}
		$field = isset( $input['field'] ) ? $input['field'] : '';
		if ( 'window' !== $view || ( 'document' === $kind && 'content' !== $field ) || ( 'task' === $kind && 'notes' !== $field ) ||
			! isset( $input['expected_version'], $input['expected_state_hash'] ) ||
			(int) $input['expected_version'] !== (int) $record['version'] ||
			! is_string( $input['expected_state_hash'] ) || ! hash_equals( $record['state_hash'], $input['expected_state_hash'] ) ) {
			return new WP_Error( 'workspace_stale', __( 'Guarded Workspace field reads require the current record version and hash.', 'wp-ai-bridge' ) );
		}
		$offset = isset( $input['offset'] ) ? $input['offset'] : 0;
		$limit  = isset( $input['max_bytes'] ) ? $input['max_bytes'] : 8192;
		$window = Bounded_Payload::text_window( (string) $record[ $field ], $offset, $limit );
		if ( is_wp_error( $window ) ) {
			return $window;
		}
		$summary                 = 'document' === $kind ? $this->document_summary( $record ) : $this->task_summary( $record );
		$summary['field_window'] = array(
			'field'       => $field,
			'text'        => $window['content'],
			'total_bytes' => $window['total_bytes'],
			'next_offset' => $window['next_offset'],
			'complete'    => $window['complete'],
		);
		$result                  = array( 'items' => array( $summary ) );
		return Bounded_Payload::fits( $result ) ? $result :
			new WP_Error( 'workspace_window_too_large', __( 'Workspace window exceeds the serialized response budget; reduce max_bytes.', 'wp-ai-bridge' ) );
	}

	/**
	 * Reads one Workspace task.
	 *
	 * @param int $id Task ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_task( $id ) {
		return $this->read_record( (int) $id, self::TASK_POST_TYPE, 'workspace_task_not_found' );
	}

	/**
	 * Creates one Workspace task.
	 *
	 * @param array<string,mixed> $input Input fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_task( $input ) {
		$invalid = $this->validate_input_fidelity( $input, 'task' );
		if ( is_wp_error( $invalid ) ) {
			return $invalid;
		}
		if ( $this->record_count( self::TASK_POST_TYPE ) >= self::MAX_RECORDS ) {
			return new WP_Error( 'workspace_task_limit', __( 'The Workspace task limit has been reached.', 'wp-ai-bridge' ) );
		}

		$title = $this->bounded_text( $input['title'] ?? '', 500, false );
		if ( '' === $title ) {
			return new WP_Error( 'workspace_invalid_task', __( 'A Workspace task title is required.', 'wp-ai-bridge' ) );
		}

		$progress = $this->enum_value( $input['progress'] ?? 'todo', array( 'todo', 'in_progress', 'blocked', 'done' ) );
		$review   = $this->enum_value( $input['review'] ?? 'not_required', array( 'not_required', 'pending', 'changes_requested', 'approved' ) );
		$delivery = $this->enum_value( $input['delivery'] ?? 'not_applicable', array( 'not_applicable', 'draft_preview', 'live' ) );
		if ( null === $progress || null === $review || null === $delivery ) {
			return new WP_Error( 'workspace_invalid_task', __( 'The Workspace task state is not valid.', 'wp-ai-bridge' ) );
		}

		$now     = gmdate( 'c' );
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::TASK_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'workspace_write_failed', __( 'WordPress could not create the Workspace task.', 'wp-ai-bridge' ) );
		}

		$state = array(
			'kind'         => 'task',
			'title'        => $title,
			'project_ref'  => isset( $input['project_ref'] ) ? (string) $input['project_ref'] : '',
			'next_action'  => $this->bounded_text( $input['next_action'] ?? '', 1000, true ),
			'blocker'      => $this->bounded_text( $input['blocker'] ?? '', 1000, true ),
			'goal'         => $this->bounded_text( $input['goal'] ?? '', 5000, true ),
			'acceptance'   => $this->bounded_string_list( $input['acceptance'] ?? array() ),
			'dependencies' => $this->bounded_string_list( $input['dependencies'] ?? array() ),
			'target_refs'  => $this->bounded_string_list( $input['target_refs'] ?? array() ),
			'notes'        => $this->bounded_text( $input['notes'] ?? '', self::MAX_NOTES_BYTES, true ),
			'progress'     => $progress,
			'review'       => $review,
			'delivery'     => $delivery,
			'archived'     => false,
			'version'      => 1,
			'created_gmt'  => $now,
			'modified_gmt' => $now,
		);

		$stored = $this->store_initial_state( (int) $post_id, $state );
		if ( is_wp_error( $stored ) ) {
			wp_delete_post( (int) $post_id, true );
			return $stored;
		}

		return $stored;
	}

	/**
	 * Updates descriptive fields on one Workspace task.
	 *
	 * @param int                 $id    Task ID.
	 * @param array<string,mixed> $input Input fields including expected identity.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update_task( $id, $input ) {
		$invalid = $this->validate_input_fidelity( $input, 'task' );
		if ( is_wp_error( $invalid ) ) {
			return $invalid;
		}
		return $this->mutate_record(
			(int) $id,
			self::TASK_POST_TYPE,
			$input,
			'workspace_task_not_found',
			function ( $state ) use ( $input ) {
				if ( array_key_exists( 'title', $input ) ) {
					$title = $this->bounded_text( $input['title'], 500, false );
					if ( '' === $title ) {
						return new WP_Error( 'workspace_invalid_task', __( 'A Workspace task title is required.', 'wp-ai-bridge' ) );
					}
					$state['title'] = $title;
				}

				if ( array_key_exists( 'project_ref', $input ) ) {
					$state['project_ref'] = (string) $input['project_ref'];
				}
				foreach ( array( 'next_action', 'blocker' ) as $field ) {
					if ( array_key_exists( $field, $input ) ) {
						$state[ $field ] = $this->bounded_text( $input[ $field ], 1000, true );
					}
				}
				if ( array_key_exists( 'goal', $input ) ) {
					$state['goal'] = $this->bounded_text( $input['goal'], 5000, true );
				}
				if ( array_key_exists( 'acceptance', $input ) ) {
					$state['acceptance'] = $this->bounded_string_list( $input['acceptance'] );
				}
				if ( array_key_exists( 'dependencies', $input ) ) {
					$state['dependencies'] = $this->bounded_string_list( $input['dependencies'] );
				}
				if ( array_key_exists( 'target_refs', $input ) ) {
					$state['target_refs'] = $this->bounded_string_list( $input['target_refs'] );
				}
				if ( array_key_exists( 'notes', $input ) ) {
					$state['notes'] = $this->bounded_text( $input['notes'], self::MAX_NOTES_BYTES, true );
				}

				return $state;
			}
		);
	}

	/**
	 * Transitions independent task progress/review/delivery state.
	 *
	 * @param int                 $id    Task ID.
	 * @param array<string,mixed> $input Input fields including expected identity.
	 * @return array<string,mixed>|WP_Error
	 */
	public function transition_task( $id, $input ) {
		return $this->mutate_record(
			(int) $id,
			self::TASK_POST_TYPE,
			$input,
			'workspace_task_not_found',
			function ( $state ) use ( $input ) {
				$changed = false;
				$fields  = array(
					'progress' => array( 'todo', 'in_progress', 'blocked', 'done' ),
					'review'   => array( 'not_required', 'pending', 'changes_requested', 'approved' ),
					'delivery' => array( 'not_applicable', 'draft_preview', 'live' ),
				);

				foreach ( $fields as $field => $allowed ) {
					if ( ! array_key_exists( $field, $input ) ) {
						continue;
					}
					$value = $this->enum_value( $input[ $field ], $allowed );
					if ( null === $value ) {
						return new WP_Error( 'workspace_invalid_task', __( 'The Workspace task state is not valid.', 'wp-ai-bridge' ) );
					}
					$state[ $field ] = $value;
					$changed         = true;
				}

				if ( ! $changed ) {
					return new WP_Error( 'workspace_invalid_task', __( 'At least one task progress, review, or delivery state is required for a transition.', 'wp-ai-bridge' ) );
				}

				return $state;
			}
		);
	}

	/**
	 * Archives one Workspace task.
	 *
	 * @param int                 $id    Task ID.
	 * @param array<string,mixed> $input Expected identity.
	 * @return array<string,mixed>|WP_Error
	 */
	public function archive_task( $id, $input ) {
		return $this->mutate_record(
			(int) $id,
			self::TASK_POST_TYPE,
			$input,
			'workspace_task_not_found',
			static function ( $state ) {
				$state['archived'] = true;
				return $state;
			}
		);
	}

	/**
	 * Returns a compact orientation packet for a fresh connected chat.
	 *
	 * @return array<string,mixed>
	 */
	public function resume( $project_ref = null ) {
		if ( null !== $project_ref && ! $this->valid_project_filter( $project_ref ) ) {
			return new WP_Error( 'workspace_invalid_project', __( 'Requested Workspace project reference is invalid.', 'wp-ai-bridge' ) );
		}
		$all_tasks     = $this->checked_records( self::TASK_POST_TYPE );
		$all_documents = $this->checked_records( self::DOCUMENT_POST_TYPE );
		if ( is_wp_error( $all_tasks ) ) {
			return $all_tasks;
		}
		if ( is_wp_error( $all_documents ) ) {
			return $all_documents;
		}
		$projects = array();
		$unbound  = false;
		foreach ( array_merge( $all_tasks, $all_documents ) as $item ) {
			$project = (string) $item['project_ref'];
			if ( '' === $project ) {
				$unbound = true;
			} else {
				$projects[ $project ] = true;
			}
		}
		$project_refs = array_keys( $projects );
		sort( $project_refs, SORT_STRING );
		$scope = null !== $project_ref ? 'project' :
			( count( $projects ) + ( $unbound ? 1 : 0 ) > 1 ? 'mixed' :
				( count( $projects ) > 0 ? 'single_project' : 'legacy_site' ) );

		$tasks     = array_values(
			array_filter(
				$all_tasks,
				static function ( $item ) use ( $project_ref ) {
					return empty( $item['archived'] ) && ( null === $project_ref || $item['project_ref'] === $project_ref );
				}
			)
		);
		$documents = array_values(
			array_filter(
				$all_documents,
				static function ( $item ) use ( $project_ref ) {
					return empty( $item['archived'] ) && ( null === $project_ref || $item['project_ref'] === $project_ref );
				}
			)
		);
		$active    = array();
		$blocked   = array();
		$review    = array();
		$done      = 0;
		$focus     = '';
		foreach ( $tasks as $task ) {
			if ( 'done' === $task['progress'] ) {
				++$done;
			} else {
				$active[] = $this->task_summary( $task );
				if ( '' === $focus && 'in_progress' === $task['progress'] ) {
					$focus = $task['title'];
				}
			}
			if ( 'blocked' === $task['progress'] ) {
				$blocked[] = $this->task_summary( $task );
			}
			if ( in_array( $task['review'], array( 'pending', 'changes_requested' ), true ) ) {
				$review[] = $this->task_summary( $task );
			}
		}
		if ( 'mixed' === $scope ) {
			$focus = '';
		}
		$last_modified = '';
		foreach ( array_merge( $tasks, $documents ) as $item ) {
			if ( '' === $last_modified || strcmp( $item['modified_gmt'], $last_modified ) > 0 ) {
				$last_modified = $item['modified_gmt'];
			}
		}
		$sections = array(
			'active_tasks'  => $active,
			'blocked_tasks' => $blocked,
			'review_needed' => $review,
			'documents'     => array_map( array( $this, 'document_summary' ), $documents ),
		);
		$result   = array(
			'site'                    => array(
				'name' => (string) get_bloginfo( 'name' ),
				'url'  => (string) home_url( '/' ),
			),
			'project_ref'             => null !== $project_ref ? $project_ref : '',
			'scope'                   => $scope,
			'project_refs'            => array_slice( $project_refs, 0, 25 ),
			'projects_truncated'      => count( $project_refs ) > 25,
			'current_focus'           => $this->summary_prefix( $focus, 160 ),
			'current_focus_truncated' => strlen( $focus ) > strlen( $this->summary_prefix( $focus, 160 ) ),
			'counts'                  => array(
				'documents'          => count( $documents ),
				'tasks'              => count( $tasks ),
				'active_tasks'       => count( $active ),
				'blocked'            => count( $blocked ),
				'review_needed'      => count( $review ),
				'done_tasks'         => $done,
				'documents_total'    => count( $all_documents ),
				'tasks_total'        => count( $all_tasks ),
				'documents_archived' => count(
					array_filter(
						$all_documents,
						static function ( $item ) {
							return $item['archived']; }
					)
				),
				'tasks_archived'     => count(
					array_filter(
						$all_tasks,
						static function ( $item ) {
							return $item['archived']; }
					)
				),
			),
			'last_modified_gmt'       => $last_modified,
			'sections'                => array(),
		);
		foreach ( $sections as $name => $items ) {
			$limit                       = 'documents' === $name ? 25 : 10;
			$result[ $name ]             = array_slice( $items, 0, $limit );
			$result['sections'][ $name ] = array(
				'total'       => count( $items ),
				'returned'    => count( $result[ $name ] ),
				'has_more'    => count( $items ) > count( $result[ $name ] ),
				'next_cursor' => count( $items ) > count( $result[ $name ] ) && $result[ $name ] ? (int) end( $result[ $name ] )['id'] : 0,
			);
		}
		// Serialize first. Shrink only visibly marked projection pages if needed.
		for ( $attempt = 0; $attempt < 75 && ! Bounded_Payload::fits( $result ); ++$attempt ) {
			$largest = '';
			$size    = 0;
			foreach ( array( 'documents', 'active_tasks', 'blocked_tasks', 'review_needed' ) as $name ) {
				if ( count( $result[ $name ] ) > $size ) {
					$largest = $name;
					$size    = count( $result[ $name ] );
				}
			}
			if ( '' === $largest || 0 === $size ) {
				break;
			}
			array_pop( $result[ $largest ] );
			$result['sections'][ $largest ]['returned']    = count( $result[ $largest ] );
			$result['sections'][ $largest ]['has_more']    = $result['sections'][ $largest ]['returned'] < $result['sections'][ $largest ]['total'];
			$result['sections'][ $largest ]['next_cursor'] = $result[ $largest ] ? (int) end( $result[ $largest ] )['id'] : 0;
		}
		return Bounded_Payload::fits( $result )
			? $result
			: new WP_Error( 'workspace_resume_too_large', __( 'Workspace resume could not fit safely; request exact project pages.', 'wp-ai-bridge' ) );
	}

	/**
	 * Exports the current durable Workspace state.
	 *
	 * @return array<string,mixed>
	 */
	public function export_snapshot() {
		$documents = $this->checked_records( self::DOCUMENT_POST_TYPE );
		$tasks     = $this->checked_records( self::TASK_POST_TYPE );
		if ( is_wp_error( $documents ) ) {
			return $documents;
		}
		if ( is_wp_error( $tasks ) ) {
			return $tasks;
		}
		return array(
			'format'       => 'wp-ai-bridge-workspace-v1',
			'generated_at' => gmdate( 'c' ),
			'complete'     => true,
			'counts'       => array(
				'documents' => count( $documents ),
				'tasks'     => count( $tasks ),
			),
			'site'         => array(
				'name' => (string) get_bloginfo( 'name' ),
				'url'  => (string) home_url( '/' ),
			),
			'documents'    => $documents,
			'tasks'        => $tasks,
		);
	}

	/**
	 * Permanently clears every Workspace document/task.
	 *
	 * Caller must enforce administrator, destructive-group, nonce, and explicit confirmation gates.
	 *
	 * @return array<string,int>|WP_Error
	 */
	public function clear() {
		$deleted = array(
			'documents' => 0,
			'tasks'     => 0,
		);

		foreach ( array(
			self::DOCUMENT_POST_TYPE => 'documents',
			self::TASK_POST_TYPE     => 'tasks',
		) as $post_type => $bucket ) {
			$ids = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $ids as $id ) {
				if ( ! wp_delete_post( (int) $id, true ) ) {
					return new WP_Error( 'workspace_clear_failed', __( 'WordPress could not completely clear the Workspace.', 'wp-ai-bridge' ) );
				}
				++$deleted[ $bucket ];
			}
		}

		return $deleted;
	}

	/**
	 * Returns compact counts for the admin dashboard.
	 *
	 * @return array<string,int>
	 */
	public function counts() {
		$resume = $this->resume();
		return is_wp_error( $resume ) ? $resume : $resume['counts'];
	}

	/**
	 * Reads all valid records for one internal type.
	 *
	 * @param string $post_type Internal post type.
	 * @return array<int,array<string,mixed>>
	 */

	/**
	 * Counts internal records without exposing them publicly.
	 *
	 * @param string $post_type Internal post type.
	 * @return int
	 */
	private function record_count( $post_type ) {
		$ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array_keys( get_post_stati( array(), 'names' ) ),
				'posts_per_page' => self::MAX_RECORDS + 1,
				'fields'         => 'ids',
			)
		);
		return count( $ids );
	}

	/**
	 * Stores the first authoritative state payload.
	 *
	 * @param int                 $post_id Record ID.
	 * @param array<string,mixed> $state   State.
	 * @return array<string,mixed>|WP_Error
	 */
	private function store_initial_state( $post_id, $state ) {
		$json = $this->encode_state( $state );
		if ( false === $json || ! add_post_meta( $post_id, self::META_STATE, wp_slash( $json ), true ) ) {
			return new WP_Error( 'workspace_write_failed', __( 'WordPress could not save the Workspace state.', 'wp-ai-bridge' ) );
		}
		return $this->decorate_state( $post_id, $state, $json );
	}

	/**
	 * Reads one authoritative state payload.
	 *
	 * @param int    $id             Record ID.
	 * @param string $expected_type  Expected post type.
	 * @param string $not_found_code Error code.
	 * @return array<string,mixed>|WP_Error
	 */
	private function read_record( $id, $expected_type, $not_found_code ) {
		$post = get_post( $id );
		if ( ! $post || $expected_type !== $post->post_type ) {
			return new WP_Error( $not_found_code, __( 'The requested Workspace record was not found.', 'wp-ai-bridge' ) );
		}

		$rows = get_post_meta( $id, self::META_STATE, false );
		if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! is_string( $rows[0] ) ) {
			return new WP_Error( 'workspace_state_invalid', __( 'The Workspace record state is invalid.', 'wp-ai-bridge' ) );
		}
		$json  = $rows[0];
		$state = $this->decode_state( $json );
		$kind  = self::DOCUMENT_POST_TYPE === $expected_type ? 'document' : 'task';
		if ( null === $state || $kind !== $state['kind'] ) {
			return new WP_Error( 'workspace_state_invalid', __( 'The Workspace record state is invalid.', 'wp-ai-bridge' ) );
		}

		return $this->decorate_state( $id, $state, $json );
	}

	/**
	 * Mutates one state document through an atomic meta compare-and-swap.
	 *
	 * @param int      $id             Record ID.
	 * @param string   $expected_type  Expected post type.
	 * @param array    $input          Input including expected identity.
	 * @param string   $not_found_code Error code.
	 * @param callable $mutator        State mutator.
	 * @return array<string,mixed>|WP_Error
	 */
	private function mutate_record( $id, $expected_type, $input, $not_found_code, $mutator ) {
		$post = get_post( $id );
		if ( ! $post || $expected_type !== $post->post_type ) {
			return new WP_Error( $not_found_code, __( 'The requested Workspace record was not found.', 'wp-ai-bridge' ) );
		}

		$rows          = get_post_meta( $id, self::META_STATE, false );
		$current_json  = is_array( $rows ) && 1 === count( $rows ) && is_string( $rows[0] ) ? $rows[0] : '';
		$current_state = $this->decode_state( $current_json );
		$kind          = self::DOCUMENT_POST_TYPE === $expected_type ? 'document' : 'task';
		if ( null === $current_state || $kind !== $current_state['kind'] ) {
			return new WP_Error( 'workspace_state_invalid', __( 'The Workspace record state is invalid.', 'wp-ai-bridge' ) );
		}

		$expected_version = isset( $input['expected_version'] ) ? (int) $input['expected_version'] : 0;
		$expected_hash    = isset( $input['expected_state_hash'] ) ? strtolower( trim( (string) $input['expected_state_hash'] ) ) : '';
		$current_hash     = $this->state_hash( $current_json );
		if ( $expected_version < 1 || ! preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
			return new WP_Error( 'workspace_expected_identity_required', __( 'expected_version and expected_state_hash are required for Workspace updates.', 'wp-ai-bridge' ) );
		}
		if ( (int) $current_state['version'] !== $expected_version || ! hash_equals( $current_hash, $expected_hash ) ) {
			return new WP_Error( 'workspace_stale', __( 'The Workspace record changed after it was inspected. Refresh it before applying this update.', 'wp-ai-bridge' ) );
		}

		$next_state = call_user_func( $mutator, $current_state );
		if ( is_wp_error( $next_state ) ) {
			return $next_state;
		}
		if ( ! is_array( $next_state ) ) {
			return new WP_Error( 'workspace_write_failed', __( 'The Workspace mutation did not produce a valid state.', 'wp-ai-bridge' ) );
		}

		$next_state['version']      = $expected_version + 1;
		$next_state['modified_gmt'] = gmdate( 'c' );
		$next_json                  = $this->encode_state( $next_state );
		if ( false === $next_json ) {
			return new WP_Error( 'workspace_write_failed', __( 'WordPress could not encode the Workspace state.', 'wp-ai-bridge' ) );
		}

		$updated = update_post_meta( $id, self::META_STATE, wp_slash( $next_json ), $current_json );
		if ( false === $updated ) {
			$latest_json = (string) get_post_meta( $id, self::META_STATE, true );
			if ( $latest_json !== $current_json ) {
				return new WP_Error( 'workspace_stale', __( 'The Workspace record changed after it was inspected. Refresh it before applying this update.', 'wp-ai-bridge' ) );
			}
			return new WP_Error( 'workspace_write_failed', __( 'WordPress could not update the Workspace state.', 'wp-ai-bridge' ) );
		}

		$verified_json = (string) get_post_meta( $id, self::META_STATE, true );
		if ( $verified_json !== $next_json ) {
			return new WP_Error( 'workspace_write_failed', __( 'The Workspace update could not be verified.', 'wp-ai-bridge' ) );
		}

		return $this->decorate_state( $id, $next_state, $next_json );
	}

	/**
	 * JSON-encodes one authoritative state payload deterministically.
	 *
	 * @param array<string,mixed> $state State.
	 * @return string|false
	 */
	private function encode_state( $state ) {
		return wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Decodes and minimally validates one state payload.
	 *
	 * @param string $json JSON state.
	 * @return array<string,mixed>|null
	 */
	private function decode_state( $json ) {
		if ( '' === $json ) {
			return null;
		}
		$state = json_decode( $json, true );
		if ( ! is_array( $state ) || ! isset( $state['kind'], $state['version'], $state['created_gmt'], $state['modified_gmt'] ) ||
			! in_array( $state['kind'], array( 'document', 'task' ), true ) ||
			! is_int( $state['version'] ) || $state['version'] < 1 ||
			! is_string( $state['created_gmt'] ) || ! is_string( $state['modified_gmt'] ) ||
			! array_key_exists( 'archived', $state ) || ! is_bool( $state['archived'] ) ||
			! isset( $state['title'] ) || ! is_string( $state['title'] ) ) {
			return null;
		}
		if ( 'document' === $state['kind'] && ( ! isset( $state['key'], $state['content'] ) ||
			! is_string( $state['key'] ) || ! is_string( $state['content'] ) ) ) {
			return null;
		}
		if ( 'task' === $state['kind'] && ( ! isset( $state['progress'], $state['review'], $state['delivery'] ) ||
			! is_string( $state['progress'] ) || ! is_string( $state['review'] ) ||
			! is_string( $state['delivery'] ) ) ) {
			return null;
		}
		return $state;
	}

	/**
	 * Decorates state with public object identity.
	 *
	 * @param int                 $id    Record ID.
	 * @param array<string,mixed> $state State.
	 * @param string              $json  Exact stored state JSON.
	 * @return array<string,mixed>
	 */
	private function decorate_state( $id, $state, $json ) {
		$result                = $state;
		$result['project_ref'] = isset( $result['project_ref'] ) && is_string( $result['project_ref'] ) ? $result['project_ref'] : '';
		if ( 'task' === ( $result['kind'] ?? '' ) ) {
			$result['next_action'] = isset( $result['next_action'] ) ? (string) $result['next_action'] : '';
			$result['blocker']     = isset( $result['blocker'] ) ? (string) $result['blocker'] : '';
		}
		$result['id']         = (int) $id;
		$result['state_hash'] = $this->state_hash( $json );
		return $result;
	}

	/**
	 * Hashes the exact mutation-relevant state payload.
	 *
	 * @param string $json State JSON.
	 * @return string
	 */
	private function state_hash( $json ) {
		return hash( 'sha256', $json );
	}

	/**
	 * Produces a compact task summary for resume output.
	 *
	 * @param array<string,mixed> $task Task.
	 * @return array<string,mixed>
	 */
	private function task_summary( $task ) {
		$project = isset( $task['project_ref'] ) ? (string) $task['project_ref'] : '';
		$title   = $this->summary_prefix( (string) $task['title'], 160 );
		$next    = $this->summary_prefix( (string) ( $task['next_action'] ?? '' ), 240 );
		$blocker = $this->summary_prefix( (string) ( $task['blocker'] ?? '' ), 240 );
		return array(
			'id'               => (int) $task['id'],
			'project_ref'      => $project,
			'title'            => $title,
			'progress'         => (string) $task['progress'],
			'review'           => (string) $task['review'],
			'delivery'         => (string) $task['delivery'],
			'next_action'      => $next,
			'blocker'          => $blocker,
			'truncated_fields' => array_values(
				array_filter(
					array(
						strlen( (string) $task['title'] ) > strlen( $title ) ? 'title' : '',
						strlen( (string) ( $task['next_action'] ?? '' ) ) > strlen( $next ) ? 'next_action' : '',
						strlen( (string) ( $task['blocker'] ?? '' ) ) > strlen( $blocker ) ? 'blocker' : '',
					)
				)
			),
			'archived'         => (bool) $task['archived'],
			'version'          => (int) $task['version'],
			'state_hash'       => (string) $task['state_hash'],
			'modified_gmt'     => (string) $task['modified_gmt'],
		);
	}

	/**
	 * Produces a compact document index entry for resume output.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return array<string,mixed>
	 */
	private function document_summary( $document ) {
		$title = $this->summary_prefix( (string) $document['title'], 160 );
		return array(
			'id'               => (int) $document['id'],
			'project_ref'      => (string) ( $document['project_ref'] ?? '' ),
			'key'              => (string) $document['key'],
			'title'            => $title,
			'truncated_fields' => strlen( (string) $document['title'] ) > strlen( $title ) ? array( 'title' ) : array(),
			'archived'         => (bool) $document['archived'],
			'version'          => (int) $document['version'],
			'state_hash'       => (string) $document['state_hash'],
			'modified_gmt'     => (string) $document['modified_gmt'],
		);
	}

	/** @param string $text Original UTF-8 text. @param int $bytes Max bytes. @return string */
	private function summary_prefix( $text, $bytes ) {
		if ( strlen( $text ) <= $bytes ) {
			return $text;
		}
		$prefix = substr( $text, 0, $bytes );
		while ( '' !== $prefix && 1 !== preg_match( '//u', $prefix ) ) {
			$prefix = substr( $prefix, 0, -1 );
		}
		return $prefix;
	}

	/**
	 * Reject values that would be silently truncated, stripped or normalized.
	 * Existing legacy records are not rewritten or migrated.
	 *
	 * @param array<string,mixed> $input Input fields.
	 * @param string              $kind  Record type.
	 * @return true|WP_Error
	 */
	private function validate_input_fidelity( $input, $kind ) {
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'workspace_invalid_input', __( 'Workspace input must be a valid object.', 'wp-ai-bridge' ) );
		}
		$limits = 'document' === $kind
			? array(
				'title'   => 500,
				'content' => self::MAX_DOCUMENT_BYTES,
			)
			: array(
				'title'       => 500,
				'goal'        => 5000,
				'notes'       => self::MAX_NOTES_BYTES,
				'next_action' => 1000,
				'blocker'     => 1000,
			);
		foreach ( $limits as $name => $limit ) {
			if ( ! array_key_exists( $name, $input ) ) {
				continue;
			}
			if ( ! is_string( $input[ $name ] ) || strlen( $input[ $name ] ) > $limit ||
				1 !== preg_match( '//u', $input[ $name ] ) ) {
				return new WP_Error( 'workspace_lossy_input', __( 'Workspace input exceeds its byte limit or is not valid UTF-8; nothing was saved.', 'wp-ai-bridge' ) );
			}
			$cleaned = 'content' === $name
				? wp_kses_post( $input[ $name ] )
				: ( in_array( $name, array( 'goal', 'notes', 'next_action', 'blocker' ), true )
					? sanitize_textarea_field( $input[ $name ] )
					: sanitize_text_field( $input[ $name ] ) );
			if ( $cleaned !== $input[ $name ] ) {
				return new WP_Error( 'workspace_lossy_input', __( 'Workspace text would change during sanitization; correct it before saving.', 'wp-ai-bridge' ) );
			}
		}
		if ( array_key_exists( 'project_ref', $input ) &&
			( ! is_string( $input['project_ref'] ) ||
				1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $input['project_ref'] ) ) ) {
			return new WP_Error( 'workspace_invalid_project', __( 'project_ref must identify one explicit project with 1 to 128 URL-safe characters.', 'wp-ai-bridge' ) );
		}
		if ( 'document' === $kind && array_key_exists( 'key', $input ) &&
			( ! is_string( $input['key'] ) || '' === $input['key'] || strlen( $input['key'] ) > 100 ||
				sanitize_key( $input['key'] ) !== $input['key'] ) ) {
			return new WP_Error( 'workspace_invalid_document', __( 'The Workspace document key must already be a valid canonical key.', 'wp-ai-bridge' ) );
		}
		if ( 'task' === $kind ) {
			foreach ( array( 'acceptance', 'dependencies', 'target_refs' ) as $field ) {
				if ( ! array_key_exists( $field, $input ) ) {
					continue;
				}
				$items = $input[ $field ];
				if ( ! is_array( $items ) || count( $items ) > self::MAX_LIST_ITEMS || ( $items && array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) ) {
					return new WP_Error( 'workspace_lossy_input', __( 'Workspace list exceeds limits or is not a dense list; nothing was saved.', 'wp-ai-bridge' ) );
				}
				foreach ( $items as $item ) {
					if ( ! is_string( $item ) || '' === $item || strlen( $item ) > self::MAX_ITEM_BYTES ||
						1 !== preg_match( '//u', $item ) || sanitize_textarea_field( $item ) !== $item ) {
						return new WP_Error( 'workspace_lossy_input', __( 'Workspace list contains an invalid or lossy item; nothing was saved.', 'wp-ai-bridge' ) );
					}
				}
			}
		}
		return true;
	}

	/**
	 * Sanitizes and bounds plain text.
	 *
	 * @param mixed $value     Input value.
	 * @param int   $max_bytes Maximum byte length.
	 * @param bool  $multiline Whether line breaks are retained.
	 * @return string
	 */
	private function bounded_text( $value, $max_bytes, $multiline ) {
		$text = $multiline ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
		if ( strlen( $text ) > $max_bytes ) {
			$text = substr( $text, 0, $max_bytes );
			$text = wp_check_invalid_utf8( $text, true );
		}
		return $text;
	}

	/**
	 * Sanitizes and bounds Markdown-oriented document content.
	 *
	 * @param mixed $value     Input content.
	 * @param int   $max_bytes Maximum byte length.
	 * @return string
	 */
	private function bounded_markdown( $value, $max_bytes ) {
		$text = wp_kses_post( (string) $value );
		if ( strlen( $text ) > $max_bytes ) {
			$text = substr( $text, 0, $max_bytes );
			$text = wp_check_invalid_utf8( $text, true );
		}
		return $text;
	}

	/**
	 * Sanitizes one bounded list of concise strings.
	 *
	 * @param mixed $values Input values.
	 * @return array<int,string>
	 */
	private function bounded_string_list( $values ) {
		if ( ! is_array( $values ) ) {
			return array();
		}
		$result = array();
		foreach ( array_slice( $values, 0, self::MAX_LIST_ITEMS ) as $value ) {
			$item = $this->bounded_text( $value, self::MAX_ITEM_BYTES, true );
			if ( '' !== $item ) {
				$result[] = $item;
			}
		}
		return $result;
	}

	/**
	 * Validates one enum value.
	 *
	 * @param mixed             $value   Value.
	 * @param array<int,string> $allowed Allowed values.
	 * @return string|null
	 */
	private function enum_value( $value, $allowed ) {
		$value = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : null;
	}
}
