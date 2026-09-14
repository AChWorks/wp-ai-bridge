<?php
/**
 * Static architecture guard for Issue #44 direct registration-return provenance.
 *
 * @package WP_Native_Builder_Bridge
 */

$root     = dirname( __DIR__ );
$failures = array();
$checked  = 0;

$ability_files = glob( $root . '/src/Abilities/class-*-abilities.php' );
$ability_files[] = $root . '/src/Abilities/class-registrar.php';

foreach ( $ability_files as $file ) {
	$lines = file( $file, FILE_IGNORE_NEW_LINES );
	if ( false === $lines ) {
		$failures[] = 'Could not read ' . $file;
		continue;
	}

	foreach ( $lines as $number => $line ) {
		if ( false === strpos( $line, 'wp_register_ability(' ) ) {
			continue;
		}
		++$checked;
		if ( false === strpos( $line, '$registered[] = wp_register_ability(' ) ) {
			$failures[] = sprintf(
				'%s:%d registers an Ability without collecting the exact Core return object.',
				str_replace( $root . '/', '', $file ),
				$number + 1
			);
		}
	}
}

if ( 0 === $checked ) {
	$failures[] = 'No Bridge Ability registrations were found.';
}

$delegation_source = file_get_contents( $root . '/src/Support/class-native-ability-delegation.php' );
$registrar_source  = file_get_contents( $root . '/src/Abilities/class-registrar.php' );
$plugin_source     = file_get_contents( $root . '/src/class-plugin.php' );

if ( false === $delegation_source || false === $registrar_source || false === $plugin_source ) {
	$failures[] = 'Could not read direct-return provenance production sources.';
} else {
	$forbidden = array(
		'capture_bridge_registrations',
		'bridge_callback_owners',
		'bridge_registration_depth',
		'pending_bridge_ability_names',
		'trusted_bridge_callback_owners',
		'wp_ai_bridge_owned',
	);
	$production = $delegation_source . "\n" . $registrar_source . "\n" . $plugin_source;
	foreach ( $forbidden as $identifier ) {
		if ( false !== strpos( $production, $identifier ) ) {
			$failures[] = 'Obsolete ownership-inference mechanism reintroduced: ' . $identifier;
		}
	}

	if ( false === strpos( $registrar_source, 'remember_bridge_abilities' ) ) {
		$failures[] = 'Registrar no longer forwards direct Core registration objects to provenance.';
	}
	if ( false === strpos( $delegation_source, 'SplObjectStorage' ) ) {
		$failures[] = 'Delegation no longer uses exact object-identity provenance.';
	}
	if ( false === strpos( $plugin_source, "array( \$this->registrar, 'register_abilities' )" ) ) {
		$failures[] = 'Plugin no longer delegates Bridge Ability registration directly to Registrar.';
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo 'PASS: Issue #44 direct-return provenance architecture guard (' . $checked . " registrations).\n";
