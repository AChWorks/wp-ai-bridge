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
	'literal globals wpdb bypass' => str_replace(
		"\$current = \$wpdb->get_var( 'SELECT CONNECTION_ID()' );",
		"\$current = \$GLOBALS['wpdb']->get_var( 'SELECT CONNECTION_ID()' );",
		$canonical
	),
	'indirect globals wpdb bypass' => str_replace(
		'    return $owner === $current;',
		"    \$key = 'wpdb';\n    \$shadow = \$GLOBALS[\$key];\n    \$shadow->get_var( 'SELECT 1' );\n    return \$owner === \$current;",
		$canonical
	),
	'new wpdb bypass' => str_replace(
		'    return $owner === $current;',
		"    \$shadow = new wpdb( 'u', 'p', 'd', 'h' );\n    return \$owner === \$current;",
		$canonical
	),
	'new fully-qualified wpdb bypass' => str_replace(
		'    return $owner === $current;',
		"    \$shadow = new \\wpdb( 'u', 'p', 'd', 'h' );\n    return \$owner === \$current;",
		$canonical
	),
	'new qualified wpdb bypass' => str_replace(
		'    return $owner === $current;',
		"    \$shadow = new Vendor\\wpdb( 'u', 'p', 'd', 'h' );\n    return \$owner === \$current;",
		$canonical
	),
	'new relative wpdb bypass' => str_replace(
		'    return $owner === $current;',
		"    \$shadow = new namespace\\wpdb( 'u', 'p', 'd', 'h' );\n    return \$owner === \$current;",
		$canonical
	),
	'new mixed-case wpdb bypass' => str_replace(
		'    return $owner === $current;',
		"    \$shadow = new \\WpDb( 'u', 'p', 'd', 'h' );\n    return \$owner === \$current;",
		$canonical
	),
	'dynamic class construction bypass' => str_replace(
		'    return $owner === $current;',
		"    \$class = 'wpdb';\n    \$shadow = new \$class( 'u', 'p', 'd', 'h' );\n    return \$owner === \$current;",
		$canonical
	),
	'double-dollar variable bypass' => str_replace(
		'    return $owner === $current;',
		"    \$key = 'wpdb';\n    \$shadow = \$\$key;\n    return \$owner === \$current;",
		$canonical
	),
	'braced variable bypass' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    $shadow = ${'wpdb'};
    return $owner === $current;
SRC,
		$canonical
	),
	'get_defined_vars handle acquisition' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    $shadow = get_defined_vars()['wpdb'];
    return $owner === $current;
SRC,
		$canonical
	),
	'compact handle acquisition' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    $shadow = compact( 'wpdb' )['wpdb'];
    return $owner === $current;
SRC,
		$canonical
	),
	'alternate object receiver' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    $shadow = null;
    $shadow->get_var( 'SELECT 1' );
    return $owner === $current;
SRC,
		$canonical
	),
	'class-alias database handle' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    class_alias( '\wpdb', 'Issue80ShadowDb' );
    $shadow = new Issue80ShadowDb( 'u', 'p', 'd', 'h' );
    return $owner === $current;
SRC,
		$canonical
	),
	'subclass database handle' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    class Issue80ShadowDb extends \wpdb {}
    $shadow = new Issue80ShadowDb( 'u', 'p', 'd', 'h' );
    return $owner === $current;
SRC,
		$canonical
	),
	'unapproved static constructor' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    $shadow = new \stdClass();
    return $owner === $current;
SRC,
		$canonical
	),
	'procedural mysqli query' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    mysqli_query( $db, 'SELECT 1' );
    return $owner === $current;
SRC,
		$canonical
	),
	'fully-qualified procedural mysqli query' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    \mysqli_query( $db, 'SELECT 1' );
    return $owner === $current;
SRC,
		$canonical
	),
	'procedural mysqli connect and query' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    $db = mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
    mysqli_query( $db, 'SELECT 1' );
    return $owner === $current;
SRC,
		$canonical
	),
	'procedural caller-selected SQL' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    \mysqli_query( \mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ), $sql );
    return $owner === $current;
SRC,
		$canonical
	),
	'variable function database dispatch' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    $fn = 'mysqli_query';
    $fn( $db, $sql );
    return $owner === $current;
SRC,
		$canonical
	),
	'call_user_func database dispatch' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    call_user_func( 'mysqli_query', $db, $sql );
    return $owner === $current;
SRC,
		$canonical
	),
	'call_user_func_array database dispatch' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    call_user_func_array( 'mysqli_query', array( $db, $sql ) );
    return $owner === $current;
SRC,
		$canonical
	),
	'forward_static_call database dispatch' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    forward_static_call( 'mysqli_query', $db, $sql );
    return $owner === $current;
SRC,
		$canonical
	),
	'string callable database dispatch' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    ( 'mysqli_query' )( $db, $sql );
    return $owner === $current;
SRC,
		$canonical
	),
	'array callable database dispatch' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    array( 'mysqli_query' )[0]( $db, $sql );
    return $owner === $current;
SRC,
		$canonical
	),
	'static callable factory dispatch' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    $fn = \Closure::fromCallable( 'mysqli_query' );
    $fn( $db, $sql );
    return $owner === $current;
SRC,
		$canonical
	),
	'function alias import bypass' => str_replace(
		"<?php\n",
		"<?php\nuse function mysqli_query as is_object;\n",
		$canonical
	),
	'include code-loading bypass' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    include 'unsafe-db.php';
    return $owner === $current;
SRC,
		$canonical
	),

	'allowed callback carrier changed to DB primitive' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    $recovery_ids = array_filter( $recovery_ids, 'mysqli_query' );
    return $owner === $current;
SRC,
		$canonical
	),


	'foreign unqualified static constant' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    Issue80_Autoload_Probe::TRIGGER;
    return $owner === $current;
SRC,
		$canonical
	),
	'foreign fully-qualified static constant' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    \WP_AI_Bridge\Auth\Issue80_Autoload_Probe::TRIGGER;
    return $owner === $current;
SRC,
		$canonical
	),
	'foreign qualified static constant' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    Vendor\Issue80_Autoload_Probe::TRIGGER;
    return $owner === $current;
SRC,
		$canonical
	),
	'foreign relative static constant' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    namespace\Issue80_Autoload_Probe::TRIGGER;
    return $owner === $current;
SRC,
		$canonical
	),
	'foreign static property read' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    $probe = Issue80_Autoload_Probe::$trigger;
    return $owner === $current;
SRC,
		$canonical
	),
	'foreign static property write' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    Issue80_Autoload_Probe::$trigger = 1;
    return $owner === $current;
SRC,
		$canonical
	),
	'parent static scope' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    parent::TRIGGER;
    return $owner === $current;
SRC,
		$canonical
	),
	'late-static scope' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    static::TRIGGER;
    return $owner === $current;
SRC,
		$canonical
	),
	'self static property' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    $probe = self::$trigger;
    return $owner === $current;
SRC,
		$canonical
	),
	'self static method' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    self::TYPE_ACCESS();
    return $owner === $current;
SRC,
		$canonical
	),
	'unapproved self constant' => str_replace(
		'    return $owner === $current;',
		<<<'SRC'
    self::UNREVIEWED_STATIC_CONSTANT;
    return $owner === $current;
SRC,
		$canonical
	),

);

foreach ( $fixtures as $label => $fixture ) {
	$error = wpai_issue80_check_oauth_store_db_confinement( $fixture );
	wpai_issue80_db_guard_assert( '' !== $error, 'Unsafe fixture false-passed: ' . $label );
}

echo "PASS: Issue #80 OAuth DB confinement negative regressions.\n";
