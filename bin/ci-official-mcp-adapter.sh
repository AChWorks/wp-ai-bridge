#!/usr/bin/env bash
# Source from integration runners after WordPress is running.
# Returns the original URL locally; in GitHub Actions, copies the exact
# CI-supplied official ZIP to the disposable WordPress/CLI shared volume.
wpai_ci_mcp_adapter_package() {
    local url="$1" version digest archive target='/var/www/html/wpai-ci-mcp-adapter.zip'

    if [[ "${GITHUB_ACTIONS:-}" != 'true' ]]; then
        printf '%s\n' "$url"
        return 0
    fi

    case "$url" in
        https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip)
            version=0.6.1
            digest=1c3cd47c32e99b4e7d8690a44a7890256e92a8b96f61776cbe1894e5483cf676 ;;
        https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip)
            version=0.7.0
            digest=9168c18dbd018428ff14ee28e7018aa0399d4b8819b7c98b406f6610731c3a79 ;;
        https://github.com/WordPress/mcp-adapter/releases/download/v0.7.1/mcp-adapter.zip)
            version=0.7.1
            digest=c8bc28f83d3c39beffa2add4f6becc0b1ae54097df433697d1dbe6520a80d769 ;;
        *)
            echo 'ERROR: unpinned official MCP Adapter dependency requested in CI.' >&2
            return 1 ;;
    esac

    archive="${RUNNER_TEMP:?Missing GitHub CI asset directory}/wpai-ci-images/wpai-ci-mcp-adapter-${version}.zip"
    if [[ ! -s "$archive" ]] || ! printf '%s  %s\n' "$digest" "$archive" | sha256sum --check --status; then
        echo "ERROR: missing or invalid preverified official MCP Adapter ${version} archive." >&2
        return 1
    fi

    if [[ -n "${compose[0]-}" ]]; then
        "${compose[@]}" cp "$archive" "wordpress:${target}" >&2
    elif declare -F dc >/dev/null 2>&1; then
        dc cp "$archive" "wordpress:${target}" >&2
    else
        echo 'ERROR: no disposable WordPress Compose target for CI adapter archive.' >&2
        return 1
    fi
    printf '%s\n' "$target"
}
