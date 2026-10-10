#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
compose=(docker compose -f "$compose_file")

command -v jq >/dev/null 2>&1 || {
    echo "ERROR: jq is required for the MCP STDIO integration smoke." >&2
    exit 1
}

wp=("${compose[@]}" run --rm cli)
output_file="$(mktemp /tmp/wpai-mcp-output.XXXXXX)"
request_file="$(mktemp /tmp/wpai-mcp-request.XXXXXX)"
normalized_file="$(mktemp /tmp/wpai-mcp-normalized.XXXXXX)"
post_id=""
media_id=""
original_tagline="$("${wp[@]}" option get blogdescription --allow-root | tail -n 1)"

cleanup() {
    if [[ -n "$post_id" ]]; then
        "${wp[@]}" post delete "$post_id" --force --allow-root >/dev/null 2>&1 || true
    fi
    if [[ -n "$media_id" ]]; then
        "${wp[@]}" post delete "$media_id" --force --allow-root >/dev/null 2>&1 || true
    fi
    "${wp[@]}" option update blogdescription "$original_tagline" --allow-root >/dev/null 2>&1 || true
    "${wp[@]}" eval 'use WP_AI_Bridge\Support\Settings; $s=new Settings(); update_option(Settings::OPTION_NAME,$s->defaults(),false);' --user=1 --allow-root >/dev/null 2>&1 || true
    rm -f "$output_file" "$request_file" "$normalized_file"
}
trap cleanup EXIT

run_mcp() {
    local payload="$1"
    local request_id
    request_id="$(jq -re '.id | select(type == "number" or type == "string")' <<< "$payload")"

    # Each call uses a new STDIO process. Complete the 2025 MCP lifecycle
    # on that stream before executing a tool, as required by Adapter 0.7.0.
    printf '%s\n' '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"wpai-stdio-smoke","version":"1.0"}}}' '{"jsonrpc":"2.0","method":"notifications/initialized"}' "$payload" > "$request_file"
    "${compose[@]}" run -T --rm cli mcp-adapter serve --server=mcp-adapter-default-server --user=1 --allow-root < "$request_file" > "$output_file" 2>/dev/null

    # Require an exact successful initialize followed by the requested result.
    if ! jq -se --argjson id "$request_id" 'length == 2 and .[0].id == 0 and .[0].result.protocolVersion == "2025-11-25" and .[1].id == $id and (.[1].error == null)' "$output_file" >/dev/null; then
        echo 'ERROR: STDIO initialize or exact tool result failed.' >&2
        exit 1
    fi
    jq -sc '.[1]' "$output_file" > "$normalized_file"
    cp -- "$normalized_file" "$output_file"
}

"${wp[@]}" eval 'use WP_AI_Bridge\Support\Settings; $s=new Settings(); $a=$s->defaults(); $a[Settings::GROUP_SITE_READ]=1; $a[Settings::GROUP_BUILDER_WRITE]=1; $a[Settings::GROUP_SITE_CONFIG]=1; update_option(Settings::OPTION_NAME,$a,false);' --user=1 --allow-root >/dev/null

run_mcp '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
jq -e '.result.tools | map(.name) | sort == ["mcp-adapter-discover-abilities","mcp-adapter-execute-ability","mcp-adapter-get-ability-info"]' "$output_file" >/dev/null

echo "MCP tools/list: PASS"

run_mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}'
grep -Fq 'wp-ai-bridge/bridge-info' "$output_file"
grep -Fq 'wp-ai-bridge/content-upsert' "$output_file"
grep -Fq 'wp-ai-bridge/workspace-resume' "$output_file"
grep -Fq 'wp-ai-bridge/workspace-document' "$output_file"
grep -Fq 'wp-ai-bridge/workspace-task' "$output_file"

run_mcp '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp-adapter-get-ability-info","arguments":{"ability_name":"wp-ai-bridge/bridge-info"}}}'
jq -e '.result.structuredContent.name == "wp-ai-bridge/bridge-info"' "$output_file" >/dev/null

run_mcp '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/bridge-info","parameters":{}}}}'
jq -e '.result.structuredContent.success == true' "$output_file" >/dev/null

run_mcp '{"jsonrpc":"2.0","id":41,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/workspace-resume","parameters":{}}}}'
jq -e '.result.structuredContent.success == true and (.result.structuredContent.data.counts | type == "object")' "$output_file" >/dev/null

