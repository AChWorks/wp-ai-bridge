#!/usr/bin/env bash
# Prepare once in CI image supply, or on demand for local target runners.
# Official tags stay unchanged. Derived tags are keyed by source image ID.
wpai199c_prepare_node_images() {
    WPAI_ADMIN13_APACHE_IMAGE="$(python3 "$root/bin/issue199-completion-image.py" prepare \
        "${WPAI_CI_IMAGE_REGISTRY:-}wordpress:${WORDPRESS_TAG:-6.9-php8.4-apache}")" || return 1
    WPAI_ADMIN13_CLI_IMAGE="$(python3 "$root/bin/issue199-completion-image.py" prepare \
        "${WPAI_CI_IMAGE_REGISTRY:-}wordpress:cli-php8.4")" || return 1
    export WPAI_ADMIN13_APACHE_IMAGE WPAI_ADMIN13_CLI_IMAGE
}

# Resolve the effective Compose model before any service is created. This also
# fails clearly if a local Compose version cannot apply !override correctly.
wpai199c_validate_node_mounts() {
    local model result=0
    model="$(mktemp "${TMPDIR:-/tmp}/wpai-admin13-compose.XXXXXX")"
    "${compose[@]}" config --format json >"$model" || result=$?
    if [[ "$result" == 0 ]]; then
        python3 "$root/bin/issue199-completion-image.py" check-compose "$model" || result=$?
    fi
    rm -f "$model"
    return "$result"
}

# Sourced by disposable runners at startup; product fixtures begin after historical checks.
# All new fixtures receive an explicit DB identity and nonserved restore target.

wpai199c_bootstrap() {
    local enable_native_sql="${1:-false}"
    local engine="${2:-mariadb}"
    [[ "$enable_native_sql" == true || "$enable_native_sql" == false ]] || return 1
    [[ "$engine" == mysql || "$engine" == mariadb ]] || return 1

    tar --mode='u+rwX,go+rX' -C "$root" -cf - tests \
        | "${compose[@]}" exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/wp-ai-bridge
    "${wp[@]}" config set WPAI_ADMIN13_DISPOSABLE true --raw --allow-root >/dev/null
    "${wp[@]}" config set WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL "$enable_native_sql" --raw --allow-root >/dev/null
    "${wp[@]}" config set DISABLE_WP_CRON true --raw --allow-root >/dev/null
    # Only this disposable runner's installed root is normalized for the v1
    # ordinary-permissions roundtrip. Product source roots are never changed.
    case "${COMPOSE_PROJECT_NAME:-}" in
        wpai-integration-*|wpai199-write-*|wpai-issue58-ms-*) ;;
        *) echo 'ERROR: completion bootstrap requires an owned disposable Compose project.' >&2; return 1 ;;
    esac
    "${wp[@]}" eval '
        global $wpdb;
        $root = rtrim(ABSPATH, "/\\");
        if (!defined("WPAI_ADMIN13_DISPOSABLE") || true !== WPAI_ADMIN13_DISPOSABLE ||
            "/var/www/html" !== $root || realpath($root) !== $root || is_link($root) ||
            "wordpress" !== $wpdb->get_var("SELECT DATABASE()")) {
            throw new RuntimeException("Owned disposable installation identity mismatch.");
        }
        if (!chmod($root, 0755)) { throw new RuntimeException("Disposable ordinary root permission setup failed."); }
        clearstatcache(true, $root);
        if (0755 !== (lstat($root)["mode"] & 07777)) {
            throw new RuntimeException("Disposable ordinary root permissions were not applied.");
        }
    ' --allow-root
    "${compose[@]}" exec -T wordpress sh -ec '
        mkdir -p /var/www/html/wp-content/mu-plugins
        cp /var/www/html/wp-content/plugins/wp-ai-bridge/tests/fixtures/issue199-completion-provider.php /var/www/html/wp-content/mu-plugins/admin13-provider.php
    '
    "${compose[@]}" exec -T wordpress sh -ec '
        install -d -o 33 -g 33 -m 0700 /var/www/wpai-admin13/private /var/www/wpai-admin13/private/wp-temp /var/www/wpai-admin13/private/wpcli-cache /var/www/wpai-admin13/private/wpcli-packages
        mkdir -p /var/www/html/wp-content/uploads
        chown -R 33:33 /var/www/html/wp-content/uploads
    '
    # A real native publication crosses from private staging to the actual
    # uploads directory once. Matching st_dev alone does not prove mount identity.
    "${compose[@]}" exec -T --user=33:33 wordpress php -r '
        $source = tempnam("/var/www/wpai-admin13/private", "node-link-");
        $target = "/var/www/html/wp-content/uploads/.wpai-node-" . bin2hex(random_bytes(12));
        try {
            if (false === $source || 13 !== file_put_contents($source, "owned-node-v1")) {
                throw new RuntimeException("Private node admission bytes failed");
            }
            if (!link($source, $target)) {
                throw new RuntimeException("Private and uploads roots do not permit same-node native publication");
            }
            $a = lstat($source); $b = lstat($target);
            if (!$a || !$b || $a["dev"] !== $b["dev"] || $a["ino"] !== $b["ino"] ||
                $a["nlink"] < 2 || $a["uid"] !== 33 || "owned-node-v1" !== file_get_contents($target)) {
                throw new RuntimeException("Same-node native publication identity failed");
            }
            echo "Verified UID33 private-to-uploads native link on one mount; exact bytes/inode.\n";
        } finally {
            if (is_file($target)) { unlink($target); }
            if (is_string($source) && is_file($source)) { unlink($source); }
        }
    '
    "${wp[@]}" config set WP_TEMP_DIR /var/www/wpai-admin13/private/wp-temp --allow-root >/dev/null
    wpai199c_restore_root="$("${compose[@]}" exec -T --user=33:33 wordpress mktemp -d /var/www/wpai-admin13/private/restore.XXXXXX | tr -d '\r')"
    [[ "$wpai199c_restore_root" == /var/www/wpai-admin13/private/restore.* ]] || {
        echo 'ERROR: disposable restore root was not created.' >&2
        return 1
    }
    # This is container-administrator fixture provisioning, never a product connection.
    # Keep the historical suites above unchanged, then narrow the new suite's app account.
    local wpai199c_app_password
    wpai199c_app_password="$(openssl rand -hex 24)"
    "${compose[@]}" exec -T -e "WPAI199C_APP_PASSWORD=$wpai199c_app_password" -e "WPAI199C_ENGINE=$engine" db sh -se <<'DBBOOT'
