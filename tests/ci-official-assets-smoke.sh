#!/usr/bin/env bash
set -euo pipefail

# The exact ZIPs have already been fetched and hash-verified by the supply job.
# Validate that test runners use their bytes, reject tampering and cannot
# silently substitute an unpinned dependency URL in CI.
root="$(cd "$(dirname "$0")/.." && pwd)"
source "$root/bin/ci-official-mcp-adapter.sh"
temp="$(mktemp -d)"
trap 'rm -rf -- "$temp"' EXIT
mkdir -p "$temp/wpai-ci-images"
archive="$temp/wpai-ci-images/wpai-ci-mcp-adapter-0.7.0.zip"
cp -- "${RUNNER_TEMP:?}/wpai-ci-mcp-adapter-0.7.0.zip" "$archive"
received="$temp/wordpress-received.zip"

mock_compose() {
    [[ "$1" == 'cp' && "$3" == 'wordpress:/var/www/html/wpai-ci-mcp-adapter.zip' ]] || return 1
    cp -- "$2" "$received"
}
compose=(mock_compose)
package='https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip'

if [[ "$(GITHUB_ACTIONS=false wpai_ci_mcp_adapter_package "$package")" != "$package" ]]; then
    echo 'ERROR: local/non-CI dependency behavior changed.' >&2
    exit 1
fi

actual="$(GITHUB_ACTIONS=true RUNNER_TEMP="$temp" wpai_ci_mcp_adapter_package "$package")"
if [[ "$actual" != '/var/www/html/wpai-ci-mcp-adapter.zip' ]] || ! cmp -s "$received" "$archive"; then
    echo 'ERROR: verified CI Adapter bytes were not copied to the disposable WordPress node.' >&2
    exit 1
fi

# Tampering must fail before a Compose copy, never fall back to remote.
rm -f "$received"
printf '\nCORRUPTED\n' >> "$archive"
if (GITHUB_ACTIONS=true RUNNER_TEMP="$temp" wpai_ci_mcp_adapter_package "$package" >/dev/null 2>&1); then
    echo 'ERROR: corrupted CI Adapter package was accepted.' >&2
    exit 1
fi
test ! -e "$received"

if (GITHUB_ACTIONS=true RUNNER_TEMP="$temp" wpai_ci_mcp_adapter_package \
    'https://github.com/WordPress/mcp-adapter/releases/download/v9.9.9/mcp-adapter.zip' >/dev/null 2>&1); then
    echo 'ERROR: unpinned CI Adapter version was accepted.' >&2
    exit 1
fi
echo 'PASS: verified same-run MCP Adapter ZIP is reused; local passthrough, tampering and unpinned versions are safe.'
