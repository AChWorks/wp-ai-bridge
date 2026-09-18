#!/usr/bin/env bash
# shellcheck disable=SC2016 # Embedded PHP intentionally remains literal until PHP receives it.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpai-issue80-${safe_tag}-$$"
compose=(docker compose -f "$compose_file")
tmp_dir="$(mktemp -d)"
cleanup() {
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$tmp_dir"
}
trap cleanup EXIT

bash "$root/bin/build-zip.sh"
"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 30); do
    if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-load.php; then
        break
    fi
    if [[ "$attempt" == "30" ]]; then
        echo 'ERROR: WordPress files were not initialized.' >&2
        exit 1
    fi
    sleep 2
done

"${compose[@]}" cp "$root/build/wp-ai-bridge.zip" wordpress:/var/www/html/wp-ai-bridge.zip
wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install \
    --url=https://localhost \
    --title='WP AI Bridge Issue 80' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root
"${wp[@]}" plugin install "${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}" --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --activate --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge.zip
"${compose[@]}" exec -T wordpress mkdir -p /var/www/html/wp-content/plugins/wp-ai-bridge/tests/integration
"${compose[@]}" cp "$root/tests/integration/issue80-refresh-recovery-smoke.php" wordpress:/var/www/html/wp-content/plugins/wp-ai-bridge/tests/integration/issue80-refresh-recovery-smoke.php
"${compose[@]}" cp "$root/tests/integration/issue80-refresh-concurrency-worker.php" wordpress:/var/www/html/wp-content/plugins/wp-ai-bridge/tests/integration/issue80-refresh-concurrency-worker.php

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
echo "Issue #80 baseline: WordPress ${actual_wp}; PHP ${actual_php}; image ${wordpress_tag}"

"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue80-refresh-recovery-smoke.php --user=1 --allow-root

echo '== Issue #80 real-database concurrency =='
refresh_token="$("${wp[@]}" eval '
$store = new WP_AI_Bridge\Auth\OAuth_Store();
$oauth = new WP_AI_Bridge\Auth\OAuth_Server($store);
echo $store->issue(
    WP_AI_Bridge\Auth\OAuth_Store::TYPE_REFRESH,
    array(
        "user_id" => 1,
        "client_id" => WP_AI_Bridge\Auth\OAuth_Server::CHATGPT_CLIENT_ID,
        "resource" => $oauth->mcp_endpoint_url(),
        "scope" => WP_AI_Bridge\Auth\OAuth_Server::SCOPE_MCP . " " . WP_AI_Bridge\Auth\OAuth_Server::SCOPE_OFFLINE,
    ),
    WP_AI_Bridge\Auth\OAuth_Server::REFRESH_TTL
);
' --user=1 --allow-root | tail -n 1)"
if [[ "$refresh_token" != wpai_r.* ]]; then
    echo 'ERROR: Could not seed the Issue #80 concurrency refresh token.' >&2
    exit 1
fi

worker_a=("${compose[@]}" run --rm -e "WPAI_ISSUE80_REFRESH_TOKEN=$refresh_token" cli)
worker_b=("${compose[@]}" run --rm -e "WPAI_ISSUE80_REFRESH_TOKEN=$refresh_token" cli)
"${worker_a[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue80-refresh-concurrency-worker.php --user=1 --allow-root >"$tmp_dir/a.out" 2>"$tmp_dir/a.err" &
pid_a=$!
"${worker_b[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue80-refresh-concurrency-worker.php --user=1 --allow-root >"$tmp_dir/b.out" 2>"$tmp_dir/b.err" &
pid_b=$!
wait "$pid_a"
wait "$pid_b"

result_a="$(tail -n 1 "$tmp_dir/a.out")"
result_b="$(tail -n 1 "$tmp_dir/b.out")"
php -r '
$a = json_decode($argv[1], true);
$b = json_decode($argv[2], true);
if (!is_array($a) || !is_array($b)) { fwrite(STDERR, "Invalid Issue #80 concurrency output.\n"); exit(1); }
foreach (["status", "access_hash", "refresh_hash"] as $key) {
    if (!array_key_exists($key, $a) || !array_key_exists($key, $b)) { fwrite(STDERR, "Incomplete Issue #80 concurrency output.\n"); exit(1); }
}
if (200 !== (int) $a["status"] || 200 !== (int) $b["status"]) { fwrite(STDERR, "Concurrent refresh did not produce two bounded successful views.\n"); exit(1); }
if ("" === $a["access_hash"] || "" === $a["refresh_hash"] || !hash_equals($a["access_hash"], $b["access_hash"]) || !hash_equals($a["refresh_hash"], $b["refresh_hash"])) {
    fwrite(STDERR, "Concurrent refresh created or exposed different successor generations.\n"); exit(1);
}
' "$result_a" "$result_b"

third=("${compose[@]}" run --rm -e "WPAI_ISSUE80_REFRESH_TOKEN=$refresh_token" cli)
third_result="$("${third[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue80-refresh-concurrency-worker.php --user=1 --allow-root | tail -n 1)"
php -r '
$r = json_decode($argv[1], true);
if (!is_array($r) || 400 !== (int) ($r["status"] ?? 0) || "invalid_grant" !== ($r["error"] ?? "")) {
    fwrite(STDERR, "Issue #80 concurrency allowance was not one-shot.\n"); exit(1);
}
' "$third_result"

echo 'Issue #80 real-database concurrency: PASS'
echo 'Issue #80 refresh recovery integration: PASS'
