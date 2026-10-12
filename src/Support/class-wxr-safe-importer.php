<?php
/**
 * Minimal, deliberately constrained adapter over the official WordPress Importer.
 *
 * Loaded only AFTER official WP_Import has been bootstrapped and verified.
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Native WXR importer with explicit existing-user mapping, no user creation
 * and no remote attachments. Never relies on forged admin form parameters.
 */
final class WXR_Safe_Importer extends \WP_Import {
	/** @var array<string,int> */
	private $target_authors;

	/** @var int */
	private $current_author;

	/** @var \Closure Exact shared planning-time serialized-grammar validator. */
	private $serialized_guard;

	/**
	 * @param array<string,int> $authors Sanitized source-login => existing user ID.
	 * @param int               $actor Current principal.
	 * @param \Closure         $serialized_guard Reuse the pre-claim strict wire grammar.
	 */
	public function __construct( $authors, $actor, \Closure $serialized_guard ) {
		$this->target_authors    = $authors;
		$this->current_author    = $actor;
		$this->serialized_guard  = $serialized_guard;
		$this->fetch_attachments = false;
	}

	/** @return bool No remote attachment downloads, even if a filter requests them. */
	public function allow_fetch_attachments() {
		return false;
	}

	/** @return bool No new users or roles. */
	public function allow_create_users() {
		return false;
	}


	/**
	 * Override the official Importer's protected metadata decoder ONLY.
	 *
	 * Its upstream implementation runs a global O:class-name rewrite on the
	 * serialized blob (including inside byte-length-delimited *strings*),
	 * changing the grammar AFTER the Bridge's strict pre-claim inspection.
	 * Decode the exact original bytes instead, after reusing the very same
	 * bounded allowlist at this final consumption boundary. Disallow class
	 * materialization at the PHP runtime as a second independent safeguard.
	 * All post/term/comment/meta import semantics still belong to WP_Import.
	 *
	 * @param mixed $data Native parsed metadata input.
	 * @return mixed The unchanged scalar or exact decoded primitive/array.
	 */
	protected function maybe_unserialize( $data ) {
		if ( ! is_string( $data ) || ! is_serialized( $data ) ) {
			return $data;
		}
		if ( ! ( $this->serialized_guard )( $data ) ) {
			throw new \UnexpectedValueException( 'Unsafe serialized WXR metadata reached the native importer.' );
		}
		$value = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Prevalidated exact complete primitive/array grammar and runtime class-denial, preserving native WordPress maybe_unserialize semantics for valid b:0;.
		if ( false === $value && 'b:0;' !== $data ) {
			throw new \UnexpectedValueException( 'Previously verified WXR serialized metadata could not be decoded safely.' );
		}
		return $value;
	}

	/**
	 * Replace upstream $_POST/admin-form author mapping with an explicit,
	 * previously authorized existing-principal map.
	 *
	 * @return void
	 */
	public function get_author_mapping() {
		foreach ( $this->authors as $login => $author ) {
			$name                          = sanitize_user( (string) $login, true );
			$user                          = $this->target_authors[ $name ] ?? $this->current_author;
			$this->author_mapping[ $name ] = $user;
			if ( is_array( $author ) && ! empty( $author['author_id'] ) ) {
				$this->processed_authors[ (int) $author['author_id'] ] = $user;
			}
		}
	}
}
