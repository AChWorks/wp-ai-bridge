<?php
/**
 * Exact log windows, bounded backwards tail, and observed append cursors.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support;

use WP_Error;

/** Every pathname open and identity check belongs to Scoped_Filesystem. */
final class Log_Reader {
	/** @var Administration_Access */
	private $access;
	/** @var Filesystem_Roots */
	private $roots;
	/** @var Scoped_Filesystem */
	private $files;
	/** @param Permissions $permissions Current authority. */
	public function __construct( Permissions $permissions ) {
		$this->access = new Administration_Access( $permissions );
		$this->roots  = new Filesystem_Roots( $permissions );
		$this->files  = new Scoped_Filesystem( $permissions );
	}
	/** @return array|WP_Error Approved roots and native configured file sources. */
	public function sources() {
		$roots = $this->roots->available( 'logs', 'read' );
		if ( is_wp_error( $roots ) ) {
			return $roots;
		}
		$candidates = array(
			'wordpress' => defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG :
				( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : '' ),
			'php'       => (string) ini_get( 'error_log' ),
		);
		$sources    = array();
		foreach ( $candidates as $name => $path ) {
			$row = array(
				'source'    => $name,
				'available' => false,
				'reason'    => 'not_configured',
			);
			if ( '' === $path || 'syslog' === $path || false !== strpos( $path, '://' ) || '/' !== substr( $path, 0, 1 ) ) {
				$row['reason'] = 'not_a_configured_absolute_file';
			} else {
				foreach ( $roots as $root ) {
					$id       = $root['id'] ?? ( $root['root_id'] ?? '' );
					$resolved = $this->roots->resolve( $id, 'logs', 'read' );
					if ( is_wp_error( $resolved ) ) {
						continue;
					}
					$base = rtrim( $resolved['path'] ?? '', '/' );
					if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
						$relative = substr( $path, strlen( $base ) + 1 );
						$stat     = $this->files->stat( $id, $relative, 'logs' );
						if ( ! is_wp_error( $stat ) && 'file' === ( $stat['type'] ?? '' ) ) {
							$row = array(
								'source'    => $name,
								'available' => true,
								'reason'    => '',
								'root_id'   => $id,
								'path'      => $relative,
							);
						}
						break;
					}
				}
			}
			$sources[] = $row;
		}
		return array(
			'roots'         => $roots,
			'sources'       => $sources,
			'provider_logs' => 'approved_root_and_relative_path',
		);
	}
	/** @param array $input Closed input. @param array $origin Native identity. @return array|WP_Error */
	public function read( array $input, array $origin ) {
		$config = $this->access->configuration();
		$budget = min( (int) ( $config['step_bytes'] ?? 0 ), Bounded_Payload::window_limit(), max( 0, intdiv( Bounded_Payload::response_limit() - 8192, 4 ) ) );
		$memory = Operation_Content_Limits::effective_memory_bytes();
		if ( $memory > 0 ) {
			$budget = min( $budget, max( 0, intdiv( $memory - memory_get_usage( true ), 16 ) ) );
		}
		if ( $budget < 1 ) {
			return $this->error( 'logs_capacity' );
		}
		$maximum = min( $input['max_bytes'] ?? $budget, $budget );
		$cursor  = isset( $input['cursor'] ) ? $this->decode_cursor( $input['cursor'] ) : null;
		if ( null !== $cursor && ( is_wp_error( $cursor ) || ( $cursor['origin'] ?? null ) !== $origin ||
			( $cursor['revision'] ?? null ) !== ( $config['revision'] ?? null ) ||
			( $cursor['root'] ?? null ) !== $input['root_id'] || ( $cursor['path'] ?? null ) !== hash( 'sha256', wp_json_encode( $input['path'] ) ) ) ) {
			return $this->error( 'logs_cursor_stale' );
		}
		$deadline = microtime( true ) + max( 1, (int) ( $config['step_seconds'] ?? 1 ) );
		return $this->files->with_read_stream(
			$input['root_id'],
			$input['path'],
			'logs',
			null,
			function ( $stream, $descriptor ) use ( $input, $origin, $cursor, $config, $maximum, $deadline ) {
				$size = $descriptor['size'] ?? ( $descriptor['bytes'] ?? null );
				$key  = $descriptor['file_key'] ?? null;
				if ( ! is_int( $size ) || $size < 0 || ! is_string( $key ) || '' === $key ) {
					return $this->error( 'logs_identity_unavailable' );
				}
				$offset    = $input['offset'] ?? 0;
				$inspected = 0;
				if ( null !== $cursor ) {
					if ( ( $cursor['file_key'] ?? null ) !== $key || ( $cursor['root_revision'] ?? null ) !== ( $descriptor['root_revision'] ?? null ) ||
						! is_int( $cursor['offset'] ?? null ) || $cursor['offset'] > $size || $cursor['offset'] < 0 ||
						! is_int( $cursor['anchor_offset'] ?? null ) || $cursor['anchor_offset'] < 0 ||
						$cursor['anchor_offset'] > $cursor['offset'] || $cursor['offset'] - $cursor['anchor_offset'] > 128 ) {
						return $this->error( 'logs_rotated_or_truncated' );
					}
					$anchor = self::stream_bytes( $stream, $cursor['anchor_offset'], $cursor['offset'] - $cursor['anchor_offset'] );
					if ( is_wp_error( $anchor ) || ! is_string( $cursor['anchor_sha256'] ?? null ) ||
						! hash_equals( $cursor['anchor_sha256'], hash( 'sha256', $anchor ) ) ) {
						return $this->error( 'logs_rotated_or_truncated' );
					}
					$inspected += strlen( $anchor );
					$offset     = $cursor['offset'];
				}
				if ( $offset > $size ) {
					return $this->error( 'logs_offset_invalid' );
				}
				if ( 'tail' === $input['action'] ) {
					$window = self::tail_bytes( $stream, $size, $maximum, $input['lines'] ?? 50, $deadline );
				} else {
					$data   = self::stream_bytes( $stream, $offset, min( $maximum, $size - $offset ) );
					$window = is_wp_error( $data ) ? $data : array(
						'bytes'           => $data,
						'offset'          => $offset,
						'inspected_bytes' => strlen( $data ),
						'leading_partial' => $offset > 0,
						'limited'         => strlen( $data ) < $size - $offset,
					);
				}
				if ( is_wp_error( $window ) ) {
					return $window;
				}
				$next          = $window['offset'] + strlen( $window['bytes'] );
				$anchor_offset = max( 0, $next - min( 128, $maximum ) );
				$anchor        = self::stream_bytes( $stream, $anchor_offset, $next - $anchor_offset );
				if ( is_wp_error( $anchor ) ) {
					return $anchor;
				}
				$continuation = array(
					'version'       => 1,
					'origin'        => $origin,
					'revision'      => $config['revision'] ?? null,
					'root'          => $input['root_id'],
					'path'          => hash( 'sha256', wp_json_encode( $input['path'] ) ),
					'root_revision' => $descriptor['root_revision'] ?? null,
					'file_key'      => $key,
					'offset'        => $next,
					'anchor_offset' => $anchor_offset,
					'anchor_sha256' => hash( 'sha256', $anchor ),
				);
				$result       = array(
					'root_id'         => $input['root_id'],
					'offset'          => $window['offset'],
					'next_offset'     => $next,
					'observed_size'   => $size,
					'bytes'           => strlen( $window['bytes'] ),
					'data_base64'     => base64_encode( $window['bytes'] ),
					'sha256'          => hash( 'sha256', $window['bytes'] ),
					'eof'             => $next === $size,
					'limited'         => $window['limited'],
					'leading_partial' => $window['leading_partial'],
					'inspected_bytes' => $inspected + $window['inspected_bytes'] + strlen( $anchor ),
					'identity'        => $descriptor['identity'],
					'cursor'          => $this->encode_cursor( $continuation ),
					'continuity'      => 'observed_file_and_boundary',
					'redacted'        => false,
				);
				return Bounded_Payload::fits( $result ) ? $result : $this->error( 'logs_response_capacity' );
			}
		);
	}
	/** @param resource $stream Pinned stream. @param int $offset Byte offset. @param int $bytes Finite count. @return string|WP_Error */
	public static function stream_bytes( $stream, $offset, $bytes ) {
		if ( ! is_resource( $stream ) || ! is_int( $offset ) || $offset < 0 || ! is_int( $bytes ) || $bytes < 0 || 0 !== fseek( $stream, $offset ) ) {
			return new WP_Error( 'logs_read_failed', __( 'The selected log window could not be read.', 'wp-ai-bridge' ) );
		}
		$data = '';
		while ( strlen( $data ) < $bytes ) {
			$part = fread( $stream, min( 8192, $bytes - strlen( $data ) ) );
			if ( false === $part || '' === $part ) {
				return new WP_Error( 'logs_read_failed', __( 'The selected log window could not be read.', 'wp-ai-bridge' ) );
			}
			$data .= $part;
		}
		return $data;
	}
	/** @param resource $stream Pinned stream. @param int $size Observed size. @param int $maximum Byte budget. @param int $lines Requested lines. @param float $deadline Work deadline. @return array|WP_Error */
	public static function tail_bytes( $stream, $size, $maximum, $lines, $deadline ) {
		$chunks           = array();
		$offset           = $size;
		$read             = 0;
		$newlines         = 0;
		$terminal_newline = false;
		while ( $offset > 0 && $read < $maximum && microtime( true ) < $deadline ) {
			$count   = min( 8192, $offset, $maximum - $read );
			$offset -= $count;
			$part    = self::stream_bytes( $stream, $offset, $count );
			if ( is_wp_error( $part ) ) {
				return $part;
			}
			if ( 0 === $read ) {
				$terminal_newline = "\n" === substr( $part, -1 );
			}
			$chunks[]  = $part;
			$read     += $count;
			$newlines += substr_count( $part, "\n" );
			if ( $newlines >= $lines + ( $terminal_newline ? 1 : 0 ) ) {
				break;
			}
		}
		$data     = implode( '', array_reverse( $chunks ) );
		$position = strlen( $data ) - ( $terminal_newline ? 2 : 1 );
		$found    = 0;
		while ( $position >= 0 && $found < $lines ) {
			if ( "\n" === $data[ $position ] ) {
				++$found;
				if ( $found === $lines ) {
					break;
				}
			}
			--$position;
		}
		$partial = $offset > 0;
		if ( $found === $lines ) {
			$offset += $position + 1;
			$data    = substr( $data, $position + 1 );
			$partial = false;
		}
		return array(
			'bytes'           => $data,
			'offset'          => $offset,
			'inspected_bytes' => $read,
			'leading_partial' => $partial,
			'limited'         => $partial,
		);
	}
	/** @param array $data Inert cursor. @return string */
	private function encode_cursor( array $data ) {
		$body = rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' );
		return $body . '.' . hash_hmac( 'sha256', $body, wp_salt( 'auth' ) );
	}
	/** @param string $cursor Opaque continuation. @return array|WP_Error */
	private function decode_cursor( $cursor ) {
		$parts = explode( '.', $cursor );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) {
			return $this->error( 'logs_cursor_invalid' );
		}
		$raw  = base64_decode( strtr( $parts[0], '-_', '+/' ), true );
		$data = false === $raw ? null : json_decode( $raw, true );
		return is_array( $data ) && 1 === ( $data['version'] ?? null ) ? $data : $this->error( 'logs_cursor_invalid' );
	}
	/** @param string $code Fixed safe code. @return WP_Error */
	private function error( $code ) {
		return new WP_Error( $code, __( 'The selected log source or continuation is unavailable. Reinspect its configured scope and current authority.', 'wp-ai-bridge' ) );
	}
}
