#!/usr/bin/env bash
# Disposable WordPress+official MCP Adapter private DB staging acceptance.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
tag="${1:-6.9-php8.4-apache}"
export WORDPRESS_TAG="$tag"
export COMPOSE_PROJECT_NAME="wpai134-db-$(printf '%s' "$tag" | tr -c 'A-Za-z0-9' '-')-$$"
compose=(docker compose -f "$root/tests/integration/compose.yml")
cleanup() { "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT

# On the dedicated Docker test host PHP CLI may not be installed. Reuse the
# exact shipped file set for a disposable test ZIP; ordinary CI continues
# exercising bin/build-zip.sh and its ZipArchive/manifest checks.
if command -v php >/dev/null 2>&1 && php -r 'exit( class_exists("ZipArchive") ? 0 : 1 );'; then
  bash "$root/bin/build-zip.sh"
else
  stage="$root/build/db-smoke-stage/wp-ai-bridge"
  rm -rf "$root/build/db-smoke-stage"
  mkdir -p "$stage"
  cp "$root/wp-ai-bridge.php" "$root/uninstall.php" "$root/README.md" "$root/CHANGELOG.md" "$root/LICENSE" "$stage/"
  cp -R "$root/src" "$root/languages" "$stage/"
  rm -f "$root/build/wp-ai-bridge.zip"
  (cd "$root/build/db-smoke-stage" && zip -qr "$root/build/wp-ai-bridge.zip" wp-ai-bridge)
  unzip -tq "$root/build/wp-ai-bridge.zip" >/dev/null
  rm -rf "$root/build/db-smoke-stage"
fi
"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 45); do
  if "${compose[@]}" exec -T wordpress sh -c '
    test -s /var/www/html/wp-load.php &&
    test -s /var/www/html/wp-settings.php &&
    test -s /var/www/html/wp-includes/version.php &&
    test -s /var/www/html/wp-admin/includes/upgrade.php &&
    test -s /var/www/html/wp-config.php
  '; then break; fi
  if [[ "$attempt" == 45 ]]; then echo 'WordPress did not initialize' >&2; exit 1; fi
  sleep 2
done
wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install --url=https://localhost --title='Disposable private DB' \
  --admin_user=admin --admin_password='fixture-only-password' --admin_email=admin@example.invalid \
  --skip-email --allow-root
# Test-only hostile temporary path: ordinary production installs need no config.
"${wp[@]}" config set WP_TEMP_DIR /var/www/html/wp-content --allow-root
"${compose[@]}" cp "$root/build/wp-ai-bridge.zip" wordpress:/var/www/html/wp-ai-bridge.zip
"${wp[@]}" plugin install "${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip}" --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --activate --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge.zip
tar --mode='u+rwX,go+rX' -C "$root" -cf - tests |
  "${compose[@]}" exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/wp-ai-bridge
echo "Candidate $(git -c safe.directory="$root" -C "$root" rev-parse HEAD) WordPress $("${wp[@]}" core version --allow-root | tail -1) Adapter $("${wp[@]}" plugin get mcp-adapter --field=version --allow-root | tail -1)"
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue134-portable-db-staging-smoke.php --user=1 --allow-root
