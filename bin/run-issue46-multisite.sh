#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpai-issue46-ms-${safe_tag}-$$"

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
        echo "ERROR: WordPress files were not initialized for Issue #46 multisite smoke." >&2
        exit 1
    fi
    sleep 2
done

"${compose[@]}" cp "$root/build/wp-ai-bridge.zip" wordpress:/var/www/html/wp-ai-bridge.zip
wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core multisite-install \
    --url=http://wordpress \
    --title='WP AI Bridge Issue 46 Multisite' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root
"${wp[@]}" site create --slug=secondary --title="Issue 46 Secondary Site" --email=admin@example.invalid --allow-root >/dev/null
mcp_adapter_url="${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}"
"${wp[@]}" plugin install "$mcp_adapter_url" --activate-network --allow-root
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --activate-network --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge.zip

tar --mode='u+rwX,go+rX' -C "$root" -cf - tests \
    | "${compose[@]}" exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/wp-ai-bridge
# Real network-option save hooks require the actual disposable Super Admin.
"${compose[@]}" run --rm -e WPAI206_EXPECT_DATABASE=wordpress cli eval 'define("WPAI206_DISPOSABLE", true); require ABSPATH . "wp-content/plugins/wp-ai-bridge/tests/integration/issue206-settings-save-smoke.php";' --user=1 --allow-root
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue135-no-private-packages-smoke.php --user=1 --allow-root
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue130-141-multisite-smoke.php --user=1 --allow-root
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue126-128-multisite-smoke.php --user=1 --allow-root
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue126-delete-native-multisite-smoke.php --user=1 --allow-root
# Run each native hook exception and non-native Ability lifecycle case
# in its own disposable PHP request. Provider exceptions may be normalized
# by WordPress 7.1+, so prove same-request WP_Hook recovery explicitly.
for stage in pre_delete_post before_delete_post; do
    "${compose[@]}" run --rm -e "WPAI_ALL_HOOK_STAGE=${stage}" cli eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue126-all-hook-exception-smoke.php --user=1 --allow-root
done
for stage in permission post_cas compensation activity guard_pre guard_before; do
    "${compose[@]}" run --rm -e "WPAI_HOOK_BOUNDARY_STAGE=${stage}" cli eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue126-hook-boundary-smoke.php --user=1 --allow-root
done
# An unprovable dispatcher is the ONLY intentionally non-zero test. PHP
# exit(70), not a throwable, must bypass WP_Ability::invoke_callback()
# including on WordPress 7.1+, and must not print a continuation marker.
if unrecoverable_output=$("${compose[@]}" run --rm -e WPAI_HOOK_BOUNDARY_STAGE=unrecoverable cli eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue126-hook-boundary-smoke.php --user=1 --allow-root 2>&1); then
    echo "ERROR: Unrecoverable Workspace hook dispatcher continued normally." >&2
    exit 1
else
    unrecoverable_code=$?
fi
if [[ "$unrecoverable_code" != "70" ||
      "$unrecoverable_output" != *"WP AI Bridge: unsafe WordPress hook dispatcher; request stopped."* ||
      "$unrecoverable_output" != *"PASS: #126 PHP shutdown completed with quarantined WordPress hooks."* ||
      "$unrecoverable_output" == *"UNSAFE_BOUNDARY_CONTINUED"* ||
      "$unrecoverable_output" == *"UNSAFE_WORDPRESS_SHUTDOWN_HOOK_RAN"* ||
      "$unrecoverable_output" == *"UNSAFE_WORDPRESS_ALL_SHUTDOWN_RAN"* ||
      "$unrecoverable_output" == *"UNSAFE_TERMINAL_HOOK_REGISTRY"* ]]; then
    printf '%s\n' "$unrecoverable_output" >&2
    echo "ERROR: Unrecoverable Workspace hook boundary did not terminate safely." >&2
    exit 1
fi
echo "PASS: #126 unrecoverable hook dispatcher blocked WordPress shutdown/all callbacks before PHP exit."
# Execute the actual secondary-blog 199->200 race in ONE specialized CI
# lane. This runner does not receive MCP_ADAPTER_EXPECTED_VERSION; the old
# condition silently skipped the entire fixture even though CI was green.
if [[ "$wordpress_tag" == '6.9-php8.4-apache' ]]; then
    WPAI_QUOTA_MULTISITE=1 bash "$root/bin/run-issue126-quota-race.sh"
fi

"${compose[@]}" exec -T wordpress sh -lc '
set -eu
mkdir -p /var/www/html/wp-content/plugins/wpai-network-source
cat > /var/www/html/wp-content/plugins/wpai-network-source/wpai-network-source.php <<"PHP"
<?php
/*
Plugin Name: WPNB Network Source
*/
function wpai_network_source_value() { return "network-original"; }
PHP
chown -R www-data:www-data /var/www/html/wp-content/plugins/wpai-network-source
chmod 0666 /var/www/html/wp-content/plugins/wpai-network-source/wpai-network-source.php
'

"${wp[@]}" plugin activate wpai-network-source --network --allow-root >/dev/null
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue46-network-active-smoke.php --user=1 --allow-root
"${wp[@]}" plugin deactivate wpai-network-source --network --allow-root >/dev/null
"${compose[@]}" exec -T wordpress rm -rf /var/www/html/wp-content/plugins/wpai-network-source

echo "PASS: Issue #46 multisite integration suite for ${wordpress_tag}."
