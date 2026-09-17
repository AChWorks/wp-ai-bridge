# WP AI Bridge - Master Specification

Status: Canonical project specification

Canonical repository: `ach1992/wp-ai-bridge`

Companion project: `ach1992/wp-native-builder`

## How to use this specification

This is the root, normative specification for the project. It defines the product's purpose, required coverage, durable architecture, detailed cross-cutting requirements, constraints, non-goals, and success criteria. It must remain understandable and authoritative without any particular Issue, pull request, release, site, provider, or previous conversation.

Requirements flow from this specification to the detailed documentation and then to implementation work, Issues, acceptance criteria, and tests. Issues and pull requests organize delivery; they do not own or narrow the product's purpose. A task's exclusions are that task's scope, not permanent product exclusions. Accepted changes to project intent must be recorded here first, then reconciled into affected downstream work without overwriting concurrent valid work.

Code, tests, and current public documentation establish what is actually implemented; CI establishes validation; immutable tags and releases establish what is published. Missing implementation is a conformance gap against this specification, not grounds to silently redefine the goal. This document is not a live task log or a claim that all required capabilities already ship.

Historical documents under `docs/maintainer/reference/` are design evidence only. They cannot override this specification or substitute for current implementation evidence.

## 1. Purpose

WP AI Bridge is a small, free, self-hosted WordPress plugin whose goal is to make the full range of administration available to a real WordPress administrator discoverable and delegable to an AI through the Bridge. The canonical WordPress plugin installation identity is `wp-ai-bridge/wp-ai-bridge.php`; maintained PHP, localization, Ability, admin, OAuth/MCP, and Bridge-owned storage identifiers use the WP AI Bridge identity. The bounded pre-canonical Workspace migration is preserved only in the immutable v0.4.0 release; current maintained runtime beginning with v0.4.1 does not read or depend on pre-0.4.0 product or storage identifiers.

The intended experience is an additional WordPress administrator whose effective access the principal site administrator can increase, reduce, or revoke in WordPress settings. The AI must be able to discover the operations available on the actual installation, understand their inputs and required permissions, and perform the operations the administrator has delegated. Broad administrator-equivalent access and narrower grants must use the same simple access model.

The product is not limited to site building, selected plugins/themes, public content types, known metadata keys, or previously anticipated site requests. Any legitimate administrative operation available on the installation is within the coverage goal. Do not deliberately make an operation permanently unavailable merely because the administrator might choose not to enable it by default.

Administrator parity means the authority WordPress actually permits for the authenticated principal and target installation. It does not create operating-system root access, override hosting policy, turn a multisite site administrator into a Super Admin, or grant authority the delegating administrator does not possess.

The plugin is the WordPress-side integration layer. The companion project may orchestrate workflows, but this repository owns its product and must remain usable and recoverable independently of that companion and of chat history.

## 2. Architecture and simplicity

Use the existing WordPress administration and extension systems rather than building a parallel administration platform.

```text
AI / MCP client
      |
      v
Bridge authentication + administrator delegation policy
      |
      v
Official MCP Adapter + WordPress Abilities
      |
      +-- compatible registered Core/provider operations
      +-- thin typed fallbacks using WordPress/provider APIs
      |
      v
The actual WordPress installation and its permission checks
```

Reuse the Bridge's existing authentication, settings, permission service, discovery, and execution paths. Prefer one coherent policy boundary and a small reusable execution/discovery layer over separate permission engines or adapters for each provider. A generic layer must still execute an identified supported operation; it is not a raw function-call or arbitrary command endpoint.

For an operation, prefer in order:

