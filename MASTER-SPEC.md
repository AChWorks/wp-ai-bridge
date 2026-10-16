# WP AI Bridge — Master Specification

**Status:** Canonical, long-term product specification
**Repository:** `AChWorks/wp-ai-bridge`

## 1. Vision and purpose

WP AI Bridge is a lightweight, self-hosted, WordPress-native **administrative access and delegation bridge**. Its purpose is to make the legitimate administrative capabilities of an actual WordPress installation discoverable and usable by **authorized external consumers**, with authority comparable to that of the WordPress principal on whose behalf an operation is performed.

**AI is an important consumer, not the only consumer.** The Bridge serves compatible AI agents, human-operated Gateway interfaces, directly connected applications, service integrations, and approved automations. A human using a Gateway panel to publish a post and an AI doing the same work should use the **same WordPress operation and security model**, not two separate administrative implementations.

The product's coverage goal is the complete set of legitimate operations available to the relevant WordPress administrator and installed providers, not a fixed list of AI tools, integrations, object types, keys, or predetermined site-building tasks. Coverage is measured against real administrative work, not the number of Bridge Abilities shipped. Where an operation is feasible through an appropriate WordPress/provider contract, its sensitivity or complexity is a reason to secure and delegate it correctly, **not a reason to forbid that administrative family permanently**.

Administrator equivalence is bounded by actual WordPress authority, site/network roles, hosting policy, and available interfaces. Bridge does not create operating-system root access, override provider denials, or silently turn a site administrator into a multisite Super Admin. Where no legitimate supported execution path exists, the product must report the gap rather than falsely advertise support.

Bridge is an integration layer, **not an AI model, website builder, duplicate WordPress administration system, or provider-business-logic engine**. Its responsibility is connecting identities, delegating authority, discovering and executing valid operations, exchanging data, supporting recovery, and maintaining a small private Workspace.

## 2. Consumers, connections, and identities

The product is **consumer-neutral**. Authorized requests may arrive through a direct compatible connection, an optional Gateway, or another approved integration interface as the product evolves. A Gateway may relay an AI request **or originate its own human-initiated or automated request**. Neither mode depends on an AI being in the execution path.

Two identity/delegation models are part of the product's required direction:

1. **User-delegated operations:** a client acts on behalf of an authenticated WordPress user, within that user's effective WordPress permissions and the administrator-approved Bridge delegation.
2. **Approved service operations:** a connected service or automation has its own independently identifiable, administrator-approved connection and delegated scope, bound to an explicit WordPress-recognized authority rather than an invisible or unlimited superuser.

Both models share the same WordPress-side execution, permission, validation, audit and revocation principles. A service identity must never be a way to impersonate arbitrary users, bypass WordPress capabilities, or inherit another client's consent. Administrator control must cover approval, effective scope, identity binding, revocation, and sensitive changes. **The service model is an architectural direction, not a claim that every service-identity workflow already ships.** Its concrete credential and lifecycle design belongs in specialized security and implementation documentation.

The Bridge's WordPress-side contracts should be portable across compatible clients. A named AI vendor, Gateway, or automation service is not the product identity. Client-specific authentication, transport, file-delivery and compatibility claims require actual verification; direct connection and Gateway-mediated operation should not create separate provider-specific permission engines.

## 3. Target architecture

```text
AI clients  |  Gateway UI/API  |  applications  |  approved services/automations
                              |
                Direct or intermediated connections
                              |
             WP AI Bridge: identity, delegation, discovery,
              authorized execution, exchange, recovery,
                         private Workspace
                              |
          WordPress Core / Abilities / registered REST APIs /
               supported plugin and theme interfaces
                              |
              Native WordPress authority and effects
```

The Bridge lives inside WordPress and **reuses** the WordPress identity/capability system, native operations, official MCP Adapter and Abilities API where appropriate, registered REST contracts, and installed-provider public APIs. It is not a second implementation of posts, forms, commerce, roles, scheduling, appearance or other provider-owned business rules.

Use **one coherent authorization and execution boundary** for equivalent operations regardless of whether the initiating consumer is an AI, human-controlled Gateway, or service. Prefer runtime discovery of valid WordPress/provider contracts over hardcoded provider allowlists. When a native operation is missing, add a focused, typed Bridge adapter only for the real gap, preserving native validation and lifecycle. Do not introduce a custom global administration framework, a helper plugin per provider, duplicate registries or databases merely to simulate coverage.

