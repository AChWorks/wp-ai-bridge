# User guide

## Dashboard

**WP AI Bridge → Dashboard** provides a compact view of Workspace state and the Bridge connection environment.

Use it to confirm that the plugin is active and to orient a connected client before performing site work.

## Documents

**WP AI Bridge → Documents** stores durable project documents inside private WordPress-native storage.

The read-only **Project ID** column and document inspector display the exact stored `project_ref` beside its title. Records without a project binding show **Unbound**; the admin does not infer, assign, or create projects. Document tables, mixed-language titles and the inspector heading inherit the active WordPress admin direction (RTL in Persian, LTR in English). Only the escaped raw Markdown document body is explicitly LTR/left-aligned; inline technical keys and hashes keep their readable character order without changing the surrounding cell alignment. This affects display only; it does not translate, rewrite, or render Markdown as trusted HTML.

Documents support:

- create;
- list and read;
- update;
- archive;
- optimistic concurrency using `version` and `state_hash`.

A client must refresh a document after another actor changes it before overwriting it. Stale writes are rejected instead of silently replacing newer state.

## Tasks

**WP AI Bridge → Tasks** stores durable work items. Each task keeps progress, review, and delivery as independent state dimensions so one status does not accidentally imply another.

Task filters are available for progress, review, delivery, and archived state. The read-only **Project ID** column and task inspector show the same exact project reference or **Unbound** marker. Task list titles, dashboard summaries, inspector titles and goals inherit native WordPress admin direction and alignment. Only the escaped raw Notes text block in the task inspector is isolated LTR/left-aligned like the document Markdown body, while the translated Notes label and surrounding controls retain their normal direction; no stored content is changed. No separate project registry or Projects admin screen is required.

Tasks use the same `version` + `state_hash` stale-write protection as Workspace documents.

## Workspace record-count capacity and continuity

**WP AI Bridge → Settings → Workspace document and task capacity** has **two independent per-site record-count controls**, separate from the maximum KiB size of an individual document/task, the MCP JSON response budget, and all WordPress/Bridge permissions. Existing sites retain the conservative **200-document / 200-task defaults** until an administrator adjusts them. The validated ranges are **10–20,000 documents** and **10–50,000 tasks** per WordPress blog, subject to actual PHP, MariaDB and hosting capacity. These figures are finite operator policies, not guarantees of throughput at the configured maximum.

Archived records are intentionally kept and count toward each policy; lowering a policy never deletes or hides records. A site above the new limit can continue reading and exporting its permitted data, but cannot create more of that record type until the operator raises the quota. WordPress core data and other sites on a multisite network are unaffected. No automatic purge, retention policy, or extra project management UI is introduced.

