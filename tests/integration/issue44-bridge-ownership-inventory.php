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

$foreign = array(
	'wp-native-builder/foreign-fixture'       => true,
	'wp-native-builder/forged-class-fixture' => true,
);
$seen    = array();
$checked = 0;
$page    = 1;

while ( true ) {
	$result = $catalog->execute(
		array(
			'action'    => 'list',
			'namespace' => 'wp-native-builder',
			'page'      => $page,
			'per_page'  => 50,
		)
	);
	if ( is_wp_error( $result ) || ! is_array( $result ) ) {
		throw new RuntimeException( 'Bridge Ability catalog could not be inspected for ownership provenance.' );
	}

	foreach ( $result['items'] as $item ) {
		$name       = isset( $item['name'] ) ? (string) $item['name'] : '';
		$delegation = isset( $item['bridge_delegation'] ) ? (string) $item['bridge_delegation'] : '';
		if ( '' === $name || isset( $seen[ $name ] ) ) {
			throw new RuntimeException( 'Ability ownership inventory contained an empty or duplicate name.' );
		}
		$seen[ $name ] = true;

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

	$total_pages = isset( $result['total_pages'] ) ? (int) $result['total_pages'] : 0;
	if ( $page >= $total_pages ) {
		break;
	}
	++$page;
}

if ( $checked < 1 ) {
	throw new RuntimeException( 'No Bridge-owned Abilities were available for ownership inventory validation.' );
}

foreach ( array_keys( $foreign ) as $name ) {
	if ( empty( $seen[ $name ] ) ) {
		throw new RuntimeException( 'Foreign ownership-forgery fixture was missing from catalog inventory: ' . $name );
	}
}

if ( count( $seen ) !== (int) ( $result['total'] ?? -1 ) ) {
	throw new RuntimeException( 'Ability ownership inventory did not cover the complete paginated namespace.' );
}

echo 'PASS: Issue #44 complete Bridge ownership provenance inventory (' . $checked . " genuine abilities).\n";
