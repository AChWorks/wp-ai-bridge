# Architecture

## Normative source and implementation boundary

[`MASTER-SPEC.md`](../MASTER-SPEC.md) defines the product goal: full legitimate WordPress administration, discoverable and delegable through simple administrator-controlled settings. This document refines that specification and describes the actual component boundaries and outstanding coverage. Neither the current tool inventory nor a task's exclusions are a permanent product ceiling.

The architecture is deliberately small. Reuse WordPress identity/capabilities, registered Abilities, the official MCP Adapter, the existing Bridge settings and permission service, and thin public-API fallbacks. Do not create a second registry, policy language, per-provider permission engine, generic execution framework, or helper-plugin bundle. Bridge is a credential-like, scoped **connection and data-exchange boundary**; it must not reimplement feature-plugin business logic. Persistent Workspace is the intentional small Bridge-owned project-continuity exception, not a separate site-builder.

## Runtime ownership

```text
AI / MCP client
  -> authenticated Bridge HTTPS endpoint
  -> official MCP Adapter transport and discovery/execution tools
  -> WordPress Abilities registry
       -> Core/provider-owned public contracts
       -> Bridge-owned typed fallback contracts
  -> the operation's actual WordPress/provider API and authorization
```

| Responsibility | Existing owner | Boundary |
| --- | --- | --- |
| Connection identity and revocation | `src/Auth/class-oauth-server.php`, `class-oauth-store.php` | WordPress-backed OAuth, resource/client binding, current WordPress principal; no separate AI superuser. |
| Native registry and invocation | WordPress Abilities API and official MCP Adapter | Native schemas, permission callbacks and lifecycle remain authoritative. |
| Exposure compatibility and reuse | `src/Abilities/class-ability-resolver.php` | Explicit MCP opt-out takes precedence over general public metadata. |
| Paginated public contract inspection | `src/Abilities/class-ability-catalog-abilities.php` | Read-only list/detail; no operation or target permission callback is invoked. |
| Bridge delegation settings | `src/Support/class-settings.php`, `class-permissions.php`, `class-native-ability-delegation.php` | Small default-off groups plus actual WordPress/provider authority, checked at execution. |
| Typed administration | Existing providers under `src/Abilities/` | Object-specific inputs, capabilities, lifecycle, error and integrity behavior. |
| Exact metadata persistence | `src/Support/class-post-meta-store.php`, `class-term-meta-store.php` | Fixed-purpose, fixed-schema row identity/CAS; not a generic database API. |
| Persistent Workspace | `src/Workspace/class-store.php`, Workspace abilities and admin screens | Private native storage, version/hash concurrency, dedicated administration. |
| Activity | `src/Support/class-mutation-log.php` | Bounded identity/outcome metadata, never request bodies or secrets. |

Production requires the official Adapter and this Bridge, not Composer, Docker, Node.js, a daemon, another database, or an external identity platform. Build and integration tooling remain development-only.

## AI clients versus WordPress providers

A **WordPress operation provider** is Core, a plugin or a theme registering operations. Its Abilities are discovered generically at runtime under WordPress and provider permission checks. An **AI/MCP client** is the external caller (ChatGPT, an independently operated Gateway, or another future compatible AI application) authenticated to this WordPress installation through the existing Bridge OAuth/MCP contract.

Multiple approved clients must share the same administrator delegation and WordPress execution boundary, while keeping distinct client identities, grants and revocation. The existing built-in ChatGPT compatibility branch is intentional: do not rename its persisted token/client identity or assume other applications can authenticate as ChatGPT. Client-specific compatibility must be verified against its actual protocol; the Bridge is not a chat/model API proxy.

See [Approved OAuth clients](./OAUTH-CLIENTS.md) for the currently implemented additional-client contract and `MASTER-SPEC.md` for future AI-client neutrality as a product requirement.

Direct AI-to-Bridge and AI-to-Gateway-to-Bridge remain independent supported topologies. The Gateway is its own approved OAuth client and cannot borrow the built-in ChatGPT identity; its user/Target scope further **narrows**, never widens, the WordPress principal and Bridge grants. Small bounded native Ability results may be routed through the existing Gateway discovery/read/execute contract. Separate file/binary transfer and new negotiated protocols require a Gateway-side capability and actual end-to-end proof; Gateway connector code belongs to `AChWorks/mcp-gateway`, not this repository.

## Discovery and reuse

