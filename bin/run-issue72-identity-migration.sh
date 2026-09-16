#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
legacy_tag="v0.3.0"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpai-identity-migration-${safe_tag}-$$"

compose=(docker compose -f "$compose_file")
legacy_root="$(mktemp -d)"
cleanup() {
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$legacy_root"
}
trap cleanup EXIT

bash "$root/bin/build-zip.sh"
candidate_zip="$root/build/wp-ai-bridge.zip"
test -f "$candidate_zip"

if ! git -c "safe.directory=$root" -C "$root" cat-file -e "${legacy_tag}^{commit}" 2>/dev/null; then
    git -c "safe.directory=$root" -C "$root" fetch --tags origin "$legacy_tag"
fi
git -c "safe.directory=$root" -C "$root" archive "$legacy_tag" | tar -x -C "$legacy_root"
bash "$legacy_root/bin/build-zip.sh"
legacy_zip="$legacy_root/build/wp-ai-bridge.zip"
test -f "$legacy_zip"

"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 30); do
    if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-load.php; then
        break
    fi
    if [[ "$attempt" == "30" ]]; then
        echo "ERROR: WordPress files were not initialized for identity migration smoke." >&2
        exit 1
    fi
    sleep 2
done

wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install \
    --url=https://localhost \
    --title='WP AI Bridge Identity Migration' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root >/dev/null
mcp_adapter_url="${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}"
"${wp[@]}" plugin install "$mcp_adapter_url" --activate --allow-root >/dev/null

# Install the exact v0.3.0 package under its historical plugin directory and seed real durable state.
"${compose[@]}" cp "$legacy_zip" wordpress:/var/www/html/wp-ai-bridge-v030.zip
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge-v030.zip --activate --allow-root >/dev/null
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge-v030.zip
"${wp[@]}" eval '
$settings = new WP_Native_Builder_Bridge\Support\Settings();
$values = $settings->defaults();
$values[WP_Native_Builder_Bridge\Support\Settings::GROUP_BUILDER_WRITE] = 1;
$values[WP_Native_Builder_Bridge\Support\Settings::GROUP_ADVANCED_METADATA] = 1;
update_option(WP_Native_Builder_Bridge\Support\Settings::OPTION_NAME, $values, false);
$store = new WP_Native_Builder_Bridge\Workspace\Store();
$doc = $store->create_document(array("key" => "issue72-migration", "title" => "Issue 72 migration", "content" => "Preserve Workspace state across identity cutover."));
$task = $store->create_task(array("title" => "Issue 72 migration task", "progress" => "in_progress", "review" => "pending", "delivery" => "not_applicable"));
if (is_wp_error($doc) || is_wp_error($task)) { exit(1); }
$log = new WP_Native_Builder_Bridge\Support\Mutation_Log();
$log->record("wp-native-builder/content-upsert", "post", 72, true, "");
$oauth_store = new WP_Native_Builder_Bridge\Auth\OAuth_Store();
$oauth_instance = $oauth_store->instance_id();
$legacy_resource = rest_url("wp-native-builder/v1/mcp");
$legacy_token = $oauth_store->issue(
    WP_Native_Builder_Bridge\Auth\OAuth_Store::TYPE_ACCESS,
    array(
        "user_id" => 1,
        "client_id" => WP_Native_Builder_Bridge\Auth\OAuth_Server::CHATGPT_CLIENT_ID,
        "resource" => $legacy_resource,
        "scope" => WP_Native_Builder_Bridge\Auth\OAuth_Server::SCOPE_MCP,
    ),
    WP_Native_Builder_Bridge\Auth\OAuth_Server::ACCESS_TTL
);
update_option("wp_native_builder_bridge_oauth_clients", array("https://gateway.example.invalid/oauth/client.json"), false);
update_option("wp_native_builder_bridge_oauth_clients_revision", 7, false);
update_option("wpai_issue72_fixture", array(
    "settings" => $values,
    "document_id" => (int) $doc["id"],
    "document_hash" => (string) $doc["state_hash"],
    "task_id" => (int) $task["id"],
    "task_hash" => (string) $task["state_hash"],
    "oauth_instance" => $oauth_instance,
    "legacy_token" => $legacy_token,
), false);
' --user=1 --allow-root >/dev/null

legacy_doc_id="$("${wp[@]}" option get wpai_issue72_fixture --format=json --allow-root | jq -r '.document_id')"
legacy_task_id="$("${wp[@]}" option get wpai_issue72_fixture --format=json --allow-root | jq -r '.task_id')"

