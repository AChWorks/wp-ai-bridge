<?php
/**
 * Real provider smoke proving Astra contributes native public Abilities without a Bridge duplicate.
 * Safe to run without Astra: reports SKIP.
 *
 * @package WP_AI_Bridge
 */

function wpai_issue4_astra_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$theme = wp_get_theme();
if ( 'astra' !== (string) $theme->get_template() ) {
	echo "SKIP: Astra is not the active theme.\n";
	return;
}
$status_ability = wp_get_ability( 'wp-ai-bridge/integration-status' );
wpai_issue4_astra_assert( $status_ability instanceof WP_Ability, 'Integration status ability is missing.' );
$status = $status_ability->execute( array() );
wpai_issue4_astra_assert( 'ability' === $status['astra']['mode'], 'Active Astra did not resolve to native Ability reuse.' );
wpai_issue4_astra_assert( count( $status['astra']['ability_names'] ) > 0, 'No public Astra Ability names were observed.' );
foreach ( wp_get_abilities() as $ability ) {
	$name = $ability->get_name();
	if ( 0 === strpos( $name, 'wp-ai-bridge/astra' ) ) {
		throw new RuntimeException( 'Bridge registered an unnecessary Astra-specific duplicate: ' . $name );
	}
}
echo 'PASS: Issue #4 Astra native Ability reuse (' . count( $status['astra']['ability_names'] ) . " observed abilities).\n";
