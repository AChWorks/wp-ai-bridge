<?php
/**
 * Trusted installed-provider contract for one targeted, replay-safe Cron effect.
 *
 * This file deliberately follows the Bridge class autoloader convention even
 * though the declared symbol is an interface.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * Providers must implement their OWN durable idempotency, including the
 * corresponding normally scheduled callback. There is no generic do_action.
 *
 * The context contains only an exact existing native event, immutable
 * installed-provider arguments, a stable operation ID, and a time budget.
 * Calling run_once is a real effect. On lost/unknown response the Bridge
 * reserves the event and never automatically calls it again.
 */
interface Cron_Targeted_Runner {
	/**
	 * Execute the exact targeted effect once; enforce the supplied deadline.
	 * Return array( 'status' => 'completed', 'effect_ref' => 64 lowercase hex ).
	 * Any other return is an unverified outcome, not evidence of no effect.
	 *
	 * @param array<string,mixed> $context Trusted server-supplied context.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run_once( $context );

	/**
	 * Read-only check of the provider's durable effect receipt.
	 * Do not emit raw receipts, credentials, or callback output to MCP.
	 *
	 * @param array<string,mixed> $context Exact server-supplied context.
	 * @param string              $effect_ref 64-hex provider effect identity.
	 * @return bool Strict true only if independent durable effect is confirmed.
	 */
	public function verify( $context, $effect_ref );
}
