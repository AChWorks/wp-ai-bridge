<?php
/**
 * Internal-only, real WordPress private multipart inbound byte staging.
 *
 * This does not register an external MCP/REST file upload API or consume an
 * archive/import. All transfers here exist only on disposable test WordPress.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Transfer_Session;
use WP_AI_Bridge\Support\Settings;

/** @param bool $okay Assertion. @param string $why Failure reason. @return void */
function wpai134_staging_assert( $okay, $why ) {
	if ( ! $okay ) {
		throw new RuntimeException( $why );
	}
}

$actor       = get_current_user_id();
$settings    = new Settings();
$original    = get_option( Settings::OPTION_NAME, false );
$permissions = new Permissions( $settings );
$transfer    = new Private_Transfer_Session( $permissions );
$ids         = array();
$other_user  = 0;

try {
	wpai134_staging_assert( $actor > 0, 'A native WordPress administrator is required.' );
	$grant = $settings->defaults();
	update_option( Settings::OPTION_NAME, $grant, false );
	wpai134_staging_assert(
		is_wp_error( $transfer->begin( 'media', 0, hash( 'sha256', '' ), 1024, 512, 2, 600 ) ),
		'Private ingress was allowed without Builder Write.'
	);
	$grant[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grant, false );

	// PNG and ZIP-like *opaque* binary: the state owner never interprets data.
	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=', true );
	wpai134_staging_assert( is_string( $png ) && strlen( $png ) === 68, 'Missing tiny binary source.' );
	$open = $transfer->begin( 'media', strlen( $png ), hash( 'sha256', $png ), 4096, 32, 3, 600 );
	wpai134_staging_assert( ! is_wp_error( $open ) && 'open' === $open['state'] && 0 === $open['received'], 'Private source allocation did not return an opaque initial receipt.' );
	$id    = $open['id'];
	$ids[] = $id;
	// A second real native WordPress administrator has the same grant but
	// MUST NOT inherit a different principal's opaque private transfer.
	$other_user = wp_insert_user(
		array(
			'user_login' => 'wpai134_other_' . bin2hex( random_bytes( 5 ) ),
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'other-' . bin2hex( random_bytes( 4 ) ) . '@example.invalid',
			'role'       => 'administrator',
		)
	);
	wpai134_staging_assert( is_int( $other_user ) && $other_user > 0, 'Could not create isolated second administrator.' );
	wp_set_current_user( $other_user );
	wpai134_staging_assert(
		current_user_can( 'upload_files' ) &&
		is_wp_error( $transfer->status( $id ) ) &&
		is_wp_error( $transfer->append( $id, 0, substr( $png, 0, 12 ) ) ) &&
		is_wp_error( $transfer->cancel( $id ) ),
		'An equally privileged but unrelated administrator could use the opaque transfer.'
	);
	wp_set_current_user( $actor );

	wpai134_staging_assert( is_wp_error( $transfer->verify( $id ) ), 'Incomplete binary was prematurely verified.' );
	$first = new Private_Transfer_Session( $permissions );
	$a     = $first->append( $id, 0, substr( $png, 0, 32 ) );
	wpai134_staging_assert( ! is_wp_error( $a ) && 32 === $a['received'], 'First private byte chunk was not persisted.' );
	$retry = $transfer->append( $id, 0, substr( $png, 0, 32 ) );
	wpai134_staging_assert( ! is_wp_error( $retry ) && 32 === $retry['received'], 'Identical retried segment was not idempotent.' );
	$conflict = $transfer->append( $id, 0, str_repeat( '?', 32 ) );
	wpai134_staging_assert( is_wp_error( $conflict ) && 'transfer_conflict' === $conflict->get_error_code(), 'Changed retried bytes were silently overwritten.' );
	wpai134_staging_assert( is_wp_error( $transfer->append( $id, 44, 'future bytes' ) ), 'Out-of-order segment was accepted.' );
	wpai134_staging_assert( is_wp_error( $transfer->append( $id, 32, str_repeat( 'Z', 33 ) ) ), 'Segment larger than source-window budget was accepted.' );
	$b = $first->append( $id, 32, substr( $png, 32, 32 ) );
	$c = $transfer->append( $id, 64, substr( $png, 64 ) );
	wpai134_staging_assert( ! is_wp_error( $b ) && ! is_wp_error( $c ) && 68 === $c['received'] && $c['complete'], 'Final binary byte or continuation was lost.' );

	// Same opaque receipt must not confer authority if native grants change.
	$grant[ Settings::GROUP_BUILDER_WRITE ] = 0;
	update_option( Settings::OPTION_NAME, $grant, false );
	wpai134_staging_assert( is_wp_error( $transfer->status( $id ) ) && is_wp_error( $transfer->verify( $id ) ), 'Revoked grant continued to expose private source.' );
	wp_set_current_user( 0 );
	wpai134_staging_assert( is_wp_error( $transfer->status( $id ) ), 'Anonymous access to private transfer was allowed.' );
	wp_set_current_user( $actor );
	$grant[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $grant, false );
	$verified = $transfer->verify( $id );
	wpai134_staging_assert( ! is_wp_error( $verified ) && 'verified' === $verified['state'], 'Completed binary was not SHA-256 verified.' );
	$private = $transfer->verified_private_source( $id, 'media' );
	wpai134_staging_assert(
		! is_wp_error( $private ) && hash_file( 'sha256', $private['path'] ) === hash( 'sha256', $png ) &&
		file_get_contents( $private['path'] ) === $png &&
		0600 === ( fileperms( $private['path'] ) & 0777 ) &&
		false === strpos( wp_normalize_path( $private['path'] ), trailingslashit( wp_normalize_path( ABSPATH ) ) ),
		'Privately staged source altered its bytes, owner-only mode, or storage confinement.'
	);
	wpai134_staging_assert( is_wp_error( $transfer->verified_private_source( $id, 'wxr' ) ), 'A Media lease was converted into an unauthorized WXR consumer.' );
	wpai134_staging_assert( is_wp_error( $transfer->append( $id, 0, substr( $png, 0, 1 ) ) ), 'Appending after verification was allowed.' );
	// Even a privileged WordPress delete_file filter can veto cleanup. A
	// canceled lease must NEVER report success before deletion is verified.
	$refuse_delete = static function ( $path ) use ( $private ) {
		return $path === $private['path'] ? false : $path;
	};
	add_filter( 'wp_delete_file', $refuse_delete );
	$blocked_cleanup = $transfer->cancel( $id );
	wpai134_staging_assert(
		is_wp_error( $blocked_cleanup ) && 'transfer_cleanup_uncertain' === $blocked_cleanup->get_error_code() &&
		file_exists( $private['path'] ),
		'Private cleanup falsely confirmed a blocked disk deletion.'
	);
	remove_filter( 'wp_delete_file', $refuse_delete );
	wpai134_staging_assert( true === $transfer->cancel( $id ) && ! file_exists( $private['path'] ), 'Cancel left confidential staged bytes on disk.' );
	wpai134_staging_assert( is_wp_error( $transfer->status( $id ) ), 'Canceled lease remained accessible.' );
	$ids = array();

	// Zero length is a valid file; no artificial minimum on source bytes.
	$zero = $transfer->begin( 'media', 0, hash( 'sha256', '' ), 4096, 512, 3, 600 );
	wpai134_staging_assert( ! is_wp_error( $zero ), 'Zero-length private source was disallowed.' );
	$ids[]    = $zero['id'];
	$zero_ver = $transfer->verify( $zero['id'] );
	wpai134_staging_assert( ! is_wp_error( $zero_ver ) && $zero_ver['complete'] && 'verified' === $zero_ver['state'], 'Empty-byte SHA-256 could not be verified.' );
	$zero_file = $transfer->verified_private_source( $zero['id'], 'media' );
	wpai134_staging_assert( ! is_wp_error( $zero_file ) && 0 === filesize( $zero_file['path'] ), 'Verified empty source was not exactly empty.' );
	wpai134_staging_assert( true === $transfer->cancel( $zero['id'] ), 'Zero-length cleanup failed.' );
	$ids = array();

	// Large Unicode-rich opaque data verifies byte-exact without one giant
	// JSON request. This internal component does not create/alter WP posts.
	$long = str_repeat( "سلام 🌍 WordPress \n", 85000 );
	wpai134_staging_assert( strlen( $long ) > 1024 * 1024 && 1 === preg_match( '//u', $long ), 'Multilingual multi-MiB fixture did not initialize.' );
	$sha   = hash( 'sha256', $long );
	$large = $transfer->begin( 'text', strlen( $long ), $sha, 8 * 1024 * 1024, 80 * 1024, 3, 600 );
	wpai134_staging_assert( ! is_wp_error( $large ), 'Large text transfer allocation failed.' );
	$ids[]  = $large['id'];
	$offset = 0;
	$steps  = 0;
	while ( $offset < strlen( $long ) && $steps < 60 ) {
		$part = substr( $long, $offset, 70 * 1024 );
		$next = $transfer->append( $large['id'], $offset, $part );
		wpai134_staging_assert( ! is_wp_error( $next ) && $next['received'] === $offset + strlen( $part ), 'UTF-8 byte offsets drifted during private stage.' );
		$offset = $next['received'];
		++$steps;
	}
	wpai134_staging_assert( strlen( $long ) === $offset, 'Private multi-request UTF-8 stage did not complete.' );
	$large_verified = $transfer->verify( $large['id'] );
	$large_file     = $transfer->verified_private_source( $large['id'], 'text' );
	wpai134_staging_assert(
		! is_wp_error( $large_verified ) && ! is_wp_error( $large_file ) &&
		sha1_file( $large_file['path'] ) === sha1( $long ) &&
		hash_file( 'sha256', $large_file['path'] ) === $sha &&
		file_get_contents( $large_file['path'] ) === $long,
		'Multi-megabyte Persian/emoji exact-byte reconstruction failed.'
	);

	// A privileged local change after verification must invalidate the
	// immutable content digest rather than silently reusing a stale receipt.
	$changed_bytes = 'X' . substr( $long, 1 );
	wpai134_staging_assert(
		strlen( $changed_bytes ) === strlen( $long ) &&
		strlen( $changed_bytes ) === file_put_contents( $large_file['path'], $changed_bytes ),
		'Could not exercise post-verification source mutation.'
	);
	$changed = $transfer->verified_private_source( $large['id'], 'text' );
	wpai134_staging_assert(
		is_wp_error( $changed ) &&
		'transfer_source_changed' === $changed->get_error_code(),
		'An altered verified private source was accepted as original content.'
	);
	wpai134_staging_assert(
		strlen( $long ) === file_put_contents( $large_file['path'], $long ) &&
		! is_wp_error( $transfer->verified_private_source( $large['id'], 'text' ) ),
		'Restored exact private bytes did not regain SHA-256 integrity.'
	);


	// Full length alone is not sufficient without correct digest.
	$bad = $transfer->begin( 'media', 4, str_repeat( '0', 64 ), 8 * 1024 * 1024, 100, 3, 600 );
	wpai134_staging_assert( ! is_wp_error( $bad ), 'Corrupt-hash fixture could not begin.' );
	$ids[] = $bad['id'];
	wpai134_staging_assert( ! is_wp_error( $transfer->append( $bad['id'], 0, 'abcd' ) ), 'Incorrect-hash fixture staging unexpectedly refused raw bytes.' );
	$bad_result = $transfer->verify( $bad['id'] );
	wpai134_staging_assert( is_wp_error( $bad_result ) && 'transfer_checksum_mismatch' === $bad_result->get_error_code(), 'Checksum mismatch was not caught before consumer handoff.' );

	// Reservation quota applies before side effects; changing effective
	// limit may affect further admissions without destroying old receipts.
	$cap = $transfer->begin( 'media', 1, hash( 'sha256', 'a' ), 8 * 1024 * 1024, 512, 2, 600 );
	wpai134_staging_assert( is_wp_error( $cap ) && 'transfer_capacity' === $cap->get_error_code(), 'Per-site active lease cap was bypassed.' );

	// Wrong privileges must not see the staged artifact, even if it knows
	// the opaque ID and source hash. No client path is provided in receipts.
	$grant[ Settings::GROUP_BUILDER_WRITE ] = 0;
	update_option( Settings::OPTION_NAME, $grant, false );
	wpai134_staging_assert( is_wp_error( $transfer->verified_private_source( $large['id'], 'text' ) ), 'Revoked transfer continued to provide a private path.' );

	echo sprintf(
		"MEASURE: #134 internal_private_stage_unicode_bytes=%d windows=%d, SHA-256 exact, zero-byte + PNG + conflict + revocation + capacity.\n",
		strlen( $long ),
		$steps
	);
	echo "PASS: #134 real WordPress internal-only resumable private binary/text staging, fail-closed leases and byte integrity.\n";
} finally {
	wp_set_current_user( $actor );
	if ( is_int( $other_user ) && $other_user > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $other_user );
	}
	$restore                                  = $settings->defaults();
	$restore[ Settings::GROUP_BUILDER_WRITE ] = 1;
	update_option( Settings::OPTION_NAME, $restore, false );
	foreach ( $ids as $id ) {
		$transfer->cancel( $id );
	}
	if ( false === $original ) {
		delete_option( Settings::OPTION_NAME );
	} else {
		update_option( Settings::OPTION_NAME, $original, false );
	}
}
