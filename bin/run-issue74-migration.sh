#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
legacy_base="22c2f7ef802c604415ac186d7c9d9734246046ef"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpai-issue74-${safe_tag}-$$"

compose=(docker compose -f "$compose_file")
legacy_root="$(mktemp -d)"
cleanup() {
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$legacy_root"
}
trap cleanup EXIT

# Build the canonical candidate and the exact published v0.3.0 package independently.
bash "$root/bin/build-zip.sh"
if ! git -c "safe.directory=$root" -C "$root" cat-file -e "${legacy_base}^{commit}" 2>/dev/null; then
    git -c "safe.directory=$root" -C "$root" fetch --no-tags --depth=1 origin "$legacy_base"
fi
git -c "safe.directory=$root" -C "$root" archive "$legacy_base" | tar -x -C "$legacy_root"
bash "$legacy_root/bin/build-zip.sh"
legacy_zip="$legacy_root/build/wp-ai-bridge.zip"
candidate_zip="$root/build/wp-ai-bridge.zip"
test -f "$legacy_zip"
test -f "$candidate_zip"
legacy_entries="$(unzip -Z1 "$legacy_zip")"
candidate_entries="$(unzip -Z1 "$candidate_zip")"
grep -Fxq 'wp-native-builder-bridge/wp-native-builder-bridge.php' <<<"$legacy_entries"
grep -Fxq 'wp-ai-bridge/wp-ai-bridge.php' <<<"$candidate_entries"

"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 30); do
    if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-load.php; then break; fi
    if [[ "$attempt" == "30" ]]; then
        echo "ERROR: WordPress files were not initialized for Issue #74 migration smoke." >&2
        exit 1
    fi
    sleep 2
done

wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install \
    --url=https://localhost \
    --title='WP AI Bridge Issue 74 Migration' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root >/dev/null
mcp_adapter_url="${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}"
"${wp[@]}" plugin install "$mcp_adapter_url" --activate --allow-root >/dev/null

# Install the exact published v0.3.0 identity under its historical directory.
"${compose[@]}" cp "$legacy_zip" wordpress:/var/www/html/wp-ai-bridge-v030.zip
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge-v030.zip --activate --allow-root >/dev/null
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge-v030.zip

# Seed the Workspace data that must survive plus legacy runtime/security state that must not migrate.
"${wp[@]}" eval '
$settings = new WP_Native_Builder_Bridge\Support\Settings();
$values = $settings->defaults();
$values[WP_Native_Builder_Bridge\Support\Settings::GROUP_BUILDER_WRITE] = 1;
$values[WP_Native_Builder_Bridge\Support\Settings::GROUP_ADVANCED_METADATA] = 1;
update_option(WP_Native_Builder_Bridge\Support\Settings::OPTION_NAME, $values, false);
update_option(WP_Native_Builder_Bridge\Support\Mutation_Log::OPTION_NAME, array(array(
    "timestamp" => "2026-09-16T00:00:00Z",
    "user_id" => 1,
    "ability" => "wp-native-builder/content-upsert",
    "target_type" => "post",
    "target_id" => 123,
    "success" => true,
    "error_code" => "",
)), false);
update_option(WP_Native_Builder_Bridge\Auth\Approved_OAuth_Clients::OPTION_NAME, array("https://gateway.example.invalid/client.json"), false);
update_option(WP_Native_Builder_Bridge\Auth\Approved_OAuth_Clients::REVISION_OPTION, 7, false);
$oauth_store = new WP_Native_Builder_Bridge\Auth\OAuth_Store();
$legacy_access = $oauth_store->issue(WP_Native_Builder_Bridge\Auth\OAuth_Store::TYPE_ACCESS, array(
    "user_id" => 1,
    "client_id" => WP_Native_Builder_Bridge\Auth\OAuth_Server::CHATGPT_CLIENT_ID,
    "resource" => rest_url("wp-native-builder/v1/mcp"),
    "scope" => WP_Native_Builder_Bridge\Auth\OAuth_Server::SCOPE_MCP,
), 3600);
$store = new WP_Native_Builder_Bridge\Workspace\Store();
$doc = $store->create_document(array("key" => "issue74-migration", "title" => "Issue 74 migration", "content" => "Preserve this workspace document."));
$task = $store->create_task(array("title" => "Issue 74 task", "progress" => "in_progress", "review" => "pending", "delivery" => "not_applicable"));
if (is_wp_error($doc) || is_wp_error($task)) { exit(1); }
update_option(WP_Native_Builder_Bridge\Abilities\Source_Editing_Abilities::RECOVERY_OPTION, array(
    "version" => 1,
    "token" => str_repeat("b", 32),
    "kind" => "plugin",
    "extension" => "wp-native-builder-bridge/wp-native-builder-bridge.php",
    "file" => "wp-native-builder-bridge.php",
), false);
update_option("wpai_issue74_fixture", array(
    "legacy_access" => $legacy_access,
    "document_id" => (int) $doc["id"],
    "document_hash" => (string) $doc["state_hash"],
    "document_version" => (int) $doc["version"],
    "task_id" => (int) $task["id"],
    "task_hash" => (string) $task["state_hash"],
    "task_version" => (int) $task["version"],
), false);
' --user=1 --allow-root >/dev/null

