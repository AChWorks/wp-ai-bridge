#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpnb-regressions-${safe_tag}-$$"

compose=(docker compose -f "$compose_file")
cleanup() {
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

bash "$root/bin/build-zip.sh"
"${compose[@]}" up -d db wordpress

for attempt in $(seq 1 30); do
    if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-load.php; then
        break
    fi
    if [[ "$attempt" == "30" ]]; then
        echo "ERROR: WordPress files were not initialized." >&2
        exit 1
    fi
    sleep 2
done

"${compose[@]}" cp "$root/build/wp-native-builder-bridge.zip" wordpress:/var/www/html/wp-native-builder-bridge.zip

wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install \
    --url=https://localhost \
    --title='WP AI Bridge consolidated regressions' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root

"${wp[@]}" plugin install "${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}" --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wp-native-builder-bridge.zip --activate --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-native-builder-bridge.zip

# Copy the complete integration fixture tree once. Each smoke file runs in a fresh
# WP-CLI PHP process while sharing the same isolated WordPress installation.
tar --mode='u+rwX,go+rX' -C "$root" -cf - tests \
    | "${compose[@]}" exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/wp-native-builder-bridge

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
echo "Consolidated regression baseline: WordPress ${actual_wp}; PHP ${actual_php}; image ${wordpress_tag}"

run_eval() {
    local test_file="$1"
    echo "== ${test_file} =="
    "${wp[@]}" eval-file "wp-content/plugins/wp-native-builder-bridge/tests/integration/${test_file}" --user=1 --allow-root
}

# Issue #44 requires its provider fixture only for its own two smoke files.
"${compose[@]}" exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
"${compose[@]}" cp "$root/tests/fixtures/issue44-native-provider.php" wordpress:/var/www/html/wp-content/mu-plugins/wp-ai-bridge-issue44-native-provider.php
run_eval issue44-native-ability-delegation-smoke.php
run_eval issue44-bridge-ownership-inventory.php
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-content/mu-plugins/wp-ai-bridge-issue44-native-provider.php

run_eval issue52-comment-administration-smoke.php

run_eval issue54-approved-oauth-clients-smoke.php
run_eval issue54-chatgpt-compatibility-smoke.php
run_eval issue54-client-assertion-replay-window-smoke.php

run_eval issue56-registered-settings-smoke.php
run_eval issue56-registered-settings-typed-smoke.php
run_eval issue56-specialized-settings-smoke.php

run_eval issue58-user-comment-meta-smoke.php
run_eval issue58-user-comment-meta-policy-smoke.php
run_eval issue58-user-comment-meta-race-smoke.php
run_eval issue58-user-comment-meta-create-race-smoke.php
run_eval issue58-user-comment-meta-storage-smoke.php

run_eval issue61-application-passwords-smoke.php
run_eval issue61-f003-create-provenance-smoke.php
run_eval issue61-f004-f005-persistence-smoke.php
run_eval issue61-f006-same-request-dispatch-smoke.php

echo "Consolidated single-site regressions: PASS"
