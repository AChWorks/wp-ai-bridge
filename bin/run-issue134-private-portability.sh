#!/usr/bin/env bash
set -euo pipefail

# One representative real WordPress/Adapter lane: published v0.6.5 incomplete
# lease -> candidate native upgrade -> served WP_TEMP_DIR fallback and denial.
# No production hosts, settings or data are touched.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tag="${1:-6.9-php8.4-apache}"
export WORDPRESS_TAG="$tag"
safe_tag="$(printf '%s' "$tag" | tr -c 'A-Za-z0-9' '-')"
export COMPOSE_PROJECT_NAME="wpai134-port-${safe_tag}-${GITHUB_RUN_ID:-local}-$$"
compose=(docker compose -f "$root/tests/integration/compose.yml" -f "$root/tests/integration/compose-shared-private.yml")
scratch="$(mktemp -d "${TMPDIR:-/tmp}/wpai134-portability-XXXXXX")"
chmod 0700 "$scratch"
cleanup() {
    local rc=$?
    trap - EXIT
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf -- "$scratch"
    exit "$rc"
}
trap cleanup EXIT

baseline="$scratch/published-v0.6.5.zip"
curl -fsSL --retry 2 --connect-timeout 15 --max-time 120     https://github.com/AChWorks/wp-ai-bridge/releases/download/v0.6.5/wp-ai-bridge.zip     -o "$baseline"
printf '%s  %s\n' 'd760bd5c3ae8707220d6c112b8002bdbf10e795f3ac2830721bd35acbd6a7e5d' "$baseline" |
    sha256sum --check --status || {
        echo 'ERROR: published v0.6.5 baseline ZIP digest changed.' >&2
        exit 1
    }
bash "$root/bin/build-zip.sh"
candidate="$root/build/wp-ai-bridge.zip"
test -s "$candidate"
echo 'PASS: pinned real v0.6.5 package and current candidate ZIP available.'

"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 45); do
    if "${compose[@]}" exec -T wordpress sh -c 'test -s /var/www/html/wp-load.php && test -s /var/www/html/wp-config.php'; then
        break
    fi
    if [[ "$attempt" == 45 ]]; then
        echo 'ERROR: disposable WordPress site was not initialized.' >&2
        exit 1
    fi
    sleep 2
done
"${compose[@]}" cp "$baseline" wordpress:/var/www/html/wpai134-baseline.zip
"${compose[@]}" cp "$candidate" wordpress:/var/www/html/wpai134-candidate.zip
"${compose[@]}" cp "$root/tests/integration/issue134-existing-lease-upgrade-smoke.php" wordpress:/var/www/html/wpai134-lease-upgrade.php
wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install --url=https://localhost --title='Private portability acceptance'     --admin_user=admin --admin_password='test-only-password'     --admin_email=admin@example.invalid --skip-email --allow-root
"${wp[@]}" plugin install "${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip}" --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wpai134-baseline.zip --activate --allow-root
test "$("${wp[@]}" plugin get wp-ai-bridge --field=version --allow-root | tail -n 1)" = '0.6.5'
echo '== Published v0.6.5 incomplete lease, before candidate upgrade =='
"${wp[@]}" eval-file /var/www/html/wpai134-lease-upgrade.php --user=1 --allow-root

# Real WordPress Core installer replaces only this disposable plugin.
"${wp[@]}" plugin install /var/www/html/wpai134-candidate.zip --force --allow-root
"${wp[@]}" plugin is-active wp-ai-bridge --allow-root
"${wp[@]}" config set WP_TEMP_DIR /var/www/html/wp-content --allow-root
tar --mode='u+rwX,go+rX' -C "$root" -cf - tests |
    "${compose[@]}" exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/wp-ai-bridge
echo '== Resume published lease after native ZIP replacement and served WP_TEMP_DIR =='
"${wp[@]}" eval-file /var/www/html/wpai134-lease-upgrade.php --user=1 --allow-root
echo '== Safe off-webroot fallback and native WXR artifact =='
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue134-host-private-fallback-smoke.php --user=1 --allow-root
echo '== Real WordPress native Media and plugin ZIP final placement =='
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue173-native-temp-smoke.php --user=1 --allow-root
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue173-provider-media-upload-smoke.php --user=1 --allow-root
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue173-native-package-smoke.php --user=1 --allow-root
echo '== Restricted open_basedir, no private temp available, pre-write denial =='
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue134-restricted-temp-smoke.php --user=1 --allow-root

echo '== Two blog identities on the same disposable multisite =='
"${wp[@]}" core multisite-convert --title='Private portability network' --allow-root
"${wp[@]}" plugin activate wp-ai-bridge --network --allow-root
"${wp[@]}" site create --slug=second --title='Second disposable blog' --allow-root
"${wp[@]}" eval-file wp-content/plugins/wp-ai-bridge/tests/integration/issue134-multisite-private-fallback-smoke.php --user=1 --allow-root

# Quality CI separately uses SIGKILL to exercise an actual abandoned 0600
# one-shot Media/ZIP input and verify next-operation bounded orphan recovery.
echo 'PASS: #134 pinned baseline upgrade, host fallback, denial and two-blog isolation on real WordPress.'
