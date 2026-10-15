<?php
/**
 * Persistent Workspace storage.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Workspace;

use WP_Error;
use WP_AI_Bridge\Support\Bounded_Payload;
use WP_AI_Bridge\Support\Payload_Settings;
use WP_AI_Bridge\Support\Workspace_Limits;
use WP_AI_Bridge\Support\Create_Claim;
use WP_AI_Bridge\Support\Admission_Lock;

/**
 * Stores compact Workspace documents and tasks in private WordPress objects.
 */
final class Store {
	const DOCUMENT_POST_TYPE   = 'wpai_doc';
	const TASK_POST_TYPE       = 'wpai_task';
	const META_STATE           = '_wpai_workspace_state';
	const MAX_RECORDS          = Workspace_Limits::DEFAULT_DOCUMENTS; // Legacy default/fixture compatibility.
	const MAX_COLLECTION_BYTES = 16777216; // Avoid full in-memory snapshots beyond bounded PHP resources.
	const MAX_DOCUMENT_BYTES   = Payload_Settings::DEFAULT_DOCUMENT_KIB * 1024;
	const MAX_NOTES_BYTES      = Payload_Settings::DEFAULT_NOTES_KIB * 1024;
	const MAX_LIST_ITEMS       = 25;
	const MAX_ITEM_BYTES       = 1000;

	/**
	 * Per-site Workspace record limits, independent of MCP response budgets.
	 *
	 * @return int
	 */
	public static function document_limit() {
		$settings = new Payload_Settings();
		return $settings->all()[ Payload_Settings::DOCUMENT_KEY ] * 1024;
	}

	/** @return int */
	private function record_limit( $post_type ) {
		$limits = ( new Workspace_Limits() )->all();
		return self::DOCUMENT_POST_TYPE === $post_type ? $limits[ Workspace_Limits::DOCUMENTS_KEY ] : $limits[ Workspace_Limits::TASKS_KEY ];
	}

