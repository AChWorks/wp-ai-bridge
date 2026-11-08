#!/usr/bin/env bash
set -euo pipefail

# GitHub Actions-only supply of pinned official test-plugin archives. Download
# once, then share exact verified bytes with every isolated WordPress CI lane.
# No GitHub token or alternative mirror is needed for public release assets.
: "${RUNNER_TEMP:?Must run in a GitHub Actions runner}"
umask 077

fetch_asset() {
    local file="$1" asset_id="$2" size="$3" digest="$4" release_url="$5"
    local path="${RUNNER_TEMP}/${file}" url result
    test ! -e "$path" || { echo "ERROR: CI asset already exists: ${file}" >&2; return 1; }
    for url in "https://api.github.com/repos/${asset_id}" "$release_url"; do
        # The API can serve the ZIP directly or redirect to GitHub's CDN.
        # A bounded retry also handles intermittent HTTP 502/503/504.
        if curl --fail --silent --show-error --location --retry 2 --retry-delay 3 \
            --retry-all-errors --retry-max-time 210 --connect-timeout 12 --max-time 75 \
            --header 'Accept: application/octet-stream' \
            --header 'X-GitHub-Api-Version: 2022-11-28' \
            --output "$path" "$url"; then
            if [[ "$(stat -c %s "$path")" == "$size" ]] \
                && printf '%s  %s\n' "$digest" "$path" | sha256sum --check --status \
                && unzip -tqq "$path"; then
                printf 'Verified official pinned release asset %s size=%s SHA256=%s\n' \
                    "$file" "$size" "$digest"
                return 0
            fi
            echo "WARNING: Official release endpoint returned invalid bytes for ${file}; trying other official endpoint." >&2
        fi
        rm -f -- "$path"
    done
    echo "ERROR: Could not retrieve and verify pinned official release asset ${file}." >&2
    return 1
}

# Exact release IDs, byte lengths and digests are published in GitHub REST
# release-asset metadata. Pin them here instead of trusting a redirect,
# a filename, or a merely successful HTTP status.
fetch_asset wpai-ci-mcp-adapter-0.6.1.zip \
    WordPress/mcp-adapter/releases/assets/512536071 \
    455463 1c3cd47c32e99b4e7d8690a44a7890256e92a8b96f61776cbe1894e5483cf676 \
    https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip
fetch_asset wpai-ci-mcp-adapter-0.7.0.zip \
    WordPress/mcp-adapter/releases/assets/605802257 \
    435201 9168c18dbd018428ff14ee28e7018aa0399d4b8819b7c98b406f6610731c3a79 \
    https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip
fetch_asset wpai-ci-mcp-adapter-0.7.1.zip \
    WordPress/mcp-adapter/releases/assets/646782960 \
    441723 c8bc28f83d3c39beffa2add4f6becc0b1ae54097df433697d1dbe6520a80d769 \
    https://github.com/WordPress/mcp-adapter/releases/download/v0.7.1/mcp-adapter.zip
fetch_asset wpai-ci-action-scheduler-3.9.3.zip \
    woocommerce/action-scheduler/releases/assets/273006744 \
    206917 7c9fd7c37676f4032fbf4c29fae1fb6e37b7de94c94dbb1f56b10c711ee15432 \
    https://github.com/woocommerce/action-scheduler/releases/download/3.9.3/action-scheduler.zip
fetch_asset wpai-ci-action-scheduler-4.2.0.zip \
    woocommerce/action-scheduler/releases/assets/568873501 \
    234808 99d14500f28b62330e61e109ad9f9d1a5f092c2f54858dfe199aee7bd97e66b8 \
    https://github.com/woocommerce/action-scheduler/releases/download/4.2.0/action-scheduler.zip
