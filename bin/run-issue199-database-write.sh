#!/usr/bin/env bash
set -euo pipefail

# Install the candidate release ZIP into an isolated real-engine WordPress stack.
# This focused suite complements the ordinary six-lane compatibility suite.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
database_engine="${1:-mariadb}"
wordpress_tag="${2:-6.9-php8.4-apache}"
compose=(docker compose -f "$root/tests/integration/compose.yml" -f "$root/tests/integration/compose-completion-private.yml")
case "$database_engine" in
    mariadb) ;;
    mysql) compose+=(-f "$root/tests/integration/compose-database-write-mysql.yml") ;;
    *) echo "ERROR: unsupported typed-write database engine: ${database_engine}" >&2; exit 1 ;;
esac
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpai199-write-${database_engine}-${safe_tag}-${GITHUB_RUN_ID:-local}-$$"

fixtures=(
    issue199-database-write-smoke.php
    issue199-database-write-staging-smoke.php
    issue199-database-write-mcp-smoke.php
    issue199-database-write-session-smoke.php
    issue199-database-write-identity-smoke.php
    issue199-database-write-concurrency-smoke.php
    issue199-database-write-transport-smoke.php
    issue199-native-option-write-smoke.php
    issue199-native-option-write-mcp-smoke.php
    issue199-native-option-write-session-smoke.php
)
for fixture in issue199-native-options-helper.php issue199-native-option-write-helper.php \
    issue199-native-option-write-transport-proxy.php \
    issue199-database-write-helper.php issue199-database-write-concurrency-worker.php \
    issue199-database-write-transport-proxy.php issue199-database-write-transport-worker.php "${fixtures[@]}"; do
    test -s "$root/tests/integration/$fixture" || {
        echo "ERROR: required typed-write fixture is missing: ${fixture}" >&2
        exit 1
    }
done
cleanup() {
    local result=$?
    trap - EXIT
    if [[ "$result" != 0 ]]; then
        "${compose[@]}" logs --no-color --tail=80 db wordpress >&2 || true
    fi
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    exit "$result"
}
trap cleanup EXIT

bash "$root/bin/build-zip.sh"
"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 45); do
    if "${compose[@]}" exec -T wordpress sh -c '
        test -s /var/www/html/wp-load.php &&
        test -s /var/www/html/wp-settings.php &&
        test -s /var/www/html/wp-includes/version.php &&
        test -s /var/www/html/wp-admin/includes/upgrade.php &&
        test -s /var/www/html/wp-config.php
    '; then
        break
    fi
    if [[ "$attempt" == "45" ]]; then
        echo 'ERROR: WordPress Core files/configuration did not initialize for typed writes.' >&2
        exit 1
    fi
    sleep 2
done

wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install \
    --url=http://wordpress \
    --title="WP AI Bridge typed writes / ${database_engine}" \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root
"${wp[@]}" plugin install "${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.7.1/mcp-adapter.zip}" --activate --allow-root
"${compose[@]}" cp "$root/build/wp-ai-bridge.zip" wordpress:/var/www/html/wp-ai-bridge.zip
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --activate --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge.zip
# Fixtures remain absent from the shipped ZIP. Only tests are overlaid afterward.
tar --mode='u+rwX,go+rX' -C "$root" -cf - tests \
    | "${compose[@]}" exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/wp-ai-bridge

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
actual_adapter="$("${wp[@]}" plugin get mcp-adapter --field=version --allow-root | tail -n 1)"
if [[ "$actual_adapter" != "${MCP_ADAPTER_EXPECTED_VERSION:-0.7.1}" ]]; then
    echo "ERROR: focused typed-write Adapter version mismatch: ${actual_adapter}." >&2
    exit 1
fi
echo "Typed-write baseline: WordPress ${actual_wp}; PHP ${actual_php}; Adapter ${actual_adapter}; expected engine ${database_engine}"
"${compose[@]}" run --rm -e "WPAI199W_DATABASE_ENGINE=$database_engine" cli eval '
global $wpdb;
$version = (string) $wpdb->get_var("SELECT VERSION()");
$engine = getenv("WPAI199W_DATABASE_ENGINE");
$is_mariadb = false !== stripos($version, "MariaDB");
if (("mysql" === $engine && ($is_mariadb || 0 !== strpos($version, "8.4."))) || ("mariadb" === $engine && !$is_mariadb)) {
    throw new RuntimeException("Typed-write database engine does not match the selected CI lane: " . $version);
}
echo "Verified native database: " . $version . "\n";
' --user=1 --allow-root

# Session/transport failure and concurrency fixtures intentionally run only here,
# against each actual engine. The coordinator spawns its own independent workers.
# Shared helper files are copied above but never invoked as standalone tests.
for fixture in "${fixtures[@]}"; do
    echo "== ${database_engine} / ${fixture} =="
    "${wp[@]}" eval-file "wp-content/plugins/wp-ai-bridge/tests/integration/${fixture}" --user=1 --allow-root
done
# New administration families extend these same engine lanes; existing fixtures above remain intact.
source "$root/bin/issue199-completion-runtime.sh"
wpai199c_bootstrap true "$database_engine"
wpai199c_eval issue199-database-administration-smoke.php
wpai199c_eval issue199-native-configuration-smoke.php
wpai199c_eval issue199-native-schedulers-smoke.php
for as_version in 3.9.3 4.2.0; do
    wpai199c_install_as "$as_version"
    wpai199c_eval issue199-native-schedulers-smoke.php
done
wpai199c_eval issue199-completion-concurrency-smoke.php
wpai199c_eval issue199-completion-mcp-smoke.php
wpai199c_eval issue199-backup-smoke.php
wpai199c_eval issue199-completion-derived-bootstrap-smoke.php
wpai199c_remove_provider

echo "PASS: focused typed-write native suite for ${database_engine} / ${wordpress_tag}."
