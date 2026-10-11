#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
compose="$root/tests/integration/compose.yml"
dc() { docker compose -f "$compose" "$@"; }
fixture=wp-content/plugins/wp-ai-bridge/tests/integration
for mode in operation canonical; do
    key="cap-$(date +%s)-$RANDOM-$$"
    one="$(mktemp)"
    two="$(mktemp)"
    cleanup() {
        dc exec -T wordpress rm -rf "/var/www/html/wp-content/wpai128-capacity-$key-$mode" >/dev/null 2>&1 || true
        rm -f "$one" "$two"
    }
    trap cleanup EXIT
    echo "== Different-identity 2047/2048 capacity boundary: $mode =="
    dc run --rm -e WPAI_CAP_KEY="$key" -e WPAI_CAP_MODE="$mode" -e WPAI_CAP_ACTION=setup cli         eval-file "$fixture/issue128-capacity-race-state.php" --user=1 --allow-root
    dc run --rm -e WPAI_CAP_KEY="$key" -e WPAI_CAP_MODE="$mode" -e WPAI_CAP_SLOT=one cli         eval-file "$fixture/issue128-capacity-race-worker.php" --user=1 --allow-root >"$one" 2>&1 &
    first=$!
    dc run --rm -e WPAI_CAP_KEY="$key" -e WPAI_CAP_MODE="$mode" -e WPAI_CAP_SLOT=two cli         eval-file "$fixture/issue128-capacity-race-worker.php" --user=1 --allow-root >"$two" 2>&1 &
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
    if [[ "$(grep -Ec '^WPAI_CAP_RESULT=created:' <<<"$combined" || true)" != 1 ||
          "$(grep -Ec '^WPAI_CAP_RESULT=capacity$' <<<"$combined" || true)" != 1 ]]; then
        printf '%s\n' "$combined" >&2
        echo "ERROR: the loser must fail with create_claim_capacity before effect." >&2
        exit 1
    fi
    dc run --rm -e WPAI_CAP_KEY="$key" -e WPAI_CAP_MODE="$mode" -e WPAI_CAP_ACTION=verify cli         eval-file "$fixture/issue128-capacity-race-state.php" --user=1 --allow-root
    cleanup
    trap - EXIT
done