# Install the new package while v0.3.0 is still active, but prove activation fails closed
# until the legacy plugin is deactivated. This prevents a mixed-runtime namespace collision.
"${compose[@]}" cp "$candidate_zip" wordpress:/var/www/html/wp-ai-bridge-v040.zip
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge-v040.zip --allow-root >/dev/null
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge-v040.zip
if "${wp[@]}" plugin activate wp-ai-bridge --allow-root >/tmp/wpai-issue72-unexpected-activation.log 2>&1; then
    echo "ERROR: WP AI Bridge activated while the legacy plugin was still active." >&2
    cat /tmp/wpai-issue72-unexpected-activation.log >&2 || true
    exit 1
fi
if "${wp[@]}" plugin is-active wp-ai-bridge --allow-root >/dev/null 2>&1; then
    echo "ERROR: failed activation still marked WP AI Bridge active." >&2
    exit 1
fi

# Cut over without executing v0.3.0 uninstall: old DB state must remain available to the importer.
"${wp[@]}" plugin deactivate wp-native-builder-bridge --allow-root >/dev/null
"${wp[@]}" plugin activate wp-ai-bridge --allow-root >/dev/null

# The two package directories coexist only for the bounded cutover window; only the new one is active.
"${compose[@]}" exec -T wordpress test -d /var/www/html/wp-content/plugins/wp-native-builder-bridge
"${compose[@]}" exec -T wordpress test -d /var/www/html/wp-content/plugins/wp-ai-bridge
"${wp[@]}" plugin is-active wp-ai-bridge --allow-root
if "${wp[@]}" plugin is-active wp-native-builder-bridge --allow-root >/dev/null 2>&1; then
    echo "ERROR: legacy plugin remained active during identity cutover." >&2
    exit 1
fi

# Verify migrated values, stable Workspace IDs/state, canonical-only runtime identity, and retired legacy state.
"${wp[@]}" eval '
$fixture = get_option("wpai_issue72_fixture", array());
if (!is_array($fixture) || empty($fixture["legacy_token"])) { exit(1); }
$status = get_option("wp_ai_bridge_identity_migration", array());
if (!is_array($status) || 1 !== (int)($status["version"] ?? 0) || empty($status["completed"]) || empty($status["migrated_legacy"]) || empty($status["reconnect_oauth"])) { exit(1); }
if (get_option("wp_native_builder_bridge_settings", false) !== false) { exit(1); }
if (get_option("wp_native_builder_bridge_recent_actions", false) !== false) { exit(1); }
if (get_option("wp_native_builder_bridge_oauth_instance", false) !== false) { exit(1); }
if (get_option("wp_native_builder_bridge_oauth_clients", false) !== false) { exit(1); }
if (get_option("wp_native_builder_bridge_oauth_clients_revision", false) !== false) { exit(1); }
$current_settings = get_option("wp_ai_bridge_settings", array());
if ($current_settings !== $fixture["settings"]) { exit(1); }
if (!hash_equals((string)$fixture["oauth_instance"], (string)get_option("wp_ai_bridge_oauth_instance", ""))) { exit(1); }
if (get_option("wp_ai_bridge_oauth_clients", array()) !== array("https://gateway.example.invalid/oauth/client.json")) { exit(1); }
if (7 !== (int)get_option("wp_ai_bridge_oauth_clients_revision", 0)) { exit(1); }
$log = get_option("wp_ai_bridge_recent_actions", array());
if (!isset($log[0]["ability"]) || "wp-ai-bridge/content-upsert" !== $log[0]["ability"]) { exit(1); }
$store = new WP_Native_Builder_Bridge\Workspace\Store();
$doc = $store->get_document((int)$fixture["document_id"]);
$task = $store->get_task((int)$fixture["task_id"]);
if (is_wp_error($doc) || is_wp_error($task)) { exit(1); }
if ((int)$doc["id"] !== (int)$fixture["document_id"] || !hash_equals((string)$fixture["document_hash"], (string)$doc["state_hash"])) { exit(1); }
if ((int)$task["id"] !== (int)$fixture["task_id"] || !hash_equals((string)$fixture["task_hash"], (string)$task["state_hash"])) { exit(1); }
if ("wpai_doc" !== get_post_type((int)$fixture["document_id"]) || "wpai_task" !== get_post_type((int)$fixture["task_id"])) { exit(1); }
if ("" === (string)get_post_meta((int)$fixture["document_id"], "_wpai_workspace_state", true)) { exit(1); }
if (metadata_exists("post", (int)$fixture["document_id"], "_wpnb_workspace_state")) { exit(1); }
$oauth_store = new WP_Native_Builder_Bridge\Auth\OAuth_Store();
if (false !== $oauth_store->read(WP_Native_Builder_Bridge\Auth\OAuth_Store::TYPE_ACCESS, (string)$fixture["legacy_token"], false)) { exit(1); }
$routes = rest_get_server()->get_routes();
foreach (array("/wp-ai-bridge/v1/mcp", "/wp-ai-bridge/v1/oauth/token", "/wp-ai-bridge/v1/oauth/revoke") as $route) {
    if (!isset($routes[$route])) { exit(1); }
}
foreach (array("/wp-native-builder/v1/mcp", "/wp-native-builder/v1/oauth/token", "/wp-native-builder/v1/oauth/revoke") as $route) {
    if (isset($routes[$route])) { exit(1); }
}
if (!(wp_get_ability("wp-ai-bridge/bridge-info") instanceof WP_Ability)) { exit(1); }
if (wp_get_ability("wp-native-builder/bridge-info") instanceof WP_Ability) { exit(1); }
$plugin = get_plugin_data(WP_PLUGIN_DIR . "/wp-ai-bridge/wp-ai-bridge.php", false, false);
if ("WP AI Bridge" !== ($plugin["Name"] ?? "") || "0.4.0" !== ($plugin["Version"] ?? "")) { exit(1); }
' --user=1 --allow-root >/dev/null

