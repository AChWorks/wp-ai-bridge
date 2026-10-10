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
# Shared disposable /tmp lets real web and CLI worker cleanup inspect the same ZIPs.
"${compose[@]}" exec -T -u root wordpress chmod 1777 /tmp
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
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue108-multisite-smoke.php --user=1 --allow-root

"${wp[@]}" plugin activate wpai-network-source --network --allow-root >/dev/null
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue46-network-active-smoke.php --user=1 --allow-root
"${wp[@]}" plugin deactivate wpai-network-source --network --allow-root >/dev/null
"${compose[@]}" exec -T wordpress rm -rf /var/www/html/wp-content/plugins/wpai-network-source

# R1: network deactivation and uninstall retire all blog-local artifacts.
run_lifecycle() {
    local mode="$1"
    "${compose[@]}" run --rm -e "WPAI108_LIFECYCLE_MODE=$mode" -e WPAI108_LIFECYCLE_NETWORK=1 cli         eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue108-lifecycle-smoke.php         --user=1 --allow-root
}
run_lifecycle setup
# N1 MULTISITE: a different physical /tmp with an otherwise shared WordPress
# database and installation cannot retire ZIPs on either original blog.
"${compose[@]}" run --rm cli-unshared eval '
require_once WP_PLUGIN_DIR . "/wp-ai-bridge/src/Support/class-private-package-storage.php";
if ( ! is_wp_error( \WP_AI_Bridge\Support\Private_Package_Storage::existing_directory() ) ) {
    throw new RuntimeException( "The isolated network CLI unexpectedly sees the original storage domain." );
}
echo "PASS: distinct physical multisite tmp storage rejected.\n";
' --user=1 --allow-root
set +e
"${compose[@]}" run --rm cli-unshared plugin deactivate wp-ai-bridge --network --allow-root >/tmp/wpai108-unshared-multisite.log 2>&1
network_mismatch_result=$?
set -e
if [[ "$network_mismatch_result" == 0 ]] ||
   ! grep -q 'Private package files could not be retired safely' /tmp/wpai108-unshared-multisite.log; then
    echo 'ERROR: unshared-tmp network deactivation unexpectedly succeeded.' >&2
    tail -n 18 /tmp/wpai108-unshared-multisite.log >&2
    exit 1
fi
"${wp[@]}" eval '
require_once ABSPATH . "wp-admin/includes/plugin.php";
if ( ! is_plugin_active_for_network( "wp-ai-bridge/wp-ai-bridge.php" ) ) {
    throw new RuntimeException( "Mismatched temporary root incorrectly deactivated network Bridge." );
}
' --allow-root
run_lifecycle verify-staged
set +e
"${compose[@]}" run --rm cli-unshared eval '
define( "WP_UNINSTALL_PLUGIN", "wp-ai-bridge/wp-ai-bridge.php" );
require WP_PLUGIN_DIR . "/wp-ai-bridge/uninstall.php";
' --allow-root >/tmp/wpai108-unshared-ms-uninstall.log 2>&1
different_uninstall_result=$?
set -e
if [[ "$different_uninstall_result" == 0 ]] ||
   ! grep -q 'private ZIP cleanup could not finish safely' /tmp/wpai108-unshared-ms-uninstall.log; then
    echo 'ERROR: mismatched CLI network uninstall falsely retired private ZIPs.' >&2
    tail -n 18 /tmp/wpai108-unshared-ms-uninstall.log >&2
    exit 1
fi
run_lifecycle verify-staged
echo 'PASS: Issue #108 N1 two-blog network deactivation/uninstall fail closed on independent CLI tmp storage.'
"${wp[@]}" plugin deactivate wp-ai-bridge --network --allow-root
run_lifecycle verify
run_lifecycle cleanup
"${wp[@]}" plugin activate wp-ai-bridge --network --allow-root >/dev/null
run_lifecycle setup
"${wp[@]}" plugin deactivate wp-ai-bridge --network --allow-root
"${wp[@]}" plugin uninstall wp-ai-bridge --skip-delete --allow-root
run_lifecycle verify
run_lifecycle cleanup

echo "PASS: Issue #46 multisite integration suite for ${wordpress_tag}."