Use the native registry as the one operation inventory. Prefer a suitable Core/provider Ability with its real public contract. Otherwise use a supported public WordPress/provider API, including an appropriate registered REST contract, through the smallest typed fallback needed for the actual gap. Only a proven public contract justifies a provider-specific fallback. Keep such fallbacks removable when upstream publishes a suitable native Ability.

Do not infer execution compatibility or authority from names, descriptions, category names, or read-only annotations. A provider explicitly hiding its native Ability is not permission to expose an equivalent lower-level fallback. Unknown/private business behavior needs a verified implementation path, not guessed storage mutation.

`bridge-info` reports dependencies and enabled Bridge groups. `site-context` preserves exact small-site output; on large/provider-heavy sites it supplies a bounded current-principal capability summary with explicit omissions and an optional section/offset/limit interface for safe pages beyond the former first-50 provider hints. `abilities-read` supplements it with sorted, filtered, paginated public Core/Bridge/provider contracts and exact named schema reads. It excludes non-public and explicitly MCP-hidden contracts and does not return arbitrary provider metadata. The shared resolver matches the pinned Adapter: malformed MCP metadata is denied, explicit non-null MCP public flags take precedence, and an inherited general public flag must be exactly boolean true.

Contract inspection always reports `execution_permission: not_evaluated`. A schema is not permission, and a target-specific provider callback cannot safely be evaluated without its real valid input. It also reports `bridge_delegation`: Bridge-owned operations use their ability-specific policy while non-Bridge registered operations require `native_abilities` when executed through the Bridge. Native permission checks still run when the operation is executed. Bounded errors replace oversized/unrepresentable inspection output; schemas are never silently truncated.

The broader specification also requires actionable effect/availability diagnostics. Public contract inspection does not invent arbitrary provider effects or complete input-dependent availability from provider prose/annotations. Exact schemas, delegation requirements and provider contracts remain discoverable; a concrete operation that cannot expose the additional diagnostics through an authoritative contract is reported as a contract/upstream limitation rather than guessed by the Bridge.

## Registered WordPress REST access: published, guarded; execution evidence is scoped

