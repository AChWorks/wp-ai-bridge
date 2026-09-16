<?php
/**
 * Provider-neutral metadata key exclusion policy.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/** Shared unchanged credential-name policy for Advanced Metadata. */
final class Metadata_Key_Policy {
	/**
	 * Checks common credential, session, and security key forms.
	 *
	 * @param string $key Exact metadata key.
	 * @return bool
	 */
	public static function is_sensitive( $key ) {
		$bounded    = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '_', (string) $key );
		$normalized = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $bounded ) );
		$normalized = trim( $normalized, '_' );
		$compact    = str_replace( '_', '', $normalized );

		$single = '(passwords?|passwds?|secrets?|credentials?)';
		$pairs  = '(access|refresh|bearer|auth|oauth|api|session|identity|id|jwt|security|csrf)_(tokens?)'
			. '|(api)_(keys?)'
			. '|(private)_(keys?)'
			. '|(application)_(passwords?)'
			. '|(client|consumer)_(secrets?)';
		if ( 1 === preg_match( '/(^|_)(' . $single . '|' . $pairs . ')($|_)/', $normalized ) ) {
			return true;
		}

		$compact_pairs = '(accesstokens?|refreshtokens?|bearertokens?|authtokens?|oauthtokens?|apitokens?|sessiontokens?|identitytokens?|idtokens?|jwttokens?|securitytokens?|csrftokens?|apikeys?|privatekeys?|applicationpasswords?|clientsecrets?|consumersecrets?)';
		return 1 === preg_match( '/(' . $single . '|' . $compact_pairs . ')$/', $compact );
	}
}
