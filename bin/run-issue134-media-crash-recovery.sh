#!/usr/bin/env bash
# One crashed native Media worker, followed by a distinct admin recovery worker.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
dc() {
    docker compose -f "$root/tests/integration/compose.yml" \
        -f "$root/tests/integration/compose-shared-private.yml" "$@"
}
fixture='wp-content/plugins/wp-ai-bridge/tests/integration/issue134-media-crash-recovery.php'
key="crash-$(date +%s)-$RANDOM-$$"
cleanup() {
    dc run --rm -e WPAI134_RECOVERY_KEY="$key" -e WPAI134_RECOVERY_ACTION=cleanup \
        cli eval-file "$fixture" --user=1 --allow-root >/dev/null 2>&1 || true
}
trap cleanup EXIT

dc run --rm -e WPAI134_RECOVERY_KEY="$key" -e WPAI134_RECOVERY_ACTION=setup \
    cli eval-file "$fixture" --user=1 --allow-root
dc run --rm -e WPAI134_RECOVERY_KEY="$key" -e WPAI134_RECOVERY_ACTION=worker \
    cli eval-file "$fixture" --user=1 --allow-root
dc run --rm -e WPAI134_RECOVERY_KEY="$key" -e WPAI134_RECOVERY_ACTION=verify \
    cli eval-file "$fixture" --user=1 --allow-root
echo 'PASS: #134 B1 separate-worker native Media crash/reservation/admin recovery.'