WP AI Bridge **v0.5.1 publishes** two distinct registered-REST Abilities: `wp-ai-bridge/rest-routes-read` for filtered, bounded public route/method discovery (#119 / PR #120), and `wp-ai-bridge/rest-route-invoke` for guarded, local native execution (#119 / PR #121). A read-only authenticated audit of a deployed v0.5.1 site verified both registrations and paged indexed REST discovery; **no generic production REST handler was executed**, and broad provider/interactive OAuth acceptance is not thereby proven. Check each target site's actual installed build, grants and current principal rather than inheriting this dated evidence. Discovery requires a separately default-off Registered REST Discovery grant and native `manage_options`; its summaries and details do not evaluate provider permission callbacks.

Invocation requires a separate default-off **High-Trust Registered REST Invocation** grant, native `manage_options`, and every protected Bridge consent/native capability in the current all-protected-effects policy. The implementation deliberately does **not** infer that GET or provider annotations are safe. It verifies the exact indexed registered route, method and concrete local path, rejects hidden/ambiguous routes and excluded Bridge/authentication/credentials/batch/Core-administration control planes, and dispatches only through WordPress's native REST lifecycle with the current WordPress user. Provider permission callbacks, validation and side effects remain authoritative. The result is bounded JSON with an explicit unknown outcome after uncertain dispatch, **never a blind retry**. It is neither an arbitrary HTTP proxy, filesystem/SQL/PHP executor nor generic binary transfer. See [guarded REST execution](./ABILITIES.md#guarded-registered-rest-execution-published-119).

Provider-heavy performance, full interactive OAuth/PKCE onboarding for each distinct client, and real-world route/provider execution still require evidence for those *specific* claims; publication and the current deployed-site **discovery** are already verified. See [#119](https://github.com/AChWorks/wp-ai-bridge/issues/119). An installed plugin's REST registration and WordPress principal authority alone do not enable Bridge access; Gateway effect classification and binary interoperability require separate evidence in Gateway #130. Browser-only wp-admin actions need an actual owning API/Ability or independent browser tool, not an invented Bridge route.

## Delegation boundary

Bridge-owned operations enforce their documented precise access groups and native capabilities. Registered non-Bridge Core/provider Abilities invoked through the exact WP AI Bridge MCP routes additionally require the default-off **Native Abilities** group and then still must pass the target's original WordPress/provider permission callback. Native Abilities is deliberately coarse broad trust because the Bridge does not infer safe effect classes from provider annotations, descriptions, names, or categories.

`src/Support/class-native-ability-delegation.php` owns this boundary:

- the public WordPress `rest_endpoints` filter wraps only the canonical and retained legacy Bridge MCP callbacks with balanced request context and unconditional cleanup;
- only the Adapter's `mcp-adapter/execute-ability` permission callback is layered with Bridge policy during Ability registration;
- each Bridge provider returns only the exact successful Ability objects returned by its own Core `wp_register_ability()` calls; `Registrar` forwards those direct return values to the injected delegation instance's private object-identity provenance set, so re-entrant provider registrations and filter timing are not ownership signals;
- the provenance set stores only those Bridge-owned live object identities. Namespaces, metadata, annotations, custom getters, custom `ability_class` implementations and later same-name replacements cannot grant or inherit Bridge-owned treatment. It is not a second Ability registry, contains no provider contracts/schemas/callbacks, performs no execution dispatch, and leaves WordPress as the sole operation registry;
- the current `native_abilities` setting is read at execution time, so subsequent calls observe revocation immediately.

The Bridge layer can add a denial but cannot turn a provider/Core denial into allow. Provider schemas, callbacks, custom Ability classes and native lifecycle remain owned by WordPress/provider code. Direct `WP_Ability::execute()`, the Adapter default server, WP-CLI, and unrelated REST routes are outside this Bridge request context and remain unchanged.

`abilities-read` exposes the delegation requirement from the same exact-object provenance decision used by execution, but still does not execute or pre-authorize target permission callbacks. Existing Bridge-owned typed operations that call provider APIs keep their documented Bridge groups plus provider/WordPress authority; Native Abilities does not replace those contracts.

Use existing WordPress roles/capabilities for identity and object authority; use Bridge settings for delegation. Adding an authenticated connection must not implicitly grant administrator or network authority. Capability changes, disconnection and policy revocation must be checked against current state.

## Administrative coverage and current boundaries

This table is a code-backed capability inventory, not a roadmap schedule or a live task ledger. `Implemented` means a typed Bridge contract or provider-neutral registered-Ability path exists, not that its access is enabled or that the current user may execute it. Future provider/upstream-dependent possibilities are not automatically active implementation Issues: promote a gap only when an applicable operation and an authoritative execution contract are evidenced. Research-only contract gaps remain tracked separately rather than being filled with private/provider-specific guesses.

| Family | Implemented entry points / owner | Current boundary / future contract-dependent coverage |
| --- | --- | --- |
| Context and discovery | `bridge-info`, `site-context`, `integration-status`, `abilities-read`; native Adapter discovery | Exact public contracts and delegation are discoverable. Input-dependent provider permission/effect explanations are not guessed where the authoritative provider contract cannot supply them. |
| Registered REST API | Filtered bounded `rest-routes-read` and guarded `rest-route-invoke` shipped and registered on one verified deployed v0.5.1 site, plus registered REST settings and typed fallbacks | Both REST grants default off and require native `manage_options`; invocation conservatively requires all protected consents/native capabilities. **Live discovery was verified; arbitrary real provider REST dispatch/interactive new-client onboarding was not**. No general external HTTP/binary bridge. |
| Content and revisions | `class-content-abilities.php`, `class-content-eligibility.php` | Ordinary supported post lifecycles are typed; private/internal objects with a materially different lifecycle require their own authoritative contract rather than blindly widening authoring. |
| Blocks and appearance | `class-block-abilities.php`, `class-navigation-abilities.php`; compatible theme/provider Abilities | Provider/native appearance operations are reusable dynamically. Historical research [#31](https://github.com/AChWorks/wp-ai-bridge/issues/31) and [#32](https://github.com/AChWorks/wp-ai-bridge/issues/32) is **closed/not planned**, not pending acceptance or a dependency for ordinary content changes. |
| Media | `class-media-abilities.php`: inspection, Base64 upload, explicitly enabled URL import, metadata update and deletion | Additional file/provider workflows use registered Abilities or a verified public lifecycle when one exists; Remote Media remains separate from executable-package authority. |
| Taxonomies and metadata | `class-taxonomy-abilities.php`; generic `post-meta-*`, `term-meta-*`, `user-meta-*`, and `comment-meta-*` with object-native authority and physical-state integrity | Purpose-specific authentication/session/role lifecycles remain outside generic metadata; no provider/key allowlists are used as a substitute. |
| Configuration | `class-site-config-abilities.php`; provider-neutral registered REST settings delegation; compatible provider Abilities | Registered/provider configuration is reused dynamically. Unregistered/private settings require explicit supported semantics rather than arbitrary option access. |
| Extensions and source | `class-extension-abilities.php`: WordPress.org/public HTTPS installation and lifecycle; installed source read/preview/apply/recovery; optional managed snippets | Native WordPress Upgrader and the shared installation lock remain authoritative. No manual ZIP staging, automatic activation, arbitrary file upload, or generic filesystem proxy. |
| Users and access | `class-user-abilities.php`: bounded users/roles and account upsert/removal; `class-application-password-abilities.php`: Core Application Password lifecycle behind separate default-off consent | Additional membership/session/authentication lifecycles use registered/provider operations or a dedicated supported contract; generic secret dumping remains out of scope. |
| Comments | `class-comment-abilities.php`: bounded Core inspection/reply/moderation/deletion; generic `comment-meta-*` under Advanced Metadata | Provider-specific comment workflows use a supported native/public contract when available. |
| Tools and maintenance | Environment inspection, extension update lifecycle, and compatible registered Core/provider Abilities through Native Abilities | **Accepted missing AI-facing outcomes:** native WP-Cron [#138](https://github.com/AChWorks/wp-ai-bridge/issues/138), WXR export/import [#139](https://github.com/AChWorks/wp-ai-bridge/issues/139), and real Site Health diagnostics [#140](https://github.com/AChWorks/wp-ai-bridge/issues/140) are **not shipped**; WordPress Core update detection is **read-only only** [#141](https://github.com/AChWorks/wp-ai-bridge/issues/141), with no upgrade execution from Bridge. Backup/restore and provider-specific maintenance remain native host/provider workflows, not raw shell/database fallbacks. |
| Provider business administration | Native public Abilities; verified Gravity Forms and Code Snippets fallbacks; provider-neutral metadata/settings contracts | Additional installed-provider workflows are dynamically reusable when public Abilities exist; private business/storage behavior without a supported contract remains an honest provider/upstream gap rather than guessed mutation. |
| Multisite | Bridge operations preserve native site/network/Super Admin authority; registered Core/provider network Abilities remain reusable through Native Abilities | Additional explicit site/network lifecycle operations require the corresponding supported WordPress/provider contract and real Super Admin/site boundaries; site-admin authority is never promoted to network authority. |
| Persistent Workspace | `workspace-resume`, `workspace-document`, `workspace-task` and admin lifecycle | Preserve version/hash guarantees and dedicated privacy boundaries as coverage grows. |

## Integrity and lifecycle

Use the operation's owning API, not a generic storage write that bypasses business validation. Registered metadata authorization and additional mapped capabilities remain authoritative. The protected-unregistered metadata opt-in is deliberately narrow: exact target authority, enabled Advanced Metadata, no explicit provider denial, no credential-like key, and lossless single-row state.

Existing post and term metadata updates/deletes use exact physical-row identity and byte-exact conditional persistence. Term authority additionally binds the original real term, taxonomy and term-taxonomy row inside every primary/compensating write. The term store reads native identity tables only through fixed joins; its only mutation target remains termmeta. Creation preserves the native sanitizer/filter/uniqueness/lifecycle around a conditional insertion instead of rewriting Core queries or adding an application transaction. Compensate only the current invocation's own unchanged row; never overwrite newer state to manufacture success. Share policy code where semantics are identical, but keep object-specific authority and lifecycle separate when extending metadata to users or comments.

For content/blocks/Workspace and future settings/files, use the current-state identity appropriate to overwrite risk. Preserve revisions where native, verify persistence, and describe partial failure/recovery accurately. Workspace internals remain inaccessible through unrelated content/meta operations but manageable through dedicated Workspace contracts.

## Network, package and source workflows

Network and executable-package authority use explicit default-off consent on fresh install and upgrade. Media import uses safe bounded streaming and normal MIME/attachment handling and cannot install executable packages. External Packages separately authorizes one public HTTPS plugin/theme package source under Code & Extensions plus the exact native install capability; the Bridge validates the destination/redirect boundary, streams into invocation-owned WordPress temporary storage with finite resource limits, then hands the completed package to the native Core Upgrader. It does not expose a generic HTTP/package proxy, caller-selected local path, request credentials/headers, or automatic activation.

WordPress Core extension installation uses a shared `WP_CONTENT_DIR` filesystem/database coordination lock across Bridge-owned native installer actions. **No manual WordPress-dashboard archive handoff exists in maintained Bridge source.** Client-supplied files need a separately verified, authorized **bidirectional** file exchange contract; transfer never implies executable installation or activation ([#134](https://github.com/AChWorks/wp-ai-bridge/issues/134)).

Source editing resolves an installed extension and a WordPress-editable relative file, enforces exact file-edit capabilities and deployment restrictions, rejects traversal/symlink escapes, previews exact previous/candidate bytes, persists with stale-state protection, and provides a private preimage/recovery path. The implementation verifies the actual Core editor/loopback boundary and preserves active/inactive/network authority. A nonce is not substitute authentication, and restoring a file does not undo PHP side effects.

Hooks, custom plugins and child themes remain preferable for routine customization; they are guidance rather than a blanket ban on an explicitly authorized vendor-file change. Editing the Bridge/Adapter itself requires an exact connection-loss and independently reachable recovery plan, not a hidden provider blacklist.

## Data exchange and performance contract

The current implementation uses bounded UTF-8 text windows for large posts/revisions, targeted Gutenberg discovery, paged Ability inspection and bounded Media Library/HTTPS package workflows. These are useful mechanisms, **not a universal file-transfer or data-type contract**. The accepted direction is in [Large MCP payloads](./LARGE-PAYLOADS.md): type-appropriate structured, long-text, binary, executable-package, and status/outcome semantics with exact identity, integrity, truthful completeness, and no unbounded JSON/base64. Source pagination belongs to WordPress/Bridge; Gateway enforces its independent transport ceilings. A large/unknown mutating response is not safe to replay automatically.

Gateway's current WordPress connector routes bounded JSON through named Ability discovery/read/execute; a compatible new Bridge Ability can be discovered without a WordPress-specific Gateway rewrite. Bidirectional binary file exchange requires independently proven client ingress and egress plus scoped Bridge/Gateway transfer paths, with exact user/site/Target binding; ordinary JSON Ability calls do not establish either file direction. There is also a **granular delegation mismatch** for the **already-shipped** generic REST invocation Ability: the current Gateway WordPress connector classifies a whole Ability from static readonly/destructive annotations, whereas the REST method/route determines the actual operation. Until a proven per-operation Gateway contract exists, treat that generic executor as elevated/unclassified end-to-end; never present its read-looking inputs as permission to run a destructive REST request. Only the Gateway repository may change its connector or shared transport; any proven Bridge interoperability gap on that side is coordinated through [mcp-gateway#130](https://github.com/AChWorks/mcp-gateway/issues/130) rather than another parallel Gateway workstream. Changes must remain compatible with already installed Bridge and Gateway versions until a separately approved release boundary. The verified Gateway WordPress connector currently speaks MCP `2025-11-25` with an initialize/session exchange. [MCP `2026-07-28`](https://blog.modelcontextprotocol.io/posts/2026-07-28/) changes core transport/session semantics; future support must use actual client/Adapter protocol negotiation and compatibility evidence, not unconditionally replace the proven older transport.

Performance work should establish representative small/large/concurrent baselines, distinguish WordPress parser/query cost from HTTP/JSON encoding and Gateway forwarding, and preserve ordinary idle-site overhead near zero. Apply targeted queries, finite concurrency, cache only non-authoritative metadata with invalidation, and avoid duplicated provider catalogs, repeated broad scans or per-site background daemons. Never trade away runtime permission rechecks for cache speed.

## Validation and evolution

Retain existing quality/static/regression coverage, but run assurance at a meaningful frozen-candidate cutline rather than after each small remediation. During Draft implementation use the narrowest diagnostic check that can resolve a concrete uncertainty and batch coherent fixes. The final runtime-relevant candidate receives the complete supported WordPress assurance set required by the repository workflow.

Independent HIGH_ASSURANCE review, when required, is read-only and source/diff/evidence based. It may inspect existing defensive regression fixtures and CI results but is not an instruction to generate or execute new exploit-style/security probe matrices. A newly identified missing regression returns to implementation, where the fixture can be added and related remediation batched before the next meaningful final candidate.

Update the root specification only for accepted product-level changes. Refine this architecture and public operation documentation when implementation changes. Keep task scope, current candidates, CI results, ownership and blockers in GitHub, not in parallel manager-memory documents. A concrete operation with no supported execution contract remains an honest conformance/upstream gap and should be tracked when evidenced; it is not permission to invent a generic privileged fallback.
