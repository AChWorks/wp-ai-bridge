#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
tag="${1:-6.9-php8.4-apache}"
export WORDPRESS_TAG="$tag"
export COMPOSE_PROJECT_NAME="wpai134-native-$(printf '%s' "$tag" | tr -c 'A-Za-z0-9' '-')-$$"
compose=(docker compose -f "$root/tests/integration/compose.yml")
cleanup() {
    docker compose -f "$root/tests/integration/compose.yml" -f "$root/tests/integration/compose-shared-private.yml" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

bash "$root/bin/build-zip.sh"
"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 30); do
    if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-load.php; then break; fi
    if [[ "$attempt" == "30" ]]; then echo 'ERROR: disposable WordPress not ready.' >&2; exit 1; fi
    sleep 2
done

"${compose[@]}" cp "$root/build/wp-ai-bridge.zip" wordpress:/var/www/html/wp-ai-bridge.zip
wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install --url=https://localhost \
    --title='Private transfer native acceptance' --admin_user=admin \
    --admin_password='test-only-password' --admin_email=admin@example.invalid \
    --skip-email --allow-root
"${wp[@]}" plugin install \
    "${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip}" \
    --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --activate --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge.zip
tar --mode='u+rwX,go+rX' -C "$root" -cf - tests \
    | "${compose[@]}" exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/wp-ai-bridge

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
echo "FOCUSED candidate $(git -c safe.directory="$root" -C "$root" rev-parse HEAD); WordPress $actual_wp; PHP $actual_php; Adapter ${MCP_ADAPTER_EXPECTED_VERSION:-0.7.0}"
for fixture in issue134-payload-settings-smoke.php issue134-direct-mcp-bytes-smoke.php \
    issue134-public-session-smoke.php issue134-private-staging-smoke.php \
    issue134-review-recovery-smoke.php; do
    echo "== $fixture =="
    "${wp[@]}" eval-file "wp-content/plugins/wp-ai-bridge/tests/integration/$fixture" --user=1 --allow-root
done
# One deterministic two-process native CAS race, with independent staged
# leases and an ordinary WordPress writer as a competing author.
if [[ "$tag" == '6.9-php8.4-apache' ]]; then
    bash "$root/bin/run-issue134-cas-race.sh"
    bash "$root/bin/run-issue134-media-admission-race.sh"
fi
echo "PASS: Issue #134 native source/consumer integration suite for $tag."
