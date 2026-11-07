<?php
/**
 * Issue #207: real Astra late-Customizer inventory falls back to stored theme mods.
 *
 * Run only on an isolated disposable WordPress installation with Astra 4.13.11.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Customizer_Abilities;
use WP_AI_Bridge\Abilities\Native_Configuration_Abilities;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

require_once __DIR__ . '/issue199-native-options-helper.php';

wpai199n_assert( ! is_multisite() && current_user_can( 'manage_options' ) && 'astra' === get_stylesheet() && '4.13.11' === wp_get_theme()->get( 'Version' ), 'Adaptive inventory requires disposable Astra 4.13.11.' );
$settings       = new Settings();
$permissions    = new Permissions( $settings );
$customizer     = new Customizer_Abilities( $permissions );
$native         = new Native_Configuration_Abilities( $permissions );
$original_flags = get_option( Settings::OPTION_NAME, null );
$original_admin = get_option( Settings::ADMINISTRATION_OPTION_NAME, null );
$original_log   = get_option( Mutation_Log::OPTION_NAME, null );
$original_mods  = get_option( 'theme_mods_astra', null );
$key            = 'wpai207_' . bin2hex( random_bytes( 6 ) );
global $wpdb;
$before_changesets = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'customize_changeset' ORDER BY ID" );
try {
	update_option( Settings::OPTION_NAME, $settings->sanitize( array_fill_keys( array_keys( $settings->defaults() ), 1 ) ), false );
	update_option( Settings::ADMINISTRATION_OPTION_NAME, $settings->sanitize_administration_grants( array( Settings::GROUP_CUSTOMIZER_WRITE => 1, Settings::GROUP_NATIVE_CONFIGURATION => 1 ) ), false );
	set_theme_mod( $key, 'issue207-test-value' );

	$keys = array_keys( get_theme_mods() );
	sort( $keys, SORT_STRING );
	$position = array_search( $key, $keys, true );
	wpai199n_assert( false !== $position, 'Disposable theme-mod setup was not visible.' );
	$input = array( 'action' => 'list', 'expected_stylesheet' => 'astra', 'limit' => 1 );
	if ( $position > 0 ) {
		$input['after_name'] = (string) $keys[ $position - 1 ];
	}
	$result = wpai199n_ok( $customizer->execute( $input ), 'Astra registered Customizer listing did not degrade to stored keys.' );
	wpai199n_assert( 'list' === $result['action'] && 'theme_mod' === $result['target'] && 'astra' === $result['stylesheet'], 'Real fallback lost native active-theme identity.' );
	wpai199n_assert( 'stored_theme_mod_keys' === $result['inventory'] && 'stored_theme_mods' === $result['provenance'] && 'stored_only' === $result['coverage'] && false === $result['virtual_keys_enumerated'], 'Real fallback misrepresented partial coverage.' );
	wpai199n_assert( 'customizer_late_bootstrap_unavailable' === $result['customizer_error_code'], 'Original real Astra bootstrap failure was concealed.' );
	wpai199n_assert( array( array( 'name' => $key ) ) === $result['items'], 'Real stored-key inventory failed exact cursor/limit.' );
	wpai199n_assert( 'issue207-test-value' === get_theme_mod( $key ), 'Read-only fallback unexpectedly modified stored theme mod.' );

	$read = wpai199n_ok( $native->execute( array( 'action' => 'read', 'target' => 'theme_mod', 'expected_stylesheet' => 'astra', 'name' => $key ) ), 'Explicit native theme-mod read failed after Customizer fallback.' );
	wpai199n_assert( 'wpai-native-option-value-v2' === $read['format'] && true === $read['value_available'], 'Explicit native value contract was lost.' );
	$status = wp_get_ability( 'wp-ai-bridge/integration-status' )->execute( array() );
	wpai199n_assert( 'ability' === $status['astra']['mode'] && count( $status['astra']['ability_names'] ) > 0, 'Astra provider-native Abilities were disabled by generic fallback.' );
	wpai199n_assert( $before_changesets === $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'customize_changeset' ORDER BY ID" ), 'Fallback unexpectedly published a Customizer changeset.' );
	echo 'PASS: Issue #207 real Astra adaptive stored-key fallback (' . $GLOBALS['wpai199n_checks'] . " checks).\n";
} finally {
	wpai199n_restore_option( 'theme_mods_astra', $original_mods, null !== $original_mods );
	wpai199n_restore_option( Settings::OPTION_NAME, $original_flags, null !== $original_flags );
	wpai199n_restore_option( Settings::ADMINISTRATION_OPTION_NAME, $original_admin, null !== $original_admin );
	wpai199n_restore_option( Mutation_Log::OPTION_NAME, $original_log, null !== $original_log );
}
