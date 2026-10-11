<?php
/**
 * Internal bounded WordPress-native WXR export producer.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Private WXR exporter; does not register a public Ability or file transport.
 */
final class WXR_Export_Stager {
	const OUTPUT_CHUNK_BYTES = 8192;

	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Existing native/Bridge authorization. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/**
	 * Stage exact Core WXR bytes without capturing the whole XML in memory.
	 *
	 * The explicit finite budget belongs to the future consumer/transport
	 * quota. Core export_wp() supplies actual WXR semantics. A returned
	 * private path must be consumed and deleted by an authorized orchestrator;
	 * it must never be exposed as an MCP result or unrestricted download link.
	 *
	 * @param string $content_type 'all' or a Core exportable post type.
	 * @param int    $max_bytes    Negotiated finite byte budget.
	 * @return array<string,int|string>|WP_Error
	 */
	public function stage( $content_type, $max_bytes ) {
		if (
			! $this->permissions->allowed( Settings::GROUP_SITE_CONFIG, 'export' ) ||
			( is_multisite() && ! current_user_can( 'manage_network_options' ) )
		) {
			return new WP_Error( 'wxr_stage_denied', __( 'Native export and Site Configuration authorization are required.', 'wp-ai-bridge' ) );
		}
		if ( ! is_string( $content_type ) || '' === $content_type ) {
			return new WP_Error( 'wxr_stage_invalid', __( 'Invalid WXR export type or staging budget.', 'wp-ai-bridge' ) );
		}
		if ( 'all' !== $content_type ) {
			$type = get_post_type_object( $content_type );
			if ( ! is_object( $type ) || empty( $type->can_export ) ) {
				return new WP_Error( 'wxr_stage_unsupported', __( 'This WordPress post type is not exportable.', 'wp-ai-bridge' ) );
			}
		}
		// Core declares wxr_cdata() and other helpers globally inside
		// export_wp(). A second export_wp() call in the same PHP request
		// would fatally redeclare them. Never attempt an in-request retry.
		if ( function_exists( 'wxr_cdata' ) ) {
			return new WP_Error( 'wxr_stage_request_exhausted', __( 'Native WXR export must use a new WordPress request for every attempt.', 'wp-ai-bridge' ) );
		}
		if ( headers_sent() ) {
			return new WP_Error( 'wxr_stage_headers', __( 'WXR export must begin before HTTP headers have been sent.', 'wp-ai-bridge' ) );
		}

		$writer = Private_Artifact_Writer::begin( $max_bytes );
		if ( is_wp_error( $writer ) ) {
			return $writer;
		}
		require_once ABSPATH . 'wp-admin/includes/export.php';
		$problem      = '';
		$before_level = ob_get_level();
		$headers      = $this->snapshot_export_headers();

		$callback = static function ( $chunk ) use ( $writer, &$problem ) {
			if ( '' !== $problem ) {
				return '';
			}
			$written = $writer->append( $chunk );
			if ( is_wp_error( $written ) ) {
				$problem = $written->get_error_code();
			}
			// NEVER let Core's WXR XML be emitted into a REST/MCP response.
			return '';
		};

		$started = false;
		try {
			$started = ob_start( $callback, self::OUTPUT_CHUNK_BYTES );
			if ( ! $started ) {
				$problem = 'buffer';
			} else {
				export_wp( array( 'content' => $content_type ) );
			}
		} catch ( \Throwable $error ) {
			// Provider filter/callback exceptions can carry private content.
			$problem = 'producer';
		} finally {
			while ( ob_get_level() > $before_level ) {
				if ( ! ob_end_clean() ) {
					$problem = 'buffer';
					break;
				}
			}
			if ( ob_get_level() !== $before_level ) {
				$problem = 'buffer';
			}
			$this->restore_export_headers( $headers );
		}
		if ( ! $started || '' !== $problem ) {
			$writer->abort();
			$code = 'artifact_too_large' === $problem ? 'wxr_stage_too_large' : 'wxr_stage_failed';
			return new WP_Error( $code, __( 'Native WXR export could not complete within the available private staging budget.', 'wp-ai-bridge' ) );
		}
		$artifact = $writer->finish();
		if ( is_wp_error( $artifact ) ) {
			return new WP_Error( 'wxr_stage_failed', __( 'Native WXR export could not complete within the available private staging budget.', 'wp-ai-bridge' ) );
		}
		if ( 0 === $artifact['bytes'] ) {
			wp_delete_file( $artifact['path'] );
			return new WP_Error( 'wxr_stage_failed', __( 'Native WXR export could not complete within the available private staging budget.', 'wp-ai-bridge' ) );
		}
		return array_merge(
			$artifact,
			array(
				'blog_id' => (int) get_current_blog_id(),
				'type'    => $content_type,
				'mime'    => 'application/xml',
			)
		);
	}

	/** @return array<string,string> */
	private function snapshot_export_headers() {
		$original = array();
		foreach ( headers_list() as $header ) {
			foreach ( array( 'Content-Description', 'Content-Disposition', 'Content-Type' ) as $name ) {
				if ( 0 === stripos( $header, $name . ':' ) ) {
					$original[ $name ] = $header;
				}
			}
		}
		return $original;
	}

	/** @param array<string,string> $previous Original headers to restore. */
	private function restore_export_headers( $previous ) {
		foreach ( array( 'Content-Description', 'Content-Disposition', 'Content-Type' ) as $name ) {
			header_remove( $name );
			if ( isset( $previous[ $name ] ) ) {
				header( $previous[ $name ], true );
			}
		}
	}
}
