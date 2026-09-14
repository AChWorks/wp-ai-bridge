<?php
/**
 * Live WordPress inventory check for Issue #44 Bridge-owned Ability markers.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Native_Ability_Delegation;

$checked = 0;
foreach ( wp_get_abilities() as $ability ) {
	if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
		continue;
	}

	$name = (string) $ability->get_name();
	if ( 0 !== strpos( $name, 'wp-native-builder/' ) ) {
		continue;
	}

	if ( 'wp-native-builder/foreign-fixture' === $name ) {
		if ( Native_Ability_Delegation::is_bridge_owned_ability( $ability ) ) {
			throw new RuntimeException( 'Foreign historical-prefix Ability retained a forged Bridge ownership marker.' );
		}
		continue;
	}

	++$checked;
	if ( ! Native_Ability_Delegation::is_bridge_owned_ability( $ability ) ) {
		throw new RuntimeException( 'Bridge Ability is missing ownership marker: ' . $name );
	}
}

if ( $checked < 1 ) {
	throw new RuntimeException( 'No Bridge-owned Abilities were available for ownership inventory validation.' );
}

echo 'PASS: Issue #44 Bridge ownership inventory (' . $checked . " abilities).\n";
