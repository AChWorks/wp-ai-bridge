<?php
/**
 * Provider-scoped, lossless binary source windows for direct MCP fallback.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/**
 * Read-only byte windows with per-request authority and source-state binding.
 *
 * This is not a generic filesystem reader, a public signed URL, or a transfer
 * session. Neither a source path nor a file handle crosses the public boundary.
 * An ordinary MCP client can reconstruct the bytes and verify the SHA-256.
 */
final class Inline_File_Read {
	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Native Bridge permissions. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/**
	 * @param array<string,mixed> $input Validated typed Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' ) ) {
			return new WP_Error( 'file_read_denied', __( 'The current WordPress user is not allowed to read this file source.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'source_type', 'source_id', 'action', 'offset', 'max_bytes', 'expected_snapshot', 'expected_sha256' ) ) ) {
			return $this->invalid();
		}
		$type   = $input['source_type'] ?? '';
		$id     = $input['source_id'] ?? 0;
		$action = $input['action'] ?? '';
		if ( 'media' !== $type || ! is_int( $id ) || $id < 1 || ! in_array( $action, array( 'inspect', 'chunk' ), true ) ) {
			return $this->invalid();
		}
		if ( 'inspect' === $action && array_diff( array_keys( $input ), array( 'source_type', 'source_id', 'action' ) ) ) {
			return $this->invalid();
		}

		$source = $this->media_source( $id );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( 'inspect' === $action ) {
			$digest = hash_file( 'sha256', $source['path'] );
			if ( ! is_string( $digest ) || ! $this->source_is_same( $source, $id ) ) {
				return $this->changed();
			}
			return $this->format( $source, $digest, $this->snapshot( $source, $digest ), '', 0, 'inspect' );
		}

		$offset   = $input['offset'] ?? null;
		$max      = $input['max_bytes'] ?? null;
		$expected = $input['expected_snapshot'] ?? '';
		$digest   = $input['expected_sha256'] ?? '';
		if (
			! is_int( $offset ) || $offset < 0 || $offset > $source['bytes'] ||
			! is_int( $max ) || $max < 1 || $max > Bounded_Payload::response_limit() ||
			! is_string( $expected ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) ||
			! is_string( $digest ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest )
		) {
			return $this->invalid();
		}
		if ( ! hash_equals( $this->snapshot( $source, $digest ), $expected ) ) {
			return $this->changed();
		}

		// Bound this one read by the operator's *wire* response budget. Each
		// result may be duplicated by MCP Adapter in text + structured form.
		$remaining = $source['bytes'] - $offset;
		$budget    = Bounded_Payload::response_limit();
		$size      = min( $max, $remaining, max( 1, intdiv( $budget, 2 ) ) );
		$bytes     = '';
		if ( $size > 0 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Canonical native uploads-root file, bounded offset and length, read-only.
			$bytes = file_get_contents( $source['path'], false, null, $offset, $size );
			if ( ! is_string( $bytes ) || strlen( $bytes ) !== $size ) {
				return $this->changed();
			}
		}
		if ( ! $this->source_is_same( $source, $id ) ) {
			return $this->changed();
		}
		if ( $offset + strlen( $bytes ) === $source['bytes'] ) {
			// One final O(file-size) integrity check, not a full rehash on
			// every window. A caller must ALSO verify assembled SHA-256.
			$final_digest = hash_file( 'sha256', $source['path'] );
			if ( ! is_string( $final_digest ) || ! hash_equals( $digest, $final_digest ) || ! $this->source_is_same( $source, $id ) ) {
				return $this->changed();
			}
		}

		$low  = 0;
		$high = strlen( $bytes );
		$best = null;
		while ( $low <= $high ) {
			$middle    = intdiv( $low + $high, 2 );
			$candidate = $this->format( $source, $digest, $expected, base64_encode( substr( $bytes, 0, $middle ) ), $offset, 'chunk' );
			if ( Bounded_Payload::fits( $candidate ) ) {
				$best = $candidate;
				$low  = $middle + 1;
			} else {
				$high = $middle - 1;
			}
		}
		if ( ! is_array( $best ) || ( $remaining > 0 && $best['next_offset'] === $offset ) ) {
			return new WP_Error( 'file_read_response_budget', __( 'The file-read result cannot fit the current MCP response budget.', 'wp-ai-bridge' ) );
		}
		return $best;
	}

	/** @return WP_Error */
	private function invalid() {
		return new WP_Error( 'file_read_invalid', __( 'The file source, snapshot or byte window is invalid.', 'wp-ai-bridge' ) );
	}

	/** @return WP_Error */
	private function changed() {
		return new WP_Error( 'file_read_changed', __( 'The selected file or transfer identity changed. Inspect the source again.', 'wp-ai-bridge' ) );
	}

