<?php
/**
 * Bounded private temporary artifact writer shared by WordPress producers.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Internal byte stream, with caller-supplied finite budget and SHA-256.
 *
 * No client path, URL, ability or network exposure. A successful finish()
 * returns an owner-only temporary file; the consuming operation must delete
 * that file, enforce per-principal/site access and manage its own TTL.
 */
final class Private_Artifact_Writer {
	/** @var resource|false */
	private $handle = false;

	/** @var string */
	private $path = '';

	/** @var int */
	private $budget = 0;

	/** @var int */
	private $size = 0;

	/** @var resource|\HashContext */
	private $digest;

	/** @var bool */
	private $released = false;

	/** @param int $budget Maximum bytes for one staging operation. */
	private function __construct( $budget ) {
		$this->budget = $budget;
		$this->digest = hash_init( 'sha256' );
	}

	/**
	 * Create an owner-only file outside the web root; no caller path accepted.
	 *
	 * @param int $budget Positive finite maximum artifact bytes.
	 * @return self|WP_Error
	 */
	public static function begin( $budget ) {
		if ( ! is_int( $budget ) || $budget < 1 || $budget >= PHP_INT_MAX ) {
			return new WP_Error( 'artifact_budget_invalid', 'A positive finite private artifact budget is required.' );
		}
		$instance = new self( $budget );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = Safe_Temporary_Staging::create( 'wpai-private-artifact.bin' );
		if ( is_wp_error( $file ) ) {
			return new WP_Error( 'artifact_storage_unsafe', 'No safe private temporary directory is available for an artifact.' );
		}
		$real = realpath( $file );
		if ( ! is_string( $real ) || '' === $real || self::is_under_webroot( $real ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'artifact_storage_unsafe', 'Temporary artifact directory must not be web-accessible.' );
		}
		$instance->path = $real;
		if ( ! chmod( $real, 0600 ) || 0600 !== ( fileperms( $real ) & 0777 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Privately allocated WordPress temp file only.
			$instance->abort();
			return new WP_Error( 'artifact_storage_unsafe', 'Cannot enforce owner-only temporary artifact permissions.' );
		}
		$instance->handle = fopen( $real, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Restricted WordPress temp stream; never a supplied path.
		if ( false === $instance->handle ) {
			$instance->abort();
			return new WP_Error( 'artifact_storage_unavailable', 'Could not open the private temporary artifact.' );
		}
		return $instance;
	}

	/**
	 * Append exact opaque bytes, enforcing the full negotiated byte budget.
	 *
	 * @param string $bytes Arbitrary binary string, no decoding or transformation.
	 * @return bool|WP_Error
	 */
	public function append( $bytes ) {
		if ( ! is_string( $bytes ) || false === $this->handle || $this->released ) {
			return new WP_Error( 'artifact_writer_closed', 'The private artifact writer is not writable.' );
		}
		$length = strlen( $bytes );
		if ( $length > $this->budget - $this->size ) {
			return new WP_Error( 'artifact_too_large', 'Private artifact exceeded the negotiated staging byte budget.' );
		}
		$offset = 0;
		while ( $offset < $length ) {
			$written = fwrite( $this->handle, substr( $bytes, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Exact bounded WordPress private-temp stream.
			if ( false === $written || 0 === $written ) {
				return new WP_Error( 'artifact_short_write', 'Could not complete a private artifact write.' );
			}
			hash_update( $this->digest, substr( $bytes, $offset, $written ) );
			$this->size += $written;
			$offset     += $written;
		}
		return true;
	}

	/**
	 * Complete a fully verified private artifact and transfer cleanup ownership.
	 *
	 * @return array{path:string,bytes:int,sha256:string}|WP_Error
	 */
	public function finish() {
		if ( false === $this->handle || $this->released || '' === $this->path ) {
			return new WP_Error( 'artifact_writer_closed', 'The private artifact writer is not writable.' );
		}
		$flushed      = fflush( $this->handle );
		$closed       = fclose( $this->handle );
		$this->handle = false;
		clearstatcache( true, $this->path );
		if ( ! $flushed || ! $closed || ! is_file( $this->path ) ||
			filesize( $this->path ) !== $this->size ) {
			$this->abort();
			return new WP_Error( 'artifact_short_write', 'Private artifact length could not be verified.' );
		}
		$this->released = true;
		return array(
			'path'   => $this->path,
			'bytes'  => $this->size,
			'sha256' => hash_final( $this->digest ),
		);
	}

	/** Delete any unfinished private artifact; safe on repeated calls. */
	public function abort() {
		if ( false !== $this->handle ) {
			fclose( $this->handle );
			$this->handle = false;
		}
		if ( ! $this->released && '' !== $this->path ) {
			wp_delete_file( $this->path );
			$this->path = '';
		}
	}

	/** Prevent temporary-file leftovers on failed/abandoned producers. */
	public function __destruct() {
		$this->abort();
	}

	/** @param string $real_path Canonical private artifact path. @return bool */
	private static function is_under_webroot( $real_path ) {
		$roots = array( ABSPATH );
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$roots[] = $_SERVER['DOCUMENT_ROOT'];
		}
		foreach ( $roots as $root ) {
			$canonical = realpath( $root );
			if ( is_string( $canonical ) && '' !== $canonical ) {
				$prefix = trailingslashit( wp_normalize_path( $canonical ) );
				if ( 0 === strpos( wp_normalize_path( $real_path ), $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