1. discover and reuse a suitable registered Core/provider Ability with its real schema and permission callback;
2. where no suitable Ability exists, reuse a supported WordPress/provider public API, including a registered REST contract when appropriate, preserving its validation, authorization, and lifecycle;
3. add a thin typed fallback only for coverage or normalization that the existing contract cannot supply;
4. when public APIs cannot provide a required integrity/concurrency guarantee, use the smallest fixed-purpose internal persistence primitive bound to the already-authorized object/data model, with no caller-selected SQL/table/column/query/command fragments, focused tests, static confinement, and high-assurance review;
5. report a specific implementation or upstream-contract gap when no usable path exists, rather than inventing an API or disguising missing code as a disabled permission.

A new compatible registered operation or a new target within an existing generic contract must not require a provider-specific Bridge source edit merely to be discovered and used after authorization. Prefer current WordPress registrations and supported schemas over hardcoded lists of plugin names, themes, object types, taxonomies, metadata keys, option names, or download origins.

Discoverability is not execution consent. Unknown side effects or insufficient authorization/schema information must be reported and classified before execution; do not infer permission from an operation's name, prose description, or a provider's read-only annotation alone. Do not bypass an explicit provider denial by selecting a lower-level data route.

Keep implementation proportional: no duplicate registry, custom policy language, background daemon, extra database, adapter per plugin, hundreds of permission switches, or helper-plugin bundle merely for anticipated completeness. Anticipate the capability families and extensible authorization contract now; implement missing operations in coherent tested increments rather than a speculative all-purpose execution framework.

## 3. Supported platform baseline

- WordPress 6.9+ for the Core Abilities API, with actual compatibility tested against the supported baseline and current WordPress.
- Official `WordPress/mcp-adapter` installed and active for MCP exposure in the supported architecture.
- This Bridge installed and active; HTTPS for remote connections.
- Current development and CI baseline includes PHP 8.4. Minimum PHP support follows the tested intersection with WordPress and MCP Adapter, not an unverified compatibility promise.
- No SaaS bridge, additional MCP server, external database, daemon, or queue is required by default.
- Installed site plugins and themes are optional sources of additional operations, not a fixed supported-provider list or mandatory Bridge dependencies.

Do not bundle a private MCP Adapter. Detect missing dependencies and report the actual administrator action required. Version-sensitive contracts must be checked against the supported runtimes before adoption.

## 4. Design principles

1. **Administrator parity:** full legitimate WordPress administration is the goal, not a fixed small tool inventory.
2. **Administrator choice:** implement useful capabilities and let the administrator control delegation; default-off is not unsupported-by-design.
3. **WordPress authority:** retain real user, object, provider, site/network, and deployment authorization.
4. **Dynamic discovery:** identify the actual installation's operations and requirements without assuming a particular stack.
5. **Generic coverage:** do not require new Bridge code for every valid target of an already-supported generic operation.
6. **Reuse before duplication:** prefer supported registered contracts and thin fallbacks.
7. **Simple implementation:** share existing policy and execution mechanisms; introduce a new subsystem only when concrete requirements justify it.
8. **Safe integrity:** protect target identity, current state, persistence, and recovery rather than removing whole administrative families.
9. **Honest availability:** distinguish implemented, disabled, denied, missing, and environment-blocked operations.
10. **No hidden privilege expansion:** preserve existing grants and require explicit consent for materially new elevated authority.
11. **Small footprint:** keep the baseline to the official Adapter and this Bridge.
12. **Specification-led delivery:** downstream documentation, work items, code, and tests must implement this specification rather than redefine it.

## 5. Delegation, security, and trust

Effective access is the intersection of the authenticated WordPress principal's authority, the principal administrator's Bridge delegation, and the target/provider/environment's applicable policy. A Bridge setting does not grant a missing WordPress capability.

The administrator must be able to delegate broad administration or a smaller subset, inspect what is granted, and revoke access. Recheck effective authority for each execution and each overwrite-sensitive target; cached discovery or a previously successful request is not continuing authorization.

Separate three concerns:

- **Delegation policy:** which legitimate operations the administrator permits the AI to use. This is configurable, not a developer-imposed product ceiling.
- **WordPress/environment policy:** actual capability mapping, provider authorization, multisite authority, filesystem permissions, and deployment restrictions. Report these boundaries accurately instead of silently bypassing them.
- **Integrity and privacy:** authentication, validation, correct target resolution, stale-state protection, safe networking/file handling, resource control, and minimal logs. These protect an authorized operation; they are not substitutes for implementing it.

Use capabilities appropriate to the exact action and target, not one hardcoded `manage_options` or role-name check for all operations. Preserve registered Core/provider authorization and additional mapped capability requirements. Generic routes must not be back doors around a more specific denial.

Ordinary content, metadata, settings, REST, and provider execution paths must not silently expand their own Bridge delegation or bypass disabled elevated authority. Managing delegation itself requires the principal administrator's explicit authorization and must not be smuggled through an ordinary option update. Use WordPress's existing identity/capability system rather than inventing a separate AI superuser.

Executable code is an elevated trust boundary. Authorized plugin/theme source changes, snippets, or executable package installation run with the WordPress runtime's authority and can affect application data and policy. Access groups and input validation are not a sandbox for intentionally authorized PHP. Explain this in settings and security documentation. File rollback does not transactionally reverse already-executed code side effects.

Do not use a raw PHP evaluator, arbitrary SQL/shell endpoint, unrestricted server-file API, unchecked HTTP proxy, or indiscriminate credential dump as a shortcut to administrator parity. This is a restriction on unsafe generic mechanisms, not a permanent prohibition on the corresponding legitimate WordPress administrative workflows. Provide those workflows through explicit supported operations and appropriate delegation instead.

Options, user/comment/term/post metadata, package sources, and installed-extension editing are not categorically out of scope. Their ordinary generic routes must preserve relevant semantic, authorization, and sensitive-data boundaries. Security/credential/session-like keys remain excluded from ordinary generic metadata and broad inspection. Legitimate account, authentication, or provider-secret management uses purpose-specific authorized lifecycle operations with redacted ordinary output; any necessary sensitive disclosure requires explicit elevated authorization and must never enter logs. Never claim WordPress can reveal an existing plaintext password it does not expose.

Resource controls must remain effective without becoming arbitrary permanent workflow ceilings. Prefer WordPress/hosting limits, streaming, pagination, batching, and administrator-configurable policy. Explain actual hard runtime limits and provide a supported alternative when feasible rather than requiring a Bridge patch merely to change a product-policy limit.

## 6. Access settings and defaults

Keep a small grouped permission model, reusing existing settings and WordPress capabilities. Add a narrowly justified subpermission or group only when a materially different trust boundary needs separate consent, not one switch per tool, provider, or metadata key.

The administrator-facing model must support broad administrator-equivalent delegation and reduced grants, with clear effects and revocation. Powerful/write/destructive/code/network additions remain disabled until explicitly enabled. The current low-risk Site Read default is not permission for broad private-data disclosure.

The existing groups provide a starting structure, not a maximum capability list:

| Group | Intended scope | Default |
| --- | --- | --- |
| Site Read | permitted environment and compact object/capability inspection | Enabled |
| Builder Write | content, blocks, media, taxonomies, navigation, and Workspace authoring | Disabled |
| Live Content | publishing and live-content/status changes | Disabled |
| Site Configuration | authorized WordPress, theme, and provider configuration | Disabled |
| Advanced Metadata | authorized generic object metadata; deletion also needs destructive access | Disabled |
| Code & Extensions | authorized executable-code and extension lifecycle workflows | Disabled |
| Users & Destructive | user/role administration and destructive operations | Disabled |

The exact implemented settings inventory belongs in current product documentation. New materially elevated surfaces, such as source editing or remote import, need explicit intended consent even on an upgrade where an older adjacent group was already enabled. Do not silently widen old consent, but do not reset valid grants or require new provider-specific switches when an unchanged approved generic contract gains another legitimate target.