	/**
	 * Native Media Library source adapter, never a caller-supplied file path.
	 *
	 * @param int $id Exact attachment ID for current WordPress blog.
	 * @return array<string,mixed>|WP_Error
	 */
	private function media_source( $id ) {
		$post = get_post( $id );
		if (
			! $post || 'attachment' !== $post->post_type ||
			! current_user_can( 'read_post', $id ) ||
			( (int) $post->post_parent > 0 && ! current_user_can( 'read_post', (int) $post->post_parent ) ) ||
			0 === get_current_user_id()
		) {
			return new WP_Error( 'file_read_denied', __( 'The current WordPress user is not allowed to read this file source.', 'wp-ai-bridge' ) );
		}
		$upload = wp_get_upload_dir();
		$file   = get_attached_file( $id, true );
		$base   = isset( $upload['basedir'] ) && empty( $upload['error'] ) ? realpath( $upload['basedir'] ) : false;
		$path   = is_string( $file ) && '' !== $file ? realpath( $file ) : false;
		if ( ! is_string( $base ) || ! is_string( $path ) ||
			0 !== strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $base ) ) ) ||
			! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'file_read_unavailable', __( 'The authorized Media Library source has no readable native file.', 'wp-ai-bridge' ) );
		}
		clearstatcache( true, $path );
		$stat = stat( $path );
		if ( ! is_array( $stat ) || ! isset( $stat['size'], $stat['dev'], $stat['ino'], $stat['mtime'], $stat['ctime'] ) ||
			! is_int( $stat['size'] ) || $stat['size'] < 0 ) {
			return new WP_Error( 'file_read_unavailable', __( 'The authorized Media Library source has no readable native file.', 'wp-ai-bridge' ) );
		}

		// This is a tunable upper bound ONLY on the portable inline-MCP
		// Base64 fallback. It is not a limit on future binary streaming.
		$source_limit = ( new Payload_Settings() )->all()[ Payload_Settings::INLINE_FILE_KEY ] * 1048576;
		if ( $stat['size'] > $source_limit ) {
			return new WP_Error( 'file_read_too_large', __( 'The file exceeds the configured inline MCP reader ceiling; use another supported file transport.', 'wp-ai-bridge' ) );
		}
		return array(
			'path'   => $path,
			'stat'   => $stat,
			'bytes'  => $stat['size'],
			'id'     => $id,
			'mime'   => (string) get_post_mime_type( $id ),
			'name'   => sanitize_file_name( basename( $path ) ),
			'blog'   => (int) get_current_blog_id(),
			'user'   => (int) get_current_user_id(),
			'client' => OAuth_Server::authenticated_mcp_client_id(),
		);
	}

	/** @param array<string,mixed> $source Initial source. @param int $id Attachment. @return bool */
	private function source_is_same( $source, $id ) {
		$current = $this->media_source( $id );
		if ( is_wp_error( $current ) ) {
			return false;
		}
		return $this->source_key( $source ) === $this->source_key( $current );
	}

	/** @param array<string,mixed> $source Source. @return string */
	private function source_key( $source ) {
		$stat = $source['stat'];
		// Binary-safe fixed-field encoding; a non-UTF-8 filesystem name
		// must not collapse the source identity to failed JSON encoding.
		return serialize(
			array(
				'wpai-inline-source-v1',
				$source['blog'],
				$source['user'],
				$source['client'],
				$source['id'],
				$source['path'],
				$source['bytes'],
				$stat['dev'],
				$stat['ino'],
				$stat['mtime'],
				$stat['ctime'],
				$source['mime'],
				$source['name'],
			)
		);
	}

	/** @param array<string,mixed> $source Source. @param string $digest Digest. @return string */
	private function snapshot( $source, $digest ) {
		return hash_hmac( 'sha256', $this->source_key( $source ) . '|' . $digest, wp_salt( 'auth' ) );
	}

	/** @param array<string,mixed> $source Source. @param string $digest Hash. @param string $snap Snapshot. @param string $encoded Encoded bytes. @param int $offset Offset. @param string $mode Operation. @return array<string,mixed> */
	private function format( $source, $digest, $snap, $encoded, $offset, $mode ) {
		$length = 'chunk' === $mode ? intdiv( strlen( $encoded ), 4 ) * 3 - substr_count( substr( $encoded, -2 ), '=' ) : 0;
		$next   = $offset + (int) $length;
		return array(
			'mode'           => 'inline_mcp_base64',
			'source_type'    => 'media',
			'source_id'      => $source['id'],
			'snapshot'       => $snap,
			'content_sha256' => $digest,
			'total_bytes'    => $source['bytes'],
			'mime_type'      => $source['mime'],
			'file_name'      => $source['name'],
			'offset'         => $offset,
			'next_offset'    => $next,
			'content_base64' => $encoded,
			'complete'       => $next === $source['bytes'],
		);
	}
}
