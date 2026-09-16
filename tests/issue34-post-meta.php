<?php
/**
 * Dependency-free regression coverage for Issue #34 generic post metadata abilities.
 */

define( 'WP_NATIVE_BUILDER_BRIDGE_TEST_MODE', true );
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Post_Meta_Abilities;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

function wpai_issue34_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$GLOBALS['wpai_issue34_posts'] = array(
	101 => (object) array(
		'ID'          => 101,
		'post_type'   => 'wpai_builder_fixture',
		'post_status' => 'draft',
	),
	102 => (object) array(
		'ID'          => 102,
		'post_type'   => 'wpai_doc',
		'post_status' => 'private',
	),
);
$GLOBALS['wpai_issue34_meta'] = array(
	101 => array(
		'_builder_markup' => array( '<footer>Old</footer>' ),
		'api_secret'      => array( 'must-not-be-exposed' ),
		'multi_value_key' => array( 'first', 'second' ),
		'nested_object'   => array( array( 'object' => (object) array( 'state' => 'keep' ) ) ),
	),
	102 => array(
		'_wpai_workspace_state' => array( '{"private":true}' ),
	),
);
$GLOBALS['wpai_issue34_registered_meta'] = array();
$GLOBALS['wpai_issue34_mapped_caps']     = null;

function get_post( $post_id ) {
	return isset( $GLOBALS['wpai_issue34_posts'][ $post_id ] ) ? $GLOBALS['wpai_issue34_posts'][ $post_id ] : null;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	$all = isset( $GLOBALS['wpai_issue34_meta'][ $post_id ] ) ? $GLOBALS['wpai_issue34_meta'][ $post_id ] : array();
	if ( '' === $key ) {
		return $all;
	}
	$values = isset( $all[ $key ] ) ? $all[ $key ] : array();
	if ( $single ) {
		return isset( $values[0] ) ? $values[0] : '';
	}
	return $values;
}

function add_post_meta( $post_id, $key, $value, $unique = false ) {
	$values = get_post_meta( $post_id, $key, false );
	if ( $unique && $values ) {
		return false;
	}
	$GLOBALS['wpai_issue34_meta'][ $post_id ][ $key ][] = $value;
	return count( $GLOBALS['wpai_issue34_meta'][ $post_id ][ $key ] );
}

function update_post_meta( $post_id, $key, $value, $prev_value = '' ) {
	$values = get_post_meta( $post_id, $key, false );
	if ( ! $values ) {
		$GLOBALS['wpai_issue34_meta'][ $post_id ][ $key ] = array( $value );
		return true;
	}
	if ( '' !== $prev_value && serialize( $values[0] ) !== serialize( $prev_value ) ) {
		return false;
	}
	$GLOBALS['wpai_issue34_meta'][ $post_id ][ $key ] = array( $value );
	return true;
}

function delete_post_meta( $post_id, $key, $value = '' ) {
	$values = get_post_meta( $post_id, $key, false );
	if ( ! $values ) {
		return false;
	}
	if ( '' !== $value && serialize( $values[0] ) !== serialize( $value ) ) {
		return false;
	}
	unset( $GLOBALS['wpai_issue34_meta'][ $post_id ][ $key ] );
	return true;
}

function is_protected_meta( $key, $type = null ) {
	return 0 === strpos( (string) $key, '_' );
}

function get_registered_meta_keys( $object_type, $object_subtype = '' ) {
	return $GLOBALS['wpai_issue34_registered_meta'];
}

function has_filter( $hook_name, $callback = false ) {
	return false;
}

function map_meta_cap( $capability, $user_id, ...$args ) {
	$extra = $GLOBALS['wpai_issue34_mapped_caps'];
	return is_array( $extra ) ? array_merge( array( $capability ), $extra ) : array( $capability );
}

function maybe_serialize( $data ) {
	return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data;
}

function maybe_unserialize( $data ) {
	if ( is_string( $data ) && preg_match( '/^[aObisd]:/', $data ) ) {
		$value = @unserialize( $data );
		return false !== $value || 'b:0;' === $data ? $value : $data;
	}
	return $data;
}

