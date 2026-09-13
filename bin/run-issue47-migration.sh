#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
legacy_base="f55244068e62c23ea9078dcd56674d3238257645"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpai-issue47-${safe_tag}-$$"

compose=(docker compose -f "$compose_file")
legacy_root="$(mktemp -d)"
cleanup() {
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$legacy_root"
}
trap cleanup EXIT

# Candidate public artifact is wp-ai-bridge.zip, but its internal plugin directory
# deliberately stays wp-native-builder-bridge for in-place WordPress upgrades.
bash "$root/bin/build-zip.sh"

# Build the exact pre-rename integrated baseline independently. GitHub pull-request
# checkouts can be shallow, so fetch only this immutable commit when it is absent.
if ! git -C "$root" cat-file -e "${legacy_base}^{commit}" 2>/dev/null; then
    git -C "$root" fetch --no-tags --depth=1 origin "$legacy_base"
fi
git -C "$root" archive "$legacy_base" | tar -x -C "$legacy_root"
bash "$legacy_root/bin/build-zip.sh"
legacy_zip="$legacy_root/build/wp-native-builder-bridge.zip"
candidate_zip="$root/build/wp-ai-bridge.zip"
test -f "$legacy_zip"
test -f "$candidate_zip"

"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 30); do
    if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-load.php; then
        break
    fi
    if [[ "$attempt" == "30" ]]; then
        echo "ERROR: WordPress files were not initialized for Issue #47 migration smoke." >&2
        exit 1
    fi
    sleep 2
done

wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install \
    --url=https://localhost \
    --title='WP AI Bridge Issue 47 Migration' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root >/dev/null
mcp_adapter_url="${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}"
"${wp[@]}" plugin install "$mcp_adapter_url" --activate --allow-root >/dev/null

"${compose[@]}" cp "$legacy_zip" wordpress:/var/www/html/wp-native-builder-bridge-legacy.zip
"${wp[@]}" plugin install /var/www/html/wp-native-builder-bridge-legacy.zip --activate --allow-root >/dev/null
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-native-builder-bridge-legacy.zip

# Seed durable identities through the exact pre-rename build.
"${wp[@]}" eval '
$settings = new WP_Native_Builder_Bridge\Support\Settings();
$values = $settings->defaults();
$values[WP_Native_Builder_Bridge\Support\Settings::GROUP_BUILDER_WRITE] = 1;
update_option(WP_Native_Builder_Bridge\Support\Settings::OPTION_NAME, $values, false);
$store = new WP_Native_Builder_Bridge\Workspace\Store();
$doc = $store->create_document(array("key" => "issue47-upgrade", "title" => "Issue 47 upgrade", "content" => "Preserve public-rename state."));
$task = $store->create_task(array("title" => "Issue 47 upgrade task", "progress" => "in_progress", "review" => "pending", "delivery" => "not_applicable"));
if (is_wp_error($doc) || is_wp_error($task)) { exit(1); }
$oauth_store = new WP_Native_Builder_Bridge\Auth\OAuth_Store();
$legacy_resource = rest_url("wp-native-builder/v1/mcp");
$token = $oauth_store->issue(
    WP_Native_Builder_Bridge\Auth\OAuth_Store::TYPE_ACCESS,
    array(
        "user_id" => 1,
        "client_id" => WP_Native_Builder_Bridge\Auth\OAuth_Server::CHATGPT_CLIENT_ID,
        "resource" => $legacy_resource,
        "scope" => WP_Native_Builder_Bridge\Auth\OAuth_Server::SCOPE_MCP,
    ),
    WP_Native_Builder_Bridge\Auth\OAuth_Server::ACCESS_TTL
);
update_option("wpnb_issue47_upgrade_fixture", array(
    "settings" => $values,
    "document_id" => (int) $doc["id"],
    "document_hash" => (string) $doc["state_hash"],
    "task_id" => (int) $task["id"],
    "task_hash" => (string) $task["state_hash"],
    "legacy_token" => $token,
    "legacy_resource" => $legacy_resource,
), false);
' --user=1 --allow-root >/dev/null

# Upgrade the same installed plugin directory with the new public artifact.
"${wp[@]}" plugin deactivate wp-native-builder-bridge --allow-root >/dev/null
"${compose[@]}" cp "$candidate_zip" wordpress:/var/www/html/wp-ai-bridge.zip
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --force --activate --allow-root >/dev/null
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge.zip

# The public rename must not become a second WordPress plugin installation.
"${compose[@]}" exec -T wordpress test -d /var/www/html/wp-content/plugins/wp-native-builder-bridge
if "${compose[@]}" exec -T wordpress test -e /var/www/html/wp-content/plugins/wp-ai-bridge; then
    echo "ERROR: WP AI Bridge upgrade created a duplicate plugin directory." >&2
    exit 1
fi

# Prove all seeded durable identities survived and an old access token still binds
# only to the retained legacy resource after upgrade.
"${wp[@]}" eval '
$fixture = get_option("wpnb_issue47_upgrade_fixture", array());
if (!is_array($fixture) || empty($fixture["legacy_token"])) { exit(1); }
$settings = new WP_Native_Builder_Bridge\Support\Settings();
if ($settings->all() !== $fixture["settings"]) { exit(1); }
$store = new WP_Native_Builder_Bridge\Workspace\Store();
$doc = $store->get_document((int) $fixture["document_id"]);
$task = $store->get_task((int) $fixture["task_id"]);
if (is_wp_error($doc) || is_wp_error($task) || !hash_equals((string) $fixture["document_hash"], (string) $doc["state_hash"]) || !hash_equals((string) $fixture["task_hash"], (string) $task["state_hash"])) { exit(1); }
$oauth = new WP_Native_Builder_Bridge\Auth\OAuth_Server(new WP_Native_Builder_Bridge\Auth\OAuth_Store());
$legacy = new WP_REST_Request("POST", WP_Native_Builder_Bridge\Auth\OAuth_Server::LEGACY_MCP_REQUEST_ROUTE);
$legacy->set_header("Authorization", "Bearer " . $fixture["legacy_token"]);
if (!$oauth->authenticate_mcp_request($legacy)) { exit(1); }
$canonical = new WP_REST_Request("POST", WP_Native_Builder_Bridge\Auth\OAuth_Server::MCP_REQUEST_ROUTE);
$canonical->set_header("Authorization", "Bearer " . $fixture["legacy_token"]);
if ($oauth->authenticate_mcp_request($canonical)) { exit(1); }
$plugin = get_plugin_data(WP_PLUGIN_DIR . "/wp-native-builder-bridge/wp-native-builder-bridge.php", false, false);
if ("WP AI Bridge" !== ($plugin["Name"] ?? "")) { exit(1); }
' --user=1 --allow-root >/dev/null

# Run the candidate-native identity/audience/admin-alias smoke after the real upgrade.
"${compose[@]}" exec -T wordpress mkdir -p /var/www/html/wp-content/plugins/wp-native-builder-bridge/tests/integration
"${compose[@]}" cp "$root/tests/integration/issue47-identity-migration-smoke.php" wordpress:/var/www/html/wp-content/plugins/wp-native-builder-bridge/tests/integration/issue47-identity-migration-smoke.php
"${wp[@]}" eval-file wp-content/plugins/wp-native-builder-bridge/tests/integration/issue47-identity-migration-smoke.php --user=1 --allow-root

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
echo "PASS: Issue #47 exact-base upgrade continuity and WP AI Bridge identity migration on WordPress ${actual_wp} / PHP ${actual_php}."
