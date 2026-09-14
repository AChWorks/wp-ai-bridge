#!/usr/bin/env bash
set -euo pipefail

base_url="${WPNB_HTTP_BASE_URL:-http://127.0.0.1:18080}"
public_origin="${WPNB_PUBLIC_ORIGIN:-https://localhost}"
host_header="${WPNB_HTTP_HOST:-localhost}"
resource="${public_origin}/wp-json/wp-ai-bridge/v1/mcp"
legacy_resource="${public_origin}/wp-json/wp-native-builder/v1/mcp"
protected_metadata="${public_origin}/.well-known/oauth-protected-resource"
legacy_protected_metadata="${public_origin}/.well-known/oauth-protected-resource/wp-native-builder/v1/mcp"
authorization_metadata="${public_origin}/.well-known/oauth-authorization-server"

command -v curl >/dev/null 2>&1 || { echo 'ERROR: curl is required.' >&2; exit 1; }
command -v jq >/dev/null 2>&1 || { echo 'ERROR: jq is required.' >&2; exit 1; }

protected_file="$(mktemp /tmp/wpnb-protected-resource.XXXXXX)"
authorization_file="$(mktemp /tmp/wpnb-authorization-server.XXXXXX)"
legacy_protected_file="$(mktemp /tmp/wpnb-legacy-protected-resource.XXXXXX)"
headers_file="$(mktemp /tmp/wpnb-mcp-headers.XXXXXX)"
body_file="$(mktemp /tmp/wpnb-mcp-body.XXXXXX)"
cleanup() {
    rm -f "$protected_file" "$legacy_protected_file" "$authorization_file" "$headers_file" "$body_file"
}
trap cleanup EXIT

curl -fsS -H "Host: ${host_header}" "${base_url}/.well-known/oauth-protected-resource" > "$protected_file"
jq -e \
    --arg resource "$resource" \
    --arg issuer "$public_origin" \
    '.resource == $resource
     and (.authorization_servers == [$issuer])
     and (.scopes_supported | index("mcp:use") != null)
     and (.scopes_supported | index("offline_access") != null)
     and (.bearer_methods_supported == ["header"])' \
    "$protected_file" >/dev/null

curl -fsS -H "Host: ${host_header}" "${base_url}/.well-known/oauth-protected-resource/wp-native-builder/v1/mcp" > "$legacy_protected_file"
jq -e \
    --arg resource "$legacy_resource" \
    --arg issuer "$public_origin" \
    '.resource == $resource
     and (.authorization_servers == [$issuer])
     and (.scopes_supported | index("mcp:use") != null)
     and (.scopes_supported | index("offline_access") != null)
     and (.bearer_methods_supported == ["header"])' \
    "$legacy_protected_file" >/dev/null

curl -fsS -H "Host: ${host_header}" "${base_url}/.well-known/oauth-authorization-server" > "$authorization_file"
jq -e \
    --arg issuer "$public_origin" \
    --arg authorization_endpoint "${public_origin}/wp-ai-bridge/oauth/authorize" \
    --arg token_endpoint "${public_origin}/wp-json/wp-ai-bridge/v1/oauth/token" \
    '.issuer == $issuer
     and .authorization_endpoint == $authorization_endpoint
     and .token_endpoint == $token_endpoint
     and .client_id_metadata_document_supported == true
     and .authorization_response_iss_parameter_supported == true
     and (.grant_types_supported | index("authorization_code") != null)
     and (.grant_types_supported | index("refresh_token") != null)
     and (.code_challenge_methods_supported == ["S256"])
     and (.token_endpoint_auth_methods_supported == ["private_key_jwt"])
     and (.token_endpoint_auth_signing_alg_values_supported == ["RS256"])' \
    "$authorization_file" >/dev/null

status="$(curl -sS \
    -H "Host: ${host_header}" \
    -H 'Accept: application/json, text/event-stream' \
    -H 'Content-Type: application/json' \
    -D "$headers_file" \
    -o "$body_file" \
    -w '%{http_code}' \
    -X POST \
    --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"wpnb-direct-http-smoke","version":"1.0.0"}}}' \
    "${base_url}/wp-json/wp-ai-bridge/v1/mcp")"

if [[ "$status" != "401" ]]; then
    echo "ERROR: unauthenticated direct MCP request returned HTTP ${status}, expected 401." >&2
    cat "$body_file" >&2
    exit 1
fi

tr -d '\r' < "$headers_file" | grep -Fqi "WWW-Authenticate: Bearer resource_metadata=\"${protected_metadata}\""
tr -d '\r' < "$headers_file" | grep -Fqi 'Cache-Control: no-store'

legacy_status="$(curl -sS \
    -H "Host: ${host_header}" \
    -H 'Accept: application/json, text/event-stream' \
    -H 'Content-Type: application/json' \
    -D "$headers_file" \
    -o "$body_file" \
    -w '%{http_code}' \
    -X POST \
    --data '{"jsonrpc":"2.0","id":2,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"wpnb-legacy-http-smoke","version":"1.0.0"}}}' \
    "${base_url}/wp-json/wp-native-builder/v1/mcp")"
