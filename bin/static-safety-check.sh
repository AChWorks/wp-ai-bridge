#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

forbidden='(^|[^[:alnum:]_])(shell_exec|exec|system|passthru|proc_open|popen|eval|file_put_contents|fopen|fwrite|unlink|rename|copy|mkdir|rmdir)[[:space:]]*\('

if grep -R -nE "$forbidden" src --include='*.php' --exclude='class-media-abilities.php' --exclude='class-source-editing-abilities.php'; then
    echo "ERROR: forbidden direct execution/filesystem primitive found in production source." >&2
    exit 1
fi

media_write_count="$(grep -cF 'file_put_contents( $tmp_name, $bytes )' src/Abilities/class-media-abilities.php || true)"
media_temp_count="$(grep -cF 'wp_tempnam( $filename )' src/Abilities/class-media-abilities.php || true)"
media_base64_temp_count="$(grep -cF '$tmp_name = wp_tempnam( $filename );' src/Abilities/class-media-abilities.php || true)"
media_import_temp_count="$(grep -Ec '\$temp_file[[:space:]]*=[[:space:]]*wp_tempnam\([[:space:]]*\$filename[[:space:]]*\);' src/Abilities/class-media-abilities.php || true)"
media_all_write_count="$(grep -cF 'file_put_contents(' src/Abilities/class-media-abilities.php || true)"
if [[ "$media_write_count" != "1" || "$media_all_write_count" != "1" || "$media_temp_count" != "2" || "$media_base64_temp_count" != "1" || "$media_import_temp_count" != "1" ]]; then
    echo "ERROR: media upload filesystem exception no longer matches the single bounded WordPress temp-file write." >&2
    exit 1
fi

# URL media uses a second WordPress staging allocation, never another direct writer,
# an unchecked HTTP client, or a caller-controlled filesystem/command primitive.
media_forbidden='(^|[^[:alnum:]_])(shell_exec|exec|system|passthru|proc_open|popen|eval|fopen|fwrite|unlink|rename|copy|mkdir|rmdir|curl_exec|curl_init|fsockopen|stream_socket_client|file_get_contents|wp_remote_get|wp_remote_post|wp_remote_request)[[:space:]]*\('
if grep -nE "$media_forbidden" src/Abilities/class-media-abilities.php; then
    echo "ERROR: media import introduced an unbounded execution/filesystem/HTTP primitive." >&2
    exit 1
fi
if [[ "$(grep -cF 'wp_safe_remote_get(' src/Abilities/class-media-abilities.php || true)" != "1" ]]; then
    echo "ERROR: media import must retain exactly one safe WordPress HTTP request surface." >&2
    exit 1
fi

# Issue #46 source editing is a fixed-purpose installed-extension lifecycle, not a generic filesystem proxy.
source_editor='src/Abilities/class-source-editing-abilities.php'
if [[ ! -f "$source_editor" ]]; then
    echo "ERROR: bounded source-editing provider is missing." >&2
    exit 1
