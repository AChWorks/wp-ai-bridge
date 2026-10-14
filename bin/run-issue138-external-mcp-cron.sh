#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
export WORDPRESS_TAG=6.9-php8.4-apache
export WPAI_CI_PULL_POLICY=never
export COMPOSE_PROJECT_NAME="wpai-138-external-${BASHPID}"
dc() { docker compose -f tests/integration/compose.yml "$@"; }
wp() { dc run --rm cli "$@"; }
token_file="$(mktemp /tmp/wpai138-http-token.XXXXXX)"
chmod 600 "$token_file"
cleanup() {
  rm -f "$token_file"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT
bash bin/build-zip.sh
dc up -d db wordpress
for attempt in $(seq 1 50); do
  if dc exec -T wordpress test -s /var/www/html/wp-load.php; then break; fi
  if [ "$attempt" -eq 50 ]; then echo "FAIL: WordPress not ready"; exit 1; fi
  sleep 2
done
host_port="$(dc port wordpress 443 | awk -F: '{print $NF}')"
test -n "$host_port"
dc cp build/wp-ai-bridge.zip wordpress:/var/www/html/wp-ai-bridge.zip
wp core install --url="https://localhost:$host_port" --title="Native Cron external MCP proof" --admin_user=admin --admin_password=ephemeral-only --admin_email=admin@example.invalid --skip-email --allow-root
wp rewrite structure '/%postname%/' --hard --allow-root
wp rewrite flush --hard --allow-root
wp plugin install https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip --activate --allow-root
wp plugin install /var/www/html/wp-ai-bridge.zip --activate --allow-root
# Test installed native-callback and aliased-runner safety before external MCP.
dc cp tests/integration/issue138-targeted-alias-idempotency-smoke.php wordpress:/var/www/html/wpai138-targeted-alias-idempotency-smoke.php
wp eval-file /var/www/html/wpai138-targeted-alias-idempotency-smoke.php --user=1 --allow-root
dc exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
dc cp tests/fixtures/issue138-cron-external-provider.php wordpress:/var/www/html/wp-content/mu-plugins/wpai138-cron-external-provider.php
# Docker cp preserves source 0640 on some runner checkouts. WordPress HTTP
# runs as www-data while WP-CLI runs as root: both MUST load this MU fixture.
dc exec -T wordpress chmod 0644 /var/www/html/wp-content/mu-plugins/wpai138-cron-external-provider.php
# Disposable localhost-only Apache SSL, never a production endpoint.
dc exec -T wordpress bash -ec 'install -d -o www-data -g www-data -m 0775 /var/www/html/wp-content/uploads; chown -R www-data:www-data /var/www/html/wp-content/uploads'
dc exec -T wordpress bash -ec '
a2enmod ssl >/dev/null
openssl req -x509 -nodes -newkey rsa:2048 -keyout /etc/ssl/private/wpai-local.key -out /etc/ssl/certs/wpai-local.crt -days 1 -subj "/CN=localhost" >/dev/null 2>&1
sed -i "s@/etc/ssl/certs/ssl-cert-snakeoil.pem@/etc/ssl/certs/wpai-local.crt@; s@/etc/ssl/private/ssl-cert-snakeoil.key@/etc/ssl/private/wpai-local.key@" /etc/apache2/sites-available/default-ssl.conf
a2ensite default-ssl >/dev/null
apache2ctl graceful >/dev/null
'
echo "CHECK: temporary Apache HTTPS listeners (no secrets)."
dc exec -T wordpress bash -ec 'apache2ctl -S 2>&1 | tail -n 18; grep -n Listen /etc/apache2/ports.conf; (command -v ss >/dev/null && ss -lnt | tail -n 15) || true; curl -kIs --max-time 4 https://127.0.0.1/ | head -n 5 || true'
sleep 3
wp eval 'wp_schedule_single_event(time()+7200, "wpai138_external_cron_fixture", array(), true);' --user=1 --allow-root
wp eval 'echo "CHECK: callback=" . (false !== has_action("wpai138_external_cron_fixture") ? "registered" : "missing") . " profiles=" . count(apply_filters("wp_ai_bridge_cron_profiles", array())) . PHP_EOL;' --user=1 --allow-root
curl --silent --show-error --insecure --fail --max-time 12 "https://localhost:$host_port/wp-json/" | python3 -c 'import json,sys; data=json.load(sys.stdin); assert "namespaces" in data, "REST did not route"'
wp eval "$(cat <<'PHP'
$settings = new \WP_AI_Bridge\Support\Settings();
$grants = $settings->defaults();
$grants[\WP_AI_Bridge\Support\Settings::GROUP_SITE_READ] = 1;
$grants[\WP_AI_Bridge\Support\Settings::GROUP_SITE_CONFIG] = 1;
$grants[\WP_AI_Bridge\Support\Settings::GROUP_CODE_EXTENSIONS] = 1;
update_option(\WP_AI_Bridge\Support\Settings::OPTION_NAME, $grants, false);
$store = new \WP_AI_Bridge\Auth\OAuth_Store();
$server = new \WP_AI_Bridge\Auth\OAuth_Server($store);
echo $store->issue(
  \WP_AI_Bridge\Auth\OAuth_Store::TYPE_ACCESS,
  array(
    'user_id' => get_current_user_id(),
    'client_id' => \WP_AI_Bridge\Auth\OAuth_Server::CHATGPT_CLIENT_ID,
    'resource' => $server->mcp_endpoint_url(),
    'scope' => \WP_AI_Bridge\Auth\OAuth_Server::SCOPE_MCP . ' ' . \WP_AI_Bridge\Auth\OAuth_Server::SCOPE_OFFLINE
  ), 600
);
PHP
)" --user=1 --allow-root > "$token_file"
if [ "$(wc -c < "$token_file")" -lt 20 ]; then echo "FAIL: test OAuth token not issued"; exit 1; fi
python3 "$root/tests/integration/issue138-external-mcp-cron.py" "$host_port" "$token_file"
wp eval 'if (get_option("wpai138_unexpected_callback_execution", false)) { exit(1); } if ((int)get_option("wpai138_external_runnow_count", 0) !== 1) { exit(2); }' --user=1 --allow-root
echo "PASS: #138 disposable external HTTPS standards client verified exact provider-managed WordPress Cron lifecycle."

