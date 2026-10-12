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

	/** @param array<string,int> $authors Sanitized source-login => existing user ID. @param int $actor Current principal. */
	public function __construct( $authors, $actor ) {
		$this->target_authors    = $authors;
		$this->current_author    = $actor;
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
