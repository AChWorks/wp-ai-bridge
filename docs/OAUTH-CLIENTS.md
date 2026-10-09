# Approved OAuth clients

WP AI Bridge keeps **connection eligibility** separate from WordPress and Bridge operation authority.

## Built-in ChatGPT compatibility

`https://chatgpt.com/oauth/client.json` remains the built-in client. Existing direct ChatGPT OAuth behavior needs no administrator migration or additional setting, and historical ChatGPT access/refresh artifacts do not depend on the additional-client approval revision.

## Additional clients

An administrator may approve up to ten exact public HTTPS Client ID Metadata Document URLs under **WP AI Bridge → OAuth Clients**. The default is an empty list on fresh installs and upgrades. Approval does not enable any Bridge access group, WordPress capability, Ability, or Gateway-specific permission path.

Each approved metadata document must self-identify with the exact approved `client_id` and declare:

```json
{
  "client_id": "https://gateway.example.com/oauth/client.json",
  "client_name": "Example MCP Gateway",
  "redirect_uris": ["https://gateway.example.com/oauth/callback"],
  "grant_types": ["authorization_code", "refresh_token"],
  "response_types": ["code"],
  "token_endpoint_auth_method": "private_key_jwt",
  "jwks_uri": "https://gateway.example.com/oauth/jwks.json"
}
```

The Bridge fetches metadata and JWKS only through bounded, redirect-disabled WordPress safe HTTP requests to validated public HTTPS targets. Client assertions are RS256 `private_key_jwt` values with exact `iss=sub=client_id`, an accepted Bridge token/revocation audience (or issuer), bounded lifetime, and a unique `jti`. JWKS rotation is cache-bounded and an unknown key can trigger only a rate-limited refresh.

Authorization still uses S256 PKCE. Authorization codes, access tokens, refresh tokens, redirect URIs, MCP resource, WordPress user and scopes remain bound to the exact authenticated client. A client cannot revoke another client's artifact.

Refresh tokens remain rotating and one-time. If a successful refresh response is lost after the Bridge commits rotation, the same authenticated client may retry the exact old refresh token once within the Bridge's 60-second recovery window. The retry must use fresh `private_key_jwt` authentication and the same resource and effective scope. The Bridge returns the exact already-committed successor pair; it does not mint another generation. Wrong-client/resource/scope retries, expired/tampered state, authorization or approved-client revision changes, and revoked/expired successors fail closed.

## Durable idle OAuth client maintenance

A connection granted **`offline_access`** returns a rotating refresh token and a non-secret, additive **`refresh_token_expires_in`** integer in the OAuth token response, alongside standard access-token `expires_in`. The refresh hint is measured in **seconds at token issuance** and applies to the newly issued refresh token, including each successful refresh-token rotation. An authorization without `offline_access` has neither a refresh token nor this hint. This response hint is a documented OAuth token-response extension, not a new scope, persistent bearer, or guarantee that a revoked token remains usable until its advertised lifetime.

A trusted client that wants an idle connection to remain authorized **must independently schedule a periodic refresh before the finite refresh-token expiry**, even when it has not sent MCP traffic and even while the access token is still valid. Read the actual current `refresh_token_expires_in` from the **most recent** token response, choose a conservative interval well inside its lifetime (for example, no later than halfway through the reported period), keep the refresh endpoint `resource` and original scopes/client identity unchanged, and atomically persist the rotated successor before retiring the predecessor. Recalculate the next deadline from each successor; do not hardcode any Bridge refresh-TTL constant in a client. An inactive client beyond the lifetime must reauthorize interactively; Bridge will not silently mint non-expiring credentials.

The previously documented one-shot **60-second recovery** handles an ambiguous *lost token response* after a committed rotation. An exact old-token retry requires the same freshly authenticated approved client and authorization bindings. Its recovered response is **byte-identical** to the committed response (so the lifetime hint is the original **issue-time** duration, not a new time window). Do not use recovery to prolong an idle expired token; keep a safety margin and avoid concurrent refreshes of the same token.

An OAuth offline grant is independent of the user's **WordPress browser login session**: logging out of WordPress does not itself revoke it. Explicit OAuth token revocation, removal or revision of approved client, user deletion/loss of native authorization, expired artifacts, wrong resource/scope and other terminal security conditions remain fail-closed. Periodic maintenance belongs in each trusted client's own scheduler (e.g., Gateway), **not** a WordPress Cron keepalive or provider-specific daemon in the Bridge.

## Revocation behavior

Changing the approved additional-client list rotates a shared additional-client approval revision. This intentionally invalidates every outstanding non-ChatGPT consent/code/access/refresh artifact immediately. Re-adding the same client later does not resurrect artifacts issued under an older revision. ChatGPT remains independent from this revision.

This conservative behavior makes removal a real revocation boundary rather than a temporary allowlist toggle.

## Gateway contract

An independently hosted MCP Gateway should publish its own stable HTTPS client metadata and JWKS, keep the corresponding private signing key only on the Gateway, and use its own exact redirect URI. Do not reuse ChatGPT's client identity, share a plaintext client secret with WordPress, or exchange WordPress credentials.

After OAuth connection, every MCP operation still passes the same WP AI Bridge access-group checks, WordPress principal/capability checks, Ability policy, and provider/Core authorization that apply to direct ChatGPT connections.
