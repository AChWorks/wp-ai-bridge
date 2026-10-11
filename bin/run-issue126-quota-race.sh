#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
compose="$root/tests/integration/compose.yml"
dc() { docker compose -f "$compose" "$@"; }
fixture=wp-content/plugins/wp-ai-bridge/tests/integration
modes=(document task)
if [[ "${WPAI_QUOTA_MULTISITE:-0}" == 1 ]]; then
    modes=(document)
fi
for mode in "${modes[@]}"; do
    key="quota-$(date +%s)-$RANDOM-$$"
    one="$(mktemp)"
    two="$(mktemp)"
    cleanup() {
        dc exec -T wordpress rm -rf "/var/www/html/wp-content/wpai126-quota-$key-$mode" >/dev/null 2>&1 || true
        rm -f "$one" "$two"
    }
    trap cleanup EXIT
    echo "== Two-process Workspace 199/200 cap: $mode =="
    dc run --rm -e WPAI_QUOTA_KEY="$key" -e WPAI_QUOTA_MODE="$mode" -e WPAI_QUOTA_ACTION=setup -e WPAI_QUOTA_MULTISITE="${WPAI_QUOTA_MULTISITE:-0}" cli         eval-file "$fixture/issue126-quota-race-state.php" --user=1 --allow-root
    dc run --rm -e WPAI_QUOTA_KEY="$key" -e WPAI_QUOTA_MODE="$mode" -e WPAI_QUOTA_SLOT=one -e WPAI_QUOTA_MULTISITE="${WPAI_QUOTA_MULTISITE:-0}" cli         eval-file "$fixture/issue126-quota-race-worker.php" --user=1 --allow-root >"$one" 2>&1 &
    first=$!
    dc run --rm -e WPAI_QUOTA_KEY="$key" -e WPAI_QUOTA_MODE="$mode" -e WPAI_QUOTA_SLOT=two -e WPAI_QUOTA_MULTISITE="${WPAI_QUOTA_MULTISITE:-0}" cli         eval-file "$fixture/issue126-quota-race-worker.php" --user=1 --allow-root >"$two" 2>&1 &
    second=$!
    first_status=0
    second_status=0
    wait "$first" || first_status=$?
    wait "$second" || second_status=$?
    if [[ "$first_status" != 0 || "$second_status" != 0 ]]; then
        cat "$one" "$two" >&2
        exit 1
    fi
    combined="$(cat "$one" "$two")"
    if [[ "$(grep -Ec '^WPAI_QUOTA_RESULT=created:' <<<"$combined" || true)" != 1 ||
          "$(grep -Ec '^WPAI_QUOTA_RESULT=capacity$' <<<"$combined" || true)" != 1 ]]; then
        printf '%s\n' "$combined" >&2
        echo "ERROR: Workspace must admit exactly one worker at final record slot." >&2
        exit 1
    fi
    dc run --rm -e WPAI_QUOTA_KEY="$key" -e WPAI_QUOTA_MODE="$mode" -e WPAI_QUOTA_ACTION=verify -e WPAI_QUOTA_MULTISITE="${WPAI_QUOTA_MULTISITE:-0}" cli         eval-file "$fixture/issue126-quota-race-state.php" --user=1 --allow-root
    cleanup
    trap - EXIT
done
