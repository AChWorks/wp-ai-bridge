# Changelog

All notable public changes are documented here.

## Unreleased

## 0.6.11

- Refresh PHP's stat cache after writing owner-only Media sideload input and confirm actual filesize before WordPress Core's native importer; also refresh verified confidential-transfer Media staging metadata. This addresses a reproducible `upload_error` / "File is empty" failure path observed on LiteSpeed/PHP 8.1 (#134, #173; [PR #191](https://github.com/AChWorks/wp-ai-bridge/pull/191)). Preserve the existing authorized Media Library destination, exact-byte integrity, private staging and rejection/exception cleanup. Isolated Core integration and exact-head review/CI passed, but the **installed LiteSpeed fix remains unverified** until this release is installed and tested.
- Advance the disposable native Core-upgrade continuity regression from the genuine published **v0.6.10** ZIP, pinned by SHA-256 `763542b6e843cd8acfe13e140815656d219464d43b4095964b2d080fefdd5043`, to the current candidate. Verify preservation of canonical OAuth/Workspace/permissions without changing existing runtime upgrade semantics.
- This is a WordPress-side patch, not a new Gateway binary/attachment transport or universal webserver/CDN-alias privacy claim. Existing Gateway #147/#154 and Bridge #134/#173 end-to-end acceptance remain separate.

## 0.6.10

- Restore selected native WordPress Site Health checks in authenticated MCP/REST requests by lazily loading only the fixed WordPress Core admin helpers required by the selected allowlisted test (#186). This addresses observed `get_plugin_updates()` and `wp_check_php_version()` PHP callback exceptions without weakening permissions, Core method/REST identity checks or fabricating healthy status; separate bare WordPress bootstrap and official MCP Adapter regressions cover the affected calls.
- Let authorized administrators and WordPress's scheduled Core updater discover the canonical WP AI Bridge release even when WordPress.org's general plugin-update request fails before the custom `update_plugins_github.com` hook. Keep only the already validated official GitHub stable-release package, leave unrelated plugins' offers unchanged, and retain Core's normal installation and consent (#184).
- Honor WordPress's **Check Again** action for the separate Bridge metadata cache, with permission checks. Reduce the successful-cache window from six to one hour while retaining the bounded 15-minute failure retry interval and existing strict release-origin checks.
- Test an actual WordPress.org update-API failure followed by authenticated Core plugin update, and pin the real published v0.6.9 ZIP by SHA-256 `3920e03ea296b7b7a927f1a8e85e672db03ccb138f76150886a246871195f88d` for the v0.6.9-to-v0.6.10 migration fixture. Preserve OAuth clients, Workspace and canonical plugin identity.
- **Network limits:** this is not an alternate download transport. GitHub's Release API and official ZIP URL must still be reachable from the hosting network. The proposed second mirror for Iranian hosts is separately gated on measured access, owner-controlled infrastructure, authenticated release metadata and ZIP integrity. Do not use unverified third-party proxies; publishing this GitHub Release does not establish that a WordPress site has installed it or can reach GitHub.

## 0.6.9

- Preserve the **actually observed** WordPress/Bridge MCP error code, message and useful bounded structured data with explicit `origin`, `stage` and `code_source`; never guess an upstream code or normalize unfamiliar provider errors into generic denials (PR #181). WordPress 7.1 Ability lifecycle observations retain codes when actually available; older supported WordPress/Adapter paths explicitly report when only Adapter text can be observed.
- Extend the **existing bounded Activity / Mutation_Log** with administrator-readable, redacted error diagnostics and opaque `diagnostic_reference` lookup. Detailed records are restricted to the originating WordPress administrator **and authenticated OAuth client**; fixed transfer/Media metadata retains its established administrator visibility. Gateway-only errors cannot be recovered by WordPress (`diagnostics-read`, PRs #181/#182).
- Mask credentials, quoted/opaque Cookie fragments, private paths and sensitive nested diagnostic fields without discarding separately identifiable safe HTTP context or original WordPress error identities. Fail closed if a regex cannot safely complete; disclose truthful `redacted`/`truncated` flags. Bound serialized diagnostic JSON to 32768 bytes while preserving supported scalar, array and object semantics.
- Preserve provider-owned permission checks, MCP wire shapes, successful responses, administrator grants, OAuth identities, Workspace and cautious unknown-effect/no-blind-retry behavior. Exact-head R9 CI for PR #181 passed 20/20 jobs, including WordPress 6.9.4/7.1.3 with MCP Adapter 0.6.1/0.7.0/0.7.1 and 118 provenance assertions; an independent security review returned `APPROVE` on the unchanged error-handling implementation.
- **Delivery scope:** Publishing this stable GitHub ZIP does not itself upgrade a WordPress site or prove that a previously observed Gateway `downstream_rejected` transfer failure is resolved. Direct Bridge and Gateway-to-Bridge real-client acceptance, confidential file delivery and hosting-specific temporary-path exposure remain in separate existing Issues (#134, #173; Gateway #199). Active updater-equipped v0.6.8 installations can discover this published stable release through WordPress Core; updates and optional auto-updates remain under normal WordPress controls.

## 0.6.8

- Advertise only the official stable GitHub Release `wp-ai-bridge.zip` asset through WordPress Core's native `Update URI` / `update_plugins_github.com` mechanism. WordPress Core continues to own update detection cadence, user confirmation, installs, activation and optional auto-update selection; no independent updater, background daemon or new privileged endpoint (#171, PR #179).
- Strictly confine release metadata to the official AChWorks repository and the exact canonical installable release asset, reject drafts/prereleases, source-code archives, unrecognized release tags and cross-origin cache values, and use bounded WordPress safe HTTP with positive/negative caching.
- Add the standard `readme.txt` with accurate feature, installation, update, privacy and third-party disclosures and keep its stable tag synchronized with the main plugin header in the package build. Protect the WordPress compiled Persian localization entrypoint against direct HTTP execution while retaining the full translation catalog.
- Preserve all current administrator-authorized features (including separately gated Source Editing and External Packages) and the existing canonical plugin directory, Namespace, OAuth identities, Workspace state, native capabilities, permissions and installed-Adapter behavior. WordPress.org listing is conditional on acceptance of the unchanged full feature set; no directory-only fork or omission is made.
- Validate the **actual published v0.6.7 ZIP** (SHA-256 `6e0edc1697ab63d10d2556002cf2a1f824b579110e690cf707353b9fe674ad3f`) through Core replacement and preservation of Workspace, OAuth signed-client refresh and administrator grants. Run additional isolated native GitHub metadata and Core bulk-upgrade tests on both WordPress integration lanes.
- **Upgrade path:** Existing v0.6.7 installations do not contain the new update discovery hook and require one normal manual replacement with the first published updater-equipped ZIP. Starting from that version, *active* installations can receive standard WordPress update offers from later stable GitHub Releases. No claim of WordPress.org listing or live-site deployment is made.

## 0.6.7

- Reuse an existing private transfer lease directory already verified as owner-only `0700`, or a private file already verified as `0600`, without requiring a redundant `chmod` that some managed/shared WordPress hosts refuse. Preserve canonical-path, symlink, inode, owner-only permission, stable per-site lease identity and fail-closed checks for unsafe storage (#134, PR #177).
- Extend the existing authenticated site-administrator diagnostic view with **fixed, allowlisted** storage-denial reason codes and narrow native Media temporary-staging error codes. Do not expose file paths, payloads, arbitrary `WP_Error` messages, OAuth secrets, or unrelated WordPress activity (#134, #173; PR #177).
- Add regression checks for already-safe `0700/0600` reuse with denied redundant `chmod`, insecure-mode and symlink rejection, and sanitized diagnostics, with aligned Persian text and troubleshooting guidance.
- Update the published-plugin upgrade acceptance to start from the **actual v0.6.6 GitHub Release ZIP**, pinned to SHA-256 `658cfe36724fc949f3687b4df713b431462df5a9a7bef416b0f6d767f395f6dc`, verifying native Core replacement and preservation of existing Workspace, OAuth and delegated settings.
- **Limitations:** This is a focused compatibility and observability update, not a general new transfer backend or a claim that any specific shared host is repaired. Hosted native Media upload, confidential transfer and AI-client file handoff require real post-install acceptance. No WordPress.org submission or automatic production deployment is part of the GitHub ZIP release.

## 0.6.6

- Reuse the existing filesystem-based confidential transfer journal on ordinary supported WordPress hosting with an automatically selected, validated private PHP/OS temporary location outside known served WordPress roots. Preserve pre-existing v0.6.5 leases, authenticated actor/client/blog binding, verified digests, claims, and no-blind-replay recovery; fail closed when no safe directory exists (#134, PR #170).
- Stage native Media uploads and approved plugin/theme ZIP inputs as owner-only private files (0600) inside a reusable owner-only scratch directory (0700); complete authorized Media and extension operations through the native WordPress Media Library and Core Upgrader, without a second Media manager, installer, database storage backend or operator-managed directory (#173, PR #174; #134, PR #170).
- Keep private WXR producer artifacts and preflight on the validated private location. Retire one-shot native inputs on successful completion and opportunistically reap stale abandoned inputs after 24 hours on a later safe allocation; no wall-clock deletion guarantee or universal CDN/custom webserver-alias safety claim.
- Extend the real WordPress/official MCP Adapter security and portability regressions for native destinations, served temporary roots, restrictive open_basedir, interrupted operations, multisite, and v0.6.5 lease continuity. Pin the actual published v0.6.5 release ZIP and checksum for the next release's Core-native upgrade acceptance.
- Hosted AI-client attachment materialization, Gateway cross-hop delivery, unusual host mapping acceptance, fresh-chat Workspace continuity, long-idle OAuth acceptance, and optional WordPress.org eligibility remain separately tracked; a source merge or GitHub ZIP release does not prove those external outcomes.

## 0.6.5

- Add explicit, identity-bound replacement of an existing plugin or theme from a separately authorized public HTTPS ZIP via the existing native `extension-lifecycle(action=update)` Ability (#168). This preserves WordPress.org installs and ordinary update offers. Replacement requires Code & Extensions plus External Packages, the native install and update capabilities, the exact installed target and preimage version, an approved ZIP SHA-256, an expected package version and affirmative confirmation.
- Refuse exact-target replacement when the installed extension tree contains symlinks or is not safely enumerable, including nested links that could redirect Core's recursive delete outside the extension root; leave that special deployment to administrator-controlled procedures.
- Refuse overwrite when another independently registered plugin shares the authorized plugin's installation directory; WordPress Core replaces whole directories, not individual plugin basenames. Refresh native plugin/theme headers and relevant activation/theme-selection option caches before checking the authoritative preimage under the shared installer lock, rejecting concurrent stale-version downgrades.
- Validate ZIP root and canonical extension header, reject nonmatching or unsafe archive entries and excessive decompressed payloads, and recheck installed identity, grants and shared native-upgrader lock before the WordPress Core overwrite operation. Preserve active state; failed or ambiguous native changes require administrator inspection rather than automatic blind retry. The existing Bridge plugin can update itself when running a sufficiently new installed release that exposes this Ability.
- Extend the isolated real WordPress single-site CI matrix with active plugin, theme, denied/revoked/incorrect-package/replay and active Bridge self-replacement tests, and pin the published-package upgrade proof to v0.6.4. Public release and live-site rollout are separate from source integration and require their respective approvals.

## 0.6.4

- Introduce a narrowly scoped `workspace-delete` Ability for one explicitly identified project-bound private document or task. A current WordPress site administrator and authenticated OAuth client must meet WordPress delete permissions and both Builder Write and Users & Destructive grants, provide exact blog/project/record identity, version and state hash, and confirm the individual permanent deletion. Preserve the canonical-key reservation and return an explicit unknown outcome rather than unsafe retries when native effects cannot be established (#126, PR #166).
- Extend the existing bounded WordPress Activity log with redacted provider-side private-transfer diagnostics and a strictly administrator-authorized, read-only `diagnostics-read` Ability. Diagnostics expose only allowlisted metadata, and cannot observe Gateway or pre-WordPress rejections (#165, PR #166).
- Harden exact Workspace deletion against WordPress native hook and universal `all` callback exceptions across supported WordPress versions. Restore only provably valid dispatchers, track nested native and compensation scopes, and quarantine WordPress hooks before terminal shutdown on unrecoverable dispatcher corruption (#126, PR #166).
- Preserve existing WordPress plugin identity, Workspace records, OAuth approvals and credentials, and deliberate opt-in access groups when replacing published v0.6.3 with v0.6.4; installation and real-site transfer/fresh-chat acceptance remain separate (#126, #134, #165).

## 0.6.3

- Display the Task inspector's **raw Notes body** as an escaped, whitespace-preserving LTR/left-aligned text region, consistent with the inspected Document Markdown body. The Task heading, Project ID, Goal, Notes label, Dashboard and mixed-language tables continue to inherit the localized WordPress admin direction (#157).
- Extend real WordPress administrator regressions with multiline English/Persian Notes and escaped HTML; preserve stored Workspace data, grants, OAuth, and original plugin identity. Upgrade smoke now starts from the actual SHA-256-pinned published v0.6.2 ZIP.

- Provide administrator-delegated, bounded native WP-Cron event inspection and trusted installed-provider profile schedule/reschedule/unschedule operations, with exact event identity, stale-state/concurrency protection, and protected grants; targeted `run_now` is available only for explicitly opted-in installed-provider runners with durable once-only business-effect claims and truthful uncertain outcomes. No generic callback executor or uncontrolled run-all action (#138, PR #144).

- Add purpose-bound authenticated Source Editing snapshots and verified private transfer preview/apply for multi-MiB source files across bounded MCP windows; preserve atomic source CAS/recovery and apply a conservative PHP token/parser memory preflight before syntax validation (#147).

- Introduce independent native WordPress administrator controls for bounded installed extension source editing, registered setting values and public schemas, and read-only Ability catalog JSON responses, preserving existing defaults, grants, authorization, source CAS and durable recovery when a limit is lowered (#147).

- Allow separate administrator tuning of the finite high-trust registered REST JSON input budget, retaining its original 16 KiB default, independently clamped to existing incoming MCP request capacity and native Core/provider authorization, with real Adapter/WordPress tests (#147).

- Allow a WordPress administrator to configure finite, per-site public HTTPS plugin/theme package download capacity separately from WordPress host upload limits and the protected Code & Extensions / External Packages grants, retaining the historical 100 MiB default and strict bounded streaming/cleanup behavior (#147).

- Preserve empty-string PHP source-edit preview/apply semantics for both inline and verified private transfer (including zero-byte leases), without allocating parser tokens or weakening the existing memory, authorization, CAS/recovery and no-replay protections (#147, PR #163).

- Preserve the existing canonical WordPress plugin identity, Workspace data, OAuth clients and grants across an ordinary published v0.6.2 → v0.6.3 upgrade; new high-trust operations require deliberate administrator grants and native capabilities, and there is no automatic activation of Cron mutation or Source Editing. Installed-site/browser and hosted-client acceptance remain separate (#92, #126, #134, #138, #147, #157).

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
