#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpnb-issue58-ms-${safe_tag}-$$"

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
        echo 'ERROR: WordPress files were not initialized for Issue #58 multisite smoke.' >&2
        exit 1
    fi
    sleep 2
done

"${compose[@]}" cp "$root/build/wp-ai-bridge.zip" wordpress:/var/www/html/wp-ai-bridge.zip
wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core multisite-install \
    --url=http://wordpress \
    --title='WP AI Bridge Issue 58 Multisite' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root
"${wp[@]}" site create --slug=secondary --title='Issue 58 Secondary Site' --email=admin@example.invalid --allow-root >/dev/null
"${wp[@]}" plugin install "${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}" --activate-network --allow-root
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --activate-network --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge.zip
"${compose[@]}" exec -T wordpress mkdir -p /var/www/html/wp-content/plugins/wp-native-builder-bridge/tests/integration
"${compose[@]}" cp "$root/tests/integration/issue58-user-meta-multisite-smoke.php" wordpress:/var/www/html/wp-content/plugins/wp-native-builder-bridge/tests/integration/issue58-user-meta-multisite-smoke.php

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
echo "Issue #58 multisite baseline: WordPress ${actual_wp}; PHP ${actual_php}; image ${wordpress_tag}"
"${wp[@]}" eval-file wp-content/plugins/wp-native-builder-bridge/tests/integration/issue58-user-meta-multisite-smoke.php --user=1 --allow-root

echo "PASS: Issue #58 multisite integration suite for ${wordpress_tag}."
