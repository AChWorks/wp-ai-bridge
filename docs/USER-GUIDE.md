# Using WP AI Bridge

WP AI Bridge provides a WordPress administrator interface and a private project Workspace that approved clients can use to continue work across sessions. **WordPress permissions and the access groups selected by the administrator control actual operations**; merely connecting a client does not enable site changes.

## Dashboard and Settings

- Open **WP AI Bridge → Dashboard** to inspect Workspace orientation and the locally configured Bridge connection environment.
- Open **WP AI Bridge → Settings** to review connection details, dependency/HTTPS checks, Bridge access groups and administrator-adjustable resource budgets.
- **Only Site Read is enabled by default.** Turn on write, publishing, source-editing, external-package or destructive grants only when the connected WordPress principal and trusted client need them. Every action still requires its own native WordPress/provider permission.
- A configured HTTPS endpoint is **not** proof that an external client can reach it or complete OAuth. Confirm the real client connection; use [Troubleshooting](./TROUBLESHOOTING.md) if discovery or authorization fails.

For the full list of access groups and risks, see the [user README](../README.md#permissions-you-control); for exact tool schemas and permissions, see the [Ability reference](./ABILITIES.md).

## Project documents and tasks

Use **WP AI Bridge → Documents** and **WP AI Bridge → Tasks** to inspect privately stored project context. These records remain in WordPress, not in the conversation history of a particular AI provider or Gateway.

- Documents contain project notes and other durable text; tasks track work and distinguish **progress**, **review** and **delivery** status.
- The **Project ID** column shows the exact saved `project_ref`; a record without one is displayed as **Unbound**. This is an organizer/lookup identity, **not** a separate WordPress security tenant or an automatically created Projects screen.
- Archive a record when it should remain available for history but not active daily work. Archived records are still retained and count toward the site's Workspace policy.
- Concurrent changes use `version` and `state_hash` checks. When an update reports a stale/conflict result, read the latest record first rather than overwriting another actor's work.

### Capacity controls

Settings provide **separate per-site Workspace document and task count limits**, initially **200 documents and 200 tasks**. Administrators may choose validated finite capacities of **10–20,000 documents** and **10–50,000 tasks**. Record sizes, network-message size and available hosting resources are independent constraints.

Lowering a count limit does **not** delete or conceal existing records; it limits further creation until usage is below the selected budget. Raising quotas increases real database work and does not guarantee throughput on a resource-constrained host. Use normal WordPress database backups for disaster recovery.

## Recover a project in a new conversation or application

An authorized AI/client can use `workspace-resume` for concise orientation, optionally passing the **exact** `project_ref` for the intended project. The same site can hold many different projects; an unfiltered resume does not invent a single active project when several exist.

For complete records, use `workspace-document` or `workspace-task` with a **summary list**, `limit` (1–25) and the returned `has_more`/`next_cursor` to continue through all pages. The first 25 results do **not** prove the list is complete. For a long document or task note, retrieve exact UTF-8 windows tied to its current `version` and `state_hash` until `complete=true`. After a conflict or changed hash, refresh the record and restart.

Canonical document keys are unique per site/project reference; use a guarded update or unarchive rather than creating a duplicate governing document. See the [Workspace Ability reference](./ABILITIES.md) for exact inputs and output fields.

## Recover or delete a change safely

For supported create operations, a client can supply a stable `operation_id` to avoid duplicates when a response was lost. **Reuse that ID only to retry the same intended input.** A genuinely different operation needs a different ID. Known successful receipts can be read back for **30 days**; uncertain and expired identities remain reserved for safety. The administrator-configurable admission budget for new generic receipts defaults to **2,048** and is separate from Workspace record counts.

**Permanent Workspace deletion is deliberately separate from archiving.** The `workspace-delete` operation accepts one exact, project-bound document/task, its current site and record identity, version/hash and the required explicit phrase `PERMANENTLY DELETE DOCUMENT <id>` or `PERMANENTLY DELETE TASK <id>`. It requires the current WordPress deletion/administrator authority plus **Builder Write** and **Users & Destructive**. An unbound legacy record or bulk selection is not eligible. Refresh the exact record after a veto, interrupted or uncertain result; **never blindly repeat an uncertain deletion**. Do not use **Clear Workspace** as a test-record cleanup shortcut.

## Activity and diagnostics

**WP AI Bridge → Activity** shows a bounded summary of Bridge mutations and selected transfer events; it does not store complete request bodies, passwords or private file contents. It retains **at most 50 entries**, and entries older than **30 days** are removed on a subsequent event. It is useful for diagnosis but **not** a comprehensive, guaranteed audit ledger.

An authorized administrator with **Site Read** can use `diagnostics-read` for sanitized Bridge-side transfer metadata. A Gateway-side denial, failed OAuth boundary or transport error may leave **no** WordPress Activity entry. Correlate timestamps and check the appropriate client/Gateway layer without relaxing permissions.

WordPress **Tools → Site Health** remains the native full diagnostic interface. The Bridge's Site Health tools require the relevant default-off **Site Configuration** permission. `core-update-status` can inspect cached available **WordPress Core update status**, but cannot install or schedule a WordPress Core upgrade. Supported WP-Cron inventory and provider-authorized management depend on registered provider capabilities and the proper grants; they are not a generic callback executor.

## Export, backups and destructive clearing

Workspace export is an administrator-visible data-export workflow, **not** an automatic Bridge JSON restore or complete WordPress backup. A large export may be refused when it cannot fit within the real bounded response/memory envelope rather than silently returning an incomplete collection.

Use a verified WordPress **database backup and restore process** for recovery of the persistent private Workspace and its site state. An explicit **Clear Workspace** is destructive and is deliberately separate from ordinary plugin deactivation, ZIP replacement and uninstall.

## Language and display

The admin interface supports the WordPress language and direction, including the bundled **Persian (`fa_IR`)** translation and RTL layout. Record IDs, keys, version/hash values and stored content remain byte-exact; changing the display language does not rename projects or translate private Workspace data.