wpai_test_reset_state();
$GLOBALS['wpai_test']['capabilities']['read']             = true;
$GLOBALS['wpai_test']['capabilities']['edit_post']        = true;
$GLOBALS['wpai_test']['capabilities']['edit_post_meta']   = true;
$GLOBALS['wpai_test']['capabilities']['add_post_meta']    = true;
$GLOBALS['wpai_test']['capabilities']['delete_post_meta'] = true;

$settings    = new Settings();
$permissions = new Permissions( $settings );
$abilities   = new Post_Meta_Abilities( $permissions, new Mutation_Log() );
$defaults    = $settings->defaults();
wpai_issue34_assert( isset( $defaults[ Settings::GROUP_ADVANCED_METADATA ] ), 'Advanced Metadata group is missing.' );
wpai_issue34_assert( 0 === $defaults[ Settings::GROUP_ADVANCED_METADATA ], 'Advanced Metadata must default to disabled.' );
update_option( Settings::OPTION_NAME, $defaults, false );
wpai_issue34_assert( ! $abilities->can_read( array( 'post_id' => 101, 'key' => '_builder_markup' ) ), 'Metadata was readable while Advanced Metadata was disabled.' );

$access                                      = $defaults;
$access[ Settings::GROUP_ADVANCED_METADATA ] = 1;
$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $access, false );

$listed = $abilities->read( array( 'post_id' => 101 ) );
wpai_issue34_assert( ! is_wp_error( $listed ), 'Metadata discovery failed.' );
$listed_keys = array_column( $listed['items'], 'key' );
wpai_issue34_assert( in_array( '_builder_markup', $listed_keys, true ), 'Builder metadata was not discoverable.' );
wpai_issue34_assert( ! in_array( 'api_secret', $listed_keys, true ), 'Credential metadata leaked through discovery.' );

$secret_keys = array(
	'sessionToken', 'session_token', 'sessionTokens', 'session_tokens', 'SESSION__TOKENS', 'session\\tokens',
	'identityTokens', 'identity_tokens', 'idToken', 'id_token', 'idTokens', 'idtokens',
	'jwtToken', 'jwt_token', 'jwtTokens', 'jwttokens', 'securityTokens', 'csrfTokens',
	'clientSecret', 'clientSecrets', 'client_secrets', 'consumerSecrets',
	'accessToken', 'accessTokens', 'apiKeys', 'privateKeys', 'applicationPasswords',
);
foreach ( $secret_keys as $secret_key ) {
	$GLOBALS['wpai_issue34_meta'][101][ $secret_key ] = array( 'secret-fixture' );
	$secret = $abilities->read( array( 'post_id' => 101, 'key' => $secret_key, 'include_values' => true ) );
	wpai_issue34_assert( is_wp_error( $secret ) && 'sensitive_post_meta_key' === $secret->get_error_code(), 'Credential-like key was not denied: ' . $secret_key );
}
$listed = $abilities->read( array( 'post_id' => 101 ) );
$listed_keys = array_column( $listed['items'], 'key' );
foreach ( $secret_keys as $secret_key ) {
	wpai_issue34_assert( ! in_array( $secret_key, $listed_keys, true ), 'Credential-like key leaked through discovery: ' . $secret_key );
}
$GLOBALS['wpai_issue34_meta'][101]['design_token'] = array( 'blue' );
wpai_issue34_assert( ! is_wp_error( $abilities->read( array( 'post_id' => 101, 'key' => 'design_token' ) ) ), 'Unrelated design_token was overblocked.' );

$read = $abilities->read( array( 'post_id' => 101, 'key' => '_builder_markup', 'include_values' => true ) );
wpai_issue34_assert( ! is_wp_error( $read ) && 1 === $read['items'][0]['count'], 'Builder metadata read failed.' );
$old_hash = $read['items'][0]['state_hash'];
$updated  = $abilities->update(
	array(
		'post_id'             => 101,
		'key'                 => '_builder_markup',
		'value_json'          => json_encode( '<footer>New</footer>' ),
		'expected_state_hash' => $old_hash,
	)
);
wpai_issue34_assert( ! is_wp_error( $updated ), 'Builder metadata update failed.' );
wpai_issue34_assert( '<footer>New</footer>' === get_post_meta( 101, '_builder_markup', true ), 'Builder metadata update did not persist.' );