case "$WPAI199C_APP_PASSWORD" in *[!a-f0-9]*|"") exit 1 ;; esac
if command -v mariadb >/dev/null 2>&1; then db_client=mariadb; else db_client=mysql; fi
root_password=${MARIADB_ROOT_PASSWORD:-${MYSQL_ROOT_PASSWORD:-}}
test -n "$root_password"
# Both native InnoDB dictionary/FK views require PROCESS metadata visibility.
# This grants no third-schema data/DDL authority; verify those denials below.
metadata_grant="GRANT PROCESS ON *.* TO 'wpai_admin13'@'%';"
MYSQL_PWD="$root_password" "$db_client" --protocol=socket --user=root --batch <<SQL
CREATE DATABASE wpai_admin13_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE wpai_admin13_denied CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE wpai_admin13_denied.must_not_read(id INT);
CREATE USER 'wpai_admin13'@'%' IDENTIFIED BY '$WPAI199C_APP_PASSWORD';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wpai_admin13'@'%';
GRANT ALL PRIVILEGES ON wpai_admin13_restore.* TO 'wpai_admin13'@'%';
$metadata_grant
SQL
DBBOOT
    "${wp[@]}" config set DB_USER wpai_admin13 --allow-root >/dev/null
    "${wp[@]}" config set DB_PASSWORD "$wpai199c_app_password" --allow-root >/dev/null
    unset wpai199c_app_password
    "${wp[@]}" eval '
    global $wpdb;
    if ("wordpress" !== $wpdb->get_var("SELECT DATABASE()")) { throw new RuntimeException("Disposable database identity mismatch."); }
    $prior = $wpdb->suppress_errors(true);
    $denied = false === $wpdb->query("CREATE TABLE wpai_admin13_denied.must_not_create(id INT)");
    $read_denied = null === $wpdb->get_var("SELECT id FROM wpai_admin13_denied.must_not_read LIMIT 1") && "" !== $wpdb->last_error;
    $drop_denied = false === $wpdb->query("DROP TABLE wpai_admin13_denied.must_not_read");
    $view = false !== stripos($wpdb->get_var("SELECT VERSION()"),"MariaDB") ? "INNODB_SYS_FOREIGN" : "INNODB_FOREIGN";
    $wpdb->get_results("SELECT ID FROM information_schema.".$view." LIMIT 1");
    $fk_readable = "" === $wpdb->last_error;
    if ("INNODB_SYS_FOREIGN" === $view) {
        $wpdb->get_results("SELECT TABLE_ID FROM information_schema.INNODB_SYS_TABLES LIMIT 1");
        $fk_readable = $fk_readable && "" === $wpdb->last_error;
    }
    $wpdb->suppress_errors($prior);
    if (!$denied || !$read_denied || !$drop_denied || !$fk_readable) {
        throw new RuntimeException("The completion app account scope or native metadata prerequisite is incorrect: " . json_encode(array(
            "create_denied"=>$denied,"read_denied"=>$read_denied,"drop_denied"=>$drop_denied,"fk_metadata_readable"=>$fk_readable
        ), JSON_THROW_ON_ERROR));
    }
    echo "Verified limited app account: source plus approved restore; third schema denied.\n";
    ' --user=1 --allow-root
    # Full installation capture/ingress/restore/oracle makes several complete passes.
    # Retain the fixture's finite 60..3600 policy; shared-host measurements exceed 900s.
    wpai199c_wp=("${compose[@]}" run --rm --user=33:33
        -e WP_CLI_CACHE_DIR=/var/www/wpai-admin13/private/wpcli-cache
        -e WP_CLI_PACKAGES_DIR=/var/www/wpai-admin13/private/wpcli-packages
        -e "WPAI199C_EXPECT_PRODUCT_SHA=$(git -C "$root" rev-parse HEAD)"
        -e "WPAI199C_EXPECT_FILESYSTEM_SHA256=$(sha256sum "$root/src/Support/class-scoped-filesystem.php" | cut -d ' ' -f 1)"
        -e WPAI199C_EXPECT_DATABASE=wordpress
        -e "WPAI199C_EXPECT_ENGINE=$engine"
        -e WPAI199C_RESTORE_SCHEMA=wpai_admin13_restore
        -e "WPAI199C_RESTORE_ROOT=$wpai199c_restore_root"
        -e WPAI_ADMIN13_RESTORE_SCHEMA=wpai_admin13_restore
        -e "WPAI_ADMIN13_RESTORE_ROOT=$wpai199c_restore_root"
        -e "WPAI_ADMIN13_BACKUP_TEST_SECONDS=${WPAI_ADMIN13_BACKUP_TEST_SECONDS:-3600}"
        cli)
}

