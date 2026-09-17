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

if ! grep -qF "Version: 0.4.1" wp-ai-bridge.php || ! grep -qF "define( 'WP_AI_BRIDGE_VERSION', '0.4.1' );" wp-ai-bridge.php; then
    echo "ERROR: canonical cleanup release version metadata is not 0.4.1." >&2
    exit 1
fi

echo "PASS: canonical WP AI Bridge runtime has no retired migration dependency or former product identity."