if [[ "$legacy_status" != "401" ]]; then
    echo "ERROR: unauthenticated legacy MCP request returned HTTP ${legacy_status}, expected 401." >&2
    cat "$body_file" >&2
    exit 1
fi
tr -d '\r' < "$headers_file" | grep -Fqi "WWW-Authenticate: Bearer resource_metadata=\"${legacy_protected_metadata}\""
tr -d '\r' < "$headers_file" | grep -Fqi 'Cache-Control: no-store'

# RFC 9207 issuer identification must be present even on authorization errors
# when the fixed ChatGPT client and redirect are otherwise valid.
authorize_status="$(curl -sS \
    -H "Host: ${host_header}" \
    -D "$headers_file" \
    -o "$body_file" \
    -w '%{http_code}' \
    --get \
    --data-urlencode 'client_id=https://chatgpt.com/oauth/client.json' \
    --data-urlencode 'redirect_uri=https://chatgpt.com/connector_platform_oauth_redirect' \
    --data-urlencode 'response_type=token' \
    --data-urlencode "resource=${resource}" \
    --data-urlencode 'scope=mcp:use offline_access' \
    --data-urlencode 'state=wpnb-http-state' \
    --data-urlencode 'code_challenge=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' \
    --data-urlencode 'code_challenge_method=S256' \
    "${base_url}/wp-ai-bridge/oauth/authorize")"

if [[ "$authorize_status" != "302" ]]; then
    echo "ERROR: valid ChatGPT callback did not receive an OAuth error redirect; HTTP ${authorize_status}." >&2
    cat "$body_file" >&2
    exit 1
fi

authorize_headers="$(tr -d '\r' < "$headers_file")"
for expected_header_fragment in \
    'Location: https://chatgpt.com/connector_platform_oauth_redirect?' \
    'error=unsupported_response_type' \
    'iss=https://localhost' \
    'state=wpnb-http-state'
do
    if ! grep -Fqi "$expected_header_fragment" <<< "$authorize_headers"; then
        echo "ERROR: OAuth error redirect is missing expected header fragment: $expected_header_fragment" >&2
        printf '%s\n' "$authorize_headers" >&2
        exit 1
    fi
done

# An untrusted redirect URI must never become an OAuth error redirect target.
open_redirect_status="$(curl -sS \
    -H "Host: ${host_header}" \
    -D "$headers_file" \
    -o "$body_file" \
    -w '%{http_code}' \
    --get \
    --data-urlencode 'client_id=https://chatgpt.com/oauth/client.json' \
    --data-urlencode 'redirect_uri=https://attacker.invalid/callback' \
    --data-urlencode 'response_type=token' \
    --data-urlencode "resource=${resource}" \
    --data-urlencode 'scope=mcp:use' \
    --data-urlencode 'state=wpnb-open-redirect-test' \
    --data-urlencode 'code_challenge=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' \
    --data-urlencode 'code_challenge_method=S256' \
    "${base_url}/wp-ai-bridge/oauth/authorize")"

if [[ "$open_redirect_status" != "400" ]]; then
    echo "ERROR: untrusted OAuth redirect URI returned HTTP ${open_redirect_status}, expected 400." >&2
    exit 1
fi

if tr -d '\r' < "$headers_file" | grep -qi '^Location:'; then
    echo 'ERROR: untrusted OAuth redirect URI produced a Location header.' >&2
    exit 1
fi

legacy_authorize_status="$(curl -sS \
    -H "Host: ${host_header}" \
    -D "$headers_file" \
    -o "$body_file" \
    -w '%{http_code}' \
    --get \
    --data-urlencode 'client_id=https://chatgpt.com/oauth/client.json' \
    --data-urlencode 'redirect_uri=https://chatgpt.com/connector_platform_oauth_redirect' \
    --data-urlencode 'response_type=token' \
    --data-urlencode "resource=${legacy_resource}" \
    --data-urlencode 'scope=mcp:use offline_access' \
    --data-urlencode 'state=wpnb-legacy-http-state' \
    --data-urlencode 'code_challenge=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' \
    --data-urlencode 'code_challenge_method=S256' \
    "${base_url}/wp-native-builder/oauth/authorize")"
if [[ "$legacy_authorize_status" != "302" ]]; then
    echo "ERROR: legacy authorization alias returned HTTP ${legacy_authorize_status}, expected 302." >&2
    cat "$body_file" >&2
    exit 1
fi
legacy_authorize_headers="$(tr -d '\r' < "$headers_file")"
for expected_header_fragment in \
    'Location: https://chatgpt.com/connector_platform_oauth_redirect?' \
    'error=unsupported_response_type' \
    'iss=https://localhost' \
    'state=wpnb-legacy-http-state'
do
    if ! grep -Fqi "$expected_header_fragment" <<< "$legacy_authorize_headers"; then
        echo "ERROR: legacy OAuth alias redirect is missing expected header fragment: $expected_header_fragment" >&2
        printf '%s\n' "$legacy_authorize_headers" >&2
        exit 1
    fi
done

echo 'PASS: canonical and legacy OAuth discovery/MCP challenge, authorization aliases, issuer error redirect, and open-redirect rejection over real HTTP.'
