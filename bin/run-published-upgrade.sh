#!/usr/bin/env bash
set -euo pipefail

# Release-bound compatibility proof: install the actual immutable v0.4.2 ZIP,
# seed canonical/WP Workspace and authenticated OAuth state, then exercise
# WordPress Core's normal ZIP replacement with the current candidate artifact.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wordpress_tag="${1:-6.9-php8.4-apache}"
compose_file="$root/tests/integration/compose.yml"
baseline_url="https://github.com/AChWorks/wp-ai-bridge/releases/download/v0.4.2/wp-ai-bridge.zip"
baseline_sha256="1214fa1a6a5ff7da8682fe91535c1100f45d30cbfe23b380630e1234eec1f2ca"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpai124-${safe_tag}-${GITHUB_RUN_ID:-local}-$$"

private_dir="$(mktemp -d "${TMPDIR:-/tmp}/wpai124-upgrade-XXXXXX")"
chmod 0700 "$private_dir"
compose=(docker compose -f "$compose_file")
cleanup() {
    local result=$?
    trap - EXIT
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf -- "$private_dir"
    exit "$result"
}
trap cleanup EXIT

baseline_zip="$private_dir/published-v0.4.2.zip"
candidate_zip="$root/build/wp-ai-bridge.zip"
fixture="$root/tests/integration/issue124-published-upgrade-smoke.php"
test -f "$fixture"

curl -fL --retry 2 --connect-timeout 15 --max-time 120 -sS "$baseline_url" -o "$baseline_zip"
printf '%s  %s\n' "$baseline_sha256" "$baseline_zip" | sha256sum --check --status || {
    echo "ERROR: published v0.4.2 artifact checksum mismatch." >&2
    exit 1
}
old_version="$(unzip -p "$baseline_zip" wp-ai-bridge/wp-ai-bridge.php | sed -nE 's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/p')"
test "$old_version" = "0.4.2" || {
    echo "ERROR: published ZIP did not contain the expected canonical baseline version." >&2
    exit 1
}
echo "PASS: downloaded and verified original published v0.4.2 release ZIP (pinned SHA-256)."

bash "$root/bin/build-zip.sh"
expected_version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/p' "$root/wp-ai-bridge.php")"
test "$expected_version" = "0.5.0" || {
    echo "ERROR: this release-upgrade acceptance runner is scoped to the v0.5.0 candidate." >&2
    exit 1
}

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
"${compose[@]}" cp "$baseline_zip" wordpress:/var/www/html/wpai124-published-v0.4.2.zip
"${compose[@]}" cp "$candidate_zip" wordpress:/var/www/html/wpai124-candidate-v0.5.0.zip
"${compose[@]}" cp "$fixture" wordpress:/var/www/html/wpai124-upgrade-smoke.php
wp=("${compose[@]}" run --rm cli)

"${wp[@]}" core install \
    --url=https://localhost \
    --title='WP AI Bridge published upgrade fixture' \
    --admin_user=admin \
    --admin_password='isolated-fixture-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root

adapter_url="${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}"
"${wp[@]}" plugin install "$adapter_url" --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wpai124-published-v0.4.2.zip --activate --allow-root
baseline_installed="$("${wp[@]}" plugin get wp-ai-bridge --field=version --allow-root | tail -n 1)"
test "$baseline_installed" = "0.4.2" || {
    echo "ERROR: exact published baseline was not installed." >&2
    exit 1
}
"${compose[@]}" run --rm -e WPAI_UPGRADE_PHASE=before cli eval-file /var/www/html/wpai124-upgrade-smoke.php --user=1 --allow-root

# --force invokes the normal WordPress Core upgrader for an already installed
# canonical plugin, instead of deleting/reinstalling or creating a second root.
"${wp[@]}" plugin install /var/www/html/wpai124-candidate-v0.5.0.zip --force --allow-root
"${wp[@]}" plugin is-active wp-ai-bridge --allow-root
installed="$("${wp[@]}" plugin get wp-ai-bridge --field=version --allow-root | tail -n 1)"
test "$installed" = "$expected_version" || {
    echo "ERROR: WordPress Core replacement did not install the exact release-candidate version." >&2
    exit 1
}
plugin_dir="$("${wp[@]}" eval 'echo plugin_basename(WP_AI_BRIDGE_FILE);' --allow-root | tail -n 1)"
test "$plugin_dir" = "wp-ai-bridge/wp-ai-bridge.php" || {
    echo "ERROR: canonical installed plugin identity changed during the upgrade." >&2
    exit 1
}
"${compose[@]}" run --rm -e WPAI_UPGRADE_PHASE=after cli eval-file /var/www/html/wpai124-upgrade-smoke.php --user=1 --allow-root
actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_adapter="$("${wp[@]}" plugin get mcp-adapter --field=version --allow-root | tail -n 1)"
echo "PASS: published 0.4.2 -> candidate ${expected_version} on WordPress ${actual_wp}, official MCP Adapter ${actual_adapter}; canonical OAuth/Workspace and safe grants preserved."

