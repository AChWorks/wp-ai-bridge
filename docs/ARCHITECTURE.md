# Architecture

## Normative source and implementation boundary

[`MASTER-SPEC.md`](../MASTER-SPEC.md) defines the product goal: full legitimate WordPress administration, discoverable and delegable through simple administrator-controlled settings. This document refines that specification and describes the actual component boundaries and outstanding coverage. Neither the current tool inventory nor a task's exclusions are a permanent product ceiling.

The architecture is deliberately small. Reuse WordPress identity/capabilities, registered Abilities, the official MCP Adapter, the existing Bridge settings and permission service, and thin public-API fallbacks. Do not create a second registry, policy language, per-provider permission engine, generic execution framework, or helper-plugin bundle.

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

## Discovery and reuse

Use the native registry as the one operation inventory. Prefer a suitable Core/provider Ability with its real public contract. Otherwise use a supported public WordPress/provider API, including an appropriate registered REST contract, through the smallest typed fallback needed for the actual gap. Only a proven public contract justifies a provider-specific fallback. Keep such fallbacks removable when upstream publishes a suitable native Ability.

Do not infer execution compatibility or authority from names, descriptions, category names, or read-only annotations. A provider explicitly hiding its native Ability is not permission to expose an equivalent lower-level fallback. Unknown/private business behavior needs a verified implementation path, not guessed storage mutation.

`bridge-info` reports dependencies and enabled Bridge groups. `site-context` preserves its compact installation context and first-50 external reuse hints. `abilities-read` supplements it with sorted, filtered, paginated public Core/Bridge/provider contracts and exact named schema reads. It excludes non-public and explicitly MCP-hidden contracts and does not return arbitrary provider metadata. The shared resolver matches the pinned Adapter: malformed MCP metadata is denied, explicit non-null MCP public flags take precedence, and an inherited general public flag must be exactly boolean true.

Contract inspection always reports `execution_permission: not_evaluated`. A schema is not permission, and a target-specific provider callback cannot safely be evaluated without its real valid input. It also reports `bridge_delegation`: Bridge-owned operations use their ability-specific policy while non-Bridge registered operations require `native_abilities` when executed through the Bridge. Native permission checks still run when the operation is executed. Bounded errors replace oversized/unrepresentable inspection output; schemas are never silently truncated.

The broader specification also requires actionable effect/availability diagnostics. Public contract inspection does not infer arbitrary provider effects or complete input-dependent availability from provider prose/annotations. Those remain explicit implementation gaps, not fabricated discovery fields.

## Delegation boundary

Bridge-owned operations enforce their documented precise access groups and native capabilities. Registered non-Bridge Core/provider Abilities invoked through the exact WP AI Bridge MCP routes additionally require the default-off **Native Abilities** group and then still must pass the target's original WordPress/provider permission callback. Native Abilities is deliberately coarse broad trust because the Bridge does not infer safe effect classes from provider annotations, descriptions, names, or categories.

`src/Support/class-native-ability-delegation.php` owns this boundary:

- the public WordPress `rest_endpoints` filter wraps only the canonical and retained legacy Bridge MCP callbacks with balanced request context and unconditional cleanup;
- only the Adapter's `mcp-adapter/execute-ability` permission callback is layered with Bridge policy during Ability registration;
- genuine Bridge registrations are remembered only until WordPress finishes registration, then the exact successfully registered live Ability objects are bound into a private object-identity provenance set; provider namespaces, metadata, annotations, custom getters and custom `ability_class` implementations cannot grant Bridge-owned treatment;
- the provenance set stores only Bridge-owned live object identity. It is not a second Ability registry, contains no provider contracts/schemas/callbacks, performs no execution dispatch, and leaves WordPress as the sole operation registry;
- the current `native_abilities` setting is read at execution time, so subsequent calls observe revocation immediately.

The Bridge layer can add a denial but cannot turn a provider/Core denial into allow. Provider schemas, callbacks, custom Ability classes and native lifecycle remain owned by WordPress/provider code. Direct `WP_Ability::execute()`, the Adapter default server, WP-CLI, and unrelated REST routes are outside this Bridge request context and remain unchanged.

`abilities-read` exposes the delegation requirement from the same exact-object provenance decision used by execution, but still does not execute or pre-authorize target permission callbacks. Existing Bridge-owned typed operations that call provider APIs keep their documented Bridge groups plus provider/WordPress authority; Native Abilities does not replace those contracts.

Use existing WordPress roles/capabilities for identity and object authority; use Bridge settings for delegation. Adding an authenticated connection must not implicitly grant administrator or network authority. Capability changes, disconnection and policy revocation must be checked against current state.

## Administrative coverage and remaining gaps

This table is a code-backed capability inventory, not a roadmap schedule or a live task ledger. `Implemented` means a typed contract exists, not that its access is enabled or that the current user may execute it. The list is non-exhaustive and does not redefine the root specification. Update the relevant row when a tested implementation reaches the target branch; active work and ordering belong in GitHub Issues/PRs.