	/** @return int */
	public static function notes_limit() {
		$settings = new Payload_Settings();
		return $settings->all()[ Payload_Settings::NOTES_KEY ] * 1024;
	}

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
		$lock = Admission_Lock::acquire( 'documents' );
		if ( ! $lock ) {
			return new WP_Error( 'workspace_admission_unavailable', __( 'Workspace capacity admission is unavailable; no create was attempted.', 'wp-ai-bridge' ) );
		}
		try {
			return $this->create_document_locked( $input, $lock );
		} finally {
			$lock->release();
		}
	}

	/** @param array $input Validated create input. @param Admission_Lock $lock Exclusive site/type admission lock. @return array|WP_Error */
	private function create_document_locked( $input, $lock ) {
		if ( ! $lock->is_owned() ) {
			return new WP_Error( 'workspace_admission_unavailable', __( 'Workspace capacity admission is unavailable; no create was attempted.', 'wp-ai-bridge' ) );
		}
		$count = $this->record_count( self::DOCUMENT_POST_TYPE );
		if ( is_wp_error( $count ) ) {
			return $count;
		}
		if ( $count >= $this->record_limit( self::DOCUMENT_POST_TYPE ) ) {
			return new WP_Error( 'workspace_document_limit', __( 'The Workspace document record-count policy has been reached; an administrator can adjust the per-site limit.', 'wp-ai-bridge' ) );
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
			$existing = $this->find_project_key( $project_ref, $key, 0 );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			if ( $existing > 0 ) {
				return new WP_Error( 'workspace_key_exists', __( 'This project already has a document with the same canonical key, possibly archived. Inspect or unarchive that record.', 'wp-ai-bridge' ), array( 'id' => $existing ) );
			}
			$claim = Create_Claim::reserve_document_key( $project_ref, $key );
			if ( is_wp_error( $claim ) ) {
				return $claim;
			}
		}
		$content = $this->bounded_markdown( $input['content'] ?? '', self::document_limit() );
		$now     = gmdate( 'c' );
		if ( ! $lock->is_owned() ) {
			return new WP_Error( 'workspace_admission_unavailable', __( 'Workspace capacity admission is unavailable; no create was attempted.', 'wp-ai-bridge' ) );
		}
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

		// If the DB session reconnected during WordPress hooks, a committed
		// object must not be falsely reported as a failed/nonexistent create.
		if ( ! $lock->is_owned() ) {
			return new WP_Error( 'workspace_admission_outcome_unknown', __( 'Workspace object may have committed after its admission lock was lost; inspect its ID before retrying.', 'wp-ai-bridge' ), array( 'id' => (int) $post_id ) );
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
			$existing = $this->find_project_key( $next_project, $next_key, (int) $id );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			if ( $existing > 0 ) {
				return new WP_Error( 'workspace_key_exists', __( 'A document with this canonical key already exists for the project.', 'wp-ai-bridge' ) );
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
					$state['content'] = $this->bounded_markdown( $input['content'], self::document_limit() );
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
	 * Decode each authoritative JSON record from a bounded read-only DB scan.
	 *
	 * @param string $post_type Exact private type.
	 * @param callable $consumer Receives decoded record.
	 * @return int|WP_Error
	 */
	private function walk_records( $post_type, $consumer ) {
		$kind = self::DOCUMENT_POST_TYPE === $post_type ? 'document' : 'task';
		return ( new Record_Scan_Store() )->walk(
			$post_type,
			function ( $id, $json ) use ( $kind, $consumer ) {
				$state = $this->decode_state( $json );
				if ( null === $state || $kind !== $state['kind'] ) {
					return new WP_Error( 'workspace_incomplete', __( 'One or more Workspace records could not be decoded; results cannot be claimed complete.', 'wp-ai-bridge' ), array( 'id' => (int) $id ) );
				}
				// A fenced but incomplete delete is not a live object and must
				// never appear in a supposedly complete Workspace projection.
				if ( ! empty( $state['delete_pending'] ) ) {
					return new WP_Error( 'workspace_delete_outcome_unknown', __( 'Workspace record is pending permanent deletion.', 'wp-ai-bridge' ), array( 'id' => (int) $id ) );
				}
				return call_user_func( $consumer, $this->decorate_state( (int) $id, $state, $json ) );
			}
		);
	}

	/**
	 * Bound complete JSON collections to a fraction of current PHP memory:
	 * both record categories and a final encoded JSON export may coexist.
	 *
	 * @return int
	 */
	private function collection_budget() {
		$memory = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) : -1;
		return is_int( $memory ) && $memory > 0
			? min( self::MAX_COLLECTION_BYTES, max( 1048576, intdiv( $memory, 16 ) ) )
			: self::MAX_COLLECTION_BYTES;
	}

	/**
	 * Complete in-memory collection only for small compatibility/export callers.
	 * Large workspaces require the bounded project-aware page/read APIs and
	 * native WordPress database backup, never a silently truncated JSON export.
	 *
	 * @param string $post_type Exact private type.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function checked_records( $post_type ) {
		$items = array();
		$bytes = 0;
		$scan  = $this->walk_records(
			$post_type,
			function ( $item ) use ( &$items, &$bytes ) {
				$json = wp_json_encode( $item );
				if ( ! is_string( $json ) ) {
					return new WP_Error( 'workspace_incomplete', __( 'A Workspace record cannot be serialized without losing data.', 'wp-ai-bridge' ) );
				}
				$bytes += strlen( $json );
				if ( $bytes > $this->collection_budget() ) {
					return new WP_Error( 'workspace_collection_too_large', __( 'Workspace data exceeds the safe one-request export/list budget. Use bounded pages for AI and a verified WordPress database backup for full restoration.', 'wp-ai-bridge' ) );
				}
				$items[] = $item;
				return true;
			}
		);
		return is_wp_error( $scan ) ? $scan : $items;
	}


	/**
	 * Looks for any old or claimed same-project document key without loading
	 * the whole document collection. Includes archived records deliberately.
	 *
	 * @param string $project_ref Project reference.
	 * @param string $key Canonical key.
	 * @param int    $except_id Legacy record being bound.
	 * @return int|WP_Error
	 */
	private function find_project_key( $project_ref, $key, $except_id ) {
		$found = 0;
		$scan  = $this->walk_records(
			self::DOCUMENT_POST_TYPE,
			static function ( $record ) use ( $project_ref, $key, $except_id, &$found ) {
				if ( (int) $record['id'] !== $except_id && $record['project_ref'] === $project_ref && $record['key'] === $key ) {
					$found = (int) $record['id'];
				}
				return true;
			}
		);
		return is_wp_error( $scan ) ? $scan : $found;
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
		$limit     = isset( $filters['limit'] ) ? $filters['limit'] : 10;
		$before_id = isset( $filters['before_id'] ) ? $filters['before_id'] : 0;
		if ( ! is_int( $limit ) || $limit < 1 || $limit > 25 || ! is_int( $before_id ) || $before_id < 0 ||
			( isset( $filters['project_ref'] ) && ! $this->valid_project_filter( $filters['project_ref'] ) ) ) {
			return new WP_Error( 'workspace_invalid_page', __( 'Workspace page selector or project reference is invalid.', 'wp-ai-bridge' ) );
		}
		$page       = array();
		$total      = 0;
		$candidates = 0;
		$summary    = self::TASK_POST_TYPE === $type ? 'task_summary' : 'document_summary';
		$scan       = $this->walk_records(
			$type,
			function ( $item ) use ( $filters, $type, $before_id, $limit, $summary, &$page, &$total, &$candidates ) {
				if ( ! empty( $filters['archived_only'] ) && empty( $item['archived'] ) ) {
					return true;
				}
				if ( empty( $filters['include_archived'] ) && ! empty( $item['archived'] ) ) {
					return true;
				}
				if ( array_key_exists( 'project_ref', $filters ) && $item['project_ref'] !== $filters['project_ref'] ) {
					return true;
				}
				if ( self::TASK_POST_TYPE === $type ) {
					foreach ( array( 'progress', 'review', 'delivery' ) as $field ) {
						if ( isset( $filters[ $field ] ) && '' !== $filters[ $field ] && $item[ $field ] !== $filters[ $field ] ) {
							return true;
						}
					}
				}
				++$total;
				if ( 0 === $before_id || (int) $item['id'] < $before_id ) {
					++$candidates;
					if ( count( $page ) < $limit ) {
						$page[] = $this->{$summary}( $item );
					}
				}
				return true;
			}
		);
		if ( is_wp_error( $scan ) ) {
			return $scan;
		}
		$more   = $candidates > count( $page );
		$result = array(
			'items'       => $page,
			'total'       => $total,
			'returned'    => count( $page ),
			'has_more'    => $more,
			'next_cursor' => $more && $page ? (int) $page[ count( $page ) - 1 ]['id'] : 0,
			'complete'    => ! $more,
		);
		return Bounded_Payload::fits( $result )
			? $result
			: new WP_Error( 'workspace_page_too_large', __( 'Workspace summary page exceeds the response budget; request a smaller limit.', 'wp-ai-bridge' ) );
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
		$limit  = isset( $input['max_bytes'] ) ? $input['max_bytes'] : Bounded_Payload::window_limit();
		if ( ! is_int( $limit ) || $limit < 1 || $limit > Bounded_Payload::window_limit() ) {
			return new WP_Error( 'invalid_content_window', __( 'The requested content window is outside the supported bounds.', 'wp-ai-bridge' ) );
		}
		$summary = 'document' === $kind ? $this->document_summary( $record ) : $this->task_summary( $record );
		// The administrator's maximum is not a promise that Unicode-escaped
		// JSON can carry that many source bytes in one response. Shrink a
		// single response automatically while preserving exact byte offsets.
		for ( $size = $limit; $size >= 1; $size = 1 === $size ? 0 : max( 1, (int) floor( $size / 2 ) ) ) {
			$window = Bounded_Payload::text_window( (string) $record[ $field ], $offset, $size );
			if ( is_wp_error( $window ) ) {
				// An invalid UTF-8 offset or encoding must not be
				// disguised as a response-size failure.
				return $window;
			}
			$summary['field_window'] = array(
				'field'       => $field,
				'text'        => $window['content'],
				'total_bytes' => $window['total_bytes'],
				'next_offset' => $window['next_offset'],
				'complete'    => $window['complete'],
			);
			$result                  = array( 'items' => array( $summary ) );
			if ( Bounded_Payload::fits( $result ) ) {
				return $result;
			}
		}
		return new WP_Error( 'workspace_window_too_large', __( 'Workspace window exceeds the serialized response budget; reduce max_bytes.', 'wp-ai-bridge' ) );
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
		$lock = Admission_Lock::acquire( 'tasks' );
		if ( ! $lock ) {
			return new WP_Error( 'workspace_admission_unavailable', __( 'Workspace capacity admission is unavailable; no create was attempted.', 'wp-ai-bridge' ) );
		}
		try {
			return $this->create_task_locked( $input, $lock );
		} finally {
			$lock->release();
		}
	}

	/** @param array $input Validated create input. @param Admission_Lock $lock Exclusive site/type admission lock. @return array|WP_Error */
	private function create_task_locked( $input, $lock ) {
		if ( ! $lock->is_owned() ) {
			return new WP_Error( 'workspace_admission_unavailable', __( 'Workspace capacity admission is unavailable; no create was attempted.', 'wp-ai-bridge' ) );
		}
		$count = $this->record_count( self::TASK_POST_TYPE );
		if ( is_wp_error( $count ) ) {
			return $count;
		}
		if ( $count >= $this->record_limit( self::TASK_POST_TYPE ) ) {
			return new WP_Error( 'workspace_task_limit', __( 'The Workspace task record-count policy has been reached; an administrator can adjust the per-site limit.', 'wp-ai-bridge' ) );
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

		$now = gmdate( 'c' );
		if ( ! $lock->is_owned() ) {
			return new WP_Error( 'workspace_admission_unavailable', __( 'Workspace capacity admission is unavailable; no create was attempted.', 'wp-ai-bridge' ) );
		}
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
			'notes'        => $this->bounded_text( $input['notes'] ?? '', self::notes_limit(), true ),
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

		// If the DB session reconnected during WordPress hooks, a committed
		// object must not be falsely reported as a failed/nonexistent create.
		if ( ! $lock->is_owned() ) {
			return new WP_Error( 'workspace_admission_outcome_unknown', __( 'Workspace object may have committed after its admission lock was lost; inspect its ID before retrying.', 'wp-ai-bridge' ), array( 'id' => (int) $post_id ) );
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
					$state['notes'] = $this->bounded_text( $input['notes'], self::notes_limit(), true );
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
		$projects           = array();
		$projects_truncated = false;
		$unbound            = false;
		$counts             = array(
			'documents'          => 0,
			'tasks'              => 0,
			'active_tasks'       => 0,
			'blocked'            => 0,
			'review_needed'      => 0,
			'done_tasks'         => 0,
			'documents_total'    => 0,
			'tasks_total'        => 0,
			'documents_archived' => 0,
			'tasks_archived'     => 0,
		);
		$sections           = array(
			'active_tasks'  => array(),
			'blocked_tasks' => array(),
			'review_needed' => array(),
			'documents'     => array(),
		);
		$section_totals     = array_fill_keys( array_keys( $sections ), 0 );
		$focus              = '';
		$last_modified      = '';
		$track_project      = static function ( $item ) use ( &$projects, &$projects_truncated, &$unbound ) {
			$reference = (string) $item['project_ref'];
			if ( '' === $reference ) {
				$unbound = true;
				return;
			}
			if ( isset( $projects[ $reference ] ) ) {
				return;
			}
			// Retain only the lexicographically first 25 distinct project
			// references. This preserves deterministic discovery without a
			// 50k-item per-request PHP set for a very large Workspace.
			if ( 25 === count( $projects ) ) {
				$projects_truncated = true;
				ksort( $projects, SORT_STRING );
				$largest = array_key_last( $projects );
				if ( strcmp( $reference, $largest ) >= 0 ) {
					return;
				}
				unset( $projects[ $largest ] );
			}
			$projects[ $reference ] = true;
		};
		$track_modified     = static function ( $item ) use ( &$last_modified ) {
			if ( '' === $last_modified || strcmp( $item['modified_gmt'], $last_modified ) > 0 ) {
				$last_modified = $item['modified_gmt'];
			}
		};

		$task_scan = $this->walk_records(
			self::TASK_POST_TYPE,
			function ( $task ) use ( $project_ref, &$counts, &$sections, &$section_totals, &$focus, $track_project, $track_modified ) {
				$track_project( $task );
				++$counts['tasks_total'];
				if ( $task['archived'] ) {
					++$counts['tasks_archived'];
					return true;
				}
				if ( null !== $project_ref && $task['project_ref'] !== $project_ref ) {
					return true;
				}
				++$counts['tasks'];
				$track_modified( $task );
				$members = array();
				if ( 'done' === $task['progress'] ) {
					++$counts['done_tasks'];
				} else {
					++$counts['active_tasks'];
					$members[] = 'active_tasks';
					if ( '' === $focus && 'in_progress' === $task['progress'] ) {
						$focus = $task['title'];
					}
				}
				if ( 'blocked' === $task['progress'] ) {
					++$counts['blocked'];
					$members[] = 'blocked_tasks';
				}
				if ( in_array( $task['review'], array( 'pending', 'changes_requested' ), true ) ) {
					++$counts['review_needed'];
					$members[] = 'review_needed';
				}
				foreach ( $members as $section ) {
					++$section_totals[ $section ];
					if ( count( $sections[ $section ] ) < 10 ) {
						$sections[ $section ][] = $this->task_summary( $task );
					}
				}
				return true;
			}
		);
		if ( is_wp_error( $task_scan ) ) {
			return $task_scan;
		}
		$document_scan = $this->walk_records(
			self::DOCUMENT_POST_TYPE,
			function ( $document ) use ( $project_ref, &$counts, &$sections, &$section_totals, $track_project, $track_modified ) {
				$track_project( $document );
				++$counts['documents_total'];
				if ( $document['archived'] ) {
					++$counts['documents_archived'];
					return true;
				}
				if ( null !== $project_ref && $document['project_ref'] !== $project_ref ) {
					return true;
				}
				++$counts['documents'];
				++$section_totals['documents'];
				$track_modified( $document );
				if ( count( $sections['documents'] ) < 25 ) {
					$sections['documents'][] = $this->document_summary( $document );
				}
				return true;
			}
		);
		if ( is_wp_error( $document_scan ) ) {
			return $document_scan;
		}
		$project_refs = array_keys( $projects );
		sort( $project_refs, SORT_STRING );
		$scope = null !== $project_ref ? 'project' :
			( count( $projects ) + ( $unbound ? 1 : 0 ) > 1 ? 'mixed' :
				( count( $projects ) > 0 ? 'single_project' : 'legacy_site' ) );
		if ( 'mixed' === $scope ) {
			$focus = '';
		}
		$result = array(
			'site'                    => array(
				'name' => (string) get_bloginfo( 'name' ),
				'url'  => (string) home_url( '/' ),
			),
			'project_ref'             => null !== $project_ref ? $project_ref : '',
			'scope'                   => $scope,
			'project_refs'            => array_slice( $project_refs, 0, 25 ),
			'projects_truncated'      => $projects_truncated,
			'current_focus'           => $this->summary_prefix( $focus, 160 ),
			'current_focus_truncated' => strlen( $focus ) > strlen( $this->summary_prefix( $focus, 160 ) ),
			'counts'                  => $counts,
			'last_modified_gmt'       => $last_modified,
			'sections'                => array(),
		);
		foreach ( $sections as $name => $items ) {
			$result[ $name ]             = $items;
			$result['sections'][ $name ] = array(
				'total'       => $section_totals[ $name ],
				'returned'    => count( $items ),
				'has_more'    => $section_totals[ $name ] > count( $items ),
				'next_cursor' => $section_totals[ $name ] > count( $items ) && $items ? (int) end( $items )['id'] : 0,
			);
		}
		// Shrink only visibly marked projections if necessary to fit the wire.
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
	 * Exact raw record usage, scoped to this WordPress blog. Includes archived
	 * records because retention is explicit and no data is auto-purged.
	 *
	 * @return array<string,int>|WP_Error
	 */
	public function capacity_counts() {
		$documents = $this->record_count( self::DOCUMENT_POST_TYPE );
		if ( is_wp_error( $documents ) ) {
			return $documents;
		}
		$tasks = $this->record_count( self::TASK_POST_TYPE );
		if ( is_wp_error( $tasks ) ) {
			return $tasks;
		}
		return array(
			'documents' => $documents,
			'tasks'     => $tasks,
		);
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
		return ( new Record_Scan_Store() )->count( $post_type );
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

		if ( ! empty( $state['delete_pending'] ) ) {
			return new WP_Error( 'workspace_delete_outcome_unknown', __( 'Workspace record is pending permanent deletion.', 'wp-ai-bridge' ) );
		}
		return $this->decorate_state( $id, $state, $json );
	}


	/**
	 * Permanently delete ONLY the exact project-bound private record after a
	 * strong identity check and an atomic deletion fence. No bulk or legacy
	 * Workspace records can be deleted through this surface.
	 *
	 * The claim/key reservation ledger is intentionally untouched: a
	 * previously committed canonical project/key remains reserved, preserving
	 * the durable no-duplicate contract even after explicit deletion.
	 *
	 * @param array<string,mixed> $input     Exact WordPress site and record identity.
	 * @param callable            $authorize Fresh same-principal Ability authorization callback.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete_exact( $input, $authorize ) {
		if ( ! is_array( $input ) || 7 !== count( $input ) ||
			! isset( $input['kind'], $input['id'], $input['blog_id'], $input['project_ref'], $input['expected_version'], $input['expected_state_hash'], $input['confirmation'] ) ||
			! in_array( $input['kind'], array( 'document', 'task' ), true ) ||
			! is_int( $input['id'] ) || $input['id'] < 1 ||
			! is_int( $input['blog_id'] ) || (int) get_current_blog_id() !== $input['blog_id'] ||
			! is_string( $input['project_ref'] ) || '' === $input['project_ref'] || ! $this->valid_project_filter( $input['project_ref'] ) ||
			! is_int( $input['expected_version'] ) || $input['expected_version'] < 1 ||
			! is_string( $input['expected_state_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['expected_state_hash'] ) ||
			! is_string( $input['confirmation'] ) ||
			0 !== strcmp( sprintf( 'PERMANENTLY DELETE %s %d', strtoupper( $input['kind'] ), $input['id'] ), $input['confirmation'] ) ) {
			return new WP_Error( 'workspace_delete_invalid', __( 'Exact site, bound record identity, version/hash and explicit record-specific confirmation are required.', 'wp-ai-bridge' ) );
		}

		// Authorization must remain valid after admission waits and immediately
		// before the irreversible effect; an earlier Ability check is not enough.
		if ( ! $this->deletion_authorized( $authorize, $input ) ) {
			return new WP_Error( 'workspace_delete_denied', __( 'Current WordPress principal cannot permanently delete this private record.', 'wp-ai-bridge' ) );
		}

		$kind  = $input['kind'];
		$id    = $input['id'];
		$type  = 'document' === $kind ? self::DOCUMENT_POST_TYPE : self::TASK_POST_TYPE;
		$scope = 'document' === $kind ? 'documents' : 'tasks';
		$lock  = Admission_Lock::acquire( $scope );
		if ( ! $lock ) {
			return new WP_Error( 'workspace_delete_busy', __( 'Exclusive Workspace deletion admission is unavailable; no deletion was attempted.', 'wp-ai-bridge' ) );
		}
		$posts_table = Admission_Lock::current_posts_table();
		try {
			if ( ! $lock->is_owned() ) {
				return new WP_Error( 'workspace_delete_busy', __( 'Workspace deletion admission was lost; no deletion was attempted.', 'wp-ai-bridge' ) );
			}
			$post = get_post( $id );
			if ( ! $post || $type !== $post->post_type ) {
				return new WP_Error( 'workspace_delete_not_found', __( 'The exact private Workspace record was not found.', 'wp-ai-bridge' ) );
			}
			if ( ! $this->deletion_authorized( $authorize, $input ) ) {
				return new WP_Error( 'workspace_delete_denied', __( 'Current WordPress principal cannot permanently delete this private record.', 'wp-ai-bridge' ) );
			}
			$rows = get_post_meta( $id, self::META_STATE, false );
			if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! is_string( $rows[0] ) ) {
				return new WP_Error( 'workspace_state_invalid', __( 'Workspace state has missing or multiple authoritative rows; no deletion was attempted.', 'wp-ai-bridge' ) );
			}
			$original_json = $rows[0];
			$state         = $this->decode_state( $original_json );
			if ( null === $state || $kind !== $state['kind'] ||
				! isset( $state['project_ref'] ) || $state['project_ref'] !== $input['project_ref'] ) {
				return new WP_Error( 'workspace_delete_not_found', __( 'The exact bound private Workspace record was not found.', 'wp-ai-bridge' ) );
			}
			if ( ! empty( $state['delete_pending'] ) ) {
				return new WP_Error( 'workspace_delete_outcome_unknown', __( 'A previous exact-record deletion may be incomplete. Reconcile the record before retrying.', 'wp-ai-bridge' ) );
			}
			if ( $state['version'] !== $input['expected_version'] ||
				! hash_equals( $this->state_hash( $original_json ), $input['expected_state_hash'] ) ) {
				return new WP_Error( 'workspace_stale', __( 'Workspace state changed since the exact deletion was approved; refresh before retrying.', 'wp-ai-bridge' ) );
			}

			// Atomic metadata CAS fences every subsequent Store mutation. If
			// another writer won the race, do not attempt a destructive effect.
			$state['delete_pending'] = true;
			$state['version']        = $state['version'] + 1;
			$state['modified_gmt']   = gmdate( 'c' );
			$fenced_json             = $this->encode_state( $state );
			if ( ! $this->deletion_authorized( $authorize, $input ) ) {
				return new WP_Error( 'workspace_delete_denied', __( 'Current WordPress principal cannot permanently delete this private record.', 'wp-ai-bridge' ) );
			}
			if ( ! is_string( $fenced_json ) ) {
				return new WP_Error( 'workspace_delete_outcome_unknown', __( 'Deletion admission was not verified. Check the exact record before retrying.', 'wp-ai-bridge' ) );
			}
			if ( false === update_post_meta( $id, self::META_STATE, wp_slash( $fenced_json ), $original_json ) ) {
				// A failed/ambiguous CAS can still have committed before a
				// metadata callback aborted. Restore only when its exact
				// fenced row and all pre-native prerequisites are provable.
				return $this->pre_native_delete_aborted( $id, $type, $input, $original_json, $fenced_json, $posts_table, $scope, $lock );
			}
			// This guard runs AFTER committing the CAS fence. Failure is known
			// pre-native and must be compensated conditionally, not stranded.
			if ( ! $this->delete_gate_allows( $id, $type, $input, $fenced_json, $posts_table, $lock, $authorize ) ) {
				return $this->pre_native_delete_aborted( $id, $type, $input, $original_json, $fenced_json, $posts_table, $scope, $lock );
			}
			// Never enter nested native deletion when either targeted dispatcher
			// is already executing. An aborted nested hook could not be rebuilt
			// without interfering with the still-running outer hook.
			if ( doing_filter( 'pre_delete_post' ) || doing_action( 'before_delete_post' ) ) {
				return $this->pre_native_delete_aborted( $id, $type, $input, $original_json, $fenced_json, $posts_table, $scope, $lock );
			}
			// Enforce the pre_delete_post boundary and terminal callback
			// ordering: other hooks may change blog, grant, or DB session.
			$native_vetoed         = false;
			$bridge_vetoed         = false;
			$native_delete_started = false;
			$pre_hook_order_abort  = new \RuntimeException( 'Workspace pre-delete hook guard is no longer terminal.' );
			$guard_pre_delete      = null;
			$guard_pre_delete      = function (
				$check,
				$candidate,
				$force
			) use (
				$id,
				$type,
				$input,
				$fenced_json,
				$posts_table,
				$lock,
				$authorize,
				$pre_hook_order_abort,
				&$guard_pre_delete,
				&$native_vetoed,
				&$bridge_vetoed
			) {
				if ( $candidate instanceof \WP_Post && (int) $candidate->ID === $id && true === $force ) {
					// Throwing, rather than returning false, ensures any callback
					// appended AFTER this sentinel cannot reset the veto to null.
					if ( ! $this->is_terminal_delete_guard( 'pre_delete_post', $guard_pre_delete ) ) {
						throw $pre_hook_order_abort;
					}
					if ( null !== $check ) {
						$native_vetoed = true;
						return $check;
					}
					$allowed = $type === $candidate->post_type &&
						$this->delete_gate_allows( $id, $type, $input, $fenced_json, $posts_table, $lock, $authorize );
					// Permission callbacks also run arbitrary WP hooks and may
					// dynamically register callbacks after this sentinel.
					if ( ! $this->is_terminal_delete_guard( 'pre_delete_post', $guard_pre_delete ) ) {
						throw $pre_hook_order_abort;
					}
					if ( ! $allowed ) {
						$bridge_vetoed = true;
						return false;
					}
				}
				return $check;
			};
			$observe_delete_start  = static function ( $candidate_id ) use ( $id, &$native_delete_started ) {
				if ( (int) $candidate_id === $id ) {
					$native_delete_started = true;
				}
			};
			// Terminal native lifecycle check before Core cleanup, including
			// site drift or callbacks added after this sentinel. An exception
			// from this phase remains outcome_unknown; never auto-restore.
			$guard_native_start = null;
			$guard_native_start = function (
				$candidate_id,
				$candidate
			) use (
				$id,
				$type,
				$input,
				$fenced_json,
				$posts_table,
				$lock,
				$authorize,
				&$guard_native_start
			) {
				if ( (int) $candidate_id === $id ) {
					$allowed = $candidate instanceof \WP_Post && $type === $candidate->post_type &&
						$this->delete_gate_allows( $id, $type, $input, $fenced_json, $posts_table, $lock, $authorize );
					// Later callbacks cannot be allowed to run after the final
					// invariant check, even if dynamically registered while
					// before_delete_post is already executing.
					if ( ! $allowed || ! $this->is_terminal_delete_guard( 'before_delete_post', $guard_native_start ) ) {
						throw new \RuntimeException( 'Workspace native deletion gate rejected changed context or hook order.' );
					}
				}
			};
			add_filter( 'pre_delete_post', $guard_pre_delete, PHP_INT_MAX, 3 );
			add_action( 'before_delete_post', $observe_delete_start, PHP_INT_MIN, 1 );
			add_action( 'before_delete_post', $guard_native_start, PHP_INT_MAX, 2 );
			// WordPress WP_Hook does not use finally to unwind callback
			// execution. Preserve the exact outer filter stack so a controlled
			// abort can rebuild only the escaped hook frames.
			$native_hook_baseline = $GLOBALS['wp_current_filter'] ?? null;
			try {
				try {
					wp_delete_post( $id, true );
				} catch ( \Throwable $exception ) {
					if ( ! $this->restore_native_hook_dispatch( $native_hook_baseline ) ) {
						// Never continue the WordPress request with a dispatcher
						// whose execution state cannot be reconstructed safely.
						throw $exception;
					}
					if ( $exception === $pre_hook_order_abort && ! $native_delete_started ) {
						// This EXACT exception belongs to our pre_delete_post
						// sentinel. Core has not entered before_delete_post,
						// and no dynamically appended tail callback has run.
						$bridge_vetoed = true;
					} else {
						return new WP_Error( 'workspace_delete_outcome_unknown', __( 'Exact deletion could not be confirmed from WordPress readback. Do not recreate the record.', 'wp-ai-bridge' ) );
					}
				}
			} finally {
				remove_action( 'before_delete_post', $guard_native_start, PHP_INT_MAX );
				remove_action( 'before_delete_post', $observe_delete_start, PHP_INT_MIN );
				remove_filter( 'pre_delete_post', $guard_pre_delete, PHP_INT_MAX );
			}
			if ( ! $native_delete_started && ( $native_vetoed || $bridge_vetoed ) ) {
				return $this->pre_native_delete_aborted(
					$id,
					$type,
					$input,
					$original_json,
					$fenced_json,
					$posts_table,
					$scope,
					$lock,
					$native_vetoed
				);
			}
			// Never inspect another blog's post with this numeric ID, or
			// infer verified deletion after losing exclusive DB admission.
			if ( (int) get_current_blog_id() !== $input['blog_id'] ||
				Admission_Lock::current_posts_table() !== $posts_table || ! $lock->is_owned() ) {
				return new WP_Error( 'workspace_delete_outcome_unknown', __( 'Exact deletion could not be confirmed from WordPress readback. Do not recreate the record.', 'wp-ai-bridge' ) );
			}
			$remaining_post  = get_post( $id );
			$remaining_state = get_post_meta( $id, self::META_STATE, false );
			if ( ! $remaining_post && ! $remaining_state ) {
				return array(
					'deleted' => true,
					'id'      => $id,
					'kind'    => $kind,
					'blog_id' => (int) get_current_blog_id(),
				);
			}
			return new WP_Error( 'workspace_delete_outcome_unknown', __( 'Exact deletion could not be confirmed from WordPress readback. Do not recreate the record.', 'wp-ai-bridge' ) );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Verify the exact site, private record, CAS fence and DB admission
	 * immediately around any authorization callback or third-party hook.
	 * Authorization hooks can themselves switch site or replace DB sessions.
	 */
	private function delete_gate_allows( $id, $type, $input, $fenced_json, $posts_table, $lock, $authorize ) {
		try {
			return $this->delete_fence_intact( $id, $type, $input, $fenced_json, $posts_table, $lock ) &&
				$this->deletion_authorized( $authorize, $input ) &&
				$this->delete_fence_intact( $id, $type, $input, $fenced_json, $posts_table, $lock );
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	/**
	 * WordPress can append same-priority callbacks while a hook is running.
	 * PHP_INT_MAX is a priority, NOT a guarantee of terminal execution.
	 * The last callback must be this exact sentinel after every callback-
	 * invoking authorization check; otherwise abort before Core cleanup.
	 *
	 * @param string   $hook Hook name.
	 * @param callable $sentinel Exact registered guard closure.
	 * @return bool Whether the hook has no callbacks after this guard.
	 */
	private function is_terminal_delete_guard( $hook, $sentinel ) {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return false;
		}
		$callbacks = $wp_filter[ $hook ]->callbacks[ PHP_INT_MAX ] ?? null;
		if ( ! is_array( $callbacks ) || ! $callbacks ) {
			return false;
		}
		$last = end( $callbacks );
		return is_array( $last ) && isset( $last['function'] ) && $last['function'] === $sentinel;
	}

	/**
	 * Reconstitute hook dispatchers after an exception escapes native Core
	 * hooks. WordPress' WP_Hook and global filter stack do not unwind in a
	 * finally block. Build fresh dispatchers using public WP_Hook APIs and
	 * preserve every callback, priority and insertion order, including any
	 * dynamically registered third-party callbacks.
	 *
	 * Never replace a dispatcher already running in an outer frame, or a
	 * custom/unknown dispatcher. On failed proof the caller must propagate
	 * the exception, not continue in an inconsistent WordPress request.
	 *
	 * @param array<int,string> $baseline Filter stack before wp_delete_post().
	 * @return bool True only after complete safe dispatcher restoration.
	 */
	private function restore_native_hook_dispatch( $baseline ) {
		global $wp_filter, $wp_current_filter;
		if ( ! is_array( $baseline ) || ! is_array( $wp_current_filter ) ||
			count( $wp_current_filter ) < count( $baseline ) ||
			array_slice( $wp_current_filter, 0, count( $baseline ) ) !== $baseline ) {
			return false;
		}
		$escaped = array_slice( $wp_current_filter, count( $baseline ) );
		if ( count( $escaped ) > 16 ) {
			return false;
		}
		$replacements = array();
		try {
			foreach ( array_unique( $escaped ) as $hook ) {
				if ( ! is_string( $hook ) || '' === $hook ||
					in_array( $hook, $baseline, true ) ||
					! isset( $wp_filter[ $hook ] ) ||
					get_class( $wp_filter[ $hook ] ) !== \WP_Hook::class ) {
					return false;
				}
				$previous = $wp_filter[ $hook ];
				$fresh    = new \WP_Hook();
				foreach ( $previous->callbacks as $priority => $callbacks ) {
					if ( ! is_int( $priority ) || ! is_array( $callbacks ) ) {
						return false;
					}
					foreach ( $callbacks as $callback ) {
						if ( ! is_array( $callback ) ||
							! array_key_exists( 'function', $callback ) ||
							! isset( $callback['accepted_args'] ) ||
							! is_int( $callback['accepted_args'] ) ) {
							return false;
						}
						$fresh->add_filter( $hook, $callback['function'], $priority, $callback['accepted_args'] );
					}
				}
				// Never silently drop, reorder or rewrite provider hooks.
				if ( $fresh->callbacks !== $previous->callbacks ) {
					return false;
				}
				$replacements[ $hook ] = array(
					'previous' => $previous,
					'fresh'    => $fresh,
				);
			}
		} catch ( \Throwable $exception ) {
			return false;
		}
		// The freshly built WP_Hook objects have no stale iteration, nesting
		// or doing_action state. Commit the swap only after full preflight.
		foreach ( $replacements as $hook => $pair ) {
			if ( ! isset( $wp_filter[ $hook ] ) || $wp_filter[ $hook ] !== $pair['previous'] ) {
				return false;
			}
		}
		foreach ( $replacements as $hook => $pair ) {
			$wp_filter[ $hook ] = $pair['fresh'];
		}
		$wp_current_filter = $baseline;
		return true;
	}

	/**
	 * Observational state proof; does not require a still-active destructive
	 * grant when compensating for an already-committed pre-native fence.
	 */
	private function delete_fence_intact( $id, $type, $input, $fenced_json, $posts_table, $lock ) {
		if ( (int) get_current_blog_id() !== $input['blog_id'] ||
			Admission_Lock::current_posts_table() !== $posts_table || ! $lock->is_owned() ) {
			return false;
		}
		$post = get_post( $id );
		$rows = get_post_meta( $id, self::META_STATE, false );
		return $post instanceof \WP_Post &&
			$type === $post->post_type &&
			array( $fenced_json ) === $rows &&
			(int) get_current_blog_id() === $input['blog_id'] &&
			Admission_Lock::current_posts_table() === $posts_table && $lock->is_owned();
	}

	/**
	 * Roll back only a conclusively pre-native exact-delete abort. On any
	 * ambiguous readback, lock acquisition or hook side effect, retain the
	 * delete_pending fence and require administrator reconciliation.
	 */
	private function restore_pre_native_delete( $id, $type, $input, $original_json, $fenced_json, $posts_table, $scope, $lock ) {
		$switched   = false;
		$reacquired = false;
		try {
			if ( (int) get_current_blog_id() !== $input['blog_id'] ) {
				if ( ! is_multisite() || ! switch_to_blog( $input['blog_id'] ) ) {
					return false;
				}
				$switched = true;
			}
			if ( (int) get_current_blog_id() !== $input['blog_id'] || Admission_Lock::current_posts_table() !== $posts_table ) {
				return false;
			}
			if ( ! $lock->is_owned() ) {
				// An expired DB session no longer owns admission. Never
				// compensate without first acquiring a fresh exclusive lock.
				$lock->release();
				$reacquired = Admission_Lock::acquire( $scope );
				if ( ! $reacquired ) {
					return false;
				}
			}
			$owned = $reacquired ? $reacquired : $lock;
			if ( ! $this->delete_fence_intact( $id, $type, $input, $fenced_json, $posts_table, $owned ) ||
				false === update_post_meta( $id, self::META_STATE, wp_slash( $original_json ), $fenced_json ) ) {
				return false;
			}
			$post = get_post( $id );
			return $post instanceof \WP_Post && $type === $post->post_type &&
				array( $original_json ) === get_post_meta( $id, self::META_STATE, false ) &&
				(int) get_current_blog_id() === $input['blog_id'] &&
				Admission_Lock::current_posts_table() === $posts_table && $owned->is_owned();
		} catch ( \Throwable $exception ) {
			return false;
		} finally {
			if ( $reacquired ) {
				$reacquired->release();
			}
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/** @return WP_Error A definite pre-native abort, or an unverifiable state. */
	private function pre_native_delete_aborted( $id, $type, $input, $original_json, $fenced_json, $posts_table, $scope, $lock, $external_veto = false ) {
		if ( $this->restore_pre_native_delete( $id, $type, $input, $original_json, $fenced_json, $posts_table, $scope, $lock ) ) {
			if ( $external_veto ) {
				return new WP_Error( 'workspace_delete_vetoed', __( 'WordPress vetoed permanent deletion; the unchanged Workspace record was restored.', 'wp-ai-bridge' ) );
			}
			return new WP_Error( 'workspace_delete_aborted', __( 'Permanent deletion was aborted before WordPress cleanup; the unchanged Workspace record was restored.', 'wp-ai-bridge' ) );
		}
		return new WP_Error( 'workspace_delete_outcome_unknown', __( 'Deletion is fenced but could not be safely completed. Administrator reconciliation is required.', 'wp-ai-bridge' ) );
	}

	/**
	 * Re-evaluates the authoritative Ability grant without caching it across
	 * lock waits, CAS or provider hooks. Thrown access filters fail closed.
	 *
	 * @param callable $authorize Current Ability authorization callback.
	 * @param array    $input     Exact record identity.
	 * @return bool
	 */
	private function deletion_authorized( $authorize, $input ) {
		try {
			return is_callable( $authorize ) && true === call_user_func( $authorize, $input );
		} catch ( \Throwable $exception ) {
			return false;
		}
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

		if ( ! empty( $current_state['delete_pending'] ) ) {
			return new WP_Error( 'workspace_delete_outcome_unknown', __( 'Workspace record is pending permanent deletion and cannot be modified.', 'wp-ai-bridge' ) );
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
		// An absent project_ref is a genuine legacy/unbound record. A PRESENT
		// malformed binding is corruption, never evidence of being unbound.
		if ( array_key_exists( 'project_ref', $state ) &&
			( ! is_string( $state['project_ref'] ) ||
				( '' !== $state['project_ref'] &&
					1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $state['project_ref'] ) ) ) ) {
			return null;
		}
		if ( 'document' === $state['kind'] &&
			( '' === $state['key'] || sanitize_key( $state['key'] ) !== $state['key'] ) ) {
			return null;
		}
		if ( 'task' === $state['kind'] ) {
			if ( ! isset( $state['progress'], $state['review'], $state['delivery'] ) ||
				! in_array( $state['progress'], array( 'todo', 'in_progress', 'blocked', 'done' ), true ) ||
				! in_array( $state['review'], array( 'not_required', 'pending', 'changes_requested', 'approved' ), true ) ||
				! in_array( $state['delivery'], array( 'not_applicable', 'draft_preview', 'live' ), true ) ) {
				return null;
			}
			foreach ( array( 'goal', 'notes', 'next_action', 'blocker' ) as $field ) {
				if ( array_key_exists( $field, $state ) && ! is_string( $state[ $field ] ) ) {
					return null;
				}
			}
			foreach ( array( 'acceptance', 'dependencies', 'target_refs' ) as $field ) {
				if ( ! array_key_exists( $field, $state ) ) {
					continue; // Old records may omit optional descriptive fields.
				}
				if ( ! is_array( $state[ $field ] ) ||
					array_values( $state[ $field ] ) !== $state[ $field ] ) {
					return null;
				}
				foreach ( $state[ $field ] as $entry ) {
					if ( ! is_string( $entry ) ) {
						return null;
					}
				}
			}
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
				'content' => self::document_limit(),
			)
			: array(
				'title'       => 500,
				'goal'        => 5000,
				'notes'       => self::notes_limit(),
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
