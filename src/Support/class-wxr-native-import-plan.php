<?php
/**
 * Read-only official WordPress Importer analysis of a private WXR artifact.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Internal WordPress-owned semantic preview, not an executable import plan.
 *
 * Never creates users, downloads attachments, or calls WP_Import::import().
 * Official parser versions materialize the full file in PHP memory, so this
 * additional step is deliberately opt-in under its own finite parser budget.
 * Large/unknown files retain the independent XMLReader structural preflight.
 */
final class WXR_Native_Import_Plan {
	/** @var WXR_Import_Preflight */
	private $preflight;

	/** @param Permissions $permissions Existing native and Bridge permissions. */
	public function __construct( Permissions $permissions ) {
		$this->preflight = new WXR_Import_Preflight( $permissions );
	}

	/**
	 * Inspect exact Core WXR using the installed, native WordPress Importer.
	 *
	 * Limits are the caller/operator's resource policy, never hardcoded
	 * product-size limits. This is intentionally *not* a dry-run of import(),
	 * which mutates WordPress content, hooks, taxonomy, cache and metadata.
	 *
	 * @param array<string,mixed> $artifact Internal private staged descriptor.
	 * @param int                 $max_bytes Structural input byte quota.
	 * @param int                 $max_nodes Structural node quota.
	 * @param int                 $max_items Maximum post items.
	 * @param int                 $max_parser_bytes Finite **separate** full-parser byte quota.
	 * @param int                 $max_report_names Maximum names in diagnostic lists.
	 * @return array<string,mixed>|WP_Error Safe non-sensitive bounded summary only.
	 */
	public function inspect( $artifact, $max_bytes, $max_nodes, $max_items, $max_parser_bytes, $max_report_names ) {
		$structural = $this->preflight->inspect( $artifact, $max_bytes, $max_nodes, $max_items );
		if ( is_wp_error( $structural ) ) {
			return $structural;
		}
		if (
			! is_int( $max_parser_bytes ) || $max_parser_bytes < 1 || $max_parser_bytes >= PHP_INT_MAX ||
			! is_int( $max_report_names ) || $max_report_names < 1
		) {
			return new WP_Error( 'wxr_plan_invalid', __( 'Invalid native WXR analysis resource budgets.', 'wp-ai-bridge' ) );
		}
		if ( $structural['content_bytes'] > $max_parser_bytes ) {
			return new WP_Error( 'wxr_plan_budget', __( 'WXR file exceeds the full-parser input byte budget; structural inspection remains available.', 'wp-ai-bridge' ) );
		}

		// The native Importer only bootstraps with WP_LOAD_IMPORTERS enabled
		// before plugins load. Never include/redefine provider classes here.
		// Other plugins may define WP_Import; restrict to the normal installed
		// official WordPress Importer source tree and do not resolve aliases.
		if ( ! defined( 'WP_LOAD_IMPORTERS' ) || ! WP_LOAD_IMPORTERS || ! class_exists( 'WP_Import', false ) ) {
			return new WP_Error( 'wxr_plan_provider_unavailable', __( 'The official WordPress Importer is not enabled for this request.', 'wp-ai-bridge' ) );
		}
		$reflection = new \ReflectionClass( 'WP_Import' );
		$source     = realpath( $reflection->getFileName() );
		$folder     = realpath( WP_PLUGIN_DIR . '/wordpress-importer' );
		if (
			! is_string( $source ) || ! is_string( $folder ) ||
			0 !== strpos( wp_normalize_path( $source ), trailingslashit( wp_normalize_path( $folder ) ) )
		) {
			return new WP_Error( 'wxr_plan_provider_unavailable', __( 'The official WordPress Importer is not enabled for this request.', 'wp-ai-bridge' ) );
		}

		// Parse has no import side effects, but an installed provider might
		// echo diagnostics. Confine all such output; no data enters MCP JSON.
		$start_level     = ob_get_level();
		$previous_libxml = libxml_use_internal_errors();
		$parsed          = null;
		$failed          = false;
		try {
			if ( ! ob_start(
				static function () {
					return ''; }
			) ) {
				$failed = true;
			} else {
				$importer = new \WP_Import();
				$parsed   = $importer->parse( $artifact['path'] );
			}
		} catch ( \Throwable $error ) {
			$failed = true;
		} finally {
			while ( ob_get_level() > $start_level ) {
				if ( ! ob_end_clean() ) {
					$failed = true;
					break;
				}
			}
			// Official Importer 0.9.6 SimpleXML's parser switches this
			// process-wide setting on and does not restore it. A diagnostic
			// Ability must not change subsequent WordPress XML handling.
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_libxml );
		}

