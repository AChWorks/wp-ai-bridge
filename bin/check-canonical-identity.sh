#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

# The one-time pre-0.4.0 Workspace migrator is the only production PHP source
# allowed to know legacy identifiers. Current runtime surfaces must be canonical.
legacy_pattern='WP_Native_Builder_Bridge|WP_NATIVE_BUILDER_BRIDGE|wp-native-builder-bridge|wp-native-builder/|wp_native_builder_bridge|wpnb_'

runtime_hits="$(
    {
        grep -R -nE "$legacy_pattern" src --include='*.php' --exclude='class-migrator.php' || true
        grep -nE "$legacy_pattern" wp-ai-bridge.php uninstall.php || true
        grep -R -nE "$legacy_pattern" languages --include='*.php' --exclude='migration.php' || true
    } | sed '/^[[:space:]]*$/d'
)"

if [[ -n "$runtime_hits" ]]; then
    printf '%s\n' "$runtime_hits"
    echo "ERROR: legacy WP Native Builder identity leaked outside the one-time migration boundary." >&2
    exit 1
fi

if [[ ! -f src/class-migrator.php ]] || ! grep -qF "'wpnb_doc'" src/class-migrator.php || ! grep -qF "'wpnb_task'" src/class-migrator.php || ! grep -qF "'_wpnb_workspace_state'" src/class-migrator.php; then
    echo "ERROR: one-time Workspace migration boundary is missing its exact legacy identifiers." >&2
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

echo "PASS: canonical WP AI Bridge runtime identity; legacy identifiers confined to the one-time Workspace migrator."