Settings changes require the appropriate WordPress administration capability, normally `manage_options` at site scope and the relevant network authority for network policy. Show what a setting enables, its material risks, and any remaining upstream denial. A setting must never advertise working support for an operation that has no implementation.

## 7. Discovery and operation contracts

The AI must be able to ask what it can do on this installation without guessing tool names or dumping the entire site. Reuse native registry/discovery information and existing Bridge diagnostics with bounded, searchable or paginated summaries and on-demand detail.

Discovery must make the following information available where authorized:

- exact operation identity and provider, purpose, real input/output schema, and target requirements;
- required Bridge delegation and WordPress/provider authority, including target-dependent checks that cannot be decided without input;
- material side effects such as live change, deletion, executable code, external requests, or sensitive data;
- whether the operation is usable, implemented but disabled, denied for the current principal/target, missing from the Bridge, or unavailable because of an upstream/environment condition;
- an actionable reason and the relevant administrator setting or external prerequisite when execution is unavailable.

Discovery must not leak secret values, private object contents, source code, or full metadata values through broad lists. A registered operation's existence does not establish current permission; execution still applies the full contract.

Bridge-owned Abilities use `wp-ai-bridge/<ability-name>`. Each requires a precise description, closed input schema, output schema where practical, a permission callback, deterministic errors, compact results, and no hidden effects beyond the declared operation. Reused provider contracts retain their own namespaces, schemas, permissions, and lifecycle rather than being needlessly cloned.

A caller chooses a discovered supported operation and valid inputs, not arbitrary PHP callable names, SQL fragments, server paths, or unaudited remote URLs. Unknown or private third-party UI behavior must be reported as an implementation gap requiring a verified execution path; do not pretend that discovery or a permission checkbox alone implements it.

## 8. Required administrative coverage

Plan coverage across the complete WordPress administration surface from the start. The families below are a coverage baseline, not an exhaustive allowlist or a claim of current implementation. The actual installation and administrator's authority determine applicable operations; an unlisted legitimate family remains within the product goal.

| Family | Required coverage direction |
| --- | --- |
| Installation and discovery | environment, site/network identity, health, installed providers, effective access, and available operations |
| Content | posts, pages, custom post types, revisions, statuses, scheduling, publication, and authorized private/internal content through its correct lifecycle |
| Media and files | Media Library discovery, upload, URL import/download into the intended WordPress workflow, metadata, attachment relationships, and deletion |
| Taxonomies and object metadata | categories, tags, custom taxonomies, exact terms, and post/term/user/comment metadata with the appropriate object-specific contracts |
| Appearance | menus, navigation, widgets, blocks, patterns, templates, template parts, site styles, theme configuration, and equivalent provider-managed presentation |
| Settings | site, network, theme, and provider configuration, using registered schemas/public APIs where available and explicit semantics for unregistered settings |
| Extensions and source | plugin/theme discovery, installation, activation, updates, removal, authorized package sources, managed snippets, and installed-extension source editing/recovery |
| Users and access | accounts, profiles, roles/capabilities, memberships, sessions, and authentication lifecycle within the actual delegable WordPress authority |
| Comments | discovery, moderation, replies, status changes, and deletion |
| Tools and maintenance | supported import/export, site health, updates, scheduled work, cache/maintenance operations, and backup/restore when WordPress or an installed provider supplies that workflow |
| Provider administration | installed-provider business operations, including forms, fields, commerce, SEO, and other administration; not limited to named example plugins |
| Multisite | site and network administration only under the corresponding actual site/Super Admin capabilities |
| Persistent Workspace | private project documents, tasks, state, activity, export, and explicitly authorized lifecycle operations |

Use the correct owning API for the operation. Generic storage mutation is not a shortcut around business validation, commerce/order storage, role mapping, settings sanitization, or provider lifecycle. An undocumented provider-private table requires investigation, not automatic database access.