		if ( $failed || is_wp_error( $parsed ) || ! is_array( $parsed ) ||
			! isset( $parsed['posts'], $parsed['version'] ) ||
			! is_array( $parsed['posts'] ) || '1.2' !== (string) $parsed['version'] ||
			count( $parsed['posts'] ) !== $structural['item_count'] ) {
			return new WP_Error( 'wxr_plan_provider_mismatch', __( 'Official WordPress Importer could not consistently parse the staged WXR.', 'wp-ai-bridge' ) );
		}

		$types              = array();
		$missing_types      = array();
		$missing_taxonomies = array();
		$taxonomies         = array();
		$attachment_count   = 0;
		$comment_count      = 0;
		$postmeta_count     = 0;
		foreach ( $parsed['posts'] as $post ) {
			if ( ! is_array( $post ) || ! isset( $post['post_type'] ) || ! is_string( $post['post_type'] ) ||
				! preg_match( '/^[a-z0-9_-]{1,64}$/D', $post['post_type'] ) ) {
				return new WP_Error( 'wxr_plan_provider_mismatch', __( 'Official WordPress Importer could not consistently parse the staged WXR.', 'wp-ai-bridge' ) );
			}
			$type           = $post['post_type'];
			$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
			if ( ! post_type_exists( $type ) ) {
				$missing_types[ $type ] = true;
			}
			if ( 'attachment' === $type ) {
				++$attachment_count;
			}
			foreach ( array(
				'comments' => 'comment',
				'postmeta' => 'meta',
			) as $key => $kind ) {
				if ( isset( $post[ $key ] ) && ! is_array( $post[ $key ] ) ) {
					return new WP_Error( 'wxr_plan_provider_mismatch', __( 'Official WordPress Importer could not consistently parse the staged WXR.', 'wp-ai-bridge' ) );
				}
				$amount = count( $post[ $key ] ?? array() );
				if ( 'comment' === $kind ) {
					$comment_count += $amount;
				} else {
					$postmeta_count += $amount;
				}
			}
			if ( isset( $post['terms'] ) && ! is_array( $post['terms'] ) ) {
				return new WP_Error( 'wxr_plan_provider_mismatch', __( 'Official WordPress Importer could not consistently parse the staged WXR.', 'wp-ai-bridge' ) );
			}
			foreach ( $post['terms'] ?? array() as $term ) {
				if ( is_array( $term ) && isset( $term['domain'] ) && is_string( $term['domain'] ) ) {
					$taxonomies[ $term['domain'] ] = true;
				}
			}
		}
		foreach ( array( 'categories', 'tags', 'terms' ) as $group ) {
			if ( isset( $parsed[ $group ] ) && ! is_array( $parsed[ $group ] ) ) {
				return new WP_Error( 'wxr_plan_provider_mismatch', __( 'Official WordPress Importer could not consistently parse the staged WXR.', 'wp-ai-bridge' ) );
			}
			foreach ( $parsed[ $group ] ?? array() as $term ) {
				if ( ! is_array( $term ) ) {
					continue;
				}
				$taxonomy = 'categories' === $group ? 'category' : ( 'tags' === $group ? 'post_tag' : ( $term['term_taxonomy'] ?? '' ) );
				if ( is_string( $taxonomy ) && '' !== $taxonomy ) {
					$taxonomies[ $taxonomy ] = true;
				}
			}
		}

