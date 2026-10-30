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

	/** @var bool Explicit v2 opt-in; existing Options contracts remain v1. */
	private $allow_stdclass = false;

	/** @var array<int,bool> Seen object identities; aliases and cycles are rejected. */
	private $objects = array();

	/** @param int $maximum Finite output budget. */
	private function __construct( $maximum ) {
		$this->maximum = $maximum;
	}

	/**
	 * Encode one complete v1 document, or v2 with explicit stdClass opt-in.
	 *
	 * @param mixed $value Already materialized native value.
	 * @param int   $max_bytes Maximum complete encoded document bytes.
	 * @param bool  $allow_stdclass Explicit v2 mode; exact stdClass data only.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function encode( $value, $max_bytes, $allow_stdclass = false ) {
		if ( ! is_int( $max_bytes ) || $max_bytes < 1 ) {
			return new WP_Error( 'native_option_value_budget', 'The native value exceeds the available encoding budget.' );
		}
		if ( ! is_bool( $allow_stdclass ) ) {
			return new WP_Error( 'native_option_value_unsupported', 'The native value codec mode is invalid.' );
		}
		$writer                 = new self( $max_bytes );
		$writer->allow_stdclass = $allow_stdclass;
		$result                 = $writer->append( '{"format":"wpai-native-option-value-v' . ( $allow_stdclass ? '2' : '1' ) . '","value":' );
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
		$object = is_object( $value );
		if ( $object ) {
			if ( ! $this->allow_stdclass || 'stdClass' !== get_class( $value ) || isset( $this->objects[ spl_object_id( $value ) ] ) ) {
				return $this->unsupported();
			}
			$this->objects[ spl_object_id( $value ) ] = true;
			$minimum                                  = 0;
			foreach ( $value as $property => $unused ) {
				$cost = 48 + strlen( (string) $property );
				if ( $cost > $this->maximum - strlen( $this->json ) - $minimum ) {
					return $this->decode_budget();
				}
				$minimum += $cost;
			}
			$value = (array) $value;
		}
		if ( ! is_array( $value ) ) {
			return $this->unsupported();
		}
		$result = $this->append( '{"type":"' . ( $object ? 'stdClass' : 'array' ) . '","entries":[' );
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
				$result = $this->tag( $object ? (string) $key : $key, $depth + 1 );
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

	/** @var string Canonical incoming document; never an expanded tagged AST. */
	private $document = '';

	/** @var int Monotonic byte cursor. */
	private $position = 0;

	/** @var int Absolute admitted PHP allocation ceiling; zero means unknown. */
	private $memory_ceiling = 0;

	/**
	 * Decode only canonical documents produced by this codec.
	 *
	 * This reconstructs a complete PHP value. The caller owns transport/file
	 * admission and reserves memory for subsequent native setter callbacks.
	 *
	 * @param mixed $document Complete canonical v1 document, or v2 in opt-in mode.
	 * @param int   $max_bytes Finite encoded input admission.
	 * @param int   $memory_ceiling_bytes Absolute PHP allocation ceiling, or zero.
	 * @param bool  $allow_stdclass Explicit v2 mode; never accepts class names.
	 * @return array<string,mixed>|WP_Error Internal native value, never a tool result.
	 */
	public static function decode( $document, $max_bytes, $memory_ceiling_bytes = 0, $allow_stdclass = false ) {
		if ( ! is_int( $max_bytes ) || $max_bytes < 1 ||
			! is_int( $memory_ceiling_bytes ) || $memory_ceiling_bytes < 0 ) {
			return new WP_Error( 'native_option_value_budget', 'The native value exceeds the available decoding budget.' );
		}
		if ( ! is_string( $document ) ) {
			return new WP_Error( 'native_option_value_invalid', 'The native value document is not canonical v1 encoding.' );
		}
		if ( strlen( $document ) > $max_bytes ) {
			return new WP_Error( 'native_option_value_budget', 'The native value exceeds the available decoding budget.' );
		}
		if ( ! is_bool( $allow_stdclass ) ) {
			return new WP_Error( 'native_option_value_unsupported', 'The native value codec mode is invalid.' );
		}
		$reader                 = new self( $max_bytes );
		$reader->allow_stdclass = $allow_stdclass;
		$reader->document       = $document;
		$reader->memory_ceiling = $memory_ceiling_bytes;
		if ( ! $reader->memory_fits( 4096 ) ) {
			return $reader->decode_budget();
		}
		if ( ! $reader->consume( '{"format":"wpai-native-option-value-v' . ( $allow_stdclass ? '2' : '1' ) . '","value":' ) ) {
			return $reader->decode_invalid();
		}
		$value = $reader->decode_tag( 0 );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		if ( ! $reader->consume( '}' ) || strlen( $document ) !== $reader->position ) {
			return $reader->decode_invalid();
		}
		if ( ! $reader->memory_fits( 4096 ) ) {
			return $reader->decode_budget();
		}
		return array(
			'value'  => $value,
			'json'   => $document,
			'bytes'  => strlen( $document ),
			'sha256' => hash( 'sha256', $document ),
		);
	}

	/** @param string $literal Exact fixed token. @return bool */
	private function consume( $literal ) {
		$length = strlen( $literal );
		if ( $length > strlen( $this->document ) - $this->position ||
			0 !== substr_compare( $this->document, $literal, $this->position, $length ) ) {
			return false;
		}
		$this->position += $length;
		return true;
	}

	/** @param int $additional Conservative upcoming allocation reserve. @return bool */
	private function memory_fits( $additional ) {
		return 0 === $this->memory_ceiling ||
			( $additional >= 0 && $additional <= $this->memory_ceiling - memory_get_usage( true ) );
	}

	/** @param int $depth Native representation depth. @param bool $key Only int/string permitted. @return mixed */
	private function decode_tag( $depth, $key = false ) {
		if ( $depth > 64 ) {
			return $this->unsupported();
		}
		if ( ! $this->memory_fits( 4096 ) ) {
			return $this->decode_budget();
		}
		if ( ! $key ) {
			if ( $this->consume( '{"type":"null"}' ) ) {
				return null;
			}
			if ( $this->consume( '{"type":"boolean","value":true}' ) ) {
				return true;
			}
			if ( $this->consume( '{"type":"boolean","value":false}' ) ) {
				return false;
			}
			if ( $this->consume( '{"type":"float","base64":"' ) ) {
				$bytes = $this->decode_binary();
				if ( is_wp_error( $bytes ) ) {
					return $bytes;
				}
				if ( 8 !== strlen( $bytes ) ) {
					return $this->decode_invalid();
				}
				$parts = unpack( 'Evalue', $bytes );
				if ( ! is_array( $parts ) || ! is_float( $parts['value'] ?? null ) ||
					pack( 'E', $parts['value'] ) !== $bytes ) {
					return $this->unsupported();
				}
				return $parts['value'];
			}
		}
		if ( $this->consume( '{"type":"integer","decimal":"' ) ) {
			return $this->decode_integer();
		}
		if ( $this->consume( '{"type":"string","base64":"' ) ) {
			return $this->decode_binary();
		}
		if ( ! $key && $this->consume( '{"type":"array","entries":[' ) ) {
			return $this->decode_entries( $depth );
		}
		if ( ! $key && $this->allow_stdclass && $this->consume( '{"type":"stdClass","entries":[' ) ) {
			return $this->decode_object( $depth );
		}
		return $this->decode_invalid();
	}

	/** @return int|WP_Error Exact canonical machine integer. */
	private function decode_integer() {
		$end = strpos( $this->document, '"', $this->position );
		if ( false === $end ) {
			return $this->decode_invalid();
		}
		$length   = $end - $this->position;
		$negative = '-' === ( $this->document[ $this->position ] ?? '' );
		$start    = $this->position + ( $negative ? 1 : 0 );
		$digits   = $length - ( $negative ? 1 : 0 );
		if ( $digits < 1 || strspn( $this->document, '0123456789', $start, $digits ) !== $digits ||
			( '0' === $this->document[ $start ] && ( $negative || $digits > 1 ) ) ) {
			return $this->decode_invalid();
		}
		$bound = $negative ? substr( (string) PHP_INT_MIN, 1 ) : (string) PHP_INT_MAX;
		if ( $digits > strlen( $bound ) ||
			( strlen( $bound ) === $digits && substr_compare( $this->document, $bound, $start, $digits ) > 0 ) ) {
			return $this->unsupported();
		}
		$text           = substr( $this->document, $this->position, $length );
		$this->position = $end;
		if ( ! $this->consume( '"}' ) ) {
			return $this->decode_invalid();
		}
		$value = (int) $text;
		return (string) $value === $text ? $value : $this->decode_invalid();
	}

	/** @return string|WP_Error Canonical binary scalar, without JSON unescaping. */
	private function decode_binary() {
		$end = strpos( $this->document, '"', $this->position );
		if ( false === $end ) {
			return $this->decode_invalid();
		}
		$length = $end - $this->position;
		if ( 0 !== $length % 4 ) {
			return $this->decode_invalid();
		}
		$padding = 0;
		if ( $length > 0 && '=' === $this->document[ $end - 1 ] ) {
			$padding = $length > 1 && '=' === $this->document[ $end - 2 ] ? 2 : 1;
		}
		if ( strspn( $this->document, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', $this->position, $length - $padding ) !== $length - $padding ) {
			return $this->decode_invalid();
		}
		// Substring + decoded bytes + canonical Base64 comparison are all
		// live temporarily. Admit their combined upper bound before allocation.
		if ( $length > intdiv( PHP_INT_MAX - 4096, 3 ) || ! $this->memory_fits( $length * 3 + 4096 ) ) {
			return $this->decode_budget();
		}
		$encoded = substr( $this->document, $this->position, $length );
		$decoded = base64_decode( $encoded, true );
		if ( ! is_string( $decoded ) || base64_encode( $decoded ) !== $encoded ) {
			return $this->decode_invalid();
		}
		$this->position = $end;
		return $this->consume( '"}' ) ? $decoded : $this->decode_invalid();
	}

	/** @param int $depth Parent array depth. @return array|WP_Error */
	private function decode_entries( $depth ) {
		$value = array();
		$first = true;
		while ( ! $this->consume( ']}' ) ) {
			if ( ! $first && ! $this->consume( ',' ) ) {
				return $this->decode_invalid();
			}
			if ( ! $this->consume( '{"key":' ) ) {
				return $this->decode_invalid();
			}
			$key = $this->decode_tag( $depth + 1, true );
			if ( is_wp_error( $key ) ) {
				return $key;
			}
			if ( is_string( $key ) ) {
				$probe = array( $key => true );
				$exact = array_key_first( $probe );
				unset( $probe );
				if ( ! is_string( $exact ) || $exact !== $key ) {
					return $this->decode_invalid();
				}
			}
			if ( array_key_exists( $key, $value ) || ! $this->consume( ',"value":' ) ) {
				return $this->decode_invalid();
			}
			$part = $this->decode_tag( $depth + 1 );
			if ( is_wp_error( $part ) ) {
				return $part;
			}
			if ( ! $this->consume( '}' ) ) {
				return $this->decode_invalid();
			}
			// Reserve potential native hash-table growth, not a node quota.
			// Current actual allocation is rechecked on each following node.
			$count = count( $value ) + 1;
			if ( $count > intdiv( PHP_INT_MAX - 4096, 128 ) ||
				! $this->memory_fits( $count * 128 + 4096 ) ) {
				return $this->decode_budget();
			}
			$value[ $key ] = $part;
			$first         = false;
		}
		return $value;
	}

	/** @param int $depth Parent object depth. @return \stdClass|WP_Error */
	private function decode_object( $depth ) {
		$properties = array();
		$seen       = array();
		$first      = true;
		while ( ! $this->consume( ']}' ) ) {
			if ( ( ! $first && ! $this->consume( ',' ) ) || ! $this->consume( '{"key":' ) ) {
				return $this->decode_invalid();
			}
			$key = $this->decode_tag( $depth + 1, true );
			if ( is_wp_error( $key ) ) {
				return $key;
			}
			if ( ! is_string( $key ) || isset( $seen[ ':' . base64_encode( $key ) ] ) || ! $this->consume( ',"value":' ) ) {
				return $this->decode_invalid();
			}
			$part = $this->decode_tag( $depth + 1 );
			if ( is_wp_error( $part ) ) {
				return $part;
			}
			if ( ! $this->consume( '}' ) ) {
				return $this->decode_invalid();
			}
			$count = count( $properties ) + 1;
			if ( $count > intdiv( PHP_INT_MAX - 4096, 512 ) || ! $this->memory_fits( $count * 512 + 4096 ) ) {
				return $this->decode_budget();
			}
			$seen[ ':' . base64_encode( $key ) ] = true;
			$properties[ $key ]                  = $part;
			$first                               = false;
		}
		// Native array-to-stdClass cast preserves binary/NUL and numeric property
		// names. Dynamic property assignment rejects some otherwise valid names.
		return (object) $properties;
	}

	/** @return WP_Error Redacted syntax/canonicality error. */
	private function decode_invalid() {
		return new WP_Error( 'native_option_value_invalid', 'The native value document is not canonical v1 encoding.' );
	}

	/** @return WP_Error Redacted resource admission error. */
	private function decode_budget() {
		return new WP_Error( 'native_option_value_budget', 'The native value exceeds the available decoding budget.' );
	}

	/** @return WP_Error */
	private function unsupported() {
		return new WP_Error( 'native_option_value_unsupported', 'The native value contains an unsupported type, reference or representation depth.' );
	}
}
