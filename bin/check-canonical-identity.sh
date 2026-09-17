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

migration_store='src/class-migrator.php'
if [[ ! -f "$migration_store" ]] || ! grep -qF "'wpnb_doc'" "$migration_store" || ! grep -qF "'wpnb_task'" "$migration_store" || ! grep -qF "'_wpnb_workspace_state'" "$migration_store"; then
    echo "ERROR: one-time Workspace migration boundary is missing its exact legacy identifiers." >&2
    exit 1
fi

# Inventory each wpdb member independently so an unexpected method cannot hide on a line
# that also contains an allowed table/member. The one-time migrator retains a deliberately
# narrow surface: exact core tables plus query/get_var/prepare/esc_like only.
migration_member_inventory="$(grep -oE '\$wpdb->[A-Za-z_][A-Za-z0-9_]*' "$migration_store" | sort -u || true)"
unexpected_migration_members="$(
    printf '%s\n' "$migration_member_inventory" \
        | grep -vE '^\$wpdb->(posts|postmeta|options|query|get_var|prepare|esc_like)$' \
        || true
)"
if [[ -n "$unexpected_migration_members" ]]; then
    printf '%s\n' "$unexpected_migration_members"
    echo "ERROR: Workspace migrator uses a database member outside its fixed migration surface." >&2
    exit 1
fi

# F-001 remediation permits only these fixed-purpose additional reads: migration identity
# inventories, exact schema marker state, metadata-conflict inventory, and the exact table-engine
# preflight. Pin their literal shapes/counts so transactional safety cannot become generic SQL.
if [[ "$(grep -cF "SELECT CONCAT(COALESCE(SUM(post_type = 'wpnb_doc'), 0), ':', COALESCE(SUM(post_type = 'wpnb_task'), 0), ':', COALESCE(SUM(post_type = 'wpai_doc'), 0), ':', COALESCE(SUM(post_type = 'wpai_task'), 0)) FROM {\$wpdb->posts} WHERE post_type IN ('wpnb_doc','wpnb_task','wpai_doc','wpai_task')" "$migration_store" || true)" != "1" ]]; then
    echo "ERROR: Workspace migrator post-identity inventory changed outside its fixed contract." >&2
    exit 1
fi
if [[ "$(grep -cF "SELECT CONCAT(COALESCE(SUM(meta_key = '_wpnb_workspace_state'), 0), ':', COALESCE(SUM(meta_key = '_wpai_workspace_state'), 0)) FROM {\$wpdb->postmeta} WHERE meta_key IN ('_wpnb_workspace_state','_wpai_workspace_state')" "$migration_store" || true)" != "1" ]]; then
    echo "ERROR: Workspace migrator metadata-identity inventory changed outside its fixed contract." >&2
    exit 1
fi
if [[ "$(grep -cF "SELECT COALESCE(MAX(option_value), '__wpai_absent__') FROM {\$wpdb->options} WHERE option_name = 'wp_ai_bridge_schema_version'" "$migration_store" || true)" != "1" ]]; then
    echo "ERROR: Workspace migrator schema-marker inventory changed outside its fixed contract." >&2
    exit 1
fi
if [[ "$(grep -cF "SELECT COUNT(*) FROM {\$wpdb->postmeta} legacy INNER JOIN {\$wpdb->postmeta} canonical ON canonical.post_id = legacy.post_id WHERE legacy.meta_key = '_wpnb_workspace_state' AND canonical.meta_key = '_wpai_workspace_state'" "$migration_store" || true)" != "1" ]]; then
    echo "ERROR: Workspace migrator metadata-conflict inventory changed outside its fixed contract." >&2
    exit 1
fi
if [[ "$(grep -cF "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{\$table_name}'" "$migration_store" || true)" != "1" || "$(grep -cF "preg_match( '/^[A-Za-z0-9_]+\$/D', \$table )" "$migration_store" || true)" != "1" || "$(grep -cF 'esc_sql( $table )' "$migration_store" || true)" != "1" ]]; then
    echo "ERROR: Workspace migrator InnoDB preflight changed outside its fixed exact-table contract." >&2
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

echo "PASS: canonical WP AI Bridge runtime identity; legacy identifiers and migration SQL remain confined to the one-time Workspace migrator."