fi
if [[ "$(grep -cF 'new \WP_Filesystem_Direct( null )' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one direct WordPress filesystem constructor." >&2
    exit 1
fi
if [[ "$(grep -cF 'new \SplFileObject(' "$source_editor" || true)" != "2" ]]; then
    echo "ERROR: source editing must retain exactly the stable coordination lock plus the exact target-inode lock." >&2
    exit 1
fi
if [[ "$(grep -cF "new \\SplFileObject( \$target['canonical_path'], 'rb' )" "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one read-only advisory lock on the confined target inode." >&2
    exit 1
fi
if [[ "$(grep -cF 'new \SplFileObject( $this->coordination_lock_path( $canonical_path )' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one stable path-keyed coordination lock." >&2
    exit 1
fi
if [[ "$(grep -cF -- '->flock( LOCK_EX | LOCK_NB )' "$source_editor" || true)" != "2" || "$(grep -cF -- '->flock( LOCK_UN )' "$source_editor" || true)" != "2" ]]; then
    echo "ERROR: source editing stable/path-inode advisory lock counts changed unexpectedly." >&2
    exit 1
fi
if [[ "$(grep -cF 'wp_remote_get(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing runtime validation must retain one bounded Core-compatible loopback request call site." >&2
    exit 1
fi
if [[ "$(grep -cF "wp_is_file_mod_allowed( 'wp_native_builder_bridge_source_editing' )" "$source_editor" || true)" != "2" ]]; then
    echo "ERROR: source editing must recheck WordPress file-modification policy for normal execution and recovery." >&2
    exit 1
fi
if grep -nE "['\"](server_path|file_path|absolute_path|filesystem_path|ftp_password|ssh_password|private_key)['\"][[:space:]]*=>" "$source_editor"; then
    echo "ERROR: source editing exposed a caller-selected server path or filesystem credential field." >&2
    exit 1
fi
# F-001 remediation permits only the exact fixed-purpose path-CAS primitives below. They stage
# complete bytes privately, quarantine the current pathname, and publish with hard-link no-replace
# semantics so a non-cooperating writer that recreates the live path is never overwritten.
if grep -nE '(^|[^[:alnum:]_])(shell_exec|exec|system|passthru|proc_open|popen|eval|file_put_contents|copy|mkdir|rmdir|symlink)[[:space:]]*\(' "$source_editor"; then
    echo "ERROR: source editing grew an execution/filesystem primitive outside its fixed guarded replacement surface." >&2
    exit 1
fi
if [[ "$(grep -cF '@fopen(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one exclusive private stage-file open." >&2
    exit 1
fi
if [[ "$(grep -cF '@fwrite(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one bounded stage-file write loop." >&2
    exit 1
fi
if [[ "$(grep -cF '@rename(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one live-path-to-quarantine rename boundary." >&2
    exit 1
fi
if [[ "$(grep -cF '@link(' "$source_editor" || true)" != "3" ]]; then
    echo "ERROR: source editing must retain exactly three no-replace hard-link call sites (probe, publish, restore)." >&2
    exit 1
fi
if [[ "$(grep -cF '@unlink(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing guarded-replacement cleanup surface changed unexpectedly." >&2
    exit 1
fi
if [[ "$(grep -cF '@chmod(' "$source_editor" || true)" != "2" || "$(grep -cF '@chown(' "$source_editor" || true)" != "1" || "$(grep -cF '@chgrp(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must preserve mode/owner/group only through the single staged replacement metadata path." >&2
    exit 1
fi

# Issues #34/#36/#58 need exact-row compare-and-swap against fixed metadata tables. Direct database
# use remains forbidden outside the three explicitly confined metadata stores below. Those stores
# accept no SQL text, table name, column name, row selector, or query fragment from Ability input.
metadata_store='src/Support/class-post-meta-store.php'
term_metadata_store='src/Support/class-term-meta-store.php'
user_comment_metadata_store='src/Support/class-user-comment-meta-store.php'
if [[ ! -f "$metadata_store" || ! -f "$term_metadata_store" || ! -f "$user_comment_metadata_store" ]]; then
    echo "ERROR: one or more bounded metadata stores are missing." >&2
    exit 1
fi
unexpected_db_files="$(grep -R -lF '$wpdb' src --include='*.php' | grep -vFx "$metadata_store" | grep -vFx "$term_metadata_store" | grep -vFx "$user_comment_metadata_store" || true)"
if [[ -n "$unexpected_db_files" ]]; then
    printf '%s\n' "$unexpected_db_files"
    echo "ERROR: direct database access found outside the bounded metadata stores." >&2
    exit 1
fi
if [[ -f "$metadata_store" ]]; then
    if grep -nE '\$wpdb->(get_[A-Za-z0-9_]*|replace|esc_like)([^A-Za-z0-9_]|$)' "$metadata_store"; then
        echo "ERROR: post-meta store grew a database read/generic primitive outside its fixed physical-row/CAS design." >&2
        exit 1
    fi
    if grep -nE '\$wpdb->[A-Za-z_][A-Za-z0-9_]*' "$metadata_store" | grep -vE '\$wpdb->(postmeta|update|delete|insert|prepare|query)([^A-Za-z0-9_]|$)'; then
        echo "ERROR: post-meta store uses a database member outside its fixed postmeta persistence surface." >&2
        exit 1
    fi
    if grep -nE 'function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*[[:space:]]*\([^)]*\$(sql|table|column|query|where)([^A-Za-z0-9_]|$)' "$metadata_store"; then
        echo "ERROR: post-meta store must not accept caller-selected SQL/table/column/query inputs." >&2
        exit 1
    fi
    if grep -nE '\$(sql|prepared_sql|set_sql|raw_sql)[[:space:]]*=' "$metadata_store"; then
        echo "ERROR: post-meta raw CAS SQL must remain complete literal prepared templates, not assembled SQL fragments." >&2
        exit 1
    fi

    prepare_count="$(grep -cF '$wpdb->prepare(' "$metadata_store" || true)"
    query_count="$(grep -cF '$wpdb->query(' "$metadata_store" || true)"
    binary_key_count="$(grep -cF 'CAST(meta_key AS BINARY) = CAST(%s AS BINARY)' "$metadata_store" || true)"
    binary_value_count="$(grep -cF 'CAST(meta_value AS BINARY) = CAST(%s AS BINARY)' "$metadata_store" || true)"
    null_value_count="$(grep -cF 'meta_value IS NULL' "$metadata_store" || true)"
    set_null_count="$(grep -cF 'SET meta_value = NULL' "$metadata_store" || true)"
    set_string_count="$(grep -cF 'SET meta_value = %s' "$metadata_store" || true)"
    if [[ "$prepare_count" != "6" || "$query_count" != "6" || "$binary_key_count" != "6" || "$binary_value_count" != "3" || "$null_value_count" != "3" || "$set_null_count" != "2" || "$set_string_count" != "2" ]]; then
        echo "ERROR: post-meta raw SQL must remain exactly the six fixed byte-exact update/delete CAS branches with explicit NULL handling." >&2
        exit 1
    fi
fi

# Issue #36 writes only termmeta; native term tables appear only in identity joins. Do not relax the
# postmeta assertions above: the shipped postmeta CAS surface remains unchanged.
if grep -nE '\$wpdb->[A-Za-z_][A-Za-z0-9_]*' "$term_metadata_store" | grep -vE '\$wpdb->(termmeta|term_taxonomy|terms|last_error|insert_id|get_results|get_var|prepare|query)([^A-Za-z0-9_]|$)'; then
    echo "ERROR: term-meta store exceeded its fixed termmeta persistence surface." >&2
    exit 1
fi
if grep -nE 'function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*[[:space:]]*\([^)]*\$(sql|table|column|query|where)([^A-Za-z0-9_]|$)|\$(sql|prepared_sql|set_sql|raw_sql)[[:space:]]*=' "$term_metadata_store"; then
    echo "ERROR: term-meta store must not accept or assemble SQL fragments." >&2
    exit 1
fi
term_prepare_count="$(grep -cF '$wpdb->prepare(' "$term_metadata_store" || true)"
term_query_count="$(grep -cF '$wpdb->query(' "$term_metadata_store" || true)"
term_read_count="$(grep -cF '$wpdb->get_results(' "$term_metadata_store" || true)"
term_unique_read_count="$(grep -cF '$wpdb->get_var(' "$term_metadata_store" || true)"
term_binary_key_count="$(grep -cF 'CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY)' "$term_metadata_store" || true)"
term_binary_value_count="$(grep -cF 'CAST(m.meta_value AS BINARY) = CAST(%s AS BINARY)' "$term_metadata_store" || true)"
term_null_count="$(grep -cF 'meta_value IS NULL' "$term_metadata_store" || true)"
term_set_null_count="$(grep -cF 'SET m.meta_value = NULL' "$term_metadata_store" || true)"
term_set_string_count="$(grep -cF 'SET m.meta_value = %s' "$term_metadata_store" || true)"
if [[ "$term_prepare_count" != "11" || "$term_query_count" != "8" || "$term_read_count" != "2" || "$term_unique_read_count" != "1" || "$term_binary_key_count" != "6" || "$term_binary_value_count" != "3" || "$term_null_count" != "3" || "$term_set_null_count" != "2" || "$term_set_string_count" != "2" ]]; then
    echo "ERROR: term-meta persistence must retain two bounded reads, one native uniqueness check, six identity-bound CAS branches, and two conditional insert branches." >&2
    exit 1
fi
if [[ "$(grep -cF 'SELECT meta_id, term_id, meta_key, CASE WHEN OCTET_LENGTH(meta_value) <= 1048576 THEN meta_value ELSE NULL END AS meta_value, OCTET_LENGTH(meta_value) AS value_bytes FROM %i WHERE term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id LIMIT 2' "$term_metadata_store" || true)" != "1" || "$(grep -cF 'SELECT MIN(meta_key) AS meta_key, COUNT(*) AS row_count FROM %i WHERE term_id = %d AND meta_key IS NOT NULL GROUP BY CAST(meta_key AS BINARY) ORDER BY CAST(meta_key AS BINARY) LIMIT %d OFFSET %d' "$term_metadata_store" || true)" != "1" ]]; then
    echo "ERROR: term-meta physical reads lost their fixed bounds or value-free list contract." >&2
    exit 1
fi
if grep -nE '(get|add|update|delete)_(option|user_meta|post_meta)[[:space:]]*\(' src/Abilities/class-term-meta-abilities.php "$term_metadata_store"; then
    echo "ERROR: term metadata must not grow a separate options/user/post metadata surface." >&2
    exit 1
fi

# These names are forbidden in AI-exposed Ability schemas. OAuth protocol responses
# legitimately use access_token, but no OAuth bearer material may become an Ability input.
if grep -R -nE "['\"](server_path|file_path|package_url|shell_command|sql_query|application_password|session_token|access_token|refresh_token|authorization_code|api_secret)['\"][[:space:]]*=>" src/Abilities --include='*.php'; then
    echo "ERROR: forbidden generic path/command/secret schema field found in an exposed Ability." >&2
    exit 1
fi

# Issue #34 deliberately adds generic post_meta access, not a generic WordPress data-store
# backdoor. Keep options/user-meta APIs out of that provider so future edits cannot silently
# expand its authority without an explicit architectural change.
metadata_provider='src/Abilities/class-post-meta-abilities.php'
if [[ -f "$metadata_provider" ]]; then
    if grep -nE '(^|[^[:alnum:]_])(get|add|update|delete)_option[[:space:]]*\(' "$metadata_provider"; then
        echo "ERROR: Advanced Metadata provider must not expose generic WordPress options." >&2
        exit 1
    fi
    if grep -nE '(^|[^[:alnum:]_])(get|add|update|delete)_user_meta[[:space:]]*\(' "$metadata_provider"; then
        echo "ERROR: Advanced Metadata provider must not expose generic user metadata." >&2
        exit 1
    fi
fi

# The consent form posts to the same WordPress origin, then redirects to ChatGPT's
# fixed OAuth callback. Chromium applies form-action across that redirect chain,
# so the callback origin must remain explicitly allowed without broadening the CSP.
oauth_consent_csp_count="$(grep -cF "form-action 'self' https://chatgpt.com" src/Auth/class-oauth-server.php || true)"
if [[ "$oauth_consent_csp_count" != "1" ]]; then
    echo "ERROR: OAuth consent CSP must explicitly allow the fixed ChatGPT callback origin exactly once." >&2
    exit 1
fi

if grep -Fq "form-action 'self';" src/Auth/class-oauth-server.php; then
    echo "ERROR: OAuth consent CSP regressed to same-origin-only form navigation and can block the ChatGPT callback redirect." >&2
    exit 1
fi

php bin/check-term-meta-confinement.php
php bin/check-user-comment-meta-confinement.php

echo "PASS: static safety surface audit."
