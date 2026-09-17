#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpai-issue74-engine-${safe_tag}-$$"

compose=(docker compose -f "$compose_file")
cleanup() {
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

bash "$root/bin/build-zip.sh"
candidate_zip="$root/build/wp-ai-bridge.zip"
test -f "$candidate_zip"

"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 30); do
    if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-load.php; then break; fi
    if [[ "$attempt" == "30" ]]; then
        echo "ERROR: WordPress files were not initialized for Issue #74 storage-engine guard." >&2
        exit 1
    fi
    sleep 2
done

wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install \
    --url=https://localhost \
    --title='WP AI Bridge Issue 74 Engine Guard' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root >/dev/null

"${compose[@]}" cp "$candidate_zip" wordpress:/var/www/html/wp-ai-bridge-candidate.zip
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge-candidate.zip --allow-root >/dev/null
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge-candidate.zip

prefix="$("${wp[@]}" db prefix --allow-root | tail -n 1)"

set_engine() {
    local suffix="$1"
    local engine="$2"
    local table="${prefix}${suffix}"
    "${wp[@]}" db query "ALTER TABLE \`${table}\` ENGINE=${engine}" --allow-root >/dev/null
    actual_engine="$("${wp[@]}" db query "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${table}'" --skip-column-names --allow-root | tail -n 1)"
    if [[ "${actual_engine^^}" != "${engine^^}" ]]; then
        echo "ERROR: expected ${table} to use ${engine}, got ${actual_engine}." >&2
        exit 1
    fi
}

assert_legacy_workspace_intact() {
    "${wp[@]}" eval '
$fixture = get_option("wpai_issue74_engine_fixture", array());
if (!is_array($fixture) || empty($fixture["document_id"]) || empty($fixture["task_id"])) { exit(1); }
global $wpdb;
if (1 !== (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", (int)$fixture["document_id"], "wpnb_doc"))) { exit(2); }
if (1 !== (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", (int)$fixture["task_id"], "wpnb_task"))) { exit(3); }
if (1 !== (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", (int)$fixture["document_id"], "_wpnb_workspace_state"))) { exit(4); }
if (1 !== (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", (int)$fixture["task_id"], "_wpnb_workspace_state"))) { exit(5); }
if (0 !== (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN (\"wpai_doc\",\"wpai_task\")")) { exit(6); }
if (0 !== (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = \"_wpai_workspace_state\"")) { exit(7); }
if (0 !== (int)get_option("wp_ai_bridge_schema_version", 0)) { exit(8); }
if (false === get_option("wp_native_builder_bridge_source_recovery", false)) { exit(9); }
' --allow-root >/dev/null
}

# The InnoDB requirement is migration-only: a clean install with no legacy Workspace rows
# must remain activatable even when a core table uses a nontransactional engine.
set_engine postmeta MyISAM
"${wp[@]}" plugin activate wp-ai-bridge --allow-root >/dev/null
"${wp[@]}" plugin deactivate wp-ai-bridge --allow-root >/dev/null
set_engine postmeta InnoDB
"${wp[@]}" option delete wp_ai_bridge_schema_version --allow-root >/dev/null

# Seed legacy Workspace identities without the former plugin. They are sufficient to make
# the one-time canonical migration path mandatory on the next activation.
"${wp[@]}" eval '
register_post_type("wpnb_doc", array("public" => false));
register_post_type("wpnb_task", array("public" => false));
$doc = wp_insert_post(array(
    "post_type" => "wpnb_doc",
    "post_status" => "private",
    "post_title" => "Issue 74 engine guard document",
    "post_content" => "Preserve this legacy Workspace document.",
), true);
$task = wp_insert_post(array(
    "post_type" => "wpnb_task",
    "post_status" => "private",
    "post_title" => "Issue 74 engine guard task",
), true);
if (is_wp_error($doc) || is_wp_error($task)) { exit(1); }
if (!add_post_meta((int)$doc, "_wpnb_workspace_state", wp_json_encode(array("version" => 3, "kind" => "document")), true)) { exit(2); }
if (!add_post_meta((int)$task, "_wpnb_workspace_state", wp_json_encode(array("version" => 5, "kind" => "task")), true)) { exit(3); }
update_option("wpai_issue74_engine_fixture", array("document_id" => (int)$doc, "task_id" => (int)$task), false);
update_option("wp_native_builder_bridge_source_recovery", array("issue74_engine_guard" => true), false);
' --allow-root >/dev/null

# Every table whose state participates in the atomic Workspace migration must be InnoDB.
for suffix in posts postmeta options; do
    table="${prefix}${suffix}"
    log="/tmp/wpai-issue74-engine-${suffix}.log"
    set_engine "$suffix" MyISAM
    if "${wp[@]}" plugin activate wp-ai-bridge --allow-root >"$log" 2>&1; then
        echo "ERROR: ${table} using MyISAM did not stop Workspace migration activation." >&2
        exit 1
    fi
    if ! grep -Fq 'requires InnoDB transactional storage' "$log" || ! grep -Fq "$table" "$log"; then
        echo "ERROR: ${table} MyISAM rejection did not reach the expected storage-engine fail-closed path." >&2
        cat "$log" >&2
        exit 1
    fi
    if "${wp[@]}" plugin is-active wp-ai-bridge --allow-root >/dev/null 2>&1; then
        echo "ERROR: WP AI Bridge remained active after ${table} storage-engine rejection." >&2
        exit 1
    fi
    assert_legacy_workspace_intact
    set_engine "$suffix" InnoDB
    rm -f "$log"
done

# Once all affected tables provide rollback-capable storage, the same legacy state migrates normally.
"${wp[@]}" plugin activate wp-ai-bridge --allow-root >/dev/null
"${wp[@]}" eval '
$fixture = get_option("wpai_issue74_engine_fixture", array());
if (!is_array($fixture) || empty($fixture["document_id"]) || empty($fixture["task_id"])) { exit(1); }
global $wpdb;
if (1 !== (int)get_option("wp_ai_bridge_schema_version", 0)) { exit(2); }
if (1 !== (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", (int)$fixture["document_id"], "wpai_doc"))) { exit(3); }
if (1 !== (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", (int)$fixture["task_id"], "wpai_task"))) { exit(4); }
if (0 !== (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = \"_wpnb_workspace_state\"")) { exit(5); }
if (2 !== (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = \"_wpai_workspace_state\"")) { exit(6); }
if (false !== get_option("wp_native_builder_bridge_source_recovery", false)) { exit(7); }
' --allow-root >/dev/null

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
echo "PASS: Issue #74 rejects nontransactional posts/postmeta/options before legacy Workspace mutation and still permits clean-install activation on WordPress ${actual_wp} / PHP ${actual_php}."
