#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpnb-issue46-ms-${safe_tag}-$$"

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

"${compose[@]}" cp "$root/build/wp-native-builder-bridge.zip" wordpress:/var/www/html/wp-native-builder-bridge.zip
wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core multisite-install \
    --url=http://wordpress \
    --title='WP Native Builder Bridge Issue 46 Multisite' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root
"${wp[@]}" site create --slug=secondary --title="Issue 46 Secondary Site" --email=admin@example.invalid --allow-root >/dev/null
mcp_adapter_url="${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}"
"${wp[@]}" plugin install "$mcp_adapter_url" --activate-network --allow-root
"${wp[@]}" plugin install /var/www/html/wp-native-builder-bridge.zip --activate-network --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-native-builder-bridge.zip

tar --mode='u+rwX,go+rX' -C "$root" -cf - tests \
    | "${compose[@]}" exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/wp-native-builder-bridge

"${compose[@]}" exec -T wordpress sh -lc '
set -eu
mkdir -p /var/www/html/wp-content/plugins/wpnb-network-source
cat > /var/www/html/wp-content/plugins/wpnb-network-source/wpnb-network-source.php <<"PHP"
<?php
/*
Plugin Name: WPNB Network Source
*/
function wpnb_network_source_value() { return "network-original"; }
PHP
chown -R www-data:www-data /var/www/html/wp-content/plugins/wpnb-network-source
chmod 0666 /var/www/html/wp-content/plugins/wpnb-network-source/wpnb-network-source.php
'
"${wp[@]}" plugin activate wpnb-network-source --network --allow-root >/dev/null
"${wp[@]}" eval-file wp-content/plugins/wp-native-builder-bridge/tests/integration/issue46-network-active-smoke.php --user=1 --allow-root
"${wp[@]}" plugin deactivate wpnb-network-source --network --allow-root >/dev/null
"${compose[@]}" exec -T wordpress rm -rf /var/www/html/wp-content/plugins/wpnb-network-source

echo "PASS: Issue #46 multisite integration suite for ${wordpress_tag}."
