<?php
/**
 * Bounded, opaque installation backup frames.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * A frame chain is portable integrity metadata, not a whole-file SHA-256.
 * Every payload is bounded before allocation; no SQL or PHP is evaluated.
 */
final class Backup_Archive {
	const FORMAT       = 'wpai-installation-backup-v1';
	const CHAIN_FORMAT = 'sha256-frame-chain-v1';

	/** @return string Initial digest shared with the private stream backend. */
	public static function initial_chain() {
		return hash( 'sha256', 'wpai-administration-stream-v1' );
	}

	/** @param string $previous Prior digest. @param string $frame Exact frame. @return string */
	public static function fold( $previous, $frame ) {
		return hash( 'sha256', $previous . ':' . strlen( $frame ) . ':' . hash( 'sha256', $frame ) );
	}

	/** @param array $record Closed server-owned record. @param string $payload Raw bytes. @param int $budget Frame byte allowance. @return string */
	public static function encode( array $record, $payload, $budget ) {
		if ( ! is_string( $payload ) || ! is_int( $budget ) || $budget < 24 ) {
			throw new \RuntimeException( 'backup_frame_invalid' );
		}
		$record['payload_bytes']  = strlen( $payload );
		$record['payload_sha256'] = hash( 'sha256', $payload );
		$json                     = json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > 0xffffffff || strlen( $payload ) > 0xffffffff ||
			strlen( $json ) + strlen( $payload ) + 24 > $budget ) {
			throw new \RuntimeException( 'backup_frame_capacity' );
		}
		return 'WPAI1:' . sprintf( '%08x', strlen( $json ) ) . ':' . sprintf( '%08x', strlen( $payload ) ) . "\n" . $json . $payload;
	}

	/**
	 * Parse one exact frame at the current stream position. NULL means clean EOF.
	 *
	 * @param resource $stream Pinned private immutable stream.
	 * @param int $budget Current finite maximum complete frame bytes.
	 * @return array|null Exact frame, record and raw payload.
	 */
	public static function read( $stream, $budget ) {
		if ( ! is_resource( $stream ) || ! is_int( $budget ) || $budget < 24 ) {
			throw new \RuntimeException( 'backup_frame_invalid' );
		}
		$prefix = fread( $stream, 24 );
		if ( '' === $prefix && feof( $stream ) ) {
			return null;
		}
		if ( ! is_string( $prefix ) || 24 !== strlen( $prefix ) ||
			1 !== preg_match( '/^WPAI1:([a-f0-9]{8}):([a-f0-9]{8})\n$/D', $prefix, $match ) ) {
			throw new \RuntimeException( 'backup_archive_truncated' );
		}
		$header = hexdec( $match[1] );
		$bytes  = hexdec( $match[2] );
		if ( $header < 2 || $header > $budget - 24 || $bytes > $budget - 24 - $header ) {
			throw new \RuntimeException( 'backup_frame_capacity' );
		}
		$json    = self::exact( $stream, (int) $header );
		$payload = self::exact( $stream, (int) $bytes );
		$record  = json_decode( $json, true, 32 );
		if ( ! is_array( $record ) || ! is_int( $record['seq'] ?? null ) || $record['seq'] < 0 ||
			! is_string( $record['type'] ?? null ) ||
			( $record['payload_bytes'] ?? null ) !== $bytes ||
			! self::digest( $record['payload_sha256'] ?? null ) ||
			! hash_equals( $record['payload_sha256'], hash( 'sha256', $payload ) ) ) {
			throw new \RuntimeException( 'backup_archive_integrity' );
		}
		return array(
			'record'  => $record,
			'payload' => $payload,
			'frame'   => $prefix . $json . $payload,
		);
	}

	/** @param resource $stream Pinned source. @param int $bytes Bounded count. @return string */
	private static function exact( $stream, $bytes ) {
		$value = '';
		while ( strlen( $value ) < $bytes ) {
			$part = fread( $stream, $bytes - strlen( $value ) );
			if ( ! is_string( $part ) || '' === $part ) {
				throw new \RuntimeException( 'backup_archive_truncated' );
			}
			$value .= $part;
		}
		return $value;
	}

	/** @param mixed $value Candidate digest. @return bool */
	public static function digest( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	/** @param string $value Exact byte name. @return string */
	public static function name( $value ) {
		return base64_encode( $value );
	}

	/** @param mixed $value Encoded exact byte name. @return string */
	public static function decode_name( $value ) {
		$bytes = is_string( $value ) ? base64_decode( $value, true ) : false;
		if ( ! is_string( $bytes ) || base64_encode( $bytes ) !== $value || false !== strpos( $bytes, "\0" ) ) {
			throw new \RuntimeException( 'backup_archive_identifier' );
		}
		return $bytes;
	}
}