		foreach ( array_keys( $taxonomies ) as $taxonomy ) {
			if ( ! is_string( $taxonomy ) || 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/D', $taxonomy ) ) {
				return new WP_Error( 'wxr_plan_provider_mismatch', __( 'Official WordPress Importer could not consistently parse the staged WXR.', 'wp-ai-bridge' ) );
			}
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$missing_taxonomies[ $taxonomy ] = true;
			}
		}
		ksort( $types, SORT_STRING );
		ksort( $missing_types, SORT_STRING );
		ksort( $missing_taxonomies, SORT_STRING );

		// Check the source *after* the provider materializes the XML as well.
		// The returned fingerprint is evidence for this preview only, NEVER
		// authorization to execute a later mutation.
		clearstatcache( true, $artifact['path'] );
		$latest_size = filesize( $artifact['path'] );
		$latest_hash = hash_file( 'sha256', $artifact['path'] );
		if ( ! is_int( $latest_size ) || $latest_size !== $structural['content_bytes'] ||
			! is_string( $latest_hash ) || ! hash_equals( $structural['content_sha256'], $latest_hash ) ) {
			return new WP_Error( 'wxr_preview_stale', __( 'WXR source SHA-256 changed after staging.', 'wp-ai-bridge' ) );
		}

		$post_counts     = $structural['post_type_counts'];
		$native_counts   = $types;
		$equivalent_data = $post_counts === $native_counts;
		if ( ! $equivalent_data ) {
			return new WP_Error( 'wxr_plan_provider_mismatch', __( 'Official WordPress Importer could not consistently parse the staged WXR.', 'wp-ai-bridge' ) );
		}

		$unsupported_types = array_slice( array_keys( $missing_types ), 0, $max_report_names );
		$unsupported_terms = array_slice( array_keys( $missing_taxonomies ), 0, $max_report_names );
		$authors           = is_array( $parsed['authors'] ?? null ) ? count( $parsed['authors'] ) : 0;

		$result = array(
			'preview_type'                  => 'native_parser_informational',
			'provider'                      => 'wordpress-importer',
			'authoritative_import_plan'     => false,
			'import_executed'               => false,
			'blog_id'                       => $structural['blog_id'],
			'content_sha256'                => $structural['content_sha256'],
			'content_bytes'                 => $structural['content_bytes'],
			'wxr_version'                   => $structural['wxr_version'],
			'post_count'                    => count( $parsed['posts'] ),
			'post_type_counts'              => $types,
			'author_definitions'            => $authors,
			'category_definitions'          => count( $parsed['categories'] ?? array() ),
			'tag_definitions'               => count( $parsed['tags'] ?? array() ),
			'term_definitions'              => count( $parsed['terms'] ?? array() ),
			'comment_count'                 => $comment_count,
			'postmeta_count'                => $postmeta_count,
			'attachment_candidates'         => $attachment_count,
			'missing_post_type_count'       => count( $missing_types ),
			'missing_post_types'            => $unsupported_types,
			'missing_post_types_truncated'  => count( $missing_types ) > $max_report_names,
			'missing_taxonomy_count'        => count( $missing_taxonomies ),
			'missing_taxonomies'            => $unsupported_terms,
			'missing_taxonomies_truncated'  => count( $missing_taxonomies ) > $max_report_names,
			'author_mapping_consent_needed' => $authors > 0,
			'external_media_consent_needed' => $attachment_count > 0,
			'conflicts_unresolved'          => true,
			'native_parser_byte_quota'      => $max_parser_bytes,
		);
		unset( $parsed, $importer );
		return $result;
	}
}
