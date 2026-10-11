<?php
/**
 * Internal, read-only WXR artifact preflight before any native importer logic.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;
use XMLReader;

/**
 * Bounded structural inspection of a privately staged Core WXR artifact.
 *
 * This is NOT an authoritative WordPress Importer dry-run or import plan:
 * Core/provider hooks, author mapping, duplicates, media and side effects
 * are evaluated only by the actual official importer under separate gates.
 * Never registers an endpoint or accepts a client-controlled filesystem path.
 */
final class WXR_Import_Preflight {
	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Existing Bridge/native authorization. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/**
	 * Inspect an internal private artifact with explicit finite resource caps.
	 *
	 * @param array<string,mixed> $artifact Internal staged descriptor only.
	 * @param int                 $max_bytes Max allowed on-disk bytes.
	 * @param int                 $max_nodes Max streamed XML reader nodes.
	 * @param int                 $max_items Max RSS post items to inspect.
	 * @return array<string,mixed>|WP_Error Read-only structural summary, no path.
	 */
	public function inspect( $artifact, $max_bytes, $max_nodes, $max_items ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'import' ) ) {
			return new WP_Error( 'wxr_preview_denied', __( 'Native WordPress import and Site Configuration authorization are required.', 'wp-ai-bridge' ) );
		}
		if (
			! is_array( $artifact ) ||
			! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes >= PHP_INT_MAX ||
			! is_int( $max_nodes ) || $max_nodes < 1 ||
			! is_int( $max_items ) || $max_items < 1 ||
			! isset( $artifact['path'], $artifact['bytes'], $artifact['sha256'], $artifact['blog_id'] ) ||
			! is_string( $artifact['path'] ) ||
			! is_int( $artifact['bytes'] ) ||
			! is_int( $artifact['blog_id'] ) ||
			! is_string( $artifact['sha256'] ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $artifact['sha256'] )
		) {
			return new WP_Error( 'wxr_preview_invalid', __( 'Invalid private WXR preview parameters.', 'wp-ai-bridge' ) );
		}
		if ( (int) get_current_blog_id() !== $artifact['blog_id'] ) {
			return new WP_Error( 'wxr_preview_wrong_site', __( 'The WXR artifact belongs to another WordPress blog.', 'wp-ai-bridge' ) );
		}
		$path      = $artifact['path'];
		$canonical = realpath( $path );
		$temp_dir  = realpath( get_temp_dir() );
		if (
			! is_string( $canonical ) || ! is_string( $temp_dir ) ||
			is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ||
			dirname( $canonical ) !== $temp_dir ||
			0 !== strpos( basename( $canonical ), 'wpai-private-artifact' ) ||
			0600 !== ( fileperms( $canonical ) & 0777 )
		) {
			return new WP_Error( 'wxr_preview_storage', __( 'WXR preview requires an owner-only internal temporary artifact.', 'wp-ai-bridge' ) );
		}
		clearstatcache( true, $canonical );
		$size = filesize( $canonical );
		if ( ! is_int( $size ) || 0 === $size || $size > $max_bytes || $artifact['bytes'] !== $size ) {
			return new WP_Error( 'wxr_preview_size', __( 'WXR preview exceeds the available byte budget or source length changed.', 'wp-ai-bridge' ) );
		}
		$digest = hash_file( 'sha256', $canonical );
		if ( ! is_string( $digest ) || ! hash_equals( $artifact['sha256'], $digest ) ) {
			return new WP_Error( 'wxr_preview_stale', __( 'WXR source SHA-256 changed after staging.', 'wp-ai-bridge' ) );
		}
		if ( ! class_exists( XMLReader::class ) ) {
			return new WP_Error( 'wxr_preview_unsupported', __( 'Streaming XMLReader is unavailable for WXR inspection.', 'wp-ai-bridge' ) );
		}

		// XMLReader::open() never receives a remote URL or caller path. Disable
		// network, DTD loading, validation and entity substitution. Reject any
		// DOCTYPE/entity node even if libxml would otherwise permit it.
		$reader       = new XMLReader();
		$previous_lib = libxml_use_internal_errors( true );
		$nodes        = 0;
		$item_count   = 0;
		$types        = array();
		$version      = '';
		$seen_rss     = false;
		$seen_channel = false;
		$current_type = '';
		$inside_item  = false;
		$error_code   = '';
		$opened       = false;
		try {
			libxml_clear_errors();
			$opened = $reader->open( $canonical, null, LIBXML_NONET | LIBXML_COMPACT );
			if ( ! $opened ) {
				$error_code = 'wxr_preview_xml';
			} else {
				$reader->setParserProperty( XMLReader::LOADDTD, false );
				$reader->setParserProperty( XMLReader::VALIDATE, false );
				$reader->setParserProperty( XMLReader::SUBST_ENTITIES, false );
				while ( $reader->read() ) {
					++$nodes;
					if ( $nodes > $max_nodes ) {
						$error_code = 'wxr_preview_complex';
						break;
					}
					// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native XMLReader fields.
					if (
						XMLReader::DOC_TYPE === $reader->nodeType ||
						XMLReader::ENTITY === $reader->nodeType ||
						XMLReader::ENTITY_REF === $reader->nodeType
					) {
						$error_code = 'wxr_preview_unsafe_xml';
						break;
					}
					if ( XMLReader::ELEMENT === $reader->nodeType ) {
						if ( 0 === $reader->depth && 'rss' === $reader->localName ) {
							$seen_rss = true;
						}
						if ( 1 === $reader->depth && 'channel' === $reader->localName ) {
							$seen_channel = true;
						}
						if ( 2 === $reader->depth && 'item' === $reader->localName && $seen_channel ) {
							$inside_item  = true;
							$current_type = '';
							++$item_count;
							if ( $item_count > $max_items ) {
								$error_code = 'wxr_preview_complex';
								break;
							}
						}
						if ( 'http://wordpress.org/export/1.2/' === $reader->namespaceURI ) {
							if ( 'wxr_version' === $reader->localName && 2 === $reader->depth ) {
								$version = trim( $reader->readString() );
								if ( strlen( $version ) > 8 ) {
									$error_code = 'wxr_preview_unsupported';
									break;
								}
							}
							if ( $inside_item && 'post_type' === $reader->localName && 3 === $reader->depth ) {
								$current_type = trim( $reader->readString() );
								if ( strlen( $current_type ) > 64 || 1 !== preg_match( '/^[a-z0-9_-]+$/D', $current_type ) ) {
									$error_code = 'wxr_preview_unsupported';
									break;
								}
							}
						}
					}
					if ( XMLReader::END_ELEMENT === $reader->nodeType &&
						2 === $reader->depth && 'item' === $reader->localName && $inside_item ) {
						$inside_item    = false;
						$name           = '' !== $current_type ? $current_type : 'unknown';
						$types[ $name ] = ( $types[ $name ] ?? 0 ) + 1;
					}
					// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}
				if ( '' === $error_code && libxml_get_errors() ) {
					$error_code = 'wxr_preview_xml';
				}
			}
		} catch ( \Throwable $exception ) {
			$error_code = 'wxr_preview_xml';
		} finally {
			if ( $opened ) {
				$reader->close();
			}
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_lib );
		}
		if ( '' !== $error_code ) {
			return new WP_Error( $error_code, __( 'The WXR artifact did not pass bounded structural XML inspection.', 'wp-ai-bridge' ) );
		}
		if ( ! $seen_rss || ! $seen_channel || '1.2' !== $version || $inside_item ) {
			return new WP_Error( 'wxr_preview_unsupported', __( 'The WXR document is incomplete or has an unsupported version.', 'wp-ai-bridge' ) );
		}
		// The input is a private staged file, but an unrelated privileged
		// process could still change it during XML streaming. Never commit
		// a preview bound to different bytes than those inspected.
		clearstatcache( true, $canonical );
		$after_size   = filesize( $canonical );
		$after_digest = hash_file( 'sha256', $canonical );
		if (
			! is_int( $after_size ) || $after_size !== $size ||
			! is_string( $after_digest ) || ! hash_equals( $digest, $after_digest )
		) {
			return new WP_Error( 'wxr_preview_stale', __( 'WXR source SHA-256 changed after staging.', 'wp-ai-bridge' ) );
		}
		ksort( $types, SORT_STRING );

		return array(
			'blog_id'            => (int) get_current_blog_id(),
			'content_sha256'     => $digest,
			'content_bytes'      => $size,
			'wxr_version'        => $version,
			'item_count'         => $item_count,
			'post_type_counts'   => $types,
			'inspected_nodes'    => $nodes,
			'preview_type'       => 'structural_only',
			'authoritative_plan' => false,
			'mutation_performed' => false,
		);
	}
}