An implementation increment may cover fewer families, but its scope cannot redefine the product ceiling. Track verified implementation gaps in downstream work derived from this specification. Do not mark overall administrator parity complete merely because the current tool list or one work item is complete.

## 9. Content, metadata, and concurrency

Generic authoring uses an eligibility predicate suitable for the action, not a permanent ban on administrating other legitimate WordPress objects. Objects needing a different lifecycle must use the corresponding operation. Private Bridge Workspace/authentication storage stays behind its own contracts, not ordinary content or metadata routes.

Advanced Metadata is an explicitly delegated generic surface. Do not use provider, post-type, taxonomy, or meta-key allowlists that require Bridge development for each legitimate target. Validate the real object and its exact authority; a term target includes its verified taxonomy, and user/comment targets have their own distinct authorization semantics.

For protected/private unregistered metadata, an explicit Advanced Metadata grant may use the target's appropriate native authority when the only generic denial is the protected-key default. Do not treat that rule as permission to bypass explicit registered/Core/provider authorization or additional mapped capabilities. For post metadata, exact target `edit_post` authority remains required; do not mechanically copy post semantics to other object types. Security-like keys remain outside ordinary generic metadata.

Broad metadata inspection returns bounded authorized key/state summaries. Require an exact key before returning its value. Mutation identity must describe actual physical stored rows and raw stored value, not merely registered defaults or a filtered virtual view. Ambiguous multi-row data and values containing PHP objects/resources or other loss that the JSON contract cannot represent must fail closed. Deletion also requires destructive delegation.

For an existing single-row value, update/delete must bind to the exact inspected physical row so a concurrent identical-value row is not collateral. Prefer supported metadata APIs; where they cannot supply the required conditional single-row guarantee, use the smallest fixed-purpose store for that exact metadata model. It may perform only prepared/fixed-schema identity/value operations after authorization, must preserve relevant sanitization, hooks, cache invalidation and lifecycle, and must remain statically confined and independently reviewed. It is not a general database abstraction.

Absent-state creation must detect interference crossing a non-atomic uniqueness window and compensate only the row created by that invocation. Compensation must never overwrite or delete a newer unrelated state. Preserve the existing post-metadata guarantees while extending another object family.

Full-content, block, settings, file, and Workspace updates likewise need current identity appropriate to their overwrite risk. Reject stale state, preserve unrelated edits, verify persistence, and make partial failure/recovery explicit. Reuse native revisions where available without treating revision history as the sole authoritative current state.

## 10. Media, packages, and source editing

Media workflows use WordPress Media Library, MIME, upload, and attachment handling. Callers may supply bytes, a filename, or a source URL for the explicit import workflow, not an arbitrary destination on the server. Use bounded streaming and the applicable WordPress/hosting and administrator-configured limits; do not make a hardcoded Bridge payload ceiling the permanent product policy.

URL import is required, not an optional provider-specific exception. Validate destinations and redirects, apply safe HTTP handling, enforce resource bounds and permitted file types, and clean up owned temporary files. Public origins must not need a hardcoded per-origin adapter. Additional destination/network policy belongs to explicit administrator configuration and the validated workflow, never an unchecked HTTP proxy or implicit access to internal services.

Media import must not install executable packages. Plugin/theme package installation is its own explicitly authorized operation using WordPress/provider installer and lifecycle APIs. WordPress.org, a provider, an uploaded package, or an administrator-selected package URL must not be categorically excluded merely by source; enforce actual target/package validation, provenance/integrity evidence available for that source, current capabilities/deployment policy, and recovery controls. This requirement does not assert every package source is already supported.

