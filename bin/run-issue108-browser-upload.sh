#!/usr/bin/env bash
# Real WordPress wp-login.php cookie + wp-admin nonce + multipart staging.
# Restricted to the disposable single-site CI compose project.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
if [[ ! "$COMPOSE_PROJECT_NAME" =~ ^wpai-regressions-[A-Za-z0-9-]+-[0-9]+$ ]]; then
    echo 'ERROR: Browser upload smoke requires the isolated regression compose project.' >&2
    exit 2
fi
compose_file="$root/tests/integration/compose.yml"
run_compose() { docker compose -f "$compose_file" "$@"; }
run_wp() { run_compose run --rm cli "$@"; }
http() { curl --noproxy '*' --silent --show-error --max-time 15 --cacert "$tmp/cert.pem" "$@"; }
run_wp core is-installed --allow-root >/dev/null

endpoint="$(run_compose port wordpress 80)"
port="$(printf '%s' "$endpoint" | awk -F: '{ print $NF }')"
tls_endpoint="$(run_compose port wordpress 443)"
tls_port="$(printf '%s' "$tls_endpoint" | awk -F: '{ print $NF }')"
if [[ ! "$port" =~ ^[0-9]{2,5}$ || ! "$tls_port" =~ ^[0-9]{2,5}$ ]]; then
    echo 'ERROR: Could not resolve isolated WordPress HTTP/HTTPS test ports.' >&2
    exit 2
fi
tmp="$(mktemp -d)"
origin="https://localhost:$tls_port"
http_origin="http://localhost:$port"

# Real TLS termination by Apache/mod_ssl inside the isolated WordPress container.
# This is not an X-Forwarded-Proto spoof or is_ssl() monkeypatch.
openssl req -newkey rsa:2048 -nodes -x509 -days 1     -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost'     -keyout "$tmp/key.pem" -out "$tmp/cert.pem" >/dev/null 2>&1
run_compose cp "$tmp/cert.pem" wordpress:/etc/ssl/certs/wpai108-test.crt
run_compose cp "$tmp/key.pem" wordpress:/etc/ssl/private/wpai108-test.key
run_compose exec -T -u root wordpress sh -lc '
set -eu
chmod 0600 /etc/ssl/private/wpai108-test.key
a2enmod ssl >/dev/null
cat > /etc/apache2/sites-available/wpai108-ssl.conf <<"CONF"
<VirtualHost *:443>
    ServerName localhost
    DocumentRoot /var/www/html
    SSLEngine On
    SSLCertificateFile /etc/ssl/certs/wpai108-test.crt
    SSLCertificateKeyFile /etc/ssl/private/wpai108-test.key
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
CONF
a2ensite wpai108-ssl >/dev/null
apache2ctl -k graceful
'
for attempt in $(seq 1 25); do
    if http -o /dev/null "$origin/wp-login.php" 2>/dev/null; then
        break
    fi
    if [[ "$attempt" == 25 ]]; then
        echo 'ERROR: Isolated Apache did not serve a certificate-validated HTTPS login.' >&2
        exit 1
    fi
    sleep 1
done
echo 'PASS: Issue #108 real Apache HTTPS transport with validated self-signed test CA.'

original_home="$(run_wp option get home --allow-root)"
original_siteurl="$(run_wp option get siteurl --allow-root)"
original_access="$(run_wp option get wp_ai_bridge_settings --format=json --allow-root)"
restore() {
    run_wp option update home "$original_home" --allow-root >/dev/null || true
    run_wp option update siteurl "$original_siteurl" --allow-root >/dev/null || true
    run_wp option update wp_ai_bridge_settings "$original_access" --format=json --allow-root >/dev/null || true
    rm -rf -- "$tmp"
}
trap restore EXIT

php -r '
foreach (array("plugin","theme") as $kind) {
    $root = "wpai108-browser-" . $kind;
    $zip = new ZipArchive();
    if (true !== $zip->open($argv[1] . "/" . $kind . ".zip", ZipArchive::CREATE | ZipArchive::OVERWRITE)) { exit(1); }
    $zip->addEmptyDir($root);
    if ("plugin" === $kind) {
        $zip->addFromString($root . "/main.php", "<?php\n/*\nPlugin Name: WPAI108 Browser Fixture\nVersion: 1.0\n*/\n");
    } else {
        $zip->addFromString($root . "/style.css", "/*\nTheme Name: WPAI108 Browser Fixture\nVersion: 1.0\n*/\n");
        $zip->addFromString($root . "/index.php", "<?php\n");
    }
    $zip->close();
}
' "$tmp"

run_wp option update home "$origin" --allow-root >/dev/null
run_wp option update siteurl "$origin" --allow-root >/dev/null

