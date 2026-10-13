<?php
/**
 * Private bounded incremental WordPress WXR export producer proof.
 *
 * Only disposable WP-CLI and synthetic content. Not a public export tool.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Artifact_Writer;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\WXR_Export_Stager;

function wpai139_stage_assert( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( $reason );
	}
}

$original_user   = get_current_user_id();
$settings        = new Settings();
$original_grants = get_option( Settings::OPTION_NAME, $settings->defaults() );
$type            = 'wpai139_stream';
$private_type    = 'wpai139_unexportable';
$post_id         = 0;
$path            = '';
$marker          = 'wpai139-stream-' . bin2hex( random_bytes( 8 ) );
$private_paths   = static function () {
	$found = glob( trailingslashit( get_temp_dir() ) . 'wpai-private-artifact*' );
	return is_array( $found ) ? $found : array();
};

try {
	wpai139_stage_assert( $original_user > 0 && current_user_can( 'export' ), 'Real native export authority is necessary for isolated fixture.' );
	$producer = new WXR_Export_Stager( new Permissions( $settings ) );
	$before   = $private_paths();
	$binary   = Private_Artifact_Writer::begin( 16 );
	wpai139_stage_assert( ! is_wp_error( $binary ), 'Internal private artifact writer could not allocate safe staging.' );
	wpai139_stage_assert(
		true === $binary->append( chr( 0 ) . 'Binary' . "\n" ) &&
		is_wp_error( $binary->append( str_repeat( 'x', 32 ) ) ),
		'Opaque binary writer did not reject bytes exceeding the negotiated budget.'
	);
	$binary->abort();
	wpai139_stage_assert( $before === $private_paths(), 'Aborted private artifact remained on the filesystem.' );

	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	$disabled = $producer->stage( 'post', 2048 );
	wpai139_stage_assert( is_wp_error( $disabled ) && 'wxr_stage_denied' === $disabled->get_error_code(), 'Default-off Bridge group did not block WXR staging.' );

	$grants                                = $settings->defaults();
	$grants[ Settings::GROUP_SITE_CONFIG ] = 1;
	update_option( Settings::OPTION_NAME, $grants, false );

	register_post_type(
		$type,
		array(
			'public'     => true,
			'can_export' => true,
			'label'      => 'Private WXR staging test',
		)
	);
	register_post_type(
		$private_type,
		array(
			'public'     => false,
			'can_export' => false,
		)
	);
	wpai139_stage_assert(
		is_wp_error( $producer->stage( $private_type, 1024 * 1024 ) ) &&
		is_wp_error( $producer->stage( 'wpai139_fake', 1024 * 1024 ) ),
		'Core fallback to a different post type or unsupported target was not prevented.'
	);
	wpai139_stage_assert(
		is_wp_error( $producer->stage( $type, 0 ) ) &&
		is_wp_error( $producer->stage( array( 'all' ), 3000 ) ),
		'Malformed WXR staging budget or type passed validation.'
	);

	// A real selected CPT with roughly 1.5 MiB of Persian UTF-8 source text.
	$content = str_repeat( "سلام WordPress / 🌍\n", 70000 ) . $marker;
	$post_id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_title'   => 'Synthetic bounded native WXR',
			'post_status'  => 'publish',
			'post_content' => wp_slash( $content ),
		),
		true
	);
	wpai139_stage_assert( ! is_wp_error( $post_id ) && (int) $post_id > 0, 'Could not create large native WXR source.' );

	$start      = microtime( true );
	$result     = $producer->stage( $type, 4 * 1024 * 1024 );
	$elapsed_ms = ( microtime( true ) - $start ) * 1000;
	wpai139_stage_assert( ! is_wp_error( $result ), 'Core streaming staging failed with sufficient bounded private quota.' );
	$path = $result['path'];
	wpai139_stage_assert(
		(int) $result['bytes'] > 1024 * 1024 &&
		(int) $result['bytes'] < 4 * 1024 * 1024 &&
		filesize( $path ) === (int) $result['bytes'] &&
		hash_file( 'sha256', $path ) === (string) $result['sha256'] &&
		get_current_blog_id() === (int) $result['blog_id'] &&
		'application/xml' === $result['mime'] &&
		0600 === ( fileperms( $path ) & 0777 ),
		'Private Core WXR staging lost length/hash/site/type/MIME/permissions.'
	);
	$xml = file_get_contents( $path );
	wpai139_stage_assert(
		is_string( $xml ) &&
		false !== strpos( $xml, $marker ) &&
		false !== strpos( $xml, '<rss' ) &&
		false !== strpos( $xml, 'http://wordpress.org/export/1.2/' ),
		'Incrementally staged WXR was incomplete or lacked required native identity.'
	);
	unset( $xml, $content );

	$repeated = $producer->stage( $type, 4 * 1024 * 1024 );
	wpai139_stage_assert(
		is_wp_error( $repeated ) && 'wxr_stage_request_exhausted' === $repeated->get_error_code(),
		'Second Core export attempt in one PHP request was not safely rejected.'
	);
	wp_set_current_user( 0 );
	wpai139_stage_assert(
		is_wp_error( $producer->stage( $type, 4096 ) ),
		'Anonymous caller could stage protected WXR content.'
	);
	wp_set_current_user( $original_user );
	$grants[ Settings::GROUP_SITE_CONFIG ] = 0;
	update_option( Settings::OPTION_NAME, $grants, false );
	wpai139_stage_assert(
		is_wp_error( $producer->stage( $type, 4096 ) ),
		'Revoked Bridge grant could still stage protected WXR.'
	);

	echo sprintf(
		"MEASURE: #139 staged_wxr_bytes=%d stage_ms=%.2f php_peak_bytes=%d.\n",
		$result['bytes'],
		$elapsed_ms,
		memory_get_peak_usage( true )
	);
	echo "PASS: #139 private bounded incremental Core WXR export, SHA-256, cleanup and authorization.\n";
} finally {
	wp_set_current_user( $original_user );
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	if ( '' !== $path ) {
		wp_delete_file( $path );
	}
	unregister_post_type( $type );
	unregister_post_type( $private_type );
	if ( false === $original_grants ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original_grants, false );
	}
}
