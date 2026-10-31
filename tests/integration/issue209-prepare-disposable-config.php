<?php
/**
 * Normalize the disposable WordPress Docker wp-config.php for Issue #209.
 *
 * Docker's official template has an optional eval(WORDPRESS_CONFIG_EXTRA)
 * extension. The production analyzer correctly rejects ALL eval source.
 * Remove that *inactive* extension only in this isolated Docker named-volume
 * fixture, then prove automatic setup on the otherwise-real WordPress stack.
 * Never run this script against a hosted installation.
 */
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'WPAI209_DISPOSABLE_TEST' ) || ! is_file( '/.dockerenv' ) ) {
	fwrite( STDERR, "Issue 209 fixture preparation is restricted to explicit disposable Docker CLI execution.\n" );
	exit( 1 );
}
$extra = getenv( 'WORDPRESS_CONFIG_EXTRA' );
if ( false !== $extra && '' !== $extra ) {
	fwrite( STDERR, "Refusing to remove an active Docker extra-configuration extension.\n" );
	exit( 1 );
}
$path     = '/var/www/html/wp-config.php';
$original = @file_get_contents( $path );
if ( false === $original ) {
	fwrite( STDERR, "The disposable WordPress configuration is unreadable.\n" );
	exit( 1 );
}
$pattern = '/^if \(\$configExtra = getenv_docker\(\x27WORDPRESS_CONFIG_EXTRA\x27, \x27\x27\)\) \{\R[\t ]*eval\(\$configExtra\);\R\}\R?/m';
if ( 1 !== preg_match_all( $pattern, $original ) ) {
	fwrite( STDERR, "Expected exactly one inactive Docker eval block; no fixture was changed.\n" );
	exit( 1 );
}
$replacement = preg_replace( $pattern, "// Issue #209 disposable fixture: inactive Docker eval extension omitted.\n", $original, 1 );
if ( ! is_string( $replacement ) || $replacement === $original ) {
	fwrite( STDERR, "Could not prepare a deterministic supported config fixture.\n" );
	exit( 1 );
}
try {
	token_get_all( $replacement, TOKEN_PARSE );
} catch ( ParseError $error ) {
	fwrite( STDERR, "The prepared disposable config failed PHP syntax parsing.\n" );
	exit( 1 );
}
if ( strlen( $replacement ) !== @file_put_contents( $path, $replacement, LOCK_EX ) ||
	$replacement !== @file_get_contents( $path ) ) {
	fwrite( STDERR, "The prepared disposable config was not verified on disk.\n" );
	exit( 1 );
}
echo "WPAI209_FIXTURE_READY=1\n";