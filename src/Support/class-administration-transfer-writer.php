<?php
/**
 * Resource-bounded private administration producer sink.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support;

use WP_Error;

/** Internal only; never accepts a caller-selected pathname. */
final class Administration_Transfer_Writer {
	private $stream;
	private $limit;
	private $bytes = 0;
	private $hash;
	private $failed = false;
	private $closed = false;

	public function __construct( $stream, $limit ) {
		$this->stream = $stream;
		$this->limit  = $limit;
		$this->hash   = hash_init( 'sha256' );
	}

	public function append( $bytes ) {
		return $this->append_native( $bytes ) ? true : new WP_Error( 'transfer_capacity', 'Private producer write failed or exceeded its reserved byte budget.' );
	}

	/** No WordPress callbacks, including WP_Error hooks, while native results are active. */
	public function append_native( $bytes ) {
		if ( $this->closed || $this->failed || ! is_string( $bytes ) || strlen( $bytes ) > $this->limit - $this->bytes ) {
			$this->failed = true;
			return false;
		}
		$offset = 0;
		while ( $offset < strlen( $bytes ) ) {
			set_error_handler(
				static function () {
					return true;
				}
			);
			try {
				$written = fwrite( $this->stream, substr( $bytes, $offset ) );
			} finally {
				restore_error_handler();
			}
			if ( ! is_int( $written ) || $written < 1 ) {
				$this->failed = true;
				return false;
			}
			hash_update( $this->hash, substr( $bytes, $offset, $written ) );
			$this->bytes += $written;
			$offset      += $written;
		}
		return true;
	}

	public function remaining() {
		return $this->limit - $this->bytes;
	}

	public function finish() {
		if ( $this->closed || $this->failed || ! fflush( $this->stream ) || fstat( $this->stream )['size'] !== $this->bytes ) {
			return new WP_Error( 'transfer_outcome_unknown', 'Private producer bytes could not be verified.' );
		}
		$this->closed = true;
		return array(
			'bytes'  => $this->bytes,
			'sha256' => hash_final( $this->hash ),
		);
	}
}