echo "MCP discover/get-info/read + Workspace resume: PASS"
run_mcp '{"jsonrpc":"2.0","id":420,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/abilities-read","parameters":{"namespace":"wp-ai-bridge","per_page":1,"page":2}}}}'
jq -e '.result.structuredContent.success == true and .result.structuredContent.data.page == 2 and (.result.structuredContent.data.items | length == 1) and .result.structuredContent.data.execution_permission == "not_evaluated"' "$output_file" >/dev/null
run_mcp '{"jsonrpc":"2.0","id":421,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/abilities-read","parameters":{"action":"get","name":"wp-ai-bridge/bridge-info"}}}}'
jq -e '.result.structuredContent.success == true and .result.structuredContent.data.items[0].name == "wp-ai-bridge/bridge-info" and .result.structuredContent.data.items[0].input_schema.type == "object"' "$output_file" >/dev/null
echo "MCP paginated contract list and exact schema: PASS"

run_mcp '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/content-upsert","parameters":{"action":"create","post_type":"post","title":"WPAI MCP CI draft","content":"<!-- wp:paragraph --><p>Initial MCP CI content</p><!-- /wp:paragraph -->","status":"draft"}}}}'
post_id="$(jq -r '.result.structuredContent.data.id' "$output_file")"
[[ "$post_id" =~ ^[1-9][0-9]*$ ]]

run_mcp "{\"jsonrpc\":\"2.0\",\"id\":6,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"wp-ai-bridge/blocks-read\",\"parameters\":{\"post_id\":$post_id}}}}"
modified="$(jq -r '.result.structuredContent.data.modified_gmt' "$output_file")"
content_hash="$(jq -r '.result.structuredContent.data.content_hash' "$output_file")"
[[ ${#content_hash} -eq 64 ]]

run_mcp "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"wp-ai-bridge/blocks-mutate\",\"parameters\":{\"post_id\":$post_id,\"action\":\"append\",\"block_markup\":\"<!-- wp:paragraph --><p>Appended through MCP CI</p><!-- /wp:paragraph -->\",\"expected_modified_gmt\":\"$modified\",\"expected_content_hash\":\"$content_hash\"}}}}"
jq -e '.result.structuredContent.success == true' "$output_file" >/dev/null
"${wp[@]}" post get "$post_id" --field=content --allow-root | grep -Fq 'Appended through MCP CI'

echo "MCP draft + targeted Gutenberg mutation: PASS"

run_mcp "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"wp-ai-bridge/content-read\",\"parameters\":{\"action\":\"get\",\"id\":$post_id}}}}"
modified="$(jq -r '.result.structuredContent.data.items[0].modified_gmt' "$output_file")"
state_hash="$(jq -r '.result.structuredContent.data.items[0].state_hash' "$output_file")"
[[ ${#state_hash} -eq 64 ]]

run_mcp "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"wp-ai-bridge/content-upsert\",\"parameters\":{\"action\":\"update\",\"id\":$post_id,\"status\":\"publish\",\"expected_modified_gmt\":\"$modified\",\"expected_state_hash\":\"$state_hash\"}}}}"
jq -e '.result.isError == true' "$output_file" >/dev/null

"${wp[@]}" eval 'use WP_AI_Bridge\Support\Settings; $a=get_option(Settings::OPTION_NAME,array()); $a[Settings::GROUP_LIVE_CONTENT]=1; update_option(Settings::OPTION_NAME,$a,false);' --user=1 --allow-root >/dev/null
run_mcp "{\"jsonrpc\":\"2.0\",\"id\":10,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"wp-ai-bridge/content-upsert\",\"parameters\":{\"action\":\"update\",\"id\":$post_id,\"status\":\"publish\",\"expected_modified_gmt\":\"$modified\",\"expected_state_hash\":\"$state_hash\"}}}}"
jq -e '.result.structuredContent.success == true' "$output_file" >/dev/null
[[ "$("${wp[@]}" post get "$post_id" --field=status --allow-root | tail -n 1)" == "publish" ]]

echo "MCP live publish gate: PASS"

run_mcp '{"jsonrpc":"2.0","id":11,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/media-upload","parameters":{"filename":"wpai-mcp-ci.png","content_base64":"iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=","title":"MCP CI media"}}}}'
media_id="$(jq -r '.result.structuredContent.data.id' "$output_file")"
[[ "$media_id" =~ ^[1-9][0-9]*$ ]]
[[ "$("${wp[@]}" post get "$media_id" --field=post_type --allow-root | tail -n 1)" == "attachment" ]]

run_mcp '{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/site-settings-update","parameters":{"tagline":"Updated through MCP CI"}}}}'
jq -e '.result.structuredContent.success == true' "$output_file" >/dev/null
[[ "$("${wp[@]}" option get blogdescription --allow-root | tail -n 1)" == "Updated through MCP CI" ]]

run_mcp '{"jsonrpc":"2.0","id":13,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-ai-bridge/extensions-read","parameters":{"kind":"all"}}}}'
jq -e '.result.structuredContent.success == true and (.result.structuredContent.data.plugins | length > 0)' "$output_file" >/dev/null

echo "MCP media + site configuration + advanced administration read: PASS"
echo "PASS: Issue #6 raw MCP STDIO workflow."
