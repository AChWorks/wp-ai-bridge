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
http() { curl --noproxy '*' --silent --show-error --max-time 15 "$@"; }
run_wp core is-installed --allow-root >/dev/null

endpoint="$(run_compose port wordpress 80)"
port="$(printf '%s' "$endpoint" | awk -F: '{ print $NF }')"
if [[ ! "$port" =~ ^[0-9]{2,5}$ ]]; then
    echo 'ERROR: Could not resolve local-only WordPress HTTP test port.' >&2
    exit 2
fi
origin="http://localhost:$port"
tmp="$(mktemp -d)"
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
    artifact="$(grep -oE 'artifact=[0-9a-f]{48}' "$tmp/headers-$kind" | head -n 1 | cut -d= -f2)"
    if [[ ! "$artifact" =~ ^[0-9a-f]{48}$ ]]; then
        echo "ERROR: Authenticated $kind upload did not create a private artifact." >&2
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
