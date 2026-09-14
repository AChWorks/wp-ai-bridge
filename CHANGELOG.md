# Changelog

All notable public changes are documented here.

## Unreleased
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

- Polished public plugin metadata and documentation.
- Set plugin author to ACh and plugin homepage to the GitHub repository.
- Kept the WP Native Builder Bridge product name untranslated as a brand name.
- Improved Tasks filter spacing in the WordPress admin UI.
- Corrected Code Snippets 3.9.x integration-status detection while retaining 3.10.x compatibility.
- Retained the complete direct ChatGPT OAuth/MCP, Persistent Workspace, Persian localization, and security boundaries introduced in 0.1.0.

## 0.1.0

- Initial public release.
- Direct ChatGPT Workspace App connection over HTTPS with WordPress-backed OAuth.
- Typed WordPress Abilities for content, Gutenberg blocks, media, taxonomies, navigation, site settings, extensions, users, and optional providers.
- Persistent Workspace with Dashboard, Documents, Tasks, Activity, Settings, optimistic concurrency, export, and explicit clear lifecycle.
- Astra native Ability reuse, compatible Code Snippets fallback, and Gravity Forms GFAPI fallback.
- Bundled Persian (`fa_IR`) localization and RTL-compatible admin UI.
- GPL-2.0-or-later license.