The Bridge must remain independently usable and maintainable without a mandatory Gateway, external SaaS, extra daemon, queue or separate database. The independently maintained Gateway may provide orchestration, user experience and transport; WordPress-side semantics and data ownership remain with the Bridge and WordPress.

## 4. Administrator-equivalent coverage

The following are **coverage families**, not release promises or an exhaustive tool inventory:

- Content: posts, pages, custom post types, blocks, revisions, publication, scheduling and supported import/export.
- Media and data: library operations, safe remote imports, legitimate downloads, binary/text transfer, attachments and files through appropriate native destinations.
- Site administration: registered settings, appearance, navigation, taxonomies, metadata and WordPress site-health diagnostics.
- Extensions: plugins, themes, installation, activation, updates, removal, administrator-approved package sources, managed code and authorized installed-source editing.
- Accounts: users, roles, capabilities, authentication and credentials through the appropriate native lifecycle and authority.
- Operations: comments, scheduled tasks, supported maintenance, backups/restores where a native/provider contract supplies them, and other legitimate admin workflows.
- Installed providers: discover and invoke applicable operations from plugins and themes without a Bridge patch for each new compatible target.
- Multisite: respect the real distinction between site-level and network-level authority.
- Workspace: project documents, tasks, state and recoverable history for authorized consumers.

**Established product exception:** WordPress **Core upgrades** are a read-only Bridge status/detection capability, not an executable upgrade workflow through Bridge. Initiation and execution of Core upgrades remain with WordPress's own administration/hosting channels. This narrow decision does **not** prohibit authorized plugin/theme update workflows or redefine the general administrator-coverage goal.

A built-in WordPress admin screen is not, by itself, a stable external execution API. Do not invent a callable or claim support when no defensible Core/provider/authorized extension interface exists. Such gaps remain visible without silently shrinking the product objective.

## 5. Administrator control, security and trust

**Administrator permission is the policy gate, not a substitute for secure code.** Implement the legitimate workflow safely and make its materially sensitive authority separately grantable, reviewable and revocable. Broad admin-equivalent delegation and smaller scopes must be possible within a comprehensible grouped policy, rather than requiring a separate switch or Bridge-specific adapter for every provider operation.

Effective access is the intersection of:

- authenticated and approved consumer identity and its intended delegated principal/service scope;
- the WordPress user's or service-bound principal's real capabilities;
- the administrator's current Bridge grants; and
- the target provider, site/network, environment and operation's actual authorization rules.

Recheck authority at execution time. Read permission, discovery, an old token, a previously issued grant, or a Gateway user's own role must not silently authorize a new write or elevated operation. Sensitive account, credentials, executable source, external package and destructive actions require appropriate independent trust and recovery treatment. New high-trust functionality must not silently inherit an old unrelated opt-in after upgrades.

Security and integrity belong **inside working administrative paths**, not in blanket feature bans. Use controlled inputs and destinations, exact target identification, native/provider checks, safe file handling, stale-write protection, verified outcomes, and explicit recovery for uncertain effects. Executable plugin/theme code is an elevated, administrator-authorized trust boundary, **not a PHP sandbox**. Reject raw unrestricted shell/PHP/SQL execution, unchecked proxying and bulk credential exposure as shortcuts, while preserving legitimate administrative alternatives.

## 6. Discovery, execution and truthful outcomes

An authorized consumer must be able to discover what the **actual installation** can do, the required authority and schemas, whether a capability is available, and why an operation is disabled, denied or unsupported. Runtime discovery should accommodate newly installed providers and new valid targets of existing generic contracts without a bespoke Bridge release.

A valid request executes an identified supported operation through the proper WordPress/provider lifecycle, not through caller-selected arbitrary internals. Preserve the distinction between discovery, consent, target-specific authorization and execution. Return clear committed, rejected, incomplete, stale or genuinely uncertain results; do not claim rollback or retry safety that cannot be established.

Provider-originated semantics and permission callbacks remain authoritative. The same operation contract must work consistently regardless of which authorized consumer initiated it.

## 7. Data exchange and continuity

Bridge must support **bidirectional, lossless and type-aware** exchange between WordPress and authorized consumers, directly and through intermediaries when those paths actually support the corresponding transport. This includes structured data, long text, large collections, media, documents and approved extension packages. Transfer itself grants no permission to publish, install or execute.