# Prove conflict and pending-recovery paths fail closed in one loaded-plugin lifecycle,
# then prove a retry can complete once the blocking state is resolved.
"${wp[@]}" eval '
$assert = static function ($condition, $label) { if (!$condition) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); } };
delete_option("wp_ai_bridge_identity_migration");
update_option("wp_native_builder_bridge_settings", array("legacy" => 1), false);
update_option("wp_ai_bridge_settings", array("canonical" => 1), false);
$result = WP_Native_Builder_Bridge\Support\Identity_Migration::run();
$assert(is_wp_error($result) && "identity_migration_option_conflict" === $result->get_error_code(), "conflict error code");
$assert(get_option("wp_native_builder_bridge_settings", array()) === array("legacy" => 1), "legacy conflict state preserved");
$assert(get_option("wp_ai_bridge_settings", array()) === array("canonical" => 1), "canonical conflict state preserved");
delete_option("wp_native_builder_bridge_settings");
delete_option("wp_ai_bridge_settings");
update_option("wp_native_builder_bridge_source_recovery", array("token" => "legacy-pending"), false);
$result = WP_Native_Builder_Bridge\Support\Identity_Migration::run();
$assert(is_wp_error($result) && "identity_migration_pending_source_recovery" === $result->get_error_code(), "pending recovery error code");
$assert(false === get_option("wp_ai_bridge_identity_migration", false), "blocked migration marker absent");
delete_option("wp_native_builder_bridge_source_recovery");
update_option("wp_native_builder_bridge_settings", array("site_read" => 1), false);
$result = WP_Native_Builder_Bridge\Support\Identity_Migration::run();
$assert(!is_wp_error($result), "retry migration succeeds: " . (is_wp_error($result) ? $result->get_error_code() : "ok"));
$assert(false === get_option("wp_native_builder_bridge_settings", false), "retry retires legacy settings");
$assert(get_option("wp_ai_bridge_settings", array()) === array("site_read" => 1), "retry writes canonical settings");
$status = get_option("wp_ai_bridge_identity_migration", array());
$assert(is_array($status) && !empty($status["completed"]), "retry writes completion marker");
' --user=1 --allow-root >/dev/null

# Delete the old plugin installation only after the retry-safe migration completed.
"${wp[@]}" plugin delete wp-native-builder-bridge --allow-root >/dev/null
if "${compose[@]}" exec -T wordpress test -e /var/www/html/wp-content/plugins/wp-native-builder-bridge; then
    echo "ERROR: legacy plugin directory survived post-migration deletion." >&2
    exit 1
fi
"${wp[@]}" plugin is-active wp-ai-bridge --allow-root
"${wp[@]}" eval '
if (false === get_option("wp_ai_bridge_identity_migration", false)) { exit(1); }
if (false === get_option("wp_ai_bridge_settings", false)) { exit(1); }
' --allow-root >/dev/null

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
echo "PASS: v0.3.0 -> v0.4.0 clean identity migration on WordPress ${actual_wp} / PHP ${actual_php}; Workspace IDs ${legacy_doc_id}/${legacy_task_id} preserved."