# This *disposable* CI image runs WP-CLI as root and Apache as www-data.
# Earlier native WP-CLI fixtures already created a 0700 root-owned private dir
# on the shared test-only /tmp volume. Explicitly transfer just that fixture
# directory and native plugin destination to the HTTP worker. No production
# permissions or source security guards are relaxed.
private_dir="$(run_wp eval '
$store = new \WP_AI_Bridge\Support\Private_Package_Store(
    new \WP_AI_Bridge\Support\Permissions( new \WP_AI_Bridge\Support\Settings() )
);
echo ( new ReflectionMethod( $store, "directory" ) )->invoke( $store );
' --user=1 --allow-root | tail -n 1)"
if [[ ! "$private_dir" =~ ^/tmp/wpai-private-zip-[0-9a-f]{24}$ ]]; then
    echo 'ERROR: Could not establish the exact test-owned private ZIP directory.' >&2
    exit 1
fi
run_compose exec -T -u root wordpress chown -R www-data:www-data "$private_dir"
run_compose exec -T -u root wordpress chown -R www-data:www-data /var/www/html/wp-content/plugins
run_compose exec -T -u root wordpress chown www-data:www-data /var/www/html/wp-content


http -c "$tmp/cookies" -o "$tmp/login" "$origin/wp-login.php"
http -c "$tmp/cookies" -b "$tmp/cookies" -o "$tmp/login-post" -D "$tmp/login-headers" \
    --data-urlencode 'log=admin' \
    --data-urlencode 'pwd=integration-only-password' \
    --data-urlencode 'wp-submit=Log In' \
    --data-urlencode "redirect_to=$origin/wp-admin/admin.php?page=wp-ai-bridge-private-zips" \
    --data-urlencode 'testcookie=1' "$origin/wp-login.php"

page="$origin/wp-admin/admin.php?page=wp-ai-bridge-private-zips"
code="$(http -b "$tmp/cookies" -c "$tmp/cookies" -o "$tmp/admin" -w '%{http_code}' "$page")"
if [[ "$code" != 200 ]] || ! grep -q 'name="wpai_private_zip"' "$tmp/admin"; then
    echo "ERROR: WordPress admin login/form was not authenticated (HTTP $code)." >&2
    exit 1
