<?php
/**
 * Live WordPress inventory check for Issue #44 Bridge ownership provenance.
 *
 * @package WP_Native_Builder_Bridge
 */

$catalog = wp_get_ability( 'wp-native-builder/abilities-read' );
if ( ! $catalog instanceof WP_Ability ) {
	throw new RuntimeException( 'Bridge Ability catalog was not registered.' );
}

$result = $catalog->execute(
	array(
		'action'    => 'list',
		'namespace' => 'wp-native-builder',
		'page'      => 1,
		'per_page'  => 100,
	)
);
if ( is_wp_error( $result ) || ! is_array( $result ) ) {
	throw new RuntimeException( 'Bridge Ability catalog could not be inspected for ownership provenance.' );
}

$foreign = array(
	'wp-native-builder/foreign-fixture'      => true,
	'wp-native-builder/forged-class-fixture' => true,
);
$checked = 0;
foreach ( $result['items'] as $item ) {
	$name       = isset( $item['name'] ) ? (string) $item['name'] : '';
	$delegation = isset( $item['bridge_delegation'] ) ? (string) $item['bridge_delegation'] : '';
	if ( isset( $foreign[ $name ] ) ) {
		if ( 'native_abilities' !== $delegation ) {
			throw new RuntimeException( 'Foreign Ability was misclassified as Bridge-owned: ' . $name );
		}
		continue;
	}

	++$checked;
	if ( 'ability_specific' !== $delegation ) {
		throw new RuntimeException( 'Genuine Bridge Ability is missing object-identity provenance: ' . $name );
	}
}

if ( $checked < 1 ) {
	throw new RuntimeException( 'No Bridge-owned Abilities were available for ownership inventory validation.' );
}

foreach ( array_keys( $foreign ) as $name ) {
	$found = false;
	foreach ( $result['items'] as $item ) {
		if ( $name === ( $item['name'] ?? '' ) ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
		throw new RuntimeException( 'Foreign ownership-forgery fixture was missing from catalog inventory: ' . $name );
	}
}

echo 'PASS: Issue #44 Bridge ownership provenance inventory (' . $checked . " abilities).\n";
