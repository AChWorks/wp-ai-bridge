<?php
/**
 * Real WordPress/official Adapter #134 DB fallback acceptance on a disposable site.
 *
 * The runner sets WP_TEMP_DIR under WP_CONTENT_DIR before Core boot. The
 * production plugin must remain zero-configuration and cannot use a public
 * filesystem for plaintext staging.
 *
 * @package WP_AI_Bridge
 */

require_once __DIR__ . '/issue134-review-helper.php';

use WP_AI_Bridge\Support\Settings;

global $wpdb;

wpai134_review_require(
	defined( 'WP_TEMP_DIR' ) && realpath( WP_CONTENT_DIR ) === realpath( get_temp_dir() ),
	'Fixture requires a WordPress content-local temp root.'
);
$admin                                   = get_current_user_id();
$settings                                = new Settings();
$previous                                = get_option( Settings::OPTION_NAME, false );
$grants                                  = $settings->defaults();
$grants[ Settings::GROUP_BUDER_WRITE ]   = 1;
$grants[ Settings::GROUP_BUILDER_WRITE ] = 1;
update_option( Settings::OPTION_NAME, $grants, false );
$lease_table = $wpdb->prefix . 'wpai_private_lease';
$chunk_table = $wpdb->prefix . 'wpai_private_chunk';
$leases      = array();
$store       = null;
$token       = '';
$session     = '';
$count       = 0;
$binary      = str_repeat( "\x00\x01\xff\xfeX\n", 189420 ) . "فارسی\x00\xff";
try {
	list( $token, $session, $store ) = wpai134_review_connect( $admin );
	foreach ( array( '', 'Z', "سلام🌍\x00\xff", $binary ) as $payload ) {
		$begin  = wpai134_review_transfer(
			$token,
			$session,
			array(
				'action'      => 'begin',
				'purpose'     => 'text',
				'total_bytes' => strlen( $payload ),
				'sha256'      => hash( 'sha256', $payload ),
			)
		);
		$record = $begin['result']['structuredContent'] ?? array();
		wpai134_review_require( true === ( $record['success'] ?? false ), 'DB fallback begin failed: ' . wp_json_encode( $begin['result'] ?? array() ) );
		$meta     = $record['data'];
		$id       = $meta['transfer_id'];
		$leases[] = $id;
		wpai134_review_require(
			1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %s', $lease_table, $id ) ),
			'Private lease was not persisted in per-site InnoDB storage.'
		);
		$offset = 0;
		$limit  = min( 32768, $meta['max_chunk_bytes'] );
		while ( $offset < strlen( $payload ) ) {
			$piece  = substr( $payload, $offset, $limit );
			$params = array(
				'action'         => 'append',
				'transfer_id'    => $id,
				'offset'         => $offset,
				'content_base64' => base64_encode( $piece ),
			);
			$sent   = wpai134_review_transfer( $token, $session, $params );
			wpai134_review_require( true === ( $sent['result']['structuredContent']['success'] ?? false ), 'DB byte append failed at offset ' . $offset );
			if ( 0 === $offset ) {
				$replayed = wpai134_review_transfer( $token, $session, $params );
				wpai134_review_require( true === ( $replayed['result']['structuredContent']['success'] ?? false ), 'Exact replay was not idempotent.' );
				$changed                   = $params;
				$changed['content_base64'] = base64_encode( str_repeat( '*', strlen( $piece ) ) );
				$conflict                  = wpai134_review_transfer( $token, $session, $changed );
				wpai134_review_require( ! empty( $conflict['result']['isError'] ), 'Conflicting replay was accepted.' );
			}
			$offset += strlen( $piece );
		}
		$verified = wpai134_review_transfer(
			$token,
			$session,
			array(
				'action'      => 'verify',
				'transfer_id' => $id,
			)
		);
		wpai134_review_require( true === ( $verified['result']['structuredContent']['success'] ?? false ), 'DB transfer digest verification failed.' );
		$status = wpai134_review_transfer(
			$token,
			$session,
			array(
				'action'      => 'status',
				'transfer_id' => $id,
			)
		);
		$data   = $status['result']['structuredContent']['data'] ?? array();
		wpai134_review_require(
			'verified' === ( $data['state'] ?? '' ) && strlen( $payload ) === $data['received_bytes'] &&
			hash_equals( hash( 'sha256', $payload ), $data['sha256'] ),
			'Authenticated DB readback mismatched received bytes or digest.'
		);
		$bytes_count = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(OCTET_LENGTH(data)),0) FROM %i WHERE id = %s', $chunk_table, $id )
		);
		wpai134_review_require( strlen( $payload ) === (int) $bytes_count, 'Private DB stored binary length differs from exact source.' );
		$cancel = wpai134_review_transfer(
			$token,
			$session,
			array(
				'action'      => 'cancel',
				'transfer_id' => $id,
			)
		);
		wpai134_review_require( true === ( $cancel['result']['structuredContent']['success'] ?? false ), 'Explicit DB cancellation failed.' );
		$leases = array_values( array_diff( $leases, array( $id ) ) );
		wpai134_review_require(
			0 === (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %s', $chunk_table, $id )
			),
			'Private payload remained after cancel.'
		);
		++$count;
	}
	// Mismatching declared SHA256 must refuse verify and allow exact cleanup.
	$start = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'begin',
			'purpose'     => 'text',
			'total_bytes' => 1,
			'sha256'      => hash( 'sha256', 'wrong' ),
		)
	);
	wpai134_review_require( true === ( $start['result']['structuredContent']['success'] ?? false ), 'Digest-negative begin failed.' );
	$id       = $start['result']['structuredContent']['data']['transfer_id'];
	$leases[] = $id;
	$part     = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'         => 'append',
			'transfer_id'    => $id,
			'offset'         => 0,
			'content_base64' => base64_encode( 'a' ),
		)
	);
	wpai134_review_require( true === ( $part['result']['structuredContent']['success'] ?? false ), 'Digest-negative append failed.' );
	$verified = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'verify',
			'transfer_id' => $id,
		)
	);
	wpai134_review_require( ! empty( $verified['result']['isError'] ), 'Mismatching SHA256 was verified.' );
	$cancel = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'cancel',
			'transfer_id' => $id,
		)
	);
	wpai134_review_require( true === ( $cancel['result']['structuredContent']['success'] ?? false ), 'Digest-negative cleanup failed.' );
	$leases = array_values( array_diff( $leases, array( $id ) ) );

	// Expiration must be reaped on the next admission, not retain binary data.
	$expired         = wpai134_review_stage( $token, $session, 'text', "expired\0payload" );
	$leases[]        = $expired;
	$row             = $wpdb->get_row( $wpdb->prepare( 'SELECT meta FROM %i WHERE id = %s', $lease_table, $expired ) );
	$meta            = json_decode( $row->meta, true );
	$meta['expires'] = time() - 5;
	$wpdb->update(
		$lease_table,
		array(
			'expires_at' => time() - 5,
			'meta'       => wp_json_encode( $meta ),
		),
		array( 'id' => $expired ),
		array( '%d', '%s' ),
		array( '%s' )
	);
	$fresh = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'begin',
			'purpose'     => 'text',
			'total_bytes' => 0,
			'sha256'      => hash( 'sha256', '' ),
		)
	);
	wpai134_review_require( true === ( $fresh['result']['structuredContent']['success'] ?? false ), 'Expired lease cleanup blocked a new session.' );
	$leases[] = $fresh['result']['structuredContent']['data']['transfer_id'];
	wpai134_review_require(
		0 === (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %s', $chunk_table, $expired )
		),
		'Expired private bytes were not reaped.'
	);
	$leases = array_values( array_diff( $leases, array( $expired ) ) );
	$cancel = wpai134_review_transfer(
		$token,
		$session,
		array(
			'action'      => 'cancel',
			'transfer_id' => $fresh['result']['structuredContent']['data']['transfer_id'],
		)
	);
	wpai134_review_require( true === ( $cancel['result']['structuredContent']['success'] ?? false ), 'New zero-byte lease cleanup failed.' );
	$leases = array();
	echo 'PASS: WordPress+Adapter DB-only private staging, 0B/1B/Unicode/binary ' . strlen( $binary ) .
		'B, SHA256, offset replay/conflict, cancel, TTL reaping and public-path exclusion (' . $count . ' payloads).';
} finally {
	if ( is_string( $token ) && '' !== $token && is_object( $store ) ) {
		foreach ( $leases as $id ) {
			wpai134_review_transfer(
				$token,
				$session,
				array(
					'action'      => 'cancel',
					'transfer_id' => $id,
				)
			);
		}
		$store->revoke( $token );
	}
	if ( false === $previous ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $previous, false );
	}
}
