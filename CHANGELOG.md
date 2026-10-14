# Changelog

All notable public changes are documented here.

## Unreleased

## 0.6.3

- Display the Task inspector's **raw Notes body** as an escaped, whitespace-preserving LTR/left-aligned text region, consistent with the inspected Document Markdown body. The Task heading, Project ID, Goal, Notes label, Dashboard and mixed-language tables continue to inherit the localized WordPress admin direction (#157).
- Extend real WordPress administrator regressions with multiline English/Persian Notes and escaped HTML; preserve stored Workspace data, grants, OAuth, and original plugin identity. Upgrade smoke now starts from the actual SHA-256-pinned published v0.6.2 ZIP.

## 0.6.2

- Restore the native WordPress admin direction and alignment for Dashboard, Documents and Tasks tables, mixed Persian/English titles, document/task inspector headings, and task goal/notes. Remove excessive per-cell LTR/left overrides introduced in PR #152, while retaining precise inline technical identifier ordering (#157).
- Keep the sole explicitly LTR/left-aligned area as the escaped raw Markdown body of an inspected Workspace document. This is display-only: no changes to stored records, project bindings, capabilities, Bridge grants, OAuth, database layout or public Abilities.
- Extend isolated WordPress regression coverage for mixed-language Workspace titles and default-inherited layout; upgrade testing now replaces the exact published v0.6.1 ZIP with the new candidate and checks preservation of Workspace, OAuth and permission state.

## 0.6.1

