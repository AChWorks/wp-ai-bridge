#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
dc() { docker compose -f "$root/tests/integration/compose.yml" -f "$root/tests/integration/compose-shared-private.yml" "$@"; }
fixture='wp-content/plugins/wp-ai-bridge/tests/integration/issue134-media-admission-race.php'
key="media-$(date +%s)-$RANDOM-$$"
one="$(mktemp)"
two="$(mktemp)"
cleanup() {
    dc run --rm -e WPAI134_MEDIA_KEY="$key" -e WPAI134_MEDIA_ACTION=cleanup \
        cli eval-file "$fixture" --user=1 --allow-root >/dev/null 2>&1 || true
    rm -f "$one" "$two"
}
trap cleanup EXIT

dc run --rm -e WPAI134_MEDIA_KEY="$key" -e WPAI134_MEDIA_ACTION=setup \
    cli eval-file "$fixture" --user=1 --allow-root

dc run --rm -e WPAI134_MEDIA_KEY="$key" -e WPAI134_MEDIA_ACTION=importer \
    cli eval-file "$fixture" --user=1 --allow-root >"$one" 2>&1 &
importer=$!

dc run --rm -e WPAI134_MEDIA_KEY="$key" -e WPAI134_MEDIA_ACTION=contender \
    cli eval-file "$fixture" --user=1 --allow-root >"$two" 2>&1 &
contender=$!

importer_exit=0
contender_exit=0
wait "$contender" || contender_exit=$?
wait "$importer" || importer_exit=$?
if [[ "$contender_exit" != 0 || "$importer_exit" != 0 ]]; then
    cat "$one" "$two" >&2
    echo 'ERROR: Independent native Media admission race failed.' >&2
    exit 1
fi
grep -q '^WPAI134_MEDIA_RESULT=committed$' "$one"
grep -q '^PASS: #134 C independent begin/cancel' "$two"
cat "$two"
dc run --rm -e WPAI134_MEDIA_KEY="$key" -e WPAI134_MEDIA_ACTION=verify \
    cli eval-file "$fixture" --user=1 --allow-root
echo 'PASS: #134 C two-worker real WordPress Media lock/admission and no duplicate.'
