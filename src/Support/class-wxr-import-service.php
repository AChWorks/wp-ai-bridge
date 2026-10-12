<?php
/**
 * Read-only executable WXR planning + one-way native importer coordination.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/**
 * Owns provider validation, identity-bound preview consent and effect fencing.
 * Delegates WXR semantics and all post/term/comment/meta mutations to the
 * installed official WordPress Importer, never a second homegrown parser.
 */
final class WXR_Import_Service {
	/** @var Permissions */
	private $permissions;

	/** @var Private_Transfer_Session */
	private $store;

	/** @param Permissions $permissions Current delegated WordPress principal. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
		$this->store       = new Private_Transfer_Session( $permissions );
	}

	/** @return bool Current native and Bridge mutation authority. */
	public function can_execute() {
		return get_current_user_id() > 0 &&
			'' !== OAuth_Server::authenticated_mcp_client_id() &&
			$this->permissions->allowed_all(
				array(
					array( Settings::GROUP_SITE_CONFIG, 'import' ),
					array( Settings::GROUP_BUILDER_WRITE, 'edit_posts' ),
				)
			) &&
			current_user_can( 'manage_options' ) &&
			current_user_can( 'publish_posts' ) &&
			( ! is_multisite() || current_user_can( 'manage_network_options' ) );
	}

