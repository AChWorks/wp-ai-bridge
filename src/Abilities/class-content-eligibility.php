<?php
/**
 * Generic content eligibility rules.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

/**
 * Keeps generic Builder content coverage broad without exposing administrative records by show_ui alone.
 */
final class Content_Eligibility {
	/**
	 * Resolves a post type that is safe for generic Bridge content operations.
	 *
	 * Posts/pages are explicit core content. Other types must be content-facing through
	 * public/queryable registration or a REST-exposed editor surface.
	 *
	 * @param string $type Post type name.
	 * @return object|null Eligible post-type object, or null.
	 */
	public static function post_type_object( $type ) {
		if ( ! is_string( $type ) || '' === $type || 'attachment' === $type || self::is_workspace_internal_type( $type ) ) {
			return null;
		}

		$obj = get_post_type_object( $type );
		if ( ! $obj || empty( $obj->cap ) ) {
			return null;
		}

		if ( in_array( $type, array( 'post', 'page' ), true ) ) {
			return $obj;
		}

		$content_facing = ! empty( $obj->public ) || ! empty( $obj->publicly_queryable );
		$rest_editor    = ! empty( $obj->show_in_rest )
			&& function_exists( 'post_type_supports' )
			&& post_type_supports( $type, 'editor' );

		return ( $content_facing || $rest_editor ) ? $obj : null;
	}

	/**
	 * Identifies Bridge-owned private Workspace object types.
	 *
	 * Keep this explicit defense-in-depth boundary independent from WordPress
	 * registration flags so future registration changes cannot accidentally make
	 * Workspace memory generic site content.
	 *
	 * @param string $type Post type name.
	 * @return bool
	 */
	public static function is_workspace_internal_type( $type ) {
		return in_array( $type, array( 'wpai_doc', 'wpai_task' ), true );
	}

	/**
	 * Determines whether a generic content type can be targeted by Gutenberg operations.
	 *
	 * @param string $type Post type name.
	 * @return bool Whether the type is generic content with editor support.
	 */
	public static function supports_blocks( $type ) {
		return null !== self::post_type_object( $type )
			&& function_exists( 'post_type_supports' )
			&& post_type_supports( $type, 'editor' );
	}
}