"${wp[@]}" eval '
$key = "wpnb_oauth_assertion_" . str_repeat("a", 40);
add_option($key, time() + 600, "", false);
wp_schedule_single_event(time() + 660, "wpnb_oauth_cleanup_client_assertion", array($key, time() + 600));
' --allow-root >/dev/null

# This is the real supported operator path: remove the old plugin completely first.
"${wp[@]}" plugin deactivate wp-native-builder-bridge --allow-root >/dev/null
"${wp[@]}" plugin uninstall wp-native-builder-bridge --allow-root >/dev/null

# v0.3.0 uninstall must preserve only Workspace posts/meta; disposable connection/settings state is gone.
"${wp[@]}" eval '
$fixture = get_option("wpai_issue74_fixture", array());
if (!is_array($fixture) || empty($fixture["document_id"]) || empty($fixture["task_id"])) { exit(1); }
global $wpdb;
if (1 !== (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", (int)$fixture["document_id"], "wpnb_doc"))) { exit(2); }
if (1 !== (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", (int)$fixture["task_id"], "wpnb_task"))) { exit(3); }
if (false !== get_option("wp_native_builder_bridge_settings", false)) { exit(4); }
if (false !== get_option("wp_native_builder_bridge_recent_actions", false)) { exit(5); }
if (false !== get_option("wp_native_builder_bridge_oauth_instance", false)) { exit(6); }
if (false !== get_option("wp_native_builder_bridge_oauth_clients", false)) { exit(7); }
if (false === get_option("wp_native_builder_bridge_source_recovery", false)) { exit(8); }
if (is_dir(WP_PLUGIN_DIR . "/wp-native-builder-bridge")) { exit(9); }
' --allow-root >/dev/null

# Install/activate the clean canonical plugin. Activation migrates Workspace only.
"${compose[@]}" cp "$candidate_zip" wordpress:/var/www/html/wp-ai-bridge-candidate.zip
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge-candidate.zip --activate --allow-root >/dev/null
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge-candidate.zip

# Verify exact Workspace continuity and an intentionally fresh runtime/security state.
"${wp[@]}" eval '
$fixture = get_option("wpai_issue74_fixture", array());
if (!is_array($fixture) || empty($fixture["document_id"]) || empty($fixture["task_id"])) { exit(1); }
if (1 !== (int) get_option("wp_ai_bridge_schema_version", 0)) { exit(2); }
foreach (array(
    "wp_ai_bridge_settings",
    "wp_ai_bridge_recent_actions",
    "wp_ai_bridge_oauth_instance",
    "wp_ai_bridge_oauth_clients",
    "wp_ai_bridge_oauth_clients_revision",
    "wp_ai_bridge_source_recovery",
) as $name) {
    if (false !== get_option($name, false)) { exit(3); }
}
foreach (array(
    "wp_native_builder_bridge_settings",
    "wp_native_builder_bridge_recent_actions",
    "wp_native_builder_bridge_oauth_instance",
    "wp_native_builder_bridge_oauth_clients",
    "wp_native_builder_bridge_oauth_clients_revision",
    "wp_native_builder_bridge_source_recovery",
) as $name) {
    if (false !== get_option($name, false)) { exit(4); }
}
global $wpdb;
if (0 !== (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN (\"wpnb_doc\",\"wpnb_task\")")) { exit(5); }
if (0 !== (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = \"_wpnb_workspace_state\"")) { exit(6); }
$store = new WP_AI_Bridge\Workspace\Store();
$doc = $store->get_document((int)$fixture["document_id"]);
$task = $store->get_task((int)$fixture["task_id"]);
if (is_wp_error($doc) || is_wp_error($task)) { exit(7); }
if (!hash_equals((string)$fixture["document_hash"], (string)$doc["state_hash"]) || (int)$fixture["document_version"] !== (int)$doc["version"]) { exit(8); }
if (!hash_equals((string)$fixture["task_hash"], (string)$task["state_hash"]) || (int)$fixture["task_version"] !== (int)$task["version"]) { exit(9); }
$settings = (new WP_AI_Bridge\Support\Settings())->all();
if (1 !== (int)$settings[WP_AI_Bridge\Support\Settings::GROUP_SITE_READ]) { exit(10); }
if (0 !== (int)$settings[WP_AI_Bridge\Support\Settings::GROUP_BUILDER_WRITE]) { exit(11); }
if (0 !== (int)$settings[WP_AI_Bridge\Support\Settings::GROUP_ADVANCED_METADATA]) { exit(12); }
$new_oauth = new WP_AI_Bridge\Auth\OAuth_Store();
if (false !== $new_oauth->read(WP_AI_Bridge\Auth\OAuth_Store::TYPE_ACCESS, (string)$fixture["legacy_access"], false)) { exit(13); }
if (0 !== (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \"_transient_wpnb_oauth_%\" OR option_name LIKE \"_transient_timeout_wpnb_oauth_%\" OR option_name LIKE \"wpnb_oauth_assertion_%\"")) { exit(14); }
if (wp_next_scheduled("wpnb_oauth_cleanup_client_assertion")) { exit(15); }
' --user=1 --allow-root >/dev/null

# Prove migration is repeat-safe and cannot mutate the preserved Workspace a second time.
"${wp[@]}" eval '
$fixture = get_option("wpai_issue74_fixture", array());
WP_AI_Bridge\Migrator::activate(false);
$store = new WP_AI_Bridge\Workspace\Store();
$doc = $store->get_document((int)$fixture["document_id"]);
$task = $store->get_task((int)$fixture["task_id"]);
if (is_wp_error($doc) || is_wp_error($task)) { exit(1); }
if (!hash_equals((string)$fixture["document_hash"], (string)$doc["state_hash"]) || !hash_equals((string)$fixture["task_hash"], (string)$task["state_hash"])) { exit(2); }
' --user=1 --allow-root >/dev/null

# Candidate-native route/admin identity smoke.
"${compose[@]}" exec -T wordpress mkdir -p /var/www/html/wp-content/plugins/wp-ai-bridge/tests/integration
"${compose[@]}" cp "$root/tests/integration/issue74-canonical-identity-smoke.php" wordpress:/var/www/html/wp-content/plugins/wp-ai-bridge/tests/integration/issue74-canonical-identity-smoke.php
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue74-canonical-identity-smoke.php --user=1 --allow-root

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
echo "PASS: Issue #74 exact v0.3.0 uninstall -> canonical Workspace-only migration, fresh OAuth/settings state, and new WP AI Bridge identity on WordPress ${actual_wp} / PHP ${actual_php}."
