#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpai-regressions-${safe_tag}-$$"

compose=(docker compose -f "$compose_file")
cleanup() {
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

bash "$root/bin/build-zip.sh"
"${compose[@]}" up -d db wordpress
# Shared disposable /tmp lets real web and CLI worker cleanup inspect the same ZIPs.
"${compose[@]}" exec -T -u root wordpress chmod 1777 /tmp

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
    --title='WP AI Bridge consolidated regressions' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root

"${wp[@]}" plugin install "${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}" --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --activate --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge.zip

# Copy the complete integration fixture tree once. Each smoke file runs in a fresh
# WP-CLI PHP process while sharing the same isolated WordPress installation.
tar --mode='u+rwX,go+rX' -C "$root" -cf - tests \
    | "${compose[@]}" exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/wp-ai-bridge

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
echo "Consolidated regression baseline: WordPress ${actual_wp}; PHP ${actual_php}; image ${wordpress_tag}"

run_eval() {
    local test_file="$1"
    echo "== ${test_file} =="
    "${wp[@]}" eval-file "wp-content/plugins/wp-ai-bridge/tests/integration/${test_file}" --user=1 --allow-root
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
run_eval issue61-f008-throwable-smoke.php
run_eval issue61-f003-create-provenance-smoke.php
run_eval issue61-f004-f005-persistence-smoke.php
run_eval issue61-f006-same-request-dispatch-smoke.php

run_eval issue67-external-packages-smoke.php
run_eval issue108-private-packages-smoke.php

bash "$root/bin/run-issue108-browser-upload.sh"

# N1: Real web multipart staged ZIPs are on the shared web/CLI /tmp volume.
# A separate CLI runs with the SAME DB/WordPress filesystem but another /tmp.
run_web_storage() {
    local mode="$1"
    "${compose[@]}" run --rm -e "WPAI108_WEB_STORAGE_MODE=$mode" cli         eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue108-web-storage-smoke.php         --user=1 --allow-root
}
run_web_storage staged
"${compose[@]}" run --rm cli-unshared eval '
require_once WP_PLUGIN_DIR . "/wp-ai-bridge/src/Support/class-private-package-storage.php";
if ( ! is_wp_error( \WP_AI_Bridge\Support\Private_Package_Storage::existing_directory() ) ) {
    throw new RuntimeException( "An independent private tmp mount incorrectly matched the web storage identity." );
}
echo "PASS: separate physical PHP tmp storage was rejected.\n";
' --user=1 --allow-root
set +e
"${compose[@]}" run --rm cli-unshared plugin deactivate wp-ai-bridge --allow-root > /tmp/wpai108-unshared-single-site.log 2>&1
different_root_result=$?
set -e
if [[ "$different_root_result" == 0 ]] ||
   ! grep -q 'Private package files could not be retired safely' /tmp/wpai108-unshared-single-site.log ||
   ! "${wp[@]}" plugin is-active wp-ai-bridge --allow-root; then
    echo 'ERROR: independent CLI tmp storage falsely completed WordPress deactivation.' >&2
    tail -n 18 /tmp/wpai108-unshared-single-site.log >&2
    exit 1
fi
run_web_storage staged
# Execute the actual uninstall.php early lifecycle boundary from the
# mismatched CLI too; it must abort BEFORE ordinary Bridge state removal.
set +e
"${compose[@]}" run --rm cli-unshared eval '
define( "WP_UNINSTALL_PLUGIN", "wp-ai-bridge/wp-ai-bridge.php" );
require WP_PLUGIN_DIR . "/wp-ai-bridge/uninstall.php";
' --allow-root > /tmp/wpai108-unshared-single-uninstall.log 2>&1
different_uninstall_result=$?
set -e
if [[ "$different_uninstall_result" == 0 ]] ||
   ! grep -q 'private ZIP cleanup could not finish safely' /tmp/wpai108-unshared-single-uninstall.log ||
   ! "${wp[@]}" plugin is-active wp-ai-bridge --allow-root; then
    echo 'ERROR: mismatched CLI uninstall falsely completed or removed active plugin state.' >&2
    tail -n 18 /tmp/wpai108-unshared-single-uninstall.log >&2
    exit 1
fi
run_web_storage staged
"${wp[@]}" plugin deactivate wp-ai-bridge --allow-root
run_web_storage retired
"${wp[@]}" plugin activate wp-ai-bridge --allow-root >/dev/null
echo 'PASS: Issue #108 N1 real web ZIPs survive mismatched CLI tmp and retire under the verified original root.'

# Actual plugin deactivation, activation and uninstall; WP-CLI preserves
# the fixture source during test-owned uninstall using --skip-delete.
run_lifecycle() {
    local mode="$1"
    "${compose[@]}" run --rm -e "WPAI108_LIFECYCLE_MODE=$mode" cli         eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue108-lifecycle-smoke.php         --user=1 --allow-root
}
run_lifecycle setup
"${wp[@]}" plugin deactivate wp-ai-bridge --allow-root
run_lifecycle verify
run_lifecycle cleanup
"${wp[@]}" plugin activate wp-ai-bridge --allow-root >/dev/null
run_lifecycle setup
"${wp[@]}" plugin deactivate wp-ai-bridge --allow-root
"${wp[@]}" plugin uninstall wp-ai-bridge --skip-delete --allow-root
run_lifecycle verify
run_lifecycle cleanup

echo "Consolidated single-site regressions: PASS"
