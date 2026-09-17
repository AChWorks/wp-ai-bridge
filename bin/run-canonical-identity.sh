#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpai-canonical-${safe_tag}-$$"

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

"${compose[@]}" cp "$root/build/wp-ai-bridge.zip" wordpress:/var/www/html/wp-ai-bridge.zip
wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install \
    --url=https://localhost \
    --title='WP AI Bridge canonical identity' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root

mcp_adapter_url="${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}"
"${wp[@]}" plugin install "$mcp_adapter_url" --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --activate --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge.zip

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
actual_version="$("${wp[@]}" plugin get wp-ai-bridge --field=version --allow-root | tail -n 1)"
echo "Canonical baseline: WordPress ${actual_wp}; PHP ${actual_php}; plugin ${actual_version}; image ${wordpress_tag}"

if [[ "$actual_version" != "0.4.1" ]]; then
    echo "ERROR: installed cleanup release is not version 0.4.1." >&2
    exit 1
fi

if "${compose[@]}" exec -T wordpress test -e /var/www/html/wp-content/plugins/wp-ai-bridge/src/class-migrator.php; then
    echo "ERROR: retired Migrator is present in the installable package." >&2
    exit 1
fi
if "${compose[@]}" exec -T wordpress test -e /var/www/html/wp-content/plugins/wp-ai-bridge/languages/fa_IR-parts/migration.php; then
    echo "ERROR: retired migration localization part is present in the installable package." >&2
    exit 1
fi

legacy_pattern='WP_Native_Builder_Bridge|WP_NATIVE_BUILDER_BRIDGE|wp-native-builder-bridge|wp-native-builder/|wp_native_builder_bridge|wpnb_'
if "${compose[@]}" exec -T wordpress sh -lc "grep -R -nE '$legacy_pattern' /var/www/html/wp-content/plugins/wp-ai-bridge/src /var/www/html/wp-content/plugins/wp-ai-bridge/languages /var/www/html/wp-content/plugins/wp-ai-bridge/wp-ai-bridge.php /var/www/html/wp-content/plugins/wp-ai-bridge/uninstall.php"; then
    echo "ERROR: retired product identity remains in installed runtime/localization source." >&2
    exit 1
fi

# Copy the canonical identity smoke beside the installed package; tests are intentionally not shipped.
"${compose[@]}" exec -T wordpress mkdir -p /var/www/html/wp-content/plugins/wp-ai-bridge/tests/integration
"${compose[@]}" cp "$root/tests/integration/issue74-canonical-identity-smoke.php" wordpress:/var/www/html/wp-content/plugins/wp-ai-bridge/tests/integration/canonical-identity-smoke.php
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/canonical-identity-smoke.php --user=1 --allow-root

if "${wp[@]}" option get wp_ai_bridge_schema_version --allow-root >/dev/null 2>&1; then
    echo "ERROR: fresh canonical activation unexpectedly created the retired migration schema marker." >&2
    exit 1
fi

"${wp[@]}" eval '
$store = new WP_AI_Bridge\Workspace\Store();
$doc = $store->create_document(array("key" => "canonical-cleanup", "title" => "Canonical cleanup", "content" => "v0.4.1 native Workspace"));
$task = $store->create_task(array("title" => "Canonical cleanup task", "progress" => "in_progress", "review" => "not_required", "delivery" => "not_applicable"));
if (is_wp_error($doc) || is_wp_error($task)) { exit(1); }
$doc_read = $store->get_document((int) $doc["id"]);
$task_read = $store->get_task((int) $task["id"]);
if (is_wp_error($doc_read) || is_wp_error($task_read)) { exit(1); }
if ((int) $doc_read["id"] !== (int) $doc["id"] || (int) $task_read["id"] !== (int) $task["id"]) { exit(1); }
if (!hash_equals((string) $doc["state_hash"], (string) $doc_read["state_hash"]) || !hash_equals((string) $task["state_hash"], (string) $task_read["state_hash"])) { exit(1); }
' --user=1 --allow-root

echo "PASS: v0.4.1 activates natively with canonical identity and canonical Workspace storage, without retired migration code."
