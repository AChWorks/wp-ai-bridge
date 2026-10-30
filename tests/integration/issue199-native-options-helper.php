<?php
/**
 * Test-only native option value decoder and disposable fixture assertions.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Settings;

function wpai199n_assert( $condition, $message ) {
	$GLOBALS['wpai199n_checks'] = 1 + ( $GLOBALS['wpai199n_checks'] ?? 0 );
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function wpai199n_ok( $result, $message ) {
	wpai199n_assert( ! is_wp_error( $result ), $message . ( is_wp_error( $result ) ? ' [' . $result->get_error_code() . ']' : '' ) );
	wpai199n_assert( is_array( $result ), 'Native option result must be an object-shaped array.' );
	return $result;
}
function wpai199n_error( $result, $message, array $codes = array() ) {
	wpai199n_assert( is_wp_error( $result ), $message );
	if ( $codes ) {
		wpai199n_assert( in_array( $result->get_error_code(), $codes, true ), 'Unexpected native option error at ' . $message . ': ' . $result->get_error_code() );
	}
	return $result;
}
function wpai199n_call( array $input ) {
	$ability = wp_get_ability( 'wp-ai-bridge/native-options-read' );
	wpai199n_assert( $ability instanceof WP_Ability, 'Missing native-options-read Ability.' );
	return $ability->execute( $input );
}

/**
 * Independent consumer: reconstruct native values from the documented wire format.
 * JSON remains a wire-only representation; no unserialize or production decoder.
 */
function wpai199n_decode( $json ) {
	$document = is_string( $json ) ? json_decode( $json, true, 512, JSON_THROW_ON_ERROR ) : $json;
	wpai199n_assert( is_array( $document ) && 'wpai-native-option-value-v1' === ( $document['format'] ?? null ) && isset( $document['value']['type'] ), 'Native value envelope format changed.' );
	return wpai199n_decode_tag( $document['value'] );
}
function wpai199n_decode_tag( array $tag ) {
	switch ( $tag['type'] ?? '' ) {
		case 'null':
			wpai199n_assert( array( 'type' ) === array_keys( $tag ), 'Null tag contains an invented value.' );
			return null;
		case 'boolean':
			wpai199n_assert( is_bool( $tag['value'] ?? null ), 'Boolean tag lost its native type.' );
			return $tag['value'];
		case 'integer':
			wpai199n_assert( is_string( $tag['decimal'] ?? null ) && 1 === preg_match( '/^-?(0|[1-9][0-9]*)$/D', $tag['decimal'] ), 'Integer tag is not exact decimal text.' );
			$integer = (int) $tag['decimal'];
			wpai199n_assert( (string) $integer === $tag['decimal'], 'Native integer exceeds the consumer range or changed spelling.' );
			return $integer;
		case 'float':
			$binary = base64_decode( $tag['base64'] ?? '', true );
			wpai199n_assert( is_string( $binary ) && 8 === strlen( $binary ), 'Float tag is not eight binary bytes.' );
			return unpack( 'Evalue', $binary )['value'];
		case 'string':
			$bytes = base64_decode( $tag['base64'] ?? '', true );
			wpai199n_assert( is_string( $bytes ) && base64_encode( $bytes ) === $tag['base64'], 'String tag lost canonical binary bytes.' );
			return $bytes;
		case 'array':
			wpai199n_assert( is_array( $tag['entries'] ?? null ) && array_values( $tag['entries'] ) === $tag['entries'], 'Array entries lost their ordered sequence.' );
			$value = array();
			foreach ( $tag['entries'] as $entry ) {
				wpai199n_assert( isset( $entry['key']['type'], $entry['value']['type'] ) && in_array( $entry['key']['type'], array( 'integer', 'string' ), true ), 'Array key is not an exact native integer/string tag.' );
				$key = wpai199n_decode_tag( $entry['key'] );
				wpai199n_assert( ! array_key_exists( $key, $value ), 'Array wire format duplicated a native key.' );
				$value[ $key ] = wpai199n_decode_tag( $entry['value'] );
			}
			return $value;
	}
	throw new RuntimeException( 'Undocumented native option value tag.' );
}
function wpai199n_same( $expected, $actual, $message ) {
	if ( is_float( $expected ) ) {
		wpai199n_assert( is_float( $actual ) && pack( 'E', $expected ) === pack( 'E', $actual ), $message );
		return;
	}
	if ( is_array( $expected ) ) {
		wpai199n_assert( is_array( $actual ) && array_keys( $expected ) === array_keys( $actual ), $message . ' (ordered keys)' );
		foreach ( $expected as $key => $value ) {
			wpai199n_same( $value, $actual[ $key ], $message . ' (entry)' );
		}
		return;
	}
	wpai199n_assert( gettype( $expected ) === gettype( $actual ) && $expected === $actual, $message );
}
function wpai199n_restore_option( $name, $original, $present = true ) {
	if ( ! $present ) {
		delete_option( $name );
		return;
	}
	// Protected grants use their genuine native setter with the original actor.
	update_option( $name, $original, false );
}
function wpai199n_grant_site( $enabled = true ) {
	$settings = new Settings();
	$flags = $settings->all();
	$flags[ Settings::GROUP_NATIVE_OPTIONS_READ ] = $enabled ? 1 : 0;
	update_option( Settings::OPTION_NAME, $settings->sanitize( $flags ), false );
	return $flags;
}
function wpai199n_protected_denial( $callback ) {
	$denied = false;
	$handler = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			throw new RuntimeException( 'wpai199n-protected-' . ( is_array( $args ) ? ( $args['response'] ?? 500 ) : 500 ) );
		};
	};
	add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		if ( 'wpai199n-protected-403' !== $error->getMessage() ) {
			throw $error;
		}
		$denied = true;
	} finally {
		remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	}
	wpai199n_assert( $denied, 'Unauthorized network grant setter did not fail before persistence with HTTP 403.' );
}
/**
 * Advance only one exact fixture-owned lease's clock without sleeping. Use the
 * store's locked checkpoint writer so its hashes/generation protocol stay real.
 * This is state injection for expiry assertions, never an authority shortcut.
 */
