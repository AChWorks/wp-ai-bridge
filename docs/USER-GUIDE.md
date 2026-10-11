# User guide

## Dashboard

**WP AI Bridge → Dashboard** provides a compact view of Workspace state and the Bridge connection environment.

Use it to confirm that the plugin is active and to orient a connected client before performing site work.

## Documents

**WP AI Bridge → Documents** stores durable project documents inside private WordPress-native storage.

Documents support:

- create;
- list and read;
- update;
- archive;
- optimistic concurrency using `version` and `state_hash`.

A client must refresh a document after another actor changes it before overwriting it. Stale writes are rejected instead of silently replacing newer state.

## Tasks

**WP AI Bridge → Tasks** stores durable work items. Each task keeps progress, review, and delivery as independent state dimensions so one status does not accidentally imply another.

Task filters are available for progress, review, delivery, and archived state.

Tasks use the same `version` + `state_hash` stale-write protection as Workspace documents.

## Activity

**WP AI Bridge → Activity** shows the bounded Bridge mutation log. The log records operation metadata needed for administration and troubleshooting; it is not intended to store submitted content, credentials, OAuth secrets, or arbitrary payloads.

## Settings

**WP AI Bridge → Settings** contains:

- the canonical direct ChatGPT MCP endpoint;
- OAuth metadata links;
- dependency/HTTPS readiness;
- Bridge access groups.

Enable the smallest access set needed for the current work. Version 0.4.0 uses only the canonical `wp-ai-bridge...` admin page slugs; former admin aliases are not registered.

## Workspace resume

The `workspace-resume` Ability returns compact orientation information for a connected client. Its public Ability identifier uses the canonical `wp-ai-bridge/*` namespace. It is designed to help continue a site project without dumping complete document bodies, task notes, or chat history into every new conversation.

### Project-specific recovery (PR #143 candidate)

An optional exact project_ref scopes Workspace discovery and resume to one logical project on the authenticated WordPress site. It is not a new tenant role or privacy boundary. Legacy items remain unbound until explicitly associated using their current version and state_hash. When a site has multiple projects, an unfiltered resume is labelled mixed and deliberately does not select an arbitrary current task. Project task summaries show separate progress, review, delivery, next_action and blocker.

Use workspace-document or workspace-task with action=list, view=summary, limit (1 to 25), an optional project_ref and before_id for small pages. Read has_more and next_cursor; one page never proves there are no other records. For long Markdown or notes, first get a summary containing the current ID/version/state_hash; then get view=window for content or notes, with offset, max_bytes and the same expected_version plus expected_state_hash. Concatenate the exact UTF-8 windows until complete is true. Large successful creates and updates return verified committed identity plus explicit omitted_fields instead of pretending that a truncated response is complete.

Canonical document keys such as project-brief are unique within a site/project_ref pair, including after archival. Reopening uses guarded unarchive, not a new document. Invalid UTF-8, oversized fields and sanitization that would change submitted text are rejected before persisting.

### Optional duplicate-safe creation (PR #143 candidate)

Six existing Bridge create operations accept an optional client-provided, stable operation_id: content create, media upload, media URL import, comment reply, Workspace document create and Workspace task create. Reuse exactly the same ID AND input only for a retry of the same intended create. A different intent must use another ID. An existing committed result is read back only under current WordPress and Bridge permissions, while a conflicting payload is rejected. When the effect is uncertain or incomplete, do not invent a new ID and blindly repeat the mutation. Keyless legacy creates retain their previous behavior and are not guaranteed duplicate-safe.

Receipt readback lasts 30 days; uncertain and expired IDs stay reserved against unsafe reuse. Under the bounded 2,048-claim site capacity, new keyed writes fail closed until explicit authorized reconciliation. This deliberately prioritizes data integrity over silent duplicate creation.

### Site Health and Core update status (PRs #142/#143 candidates)

Dashboard and Settings show which local MCP/OAuth dependencies and HTTPS URL scheme are configured, but distinguish them from public network reachability and authenticated external AI-client connectivity, which are not verified by merely rendering a local URL. WordPress Tools > Site Health remains the complete native UI. The new Bridge site-health capability requires the default-off Site Configuration grant plus native view_site_health_checks, discovers native tests without running them, and executes only individually supported Core diagnostics on request; it does not expose raw server debug secrets. An internal Authorization-header check cannot verify public proxy header forwarding. The read-only core-update-status output uses cached, freshness-checked WordPress update offers and never triggers a Core upgrade.

These are development candidates, not claims about an installed published release.

## Export and clear

Workspace administration provides explicit export/clear lifecycle controls. Clearing Workspace data is intentionally separate from ordinary plugin deactivation/uninstall because durable project state should not disappear as a side effect of replacing a transport plugin.

## Language and RTL

The plugin uses standard WordPress localization APIs. Persian (`fa_IR`) is bundled, and the admin screens are designed to work in both RTL and LTR WordPress installations. The canonical text domain is `wp-ai-bridge`.
