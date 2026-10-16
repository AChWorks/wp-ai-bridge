#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
: "${WORDPRESS_TAG:=6.9-php8.4-apache}"
export WORDPRESS_TAG
export WPAI_CI_PULL_POLICY=never
export COMPOSE_PROJECT_NAME="wpai139-external-$$"
compose=(docker compose -f tests/integration/compose.yml)
wp=("${compose[@]}" run --rm cli)
umask 077
token_file="$(mktemp /tmp/wpai139-external-oauth.XXXXXX)"
cleanup() {
    rm -f "$token_file"
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

bash bin/build-zip.sh
"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 50); do
    if "${compose[@]}" exec -T wordpress sh -c 'test -s /var/www/html/wp-load.php && test -s /var/www/html/wp-admin/includes/upgrade.php && test -s /var/www/html/wp-config.php' ; then
        break
    fi
    [[ "$attempt" != 50 ]] || { echo 'ERROR: isolated WordPress was not ready' >&2; exit 1; }
    sleep 2
done

host_port="$("${compose[@]}" port wordpress 443 | awk -F: '{print $NF}')"
[[ "$host_port" =~ ^[0-9]+$ ]] || { echo 'ERROR: missing loopback HTTPS port' >&2; exit 1; }
"${compose[@]}" cp build/wp-ai-bridge.zip wordpress:/var/www/html/wp-ai-bridge.zip
"${wp[@]}" core install --url="https://localhost:$host_port" --title='WXR external MCP proof' --admin_user=admin --admin_password=disposable-fixture-only --admin_email=admin@example.invalid --skip-email --allow-root
if [[ "${WPAI_ISSUE134_PUBLIC_TEMP:-0}" == "1" ]]; then
    # Disposable acceptance only: WordPress default temp is deliberately HTTP served.
    "${wp[@]}" config set WP_TEMP_DIR /var/www/html/wp-content --allow-root
fi
"${wp[@]}" rewrite structure '/%postname%/' --hard --allow-root
"${wp[@]}" rewrite flush --hard --allow-root
"${wp[@]}" plugin install https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wp-ai-bridge.zip --activate --allow-root
"${wp[@]}" plugin install wordpress-importer --version=0.9.6 --activate --allow-root
"${wp[@]}" config set WP_LOAD_IMPORTERS true --raw --allow-root >/dev/null
"${compose[@]}" exec -T wordpress bash -ec 'a2enmod ssl >/dev/null; openssl req -x509 -nodes -newkey rsa:2048 -keyout /etc/ssl/private/wpai-local.key -out /etc/ssl/certs/wpai-local.crt -days 1 -subj "/CN=localhost" >/dev/null 2>&1; sed -i "s@/etc/ssl/certs/ssl-cert-snakeoil.pem@/etc/ssl/certs/wpai-local.crt@; s@/etc/ssl/private/ssl-cert-snakeoil.key@/etc/ssl/private/wpai-local.key@" /etc/apache2/sites-available/default-ssl.conf; a2ensite default-ssl >/dev/null; apache2ctl graceful >/dev/null'
sleep 2
curl -ksf --max-time 15 "https://localhost:$host_port/wp-json/" | python3 -c 'import json,sys; assert "namespaces" in json.load(sys.stdin)'

"${wp[@]}" eval "$(cat <<'PHP'
$settings = new \WP_AI_Bridge\Support\Settings();
$grants = $settings->defaults();
$grants[\WP_AI_Bridge\Support\Settings::GROUP_SITE_READ] = 1;
$grants[\WP_AI_Bridge\Support\Settings::GROUP_SITE_CONFIG] = 1;
$grants[\WP_AI_Bridge\Support\Settings::GROUP_BUILDER_WRITE] = 1;
$grants[\WP_AI_Bridge\Support\Settings::GROUP_LIVE_CONTENT] = 1;
update_option(\WP_AI_Bridge\Support\Settings::OPTION_NAME, $grants, false);
$marker = 'wxr-e2e-' . bin2hex(random_bytes(5));
$post = wp_insert_post(array(
 'post_type'=>'post', 'post_status'=>'draft', 'post_title'=>$marker,
 'post_content'=>wp_slash(str_repeat("سلام وردپرس 🌍 Unicode / WXR\n", 8000) . $marker)
), true);
if (is_wp_error($post) || $post < 1) { exit(1); }
update_option('wpai139_disposable_marker', $marker, false);
$store = new \WP_AI_Bridge\Auth\OAuth_Store();
$server = new \WP_AI_Bridge\Auth\OAuth_Server($store);
echo $store->issue(\WP_AI_Bridge\Auth\OAuth_Store::TYPE_ACCESS,
array('user_id'=>get_current_user_id(),
'client_id'=>\WP_AI_Bridge\Auth\OAuth_Server::CHATGPT_CLIENT_ID,
'resource'=>$server->mcp_endpoint_url(),
'scope'=>\WP_AI_Bridge\Auth\OAuth_Server::SCOPE_MCP . ' ' . \WP_AI_Bridge\Auth\OAuth_Server::SCOPE_OFFLINE), 900);
PHP
)" --user=1 --allow-root >"$token_file"
[[ "$(wc -c < "$token_file")" -gt 20 ]] || { echo 'ERROR: disposable OAuth token not issued' >&2; exit 1; }

python3 "$root/tests/integration/issue139-external-mcp-wxr.py" "$host_port" "$token_file"
echo 'PASS: #139 separate-process HTTPS/OAuth/MCP WordPress WXR complete send, import and effect receipt.'