wpai199c_eval() {
    local fixture="$1"
    test -s "$root/tests/integration/$fixture"
    "${wpai199c_wp[@]}" eval-file \
        wp-content/plugins/wp-ai-bridge/tests/integration/issue199-completion-loader.php "$fixture" --user=1
}

wpai199c_install_as() {
    local version="$1" expected tmpfile actual
    case "$version" in
        3.9.3) expected=7c9fd7c37676f4032fbf4c29fae1fb6e37b7de94c94dbb1f56b10c711ee15432 ;;
        4.2.0) expected=99d14500f28b62330e61e109ad9f9d1a5f092c2f54858dfe199aee7bd97e66b8 ;;
        *) echo 'ERROR: unpinned Action Scheduler fixture version.' >&2; return 1 ;;
    esac
    tmpfile="$(mktemp "${TMPDIR:-/tmp}/wpai199c-as.XXXXXX")"
    if ! curl -fsSL --connect-timeout 15 --max-time 120 \
        "https://github.com/woocommerce/action-scheduler/releases/download/$version/action-scheduler.zip" -o "$tmpfile"; then
        rm -f "$tmpfile"; return 1
    fi
    if ! printf '%s  %s\n' "$expected" "$tmpfile" | sha256sum --check --status; then
        rm -f "$tmpfile"; echo 'ERROR: Action Scheduler release digest mismatch.' >&2; return 1
    fi
    # The CLI and Apache containers share this private volume; /tmp is per-container.
    "${compose[@]}" cp "$tmpfile" wordpress:/var/www/wpai-admin13/private/admin13-action-scheduler.zip
    rm -f "$tmpfile"
    "${wp[@]}" plugin install /var/www/wpai-admin13/private/admin13-action-scheduler.zip --activate --force --allow-root
    "${compose[@]}" exec -T wordpress rm -f /var/www/wpai-admin13/private/admin13-action-scheduler.zip
    actual="$("${wp[@]}" plugin get action-scheduler --field=version --allow-root | tail -n 1)"
    [[ "$actual" == "$version" ]] || {
        echo "ERROR: installed Action Scheduler $actual differs from pinned $version." >&2
        return 1
    }
    echo "Verified installed Action Scheduler $actual; release SHA256 $expected"
}