Installed plugin/theme source editing requires actual discovery, authorized read, change preview, apply, and recovery. Discover targets from the installed-extension inventory and WordPress editable-file contracts rather than a Bridge provider allowlist. Honor current `edit_plugins`/`edit_themes` authority, multisite rules, `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, and actual filesystem access.

Resolve the exact installed extension and relative file, verify real-path containment, and reject traversal, symlink escapes, unrelated credential/configuration files, and arbitrary server paths. Bind writes to current bytes/hash and intended candidate, verify persistence, and keep an exact preimage in bounded private recovery storage. Recovery must not clobber concurrent edits. Reuse supported editor/lifecycle APIs where suitable and test their actual PHP validation, active/inactive/network-active, authenticated loopback, cache, and failure behavior rather than assuming guarantees.

Recommend hooks, custom plugins, and child themes for routine customization because updates can replace vendor/parent-theme edits; this is guidance, not a permanent prohibition on an administrator-authorized source edit. Control-plane components, including the Bridge and Adapter, require a connection-loss warning and a proven recovery mechanism, not a hidden provider blacklist. Source/diff disclosure requires elevated source access and never belongs in ordinary lists or mutation logs. Executable-code consent is not a sandbox or transactional rollback of code side effects.

## 11. Persistent Workspace

Persistent Workspace provides durable project continuity inside WordPress, independent of conversation history.

- Store documents and tasks in private Bridge-owned object types, excluded from generic content, Gutenberg, and metadata routes.
- Provide their administration through the Workspace's own typed contracts; isolation must not mean permanently inaccessible to the authorized administrator.
- Keep current state authoritative even when WordPress revisions are disabled or pruned.
- Require version/state identity and stale-write rejection for updates.
- Expose compact resume/orientation, documents, tasks, export, and explicit destructive lifecycle operations.
- Reuse Dashboard, Documents, Tasks, Activity, and Settings views without a parallel project-management database.
- Preserve durable content on uninstall unless an explicitly authorized destructive lifecycle action clears it.

## 12. Optional providers

No plugin or theme defines the outer scope of the product. Provider integrations are optional implementations of the same administrator-coverage goal and resolution order.

Prefer compatible native Abilities; otherwise use supported public APIs. Isolate necessary fallbacks so upstream equivalents can replace them. Existing Astra, Gravity Forms, Code Snippets, WooCommerce, or other examples are not a supported-provider allowlist.

A form's fields and submissions, a commerce system's orders/customers/settings, a theme's presentation, or a code provider's managed snippets require the real provider lifecycle and permissions. Do not assume generic post metadata substitutes for provider-specific storage or business operations. Do not run submitted snippet code directly in Bridge code as a substitute for the provider lifecycle.

When a public contract is insufficient, report the precise gap and implement only the necessary verified adapter. Do not demand another helper plugin or a Bridge edit when an already-compatible registered contract is sufficient.

## 13. Authentication and MCP transport

Use WordPress-backed authentication/delegation and HTTPS MCP with the official Adapter. Bind each connection to the intended installation and WordPress principal. Reuse current authentication and capability machinery; a broad grant is explicit, never an unauthenticated AI administrator.

Verify version-sensitive client and Adapter requirements before modifying transport. Do not add a proxy, tunnel, independent identity platform, or second MCP stack merely for completeness. Connection/delegation revocation and relevant policy changes must affect subsequent execution rather than only a cached discovery response.

## 14. Logging and privacy

Use bounded metadata-oriented activity logs: operation identity, actor, target identity when appropriate, outcome, and a safe error code. Never log credentials, bearer tokens, passwords, source/diffs, metadata keys/values, file bodies, signed URLs, arbitrary content bodies, or complete request/response payloads.

Return only information necessary for the authorized operation. Separate broad discovery from exact private-data reads. Normalize errors that could contain credentials, paths, or signed URLs without hiding whether an operation succeeded, failed, conflicted, or needs recovery.

## 15. Compatibility and evolution

- Preserve native WordPress behavior and provider contracts; version-check upstream APIs.
- Do not weaken an accepted security/concurrency guarantee to add a capability quickly or obtain green tests.
- Keep public contract changes deliberate and documented; do not silently broaden old grants.
- Remove artificial provider/object/key/origin restrictions through generic validated contracts and administrator policy, not by deleting authorization checks.
- Treat broader targets of a supported generic contract differently from materially new risky operations requiring consent.
- Prefer extending existing mechanisms over a new framework, store, service, or integration dependency.
- Public docs describe implemented behavior; this specification defines required product behavior. Record and close gaps without misrepresenting either.

## 16. Validation and delivery discipline

Use proportional evidence for each implementation: strict Composer validation, PHP syntax, functional/unit and regression tests, WordPress Coding Standards, compatibility checks, Persian localization, static confinement, both supported real-WordPress integration lanes, actual MCP discovery/execution, and validated release packaging where relevant.

Coverage must prove fresh-install and upgrade defaults, administrator enable/reduce/revoke behavior, exact target authority, provider-denial preservation, honest discovery status, provider-neutral operation on custom targets, and relevant malformed-input, stale-write, concurrent mutation, cleanup, recovery, resource, privacy, and destructive/code boundaries. Self-review is not independent review; material high-risk surfaces need a genuinely independent exact-candidate review.

Identify candidates by exact commits and keep validation/review current to the target and effective change. Publishing a specification or integrating code does not itself enable site permissions, deploy, publish a release, or authorize a high-risk operation. Apply the applicable action/approval gates separately.

Published tags are immutable. Build and verify release artifacts from the exact release candidate; never force-move a public tag. Keep maintainer/reference material repository-only unless packaging requirements explicitly change.

## 17. Documentation and source ownership

This specification owns project intent and normative requirements. Detailed architecture/security/operation documents explain and refine those requirements without contradicting them. Public README and usage docs accurately describe the implemented product. Issues and pull requests derive executable scope and acceptance from these sources and own current work state, not the product's goal.

Do not put Issue numbers, active branch/candidate identities, handoffs, resolved task narratives, or implementation schedules in this specification. Do not add parallel Master-state, checkpoint, or chat-history ledgers. Current Git/GitHub/CI/release evidence owns live state; historical references retain only useful superseded rationale.

Before accepting an implementation contract or major design change, verify conformance to the full-administrator coverage goal, configurable delegation, native authorization, honest discovery, and simplicity requirements. A local scope exclusion or missing API is not a permanent product ban. Update this root specification for an accepted product-level change before deriving affected downstream work; do not reconstruct project purpose from an Issue.

## 18. Non-goals

The Bridge is not an operating-system administrator, unrestricted remote shell/database console, indiscriminate secrets extractor, new identity platform, SaaS control plane, duplicate provider-Ability catalog implementation, helper-plugin bundle, or chat-dependent management archive.

These exclusions describe unrelated infrastructure or unsafe shortcut mechanisms. They do not exclude legitimate WordPress administrator workflows, sensitive-but-authorized administration, generic settings/object families, administrator-selected package sources, or a particular plugin/theme merely because implementing its supported workflow needs care.

## 19. Success model

The product succeeds when an administrator can connect an AI to a supported WordPress installation, discover its actual administrative operations and prerequisites, delegate broad administrator-equivalent access or a smaller subset from simple settings, and have the AI execute that scope through the correct WordPress/provider contracts with verifiable outcomes and recovery where needed.

The administrator can reduce or revoke delegation without editing Bridge source. Compatible new providers and legitimate new targets of generic contracts do not require Bridge patches merely because their names or keys were not anticipated. Powerful access is never enabled silently, and full delegation still respects native WordPress, provider, network, and hosting authority.

Coverage is measured against actual legitimate administration on the installation, not the number of shipped tools. Missing operations remain visible implementation gaps until addressed; partial delivery must not be described as full administrator parity.

A future maintainer can derive the project's purpose and requirements from this file alone, then locate detailed documentation, current code/tests, active Issues/PRs, exact validation, and published state through the repository without any prior conversation. Persistent Workspace supports the same continuity for administration projects inside WordPress.
