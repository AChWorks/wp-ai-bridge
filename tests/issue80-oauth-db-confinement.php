<?php
/**
 * Regression tests for the Issue #80 syntax-aware OAuth DB confinement checker.
 *
 * @package WP_AI_Bridge
 */

require_once dirname( __DIR__ ) . '/bin/check-oauth-store-db-confinement.php';

function wpai_issue80_db_guard_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$canonical = <<<'PHP'
<?php
function acquire_refresh_lock_id( $name ) {
    global $wpdb;
    if ( '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return false; }
    return $wpdb->get_var(
        $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::REFRESH_LOCK_TIMEOUT )
    );
}
function release_refresh_lock_id( $name ) {
    global $wpdb;
    if ( '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return; }
    $wpdb->get_var(
        $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name )
    );
}
function refresh_lock_is_owned( $name ) {
    global $wpdb;
    if ( '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return false; }
    $owner = $wpdb->get_var(
        $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name )
    );
    $current = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
    return $owner === $current;
}
PHP;

$error = wpai_issue80_check_oauth_store_db_confinement( $canonical );
wpai_issue80_db_guard_assert( '' === $error, 'Canonical Issue #80 DB surface was rejected: ' . $error );

$fixtures = array(
	'dynamic SQL with expected literal in comment' => str_replace(
		"\$current = \$wpdb->get_var( 'SELECT CONNECTION_ID()' );",
		"// 'SELECT CONNECTION_ID()'\n    \$statement = \$attacker_controlled;\n    \$current = \$wpdb->get_var( \$statement );",
		$canonical
	),
	'dynamic SQL with expected literal in dead string' => str_replace(
		"\$current = \$wpdb->get_var( 'SELECT CONNECTION_ID()' );",
		"\$decoy = 'SELECT CONNECTION_ID()';\n    \$arbitrary = \$attacker_controlled;\n    \$current = \$wpdb->get_var( \$arbitrary );",
		$canonical
	),
	'altered literal query' => str_replace( 'SELECT CONNECTION_ID()', 'SELECT CONNECTION_ID() + 0', $canonical ),
	'extra wpdb call'       => str_replace(
		'    return $owner === $current;',
		"    \$extra = \$wpdb->get_var( 'SELECT 1' );\n    return \$owner === \$current;",
		$canonical
	),
	'caller-selected query fragment' => str_replace(
		"\$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', \$name, self::REFRESH_LOCK_TIMEOUT )",
		"\$wpdb->prepare( 'SELECT GET_LOCK(' . \$fragment . ')', \$name, self::REFRESH_LOCK_TIMEOUT )",
		$canonical
	),
	'wpdb alias bypass' => str_replace(
		"\$current = \$wpdb->get_var( 'SELECT CONNECTION_ID()' );",
		"\$db = \$wpdb;\n    \$current = \$db->get_var( 'SELECT CONNECTION_ID()' );",
		$canonical
	),
	'globals wpdb bypass' => str_replace(
		"\$current = \$wpdb->get_var( 'SELECT CONNECTION_ID()' );",
		"\$current = \$GLOBALS['wpdb']->get_var( 'SELECT CONNECTION_ID()' );",
		$canonical
	),
	'new wpdb bypass' => str_replace(
		'    return $owner === $current;',
		"    \$shadow = new wpdb( 'u', 'p', 'd', 'h' );\n    return \$owner === \$current;",
		$canonical
	),
);

foreach ( $fixtures as $label => $fixture ) {
	$error = wpai_issue80_check_oauth_store_db_confinement( $fixture );
	wpai_issue80_db_guard_assert( '' !== $error, 'Unsafe fixture false-passed: ' . $label );
}

echo "PASS: Issue #80 OAuth DB confinement negative regressions.\n";