function wpai199n_lease_expiry( $id, $expires, $scope = 'site' ) {
	$store = WP_AI_Bridge\Support\Private_Transfer_Session::for_native_options( new WP_AI_Bridge\Support\Permissions( new Settings() ), $scope );
	$root_method = new ReflectionMethod( $store, 'root' );
	$open_method = new ReflectionMethod( $store, 'open_meta' );
	$save_method = new ReflectionMethod( $store, 'save_meta' );
	$close_method = new ReflectionMethod( $store, 'close_meta' );
	$root = $root_method->invoke( $store );
	wpai199n_assert( is_string( $root ), 'Fixture cannot resolve the trusted private store root.' );
	$opened = $open_method->invoke( $store, $root, $id );
	wpai199n_assert( is_array( $opened ), 'Fixture cannot lock its exact created lease.' );
	list( $handle, $meta ) = $opened;
	try {
		wpai199n_assert( $id === $meta['id'] && get_current_user_id() === $meta['user'] && get_current_blog_id() === $meta['blog'] && 'native_' . $scope . '_settings_read' === $meta['purpose'], 'Expiry injection is not bound to this fixture owner/purpose.' );
		$before = $meta['expires'];
		$meta['expires'] = $expires;
		$args = array( $handle, &$meta );
		wpai199n_assert( true === $save_method->invokeArgs( $store, $args ), 'Fixture expiry checkpoint failed.' );
		return $before;
	} finally {
		$close_method->invoke( $store, $handle );
	}
}
function wpai199n_collect( array $snapshot, $scope = 'site', $caller = null ) {
	$call = null === $caller ? 'wpai199n_call' : $caller;
	$bytes = '';
	$offset = 0;
	$windows = 0;
	do {
		$window = wpai199n_ok( $call( array( 'action' => 'snapshot_read', 'scope' => $scope, 'transfer_id' => $snapshot['transfer_id'], 'offset' => $offset ) ), 'Snapshot continuation failed.' );
		$piece = base64_decode( $window['base64'], true );
		wpai199n_assert( is_string( $piece ) && strlen( $piece ) > 0 && hash( 'sha256', $piece ) === $window['chunk_sha256'], 'Snapshot window lost exact bytes or chunk SHA.' );
		wpai199n_assert( $snapshot['transfer_id'] === $window['transfer_id'] && $offset === $window['offset'] && $snapshot['total_bytes'] === $window['total_bytes'] && $snapshot['sha256'] === $window['sha256'], 'Snapshot continuation identity changed.' );
		$bytes .= $piece;
		wpai199n_assert( strlen( $bytes ) <= $snapshot['total_bytes'], 'Snapshot continuation exceeded declared byte count.' );
		$offset = $window['next_offset'];
		wpai199n_assert( $offset === strlen( $bytes ) && $window['complete'] === ( strlen( $bytes ) === $snapshot['total_bytes'] ), 'Snapshot continuation skipped/repeated bytes or lied about completion.' );
		++$windows;
		wpai199n_assert( $windows < 10000, 'Snapshot continuation made no bounded progress.' );
	} while ( ! $window['complete'] );
	wpai199n_assert( strlen( $bytes ) === $snapshot['total_bytes'] && hash( 'sha256', $bytes ) === $snapshot['sha256'], 'Full private snapshot byte count/SHA mismatch.' );
	return $bytes;
}
