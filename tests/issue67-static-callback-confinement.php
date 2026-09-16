<?php
/** Dependency-free regressions for external-package callback carrier confinement. */
require __DIR__ . '/../bin/check-external-package-carrier-taint.php';

$assertions = 0;

$accept = static function ( $source, $label ) use ( &$assertions ) {
	try {
		WP_AI_Bridge_External_Package_Carrier_Taint::assert_source( $source );
	} catch ( WP_AI_Bridge_External_Package_Carrier_Taint_Exception $error ) {
		throw new RuntimeException( $label . ' was rejected unexpectedly: ' . $error->getMessage() );
	}
	++$assertions;
};

$reject = static function ( $source, $label ) use ( &$assertions ) {
	try {
		WP_AI_Bridge_External_Package_Carrier_Taint::assert_source( $source );
	} catch ( WP_AI_Bridge_External_Package_Carrier_Taint_Exception $error ) {
		++$assertions;
		return;
	}
	throw new RuntimeException( $label . ' was not rejected.' );
};

$reject(
	"<?php\n\$callbacks = array();\n\$callbacks['cb'] = 'wp_remote_post';\n\$zip = new ZipArchive();\n\$zip->registerProgressCallback( 0.1, \$callbacks['cb'] );\n",
	'Array-offset protected callable carrier'
);

$reject(
	"<?php\n\$holder = new stdClass();\n\$holder->cb = 'wp_remote_request';\n\$zip = new ZipArchive();\n\$zip->registerCancelCallback( \$holder->cb );\n",
	'Object-property protected callable carrier'
);

$reject(
	"<?php\n\$name = 'wp_remote_get';\n\$holder = new stdClass();\n\$holder->cb = \$name;\n\$zip = new ZipArchive();\n\$zip->registerCancelCallback( \$holder->cb );\n",
	'Transitive protected callable carrier'
);

$reject(
	"<?php\n\$name = 'wp_safe_remote_post';\n\$alias = \$name;\n\$callbacks = array();\n\$callbacks['cb'] = \$alias;\n\$zip = new ZipArchive();\n\$zip->registerCancelCallback( \$callbacks['cb'] );\n",
	'Multi-hop protected callable carrier'
);

$accept(
	"<?php\n// wp_remote_post stays inert in comments.\n\$label = 'wp_remote_request';\n\$items = array( new stdClass() );\narray_filter( \$items, 'is_object' );\nfunction_exists( 'wp_tempnam' );\n\$plain = new stdClass();\n\$plain->ordinaryMethod( 'value' );\nPlainType::ordinaryStatic( 'value' );\n",
	'Inert strings/comments and ordinary non-callback calls'
);

$provider_path = __DIR__ . '/../src/Abilities/class-extension-abilities.php';
$provider      = file_get_contents( $provider_path );
if ( false === $provider ) {
	throw new RuntimeException( 'External package provider fixture could not be read.' );
}
$accept( $provider, 'Current external-package provider' );

echo "PASS: Issue #67 static callback carrier confinement ({$assertions} assertions).\n";
