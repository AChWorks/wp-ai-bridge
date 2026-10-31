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
$project = getenv( 'WPAI209_COMPOSE_PROJECT' );
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'WPAI209_DISPOSABLE_TEST' ) ||
	! is_string( $project ) || ! str_starts_with( $project, 'wpai-integration-' ) || ! is_file( '/.dockerenv' ) ) {
	fwrite( STDERR, "Issue 209 fixture preparation requires the owned disposable WordPress Docker project.\n" );
	exit( 1 );
}
$extra = getenv( 'WORDPRESS_CONFIG_EXTRA' );
if ( false !== $extra && '' !== $extra ) {
	fwrite( STDERR, "Refusing to remove an active Docker extra-configuration extension.\n" );
	exit( 1 );
}
$path = '/var/www/html/wp-config.php';
if ( ! is_file( $path ) || is_link( $path ) || realpath( $path ) !== $path ||
	realpath( dirname( $path ) ) !== dirname( $path ) ) {
	fwrite( STDERR, "The disposable WordPress configuration is not a proven regular local file.\n" );
	exit( 1 );
}
$original = @file_get_contents( $path );
if ( false === $original ) {
	fwrite( STDERR, "The disposable WordPress configuration is unreadable.\n" );
	exit( 1 );
}
// Earlier Issue #199 integration tests installed this exact false definition.
// Prove test ownership and exactly one false declaration before removal.
// An intentional false on any hosted WordPress is NEVER auto-overridden.
$owner_pattern = '/^[ \t]*define\(\h*([\x27\x22])WPAI_ADMIN13_DISPOSABLE\1\h*,\h*true\h*\)\h*;\h*$/m';
$false_pattern = '/^[ \t]*define\(\h*([\x27\x22])WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL\1\h*,\h*false\h*\)\h*;\h*(?:\R|$)/m';
if ( 1 !== preg_match_all( $owner_pattern, $original ) || 1 !== preg_match_all( $false_pattern, $original ) ) {
	fwrite( STDERR, "Expected one test-owned true marker and one prior test-owned false SQL definition.\n" );
	exit( 1 );
}
$pattern = '/^if \(\$configExtra = getenv_docker\(\x27WORDPRESS_CONFIG_EXTRA\x27, \x27\x27\)\) \{\R[\t ]*eval\(\$configExtra\);\R\}\R?/m';
if ( 1 !== preg_match_all( $pattern, $original ) ) {
	fwrite( STDERR, "Expected exactly one inactive Docker eval block; no fixture was changed.\n" );
	exit( 1 );
}
$replacement = preg_replace( $pattern, "// Issue #209 disposable fixture: inactive Docker eval extension omitted.\n", $original, 1 );
$replacement = is_string( $replacement ) ? preg_replace( $false_pattern, '', $replacement, 1 ) : null;
if ( ! is_string( $replacement ) || $replacement === $original ||
	str_contains( $replacement, 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL' ) ||
	1 !== preg_match_all( $owner_pattern, $replacement ) ) {
	fwrite( STDERR, "Could not prepare a verified test-owned static configuration without the prior false policy.\n" );
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
echo "WPAI209_FALSE_REMOVED=1\nWPAI209_FIXTURE_READY=1\n";