<?php
/** WP-CLI-only injection at the native directory fsync boundary. @package WP_AI_Bridge */
namespace WP_AI_Bridge\Support;

/** Inject the directory-sync error without touching production PHP. */
function fsync( $stream ) {
	$stat = \fstat( $stream );
	if ( \is_array( $stat ) && 0040000 === ( $stat['mode'] & 0170000 ) ) {
		++$GLOBALS['wpai134_test_dirsync_attempts'];
		if ( ! empty( $GLOBALS['wpai134_test_fail_dirsync'] ) ) {
			return false;
		}
	}
	return \fsync( $stream );
}