| Family | Implemented entry points / owner | Remaining coverage against the specification |
| --- | --- | --- |
| Context and discovery | `bridge-info`, `site-context`, `integration-status`, `abilities-read`; native Adapter discovery | Effect and input-dependent availability explanations without treating annotations as authorization. |
| Content and revisions | `class-content-abilities.php`, `class-content-eligibility.php` | Administration of objects with different private/internal lifecycles must use appropriate contracts rather than widening ordinary authoring blindly. |
| Blocks and appearance | `class-block-abilities.php`, `class-navigation-abilities.php`; compatible theme/provider Abilities | Generic widget/template/style administration and authoritative editor-serialization or staged-theme workflows where upstream supports them. PHP parse/serialize is not editor validation. |
| Media | `class-media-abilities.php`: inspection, Base64 upload, explicitly enabled URL import, metadata update and deletion | Additional validated file workflows; Remote Media remains default-off and is not executable-package installation. |
| Taxonomies and metadata | `class-taxonomy-abilities.php`; generic `post-meta-*` and exact-taxonomy `term-meta-*` with protected-key opt-in and physical-state integrity | User/comment metadata with distinct authorization. No provider/key allowlists. |
| Configuration | `class-site-config-abilities.php`: bounded site-setting fields; compatible provider Abilities | Broader registered site/network/theme/provider settings and explicit semantics for unregistered settings. The current field list is not a permanent product policy. |
| Extensions and source | `class-extension-abilities.php`; optional managed snippets; installed source read/preview/apply/recovery | Separately consented uploaded/URL package sources and broader validated extension workflows where WordPress provides an authoritative lifecycle. |
| Users and access | `class-user-abilities.php`: bounded users/roles, account upsert/removal | Wider role/capability, membership, session and authentication lifecycle with real delegable authority; no generic secret dumping. |
| Comments | No Bridge-owned moderation contract yet; compatible provider contracts may exist | Native comment inspection, moderation/replies/status/deletion and comment metadata. |
| Tools and maintenance | Environment inspection and any compatible installed-provider Ability | Supported import/export, scheduled tasks, maintenance/cache and backup/restore workflows, without a raw shell or database console. |
| Provider business administration | Native public Abilities; verified Gravity Forms and Code Snippets fallbacks | Additional installed-provider workflows through their real public lifecycle; generic post metadata is not a replacement for commerce/order or private provider storage. |
| Multisite | Existing operations remain subject to native WordPress authority | Explicit site/network administration and delegation, with real Super Admin/site boundaries and dedicated tests. |
| Persistent Workspace | `workspace-resume`, `workspace-document`, `workspace-task` and admin lifecycle | Preserve version/hash guarantees and dedicated privacy boundaries as coverage grows. |

## Integrity and lifecycle

Use the operation's owning API, not a generic storage write that bypasses business validation. Registered metadata authorization and additional mapped capabilities remain authoritative. The protected-unregistered metadata opt-in is deliberately narrow: exact target authority, enabled Advanced Metadata, no explicit provider denial, no credential-like key, and lossless single-row state.

Existing post and term metadata updates/deletes use exact physical-row identity and byte-exact conditional persistence. Term authority additionally binds the original real term, taxonomy and term-taxonomy row inside every primary/compensating write. The term store reads native identity tables only through fixed joins; its only mutation target remains termmeta. Creation preserves the native sanitizer/filter/uniqueness/lifecycle around a conditional insertion instead of rewriting Core queries or adding an application transaction. Compensate only the current invocation's own unchanged row; never overwrite newer state to manufacture success. Share policy code where semantics are identical, but keep object-specific authority and lifecycle separate when extending metadata to users or comments.

For content/blocks/Workspace and future settings/files, use the current-state identity appropriate to overwrite risk. Preserve revisions where native, verify persistence, and describe partial failure/recovery accurately. Workspace internals remain inaccessible through unrelated content/meta operations but manageable through dedicated Workspace contracts.

## Network, package and source workflows

These are required coverage, not yet a claim that every workflow is implemented. Use explicit default-off consent for materially new outbound or executable authority on fresh install and upgrade. Media import uses safe bounded streaming and normal MIME/attachment handling; it cannot install executable packages. Package installation uses its own WordPress installer/lifecycle and provenance/target checks, without a permanent WordPress.org-only policy.

Source editing must resolve an installed extension and a WordPress-editable relative file, enforce exact file-edit capabilities and deployment restrictions, reject traversal/symlink escapes, preview exact previous/candidate bytes, persist with stale-state protection, and provide a private preimage/recovery path. Verify the actual Core editor API's loopback authentication, active/inactive/network behavior and rollback ownership before reuse. A nonce is not substitute authentication, and restoring a file does not undo PHP side effects.

Hooks, custom plugins and child themes remain preferable for routine customization; they are guidance rather than a blanket ban on an explicitly authorized vendor-file change. Editing the Bridge/Adapter itself requires an exact connection-loss and independently reachable recovery plan, not a hidden provider blacklist.

## Validation and evolution

For each increment, retain existing quality/static checks, test negative permissions and revocation as well as success, verify real WordPress behavior on both supported integration lanes, and exercise the actual Adapter contract. Add fixture providers/custom targets rather than assuming a named vendor defines coverage. High-risk execution surfaces need independent exact-candidate review and the applicable integration/production gates.

Update the root specification only for accepted product-level changes. Refine this architecture and public operation documentation when implementation changes. Keep task scope, current candidates, CI results, ownership and blockers in GitHub, not in parallel manager-memory documents. Missing capabilities remain tracked conformance gaps; completing one increment is not full administrator parity.