wpai199c_remove_provider() {
    "${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-content/mu-plugins/admin13-provider.php
}

# A separate host process uses real HTTPS/OAuth/MCP, with explicit disposable CA trust.
wpai199c_external_https() (
    local binding port origin private_host ca_host result=0
    binding="$("${compose[@]}" port wordpress 443 | tail -n 1)"
    port="${binding##*:}"
    [[ "$port" =~ ^[0-9]+$ ]] || return 1
    origin="https://localhost:$port"
    private_host="$(mktemp "${TMPDIR:-/tmp}/wpai199c-https.XXXXXX")"
    ca_host="$(mktemp "${TMPDIR:-/tmp}/wpai199c-ca.XXXXXX")"
    chmod 0600 "$private_host" "$ca_host"
    trap 'rm -f "$private_host" "$ca_host"' EXIT
    "${compose[@]}" exec -T wordpress bash -se <<'TLS'
a2enmod ssl >/dev/null
openssl req -x509 -nodes -newkey rsa:2048 -days 1 -subj '/CN=localhost' \
    -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' \
    -keyout /var/www/wpai-admin13/tls.key -out /var/www/wpai-admin13/tls.crt >/dev/null 2>&1
chmod 0600 /var/www/wpai-admin13/tls.key
cat > /etc/apache2/sites-available/wpai-admin13-ssl.conf <<'VHOST'
<IfModule mod_ssl.c>
<VirtualHost *:443>
    DocumentRoot /var/www/html
    SSLEngine on
    SSLCertificateFile /var/www/wpai-admin13/tls.crt
    SSLCertificateKeyFile /var/www/wpai-admin13/tls.key
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
</IfModule>
VHOST
a2ensite wpai-admin13-ssl >/dev/null
apache2ctl graceful >/dev/null
TLS
    "${compose[@]}" cp wordpress:/var/www/wpai-admin13/tls.crt "$ca_host"
    "${wpai199c_wp[@]}" eval-file \
        wp-content/plugins/wp-ai-bridge/tests/integration/issue199-completion-https-fixture.php setup "$origin" --user=1
    "${compose[@]}" cp wordpress:/var/www/wpai-admin13/private/wp-temp/admin13-https-fixture.json "$private_host"
    chmod 0600 "$private_host"
    python3 "$root/tests/integration/issue199-completion-https-client.py" "$private_host" "$ca_host" run || result=$?
    if [[ "$result" == 0 ]]; then
        "${wpai199c_wp[@]}" eval-file \
            wp-content/plugins/wp-ai-bridge/tests/integration/issue199-completion-https-fixture.php revoke_grants --user=1
        python3 "$root/tests/integration/issue199-completion-https-client.py" "$private_host" "$ca_host" revoked || result=$?
    fi
    "${wpai199c_wp[@]}" eval-file \
        wp-content/plugins/wp-ai-bridge/tests/integration/issue199-completion-https-fixture.php cleanup --user=1
    if [[ "$result" == 0 ]]; then
        python3 "$root/tests/integration/issue199-completion-https-client.py" "$private_host" "$ca_host" token_revoked || result=$?
    fi
    rm -f "$private_host" "$ca_host"
    return "$result"
)
