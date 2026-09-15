<?php
/**
 * Static confinement guard for Issue #58 user/comment metadata persistence.
 *
 * @package WP_Native_Builder_Bridge
 */

$root   = dirname( __DIR__ );
$source = $root . '/src/Support/class-user-comment-meta-store.php';
if ( ! is_file( $source ) ) {
	fwrite( STDERR, "ERROR: bounded user/comment metadata store is missing.\n" );
	exit( 1 );
}

$code = file_get_contents( $source );
if ( false === $code ) {
	fwrite( STDERR, "ERROR: bounded user/comment metadata store could not be read.\n" );
	exit( 1 );
}

if ( preg_match( '/function\s+[A-Za-z_][A-Za-z0-9_]*\s*\([^)]*\$(?:sql|table|column|query|where)(?:[^A-Za-z0-9_]|$)/', $code )
	|| preg_match( '/\$(?:sql|prepared_sql|set_sql|raw_sql)\s*=/', $code ) ) {
	fwrite( STDERR, "ERROR: user/comment metadata store must not accept or assemble SQL fragments.\n" );
	exit( 1 );
}

preg_match_all( '/\$wpdb->([A-Za-z_][A-Za-z0-9_]*)/', $code, $matches );
$allowed_members = array( 'usermeta', 'commentmeta', 'last_error', 'get_results', 'prepare', 'query' );
foreach ( array_unique( $matches[1] ) as $member ) {
	if ( ! in_array( $member, $allowed_members, true ) ) {
		fwrite( STDERR, 'ERROR: user/comment metadata store exceeded its fixed persistence surface: ' . $member . "\n" );
		exit( 1 );
	}
}

$required_counts = array(
	'$wpdb->prepare('                                  => 14,
	'$wpdb->query('                                    => 10,
	'$wpdb->get_results('                              => 2,
	'$wpdb->usermeta'                                  => 7,
	'$wpdb->commentmeta'                               => 7,
	'CAST(meta_key AS BINARY) = CAST(%s AS BINARY)'   => 12,
	'CAST(meta_value AS BINARY) = CAST(%s AS BINARY)' => 6,
	'meta_value IS NULL'                               => 4,
);
foreach ( $required_counts as $needle => $expected ) {
	$actual = substr_count( $code, $needle );
	if ( $actual !== $expected ) {
		fwrite( STDERR, sprintf( "ERROR: user/comment metadata confinement count changed for %s: expected %d, got %d.\n", $needle, $expected, $actual ) );
		exit( 1 );
	}
}

foreach ( array( 'SELECT umeta_id AS meta_id', 'SELECT meta_id, comment_id AS object_id', 'UPDATE {$wpdb->usermeta}', 'UPDATE {$wpdb->commentmeta}', 'DELETE FROM {$wpdb->usermeta}', 'DELETE FROM {$wpdb->commentmeta}' ) as $required_literal ) {
	if ( false === strpos( $code, $required_literal ) ) {
		fwrite( STDERR, 'ERROR: user/comment metadata store lost a fixed persistence template: ' . $required_literal . "\n" );
		exit( 1 );
	}
}

if ( preg_match( '/\b(?:get|add|update|delete)_option\s*\(/', $code ) ) {
	fwrite( STDERR, "ERROR: user/comment metadata persistence must not grow an options surface.\n" );
	exit( 1 );
}

if ( false === strpos( $code, "array( 'user', 'comment' )" ) ) {
	fwrite( STDERR, "ERROR: user/comment metadata store lost its closed object-type boundary.\n" );
	exit( 1 );
}

echo "PASS: Issue #58 user/comment metadata SQL confinement.\n";
