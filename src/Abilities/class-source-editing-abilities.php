<?php
/**
 * Administrator-controlled installed plugin/theme source editing.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Abilities;

use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;
use WP_Error;

/**
 * Provides a fixed-purpose, recovery-aware source-editing lifecycle.
 */
final class Source_Editing_Abilities {
	const MAX_SOURCE_BYTES = 2097152;
	const RECOVERY_OPTION  = 'wp_ai_bridge_source_recovery';

	/** @var Permissions */
	private $permissions;

	/** @var Mutation_Log */
	private $log;

	/** @var \SplFileObject|null Exact target-inode advisory lock. */
	private $file_lock;

	/** @var \SplFileObject|null Stable path-keyed coordination lock across inode replacement. */
	private $coordination_lock;

	/** @var callable|null Deterministic replacement-boundary hook used only by direct test instances. */
	private $replacement_boundary_hook;

	/** @var callable|null Deterministic private-artifact cleanup hook used only by direct test instances. */
	private $replacement_artifact_cleanup_hook;

	/**
	 * Creates the provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log  $log                       Bounded mutation log.
	 * @param callable|null $replacement_boundary_hook         Optional deterministic CAS-boundary test hook.
	 * @param callable|null $replacement_artifact_cleanup_hook Optional deterministic private-artifact cleanup test hook.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log, $replacement_boundary_hook = null, $replacement_artifact_cleanup_hook = null ) {
		$this->permissions                       = $permissions;
		$this->log                               = $log;
		$this->file_lock                         = null;
		$this->coordination_lock                 = null;
		$this->replacement_boundary_hook         = is_callable( $replacement_boundary_hook ) ? $replacement_boundary_hook : null;
		$this->replacement_artifact_cleanup_hook = is_callable( $replacement_artifact_cleanup_hook ) ? $replacement_artifact_cleanup_hook : null;
	}

	/**
	 * Registers the typed source-editing abilities.
	 *
	 * @return void
	 */
	public function register() {
		$registered   = array();
		$registered[] = wp_register_ability(
			'wp-ai-bridge/source-files-read',
			array(
				'label'               => __( 'Read Installed Extension Source', 'wp-ai-bridge' ),
				'description'         => __( 'Lists or reads editable installed plugin/theme source files behind the explicit Source Editing trust boundary.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'permission_callback' => array( $this, 'can_source_action' ),
				'execute_callback'    => array( $this, 'read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/source-file-preview',
			array(
				'label'               => __( 'Preview Installed Extension Source Edit', 'wp-ai-bridge' ),
				'description'         => __( 'Validates an exact source candidate and binds it to the current installed-file preimage without writing the file.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->candidate_input_schema( false ),
				'output_schema'       => $this->preview_output_schema(),
				'permission_callback' => array( $this, 'can_source_action' ),
				'execute_callback'    => array( $this, 'preview' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/source-file-apply',
			array(
				'label'               => __( 'Apply Installed Extension Source Edit', 'wp-ai-bridge' ),
				'description'         => __( 'Applies one exact preview-bound installed plugin/theme source candidate with verified persistence and bounded recovery.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->candidate_input_schema( true ),
				'output_schema'       => $this->apply_output_schema(),
				'permission_callback' => array( $this, 'can_source_action' ),
				'execute_callback'    => array( $this, 'apply' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);

		$registered[] = wp_register_ability(
			'wp-ai-bridge/source-file-recover',
			array(
				'label'               => __( 'Recover Installed Extension Source Edit', 'wp-ai-bridge' ),
				'description'         => __( 'Recovers the single pending Bridge-owned source preimage only when the current file still has the exact candidate identity.', 'wp-ai-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->recover_input_schema(),
				'output_schema'       => $this->recover_output_schema(),
				'permission_callback' => array( $this, 'can_recover' ),
				'execute_callback'    => array( $this, 'recover' ),
				'meta'                => $this->meta( false, true, true ),
			)
		);

		return array_values( array_filter( $registered, 'is_object' ) );
	}

	/**
	 * Checks source read/preview/apply authorization.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public function can_source_action( $input ) {
		if ( ! $this->source_boundary_enabled() || ! wp_is_file_mod_allowed( 'wp_ai_bridge_source_editing' ) || ! is_array( $input ) || empty( $input['kind'] ) ) {
			return false;
		}
		$kind = (string) $input['kind'];
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) {
			return false;
		}
		return current_user_can( $this->capability_for_kind( $kind ) );
	}

	/**
	 * Checks explicit recovery authorization against the recorded target kind.
	 *
	 * @return bool
	 */
	public function can_recover() {
		if ( ! $this->source_boundary_enabled() || ! wp_is_file_mod_allowed( 'wp_ai_bridge_source_editing' ) ) {
			return false;
		}

		$record = $this->state_get( self::RECOVERY_OPTION, null );
		if ( ! is_array( $record ) || empty( $record['kind'] ) ) {
			return current_user_can( 'edit_plugins' ) || current_user_can( 'edit_themes' );
		}

		return current_user_can( $this->capability_for_kind( (string) $record['kind'] ) );
	}

	/**
	 * Lists or reads exact editable source files.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read( $input ) {
		if ( ! $this->can_source_action( $input ) ) {
			return $this->permission_denied();
		}
		if ( ! is_array( $input ) || empty( $input['kind'] ) ) {
			return $this->invalid_input();
		}

		$action = isset( $input['action'] ) ? (string) $input['action'] : 'list';
		if ( ! in_array( $action, array( 'list', 'read' ), true ) ) {
			return $this->invalid_input();
		}

		if ( 'read' === $action ) {
			$target = $this->resolve_target_from_input( $input );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			$bytes = $this->read_target_bytes( $target );
			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}
			return array(
				'action'      => 'read',
				'items'       => array(),
				'page'        => 1,
				'per_page'    => 1,
				'total'       => 1,
				'total_pages' => 1,
				'target'      => $this->public_target( $target, $bytes ),
				'content'     => $bytes,
			);
		}

		$kind      = (string) $input['kind'];
		$extension = isset( $input['extension'] ) ? (string) $input['extension'] : '';
		$page      = isset( $input['page'] ) ? (int) $input['page'] : 1;
		$per_page  = isset( $input['per_page'] ) ? (int) $input['per_page'] : 25;
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || $page < 1 || $per_page < 1 || $per_page > 100 ) {
			return $this->invalid_input();
		}

		$items = $this->discover_targets( $kind, $extension );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$total       = count( $items );
		$total_pages = $total ? (int) ceil( $total / $per_page ) : 0;
		$offset      = ( $page - 1 ) * $per_page;
		$page_items  = $offset < $total ? array_slice( $items, $offset, $per_page ) : array();

		return array(
			'action'      => 'list',
			'items'       => $page_items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total_pages,
			'target'      => null,
			'content'     => null,
		);
	}

	/**
	 * Validates and binds a candidate without mutation.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function preview( $input ) {
		if ( ! $this->can_source_action( $input ) ) {
			return $this->permission_denied();
		}
		if ( ! is_array( $input ) || ! isset( $input['candidate'] ) || ! is_string( $input['candidate'] ) ) {
			return $this->invalid_input();
		}
		$target = $this->resolve_target_from_input( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$preimage = $this->read_target_bytes( $target );
		if ( is_wp_error( $preimage ) ) {
			return $preimage;
		}
		$candidate = $input['candidate'];
		$syntax    = $this->validate_candidate( $target, $candidate );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}
		$preimage_hash  = hash( 'sha256', $preimage );
		$candidate_hash = hash( 'sha256', $candidate );

		return array(
			'kind'                        => $target['kind'],
			'extension'                   => $target['extension'],
			'file'                        => $target['file'],
			'preimage_sha256'             => $preimage_hash,
			'candidate_sha256'            => $candidate_hash,
			'candidate_id'                => $this->candidate_id( $target, $preimage_hash, $candidate_hash ),
			'candidate_bytes'             => strlen( $candidate ),
			'php_syntax_valid'            => true,
			'runtime_validation_required' => $this->runtime_validation_required( $target ),
			'control_plane_risk'          => $target['control_plane_risk'],
		);
	}

	/**
	 * Applies an exact preview-bound candidate.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function apply( $input ) {
		if ( ! $this->can_source_action( $input ) ) {
			return $this->permission_denied();
		}
		if ( ! is_array( $input ) || ! isset( $input['candidate'], $input['preimage_sha256'], $input['candidate_sha256'], $input['candidate_id'] )
			|| ! is_string( $input['candidate'] ) || ! is_string( $input['preimage_sha256'] ) || ! is_string( $input['candidate_sha256'] ) || ! is_string( $input['candidate_id'] ) ) {
			return $this->invalid_input();
		}

		$target = $this->resolve_target_from_input( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$candidate = $input['candidate'];
		$syntax    = $this->validate_candidate( $target, $candidate );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}
		$candidate_hash = hash( 'sha256', $candidate );
		if ( ! hash_equals( strtolower( $input['candidate_sha256'] ), $candidate_hash ) ) {
			return new WP_Error( 'source_candidate_changed', __( 'The submitted source candidate no longer matches the previewed candidate hash.', 'wp-ai-bridge' ), array( 'outcome' => 'conflict' ) );
		}
		$expected_id = $this->candidate_id( $target, strtolower( $input['preimage_sha256'] ), $candidate_hash );
		if ( ! hash_equals( strtolower( $input['candidate_id'] ), $expected_id ) ) {
			return new WP_Error( 'source_candidate_binding_changed', __( 'The submitted source candidate is not bound to this exact target and preimage.', 'wp-ai-bridge' ), array( 'outcome' => 'conflict' ) );
		}

		$preimage = $this->read_target_bytes( $target );
		if ( is_wp_error( $preimage ) ) {
			return $preimage;
		}
		$preimage_hash = hash( 'sha256', $preimage );
		if ( ! hash_equals( strtolower( $input['preimage_sha256'] ), $preimage_hash ) ) {
			return new WP_Error( 'source_preimage_stale', __( 'The installed source file changed after preview. Read and preview it again before applying.', 'wp-ai-bridge' ), array( 'outcome' => 'conflict' ) );
		}
		if ( ! $target['writable'] ) {
			return new WP_Error( 'source_file_not_directly_writable', __( 'The exact installed source file is not directly writable by the WordPress PHP process. Bridge does not collect FTP or SSH filesystem credentials.', 'wp-ai-bridge' ) );
		}
		if ( false !== $this->state_get( self::RECOVERY_OPTION, false ) ) {
			return new WP_Error( 'source_recovery_required', __( 'A previous source edit still owns pending recovery material. Recover or reconcile it before another source write.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}

		$locked = $this->acquire_file_lock( $target );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		$token = wp_generate_uuid4();

		try {
			$locked_target = $this->resolve_target_from_input( $input );
			if ( is_wp_error( $locked_target ) || $locked_target['canonical_path'] !== $target['canonical_path'] ) {
				return new WP_Error( 'source_target_changed', __( 'The installed source target changed before the write could begin.', 'wp-ai-bridge' ), array( 'outcome' => 'conflict' ) );
			}
			$locked_preimage = $this->read_target_bytes( $locked_target );
			if ( is_wp_error( $locked_preimage ) || ! hash_equals( $preimage_hash, hash( 'sha256', (string) $locked_preimage ) ) ) {
				return new WP_Error( 'source_preimage_stale', __( 'The installed source file changed before the write could begin.', 'wp-ai-bridge' ), array( 'outcome' => 'conflict' ) );
			}

			$record = array(
				'version'          => 1,
				'token'            => $token,
				'kind'             => $target['kind'],
				'extension'        => $target['extension'],
				'file'             => $target['file'],
				'canonical_root'   => $target['canonical_root'],
				'canonical_path'   => $target['canonical_path'],
				'preimage_sha256'  => $preimage_hash,
				'candidate_sha256' => $candidate_hash,
				'preimage'         => $preimage,
				'created_gmt'      => gmdate( 'c' ),
			);
			if ( ! $this->state_add( self::RECOVERY_OPTION, $record ) ) {
				return new WP_Error( 'source_recovery_required', __( 'Recovery material already exists for another source edit.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
			}
			register_shutdown_function( array( $this, 'shutdown_recover' ), $token );

			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			$write = $this->replace_exact_bytes( $target, $preimage_hash, $candidate, $token, 'apply' );
			if ( is_wp_error( $write ) ) {
				return $write;
			}

			$persisted = $this->read_target_bytes( $target );
			if ( is_wp_error( $persisted ) || ! hash_equals( $candidate_hash, hash( 'sha256', (string) $persisted ) ) ) {
				return $this->handle_failed_write( $record );
			}

			if ( $this->runtime_validation_required( $target ) ) {
				$runtime = $this->validate_runtime_boot();
				if ( is_wp_error( $runtime ) ) {
					$restored = $this->restore_record( $record );
					if ( true === $restored ) {
						$this->log->record( 'wp-ai-bridge/source-file-apply', $target['kind'], 0, false, 'runtime_validation_failed' );
						return new WP_Error( 'source_runtime_validation_failed', __( 'WordPress runtime validation failed and the exact previous source bytes were restored.', 'wp-ai-bridge' ), array( 'outcome' => 'validation_failed_restored' ) );
					}
					return $restored;
				}
			}

			if ( ! $this->delete_recovery_if_token( $token ) ) {
				return new WP_Error( 'source_recovery_cleanup_failed', __( 'The source candidate was verified, but Bridge could not clear its private recovery record.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
			}

			$this->log->record( 'wp-ai-bridge/source-file-apply', $target['kind'], 0, true, '' );
			return array(
				'kind'               => $target['kind'],
				'extension'          => $target['extension'],
				'file'               => $target['file'],
				'outcome'            => 'success',
				'persisted_sha256'   => $candidate_hash,
				'control_plane_risk' => $target['control_plane_risk'],
			);
		} finally {
			$this->release_file_lock();
		}
	}

	/**
	 * Explicitly restores a pending exact preimage when candidate identity still matches.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function recover( $input ) {
		if ( ! $this->can_recover() ) {
			return $this->permission_denied();
		}
		$record = $this->state_get( self::RECOVERY_OPTION, null );
		if ( ! is_array( $record ) || empty( $record['token'] ) ) {
			return array(
				'outcome'         => 'success',
				'recovered'       => false,
				'preimage_sha256' => '',
			);
		}
		if ( ! is_array( $input ) || empty( $input['candidate_sha256'] ) || ! is_string( $input['candidate_sha256'] )
			|| ! hash_equals( (string) $record['candidate_sha256'], strtolower( $input['candidate_sha256'] ) ) ) {
			return new WP_Error( 'source_recovery_identity_required', __( 'Recovery requires the exact pending candidate hash.', 'wp-ai-bridge' ), array( 'outcome' => 'conflict' ) );
		}

		$context = $this->trusted_record_path( $record );
		if ( is_wp_error( $context ) ) {
			return new WP_Error( 'source_recovery_target_changed', __( 'The pending source recovery target changed and cannot be restored automatically.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		$coordination = $this->acquire_coordination_lock( $context['canonical_path'] );
		if ( is_wp_error( $coordination ) ) {
			return $coordination;
		}

		try {
			$reconciled = $this->reconcile_replacement_artifacts( $record );
			if ( is_wp_error( $reconciled ) ) {
				return $reconciled;
			}

			$target = $this->resolve_record_target( $record );
			if ( is_wp_error( $target ) ) {
				return new WP_Error( 'source_recovery_target_changed', __( 'The pending source recovery target changed and cannot be restored automatically.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
			}
			$locked = $this->acquire_file_lock( $target );
			if ( is_wp_error( $locked ) ) {
				return $locked;
			}

			$result = $this->restore_record( $record );
			if ( true !== $result ) {
				return $result;
			}
			$this->log->record( 'wp-ai-bridge/source-file-recover', (string) $record['kind'], 0, true, '' );
			return array(
				'outcome'         => 'success',
				'recovered'       => true,
				'preimage_sha256' => (string) $record['preimage_sha256'],
			);
		} finally {
			$this->release_file_lock();
		}
	}

	/**
	 * Shutdown compensation for an apply that did not clear its recovery record.
	 *
	 * @param string $token Recovery token.
	 * @return void
	 */
	public function shutdown_recover( $token ) {
		$record = $this->state_get( self::RECOVERY_OPTION, null );
		if ( ! is_array( $record ) || empty( $record['token'] ) || ! hash_equals( (string) $record['token'], (string) $token ) ) {
			$this->release_file_lock();
			return;
		}

		$context = $this->trusted_record_path( $record );
		if ( is_wp_error( $context ) ) {
			$this->release_file_lock();
			return;
		}
		if ( ! $this->coordination_lock instanceof \SplFileObject ) {
			$coordination = $this->acquire_coordination_lock( $context['canonical_path'] );
			if ( is_wp_error( $coordination ) ) {
				return;
			}
		}

		try {
			$reconciled = $this->reconcile_replacement_artifacts( $record );
			if ( is_wp_error( $reconciled ) ) {
				return;
			}

			$target = $this->resolve_record_target( $record );
			if ( is_wp_error( $target ) ) {
				return;
			}
			if ( ! $this->file_lock instanceof \SplFileObject ) {
				$locked = $this->acquire_file_lock( $target );
				if ( is_wp_error( $locked ) ) {
					return;
				}
			}
			$this->restore_record( $record );
		} finally {
			$this->release_file_lock();
		}
	}

	/** @return bool */
	private function source_boundary_enabled() {
		return $this->permissions->allowed( Settings::GROUP_CODE_EXTENSIONS, 'read' )
			&& $this->permissions->allowed( Settings::GROUP_SOURCE_EDITING, 'read' );
	}

	/** @param string $kind Extension kind. @return string */
	private function capability_for_kind( $kind ) {
		return 'theme' === $kind ? 'edit_themes' : 'edit_plugins';
	}

	/**
	 * Resolves one exact installed source target.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolve_target_from_input( $input ) {
		if ( empty( $input['kind'] ) || empty( $input['extension'] ) || empty( $input['file'] )
			|| ! is_string( $input['kind'] ) || ! is_string( $input['extension'] ) || ! is_string( $input['file'] ) ) {
			return $this->invalid_input();
		}
		$kind      = $input['kind'];
		$extension = $input['extension'];
		$file      = $input['file'];
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || 0 !== validate_file( $file ) || '' === $file ) {
			return new WP_Error( 'source_target_invalid', __( 'The source target must identify one installed plugin/theme and one relative editable file.', 'wp-ai-bridge' ) );
		}
		return 'plugin' === $kind ? $this->resolve_plugin_target( $extension, $file ) : $this->resolve_theme_target( $extension, $file );
	}

	/**
	 * Resolves one plugin source target through Core inventory.
	 *
	 * @param string $plugin Plugin main file.
	 * @param string $file   File relative to plugin root.
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolve_plugin_target( $plugin, $file ) {
		$this->load_editor_files();
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) {
			return new WP_Error( 'source_extension_not_found', __( 'The installed source extension was not found.', 'wp-ai-bridge' ) );
		}
		$plugin_base = realpath( WP_PLUGIN_DIR );
		$root        = realpath( dirname( WP_PLUGIN_DIR . '/' . $plugin ) );
		if ( false === $plugin_base || false === $root ) {
			return new WP_Error( 'source_root_unavailable', __( 'The installed extension source root cannot be resolved.', 'wp-ai-bridge' ) );
		}
		if ( $root !== $plugin_base && ! $this->path_is_within( $root, $plugin_base ) ) {
			return new WP_Error( 'source_path_escape', __( 'The installed source file resolves outside its exact extension root and cannot be edited through Bridge.', 'wp-ai-bridge' ) );
		}
		$plugin_dir = dirname( $plugin );
		$allowed    = array();
		$types      = wp_get_plugin_file_editable_extensions( $plugin );
		foreach ( get_plugin_files( $plugin ) as $plugin_file ) {
			$relative = '.' === $plugin_dir ? basename( $plugin_file ) : substr( $plugin_file, strlen( $plugin_dir ) + 1 );
			$type     = strtolower( pathinfo( $plugin_file, PATHINFO_EXTENSION ) );
			if ( '' !== $relative && in_array( $type, $types, true ) ) {
				$allowed[ wp_normalize_path( $relative ) ] = WP_PLUGIN_DIR . '/' . $plugin_file;
			}
		}
		$file = wp_normalize_path( $file );
		if ( ! isset( $allowed[ $file ] ) ) {
			return new WP_Error( 'source_file_not_editable', __( 'That installed extension file is not in WordPress editable source inventory.', 'wp-ai-bridge' ) );
		}
		return $this->resolved_target(
			'plugin',
			$plugin,
			$file,
			$root,
			$allowed[ $file ],
			is_plugin_active( $plugin ),
			is_multisite() && is_plugin_active_for_network( $plugin )
		);
	}

	/**
	 * Resolves one theme source target through Core inventory.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @param string $file       File relative to theme root.
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolve_theme_target( $stylesheet, $file ) {
		$this->load_editor_files();
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'source_extension_not_found', __( 'The installed source extension was not found.', 'wp-ai-bridge' ) );
		}
		$theme_base = realpath( get_theme_root( $stylesheet ) );
		$root       = realpath( $theme->get_stylesheet_directory() );
		if ( false === $theme_base || false === $root ) {
			return new WP_Error( 'source_root_unavailable', __( 'The installed extension source root cannot be resolved.', 'wp-ai-bridge' ) );
		}
		if ( $root !== $theme_base && ! $this->path_is_within( $root, $theme_base ) ) {
			return new WP_Error( 'source_path_escape', __( 'The installed source file resolves outside its exact extension root and cannot be edited through Bridge.', 'wp-ai-bridge' ) );
		}
		$allowed = array();
		foreach ( wp_get_theme_file_editable_extensions( $theme ) as $type ) {
			$files = $theme->get_files( $type, -1 );
			if ( is_array( $files ) ) {
				$allowed = array_merge( $allowed, $files );
			}
		}
		$file = wp_normalize_path( $file );
		if ( ! isset( $allowed[ $file ] ) ) {
			return new WP_Error( 'source_file_not_editable', __( 'That installed extension file is not in WordPress editable source inventory.', 'wp-ai-bridge' ) );
		}
		$active = get_stylesheet() === $stylesheet || get_template() === $stylesheet;
		return $this->resolved_target( 'theme', $stylesheet, $file, $root, $allowed[ $file ], $active, false );
	}

	/**
	 * Builds a confined target and rejects canonical symlink escape.
	 *
	 * @param string $kind           Extension kind.
	 * @param string $extension      Installed extension identity.
	 * @param string $file           Relative file.
	 * @param string $root           Canonical extension root.
	 * @param string $path           Inventory path.
	 * @param bool   $active         Active in current site/runtime.
	 * @param bool   $network_active Network-active plugin.
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolved_target( $kind, $extension, $file, $root, $path, $active, $network_active ) {
		$canonical = realpath( $path );
		if ( is_link( $path ) || false === $canonical || ! is_file( $canonical ) || ! $this->path_is_within( $canonical, $root ) ) {
			return new WP_Error( 'source_path_escape', __( 'The installed source file resolves outside its exact extension root and cannot be edited through Bridge.', 'wp-ai-bridge' ) );
		}
		$type = strtolower( pathinfo( $canonical, PATHINFO_EXTENSION ) );
		return array(
			'kind'               => $kind,
			'extension'          => $extension,
			'file'               => $file,
			'canonical_root'     => wp_normalize_path( $root ),
			'canonical_path'     => wp_normalize_path( $canonical ),
			'php'                => 'php' === $type,
			'active'             => (bool) $active,
			'network_active'     => (bool) $network_active,
			'writable'           => is_writable( $canonical ),
			'control_plane_risk' => (bool) ( $active || $network_active ),
		);
	}

	/**
	 * Returns source inventory data without source payloads.
	 *
	 * @param string $kind      plugin|theme.
	 * @param string $extension Optional exact extension identity.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function discover_targets( $kind, $extension ) {
		$this->load_editor_files();
		$items = array();
		if ( 'plugin' === $kind ) {
			foreach ( array_keys( get_plugins() ) as $plugin ) {
				if ( '' !== $extension && $extension !== $plugin ) {
					continue;
				}
				$plugin_dir = dirname( $plugin );
				$types      = wp_get_plugin_file_editable_extensions( $plugin );
				foreach ( get_plugin_files( $plugin ) as $plugin_file ) {
					$type = strtolower( pathinfo( $plugin_file, PATHINFO_EXTENSION ) );
					if ( ! in_array( $type, $types, true ) ) {
						continue;
					}
					$relative = '.' === $plugin_dir ? basename( $plugin_file ) : substr( $plugin_file, strlen( $plugin_dir ) + 1 );
					$target   = $this->resolve_plugin_target( $plugin, $relative );
					if ( ! is_wp_error( $target ) ) {
						$items[] = $this->public_target( $target );
					}
				}
			}
		} else {
			foreach ( wp_get_themes() as $stylesheet => $theme ) {
				if ( '' !== $extension && $extension !== $stylesheet ) {
					continue;
				}
				foreach ( wp_get_theme_file_editable_extensions( $theme ) as $type ) {
					$files = $theme->get_files( $type, -1 );
					if ( ! is_array( $files ) ) {
						continue;
					}
					foreach ( array_keys( $files ) as $relative ) {
						$target = $this->resolve_theme_target( $stylesheet, $relative );
						if ( ! is_wp_error( $target ) ) {
							$items[] = $this->public_target( $target );
						}
					}
				}
			}
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( $a['kind'] . "\0" . $a['extension'] . "\0" . $a['file'], $b['kind'] . "\0" . $b['extension'] . "\0" . $b['file'] );
			}
		);
		return $items;
	}

	/**
	 * Reads bounded bytes through the direct WordPress filesystem implementation.
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @return string|WP_Error
	 */
	private function read_target_bytes( $target ) {
		$filesystem = $this->filesystem();
		$size       = $filesystem->size( $target['canonical_path'] );
		if ( false === $size || $size < 0 ) {
			return new WP_Error( 'source_read_failed', __( 'The installed source file could not be read.', 'wp-ai-bridge' ) );
		}
		if ( $size > self::MAX_SOURCE_BYTES ) {
			return new WP_Error( 'source_file_too_large', __( 'The installed source file exceeds the Bridge source-editing safety bound.', 'wp-ai-bridge' ) );
		}
		$bytes = $filesystem->get_contents( $target['canonical_path'] );
		if ( false === $bytes || strlen( $bytes ) !== (int) $size ) {
			return new WP_Error( 'source_read_failed', __( 'The installed source file could not be read completely.', 'wp-ai-bridge' ) );
		}
		return $bytes;
	}

	/**
	 * Performs candidate byte/syntax validation without executing source.
	 *
	 * @param array<string,mixed> $target    Resolved target.
	 * @param string              $candidate Candidate bytes.
	 * @return true|WP_Error
	 */
	private function validate_candidate( $target, $candidate ) {
		if ( strlen( $candidate ) > self::MAX_SOURCE_BYTES ) {
			return new WP_Error( 'source_candidate_too_large', __( 'The source candidate exceeds the Bridge source-editing safety bound.', 'wp-ai-bridge' ) );
		}
		if ( ! $target['php'] ) {
			return true;
		}
		try {
			token_get_all( $candidate, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			return new WP_Error( 'source_php_syntax_invalid', __( 'The PHP source candidate has invalid syntax and was not written.', 'wp-ai-bridge' ) );
		}
		return true;
	}

	/**
	 * Acquires a stable path-keyed Bridge coordination lock and the exact target-inode advisory lock.
	 *
	 * The path-keyed lock remains stable when guarded replacement changes the live inode. The exact-inode
	 * lock still detects ordinary cooperating writers already holding the source file. Both are OS locks,
	 * so process exit releases them automatically. Persistent recovery state owns crash reconciliation.
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @return true|WP_Error
	 */
	private function acquire_file_lock( $target ) {
		if ( $this->file_lock instanceof \SplFileObject ) {
			return new WP_Error( 'source_edit_locked', __( 'Another Bridge source edit is already in progress.', 'wp-ai-bridge' ) );
		}

		$coordination_was_held = $this->coordination_lock instanceof \SplFileObject;
		if ( ! $coordination_was_held ) {
			$coordination = $this->acquire_coordination_lock( $target['canonical_path'] );
			if ( is_wp_error( $coordination ) ) {
				return $coordination;
			}
		}

		try {
			$target_lock = new \SplFileObject( $target['canonical_path'], 'rb' );
		} catch ( \RuntimeException $error ) {
			if ( ! $coordination_was_held ) {
				$this->release_file_lock();
			}
			return new WP_Error( 'source_lock_unavailable', __( 'Bridge could not acquire a cooperative lock for the exact source file.', 'wp-ai-bridge' ) );
		}

		if ( ! $target_lock->flock( LOCK_EX | LOCK_NB ) ) {
			if ( ! $coordination_was_held ) {
				$this->release_file_lock();
			}
			return new WP_Error( 'source_edit_locked', __( 'Another Bridge source edit is already in progress.', 'wp-ai-bridge' ) );
		}
		$this->file_lock = $target_lock;
		return true;
	}

	/**
	 * Acquires the stable path-keyed Bridge coordination lock, even when the live source path is absent.
	 *
	 * @param string $canonical_path Trusted canonical source pathname.
	 * @return true|WP_Error
	 */
	private function acquire_coordination_lock( $canonical_path ) {
		if ( $this->coordination_lock instanceof \SplFileObject ) {
			return true;
		}
		try {
			$coordination = new \SplFileObject( $this->coordination_lock_path( $canonical_path ), 'c+b' );
		} catch ( \RuntimeException $error ) {
			return new WP_Error( 'source_lock_unavailable', __( 'Bridge could not acquire a cooperative lock for the exact source file.', 'wp-ai-bridge' ) );
		}
		if ( ! $coordination->flock( LOCK_EX | LOCK_NB ) ) {
			return new WP_Error( 'source_edit_locked', __( 'Another Bridge source edit is already in progress.', 'wp-ai-bridge' ) );
		}
		$this->coordination_lock = $coordination;
		return true;
	}

	/**
	 * Releases the current process-scoped advisory locks.
	 *
	 * @return void
	 */
	private function release_file_lock() {
		if ( $this->file_lock instanceof \SplFileObject ) {
			$this->file_lock->flock( LOCK_UN );
			$this->file_lock = null;
		}
		if ( $this->coordination_lock instanceof \SplFileObject ) {
			$this->coordination_lock->flock( LOCK_UN );
			$this->coordination_lock = null;
		}
	}

	/** @param string $canonical_path Trusted canonical source pathname. @return string */
	private function coordination_lock_path( $canonical_path ) {
		$key = wp_normalize_path( ABSPATH ) . "\0" . wp_normalize_path( $canonical_path );
		return trailingslashit( get_temp_dir() ) . 'wpai-source-lock-' . hash( 'sha256', $key ) . '.lock';
	}

	/**
	 * Atomically publishes exact bytes without overwriting a path recreated by a non-cooperating writer.
	 *
	 * The target pathname is first moved to a same-directory quarantine name. The bytes actually moved
	 * are then compared with the expected hash. The fully staged replacement is published with link(),
	 * whose existing-destination failure supplies the no-replace boundary that ordinary rename/fopen
	 * cannot provide. A third-party writer that recreates the target path wins and is never overwritten.
	 *
	 * @param array<string,mixed> $target        Resolved target.
	 * @param string              $expected_hash Exact hash that must own the path at replacement time.
	 * @param string              $bytes         Exact replacement bytes.
	 * @param string              $token         Recovery token.
	 * @param string              $phase         apply|recovery|handoff.
	 * @return true|WP_Error
	 */
	private function replace_exact_bytes( $target, $expected_hash, $bytes, $token, $phase ) {
		$path  = $target['canonical_path'];
		$paths = $this->replacement_paths( $path, $token, $phase );
		if ( is_wp_error( $paths ) ) {
			return $paths;
		}
		foreach ( $paths as $artifact ) {
			if ( $this->path_exists( $artifact ) ) {
				return new WP_Error( 'source_recovery_artifact_pending', __( 'A previous source replacement artifact still requires administrator reconciliation.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
			}
		}

		$stage = $this->stage_exact_bytes( $paths['stage'], $bytes );
		if ( is_wp_error( $stage ) ) {
			return $stage;
		}

		// Prove same-directory hard-link/no-replace support before moving the live pathname.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- guarded CAS converts primitive failure to fail-closed state.
		if ( ! @link( $paths['stage'], $paths['probe'] ) ) {
			$cleanup = $this->remove_replacement_artifact( $paths['stage'] );
			return is_wp_error( $cleanup ) ? $cleanup : new WP_Error( 'source_atomic_replace_unavailable', __( 'This filesystem cannot provide the no-overwrite replacement primitive required for safe source editing.', 'wp-ai-bridge' ) );
		}
		$probe_cleanup = $this->remove_replacement_artifact( $paths['probe'] );
		if ( is_wp_error( $probe_cleanup ) ) {
			return $probe_cleanup;
		}

		if ( ! @rename( $path, $paths['hold'] ) ) {
			$cleanup = $this->remove_replacement_artifact( $paths['stage'] );
			return is_wp_error( $cleanup ) ? $cleanup : new WP_Error( 'source_target_changed', __( 'The installed source target changed before the guarded replacement boundary.', 'wp-ai-bridge' ), array( 'outcome' => 'conflict' ) );
		}

		$held = $this->read_regular_path( $paths['hold'] );
		if ( is_wp_error( $held ) ) {
			$restored = $this->restore_quarantined_path( $paths['hold'], $path );
			$cleanup  = $this->remove_replacement_artifact( $paths['stage'] );
			if ( is_wp_error( $cleanup ) ) {
				return $cleanup;
			}
			if ( true === $restored ) {
				return new WP_Error( 'source_recovery_required', __( 'Bridge restored the quarantined source pathname but could not verify its bytes. Recovery ownership was retained.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
			}
			return new WP_Error( 'source_recovery_required', __( 'Bridge quarantined the source path but could not verify or restore it without risking another writer.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		$held_hash = hash( 'sha256', $held );
		if ( ! hash_equals( strtolower( $expected_hash ), $held_hash ) ) {
			$restored = $this->restore_quarantined_path( $paths['hold'], $path );
			$cleanup  = $this->remove_replacement_artifact( $paths['stage'] );
			if ( is_wp_error( $cleanup ) ) {
				return $cleanup;
			}
			if ( true === $restored ) {
				return new WP_Error( 'source_concurrent_write_detected', __( 'The source path changed at the replacement boundary. The newer bytes were preserved and no Bridge replacement was published.', 'wp-ai-bridge' ), array( 'outcome' => 'conflict' ) );
			}
			return new WP_Error( 'source_recovery_required', __( 'The source path changed during guarded replacement and requires administrator reconciliation. Bridge did not overwrite the competing path.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}

		$metadata = $this->match_stage_metadata( $paths['stage'], $paths['hold'] );
		if ( is_wp_error( $metadata ) ) {
			$restored = $this->restore_quarantined_path( $paths['hold'], $path );
			$cleanup  = $this->remove_replacement_artifact( $paths['stage'] );
			if ( is_wp_error( $cleanup ) ) {
				return $cleanup;
			}
			if ( true !== $restored ) {
				return new WP_Error( 'source_recovery_required', __( 'Bridge could not restore the quarantined source after replacement preparation failed. Recovery ownership was retained.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
			}
			return $metadata;
		}

		$this->invoke_replacement_boundary_hook( $phase, $target );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- guarded CAS converts primitive failure to fail-closed state.
		if ( ! @link( $paths['stage'], $path ) ) {
			$competing_path = $this->path_exists( $path );
			if ( $competing_path ) {
				$held_after = $this->read_regular_path( $paths['hold'] );
				$cleanup    = $this->remove_replacement_artifact( $paths['stage'] );
				if ( is_wp_error( $cleanup ) ) {
					return $cleanup;
				}
				if ( is_wp_error( $held_after ) || ! hash_equals( $held_hash, hash( 'sha256', (string) $held_after ) ) ) {
					return new WP_Error( 'source_recovery_required', __( 'Another writer recreated the source path and also changed the quarantined source inode. Bridge preserved both versions for administrator reconciliation.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
				}
				// The competing live pathname already owns the installed generation. The verified hold is the
				// superseded preimage, not a second live target; descriptors retained from before quarantine
				// remain attached to that old inode and cannot mutate the winning installed pathname.
				$hold_cleanup = $this->remove_replacement_artifact( $paths['hold'] );
				if ( is_wp_error( $hold_cleanup ) ) {
					return $hold_cleanup;
				}
				return new WP_Error( 'source_concurrent_write_detected', __( 'Another writer recreated the source path during guarded replacement. Its bytes were preserved and Bridge did not overwrite them.', 'wp-ai-bridge' ), array( 'outcome' => 'conflict' ) );
			}

			$restored = $this->restore_quarantined_path( $paths['hold'], $path );
			$cleanup  = $this->remove_replacement_artifact( $paths['stage'] );
			if ( is_wp_error( $cleanup ) ) {
				return $cleanup;
			}
			if ( true === $restored ) {
				return new WP_Error( 'source_atomic_replace_unavailable', __( 'Bridge could not publish the guarded replacement and restored the exact previous pathname without overwriting another writer.', 'wp-ai-bridge' ), array( 'outcome' => 'validation_failed_restored' ) );
			}
			return new WP_Error( 'source_recovery_required', __( 'Bridge could not publish or safely restore the guarded replacement pathname. Recovery ownership was retained.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}

		$stage_cleanup = $this->remove_replacement_artifact( $paths['stage'] );
		if ( is_wp_error( $stage_cleanup ) ) {
			return $stage_cleanup;
		}
		$published = $this->read_regular_path( $path );
		$wanted    = hash( 'sha256', $bytes );
		if ( is_wp_error( $published ) || ! hash_equals( $wanted, hash( 'sha256', (string) $published ) ) ) {
			return new WP_Error( 'source_write_state_uncertain', __( 'Bridge published the replacement boundary but could not verify the exact current source bytes. Recovery material was retained.', 'wp-ai-bridge' ), array( 'outcome' => 'uncertain_partial_state' ) );
		}

		// Detect a writer that retained the pre-replacement inode and changed it before cleanup.
		$held_after = $this->read_regular_path( $paths['hold'] );
		if ( is_wp_error( $held_after ) ) {
			return new WP_Error( 'source_recovery_required', __( 'Bridge could not verify the quarantined pre-replacement source before cleanup.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		$held_after_hash = hash( 'sha256', $held_after );
		if ( ! hash_equals( $held_hash, $held_after_hash ) ) {
			return new WP_Error( 'source_recovery_required', __( 'A writer changed the previous source inode during replacement. Bridge preserved that quarantined version and requires administrator reconciliation instead of overwriting it.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}

		// No-replace publication has committed a new installed generation and that live pathname was
		// verified above. Retire the unchanged preimage generation; a descriptor opened before quarantine
		// is stale after this boundary and cannot alter the verified installed pathname.
		$hold_cleanup = $this->remove_replacement_artifact( $paths['hold'] );
		if ( is_wp_error( $hold_cleanup ) ) {
			return $hold_cleanup;
		}
		wp_opcache_invalidate( $path, true );
		if ( 'theme' === $target['kind'] ) {
			wp_clean_themes_cache( true );
		}
		return true;
	}

	/**
	 * Creates a fully written private same-directory stage file before publication.
	 *
	 * @param string $path  Stage path.
	 * @param string $bytes Exact bytes.
	 * @return true|WP_Error
	 */
	private function stage_exact_bytes( $path, $bytes ) {
		$handle = @fopen( $path, 'x+b' );
		if ( false === $handle ) {
			return new WP_Error( 'source_stage_failed', __( 'Bridge could not create a private source replacement stage file.', 'wp-ai-bridge' ) );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is checked and the private stage is removed.
		if ( ! @chmod( $path, 0600 ) ) {
			fclose( $handle );
			$cleanup = $this->remove_replacement_artifact( $path );
			return is_wp_error( $cleanup ) ? $cleanup : new WP_Error( 'source_stage_failed', __( 'Bridge could not create a private source replacement stage file.', 'wp-ai-bridge' ) );
		}
		$length = strlen( $bytes );
		$offset = 0;
		while ( $offset < $length ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- guarded CAS converts primitive failure to fail-closed state.
			$written = @fwrite( $handle, substr( $bytes, $offset ) );
			if ( false === $written || 0 === $written ) {
				fclose( $handle );
				$cleanup = $this->remove_replacement_artifact( $path );
				return is_wp_error( $cleanup ) ? $cleanup : new WP_Error( 'source_stage_failed', __( 'Bridge could not write the complete source replacement stage file.', 'wp-ai-bridge' ) );
			}
			$offset += $written;
		}
		if ( ! fflush( $handle ) ) {
			fclose( $handle );
			$cleanup = $this->remove_replacement_artifact( $path );
			return is_wp_error( $cleanup ) ? $cleanup : new WP_Error( 'source_stage_failed', __( 'Bridge could not flush the complete source replacement stage file.', 'wp-ai-bridge' ) );
		}
		if ( function_exists( 'fsync' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- guarded CAS converts primitive failure to fail-closed state.
			@fsync( $handle );
		}
		fclose( $handle );
		return true;
	}

	/**
	 * Copies ownership and mode from the exact quarantined source inode to the staged replacement.
	 *
	 * @param string $stage Stage path.
	 * @param string $hold  Quarantined source path.
	 * @return true|WP_Error
	 */
	private function match_stage_metadata( $stage, $hold ) {
		$source = @stat( $hold );
		if ( false === $source || is_link( $hold ) || ! is_file( $hold ) ) {
			return new WP_Error( 'source_target_changed', __( 'The quarantined source is no longer the expected regular file.', 'wp-ai-bridge' ), array( 'outcome' => 'conflict' ) );
		}
		$mode = $source['mode'] & 0777;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- guarded CAS converts primitive failure to fail-closed state.
		if ( ! @chmod( $stage, $mode ) ) {
			return new WP_Error( 'source_atomic_replace_unavailable', __( 'Bridge could not preserve the source file mode for atomic replacement.', 'wp-ai-bridge' ) );
		}
		$stage_owner = @fileowner( $stage );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- guarded CAS converts primitive failure to fail-closed state.
		if ( false === $stage_owner || ( (int) $stage_owner !== (int) $source['uid'] && ! @chown( $stage, (int) $source['uid'] ) ) ) {
			return new WP_Error( 'source_atomic_replace_unavailable', __( 'Bridge could not preserve source file ownership for atomic replacement.', 'wp-ai-bridge' ) );
		}
		$stage_group = @filegroup( $stage );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- guarded CAS converts primitive failure to fail-closed state.
		if ( false === $stage_group || ( (int) $stage_group !== (int) $source['gid'] && ! @chgrp( $stage, (int) $source['gid'] ) ) ) {
			return new WP_Error( 'source_atomic_replace_unavailable', __( 'Bridge could not preserve source file group ownership for atomic replacement.', 'wp-ai-bridge' ) );
		}
		clearstatcache( true, $stage );
		return true;
	}

	/**
	 * Restores a quarantined regular file only when the target pathname is still absent.
	 *
	 * @param string $hold Quarantined file.
	 * @param string $path Live target path.
	 * @return true|WP_Error
	 */
	private function restore_quarantined_path( $hold, $path ) {
		if ( is_link( $hold ) || ! is_file( $hold ) ) {
			return new WP_Error( 'source_recovery_required' );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- guarded CAS converts primitive failure to fail-closed state.
		if ( ! @link( $hold, $path ) ) {
			return new WP_Error( 'source_recovery_required' );
		}
		$cleanup = $this->remove_replacement_artifact( $hold );
		return is_wp_error( $cleanup ) ? $cleanup : true;
	}

	/**
	 * Reads only a bounded regular non-symlink path.
	 *
	 * @param string $path Exact internal path.
	 * @return string|WP_Error
	 */
	private function read_regular_path( $path ) {
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return new WP_Error( 'source_read_failed' );
		}
		$filesystem = $this->filesystem();
		$size       = $filesystem->size( $path );
		if ( false === $size || $size < 0 || $size > self::MAX_SOURCE_BYTES ) {
			return new WP_Error( 'source_read_failed' );
		}
		$bytes = $filesystem->get_contents( $path );
		return false !== $bytes && strlen( $bytes ) === (int) $size ? $bytes : new WP_Error( 'source_read_failed' );
	}

	/** @param string $path Path. @return bool */
	private function path_exists( $path ) {
		return file_exists( $path ) || is_link( $path );
	}

	/**
	 * Retires one Bridge-owned private replacement artifact and verifies pathname disappearance.
	 *
	 * @param string $path Exact private artifact pathname.
	 * @return true|WP_Error
	 */
	private function remove_replacement_artifact( $path ) {
		if ( ! $this->path_exists( $path ) ) {
			return true;
		}
		if ( is_callable( $this->replacement_artifact_cleanup_hook )
			&& false === call_user_func( $this->replacement_artifact_cleanup_hook, $path ) ) {
			return new WP_Error( 'source_recovery_artifact_pending', __( 'A source replacement artifact could not be verified safely and requires administrator reconciliation.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- removal is verified immediately below and failure keeps recovery ownership.
		if ( ! @unlink( $path ) ) {
			return new WP_Error( 'source_recovery_artifact_pending', __( 'A source replacement artifact could not be verified safely and requires administrator reconciliation.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		clearstatcache( true, $path );
		if ( $this->path_exists( $path ) ) {
			return new WP_Error( 'source_recovery_artifact_pending', __( 'A source replacement artifact could not be verified safely and requires administrator reconciliation.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		return true;
	}

	/**
	 * Returns recovery-token-keyed same-directory artifact names on the exact target filesystem.
	 *
	 * @param string $path  Trusted canonical target path.
	 * @param string $token Recovery token.
	 * @param string $phase Replacement phase.
	 * @return array<string,string>|WP_Error
	 */
	private function replacement_paths( $path, $token, $phase ) {
		$directory = realpath( dirname( $path ) );
		if ( false === $directory || wp_normalize_path( $directory ) !== wp_normalize_path( dirname( $path ) ) ) {
			return new WP_Error( 'source_atomic_replace_unavailable', __( 'Bridge could not resolve the exact source directory for guarded replacement.', 'wp-ai-bridge' ) );
		}
		$safe_token = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $token );
		$safe_phase = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $phase );
		if ( '' === $safe_token || '' === $safe_phase ) {
			return new WP_Error( 'source_atomic_replace_unavailable', __( 'Bridge could not derive a private replacement identity.', 'wp-ai-bridge' ) );
		}
		$key    = hash( 'sha256', $safe_token . "\\0" . $safe_phase . "\\0" . wp_normalize_path( $path ) );
		$prefix = trailingslashit( wp_normalize_path( $directory ) ) . '.ht-wpai-source-cas-' . substr( $key, 0, 40 );
		return array(
			'stage' => $prefix . '.stage',
			'probe' => $prefix . '.probe',
			'hold'  => $prefix . '.hold',
		);
	}

	/** @param string $phase Phase. @param array<string,mixed> $target Target. @return void */
	private function invoke_replacement_boundary_hook( $phase, $target ) {
		if ( is_callable( $this->replacement_boundary_hook ) ) {
			call_user_func( $this->replacement_boundary_hook, $phase, $target );
		}
	}

	/**
	 * Performs native edited-file scrape validation without admin session fabrication.
	 *
	 * @return true|WP_Error
	 */
	private function validate_runtime_boot() {
		$scrape_key   = substr( md5( wp_generate_uuid4() ), 0, 32 );
		$scrape_nonce = wp_generate_password( 32, false, false );
		$transient    = 'scrape_key_' . $scrape_key;
		set_transient( $transient, $scrape_nonce, 60 );
		$params  = array(
			'wp_scrape_key'   => $scrape_key,
			'wp_scrape_nonce' => $scrape_nonce,
		);
		$headers = array( 'Cache-Control' => 'no-cache' );
		$urls    = array( add_query_arg( $params, admin_url( 'admin-ajax.php' ) ), add_query_arg( $params, home_url( '/' ) ) );
		try {
			foreach ( $urls as $url ) {
				$sslverify = apply_filters( 'https_local_ssl_verify', false, $url );
				$response  = wp_remote_get(
					$url,
					array(
						'headers'   => $headers,
						'timeout'   => 30,
						'sslverify' => $sslverify,
					)
				);
				if ( is_wp_error( $response ) || true !== $this->scrape_result( wp_remote_retrieve_body( $response ), $scrape_key ) ) {
					return new WP_Error( 'source_runtime_boot_failed' );
				}
			}
		} finally {
			delete_transient( $transient );
		}
		return true;
	}

	/**
	 * Parses only the Core scrape sentinel payload.
	 *
	 * @param string $body       Loopback response body.
	 * @param string $scrape_key Scrape key.
	 * @return bool
	 */
	private function scrape_result( $body, $scrape_key ) {
		$start = "###### wp_scraping_result_start:$scrape_key ######";
		$end   = "###### wp_scraping_result_end:$scrape_key ######";
		$pos   = strpos( $body, $start );
		if ( false === $pos ) {
			return false;
		}
		$payload = substr( $body, $pos + strlen( $start ) );
		$end_pos = strpos( $payload, $end );
		if ( false === $end_pos ) {
			return false;
		}
		return true === json_decode( trim( substr( $payload, 0, $end_pos ) ), true );
	}

	/** @param array<string,mixed> $target Target. @return bool */
	private function runtime_validation_required( $target ) {
		return ! empty( $target['php'] ) && ( ! empty( $target['active'] ) || ! empty( $target['network_active'] ) );
	}

	/**
	 * Attempts safe compensation after a write/persistence failure.
	 *
	 * @param array<string,mixed> $record Recovery record.
	 * @return WP_Error
	 */
	private function handle_failed_write( $record ) {
		$target = $this->resolve_record_target( $record );
		if ( is_wp_error( $target ) ) {
			return new WP_Error( 'source_write_state_uncertain', __( 'The source write outcome is uncertain and its recovery target can no longer be resolved safely.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		$current = $this->read_target_bytes( $target );
		if ( is_wp_error( $current ) ) {
			return new WP_Error( 'source_write_state_uncertain', __( 'The source write outcome is uncertain and Bridge could not verify current persisted bytes.', 'wp-ai-bridge' ), array( 'outcome' => 'uncertain_partial_state' ) );
		}
		$current_hash = hash( 'sha256', $current );
		if ( hash_equals( (string) $record['preimage_sha256'], $current_hash ) ) {
			$this->delete_recovery_if_token( (string) $record['token'] );
			return new WP_Error( 'source_write_failed_restored', __( 'The source write failed without changing the verified previous bytes.', 'wp-ai-bridge' ), array( 'outcome' => 'validation_failed_restored' ) );
		}
		if ( hash_equals( (string) $record['candidate_sha256'], $current_hash ) ) {
			$restored = $this->restore_record( $record );
			if ( true === $restored ) {
				return new WP_Error( 'source_write_failed_restored', __( 'The source write failed and the exact previous source bytes were restored.', 'wp-ai-bridge' ), array( 'outcome' => 'validation_failed_restored' ) );
			}
			return $restored;
		}
		return new WP_Error( 'source_write_state_uncertain', __( 'The source write produced bytes that match neither the exact preimage nor the candidate. Recovery material was retained.', 'wp-ai-bridge' ), array( 'outcome' => 'uncertain_partial_state' ) );
	}

	/**
	 * Restores exact preimage only when current bytes are still the owned candidate.
	 *
	 * @param array<string,mixed> $record Recovery record.
	 * @return true|WP_Error
	 */
	private function restore_record( $record ) {
		$target = $this->resolve_record_target( $record );
		if ( is_wp_error( $target ) ) {
			return new WP_Error( 'source_recovery_target_changed', __( 'The pending source recovery target changed and cannot be restored automatically.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		$current = $this->read_target_bytes( $target );
		if ( is_wp_error( $current ) ) {
			return new WP_Error( 'source_recovery_read_failed', __( 'Bridge could not read the pending recovery target safely.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		$current_hash = hash( 'sha256', $current );
		if ( hash_equals( (string) $record['preimage_sha256'], $current_hash ) ) {
			$artifacts = $this->cleanup_known_replacement_holds( $record );
			if ( is_wp_error( $artifacts ) ) {
				return $artifacts;
			}
			return $this->delete_recovery_if_token( (string) $record['token'] ) ? true : new WP_Error( 'source_recovery_cleanup_failed', __( 'The previous source bytes are already restored, but Bridge could not clear its recovery record.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		if ( ! hash_equals( (string) $record['candidate_sha256'], $current_hash ) ) {
			return new WP_Error( 'source_recovery_conflict', __( 'The source file changed after the Bridge candidate. Recovery will not overwrite newer bytes.', 'wp-ai-bridge' ), array( 'outcome' => 'conflict' ) );
		}
		$write = $this->replace_exact_bytes( $target, (string) $record['candidate_sha256'], (string) $record['preimage'], (string) $record['token'], 'recovery' );
		if ( is_wp_error( $write ) ) {
			return $write;
		}
		$verified = $this->read_target_bytes( $target );
		if ( is_wp_error( $verified ) || ! hash_equals( (string) $record['preimage_sha256'], hash( 'sha256', (string) $verified ) ) ) {
			return new WP_Error( 'source_recovery_verification_failed', __( 'Bridge attempted recovery but could not verify the exact previous source bytes. Recovery material was retained.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		$artifacts = $this->cleanup_known_replacement_holds( $record );
		if ( is_wp_error( $artifacts ) ) {
			return $artifacts;
		}
		if ( ! $this->delete_recovery_if_token( (string) $record['token'] ) ) {
			return new WP_Error( 'source_recovery_cleanup_failed', __( 'The exact previous source bytes were restored, but Bridge could not clear its recovery record.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		return true;
	}

	/**
	 * Deletes only known-hash quarantine files after the live pathname is verified restored.
	 *
	 * @param array<string,mixed> $record Recovery record.
	 * @return true|WP_Error
	 */
	private function cleanup_known_replacement_holds( $record ) {
		$context = $this->trusted_record_path( $record );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$known = array( (string) $record['preimage_sha256'], (string) $record['candidate_sha256'] );
		foreach ( array( 'apply', 'recovery' ) as $phase ) {
			$paths = $this->replacement_paths( $context['canonical_path'], (string) $record['token'], $phase );
			if ( is_wp_error( $paths ) ) {
				return $paths;
			}
			if ( ! $this->path_exists( $paths['hold'] ) ) {
				continue;
			}
			$held = $this->read_regular_path( $paths['hold'] );
			if ( is_wp_error( $held ) || ! in_array( hash( 'sha256', (string) $held ), $known, true ) ) {
				return new WP_Error( 'source_recovery_artifact_pending', __( 'A source replacement artifact could not be verified safely and requires administrator reconciliation.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
			}
			// The live pathname has already been verified at a known terminal generation. This hold is a
			// known superseded generation, so retiring it cannot overwrite the installed source target.
			$cleanup = $this->remove_replacement_artifact( $paths['hold'] );
			if ( is_wp_error( $cleanup ) ) {
				return $cleanup;
			}
		}
		return true;
	}

	/**
	 * Reconciles private same-directory replacement artifacts left by abrupt process termination.
	 *
	 * Artifact reconciliation never treats pathname absence as proof that Bridge still owns the absence.
	 * A crash before publication and a legitimate delete/rename after publication are indistinguishable
	 * without durable phase evidence, so an absent live pathname plus a hold fails closed. Unknown or
	 * unremovable artifacts are retained for administrator reconciliation rather than changed speculatively.
	 *
	 * @param array<string,mixed> $record Recovery record.
	 * @return true|WP_Error
	 */
	private function reconcile_replacement_artifacts( $record ) {
		$context = $this->trusted_record_path( $record );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$path  = $context['canonical_path'];
		$known = array( (string) $record['preimage_sha256'], (string) $record['candidate_sha256'] );

		foreach ( array( 'apply', 'recovery' ) as $phase ) {
			$paths = $this->replacement_paths( $path, (string) $record['token'], $phase );
			if ( is_wp_error( $paths ) ) {
				return $paths;
			}
			if ( $this->path_exists( $paths['hold'] ) ) {
				$held = $this->read_regular_path( $paths['hold'] );
				if ( is_wp_error( $held ) ) {
					return new WP_Error( 'source_recovery_artifact_pending', __( 'A source replacement artifact could not be verified safely and requires administrator reconciliation.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
				}
				$held_hash = hash( 'sha256', $held );
				if ( ! in_array( $held_hash, $known, true ) ) {
					return new WP_Error( 'source_recovery_artifact_pending', __( 'A concurrent writer changed a quarantined source inode. Bridge preserved it for administrator reconciliation.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
				}
				if ( ! $this->path_exists( $path ) ) {
					// Absence is ambiguous: publication may not have happened, or a later writer may have
					// legitimately deleted/renamed the published pathname. Never recreate it speculatively.
					return new WP_Error( 'source_recovery_artifact_pending', __( 'A source replacement artifact could not be verified safely and requires administrator reconciliation.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
				}
			}

			foreach ( array( 'stage', 'probe' ) as $disposable ) {
				$artifact = $paths[ $disposable ];
				if ( ! $this->path_exists( $artifact ) ) {
					continue;
				}
				$artifact_bytes = $this->read_regular_path( $artifact );
				if ( is_wp_error( $artifact_bytes ) || ! in_array( hash( 'sha256', (string) $artifact_bytes ), $known, true ) ) {
					return new WP_Error( 'source_recovery_artifact_pending', __( 'A private source replacement artifact has unexpected bytes and requires administrator reconciliation.', 'wp-ai-bridge' ), array( 'outcome' => 'recovery_required' ) );
				}
				$cleanup = $this->remove_replacement_artifact( $artifact );
				if ( is_wp_error( $cleanup ) ) {
					return $cleanup;
				}
			}
		}
		return true;
	}

	/**
	 * Validates the private canonical path stored only after normal target confinement succeeded.
	 *
	 * @param array<string,mixed> $record Recovery record.
	 * @return array<string,string>|WP_Error
	 */
	private function trusted_record_path( $record ) {
		if ( ! isset( $record['kind'], $record['extension'], $record['file'], $record['canonical_root'], $record['canonical_path'] )
			|| ! is_string( $record['kind'] ) || ! is_string( $record['extension'] ) || ! is_string( $record['file'] ) || ! is_string( $record['canonical_root'] ) || ! is_string( $record['canonical_path'] )
			|| ! in_array( $record['kind'], array( 'plugin', 'theme' ), true ) || '' === $record['extension'] || 0 !== validate_file( $record['extension'] ) || 0 !== validate_file( $record['file'] ) ) {
			return new WP_Error( 'source_recovery_record_invalid' );
		}

		$root      = wp_normalize_path( $record['canonical_root'] );
		$path      = wp_normalize_path( $record['canonical_path'] );
		$root_real = realpath( $root );
		if ( false === $root_real || wp_normalize_path( $root_real ) !== $root ) {
			return new WP_Error( 'source_recovery_target_changed' );
		}

		// Bind the private canonical path back to the exact relative file originally resolved.
		// This remains checkable while the live pathname is temporarily absent in quarantine.
		$expected_path = wp_normalize_path( trailingslashit( $root ) . wp_normalize_path( $record['file'] ) );
		if ( $expected_path !== $path || ! $this->path_is_within( $path, $root ) ) {
			return new WP_Error( 'source_recovery_target_changed' );
		}

		if ( 'plugin' === $record['kind'] ) {
			$base_real = realpath( WP_PLUGIN_DIR );
			if ( false === $base_real ) {
				return new WP_Error( 'source_recovery_target_changed' );
			}
			$base          = wp_normalize_path( $base_real );
			$expected_root = realpath( dirname( WP_PLUGIN_DIR . '/' . $record['extension'] ) );
			if ( false === $expected_root || wp_normalize_path( $expected_root ) !== $root || ( $root !== $base && ! $this->path_is_within( $root, $base ) ) ) {
				return new WP_Error( 'source_recovery_target_changed' );
			}
		} elseif ( ! $this->trusted_registered_theme_root( $record['extension'], $root ) ) {
			return new WP_Error( 'source_recovery_target_changed' );
		}

		return array(
			'canonical_root' => $root,
			'canonical_path' => $path,
		);
	}

	/**
	 * Confirms an exact recorded theme directory against current WordPress theme-root registration.
	 *
	 * The exact theme-aware root is preferred. Registered theme directories are also checked directly so
	 * crash recovery still works when the edited live file (including style.css) is temporarily quarantined
	 * and therefore cannot participate in a fresh theme-directory scan.
	 *
	 * @param string $stylesheet Exact recorded stylesheet identity.
	 * @param string $root       Exact canonical recorded theme directory.
	 * @return bool
	 */
	private function trusted_registered_theme_root( $stylesheet, $root ) {
		$theme_base = realpath( get_theme_root( $stylesheet ) );
		if ( false !== $theme_base ) {
			$expected = realpath( trailingslashit( $theme_base ) . $stylesheet );
			if ( false !== $expected && wp_normalize_path( $expected ) === $root ) {
				return true;
			}
		}

		global $wp_theme_directories;
		foreach ( (array) $wp_theme_directories as $registered_directory ) {
			$registered = realpath( $registered_directory );
			if ( false === $registered ) {
				continue;
			}
			$expected = realpath( trailingslashit( $registered ) . $stylesheet );
			if ( false !== $expected && wp_normalize_path( $expected ) === $root ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $record Recovery record. @return array<string,mixed>|WP_Error */
	private function resolve_record_target( $record ) {
		if ( ! isset( $record['kind'], $record['extension'], $record['file'] ) ) {
			return new WP_Error( 'source_recovery_record_invalid' );
		}
		return $this->resolve_target_from_input(
			array(
				'kind'      => (string) $record['kind'],
				'extension' => (string) $record['extension'],
				'file'      => (string) $record['file'],
			)
		);
	}

	/** @param string $token Token. @return bool */
	private function delete_recovery_if_token( $token ) {
		$current = $this->state_get( self::RECOVERY_OPTION, null );
		if ( ! is_array( $current ) || empty( $current['token'] ) || ! hash_equals( (string) $current['token'], $token ) ) {
			return false;
		}
		return $this->state_delete( self::RECOVERY_OPTION );
	}

	/**
	 * Reads private source-editing state from a scope shared by all sites in a network.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $fallback Default value.
	 * @return mixed
	 */
	private function state_get( $name, $fallback = false ) {
		return is_multisite() ? get_site_option( $name, $fallback ) : get_option( $name, $fallback );
	}

	/**
	 * Atomically claims private source-editing state.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Option value.
	 * @return bool
	 */
	private function state_add( $name, $value ) {
		return is_multisite() ? add_site_option( $name, $value ) : add_option( $name, $value, '', false );
	}

	/**
	 * Deletes private source-editing state from the matching install scope.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private function state_delete( $name ) {
		return is_multisite() ? delete_site_option( $name ) : delete_option( $name );
	}

	/**
	 * Gets a direct WordPress filesystem implementation without collecting credentials.
	 *
	 * @return \WP_Filesystem_Direct
	 */
	private function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		return new \WP_Filesystem_Direct( null );
	}

	/** @return void */
	private function load_editor_files() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	/**
	 * Tests canonical containment.
	 *
	 * @param string $path Canonical file path.
	 * @param string $root Canonical root path.
	 * @return bool
	 */
	private function path_is_within( $path, $root ) {
		$path = wp_normalize_path( $path );
		$root = untrailingslashit( wp_normalize_path( $root ) );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path = strtolower( $path );
			$root = strtolower( $root );
		}
		return 0 === strpos( $path, $root . '/' );
	}

	/**
	 * Creates privacy-safe public target metadata.
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @param string|null         $bytes  Optional already-read bytes.
	 * @return array<string,mixed>
	 */
	private function public_target( $target, $bytes = null ) {
		$size = null === $bytes ? $this->filesystem()->size( $target['canonical_path'] ) : strlen( $bytes );
		return array(
			'kind'                        => $target['kind'],
			'extension'                   => $target['extension'],
			'file'                        => $target['file'],
			'bytes'                       => false === $size ? 0 : (int) $size,
			'php'                         => (bool) $target['php'],
			'active'                      => (bool) $target['active'],
			'network_active'              => (bool) $target['network_active'],
			'writable'                    => (bool) $target['writable'],
			'runtime_validation_required' => $this->runtime_validation_required( $target ),
			'control_plane_risk'          => (bool) $target['control_plane_risk'],
		);
	}

	/** @param array<string,mixed> $target Target. @param string $preimage Preimage hash. @param string $candidate Candidate hash. @return string */
	private function candidate_id( $target, $preimage, $candidate ) {
		return hash( 'sha256', $target['kind'] . "\0" . $target['extension'] . "\0" . $target['file'] . "\0" . $preimage . "\0" . $candidate );
	}

	/** @return WP_Error */
	private function permission_denied() {
		return new WP_Error( 'source_editing_permission_denied', __( 'Source Editing, Code & Extensions, WordPress file-modification policy, and the matching native file-editor capability must all allow this operation.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function invalid_input() {
		return new WP_Error( 'invalid_source_editing_input', __( 'Use one exact installed plugin/theme identity and relative editable source file with the fields required by this source-editing action.', 'wp-ai-bridge' ) );
	}

	/** @param bool $is_readonly Read-only annotation. @param bool $destructive Destructive annotation. @param bool $idempotent Idempotent annotation. @return array<string,mixed> */
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

	/** @return array<string,mixed> */
	private function target_properties() {
		return array(
			'kind'      => array(
				'type' => 'string',
				'enum' => array( 'plugin', 'theme' ),
			),
			'extension' => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 300,
			),
			'file'      => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 500,
			),
		);
	}

	/** @return array<string,mixed> */
	private function read_input_schema() {
		$properties             = $this->target_properties();
		$properties['action']   = array(
			'type'    => 'string',
			'enum'    => array( 'list', 'read' ),
			'default' => 'list',
		);
		$properties['page']     = array(
			'type'    => 'integer',
			'minimum' => 1,
			'default' => 1,
		);
		$properties['per_page'] = array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => 100,
			'default' => 25,
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'kind' ),
			'additionalProperties' => false,
		);
	}

	/** @param bool $apply Include apply binding fields. @return array<string,mixed> */
	private function candidate_input_schema( $apply ) {
		$properties              = $this->target_properties();
		$properties['candidate'] = array(
			'type'      => 'string',
			'maxLength' => self::MAX_SOURCE_BYTES,
		);
		$required                = array( 'kind', 'extension', 'file', 'candidate' );
		if ( $apply ) {
			$properties['preimage_sha256']  = array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			);
			$properties['candidate_sha256'] = array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			);
			$properties['candidate_id']     = array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			);
			$required                       = array_merge( $required, array( 'preimage_sha256', 'candidate_sha256', 'candidate_id' ) );
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function recover_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'candidate_sha256' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
			),
			'required'             => array( 'candidate_sha256' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function target_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'                        => array( 'type' => 'string' ),
				'extension'                   => array( 'type' => 'string' ),
				'file'                        => array( 'type' => 'string' ),
				'bytes'                       => array( 'type' => 'integer' ),
				'php'                         => array( 'type' => 'boolean' ),
				'active'                      => array( 'type' => 'boolean' ),
				'network_active'              => array( 'type' => 'boolean' ),
				'writable'                    => array( 'type' => 'boolean' ),
				'runtime_validation_required' => array( 'type' => 'boolean' ),
				'control_plane_risk'          => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'kind', 'extension', 'file', 'bytes', 'php', 'active', 'network_active', 'writable', 'runtime_validation_required', 'control_plane_risk' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function read_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'      => array( 'type' => 'string' ),
				'items'       => array(
					'type'  => 'array',
					'items' => $this->target_output_schema(),
				),
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
				'total'       => array( 'type' => 'integer' ),
				'total_pages' => array( 'type' => 'integer' ),
				'target'      => array( 'type' => array( 'object', 'null' ) ),
				'content'     => array( 'type' => array( 'string', 'null' ) ),
			),
			'required'             => array( 'action', 'items', 'page', 'per_page', 'total', 'total_pages', 'target', 'content' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function preview_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'                        => array( 'type' => 'string' ),
				'extension'                   => array( 'type' => 'string' ),
				'file'                        => array( 'type' => 'string' ),
				'preimage_sha256'             => array( 'type' => 'string' ),
				'candidate_sha256'            => array( 'type' => 'string' ),
				'candidate_id'                => array( 'type' => 'string' ),
				'candidate_bytes'             => array( 'type' => 'integer' ),
				'php_syntax_valid'            => array( 'type' => 'boolean' ),
				'runtime_validation_required' => array( 'type' => 'boolean' ),
				'control_plane_risk'          => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'kind', 'extension', 'file', 'preimage_sha256', 'candidate_sha256', 'candidate_id', 'candidate_bytes', 'php_syntax_valid', 'runtime_validation_required', 'control_plane_risk' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function apply_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'               => array( 'type' => 'string' ),
				'extension'          => array( 'type' => 'string' ),
				'file'               => array( 'type' => 'string' ),
				'outcome'            => array( 'type' => 'string' ),
				'persisted_sha256'   => array( 'type' => 'string' ),
				'control_plane_risk' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'kind', 'extension', 'file', 'outcome', 'persisted_sha256', 'control_plane_risk' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function recover_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'outcome'         => array( 'type' => 'string' ),
				'recovered'       => array( 'type' => 'boolean' ),
				'preimage_sha256' => array( 'type' => 'string' ),
			),
			'required'             => array( 'outcome', 'recovered', 'preimage_sha256' ),
			'additionalProperties' => false,
		);
	}
}
