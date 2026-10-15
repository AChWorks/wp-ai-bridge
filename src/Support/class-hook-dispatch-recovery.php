<?php
/**
 * Exception-safe WordPress hook boundary for one destructive Workspace Ability.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * WordPress WP_Hook does not unwind its iteration state in finally blocks.
 * WordPress 7.1+ may catch Ability callback exceptions and CONTINUE the request.
 * This boundary restores exact hook state BEFORE an exception can be caught.
 *
 * On unprovable recovery, there is no safe WP_Error/Throwable fallback:
 * terminate the current request without calling more WordPress hooks.
 */
final class Hook_Dispatch_Recovery {
	/** @var array<int,object> Active nested scopes needing identity rebasing. */
	private static $active_scopes = array();

	/**
	 * Capture the visible WordPress hook stack and the hidden 'all' identity.
	 *
	 * @return object Synchronous, request-local snapshot.
	 */
	public static function snapshot() {
		global $wp_filter, $wp_current_filter;
		$all = isset( $wp_filter['all'] ) ? $wp_filter['all'] : null;
		return (object) array(
			'stack'      => $wp_current_filter ?? array(),
			'all'        => $all,
			'all_active' => $all instanceof \WP_Hook && false !== $all->current_priority(),
		);
	}

	/**
	 * Wrap a hook-capable operation that may throw into WP_Ability or a
	 * caller-owned catch-and-continue. Recovered provider exceptions retain
	 * their original object and semantics.
	 *
	 * @param callable $operation Synchronous operation.
	 * @return mixed Operation result.
	 */
	public static function run( $operation ) {
		$baseline              = self::snapshot();
		self::$active_scopes[] = $baseline;
		try {
			return $operation();
		} catch ( \Throwable $exception ) {
			self::restore_or_terminate( $baseline );
			throw $exception;
		} finally {
			array_pop( self::$active_scopes );
		}
	}

	/**
	 * Restore an escaped dispatcher state or terminate before ANY callback
	 * framework (including WP_Ability::invoke_callback()) can resume.
	 *
	 * @param object  $baseline Snapshot captured before operation.
	 * @param ?string             $owned_hook If set, require only this exact
	 *                                        owned native sentinel frame escaped.
	 * @return void
	 */
	public static function restore_or_terminate( $baseline, $owned_hook = null ) {
		if ( ! self::recover( $baseline, $owned_hook ) ) {
			self::terminate_unsafe_request();
		}
	}

	/**
	 * Reconstruct escaped *named* hooks and the invisible universal 'all'.
	 * Preserve callback identity, priority, accepted args, insertion order.
	 * Never replace a hook that could still be executing in an outer frame.
	 *
	 * @param object  $baseline Original per-operation snapshot.
	 * @param ?string             $owned_hook Optional exact sentinel hook.
	 * @return bool Whether complete recovery was proven and committed.
	 */
	private static function recover( $baseline, $owned_hook ) {
		global $wp_filter, $wp_current_filter;
		if ( ! is_object( $baseline ) || ! isset( $baseline->stack ) ||
			! is_array( $baseline->stack ) ||
			! is_array( $wp_current_filter ) ||
			count( $wp_current_filter ) < count( $baseline->stack ) ||
			array_slice( $wp_current_filter, 0, count( $baseline->stack ) ) !== $baseline->stack ) {
			return false;
		}
		$escaped = array_slice( $wp_current_filter, count( $baseline->stack ) );
		if ( 16 < count( $escaped ) ||
			( null !== $owned_hook && array( $owned_hook ) !== $escaped ) ) {
			return false;
		}
		foreach ( $escaped as $hook ) {
			if ( ! is_string( $hook ) || '' === $hook ||
				in_array( $hook, $baseline->stack, true ) ) {
				return false;
			}
		}
		$original_all = $baseline->all ?? null;
		$current_all  = isset( $wp_filter['all'] ) ? $wp_filter['all'] : null;
		// A provider replacing/removing the dispatcher we snapshotted
		// cannot be treated as a complete, provably recovered transaction.
		if ( null !== $original_all && $current_all !== $original_all ) {
			return false;
		}
		// 'all' runs invisibly in wp_current_filter. If it was active in
		// our OUTER frame, a nested failure cannot safely reconstruct it.
		if ( ! empty( $baseline->all_active ) && $escaped ) {
			return false;
		}
		$affected = array_values( array_unique( $escaped ) );
		if ( null !== $current_all && empty( $baseline->all_active ) ) {
			$affected[] = 'all';
		}

		$replacements = array();
		try {
			foreach ( array_unique( $affected ) as $hook ) {
				if ( ! isset( $wp_filter[ $hook ] ) ||
					! is_object( $wp_filter[ $hook ] ) ||
					get_class( $wp_filter[ $hook ] ) !== \WP_Hook::class ) {
					return false;
				}
				$previous = $wp_filter[ $hook ];
				$fresh    = new \WP_Hook();
				foreach ( $previous->callbacks as $priority => $callbacks ) {
					if ( ! is_int( $priority ) || ! is_array( $callbacks ) ) {
						return false;
					}
					foreach ( $callbacks as $callback ) {
						if ( ! is_array( $callback ) ||
							! array_key_exists( 'function', $callback ) ||
							! isset( $callback['accepted_args'] ) ||
							! is_int( $callback['accepted_args'] ) ) {
							return false;
						}
						$fresh->add_filter( $hook, $callback['function'], $priority, $callback['accepted_args'] );
					}
				}
				if ( $fresh->callbacks !== $previous->callbacks ) {
					return false;
				}
				$replacements[ $hook ] = array(
					'previous' => $previous,
					'fresh'    => $fresh,
				);
			}
		} catch ( \Throwable $exception ) {
			return false;
		}
		foreach ( $replacements as $hook => $pair ) {
			if ( ! isset( $wp_filter[ $hook ] ) || $wp_filter[ $hook ] !== $pair['previous'] ) {
				return false;
			}
		}
		foreach ( $replacements as $hook => $pair ) {
			$wp_filter[ $hook ] = $pair['fresh'];
		}
		// Nested exception boundaries remain active while a leaf scope
		// repairs the universal dispatcher. Rebind their proven original
		// identity to the equally proven new dispatcher, otherwise a later
		// enclosing catch could mistake our own repair for a foreign swap.
		if ( isset( $replacements['all'] ) ) {
			foreach ( self::$active_scopes as $active ) {
				if ( $active->all === $replacements['all']['previous'] && ! $active->all_active ) {
					$active->all = $replacements['all']['fresh'];
				}
			}
		}
		$wp_current_filter = $baseline->stack;
		return true;
	}

	/**
	 * An unprovable WordPress dispatcher CANNOT flow back to WP_Ability:
	 * on WordPress 7.1+ a Throwable is caught and turned into a normal
	 * WP_Error, continuing the same poisoned PHP request.
	 *
	 * No WordPress functions, filters, error formatters or callback-capable
	 * error handlers are invoked here. Exit status 70 also permits a real
	 * isolated WP-CLI subprocess to prove that no continuation occurred.
	 *
	 * @return never
	 */
	private static function terminate_unsafe_request() {
		if ( ! headers_sent() ) {
			http_response_code( 500 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
		}
		echo 'WP AI Bridge: unsafe WordPress hook dispatcher; request stopped.';
		exit( 70 );
	}
}
