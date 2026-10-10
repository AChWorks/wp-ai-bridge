<?php
/**
 * Ability permission checks.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Combines bridge access groups with WordPress capability checks.
 */
final class Permissions {
	/**
	 * Bridge settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Creates the permission service.
	 *
	 * @param Settings $settings Bridge settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Checks both the bridge group and the current user's WordPress capability.
	 *
	 * @param string $group      Access-group key.
	 * @param string $capability Required WordPress capability.
	 * @return bool
	 */
	public function allowed( $group, $capability ) {
		if ( ! $this->settings->is_enabled( $group ) ) {
			return false;
		}

		if ( ! is_string( $capability ) || '' === $capability ) {
			return false;
		}

		return current_user_can( $capability );
	}

	/**
	 * Checks a full explicit high-trust grant set from one fresh snapshot.
	 *
	 * Avoids repeated option/group normalization for every protected
	 * lifecycle and never caches across an invocation or a grant revocation.
	 *
	 * @param array<int,array{0:string,1:string}> $requirements Explicit group/capability pairs.
	 * @return bool
	 */
	public function allowed_all( array $requirements ) {
		$enabled = $this->settings->all();
		foreach ( $requirements as $requirement ) {
			if (
				! is_array( $requirement ) || 2 !== count( $requirement ) ||
				! isset( $requirement[0], $requirement[1] ) ||
				! is_string( $requirement[0] ) || ! is_string( $requirement[1] ) ||
				'' === $requirement[1] || empty( $enabled[ $requirement[0] ] ) ||
				! current_user_can( $requirement[1] )
			) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Creates a reusable ability permission callback.
	 *
	 * @param string $group      Access-group key.
	 * @param string $capability Required WordPress capability.
	 * @return callable Permission callback.
	 */
	public function callback( $group, $capability ) {
		return function () use ( $group, $capability ) {
			return $this->allowed( $group, $capability );
		};
	}
}