Use appropriate bounded messages, streaming/chunks, pagination and resumable operations with identity, integrity, privacy and recovery controls. Never require manually relocating a staged file to WordPress's final Media Library or extension destination. Private temporary data must stay private; completed authorized WordPress effects must use native destinations. An end-to-end client file experience cannot be claimed until that client's actual ingress and egress are verified.

A **small private persistent Workspace** keeps multiple projects' documents, tasks, state and useful activity across conversations, sessions, AI clients and other approved consumers. It is WordPress-owned, not a ChatGPT-memory surrogate or a duplicate public project-management platform. Authorized consumers should be able to identify the correct project, recover prior context and continue work without relying on a particular chat or vendor. Preserve data across ordinary plugin updates and handle edits, conflicts, export and explicit destructive lifecycle safely.

## 8. Performance, capacity and deployment simplicity

**Do not create artificial permanent Bridge ceilings on legitimate work to simplify implementation.** Ordinary file sizes, message/transfer capacities, concurrency, retention, record counts and work budgets should have finite, validated, administrator-configurable policies that can follow the site's actual available resources without editing Bridge source for routine expansion.

WordPress/PHP/hosting resources, network limits, actual API constraints and external client/Gateway capacities remain real. Honor them and return clear capacity or compatibility feedback. Prefer bounded streaming, selective reads, lazy discovery, pagination, efficient native operations and recoverable long workflows over loading everything into one request, setting arbitrary universal caps or requiring additional infrastructure.

Security invariants such as authorization, identity, protected paths, data integrity and unknown-effect fencing are **not configurable away as performance knobs**. Optimize based on measured cost and compatible WordPress behavior, not by cutting administrator coverage.

## 9. Portability and distribution

Build as a small, maintainable, free WordPress plugin based on supported WordPress and official MCP Adapter interfaces. Preserve canonical WordPress plugin identity `wp-ai-bridge/wp-ai-bridge.php`, stable settings/Workspace data, relevant OAuth identity and compatible public contracts through ordinary upgrades.

**GitHub published stable Releases** and the official installable ZIP are the maintained distribution channel; use WordPress-native update flows. WordPress.org directory listing is a separate conditional option **only for the same transparent full-feature product**. Do not hide or remove legitimate administrator-authorized External Packages, Source Editing or other capabilities, or create a covert reduced-feature fork to obtain directory acceptance.

Use supported native WordPress facilities whenever possible. Validate version-specific and client-specific claims rather than making blanket compatibility promises.

## 10. Development and documentation philosophy

Develop toward complete legitimate administrator coverage in coherent, evidence-backed increments while keeping the architecture small, reusable and understandable. Preserve proven behavior and authorization contracts. New interfaces or trust boundaries require proportionate security, compatibility, recovery and end-to-end validation; working code must be distinguished from a verified client workflow or an actually deployed installation.

**This document defines enduring product intent and architecture, not project status.** Do not add Issue/PR numbers, implementation histories, dated test results, release checkpoints, sprint plans, technical assertion inventories or operational handoffs here.

- `README.md`, `readme.txt` and user guides explain the **actually available product** and how to use it.
- `docs/` owns detailed architecture, API/Ability behavior, security, integration, operations, installation and developer guidance.
- Source/tests, Issues/PRs, CI, tags and Releases own implemented behavior, active work, validation and delivery evidence.
- Historical material, where worth keeping, belongs in explicitly non-authoritative references, not as a second current specification.

## 11. Success criteria

WP AI Bridge succeeds when a WordPress administrator can authorize an AI, a Gateway operator or another approved client/service; discover the site's real administrative surface; execute its granted legitimate operations through the owning WordPress contracts; and inspect/revoke access without relying on the consumer's vendor or a duplicate tool-specific security model.

New compatible providers and legitimate targets should not require ongoing Bridge source changes merely to become usable. Authorized data should move reliably in both directions through verified compatible routes, and a project should be recoverable from its WordPress Workspace by a subsequent authorized consumer.

Coverage, integrity, recoverability, performance, simplicity and truthful reporting **all matter**: none is a reason to silently abandon the others. Incomplete or unsupported operations remain visible gaps until correctly addressed rather than being reclassified as permanent product exclusions.
