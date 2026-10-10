#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

legacy_pattern='WP_Native_Builder_Bridge|WP_NATIVE_BUILDER_BRIDGE|wp-native-builder-bridge|wp-native-builder/|wp_native_builder_bridge|wpnb_'

runtime_hits="$(
    {
        grep -R -nE "$legacy_pattern" src --include='*.php' || true
        grep -nE "$legacy_pattern" wp-ai-bridge.php uninstall.php || true
        grep -R -nE "$legacy_pattern" languages --include='*.php' || true
    } | sed '/^[[:space:]]*$/d'
)"

if [[ -n "$runtime_hits" ]]; then
    printf '%s\n' "$runtime_hits"
    echo "ERROR: retired WP Native Builder Bridge identity remains in maintained runtime/localization source." >&2
    exit 1
fi

if [[ -e src/class-migrator.php || -e languages/fa_IR-parts/migration.php ]]; then
    echo "ERROR: retired one-time migration files remain in maintained source." >&2
    exit 1
fi

if grep -nE 'register_activation_hook[[:space:]]*\([^;]*(Migrator|migrat)' wp-ai-bridge.php; then
    echo "ERROR: plugin entrypoint still depends on the retired migration activation path." >&2
    exit 1
fi

if [[ ! -f wp-ai-bridge.php ]] || [[ -f wp-native-builder-bridge.php ]]; then
    echo "ERROR: canonical plugin entrypoint identity is not exclusive." >&2
    exit 1
fi

if grep -R -nE 'LEGACY_|wp-native-builder' src/Admin src/Auth --include='*.php'; then
    echo "ERROR: legacy admin/OAuth route aliases remain in canonical runtime." >&2
    exit 1
fi

header_version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/p' wp-ai-bridge.php)"
constant_version="$(sed -nE "s/^define\( 'WP_AI_BRIDGE_VERSION', '([0-9]+\.[0-9]+\.[0-9]+)' \);$/\1/p" wp-ai-bridge.php)"
if [[ -z "$header_version" || "$header_version" != "$constant_version" ]]; then
    echo "ERROR: canonical plugin header and WP_AI_BRIDGE_VERSION do not match a valid release version." >&2
    exit 1
fi
if ! grep -qxF "## $header_version" CHANGELOG.md; then
    echo "ERROR: changelog does not contain the exact canonical plugin release version." >&2
    exit 1
fi

echo "PASS: canonical WP AI Bridge runtime has no retired migration dependency or former product identity."
