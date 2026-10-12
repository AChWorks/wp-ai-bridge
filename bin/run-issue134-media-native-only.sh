#!/usr/bin/env bash
# Isolated, one-command two-worker Media admission regression for #134.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
export WORDPRESS_TAG="${1:-6.9-php8.4-apache}"
export COMPOSE_PROJECT_NAME="wpai134-media-race-$$"
compose=(docker compose -f "$root/tests/integration/compose.yml" -f "$root/tests/integration/compose-shared-private.yml")
cleanup() { "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT
bash "$root/bin/build-zip.sh"
"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 30); do
    if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-load.php; then break; fi
    if [[ "$attempt" == 30 ]]; then echo 'ERROR: WordPress not ready.' >&2; exit 1; fi
    sleep 2
done
"${compose[@]}" cp "$root/build/wp-ai-bridge.zip" wordpress:/var/www/html/wp-ai-bridge.zip
wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install --url=https://localhost --title='Transfer Media concurrent acceptance' \
    --admin_user=admin --admin_password='test-only-password' \
    --admin_email=admin@example.invalid --skip-email --allow-root
"${wp[@]}" plugin install \
    "${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip}" \
    --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --activate --allow-root
tar --mode='u+rwX,go+rX' -C "$root" -cf - tests \
    | "${compose[@]}" exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/wp-ai-bridge
echo "Native Media concurrency candidate: $(git -c safe.directory="$root" -C "$root" rev-parse HEAD)"
bash "$root/bin/run-issue134-media-admission-race.sh"