$stale = $abilities->update(
	array(
		'post_id'             => 101,
		'key'                 => '_builder_markup',
		'value_json'          => json_encode( 'stale' ),
		'expected_state_hash' => $old_hash,
	)
);
wpai_issue34_assert( is_wp_error( $stale ) && 'stale_post_meta_conflict' === $stale->get_error_code(), 'Stale update was not rejected.' );

/* Empty-like physical values remain legitimate exact-row states. */
foreach (
	array(
		'empty_string' => array( '', 'filled' ),
		'zero_string'  => array( '0', 'one' ),
		'empty_array'  => array( array(), array( 'filled' ) ),
	) as $safe_key => $fixture
) {
	$GLOBALS['wpai_issue34_meta'][101][ $safe_key ] = array( $fixture[0] );
	$safe_read = $abilities->read( array( 'post_id' => 101, 'key' => $safe_key ) );
	$safe_update = $abilities->update(
		array(
			'post_id'             => 101,
			'key'                 => $safe_key,
			'value_json'          => json_encode( $fixture[1] ),
			'expected_state_hash' => $safe_read['items'][0]['state_hash'],
		)
	);
	wpai_issue34_assert( ! is_wp_error( $safe_update ), 'Safe empty-like metadata could not be updated: ' . $safe_key );
}

/* SQL-NULL-like harness state is distinct from the empty string in physical identity. */
$GLOBALS['wpai_issue34_meta'][101]['nullable_state'] = array( null );
$null_read = $abilities->read( array( 'post_id' => 101, 'key' => 'nullable_state' ) );
$GLOBALS['wpai_issue34_meta'][101]['nullable_state'] = array( '' );
$empty_read = $abilities->read( array( 'post_id' => 101, 'key' => 'nullable_state' ) );
wpai_issue34_assert( $null_read['items'][0]['state_hash'] !== $empty_read['items'][0]['state_hash'], 'NULL and empty-string physical states shared one hash.' );

$multi = $abilities->read( array( 'post_id' => 101, 'key' => 'multi_value_key' ) );
$multi_update = $abilities->update(
	array(
		'post_id'             => 101,
		'key'                 => 'multi_value_key',
		'value_json'          => json_encode( 'replacement' ),
		'expected_state_hash' => $multi['items'][0]['state_hash'],
	)
);
wpai_issue34_assert( is_wp_error( $multi_update ) && 'post_meta_multiple_values_unsupported' === $multi_update->get_error_code(), 'Multi-row update did not fail closed.' );

$nested = $abilities->read( array( 'post_id' => 101, 'key' => 'nested_object' ) );
$nested_delete = $abilities->delete(
	array(
		'post_id'             => 101,
		'key'                 => 'nested_object',
		'expected_state_hash' => $nested['items'][0]['state_hash'],
	)
);
wpai_issue34_assert( is_wp_error( $nested_delete ) && 'post_meta_object_value_unsupported' === $nested_delete->get_error_code(), 'Opaque nested-object metadata delete did not fail closed.' );
wpai_issue34_assert( isset( $GLOBALS['wpai_issue34_meta'][101]['nested_object'] ), 'Denied opaque metadata delete changed storage.' );

$workspace = $abilities->read( array( 'post_id' => 102 ) );
wpai_issue34_assert( is_wp_error( $workspace ) && 'post_meta_target_not_allowed' === $workspace->get_error_code(), 'Workspace metadata became reachable.' );

$fresh   = $abilities->read( array( 'post_id' => 101, 'key' => '_builder_markup' ) );
$deleted = $abilities->delete(
	array(
		'post_id'             => 101,
		'key'                 => '_builder_markup',
		'expected_state_hash' => $fresh['items'][0]['state_hash'],
	)
);
wpai_issue34_assert( ! is_wp_error( $deleted ) && true === $deleted['deleted'], 'Authorized metadata delete failed.' );
wpai_issue34_assert( array() === get_post_meta( 101, '_builder_markup', false ), 'Metadata delete left the row behind.' );

echo "PASS: Issue #34 advanced post metadata regression coverage.\n";