AI-facing \`workspace-document\` and \`workspace-task\` \`view=summary\` use **at most 25** small result entries per call. Check \`has_more\` and \`next_cursor\`; do not mistake one page for a complete record set. The admin Documents and Tasks tables also use 25-row pages. Project resume retains only the first 25 sorted distinct project references, marks projects_truncated if more exist, and clients can enumerate the remaining projects through complete document/task summary pages. Exact document/task content remains in the existing private WordPress posts/meta, with one bound \`project_ref\`, current version/hash and explicitly guarded read windows. The Bridge scans bounded batches using a size-only preflight and memory-budgeted state reads, and fails closed on malformed or unexpectedly oversized metadata instead of loading every document into PHP memory. Large record collections still incur actual database work: monitor response time and resources when raising quotas on a shared host.

Legacy full-list and **Export Workspace** are allowed only if the complete encoded collection fits the current safe in-memory budget. An oversized collection returns a clear failure rather than silently truncating content or labelling a partial export complete. For disaster recovery, use a tested **WordPress database backup/restore**; exported JSON is not a Bridge restore API. Create-operation receipts and canonical project/key reservations are retained so retries cannot silently duplicate records. New receipts use independently counted generic-operation and canonical-document-key admission domains while preserving the exact old immutable option identity for backward-compatible retries. Lowering Workspace document/task limits does not reduce unrelated generic post/media/comment create recovery capacity. A separate representative real WordPress/MariaDB test enforces a **96MiB PHP limit from process startup** and reports its verified limit, workload counts and peak memory; this is a test bound, not a guarantee that every live host can scale to the maximum setting.

## Exact Workspace record deletion

The destructive `workspace-delete` Ability deletes **one** named, project-bound document or task permanently. A site administrator must provide its current WordPress blog ID, exact record type/ID and project reference, latest version/state hash and the confirmation phrase `PERMANENTLY DELETE DOCUMENT <id>` or `PERMANENTLY DELETE TASK <id>`. The operation requires an authenticated MCP client, WordPress `delete_post` plus administrative authority, and both Builder Write and Users & Destructive access groups. The source uses an atomic deletion fence and post-delete readback. If WordPress explicitly vetoes deletion before its native destructive phase, a verified state-only CAS rollback preserves the existing readable record and returns `workspace_delete_vetoed`. If a grant, site or DB admission check changes after the CAS but before native cleanup, a proven safe compensation restores the original record and returns `workspace_delete_aborted`; neither is a successful deletion. The Bridge also refuses to proceed when another hook is dynamically scheduled after its terminal delete guard. A cross-blog hook switch can cause the best-effort Activity entry to be omitted; the Bridge must never write that event to an unrelated blog. Refresh the record's current identity before any later authorized attempt. If the result is uncertain, stop and reconcile the specific ID; do not blindly recreate or repeat. Legacy/unbound records are deliberately excluded, and reserved canonical document keys remain reserved even after the post is removed. There is no bulk cleanup through this Ability. **Do not use Clear Workspace to clean up test records.**

## Activity

**WP AI Bridge → Activity** shows the bounded Bridge mutation log. The log records operation metadata needed for administration and troubleshooting; it is not intended to store submitted content, credentials, OAuth secrets, or arbitrary payloads.

Private transfer `begin` results and selected authenticated transfer failures are also recorded here, using fixed error codes, UTC time, operation/phase, and opaque per-event references. At most 50 entries are stored; entries older than 30 days are hidden and discarded on the next recorded event; busy or concurrent writes mean the list is useful for diagnosis, not a guaranteed exhaustive audit trail. Never infer that a request reached WordPress solely from a Gateway error: Gateway-side rejection, OAuth denial before the callback, transport faults and missing permissions may leave **no Bridge Activity entry**. Do not disable security or broaden OAuth rights to force a passing transfer.

An authenticated WordPress site administrator with delegated **Site Read** may query the bounded, read-only `wp-ai-bridge/diagnostics-read` Ability. This returns only sanitized provider event metadata—no user IDs, Workspace content, paths, tokens or raw traces. It cannot read or diagnose Gateway logs, and it does not authorize any transfer operation. For a rejected file upload, first inspect Activity and correlate the UTC time; if no event was recorded, investigate the Gateway and WordPress callback/authentication boundary separately. Existing Activity records are not rewritten on upgrade.

## Settings

**WP AI Bridge → Settings** contains:

- the canonical direct ChatGPT MCP endpoint;
- OAuth metadata links;
- dependency/HTTPS readiness;
- Bridge access groups.

Enable the smallest access set needed for the current work. Version 0.4.0 uses only the canonical `wp-ai-bridge...` admin page slugs; former admin aliases are not registered.

## Workspace resume

The `workspace-resume` Ability returns compact orientation information for a connected client. Its public Ability identifier uses the canonical `wp-ai-bridge/*` namespace. It is designed to help continue a site project without dumping complete document bodies, task notes, or chat history into every new conversation.

### Project-specific recovery

An optional exact project_ref scopes Workspace discovery and resume to one logical project on the authenticated WordPress site. It is not a new tenant role or privacy boundary. Legacy items remain unbound until explicitly associated using their current version and state_hash. When a site has multiple projects, an unfiltered resume is labelled mixed and deliberately does not select an arbitrary current task. Project task summaries show separate progress, review, delivery, next_action and blocker.

Use workspace-document or workspace-task with action=list, view=summary, limit (1 to 25), an optional project_ref and before_id for small pages. Read has_more and next_cursor; one page never proves there are no other records. For long Markdown or notes, first get a summary containing the current ID/version/state_hash; then get view=window for content or notes, with offset, max_bytes and the same expected_version plus expected_state_hash. Concatenate the exact UTF-8 windows until complete is true. Large successful creates and updates return verified committed identity plus explicit omitted_fields instead of pretending that a truncated response is complete.

Canonical document keys such as project-brief are unique within a site/project_ref pair, including after archival. Reopening uses guarded unarchive, not a new document. Invalid UTF-8, oversized fields and sanitization that would change submitted text are rejected before persisting.

### Optional duplicate-safe creation

Six existing Bridge create operations accept an optional client-provided, stable operation_id: content create, media upload, media URL import, comment reply, Workspace document create and Workspace task create. Reuse exactly the same ID AND input only for a retry of the same intended create. A different intent must use another ID. An existing committed result is read back only under current WordPress and Bridge permissions, while a conflicting payload is rejected. When the effect is uncertain or incomplete, do not invent a new ID and blindly repeat the mutation. Keyless legacy creates retain their previous behavior and are not guaranteed duplicate-safe.

Receipt readback lasts 30 days; uncertain and expired IDs stay reserved against unsafe reuse. New `operation_id` receipts have a **separate administrator-configurable per-site admission budget** (default **2,048**, validated range **128–100,000**). Under **WP AI Bridge → Settings → Keyed create recovery capacity**, administrators can raise this finite generic limit without editing PHP, changing Bridge access grants or altering Workspace document/task quotas. Higher settings need actual WordPress database resources; counts can become slower as the receipt ledger grows. Lowering capacity blocks admission of new independent IDs above the new limit but never destroys stored receipts or prevents authorized recovery of an existing committed ID; canonical document-key reservations have their own finite budget derived from current Workspace count settings (minimum 2,048), without reducing generic admission. Historical untyped receipts retain their original database-unique identity and remain recoverable/reserved, but do not consume either new typed domain. At the relevant domain's capacity, new keyed creates fail closed and require explicit authorized reconciliation; Bridge never automatically deletes receipts or retries an uncertain effect.

### Site Health and Core update status

Dashboard and Settings show which local MCP/OAuth dependencies and HTTPS URL scheme are configured, but distinguish them from public network reachability and authenticated external AI-client connectivity, which are not verified by merely rendering a local URL. WordPress Tools > Site Health remains the complete native UI. The new Bridge site-health capability requires the default-off Site Configuration grant plus native view_site_health_checks, discovers native tests without running them, and executes only individually supported Core diagnostics on request; it does not expose raw server debug secrets. An internal Authorization-header check cannot verify public proxy header forwarding. The read-only core-update-status output uses cached, freshness-checked WordPress update offers and never triggers a Core upgrade.

These read-only diagnostics are included in published Bridge v0.6.0+ packages, subject to the exact installed version, Site Configuration grant and WordPress native permissions. They do **not** authorize WordPress Core upgrades through Bridge.

## Export and clear

Workspace administration provides explicit export/clear lifecycle controls. Clearing Workspace data is intentionally separate from ordinary plugin deactivation/uninstall because durable project state should not disappear as a side effect of replacing a transport plugin.

## Language and RTL

The plugin uses standard WordPress localization APIs. Persian (`fa_IR`) is bundled, and the admin screens are designed to work in both RTL and LTR WordPress installations. The canonical text domain is `wp-ai-bridge`.