fi
nonce="$(grep -oE 'name="_wpnonce" value="[^"]+"' "$tmp/admin" | head -n 1 | cut -d '"' -f 4)"
if [[ ! "$nonce" =~ ^[a-zA-Z0-9]{8,16}$ ]]; then
    echo 'ERROR: Native admin form did not issue a usable WordPress CSRF nonce.' >&2
    exit 1
fi

# R3: even a real administrator cannot stage private executable bytes on
# an HTTP request. No unauthenticated-cookie shortcut is counted as proof.
http_status="$(http -b "$tmp/cookies" -o "$tmp/http-denied" -D "$tmp/http-denied-headers" -w '%{http_code}'     -F 'action=wpai_private_zip_upload' -F "_wpnonce=$nonce" -F 'kind=plugin'     -F 'client_id=https://chatgpt.com/oauth/client.json' -F "wpai_private_zip=@$tmp/plugin.zip;type=application/zip"     "$http_origin/wp-admin/admin-post.php")"
if [[ "$http_status" == 200 ]] || grep -q 'artifact=' "$tmp/http-denied-headers"; then
    echo 'ERROR: Private ZIP staging accepted cleartext HTTP transport.' >&2
    exit 1
fi
set +e
run_wp eval '
$provider = new \WP_AI_Bridge\Abilities\Private_Package_Abilities(
    new \WP_AI_Bridge\Support\Permissions( new \WP_AI_Bridge\Support\Settings() )
);
$provider->render_upload_page();
' --user=1 --allow-root > "$tmp/http-direct-denial" 2>&1
direct_result=$?
set -e
if [[ "$direct_result" == 0 ]] || ! grep -q 'Private ZIP transfer requires HTTPS' "$tmp/http-direct-denial"; then
    echo 'ERROR: Logged-in WordPress admin surface did not enforce is_ssl() rejection.' >&2
    cat "$tmp/http-direct-denial" >&2
    exit 1
fi
echo 'PASS: Issue #108 cleartext HTTP refusal even for a logged-in administrator.'

# Authenticated but non-consented: deny without storing any ZIP.
http -b "$tmp/cookies" -c "$tmp/cookies" -o "$tmp/disabled" -D "$tmp/disabled-headers" \
    -F 'action=wpai_private_zip_upload' -F "_wpnonce=$nonce" -F 'kind=plugin' \
    -F 'client_id=https://chatgpt.com/oauth/client.json' -F "wpai_private_zip=@$tmp/plugin.zip;type=application/zip" \
    "$origin/wp-admin/admin-post.php"
if ! grep -q 'error=private_package_permission_denied' "$tmp/disabled-headers"; then
    echo 'ERROR: Default-off Bridge grant did not deny authenticated ZIP staging.' >&2
    exit 1
fi

# Missing nonce with active WordPress session must fail before upload.
csrf_code="$(http -b "$tmp/cookies" -c "$tmp/cookies" -o "$tmp/csrf" -w '%{http_code}' \
    -F 'action=wpai_private_zip_upload' -F 'kind=plugin' \
    -F 'client_id=https://chatgpt.com/oauth/client.json' -F "wpai_private_zip=@$tmp/plugin.zip;type=application/zip" \
    "$origin/wp-admin/admin-post.php")"
if [[ "$csrf_code" != 403 ]]; then
    echo "ERROR: Wrong/missing WordPress CSRF nonce was not denied (HTTP $csrf_code)." >&2
    exit 1
fi

# No WordPress login session must not reach the upload action.
unauthed_code="$(http -o "$tmp/anonymous" -w '%{http_code}' \
    -F 'action=wpai_private_zip_upload' -F "_wpnonce=$nonce" -F 'kind=plugin' \
    -F 'client_id=https://chatgpt.com/oauth/client.json' -F "wpai_private_zip=@$tmp/plugin.zip;type=application/zip" \
    "$origin/wp-admin/admin-post.php")"
if [[ "$unauthed_code" == 200 ]] || grep -q 'artifact=' "$tmp/anonymous"; then
    echo "ERROR: Unauthenticated multipart upload was accepted (HTTP $unauthed_code)." >&2
    exit 1
fi

run_wp eval '
$settings = new \WP_AI_Bridge\Support\Settings();
$grants = $settings->defaults();
$grants[\WP_AI_Bridge\Support\Settings::GROUP_CODE_EXTENSIONS] = 1;
$grants[\WP_AI_Bridge\Support\Settings::GROUP_EXTERNAL_PACKAGES] = 1;
update_option(\WP_AI_Bridge\Support\Settings::OPTION_NAME, $grants, false);
' --user=1 --allow-root >/dev/null

# Cross-process quota lock: a second authenticated worker must not bypass a
# reservation held by a different PHP/MySQL connection.
run_wp eval '
$settings = new \WP_AI_Bridge\Support\Settings();
$store = new \WP_AI_Bridge\Support\Private_Package_Store( new \WP_AI_Bridge\Support\Permissions( $settings ) );
$method = new ReflectionMethod( $store, "stage_lock_name" );
$name = $method->invoke( $store );
global $wpdb;
$owned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, %d)", $name, 1 ) );
if ( 1 !== $owned ) { throw new RuntimeException( "Could not hold the real quota lock." ); }
echo "LOCK_ACQUIRED\n";
flush();
sleep(9);
$wpdb->get_var( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $name ) );
' --user=1 --allow-root >"$tmp/quota-lock" 2>&1 &
holder_pid=$!
found_lock=0
for attempt in $(seq 1 45); do
    if grep -q 'LOCK_ACQUIRED' "$tmp/quota-lock"; then
        found_lock=1
        break
    fi
    sleep 0.2
done
if [[ "$found_lock" != 1 ]]; then
    wait "$holder_pid" || true
    echo 'ERROR: Concurrent WordPress DB quota lock was not acquired.' >&2
    exit 1
fi
http -b "$tmp/cookies" -c "$tmp/cookies" -o "$tmp/quota-denied" -D "$tmp/quota-denied-headers"     -F 'action=wpai_private_zip_upload' -F "_wpnonce=$nonce" -F 'kind=plugin'     -F 'client_id=https://chatgpt.com/oauth/client.json' -F "wpai_private_zip=@$tmp/plugin.zip;type=application/zip"     "$origin/wp-admin/admin-post.php"
wait "$holder_pid"
if ! grep -q 'error=private_package_busy' "$tmp/quota-denied-headers"; then
    echo 'ERROR: Concurrent authenticated upload bypassed the per-blog staging lock.' >&2
    exit 1
fi
echo 'PASS: Issue #108 cross-worker bounded quota lock and deny-before-stage.'

