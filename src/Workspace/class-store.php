<?php
/**
 * Persistent Workspace storage.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Workspace;

use WP_Error;

/**
 * Stores compact Workspace documents and tasks in private WordPress objects.
 */
final class Store {
	const DOCUMENT_POST_TYPE = 'wpnb_doc';
	const TASK_POST_TYPE     = 'wpnb_task';
	const META_STATE         = '_wpnb_workspace_state';
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
					'label'  => __( 'WP AI Bridge Documents', 'wp-native-builder-bridge' ),
					'labels' => array(
						'name'          => __( 'Workspace Documents', 'wp-native-builder-bridge' ),
						'singular_name' => __( 'Workspace Document', 'wp-native-builder-bridge' ),
					),
				)
			)
		);

		register_post_type(
			self::TASK_POST_TYPE,
			array_merge(
				$common,
				array(
					'label'  => __( 'WP AI Bridge Tasks', 'wp-native-builder-bridge' ),
					'labels' => array(
						'name'          => __( 'Workspace Tasks', 'wp-native-builder-bridge' ),
						'singular_name' => __( 'Workspace Task', 'wp-native-builder-bridge' ),
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
		$items = $this->list_records( self::DOCUMENT_POST_TYPE );
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
		if ( $this->record_count( self::DOCUMENT_POST_TYPE ) >= self::MAX_RECORDS ) {
			return new WP_Error( 'workspace_document_limit', __( 'The Workspace document limit has been reached.', 'wp-native-builder-bridge' ) );
		}

		$title = $this->bounded_text( $input['title'] ?? '', 500, false );
		if ( '' === $title ) {
			return new WP_Error( 'workspace_invalid_document', __( 'A Workspace document title is required.', 'wp-native-builder-bridge' ) );
		}

		$key = isset( $input['key'] ) ? sanitize_key( (string) $input['key'] ) : '';
		if ( isset( $input['key'] ) && '' === $key ) {
			return new WP_Error( 'workspace_invalid_document', __( 'The Workspace document key must contain URL-safe letters, numbers, dashes, or underscores.', 'wp-native-builder-bridge' ) );
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
			return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'workspace_write_failed', __( 'WordPress could not create the Workspace document.', 'wp-native-builder-bridge' ) );
		}

		$state = array(
			'kind'         => 'document',
			'key'          => '' !== $key ? $key : 'document-' . (int) $post_id,
			'title'        => $title,
			'content'      => $content,
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
	 * Updates one Workspace document through optimistic compare-and-swap.
	 *
	 * @param int                 $id    Document ID.
	 * @param array<string,mixed> $input Input fields including expected identity.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update_document( $id, $input ) {
		return $this->mutate_record(
			(int) $id,
			self::DOCUMENT_POST_TYPE,
			$input,
			'workspace_document_not_found',
			function ( $state ) use ( $input ) {
				if ( array_key_exists( 'title', $input ) ) {
					$title = $this->bounded_text( $input['title'], 500, false );
					if ( '' === $title ) {
						return new WP_Error( 'workspace_invalid_document', __( 'A Workspace document title is required.', 'wp-native-builder-bridge' ) );
					}
					$state['title'] = $title;
				}

				if ( array_key_exists( 'key', $input ) ) {
					$key = sanitize_key( (string) $input['key'] );
					if ( '' === $key ) {
						return new WP_Error( 'workspace_invalid_document', __( 'The Workspace document key must contain URL-safe letters, numbers, dashes, or underscores.', 'wp-native-builder-bridge' ) );
					}
					$state['key'] = $key;
				}

				if ( array_key_exists( 'content', $input ) ) {
					$state['content'] = $this->bounded_markdown( $input['content'], self::MAX_DOCUMENT_BYTES );
				}

				return $state;
			}
		);
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
	 * Lists Workspace tasks with bounded filters.
	 *
	 * @param array<string,mixed> $filters Task filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_tasks( $filters = array() ) {
		$items            = $this->list_records( self::TASK_POST_TYPE );
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
		if ( $this->record_count( self::TASK_POST_TYPE ) >= self::MAX_RECORDS ) {
			return new WP_Error( 'workspace_task_limit', __( 'The Workspace task limit has been reached.', 'wp-native-builder-bridge' ) );
		}

		$title = $this->bounded_text( $input['title'] ?? '', 500, false );
		if ( '' === $title ) {
			return new WP_Error( 'workspace_invalid_task', __( 'A Workspace task title is required.', 'wp-native-builder-bridge' ) );
		}

		$progress = $this->enum_value( $input['progress'] ?? 'todo', array( 'todo', 'in_progress', 'blocked', 'done' ) );
		$review   = $this->enum_value( $input['review'] ?? 'not_required', array( 'not_required', 'pending', 'changes_requested', 'approved' ) );
		$delivery = $this->enum_value( $input['delivery'] ?? 'not_applicable', array( 'not_applicable', 'draft_preview', 'live' ) );
		if ( null === $progress || null === $review || null === $delivery ) {
			return new WP_Error( 'workspace_invalid_task', __( 'The Workspace task state is not valid.', 'wp-native-builder-bridge' ) );
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
			return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'workspace_write_failed', __( 'WordPress could not create the Workspace task.', 'wp-native-builder-bridge' ) );
		}

		$state = array(
			'kind'         => 'task',
			'title'        => $title,
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
		return $this->mutate_record(
			(int) $id,
			self::TASK_POST_TYPE,
			$input,
			'workspace_task_not_found',
			function ( $state ) use ( $input ) {
				if ( array_key_exists( 'title', $input ) ) {
					$title = $this->bounded_text( $input['title'], 500, false );
					if ( '' === $title ) {
						return new WP_Error( 'workspace_invalid_task', __( 'A Workspace task title is required.', 'wp-native-builder-bridge' ) );
					}
					$state['title'] = $title;
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
						return new WP_Error( 'workspace_invalid_task', __( 'The Workspace task state is not valid.', 'wp-native-builder-bridge' ) );
					}
					$state[ $field ] = $value;
					$changed         = true;
				}

				if ( ! $changed ) {
					return new WP_Error( 'workspace_invalid_task', __( 'At least one task progress, review, or delivery state is required for a transition.', 'wp-native-builder-bridge' ) );
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
	public function resume() {
		$tasks      = $this->list_tasks();
		$documents  = $this->list_documents();
		$active     = array();
		$blocked    = array();
		$review     = array();
		$focus      = '';
		$done_count = 0;

		foreach ( $tasks as $task ) {
			if ( 'done' === $task['progress'] ) {
				++$done_count;
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

		$document_index = array_map( array( $this, 'document_summary' ), array_slice( $documents, 0, 25 ) );
		$last_modified  = '';
		foreach ( array_merge( $tasks, $documents ) as $item ) {
			if ( isset( $item['modified_gmt'] ) && ( '' === $last_modified || strcmp( $item['modified_gmt'], $last_modified ) > 0 ) ) {
				$last_modified = $item['modified_gmt'];
			}
		}

		return array(
			'site'              => array(
				'name' => (string) get_bloginfo( 'name' ),
				'url'  => (string) home_url( '/' ),
			),
			'current_focus'     => $focus,
			'counts'            => array(
				'documents'     => count( $documents ),
				'tasks'         => count( $tasks ),
				'active_tasks'  => count( $active ),
				'blocked'       => count( $blocked ),
				'review_needed' => count( $review ),
				'done_tasks'    => $done_count,
			),
			'active_tasks'      => array_slice( $active, 0, 10 ),
			'blocked_tasks'     => array_slice( $blocked, 0, 10 ),
			'review_needed'     => array_slice( $review, 0, 10 ),
			'documents'         => $document_index,
			'last_modified_gmt' => $last_modified,
		);
	}

	/**
	 * Exports the current durable Workspace state.
	 *
	 * @return array<string,mixed>
	 */
	public function export_snapshot() {
		return array(
			'format'       => 'wp-native-builder-workspace-v1',
			'generated_at' => gmdate( 'c' ),
			'site'         => array(
				'name' => (string) get_bloginfo( 'name' ),
				'url'  => (string) home_url( '/' ),
			),
			'documents'    => $this->list_documents( true ),
			'tasks'        => $this->list_tasks( array( 'include_archived' => true ) ),
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
					return new WP_Error( 'workspace_clear_failed', __( 'WordPress could not completely clear the Workspace.', 'wp-native-builder-bridge' ) );
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
		return $resume['counts'];
	}

	/**
	 * Reads all valid records for one internal type.
	 *
	 * @param string $post_type Internal post type.
	 * @return array<int,array<string,mixed>>
	 */
	private function list_records( $post_type ) {
		$ids   = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => self::MAX_RECORDS,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $ids as $id ) {
			$item = $this->read_record( (int) $id, $post_type, 'workspace_not_found' );
			if ( ! is_wp_error( $item ) ) {
				$items[] = $item;
			}
		}

		usort(
			$items,
			static function ( $left, $right ) {
				return strcmp( (string) $right['modified_gmt'], (string) $left['modified_gmt'] );
			}
		);

		return $items;
	}

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
				'post_status'    => 'any',
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
			return new WP_Error( 'workspace_write_failed', __( 'WordPress could not save the Workspace state.', 'wp-native-builder-bridge' ) );
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
			return new WP_Error( $not_found_code, __( 'The requested Workspace record was not found.', 'wp-native-builder-bridge' ) );
		}

		$json  = (string) get_post_meta( $id, self::META_STATE, true );
		$state = $this->decode_state( $json );
		if ( null === $state ) {
			return new WP_Error( 'workspace_state_invalid', __( 'The Workspace record state is invalid.', 'wp-native-builder-bridge' ) );
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
			return new WP_Error( $not_found_code, __( 'The requested Workspace record was not found.', 'wp-native-builder-bridge' ) );
		}

		$current_json  = (string) get_post_meta( $id, self::META_STATE, true );
		$current_state = $this->decode_state( $current_json );
		if ( null === $current_state ) {
			return new WP_Error( 'workspace_state_invalid', __( 'The Workspace record state is invalid.', 'wp-native-builder-bridge' ) );
		}

		$expected_version = isset( $input['expected_version'] ) ? (int) $input['expected_version'] : 0;
		$expected_hash    = isset( $input['expected_state_hash'] ) ? strtolower( trim( (string) $input['expected_state_hash'] ) ) : '';
		$current_hash     = $this->state_hash( $current_json );
		if ( $expected_version < 1 || ! preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
			return new WP_Error( 'workspace_expected_identity_required', __( 'expected_version and expected_state_hash are required for Workspace updates.', 'wp-native-builder-bridge' ) );
		}
		if ( (int) $current_state['version'] !== $expected_version || ! hash_equals( $current_hash, $expected_hash ) ) {
			return new WP_Error( 'workspace_stale', __( 'The Workspace record changed after it was inspected. Refresh it before applying this update.', 'wp-native-builder-bridge' ) );
		}

		$next_state = call_user_func( $mutator, $current_state );
		if ( is_wp_error( $next_state ) ) {
			return $next_state;
		}
		if ( ! is_array( $next_state ) ) {
			return new WP_Error( 'workspace_write_failed', __( 'The Workspace mutation did not produce a valid state.', 'wp-native-builder-bridge' ) );
		}

		$next_state['version']      = $expected_version + 1;
		$next_state['modified_gmt'] = gmdate( 'c' );
		$next_json                  = $this->encode_state( $next_state );
		if ( false === $next_json ) {
			return new WP_Error( 'workspace_write_failed', __( 'WordPress could not encode the Workspace state.', 'wp-native-builder-bridge' ) );
		}

		$updated = update_post_meta( $id, self::META_STATE, wp_slash( $next_json ), $current_json );
		if ( false === $updated ) {
			$latest_json = (string) get_post_meta( $id, self::META_STATE, true );
			if ( $latest_json !== $current_json ) {
				return new WP_Error( 'workspace_stale', __( 'The Workspace record changed after it was inspected. Refresh it before applying this update.', 'wp-native-builder-bridge' ) );
			}
			return new WP_Error( 'workspace_write_failed', __( 'WordPress could not update the Workspace state.', 'wp-native-builder-bridge' ) );
		}

		$verified_json = (string) get_post_meta( $id, self::META_STATE, true );
		if ( $verified_json !== $next_json ) {
			return new WP_Error( 'workspace_write_failed', __( 'The Workspace update could not be verified.', 'wp-native-builder-bridge' ) );
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
		if ( ! is_array( $state ) || empty( $state['kind'] ) || empty( $state['version'] ) || empty( $state['created_gmt'] ) || empty( $state['modified_gmt'] ) ) {
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
		$result               = $state;
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
		return array(
			'id'           => (int) $task['id'],
			'title'        => (string) $task['title'],
			'progress'     => (string) $task['progress'],
			'review'       => (string) $task['review'],
			'delivery'     => (string) $task['delivery'],
			'version'      => (int) $task['version'],
			'state_hash'   => (string) $task['state_hash'],
			'modified_gmt' => (string) $task['modified_gmt'],
		);
	}

	/**
	 * Produces a compact document index entry for resume output.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return array<string,mixed>
	 */
	private function document_summary( $document ) {
		return array(
			'id'           => (int) $document['id'],
			'key'          => (string) $document['key'],
			'title'        => (string) $document['title'],
			'version'      => (int) $document['version'],
			'state_hash'   => (string) $document['state_hash'],
			'modified_gmt' => (string) $document['modified_gmt'],
		);
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
