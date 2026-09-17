# Changelog

All notable public changes are documented here.

## 0.4.1

- Retire the one-time pre-0.4.0 Workspace migration runtime after the sole real installation was verified on canonical v0.4.0 with its existing Workspace Documents/Tasks intact.
- Remove the migration activation hook, migration-only PHP/localization files, storage-engine/transaction migration fixtures, and migration-specific CI lanes from maintained runtime/development source.
- Replace the temporary migration exception with a permanent canonical-identity invariant: current runtime/localization must not depend on former WP Native Builder Bridge identifiers.
- Keep equivalent real-WordPress canonical activation/identity coverage on both supported WordPress lanes and verify canonical Workspace create/read behavior without a migration schema marker.
- Preserve immutable v0.4.0 as the historical migration release for any installation that never completed the pre-canonical transition; v0.4.1 is the native canonical baseline for fresh installs and normal upgrades from v0.4.0.

## 0.4.0

- Establish the canonical WordPress plugin installation identity as `wp-ai-bridge/wp-ai-bridge.php`, with `WP_AI_Bridge` PHP namespace/constants, `wp-ai-bridge` text domain, `wp-ai-bridge/*` Ability identifiers, and canonical Bridge-owned storage/runtime identifiers.
- Remove former `wp-native-builder...` admin, OAuth/MCP, and Ability compatibility aliases from the maintained runtime; version 0.4.0 serves only the canonical WP AI Bridge routes and identifiers.
- Add a one-time activation migration for persistent Workspace Documents and Tasks left by the published 0.3.0 uninstall path, preserving WordPress record IDs, content/state, state hashes, and versions while moving them to canonical Workspace post-type/meta identifiers.
- Intentionally do not migrate former access-group settings, mutation/activity state, OAuth clients/tokens, or other connection/runtime state. The 0.4.0 installation starts from safe access defaults and ChatGPT must be reconnected against the canonical MCP endpoint.
- Change the supported 0.3.0 migration sequence to deactivate and uninstall/delete the former plugin first, then install/activate 0.4.0; the old uninstall preserves only the durable Workspace records needed by the new migrator.
- Update release packaging, Persian localization, static identity/confinement checks, and both supported real-WordPress migration lanes for the canonical identity.

## 0.3.0

- Add admin-controlled generic term metadata read/update/delete for exact authorized taxonomy terms, including private/non-REST taxonomies, with physical-row identity, stale-state protection, secret-policy exclusions, and destructive gating.
- Add explicitly enabled safe public-URL Media Library import with bounded streaming, WordPress-native sideload/attachment handling, authority rechecks, cleanup, and redacted failures.
- Add a separate default-off Source Editing boundary for installed plugin/theme source read, preview, apply, and conflict-safe recovery, using target-bound hashes and fixed-purpose no-overwrite replacement semantics while preserving native WordPress source-edit authority.
- Add paginated provider-neutral Ability catalog discovery so administrators/clients can inspect registered Core/provider contracts and Bridge delegation requirements without executing permission callbacks during discovery.
- Add a separate default-off External Packages boundary for administrator-authorized plugin/theme installation from bounded public HTTPS package URLs, preserving native WordPress install authority, Core Upgrader validation, redirect/SSRF protections, temporary-package cleanup, and secret-safe errors/logging.
- Add a default-off Authentication & Credentials boundary for Core-native WordPress Application Password list/get/create/rename/revoke lifecycle, with one-time plaintext return on create and strict hash/secret/log redaction.
- Add admin-controlled provider-neutral user/comment metadata read, update, and delete with exact WordPress target authority, role/session/credential exclusions, byte-exact stale-state protection, and destructive gating for delete.
- Add provider-neutral discovery plus exact read/update for non-sensitive WordPress settings registered through the Core REST settings contract, while preserving Core schema/sanitization, native `manage_options`, Bridge Site Configuration authority, and the existing specialized site-settings compatibility surface.
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
- Added generic typed post-meta read, update, and delete abilities for authorized WordPress post objects, including private and non-REST CPTs, while excluding Workspace internals and credential/session/security-like metadata keys.
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
