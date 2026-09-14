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

## Revocation behavior

Changing the approved additional-client list rotates a shared additional-client approval revision. This intentionally invalidates every outstanding non-ChatGPT consent/code/access/refresh artifact immediately. Re-adding the same client later does not resurrect artifacts issued under an older revision. ChatGPT remains independent from this revision.

This conservative behavior makes removal a real revocation boundary rather than a temporary allowlist toggle.

## Gateway contract

An independently hosted MCP Gateway should publish its own stable HTTPS client metadata and JWKS, keep the corresponding private signing key only on the Gateway, and use its own exact redirect URI. Do not reuse ChatGPT's client identity, share a plaintext client secret with WordPress, or exchange WordPress credentials.

After OAuth connection, every MCP operation still passes the same WP AI Bridge access-group checks, WordPress principal/capability checks, Ability policy, and provider/Core authorization that apply to direct ChatGPT connections.
