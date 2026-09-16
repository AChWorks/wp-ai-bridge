<?php
/** Read-only public registry contract regression tests. */
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Ability_Catalog_Abilities;
use WP_Native_Builder_Bridge\Abilities\Ability_Resolver;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

$tests = 0;
function wpai_catalog_assert( $condition, $message ) {
	global $tests;
	++$tests;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

final class WPNB_Catalog_Test_Ability {
	public $name;
	public $input = array( 'type' => 'object', 'properties' => array( 'target' => array( 'type' => 'integer' ) ), 'additionalProperties' => false );
	public $output = array( 'type' => 'string' );
	public $meta = array( 'mcp' => array( 'public' => true, 'type' => 'tool' ) );
	public $label = 'Catalog fixture';
	public $description = 'Public operation contract.';
	public $permission_calls = 0;
	public $execute_calls = 0;
	public function __construct( $name ) { $this->name = $name; }
	public function get_name() { return $this->name; }
	public function get_label() { return $this->label; }
	public function get_description() { return $this->description; }
	public function get_category() { return 'test'; }
	public function get_input_schema() { return $this->input; }
	public function get_output_schema() { return $this->output; }
	public function get_meta() { return $this->meta; }
	public function check_permissions( $input = null ) { ++$this->permission_calls; throw new RuntimeException( 'Catalog invoked a provider permission callback.' ); }
	public function execute( $input = null ) { ++$this->execute_calls; throw new RuntimeException( 'Catalog executed a provider operation.' ); }
}

wpai_test_reset_state();
$settings = new Settings();
$catalog = new Ability_Catalog_Abilities( new Ability_Resolver(), new Permissions( $settings ) );
$catalog->register();
$args = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/abilities-read'];
wpai_catalog_assert( true === $args['meta']['annotations']['readonly'], 'Catalog must be readonly.' );
wpai_catalog_assert( false === $args['meta']['annotations']['destructive'], 'Catalog must not be destructive.' );
wpai_catalog_assert( false === $args['input_schema']['additionalProperties'], 'Catalog input schema must remain closed.' );
wpai_catalog_assert( $catalog->can_read(), 'Safe default must permit contract inspection.' );

for ( $i = 136; $i >= 0; --$i ) {
	$name = sprintf( 'unknown-provider/operation-%03d', $i );
	$GLOBALS['wpai_test']['abilities'][ $name ] = new WPNB_Catalog_Test_Ability( $name );
}
$private = new WPNB_Catalog_Test_Ability( 'hidden/private' );
$private->meta = array( 'public' => false );
$GLOBALS['wpai_test']['abilities'][ $private->name ] = $private;
$optout = new WPNB_Catalog_Test_Ability( 'hidden/optout' );
$optout->meta = array( 'public' => true, 'mcp' => array( 'public' => false ) );
$GLOBALS['wpai_test']['abilities'][ $optout->name ] = $optout;

$list = $catalog->read( array() );
wpai_catalog_assert( ! is_wp_error( $list ), 'Default inventory failed.' );
wpai_catalog_assert( 137 === $list['total'] && 25 === count( $list['items'] ) && 6 === $list['total_pages'], 'Default bounded pagination is incorrect.' );
wpai_catalog_assert( 'unknown-provider/operation-000' === $list['items'][0]['name'], 'Inventory must sort by exact name, not registration order.' );
wpai_catalog_assert( 'not_evaluated' === $list['execution_permission'], 'Catalog must not invent execution permission.' );
wpai_catalog_assert( ! isset( $list['items'][0]['input_schema'], $list['items'][0]['output_schema'] ), 'Broad listing must not dump schemas.' );
wpai_catalog_assert( null === $list['items'][0]['annotations']['readonly'], 'Missing annotations must not become trusted booleans.' );
$second = $catalog->read( array( 'page' => 2, 'per_page' => 100 ) );
wpai_catalog_assert( 37 === count( $second['items'] ) && 'unknown-provider/operation-100' === $second['items'][0]['name'], 'Records after the first 100 must be reachable.' );
$all = array();
for ( $page = 1; $page <= 6; ++$page ) {
	$result = $catalog->read( array( 'page' => $page ) );
	$all = array_merge( $all, array_column( $result['items'], 'name' ) );
}
wpai_catalog_assert( 137 === count( array_unique( $all ) ), 'Pagination dropped or duplicated registered contracts.' );
foreach ( array( 7, PHP_INT_MAX ) as $page ) {
	$result = $catalog->read( array( 'page' => $page ) );
	wpai_catalog_assert( array() === $result['items'] && 137 === $result['total'], 'Out-of-range page must be empty without integer overflow.' );
}
$filtered = $catalog->read( array( 'namespace' => 'unknown-provider', 'search' => 'OPERATION-13' ) );
wpai_catalog_assert( 7 === $filtered['total'], 'Case-insensitive search and exact namespace filtering must combine.' );
wpai_catalog_assert( 0 === $catalog->read( array( 'namespace' => 'unknown' ) )['total'], 'Namespace matching must include the namespace delimiter.' );

$name = 'unknown-provider/operation-025';
$fixture = $GLOBALS['wpai_test']['abilities'][ $name ];
$fixture->meta['annotations'] = array( 'readonly' => false, 'destructive' => true, 'idempotent' => 'untrusted-string' );
$fixture->meta['private_configuration'] = 'SHOULD_NOT_APPEAR';
$fixture->meta['callbacks'] = array( 'password' => 'SHOULD_NOT_APPEAR' );
$detail = $catalog->read( array( 'action' => 'get', 'name' => $name ) );
wpai_catalog_assert( ! is_wp_error( $detail ), 'Exact public contract lookup failed.' );
$item = $detail['items'][0];
wpai_catalog_assert( $fixture->input === $item['input_schema'] && $fixture->output === $item['output_schema'], 'Exact native schemas must remain unchanged.' );
wpai_catalog_assert( false === $item['annotations']['readonly'] && true === $item['annotations']['destructive'] && null === $item['annotations']['idempotent'], 'Only actual boolean annotations may be reported as booleans.' );
wpai_catalog_assert( false === strpos( json_encode( $detail ), 'SHOULD_NOT_APPEAR' ), 'Arbitrary provider metadata leaked.' );
wpai_catalog_assert( 0 === $fixture->execute_calls && 0 === $fixture->permission_calls, 'Inventory invoked a provider callback.' );
foreach ( array( 'hidden/private', 'hidden/optout', 'missing/ability' ) as $hidden ) {
	$result = $catalog->read( array( 'action' => 'get', 'name' => $hidden ) );
	wpai_catalog_assert( is_wp_error( $result ) && 'ability_contract_not_found' === $result->get_error_code(), 'Private, hidden, and missing names must have indistinguishable lookup errors.' );
}

$fixture->output = array();
wpai_catalog_assert( array() === $catalog->read( array( 'action' => 'get', 'name' => $name ) )['items'][0]['output_schema'], 'Absent output schema must preserve the actual empty native schema.' );
$fixture->input['default'] = (object) array();
$detail = $catalog->read( array( 'action' => 'get', 'name' => $name ) );
wpai_catalog_assert( $detail['items'][0]['input_schema']['default'] instanceof stdClass, 'JSON object defaults must not be flattened into arrays.' );
$fixture->input['default'] = new class implements JsonSerializable {
	public function jsonSerialize(): mixed { throw new RuntimeException( 'Custom object serialization must not run.' ); }
};
wpai_catalog_assert( 'ability_contract_unrepresentable' === $catalog->read( array( 'action' => 'get', 'name' => $name ) )->get_error_code(), 'Unsupported contract objects must fail before JSON serialization.' );
$fixture->input = array( 'type' => 'string', 'description' => str_repeat( 'x', Ability_Catalog_Abilities::MAX_RESPONSE_BYTES ) );
wpai_catalog_assert( 'ability_catalog_response_too_large' === $catalog->read( array( 'action' => 'get', 'name' => $name ) )->get_error_code(), 'Oversized contract must fail, not be truncated.' );
$fixture->input = array( 'type' => 'string', 'description' => "\xff" );
wpai_catalog_assert( 'ability_contract_unrepresentable' === $catalog->read( array( 'action' => 'get', 'name' => $name ) )->get_error_code(), 'Invalid UTF-8 must not be silently changed.' );
$fixture->input = array( 'type' => 'string' );
$fixture->output = null;
wpai_catalog_assert( is_wp_error( $catalog->read( array( 'action' => 'get', 'name' => $name ) ) ), 'Invalid output contract type must fail closed.' );
$fixture->output = array();

foreach ( array( false, array( 'action' => 'execute' ), array( 'action' => 'get' ), array( 'action' => 'get', 'name' => array() ), array( 'page' => 0 ), array( 'page' => '2' ), array( 'per_page' => 101 ), array( 'per_page' => 0 ), array( 'namespace' => array() ), array( 'search' => array() ) ) as $invalid ) {
	wpai_catalog_assert( is_wp_error( $catalog->read( $invalid ) ), 'Malformed catalog arguments must fail.' );
}
wpai_catalog_assert( 0 === $catalog->read( array( 'namespace' => 'unknown-provider/operation-025' ) )['total'], 'Namespace must not act as an arbitrary path prefix.' );
$fixture->input = array( 'type' => 'object' );
$fixture->input['recursive'] = &$fixture->input;
wpai_catalog_assert( is_wp_error( $catalog->read( array( 'action' => 'get', 'name' => $name ) ) ), 'Recursive contract data must fail within the depth budget.' );
unset( $fixture->input['recursive'] );
$fixture->input = array( 'type' => 'string' );
$long_name = 'unknown-provider/' . str_repeat( 'a', 300 );
$GLOBALS['wpai_test']['abilities'][ $long_name ] = new WPNB_Catalog_Test_Ability( $long_name );
wpai_catalog_assert( ! is_wp_error( $catalog->read( array( 'action' => 'get', 'name' => $long_name ) ) ), 'Valid registered names must not be rejected by an invented provider/name allowlist.' );
$fixture->input = array( 'type' => 'object', 'default' => new class extends stdClass implements JsonSerializable {
	public function jsonSerialize(): mixed { throw new RuntimeException( 'A stdClass subclass must not execute custom serialization during inspection.' ); }
} );
wpai_catalog_assert( 'ability_contract_unrepresentable' === $catalog->read( array( 'action' => 'get', 'name' => $name ) )->get_error_code(), 'A derived stdClass must be rejected before custom JSON serialization.' );
$fixture->input = array( 'type' => 'object', 'default' => new stdClass() );
wpai_catalog_assert( ! is_wp_error( $catalog->read( array( 'action' => 'get', 'name' => $name ) ) ), 'Plain JSON object defaults must remain supported.' );
$exposure_cases = array(
	array( array( 'public' => true, 'mcp' => 'malformed' ), false ),
	array( array( 'public' => true, 'mcp' => false ), false ),
	array( array( 'public' => 'true' ), false ),
	array( array( 'public' => 1 ), false ),
	array( array( 'public' => true, 'mcp' => null ), true ),
	array( array( 'public' => true, 'mcp' => array( 'public' => null ) ), true ),
	array( array( 'public' => true, 'mcp' => array( 'public' => 0 ) ), false ),
	array( array( 'public' => false, 'mcp' => array( 'public' => true ) ), true ),
);
$resolver = new Ability_Resolver();
foreach ( $exposure_cases as $index => $case ) {
	$exposure = new WPNB_Catalog_Test_Ability( 'exposure/case-' . $index );
	$exposure->meta = $case[0];
	$GLOBALS['wpai_test']['abilities'][ $exposure->name ] = $exposure;
	wpai_catalog_assert( $case[1] === $resolver->is_mcp_exposed( $exposure ), 'Resolver disagrees with pinned Adapter exposure semantics.' );
	$read = $catalog->read( array( 'action' => 'get', 'name' => $exposure->name ) );
	wpai_catalog_assert( $case[1] === ! is_wp_error( $read ), 'Exact inspection disagrees with the native exposure rule.' );
	$found = $resolver->find( array( $exposure->name ) );
	wpai_catalog_assert( $case[1] === ( null !== $found ), 'Native reuse must share the same exposure rule.' );
	unset( $GLOBALS['wpai_test']['abilities'][ $exposure->name ] );
}
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] = array( Settings::GROUP_SITE_READ => 0 );
wpai_catalog_assert( ! $catalog->can_read() && is_wp_error( $catalog->read( array() ) ), 'Revoked Site Read must deny direct and Ability entry points.' );
$GLOBALS['wpai_test']['options'][ Settings::OPTION_NAME ] = $settings->defaults();
$GLOBALS['wpai_test']['capabilities']['read'] = false;
wpai_catalog_assert( ! $catalog->can_read() && is_wp_error( $catalog->read( array( 'action' => 'get', 'name' => $name ) ) ), 'Missing native capability must deny exact lookup.' );
$GLOBALS['wpai_test']['capabilities']['read'] = true;
$GLOBALS['wpai_test']['abilities'] = array();
$empty = $catalog->read( array() );
wpai_catalog_assert( 0 === $empty['total'] && 0 === $empty['total_pages'] && array() === $empty['items'], 'Empty registry must be explicit.' );
echo "PASS: {$tests} Ability catalog regression assertions.\n";
