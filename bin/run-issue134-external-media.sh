#!/usr/bin/env bash
set -euo pipefail

# Separate-process, actual HTTPS/OAuth/MCP direct Media file exchange.
# Runs ONLY against a fresh, isolated localhost disposable WordPress project.
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
: "${WORDPRESS_TAG:=${1:-6.9-php8.4-apache}}"
export WORDPRESS_TAG
export WPAI_CI_PULL_POLICY=never
export COMPOSE_PROJECT_NAME="wpai134-direct-media-$$"
compose=(docker compose -f tests/integration/compose.yml)
wp=("${compose[@]}" run --rm cli)
umask 077
token_file="$(mktemp /tmp/wpai134-mcp-media-oauth.XXXXXX)"
cleanup() {
    rm -f "$token_file"
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

bash bin/build-zip.sh
"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 50); do
    if "${compose[@]}" exec -T wordpress sh -c 'test -s /var/www/html/wp-load.php && test -s /var/www/html/wp-admin/includes/upgrade.php && test -s /var/www/html/wp-config.php'; then
        break
    fi
    if [[ "$attempt" == "50" ]]; then
        echo 'ERROR: isolated WordPress is not ready.' >&2
        exit 1
    fi
    sleep 2
done

binding="$("${compose[@]}" port wordpress 443 | tail -n 1)"
port="${binding##*:}"
if [[ ! "$port" =~ ^[1-9][0-9]*$ || "$port" -gt 65535 ]]; then
    echo 'ERROR: isolated WordPress has no valid HTTPS loopback port.' >&2
    exit 1
fi
"${compose[@]}" cp build/wp-ai-bridge.zip wordpress:/var/www/html/wp-ai-bridge.zip
"${wp[@]}" core install --url="https://localhost:$port" --title='Bridge external Media byte acceptance' \
    --admin_user=admin --admin_password='disposable-only-bridge-media' \
    --admin_email=admin@example.invalid --skip-email --allow-root
"${wp[@]}" rewrite structure '/%postname%/' --hard --allow-root
"${wp[@]}" rewrite flush --hard --allow-root
"${wp[@]}" plugin install https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --activate --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-ai-bridge.zip

# Disposable, localhost-only TLS fixture. Real-world TLS validation remains mandatory.
"${compose[@]}" exec -T wordpress bash -ec '
    a2enmod ssl >/dev/null
    openssl req -x509 -nodes -newkey rsa:2048 \
      -keyout /etc/ssl/private/wpai134-local.key \
      -out /etc/ssl/certs/wpai134-local.crt -days 1 -subj "/CN=localhost" >/dev/null 2>&1
    sed -i "s@/etc/ssl/certs/ssl-cert-snakeoil.pem@/etc/ssl/certs/wpai134-local.crt@; s@/etc/ssl/private/ssl-cert-snakeoil.key@/etc/ssl/private/wpai134-local.key@" /etc/apache2/sites-available/default-ssl.conf
    a2ensite default-ssl >/dev/null
    apache2ctl graceful >/dev/null
'
sleep 2
curl -ksf --connect-timeout 5 --max-time 15 "https://localhost:$port/wp-json/" \
    | python3 -c 'import json,sys; assert "namespaces" in json.load(sys.stdin)'

# Issue a short-lived OAuth bearer solely inside the disposable WordPress DB.
# Neither the temporary bearer nor source file bytes are printed/logged.
"${wp[@]}" eval "$(cat <<'PHP'
$settings = new \WP_AI_Bridge\Support\Settings();
$grants = $settings->defaults();
$grants[\WP_AI_Bridge\Support\Settings::GROUP_SITE_READ] = 1;
$grants[\WP_AI_Bridge\Support\Settings::GROUP_BUILDER_WRITE] = 1;
update_option(\WP_AI_Bridge\Support\Settings::OPTION_NAME, $grants, false);
$store = new \WP_AI_Bridge\Auth\OAuth_Store();
$server = new \WP_AI_Bridge\Auth\OAuth_Server($store);
echo $store->issue(\WP_AI_Bridge\Auth\OAuth_Store::TYPE_ACCESS,
 array('user_id'=>get_current_user_id(),
 'client_id'=>\WP_AI_Bridge\Auth\OAuth_Server::CHATGPT_CLIENT_ID,
 'resource'=>$server->mcp_endpoint_url(),
 'scope'=>\WP_AI_Bridge\Auth\OAuth_Server::SCOPE_MCP . ' ' . \WP_AI_Bridge\Auth\OAuth_Server::SCOPE_OFFLINE), 900);
PHP
)" --user=1 --allow-root > "$token_file"
if [[ "$(wc -c < "$token_file")" -lt 20 ]]; then
    echo 'ERROR: disposable OAuth bearer was not issued.' >&2
    exit 1
fi

python3 tests/integration/issue134-external-mcp-media.py "$port" "$token_file"
echo 'PASS: Issue #134 direct, external HTTPS/OAuth/MCP two-way Media bytes and native WordPress claims.'
