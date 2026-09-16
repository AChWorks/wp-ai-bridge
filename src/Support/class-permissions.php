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
