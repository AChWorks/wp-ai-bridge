#!/usr/bin/env bash
set -euo pipefail

# Reuse the installed disposable WordPress integration compose stack.
root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
dc() { docker compose -f "$compose_file" "$@"; }
wp() { dc run --rm "$@"; }
fixture="wp-content/plugins/wp-ai-bridge/tests/integration"
for mode in operation canonical; do
    key="race-$(date +%s)-$RANDOM-$$"
    one="$(mktemp)"
    two="$(mktemp)"
    cleanup() {
        dc exec -T wordpress rm -rf "/var/www/html/wp-content/wpai128-race-$key-$mode" >/dev/null 2>&1 || true
        rm -f "$one" "$two"
    }
    trap cleanup EXIT
    echo "== Two-process native MySQL atomic race: $mode =="
    wp -e WPAI128_RACE_KEY="$key" -e WPAI128_RACE_MODE="$mode" -e WPAI128_RACE_SLOT=one \
        cli eval-file "$fixture/issue128-atomic-race-worker.php" --user=1 --allow-root >"$one" 2>&1 &
    first=$!
    wp -e WPAI128_RACE_KEY="$key" -e WPAI128_RACE_MODE="$mode" -e WPAI128_RACE_SLOT=two \
        cli eval-file "$fixture/issue128-atomic-race-worker.php" --user=1 --allow-root >"$two" 2>&1 &
    second=$!
    first_status=0
    second_status=0
    wait "$first" || first_status=$?
    wait "$second" || second_status=$?
    if [[ "$first_status" != 0 || "$second_status" != 0 ]]; then
        cat "$one" "$two" >&2
        echo "ERROR: concurrent WordPress workers failed." >&2
        exit 1
    fi
    combined="$(cat "$one" "$two")"
    created_count="$(grep -Ec '^WPAI128_RACE_RESULT=created:' <<< "$combined" || true)"
    contended_count="$(grep -Ec '^WPAI128_RACE_RESULT=contended:' <<< "$combined" || true)"
    if [[ "$created_count" != 1 || "$contended_count" != 1 ]]; then
        printf '%s\n' "$combined" >&2
        echo "ERROR: only one PHP worker may commit the create claim." >&2
        exit 1
    fi
    wp -e WPAI128_RACE_KEY="$key" -e WPAI128_RACE_MODE="$mode" \
        cli eval-file "$fixture/issue128-atomic-race-verify.php" --user=1 --allow-root
    cleanup
    trap - EXIT
done
