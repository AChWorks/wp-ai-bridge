<?php
/** Run an exact named integration fixture with finite post-bootstrap PHP memory. */
$fixture = $args[0] ?? '';
if ( ! is_string( $fixture ) || ! preg_match( '/^issue199-[a-z0-9-]+-smoke\.php$/D', $fixture ) || ! is_file( __DIR__ . '/' . $fixture ) ) {
	throw new RuntimeException( 'An exact completion fixture basename is required.' );
}
ini_set( 'memory_limit', '512M' );
if ( '512M' !== ini_get( 'memory_limit' ) ) {
	throw new RuntimeException( 'The completion fixture requires finite 512MiB PHP memory.' );
}
require __DIR__ . '/' . $fixture;
