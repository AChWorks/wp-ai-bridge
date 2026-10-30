#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
compose="$root/tests/integration/compose.yml"
dc() {
    if [[ -n "${COMPOSE_FILE:-}" ]]; then
        docker compose "$@"
    else
        docker compose -f "$compose" "$@"
    fi
}
fixture=wp-content/plugins/wp-ai-bridge/tests/integration
for mode in before after; do
    key="crash-$(date +%s)-$RANDOM-$$"
    log="$(mktemp)"
    trap 'rm -f "$log"' EXIT
    echo "== WordPress native-effect process death: $mode =="
    status=0
    dc run --no-deps --rm -e WPAI_CRASH_KEY="$key" -e WPAI_CRASH_MODE="$mode" cli         eval-file "$fixture/issue128-crash-worker.php" --user=1 --allow-root >"$log" 2>&1 || status=$?
    expected=37
    if [[ "$mode" == after ]]; then expected=38; fi
    if [[ "$status" != "$expected" ]] || ! grep -Fq "WPAI_CRASH_CHECKPOINT=$mode" "$log"; then
        cat "$log" >&2
        echo "ERROR: real PHP worker did not die at the requested native-effect checkpoint (status=$status)." >&2
        exit 1
    fi
    dc run --no-deps --rm -e WPAI_CRASH_KEY="$key" -e WPAI_CRASH_MODE="$mode" cli         eval-file "$fixture/issue128-crash-verify.php" --user=1 --allow-root
    rm -f "$log"
    trap - EXIT
done
