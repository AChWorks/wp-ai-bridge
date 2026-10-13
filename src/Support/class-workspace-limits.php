<?php
/**
 * Administrator-controlled site-local Workspace record-count policy.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Separates persistent record counts from per-record byte and MCP transport budgets.
 */
final class Workspace_Limits {
	const OPTION_NAME       = 'wp_ai_bridge_workspace_limits';
	const OPTION_GROUP      = 'wp_ai_bridge_workspace_capacity';
	const DOCUMENTS_KEY     = 'documents';
	const TASKS_KEY         = 'tasks';
	const DEFAULT_DOCUMENTS = 200;
	const DEFAULT_TASKS     = 200;
	const MIN_DOCUMENTS     = 10;
	const MIN_TASKS         = 10;
	// Finite operational guardrails. Reads must remain bounded before raising
	// these values on a particular deployment; this is not a DB/post-type limit.
	const MAX_DOCUMENTS = 20000;
	const MAX_TASKS     = 50000;

	/** @return array<string,int> */
	public function defaults() {
		return array(
			self::DOCUMENTS_KEY => self::DEFAULT_DOCUMENTS,
			self::TASKS_KEY     => self::DEFAULT_TASKS,
		);
	}

	/** @return array<string,int> */
	public function upper_bounds() {
		return array(
			self::DOCUMENTS_KEY => self::MAX_DOCUMENTS,
			self::TASKS_KEY     => self::MAX_TASKS,
		);
	}

	/** @return array<string,int> */
	public function all() {
		$values = $this->defaults();
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			return $values;
		}
		foreach ( $values as $key => $default ) {
			if ( array_key_exists( $key, $stored ) ) {
				$parsed = $this->parse( $key, $stored[ $key ] );
				if ( null !== $parsed ) {
					$values[ $key ] = $parsed;
				}
			}
		}
		return $values;
	}

	/** Native per-blog WordPress Settings API, independent of all grants. */
	public function register() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => $this->defaults(),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Invalid inputs retain the previous valid per-blog value; old data is
	 * NEVER removed if the site administrator lowers an admission limit.
	 *
	 * @param mixed $incoming Submitted option value.
	 * @return array<string,int>
	 */
	public function sanitize( $incoming ) {
		$previous = $this->all();
		if ( ! is_array( $incoming ) ) {
			$this->settings_error();
			return $previous;
		}
		foreach ( $this->defaults() as $key => $unused ) {
			if ( ! array_key_exists( $key, $incoming ) ) {
				continue;
			}
			$parsed = $this->parse( $key, $incoming[ $key ] );
			if ( null === $parsed ) {
				$this->settings_error();
			} else {
				$previous[ $key ] = $parsed;
			}
		}
		return $previous;
	}

	/**
	 * Strict decimal-only machine integer parser without exponent, fractions,
	 * signs, whitespace, float truncation or PHP bigint-to-float coercion.
	 *
	 * @param string $key Known record-count type.
	 * @param mixed  $value Submitted or stored scalar.
	 * @return int|null
	 */
	private function parse( $key, $value ) {
		$limits = array(
			self::DOCUMENTS_KEY => array( self::MIN_DOCUMENTS, self::MAX_DOCUMENTS ),
			self::TASKS_KEY     => array( self::MIN_TASKS, self::MAX_TASKS ),
		);
		if ( ! isset( $limits[ $key ] ) || ( ! is_int( $value ) && ! is_string( $value ) ) ) {
			return null;
		}
		$text = (string) $value;
		$max  = (string) $limits[ $key ][1];
		if ( '' === $text || 1 !== preg_match( '/^[0-9]+$/D', $text ) ||
			strlen( $text ) > strlen( $max ) ||
			( strlen( $text ) === strlen( $max ) && strcmp( $text, $max ) > 0 ) ) {
			return null;
		}
		$number = (int) $text;
		return $number >= $limits[ $key ][0] && $number <= $limits[ $key ][1] ? $number : null;
	}

	/** @return void */
	private function settings_error() {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'wpai_workspace_count_invalid',
				__( 'Workspace record quotas must be whole numbers in the shown ranges. Invalid values were not saved.', 'wp-ai-bridge' )
			);
		}
	}
}