- Scale persistent per-site Workspace document/task capacity beyond the former fixed 200-item ceiling: independent finite administrator count policies, bounded/lazy projected page reads and conservative capacity/concurrency handling, while retaining canonical-key identity, legacy records, private authorization and exact complete-or-error export semantics (#153, PR #154).
- Add a separate administrator-controlled finite capacity for durable generic `operation_id` create receipts (128–100000, default 2048). An existing authorized receipt remains recoverable when the configured capacity is lowered; canonical Workspace document-key claims stay on their separate quota. No receipt purge or access-grant change (#147, PR #155).
- Improve existing WordPress administrator Workspace views: show exact per-record Project IDs without inventing a Project manager, distinguish mixed-project Dashboard focus, display AI-authored English Markdown/task content legibly within RTL pages, and clarify Persian binary-capacity KiB/MiB labels (#150, #151, #152).
- Retain the existing canonical plugin identity, WordPress Settings/OAuth credentials, Bridge grants and durable WordPress Workspace records. The proposed Cron scheduling/management abilities in PR #144 are **not included** in this release, pending separate security/public-API acceptance.
- Source acceptance includes real isolated WordPress 6.9/current with official MCP Adapter 0.6.1/0.7.0, multisite, concurrency, ZIP upgrade and exact candidate CI; hosted-client attachment handling, deployment and installed fresh-chat project recovery remain separate real-site acceptance activities (#126, #134).

## 0.6.0

- Introduce explicit, authenticated native WordPress WXR content portability: Core XML export in bounded private byte windows, verified private import staging, read-only structural inspection, exact-source/destination-bound importer plan, and explicit consent before official WordPress Importer 0.9.6 effects (#139, PR #146). The importer denies remote media fetching and new user creation, rejects unsafe PHP object/reference metadata, preserves approved exact-string bytes, and records non-atomic/unknown results durably with no blind replay. This is content portability, NOT a complete database/media site backup.
- Deliver configurable site-administrator finite private-transfer capacities, purpose-bound and SHA-256-verified multipart ingress/egress, Media snapshots/import, optional bounded inline Media/file fallback sizes, and long UTF-8 content commit with native optimistic concurrency (#134, PR #145). Client-side attachment materialization, Gateway forwarding, multi-host private storage and public-network performance still require separate verification; a successful MCP tool response is NOT automatically an attached file.
- Add project-aware, lossless and page-resumable Workspace documents/tasks with stable project identity and readback, plus optional durable operation_id deduplication for six Bridge-owned non-idempotent create operations (#126, #128, PR #143). Unknown or interrupted effects must not be blindly retried.
- Add native WordPress Site Health diagnostics with ordinary WordPress and Bridge permissions, read-only cached Core update availability with truthful stale/unknown states (NO WordPress Core upgrade executor), and more accurate local-vs-remote OAuth/MCP connection diagnostics (#130, #140, #141, PRs #142/#143).
- Preserve the canonical wp-ai-bridge/wp-ai-bridge.php installation, existing OAuth clients/refresh sessions, WordPress Workspace data, and previously configured Bridge grants across normal v0.5.1 replacement. Newly available effectful Abilities remain subject to current WordPress capabilities and default-off Bridge access groups. No reauthorization, reset or permission broadening is automatic.
- Native WordPress and official MCP Adapter 0.6.1/0.7.0 acceptance includes import-effect/partial-outcome tests, bounded transfer fidelity, real isolated HTTPS/OAuth/MCP interactions and published-release-to-candidate upgrade regression. These tests do NOT prove a production deployment or hosted ChatGPT file upload/download support.

## 0.5.1

- Retire the unwanted manual WordPress-dashboard Private ZIP Packages upload/inspection/install subsystem and its runtime/CI/documentation surface; preserve existing native WordPress.org/public HTTPS installation and Media capabilities (#135). Existing historic v0.5.0 files/options are left for optional manual removal; the maintained plugin adds no migration or cleanup code.
- Clarify the required AI/client-agnostic bidirectional text/document/binary/media exchange contract, which remains a future independently verified transport deliverable (#134).

## 0.5.0

- Provide bounded Gutenberg discovery and targeted subtree reads for large/nested pages, with exact path and fingerprint interoperability; return compact committed identity after oversized block mutations rather than an ambiguous transport failure.
- Add UTF-8-safe, hash-guarded raw content windows for large posts/pages and a shared encoded-response budget helper, with explicit completeness/continuation metadata.
- Add bounded and pageable site-context discovery, including current-principal capability flags, for provider-heavy WordPress installations (#110).
- Add read-only plugin/theme action-specific authorization diagnosis without executing Core lifecycle mutations (#99).
- Integrate administrator-opt-in filtered registered REST route discovery and guarded native local REST execution, preserving default-off high-trust consent and Core/provider denials (#119).
- Integrate source-agnostic private plugin/theme ZIP browser staging, bounded hash-bound inspection, and explicit native installation without activation; fail closed when lifecycle workers do not share the verified private storage domain or extension lock (#108).
- Publish finite rotating OAuth refresh-token lifetime hints to clients while retaining one-time rotation, revocation and bounded loss recovery.
- Document the implemented authenticated Bridge-owned browser multipart ZIP transfer and its limits: MCP/Gateway JSON calls do not establish generic binary upload streaming or deployment support.

## 0.4.2

- Make rotating OAuth refresh-token exchange recover deterministically from an ambiguous lost response using bounded client/resource/user/scope/revision-bound recovery state while preserving one-time rotation and replay rejection.
- Keep the Gateway-facing provider-native Ability catalog aligned with the active MCP Adapter exposure resolver; when that runtime resolver is unavailable, fail closed unless `meta.mcp.public=true` is explicitly present.
- Bound provider-native Ability results at the canonical Bridge MCP boundary with the shared sensitive-identity policy so credential/security-like nested results, errors, and throwables cannot cross; direct/default Adapter execution and Bridge-owned purpose-specific credential contracts remain unchanged.
- Expand regression coverage for refresh recovery/concurrency, result-boundary secret confinement, catalog-to-Adapter execution parity, and canonical identity across the supported WordPress lanes.
- Require no data migration, OAuth client reset, or ChatGPT reconnection solely for this patch release on an already-canonical v0.4.x installation.

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