for kind in plugin theme; do
    http -b "$tmp/cookies" -c "$tmp/cookies" -o "$tmp/upload-$kind" -D "$tmp/headers-$kind" \
        -F 'action=wpai_private_zip_upload' -F "_wpnonce=$nonce" -F "kind=$kind" \
        -F 'client_id=https://chatgpt.com/oauth/client.json' -F "wpai_private_zip=@$tmp/$kind.zip;type=application/zip" \
        "$origin/wp-admin/admin-post.php"
    artifact="$(grep -oE 'artifact=[0-9a-f]{48}' "$tmp/headers-$kind" | head -n 1 | cut -d= -f2 || true)"
    if [[ ! "$artifact" =~ ^[0-9a-f]{48}$ ]]; then
        echo "ERROR: Authenticated $kind upload did not create a private artifact." >&2
        grep -iE '^HTTP/|^location:|^content-type:' "$tmp/headers-$kind" >&2 || true
        exit 1
    fi
    code="$(http -b "$tmp/cookies" -o "$tmp/review-$kind" -w '%{http_code}' "$page&artifact=$artifact")"
    expected_hash="$(sha256sum "$tmp/$kind.zip" | cut -d ' ' -f1)"
    if [[ "$code" != 200 ]] || ! grep -qF "$expected_hash" "$tmp/review-$kind"; then
        echo "ERROR: Reviewed $kind staging metadata is missing/wrong (HTTP $code)." >&2
        exit 1
    fi
    if [[ "$kind" == plugin ]] && run_wp plugin is-installed wpai108-browser-plugin --allow-root >/dev/null 2>&1; then
        echo 'ERROR: Browser upload automatically installed plugin code.' >&2
        exit 1
    fi
    if [[ "$kind" == theme ]] && run_wp theme is-installed wpai108-browser-theme --allow-root >/dev/null 2>&1; then
        echo 'ERROR: Browser upload automatically installed a theme.' >&2
        exit 1
    fi
    echo "PASS: Issue #108 WordPress-authenticated $kind multipart staging, SHA-256 review, no auto-install."
done
echo 'PASS: Issue #108 real WordPress login/cookie/nonce, denied/allowed multipart transport.'

# B1: two distinct reviewed ZIPs target the same Core plugin destination.
# A holds the actual native Upgrader boundary; B must fail BEFORE its claim.
run_compose exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
run_compose cp "$root/tests/fixtures/issue108-install-race-mu.php" wordpress:/var/www/html/wp-content/mu-plugins/wpai108-install-race.php
race_url="$origin/wp-admin/admin-post.php"
race() {
    http -b "$tmp/cookies" -F 'action=wpai108_install_race' -F "_wpnonce=$nonce" -F "mode=$1" "$race_url"
}
race setup > "$tmp/race-setup.json"
if ! grep -q '"setup":true' "$tmp/race-setup.json"; then
    echo 'ERROR: Could not initialize two distinct reviewed ZIP artifacts.' >&2
    exit 1
fi
race install-a > "$tmp/race-a.json" &
install_pid=$!
entered=0
for attempt in $(seq 1 65); do
    if run_compose exec -T wordpress test -f /tmp/wpai108-race-core-entered; then
        entered=1
        break
    fi
    sleep 0.2
done
if [[ "$entered" != 1 ]]; then
    wait "$install_pid" || true
    echo 'ERROR: First native Core installation never reached held Upgrader boundary.' >&2
    exit 1
fi
race install-b > "$tmp/race-b.json"
race public-while-locked > "$tmp/race-public.json"
wait "$install_pid"
race verify > "$tmp/race-verify.json"
if ! grep -q '"ok":true' "$tmp/race-a.json" ||
   ! grep -q '"code":"private_package_install_busy"' "$tmp/race-b.json" ||
   ! grep -q '"unclaimed":true' "$tmp/race-b.json" ||
   ! grep -q '"still_staged":true' "$tmp/race-b.json" ||
   ! grep -q '"code":"extension_install_busy"' "$tmp/race-public.json" ||
   ! grep -q '"exact_tree":true' "$tmp/race-verify.json" ||
   ! grep -q '"b_unclaimed":true' "$tmp/race-verify.json"; then
    echo 'ERROR: Different-artifact Core install race or cross-path lock was not correctly rejected.' >&2
    cat "$tmp/race-a.json" "$tmp/race-b.json" "$tmp/race-public.json" "$tmp/race-verify.json" >&2
    exit 1
fi
race cleanup > "$tmp/race-cleanup.json"
if ! grep -q '"cleaned":true' "$tmp/race-cleanup.json"; then
    echo 'ERROR: Race fixture cleanup did not succeed.' >&2
    exit 1
fi
run_compose exec -T wordpress rm -f /var/www/html/wp-content/mu-plugins/wpai108-install-race.php
echo 'PASS: Issue #108 two different ZIP IDs versus one native destination, no claim/core overlap, exact final filesystem tree and shared WordPress.org lock.'
