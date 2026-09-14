# Changelog

All notable public changes are documented here.

## Unreleased
- Allow administrators to approve additional exact public-HTTPS OAuth client metadata identities for independently operated MCP Gateways while preserving built-in ChatGPT behavior, private-key JWT authentication, PKCE, exact redirect/resource binding, and Bridge/WordPress authorization boundaries.
- Add a default-off Comments administration boundary with fixed Core REST routing, privacy-bounded output, exact moderation authority, and dual-gated permanent deletion.

- Added a default-off **Native Abilities** delegation boundary for registered Core/provider Abilities invoked through the canonical or legacy WP AI Bridge MCP routes; provider/WordPress permission callbacks remain independently authoritative and ordinary direct/default-server execution is unchanged.
- Ability contract discovery now reports whether execution uses Bridge ability-specific policy or requires the broad `native_abilities` delegation group without evaluating the target permission callback.
- Renamed the public product from **WP Native Builder Bridge** to **WP AI Bridge**.
- Migrated canonical admin, OAuth, and MCP public routes to `wp-ai-bridge...` while retaining bounded legacy aliases for existing bookmarks and OAuth/MCP connections.
- Preserved established compatibility identifiers including the installed plugin directory/entrypoint, PHP namespace/constants, text domain, stored option/transient keys, Workspace identifiers, and `wp-native-builder/*` Ability names so existing installations upgrade in place without a second plugin or data store.
- Renamed the public distributable artifact to `wp-ai-bridge.zip` while deliberately keeping the archive's installed root directory as `wp-native-builder-bridge/` for WordPress upgrade continuity.
- Added exact-base upgrade coverage that seeds real settings, Workspace, and legacy OAuth state on the pre-rename integrated build and verifies continuity after upgrading to WP AI Bridge on both supported WordPress lanes.

## 0.2.0

- Added an administrator-controlled Advanced Metadata access group, disabled by default, for provider-neutral WordPress post metadata workflows.
- Added generic typed post-meta read, update, and delete abilities for authorized WordPress post objects, including private and non-REST CPTs, while excluding Workspace internals and credential/session/security-like keys.
- Added physical-row metadata identity, byte-exact optimistic concurrency, SQL NULL handling, ambiguity refusal, and row-scoped compensation so stale or concurrent writes fail closed instead of silently corrupting metadata.
- Preserved registered Core/provider metadata authorization, required Users & Destructive permission for generic metadata deletion, and kept generic SQL, options, user meta, filesystem, shell, and credential access out of scope.

## 0.1.2

- Fixed Gutenberg targeted mutations so canonical top-level block path `0` works and non-canonical leading-zero aliases such as `00` are rejected.
- Fixed the Gravity Forms GFAPI fallback read permission to use the provider-supported `gravityforms_edit_forms` capability while preserving native `gravityforms/*` precedence.
- Added regression coverage for authorized and denied Gravity Forms fallback reads, including raw MCP transport coverage without the invalid capability.
- Codified the discovery-first, provider-agnostic integration architecture: reuse native Abilities first, use bounded public-API fallbacks only for real gaps, and never treat capability discovery as privilege escalation.

## 0.1.1

- Added provider-neutral metadata administration across content, taxonomies, and users with a default-off `Advanced Metadata` permission group.
- Added exact-row post-meta and term-meta contracts with physical row identity, byte-exact optimistic concurrency, SQL NULL modeling, bounded reads/writes, guarded deletion, and row-scoped compensation.
- Kept arbitrary SQL/options/filesystem/shell access out of the Bridge and required `Users & Destructive` in addition to `Advanced Metadata` for generic metadata deletion.
- Added WordPress 6.9 and 7.1 integration coverage for metadata handling, protected-key denial, stale writes, duplicate rows, NULL state, and cleanup/compensation paths.

## 0.1.0

- Initial public release.
