<?php
/** Dependency-free compatibility checks for the extended install input contract. */
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Extension_Abilities;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

wpnb_test_reset_state();
$GLOBALS['wpnb_test']['capabilities']['install_plugins'] = true;
$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = array(
	'code_extensions'   => 1,
	'external_packages' => 1,
);

$settings = new Settings();
$ability  = new Extension_Abilities( new Permissions( $settings ), new Mutation_Log() );

$missing = $ability->mutate(
	array(
		'kind'   => 'plugin',
		'action' => 'install',
	)
);
if ( ! is_wp_error( $missing ) || 'extension_slug_required' !== $missing->get_error_code() ) {
	throw new RuntimeException( 'Legacy missing-slug install error code changed.' );
}

$conflict = $ability->mutate(
	array(
		'kind'        => 'plugin',
		'action'      => 'install',
		'slug'        => 'hello-dolly',
		'package_url' => 'https://packages.example.test/plugin.zip',
	)
);
if ( ! is_wp_error( $conflict ) || 'extension_install_source_required' !== $conflict->get_error_code() ) {
	throw new RuntimeException( 'Ambiguous install-source input was not rejected explicitly.' );
}

echo "PASS: Issue #67 install-source compatibility (2 assertions).\n";