	/**
	 * No effect. Re-parses actual incoming bytes with the verified official
	 * native parser and computes a target/user/client/source/choice-bound plan.
	 *
	 * @param mixed $input Exact user choices; never a file path.
	 * @return array<string,mixed>|WP_Error
	 */
	public function plan( $input ) {
		if ( ! $this->can_execute() ) {
			return $this->denied();
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'transfer_id', 'author_mapping', 'on_conflict' ) ) ||
			! is_string( $input['transfer_id'] ?? null ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['transfer_id'] ) ) {
			return $this->invalid();
		}
		$mapping = $input['author_mapping'] ?? array();
		$mode    = $input['on_conflict'] ?? 'reject';
		if ( ! is_array( $mapping ) || ! in_array( $mode, array( 'reject', 'allow_existing' ), true ) ) {
			return $this->invalid();
		}
		if ( 'allow_existing' === $mode && ! $this->permissions->allowed( Settings::GROUP_LIVE_CONTENT, 'edit_others_posts' ) ) {
			return $this->denied();
		}
		if ( ( 'allow_existing' === $mode || $mapping ) &&
			( ! current_user_can( 'edit_others_posts' ) || ! current_user_can( 'list_users' ) ) ) {
			return $this->denied();
		}
		$provider = $this->provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$policy = ( new Transfer_Settings() )->bytes();
		$size   = $policy['file'];
		$nodes  = $size > intdiv( PHP_INT_MAX, 4 ) ? PHP_INT_MAX : $size * 4;
		$source = $this->store->verified_private_source( $input['transfer_id'], 'wxr' );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$streamed = ( new WXR_Import_Preflight( $this->permissions ) )->inspect_transfer(
			$input['transfer_id'],
			$size,
			$nodes,
			$size
		);
		if ( is_wp_error( $streamed ) ) {
			return $streamed;
		}
		$memory = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) : -1;
		$budget = $memory > 0 ? min( $size, max( 1, intdiv( $memory, 16 ) ) ) : $size;
		if ( $source['bytes'] > $budget ) {
			return new WP_Error( 'wxr_import_memory_budget', __( 'WXR is too large for the current PHP full-parser memory envelope; use a larger authorized WordPress worker.', 'wp-ai-bridge' ) );
		}

		$previous_libxml = libxml_use_internal_errors();
		$before_level    = ob_get_level();
		$native          = null;
		$failed          = false;
		try {
			if ( ! ob_start(
				static function () {
					return ''; }
			) ) {
				$failed = true;
			} else {
				$importer = new \WP_Import();
				$native   = $importer->parse( $source['path'] );
			}
		} catch ( \Throwable $error ) {
			$failed = true;
		} finally {
			while ( ob_get_level() > $before_level ) {
				if ( ! ob_end_clean() ) {
					$failed = true;
					break;
				}
			}
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_libxml );
		}
		if ( $failed || is_wp_error( $native ) || ! is_array( $native ) ||
			'1.2' !== (string) ( $native['version'] ?? '' ) ||
			! is_array( $native['posts'] ?? null ) ||
			count( $native['posts'] ) !== $streamed['item_count'] ) {
			return new WP_Error( 'wxr_import_provider_parse', __( 'Official WordPress Importer could not safely parse the exact private WXR.', 'wp-ai-bridge' ) );
		}

		if ( ! $this->safe_serialized_meta( $native ) ) {
			return new WP_Error( 'wxr_import_unsafe_meta', __( 'WXR contains serialized PHP objects or references that are not safe to import.', 'wp-ai-bridge' ) );
		}
		$types            = array();
		$authors          = array();
		$conflicts        = 0;
		$attachments      = 0;
		$publishing       = 0;
		$conflict_targets = array();
		$seen_source_ids  = array();
		foreach ( $native['posts'] as $post ) {
			if ( ! is_array( $post ) || ! is_string( $post['post_type'] ?? null ) ||
				! is_string( $post['post_title'] ?? null ) ||
				! is_string( $post['post_date'] ?? null ) ||
				! is_string( $post['post_author'] ?? null ) ) {
				return $this->invalid();
			}
			$source_id = (int) ( $post['post_id'] ?? 0 );
			if ( $source_id < 1 || isset( $seen_source_ids[ $source_id ] ) ) {
				return $this->invalid();
			}
			$seen_source_ids[ $source_id ] = true;
			$type                          = $post['post_type'];
			$post_type                     = get_post_type_object( $type );
			if ( ! $post_type ) {
				return new WP_Error( 'wxr_import_missing_post_type', __( 'Import requires the same registered content types as its native WXR source.', 'wp-ai-bridge' ) );
			}
			if ( ! current_user_can( $post_type->cap->edit_posts ) || ! current_user_can( $post_type->cap->create_posts ) ) {
				return $this->denied();
			}
			if ( in_array( $post['status'] ?? '', array( 'publish', 'private', 'future' ), true ) ) {
				if ( ! current_user_can( $post_type->cap->publish_posts ) ) {
					return $this->denied();
				}
				++$publishing;
			}
			$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
			$name           = sanitize_user( $post['post_author'], true );
			if ( '' !== $name ) {
				$authors[ $name ] = true;
			}
			if ( 'attachment' === $type ) {
				++$attachments;
			}
			if ( 'auto-draft' !== ( $post['status'] ?? '' ) ) {
				$existing = (int) post_exists( $post['post_title'], '', $post['post_date'], $type );
				if ( $existing > 0 ) {
					$source_id = (int) ( $post['post_id'] ?? 0 );
					if ( $source_id < 1 ) {
						return $this->invalid();
					}
					++$conflicts;
					$conflict_targets[] = array( $source_id, $existing );
				}
			}
		}
		if ( $publishing > 0 && ! $this->permissions->allowed( Settings::GROUP_LIVE_CONTENT, 'publish_posts' ) ) {
			return new WP_Error( 'wxr_import_publish_denied', __( 'Publishing imported WXR content requires Live Content authorization.', 'wp-ai-bridge' ) );
		}
		foreach ( array( 'categories', 'tags', 'terms' ) as $group ) {
			if ( ! is_array( $native[ $group ] ?? null ) ) {
				return $this->invalid();
			}
		}
		foreach ( $native['terms'] as $term ) {
			if ( ! is_array( $term ) || ! is_string( $term['term_taxonomy'] ?? null ) ||
				! taxonomy_exists( $term['term_taxonomy'] ) ) {
				return new WP_Error( 'wxr_import_missing_taxonomy', __( 'Import requires registered taxonomies from the verified WXR.', 'wp-ai-bridge' ) );
			}
			$taxonomy = get_taxonomy( $term['term_taxonomy'] );
			if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->manage_terms ) ) {
				return $this->denied();
			}
		}
		foreach ( $native['posts'] as $post ) {
			foreach ( $post['terms'] ?? array() as $term ) {
				if ( ! is_array( $term ) || ! is_string( $term['domain'] ?? null ) ||
					! taxonomy_exists( $term['domain'] ) ) {
					return new WP_Error( 'wxr_import_missing_taxonomy', __( 'Import requires registered taxonomies from the verified WXR.', 'wp-ai-bridge' ) );
				}
				$taxonomy = get_taxonomy( $term['domain'] );
				if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
					return $this->denied();
				}
			}
		}
		ksort( $types, SORT_STRING );
		ksort( $authors, SORT_STRING );
		ksort( $mapping, SORT_STRING );
		foreach ( $mapping as $login => $id ) {
			if ( ! is_string( $login ) || '' === $login ||
				sanitize_user( $login, true ) !== $login ||
				! isset( $authors[ $login ] ) || ! is_int( $id ) || $id < 1 ||
				! get_user_by( 'id', $id ) ||
				( is_multisite() && ! is_user_member_of_blog( $id, get_current_blog_id() ) ) ) {
				return $this->invalid();
			}
		}
		clearstatcache( true, $source['path'] );
		if ( filesize( $source['path'] ) !== $streamed['content_bytes'] ||
			! hash_equals( $source['sha256'], (string) hash_file( 'sha256', $source['path'] ) ) ) {
			return new WP_Error( 'wxr_import_stale', __( 'Private WXR bytes changed after the native preview.', 'wp-ai-bridge' ) );
		}
		$identity = array(
			'version'            => 1,
			'blog'               => (int) get_current_blog_id(),
			'user'               => (int) get_current_user_id(),
			'client'             => OAuth_Server::authenticated_mcp_client_id(),
			'lease'              => $input['transfer_id'],
			'bytes'              => $source['bytes'],
			'sha256'             => $source['sha256'],
			'provider'           => $provider,
			'types'              => $types,
			'authors'            => array_keys( $authors ),
			'mapping'            => $mapping,
			'conflicts'          => $conflicts,
			'publish_candidates' => $publishing,
			'conflict_targets'   => $conflict_targets,
			'conflict'           => $mode,
		);
		$wire     = wp_json_encode( $identity );
		$token    = is_string( $wire ) ? hash_hmac( 'sha256', $wire, wp_salt( 'auth' ) ) : '';
		if ( '' === $token ) {
			return new WP_Error( 'wxr_import_plan_unavailable', __( 'Native WXR import identity could not be bound.', 'wp-ai-bridge' ) );
		}
		return array(
			'transfer_id'           => $input['transfer_id'],
			'plan_token'            => $token,
			'blog_id'               => (int) get_current_blog_id(),
			'content_sha256'        => $source['sha256'],
			'content_bytes'         => $source['bytes'],
			'item_count'            => count( $native['posts'] ),
			'post_type_counts'      => $types,
			'author_count'          => count( $authors ),
			'author_names'          => array_keys( $authors ),
			'author_mapping'        => $mapping,
			'default_author_id'     => (int) get_current_user_id(),
			'conflict_count'        => $conflicts,
			'existing_targets'      => $conflict_targets,
			'publish_candidates'    => $publishing,
			'on_conflict'           => $mode,
			'attachment_candidates' => $attachments,
			'fetch_attachments'     => false,
			'create_users'          => false,
			'rewrite_urls'          => false,
			'native_provider'       => 'wordpress-importer',
			'import_performed'      => false,
			'partial_possible'      => true,
		);
	}

	/**
	 * Actual explicit native Importer effect. Never assume atomicity or retry.
	 *
	 * @param mixed $input Previously consented exact import choices/token.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input ) {
		if ( ! $this->can_execute() || ! is_array( $input ) ||
			! isset( $input['confirm'], $input['plan_token'] ) ||
			true !== $input['confirm'] ||
			! is_string( $input['plan_token'] ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['plan_token'] ) ||
			array_diff( array_keys( $input ), array( 'transfer_id', 'author_mapping', 'on_conflict', 'plan_token', 'confirm' ) ) ) {
			return $this->invalid();
		}
		$plan_input = $input;
		unset( $plan_input['plan_token'], $plan_input['confirm'] );
		$plan = $this->plan( $plan_input );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( ! hash_equals( $plan['plan_token'], $input['plan_token'] ) ) {
			return new WP_Error( 'wxr_import_plan_stale', __( 'WXR import plan, current target or authorization changed; review a new plan before importing.', 'wp-ai-bridge' ) );
		}
		if ( $plan['conflict_count'] > 0 && 'reject' === $plan['on_conflict'] ) {
			return new WP_Error( 'wxr_import_conflict', __( 'Native WXR posts already exist; a new explicit allow-existing plan is required.', 'wp-ai-bridge' ) );
		}
		$claim = $this->store->claim_wxr_import( $input['transfer_id'], $plan['plan_token'] );
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}
		$reported         = null;
		$failed           = false;
		$finished         = false;
		$level            = ob_get_level();
		$old_lib          = libxml_use_internal_errors();
		$importer         = null;
		$expected_targets = array();
		foreach ( $plan['existing_targets'] as $pair ) {
			$expected_targets[ $pair[0] ] = $pair[1];
		}
		// The native provider may race another WordPress writer after the
		// plan recheck. Compare its exact per-post existing-target result
		// immediately before Core acts; never silently add meta/comments to
		// an unapproved post, even if previous items were already imported.
		$existing_guard = function ( $existing, $post ) use ( $expected_targets, $plan ) {
			$source_id = (int) ( $post['post_id'] ?? 0 );
			$expected  = (int) ( $expected_targets[ $source_id ] ?? 0 );
			if ( ! $this->can_execute() || (int) $existing !== $expected ||
				( $plan['publish_candidates'] > 0 &&
					! $this->permissions->allowed( Settings::GROUP_LIVE_CONTENT, 'publish_posts' ) ) ||
				( 'allow_existing' === $plan['on_conflict'] &&
					! $this->permissions->allowed( Settings::GROUP_LIVE_CONTENT, 'edit_others_posts' ) ) ) {
				throw new \RuntimeException( 'Native WXR target or authority changed during an irrevocable import.' );
			}
			return $existing;
		};
		$http_veto      = static function () {
			return new WP_Error( 'wxr_import_network_denied', 'Native WXR import does not authorize remote connections.' );
		};
		add_filter( 'wp_import_existing_post', $existing_guard, PHP_INT_MAX, 2 );
		add_filter( 'pre_http_request', $http_veto, 1 );
		try {
			if ( ! ob_start(
				static function () {
					return ''; }
			) ) {
				$failed = true;
			} else {
				$importer = new WXR_Safe_Importer( $plan['author_mapping'], get_current_user_id() );
				$importer->import( $claim['path'], array( 'rewrite_urls' => false ) );
				$finished = true;
			}
		} catch ( \Throwable $error ) {
			$failed = true;
		} finally {
			while ( ob_get_level() > $level ) {
				if ( ! ob_end_clean() ) {
					$failed = true;
					break;
				}
			}
			remove_filter( 'pre_http_request', $http_veto, 1 );
			remove_filter( 'wp_import_existing_post', $existing_guard, PHP_INT_MAX );
			libxml_clear_errors();
			libxml_use_internal_errors( $old_lib );
			if ( ! $finished ) {
				// Native Importer lacks a transaction; recover PHP process state
				// but leave the durable one-way native-effect claim untouched.
				wp_suspend_cache_invalidation( false );
				wp_defer_term_counting( false );
				wp_defer_comment_counting( false );
			}
		}
		if ( $failed || ! $finished || ! $importer ) {
			$this->store->abandon_wxr_import( $claim );
			return new WP_Error( 'wxr_import_outcome_unknown', __( 'Native WordPress Importer was interrupted after a durable claim. Inspect WordPress content and transfer status; do not retry.', 'wp-ai-bridge' ) );
		}
		$processed = count( $importer->processed_posts );
		$reported  = $this->store->finish_wxr_import( $claim, $plan['item_count'], $processed, true );
		if ( is_wp_error( $reported ) ) {
			return $reported;
		}
		return array(
			'transfer_id'              => $claim['id'],
			'plan_token'               => $plan['plan_token'],
			'state'                    => $reported['state'],
			'outcome'                  => $reported['outcome'],
			'planned_items'            => $plan['item_count'],
			'processed_posts'          => $processed,
			'conflict_count'           => $plan['conflict_count'],
			'created_users'            => false,
			'fetched_attachments'      => false,
			'native_provider_returned' => true,
			'partial_possible'         => true,
			'needs_inspection'         => true,
		);
	}

	/**
	 * Only a verified, active official WordPress Importer can mutate.
	 * It must have been loaded by WordPress's native pre-bootstrap gate.
	 *
	 * @return string|WP_Error The exact current provider version.
	 */
	private function provider() {
		if ( ! defined( 'WP_LOAD_IMPORTERS' ) || true !== WP_LOAD_IMPORTERS ||
			! class_exists( 'WP_Import', false ) ) {
			return new WP_Error( 'wxr_import_dependency', __( 'Enable and activate the official WordPress Importer with WP_LOAD_IMPORTERS configured before the WordPress request.', 'wp-ai-bridge' ) );
		}
		$reflection = new \ReflectionClass( 'WP_Import' );
		$source     = realpath( $reflection->getFileName() );
		$root       = realpath( WP_PLUGIN_DIR . '/wordpress-importer' );
		if ( ! is_string( $source ) || ! is_string( $root ) ||
			! str_starts_with( wp_normalize_path( $source ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
			return new WP_Error( 'wxr_import_dependency', __( 'The active official WordPress Importer provider could not be verified.', 'wp-ai-bridge' ) );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		$version   = (string) ( $installed['wordpress-importer/wordpress-importer.php']['Version'] ?? '' );
		if ( '0.9.6' !== $version ||
			! method_exists( 'WP_Import', 'import' ) ||
			! method_exists( 'WP_Import', 'parse' ) ) {
			return new WP_Error( 'wxr_import_unsupported_provider', __( 'Use a reviewed compatible version of the official WordPress Importer before executing imports.', 'wp-ai-bridge' ) );
		}
		// WP_Import normally runs under wp-admin where these official Core
		// helpers are eagerly loaded. Public OAuth REST/MCP execution does
		// not have that admin bootstrap: import post_exists() and taxonomy
		// helpers explicitly, not a forged admin.php request or form nonce.
		if ( ! function_exists( 'post_exists' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		if ( ! function_exists( 'wp_insert_category' ) ) {
			require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
		}
		return $version;
	}

	/**
	 * WordPress Importer deserializes post/term/comment metadata and may invoke
	 * provider callbacks on values. Refuse serialized PHP objects/custom types
	 * and reference aliases before any native effect; primitive/array metadata
	 * remain handled by the owning WordPress Importer.
	 *
	 * @param array<string,mixed> $native Official parsed WXR tree.
	 * @return bool No serialized object/reference payload found.
	 */
	private function safe_serialized_meta( $native ) {
		$meta_groups = array();
		foreach ( $native['posts'] as $post ) {
			$meta_groups[] = $post['postmeta'] ?? array();
			foreach ( $post['comments'] ?? array() as $comment ) {
				$meta_groups[] = $comment['commentmeta'] ?? array();
			}
		}
		foreach ( array( 'categories', 'tags', 'terms' ) as $group ) {
			foreach ( $native[ $group ] ?? array() as $term ) {
				$meta_groups[] = $term['termmeta'] ?? array();
			}
		}
		foreach ( $meta_groups as $items ) {
			if ( ! is_array( $items ) ) {
				return false;
			}
			foreach ( $items as $meta ) {
				if ( ! is_array( $meta ) || ! is_string( $meta['value'] ?? null ) ) {
					return false;
				}
				$value = $meta['value'];
				if ( is_serialized( $value ) &&
					preg_match( '/(?:O|C):[0-9]{1,12}:"|(?:^|[;{])(?:r|R):[0-9]{1,12};/', $value ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** @return WP_Error */
	private function denied() {
		return new WP_Error( 'wxr_import_denied', __( 'Native WordPress import plus Site Configuration, Builder Write and administrator authority are required.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function invalid() {
		return new WP_Error( 'wxr_import_invalid', __( 'Invalid private WXR plan or explicit native import parameters.', 'wp-ai-bridge' ) );
	}
}
