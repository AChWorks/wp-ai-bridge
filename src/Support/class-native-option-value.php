<?php
/**
 * Exact, budgeted representation of a materialized native Options API value.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Binary-safe tagged JSON. Never invokes serialization or object callbacks.
 *
 * Native retrieval already materializes the value. The supplied budget limits
 * additional encoded output/work; it does not make native retrieval streaming.
 */
final class Native_Option_Value {
	/** @var string Incrementally admitted ASCII JSON. */
	private $json = '';

	/** @var int Caller-owned finite encoded byte budget. */
	private $maximum;

	/** @param int $maximum Finite output budget. */
	private function __construct( $maximum ) {
		$this->maximum = $maximum;
	}

	/**
	 * Encode one complete wpai-native-option-value-v1 document.
	 *
	 * @param mixed $value Already materialized native value.
	 * @param int   $max_bytes Maximum complete encoded document bytes.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function encode( $value, $max_bytes ) {
		if ( ! is_int( $max_bytes ) || $max_bytes < 1 ) {
			return new WP_Error( 'native_option_value_budget', 'The native value exceeds the available encoding budget.' );
		}
		$writer = new self( $max_bytes );
		$result = $writer->append( '{"format":"wpai-native-option-value-v1","value":' );
		if ( ! is_wp_error( $result ) ) {
			$result = $writer->tag( $value, 0 );
		}
		if ( ! is_wp_error( $result ) ) {
			$result = $writer->append( '}' );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'json'   => $writer->json,
			'bytes'  => strlen( $writer->json ),
			'sha256' => hash( 'sha256', $writer->json ),
		);
	}

	/** @param string $part ASCII fragment. @return true|WP_Error */
	private function append( $part ) {
		if ( strlen( $part ) > $this->maximum - strlen( $this->json ) ) {
			return new WP_Error( 'native_option_value_budget', 'The native value exceeds the available encoding budget.' );
		}
		$this->json .= $part;
		return true;
	}

	/** @param string $bytes Binary scalar. @return true|WP_Error */
	private function binary_tag( $bytes, $type ) {
		$result = $this->append( '{"type":"' . $type . '","base64":"' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Preflight Base64 expansion before allocating it. Avoid length * 4
		// overflow and never allocate an unbounded expanded temporary tree.
		$length = strlen( $bytes );
		$blocks = intdiv( $length, 3 ) + ( 0 === $length % 3 ? 0 : 1 );
		$room   = $this->maximum - strlen( $this->json ) - 2;
		if ( $room < 0 || $blocks > intdiv( $room, 4 ) ) {
			return new WP_Error( 'native_option_value_budget', 'The native value exceeds the available encoding budget.' );
		}
		$result = $this->append( base64_encode( $bytes ) );
		return is_wp_error( $result ) ? $result : $this->append( '"}' );
	}

	/** @param mixed $value Native node. @param int $depth Representation depth. @return true|WP_Error */
	private function tag( $value, $depth ) {
		if ( $depth > 64 ) {
			return $this->unsupported();
		}
		if ( null === $value ) {
			return $this->append( '{"type":"null"}' );
		}
		if ( is_bool( $value ) ) {
			return $this->append( '{"type":"boolean","value":' . ( $value ? 'true' : 'false' ) . '}' );
		}
		if ( is_int( $value ) ) {
			return $this->append( '{"type":"integer","decimal":"' . (string) $value . '"}' );
		}
		if ( is_float( $value ) ) {
			return $this->binary_tag( pack( 'E', $value ), 'float' );
		}
		if ( is_string( $value ) ) {
			return $this->binary_tag( $value, 'string' );
		}
		if ( ! is_array( $value ) ) {
			return $this->unsupported();
		}
		$result = $this->append( '{"type":"array","entries":[' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$first = true;
		foreach ( $value as $key => $unused ) {
			// Check the original element BEFORE descending. This rejects
			// aliases and recursive references without following either one.
			if ( null !== \ReflectionReference::fromArrayElement( $value, $key ) ) {
				return $this->unsupported();
			}
			$result = $this->append( ( $first ? '' : ',' ) . '{"key":' );
			if ( ! is_wp_error( $result ) ) {
				$result = $this->tag( $key, $depth + 1 );
			}
			if ( ! is_wp_error( $result ) ) {
				$result = $this->append( ',"value":' );
			}
			if ( ! is_wp_error( $result ) ) {
				$result = $this->tag( $value[ $key ], $depth + 1 );
			}
			if ( ! is_wp_error( $result ) ) {
				$result = $this->append( '}' );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$first = false;
		}
		return $this->append( ']}' );
	}

	/** @return WP_Error */
	private function unsupported() {
		return new WP_Error( 'native_option_value_unsupported', 'The native value contains an unsupported type, reference or representation depth.' );
	}
}
