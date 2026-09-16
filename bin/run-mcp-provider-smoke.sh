#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
compose=(docker compose -f "$compose_file")
wp=("${compose[@]}" run --rm cli)

command -v jq >/dev/null 2>&1 || {
    echo "ERROR: jq is required for the MCP provider integration smoke." >&2
    exit 1
}

request_file="$(mktemp /tmp/wpai-provider-request.XXXXXX)"
output_file="$(mktemp /tmp/wpai-provider-output.XXXXXX)"
cleanup() {
    rm -f "$request_file" "$output_file"
}
trap cleanup EXIT

run_mcp() {
    local payload="$1"
    printf '%s\n' "$payload" > "$request_file"
    "${compose[@]}" run -T --rm cli mcp-adapter serve --server=mcp-adapter-default-server --user=1 --allow-root < "$request_file" > "$output_file" 2>/dev/null
}

"${wp[@]}" eval 'use WP_Native_Builder_Bridge\Support\Settings; $s=new Settings(); $a=$s->defaults(); $a[Settings::GROUP_SITE_READ]=1; $a[Settings::GROUP_BUILDER_WRITE]=1; $a[Settings::GROUP_CODE_EXTENSIONS]=1; update_option(Settings::OPTION_NAME,$a,false);' --user=1 --allow-root >/dev/null

if "${wp[@]}" plugin is-active code-snippets --allow-root >/dev/null 2>&1; then
    run_mcp '{"jsonrpc":"2.0","id":101,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/snippets-read","parameters":{"action":"list"}}}}'
    jq -e '.result.structuredContent.success == true' "$output_file" >/dev/null
    echo "Code Snippets fallback through raw MCP: PASS"
fi

if [[ "$("${wp[@]}" option get stylesheet --allow-root | tail -n 1)" == "astra" ]]; then
    run_mcp '{"jsonrpc":"2.0","id":102,"method":"tools/call","params":{"name":"mcp-adapter-get-ability-info","arguments":{"ability_name":"astra/get-performance"}}}'
    jq -e '.result.structuredContent.name == "astra/get-performance"' "$output_file" >/dev/null

    run_mcp '{"jsonrpc":"2.0","id":103,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"astra/get-performance","parameters":{}}}}'
    jq -e '.result.structuredContent.success == true' "$output_file" >/dev/null

    run_mcp '{"jsonrpc":"2.0","id":104,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/integration-status","parameters":{}}}}'
    jq -e '.result.structuredContent.success == true and .result.structuredContent.data.astra.mode == "ability" and (.result.structuredContent.data.astra.ability_names | length > 0)' "$output_file" >/dev/null
    echo "Astra native Ability reuse through raw MCP: PASS"
fi

if "${wp[@]}" eval 'echo class_exists("GFAPI") ? "yes" : "no";' --allow-root | tail -n 1 | grep -Fxq yes; then
    run_mcp '{"jsonrpc":"2.0","id":105,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/gravity-form-upsert","parameters":{"action":"create","form":{"title":"WPNB GFAPI transport contract","description":"Test-only GFAPI contract fixture","fields":[]}}}}}'
    jq -e '.result.structuredContent.success == true and .result.structuredContent.data.form.title == "WPNB GFAPI transport contract"' "$output_file" >/dev/null
    run_mcp '{"jsonrpc":"2.0","id":106,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/gravity-forms-read","parameters":{"action":"list"}}}}'
    jq -e '.result.structuredContent.success == true and (.result.structuredContent.data.items | any(.title == "WPNB GFAPI readable fixture"))' "$output_file" > /dev/null
    echo "GFAPI create/read contract through raw MCP: PASS (commercial Gravity Forms binary not present)"
fi

"${wp[@]}" eval 'use WP_Native_Builder_Bridge\Support\Settings; $s=new Settings(); update_option(Settings::OPTION_NAME,$s->defaults(),false);' --user=1 --allow-root >/dev/null

echo "PASS: Issue #6 optional provider MCP smoke."
