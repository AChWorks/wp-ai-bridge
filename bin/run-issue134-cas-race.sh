#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
dc() { docker compose -f "$root/tests/integration/compose.yml" "$@"; }
fixture='wp-content/plugins/wp-ai-bridge/tests/integration'
for mode in two_leases ordinary; do
    key="cas-$(date +%s)-$RANDOM-$$"
    one="$(mktemp)"
    two="$(mktemp)"
    cleanup() {
        dc run --rm -e WPAI134_CAS_KEY="$key" -e WPAI134_CAS_MODE="$mode" \
            -e WPAI134_CAS_ACTION=cleanup cli eval-file "$fixture/issue134-cas-race-state.php" --user=1 --allow-root >/dev/null 2>&1 || true
        rm -f "$one" "$two"
    }
    trap cleanup EXIT
    dc run --rm -e WPAI134_CAS_KEY="$key" -e WPAI134_CAS_MODE="$mode" \
        -e WPAI134_CAS_ACTION=setup cli eval-file "$fixture/issue134-cas-race-state.php" --user=1 --allow-root
    dc run --rm -e WPAI134_CAS_KEY="$key" -e WPAI134_CAS_MODE="$mode" \
        -e WPAI134_CAS_SLOT=one cli eval-file "$fixture/issue134-cas-race-worker.php" --user=1 --allow-root >"$one" 2>&1 &
    first=$!
    dc run --rm -e WPAI134_CAS_KEY="$key" -e WPAI134_CAS_MODE="$mode" \
        -e WPAI134_CAS_SLOT=two cli eval-file "$fixture/issue134-cas-race-worker.php" --user=1 --allow-root >"$two" 2>&1 &
    second=$!
    first_status=0
    second_status=0
    wait "$first" || first_status=$?
    wait "$second" || second_status=$?
    if [[ "$first_status" != 0 || "$second_status" != 0 ]]; then
        cat "$one" "$two" >&2
        echo 'ERROR: WordPress two-worker native CAS race failed.' >&2
        exit 1
    fi
    if [[ "$mode" == two_leases ]]; then
        grep -q '^WPAI134_CAS_RESULT=committed$' "$one"
        grep -q '^WPAI134_CAS_RESULT=conflict$' "$two"
    else
        grep -q '^WPAI134_CAS_RESULT=conflict$' "$one"
        grep -q '^WPAI134_CAS_RESULT=ordinary-native$' "$two"
    fi
    dc run --rm -e WPAI134_CAS_KEY="$key" -e WPAI134_CAS_MODE="$mode" \
        -e WPAI134_CAS_ACTION=verify cli eval-file "$fixture/issue134-cas-race-state.php" --user=1 --allow-root
    echo "PASS: #134 R2 physical InnoDB WordPress CAS against $mode competitor."
    cleanup
    trap - EXIT
done
